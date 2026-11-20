# SCIM Server for Nextcloud

Let your identity provider (authentik, Okta, Microsoft Entra ID, ...) manage Nextcloud accounts through **SCIM 2.0**.
Nextcloud already has a SCIM *client* (Nextcloud as the directory); this is the other direction: the IdP is the directory
and Nextcloud follows it. It runs entirely inside Nextcloud, no extra service needed.

> [!CAUTION]
> This app is **AI-generated** code. It is covered by unit tests and an automated end-to-end suite against Nextcloud, and it has been tested by hand with authentik (SCIM provisioning, OpenID Connect sign-in through `user_oidc`) and with Microsoft Entra ID (SCIM provisioning, SAML sign-in through `user_saml`). It is still young and security-sensitive (it creates, disables and deletes accounts): review it, try it on a test instance first, and keep backups before pointing it at production.

- **Provision**: users and groups created in the IdP appear in Nextcloud, with display name, email and group membership.
- **Update**: changes in the IdP are applied; `active: false` **disables** the account (login blocked, data kept).
- **Deprovision**: you choose what happens when the IdP deletes an account (Settings → Administration → Security → *SCIM Server*):
  - only disable (keep all data forever),
  - disable now and **delete the account and its data after N days** (default 30, you set N),
  - delete immediately.
  Becoming active again re-enables the account and cancels a scheduled deletion.
- Group filter: a regular expression in the settings decides which groups land in Nextcloud (like the group filter of `user_oidc`); excluded groups never appear and their members are ignored, but the IdP still gets a normal answer, so syncs stay green.
- Groups: create, rename, delete (deleting a group never deletes accounts), membership via PUT or PATCH.
- SCIM only sees and changes the accounts and groups **it created**. Everything else in Nextcloud (local accounts,
  administrators, groups made by hand) is invisible to the identity provider. To let it take over accounts that already
  exist (for example created by SSO login before SCIM was set up), switch off *Only manage accounts and groups that were
  created through SCIM* in the settings: then every account and group is listed and can be adopted, never duplicated.
- Administrators and the `admin` group are protected from SCIM by default (switchable): SCIM cannot change, disable or
  delete them. If your IdP also syncs an administrator, it gets `403` for that account; that is intended.

Requires Nextcloud 33-35, PHP 8.2-8.5.

## Setup with authentik

1. Install the app and open *Administration settings → Security → SCIM Server*. Create a token (shown once) and note the
   **SCIM base URL**, e.g. `https://cloud.example.com/index.php/apps/scim_server/v2`.
   (Without the web UI: `occ scim:token:create authentik`.)
2. In authentik create a **SCIM Provider**: URL = the base URL, authentication = *Token*, paste the token.
   Add it as a **backchannel provider** of your Nextcloud application (the one that does the OIDC/SAML login) and bind the
   users/groups that should get an account.
3. **Make the usernames match the SSO login.** SCIM creates local Nextcloud accounts whose id is the SCIM `userName`.
   Your login app must resolve to the same id, otherwise users get a second account on first login. With `user_oidc`:
   set the *user ID mapping* of the provider to the claim that authentik also sends as `userName` (by default the
   authentik SCIM `userName` is the username, so use `preferred_username`), and test the login of a pre-provisioned account.
4. Run a sync in authentik and check the provider's events.

authentik sends PUT for user changes, DELETE when a user leaves the application's scope and `active: false`
when a user is deactivated. Both end in a *disabled* Nextcloud account unless you chose immediate deletion.

## Opaque user ids (UUID) with email sign-in

The Nextcloud user id is whatever the IdP sends as SCIM `userName`, and it can never be changed afterwards (SCIM answers
`400 mutability` if a later request sends a different `userName` for the same account). Email, display name and groups stay
changeable. For a stable id use an immutable value, not the username or email.

**The id in SCIM `userName` and the id `user_oidc` derives at login must be the same string**, otherwise people get a second,
empty account on first login. How `user_oidc` derives it (from its source, `LocalIdService`/`LoginController`):

| `user_oidc` provider setting | Nextcloud user id |
|---|---|
| *Use unique user id* **on** (default) | `sha256(providerId + claim)`: never matches a SCIM account, you get duplicates |
| *Use unique user id* **off** | the claim named in *User ID mapping*, unchanged: matches the SCIM account |

So: turn **unique user id off** (and leave *provider-based ids* off), and point the user id mapping at the claim that equals `userName`.
An existing local account with that id is then adopted at login (Nextcloud's `soft_auto_provision` setting, on by default).
Only do this with a provider you trust: it can now log in as any account with the same id (that is what the warning in the setting says).

### authentik

1. **OAuth2/OpenID provider:** set *Subject mode* to **Based on user UUID**. `sub` is now the user's UUID.
2. **`user_oidc` (Nextcloud web UI):** sign in as an administrator and open *Administration settings → OpenID Connect*
   (the app is *OpenID Connect user backend*). Edit your authentik provider, or add one, and set:
   - *Discovery endpoint*, *Client ID* and *Client secret* from the authentik OAuth2/OpenID provider
     (the discovery URL looks like `https://authentik.example.com/application/o/<slug>/.well-known/openid-configuration`);
   - in the attribute mapping section, *User ID mapping* = `sub`;
   - **untick** *Use unique user ID* (and leave provider-based ids off);
   - **untick** group provisioning, if SCIM manages the groups (see "Groups: one owner per group");
   - save.

   (Command line instead: `occ user_oidc:provider authentik -c <client id> --clientsecret <secret> -d <discovery url> --unique-uid=0 --mapping-uid=sub --group-provisioning=0`.)
3. **SCIM provider:** create a *SCIM Mapping* (Customization → Property Mappings) with this expression:

   ```python
   return {"userName": str(request.user.uuid), "externalId": str(request.user.uuid)}
   ```

   and attach it to the SCIM provider's *User Property Mappings* **together with** the default
   *authentik default SCIM Mapping: User* (which supplies `displayName`, `emails`, `active`). authentik merges all attached
   mappings **in alphabetical order of their names**, later names winning, so name yours so it sorts after the default one:
   `z-uuid` works. Upper case sorts before lower case, so a name like `SCIM user mapping` sorts *before*
   `authentik default …` and gets overwritten. Attachment order does not matter.

   Use `request.user`, as the default mapping does: a bare `user` fails in authentik 2026.8 with
   `NameError: name 'user' is not defined` (shown as *Failed to evaluate property-mapping* under Events).

This mapping is for SCIM only; you do not use it in the OIDC config. Both sides arrive at the same UUID separately:

| Path | Where the UUID comes from | Configured in |
|---|---|---|
| SCIM provisioning | `userName` from the `z-uuid` SCIM mapping | authentik SCIM provider → *User Property Mappings* |
| OIDC login | the `sub` claim (*Subject mode* = Based on user UUID) | authentik OAuth2 provider; `user_oidc` *User ID mapping* = `sub` |

The other `user_oidc` mappings (display name, email, groups) can stay as they are; only the user id has to match.

**Start clean.** If accounts were already provisioned with another `userName` (for example the username), authentik will keep
sending the new UUID for them and the app refuses (`userName cannot be changed`). Remove those accounts in Nextcloud and
remove the provider's synced objects in authentik (or just create a fresh SCIM provider), then run a new sync.
Existing users cannot be renamed in Nextcloud; moving an existing instance to UUID ids means migrating the users' data.

### Microsoft Entra ID (with SAML)

This section covers Entra ID with **SAML** login (`user_saml`). Entra with OpenID Connect is not documented yet.

The user principal name and the email can change, so they make poor Nextcloud user ids. Use the immutable **Object ID**
(a GUID) as the id: Entra sends it to SCIM as `userName` (below) and to Nextcloud at SAML login as a claim, and both must be
the same value, otherwise users get a second, empty account on first login.

1. **One enterprise application for login and provisioning:** *Enterprise applications → New application → Create your own
   application → Integrate any other application (non-gallery)*. Users and groups assigned to it decide both who can log in
   and who is provisioned.
2. **SAML login:**
   - In the enterprise application open *Single sign-on → SAML*. In *Basic SAML Configuration* enter the *Identifier (Entity ID)*
     and *Reply URL (Assertion Consumer Service URL)* that Nextcloud shows under *Administration settings → SSO & SAML
     authentication* (service provider data).
   - In *Attributes & Claims* the Object ID is **not** sent by default (the required *Unique User Identifier (Name ID)* is
     the user principal name, which can change). Choose *Add new claim* and create one: *Name* `objectid`, *Source*
     attribute, *Source attribute* `user.objectid`. The default claims (`displayname`, `name`, `emailaddress`, `givenname`,
     `surname`) can stay.
   - In Nextcloud (*SSO & SAML authentication*) enter the Entra *Login URL*, *Microsoft Entra Identifier* and the
     *Certificate (Base64)* as IdP data, and set **Attribute to map the UID to** to `objectid`, so the Nextcloud user id is
     the Object ID. Map the display name and email attributes to the claims `http://schemas.microsoft.com/identity/claims/displayname`
     and `http://schemas.xmlsoap.org/ws/2005/05/identity/claims/emailaddress` if you want `user_saml` to fill them in at login.
   - Groups: `user_saml` puts users in groups it names with a prefix (`SAML_` by default). If SCIM manages the groups, clear
     the group attribute mapping in `user_saml` and read "Groups: one owner per group" below.
3. **Provisioning** (enterprise application → *Provisioning*, mode *Automatic*): *Tenant URL*
   `https://<nextcloud>/index.php/apps/scim_server/v2`, *Secret token* = a token from this app's admin settings, then
   *Test Connection*. Change the user attribute mappings **before the first cycle** (a later `userName` change gets
   `400 mutability`):

   | SCIM attribute | Entra source attribute | Matching precedence | |
   |---|---|---|---|
   | `userName` | `objectId` | **1** | default is `userPrincipalName`: change it |
   | `externalId` | `objectId` | | default is `mailNickname` |
   | `emails[type eq "work"].value` | `mail` | | |
   | `emails[type eq "home"].value` | `userPrincipalName` | **none** | default mapping and default matching attribute: switch its matching off (or delete the mapping) |
   | `displayName` | `displayName` | | |
   | `active` | `Switch([IsSoftDeleted], …)` | | keep the default |

   In *Test Connection* the same value must work as the lookup key: the test searches for an account with the
   matching attribute (`userName eq "<objectId>"` with the table above).

   **Matching precedence:** Entra looks accounts up with the mapping that has *Matching precedence* 1 (open *Edit attribute
   mapping*, "Use this attribute to match objects"). By default that is `userPrincipalName` -> `emails[type eq "home"].value`.
   Switch matching to `objectId` -> `userName` and untick it on the email mapping. Entra would otherwise find accounts by
   UPN and then send the Object ID as `userName`, which is refused (`mutability`). The app does answer email lookups (needed
   for *Test Connection*), but the lookup key and the id should be the same stable value.

   Group mappings can stay as they are (`displayName`, `members`). Scope: *Sync only assigned users and groups*.
   Entra uses PATCH, including its capitalised operations, which the app supports.
4. **Test** with *Provision on demand* for one user (a regular cycle runs about every 40 minutes): the Nextcloud account
   id must be the user's *Object ID*. Then sign in with Entra and check that no second account appears.

The Entra claims are as configured in a working test; the `user_saml` field names come from memory and may differ in your version: check them in Nextcloud.

## Groups: one owner per group

> [!IMPORTANT]
> **Let only one thing write a group's membership.** SCIM, `user_oidc` group provisioning and `user_saml` group mapping all
> create groups and add/remove members. If two of them manage the same group they overwrite each other (a membership sync
> removes members the other one added). When SCIM owns the groups, switch the others off: for `user_oidc` set *group
> provisioning* off (`--group-provisioning=0`); for `user_saml` clear the group attribute mapping.

SCIM uses the group name from the IdP as the Nextcloud group name, exactly as sent. That is also what `user_oidc` produces,
so with OIDC the names line up. `user_saml` prefixes the group names it creates (`SAML_` unless you configured another prefix),
so a user who logs in via SAML is in `SAML_DATA-MGMT` while authentik calls the group `DATA-MGMT`. To make SCIM write to that
same group, set the **Group name prefix** in the SCIM Server settings to the prefix `user_saml` uses (`SAML_`).
Nextcloud then stores the SCIM group `DATA-MGMT` as `SAML_DATA-MGMT`, while the IdP keeps seeing `DATA-MGMT`.
The group filter is checked against the name without the prefix. Changing the prefix later does not rename existing groups.

**Groups that already exist** (for example `SAML_DATA-MGMT`, created by `user_saml` at a login) are invisible to SCIM by default,
because SCIM only manages what it created. There are two ways to let SCIM manage the membership of an existing group:

- **Allow-list (recommended):** in the settings, *Existing groups whose members SCIM may manage*: a regular expression on the
  group name without the prefix, for example `^DATA-` or `^(DATA-MGMT|SALES)$`. Matching existing groups are then managed by SCIM
  with these limits: SCIM adds and removes **only the accounts it manages** (never SAML, OIDC or local members), it **never
  renames** such a group, and it **never deletes** it (when the IdP deletes the group, SCIM only removes its own accounts from
  it; deleting a Nextcloud group would also delete its shares and permissions for members SCIM does not manage). Only the
  exact group id counts, so a group with a look-alike display name is not picked up.
- **Everything:** switch off *Only manage accounts and groups that were created through SCIM*. SCIM then sees and can take over
  every account and group, including renaming and deleting groups. Use this only on a dedicated setup.

> [!WARNING]
> Whoever can create accounts or edit groups at the identity provider (or holds the SCIM token) can put accounts into every group
> on the allow-list, and with it gets whatever that Nextcloud group grants (shares, group folders, app restrictions, subadmin
> rights). List only groups the identity provider should control. The `admin` group stays protected unless you switch the
> administrator protection off; more groups can be protected with `occ config:app:set scim_server protected_groups --value="gid1,gid2"`.

**Groups that appear without members** almost always mean the members are accounts SCIM does not know. A member can only be
added if its account was created through SCIM (or, with the setting above off, already exists). Look at the authentik
provider's sync events: a failing *user* sync (typically a `409` because the account already exists from an SSO login, which
SCIM then cannot see) means authentik leaves that user out of the group, and a group request answered with `400 Unknown member`
names the id it could not resolve. Fix the user sync first (delete the stray account, or switch the setting off so SCIM can
adopt it), then run a new sync.

## SCIM support

`GET /ServiceProviderConfig`, `/Users` and `/Groups` with POST, GET (list and single), PUT, PATCH and DELETE.
Filters: `userName eq`, `externalId eq` (users), `displayName eq`, `externalId eq` (groups). Paging with `startIndex`/`count`.
PATCH: `add`/`replace`/`remove` on `active`, `displayName`, `externalId`, `emails`, `name.*` and group `members` (including
`members[value eq "id"]` paths and Entra's capitalised operations). Not supported: bulk, sort, ETags, password changes,
`/Schemas` and `/ResourceTypes`, enterprise extension. Nextcloud has a single display name, so `name.givenName`/`familyName`
only feed it. Renaming a group changes its display name; its Nextcloud group id stays.
`userName` cannot be changed (SCIM answers `mutability`).

## How fast is provisioning?

CI measures it on every run: a real authentik (Docker) pushes changes to Nextcloud over SCIM, and the job
*Nextcloud stable35 provisioned by authentik* times how long it takes from the authentik API call until the change is visible
in Nextcloud: account created (and in its group), account deactivated, account deleted. The numbers (median, min, max of 5
runs per step) are in that job's summary on the Actions page, and the raw samples are in its `timings` artifact, so anyone can
inspect them. They are end-to-end latencies of an idle system on a shared GitHub runner, polled every 100 ms. Most of the time
is authentik's own worker queue, not this app; real instances will differ.

## Security

Tokens are random, stored as SHA-256 hashes, compared in constant time and revocable. SCIM requests are only accepted
with a valid token, over HTTPS, and bypass login/CSRF by design. Failed attempts are logged and slowed down; every
changing request is logged with the token that made it (Nextcloud log, level *info*). See [SECURITY.md](SECURITY.md).

**Treat a SCIM token like an administrator password.** Whoever holds it can create accounts, change any non-administrator
account's email address, disable or delete accounts, and change every group except `admin`. By default that is limited to
the accounts and groups created through SCIM; with that setting off it covers everything in Nextcloud. Recommended on an
SSO-only instance:

- Turn off password reset, in `config/config.php`: `'lost_password_link' => 'disabled'`. Otherwise a token holder can
  change an account's email and reset its password, and any provisioned user can set a local password and sign in
  without the IdP (and without its MFA). Also consider `'hide_login_form' => true`.
- Protect groups that carry privileges (delegated administration, groups excluded from two-factor enforcement, groups
  with sensitive shares): `occ config:app:set scim_server protected_groups --value="gid1,gid2"`. SCIM then answers `403`
  to changes of those groups.
- A disabled account can no longer sign in, but its public share links and shares to other people keep working until the
  account is deleted.
- Behind a reverse proxy Nextcloud must know that requests arrive over HTTPS (`overwriteprotocol`, `trusted_proxies`),
  or SCIM answers `403`. For a local test without TLS only:
  `occ config:app:set scim_server allow_http --value=true --type=boolean`.

## Development

```bash
composer install
composer run lint && composer run test:unit && composer run psalm
scripts/package.sh                      # build/scim_server-<version>.tar.gz
SCIM_TEST_DISPOSABLE_INSTANCE=1 NC_DIR=/path/to/installed/server tests/integration/run.sh   # end-to-end, needs the app enabled, jq and curl
```

Run the end-to-end suite **only against a throw-away Nextcloud**: it creates an administrator with a known password and a
SCIM token, switches to immediate deletion and deletes accounts.

CI runs the same checks, plus the end-to-end suite on Nextcloud 33 (SQLite), 34 (SQLite) and 35 (PostgreSQL).

Licence: AGPL-3.0-or-later.

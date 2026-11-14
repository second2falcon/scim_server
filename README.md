# SCIM Server for Nextcloud

Let your identity provider (authentik, Okta, Microsoft Entra ID, ...) manage Nextcloud accounts through **SCIM 2.0**.
Nextcloud already has a SCIM *client* (Nextcloud as the directory); this is the other direction: the IdP is the directory
and Nextcloud follows it. It runs entirely inside Nextcloud, no extra service needed.

> [!CAUTION]
> This app is **AI-generated** code. It is covered by unit tests and an automated end-to-end suite against Nextcloud, and it has been tested by hand with authentik (SCIM provisioning) and OpenID Connect (`user_oidc`) sign-in. It is still young and security-sensitive (it creates, disables and deletes accounts): review it, try it on a test instance first, and keep backups before pointing it at production.

- **Provision**: users and groups created in the IdP appear in Nextcloud, with display name, email and group membership.
- **Update**: changes in the IdP are applied; `active: false` **disables** the account (login blocked, data kept).
- **Deprovision**: you choose what happens when the IdP deletes an account (Settings → Administration → Security → *SCIM Server*):
  - only disable (keep all data forever),
  - disable now and **delete the account and its data after N days** (default 30, you set N),
  - delete immediately.
  Becoming active again re-enables the account and cancels a scheduled deletion.
- Groups: create, rename, delete (deleting a group never deletes accounts), membership via PUT or PATCH.
- Accounts that already exist in Nextcloud (for example created by SSO login) are adopted by SCIM, never duplicated.
- Administrators and the `admin` group are protected from SCIM by default (switchable).

Requires Nextcloud 33-35, PHP 8.2-8.5.

## Setup with authentik

1. Install the app and open *Administration settings → Security → SCIM Server*. Create a token (shown once) and note the
   **SCIM base URL**, e.g. `https://cloud.example.com/index.php/apps/scim_server/v2`.
   Headless: `occ scim:token:create authentik`.
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
2. **`user_oidc`:** provider with *User ID mapping* = `sub` and *Use unique user id* off:

   ```bash
   occ user_oidc:provider authentik -c <client id> --clientsecret <secret> -d https://authentik.example.com/application/o/<slug>/.well-known/openid-configuration \
     --unique-uid=0 --mapping-uid=sub --group-provisioning=0
   ```
3. **SCIM provider:** create a *SCIM Mapping* (Customization → Property Mappings) with this expression:

   ```python
   return {"userName": str(user.uuid), "externalId": str(user.uuid)}
   ```

   and attach it to the SCIM provider's *User Property Mappings* **together with** the default
   *authentik default SCIM Mapping: User* (which supplies `displayName`, `emails`, `active`). authentik merges all attached
   mappings **in alphabetical order of their names**, later names winning, so name yours so it sorts after the default one
   (for example `z-uuid`). Attachment order does not matter.

**Start clean.** If accounts were already provisioned with another `userName` (for example the username), authentik will keep
sending the new UUID for them and the app refuses (`userName cannot be changed`). Remove those accounts in Nextcloud and
remove the provider's synced objects in authentik (or just create a fresh SCIM provider), then run a new sync.
Existing users cannot be renamed in Nextcloud; moving an existing instance to UUID ids means migrating the users' data.

### Microsoft Entra ID

Entra's `sub` is different for every application, and `preferred_username`/`upn`/`email` can change, so neither is a good id.
Use the immutable object id: **`oid`**.

- `user_oidc`: *User ID mapping* = `oid` and *Use unique user id* off (the `oid` claim is in the ID token for work/school accounts).
- Entra provisioning: in the application's attribute mappings send the **object id** as SCIM `userName` (source attribute
  `objectId`), and keep `userPrincipalName` out of `userName`. Entra uses PATCH, which the app supports.

I derived the Entra settings from Microsoft's claim reference, not from a live tenant: verify the attribute names in your portal.

## SCIM support

`GET /ServiceProviderConfig`, `/Users` and `/Groups` with POST, GET (list and single), PUT, PATCH and DELETE.
Filters: `userName eq`, `externalId eq` (users), `displayName eq`, `externalId eq` (groups). Paging with `startIndex`/`count`.
PATCH: `add`/`replace`/`remove` on `active`, `displayName`, `externalId`, `emails`, `name.*` and group `members` (including
`members[value eq "id"]` paths and Entra's capitalised operations). Not supported: bulk, sort, ETags, password changes,
`/Schemas` and `/ResourceTypes`, enterprise extension. Nextcloud has a single display name, so `name.givenName`/`familyName`
only feed it. Renaming a group changes its display name; its Nextcloud group id stays.
`userName` cannot be changed (SCIM answers `mutability`).

## Security

Tokens are random, stored as SHA-256 hashes, compared in constant time and revocable. Always serve Nextcloud over HTTPS.
SCIM requests are only accepted with a valid token and bypass login/CSRF by design. See [SECURITY.md](SECURITY.md).

## Development

```bash
composer install
composer run lint && composer run test:unit && composer run psalm
scripts/package.sh                      # build/scim_server-<version>.tar.gz
NC_DIR=/path/to/installed/server tests/integration/run.sh   # end-to-end, needs the app enabled, jq and curl
```

CI runs the same checks, plus the end-to-end suite on Nextcloud 33 (SQLite), 34 (SQLite) and 35 (PostgreSQL).

Licence: AGPL-3.0-or-later.

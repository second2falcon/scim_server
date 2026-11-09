# SCIM Server for Nextcloud

Let your identity provider (authentik, Okta, Microsoft Entra ID, ...) manage Nextcloud accounts through **SCIM 2.0**.
Nextcloud already has a SCIM *client* (Nextcloud as the directory); this is the other direction: the IdP is the directory
and Nextcloud follows it. It runs entirely inside Nextcloud, no extra service needed.

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

### Releasing

Same flow as GraphCal: set `<version>` in `appinfo/info.xml`, add a `## <version>` section to `CHANGELOG.md`, merge to `main`,
push a tag `v<version>`. The workflow runs CI, builds the archive and creates the GitHub release; if the `appstore`
environment has `APP_PRIVATE_KEY` and `APPSTORE_TOKEN` it also signs and publishes to the Nextcloud App Store
(register the app id `scim_server` and request a certificate at `nextcloud/app-certificate-requests` first).

Licence: AGPL-3.0-or-later.

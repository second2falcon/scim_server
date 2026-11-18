# Changelog

## [Unreleased]

## 0.1.1-dev.1

Development build: bump the `-dev.N` number for every build you want Nextcloud to upgrade to.

- **Group filter**: a regular expression in the admin settings decides which groups are created in Nextcloud. Other groups are acknowledged to the IdP (so syncs do not fail) but never created, and their members are ignored. The filter applies when a group is created, or when it is renamed to a name that matches.
- Clearer error when an IdP sends a different `userName` for an existing account.
- Database migration (adds a column to `scim_groups`).

### Security fixes

Security fixes from the review in `docs/SecurityReview-261114-ClaudeCode-claude-fable-5-2.md`.

- **Administrator protection now covers every change**, not only disabling and deleting. Before, a SCIM token could change an administrator's email address, which together with Nextcloud's password reset allowed taking over the account.
- SCIM is refused over plain HTTP (opt-out for test setups: app config `allow_http`).
- Failed token attempts are logged and throttled; changing requests are logged with the token used.
- Creating or revoking tokens and saving the settings ask for password confirmation.
- Accounts and groups deleted in Nextcloud lose their SCIM bookkeeping at once, so a later account with the same id cannot inherit an old SCIM id or deletion date. Enabling an account in Nextcloud cancels its scheduled deletion. The purge job never deletes administrators, and switching away from "delete later" cancels all scheduled deletions.
- **SCIM is limited to the accounts and groups it created** (new setting, on by default). Other accounts and groups are not listed, cannot be changed and are not removed from groups by a membership sync. Switch the setting off to let the identity provider adopt existing accounts, as before. On upgrade, accounts and groups that have an `externalId` count as created through SCIM.
- The SCIM endpoints fail closed: the controller itself refuses every request that the token check has not approved, so a broken or missing middleware registration can no longer leave them open.
- New app config `protected_groups`: further groups that SCIM may not change.
- Email addresses are validated; `externalId` is limited to 255 characters and unique in the database; `active: null` is rejected instead of disabling the account; filter values are read as JSON strings; at most 1000 operations or members per request.
- The end-to-end test script refuses to run unless `SCIM_TEST_DISPOSABLE_INSTANCE=1` is set.

## 0.1.0

First release.

- SCIM 2.0 server for users and groups (`/v2/Users`, `/v2/Groups`, `/v2/ServiceProviderConfig`) with filters, paging and PATCH, tested against the requests authentik sends (Okta and Entra styles are handled too).
- Admin settings: bearer tokens (stored hashed), what happens when the IdP deletes an account (disable only, disable then delete after N days, or delete at once) and administrator protection.
- Accounts marked inactive are disabled and enabled again when they become active.
- Accounts that already exist in Nextcloud (for example created by SSO login) are adopted, not duplicated.
- `occ scim:token:create`.

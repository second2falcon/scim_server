# Changelog

## [Unreleased]

## 0.1.0

First release.

- SCIM 2.0 server for users and groups (`/v2/Users`, `/v2/Groups`, `/v2/ServiceProviderConfig`) with filters, paging and PATCH, tested against the requests authentik sends (Okta and Entra styles are handled too).
- Admin settings: bearer tokens (stored hashed), what happens when the IdP deletes an account (disable only, disable then delete after N days, or delete at once) and administrator protection.
- Accounts marked inactive are disabled and enabled again when they become active.
- Accounts that already exist in Nextcloud (for example created by SSO login) are adopted, not duplicated.
- `occ scim:token:create`.

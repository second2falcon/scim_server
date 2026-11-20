# Changelog

## [Unreleased]

## 0.2.0

First release. This app was written entirely with AI (Claude Code) and is tested by hand and by automated tests, see the README.

- SCIM 2.0 server for users and groups (`/v2/Users`, `/v2/Groups`, `/v2/ServiceProviderConfig`) with filters (`userName`, `externalId`, email, group `displayName`), paging and PATCH, as sent by authentik and Microsoft Entra ID.
- **Account lifecycle**: accounts are provisioned and updated from the identity provider. An account marked inactive is disabled and enabled again when it becomes active. When the IdP deletes an account you choose (admin settings): only disable, disable and delete the account and its data after N days (you set N), or delete at once.
- **Groups**: create, rename, delete and membership through PUT and PATCH. Admin settings: a **group filter** (regular expression) for which groups are created in Nextcloud, a **group name prefix** (for example `SAML_`, to write to the groups `user_saml` creates) and an **adopt list** (existing groups whose members SCIM may manage, without renaming or deleting them).
- **Safe by default**: SCIM only sees and changes accounts and groups it created; administrators and the `admin` group are protected (more groups with `protected_groups`); SCIM is refused over plain HTTP; the endpoints fail closed; bearer tokens are stored hashed, throttled on failure and need password confirmation to create or revoke; requests that change data are logged.
- **Parallel requests**: when an IdP's workers create the same account or group at the same time, the second request gets `409` (not a server error); a busy database (for example SQLite's single writer, or a deadlock) is answered with `503` and `Retry-After`, which IdPs retry.
- `occ scim:token:create`.
- Tested with authentik (OpenID Connect login) and Microsoft Entra ID (SAML login). The README has the setup for both.

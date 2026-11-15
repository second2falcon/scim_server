# Security review: SCIM Server for Nextcloud

| | |
|---|---|
| Date | 2026-11-14 |
| Reviewer | Claude Code (model `claude-fable-5-2`), AI-generated review |
| Commit reviewed | `46e88fe` (branch `claude/optimistic-carson-d8nhqt`) |
| Scope | Every tracked file: `lib/`, `appinfo/`, `js/`, `templates/`, `scripts/`, `tests/`, `.github/workflows/`, `composer.json`, docs |

> **Follow-up, same day:** fixes for these findings were implemented after this review; see `CHANGELOG.md` (Unreleased).
> SCIM is now limited to the resources it created (M-1, setting on by default). Not changed: token expiry and scopes
> (L-3), batching of group member lookups (L-6) and pinning of the App Store XSD (L-9). I-1 is fixed: the controller
> fails closed without the middleware. `docs/HANDOVER.md` (L-8)
> was removed, also from the git history.

## Summary

The code is small (about 1,300 lines of PHP) and the basics are done well: all SQL goes through the query builder with
bound parameters, tokens are high-entropy and stored hashed, all template and JavaScript output is escaped, the admin
endpoints keep Nextcloud's default admin-only and CSRF checks, and the CI workflows use least-privilege permissions and
pinned actions. I found no SQL injection, XSS, CSRF, path traversal, deserialisation or authentication-bypass bug.

The serious problem is in the authorisation model, not in the plumbing: **"Protect administrators" only blocks
disabling/deleting an administrator. A SCIM token can still change an administrator's email address, and Nextcloud's
password-reset mail goes to that address.** With the protection switched on, a leaked or misused SCIM token is therefore
still a path to full Nextcloud administrator access (finding H-1). The same mechanism lets the token holder take over any
other account (M-1).

| ID | Severity | Finding |
|---|---|---|
| H-1 | High | Admin protection does not cover email changes: administrator takeover through password reset |
| M-1 | Medium | The token can take over any account and change any group; it is not limited to SCIM-managed resources |
| M-2 | Medium | Stale bookkeeping rows can cause the purge job to delete the wrong (new) account |
| M-3 | Medium | Provisioned accounts can set a local password by "forgot password" and bypass the IdP (and its MFA) |
| L-1 | Low | Token creation and the admin-protection switch do not ask for password confirmation |
| L-2 | Low | No rate limiting, failed-login logging or audit trail on the SCIM endpoints |
| L-3 | Low | Tokens never expire and plain HTTP is not refused |
| L-4 | Low | Email addresses are stored without validation |
| L-5 | Low | `externalId` uniqueness is check-then-write, with no unique index |
| L-6 | Low | Expensive requests for a token holder (group listing, unbounded PATCH/member lists) |
| L-7 | Low | The integration test script leaves an admin account with a known password on the target instance |
| L-8 | Low | `docs/HANDOVER.md` names real production hosts |
| L-9 | Low | Release workflow: signing key is exposed to more steps than needed |
| I-1..I-6 | Info | Smaller observations, see the end |

How sure I am: every finding comes from reading the code. H-1's code path is unambiguous in this repository; the
password-reset half of the chain is Nextcloud core behaviour that I know from its source but did **not** run here. Nothing was
tested against a live Nextcloud, authentik or Entra instance. See "What was not checked".

## Trust model (as implemented)

- Anyone who holds a SCIM bearer token can list every account and group of the instance (not only SCIM-created ones), create
  accounts, change display name/email/enabled state, delete or disable accounts, and create/rename/delete groups and change
  their membership.
- The only limit is the *Protect administrators* setting (default on): members of `admin` cannot be disabled or deleted, and
  the `admin` group cannot be changed or deleted.
- The settings and token management are for full Nextcloud administrators only.

The findings below are measured against that model and against what the settings page promises
("Protect administrators and the admin group from SCIM changes").

## High

### H-1 Admin protection does not cover email changes: administrator takeover through password reset

**Where:** `lib/Service/UserService.php:174-210` (`apply()`), `guardAdmin()` at `:234`.

`apply()` calls `guardAdmin()` only when the request sets `active` to false (`:185-187`). Display name, `externalId` and
**email** (`:201-203`, `setSystemEMailAddress`) are applied to administrators without any check. Administrators are reachable
because every existing account is adopted on first sight: `GET /Users?filter=userName eq "admin"` returns its SCIM id.

Attack, with *Protect administrators* on, holding only a SCIM token:

1. `GET /v2/Users?filter=userName eq "admin"` to get the administrator's SCIM id.
2. `PUT /v2/Users/{id}` with `{"userName":"admin","emails":[{"value":"attacker@example.org","primary":true}]}`
   (or the equivalent PATCH on `emails`).
3. Use Nextcloud's "Forgot password?" for `admin`. Nextcloud sends the reset link to the account's email address, now the
   attacker's.
4. Set a new password and log in as administrator.

**Impact:** a credential that the UI presents as unable to touch administrators yields full administrator access: all files,
all settings, code execution through app installation.

**What limits it:** the instance must be able to send mail and must not have `lost_password_link` disabled; the account's
backend must allow password changes (the built-in database backend does); two-factor authentication configured on the
administrator account still applies after the reset.

**Fix:** in `apply()`, when the target is an administrator and protection is on, refuse the request or skip the change for
every attribute, not only for `active: false`. One `guardAdmin($user)` call at the top of `apply()` for non-create calls
closes it (the IdP then gets `403` for administrators it tries to sync, which matches "protected from SCIM changes"). Add a
unit test next to `testAdminsAreProtected` that expects `setSystemEMailAddress` never to be called for an administrator.

## Medium

### M-1 The token can take over any account and change any group

**Where:** `lib/Service/UserService.php:201-203`, `lib/Db/Store.php:136-153` (lazy adoption), `lib/Service/GroupService.php:229-233`.

This is H-1 for non-administrators, plus the reach of group changes:

- Changing any user's email and then resetting the password gives a login as that user. Creating a new account with an
  attacker-controlled email works the same way. An IdP that controls SSO can already impersonate users, but the SCIM token is
  a separate, long-lived secret that sits in the IdP's configuration and possibly in backups and logs; it should not be worth
  as much as the IdP's signing key.
- Only the group with id `admin` is protected. Membership of other groups can also carry privilege: groups that were
  delegated administration settings, groups excluded from enforced two-factor authentication, groups that apps are
  restricted to, group folders and group shares. A token holder can put any account (including one it controls) into those
  groups, or delete them.
- SCIM is not limited to what SCIM created. Accounts and groups made by hand, by LDAP or by SSO are listed, adopted and
  changeable. The README describes adoption as a feature; the consequence for the token's reach is not stated.

**Fix (pick what fits):** document plainly that a SCIM token is close to administrator-equivalent and must be stored like
one; add an admin setting listing extra protected groups; optionally let admins restrict SCIM to accounts/groups that SCIM
created (a `managed` flag is one column in the existing tables). Disabling password reset (M-3) removes the takeover half.

### M-2 Stale bookkeeping rows can cause the purge job to delete the wrong account

**Where:** `lib/Db/Store.php:136-153`, `lib/Service/UserService.php:146-160` (`purgeDue`), `:162-172` (`find`).

Rows in `scim_users`/`scim_groups` are removed only when SCIM itself deletes the resource or later looks it up by SCIM id.
If an administrator deletes an account directly in Nextcloud, its row stays, including `pending_delete_at`. When an account
with the same user id is created later (by hand or by SSO auto-provisioning), `row()` finds the old row, so the new account
inherits the old SCIM id, `externalId` and deletion date:

- The IdP's old reference now controls an unrelated new account.
- If the row carried a deletion date and the new account is ever disabled for any reason, the hourly job deletes it and its
  files.

Related, same function:

- `purgeDue()` does not re-check administrator protection. An account that was scheduled for deletion and later added to
  `admin` while still disabled is deleted.
- A deletion date survives a manual re-enable in the Nextcloud UI (only SCIM `active: true` clears it). Disabling that
  account by hand later triggers deletion at the old date.
- Dates are ignored while another delete mode is selected, then all fire together when `disable_purge` is selected again.
  Switching the mode can cause a batch of deletions on the next job run.
- For an account that is enabled again, `purgeDue()` deletes the whole row (`:157`), which also throws away its SCIM id; the
  IdP's next request for that id gets `404`.

**Fix:** listen for Nextcloud's `UserDeletedEvent` and `GroupDeletedEvent` and delete the row there; in `purgeDue()` clear
only `pending_delete_at` for enabled accounts, and skip administrators when protection is on.

### M-3 Provisioned accounts can set a local password and bypass the IdP

**Where:** `lib/Service/UserService.php:99-102`.

SCIM-created accounts are ordinary local accounts with a random password. Any such user can use "Forgot password?" to set a
password of their own and then sign in on the normal login form, without the IdP and so without its MFA or conditional
access. Disabling in the IdP still blocks them (the account is disabled), so this is a policy bypass, not a deprovisioning
hole.

**Fix:** documentation is enough: recommend `'lost_password_link' => 'disabled'` in `config.php` (this also removes the
takeover step of H-1 and M-1) and hiding the password login form (`'hide_login_form' => true`) on SSO-only instances.

## Low

### L-1 No password confirmation for token creation and the protection switch

**Where:** `lib/Controller/AdminController.php:25-41`.

The three admin actions rely on admin session plus CSRF token, which is correct, but Nextcloud asks for the password again
before comparable actions (creating app passwords, for example). Without it, a briefly unattended or hijacked admin session
can mint a permanent SCIM token or switch off administrator protection. **Fix:** add `#[PasswordConfirmationRequired]` to
`save`, `createToken` and `deleteToken`, and call the password-confirmation dialog in `js/admin.js` before the request.

### L-2 No rate limiting, failed-login logging or audit trail

**Where:** `lib/Middleware/ScimMiddleware.php:25-33`, `lib/Controller/ScimController.php`.

Guessing a token is not realistic (48 alphanumeric characters), so this is about visibility and load: a wrong token causes
no log line and no throttling, and each unauthenticated request costs a database query. Successful SCIM operations are not
logged with the token that made them, so after an incident there is no way to tell what a given token did (Nextcloud's own
audit log records "user deleted" but not by whom). **Fix:** log failed authentication and each state-changing call with the
token id and label; add `#[AnonRateLimit]` or the brute-force throttler to the 401 path.

### L-3 Tokens never expire; plain HTTP is not refused

**Where:** `lib/Db/Store.php:32-71`.

Tokens are valid until revoked, have no scope (read-only, users-only) and no source-address restriction. The README says to
use HTTPS but the middleware accepts a token over plain HTTP, where it can be read in transit. **Fix:** refuse SCIM requests
when `$request->getServerProtocol()` is not `https` (with an opt-out for test setups); consider an optional expiry date.

### L-4 Email addresses are stored without validation

**Where:** `lib/Service/UserService.php:201-203`, `:212-223`.

Any string in `emails[].value` is written with `setSystemEMailAddress`, which does not check the format. Nextcloud's mailer
rejects invalid addresses when sending, so I see no injection path, but junk values end up in the account data and in
other apps that read it. **Fix:** reject values that fail `IMailer::validateMailAddress()` with `400 invalidValue`.

### L-5 `externalId` uniqueness is check-then-write

**Where:** `lib/Service/UserService.php:225-232`, `lib/Service/GroupService.php:180-187`,
`lib/Migration/Version0001Date20261109000000.php:49`.

The index on `external_id` is not unique, and the check and the update are separate statements, so two concurrent requests
can give two resources the same `externalId`; lookups by `externalId` then return whichever row the database yields first.
Values longer than 255 characters produce a database error (or silent truncation on lenient MySQL setups). **Fix:** validate
the length, and make the index unique in a follow-up migration.

### L-6 Expensive requests for a token holder

**Where:** `lib/Service/GroupService.php:31-37`, `:59-81`, `:190-201`; `lib/Service/Patch.php:17-43`;
`lib/Controller/ScimController.php:143-149`.

Authenticated only, so the risk is a misbehaving IdP or a leaked token rather than an anonymous attacker:

- `GET /Groups` loads all groups, and for each group on the page all members, with one query per member; it also inserts a
  bookkeeping row for every group and member it has not seen before (a GET that writes).
- The number of PATCH operations and of members in one request is unbounded; each member costs two lookups.

**Fix:** cap operations and members per request (for example 1,000) with `413`/`400`, and batch the member lookups when
group sizes make it necessary (the code already carries a note about this).

### L-7 The integration test script leaves a backdoor on the target instance

**Where:** `tests/integration/run.sh:16`, `:20`, `:68-73`, `:89-90`.

The README offers `NC_DIR=/path/to/installed/server tests/integration/run.sh`. Run against anything but a throw-away
instance, the script leaves behind: a user `boss` **in the `admin` group with the fixed password `Passw0rd!xx-ci`**, a valid
SCIM token labelled `ci`, `delete_mode=delete` (immediate deletion), a changed `trusted_domains` entry and test accounts. It
also deletes accounts named `ann`/`bob` if they exist. **Fix:** make the script refuse to run unless an explicit
`I_KNOW_THIS_INSTANCE_IS_DISPOSABLE=1` style variable is set, and say so in the README.

### L-8 `docs/HANDOVER.md` names real production hosts

**Where:** `docs/HANDOVER.md:12-16`.

The file gives the real Nextcloud and authentik host names of a production deployment and states that it runs this
young, security-sensitive app. If the repository is or becomes public, that is a ready-made target list entry. The file is
not shipped in the app archive. **Fix:** replace the host names with placeholders or remove the file; it remains in git
history unless history is rewritten.

### L-9 Release workflow: signing key exposed to more steps than needed

**Where:** `.github/workflows/release.yml:45-46`, `:74-84`.

`APP_PRIVATE_KEY` is a job-level environment variable, so every step of the `release` job can read it, including the
checkout of `nextcloud/server` and `scripts/package.sh`. The key file is written with default permissions and never
removed (the runner is ephemeral, so the exposure is short). Also:

- Anyone who can push a tag on a commit of `main` starts a release; the `appstore` environment should have required
  reviewers and a tag-name restriction (GitHub settings, not visible in the repository).
- `ci.yml:58` downloads the App Store XSD from the moving `master` branch; it is only fed to `xmllint`, without secrets.
- The actions are pinned to commit ids, which is right. I could not check from here that each id is the release named in
  its comment.

**Fix:** pass the key only to the step that writes it (and use a step output for the "is signing configured" condition),
`umask 077` before writing it, and delete it in an `always()` step.

## Informational

- **I-1 Authentication lives in one middleware.** `ScimController` actions are all `#[PublicPage]`; the only gate is
  `ScimMiddleware::beforeController`. If the middleware were ever not registered (a refactor, a framework change), every
  endpoint would be open without any error. The end-to-end suite checks the 401 cases, which is the right safety net; keep
  those tests mandatory for releases (they are).
- **I-2 `active: null` disables.** `filter_var(null, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)` is `false`, so a PUT
  with `"active": null` or a PATCH `remove` on `active` disables the account (`UserService.php:181`). It fails in the safe
  direction, but probably should be `400`.
- **I-3 Filter unescaping.** `Filter.php:23` uses `stripcslashes`, which also interprets C escapes such as `\x41` and octal
  sequences, more than SCIM's JSON-style escapes. The value is only used as a bound parameter or lookup key.
- **I-4 Disabled is not the same as cut off.** In the two "disable" modes the account's public share links and shares to
  other users keep working. That is Nextcloud's behaviour for disabled accounts, worth a line in the README for people who
  expect deprovisioning to revoke access to shared data.
- **I-5 Uncaught errors.** A password-policy rejection of the generated password, a concurrent first lookup of the same
  resource (duplicate primary key in `Store::row`) and over-long values surface as `500`. No information leaks (Nextcloud
  hides details unless debug mode is on).
- **I-6 `find()` deletes bookkeeping on a null lookup.** `UserService.php:166-168` drops the row when the user manager
  returns no user. With a remote backend (LDAP) that is temporarily unreachable this could discard SCIM ids; harmless for
  local accounts.

## What is done well

- **SQL:** only the query builder with named parameters; table and column names are constants (`lib/Db/Store.php`).
- **Tokens:** 48 characters from `ISecureRandom` (about 285 bits), only the SHA-256 hash is stored, shown once, revocable,
  compared with `hash_equals`. An unsalted fast hash is appropriate for secrets of this entropy.
- **Output:** every template value goes through `p()`; `js/admin.js` uses `textContent` only; SCIM errors are JSON-encoded
  with a JSON content type, so reflected input cannot become HTML.
- **CSRF:** the SCIM endpoints require an `Authorization` header that a browser will not attach cross-site, so skipping the
  CSRF check there is sound. The admin endpoints keep the default CSRF and admin checks and are not open to delegated
  settings groups.
- **Regular expressions:** the filter and path patterns have no ambiguous alternation; I found no catastrophic backtracking.
- **Admin group guard:** creating, replacing, patching or deleting the `admin` group is blocked, and a second group named
  like it is refused by the case-insensitive name check.
- **Safe defaults:** deletion is "disable, purge after 30 days" and protection is on by default.
- **CI/CD:** `permissions: contents: read` by default, write only in the release job, `pull_request` (not
  `pull_request_target`), no secrets in CI, actions pinned by commit, releases only from commits on `main`, the signing
  certificate is checked against the key, and the signing tool comes from a pinned Nextcloud commit.
- **Packaging:** the archive contains runtime files only; there are no runtime Composer dependencies, so no third-party
  code ships with the app.

## What was not checked

- No request was sent to a running Nextcloud; behaviour of Nextcloud core (password reset, session handling of disabled
  accounts, mail validation) is stated from knowledge of its source, not observed here.
- The development dependencies in `composer.lock` (PHPUnit, Psalm, `nextcloud/ocp`) were not audited for known
  vulnerabilities; they are not shipped.
- The pinned GitHub Action commit ids were not compared with the upstream releases.
- GitHub repository settings (branch protection, environment reviewers, secret scanning) are not visible in the code.
- The interaction with `user_oidc`/`user_saml` and with the IdPs was not tested.

## Suggested order of work

1. H-1: guard every attribute change on administrators, with a test. Small change, removes the worst outcome.
2. M-3 / M-1: document `lost_password_link => disabled` and what a token can do.
3. M-2: delete bookkeeping rows on Nextcloud's user/group deleted events; fix `purgeDue()`.
4. L-7 and L-8: guard the test script, scrub the host names.
5. L-1, L-2: password confirmation and logging.

#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 SCIM Server contributors
# SPDX-License-Identifier: AGPL-3.0-or-later
#
# End-to-end test of the SCIM endpoints against a real, installed Nextcloud with the app enabled.
# Usage: NC_DIR=/path/to/server SCIM_TEST_DISPOSABLE_INSTANCE=1 tests/integration/run.sh   (needs php, curl, jq)
set -euo pipefail
# Needs a running Nextcloud with the app enabled: start it with tests/integration/setup.sh first.
# NC_URL: where Nextcloud is served (default http://127.0.0.1:8089). NC_DIR: the server checkout, for occ; or
# OCC: a command prefix for occ instead, e.g. for AIO "docker exec -e OC_PASS --user www-data nextcloud-aio-nextcloud php /var/www/html/occ".
# This creates an admin with a known password, a SCIM token, switches to immediate deletion and deletes accounts.
[[ "${SCIM_TEST_DISPOSABLE_INSTANCE:-}" == 1 ]] || { echo "Only for a throw-away Nextcloud: it leaves an admin with a known password and deletes accounts. Set SCIM_TEST_DISPOSABLE_INSTANCE=1 to run." >&2; exit 1; }
NC_URL=${NC_URL:-http://127.0.0.1:8089}
if [ -n "${OCC:-}" ]; then
	occ() { $OCC "$@"; }
else
	: "${NC_DIR:?set NC_DIR (the server checkout) or OCC}"
	occ() { php "$NC_DIR/occ" "$@"; }
fi
curl -sf "$NC_URL/status.php" >/dev/null || { echo "Nextcloud is not running at $NC_URL: run tests/integration/setup.sh first" >&2; exit 1; }

BASE="$NC_URL/index.php/apps/scim_server/v2"
# NC_ASSUME_HTTPS=1: Nextcloud reports https (overwriteprotocol, as behind a TLS reverse proxy), so SCIM must work
# without the allow_http opt-out. NC_SMOKE=1: only the basic users and groups checks (no settings changes through occ:
# some setups, e.g. All-in-One, cache app settings per PHP process, so those changes would not be seen).
TOKEN=$(occ scim:token:create ci)
if [[ "${NC_ASSUME_HTTPS:-}" == 1 || "$NC_URL" == https://* ]]; then
	[ -z "$(occ config:app:get scim_server allow_http 2>/dev/null || true)" ] && echo "ok   - allow_http is not set: SCIM has to work because Nextcloud reports https" || { echo "FAIL - allow_http is set"; exit 1; }
	# The PHP of some setups (AIO) keeps config.php for a while: wait until the web process reports https.
	for _ in $(seq 60); do
		code=$(curl -s -o /dev/null -w '%{http_code}' "$BASE/ServiceProviderConfig" -H "Authorization: Bearer $TOKEN")
		[[ "$code" != 403 ]] && break
		sleep 3
	done
	[[ "$code" != 403 ]] && echo "ok   - the web process reports https (HTTP $code)" || { echo "FAIL - the web process still reports http after 3 minutes (overwriteprotocol = $(occ config:system:get overwriteprotocol))"; exit 1; }
else
code=$(curl -s -o /tmp/scim-first-response -w '%{http_code}' "$BASE/Users" -H "Authorization: Bearer $TOKEN")
[[ "$code" == 403 ]] && echo "ok   - plain HTTP is refused" || { echo "FAIL - plain HTTP is refused (HTTP $code; overwriteprotocol = $(occ config:system:get overwriteprotocol || echo unset)); body: $(head -c 400 /tmp/scim-first-response); apps: $(occ app:list --enabled | grep -c scim_server)"; exit 1; }
fi
[[ "${NC_ASSUME_HTTPS:-}" == 1 || "$NC_URL" == https://* ]] || occ config:app:set scim_server allow_http --value=true --type=boolean >/dev/null
fails=0
ok() { echo "ok   - $1"; }
bad() { echo "FAIL - $1 ($2)"; fails=$((fails + 1)); }
# req METHOD PATH [JSON] -> sets $STATUS and $BODY
req() {
	local out
	out=$(curl -s -w '\n%{http_code}' -X "$1" "$BASE$2" -H "Authorization: Bearer $TOKEN" \
		-H 'Content-Type: application/scim+json' ${3:+--data "$3"})
	STATUS=${out##*$'\n'}; BODY=${out%$'\n'*}
}
expect() { # description, expected status
	[[ "$STATUS" == "$2" ]] && ok "$1" || bad "$1" "HTTP $STATUS, wanted $2: $BODY"
}
field() { [[ "$(jq -r "$2" <<<"$BODY")" == "$3" ]] && ok "$1" || bad "$1" "$2 = $(jq -r "$2" <<<"$BODY"), wanted $3"; }
enabled() { occ user:info "$1" --output=json | jq -r .enabled; }

finish() {
	echo
	if [[ $fails == 0 ]]; then echo "all passed"; exit 0; fi
	echo "$fails failed"; tail -n 20 "${NC_DIR:-.}/data/nextcloud.log" 2>/dev/null || true; exit 1
}
# --- authentication
[[ $(curl -s -o /dev/null -w '%{http_code}' "$BASE/Users") == 401 ]] && ok "no token is rejected" || bad "no token is rejected" ""
[[ $(curl -s -o /dev/null -w '%{http_code}' "$BASE/Users" -H 'Authorization: Bearer nope') == 401 ]] && ok "wrong token is rejected" || bad "wrong token is rejected" ""
req GET /ServiceProviderConfig; expect "ServiceProviderConfig" 200; field "patch advertised" .patch.supported true

# --- users
req POST /Users '{"schemas":["urn:ietf:params:scim:schemas:core:2.0:User"],"userName":"ann","externalId":"ext-ann","displayName":"Ann B","emails":[{"value":"ann@example.com","primary":true}],"active":true}'
expect "create user" 201; field "created userName" .userName ann; UID_ANN=$(jq -r .id <<<"$BODY")
[[ "$(occ user:info ann --output=json | jq -r .display_name)" == "Ann B" ]] && ok "display name stored in Nextcloud" || bad "display name stored" ""
req POST /Users '{"userName":"ann"}'; expect "duplicate user is a conflict" 409
req GET '/Users?filter=userName%20eq%20%22ann%22'; expect "filter userName" 200; field "one match" .totalResults 1
req GET '/Users?filter=externalId%20eq%20%22ext-ann%22'; field "filter externalId" '.Resources[0].userName' ann
req GET '/Users?filter=emails%5Btype%20eq%20%22home%22%5D.value%20eq%20%22ann%40example.com%22'; field "filter on an email (Entra matching attribute)" '.Resources[0].userName' ann
req GET '/Users?filter=emails%5Btype%20eq%20%22home%22%5D.value%20eq%20%22nobody%40example.com%22'; field "unknown email is an empty list" .totalResults 0
req GET '/Users?filter=userName%20eq%20%22nobody%22'; field "no match is an empty list" .totalResults 0
req GET "/Users/$UID_ANN"; expect "get user" 200
req GET /Users/00000000-0000-4000-8000-000000000000; expect "unknown user" 404
req PUT "/Users/$UID_ANN" '{"userName":"ann","displayName":"Ann Bee","emails":[{"value":"bee@example.com","primary":true}]}'; expect "replace user" 200; field "new name" .displayName "Ann Bee"
req PATCH "/Users/$UID_ANN" '{"schemas":["urn:ietf:params:scim:api:messages:2.0:PatchOp"],"Operations":[{"op":"Replace","path":"active","value":"False"}]}'
expect "deactivate" 200; [[ $(enabled ann) == false ]] && ok "deactivated account is disabled" || bad "deactivated account is disabled" ""
req PATCH "/Users/$UID_ANN" '{"Operations":[{"op":"replace","value":{"active":true}}]}'; [[ $(enabled ann) == true ]] && ok "reactivated" || bad "reactivated" ""

# --- groups
req POST /Users '{"userName":"bob"}'; UID_BOB=$(jq -r .id <<<"$BODY")
req POST /Groups "{\"displayName\":\"staff\",\"members\":[{\"value\":\"$UID_ANN\"}]}"; expect "create group" 201; GID=$(jq -r .id <<<"$BODY")
[[ "$(occ group:list --output=json | jq -r '.staff | join(",")')" == "ann" ]] && ok "member added in Nextcloud" || bad "member added" ""
req PATCH "/Groups/$GID" "{\"Operations\":[{\"op\":\"add\",\"path\":\"members\",\"value\":[{\"value\":\"$UID_BOB\"}]}]}"; expect "add member" 200; field "two members" '.members|length' 2
req PATCH "/Groups/$GID" "{\"Operations\":[{\"op\":\"remove\",\"path\":\"members[value eq \\\"$UID_ANN\\\"]\"}]}"; field "member removed" '.members|length' 1
req GET '/Groups?filter=displayName%20eq%20%22staff%22'; field "filter group" .totalResults 1
req DELETE "/Groups/$GID"; expect "delete group" 204
[[ $(occ user:list --output=json | jq 'has("ann")') == true ]] && ok "deleting a group keeps its users" || bad "deleting a group keeps its users" ""

[[ "${NC_SMOKE:-}" != 1 ]] || finish

# --- group filter
occ config:app:set scim_server group_regex --value='^nc-' >/dev/null
req POST /Groups "{\"displayName\":\"everyone\",\"externalId\":\"ak-g1\",\"members\":[{\"value\":\"$UID_ANN\"}]}"
expect "excluded group is acknowledged" 201; XG=$(jq -r .id <<<"$BODY")
[[ $(occ group:list --output=json | jq 'has("everyone")') == false ]] && ok "excluded group is not created in Nextcloud" || bad "excluded group is not created" ""
req GET "/Groups/$XG"; expect "excluded group can be read" 200
req PATCH "/Groups/$XG" "{\"Operations\":[{\"op\":\"add\",\"path\":\"members\",\"value\":[{\"value\":\"$UID_ANN\"}]}]}"; expect "excluded group accepts patches" 200
req POST /Groups '{"displayName":"everyone"}'; expect "excluded group name is a conflict" 409
req GET '/Groups?filter=displayName%20eq%20%22everyone%22'; field "excluded group is found by filter" '.Resources[0].id' "$XG"
req POST /Groups '{"displayName":"nc-staff"}'; expect "matching group is created" 201
[[ $(occ group:list --output=json | jq 'has("nc-staff")') == true ]] && ok "matching group exists in Nextcloud" || bad "matching group exists" ""
req PUT "/Groups/$XG" "{\"displayName\":\"nc-everyone\",\"members\":[{\"value\":\"$UID_ANN\"}]}"; field "renaming to a matching name creates it, same id" .id "$XG"
[[ "$(occ group:list --output=json | jq -r '.["nc-everyone"] | join(",")')" == "ann" ]] && ok "promoted group has its members" || bad "promoted group has its members" ""
req DELETE "/Groups/$XG"; expect "delete promoted group" 204
occ config:app:set scim_server group_regex --value='' >/dev/null

# --- deprovisioning modes
occ config:app:set scim_server delete_mode --value=disable >/dev/null
req DELETE "/Users/$UID_ANN"; expect "delete (mode: disable)" 204; [[ $(enabled ann) == false ]] && ok "only disabled" || bad "only disabled" ""
occ config:app:set scim_server delete_mode --value=disable_purge >/dev/null
req DELETE "/Users/$UID_BOB"; expect "delete (mode: purge later)" 204
[[ $(enabled bob) == false ]] && ok "disabled, still exists" || bad "disabled, still exists" ""
occ config:app:set scim_server delete_mode --value=delete >/dev/null
req DELETE "/Users/$UID_BOB"; expect "delete (mode: immediate)" 204
[[ $(occ user:list --output=json | jq 'has("bob")') == false ]] && ok "bob is gone" || bad "bob is gone" ""
req GET "/Users/$UID_BOB"; expect "deleted user is 404" 404

# --- UUID user ids (userName is an opaque id, the email is just an attribute)
UUID=3f2b8c1e-9d4a-4e7b-8a61-5c0d2e9f7a14
req POST /Users "{\"userName\":\"$UUID\",\"externalId\":\"ak-1\",\"displayName\":\"Carol C\",\"emails\":[{\"value\":\"carol@example.com\",\"primary\":true}],\"active\":true}"
expect "create user with a UUID as userName" 201; UID_CAROL=$(jq -r .id <<<"$BODY")
[[ "$(occ user:info "$UUID" --output=json | jq -r '.email + "|" + .display_name')" == "carol@example.com|Carol C" ]] && ok "UUID account has email and display name" || bad "UUID account has email and display name" ""
req GET "/Users?filter=userName%20eq%20%22$(tr a-f A-F <<<"$UUID")%22"; field "UUID lookup is case-insensitive" .totalResults 1
req PUT "/Users/$UID_CAROL" "{\"userName\":\"$UUID\",\"displayName\":\"Carol D\",\"emails\":[{\"value\":\"carol.d@example.com\",\"primary\":true}]}"
expect "email and name change keep the UUID" 200; field "same userName" .userName "$UUID"
req PUT "/Users/$UID_CAROL" '{"userName":"carol"}'; expect "userName cannot be changed" 400

# --- admins are protected
OC_PASS='Passw0rd!xx-ci' occ user:add --password-from-env boss >/dev/null
occ group:adduser admin boss
req POST /Users '{"userName":"boss"}'; expect "existing account conflicts" 409
# --- by default SCIM only sees what it created
req GET '/Users?filter=userName%20eq%20%22boss%22'; field "account not created through SCIM is invisible" .totalResults 0
req GET /Users; field "and not listed" '[.Resources[].userName] | index("boss") // "absent"' absent
occ group:add locals >/dev/null
req GET '/Groups?filter=displayName%20eq%20%22locals%22'; field "group not created through SCIM is invisible" .totalResults 0
occ config:app:set scim_server managed_only --value=false --type=boolean >/dev/null
req GET '/Users?filter=userName%20eq%20%22boss%22'; field "visible once existing accounts may be adopted" .totalResults 1
req GET '/Users?filter=userName%20eq%20%22boss%22'; BOSS=$(jq -r '.Resources[0].id' <<<"$BODY")
req DELETE "/Users/$BOSS"; expect "admin cannot be deprovisioned" 403
req PUT "/Users/$BOSS" '{"userName":"boss","emails":[{"value":"evil@example.org","primary":true}]}'; expect "admin email cannot be changed" 403
[[ "$(occ user:info boss --output=json | jq -r '.email // ""')" == "" ]] && ok "admin email untouched" || bad "admin email untouched" ""

# --- group prefix (e.g. user_saml's SAML_): SCIM writes to the prefixed group, the IdP only sees the plain name
occ config:app:set scim_server group_prefix --value='SAML_' >/dev/null
req POST /Users '{"userName":"erin"}'; UID_ERIN=$(jq -r .id <<<"$BODY")
req POST /Groups "{\"displayName\":\"DATA-MGMT\",\"members\":[{\"value\":\"$UID_ERIN\"}]}"; expect "create group with a prefix" 201
field "the IdP sees the plain name" .displayName DATA-MGMT
[[ "$(occ group:list --output=json | jq -r '.["SAML_DATA-MGMT"] | join(",")')" == "erin" ]] && ok "Nextcloud has SAML_DATA-MGMT with the member" || bad "Nextcloud has SAML_DATA-MGMT with the member" ""
[[ $(occ group:list --output=json | jq 'has("DATA-MGMT")') == false ]] && ok "no unprefixed group" || bad "no unprefixed group" ""
req GET '/Groups?filter=displayName%20eq%20%22DATA-MGMT%22'; field "found by the plain name" .totalResults 1
# a group a SAML login already created is adopted (needs managed_only off, as set above)
occ group:add SAML_PRE >/dev/null
req POST /Groups '{"displayName":"PRE"}'; expect "existing prefixed group is a conflict" 409
req GET '/Groups?filter=displayName%20eq%20%22PRE%22'; PRE=$(jq -r '.Resources[0].id' <<<"$BODY")
req PATCH "/Groups/$PRE" "{\"Operations\":[{\"op\":\"add\",\"path\":\"members\",\"value\":[{\"value\":\"$UID_ERIN\"}]}]}"; expect "adopted group gets members" 200
[[ "$(occ group:list --output=json | jq -r '.["SAML_PRE"] | join(",")')" == "erin" ]] && ok "member is in SAML_PRE" || bad "member is in SAML_PRE" ""
# an unknown member must not leave an empty group behind
req POST /Groups '{"displayName":"GHOSTS","members":[{"value":"00000000-0000-4000-8000-000000000000"}]}'; expect "unknown member is rejected" 400
[[ $(occ group:list --output=json | jq 'has("SAML_GHOSTS")') == false ]] && ok "and no empty group is created" || bad "and no empty group is created" ""
occ config:app:set scim_server group_prefix --value='' >/dev/null

# --- bookkeeping follows deletions made outside SCIM
req POST /Users '{"userName":"dave","externalId":"ext-dave"}'; OLD_DAVE=$(jq -r .id <<<"$BODY")
occ user:delete dave >/dev/null
OC_PASS='Passw0rd!xx-ci' occ user:add --password-from-env dave >/dev/null
req GET '/Users?filter=userName%20eq%20%22dave%22'
[[ "$(jq -r '.Resources[0].id' <<<"$BODY")" != "$OLD_DAVE" ]] && ok "a new account with an old id gets a new SCIM id" || bad "a new account with an old id gets a new SCIM id" ""
field "and no old externalId" '.Resources[0].externalId // ""' ""

# --- adopting existing groups (managed-only on): members only, never rename or delete
occ config:app:set scim_server managed_only --value=true --type=boolean >/dev/null
occ group:add adoptme >/dev/null; occ group:add keepout >/dev/null
OC_PASS='Passw0rd!xx-ci' occ user:add --password-from-env samlish >/dev/null
occ group:adduser adoptme samlish; occ group:adduser keepout samlish
req POST /Users '{"userName":"frank"}'; UID_FRANK=$(jq -r .id <<<"$BODY")
req POST /Groups '{"displayName":"adoptme"}'; expect "existing group is a conflict without an adopt rule" 409
req GET '/Groups?filter=displayName%20eq%20%22adoptme%22'; field "and invisible" .totalResults 0
occ config:app:set scim_server adopt_groups_regex --value='^adoptme$' >/dev/null
req GET '/Groups?filter=displayName%20eq%20%22adoptme%22'; field "visible once on the adopt list" .totalResults 1; AD=$(jq -r '.Resources[0].id' <<<"$BODY")
req GET '/Groups?filter=displayName%20eq%20%22keepout%22'; field "other existing groups stay invisible" .totalResults 0
req PATCH "/Groups/$AD" "{\"Operations\":[{\"op\":\"add\",\"path\":\"members\",\"value\":[{\"value\":\"$UID_FRANK\"}]}]}"; expect "SCIM account joins the existing group" 200
[[ "$(occ group:list --output=json | jq -r '.adoptme | sort | join(",")')" == "frank,samlish" ]] && ok "other members are left alone" || bad "other members are left alone" ""
req PUT "/Groups/$AD" '{"displayName":"adoptme-renamed","members":[]}'; expect "replace with an empty member list" 200
[[ "$(occ group:list --output=json | jq -r '.adoptme | join(",")')" == "samlish" ]] && ok "only the SCIM account was removed" || bad "only the SCIM account was removed" ""
[[ "$(occ group:info adoptme --output=json | jq -r '.displayName // .gid')" == adoptme ]] && ok "the group was not renamed" || bad "the group was not renamed" ""
req DELETE "/Groups/$AD"; expect "delete adopted group" 204
[[ "$(occ group:list --output=json | jq -r '.adoptme | join(",")')" == "samlish" ]] && ok "the group and its other members survive an IdP delete" || bad "the group and its other members survive an IdP delete" ""
occ config:app:set scim_server adopt_groups_regex --value='' >/dev/null
occ config:app:set scim_server managed_only --value=false --type=boolean >/dev/null

finish

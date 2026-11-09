#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 SCIM Server contributors
# SPDX-License-Identifier: AGPL-3.0-or-later
#
# End-to-end test of the SCIM endpoints against a real, installed Nextcloud with the app enabled.
# Usage: NC_DIR=/path/to/server tests/integration/run.sh   (needs php, curl, jq)
set -euo pipefail
NC_DIR=${NC_DIR:?set NC_DIR to the Nextcloud server checkout}
PORT=${PORT:-8089}
cd "$NC_DIR"
occ() { php occ "$@"; }

php -S "127.0.0.1:$PORT" -t "$NC_DIR" > /tmp/scim-nc.log 2>&1 &
SERVER=$!
trap 'kill $SERVER 2>/dev/null || true' EXIT
occ config:system:set trusted_domains 1 --value="127.0.0.1:$PORT" >/dev/null
for _ in $(seq 30); do curl -sf "http://127.0.0.1:$PORT/status.php" >/dev/null && break; sleep 1; done

BASE="http://127.0.0.1:$PORT/index.php/apps/scim_server/v2"
TOKEN=$(occ scim:token:create ci)
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
req GET '/Users?filter=userName%20eq%20%22boss%22'; BOSS=$(jq -r '.Resources[0].id' <<<"$BODY")
req DELETE "/Users/$BOSS"; expect "admin cannot be deprovisioned" 403

echo; [[ $fails == 0 ]] && echo "all passed" || { echo "$fails failed"; tail -n 20 data/nextcloud.log 2>/dev/null || true; exit 1; }

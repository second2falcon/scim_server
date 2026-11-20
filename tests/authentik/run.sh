#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 SCIM Server contributors
# SPDX-License-Identifier: AGPL-3.0-or-later
#
# Semi real-world test: a real authentik pushes users and groups to the Nextcloud under test over SCIM, using
# the documented setup (user UUID as userName). Everything is created through authentik's REST API; the results
# are checked in Nextcloud. Needs docker, curl, jq, php, and a running Nextcloud reachable from the containers
# as http://host.docker.internal:8089 (tests/integration/setup.sh with NC_BIND=0.0.0.0).
# Usage: NC_DIR=/path/to/server SCIM_TEST_DISPOSABLE_INSTANCE=1 tests/authentik/run.sh
set -euo pipefail
: "${NC_DIR:?set NC_DIR to the Nextcloud server checkout}"
[[ "${SCIM_TEST_DISPOSABLE_INSTANCE:-}" == 1 ]] || { echo "Only for a throw-away Nextcloud. Set SCIM_TEST_DISPOSABLE_INSTANCE=1 to run." >&2; exit 1; }
HERE=$(cd "$(dirname "$0")" && pwd)
AK_URL=${AK_URL:-http://127.0.0.1:9000}
export AK_TOKEN=${AK_TOKEN:-$(openssl rand -hex 24)}
NC_FROM_AK=${NC_FROM_AK:-http://host.docker.internal:8089}
occ() { php "$NC_DIR/occ" "$@"; }
compose() { docker compose -f "$HERE/compose.yml" "$@"; }
fails=0
ok() { echo "ok   - $1"; }
bad() { echo "FAIL - $1 ($2)"; fails=$((fails + 1)); }

# ak METHOD PATH [JSON] -> prints the body, fails on a non-2xx answer
ak() {
	local out status
	out=$(curl -s -w '\n%{http_code}' -X "$1" "$AK_URL/api/v3$2" -H "Authorization: Bearer $AK_TOKEN" \
		-H 'Content-Type: application/json' ${3:+--data "$3"})
	status=${out##*$'\n'}
	if [[ "$status" != 2* ]]; then echo "authentik $1 $2 -> HTTP $status: ${out%$'\n'*}" >&2; return 1; fi
	printf '%s' "${out%$'\n'*}"
}
# What Nextcloud and authentik saw, printed when something does not arrive.
diagnose() {
	[ -z "${DIAGNOSED:-}" ] || return 0
	DIAGNOSED=1
	echo "--- diagnosis"
	echo "Nextcloud accounts:"; occ user:list --output=json | jq -c . || true
	echo "Nextcloud groups:"; occ group:list --output=json | jq -c . || true
	echo "Nextcloud SCIM /Users:"; curl -s -H "Authorization: Bearer $TOKEN" "${NC_LOCAL:-http://127.0.0.1:8089/index.php/apps/scim_server/v2}/Users" | jq -c '[.Resources[]? | {userName, active, id}]' || true
	echo "Nextcloud log:"; tail -n 15 "$NC_DIR/data/nextcloud.log" | cut -c1-400 || true
	echo "authentik sync status:"; ak GET "/providers/scim/${PROVIDER_PK:-0}/sync/status/" | jq -c . || true
	echo "authentik worker, SCIM lines:"; compose logs --no-color worker 2>&1 | grep -i "scim\|sync" | grep -i -v "Imported related module\|Loaded config" | tail -n 25 | cut -c1-500 || true
	echo "--- end diagnosis"
}
# eventually DESCRIPTION COMMAND...: authentik syncs from a worker queue, so give it time
eventually() {
	local what=$1; shift
	for _ in $(seq 30); do
		if "$@" >/dev/null 2>&1; then ok "$what"; return 0; fi
		sleep 3
	done
	bad "$what" "not within 90 seconds"
	diagnose
}

echo "Starting authentik..."
compose up -d
trap 'rc=$?; [ $rc -eq 0 ] || compose logs --no-color --tail 40 worker 2>&1 | tail -n 60; compose down -v >/dev/null 2>&1 || true' EXIT
for _ in $(seq 120); do
	curl -sf -o /dev/null "$AK_URL/-/health/ready/" && ak GET '/core/users/?username=akadmin' >/dev/null 2>&1 && break
	sleep 3
done
ak GET '/core/users/?username=akadmin' | jq -e '.pagination.count == 1' >/dev/null && ok "authentik is up and the API token works" || { bad "authentik is up" "no akadmin via the API"; exit 1; }

echo "Preparing Nextcloud..."
occ config:app:set scim_server allow_http --value=true --type=boolean >/dev/null
occ config:app:set scim_server delete_mode --value=disable_purge >/dev/null
TOKEN=$(occ scim:token:create authentik)

echo "Configuring authentik: users, group, SCIM provider, application..."
alice=$(ak POST /core/users/ '{"username":"alice","name":"Alice Anders","email":"alice@example.test","is_active":true,"path":"users"}')
bob=$(ak POST /core/users/ '{"username":"bob","name":"Bob Bakker","email":"bob@example.test","is_active":true,"path":"users"}')
ALICE=$(jq -r .uuid <<<"$alice"); BOB=$(jq -r .uuid <<<"$bob")
ALICE_PK=$(jq -r .pk <<<"$alice"); BOB_PK=$(jq -r .pk <<<"$bob")
group=$(ak POST /core/groups/ "{\"name\":\"DATA-MGMT\",\"users\":[$ALICE_PK,$BOB_PK]}")
GROUP_PK=$(jq -r .pk <<<"$group")
# The documented mapping: the immutable UUID becomes the Nextcloud user id.
ak POST /propertymappings/provider/scim/ '{"name":"z-uuid","expression":"return {\"userName\": str(request.user.uuid), \"externalId\": str(request.user.uuid)}"}' >/dev/null
# authentik's built-in mappings come from a system blueprint that the worker applies at startup: wait for them.
default_mapping() {
	local pk
	for _ in $(seq 40); do
		pk=$(ak GET "/propertymappings/provider/scim/?managed=$1" | jq -r '.results[0].pk // empty')
		[ -z "$pk" ] || { echo "$pk"; return 0; }
		sleep 3
	done
	echo "the built-in SCIM mapping $1 never appeared" >&2; return 1
}
DEFAULT_USER=$(default_mapping goauthentik.io/providers/scim/user)
DEFAULT_GROUP=$(default_mapping goauthentik.io/providers/scim/group)
ZUUID=$(ak GET '/propertymappings/provider/scim/?search=z-uuid' | jq -r '.results[0].pk')
UMAP=$(jq -nc --arg a "$DEFAULT_USER" --arg b "$ZUUID" '[$a, $b]')   # applied in the order of their names: z-uuid last, so it wins
GMAP=$(jq -nc --arg g "$DEFAULT_GROUP" '[$g]')
provider=$(ak POST /providers/scim/ "$(jq -nc --arg url "$NC_FROM_AK/index.php/apps/scim_server/v2" --arg token "$TOKEN" --argjson u "$UMAP" --argjson g "$GMAP" \
	'{name:"nextcloud-scim",url:$url,token:$token,auth_mode:"token",property_mappings:$u,property_mappings_group:$g,exclude_users_service_account:true}')")
PROVIDER_PK=$(jq -r .pk <<<"$provider")
echo "user mappings $UMAP, group mappings $GMAP, provider $PROVIDER_PK"
app=$(ak POST /core/applications/ "{\"name\":\"Nextcloud\",\"slug\":\"nextcloud\",\"backchannel_providers\":[$PROVIDER_PK]}")
ak POST /policies/bindings/ "{\"target\":\"$(jq -r .pk <<<"$app")\",\"group\":\"$GROUP_PK\",\"order\":0}" >/dev/null

echo "Provisioning"
compose exec -T worker ak scim_sync nextcloud-scim >/dev/null 2>&1 || true
ncuser() { occ user:info "$1" --output=json; }
eventually "alice is created in Nextcloud with her authentik UUID as user id" bash -c "php '$NC_DIR/occ' user:info '$ALICE' --output=json | jq -e '.email == \"alice@example.test\" and .display_name == \"Alice Anders\" and .enabled == true'"
eventually "bob is created in Nextcloud with his authentik UUID as user id" bash -c "php '$NC_DIR/occ' user:info '$BOB' --output=json | jq -e '.email == \"bob@example.test\"'"
eventually "the group DATA-MGMT exists in Nextcloud with both members" bash -c "php '$NC_DIR/occ' group:list --output=json | jq -e '(.\"DATA-MGMT\" // []) | sort == ([\"$ALICE\",\"$BOB\"] | sort)'"

echo "Membership"
ak PATCH "/core/groups/$GROUP_PK/" "{\"users\":[$ALICE_PK]}" >/dev/null
eventually "removing bob from the authentik group removes him from the Nextcloud group" bash -c "php '$NC_DIR/occ' group:list --output=json | jq -e '(.\"DATA-MGMT\" // []) == [\"$ALICE\"]'"

echo "Deprovisioning"
ak PATCH "/core/users/$ALICE_PK/" '{"is_active":false}' >/dev/null
eventually "deactivating alice in authentik disables her account in Nextcloud" bash -c "php '$NC_DIR/occ' user:info '$ALICE' --output=json | jq -e '.enabled == false'"
ak PATCH "/core/users/$ALICE_PK/" '{"is_active":true}' >/dev/null
eventually "reactivating alice enables her account again" bash -c "php '$NC_DIR/occ' user:info '$ALICE' --output=json | jq -e '.enabled == true'"
ak DELETE "/core/users/$BOB_PK/" >/dev/null
eventually "deleting bob in authentik disables his account (delete mode: disable, delete later)" bash -c "php '$NC_DIR/occ' user:info '$BOB' --output=json | jq -e '.enabled == false'"

# ---- Timing: how long until a change in authentik shows up in Nextcloud -------------------------------------
# End to end: the authentik API call returns -> authentik's worker queue and SCIM client -> HTTP -> Nextcloud.
# Nextcloud is polled over SCIM every 100 ms, so every number has about that resolution. One runner, one
# sequential change at a time, so these are latencies of an idle system, not throughput.
N=${TIMING_RUNS:-5}
NC_LOCAL=${NC_LOCAL:-http://127.0.0.1:8089/index.php/apps/scim_server/v2}
declare -A SAMPLES TIMEOUTS
now_us() { local t=$EPOCHREALTIME; echo $(( ${t/./} )); }
nc_get() { curl -s -H "Authorization: Bearer $TOKEN" "$NC_LOCAL$1"; }
# measure LABEL START_US JQ_FILTER PATH: poll until the filter holds, record the milliseconds since START
# A 5th argument "soft" makes a timeout a data point (counted in the report), not a test failure.
measure() {
	local label=$1 t0=$2 filter=$3 path=$4 soft=${5:-}
	[ -z "${TIMING_ABORT:-}" ] || return 1
	for _ in $(seq 600); do
		if nc_get "$path" | jq -e "$filter" >/dev/null 2>&1; then
			SAMPLES[$label]+="$(( ($(now_us) - t0) / 1000 )) "
			return 0
		fi
		sleep 0.1
	done
	if [ -n "$soft" ]; then TIMEOUTS[$label]=$(( ${TIMEOUTS[$label]:-0} + 1 )); echo "     (timeout) $label"; return 1; fi
	bad "timing: $label" "no result within 60 seconds"
	TIMING_ABORT=1
	diagnose
	return 1
}
user_path() { echo "/Users?filter=userName%20eq%20%22$1%22"; }
new_user() { # prints "uuid pk"
	local u; u=$(ak POST /core/users/ "{\"username\":\"$1\",\"name\":\"$1\",\"email\":\"$1@example.test\",\"is_active\":true,\"path\":\"users\",\"groups\":[\"$GROUP_PK\"]}")
	echo "$(jq -r .uuid <<<"$u") $(jq -r .pk <<<"$u")"
}
echo "Timing ($N runs per step)..."
members=1   # alice is still in the group; every user created below joins it
declare -a Y_UUID Y_PK X_UUID X_PK
for i in $(seq "$N"); do   # users that are deleted later, created outside the measurement
	read -r u pk < <(new_user "tdel$i-$RANDOM"); Y_UUID+=("$u"); Y_PK+=("$pk"); members=$((members + 1))
	measure warmup "$(now_us)" '.totalResults == 1' "$(user_path "$u")" || true
done
SAMPLES[warmup]=
for i in $(seq "$N"); do
	t0=$(now_us)
	read -r u pk < <(new_user "tprov$i-$RANDOM"); X_UUID+=("$u"); X_PK+=("$pk"); members=$((members + 1))
	measure "Provisioning: account exists in Nextcloud" "$t0" '.totalResults == 1 and .Resources[0].active == true' "$(user_path "$u")" || true
	sid=$(nc_get "$(user_path "$u")" | jq -r '.Resources[0].id')
	# authentik syncs group membership in a second step; if the account is not synced yet it waits for a later sync
	measure "Provisioning: account is in its group" "$t0" "[.Resources[0].members[].value] | index(\"$sid\") != null" '/Groups?filter=displayName%20eq%20%22DATA-MGMT%22' soft || true
done
for i in $(seq "$N"); do
	t0=$(now_us)
	ak PATCH "/core/users/${X_PK[$((i - 1))]}/" '{"is_active":false}' >/dev/null
	measure "Deprovisioning: user deactivated, account disabled" "$t0" '.Resources[0].active == false' "$(user_path "${X_UUID[$((i - 1))]}")" || true
done
for i in $(seq "$N"); do
	t0=$(now_us)
	ak DELETE "/core/users/${Y_PK[$((i - 1))]}/" >/dev/null
	measure "Deprovisioning: user deleted, account disabled" "$t0" '.Resources[0].active == false' "$(user_path "${Y_UUID[$((i - 1))]}")" || true
done

# Report: a table (job summary), and the raw samples as JSON (uploaded by CI as an artifact).
mapfile -t labels < <(printf '%s\n' "${!SAMPLES[@]}" "${!TIMEOUTS[@]}" | sort -u)
report=$(for label in "${labels[@]}"; do
	[[ "$label" == warmup ]] && continue
	jq -nc --arg l "$label" --arg s "${SAMPLES[$label]}" --argjson t "${TIMEOUTS[$label]:-0}" '($s | split(" ") | map(select(. != "") | tonumber) | sort) as $v
		| {step: $l, runs: ($v | length), timeouts: $t, min_ms: $v[0], median_ms: $v[(($v | length) / 2 | floor)], max_ms: $v[-1], samples_ms: $v}'
done | jq -s 'sort_by(.step)')
nc_version=$(occ status --output=json | jq -r .versionstring)
meta=$(jq -nc --arg ak "${AUTHENTIK_TAG:-2026.8.6}" --arg nc "$nc_version" --arg app "$(php -r 'echo simplexml_load_file("'"$HERE"'/../../appinfo/info.xml")->version;')" --argjson n "$N" \
	'{authentik: $ak, nextcloud: $nc, scim_server: $app, runs_per_step: $n, polling_interval_ms: 100}')
jq -n --argjson meta "$meta" --argjson steps "$report" '{meta: $meta, steps: $steps}' > "${TIMINGS_JSON:-timings.json}"
table=$(jq -r '.steps[] | "| \(.step) | \(.runs) | \(.timeouts) | \(.min_ms) | \(.median_ms) | \(.max_ms) |"' "${TIMINGS_JSON:-timings.json}")
summary=$(printf '### Provisioning latency: authentik %s -> Nextcloud %s (SCIM Server %s)\n\n| Step | Runs | Not within 60 s | Min (ms) | Median (ms) | Max (ms) |\n|---|---|---|---|---|---|\n%s\n\nGroup membership is synced by authentik in a second step; when the account is not synced yet at that moment, authentik leaves it to a later sync, hence the spread and the occasional timeout. End to end, from the authentik API call to the change being visible in Nextcloud, polled every 100 ms. One shared GitHub runner, one change at a time (idle system); numbers vary between runs. Raw samples: the `timings` artifact.\n' \
	"${AUTHENTIK_TAG:-2026.8.6}" "$nc_version" "$(jq -r .meta.scim_server "${TIMINGS_JSON:-timings.json}")" "$table")
echo; echo "$summary"
[ -z "${GITHUB_STEP_SUMMARY:-}" ] || echo "$summary" >> "$GITHUB_STEP_SUMMARY"

echo; [[ $fails == 0 ]] && echo "all passed" || { echo "$fails failed"; tail -n 30 "$NC_DIR/data/nextcloud.log" 2>/dev/null || true; exit 1; }

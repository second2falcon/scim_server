#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 SCIM Server contributors
# SPDX-License-Identifier: AGPL-3.0-or-later
#
# Prepare an installed Nextcloud with the app enabled for run.sh and browser.mjs, and start it with PHP's
# built-in server. Usage: NC_DIR=/path/to/server tests/integration/setup.sh
#   NC_URL: where Nextcloud is served (default http://127.0.0.1:8089).
#   NC_WEB=external: Nextcloud is already served there (nginx + PHP-FPM, AIO): only configure it.
#   NC_BIND: address for PHP's built-in server (default 127.0.0.1; 0.0.0.0 to be reachable from containers).
#   NC_TRUSTED: one more trusted domain, e.g. host.docker.internal.
#   OCC: a command prefix for occ instead of NC_DIR, e.g. for AIO
#        "docker exec -e OC_PASS --user www-data nextcloud-aio-nextcloud php /var/www/html/occ".
set -euo pipefail
NC_URL=${NC_URL:-http://127.0.0.1:8089}
if [ -n "${OCC:-}" ]; then
	occ() { $OCC "$@"; }
else
	: "${NC_DIR:?NC_DIR or OCC must be set}"
	occ() { php "$NC_DIR/occ" "$@"; }
fi

occ config:system:set trusted_domains 1 --value=127.0.0.1
[ -z "${NC_TRUSTED:-}" ] || occ config:system:set trusted_domains 2 --value="$NC_TRUSTED"
occ config:system:set overwrite.cli.url --value="$NC_URL"
# The tests use plain http: make Nextcloud report it, whatever a container image presets (AIO may preset https).
# NC_ASSUME_HTTPS=1 models a TLS reverse proxy in front: Nextcloud reports https, the tests still talk plain http to it.
if [ "${NC_ASSUME_HTTPS:-}" = 1 ]; then
	occ config:system:set overwriteprotocol --value=https
elif [[ "$NC_URL" == http://* ]]; then
	occ config:system:set overwriteprotocol --value=http
fi
occ config:system:set auth.bruteforce.protection.enabled --value=false --type=boolean
# The welcome dialog of the first-run wizard would cover the pages the browser test uses.
occ app:disable firstrunwizard >/dev/null 2>&1 || true

if [ "${NC_WEB:-}" != external ]; then
	PHP_CLI_SERVER_WORKERS=4 php -S "${NC_BIND:-127.0.0.1}:${NC_URL##*:}" -t "$NC_DIR" >/tmp/scim-nc.log 2>&1 &
fi
for _ in $(seq 1 30); do
	curl -sf "$NC_URL/status.php" >/dev/null && exit 0
	sleep 1
done
echo "Nextcloud did not start at $NC_URL" >&2
exit 1

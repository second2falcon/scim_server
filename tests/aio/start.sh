#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 SCIM Server contributors
# SPDX-License-Identifier: AGPL-3.0-or-later
#
# Start Nextcloud All-in-One from its official manual-install compose file and install SCIM Server from its
# release archive into custom_apps, the same way as the manual installation.
# Usage: ADMIN_PASS=... tests/aio/start.sh /path/to/all-in-one /path/to/scim_server
set -euo pipefail
AIO_DIR=$1
APP_DIR=$2
HERE=$(cd "$(dirname "$0")" && pwd)
occ() { docker exec --user www-data nextcloud-aio-nextcloud php /var/www/html/occ "$@"; }

cd "$AIO_DIR/manual-install"
cp sample.conf .env
setv() { sed -i -E "s|^$1=.*|$1=$2|" .env; }
for key in $(grep -oE '^[A-Z_]*(PASSWORD|SECRET|KEY)=' .env | tr -d '='); do
	setv "$key" "ci$(openssl rand -hex 16)"
done
setv NEXTCLOUD_PASSWORD "$ADMIN_PASS"
setv NC_DOMAIN 127.0.0.1
setv APACHE_PORT 11000            # plain http inside: AIO expects a TLS reverse proxy in front (CI runs nginx)
setv APACHE_IP_BINDING 127.0.0.1
setv NEXTCLOUD_STARTUP_APPS '"calendar"'
docker compose -f latest.yml -f "$HERE/compose.override.yml" up -d

echo "Waiting for AIO to install Nextcloud and the startup apps..."
for _ in $(seq 1 150); do
	if curl -sf http://127.0.0.1:11000/status.php | grep -q '"installed":true' \
		&& occ app:list --enabled 2>/dev/null | grep -q ' calendar:'; then
		break
	fi
	sleep 5
done
occ status

echo "Waiting for the Nextcloud container to finish its own setup (healthy)..."
for _ in $(seq 120); do
	[ "$(docker inspect -f '{{.State.Health.Status}}' nextcloud-aio-nextcloud)" = healthy ] && break
	sleep 5
done
docker inspect -f 'container health: {{.State.Health.Status}}' nextcloud-aio-nextcloud

"$APP_DIR/scripts/package.sh" >/dev/null
docker cp "$APP_DIR"/build/scim_server-*.tar.gz nextcloud-aio-nextcloud:/tmp/scim_server.tar.gz
docker exec nextcloud-aio-nextcloud sh -c 'mkdir -p /var/www/html/custom_apps && cd /var/www/html/custom_apps && rm -rf scim_server && tar -xzf /tmp/scim_server.tar.gz && chown -R www-data:www-data scim_server && rm /tmp/scim_server.tar.gz'
occ app:enable scim_server

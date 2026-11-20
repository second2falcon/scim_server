#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 SCIM Server contributors
# SPDX-License-Identifier: AGPL-3.0-or-later
#
# Build the installable app archive: build/scim_server-<version>.tar.gz with a single top-level
# "scim_server/" folder holding only what Nextcloud needs (no tests, docs or dev tooling), and check it.
# Prints only the archive path on stdout. Works with GNU and BSD tar.
#
# Code signing (App Store releases): set SIGN_KEY and SIGN_CERT to the app's private key and
# certificate, and OCC to the occ of any Nextcloud server checkout (it doesn't need to be installed).
set -euo pipefail
cd "$(dirname "$0")/.."
fail() { echo "package.sh: $*" >&2; exit 1; }

# What goes into the archive, and what deliberately doesn't. Every top-level folder must be in one
# of the two lists, so a new runtime folder (l10n, css, ...) can't be left out of releases by accident.
APP=(appinfo js lib templates LICENSES README.md CHANGELOG.md)
DEV_DIRS=(build docs node_modules screenshots scripts tests vendor)
for dir in */; do
	dir=${dir%/}
	[[ " ${APP[*]} ${DEV_DIRS[*]} " == *" $dir "* ]] || fail "unknown top-level folder '$dir': add it to APP or DEV_DIRS"
done
for path in "${APP[@]}"; do
	[[ -e "$path" ]] || fail "'$path' is listed in APP but doesn't exist"
done

version=$(php -r 'echo simplexml_load_file("appinfo/info.xml")->version;')
[[ "$version" =~ ^[0-9]+\.[0-9]+\.[0-9]+(-[0-9A-Za-z.]+)?$ ]] || fail "can't read a version from appinfo/info.xml (got '$version')"

# Copy into a staging folder named like the app, then archive that folder (no tar-specific renaming).
stage=$(mktemp -d)
trap 'rm -rf "$stage"' EXIT
mkdir "$stage/scim_server"
cp -R "${APP[@]}" "$stage/scim_server/"
for f in appinfo/info.xml appinfo/routes.php lib/AppInfo/Application.php lib/Service/UserService.php js/admin.js templates/admin.php \
	LICENSES/AGPL-3.0-or-later.txt; do
	[[ -e "$stage/scim_server/$f" ]] || fail "missing from the archive: $f"
done
if [[ -n "${SIGN_KEY:-}" ]]; then
	[[ -f "$SIGN_KEY" && -f "${SIGN_CERT:-}" && -f "${OCC:-}" ]] || fail "signing needs SIGN_KEY, SIGN_CERT and OCC to be existing files"
	php "$OCC" integrity:sign-app --privateKey="$(cd "$(dirname "$SIGN_KEY")" && pwd)/$(basename "$SIGN_KEY")" \
		--certificate="$(cd "$(dirname "$SIGN_CERT")" && pwd)/$(basename "$SIGN_CERT")" --path="$stage/scim_server" >&2 || fail "occ integrity:sign-app failed"
	[[ -s "$stage/scim_server/appinfo/signature.json" ]] || fail "signing wrote no appinfo/signature.json"
fi

mkdir -p build
rm -f build/scim_server-*.tar.gz
archive="build/scim_server-$version.tar.gz"
tar -C "$stage" -czf "$archive" scim_server
outside=$(tar -tzf "$archive" | grep -v -e '^scim_server/' -e '^scim_server$' -e '^$' || true)
[[ -z "$outside" ]] || fail "archive has entries outside scim_server/: $outside"
# Belt and braces: whatever the lists above say, no tests, CI files, dev tooling or test tooling in a release.
dev=$(tar -tzf "$archive" | grep -E '/(tests|docs|scripts|screenshots|node_modules|vendor|build|\.github)(/|$)|/(composer\.(json|lock)|psalm\.xml|package\.json)$' || true)
[[ -z "$dev" ]] || fail "development files in the archive: $dev"
echo "$archive"

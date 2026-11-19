#!/bin/sh
# Copyright 2026 Jeff Nelson
# Licensed under the Apache License, Version 2.0.
#
# Install a pfSense-pkg-speedtest release (upgrade, reinstall or rollback).
# Usage: update.sh <version> <pkg_url> <sha256>
# Exit: 0 ok, 1 usage/download/install error, 3 checksum mismatch,
#       5 not the expected package, 6 another update is running.
# While it runs, $run_dir/update.lock exists; the package's post-deinstall
# script sees it and keeps the Ookla binary and stored results.
# Test-only env: SPEEDTEST_PKG, SPEEDTEST_PHP, SPEEDTEST_INC, SPEEDTEST_RUN_DIR,
# SPEEDTEST_TEST_ALLOW_FILE_URL=1 (accept file:// URLs).
# SPEEDTEST_UPDATE_LOG=<file>: once the lock is held, send all output there
# (the GUI starts it detached and shows this file).
set -eu

name=pfSense-pkg-speedtest
if [ $# -ne 3 ]; then
	echo "usage: $0 <version> <pkg_url> <sha256>" >&2
	exit 1
fi
version=$1
url=$2
sha=$3
pkg=${SPEEDTEST_PKG:-/usr/local/sbin/pkg-static}
php=${SPEEDTEST_PHP:-/usr/local/bin/php}
inc=${SPEEDTEST_INC:-/usr/local/pkg/speedtest.inc}
run_dir=${SPEEDTEST_RUN_DIR:-/var/run/speedtest}

if ! printf '%s\n' "$version" | grep -Eqx '[0-9]{1,6}\.[0-9]{1,6}\.[0-9]{1,6}'; then
	echo "invalid version: $version" >&2
	exit 1
fi
if ! printf '%s\n' "$sha" | grep -Eqx '[0-9a-f]{64}'; then
	echo "invalid sha256" >&2
	exit 1
fi
case $url in
https://github.com/*/releases/download/v"$version"/"$name-$version".pkg) ;;
file://*/"$name-$version".pkg)
	if [ "${SPEEDTEST_TEST_ALLOW_FILE_URL:-}" != 1 ]; then
		echo "refusing URL: $url" >&2
		exit 1
	fi
	;;
*)
	echo "refusing URL: $url" >&2
	exit 1
	;;
esac

# pkg replaces this file while it runs; carry on from a private copy.
if [ -z "${SPEEDTEST_UPDATE_COPY:-}" ]; then
	copy=$(mktemp "${TMPDIR:-/tmp}/speedtest-update.XXXXXX")
	cp "$0" "$copy"
	SPEEDTEST_UPDATE_COPY=$copy
	export SPEEDTEST_UPDATE_COPY
	exec /bin/sh "$copy" "$@"
fi

log() { printf '%s %s\n' "$(date '+%Y-%m-%d %H:%M:%S')" "$*"; }

mkdir -p "$run_dir"
# A lock left by a crashed run (its pid is gone) is stale.
if [ -d "$run_dir/update.lock" ]; then
	old=$(cat "$run_dir/update.lock/pid" 2>/dev/null || true)
	if [ -n "$old" ] && ! kill -0 "$old" 2>/dev/null; then
		rm -rf "$run_dir/update.lock"
	fi
fi
if ! mkdir "$run_dir/update.lock" 2>/dev/null; then
	echo "another update is already running" >&2
	rm -f "$SPEEDTEST_UPDATE_COPY"
	exit 6
fi
echo $$ >"$run_dir/update.lock/pid"
if [ -n "${SPEEDTEST_UPDATE_LOG:-}" ]; then
	exec >"$SPEEDTEST_UPDATE_LOG" 2>&1
fi
tmpdir=$(mktemp -d "${TMPDIR:-/tmp}/speedtest-update.XXXXXX")
rm -f "$run_dir/update.rc"
# The exit status is left in update.rc for the GUI.
trap 'echo $? >"$run_dir/update.rc"; rm -rf "$tmpdir" "$run_dir/update.lock" "$SPEEDTEST_UPDATE_COPY"' EXIT

sha256_of() {
	if command -v sha256 >/dev/null 2>&1; then
		sha256 -q "$1"
	else
		shasum -a 256 "$1" | cut -d' ' -f1
	fi
}

installed=$("$pkg" query %v "$name" 2>/dev/null || true)
log "updating $name ${installed:-(not installed)} -> $version"

file="$tmpdir/$name-$version.pkg"
log "downloading $url"
if command -v fetch >/dev/null 2>&1; then
	fetch -q -T 60 -o "$file" "$url" || { log "download failed"; exit 1; }
else
	curl -fsSL --max-time 120 -o "$file" "$url" || { log "download failed"; exit 1; }
fi

got=$(sha256_of "$file")
if [ "$got" != "$sha" ]; then
	log "checksum mismatch: expected $sha, got $got"
	exit 3
fi
log "checksum ok ($sha)"

meta=$("$pkg" query -F "$file" '%n %v' 2>/dev/null || true)
if [ "$meta" != "$name $version" ]; then
	log "package file is '${meta:-unreadable}', expected '$name $version'"
	exit 5
fi

# pkg add upgrades in place to a newer version; same or older needs -f.
force=
# shellcheck disable=SC2016 # PHP code, not shell expansion
if [ -n "$installed" ] && ! "$php" -r 'exit(version_compare($argv[1], $argv[2], ">") ? 0 : 1);' "$version" "$installed"; then
	force=-f
fi
log "running pkg add ${force:+$force }$name-$version.pkg"
if ! ASSUME_ALWAYS_YES=yes "$pkg" add $force "$file" 2>&1; then
	log "pkg add failed"
	exit 1
fi

# Reapply cron and data-dir settings in case the deinstall hooks removed them.
# shellcheck disable=SC2016 # PHP code, not shell expansion
"$php" -r 'require $argv[1]; speedtest_resync();' "$inc" 2>&1 || log "warning: settings resync failed"

now=$("$pkg" query %v "$name" 2>/dev/null || true)
log "done: $name ${now:-?} installed"

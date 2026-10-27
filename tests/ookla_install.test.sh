#!/bin/sh
# Copyright 2026 Jeff Nelson
# Licensed under the Apache License, Version 2.0.
#
# Network test: downloads the real Ookla pkg once. SPEEDTEST_OFFLINE=1 skips.
set -eu
if [ "${SPEEDTEST_OFFLINE:-}" = 1 ]; then
	echo "skipped (offline)"
	exit 0
fi
root=$(cd "$(dirname "$0")/.." && pwd)
manifest="$root/files/usr/local/share/pfSense-pkg-speedtest/ookla-manifest.json"
installer="$root/files/usr/local/libexec/speedtest/ookla-install.sh"
tmp=$(mktemp -d)
trap 'rm -rf "$tmp"' EXIT
SPEEDTEST_PHP=$(command -v php)
SPEEDTEST_LIB="$root/files/usr/local/pkg/speedtest_lib.inc"
export SPEEDTEST_PHP SPEEDTEST_LIB SPEEDTEST_SKIP_EXEC=1

fail() { echo "FAIL: $1"; exit 1; }
sha() {
	if command -v sha256 >/dev/null 2>&1; then
		sha256 -q "$1"
	else
		shasum -a 256 "$1" | cut -d' ' -f1
	fi
}

# Happy path: FreeBSD 16 falls back to the freebsd13 build.
rc=0
out=$(sh "$installer" "$manifest" 16 "$tmp/speedtest") || rc=$?
[ "$rc" -eq 0 ] || fail "exit $rc: $out"
[ -f "$tmp/speedtest" ] || fail "dest missing"
[ ! -e "$tmp/speedtest.tmp" ] || fail "tmp file left behind"
want=$(sed -n 's/.*"freebsd_major":13.*"bin_sha256":"\([0-9a-f]*\)".*/\1/p' "$manifest")
[ -n "$want" ] || fail "could not read bin_sha256 from manifest"
[ "$(sha "$tmp/speedtest")" = "$want" ] || fail "binary sha mismatch"
case "$out" in *"installed 1.2.0 (freebsd13)"*) ;; *) fail "unexpected output: $out" ;; esac

# No build for FreeBSD 11 -> exit 2.
rc=0
sh "$installer" "$manifest" 11 "$tmp/none" >/dev/null 2>&1 || rc=$?
[ "$rc" -eq 2 ] || fail "no-build exit $rc, want 2"
[ ! -e "$tmp/none" ] || fail "dest created without a build"

# Tampered pkg checksum -> exit 3, no dest.
sed 's/"pkg_sha256":"23b2/"pkg_sha256":"0000/' "$manifest" >"$tmp/bad-pkg.json"
rc=0
sh "$installer" "$tmp/bad-pkg.json" 16 "$tmp/bad1" >/dev/null 2>&1 || rc=$?
[ "$rc" -eq 3 ] || fail "tampered pkg exit $rc, want 3"
[ ! -e "$tmp/bad1" ] || fail "dest exists after pkg checksum failure"

# Tampered binary checksum -> exit 3, no dest, no tmp.
sed 's/"bin_sha256":"a3a6/"bin_sha256":"0000/' "$manifest" >"$tmp/bad-bin.json"
rc=0
sh "$installer" "$tmp/bad-bin.json" 16 "$tmp/bad2" >/dev/null 2>&1 || rc=$?
[ "$rc" -eq 3 ] || fail "tampered bin exit $rc, want 3"
[ ! -e "$tmp/bad2" ] || fail "dest exists after bin checksum failure"
[ ! -e "$tmp/bad2.tmp" ] || fail "tmp left after bin checksum failure"
echo ok

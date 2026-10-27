#!/bin/sh
# Copyright 2026 Jeff Nelson
# Licensed under the Apache License, Version 2.0.
#
# The embedded post-deinstall script must keep the Ookla binary and results
# during `pkg upgrade` (PKG_UPGRADE set) and remove them on a real deinstall.
set -u
base=$(cd "$(dirname "$0")/.." && pwd)
tmp=$(mktemp -d)
trap 'rm -rf "$tmp"' EXIT
rc=0
fail() { echo "FAIL $1"; rc=1; }

# shellcheck disable=SC2016 # PHP code, not shell
php -r '$m = json_decode(stream_get_contents(STDIN), true); echo $m["scripts"]["post-deinstall"];' \
	<<EOT >"$tmp/post-deinstall.sh"
$(php "$base/pkg/gen-manifest.php" 0.0.0)
EOT
grep -q 'set -- pfSense-pkg-speedtest POST-DEINSTALL' "$tmp/post-deinstall.sh" || fail "phase not set"
grep -q 'PKG_UPGRADE' "$tmp/post-deinstall.sh" || fail "no PKG_UPGRADE guard"

# Run it with the destructive commands' paths redirected into $tmp.
sbox() {
	sed -e "s#/usr/local/bin/php -f /etc/rc.packages#true#" \
		-e "s#/usr/local/libexec/speedtest#$tmp/libexec#g" \
		-e "s#/var/db/speedtest#$tmp/db#g" \
		-e "s#/var/run/speedtest#$tmp/run#g" "$tmp/post-deinstall.sh" >"$tmp/sbox.sh"
	mkdir -p "$tmp/libexec" "$tmp/db" "$tmp/run"
	: >"$tmp/libexec/ookla-speedtest"
	: >"$tmp/db/.remove-on-deinstall"
}

sbox
(PKG_UPGRADE=true; export PKG_UPGRADE; sh "$tmp/sbox.sh") || fail "upgrade run failed"
[ -e "$tmp/libexec/ookla-speedtest" ] || fail "upgrade removed the Ookla binary"
[ -d "$tmp/db" ] || fail "upgrade wiped the data dir"

sbox
(unset PKG_UPGRADE; sh "$tmp/sbox.sh") || fail "deinstall run failed"
[ ! -e "$tmp/libexec/ookla-speedtest" ] || fail "deinstall kept the Ookla binary"
[ ! -e "$tmp/db" ] || fail "deinstall kept data despite the removal marker"
[ ! -e "$tmp/run" ] || fail "deinstall kept the runtime dir"

[ "$rc" -eq 0 ] && echo "ok pkg scripts"
exit "$rc"

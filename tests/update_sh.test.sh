#!/bin/sh
# Copyright 2026 Jeff Nelson
# Licensed under the Apache License, Version 2.0.
#
# update.sh against a fake pkg-static: checksum and package checks, upgrade vs
# forced rollback, the lock the deinstall hook relies on, log and exit status.
set -u
base=$(cd "$(dirname "$0")/.." && pwd)
script="$base/files/usr/local/libexec/speedtest/update.sh"
tmp=$(mktemp -d)
trap 'rm -rf "$tmp"' EXIT
rc=0
fail() { echo "FAIL $1"; rc=1; }

sha256_of() {
	if command -v sha256 >/dev/null 2>&1; then sha256 -q "$1"; else shasum -a 256 "$1" | cut -d' ' -f1; fi
}

cat >"$tmp/pkg-static" <<'PKG'
#!/bin/sh
echo "$*" >>"$FAKE_LOG"
case $1 in
query)
	if [ "$2" = -F ]; then echo "$FAKE_META"; else cat "$FAKE_STATE" 2>/dev/null || exit 1; fi ;;
add)
	[ -d "$SPEEDTEST_RUN_DIR/update.lock" ] && echo "lock held during add" >>"$FAKE_LOG"
	[ -n "${FAKE_ADD_FAIL:-}" ] && exit 70
	echo "$FAKE_NEW" >"$FAKE_STATE" ;;
esac
PKG
chmod +x "$tmp/pkg-static"
cat >"$tmp/stub.inc" <<'INC'
<?php
function speedtest_resync(): void { touch(getenv('FAKE_RESYNC')); }
INC

mkdir -p "$tmp/rel/v0.3.0" "$tmp/rel/v0.2.2"
printf 'new package' >"$tmp/rel/v0.3.0/pfSense-pkg-speedtest-0.3.0.pkg"
printf 'old package' >"$tmp/rel/v0.2.2/pfSense-pkg-speedtest-0.2.2.pkg"
sha_new=$(sha256_of "$tmp/rel/v0.3.0/pfSense-pkg-speedtest-0.3.0.pkg")
sha_old=$(sha256_of "$tmp/rel/v0.2.2/pfSense-pkg-speedtest-0.2.2.pkg")
url_new="file://$tmp/rel/v0.3.0/pfSense-pkg-speedtest-0.3.0.pkg"
url_old="file://$tmp/rel/v0.2.2/pfSense-pkg-speedtest-0.2.2.pkg"

# Fresh state; $1 = installed version. Sets the env update.sh reads.
setup() {
	rm -rf "$tmp/run" "$tmp/t"
	mkdir -p "$tmp/t"
	: >"$tmp/pkg.log"
	rm -f "$tmp/resynced"
	echo "$1" >"$tmp/state"
	export SPEEDTEST_PKG="$tmp/pkg-static" SPEEDTEST_PHP=php SPEEDTEST_INC="$tmp/stub.inc" \
		SPEEDTEST_RUN_DIR="$tmp/run" SPEEDTEST_TEST_ALLOW_FILE_URL=1 TMPDIR="$tmp/t" \
		FAKE_LOG="$tmp/pkg.log" FAKE_STATE="$tmp/state" FAKE_RESYNC="$tmp/resynced"
	unset FAKE_ADD_FAIL SPEEDTEST_UPDATE_LOG
}
run() { sh "$script" "$@" >"$tmp/out" 2>&1; echo $?; }

# upgrade
setup 0.2.2
FAKE_META="pfSense-pkg-speedtest 0.3.0" FAKE_NEW=0.3.0; export FAKE_META FAKE_NEW
[ "$(run 0.3.0 "$url_new" "$sha_new")" = 0 ] || { fail "upgrade exit"; cat "$tmp/out"; }
grep -Eq '^add /.*/pfSense-pkg-speedtest-0.3.0.pkg$' "$tmp/pkg.log" || fail "upgrade: pkg add without -f"
grep -q -- 'add -f' "$tmp/pkg.log" && fail "upgrade used -f"
grep -q 'lock held during add' "$tmp/pkg.log" || fail "upgrade: lock not held during pkg add"
[ ! -e "$tmp/run/update.lock" ] || fail "upgrade: lock left behind"
[ "$(cat "$tmp/run/update.rc")" = 0 ] || fail "upgrade: update.rc not 0"
[ -e "$tmp/resynced" ] || fail "upgrade: no resync"
grep -q 'done: pfSense-pkg-speedtest 0.3.0 installed' "$tmp/out" || fail "upgrade: no done line"
[ -z "$(ls "$tmp/t")" ] || fail "upgrade: temp files left: $(ls "$tmp/t")"

# rollback needs -f
setup 0.3.0
FAKE_META="pfSense-pkg-speedtest 0.2.2" FAKE_NEW=0.2.2
[ "$(run 0.2.2 "$url_old" "$sha_old")" = 0 ] || { fail "rollback exit"; cat "$tmp/out"; }
grep -Eq '^add -f /.*/pfSense-pkg-speedtest-0.2.2.pkg$' "$tmp/pkg.log" || fail "rollback: no pkg add -f"

# reinstall of the same version needs -f
setup 0.2.2
[ "$(run 0.2.2 "$url_old" "$sha_old")" = 0 ] || fail "reinstall exit"
grep -q '^add -f ' "$tmp/pkg.log" || fail "reinstall: no -f"

# checksum mismatch: nothing installed
setup 0.2.2
FAKE_META="pfSense-pkg-speedtest 0.3.0" FAKE_NEW=0.3.0
[ "$(run 0.3.0 "$url_new" "$sha_old")" = 3 ] || fail "mismatch exit"
grep -q '^add' "$tmp/pkg.log" && fail "mismatch: pkg add ran"
[ "$(cat "$tmp/run/update.rc")" = 3 ] || fail "mismatch: update.rc not 3"

# file is some other package
setup 0.2.2
FAKE_META="pfSense-pkg-evil 0.3.0"
[ "$(run 0.3.0 "$url_new" "$sha_new")" = 5 ] || fail "wrong package exit"
grep -q '^add' "$tmp/pkg.log" && fail "wrong package: pkg add ran"
FAKE_META="pfSense-pkg-speedtest 0.3.0"

# pkg add fails
setup 0.2.2
FAKE_ADD_FAIL=1; export FAKE_ADD_FAIL
[ "$(run 0.3.0 "$url_new" "$sha_new")" = 1 ] || fail "pkg add failure exit"
[ ! -e "$tmp/resynced" ] || fail "pkg add failure: resync ran"
unset FAKE_ADD_FAIL

# argument checks
setup 0.2.2
[ "$(run 0.3.0 "https://evil.example/pfSense-pkg-speedtest-0.3.0.pkg" "$sha_new")" = 1 ] || fail "foreign URL accepted"
[ "$(run 0.3.0 "https://github.com/x/y/releases/download/v0.2.2/pfSense-pkg-speedtest-0.3.0.pkg" "$sha_new")" = 1 ] || fail "URL for another tag accepted"
[ "$(run '0.3.0;id' "$url_new" "$sha_new")" = 1 ] || fail "bad version accepted"
[ "$(run 0.3.0 "$url_new" nothex)" = 1 ] || fail "bad sha accepted"
[ "$(run 0.3.0 "$url_new")" = 1 ] || fail "missing arg accepted"
(unset SPEEDTEST_TEST_ALLOW_FILE_URL; [ "$(run 0.3.0 "$url_new" "$sha_new")" = 1 ]) || fail "file URL accepted outside tests"
[ ! -e "$tmp/pkg.log" ] || ! grep -q . "$tmp/pkg.log" || fail "argument errors still ran pkg"

# a live lock blocks; a dead one is cleared
setup 0.2.2
mkdir -p "$tmp/run/update.lock"
echo $$ >"$tmp/run/update.lock/pid"
[ "$(run 0.3.0 "$url_new" "$sha_new")" = 6 ] || fail "live lock did not block"
[ -d "$tmp/run/update.lock" ] || fail "live lock removed"
[ -z "$(ls "$tmp/t")" ] || fail "blocked run left its copy"
sh -c 'exit 0' &
dead=$!
wait "$dead"
echo "$dead" >"$tmp/run/update.lock/pid"
[ "$(run 0.3.0 "$url_new" "$sha_new")" = 0 ] || fail "stale lock not cleared"

# SPEEDTEST_UPDATE_LOG gets the output
setup 0.2.2
SPEEDTEST_UPDATE_LOG="$tmp/run/update.log"; export SPEEDTEST_UPDATE_LOG
mkdir -p "$tmp/run"
[ "$(run 0.3.0 "$url_new" "$sha_new")" = 0 ] || fail "logged run exit"
grep -q 'checksum ok' "$tmp/run/update.log" || fail "log file lacks progress"
grep -q . "$tmp/out" && fail "logged run still wrote to stdout"

[ "$rc" -eq 0 ] && echo "ok update.sh"
exit "$rc"

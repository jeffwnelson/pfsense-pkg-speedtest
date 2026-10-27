#!/bin/sh
# Copyright 2026 Jeff Nelson
# Licensed under the Apache License, Version 2.0.
#
# build.sh --dry-run stages the tree, substitutes the version, renders plist+manifest.
set -eu
root=$(cd "$(dirname "$0")/.." && pwd)
cd "$root"
fail() { echo "FAIL: $1"; exit 1; }

out=$(sh build.sh 9.8.7 --dry-run) || fail "build.sh failed: $out"
case $out in *"pkg create"*) ;; *) fail "no pkg create line: $out" ;; esac

(cd files && find . -type f ! -name .gitkeep) | while IFS= read -r f; do
	[ -f "stage/$f" ] || fail "missing from stage: $f"
done
if grep -rq '%%PKGVERSION%%' stage; then fail "unsubstituted %%PKGVERSION%% left"; fi
grep -q '<version>9.8.7</version>' stage/usr/local/pkg/speedtest.xml || fail "version not substituted"
[ -x stage/usr/local/libexec/speedtest/run.sh ] || fail "run.sh lost its exec bit"
[ ! -x stage/usr/local/pkg/speedtest.inc ] || fail "speedtest.inc should not be executable"

grep -qx '/usr/local/share/pfSense-pkg-speedtest/info.xml' plist || fail "plist lacks info.xml"
if grep -q '^stage' plist; then fail "plist has stage prefix"; fi

# shellcheck disable=SC2016
php -r '
$m = json_decode(file_get_contents("manifest.json"), true);
if (!is_array($m)) { fwrite(STDERR, "invalid JSON\n"); exit(1); }
if ($m["name"] !== "pfSense-pkg-speedtest" || $m["version"] !== "9.8.7") { exit(2); }
foreach (["post-install", "pre-deinstall", "post-deinstall"] as $k) {
	if (strpos($m["scripts"][$k], "set -- pfSense-pkg-speedtest") === false) { exit(3); }
}
' || fail "manifest check failed (rc $?)"
rm -rf stage dist plist manifest.json
echo "ok"

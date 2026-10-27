#!/bin/sh
# Copyright 2026 Jeff Nelson
# Licensed under the Apache License, Version 2.0.
#
# Detached speedtest runner for one gateway.
# Usage: run.sh <gwname> <ifname> <addr> <server_id|0> <root> <run_dir> <bin> <cap>
#   root:    persistent result store (<root>/<gw>/latest.json, history.jsonl)
#   run_dir: runtime state: lock, running.json, others.json, gwcfg.json, Ookla output
# Env: SPEEDTEST_TIMEOUT (seconds, default 90), SPEEDTEST_PHP, SPEEDTEST_LIB.
# Not run with -e on purpose: the finish step must always record a result.
set -u

if [ "$#" -ne 8 ]; then
	echo "usage: $0 gwname ifname addr server_id root run_dir bin cap" >&2
	exit 2
fi
gw=$1 ifname=$2 addr=$3 server_id=$4 root=$5 run_dir=$6 bin=$7 cap=$8

here=$(dirname "$0")
# The detached runner inherits a minimal PATH, so do not rely on it for php.
if [ -n "${SPEEDTEST_PHP:-}" ]; then
	php=$SPEEDTEST_PHP
elif [ -x /usr/local/bin/php ]; then
	php=/usr/local/bin/php
else
	php=php
fi
SPEEDTEST_TIMEOUT=${SPEEDTEST_TIMEOUT:-90}
export SPEEDTEST_TIMEOUT
others="$run_dir/others.json"
gwcfg="$run_dir/gwcfg.json"
out="$run_dir/ookla.stdout"
err="$run_dir/ookla.stderr"

trap 'rm -f "$run_dir/running.json" "$run_dir/lock" "$out" "$err"' EXIT
trap 'exit 1' INT TERM

printf '{"gateway":"%s","started_at":"%s","pid":%s}\n' \
	"$gw" "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$$" >"$run_dir/running.json"

: >"$out"
: >"$err"
code=0
if [ -z "$addr" ]; then
	SPEEDTEST_PRESET_ERROR="interface has no address"
	export SPEEDTEST_PRESET_ERROR
else
	if command -v timeout >/dev/null 2>&1; then
		tmo=timeout
	elif command -v gtimeout >/dev/null 2>&1; then
		tmo=gtimeout
	else
		tmo= # no timeout(1) available: run unbounded
	fi
	set -- -f json --accept-license --accept-gdpr "--ip=$addr" --selection-details
	if [ "$server_id" != 0 ]; then
		set -- "$@" "--server-id=$server_id"
	fi
	if [ -n "$tmo" ]; then
		"$tmo" -k 5 "$SPEEDTEST_TIMEOUT" "$bin" "$@" >"$out" 2>"$err"
	else
		"$bin" "$@" >"$out" 2>"$err"
	fi
	code=$?
fi

finish() {
	"$php" -f "$here/finish.php" "$gw" "$ifname" "$addr" "$root" "$cap" \
		"$others" "$gwcfg" "$out" "$err" "$code"
}

finish
frc=$?
[ "$frc" -eq 0 ] && exit 0

# finish.php failed: record an error so the row never stays stale. Try PHP once
# more with a preset error, then fall back to writing the record directly.
SPEEDTEST_PRESET_ERROR="finish.php failed (rc $frc)"
export SPEEDTEST_PRESET_ERROR
finish && exit 1
mkdir -p "$root/$gw" || exit 1
rec=$(printf '{"gateway":"%s","interface":"%s","address":"%s","ran_at":"%s","state":"error","error":"%s"}' \
	"$gw" "$ifname" "$addr" "$(date +%Y-%m-%dT%H:%M:%S%z | sed 's/\(..\)$/:\1/')" "$SPEEDTEST_PRESET_ERROR")
printf '%s\n' "$rec" >"$root/$gw/latest.json.tmp.$$" &&
	mv -f "$root/$gw/latest.json.tmp.$$" "$root/$gw/latest.json"
printf '%s\n' "$rec" >>"$root/$gw/history.jsonl"
exit 1

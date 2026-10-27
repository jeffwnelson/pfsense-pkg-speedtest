#!/bin/sh
# Copyright 2026 Jeff Nelson
# Licensed under the Apache License, Version 2.0.
#
# Tests run.sh + finish.php end to end against a fake Ookla binary.
set -u
base=$(cd "$(dirname "$0")/.." && pwd)
run="$base/files/usr/local/libexec/speedtest/run.sh"
fake="$base/tests/fixtures/fake-ookla.sh"
SPEEDTEST_PHP=$(command -v php)
SPEEDTEST_LIB="$base/files/usr/local/pkg/speedtest_lib.inc"
export SPEEDTEST_PHP SPEEDTEST_LIB
tmp=$(mktemp -d)
trap 'rm -rf "$tmp"' EXIT
rc=0

fail() { echo "FAIL $1: $2"; rc=1; }

# Env vars are exported inside subshells: FreeBSD sh does not export prefix assignments to functions.
# field <root> <key>: read a key from METRO_GW's latest.json.
field() {
	# shellcheck disable=SC2016 # PHP code, not shell
	php -r '$r = json_decode(file_get_contents($argv[1] . "/METRO_GW/latest.json"), true); echo $r[$argv[2]] ?? "";' "$1" "$2"
}

# setup <name> <others-json>: fresh store root plus a runtime dir holding the
# lock, running.json and runner inputs, as speedtest_start() leaves them.
setup() {
	root="$tmp/$1/db"
	run_dir="$tmp/$1/run"
	mkdir -p "$root" "$run_dir"
	: >"$run_dir/lock"
	echo '{}' >"$run_dir/running.json"
	echo "$2" >"$run_dir/others.json"
	echo '{"advertised_down":2000,"advertised_up":null,"threshold_pct":80,"server_id":null}' >"$run_dir/gwcfg.json"
}

# go <addr> <bin>: run the runner synchronously (argument order of run.sh's usage line).
go() {
	sh "$run" METRO_GW ixl5 "$1" 0 "$root" "$run_dir" "$2" 100
}

cleaned() {
	[ ! -e "$run_dir/running.json" ] || fail "$1" "running.json left behind"
	[ ! -e "$run_dir/lock" ] || fail "$1" "lock left behind"
	[ ! -e "$run_dir/ookla.stdout" ] && [ ! -e "$run_dir/ookla.stderr" ] || fail "$1" "temp output left behind"
	[ -z "$(find "$root" -name '*.tmp.*')" ] || fail "$1" "temp record left behind"
}

NOOTHERS='{"QUANTUM_GW":{"address":"198.51.100.1","last_public_ip":null}}'

setup runner_success "$NOOTHERS"
go 203.0.113.45 "$fake"
[ "$(field "$root" state)" = pass ] || fail runner_success "state=$(field "$root" state)"
[ "$(field "$root" external_ip)" = 203.0.113.45 ] || fail runner_success "external_ip"
[ "$(field "$root" advertised_down)" = 2000 ] || fail runner_success "advertised_down"
cleaned runner_success

setup runner_no_address "$NOOTHERS"
(export FAKE_MARKER="$root/marker"; go "" "$fake")
[ "$(field "$root" state)" = error ] || fail runner_no_address "state"
[ "$(field "$root" error)" = "interface has no address" ] || fail runner_no_address "error=$(field "$root" error)"
[ ! -e "$root/marker" ] || fail runner_no_address "binary was invoked"
cleaned runner_no_address

setup runner_missing_binary "$NOOTHERS"
go 203.0.113.45 /nonexistent
[ "$(field "$root" state)" = error ] || fail runner_missing_binary "state"
case "$(field "$root" error)" in
*"not found"* | *"No such file"*) ;;
*) fail runner_missing_binary "error=$(field "$root" error)" ;;
esac
cleaned runner_missing_binary

setup runner_nonzero_exit "$NOOTHERS"
(export FAKE_EXIT=1 FAKE_STDERR=boom; go 203.0.113.45 "$fake")
[ "$(field "$root" state)" = error ] || fail runner_nonzero_exit "state"
case "$(field "$root" error)" in
*boom*) ;;
*) fail runner_nonzero_exit "error=$(field "$root" error)" ;;
esac
cleaned runner_nonzero_exit

setup runner_timeout "$NOOTHERS"
start=$(date +%s)
(export FAKE_DELAY=5 SPEEDTEST_TIMEOUT=1; go 203.0.113.45 "$fake")
elapsed=$(($(date +%s) - start))
[ "$(field "$root" state)" = error ] || fail runner_timeout "state"
[ "$(field "$root" error)" = "timed out after 1s" ] || fail runner_timeout "error=$(field "$root" error)"
[ "$elapsed" -lt 4 ] || fail runner_timeout "took ${elapsed}s"
cleaned runner_timeout

setup runner_egress_mismatch '{"QUANTUM_GW":{"address":"203.0.113.45","last_public_ip":null}}'
go 203.0.113.45 "$fake"
[ "$(field "$root" state)" = egress_mismatch ] || fail runner_egress_mismatch "state=$(field "$root" state)"
cleaned runner_egress_mismatch

# finish.php cannot load the library (or fails at all): run.sh still records an error.
setup runner_finish_failure_writes_error "$NOOTHERS"
(export SPEEDTEST_LIB=/nonexistent; go 203.0.113.45 "$fake" >/dev/null 2>&1)
[ "$(field "$root" state)" = error ] || fail runner_finish_failure_writes_error "state=$(field "$root" state)"
case "$(field "$root" error)" in
"finish.php failed (rc "[1-9]*")") ;;
*) fail runner_finish_failure_writes_error "error=$(field "$root" error)" ;;
esac
[ "$(wc -l <"$root/METRO_GW/history.jsonl" | tr -d ' ')" = 1 ] || fail runner_finish_failure_writes_error "history not appended once"
cleaned runner_finish_failure_writes_error

# SPEEDTEST_PHP unset: run.sh must find php itself (/usr/local/bin/php, else PATH).
setup runner_default_php "$NOOTHERS"
(unset SPEEDTEST_PHP; go 203.0.113.45 "$fake")
[ "$(field "$root" state)" = pass ] || fail runner_default_php "state=$(field "$root" state)"
cleaned runner_default_php

setup runner_scheduled_trigger "$NOOTHERS"
echo '{"advertised_down":2000,"advertised_up":null,"threshold_pct":80,"server_id":null,"trigger":"scheduled"}' >"$run_dir/gwcfg.json"
go 203.0.113.45 "$fake"
[ "$(field "$root" trigger)" = scheduled ] || fail runner_scheduled_trigger "trigger=$(field "$root" trigger)"
cleaned runner_scheduled_trigger

setup runner_default_trigger "$NOOTHERS"
go 203.0.113.45 "$fake"
[ "$(field "$root" trigger)" = manual ] || fail runner_default_trigger "trigger=$(field "$root" trigger)"
cleaned runner_default_trigger

[ "$rc" -eq 0 ] && echo "ok runner (10 cases)"
exit "$rc"

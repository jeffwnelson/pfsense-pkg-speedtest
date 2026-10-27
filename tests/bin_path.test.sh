#!/bin/sh
# Copyright 2026 Jeff Nelson
# Licensed under the Apache License, Version 2.0.
#
# Regression guard: the runner must use the Ookla binary under libexec, never the
# py311-speedtest-cli wrapper at /usr/local/bin/speedtest.
base=$(cd "$(dirname "$0")/.." && pwd)
inc="$base/files/usr/local/pkg/speedtest.inc"
rc=0
grep -q "SPEEDTEST_LIBEXEC . '/ookla-speedtest'" "$inc" || { echo "FAIL: SPEEDTEST_BIN not derived from SPEEDTEST_LIBEXEC/ookla-speedtest"; rc=1; }
grep -q "/usr/local/libexec/speedtest'" "$inc" || { echo "FAIL: SPEEDTEST_LIBEXEC missing"; rc=1; }
if grep -q '/usr/local/bin/speedtest' "$inc"; then echo "FAIL: speedtest.inc references /usr/local/bin/speedtest"; rc=1; fi
exit $rc

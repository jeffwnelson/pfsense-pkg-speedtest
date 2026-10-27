#!/bin/sh
# Copyright 2026 Jeff Nelson
# Licensed under the Apache License, Version 2.0.
#
# Test double for the Ookla CLI. Behaviour is driven by FAKE_* env vars.
touch "${FAKE_MARKER:-/dev/null}"
sleep "${FAKE_DELAY:-0}"
[ -n "${FAKE_STDERR:-}" ] && echo "$FAKE_STDERR" >&2
cat "$(dirname "$0")/ookla_full.json"
exit "${FAKE_EXIT:-0}"

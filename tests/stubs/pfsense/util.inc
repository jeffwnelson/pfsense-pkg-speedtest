<?php
/*
 * Copyright 2026 Jeff Nelson
 * Licensed under the Apache License, Version 2.0.
 *
 * Test stub for pfSense's util.inc. mwexec_bg() records the command in
 * $GLOBALS['stub_mwexec_bg'] instead of running it.
 */
function mwexec_bg(string $command, bool $clearsigmask = false, bool $nohup = true): void {
	$GLOBALS['stub_mwexec_bg'][] = $command;
}

function mwexec(string $command, bool $nologentry = false, bool $clearsigmask = false, bool $background = false): int {
	$GLOBALS['stub_mwexec'][] = $command;
	return 0;
}

function log_error(string $error): void {
	$GLOBALS['stub_log'][] = $error;
}


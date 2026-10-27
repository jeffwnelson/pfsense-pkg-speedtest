<?php
/*
 * Copyright 2026 Jeff Nelson
 * Licensed under the Apache License, Version 2.0.
 *
 * Turn a finished Ookla run into a stored record.
 * Usage: finish.php <gwname> <ifname> <addr> <root> <cap> <others_json>
 *                   <gwcfg_json> <stdout_file> <stderr_file> <exit_code>
 * Env: SPEEDTEST_LIB, SPEEDTEST_TIMEOUT, SPEEDTEST_PRESET_ERROR.
 * Records are encoded by speedtest_store_write() (invalid UTF-8 substituted).
 */
require_once getenv('SPEEDTEST_LIB') ?: '/usr/local/pkg/speedtest_lib.inc';

if ($argc !== 11) {
	fwrite(STDERR, "usage: finish.php gwname ifname addr root cap others_json gwcfg_json stdout stderr exit_code\n");
	exit(2);
}
[, $gw, $ifname, $addr, $root, $cap, $others_path, $gwcfg_path, $out_path, $err_path, $code] = $argv;
$code = (int)$code;

$read = function (string $path): string {
	$s = @file_get_contents($path);
	return $s === false ? '' : $s;
};
$others = json_decode($read($others_path), true);
$gwcfg = json_decode($read($gwcfg_path), true);
$others = is_array($others) ? $others : [];
$gwcfg = is_array($gwcfg) ? $gwcfg : [];

$ookla = null;
$error = null;
$preset = getenv('SPEEDTEST_PRESET_ERROR');
if ($preset !== false && $preset !== '') {
	$error = $preset;
} elseif ($code === 124) {
	$error = 'timed out after ' . (int)(getenv('SPEEDTEST_TIMEOUT') ?: 90) . 's';
} elseif ($code !== 0) {
	$stderr = trim($read($err_path));
	/* The useful message is at the end; Ookla logs many bind() lines first. */
	$error = $stderr !== '' ? trim(substr($stderr, -200)) : "Ookla CLI exited $code";
} else {
	$ookla = speedtest_parse_ookla($read($out_path));
	if ($ookla === null) {
		$error = 'could not parse Ookla output';
	}
}

$record = speedtest_build_record($gw, $ifname, $addr, $gwcfg, $ookla, $error, date('c'));
$record = speedtest_apply_egress($record, $others);
if (!speedtest_store_write($root, $record, (int)$cap)) {
	fwrite(STDERR, "finish.php: could not write result for $gw under $root\n");
	exit(1);
}

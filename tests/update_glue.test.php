<?php
/*
 * Copyright 2026 Jeff Nelson
 * Licensed under the Apache License, Version 2.0.
 *
 * speedtest_update.inc (self-update glue) against the pfSense stubs.
 */
require __DIR__ . '/assert.inc';
require __DIR__ . '/glue_harness.inc';
require_once __DIR__ . '/../files/usr/local/pkg/speedtest_update.inc';

$tmp = sys_get_temp_dir() . '/speedtest-update-' . getmypid();
$fixture = file_get_contents(__DIR__ . '/fixtures/github_releases.json');
$api = SPEEDTEST_RELEASES_API;
$sha_022 = str_repeat('c', 64);

/* Fresh dirs; HTTP served from $GLOBALS['http'] (url => body|null), calls counted. */
function fresh_update(string $name, string $installed = '0.2.2'): array {
	global $tmp, $fixture, $api, $sha_022;
	$root = "$tmp/$name/db";
	$run_dir = "$tmp/$name/run";
	glue_fixture($root, $run_dir, '/nonexistent');
	$GLOBALS['speedtest_test_installed'] = $installed;
	$GLOBALS['stub_notices'] = [];
	$GLOBALS['http_calls'] = [];
	$GLOBALS['http'] = [
		$api => $fixture,
		'https://github.com/jeffwnelson/pfsense-pkg-speedtest/releases/download/v0.2.2/pfSense-pkg-speedtest-0.2.2.pkg.sha256'
		    => "$sha_022 pfSense-pkg-speedtest-0.2.2.pkg\n",
	];
	$GLOBALS['speedtest_test_http'] = function (string $url): ?string {
		$GLOBALS['http_calls'][] = $url;
		return $GLOBALS['http'][$url] ?? null;
	};
	return [$root, $run_dir];
}

function split_args(string $cmd): array {
	preg_match_all("/'((?:[^']|'\\\\'')*)'/", $cmd, $m);
	return array_map(function ($a) { return str_replace("'\\''", "'", $a); }, $m[1]);
}

/* release cache */
[$root] = fresh_update('cache');
$r = speedtest_releases();
assert_eq(['0.10.0', '0.2.2', '0.2.1'], array_column($r['releases'], 'version'), 'releases fetched and parsed');
assert_null($r['error'], 'no error');
assert_true(is_file("$root/releases.json"), 'cache written');
speedtest_releases();
assert_eq(1, count($GLOBALS['http_calls']), 'second call served from cache');
speedtest_releases(true);
assert_eq(2, count($GLOBALS['http_calls']), 'refresh refetches');
$GLOBALS['http'][$api] = null;
$r = speedtest_releases(true);
assert_eq(3, count($r['releases']), 'GitHub down: stale list kept');
assert_true($r['error'] !== null, 'GitHub down: error reported');
$GLOBALS['http'][$api] = '{"message":"API rate limit exceeded"}';
$r = speedtest_releases(true);
assert_eq(3, count($r['releases']), 'API error object does not wipe the cache');
assert_true($r['error'] !== null, 'API error object reported');
$c = json_decode(file_get_contents("$root/releases.json"), true);
$c['fetched_at'] = time() - SPEEDTEST_RELEASES_TTL_S - 1;
file_put_contents("$root/releases.json", json_encode($c));
$GLOBALS['http'][$api] = $fixture;
$n = count($GLOBALS['http_calls']);
speedtest_releases();
assert_eq($n + 1, count($GLOBALS['http_calls']), 'expired cache refetched');

fresh_update('nocache');
$GLOBALS['http'][$api] = null;
$r = speedtest_releases();
assert_eq([[], 0], [$r['releases'], $r['fetched_at']], 'no cache and GitHub down: empty');

/* update check + notice */
fresh_update('check');
$res = speedtest_check_updates();
assert_eq('0.10.0', $res['available']['version'], 'check: update found');
assert_eq(1, count($GLOBALS['stub_notices']), 'check: notice posted');
assert_eq('/speedtest_updates.php', $GLOBALS['stub_notices'][0]['url'], 'check: notice links to Updates tab');
assert_true(strpos($GLOBALS['stub_notices'][0]['notice'], '0.10.0') !== false, 'check: notice names the version');
speedtest_check_updates();
assert_eq(1, count($GLOBALS['stub_notices']), 'check: one notice per version');
speedtest_check_updates(false);
assert_eq(1, count($GLOBALS['stub_notices']), 'check: notify=false posts nothing');

fresh_update('check_current', '0.10.0');
assert_null(speedtest_check_updates()['available'], 'check: up to date');
assert_eq([], $GLOBALS['stub_notices'], 'check: up to date -> no notice');
fresh_update('check_unknown', '');
assert_null(speedtest_check_updates()['available'], 'check: unknown installed version -> no offer');

/* checksum source */
fresh_update('sha');
$rel = speedtest_releases()['releases'];
assert_eq('7eb558a5f133b662c670c8ef747bb6dba6c4071fc347886c62ae20c86e9bd7ce', speedtest_release_sha256($rel[0]), 'sha: asset digest');
assert_eq($sha_022, speedtest_release_sha256($rel[1]), 'sha: from .sha256 file');
assert_null(speedtest_release_sha256($rel[2]), 'sha: none available');

/* starting an update */
[, $run_dir] = fresh_update('start');
$res = speedtest_update_start('0.10.0');
assert_eq(['ok' => true, 'error' => null], $res, 'start: ok');
assert_eq(1, count($GLOBALS['stub_mwexec_bg']), 'start: spawned once');
$cmd = $GLOBALS['stub_mwexec_bg'][0];
assert_true(strpos($cmd, '/usr/bin/env SPEEDTEST_UPDATE_LOG=') === 0, 'start: logs to update.log');
$args = split_args($cmd);
assert_eq("$run_dir/update.log", $args[0], 'start: log path');
assert_eq([
	realpath(__DIR__ . '/../files/usr/local/libexec/speedtest') . '/update.sh',
	'0.10.0',
	'https://github.com/jeffwnelson/pfsense-pkg-speedtest/releases/download/v0.10.0/pfSense-pkg-speedtest-0.10.0.pkg',
	'7eb558a5f133b662c670c8ef747bb6dba6c4071fc347886c62ae20c86e9bd7ce',
], [realpath(dirname($args[1])) . '/' . basename($args[1]), $args[2], $args[3], $args[4]], 'start: update.sh arguments');
assert_true(speedtest_update_status()['running'], 'start: reported running before update.sh takes its lock');

fresh_update('start_rollback');
assert_true(speedtest_update_start('0.2.2')['ok'], 'start: rollback to an older release');
assert_eq($sha_022, split_args($GLOBALS['stub_mwexec_bg'][0])[4], 'start: rollback checksum from .sha256');

fresh_update('start_bad');
assert_eq('Invalid version.', speedtest_update_start("0.10.0'; reboot; '")['error'], 'start: bad version');
assert_true(strpos((string)speedtest_update_start('0.9.0')['error'], 'No release') === 0, 'start: release without package');
assert_true(strpos((string)speedtest_update_start('0.2.1')['error'], 'no checksum') !== false, 'start: no checksum -> refused');
assert_eq([], $GLOBALS['stub_mwexec_bg'], 'start: nothing spawned for refused versions');

[, $run_dir] = fresh_update('start_busy');
mkdir("$run_dir/update.lock", 0755, true);
file_put_contents("$run_dir/update.lock/pid", (string)getmypid());
assert_eq('An update is already running.', speedtest_update_start('0.10.0')['error'], 'start: busy');
file_put_contents("$run_dir/update.lock/pid", '999999');
file_put_contents("$run_dir/update.rc", "3\n");
$st = speedtest_update_status();
assert_eq([false, 3], [$st['running'], $st['rc']], 'status: dead pid not running; rc read');

/* cron: daily check unless disabled */
fresh_update('cron');
unset($GLOBALS['stub_config']['installedpackages']['speedtest']['config'][0]['no_update_check']);
$GLOBALS['stub_config']['cron']['item'] = [['minute' => '5', 'hour' => '1', 'command' => '/usr/bin/other']];
speedtest_reconcile_cron();
$cmds = array_column($GLOBALS['stub_config']['cron']['item'], 'command');
assert_true(in_array(SPEEDTEST_UPDATE_CHECK_CMD, $cmds, true), 'cron: update check installed');
assert_eq('/usr/bin/other', $cmds[0], 'cron: foreign item kept');
$GLOBALS['stub_config']['installedpackages']['speedtest']['config'][0]['no_update_check'] = 'on';
speedtest_reconcile_cron();
$cmds = array_column($GLOBALS['stub_config']['cron']['item'], 'command');
assert_eq(['/usr/bin/other'], $cmds, 'cron: update check removed when disabled');
unset($GLOBALS['stub_config']['installedpackages']['speedtest']);
assert_true(speedtest_settings()['update_check'], 'never-saved General tab checks for updates');
speedtest_remove_all_cron_jobs();
assert_eq(['/usr/bin/other'], array_column($GLOBALS['stub_config']['cron']['item'], 'command'), 'cron: deinstall removes update check');

/* CLI */
fresh_update('cli');
ob_start();
$rc = speedtest_cli_main(['pfsense-speedtest', 'check']);
$out = ob_get_clean();
assert_eq(0, $rc, 'cli check rc');
assert_true(strpos($out, 'update available: 0.10.0') !== false, 'cli check output');
assert_eq([], $GLOBALS['stub_notices'], 'cli check (interactive) posts no notice');
ob_start();
speedtest_cli_main(['pfsense-speedtest', 'list']);
$out = ob_get_clean();
assert_true((bool)preg_match("/^0\\.2\\.2\t2026-11-18\t\\(installed\\)$/m", $out), 'cli list marks installed');
assert_eq(0, speedtest_cli_main(['pfsense-speedtest', 'check', '--cron']), 'cli cron check rc');
assert_eq(1, count($GLOBALS['stub_notices']), 'cli cron check posts notice');
$GLOBALS['http'][$api] = null;
$GLOBALS['stub_log'] = [];
assert_eq(0, speedtest_cli_main(['pfsense-speedtest', 'check', '--cron']), 'cli cron check never fails cron');
assert_true(strpos((string)($GLOBALS['stub_log'][0] ?? ''), 'update check failed') !== false, 'cli cron failure logged');

exec('rm -rf ' . escapeshellarg($tmp));

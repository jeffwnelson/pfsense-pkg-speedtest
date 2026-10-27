<?php
/*
 * Copyright 2026 Jeff Nelson
 * Licensed under the Apache License, Version 2.0.
 *
 * speedtest.inc (pfSense glue) against the stubs in tests/stubs/pfsense.
 */
require __DIR__ . '/assert.inc';
require __DIR__ . '/glue_harness.inc';

$base = realpath(__DIR__ . '/..');
$fake = "$base/tests/fixtures/fake-ookla.sh";
$tmp = sys_get_temp_dir() . '/speedtest-glue-' . getmypid();

/* Fresh root/run dirs per case. */
function fresh(string $name, string $bin): array {
	global $tmp;
	$root = "$tmp/$name/db";
	$run_dir = "$tmp/$name/run";
	glue_fixture($root, $run_dir, $bin);
	return [$root, $run_dir];
}

/* Split an escapeshellarg()-joined command line back into its arguments. */
function split_cmd(string $cmd): array {
	preg_match_all("/'((?:[^']|'\\\\'')*)'/", $cmd, $m);
	return array_map(function ($a) {
		return str_replace("'\\''", "'", $a);
	}, $m[1]);
}

/* unknown gateway: not configured, and not a gateway at all */
fresh('unknown', $fake);
assert_eq(['ok' => false, 'error' => 'unknown gateway', 'gateway' => null], speedtest_start('OTHER_GW'), 'unconfigured gateway rejected');
assert_eq('unknown gateway', speedtest_start('NOPE; rm -rf /')['error'], 'bogus gateway rejected');
assert_eq([], $GLOBALS['stub_mwexec_bg'], 'unknown gateway: nothing spawned');

/* ookla missing */
[$root, $run_dir] = fresh('missing', "$tmp/no-such-ookla");
assert_eq('ookla missing', speedtest_start('METRO_GW')['error'], 'ookla missing');
assert_true(!file_exists("$run_dir/lock"), 'ookla missing: no lock taken');

/* disabled */
[$root, $run_dir] = fresh('disabled', $fake);
$GLOBALS['stub_config']['installedpackages']['speedtest']['config'][0]['enable'] = '';
assert_eq(['ok' => false, 'error' => 'disabled', 'gateway' => null], speedtest_start('METRO_GW'), 'disabled -> error disabled');
assert_eq(false, speedtest_status()['enabled'], 'status reports disabled');
assert_eq([], $GLOBALS['stub_mwexec_bg'], 'disabled: nothing spawned');
unset($GLOBALS['stub_config']['installedpackages']['speedtest']);
assert_true(speedtest_settings()['enabled'], 'never-saved General tab defaults to enabled');

/* retention default 500 */
fresh('retention', $fake);
assert_eq(500, speedtest_settings()['retention'], 'retention default 500');
assert_eq(80, speedtest_settings()['rows']['METRO_GW']['threshold_pct'], 'threshold default 80');
assert_eq(1234, speedtest_settings()['rows']['METRO_GW']['server_id'], 'server id parsed');

/* success path: runtime files written, command args match run.sh's usage line */
[$root, $run_dir] = fresh('success', $fake);
mkdir("$root/QUANTUM_GW", 0755, true);
file_put_contents("$root/QUANTUM_GW/last_public_ip", "198.51.100.9\n");
assert_eq(['ok' => true], speedtest_start('METRO_GW'), 'start ok');
assert_true(is_dir($root) && is_dir($run_dir), 'root and run dir created');
assert_eq('0755', substr(sprintf('%o', fileperms($run_dir)), -4), 'run dir 0755');
assert_eq('METRO_GW', file_get_contents("$run_dir/lock"), 'lock names the gateway');
assert_eq(['QUANTUM_GW' => ['address' => '198.51.100.1', 'last_public_ip' => '198.51.100.9']],
	json_decode(file_get_contents("$run_dir/others.json"), true), 'others.json: other gateways with last public IP');
assert_eq(['advertised_down' => 2000, 'advertised_up' => null, 'threshold_pct' => 80, 'server_id' => 1234, 'interval' => 0, 'trigger' => 'manual'],
	json_decode(file_get_contents("$run_dir/gwcfg.json"), true), 'gwcfg.json');
assert_true(!file_exists("$root/others.json") && !file_exists("$root/lock"), 'no runtime files in the persistent root');
assert_eq(1, count($GLOBALS['stub_mwexec_bg']), 'one command spawned');
$cmd = $GLOBALS['stub_mwexec_bg'][0];
assert_true(strpos($cmd, '/bin/sh ') === 0, 'spawned via /bin/sh');
$args = split_cmd($cmd);
preg_match('/^# Usage: run\.sh (.*)$/m', file_get_contents("$base/files/usr/local/libexec/speedtest/run.sh"), $m);
$names = array_map(function ($w) {
	return trim($w, '<>');
}, preg_split('/\s+/', trim($m[1])));
assert_eq(['gwname', 'ifname', 'addr', 'server_id|0', 'root', 'run_dir', 'bin', 'cap'], $names, 'run.sh usage line');
assert_eq(count($names) + 1, count($args), 'argument count matches run.sh');
$byname = array_combine($names, array_slice($args, 1));
assert_eq("$base/files/usr/local/libexec/speedtest/run.sh", realpath($args[0]), 'run.sh path');
assert_eq(['gwname' => 'METRO_GW', 'ifname' => 'ixl5', 'addr' => '203.0.113.45', 'server_id|0' => '1234',
	'root' => $root, 'run_dir' => $run_dir, 'bin' => $fake, 'cap' => '500'], $byname, 'positional args line up with run.sh');

/* ...and the spawned command really runs to completion and releases the lock */
putenv('SPEEDTEST_LIB=' . "$base/files/usr/local/pkg/speedtest_lib.inc");
putenv('SPEEDTEST_PHP=' . PHP_BINARY);
putenv('SPEEDTEST_TIMEOUT=20');
$out = [];
$rc = -1;
exec($cmd . ' 2>&1', $out, $rc);
assert_eq(0, $rc, 'spawned runner exits 0: ' . implode(' | ', $out));
$st = speedtest_status();
assert_null($st['running'], 'not running after the runner finished');
assert_eq('pass', $st['results']['METRO_GW']['state'] ?? null, 'result recorded');
assert_eq("203.0.113.45\n", @file_get_contents("$root/METRO_GW/last_public_ip"), 'last_public_ip stored for a trusted run');
assert_eq([], array_values(array_diff(scandir($run_dir), ['.', '..', 'others.json', 'gwcfg.json'])), 'run dir clean');

/* start_when_locked: a live runner holds the lock */
[$root, $run_dir] = fresh('locked', $fake);
mkdir($run_dir, 0755, true);
file_put_contents("$run_dir/lock", 'QUANTUM_GW');
file_put_contents("$run_dir/running.json", json_encode(['gateway' => 'QUANTUM_GW', 'started_at' => date('c', time() - 5), 'pid' => getmypid()]));
assert_eq(['ok' => false, 'error' => 'busy', 'gateway' => 'QUANTUM_GW'], speedtest_start('METRO_GW'), 'start_when_locked -> busy with running gateway');
$st = speedtest_status();
assert_eq('QUANTUM_GW', $st['running']['gateway'] ?? null, 'status reports the running gateway');
assert_true(($st['running']['elapsed_s'] ?? -1) >= 4, 'elapsed_s from started_at');
unlink("$run_dir/running.json");
assert_eq('QUANTUM_GW', speedtest_start('METRO_GW')['gateway'], 'busy before running.json: gateway from the lock');
assert_eq('QUANTUM_GW', speedtest_status()['running']['gateway'] ?? null, 'status running from the lock alone');
assert_eq([], $GLOBALS['stub_mwexec_bg'], 'locked: nothing spawned');

/* stale_lock_recovered: dead pid, and old mtime */
$dead = (int)exec('sh -c \'echo $$\'');
[$root, $run_dir] = fresh('stale_pid', $fake);
mkdir($run_dir, 0755, true);
file_put_contents("$run_dir/lock", 'QUANTUM_GW');
file_put_contents("$run_dir/running.json", json_encode(['gateway' => 'QUANTUM_GW', 'started_at' => date('c'), 'pid' => $dead]));
assert_true(!speedtest_pid_alive($dead), 'exited pid is dead');
assert_true(speedtest_pid_alive(getmypid()), 'own pid is alive');
assert_eq(['ok' => true], speedtest_start('METRO_GW'), 'stale_lock_recovered (dead pid) -> start ok');
assert_eq('METRO_GW', file_get_contents("$run_dir/lock"), 'lock re-taken for the new gateway');

[$root, $run_dir] = fresh('stale_old', $fake);
mkdir($run_dir, 0755, true);
file_put_contents("$run_dir/lock", 'QUANTUM_GW');
file_put_contents("$run_dir/running.json", json_encode(['gateway' => 'QUANTUM_GW', 'pid' => getmypid()]));
touch("$run_dir/lock", time() - 600);
assert_eq(['ok' => true], speedtest_start('METRO_GW'), 'stale_lock_recovered (old mtime + dead pid) -> start ok');

/* speedtest_status clears a stale lock instead of reporting running forever */
[$root, $run_dir] = fresh('status_stale', $fake);
mkdir($run_dir, 0755, true);
file_put_contents("$run_dir/lock", 'METRO_GW');
file_put_contents("$run_dir/running.json", json_encode(['gateway' => 'METRO_GW', 'started_at' => date('c', time() - 600), 'pid' => $dead]));
touch("$run_dir/lock", time() - 600);
$st = speedtest_status();
assert_null($st['running'], 'stale lock: status not running');
assert_true(!file_exists("$run_dir/lock") && !file_exists("$run_dir/running.json"), 'stale lock and running.json cleared');
assert_eq(['METRO_GW' => null, 'QUANTUM_GW' => null], $st['results'], 'results for configured gateways only');

[$root, $run_dir] = fresh('status_deadpid', $fake);
mkdir($run_dir, 0755, true);
file_put_contents("$run_dir/lock", 'METRO_GW');
file_put_contents("$run_dir/running.json", json_encode(['gateway' => 'METRO_GW', 'started_at' => date('c'), 'pid' => $dead]));
assert_null(speedtest_status()['running'], 'fresh lock with dead pid: status not running');

/* RAM disks: both config keys move the store off /var */
fresh('ramdisk', $fake);
unset($GLOBALS['speedtest_test_paths']['root']);
assert_eq('/var/db/speedtest', speedtest_root(), 'default root');
foreach (['use_mfs_tmpvar', 'use_mfs_var'] as $k) {
	$GLOBALS['stub_config']['system'] = [$k => ''];
	assert_eq('/usr/local/share/pfSense-pkg-speedtest/db', speedtest_root(), "RAM disk root ($k)");
}
assert_eq('/var/run/speedtest', (function () {
	unset($GLOBALS['speedtest_test_paths']['run_dir']);
	return speedtest_run_dir();
})(), 'default run dir');

/* cron reconciliation */
const SCHED = '/usr/local/bin/php -f /usr/local/libexec/speedtest/scheduled.php ';
function cron_reset(array $items = []): void {
	$GLOBALS['stub_write_config'] = [];
	$GLOBALS['stub_configure_cron'] = 0;
	$GLOBALS['stub_config']['cron']['item'] = $items;
}
function cron_item(string $gw, string $m = '0', string $h = '*'): array {
	return ['minute' => $m, 'hour' => $h, 'mday' => '*', 'month' => '*', 'wday' => '*', 'who' => 'root', 'command' => SCHED . $gw];
}
function set_intervals(array $iv): void {
	foreach ($GLOBALS['stub_config']['installedpackages']['speedtestgateways']['config'] as $i => &$r) {
		$r['interval'] = (string)($iv[$r['gateway']] ?? '0');
	}
}

fresh('cron_installs', $fake);
set_intervals(['METRO_GW' => 60, 'QUANTUM_GW' => 1440]);
cron_reset([['minute' => '5', 'hour' => '1', 'command' => '/usr/bin/other']]);
speedtest_reconcile_cron();
$cmds = array_column($GLOBALS['stub_config']['cron']['item'], 'command');
assert_eq(1, count($GLOBALS['stub_write_config']), 'reconcile_installs: one write_config');
assert_eq(1, $GLOBALS['stub_configure_cron'], 'reconcile_installs: configure_cron once');
assert_eq(['/usr/bin/other', SCHED . 'METRO_GW', SCHED . 'QUANTUM_GW'], $cmds, 'reconcile_installs: foreign kept, two installed');
assert_eq(['7', '3'], [$GLOBALS['stub_config']['cron']['item'][2]['minute'], $GLOBALS['stub_config']['cron']['item'][2]['hour']], 'reconcile_installs: spec');

fresh('cron_stale', $fake);
set_intervals(['METRO_GW' => 60]);
cron_reset([cron_item('METRO_GW'), cron_item('GONE_GW', '14'), cron_item('METRO_GW_BACKUP', '21')]);
speedtest_reconcile_cron();
$cmds = array_column($GLOBALS['stub_config']['cron']['item'], 'command');
assert_true(in_array(SCHED . 'METRO_GW', $cmds, true) && count($cmds) === 1, 'reconcile_removes_stale: only METRO_GW remains');
assert_eq(1, count($GLOBALS['stub_write_config']), 'reconcile_removes_stale: one write');

fresh('cron_disabled', $fake);
set_intervals(['METRO_GW' => 60, 'QUANTUM_GW' => 60]);
$GLOBALS['stub_config']['installedpackages']['speedtest']['config'][0]['enable'] = '';
cron_reset([cron_item('METRO_GW'), cron_item('QUANTUM_GW', '7')]);
speedtest_reconcile_cron();
assert_eq([], $GLOBALS['stub_config']['cron']['item'], 'reconcile_disabled: all removed');
assert_eq(1, count($GLOBALS['stub_write_config']), 'reconcile_disabled: write happened');

fresh('cron_noop', $fake);
set_intervals(['METRO_GW' => 60, 'QUANTUM_GW' => 1440]);
cron_reset([cron_item('METRO_GW', '0', '*'), cron_item('QUANTUM_GW', '7', '3')]);
speedtest_reconcile_cron();
assert_eq([], $GLOBALS['stub_write_config'], 'reconcile_noop_no_write: no write_config');
assert_eq(0, $GLOBALS['stub_configure_cron'], 'reconcile_noop_no_write: no configure_cron');

fresh('cron_deinstall', $fake);
cron_reset([cron_item('METRO_GW'), ['command' => '/usr/bin/other']]);
speedtest_remove_all_cron_jobs();
assert_eq(1, count($GLOBALS['stub_config']['cron']['item']), 'deinstall: only scheduled items removed');
assert_eq(1, count($GLOBALS['stub_write_config']), 'deinstall: one write');
assert_eq(1, $GLOBALS['stub_configure_cron'], 'deinstall: configure_cron once');

fresh('resync_reconciles', $fake);
set_intervals(['METRO_GW' => 60]);
cron_reset([]);
speedtest_resync();
assert_eq([SCHED . 'METRO_GW'], array_column($GLOBALS['stub_config']['cron']['item'], 'command'), 'resync: installs job for scheduled row');
assert_eq(60, speedtest_row_interval('60'), 'interval parse');
assert_eq(0, speedtest_row_interval('77'), 'interval invalid -> 0');

fresh('cron_prefix', $fake);
$GLOBALS['stub_config']['installedpackages']['speedtestgateways']['config'] = [
	['gateway' => 'X', 'interval' => '60'], ['gateway' => 'X_2', 'interval' => '60']];
$foreign = ['minute' => '1', 'hour' => '2', 'command' => '/usr/bin/other'];
cron_reset([cron_item('X', '33'), $foreign]);
speedtest_reconcile_cron();
assert_eq([SCHED . 'X', '/usr/bin/other', SCHED . 'X_2'], array_column($GLOBALS['stub_config']['cron']['item'], 'command'), 'reconcile_prefix_names: one X, one X_2, order stable');
assert_eq($foreign, $GLOBALS['stub_config']['cron']['item'][1], 'reconcile_prefix_names: foreign untouched');
assert_eq(['0', '7'], [$GLOBALS['stub_config']['cron']['item'][0]['minute'], $GLOBALS['stub_config']['cron']['item'][2]['minute']], 'reconcile_prefix_names: minutes');

fresh('cron_inplace', $fake);
set_intervals(['METRO_GW' => 60]);
cron_reset([cron_item('METRO_GW', '45')]);
speedtest_reconcile_cron();
assert_eq(1, count($GLOBALS['stub_config']['cron']['item']), 'reconcile_minute_change_updates_in_place: count unchanged');
assert_eq('0', $GLOBALS['stub_config']['cron']['item'][0]['minute'], 'reconcile_minute_change_updates_in_place: minute updated');
assert_eq(1, count($GLOBALS['stub_write_config']), 'reconcile_minute_change_updates_in_place: one write');

/* trigger field */
[$root, $run_dir] = fresh('trigger', $fake);
assert_eq(['ok' => true], speedtest_start('METRO_GW', 'scheduled'), 'start scheduled ok');
assert_eq('scheduled', json_decode(file_get_contents("$run_dir/gwcfg.json"), true)['trigger'], 'trigger scheduled recorded');
[$root, $run_dir] = fresh('trigger_bogus', $fake);
assert_eq(['ok' => true], speedtest_start('METRO_GW', 'bogus'), 'start bogus trigger ok');
assert_eq('manual', json_decode(file_get_contents("$run_dir/gwcfg.json"), true)['trigger'], 'bogus trigger -> manual');

/* scheduled.php entry point */
$GLOBALS['stub_log'] = [];
fresh('scheduled_success', $fake);
assert_eq(0, speedtest_scheduled_main(['scheduled.php', 'METRO_GW']), 'scheduled_success: rc 0');
assert_eq(1, count($GLOBALS['stub_mwexec_bg']), 'scheduled_success: runner spawned');
assert_eq([], $GLOBALS['stub_log'], 'scheduled_success: nothing logged');

$GLOBALS['stub_log'] = [];
[$root, $run_dir] = fresh('scheduled_busy', $fake);
mkdir($run_dir, 0755, true);
file_put_contents("$run_dir/lock", 'QUANTUM_GW');
file_put_contents("$run_dir/running.json", json_encode(['gateway' => 'QUANTUM_GW', 'started_at' => date('c'), 'pid' => getmypid()]));
assert_eq(0, speedtest_scheduled_main(['scheduled.php', 'METRO_GW']), 'scheduled_busy: rc 0');
assert_eq(['speedtest: scheduled run skipped: busy (METRO_GW)'], $GLOBALS['stub_log'], 'scheduled_busy: logged');
assert_eq([], $GLOBALS['stub_mwexec_bg'], 'scheduled_busy: nothing spawned');

$GLOBALS['stub_log'] = [];
fresh('scheduled_disabled', $fake);
$GLOBALS['stub_config']['installedpackages']['speedtest']['config'][0]['enable'] = '';
assert_eq(0, speedtest_scheduled_main(['scheduled.php', 'METRO_GW']), 'scheduled_disabled: rc 0');
assert_eq(['speedtest: scheduled run skipped: disabled (METRO_GW)'], $GLOBALS['stub_log'], 'scheduled_disabled: logged');
assert_eq([], $GLOBALS['stub_mwexec_bg'], 'scheduled_disabled: nothing spawned');

$GLOBALS['stub_log'] = [];
fresh('scheduled_unknown', $fake);
assert_eq(0, speedtest_scheduled_main(['scheduled.php', 'NOPE_GW']), 'scheduled_unknown: rc 0');
assert_eq(['speedtest: scheduled run skipped: unknown gateway (NOPE_GW)'], $GLOBALS['stub_log'], 'scheduled_unknown: logged');
$GLOBALS['stub_log'] = [];
assert_eq(0, speedtest_scheduled_main(['scheduled.php']), 'scheduled_nogw: rc 0');
assert_eq(['speedtest: scheduled run skipped: no gateway given'], $GLOBALS['stub_log'], 'scheduled_nogw: logged');

$GLOBALS['stub_log'] = [];
fresh('scheduled_exception_swallowed', $fake);
$GLOBALS['stub_gateways_throw'] = true;
ob_start();
$rc = speedtest_scheduled_main(['scheduled.php', 'METRO_GW']);
$out = ob_get_clean();
unset($GLOBALS['stub_gateways_throw']);
assert_eq(0, $rc, 'scheduled_exception_swallowed: rc 0');
assert_eq('', $out, 'scheduled_exception_swallowed: no stdout');
assert_eq(['speedtest: scheduled run skipped: gateway lookup exploded (METRO_GW)'], $GLOBALS['stub_log'], 'scheduled_exception_swallowed: logged');
assert_eq([], $GLOBALS['stub_mwexec_bg'], 'scheduled_exception_swallowed: nothing spawned');

exec('rm -rf ' . escapeshellarg($tmp));

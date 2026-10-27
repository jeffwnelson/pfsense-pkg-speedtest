<?php
/*
 * Copyright 2026 Jeff Nelson
 * Licensed under the Apache License, Version 2.0.
 *
 * pfSense glue for pfSense-pkg-speedtest: config access, gateway discovery,
 * and starting the detached runner. Pure logic lives in speedtest_lib.inc.
 */
require_once('config.inc');
require_once('gwlb.inc');
require_once('interfaces.inc');
require_once('services.inc');
require_once('util.inc');
require_once(__DIR__ . '/speedtest_lib.inc');

const SPEEDTEST_LIBEXEC = '/usr/local/libexec/speedtest';
const SPEEDTEST_BIN = SPEEDTEST_LIBEXEC . '/ookla-speedtest';
const SPEEDTEST_RUN_DIR = '/var/run/speedtest';
const SPEEDTEST_LOCK_STALE_S = 180;
const SPEEDTEST_DEFAULT_THRESHOLD = 80;
const SPEEDTEST_DEFAULT_RETENTION = 500;

/*
 * Paths below can be redirected by tests through $GLOBALS['speedtest_test_paths']
 * (keys root, run_dir, libexec, bin); production never sets it.
 */
function speedtest_path(string $key, string $default): string {
	return (string)($GLOBALS['speedtest_test_paths'][$key] ?? $default);
}

/* Data directory; RAM-disk /var is not persistent, so keep data on disk. */
function speedtest_root(): string {
	/* pfSense stores "Use RAM Disks" as system/use_mfs_tmpvar; use_mfs_var is the older spelling. */
	if (config_path_enabled('system', 'use_mfs_tmpvar') || config_path_enabled('system', 'use_mfs_var')) {
		return speedtest_path('root', '/usr/local/share/pfSense-pkg-speedtest/db');
	}
	return speedtest_path('root', '/var/db/speedtest');
}

/* Runtime state (lock, running.json, runner inputs and temp output); cleared by reboots. */
function speedtest_run_dir(): string {
	return speedtest_path('run_dir', SPEEDTEST_RUN_DIR);
}

function speedtest_libexec(): string {
	return speedtest_path('libexec', SPEEDTEST_LIBEXEC);
}

function speedtest_bin(): string {
	return speedtest_path('bin', SPEEDTEST_BIN);
}

/* pfSense returns "" for unset fields; map that to null. */
function speedtest_int_or_null($v): ?int {
	return ($v === null || $v === '' || !is_numeric($v)) ? null : (int)$v;
}

/* Interval in minutes; anything not a known option (including blank) is 0 (off). */
function speedtest_row_interval($v): int {
	$n = speedtest_int_or_null($v);
	return ($n !== null && in_array($n, SPEEDTEST_INTERVALS, true)) ? $n : 0;
}

/*
 * ['rows' => [gw => ['advertised_down'=>?int,'advertised_up'=>?int,
 * 'threshold_pct'=>int,'server_id'=>?int,'interval'=>int]], 'retention' => int,
 * 'ookla_version' => ?string, 'enabled' => bool].
 */
function speedtest_settings(): array {
	if (isset($GLOBALS['speedtest_test_settings'])) {
		return $GLOBALS['speedtest_test_settings'];
	}
	$general = config_get_path('installedpackages/speedtest/config/0', []);
	$general = is_array($general) ? $general : [];
	$rows = [];
	foreach (config_get_path('installedpackages/speedtestgateways/config', []) as $row) {
		$gw = (string)($row['gateway'] ?? '');
		if ($gw === '') {
			continue;
		}
		$thr = speedtest_int_or_null($row['threshold_pct'] ?? null);
		$rows[$gw] = [
			'advertised_down' => speedtest_int_or_null($row['advertised_down'] ?? null),
			'advertised_up' => speedtest_int_or_null($row['advertised_up'] ?? null),
			'threshold_pct' => $thr ?? SPEEDTEST_DEFAULT_THRESHOLD,
			'server_id' => speedtest_int_or_null($row['server_id'] ?? null),
			'interval' => speedtest_row_interval($row['interval'] ?? null),
		];
	}
	$ver = (string)($general['ookla_version'] ?? '');
	return [
		'rows' => $rows,
		'retention' => speedtest_int_or_null($general['retention'] ?? null) ?? SPEEDTEST_DEFAULT_RETENTION,
		'ookla_version' => $ver === '' ? null : $ver,
		/* Enable defaults to on: until the General tab is first saved there is no config entry. */
		'enabled' => $general === [] || ($general['enable'] ?? '') === 'on',
	];
}

/* [gwname => ['descr','ifname','friendly','address' => ?string]] for configured gateways that exist. */
function speedtest_configured_gateways(): array {
	$rows = speedtest_settings()['rows'];
	$out = [];
	foreach (return_gateways_array() as $name => $gw) {
		if (!isset($rows[$name])) {
			continue;
		}
		$friendly = (string)($gw['friendlyiface'] ?? '');
		$out[$name] = [
			'descr' => (string)($gw['descr'] ?? ''),
			'ifname' => (string)get_real_interface($friendly),
			'friendly' => $friendly,
			'address' => get_interface_ip($friendly) ?: null,
		];
	}
	return $out;
}

/* Contents of running.json, or null when absent/unreadable. */
function speedtest_running(string $run_dir): ?array {
	$raw = @file_get_contents("$run_dir/running.json");
	$data = $raw === false ? null : json_decode($raw, true);
	return is_array($data) ? $data : null;
}

/* A lock is stale when old, or when the recorded runner pid is gone. */
function speedtest_lock_is_stale(string $run_dir): bool {
	$mtime = @filemtime("$run_dir/lock");
	if ($mtime === false) {
		return false;
	}
	if (time() - $mtime > SPEEDTEST_LOCK_STALE_S) {
		return true;
	}
	$run = speedtest_running($run_dir);
	$pid = (int)($run['pid'] ?? 0);
	return $pid > 0 && !speedtest_pid_alive($pid);
}

/* Signal-0 probe. EPERM still means the process exists; kill(1) if ext/posix is absent. */
function speedtest_pid_alive(int $pid): bool {
	if (function_exists('posix_kill')) {
		return posix_kill($pid, 0) || posix_get_last_error() === 1;
	}
	$out = [];
	$rc = 1;
	exec('/bin/kill -0 ' . $pid . ' 2>/dev/null', $out, $rc);
	return $rc === 0;
}

function speedtest_clear_lock(string $run_dir): void {
	@unlink("$run_dir/lock");
	@unlink("$run_dir/running.json");
}

/* Try to take <run_dir>/lock; clears one stale lock and retries once. */
function speedtest_acquire_lock(string $run_dir, string $gwname = ''): bool {
	for ($attempt = 0; $attempt < 2; $attempt++) {
		$fp = @fopen("$run_dir/lock", 'x');
		if ($fp !== false) {
			/* Record the gateway so status can report running before run.sh writes running.json. */
			fwrite($fp, $gwname);
			fclose($fp);
			return true;
		}
		if (!speedtest_lock_is_stale($run_dir)) {
			return false;
		}
		speedtest_clear_lock($run_dir);
	}
	return false;
}

function speedtest_mkdir(string $dir): bool {
	return is_dir($dir) || @mkdir($dir, 0755, true) || is_dir($dir);
}

/*
 * Start a detached test for one gateway. Returns ['ok' => true] or
 * ['ok' => false, 'error' => 'disabled'|'unknown gateway'|'busy'|'ookla missing'|'start failed',
 * 'gateway' => ?currently running gateway].
 */
function speedtest_start(string $gwname, string $trigger = 'manual'): array {
	$trigger = $trigger === 'scheduled' ? 'scheduled' : 'manual';
	if (!(speedtest_settings()['enabled'] ?? true)) {
		return ['ok' => false, 'error' => 'disabled', 'gateway' => null];
	}
	$gateways = speedtest_configured_gateways();
	if (!isset($gateways[$gwname]) || !speedtest_store_safe_name($gwname)) {
		return ['ok' => false, 'error' => 'unknown gateway', 'gateway' => null];
	}
	if (!speedtest_ookla_status(speedtest_bin())['present']) {
		return ['ok' => false, 'error' => 'ookla missing', 'gateway' => null];
	}

	$root = speedtest_root();
	$run_dir = speedtest_run_dir();
	if (!speedtest_mkdir($root) || !speedtest_mkdir($run_dir)) {
		return ['ok' => false, 'error' => 'start failed', 'gateway' => null];
	}
	if (!speedtest_acquire_lock($run_dir, $gwname)) {
		$run = speedtest_running($run_dir);
		$gw = $run['gateway'] ?? (trim((string)@file_get_contents("$run_dir/lock")) ?: null);
		return ['ok' => false, 'error' => 'busy', 'gateway' => $gw];
	}

	/* From here any failure must release the lock, or starts report busy for 180 s. */
	try {
		if (speedtest_spawn($root, $run_dir, $gwname, $gateways, $trigger)) {
			return ['ok' => true];
		}
	} catch (Throwable $e) {
		log_error('speedtest: start failed: ' . $e->getMessage());
	}
	speedtest_clear_lock($run_dir);
	return ['ok' => false, 'error' => 'start failed', 'gateway' => null];
}

/*
 * Write the runner inputs to <run_dir> and spawn it detached. False if inputs
 * could not be written. Argument order must match run.sh's usage line.
 */
function speedtest_spawn(string $root, string $run_dir, string $gwname, array $gateways, string $trigger = 'manual'): bool {
	$settings = speedtest_settings();
	$cfg = $settings['rows'][$gwname];
	$cfg['trigger'] = $trigger === 'scheduled' ? 'scheduled' : 'manual';
	$last_ips = speedtest_store_last_public_ips($root, array_keys($gateways));
	$others = [];
	foreach ($gateways as $name => $gw) {
		if ($name !== $gwname) {
			$others[$name] = ['address' => $gw['address'], 'last_public_ip' => $last_ips[$name] ?? null];
		}
	}
	if (file_put_contents("$run_dir/others.json", json_encode($others)) === false
	    || file_put_contents("$run_dir/gwcfg.json", json_encode($cfg)) === false) {
		return false;
	}

	$gw = $gateways[$gwname];
	$args = [
		speedtest_libexec() . '/run.sh', $gwname, $gw['ifname'], (string)($gw['address'] ?? ''),
		(string)($cfg['server_id'] ?? 0), $root, $run_dir, speedtest_bin(), (string)$settings['retention'],
	];
	mwexec_bg('/bin/sh ' . implode(' ', array_map('escapeshellarg', $args)));
	return true;
}

/*
 * ['running' => ?['gateway','started_at','elapsed_s'], 'results' => [gw => latest record|null],
 * 'enabled' => bool]. A stale lock (runner killed, or left over from before a
 * reboot) is cleared here so the widget does not report a run forever.
 */
function speedtest_status(): array {
	$root = speedtest_root();
	$run_dir = speedtest_run_dir();
	if (speedtest_lock_is_stale($run_dir)) {
		speedtest_clear_lock($run_dir);
	}
	$running = null;
	$lock_mtime = @filemtime("$run_dir/lock");
	if ($lock_mtime !== false) {
		$run = speedtest_running($run_dir) ?? [];
		$gw = $run['gateway'] ?? (trim((string)@file_get_contents("$run_dir/lock")) ?: null);
		$started_at = $run['started_at'] ?? date('c', $lock_mtime);
		$started = strtotime((string)$started_at);
		$running = [
			'gateway' => $gw,
			'started_at' => $started_at,
			'elapsed_s' => $started === false ? null : max(0, time() - $started),
		];
	}
	return [
		'running' => $running,
		'results' => speedtest_store_all_latest($root, array_keys(speedtest_configured_gateways())),
		'enabled' => (bool)(speedtest_settings()['enabled'] ?? true),
	];
}

const SPEEDTEST_MANIFEST = '/usr/local/share/pfSense-pkg-speedtest/ookla-manifest.json';

/* Decoded manifest, or ['builds' => []] when missing or invalid. */
function speedtest_manifest(): array {
	$data = json_decode((string)@file_get_contents(SPEEDTEST_MANIFEST), true);
	return is_array($data) ? $data : ['builds' => []];
}

/* [value => label] for the Ookla version select; blank value means newest compatible. */
function speedtest_manifest_versions(): array {
	$out = ['' => 'Newest compatible'];
	foreach (speedtest_manifest_version_list(speedtest_manifest()) as $v) {
		$out[$v] = $v;
	}
	return $out;
}

function speedtest_ookla_status_text(): string {
	$st = speedtest_ookla_status(speedtest_bin());
	if (!$st['present']) {
		return 'Not installed (use Reinstall CLI)';
	}
	return 'Installed: ' . ($st['version'] ?? 'version unknown');
}

/* Run the Ookla installer; returns its combined output. */
function speedtest_ookla_install(?string $version): string {
	$major = (int)explode('.', php_uname('r'))[0];
	$args = [speedtest_libexec() . '/ookla-install.sh', SPEEDTEST_MANIFEST, (string)$major, speedtest_bin()];
	if ($version !== null && $version !== '') {
		$args[] = $version;
	}
	$out = [];
	$rc = 0;
	exec('/bin/sh ' . implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1', $out, $rc);
	if ($rc !== 0) {
		$out[] = "Installer exited with status {$rc}";
	}
	return implode("\n", $out);
}

/* Handler for the Reinstall CLI button. Returns ['ok' => bool, 'message' => string]. */
function speedtest_reinstall_from_post(array $post): array {
	$version = trim((string)($post['ookla_version'] ?? ''));
	if (!array_key_exists($version, speedtest_manifest_versions())) {
		return ['ok' => false, 'message' => 'Unknown Ookla version'];
	}
	$output = speedtest_ookla_install($version === '' ? null : $version);
	$ok = speedtest_ookla_status(speedtest_bin())['present'] && strpos($output, 'Installer exited') === false;
	return ['ok' => $ok, 'message' => ($ok ? 'Ookla CLI installed. ' : 'Ookla CLI install failed. ') . $output];
}

/* Fill the dynamic status text into the parsed package form before it renders. */
function speedtest_fill_status(array &$pkg): void {
	foreach ($pkg['fields']['field'] as &$field) {
		if (($field['type'] ?? '') === 'info') {
			$field['description'] = htmlspecialchars(speedtest_ookla_status_text());
		}
	}
	unset($field);
}

/* [gwname => "descr (gwname)"] for IPv4 gateways; tunnels hidden unless show_all. */
function speedtest_gateway_options(?bool $show_all = null): array {
	if ($show_all === null) {
		$show_all = config_get_path('installedpackages/speedtest/config/0/show_all_gateways') === 'on';
	}
	$out = [];
	foreach (return_gateways_array() as $name => $gw) {
		if (($gw['ipprotocol'] ?? 'inet') !== 'inet') {
			continue;
		}
		$friendly = (string)($gw['friendlyiface'] ?? $gw['interface'] ?? '');
		if (!$show_all && speedtest_is_tunnel_ifname((string)get_real_interface($friendly))) {
			continue;
		}
		$descr = trim((string)($gw['descr'] ?? ''));
		$out[$name] = $descr === '' ? $name : "{$descr} ({$name})";
	}
	return $out;
}

function speedtest_validate_general(array $post, &$input_errors): void {
	$errors = speedtest_validate_settings([], [], (string)($post['retention'] ?? ''))['errors'];
	$version = trim((string)($post['ookla_version'] ?? ''));
	if (!array_key_exists($version, speedtest_manifest_versions())) {
		$errors[] = 'Unknown Ookla version';
	}
	foreach ($errors as $e) {
		$input_errors[] = $e;
	}
}

/* $id is the row being edited (null when adding), used for the duplicate check. */
function speedtest_validate_gateway_row(array $post, &$input_errors, $id = null): void {
	$v = speedtest_validate_row($post, array_keys(speedtest_gateway_options(true)));
	foreach ($v['errors'] as $e) {
		$input_errors[] = $e;
	}
	$gw = $v['clean']['gateway'];
	foreach (config_get_path('installedpackages/speedtestgateways/config', []) as $i => $row) {
		if (($row['gateway'] ?? null) === $gw && (string)$i !== (string)$id) {
			$input_errors[] = "Gateway {$gw} is listed more than once";
			break;
		}
	}
}

const SPEEDTEST_SCHEDULED_SCRIPT = '/usr/local/libexec/speedtest/scheduled.php';

/*
 * Make cron/item match the settings. install_cron_job() matches by substring
 * (gateway X would clobber X_2), so cron/item is edited directly with exact
 * command comparison. Foreign items keep their place; speedtest items are kept
 * in place when desired (values refreshed), dropped when stale, and missing
 * ones appended. One write_config() and configure_cron() only if it changed.
 */
function speedtest_apply_cron(array $desired, string $desc): void {
	$before = config_get_path('cron/item', []);
	$before = is_array($before) ? $before : [];
	$after = [];
	$seen = [];
	foreach ($before as $item) {
		$cmd = (string)($item['command'] ?? '');
		if (strpos($cmd, SPEEDTEST_SCHEDULED_SCRIPT) === false) {
			$after[] = $item;
		} elseif (isset($desired[$cmd]) && !isset($seen[$cmd])) {
			$seen[$cmd] = true;
			$after[] = speedtest_cron_item($cmd, $desired[$cmd]);
		}
	}
	foreach ($desired as $cmd => $spec) {
		if (!isset($seen[$cmd])) {
			$after[] = speedtest_cron_item((string)$cmd, $spec);
		}
	}
	if ($after != $before) {
		config_set_path('cron/item', $after);
		write_config($desc);
		configure_cron();
	}
}

function speedtest_cron_item(string $cmd, array $spec): array {
	return ['minute' => $spec['minute'], 'hour' => $spec['hour'], 'mday' => '*', 'month' => '*',
	    'wday' => '*', 'who' => 'root', 'command' => $cmd];
}

function speedtest_reconcile_cron(): void {
	$settings = speedtest_settings();
	$desired = ($settings['enabled'] ?? true) ? speedtest_desired_cron_jobs($settings['rows']) : [];
	speedtest_apply_cron($desired, 'speedtest: cron reconcile');
}

/* Deinstall: drop every scheduled.php cron item. */
function speedtest_remove_all_cron_jobs(): void {
	speedtest_apply_cron([], 'speedtest: cron removed');
}

/* Ensure the data dir exists, mirror remove_on_deinstall into its marker file, and sync cron. */
function speedtest_resync(): void {
	speedtest_reconcile_cron();
	$root = speedtest_root();
	if (!is_dir($root)) {
		@mkdir($root, 0755, true);
	}
	$marker = "$root/.remove-on-deinstall";
	if (config_get_path('installedpackages/speedtest/config/0/remove_on_deinstall') === 'on') {
		@touch($marker);
	} else {
		@unlink($marker);
	}
}

/*
 * Entry point for scheduled.php (cron). Returns the process exit code: always
 * 0, so cron never mails; skips are logged. Prints nothing.
 */
function speedtest_scheduled_main(array $argv): int {
	$gw = (string)($argv[1] ?? '');
	if ($gw === '') {
		log_error('speedtest: scheduled run skipped: no gateway given');
		return 0;
	}
	try {
		$res = speedtest_start($gw, 'scheduled');
	} catch (Throwable $e) {
		log_error('speedtest: scheduled run skipped: ' . $e->getMessage() . " ($gw)");
		return 0;
	}
	if (!$res['ok']) {
		log_error("speedtest: scheduled run skipped: {$res['error']} ($gw)");
	}
	return 0;
}

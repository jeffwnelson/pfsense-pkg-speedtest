<?php
/*
 * Copyright 2026 Jeff Nelson
 * Licensed under the Apache License, Version 2.0.
 *
 * Pure-PHP helpers for pfSense-pkg-speedtest. No pfSense dependencies.
 */

/* Convert Ookla bandwidth (bytes per second) to megabits per second. */
function speedtest_bandwidth_mbps(int $bytes_per_sec): float {
	return $bytes_per_sec * 8 / 1000000;
}

/*
 * Decode Ookla CLI JSON output. Returns the decoded result only when it is a
 * complete "result" document; otherwise null (log lines, partial results,
 * garbage).
 */
function speedtest_parse_ookla(string $json): ?array {
	$d = json_decode($json, true);
	if (!is_array($d) || ($d['type'] ?? null) !== 'result') {
		return null;
	}
	$required = [
		['download', 'bandwidth'],
		['upload', 'bandwidth'],
		['ping', 'latency'],
		['interface', 'externalIp'],
		['server', 'id'],
	];
	foreach ($required as $path) {
		$node = $d;
		foreach ($path as $key) {
			if (!is_array($node) || !isset($node[$key])) {
				return null;
			}
			$node = $node[$key];
		}
	}
	return $d;
}

/* Percent of the advertised speed achieved, rounded; null if not advertised. */
function speedtest_pct(float $mbps, ?int $adv): ?int {
	if ($adv === null || $adv <= 0) {
		return null;
	}
	return (int)round($mbps / $adv * 100);
}

/*
 * pass: every advertised direction >= threshold; fail: any < 50%; warn: in
 * between; measured: nothing advertised.
 */
function speedtest_compute_state(float $down_mbps, float $up_mbps, ?int $adv_down, ?int $adv_up, int $threshold_pct): string {
	$pcts = array_filter([
		speedtest_pct($down_mbps, $adv_down),
		speedtest_pct($up_mbps, $adv_up),
	], function ($p) { return $p !== null; });
	if (!$pcts) {
		return 'measured';
	}
	$lowest = min($pcts);
	if ($lowest < 50) {
		return 'fail';
	}
	return $lowest >= $threshold_pct ? 'pass' : 'warn';
}

/*
 * Build the stored per-gateway record. $ookla null means the run failed and
 * $error carries the message. egress_mismatch is decided elsewhere.
 */
function speedtest_build_record(string $gwname, string $ifname, string $addr, array $gwcfg, ?array $ookla, ?string $error, string $ran_at): array {
	$adv_down = $gwcfg['advertised_down'] ?? null;
	$adv_up = $gwcfg['advertised_up'] ?? null;
	$threshold = $gwcfg['threshold_pct'] ?? 80;

	$rec = [
		'gateway' => $gwname,
		'interface' => $ifname,
		'address' => $addr,
		'ran_at' => $ran_at,
		'state' => 'error',
		'down_mbps' => null,
		'up_mbps' => null,
		'pct_down' => null,
		'pct_up' => null,
		'ping_ms' => null,
		'jitter_ms' => null,
		'packet_loss' => null,
		'external_ip' => null,
		'isp' => null,
		'server' => null,
		'result_url' => null,
		'advertised_down' => $adv_down,
		'advertised_up' => $adv_up,
		'threshold_pct' => $threshold,
		'trigger' => (($gwcfg['trigger'] ?? '') === 'scheduled') ? 'scheduled' : 'manual',
		'error' => $error,
	];
	if ($ookla === null) {
		return $rec;
	}

	$down = speedtest_bandwidth_mbps((int)$ookla['download']['bandwidth']);
	$up = speedtest_bandwidth_mbps((int)$ookla['upload']['bandwidth']);
	$rec['state'] = speedtest_compute_state($down, $up, $adv_down, $adv_up, $threshold);
	$rec['down_mbps'] = $down;
	$rec['up_mbps'] = $up;
	$rec['pct_down'] = speedtest_pct($down, $adv_down);
	$rec['pct_up'] = speedtest_pct($up, $adv_up);
	$rec['ping_ms'] = $ookla['ping']['latency'];
	$rec['jitter_ms'] = $ookla['ping']['jitter'] ?? null;
	$rec['packet_loss'] = $ookla['packetLoss'] ?? null;
	$rec['external_ip'] = $ookla['interface']['externalIp'];
	$rec['isp'] = $ookla['isp'] ?? null;
	$rec['server'] = [
		'id' => $ookla['server']['id'],
		'name' => $ookla['server']['name'] ?? null,
		'location' => $ookla['server']['location'] ?? null,
	];
	$rec['result_url'] = $ookla['result']['url'] ?? null;
	$rec['error'] = null;
	return $rec;
}

/*
 * False (mismatch) when the measured external IP equals the address or last
 * known public IP of any other gateway, i.e. the test left via the wrong WAN.
 * $others: [gwname => ['address' => ?string, 'last_public_ip' => ?string]].
 */
function speedtest_check_egress(string $external_ip, string $gwname, array $others): bool {
	return speedtest_egress_match($external_ip, $others) === null;
}

/* Name of the other gateway whose address or last public IP equals $external_ip, or null. */
function speedtest_egress_match(string $external_ip, array $others): ?string {
	foreach ($others as $name => $other) {
		foreach (['address', 'last_public_ip'] as $key) {
			$val = $other[$key] ?? null;
			if ($val !== null && $val !== '' && $val === $external_ip) {
				return (string)$name;
			}
		}
	}
	return null;
}

/* Mark a record egress_mismatch unless it already errored or has no IP. */
function speedtest_apply_egress(array $record, array $others): array {
	$ip = $record['external_ip'] ?? null;
	if (($record['state'] ?? null) === 'error' || $ip === null || $ip === '') {
		return $record;
	}
	$other = speedtest_egress_match((string)$ip, $others);
	if ($other !== null) {
		$record['state'] = 'egress_mismatch';
		$record['error'] = "egress via {$other} ({$ip})";
	}
	return $record;
}

/*
 * Parse a whole-number form field. Returns [ok, value]; blank gives
 * [true, null]. Out-of-range or non-numeric input gives [false, null].
 */
function speedtest_parse_int_field($raw, int $min, int $max): array {
	$s = trim((string)$raw);
	if ($s === '') {
		return [true, null];
	}
	if (!preg_match('/^[0-9]{1,9}$/', $s)) {
		return [false, null];
	}
	$n = (int)$s;
	return ($n >= $min && $n <= $max) ? [true, $n] : [false, null];
}

const SPEEDTEST_INTERVALS = [0, 60, 180, 360, 720, 1440];

/* [minutes => label] for the schedule select. */
function speedtest_interval_options(): array {
	return [0 => 'Off', 60 => 'Every hour', 180 => 'Every 3 hours', 360 => 'Every 6 hours',
	    720 => 'Every 12 hours', 1440 => 'Daily (03:00)'];
}

/* Cron minute/hour for an interval; $index staggers jobs by 7 minutes. Null when off/invalid. */
function speedtest_cron_spec(int $interval, int $index): ?array {
	$hours = [60 => '*', 180 => '*/3', 360 => '*/6', 720 => '*/12', 1440 => '3'];
	if (!isset($hours[$interval])) {
		return null;
	}
	return ['minute' => (string)(($index * 7) % 60), 'hour' => $hours[$interval]];
}

function speedtest_cron_command(string $gwname): string {
	return '/usr/local/bin/php -f /usr/local/libexec/speedtest/scheduled.php ' . $gwname;
}

/* [command => ['minute','hour']] for rows with interval > 0, in row order. */
function speedtest_desired_cron_jobs(array $rows): array {
	$jobs = [];
	$index = 0;
	foreach ($rows as $gw => $row) {
		/* Sink guard: the name goes into a shell command line unescaped. */
		if (!preg_match('/^[A-Za-z0-9_.-]{1,64}$/', (string)$gw)) {
			continue;
		}
		$spec = speedtest_cron_spec((int)($row['interval'] ?? 0), $index);
		if ($spec === null) {
			continue;
		}
		$jobs[speedtest_cron_command((string)$gw)] = $spec;
		$index++;
	}
	return $jobs;
}

/*
 * Validate one settings row (form strings). Returns user-facing errors and a
 * clean row with ints/null.
 */
function speedtest_validate_row(array $row, array $valid_gateways): array {
	$errors = [];
	$gw = trim((string)($row['gateway'] ?? ''));
	if (!in_array($gw, $valid_gateways, true)) {
		$errors[] = "Gateway {$gw} is not a configured gateway";
	}

	[$ok, $down] = speedtest_parse_int_field($row['advertised_down'] ?? '', 1, 100000);
	if (!$ok) {
		$errors[] = 'Advertised download must be a whole number between 1 and 100000';
	}
	[$ok, $up] = speedtest_parse_int_field($row['advertised_up'] ?? '', 1, 100000);
	if (!$ok) {
		$errors[] = 'Advertised upload must be a whole number between 1 and 100000';
	}
	/* Below 50 % is already "fail", so a lower pass threshold would make warn unreachable. */
	[$ok, $threshold] = speedtest_parse_int_field($row['threshold_pct'] ?? '', 50, 100);
	if (!$ok) {
		$errors[] = 'Pass threshold must be a whole number between 50 and 100';
	}
	[$ok, $server] = speedtest_parse_int_field($row['server_id'] ?? '', 1, 999999999);
	if (!$ok) {
		$errors[] = 'Server ID must be a positive whole number';
	}

	[$ok, $interval] = speedtest_parse_int_field($row['interval'] ?? '', 0, 100000);
	if (!$ok || !in_array($interval ?? 0, SPEEDTEST_INTERVALS, true)) {
		$errors[] = 'Interval must be one of the listed values';
		$interval = 0;
	}

	return [
		'errors' => $errors,
		'clean' => [
			'gateway' => $gw,
			'interval' => $interval ?? 0,
			'advertised_down' => $down,
			'advertised_up' => $up,
			'threshold_pct' => $threshold ?? 80,
			'server_id' => $server,
		],
	];
}

/*
 * Validate the whole settings form. 'clean' => ['rows' => [gwname => row],
 * 'retention' => int].
 */
function speedtest_validate_settings(array $rows, array $valid_gateways, string $retention): array {
	$errors = [];
	$clean_rows = [];
	foreach ($rows as $row) {
		$v = speedtest_validate_row($row, $valid_gateways);
		$errors = array_merge($errors, $v['errors']);
		$gw = $v['clean']['gateway'];
		if (isset($clean_rows[$gw])) {
			$errors[] = "Gateway {$gw} is listed more than once";
			continue;
		}
		$clean_rows[$gw] = $v['clean'];
	}

	[$ok, $keep] = speedtest_parse_int_field($retention, 10, 10000);
	if (!$ok) {
		$errors[] = 'Result retention must be a whole number between 10 and 10000';
	}

	return [
		'errors' => $errors,
		'clean' => ['rows' => $clean_rows, 'retention' => $keep ?? 500],
	];
}

/* True if the gateway name is safe to use as a directory name. */
function speedtest_store_safe_name(string $gwname): bool {
	return $gwname !== '' && strpos($gwname, '/') === false && strpos($gwname, '..') === false;
}

/* Write $data to $path atomically (temp file in the same dir, then rename). */
function speedtest_store_atomic_write(string $path, string $data): bool {
	$tmp = $path . '.tmp.' . getmypid();
	if (@file_put_contents($tmp, $data) === false) {
		return false;
	}
	@chmod($tmp, 0644);
	if (!@rename($tmp, $path)) {
		@unlink($tmp);
		return false;
	}
	return true;
}

/* JSON for a stored record; invalid UTF-8 is substituted. Falls back to a minimal error record. */
function speedtest_encode_record(array $record): string {
	$line = json_encode($record, JSON_INVALID_UTF8_SUBSTITUTE);
	if ($line === false) {
		$line = json_encode([
			'gateway' => (string)($record['gateway'] ?? ''),
			'ran_at' => (string)($record['ran_at'] ?? date('c')),
			'state' => 'error',
			'error' => 'could not encode result: ' . json_last_error_msg(),
		], JSON_INVALID_UTF8_SUBSTITUTE);
	}
	return (string)$line;
}

/* States whose external_ip is trusted as this gateway's public IP. */
function speedtest_store_trusts_ip(array $record): bool {
	$state = $record['state'] ?? null;
	$ip = $record['external_ip'] ?? null;
	return $state !== null && $state !== 'error' && $state !== 'egress_mismatch'
		&& is_string($ip) && $ip !== '';
}

/*
 * Persist a record as latest.json and append it to the capped history.jsonl.
 * <gw>/last_public_ip is updated only from runs whose egress was trusted, so a
 * misrouted or failed run cannot poison the next egress check.
 */
function speedtest_store_write(string $root, array $record, int $cap): bool {
	$gw = (string)($record['gateway'] ?? '');
	if (!speedtest_store_safe_name($gw)) {
		return false;
	}
	$dir = "$root/$gw";
	if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
		return false;
	}
	$line = speedtest_encode_record($record);
	if (!speedtest_store_atomic_write("$dir/latest.json", $line . "\n")) {
		return false;
	}
	if (speedtest_store_trusts_ip($record)) {
		speedtest_store_atomic_write("$dir/last_public_ip", $record['external_ip'] . "\n");
	}

	$hist = "$dir/history.jsonl";
	file_put_contents($hist, $line . "\n", FILE_APPEND | LOCK_EX);
	chmod($hist, 0644);
	$lines = file($hist, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
	if ($lines !== false && count($lines) > $cap) {
		$lines = array_slice($lines, -$cap);
		speedtest_store_atomic_write($hist, implode("\n", $lines) . "\n");
	}
	return true;
}

/* Most recent record for a gateway, or null if missing/undecodable. */
function speedtest_store_latest(string $root, string $gwname): ?array {
	if (!speedtest_store_safe_name($gwname)) {
		return null;
	}
	$raw = @file_get_contents("$root/$gwname/latest.json");
	if ($raw === false) {
		return null;
	}
	$data = json_decode($raw, true);
	return is_array($data) ? $data : null;
}

/* [gateway => latest record or null], in the order given. */
function speedtest_store_all_latest(string $root, array $gwnames): array {
	$out = [];
	foreach ($gwnames as $gw) {
		$out[$gw] = speedtest_store_latest($root, $gw);
	}
	return $out;
}

/* Up to $limit history records, newest first; undecodable lines are skipped. */
function speedtest_store_history(string $root, string $gwname, int $limit): array {
	if (!speedtest_store_safe_name($gwname)) {
		return [];
	}
	$lines = @file("$root/$gwname/history.jsonl", FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
	if ($lines === false) {
		return [];
	}
	$out = [];
	foreach (array_reverse($lines) as $line) {
		if (count($out) >= $limit) {
			break;
		}
		$rec = json_decode($line, true);
		if (is_array($rec)) {
			$out[] = $rec;
		}
	}
	return $out;
}

/* [gateway => last trusted public IP (see speedtest_store_write), or null]. */
function speedtest_store_last_public_ips(string $root, array $gwnames): array {
	$out = [];
	foreach ($gwnames as $gw) {
		$ip = speedtest_store_safe_name($gw) ? @file_get_contents("$root/$gw/last_public_ip") : false;
		$ip = $ip === false ? '' : trim($ip);
		if ($ip === '') {
			/* Data written before last_public_ip existed: trust latest.json only if its egress was trusted. */
			$rec = speedtest_store_latest($root, $gw);
			$ip = ($rec !== null && speedtest_store_trusts_ip($rec)) ? (string)$rec['external_ip'] : '';
		}
		$out[$gw] = $ip === '' ? null : $ip;
	}
	return $out;
}

/*
 * Pick the Ookla build for a FreeBSD major: the highest freebsd_major not
 * above $running_major, then the highest version. When $wanted_version is
 * given only that version qualifies. Null when nothing matches.
 */
function speedtest_select_build(array $manifest, int $running_major, ?string $wanted_version): ?array {
	$best = null;
	foreach ($manifest['builds'] ?? [] as $b) {
		if ((int)$b['freebsd_major'] > $running_major) {
			continue;
		}
		if ($wanted_version !== null && $b['version'] !== $wanted_version) {
			continue;
		}
		if ($best === null
		    || (int)$b['freebsd_major'] > (int)$best['freebsd_major']
		    || ((int)$b['freebsd_major'] === (int)$best['freebsd_major']
		    && version_compare($b['version'], $best['version'], '>'))) {
			$best = $b;
		}
	}
	return $best;
}

/* ['present' => bool, 'version' => first line of "$bin --version" or null]. */
function speedtest_ookla_status(string $bin): array {
	if (!is_file($bin) || !is_executable($bin)) {
		return ['present' => false, 'version' => null];
	}
	$out = [];
	$rc = 0;
	exec(escapeshellarg($bin) . ' --accept-license --accept-gdpr --version 2>/dev/null', $out, $rc);
	$line = ($rc === 0 && isset($out[0]) && trim($out[0]) !== '') ? trim($out[0]) : null;
	return ['present' => true, 'version' => $line];
}

/* True for VPN/tunnel interface names (tun0, ovpns1, wg0, ipsec1000, ...). */
function speedtest_is_tunnel_ifname(string $ifname): bool {
	return preg_match('/^(tun|tap|ovpn|wg|ipsec)/', $ifname) === 1;
}

/* Distinct manifest versions, newest first. */
function speedtest_manifest_version_list(array $manifest): array {
	$versions = [];
	foreach ($manifest['builds'] ?? [] as $b) {
		$versions[(string)$b['version']] = true;
	}
	$versions = array_keys($versions);
	usort($versions, function ($a, $b) {
		return version_compare((string)$b, (string)$a);
	});
	return array_map('strval', $versions);
}

/* [value => label] to the [['name' =>, 'value' =>]] rows pkg_edit.php select_source expects. */
function speedtest_select_rows(array $options): array {
	$rows = [];
	foreach ($options as $value => $label) {
		$rows[] = ['name' => (string)$label, 'value' => (string)$value];
	}
	return $rows;
}

function speedtest_h($v): string {
	return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

/* Mbps for display: whole number with unit, or an em dash when absent. */
function speedtest_fmt_mbps($mbps): string {
	return $mbps === null ? '—' : number_format((float)$mbps, 0) . ' Mbps';
}

/*
 * One <td> for a direction ('down'|'up'): only the measured speed and unit,
 * coloured by the record state. Advertised speed and percentage are shown in
 * the detail row (speedtest_render_advertised).
 */
function speedtest_render_speed_cell(array $rec, string $dir): string {
	$state = (string)($rec['state'] ?? '');
	if ($state === 'error' || $state === 'egress_mismatch') {
		if ($dir === 'up') {
			return '<td></td>';
		}
		$label = $state === 'error' ? 'error' : 'egress?';
		return '<td><span class="text-danger" title="' . speedtest_h($rec['error'] ?? '') . '">'
			. speedtest_h($label) . '</span></td>';
	}
	$text = speedtest_h(speedtest_fmt_mbps($rec[$dir . '_mbps'] ?? null));
	$classes = ['pass' => 'text-success', 'warn' => 'text-warning', 'fail' => 'text-danger', 'measured' => 'text-muted'];
	if (!isset($classes[$state])) {
		return '<td>' . $text . '</td>';
	}
	return '<td><span class="' . $classes[$state] . '">' . $text . '</span></td>';
}

/* Detail-row text for one direction: "2,000 Mbps (99 %)", or null without an advertised figure. */
function speedtest_render_advertised(array $rec, string $dir): ?string {
	$adv = $rec['advertised_' . $dir] ?? null;
	if ($adv === null || $adv === '') {
		return null;
	}
	$pct = $rec['pct_' . $dir] ?? null;
	return speedtest_fmt_mbps($adv) . ($pct === null ? '' : ' (' . (int)$pct . ' %)');
}

/*
 * [hide_ping, hide_lastrun] from a widget's saved settings. Settings saved
 * before the two toggles existed only have "compact", which hid both.
 */
function speedtest_widget_hide_flags(array $wcfg): array {
	if (!array_key_exists('hide_ping', $wcfg) && !array_key_exists('hide_lastrun', $wcfg)) {
		$legacy = !empty($wcfg['compact']);
		return [$legacy, $legacy];
	}
	return [!empty($wcfg['hide_ping']), !empty($wcfg['hide_lastrun'])];
}

/* Widget column count: WAN, Download, Upload, [Ping], [Last run], Run button. */
function speedtest_widget_cols(bool $hide_ping, bool $hide_lastrun): int {
	return 6 - (int)$hide_ping - (int)$hide_lastrun;
}

/*
 * Widget <tbody> contents. Pure: callers pass configured gateways
 * [name => ['descr', ...]], latest results [name => record|null], the running
 * descriptor (or null), hidden gateway names and the Hide Ping / Hide Last
 * run flags (see speedtest_widget_hide_flags). With
 * $enabled false (package Enable unchecked) every Run button is disabled.
 */
function speedtest_render_rows(array $gateways, array $results, ?array $running, array $hidden, bool $hide_ping, bool $hide_lastrun, string $widgetkey, bool $enabled = true): string {
	$tr = function_exists('gettext') ? 'gettext' : function ($s) {
		return $s;
	};
	$cols = speedtest_widget_cols($hide_ping, $hide_lastrun);
	$out = '';
	$shown = 0;
	$running_gw = $running['gateway'] ?? null;

	foreach ($gateways as $name => $gw) {
		if (in_array($name, $hidden, true)) {
			continue;
		}
		$shown++;
		$rec = $results[$name] ?? null;
		$rec = is_array($rec) ? $rec : null;
		$gwh = speedtest_h($name);

		$out .= '<tr class="speedtest-row" data-gw="' . $gwh . '">';
		$out .= '<td>' . ($rec !== null ? '<i class="fa-solid fa-chevron-right speedtest-toggle" role="button"></i> ' : '')
			. speedtest_h($gw['descr'] ?? $name) . '</td>';
		if ($rec === null) {
			$out .= '<td></td><td></td>';
		} else {
			$out .= speedtest_render_speed_cell($rec, 'down') . speedtest_render_speed_cell($rec, 'up');
		}
		if (!$hide_ping) {
			$ping = ($rec !== null && isset($rec['ping_ms']) && $rec['ping_ms'] !== null)
				? number_format((float)$rec['ping_ms'], 1) . ' ms' : '';
			$out .= '<td>' . speedtest_h($ping) . '</td>';
		}
		if (!$hide_lastrun) {
			$ts = ($rec !== null && !empty($rec['ran_at'])) ? strtotime((string)$rec['ran_at']) : false;
			$mark = ($rec !== null && ($rec['trigger'] ?? '') === 'scheduled')
				? ' <i class="fa-solid fa-clock" title="scheduled"></i>' : '';
			$out .= '<td>' . speedtest_h($ts === false ? $tr('never') : date('Y-m-d H:i', $ts)) . $mark . '</td>';
		}
		$spin = $running_gw === $name;
		$off = $running !== null || !$enabled;
		$title = $enabled ? $tr('Run speedtest') : $tr('Speedtests are disabled in Services → Speedtest');
		$out .= '<td><a class="btn btn-xs btn-default speedtest-run' . ($off ? ' disabled' : '')
			. ($enabled ? '' : ' speedtest-off') . '"'
			. ' data-gw="' . $gwh . '"' . ($off ? ' disabled="disabled" aria-disabled="true"' : '')
			. ' title="' . speedtest_h($title) . '"><i class="fa-solid '
			. ($spin ? 'fa-spinner fa-spin' : 'fa-play') . '"></i></a></td>';
		$out .= "</tr>\n";

		if ($rec !== null) {
			$srv = is_array($rec['server'] ?? null) ? $rec['server'] : [];
			$bits = [];
			$srvname = trim((string)($srv['name'] ?? '') . ' ' . (isset($srv['location']) ? '(' . $srv['location'] . ')' : ''));
			$fields = [
				'Advertised down' => speedtest_render_advertised($rec, 'down'),
				'Advertised up' => speedtest_render_advertised($rec, 'up'),
				'Server' => $srvname === '' ? null : $srvname,
				'Jitter' => isset($rec['jitter_ms']) ? number_format((float)$rec['jitter_ms'], 1) . ' ms' : null,
				'Packet loss' => isset($rec['packet_loss']) ? number_format((float)$rec['packet_loss'], 1) . ' %' : null,
				'External IP' => $rec['external_ip'] ?? null,
				'ISP' => $rec['isp'] ?? null,
				'Trigger' => ($rec['trigger'] ?? '') === 'scheduled' ? 'scheduled' : 'manual',
			];
			foreach ($fields as $label => $val) {
				if ($val !== null && $val !== '') {
					$bits[] = '<strong>' . speedtest_h($tr($label)) . ':</strong> ' . speedtest_h($val);
				}
			}
			$url = (string)($rec['result_url'] ?? '');
			if (preg_match('#^https?://#', $url) === 1) {
				$bits[] = '<a href="' . speedtest_h($url) . '" target="_blank" rel="noopener noreferrer">'
					. speedtest_h($tr('Ookla result')) . '</a>';
			}
			$out .= '<tr class="speedtest-detail hidden" data-gw="' . $gwh . '"><td colspan="' . $cols . '">'
				. '<small>' . implode(' &middot; ', $bits) . "</small></td></tr>\n";
		}
	}

	if ($shown === 0) {
		$msg = $gateways ? 'All gateways are hidden.' : 'No gateways configured.';
		$out .= '<tr><td colspan="' . $cols . '" class="text-center">' . speedtest_h($tr($msg)) . "</td></tr>\n";
	}
	return $out;
}

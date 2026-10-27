<?php
/*
 * Copyright 2026 Jeff Nelson
 * Licensed under the Apache License, Version 2.0.
 */
require __DIR__ . '/assert.inc';
require __DIR__ . '/../files/usr/local/pkg/speedtest_lib.inc';

function has(string $needle, string $hay): bool {
	return strpos($hay, $needle) !== false;
}

/* [download td, upload td] of the main row for $gw. */
function speed_cells(string $html, string $gw): array {
	preg_match('#<tr class="speedtest-row" data-gw="' . $gw . '">(.*?)</tr>#s', $html, $m);
	preg_match_all('#<td[^>]*>.*?</td>#s', $m[1] ?? '', $td);
	return [$td[0][1] ?? '', $td[0][2] ?? ''];
}

function detail_row(string $html, string $gw): string {
	return preg_match('#<tr class="speedtest-detail hidden" data-gw="' . $gw . '">.*?</tr>#s', $html, $m) ? $m[0] : '';
}

$gws = [
	'QUANTUM_GW' => ['descr' => 'Quantum', 'ifname' => 'igc0', 'friendly' => 'wan', 'address' => '1.2.3.4'],
	'METRONET_GW' => ['descr' => 'MetroNet', 'ifname' => 'ixl5', 'friendly' => 'opt1', 'address' => '5.6.7.8'],
];
$pass = [
	'gateway' => 'QUANTUM_GW', 'ran_at' => '2026-10-23T10:05:00-05:00', 'state' => 'pass',
	'down_mbps' => 1984.4, 'up_mbps' => 1922.2, 'pct_down' => 99, 'pct_up' => 96, 'ping_ms' => 4.25,
	'jitter_ms' => 0.5, 'packet_loss' => 0, 'external_ip' => '198.51.100.1', 'isp' => 'Quantum Fiber',
	'server' => ['id' => 1, 'name' => 'SrvName', 'location' => 'Denver'], 'result_url' => 'https://www.speedtest.net/result/c/abc',
	'advertised_down' => 2000, 'advertised_up' => 2000, 'error' => null,
];
$measured = ['state' => 'measured', 'ran_at' => '2026-10-23T09:00:00-05:00', 'down_mbps' => 120.4, 'up_mbps' => 15.1,
	'pct_down' => null, 'pct_up' => null, 'ping_ms' => 20.0, 'advertised_down' => null, 'advertised_up' => null,
	'server' => [], 'error' => null];
$err = ['state' => 'error', 'ran_at' => '2026-10-23T09:00:00-05:00', 'error' => 'interface has no address <x>'];

$h = speedtest_render_rows($gws, ['QUANTUM_GW' => $pass], null, [], false, false, 'wk');
[$down, $up] = speed_cells($h, 'QUANTUM_GW');
assert_eq('<td><span class="text-success">1,984 Mbps</span></td>', $down, 'download cell: number+unit, state class on the number');
assert_eq('<td><span class="text-success">1,922 Mbps</span></td>', $up, 'upload cell: number+unit, state class on the number');
$txt = strip_tags($down . $up);
assert_true(!has('%', $txt) && !has('/', $txt) && !has('●', $txt), 'no badge, percent or denominator in main cells');
$d = detail_row($h, 'QUANTUM_GW');
assert_true(has('Advertised down:</strong> 2,000 Mbps (99 %)', $d), 'advertised down + pct in detail row');
assert_true(has('Advertised up:</strong> 2,000 Mbps (96 %)', $d), 'advertised up + pct in detail row');
assert_true(has(date('Y-m-d H:i', strtotime('2026-10-23T10:05:00-05:00')), $h), 'ran_at formatted');
assert_true(has('never', $h), 'never for missing result');
assert_true(has('4.3 ms', $h) || has('4.2 ms', $h), 'ping shown');
assert_true(has('data-gw="QUANTUM_GW"', $h), 'run button data-gw');
assert_true(has('fa-chevron-right', $h), 'detail toggle');
assert_true(has('198.51.100.1', $h) && has('Denver', $h), 'detail content');

$h = speedtest_render_rows($gws, ['QUANTUM_GW' => $measured], null, [], false, false, 'wk');
assert_eq(['<td><span class="text-muted">120 Mbps</span></td>', '<td><span class="text-muted">15 Mbps</span></td>'], speed_cells($h, 'QUANTUM_GW'), 'measured: muted number only');
assert_true(!has('Advertised', detail_row($h, 'QUANTUM_GW')), 'measured: no advertised in detail row');

$warn = ['state' => 'warn', 'pct_down' => 75, 'pct_up' => 70] + $pass;
$fail = ['state' => 'fail'] + $pass;
$h = speedtest_render_rows($gws, ['QUANTUM_GW' => $warn], null, [], false, false, 'wk');
assert_eq('<td><span class="text-warning">1,984 Mbps</span></td>', speed_cells($h, 'QUANTUM_GW')[0], 'warn class on number');
assert_true(has('(75 %)', detail_row($h, 'QUANTUM_GW')), 'warn pct in detail row');
$h = speedtest_render_rows($gws, ['QUANTUM_GW' => $fail], null, [], false, false, 'wk');
assert_eq('<td><span class="text-danger">1,922 Mbps</span></td>', speed_cells($h, 'QUANTUM_GW')[1], 'fail class on number');
assert_true(!has('text-success', $h), 'fail has no success class');
$egress = ['state' => 'egress_mismatch', 'error' => 'egress 9.9.9.9 <i>'] + $pass;
$h = speedtest_render_rows($gws, ['QUANTUM_GW' => $egress], null, [], false, false, 'wk');
assert_true(has('egress?', $h) && has('text-danger', $h) && has('title="egress 9.9.9.9 &lt;i&gt;"', $h), 'egress_mismatch badge and title');
$h = speedtest_render_rows($gws, ['QUANTUM_GW' => ['result_url' => 'javascript:alert(1)'] + $pass], null, [], false, false, 'wk');
assert_true(!has('href=', $h) && !has('javascript:', $h), 'javascript: result_url not rendered');
$h = speedtest_render_rows($gws, ['QUANTUM_GW' => $pass], null, [], false, false, 'wk');
assert_true(has('href="https://www.speedtest.net/result/c/abc"', $h), 'https result_url rendered');
$xss = ['external_ip' => '<b>ip</b>', 'isp' => '<b>isp</b>', 'server' => ['name' => '<b>srv</b>', 'location' => '<b>loc</b>']] + $pass;
$h = speedtest_render_rows($gws, ['QUANTUM_GW' => $xss], null, [], false, false, 'wk');
assert_true(!has('<b>', $h) && has('&lt;b&gt;ip', $h) && has('&lt;b&gt;isp', $h) && has('&lt;b&gt;srv', $h) && has('&lt;b&gt;loc', $h), 'detail fields escaped');

$h = speedtest_render_rows($gws, ['QUANTUM_GW' => $err], null, [], false, false, 'wk');
assert_true(has('text-danger', $h), 'error badge');
assert_true(has('title="interface has no address &lt;x&gt;"', $h), 'error title text escaped');

$h = speedtest_render_rows($gws, [], null, ['METRONET_GW'], false, false, 'wk');
assert_true(!has('MetroNet', $h) && has('Quantum', $h), 'hidden gateway omitted');

$h = speedtest_render_rows($gws, ['QUANTUM_GW' => $pass], null, [], true, true, 'wk');
assert_true(!has('4.3 ms', $h) && !has('never', $h) && !has(date('Y-m-d H:i', strtotime('2026-10-23T10:05:00-05:00')), $h), 'both hidden: no ping/last run text');
$main = function (string $html): array {
	preg_match_all('#<tr class="speedtest-row".*?</tr>#s', $html, $m);
	return array_map(function ($r) {
		return substr_count($r, '<td');
	}, $m[0]);
};
assert_eq([4, 4], $main($h), 'both hidden: main rows have 4 cells');
assert_eq([6, 6], $main(speedtest_render_rows($gws, ['QUANTUM_GW' => $pass], null, [], false, false, 'wk')), 'full rows have 6 cells');
assert_true(has('120', speedtest_render_rows($gws, ['QUANTUM_GW' => $measured], null, [], true, true, 'wk')), 'both hidden: download kept');

$h = speedtest_render_rows($gws, [], ['gateway' => 'QUANTUM_GW', 'started_at' => 'x', 'elapsed_s' => 3], [], false, false, 'wk');
assert_true(has('fa-spinner fa-spin', $h), 'running spinner');
assert_eq(2, substr_count($h, 'disabled="disabled"'), 'all run buttons disabled (2 gateways)');

$evil = ['X_GW' => ['descr' => '<b>bold</b>', 'ifname' => 'a', 'friendly' => 'a', 'address' => '1.1.1.1']];
$h = speedtest_render_rows($evil, [], null, [], false, false, 'wk');
assert_true(!has('<b>', $h) && has('&lt;b&gt;bold', $h), 'descr escaped');

$h = speedtest_render_rows([], [], null, [], false, false, 'wk');
assert_true(has('No gateways', $h), 'empty message');
$h = speedtest_render_rows($gws, [], null, ['QUANTUM_GW', 'METRONET_GW'], false, false, 'wk');
assert_true(has('hidden', $h), 'all hidden message');

/* Package Enable off: every Run button disabled with an explanatory title, not a spinner. */
$h = speedtest_render_rows($gws, ['QUANTUM_GW' => $pass], null, [], false, false, 'wk', false);
assert_eq(2, substr_count($h, 'disabled="disabled"'), 'disabled package: all run buttons disabled');
assert_eq(2, substr_count($h, 'speedtest-off'), 'disabled package: buttons marked speedtest-off');
assert_eq(2, substr_count($h, 'title="Speedtests are disabled in Services → Speedtest"'), 'disabled package: button title');
assert_true(!has('fa-spinner', $h), 'disabled package: no spinner');
$h = speedtest_render_rows($gws, ['QUANTUM_GW' => $pass], null, [], false, false, 'wk');
assert_eq(0, substr_count($h, 'disabled'), 'enabled default: buttons enabled');

/* Hide Ping / Hide Last run are independent. */
$ran = date('Y-m-d H:i', strtotime('2026-10-23T10:05:00-05:00'));
$colspans = function (string $html): array {
	preg_match_all('#colspan="(\d+)"#', $html, $m);
	return array_map('intval', array_unique($m[1]));
};
foreach ([[false, false, 6], [true, false, 5], [false, true, 5], [true, true, 4]] as [$hp, $hl, $n]) {
	$label = 'hide_ping=' . (int)$hp . ' hide_lastrun=' . (int)$hl;
	$h = speedtest_render_rows($gws, ['QUANTUM_GW' => $pass], null, [], $hp, $hl, 'wk');
	assert_eq([$n, $n], $main($h), "$label: $n cells per main row");
	assert_eq($hp, !has('4.3 ms', $h) && !has('4.2 ms', $h), "$label: ping cell " . ($hp ? 'absent' : 'present'));
	assert_eq($hl, !has($ran, $h) && !has('never', $h), "$label: last run cell " . ($hl ? 'absent' : 'present'));
	assert_eq([$n], $colspans($h), "$label: detail row colspan $n");
	assert_eq([$n], $colspans(speedtest_render_rows([], [], null, [], $hp, $hl, 'wk')), "$label: empty-row colspan $n");
	assert_eq($n, speedtest_widget_cols($hp, $hl), "$label: header column count $n");
}
$h = speedtest_render_rows($gws, ['QUANTUM_GW' => $pass], null, [], true, false, 'wk');
preg_match('#<tr class="speedtest-row" data-gw="QUANTUM_GW">(.*?)</tr>#s', $h, $m);
preg_match_all('#<td[^>]*>(.*?)</td>#s', $m[1], $td);
assert_eq($ran, $td[1][3], 'hide ping only: Last run is the 4th cell');

/* Saved-settings migration: legacy compact=1 hides both unless the new keys exist. */
assert_eq([false, false], speedtest_widget_hide_flags([]), 'no settings: show both');
assert_eq([true, true], speedtest_widget_hide_flags(['compact' => 1]), 'legacy compact=1: hide both');
assert_eq([false, false], speedtest_widget_hide_flags(['compact' => 0]), 'legacy compact=0: show both');
assert_eq([true, false], speedtest_widget_hide_flags(['compact' => 1, 'hide_ping' => 1, 'hide_lastrun' => 0]), 'new keys win over compact');
assert_eq([false, true], speedtest_widget_hide_flags(['hide_lastrun' => '1']), 'one new key present: other defaults to shown');

/* Trigger marker: clock icon only for scheduled; detail row names the trigger. */
$clock = 'fa-clock';
$sched = $pass + ['trigger' => 'scheduled'];
$man = $pass + ['trigger' => 'manual'];
$h = speedtest_render_rows($gws, ['QUANTUM_GW' => $sched], null, [], false, false, 'wk');
assert_true(has('<i class="fa-solid fa-clock" title="scheduled"></i>', $h), 'scheduled: clock icon');
assert_true(has('Trigger:</strong> scheduled', detail_row($h, 'QUANTUM_GW')), 'scheduled: detail Trigger line');
$h = speedtest_render_rows($gws, ['QUANTUM_GW' => $man], null, [], false, false, 'wk');
assert_true(!has($clock, $h), 'manual: no clock icon');
assert_true(has('Trigger:</strong> manual', detail_row($h, 'QUANTUM_GW')), 'manual: detail Trigger line');
$h = speedtest_render_rows($gws, ['QUANTUM_GW' => $pass], null, [], false, false, 'wk');
assert_true(!has($clock, $h), 'absent trigger: no clock icon');
assert_true(has('Trigger:</strong> manual', detail_row($h, 'QUANTUM_GW')), 'absent trigger defaults to manual');
$h = speedtest_render_rows($gws, ['QUANTUM_GW' => $sched], null, [], false, true, 'wk');
assert_true(!has($clock, $h), 'scheduled with Last run hidden: no clock icon');
assert_true(has('Trigger:</strong> scheduled', detail_row($h, 'QUANTUM_GW')), 'scheduled with Last run hidden: detail still names trigger');

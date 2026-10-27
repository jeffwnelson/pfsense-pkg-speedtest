<?php
/*
 * Copyright 2026 Jeff Nelson
 * Licensed under the Apache License, Version 2.0.
 */
require __DIR__ . '/assert.inc';
require __DIR__ . '/../files/usr/local/pkg/speedtest_lib.inc';

$fx = __DIR__ . '/fixtures';

assert_eq(1332.45, round(speedtest_bandwidth_mbps(166556613), 2), 'mbps');
assert_true(speedtest_parse_ookla(file_get_contents($fx.'/ookla_full.json')) !== null, 'full parses');
assert_null(speedtest_parse_ookla(file_get_contents($fx.'/ookla_partial.json')), 'partial -> null');
assert_null(speedtest_parse_ookla(file_get_contents($fx.'/ookla_error.json')), 'error log -> null');
assert_null(speedtest_parse_ookla('not json'), 'garbage -> null');
assert_eq('pass', speedtest_compute_state(1984, 1922, 2000, 2000, 80), 'pass');
assert_eq('warn', speedtest_compute_state(1500, 1922, 2000, 2000, 80), 'warn 75%');
assert_eq('fail', speedtest_compute_state(1984, 326, 2000, 2000, 80), 'fail 16% up');
assert_eq('measured', speedtest_compute_state(120, 15, null, null, 80), 'measured');
assert_eq('pass', speedtest_compute_state(1333, 1282, 1000, 1000, 80), 'over 100% is pass');
assert_eq(99, speedtest_pct(1984, 2000), 'pct'); assert_null(speedtest_pct(5, null), 'pct null');
$r = speedtest_build_record('METRONET_GW','ixl5','203.0.113.45',['advertised_down'=>2000,'advertised_up'=>2000,'threshold_pct'=>80,'server_id'=>null], speedtest_parse_ookla(file_get_contents($fx.'/ookla_full.json')), null, '2026-06-29T08:38:33-05:00');
assert_eq('pass', $r['state'], 'record state'); assert_eq(1984, (int)round($r["down_mbps"]), 'record down'); assert_eq('203.0.113.45', $r['external_ip'], 'record ext ip');
$e = speedtest_build_record('STARLINK_DHCP','igb1','',['advertised_down'=>null,'advertised_up'=>null,'threshold_pct'=>80,'server_id'=>null], null, 'interface has no address', '2026-10-23T10:00:00-05:00');
assert_eq('manual', $r['trigger'], 'record trigger defaults manual');
$t = speedtest_build_record('X','i','',['trigger'=>'scheduled'], null, 'e', '2026-10-23T10:00:00-05:00');
assert_eq('scheduled', $t['trigger'], 'record trigger scheduled');
$t = speedtest_build_record('X','i','',['trigger'=>'evil'], null, 'e', '2026-10-23T10:00:00-05:00');
assert_eq('manual', $t['trigger'], 'record trigger invalid -> manual');
assert_eq('error', $e['state'], 'error record'); assert_eq('interface has no address', $e['error'], 'error msg'); assert_null($e['down_mbps'], 'error has no mbps');

<?php
/*
 * Copyright 2026 Jeff Nelson
 * Licensed under the Apache License, Version 2.0.
 */
require __DIR__ . '/assert.inc';
require __DIR__ . '/../files/usr/local/pkg/speedtest_lib.inc';

assert_eq([0, 60, 180, 360, 720, 1440], SPEEDTEST_INTERVALS, 'intervals');
assert_eq([0 => 'Off', 60 => 'Every hour', 180 => 'Every 3 hours', 360 => 'Every 6 hours', 720 => 'Every 12 hours', 1440 => 'Daily (03:00)'], speedtest_interval_options(), 'options');

/* cron_spec_stagger */
assert_eq(['minute' => '0', 'hour' => '*'], speedtest_cron_spec(60, 0), 'hourly idx0');
assert_eq(['minute' => '7', 'hour' => '*'], speedtest_cron_spec(60, 1), 'hourly idx1');
assert_eq(['minute' => '0', 'hour' => '3'], speedtest_cron_spec(1440, 0), 'daily idx0');
assert_eq(['minute' => '7', 'hour' => '3'], speedtest_cron_spec(1440, 1), 'daily idx1');
assert_eq(['minute' => '14', 'hour' => '3'], speedtest_cron_spec(1440, 2), 'daily idx2');
assert_eq(['minute' => '0', 'hour' => '*/3'], speedtest_cron_spec(180, 0), '3h');
assert_eq('*/6', speedtest_cron_spec(360, 0)['hour'], '6h');
assert_eq('*/12', speedtest_cron_spec(720, 0)['hour'], '12h');
assert_null(speedtest_cron_spec(0, 0), 'off');
assert_null(speedtest_cron_spec(90, 0), 'invalid');

assert_eq('/usr/local/bin/php -f /usr/local/libexec/speedtest/scheduled.php QUANTUM_GW', speedtest_cron_command('QUANTUM_GW'), 'command');

/* desired_jobs_order_and_skip */
$jobs = speedtest_desired_cron_jobs([
	'A_GW' => ['interval' => 60],
	'B_GW' => ['interval' => 0],
	'C_GW' => ['interval' => 1440],
]);
assert_eq([
	speedtest_cron_command('A_GW') => ['minute' => '0', 'hour' => '*'],
	speedtest_cron_command('C_GW') => ['minute' => '7', 'hour' => '3'],
], $jobs, 'desired jobs');
assert_eq([speedtest_cron_command('A_GW'), speedtest_cron_command('C_GW')], array_keys($jobs), 'order');

/* name guard: hostile name yields no job and consumes no stagger slot */
$jobs = speedtest_desired_cron_jobs([
	'X; id' => ['interval' => 60],
	'A_GW' => ['interval' => 60],
]);
assert_eq([speedtest_cron_command('A_GW') => ['minute' => '0', 'hour' => '*']], $jobs, 'bad name skipped, no slot consumed');

/* validate_interval */
$base = ['gateway' => 'A_GW', 'advertised_down' => '', 'advertised_up' => '', 'threshold_pct' => '', 'server_id' => ''];
$v = speedtest_validate_row($base + ['interval' => ''], ['A_GW']);
assert_eq([], $v['errors'], 'blank ok'); assert_eq(0, $v['clean']['interval'], 'blank -> 0');
$v = speedtest_validate_row($base, ['A_GW']);
assert_eq(0, $v['clean']['interval'], 'missing -> 0');
$v = speedtest_validate_row($base + ['interval' => '180'], ['A_GW']);
assert_eq([], $v['errors'], '180 ok'); assert_eq(180, $v['clean']['interval'], '180 int');
$v = speedtest_validate_row($base + ['interval' => '90'], ['A_GW']);
assert_eq(['Interval must be one of the listed values'], $v['errors'], '90 error');
$v = speedtest_validate_row($base + ['interval' => 'abc'], ['A_GW']);
assert_eq(['Interval must be one of the listed values'], $v['errors'], 'abc error');

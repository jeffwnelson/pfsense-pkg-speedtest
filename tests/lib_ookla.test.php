<?php
/*
 * Copyright 2026 Jeff Nelson
 * Licensed under the Apache License, Version 2.0.
 */
require __DIR__ . '/assert.inc';
require __DIR__ . '/../files/usr/local/pkg/speedtest_lib.inc';

$m = json_decode(file_get_contents(__DIR__ . '/../files/usr/local/share/pfSense-pkg-speedtest/ookla-manifest.json'), true);
assert_eq(13, speedtest_select_build($m, 16, null)['freebsd_major'], '16 -> newest lower = 13');
assert_eq(12, speedtest_select_build($m, 12, null)['freebsd_major'], '12 -> 12');
assert_null(speedtest_select_build($m, 11, null), '11 -> none');
assert_null(speedtest_select_build($m, 16, '9.9.9'), 'unknown version -> none');
assert_eq('1.2.0', speedtest_select_build($m, 16, '1.2.0')['version'], 'wanted version matches');
assert_eq(false, speedtest_ookla_status('/nonexistent/bin')['present'], 'status missing');

$fake = sys_get_temp_dir() . '/st_fake_' . getmypid();
file_put_contents($fake, "#!/bin/sh\necho 'Speedtest by Ookla 1.2.0'\necho second\n");
chmod($fake, 0755);
$s = speedtest_ookla_status($fake);
assert_eq(true, $s['present'], 'status present');
assert_eq('Speedtest by Ookla 1.2.0', $s['version'], 'status version first line');
chmod($fake, 0644);
assert_eq(false, speedtest_ookla_status($fake)['present'], 'non-executable not present');
unlink($fake);

assert_eq(true, speedtest_is_tunnel_ifname('ovpns1'), 'ovpns is tunnel');
assert_eq(true, speedtest_is_tunnel_ifname('wg0'), 'wg is tunnel');
assert_eq(true, speedtest_is_tunnel_ifname('ipsec1000'), 'ipsec is tunnel');
assert_eq(false, speedtest_is_tunnel_ifname('igc0'), 'igc is not tunnel');
assert_eq(false, speedtest_is_tunnel_ifname('igc0.100'), 'vlan is not tunnel');
assert_eq(['1.10.0', '1.2.0'], speedtest_manifest_version_list(['builds' => [
	['version' => '1.2.0'], ['version' => '1.10.0'], ['version' => '1.2.0']]]), 'versions distinct newest first');
assert_eq([['name' => 'A (a)', 'value' => 'a']], speedtest_select_rows(['a' => 'A (a)']), 'select rows shape');

<?php
/*
 * Copyright 2026 Jeff Nelson
 * Licensed under the Apache License, Version 2.0.
 *
 * Load the real files/usr/local/pkg/speedtest.inc against the pfSense stubs in
 * tests/stubs/pfsense. Paths are redirected with $GLOBALS['speedtest_test_paths'].
 */
set_include_path(__DIR__ . '/stubs/pfsense' . PATH_SEPARATOR . get_include_path());
require_once __DIR__ . '/../files/usr/local/pkg/speedtest.inc';

/* Two configured gateways on wan/opt1 plus an unconfigured one. */
function glue_fixture(string $root, string $run_dir, string $bin): void {
	$GLOBALS['speedtest_test_paths'] = [
		'root' => $root,
		'run_dir' => $run_dir,
		'bin' => $bin,
		'libexec' => __DIR__ . '/../files/usr/local/libexec/speedtest',
	];
	$GLOBALS['stub_config'] = ['installedpackages' => [
		'speedtest' => ['config' => [['enable' => 'on', 'retention' => '']]],
		'speedtestgateways' => ['config' => [
			['gateway' => 'METRO_GW', 'advertised_down' => '2000', 'advertised_up' => '', 'threshold_pct' => '', 'server_id' => '1234'],
			['gateway' => 'QUANTUM_GW', 'advertised_down' => '1000', 'advertised_up' => '1000', 'threshold_pct' => '90', 'server_id' => ''],
		]],
	]];
	$GLOBALS['stub_gateways'] = [
		'METRO_GW' => ['descr' => 'MetroNet', 'friendlyiface' => 'opt1', 'ipprotocol' => 'inet'],
		'QUANTUM_GW' => ['descr' => 'Quantum', 'friendlyiface' => 'wan', 'ipprotocol' => 'inet'],
		'OTHER_GW' => ['descr' => 'Other', 'friendlyiface' => 'opt2', 'ipprotocol' => 'inet'],
	];
	$GLOBALS['stub_ifaces'] = [
		'opt1' => ['real' => 'ixl5', 'ip' => '203.0.113.45'],
		'wan' => ['real' => 'igc0', 'ip' => '198.51.100.1'],
		'opt2' => ['real' => 'igb1', 'ip' => ''],
	];
	$GLOBALS['stub_mwexec_bg'] = [];
}

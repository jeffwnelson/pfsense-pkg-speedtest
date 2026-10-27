<?php
/*
 * Copyright 2026 Jeff Nelson
 * Licensed under the Apache License, Version 2.0.
 *
 * Test stub for pfSense's interfaces.inc. $GLOBALS['stub_ifaces'] maps a
 * friendly name (wan, opt1) to ['real' => 'ixl5', 'ip' => '1.2.3.4'|''].
 */
function get_real_interface(string $interface = 'wan', string $family = 'all', bool $realv6iface = false, bool $flush = true) {
	return $GLOBALS['stub_ifaces'][$interface]['real'] ?? $interface;
}

function get_interface_ip(string $interface = 'wan') {
	$ip = $GLOBALS['stub_ifaces'][$interface]['ip'] ?? '';
	return $ip === '' ? null : $ip;
}

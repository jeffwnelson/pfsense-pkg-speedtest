<?php
/*
# Copyright 2026 Jeff Nelson
# Licensed under the Apache License, Version 2.0.
 *
 * Render the pkg(8) +MANIFEST (JSON) to stdout.
 * Usage: php gen-manifest.php <version>
 */
if ($argc !== 2) {
	fwrite(STDERR, "usage: gen-manifest.php <version>\n");
	exit(1);
}
$name = 'pfSense-pkg-speedtest';
$dir = __DIR__;

function script($file, $name, $phase) {
	// pkg runs embedded scripts without the legacy $1/$2 arguments, so set them.
	$text = file_get_contents($file);
	if ($text === false) {
		fwrite(STDERR, "cannot read $file\n");
		exit(1);
	}
	return preg_replace('/^#!\/bin\/sh\n/', "#!/bin/sh\nset -- $name $phase\n", $text, 1);
}

$manifest = [
	'name' => $name,
	'version' => $argv[1],
	'origin' => 'net/' . $name,
	'comment' => 'Per-WAN Ookla speedtests for pfSense',
	'desc' => 'On-demand Ookla Speedtest CLI runs per WAN gateway, '
		. 'started and shown from a dashboard widget.',
	'maintainer' => 'jeff@tuxy.io',
	'www' => 'https://github.com/jeffwnelson/pfsense-pkg-speedtest',
	'abi' => 'FreeBSD:16:amd64',
	'arch' => 'freebsd:16:x86:64',
	'prefix' => '/',
	'licenselogic' => 'single',
	'licenses' => ['APACHE20'],
	'categories' => ['net'],
	'deps' => new stdClass(),
	'scripts' => [
		'post-install' => script("$dir/pkg-install.sh", $name, 'POST-INSTALL'),
		'pre-deinstall' => script("$dir/pkg-deinstall.sh", $name, 'DEINSTALL'),
		'post-deinstall' => script("$dir/pkg-deinstall.sh", $name, 'POST-DEINSTALL'),
	],
];
echo json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";

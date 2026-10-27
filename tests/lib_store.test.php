<?php
/*
 * Copyright 2026 Jeff Nelson
 * Licensed under the Apache License, Version 2.0.
 */
require __DIR__ . '/assert.inc';
require __DIR__ . '/../files/usr/local/pkg/speedtest_lib.inc';

$root=sys_get_temp_dir().'/st_'.getmypid(); $rec=['gateway'=>'METRONET_GW','state'=>'pass','external_ip'=>'203.0.113.45','ran_at'=>'2026-10-23T10:00:00-05:00'];
speedtest_store_write($root,$rec,3); assert_eq('pass', speedtest_store_latest($root,'METRONET_GW')['state'],'latest');
for($i=0;$i<5;$i++){ $rec['ran_at']="2026-10-23T10:0{$i}:00-05:00"; speedtest_store_write($root,$rec,3); }
assert_eq(3, count(speedtest_store_history($root,'METRONET_GW',100)),'cap 3'); assert_eq('2026-10-23T10:04:00-05:00', speedtest_store_history($root,'METRONET_GW',1)[0]['ran_at'],'newest first');
file_put_contents("$root/METRONET_GW/history.jsonl","{broken\n",FILE_APPEND); assert_eq(3, count(speedtest_store_history($root,'METRONET_GW',100)),'read_history_skips_corrupt');
file_put_contents("$root/METRONET_GW/latest.json","{broken"); assert_null(speedtest_store_latest($root,'METRONET_GW'),'read_latest_corrupt_returns_null');
assert_null(speedtest_store_latest($root,'NOPE'),'missing gw null');
$all=speedtest_store_all_latest($root,['QUANTUM_GW','METRONET_GW']); assert_eq(['QUANTUM_GW','METRONET_GW'], array_keys($all),'order kept'); assert_null($all['QUANTUM_GW'],'missing is null');
speedtest_store_write($root,['gateway'=>'QUANTUM_GW','state'=>'pass','external_ip'=>'198.51.100.214','ran_at'=>'x'],500);
assert_eq('198.51.100.214', speedtest_store_last_public_ips($root,['QUANTUM_GW','METRONET_GW'])['QUANTUM_GW'],'last public ips');

/* Unsafe gateway names are refused without writing anything. */
speedtest_store_write($root,['gateway'=>'../evil','state'=>'pass'],3);
speedtest_store_write($root,['gateway'=>'a/b','state'=>'pass'],3);
assert_true(!file_exists("$root/../evil") && !file_exists("$root/a"),'unsafe gateway refused');
assert_null(speedtest_store_latest($root,'../evil'),'unsafe gateway read null');

/* File and directory permissions; no leftover temp files. */
assert_eq('0755', substr(sprintf('%o',fileperms("$root/QUANTUM_GW")),-4),'dir perms');
assert_eq('0644', substr(sprintf('%o',fileperms("$root/QUANTUM_GW/latest.json")),-4),'latest perms');
assert_eq('0644', substr(sprintf('%o',fileperms("$root/QUANTUM_GW/history.jsonl")),-4),'history perms');
assert_eq(['history.jsonl','last_public_ip','latest.json'], array_values(array_diff(scandir("$root/METRONET_GW"),['.','..'])),'no temp files left');

/* last_public_ip: only runs whose egress is trusted update it. */
$lp = function () use ($root) { return speedtest_store_last_public_ips($root, ['LP_GW'])['LP_GW']; };
assert_null($lp(), 'last_public_ip absent -> null');
speedtest_store_write($root, ['gateway'=>'LP_GW','state'=>'pass','external_ip'=>'1.1.1.1','ran_at'=>'a'], 10);
assert_eq('1.1.1.1', $lp(), 'pass updates last_public_ip');
speedtest_store_write($root, ['gateway'=>'LP_GW','state'=>'egress_mismatch','external_ip'=>'9.9.9.9','ran_at'=>'b'], 10);
assert_eq('1.1.1.1', $lp(), 'egress_mismatch does not update last_public_ip');
speedtest_store_write($root, ['gateway'=>'LP_GW','state'=>'error','external_ip'=>null,'ran_at'=>'c'], 10);
assert_eq('1.1.1.1', $lp(), 'error does not update last_public_ip');
speedtest_store_write($root, ['gateway'=>'LP_GW','state'=>'error','external_ip'=>'8.8.8.8','ran_at'=>'c2'], 10);
assert_eq('1.1.1.1', $lp(), 'error with ip does not update last_public_ip');
foreach (['warn'=>'2.2.2.2','fail'=>'3.3.3.3','measured'=>'4.4.4.4'] as $st=>$ip) {
	speedtest_store_write($root, ['gateway'=>'LP_GW','state'=>$st,'external_ip'=>$ip,'ran_at'=>'d'], 10);
	assert_eq($ip, $lp(), "$st updates last_public_ip");
}
assert_eq('egress_mismatch', speedtest_store_history($root,'LP_GW',10)[5]['state'], 'mismatch still recorded in history');

/* Legacy data (no last_public_ip file): fall back to latest.json only when trusted. */
mkdir("$root/OLD_GW"); file_put_contents("$root/OLD_GW/latest.json", json_encode(['gateway'=>'OLD_GW','state'=>'pass','external_ip'=>'5.5.5.5']));
mkdir("$root/OLD_BAD"); file_put_contents("$root/OLD_BAD/latest.json", json_encode(['gateway'=>'OLD_BAD','state'=>'egress_mismatch','external_ip'=>'6.6.6.6']));
assert_eq(['OLD_GW'=>'5.5.5.5','OLD_BAD'=>null], speedtest_store_last_public_ips($root,['OLD_GW','OLD_BAD']), 'legacy fallback trusts only good states');

/* Encoding: invalid UTF-8 is substituted, unencodable records become an error record. */
assert_true(speedtest_store_write($root, ['gateway'=>'ENC_GW','state'=>'error','error'=>"bad \xff byte",'ran_at'=>'e'], 10), 'invalid utf8 written');
assert_eq("bad \u{FFFD} byte", speedtest_store_latest($root,'ENC_GW')['error'], 'invalid utf8 substituted');
assert_true(speedtest_store_write($root, ['gateway'=>'ENC_GW','state'=>'pass','down_mbps'=>INF,'ran_at'=>'f'], 10), 'INF record written');
$e = speedtest_store_latest($root,'ENC_GW');
assert_eq('error', $e['state'], 'unencodable record stored as error'); assert_eq('f', $e['ran_at'], 'fallback keeps ran_at');
assert_true(strpos($e['error'], 'could not encode result') === 0, 'fallback error text');
assert_true(!speedtest_store_write("$root/ENC_GW/latest.json", ['gateway'=>'X','state'=>'pass'], 10), 'unwritable root returns false');

/* Cleanup */
foreach (glob("$root/*/*") as $f) { unlink($f); }
foreach (glob("$root/*", GLOB_ONLYDIR) as $d) { rmdir($d); }
rmdir($root);

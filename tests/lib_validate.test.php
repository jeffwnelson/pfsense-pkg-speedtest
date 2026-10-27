<?php
/*
 * Copyright 2026 Jeff Nelson
 * Licensed under the Apache License, Version 2.0.
 */
require __DIR__ . '/assert.inc';
require __DIR__ . '/../files/usr/local/pkg/speedtest_lib.inc';

$others=['QUANTUM_GW'=>['address'=>'198.51.100.214','last_public_ip'=>'198.51.100.214'],'STARLINK_DHCP'=>['address'=>null,'last_public_ip'=>'203.0.113.9']];
assert_true(speedtest_check_egress('203.0.113.45','METRONET_GW',$others),'own ip ok');
assert_true(!speedtest_check_egress('198.51.100.214','METRONET_GW',$others),'other wan ip = mismatch');
assert_true(!speedtest_check_egress('203.0.113.9','METRONET_GW',$others),'other last public ip = mismatch');
assert_true(speedtest_check_egress('100.72.3.4','STARLINK_DHCP',['METRONET_GW'=>['address'=>'203.0.113.45','last_public_ip'=>null]]),'cgnat starlink ok');
assert_true(speedtest_check_egress('203.0.113.45','METRONET_GW',['X'=>['address'=>'','last_public_ip'=>null]]),'empty other values ignored');
$rec=['state'=>'pass','external_ip'=>'198.51.100.214','error'=>null]; $a=speedtest_apply_egress($rec,$others);
assert_eq('egress_mismatch', $a['state'],'apply'); assert_eq('egress via QUANTUM_GW (198.51.100.214)', $a['error'],'egress error names the other gateway');
assert_eq('STARLINK_DHCP', speedtest_egress_match('203.0.113.9',$others),'egress match name'); assert_null(speedtest_egress_match('1.1.1.1',$others),'no egress match');
$rec=['state'=>'pass','external_ip'=>'203.0.113.45']; assert_eq('pass', speedtest_apply_egress($rec,$others)['state'],'apply no mismatch');
$rec=['state'=>'error','external_ip'=>null]; assert_eq('error', speedtest_apply_egress($rec,$others)['state'],'error wins');
$rec=['state'=>'error','external_ip'=>'198.51.100.214']; assert_eq('error', speedtest_apply_egress($rec,$others)['state'],'error wins with ip');
$rec=['state'=>'pass','external_ip'=>'']; assert_eq('pass', speedtest_apply_egress($rec,$others)['state'],'empty ip unchanged');

$v=speedtest_validate_row(['gateway'=>'METRONET_GW','advertised_down'=>'2000','advertised_up'=>'2000','threshold_pct'=>'','server_id'=>''],['METRONET_GW','QUANTUM_GW']);
assert_eq([], $v['errors'],'valid row'); assert_eq(80,$v['clean']['threshold_pct'],'default threshold'); assert_null($v['clean']['server_id'],'blank server');
assert_eq(2000,$v['clean']['advertised_down'],'down int');
$v=speedtest_validate_row(['gateway'=>'STARLINK_DHCP','advertised_down'=>'','advertised_up'=>'  ','threshold_pct'=>'80','server_id'=>''],['STARLINK_DHCP']);
assert_eq([], $v['errors'],'blank advertised ok'); assert_null($v['clean']['advertised_down'],'blank -> null'); assert_null($v['clean']['advertised_up'],'whitespace -> null');
$v=speedtest_validate_row(['gateway'=>'EVIL;rm','advertised_down'=>'1','advertised_up'=>'1','threshold_pct'=>'80','server_id'=>''],['METRONET_GW']);
assert_eq(['Gateway EVIL;rm is not a configured gateway'],$v['errors'],'bad gateway');
$v=speedtest_validate_row(['gateway'=>'METRONET_GW','advertised_down'=>'-5','advertised_up'=>'abc','threshold_pct'=>'0','server_id'=>'x'],['METRONET_GW']);
assert_eq(['Advertised download must be a whole number between 1 and 100000','Advertised upload must be a whole number between 1 and 100000','Pass threshold must be a whole number between 50 and 100','Server ID must be a positive whole number'],$v['errors'],'four errors');
$v=speedtest_validate_row(['gateway'=>'METRONET_GW','advertised_down'=>'100001','advertised_up'=>'100000','threshold_pct'=>'101','server_id'=>'0'],['METRONET_GW']);
assert_eq(3,count($v['errors']),'range limits');
foreach (['49'=>false,'50'=>true,'1'=>false,'100'=>true] as $t=>$ok) {
	$v=speedtest_validate_row(['gateway'=>'METRONET_GW','advertised_down'=>'','advertised_up'=>'','threshold_pct'=>(string)$t,'server_id'=>''],['METRONET_GW']);
	assert_eq($ok ? [] : ['Pass threshold must be a whole number between 50 and 100'],$v['errors'],"threshold $t");
}
$v=speedtest_validate_row(['gateway'=>'METRONET_GW','advertised_down'=>'1.5','advertised_up'=>'99999999999999999999','threshold_pct'=>' 100 ','server_id'=>' 1234 '],['METRONET_GW']);
assert_eq(2,count($v['errors']),'decimal and huge rejected'); assert_eq(100,$v['clean']['threshold_pct'],'trimmed threshold'); assert_eq(1234,$v['clean']['server_id'],'trimmed server');

$row=['gateway'=>'METRONET_GW','advertised_down'=>'1','advertised_up'=>'1','threshold_pct'=>'','server_id'=>''];
$s=speedtest_validate_settings([$row,$row],['METRONET_GW'],'');
assert_true(in_array('Gateway METRONET_GW is listed more than once',$s['errors']),'dup'); assert_eq(500,$s['clean']['retention'],'default retention');
$s=speedtest_validate_settings([$row],['METRONET_GW'],'250');
assert_eq([],$s['errors'],'valid settings'); assert_eq(250,$s['clean']['retention'],'retention'); assert_eq(80,$s['clean']['rows']['METRONET_GW']['threshold_pct'],'rows keyed by gateway');
$s=speedtest_validate_settings([$row],['METRONET_GW'],'9');
assert_eq(['Result retention must be a whole number between 10 and 10000'],$s['errors'],'retention low');
$s=speedtest_validate_settings([],['METRONET_GW'],'10001');
assert_eq(1,count($s['errors']),'retention high');

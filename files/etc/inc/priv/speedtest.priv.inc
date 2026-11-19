<?php
/*
 * Copyright 2026 Jeff Nelson
 * Licensed under the Apache License, Version 2.0.
 */
global $priv_list;

$priv_list['page-services-speedtest'] = array();
$priv_list['page-services-speedtest']['name'] = "WebCfg - Services: Speedtest";
$priv_list['page-services-speedtest']['descr'] = "Allow access to the Speedtest package pages and widget.";
$priv_list['page-services-speedtest']['match'] = array();
$priv_list['page-services-speedtest']['match'][] = "pkg_edit.php?xml=speedtest.xml*";
$priv_list['page-services-speedtest']['match'][] = "pkg.php?xml=speedtestgateways.xml*";
$priv_list['page-services-speedtest']['match'][] = "pkg_edit.php?xml=speedtestgateways.xml*";
$priv_list['page-services-speedtest']['match'][] = "widgets/widgets/speedtest.widget.php*";

/* Installing package versions runs pkg as root, so it is a separate privilege. */
$priv_list['page-services-speedtest-updates'] = array();
$priv_list['page-services-speedtest-updates']['name'] = "WebCfg - Services: Speedtest: Updates";
$priv_list['page-services-speedtest-updates']['descr'] = "Allow checking for and installing Speedtest package updates.";
$priv_list['page-services-speedtest-updates']['match'] = array();
$priv_list['page-services-speedtest-updates']['match'][] = "speedtest_updates.php*";

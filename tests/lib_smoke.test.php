<?php
/*
 * Copyright 2026 Jeff Nelson
 * Licensed under the Apache License, Version 2.0.
 */
require __DIR__.'/assert.inc';
require __DIR__.'/../files/usr/local/pkg/speedtest_lib.inc';
assert_true(function_exists('speedtest_bandwidth_mbps'), 'lib loads');

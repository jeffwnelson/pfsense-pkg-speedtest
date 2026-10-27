<?php
/*
 * Copyright 2026 Jeff Nelson
 * Licensed under the Apache License, Version 2.0.
 *
 * Cron entry: start a scheduled speedtest. Usage: scheduled.php <gwname>
 */
require_once getenv('SPEEDTEST_INC') ?: '/usr/local/pkg/speedtest.inc';
exit(speedtest_scheduled_main($argv));

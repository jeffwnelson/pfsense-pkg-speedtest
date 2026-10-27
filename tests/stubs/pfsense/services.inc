<?php
/*
 * Copyright 2026 Jeff Nelson
 * Licensed under the Apache License, Version 2.0.
 *
 * Test stub for pfSense's services.inc: configure_cron() counts calls in
 * $GLOBALS['stub_configure_cron']. The package edits cron/item directly.
 */
function configure_cron(): void {
	$GLOBALS['stub_configure_cron'] = ($GLOBALS['stub_configure_cron'] ?? 0) + 1;
}

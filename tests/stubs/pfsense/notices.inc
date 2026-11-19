<?php
/*
 * Copyright 2026 Jeff Nelson
 * Licensed under the Apache License, Version 2.0.
 *
 * Test stub for pfSense's notices.inc: file_notice() records its arguments in
 * $GLOBALS['stub_notices'].
 */
function file_notice($id, $notice, $category = "General", $url = "", $priority = 1, $local_only = false) {
	$GLOBALS['stub_notices'][] = ['id' => $id, 'notice' => $notice, 'category' => $category, 'url' => $url];
	return true;
}

#!/bin/sh
# Licensed under the Apache License, Version 2.0. Copyright (c) 2026 Jeff Nelson.
# pkg_edit.php calls write_config($pkg['addedit_string']) / ($pkg['delete_string']);
# missing strings produce "write_config() was called without description" in the GUI.
cd "$(dirname "$0")/.." || exit 1
rc=0
for f in files/usr/local/pkg/speedtest.xml files/usr/local/pkg/speedtestgateways.xml; do
	for tag in addedit_string delete_string; do
		if ! grep -Eq "<$tag>[^<]+</$tag>" "$f"; then
			echo "FAIL $f lacks a non-empty <$tag>"
			rc=1
		fi
	done
done
[ $rc -eq 0 ] && echo "ok xml_strings"
exit $rc

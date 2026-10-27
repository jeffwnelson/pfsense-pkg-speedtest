#!/bin/sh
# Copyright 2026 Jeff Nelson
# Licensed under the Apache License, Version 2.0.
#
# pkg deinstall hook. The build embeds this in +MANIFEST with $2 set to the phase.
# pkg(8) exports PKG_UPGRADE=true to scripts run as part of an upgrade
# (pkg-script(5) ENVIRONMENT; libpkg/scripts.c pkg_script_run()). Current pkg
# runs only the old pre-deinstall during an upgrade, but older or other code
# paths may run post-deinstall too, so it must never delete the Ookla binary
# or stored results while PKG_UPGRADE is set.
case "${2}" in
DEINSTALL)
	/usr/local/bin/php -f /etc/rc.packages pfSense-pkg-speedtest DEINSTALL
	;;
POST-DEINSTALL)
	/usr/local/bin/php -f /etc/rc.packages pfSense-pkg-speedtest POST-DEINSTALL
	if [ -n "${PKG_UPGRADE:-}" ]; then
		exit 0
	fi
	rm -f /usr/local/libexec/speedtest/ookla-speedtest
	rm -rf /var/run/speedtest
	# Results are kept unless the settings page left the removal marker.
	if [ -e /var/db/speedtest/.remove-on-deinstall ]; then
		rm -rf /var/db/speedtest
	fi
	;;
esac
exit 0

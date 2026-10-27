#!/bin/sh
# Copyright 2026 Jeff Nelson
# Licensed under the Apache License, Version 2.0.
#
# pkg install hook. The build embeds this in +MANIFEST with $2 set to the phase.
if [ "${2}" != "POST-INSTALL" ]; then
	exit 0
fi
/usr/local/bin/php -f /etc/rc.packages pfSense-pkg-speedtest POST-INSTALL
mkdir -p /var/db/speedtest
# Never fail the package install because the Ookla download failed.
/usr/local/libexec/speedtest/ookla-install.sh \
	/usr/local/share/pfSense-pkg-speedtest/ookla-manifest.json \
	"$(pkg config ABI | cut -d: -f2)" \
	/usr/local/libexec/speedtest/ookla-speedtest ||
	echo "WARNING: Ookla CLI install failed; use Services > Speedtest > Reinstall CLI"
exit 0

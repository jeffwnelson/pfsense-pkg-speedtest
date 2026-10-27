#!/bin/sh
# Copyright 2026 Jeff Nelson
# Licensed under the Apache License, Version 2.0.
#
# Build dist/pfSense-pkg-speedtest-<version>.pkg from files/.
# Usage: build.sh <version> [--dry-run]
# --dry-run stages and renders everything but prints the pkg create command.
set -eu

if [ $# -lt 1 ] || [ $# -gt 2 ]; then
	echo "usage: $0 <version> [--dry-run]" >&2
	exit 1
fi
version=$1
dry=0
if [ "${2:-}" = "--dry-run" ]; then
	dry=1
elif [ $# -eq 2 ]; then
	echo "usage: $0 <version> [--dry-run]" >&2
	exit 1
fi
name=pfSense-pkg-speedtest
php=php
[ -x /usr/local/bin/php ] && php=/usr/local/bin/php

cd "$(dirname "$0")"
rm -rf stage dist plist manifest.json
mkdir -p stage dist

# Stage files/ (modes preserved), skipping .gitkeep placeholders.
(cd files && find . -type f ! -name .gitkeep | cpio -pdm ../stage 2>/dev/null)

for f in usr/local/pkg/speedtest.xml usr/local/pkg/speedtestgateways.xml \
	usr/local/share/$name/info.xml; do
	sed "s/%%PKGVERSION%%/$version/g" "stage/$f" >"stage/$f.new"
	cat "stage/$f.new" >"stage/$f"
	rm -f "stage/$f.new"
done

find stage -type f | sed 's#^stage##' | sort >plist
"$php" pkg/gen-manifest.php "$version" >manifest.json

if [ $dry -eq 1 ]; then
	echo "pkg create -M manifest.json -p plist -r stage -o dist/"
	echo "would produce dist/$name-$version.pkg"
	exit 0
fi
pkg create -M manifest.json -p plist -r stage -o dist/
echo "dist/$name-$version.pkg"

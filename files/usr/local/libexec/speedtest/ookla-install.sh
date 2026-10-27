#!/bin/sh
# Copyright 2026 Jeff Nelson
# Licensed under the Apache License, Version 2.0.
#
# Install the Ookla Speedtest CLI from a checksum manifest.
# Usage: ookla-install.sh <manifest.json> <freebsd_major> <dest_bin> [wanted_version]
# Exit: 0 ok, 1 usage/download error, 2 no matching build,
#       3 checksum mismatch, 4 binary not runnable.
# Test-only env: SPEEDTEST_SKIP_EXEC=1 skips running the installed binary.
set -eu

if [ $# -lt 3 ] || [ $# -gt 4 ]; then
	echo "usage: $0 <manifest.json> <freebsd_major> <dest_bin> [wanted_version]" >&2
	exit 1
fi
manifest=$1
major=$2
dest=$3
wanted=${4:-}
php=${SPEEDTEST_PHP:-/usr/local/bin/php}
lib=${SPEEDTEST_LIB:-/usr/local/pkg/speedtest_lib.inc}

tmpdir=$(mktemp -d)
trap 'rm -rf "$tmpdir"; rm -f "$dest.tmp"' EXIT

# Print one field of the selected build; empty output means no build.
build_field() {
	# shellcheck disable=SC2016 # PHP code, not shell expansion
	"$php" -r '
		require $argv[1];
		$m = json_decode(file_get_contents($argv[2]), true);
		$b = speedtest_select_build(is_array($m) ? $m : [], (int)$argv[3], $argv[4] === "" ? null : $argv[4]);
		if ($b !== null) { echo $b[$argv[5]] ?? ""; }
	' "$lib" "$manifest" "$major" "$wanted" "$1"
}

sha256_of() {
	if command -v sha256 >/dev/null 2>&1; then
		sha256 -q "$1"
	else
		shasum -a 256 "$1" | cut -d' ' -f1
	fi
}

version=$(build_field version)
if [ -z "$version" ]; then
	echo "no Ookla build for FreeBSD $major${wanted:+ version $wanted}" >&2
	exit 2
fi
build_major=$(build_field freebsd_major)
url=$(build_field url)
pkg_sha=$(build_field pkg_sha256)
pkg_size=$(build_field pkg_size)
bin_sha=$(build_field bin_sha256)

pkg="$tmpdir/ookla.pkg"
if command -v fetch >/dev/null 2>&1; then
	fetch -q -o "$pkg" "$url" || { echo "download failed: $url" >&2; exit 1; }
else
	curl -fsSL -o "$pkg" "$url" || { echo "download failed: $url" >&2; exit 1; }
fi

if [ "$(stat -f %z "$pkg")" != "$pkg_size" ] || [ "$(sha256_of "$pkg")" != "$pkg_sha" ]; then
	echo "package checksum or size mismatch" >&2
	exit 3
fi

tar -xOf "$pkg" /usr/local/bin/speedtest >"$dest.tmp" 2>/dev/null \
	|| { echo "cannot extract binary from package" >&2; exit 1; }
if [ -n "$bin_sha" ] && [ "$(sha256_of "$dest.tmp")" != "$bin_sha" ]; then
	echo "binary checksum mismatch" >&2
	exit 3
fi
chmod 555 "$dest.tmp"
mv -f "$dest.tmp" "$dest"

if [ "${SPEEDTEST_SKIP_EXEC:-}" != 1 ]; then
	if ! out=$("$dest" --accept-license --accept-gdpr --version 2>&1); then
		rm -f "$dest"
		echo "installed binary is not runnable" >&2
		exit 4
	fi
	version=$(printf '%s\n' "$out" | sed -n '1p')
fi
echo "installed $version (freebsd$build_major)"

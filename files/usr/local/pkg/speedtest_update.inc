<?php
/*
 * Copyright 2026 Jeff Nelson
 * Licensed under the Apache License, Version 2.0.
 *
 * Package self-update: list GitHub releases, notice new versions, and start
 * update.sh to install one (upgrade, reinstall or rollback). Used by the
 * Updates tab (speedtest_updates.php) and the pfsense-speedtest CLI.
 */
require_once(__DIR__ . '/speedtest.inc');
require_once('notices.inc');

const SPEEDTEST_REPO = 'jeffwnelson/pfsense-pkg-speedtest';
const SPEEDTEST_RELEASES_API = 'https://api.github.com/repos/' . SPEEDTEST_REPO . '/releases?per_page=50';
const SPEEDTEST_RELEASES_TTL_S = 3600;
const SPEEDTEST_PKG_STATIC = '/usr/local/sbin/pkg-static';
const SPEEDTEST_UPDATES_URL = '/speedtest_updates.php';

/* Body of an HTTPS GET, or null on failure. Tests replace it via $GLOBALS['speedtest_test_http']. */
function speedtest_http_get(string $url): ?string {
	if (isset($GLOBALS['speedtest_test_http'])) {
		return ($GLOBALS['speedtest_test_http'])($url);
	}
	$out = [];
	$rc = 0;
	exec('/usr/bin/fetch -q -T 20 -o - ' . escapeshellarg($url) . ' 2>/dev/null', $out, $rc);
	return $rc === 0 ? implode("\n", $out) : null;
}

/* Installed package version ("" if unknown). Tests set $GLOBALS['speedtest_test_installed']. */
function speedtest_installed_version(): string {
	if (isset($GLOBALS['speedtest_test_installed'])) {
		return (string)$GLOBALS['speedtest_test_installed'];
	}
	$out = [];
	$rc = 0;
	exec(SPEEDTEST_PKG_STATIC . ' query %v ' . escapeshellarg(SPEEDTEST_PKG_NAME) . ' 2>/dev/null', $out, $rc);
	return $rc === 0 ? trim((string)($out[0] ?? '')) : '';
}

function speedtest_releases_cache_path(): string {
	return speedtest_root() . '/releases.json';
}

/*
 * ['releases' => parsed releases (newest first), 'fetched_at' => unix time,
 * 'error' => ?string]. Served from a cache younger than an hour unless
 * $refresh; when GitHub cannot be reached the last good list is returned
 * with an error.
 */
function speedtest_releases(bool $refresh = false): array {
	$path = speedtest_releases_cache_path();
	$cached = null;
	if (is_file($path)) {
		$c = json_decode((string)@file_get_contents($path), true);
		if (is_array($c) && is_array($c['releases'] ?? null) && isset($c['fetched_at'])) {
			$cached = ['releases' => $c['releases'], 'fetched_at' => (int)$c['fetched_at']];
		}
	}
	$now = time();
	if (!$refresh && $cached !== null && $now - $cached['fetched_at'] < SPEEDTEST_RELEASES_TTL_S) {
		return $cached + ['error' => null];
	}
	$body = speedtest_http_get(SPEEDTEST_RELEASES_API);
	$list = $body === null ? null : json_decode($body, true);
	if (!is_array($list) || !array_is_list($list)) {
		$res = $cached ?? ['releases' => [], 'fetched_at' => 0];
		$res['error'] = 'Could not get the release list from GitHub.';
		return $res;
	}
	$res = ['releases' => speedtest_parse_releases($body, SPEEDTEST_REPO), 'fetched_at' => $now];
	if (speedtest_mkdir(speedtest_root())) {
		speedtest_store_atomic_write($path, json_encode($res));
	}
	return $res + ['error' => null];
}

/*
 * Refresh the release list and, when $notify, post one pfSense notice per new
 * version. Returns ['installed' => string, 'available' => ?release, 'error' => ?string].
 */
function speedtest_check_updates(bool $notify = true): array {
	$rel = speedtest_releases(true);
	$installed = speedtest_installed_version();
	$avail = $installed === '' ? null : speedtest_update_available($installed, $rel['releases']);
	if ($notify && $avail !== null) {
		$marker = speedtest_root() . '/.notified-version';
		if (trim((string)@file_get_contents($marker)) !== $avail['version']) {
			file_notice('speedtest', sprintf('Speedtest package %s is available (installed: %s). '
			    . 'Install it from Services > Speedtest > Updates.', $avail['version'], $installed),
			    'Speedtest update', SPEEDTEST_UPDATES_URL, 1);
			speedtest_store_atomic_write($marker, $avail['version']);
		}
	}
	return ['installed' => $installed, 'available' => $avail, 'error' => $rel['error']];
}

/* Expected SHA-256 of a release's package: the GitHub asset digest, else its .sha256 file. */
function speedtest_release_sha256(array $release): ?string {
	if (!empty($release['pkg_sha256']) && speedtest_valid_sha256((string)$release['pkg_sha256'])) {
		return (string)$release['pkg_sha256'];
	}
	if (!empty($release['sha_url'])) {
		$text = speedtest_http_get((string)$release['sha_url']);
		if ($text !== null) {
			return speedtest_parse_sha256_file($text, basename((string)$release['pkg_url']));
		}
	}
	return null;
}

function speedtest_update_log_path(): string {
	return speedtest_run_dir() . '/update.log';
}

/*
 * ['running' => bool, 'log' => string, 'rc' => ?int (exit status of the last
 * finished update)]. update.sh holds <run_dir>/update.lock/ while it runs.
 */
function speedtest_update_status(): array {
	$run_dir = speedtest_run_dir();
	$pid = (int)trim((string)@file_get_contents("$run_dir/update.lock/pid"));
	$rc = @file_get_contents("$run_dir/update.rc");
	$rc = ($rc === false || trim($rc) === '') ? null : (int)trim($rc);
	$running = is_dir("$run_dir/update.lock") && ($pid === 0 || speedtest_pid_alive($pid));
	/* Just started: update.sh has not taken its lock yet. */
	$log_mtime = @filemtime(speedtest_update_log_path());
	if (!$running && $rc === null && $log_mtime !== false && time() - $log_mtime < 30) {
		$running = true;
	}
	return [
		'running' => $running,
		'log' => (string)@file_get_contents(speedtest_update_log_path()),
		'rc' => $rc,
	];
}

/*
 * Resolve $version to a release and its checksum. Returns ['ok' => true,
 * 'args' => update.sh arguments] or ['ok' => false, 'error' => string].
 */
function speedtest_update_args(string $version): array {
	if (!speedtest_valid_version($version)) {
		return ['ok' => false, 'error' => 'Invalid version.'];
	}
	$rel = speedtest_find_release(speedtest_releases()['releases'], $version);
	if ($rel === null) {
		$rel = speedtest_find_release(speedtest_releases(true)['releases'], $version);
	}
	if ($rel === null) {
		return ['ok' => false, 'error' => "No release $version with a package was found."];
	}
	$sha = speedtest_release_sha256($rel);
	if ($sha === null) {
		return ['ok' => false, 'error' => "Release $version has no checksum; not installing it."];
	}
	return ['ok' => true, 'args' => [speedtest_libexec() . '/update.sh', $version, $rel['pkg_url'], $sha]];
}

/* Start a detached update to $version. Returns ['ok' => bool, 'error' => ?string]. */
function speedtest_update_start(string $version): array {
	if (speedtest_update_status()['running']) {
		return ['ok' => false, 'error' => 'An update is already running.'];
	}
	$res = speedtest_update_args($version);
	if (!$res['ok']) {
		return $res;
	}
	if (!speedtest_mkdir(speedtest_run_dir())) {
		return ['ok' => false, 'error' => 'Cannot create ' . speedtest_run_dir() . '.'];
	}
	@unlink(speedtest_run_dir() . '/update.rc');
	@file_put_contents(speedtest_update_log_path(), "Starting update to $version...\n");
	mwexec_bg('/usr/bin/env SPEEDTEST_UPDATE_LOG=' . escapeshellarg(speedtest_update_log_path())
	    . ' /bin/sh ' . implode(' ', array_map('escapeshellarg', $res['args'])));
	return ['ok' => true, 'error' => null];
}

/* Entry point for /usr/local/bin/pfsense-speedtest. Returns the exit code. */
function speedtest_cli_main(array $argv): int {
	$cmd = (string)($argv[1] ?? '');
	$usage = "usage: pfsense-speedtest version | check [--cron] | list | update [version]\n";
	switch ($cmd) {
	case 'version':
		echo (speedtest_installed_version() ?: 'unknown'), "\n";
		return 0;
	case 'check':
		$cron = ($argv[2] ?? '') === '--cron';
		$res = speedtest_check_updates($cron);
		if ($cron) {
			if ($res['error'] !== null) {
				log_error('speedtest: update check failed: ' . $res['error']);
			}
			return 0;
		}
		if ($res['error'] !== null) {
			fwrite(STDERR, $res['error'] . "\n");
			return 1;
		}
		echo 'installed: ', ($res['installed'] ?: 'unknown'), "\n";
		echo $res['available'] ? "update available: {$res['available']['version']}\n" : "up to date\n";
		return 0;
	case 'list':
		$rel = speedtest_releases(true);
		if ($rel['error'] !== null) {
			fwrite(STDERR, $rel['error'] . "\n");
		}
		$installed = speedtest_installed_version();
		foreach ($rel['releases'] as $r) {
			echo $r['version'], "\t", substr($r['published'], 0, 10), ($r['version'] === $installed ? "\t(installed)" : ''), "\n";
		}
		return $rel['releases'] ? 0 : 1;
	case 'update':
		$version = (string)($argv[2] ?? '');
		if ($version === '') {
			$res = speedtest_check_updates(false);
			if ($res['available'] === null) {
				echo $res['error'] ?? 'Already up to date (' . ($res['installed'] ?: 'unknown') . ').', "\n";
				return $res['error'] === null ? 0 : 1;
			}
			$version = $res['available']['version'];
		}
		$res = speedtest_update_args($version);
		if (!$res['ok']) {
			fwrite(STDERR, $res['error'] . "\n");
			return 1;
		}
		$rc = 0;
		passthru('/bin/sh ' . implode(' ', array_map('escapeshellarg', $res['args'])), $rc);
		return $rc;
	default:
		fwrite(STDERR, $usage);
		return $cmd === '' || $cmd === 'help' ? 0 : 1;
	}
}

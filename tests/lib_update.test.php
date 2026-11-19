<?php
/*
 * Copyright 2026 Jeff Nelson
 * Licensed under the Apache License, Version 2.0.
 *
 * Pure self-update helpers in speedtest_lib.inc.
 */
require __DIR__ . '/assert.inc';
require __DIR__ . '/../files/usr/local/pkg/speedtest_lib.inc';

$repo = 'jeffwnelson/pfsense-pkg-speedtest';
$dl = "https://github.com/$repo/releases/download";
$json = file_get_contents(__DIR__ . '/fixtures/github_releases.json');
$rel = speedtest_parse_releases($json, $repo);

assert_eq(['0.10.0', '0.2.2', '0.2.1'], array_column($rel, 'version'),
    'releases: stable vX.Y.Z with a package asset from this repo, newest first (0.10 > 0.2)');
assert_eq('v0.10.0', $rel[0]['tag'], 'tag kept');
assert_eq("$dl/v0.10.0/pfSense-pkg-speedtest-0.10.0.pkg", $rel[0]['pkg_url'], 'package URL');
assert_eq('7eb558a5f133b662c670c8ef747bb6dba6c4071fc347886c62ae20c86e9bd7ce', $rel[0]['pkg_sha256'], 'asset digest used');
assert_eq("$dl/v0.10.0/pfSense-pkg-speedtest-0.10.0.pkg.sha256", $rel[0]['sha_url'], 'sha256 asset URL');
assert_null($rel[1]['pkg_sha256'], 'digest of the .sha256 asset is not the package digest');
assert_eq("$dl/v0.2.2/pfSense-pkg-speedtest-0.2.2.pkg.sha256", $rel[1]['sha_url'], 'sha256 asset found regardless of order');
assert_null($rel[2]['pkg_sha256'], 'malformed digest ignored');
assert_null($rel[2]['sha_url'], 'no sha256 asset -> null');
assert_eq('2026-11-18T23:37:17Z', $rel[1]['published'], 'published date');
assert_eq([], speedtest_parse_releases('{"message":"API rate limit exceeded"}', $repo), 'API error object -> none');
assert_eq([], speedtest_parse_releases('not json', $repo), 'garbage -> none');
assert_eq([], speedtest_parse_releases($json, 'someone/else'), 'assets must belong to the configured repo');

assert_eq('0.10.0', speedtest_update_available('0.2.2', $rel)['version'], 'update available');
assert_null(speedtest_update_available('0.10.0', $rel), 'latest installed -> none');
assert_null(speedtest_update_available('0.11.0', $rel), 'newer than any release -> none');
assert_eq('0.10.0', speedtest_update_available('0.0.0-dev.abc1234', $rel)['version'], 'dev build -> latest');
assert_null(speedtest_update_available('0.2.2', []), 'no releases -> none');
assert_eq('0.2.1', speedtest_find_release($rel, '0.2.1')['version'], 'find release');
assert_null(speedtest_find_release($rel, '0.9.0'), 'release without package not found');

assert_true(speedtest_valid_version('1.22.333'), 'valid version');
foreach (['', '1.2', '1.2.3.4', 'v1.2.3', '1.2.3-rc1', "1.2.3\n", '1.2.3; rm -rf /'] as $bad) {
	assert_true(!speedtest_valid_version($bad), 'invalid version ' . json_encode($bad));
}
assert_true(speedtest_valid_sha256(str_repeat('a', 64)), 'valid sha256');
assert_true(!speedtest_valid_sha256(str_repeat('A', 64)), 'uppercase sha256 rejected (normalised elsewhere)');
assert_true(!speedtest_valid_sha256(str_repeat('a', 63)), 'short sha256 rejected');

$h = str_repeat('ab', 32);
assert_eq($h, speedtest_parse_sha256_file("$h pfSense-pkg-speedtest-1.0.0.pkg\n", 'pfSense-pkg-speedtest-1.0.0.pkg'), 'sha256 -r format');
assert_eq($h, speedtest_parse_sha256_file(strtoupper($h) . "  *dist/pfSense-pkg-speedtest-1.0.0.pkg", 'pfSense-pkg-speedtest-1.0.0.pkg'), 'sha256sum binary format, path, uppercase');
assert_null(speedtest_parse_sha256_file("$h other.pkg\n", 'pfSense-pkg-speedtest-1.0.0.pkg'), 'other file name -> null');
assert_null(speedtest_parse_sha256_file("nothing here", 'x.pkg'), 'garbage -> null');

$html = speedtest_render_notes($rel[0]['notes']);
assert_true(strpos($html, '<p><strong>Features</strong></p>') !== false, 'notes: heading');
assert_true(strpos($html, '<ul><li>(<strong>widget</strong>) show &lt;b&gt;bold&lt;/b&gt;') !== false, 'notes: list, bold, HTML escaped');
assert_true(strpos($html, '<a href="https://github.com/jeffwnelson/pfsense-pkg-speedtest/commit/abc1234" target="_blank" rel="noopener noreferrer">abc1234</a>') !== false, 'notes: https link');
assert_true(strpos($html, 'javascript:') === false || strpos($html, 'href="javascript:') === false, 'notes: javascript: not linked');
assert_true(strpos($html, '<script') === false, 'notes: no raw tags');
assert_eq('<p>x &quot;y&quot; <code>z</code></p>', speedtest_render_notes("x \"y\" `z`"), 'notes: quotes escaped, code');
assert_eq('<p>[a](http://plain.example)</p>', speedtest_render_notes('[a](http://plain.example)'), 'notes: http link left as text');
assert_eq('', speedtest_render_notes(''), 'notes: empty');

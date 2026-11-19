<?php
/*
 * speedtest_updates.php
 *
 * part of pfSense-pkg-speedtest
 * Copyright 2026 Jeff Nelson
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 * http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

/* Access is the page-services-speedtest-updates privilege (/etc/inc/priv/speedtest.priv.inc). */

require_once("guiconfig.inc");
require_once("/usr/local/pkg/speedtest_update.inc");

$self = '/speedtest_updates.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	if (isset($_POST['check'])) {
		$res = speedtest_check_updates(false);
		$msg = $res['error'] ?? ($res['available'] ? sprintf(gettext('Version %s is available.'), $res['available']['version'])
		    : gettext('The package is up to date.'));
	} elseif (isset($_POST['install'])) {
		$res = speedtest_update_start((string)($_POST['version'] ?? ''));
		$msg = $res['ok'] ? gettext('Update started.') : $res['error'];
	} else {
		$msg = '';
	}
	header('Location: ' . $self . ($msg !== '' ? '?savemsg=' . urlencode($msg) : ''));
	exit;
}

$installed = speedtest_installed_version();
$rel = speedtest_releases();
$releases = $rel['releases'];
$latest = $releases[0] ?? null;
$avail = $installed === '' ? null : speedtest_update_available($installed, $releases);
$status = speedtest_update_status();

$pgtitle = [gettext("Services"), gettext("Speedtest"), gettext("Updates")];
$pglinks = ["", "/pkg_edit.php?xml=speedtest.xml", "@self"];
include("head.inc");

if (!empty($_GET['savemsg'])) {
	print_info_box(htmlspecialchars((string)$_GET['savemsg']), 'info');
}
if ($rel['error'] !== null) {
	print_info_box(htmlspecialchars($rel['error']), 'warning');
}

$tab_array = [
	[gettext("General"), false, "/pkg_edit.php?xml=speedtest.xml"],
	[gettext("Gateways"), false, "/pkg.php?xml=speedtestgateways.xml"],
	[gettext("Updates"), true, $self],
];
display_top_tabs($tab_array);

/* Notes to show: every release newer than the installed one, else the latest. */
$notes = [];
foreach ($releases as $r) {
	if ($installed !== '' && version_compare($r['version'], $installed, '<=')) {
		break;
	}
	$notes[] = $r;
}
if (!$notes && $latest !== null) {
	$notes[] = $latest;
}

$h = function ($v): string { return htmlspecialchars((string)$v); };

$form = new Form(false);

$section = new Form_Section(gettext('Version'));
$section->addInput(new Form_StaticText(gettext('Installed version'), $h($installed ?: gettext('unknown'))))
    ->setHelp(gettext('The Speedtest package version installed on this system.'));
$badge = '';
if ($avail !== null) {
	$badge = ' <span class="label label-success">' . gettext('Update available') . '</span>';
} elseif ($latest !== null && $installed !== '') {
	$badge = ' <span class="label label-default">' . gettext('Up to date') . '</span>';
}
$section->addInput(new Form_StaticText(gettext('Latest version'), $h($latest['version'] ?? gettext('unknown')) . $badge))
    ->setHelp(gettext('The newest release published on GitHub.'));
$section->addInput(new Form_StaticText(gettext('Release date'),
    $h($latest !== null && $latest['published'] !== '' ? substr($latest['published'], 0, 10) : gettext('unknown'))))
    ->setHelp(gettext("The latest release's publish date."));
$section->addInput(new Form_StaticText(gettext('Last checked'),
    $h($rel['fetched_at'] ? date('Y-m-d H:i', $rel['fetched_at']) : gettext('never'))))
    ->setHelp(gettext('Checked daily in the background (unless disabled on the General tab) and at most hourly when this page is opened.'));
$form->add($section);

if ($releases) {
	$options = [];
	foreach ($releases as $i => $r) {
		$tags = [];
		if ($i === 0) {
			$tags[] = gettext('latest');
		}
		if ($r['version'] === $installed) {
			$tags[] = gettext('installed');
		}
		$options[$r['version']] = $r['version'] . ($tags ? ' (' . implode(', ', $tags) . ')' : '')
		    . ($r['published'] !== '' ? ' - ' . substr($r['published'], 0, 10) : '');
	}
	$selected = $avail !== null ? $avail['version'] : ($installed !== '' && isset($options[$installed]) ? $installed : array_key_first($options));
	$section = new Form_Section(gettext('Install a different version'));
	$select = $section->addInput(new Form_Select('version', gettext('Install version'), $selected, $options))
	    ->setHelp(gettext('Set the version to update or roll back to. The release is downloaded from GitHub, its SHA-256 '
	    . 'is checked and it is installed with pkg. Settings, gateways and stored results are kept.'));
	if ($status['running']) {
		$select->setDisabled();
	}
	$form->add($section);
}

if ($releases) {
	$install = new Form_Button('install', gettext('Install'), null, 'fa-solid fa-download');
	$install->addClass('btn-primary');
	if ($status['running']) {
		$install->setDisabled();
	}
	$form->addGlobal($install);
}
$check = new Form_Button('check', gettext('Check now'), null, 'fa-solid fa-search');
$check->addClass('btn-default');
$form->addGlobal($check);

print($form);

if ($status['log'] !== '') {
	if ($status['running']) {
		$title = '<i class="fa-solid fa-spinner fa-spin"></i> ' . gettext('Update in progress');
	} elseif ($status['rc'] === 0) {
		$title = gettext('Last update: succeeded');
	} else {
		$title = gettext('Last update: failed') . ($status['rc'] !== null ? ' (' . (int)$status['rc'] . ')' : '');
	}
?>
<div class="panel panel-default">
	<div class="panel-heading"><h2 class="panel-title"><?=$title?></h2></div>
	<div class="panel-body" style="padding: 10px 15px;"><pre style="margin: 0;"><?=$h($status['log'])?></pre></div>
</div>
<?php
}

foreach ($notes as $r):
?>
<div class="panel panel-default">
	<div class="panel-heading"><h2 class="panel-title"><?=sprintf(gettext("What's new in %s"), $h($r['version']))?></h2></div>
	<div class="panel-body speedtest-notes" style="padding: 10px 15px;"><?=speedtest_render_notes($r['notes']) ?: gettext('No release notes.')?></div>
</div>
<?php endforeach; ?>

<script type="text/javascript">
//<![CDATA[
events.push(function() {
	$('button[name=install]').on('click', function() {
		var v = $('select[name=version]').val();
		return confirm('Install pfSense-pkg-speedtest ' + v + '? The package will be reinstalled; settings and results are kept.');
	});
<?php if ($status['running']): ?>
	setTimeout(function() { window.location.replace('<?=$self?>'); }, 3000);
<?php endif; ?>
});
//]]>
</script>
<?php include("foot.inc"); ?>

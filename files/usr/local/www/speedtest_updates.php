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
?>
<div class="panel panel-default">
	<div class="panel-heading"><h2 class="panel-title"><?=gettext("Package version")?></h2></div>
	<div class="panel-body">
		<dl class="dl-horizontal">
			<dt><?=gettext("Installed")?></dt>
			<dd><?=htmlspecialchars($installed ?: gettext('unknown'))?></dd>
			<dt><?=gettext("Latest release")?></dt>
			<dd>
				<?=htmlspecialchars($latest['version'] ?? gettext('unknown'))?>
<?php if ($avail !== null): ?>
				<span class="label label-success"><?=gettext("Update available")?></span>
<?php elseif ($latest !== null && $installed !== ''): ?>
				<span class="label label-default"><?=gettext("Up to date")?></span>
<?php endif; ?>
			</dd>
			<dt><?=gettext("Last checked")?></dt>
			<dd><?=$rel['fetched_at'] ? htmlspecialchars(date('Y-m-d H:i', $rel['fetched_at'])) : gettext('never')?></dd>
		</dl>
		<form method="post" action="<?=$self?>">
			<button type="submit" name="check" value="1" class="btn btn-sm btn-default">
				<i class="fa-solid fa-arrows-rotate icon-embed-btn"></i><?=gettext("Check now")?>
			</button>
		</form>
	</div>
</div>

<div class="panel panel-default">
	<div class="panel-heading"><h2 class="panel-title"><?=gettext("Install a version")?></h2></div>
	<div class="panel-body">
<?php if (!$releases): ?>
		<p><?=gettext("No releases are known yet. Use Check now.")?></p>
<?php else: ?>
		<form method="post" action="<?=$self?>" class="form-inline" id="speedtest-install">
			<select name="version" class="form-control"<?=$status['running'] ? ' disabled' : ''?>>
<?php foreach ($releases as $i => $r):
	$tags = [];
	if ($i === 0) {
		$tags[] = gettext('latest');
	}
	if ($r['version'] === $installed) {
		$tags[] = gettext('installed');
	}
	$label = $r['version'] . ($tags ? ' (' . implode(', ', $tags) . ')' : '') . ' - ' . substr($r['published'], 0, 10);
?>
				<option value="<?=htmlspecialchars($r['version'])?>"<?=($avail !== null ? $i === 0 : $r['version'] === $installed) ? ' selected' : ''?>><?=htmlspecialchars($label)?></option>
<?php endforeach; ?>
			</select>
			<button type="submit" name="install" value="1" class="btn btn-primary"<?=$status['running'] ? ' disabled' : ''?>>
				<i class="fa-solid fa-download icon-embed-btn"></i><?=gettext("Install")?>
			</button>
		</form>
		<p class="help-block"><?=gettext("Downloads the release from GitHub, checks its SHA-256 and installs it with pkg. "
		    . "Choosing an older version rolls back. Settings, gateways and stored results are kept.")?></p>
<?php endif; ?>
	</div>
</div>

<?php if ($status['log'] !== ''): ?>
<div class="panel panel-default">
	<div class="panel-heading"><h2 class="panel-title">
<?php if ($status['running']): ?>
		<i class="fa-solid fa-spinner fa-spin"></i> <?=gettext("Update in progress")?>
<?php elseif ($status['rc'] === 0): ?>
		<?=gettext("Last update: succeeded")?>
<?php else: ?>
		<?=gettext("Last update: failed")?><?=$status['rc'] !== null ? ' (' . (int)$status['rc'] . ')' : ''?>
<?php endif; ?>
	</h2></div>
	<div class="panel-body"><pre><?=htmlspecialchars($status['log'])?></pre></div>
</div>
<?php endif; ?>

<?php foreach ($notes as $r): ?>
<div class="panel panel-default">
	<div class="panel-heading"><h2 class="panel-title"><?=sprintf(gettext("What's new in %s"), htmlspecialchars($r['version']))?></h2></div>
	<div class="panel-body"><?=speedtest_render_notes($r['notes']) ?: gettext('No release notes.')?></div>
</div>
<?php endforeach; ?>

<script type="text/javascript">
//<![CDATA[
events.push(function() {
	$('#speedtest-install').on('submit', function() {
		var v = $(this).find('select[name=version]').val();
		return confirm('Install pfSense-pkg-speedtest ' + v + '? The package will be reinstalled; settings and results are kept.');
	});
<?php if ($status['running']): ?>
	setTimeout(function() { window.location.replace('<?=$self?>'); }, 3000);
<?php endif; ?>
});
//]]>
</script>
<?php include("foot.inc"); ?>

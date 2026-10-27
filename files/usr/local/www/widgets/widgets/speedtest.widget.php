<?php
/*
 * speedtest.widget.php
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

require_once("guiconfig.inc");
require_once("pfsense-utils.inc");
require_once("functions.inc");
require_once("/usr/local/www/widgets/include/speedtest.inc");
require_once("/usr/local/pkg/speedtest.inc");

/*
 * Validate the "widgetkey" value.
 * When this widget is present on the Dashboard, $widgetkey is defined before
 * the Dashboard includes the widget. During other types of requests, such as
 * saving settings or AJAX, the value may be set via $_POST or similar.
 */
if (($_POST['widgetkey'] ?? null) || ($_GET['widgetkey'] ?? null)) {
	$rwidgetkey = $_POST['widgetkey'] ?? $_GET['widgetkey'] ?? null;
	if (is_valid_widgetkey($rwidgetkey, $user_settings, __FILE__)) {
		$widgetkey = $rwidgetkey;
	} else {
		print gettext("Invalid Widget Key");
		exit;
	}
}

if (!function_exists('speedtest_widget_rows')) {
	/* $status is speedtest_status(), computed once per request by the caller. */
	function speedtest_widget_rows($widgetkey, array $status) {
		global $user_settings;

		$wcfg = $user_settings["widgets"][$widgetkey] ?? [];
		$hidden = explode(",", (string)($wcfg["gatewaysfilter"] ?? ''));
		[$hide_ping, $hide_lastrun] = speedtest_widget_hide_flags(is_array($wcfg) ? $wcfg : []);

		return speedtest_render_rows(speedtest_configured_gateways(), $status['results'], $status['running'],
		    $hidden, $hide_ping, $hide_lastrun, (string)$widgetkey, $status['enabled']);
	}
}

if (!function_exists('speedtest_widget_json')) {
	function speedtest_widget_json($data, $code = 200) {
		http_response_code($code);
		header("Content-Type: application/json");
		print(json_encode($data));
		exit;
	}
}

// Rows for the heading-icon reload and first render
if ($_REQUEST['ajax'] ?? null) {
	print(speedtest_widget_rows($_REQUEST['widgetkey'] ?? null, speedtest_status()));
	exit;
}

// Poller: status plus the rows to swap in
if (($_GET['act'] ?? null) === 'status') {
	$status = speedtest_status();
	$status['rows_html'] = speedtest_widget_rows($_GET['widgetkey'] ?? null, $status);
	speedtest_widget_json($status);
}

// Start a run; speedtest_start() validates the gateway name
if (($_POST['act'] ?? null) === 'start') {
	$res = speedtest_start((string)($_POST['gateway'] ?? ''));
	$codes = ['busy' => 409, 'disabled' => 409, 'unknown gateway' => 400, 'ookla missing' => 503];
	speedtest_widget_json($res, $res['ok'] ? 200 : ($codes[$res['error']] ?? 400));
}

if ($_POST['widgetkey'] ?? null) {
	set_customwidgettitle($user_settings);

	if (!is_array($user_settings["widgets"][$_POST['widgetkey']] ?? null)) {
		$user_settings["widgets"][$_POST['widgetkey']] = array();
	}

	$validNames = array_keys(speedtest_configured_gateways());
	if (is_array($_POST['show'] ?? null)) {
		$user_settings["widgets"][$_POST['widgetkey']]["gatewaysfilter"] = implode(',', array_diff($validNames, $_POST['show']));
	} else {
		$user_settings["widgets"][$_POST['widgetkey']]["gatewaysfilter"] = implode(',', $validNames);
	}
	$user_settings["widgets"][$_POST['widgetkey']]["hide_ping"] = !empty($_POST['hide_ping']) ? 1 : 0;
	$user_settings["widgets"][$_POST['widgetkey']]["hide_lastrun"] = !empty($_POST['hide_lastrun']) ? 1 : 0;
	unset($user_settings["widgets"][$_POST['widgetkey']]["compact"]);

	save_widget_settings($_SESSION['Username'], $user_settings["widgets"], gettext("Updated speedtest widget settings via dashboard."));
	header("Location: /");
	exit(0);
}

$wcfg = $user_settings["widgets"][$widgetkey] ?? [];
[$hide_ping, $hide_lastrun] = speedtest_widget_hide_flags(is_array($wcfg) ? $wcfg : []);
$status = speedtest_status();
$is_running = $status['running'] !== null;

?>

<div class="table-responsive">
	<table class="table table-striped table-hover table-condensed">
		<thead>
			<tr>
				<th><?=gettext("WAN")?></th>
				<th><?=gettext("Download")?></th>
				<th><?=gettext("Upload")?></th>
<?php if (!$hide_ping): ?>
				<th><?=gettext("Ping")?></th>
<?php endif; ?>
<?php if (!$hide_lastrun): ?>
				<th><?=gettext("Last run")?></th>
<?php endif; ?>
				<th></th>
			</tr>
		</thead>
		<tbody id="<?=htmlspecialchars($widgetkey)?>-sttblbody">
<?php
		print(speedtest_widget_rows($widgetkey, $status));
?>
		</tbody>
	</table>
	<small class="text-muted" id="<?=htmlspecialchars($widgetkey)?>-status"></small>
</div>
<!-- close the body we're wrapped in and add a configuration-panel -->
</div><div id="<?=$widget_panel_footer_id?>" class="panel-footer collapse">
<form action="/widgets/widgets/speedtest.widget.php" method="post" class="form-horizontal">
	<?=gen_customwidgettitle_div($widgetconfig['title']); ?>
	<input type="hidden" name="widgetkey" value="<?=htmlspecialchars($widgetkey); ?>">
	<div class="panel panel-default col-sm-10">
		<div class="panel-body">
			<div class="table responsive">
				<table class="table table-striped table-hover table-condensed">
					<thead>
						<tr>
							<th><?=gettext("Gateway")?></th>
							<th><?=gettext("Show")?></th>
						</tr>
					</thead>
					<tbody>
<?php
				$hiddengateways = explode(",", (string)($wcfg["gatewaysfilter"] ?? ''));
				foreach (speedtest_configured_gateways() as $gname => $gateway):
?>
						<tr>
							<td><?=htmlspecialchars($gname)?></td>
							<td class="col-sm-2"><input id="show[]" name="show[]" value="<?=htmlspecialchars($gname)?>" type="checkbox" <?=(!in_array($gname, $hiddengateways) ? 'checked':'')?>></td>
						</tr>
<?php
				endforeach;
?>
					</tbody>
				</table>
			</div>
			<div class="checkbox">
				<label><input name="hide_ping" type="checkbox" value="1" <?=($hide_ping ? 'checked' : '')?>> <?=gettext('Hide Ping')?></label>
			</div>
			<div class="checkbox">
				<label><input name="hide_lastrun" type="checkbox" value="1" <?=($hide_lastrun ? 'checked' : '')?>> <?=gettext('Hide Last run')?></label>
			</div>
		</div>
	</div>

	<div class="form-group">
		<div class="col-sm-offset-3 col-sm-6">
			<button type="submit" class="btn btn-primary"><i class="fa-solid fa-save icon-embed-btn"></i><?=gettext('Save')?></button>
			<button id="<?=$widget_showallnone_id?>" type="button" class="btn btn-info"><i class="fa-solid fa-undo icon-embed-btn"></i><?=gettext('All')?></button>
		</div>
	</div>
</form>

<script>
//<![CDATA[

events.push(function(){
	var widgetkey = <?=json_encode($widgetkey)?>;
	var url = "/widgets/widgets/speedtest.widget.php";
	var $body = $(<?=json_encode('#' . $widgetkey . '-sttblbody')?>);
	var $status = $(<?=json_encode('#' . $widgetkey . '-status')?>);
	var timer = null;
	var inflight = false;
	var polls = 0;
	var seen = false;
	var MAX_POLLS = 75; // 2 s apart: give up after 150 s (runner hard limit is 90 s)

	function setStatus(text, danger) {
		$status.text(text).toggleClass('text-danger', !!danger).toggleClass('text-muted', !danger);
	}

	function swapRows(html) {
		// keep expanded detail rows open across the refresh
		var open = [];
		$body.find('tr.speedtest-detail').not('.hidden').each(function() {
			open.push($(this).attr('data-gw'));
		});
		$body.html(html);
		$body.find('tr.speedtest-detail').each(function() {
			var gw = $(this).attr('data-gw');
			if (open.indexOf(gw) !== -1) {
				$(this).removeClass('hidden');
				$body.find('tr[data-gw]').not('.speedtest-detail').filter(function() { return $(this).attr('data-gw') === gw; })
					.find('.speedtest-toggle').removeClass('fa-chevron-right').addClass('fa-chevron-down');
			}
		});
	}

	// Idle refresh: pick up scheduled runs. Never active while a run is polled.
	var idleTimer = null;
	var IDLE_MS = 60000;

	function refreshRows() {
		if (timer !== null) {
			return; // a run is being polled
		}
		$.get(url, {ajax: "ajax", widgetkey: widgetkey}, function(html) {
			if (timer === null) { // a run may have started meanwhile
				swapRows(html);
				if ($body.find('.fa-spin').length) {
					startPolling(); // a scheduled run is in progress: let the poll take over
				}
			}
		});
	}

	function startIdle() {
		if (idleTimer === null) {
			idleTimer = setInterval(refreshRows, IDLE_MS);
		}
	}

	function stopIdle() {
		if (idleTimer !== null) {
			clearInterval(idleTimer);
			idleTimer = null;
		}
	}

	function stopPolling() {
		if (timer !== null) {
			clearInterval(timer);
			timer = null;
		}
		startIdle();
	}

	// Re-enable Run buttons the server would not have disabled (package Enable is off -> speedtest-off).
	function enableButtons() {
		$body.find('a.speedtest-run').not('.speedtest-off').removeClass('disabled')
			.removeAttr('disabled').removeAttr('aria-disabled')
			.find('i').removeClass('fa-spinner fa-spin').addClass('fa-play');
	}

	function poll() {
		if (inflight) {
			return;
		}
		if (polls >= MAX_POLLS) {
			stopPolling();
			enableButtons();
			setStatus(<?=json_encode(gettext('Timed out waiting for result.'))?>, true);
			return;
		}
		inflight = true;
		polls++;
		$.ajax({
			type: "GET",
			url: url,
			data: {act: "status", widgetkey: widgetkey},
			dataType: "json"
		}).done(function(s) {
			swapRows(s.rows_html);
			if (s.running === null) {
				if (seen || polls >= 3) {
					stopPolling();
					setStatus("", false);
				} else {
					setStatus(<?=json_encode(gettext('Starting…'))?>, false);
				}
			} else {
				seen = true;
				setStatus(<?=json_encode(gettext('Running on '))?> + s.running.gateway + " (" + (s.running.elapsed_s || 0) + " s)", false);
			}
		}).always(function() {
			inflight = false;
		}).fail(function() {
			stopPolling();
			setStatus(<?=json_encode(gettext('Could not read speedtest status.'))?>, true);
		});
	}

	function startPolling() {
		stopIdle();
		if (timer === null) {
			polls = 0;
			seen = false;
			timer = setInterval(poll, 2000);
		}
	}

	// Run button (rows are replaced on every refresh, so delegate)
	$body.on('click', 'a.speedtest-run', function(e) {
		e.preventDefault();
		var $btn = $(this);
		if ($btn.attr('disabled')) {
			return;
		}
		var gw = $btn.attr('data-gw');
		$body.find('a.speedtest-run').addClass('disabled').attr('disabled', 'disabled');
		$btn.find('i').removeClass('fa-play').addClass('fa-spinner fa-spin');
		setStatus("", false);

		$.ajax({
			type: "POST",
			url: url,
			data: {act: "start", gateway: gw, widgetkey: widgetkey},
			dataType: "json"
		}).done(function() {
			// running.json may lag the spawn; poll() tolerates a few empty states
			startPolling();
		}).fail(function(xhr) {
			var r = xhr.responseJSON || {};
			if (xhr.status === 409 && r.error === "disabled") {
				setStatus(<?=json_encode(gettext('Speedtests are disabled in Services → Speedtest'))?>, true);
				refreshRows();
				return;
			}
			if (xhr.status === 409) {
				setStatus(<?=json_encode(gettext('A test is already running on '))?> + (r.gateway || "?"), true);
				startPolling();
				poll();
				return;
			}
			setStatus(<?=json_encode(gettext('Could not start test: '))?> + (r.error || xhr.status), true);
			refreshRows();
		});
	});

	// Expand/collapse the detail row
	$body.on('click', '.speedtest-toggle', function() {
		var gw = $(this).closest('tr').attr('data-gw');
		var $detail = $body.find('tr.speedtest-detail').filter(function() { return $(this).attr('data-gw') === gw; });
		$detail.toggleClass('hidden');
		$(this).toggleClass('fa-chevron-right fa-chevron-down');
	});

	// Reload icon in the widget heading
	var $reload = $('<a data-toggle="tooltip" title="' + <?=json_encode(gettext('Reload'))?> + '"><i class="fa-solid fa-rotate"></i></a>');
	$body.closest('.panel').find('.widget-heading-icon').first().prepend($reload);
	$reload.on('click', function(e) {
		e.preventDefault();
		refreshRows();
	});

	// A test may already be in progress when the dashboard loads
	if (<?=json_encode($is_running)?>) {
		startPolling();
		poll();
	} else {
		startIdle();
	}
});

//]]>
</script>

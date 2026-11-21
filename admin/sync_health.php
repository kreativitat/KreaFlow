<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License,
 * or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

// Load Dolibarr environment
$res = 0;
if (!$res && file_exists("../../main.inc.php")) {
	$res = include '../../main.inc.php';
}
if (!$res && file_exists("../../../main.inc.php")) {
	$res = include '../../../main.inc.php';
}
if (!$res) {
	die("Main include failed");
}

require_once DOL_DOCUMENT_ROOT . '/core/lib/admin.lib.php';
require_once '../lib/dolizsynch.lib.php';
require_once '../class/zssyncmonitor.class.php';

// Translations
$langs->loadLangs(array("admin", "dolizsynch@kreaflow"));

// Access control
if (!$user->admin) {
	accessforbidden();
}
if (!ZSSyncMonitor::FEATURE_ENABLED) {
	accessforbidden($langs->trans('FeatureDisabled'));
}

// Parameters
$action = GETPOST('action', 'aZ09');

$monitor = new ZSSyncMonitor($db);

if ($action === 'testNotification') {
	$sent = $monitor->sendTestNotification(true);
	if ($sent) {
		setEventMessages($langs->trans("SyncHealthTestSuccess"), null, 'mesgs');
	} else {
		setEventMessages($langs->trans("SyncHealthTestFailed"), null, 'errors');
	}

	header("Location: " . $_SERVER["PHP_SELF"]);
	exit;
}

$windowHours = getDolGlobalInt('DOLIZSYNCH_DASHBOARD_WINDOW_HOURS', 24);
$trendDays = getDolGlobalInt('DOLIZSYNCH_DASHBOARD_TREND_DAYS', 14);

$summary = $monitor->getInvoiceHealthSummary($windowHours);
$trend = $monitor->getInvoiceErrorTrend($trendDays);
$topErrors = $monitor->getTopInvoiceErrors($windowHours, 10);
$productSummary = $monitor->getProductHealthSummary($windowHours);
$punchclockSummary = $monitor->getPunchclockHealthSummary($windowHours);
$apiSummary = $monitor->getApiHealthSummary($windowHours);
$topProductErrors = $monitor->getTopProductErrors($windowHours, 10);
$topApiEvents = $monitor->getTopApiEvents($windowHours, 10);

// Show most recent dates first
$trend = array_reverse($trend);

$page_name = "SyncHealthDashboard";
llxHeader('', $langs->trans($page_name));

$linkback = '<a href="' . DOL_URL_ROOT . '/admin/modules.php?restore_lastsearch_values=1">' . $langs->trans("BackToModuleList") . '</a>';
print load_fiche_titre($langs->trans($page_name), $linkback, 'title_setup');

$head = dolizsynchAdminPrepareHead();
print dol_get_fiche_head($head, 'sync_health', $langs->trans("Module156558Name"), -1, 'dolizsynch@kreaflow');

print info_admin($langs->trans("SyncHealthInfo"));

print '<style>';
print '.dz-health-grid{display:flex;flex-wrap:wrap;gap:12px;margin-bottom:16px;}';
print '.dz-health-card{flex:1 1 180px;min-width:160px;border:1px solid #e5e5e5;background:#f8f8f8;border-radius:6px;padding:10px 12px;}';
print '.dz-health-label{font-size:11px;color:#666;text-transform:uppercase;letter-spacing:0.4px;}';
print '.dz-health-value{font-size:20px;font-weight:bold;margin-top:4px;}';
print '.dz-health-bar{display:inline-block;height:8px;background:#d9534f;border-radius:4px;}';
print '</style>';

$metrics = array(
	array('label' => $langs->trans("SyncHealthTotal"), 'value' => number_format($summary['total'])),
	array('label' => $langs->trans("SyncHealthProcessed"), 'value' => number_format($summary['processed_total'])),
	array('label' => $langs->trans("SyncHealthSuccessRate"), 'value' => number_format($summary['success_rate'], 2) . '%'),
	array('label' => $langs->trans("SyncHealthAverageLatency"), 'value' => $monitor->formatDuration($summary['avg_latency_seconds'])),
	array('label' => $langs->trans("SyncHealthErrors"), 'value' => number_format($summary['error_count'])),
	array('label' => $langs->trans("SyncHealthPending"), 'value' => number_format($summary['pending_count'])),
	array('label' => $langs->trans("SyncHealthProcessing"), 'value' => number_format($summary['processing_count'])),
	array('label' => $langs->trans("SyncHealthP95Latency"), 'value' => $monitor->formatDuration($summary['p95_latency_seconds'])),
	array('label' => $langs->trans("SyncHealthMaxLatency"), 'value' => $monitor->formatDuration($summary['max_latency_seconds'])),
);

print '<div><strong>' . $langs->trans("SyncHealthWindowLabel") . ':</strong> ';
print $langs->trans("SyncHealthWindowValue", (int) $windowHours) . '</div>';
print '<br>';

print '<div class="dz-health-grid">';
foreach ($metrics as $metric) {
	print '<div class="dz-health-card">';
	print '<div class="dz-health-label">' . dol_escape_htmltag($metric['label']) . '</div>';
	print '<div class="dz-health-value">' . dol_escape_htmltag($metric['value']) . '</div>';
	print '</div>';
}
print '</div>';

$notAvailableLabel = $langs->trans("SyncHealthNotAvailable");
if ($notAvailableLabel === "SyncHealthNotAvailable") {
	$notAvailableLabel = 'N/A';
}

$extraMetrics = array(
	array(
		'label' => $langs->trans("SyncHealthProductsEnabled"),
		'value' => !empty($productSummary['available']) ? number_format((int) $productSummary['enabled_for_sync']) : $notAvailableLabel
	),
	array(
		'label' => $langs->trans("SyncHealthProductsMapped"),
		'value' => !empty($productSummary['available']) ? number_format((int) $productSummary['mapped_products']) : $notAvailableLabel
	),
	array(
		'label' => $langs->trans("SyncHealthProductsUnmapped"),
		'value' => !empty($productSummary['available']) ? number_format((int) $productSummary['enabled_unmapped']) : $notAvailableLabel
	),
	array(
		'label' => $langs->trans("SyncHealthProductsRecentErrors"),
		'value' => !empty($productSummary['available']) ? number_format((int) $productSummary['recent_error_count']) : $notAvailableLabel
	),
	array(
		'label' => $langs->trans("SyncHealthPunchPending"),
		'value' => !empty($punchclockSummary['available']) ? number_format((int) $punchclockSummary['pending_rows']) : $notAvailableLabel
	),
	array(
		'label' => $langs->trans("SyncHealthPunchUnpaired"),
		'value' => !empty($punchclockSummary['available']) ? number_format((int) $punchclockSummary['unpaired_rows']) : $notAvailableLabel
	),
	array(
		'label' => $langs->trans("SyncHealthPunchSynthetic"),
		'value' => !empty($punchclockSummary['available']) ? number_format((int) $punchclockSummary['synthetic_rows']) : $notAvailableLabel
	),
	array(
		'label' => $langs->trans("SyncHealthApiRetries"),
		'value' => !empty($apiSummary['available']) ? number_format((int) $apiSummary['retry_count']) : $notAvailableLabel
	),
	array(
		'label' => $langs->trans("SyncHealthApiRateLimit"),
		'value' => !empty($apiSummary['available']) ? number_format((int) $apiSummary['rate_limit_count']) : $notAvailableLabel
	),
	array(
		'label' => $langs->trans("SyncHealthApiRetryExhausted"),
		'value' => !empty($apiSummary['available']) ? number_format((int) $apiSummary['exhausted_count']) : $notAvailableLabel
	),
);

print '<div><strong>' . $langs->trans("SyncHealthCrossDomain") . '</strong></div>';
print '<br>';
print '<div class="dz-health-grid">';
foreach ($extraMetrics as $metric) {
	print '<div class="dz-health-card">';
	print '<div class="dz-health-label">' . dol_escape_htmltag($metric['label']) . '</div>';
	print '<div class="dz-health-value">' . dol_escape_htmltag($metric['value']) . '</div>';
	print '</div>';
}
print '</div>';

// Error trend
print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<th colspan="3">' . $langs->trans("SyncHealthErrorTrend") . '</th>';
print '</tr>';
print '<tr class="liste_titre">';
print '<th>' . $langs->trans("Date") . '</th>';
print '<th class="right">' . $langs->trans("SyncHealthTrendCount") . '</th>';
print '<th>' . $langs->trans("SyncHealthTrendBar") . '</th>';
print '</tr>';

$maxTrend = 0;
foreach ($trend as $entry) {
	if ($entry['count'] > $maxTrend) {
		$maxTrend = $entry['count'];
	}
}

if (empty($trend)) {
	print '<tr class="oddeven"><td colspan="3" class="center">' . $langs->trans("SyncHealthNoData") . '</td></tr>';
} else {
	foreach ($trend as $entry) {
		$barWidth = $maxTrend > 0 ? (int) round(($entry['count'] / $maxTrend) * 180) : 0;
		print '<tr class="oddeven">';
		print '<td>' . dol_escape_htmltag($entry['date']) . '</td>';
		print '<td class="right">' . number_format($entry['count']) . '</td>';
		print '<td>';
		if ($barWidth > 0) {
			print '<span class="dz-health-bar" style="width:' . $barWidth . 'px;"></span>';
		}
		print '</td>';
		print '</tr>';
	}
}
print '</table>';

print '<br>';

// Top errors
print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<th colspan="2">' . $langs->trans("SyncHealthTopErrors") . '</th>';
print '</tr>';
print '<tr class="liste_titre">';
print '<th>' . $langs->trans("Error") . '</th>';
print '<th class="right">' . $langs->trans("SyncHealthTrendCount") . '</th>';
print '</tr>';

if (empty($topErrors)) {
	print '<tr class="oddeven"><td colspan="2" class="center">' . $langs->trans("SyncHealthNoErrors") . '</td></tr>';
} else {
	foreach ($topErrors as $errorRow) {
		$label = dol_trunc($errorRow['message'], 120);
		print '<tr class="oddeven">';
		print '<td>' . dol_escape_htmltag($label) . '</td>';
		print '<td class="right">' . number_format($errorRow['count']) . '</td>';
		print '</tr>';
	}
}
print '</table>';

print '<br>';

// Top product errors
print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<th colspan="2">' . $langs->trans("SyncHealthTopProductErrors") . '</th>';
print '</tr>';
print '<tr class="liste_titre">';
print '<th>' . $langs->trans("Error") . '</th>';
print '<th class="right">' . $langs->trans("SyncHealthTrendCount") . '</th>';
print '</tr>';

if (empty($topProductErrors)) {
	print '<tr class="oddeven"><td colspan="2" class="center">' . $langs->trans("SyncHealthNoErrors") . '</td></tr>';
} else {
	foreach ($topProductErrors as $errorRow) {
		$label = dol_trunc($errorRow['message'], 120);
		print '<tr class="oddeven">';
		print '<td>' . dol_escape_htmltag($label) . '</td>';
		print '<td class="right">' . number_format($errorRow['count']) . '</td>';
		print '</tr>';
	}
}
print '</table>';

print '<br>';

// Top API events
print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<th colspan="2">' . $langs->trans("SyncHealthTopApiEvents") . '</th>';
print '</tr>';
print '<tr class="liste_titre">';
print '<th>' . $langs->trans("SyncHealthDetails") . '</th>';
print '<th class="right">' . $langs->trans("SyncHealthTrendCount") . '</th>';
print '</tr>';

if (empty($topApiEvents)) {
	print '<tr class="oddeven"><td colspan="2" class="center">' . $langs->trans("SyncHealthNoApiEvents") . '</td></tr>';
} else {
	foreach ($topApiEvents as $apiRow) {
		$label = dol_trunc($apiRow['message'], 120);
		print '<tr class="oddeven">';
		print '<td>' . dol_escape_htmltag($label) . '</td>';
		print '<td class="right">' . number_format($apiRow['count']) . '</td>';
		print '</tr>';
	}
}
print '</table>';

print '<br>';

// Notification status
$emailEnabled = (bool) getDolGlobalInt('DOLIZSYNCH_NOTIFY_EMAIL_ENABLED', 0);
$emailTo = trim(getDolGlobalString('DOLIZSYNCH_NOTIFY_EMAIL_TO', ''));
print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<th colspan="3">' . $langs->trans("SyncHealthNotifications") . '</th>';
print '</tr>';
print '<tr class="liste_titre">';
print '<th>' . $langs->trans("SyncHealthChannel") . '</th>';
print '<th>' . $langs->trans("SyncHealthStatus") . '</th>';
print '<th>' . $langs->trans("SyncHealthDetails") . '</th>';
print '</tr>';

$emailStatus = ($emailEnabled && $emailTo !== '') ? $langs->trans("Enabled") : $langs->trans("Disabled");
$emailDetails = ($emailTo !== '') ? $emailTo : $langs->trans("NotConfigured");

print '<tr class="oddeven">';
print '<td>' . $langs->trans("Email") . '</td>';
print '<td>' . dol_escape_htmltag($emailStatus) . '</td>';
print '<td>' . dol_escape_htmltag($emailDetails) . '</td>';
print '</tr>';
print '</table>';

print '<br>';

// Thresholds
print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<th colspan="2">' . $langs->trans("SyncHealthThresholds") . '</th>';
print '</tr>';
print '<tr class="liste_titre">';
print '<th>' . $langs->trans("SyncHealthMetric") . '</th>';
print '<th class="right">' . $langs->trans("Value") . '</th>';
print '</tr>';

$thresholds = array(
	array('label' => $langs->trans("DOLIZSYNCH_NOTIFY_WINDOW_HOURS"), 'value' => (int) getDolGlobalInt('DOLIZSYNCH_NOTIFY_WINDOW_HOURS', 24) . ' ' . $langs->trans("hours")),
	array('label' => $langs->trans("DOLIZSYNCH_NOTIFY_ERROR_THRESHOLD"), 'value' => (int) getDolGlobalInt('DOLIZSYNCH_NOTIFY_ERROR_THRESHOLD', 5)),
	array('label' => $langs->trans("DOLIZSYNCH_NOTIFY_SUCCESS_RATE_THRESHOLD"), 'value' => (int) getDolGlobalInt('DOLIZSYNCH_NOTIFY_SUCCESS_RATE_THRESHOLD', 90) . '%'),
	array('label' => $langs->trans("DOLIZSYNCH_NOTIFY_LATENCY_THRESHOLD_MINUTES"), 'value' => (int) getDolGlobalInt('DOLIZSYNCH_NOTIFY_LATENCY_THRESHOLD_MINUTES', 30) . ' ' . $langs->trans("minutes")),
	array('label' => $langs->trans("DOLIZSYNCH_NOTIFY_COOLDOWN_MINUTES"), 'value' => (int) getDolGlobalInt('DOLIZSYNCH_NOTIFY_COOLDOWN_MINUTES', 60) . ' ' . $langs->trans("minutes")),
);

foreach ($thresholds as $threshold) {
	print '<tr class="oddeven">';
	print '<td>' . dol_escape_htmltag($threshold['label']) . '</td>';
	print '<td class="right">' . dol_escape_htmltag($threshold['value']) . '</td>';
	print '</tr>';
}
print '</table>';

print '<br>';

print '<div class="center">';
print '<h3>' . $langs->trans("SyncHealthTestNotification") . '</h3>';
print '<p class="opacitymedium">' . $langs->trans("SyncHealthTestDescription") . '</p>';
print '<form method="POST" action="' . $_SERVER["PHP_SELF"] . '">';
print '<input type="hidden" name="token" value="' . newToken() . '">';
print '<input type="hidden" name="action" value="testNotification">';
print '<input type="submit" class="button" value="' . $langs->trans("SyncHealthTestNotification") . '">';
print '</form>';
print '</div>';

print dol_get_fiche_end();

llxFooter();
$db->close();

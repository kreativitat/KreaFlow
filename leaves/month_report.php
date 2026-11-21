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

require '../../../main.inc.php';
require_once __DIR__ . '/../class/KreaFlowVacationReport.class.php';

$langs->loadLangs(array('main', 'users', 'dolizsynch@kreaflow'));
if (!KreaFlowVacationReport::canRead($conf, $user)) accessforbidden();

$selectedYear = (int) GETPOST('year', 'int');
if (!$selectedYear) $selectedYear = (int) (new DateTimeImmutable('now', new DateTimeZone('Europe/Lisbon')))->format('Y');
if ($selectedYear < 2000 || $selectedYear > 2100) accessforbidden('Invalid year');
$selectedMonth = (int) GETPOST('month', 'int');
if ($selectedMonth < 0 || $selectedMonth > 12) accessforbidden('Invalid month');
$today = (new DateTimeImmutable('now', new DateTimeZone('Europe/Lisbon')))->format('Y-m-d');
$pageTitle = $langs->trans('VacationMapTitle', $selectedYear);
$companyName = $mysoc->name;
$months = array();
for ($month = 1; $month <= 12; $month++) $months[$month] = $langs->trans('Month' . sprintf('%02d', $month));
$report = null;
try {
	$report = KreaFlowVacationReport::load($db, $conf, $user, $selectedYear, $today);
} catch (RuntimeException $e) {
	dol_syslog('KreaFlow vacation report: ' . $e->getMessage(), LOG_ERR);
	http_response_code(500);
	setEventMessages($langs->trans('VacationMapReadError'), null, 'errors');
}

if ($report && GETPOST('export', 'alpha') === 'xlsx') {
	require_once __DIR__ . '/../lib/vacation_report_xlsx.lib.php';
	kreaflow_vacation_report_xlsx($report, $selectedMonth, $companyName, $months, $langs);
	exit;
}

$reportScript = '/kreaflow/scripts/vacation_report.js?v=' . filemtime(__DIR__ . '/../scripts/vacation_report.js');
$reportStyles = '/kreaflow/css/vacation_report.css?v=' . filemtime(__DIR__ . '/../css/vacation_report.css');
llxHeader('', $pageTitle, '', '', 0, 0, array($reportScript), array($reportStyles));
print '<div class="kf-vacation-report">';
print load_fiche_titre($pageTitle, '', 'holiday');
print '<p class="kf-vacation-company">' . dol_escape_htmltag($companyName) . '</p>';
print '<div class="kf-vacation-controls">';
print '<form method="get"><label for="vacation-report-year">' . $langs->trans('Year') . '</label> ';
print '<input id="vacation-report-year" name="year" type="number" min="2000" max="2100" value="' . $selectedYear . '"> ';
print '<label for="vacation-report-month">' . $langs->trans('Month') . '</label> ';
print '<select id="vacation-report-month" name="month"><option value="0">' . $langs->trans('VacationMapAllMonths') . '</option>';
foreach ($months as $month => $label) print '<option value="' . $month . '"' . ($selectedMonth === $month ? ' selected' : '') . '>' . dol_escape_htmltag($label) . '</option>';
print '</select> <button class="button" type="submit">' . $langs->trans('Refresh') . '</button></form>';
print '<div class="kf-vacation-actions">';
print '<a class="butAction" href="' . dol_buildpath('/kreaflow/leaves/zsvacations.php', 1) . '?year=' . $selectedYear . '">' . $langs->trans('VacationMapPeriods') . '</a>';
if ($report) {
	print '<a class="butAction" href="?year=' . $selectedYear . '&amp;month=' . $selectedMonth . '&amp;export=xlsx">' . $langs->trans('VacationMapExcel') . '</a>';
	print '<button type="button" class="butAction" id="vacation-report-print">' . $langs->trans('Print') . ' / PDF</button>';
}
print '</div></div>';
if ($report) require __DIR__ . '/../tpl/vacation_report.tpl.php';
print '</div>';
llxFooter();
$db->close();

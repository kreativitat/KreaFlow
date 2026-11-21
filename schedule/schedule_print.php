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

/**
 * Shift planner weekly PDF export.
 */

define('NOCSRFCHECK', 1);
define('NOTOKENRENEWAL', 1);
define('NOREQUIREMENU', 1);
define('NOREQUIREHTML', 1);
define('NOREQUIREAJAX', 1);

require '../../../main.inc.php';
require_once DOL_DOCUMENT_ROOT . '/core/lib/pdf.lib.php';
require_once DOL_DOCUMENT_ROOT . '/core/lib/date.lib.php';
require_once DOL_DOCUMENT_ROOT . '/core/lib/security.lib.php';
require_once DOL_DOCUMENT_ROOT . '/core/lib/functions.lib.php';
require_once __DIR__ . '/../lib/shiftplanner.lib.php';

global $db, $langs, $conf, $user;

$langs->loadLangs(['main', 'dolizsynch@kreaflow']);

// Fail fast with explicit messages instead of a blank 500
if (empty($conf->dolizsynch->enabled)) {
	accessforbidden('Module KreaFlow is not enabled');
}
if (empty($user->id)) {
	accessforbidden();
}
if (empty($user->rights->dolizsynch->shift->read)) {
	accessforbidden('You do not have permission to read shift schedules');
}

$weekParam = '';
$rawWeek = GETPOST('week', 'alphanohtml');
if (empty($rawWeek) && isset($_GET['week'])) {
	$rawWeek = trim((string) $_GET['week']);
}
if (!empty($rawWeek) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $rawWeek)) {
	$weekParam = $rawWeek;
}
$weekStartDate = shiftplannerExtractWeekStart($weekParam);
$startTs = strtotime($weekStartDate . ' 00:00:00');
$endTs = strtotime('+7 days', $startTs);
$startSql = $weekStartDate . ' 00:00:00';
$endSql = date('Y-m-d', $endTs) . ' 00:00:00';
$includeForeign = 0; // Print view should not include foreign entity shifts
$blockedBg = '#e0e0e0';
$holidayBg = $blockedBg;
$nameBg = '#bababa';

$weekEndDate = date('Y-m-d', $endTs - 86400);
$overrides = loadWeekEmployeeOverrides($db, $weekStartDate);
$forcedIds = $overrides['forced'];
$hiddenIds = $overrides['hidden'];
$employees = loadEmployeesWithQuota($db, $user, $conf, $weekStartDate, $weekEndDate, $forcedIds, $hiddenIds);
usort($employees, 'compareEmployeesForPrint');
$shifts = loadWeekShifts($db, $startSql, $endSql, $includeForeign);
$indexes = indexShiftsByEmployee($shifts);
$shiftsByEmployee = $indexes['by_employee'];
$shiftsByEmployeeDay = $indexes['by_employee_day'];
$dayoffMap = loadDayOffMap($db, $startTs, $endTs, $includeForeign);
$leaveData = loadLeaveData($db, $startTs, $endTs, $includeForeign);
$leaveByDate = $leaveData['by_date'];
$leaveLegend = $leaveData['legend'];
$legendLine = formatLeaveLegendLine($leaveLegend, $langs);

$days = [];
for ($i = 0; $i < 7; $i++) {
	$ts = strtotime("+$i day", $startTs);
	$days[] = [
		'date' => date('Y-m-d', $ts),
		'label' => dol_print_date($ts, 'day'),
	];
}

$holidayCountryId = (int) getDolGlobalInt('DOLIZSYNCH_PUBLIC_HOLIDAY_COUNTRY', 25);
$publicHolidays = shiftplanner_fetch_public_holidays($db, $holidayCountryId, $weekStartDate, date('Y-m-d', $endTs));
$holidayMap = [];
foreach ($publicHolidays as $holidayEntry) {
	$date = !empty($holidayEntry['date']) ? substr($holidayEntry['date'], 0, 10) : '';
	if ($date === '') {
		continue;
	}
	$name = trim((string) ($holidayEntry['name'] ?? ''));
	$holidayMap[$date] = $name;
}

// Ensure TCPDF classes/constants are loaded
$__seedPdf = pdf_getInstance('A4');
unset($__seedPdf);

if (!class_exists('SchedulePrintPDF')) {
	if (!getDolGlobalString('MAIN_DISABLE_TCPDI') && class_exists('TCPDI')) {
		class SchedulePrintPDF extends TCPDI
		{
			public $legendLine = '';
			public $legendFontSize = 7;

			public function Footer()
			{
				if ($this->legendLine === '') {
					return;
				}
				$this->SetFont('', '', $this->legendFontSize);
				$margins = $this->getMargins();
				$width = $this->getPageWidth() - $margins['left'] - $margins['right'];
				$this->SetY(-$this->getFooterMargin());
				$this->MultiCell($width, 0, $this->legendLine, 'T', 'L', false, 1, $margins['left'], '', true, 0, false, true, 0, 'T', false);
			}
		}
	} else {
		class SchedulePrintPDF extends TCPDF
		{
			public $legendLine = '';
			public $legendFontSize = 7;

			public function Footer()
			{
				if ($this->legendLine === '') {
					return;
				}
				$this->SetFont('', '', $this->legendFontSize);
				$margins = $this->getMargins();
				$width = $this->getPageWidth() - $margins['left'] - $margins['right'];
				$this->SetY(-$this->getFooterMargin());
				$this->MultiCell($width, 0, $this->legendLine, 'T', 'L', false, 1, $margins['left'], '', true, 0, false, true, 0, 'T', false);
			}
		}
	}
}

// Create PDF instance safely (avoid fatal 500 when TCPDF is missing)
$pdfa = getDolGlobalInt('PDF_USE_A', 0);
if (!getDolGlobalString('MAIN_DISABLE_TCPDI') && class_exists('TCPDI')) {
	$pdf = new SchedulePrintPDF('L', 'mm', 'A4', true, 'UTF-8', false, $pdfa);
} else {
	$pdf = new SchedulePrintPDF('L', 'mm', 'A4', true, 'UTF-8', false, $pdfa);
}
if (!$pdf || !is_object($pdf)) {
	dol_syslog('schedule_print: Unable to instantiate PDF engine (check TCPDF installation)', LOG_ERR);
	accessforbidden($langs->trans('ErrorPDFNotAvailable'));
}
if (class_exists('TCPDF') && is_callable([$pdf, 'setPrintHeader'])) {
	$pdf->setPrintHeader(false);
}
if (class_exists('TCPDF') && is_callable([$pdf, 'setPrintFooter'])) {
	$pdf->setPrintFooter($legendLine !== '');
}
$pdf->SetTitle('HORÁRIO DE TRABALHO - ' . formatWeekRangeSimple($startTs));
$pdf->SetMargins(10, 10, 10);
$pdf->SetAutoPageBreak(true, 20);
$pdf->AddPage('L', 'A4');
$pdf->SetFont(pdf_getPDFFont($langs), '', 10);

$pdf->SetFont('', 'B', 14);
$pdf->Cell(0, 8, 'HORÁRIO DE TRABALHO', 0, 1, 'C');
$pdf->Ln(2);
$pdf->SetFont('', '', 10);
$pdf->Cell(0, 6, formatWeekRangeSimple($startTs), 0, 1, 'C');
$pdf->Ln(4);

$pdf->SetFont('', '', 8);
$employeeColumnLabel = $langs->transnoentities('ShiftPlannerEmployeeColumnLabel');
if ($employeeColumnLabel === 'ShiftPlannerEmployeeColumnLabel') {
	$employeeColumnLabel = 'Funcionário';
}

$pdf->legendLine = $legendLine;
$pdf->legendFontSize = 7;
if ($legendLine !== '') {
	$pdf->SetFont('', '', $pdf->legendFontSize);
	$margins = $pdf->getMargins();
	$usableWidth = $pdf->getPageWidth() - $margins['left'] - $margins['right'];
	$legendHeight = $pdf->getStringHeight($usableWidth, $legendLine);
	$footerMargin = max(8, $legendHeight + 4);
	$pdf->setFooterMargin($footerMargin);
	$pdf->SetAutoPageBreak(true, max(20, $footerMargin + 2));
}

$nameColWidth = '12%';
$dayColWidth = sprintf('%.2f', 88 / 7) . '%';

$table = '<table border="1" cellpadding="4" cellspacing="0" width="100%">';
$table .= '<thead>';
$table .= '<tr style="background-color:' . $nameBg . ';font-weight:bold;text-align:center;">';
$table .= '<th width="' . $nameColWidth . '" style="text-align:center;">' . dol_escape_htmltag($employeeColumnLabel) . '</th>';
foreach ($days as $day) {
	$holidayName = $holidayMap[$day['date']] ?? '';
	$holidayBadge = $holidayName !== ''
		? '<br><span style="font-size:8px;color:#333;">' . dol_escape_htmltag($holidayName) . '</span>'
		: '';
	$header = dol_escape_htmltag(getDayNamePT($day['date'])) . '<br>' . dol_escape_htmltag(formatDatePT($day['date'])) . $holidayBadge;
	$table .= '<th width="' . $dayColWidth . '">' . $header . '</th>';
}
$table .= '</tr>';
$table .= '</thead>';
$table .= '<tbody>';

foreach ($employees as $emp) {
	$empId = $emp['id'];
	$empName = getFirstLastName($emp['name']);
	$employeeShifts = $shiftsByEmployee[$empId] ?? [];
	$hoursSummary = formatEmployeeHoursPT($emp, $employeeShifts);
	$label = '<strong>' . dol_escape_htmltag($empName) . '</strong>';
	$mainTaskName = getEmployeeMainTaskLabelPrint($emp);
	if ($mainTaskName !== '') {
		$label .= '<br><span style="font-size:8px;color:#333;">(' . dol_escape_htmltag($mainTaskName) . ')</span>';
	}
	if ($hoursSummary !== '') {
		$label .= '<br><span style="font-size:8px;color:#444;">' . dol_escape_htmltag($hoursSummary) . '</span>';
	}
	$table .= '<tr nobr="true">';
	$table .= '<td width="' . $nameColWidth . '" style="text-align:center;vertical-align:middle;background-color:' . $nameBg . ';">' . $label . '</td>';
	foreach ($days as $day) {
		$dateISO = $day['date'];
		$cellShifts = $shiftsByEmployeeDay[$empId][$dateISO] ?? [];
		$dayOffInfo = $dayoffMap[$empId][$dateISO] ?? null;
		$leaveEntries = $leaveByDate[$empId][$dateISO] ?? [];
		$holidayName = $holidayMap[$dateISO] ?? '';
		$table .= renderScheduleCell($dateISO, $cellShifts, $dayOffInfo, $leaveEntries, $emp, $langs, $blockedBg, $holidayBg, $holidayName, $dayColWidth);
	}
	$table .= '</tr>';
}
$table .= '</tbody>';
$table .= '</table>';
try {
	$pdf->writeHTML($table, true, false, true, false);
	$pdf->Output('schedule.pdf', 'I');
	exit;
} catch (Throwable $e) {
	// Gracefully report PDF build errors instead of returning a blank 500
	dol_syslog('schedule_print: PDF generation failed - ' . $e->getMessage(), LOG_ERR);
	top_httphead('text/html', 0, 'UTF-8');
	echo '<h1>' . dol_escape_htmltag($langs->trans('Error')) . '</h1>';
	echo '<p>' . dol_escape_htmltag($langs->trans('ErrorPDFNotAvailable')) . '</p>';
	exit;
}

function shiftplannerExtractWeekStart($week)
{
	if (empty($week)) {
		$dt = new DateTime();
	} else {
		$dt = DateTime::createFromFormat('Y-m-d', substr($week, 0, 10));
		if (!$dt) {
			$dt = new DateTime();
		}
	}
	$day = (int) $dt->format('w');
	$offset = ($day + 6) % 7;
	if ($offset > 0) {
		$dt->modify('-' . $offset . ' days');
	}
	return $dt->format('Y-m-d');
}

function loadWeekEmployeeOverrides(DoliDB $db, $weekStart)
{
	global $conf;

	$result = ['forced' => [], 'hidden' => []];
	$weekStart = trim((string) $weekStart);
	if ($weekStart === '' || !preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $weekStart)) {
		return $result;
	}

	$table = MAIN_DB_PREFIX . "dolizsynch_week_notes";
	$hasForcedColumn = shiftplannerPrintTableHasColumn($db, $table, 'forced_user_ids');
	$hasHiddenColumn = shiftplannerPrintTableHasColumn($db, $table, 'hidden_user_ids');

	$sql = "SELECT note";
	if ($hasForcedColumn) {
		$sql .= ", forced_user_ids";
	}
	if ($hasHiddenColumn) {
		$sql .= ", hidden_user_ids";
	}
	$sql .= " FROM " . $table;
	$sql .= " WHERE week_start = '" . $db->escape($weekStart) . "'";
	$sql .= " AND entity IN (0, " . ((int) $conf->entity) . ")";
	$sql .= " ORDER BY entity DESC";

	$resql = $db->query($sql);
	if (!$resql) {
		return $result;
	}
	if ($obj = $db->fetch_object($resql)) {
		if ($hasForcedColumn) {
			$result['forced'] = shiftplannerPrintNormalizeIdList($obj->forced_user_ids ?? '');
			if ($hasHiddenColumn) {
				$result['hidden'] = shiftplannerPrintNormalizeIdList($obj->hidden_user_ids ?? '');
			}
			if (!empty($obj->note)) {
				$decoded = shiftplannerPrintDecodeWeekNotes($obj->note);
				if (empty($result['forced'])) {
					$result['forced'] = $decoded['forced'];
				}
				if (empty($result['hidden'])) {
					$result['hidden'] = $decoded['hidden'];
				}
			}
		} else {
			$decoded = shiftplannerPrintDecodeWeekNotes($obj->note);
			$result['forced'] = $decoded['forced'];
			$result['hidden'] = $decoded['hidden'];
		}
	}
	$db->free($resql);
	return $result;
}

function shiftplannerPrintDecodeWeekNotes($raw)
{
	$result = ['forced' => [], 'hidden' => []];
	if ($raw === null) {
		return $result;
	}
	$rawStr = (string) $raw;
	if ($rawStr === '') {
		return $result;
	}
	$decoded = json_decode($rawStr, true);
	if (!is_array($decoded)) {
		return $result;
	}
	$result['forced'] = shiftplannerPrintNormalizeIdList($decoded['forced_user_ids'] ?? []);
	$result['hidden'] = shiftplannerPrintNormalizeIdList($decoded['hidden_user_ids'] ?? []);
	return $result;
}

function shiftplannerPrintNormalizeIdList($raw)
{
	$values = [];
	if (is_array($raw)) {
		$values = $raw;
	} else {
		$rawStr = trim((string) $raw);
		if ($rawStr === '') {
			return [];
		}
		$values = preg_split('/\\s*,\\s*/', $rawStr);
	}
	$ids = [];
	foreach ($values as $value) {
		$id = (int) $value;
		if ($id > 0) {
			$ids[$id] = $id;
		}
	}
	return array_values($ids);
}

function shiftplannerPrintGetEmployeeGroupIds()
{
	$raw = trim((string) getDolGlobalString('DOLIZSYNCH_SHIFT_EMPLOYEE_GROUPS', ''));
	$ids = [];
	if ($raw !== '') {
		$parts = preg_split('/\s*,\s*/', $raw);
		foreach ($parts as $part) {
			$id = (int) $part;
			if ($id > 0) {
				$ids[$id] = $id;
			}
		}
	}
	if (empty($ids)) {
		$single = (int) getDolGlobalInt('DOLIZSYNCH_SHIFT_EMPLOYEE_GROUP', 0);
		if ($single > 0) {
			$ids[$single] = $single;
		}
	}
	return array_values($ids);
}

function loadEmployeesWithQuota($db, $user, $conf, $startIso = '', $endIso = '', array $forcedIds = [], array $hiddenIds = [])
{
	$forcedIds = shiftplannerPrintNormalizeIdList($forcedIds);
	$hiddenIds = shiftplannerPrintNormalizeIdList($hiddenIds);
	$groupIds = shiftplannerPrintGetEmployeeGroupIds();
	$filterEmployment = false;
	if (
		$startIso !== '' &&
		$endIso !== '' &&
		preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $startIso) &&
		preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $endIso)
	) {
		$filterEmployment = true;
	}

	$sql = "SELECT DISTINCT u.rowid, u.firstname, u.lastname, u.login, u.weeklyhours, u.dateemployment, u.dateemploymentend, ue.horasefect, ue.zs_main_task, COALESCE(ue.zs_user_hide, 0) AS hideflag";
	$sql .= " FROM " . MAIN_DB_PREFIX . "user AS u";
	$sql .= " LEFT JOIN " . MAIN_DB_PREFIX . "usergroup_user AS ugu";
	$sql .= " ON ugu.fk_user = u.rowid";
	if (!empty($groupIds)) {
		$sql .= " AND ugu.fk_usergroup IN (" . implode(',', $groupIds) . ")";
	}
	$sql .= " AND ugu.entity = " . (int) $conf->entity;
	$sql .= " LEFT JOIN " . MAIN_DB_PREFIX . "user_extrafields AS ue ON ue.fk_object = u.rowid";
	$sql .= " WHERE COALESCE(ue.zs_user_hide, 0) = 0";
	if (!empty($forcedIds)) {
		$sql .= " AND (ugu.fk_user IS NOT NULL OR u.rowid IN (" . implode(',', $forcedIds) . "))";
	} else {
		$sql .= " AND ugu.fk_user IS NOT NULL";
	}
	if (!empty($hiddenIds)) {
		$sql .= " AND u.rowid NOT IN (" . implode(',', $hiddenIds) . ")";
	}
	if ($filterEmployment) {
		$sql .= " AND (u.dateemployment IS NULL OR u.dateemployment = '0000-00-00' OR u.dateemployment <= '" . $db->escape($endIso) . "')";
		$sql .= " AND (u.dateemploymentend IS NULL OR u.dateemploymentend = '0000-00-00' OR u.dateemploymentend > '" . $db->escape($startIso) . "')";
	}
	$sql .= " ORDER BY u.lastname, u.firstname, u.rowid";

	$resql = $db->query($sql);
	$employees = [];
	if ($resql) {
	while ($obj = $db->fetch_object($resql)) {
		if (!empty($obj->hideflag)) continue;
		$name = trim(($obj->firstname ?: '') . ' ' . ($obj->lastname ?: ''));
		if ($name === '') {
			$name = $obj->login ?: ('User ' . (int) $obj->rowid);
		}
		$quota = shiftplanner_resolve_contracted_hours($obj->horasefect ?? null, $obj->weeklyhours ?? null);
		$employmentStartTs = $obj->dateemployment ? $db->jdate($obj->dateemployment) : 0;
		$employmentEndTs = $obj->dateemploymentend ? $db->jdate($obj->dateemploymentend) : 0;
		$mainTaskId = null;
		if (isset($obj->zs_main_task) && is_numeric($obj->zs_main_task) && (int) $obj->zs_main_task > 0) {
			$mainTaskId = (int) $obj->zs_main_task;
		}
		$employees[] = [
			'id' => (int) $obj->rowid,
			'name' => $name,
			'expected' => $quota,
			'employment_start' => $employmentStartTs ? dol_print_date($employmentStartTs, '%Y-%m-%d') : '',
			'employment_end' => $employmentEndTs ? dol_print_date($employmentEndTs, '%Y-%m-%d') : '',
			'main_task_id' => $mainTaskId,
		];
	}
		$db->free($resql);
	}
	return $employees;
}

function loadWeekShifts($db, $startSql, $endSql, $includeForeign = false)
{
	global $conf;

	$dayWindow = shiftplanner_get_day_window();
	$dayMeta = shiftplanner_build_daywindow_meta($dayWindow);

	$shiftTable = MAIN_DB_PREFIX . 'dolizsynch_shift';
	$taskTable = MAIN_DB_PREFIX . 'dolizsynch_shift_task';
	$sql = "SELECT s.rowid, s.fk_user, s.fk_task, s.label, s.date_start, s.date_end, t.label AS task_label, t.color AS task_color";
	$sql .= " FROM " . $shiftTable . " AS s";
	$sql .= " LEFT JOIN " . $taskTable . " AS t ON t.rowid = s.fk_task";
	$sql .= " WHERE s.date_start >= '" . $db->escape($startSql) . "'";
	$sql .= " AND s.date_start < '" . $db->escape($endSql) . "'";
	if (empty($includeForeign)) {
		$sql .= " AND s.entity = " . ((int) $conf->entity);
	}
	$sql .= " ORDER BY s.fk_user, s.date_start";

	$resql = $db->query($sql);
	$shifts = [];
	if ($resql) {
		while ($obj = $db->fetch_object($resql)) {
			$startTs = $db->jdate($obj->date_start);
			$displayDate = $startTs
				? shiftplanner_get_display_date_php($startTs, $dayMeta)
				: substr($obj->date_start, 0, 10);
			$shifts[] = [
				'id' => (int) $obj->rowid,
				'emp_id' => (int) $obj->fk_user,
				'task_label' => $obj->task_label ?: '',
				'task_color' => $obj->task_color ?: '',
				'label' => $obj->label,
				'start' => $obj->date_start,
				'end' => $obj->date_end,
				'date' => $displayDate,
			];
		}
		$db->free($resql);
	}
	return $shifts;
}

function formatWeekRangeSimple($startTs)
{
	$startLabel = date('d/m/Y', $startTs);
	$endLabel = date('d/m/Y', strtotime('+6 days', $startTs));
	$referenceTs = strtotime('+1 day', $startTs);
	$isoWeek = date('W', $referenceTs);
	$year = date('Y', $referenceTs);
	return "Semana $isoWeek de $year ($startLabel a $endLabel)";
}

function getDayNamePT($dateStr)
{
	$dayNames = [
		'Sunday' => 'Domingo',
		'Monday' => 'Segunda',
		'Tuesday' => 'Terça',
		'Wednesday' => 'Quarta',
		'Thursday' => 'Quinta',
		'Friday' => 'Sexta',
		'Saturday' => 'Sábado',
	];
	$timestamp = strtotime($dateStr);
	$dayNameEN = date('l', $timestamp);
	return $dayNames[$dayNameEN] ?? $dayNameEN;
}

function formatDatePT($dateStr)
{
	$timestamp = strtotime($dateStr);
	return date('d/m', $timestamp);
}

function getFirstLastName($fullName)
{
	$parts = array_filter(explode(' ', trim($fullName)));
	if (count($parts) === 0) return '';
	if (count($parts) === 1) return $parts[0];
	return $parts[0] . ' ' . end($parts);
}

function formatEmployeeHoursPT($employee, array $employeeShifts)
{
	$totalMinutes = 0;
	foreach ($employeeShifts as $shift) {
		$totalMinutes += max(0, getShiftDurationMinutes($shift['start'], $shift['end']));
	}
	if ($totalMinutes <= 0 && empty($employee['expected'])) {
		return '';
	}
	$actualHours = $totalMinutes / 60;
	$summary = formatHoursValue($actualHours, true);
	if (!empty($employee['expected'])) {
		$summary .= '/' . formatHoursValue((float) $employee['expected'], true);
	}
	return $summary;
}

function indexShiftsByEmployee(array $shifts)
{
	$byEmployee = [];
	$byEmployeeDay = [];
	foreach ($shifts as $shift) {
		$empId = $shift['emp_id'];
		$dateIso = $shift['date'];
		$byEmployee[$empId][] = $shift;
		if (!isset($byEmployeeDay[$empId])) {
			$byEmployeeDay[$empId] = [];
		}
		$byEmployeeDay[$empId][$dateIso][] = $shift;
	}
	return [
		'by_employee' => $byEmployee,
		'by_employee_day' => $byEmployeeDay,
	];
}

function loadDayOffMap(DoliDB $db, $startTs, $endTs, $includeForeign = false)
{
	global $conf;

	$map = [];
	$startSql = $db->escape(dol_print_date($startTs, '%Y-%m-%d'));
	$endSql = $db->escape(dol_print_date($endTs, '%Y-%m-%d'));

	$sql = "SELECT rowid, fk_user, date_dayoff, is_recurring, description FROM " . MAIN_DB_PREFIX . "dolizsynch_dayoff";
	$sql .= " WHERE ((is_recurring = 0 AND date_dayoff >= '" . $startSql . "' AND date_dayoff <= '" . $endSql . "')";
	$sql .= " OR (is_recurring = 1 AND date_dayoff <= '" . $endSql . "'))";
	if (empty($includeForeign) && shiftplannerPrintTableHasColumn($db, MAIN_DB_PREFIX . "dolizsynch_dayoff", 'entity')) {
		$sql .= " AND (entity IS NULL OR entity IN (0, " . ((int) $conf->entity) . "))";
	}
	$sql .= " ORDER BY date_dayoff";

	$resql = $db->query($sql);
	if (!$resql) {
		return $map;
	}

	while ($obj = $db->fetch_object($resql)) {
		$userId = (int) $obj->fk_user;
		if (!isset($map[$userId])) {
			$map[$userId] = [];
		}
		$baseTs = dol_stringtotime($obj->date_dayoff);
		if (!$baseTs) continue;
		$isRecurring = ((int) $obj->is_recurring === 1);
		if ($isRecurring) {
			$baseDow = (int) date('w', $baseTs);
			for ($ts = $startTs; $ts <= $endTs; $ts += 86400) {
				if ($ts < $baseTs) continue;
				if ((int) date('w', $ts) !== $baseDow) continue;
				$dateIso = date('Y-m-d', $ts);
				$map[$userId][$dateIso] = [
					'id' => (int) $obj->rowid,
					'description' => $obj->description ?: '',
					'is_recurring' => true,
				];
			}
		} else {
			if ($baseTs < $startTs || $baseTs > $endTs) continue;
			$dateIso = date('Y-m-d', $baseTs);
			$map[$userId][$dateIso] = [
				'id' => (int) $obj->rowid,
				'description' => $obj->description ?: '',
				'is_recurring' => false,
			];
		}
	}
	$db->free($resql);

	return $map;
}

function loadLeaveData(DoliDB $db, $startTs, $endTs, $includeForeign = false)
{
	global $conf;

	$result = ['by_date' => [], 'legend' => []];
	$leaveTable = MAIN_DB_PREFIX . "dolizsynch_leave";
	$dayChangeHour = shiftplanner_get_day_change_hour();
	$rangeEndTs = $endTs + 86399;
	$startBoundSql = $db->idate($startTs);
	$endBoundSql = $db->idate($rangeEndTs);
	$colorSelect = shiftplannerHolidayTypeHasColor($db) ? "COALESCE(ht.color, '#FFA500')" : "'#FFA500'";

	$sql = "SELECT l.rowid, l.fk_user, l.date_start, l.date_end, l.halfday, l.minutes_total, l.fk_type as type_id, l.description,";
	$sql .= " ht.label as type_label, ht.code as type_code, " . $colorSelect . " as color";
	$sql .= " FROM " . $leaveTable . " l";
	$sql .= " LEFT JOIN " . MAIN_DB_PREFIX . "c_holiday_types ht ON ht.rowid = l.fk_type";
	$sql .= " WHERE 1 = 1";
	if (empty($includeForeign)) {
		$sql .= " AND (l.entity IS NULL OR l.entity IN (0, " . ((int) $conf->entity) . "))";
	}
	$sql .= " AND l.date_end > '" . $db->escape($startBoundSql) . "'";
	$sql .= " AND l.date_start < '" . $db->escape($endBoundSql) . "'";
	$sql .= " ORDER BY l.date_start";

	$resql = $db->query($sql);
	if (!$resql) {
		return $result;
	}

	while ($obj = $db->fetch_object($resql)) {
		$empId = (int) $obj->fk_user;
		if (!isset($result['by_date'][$empId])) {
			$result['by_date'][$empId] = [];
		}
		$leaveStartTs = shiftplannerNormalizeTimestamp($db, $obj->date_start);
		$leaveEndTs = shiftplannerNormalizeTimestamp($db, $obj->date_end);
		$minutesTotalStored = (int) $obj->minutes_total;
		$durationMinutes = shiftplannerComputeMinutesBetween($leaveStartTs, $leaveEndTs);
		$startBoundaryTs = shiftplanner_operational_boundary_for_timestamp($leaveStartTs, $dayChangeHour);

		$halfdayVal = (int) $obj->halfday;
		$minutesOnly = false;
		if ($minutesTotalStored > 0) {
			$expectedEndTs = $leaveStartTs + ($minutesTotalStored * 60);
			if ($leaveStartTs === $startBoundaryTs && $leaveEndTs === $expectedEndTs) {
				$minutesOnly = true;
			}
		}

		$isHoursLeave = (!$minutesOnly && $durationMinutes > 0 && $durationMinutes < 1440);
		$isPartialLeave = $isHoursLeave || $minutesOnly || ($halfdayVal !== 0);

		$hoursStartLabel = '';
		$hoursEndLabel = '';
		$hoursRangeLabel = '';
		$hoursTotalMinutes = 0;
		if ($isHoursLeave) {
			$hoursStartLabel = dol_print_date($leaveStartTs, '%H:%M');
			$hoursEndLabel = dol_print_date($leaveEndTs, '%H:%M');
			$hoursRangeLabel = $hoursStartLabel && $hoursEndLabel ? ($hoursStartLabel . '-' . $hoursEndLabel) : '';
			$hoursTotalMinutes = $durationMinutes;
		} elseif ($minutesOnly) {
			$hoursTotalMinutes = $minutesTotalStored;
		}

		if ($isPartialLeave) {
			$startDayTs = $leaveStartTs;
			$endDayTs = $leaveEndTs;
			$startIso = date('Y-m-d', $leaveStartTs);
			$endIso = date('Y-m-d', $leaveEndTs - 1);
		} else {
			$startBoundaryTs = shiftplanner_operational_boundary_for_timestamp($leaveStartTs, $dayChangeHour);
			$lastDayBoundaryTs = shiftplanner_operational_boundary_for_timestamp($leaveEndTs - 1, $dayChangeHour);
			$startDayTs = $startBoundaryTs;
			$endDayTs = $lastDayBoundaryTs + 86400;
			$startIso = date('Y-m-d', $startBoundaryTs);
			$endIso = date('Y-m-d', $lastDayBoundaryTs);
		}

		$entry = [
			'id' => (int) $obj->rowid,
			'empId' => $empId,
			'type_id' => (int) $obj->type_id,
			'start' => $startDayTs,
			'end' => $endDayTs,
			'halfday' => (int) $obj->halfday,
			'type' => $obj->type_label ?: ($obj->type_code ?: 'Leave'),
			'code' => $obj->type_code ?: '',
			'color' => $obj->color ?: '#FFA500',
			'start_iso' => $startIso,
			'end_iso' => $endIso,
			'description' => (string) ($obj->description ?? ''),
			'is_hours' => $isHoursLeave ? 1 : 0,
			'minutes_only' => $minutesOnly ? 1 : 0,
			'hours_range' => $hoursRangeLabel,
			'hours_start' => $hoursStartLabel,
			'hours_end' => $hoursEndLabel,
			'hours_total_minutes' => $hoursTotalMinutes,
			'start_ts' => $leaveStartTs,
			'end_ts' => $endDayTs,
		];

		foreach (expandLeaveDates($entry) as $dateIso) {
			if (!isset($result['by_date'][$empId][$dateIso])) {
				$result['by_date'][$empId][$dateIso] = [];
			}
			$result['by_date'][$empId][$dateIso][] = $entry;
		}

		$codeKey = strtoupper(trim($entry['code'] ?? ''));
		if ($codeKey !== '' && !isset($result['legend'][$codeKey])) {
			$result['legend'][$codeKey] = [
				'display' => formatLeaveCodeDisplay($entry['code'], $entry['type']),
				'label' => $entry['type'],
			];
		}
	}
	$db->free($resql);

	return $result;
}


function expandLeaveDates(array $leave)
{
	$dates = [];
	$startIso = isset($leave['start_iso']) ? substr((string) $leave['start_iso'], 0, 10) : '';
	$endIso = isset($leave['end_iso']) ? substr((string) $leave['end_iso'], 0, 10) : '';
	if ($startIso && $endIso && preg_match('/^\d{4}-\d{2}-\d{2}$/', $startIso) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $endIso)) {
		$cursor = strtotime($startIso . ' 00:00:00');
		$end = strtotime($endIso . ' 00:00:00');
		if ($cursor !== false && $end !== false) {
			for ($ts = $cursor; $ts <= $end; $ts += 86400) {
				$dates[] = date('Y-m-d', $ts);
			}
			return $dates;
		}
	}
	$startTs = isset($leave['start']) ? (int) $leave['start'] : 0;
	$endTs = isset($leave['end']) ? (int) $leave['end'] : $startTs;
	if ($startTs <= 0 || $endTs <= 0) {
		return $dates;
	}
	for ($ts = $startTs; $ts <= $endTs; $ts += 86400) {
		$dates[] = date('Y-m-d', $ts);
	}
	return $dates;
}

function renderScheduleCell($dateISO, array $cellShifts, $dayOffInfo, array $leaveEntries, $employee, $langs, $blockedBg, $holidayBg, $holidayName = '', $cellWidth = '')
{
	$style = 'text-align:center;vertical-align:middle;font-size:9px;padding:4px;';
	$widthAttr = $cellWidth !== '' ? ' width="' . $cellWidth . '"' : '';
	$employmentMessage = getEmploymentBlockStatus($employee, $dateISO, $langs);
	if ($employmentMessage !== null) {
		return '<td' . $widthAttr . ' style="' . $style . 'background-color:' . $blockedBg . ';color:#222;font-weight:bold;">' . dol_escape_htmltag($employmentMessage) . '</td>';
	}
	if ($dayOffInfo) {
		$label = $langs->transnoentities('ShiftPlannerDayOffLabel');
		$content = '<strong>' . dol_escape_htmltag($label) . '</strong>';
		if (!empty($dayOffInfo['is_recurring'])) {
			$content .= '<br><small>' . dol_escape_htmltag($langs->transnoentities('ShiftPlannerRecurringHint')) . '</small>';
		}
		if (!empty($dayOffInfo['description'])) {
			$content .= '<br><span style="font-size:8px;">' . dol_escape_htmltag($dayOffInfo['description']) . '</span>';
		}
		$mainTask = getEmployeeMainTaskLabelPrint($employee);
		if ($mainTask !== '') {
			$content .= '<br><span style="font-size:8px;">(' . dol_escape_htmltag($mainTask) . ')</span>';
		}
		return '<td' . $widthAttr . ' style="' . $style . 'background-color:' . $blockedBg . ';">' . $content . '</td>';
	}
	$lines = [];
	$hasFullDayLeave = false;
	foreach ($leaveEntries as $leaveInfo) {
		if (isPartialLeave($leaveInfo)) {
			$lines[] = dol_escape_htmltag(formatPartialLeaveLine($leaveInfo, $langs));
			continue;
		}
		$hasFullDayLeave = true;
		$label = formatLeaveCodeDisplay($leaveInfo['code'] ?? '', $leaveInfo['type'] ?? '');
		$content = '<strong>' . dol_escape_htmltag($label) . '</strong>';
		if (!empty($leaveInfo['description'])) {
			$content .= '<br><span style="font-size:8px;">' . dol_escape_htmltag($leaveInfo['description']) . '</span>';
		}
		$mainTask = getEmployeeMainTaskLabelPrint($employee);
		if ($mainTask !== '') {
			$content .= '<br><span style="font-size:8px;">(' . dol_escape_htmltag($mainTask) . ')</span>';
		}
		$lines[] = $content;
	}
	if (!$hasFullDayLeave && !empty($cellShifts)) {
		$lines = array_merge($lines, array_map('dol_escape_htmltag', buildShiftLineEntries($cellShifts)));
	}
	if (empty($lines)) {
		$lines[] = '<span style="color:#777;">—</span>';
	}
	$styleAttr = $style;
	if ($hasFullDayLeave) {
		$styleAttr .= 'background-color:' . $blockedBg . ';';
	} elseif ($holidayName !== '') {
		$styleAttr .= 'background-color:' . $holidayBg . ';';
	}
	return '<td' . $widthAttr . ' style="' . $styleAttr . '">' . implode('<br>', $lines) . '</td>';
}

function isPartialLeave(array $leaveInfo)
{
	$halfday = isset($leaveInfo['halfday']) ? (int) $leaveInfo['halfday'] : 0;
	$isHoursLeave = (int) ($leaveInfo['is_hours'] ?? 0) === 1;
	$minutesOnlyFlag = (int) ($leaveInfo['minutes_only'] ?? 0) === 1;
	$hoursStart = trim((string) ($leaveInfo['hours_start'] ?? ''));
	$hoursEnd = trim((string) ($leaveInfo['hours_end'] ?? ''));
	$hoursRange = trim((string) ($leaveInfo['hours_range'] ?? ''));
	$minutesTotal = (int) ($leaveInfo['hours_total_minutes'] ?? 0);
	$hasMinuteOnlyFields = $hoursStart === '' && $hoursEnd === '' && $hoursRange === '';
	$isMinutesLeave = $minutesOnlyFlag || ($minutesTotal > 0 && $hasMinuteOnlyFields);
	return $isHoursLeave || $isMinutesLeave || $halfday !== 0;
}

function formatPartialLeaveLine(array $leaveInfo, $langs)
{
	$codeDisplay = formatLeaveCodeDisplay($leaveInfo['code'] ?? '', $leaveInfo['type'] ?? '');
	$detail = buildLeaveDetailLabel($leaveInfo, $langs);
	$parts = array_filter([$codeDisplay, $detail], static fn($v) => (string) $v !== '');
	if (!empty($leaveInfo['description'])) {
		$parts[] = trim((string) $leaveInfo['description']);
	}
	return implode(' - ', $parts);
}

function buildShiftLineEntries(array $cellShifts)
{
	if (empty($cellShifts)) return [];
	$lines = [];
	$totalMinutes = 0;
	foreach ($cellShifts as $shift) {
		$start = shiftplannerSqlDateTimeToTimeLabel($shift['start']);
		$end = shiftplannerSqlDateTimeToTimeLabel($shift['end']);
		$line = $start . '-' . $end;
		if (!empty($shift['task_label'])) {
			$line .= ' (' . $shift['task_label'] . ')';
		}
		$lines[] = $line;
		$totalMinutes += max(0, getShiftDurationMinutes($shift['start'], $shift['end']));
	}
	if ($totalMinutes > 0 && !empty($lines)) {
		$lines[count($lines) - 1] .= ' (' . formatHoursValue($totalMinutes / 60) . ')';
	}
	return $lines;
}

function buildLeaveDetailLabel(array $leaveInfo, $langs)
{
	$minutesTotal = (int) ($leaveInfo['hours_total_minutes'] ?? 0);
	$isHoursLeave = (int) ($leaveInfo['is_hours'] ?? 0) === 1;
	$minutesOnlyFlag = (int) ($leaveInfo['minutes_only'] ?? 0) === 1;
	$hoursRange = trim((string) ($leaveInfo['hours_range'] ?? ''));
	$hoursStart = trim((string) ($leaveInfo['hours_start'] ?? ''));
	$hoursEnd = trim((string) ($leaveInfo['hours_end'] ?? ''));
	$hasMinuteOnlyFields = $hoursStart === '' && $hoursEnd === '' && $hoursRange === '';
	$isMinutesLeave = $minutesOnlyFlag || ($minutesTotal > 0 && $hasMinuteOnlyFields);
	$startTs = isset($leaveInfo['start_ts']) ? (int) $leaveInfo['start_ts'] : 0;
	$endTs = isset($leaveInfo['end_ts']) ? (int) $leaveInfo['end_ts'] : 0;
	$computedRange = ($startTs && $endTs && $endTs > $startTs) ? formatTimeRangeFromSeconds($startTs, $endTs) : '';
	if ($isHoursLeave) {
		return $hoursRange ? str_replace('-', ' - ', $hoursRange) : $computedRange;
	}
	if ($isMinutesLeave) {
		return formatMinutesSummary($minutesTotal, $langs);
	}
	$halfday = isset($leaveInfo['halfday']) ? (int) $leaveInfo['halfday'] : 0;
	if ($halfday !== 0) {
		if ($computedRange) {
			return $computedRange;
		}
		return ($halfday === 1)
			? $langs->transnoentities('ShiftPlannerLeaveHalfMorningLabel')
			: $langs->transnoentities('ShiftPlannerLeaveHalfAfternoonLabel');
	}
	if ($minutesTotal > 0 && ($computedRange === '' || $computedRange === '00:00 - 00:00')) {
		return formatMinutesSummary($minutesTotal, $langs);
	}
	return '';
}

function formatMinutesSummary($minutes, $langs)
{
	if (!$minutes || $minutes <= 0) {
		return '';
	}
	$hours = floor($minutes / 60);
	$mins = $minutes % 60;
	$duration = sprintf('%02d:%02d', $hours, $mins);
	$template = $langs->transnoentities('ShiftPlannerLeaveMinutesSummary');
	if ($template === 'ShiftPlannerLeaveMinutesSummary') {
		$template = 'Faltou %s';
	}
	return str_replace('%s', $duration, $template);
}

function getEmployeeMainTaskLabelPrint(array $employee)
{
	if (empty($employee['main_task_id'])) {
		return '';
	}
	static $taskLabels = null;
	if ($taskLabels === null) {
		$taskLabels = loadTaskLabelsForPrint();
	}
	$key = (string) $employee['main_task_id'];
	return $taskLabels[$key] ?? '';
}

function loadTaskLabelsForPrint()
{
	global $db;
	$result = [];
	$sql = "SELECT rowid, label FROM " . MAIN_DB_PREFIX . "dolizsynch_shift_task WHERE active = 1";
	$resql = $db->query($sql);
	if ($resql) {
		while ($obj = $db->fetch_object($resql)) {
			if (!empty($obj->rowid) && $obj->label !== null && $obj->label !== '') {
				$result[(string) $obj->rowid] = $obj->label;
			}
		}
		$db->free($resql);
	}
	return $result;
}

function compareEmployeesForPrint($a, $b)
{
	$taskA = mb_strtolower(trim(getEmployeeMainTaskLabelPrint($a)), 'UTF-8');
	$taskB = mb_strtolower(trim(getEmployeeMainTaskLabelPrint($b)), 'UTF-8');
	$hasTaskA = $taskA !== '';
	$hasTaskB = $taskB !== '';
	if ($hasTaskA && !$hasTaskB) return -1;
	if (!$hasTaskA && $hasTaskB) return 1;
	if ($hasTaskA && $hasTaskB) {
		$cmpTask = strcmp($taskA, $taskB);
		if ($cmpTask !== 0) return $cmpTask;
	}
	$nameA = isset($a['name']) ? mb_strtolower(trim($a['name']), 'UTF-8') : '';
	$nameB = isset($b['name']) ? mb_strtolower(trim($b['name']), 'UTF-8') : '';
	$cmpName = strcmp($nameA, $nameB);
	if ($cmpName !== 0) return $cmpName;
	return ($a['id'] ?? 0) <=> ($b['id'] ?? 0);
}

function getEmploymentBlockStatus(array $employee, $dateISO, $langs)
{
	$employmentStart = $employee['employment_start'] ?? '';
	$employmentEnd = $employee['employment_end'] ?? '';
	if ($employmentStart) {
		$prevDay = shiftplannerAdjustDate($employmentStart, -1);
			if ($prevDay && $dateISO === $prevDay) {
				$startLabel = trim($langs->transnoentities('ShiftPlannerFutureEmploymentLabel'));
				if (
					!$startLabel ||
					$startLabel === 'ShiftPlannerFutureEmploymentLabel'
				) {
					$startLabel = 'Admissão';
				}
				return $startLabel . ' >';
			}
		if ($dateISO < $employmentStart) {
			return '—';
		}
	}
	if ($employmentEnd) {
		$nextDay = shiftplannerAdjustDate($employmentEnd, 1);
		if ($nextDay && $dateISO === $nextDay) {
			$endLabel = removeNonAscii(trim($langs->transnoentities('ShiftPlannerDayAfterEndLabel')));
			return '< ' . $endLabel;
		}
		if ($dateISO > $employmentEnd) {
			return '—';
		}
	}
	return null;
}

function formatHoursValue($hours, $forceSingleDecimal = false)
{
	if (!is_numeric($hours) || $hours < 0) {
		return $forceSingleDecimal ? '0.0h' : '0h';
	}
	$rounded = round($hours * 10) / 10;
	if ($forceSingleDecimal) {
		return number_format($rounded, 1, '.', '') . 'h';
	}
	return (floor($rounded) == $rounded ? (string) $rounded : rtrim(rtrim(number_format($rounded, 1, '.', ''), '0'), '.')) . 'h';
}

function formatLeaveCodeDisplay($code, $fallback = '')
{
	$code = trim((string) $code);
	if ($code === '') {
		return $fallback;
	}
	$hasUnderscore = strpos($code, '_') !== false;
	$parts = preg_split('/[_\s]+/', $code);
	$formatted = array_map(function ($part) {
		$lower = mb_strtolower($part, 'UTF-8');
		$first = mb_substr($lower, 0, 1, 'UTF-8');
		$rest = mb_substr($lower, 1, null, 'UTF-8');
		return mb_strtoupper($first, 'UTF-8') . $rest;
	}, $parts);
	return $hasUnderscore ? implode('_', $formatted) : implode(' ', $formatted);
}

function getShiftDurationMinutes($startIso, $endIso)
{
	$startTs = dol_stringtotime($startIso);
	$endTs = dol_stringtotime($endIso);
	if (!$startTs || !$endTs || $endTs <= $startTs) {
		return 0;
	}
	return (int) round(($endTs - $startTs) / 60);
}

function formatTimeRangeFromSeconds($startTs, $endTs)
{
	if (!$startTs || !$endTs || $endTs <= $startTs) {
		return '';
	}
	return date('H:i', $startTs) . ' - ' . date('H:i', $endTs);
}

function removeNonAscii($value)
{
	return preg_replace('/[^\x00-\x7F]/', '', (string) $value);
}

function shiftplannerAdjustDate($dateIso, $offsetDays)
{
	if (!$dateIso || !is_numeric($offsetDays)) {
		return null;
	}
	$dt = DateTime::createFromFormat('Y-m-d', $dateIso);
	if (!$dt) {
		return null;
	}
	$dt->modify(($offsetDays >= 0 ? '+' : '') . $offsetDays . ' day');
	return $dt->format('Y-m-d');
}

function shiftplannerExtractLeaveHours($description)
{
	$text = trim((string) $description);
	$info = [
		'range' => '',
		'start' => '',
		'end' => '',
		'minutes' => 0,
		'description' => $text,
	];
	$matched = false;
	while (preg_match('/^\[\[(HOURS|MINUTES)=([^\]]+)\]\]\s*(.*)$/', $text, $m)) {
		$matched = true;
		if ($m[1] === 'HOURS') {
			if (preg_match('/^([0-2]\d:[0-5]\d)-([0-2]\d:[0-5]\d)$/', $m[2], $parts)) {
				$info['range'] = $parts[1] . '-' . $parts[2];
				$info['start'] = $parts[1];
				$info['end'] = $parts[2];
			}
		} elseif ($m[1] === 'MINUTES') {
			$info['minutes'] = (int) $m[2];
		}
		$text = trim($m[3]);
	}
	if (!$matched) {
		return null;
	}
	$info['description'] = $text;
	return $info;
}

function shiftplannerComputeMinutesFromRange($startLabel, $endLabel)
{
	if (!preg_match('/^\d{2}:\d{2}$/', (string) $startLabel) || !preg_match('/^\d{2}:\d{2}$/', (string) $endLabel)) {
		return 0;
	}
	[$sh, $sm] = array_map('intval', explode(':', $startLabel));
	[$eh, $em] = array_map('intval', explode(':', $endLabel));
	$startMinutes = ($sh * 60) + $sm;
	$endMinutes = ($eh * 60) + $em;
	$diff = $endMinutes - $startMinutes;
	if ($diff <= 0) {
		$diff += 24 * 60;
	}
	return $diff;
}

function shiftplannerSqlDateTimeToTimeLabel($value)
{
	if ($value === null || $value === '') {
		return '';
	}

	$text = trim((string) $value);
	if (
		preg_match(
			'/^\d{4}-\d{2}-\d{2}[T\s]([01]\d|2[0-3]):([0-5]\d)(?::[0-5]\d)?(?:\.\d+)?(?:Z|[+\-]\d{2}:?\d{2})?$/',
			$text,
			$matches
		)
	) {
		// Keep the literal SQL/local wall-clock hour to prevent DST/timezone drift in print output.
		return $matches[1] . ':' . $matches[2];
	}
	if (preg_match('/^([01]\d|2[0-3]):([0-5]\d)(?::[0-5]\d)?$/', $text, $matches)) {
		return $matches[1] . ':' . $matches[2];
	}

	$ts = dol_stringtotime($text);
	return empty($ts) ? '' : date('H:i', $ts);
}

function shiftplannerNormalizeTimestamp(DoliDB $db, $value)
{
	if ($value === null || $value === '') {
		return 0;
	}
	if (is_numeric($value)) {
		return (int) $value;
	}
	return (int) $db->jdate($value);
}

function shiftplannerHolidayTypeHasColor(DoliDB $db)
{
	static $hasColor = null;
	if ($hasColor !== null) return $hasColor;
	$sql = "SHOW COLUMNS FROM " . MAIN_DB_PREFIX . "c_holiday_types LIKE 'color'";
	$resql = $db->query($sql);
	if (!$resql) {
		$hasColor = false;
		return $hasColor;
	}
	$hasColor = ($db->num_rows($resql) > 0);
	$db->free($resql);
	return $hasColor;
}

function shiftplannerPrintTableHasColumn(DoliDB $db, $table, $column)
{
	static $cache = [];
	$key = $table . '|' . $column;
	if (array_key_exists($key, $cache)) {
		return $cache[$key];
	}
	$sql = "SHOW COLUMNS FROM " . $table . " LIKE '" . $db->escape($column) . "'";
	$resql = $db->query($sql);
	if (!$resql) {
		$cache[$key] = false;
		return false;
	}
	$cache[$key] = ($db->num_rows($resql) > 0);
	$db->free($resql);
	return $cache[$key];
}

function formatLeaveLegendLine(array $leaveLegend, $langs)
{
	if (empty($leaveLegend)) {
		return '';
	}
	$entries = [];
	foreach ($leaveLegend as $legendItem) {
		if (empty($legendItem['display'])) continue;
		$entry = $legendItem['display'];
		if (!empty($legendItem['label']) && strcasecmp($legendItem['label'], $legendItem['display']) !== 0) {
			$entry .= ' - ' . $legendItem['label'];
		}
		$entries[] = $entry;
	}
	if (empty($entries)) {
		return '';
	}
	$title = $langs->transnoentities('ShiftPlannerLeaveLegendTitle');
	if (!$title || $title === 'ShiftPlannerLeaveLegendTitle') {
		$title = 'Legenda de ausências';
	}
	return $title . ': ' . implode(', ', $entries);
}

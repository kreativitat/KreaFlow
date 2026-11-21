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
 * Vacations yearly PDF export.
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
require_once __DIR__ . '/../class/zsleaves.class.php';
require_once __DIR__ . '/../lib/shiftplanner.lib.php';

global $db, $langs, $conf, $user;

if (!function_exists('dolizsynchCountWeekdays')) {
	function dolizsynchCountWeekdays($startTs, $endTs)
	{
		$startTs = (int) $startTs;
		$endTs = (int) $endTs;

		if ($endTs < $startTs) {
			return 0;
		}

		static $utcTimezone = null;
		if ($utcTimezone === null) {
			$utcTimezone = new DateTimeZone('UTC');
		}

		$startDate = new DateTime('@' . $startTs);
		$startDate->setTimezone($utcTimezone);
		$startDate->setTime(0, 0, 0);

		$endDate = new DateTime('@' . $endTs);
		$endDate->setTimezone($utcTimezone);
		$endDate->setTime(0, 0, 0);

		$diffDays = (int) $startDate->diff($endDate)->format('%a') + 1;
		$fullWeeks = (int) floor($diffDays / 7);
		$remainingDays = $diffDays % 7;

		$weekdayCount = $fullWeeks * 5;
		$startDow = (int) $startDate->format('N');

		for ($i = 0; $i < $remainingDays; $i++) {
			$weekday = (($startDow - 1 + $i) % 7) + 1;
			if ($weekday <= 5) {
				$weekdayCount++;
			}
		}

		return $weekdayCount;
	}
}

$langs->loadLangs(['main', 'dolizsynch@kreaflow']);

if (empty($conf->dolizsynch->enabled)) {
	accessforbidden('Module KreaFlow is not enabled');
}
if (empty($user->rights->dolizsynch->shift->schedule_vacations_view)) {
	accessforbidden('You do not have permission to view annual vacations');
}

$selectedYear = (int) GETPOST('year', 'int');
if ($selectedYear <= 0) {
	$selectedYear = (int) date('Y');
}

$leavesHelper = new ZSLeaves($db);
$leaveTable = MAIN_DB_PREFIX . $leavesHelper->table_element;
$leaveEntityFilter = "(l.entity IS NULL OR l.entity IN (0, " . ((int) $conf->entity) . "))";
$allowedEmployees = array_values(array_map('intval', array_unique($leavesHelper->getEntityEmployeesForYear($selectedYear, $conf->entity, 2, 'u.firstname'))));
$allowedEmployees = array_values(array_filter($allowedEmployees, function ($id) {
	return $id > 0;
}));

$vacTypeIds = [];
$allTypes = $leavesHelper->getTypes(1);
$vacCodes = array('CP', 'VAC', 'VACATION', 'FERIAS', 'FERIA', 'FERIE');
foreach ($allTypes as $typeId => $row) {
	$codeUpper = strtoupper(trim((string) ($row['code'] ?? '')));
	if ($codeUpper !== '' && in_array($codeUpper, $vacCodes, true)) {
		$vacTypeIds[(int) $typeId] = (int) $typeId;
	}
}
if (empty($vacTypeIds) && !empty($allTypes)) {
	foreach ($allTypes as $typeId => $row) {
		$label = trim((string) ($row['label'] ?? ''));
		if ($label === '') {
			continue;
		}
		$labelLower = dol_strtolower($label);
		if (strpos($labelLower, 'fer') !== false || strpos($labelLower, 'vac') !== false) {
			$vacTypeIds[(int) $typeId] = (int) $typeId;
		}
	}
}
if (empty($vacTypeIds)) {
	$vacTypeIds = array(-1);
}
$vacTypeSql = implode(',', array_map('intval', $vacTypeIds));

$rangeStartTs = dol_mktime(0, 0, 0, 1, 1, $selectedYear);
$rangeEndTs = dol_mktime(23, 59, 59, 12, 31, $selectedYear);
$rangeStartSql = $db->idate($rangeStartTs);
$rangeEndSql = $db->idate($rangeEndTs);

$leaveEmployees = [];
$sqlEmployees = "SELECT DISTINCT l.fk_user";
$sqlEmployees .= " FROM " . $leaveTable . " l";
$sqlEmployees .= " WHERE " . $leaveEntityFilter;
$sqlEmployees .= " AND l.fk_type IN (" . $vacTypeSql . ")";
$sqlEmployees .= " AND l.date_end >= '" . $db->escape($rangeStartSql) . "'";
$sqlEmployees .= " AND l.date_start <= '" . $db->escape($rangeEndSql) . "'";
$resEmployees = $db->query($sqlEmployees);
if ($resEmployees) {
	while ($objEmp = $db->fetch_object($resEmployees)) {
		$empId = (int) ($objEmp->fk_user ?? 0);
		if ($empId > 0) {
			$leaveEmployees[$empId] = $empId;
		}
	}
	$db->free($resEmployees);
}
if (!empty($leaveEmployees)) {
	$allowedEmployees = array_values(array_unique(array_merge($allowedEmployees, array_values($leaveEmployees))));
}
if (empty($allowedEmployees)) {
	$allowedEmployees = array(-1);
}

$leavesByEmployee = [];
$employees = [];

$sql = "SELECT l.rowid, l.fk_user, l.date_start, l.date_end, l.halfday,";
$sql .= " u.firstname, u.lastname";
$sql .= " FROM " . $leaveTable . " l";
$sql .= " INNER JOIN " . MAIN_DB_PREFIX . "user u ON u.rowid = l.fk_user";
$sql .= " WHERE " . $leaveEntityFilter;
$sql .= " AND l.fk_type IN (" . $vacTypeSql . ")";
if (!empty($allowedEmployees)) {
	$sql .= " AND u.rowid IN (" . implode(',', array_map('intval', $allowedEmployees)) . ")";
}
$sql .= " AND l.date_end >= '" . $db->escape($rangeStartSql) . "'";
$sql .= " AND l.date_start <= '" . $db->escape($rangeEndSql) . "'";
$sql .= " ORDER BY u.firstname, u.lastname, l.date_start";

$resql = $db->query($sql);
if ($resql) {
	while ($obj = $db->fetch_object($resql)) {
		$empId = (int) $obj->fk_user;
		if (!isset($employees[$empId])) {
			$fullName = trim(($obj->firstname ?: '') . ' ' . ($obj->lastname ?: ''));
			$employees[$empId] = $fullName ?: ('Funcionario #' . $empId);
			$leavesByEmployee[$empId] = [];
		}
		$startTs = shiftplannerNormalizeTimestamp($db, $obj->date_start);
		$endTs = shiftplannerNormalizeTimestamp($db, $obj->date_end);
		if (!$startTs || !$endTs) {
			continue;
		}
		$periodStart = max($startTs, $rangeStartTs);
		$periodEnd = min($endTs, $rangeEndTs);
		if ($periodEnd < $periodStart) {
			continue;
		}
		$durationDays = dolizsynchCountWeekdays($periodStart, $periodEnd);
		$totalDays = (($periodEnd - $periodStart) / 86400) + 1;
		$halfdayVal = (int) $obj->halfday;
		if ($halfdayVal === 1) {
			$durationDays -= 0.5;
			$totalDays -= 0.5;
		} elseif ($halfdayVal === 2 || $halfdayVal === 3) {
			$durationDays = min($durationDays, 1);
			$totalDays = min($totalDays, 1);
		}
		$durationDays = round(max(0, $durationDays), 1);
		$totalDays = round(max(0, $totalDays), 1);
		$leavesByEmployee[$empId][] = [
			'start' => $periodStart,
			'end' => $periodEnd,
			'days' => $durationDays,
			'total_days' => $totalDays,
		];
	}
	$db->free($resql);
}

ksort($employees, SORT_NUMERIC);

$pdfTitle = $langs->transnoentities('VacationsYearTitle', $selectedYear);
if (!$pdfTitle || $pdfTitle === 'VacationsYearTitle') {
	$pdfTitle = 'Vacations ' . $selectedYear;
}
$pdf = pdf_getInstance('A4');
if (class_exists('TCPDF')) {
	$pdf->setPrintHeader(false);
	$pdf->setPrintFooter(false);
}
$pdf->SetTitle($pdfTitle);
$pdf->SetMargins(10, 10, 10);
$pdf->SetAutoPageBreak(true, 20);
$pdf->AddPage('P', 'A4');
$pdf->SetFont(pdf_getPDFFont($langs), '', 10);

$pdf->SetFont('', 'B', 14);
$pdf->Cell(0, 8, $pdfTitle, 0, 1, 'C');
$pdf->Ln(4);

$printedEmployees = 0;
$noDataLabel = $langs->transnoentities('NoDataFound');
if (!$noDataLabel || $noDataLabel === 'NoDataFound') {
	$noDataLabel = 'Sem registos';
}
$startLabel = $langs->transnoentities('DateStart');
if (!$startLabel || $startLabel === 'DateStart') {
	$startLabel = 'Data Início';
}
$endLabel = $langs->transnoentities('EndDate');
if (!$endLabel || $endLabel === 'EndDate') {
	$endLabel = 'Data Fim';
}
$durationLabel = $langs->transnoentities('Duration');
if (!$durationLabel || $durationLabel === 'Duration') {
	$durationLabel = 'Duração';
}
$totalLabel = $langs->transnoentities('VacationsTotalDays');
if (!$totalLabel || $totalLabel === 'VacationsTotalDays') {
	$totalLabel = 'Dias Totais';
}
$daysWord = $langs->transnoentities('Days');
if (!$daysWord || $daysWord === 'Days') {
	$daysWord = 'Dias';
}

foreach ($employees as $empId => $name) {
	$records = $leavesByEmployee[$empId] ?? [];
	if (empty($records)) {
		continue;
	}
	$printedEmployees++;
	$totalDuration = 0;
	foreach ($records as $rec) {
		$totalDuration += $rec['days'];
	}
	$header = $name . ' - ' . number_format($totalDuration, 1) . ' ' . $daysWord;
	$pdf->SetFont('', 'B', 11);
	$pdf->Cell(0, 6, $header, 0, 1, 'L');

	$pdf->SetFont('', '', 9);
	$table = '<table border="1" cellpadding="4" cellspacing="0" width="100%">';
	$table .= '<tr style="font-weight:bold;text-align:center;background-color:#efefef;">';
	$table .= '<th width="25%">' . dol_escape_htmltag($startLabel) . '</th>';
	$table .= '<th width="25%">' . dol_escape_htmltag($endLabel) . '</th>';
	$table .= '<th width="25%">' . dol_escape_htmltag($durationLabel) . '</th>';
	$table .= '<th width="25%">' . dol_escape_htmltag($totalLabel) . '</th>';
	$table .= '</tr>';
	foreach ($records as $record) {
		$table .= '<tr>';
		$table .= '<td>' . dol_escape_htmltag(dol_print_date($record['start'], '%d/%m/%Y')) . '</td>';
		$table .= '<td>' . dol_escape_htmltag(dol_print_date($record['end'], '%d/%m/%Y')) . '</td>';
		$table .= '<td>' . dol_escape_htmltag(number_format($record['days'], 1)) . ' ' . dol_escape_htmltag($daysWord) . '</td>';
		$table .= '<td>' . dol_escape_htmltag(number_format($record['total_days'], 1)) . ' ' . dol_escape_htmltag($daysWord) . '</td>';
		$table .= '</tr>';
	}
	$table .= '</table>';
	$pdf->writeHTML($table, true, false, true, false);
	$pdf->Ln(3);
}

if ($printedEmployees === 0) {
	$pdf->SetFont('', '', 10);
	$pdf->Cell(0, 6, $noDataLabel, 0, 1, 'C');
}

$fileName = 'vacations_' . $selectedYear . '.pdf';
$pdf->Output($fileName, 'I');
exit;

if (!function_exists('dolizsynchCountWeekdays')) {
	function dolizsynchCountWeekdays($startTs, $endTs)
	{
		$startTs = (int) $startTs;
		$endTs = (int) $endTs;

		if ($endTs < $startTs) {
			return 0;
		}

		static $utcTimezone = null;
		if ($utcTimezone === null) {
			$utcTimezone = new DateTimeZone('UTC');
		}

		$startDate = new DateTime('@' . $startTs);
		$startDate->setTimezone($utcTimezone);
		$startDate->setTime(0, 0, 0);

		$endDate = new DateTime('@' . $endTs);
		$endDate->setTimezone($utcTimezone);
		$endDate->setTime(0, 0, 0);

		$diffDays = (int) $startDate->diff($endDate)->format('%a') + 1;
		$fullWeeks = (int) floor($diffDays / 7);
		$remainingDays = $diffDays % 7;

		$weekdayCount = $fullWeeks * 5;
		$startDow = (int) $startDate->format('N');

		for ($i = 0; $i < $remainingDays; $i++) {
			$weekday = (($startDow - 1 + $i) % 7) + 1;
			if ($weekday <= 5) {
				$weekdayCount++;
			}
		}

		return $weekdayCount;
	}
}

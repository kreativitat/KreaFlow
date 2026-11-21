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
 * Monthly HR summary report (schedule + punch + leaves).
 *
 * Shows per-employee overview similar to the shared spreadsheet:
 * - Employee name + code (zs_employee_id)
 * - Totals for scheduled hours, punched hours
 * - Public holidays in the month
 * - Leave buckets (vacation, sick/baixa, absences, training)
 * - Free-form observations column
 */

require '../../../main.inc.php';
require_once DOL_DOCUMENT_ROOT . '/core/lib/date.lib.php';
require_once DOL_DOCUMENT_ROOT . '/core/lib/functions2.lib.php';
require_once __DIR__ . '/../lib/shiftplanner.lib.php';

global $db, $langs, $conf, $user;

$langs->loadLangs(['main', 'errors', 'dolizsynch@kreaflow']);

if (empty($conf->dolizsynch->enabled)) {
	accessforbidden('Module KreaFlow is not enabled');
}
if (empty($user->rights->dolizsynch->shift->schedule_report_view)) {
	accessforbidden('You do not have permission to view this report');
}

// Monthly notes storage table (must exist via SQL install script)
$notesTable = MAIN_DB_PREFIX . "dolizsynch_month_notes";
$notesHasManual = false;
$colCheck = $db->query("SHOW COLUMNS FROM " . $notesTable . " LIKE 'manual_json'");
if ($colCheck && $db->num_rows($colCheck) > 0) {
	$notesHasManual = true;
}
if ($colCheck) {
	$db->free($colCheck);
}
$notesHasForced = dz_report_table_has_column($db, $notesTable, 'forced_user_ids');
$notesHasHidden = dz_report_table_has_column($db, $notesTable, 'hidden_user_ids');

// Ajax save of employee/month note/manual data
$action = GETPOST('action', 'alpha');
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($action, array('save_note', 'save_row'))) {
	$empId = (int) GETPOST('emp_id', 'int');
	$monthKey = trim((string) GETPOST('month', 'alpha'));
	$noteText = GETPOST('note', 'restricthtml');
	$manualDataRaw = ($_SERVER['REQUEST_METHOD'] === 'POST') ? GETPOST('manual_data', 'none') : null;
	if ($empId <= 0 || !preg_match('/^\d{4}-\d{2}$/', $monthKey)) {
		http_response_code(400);
		echo json_encode(['error' => 'Invalid parameters']);
		exit;
	}

	$fields = "entity, month_key, fk_user, note";
	$values = ((int) $conf->entity) . ", '" . $db->escape($monthKey) . "', " . $empId . ", '" . $db->escape($noteText) . "'";
	if ($notesHasManual) {
		$manualVal = ($manualDataRaw !== null) ? $manualDataRaw : '{}';
		$fields .= ", manual_json";
		$values .= ", '" . $db->escape($manualVal) . "'";
	}

	$sqlSave = "INSERT INTO " . $notesTable . " (" . $fields . ") VALUES (" . $values . ")";
	$sqlSave .= " ON DUPLICATE KEY UPDATE note = '" . $db->escape($noteText) . "'";
	if ($notesHasManual && $manualDataRaw !== null) {
		$sqlSave .= ", manual_json = '" . $db->escape($manualVal) . "'";
	}
	$sqlSave .= ", tms = CURRENT_TIMESTAMP";
	if (!$db->query($sqlSave)) {
		http_response_code(500);
		echo json_encode(['error' => $db->lasterror()]);
		exit;
	}

	echo json_encode(['success' => true]);
	exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'get_month_overrides') {
	$monthKey = trim((string) GETPOST('month', 'alpha'));
	if (!preg_match('/^\d{4}-\d{2}$/', $monthKey)) {
		http_response_code(400);
		echo json_encode(['error' => 'Invalid month']);
		exit;
	}
	$sql = "SELECT note";
	if ($notesHasForced) {
		$sql .= ", forced_user_ids";
	}
	if ($notesHasHidden) {
		$sql .= ", hidden_user_ids";
	}
	$sql .= " FROM " . $notesTable;
	$sql .= " WHERE month_key = '" . $db->escape($monthKey) . "'";
	$sql .= " AND fk_user = 0";
	$sql .= " AND entity IN (0, " . ((int) $conf->entity) . ")";
	$sql .= " ORDER BY entity DESC";
	$res = $db->query($sql);
	if (!$res) {
		http_response_code(500);
		echo json_encode(['error' => $db->lasterror()]);
		exit;
	}
	$forcedIds = array();
	$hiddenIds = array();
	if ($obj = $db->fetch_object($res)) {
		if ($notesHasForced) {
			$forcedIds = dz_report_parse_id_list($obj->forced_user_ids ?? '');
		}
		if ($notesHasHidden) {
			$hiddenIds = dz_report_parse_id_list($obj->hidden_user_ids ?? '');
		}
		if (!empty($obj->note)) {
			$decoded = dz_report_decode_overrides($obj->note);
			if (!empty($decoded['is_json'])) {
				if (empty($forcedIds)) {
					$forcedIds = $decoded['forced_user_ids'];
				}
				if (empty($hiddenIds)) {
					$hiddenIds = $decoded['hidden_user_ids'];
				}
			}
		}
	}
	$db->free($res);
	echo json_encode(array('forced_user_ids' => $forcedIds, 'hidden_user_ids' => $hiddenIds));
	exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'save_month_overrides') {
	$monthKey = trim((string) GETPOST('month', 'alpha'));
	if (!preg_match('/^\d{4}-\d{2}$/', $monthKey)) {
		http_response_code(400);
		echo json_encode(['error' => 'Invalid month']);
		exit;
	}
	$forcedIds = dz_report_parse_id_list(GETPOST('forced_user_ids', 'none'));
	$hiddenIds = dz_report_parse_id_list(GETPOST('hidden_user_ids', 'none'));
	$notePayload = dz_report_encode_overrides('', $forcedIds, $hiddenIds, true);

	$columns = array('entity', 'month_key', 'fk_user', 'note');
	$values = array(
		(int) $conf->entity,
		"'" . $db->escape($monthKey) . "'",
		0,
		"'" . $db->escape($notePayload) . "'",
	);
	if ($notesHasForced) {
		$forcedCsv = implode(',', array_map('intval', $forcedIds));
		$columns[] = 'forced_user_ids';
		$values[] = $forcedCsv !== '' ? "'" . $db->escape($forcedCsv) . "'" : "NULL";
	}
	if ($notesHasHidden) {
		$hiddenCsv = implode(',', array_map('intval', $hiddenIds));
		$columns[] = 'hidden_user_ids';
		$values[] = $hiddenCsv !== '' ? "'" . $db->escape($hiddenCsv) . "'" : "NULL";
	}
	$sqlSave = "REPLACE INTO " . $notesTable . " (" . implode(', ', $columns) . ")";
	$sqlSave .= " VALUES (" . implode(', ', $values) . ")";
	if (!$db->query($sqlSave)) {
		http_response_code(500);
		echo json_encode(['error' => $db->lasterror()]);
		exit;
	}
	echo json_encode(['success' => true]);
	exit;
}

$rawMonth = trim((string) GETPOST('month', 'alpha'));
if ($rawMonth === '') {
	$rawMonth = date('Y-m');
}
$monthDt = DateTime::createFromFormat('Y-m', $rawMonth);
if (!$monthDt) {
	$monthDt = new DateTime(date('Y-m-01'));
}
$monthStartDt = clone $monthDt;
$monthStartDt->setDate((int) $monthDt->format('Y'), (int) $monthDt->format('m'), 1);
$monthStartDt->setTime(0, 0, 0);
$monthEndDt = clone $monthStartDt;
$monthEndDt->modify('last day of this month');
$monthEndDt->setTime(23, 59, 59);

$monthStartTs = $monthStartDt->getTimestamp();
$monthEndTs = $monthEndDt->getTimestamp();
$monthStartSql = $db->idate($monthStartTs);
$monthEndSql = $db->idate($monthEndTs);
$monthStartIso = $monthStartDt->format('Y-m-d');
$monthEndIso = $monthEndDt->format('Y-m-d');
$monthEndExclusiveDt = clone $monthEndDt;
$monthEndExclusiveDt->modify('+1 day');
$monthEndExclusiveDt->setTime(0, 0, 0);
$monthEndExclusiveIso = $monthEndExclusiveDt->format('Y-m-d');
$monthEndExclusiveTs = $monthEndExclusiveDt->getTimestamp();
$monthKey = $monthStartDt->format('Y-m');
$dayChangeHour = shiftplanner_get_day_change_hour();
$monthStartOperationalTs = shiftplanner_operational_day_start_from_iso($monthStartIso, $dayChangeHour);
$monthEndExclusiveOperationalTs = shiftplanner_operational_day_start_from_iso($monthEndExclusiveIso, $dayChangeHour);
$monthStartOperationalSql = $db->idate($monthStartOperationalTs);
$monthEndExclusiveOperationalSql = $db->idate($monthEndExclusiveOperationalTs);

/**
 * Parse leave/summary columns configuration (computed + optional manual/built-in columns).
 *
 * Format: "Label|gray:CODE1+CODE2-CODE3;Label2|manual|u=h:Default" in DOLIZSYNCH_REPORT_LEAVE_COLUMNS.
 * Supports +, -, *, / and numeric factors. Codes refer to c_holiday_types.code (case-insensitive).
 * Unit via "|u=UNIT". "|gray" shades the column. "|manual" renders an input; text after ":" is the default value.
 * Optional flags: "|key=FIELDNAME" to control the stored key; "|w=120px" (or just number) to set input width for manual columns.
 * Add "|builtin" (or "|builtin=Scheduled") to use built-in metrics instead of leave expressions. Recognized built-ins:
 * PublicHoliday (list), PublicHolidayWorked (count of PH days with activity), PublicHolidayWorkedHours (punched hours on PH),
 * Scheduled, Worked, TrainingHours (case-insensitive). Expression aliases also expose PUBLICHOLIDAYSCHEDULEDHOURS.
 * If empty, the table renders only the base columns (no dynamic columns).
 *
 * @return array<int, array{key:string,label:string,expr:string,class:string,unit:string,mode:string,default:string,width:string,builtin?:string,renderer?:string}>
 */
function dz_report_get_leave_columns($langs)
{
	$raw = getDolGlobalString('DOLIZSYNCH_REPORT_LEAVE_COLUMNS', '');
	$columns = array();
	$builtinColumns = array(
		'publicholiday' => array('key' => 'public_holiday', 'label' => $langs->trans('PublicHoliday'), 'unit' => '', 'renderer' => 'ph_list'),
		// worked -> number of public-holiday days with activity (shift or punch)
		'publicholidayworked' => array('key' => 'public_holiday_worked', 'label' => $langs->trans('PublicHolidayWorked'), 'unit' => 'd', 'renderer' => 'ph_worked_days'),
		'publicholidayworkedhours' => array('key' => 'public_holiday_worked_hours', 'label' => $langs->trans('PublicHolidayWorkedHours'), 'unit' => 'h', 'renderer' => 'ph_worked_hours'),
		'publicholidayscheduledhours' => array('key' => 'public_holiday_scheduled_hours', 'label' => $langs->trans('PublicHolidayScheduledHours'), 'unit' => 'h', 'renderer' => 'ph_worked_hours'),
		'scheduled' => array('key' => 'scheduled', 'label' => $langs->trans('Scheduled'), 'unit' => 'h', 'renderer' => 'hours'),
		'worked' => array('key' => 'worked', 'label' => $langs->trans('Worked'), 'unit' => 'h', 'renderer' => 'hours'),
		'traininghours' => array('key' => 'training_hours', 'label' => $langs->trans('TrainingHours'), 'unit' => 'h', 'renderer' => 'hours'),
	);
	if ($raw !== '') {
		$parts = array_filter(array_map('trim', explode(';', $raw)));
		foreach ($parts as $idx => $part) {
			$sub = explode(':', $part, 2);
			$labelClass = '';
			$bgColor = '#dcdcdc'; // default swatch
			$unit = 'd'; // default days
			$unitOverridden = false;
			$mode = 'computed';
			$customKey = '';
			$width = '';
			$labelPart = trim($sub[0]);
			$explicitBuiltin = '';
			if (strpos($labelPart, '|') !== false) {
				$pieces = array_filter(array_map('trim', explode('|', $labelPart)));
				$label = array_shift($pieces);
				foreach ($pieces as $piece) {
					$pieceLower = strtolower($piece);
					if ($pieceLower === 'gray' || $pieceLower === 'grey') {
						$labelClass = 'leave-sum-col';
						$bgColor = '#e0e0e0';
					} elseif (stripos($piece, 'color=') === 0) {
						$tmpColor = trim(substr($piece, 6));
						if ($tmpColor !== '') {
							if ($tmpColor[0] !== '#') {
								$tmpColor = '#' . $tmpColor;
							}
							$bgColor = $tmpColor;
						}
					} elseif (stripos($piece, 'u=') === 0) {
						$unit = substr($piece, 2);
						$unitOverridden = true;
					} elseif ($pieceLower === 'manual') {
						$mode = 'manual';
					} elseif (stripos($piece, 'key=') === 0) {
						$customKey = preg_replace('/[^A-Z0-9_]/i', '', substr($piece, 4));
					} elseif (stripos($piece, 'w=') === 0) {
						$width = trim(substr($piece, 2));
						if ($width !== '' && ctype_digit($width)) {
							$width .= 'px';
						}
					} elseif ($pieceLower === 'builtin') {
						$mode = 'builtin';
					} elseif (stripos($piece, 'builtin=') === 0 || stripos($piece, 'type=') === 0) {
						$mode = 'builtin';
						$explicitBuiltin = strtolower(substr($piece, strpos($piece, '=') + 1));
					}
				}
			} else {
				$label = $labelPart;
			}
			if ($label === '') {
				$label = 'Column ' . ($idx + 1);
			}
			$rawExpr = isset($sub[1]) ? trim($sub[1]) : '';
			$expr = ($mode === 'manual') ? '' : $rawExpr;
			$keyName = $customKey !== '' ? $customKey : ('col_' . ($idx + 1));

			$labelKey = strtolower($label);
			// Built-in column detection (by option or by label when no expression)
			if ($mode === 'builtin' || (($rawExpr === '' || $rawExpr === null) && isset($builtinColumns[$labelKey]))) {
				$builtinKey = $explicitBuiltin !== '' ? $explicitBuiltin : $labelKey;
				if (!isset($builtinColumns[$builtinKey]) && isset($builtinColumns[$labelKey])) {
					$builtinKey = $labelKey;
				}
				if (isset($builtinColumns[$builtinKey])) {
					$builtinDef = $builtinColumns[$builtinKey];
					if ($customKey === '') {
						$keyName = $builtinDef['key'];
					}
					$label = $builtinDef['label'];
					$unitToUse = $unitOverridden ? $unit : (isset($builtinDef['unit']) ? $builtinDef['unit'] : $unit);
					$columns[] = array(
						'key' => $keyName,
						'label' => $label,
						'expr' => '',
						'class' => $labelClass,
						'color' => $bgColor,
						'unit' => $unitToUse,
						'mode' => 'builtin',
						'default' => '',
						'width' => $width,
						'builtin' => $builtinKey,
						'renderer' => $builtinDef['renderer'],
					);
					continue;
				}
				// Fall back to computed if builtin key is unknown
				$mode = 'computed';
			}

			$columns[] = array(
				'key' => $keyName,
				'label' => $label,
				'expr' => $mode === 'manual' ? '' : $expr,
				'class' => $labelClass,
				'color' => $bgColor,
				'unit' => $unit,
				'mode' => $mode,
				'default' => $mode === 'manual' ? $rawExpr : '',
				'width' => $width,
			);
		}
	}

	// Keep absence totals consistent with configured absence buckets:
	// - If "Falta jus. Paga" contains FALTA_JUS, include it in Total F and Total(h)
	// - If "Falta injus." contains LIC_SR/OUTRAS_LIC, include them in Total F and Total(h)
	if (!empty($columns)) {
		$paidExprUpper = '';
		$unjustExprUpper = '';
		foreach ($columns as $colMeta) {
			$modeVal = isset($colMeta['mode']) ? $colMeta['mode'] : 'computed';
			if ($modeVal !== 'computed') {
				continue;
			}
			$labelNorm = strtolower(trim(preg_replace('/\s+/', ' ', (string) ($colMeta['label'] ?? ''))));
			$exprUpper = strtoupper(str_replace(' ', '', (string) ($colMeta['expr'] ?? '')));
			if ($exprUpper === '') {
				continue;
			}
			if (strpos($labelNorm, 'falta jus') !== false && strpos($labelNorm, 'paga') !== false) {
				$paidExprUpper = $exprUpper;
			}
			if (
				strpos($labelNorm, 'falta') !== false &&
				(strpos($labelNorm, 'injus') !== false || strpos($labelNorm, 'injust') !== false || strpos($labelNorm, 'unjust') !== false)
			) {
				$unjustExprUpper = $exprUpper;
			}
		}

		$mustIncludeFaltaJus = (strpos($paidExprUpper, 'FALTA_JUS') !== false);
		$mustIncludeFaltaInjus = (strpos($unjustExprUpper, 'FALTA_INJUS') !== false);
		$mustIncludeLicSr = (strpos($unjustExprUpper, 'LIC_SR') !== false);
		$mustIncludeOutrasLic = (strpos($unjustExprUpper, 'OUTRAS_LIC') !== false);

		$containsToken = function ($exprUpperNoSpaces, $token) {
			return preg_match('/(?<![A-Z0-9_])' . preg_quote($token, '/') . '(?![A-Z0-9_])/', $exprUpperNoSpaces) === 1;
		};
		$appendToken = function ($expr, $token) use ($containsToken) {
			$exprTrim = trim((string) $expr);
			if ($exprTrim === '') {
				return $token;
			}
			$exprUpper = strtoupper(str_replace(' ', '', $exprTrim));
			if ($containsToken($exprUpper, $token)) {
				return $exprTrim;
			}
			return $exprTrim . '+' . $token;
		};

		foreach ($columns as &$colMeta) {
			$modeVal = isset($colMeta['mode']) ? $colMeta['mode'] : 'computed';
			if ($modeVal !== 'computed') {
				continue;
			}
			$labelNorm = strtolower(trim(preg_replace('/\s+/', ' ', (string) ($colMeta['label'] ?? ''))));
			$expr = trim((string) ($colMeta['expr'] ?? ''));
			if ($expr === '') {
				continue;
			}
			$exprUpper = strtoupper(str_replace(' ', '', $expr));
			$labelNoUnit = trim((string) preg_replace('/\(\s*[dh]\s*\)$/i', '', $labelNorm));
			$isFaltaJusPagaLabel = (strpos($labelNoUnit, 'falta jus') !== false && strpos($labelNoUnit, 'paga') !== false);
			$isFaltaInjusLabel = (
				strpos($labelNoUnit, 'falta') !== false &&
				(strpos($labelNoUnit, 'injus') !== false || strpos($labelNoUnit, 'injust') !== false || strpos($labelNoUnit, 'unjust') !== false)
			);
			$isFaltaJusLabel = (strpos($labelNoUnit, 'falta jus') !== false && !$isFaltaJusPagaLabel && !$isFaltaInjusLabel);
			if ($isFaltaJusPagaLabel || $isFaltaJusLabel || $isFaltaInjusLabel) {
				$colMeta['unit'] = 'h';
				if (strpos($exprUpper, 'HC/5') === false) {
					$expr = '(' . $expr . ')*HC/5';
					$colMeta['expr'] = $expr;
					$exprUpper = strtoupper(str_replace(' ', '', $expr));
				}
			}
			$isAbsenceFormula = (
				strpos($exprUpper, 'FJUS_') !== false ||
				strpos($exprUpper, 'FALTA_') !== false ||
				strpos($exprUpper, 'LIC_SR') !== false ||
				strpos($exprUpper, 'OUTRAS_LIC') !== false
			);
			$isTotalF = ($labelNorm === 'total f');
			$isAbsenceHoursTotal = ($labelNorm === 'total' && strpos($exprUpper, 'HC/5') !== false && $isAbsenceFormula);
			if (!$isTotalF && !$isAbsenceHoursTotal) {
				continue;
			}

			$baseExpr = $expr;
			if ($isAbsenceHoursTotal) {
				if (preg_match('/^\((.*)\)\s*\*\s*HC\s*\/\s*5$/i', $expr, $m)) {
					$baseExpr = trim($m[1]);
				} else {
					$baseExpr = trim((string) preg_replace('/\s*\*\s*HC\s*\/\s*5\s*$/i', '', $expr));
				}
			}

			if ($mustIncludeFaltaJus) {
				$baseExpr = $appendToken($baseExpr, 'FALTA_JUS');
			}
			if ($mustIncludeFaltaInjus) {
				$baseExpr = $appendToken($baseExpr, 'FALTA_INJUS');
			}
			if ($mustIncludeLicSr) {
				$baseExpr = $appendToken($baseExpr, 'LIC_SR');
			}
			if ($mustIncludeOutrasLic) {
				$baseExpr = $appendToken($baseExpr, 'OUTRAS_LIC');
			}

			$colMeta['expr'] = $isAbsenceHoursTotal ? ('(' . $baseExpr . ')*HC/5') : $baseExpr;
		}
		unset($colMeta);
	}

	return $columns;
}
$leaveColumns = dz_report_get_leave_columns($langs);
$hasManualColumns = false;
$hasHolidayColumns = false;
$hasPublicHolidayListColumn = false;
$hasPublicHolidayScheduledHoursColumn = false;
$hasPublicHolidayExprColumn = false;
$hasTrainingHoursColumn = false;
foreach ($leaveColumns as $c) {
	$modeVal = isset($c['mode']) ? $c['mode'] : '';
	if ($modeVal === 'manual') {
		$hasManualColumns = true;
	}
	if ($modeVal === 'builtin' && isset($c['builtin'])) {
		if (in_array($c['builtin'], array('publicholiday', 'publicholidayworked', 'publicholidayworkedhours'), true)) {
			$hasHolidayColumns = true;
		}
		if ($c['builtin'] === 'publicholiday') {
			$hasPublicHolidayListColumn = true;
		}
		if ($c['builtin'] === 'publicholidayscheduledhours') {
			$hasPublicHolidayScheduledHoursColumn = true;
		}
		if ($c['builtin'] === 'traininghours') {
			$hasTrainingHoursColumn = true;
		}
	}
	$exprRaw = isset($c['expr']) ? trim($c['expr']) : '';
	$exprNorm = strtoupper(str_replace(' ', '', $exprRaw));
	if ($exprNorm === 'PUBLICHOLIDAY' || $exprNorm === 'PUBLIC_HOLIDAY') {
		$hasPublicHolidayExprColumn = true;
	}
}

// Ensure training hours are visible even if not configured
if (!$hasTrainingHoursColumn) {
	$leaveColumns[] = array(
		'key' => 'training_hours',
		'label' => $langs->trans('TrainingHours'),
		'expr' => '',
		'class' => '',
		'color' => '',
		'unit' => 'h',
		'mode' => 'builtin',
		'default' => '',
		'width' => '',
		'builtin' => 'traininghours',
		'renderer' => 'hours',
	);
}

$unjustAbsenceColumnKeys = array();
$unjustAbsenceCodes = array();
$unjustAbsenceDetected = false;
$unjustAbsenceBuiltinTokens = array(
	'SCHEDULED',
	'WORKED',
	'HE',
	'HC',
	'TRAININGHOURS',
	'TRAINING_HOURS',
	'PUBLICHOLIDAY',
	'PUBLICHOLIDAYWORKED',
	'PUBLICHOLIDAYWORKEDHOURS',
	'PUBLICHOLIDAYSCHEDULEDHOURS',
	'PUBLIC_HOLIDAY',
	'PUBLIC_HOLIDAY_WORKED',
	'PUBLIC_HOLIDAY_WORKED_HOURS',
	'PUBLIC_HOLIDAY_SCHEDULED_HOURS',
);
foreach ($leaveColumns as $col) {
	$label = isset($col['label']) ? $col['label'] : '';
	if (!dz_report_is_unjustified_label($label)) {
		continue;
	}
	$unjustAbsenceDetected = true;
	$modeVal = isset($col['mode']) ? $col['mode'] : 'computed';
	if ($modeVal !== 'computed') {
		continue;
	}
	$unjustAbsenceColumnKeys[] = $col['key'];
	$expr = isset($col['expr']) ? $col['expr'] : '';
	$codes = dz_report_extract_expr_codes($expr, $unjustAbsenceBuiltinTokens);
	foreach ($codes as $codeToken) {
		$unjustAbsenceCodes[$codeToken] = true;
	}
}
$unjustAbsenceColumnKeys = array_values(array_unique($unjustAbsenceColumnKeys));

$monthKey = $monthStartDt->format('Y-m');
if ($hasManualColumns && !$notesHasManual) {
	setEventMessages($langs->trans('ManualColumnsStorageMissing'), null, 'warnings');
}


/**
 * Classify a leave into a bucket based on code/label.
 */
function dz_report_classify_leave($code, $label)
{
	$haystack = strtolower(trim((string) $code . ' ' . $label));
	if ($haystack === '') {
		return 'other';
	}
	if (strpos($haystack, 'féri') !== false || strpos($haystack, 'feria') !== false || strpos($haystack, 'vac') !== false || strpos($haystack, 'cp') !== false) {
		return 'vacation';
	}
	if (strpos($haystack, 'baixa') !== false || strpos($haystack, 'sick') !== false || strpos($haystack, 'doen') !== false || strpos($haystack, 'socia') !== false) {
		return 'sick';
	}
	if (strpos($haystack, 'falta') !== false || strpos($haystack, 'absenc') !== false) {
		return 'absence';
	}
	if (strpos($haystack, 'forma') !== false || strpos($haystack, 'train') !== false) {
		return 'training';
	}
	return 'other';
}

/**
 * Format a date range label.
 */
function dz_report_format_range($startTs, $endTs)
{
	if (empty($startTs) || empty($endTs)) {
		return '';
	}
	$start = dol_print_date($startTs, 'dayrfc');
	$end = dol_print_date($endTs, 'dayrfc');
	return ($start === $end) ? $start : ($start . ' → ' . $end);
}

/**
 * Sum overlap (seconds) between an interval and the target month.
 */
function dz_report_overlap_seconds($startTs, $endTs, $monthStartTs, $monthEndTs)
{
	if ($endTs < $monthStartTs || $startTs > $monthEndTs) {
		return 0;
	}
	$from = max($startTs, $monthStartTs);
	$to = min($endTs, $monthEndTs);
	return max(0, ($to - $from));
}

if (!function_exists('dz_report_count_weekdays')) {
	/**
	 * Count weekdays between two timestamps (inclusive).
	 */
function dz_report_count_weekdays($startTs, $endTs)
{
	$startTs = (int) $startTs;
	$endTs = (int) $endTs;
	if ($endTs < $startTs) {
			return 0;
		}
		$start = new DateTime('@' . $startTs);
		$start->setTime(0, 0, 0);
		$end = new DateTime('@' . $endTs);
		$end->setTime(0, 0, 0);
		$diffDays = (int) $start->diff($end)->format('%a') + 1;
		$fullWeeks = (int) floor($diffDays / 7);
		$remaining = $diffDays % 7;
		$weekdayCount = $fullWeeks * 5;
		$startDow = (int) $start->format('N'); // 1=Mon .. 7=Sun
		for ($i = 0; $i < $remaining; $i++) {
			$dow = (($startDow - 1 + $i) % 7) + 1;
			if ($dow <= 5) {
				$weekdayCount++;
			}
		}
return $weekdayCount;
}

}

/**
 * Check if a table has a given column.
 */
function dz_report_table_has_column(DoliDB $db, $table, $column)
{
	$sql = "SHOW COLUMNS FROM " . $table . " LIKE '" . $db->escape($column) . "'";
	$res = $db->query($sql);
	if (!$res) {
		return false;
	}
	$has = ($db->num_rows($res) > 0);
	$db->free($res);
	return $has;
}

/**
 * Parse comma-separated or array id list into ints.
 *
 * @param mixed $raw
 * @return array<int,int>
 */
function dz_report_parse_id_list($raw)
{
	$values = array();
	if (is_array($raw)) {
		$values = $raw;
	} else {
		$rawStr = trim((string) $raw);
		if ($rawStr === '') {
			return array();
		}
		$values = preg_split('/\s*,\s*/', $rawStr);
	}
	$ids = array();
	foreach ($values as $value) {
		$id = (int) $value;
		if ($id > 0) {
			$ids[$id] = $id;
		}
	}
	return array_values($ids);
}

/**
 * Decode overrides payload stored in note JSON.
 *
 * @param string $raw
 * @return array{forced_user_ids:array<int,int>, hidden_user_ids:array<int,int>, is_json:bool}
 */
function dz_report_decode_overrides($raw)
{
	$result = array(
		'forced_user_ids' => array(),
		'hidden_user_ids' => array(),
		'is_json' => false,
	);
	$rawStr = (string) $raw;
	if ($rawStr === '') {
		return $result;
	}
	$decoded = json_decode($rawStr, true);
	if (
		is_array($decoded) &&
		(array_key_exists('forced_user_ids', $decoded) || array_key_exists('hidden_user_ids', $decoded))
	) {
		$result['is_json'] = true;
		$forced = array();
		if (isset($decoded['forced_user_ids']) && is_array($decoded['forced_user_ids'])) {
			$forced = $decoded['forced_user_ids'];
		}
		$hidden = array();
		if (isset($decoded['hidden_user_ids']) && is_array($decoded['hidden_user_ids'])) {
			$hidden = $decoded['hidden_user_ids'];
		}
		$result['forced_user_ids'] = dz_report_parse_id_list($forced);
		$result['hidden_user_ids'] = dz_report_parse_id_list($hidden);
	}
	return $result;
}

/**
 * Encode overrides payload as JSON when needed.
 *
 * @param string $note
 * @param array<int,int> $forcedIds
 * @param array<int,int> $hiddenIds
 * @param bool $forceJson
 * @return string
 */
function dz_report_encode_overrides($note, array $forcedIds = array(), array $hiddenIds = array(), $forceJson = false)
{
	$noteText = is_string($note) ? $note : (string) $note;
	$forcedIds = dz_report_parse_id_list($forcedIds);
	$hiddenIds = dz_report_parse_id_list($hiddenIds);
	if (!$forceJson && empty($forcedIds) && empty($hiddenIds)) {
		return $noteText;
	}
	$payload = array(
		'forced_user_ids' => $forcedIds,
		'hidden_user_ids' => $hiddenIds,
	);
	$encoded = json_encode($payload, JSON_UNESCAPED_UNICODE);
	if ($encoded === false) {
		return $noteText;
	}
	return $encoded;
}

/**
 * Extract identifier tokens from an expression (excluding known builtins).
 *
 * @param string $expr
 * @param array<int,string> $excludeTokens
 * @return array<int,string>
 */
function dz_report_extract_expr_codes($expr, $excludeTokens = array())
{
	$expr = strtoupper(str_replace(' ', '', (string) $expr));
	if ($expr === '') {
		return array();
	}
	$tokens = array();
	if (preg_match_all('/[A-Z0-9_]+/', $expr, $m)) {
		$tokens = $m[0];
	}
	if (empty($tokens)) {
		return array();
	}
	$excludeSet = array();
	foreach ($excludeTokens as $t) {
		$excludeSet[strtoupper($t)] = true;
	}
	$result = array();
	foreach ($tokens as $tok) {
		if ($tok === '' || ctype_digit($tok)) {
			continue;
		}
		if (isset($excludeSet[$tok])) {
			continue;
		}
		$result[$tok] = true;
	}
	return array_keys($result);
}

/**
 * True when a label references unjustified absence.
 */
function dz_report_is_unjustified_label($label)
{
	$label = strtolower(trim((string) $label));
	if ($label === '') {
		return false;
	}
	if (strpos($label, 'falta') === false && strpos($label, 'absence') === false) {
		return false;
	}
	return (strpos($label, 'injus') !== false || strpos($label, 'injust') !== false || strpos($label, 'unjust') !== false);
}

/**
 * True when a label references justified absence.
 */
function dz_report_is_justified_label($label)
{
	$label = strtolower(trim((string) $label));
	if ($label === '') {
		return false;
	}
	if (strpos($label, 'falta') === false && strpos($label, 'absence') === false) {
		return false;
	}
	if (strpos($label, 'injus') !== false || strpos($label, 'injust') !== false || strpos($label, 'unjust') !== false) {
		return false;
	}
	return (strpos($label, 'jus') !== false || strpos($label, 'just') !== false);
}

/**
 * Load day-offs (including recurring) into a per-employee date map.
 *
 * @return array<int,array<string,bool>>
 */
function dz_report_load_dayoff_map(DoliDB $db, $startTs, $endTs, $entityId = 0, $employeeIds = array())
{
	$map = array();
	$table = MAIN_DB_PREFIX . "dolizsynch_dayoff";
	$startSql = $db->escape(dol_print_date($startTs, '%Y-%m-%d'));
	$endSql = $db->escape(dol_print_date($endTs, '%Y-%m-%d'));
	$hasEntity = dz_report_table_has_column($db, $table, 'entity');

	$sql = "SELECT rowid, fk_user, date_dayoff, is_recurring FROM " . $table;
	$sql .= " WHERE ((is_recurring = 0 AND date_dayoff >= '" . $startSql . "' AND date_dayoff <= '" . $endSql . "')";
	$sql .= " OR (is_recurring = 1 AND date_dayoff <= '" . $endSql . "'))";
	if ($hasEntity && ((int) $entityId) > 0) {
		$sql .= " AND (entity IS NULL OR entity IN (0, " . ((int) $entityId) . "))";
	}
	if (!empty($employeeIds)) {
		$sql .= " AND fk_user IN (" . implode(',', array_map('intval', $employeeIds)) . ")";
	}
	$sql .= " ORDER BY date_dayoff";

	$resql = $db->query($sql);
	if (!$resql) {
		return $map;
	}

	while ($obj = $db->fetch_object($resql)) {
		$userId = (int) $obj->fk_user;
		if (!isset($map[$userId])) {
			$map[$userId] = array();
		}
		$baseTs = dol_stringtotime($obj->date_dayoff);
		if (!$baseTs) {
			continue;
		}
		$isRecurring = ((int) $obj->is_recurring === 1);
		if ($isRecurring) {
			$baseDow = (int) date('w', $baseTs);
			for ($ts = $startTs; $ts <= $endTs; $ts += 86400) {
				if ($ts < $baseTs) {
					continue;
				}
				if ((int) date('w', $ts) !== $baseDow) {
					continue;
				}
				$dateIso = date('Y-m-d', $ts);
				$map[$userId][$dateIso] = true;
			}
		} else {
			if ($baseTs < $startTs || $baseTs > $endTs) {
				continue;
			}
			$dateIso = date('Y-m-d', $baseTs);
			$map[$userId][$dateIso] = true;
		}
	}
	$db->free($resql);

	return $map;
}

/**
 * Resolve configured employee group ids for shift planner.
 *
 * @return array<int,int>
 */
function dz_report_get_employee_group_ids()
{
	$raw = trim((string) getDolGlobalString('DOLIZSYNCH_SHIFT_EMPLOYEE_GROUPS', ''));
	$ids = array();
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

/**
 * Evaluate a leave expression using code totals.
 * Supports +, -, *, / and numeric constants. Identifiers map to leave code totals (uppercased).
 *
 * @param string $expr
 * @param array<string,float> $codeTotals
 * @return float
 */
function dz_report_eval_leave_expression($expr, $codeTotals)
{
	$expr = strtoupper(str_replace(' ', '', (string) $expr));
	if ($expr === '') {
		return 0.0;
	}

	$tokens = array();
	if (preg_match_all('/([A-Z0-9_]+|\\d+(?:\\.\\d+)?|[()+\\-*\\/])/', $expr, $m)) {
		$tokens = $m[0];
	}
	if (empty($tokens)) {
		return 0.0;
	}

	$precedence = array('+' => 1, '-' => 1, '*' => 2, '/' => 2);
	$output = array();
	$stack = array();
	$prevType = 'op';

	foreach ($tokens as $tok) {
		if (preg_match('/^\\d+(?:\\.\\d+)?$/', $tok)) {
			$output[] = $tok;
			$prevType = 'operand';
		} elseif (preg_match('/^[A-Z0-9_]+$/', $tok)) {
			$output[] = $tok;
			$prevType = 'operand';
		} elseif ($tok === '(') {
			$stack[] = $tok;
			$prevType = 'lparen';
		} elseif ($tok === ')') {
			while (!empty($stack) && end($stack) !== '(') {
				$output[] = array_pop($stack);
			}
			if (!empty($stack) && end($stack) === '(') {
				array_pop($stack);
			}
			$prevType = 'rparen';
		} elseif (isset($precedence[$tok])) {
			if ($tok === '-' && ($prevType === 'op' || $prevType === 'lparen')) {
				$output[] = '0';
			}
			while (!empty($stack)) {
				$top = end($stack);
				if (isset($precedence[$top]) && $precedence[$top] >= $precedence[$tok]) {
					$output[] = array_pop($stack);
				} else {
					break;
				}
			}
			$stack[] = $tok;
			$prevType = 'op';
		}
	}
	while (!empty($stack)) {
		$output[] = array_pop($stack);
	}

	$evalStack = array();
	foreach ($output as $item) {
		if (isset($precedence[$item])) {
			$b = array_pop($evalStack);
			$a = array_pop($evalStack);
			if ($a === null) {
				$a = 0;
			}
			if ($b === null) {
				$b = 0;
			}
			switch ($item) {
				case '+':
					$evalStack[] = $a + $b;
					break;
				case '-':
					$evalStack[] = $a - $b;
					break;
				case '*':
					$evalStack[] = $a * $b;
					break;
				case '/':
					$evalStack[] = ($b == 0) ? 0 : ($a / $b);
					break;
			}
		} else {
			if (preg_match('/^\\d+(?:\\.\\d+)?$/', $item)) {
				$evalStack[] = (float) $item;
			} else {
				$evalStack[] = isset($codeTotals[$item]) ? (float) $codeTotals[$item] : 0.0;
			}
		}
	}

	return isset($evalStack[0]) ? (float) $evalStack[0] : 0.0;
}

/**
 * Format a number removing trailing zeros (e.g., 40.0 -> 40, 40.5 -> 40.5).
 *
 * @param float|int $value
 * @param int $decimals
 * @return string
 */
function dz_report_format_number($value, $decimals = 2)
{
	$formatted = number_format((float) $value, $decimals, '.', '');
	$formatted = rtrim(rtrim($formatted, '0'), '.');
	if ($formatted === '' || $formatted === '-0') {
		$formatted = '0';
	}
	return $formatted;
}

/**
 * Format a number with fixed decimals (table display).
 *
 * @param float|int $value
 * @param int $decimals
 * @return string
 */
function dz_report_format_number_fixed($value, $decimals = 2)
{
	return number_format((float) $value, $decimals, '.', '');
}

/**
 * Normalize dynamic column header labels with unit suffix.
 *
 * @param string $label
 * @param string $unit
 * @return string
 */
function dz_report_column_label_with_unit($label, $unit)
{
	$label = trim((string) $label);
	$unit = strtoupper(trim((string) $unit));
	if ($label === '' || ($unit !== 'D' && $unit !== 'H')) {
		return $label;
	}
	if (preg_match('/\(\s*[DH]\s*\)$/i', $label)) {
		return preg_replace_callback('/\(\s*([DH])\s*\)$/i', function ($m) {
			return '(' . strtoupper($m[1]) . ')';
		}, $label);
	}
	return $label . ' (' . $unit . ')';
}

// Fetch active employees for the current entity with zs_employee_id
$employees = [];
	$employeesByCode = [];
	$employeeShiftDays = [];
	$employeeShiftDaySeconds = [];
	$employeeShiftIntervals = [];
	$employeePunchDaySeconds = [];
	$employeePunchIntervals = [];
	$employeeLeaveCodeTotals = [];
	$employeeTrainingHours = [];
	$currentEntity = (int) $conf->entity;
$employeeGroupIds = dz_report_get_employee_group_ids();
$sqlEmployees = "SELECT DISTINCT u.rowid, u.firstname, u.lastname, u.entity, ue.zs_employee_id, u.datec, u.datestartvalidity, u.dateendvalidity, u.dateemployment, u.dateemploymentend, ue.horasefect, u.weeklyhours";
$sqlEmployees .= " FROM " . MAIN_DB_PREFIX . "user u";
$sqlEmployees .= " LEFT JOIN " . MAIN_DB_PREFIX . "usergroup_user ugu";
$sqlEmployees .= " ON ugu.fk_user = u.rowid";
if (!empty($employeeGroupIds)) {
	$sqlEmployees .= " AND ugu.fk_usergroup IN (" . implode(',', array_map('intval', $employeeGroupIds)) . ")";
}
$sqlEmployees .= " AND ugu.entity = " . $currentEntity;
$sqlEmployees .= " LEFT JOIN " . MAIN_DB_PREFIX . "user_extrafields ue ON ue.fk_object = u.rowid";
$sqlEmployees .= " WHERE COALESCE(ue.zs_user_hide, 0) = 0";
$sqlEmployees .= " AND ugu.fk_user IS NOT NULL";
// Employee validity window must overlap the report month
$sqlEmployees .= " AND (u.dateemployment IS NULL OR u.dateemployment = '0000-00-00' OR u.dateemployment <= '" . $db->escape($monthEndIso) . "')";
$sqlEmployees .= " AND (u.dateemploymentend IS NULL OR u.dateemploymentend = '0000-00-00' OR u.dateemploymentend > '" . $db->escape($monthStartIso) . "')";
$sqlEmployees .= " ORDER BY u.firstname, u.lastname";

$resEmp = $db->query($sqlEmployees);
if ($resEmp) {
	while ($obj = $db->fetch_object($resEmp)) {
		$empId = (int) $obj->rowid;
		$code = ($obj->zs_employee_id !== null && $obj->zs_employee_id !== '') ? $obj->zs_employee_id : '';
		$validityStartRaw = property_exists($obj, 'datestartvalidity') ? $obj->datestartvalidity : null;
		$validityEndRaw = property_exists($obj, 'dateendvalidity') ? $obj->dateendvalidity : null;
		$employmentRaw = property_exists($obj, 'dateemployment') ? $obj->dateemployment : null;

		$validityStartTs = (!empty($validityStartRaw) && $validityStartRaw !== '0000-00-00' && $validityStartRaw !== '0000-00-00 00:00:00') ? (int) $db->jdate($validityStartRaw) : null;
		$validityEndTs = (!empty($validityEndRaw) && $validityEndRaw !== '0000-00-00' && $validityEndRaw !== '0000-00-00 00:00:00') ? (int) $db->jdate($validityEndRaw) : null;
		$employmentTs = (!empty($employmentRaw) && $employmentRaw !== '0000-00-00' && $employmentRaw !== '0000-00-00 00:00:00') ? (int) $db->jdate($employmentRaw) : null;
		$admissionTs = $validityStartTs ?: $employmentTs;
		if (!$admissionTs && !empty($obj->datec)) {
			$admissionTs = (int) $db->jdate($obj->datec);
		}
		$record = [
			'id' => $empId,
			'name' => trim(($obj->firstname ?: '') . ' ' . ($obj->lastname ?: '')),
			'code' => $code,
			'entity' => (int) $obj->entity,
				'admission' => $admissionTs,
				'validity_start' => $validityStartTs,
				'validity_end' => $validityEndTs,
				'horasefect' => property_exists($obj, 'horasefect') ? $obj->horasefect : '',
			'weeklyhours' => property_exists($obj, 'weeklyhours') ? $obj->weeklyhours : '',
			'shifts_seconds' => 0,
			'punch_seconds' => 0,
			'leaves_dynamic' => array(),
			'manual_values' => array(),
			'note' => '',
			'training_hours' => 0.0,
		];
		foreach ($leaveColumns as $col) {
			$modeVal = isset($col['mode']) ? $col['mode'] : 'computed';
			if ($modeVal === 'manual') {
				$record['manual_values'][$col['key']] = isset($col['default']) ? $col['default'] : '';
			} else {
				$record['leaves_dynamic'][$col['key']] = null;
			}
		}
		$employees[$empId] = $record;
		if ($code !== '') {
			$employeesByCode[(string) $code] = $empId;
		}
	}

// Load existing notes/manual data for the month
$sqlNotes = "SELECT fk_user, note";
if ($notesHasManual) {
	$sqlNotes .= ", manual_json";
}
$sqlNotes .= " FROM " . $notesTable . " WHERE entity = " . ((int) $conf->entity) . " AND month_key = '" . $db->escape($monthKey) . "'";
$resNotes = $db->query($sqlNotes);
if ($resNotes) {
	while ($obj = $db->fetch_object($resNotes)) {
		$uid = (int) $obj->fk_user;
		if (isset($employees[$uid])) {
			$employees[$uid]['note'] = $obj->note ?: '';
			if ($notesHasManual && isset($obj->manual_json) && $obj->manual_json !== null) {
				$decoded = json_decode($obj->manual_json, true);
				if (is_array($decoded)) {
					foreach ($decoded as $k => $v) {
						if (isset($employees[$uid]['manual_values'][$k])) {
							$employees[$uid]['manual_values'][$k] = $v;
						}
					}
				}
			}
		}
	}
	$db->free($resNotes);
}
	$db->free($resEmp);
}

$hasUnjustAbsenceColumns = !empty($unjustAbsenceColumnKeys);
$countDayoffAsAbsence = ((int) getDolGlobalInt('DOLIZSYNCH_REPORT_DAYOFF_AS_ABSENCE', 1) === 1);
$employeeDayoffMap = array();
$employeeUnjustAbsenceDays = array();
$employeeDayoffAbsenceCount = array();
$employeeUnjustAbsenceCodeCounts = array();
$absenceAdjStartTs = $monthStartOperationalTs - 86400;
$absenceAdjEndExclusiveTs = $monthEndExclusiveOperationalTs + 86400;
if ($hasUnjustAbsenceColumns && $countDayoffAsAbsence && !empty($employees)) {
	$employeeDayoffMap = dz_report_load_dayoff_map($db, $monthStartTs, $monthEndTs, 0, array_keys($employees));
}

// Sum scheduled hours (shift planner)
if (!empty($employees)) {
	$shiftTable = MAIN_DB_PREFIX . "dolizsynch_shift";
	$shiftHasEntity = dz_report_table_has_column($db, $shiftTable, 'entity');
	$shiftHasStatus = dz_report_table_has_column($db, $shiftTable, 'shift_status');
	$sqlShift = "SELECT fk_user, date_start, date_end";
	if ($shiftHasStatus) {
		$sqlShift .= ", shift_status";
	}
	$sqlShift .= " FROM " . $shiftTable;
	$sqlShift .= " WHERE date_end > '" . $db->escape($monthStartOperationalSql) . "'";
	$sqlShift .= " AND date_start < '" . $db->escape($monthEndExclusiveOperationalSql) . "'";
	if ($shiftHasEntity) {
		$sqlShift .= " AND entity = " . ((int) $conf->entity);
	}
	if ($shiftHasStatus) {
		$sqlShift .= " AND (shift_status IS NULL OR TRIM(shift_status) = '' OR LOWER(TRIM(shift_status)) NOT IN ('missed', 'missed_unjustified', 'missed_justified'))";
	}

	$resShift = $db->query($sqlShift);
	if ($resShift) {
		while ($obj = $db->fetch_object($resShift)) {
			$userId = (int) $obj->fk_user;
			if (!isset($employees[$userId])) {
				continue;
			}
			$startTs = shiftplannerNormalizeTimestamp($db, $obj->date_start);
			$endTs = shiftplannerNormalizeTimestamp($db, $obj->date_end);
			if (!$startTs || !$endTs) {
				continue;
			}
			$employeeShiftIntervals[$userId][] = array('start' => $startTs, 'end' => $endTs);
			$employees[$userId]['shifts_seconds'] += dz_report_overlap_seconds($startTs, $endTs, $monthStartOperationalTs, $monthEndExclusiveOperationalTs);
			// Track each calendar date (within the month) that has a scheduled shift for this employee
			$clampedStart = max($startTs, $monthStartOperationalTs);
			$clampedEnd = min($endTs, $monthEndExclusiveOperationalTs);
			if ($clampedEnd > $clampedStart) {
				$dayCursor = shiftplanner_operational_boundary_for_timestamp($clampedStart, $dayChangeHour);
				while ($dayCursor < $clampedEnd) {
					$dayStart = $dayCursor;
					$dayEnd = $dayCursor + 86400;
					$overlap = max(0, min($clampedEnd, $dayEnd) - max($clampedStart, $dayStart));
					$dateIso = date('Y-m-d', $dayStart);
					$employeeShiftDays[$userId][$dateIso] = true;
					$employeeShiftDaySeconds[$userId][$dateIso] = ($employeeShiftDaySeconds[$userId][$dateIso] ?? 0) + $overlap;
					$dayCursor += 86400;
				}
			}
		}
		$db->free($resShift);
	}
}

// Sum punched hours
if (!empty($employees)) {
	$sqlPunch = "SELECT fk_user, empid_zs, dataval, datahora_in, datahora_out, duration";
	$sqlPunch .= " FROM " . MAIN_DB_PREFIX . "dolizsynch_zspunchclock";
	$sqlPunch .= " WHERE datahora_in IS NOT NULL";
	$sqlPunch .= " AND datahora_out IS NOT NULL";
	$sqlPunch .= " AND datahora_out > '" . $db->escape($monthStartOperationalSql) . "'";
	$sqlPunch .= " AND datahora_in < '" . $db->escape($monthEndExclusiveOperationalSql) . "'";

	$resPunch = $db->query($sqlPunch);
	if ($resPunch) {
		while ($obj = $db->fetch_object($resPunch)) {
			$userId = (int) $obj->fk_user;
			if (!$userId && !empty($obj->empid_zs) && isset($employeesByCode[(string) $obj->empid_zs])) {
				$userId = (int) $employeesByCode[(string) $obj->empid_zs];
			}
			if (!$userId || !isset($employees[$userId])) {
				// Fallback: if fk_user not in report set, try mapping by empid_zs
				if (!empty($obj->empid_zs) && isset($employeesByCode[(string) $obj->empid_zs])) {
					$userId = (int) $employeesByCode[(string) $obj->empid_zs];
				}
				if (!$userId || !isset($employees[$userId])) {
					continue;
				}
			}
				if (!empty($obj->datahora_in) && !empty($obj->datahora_out)) {
					$startTs = strtotime($obj->datahora_in);
					$endTs = strtotime($obj->datahora_out);
					if ($startTs && $endTs && $endTs > $startTs) {
						$employeePunchIntervals[$userId][] = array('start' => $startTs, 'end' => $endTs);
						$overlapSeconds = dz_report_overlap_seconds($startTs, $endTs, $monthStartOperationalTs, $monthEndExclusiveOperationalTs);
						if ($overlapSeconds > 0) {
							$employees[$userId]['punch_seconds'] += $overlapSeconds;
							$dayKey = !empty($obj->dataval) ? substr($obj->dataval, 0, 10) : null;
							if ($dayKey) {
								if (!isset($employeePunchDaySeconds[$userId])) {
									$employeePunchDaySeconds[$userId] = array();
								}
								$employeePunchDaySeconds[$userId][$dayKey] = ($employeePunchDaySeconds[$userId][$dayKey] ?? 0) + $overlapSeconds;
							}
						}
					}
				}
		}
		$db->free($resPunch);
	}
}

// Leaves (Dolibarr holidays) - load and filter overlap in PHP to avoid type mismatches
if (!empty($employees)) {
		// Use DoliZSynch leave table (llx_dolizsynch_leave) instead of Dolibarr core holiday
		$holidayTable = MAIN_DB_PREFIX . "dolizsynch_leave";
		$holidayTypesTable = MAIN_DB_PREFIX . "c_holiday_types";
		$sqlLeaves = "SELECT h.rowid, h.fk_user, h.date_start as date_debut, h.date_end as date_fin, h.halfday, h.minutes_total, h.description, ht.code as leave_code, ht.label as leave_label, h.day_change_hour";
		$sqlLeaves .= " FROM " . $holidayTable . " h";
		$sqlLeaves .= " LEFT JOIN " . $holidayTypesTable . " ht ON ht.rowid = h.fk_type";
		$sqlLeaves .= " WHERE 1 = 1";

	$resLeaves = $db->query($sqlLeaves);
	if ($resLeaves) {
		while ($obj = $db->fetch_object($resLeaves)) {
			$userId = (int) $obj->fk_user;
			if (!isset($employees[$userId])) {
				continue;
			}
			$startTs = shiftplannerNormalizeTimestamp($db, $obj->date_debut);
			$endTs = shiftplannerNormalizeTimestamp($db, $obj->date_fin);
			if (!$startTs || !$endTs) {
				continue;
			}
			// Treat end timestamp as exclusive (aligned with operational day change hour).
			$endExclusive = $endTs;

			// Clamp to report window (month end exclusive)
			$periodStart = max($startTs, $monthStartOperationalTs);
			$periodEnd = min($endExclusive, $monthEndExclusiveOperationalTs);
			if ($periodEnd <= $periodStart) {
				continue;
			}

			$secondsOverlap = max(0, $periodEnd - $periodStart);

			// Parse explicit minutes (partial-day leave)
			$minutes = isset($obj->minutes_total) ? (int) $obj->minutes_total : 0;
			$hoursPerDay = 8;
			if (isset($employees[$userId]['weeklyhours']) && $employees[$userId]['weeklyhours'] > 0) {
				$hoursPerDay = ((float) $employees[$userId]['weeklyhours']) / 5;
				if ($hoursPerDay <= 0) {
					$hoursPerDay = 8;
				}
			}
			// Backward compatibility for legacy rows that stored minutes in description JSON/text.
			if ($minutes <= 0) {
				$extraStr = !empty($obj->description) ? (string) $obj->description : '';
				if ($extraStr !== '') {
					if (preg_match('/MINUTES\\s*=\\s*(\\d+)/i', $extraStr, $m)) {
						$minutes = (int) $m[1];
					} elseif (preg_match('/\"minutes\"\\s*:\\s*(\\d+)/i', $extraStr, $m)) {
						$minutes = (int) $m[1];
					}
				}
			}

			// halfday meaning: 0 full, 1 half-day, 2 hours range, 3 minutes
			$halfdayVal = (int) $obj->halfday;
			if ($halfdayVal === 1) {
				// A half-day leave always counts as 0.5 day when it overlaps the month window.
				$totalDays = ($periodEnd > $periodStart) ? 0.5 : 0.0;
				$weekdayDays = $totalDays;
			} elseif ($halfdayVal === 3) {
				$minutesOnly = $minutes > 0 ? $minutes : (int) round($secondsOverlap / 60);
				$totalDays = ($minutesOnly > 0) ? (($minutesOnly / 60) / $hoursPerDay) : 0;
				$weekdayDays = $totalDays;
			} elseif ($halfdayVal === 2) {
				$rangeMinutes = $minutes > 0 ? $minutes : (int) round($secondsOverlap / 60);
				$totalDays = ($rangeMinutes > 0) ? (($rangeMinutes / 60) / $hoursPerDay) : 0;
				$weekdayDays = $totalDays;
			} else {
				// Compute days with partial support
				$weekdayDays = dz_report_count_weekdays($periodStart, $periodEnd - 1); // inclusive end adjust
				$totalDays = $secondsOverlap / 86400;
				// For single partial-day ranges, align weekdayDays to partial too
				if ($totalDays < 1) {
					$weekdayDays = $totalDays;
				}
			}
				// Keep precision while accumulating by leave code; round only at final column output.
				$weekdayDays = round(max(0, $weekdayDays), 6);
				$totalDays = round(max(0, $totalDays), 6);

			$code = isset($obj->leave_code) ? strtoupper(trim((string) $obj->leave_code)) : '';
			if (!isset($employeeLeaveCodeTotals[$userId])) {
				$employeeLeaveCodeTotals[$userId] = array();
			}
			$employeeLeaveCodeTotals[$userId][$code] = ($employeeLeaveCodeTotals[$userId][$code] ?? 0) + $totalDays;

			if ($hasUnjustAbsenceColumns) {
				$label = isset($obj->leave_label) ? $obj->leave_label : '';
				$labelCombo = trim($code . ' ' . $label);
				$isUnjustified = dz_report_is_unjustified_label($labelCombo);
				if (!$isUnjustified && !empty($unjustAbsenceCodes) && $code !== '' && isset($unjustAbsenceCodes[$code])) {
					if (!dz_report_is_justified_label($labelCombo)) {
						$isUnjustified = true;
					}
				}
				if ($isUnjustified) {
					if ($code !== '') {
						if (!isset($employeeUnjustAbsenceCodeCounts[$userId])) {
							$employeeUnjustAbsenceCodeCounts[$userId] = array();
						}
						$employeeUnjustAbsenceCodeCounts[$userId][$code] = ($employeeUnjustAbsenceCodeCounts[$userId][$code] ?? 0) + $totalDays;
					}
					if (!isset($employeeUnjustAbsenceDays[$userId])) {
						$employeeUnjustAbsenceDays[$userId] = array();
					}
					$adjStart = max($startTs, $absenceAdjStartTs);
					$adjEnd = min($endExclusive, $absenceAdjEndExclusiveTs);
					if ($adjEnd > $adjStart) {
						$cursor = shiftplanner_operational_boundary_for_timestamp($adjStart, $dayChangeHour);
						while ($cursor < $adjEnd) {
							$employeeUnjustAbsenceDays[$userId][date('Y-m-d', $cursor)] = true;
							$cursor += 86400;
						}
					}
				}
			}
		}
		$db->free($resLeaves);
	}
}

if ($hasUnjustAbsenceColumns && $countDayoffAsAbsence && !empty($employeeDayoffMap)) {
	foreach ($employeeDayoffMap as $userId => $dayoffs) {
		if (empty($dayoffs)) {
			continue;
		}
		$absenceDates = $employeeUnjustAbsenceDays[$userId] ?? array();
		if (empty($absenceDates)) {
			continue;
		}
		$count = 0;
		foreach ($dayoffs as $dateIso => $val) {
			if (isset($absenceDates[$dateIso])) {
				continue;
			}
			$prev = date('Y-m-d', strtotime($dateIso . ' -1 day'));
			$next = date('Y-m-d', strtotime($dateIso . ' +1 day'));
			if (isset($absenceDates[$prev]) || isset($absenceDates[$next])) {
				$count++;
			}
		}
		if ($count > 0) {
			$employeeDayoffAbsenceCount[$userId] = $count;
		}
	}
}

if ($hasUnjustAbsenceColumns && $countDayoffAsAbsence && !empty($employeeDayoffAbsenceCount) && !empty($unjustAbsenceCodes)) {
	foreach ($employeeDayoffAbsenceCount as $userId => $extraDays) {
		$extraDays = (float) $extraDays;
		if ($extraDays <= 0) {
			continue;
		}
		$targetCode = '';
		if (count($unjustAbsenceCodes) === 1) {
			foreach ($unjustAbsenceCodes as $codeKey => $flag) {
				$targetCode = $codeKey;
				break;
			}
		} elseif (!empty($employeeUnjustAbsenceCodeCounts[$userId])) {
			$maxVal = null;
			foreach ($employeeUnjustAbsenceCodeCounts[$userId] as $codeKey => $val) {
				if ($maxVal === null || $val > $maxVal) {
					$maxVal = $val;
					$targetCode = $codeKey;
				}
			}
		} else {
			foreach ($unjustAbsenceCodes as $codeKey => $flag) {
				$targetCode = $codeKey;
				break;
			}
		}
		if ($targetCode !== '') {
			if (!isset($employeeLeaveCodeTotals[$userId])) {
				$employeeLeaveCodeTotals[$userId] = array();
			}
			$employeeLeaveCodeTotals[$userId][$targetCode] = ($employeeLeaveCodeTotals[$userId][$targetCode] ?? 0) + $extraDays;
		}
	}
}

// Training hours per user within the month (handles multi-day courses)
if (!empty($employees)) {
	$courseTable = MAIN_DB_PREFIX . "trainingcourse_course";
	$userCourseTable = MAIN_DB_PREFIX . "trainingcourse_usercourse";

	$hasTrainingTables = true;
	if (method_exists($db, 'table_exists')) {
		if (!$db->table_exists($courseTable) || !$db->table_exists($userCourseTable)) {
			// Try alternate prefix (mc) if main prefix check fails
			$altCourse = $db->prefix() . "trainingcourse_course";
			$altUserCourse = $db->prefix() . "trainingcourse_usercourse";
			if ($db->table_exists($altCourse) && $db->table_exists($altUserCourse)) {
				$courseTable = $altCourse;
				$userCourseTable = $altUserCourse;
			} else {
				$hasTrainingTables = false;
			}
		}
	}

	if ($hasTrainingTables) {
		$courseEndColumnExists = true;
		if (method_exists($db, 'DDLInfoTable')) {
			$courseEndColumnExists = false;
			$cols = $db->DDLInfoTable($userCourseTable);
			if (is_array($cols) && !empty($cols)) {
				foreach ($cols as $colInfo) {
					if (!empty($colInfo[0]) && strtolower($colInfo[0]) === 'courseenddate') {
						$courseEndColumnExists = true;
						break;
					}
				}
			} else {
				$courseEndColumnExists = true;
			}
		}

		$empIdList = implode(',', array_map('intval', array_keys($employees)));
		$endSelect = $courseEndColumnExists ? "uc.courseEndDate" : "uc.courseDate AS courseEndDate";
		$startExpr = "COALESCE(NULLIF(uc.courseDate, '0000-00-00'), " . ($courseEndColumnExists ? "NULLIF(uc.courseEndDate, '0000-00-00')" : "NULLIF(uc.courseDate, '0000-00-00')") . ")";
		$endExpr = $courseEndColumnExists ? "COALESCE(NULLIF(uc.courseEndDate, '0000-00-00'), NULLIF(uc.courseDate, '0000-00-00'))" : $startExpr;

		$sqlTrain = "SELECT uc.fk_user, uc.courseDate, " . $endSelect . ", c.total_hours";
		$sqlTrain .= " FROM " . $userCourseTable . " uc";
		$sqlTrain .= " LEFT JOIN " . $courseTable . " c ON c.rowid = uc.fk_course";
		$sqlTrain .= " WHERE $startExpr IS NOT NULL";
		$sqlTrain .= " AND $startExpr <= '" . $db->escape($monthEndIso) . "'";
		$sqlTrain .= " AND $endExpr >= '" . $db->escape($monthStartIso) . "'";
		if ($empIdList !== '') {
			$sqlTrain .= " AND uc.fk_user IN (" . $empIdList . ")";
		}

		$resTrain = $db->query($sqlTrain);
		if ($resTrain) {
			$monthStartDate = new DateTime($monthStartIso);
			$monthEndDate = new DateTime($monthEndIso);

			while ($obj = $db->fetch_object($resTrain)) {
				$uid = (int) $obj->fk_user;
				if (!isset($employees[$uid])) {
					continue;
				}

				$startStr = (!empty($obj->courseDate) && $obj->courseDate !== '0000-00-00') ? $obj->courseDate : $obj->courseEndDate;
				$endStr = (!empty($obj->courseEndDate) && $obj->courseEndDate !== '0000-00-00') ? $obj->courseEndDate : $startStr;
				if (empty($startStr) || empty($endStr)) {
					continue;
				}

				try {
					$courseStart = new DateTime($startStr);
					$courseEnd = new DateTime($endStr);
				} catch (Exception $e) {
					continue;
				}

				if ($courseEnd < $courseStart) {
					$tmp = $courseStart;
					$courseStart = $courseEnd;
					$courseEnd = $tmp;
				}

				$totalDays = ((int) $courseStart->diff($courseEnd)->format('%a')) + 1;
				$overlapStart = ($courseStart > $monthStartDate) ? clone $courseStart : clone $monthStartDate;
				$overlapEnd = ($courseEnd < $monthEndDate) ? clone $courseEnd : clone $monthEndDate;
				if ($overlapEnd < $overlapStart) {
					continue;
				}
				$overlapDays = ((int) $overlapStart->diff($overlapEnd)->format('%a')) + 1;

				$totalHours = (float) ($obj->total_hours ?? 0);
				// If course has no hours set, assume 8h per day
				if ($totalHours <= 0) {
					$totalHours = 8.0 * max(1, $totalDays);
				}

				$hoursPerDay = $totalHours / max(1, $totalDays);
				if (!isset($employees[$uid]['training_hours'])) {
					$employees[$uid]['training_hours'] = 0.0;
				}
				$employees[$uid]['training_hours'] += $hoursPerDay * $overlapDays;
			}
			$db->free($resTrain);
		}
	}
}

// Public holidays for the month (shown as reference columns)
$holidayCountryId = (int) getDolGlobalInt('DOLIZSYNCH_PUBLIC_HOLIDAY_COUNTRY', 25);
$publicHolidays = shiftplanner_fetch_public_holidays($db, $holidayCountryId, $monthStartIso, $monthEndExclusiveIso);
$publicHolidayMap = [];
if (!empty($publicHolidays)) {
	foreach ($publicHolidays as $ph) {
		if (!empty($ph['date'])) {
			$dateKey = substr($ph['date'], 0, 10);
			$publicHolidayMap[$dateKey] = $ph['name'] ?? '';
		}
	}
}
$publicHolidayIndex = [];
if (!empty($publicHolidayMap)) {
	$counter = 1;
	foreach ($publicHolidayMap as $d => $n) {
		$publicHolidayIndex[$d] = $counter;
		$counter++;
	}
}
$publicHolidayPillsHtml = '';
if (!empty($publicHolidayIndex)) {
	$publicHolidayPillsHtml = implode(' ', array_map(function ($num) {
		return '<span class="ph-pill">' . dol_escape_htmltag($num) . '</span>';
	}, array_values($publicHolidayIndex)));
}

$employeeHolidayLabels = array();
$employeeHolidayScheduledHours = array();
$employeeHolidayWorkedHours = array();
$employeeHolidayCount = array();
$dayChangeHour = shiftplanner_get_day_change_hour();
if (!empty($publicHolidayMap) && !empty($employees)) {
	foreach ($employees as $empId => $emp) {
		$labels = array();
		$scheduledSeconds = 0;
		$workedSeconds = 0;
		foreach ($publicHolidayMap as $dateKey => $name) {
			// Operational day window (supports day-change hour)
			$windowStart = shiftplanner_operational_day_start_from_iso($dateKey, $dayChangeHour);
			$windowEnd = $windowStart + 86400;

			// Sum shift overlap with this window
			$shiftSeconds = 0;
			if (!empty($employeeShiftIntervals[$empId])) {
				foreach ($employeeShiftIntervals[$empId] as $interval) {
					$s = isset($interval['start']) ? (int) $interval['start'] : 0;
					$e = isset($interval['end']) ? (int) $interval['end'] : 0;
					if ($e <= $s) continue;
					$overlap = max(0, min($e, $windowEnd) - max($s, $windowStart));
					$shiftSeconds += $overlap;
				}
			}

			// Sum punch overlap with this window
			$punchSeconds = 0;
			if (!empty($employeePunchIntervals[$empId])) {
				foreach ($employeePunchIntervals[$empId] as $interval) {
					$s = isset($interval['start']) ? (int) $interval['start'] : 0;
					$e = isset($interval['end']) ? (int) $interval['end'] : 0;
					if ($e <= $s) continue;
					$overlap = max(0, min($e, $windowEnd) - max($s, $windowStart));
					$punchSeconds += $overlap;
				}
			}

			// Only count holidays with a valid punch (worked)
			if ($punchSeconds > 0) {
				$labels[] = array(
					'label' => ($name !== '' ? ($dateKey . ' - ' . $name) : $dateKey),
					'number' => $publicHolidayIndex[$dateKey] ?? $dateKey,
				);
				$scheduledSeconds += $shiftSeconds;
				$workedSeconds += $punchSeconds;
			}
		}
		$employeeHolidayLabels[$empId] = $labels;
		$employeeHolidayCount[$empId] = count($labels);
		$employeeHolidayScheduledHours[$empId] = ($scheduledSeconds > 0) ? ($scheduledSeconds / 3600) : 0;
		$employeeHolidayWorkedHours[$empId] = ($workedSeconds > 0) ? ($workedSeconds / 3600) : 0;
	}
}

// Evaluate dynamic leave columns (supports arithmetic with codes and built-in metrics such as SCHEDULED, WORKED, HE, HC, TRAININGHOURS, PUBLICHOLIDAY, PUBLICHOLIDAYWORKED, PUBLICHOLIDAYWORKEDHOURS, PUBLICHOLIDAYSCHEDULEDHOURS)
if (!empty($employees)) {
	foreach ($employees as $empId => &$emp) {
		$codeTotals = $employeeLeaveCodeTotals[$empId] ?? array();
		$sumAll = !empty($codeTotals) ? array_sum($codeTotals) : 0;
		$vars = $codeTotals;
		$vars['SCHEDULED'] = $emp['shifts_seconds'] > 0 ? ($emp['shifts_seconds'] / 3600) : 0;
		$vars['WORKED'] = $emp['punch_seconds'] > 0 ? ($emp['punch_seconds'] / 3600) : 0;
		if ($emp['horasefect'] !== '' && $emp['horasefect'] !== null) {
			$vars['HE'] = (float) $emp['horasefect'];
		}
		if ($emp['weeklyhours'] !== '' && $emp['weeklyhours'] !== null) {
			$vars['HC'] = (float) $emp['weeklyhours'];
		}
		if (isset($emp['training_hours'])) {
			$vars['TRAININGHOURS'] = (float) $emp['training_hours'];
		}
		if (isset($employeeHolidayCount[$empId])) {
			$vars['PUBLICHOLIDAY'] = (float) $employeeHolidayCount[$empId];
		}
		if (isset($employeeHolidayScheduledHours[$empId])) {
			$vars['PUBLICHOLIDAYSCHEDULEDHOURS'] = (float) $employeeHolidayScheduledHours[$empId];
		}
		if (isset($employeeHolidayScheduledHours[$empId])) {
			$vars['PUBLIC_HOLIDAY_SCHEDULED_HOURS'] = (float) $employeeHolidayScheduledHours[$empId];
		}
		if (isset($employeeHolidayWorkedHours[$empId])) {
			$vars['PUBLICHOLIDAYWORKEDHOURS'] = (float) $employeeHolidayWorkedHours[$empId];
		}
		if (isset($employeeHolidayCount[$empId])) {
			$vars['PUBLICHOLIDAYWORKED'] = (float) $employeeHolidayCount[$empId];
		}
		// Provide underscore aliases for expressions like PUBLIC_HOLIDAY_WORKED_HOURS
		$aliasMap = array(
			'PUBLIC_HOLIDAY' => 'PUBLICHOLIDAY',
			'PUBLIC_HOLIDAY_WORKED' => 'PUBLICHOLIDAYWORKED',
			'PUBLIC_HOLIDAY_WORKED_HOURS' => 'PUBLICHOLIDAYWORKEDHOURS',
			'PUBLIC_HOLIDAY_SCHEDULED_HOURS' => 'PUBLICHOLIDAYSCHEDULEDHOURS',
			'TRAINING_HOURS' => 'TRAININGHOURS',
			'SCHEDULED' => 'SCHEDULED',
			'WORKED' => 'WORKED',
		);
		foreach ($aliasMap as $aliasKey => $baseKey) {
			if (isset($vars[$baseKey])) {
				$vars[$aliasKey] = $vars[$baseKey];
			}
		}
		foreach ($leaveColumns as $col) {
			if (isset($col['mode']) && ($col['mode'] === 'manual' || $col['mode'] === 'builtin')) {
				continue;
			}
			$expr = isset($col['expr']) ? trim($col['expr']) : '';
			if ($expr !== '') {
				$value = dz_report_eval_leave_expression($expr, $vars);
			} else {
				$value = $sumAll; // catch-all column when no expression provided
			}
			$emp['leaves_dynamic'][$col['key']] = round((float) $value, 2);
		}
	}
	unset($emp);
}

if ($hasUnjustAbsenceColumns && $countDayoffAsAbsence && !empty($employeeDayoffAbsenceCount) && empty($unjustAbsenceCodes)) {
	foreach ($employees as $empId => &$emp) {
		$extra = $employeeDayoffAbsenceCount[$empId] ?? 0;
		if ($extra <= 0) {
			continue;
		}
		foreach ($unjustAbsenceColumnKeys as $colKey) {
			$current = isset($emp['leaves_dynamic'][$colKey]) ? (float) $emp['leaves_dynamic'][$colKey] : 0.0;
			$emp['leaves_dynamic'][$colKey] = round($current + $extra, 2);
		}
	}
	unset($emp);
}

// Rendering
$title = 'Relatório Mensal RH';
$helpUrl = '';

llxHeader('', $title, $helpUrl);
$scheduleCssPath = __DIR__ . '/../css/schedule.css';
$scheduleCssVersion = @filemtime($scheduleCssPath);
$scheduleCssUrl = dol_buildpath('/kreaflow/css/schedule.css', 1);
if ($scheduleCssVersion) {
	$scheduleCssUrl .= '?v=' . $scheduleCssVersion;
}
?>
<link rel="stylesheet" type="text/css" href="<?php echo $scheduleCssUrl; ?>">
<style>
	.dz-report-table {
		width: 100%;
		border-collapse: collapse;
		font-size: 12px;
	}

	.dz-report-table th,
	.dz-report-table td {
		border: 1px solid #ccc;
		padding: 6px 8px;
		vertical-align: top;
	}

	.dz-report-table th {
		background: #f3f3f3;
		text-align: center;
	}

	.dz-report-table .group {
		background: #222;
		color: #fff;
		font-weight: bold;
		text-transform: uppercase;
	}

	.dz-chip {
		display: inline-block;
		padding: 2px 6px;
		margin: 1px 0;
		background: #eef5ff;
		border: 1px solid #ccd7ff;
		border-radius: 4px;
	}

	.dz-muted {
		color: #777;
	}

	.report-container {
		width: 100%;
		margin: 0 auto;
		overflow-x: auto;
	}

	.report-legend {
		margin: 12px 0 0;
		width: 100%;
	}

	.report-legend #leave-code-legend {
		display: flex;
		width: 100%;
		margin: 0;
		box-sizing: border-box;
	}

	.report-legend .legend-row-leaves {
		flex-wrap: wrap;
		gap: 8px;
	}

	.note-col {
		min-width: 180px;
	}

	.note-input {
		width: 100%;
		min-height: 34px;
		resize: none;
		box-sizing: border-box;
	}

	.ph-pill {
		display: inline-block;
		min-width: 22px;
		padding: 2px 6px;
		margin: 1px 3px 1px 0;
		border-radius: 12px;
		background: #eef5ff;
		border: 1px solid #ccd7ff;
		font-weight: 700;
		text-align: center;
		font-size: 11px;
		color: #0d47a1;
	}

	.leave-sum-col {
		background: #f5f5f5;
	}

	.name-cell {
		background: #e0e0e0;
	}

	.name-dark {
		background: #444;
		color: #fff;
	}

	.manual-col {
		min-width: 0;
		width: auto;
	}

	.manual-input {
		width: 100%;
		box-sizing: border-box;
		resize: none;
		min-height: 28px;
	}

	.dz-report-row-hidden {
		display: none;
	}
</style>

<div class="fichecenter" style="margin-bottom: 16px;">
	<form method="get" action="<?php echo $_SERVER['PHP_SELF']; ?>" style="display: flex; align-items: center; gap: 8px;">
		<label for="month"><?php echo $langs->trans('Month'); ?>:</label>
		<input type="month" name="month" id="month"
			value="<?php echo dol_escape_htmltag($monthStartDt->format('Y-m')); ?>">
		<input type="submit" class="button" value="<?php echo $langs->trans('Refresh'); ?>">
		<input type="button" class="button" id="btn-print-report" value="<?php echo $langs->trans('Print'); ?>">
		<input type="button" class="button" id="btn-export-xls" value="<?php echo $langs->trans('Export'); ?> XLS">
	</form>
</div>

<h1><?php echo dol_escape_htmltag($title . ' - ' . $monthStartDt->format('F Y')); ?></h1>

<?php if (empty($employees)) { ?>
	<div class="opacitymedium"><?php echo $langs->trans('NoDataFound'); ?></div>
<?php } else { ?>
	<div class="report-container">
		<table class="dz-report-table">
			<thead>
				<tr>
					<th class="group" style="min-width: 200px;">
						<button type="button" id="manage-employees-btn" class="employee-manage-btn" title="<?php echo dol_escape_htmltag($langs->trans('ShiftPlannerManageEmployeesHint')); ?>">
							<?php echo $langs->trans('Employee'); ?>
						</button>
					</th>
					<th class="group"><?php echo $langs->trans('Code'); ?></th>
					<th class="group"><?php echo $langs->trans('Horasefect'); ?> (H)</th>
					<th class="group"><?php echo $langs->trans('WeeklyHours'); ?> (H)</th>
					<?php foreach ($leaveColumns as $col) {
						$headerLabel = dz_report_column_label_with_unit($col['label'] ?? '', $col['unit'] ?? '');
					?>
						<th class="group <?php echo dol_escape_htmltag($col['class']); ?>"><?php echo dol_escape_htmltag($headerLabel); ?></th>
					<?php } ?>
					<th class="group note-col"><?php echo $langs->trans('Note'); ?></th>
				</tr>
			</thead>
		<tbody>
			<?php foreach ($employees as $emp) {
				$scheduledHours = round($emp['shifts_seconds'] / 3600, 2);
				$workedHours = round($emp['punch_seconds'] / 3600, 2);
				$fromDate = ($emp['validity_start'] && $emp['validity_start'] > 0) ? dol_print_date($emp['validity_start'], 'day') : (($emp['admission'] && $emp['admission'] > 0) ? dol_print_date($emp['admission'], 'day') : '-');
				$toDate = ($emp['validity_end'] && $emp['validity_end'] > 0) ? dol_print_date($emp['validity_end'], 'day') : '—';
				$codeDisplay = ($emp['code'] !== '') ? dol_escape_htmltag($emp['code']) : '<span class="dz-muted">-</span>';
				$noteValue = isset($emp['note']) ? $emp['note'] : '';
				$holidayLabels = $employeeHolidayLabels[$emp['id']] ?? array();
				if (!empty($holidayLabels)) {
					$nums = array_map(function ($item) {
						return (string) ($item['number'] ?? '?');
					}, $holidayLabels);
					$holidayDisplay = implode(' ', array_map(function ($num) {
						return '<span class="ph-pill">' . dol_escape_htmltag($num) . '</span>';
					}, $nums));
				} else {
					$holidayDisplay = '<span class="dz-muted">-</span>';
				}
				$holidayWorkedDaysVal = $employeeHolidayCount[$emp['id']] ?? 0;
				$holidayScheduledHoursVal = $employeeHolidayScheduledHours[$emp['id']] ?? 0;
				$holidayWorkedHoursVal = $employeeHolidayWorkedHours[$emp['id']] ?? 0;
				?>
					<tr data-emp-id="<?php echo (int) $emp['id']; ?>" data-emp-name="<?php echo dol_escape_htmltag($emp['name']); ?>" data-emp-code="<?php echo dol_escape_htmltag($emp['code']); ?>">
						<td class="name-cell">
							<?php
							$userUrl = dol_buildpath('/user/card.php', 1) . '?id=' . ((int) $emp['id']);
							?>
							<a href="<?php echo dol_escape_htmltag($userUrl); ?>" target="_blank" rel="noopener noreferrer" class="classfortooltip" title="<?php echo dol_escape_htmltag($emp['name']); ?>">
								<?php echo img_picto('', 'user'); ?>
								<?php echo dol_escape_htmltag($emp['name']); ?>
							</a>
						</td>
						<td class="leave-sum-col" style="text-align: right;"><?php echo $codeDisplay; ?></td>
						<td class="leave-sum-col" style="text-align: right;"><?php echo ($emp['horasefect'] !== '' ? dz_report_format_number_fixed((float) $emp['horasefect'], 2) : '<span class="dz-muted">-</span>'); ?></td>
						<td class="leave-sum-col" style="text-align: right;"><?php echo ($emp['weeklyhours'] !== '' ? dz_report_format_number_fixed((float) $emp['weeklyhours'], 2) : '<span class="dz-muted">-</span>'); ?></td>
						<?php foreach ($leaveColumns as $col) { 
							$unit = isset($col['unit']) ? trim($col['unit']) : '';
							$unitDisplay = ''; // Units removed from cell display
							$modeVal = isset($col['mode']) ? $col['mode'] : 'computed';
							if ($modeVal === 'manual') {
								$mVal = isset($emp['manual_values'][$col['key']]) ? $emp['manual_values'][$col['key']] : '';
								$cellStyle = '';
								if (!empty($col['color'])) {
									$cellStyle = ' style="background:' . dol_escape_htmltag($col['color']) . ';"';
								}
						?>
						<td class="manual-col <?php echo dol_escape_htmltag($col['class']); ?>"<?php echo $cellStyle; ?>>
							<?php
								$widthAttr = '';
								if (!empty($col['width'])) {
									$widthAttr = 'style="width:' . dol_escape_htmltag($col['width']) . '"';
								}
							?>
								<div style="display: flex; align-items: center; gap: 4px;">
									<input type="text" class="manual-input" data-emp-id="<?php echo (int) $emp['id']; ?>" data-manual-key="<?php echo dol_escape_htmltag($col['key']); ?>" value="<?php echo dol_escape_htmltag($mVal); ?>" <?php echo $widthAttr; ?> />
									<?php if ($unit !== '') { ?>
										<span class="dz-muted" style="white-space: nowrap;"></span>
									<?php } ?>
								</div>
							</td>
							<?php
			} elseif ($modeVal === 'builtin') {
				$cellStyle = '';
				if (!empty($col['color'])) {
					$cellStyle = ' style="background:' . dol_escape_htmltag($col['color']) . ';"';
				}
				$builtinKey = isset($col['builtin']) ? strtolower($col['builtin']) : '';
				$valueHtml = '<span class="dz-muted">-</span>';
								if ($builtinKey === 'publicholiday') {
									// Show only holidays where this employee has punch overlap
									$valueHtml = $holidayDisplay;
				} elseif ($builtinKey === 'publicholidayworked') {
					$valueHtml = $holidayWorkedDaysVal > 0 ? dol_escape_htmltag(dz_report_format_number_fixed($holidayWorkedDaysVal, 2) . $unitDisplay) : '<span class="dz-muted">-</span>';
				} elseif ($builtinKey === 'publicholidayworkedhours') {
					$valueHtml = $holidayWorkedHoursVal > 0 ? dol_escape_htmltag(dz_report_format_number_fixed($holidayWorkedHoursVal, 2) . $unitDisplay) : '<span class="dz-muted">-</span>';
				} elseif ($builtinKey === 'publicholidayscheduledhours') {
					$valueHtml = $holidayScheduledHoursVal > 0 ? dol_escape_htmltag(dz_report_format_number_fixed($holidayScheduledHoursVal, 2)) : '<span class="dz-muted">-</span>';
				} elseif ($builtinKey === 'scheduled') {
					$valueHtml = $scheduledHours > 0 ? dol_escape_htmltag(dz_report_format_number_fixed($scheduledHours, 2) . $unitDisplay) : '<span class="dz-muted">-</span>';
								} elseif ($builtinKey === 'worked') {
									$valueHtml = $workedHours > 0 ? dol_escape_htmltag(dz_report_format_number_fixed($workedHours, 2) . $unitDisplay) : '<span class="dz-muted">-</span>';
								} elseif ($builtinKey === 'traininghours') {
									$valueHtml = $emp['training_hours'] > 0 ? dol_escape_htmltag(dz_report_format_number_fixed($emp['training_hours'], 2) . $unitDisplay) : '<span class="dz-muted">-</span>';
								}
								?>
				<td class="<?php echo dol_escape_htmltag($col['class']); ?>"<?php echo $cellStyle; ?>><?php echo $valueHtml; ?></td>
								<?php
							} else {
								$totalDays = isset($emp['leaves_dynamic'][$col['key']]) ? (float) $emp['leaves_dynamic'][$col['key']] : null;
								$cellStyle = '';
								if (!empty($col['color'])) {
									$cellStyle = ' style="background:' . dol_escape_htmltag($col['color']) . ';"';
								}
								$exprRaw = isset($col['expr']) ? trim($col['expr']) : '';
								$exprNorm = strtoupper(str_replace(' ', '', $exprRaw));
								if ($exprNorm === 'PUBLICHOLIDAY' || $exprNorm === 'PUBLIC_HOLIDAY') {
									// Show only holidays where this employee has punch overlap
									$valueHtml = $holidayDisplay;
								} else {
									if ($totalDays === null || abs((float) $totalDays) < 0.000001) {
										$valueHtml = '<span class="dz-muted">-</span>';
									} else {
										$valueHtml = dol_escape_htmltag(dz_report_format_number_fixed($totalDays, 2) . $unitDisplay);
									}
								}
								?>
				<td class="<?php echo dol_escape_htmltag($col['class']); ?>"<?php echo $cellStyle; ?>><?php echo $valueHtml; ?></td>
								<?php
							}
						} ?>
						<td class="note-col">
							<textarea class="note-input" data-emp-id="<?php echo (int) $emp['id']; ?>" rows="1"><?php echo dol_escape_htmltag($noteValue); ?></textarea>
						</td>
					</tr>
			<?php } ?>
		</tbody>
		</table>
			<?php if (!empty($publicHolidayMap)) { ?>
			<div class="report-legend">
				<div id="leave-code-legend" class="leave-code-legend" style="display: flex;">
					<div class="leave-legend-title"><?php echo dol_escape_htmltag($langs->trans('PublicHoliday')); ?></div>
					<div class="legend-row-leaves">
						<?php
						$counter = 1;
						foreach ($publicHolidayMap as $d => $n) {
							?>
							<div class="leave-pill">
								<span class="leave-pill-code"><?php echo dol_escape_htmltag($counter); ?></span>
								<span class="leave-pill-label"><?php echo dol_escape_htmltag($d . ' - ' . ($n ?: '')); ?></span>
							</div>
							<?php
							$counter++;
						}
						?>
					</div>
				</div>
			</div>
		<?php } ?>
	</div>
<?php } ?>

<div id="employee-manager-modal" class="employee-manager-modal" aria-hidden="true">
	<div class="employee-manager-backdrop" data-employee-manager-close></div>
	<div class="employee-manager-dialog" role="dialog" aria-modal="true" aria-labelledby="employee-manager-title">
		<div class="employee-manager-header">
			<h3 id="employee-manager-title"><?php echo $langs->transnoentities('ShiftPlannerManageEmployeesTitle'); ?></h3>
			<button type="button" class="employee-manager-close" data-employee-manager-close aria-label="<?php echo dol_escape_htmltag($langs->transnoentities('Close')); ?>">&times;</button>
		</div>
		<p class="employee-manager-hint"><?php echo $langs->transnoentities('ShiftPlannerManageEmployeesHint'); ?></p>
		<div class="employee-manager-search">
			<input type="text" id="employee-manager-search" placeholder="<?php echo dol_escape_htmltag($langs->transnoentities('ShiftPlannerManageEmployeesSearch')); ?>">
		</div>
		<div id="employee-manager-feedback" class="employee-manager-feedback" role="alert" style="display:none;"></div>
		<div id="employee-manager-list" class="employee-manager-list" role="listbox" aria-live="polite"></div>
		<div id="employee-manager-empty" class="employee-manager-empty" style="display:none;">
			<?php echo $langs->transnoentities('ShiftPlannerManageEmployeesEmpty'); ?>
		</div>
		<div class="employee-manager-footer">
			<button type="button" id="employee-manager-cancel" class="button"><?php echo $langs->transnoentities('ShiftPlannerManageEmployeesCancel'); ?></button>
			<button type="button" id="employee-manager-save" class="button"><?php echo $langs->transnoentities('ShiftPlannerManageEmployeesSave'); ?></button>
		</div>
	</div>
</div>

<script>
	(function () {
		const monthKey = "<?php echo dol_escape_js($monthKey); ?>";
		const hasManualStorage = <?php echo $notesHasManual ? 'true' : 'false'; ?>;
		function saveRow(empId) {
			if (!empId) return;
			const noteEl = document.querySelector('.note-input[data-emp-id="' + empId + '"]');
			const noteVal = noteEl ? noteEl.value || "" : "";
			const manualData = {};
			if (hasManualStorage) {
				document.querySelectorAll('.manual-input[data-emp-id="' + empId + '"]').forEach((inp) => {
					const key = inp.dataset.manualKey;
					if (key) manualData[key] = inp.value || "";
				});
			}
			const payloadObj = {
				action: "save_row",
				emp_id: empId,
				month: monthKey,
				note: noteVal,
			};
			if (hasManualStorage) {
				payloadObj.manual_data = JSON.stringify(manualData);
			}
			const payload = new URLSearchParams(payloadObj);
			fetch(window.location.href, {
				method: "POST",
				headers: { "Content-Type": "application/x-www-form-urlencoded" },
				body: payload,
			}).catch(() => {});
		}

		document.querySelectorAll(".note-input").forEach((el) => {
			el.addEventListener("change", () => {
				saveRow(el.dataset.empId);
			});
		});
		document.querySelectorAll(".manual-input").forEach((el) => {
			el.addEventListener("change", () => {
				saveRow(el.dataset.empId);
			});
		});
	})();
</script>

<script>
	(function () {
		const monthKey = "<?php echo dol_escape_js($monthKey); ?>";
		const manageBtn = document.getElementById("manage-employees-btn");
		const modal = document.getElementById("employee-manager-modal");
		const listEl = document.getElementById("employee-manager-list");
		const emptyEl = document.getElementById("employee-manager-empty");
		const searchInput = document.getElementById("employee-manager-search");
		const saveBtn = document.getElementById("employee-manager-save");
		const cancelBtn = document.getElementById("employee-manager-cancel");
		const backdropTriggers = document.querySelectorAll("[data-employee-manager-close]");
		if (!manageBtn || !modal || !listEl) return;

		let employees = [];
		let overridesLoaded = false;
		let storedForced = new Set();
		let storedHidden = new Set();
		let draftHidden = new Set();

		function normalizeSearchText(value) {
			if (!value) return "";
			return value
				.toString()
				.normalize("NFD")
				.replace(/[\u0300-\u036f]/g, "")
				.toLowerCase();
		}

		function escapeHtml(value) {
			return String(value || "")
				.replace(/&/g, "&amp;")
				.replace(/</g, "&lt;")
				.replace(/>/g, "&gt;")
				.replace(/"/g, "&quot;")
				.replace(/'/g, "&#39;");
		}

		function collectEmployees() {
			employees = [];
			document.querySelectorAll(".dz-report-table tbody tr[data-emp-id]").forEach((row) => {
				const id = row.dataset.empId || "";
				if (!id) return;
				const name = row.dataset.empName || "";
				const code = row.dataset.empCode || "";
				employees.push({
					id: String(id),
					name: name,
					code: code,
					row: row,
				});
			});
		}

		async function fetchMonthOverrides() {
			const payload = new URLSearchParams({
				action: "get_month_overrides",
				month: monthKey,
			});
			const resp = await fetch(window.location.href, {
				method: "POST",
				headers: { "Content-Type": "application/x-www-form-urlencoded" },
				body: payload,
			});
			if (!resp.ok) {
				throw new Error(`HTTP ${resp.status}`);
			}
			const data = await resp.json();
			return {
				forced: Array.isArray(data.forced_user_ids) ? data.forced_user_ids : [],
				hidden: Array.isArray(data.hidden_user_ids) ? data.hidden_user_ids : [],
			};
		}

		async function saveMonthOverrides(forcedSet, hiddenSet) {
			const payload = new URLSearchParams({
				action: "save_month_overrides",
				month: monthKey,
				forced_user_ids: Array.from(forcedSet).join(","),
				hidden_user_ids: Array.from(hiddenSet).join(","),
			});
			const resp = await fetch(window.location.href, {
				method: "POST",
				headers: { "Content-Type": "application/x-www-form-urlencoded" },
				body: payload,
			});
			let data = null;
			try {
				data = await resp.json();
			} catch (err) {
				data = null;
			}
			if (!resp.ok || !data || !data.success) {
				throw new Error((data && data.error) ? data.error : `HTTP ${resp.status}`);
			}
			return true;
		}

		async function loadOverrides(force = false) {
			if (overridesLoaded && !force) return;
			const data = await fetchMonthOverrides();
			storedForced = new Set((data.forced || []).map((id) => String(id)));
			storedHidden = new Set((data.hidden || []).map((id) => String(id)));
			overridesLoaded = true;
		}

		function applyVisibility(hiddenSet) {
			employees.forEach((emp) => {
				const isHidden = hiddenSet && hiddenSet.has(emp.id);
				emp.row.classList.toggle("dz-report-row-hidden", isHidden);
				emp.row.dataset.hidden = isHidden ? "1" : "0";
			});
		}

		function renderList() {
			const term = normalizeSearchText(searchInput && searchInput.value ? searchInput.value.trim() : "");
			const filtered = employees.filter((emp) => {
				if (!term) return true;
				const haystack = normalizeSearchText(`${emp.name} ${emp.code}`);
				return haystack.includes(term);
			});
			if (emptyEl) {
				emptyEl.style.display = filtered.length ? "none" : "block";
			}
			if (!filtered.length) {
				listEl.innerHTML = "";
				return;
			}
			listEl.innerHTML = filtered
				.map((emp) => {
					const checked = draftHidden.has(emp.id) ? "" : "checked";
					const code = emp.code ? `<?php echo dol_escape_js($langs->transnoentities('Code')); ?>: ${emp.code}` : "";
					const meta = code ? `<span class="employee-manager-meta">${escapeHtml(code)}</span>` : "";
					return `<label class="employee-manager-item">
						<input type="checkbox" data-user-id="${emp.id}" ${checked}>
						<div class="employee-manager-info">
							<span class="employee-manager-name">${escapeHtml(emp.name || emp.id)}</span>
							${meta}
						</div>
					</label>`;
				})
				.join("");
		}

		async function openModal() {
			collectEmployees();
			try {
				await loadOverrides();
			} catch (err) {
				overridesLoaded = false;
				storedForced = new Set();
				storedHidden = new Set();
			}
			draftHidden = new Set(storedHidden);
			if (searchInput) {
				searchInput.value = "";
			}
			renderList();
			modal.classList.add("open");
			modal.setAttribute("aria-hidden", "false");
		}

		function closeModal() {
			modal.classList.remove("open");
			modal.setAttribute("aria-hidden", "true");
		}

		manageBtn.addEventListener("click", openModal);
		if (searchInput) {
			searchInput.addEventListener("input", () => {
				renderList();
			});
		}
		if (listEl) {
			listEl.addEventListener("change", (e) => {
				const target = e.target;
				if (!target || !target.matches('input[type="checkbox"][data-user-id]')) return;
				const userId = target.dataset.userId;
				if (!userId) return;
				if (target.checked) {
					draftHidden.delete(String(userId));
				} else {
					draftHidden.add(String(userId));
				}
			});
		}
		if (saveBtn) {
			saveBtn.addEventListener("click", async () => {
				saveBtn.disabled = true;
				try {
					await saveMonthOverrides(storedForced, draftHidden);
					storedHidden = new Set(draftHidden);
					applyVisibility(storedHidden);
					closeModal();
				} catch (err) {
					saveBtn.disabled = false;
					closeModal();
				}
			});
		}
		if (cancelBtn) {
			cancelBtn.addEventListener("click", closeModal);
		}
		if (backdropTriggers && backdropTriggers.length) {
			backdropTriggers.forEach((trigger) => {
				trigger.addEventListener("click", closeModal);
			});
		}

		collectEmployees();
		loadOverrides()
			.then(() => {
				if (storedHidden.size) {
					applyVisibility(storedHidden);
				}
			})
			.catch(() => {});
	})();
</script>

<!-- Print & Export -->
<script>
	(function() {
		const printBtn = document.getElementById('btn-print-report');
		const exportBtn = document.getElementById('btn-export-xls');
		const table = document.querySelector('.dz-report-table');
		if (printBtn && table) {
			printBtn.addEventListener('click', function() {
				const win = window.open('', '_blank');
				if (!win) return;
				const html = `
					<html>
						<head>
							<title><?php echo dol_escape_js($title . ' - ' . $monthStartDt->format('F Y')); ?></title>
							<link rel="stylesheet" type="text/css" href="<?php echo $scheduleCssUrl; ?>">
							<style>
								body { padding: 12px; font-family: Arial, sans-serif; }
								table { width: 100%; border-collapse: collapse; font-size: 12px; }
								th, td { border: 1px solid #ccc; padding: 6px 8px; vertical-align: top; }
								th { background: #f3f3f3; text-align: center; }
								.dz-report-row-hidden { display: none; }
							</style>
						</head>
						<body>
							<h3><?php echo dol_escape_js($title . ' - ' . $monthStartDt->format('F Y')); ?></h3>
							${table.outerHTML}
						</body>
					</html>`;
				win.document.open();
				win.document.write(html);
				win.document.close();
				win.focus();
				win.print();
			});
			printBtn.type = 'button';
		}
		if (exportBtn && table) {
			exportBtn.addEventListener('click', function() {
				const rows = Array.from(table.querySelectorAll('tr')).filter(tr => !tr.classList.contains('dz-report-row-hidden'));
				const csvLines = rows.map(tr => {
					const cells = Array.from(tr.querySelectorAll('th,td'));
					return cells.map(td => {
						const txt = (td.innerText || '').replace(/\s+/g, ' ').trim();
						const safe = '"' + txt.replace(/"/g, '""') + '"';
						return safe;
					}).join(';');
				});
				const csvContent = csvLines.join('\n');
				const blob = new Blob([csvContent], { type: "text/csv;charset=utf-8;" });
				const url = URL.createObjectURL(blob);
				const a = document.createElement('a');
				a.href = url;
				a.download = "schedule_report_<?php echo dol_escape_js($monthKey); ?>.csv";
				document.body.appendChild(a);
				a.click();
				document.body.removeChild(a);
				URL.revokeObjectURL(url);
			});
		}
	})();
</script>

<?php
llxFooter();
$db->close();

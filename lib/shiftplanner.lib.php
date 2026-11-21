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
 * Helper utilities for the shift planner screens.
 *
 * @return array{
 *     start_label: string,
 *     end_label: string,
 *     start_minutes: int,
 *     end_minutes: int
 * }
 */
function shiftplanner_get_day_window()
{
	global $conf;

	$defaults = [
		'start' => '07:00',
		'end' => '00:00',
	];

	$startRaw = trim((string) ($conf->global->DOLIZSYNCH_SHIFT_DAY_START ?? ''));
	$endRaw = trim((string) ($conf->global->DOLIZSYNCH_SHIFT_DAY_END ?? ''));

	$startLabel = shiftplanner_validate_time_label($startRaw) ?: $defaults['start'];
	$endLabel = shiftplanner_validate_time_label($endRaw) ?: $defaults['end'];

	$startMinutes = shiftplanner_time_to_minutes($startLabel);
	$endMinutes = shiftplanner_time_to_minutes($endLabel);

	// Interpret the configured end as the last displayed hour (inclusive),
	// so add 60 minutes to convert into an exclusive boundary.
	$endExclusive = $endMinutes + 60;
	if ($endExclusive <= $startMinutes) {
		$endExclusive += 24 * 60;
	}

	$duration = $endExclusive - $startMinutes;
	if ($duration < 8 * 60 || $duration > 24 * 60) {
		// Fallback to defaults if the configured period is outside [8h, 24h].
		$startLabel = $defaults['start'];
		$endLabel = $defaults['end'];
		$startMinutes = shiftplanner_time_to_minutes($startLabel);
		$endMinutes = shiftplanner_time_to_minutes($endLabel);
		$endExclusive = $endMinutes + 60;
		if ($endExclusive <= $startMinutes) {
			$endExclusive += 24 * 60;
		}
		$duration = $endExclusive - $startMinutes;
	}

	$minuteStepRaw = (int) ($conf->global->DOLIZSYNCH_SHIFT_MINUTE_STEP ?? 30);
	$minuteStep = shiftplanner_validate_minute_step($minuteStepRaw);

	return [
		'start_label' => $startLabel,
		'end_label' => $endLabel,
		'start_minutes' => $startMinutes,
		'end_minutes' => $endExclusive,
		'duration_minutes' => $duration,
		'minute_step' => $minuteStep,
	];
}

/**
 * Validate HH:MM values (24h clock).
 */
function shiftplanner_validate_time_label($value)
{
	$value = trim((string) $value);
	if (preg_match('/^(2[0-3]|[01][0-9]):([0-5][0-9])$/', $value)) {
		return $value;
	}
	return null;
}

/**
 * Convert HH:MM into minutes since midnight.
 */
function shiftplanner_time_to_minutes($value)
{
	if (!preg_match('/^(2[0-3]|[01][0-9]):([0-5][0-9])$/', $value, $matches)) {
		return 0;
	}
	return ((int) $matches[1]) * 60 + (int) $matches[2];
}

function shiftplanner_build_daywindow_meta(array $dayWindow)
{
	$minutesPerDay = 24 * 60;
	$startMinutes = (int) ($dayWindow['start_minutes'] ?? 0);
	$endMinutesExclusive = (int) ($dayWindow['end_minutes'] ?? ($startMinutes + 24 * 60));
	$durationMinutes = (int) ($dayWindow['duration_minutes'] ?? ($endMinutesExclusive - $startMinutes));
	$binCount = max(1, (int) ceil($durationMinutes / 60));
	$crossesMidnight = $endMinutesExclusive > $minutesPerDay;
	$lastHour = (int) floor(((($endMinutesExclusive - 1) % $minutesPerDay)) / 60);
	$wrapMinutes = $crossesMidnight ? ($lastHour + 1) * 60 : 0;

	return [
		'start_minutes' => $startMinutes,
		'end_minutes_exclusive' => $endMinutesExclusive,
		'duration_minutes' => $durationMinutes,
		'bin_count' => $binCount,
		'crosses_midnight' => $crossesMidnight,
		'wrap_minutes' => $wrapMinutes,
	];
}

function shiftplanner_should_wrap_previous_day_php($minutes, array $meta)
{
	if (empty($meta['crosses_midnight'])) {
		return false;
	}
	return is_numeric($minutes) && $minutes < (int) $meta['wrap_minutes'];
}

function shiftplanner_normalize_minutes_php($minutes, array $meta)
{
	if (!is_numeric($minutes)) {
		return null;
	}
	$minutes = (int) $minutes;
	return shiftplanner_should_wrap_previous_day_php($minutes, $meta)
		? $minutes + 24 * 60
		: $minutes;
}

function shiftplanner_get_display_date_php($timestamp, array $meta)
{
	$base = date('Y-m-d', $timestamp);
	if (empty($meta['crosses_midnight'])) {
		return $base;
	}
	$minutes = (int) date('G', $timestamp) * 60 + (int) date('i', $timestamp);
	if (!shiftplanner_should_wrap_previous_day_php($minutes, $meta)) {
		return $base;
	}
	$dt = DateTime::createFromFormat('Y-m-d', $base);
	if (!$dt) {
		return $base;
	}
	$dt->modify('-1 day');
	return $dt->format('Y-m-d');
}

function shiftplanner_normalize_employee_key_php($value)
{
	if ($value === null) {
		return null;
	}
	$str = trim((string) $value);
	return $str === '' ? null : $str;
}

function shiftplanner_normalize_task_key_php($value)
{
	if ($value === null) {
		return null;
	}
	$str = trim((string) $value);
	return $str === '' ? null : $str;
}

function shiftplanner_validate_minute_step($value)
{
	$step = (int) $value;
	if ($step < 1 || $step > 60) {
		return 30;
	}
	if (60 % $step !== 0) {
		return 30;
	}
	return $step;
}

/**
 * True when contracted hours should come from user extrafields.horasefect.
 */
function shiftplanner_use_extrafield_hours()
{
	global $conf;

	return !empty($conf->global->DOLIZSYNCH_SHIFT_USE_EXTRAFIELD_HOURS);
}

/**
 * Convert DB values into a normalized float or null.
 */
function shiftplanner_sanitize_hours_value($value)
{
	if ($value === null) {
		return null;
	}
	if (is_string($value)) {
		$value = trim($value);
		if ($value === '') {
			return null;
		}
	}
	if (!is_numeric($value)) {
		return null;
	}
	return (float) $value;
}

/**
 * Resolve contracted hours based on the configured source with fallback.
 */
function shiftplanner_resolve_contracted_hours($horasefectValue, $weeklyHoursValue)
{
	$useExtrafield = shiftplanner_use_extrafield_hours();
	$primary = $useExtrafield ? $horasefectValue : $weeklyHoursValue;
	$fallback = $useExtrafield ? $weeklyHoursValue : $horasefectValue;

	$cleanPrimary = shiftplanner_sanitize_hours_value($primary);
	if ($cleanPrimary !== null) {
		return $cleanPrimary;
	}

	return shiftplanner_sanitize_hours_value($fallback);
}

function shiftplanner_render_time_options($selectedValue, $minuteStep)
{
	$minuteStep = shiftplanner_validate_minute_step($minuteStep);
	$selectedLabel = shiftplanner_validate_time_label($selectedValue) ?: '00:00';
	$options = '';
	for ($minutes = 0; $minutes < 24 * 60; $minutes += $minuteStep) {
		$hours = floor($minutes / 60);
		$mins = $minutes % 60;
		$label = sprintf('%02d:%02d', $hours, $mins);
		$selectedAttr = ($label === $selectedLabel) ? ' selected' : '';
		$options .= '<option value="' . dol_escape_htmltag($label) . '"' . $selectedAttr . '>' . dol_escape_htmltag($label) . '</option>';
	}
	return $options;
}

/**
 * Resolve the ISO date for the Monday of a requested week parameter (YYYY-MM-DD).
 *
 * @param string $weekParam
 * @return string ISO date (Y-m-d) pointing to the Monday of the week
 */
function shiftplanner_resolve_week_start_date($weekParam)
{
	$weekParam = trim((string) $weekParam);
	if ($weekParam !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $weekParam)) {
		$dt = DateTime::createFromFormat('Y-m-d', $weekParam);
	} else {
		$dt = new DateTime();
	}
	if (!$dt) {
		$dt = new DateTime();
	}
	$day = (int) $dt->format('w'); // 0 (Sun) .. 6 (Sat)
	$offset = ($day + 6) % 7; // convert to Monday-based offset
	if ($offset > 0) {
		$dt->modify('-' . $offset . ' days');
	}
	return $dt->format('Y-m-d');
}

/**
 * Fetch public holidays for the provided country in the range starting at $weekStartIso.
 * If $rangeEndIso is empty, a 7 day window is used.
 *
 * @param DoliDB $db
 * @param int $countryId
 * @param string $weekStartIso
 * @param string $rangeEndIso Exclusive range end (Y-m-d) or empty for +7 days
 * @return array<int, array{date: string, name: string}>
 */
function shiftplanner_fetch_public_holidays(DoliDB $db, $countryId, $weekStartIso, $rangeEndIso = '')
{
	global $conf;

	require_once DOL_DOCUMENT_ROOT . '/core/lib/date.lib.php';

	$holidays = array();
	$countryId = (int) $countryId;
	if (empty($weekStartIso) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $weekStartIso)) {
		$weekStartIso = shiftplanner_resolve_week_start_date('');
	}
	$weekStartTs = strtotime($weekStartIso . ' 00:00:00');
	if ($weekStartTs === false) {
		return $holidays;
	}
	$rangeEndIso = trim((string) $rangeEndIso);
	if ($rangeEndIso !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $rangeEndIso)) {
		$weekEndTs = strtotime($rangeEndIso . ' 00:00:00');
		if ($weekEndTs === false || $weekEndTs <= $weekStartTs) {
			$weekEndTs = strtotime('+7 days', $weekStartTs);
		}
	} else {
		$weekEndTs = strtotime('+7 days', $weekStartTs);
	}

	$entries = array();
	$table = MAIN_DB_PREFIX . "c_hrm_public_holiday";
	$sql = "SELECT id, code, dayrule, year, month, day, fk_country";
	$sql .= " FROM " . $table;
	$sql .= " WHERE active = 1";
	$countryFilter = ($countryId > 0) ? " IN (0, " . $countryId . ")" : " IN (0)";
	$sql .= " AND (fk_country IS NULL OR fk_country" . $countryFilter . ")";
	// Public holidays are common across entities; do not filter by entity
	$sql .= " ORDER BY month ASC, day ASC";

	$resql = $db->query($sql);
	if ($resql) {
		while ($obj = $db->fetch_object($resql)) {
			$entries[] = $obj;
		}
		$db->free($resql);
	}

	if (empty($entries)) {
		return $holidays;
	}

	$holidayLabelsByDate = array();
	$currentTs = $weekStartTs;
	while ($currentTs < $weekEndTs) {
		$dateIso = dol_print_date($currentTs, '%Y-%m-%d');
		$labels = array();
		foreach ($entries as $entry) {
			if (shiftplanner_entry_matches_holiday_date($entry, $currentTs)) {
				$labels[] = shiftplanner_format_public_holiday_name($entry);
			}
		}
		if (!empty($labels)) {
			$holidayLabelsByDate[$dateIso] = implode(', ', array_unique($labels));
		}
		$currentTs += 86400;
	}

	foreach ($holidayLabelsByDate as $dateIso => $label) {
		$holidays[] = array(
			'date' => $dateIso,
			'name' => $label,
		);
	}

	return $holidays;
}

function shiftplanner_fetch_hourly_summary(DoliDB $db, $weekStartIso, $weekEndIso, array $dayWindow)
{
	global $conf;

	$result = [];
	if (empty($weekStartIso) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $weekStartIso)) {
		$weekStartIso = shiftplanner_resolve_week_start_date('');
	}
	$weekEndIso = trim((string) $weekEndIso);
	if ($weekEndIso === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $weekEndIso)) {
		$weekEndIso = date('Y-m-d', strtotime($weekStartIso . ' +7 days'));
	}
	$rangeStartSql = $weekStartIso . ' 00:00:00';
	$rangeEndSql = $weekEndIso . ' 23:59:59';

	$meta = shiftplanner_build_daywindow_meta($dayWindow);
	$binCount = (int) $meta['bin_count'];
	if ($binCount <= 0) {
		return $result;
	}
	$dayStartMinutes = (int) $meta['start_minutes'];

	$table = MAIN_DB_PREFIX . 'dolizsynch_shift';
	$rangeStartEscaped = $db->escape($rangeStartSql);
	$rangeEndEscaped = $db->escape($rangeEndSql);
	$entityId = (int) $conf->entity;
	$dbType = strtolower((string) ($db->type ?? ''));

	if (strpos($dbType, 'pgsql') !== false) {
		$sql = "WITH RECURSIVE filtered AS (";
		$sql .= " SELECT fk_user AS employee_id, fk_task AS task_id, date_start::timestamp AS shift_start, date_end::timestamp AS shift_end";
		$sql .= " FROM " . $table;
		$sql .= " WHERE date_end >= '" . $rangeStartEscaped . "'";
		$sql .= " AND date_start <= '" . $rangeEndEscaped . "'";
		$sql .= " AND entity = " . $entityId;
		$sql .= " AND date_end > date_start";
		$sql .= "), expanded_hours AS (";
		$sql .= " SELECT";
		$sql .= " employee_id,";
		$sql .= " task_id,";
		$sql .= " generate_series(";
		$sql .= "   date_trunc('hour', shift_start),";
		$sql .= "   shift_end - INTERVAL '1 second',";
		$sql .= "   INTERVAL '1 hour'";
		$sql .= " ) AS hour_start";
		$sql .= " FROM filtered";
		$sql .= ")";
		$sql .= " SELECT";
		$sql .= " DATE(hour_start) AS work_date,";
		$sql .= " EXTRACT(HOUR FROM hour_start) AS work_hour,";
		$sql .= " task_id,";
		$sql .= " COUNT(DISTINCT employee_id) AS employees_on_shift";
		$sql .= " FROM expanded_hours";
		$sql .= " GROUP BY work_date, work_hour, task_id";
		$sql .= " ORDER BY work_date, work_hour, task_id";
	} else {
		$sql = "WITH RECURSIVE filtered AS (";
		$sql .= " SELECT fk_user AS employee_id, fk_task AS task_id, date_start AS shift_start, date_end AS shift_end";
		$sql .= " FROM " . $table;
		$sql .= " WHERE date_end >= '" . $rangeStartEscaped . "'";
		$sql .= " AND date_start <= '" . $rangeEndEscaped . "'";
		$sql .= " AND entity = " . $entityId;
		$sql .= " AND date_end > date_start";
		$sql .= "), expanded_hours AS (";
		$sql .= " SELECT";
		$sql .= " employee_id,";
		$sql .= " task_id,";
		$sql .= " shift_start,";
		$sql .= " shift_end,";
		$sql .= " CAST(DATE_FORMAT(shift_start, '%Y-%m-%d %H:00:00') AS DATETIME) AS hour_start";
		$sql .= " FROM filtered";
		$sql .= " UNION ALL";
		$sql .= " SELECT";
		$sql .= " employee_id,";
		$sql .= " task_id,";
		$sql .= " shift_start,";
		$sql .= " shift_end,";
		$sql .= " DATE_ADD(hour_start, INTERVAL 1 HOUR)";
		$sql .= " FROM expanded_hours";
		$sql .= " WHERE DATE_ADD(hour_start, INTERVAL 1 HOUR) < shift_end";
		$sql .= ")";
		$sql .= " SELECT";
		$sql .= " DATE(hour_start) AS work_date,";
		$sql .= " HOUR(hour_start) AS work_hour,";
		$sql .= " task_id,";
		$sql .= " COUNT(DISTINCT employee_id) AS employees_on_shift";
		$sql .= " FROM expanded_hours";
		$sql .= " GROUP BY work_date, work_hour, task_id";
		$sql .= " ORDER BY work_date, work_hour, task_id";
	}

	$resql = $db->query($sql);
	if (!$resql) {
		return $result;
	}

	while ($obj = $db->fetch_object($resql)) {
		$workDate = $obj->work_date;
		$workHour = isset($obj->work_hour) ? (int) $obj->work_hour : 0;
		$taskKey = shiftplanner_normalize_task_key_php($obj->task_id) ?: '__UNASSIGNED__';
		$count = isset($obj->employees_on_shift) ? (int) $obj->employees_on_shift : 0;
		if ($count <= 0) {
			continue;
		}

		$hourStartTs = strtotime($workDate . ' ' . str_pad((string) $workHour, 2, '0', STR_PAD_LEFT) . ':00:00');
		if ($hourStartTs === false) {
			continue;
		}
		$displayDateIso = shiftplanner_get_display_date_php($hourStartTs, $meta);
		if ($displayDateIso === '') {
			continue;
		}

		$normalizedMinute = shiftplanner_normalize_minutes_php($workHour * 60, $meta);
		if ($normalizedMinute === null) {
			continue;
		}
		$offsetMinutes = $normalizedMinute - $dayStartMinutes;
		if ($offsetMinutes < 0) {
			continue;
		}
		$binIndex = (int) floor($offsetMinutes / 60);
		if ($binIndex < 0 || $binIndex >= $binCount) {
			continue;
		}

		if (!isset($result[$displayDateIso])) {
			$result[$displayDateIso] = [];
			for ($i = 0; $i < $binCount; $i++) {
				$result[$displayDateIso][$i] = new stdClass();
			}
		}

		$result[$displayDateIso][$binIndex]->{$taskKey} = $count;
	}
	$db->free($resql);

	return $result;
}

function shiftplanner_entry_matches_holiday_date($entry, $targetTimestamp)
{
	if (!$entry) {
		return false;
	}
	$targetIso = dol_print_date($targetTimestamp, '%Y-%m-%d');
	if (!$targetIso) {
		return false;
	}
	list($targetYear, $targetMonth, $targetDay) = array_map('intval', explode('-', $targetIso));

	$dayrule = trim((string) ($entry->dayrule ?? ''));
	if ($dayrule !== '' && $dayrule !== 'date') {
		$ruleIso = shiftplanner_compute_special_holiday_iso($dayrule, $targetYear);
		return $ruleIso === $targetIso;
	}

	$entryMonth = (int) ($entry->month ?? 0);
	$entryDay = (int) ($entry->day ?? 0);
	if ($entryMonth <= 0 || $entryDay <= 0) {
		return false;
	}

	if ($entryMonth !== $targetMonth || $entryDay !== $targetDay) {
		return false;
	}

	$entryYear = (int) ($entry->year ?? 0);
	if ($entryYear > 0 && $entryYear !== $targetYear) {
		return false;
	}

	return true;
}

function shiftplanner_compute_special_holiday_iso($rule, $year)
{
	$ruleKey = strtolower(trim((string) $rule));
	if ($ruleKey === '' || $ruleKey === 'date') {
		return null;
	}
	$ruleKey = preg_replace('/[^a-z0-9]/', '', $ruleKey);

	switch ($ruleKey) {
		case 'easter':
		case 'eastersunday':
			$timestamp = getGMTEasterDatetime($year);
			break;
		case 'eastermonday':
		case 'eastermon':
			$timestamp = getGMTEasterDatetime($year) + (86400);
			break;
		case 'goodfriday':
		case 'viernessanto':
		case 'goodfridayday':
			$timestamp = getGMTEasterDatetime($year) - (86400 * 2);
			break;
		case 'ascension':
		case 'ascensionday':
			$timestamp = getGMTEasterDatetime($year) + (86400 * 39);
			break;
		case 'pentecost':
		case 'pentecote':
		case 'pentecostsunday':
			$timestamp = getGMTEasterDatetime($year) + (86400 * 49);
			break;
		case 'pentecotemonday':
		case 'pentecostmonday':
		case 'lundiPentecote':
			$timestamp = getGMTEasterDatetime($year) + (86400 * 50);
			break;
		case 'fronleichnam':
			$timestamp = getGMTEasterDatetime($year) + (86400 * 60);
			break;
		case 'genevafast':
			$timestamp = shiftplanner_compute_geneva_fast_timestamp($year);
			break;
		case 'carnival':
		case 'carnaval':
		case 'carnivalday':
		case 'carnavalday':
		case 'carnivalmonday':
		case 'carnavaltuesday':
			$timestamp = getGMTEasterDatetime($year) - (86400 * 47);
			break;
		default:
			$timestamp = null;
	}

	if ($timestamp === null) {
		return null;
	}

	return dol_print_date($timestamp, '%Y-%m-%d');
}

function shiftplanner_compute_geneva_fast_timestamp($year)
{
	try {
		$tz = new DateTimeZone('UTC');
		$dt = new DateTimeImmutable(sprintf('%04d-09-01 00:00:00', $year), $tz);
		while ((int) $dt->format('w') !== 0) {
			$dt = $dt->modify('+1 day');
		}
		$dt = $dt->modify('+4 days');
		return $dt->getTimestamp();
	} catch (Exception $e) {
		return null;
	}
}

if (!function_exists('shiftplannerComputeMinutesBetween')) {
	function shiftplannerComputeMinutesBetween($startTs, $endTs)
	{
		if (empty($startTs) || empty($endTs) || $endTs <= $startTs) {
			return 0;
		}
		return (int) round(($endTs - $startTs) / 60);
	}
}

if (!function_exists('shiftplannerComputeMinutesFromRange')) {
	function shiftplannerComputeMinutesFromRange($startLabel, $endLabel)
	{
		if (!preg_match('/^\\d{2}:\\d{2}$/', (string) $startLabel) || !preg_match('/^\\d{2}:\\d{2}$/', (string) $endLabel)) {
			return 0;
		}
		list($sh, $sm) = array_map('intval', explode(':', $startLabel));
		list($eh, $em) = array_map('intval', explode(':', $endLabel));
		$startMinutes = ($sh * 60) + $sm;
		$endMinutes = ($eh * 60) + $em;
		$diff = $endMinutes - $startMinutes;
		if ($diff <= 0) {
			$diff += 24 * 60;
		}
		return $diff;
	}
}

function shiftplanner_format_public_holiday_name($entry)
{
	$label = trim((string) ($entry->code ?? ''));
	if ($label === '') {
		$label = trim((string) ($entry->dayrule ?? ''));
	}
	if ($label === '') {
		$label = 'Holiday';
	}
	$label = str_replace('_', ' ', $label);

	return trim($label);
}

if (!function_exists('shiftplannerNormalizeTimestamp')) {
	/**
	 * Normalize unix timestamp or SQL datetime to unix timestamp
	 *
	 * @param DoliDB $db
	 * @param mixed  $value
	 * @return int
	 */
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
}

if (!function_exists('shiftplanner_get_day_change_hour')) {
	/**
	 * Hour of the day when the operational day changes (defaults to 0).
	 */
	function shiftplanner_get_day_change_hour()
	{
		global $conf;

		$hour = isset($conf->global->ZS_PUNCH_DAY_CHANGE_HOUR) ? (int) $conf->global->ZS_PUNCH_DAY_CHANGE_HOUR : 0;
		if ($hour < 0 || $hour > 23) {
			return 0;
		}
		return $hour;
	}
}

if (!function_exists('shiftplanner_parse_wall_clock_datetime')) {
	/**
	 * Parse a planner datetime without applying the PHP host timezone.
	 *
	 * Shift-planner datetimes are persisted as business wall-clock values. Using
	 * strtotime() or date() here would make their operational date depend on the
	 * web/CLI host timezone.
	 *
	 * @param string $value ISO or SQL datetime
	 * @return DateTimeImmutable|null
	 */
	function shiftplanner_parse_wall_clock_datetime($value)
	{
		$value = trim((string) $value);
		if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})[T\s](\d{2}):(\d{2})(?::(\d{2}))?/', $value, $matches)) {
			return null;
		}

		$normalized = sprintf(
			'%04d-%02d-%02d %02d:%02d:%02d',
			(int) $matches[1],
			(int) $matches[2],
			(int) $matches[3],
			(int) $matches[4],
			(int) $matches[5],
			isset($matches[6]) && $matches[6] !== '' ? (int) $matches[6] : 0
		);
		$date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $normalized, new DateTimeZone('UTC'));
		$errors = DateTimeImmutable::getLastErrors();
		if (!$date || (is_array($errors) && (!empty($errors['warning_count']) || !empty($errors['error_count'])))) {
			return null;
		}

		return $date;
	}
}

if (!function_exists('shiftplanner_build_effective_leave_range')) {
	/**
	 * Convert stored leave boundaries to their effective half-open wall-clock range.
	 *
	 * Full-day, half-day, and minutes-based leave rows are persisted at the
	 * configured cutoff for compatibility. When the cutoff is positive, that
	 * exact second belongs to the preceding operational day, so a leave starting
	 * on the selected day becomes effective one second later.
	 *
	 * @param string   $startValue Stored start datetime
	 * @param string   $endValue Stored end datetime
	 * @param int|null $dayChangeHour Operational-day cutoff hour
	 * @return array<string,string>
	 */
	function shiftplanner_build_effective_leave_range($startValue, $endValue, $dayChangeHour = null)
	{
		if ($dayChangeHour === null) {
			$dayChangeHour = shiftplanner_get_day_change_hour();
		}
		$dayChangeHour = (int) $dayChangeHour;
		if ($dayChangeHour < 0 || $dayChangeHour > 23) {
			$dayChangeHour = 0;
		}

		$start = shiftplanner_parse_wall_clock_datetime($startValue);
		$end = shiftplanner_parse_wall_clock_datetime($endValue);
		if (!$start || !$end || $end <= $start) {
			return array();
		}

		if ($dayChangeHour > 0) {
			$cutoffTime = sprintf('%02d:00:00', $dayChangeHour);
			if ($start->format('H:i:s') === $cutoffTime) {
				$start = $start->modify('+1 second');
			}
			if ($end->format('H:i:s') === $cutoffTime) {
				$end = $end->modify('+1 second');
			}
		}

		return array(
			'start_sql' => $start->format('Y-m-d H:i:s'),
			'end_sql' => $end->format('Y-m-d H:i:s'),
		);
	}
}

if (!function_exists('shiftplanner_build_operational_date_map_from_range')) {
	/**
	 * Return every operational date touched by a half-open wall-clock range.
	 *
	 * For a positive day-change hour, the new operational day begins one second
	 * after the configured boundary. With a 03:00 cutoff, 03:00:00 still belongs
	 * to the previous day and 03:00:01 belongs to the new day.
	 *
	 * @param string   $startValue Start datetime (inclusive)
	 * @param string   $endValue End datetime (exclusive)
	 * @param int|null $dayChangeHour Operational-day cutoff hour
	 * @param bool     $beginsAfterBoundary Whether the new day begins one second after the cutoff
	 * @return array<string,int>
	 */
	function shiftplanner_build_operational_date_map_from_range($startValue, $endValue, $dayChangeHour = null, $beginsAfterBoundary = true)
	{
		if ($dayChangeHour === null) {
			$dayChangeHour = shiftplanner_get_day_change_hour();
		}
		$dayChangeHour = (int) $dayChangeHour;
		if ($dayChangeHour < 0 || $dayChangeHour > 23) {
			$dayChangeHour = 0;
		}

		$start = shiftplanner_parse_wall_clock_datetime($startValue);
		$end = shiftplanner_parse_wall_clock_datetime($endValue);
		if (!$start || !$end || $end <= $start) {
			return array();
		}

		$boundaryOffset = ($dayChangeHour * 3600) + ($dayChangeHour > 0 && $beginsAfterBoundary ? 1 : 0);
		$firstOperationalInstant = $start->modify('-' . $boundaryOffset . ' seconds');
		$lastOperationalInstant = $end->modify('-1 second')->modify('-' . $boundaryOffset . ' seconds');
		$firstDate = $firstOperationalInstant->setTime(0, 0, 0);
		$lastDate = $lastOperationalInstant->setTime(0, 0, 0);

		$map = array();
		for ($cursor = $firstDate; $cursor <= $lastDate; $cursor = $cursor->modify('+1 day')) {
			$map[$cursor->format('Y-m-d')] = (int) $cursor->format('U');
		}

		return $map;
	}
}

if (!function_exists('shiftplanner_operational_day_start_from_iso')) {
	function shiftplanner_operational_day_start_from_iso($isoDate, $dayChangeHour = null)
	{
		if ($dayChangeHour === null) {
			$dayChangeHour = shiftplanner_get_day_change_hour();
		}
		$isoDate = substr(trim((string) $isoDate), 0, 10);
		if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $isoDate, $m)) {
			return 0;
		}
		[$all, $year, $month, $day] = $m;
		return dol_mktime((int) $dayChangeHour, 0, 0, (int) $month, (int) $day, (int) $year);
	}
}

if (!function_exists('shiftplanner_operational_boundary_for_timestamp')) {
	function shiftplanner_operational_boundary_for_timestamp($ts, $dayChangeHour = null)
	{
		if ($dayChangeHour === null) {
			$dayChangeHour = shiftplanner_get_day_change_hour();
		}
		$boundary = dol_mktime((int) $dayChangeHour, 0, 0, date('n', $ts), date('j', $ts), date('Y', $ts));
		if ($ts < $boundary) {
			$boundary -= 86400;
		}
		return $boundary;
	}
}

if (!function_exists('shiftplanner_build_operational_day_range')) {
	function shiftplanner_build_operational_day_range($dateTs, $dayChangeHour = null)
	{
		if ($dayChangeHour === null) {
			$dayChangeHour = shiftplanner_get_day_change_hour();
		}
		$startTs = dol_mktime((int) $dayChangeHour, 0, 0, date('n', $dateTs), date('j', $dateTs), date('Y', $dateTs));
		$endTs = $startTs + 86400;
		return [
			'start_ts' => $startTs,
			'end_ts' => $endTs,
		];
	}
}

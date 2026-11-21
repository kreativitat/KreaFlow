<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

require_once __DIR__ . '/zsleaves.class.php';
require_once __DIR__ . '/../lib/shiftplanner.lib.php';

/** Read-only projection of the custom leave ledger, shared by HTML and XLSX. */
class KreaFlowVacationReport
{
	public static function canRead($conf, $user)
	{
		return !empty($conf->dolizsynch->enabled) && empty($user->socid)
			&& !empty($user->rights->dolizsynch->shift->schedule_vacations_view);
	}

	/** Query failures must never become an apparently empty report. */
	private static function rows($db, $sql)
	{
		$result = $db->query($sql);
		if (!$result) {
			throw new RuntimeException('Unable to read vacation report source');
		}
		$rows = array();
		while ($row = $db->fetch_object($result)) $rows[] = $row;
		$db->free($result);
		return $rows;
	}

	public static function load($db, $conf, $user, $year, $today)
	{
		if (!self::canRead($conf, $user)) throw new RuntimeException('Vacation report access denied');
		$year = (int) $year;
		if ($year < 2000 || $year > 2100) throw new InvalidArgumentException('Invalid report year');
		$entity = (int) $conf->entity;
		$helper = new ZSLeaves($db);
		$types = self::rows($db, 'SELECT rowid, code, label FROM ' . MAIN_DB_PREFIX . 'c_holiday_types'
			. ' WHERE entity IN (0, ' . $entity . ')');
		$typeIds = array();
		foreach ($types as $type) {
			if (in_array(strtoupper(trim($type->code)), array('CP', 'VAC', 'VACATION', 'FERIAS', 'FERIA', 'FERIE'), true)) {
				$typeIds[] = (int) $type->rowid;
			}
		}
		if (!$typeIds) {
			foreach ($types as $type) {
				if (preg_match('/f[eé]ri|vac/iu', $type->label)) $typeIds[] = (int) $type->rowid;
			}
		}
		$typeSql = implode(',', $typeIds ?: array(-1));
		// Fetch a bounded margin: stored operational cutoffs can cross calendar midnight.
		$lower = sprintf('%04d-12-31 00:00:00', $year - 1);
		$upper = sprintf('%04d-01-02 00:00:00', $year + 1);
		$records = self::rows($db, 'SELECT rowid, fk_user, fk_type, date_start, date_end, halfday, minutes_total, day_change_hour'
			. ' FROM ' . MAIN_DB_PREFIX . 'dolizsynch_leave'
			. ' WHERE (entity IS NULL OR entity IN (0, ' . $entity . '))'
			. ' AND fk_type IN (' . $typeSql . ')'
			. " AND date_end > '" . $db->escape($lower) . "' AND date_start < '" . $db->escape($upper) . "'");
		$ids = $helper->getEntityEmployeesForYear($year, $entity, 2, 'u.firstname');
		if (!empty($helper->errors)) throw new RuntimeException('Unable to read vacation employees');
		// Retain historical employees with company-owned leave, as on the annual overview.
		$historicalIds = array();
		foreach ($records as $record) {
			$ids[] = (int) $record->fk_user;
			$historicalIds[] = (int) $record->fk_user;
		}
		$ids = array_values(array_filter(array_unique(array_map('intval', $ids)), function ($id) { return $id > 0; }));
		$idSql = implode(',', $ids ?: array(-1));
		$historicalSql = implode(',', array_unique($historicalIds) ?: array(-1));
		$employees = self::rows($db, 'SELECT rowid, firstname, lastname, ref_employee FROM ' . MAIN_DB_PREFIX . 'user'
			. ' WHERE rowid IN (' . $idSql . ') AND (employee = 1 OR rowid IN (' . $historicalSql . '))'
			. ' ORDER BY firstname, lastname, rowid');
		$quotas = self::rows($db, 'SELECT fk_user, fk_type, year, days_total, entity FROM ' . MAIN_DB_PREFIX . 'dolizsynch_leave_quota'
			. ' WHERE entity IN (0, ' . $entity . ') AND year IN (0, ' . $year . ')'
			. ' AND fk_type IN (' . $typeSql . ') AND fk_user IN (' . $idSql . ') ORDER BY year DESC, entity DESC');
		$hours = (float) getDolGlobalString('DOLIZSYNCH_SHIFT_MAX_HOURS', '8');
		$report = self::project($year, $employees, $records, $quotas, $today, max(1, ($hours > 0 ? $hours : 8) * 60));
		$report['has_vacation_type'] = (bool) $typeIds;
		global $mysoc;
		$country = (int) ($mysoc->country_id ?? 0);
		$holidayRows = self::rows($db, 'SELECT code, dayrule, year, month, day FROM ' . MAIN_DB_PREFIX . 'c_hrm_public_holiday'
			. ' WHERE active = 1 AND (fk_country IS NULL OR fk_country IN (0, ' . $country . '))');
		$report['holidays'] = array();
		// Evaluate native rules once per calendar date, avoiding fixed-second DST steps.
		foreach ($holidayRows as $holiday) {
			$iso = !empty($holiday->dayrule) && $holiday->dayrule !== 'date'
				? shiftplanner_compute_special_holiday_iso($holiday->dayrule, $year)
				: sprintf('%04d-%02d-%02d', $year, $holiday->month, $holiday->day);
			if (!$iso || (!empty($holiday->year) && (int) $holiday->year !== (int) $year)) continue;
			$report['holidays'][$iso] = shiftplanner_format_public_holiday_name($holiday);
		}
		return $report;
	}

	/** Calendar arithmetic preserves DST, half-open ends and stored operational dates. */
	public static function project($year, array $employees, array $records, array $quotas, $today, $dayMinutes = 480)
	{
		$rows = array();
		$invalidRecords = 0;
		foreach ($employees as $employee) {
			$rows[(int) $employee->rowid] = array(
				'id' => (int) $employee->rowid,
				'name' => trim($employee->firstname . ' ' . $employee->lastname),
				'number' => (string) $employee->ref_employee,
				'days' => array(), 'months' => array_fill(1, 12, 0), 'scheduled' => 0,
				'elapsed' => 0, 'entitlement' => null, 'unplanned' => null, 'remaining' => null,
			);
		}
		foreach ($records as $record) {
			$id = (int) $record->fk_user;
			if (!isset($rows[$id])) continue;
			$cutoff = isset($record->day_change_hour) ? (int) $record->day_change_hour : shiftplanner_get_day_change_hour();
			$mode = (int) $record->halfday;
			$start = shiftplanner_parse_wall_clock_datetime($record->date_start);
			$end = shiftplanner_parse_wall_clock_datetime($record->date_end);
			if (!$start || !$end || $end < $start || $mode < 0 || $mode > 3) { $invalidRecords++; continue; }
			// The standalone card stores inclusive date-only endpoints. Planner rows use
			// cutoff-to-next-cutoff intervals. At cutoff zero, retain the planner contract.
			$dateOnly = $mode <= 1 && $start->format('H:i:s') === '00:00:00' && $end->format('H:i:s') === '00:00:00'
				&& ($cutoff > 0 || $start == $end);
			if ($dateOnly) {
				$dates = array();
				for ($date = $start; $date <= $end; $date = $date->modify('+1 day')) $dates[$date->format('Y-m-d')] = 1;
			} else {
				$range = shiftplanner_build_effective_leave_range($record->date_start, $record->date_end, $cutoff);
				if (!$range) { $invalidRecords++; continue; }
				$dates = shiftplanner_build_operational_date_map_from_range($range['start_sql'], $range['end_sql'], $cutoff);
			}
			$fraction = 1;
			if ($mode === 1) $fraction = 0.5;
			elseif ($mode === 2 || $mode === 3) {
				$minutes = $mode === 3 ? (int) $record->minutes_total
					: (shiftplanner_parse_wall_clock_datetime($record->date_end)->getTimestamp() - shiftplanner_parse_wall_clock_datetime($record->date_start)->getTimestamp()) / 60;
				if ($minutes <= 0) { $invalidRecords++; continue; }
				$fraction = min(1, $minutes / max(1, $dayMinutes));
			}
			$firstDate = key($dates);
			foreach ($dates as $date => $unused) {
				if ((int) substr($date, 0, 4) !== (int) $year) continue;
				$weight = $mode ? ($date === $firstDate ? $fraction : 0) : 1;
				if ($mode === 2) {
					$boundary = shiftplanner_parse_wall_clock_datetime(sprintf('%s %02d:00:00', $date, $cutoff));
					$overlap = max(0, min($end->getTimestamp(), $boundary->modify('+1 day')->getTimestamp()) - max($start->getTimestamp(), $boundary->getTimestamp()));
					$weight = $fraction * $overlap / max(1, $end->getTimestamp() - $start->getTimestamp());
				}
				if (!$weight) continue;
				$previous = $rows[$id]['days'][$date] ?? 0;
				// Overlapping full days are one absence, rather than duplicate vacation usage.
				$rows[$id]['days'][$date] = min(1, $previous + $weight);
			}
		}
		$seen = array();
		foreach ($quotas as $quota) {
			$id = (int) $quota->fk_user;
			$key = $id . ':' . (int) $quota->fk_type;
			if (!isset($rows[$id]) || isset($seen[$key])) continue;
			$seen[$key] = true;
			if ($rows[$id]['entitlement'] === null) $rows[$id]['entitlement'] = 0;
			$rows[$id]['entitlement'] += (float) $quota->days_total;
		}
		foreach ($rows as &$row) {
			ksort($row['days']);
			foreach ($row['days'] as $date => $value) {
				$row['months'][(int) substr($date, 5, 2)] += $value;
				$row['scheduled'] += $value;
				if ($date < $today) $row['elapsed'] += $value;
			}
			if ($row['entitlement'] !== null) {
				$row['unplanned'] = $row['entitlement'] - $row['scheduled'];
				$row['remaining'] = $row['entitlement'] - $row['elapsed'];
			}
		}
		unset($row);
		return array('year' => (int) $year, 'today' => $today, 'employees' => $rows, 'has_vacation_type' => true, 'holidays' => array(), 'invalid_records' => $invalidRecords);
	}

	public static function number($value)
	{
		return $value === null ? '—' : rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.');
	}
}

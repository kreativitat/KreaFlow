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

require_once __DIR__ . '/../lib/shiftplanner.lib.php';

if (!defined('MAIN_DB_PREFIX')) {
	define('MAIN_DB_PREFIX', 'llx_');
}
if (!class_exists('DoliDB')) {
	class DoliDB
	{
	}
}
require_once __DIR__ . '/../class/zsshiftplanneractions.class.php';

class OperationalDayFakeResult
{
	public $rows;
	public $position = 0;

	public function __construct(array $rows)
	{
		$this->rows = $rows;
	}
}

class OperationalDayFakeDb extends DoliDB
{
	private $rows;

	public function __construct(array $rows)
	{
		$this->rows = $rows;
	}

	public function escape($value)
	{
		return addslashes((string) $value);
	}

	public function idate($timestamp)
	{
		return gmdate('Y-m-d H:i:s', (int) $timestamp);
	}

	public function query($sql)
	{
		return new OperationalDayFakeResult($this->rows);
	}

	public function fetch_object($result)
	{
		if (!isset($result->rows[$result->position])) {
			return false;
		}
		return (object) $result->rows[$result->position++];
	}

	public function free($result)
	{
		return true;
	}

	public function lasterror()
	{
		return '';
	}
}

/**
 * @param mixed  $expected Expected value
 * @param mixed  $actual Actual value
 * @param string $message Failure message
 * @return void
 */
function operationalDayAssertSame($expected, $actual, $message)
{
	if ($expected !== $actual) {
		fwrite(STDERR, $message . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true) . "\n");
		exit(1);
	}
}

$originalTimezone = date_default_timezone_get();
$timezones = array('Europe/Lisbon', 'Europe/Zurich', 'UTC');

foreach ($timezones as $timezone) {
	date_default_timezone_set($timezone);

	$map = shiftplanner_build_operational_date_map_from_range(
		'2026-09-05 18:00:00',
		'2026-09-06 02:00:00',
		3
	);
	operationalDayAssertSame(
		array('2026-09-05'),
		array_keys($map),
		'An 18:00-02:00 shift must remain on the September 5 operational day in ' . $timezone
	);

	$map = shiftplanner_build_operational_date_map_from_range(
		'2026-09-05 18:00:00',
		'2026-09-06 03:00:01',
		3
	);
	operationalDayAssertSame(
		array('2026-09-05'),
		array_keys($map),
		'A range ending at 03:00:01 must not collide with the new operational day in ' . $timezone
	);

	$map = shiftplanner_build_operational_date_map_from_range(
		'2026-09-05 18:00:00',
		'2026-09-06 03:00:02',
		3
	);
	operationalDayAssertSame(
		array('2026-09-05', '2026-09-06'),
		array_keys($map),
		'A range continuing after 03:00:01 must touch the new operational day in ' . $timezone
	);

	$map = shiftplanner_build_operational_date_map_from_range(
		'2026-09-06 03:00:01',
		'2026-09-06 04:00:00',
		3
	);
	operationalDayAssertSame(
		array('2026-09-06'),
		array_keys($map),
		'A range starting at 03:00:01 must belong to the new operational day in ' . $timezone
	);

	$map = shiftplanner_build_operational_date_map_from_range(
		'2026-09-06 03:00:00',
		'2026-09-07 03:00:00',
		3,
		false
	);
	operationalDayAssertSame(
		array('2026-09-06'),
		array_keys($map),
		'A stored full-day leave must retain its selected operational date in ' . $timezone
	);

	$effectiveRange = shiftplanner_build_effective_leave_range(
		'2026-08-28 03:00:00',
		'2026-09-30 03:00:00',
		3
	);
	operationalDayAssertSame(
		array(
			'start_sql' => '2026-08-28 03:00:01',
			'end_sql' => '2026-09-30 03:00:01',
		),
		$effectiveRange,
		'Full-day leave boundaries must become effective at 03:00:01 in ' . $timezone
	);
	$effectiveMap = shiftplanner_build_operational_date_map_from_range(
		$effectiveRange['start_sql'],
		$effectiveRange['end_sql'],
		3
	);
	$effectiveDates = array_keys($effectiveMap);
	operationalDayAssertSame(33, count($effectiveDates), 'The requested leave must contain 33 operational dates in ' . $timezone);
	operationalDayAssertSame('2026-08-28', reset($effectiveDates), 'The requested leave must start on August 28 in ' . $timezone);
	operationalDayAssertSame('2026-09-29', end($effectiveDates), 'The requested leave must end on September 29 in ' . $timezone);
}

date_default_timezone_set($originalTimezone);

$conf = (object) array(
	'entity' => 1,
	'global' => (object) array('ZS_PUNCH_DAY_CHANGE_HOUR' => 3),
);
$fullDayLeave = array(array(
	'rowid' => 10,
	'date_start' => '2026-09-06 03:00:00',
	'date_end' => '2026-09-07 03:00:00',
	'halfday' => 0,
	'minutes_total' => 0,
	'day_change_hour' => 3,
));
$db = new OperationalDayFakeDb($fullDayLeave);
operationalDayAssertSame(
	false,
	ZSShiftplannerActions::shiftplannerHasLeaveOverlap(
		$db,
		101,
		'2026-09-05 18:00:00',
		'2026-09-06 03:00:01'
	),
	'A shift ending at 03:00:01 must not overlap the following full-day leave'
);
operationalDayAssertSame(
	true,
	ZSShiftplannerActions::shiftplannerHasLeaveOverlap(
		$db,
		101,
		'2026-09-05 18:00:00',
		'2026-09-06 03:00:02'
	),
	'A shift extending beyond 03:00:01 must overlap the following full-day leave'
);

$previousFullDayLeave = array(array(
	'rowid' => 11,
	'date_start' => '2026-09-05 03:00:00',
	'date_end' => '2026-09-06 03:00:00',
	'halfday' => 0,
	'minutes_total' => 0,
	'day_change_hour' => 3,
));
$db = new OperationalDayFakeDb($previousFullDayLeave);
operationalDayAssertSame(
	true,
	ZSShiftplannerActions::shiftplannerHasLeaveOverlap(
		$db,
		101,
		'2026-09-06 03:00:00',
		'2026-09-06 04:00:00'
	),
	'A shift starting at 03:00:00 must still overlap the preceding operational leave day'
);

$adjacentPreviousLeave = array(array(
	'rowid' => 1211,
	'date_start' => '2026-07-29 03:00:00',
	'date_end' => '2026-08-28 03:00:00',
	'halfday' => 0,
	'minutes_total' => 0,
	'day_change_hour' => 3,
));
$effectiveCandidate = shiftplanner_build_effective_leave_range(
	'2026-08-28 03:00:00',
	'2026-09-30 03:00:00',
	3
);
$db = new OperationalDayFakeDb($adjacentPreviousLeave);
operationalDayAssertSame(
	false,
	ZSShiftplannerActions::shiftplannerHasLeaveOverlap(
		$db,
		16,
		$effectiveCandidate['start_sql'],
		$effectiveCandidate['end_sql'],
		false,
		1296
	),
	'An adjacent full-day leave ending at the cutoff must not block a leave beginning at 03:00:01'
);

$actionsSource = file_get_contents(__DIR__ . '/../class/zsshiftplanneractions.class.php');
if ($actionsSource === false) {
	fwrite(STDERR, "Unable to read shift planner actions source\n");
	exit(1);
}

operationalDayAssertSame(
	true,
	strpos($actionsSource, 'shiftplannerBuildOperationalDateMap($dateStart, $dateEnd)') !== false,
	'Shift creation must use the timezone-neutral operational date map'
);
operationalDayAssertSame(
	true,
	strpos($actionsSource, 'shiftplannerBuildOperationalDateMap(') !== false
		&& substr_count($actionsSource, '$overlapStartSql,') >= 2,
	'Leave creation and update must use the same operational date map'
);
operationalDayAssertSame(
	true,
	substr_count($actionsSource, 'shiftplanner_build_effective_leave_range(') >= 3,
	'Leave creation, update, and stored-overlap checks must share the effective boundary helper'
);
operationalDayAssertSame(
	true,
	strpos($actionsSource, "modify('+1 day')->format('Y-m-d')") !== false,
	'Hourly leave ranges must support crossing midnight'
);

echo "Shift planner operational-day tests passed.\n";

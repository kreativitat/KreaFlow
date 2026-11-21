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

if (PHP_SAPI !== 'cli') {
	http_response_code(403);
	exit('This maintenance script is available only from the command line.');
}

// Quick helper to remap legacy halfday values to new enum
// Usage: php fix_halfday_enum.php

require_once __DIR__ . '/../../main.inc.php';

global $db;

$table = MAIN_DB_PREFIX . 'dolizsynch_leave';

$updated = 0;
$sql = "SELECT rowid, date_start, date_end, halfday, minutes_total FROM " . $table;
$res = $db->query($sql);
if ($res) {
	while ($obj = $db->fetch_object($res)) {
		$halfday = (int) $obj->halfday;
		$minutes = (int) $obj->minutes_total;
		$newHalfday = $halfday;

		// Only remap legacy "partial" values (non-zero)
		if ($halfday !== 0) {
			if ($minutes > 0) {
				$newHalfday = 3; // minutes mode
			} else {
				// If duration < 1 day, treat as period; else keep half-day
				$duration = 0;
				if (!empty($obj->date_start) && !empty($obj->date_end)) {
					$duration = abs($db->jdate($obj->date_end) - $db->jdate($obj->date_start));
				}
				$durationDays = ($duration > 0) ? ($duration / 86400) : 0;
				if ($durationDays > 0 && $durationDays < 1) {
					$newHalfday = 2;
				} else {
					$newHalfday = 1;
				}
			}
		}

		if ($newHalfday !== $halfday) {
			$upd = "UPDATE " . $table . " SET halfday = " . (int) $newHalfday . " WHERE rowid = " . (int) $obj->rowid;
			if ($db->query($upd)) {
				$updated++;
			}
		}
	}
	$db->free($res);
}

print "Remap complete. Updated rows: " . $updated . "\n";

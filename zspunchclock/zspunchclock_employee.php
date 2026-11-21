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
 * \file       zspunchclock_employee.php
 * \ingroup    dolizsynch
 * \brief      Employee punch clock entries - view paired shifts by employee and date range
 */

// Load Dolibarr environment
require '../../../main.inc.php';
require_once DOL_DOCUMENT_ROOT . '/user/class/user.class.php';
require_once DOL_DOCUMENT_ROOT . '/core/class/html.form.class.php';
require_once DOL_DOCUMENT_ROOT . '/core/class/html.formother.class.php';
require_once DOL_DOCUMENT_ROOT . '/core/lib/date.lib.php';
require_once DOL_DOCUMENT_ROOT . '/projet/class/project.class.php';
require_once DOL_DOCUMENT_ROOT . '/projet/class/task.class.php';
require_once DOL_DOCUMENT_ROOT . '/core/class/timespent.class.php';
require_once __DIR__ . '/../class/zspunchclock.class.php';

/**
 * Calculate duration between two datetime strings with proper handling of midnight crossings
 * @param string $datetime_in  IN datetime in format 'Y-m-d H:i:s'
 * @param string $datetime_out OUT datetime in format 'Y-m-d H:i:s'
 * @return array Array with 'seconds' and 'display' keys, or error info
 */
function calculateDuration($datetime_in, $datetime_out)
{
	if (empty($datetime_in) || empty($datetime_out)) {
		return array('seconds' => 0, 'display' => '', 'error' => false);
	}

	// Use DateTime objects for more precise calculation
	try {
		$dt_in = new DateTime($datetime_in);
		$dt_out = new DateTime($datetime_out);

		// Calculate the difference
		$interval = $dt_in->diff($dt_out);

		// If OUT is before IN, it likely crosses midnight
		if ($dt_out < $dt_in) {
			// Add one day to OUT time
			$dt_out->add(new DateInterval('P1D'));
			$interval = $dt_in->diff($dt_out);
		}

		// Convert to total seconds
		$total_seconds = ($interval->days * 24 * 3600) + ($interval->h * 3600) + ($interval->i * 60) + $interval->s;

		// Safety check: max 24 hours
		if ($total_seconds > 86400) {
			return array('seconds' => 0, 'display' => 'Error: >24h', 'error' => true);
		}

		// Format for display
		$hours = floor($total_seconds / 3600);
		$minutes = floor(($total_seconds % 3600) / 60);
		$display = sprintf('%02d:%02d', $hours, $minutes);

		return array('seconds' => $total_seconds, 'display' => $display, 'error' => false);
	} catch (Exception $e) {
		return array('seconds' => 0, 'display' => 'Error: Invalid', 'error' => true);
	}
}

/**
 * Check if a shift overlaps with existing shifts for the same employee
 * @param object $db Database connection
 * @param int $empid_zs Employee ZS ID
 * @param int $datahora_in IN timestamp
 * @param int $datahora_out OUT timestamp
 * @param int $exclude_rowid Row ID to exclude from check (for updates)
 * @param int|null $entity_id Optional entity filter to scope the lookup
 * @return array Array with 'overlap' boolean and 'message' string
 */
function checkShiftOverlap($db, $empid_zs, $datahora_in, $datahora_out, $exclude_rowid = 0, $entity_id = null)
{
	if (empty($empid_zs) || empty($datahora_in) || empty($datahora_out)) {
		return array('overlap' => false, 'message' => '');
	}

	// Convert timestamps to datetime strings for SQL comparison
	$in_str = date('Y-m-d H:i:s', $datahora_in);
	$out_str = date('Y-m-d H:i:s', $datahora_out);


	// Get the date part from the IN timestamp to filter by day
	$date_part = date('Y-m-d', $datahora_in);

	// Query to find overlapping shifts for the same employee ON THE SAME DAY
	// Two shifts overlap if: (StartA < EndB) AND (EndA > StartB)
	// But they DON'T overlap if one ends exactly when the other starts
	$sql = "SELECT rowid, datahora_in, datahora_out FROM " . MAIN_DB_PREFIX . "dolizsynch_zspunchclock";
	$sql .= " WHERE empid_zs = " . (int)$empid_zs;
	$sql .= " AND rowid != " . (int)$exclude_rowid;
	$sql .= " AND DATE(dataval) = '" . $db->escape($date_part) . "'";
	$sql .= " AND datahora_in IS NOT NULL AND datahora_out IS NOT NULL";

	// Keep the overlap detection scoped to the same entity to avoid false positives across stores/entities
	if ($entity_id !== null) {
		$sql .= " AND entity = " . (int)$entity_id;
	} else {
		$entityScope = getEntity('dolizsynch_zspunchclock');
		if (!empty($entityScope)) {
			$sql .= " AND entity IN (" . $entityScope . ")";
		}
	}

	$sql .= " AND (";
	// Overlap if: new_start < existing_end AND new_end > existing_start
	// But allow touching (end time = start time)
	$sql .= "   ('" . $db->escape($in_str) . "' < datahora_out AND '" . $db->escape($out_str) . "' > datahora_in)";
	$sql .= " )";


	$resql = $db->query($sql);
	if ($resql) {
		if ($db->num_rows($resql) > 0) {
			$obj = $db->fetch_object($resql);
			$overlap_in = date('H:i', strtotime($obj->datahora_in));
			$overlap_out = date('H:i', strtotime($obj->datahora_out));
			return array(
				'overlap' => true,
				'message' => 'Conflito com turno existente: ' . $overlap_in . ' - ' . $overlap_out
			);
		}
	}

	return array('overlap' => false, 'message' => '');
}

global $conf, $langs, $db, $user;

// Load translations
$langs->loadLangs(['users', 'other', 'projects']);

// Get parameters
$action = GETPOST('action', 'aZ09');
$employee_id = GETPOST('employee_id', 'int');
$edit_mode = GETPOST('edit_mode', 'int') ? 1 : 0;

// Pagination
$limit = GETPOST('limit', 'int') ? GETPOST('limit', 'int') : $conf->liste_limit;
$sortfield = GETPOST('sortfield', 'aZ09comma');
$sortorder = GETPOST('sortorder', 'aZ09comma');

// Set default sort if not specified
if (empty($sortfield)) {
	$sortfield = 't.dataval';
}
if (empty($sortorder)) {
	$sortorder = 'ASC';
}
$page = GETPOSTISSET('pageplusone') ? (GETPOST('pageplusone', 'int') - 1) : GETPOST("page", 'int');

// Debug logging

// Only reset to page 0 when user clicks search/remove filter buttons
if (GETPOST('button_search', 'alpha') || GETPOST('button_removefilter', 'alpha')) {
	$page = 0;
}
// Ensure page is always numeric and never negative
if (!is_numeric($page) || $page < 0) {
	$page = 0;
}
$offset = $limit * $page;
$pageprev = $page - 1;
$pagenext = $page + 1;

// Get dates from date picker
$date_start_day = GETPOST('date_startday', 'int');
$date_start_month = GETPOST('date_startmonth', 'int');
$date_start_year = GETPOST('date_startyear', 'int');
$date_end_day = GETPOST('date_endday', 'int');
$date_end_month = GETPOST('date_endmonth', 'int');
$date_end_year = GETPOST('date_endyear', 'int');

// Security check
if (!$user->rights->dolizsynch->zspunchclock->read) {
	accessforbidden();
}

// Initialize objects
$form = new Form($db);
$formOther = new FormOther($db);
$object = new ZSPunchClock($db);

// Set default dates if not provided (last 30 days)
if (empty($date_start_year)) {
	$date_start = date('Y-m-d', strtotime('-30 days'));
} else {
	$date_start = sprintf('%04d-%02d-%02d', $date_start_year, $date_start_month, $date_start_day);
}

if (empty($date_end_year)) {
	$date_end = date('Y-m-d');
} else {
	$date_end = sprintf('%04d-%02d-%02d', $date_end_year, $date_end_month, $date_end_day);
}

// Handle AJAX save request for inline editing - MUST be before any HTML output
if ($action == 'save_inline' && GETPOST('ajax', 'alpha')) {
	// Clean any output buffers
	while (ob_get_level()) {
		ob_end_clean();
	}

	header('Content-Type: application/json');
	header('Cache-Control: no-cache, must-revalidate');

	// Catch any PHP errors and return as JSON
	try {
		$id = GETPOST('id', 'int');
		$field = GETPOST('field', 'alpha');
		$value = GETPOST('value', 'alpha');

		// Log the incoming request

		if ($id && $field && $user->rights->dolizsynch->zspunchclock->write) {
			$objecttosave = new ZSPunchClock($db);
			$result = $objecttosave->fetch($id);


			if ($result > 0) {
				// Log the current state of the object
				// Validate field - allow editing of datahora_in, datahora_out, dataval, duration
				if (in_array($field, array('datahora_in', 'datahora_out', 'dataval', 'duration'))) {
					// For dataval field, validate date format (YYYY-MM-DD)
					if ($field == 'dataval') {
						if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
							echo json_encode(array('success' => false, 'error' => 'Invalid date format. Use YYYY-MM-DD'));
							exit;
						}
						// Validate it's a real date
						$date_parts = explode('-', $value);
						if (!checkdate((int)$date_parts[1], (int)$date_parts[2], (int)$date_parts[0])) {
							echo json_encode(array('success' => false, 'error' => 'Invalid date'));
							exit;
						}
						// When dataval changes, we need to recalculate datahora_in and datahora_out timestamps
						// to use the new date while keeping the same time
						if (!empty($objecttosave->datahora_in)) {
							$in_ts = is_numeric($objecttosave->datahora_in) ? $objecttosave->datahora_in : strtotime($objecttosave->datahora_in);
							$in_time = date('H:i:s', $in_ts);
							$new_in_datetime = $value . ' ' . $in_time;
							list($hours, $minutes, $seconds) = explode(':', $in_time);
							list($year, $month, $day) = explode('-', $value);
							$objecttosave->datahora_in = mktime((int)$hours, (int)$minutes, (int)$seconds, (int)$month, (int)$day, (int)$year);
						}
						if (!empty($objecttosave->datahora_out)) {
							$out_ts = is_numeric($objecttosave->datahora_out) ? $objecttosave->datahora_out : strtotime($objecttosave->datahora_out);
							$out_time = date('H:i:s', $out_ts);
							// Check if OUT was originally on the next day
							$in_ts = is_numeric($objecttosave->datahora_in) ? $objecttosave->datahora_in : strtotime($objecttosave->datahora_in);
							$in_date = date('Y-m-d', $in_ts);
							$out_date = date('Y-m-d', $out_ts);
							$days_diff = (strtotime($out_date) - strtotime($in_date)) / 86400;

							// Apply same day offset to new date
							$new_out_date = date('Y-m-d', strtotime($value . ' +' . $days_diff . ' days'));
							$new_out_datetime = $new_out_date . ' ' . $out_time;
							list($hours, $minutes, $seconds) = explode(':', $out_time);
							list($year, $month, $day) = explode('-', $new_out_date);
							$objecttosave->datahora_out = mktime((int)$hours, (int)$minutes, (int)$seconds, (int)$month, (int)$day, (int)$year);
						}
					}
					// For datetime fields, convert to Unix timestamp
					elseif (in_array($field, array('datahora_in', 'datahora_out'))) {
						try {
							// For datahora_in, expect time-only format (HH:MM)
							if ($field == 'datahora_in') {
								// Validate time format (HH:MM)
								if (!preg_match('/^([01]?[0-9]|2[0-3]):[0-5][0-9]$/', $value)) {
									echo json_encode(array('success' => false, 'error' => 'Invalid time format. Use HH:MM'));
									exit;
								}

								// Get reference date from dataval more robustly
								if (is_numeric($objecttosave->dataval)) {
									// If dataval is a timestamp, convert to Y-m-d using server timezone
									$date_part = date('Y-m-d', $objecttosave->dataval);
								} else {
									// If dataval is already a date string, parse it
									$ts = strtotime($objecttosave->dataval);
									$date_part = date('Y-m-d', $ts);
								}
								$time_part = $value; // HH:MM
							} else {
								// For datahora_out, expect time-only format (HH:MM) like datahora_in
								// Validate time format (HH:MM)
								if (!preg_match('/^([01]?[0-9]|2[0-3]):[0-5][0-9]$/', $value)) {
									echo json_encode(array('success' => false, 'error' => 'Invalid time format. Use HH:MM'));
									exit;
								}

								// For OUT time, determine the date based on IN time and whether it crosses midnight
								// If IN time is NULL, use dataval as the base date
								if (!empty($objecttosave->datahora_in)) {
									if (is_numeric($objecttosave->datahora_in)) {
										$in_date = date('Y-m-d', $objecttosave->datahora_in);
										$in_time = date('H:i', $objecttosave->datahora_in);
									} else {
										$in_ts = strtotime($objecttosave->datahora_in);
										$in_date = date('Y-m-d', $in_ts);
										$in_time = date('H:i', $in_ts);
									}

									// Parse time parts for comparison
									$in_time_parts = explode(':', $in_time);
									$out_time_parts = explode(':', $value);

									$in_hour = (int)$in_time_parts[0];
									$in_minute = (int)$in_time_parts[1];
									$out_hour = (int)$out_time_parts[0];
									$out_minute = (int)$out_time_parts[1];

									// Calculate total minutes for comparison
									$in_total_minutes = $in_hour * 60 + $in_minute;
									$out_total_minutes = $out_hour * 60 + $out_minute;

									// If OUT time is before IN time, assume it's the next day
									if ($out_total_minutes < $in_total_minutes) {
										$in_ts_temp = strtotime($in_date);
										$out_date = date('Y-m-d', $in_ts_temp + 86400); // Add one day
									} else {
										$out_date = $in_date;
									}
								} else {
									// IN time is NULL, use dataval as the base date
									if (is_numeric($objecttosave->dataval)) {
										$out_date = date('Y-m-d', $objecttosave->dataval);
									} else {
										$ts = strtotime($objecttosave->dataval);
										$out_date = date('Y-m-d', $ts);
									}
								}

								$date_part = $out_date;
								$time_part = $value; // HH:MM
							}

							// Create full datetime string for conversion
							$new_datetime = $date_part . ' ' . $time_part . ':00';

							// Parse time components
							list($hours, $minutes) = explode(':', $time_part);
							$date_parts = explode('-', $date_part);
							$year = (int)$date_parts[0];
							$month = (int)$date_parts[1];
							$day = (int)$date_parts[2];

							// Use mktime() which uses server timezone (Europe/Lisbon)
							// This matches the Zone Soft data which is also in Lisbon timezone
							$timestamp = mktime((int)$hours, (int)$minutes, 0, $month, $day, $year);

							if ($timestamp === false || $timestamp <= 0) {
								echo json_encode(array('success' => false, 'error' => 'Invalid datetime conversion'));
								exit;
							}

							$value = $timestamp;


							// Additional validation: Check 18-hour limit between IN and OUT times
							// Handle both Unix timestamps and datetime strings properly
							if (is_numeric($objecttosave->datahora_in)) {
								$in_timestamp = (int)$objecttosave->datahora_in;
							} else {
								$in_timestamp = strtotime($objecttosave->datahora_in);
								if ($in_timestamp === false) $in_timestamp = 0;
							}

							if (is_numeric($objecttosave->datahora_out)) {
								$out_timestamp = (int)$objecttosave->datahora_out;
							} else {
								$out_timestamp = strtotime($objecttosave->datahora_out);
								if ($out_timestamp === false) $out_timestamp = 0;
							}

							// Override with the new timestamp for the field being changed
							if ($field == 'datahora_in') {
								$in_timestamp = $timestamp;
							} elseif ($field == 'datahora_out') {
								$out_timestamp = $timestamp;
							}

							// Check if we have both valid timestamps and validate the 18-hour limit
							if ($in_timestamp && $out_timestamp && $in_timestamp > 0 && $out_timestamp > 0) {
								$time_diff_seconds = $out_timestamp - $in_timestamp;
								$time_diff_hours = $time_diff_seconds / 3600;

								// Allow for negative differences (OUT before IN, crossing midnight) but still limit to 18 hours total
								if ($time_diff_hours < 0) {
									$time_diff_hours += 24; // Add 24 hours for next day calculation
									$time_diff_seconds += 86400; // Recalculate seconds for next day
								}

								if ($time_diff_hours > 18) {
									echo json_encode(array(
										'success' => false,
										'error' => 'OUT Time cannot be more than 18 hours after IN Time. Current difference: ' . number_format($time_diff_hours, 1) . ' hours.'
									));
									exit;
								}

								// Recalculate and update duration when either IN or OUT time changes
								$objecttosave->duration = $time_diff_seconds;
							}

							// Note: dataval (reference date) remains unchanged for both IN and OUT times
						} catch (Exception $e) {
							echo json_encode(array('success' => false, 'error' => 'Datetime processing error: ' . $e->getMessage()));
							exit;
						}
					}
					// For duration field, recalculate punch out time from punch in + duration
					elseif ($field == 'duration') {
						// Parse duration in HH:MM format
						$duration_parts = explode(':', $value);
						if (count($duration_parts) == 2 && is_numeric($duration_parts[0]) && is_numeric($duration_parts[1])) {
							$hours = intval($duration_parts[0]);
							$minutes = intval($duration_parts[1]);

							// Convert to seconds
							$duration_seconds = ($hours * 3600) + ($minutes * 60);

							// Calculate new punch out time from punch in + duration
							if (!empty($objecttosave->datahora_in)) {
								// Check if datahora_in is already a Unix timestamp (numeric value)
								if (is_numeric($objecttosave->datahora_in)) {
									$punch_in_timestamp = (int)$objecttosave->datahora_in;
								} else {
									$punch_in_timestamp = strtotime($objecttosave->datahora_in);
								}

								// Final check if we got a valid timestamp
								if ($punch_in_timestamp === false || $punch_in_timestamp <= 0) {
									echo json_encode(array('success' => false, 'error' => "Could not parse punch in time: '{$objecttosave->datahora_in}'"));
									exit;
								}

								$punch_out_timestamp = $punch_in_timestamp + $duration_seconds;

								// Validate 18-hour limit for duration changes
								$time_diff_hours = $duration_seconds / 3600;
								if ($time_diff_hours > 18) {
									echo json_encode(array('success' => false, 'error' => 'Duration cannot exceed 18 hours. Requested duration: ' . number_format($time_diff_hours, 1) . ' hours.'));
									exit;
								}

								// Update punch out time
								$objecttosave->datahora_out = $punch_out_timestamp;

								// Store the duration in seconds in the database
								$objecttosave->duration = $duration_seconds;

								// Don't set $field = $value since we're updating multiple fields
								$value = $duration_seconds; // For consistency
							} else {
								echo json_encode(array('success' => false, 'error' => 'Punch in time is required to calculate punch out'));
								exit;
							}
						} else {
							echo json_encode(array('success' => false, 'error' => 'Invalid duration format. Use HH:MM'));
							exit;
						}
					}

					if ($field !== 'duration' && $field !== 'dataval') {
						$objecttosave->$field = $value;
					} elseif ($field == 'dataval') {
						// For dataval, set it directly as a date string
						$objecttosave->dataval = $value;
					}

					// Check for shift overlap before saving
					// Re-fetch the object to make sure we have the latest data from database
					$tempObj = new ZSPunchClock($db);
					$tempObj->fetch($objecttosave->id);

					// Use the field being updated if applicable, otherwise use current DB value
					if ($field == 'duration') {
						// If duration field is being updated, we need to calculate the new OUT time
						$duration_seconds = $value; // Duration is already in seconds
						$in_ts = is_numeric($tempObj->datahora_in) ? $tempObj->datahora_in : strtotime($tempObj->datahora_in);
						$check_in = $tempObj->datahora_in;
						$check_out = $in_ts + $duration_seconds; // New OUT = IN + duration
						$check_empid = $tempObj->empid_zs;
					} else {
						$check_in = ($field == 'datahora_in') ? $value : $tempObj->datahora_in;
						$check_out = ($field == 'datahora_out') ? $value : $tempObj->datahora_out;
						$check_empid = $tempObj->empid_zs;
					}

					$entityFilter = isset($tempObj->entity) ? (int)$tempObj->entity : 0;

					if (!empty($check_empid) && !empty($check_in) && !empty($check_out)) {
						// Convert to timestamps for overlap check
						$in_ts = is_numeric($check_in) ? $check_in : strtotime($check_in);
						$out_ts = is_numeric($check_out) ? $check_out : strtotime($check_out);


						$overlapCheck = checkShiftOverlap($db, $check_empid, $in_ts, $out_ts, $objecttosave->id, $entityFilter > 0 ? $entityFilter : null);
						if ($overlapCheck['overlap']) {
							dol_syslog("save_inline: OVERLAP DETECTED: " . $overlapCheck['message'], LOG_WARNING);
							echo json_encode(array(
								'success' => false,
								'error' => $overlapCheck['message']
							));
							exit;
						}
					}

					// Mark as edited/manual entry (not imported from ZS BMS)
					$objecttosave->real_entry = 0;

					// Record who modified it
					$objecttosave->fk_user_modif = $user->id;

					$result = $objecttosave->update($user);

					// After updating the punch clock record, handle TimeSpent entry
					if ($result > 0 && !empty($objecttosave->fk_task)) {
						// If TimeSpent already exists, update it
						if (!empty($objecttosave->fk_timespent)) {
							$timeSpent = new TimeSpent($db);
							$fetchResult = $timeSpent->fetch($objecttosave->fk_timespent);

							if ($fetchResult > 0) {

								// Update time spent fields based on the edited punch clock data
								$timeSpent->fk_element = $objecttosave->fk_task;
								$timeSpent->elementtype = 'task';
								$timeSpent->fk_user = $objecttosave->fk_user;
								$timeSpent->element_duration = $objecttosave->duration;

								// Convert datahora_in to proper format for element_datehour
								// Pass Unix timestamps directly - updateCommon will handle conversion
								if (is_numeric($objecttosave->datahora_in)) {
									$timeSpent->element_datehour = $objecttosave->datahora_in;
									$timeSpent->element_date = $objecttosave->datahora_in;
								} else {
									$ts = strtotime($objecttosave->datahora_in);
									$timeSpent->element_datehour = $ts;
									$timeSpent->element_date = $ts;
								}

								$timeSpent->element_date_withhour = 1;

								// Update note with out time
								if (is_numeric($objecttosave->datahora_out)) {
									$timeSpent->note = 'Saida ' . date('Y-m-d H:i:s', $objecttosave->datahora_out);
								} else {
									$timeSpent->note = 'Saida ' . $objecttosave->datahora_out;
								}


								$updateResult = $timeSpent->update($user);

								if ($updateResult < 0) {
									dol_syslog("Failed to update time spent entry: " . $timeSpent->error, LOG_ERR);
								} else {
								}
							} else {
								dol_syslog("Failed to fetch TimeSpent rowid=" . $objecttosave->fk_timespent . ": " . $timeSpent->error, LOG_WARNING);
							}
						}
					}

					if ($result > 0) {
						// Return formatted value for display
						if ($field == 'datahora_in' || $field == 'datahora_out') {
							// $value is now a timestamp, format it for time input (HH:MM)
							$formatted_value = date('H:i', $value);
						} elseif ($field == 'duration') {
							// Return in HH:MM format for time input
							$hours = floor($duration_seconds / 3600);
							$minutes = floor(($duration_seconds % 3600) / 60);
							$formatted_value = sprintf('%02d:%02d', $hours, $minutes);
						} elseif ($field == 'dataval') {
							// Return date in YYYY-MM-DD format for date input
							$formatted_value = $value;
							// Also return formatted display date (dd/mm/yyyy)
							$display_date = date('d/m/Y', strtotime($value));
						} else {
							$formatted_value = htmlspecialchars($value);
						}

						$response = array(
							'success' => true,
							'formatted_value' => $formatted_value
						);

						// If we updated dataval, also return formatted display date
						if ($field == 'dataval') {
							$response['display_date'] = $display_date;
						}

						// If we updated datahora_in or datahora_out, also return the other time field and recalculated duration
						if ($field == 'datahora_in' || $field == 'datahora_out') {
							// Return both time fields
							$response['datahora_in'] = date('H:i', is_numeric($objecttosave->datahora_in) ? $objecttosave->datahora_in : strtotime($objecttosave->datahora_in));
							$response['datahora_out'] = date('H:i', is_numeric($objecttosave->datahora_out) ? $objecttosave->datahora_out : strtotime($objecttosave->datahora_out));

							// Recalculate duration
							$datahora_in_str = is_numeric($objecttosave->datahora_in) ?
								date('Y-m-d H:i:s', $objecttosave->datahora_in) : $objecttosave->datahora_in;
							$datahora_out_str = is_numeric($objecttosave->datahora_out) ?
								date('Y-m-d H:i:s', $objecttosave->datahora_out) : $objecttosave->datahora_out;

							$duration_result = calculateDuration($datahora_in_str, $datahora_out_str);
							if (!$duration_result['error']) {
								$response['duration'] = $duration_result['display'];

								// Update duration in database
								$objecttosave->duration = $duration_result['seconds'];
								$objecttosave->update($user); // Save the updated duration
							}
						} elseif ($field == 'duration') {
							// Return the updated OUT time
							$response['datahora_out'] = date('H:i', is_numeric($objecttosave->datahora_out) ? $objecttosave->datahora_out : strtotime($objecttosave->datahora_out));
						}

						echo json_encode($response);
					} else {
						echo json_encode(array('success' => false, 'error' => $objecttosave->error));
					}
				} else {
					echo json_encode(array('success' => false, 'error' => 'Invalid field'));
				}
			} else {
				echo json_encode(array('success' => false, 'error' => 'Object not found'));
			}
		} else {
			echo json_encode(array('success' => false, 'error' => 'Missing parameters or no permission'));
		}
	} catch (Exception $e) {
		echo json_encode(array('success' => false, 'error' => 'Exception: ' . $e->getMessage(), 'trace' => $e->getTraceAsString()));
	} catch (Error $e) {
		echo json_encode(array('success' => false, 'error' => 'Error: ' . $e->getMessage(), 'trace' => $e->getTraceAsString()));
	}
	exit;
}

// Handle AJAX create new entry request
if ($action == 'create_inline' && GETPOST('ajax', 'alpha')) {
	header('Content-Type: application/json');
	header('Cache-Control: no-cache, must-revalidate');

	if ($user->rights->dolizsynch->zspunchclock->write) {
		$empid_zs = GETPOST('empid_zs', 'int');
		$dataval = GETPOST('dataval', 'alpha'); // YYYY-MM-DD format
		$clicked_rowid = GETPOST('clicked_rowid', 'int');

		if ($empid_zs && $dataval) {
			// Find the maximum position for this employee on this date to insert below
			$max_position = 0;
			if ($clicked_rowid > 0) {
				// Get the position of the clicked row
				$sql_pos = "SELECT position FROM " . MAIN_DB_PREFIX . "dolizsynch_zspunchclock";
				$sql_pos .= " WHERE rowid = " . (int)$clicked_rowid;
				$res_pos = $db->query($sql_pos);
				if ($res_pos && $db->num_rows($res_pos) > 0) {
					$obj_pos = $db->fetch_object($res_pos);
					$max_position = (int)$obj_pos->position + 1;
				}
				$db->free($res_pos);
			}

			// If we didn't get a position from clicked row, find max position for this date
			if ($max_position == 0) {
				$sql_max = "SELECT MAX(position) as max_pos FROM " . MAIN_DB_PREFIX . "dolizsynch_zspunchclock";
				$sql_max .= " WHERE empid_zs = " . (int)$empid_zs;
				$sql_max .= " AND DATE(dataval) = '" . $db->escape($dataval) . "'";
				$res_max = $db->query($sql_max);
				if ($res_max && $db->num_rows($res_max) > 0) {
					$obj_max = $db->fetch_object($res_max);
					$max_position = ((int)$obj_max->max_pos) + 1;
				}
				$db->free($res_max);
			}

			$newobject = new ZSPunchClock($db);
			$newobject->empid_zs = $empid_zs;
			$newobject->dataval = $dataval;
			$newobject->entity = $conf->entity;
			$newobject->synch = 0;
			$newobject->loja_zs = !empty($conf->global->ZS_API_STORE) ? $conf->global->ZS_API_STORE : 0;
			$newobject->position = $max_position; // Set position to appear below clicked row
			$newobject->real_entry = 0; // Manual entry, not imported from ZS BMS

			// Create with empty IN/OUT times - user will fill them inline
			$newobject->datahora_in = null;
			$newobject->datahora_out = null;
			$newobject->duration = 0;

			// Record who created it
			$newobject->fk_user_modif = $user->id;

			$result = $newobject->create($user);

			if ($result > 0) {
				echo json_encode(array(
					'success' => true,
					'id' => $newobject->id,
					'message' => 'Novo registo criado com sucesso'
				));
			} else {
				$error_msg = $newobject->error;
				if (empty($error_msg) && isset($newobject->errors) && count($newobject->errors) > 0) {
					$error_msg = implode(', ', $newobject->errors);
				}
				if (empty($error_msg)) {
					$error_msg = 'Falha na criação do registo (código: ' . $result . ')';
				}
				echo json_encode(array('success' => false, 'error' => $error_msg));
			}
		} else {
			echo json_encode(array('success' => false, 'error' => 'Missing employee or date'));
		}
	} else {
		echo json_encode(array('success' => false, 'error' => 'No permission to create records'));
	}
	exit;
}

// Handle AJAX delete request
if ($action == 'delete_entire_row' && GETPOST('ajax', 'alpha')) {
	header('Content-Type: application/json');
	header('Cache-Control: no-cache, must-revalidate');

	if ($user->rights->dolizsynch->zspunchclock->write) {
		$rowid = GETPOST('rowid', 'int');

		if ($rowid) {
			$object->fetch($rowid);
			// Mark as deleted instead of deleting
			$object->synch = 3;
			$result = $object->update($user);

			if ($result > 0) {
				echo json_encode(array('success' => true));
			} else {
				echo json_encode(array('success' => false, 'error' => $object->error));
			}
		} else {
			echo json_encode(array('success' => false, 'error' => 'Missing row ID'));
		}
	} else {
		echo json_encode(array('success' => false, 'error' => 'No permission to delete records'));
	}
	exit;
}

/*
 * View
 */

$title = "Picagens de Ponto por Funcionário";
llxHeader('', $title);

// Add CSRF token for AJAX requests
print '<script type="text/javascript">var CSRF_TOKEN = "' . newToken() . '";</script>';

print '<form method="GET" action="' . $_SERVER["PHP_SELF"] . '">' . "\n";
print '<input type="hidden" name="token" value="' . newToken() . '">';
print '<input type="hidden" name="edit_mode" value="' . $edit_mode . '">';

print load_fiche_titre($title, '', 'clock');

// Filter form
print '<div class="fichecenter">';
print '<table class="border centpercent" style="max-width: 600px; margin: 0 auto;">';

// Employee selector
print '<tr>';
print '<td class="fieldrequired" style="width: 30%;">Funcionário</td>';
print '<td>';
print $form->select_dolusers($employee_id, 'employee_id', 1, null, 0, '', '', $conf->entity, 0, 0, '', 0, '', 'minwidth300');
print '</td>';
print '</tr>';

// Date start
print '<tr>';
print '<td class="fieldrequired">Data Início</td>';
print '<td>';
print $form->selectDate($date_start, 'date_start', 0, 0, 0, '', 1, 0);
print '</td>';
print '</tr>';

// Date end
print '<tr>';
print '<td class="fieldrequired">Data Fim</td>';
print '<td>';
print $form->selectDate($date_end, 'date_end', 0, 0, 0, '', 1, 0);
print '</td>';
print '</tr>';

print '</table>';

// Search button outside the table, centered
print '<div style="text-align: center; margin-top: 15px; margin-bottom: 20px;">';
print '<input type="submit" class="button butAction" name="button_search" value="Pesquisar">';
print '</div>';

print '</div>';
print '<div style="clear:both"></div>';

// Initialize variables
$punchRecords = array();
$employeeTotals = array();
$empid_zs = 0;

// Query punch clock records if employee is selected
if ($employee_id > 0) {
	// Get employee's ZS ID
	$sql_empid = "SELECT ue.zs_employee_id FROM " . MAIN_DB_PREFIX . "user_extrafields ue WHERE ue.fk_object = " . (int)$employee_id;
	$res_empid = $db->query($sql_empid);
	if ($res_empid && $db->num_rows($res_empid) > 0) {
		$obj_empid = $db->fetch_object($res_empid);
		$empid_zs = $obj_empid->zs_employee_id;

		// Query punch clock records for this employee in the date range
		$sql = 'SELECT t.rowid, t.entity, ';
		$sql .= 't.empid_zs, t.dataval, t.datahora_in, t.datahora_out, t.duration, t.tms, t.position, t.real_entry, t.fk_user_modif, ';
		$sql .= 'u.firstname, u.lastname, CONCAT(u.firstname, " ", u.lastname) as fullname, ';
		$sql .= 'um.login as modif_login, CONCAT(um.firstname, " ", um.lastname) as modif_fullname ';
		$sql .= 'FROM ' . MAIN_DB_PREFIX . 'dolizsynch_zspunchclock as t ';
		$sql .= 'LEFT JOIN ' . MAIN_DB_PREFIX . 'user_extrafields as ue ON t.empid_zs = ue.zs_employee_id ';
		$sql .= 'LEFT JOIN ' . MAIN_DB_PREFIX . 'user as u ON ue.fk_object = u.rowid ';
		$sql .= 'LEFT JOIN ' . MAIN_DB_PREFIX . 'user as um ON t.fk_user_modif = um.rowid ';
		$sql .= 'WHERE t.entity IN (' . getEntity('dolizsynch_zspunchclock') . ')';
		$sql .= ' AND t.empid_zs = ' . (int)$empid_zs;
		$sql .= ' AND DATE(t.dataval) >= \'' . $db->escape($date_start) . '\'';
		$sql .= ' AND DATE(t.dataval) <= \'' . $db->escape($date_end) . '\'';
		$sql .= ' AND (t.synch IS NULL OR t.synch != 3)'; // Exclude deleted records (synch = 3)
		$sql .= ' ORDER BY ' . $sortfield . ' ' . $sortorder;
		// Add secondary sort by datahora_in - ALWAYS ascending (time within each date always goes from earliest to latest)
		if ($sortfield == 't.dataval') {
			$sql .= ', t.datahora_in ASC';
		}
		$sql .= ', t.rowid ASC';
		$sql .= $db->plimit($limit + 1, $offset);

		$resql = $db->query($sql);

		if (!$resql) {
			dol_print_error($db);
			exit;
		}

		$num = $db->num_rows($resql);
		$nbtotalofrecords = '';

		// Count total records for pagination (without limit)
		if (empty($conf->global->MAIN_DISABLE_FULL_SCANLIST)) {
			$sql_count = 'SELECT COUNT(t.rowid) as total ';
			$sql_count .= 'FROM ' . MAIN_DB_PREFIX . 'dolizsynch_zspunchclock as t ';
			$sql_count .= 'WHERE t.entity IN (' . getEntity('dolizsynch_zspunchclock') . ')';
			$sql_count .= ' AND t.empid_zs = ' . (int)$empid_zs;
			$sql_count .= ' AND DATE(t.dataval) >= \'' . $db->escape($date_start) . '\'';
			$sql_count .= ' AND DATE(t.dataval) <= \'' . $db->escape($date_end) . '\'';
			$sql_count .= ' AND (t.synch IS NULL OR t.synch != 3)';

			$resql_count = $db->query($sql_count);
			if ($resql_count) {
				$obj_count = $db->fetch_object($resql_count);
				$nbtotalofrecords = $obj_count->total;
			}
		}

		// Process records into array
		$punchRecords = array();
		$employeeTotals = array();
		$i = 0;
		while ($i < $num) {
			$obj = $db->fetch_object($resql);

			// Calculate duration display
			$duration_display = '';
			if (!empty($obj->duration)) {
				$hours = floor($obj->duration / 3600);
				$minutes = floor(($obj->duration % 3600) / 60);
				$duration_display = sprintf('%02d:%02d', $hours, $minutes);
			}

			// Add to employee totals
			if (!isset($employeeTotals[$obj->empid_zs])) {
				$employeeTotals[$obj->empid_zs] = 0;
			}
			if (!empty($obj->duration)) {
				$employeeTotals[$obj->empid_zs] += $obj->duration;
			}

			$punchRecords[] = array(
				'rowid' => $obj->rowid,
				'empid_zs' => $obj->empid_zs,
				'fullname' => $obj->fullname,
				'dataval' => $obj->dataval,
				'date_display' => date('d/m/Y', strtotime($obj->dataval)),
				'datahora_in' => $obj->datahora_in,
				'datahora_out' => $obj->datahora_out,
				'duration' => $obj->duration,
				'duration_display' => $duration_display,
				'real_entry' => $obj->real_entry,
				'modif_login' => $obj->modif_login,
				'modif_fullname' => $obj->modif_fullname,
				'has_error' => false
			);

			$i++;
		}

		// Add CSS styles (same as zspunchclock_list.php)
		print '<style>
.error-cell {
    background-color: red;
    color: red;
}

/* Inline edit styles */
.inline-edit-field {
    width: 100%;
    border: none;
    background: transparent;
    padding: 2px;
}

.inline-edit-field:focus {
    background: #f0f8ff;
    border: 1px solid #4CAF50;
    outline: none;
}

/* New row highlight animation */
@keyframes highlightNewRow {
    0% {
        background-color: #d4edda;
        box-shadow: 0 0 10px rgba(40, 167, 69, 0.5);
    }
    50% {
        background-color: #c3e6cb;
        box-shadow: 0 0 15px rgba(40, 167, 69, 0.7);
    }
    100% {
        background-color: transparent;
        box-shadow: none;
    }
}

.new-row-highlight {
    animation: highlightNewRow 2.5s ease-in-out;
}

.new-row-highlight td {
    border-top: 2px solid #28a745 !important;
    border-bottom: 2px solid #28a745 !important;
}

.new-row-highlight td:first-child {
    border-left: 2px solid #28a745 !important;
}

.new-row-highlight td:last-child {
    border-right: 2px solid #28a745 !important;
}

.time-field {
    text-align: center;
    width: 100px;
    max-width: 100px;
    margin: 1px 0;
    padding: 2px;
}

.date-field {
    text-align: center;
    width: 140px;
    max-width: 140px;
    margin: 1px 0;
    padding: 2px;
}

.duration-field {
    text-align: center;
    font-variant-numeric: tabular-nums;
    width: 100px;
    max-width: 100px;
    margin: 1px 0;
    padding: 2px;
}

.duration-cell {
    /* text-align set inline to right */
}

.employee-total-cell {
    text-align: center;
    font-variant-numeric: tabular-nums;
}

.daily-total-cell {
    text-align: right;
    font-variant-numeric: tabular-nums;
}

.duration-cell .duration-field {
    display: inline-block;
    width: 100px;
    max-width: 100px;
    font-variant-numeric: tabular-nums;
}

.duration-cell .duration-display {
    display: inline-block;
    width: 80px;
    padding: 4px 0;
    text-align: right;
    font-variant-numeric: tabular-nums;
}

.duration-total-display {
    display: inline-flex;
    align-items: center;
    justify-content: flex-end;
    min-width: 80px;
    padding: 4px 0;
    font-variant-numeric: tabular-nums;
}

.duration-total-display.no-icon {
    justify-content: flex-end;
}

.duration-icon {
    font-size: 13px;
    color: #6c757d;
    margin-left: 6px;
}

.duration-total-text {
    min-width: 48px;
    text-align: center;
    display: inline-block;
}

.overall-total-duration {
    text-align: center;
    font-variant-numeric: tabular-nums;
}

/* Webkit browsers (Chrome, Safari, Edge) - adjust clock icon position */
.time-field::-webkit-calendar-picker-indicator {
    margin-left: -8px;
    padding: 0;
}

.numeric-field {
    text-align: right;
}

.inline-edit-field.editing {
    background: #fff3cd !important;
    border: 1px solid #ffc107 !important;
}

.inline-edit-field.saving {
    background: #d1ecf1 !important;
    border: 1px solid #bee5eb !important;
}

.inline-edit-field.error {
    background: #f8d7da !important;
    border: 1px solid #f5c6cb !important;
}

.inline-edit-field.success {
    background: #d4edda !important;
    border: 1px solid #c3e6cb !important;
}

/* Table layout */
.div-table-responsive {
    overflow-x: auto;
    margin: 0 -10px;
}

.tagtable.liste {
    width: 100%;
    table-layout: auto;
}

.tagtable.liste th,
.tagtable.liste td {
    white-space: nowrap;
    padding: 4px 8px;
    vertical-align: middle;
}

/* Right-aligned columns - ensure content inside also aligns */
.tagtable.liste th[style*="text-align: right"],
.tagtable.liste td[style*="text-align: right"] {
    text-align: right !important;
}

/* Make sure spans inside right-aligned cells also align right */
.tagtable.liste td[style*="text-align: right"] span {
    display: inline-block;
}

/* Sortable column header links */
.tagtable.liste th a {
    color: inherit;
    text-decoration: none;
    display: inline-block;
}

.tagtable.liste th a:hover {
    text-decoration: underline;
}
</style>';

		// Build pagination URL parameters
		$param = '';
		$param .= '&employee_id=' . urlencode($employee_id);
		$param .= '&date_startday=' . urlencode($date_start_day);
		$param .= '&date_startmonth=' . urlencode($date_start_month);
		$param .= '&date_startyear=' . urlencode($date_start_year);
		$param .= '&date_endday=' . urlencode($date_end_day);
		$param .= '&date_endmonth=' . urlencode($date_end_month);
		$param .= '&date_endyear=' . urlencode($date_end_year);
		$param .= '&edit_mode=' . $edit_mode;
		$param .= '&sortfield=' . urlencode($sortfield);
		$param .= '&sortorder=' . urlencode($sortorder);

		// Print navigation bar (pagination)
		print_barre_liste('', $page, $_SERVER["PHP_SELF"], $param, '', '', '', $num, $nbtotalofrecords, 'clock', 0, '', '', $limit, 0, 0, 1);

		// Display table
		print '<div class="div-table-responsive">';
		print '<table class="tagtable liste">';

		// Table header
		print '<tr class="liste_titre">';
		print '<th>Funcionário</th>';
		print '<th style="text-align: right; width: 100px;">ID ZS</th>';

		// Sortable Data column
		$sorturl = $_SERVER["PHP_SELF"] . '?employee_id=' . $employee_id;
		$sorturl .= '&date_startday=' . $date_start_day . '&date_startmonth=' . $date_start_month . '&date_startyear=' . $date_start_year;
		$sorturl .= '&date_endday=' . $date_end_day . '&date_endmonth=' . $date_end_month . '&date_endyear=' . $date_end_year;
		$sorturl .= '&edit_mode=' . $edit_mode;
		$newsortorder = ($sortfield == 't.dataval' && $sortorder == 'ASC') ? 'DESC' : 'ASC';
		$sortarrow = '';
		if ($sortfield == 't.dataval') {
			$sortarrow = ($sortorder == 'ASC') ? ' &#9650;' : ' &#9660;';
		}
		print '<th style="text-align: right; width: 100px;">';
		print '<a href="' . $sorturl . '&sortfield=t.dataval&sortorder=' . $newsortorder . '">Data' . $sortarrow . '</a>';
		print '</th>';

		print '<th style="text-align: right; width: 120px;">Entrada (IN)</th>';
		print '<th style="text-align: right; width: 120px;">Saída (OUT)</th>';
		print '<th style="text-align: right; width: 120px;">Duração</th>';
		print '<th style="text-align: center; width: 100px;">Total Dia</th>';
		print '<th style="text-align: center; width: 80px;">Editor</th>';
		print '<th style="text-align: right; width: 80px;">Ações</th>';
		print '</tr>' . "\n";

		// Table rows
		$currentEmployee = null;
		$employeeRowIndex = 0;
		$totalRecordsForEmployee = 0;

		// Calculate daily totals and find the row with greatest datahora_in for each day
		$dailyTotals = array(); // Key: date (Y-m-d), Value: total duration in seconds
		$dailyMaxDatehoraInRow = array(); // Key: date (Y-m-d), Value: array with rowid and datahora_in

		foreach ($punchRecords as $record) {
			$date_key = date('Y-m-d', strtotime($record['dataval']));

			// Calculate daily totals
			if (!isset($dailyTotals[$date_key])) {
				$dailyTotals[$date_key] = 0;
			}
			if (!empty($record['duration'])) {
				$dailyTotals[$date_key] += $record['duration'];
			}

			// Track row with greatest (latest) non-NULL datahora_in for this date
			if (!empty($record['datahora_in']) && $record['datahora_in'] != '0000-00-00 00:00:00') {
				// Update if this is the first record or has a later datahora_in
				if (!isset($dailyMaxDatehoraInRow[$date_key])) {
					$dailyMaxDatehoraInRow[$date_key] = array('rowid' => $record['rowid'], 'datahora_in' => $record['datahora_in']);
				} else {
					if (strtotime($record['datahora_in']) >= strtotime($dailyMaxDatehoraInRow[$date_key]['datahora_in'])) {
						$dailyMaxDatehoraInRow[$date_key] = array('rowid' => $record['rowid'], 'datahora_in' => $record['datahora_in']);
					}
				}
			}
		}

		// Check if we need to highlight a new row
		$newRowId = GETPOST('newrow', 'int');

		if (!empty($punchRecords)) {
			foreach ($punchRecords as $idx => $record) {
				$rowClass = '';
				if ($record['has_error']) {
					$rowClass = ' class="error-cell"';
				} elseif ($newRowId && $record['rowid'] == $newRowId) {
					$rowClass = ' class="new-row-highlight"';
				}
				$rowEmpId = isset($record['empid_zs']) ? (int)$record['empid_zs'] : 0;
				$rowDurationSeconds = (isset($record['duration']) && $record['duration'] !== '') ? (int)$record['duration'] : 0;

				print '<tr data-rowid="' . $record['rowid'] . '" data-empid="' . $rowEmpId . '" data-duration-seconds="' . $rowDurationSeconds . '"' . $rowClass . '>';

				// Employee name (left-aligned)
				print '<td>' . htmlspecialchars($record['fullname']) . '</td>';

				// Employee ID (right-aligned)
				print '<td style="text-align: right;"><span class="empid-display-' . $record['rowid'] . '">' . $record['empid_zs'] . '</span></td>';

				// Date (right-aligned, editable in edit mode)
				print '<td style="text-align: right; width: 120px;">';
				if ($user->rights->dolizsynch->zspunchclock->write && $edit_mode) {
					// Edit mode: date input field
					$date_value_iso = date('Y-m-d', strtotime($record['dataval'])); // YYYY-MM-DD for input type="date"
					print '<input type="date" class="inline-edit-field date-field" data-field="dataval" data-id="' . $record['rowid'] . '" value="' . $date_value_iso . '" title="Editar data" style="text-align: center; width: 140px;">';
				} else {
					// View mode: display date in dd/mm/yyyy format
					print '<span class="date-display-' . $record['rowid'] . '">' . $record['date_display'] . '</span>';
				}
				print '</td>';

				// IN datetime as editable field (right-aligned)
				print '<td style="text-align: right; width: 120px;">';
				if ($user->rights->dolizsynch->zspunchclock->write && $edit_mode) {
					$time_value = isset($record['datahora_in']) ? date('H:i', strtotime($record['datahora_in'])) : '';
					print '<input type="time" class="inline-edit-field time-field" data-field="datahora_in" data-id="' . $record['rowid'] . '" value="' . $time_value . '" title="Hora de entrada">';
				} else {
					// View mode: show only time (HH:MM)
					if (isset($record['datahora_in']) && !empty($record['datahora_in'])) {
						print date('H:i', strtotime($record['datahora_in']));
					} else {
						print '';
					}
				}
				print '</td>';

				// OUT datetime as editable field (right-aligned)
				print '<td style="text-align: right; width: 120px;">';
				if ($user->rights->dolizsynch->zspunchclock->write && $edit_mode) {
					$time_value = isset($record['datahora_out']) ? date('H:i', strtotime($record['datahora_out'])) : '';
					print '<input type="time" class="inline-edit-field time-field" data-field="datahora_out" data-id="' . $record['rowid'] . '" value="' . $time_value . '" title="Hora de saída">';
				} else {
					// View mode: show time with +1 if next day
					if (isset($record['datahora_in']) && isset($record['datahora_out']) && !empty($record['datahora_out'])) {
						$in_timestamp = strtotime($record['datahora_in']);
						$out_timestamp = strtotime($record['datahora_out']);

						// Get date parts for comparison
						$in_date = date('Y-m-d', $in_timestamp);
						$out_date = date('Y-m-d', $out_timestamp);

						$out_time = date('H:i', $out_timestamp);

						// If OUT date is greater than IN date, show +N indicator
						if ($out_date > $in_date) {
							$date_in = new DateTime($in_date);
							$date_out = new DateTime($out_date);
							$day_diff = $date_in->diff($date_out)->days;
							print '<span style="color: #999;">+' . $day_diff . '</span> ' . $out_time;
						} else {
							print $out_time;
						}
					} else {
						print '';
					}
				}
				print '</td>';

				// Duration as editable field (right-aligned)
				print '<td class="duration-cell" style="text-align: right; width: 120px;">';
				if ($user->rights->dolizsynch->zspunchclock->write && $edit_mode) {
					$duration_value = '';
					if (!empty($record['duration_display']) && $record['duration_display'] !== 'Error') {
						$duration_value = $record['duration_display']; // Already in HH:MM format
					}
					print '<input type="time" class="inline-edit-field duration-field" data-field="duration" data-id="' . $record['rowid'] . '" data-duration-seconds="' . $rowDurationSeconds . '" value="' . $duration_value . '" title="Editar duração">';
				} else {
					$durationDisplay = !empty($record['duration_display']) ? $record['duration_display'] : '';
					print '<span class="duration-display" data-seconds="' . $rowDurationSeconds . '">' . $durationDisplay . '</span>';
				}
				print '</td>';

				// Total Day column - show daily total on last row only (row with greatest datahora_in)
				print '<td class="daily-total-cell" style="text-align: right;">';

				$date_key = date('Y-m-d', strtotime($record['dataval']));

				// Check if this is the row with the greatest datahora_in for this date
				$showTotal = false;
				if (isset($dailyMaxDatehoraInRow[$date_key])) {
					// This row should show the total if its rowid matches the stored rowid for this date
					$showTotal = ($record['rowid'] == $dailyMaxDatehoraInRow[$date_key]['rowid']);
				}

				// Display total if this is the designated row
				if ($showTotal && isset($dailyTotals[$date_key])) {
					$totalHours = floor($dailyTotals[$date_key] / 3600);
					$totalMinutes = floor(($dailyTotals[$date_key] % 3600) / 60);
					$totalDisplay = sprintf('%02d:%02d', $totalHours, $totalMinutes);
					print '<span class="duration-total-display no-icon"><span class="duration-total-text">' . $totalDisplay . '</span></span>';
				}

				print '</td>';

				// Editor column - show ZS badge if imported, otherwise show editor username
				print '<td style="text-align: center;">';
				$is_real_entry = !empty($record['real_entry']) && $record['real_entry'] == 1;
				if ($is_real_entry) {
					print '<span class="badge badge-status4" style="background-color: #28a745;" title="Imported from ZS BMS">ZS</span>';
				} else {
					// Show username of person who modified the record
					if (!empty($record['modif_login'])) {
						print '<span class="badge badge-status4" style="background-color: #ffc107 !important; font-size: 0.85em;" title="' . dol_escape_htmltag($record['modif_fullname']) . '">' . dol_escape_htmltag($record['modif_login']) . '</span>';
					} else {
						print '<span style="color: #999;">--</span>';
					}
				}
				print '</td>';

				// Actions (right-aligned)
				print '<td style="white-space: nowrap; text-align: right;">';

				// Add + button for creating new record below this row (shaded/inactive when not in edit mode)
				if ($user->rights->dolizsynch->zspunchclock->write) {
					// Convert dataval to YYYY-MM-DD format for JavaScript
					$dataval_formatted = date('Y-m-d', strtotime($record['dataval']));
					if ($edit_mode) {
						print '<a href="#" onclick="addNewRowBelow(\'' . $dataval_formatted . '\', ' . $record['rowid'] . ', ' . $empid_zs . '); return false;" title="Adicionar novo registo abaixo" style="margin-right: 5px;">';
						print '<span class="fas fa-plus" style="color: #28a745;"></span>';
						print '</a>';
					} else {
						print '<span title="Ativar modo de edição para adicionar" style="margin-right: 5px; opacity: 0.3; cursor: not-allowed;">';
						print '<span class="fas fa-plus" style="color: #28a745;"></span>';
						print '</span>';
					}
				}

				// Add single delete button for the entire row (shaded/inactive when not in edit mode)
				// Don't show delete button for empty unsaved rows (rowid=0)
				if ($user->rights->dolizsynch->zspunchclock->write && $record['rowid'] > 0) {
					if ($edit_mode) {
						print '<a href="#" onclick="deleteEntireRow(' . $record['rowid'] . ', \'' . dol_escape_js($record['fullname']) . '\'); return false;" title="Eliminar registo completo">';
						print '<span class="fas fa-minus" style="color: #dc3545;" title="Eliminar registo completo"></span>';
						print '</a>';
					} else {
						print '<span title="Ativar modo de edição para eliminar" style="opacity: 0.3; cursor: not-allowed;">';
						print '<span class="fas fa-minus" style="color: #dc3545;"></span>';
						print '</span>';
					}
				}
				print '</td>';

				print '</tr>' . "\n";
			}
		} else {
			print '<tr><td colspan="9" style="text-align: center;">Nenhum registo encontrado</td></tr>';
		}

		// Totals footer
		if (!empty($punchRecords)) {
			$totalDuration = 0;
			foreach ($punchRecords as $record) {
				if (!empty($record['duration'])) {
					$totalDuration += $record['duration'];
				}
			}

			$totalHours = floor($totalDuration / 3600);
			$totalMinutes = floor(($totalDuration % 3600) / 60);
			$totalDurationDisplay = sprintf('%02d:%02d', $totalHours, $totalMinutes);

			print '<tr class="liste_total">';
			print '<td colspan="5" style="text-align: right; vertical-align: middle;">Total:</td>';
			print '<td class="overall-total-duration duration-cell" style="text-align: right; vertical-align: middle;" data-total-seconds="' . $totalDuration . '">';
			print '<div style="display: inline-flex; align-items: center; justify-content: flex-end; width: 100%;">';
			print '<input type="text" class="inline-edit-field duration-field total-duration-input" value="' . $totalDurationDisplay . '" disabled readonly style="text-align: right; background-color: transparent; border: none; cursor: default; color: #666; width: auto;">';
			if ($edit_mode) {
				print '<span class="fas fa-clock" style="margin-left: 6px; font-size: 13px; color: #6c757d;"></span>';
			}
			print '</div>';
			print '</td>';
			print '<td style="text-align: right; vertical-align: middle;"></td>'; // Total Dia column (empty in footer)
			print '<td style="text-align: center; vertical-align: middle;"></td>'; // Editor column (empty in footer)
			print '<td style="text-align: right; vertical-align: middle;"></td>'; // Actions column (empty in footer)
			print '</tr>' . "\n";
		}

		print '</table>' . "\n";
		print '</div>' . "\n";

		$db->free($resql);
	} else {
		setEventMessages("Funcionário sem ID ZS configurado", null, 'errors');
	}
} else {
	// No employee selected or search not clicked - show empty table
	// Add CSS styles (same as when employee is selected)
	print '<style>
.error-cell {
    background-color: red;
    color: red;
}

/* Inline edit styles */
.inline-edit-field {
    width: 100%;
    border: none;
    background: transparent;
    padding: 2px;
}

.inline-edit-field:focus {
    background: #f0f8ff;
    border: 1px solid #4CAF50;
    outline: none;
}

/* New row highlight animation */
@keyframes highlightNewRow {
    0% {
        background-color: #d4edda;
        box-shadow: 0 0 10px rgba(40, 167, 69, 0.5);
    }
    50% {
        background-color: #c3e6cb;
        box-shadow: 0 0 15px rgba(40, 167, 69, 0.7);
    }
    100% {
        background-color: transparent;
        box-shadow: none;
    }
}

.new-row-highlight {
    animation: highlightNewRow 2.5s ease-in-out;
}

.new-row-highlight td {
    border-top: 2px solid #28a745 !important;
    border-bottom: 2px solid #28a745 !important;
}

.new-row-highlight td:first-child {
    border-left: 2px solid #28a745 !important;
}

.new-row-highlight td:last-child {
    border-right: 2px solid #28a745 !important;
}

.time-field {
    text-align: center;
    width: 100px;
    max-width: 100px;
    margin: 1px 0;
    padding: 2px;
}

.duration-field {
    text-align: center;
    font-variant-numeric: tabular-nums;
    width: 100px;
    max-width: 100px;
    margin: 1px 0;
    padding: 2px;
}

.duration-cell {
    /* text-align set inline to right */
}

.employee-total-cell {
    text-align: center;
    font-variant-numeric: tabular-nums;
}

.duration-cell .duration-field {
    display: inline-block;
    width: 100px;
    max-width: 100px;
    font-variant-numeric: tabular-nums;
}

.duration-cell .duration-display {
    display: inline-block;
    width: 80px;
    padding: 4px 0;
    text-align: center;
    font-variant-numeric: tabular-nums;
}

.duration-total-display {
    display: inline-flex;
    align-items: center;
    justify-content: flex-end;
    min-width: 80px;
    padding: 4px 0;
    font-variant-numeric: tabular-nums;
}

.duration-total-display.no-icon {
    justify-content: flex-end;
}

.duration-icon {
    font-size: 13px;
    color: #6c757d;
    margin-left: 6px;
}

.duration-total-text {
    min-width: 48px;
    text-align: center;
    display: inline-block;
}

.overall-total-duration {
    text-align: center;
    font-variant-numeric: tabular-nums;
}

/* Webkit browsers (Chrome, Safari, Edge) - adjust clock icon position */
.time-field::-webkit-calendar-picker-indicator {
    margin-left: -8px;
    padding: 0;
}

.numeric-field {
    text-align: right;
}

.inline-edit-field.editing {
    background: #fff3cd !important;
    border: 1px solid #ffc107 !important;
}

.inline-edit-field.saving {
    background: #d1ecf1 !important;
    border: 1px solid #bee5eb !important;
}

.inline-edit-field.error {
    background: #f8d7da !important;
    border: 1px solid #f5c6cb !important;
}

.inline-edit-field.success {
    background: #d4edda !important;
    border: 1px solid #c3e6cb !important;
}

/* Table layout */
.div-table-responsive {
    overflow-x: auto;
    margin: 0 -10px;
}

.tagtable.liste {
    width: 100%;
    table-layout: auto;
}

.tagtable.liste th,
.tagtable.liste td {
    white-space: nowrap;
    padding: 4px 8px;
    vertical-align: middle;
}

/* Right-aligned columns - ensure content inside also aligns */
.tagtable.liste th[style*="text-align: right"],
.tagtable.liste td[style*="text-align: right"] {
    text-align: right !important;
}

/* Make sure spans inside right-aligned cells also align right */
.tagtable.liste td[style*="text-align: right"] span {
    display: inline-block;
}

/* Sortable column header links */
.tagtable.liste th a {
    color: inherit;
    text-decoration: none;
    display: inline-block;
}

.tagtable.liste th a:hover {
    text-decoration: underline;
}
</style>';

	// Display empty table
	print '<div class="div-table-responsive">';
	print '<table class="tagtable liste">';

	// Table header
	print '<tr class="liste_titre">';
	print '<th>Funcionário</th>';
	print '<th style="text-align: center;">ID ZS</th>';

	// Sortable Data column
	$sorturl = $_SERVER["PHP_SELF"] . '?employee_id=' . $employee_id;
	$sorturl .= '&date_startday=' . $date_start_day . '&date_startmonth=' . $date_start_month . '&date_startyear=' . $date_start_year;
	$sorturl .= '&date_endday=' . $date_end_day . '&date_endmonth=' . $date_end_month . '&date_endyear=' . $date_end_year;
	$sorturl .= '&edit_mode=' . $edit_mode;
	$newsortorder = ($sortfield == 't.dataval' && $sortorder == 'ASC') ? 'DESC' : 'ASC';
	$sortarrow = '';
	if ($sortfield == 't.dataval') {
		$sortarrow = ($sortorder == 'ASC') ? ' &#9650;' : ' &#9660;';
	}
	print '<th style="text-align: center;">';
	print '<a href="' . $sorturl . '&sortfield=t.dataval&sortorder=' . $newsortorder . '">Data' . $sortarrow . '</a>';
	print '</th>';

	print '<th style="text-align: center;">Entrada (IN)</th>';
	print '<th style="text-align: center;">Saída (OUT)</th>';
	print '<th style="text-align: center;">Duração</th>';
	print '<th style="text-align: center;">Total Dia</th>';
	print '<th style="text-align: center;">Editor</th>';
	print '<th style="text-align: right;">Ações</th>';
	print '</tr>' . "\n";

	// Empty message
	print '<tr><td colspan="9" style="text-align: center; padding: 20px; color: #888;">Selecione um funcionário e clique em Pesquisar</td></tr>';

	print '</table>' . "\n";
	print '</div>' . "\n";
}

// Edit Mode Toggle Button
// Show edit mode toggle button if employee is selected and records exist
if ($user->rights->dolizsynch->zspunchclock->write && $employee_id > 0 && !empty($punchRecords)) {
	print '<div style="text-align: center; margin-top: 20px;">';
	if ($edit_mode) {
		print '<a href="' . $_SERVER["PHP_SELF"] . '?';
		print 'edit_mode=0';
		print '&employee_id=' . urlencode($employee_id);
		print '&date_startday=' . urlencode($date_start_day);
		print '&date_startmonth=' . urlencode($date_start_month);
		print '&date_startyear=' . urlencode($date_start_year);
		print '&date_endday=' . urlencode($date_end_day);
		print '&date_endmonth=' . urlencode($date_end_month);
		print '&date_endyear=' . urlencode($date_end_year);
		print '" class="butAction">Sair do Modo de Edição</a>';
	} else {
		print '<a href="' . $_SERVER["PHP_SELF"] . '?';
		print 'edit_mode=1';
		print '&employee_id=' . urlencode($employee_id);
		print '&date_startday=' . urlencode($date_start_day);
		print '&date_startmonth=' . urlencode($date_start_month);
		print '&date_startyear=' . urlencode($date_start_year);
		print '&date_endday=' . urlencode($date_end_day);
		print '&date_endmonth=' . urlencode($date_end_month);
		print '&date_endyear=' . urlencode($date_end_year);
		print '" class="butAction">Entrar no Modo de Edição</a>';
	}
	print '</div>';
}

print '</form>' . "\n";

// JavaScript for inline editing (same pattern as zspunchclock_list.php)
?>
<script type="text/javascript">
	var LIST_IS_EDIT_MODE = <?= (int) $edit_mode ?>;

	function hhmmToSeconds(value) {
		if (!value || typeof value !== "string") {
			return 0;
		}
		var parts = value.split(":");
		if (parts.length < 2) {
			return 0;
		}
		var hours = parseInt(parts[0], 10);
		var minutes = parseInt(parts[1], 10);
		if (isNaN(hours) || isNaN(minutes)) {
			return 0;
		}
		return (hours * 3600) + (minutes * 60);
	}

	function secondsToHHMM(totalSeconds) {
		totalSeconds = parseInt(totalSeconds, 10);
		if (!isFinite(totalSeconds) || isNaN(totalSeconds)) {
			totalSeconds = 0;
		}
		if (totalSeconds < 0) {
			totalSeconds = 0;
		}
		var hours = Math.floor(totalSeconds / 3600);
		var minutes = Math.floor((totalSeconds % 3600) / 60);
		var hoursStr = hours < 10 ? "0" + hours : "" + hours;
		var minutesStr = minutes < 10 ? "0" + minutes : "" + minutes;
		return hoursStr + ":" + minutesStr;
	}

	function updateRowDurationState($row, durationHHMM) {
		if (!$row || !$row.length) {
			return 0;
		}

		if (typeof durationHHMM === "undefined" || durationHHMM === null) {
			var $durationField = $row.find('input[data-field="duration"]');
			if ($durationField.length) {
				durationHHMM = $durationField.val();
			} else {
				durationHHMM = $row.find(".duration-display").text();
			}
		}

		if (typeof durationHHMM !== "string") {
			durationHHMM = "";
		}

		var seconds = hhmmToSeconds(durationHHMM);
		$row.attr("data-duration-seconds", seconds).data("duration-seconds", seconds);

		var $durationFieldElement = $row.find('input[data-field="duration"]');
		if ($durationFieldElement.length) {
			$durationFieldElement.attr("data-duration-seconds", seconds);
			if (durationHHMM) {
				$durationFieldElement.val(durationHHMM);
			}
		}

		var $durationDisplay = $row.find(".duration-display");
		if ($durationDisplay.length) {
			$durationDisplay.attr("data-seconds", seconds);
			$durationDisplay.text(durationHHMM);
		}

		return seconds;
	}

	function buildDurationDisplayHtml(value, includeIcon) {
		if (typeof value !== "string") {
			value = "";
		}
		if (includeIcon) {
			return '<span class="duration-total-display"><span class="duration-total-text">' +
				value + '</span><span class="fas fa-clock duration-icon"></span></span>';
		}
		return '<span class="duration-total-display no-icon"><span class="duration-total-text">' +
			value + '</span></span>';
	}

	function updateOverallTotalDisplay() {
		var totalSeconds = 0;

		$('tr[data-rowid]').each(function() {
			var $row = $(this);
			var seconds = parseInt($row.attr("data-duration-seconds"), 10);
			if (!isNaN(seconds)) {
				totalSeconds += seconds;
			}
		});

		var $overallCell = $(".overall-total-duration");
		if ($overallCell.length) {
			var overallFormatted = secondsToHHMM(totalSeconds);
			$overallCell.attr("data-total-seconds", totalSeconds);
			$overallCell.find("input.duration-field").val(overallFormatted);
		}
	}

	function refreshTotalsForRow($row, durationHHMM) {
		if (!$row || !$row.length) {
			return;
		}

		updateRowDurationState($row, durationHHMM);
		updateOverallTotalDisplay();
	}

	function addNewRowBelow(dateString, clickedRowId, empidZs) {
		console.log("addNewRowBelow called with date:", dateString, "rowId:", clickedRowId, "empidZs:", empidZs);

		console.log("Sending AJAX request to create new record for date:", dateString);

		$.ajax({
			url: "<?= $_SERVER["PHP_SELF"] ?>",
			type: "POST",
			dataType: "json",
			data: {
				action: "create_inline",
				ajax: "1",
				token: CSRF_TOKEN,
				empid_zs: empidZs,
				dataval: dateString,
				clicked_rowid: clickedRowId
			},
			success: function(response) {
				console.log("AJAX response:", response);
				if (response.success) {
					// Reload page with new row ID to highlight it
					var params = new URLSearchParams(window.location.search);
					params.set("newrow", response.id);
					params.delete("action"); // Remove any action parameters
					window.location.href = window.location.pathname + "?" + params.toString();
				} else {
					alert("Erro: " + (response.error || "Erro desconhecido"));
				}
			},
			error: function(xhr, status, error) {
				console.error("AJAX error:", error);
				console.error("Response:", xhr.responseText);
				alert("Erro de rede: " + error);
			}
		});
	}

	function deleteEntireRow(rowid, employeeName) {
		console.log("Delete function called with rowid:", rowid);

		if (!confirm("Tem certeza que deseja eliminar todo o registo de " + employeeName + "?")) {
			console.log("User cancelled deletion");
			return;
		}

		console.log("Sending delete request...");

		$.ajax({
			url: "<?= $_SERVER["PHP_SELF"] ?>",
			type: "POST",
			dataType: "json",
			data: {
				action: "delete_entire_row",
				ajax: "1",
				token: CSRF_TOKEN,
				rowid: rowid
			},
			success: function(response) {
				console.log("Delete response received:", response);
				if (response.success) {
					console.log("Deletion successful, reloading page...");
					window.location.reload();
				} else {
					console.error("Deletion failed:", response.error);
					alert("Erro: " + (response.error || "Erro desconhecido"));
				}
			},
			error: function(xhr, status, error) {
				console.error("AJAX error:", error);
				console.error("Response text:", xhr.responseText);
				alert("Erro de rede: " + error);
			}
		});
	}

	console.log("ZSPunchClock Employee custom JavaScript is loading...");
	$(document).ready(function() {
		console.log("Document ready - initializing inline editing...");

		$('tr[data-rowid]').each(function() {
			updateRowDurationState($(this));
		});

		updateOverallTotalDisplay();

		// Store original value when field is focused
		$(".inline-edit-field").on("focus", function() {
			var $field = $(this);
			// Only store if not already stored
			if (typeof $field.data("original-value") === "undefined") {
				var initialValue = $field.val() || "";
				$field.data("original-value", initialValue);
				console.log("Stored original value on focus:", initialValue, "for field:", $field.data(
					"field"));
			}
			$field.addClass("editing");
		});

		// Remove editing class when field loses focus
		$(".inline-edit-field").on("blur", function() {
			$(this).removeClass("editing");

			var field = this;
			var $field = $(field);
			var fieldName = $field.data("field");
			var recordId = $field.data("id");
			var newValue = $field.val();
			var originalValue = $field.data("original-value");

			// If original value was never stored, skip
			if (typeof originalValue === "undefined") {
				console.log("No original value stored, skipping save");
				return;
			}

			// Skip if value hasn't changed
			if (newValue === originalValue) {
				console.log("Value hasn't changed, skipping save");
				return;
			}

			console.log("Inline field blur - saving:", {
				recordId: recordId,
				fieldName: fieldName,
				oldValue: originalValue,
				newValue: newValue
			});

			// Show saving state
			$field.addClass("saving").prop("disabled", true);

			// Make AJAX call to save
			$.ajax({
				url: "<?= $_SERVER["PHP_SELF"] ?>",
				type: "POST",
				dataType: "json",
				data: {
					action: "save_inline",
					ajax: "1",
					token: CSRF_TOKEN,
					id: recordId,
					field: fieldName,
					value: newValue
				},
				success: function(response) {
					console.log("Inline field AJAX response:", response);
					$field.removeClass("saving").prop("disabled", false);

					if (response.success) {
						var $row = $field.closest("tr");
						var durationForTotals = null;

						var formattedValue = typeof response.formatted_value !== "undefined" ?
							response.formatted_value : null;
						if (formattedValue !== null) {
							$field.val(formattedValue);
						}

						var storedOriginal = formattedValue !== null ? formattedValue :
							newValue;
						$field.data("original-value", storedOriginal);

						if (fieldName === "duration") {
							durationForTotals = storedOriginal;
						}

						$field.addClass("success").removeClass("error");
						setTimeout(function() {
							$field.removeClass("success");
						}, 1000);

						// If we updated dataval (date), update the display span in view mode
						if (fieldName === "dataval" && response.display_date) {
							var rowId = $field.data("id");
							var $displaySpan = $(".date-display-" + rowId);
							if ($displaySpan.length) {
								$displaySpan.text(response.display_date);
							}
						}

						if (fieldName === "duration") {
							if (response.datahora_out) {
								var $punchOutField = $row.find(
									"input[data-field=\"datahora_out\"]");
								if ($punchOutField.length) {
									$punchOutField.val(response.datahora_out);
									$punchOutField.data("original-value", response
										.datahora_out);
									$punchOutField.addClass("success");
									setTimeout(function() {
										$punchOutField.removeClass("success");
									}, 1000);
								}
							}
						}

						if (response.duration !== undefined) {
							durationForTotals = response.duration;
							var $durationField = $row.find("input[data-field=\"duration\"]");
							if ($durationField.length) {
								$durationField.val(response.duration);
								$durationField.data("original-value", response.duration);
								$durationField.addClass("success");
								setTimeout(function() {
									$durationField.removeClass("success");
								}, 1000);
							}
						}

						refreshTotalsForRow($row, durationForTotals);
					} else {
						// Show error
						$field.addClass("error").removeClass("success");
						alert("Error: " + (response.error || "Unknown error"));

						// Restore original value
						$field.val(originalValue);

						setTimeout(function() {
							$field.removeClass("error");
						}, 3000);
					}
				},
				error: function(xhr, status, error) {
					$field.removeClass("saving").prop("disabled", false);
					$field.addClass("error");
					alert("Network error: " + error);

					// Restore original value
					$field.val(originalValue);

					setTimeout(function() {
						$field.removeClass("error");
					}, 3000);
				}
			});
		});

		// Handle Enter key to save and move to next field
		$(".inline-edit-field").on("keydown", function(e) {
			if (e.which === 13) { // Enter key
				$(this).blur();
			}
		});

		// Handle pagination: submit form when page number changes
		$(document).on("keydown", "input.pageplusone", function(e) {
			if (e.which === 13) { // Enter key
				console.log("Page input Enter pressed, value:", $(this).val());
				e.preventDefault();
				var form = $(this).closest("form");
				console.log("Form found:", form.length);
				if (form.length) {
					form.submit();
				}
			}
		});

		// Handle pagination: submit form when limit changes
		$(document).on("change", "select#limit, input#limit", function() {
			console.log("Limit changed, value:", $(this).val());
			var form = $(this).closest("form");
			console.log("Form found:", form.length);
			if (form.length) {
				form.submit();
			}
		});
	});
</script>
<?php

llxFooter();
$db->close();

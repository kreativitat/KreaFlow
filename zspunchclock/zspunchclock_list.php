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
require '../../../main.inc.php';
require_once DOL_DOCUMENT_ROOT . '/projet/class/project.class.php';
require_once DOL_DOCUMENT_ROOT . '/projet/class/task.class.php';
require_once DOL_DOCUMENT_ROOT . '/core/lib/project.lib.php';
require_once DOL_DOCUMENT_ROOT . '/core/lib/date.lib.php';
require_once DOL_DOCUMENT_ROOT . '/core/class/html.formother.class.php';
require_once DOL_DOCUMENT_ROOT . '/core/class/html.formprojet.class.php';
require_once DOL_DOCUMENT_ROOT . '/user/class/user.class.php';
require_once __DIR__ . '/../class/zspunchclock_synch.class.php';
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

/**
 * Resolve configured shift employee group IDs for the active entity.
 *
 * @return array<int,int>
 */
function dzs_punchclock_get_shift_employee_group_ids()
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
 * Build SQL used by employee dropdowns in punch clock screens.
 *
 * @param int        $entity Entity scope
 * @param array<int> $groupIds Optional list of employee group IDs
 * @return string
 */
function dzs_punchclock_build_employee_lookup_sql($entity, array $groupIds = array())
{
	$entity = (int) $entity;

	$sql = "SELECT DISTINCT u.rowid, u.firstname, u.lastname, ue.zs_employee_id";
	$sql .= " FROM " . MAIN_DB_PREFIX . "user AS u";

	if (!empty($groupIds)) {
		$sql .= " INNER JOIN " . MAIN_DB_PREFIX . "usergroup_user AS ugu ON ugu.fk_user = u.rowid";
		$sql .= " AND ugu.entity = " . $entity;
		$sql .= " AND ugu.fk_usergroup IN (" . implode(',', array_map('intval', $groupIds)) . ")";
	}

	$sql .= " LEFT JOIN " . MAIN_DB_PREFIX . "user_extrafields AS ue ON u.rowid = ue.fk_object";
	$sql .= " WHERE u.statut = 1";

	// Fallback when no group is configured: stay in current entity to avoid cross-entity leakage.
	if (empty($groupIds)) {
		$sql .= " AND u.entity = " . $entity;
	}

	$sql .= " AND ue.zs_employee_id IS NOT NULL AND ue.zs_employee_id > 0";
	$sql .= " ORDER BY u.firstname, u.lastname";

	return $sql;
}

global $conf, $langs, $db, $user;

// Load variable for pagination
$limit = GETPOST('limit', 'int') ? GETPOST('limit', 'int') : 50;
$sortfield = GETPOST('sortfield', 'aZ09comma');
$sortorder = GETPOST('sortorder', 'aZ09comma');
$page = GETPOSTISSET('pageplusone') ? (GETPOST('pageplusone') - 1) : GETPOST("page", 'int');
if (empty($page) || $page < 0 || GETPOST('button_search', 'alpha') || GETPOST('button_removefilter', 'alpha')) {
	// If $page is not defined, or '' or -1 or if we click on clear filters
	$page = 0;
}
$offset = $limit * $page;
$pageprev = $page - 1;
$pagenext = $page + 1;

// Initialize other variables
$optioncss = GETPOST('optioncss', 'aZ');
$contextpage = GETPOST('contextpage', 'aZ') ? GETPOST('contextpage', 'aZ') : 'zspunchclocklist';
$mode = GETPOST('mode', 'aZ');
$edit_mode = GETPOST('edit_mode', 'int') ? 1 : 0;

// Load translations
$langsLoad = array('projects', 'bills', 'orders', 'companies', 'zspunchclock');
if (isModEnabled('eventorganization')) {
	$langsLoad[] = 'eventorganization';
}
$langs->loadLangs($langsLoad);

// Get parameters
$action = GETPOST('action', 'alpha');
$confirm = GETPOST('confirm', 'alpha');
$rowid = GETPOST('id', 'int');

// Load object
$object = new ZSPunchClock($db);
$object->loja_zs = !empty($conf->global->ZS_API_STORE) ? $conf->global->ZS_API_STORE : 0;

// Security check
if (!$user->rights->dolizsynch->zspunchclock->read) {
	accessforbidden();
}

$shiftEmployeeGroupIds = dzs_punchclock_get_shift_employee_group_ids();

// Fetch the ZS_PUNCH_DAY_CHANGE_HOUR configuration
$zsPunchDayChangeHour = !empty($conf->global->ZS_PUNCH_DAY_CHANGE_HOUR) ? (int)$conf->global->ZS_PUNCH_DAY_CHANGE_HOUR : 0;

// Handle day navigation using Dolibarr infrastructure
$action = GETPOST('action', 'aZ09');

// Calculate yesterday's date for comparison
$yesterday = date('Y-m-d', strtotime('-1 day'));

// Handle navigation actions
if ($action == 'prev_day') {
	$search_date = GETPOST('search_date', 'alpha');
	if (!empty($search_date)) {
		// Allow going back to any historical date
		$selectedDate = date('Y-m-d', strtotime($search_date . ' -1 day'));
	} else {
		// If no search_date provided, default to yesterday
		$selectedDate = $yesterday;
	}
} elseif ($action == 'next_day') {
	$search_date = GETPOST('search_date', 'alpha');
	if (!empty($search_date)) {
		$nextDay = date('Y-m-d', strtotime($search_date . ' +1 day'));
		// If trying to go to today or future, redirect to synch list
		if ($nextDay >= date('Y-m-d')) {
			header('Location: ' . DOL_URL_ROOT . '/custom/kreaflow/zspunchclock/zspunchclock_synch_list.php');
			exit;
		}
		$selectedDate = $nextDay;
	} else {
		// If no search_date provided, default to yesterday
		$selectedDate = $yesterday;
	}
} else {
	// Get selected date from search filters
	$selectedDate = GETPOST('search_date', 'alpha');

	if (empty($selectedDate)) {
		// Default to yesterday instead of looking for latest entry
		$selectedDate = $yesterday;
	}
}

// Calculate the date range for the selected day
$selectedDateStart = $selectedDate . ' ' . sprintf('%02d:00:00', $zsPunchDayChangeHour);
$selectedDateEnd = date('Y-m-d', strtotime($selectedDate . ' +1 day')) . ' ' . sprintf('%02d:00:00', $zsPunchDayChangeHour);

// Keep the old variables for backward compatibility, but use selected date
$todayDateStringStart = $selectedDateStart;
$todayDateStringEnd = $selectedDateEnd;


// Navigation debug logging for troubleshooting if needed
if (!empty($conf->global->MAIN_MODULE_DOLICLOUD) && $action) {
}

// Reset filters if button_removefilter_x is clicked
if (GETPOST('button_removefilter_x', 'alpha') !== '') {
	unset($_POST['search_fullname']);
	unset($_POST['search_empid_zs']);
	unset($_POST['search_sentido']);
	unset($_POST['search_date']);
	$_SERVER['REQUEST_URI'] = strtok($_SERVER["REQUEST_URI"], '?'); // Remove query parameters
	header("Location: " . $_SERVER['REQUEST_URI']);
	exit;
}

// Handle actions
if ($action == 'confirm_deleteline' && $confirm == "yes" && $user->rights->dolizsynch->zspunchclock->write) {
	$lineid = GETPOST('lineid', 'int');
	$object->fetch($lineid);
	// Instead of deleting the row, set synch to 3 to mark it as deleted.
	// Explanation: We set synch to 3 ("deleted") instead of deleting the record
	// to avoid issues with data inconsistency when synchronizing with Zone Soft.
	// This approach prevents duplicate values and maintains original data integrity.
	$object->synch = 3; // Set synch value to 3 (deleted) instead of deleting the row
	$object->fk_user_modif = $user->id; // Record who deleted it

	// Force tms update by using direct SQL to ensure timestamp is refreshed
	$sql = "UPDATE " . MAIN_DB_PREFIX . "dolizsynch_zspunchclock";
	$sql .= " SET synch = 3, fk_user_modif = " . intval($user->id);
	$sql .= " WHERE rowid = " . intval($object->id);
	$result = $db->query($sql) ? 1 : -1;
	if ($result < 0) {
		setEventMessages($langs->trans($object->error), null, 'errors');
	} else {
		setEventMessages($langs->trans("RecordDeleted"), null, 'mesgs');
	}
}

if ($action == 'updateline' && !$cancel && $user->rights->dolizsynch->zspunchclock->write) {
	$error = 0;
	$lineid = GETPOST("lineid", 'int');
	$object->fetch($lineid);

	// Fetch existing data
	$originalDatahora = $object->datahora;
	$originalDataval = $object->dataval;
	$originalSentido = $object->sentido;
	$originalEmpid_zs = $object->empid_zs;

	// Update datahora and dataval
	$datahora = dol_mktime(
		GETPOST("timelineinhour", 'int'),
		GETPOST("timelineinmin", 'int'),
		0,
		GETPOST("timelineinmonth", 'int'),
		GETPOST("timelineinday", 'int'),
		GETPOST("timelineinyear", 'int')
	);
	$dataval = dol_mktime(
		GETPOST("timelineouthour", 'int'),
		GETPOST("timelineoutmin", 'int'),
		0,
		GETPOST("timelineoutmonth", 'int'),
		GETPOST("timelineoutday", 'int'),
		GETPOST("timelineoutyear", 'int')
	);

	$zsEmployeeID = GETPOST('empid_zs', 'int');
	if ($zsEmployeeID <= 0) {
		setEventMessages($langs->trans("empid_zs"), null, 'errors');
		$error++;
	} else {
		$object->empid_zs = $zsEmployeeID;
	}

	// Check if datahora is within the allowed range
	if ($datahora < strtotime($todayDateStringStart) || $datahora > strtotime($todayDateStringEnd)) {
		setEventMessages($langs->trans("DataHoraOutOfRange"), null, 'errors');
		$error++;
	}

	if (!$error) {
		// Instead of updating the row, set synch to 2 to mark it as updated.
		// Explanation: We set synch to 2 ("updated") instead of updating the record
		// to avoid issues with data inconsistency when synchronizing with Zone Soft.
		// This approach prevents duplicate values and maintains original data integrity.
		$object->synch = 2; // Set original row's synch value to 2 (updated)
		$object->fk_user_modif = $user->id; // Record who modified it

		// Force tms update by using direct SQL to ensure timestamp is refreshed
		$sql = "UPDATE " . MAIN_DB_PREFIX . "dolizsynch_zspunchclock";
		$sql .= " SET synch = 2, fk_user_modif = " . intval($user->id);
		$sql .= " WHERE rowid = " . intval($object->id);
		$result = $db->query($sql) ? 1 : -1;
		if ($result >= 0) {
			// Insert new row with updated values and synch value 0
			$newObject = new ZSPunchClock_Synch($db);
			$newObject->datahora = date('Y-m-d H:i:s', $datahora);
			$newObject->dataval = date('Y-m-d', $dataval);
			$newObject->sentido = GETPOST("sentido", 'int'); // Ensure sentido is saved as 1 for IN and 2 for OUT
			$newObject->empid_zs = $zsEmployeeID;
			$newObject->synch = 0;
			$newObject->loja_zs = $object->loja_zs;
			$newObject->date_creation = dol_now(); // Record creation date
			$newObject->fk_user_modif = $user->id; // Record who created it

			$result = $newObject->create($user);
			if ($result >= 0) {
				setEventMessages($langs->trans("RecordSaved"), null, 'mesgs');
			} else {
				setEventMessages($langs->trans($newObject->error), null, 'errors');
			}
		} else {
			setEventMessages($langs->trans($object->error), null, 'errors');
		}
	}
}

if ($action == 'addline' && !$cancel && $user->rights->dolizsynch->zspunchclock->write) {
	$error = 0;

	// Process datahora from POST components
	$datahora = dol_mktime(
		GETPOST("datehorahour", 'int'),
		GETPOST("datehoramin", 'int'),
		0,
		GETPOST("datehoramonth", 'int'),
		GETPOST("datehoraday", 'int'),
		GETPOST("datehorayear", 'int')
	);
	$object->datahora = date('Y-m-d H:i:s', $datahora);

	$dataval = GETPOST("dataval", 'alpha');
	$object->dataval = $dataval;

	$object->synch = 0;
	$object->sentido = GETPOST("sentido", 'int');
	$zsEmployeeID = GETPOST("zsEmployeeID", 'int');

	// Fetch employee ID from user ID
	$sqlEmpID = "SELECT ue.zs_employee_id FROM " . MAIN_DB_PREFIX . "user_extrafields ue WHERE ue.fk_object = " . $zsEmployeeID;
	$resqlEmpID = $db->query($sqlEmpID);
	if ($resqlEmpID) {
		$objEmpID = $db->fetch_object($sqlEmpID);
		if ($objEmpID) {
			$object->empid_zs = $objEmpID->zs_employee_id;
		} else {
			setEventMessages($langs->trans("EmployeeNotFound"), null, 'errors');
			$error++;
		}
	} else {
		setEventMessages($langs->trans("DatabaseQueryError"), null, 'errors');
		$error++;
	}

	if (!$error) {
		$object->fk_user_modif = $user->id; // Record who created it
		$result = $object->create($user);
		if ($result >= 0) {
			setEventMessages($langs->trans("RecordAdded"), null, 'mesgs');
		} else {
			setEventMessages($langs->trans($object->error), null, 'errors');
		}
	}
}

// Build the SQL query with filters
$sql = 'SELECT t.rowid, t.entity, ';
$sql .= 't.empid_zs, t.dataval, t.datahora_in, t.datahora_out, t.duration, t.tms, t.position, t.real_entry, t.fk_user_modif, t.fk_user_creat, ';
$sql .= 'u.firstname, u.lastname, CONCAT(u.firstname, " ", u.lastname) as fullname, ';
$sql .= 'um.login as modif_login, CONCAT(um.firstname, " ", um.lastname) as modif_fullname ';
$sql .= 'FROM ' . MAIN_DB_PREFIX . 'dolizsynch_zspunchclock as t ';
$sql .= 'LEFT JOIN ' . MAIN_DB_PREFIX . 'user_extrafields as ue ON t.empid_zs = ue.zs_employee_id ';
$sql .= 'LEFT JOIN ' . MAIN_DB_PREFIX . 'user as u ON ue.fk_object = u.rowid ';
$sql .= 'LEFT JOIN ' . MAIN_DB_PREFIX . 'user as um ON t.fk_user_modif = um.rowid ';
$sql .= 'WHERE t.entity IN (' . getEntity('dolizsynch_zspunchclock') . ')';

// Apply search filters
$search_fullname = GETPOST('search_fullname', 'alpha');
$search_empid_zs = GETPOST('search_empid_zs', 'alpha');
$search_sentido = GETPOST('search_sentido', 'alpha');
$search_date = !empty($selectedDate) ? $selectedDate : GETPOST('search_date', 'alpha');

if (!empty($search_fullname)) {
	$sql .= " AND CONCAT(u.firstname, ' ', u.lastname) LIKE '%" . $db->escape($search_fullname) . "%'";
}
if (!empty($search_empid_zs)) {
	$sql .= " AND t.empid_zs LIKE '%" . $db->escape($search_empid_zs) . "%'";
}
if (!empty($search_sentido)) {
	$sql .= " AND t.sentido = '" . $db->escape($search_sentido) . "'";
}

// Filter by selected date (dataval must match exactly)
if (!empty($search_date)) {
	$sql .= " AND DATE(t.dataval) = '" . $db->escape($selectedDate) . "'";
}

// Order by employee ID first, then by datahora_in ascending (earliest to latest)
// NULLs are sorted last so the greatest actual datahora_in is the last non-NULL row
$sql .= " ORDER BY t.empid_zs ASC, t.datahora_in IS NULL ASC, t.datahora_in ASC";

// Count total for pagination
$nbtotalofrecords = '';
if (!getDolGlobalInt('MAIN_DISABLE_FULL_SCANLIST')) {
	/* The fast and low memory method to get and count full list converts the sql into a sql count */
	$sqlforcount = preg_replace('/^SELECT[^F]+FROM/s', 'SELECT COUNT(*) as nbtotalofrecords FROM', $sql);
	$sqlforcount = preg_replace('/ORDER BY .*$/s', '', $sqlforcount);

	$resql_count = $db->query($sqlforcount);
	if ($resql_count) {
		$objforcount = $db->fetch_object($resql_count);
		$nbtotalofrecords = $objforcount->nbtotalofrecords;
	} else {
		dol_print_error($db);
	}

	if (($page * $limit) > $nbtotalofrecords) {	// if total resultset is smaller then paging size (filtering), goto and load page 0
		$page = 0;
		$offset = 0;
	}
	$db->free($resql_count);
}

// Add pagination limit
$sql .= $db->plimit($limit + 1, $offset);

$resql = $db->query($sql);
if (!$resql) {
	dol_print_error($db);
	exit;
}

// No need for complex first punch logic with paired records
// All punch data is already paired in the main query

$num = $db->num_rows($resql);

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
				// Validate field - allow editing of datahora_in, datahora_out, empid_zs, dataval, duration, and fk_user
				if (in_array($field, array('datahora_in', 'datahora_out', 'empid_zs', 'dataval', 'duration', 'fk_user'))) {
					// For datetime fields, convert to Unix timestamp
					if (in_array($field, array('datahora_in', 'datahora_out'))) {
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

							// Debug logging for browser console
							$debug_info = array(
								'field' => $field,
								'new_timestamp' => $timestamp,
								'new_timestamp_date' => date('Y-m-d H:i:s', $timestamp),
								'original_datahora_in' => $objecttosave->datahora_in,
								'original_datahora_out' => $objecttosave->datahora_out,
								'in_timestamp_before' => $in_timestamp,
								'out_timestamp_before' => $out_timestamp,
								'in_timestamp_date_before' => $in_timestamp > 0 ? date('Y-m-d H:i:s', $in_timestamp) : 'invalid',
								'out_timestamp_date_before' => $out_timestamp > 0 ? date('Y-m-d H:i:s', $out_timestamp) : 'invalid'
							);

							// Add datetime creation debug info if it exists
							if (isset($datetime_debug)) {
								$debug_info['datetime_creation'] = $datetime_debug;
							}

							// Override with the new timestamp for the field being changed
							if ($field == 'datahora_in') {
								$in_timestamp = $timestamp;
							} elseif ($field == 'datahora_out') {
								$out_timestamp = $timestamp;
							}

							$debug_info['in_timestamp_after'] = $in_timestamp;
							$debug_info['out_timestamp_after'] = $out_timestamp;
							$debug_info['in_timestamp_date_after'] = $in_timestamp > 0 ? date('Y-m-d H:i:s', $in_timestamp) : 'invalid';
							$debug_info['out_timestamp_date_after'] = $out_timestamp > 0 ? date('Y-m-d H:i:s', $out_timestamp) : 'invalid';

							// Check if we have both valid timestamps and validate the 18-hour limit
							if ($in_timestamp && $out_timestamp && $in_timestamp > 0 && $out_timestamp > 0) {
								$time_diff_seconds = $out_timestamp - $in_timestamp;
								$time_diff_hours = $time_diff_seconds / 3600;
								$debug_info['time_diff_hours'] = $time_diff_hours;

								// Allow for negative differences (OUT before IN, crossing midnight) but still limit to 18 hours total
								if ($time_diff_hours < 0) {
									$time_diff_hours += 24; // Add 24 hours for next day calculation
									$time_diff_seconds += 86400; // Recalculate seconds for next day
								}

								if ($time_diff_hours > 18) {
									echo json_encode(array(
										'success' => false,
										'error' => 'OUT Time cannot be more than 18 hours after IN Time. Current difference: ' . number_format($time_diff_hours, 1) . ' hours.',
										'debug' => $debug_info
									));
									exit;
								}

								// Recalculate and update duration when either IN or OUT time changes
								$objecttosave->duration = $time_diff_seconds;
								$debug_info['recalculated_duration'] = $time_diff_seconds;
							}

							// Note: dataval (reference date) remains unchanged for both IN and OUT times
						} catch (Exception $e) {
							echo json_encode(array('success' => false, 'error' => 'Datetime processing error: ' . $e->getMessage()));
							exit;
						}
					}
					// For date field, convert from Y-m-d format to date string
					elseif ($field == 'dataval') {
						// Value is already in Y-m-d format, just validate it
						if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
							echo json_encode(array('success' => false, 'error' => 'Invalid date format'));
							exit;
						}
						// Keep as string, don't convert to timestamp
						// Also update the datetime date parts if they exist
						if (!empty($objecttosave->datahora_in)) {
							$time_part = date('H:i:s', strtotime($objecttosave->datahora_in));
							$objecttosave->datahora_in = $value . ' ' . $time_part;
						}
						if (!empty($objecttosave->datahora_out)) {
							$time_part = date('H:i:s', strtotime($objecttosave->datahora_out));
							$objecttosave->datahora_out = $value . ' ' . $time_part;
						}
					}
					// For fk_user field, update both fk_user and empid_zs
					elseif ($field == 'fk_user') {
						$fk_user = intval($value);

						// Fetch the zs_employee_id for this user
						$user_sql = "SELECT ue.zs_employee_id FROM " . MAIN_DB_PREFIX . "user_extrafields ue WHERE ue.fk_object = " . $fk_user;
						$user_result = $db->query($user_sql);

						if ($user_result && $db->num_rows($user_result) > 0) {
							$user_obj = $db->fetch_object($user_result);
							$empid_zs = $user_obj->zs_employee_id;
							$db->free($user_result);

							// Update both fields
							$objecttosave->fk_user = $fk_user;
							$objecttosave->empid_zs = $empid_zs;

							// Store empid_zs for overlap check later
							$empid_to_save = $empid_zs;

							// Store fk_user for response
							$value = $fk_user; // Keep the fk_user value for consistency
						} else {
							echo json_encode(array('success' => false, 'error' => 'User not found or no ZS employee ID'));
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
									// Try multiple datetime parsing methods for string formats
									$punch_in_timestamp = false;

									// Try direct strtotime first
									$punch_in_timestamp = strtotime($objecttosave->datahora_in);

									// If that fails, try creating a DateTime object
									if ($punch_in_timestamp === false || $punch_in_timestamp <= 0) {
										try {
											$dt = new DateTime($objecttosave->datahora_in);
											$punch_in_timestamp = $dt->getTimestamp();
										} catch (Exception $e) {
											// Still failed, try manual parsing of common database formats
											if (preg_match('/^(\d{4}-\d{2}-\d{2})\s+(\d{2}:\d{2}:\d{2})$/', $objecttosave->datahora_in, $matches)) {
												$punch_in_timestamp = strtotime($matches[1] . ' ' . $matches[2]);
											}
										}
									}
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

								$new_punch_out = date('Y-m-d H:i:s', $punch_out_timestamp);

								// Update punch out time
								$objecttosave->datahora_out = $new_punch_out;

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

					if ($field !== 'duration' && $field !== 'fk_user') {
						$objecttosave->$field = $value;
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
						$check_empid = ($field == 'fk_user' && isset($empid_to_save)) ? $empid_to_save : $tempObj->empid_zs;
					}

					// Query all shifts for this employee to help debug
					// dataval is stored as DATE, so we need to convert it properly
					$dataval_date = is_numeric($tempObj->dataval) ? date('Y-m-d', $tempObj->dataval) : date('Y-m-d', strtotime($tempObj->dataval));
					$entityFilter = isset($tempObj->entity) ? (int)$tempObj->entity : 0;

					$all_shifts_sql = "SELECT rowid, datahora_in, datahora_out, duration, dataval, empid_zs, entity FROM " . MAIN_DB_PREFIX . "dolizsynch_zspunchclock";
					$all_shifts_sql .= " WHERE empid_zs = " . (int)$tempObj->empid_zs;
					$all_shifts_sql .= " AND DATE(dataval) = '" . $db->escape($dataval_date) . "'";
					if ($entityFilter > 0) {
						$all_shifts_sql .= " AND entity = " . $entityFilter;
					}
					$all_shifts_sql .= " ORDER BY datahora_in";
					$all_shifts_result = $db->query($all_shifts_sql);
					$all_shifts = array();
					if ($all_shifts_result) {
						while ($shift_obj = $db->fetch_object($all_shifts_result)) {
							$in_time = !empty($shift_obj->datahora_in) ? date('H:i', strtotime($shift_obj->datahora_in)) : 'NULL';
							$out_time = !empty($shift_obj->datahora_out) ? date('H:i', strtotime($shift_obj->datahora_out)) : 'NULL';
							$all_shifts[] = array(
								'rowid' => $shift_obj->rowid,
								'empid_zs' => $shift_obj->empid_zs,
								'entity' => $shift_obj->entity,
								'in' => $shift_obj->datahora_in,
								'in_time' => $in_time,
								'out' => $shift_obj->datahora_out,
								'out_time' => $out_time,
								'duration' => $shift_obj->duration,
								'dataval' => $shift_obj->dataval,
								'is_current' => ($shift_obj->rowid == $objecttosave->id) ? 'YES' : 'no'
							);
						}
					}


					// Debug info
					$debug_info = array(
						'field_being_updated' => $field,
						'value_being_set' => $value,
						'current_db_in' => $tempObj->datahora_in,
						'current_db_out' => $tempObj->datahora_out,
						'current_db_empid' => $tempObj->empid_zs,
						'current_db_entity' => isset($tempObj->entity) ? $tempObj->entity : null,
						'check_in' => $check_in,
						'check_out' => $check_out,
						'check_empid' => $check_empid,
						'check_entity' => $entityFilter > 0 ? $entityFilter : null,
						'rowid' => $objecttosave->id,
						'all_shifts_for_employee' => $all_shifts
					);

					if (!empty($check_empid) && !empty($check_in) && !empty($check_out)) {
						// Convert to timestamps for overlap check
						$in_ts = is_numeric($check_in) ? $check_in : strtotime($check_in);
						$out_ts = is_numeric($check_out) ? $check_out : strtotime($check_out);

						$debug_info['in_timestamp'] = $in_ts;
						$debug_info['out_timestamp'] = $out_ts;
						$debug_info['in_formatted'] = date('Y-m-d H:i:s', $in_ts);
						$debug_info['out_formatted'] = date('Y-m-d H:i:s', $out_ts);


						$overlapCheck = checkShiftOverlap($db, $check_empid, $in_ts, $out_ts, $objecttosave->id, $entityFilter > 0 ? $entityFilter : null);
						if ($overlapCheck['overlap']) {
							dol_syslog("save_inline: OVERLAP DETECTED: " . $overlapCheck['message'], LOG_WARNING);
							echo json_encode(array(
								'success' => false,
								'error' => $overlapCheck['message'],
								'debug' => $debug_info
							));
							exit;
						}
					} else {
					}

					// Mark as edited/manual entry (not imported from ZS BMS)
					$objecttosave->real_entry = 0;

					// Record who modified it
					$objecttosave->fk_user_modif = $user->id;

					// fk_user was already handled above with empid_zs

					// Force tms update by using direct SQL to ensure timestamp is refreshed
					// For fk_user updates, only touch user-related fields to avoid writing empty date/time fields.
					$updateFields = array();
					if ($field == 'fk_user') {
						$updateFields[] = "fk_user = " . intval($objecttosave->fk_user);
						$updateFields[] = "empid_zs = " . intval($objecttosave->empid_zs);
						if (!is_null($objecttosave->real_entry)) {
							$updateFields[] = "real_entry = " . intval($objecttosave->real_entry);
						}
						$updateFields[] = "fk_user_modif = " . intval($user->id);
					} else {
						if (!is_null($objecttosave->datahora_in) && $objecttosave->datahora_in !== '') {
							$updateFields[] = "datahora_in = '" . $db->escape(is_numeric($objecttosave->datahora_in) ? date('Y-m-d H:i:s', $objecttosave->datahora_in) : $objecttosave->datahora_in) . "'";
						}
						if (!is_null($objecttosave->datahora_out) && $objecttosave->datahora_out !== '') {
							$updateFields[] = "datahora_out = '" . $db->escape(is_numeric($objecttosave->datahora_out) ? date('Y-m-d H:i:s', $objecttosave->datahora_out) : $objecttosave->datahora_out) . "'";
						}
						if (!is_null($objecttosave->duration)) {
							$updateFields[] = "duration = " . intval($objecttosave->duration);
						}
						if (!is_null($objecttosave->fk_user)) {
							$updateFields[] = "fk_user = " . intval($objecttosave->fk_user);
						}
						if (!is_null($objecttosave->fk_task)) {
							$updateFields[] = "fk_task = " . intval($objecttosave->fk_task);
						}
						if (!is_null($objecttosave->real_entry)) {
							$updateFields[] = "real_entry = " . intval($objecttosave->real_entry);
						}
						$updateFields[] = "fk_user_modif = " . intval($user->id);
					}

					$sql = "UPDATE " . MAIN_DB_PREFIX . "dolizsynch_zspunchclock";
					$sql .= " SET " . implode(", ", $updateFields);
					$sql .= " WHERE rowid = " . intval($objecttosave->id);
					$dbres = $db->query($sql);
					if (!$dbres) {
						$objecttosave->error = $db->lasterror();
					}
					$result = $dbres ? 1 : -1;

					// After updating the punch clock record, handle TimeSpent entry
					if ($result > 0 && !empty($objecttosave->fk_task)) {
						require_once DOL_DOCUMENT_ROOT . '/projet/class/task.class.php';
						require_once DOL_DOCUMENT_ROOT . '/core/class/timespent.class.php';

						// If fk_user was just assigned and no TimeSpent exists yet, create it
						if ($field == 'fk_user' && !empty($objecttosave->fk_user) && empty($objecttosave->fk_timespent)) {
							$timeSpentResult = $objecttosave->registerTimeSpent($objecttosave->fk_task, $objecttosave);
							if ($timeSpentResult > 0) {
								// Note: registerTimeSpent already adds the user as a task contact
							} else {
								dol_syslog("Failed to create TimeSpent: " . $objecttosave->error, LOG_WARNING);
							}
						}
						// If fk_user was changed and TimeSpent already exists, update it and add user as task contact
						elseif ($field == 'fk_user' && !empty($objecttosave->fk_user) && !empty($objecttosave->fk_timespent)) {
							// Update the TimeSpent user
							$timeSpent = new TimeSpent($db);
							$fetchResult = $timeSpent->fetch($objecttosave->fk_timespent);
							if ($fetchResult > 0) {
								$timeSpent->fk_user = $objecttosave->fk_user;
								$updateResult = $timeSpent->update($user);
								if ($updateResult > 0) {
								} else {
									dol_syslog("Failed to update TimeSpent user: " . $timeSpent->error, LOG_WARNING);
								}
							}

							// Add user as task contact
							$task = new Task($db);
							if ($task->fetch($objecttosave->fk_task) > 0) {
								$task->add_contact($objecttosave->fk_user, 'TASKCONTRIBUTOR', 'internal');
							}
						}
						// If TimeSpent already exists, update it
						elseif (!empty($objecttosave->fk_timespent)) {
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
						} elseif ($field == 'dataval') {
							// Return in YYYY-MM-DD format for date input
							$formatted_value = date('Y-m-d', strtotime($value));
						} elseif ($field == 'duration') {
							// Return in HH:MM format for time input
							$hours = floor($duration_seconds / 3600);
							$minutes = floor(($duration_seconds % 3600) / 60);
							$formatted_value = sprintf('%02d:%02d', $hours, $minutes);
						} elseif ($field == 'fk_user') {
							// For fk_user, return the empid_zs to update the display
							$formatted_value = htmlspecialchars($value);
						} else {
							$formatted_value = htmlspecialchars($value);
						}

						$response = array('success' => true, 'formatted_value' => $formatted_value);

						// If fk_user was changed, also return the new empid_zs
						if ($field == 'fk_user' && isset($empid_zs)) {
							$response['empid_zs'] = $empid_zs;
						}


						// Add debug info if it exists
						if (isset($debug_info)) {
							$response['debug'] = $debug_info;
						}

						// For duration changes, also return the updated punch out time
						if ($field == 'duration') {
							$response['datahora_out'] = date('H:i', strtotime($objecttosave->datahora_out));
						}

						// For datetime or date changes, calculate and return updated duration
						if (in_array($field, array('datahora_in', 'datahora_out', 'dataval'))) {
							// Convert timestamps to datetime strings for calculation
							$datahora_in_str = is_numeric($objecttosave->datahora_in) ?
								date('Y-m-d H:i:s', $objecttosave->datahora_in) : $objecttosave->datahora_in;
							$datahora_out_str = is_numeric($objecttosave->datahora_out) ?
								date('Y-m-d H:i:s', $objecttosave->datahora_out) : $objecttosave->datahora_out;

							// Calculate duration using the datetime strings
							$duration_result = calculateDuration($datahora_in_str, $datahora_out_str);
							$new_duration_display = $duration_result['display'];

							// Update the duration field in the database if calculation was successful
							if (!$duration_result['error']) {
								$objecttosave->duration = $duration_result['seconds'];
								$objecttosave->fk_user_modif = $user->id; // Record who modified it

								// Force tms update by using direct SQL to ensure timestamp is refreshed
								$sql = "UPDATE " . MAIN_DB_PREFIX . "dolizsynch_zspunchclock";
								$sql .= " SET duration = " . intval($objecttosave->duration);
								$sql .= ", fk_user_modif = " . intval($user->id);
								$sql .= " WHERE rowid = " . intval($objecttosave->id);
								$db->query($sql); // Save the updated duration
							}

							$response['duration'] = $new_duration_display;
						}

						echo json_encode($response);
					} else {
						$error_msg = $objecttosave->error;
						if (empty($error_msg)) {
							$error_msg = $db->lasterror();
						}
						if (empty($error_msg)) {
							$error_msg = 'Failed to update record';
						}
						echo json_encode(array('success' => false, 'error' => $error_msg));
					}
				} else {
					echo json_encode(array('success' => false, 'error' => 'Invalid field'));
				}
			} else {
				echo json_encode(array('success' => false, 'error' => 'Record not found'));
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

// Handle AJAX create new record request for empty cells
if ($action == 'create_punch' && GETPOST('ajax', 'alpha')) {
	// Clean any output buffers
	while (ob_get_level()) {
		ob_end_clean();
	}

	header('Content-Type: application/json');
	header('Cache-Control: no-cache, must-revalidate');

	if ($user->rights->dolizsynch->zspunchclock->write) {
		$empid_zs = GETPOST('empid_zs', 'alphanohtml');
		$dataval = GETPOST('dataval', 'alphanohtml');
		$datahora_in = GETPOST('datahora_in', 'alphanohtml');
		$datahora_out = GETPOST('datahora_out', 'alphanohtml');

		if ($empid_zs && $dataval && $datahora_in) {
			$newobject = new ZSPunchClock($db);

			// Set values for new paired record
			$newobject->empid_zs = $empid_zs;
			$newobject->dataval = $dataval;
			$newobject->datahora_in = $datahora_in;
			$newobject->datahora_out = $datahora_out; // Can be empty for incomplete pairs
			$newobject->entity = $conf->entity;
			$newobject->status = 1;
			$newobject->loja_zs = !empty($conf->global->ZS_API_STORE) ? $conf->global->ZS_API_STORE : 0;

			// Calculate duration if both times are provided
			if (!empty($datahora_in) && !empty($datahora_out)) {
				$in_time = strtotime($datahora_in);
				$out_time = strtotime($datahora_out);
				if ($out_time > $in_time) {
					$newobject->duration = $out_time - $in_time;
				}
			} else {
				$newobject->duration = 0;
			}

			// Record who created it
			$newobject->fk_user_modif = $user->id;

			$result = $newobject->create($user);

			if ($result > 0) {
				echo json_encode(array(
					'success' => true,
					'id' => $newobject->id,
					'message' => 'Record created successfully'
				));
			} else {
				$error_msg = $newobject->error;
				if (empty($error_msg) && isset($newobject->errors) && count($newobject->errors) > 0) {
					$error_msg = implode(', ', $newobject->errors);
				}
				if (empty($error_msg)) {
					$error_msg = 'Failed to create record (code: ' . $result . ')';
				}
				echo json_encode(array('success' => false, 'error' => $error_msg));
			}
		} else {
			echo json_encode(array('success' => false, 'error' => 'Missing required parameters'));
		}
	} else {
		echo json_encode(array('success' => false, 'error' => 'No permission to create records'));
	}
	exit;
}

// Handle AJAX delete request
if ($action == 'delete_inline' && GETPOST('ajax', 'alpha')) {
	// Clean any output buffers
	while (ob_get_level()) {
		ob_end_clean();
	}

	header('Content-Type: application/json');
	header('Cache-Control: no-cache, must-revalidate');

	$id = GETPOST('id', 'int');

	if ($id && $user->rights->dolizsynch->zspunchclock->write) {
		$pairdobject = new ZSPunchClock($db);
		$result = $pairdobject->fetch($id);

		if ($result > 0) {
			$db->begin();

			try {
				// Step 1: Delete the project timespent entry if it exists
				if (!empty($pairdobject->fk_timespent) && !empty($pairdobject->fk_task)) {
					require_once DOL_DOCUMENT_ROOT . '/core/class/timespent.class.php';
					$timeSpent = new TimeSpent($db);
					$fetchResult = $timeSpent->fetch($pairdobject->fk_timespent);
					if ($fetchResult > 0) {
						$delresult = $timeSpent->delete($user);
						if ($delresult < 0) {
							dol_syslog("Failed to delete time entry: " . $timeSpent->error, LOG_ERR);
							throw new Exception("Failed to delete TimeSpent: " . $timeSpent->error);
						} else {
						}
					}
				}

				// Step 2: Delete the paired record from llx_dolizsynch_zspunchclock
				$deleteresult = $pairdobject->delete($user);
				if ($deleteresult < 0) {
					throw new Exception($pairdobject->error ?: 'Failed to delete paired record');
				}

				$db->commit();
				echo json_encode(array('success' => true, 'message' => 'Registo eliminado com sucesso'));
			} catch (Exception $e) {
				$db->rollback();
				echo json_encode(array('success' => false, 'error' => $e->getMessage()));
			}
		} else {
			echo json_encode(array('success' => false, 'error' => 'Record not found'));
		}
	} else {
		echo json_encode(array('success' => false, 'error' => 'Missing parameters or no permission'));
	}
	exit;
}

// Handle AJAX delete entire row request
if ($action == 'delete_entire_row' && GETPOST('ajax', 'alpha')) {
	// Clean any output buffers
	while (ob_get_level()) {
		ob_end_clean();
	}

	header('Content-Type: application/json');
	header('Cache-Control: no-cache, must-revalidate');

	try {
		// First, let's ensure we can reach this point
		error_log("DELETE HANDLER REACHED");

		$in_id = GETPOST('in_id', 'int');
		$out_id = GETPOST('out_id', 'int');

		// Log the delete request
		error_log("DELETE REQUEST: action=delete_entire_row, in_id=" . $in_id . ", out_id=" . $out_id);

		// For paired table, we only need one ID, prefer in_id but use out_id if in_id is not available
		$row_id = $in_id ?: $out_id;

		if ($row_id && $user->rights->dolizsynch->zspunchclock->write) {
			$db->begin();

			try {
				$pairdobject = new ZSPunchClock($db);
				$result = $pairdobject->fetch($row_id);

				if ($result > 0) {

					// Delete the associated time entry (not the entire task) if it exists
					if (!empty($pairdobject->fk_timespent) && !empty($pairdobject->fk_task)) {
						require_once DOL_DOCUMENT_ROOT . '/core/class/timespent.class.php';
						$timeSpent = new TimeSpent($db);
						$fetchResult = $timeSpent->fetch($pairdobject->fk_timespent);
						if ($fetchResult > 0) {
							$delresult = $timeSpent->delete($user);
							if ($delresult < 0) {
								error_log("DELETE: Failed to delete time entry: " . $timeSpent->error);
								dol_syslog("DELETE: Failed to delete time entry: " . $timeSpent->error, LOG_ERR);
								throw new Exception("Failed to delete TimeSpent: " . $timeSpent->error);
							} else {
								error_log("DELETE: Successfully deleted time entry (rowid=" . $pairdobject->fk_timespent . ")");
							}
						}
					}

					// Delete the paired record

					// Debug user object before deletion
					error_log("DELETE: User object check before main deletion - Type: " . gettype($user) . ", Class: " . (is_object($user) ? get_class($user) : 'not_object') . ", ID: " . (is_object($user) && isset($user->id) ? $user->id : 'no_id'));

					if (!is_object($user) || get_class($user) !== 'User') {
						throw new Exception('Invalid user object for deletion - Type: ' . gettype($user) . ', Class: ' . (is_object($user) ? get_class($user) : 'not_object'));
					}

					$deleteresult = $pairdobject->delete($user);

					if ($deleteresult < 0) {
						$errorMsg = $pairdobject->error ?: 'Unknown error during record deletion';
						if (isset($pairdobject->errors) && is_array($pairdobject->errors) && count($pairdobject->errors) > 0) {
							$errorMsg .= ': ' . implode(', ', $pairdobject->errors);
						}
						dol_syslog("DELETE ERROR: " . $errorMsg, LOG_ERR);
						throw new Exception('Failed to delete paired record: ' . $errorMsg);
					}

					// Update related synch records to mark as deleted (synch=3)
					if (!empty($pairdobject->fk_pairid)) {
						$sql_update_synch = "UPDATE " . MAIN_DB_PREFIX . "dolizsynch_zspunchclock_synch SET synch = 3 WHERE pairid = " . ((int) $pairdobject->fk_pairid);
						$resql_update = $db->query($sql_update_synch);
						if (!$resql_update) {
							error_log("DELETE: Failed to update synch table: " . $db->lasterror());
							dol_syslog("DELETE: Failed to update synch table: " . $db->lasterror(), LOG_WARNING);
						} else {
							$affected_rows = $db->affected_rows($resql_update);
							error_log("DELETE: Updated " . $affected_rows . " synch records to synch=3 for pairid=" . $pairdobject->fk_pairid);
						}
					} else {
						error_log("DELETE: No fk_pairid found, skipping synch table update");
					}

					$db->commit();
					echo json_encode(array('success' => true, 'message' => 'Registo eliminado com sucesso'));
				} else {
					dol_syslog("DELETE ERROR: Record not found for ID " . $row_id, LOG_ERR);
					throw new Exception('Record not found');
				}
			} catch (Exception $e) {
				$db->rollback();
				dol_syslog("DELETE EXCEPTION: " . $e->getMessage(), LOG_ERR);
				echo json_encode(array('success' => false, 'error' => $e->getMessage()));
			}
		} else {
			dol_syslog("DELETE ERROR: Missing row_id or insufficient permissions. row_id=" . $row_id . ", has_write_rights=" . ($user->rights->dolizsynch->zspunchclock->write ? 'yes' : 'no'), LOG_ERR);
			echo json_encode(array('success' => false, 'error' => 'Missing parameters or no permission'));
		}
	} catch (Exception $outer_e) {
		dol_syslog("DELETE OUTER EXCEPTION: " . $outer_e->getMessage(), LOG_ERR);
		echo json_encode(array('success' => false, 'error' => 'Unexpected error: ' . $outer_e->getMessage()));
	} catch (Error $fatal_error) {
		dol_syslog("DELETE FATAL ERROR: " . $fatal_error->getMessage(), LOG_ERR);
		echo json_encode(array('success' => false, 'error' => 'Fatal error: ' . $fatal_error->getMessage()));
	}

	exit;
}

// Handle AJAX create new record request
if ($action == 'create_inline' && GETPOST('ajax', 'alpha')) {
	// Clean any output buffers
	while (ob_get_level()) {
		ob_end_clean();
	}

	header('Content-Type: application/json');
	header('Cache-Control: no-cache, must-revalidate');

	if ($user->rights->dolizsynch->zspunchclock->write) {
		// Get the date from the AJAX request
		$dateForNewRecord = GETPOST('dataval', 'alpha');
		if (empty($dateForNewRecord)) {
			echo json_encode(array('success' => false, 'error' => 'Date is required'));
			exit;
		}

		// Get the rowid of the clicked row (after which we want to insert)
		$clicked_rowid = GETPOST('clicked_rowid', 'int');


		// Get user list for dropdown - filter by current entity
		$users_list = array();
		$users_sql = dzs_punchclock_build_employee_lookup_sql((int) $conf->entity, $shiftEmployeeGroupIds);
		$users_result = $db->query($users_sql);
		if ($users_result) {
			while ($user_obj = $db->fetch_object($users_result)) {
				$users_list[] = array(
					'id' => $user_obj->rowid,
					'name' => $user_obj->firstname . ' ' . $user_obj->lastname,
					'empid_zs' => $user_obj->zs_employee_id
				);
			}
			$db->free($users_result);
		}

		// Calculate position for new row
		$newPosition = 0;
		if ($clicked_rowid > 0) {
			// Get the position and employee of the clicked row
			$pos_sql = "SELECT position, empid_zs FROM " . MAIN_DB_PREFIX . "dolizsynch_zspunchclock WHERE rowid = " . (int)$clicked_rowid;
			$pos_result = $db->query($pos_sql);
			if ($pos_result && $db->num_rows($pos_result) > 0) {
				$pos_obj = $db->fetch_object($pos_result);
				$clicked_position = (int)$pos_obj->position;
				$clicked_empid = (int)$pos_obj->empid_zs;

				// Get the position of the next row for the same employee on the same date
				$next_sql = "SELECT MIN(position) as next_pos FROM " . MAIN_DB_PREFIX . "dolizsynch_zspunchclock ";
				$next_sql .= "WHERE dataval = '" . $db->escape($dateForNewRecord) . "' ";
				$next_sql .= "AND empid_zs = " . (int)$clicked_empid . " ";
				$next_sql .= "AND position > " . $clicked_position;
				$next_result = $db->query($next_sql);

				if ($next_result && $db->num_rows($next_result) > 0) {
					$next_obj = $db->fetch_object($next_result);
					$next_position = $next_obj->next_pos;

					if (!empty($next_position)) {
						// Check if there's room between positions
						if ($next_position - $clicked_position > 1) {
							// There's room, insert in the middle
							$newPosition = $clicked_position + 1;
						} else {
							// No room, need to renumber all positions after clicked row for this employee
							$renumber_sql = "UPDATE " . MAIN_DB_PREFIX . "dolizsynch_zspunchclock ";
							$renumber_sql .= "SET position = position + 1 ";
							$renumber_sql .= "WHERE dataval = '" . $db->escape($dateForNewRecord) . "' ";
							$renumber_sql .= "AND empid_zs = " . (int)$clicked_empid . " ";
							$renumber_sql .= "AND position > " . $clicked_position;
							$db->query($renumber_sql);
							$newPosition = $clicked_position + 1;
						}
					} else {
						// No next row, insert after clicked row
						$newPosition = $clicked_position + 1;
					}
				} else {
					// No next row, insert after clicked row
					$newPosition = $clicked_position + 1;
				}
			}
		} else {
			// No clicked row specified, add to the end
			$max_sql = "SELECT MAX(position) as max_pos FROM " . MAIN_DB_PREFIX . "dolizsynch_zspunchclock ";
			$max_sql .= "WHERE dataval = '" . $db->escape($dateForNewRecord) . "'";
			$max_result = $db->query($max_sql);
			if ($max_result && $db->num_rows($max_result) > 0) {
				$max_obj = $db->fetch_object($max_result);
				$newPosition = (int)$max_obj->max_pos + 1;
			}
		}

		// Create a new paired punch clock record
		$newobject = new ZSPunchClock($db);

		// Set default values for new paired record
		$newobject->entity = $conf->entity;
		$newobject->loja_zs = !empty($conf->global->ZS_API_STORE) ? $conf->global->ZS_API_STORE : 1;
		$newobject->dataval = $dateForNewRecord; // Use the date passed from JavaScript

		// Always start with empty employee - user will select it manually
		// Use 0 instead of null to avoid database constraints
		$newobject->empid_zs = 0;
		$newobject->fk_user = 0;

		// Leave times as NULL - user will set them manually
		$newobject->datahora_in = null;
		$newobject->datahora_out = null;
		$newobject->sentido_in = 1;
		$newobject->sentido_out = 2;

		// Duration starts at 0
		$newobject->duration = 0;

		$newobject->status = 1;
		$newobject->synch = 0; // Not yet synched to tasks
		$newobject->real_entry = 0; // Manual entry, not imported from ZS BMS
		$newobject->position = $newPosition;

		// Find or create the corresponding task for this date
		require_once DOL_DOCUMENT_ROOT . '/projet/class/task.class.php';
		$entityLabel = $newobject->fetchEntityLabel();
		$taskRef = $entityLabel . "_Ponto_" . $dateForNewRecord;
		$task = new Task($db);
		$existingTaskId = $task->fetch(0, $taskRef);
		if ($existingTaskId > 0) {
			$newobject->fk_task = $task->id;
		} else {
			// Create task for this date
			$projectId = $newobject->getOrCreateProject($newobject->loja_zs);
			if ($projectId > 0) {
				$task->fk_project = $projectId;
				$task->ref = $taskRef;
				$task->label = "Ponto " . $dateForNewRecord;
				$task->date_start = mktime(0, 0, 0, $month, $day, $year);
				$task->date_end = mktime(23, 59, 59, $month, $day, $year);
				$taskId = $task->create($user);
				if ($taskId > 0) {
					$newobject->fk_task = $taskId;
				} else {
					dol_syslog("Failed to create task: " . $task->error, LOG_ERR);
				}
			}
		}

		// Check for shift overlap before creating (only if employee is assigned)
		if (!empty($newobject->empid_zs) && $newobject->empid_zs > 0) {
			$entityForNew = isset($newobject->entity) ? (int)$newobject->entity : (int) $conf->entity;
			$overlapCheck = checkShiftOverlap($db, $newobject->empid_zs, $newobject->datahora_in, $newobject->datahora_out, 0, $entityForNew);
			if ($overlapCheck['overlap']) {
				echo json_encode(array('success' => false, 'error' => $overlapCheck['message']));
				exit;
			}
		}

		// Record who created it
		$newobject->fk_user_modif = $user->id;

		$result = $newobject->create($user);

		if ($result > 0) {
			// Register the time spent in the task (only if user is assigned)
			if (!empty($newobject->fk_task) && !empty($newobject->fk_user)) {
				$timeSpentResult = $newobject->registerTimeSpent($newobject->fk_task, $newobject);
				if ($timeSpentResult > 0) {
				} else {
					dol_syslog("Failed to register TimeSpent: " . $newobject->error, LOG_WARNING);
				}
			} else {
			}

			echo json_encode(array(
				'success' => true,
				'id' => $newobject->id,
				'users_list' => $users_list,
				'empid_zs' => $newobject->empid_zs,
				'dataval' => date('d/m/Y', strtotime($newobject->dataval)),
				'datahora_in' => !empty($newobject->datahora_in) ? date('H:i', strtotime($newobject->datahora_in)) : '',
				'datahora_out' => !empty($newobject->datahora_out) ? date('H:i', strtotime($newobject->datahora_out)) : '',
				'duration' => '00:00',
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
		echo json_encode(array('success' => false, 'error' => 'No permission to create records'));
	}
	exit;
}

$title = $langs->trans('ZSPunchClockList');
llxHeader('', $title);

// Add CSRF token for AJAX requests
print '<script type="text/javascript">var CSRF_TOKEN = "' . newToken() . '";</script>';

// Add JavaScript function for creating new rows (must be before the HTML that uses it)
?>
<script type="text/javascript">
	function addNewRowBelow(dateString, clickedRowId) {
		console.log("addNewRowBelow called with date:", dateString, "rowId:", clickedRowId);

		console.log("Sending AJAX request to create new record for date:", dateString);

		$.ajax({
			url: "<?= $_SERVER["PHP_SELF"] ?>",
			type: "POST",
			dataType: "json",
			data: {
				action: "create_inline",
				ajax: "1",
				token: CSRF_TOKEN,
				empid_zs: "",
				dataval: dateString,
				clicked_rowid: clickedRowId
			},
			success: function(response) {
				console.log("AJAX response:", response);
				if (response.success) {
					// Reload page with new row ID and correct search_date to highlight it
					var params = new URLSearchParams(window.location.search);
					params.set("newrow", response.id);
					if (clickedRowId && clickedRowId > 0) {
						params.set("after", clickedRowId);
					} else {
						params.delete("after");
					}
					params.set("search_date", dateString); // Ensure we're viewing the correct date
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

	// Function to delete entire row (both IN and OUT entries)
	function deleteEntireRow(inId, outId, employeeName) {
		console.log("Delete function called with:", {
			inId: inId,
			outId: outId,
			employeeName: employeeName
		});

		if (!confirm("Tem certeza que deseja eliminar todo o registo de " + employeeName + "?")) {
			console.log("User cancelled deletion");
			return;
		}

		console.log("Sending delete request...");

		// Send both IDs in a single request - let the backend handle all deletions
		$.ajax({
			url: "<?= $_SERVER["PHP_SELF"] ?>",
			type: "POST",
			dataType: "json",
			data: {
				action: "delete_entire_row",
				ajax: "1",
				token: CSRF_TOKEN,
				in_id: (inId !== null && inId !== "null") ? inId : null,
				out_id: (outId !== null && outId !== "null") ? outId : null
			},
			beforeSend: function() {
				console.log("AJAX request starting...");
			},
			success: function(response) {
				console.log("Delete response received:", response);
				if (response.success) {
					console.log("Deletion successful, reloading page...");
					// Deletion successful
					window.location.reload();
				} else {
					console.error("Deletion failed:", response.error);
					alert("Erro: " + (response.error || "Erro desconhecido"));
				}
			},
			error: function(xhr, status, error) {
				console.error("AJAX error:", {
					xhr: xhr,
					status: status,
					error: error
				});
				console.error("Response text:", xhr.responseText);
				alert("Erro de rede: " + error);
			}
		});
	}
</script>
<?php


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
    padding-right: 8px; /* Reduce right padding to move clock icon closer */
}

.duration-field {
    text-align: center;
    font-variant-numeric: tabular-nums;
}

.duration-cell {
    text-align: center;
}

.employee-total-cell {
    text-align: center;
    font-variant-numeric: tabular-nums;
}

.duration-cell .duration-field {
    display: inline-block;
    width: 80px;
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
    justify-content: center;
    gap: 6px;
    min-width: 80px;
    padding: 4px 0;
    font-variant-numeric: tabular-nums;
}

.duration-total-display.no-icon {
    gap: 0;
}

.duration-icon {
    font-size: 13px;
    color: #6c757d;
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
    margin-left: -8px; /* Move the clock icon closer to the time text */
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

/* Table layout and responsiveness fixes */
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

/* Specific column widths for better layout */
.tagtable.liste th:first-child,
.tagtable.liste td:first-child {
    min-width: 150px; /* Name column */
}

.tagtable.liste th:nth-child(2),
.tagtable.liste td:nth-child(2) {
    width: 120px; /* Employee ID */
}

.tagtable.liste th:nth-child(3),
.tagtable.liste td:nth-child(3) {
    width: 130px; /* Date column */
}

.tagtable.liste th:nth-child(4),
.tagtable.liste td:nth-child(4),
.tagtable.liste th:nth-child(5),
.tagtable.liste td:nth-child(5) {
    width: 150px; /* IN/OUT time columns */
}

.tagtable.liste th:nth-child(6),
.tagtable.liste td:nth-child(6) {
    width: 80px; /* Duration column */
    text-align: center;
}

.tagtable.liste th:nth-child(7),
.tagtable.liste td:nth-child(7) {
    width: 100px; /* Actions column */
    text-align: center;
}

/* Navigation improvements */
.tabsAction {
    display: flex;
    justify-content: center;
    align-items: center;
    flex-wrap: wrap;
    gap: 10px;
    margin: 10px 0;
    padding: 10px;
    background: #f8f9fa;
    border: 1px solid #dee2e6;
    border-radius: 4px;
}

/* Responsive adjustments */
@media (max-width: 768px) {
    .tagtable.liste th,
    .tagtable.liste td {
        padding: 2px 4px;
        font-size: 12px;
    }

    .tabsAction {
        flex-direction: column;
    }

    .butAction {
        width: 100%;
        text-align: center;
    }
}
</style>';

$param = '';
if (!empty($mode)) {
	$param .= '&mode=' . urlencode($mode);
}
if (!empty($contextpage) && $contextpage != $_SERVER["PHP_SELF"]) {
	$param .= '&contextpage=' . urlencode($contextpage);
}
if ($limit > 0 && $limit != $conf->liste_limit) {
	$param .= '&limit=' . urlencode($limit);
}
if (!empty($search_fullname)) {
	$param .= '&search_fullname=' . urlencode($search_fullname);
}
if (!empty($search_empid_zs)) {
	$param .= '&search_empid_zs=' . urlencode($search_empid_zs);
}
if (!empty($search_sentido)) {
	$param .= '&search_sentido=' . urlencode($search_sentido);
}
if (!empty($search_date)) {
	$param .= '&search_date=' . urlencode($search_date);
}
if ($optioncss != '') {
	$param .= '&optioncss=' . urlencode($optioncss);
}
if (!empty($edit_mode)) {
	$param .= '&edit_mode=' . urlencode($edit_mode);
}

// Prepare title and buttons
$picto = 'clock';

// No buttons in top right corner
$newcardbutton = '';

// Mass action button (empty for now, can be added later if needed)
$massactionbutton = '';

print '<form method="POST" id="searchFormList" action="' . $_SERVER["PHP_SELF"] . '">' . "\n";
if ($optioncss != '') {
	print '<input type="hidden" name="optioncss" value="' . $optioncss . '">';
}
print '<input type="hidden" name="token" value="' . newToken() . '">';
print '<input type="hidden" name="formfilteraction" id="formfilteraction" value="list">';
print '<input type="hidden" name="action" value="list">';
print '<input type="hidden" name="sortfield" value="' . $sortfield . '">';
print '<input type="hidden" name="sortorder" value="' . $sortorder . '">';
print '<input type="hidden" name="page" value="' . $page . '">';
print '<input type="hidden" name="contextpage" value="' . $contextpage . '">';
print '<input type="hidden" name="page_y" value="">';
print '<input type="hidden" name="mode" value="' . $mode . '">';
print '<input type="hidden" name="edit_mode" value="' . $edit_mode . '">';
print '<input type="hidden" name="search_date" value="' . $search_date . '">';
print '<input type="hidden" name="search_fullname" value="' . $search_fullname . '">';
print '<input type="hidden" name="search_empid_zs" value="' . $search_empid_zs . '">';

print_barre_liste($title, $page, $_SERVER["PHP_SELF"], $param, $sortfield, $sortorder, $massactionbutton, $num, $nbtotalofrecords, 'object_' . $picto, 0, $newcardbutton, '', $limit, 0, 0, 1);

// Continue with table (form already started above)
print '<div class="div-table-responsive">';

// Add day navigation inside the table container for proper alignment
print '<div style="text-align: center; padding: 10px; background-color: #f8f9fa; border: 1px solid #dee2e6; border-radius: 4px; margin: 0 0 10px 0;">';

// Previous day button - always active for historical navigation
$prevDate = date('Y-m-d', strtotime($selectedDate . ' -1 day'));
print '<a href="' . $_SERVER["PHP_SELF"] . '?action=prev_day&search_date=' . urlencode($selectedDate) . $param . '" style="text-decoration: none; border: none; background: none; padding: 8px 15px; margin: 0 5px; font-size: 16px; color: #666;">';
print '<i class="fa fa-chevron-left"></i>';
print '</a>';

// Current date display
print ' <span style="margin: 0 20px; font-weight: bold; font-size: 18px;">' . dol_print_date($db->jdate($selectedDate), 'day') . '</span> ';

// Next day button - will redirect to synch list if going to today or future
$nextDate = date('Y-m-d', strtotime($selectedDate . ' +1 day'));
print '<a href="' . $_SERVER["PHP_SELF"] . '?action=next_day&search_date=' . urlencode($selectedDate) . $param . '" style="text-decoration: none; border: none; background: none; padding: 8px 15px; margin: 0 5px; font-size: 16px; color: #666;">';
print '<i class="fa fa-chevron-right"></i>';
print '</a>';
print '</div>';

print '<table class="tagtable nobottomiftotal liste">' . "\n";

// Header with column names
print '<tr class="liste_titre" style="background-color: #f8f9fa;">';
print '<th style="border-top-left-radius: 4px;">' . $langs->trans("zspunchclock_fullname") . '</th>';
print '<th style="text-align: right;">' . $langs->trans("zspunchclock_employee_id") . '</th>';
print '<th style="text-align: center; width: 50px;">ZS</th>';
print '<th style="text-align: right;">' . $langs->trans("zspunchclock_dataval") . '</th>';
print '<th style="text-align: right;">' . $langs->trans("CheckInTime") . '</th>';
print '<th style="text-align: right;">' . $langs->trans("CheckOutTime") . '</th>';
print '<th style="text-align: right;">' . $langs->trans("ShiftDuration") . '</th>';
print '<th style="text-align: center;">' . $langs->trans("TotalDay") . '</th>';
print '<th style="border-top-right-radius: 4px; text-align: right;">' . $langs->trans("zspunchclock_actions") . '</th>';
print '</tr>' . "\n";

// Header with search fields
print '<tr class="liste_titre" style="background-color: #f8f9fa;">';
print '<td class="liste_titre"><input type="text" class="flat" name="search_fullname" value="' . dol_escape_htmltag($search_fullname) . '"></td>';
print '<td class="liste_titre"><input type="text" class="flat" name="search_empid_zs" value="' . dol_escape_htmltag($search_empid_zs) . '"></td>';
print '<td></td>'; // ZS column - no filter needed
print '<td class="liste_titre"><input type="date" class="flat" name="search_date" value="' . dol_escape_htmltag($search_date) . '" onchange="this.form.submit()"></td>';
print '<td></td>'; // IN time column - no filter needed
print '<td></td>'; // OUT time column - no filter needed
print '<td></td>'; // Duration filter field
print '<td></td>'; // Total Day column - no filter needed
print '<td class="liste_titre" style="text-align: right;">';
print '<button type="submit" class="liste_titre button_search reposition" name="button_search_x" value="x"><span class="fas fa-search"></span></button>';
print '<button type="submit" class="liste_titre button_removefilter reposition" name="button_removefilter_x" value="x"><span class="fas fa-times"></span></button>';
print '</td>';
print '</tr>' . "\n";

// If adding a new line, display the form row here
if ($action == 'addlineform' && $user->rights->dolizsynch->zspunchclock->write) {
	print '<tr>';
	print '<td class="liste_titre">' . $form->select_dolusers($zsEmployeeID, 'zsEmployeeID', 1, '', 0, '', '', 0, 0, 0, '', 0, '', 'widthcentpercentminusx maxwidth300') . '</td>';
	print '<td></td>'; // Employee ID - auto-filled
	print '<td></td>'; // ZS column - empty for new entries (manual entry)
	print '<td>' . dol_print_date(dol_now(), 'day') . '</td>'; // Today's date
	print '<td>';
	// Add form for IN time with sentido=1 option
	print '<div style="display: inline-block; margin-right: 10px;">';
	print $form->selectDate($todayDateStringStart, 'datehora_in', 1, 1, 1, '', 1, 1, 0);
	print '<input type="hidden" name="sentido_in" value="1">';
	print '</div>';
	print '</td>';
	print '<td>';
	// Add form for OUT time with sentido=2 option
	print '<div style="display: inline-block;">';
	print $form->selectDate($todayDateStringStart, 'datehora_out', 1, 1, 1, '', 1, 1, 0);
	print '<input type="hidden" name="sentido_out" value="2">';
	print '</div>';
	print '</td>';
	print '<td></td>'; // Duration field - calculated automatically
	print '<td></td>'; // Total Day column - empty for new entries
	print '<td>';
	print '<input type="hidden" name="dataval" value="' . $todayDateStringStart . '">';
	print '<button type="submit" name="action" value="addline">' . $langs->trans("Guardar") . '</button>&nbsp';
	print '<button type="submit" name="cancel">' . $langs->trans("Cancelar") . '</button>';
	print '</td>';
	print '</tr>' . "\n";
}

// Process paired punch records (no grouping needed since data is already paired)
$punchRecords = array();
$i = 0;
$imaxinloop = ($limit ? min($num, $limit) : $num);
while ($i < $imaxinloop) {
	$obj = $db->fetch_object($resql);

	// Each record in the paired table already contains both IN and OUT
	$record = array(
		'rowid' => $obj->rowid,
		'entity' => (int) $obj->entity,
		'fullname' => $obj->fullname,
		'empid_zs' => $obj->empid_zs,
		'dataval' => $obj->dataval,
		'date_display' => dol_print_date($db->jdate($obj->dataval), 'day'),
		'datahora_in' => $obj->datahora_in,
		'datahora_out' => $obj->datahora_out,
		'duration' => $obj->duration, // Duration is already calculated and stored
		'real_entry' => $obj->real_entry, // ZS BMS indicator
		'modif_login' => $obj->modif_login, // Username of person who modified
		'modif_fullname' => $obj->modif_fullname, // Full name of person who modified
		'has_error' => false
	);


	// Format time displays
	if (!empty($obj->datahora_in)) {
		$record['in_time'] = dol_print_date($db->jdate($obj->datahora_in), 'hour');
		$record['in_datetime'] = $obj->datahora_in;
	} else {
		$record['in_time'] = '';
		$record['in_datetime'] = null;
	}

	if (!empty($obj->datahora_out)) {
		$record['out_time'] = dol_print_date($db->jdate($obj->datahora_out), 'hour');
		$record['out_datetime'] = $obj->datahora_out;
	} else {
		$record['out_time'] = '';
		$record['out_datetime'] = null;
	}

	// Format duration for display using the new calculation function
	$duration_result = calculateDuration($obj->datahora_in, $obj->datahora_out);
	$record['duration_display'] = $duration_result['display'];


	$punchRecords[] = $record;
	$i++;
}

// Display the paired punch records
if (empty($punchRecords)) {
	// Add an empty editable row by default when in edit mode, otherwise show "no records" message
	if ($user->rights->dolizsynch->zspunchclock->write && $edit_mode) {
		// Create a fake empty record to display an editable row
		$punchRecords[] = array(
			'rowid' => 0,  // Special rowid=0 indicates this is a new unsaved record
			'entity' => (int) $conf->entity,
			'fullname' => '',
			'empid_zs' => 0,
			'dataval' => $selectedDate,
			'date_display' => dol_print_date(strtotime($selectedDate), 'day'),
			'datahora_in' => null,
			'datahora_out' => null,
			'duration' => 0,
			'has_error' => false,
			'in_time' => '',
			'out_time' => '',
			'in_datetime' => null,
			'out_datetime' => null,
			'duration_display' => ''
		);
	} else {
		// Show "no records found" message when not in edit mode
		print '<tr><td colspan="7" class="center" style="padding: 20px; font-size: 14px; color: #666;">';
		print '<i class="fa fa-info-circle" style="margin-right: 5px;"></i>';
		print $langs->trans("NoRecordsFound") . ' for ' . dol_print_date(strtotime($selectedDate), 'day');
		print '</td></tr>';
	}
}

// Calculate employee totals and find the row with greatest datahora_in for each employee
$employeeTotals = array();
$employeeMaxDatehoraInRow = array(); // Store the rowid of the record with greatest datahora_in per employee

foreach ($punchRecords as $record) {
	if (!empty($record['empid_zs']) && $record['empid_zs'] > 0) {
		$empid = $record['empid_zs'];

		// Calculate totals
		if (!isset($employeeTotals[$empid])) {
			$employeeTotals[$empid] = 0;
		}
		if (!empty($record['duration'])) {
			$employeeTotals[$empid] += $record['duration'];
		}

		// Track row with greatest (latest) non-NULL datahora_in for this employee
		if (!empty($record['datahora_in']) && $record['datahora_in'] != '0000-00-00 00:00:00') {
			// Update if this is the first record or has a later datahora_in
			if (!isset($employeeMaxDatehoraInRow[$empid])) {
				$employeeMaxDatehoraInRow[$empid] = array('rowid' => $record['rowid'], 'datahora_in' => $record['datahora_in']);
			} else {
				if (strtotime($record['datahora_in']) >= strtotime($employeeMaxDatehoraInRow[$empid]['datahora_in'])) {
					$employeeMaxDatehoraInRow[$empid] = array('rowid' => $record['rowid'], 'datahora_in' => $record['datahora_in']);
				}
			}
		}
	}
}

// Display all records (including the empty one if it was added)
if (!empty($punchRecords)) {
	foreach ($punchRecords as $idx => $record) {
		$rowClass = $record['has_error'] ? ' class="error-cell"' : '';
		$rowEmpId = isset($record['empid_zs']) ? (int)$record['empid_zs'] : 0;
		$rowDurationSeconds = (isset($record['duration']) && $record['duration'] !== '') ? (int)$record['duration'] : 0;

		print '<tr data-rowid="' . $record['rowid'] . '" data-empid="' . $rowEmpId . '" data-duration-seconds="' . $rowDurationSeconds . '"' . $rowClass . '>';

		// Check if we're editing this record
		$editingRecord = ($action == 'editline' && GETPOST('lineid', 'int') == $record['rowid']);

		if ($editingRecord) {
			// Edit mode - show form fields
			print '<td>' . $record['fullname'] . '</td>';
			print '<td style="text-align: right;"><input type="text" name="empid_zs" value="' . $record['empid_zs'] . '"></td>';
			// ZS column in edit mode
			print '<td class="center" style="width:50px;">';
			$is_real_entry = !empty($record['real_entry']) && $record['real_entry'] == 1;
			if ($is_real_entry) {
				print '<span class="badge badge-status4" style="background-color: #28a745;" title="Imported from ZS BMS">ZS</span>';
			} else {
				// Show username of person who modified the record
				if (!empty($record['modif_login'])) {
					print '<span class="badge badge-status2" style="color: #ffc107; font-size: 0.85em;" title="' . dol_escape_htmltag($record['modif_fullname']) . '">' . dol_escape_htmltag($record['modif_login']) . '</span>';
				} else {
					print '<span style="color: #999;">--</span>';
				}
			}
			print '</td>';
			print '<td style="text-align: right;">' . $record['date_display'] . '</td>';
			print '<td style="text-align: right;">' . $form->selectDate($db->jdate($record['datahora_in']), 'timelinein', 1, 1, 1, '', 1, 0) . '</td>';
			print '<td style="text-align: right;">' . $form->selectDate($db->jdate($record['datahora_out']), 'timelineout', 1, 1, 1, '', 1, 0) . '</td>';
			print '<td style="text-align: right;">' . $record['duration_display'] . '</td>';
			print '<td></td>'; // Total Day column - empty in edit mode
			print '<td style="text-align: right;">';

			print '<input type="hidden" name="lineid" value="' . $record['rowid'] . '">';
			print '<input type="hidden" name="action" value="updateline">';
			print '<input type="hidden" name="search_fullname" value="' . dol_escape_htmltag($search_fullname) . '">';
			print '<input type="hidden" name="search_empid_zs" value="' . dol_escape_htmltag($search_empid_zs) . '">';
			print '<button type="submit">' . $langs->trans("Guardar") . '</button>&nbsp';
			print '<button type="submit" name="cancel">' . $langs->trans("Cancelar") . '</button>';
		} else {
			// Normal display mode with inline editing
			print '<td>';
			if ($user->rights->dolizsynch->zspunchclock->write && $edit_mode) {
				// Make employee name editable with dropdown - filter by record's entity
				$recordEntity = !empty($record['entity']) ? (int) $record['entity'] : (int) $conf->entity;
				$users_sql = dzs_punchclock_build_employee_lookup_sql($recordEntity, $shiftEmployeeGroupIds);
				$users_result = $db->query($users_sql);

				print '<select id="employee_field_' . $record['rowid'] . '" name="fk_user_' . $record['rowid'] . '" class="inline-edit-field employee-field" data-field="fk_user" data-id="' . $record['rowid'] . '" style="width: 100%; border: none; background: transparent;">';
				// Add empty option for new records
				$is_empty = (empty($record['empid_zs']) || $record['empid_zs'] == 0);
				print '<option value="0" data-empid="0"' . ($is_empty ? ' selected' : '') . '>-- Selecionar --</option>';
				if ($users_result) {
					while ($user_obj = $db->fetch_object($users_result)) {
						$selected = (!$is_empty && $record['empid_zs'] == $user_obj->zs_employee_id) ? ' selected' : '';
						print '<option value="' . $user_obj->rowid . '" data-empid="' . $user_obj->zs_employee_id . '"' . $selected . '>';
						print htmlspecialchars($user_obj->firstname . ' ' . $user_obj->lastname);
						print '</option>';
					}
					$db->free($users_result);
				}
				print '</select>';
			} else {
				print $record['fullname'];
			}
			print '</td>';
			print '<td style="text-align: right;">';
			// Employee ID - display current value
			print '<span class="empid-display-' . $record['rowid'] . '">' . $record['empid_zs'] . '</span>';
			print '</td>';

			// ZS indicator column
			print '<td class="center" style="width:50px;">';
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

			// Date field as editable
			print '<td style="text-align: right;">';
			// Date field is read-only (non-editable)
			print $record['date_display'];
			print '</td>';

			// IN datetime as editable field
			print '<td style="text-align: right;">';
			if ($user->rights->dolizsynch->zspunchclock->write && $edit_mode) {
				$time_value = isset($record['datahora_in']) ? date('H:i', strtotime($record['datahora_in'])) : '';
				print '<input type="time" class="inline-edit-field time-field" data-field="datahora_in" data-id="' . $record['rowid'] . '" value="' . $time_value . '" title="Hora de entrada (data fixa do dia de referência)">';
			} else {
				// View mode: show only time (HH:MM)
				if (isset($record['datahora_in']) && !empty($record['datahora_in'])) {
					print date('H:i', strtotime($record['datahora_in']));
				} else {
					print '';
				}
			}
			print '</td>';

			// OUT datetime as editable field
			print '<td style="text-align: right;">';
			if ($user->rights->dolizsynch->zspunchclock->write && $edit_mode) {
				$time_value = isset($record['datahora_out']) ? date('H:i', strtotime($record['datahora_out'])) : '';
				print '<input type="time" class="inline-edit-field time-field" data-field="datahora_out" data-id="' . $record['rowid'] . '" value="' . $time_value . '" title="Hora de saída (pode ultrapassar meia-noite)">';
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

			// Duration as editable field
			print '<td class="duration-cell" style="text-align: right;">';
			if ($user->rights->dolizsynch->zspunchclock->write && $edit_mode) {
				// Convert duration to HH:MM format for time input
				$duration_value = '';
				if (!empty($record['duration_display']) && $record['duration_display'] !== 'Error') {
					$duration_value = $record['duration_display']; // Already in HH:MM format
				}
				print '<input type="time" class="inline-edit-field duration-field" data-field="duration" data-id="' . $record['rowid'] . '" data-duration-seconds="' . $rowDurationSeconds . '" value="' . $duration_value . '" title="Editar duração (recalcula hora de saída)">';
			} else {
				$durationDisplay = !empty($record['duration_display']) ? $record['duration_display'] : '';
				print '<span class="duration-display" data-seconds="' . $rowDurationSeconds . '">' . $durationDisplay . '</span>';
			}
			print '</td>';

			// Total Day column - show employee total on last row only
			print '<td class="employee-total-cell" style="text-align: right;" data-empid="' . $rowEmpId . '">';

			// Check if this is the row with the greatest datahora_in for this employee
			$showTotal = false;
			if (!empty($rowEmpId) && $rowEmpId > 0 && isset($employeeMaxDatehoraInRow[$rowEmpId])) {
				// This row should show the total if its rowid matches the stored rowid for this employee
				$showTotal = ($record['rowid'] == $employeeMaxDatehoraInRow[$rowEmpId]['rowid']);
			}

			// Display total if this is the designated row
			if ($showTotal && isset($employeeTotals[$rowEmpId])) {
				$totalHours = floor($employeeTotals[$rowEmpId] / 3600);
				$totalMinutes = floor(($employeeTotals[$rowEmpId] % 3600) / 60);
				$totalDisplay = sprintf('%02d:%02d', $totalHours, $totalMinutes);
				print '<span class="duration-total-display no-icon"><span class="duration-total-text">' . $totalDisplay . '</span></span>';
			}

			print '</td>';

			print '<td style="white-space: nowrap; text-align: right;">';

			// Add + button for creating new record below this row (shaded/inactive when not in edit mode)
			if ($user->rights->dolizsynch->zspunchclock->write) {
				// Convert dataval to YYYY-MM-DD format for JavaScript
				$dataval_formatted = date('Y-m-d', strtotime($record['dataval']));
				if ($edit_mode) {
					print '<a href="#" onclick="addNewRowBelow(\'' . $dataval_formatted . '\', ' . $record['rowid'] . '); return false;" title="Adicionar novo registo abaixo" style="margin-right: 5px;">';
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
					print '<a href="#" onclick="deleteEntireRow(' . $record['rowid'] . ', null, \'' . dol_escape_js($record['fullname']) . '\'); return false;" title="Eliminar registo completo">';
					print '<span class="fas fa-minus" style="color: #dc3545;" title="Eliminar registo completo"></span>';
					print '</a>';
				} else {
					print '<span title="Ativar modo de edição para eliminar" style="opacity: 0.3; cursor: not-allowed;">';
					print '<span class="fas fa-minus" style="color: #dc3545;"></span>';
					print '</span>';
				}
			}
		}

		print '</td>';
		print '</tr>' . "\n";
	}
} // End of else block for displaying records

// Calculate totals
$totalDuration = 0;
$uniqueEmployees = array();
foreach ($punchRecords as $record) {
	if (!empty($record['duration'])) {
		$totalDuration += $record['duration'];
	}
	if (!empty($record['empid_zs']) && $record['empid_zs'] != 0) {
		$uniqueEmployees[$record['empid_zs']] = true;
	}
}
$totalEmployees = count($uniqueEmployees);

// Convert total duration from seconds to HH:MM format
$totalHours = floor($totalDuration / 3600);
$totalMinutes = floor(($totalDuration % 3600) / 60);
$totalDurationDisplay = sprintf('%02d:%02d', $totalHours, $totalMinutes);

// Fetch the task for the selected date to display link
$taskForDate = null;
$entityLabel = $object->fetchEntityLabel();
$taskRef = $entityLabel . "_Ponto_" . $selectedDate;
$taskObj = new Task($db);
if ($taskObj->fetch(0, $taskRef) > 0) {
	$taskForDate = $taskObj;
}

// Display totals footer
print '<tr class="liste_total">';
print '<td class="overall-employee-count" style="vertical-align: middle;" data-label="' . dol_escape_htmltag($langs->transnoentitiesnoconv("Employees")) . '">(' . $totalEmployees . ' ' . $langs->transnoentitiesnoconv("Employees") . ')';
// Add task link in grey after employee count
if (!empty($taskForDate) && $taskForDate->id > 0) {
	print ' <span style="color: #999; font-size: 0.9em;"><a href="' . DOL_URL_ROOT . '/projet/tasks/time.php?id=' . $taskForDate->id . '&withproject=1" target="_blank" style="color: #999;" title="' . dol_escape_htmltag($taskForDate->ref) . '">[' . dol_escape_htmltag($langs->trans("Task")) . ']</a></span>';
}
print '</td>';
print '<td></td>';
print '<td></td>';
print '<td></td>';
print '<td></td>';
print '<td></td>';
print '<td></td>';
//print '<td style="text-align: center; vertical-align: middle;">' . $langs->trans("Total") . ':</td>';
// Remove clock icon in edit mode
print '<td class="overall-total-duration" style="text-align: right; vertical-align: middle;" data-total-seconds="' . $totalDuration . '"><span class="duration-total-display no-icon"><span class="duration-total-text">' . $totalDurationDisplay . '</span></span></td>';
print '<td style="vertical-align: middle;"></td>'; // Actions column in footer
print '</tr>' . "\n";

print '</table>' . "\n";
print '</div>' . "\n";

// Edit Mode Toggle Button
if ($user->rights->dolizsynch->zspunchclock->write) {
	print '<div style="text-align: center; margin-top: 20px;">';
	if ($edit_mode) {
		print '<a href="' . $_SERVER["PHP_SELF"] . '?';
		print 'edit_mode=0';
		print '&search_date=' . urlencode($search_date);
		print '&search_fullname=' . urlencode($search_fullname);
		print '&search_empid_zs=' . urlencode($search_empid_zs);
		print '&page=' . $page;
		print '" class="butAction">' . $langs->trans("LeaveEditMode") . '</a>';
	} else {
		print '<a href="' . $_SERVER["PHP_SELF"] . '?';
		print 'edit_mode=1';
		print '&search_date=' . urlencode($search_date);
		print '&search_fullname=' . urlencode($search_fullname);
		print '&search_empid_zs=' . urlencode($search_empid_zs);
		print '&page=' . $page;
		print '" class="butAction">' . $langs->trans("EnterEditMode") . '</a>';
	}
	print '</div>';
}

print '</form>' . "\n";

// Enable searchable employee dropdowns (Select2 via Dolibarr ajax_combobox)
print ajax_combobox('.employee-field', array(), 0, 0, 'resolve', '0');


if ($action == 'deleteline') {
	$formconfirm = $form->formconfirm($_SERVER['PHP_SELF'] . '?lineid=' . GETPOST('lineid', 'int') . '&search_fullname=' . urlencode($search_fullname) . '&search_empid_zs=' . urlencode($search_empid_zs) . '&search_sentido=' . urlencode($search_sentido), $langs->trans('DeleteLine'), $langs->trans('ConfirmDeleteLine'), 'confirm_deleteline', '', 0, 1);
	print $formconfirm;
}

// JavaScript for inline editing
echo "<!-- DEBUG: About to output custom JavaScript -->\n";
?>
<!-- START CUSTOM JAVASCRIPT FOR ZSPUNCHCLOCK -->
<script type="text/javascript">
	var EMPLOYEE_LABEL = <?= json_encode($langs->transnoentitiesnoconv("Employees")) ?>;
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

	function updateEmployeeTotalsDisplay(empid) {
		empid = parseInt(empid, 10);
		if (!empid) {
			return;
		}

		var $rows = $('tr[data-empid="' + empid + '"]');
		if (!$rows.length) {
			return;
		}

		var totalSeconds = 0;
		$rows.each(function() {
			var rowSeconds = parseInt($(this).attr("data-duration-seconds"), 10);
			if (isNaN(rowSeconds)) {
				var $durationInput = $(this).find('input[data-field="duration"]');
				if ($durationInput.length) {
					rowSeconds = hhmmToSeconds($durationInput.val());
				} else {
					rowSeconds = hhmmToSeconds($(this).find(".duration-display").text());
				}
			}
			if (!isNaN(rowSeconds)) {
				totalSeconds += rowSeconds;
			}
		});

		var formatted = secondsToHHMM(totalSeconds);
		var html = buildDurationDisplayHtml(formatted, false);
		$rows.find(".employee-total-cell").empty();
		var $targetCell = $rows.last().find(".employee-total-cell");
		if ($targetCell.length) {
			$targetCell.html(html);
		}
	}

	function updateOverallTotalDisplay() {
		var totalSeconds = 0;
		var uniqueEmpIds = {};

		$('tr[data-rowid]').each(function() {
			var $row = $(this);
			var seconds = parseInt($row.attr("data-duration-seconds"), 10);
			if (!isNaN(seconds)) {
				totalSeconds += seconds;
			}
			var empid = parseInt($row.attr("data-empid"), 10);
			if (empid) {
				uniqueEmpIds[empid] = true;
			}
		});

		var $overallCell = $(".overall-total-duration");
		if ($overallCell.length) {
			var overallFormatted = secondsToHHMM(totalSeconds);
			$overallCell
				.attr("data-total-seconds", totalSeconds)
				.html(buildDurationDisplayHtml(overallFormatted, false)); // Always false - no clock icon
		}

		var $countCell = $(".overall-employee-count");
		if ($countCell.length) {
			var count = Object.keys(uniqueEmpIds).length;
			// Preserve the task link by finding it first
			var $taskLink = $countCell.find('span a').parent();
			var taskLinkHtml = $taskLink.length ? $taskLink.prop('outerHTML') : '';
			$countCell.html("(" + count + " " + EMPLOYEE_LABEL + ")" + (taskLinkHtml ? ' ' + taskLinkHtml : ''));
		}
	}

	function refreshTotalsForRow($row, durationHHMM) {
		if (!$row || !$row.length) {
			return;
		}

		updateRowDurationState($row, durationHHMM);

		var empid = parseInt($row.attr("data-empid"), 10);
		if (empid) {
			updateEmployeeTotalsDisplay(empid);
		}

		updateOverallTotalDisplay();
	}

	console.log("ZSPunchClock custom JavaScript is loading...");
	$(document).ready(function() {
		console.log("Document ready - initializing inline editing...");

		$(".employee-field").each(function() {
			var $field = $(this);
			if (typeof $field.data("original-value") === "undefined") {
				$field.data("original-value", $field.val() || "");
			}
		});

		// Ensure Select2 shows options on open (even before typing)
		$(document).on("select2:open", ".employee-field", function() {
			var $search = $(".select2-container--open .select2-search__field");
			if ($search.length && !$search.val()) {
				$search.trigger("input");
			}
		});

		$('tr[data-rowid]').each(function() {
			updateRowDurationState($(this));
		});

		var initialEmployeeSeen = {};
		$('tr[data-empid]').each(function() {
			var empId = parseInt($(this).attr("data-empid"), 10);
			if (empId && !initialEmployeeSeen[empId]) {
				updateEmployeeTotalsDisplay(empId);
				initialEmployeeSeen[empId] = true;
			}
		});
		updateOverallTotalDisplay();

		// Store original value for employee field when focused
		$(".employee-field").on("focus", function() {
			var $field = $(this);
			if (typeof $field.data("original-value") === "undefined") {
				var initialValue = $field.val() || "";
				$field.data("original-value", initialValue);
				console.log("Stored original employee value on focus:", initialValue);
			}
		});

		// Handle employee dropdown change separately (on change, not blur)
		$(".employee-field").on("change", function() {
			var $field = $(this);
			var fieldName = $field.data("field");
			var recordId = $field.data("id");
			var newValue = $field.val();
			var originalValue = $field.data("original-value");

			// If no original value stored (shouldn't happen), store it now
			if (typeof originalValue === "undefined") {
				$field.data("original-value", newValue);
				console.log("No original value, storing now:", newValue);
				originalValue = newValue;
			}

			// Skip if value hasn't changed
			if (newValue === originalValue) {
				console.log("Employee value hasn't changed, skipping");
				return;
			}

			// Get the empid from the selected option
			var empid = $field.find("option:selected").data("empid");

			// For new rows (recordId=0), we need to create the record first using addNewRowBelow
			if (recordId == 0) {
				// Get the date from the row
				var dateString = "<?= $selectedDate ?>";
				console.log("Creating new record for employee selection on date:", dateString);

				// Call addNewRowBelow to create the record, then reload
				addNewRowBelow(dateString, 0);
				return;
			}

			console.log("=== SAVE FIELD CHANGE ===");
			console.log("Record ID:", recordId);
			console.log("Field Name:", fieldName);
			console.log("Old Value:", originalValue);
			console.log("New Value:", newValue);

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
					console.log("=== AJAX SUCCESS RESPONSE ===");
					console.log("Full response:", response);
					console.log("Success:", response.success);
					console.log("Error:", response.error);
					$field.removeClass("saving").prop("disabled", false);

					if (response.success) {
						console.log("Save successful");
						var $row = $field.closest("tr");
						var previousEmpid = parseInt($row.attr("data-empid"), 10) || 0;

						$row.find(".employee-total-cell").text("");

						if ($row.hasClass("new-row-highlight")) {
							$row.removeClass("new-row-highlight");
							console.log("Removed highlight after employee save for row",
								recordId);
						}

						// Update original value
						$field.data("original-value", newValue);

						// Show brief success feedback
						$field.addClass("success").removeClass("error");
						setTimeout(function() {
							$field.removeClass("success");
						}, 1000);

						if (typeof response.empid_zs !== "undefined") {
							var newEmpid = parseInt(response.empid_zs, 10) || 0;
							$row.attr("data-empid", newEmpid).data("empid", newEmpid);
							$row.find(".employee-total-cell").attr("data-empid", newEmpid);

							if (newEmpid) {
								$(".empid-display-" + recordId).text(response.empid_zs);
							} else {
								$(".empid-display-" + recordId).text("");
							}

							updateRowDurationState($row);

							if (previousEmpid && previousEmpid !== newEmpid) {
								updateEmployeeTotalsDisplay(previousEmpid);
							}
							if (newEmpid) {
								updateEmployeeTotalsDisplay(newEmpid);
							}
							updateOverallTotalDisplay();
						} else if (previousEmpid) {
							updateEmployeeTotalsDisplay(previousEmpid);
							updateOverallTotalDisplay();
						}
					} else {
						// Show error
						console.log("=== SAVE FAILED (EMPLOYEE FIELD) ===");
						console.error("Save failed:", response.error);
						console.error("Full response:", response);
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
					console.log("=== AJAX NETWORK ERROR ===");
					console.error("XHR:", xhr);
					console.error("Status:", status);
					console.error("Error:", error);
					console.error("Response text:", xhr.responseText);
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

		// Store original value when field is focused
		$(".inline-edit-field:not(.employee-field)").on("focus", function() {
			var $field = $(this);
			// Only store if not already stored
			if (typeof $field.data("original-value") === "undefined") {
				var initialValue = $field.val() || "";
				$field.data("original-value", initialValue);
				console.log("Stored original value on focus:", initialValue, "for field:", $field.data(
					"field"));
			}
		});

		// Handle inline field changes (for non-employee fields)
		$(".inline-edit-field:not(.employee-field)").on("blur", function() {
			var field = this;
			var $field = $(field);
			var fieldName = $field.data("field");
			var recordId = $field.data("id");
			var newValue = $field.val();
			var originalValue = $field.data("original-value");

			// If original value was never stored (shouldn't happen with focus handler), skip
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

					// Debug logging to browser console
					if (response.debug) {
						console.log("AJAX Response Debug Info:", response.debug);
					}

					if (response.success) {
						var $row = $field.closest("tr");
						var durationForTotals = null;

						$row.find(".employee-total-cell").text("");

						if ($row.hasClass("new-row-highlight")) {
							$row.removeClass("new-row-highlight");
							console.log("Removed highlight after successful save for row",
								recordId);
						}

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

						if (fieldName === "duration") {
							if (response.debug) {
								if (typeof response.debug === "string") {
									alert("Debug: " + response.debug);
								} else {
									console.log("Debug info:", response.debug);
								}
							}

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
						// Debug logging for errors
						if (response.debug) {
							console.log("AJAX Error Debug Info:", response.debug);
						}

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

		// Store original value when field gains focus
		$(".inline-edit-field").on("focus", function() {
			var $field = $(this);
			if (!$field.data("original-value")) {
				$field.data("original-value", $field.val());
			}
			$field.addClass("editing");
		});

		// Remove editing class when field loses focus
		$(".inline-edit-field").on("blur", function() {
			$(this).removeClass("editing");
		});

		// Handle empty cell datetime input to create new punch records
		$(".empty-cell").on("blur", function() {
			var $cell = $(this);
			var datetimeValue = $cell.val().trim();

			if (!datetimeValue) {
				return; // No value entered
			}

			// Validate datetime format (YYYY-MM-DDTHH:MM)
			var datetimeRegex = /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/;
			if (!datetimeRegex.test(datetimeValue)) {
				alert("Please enter datetime in YYYY-MM-DDTHH:MM format (e.g., 2024-01-15T09:30)");
				$cell.val("").removeClass("error");
				return;
			}

			var empidZs = $cell.data("empid");
			var dataval = $cell.data("dataval");
			var sentido = $cell.data("sentido");

			// Show saving state
			$cell.addClass("saving").prop("disabled", true);

			// Create new punch record
			$.ajax({
				url: "<?= $_SERVER["PHP_SELF"] ?>",
				type: "POST",
				dataType: "json",
				data: {
					action: "create_punch",
					ajax: "1",
					token: CSRF_TOKEN,
					empid_zs: empidZs,
					dataval: dataval,
					sentido: sentido,
					datetime: datetimeValue
				},
				success: function(response) {
					$cell.removeClass("saving").prop("disabled", false);

					if (response.success) {
						// Show brief success feedback
						$cell.addClass("success").removeClass("error");
						setTimeout(function() {
							// Reload page to show the new record
							window.location.reload();
						}, 500);
					} else {
						// Show error
						$cell.addClass("error").removeClass("success");
						alert("Error: " + (response.error || "Unknown error"));
						$cell.val("");

						setTimeout(function() {
							$cell.removeClass("error");
						}, 3000);
					}
				},
				error: function(xhr, status, error) {
					$cell.removeClass("saving").prop("disabled", false);
					$cell.addClass("error");
					alert("Network error: " + error);
					$cell.val("");

					setTimeout(function() {
						$cell.removeClass("error");
					}, 3000);
				}
			});
		});

		// Add focus/blur handlers for empty cells
		$(".empty-cell").on("focus", function() {
			$(this).addClass("editing");
		});

		// Handle Enter key to save and move to next field
		$(".inline-edit-field, .empty-cell").on("keydown", function(e) {
			if (e.which === 13) { // Enter key
				$(this).blur();
			}
		});
	});

	// Function to delete entire row (both IN and OUT entries)
	function deleteEntireRow(inId, outId, employeeName) {
		console.log("Delete function called with:", {
			inId: inId,
			outId: outId,
			employeeName: employeeName
		});

		if (!confirm("Tem certeza que deseja eliminar todo o registo de " + employeeName + "?")) {
			console.log("User cancelled deletion");
			return;
		}

		console.log("Sending delete request...");

		// Send both IDs in a single request - let the backend handle all deletions
		$.ajax({
			url: "<?= $_SERVER["PHP_SELF"] ?>",
			type: "POST",
			dataType: "json",
			data: {
				action: "delete_entire_row",
				ajax: "1",
				token: CSRF_TOKEN,
				in_id: (inId !== null && inId !== "null") ? inId : null,
				out_id: (outId !== null && outId !== "null") ? outId : null
			},
			beforeSend: function() {
				console.log("AJAX request starting...");
			},
			success: function(response) {
				console.log("Delete response received:", response);
				if (response.success) {
					console.log("Deletion successful, reloading page...");
					// Deletion successful
					window.location.reload();
				} else {
					console.error("Deletion failed:", response.error);
					alert("Erro: " + (response.error || "Erro desconhecido"));
				}
			},
			error: function(xhr, status, error) {
				console.error("AJAX error:", {
					xhr: xhr,
					status: status,
					error: error
				});
				console.error("Response text:", xhr.responseText);
				alert("Erro de rede: " + error);
			}
		});
	}

	// Highlight newly created row if newrow parameter exists
	$(document).ready(function() {
		var urlParams = new URLSearchParams(window.location.search);
		var newRowId = urlParams.get("newrow");
		var afterRowId = urlParams.get("after");
		console.log("Checking for newrow parameter:", newRowId);
		if (newRowId) {
			var $newRow = $("tr[data-rowid=\"" + newRowId + "\"]");
			console.log("Found " + $newRow.length + " rows with data-rowid=" + newRowId);
			if ($newRow.length > 0) {
				if (afterRowId) {
					var $afterRow = $("tr[data-rowid=\"" + afterRowId + "\"]");
					if ($afterRow.length > 0) {
						$newRow.insertAfter($afterRow);
					}
				}
				console.log("Adding highlight animation to new row");
				// Add highlight class
				$newRow.addClass("new-row-highlight");

				// Automatically remove highlight class after animation completes
				setTimeout(function() {
					$newRow.removeClass("new-row-highlight");
					console.log("Removed highlight class from new row");
				}, 3200);

				// Scroll to the new row smoothly
				$("html, body").animate({
					scrollTop: $newRow.offset().top - 100
				}, 500);

				// Remove the newrow parameter from URL after 3 seconds (without page reload)
				setTimeout(function() {
					var url = new URL(window.location);
					url.searchParams.delete("newrow");
					url.searchParams.delete("after");
					window.history.replaceState({}, document.title, url);
					console.log("Removed newrow parameter from URL");
				}, 3000);
			} else {
				console.warn("Could not find row with data-rowid=" + newRowId);
			}
		}
	});
</script>
<?php

llxFooter();
$db->close();

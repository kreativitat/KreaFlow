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
require_once DOL_DOCUMENT_ROOT . '/core/lib/date.lib.php';
require_once DOL_DOCUMENT_ROOT . '/core/class/html.formother.class.php';
require_once DOL_DOCUMENT_ROOT . '/user/class/user.class.php';
require_once __DIR__ . '/../class/zspunchclock_synch.class.php';

global $conf, $langs, $db, $user;

// Load variable for pagination
$limit = GETPOST('limit', 'int') ? GETPOST('limit', 'int') : 100;
$sortfield = GETPOST('sortfield', 'aZ09comma');
$sortorder = GETPOST('sortorder', 'aZ09comma');
$page = GETPOSTISSET('pageplusone') ? (GETPOST('pageplusone') - 1) : GETPOST("page", 'int');
if (empty($page) || $page < 0 || GETPOST('button_search', 'alpha') || GETPOST('button_removefilter', 'alpha')) {
	$page = 0;
}
$offset = $limit * $page;
$pageprev = $page - 1;
$pagenext = $page + 1;

// Initialize other variables
$optioncss = GETPOST('optioncss', 'aZ');
$contextpage = GETPOST('contextpage', 'aZ') ? GETPOST('contextpage', 'aZ') : 'zspunchclocksynchlist';

// Load translations
$langsLoad = array('projects', 'bills', 'orders', 'companies', 'zspunchclock', 'dolizsynch@kreaflow');
$langs->loadLangs($langsLoad);

// Get parameters
$action = GETPOST('action', 'alpha');
$confirm = GETPOST('confirm', 'alpha');
$rowid = GETPOST('id', 'int');

// Load object
$object = new ZSPunchClock_Synch($db);
$object->loja_zs = !empty($conf->global->ZS_API_STORE) ? $conf->global->ZS_API_STORE : 0;

// Security check
if (!$user->rights->dolizsynch->zspunchclock->read) {
	accessforbidden();
}

// Fetch the ZS_PUNCH_DAY_CHANGE_HOUR configuration
$zsPunchDayChangeHour = !empty($conf->global->ZS_PUNCH_DAY_CHANGE_HOUR) ? (int)$conf->global->ZS_PUNCH_DAY_CHANGE_HOUR : 0;

// Always show today's date only (no navigation)
// For yesterday (current test):
// $selectedDate = date('Y-m-d', strtotime('-1 day'));
$selectedDate = date('Y-m-d');

// Calculate the date range for the selected day
// Example: If ZS_PUNCH_DAY_CHANGE_HOUR = 6, and selectedDate = 2025-03-01
// Range: 2025-03-01 06:00:00 to 2025-03-02 06:00:00
$selectedDateStart = $selectedDate . ' ' . sprintf('%02d:00:00', $zsPunchDayChangeHour);
$selectedDateEnd = date('Y-m-d', strtotime($selectedDate . ' +1 day')) . ' ' . sprintf('%02d:00:00', $zsPunchDayChangeHour);

// Handle AJAX actions - MUST be before any HTML output
if ($action == 'fetch_last_time' && GETPOST('ajax', 'alpha')) {
	while (ob_get_level()) {
		ob_end_clean();
	}

	header('Content-Type: application/json');
	header('Cache-Control: no-cache, must-revalidate');

	try {
		$empId = GETPOST('empid', 'int');
		$rowId = GETPOST('rowid', 'int');

		if (empty($empId)) {
			echo json_encode(array(
				'success' => true,
				'placeholder' => '00:00',
				'direction_value' => 1,
				'direction_label' => 'Entrada'
			));
			return;
		}

		$info = dolizsynch_get_last_punch_info($db, $empId, $rowId);
		$directionValue = 1;
		$placeholder = '00:00';
		if (!empty($info['sentido'])) {
			$directionValue = ((int) $info['sentido'] == 1) ? 2 : 1;
		}
		if (!empty($info['datahora'])) {
			$nextTs = strtotime($info['datahora']) + 60;
			if ($nextTs !== false) {
				$placeholder = date('H:i', $nextTs);
			}
		}
		$directionLabel = ($directionValue == 1) ? 'Entrada' : 'Saida';

		echo json_encode(array(
			'success' => true,
			'placeholder' => $placeholder,
			'direction_value' => $directionValue,
			'direction_label' => $directionLabel
		));
	} catch (Exception $e) {
		echo json_encode(array('success' => false, 'error' => $e->getMessage()));
	}
	return;
}

if ($action == 'save_inline' && GETPOST('ajax', 'alpha')) {
	// Clean any output buffers
	while (ob_get_level()) {
		ob_end_clean();
	}

	header('Content-Type: application/json');
	header('Cache-Control: no-cache, must-revalidate');

	try {
		$id = GETPOST('id', 'int');
		$field = GETPOST('field', 'alpha');
		$value = GETPOST('value', 'alpha');

		if (!$id || !$field || !$user->rights->dolizsynch->zspunchclock->write) {
			echo json_encode(array('success' => false, 'error' => 'Invalid parameters or no permission'));
			exit;
		}

		if (!in_array($field, array('datahora', 'empid_zs', 'sentido'))) {
			echo json_encode(array('success' => false, 'error' => 'Invalid field'));
			exit;
		}

		$objecttosave = new ZSPunchClock_Synch($db);
		if ($objecttosave->fetch($id) <= 0) {
			echo json_encode(array('success' => false, 'error' => 'Object not found'));
			exit;
		}
		$originalEmpId = $objecttosave->empid_zs;
		$originalDatahora = $objecttosave->datahora;

		$zsPunchDayChangeHour = !empty($conf->global->ZS_PUNCH_DAY_CHANGE_HOUR) ? (int)$conf->global->ZS_PUNCH_DAY_CHANGE_HOUR : 0;

		$newValues = array(
			'display' => null,
			'datahora' => null,
			'dataval' => null,
			'empid_zs' => null,
			'sentido' => null
		);

		$timestamp = null;

		if ($field == 'datahora') {
			// Validate time format (HH:MM)
			if (!preg_match('/^([01]?[0-9]|2[0-3]):[0-5][0-9]$/', $value)) {
				echo json_encode(array('success' => false, 'error' => 'Invalid time format. Use HH:MM'));
				exit;
			}

			list($hours, $minutes) = explode(':', $value);
			$hours = (int)$hours;
			$minutes = (int)$minutes;

			// Get reference date from dataval - use server timezone date parsing
			if (!empty($objecttosave->dataval)) {
				// dataval might be a date string (Y-m-d) or timestamp
				if (is_numeric($objecttosave->dataval)) {
					$date_part = date('Y-m-d', $objecttosave->dataval);
				} else {
					// Parse as date string and extract Y-m-d
					$ts = strtotime($objecttosave->dataval);
					$date_part = date('Y-m-d', $ts);
				}
			} elseif (!empty($objecttosave->datahora)) {
				// Fallback to datahora date
				$ts = is_numeric($objecttosave->datahora) ? $objecttosave->datahora : strtotime($objecttosave->datahora);
				$date_part = date('Y-m-d', $ts);
			} else {
				// Use selected date
				$date_part = $selectedDate;
			}

			// Parse date components
			$date_parts = explode('-', $date_part);
			$year = (int)$date_parts[0];
			$month = (int)$date_parts[1];
			$day = (int)$date_parts[2];

			// Use mktime() which uses server timezone (Europe/Lisbon)
			// This matches the Zone Soft data which is also in Lisbon timezone
			$timestamp = mktime($hours, $minutes, 0, $month, $day, $year);
			$isNextDay = ($zsPunchDayChangeHour > 0 && $hours < $zsPunchDayChangeHour);
			if ($isNextDay) {
				$timestamp += 86400; // Next day relative to dataval
			}

			if ($timestamp === false || $timestamp <= 0) {
				echo json_encode(array('success' => false, 'error' => 'Invalid datetime conversion'));
				exit;
			}

			$newValues['datahora'] = date('Y-m-d H:i:s', $timestamp);
			$newValues['dataval'] = $date_part; // Keep dataval as the selected punch day
			$displayTime = date('H:i', $timestamp);
			$newValues['display'] = $isNextDay ? '+' . $displayTime : $displayTime;
			$validation = dolizsynch_validate_chronology($db, $objecttosave->empid_zs, $newValues['datahora'], $objecttosave->id);
			if (!$validation['valid']) {
				echo json_encode(array('success' => false, 'error' => $validation['message']));
				exit;
			}
		} elseif ($field == 'empid_zs') {
			$newEmpid = (int)$value;
			if ($newEmpid < 0) {
				$newEmpid = 0;
			}
			$newValues['empid_zs'] = $newEmpid;
			$newValues['display'] = $newEmpid;
		} elseif ($field == 'sentido') {
			// Direction field - 1=Entrada, 2=Saida
			$newSentido = (int)$value;
			if ($newSentido != 1 && $newSentido != 2) {
				echo json_encode(array('success' => false, 'error' => 'Invalid direction value. Must be 1 (Entrada) or 2 (Saida)'));
				exit;
			}
			$newValues['sentido'] = $newSentido;
			$newValues['display'] = $newSentido == 1 ? 'Entrada' : 'Saida';
		}

		$isZsbmsRow = !empty($objecttosave->zsbms_synch);

		if ($field == 'empid_zs' && !empty($newValues['empid_zs']) && !empty($objecttosave->datahora)) {
			$validation = dolizsynch_validate_chronology($db, $newValues['empid_zs'], $objecttosave->datahora, $objecttosave->id);
			if (!$validation['valid']) {
				echo json_encode(array('success' => false, 'error' => $validation['message']));
				exit;
			}
		}

		if ($isZsbmsRow) {
			$newEntry = new ZSPunchClock_Synch($db);
			$newEntry->loja_zs = $objecttosave->loja_zs;
			$newEntry->sentido = $objecttosave->sentido;
			$newEntry->empid_zs = $objecttosave->empid_zs;

			// Ensure datahora and dataval are properly formatted datetime strings
			$datahora_value = $objecttosave->datahora;
			if (is_numeric($datahora_value)) {
				$datahora_value = date('Y-m-d H:i:s', $datahora_value);
			}
			$newEntry->datahora = $datahora_value;

			$dataval_value = $objecttosave->dataval;
			if (is_numeric($dataval_value)) {
				$dataval_value = date('Y-m-d', $dataval_value);
			}
			$newEntry->dataval = $dataval_value;

			$newEntry->id_zs = null;
			$newEntry->synch = 0;
			$newEntry->zsbms_synch = 0;
			$newEntry->pairid = $objecttosave->pairid;
			$newEntry->entity = $objecttosave->entity;
			$newEntry->date_creation = dol_now('tzserver'); // New record creation date (server timezone)
			$newEntry->fk_user_creat = $user->id; // Record who created this new entry
			$newEntry->fk_user_modif = $user->id; // Record who last modified it

			if ($field == 'datahora') {
				$newEntry->datahora = $newValues['datahora'];
				$newEntry->dataval = $newValues['dataval'];
			} elseif ($field == 'empid_zs') {
				$newEntry->empid_zs = $newValues['empid_zs'];
			} elseif ($field == 'sentido') {
				$newEntry->sentido = $newValues['sentido'];
			}

			if ($field == 'datahora') {
				$validation = dolizsynch_validate_chronology($db, $newEntry->empid_zs, $newEntry->datahora, $objecttosave->id);
				if (!$validation['valid']) {
					echo json_encode(array('success' => false, 'error' => $validation['message']));
					exit;
				}
			}

			if ($newEntry->create($user) > 0) {
				$objecttosave->synch = 4;
				$objecttosave->fk_user_modif = $user->id;
				// Force tms update by using direct SQL to ensure timestamp is refreshed
				$sql = "UPDATE " . MAIN_DB_PREFIX . "dolizsynch_zspunchclock_synch";
				$sql .= " SET synch = 4, fk_user_modif = " . intval($user->id);
				$sql .= " WHERE rowid = " . intval($objecttosave->id);
				$db->query($sql);
				// dolizsynch_recalculate_employee_directions($db, $originalEmpId, $originalDatahora, $zsPunchDayChangeHour);
				// dolizsynch_recalculate_employee_directions($db, $newEntry->empid_zs, $newEntry->datahora, $zsPunchDayChangeHour);
				echo json_encode(array('success' => true, 'reload' => true));
			} else {
				echo json_encode(array('success' => false, 'error' => $newEntry->error ? $newEntry->error : 'Create failed'));
			}
			exit;
		}

		// Manual entry update
		if ($field == 'datahora') {
			$objecttosave->datahora = $newValues['datahora'];
			$objecttosave->dataval = $newValues['dataval'];
		} elseif ($field == 'empid_zs') {
			$objecttosave->empid_zs = $newValues['empid_zs'];
		} elseif ($field == 'sentido') {
			$objecttosave->sentido = $newValues['sentido'];
		}

		// Record who made the modification
		$objecttosave->fk_user_modif = $user->id;

		// Force tms update by using direct SQL to ensure timestamp is refreshed
		$updateFields = array();
		if ($field == 'datahora') {
			$updateFields[] = "datahora = '" . $db->escape($objecttosave->datahora) . "'";
			$updateFields[] = "dataval = '" . $db->escape($objecttosave->dataval) . "'";
		} elseif ($field == 'empid_zs') {
			$updateFields[] = "empid_zs = " . intval($objecttosave->empid_zs);
		} elseif ($field == 'sentido') {
			$updateFields[] = "sentido = " . intval($objecttosave->sentido);
		}
		$updateFields[] = "fk_user_modif = " . intval($user->id);

		$sql = "UPDATE " . MAIN_DB_PREFIX . "dolizsynch_zspunchclock_synch";
		$sql .= " SET " . implode(", ", $updateFields);
		$sql .= " WHERE rowid = " . intval($objecttosave->id);
		$result = $db->query($sql);

		if ($result > 0) {
			if ($field == 'datahora') {
				// dolizsynch_recalculate_employee_directions($db, $originalEmpId, $originalDatahora, $zsPunchDayChangeHour);
				// dolizsynch_recalculate_employee_directions($db, $objecttosave->empid_zs, $objecttosave->datahora, $zsPunchDayChangeHour);
			} elseif ($field == 'empid_zs') {
				if ($originalEmpId && $originalEmpId != $objecttosave->empid_zs) {
					// dolizsynch_recalculate_employee_directions($db, $originalEmpId, $originalDatahora, $zsPunchDayChangeHour);
				}
				// dolizsynch_recalculate_employee_directions($db, $objecttosave->empid_zs, $objecttosave->datahora, $zsPunchDayChangeHour);
			}

			$objecttosave->fetch($id);
			$displayValue = $newValues['display'];
			$directionLabel = $objecttosave->sentido == 1 ? 'Entrada' : 'Saida';
			$updatedDate = dol_print_date($db->jdate($objecttosave->dataval), 'day');
			$nextInfo = dolizsynch_get_last_punch_info($db, $objecttosave->empid_zs, null);
			$nextPlaceholder = '00:00';
			if (!empty($nextInfo['datahora'])) {
				$npTs = strtotime($nextInfo['datahora']) + 60;
				if ($npTs !== false) {
					$nextPlaceholder = date('H:i', $npTs);
				}
			}

			echo json_encode(array(
				'success' => true,
				'value' => $displayValue,
				'id' => $id,
				'field' => $field,
				'direction' => $directionLabel,
				'dateiso' => $objecttosave->dataval,
				'datedisplay' => $updatedDate,
				'placeholder' => $nextPlaceholder
			));
		} else {
			echo json_encode(array('success' => false, 'error' => $objecttosave->error ? $objecttosave->error : 'Update failed'));
		}
	} catch (Exception $e) {
		echo json_encode(array('success' => false, 'error' => $e->getMessage()));
	}
	exit;
}

// Handle AJAX delete request
if ($action == 'delete_entire_row' && GETPOST('ajax', 'alpha')) {
	// Clean any output buffers
	while (ob_get_level()) {
		ob_end_clean();
	}

	header('Content-Type: application/json');
	header('Cache-Control: no-cache, must-revalidate');

	try {
		$rowid = GETPOST('rowid', 'int');

		if ($rowid && $user->rights->dolizsynch->zspunchclock->write) {
			$objecttodelete = new ZSPunchClock_Synch($db);
			$result = $objecttodelete->fetch($rowid);

			if ($result > 0) {
				$empIdToRecalc = $objecttodelete->empid_zs;
				$datahoraToRecalc = $objecttodelete->datahora;
				if (!empty($objecttodelete->zsbms_synch)) {
					$objecttodelete->synch = 3;
					$objecttodelete->fk_user_modif = $user->id;
					$result = $objecttodelete->update($user);
					if ($result > 0) {
						// dolizsynch_recalculate_employee_directions($db, $empIdToRecalc, $datahoraToRecalc, $zsPunchDayChangeHour);
						echo json_encode(array('success' => true, 'softDeleted' => true));
					} else {
						echo json_encode(array('success' => false, 'error' => $objecttodelete->error ? $objecttodelete->error : 'Failed to mark entry as deleted'));
					}
				} else {
					$result = $objecttodelete->delete($user);
					if ($result > 0) {
						// dolizsynch_recalculate_employee_directions($db, $empIdToRecalc, $datahoraToRecalc, $zsPunchDayChangeHour);
						echo json_encode(array('success' => true));
					} else {
						echo json_encode(array('success' => false, 'error' => $objecttodelete->error ? $objecttodelete->error : 'Delete failed'));
					}
				}
			} else {
				echo json_encode(array('success' => false, 'error' => 'Record not found'));
			}
		} else {
			echo json_encode(array('success' => false, 'error' => 'No permission to delete records or missing rowid'));
		}
	} catch (Exception $e) {
		dol_syslog("Delete exception: " . $e->getMessage(), LOG_ERR);
		echo json_encode(array('success' => false, 'error' => $e->getMessage()));
	}
	exit;
}

// Handle AJAX request to mark a ZS BMS entry as deleted (synch=3)
if ($action == 'mark_as_deleted' && GETPOST('ajax', 'alpha')) {
	while (ob_get_level()) {
		ob_end_clean();
	}

	header('Content-Type: application/json');
	header('Cache-Control: no-cache, must-revalidate');

	try {
		$rowid = GETPOST('rowid', 'int');

		if ($rowid && $user->rights->dolizsynch->zspunchclock->write) {
			$objecttoset = new ZSPunchClock_Synch($db);
			if ($objecttoset->fetch($rowid) > 0) {
				if (empty($objecttoset->zsbms_synch)) {
					echo json_encode(array('success' => false, 'error' => 'Only ZS BMS entries can be marked as deleted'));
					exit;
				}
				$objecttoset->synch = 3;
				$objecttoset->fk_user_modif = $user->id;
				if ($objecttoset->update($user) > 0) {
					// dolizsynch_recalculate_employee_directions($db, $objecttoset->empid_zs, $objecttoset->datahora, $zsPunchDayChangeHour);
					echo json_encode(array('success' => true));
				} else {
					echo json_encode(array('success' => false, 'error' => $objecttoset->error ? $objecttoset->error : 'Update failed'));
				}
			} else {
				echo json_encode(array('success' => false, 'error' => 'Record not found'));
			}
		} else {
			echo json_encode(array('success' => false, 'error' => 'No permission or missing rowid'));
		}
	} catch (Exception $e) {
		dol_syslog("Mark as deleted exception: " . $e->getMessage(), LOG_ERR);
		echo json_encode(array('success' => false, 'error' => $e->getMessage()));
	}
	exit;
}

// Handle AJAX create inline request (add new row below)
if ($action == 'create_inline' && GETPOST('ajax', 'alpha')) {
	// Clean any output buffers
	while (ob_get_level()) {
		ob_end_clean();
	}

	header('Content-Type: application/json');
	header('Cache-Control: no-cache, must-revalidate');

	try {
		$dataval = GETPOST('dataval', 'alpha');
		$clicked_rowid = GETPOST('clicked_rowid', 'int');

		if ($dataval && $user->rights->dolizsynch->zspunchclock->write) {
			$newobject = new ZSPunchClock_Synch($db);

			// Create blank row - user will select employee and enter time
			// Use a dummy empid_zs (0) temporarily - user must fill it in
			$newobject->empid_zs = 0; // Placeholder - user will select employee
			$newobject->dataval = $dataval;

			// Set a minimal time (00:00:00) since datahora is NOT NULL in database
			// User MUST edit this after creation
			$newobject->datahora = $dataval . ' 00:00:00';

			$newobject->sentido = 1; // Default to Entrada
			$newobject->synch = 0; // Not synched
			$newobject->zsbms_synch = 0;
			$newobject->pairid = null; // No pair ID
			$newobject->loja_zs = $object->loja_zs;
			$newobject->entity = $conf->entity;
			$newobject->date_creation = dol_now('tzserver'); // Record creation date (server timezone)
			$newobject->fk_user_creat = $user->id; // Record who created it
			$newobject->fk_user_modif = $user->id; // Record who last modified it

			$result = $newobject->create($user);

			if ($result > 0) {
				if (!empty($newobject->empid_zs)) {
					// dolizsynch_recalculate_employee_directions($db, $newobject->empid_zs, $newobject->datahora, $zsPunchDayChangeHour);
				}
				echo json_encode(array('success' => true, 'id' => $newobject->id));
			} else {
				echo json_encode(array('success' => false, 'error' => $newobject->error ? $newobject->error : 'Create failed'));
			}
		} else {
			$error_msg = 'Missing required parameters - ';
			$error_msg .= 'dataval: ' . ($dataval ? $dataval : 'missing') . ', ';
			$error_msg .= 'write permission: ' . ($user->rights->dolizsynch->zspunchclock->write ? 'yes' : 'no');
			echo json_encode(array('success' => false, 'error' => $error_msg));
		}
	} catch (Exception $e) {
		dol_syslog("Create exception: " . $e->getMessage(), LOG_ERR);
		echo json_encode(array('success' => false, 'error' => $e->getMessage()));
	}
	exit;
}

// Old addline handler removed - now using AJAX create_inline

// Build the SQL query with filters for synch table
$sql = 'SELECT t.rowid, t.entity, ';
$sql .= 't.empid_zs, t.dataval, t.datahora, t.sentido, t.synch, t.zsbms_synch, t.pairid, t.id_zs, t.loja_zs, t.tms, t.fk_user_modif, t.fk_user_creat, ';
$sql .= 'u.firstname, u.lastname, CONCAT(u.firstname, " ", u.lastname) as fullname, ';
$sql .= 'um.login as modif_login, CONCAT(um.firstname, " ", um.lastname) as modif_fullname ';
$sql .= 'FROM ' . MAIN_DB_PREFIX . 'dolizsynch_zspunchclock_synch as t ';
$sql .= 'LEFT JOIN ' . MAIN_DB_PREFIX . 'user_extrafields as ue ON t.empid_zs = ue.zs_employee_id ';
$sql .= 'LEFT JOIN ' . MAIN_DB_PREFIX . 'user as u ON ue.fk_object = u.rowid ';
$sql .= 'LEFT JOIN ' . MAIN_DB_PREFIX . 'user as um ON t.fk_user_modif = um.rowid ';
$sql .= 'WHERE t.entity IN (' . getEntity('dolizsynch_zspunchclock_synch') . ')';

// Apply search filters
$search_fullname = GETPOST('search_fullname', 'alpha');
$search_empid_zs = GETPOST('search_empid_zs', 'alpha');
$search_sentido = GETPOST('search_sentido', 'alpha');

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
if (!empty($selectedDate)) {
	$sql .= " AND DATE(t.dataval) = '" . $db->escape($selectedDate) . "'";
}

$sql .= " AND t.synch NOT IN (3,4)";
$sql .= " ORDER BY u.firstname ASC, u.lastname ASC, t.datahora ASC, t.rowid ASC";

// Count total for pagination
$nbtotalofrecords = '';
if (!getDolGlobalInt('MAIN_DISABLE_FULL_SCANLIST')) {
	$sqlforcount = preg_replace('/^SELECT[^F]+FROM/s', 'SELECT COUNT(*) as nbtotalofrecords FROM', $sql);
	$sqlforcount = preg_replace('/ORDER BY .*$/s', '', $sqlforcount);

	$resql_count = $db->query($sqlforcount);
	if ($resql_count) {
		$objforcount = $db->fetch_object($resql_count);
		$nbtotalofrecords = $objforcount->nbtotalofrecords;
	} else {
		dol_print_error($db);
	}

	if (($page * $limit) > $nbtotalofrecords) {
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

function dolizsynch_get_punch_day_window($datetime, $changeHour)
{
	$timestamp = is_numeric($datetime) ? (int) $datetime : strtotime($datetime);
	if (empty($timestamp)) {
		return array(null, null);
	}
	$changeHour = max(0, min(23, (int) $changeHour));
	$changeSeconds = $changeHour * 3600;
	$adjusted = $timestamp - $changeSeconds;
	$dayStartAdjusted = strtotime(date('Y-m-d 00:00:00', $adjusted));
	if (empty($dayStartAdjusted)) {
		return array(null, null);
	}
	$periodStart = $dayStartAdjusted + $changeSeconds;
	$periodEnd = $periodStart + 86400;
	return array(date('Y-m-d H:i:s', $periodStart), date('Y-m-d H:i:s', $periodEnd));
}

function dolizsynch_get_last_punch_info($db, $empId, $excludeRowId = null)
{
	$empId = (int) $empId;
	if (empty($empId)) {
		return array('datahora' => null, 'sentido' => null);
	}
	$sql = "SELECT datahora, sentido FROM " . MAIN_DB_PREFIX . "dolizsynch_zspunchclock_synch WHERE empid_zs = " . $empId . " AND synch NOT IN (3,4)";
	if (!empty($excludeRowId)) {
		$sql .= " AND rowid != " . ((int) $excludeRowId);
	}
	$sql .= " ORDER BY datahora DESC, rowid DESC";
	$sql .= $db->plimit(1);
	$res = $db->query($sql);
	if ($res) {
		if ($obj = $db->fetch_object($res)) {
			$db->free($res);
			return array('datahora' => $obj->datahora, 'sentido' => $obj->sentido);
		}
		$db->free($res);
	}
	return array('datahora' => null, 'sentido' => null);
}

function dolizsynch_validate_chronology($db, $empId, $newDateTime, $excludeRowId = null)
{
	$empId = (int) $empId;
	if (empty($empId) || empty($newDateTime)) {
		return array('valid' => true, 'max' => null);
	}
	$newTimestamp = is_numeric($newDateTime) ? (int) $newDateTime : strtotime($newDateTime);
	if (empty($newTimestamp)) {
		return array('valid' => false, 'message' => 'Invalid datetime value');
	}

	// Check for previous punch (must be less than new time)
	$sqlPrev = "SELECT MAX(datahora) as prevdt FROM " . MAIN_DB_PREFIX . "dolizsynch_zspunchclock_synch WHERE empid_zs = " . $empId . " AND synch NOT IN (3,4)";
	if (!empty($excludeRowId)) {
		$sqlPrev .= " AND rowid != " . ((int) $excludeRowId);
	}
	$sqlPrev .= " AND datahora < '" . $db->escape(date('Y-m-d H:i:s', $newTimestamp)) . "'";

	$resPrev = $db->query($sqlPrev);
	$prevDt = null;
	if ($resPrev) {
		if ($obj = $db->fetch_object($resPrev)) {
			$prevDt = $obj->prevdt;
		}
		$db->free($resPrev);
	}

	// Check for next punch (must be greater than new time)
	$sqlNext = "SELECT MIN(datahora) as nextdt FROM " . MAIN_DB_PREFIX . "dolizsynch_zspunchclock_synch WHERE empid_zs = " . $empId . " AND synch NOT IN (3,4)";
	if (!empty($excludeRowId)) {
		$sqlNext .= " AND rowid != " . ((int) $excludeRowId);
	}
	$sqlNext .= " AND datahora > '" . $db->escape(date('Y-m-d H:i:s', $newTimestamp)) . "'";

	$resNext = $db->query($sqlNext);
	$nextDt = null;
	if ($resNext) {
		if ($obj = $db->fetch_object($resNext)) {
			$nextDt = $obj->nextdt;
		}
		$db->free($resNext);
	}

	// Validation: new time must be after previous punch (if exists) and before next punch (if exists)
	if (!empty($prevDt)) {
		$prevTs = strtotime($prevDt);
		if ($prevTs !== false && $newTimestamp <= $prevTs) {
			return array('valid' => false, 'message' => 'New time must be after previous punch (' . date('H:i', $prevTs) . ')');
		}
	}

	if (!empty($nextDt)) {
		$nextTs = strtotime($nextDt);
		if ($nextTs !== false && $newTimestamp >= $nextTs) {
			return array('valid' => false, 'message' => 'New time must be before next punch (' . date('H:i', $nextTs) . ')');
		}
	}

	return array('valid' => true, 'prev' => $prevDt, 'next' => $nextDt);
}

function dolizsynch_recalculate_employee_directions($db, $empId, $datetime, $changeHour)
{
	if (empty($empId) || empty($datetime)) {
		return;
	}
	list($periodStart, $periodEnd) = dolizsynch_get_punch_day_window($datetime, $changeHour);
	if (empty($periodStart) || empty($periodEnd)) {
		return;
	}
	// Get all punches in the period INCLUDING zsbms_synch entries to calculate correct sequence
	// But we'll only UPDATE manual entries (zsbms_synch = 0 or NULL)
	$sql = "SELECT rowid, sentido, zsbms_synch FROM " . MAIN_DB_PREFIX . "dolizsynch_zspunchclock_synch "
		. "WHERE empid_zs = " . ((int) $empId) . " AND datahora >= '" . $db->escape($periodStart) . "' "
		. "AND datahora < '" . $db->escape($periodEnd) . "' "
		. "AND synch NOT IN (3,4) "
		. "ORDER BY datahora ASC, rowid ASC";
	$resql = $db->query($sql);
	if (!$resql) {
		return;
	}
	$index = 0;
	while ($obj = $db->fetch_object($resql)) {
		$expected = ($index % 2 === 0) ? 1 : 2;

		// Only update direction if:
		// 1. Direction is wrong AND
		// 2. This is NOT a ZS BMS entry (zsbms_synch = 0 or NULL)
		$isZsbmsEntry = !empty($obj->zsbms_synch);

		if ((int) $obj->sentido !== $expected && !$isZsbmsEntry) {
			$db->query("UPDATE " . MAIN_DB_PREFIX . "dolizsynch_zspunchclock_synch SET sentido = " . $expected . " WHERE rowid = " . ((int) $obj->rowid));
		}

		$index++;
	}
	$db->free($resql);
}

$num = $db->num_rows($resql);

/*
 * View
 */

$title = $langs->trans("ZSPunchClockSynchRawData");
$help_url = '';

llxHeader('', $title, $help_url);

$form = new Form($db);
$formother = new FormOther($db);

print '<form method="POST" action="' . $_SERVER["PHP_SELF"] . '" name="formfilter">';
print '<input type="hidden" name="token" value="' . newToken() . '">';
print '<input type="hidden" name="formfilteraction" id="formfilteraction" value="list">';
print '<input type="hidden" name="sortfield" value="' . $sortfield . '">';
print '<input type="hidden" name="sortorder" value="' . $sortorder . '">';
print '<input type="hidden" name="page" value="' . $page . '">';
print '<input type="hidden" name="contextpage" value="' . $contextpage . '">';

// Title and navigation
print load_fiche_titre($title, '', 'clock');

print '<div class="fichecenter">';
print '<div class="fichethirdleft">';
print '</div>';
print '<div class="fichetwothirdright">';
print '<div class="ficheaddleft">';
print '</div>';
print '</div>';
print '</div>';
print '<div class="clearboth"></div>';

// Display current date with back navigation to yesterday
print '<div style="text-align: center; padding: 10px; background-color: #f8f9fa; border: 1px solid #dee2e6; border-radius: 4px; margin: 0 0 10px 0;">';

// Back arrow to yesterday
$yesterday = date('Y-m-d', strtotime('-1 day'));
print '<a href="' . DOL_URL_ROOT . '/custom/kreaflow/zspunchclock/zspunchclock_list.php?search_date=' . urlencode($yesterday) . '" style="text-decoration: none; border: none; background: none; padding: 8px 15px; margin: 0 5px; font-size: 16px; color: #666;">';
print '<i class="fa fa-chevron-left"></i>';
print '</a>';

// Current date display (Today)
print ' <span style="margin: 0 20px; font-weight: bold; font-size: 18px;">' . dol_print_date(strtotime($selectedDate), 'day') . '</span> ';

print '</div>';

print '<div class="div-table-responsive">';
print '<table class="tagtable nobottomiftotal liste">';

// Fields title
print '<tr class="liste_titre">';
print '<th class="liste_titre" style="width: 240px;">' . $langs->trans("Employee") . '</th>';
print '<th class="liste_titre" style="width: 80px;">' . $langs->trans("EmpID") . '</th>';
print '<th class="liste_titre" style="width: 50px; text-align: center;">' . $langs->trans("DOLIZSYNCH_SOURCE_LABEL") . '</th>';
print '<th class="liste_titre" style="width: 80px; text-align: right; padding-right: 24px;">' . $langs->trans("TimeHour") . '</th>';
print '<th class="liste_titre" style="width: 90px; padding-left: 24px;">' . $langs->trans("DOLIZSYNCH_DIRECTION_LABEL") . '</th>';
print '<th class="liste_titre" style="width: 55px; text-align: right;">' . $langs->trans("zspunchclock_actions") . '</th>';
print '</tr>';

// Search row
print '<tr class="liste_titre">';
print '<td class="liste_titre" style="width: 240px;"><input type="text" class="flat" style="width: 100%;" name="search_fullname" value="' . dol_escape_htmltag($search_fullname) . '"></td>';
print '<td class="liste_titre" style="width: 80px;"><input type="text" class="flat" style="width: 100%;" name="search_empid_zs" value="' . dol_escape_htmltag($search_empid_zs) . '"></td>';
print '<td class="liste_titre" style="width: 50px;"></td>';
print '<td class="liste_titre" style="width: 80px; text-align: right; padding-right: 24px;"></td>';
print '<td class="liste_titre" style="width: 90px; padding-left: 24px;">';
print '<select name="search_sentido" class="flat" style="width: 100%;">';
print '<option value=""></option>';
print '<option value="1"' . ($search_sentido == '1' ? ' selected' : '') . '>Entrada</option>';
print '<option value="2"' . ($search_sentido == '2' ? ' selected' : '') . '>Saida</option>';
print '</select>';
print '</td>';
print '<td class="liste_titre right" style="width: 55px;">';
print '<button type="submit" class="liste_titre button_search" name="button_search" value="x"><span class="fa fa-search"></span></button>';
print '<button type="submit" class="liste_titre button_removefilter" name="button_removefilter" value="x"><span class="fa fa-remove"></span></button>';
print '</td>';
print '</tr>';

// Prepare employee select options (reused in PHP render and JS)
$employeeOptionsData = array();
$employeeOptionsHtml = '<option value="0">-- Selecionar Funcionário --</option>';
$sqlUsers = "SELECT u.rowid, CONCAT(u.firstname, ' ', u.lastname) as name, ue.zs_employee_id ";
$sqlUsers .= "FROM " . MAIN_DB_PREFIX . "user as u ";
$sqlUsers .= "LEFT JOIN " . MAIN_DB_PREFIX . "user_extrafields as ue ON u.rowid = ue.fk_object ";
$sqlUsers .= "WHERE ue.zs_employee_id IS NOT NULL AND ue.zs_employee_id > 0 ";
$sqlUsers .= "ORDER BY u.firstname, u.lastname";
$resUsers = $db->query($sqlUsers);
if ($resUsers) {
	while ($userRow = $db->fetch_object($resUsers)) {
		$employeeId = (int) $userRow->zs_employee_id;
		$employeeName = $userRow->name;
		$employeeOptionsData[] = array(
			'id' => $employeeId,
			'name' => $employeeName
		);
		$employeeOptionsHtml .= '<option value="' . $employeeId . '">' . dol_escape_htmltag($employeeName) . '</option>';
	}
}

// Data rows
$i = 0;
while ($i < min($num, $limit)) {
	$obj = $db->fetch_object($resql);
	if ($obj) {
		// Check if this is a ZS BMS entry (read-only)
		$is_zsbms = !empty($obj->zsbms_synch);

		print '<tr class="oddeven" data-rowid="' . $obj->rowid . '" data-zsbms="' . ($is_zsbms ? '1' : '0') . '" data-empid="' . (int) $obj->empid_zs . '" data-dataval="' . dol_escape_htmltag($obj->dataval) . '" data-empname="' . dol_escape_htmltag($obj->fullname) . '" data-placeholder="00:00">';

		// Employee name (editable dropdown if empid_zs = 0 AND not from ZS BMS)
		print '<td style="width:240px;">';
		if ($obj->empid_zs == 0 && !$is_zsbms) {
			// Show dropdown to select employee
			print '<select id="employee_selector_' . $obj->rowid . '" name="employee_selector_' . $obj->rowid . '" class="flat employee-selector" data-id="' . $obj->rowid . '" style="width: 100%;">';
			print $employeeOptionsHtml;
			print '</select>';
		} else {
			print dol_escape_htmltag($obj->fullname);
		}
		print '</td>';

		// Employee ID (show as text, calculated from employee selection)
		print '<td style="width:80px;">';
		if ($obj->empid_zs == 0) {
			print '<em style="color: #999;">--</em>';
		} else {
			print $obj->empid_zs;
		}
		print '</td>';

		// ZS indicator
		print '<td class="center" style="width:50px;">';
		if ($is_zsbms) {
			print '<span class="badge badge-status4" style="background-color: #28a745;" title="Imported from ZS BMS">ZS</span>';
		} else {
			// Show username of person who modified the record
			if (!empty($obj->modif_login)) {
				print '<span class="badge badge-status4" style="background-color: #ffc107 !important; font-size: 0.85em;" title="' . dol_escape_htmltag($obj->modif_fullname) . '">' . dol_escape_htmltag($obj->modif_login) . '</span>';
			} else {
				print '<span style="color: #999;">--</span>';
			}
		}
		print '</td>';

		// Time (editing a ZS BMS entry will generate a manual copy)
		$timeCellStyle = $is_zsbms ? 'color:#666;' : '';
		print '<td class="editable-field" data-id="' . $obj->rowid . '" data-field="datahora" style="width:80px; text-align: right; padding-right: 24px;' . $timeCellStyle . '">';
		if (!empty($obj->datahora)) {
			$datavalDate = '';
			if (!empty($obj->dataval)) {
				if (is_numeric($obj->dataval)) {
					$datavalDate = date('Y-m-d', $obj->dataval);
				} else {
					$dvts = strtotime($obj->dataval);
					if ($dvts) {
						$datavalDate = date('Y-m-d', $dvts);
					}
				}
			}
			if (empty($datavalDate)) {
				$datavalDate = $selectedDate;
			}
			$datahoraTs = is_numeric($obj->datahora) ? (int) $obj->datahora : strtotime($obj->datahora);
			if ($datahoraTs) {
				$timeDisplay = date('H:i', $datahoraTs);
				$datahoraDate = date('Y-m-d', $datahoraTs);
				if (!empty($datavalDate) && $datahoraDate > $datavalDate) {
					$timeDisplay = '+' . $timeDisplay;
				}
				print $timeDisplay;
			}
		}
		print '</td>';

		// Direction/Sentido (editable)
		$sentidoCellStyle = $is_zsbms ? 'color:#666;' : '';
		print '<td class="editable-field" data-id="' . $obj->rowid . '" data-field="sentido" style="width:90px; padding-left: 24px;' . $sentidoCellStyle . '">';
		print $obj->sentido == 1 ? 'Entrada' : 'Saida';
		print '</td>';

		// Actions (+ and - buttons)
		print '<td class="right" style="white-space: nowrap; width:55px;">';
		if ($user->rights->dolizsynch->zspunchclock->write) {
			// Plus button to add new row below (always enabled)
			print '<a href="#" class="js-row-action" data-action="add" title="Adicionar novo registo abaixo" style="margin-right: 8px;">';
			print '<span class="fas fa-plus" style="color: #28a745;"></span>';
			print '</a>';

			// Minus button - for ZS BMS entries, mark as deleted; for manual entries, actually delete
			if ($is_zsbms) {
				print '<a href="#" class="js-row-action" data-action="mark" title="Marcar como eliminado (ZS BMS)">';
				print '<span class="fas fa-ban" style="color: #ffc107;"></span>';
				print '</a>';
			} else {
				print '<a href="#" class="js-row-action" data-action="delete" title="Eliminar registo">';
				print '<span class="fas fa-minus" style="color: #dc3545;"></span>';
				print '</a>';
			}
		}
		print '</td>';

		print '</tr>';
	}
	$i++;
}

// No bottom form - use + buttons on rows to add new entries

print '</table>';
print '</div>';

print '</form>';

// Add CSS styles for inline editing
?>

<style>
	/* Inline edit field styles */
	.editable-field {
		cursor: pointer;
		padding: 4px 8px;
		border-radius: 3px;
		transition: all 0.2s ease;
	}

	.editable-field:hover {
		background-color: #f0f0f0;
	}

	.editable-field input {
		width: 100%;
		padding: 4px 8px;
		font-size: inherit;
		font-family: inherit;
		text-align: center;
		border: 1px solid #ccc;
		border-radius: 3px;
		box-sizing: border-box;
	}

	/* Edit mode states - green frame like zspunchclock_employee.php */
	.editable-field.editing input,
	.editable-field input:focus {
		background: #d4edda !important;
		border: 2px solid #28a745 !important;
		outline: none !important;
		box-shadow: 0 0 5px rgba(40, 167, 69, 0.3) !important;
	}

	.editable-field.saving {
		background: #d1ecf1 !important;
	}

	.editable-field.saving input {
		background: #d1ecf1 !important;
		border: 2px solid #17a2b8 !important;
		pointer-events: none;
	}

	.editable-field.success {
		background: #d4edda !important;
	}

	.editable-field.success input {
		background: #d4edda !important;
		border: 2px solid #28a745 !important;
		box-shadow: 0 0 10px rgba(40, 167, 69, 0.6) !important;
		animation: successPulse 0.5s ease-in-out;
	}

	.editable-field.error {
		background: #f8d7da !important;
	}

	.editable-field.error input {
		background: #f8d7da !important;
		border: 2px solid #dc3545 !important;
	}

	@keyframes successPulse {

		0%,
		100% {
			box-shadow: 0 0 5px rgba(40, 167, 69, 0.3);
		}

		50% {
			box-shadow: 0 0 15px rgba(40, 167, 69, 0.8);
		}
	}

	/* Employee selector styling */
	.employee-selector {
		width: 100%;
		padding: 2px 4px;
	}

	/* Keep names/IDs black; only de-emphasize non-editable ZS badge */
	table.liste tr.oddeven td {
		color: #000 !important;
	}
</style>

<script type="text/javascript">
	var CSRF_TOKEN = '<?php echo newToken(); ?>';
	var EMPLOYEE_OPTIONS =
		<?php echo json_encode($employeeOptionsData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;

	function initEmployeeSelect($select) {
		if (typeof jQuery === 'undefined' || typeof jQuery.fn.select2 !== 'function') {
			return;
		}
		$select.each(function() {
			var $el = jQuery(this);
			if ($el.data('select2')) {
				return;
			}
			$el.select2({
				dir: 'ltr',
				width: 'resolve',
				minimumInputLength: 0,
				language: (typeof select2arrayoflanguage === 'undefined') ? 'en' : select2arrayoflanguage,
				matcher: function(params, data) {
					if (jQuery.trim(params.term) === '') {
						return data;
					}
					var keywords = params.term.split(' ');
					for (var i = 0; i < keywords.length; i++) {
						if ((data.text || '').toUpperCase().indexOf(keywords[i].toUpperCase()) === -1) {
							return null;
						}
					}
					return data;
				},
				theme: 'default',
				containerCssClass: ':all:',
				selectionCssClass: ':all:',
				dropdownCssClass: 'ui-dialog',
				templateResult: function(data, container) {
					if (data.element) {
						jQuery(container).addClass(jQuery(data.element).attr('class'));
					}
					if (data.id === '0' && jQuery(data.element).attr('data-html') === undefined) {
						return '&nbsp;';
					}
					if (jQuery(data.element).attr('data-html') !== undefined && typeof htmlEntityDecodeJs === 'function') {
						return htmlEntityDecodeJs(jQuery(data.element).attr('data-html'));
					}
					return data.text;
				},
				templateSelection: function(selection) {
					if (selection.id === '0') {
						return '<span class="placeholder">' + selection.text + '</span>';
					}
					return selection.text;
				},
				escapeMarkup: function(markup) {
					return markup;
				}
			});
		});
	}

	function escapeHtml(str) {
		if (typeof str !== 'string') {
			return '';
		}
		return str.replace(/[&<>"']/g, function(match) {
			switch (match) {
				case '&':
					return '&amp;';
				case '<':
					return '&lt;';
				case '>':
					return '&gt;';
				case '"':
					return '&quot;';
				case '\'':
					return '&#039;';
				default:
					return match;
			}
		});
	}

	function fetchPunchSuggestion(empId, rowId) {
		if (!empId) {
			return jQuery.Deferred().resolve({
				success: true,
				placeholder: '00:00',
				direction_label: 'Entrada',
				direction_value: 1
			}).promise();
		}
		return jQuery.ajax({
			url: '<?php echo $_SERVER["PHP_SELF"]; ?>',
			type: 'POST',
			dataType: 'json',
			data: {
				action: 'fetch_last_time',
				ajax: 1,
				empid: empId,
				rowid: rowId,
				token: CSRF_TOKEN
			}
		});
	}

	function applyPunchSuggestion($row, info) {
		info = info || {};
		var placeholder = info.placeholder || '00:00';
		$row.attr('data-placeholder', placeholder);
		var $timeCell = $row.find('td').eq(3);
		$timeCell.attr('data-placeholder', placeholder);
		var $input = $timeCell.find('input');
		if ($input.length > 0) {
			if (!$input.val() || $input.val() === '00:00') {
				$input.val(placeholder);
			}
			$input.attr('placeholder', placeholder);
		} else {
			var currentValue = ($timeCell.text() || '').trim();
			if (!currentValue || currentValue === '00:00') {
				$timeCell.text(placeholder);
			}
		}
		if (info.direction_label) {
			var $directionCell = $row.find('td').eq(4);
			if (($row.attr('data-empid') || '0') === '0' || $directionCell.text().trim() === '--') {
				$directionCell.text(info.direction_label);
			}
		}
	}

	function handleEmployeeSelection($select) {
		var $row = $select.closest('tr');
		var rowId = $row.data('rowid');
		var previousEmp = parseInt($row.attr('data-empid') || 0, 10);
		var previousName = ($row.attr('data-empname') || '').toString();
		var empId = parseInt($select.val(), 10) || 0;
		var originalDirection = $row.find('td').eq(4).text();
		var originalPlaceholder = $row.attr('data-placeholder') || '00:00';
		var originalTimeText = ($row.find('td').eq(3).text() || '').trim();

		if (!empId) {
			$row.attr('data-empid', 0);
			$row.attr('data-empname', '');
			$row.find('td').eq(1).html('<em style="color: #999;">--</em>');
			$row.find('td').eq(4).text('--');
			applyPunchSuggestion($row, {
				placeholder: '00:00'
			});
			return;
		}

		fetchPunchSuggestion(empId, rowId).done(function(info) {
			if (info && info.success) {
				applyPunchSuggestion($row, info);
			}

			var placeholder = (info && info.placeholder) ? info.placeholder : '00:00';
			var $timeCell = $row.find('td').eq(3);

			var revertSelection = function() {
				$select.val(previousEmp ? previousEmp : '0');
				if (previousEmp) {
					$select.find('option').each(function() {
						if (parseInt(this.value, 10) === previousEmp) {
							jQuery(this).prop('selected', true);
						}
					});
				}
				$row.find('td').eq(4).text(originalDirection);
				$row.attr('data-empname', previousName);
				$row.attr('data-placeholder', originalPlaceholder);
				$row.find('td').eq(3).attr('data-placeholder', originalPlaceholder);
				if (originalTimeText) {
					$timeCell.text(originalTimeText);
				} else {
					$timeCell.text('00:00');
				}
			};

			var finalizeSelection = function() {
				jQuery.ajax({
					url: '<?php echo $_SERVER["PHP_SELF"]; ?>',
					type: 'POST',
					dataType: 'json',
					data: {
						action: 'save_inline',
						ajax: 1,
						id: rowId,
						field: 'empid_zs',
						value: empId,
						token: CSRF_TOKEN
					},
					success: function(response) {
						if (response.success) {
							$row.attr('data-empid', empId);
							var selectedName = ($select.find('option:selected').text() || '')
								.trim();
							$row.attr('data-empname', selectedName);
							$row.find('td').eq(1).text(empId);
							if (response.direction) {
								$row.find('td').eq(4).text(response.direction);
							}
							if (response.placeholder) {
								applyPunchSuggestion($row, {
									placeholder: response.placeholder
								});
							}
						} else {
							alert('Error: ' + (response.error || 'Unknown error'));
							revertSelection();
						}
					},
					error: function() {
						alert('AJAX request failed');
						revertSelection();
					}
				});
			};

			if ((!originalTimeText || originalTimeText === '00:00') && placeholder && placeholder !== '00:00') {
				jQuery.ajax({
					url: '<?php echo $_SERVER["PHP_SELF"]; ?>',
					type: 'POST',
					dataType: 'json',
					data: {
						action: 'save_inline',
						ajax: 1,
						id: rowId,
						field: 'datahora',
						value: placeholder,
						token: CSRF_TOKEN
					},
					success: function(timeResponse) {
						if (timeResponse.success) {
							$timeCell.text(timeResponse.value);
							originalTimeText = timeResponse.value;
							if (timeResponse.direction) {
								$row.find('td').eq(4).text(timeResponse.direction);
							}
							if (timeResponse.placeholder) {
								applyPunchSuggestion($row, {
									placeholder: timeResponse.placeholder
								});
							} else {
								applyPunchSuggestion($row, {
									placeholder: placeholder
								});
							}
							finalizeSelection();
						} else {
							alert('Error: ' + (timeResponse.error || 'Unknown error'));
							revertSelection();
						}
					},
					error: function() {
						alert('AJAX request failed');
						revertSelection();
					}
				});
			} else {
				finalizeSelection();
			}
		});
	}

	jQuery(document).on('change', '.employee-selector', function() {
		handleEmployeeSelection(jQuery(this));
	});

	jQuery(document).on('click', '.js-row-action', function(e) {
		e.preventDefault();
		var $link = jQuery(this);
		var action = $link.data('action');
		var $row = $link.closest('tr');
		var rowId = parseInt($row.data('rowid'), 10);
		var dateIso = ($row.data('dataval') || '').toString();
		var empId = parseInt($row.data('empid'), 10) || 0;
		var empName = ($row.attr('data-empname') || '').toString().trim();

		if (!empName) {
			var $nameCell = $row.find('td').eq(0);
			if ($nameCell.find('select').length) {
				empName = ($nameCell.find('option:selected').text() || '').trim();
			} else {
				empName = ($nameCell.text() || '').trim();
			}
		}

		if (action === 'add') {
			addNewRowBelow(rowId, dateIso, empId, empName);
		} else if (action === 'delete') {
			deleteEntireRow(rowId, empName);
		} else if (action === 'mark') {
			markAsDeleted(rowId, empName);
		}
	});

	jQuery(document).on('click', '.editable-field', function() {
		var $cell = jQuery(this);

		// Don't create new input if already editing
		if ($cell.find('input, select').length > 0) {
			console.log('Already editing this cell');
			return;
		}

		console.log('Editable field clicked');

		var currentValue = $cell.text().trim();
		var field = $cell.data('field');
		var id = $cell.data('id');
		var $row = $cell.closest('tr');
		var $input;
		var displayValue = currentValue;
		var normalizedValue = currentValue;
		if (field === 'datahora' && currentValue.indexOf('+') === 0) {
			normalizedValue = currentValue.substring(1);
		}

		console.log('Field:', field, 'ID:', id, 'Current value:', currentValue);

		if (field === 'datahora') {
			var empId = parseInt($row.attr('data-empid') || 0, 10);
			var placeholder = $row.attr('data-placeholder') || $cell.attr('data-placeholder') || '00:00';
			$input = jQuery('<input type="time" class="flat edit-input">').val(normalizedValue);
			if (placeholder) {
				$input.attr('placeholder', placeholder);
			}
			if (empId) {
				fetchPunchSuggestion(empId, id).done(function(info) {
					if (info && info.success) {
						applyPunchSuggestion($row, info);
						if (info.placeholder) {
							$input.attr('placeholder', info.placeholder);
							if (!currentValue || currentValue === '00:00') {
								$input.val(info.placeholder);
							}
						}
					}
				});
			}
		} else if (field === 'empid_zs') {
			$input = jQuery('<input type="number" class="flat edit-input">').val(currentValue);
		} else if (field === 'sentido') {
			// Direction field - dropdown with Entrada/Saida
			$input = jQuery('<select class="flat edit-input"><option value="1"' + (currentValue === 'Entrada' ?
				' selected' : '') + '>Entrada</option><option value="2"' + (currentValue === 'Saida' ?
				' selected' : '') + '>Saida</option></select>');
		} else {
			$input = jQuery('<input type="text" class="flat edit-input">').val(currentValue);
		}

		console.log('Creating input element');
		$cell.html($input);
		$cell.addClass('editing');

		console.log('Focusing input');
		$input.focus();

		// Save function - reusable for both input and select fields
		function saveFieldValue(newValue) {
			console.log('Save triggered - Old:', currentValue, 'New:', newValue);
			var compareValue = (field === 'datahora') ? normalizedValue : currentValue;

			// Skip if value hasn't changed
			if (newValue === compareValue) {
				console.log('Value unchanged, skipping save');
				$cell.removeClass('editing').text(displayValue);
				return;
			}

			// Show saving state
			console.log('Showing saving state');
			$cell.removeClass('editing').addClass('saving');
			$input.prop('disabled', true);

			jQuery.ajax({
				url: '<?php echo $_SERVER["PHP_SELF"]; ?>',
				type: 'POST',
				dataType: 'json',
				data: {
					action: 'save_inline',
					ajax: 1,
					id: id,
					field: field,
					value: newValue,
					token: CSRF_TOKEN
				},
				success: function(response) {
					console.log('AJAX response received:', response);

					if (response.success) {
						console.log('Save successful! Showing success state and reloading...');
						// Show success state briefly, then reload page
						$cell.removeClass('saving').addClass('success');

						// Reload page after brief success indication
						setTimeout(function() {
							console.log('Reloading page...');
							window.location.reload();
						}, 500);
					} else {
						console.error('Save failed:', response.error);
						// Show error
						$cell.removeClass('saving').addClass('error');
						alert('Error: ' + (response.error || 'Unknown error'));

						// Restore original value
						setTimeout(function() {
							$cell.removeClass('error').text(currentValue);
						}, 2000);
					}
				},
				error: function(xhr, status, error) {
					console.error('AJAX request failed:', status, error);
					console.error('Response:', xhr.responseText);
					$cell.removeClass('saving').addClass('error');
					alert('AJAX request failed: ' + error);

					// Restore original value
					setTimeout(function() {
						$cell.removeClass('error').text(currentValue);
					}, 2000);
				}
			});
		}

		// For select fields, save immediately on change
		if (field === 'sentido') {
			$input.on('change', function(e) {
				e.preventDefault();
				var newValue = $input.val();
				saveFieldValue(newValue);
			});
		}

		// For input fields, save on blur or Enter
		var isSaving = false; // Flag to prevent double-save
		$input.on('blur keypress', function(e) {
			if (e.type === 'blur' || e.which === 13) {
				if (isSaving) return; // Already saving, ignore

				if (e.which === 13) {
					e.preventDefault();
					$input.blur(); // Manually trigger blur, which will handle the save
					return;
				}

				if (e.type === 'blur') {
					isSaving = true; // Set flag to prevent re-entry
					var newValue = $input.val();
					saveFieldValue(newValue);
				}
			}
		});
	});

	function buildEditableRow(rowid, dateIso, empId, empName) {
		empId = parseInt(empId, 10) || 0;
		empName = empName || '';
		dateIso = dateIso || '';

		var optionMarkup = '<option value="0">-- Selecionar Funcionário --</option>';
		EMPLOYEE_OPTIONS.forEach(function(opt) {
			var optionId = parseInt(opt.id, 10) || 0;
			var selected = optionId === empId ? ' selected' : '';
			optionMarkup += '<option value="' + optionId + '"' + selected + '>' + escapeHtml(opt.name || '') +
				'</option>';
		});

		var employeeColumn = empId ? String(empId) : '<em style="color: #999;">--</em>';

		var html = '';
		html += '<tr class="oddeven" data-rowid="' + rowid + '" data-zsbms="0" data-empid="' + empId + '" data-empname="' +
			escapeHtml(empName) + '" data-dataval="' + dateIso + '" data-placeholder="00:00">';
		html += '<td style="width:240px;"><select id="employee_selector_' + rowid + '" name="employee_selector_' + rowid + '" class="flat employee-selector" data-id="' + rowid +
			'" style="width: 100%;">' + optionMarkup + '</select></td>';
		html += '<td style="width:80px;">' + employeeColumn + '</td>';
		html += '<td class="center" style="width:50px;"><span style="color: #999;">--</span></td>';
		html += '<td class="editable-field" data-id="' + rowid + '" data-field="datahora" style="width:80px; text-align: right; padding-right: 24px;">00:00</td>';
		html += '<td class="editable-field" data-id="' + rowid + '" data-field="sentido" style="width:90px; padding-left: 24px;">--</td>';
		html += '<td class="right" style="white-space: nowrap; width:55px;">';
		html +=
			'<a href="#" class="js-row-action" data-action="add" title="Adicionar novo registo abaixo" style="margin-right: 8px;"><span class="fas fa-plus" style="color: #28a745;"></span></a>';
		html +=
			'<a href="#" class="js-row-action" data-action="delete" title="Eliminar registo"><span class="fas fa-minus" style="color: #dc3545;"></span></a>';
		html += '</td></tr>';
		return html;
	}

	function addNewRowBelow(clickedRowId, dateIso, empId, empName) {
		if (!dateIso) {
			alert('Data inválida para o novo registo.');
			return;
		}
		jQuery.ajax({
			url: '<?php echo $_SERVER["PHP_SELF"]; ?>',
			type: 'POST',
			dataType: 'json',
			data: {
				action: 'create_inline',
				ajax: 1,
				token: CSRF_TOKEN,
				dataval: dateIso,
				clicked_rowid: clickedRowId
			},
			success: function(response) {
				if (response && response.success) {
					var newRowHtml = buildEditableRow(response.id, dateIso, empId, empName);
					var $clickedRow = jQuery('tr[data-rowid="' + clickedRowId + '"]');
					if ($clickedRow.length > 0) {
						$clickedRow.after(newRowHtml);
						var $newRow = jQuery('tr[data-rowid="' + response.id + '"]');
						$newRow.css('background-color', '#d4edda');
						setTimeout(function() {
							$newRow.css('background-color', '');
						}, 2000);
						jQuery('html, body').animate({
							scrollTop: $newRow.offset().top - 100
						}, 500);
						initEmployeeSelect($newRow.find('.employee-selector'));
						if (empId) {
							var $select = $newRow.find('.employee-selector');
							if ($select.length > 0) {
								$select.val(String(empId));
								if ($select.val() !== String(empId)) {
									$select.find('option').each(function() {
										if (parseInt(this.value, 10) === empId) {
											jQuery(this).prop('selected', true);
										}
									});
								}
								$select.trigger('change');
							}
						}
					} else {
						window.location.reload();
					}
				} else {
					alert('Erro: ' + (response && response.error ? response.error : 'Erro desconhecido'));
				}
			},
			error: function(xhr, status, error) {
				console.error('AJAX error:', error);
				alert('Erro de rede: ' + error);
			}
		});
	}

	function deleteEntireRow(rowid, employeeName) {
		if (!rowid) {
			alert('Registo inválido.');
			return;
		}
		var label = employeeName ? ' de ' + employeeName : '';
		if (!confirm('Tem certeza que deseja eliminar o registo' + label + '?')) {
			return;
		}
		jQuery.ajax({
			url: '<?php echo $_SERVER["PHP_SELF"]; ?>',
			type: 'POST',
			dataType: 'json',
			data: {
				action: 'delete_entire_row',
				ajax: 1,
				token: CSRF_TOKEN,
				rowid: rowid
			},
			success: function(response) {
				if (response && response.success) {
					window.location.reload();
				} else {
					alert('Erro: ' + (response && response.error ? response.error : 'Erro desconhecido'));
				}
			},
			error: function(xhr, status, error) {
				alert('Erro de rede: ' + error);
			}
		});
	}

	function markAsDeleted(rowid, employeeName) {
		if (!rowid) {
			alert('Registo inválido.');
			return;
		}
		var label = employeeName ? ' de ' + employeeName : '';
		if (!confirm('Tem certeza que deseja marcar o registo' + label + ' como eliminado?')) {
			return;
		}
		jQuery.ajax({
			url: '<?php echo $_SERVER["PHP_SELF"]; ?>',
			type: 'POST',
			dataType: 'json',
			data: {
				action: 'mark_as_deleted',
				ajax: 1,
				token: CSRF_TOKEN,
				rowid: rowid
			},
			success: function(response) {
				if (response && response.success) {
					window.location.reload();
				} else {
					alert('Erro: ' + (response && response.error ? response.error : 'Erro desconhecido'));
				}
			},
			error: function(xhr, status, error) {
				alert('Erro de rede: ' + error);
			}
		});
	}

	jQuery(document).ready(function() {
		console.log('Document ready - ZS Punch Clock Synch List initialized');
		console.log('Editable fields found:', jQuery('.editable-field').length);
		initEmployeeSelect(jQuery('.employee-selector'));

		// Test click on editable fields
		jQuery('.editable-field').each(function(index) {
			var $field = jQuery(this);
			console.log('Editable field #' + index + ':', 'field=' + $field.data('field'), 'id=' + $field
				.data('id'), 'text=' + $field.text());
		});

		var urlParams = new URLSearchParams(window.location.search);
		var newRowId = urlParams.get('newrow');
		if (newRowId) {
			var $newRow = jQuery('tr[data-rowid="' + newRowId + '"]');
			if ($newRow.length > 0) {
				$newRow.css('background-color', '#d4edda');
				setTimeout(function() {
					$newRow.css('background-color', '');
				}, 2000);
				jQuery('html, body').animate({
					scrollTop: $newRow.offset().top - 100
				}, 500);
			}
			setTimeout(function() {
				var url = new URL(window.location);
				url.searchParams.delete('newrow');
				window.history.replaceState({}, document.title, url);
			}, 2000);
		}
	});

	jQuery(document).on('select2:open', '.employee-selector', function() {
		var $search = jQuery('.select2-container--open .select2-search__field');
		if ($search.length && !$search.val()) {
			$search.trigger('input');
		}
	});
</script>


<?php

$db->free($resql);

llxFooter();
$db->close();

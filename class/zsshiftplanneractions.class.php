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
 * Encapsulates the procedural actions from zsshift.class.php.
 * This is a temporary class to allow gradual removal of global functions.
 */
require_once __DIR__ . '/../lib/shiftplanner.lib.php';

class ZSShiftplannerActions
{
	/**
	 * Field definitions for leave records managed by the module table.
	 * Kept public to allow reuse in forms or listings without duplicating metadata.
	 *
	 * @var array
	 */
	public $fields = array(
		'rowid' => array('type' => 'integer', 'label' => 'Id', 'enabled' => 1, 'visible' => 1),
		'fk_user' => array('type' => 'integer', 'label' => 'User', 'enabled' => 1, 'visible' => 1),
		'fk_type' => array('type' => 'integer', 'label' => 'Type', 'enabled' => 1, 'visible' => 1),
		'date_start' => array('type' => 'datetime', 'label' => 'DateStart', 'enabled' => 1, 'visible' => 1),
		'date_end' => array('type' => 'datetime', 'label' => 'DateEnd', 'enabled' => 1, 'visible' => 1),
		'halfday' => array('type' => 'integer', 'label' => 'Halfday', 'enabled' => 1, 'visible' => 1),
		'minutes_total' => array('type' => 'integer', 'label' => 'MinutesTotal', 'enabled' => 1, 'visible' => 1),
		'description' => array('type' => 'text', 'label' => 'Description', 'enabled' => 1, 'visible' => 1),
		'day_change_hour' => array('type' => 'integer', 'label' => 'DayChangeHour', 'enabled' => 1, 'visible' => 0),
		'entity' => array('type' => 'integer', 'label' => 'Entity', 'enabled' => 1, 'visible' => 0),
		'created_by' => array('type' => 'integer', 'label' => 'CreatedBy', 'enabled' => 1, 'visible' => 0),
		'fk_user_modif' => array('type' => 'integer', 'label' => 'ModifiedBy', 'enabled' => 1, 'visible' => 0),
		'date_creation' => array('type' => 'datetime', 'label' => 'DateCreation', 'enabled' => 1, 'visible' => 0),
		'tms' => array('type' => 'timestamp', 'label' => 'Tms', 'enabled' => 1, 'visible' => 0),
	);

	private static function sanitizeColor($value)
	{
		$val = trim((string) $value);
		if ($val === '') return '';
		// Allow #RGB or #RRGGBB, or the same without the leading '#'
		if (preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $val)) {
			return strtoupper($val);
		}
		if (preg_match('/^([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $val)) {
			return '#' . strtoupper($val);
		}
		return '';
	}

	private static function normalizeShiftStatus($value)
	{
		$status = strtolower(trim((string) $value));
		if ($status === 'missed' || $status === 'missed_unjustified' || $status === 'missed_justified') {
			return $status;
		}
		return 'scheduled';
	}

	private static function normalizeLeaveTypeKey($value)
	{
		$key = trim((string) $value);
		if ($key === '') {
			return '';
		}
		if (function_exists('dol_string_unaccent')) {
			$key = dol_string_unaccent($key);
		} elseif (function_exists('iconv')) {
			$tmp = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $key);
			if ($tmp !== false) {
				$key = $tmp;
			}
		}
		$key = preg_replace('/[^A-Za-z0-9]+/', '_', $key);
		$key = preg_replace('/_{2,}/', '_', $key);
		$key = trim((string) $key, '_');
		return strtoupper($key);
	}

	private static function resolveMissedShiftStatusFromLeaveType($code, $label = '')
	{
		$codeKey = self::normalizeLeaveTypeKey($code);
		$labelKey = self::normalizeLeaveTypeKey($label);

		if ($codeKey === 'FALTA_INJUS' || $codeKey === 'FALTA_INJUSTIFICADA') {
			return 'missed_unjustified';
		}
		if ($codeKey === 'FALTA_JUS' || $codeKey === 'FALTA_JUSTIFICADA') {
			return 'missed_justified';
		}
		if ($labelKey !== '') {
			if (strpos($labelKey, 'FALTA_INJUS') !== false || strpos($labelKey, 'FALTA_INJUSTIFICADA') !== false) {
				return 'missed_unjustified';
			}
			if (strpos($labelKey, 'FALTA_JUS') !== false || strpos($labelKey, 'FALTA_JUSTIFICADA') !== false) {
				return 'missed_justified';
			}
		}

		return '';
	}

	public static function logScheduleChange($action, User $user, array $context = array(), $level = LOG_INFO)
	{
		global $db, $conf;

		$context['user_id'] = (int) $user->id;
		$context['user_login'] = $user->login ?? '';
		$context['entity'] = (int) ($user->entity ?? 0);
		$contextJson = !empty($context) ? json_encode($context, JSON_UNESCAPED_UNICODE) : '';
		if ($contextJson === false) {
			$contextJson = '';
		}
		$contextStr = $contextJson !== '' ? ' | Context: ' . $contextJson : '';
		dol_syslog('[ZSLOG] SCHEDULE: ' . $action . $contextStr, $level);

		if (empty($db) || empty($conf)) {
			return;
		}

		$objectType = 'schedule';
		$objectId = null;
		if (strpos($action, 'shift_') === 0) {
			$objectType = 'shift';
			$objectId = isset($context['shift_id']) ? (int) $context['shift_id'] : null;
		} elseif (strpos($action, 'leave_') === 0) {
			$objectType = 'leave';
			$objectId = isset($context['leave_id']) ? (int) $context['leave_id'] : null;
		} elseif (strpos($action, 'dayoff_') === 0) {
			$objectType = 'dayoff';
			$objectId = isset($context['dayoff_id']) ? (int) $context['dayoff_id'] : null;
		} elseif (strpos($action, 'week_') === 0) {
			$objectType = 'week';
		}

		$targetUserId = null;
		if (!empty($context['employee_id'])) {
			$targetUserId = (int) $context['employee_id'];
		}

		$sql = "INSERT INTO " . MAIN_DB_PREFIX . "dolizsynch_schedule_log";
		$sql .= " (entity, action, object_type, object_id, fk_user_actor, fk_user_target, context)";
		$sql .= " VALUES (";
		$sql .= (int) $conf->entity . ", ";
		$sql .= "'" . $db->escape($action) . "', ";
		$sql .= "'" . $db->escape($objectType) . "', ";
		$sql .= $objectId ? (int) $objectId : "NULL";
		$sql .= ", " . (int) $user->id;
		$sql .= ", " . ($targetUserId ? (int) $targetUserId : "NULL");
		$sql .= ", " . ($contextJson !== '' ? "'" . $db->escape($contextJson) . "'" : "NULL");
		$sql .= ")";

		if (!$db->query($sql)) {
			dol_syslog(__METHOD__ . ' failed to insert schedule log: ' . $db->lasterror(), LOG_ERR);
		}
	}

	public static function listResources(DoliDB $db)
	{
		global $conf;

		$groupIds = self::shiftplannerGetEmployeeGroupIds();
		$startIso = trim((string) GETPOST('start', 'alphanohtml'));
		$endIso = trim((string) GETPOST('end', 'alphanohtml'));
		$forcedIds = self::shiftplannerParseIdList(GETPOST('force_user_ids', 'alphanohtml'));
		$hiddenIds = self::shiftplannerParseIdList(GETPOST('hide_user_ids', 'alphanohtml'));
		$filterEmployment = false;
		if (
			$startIso !== '' &&
			$endIso !== '' &&
			preg_match('/^\d{4}-\d{2}-\d{2}$/', $startIso) &&
			preg_match('/^\d{4}-\d{2}-\d{2}$/', $endIso)
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
		$sql .= " AND ugu.entity = " . ((int) $conf->entity);
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
		if (!$resql) {
			if ($db->lasterrno() === 'DB_ERROR_NOSUCHTABLE') {
				dol_syslog(__METHOD__ . ' table missing, skipping day off check', LOG_WARNING);
				return false;
			}
			dol_syslog(__METHOD__ . ' query failed: ' . $db->lasterror(), LOG_ERR);
			http_response_code(500);
			echo json_encode(array('error' => 'Database error', 'details' => $db->lasterror()));
			exit;
		}

		$resources = array();
		while ($obj = $db->fetch_object($resql)) {
			if (!empty($obj->hideflag)) continue;
			$fullname = trim(($obj->firstname ?: '') . ' ' . ($obj->lastname ?: ''));
			if ($fullname === '') {
				$fullname = $obj->login ?: ('User ' . (int) $obj->rowid);
			}
			$expected = shiftplanner_resolve_contracted_hours($obj->horasefect ?? null, $obj->weeklyhours ?? null);
			$dateEmploymentIso = '';
			$dateEmploymentEndIso = '';
			if (!empty($obj->dateemployment)) {
				$dateEmploymentIso = dol_print_date($db->jdate($obj->dateemployment), '%Y-%m-%d');
			}
			if (!empty($obj->dateemploymentend)) {
				$dateEmploymentEndIso = dol_print_date($db->jdate($obj->dateemploymentend), '%Y-%m-%d');
			}
			$mainTaskId = null;
			if (isset($obj->zs_main_task) && is_numeric($obj->zs_main_task) && (int) $obj->zs_main_task > 0) {
				$mainTaskId = (int) $obj->zs_main_task;
			}
			$resources[] = array(
				'id' => (int) $obj->rowid,
				'title' => $fullname,
				'expectedHours' => $expected,
				'dateEmployment' => $dateEmploymentIso,
				'dateEmploymentEnd' => $dateEmploymentEndIso,
				'mainTaskId' => $mainTaskId,
			);
		}
		$db->free($resql);

		echo json_encode($resources);
		exit;
	}

	public static function requireShiftWriteRights(User $user)
	{
		if (empty($user->rights->dolizsynch->shift->write)) {
			http_response_code(403);
			echo json_encode(array('error' => 'Forbidden'));
			exit;
		}
	}

	public static function listEmployeePool(DoliDB $db)
	{
		global $conf, $user;

		self::requireShiftWriteRights($user);
		$groupIds = self::shiftplannerGetEmployeeGroupIds();
		if (empty($groupIds)) {
			http_response_code(409);
			echo json_encode(array('error' => 'missing_group'));
			exit;
		}

		$startIso = trim((string) GETPOST('start', 'alphanohtml'));
		$endIso = trim((string) GETPOST('end', 'alphanohtml'));
		$filterEmployment = false;
		if (
			$startIso !== '' &&
			$endIso !== '' &&
			preg_match('/^\d{4}-\d{2}-\d{2}$/', $startIso) &&
			preg_match('/^\d{4}-\d{2}-\d{2}$/', $endIso)
		) {
			$filterEmployment = true;
		}

		$sql = "SELECT u.rowid, u.firstname, u.lastname, u.login, u.email, u.entity,";
		$sql .= " CASE WHEN MAX(ugu.rowid) IS NULL THEN 0 ELSE 1 END AS is_assigned,";
		$sql .= " COALESCE(ue.zs_user_hide, 0) AS hideflag";
		$sql .= " FROM " . MAIN_DB_PREFIX . "user AS u";
		$sql .= " LEFT JOIN " . MAIN_DB_PREFIX . "usergroup_user AS ugu";
		$sql .= " ON ugu.fk_user = u.rowid";
		$sql .= " AND ugu.fk_usergroup IN (" . implode(',', $groupIds) . ")";
		$sql .= " AND ugu.entity = " . ((int) $conf->entity);
		$sql .= " LEFT JOIN " . MAIN_DB_PREFIX . "user_extrafields AS ue ON ue.fk_object = u.rowid";
		$sql .= " WHERE COALESCE(ue.zs_user_hide, 0) = 0";
		if ($filterEmployment) {
			$sql .= " AND (u.dateemployment IS NULL OR u.dateemployment = '0000-00-00' OR u.dateemployment <= '" . $db->escape($endIso) . "')";
			$sql .= " AND (u.dateemploymentend IS NULL OR u.dateemploymentend = '0000-00-00' OR u.dateemploymentend > '" . $db->escape($startIso) . "')";
		}
		$sql .= " GROUP BY u.rowid, u.firstname, u.lastname, u.login, u.email, u.entity, ue.zs_user_hide";
		$sql .= " ORDER BY u.lastname, u.firstname, u.rowid";

		$resql = $db->query($sql);
		if (!$resql) {
			http_response_code(500);
			echo json_encode(array('error' => 'Database error', 'details' => $db->lasterror()));
			exit;
		}

		$items = array();
		while ($obj = $db->fetch_object($resql)) {
			$label = trim(($obj->firstname ?: '') . ' ' . ($obj->lastname ?: ''));
			if ($label === '') {
				$label = $obj->login ?: ('User ' . (int) $obj->rowid);
			}
			$visibleStatus = ((int) $obj->hideflag === 1) ? 0 : 1;
			$items[] = array(
				'id' => (int) $obj->rowid,
				'label' => $label,
				'firstname' => $obj->firstname,
				'lastname' => $obj->lastname,
				'login' => $obj->login,
				'email' => $obj->email,
				'entity' => (int) $obj->entity,
				'status' => $visibleStatus,
				'isAssigned' => ((int) $obj->is_assigned === 1),
				'is_assigned' => ((int) $obj->is_assigned === 1),
			);
		}
		$db->free($resql);

		echo json_encode(array('users' => $items));
		exit;
	}

	public static function updateEmployeePool(DoliDB $db, User $currentUser)
	{
		global $conf;

		if (empty($currentUser->rights->dolizsynch->shift->write)) {
			http_response_code(403);
			echo json_encode(array('error' => 'Forbidden'));
			exit;
		}

		$ids = array();
		$idsProvided = false;
		$rawBody = file_get_contents('php://input');
		if ($rawBody !== '') {
			$payload = json_decode($rawBody, true);
			if (is_array($payload)) {
				if (array_key_exists('user_ids', $payload)) {
					$ids = $payload['user_ids'];
					$idsProvided = true;
				} elseif (array_key_exists('employee_ids', $payload)) {
					$ids = $payload['employee_ids'];
					$idsProvided = true;
				}
			}
		}
		if (!$idsProvided && function_exists('GETPOSTISSET')) {
			if (GETPOSTISSET('user_ids')) {
				$ids = GETPOST('user_ids', 'array');
				$idsProvided = true;
			} elseif (GETPOSTISSET('employee_ids')) {
				$ids = GETPOST('employee_ids', 'array');
				$idsProvided = true;
			}
		}
		if (!$idsProvided) {
			$raw = GETPOST('user_ids', 'alpha');
			if ($raw === '') {
				$raw = GETPOST('employee_ids', 'alpha');
			}
			if ($raw !== '') {
				$ids = explode(',', $raw);
				$idsProvided = true;
			}
		}
		if (!is_array($ids)) {
			$raw = is_string($ids) ? $ids : '';
			$ids = $raw !== '' ? explode(',', $raw) : array();
		}
		$ids = array_values(array_unique(array_filter(array_map('intval', $ids), function ($id) {
			return $id > 0;
		})));

		$groupId = (int) getDolGlobalInt('DOLIZSYNCH_SHIFT_EMPLOYEE_GROUP', 0);
		if ($groupId <= 0) {
			http_response_code(409);
			echo json_encode(array('error' => 'missing_group'));
			exit;
		}

		$db->begin();

		$sqlReset = "DELETE FROM " . MAIN_DB_PREFIX . "usergroup_user";
		$sqlReset .= " WHERE fk_usergroup = " . $groupId;
		$sqlReset .= " AND entity = " . ((int) $conf->entity);
		if (!$db->query($sqlReset)) {
			$db->rollback();
			http_response_code(500);
			echo json_encode(array('error' => 'Database error', 'details' => $db->lasterror()));
			exit;
		}

		foreach ($ids as $id) {
			$id = (int) $id;
			if ($id <= 0) continue;
			$sql = "INSERT INTO " . MAIN_DB_PREFIX . "usergroup_user (entity, fk_user, fk_usergroup)";
			$sql .= " VALUES (" . ((int) $conf->entity) . ", " . $id . ", " . $groupId . ")";
			if (!$db->query($sql)) {
				$db->rollback();
				http_response_code(500);
				echo json_encode(array('error' => 'Database error', 'details' => $db->lasterror()));
				exit;
			}
		}

		$db->commit();
		echo json_encode(array('success' => true));
		exit;
	}

	public static function updateEmployeeStatus(DoliDB $db, User $currentUser)
	{
		if (empty($currentUser->rights->dolizsynch->shift->write)) {
			http_response_code(403);
			echo json_encode(array('error' => 'Forbidden'));
			exit;
		}

		$id = 0;
		$newStatus = null;
		$rawBody = file_get_contents('php://input');
		if ($rawBody !== '') {
			$payload = json_decode($rawBody, true);
			if (is_array($payload)) {
				if (array_key_exists('user_id', $payload)) {
					$id = (int) $payload['user_id'];
				} elseif (array_key_exists('id', $payload)) {
					$id = (int) $payload['id'];
				}
				if (array_key_exists('status', $payload)) {
					$newStatus = $payload['status'];
				}
			}
		}
		if ($id <= 0) {
			if (function_exists('GETPOSTISSET') && GETPOSTISSET('user_id')) {
				$id = GETPOST('user_id', 'int');
			} elseif (function_exists('GETPOSTISSET') && GETPOSTISSET('id')) {
				$id = GETPOST('id', 'int');
			}
		}
		if ($newStatus === null && function_exists('GETPOSTISSET') && GETPOSTISSET('status')) {
			$newStatus = GETPOST('status', 'int');
		}
		if (empty($id)) {
			http_response_code(400);
			echo json_encode(array('error' => 'Missing id'));
			exit;
		}
		if ($newStatus === null) {
			http_response_code(400);
			echo json_encode(array('error' => 'Missing status'));
			exit;
		}

		$visibleStatus = ((int) $newStatus === 1) ? 1 : 0;
		$hideFlag = $visibleStatus === 1 ? 0 : 1;

		$sql = "UPDATE " . MAIN_DB_PREFIX . "user_extrafields SET zs_user_hide = " . $hideFlag . " WHERE fk_object = " . (int) $id;
		if (!$db->query($sql)) {
			http_response_code(500);
			echo json_encode(array('error' => 'Database error', 'details' => $db->lasterror()));
			exit;
		}

		$logContext = array(
			'employee_id' => (int) $id,
			'visible' => $visibleStatus,
		);
		self::logScheduleChange('employee_status_update', $currentUser, $logContext);
		echo json_encode(array('success' => true));
		exit;
	}

	public static function listEvents(DoliDB $db, $table, $taskTable)
	{
		global $conf;

		$groupIds = self::shiftplannerGetEmployeeGroupIds();
		$startIso = trim((string) GETPOST('start', 'alphanohtml'));
		$endIso = trim((string) GETPOST('end', 'alphanohtml'));
		$includeForeign = (int) GETPOST('include_foreign', 'int');
		$forcedIds = self::shiftplannerParseIdList(GETPOST('force_user_ids', 'alphanohtml'));
		$hiddenIds = self::shiftplannerParseIdList(GETPOST('hide_user_ids', 'alphanohtml'));

		$hasShiftType = self::shiftplannerTableHasColumn($db, $table, 'shift_type');
		$hasShiftStatus = self::shiftplannerTableHasColumn($db, $table, 'shift_status');
		$sql = "SELECT DISTINCT s.rowid, s.fk_user, s.fk_task, s.label, s.date_start, s.date_end, s.color, s.note, s.entity as shift_entity";
		if ($hasShiftType) {
			$sql .= ", s.shift_type";
		}
		if ($hasShiftStatus) {
			$sql .= ", s.shift_status";
		}
		$sql .= ", t.label as task_label, t.color as task_color, e.label as entity_label";
		$sql .= " FROM " . $table . " as s";
		$sql .= " LEFT JOIN " . $taskTable . " as t ON t.rowid = s.fk_task";
		$sql .= " LEFT JOIN " . MAIN_DB_PREFIX . "entity as e ON e.rowid = s.entity";
		$sql .= " LEFT JOIN " . MAIN_DB_PREFIX . "usergroup_user AS ugu";
		$sql .= " ON ugu.fk_user = s.fk_user";
		if (!empty($groupIds)) {
			$sql .= " AND ugu.fk_usergroup IN (" . implode(',', $groupIds) . ")";
		}
		$sql .= " AND ugu.entity = " . ((int) $conf->entity);
		$sql .= " WHERE 1 = 1";
		if (!empty($forcedIds)) {
			$sql .= " AND (ugu.fk_user IS NOT NULL OR s.fk_user IN (" . implode(',', $forcedIds) . "))";
		} else {
			$sql .= " AND ugu.fk_user IS NOT NULL";
		}
		if (!empty($hiddenIds)) {
			$sql .= " AND s.fk_user NOT IN (" . implode(',', $hiddenIds) . ")";
		}

		if (!empty($startIso) && !empty($endIso)) {
			$sql .= " AND s.date_start < '" . $db->escape($endIso) . "'";
			$sql .= " AND s.date_end > '" . $db->escape($startIso) . "'";
		}
		if (empty($includeForeign)) {
			$sql .= " AND s.entity = " . ((int) $conf->entity);
		}

		$resql = $db->query($sql);
		if (!$resql) {
			http_response_code(500);
			echo json_encode(array('error' => 'Database error', 'details' => $db->lasterror()));
			exit;
		}

		$events = array();
		while ($obj = $db->fetch_object($resql)) {
			$entityId = isset($obj->shift_entity) ? (int) $obj->shift_entity : (int) $conf->entity;
			$entityLabel = isset($obj->entity_label) ? (string) $obj->entity_label : '';
			if ($entityLabel === '') {
				$entityLabel = 'E' . $entityId;
			}
			$event = array(
				'id' => (int) $obj->rowid,
				'resourceId' => (int) $obj->fk_user,
				'fk_task' => isset($obj->fk_task) ? (int) $obj->fk_task : null,
				'taskId' => isset($obj->fk_task) ? (int) $obj->fk_task : null,
				'title' => $obj->label ?: ($obj->task_label ?: ''),
				'start' => self::shiftplannerSqlToIso($db, $obj->date_start),
				'end' => self::shiftplannerSqlToIso($db, $obj->date_end),
				'color' => $obj->color ?: $obj->task_color,
				'note' => $obj->note,
				'entity' => $entityId,
				'entity_label' => $entityLabel,
				'foreign_entity' => ($entityId !== (int) $conf->entity) ? 1 : 0,
			);
			if ($hasShiftType && isset($obj->shift_type)) {
				$event['shift_type'] = (string) $obj->shift_type;
			}
			if ($hasShiftStatus && isset($obj->shift_status)) {
				$event['shift_status'] = self::normalizeShiftStatus($obj->shift_status);
			}
			$events[] = $event;
		}
		$db->free($resql);

		echo json_encode($events);
		exit;
	}

	public static function getWeekNotes(DoliDB $db, User $user)
	{
		global $conf;

		$weekStart = trim((string) GETPOST('week_start', 'alphanohtml'));
		if ($weekStart === '') {
			http_response_code(400);
			echo json_encode(array('error' => 'Week start missing'));
			exit;
		}
		$hasForcedColumn = self::shiftplannerTableHasColumn($db, MAIN_DB_PREFIX . "dolizsynch_week_notes", 'forced_user_ids');
		$hasHiddenColumn = self::shiftplannerTableHasColumn($db, MAIN_DB_PREFIX . "dolizsynch_week_notes", 'hidden_user_ids');
		$sql = "SELECT note";
		if ($hasForcedColumn) {
			$sql .= ", forced_user_ids";
		}
		if ($hasHiddenColumn) {
			$sql .= ", hidden_user_ids";
		}
		$sql .= " FROM " . MAIN_DB_PREFIX . "dolizsynch_week_notes";
		$sql .= " WHERE week_start = '" . $db->escape($weekStart) . "'";
		$sql .= " AND entity IN (0, " . ((int) $conf->entity) . ")";
		$sql .= " ORDER BY entity DESC";
		$resql = $db->query($sql);
		if (!$resql) {
			http_response_code(500);
			echo json_encode(array('error' => 'Database error', 'details' => $db->lasterror()));
			exit;
		}

		$note = '';
		$forcedIds = array();
		$hiddenIds = array();
		$employeeNotes = array();

		if ($obj = $db->fetch_object($resql)) {
			$noteRaw = (string) $obj->note;
			if ($hasForcedColumn) {
				$forcedIds = self::shiftplannerParseIdList($obj->forced_user_ids ?? '');
				if ($hasHiddenColumn) {
					$hiddenIds = self::shiftplannerParseIdList($obj->hidden_user_ids ?? '');
				}

				$note = $noteRaw;
				if ($noteRaw !== '') {
					$decoded = self::shiftplannerDecodeWeekNotes($noteRaw);
					if (!empty($decoded['is_json'])) {
						$note = $decoded['note'];
						$employeeNotes = $decoded['employee_notes'];
						if (empty($forcedIds)) {
							$forcedIds = $decoded['forced_user_ids'];
						}
						if (empty($hiddenIds)) {
							$hiddenIds = $decoded['hidden_user_ids'];
						}
					}
				}
			} else {
				$decoded = self::shiftplannerDecodeWeekNotes($noteRaw);
				$note = $decoded['note'];
				$forcedIds = $decoded['forced_user_ids'];
				$hiddenIds = $decoded['hidden_user_ids'];
				$employeeNotes = $decoded['employee_notes'];
			}
		}

		$db->free($resql);
		echo json_encode(array(
			'note' => $note,
			'notes' => $note,
			'forced_user_ids' => $forcedIds,
			'hidden_user_ids' => $hiddenIds,
			'employee_notes' => $employeeNotes
		));
		exit;
	}

	public static function saveWeekNotes(DoliDB $db, User $user)
	{
		global $conf;

		if (empty($user->rights->dolizsynch->shift->write)) {
			http_response_code(403);
			echo json_encode(array('error' => 'Forbidden'));
			exit;
		}

		$weekStart = trim((string) GETPOST('week_start', 'alphanohtml'));
		$notesProvided = function_exists('GETPOSTISSET') ? GETPOSTISSET('notes') : (isset($_POST['notes']) || isset($_GET['notes']));
		$notes = $notesProvided ? trim((string) GETPOST('notes', 'restricthtml')) : '';
		$employeeId = (int) GETPOST('employee_id', 'int');
		$employeeNoteProvided = function_exists('GETPOSTISSET') ? GETPOSTISSET('employee_note') : (isset($_POST['employee_note']) || isset($_GET['employee_note']));
		$employeeNote = $employeeNoteProvided ? trim((string) GETPOST('employee_note', 'restricthtml')) : '';

		if ($weekStart === '') {
			http_response_code(400);
			echo json_encode(array('error' => 'Week start missing'));
			exit;
		}

		$hasForcedColumn = self::shiftplannerTableHasColumn($db, MAIN_DB_PREFIX . "dolizsynch_week_notes", 'forced_user_ids');
		$hasHiddenColumn = self::shiftplannerTableHasColumn($db, MAIN_DB_PREFIX . "dolizsynch_week_notes", 'hidden_user_ids');
		$hasUpdatedByColumn = self::shiftplannerTableHasColumn($db, MAIN_DB_PREFIX . "dolizsynch_week_notes", 'updated_by');

		$entityId = (int) $conf->entity;
		$existingRaw = '';
		$existingNoteText = '';
		$existingForced = array();
		$existingHidden = array();
		$existingEmployeeNotes = array();

		$sqlExisting = "SELECT note";
		if ($hasForcedColumn) {
			$sqlExisting .= ", forced_user_ids";
		}
		if ($hasHiddenColumn) {
			$sqlExisting .= ", hidden_user_ids";
		}
		$sqlExisting .= " FROM " . MAIN_DB_PREFIX . "dolizsynch_week_notes";
		$sqlExisting .= " WHERE week_start = '" . $db->escape($weekStart) . "'";
		$sqlExisting .= " AND entity = " . $entityId;
		$resqlExisting = $db->query($sqlExisting);

		if ($resqlExisting) {
			if ($objExisting = $db->fetch_object($resqlExisting)) {
				$existingRaw = (string) $objExisting->note;
				if ($hasForcedColumn) {
					$existingForced = self::shiftplannerParseIdList($objExisting->forced_user_ids ?? '');
				}
				if ($hasHiddenColumn) {
					$existingHidden = self::shiftplannerParseIdList($objExisting->hidden_user_ids ?? '');
				}
			}
			$db->free($resqlExisting);
		}

		if ($existingRaw !== '') {
			$decoded = self::shiftplannerDecodeWeekNotes($existingRaw);
			$existingNoteText = $decoded['note'];
			$existingEmployeeNotes = $decoded['employee_notes'];
			if (!empty($decoded['is_json'])) {
				if (empty($existingForced)) {
					$existingForced = $decoded['forced_user_ids'];
				}
				if (empty($existingHidden)) {
					$existingHidden = $decoded['hidden_user_ids'];
				}
			}
		}

		$noteText = $notesProvided ? $notes : $existingNoteText;
		if ($employeeId > 0 && $employeeNoteProvided) {
			if ($employeeNote === '') {
				unset($existingEmployeeNotes[(string) $employeeId]);
			} else {
				$existingEmployeeNotes[(string) $employeeId] = $employeeNote;
			}
			$existingEmployeeNotes = self::shiftplannerNormalizeEmployeeWeekNotes($existingEmployeeNotes);
		}

		$notePayload = $noteText;
		if ($hasForcedColumn || $hasHiddenColumn) {
			if (!empty($existingEmployeeNotes)) {
				$notePayload = self::shiftplannerEncodeWeekNotes($noteText, array(), array(), true, $existingEmployeeNotes);
			}

			$columns = array('week_start', 'note');
			$values = array(
				"'" . $db->escape($weekStart) . "'",
				"'" . $db->escape($notePayload) . "'",
			);
			if ($hasForcedColumn) {
				$forcedCsv = implode(',', array_map('intval', $existingForced));
				$columns[] = 'forced_user_ids';
				$values[] = $forcedCsv !== '' ? "'" . $db->escape($forcedCsv) . "'" : "NULL";
			}
			if ($hasHiddenColumn) {
				$hiddenCsv = implode(',', array_map('intval', $existingHidden));
				$columns[] = 'hidden_user_ids';
				$values[] = $hiddenCsv !== '' ? "'" . $db->escape($hiddenCsv) . "'" : "NULL";
			}
			$columns[] = 'entity';
			$values[] = $entityId;
			if ($hasUpdatedByColumn) {
				$columns[] = 'updated_by';
				$values[] = (int) $user->id;
			}
			$sql = "REPLACE INTO " . MAIN_DB_PREFIX . "dolizsynch_week_notes (" . implode(', ', $columns) . ")";
			$sql .= " VALUES (" . implode(', ', $values) . ")";
		} else {
			$forceJson = !empty($existingForced) || !empty($existingHidden) || !empty($existingEmployeeNotes);
			$notePayload = self::shiftplannerEncodeWeekNotes($noteText, $existingForced, $existingHidden, $forceJson, $existingEmployeeNotes);
			$sql = "REPLACE INTO " . MAIN_DB_PREFIX . "dolizsynch_week_notes (week_start, note, entity";
			if ($hasUpdatedByColumn) {
				$sql .= ", updated_by";
			}
			$sql .= ")";
			$sql .= " VALUES ('" . $db->escape($weekStart) . "', '" . $db->escape($notePayload) . "', " . $entityId;
			if ($hasUpdatedByColumn) {
				$sql .= ", " . ((int) $user->id);
			}
			$sql .= ")";
		}

		if (!$db->query($sql)) {
			http_response_code(500);
			echo json_encode(array('error' => 'Database error', 'details' => $db->lasterror()));
			exit;
		}

		echo json_encode(array('success' => true, 'employee_notes' => $existingEmployeeNotes));
		exit;
	}

	public static function getWeekEmployeeOverrides(DoliDB $db, User $user)
	{
		global $conf;

		$weekStart = trim((string) GETPOST('week_start', 'alphanohtml'));
		if ($weekStart === '') {
			http_response_code(400);
			echo json_encode(array('error' => 'Week start missing'));
			exit;
		}
		$hasForcedColumn = self::shiftplannerTableHasColumn($db, MAIN_DB_PREFIX . "dolizsynch_week_notes", 'forced_user_ids');
		$hasHiddenColumn = self::shiftplannerTableHasColumn($db, MAIN_DB_PREFIX . "dolizsynch_week_notes", 'hidden_user_ids');
		$sql = "SELECT note";
		if ($hasForcedColumn) {
			$sql .= ", forced_user_ids";
		}
		if ($hasHiddenColumn) {
			$sql .= ", hidden_user_ids";
		}
		$sql .= " FROM " . MAIN_DB_PREFIX . "dolizsynch_week_notes";
		$sql .= " WHERE week_start = '" . $db->escape($weekStart) . "'";
		$sql .= " AND entity IN (0, " . ((int) $conf->entity) . ")";
		$sql .= " ORDER BY entity DESC";
		$resql = $db->query($sql);
		if (!$resql) {
			http_response_code(500);
			echo json_encode(array('error' => 'Database error', 'details' => $db->lasterror()));
			exit;
		}
		$forcedIds = array();
		$hiddenIds = array();
		if ($obj = $db->fetch_object($resql)) {
			if ($hasForcedColumn) {
				$forcedIds = self::shiftplannerParseIdList($obj->forced_user_ids ?? '');
				if ($hasHiddenColumn) {
					$hiddenIds = self::shiftplannerParseIdList($obj->hidden_user_ids ?? '');
				}
				if (!empty($obj->note)) {
					$decoded = self::shiftplannerDecodeWeekNotes($obj->note);
					if (!empty($decoded['is_json'])) {
						if (empty($forcedIds)) {
							$forcedIds = $decoded['forced_user_ids'];
						}
						if (empty($hiddenIds)) {
							$hiddenIds = $decoded['hidden_user_ids'];
						}
					}
				}
			} else {
				$decoded = self::shiftplannerDecodeWeekNotes($obj->note);
				$forcedIds = $decoded['forced_user_ids'];
				$hiddenIds = $decoded['hidden_user_ids'];
			}
		}
		$db->free($resql);
		echo json_encode(array('forced_user_ids' => $forcedIds, 'hidden_user_ids' => $hiddenIds));
		exit;
	}

	public static function saveWeekEmployeeOverrides(DoliDB $db, User $user)
	{
		global $conf;

		if (empty($user->rights->dolizsynch->shift->write)) {
			http_response_code(403);
			echo json_encode(array('error' => 'Forbidden'));
			exit;
		}

		$weekStart = '';
		$forcedIds = array();
		$hiddenIds = array();
		$forcedProvided = false;
		$hiddenProvided = false;
		$rawBody = file_get_contents('php://input');
		if ($rawBody !== '') {
			$payload = json_decode($rawBody, true);
			if (is_array($payload)) {
				if (isset($payload['week_start'])) {
					$weekStart = trim((string) $payload['week_start']);
				}
				if (array_key_exists('forced_user_ids', $payload)) {
					$forcedIds = self::shiftplannerParseIdList($payload['forced_user_ids']);
					$forcedProvided = true;
				} elseif (array_key_exists('user_ids', $payload)) {
					$forcedIds = self::shiftplannerParseIdList($payload['user_ids']);
					$forcedProvided = true;
				}
				if (array_key_exists('hidden_user_ids', $payload)) {
					$hiddenIds = self::shiftplannerParseIdList($payload['hidden_user_ids']);
					$hiddenProvided = true;
				} elseif (array_key_exists('hide_user_ids', $payload)) {
					$hiddenIds = self::shiftplannerParseIdList($payload['hide_user_ids']);
					$hiddenProvided = true;
				}
			}
		}

		if ($weekStart === '') {
			$weekStart = trim((string) GETPOST('week_start', 'alphanohtml'));
		}
		if (!$forcedProvided) {
			if (function_exists('GETPOSTISSET') && GETPOSTISSET('forced_user_ids')) {
				$forcedIds = self::shiftplannerParseIdList(GETPOST('forced_user_ids', 'alphanohtml'));
				$forcedProvided = true;
			} elseif (function_exists('GETPOSTISSET') && GETPOSTISSET('user_ids')) {
				$forcedIds = self::shiftplannerParseIdList(GETPOST('user_ids', 'alphanohtml'));
				$forcedProvided = true;
			}
		}
		if (!$hiddenProvided) {
			if (function_exists('GETPOSTISSET') && GETPOSTISSET('hidden_user_ids')) {
				$hiddenIds = self::shiftplannerParseIdList(GETPOST('hidden_user_ids', 'alphanohtml'));
				$hiddenProvided = true;
			} elseif (function_exists('GETPOSTISSET') && GETPOSTISSET('hide_user_ids')) {
				$hiddenIds = self::shiftplannerParseIdList(GETPOST('hide_user_ids', 'alphanohtml'));
				$hiddenProvided = true;
			}
		}
		if ($weekStart === '') {
			http_response_code(400);
			echo json_encode(array('error' => 'Week start missing'));
			exit;
		}

		$hasForcedColumn = self::shiftplannerTableHasColumn($db, MAIN_DB_PREFIX . "dolizsynch_week_notes", 'forced_user_ids');
		$hasHiddenColumn = self::shiftplannerTableHasColumn($db, MAIN_DB_PREFIX . "dolizsynch_week_notes", 'hidden_user_ids');
		$hasUpdatedByColumn = self::shiftplannerTableHasColumn($db, MAIN_DB_PREFIX . "dolizsynch_week_notes", 'updated_by');
		$entityId = (int) $conf->entity;
		$existingRaw = '';
		$existingForced = array();
		$existingHidden = array();
		$existingEmployeeNotes = array();

		$sqlExisting = "SELECT note";
		if ($hasForcedColumn) {
			$sqlExisting .= ", forced_user_ids";
		}
		if ($hasHiddenColumn) {
			$sqlExisting .= ", hidden_user_ids";
		}
		$sqlExisting .= " FROM " . MAIN_DB_PREFIX . "dolizsynch_week_notes";
		$sqlExisting .= " WHERE week_start = '" . $db->escape($weekStart) . "'";
		$sqlExisting .= " AND entity = " . $entityId;
		$resqlExisting = $db->query($sqlExisting);
		if ($resqlExisting) {
			if ($objExisting = $db->fetch_object($resqlExisting)) {
				$existingRaw = (string) $objExisting->note;
				if ($hasForcedColumn) {
					$existingForced = self::shiftplannerParseIdList($objExisting->forced_user_ids ?? '');
				}
				if ($hasHiddenColumn) {
					$existingHidden = self::shiftplannerParseIdList($objExisting->hidden_user_ids ?? '');
				}
			}
			$db->free($resqlExisting);
		}

		if ($existingRaw !== '') {
			$decoded = self::shiftplannerDecodeWeekNotes($existingRaw);
			$existingEmployeeNotes = $decoded['employee_notes'];
			if (!empty($decoded['is_json'])) {
				$existingRaw = $decoded['note'];
				if (empty($existingForced)) {
					$existingForced = $decoded['forced_user_ids'];
				}
				if (empty($existingHidden)) {
					$existingHidden = $decoded['hidden_user_ids'];
				}
			}
		}

		if (!$forcedProvided) {
			$forcedIds = $existingForced;
		}
		if (!$hiddenProvided) {
			$hiddenIds = $existingHidden;
		}

		if ($hasForcedColumn || $hasHiddenColumn) {
			$notePayload = $existingRaw;
			if (!empty($existingEmployeeNotes)) {
				$notePayload = self::shiftplannerEncodeWeekNotes($existingRaw, array(), array(), true, $existingEmployeeNotes);
			}
			$columns = array('week_start', 'note');
			$values = array(
				"'" . $db->escape($weekStart) . "'",
				"'" . $db->escape($notePayload) . "'",
			);
			if ($hasForcedColumn) {
				$forcedCsv = implode(',', array_map('intval', $forcedIds));
				$columns[] = 'forced_user_ids';
				$values[] = $forcedCsv !== '' ? "'" . $db->escape($forcedCsv) . "'" : "NULL";
			}
			if ($hasHiddenColumn) {
				$hiddenCsv = implode(',', array_map('intval', $hiddenIds));
				$columns[] = 'hidden_user_ids';
				$values[] = $hiddenCsv !== '' ? "'" . $db->escape($hiddenCsv) . "'" : "NULL";
			}
			$columns[] = 'entity';
			$values[] = $entityId;
			if ($hasUpdatedByColumn) {
				$columns[] = 'updated_by';
				$values[] = (int) $user->id;
			}
			$sql = "REPLACE INTO " . MAIN_DB_PREFIX . "dolizsynch_week_notes (" . implode(', ', $columns) . ")";
			$sql .= " VALUES (" . implode(', ', $values) . ")";
		} else {
			$forceJson = !empty($forcedIds) || !empty($hiddenIds) || !empty($existingEmployeeNotes);
			$notePayload = self::shiftplannerEncodeWeekNotes($existingRaw, $forcedIds, $hiddenIds, $forceJson, $existingEmployeeNotes);
			$sql = "REPLACE INTO " . MAIN_DB_PREFIX . "dolizsynch_week_notes (week_start, note, entity";
			if ($hasUpdatedByColumn) {
				$sql .= ", updated_by";
			}
			$sql .= ")";
			$sql .= " VALUES ('" . $db->escape($weekStart) . "', '" . $db->escape($notePayload) . "', " . $entityId;
			if ($hasUpdatedByColumn) {
				$sql .= ", " . ((int) $user->id);
			}
			$sql .= ")";
		}

		if (!$db->query($sql)) {
			http_response_code(500);
			echo json_encode(array('error' => 'Database error', 'details' => $db->lasterror()));
			exit;
		}

		echo json_encode(array('success' => true));
		exit;
	}

	public static function listHourlySummary(DoliDB $db)
	{
		$startIso = trim((string) GETPOST('start', 'alphanohtml'));
		$endIso = trim((string) GETPOST('end', 'alphanohtml'));
		$dayWindow = shiftplanner_get_day_window();
		$summary = shiftplanner_fetch_hourly_summary($db, $startIso, $endIso, $dayWindow);
		echo json_encode(array('summary' => $summary));
		exit;
	}

	public static function createShift(DoliDB $db, $table, $taskTable, User $user)
	{
		global $conf;

		$possibleLeaveFields = array(
			GETPOST('leave_type', 'int'),
			trim((string) GETPOST('leave_halfday', 'alpha')),
			GETPOST('leave_minutes_total', 'int'),
			trim((string) GETPOST('leave_hours_start', 'alphanohtml')),
			trim((string) GETPOST('leave_hours_end', 'alphanohtml')),
		);
		$isLeavePayload = false;
		foreach ($possibleLeaveFields as $v) {
			if ($v !== '' && $v !== null) {
				$isLeavePayload = true;
				break;
			}
		}
		if ($isLeavePayload) {
			global $leaveTable;
			return createLeave($db, $table, $leaveTable, $user);
		}

		if (empty($user->rights->dolizsynch->shift->write)) {
			http_response_code(403);
			echo json_encode(array('error' => 'Forbidden'));
			exit;
		}

		$fkUser = GETPOST('fk_user', 'int');
		$labelProvided = function_exists('GETPOSTISSET') ? GETPOSTISSET('label') : (isset($_POST['label']) || isset($_GET['label']));
		$label = $labelProvided ? trim((string) GETPOST('label', 'restricthtml')) : '';
		$startIso = trim((string) GETPOST('start', 'alphanohtml'));
		$endIso = trim((string) GETPOST('end', 'alphanohtml'));
		$fkTask = GETPOST('fk_task', 'int');
		$color = self::sanitizeColor(GETPOST('color', 'alphanohtml'));
		$note = trim((string) GETPOST('note', 'restricthtml'));
		$shiftType = (int) GETPOST('shift_type', 'int');
		$shiftStatus = self::shiftplannerPrepareShiftStatusForStorage($db, $table, GETPOST('shift_status', 'alphanohtml'));
		if (!in_array($shiftType, array(1, 2), true)) {
			$shiftType = 1;
		}

		$errors = array();
		if (empty($fkUser)) $errors[] = 'fk_user';
		if ($startIso === '') $errors[] = 'start';
		if ($endIso === '') $errors[] = 'end';

		if (!empty($errors)) {
			http_response_code(400);
			echo json_encode(array('error' => 'Missing required fields', 'missing' => $errors));
			exit;
		}

		$dateStart = self::shiftplannerIsoToSql($startIso);
		$dateEnd = self::shiftplannerIsoToSql($endIso);
		$startTs = dol_stringtotime($dateStart);
		$endTs = dol_stringtotime($dateEnd);
		if ($dateStart === '' || $dateEnd === '' || empty($startTs) || empty($endTs)) {
			http_response_code(400);
			echo json_encode(array('error' => 'Invalid date format'));
			exit;
		}
		if ($dateEnd <= $dateStart) {
			http_response_code(400);
			echo json_encode(array('error' => 'End must be after start'));
			exit;
		}

		$dateMap = self::shiftplannerBuildOperationalDateMap($dateStart, $dateEnd);
		$maxMinutes = self::shiftplanner_get_max_shift_minutes();
		if ($maxMinutes > 0) {
			$durationMinutes = self::shiftplanner_get_duration_minutes($dateStart, $dateEnd);
			if ($durationMinutes === null || $durationMinutes > $maxMinutes) {
				http_response_code(400);
				echo json_encode(array(
					'error' => 'Shift duration exceeds the configured maximum of ' . ($maxMinutes / 60) . ' hours'
				));
				exit;
			}
		}
		if (self::shiftplannerHasLeaveOverlap($db, $fkUser, $dateStart, $dateEnd, false)) {
			http_response_code(409);
			echo json_encode(array('error' => 'Funcionário já possui uma ausência aprovada nesse período'));
			exit;
		}
		if (self::shiftplannerHasDayOffOnDates($db, $fkUser, $dateMap)) {
			http_response_code(409);
			echo json_encode(array('error' => 'Funcionário está marcado como Day Off nesse período'));
			exit;
		}

		$taskColor = '';
		if (!empty($fkTask)) {
			$task = self::shiftplannerFetchTask($db, $taskTable, $fkTask);
			if (!$task) {
				http_response_code(400);
				echo json_encode(array('error' => 'Invalid task'));
				exit;
			}
			$taskColor = self::sanitizeColor($task->color ?? '');
		}

		if (self::shiftplannerHasOverlap($db, $table, $fkUser, $dateStart, $dateEnd)) {
			http_response_code(409);
			echo json_encode(array('error' => 'Funcionário já possui um turno sobreposto nesse intervalo'));
			exit;
		}

		$finalColor = $taskColor ?: $color;
		$entityId = (int) $conf->entity;

		$hasShiftType = self::shiftplannerTableHasColumn($db, $table, 'shift_type');
		$hasShiftStatus = self::shiftplannerTableHasColumn($db, $table, 'shift_status');
		$sql = "INSERT INTO " . $table . " (fk_user, fk_task, label, date_start, date_end, color, note";
		if ($hasShiftType) $sql .= ", shift_type";
		if ($hasShiftStatus) $sql .= ", shift_status";
		$sql .= ", created_by, entity)";
		$sql .= " VALUES (" . (int) $fkUser . ", ";
		$sql .= (!empty($fkTask) ? (int) $fkTask : "NULL") . ", ";
		$sql .= ($label === '' ? "NULL" : "'" . $db->escape($label) . "'") . ", ";
		$sql .= "'" . $db->escape($dateStart) . "', ";
		$sql .= "'" . $db->escape($dateEnd) . "', ";
		$sql .= ($finalColor !== '' ? "'" . $db->escape($finalColor) . "'" : "NULL") . ", ";
		$sql .= ($note !== '' ? "'" . $db->escape($note) . "'" : "NULL") . ", ";
		if ($hasShiftType) $sql .= (int) $shiftType . ", ";
		if ($hasShiftStatus) $sql .= "'" . $db->escape($shiftStatus) . "', ";
		$sql .= (int) $user->id . ", ";
		$sql .= $entityId . ")";

		if (!$db->query($sql)) {
			http_response_code(500);
			echo json_encode(array('error' => 'Database error', 'details' => $db->lasterror()));
			exit;
		}

		$id = $db->last_insert_id($table, 'rowid');
		$logContext = array(
			'shift_id' => (int) $id,
			'employee_id' => (int) $fkUser,
			'start' => $dateStart,
			'end' => $dateEnd,
		);
		if (!empty($fkTask)) {
			$logContext['task_id'] = (int) $fkTask;
		}
		if ($hasShiftType) {
			$logContext['shift_type'] = (int) $shiftType;
		}
		if ($hasShiftStatus) {
			$logContext['shift_status'] = $shiftStatus;
		}
		if ($label !== '') {
			$logContext['label'] = $label;
		}
		self::logScheduleChange('shift_create', $user, $logContext);
		echo json_encode(array('success' => true, 'id' => (int) $id));
		exit;
	}

	public static function updateShift(DoliDB $db, $table, $taskTable, User $user)
	{
		global $conf;

		$possibleLeaveFields = array(
			GETPOST('leave_type', 'int'),
			trim((string) GETPOST('leave_halfday', 'alpha')),
			GETPOST('leave_minutes_total', 'int'),
			trim((string) GETPOST('leave_hours_start', 'alphanohtml')),
			trim((string) GETPOST('leave_hours_end', 'alphanohtml')),
		);
		$isLeavePayload = false;
		foreach ($possibleLeaveFields as $v) {
			if ($v !== '' && $v !== null) {
				$isLeavePayload = true;
				break;
			}
		}
		if ($isLeavePayload) {
			global $leaveTable;
			return updateLeave($db, $table, $leaveTable, $user);
		}

		if (empty($user->rights->dolizsynch->shift->write)) {
			http_response_code(403);
			echo json_encode(array('error' => 'Forbidden'));
			exit;
		}

		$id = GETPOST('id', 'int');
		if (empty($id)) {
			http_response_code(400);
			echo json_encode(array('error' => 'Missing id'));
			exit;
		}

		$currentShift = self::shiftplannerFetchShift($db, $table, $id);
		if (!$currentShift) {
			http_response_code(404);
			echo json_encode(array('error' => 'Shift not found'));
			exit;
		}
		if ((int) ($currentShift->entity ?? 0) !== (int) $conf->entity) {
			http_response_code(403);
			echo json_encode(array('error' => 'wrong_entity'));
			exit;
		}

		$fkUser = GETPOST('fk_user', 'int');
		$startIso = trim((string) GETPOST('start', 'alphanohtml'));
		$endIso = trim((string) GETPOST('end', 'alphanohtml'));
		$labelProvided = function_exists('GETPOSTISSET') ? GETPOSTISSET('label') : (isset($_POST['label']) || isset($_GET['label']));
		$label = $labelProvided ? trim((string) GETPOST('label', 'restricthtml')) : '';
		$noteProvided = function_exists('GETPOSTISSET') ? GETPOSTISSET('note') : (isset($_POST['note']) || isset($_GET['note']));
		$note = $noteProvided ? trim((string) GETPOST('note', 'restricthtml')) : '';
		$fkTaskProvided = function_exists('GETPOSTISSET') ? GETPOSTISSET('fk_task') : (isset($_POST['fk_task']) || isset($_GET['fk_task']));
		$fkTask = $fkTaskProvided ? GETPOST('fk_task', 'int') : null;

		$updates = array();
		$newFkUser = (int) ($fkUser ?: $currentShift->fk_user);
		$newDateStart = $currentShift->date_start;
		$newDateEnd = $currentShift->date_end;
		$newFkTask = $currentShift->fk_task;
		$newColor = $currentShift->color;
		$newNote = $currentShift->note ?? null;
		$hasShiftType = self::shiftplannerTableHasColumn($db, $table, 'shift_type');
		$hasShiftStatus = self::shiftplannerTableHasColumn($db, $table, 'shift_status');
		$shiftTypeProvided = null;
		$newShiftType = $hasShiftType ? (int) ($currentShift->shift_type ?? 1) : null;
		$shiftStatusProvided = null;
		$newShiftStatus = $hasShiftStatus ? self::normalizeShiftStatus($currentShift->shift_status ?? 'scheduled') : null;

		if (!empty($fkUser)) {
			$updates[] = "fk_user = " . (int) $fkUser;
		}
		if ($startIso !== '') {
			$dateStart = self::shiftplannerIsoToSql($startIso);
			if ($dateStart === '') {
				http_response_code(400);
				echo json_encode(array('error' => 'Invalid start date'));
				exit;
			}
			$updates[] = "date_start = '" . $db->escape($dateStart) . "'";
			$newDateStart = $dateStart;
		}
		if ($endIso !== '') {
			$dateEnd = self::shiftplannerIsoToSql($endIso);
			if ($dateEnd === '') {
				http_response_code(400);
				echo json_encode(array('error' => 'Invalid end date'));
				exit;
			}
			$updates[] = "date_end = '" . $db->escape($dateEnd) . "'";
			$newDateEnd = $dateEnd;
		}
		if ($labelProvided) {
			if ($label === '') {
				$updates[] = "label = NULL";
			} else {
				$updates[] = "label = '" . $db->escape($label) . "'";
			}
		}
		if ($noteProvided) {
			if ($note === '') {
				$updates[] = "note = NULL";
				$newNote = null;
			} else {
				$updates[] = "note = '" . $db->escape($note) . "'";
				$newNote = $note;
			}
		}

		if ($fkTaskProvided) {
			if (!empty($fkTask)) {
				$task = self::shiftplannerFetchTask($db, $taskTable, $fkTask);
				if (!$task) {
					http_response_code(400);
					echo json_encode(array('error' => 'Invalid task'));
					exit;
				}
				$newFkTask = $fkTask;
				$newColor = self::sanitizeColor($task->color ?? '') ?: $newColor;
				$updates[] = "fk_task = " . (int) $fkTask;
				$sanitizedTaskColor = self::sanitizeColor($task->color ?? '');
				if ($sanitizedTaskColor !== '') {
					$updates[] = "color = '" . $db->escape($sanitizedTaskColor) . "'";
				}
			} else {
				$newFkTask = null;
				$updates[] = "fk_task = NULL";
			}
		}

		if ($hasShiftType) {
			$shiftTypeProvided = GETPOST('shift_type', 'alphanohtml');
			if ($shiftTypeProvided !== null && $shiftTypeProvided !== '') {
				$newShiftType = (int) $shiftTypeProvided;
				if (!in_array($newShiftType, array(1, 2), true)) {
					$newShiftType = 1;
				}
				$updates[] = "shift_type = " . $newShiftType;
			}
		}

			if ($hasShiftStatus) {
				$statusIsSet = function_exists('GETPOSTISSET') ? GETPOSTISSET('shift_status') : (isset($_POST['shift_status']) || isset($_GET['shift_status']));
				if ($statusIsSet) {
					$shiftStatusProvided = trim((string) GETPOST('shift_status', 'alphanohtml'));
					$newShiftStatus = self::shiftplannerPrepareShiftStatusForStorage($db, $table, $shiftStatusProvided);
					$updates[] = "shift_status = '" . $db->escape($newShiftStatus) . "'";
				}
			}

		if (empty($updates)) {
			http_response_code(400);
			echo json_encode(array('error' => 'No fields to update'));
			exit;
		}

		if ($newDateEnd <= $newDateStart) {
			http_response_code(400);
			echo json_encode(array('error' => 'End must be after start'));
			exit;
		}

		$newStartTs = dol_stringtotime($newDateStart);
		$newEndTs = dol_stringtotime($newDateEnd);
		if (empty($newStartTs) || empty($newEndTs)) {
			http_response_code(400);
			echo json_encode(array('error' => 'Invalid date values'));
			exit;
		}
		$requiresAvailabilityValidation =
			((int) $newFkUser !== (int) $currentShift->fk_user) ||
			((string) $newDateStart !== (string) $currentShift->date_start) ||
			((string) $newDateEnd !== (string) $currentShift->date_end);

		if ($requiresAvailabilityValidation) {
			$maxMinutes = self::shiftplanner_get_max_shift_minutes();
			if ($maxMinutes > 0) {
				$durationMinutes = self::shiftplanner_get_duration_minutes($newDateStart, $newDateEnd);
				if ($durationMinutes === null || $durationMinutes > $maxMinutes) {
					http_response_code(400);
					echo json_encode(array(
						'error' => 'Shift duration exceeds the configured maximum of ' . ($maxMinutes / 60) . ' hours'
					));
					exit;
				}
			}

			$dateMap = self::shiftplannerBuildOperationalDateMap($newDateStart, $newDateEnd);
			if (self::shiftplannerHasLeaveOverlap($db, $newFkUser, $newDateStart, $newDateEnd, false)) {
				http_response_code(409);
				echo json_encode(array('error' => 'Funcionário já possui uma ausência aprovada nesse período'));
				exit;
			}
			if (self::shiftplannerHasDayOffOnDates($db, $newFkUser, $dateMap)) {
				http_response_code(409);
				echo json_encode(array('error' => 'Funcionário está marcado como Day Off nesse período'));
				exit;
			}

			if (self::shiftplannerHasOverlap($db, $table, $newFkUser, $newDateStart, $newDateEnd, $id)) {
				http_response_code(409);
				echo json_encode(array('error' => 'Funcionário já possui um turno sobreposto nesse intervalo'));
				exit;
			}
		}

		$changes = array();
		if (!empty($fkUser)) {
			$changes['fk_user'] = array('from' => (int) $currentShift->fk_user, 'to' => (int) $newFkUser);
		}
		if ($startIso !== '') {
			$changes['date_start'] = array('from' => $currentShift->date_start, 'to' => $newDateStart);
		}
		if ($endIso !== '') {
			$changes['date_end'] = array('from' => $currentShift->date_end, 'to' => $newDateEnd);
		}
		if ($fkTaskProvided) {
			$changes['fk_task'] = array(
				'from' => $currentShift->fk_task !== null ? (int) $currentShift->fk_task : null,
				'to' => $newFkTask !== null ? (int) $newFkTask : null,
			);
		}
		if ($labelProvided) {
			$changes['label'] = array(
				'from' => $currentShift->label ?? null,
				'to' => $label !== '' ? $label : null,
			);
		}
		if ($noteProvided) {
			$changes['note'] = array(
				'from' => $currentShift->note ?? null,
				'to' => $newNote,
			);
		}
		if ($hasShiftType && $shiftTypeProvided !== null && $shiftTypeProvided !== '') {
			$changes['shift_type'] = array(
				'from' => (int) ($currentShift->shift_type ?? 1),
				'to' => (int) $newShiftType,
			);
		}
		if ($hasShiftStatus && $shiftStatusProvided !== null) {
			$changes['shift_status'] = array(
				'from' => self::normalizeShiftStatus($currentShift->shift_status ?? 'scheduled'),
				'to' => $newShiftStatus,
			);
		}

		$sql = "UPDATE " . $table . " SET " . implode(', ', $updates) . " WHERE rowid = " . (int) $id;
		$sql .= " AND entity = " . ((int) $conf->entity);
		if (!$db->query($sql)) {
			http_response_code(500);
			echo json_encode(array('error' => 'Database error', 'details' => $db->lasterror()));
			exit;
		}

		$logContext = array(
			'shift_id' => (int) $id,
			'employee_id' => (int) $newFkUser,
			'start' => $newDateStart,
			'end' => $newDateEnd,
		);
		if ($newFkTask !== null) {
			$logContext['task_id'] = (int) $newFkTask;
		}
		if ($hasShiftType && $newShiftType !== null) {
			$logContext['shift_type'] = (int) $newShiftType;
		}
		if ($hasShiftStatus && $newShiftStatus !== null) {
			$logContext['shift_status'] = $newShiftStatus;
		}
		if ($newNote !== null && $newNote !== '') {
			$logContext['note'] = $newNote;
		}
		if (!empty($changes)) {
			$logContext['changes'] = $changes;
		}
		self::logScheduleChange('shift_update', $user, $logContext);
		echo json_encode(array('success' => true));
		exit;
	}

	public static function deleteShift(DoliDB $db, $table, User $user)
	{
		global $conf;

		if (empty($user->rights->dolizsynch->shift->delete)) {
			http_response_code(403);
			echo json_encode(array('error' => 'Forbidden'));
			exit;
		}

		$id = GETPOST('id', 'int');
		if (empty($id)) {
			http_response_code(400);
			echo json_encode(array('error' => 'Missing id'));
			exit;
		}

		$currentShift = self::shiftplannerFetchShift($db, $table, $id);
		if (!$currentShift) {
			http_response_code(404);
			echo json_encode(array('error' => 'Shift not found'));
			exit;
		}
		if ((int) ($currentShift->entity ?? 0) !== (int) $conf->entity) {
			http_response_code(403);
			echo json_encode(array('error' => 'wrong_entity'));
			exit;
		}

		$sql = "DELETE FROM " . $table . " WHERE rowid = " . (int) $id;
		$sql .= " AND entity = " . ((int) $conf->entity);
		if (!$db->query($sql)) {
			http_response_code(500);
			echo json_encode(array('error' => 'Database error', 'details' => $db->lasterror()));
			exit;
		}

		$logContext = array(
			'shift_id' => (int) $id,
			'employee_id' => (int) $currentShift->fk_user,
			'start' => $currentShift->date_start,
			'end' => $currentShift->date_end,
		);
		if (!empty($currentShift->fk_task)) {
			$logContext['task_id'] = (int) $currentShift->fk_task;
		}
		if (property_exists($currentShift, 'shift_type') && $currentShift->shift_type !== null) {
			$logContext['shift_type'] = (int) $currentShift->shift_type;
		}
		if (property_exists($currentShift, 'shift_status') && $currentShift->shift_status !== null) {
			$logContext['shift_status'] = self::normalizeShiftStatus($currentShift->shift_status);
		}
		if (property_exists($currentShift, 'label') && $currentShift->label !== null && $currentShift->label !== '') {
			$logContext['label'] = $currentShift->label;
		}
		self::logScheduleChange('shift_delete', $user, $logContext);

		echo json_encode(array('success' => true));
		exit;
	}

	public static function copyWeek(DoliDB $db, $shiftTable, $taskTable, User $user)
	{
		global $conf;

		if (empty($user->rights->dolizsynch->shift->write)) {
			http_response_code(403);
			echo json_encode(array('error' => 'Forbidden'));
			exit;
		}

		$sourceWeek = trim((string) GETPOST('source_week', 'alphanohtml'));
		$targetWeek = trim((string) GETPOST('target_week', 'alphanohtml'));
		$employeeId = (int) GETPOST('employee_id', 'int');
		$copyModeRaw = strtolower(trim((string) GETPOST('copy_mode', 'alphanohtml')));
		$copyMode = ($copyModeRaw === '') ? 'merge' : $copyModeRaw;
		if (!in_array($copyMode, array('merge', 'replace'), true)) {
			http_response_code(400);
			echo json_encode(array('error' => 'Modo de cópia inválido'));
			exit;
		}
		$employeeFilterSql = $employeeId > 0 ? " AND fk_user = " . $employeeId : "";

		$sourceMonday = self::shiftplannerNormalizeWeekDate($sourceWeek);
		$targetMonday = self::shiftplannerNormalizeWeekDate($targetWeek);

		if ($sourceMonday === '' || $targetMonday === '') {
			http_response_code(400);
			echo json_encode(array('error' => 'Semanas inválidas'));
			exit;
		}

		if ($sourceMonday === $targetMonday) {
			http_response_code(400);
			echo json_encode(array('error' => 'As semanas precisam ser diferentes'));
			exit;
		}

		// Calendar positions are wall-clock values, independent of DST and the host/user timezone.
		$sourceAnchor = shiftplanner_parse_wall_clock_datetime($sourceMonday . ' 00:00:00');
		$targetAnchor = shiftplanner_parse_wall_clock_datetime($targetMonday . ' 00:00:00');
		$sourceStartTs = $sourceAnchor ? $sourceAnchor->getTimestamp() : false;
		$sourceEndTs = $sourceAnchor ? $sourceAnchor->modify('+7 days')->getTimestamp() : false;
		$targetStartTs = $targetAnchor ? $targetAnchor->getTimestamp() : false;

		if ($sourceStartTs === false || $targetStartTs === false) {
			http_response_code(400);
			echo json_encode(array('error' => 'Não foi possível interpretar as semanas'));
			exit;
		}

		$sourceStartSql = gmdate('Y-m-d H:i:s', $sourceStartTs);
		$sourceEndSql = gmdate('Y-m-d H:i:s', $sourceEndTs);
		$targetEndTs = $targetAnchor->modify('+7 days')->getTimestamp();
		$targetStartSql = gmdate('Y-m-d H:i:s', $targetStartTs);
		$targetEndSql = gmdate('Y-m-d H:i:s', $targetEndTs);
		$sourceStartDate = gmdate('Y-m-d', $sourceStartTs);
		$sourceEndDate = gmdate('Y-m-d', $sourceEndTs - 1);
		$targetStartDate = gmdate('Y-m-d', $targetStartTs);
		$targetEndDate = gmdate('Y-m-d', $targetEndTs - 1);
		$dayoffTable = MAIN_DB_PREFIX . 'dolizsynch_dayoff';
		$entityId = (int) $conf->entity;
		$hasShiftStatus = self::shiftplannerTableHasColumn($db, $shiftTable, 'shift_status');

		$sql = "SELECT rowid, fk_user, fk_task, label, date_start, date_end, color, note";
		if ($hasShiftStatus) {
			$sql .= ", shift_status";
		}
		$sql .= " FROM " . $shiftTable;
		$sql .= " WHERE date_start >= '" . $db->escape($sourceStartSql) . "'";
		$sql .= " AND date_start < '" . $db->escape($sourceEndSql) . "'";
		$sql .= " AND entity = " . $entityId;
		$sql .= $employeeFilterSql;
		$sql .= " ORDER BY date_start";

		$resql = $db->query($sql);
		if (!$resql) {
			http_response_code(500);
			echo json_encode(array('error' => 'Database error', 'details' => $db->lasterror()));
			exit;
		}

		$shifts = array();
		while ($obj = $db->fetch_object($resql)) {
			$shifts[] = $obj;
		}
		$db->free($resql);

		$hasDayoffLock = self::shiftplannerDayOffHasLockColumn($db);
		$hasDayoffEntity = self::shiftplannerTableHasColumn($db, $dayoffTable, 'entity');

		$dayoffSql = "SELECT rowid, fk_user, date_dayoff, description";
		$dayoffSql .= $hasDayoffLock ? ", is_lock" : ", 0 as is_lock";
		if ($hasDayoffEntity) {
			$dayoffSql .= ", entity";
		}
		$dayoffSql .= " FROM " . $dayoffTable;
		$dayoffSql .= " WHERE is_recurring = 0";
		$dayoffSql .= " AND date_dayoff >= '" . $db->escape($sourceStartDate) . "'";
		$dayoffSql .= " AND date_dayoff <= '" . $db->escape($sourceEndDate) . "'";
		if ($employeeId > 0) {
			$dayoffSql .= " AND fk_user = " . $employeeId;
		}
		if ($hasDayoffEntity) {
			$dayoffSql .= " AND entity = " . $entityId;
		}
		$dayoffRes = $db->query($dayoffSql);
		$dayoffs = array();
		if ($dayoffRes) {
			while ($obj = $db->fetch_object($dayoffRes)) {
				$dayoffs[] = $obj;
			}
			$db->free($dayoffRes);
		} else {
			http_response_code(500);
			echo json_encode(array('error' => 'Database error', 'details' => $db->lasterror()));
			exit;
		}

		$conflicts = array();
		$conflictKeys = array();
		$addConflict = function ($type, $userId, $date) use (&$conflicts, &$conflictKeys) {
			$key = $type . '|' . (int) $userId . '|' . $date;
			if (isset($conflictKeys[$key])) return;
			$conflictKeys[$key] = true;
			$conflicts[] = array(
				'type' => $type,
				'employee_id' => (int) $userId,
				'date' => $date,
			);
		};

		$sourceDayoffMap = array();
		foreach ($dayoffs as $dayoff) {
			$wall = shiftplanner_parse_wall_clock_datetime(substr($dayoff->date_dayoff, 0, 10) . ' 00:00:00');
			$dayoffDateTs = $wall ? $wall->getTimestamp() : false;
			if ($dayoffDateTs === false) {
				continue;
			}
			$dayoffDateIso = gmdate('Y-m-d', $dayoffDateTs);
			$userId = (int) $dayoff->fk_user;
			if (!isset($sourceDayoffMap[$userId])) {
				$sourceDayoffMap[$userId] = array();
			}
			$sourceDayoffMap[$userId][$dayoffDateIso] = true;
		}

		$sourceShiftMap = array();
		$shiftCandidates = array();
		$dayoffCandidates = array();
		$targetTouchedDates = array();
		foreach ($shifts as $shift) {
			$startWall = shiftplanner_parse_wall_clock_datetime($shift->date_start);
			$endWall = shiftplanner_parse_wall_clock_datetime($shift->date_end);
			$startTs = $startWall ? $startWall->getTimestamp() : 0;
			$endTs = $endWall ? $endWall->getTimestamp() : 0;
			if (empty($startTs) || empty($endTs)) {
				continue;
			}
			$userId = (int) $shift->fk_user;
			$sourceDateIso = gmdate('Y-m-d', $startTs);
			if (!isset($sourceShiftMap[$userId])) {
				$sourceShiftMap[$userId] = array();
			}
			$sourceShiftMap[$userId][$sourceDateIso] = true;
			if (!empty($sourceDayoffMap[$userId][$sourceDateIso])) {
				$addConflict('source_overlap', $userId, $sourceDateIso);
			}
			$offsetStart = $startTs - $sourceStartTs;
			$offsetEnd = $endTs - $sourceStartTs;
			$newStartTs = $targetStartTs + $offsetStart;
			$newEndTs = $targetStartTs + $offsetEnd;
			$targetDateIso = gmdate('Y-m-d', $newStartTs);
			$shiftCandidates[] = array(
				'shift' => $shift,
				'user_id' => $userId,
				'source_date' => $sourceDateIso,
				'target_date' => $targetDateIso,
				'new_start_sql' => gmdate('Y-m-d H:i:s', $newStartTs),
				'new_end_sql' => gmdate('Y-m-d H:i:s', $newEndTs),
			);
			if (!isset($targetTouchedDates[$userId])) {
				$targetTouchedDates[$userId] = array();
			}
			$targetTouchedDates[$userId][$targetDateIso] = true;
		}

		foreach ($dayoffs as $dayoff) {
			$wall = shiftplanner_parse_wall_clock_datetime(substr($dayoff->date_dayoff, 0, 10) . ' 00:00:00');
			$dayoffDateTs = $wall ? $wall->getTimestamp() : false;
			if ($dayoffDateTs === false) {
				continue;
			}
			$sourceDateIso = gmdate('Y-m-d', $dayoffDateTs);
			$userId = (int) $dayoff->fk_user;
			if (!empty($sourceShiftMap[$userId][$sourceDateIso])) {
				$addConflict('source_overlap', $userId, $sourceDateIso);
			}
			$offsetDays = (int) floor(($dayoffDateTs - $sourceStartTs) / 86400);
			$newDayoffTs = $targetStartTs + ($offsetDays * 86400);
			$targetDateIso = gmdate('Y-m-d', $newDayoffTs);
			$dayoffCandidates[] = array(
				'dayoff' => $dayoff,
				'user_id' => $userId,
				'source_date' => $sourceDateIso,
				'target_date' => $targetDateIso,
				'new_dayoff_date' => gmdate('Y-m-d', $newDayoffTs),
			);
			if (!isset($targetTouchedDates[$userId])) {
				$targetTouchedDates[$userId] = array();
			}
			$targetTouchedDates[$userId][$targetDateIso] = true;
		}

		if (!empty($targetTouchedDates)) {
			$employeeIds = array_keys($targetTouchedDates);
			$employeeIdsSql = implode(',', array_map('intval', $employeeIds));

			// Existing shifts in target week
			$sqlExistingShifts = "SELECT fk_user, date_start FROM " . $shiftTable;
			$sqlExistingShifts .= " WHERE date_start >= '" . $db->escape($targetStartSql) . "'";
			$sqlExistingShifts .= " AND date_start < '" . $db->escape($targetEndSql) . "'";
			$sqlExistingShifts .= " AND (entity IS NULL OR entity IN (0, " . $entityId . "))";
			$sqlExistingShifts .= " AND fk_user IN (" . $employeeIdsSql . ")";
			$resExistingShifts = $db->query($sqlExistingShifts);
			if ($resExistingShifts) {
				while ($obj = $db->fetch_object($resExistingShifts)) {
					$userId = (int) $obj->fk_user;
					$dateIso = substr((string) $obj->date_start, 0, 10);
					if (!empty($targetTouchedDates[$userId][$dateIso])) {
						$addConflict('target_shift', $userId, $dateIso);
					}
				}
				$db->free($resExistingShifts);
			}

			// Existing day-offs (including recurring) on target dates
			$allTargetDates = array();
			foreach ($targetTouchedDates as $userId => $dates) {
				foreach (array_keys($dates) as $dateIso) {
					$allTargetDates[$dateIso] = true;
				}
			}
			$allTargetDatesList = array_keys($allTargetDates);
			sort($allTargetDatesList);
			$maxTargetDate = !empty($allTargetDatesList) ? end($allTargetDatesList) : '';
			if (!empty($allTargetDatesList) && $maxTargetDate !== '') {
				$datesSql = array();
				foreach ($allTargetDatesList as $dateIso) {
					$datesSql[] = "'" . $db->escape($dateIso) . "'";
				}
				$sqlExistingDayoffs = "SELECT fk_user, date_dayoff, is_recurring FROM " . $dayoffTable;
				$sqlExistingDayoffs .= " WHERE fk_user IN (" . $employeeIdsSql . ")";
				$sqlExistingDayoffs .= " AND ((is_recurring = 0 AND date_dayoff IN (" . implode(',', $datesSql) . "))";
				$sqlExistingDayoffs .= " OR (is_recurring = 1 AND date_dayoff <= '" . $db->escape($maxTargetDate) . "'))";
				if ($hasDayoffEntity) {
					$sqlExistingDayoffs .= " AND entity = " . $entityId;
				}
				$resExistingDayoffs = $db->query($sqlExistingDayoffs);
				if ($resExistingDayoffs) {
					while ($obj = $db->fetch_object($resExistingDayoffs)) {
						$userId = (int) $obj->fk_user;
						if (empty($targetTouchedDates[$userId])) {
							continue;
						}
						$wall = shiftplanner_parse_wall_clock_datetime(substr($obj->date_dayoff, 0, 10) . ' 00:00:00');
						$dayoffDateTs = $wall ? $wall->getTimestamp() : false;
						if ($dayoffDateTs === false) {
							continue;
						}
						$dayoffDateIso = gmdate('Y-m-d', $dayoffDateTs);
						if ((int) $obj->is_recurring === 0) {
							if (!empty($targetTouchedDates[$userId][$dayoffDateIso])) {
								$addConflict('target_dayoff', $userId, $dayoffDateIso);
							}
							continue;
						}
						$baseDow = (int) gmdate('w', $dayoffDateTs);
						foreach (array_keys($targetTouchedDates[$userId]) as $targetIso) {
							if ($targetIso < $dayoffDateIso) {
								continue;
							}
							$targetTs = dol_stringtotime($targetIso);
							if ($targetTs === false) {
								continue;
							}
							if ((int) date('w', $targetTs) === $baseDow) {
								$addConflict('target_dayoff', $userId, $targetIso);
							}
						}
					}
					$db->free($resExistingDayoffs);
				}
			}

			// Existing leaves on target dates
			$leaveTable = MAIN_DB_PREFIX . "dolizsynch_leave";
			$sqlExistingLeaves = "SELECT fk_user, date_start, date_end, halfday, minutes_total, day_change_hour FROM " . $leaveTable;
			$sqlExistingLeaves .= " WHERE fk_user IN (" . $employeeIdsSql . ")";
			$sqlExistingLeaves .= " AND (entity IS NULL OR entity IN (0, " . $entityId . "))";
			$sqlExistingLeaves .= " AND date_end > '" . $db->escape($targetStartSql) . "'";
			$sqlExistingLeaves .= " AND date_start < '" . $db->escape($targetEndSql) . "'";
			$resExistingLeaves = $db->query($sqlExistingLeaves);
			if ($resExistingLeaves) {
				while ($obj = $db->fetch_object($resExistingLeaves)) {
					$userId = (int) $obj->fk_user;
					if (empty($targetTouchedDates[$userId])) {
						continue;
					}
					$leaveStart = shiftplanner_parse_wall_clock_datetime($obj->date_start);
					$leaveEnd = shiftplanner_parse_wall_clock_datetime($obj->date_end);
					if (!$leaveStart || !$leaveEnd || $leaveEnd <= $leaveStart) {
						continue;
					}
					if ((int) $obj->minutes_total > 0) {
						$leaveEnd = $leaveStart->modify('+1 day');
					}
					$leaveDayChangeHour = isset($obj->day_change_hour) && $obj->day_change_hour !== null
						? (int) $obj->day_change_hour
						: self::shiftplanner_get_day_change_hour();
					$leaveDateMap = self::shiftplannerBuildOperationalDateMap(
						$leaveStart->format('Y-m-d H:i:s'),
						$leaveEnd->format('Y-m-d H:i:s'),
						$leaveDayChangeHour,
						((int) $obj->halfday === 2)
					);
					if (empty($leaveDateMap)) {
						continue;
					}
					foreach (array_keys($targetTouchedDates[$userId]) as $targetIso) {
						if (!isset($leaveDateMap[$targetIso])) {
							continue;
						}
						$addConflict('target_leave', $userId, $targetIso);
					}
				}
				$db->free($resExistingLeaves);
			}
		}

		if ($copyMode === 'replace' && !empty($conflicts)) {
			$replaceConflictCounts = array();
			foreach ($conflicts as $conflict) {
				$type = !empty($conflict['type']) ? $conflict['type'] : '';
				if ($type === '') {
					continue;
				}
				if (!isset($replaceConflictCounts[$type])) {
					$replaceConflictCounts[$type] = 0;
				}
				$replaceConflictCounts[$type]++;
			}
			http_response_code(409);
			echo json_encode(array(
				'success' => false,
				'mode' => $copyMode,
				'error' => 'copy_conflict',
				'conflicts' => $conflicts,
				'inserted_shifts' => 0,
				'inserted_dayoffs' => 0,
				'inserted' => 0,
				'dayoffs_inserted' => 0,
				'skipped' => 0,
				'skipped_conflicts' => $replaceConflictCounts,
				'deleted_shifts' => 0,
				'deleted_dayoffs' => 0,
			));
			exit;
		}

		$resolveConflictType = function (array $candidate) use (&$conflictKeys) {
			$userId = (int) $candidate['user_id'];
			$sourceDate = (string) $candidate['source_date'];
			$targetDate = (string) $candidate['target_date'];

			if ($sourceDate !== '' && isset($conflictKeys['source_overlap|' . $userId . '|' . $sourceDate])) {
				return 'source_overlap';
			}
			if ($targetDate !== '' && isset($conflictKeys['target_shift|' . $userId . '|' . $targetDate])) {
				return 'target_shift';
			}
			if ($targetDate !== '' && isset($conflictKeys['target_dayoff|' . $userId . '|' . $targetDate])) {
				return 'target_dayoff';
			}
			if ($targetDate !== '' && isset($conflictKeys['target_leave|' . $userId . '|' . $targetDate])) {
				return 'target_leave';
			}
			return '';
		};

		$skipped = 0;
		$skippedConflictCounts = array(
			'source_overlap' => 0,
			'target_shift' => 0,
			'target_dayoff' => 0,
			'target_leave' => 0,
		);
		$plannedShiftCandidates = array();
		$plannedDayoffCandidates = array();

		foreach ($shiftCandidates as $candidate) {
			$conflictType = $resolveConflictType($candidate);
			if ($copyMode === 'merge' && $conflictType !== '') {
				$skipped++;
				if (!isset($skippedConflictCounts[$conflictType])) {
					$skippedConflictCounts[$conflictType] = 0;
				}
				$skippedConflictCounts[$conflictType]++;
				continue;
			}
			$plannedShiftCandidates[] = $candidate;
		}

		foreach ($dayoffCandidates as $candidate) {
			$conflictType = $resolveConflictType($candidate);
			if ($copyMode === 'merge' && $conflictType !== '') {
				$skipped++;
				if (!isset($skippedConflictCounts[$conflictType])) {
					$skippedConflictCounts[$conflictType] = 0;
				}
				$skippedConflictCounts[$conflictType]++;
				continue;
			}
			$plannedDayoffCandidates[] = $candidate;
		}

		$db->begin();
		try {
			$deletedShifts = 0;
			$deletedDayoffs = 0;
			if ($copyMode === 'replace') {
				$sqlDeleteShifts = "DELETE FROM " . $shiftTable;
				$sqlDeleteShifts .= " WHERE date_start >= '" . $db->escape($targetStartSql) . "'";
				$sqlDeleteShifts .= " AND date_start < '" . $db->escape($targetEndSql) . "'";
				$sqlDeleteShifts .= " AND entity = " . $entityId;
				if ($employeeId > 0) {
					$sqlDeleteShifts .= " AND fk_user = " . $employeeId;
				}
				$resDeleteShifts = $db->query($sqlDeleteShifts);
				if (!$resDeleteShifts) {
					throw new Exception($db->lasterror());
				}
				$deletedShifts = (int) $db->affected_rows($resDeleteShifts);
				if ($deletedShifts < 0) {
					$deletedShifts = 0;
				}

				$sqlDeleteDayoffs = "DELETE FROM " . $dayoffTable;
				$sqlDeleteDayoffs .= " WHERE is_recurring = 0";
				$sqlDeleteDayoffs .= " AND date_dayoff >= '" . $db->escape($targetStartDate) . "'";
				$sqlDeleteDayoffs .= " AND date_dayoff <= '" . $db->escape($targetEndDate) . "'";
				if ($employeeId > 0) {
					$sqlDeleteDayoffs .= " AND fk_user = " . $employeeId;
				}
				if ($hasDayoffEntity) {
					$sqlDeleteDayoffs .= " AND entity = " . $entityId;
				}
				$resDeleteDayoffs = $db->query($sqlDeleteDayoffs);
				if (!$resDeleteDayoffs) {
					throw new Exception($db->lasterror());
				}
				$deletedDayoffs = (int) $db->affected_rows($resDeleteDayoffs);
				if ($deletedDayoffs < 0) {
					$deletedDayoffs = 0;
				}
			}

			$insertedShifts = 0;
			$insertedDayoffs = 0;
			foreach ($plannedShiftCandidates as $candidate) {
				$shift = $candidate['shift'];
				$sqlInsert = "INSERT INTO " . $shiftTable . " (fk_user, fk_task, label, date_start, date_end, color, note";
				if ($hasShiftStatus) {
					$sqlInsert .= ", shift_status";
				}
				$sqlInsert .= ", created_by, entity)";
				$sqlInsert .= " VALUES (";
				$sqlInsert .= (int) $shift->fk_user . ",";
				$sqlInsert .= $shift->fk_task ? ((int) $shift->fk_task) . "," : "NULL,";
				$sqlInsert .= "'" . $db->escape($shift->label) . "',";
				$sqlInsert .= "'" . $db->escape($candidate['new_start_sql']) . "',";
				$sqlInsert .= "'" . $db->escape($candidate['new_end_sql']) . "',";
				$sqlInsert .= $shift->color ? "'" . $db->escape($shift->color) . "'," : "NULL,";
				$sqlInsert .= $shift->note ? "'" . $db->escape($shift->note) . "'," : "NULL,";
				if ($hasShiftStatus) {
					$sqlInsert .= "'" . $db->escape(self::shiftplannerPrepareShiftStatusForStorage($db, $shiftTable, $shift->shift_status ?? 'scheduled')) . "',";
				}
				$sqlInsert .= (int) $user->id . ",";
				$sqlInsert .= $entityId;
				$sqlInsert .= ")";

				if (!$db->query($sqlInsert)) {
					throw new Exception($db->lasterror());
				}
				$insertedShifts++;
			}

			$nowSql = $db->idate(dol_now());
			foreach ($plannedDayoffCandidates as $candidate) {
				$dayoff = $candidate['dayoff'];
				$sqlInsertDayoff = "INSERT INTO " . $dayoffTable . " (";
				if ($hasDayoffEntity) {
					$sqlInsertDayoff .= "entity, ";
				}
				$sqlInsertDayoff .= "fk_user, date_dayoff, is_recurring, description";
				if ($hasDayoffLock) {
					$sqlInsertDayoff .= ", is_lock";
				}
				$sqlInsertDayoff .= ", date_creation, fk_user_creat)";
				$sqlInsertDayoff .= " VALUES (";
				if ($hasDayoffEntity) {
					$sqlInsertDayoff .= $entityId . ",";
				}
				$sqlInsertDayoff .= (int) $dayoff->fk_user . ",";
				$sqlInsertDayoff .= "'" . $db->escape($candidate['new_dayoff_date']) . "',";
				$sqlInsertDayoff .= "0,";
				$sqlInsertDayoff .= $dayoff->description !== null && $dayoff->description !== ''
					? "'" . $db->escape($dayoff->description) . "'"
					: "NULL";
				if ($hasDayoffLock) {
					$sqlInsertDayoff .= "," . ((int) ($dayoff->is_lock ?? 0));
				}
				$sqlInsertDayoff .= ",";
				$sqlInsertDayoff .= "'" . $nowSql . "',";
				$sqlInsertDayoff .= (int) $user->id;
				$sqlInsertDayoff .= ")";
				if (!$db->query($sqlInsertDayoff)) {
					throw new Exception($db->lasterror());
				}
				$insertedDayoffs++;
			}

			$db->commit();
			$skippedConflicts = array();
			foreach ($skippedConflictCounts as $type => $count) {
				if ((int) $count > 0) {
					$skippedConflicts[$type] = (int) $count;
				}
			}
			$logContext = array(
				'mode' => $copyMode,
				'source_week' => $sourceMonday,
				'target_week' => $targetMonday,
				'employee_id' => $employeeId > 0 ? $employeeId : null,
				'inserted_shifts' => (int) $insertedShifts,
				'inserted_dayoffs' => (int) $insertedDayoffs,
				'inserted' => (int) $insertedShifts,
				'dayoffs_inserted' => (int) $insertedDayoffs,
				'skipped' => (int) $skipped,
				'skipped_conflicts' => $skippedConflicts,
				'deleted_shifts' => (int) $deletedShifts,
				'deleted_dayoffs' => (int) $deletedDayoffs,
			);
			self::logScheduleChange('week_copy', $user, $logContext);
			echo json_encode(array(
				'success' => true,
				'mode' => $copyMode,
				'inserted_shifts' => (int) $insertedShifts,
				'inserted_dayoffs' => (int) $insertedDayoffs,
				'inserted' => (int) $insertedShifts,
				'dayoffs_inserted' => (int) $insertedDayoffs,
				'skipped' => (int) $skipped,
				'skipped_conflicts' => $skippedConflicts,
				'deleted_shifts' => (int) $deletedShifts,
				'deleted_dayoffs' => (int) $deletedDayoffs,
			));
			exit;
		} catch (Exception $e) {
			$db->rollback();
			http_response_code(500);
			echo json_encode(array(
				'success' => false,
				'mode' => $copyMode,
				'error' => 'Falha ao copiar semana',
				'details' => $e->getMessage(),
			));
			exit;
		}
	}

	public static function listAvailability(DoliDB $db, $leaveTable)
	{
		global $conf;

		$startIso = trim((string) GETPOST('start', 'alphanohtml'));
		$endIso = trim((string) GETPOST('end', 'alphanohtml'));
		$includeForeign = (int) GETPOST('include_foreign', 'int');

		if ($startIso === '' || $endIso === '') {
			http_response_code(400);
			echo json_encode(array('error' => 'Missing date range'));
			exit;
		}

		$startTs = dol_stringtotime($startIso);
		$endTsRaw = dol_stringtotime($endIso);
		if (empty($startTs) || empty($endTsRaw)) {
			http_response_code(400);
			echo json_encode(array('error' => 'Invalid date range'));
			exit;
		}

		$endTs = $endTsRaw - 86400;
		if ($endTs < $startTs) {
			$endTs = $startTs;
		}

		$dayChangeHour = self::shiftplanner_get_day_change_hour();
		$startDateSql = $db->escape(dol_print_date($startTs, '%Y-%m-%d'));
		$endDateSql = $db->escape(dol_print_date($endTs, '%Y-%m-%d'));

		$dayoffTable = MAIN_DB_PREFIX . "dolizsynch_dayoff";
		$hasDayoffLock = self::shiftplannerDayOffHasLockColumn($db);
		$hasDayoffEntity = self::shiftplannerTableHasColumn($db, $dayoffTable, 'entity');

		$dayoffs = array();
		$sql = "SELECT d.rowid, d.fk_user, d.date_dayoff, d.is_recurring, d.description";
		$sql .= $hasDayoffLock ? ", d.is_lock" : ", 0 as is_lock";
		if ($hasDayoffEntity) {
			$sql .= ", d.entity as entity, e.label as entity_label";
		}
		$sql .= " FROM " . $dayoffTable . " d";
		if ($hasDayoffEntity) {
			$sql .= " LEFT JOIN " . MAIN_DB_PREFIX . "entity as e ON e.rowid = d.entity";
		}
		$sql .= " WHERE ((d.is_recurring = 0 AND d.date_dayoff >= '" . $startDateSql . "' AND d.date_dayoff <= '" . $endDateSql . "')";
		$sql .= " OR (d.is_recurring = 1 AND d.date_dayoff <= '" . $endDateSql . "'))";
		if (empty($includeForeign) && $hasDayoffEntity) {
			$sql .= " AND (d.entity IS NULL OR d.entity IN (0, " . ((int) $conf->entity) . "))";
		}
		$sql .= " ORDER BY d.date_dayoff";

		$resql = $db->query($sql);
		if ($resql) {
			while ($obj = $db->fetch_object($resql)) {
				$baseTs = dol_stringtotime($obj->date_dayoff);
				if (empty($baseTs)) {
					continue;
				}
				$userId = (int) $obj->fk_user;
				if (!isset($dayoffs[$userId])) {
					$dayoffs[$userId] = array();
				}

				$entityId = $hasDayoffEntity && isset($obj->entity) ? (int) $obj->entity : (int) $conf->entity;
				$entityLabel = $hasDayoffEntity && isset($obj->entity_label) ? (string) $obj->entity_label : '';
				if ($entityLabel === '' && $hasDayoffEntity && $entityId !== 0) {
					$entityLabel = 'E' . $entityId;
				}
				$foreignEntity = $hasDayoffEntity && ($entityId !== (int) $conf->entity) ? 1 : 0;

				if ((int) $obj->is_recurring === 1) {
					$baseDow = (int) date('w', $baseTs);
					for ($ts = $startTs; $ts <= $endTs; $ts += 86400) {
						if ($ts < $baseTs) {
							continue;
						}
						if ((int) date('w', $ts) !== $baseDow) {
							continue;
						}
						$key = dol_print_date($ts, '%Y-%m-%d');
						$dayStartTs = self::shiftplanner_operational_day_start_from_iso($key, $dayChangeHour);
						if ($dayChangeHour > 0) {
							$dayStartTs++;
						}
						$dayoffs[$userId][] = array(
							'id' => (int) $obj->rowid,
							'date' => $dayStartTs,
							'start_ts' => $dayStartTs,
							'end_ts' => $dayStartTs + 86400,
							'date_iso' => $key,
							'is_recurring' => 1,
							'description' => $obj->description ?: '',
							'entry_type' => $hasDayoffLock && (int) $obj->is_lock === 1 ? 'lockday' : 'dayoff',
							'is_lock' => $hasDayoffLock ? (int) $obj->is_lock : 0,
							'entity' => $entityId,
							'entity_label' => $entityLabel,
							'foreign_entity' => $foreignEntity,
						);
					}
				} else {
					if ($baseTs < $startTs || $baseTs > $endTs) {
						continue;
					}
					$key = dol_print_date($baseTs, '%Y-%m-%d');
					$dayStartTs = self::shiftplanner_operational_day_start_from_iso($key, $dayChangeHour);
					if ($dayChangeHour > 0) {
						$dayStartTs++;
					}
					$dayoffs[$userId][] = array(
						'id' => (int) $obj->rowid,
						'date' => $dayStartTs,
						'start_ts' => $dayStartTs,
						'end_ts' => $dayStartTs + 86400,
						'date_iso' => $key,
						'is_recurring' => 0,
						'description' => $obj->description ?: '',
						'entry_type' => $hasDayoffLock && (int) $obj->is_lock === 1 ? 'lockday' : 'dayoff',
						'is_lock' => $hasDayoffLock ? (int) $obj->is_lock : 0,
						'entity' => $entityId,
						'entity_label' => $entityLabel,
						'foreign_entity' => $foreignEntity,
					);
				}
			}
			$db->free($resql);
		} else {
			if ($db->lasterrno() !== 'DB_ERROR_NOSUCHTABLE') {
				dol_syslog(__METHOD__ . ' dayoff query failed: ' . $db->lasterror(), LOG_ERR);
				http_response_code(500);
				echo json_encode(array('error' => 'Database error', 'details' => $db->lasterror()));
				exit;
			}
			dol_syslog(__METHOD__ . ' dayoff table missing, returning empty set', LOG_WARNING);
		}

		$leaves = array();
		$rangeEndTs = $endTs + 86399;
		$startBoundSql = $db->idate($startTs);
		$endBoundSql = $db->idate($rangeEndTs);
		$hasHolidayColor = self::shiftplannerHolidayTypeHasColor($db);
		$colorSelect = $hasHolidayColor ? "COALESCE(ht.color, '#FFA500')" : "'#FFA500'";
		$sql = "SELECT l.rowid, l.fk_user, l.date_start, l.date_end, l.halfday, l.minutes_total, l.fk_type as type_id, l.description,";
		$sql .= " ht.label as type_label, ht.code as type_code, " . $colorSelect . " as color, l.entity as leave_entity, e.label as entity_label";
		$sql .= " FROM " . $leaveTable . " l";
		$sql .= " LEFT JOIN " . MAIN_DB_PREFIX . "c_holiday_types ht ON ht.rowid = l.fk_type";
		$sql .= " LEFT JOIN " . MAIN_DB_PREFIX . "entity as e ON e.rowid = l.entity";
		$sql .= " WHERE 1 = 1";
		if (empty($includeForeign)) {
			$sql .= " AND (l.entity IS NULL OR l.entity IN (0, " . ((int) $conf->entity) . "))";
		}
		$sql .= " AND l.date_end > '" . $db->escape($startBoundSql) . "'";
		$sql .= " AND l.date_start < '" . $db->escape($endBoundSql) . "'";
		$sql .= " ORDER BY l.date_start";

		$resql = $db->query($sql);
		if ($resql) {
			while ($obj = $db->fetch_object($resql)) {
				$userId = (int) $obj->fk_user;
				if (!isset($leaves[$userId])) {
					$leaves[$userId] = array();
				}
				$leaveStartTs = self::shiftplannerNormalizeTimestamp($db, $obj->date_start);
				$leaveEndTs = self::shiftplannerNormalizeTimestamp($db, $obj->date_end);
				$cleanDescription = (string) $obj->description;
				$minutesTotalStored = (int) $obj->minutes_total;
				$durationMinutes = self::shiftplannerComputeMinutesBetween($leaveStartTs, $leaveEndTs);
				$startBoundaryTs = self::shiftplanner_operational_boundary_for_timestamp($leaveStartTs, $dayChangeHour);

				$minutesOnly = false;
				if ($minutesTotalStored > 0) {
					$expectedEndTs = $leaveStartTs + ($minutesTotalStored * 60);
					if ($leaveStartTs === $startBoundaryTs && $leaveEndTs === $expectedEndTs) {
						$minutesOnly = true;
					}
				}

				$isHoursLeave = (!$minutesOnly && $durationMinutes > 0 && $durationMinutes < 1440);
				$isPartialLeave = $isHoursLeave || $minutesOnly || ((int) $obj->halfday !== 0);

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
					$startIso = dol_print_date($leaveStartTs, '%Y-%m-%d');
					$endIso = dol_print_date($leaveEndTs - 1, '%Y-%m-%d');
				} else {
					$startBoundaryTs = self::shiftplanner_operational_boundary_for_timestamp($leaveStartTs, $dayChangeHour);
					$lastDayBoundaryTs = self::shiftplanner_operational_boundary_for_timestamp($leaveEndTs - 1, $dayChangeHour);
					$startDayTs = $startBoundaryTs;
					$endDayTs = $lastDayBoundaryTs + 86400;
					$startIso = dol_print_date($startBoundaryTs, '%Y-%m-%d');
					$endIso = dol_print_date($lastDayBoundaryTs, '%Y-%m-%d');
				}
				$entityId = isset($obj->leave_entity) ? (int) $obj->leave_entity : (int) $conf->entity;
				$entityLabel = isset($obj->entity_label) ? (string) $obj->entity_label : '';
				if ($entityLabel === '' && $entityId !== 0) {
					$entityLabel = 'E' . $entityId;
				}
				$foreignEntity = ($entityId !== (int) $conf->entity) ? 1 : 0;

				$leaves[$userId][] = array(
					'id' => (int) $obj->rowid,
					'empId' => $userId,
					'type_id' => (int) $obj->type_id,
					'entity' => $entityId,
					'entity_label' => $entityLabel,
					'foreign_entity' => $foreignEntity,
					'start' => $startDayTs,
					'end' => $endDayTs,
					'halfday' => (int) $obj->halfday,
					'type' => $obj->type_label ?: ($obj->type_code ?: 'Leave'),
					'code' => $obj->type_code ?: '',
					'color' => $obj->color ?: '#FFA500',
					'start_iso' => $startIso,
					'end_iso' => $endIso,
					'description' => $cleanDescription,
					'is_hours' => $isHoursLeave ? 1 : 0,
					'minutes_only' => $minutesOnly ? 1 : 0,
					'hours_range' => $hoursRangeLabel,
					'hours_start' => $hoursStartLabel,
					'hours_end' => $hoursEndLabel,
					'hours_total_minutes' => $hoursTotalMinutes,
					'start_ts' => $leaveStartTs,
					'end_ts' => $endDayTs
				);
			}
			$db->free($resql);
		} else {
			if ($db->lasterrno() === 'DB_ERROR_NOSUCHTABLE') {
				dol_syslog(__METHOD__ . ' leave table missing, returning empty set', LOG_WARNING);
			} else {
				dol_syslog(__METHOD__ . ' leave query failed: ' . $db->lasterror(), LOG_ERR);
				http_response_code(500);
				echo json_encode(array('error' => 'Database error', 'details' => $db->lasterror()));
				exit;
			}
		}

		$holidayCountryId = (int) getDolGlobalInt('DOLIZSYNCH_PUBLIC_HOLIDAY_COUNTRY', 25);
		$rangeStartIso = dol_print_date($startTs, '%Y-%m-%d');
		$rangeEndExclusiveIso = dol_print_date($endTsRaw, '%Y-%m-%d');
		$publicHolidays = shiftplanner_fetch_public_holidays($db, $holidayCountryId, $rangeStartIso, $rangeEndExclusiveIso);

		echo json_encode(array(
			'dayoffs' => $dayoffs,
			'leaves' => $leaves,
			'public_holidays' => $publicHolidays,
		));
		exit;
	}

	public static function listPublicHolidays(DoliDB $db)
	{
		$startIso = trim((string) GETPOST('start', 'alphanohtml'));
		$endIso = trim((string) GETPOST('end', 'alphanohtml'));
		if ($startIso === '' || $endIso === '') {
			http_response_code(400);
			echo json_encode(array('error' => 'Missing date range'));
			exit;
		}
		$startTs = dol_stringtotime($startIso);
		$endTsRaw = dol_stringtotime($endIso);
		if (empty($startTs) || empty($endTsRaw)) {
			http_response_code(400);
			echo json_encode(array('error' => 'Invalid date range'));
			exit;
		}
		$holidayCountryId = (int) getDolGlobalInt('DOLIZSYNCH_PUBLIC_HOLIDAY_COUNTRY', 25);
		$rangeStartIso = dol_print_date($startTs, '%Y-%m-%d');
		$rangeEndExclusiveIso = dol_print_date($endTsRaw, '%Y-%m-%d');
		$publicHolidays = shiftplanner_fetch_public_holidays($db, $holidayCountryId, $rangeStartIso, $rangeEndExclusiveIso);
		echo json_encode(array('public_holidays' => $publicHolidays));
		exit;
	}

	public static function createDayOff(DoliDB $db, $shiftTable, User $user)
	{
		global $conf;

		if (empty($user->rights->dolizsynch->shift->write)) {
			http_response_code(403);
			echo json_encode(array('error' => 'Forbidden'));
			exit;
		}

		$userId = GETPOST('user_id', 'int');
		$dateValue = trim((string) GETPOST('dayoff_date', 'alphanohtml'));
		$isRecurring = GETPOST('dayoff_recurring', 'int') ? 1 : 0;
		$description = trim((string) GETPOST('dayoff_description', 'restricthtml'));
		$isLock = GETPOST('dayoff_is_lock', 'int') ? 1 : 0;
		$hasLockColumn = self::shiftplannerDayOffHasLockColumn($db);

		if (empty($userId) || $dateValue === '') {
			http_response_code(400);
			echo json_encode(array('error' => 'Missing required fields'));
			exit;
		}

		$dateTs = dol_stringtotime($dateValue);
		if (empty($dateTs)) {
			http_response_code(400);
			echo json_encode(array('error' => 'Invalid date'));
			exit;
		}

		$dateMap = array($dateValue => $dateTs);
		$dayChangeHour = self::shiftplanner_get_day_change_hour();
		$dayStart = shiftplanner_parse_wall_clock_datetime(sprintf('%s %02d:00:00', $dateValue, $dayChangeHour));
		if (!$dayStart) {
			http_response_code(400);
			echo json_encode(array('error' => 'Invalid date'));
			exit;
		}
		if ($dayChangeHour > 0) {
			$dayStart = $dayStart->modify('+1 second');
		}
		$dayEnd = $dayStart->modify('+1 day');
		$dayRange = array(
			'start_sql' => $dayStart->format('Y-m-d H:i:s'),
			'end_sql' => $dayEnd->format('Y-m-d H:i:s'),
		);
		if (self::shiftplannerHasLeaveOverlap($db, $userId, $dayRange['start_sql'], $dayRange['end_sql'])) {
			http_response_code(409);
			echo json_encode(array('error' => 'Funcionário já possui ausência aprovada neste dia'));
			exit;
		}
		if (self::shiftplannerHasDayOffOnDates($db, $userId, $dateMap)) {
			http_response_code(409);
			echo json_encode(array('error' => 'Já existe um Day Off registado para este dia'));
			exit;
		}

		$dayStartSql = $dayRange['start_sql'];
		$dayEndSql = $dayRange['end_sql'];
		if (self::shiftplannerHasOverlap($db, $shiftTable, $userId, $dayStartSql, $dayEndSql)) {
			http_response_code(409);
			echo json_encode(array('error' => 'Já existem turnos registados para este dia'));
			exit;
		}

		$dayoff = new DayOff($db);
		$dayoff->entity = (int) $conf->entity;
		$dayoff->fk_user = $userId;
		$dayoff->date_dayoff = $dateTs;
		$dayoff->is_recurring = $isRecurring ? 1 : 0;
		$dayoff->description = $description;
		$dayoff->is_lock = $hasLockColumn ? $isLock : 0;

		$result = $dayoff->create($user);
		if ($result < 0) {
			http_response_code(500);
			echo json_encode(array('error' => 'Database error', 'details' => $dayoff->error));
			exit;
		}

		$logContext = array(
			'dayoff_id' => (int) $result,
			'employee_id' => (int) $userId,
			'date' => $dateValue,
			'recurring' => (int) $isRecurring,
		);
		if ($hasLockColumn) {
			$logContext['is_lock'] = (int) $isLock;
		}
		self::logScheduleChange('dayoff_create', $user, $logContext);
		echo json_encode(array('success' => true, 'dayoff_id' => $result));
		exit;
	}

	public static function deleteDayOff(DoliDB $db, User $user)
	{
		global $conf;

		if (empty($user->rights->dolizsynch->shift->write)) {
			http_response_code(403);
			echo json_encode(array('error' => 'Forbidden'));
			exit;
		}

		$dayoffId = GETPOST('dayoff_id', 'int');
		if (empty($dayoffId)) {
			http_response_code(400);
			echo json_encode(array('error' => 'Missing dayoff_id'));
			exit;
		}

		$dayoff = new DayOff($db);
		if ($dayoff->fetch($dayoffId) <= 0) {
			http_response_code(404);
			echo json_encode(array('error' => 'Day off not found'));
			exit;
		}
		if ($dayoff->entity !== null && (int) $dayoff->entity !== (int) $conf->entity) {
			http_response_code(403);
			echo json_encode(array('error' => 'wrong_entity'));
			exit;
		}

		$result = $dayoff->delete($user);
		if ($result < 0) {
			http_response_code(500);
			echo json_encode(array('error' => 'Unable to delete day off', 'details' => $dayoff->error));
			exit;
		}

		$dateLabel = $dayoff->date_dayoff;
		if ($dateLabel !== null && $dateLabel !== '' && is_numeric($dateLabel)) {
			$dateLabel = dol_print_date($dateLabel, '%Y-%m-%d');
		}
		$logContext = array(
			'dayoff_id' => (int) $dayoffId,
			'employee_id' => (int) $dayoff->fk_user,
			'date' => $dateLabel,
			'recurring' => (int) $dayoff->is_recurring,
		);
		if (property_exists($dayoff, 'is_lock')) {
			$logContext['is_lock'] = (int) $dayoff->is_lock;
		}
		self::logScheduleChange('dayoff_delete', $user, $logContext);
		echo json_encode(array('success' => true));
		exit;
	}

	public static function convertShiftToLeave(DoliDB $db, $shiftTable, $leaveTable, User $user)
	{
		global $conf;

		if (empty($user->rights->dolizsynch->shift->write) || empty($user->rights->dolizsynch->shift->delete)) {
			http_response_code(403);
			echo json_encode(array('error' => 'Forbidden'));
			exit;
		}

		$shiftId = GETPOST('shift_id', 'int');
		$typeId = GETPOST('leave_type', 'int');
		$description = trim((string) GETPOST('leave_description', 'restricthtml'));

		if (empty($shiftId) || empty($typeId)) {
			http_response_code(400);
			echo json_encode(array('error' => 'Missing required fields'));
			exit;
		}

		$currentShift = self::shiftplannerFetchShift($db, $shiftTable, $shiftId);
		if (!$currentShift) {
			http_response_code(404);
			echo json_encode(array('error' => 'Shift not found'));
			exit;
		}
		if ((int) $currentShift->entity !== (int) $conf->entity) {
			http_response_code(403);
			echo json_encode(array('error' => 'wrong_entity'));
			exit;
		}

		$userId = (int) $currentShift->fk_user;
		$startSql = (string) $currentShift->date_start;
		$endSql = (string) $currentShift->date_end;
		$startTs = self::shiftplannerNormalizeTimestamp($db, $startSql);
		$endTs = self::shiftplannerNormalizeTimestamp($db, $endSql);
		if (empty($startTs) || empty($endTs) || $endTs <= $startTs) {
			http_response_code(400);
			echo json_encode(array('error' => 'Invalid shift period'));
			exit;
		}

		$dateMap = self::shiftplannerBuildOperationalDateMap($startSql, $endSql);
		if (self::shiftplannerHasDayOffOnDates($db, $userId, $dateMap)) {
			http_response_code(409);
			echo json_encode(array('error' => 'Já existe um Day Off registado no período selecionado'));
			exit;
		}
		if (self::shiftplannerHasLeaveOverlap($db, $userId, $startSql, $endSql, false)) {
			http_response_code(409);
			echo json_encode(array('error' => 'Já existe outra ausência aprovada neste período'));
			exit;
		}
		if (self::shiftplannerHasOverlap($db, $shiftTable, $userId, $startSql, $endSql, $shiftId)) {
			http_response_code(409);
			echo json_encode(array('error' => 'Existem turnos registados neste período'));
			exit;
		}

		$durationMinutes = self::shiftplannerComputeMinutesBetween($startTs, $endTs);
		$halfdayValue = ($durationMinutes > 0 && $durationMinutes < 1440) ? 2 : 0;
		$dayChangeHour = self::shiftplanner_get_day_change_hour();
		if ($description === '') {
			$description = trim((string) ($currentShift->note ?? ''));
		}

		$db->begin();

		$sqlInsert = "INSERT INTO " . $leaveTable . " (fk_user, fk_type, date_start, date_end, halfday, minutes_total, description, day_change_hour, created_by, fk_user_modif, entity)";
		$sqlInsert .= " VALUES (";
		$sqlInsert .= (int) $userId . ", ";
		$sqlInsert .= (int) $typeId . ", ";
		$sqlInsert .= "'" . $db->escape($startSql) . "', ";
		$sqlInsert .= "'" . $db->escape($endSql) . "', ";
		$sqlInsert .= (int) $halfdayValue . ", ";
		$sqlInsert .= "0, ";
		$sqlInsert .= ($description !== '' ? "'" . $db->escape($description) . "'" : "NULL") . ", ";
		$sqlInsert .= (int) $dayChangeHour . ", ";
		$sqlInsert .= (int) $user->id . ", ";
		$sqlInsert .= (int) $user->id . ", ";
		$sqlInsert .= (int) $conf->entity;
		$sqlInsert .= ")";

		if (!$db->query($sqlInsert)) {
			$db->rollback();
			http_response_code(500);
			echo json_encode(array('error' => 'Database error', 'details' => $db->lasterror()));
			exit;
		}

		$leaveId = $db->last_insert_id($leaveTable, 'rowid');

		$sqlDelete = "DELETE FROM " . $shiftTable;
		$sqlDelete .= " WHERE rowid = " . (int) $shiftId;
		$sqlDelete .= " AND entity = " . (int) $conf->entity;
		if (!$db->query($sqlDelete)) {
			$db->rollback();
			http_response_code(500);
			echo json_encode(array('error' => 'Unable to delete shift', 'details' => $db->lasterror()));
			exit;
		}

		$db->commit();

		$logContext = array(
			'shift_id' => (int) $shiftId,
			'leave_id' => (int) $leaveId,
			'employee_id' => (int) $userId,
			'type_id' => (int) $typeId,
			'start' => $startSql,
			'end' => $endSql,
			'halfday' => (int) $halfdayValue,
		);
		if ($description !== '') {
			$logContext['description'] = $description;
		}
		if (property_exists($currentShift, 'shift_status') && $currentShift->shift_status !== null) {
			$logContext['shift_status_from'] = self::normalizeShiftStatus($currentShift->shift_status);
		}
		self::logScheduleChange('shift_convert_to_leave', $user, $logContext);

		echo json_encode(array(
			'success' => true,
			'leave_id' => (int) $leaveId,
			'shift_id' => (int) $shiftId,
		));
		exit;
	}

	public static function convertLeaveToShift(DoliDB $db, $shiftTable, $leaveTable, User $user, $taskTable = '')
	{
		global $conf;

		if (empty($user->rights->dolizsynch->shift->write)) {
			http_response_code(403);
			echo json_encode(array('error' => 'Forbidden'));
			exit;
		}

		$leaveId = GETPOST('leave_id', 'int');
		$shiftType = (int) GETPOST('shift_type', 'int');
		if (!in_array($shiftType, array(1, 2), true)) {
			$shiftType = 1;
		}

		if (empty($leaveId)) {
			http_response_code(400);
			echo json_encode(array('error' => 'Missing leave_id'));
			exit;
		}

		$currentLeave = self::shiftplannerFetchLeave($db, $leaveTable, $leaveId);
		if (!$currentLeave) {
			http_response_code(404);
			echo json_encode(array('error' => 'Leave not found'));
			exit;
		}
		if ((int) $currentLeave->entity !== (int) $conf->entity) {
			http_response_code(403);
			echo json_encode(array('error' => 'wrong_entity'));
			exit;
		}

		$leaveType = self::shiftplannerFetchHolidayType($db, (int) $currentLeave->fk_type);
		$statusFromLeaveType = '';
		if ($leaveType) {
			$statusFromLeaveType = self::resolveMissedShiftStatusFromLeaveType(
				isset($leaveType->code) ? $leaveType->code : '',
				isset($leaveType->label) ? $leaveType->label : ''
			);
		}
		if ($statusFromLeaveType !== 'missed_unjustified' && $statusFromLeaveType !== 'missed_justified' && $statusFromLeaveType !== 'missed') {
			http_response_code(400);
			echo json_encode(array('error' => 'This leave type cannot be converted back to shift'));
			exit;
		}
		$shiftStatus = 'scheduled';
		$shiftNoteProvided = function_exists('GETPOSTISSET') ? GETPOSTISSET('shift_note') : (isset($_POST['shift_note']) || isset($_GET['shift_note']));
		$shiftNoteRequested = $shiftNoteProvided ? trim((string) GETPOST('shift_note', 'restricthtml')) : '';
		$leaveDescription = trim((string) ($currentLeave->description ?? ''));
		$leaveDescriptionInfo = self::shiftplannerExtractLeaveHours($leaveDescription);
		if (is_array($leaveDescriptionInfo) && array_key_exists('description', $leaveDescriptionInfo)) {
			$leaveDescription = trim((string) $leaveDescriptionInfo['description']);
		}
		$shiftNote = ($shiftNoteRequested !== '') ? $shiftNoteRequested : $leaveDescription;

		$userId = (int) $currentLeave->fk_user;
		$startSql = (string) $currentLeave->date_start;
		$endSql = (string) $currentLeave->date_end;
		$startTs = self::shiftplannerNormalizeTimestamp($db, $startSql);
		$endTs = self::shiftplannerNormalizeTimestamp($db, $endSql);
		if (empty($startTs) || empty($endTs) || $endTs <= $startTs) {
			http_response_code(400);
			echo json_encode(array('error' => 'Invalid leave period'));
			exit;
		}

		$maxMinutes = self::shiftplanner_get_max_shift_minutes();
		if ($maxMinutes > 0) {
			$durationMinutes = self::shiftplannerComputeMinutesBetween($startTs, $endTs);
			if ($durationMinutes <= 0 || $durationMinutes > $maxMinutes) {
				http_response_code(400);
				echo json_encode(array(
					'error' => 'Shift duration exceeds the configured maximum of ' . ($maxMinutes / 60) . ' hours'
				));
				exit;
			}
		}

		$dateMap = self::shiftplannerBuildOperationalDateMap($startSql, $endSql);
		if (self::shiftplannerHasDayOffOnDates($db, $userId, $dateMap)) {
			http_response_code(409);
			echo json_encode(array('error' => 'Já existe um Day Off registado no período selecionado'));
			exit;
		}
		if (self::shiftplannerHasLeaveOverlap($db, $userId, $startSql, $endSql, false, $leaveId)) {
			http_response_code(409);
			echo json_encode(array('error' => 'Já existe outra ausência aprovada neste período'));
			exit;
		}
		if (self::shiftplannerHasOverlap($db, $shiftTable, $userId, $startSql, $endSql)) {
			http_response_code(409);
			echo json_encode(array('error' => 'Existem turnos registados neste período'));
			exit;
		}

		$resolvedTaskId = 0;
		$resolvedColor = self::sanitizeColor(getDolGlobalString('DOLIZSYNCH_LEAVE_COLOR', '#cfcfcf'));
		$requestedTaskId = (int) GETPOST('fk_task', 'int');
		if ($requestedTaskId <= 0) {
			$requestedTaskId = self::shiftplannerFetchUserMainTaskId($db, $userId);
		}
		if (!empty($taskTable) && $requestedTaskId > 0) {
			$task = self::shiftplannerFetchTask($db, $taskTable, $requestedTaskId);
			if ($task) {
				$resolvedTaskId = (int) $task->rowid;
				$taskColor = self::sanitizeColor($task->color ?? '');
				if ($taskColor !== '') {
					$resolvedColor = $taskColor;
				}
			}
		}

		$hasShiftType = self::shiftplannerTableHasColumn($db, $shiftTable, 'shift_type');
		$hasShiftStatus = self::shiftplannerTableHasColumn($db, $shiftTable, 'shift_status');
		if ($hasShiftStatus) {
			$shiftStatus = self::shiftplannerPrepareShiftStatusForStorage($db, $shiftTable, $shiftStatus);
		}

		$db->begin();

		$sqlInsert = "INSERT INTO " . $shiftTable . " (fk_user, fk_task, label, date_start, date_end, color, note";
		if ($hasShiftType) {
			$sqlInsert .= ", shift_type";
		}
		if ($hasShiftStatus) {
			$sqlInsert .= ", shift_status";
		}
		$sqlInsert .= ", created_by, entity)";
		$sqlInsert .= " VALUES (";
		$sqlInsert .= (int) $userId . ", ";
		$sqlInsert .= ($resolvedTaskId > 0 ? (int) $resolvedTaskId : "NULL") . ", ";
		$sqlInsert .= "NULL, ";
		$sqlInsert .= "'" . $db->escape($startSql) . "', ";
		$sqlInsert .= "'" . $db->escape($endSql) . "', ";
		$sqlInsert .= ($resolvedColor !== '' ? "'" . $db->escape($resolvedColor) . "'" : "NULL") . ", ";
		$sqlInsert .= ($shiftNote !== '' ? "'" . $db->escape($shiftNote) . "'" : "NULL") . ", ";
		if ($hasShiftType) {
			$sqlInsert .= (int) $shiftType . ", ";
		}
		if ($hasShiftStatus) {
			$sqlInsert .= "'" . $db->escape($shiftStatus) . "', ";
		}
		$sqlInsert .= (int) $user->id . ", ";
		$sqlInsert .= (int) $conf->entity;
		$sqlInsert .= ")";

		if (!$db->query($sqlInsert)) {
			$db->rollback();
			http_response_code(500);
			echo json_encode(array('error' => 'Database error', 'details' => $db->lasterror()));
			exit;
		}

		$shiftId = $db->last_insert_id($shiftTable, 'rowid');

		$sqlDelete = "DELETE FROM " . $leaveTable;
		$sqlDelete .= " WHERE rowid = " . (int) $leaveId;
		$sqlDelete .= " AND entity = " . (int) $conf->entity;
		if (!$db->query($sqlDelete)) {
			$db->rollback();
			http_response_code(500);
			echo json_encode(array('error' => 'Unable to delete leave', 'details' => $db->lasterror()));
			exit;
		}

		$db->commit();

		$logContext = array(
			'leave_id' => (int) $leaveId,
			'shift_id' => (int) $shiftId,
			'employee_id' => (int) $userId,
			'type_id' => (int) $currentLeave->fk_type,
			'start' => $startSql,
			'end' => $endSql,
			'shift_type' => (int) $shiftType,
			'shift_status' => $shiftStatus,
			'color' => $resolvedColor,
		);
		if ($shiftNote !== '') {
			$logContext['note'] = $shiftNote;
		}
		if ($resolvedTaskId > 0) {
			$logContext['task_id'] = (int) $resolvedTaskId;
		}
		if ($leaveType) {
			$logContext['leave_type_code'] = isset($leaveType->code) ? (string) $leaveType->code : '';
		}
		self::logScheduleChange('leave_convert_to_shift', $user, $logContext);

		echo json_encode(array(
			'success' => true,
			'shift_id' => (int) $shiftId,
			'leave_id' => (int) $leaveId,
			'shift_status' => $shiftStatus,
			'task_id' => ($resolvedTaskId > 0 ? (int) $resolvedTaskId : null),
			'color' => $resolvedColor,
		));
		exit;
	}

	public static function createLeave(DoliDB $db, $shiftTable, $leaveTable, User $user)
	{
		global $conf;

		if (empty($user->rights->dolizsynch->shift->write)) {
			http_response_code(403);
			echo json_encode(array('error' => 'Forbidden'));
			exit;
		}

		$userId = GETPOST('user_id', 'int');
		$typeId = GETPOST('leave_type', 'int');
		$startValue = trim((string) GETPOST('leave_start', 'alphanohtml'));
		$endValue = trim((string) GETPOST('leave_end', 'alphanohtml'));
		$halfdayRaw = GETPOST('leave_halfday', 'alpha');
		$halfdayMode = self::shiftplannerNormalizePartialMode($halfdayRaw);
		$hoursStartValue = trim((string) GETPOST('leave_hours_start', 'alphanohtml'));
		$hoursEndValue = trim((string) GETPOST('leave_hours_end', 'alphanohtml'));
		$minutesTotalValue = GETPOST('leave_minutes_total', 'int');
		$description = trim((string) GETPOST('leave_description', 'restricthtml'));
		$isHoursLeave = ($halfdayMode === 'hours');
		$isMinutesLeave = ($halfdayMode === 'minutes');
		$isHalfday = ($halfdayMode === 'halfday');
		$isPartialRequest = $isHoursLeave || $isMinutesLeave || $isHalfday || ($halfdayRaw !== '' && $halfdayRaw !== '0');
		$dayChangeHour = self::shiftplanner_get_day_change_hour();
		$startTimeParts = null;
		$endTimeParts = null;
		$minutesTotal = 0;

		if ($isPartialRequest) {
			$endValue = $startValue;
		}

		if (empty($userId) || empty($typeId) || $startValue === '' || $endValue === '') {
			http_response_code(400);
			echo json_encode(array('error' => 'Missing required fields'));
			exit;
		}

		if ($isHoursLeave) {
			$startTimeParts = self::shiftplannerParseTimeValue($hoursStartValue);
			$endTimeParts = self::shiftplannerParseTimeValue($hoursEndValue);
			if (!$startTimeParts || !$endTimeParts) {
				http_response_code(400);
				echo json_encode(array('error' => 'Horário inválido para ausência por horas'));
				exit;
			}
			list($startYear) = self::shiftplannerParseDateParts($startValue);
			if (!$startYear) {
				http_response_code(400);
				echo json_encode(array('error' => 'Data inválida'));
				exit;
			}
			$endDateValue = $startValue;
			if (($endTimeParts['hour'] * 60 + $endTimeParts['minute']) <= ($startTimeParts['hour'] * 60 + $startTimeParts['minute'])) {
				$startDay = shiftplanner_parse_wall_clock_datetime($startValue . ' 00:00:00');
				$endDateValue = $startDay ? $startDay->modify('+1 day')->format('Y-m-d') : '';
			}
			$startSql = sprintf('%s %02d:%02d:00', $startValue, $startTimeParts['hour'], $startTimeParts['minute']);
			$endSql = sprintf('%s %02d:%02d:00', $endDateValue, $endTimeParts['hour'], $endTimeParts['minute']);
			$startDate = shiftplanner_parse_wall_clock_datetime($startSql);
			$endDate = shiftplanner_parse_wall_clock_datetime($endSql);
			if (!$startDate || !$endDate || $endDate <= $startDate) {
				http_response_code(400);
				echo json_encode(array('error' => 'Intervalo de horas inválido'));
				exit;
			}
			$startTs = (int) $startDate->format('U');
			$endExclusiveTs = (int) $endDate->format('U');
			$minutesTotal = self::shiftplannerComputeMinutesBetween($startTs, $endExclusiveTs);
		} elseif ($isMinutesLeave) {
			$minutesTotal = (int) $minutesTotalValue;
			if ($minutesTotal <= 0) {
				http_response_code(400);
				echo json_encode(array('error' => 'Indique o total de minutos da ausência'));
				exit;
			}
			$startSql = sprintf('%s %02d:00:00', $startValue, $dayChangeHour);
			$startDate = shiftplanner_parse_wall_clock_datetime($startSql);
			$endDate = $startDate ? $startDate->modify('+' . $minutesTotal . ' minutes') : null;
			if (!$startDate || !$endDate || $endDate <= $startDate) {
				http_response_code(400);
				echo json_encode(array('error' => 'Data inválida'));
				exit;
			}
			$endSql = $endDate ? $endDate->format('Y-m-d H:i:s') : '';
			$startTs = $startDate ? (int) $startDate->format('U') : 0;
			$endExclusiveTs = $endDate ? (int) $endDate->format('U') : 0;
		} else {
			$startSql = sprintf('%s %02d:00:00', $startValue, $dayChangeHour);
			$lastDay = shiftplanner_parse_wall_clock_datetime($endValue . ' 00:00:00');
			$endSql = $lastDay ? sprintf('%s %02d:00:00', $lastDay->modify('+1 day')->format('Y-m-d'), $dayChangeHour) : '';
			$startDate = shiftplanner_parse_wall_clock_datetime($startSql);
			$endDate = shiftplanner_parse_wall_clock_datetime($endSql);
			$startTs = $startDate ? (int) $startDate->format('U') : 0;
			$endExclusiveTs = $endDate ? (int) $endDate->format('U') : 0;
			if (!$startDate || !$endDate || $endDate <= $startDate) {
				http_response_code(400);
				echo json_encode(array('error' => 'Invalid date range'));
				exit;
			}
		}

		$halfdayValue = 0;
		if ($isHalfday) $halfdayValue = 1;
		elseif ($isHoursLeave) $halfdayValue = 2;
		elseif ($isMinutesLeave) $halfdayValue = 3;
		$overlapStartSql = $startSql;
		$overlapEndSql = $endSql;
		if ($isMinutesLeave) {
			$overlapEndSql = $startDate->modify('+1 day')->format('Y-m-d H:i:s');
		}
		if (!$isHoursLeave) {
			$effectiveRange = shiftplanner_build_effective_leave_range(
				$overlapStartSql,
				$overlapEndSql,
				$dayChangeHour
			);
			if (empty($effectiveRange)) {
				http_response_code(400);
				echo json_encode(array('error' => 'Invalid date range'));
				exit;
			}
			$overlapStartSql = $effectiveRange['start_sql'];
			$overlapEndSql = $effectiveRange['end_sql'];
		}
		$dateMap = self::shiftplannerBuildOperationalDateMap(
			$overlapStartSql,
			$overlapEndSql,
			$dayChangeHour
		);
		if (self::shiftplannerHasDayOffOnDates($db, $userId, $dateMap)) {
			http_response_code(409);
			echo json_encode(array('error' => 'Já existe um Day Off registado no período selecionado'));
			exit;
		}
		if (self::shiftplannerHasLeaveOverlap($db, $userId, $overlapStartSql, $overlapEndSql, false)) {
			http_response_code(409);
			echo json_encode(array('error' => 'Já existe outra ausência aprovada neste período'));
			exit;
		}

		if (self::shiftplannerHasOverlap($db, $shiftTable, $userId, $overlapStartSql, $overlapEndSql)) {
			http_response_code(409);
			echo json_encode(array('error' => 'Existem turnos registados neste período'));
			exit;
		}

		$storedMinutes = 0;
		if ($isMinutesLeave) {
			$storedMinutes = $minutesTotal;
		}

		$sql = "INSERT INTO " . $leaveTable . " (fk_user, fk_type, date_start, date_end, halfday, minutes_total, description, day_change_hour, created_by, fk_user_modif, entity)";
		$sql .= " VALUES (";
		$sql .= (int) $userId . ", ";
		$sql .= (int) $typeId . ", ";
		$sql .= "'" . $db->escape($startSql) . "', ";
		$sql .= "'" . $db->escape($endSql) . "', ";
		$sql .= (int) $halfdayValue . ", ";
		$sql .= (int) $storedMinutes . ", ";
		$sql .= ($description !== '' ? "'" . $db->escape($description) . "'" : "NULL") . ", ";
		$sql .= (int) $dayChangeHour . ", ";
		$sql .= (int) $user->id . ", ";
		$sql .= (int) $user->id . ", ";
		$sql .= (int) $conf->entity;
		$sql .= ")";

		if (!$db->query($sql)) {
			http_response_code(500);
			echo json_encode(array('error' => 'Database error', 'details' => $db->lasterror()));
			exit;
		}

		$leaveId = $db->last_insert_id($leaveTable, 'rowid');

		$logContext = array(
			'leave_id' => (int) $leaveId,
			'employee_id' => (int) $userId,
			'type_id' => (int) $typeId,
			'start' => $startSql,
			'end' => $endSql,
			'halfday' => (int) $halfdayValue,
			'minutes_total' => (int) $storedMinutes,
		);
		self::logScheduleChange('leave_create', $user, $logContext);
		echo json_encode(array('success' => true, 'leave_id' => (int) $leaveId));
		exit;
	}

	public static function deleteLeave(DoliDB $db, $leaveTable, User $user)
	{
		global $conf;

		if (empty($user->rights->dolizsynch->shift->write)) {
			http_response_code(403);
			echo json_encode(array('error' => 'Forbidden'));
			exit;
		}

		$leaveId = GETPOST('leave_id', 'int');
		if (empty($leaveId)) {
			http_response_code(400);
			echo json_encode(array('error' => 'Missing leave_id'));
			exit;
		}

		$current = self::shiftplannerFetchLeave($db, $leaveTable, $leaveId);
		if (!$current) {
			http_response_code(404);
			echo json_encode(array('error' => 'Leave not found'));
			exit;
		}
		$currentEntity = isset($current->entity) ? (int) $current->entity : 0;
		if ($currentEntity !== (int) $conf->entity) {
			http_response_code(403);
			echo json_encode(array('error' => 'wrong_entity'));
			exit;
		}

		$sql = "DELETE FROM " . $leaveTable . " WHERE rowid = " . (int) $leaveId;
		$sql .= " AND entity = " . (int) $conf->entity;
		if (!$db->query($sql)) {
			http_response_code(500);
			echo json_encode(array('error' => 'Unable to delete leave', 'details' => $db->lasterror()));
			exit;
		}

		$logContext = array(
			'leave_id' => (int) $leaveId,
			'employee_id' => (int) $current->fk_user,
			'type_id' => (int) $current->fk_type,
			'start' => $current->date_start,
			'end' => $current->date_end,
			'halfday' => (int) $current->halfday,
			'minutes_total' => (int) $current->minutes_total,
		);
		self::logScheduleChange('leave_delete', $user, $logContext);
		echo json_encode(array('success' => true));
		exit;
	}

	public static function updateLeave(DoliDB $db, $shiftTable, $leaveTable, User $user)
	{
		global $conf;

		if (empty($user->rights->dolizsynch->shift->write)) {
			http_response_code(403);
			echo json_encode(array('error' => 'Forbidden'));
			exit;
		}

		$leaveId = GETPOST('leave_id', 'int');
		if (empty($leaveId)) {
			http_response_code(400);
			echo json_encode(array('error' => 'Missing leave_id'));
			exit;
		}

		$current = self::shiftplannerFetchLeave($db, $leaveTable, $leaveId);
		if (!$current) {
			http_response_code(404);
			echo json_encode(array('error' => 'Leave not found'));
			exit;
		}
		$currentEntity = isset($current->entity) ? (int) $current->entity : 0;
		if ($currentEntity !== (int) $conf->entity) {
			http_response_code(403);
			echo json_encode(array('error' => 'wrong_entity'));
			exit;
		}

		$userId = GETPOST('user_id', 'int');
		if (empty($userId)) {
			$userId = (int) $current->fk_user;
		}
		$typeId = GETPOST('leave_type', 'int');
		$startValue = trim((string) GETPOST('leave_start', 'alphanohtml'));
		$endValue = trim((string) GETPOST('leave_end', 'alphanohtml'));
		$halfdayRaw = GETPOST('leave_halfday', 'alpha');
		$halfdayMode = self::shiftplannerNormalizePartialMode($halfdayRaw);
		$hoursStartValue = trim((string) GETPOST('leave_hours_start', 'alphanohtml'));
		$hoursEndValue = trim((string) GETPOST('leave_hours_end', 'alphanohtml'));
		$minutesTotalValue = GETPOST('leave_minutes_total', 'int');
		$description = trim((string) GETPOST('leave_description', 'restricthtml'));
		$isHoursLeave = ($halfdayMode === 'hours');
		$isMinutesLeave = ($halfdayMode === 'minutes');
		$isHalfday = ($halfdayMode === 'halfday');
		$isPartialRequest = $isHoursLeave || $isMinutesLeave || $isHalfday || ($halfdayRaw !== '' && $halfdayRaw !== '0');
		$dayChangeHour = self::shiftplanner_get_day_change_hour();
		$startTimeParts = null;
		$endTimeParts = null;
		$minutesTotal = 0;

		if ($isPartialRequest) {
			$endValue = $startValue;
		}

		if (empty($typeId)) {
			$typeId = (int) $current->fk_type;
		}

		if ($isHoursLeave) {
			$startTimeParts = self::shiftplannerParseTimeValue($hoursStartValue);
			$endTimeParts = self::shiftplannerParseTimeValue($hoursEndValue);
			if (!$startTimeParts || !$endTimeParts) {
				http_response_code(400);
				echo json_encode(array('error' => 'Horário inválido para ausência por horas'));
				exit;
			}
			list($startYear) = self::shiftplannerParseDateParts($startValue);
			if (!$startYear) {
				http_response_code(400);
				echo json_encode(array('error' => 'Data inválida'));
				exit;
			}
			$endDateValue = $startValue;
			if (($endTimeParts['hour'] * 60 + $endTimeParts['minute']) <= ($startTimeParts['hour'] * 60 + $startTimeParts['minute'])) {
				$startDay = shiftplanner_parse_wall_clock_datetime($startValue . ' 00:00:00');
				$endDateValue = $startDay ? $startDay->modify('+1 day')->format('Y-m-d') : '';
			}
			$startSql = sprintf('%s %02d:%02d:00', $startValue, $startTimeParts['hour'], $startTimeParts['minute']);
			$endSql = sprintf('%s %02d:%02d:00', $endDateValue, $endTimeParts['hour'], $endTimeParts['minute']);
			$startDate = shiftplanner_parse_wall_clock_datetime($startSql);
			$endDate = shiftplanner_parse_wall_clock_datetime($endSql);
			if (!$startDate || !$endDate || $endDate <= $startDate) {
				http_response_code(400);
				echo json_encode(array('error' => 'Intervalo de horas inválido'));
				exit;
			}
			$startTs = (int) $startDate->format('U');
			$endExclusiveTs = (int) $endDate->format('U');
			$minutesTotal = self::shiftplannerComputeMinutesBetween($startTs, $endExclusiveTs);
		} elseif ($isMinutesLeave) {
			$minutesTotal = (int) $minutesTotalValue;
			if ($minutesTotal <= 0) {
				http_response_code(400);
				echo json_encode(array('error' => 'Indique o total de minutos da ausência'));
				exit;
			}
			$startSql = sprintf('%s %02d:00:00', $startValue, $dayChangeHour);
			$startDate = shiftplanner_parse_wall_clock_datetime($startSql);
			$endDate = $startDate ? $startDate->modify('+' . $minutesTotal . ' minutes') : null;
			if (!$startDate || !$endDate || $endDate <= $startDate) {
				http_response_code(400);
				echo json_encode(array('error' => 'Data inválida'));
				exit;
			}
			$endSql = $endDate ? $endDate->format('Y-m-d H:i:s') : '';
			$startTs = $startDate ? (int) $startDate->format('U') : 0;
			$endExclusiveTs = $endDate ? (int) $endDate->format('U') : 0;
		} else {
			$startSql = sprintf('%s %02d:00:00', $startValue, $dayChangeHour);
			$lastDay = shiftplanner_parse_wall_clock_datetime($endValue . ' 00:00:00');
			$endSql = $lastDay ? sprintf('%s %02d:00:00', $lastDay->modify('+1 day')->format('Y-m-d'), $dayChangeHour) : '';
			$startDate = shiftplanner_parse_wall_clock_datetime($startSql);
			$endDate = shiftplanner_parse_wall_clock_datetime($endSql);
			$startTs = $startDate ? (int) $startDate->format('U') : 0;
			$endExclusiveTs = $endDate ? (int) $endDate->format('U') : 0;
			if (!$startDate || !$endDate || $endDate <= $startDate) {
				http_response_code(400);
				echo json_encode(array('error' => 'Invalid date range'));
				exit;
			}
		}

		$halfdayValue = 0;
		if ($isHalfday) $halfdayValue = 1;
		elseif ($isHoursLeave) $halfdayValue = 2;
		elseif ($isMinutesLeave) $halfdayValue = 3;
		$storedMinutes = $isMinutesLeave ? $minutesTotal : 0;
		$overlapStartSql = $startSql;
		$overlapEndSql = $endSql;
		if ($isMinutesLeave) {
			$overlapEndSql = $startDate->modify('+1 day')->format('Y-m-d H:i:s');
		}
		if (!$isHoursLeave) {
			$effectiveRange = shiftplanner_build_effective_leave_range(
				$overlapStartSql,
				$overlapEndSql,
				$dayChangeHour
			);
			if (empty($effectiveRange)) {
				http_response_code(400);
				echo json_encode(array('error' => 'Invalid date range'));
				exit;
			}
			$overlapStartSql = $effectiveRange['start_sql'];
			$overlapEndSql = $effectiveRange['end_sql'];
		}
		$dateMap = self::shiftplannerBuildOperationalDateMap(
			$overlapStartSql,
			$overlapEndSql,
			$dayChangeHour
		);

		if (self::shiftplannerHasDayOffOnDates($db, $userId, $dateMap)) {
			http_response_code(409);
			echo json_encode(array('error' => 'Já existe um Day Off registado no período selecionado'));
			exit;
		}
		if (self::shiftplannerHasLeaveOverlap($db, $userId, $overlapStartSql, $overlapEndSql, false, $leaveId)) {
			http_response_code(409);
			echo json_encode(array('error' => 'Já existe outra ausência aprovada neste período'));
			exit;
		}

		if (self::shiftplannerHasOverlap($db, $shiftTable, $userId, $overlapStartSql, $overlapEndSql)) {
			http_response_code(409);
			echo json_encode(array('error' => 'Existem turnos registados neste período'));
			exit;
		}

		$changes = array();
		if ((int) $current->fk_user !== (int) $userId) {
			$changes['fk_user'] = array('from' => (int) $current->fk_user, 'to' => (int) $userId);
		}
		if ((int) $current->fk_type !== (int) $typeId) {
			$changes['fk_type'] = array('from' => (int) $current->fk_type, 'to' => (int) $typeId);
		}
		if ((string) $current->date_start !== (string) $startSql) {
			$changes['date_start'] = array('from' => $current->date_start, 'to' => $startSql);
		}
		if ((string) $current->date_end !== (string) $endSql) {
			$changes['date_end'] = array('from' => $current->date_end, 'to' => $endSql);
		}
		if ((int) $current->halfday !== (int) $halfdayValue) {
			$changes['halfday'] = array('from' => (int) $current->halfday, 'to' => (int) $halfdayValue);
		}
		if ((int) $current->minutes_total !== (int) $storedMinutes) {
			$changes['minutes_total'] = array('from' => (int) $current->minutes_total, 'to' => (int) $storedMinutes);
		}

		$sql = "UPDATE " . $leaveTable . " SET";
		$sql .= " fk_user = " . (int) $userId;
		$sql .= ", fk_type = " . (int) $typeId;
		$sql .= ", date_start = '" . $db->escape($startSql) . "'";
		$sql .= ", date_end = '" . $db->escape($endSql) . "'";
		$sql .= ", halfday = " . (int) $halfdayValue;
		$sql .= ", minutes_total = " . (int) $storedMinutes;
		$sql .= ", description = " . ($description !== '' ? "'" . $db->escape($description) . "'" : "NULL");
		$sql .= ", day_change_hour = " . (int) $dayChangeHour;
		$sql .= ", fk_user_modif = " . (int) $user->id;
		$sql .= " WHERE rowid = " . (int) $leaveId;
		$sql .= " AND entity = " . (int) $conf->entity;

		if (!$db->query($sql)) {
			http_response_code(500);
			echo json_encode(array('error' => 'Database error', 'details' => $db->lasterror()));
			exit;
		}

		$logContext = array(
			'leave_id' => (int) $leaveId,
			'employee_id' => (int) $userId,
			'type_id' => (int) $typeId,
			'start' => $startSql,
			'end' => $endSql,
			'halfday' => (int) $halfdayValue,
			'minutes_total' => (int) $storedMinutes,
		);
		if (!empty($changes)) {
			$logContext['changes'] = $changes;
		}
		self::logScheduleChange('leave_update', $user, $logContext);
		echo json_encode(array('success' => true));
		exit;
	}

	public static function updateQuota(DoliDB $db, $taskTable, User $user)
	{
		global $conf;

		$canEditHours = !empty($user->rights->dolizsynch->shift->edit_employee_hours);
		$canAssignMainTask = !empty($user->rights->dolizsynch->shift->write);

		if (!$canEditHours && !$canAssignMainTask) {
			http_response_code(403);
			echo json_encode(array('error' => 'Forbidden'));
			exit;
		}

		$empId = GETPOST('emp_id', 'int');
		if (empty($empId)) {
			http_response_code(400);
			echo json_encode(array('error' => 'Missing employee id'));
			exit;
		}
		$hoursProvided = GETPOSTISSET('hours');
		$mainTaskProvided = GETPOSTISSET('main_task_id');

		if (!$hoursProvided && !$mainTaskProvided) {
			http_response_code(400);
			echo json_encode(array('error' => 'No fields provided'));
			exit;
		}

		if ($hoursProvided && !$canEditHours) {
			http_response_code(403);
			echo json_encode(array('error' => 'Forbidden - No permission to edit employee hours'));
			exit;
		}

		if ($mainTaskProvided && !$canAssignMainTask) {
			http_response_code(403);
			echo json_encode(array('error' => 'Forbidden - No permission to assign main task'));
			exit;
		}

		$hoursValue = null;
		if ($hoursProvided) {
			$hoursRaw = trim((string) GETPOST('hours', 'alphanohtml'));
			$hoursValue = $hoursRaw === '' ? null : round((float) price2num($hoursRaw), 2);
		}

		$mainTaskValue = null;
		if ($mainTaskProvided) {
			$mainTaskRaw = GETPOST('main_task_id', 'int');
			if (!empty($mainTaskRaw) && $mainTaskRaw > 0) {
				$checkSql = "SELECT rowid FROM " . $taskTable . " WHERE rowid = " . (int) $mainTaskRaw . " LIMIT 1";
				$resCheck = $db->query($checkSql);
				if ($resCheck) {
					if ($db->num_rows($resCheck) === 0) {
						http_response_code(400);
						echo json_encode(array('error' => 'Invalid task id'));
						exit;
					}
					$db->free($resCheck);
				} else {
					http_response_code(500);
					echo json_encode(array('error' => 'Database error', 'details' => $db->lasterror()));
					exit;
				}
				$mainTaskValue = (int) $mainTaskRaw;
			}
		}

		$useExtrafieldHours = shiftplanner_use_extrafield_hours();
		$table = MAIN_DB_PREFIX . 'user_extrafields';
		$extrafieldsNeedsUpdate = ($useExtrafieldHours && $hoursProvided) || $mainTaskProvided;

		$db->begin();

		// Update contracted hours in the configured source
		if ($hoursProvided && !$useExtrafieldHours) {
			$hoursDbValue = $hoursValue === null ? "NULL" : (float) $hoursValue;
			$sql = "UPDATE " . MAIN_DB_PREFIX . "user SET weeklyhours = " . $hoursDbValue . " WHERE rowid = " . (int) $empId;
			if (!$db->query($sql)) {
				$db->rollback();
				http_response_code(500);
				echo json_encode(array('error' => 'Database error', 'details' => $db->lasterror()));
				exit;
			}
		}

		if ($extrafieldsNeedsUpdate) {
			$sql = "SELECT rowid FROM " . $table . " WHERE fk_object = " . (int) $empId . " LIMIT 1";
			$res = $db->query($sql);
			$exists = $res && $db->num_rows($res) > 0;
			if ($res) {
				$db->free($res);
			}

			$fields = array();
			if ($hoursProvided && $useExtrafieldHours) {
				$fields['horasefect'] = $hoursValue === null ? "NULL" : "'" . $db->escape($hoursValue) . "'";
			}
			if ($mainTaskProvided) {
				$fields['zs_main_task'] = $mainTaskValue === null ? "NULL" : (int) $mainTaskValue;
			}

			if (empty($fields)) {
				$db->rollback();
				http_response_code(400);
				echo json_encode(array('error' => 'No valid fields provided'));
				exit;
			}

			if ($exists) {
				$assignments = array();
				foreach ($fields as $column => $value) {
					$assignments[] = $column . " = " . $value;
				}
				$sql = "UPDATE " . $table . " SET " . implode(', ', $assignments) . " WHERE fk_object = " . (int) $empId;
			} else {
				$columns = array('fk_object');
				$values = array((int) $empId);
				foreach ($fields as $column => $value) {
					$columns[] = $column;
					$values[] = $value;
				}
				$sql = "INSERT INTO " . $table . " (" . implode(', ', $columns) . ") VALUES (" . implode(', ', $values) . ")";
			}

			if (!$db->query($sql)) {
				$db->rollback();
				http_response_code(500);
				echo json_encode(array('error' => 'Database error', 'details' => $db->lasterror()));
				exit;
			}
		}

		$db->commit();

		echo json_encode(array(
			'success' => true,
			'value' => $hoursValue,
			'mainTaskId' => $mainTaskValue,
		));
		exit;
	}

	public static function listTasks(DoliDB $db, $taskTable)
	{
		$sql = "SELECT rowid, label, color FROM " . $taskTable . " WHERE active = 1 ORDER BY rowid";
		$resql = $db->query($sql);
		if (!$resql) {
			http_response_code(500);
			echo json_encode(array('error' => 'Database error', 'details' => $db->lasterror()));
			exit;
		}
		$tasks = array();
		while ($obj = $db->fetch_object($resql)) {
			$tasks[] = array(
				'id' => (int) $obj->rowid,
				'label' => $obj->label,
				'color' => $obj->color,
			);
		}
		$db->free($resql);
		echo json_encode($tasks);
		exit;
	}

	public static function createTask(DoliDB $db, $taskTable, User $user)
	{
		if (empty($user->rights->dolizsynch->shift->manage_tasks)) {
			http_response_code(403);
			echo json_encode(array('error' => 'Forbidden'));
			exit;
		}
		$label = trim((string) GETPOST('label', 'restricthtml'));
		$color = self::sanitizeColor(GETPOST('color', 'alphanohtml'));
		if ($label === '') {
			http_response_code(400);
			echo json_encode(array('error' => 'Missing label'));
			exit;
		}
		$sql = "INSERT INTO " . $taskTable . " (label, color, created_by)";
		$sql .= " VALUES ('" . $db->escape($label) . "', '" . $db->escape($color) . "', " . ((int) $user->id) . ")";
		if (!$db->query($sql)) {
			http_response_code(500);
			echo json_encode(array('error' => 'Database error', 'details' => $db->lasterror()));
			exit;
		}
		$id = $db->last_insert_id($taskTable, 'rowid');
		echo json_encode(array('success' => true, 'id' => (int) $id));
		exit;
	}

	public static function updateTask(DoliDB $db, $taskTable, User $user)
	{
		if (empty($user->rights->dolizsynch->shift->manage_tasks)) {
			http_response_code(403);
			echo json_encode(array('error' => 'Forbidden'));
			exit;
		}
		$id = GETPOST('id', 'int');
		$label = trim((string) GETPOST('label', 'restricthtml'));
		$color = self::sanitizeColor(GETPOST('color', 'alphanohtml'));
		if (empty($id)) {
			http_response_code(400);
			echo json_encode(array('error' => 'Missing id'));
			exit;
		}
		$updates = array();
		if ($label !== '') {
			$updates[] = "label = '" . $db->escape($label) . "'";
		}
		if ($color !== '') {
			$updates[] = "color = '" . $db->escape($color) . "'";
		}
		if (empty($updates)) {
			http_response_code(400);
			echo json_encode(array('error' => 'No fields to update'));
			exit;
		}
		$sql = "UPDATE " . $taskTable . " SET " . implode(', ', $updates) . " WHERE rowid = " . (int) $id;
		if (!$db->query($sql)) {
			http_response_code(500);
			echo json_encode(array('error' => 'Database error', 'details' => $db->lasterror()));
			exit;
		}
		echo json_encode(array('success' => true));
		exit;
	}

	public static function deleteTask(DoliDB $db, $taskTable, User $user)
	{
		if (empty($user->rights->dolizsynch->shift->manage_tasks)) {
			http_response_code(403);
			echo json_encode(array('error' => 'Forbidden'));
			exit;
		}
		$id = GETPOST('id', 'int');
		if (empty($id)) {
			http_response_code(400);
			echo json_encode(array('error' => 'Missing id'));
			exit;
		}
		$sql = "DELETE FROM " . $taskTable . " WHERE rowid = " . (int) $id;
		if (!$db->query($sql)) {
			http_response_code(500);
			echo json_encode(array('error' => 'Database error', 'details' => $db->lasterror()));
			exit;
		}
		echo json_encode(array('success' => true));
		exit;
	}

	/* === Helper methods migrated from global functions === */

	public static function shiftplannerParseIdList($raw)
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

	public static function shiftplannerDecodeWeekNotes($raw)
	{
		$result = array(
			'note' => '',
			'forced_user_ids' => array(),
			'hidden_user_ids' => array(),
			'employee_notes' => array(),
			'is_json' => false,
		);
		if ($raw === null) {
			return $result;
		}
		$rawStr = (string) $raw;
		if ($rawStr === '') {
			return $result;
		}

		$decoded = json_decode($rawStr, true);
		if (
			is_array($decoded) &&
			(
				array_key_exists('note', $decoded) ||
				array_key_exists('forced_user_ids', $decoded) ||
				array_key_exists('hidden_user_ids', $decoded) ||
				array_key_exists('employee_notes', $decoded)
			)
		) {
			$result['is_json'] = true;
			if (array_key_exists('note', $decoded) && !is_array($decoded['note']) && !is_object($decoded['note'])) {
				$result['note'] = (string) $decoded['note'];
			}

			$ids = isset($decoded['forced_user_ids']) && is_array($decoded['forced_user_ids'])
				? $decoded['forced_user_ids']
				: array();
			$normalized = array();
			foreach ($ids as $id) {
				$id = (int) $id;
				if ($id > 0) {
					$normalized[$id] = $id;
				}
			}
			$result['forced_user_ids'] = array_values($normalized);

			$hidden = isset($decoded['hidden_user_ids']) && is_array($decoded['hidden_user_ids'])
				? $decoded['hidden_user_ids']
				: array();
			$hiddenNormalized = array();
			foreach ($hidden as $id) {
				$id = (int) $id;
				if ($id > 0) {
					$hiddenNormalized[$id] = $id;
				}
			}
			$result['hidden_user_ids'] = array_values($hiddenNormalized);

			if (array_key_exists('employee_notes', $decoded)) {
				$result['employee_notes'] = self::shiftplannerNormalizeEmployeeWeekNotes($decoded['employee_notes']);
			}
			return $result;
		}

		$result['note'] = $rawStr;
		return $result;
	}

	public static function shiftplannerNormalizeEmployeeWeekNotes($raw)
	{
		$normalized = array();
		if (!is_array($raw)) {
			return $normalized;
		}

		foreach ($raw as $key => $value) {
			$employeeId = (int) $key;
			if ($employeeId <= 0) {
				continue;
			}
			if (is_array($value) || is_object($value) || $value === null) {
				continue;
			}
			$text = trim((string) $value);
			if ($text === '') {
				continue;
			}
			$normalized[(string) $employeeId] = $text;
		}

		return $normalized;
	}

	public static function shiftplannerEncodeWeekNotes($note, array $forcedIds = array(), array $hiddenIds = array(), $forceJson = false, array $employeeNotes = array())
	{
		$noteText = is_string($note) ? $note : (string) $note;

		$normalizedForced = array();
		foreach ($forcedIds as $id) {
			$id = (int) $id;
			if ($id > 0) {
				$normalizedForced[$id] = $id;
			}
		}
		$forcedIds = array_values($normalizedForced);

		$normalizedHidden = array();
		foreach ($hiddenIds as $id) {
			$id = (int) $id;
			if ($id > 0) {
				$normalizedHidden[$id] = $id;
			}
		}
		$hiddenIds = array_values($normalizedHidden);

		$employeeNotes = self::shiftplannerNormalizeEmployeeWeekNotes($employeeNotes);
		if (!$forceJson && empty($forcedIds) && empty($hiddenIds) && empty($employeeNotes)) {
			return $noteText;
		}

		$payload = array(
			'note' => $noteText,
			'forced_user_ids' => $forcedIds,
			'hidden_user_ids' => $hiddenIds,
			'employee_notes' => $employeeNotes,
		);
		$encoded = json_encode($payload, JSON_UNESCAPED_UNICODE);
		if ($encoded === false) {
			return $noteText;
		}

		return $encoded;
	}

	public static function shiftplannerTableHasColumn(DoliDB $db, $table, $column)
	{
		static $cache = array();
		$key = $table . '|' . $column;
		if (isset($cache[$key])) return $cache[$key];
		$sql = "SHOW COLUMNS FROM " . $table . " LIKE '" . $db->escape($column) . "'";
		$res = $db->query($sql);
		$has = $res && $db->num_rows($res) > 0;
		if ($res) $db->free($res);
		$cache[$key] = $has;
		return $has;
	}

	private static function shiftplannerGetShiftStatusColumnLength(DoliDB $db, $table)
	{
		$sql = "SHOW COLUMNS FROM " . $table . " LIKE 'shift_status'";
		$resql = $db->query($sql);
		if (!$resql) {
			return 0;
		}
		$obj = $db->fetch_object($resql);
		$db->free($resql);
		if (!$obj) {
			return 0;
		}

		$type = '';
		if (isset($obj->Type)) {
			$type = (string) $obj->Type;
		} elseif (isset($obj->type)) {
			$type = (string) $obj->type;
		}
		if ($type !== '' && preg_match('/varchar\s*\(\s*(\d+)\s*\)/i', $type, $m)) {
			return (int) $m[1];
		}
		return 0;
	}

	private static function shiftplannerEnsureShiftStatusColumnCapacity(DoliDB $db, $table, $requiredLength = 18)
	{
		static $cache = array();
		$key = $table . '|' . (int) $requiredLength;
		if (isset($cache[$key])) {
			return $cache[$key];
		}

		if (!self::shiftplannerTableHasColumn($db, $table, 'shift_status')) {
			$cache[$key] = false;
			return false;
		}

		$currentLength = self::shiftplannerGetShiftStatusColumnLength($db, $table);
		if ($currentLength >= (int) $requiredLength) {
			$cache[$key] = true;
			return true;
		}

		$sqlAlter = "ALTER TABLE " . $table . " MODIFY COLUMN shift_status VARCHAR(32) NOT NULL DEFAULT 'scheduled'";
		$resAlter = $db->query($sqlAlter);
		if (!$resAlter) {
			dol_syslog(__METHOD__ . ' failed to alter shift_status column length: ' . $db->lasterror(), LOG_ERR);
			$cache[$key] = false;
			return false;
		}

		$updatedLength = self::shiftplannerGetShiftStatusColumnLength($db, $table);
		$cache[$key] = ($updatedLength >= (int) $requiredLength);
		return $cache[$key];
	}

	private static function shiftplannerPrepareShiftStatusForStorage(DoliDB $db, $table, $value)
	{
		$status = self::normalizeShiftStatus($value);
		if (($status === 'missed_unjustified' || $status === 'missed_justified')
			&& !self::shiftplannerEnsureShiftStatusColumnCapacity($db, $table, 18)
		) {
			// Safety fallback for legacy databases where automatic alter failed.
			return 'missed';
		}
		return $status;
	}

	public static function shiftplannerDayOffHasLockColumn(DoliDB $db)
	{
		return self::shiftplannerTableHasColumn($db, MAIN_DB_PREFIX . "dolizsynch_dayoff", 'is_lock');
	}

	public static function shiftplanner_get_max_shift_minutes()
	{
		$hours = (float) getDolGlobalString('DOLIZSYNCH_SHIFT_MAX_HOURS', '8');
		return $hours > 0 ? (int) round($hours * 60) : 0;
	}

	public static function shiftplanner_get_duration_minutes($startIso, $endIso)
	{
		$startSql = self::shiftplannerIsoToSql((string) $startIso);
		$endSql = self::shiftplannerIsoToSql((string) $endIso);
		if ($startSql === '' || $endSql === '') {
			return null;
		}
		$tzUtc = new DateTimeZone('UTC');
		$startDt = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $startSql, $tzUtc);
		$endDt = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $endSql, $tzUtc);
		if (!$startDt || !$endDt) {
			return null;
		}
		$start = (int) $startDt->format('U');
		$end = (int) $endDt->format('U');
		if ($end <= $start) {
			return null;
		}
		return (int) (($end - $start) / 60);
	}

	public static function shiftplannerGetEmployeeGroupId()
	{
		$ids = self::shiftplannerGetEmployeeGroupIds();
		return !empty($ids) ? (int) $ids[0] : 0;
	}

	public static function shiftplannerGetEmployeeGroupIds()
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

	public static function shiftplannerHasConflictingLeave(DoliDB $db, $userId, $targetDateIso)
	{
		global $conf;

		$userId = (int) $userId;
		if ($userId <= 0 || empty($targetDateIso)) {
			return false;
		}
		$dayChangeHour = self::shiftplanner_get_day_change_hour();
		$dayStartTs = self::shiftplanner_operational_day_start_from_iso($targetDateIso, $dayChangeHour);
		$dayEndTs = $dayStartTs + 86400;
		$startBoundSql = $db->idate($dayStartTs);
		$endBoundSql = $db->idate($dayEndTs);

		$table = MAIN_DB_PREFIX . "dolizsynch_leave";
		$sql = "SELECT COUNT(*) as cnt FROM " . $table;
		$sql .= " WHERE fk_user = " . $userId;
		$sql .= " AND (entity IS NULL OR entity IN (0, " . ((int) $conf->entity) . "))";
		$sql .= " AND date_start < '" . $db->escape($endBoundSql) . "'";
		$sql .= " AND date_end > '" . $db->escape($startBoundSql) . "'";

		$resql = $db->query($sql);
		if (!$resql) {
			return false;
		}
		$obj = $db->fetch_object($resql);
		$db->free($resql);
		return !empty($obj) && (int) $obj->cnt > 0;
	}

	public static function shiftplannerIsoToSql($value)
	{
		$value = trim((string) $value);
		if ($value === '') {
			return '';
		}
		if (
			preg_match(
				'/^(\d{4})-(\d{2})-(\d{2})[T\s](\d{2}):(\d{2})(?::(\d{2}))?(?:\.\d+)?(?:Z|[+\-]\d{2}:?\d{2})?$/',
				$value,
				$m
			)
		) {
			$year = (int) $m[1];
			$month = (int) $m[2];
			$day = (int) $m[3];
			$hour = (int) $m[4];
			$minute = (int) $m[5];
			$second = isset($m[6]) && $m[6] !== '' ? (int) $m[6] : 0;
			if (
				checkdate($month, $day, $year) &&
				$hour >= 0 && $hour <= 23 &&
				$minute >= 0 && $minute <= 59 &&
				$second >= 0 && $second <= 59
			) {
				return sprintf('%04d-%02d-%02d %02d:%02d:%02d', $year, $month, $day, $hour, $minute, $second);
			}
		}

		return '';
	}

	public static function shiftplannerSqlToIso(DoliDB $db, $value)
	{
		if ($value === '' || $value === null) {
			return '';
		}
		$value = trim((string) $value);
		if (
			preg_match(
				'/^(\d{4})-(\d{2})-(\d{2})[T\s](\d{2}):(\d{2})(?::(\d{2}))?/',
				$value,
				$m
			)
		) {
			$year = (int) $m[1];
			$month = (int) $m[2];
			$day = (int) $m[3];
			$hour = (int) $m[4];
			$minute = (int) $m[5];
			$second = isset($m[6]) && $m[6] !== '' ? (int) $m[6] : 0;
			if (
				checkdate($month, $day, $year) &&
				$hour >= 0 && $hour <= 23 &&
				$minute >= 0 && $minute <= 59 &&
				$second >= 0 && $second <= 59
			) {
				return sprintf('%04d-%02d-%02dT%02d:%02d:%02d', $year, $month, $day, $hour, $minute, $second);
			}
		}
		return '';
	}

	public static function shiftplannerNormalizeWeekDate($value)
	{
		$value = substr((string) $value, 0, 10);
		if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
			return '';
		}
		$dt = DateTime::createFromFormat('Y-m-d', $value);
		if (!$dt) {
			return '';
		}
		$day = (int) $dt->format('w'); // 0 = Sunday
		$offset = ($day + 6) % 7; // days since Monday
		if ($offset > 0) {
			$dt->modify('-' . $offset . ' days');
		}
		return $dt->format('Y-m-d');
	}

	public static function shiftplannerParseTimeValue($value)
	{
		if (!preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', (string) $value, $m)) {
			return null;
		}
		return array('hour' => (int) $m[1], 'minute' => (int) $m[2]);
	}

	public static function shiftplannerParseDateParts($value)
	{
		if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', (string) $value, $m)) {
			return array(0, 0, 0);
		}
		return array((int) $m[1], (int) $m[2], (int) $m[3]);
	}

	public static function shiftplanner_get_day_change_hour()
	{
		global $conf;

		$hour = isset($conf->global->ZS_PUNCH_DAY_CHANGE_HOUR) ? (int) $conf->global->ZS_PUNCH_DAY_CHANGE_HOUR : 0;
		if ($hour < 0 || $hour > 23) {
			return 0;
		}
		return $hour;
	}

	public static function shiftplanner_operational_day_start_from_iso($isoDate, $dayChangeHour = null)
	{
		if ($dayChangeHour === null) {
			$dayChangeHour = self::shiftplanner_get_day_change_hour();
		}
		$isoDate = substr(trim((string) $isoDate), 0, 10);
		if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $isoDate, $m)) {
			return 0;
		}
		list(, $year, $month, $day) = $m;
		return dol_mktime((int) $dayChangeHour, 0, 0, (int) $month, (int) $day, (int) $year);
	}

	public static function shiftplanner_operational_boundary_for_timestamp($ts, $dayChangeHour = null)
	{
		if ($dayChangeHour === null) {
			$dayChangeHour = self::shiftplanner_get_day_change_hour();
		}
		$boundary = dol_mktime((int) $dayChangeHour, 0, 0, date('n', $ts), date('j', $ts), date('Y', $ts));
		if ($ts < $boundary) {
			$boundary -= 86400;
		}
		return $boundary;
	}

	public static function shiftplanner_build_operational_day_range($dateTs, $dayChangeHour = null)
	{
		if ($dayChangeHour === null) {
			$dayChangeHour = self::shiftplanner_get_day_change_hour();
		}
		$startTs = dol_mktime((int) $dayChangeHour, 0, 0, date('n', $dateTs), date('j', $dateTs), date('Y', $dateTs));
		$endTs = $startTs + 86400;
		return array(
			'start_ts' => $startTs,
			'end_ts' => $endTs,
			'start_sql' => dol_print_date($startTs, '%Y-%m-%d %H:%M:%S'),
			'end_sql' => dol_print_date($endTs, '%Y-%m-%d %H:%M:%S'),
		);
	}

	public static function shiftplannerNormalizePartialMode($rawValue)
	{
		$mode = trim((string) $rawValue);
		$lower = strtolower($mode);
		if ($lower === 'hours_range' || $lower === 'hours' || $mode === '2') {
			return 'hours';
		}
		if ($lower === 'minutes_total' || $lower === 'minutes' || $mode === '3') {
			return 'minutes';
		}
		if ($mode === '1') {
			return 'halfday';
		}
		if ($mode === '0' || $mode === '') {
			return 'full';
		}
		return $mode;
	}

	public static function shiftplannerEncodeLeaveDescription($description, $hoursRange = '', $minutesTotal = 0)
	{
		$description = trim((string) $description);
		$hoursRange = trim((string) $hoursRange);
		$minutesTotal = (int) $minutesTotal;
		$prefix = '';
		if ($hoursRange !== '') {
			$prefix .= '[[HOURS=' . $hoursRange . ']]';
		}
		if ($minutesTotal > 0) {
			$prefix .= '[[MINUTES=' . $minutesTotal . ']]';
		}
		if ($prefix === '') {
			return $description;
		}
		return $prefix . ($description !== '' ? ' ' . $description : '');
	}

	public static function shiftplannerExtractLeaveHours($description)
	{
		$text = trim((string) $description);
		$info = array(
			'range' => '',
			'start' => '',
			'end' => '',
			'minutes' => 0,
			'description' => $text,
		);
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

	public static function shiftplannerHasOverlap(DoliDB $db, $table, $fkUser, $dateStart, $dateEnd, $excludeId = 0)
	{
		global $conf;

		$sql = "SELECT COUNT(*) as cnt FROM " . $table;
		$sql .= " WHERE fk_user = " . (int) $fkUser;
		$sql .= " AND date_start < '" . $db->escape($dateEnd) . "'";
		$sql .= " AND date_end > '" . $db->escape($dateStart) . "'";
		$sql .= " AND (entity IS NULL OR entity IN (0, " . ((int) $conf->entity) . "))";
		if (!empty($excludeId)) {
			$sql .= " AND rowid != " . (int) $excludeId;
		}

		$resql = $db->query($sql);
		if (!$resql) {
			http_response_code(500);
			echo json_encode(array('error' => 'Database error', 'details' => $db->lasterror()));
			exit;
		}

		$obj = $db->fetch_object($resql);
		$db->free($resql);

		return !empty($obj) && ((int) $obj->cnt > 0);
	}

	public static function shiftplannerBuildDateMap($startTs, $endTs, $useOperationalDay = false)
	{
		$map = array();
		if (empty($startTs) || empty($endTs)) {
			return $map;
		}
		$dayChangeHour = self::shiftplanner_get_day_change_hour();
		if (!$useOperationalDay || $dayChangeHour === 0) {
			$cursor = dol_mktime(0, 0, 0, date('n', $startTs), date('j', $startTs), date('Y', $startTs));
			$limit = dol_mktime(0, 0, 0, date('n', $endTs), date('j', $endTs), date('Y', $endTs));
			while ($cursor <= $limit) {
				$key = dol_print_date($cursor, '%Y-%m-%d');
				$map[$key] = $cursor;
				$cursor += 86400;
			}
			return $map;
		}

		$cursor = self::shiftplanner_operational_boundary_for_timestamp($startTs, $dayChangeHour);
		while ($cursor <= $endTs) {
			$key = dol_print_date($cursor, '%Y-%m-%d');
			$map[$key] = $cursor;
			$cursor += 86400;
		}
		return $map;
	}

	/**
	 * Build an operational-date map from timezone-neutral planner datetimes.
	 *
	 * @param string   $startValue Start datetime (inclusive)
	 * @param string   $endValue End datetime (exclusive)
	 * @param int|null $dayChangeHour Operational-day cutoff hour
	 * @param bool     $beginsAfterBoundary Whether the new day begins one second after the cutoff
	 * @return array<string,int>
	 */
	public static function shiftplannerBuildOperationalDateMap($startValue, $endValue, $dayChangeHour = null, $beginsAfterBoundary = true)
	{
		return shiftplanner_build_operational_date_map_from_range(
			$startValue,
			$endValue,
			$dayChangeHour,
			$beginsAfterBoundary
		);
	}

	public static function shiftplannerHasLeaveOverlap(DoliDB $db, $userId, $startValue, $endValue, $ignorePartial = false, $excludeId = 0)
	{
		global $conf;

		$table = MAIN_DB_PREFIX . "dolizsynch_leave";
		$startBoundSql = is_numeric($startValue) ? $db->idate((int) $startValue) : self::shiftplannerIsoToSql($startValue);
		$endBoundSql = is_numeric($endValue) ? $db->idate((int) $endValue) : self::shiftplannerIsoToSql($endValue);
		$startDate = shiftplanner_parse_wall_clock_datetime($startBoundSql);
		$endDate = shiftplanner_parse_wall_clock_datetime($endBoundSql);
		if (!$startDate || !$endDate || $endDate <= $startDate) {
			return false;
		}
		$startTs = (int) $startDate->format('U');
		$endTs = (int) $endDate->format('U');

		$sql = "SELECT rowid, date_start, date_end, halfday, minutes_total, day_change_hour FROM " . $table;
		$sql .= " WHERE fk_user = " . (int) $userId;
		// Equality is included in the SQL prefilter because full-day/minutes
		// leave boundaries are evaluated at cutoff + 1 second below.
		$sql .= " AND date_end >= '" . $db->escape($startBoundSql) . "'";
		$sql .= " AND date_start < '" . $db->escape($endBoundSql) . "'";
		$sql .= " AND (entity IS NULL OR entity IN (0, " . ((int) $conf->entity) . "))";
		if (!empty($excludeId)) {
			$sql .= " AND rowid != " . (int) $excludeId;
		}

		$resql = $db->query($sql);
		if (!$resql) {
			http_response_code(500);
			echo json_encode(array('error' => 'Database error', 'details' => $db->lasterror()));
			exit;
		}

		$overlap = false;
		while ($obj = $db->fetch_object($resql)) {
			$halfday = (int) $obj->halfday;
			$minutes = (int) $obj->minutes_total;
			if ($ignorePartial && ($halfday || $minutes > 0)) {
				continue;
			}
			$existingStart = shiftplanner_parse_wall_clock_datetime($obj->date_start);
			$existingEnd = shiftplanner_parse_wall_clock_datetime($obj->date_end);
			if (!$existingStart || !$existingEnd) {
				continue;
			}
			$leaveDayChangeHour = isset($obj->day_change_hour) && $obj->day_change_hour !== null
				? (int) $obj->day_change_hour
				: self::shiftplanner_get_day_change_hour();
			if ($halfday !== 2) {
				$effectiveRange = shiftplanner_build_effective_leave_range(
					$obj->date_start,
					$obj->date_end,
					$leaveDayChangeHour
				);
				if (empty($effectiveRange)) {
					continue;
				}
				$existingStart = shiftplanner_parse_wall_clock_datetime($effectiveRange['start_sql']);
				$existingEnd = shiftplanner_parse_wall_clock_datetime($effectiveRange['end_sql']);
			}
			if ($minutes > 0) {
				// Minutes-only leave blocks its persisted operational day. Use wall-clock
				// calendar arithmetic so host timezone and DST cannot move the boundary.
				$existingEnd = $existingStart->modify('+1 day');
			}
			$existingStartTs = (int) $existingStart->format('U');
			$existingEndTs = (int) $existingEnd->format('U');
			if ($existingEndTs > $startTs && $existingStartTs < $endTs) {
				$overlap = true;
				break;
			}
		}
		$db->free($resql);

		return $overlap;
	}

	public static function shiftplannerHasDayOffOnDates(DoliDB $db, $userId, array $dateMap, $excludeId = 0)
	{
		global $conf;

		$table = MAIN_DB_PREFIX . "dolizsynch_dayoff";
		if (empty($dateMap)) return false;
		$dates = array_keys($dateMap);
		if (empty($dates)) return false;
		$escapedDates = array();
		foreach ($dates as $iso) {
			$escapedDates[] = "'" . $db->escape($iso) . "'";
		}
		$maxDate = max($dates);
		$hasEntityColumn = self::shiftplannerTableHasColumn($db, $table, 'entity');
		$sql = "SELECT rowid, date_dayoff, is_recurring FROM " . $table;
		$sql .= " WHERE fk_user = " . (int) $userId;
		$sql .= " AND ((is_recurring = 0 AND date_dayoff IN (" . implode(',', $escapedDates) . "))";
		$sql .= " OR (is_recurring = 1 AND date_dayoff <= '" . $db->escape($maxDate) . "'))";
		if ($hasEntityColumn) {
			$sql .= " AND (entity IS NULL OR entity IN (0, " . ((int) $conf->entity) . "))";
		}
		if (!empty($excludeId)) {
			$sql .= " AND rowid != " . (int) $excludeId;
		}
		$resql = $db->query($sql);
		if (!$resql) {
			return false;
		}
		$dayMap = array();
		foreach ($dateMap as $iso => $ts) {
			$tsValue = !empty($ts) ? $ts : dol_stringtotime($iso);
			if (empty($tsValue)) {
				continue;
			}
			$dow = (int) date('w', $tsValue);
			if (!isset($dayMap[$dow])) {
				$dayMap[$dow] = array();
			}
			$dayMap[$dow][] = $tsValue;
		}

		$hasDayOff = false;
		while ($obj = $db->fetch_object($resql)) {
			if ((int) $obj->is_recurring === 0) {
				$hasDayOff = true;
				break;
			}
			$baseTs = dol_stringtotime($obj->date_dayoff);
			if (empty($baseTs)) {
				continue;
			}
			$baseDow = (int) date('w', $baseTs);
			if (empty($dayMap[$baseDow])) {
				continue;
			}
			foreach ($dayMap[$baseDow] as $candidateTs) {
				if ($candidateTs >= $baseTs) {
					$hasDayOff = true;
					break 2;
				}
			}
		}
		$db->free($resql);
		return $hasDayOff;
	}

	public static function shiftplannerHolidayTypeHasColor(DoliDB $db)
	{
		static $hasColor = null;
		if ($hasColor !== null) {
			return $hasColor;
		}

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

	public static function shiftplannerFetchShift(DoliDB $db, $table, $id)
	{
		$hasShiftType = self::shiftplannerTableHasColumn($db, $table, 'shift_type');
		$hasShiftStatus = self::shiftplannerTableHasColumn($db, $table, 'shift_status');
		$sql = "SELECT rowid, fk_user, fk_task, label, date_start, date_end, color, note";
		if ($hasShiftType) $sql .= ", shift_type";
		if ($hasShiftStatus) $sql .= ", shift_status";
		$sql .= ", entity FROM " . $table . " WHERE rowid = " . (int) $id . " LIMIT 1";
		$resql = $db->query($sql);
		if (!$resql) {
			http_response_code(500);
			echo json_encode(array('error' => 'Database error', 'details' => $db->lasterror()));
			exit;
		}

		$obj = $db->fetch_object($resql);
		$db->free($resql);

		return $obj ?: null;
	}

	public static function shiftplannerFetchTask(DoliDB $db, $taskTable, $id)
	{
		if (empty($id)) {
			return null;
		}

		$sql = "SELECT rowid, label, color FROM " . $taskTable . " WHERE rowid = " . (int) $id . " LIMIT 1";
		$resql = $db->query($sql);
		if (!$resql) {
			http_response_code(500);
			echo json_encode(array('error' => 'Database error', 'details' => $db->lasterror()));
			exit;
		}

		$obj = $db->fetch_object($resql);
		$db->free($resql);

		return $obj ?: null;
	}

	public static function shiftplannerFetchUserMainTaskId(DoliDB $db, $userId)
	{
		$userId = (int) $userId;
		if ($userId <= 0) {
			return 0;
		}

		$table = MAIN_DB_PREFIX . 'user_extrafields';
		if (!self::shiftplannerTableHasColumn($db, $table, 'zs_main_task')) {
			return 0;
		}

		$sql = "SELECT zs_main_task FROM " . $table . " WHERE fk_object = " . $userId . " LIMIT 1";
		$resql = $db->query($sql);
		if (!$resql) {
			return 0;
		}
		$obj = $db->fetch_object($resql);
		$db->free($resql);
		if (!$obj || !isset($obj->zs_main_task)) {
			return 0;
		}

		$taskId = (int) $obj->zs_main_task;
		return $taskId > 0 ? $taskId : 0;
	}

	public static function shiftplannerFetchLeave(DoliDB $db, $leaveTable, $id)
	{
		if (empty($id)) {
			return null;
		}

		$sql = "SELECT rowid, fk_user, fk_type, date_start, date_end, halfday, minutes_total, description, day_change_hour, entity";
		$sql .= " FROM " . $leaveTable . " WHERE rowid = " . (int) $id . " LIMIT 1";
		$resql = $db->query($sql);
		if (!$resql) {
			http_response_code(500);
			echo json_encode(array('error' => 'Database error', 'details' => $db->lasterror()));
			exit;
		}

		$obj = $db->fetch_object($resql);
		$db->free($resql);

		return $obj ?: null;
	}

	public static function shiftplannerFetchHolidayType(DoliDB $db, $id)
	{
		if (empty($id)) {
			return null;
		}

		$sql = "SELECT rowid, code, label, entity FROM " . MAIN_DB_PREFIX . "c_holiday_types";
		$sql .= " WHERE rowid = " . (int) $id;
		$sql .= " LIMIT 1";
		$resql = $db->query($sql);
		if (!$resql) {
			http_response_code(500);
			echo json_encode(array('error' => 'Database error', 'details' => $db->lasterror()));
			exit;
		}

		$obj = $db->fetch_object($resql);
		$db->free($resql);

		return $obj ?: null;
	}

	public static function shiftplannerComputeMinutesBetween($startTs, $endTs)
	{
		if (empty($startTs) || empty($endTs) || $endTs <= $startTs) {
			return 0;
		}
		return (int) round(($endTs - $startTs) / 60);
	}

	public static function shiftplannerComputeMinutesFromRange($startLabel, $endLabel)
	{
		if (!preg_match('/^\d{2}:\d{2}$/', (string) $startLabel) || !preg_match('/^\d{2}:\d{2}$/', (string) $endLabel)) {
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

	public static function shiftplannerSqlDateTimeToTimeLabel($value)
	{
		if ($value === null || $value === '') {
			return '';
		}
		$ts = dol_stringtotime($value);
		if (empty($ts)) {
			return '';
		}
		return dol_print_date($ts, '%H:%M');
	}

	public static function shiftplannerNormalizeTimestamp($db, $value)
	{
		if (is_int($value)) return $value;
		return $db->jdate($value);
	}
}

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
 * Lightweight Holiday model for DoliZSynch.
 *
 * This wraps the module-owned leave table (llx_dolizsynch_leave) so the
 * legacy holiday pages can persist and display leave requests without
 * depending on Dolibarr's core holiday module tables.
 */

require_once DOL_DOCUMENT_ROOT . '/core/class/commonobject.class.php';
require_once __DIR__ . '/../lib/shiftplanner.lib.php';
require_once __DIR__ . '/zsshiftplanneractions.class.php';

class ZSLeaves extends CommonObject
{
	/** @var string */
	public $element = 'dolizsynch_leave';

	/** @var string */
	public $table_element = 'dolizsynch_leave';

	/** @var string */
	public $picto = 'holiday';

	public $fields = array(
		'rowid' => array('type' => 'integer', 'label' => 'Id', 'enabled' => 1, 'visible' => 1),
		'fk_user' => array('type' => 'integer', 'label' => 'User', 'enabled' => 1, 'visible' => 1),
		'fk_type' => array('type' => 'integer', 'label' => 'Type', 'enabled' => 1, 'visible' => 1),
		'date_start' => array('type' => 'datetime', 'label' => 'DateStart', 'enabled' => 1, 'visible' => 1),
		'date_end' => array('type' => 'datetime', 'label' => 'DateEnd', 'enabled' => 1, 'visible' => 1),
		'halfday' => array('type' => 'integer', 'label' => 'Halfday', 'enabled' => 1, 'visible' => 1), // 0 full, 1 half-day, 2 period, 3 minutes
		'minutes_total' => array('type' => 'integer', 'label' => 'MinutesTotal', 'enabled' => 1, 'visible' => 1),
		'description' => array('type' => 'text', 'label' => 'Description', 'enabled' => 1, 'visible' => 1),
		'day_change_hour' => array('type' => 'integer', 'label' => 'DayChangeHour', 'enabled' => 1, 'visible' => 0),
		'entity' => array('type' => 'integer', 'label' => 'Entity', 'enabled' => 1, 'visible' => 0),
		'created_by' => array('type' => 'integer', 'label' => 'CreatedBy', 'enabled' => 1, 'visible' => 0),
		'fk_user_modif' => array('type' => 'integer', 'label' => 'ModifiedBy', 'enabled' => 1, 'visible' => 0),
		'date_creation' => array('type' => 'datetime', 'label' => 'DateCreation', 'enabled' => 1, 'visible' => 0),
		'tms' => array('type' => 'timestamp', 'label' => 'Tms', 'enabled' => 1, 'visible' => 0),
	);

	const STATUS_DRAFT = 1;
	const STATUS_APPROVED = 3;

	public $status = self::STATUS_APPROVED;
	public $statut = self::STATUS_APPROVED;

	public function __construct($db)
	{
		$this->db = $db;
		$this->ismultientitymanaged = 1;
	}

	/**
	 * Create a leave row in llx_dolizsynch_leave.
	 *
	 * @param User $user
	 * @param int  $notrigger
	 * @return int <0 on error, new id otherwise
	 */
	public function create($user, $notrigger = 0)
	{
		global $conf;

		$this->errors = array();
		$error = 0;

		$startTs = $this->normalizeDateValue($this->date_start);
		$endTs = $this->normalizeDateValue($this->date_end);
		if (empty($this->fk_user) || empty($this->fk_type) || !$startTs || !$endTs) {
			$this->error = 'Missing required fields';
			return -1;
		}
		$dayChangeHour = isset($this->day_change_hour) && $this->day_change_hour !== ''
			? (int) $this->day_change_hour
			: shiftplanner_get_day_change_hour();
		$this->day_change_hour = $dayChangeHour;

		$this->db->begin();

		$sql = "INSERT INTO " . MAIN_DB_PREFIX . $this->table_element;
		$sql .= " (fk_user, fk_type, date_start, date_end, halfday, minutes_total, description, day_change_hour, created_by, fk_user_modif, entity)";
		$sql .= " VALUES (";
		$sql .= (int) $this->fk_user . ", ";
		$sql .= (int) $this->fk_type . ", ";
		$sql .= "'" . $this->db->escape($this->db->idate($startTs)) . "', ";
		$sql .= "'" . $this->db->escape($this->db->idate($endTs)) . "', ";
		$sql .= (int) $this->halfday . ", ";
		$sql .= (int) $this->minutes_total . ", ";
		$sql .= ($this->description !== '' ? "'" . $this->db->escape($this->description) . "'" : "NULL") . ", ";
		$sql .= (int) $dayChangeHour . ", ";
		$sql .= (int) $user->id . ", ";
		$sql .= (int) $user->id . ", ";
		$sql .= (int) $conf->entity;
		$sql .= ")";

		if (!$this->db->query($sql)) {
			$error++;
			$this->errors[] = $this->db->lasterror();
		} else {
			$this->id = (int) $this->db->last_insert_id(MAIN_DB_PREFIX . $this->table_element, 'rowid');
		}

		if ($error) {
			$this->db->rollback();
			return -1;
		}

		$this->db->commit();
		return $this->id;
	}

	/**
	 * Fetch a leave row.
	 *
	 * @param int $id
	 * @return int 0 not found, <0 error, >0 ok
	 */
	public function fetch($id)
	{
		global $conf;

		$sql = "SELECT rowid, fk_user, fk_type, date_start, date_end, halfday, minutes_total, description, day_change_hour, created_by, fk_user_modif, date_creation, tms, entity";
		$sql .= " FROM " . MAIN_DB_PREFIX . $this->table_element;
		$sql .= " WHERE rowid = " . ((int) $id);
		$sql .= " AND (entity IS NULL OR entity IN (0, " . ((int) $conf->entity) . "))";

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return -1;
		}

		if ($this->db->num_rows($resql) === 0) {
			$this->db->free($resql);
			return 0;
		}

		$obj = $this->db->fetch_object($resql);
		$this->db->free($resql);

		$this->id = (int) $obj->rowid;
		$this->ref = 'LV' . $this->id;
		$this->fk_user = (int) $obj->fk_user;
		$this->fk_type = (int) $obj->fk_type;
		$this->date_start = $this->db->jdate($obj->date_start);
		$this->date_end = $this->db->jdate($obj->date_end);
		$this->halfday = (int) $obj->halfday;
		$this->minutes_total = (int) $obj->minutes_total;
		$this->description = $obj->description;
		$this->day_change_hour = $obj->day_change_hour !== null ? (int) $obj->day_change_hour : null;
		$this->created_by = (int) $obj->created_by;
		$this->fk_user_modif = (int) $obj->fk_user_modif;
		$this->date_creation = $this->db->jdate($obj->date_creation);
		$this->tms = $this->db->jdate($obj->tms);
		$this->entity = (int) $obj->entity;

		$this->status = self::STATUS_APPROVED;
		$this->statut = self::STATUS_APPROVED;

		return 1;
	}

	/**
	 * Update an existing leave row.
	 *
	 * @param User $user
	 * @param int  $notrigger
	 * @return int
	 */
	public function update($user, $notrigger = 0)
	{
		global $conf;

		if (empty($this->id)) {
			return -1;
		}

		$startTs = $this->normalizeDateValue($this->date_start);
		$endTs = $this->normalizeDateValue($this->date_end);
		if (!$startTs || !$endTs) {
			$this->error = 'Invalid dates';
			return -1;
		}

		$sql = "UPDATE " . MAIN_DB_PREFIX . $this->table_element . " SET";
		$sql .= " fk_user = " . (int) $this->fk_user . ",";
		$sql .= " fk_type = " . (int) $this->fk_type . ",";
		$sql .= " date_start = '" . $this->db->escape($this->db->idate($startTs)) . "',";
		$sql .= " date_end = '" . $this->db->escape($this->db->idate($endTs)) . "',";
		$sql .= " halfday = " . (int) $this->halfday . ",";
		$sql .= " minutes_total = " . (int) $this->minutes_total . ",";
		$sql .= " description = " . ($this->description !== '' ? "'" . $this->db->escape($this->description) . "'" : "NULL") . ",";
		if (isset($this->day_change_hour) && $this->day_change_hour !== '') {
			$sql .= " day_change_hour = " . (int) $this->day_change_hour . ",";
		}
		$sql .= " fk_user_modif = " . (int) $user->id;
		$sql .= " WHERE rowid = " . (int) $this->id;
		$sql .= " AND (entity IS NULL OR entity IN (0, " . ((int) $conf->entity) . "))";

		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			return -1;
		}

		return 1;
	}

	/**
	 * Delete a leave row.
	 *
	 * @param User $user
	 * @param int  $notrigger
	 * @return int
	 */
	public function delete($user, $notrigger = 0)
	{
		global $conf;

		if (empty($this->id)) {
			return -1;
		}

		$sql = "DELETE FROM " . MAIN_DB_PREFIX . $this->table_element;
		$sql .= " WHERE rowid = " . (int) $this->id;
		$sql .= " AND (entity IS NULL OR entity IN (0, " . ((int) $conf->entity) . "))";

		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			return -1;
		}

		$startTs = $this->normalizeDateValue($this->date_start);
		$endTs = $this->normalizeDateValue($this->date_end);
		$logContext = array(
			'leave_id' => (int) $this->id,
			'employee_id' => (int) $this->fk_user,
			'type_id' => (int) $this->fk_type,
			'start' => $startTs ? $this->db->idate($startTs) : null,
			'end' => $endTs ? $this->db->idate($endTs) : null,
			'halfday' => (int) $this->halfday,
			'minutes_total' => (int) $this->minutes_total,
		);
		ZSShiftplannerActions::logScheduleChange('leave_delete', $user, $logContext);

		return 1;
	}

	/**
	 * List leave rows with optional filters.
	 *
	 * @param array $filters
	 * @param string $sortfield
	 * @param string $sortorder
	 * @param int $limit
	 * @param int $offset
	 * @return array<int, stdClass>|null
	 */
	public function fetchAll(array $filters = array(), $sortfield = 'date_start', $sortorder = 'ASC', $limit = 0, $offset = 0)
	{
		global $conf;

		$sql = "SELECT rowid, fk_user, fk_type, date_start, date_end, halfday, minutes_total, description, day_change_hour, created_by, fk_user_modif, date_creation, tms, entity";
		$sql .= " FROM " . MAIN_DB_PREFIX . $this->table_element;
		$sql .= " WHERE (entity IS NULL OR entity IN (0, " . ((int) $conf->entity) . "))";

		if (!empty($filters['fk_user'])) {
			$sql .= " AND fk_user = " . (int) $filters['fk_user'];
		}
		if (!empty($filters['fk_type'])) {
			$sql .= " AND fk_type = " . (int) $filters['fk_type'];
		}
		if (!empty($filters['date_start_from'])) {
			$sql .= " AND date_start >= '" . $this->db->escape($this->db->idate($filters['date_start_from'])) . "'";
		}
		if (!empty($filters['date_end_to'])) {
			$sql .= " AND date_end <= '" . $this->db->escape($this->db->idate($filters['date_end_to'])) . "'";
		}

		$sortfield = preg_replace('/[^a-z0-9_]/i', '', $sortfield);
		$sortorder = strtoupper($sortorder) === 'DESC' ? 'DESC' : 'ASC';
		$sql .= " ORDER BY " . ($sortfield ?: 'date_start') . " " . $sortorder;

		if ($limit > 0) {
			$sql .= $this->db->plimit($limit, $offset);
		}

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return null;
		}

		$rows = array();
		while ($obj = $this->db->fetch_object($resql)) {
			if (property_exists($obj, 'day_change_hour')) {
				$obj->day_change_hour = $obj->day_change_hour !== null ? (int) $obj->day_change_hour : null;
			}
			$rows[] = $obj;
		}
		$this->db->free($resql);

		return $rows;
	}

	/**
	 * Get active employees for an entity (users linked to a group and not hidden).
	 *
	 * Returns [-1] when none are found so select_dolusers shows an empty list.
	 *
	 * @param int|null $entityId
	 * @param int $statusFilter 1 active, 0 inactive, any other value = both
	 * @return array<int, int>
	 */
	public function getEntityEmployees($entityId = null, $statusFilter = 1)
	{
		global $conf;

		$entity = $entityId !== null ? (int) $entityId : (int) $conf->entity;
		$includeEmployees = array();

		$sql  = "SELECT DISTINCT ugu.fk_user";
		$sql .= " FROM " . MAIN_DB_PREFIX . "usergroup_user AS ugu";
		$sql .= " INNER JOIN " . MAIN_DB_PREFIX . "user AS u ON u.rowid = ugu.fk_user";
		$sql .= " LEFT JOIN " . MAIN_DB_PREFIX . "user_extrafields AS ue ON ue.fk_object = u.rowid";
		$sql .= " WHERE ugu.entity = " . $entity;
		if ($statusFilter === 1 || $statusFilter === 0) {
			$sql .= " AND u.statut = " . (int) $statusFilter;
		}
		$sql .= " AND (ue.zs_user_hide IS NULL OR ue.zs_user_hide = 0)";

		$resql = $this->db->query($sql);
		if ($resql) {
			while ($obj = $this->db->fetch_object($resql)) {
				$uid = (int) $obj->fk_user;
				if ($uid > 0) {
					$includeEmployees[$uid] = $uid;
				}
			}
			$this->db->free($resql);
		} else {
			$this->error = $this->db->lasterror();
			$this->errors[] = $this->error;
		}

		return $includeEmployees ?: array(-1);
	}

	/**
	 * Get employees for an entity that were employed at any point in a given year.
	 *
	 * Includes active or inactive users (statusFilter 1 or 0 to force a specific status,
	 * any other value includes both) and ignores hidden users (zs_user_hide).
	 * Returns [-1] when none are found so select_dolusers shows an empty list.
	 *
	 * @param int $year Target year (e.g. 2024)
	 * @param int|null $entityId
	 * @param int $statusFilter 1 active, 0 inactive, any other value = both
	 * @param string $orderField Allowed: u.lastname, u.firstname, u.login, u.rowid
	 * @param string $orderDir ASC or DESC
	 * @return array<int, int>
	 */
	public function getEntityEmployeesForYear($year, $entityId = null, $statusFilter = 2, $orderField = 'u.firstname', $orderDir = 'ASC')
	{
		global $conf;

		$year = (int) $year;
		if ($year <= 0) {
			return array(-1);
		}

		$entity = $entityId !== null ? (int) $entityId : (int) $conf->entity;
		$includeEmployees = array();

		$allowedOrderFields = array(
			'u.lastname' => 'u.lastname',
			'u.firstname' => 'u.firstname',
			'u.login' => 'u.login',
			'u.rowid' => 'u.rowid',
		);
		if (!isset($allowedOrderFields[$orderField])) {
			$orderField = 'u.lastname';
		}
		$orderDir = strtoupper($orderDir) === 'DESC' ? 'DESC' : 'ASC';

		$yearStartIso = sprintf('%04d-01-01', $year);
		$yearEndIso = sprintf('%04d-12-31', $year);

		$sql  = "SELECT DISTINCT ugu.fk_user";
		$sql .= " FROM " . MAIN_DB_PREFIX . "usergroup_user AS ugu";
		$sql .= " INNER JOIN " . MAIN_DB_PREFIX . "user AS u ON u.rowid = ugu.fk_user";
		$sql .= " LEFT JOIN " . MAIN_DB_PREFIX . "user_extrafields AS ue ON ue.fk_object = u.rowid";
		$sql .= " WHERE ugu.entity = " . $entity;
		if ($statusFilter === 1 || $statusFilter === 0) {
			$sql .= " AND u.statut = " . (int) $statusFilter;
		}
		$sql .= " AND (ue.zs_user_hide IS NULL OR ue.zs_user_hide = 0)";
		$sql .= " AND (u.dateemployment IS NULL OR u.dateemployment <= '" . $this->db->escape($yearEndIso) . "')";
		$sql .= " AND (u.dateemploymentend IS NULL OR u.dateemploymentend = '0000-00-00' OR u.dateemploymentend >= '" . $this->db->escape($yearStartIso) . "')";
		$sql .= " ORDER BY " . $allowedOrderFields[$orderField] . " " . $orderDir;

		$resql = $this->db->query($sql);
		if ($resql) {
			while ($obj = $this->db->fetch_object($resql)) {
				$uid = (int) $obj->fk_user;
				if ($uid > 0) {
					$includeEmployees[$uid] = $uid;
				}
			}
			$this->db->free($resql);
		} else {
			$this->error = $this->db->lasterror();
			$this->errors[] = $this->error;
		}

		return $includeEmployees ?: array(-1);
	}

	/**
	 * Compute leave usage (in days) per user and per type for a given year.
	 *
	 * @param array<int> $userIds
	 * @param array<int> $typeIds
	 * @param int|null $year
	 * @param int $dayMinutesBase Minutes that represent a full day (default 8h = 480)
	 * @return array<int, array<int, float>> userId => typeId => daysUsed
	 */
	public function getUsageByUser(array $userIds, array $typeIds, $year = null, $dayMinutesBase = 480)
	{
		global $conf;

		$userIds = array_values(array_filter(array_unique(array_map('intval', $userIds)), function ($v) {
			return $v > 0;
		}));
		if (empty($userIds)) {
			return array();
		}
		$typeIds = array_values(array_filter(array_unique(array_map('intval', $typeIds)), function ($v) {
			return $v > 0;
		}));

		$year = (int) ($year ?: date('Y'));
		$dayMinutesBase = max(1, (int) $dayMinutesBase);
		$yearStart = shiftplanner_operational_day_start_from_iso($year . '-01-01');
		$yearEndExclusive = shiftplanner_operational_day_start_from_iso(($year + 1) . '-01-01'); // exclusive upper bound

		$sql = "SELECT fk_user, fk_type, date_start, date_end, halfday, minutes_total, day_change_hour";
		$sql .= " FROM " . MAIN_DB_PREFIX . $this->table_element;
		$sql .= " WHERE (entity IS NULL OR entity IN (0, " . ((int) $conf->entity) . "))";
		$sql .= " AND fk_user IN (" . $this->db->sanitize(implode(',', $userIds)) . ")";
		if (!empty($typeIds)) {
			$sql .= " AND fk_type IN (" . $this->db->sanitize(implode(',', $typeIds)) . ")";
		}
		$sql .= " AND date_end > '" . $this->db->escape($this->db->idate($yearStart)) . "'";
		$sql .= " AND date_start < '" . $this->db->escape($this->db->idate($yearEndExclusive)) . "'";

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			$this->errors[] = $this->error;
			return array();
		}

		$usage = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$uid = (int) $obj->fk_user;
			if ($uid <= 0) continue;
			$tid = (int) $obj->fk_type;
			$halfday = (int) $obj->halfday;
			$minutesTotal = (int) $obj->minutes_total;
			$dayChangeHour = isset($obj->day_change_hour) && $obj->day_change_hour !== null
				? (int) $obj->day_change_hour
				: shiftplanner_get_day_change_hour();

			$startTs = shiftplannerNormalizeTimestamp($this->db, $obj->date_start);
			$endTs = shiftplannerNormalizeTimestamp($this->db, $obj->date_end);
			if (!$startTs || !$endTs) continue;

			// Clip to requested year range
			$clipStart = max($startTs, $yearStart);
			$clipEnd = min($endTs, $yearEndExclusive);
			if ($clipEnd <= $clipStart) continue;

			$startRef = shiftplanner_operational_boundary_for_timestamp($clipStart, $dayChangeHour);
			$endRef = shiftplanner_operational_boundary_for_timestamp($clipEnd - 1, $dayChangeHour);
			$daysCount = floor(($endRef - $startRef) / 86400) + 1;

			// Partial handling
			if ($halfday === 3 && $minutesTotal > 0) {
				$fraction = min(1, $minutesTotal / $dayMinutesBase);
				if ($fraction > 0) {
					$daysCount = $fraction;
				}
			} elseif ($halfday === 2) {
				$minutesRange = max(0, (int) round(($clipEnd - $clipStart) / 60));
				$fraction = min(1, $minutesRange / $dayMinutesBase);
				if ($fraction > 0) {
					$daysCount = $fraction;
				}
			} elseif ($halfday === 1) {
				$daysCount = min($daysCount, 0.5);
			}

			$daysCount = round($daysCount, 4);
			if (!isset($usage[$uid])) {
				$usage[$uid] = array();
			}
			if (!isset($usage[$uid][$tid])) {
				$usage[$uid][$tid] = 0.0;
			}
			$usage[$uid][$tid] += $daysCount;
		}

		$this->db->free($resql);
		return $usage;
	}

	/**
	 * Get active leave types.
	 *
	 * @param int $active
	 * @return array
	 */
	public function getTypes($active = 1)
	{
		global $conf;

		$sql = "SELECT rowid, code, label FROM " . MAIN_DB_PREFIX . "c_holiday_types";
		$sql .= " WHERE 1 = 1";
		if ($active >= 0) {
			$sql .= " AND active = " . (int) $active;
		}
		$sql .= " AND entity IN (0, " . ((int) $conf->entity) . ")";
		$sql .= " ORDER BY label";

		$resql = $this->db->query($sql);
		if (!$resql) {
			return array();
		}

		$out = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$out[(int) $obj->rowid] = array(
				'rowid' => (int) $obj->rowid,
				'code' => $obj->code,
				'label' => $obj->label,
			);
		}
		$this->db->free($resql);
		return $out;
	}

	public function getNomUrl($withpicto = 0, $option = '', $more = '')
	{
		$link = dol_buildpath('/kreaflow/leaves/card.php', 1) . '?id=' . ((int) $this->id);
		$label = ($this->ref ? $this->ref : 'LV' . (int) $this->id);
		$out = '<a href="' . $link . '">' . dol_escape_htmltag($label) . '</a>';
		if ($withpicto) {
			$out = img_object('', $this->picto) . ' ' . $out;
		}
		return $out;
	}

	public function getLibStatut($mode = 0, $alreadypaid = '')
	{
		global $langs;

		$label = $langs->trans('Approved');
		if ($mode === 5) {
			return $label;
		}
		return $label;
	}

	private function normalizeDateValue($value)
	{
		if (empty($value)) return 0;
		if (is_int($value) || ctype_digit((string) $value)) {
			return (int) $value;
		}
		$ts = strtotime((string) $value);
		return $ts ?: 0;
	}
}

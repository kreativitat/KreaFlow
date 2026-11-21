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
 *  \file       class/zsdayoff.class.php
 *  \ingroup    dolizsynch
 *  \brief      Day off management class.
 */

require_once DOL_DOCUMENT_ROOT . '/core/class/commonobject.class.php';

/**
 * Class to manage employee day off records.
 */
class DayOff extends CommonObject
{
	public $element = 'dayoff';
	public $table_element = 'dolizsynch_dayoff';

	public $rowid;
	public $id;
	public $entity;
	public $fk_user;
	public $date_dayoff;
	public $is_recurring = 0;
	public $is_lock = 0;
	public $description;
	public $date_creation;
	public $fk_user_creat;
	public $fk_user_modif;
	private static $hasLockColumn = null;
	private static $hasEntityColumn = null;

	/**
	 * Constructor.
	 *
	 * @param DoliDB $db
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * Create a day off record.
	 *
	 * @param User $user
	 * @return int
	 */
	public function create($user)
	{
		$now = dol_now();
		$dateSql = $this->date_dayoff ? $this->db->escape(dol_print_date($this->date_dayoff, '%Y-%m-%d')) : '';
		if ($dateSql === '') {
			$this->error = 'Invalid date';
			return -1;
		}

		$this->db->begin();

		$hasLock = $this->hasLockColumn();
		$hasEntity = $this->hasEntityColumn();

		$sql = "INSERT INTO " . MAIN_DB_PREFIX . $this->table_element . " (";
		if ($hasEntity) {
			$sql .= "entity, ";
		}
		$sql .= "fk_user, date_dayoff, is_recurring, description";
		if ($hasLock) {
			$sql .= ", is_lock";
		}
		$sql .= ", date_creation, fk_user_creat";
		$sql .= ") VALUES (";
		if ($hasEntity) {
			$entityValue = $this->entity;
			if ($entityValue === null || $entityValue === '') {
				$entityValue = isset($user->entity) ? (int) $user->entity : 1;
			}
			$sql .= (int) $entityValue . ", ";
		}
		$sql .= (int) $this->fk_user . ", ";
		$sql .= "'" . $dateSql . "', ";
		$sql .= (int) $this->is_recurring . ", ";
		$sql .= ($this->description !== '' ? "'" . $this->db->escape($this->description) . "'" : "NULL");
		if ($hasLock) {
			$sql .= ", " . (int) $this->is_lock;
		}
		$sql .= ", '" . $this->db->idate($now) . "', ";
		$sql .= (int) $user->id;
		$sql .= ")";

		$resql = $this->db->query($sql);
		if ($resql) {
			$this->rowid = $this->db->last_insert_id(MAIN_DB_PREFIX . $this->table_element);
			$this->id = $this->rowid;
			$this->db->commit();
			return $this->rowid;
		}

		$this->error = $this->db->lasterror();
		$this->db->rollback();

		return -1;
	}

	/**
	 * Update a day off record.
	 *
	 * @param User $user
	 * @return int
	 */
	public function update($user)
	{
		$dateSql = $this->date_dayoff ? $this->db->escape(dol_print_date($this->date_dayoff, '%Y-%m-%d')) : '';
		if ($dateSql === '') {
			$this->error = 'Invalid date';
			return -1;
		}

		$this->db->begin();

		$hasLock = $this->hasLockColumn();
		$hasEntity = $this->hasEntityColumn();

		$sql = "UPDATE " . MAIN_DB_PREFIX . $this->table_element . " SET";
		if ($hasEntity) {
			$entityValue = $this->entity;
			if ($entityValue === null || $entityValue === '') {
				$entityValue = isset($user->entity) ? (int) $user->entity : 1;
			}
			$sql .= " entity = " . (int) $entityValue . ",";
		}
		$sql .= " fk_user = " . (int) $this->fk_user . ",";
		$sql .= " date_dayoff = '" . $dateSql . "',";
		$sql .= " is_recurring = " . (int) $this->is_recurring . ",";
		if ($hasLock) {
			$sql .= " is_lock = " . (int) $this->is_lock . ",";
		}
		$sql .= " description = " . ($this->description !== '' ? "'" . $this->db->escape($this->description) . "'" : "NULL") . ",";
		$sql .= " fk_user_modif = " . (int) $user->id;
		$sql .= " WHERE rowid = " . (int) $this->rowid;

		$resql = $this->db->query($sql);
		if ($resql) {
			$this->db->commit();
			return 1;
		}

		$this->error = $this->db->lasterror();
		$this->db->rollback();
		return -1;
	}

	/**
	 * Delete a day off.
	 *
	 * @param User $user
	 * @return int
	 */
	public function delete($user)
	{
		$this->db->begin();
		$hasEntity = $this->hasEntityColumn();

		$sql = "DELETE FROM " . MAIN_DB_PREFIX . $this->table_element;
		$sql .= " WHERE rowid = " . (int) $this->rowid;
		if ($hasEntity) {
			$entityValue = $this->entity;
			if ($entityValue === null || $entityValue === '') {
				$entityValue = isset($user->entity) ? (int) $user->entity : 1;
			}
			$sql .= " AND entity = " . (int) $entityValue;
		}

		$resql = $this->db->query($sql);
		if ($resql) {
			$this->db->commit();
			return 1;
		}

		$this->error = $this->db->lasterror();
		$this->db->rollback();
		return -1;
	}

	/**
	 * Fetch a day off record.
	 *
	 * @param int $id
	 * @return int
	 */
	public function fetch($id)
	{
		$sql = "SELECT * FROM " . MAIN_DB_PREFIX . $this->table_element;
		$sql .= " WHERE rowid = " . (int) $id;

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return -1;
		}

		$obj = $this->db->fetch_object($resql);
		if (!$obj) {
			return 0;
		}

		$this->rowid = $obj->rowid;
		$this->id = $obj->rowid;
		$this->entity = property_exists($obj, 'entity') ? (int) $obj->entity : null;
		$this->fk_user = $obj->fk_user;
		$this->date_dayoff = $this->db->jdate($obj->date_dayoff);
		$this->is_recurring = (int) $obj->is_recurring;
		$this->is_lock = property_exists($obj, 'is_lock') ? (int) $obj->is_lock : 0;
		$this->description = $obj->description;
		$this->date_creation = $obj->date_creation;
		$this->fk_user_creat = $obj->fk_user_creat;
		$this->fk_user_modif = $obj->fk_user_modif;

		return 1;
	}

	/**
	 * Fetch all day offs for a user in a date range.
	 *
	 * @param int $userId
	 * @param int $dateStart
	 * @param int $dateEnd
	 * @return DayOff[]
	 */
	public function fetchByUserAndDateRange($userId, $dateStart, $dateEnd)
	{
		$dayoffs = array();
		if (empty($dateStart) || empty($dateEnd) || $dateEnd < $dateStart) {
			return $dayoffs;
		}

		$startDate = $this->db->escape(dol_print_date($dateStart, '%Y-%m-%d'));
		$endDate = $this->db->escape(dol_print_date($dateEnd, '%Y-%m-%d'));

		$sql = "SELECT * FROM " . MAIN_DB_PREFIX . $this->table_element;
		$sql .= " WHERE fk_user = " . (int) $userId;
		$sql .= " AND date_dayoff >= '" . $startDate . "'";
		$sql .= " AND date_dayoff <= '" . $endDate . "'";
		$sql .= " ORDER BY date_dayoff";

		$resql = $this->db->query($sql);
		if (!$resql) {
			return $dayoffs;
		}

		while ($obj = $this->db->fetch_object($resql)) {
			$instance = new self($this->db);
			$instance->rowid = $obj->rowid;
			$instance->id = $obj->rowid;
			$instance->entity = property_exists($obj, 'entity') ? (int) $obj->entity : null;
			$instance->fk_user = $obj->fk_user;
			$instance->date_dayoff = $this->db->jdate($obj->date_dayoff);
			$instance->is_recurring = (int) $obj->is_recurring;
			$instance->is_lock = property_exists($obj, 'is_lock') ? (int) $obj->is_lock : 0;
			$instance->description = $obj->description;
			$dayoffs[] = $instance;
		}
		$this->db->free($resql);

		return $dayoffs;
	}

	private function hasLockColumn()
	{
		if (self::$hasLockColumn !== null) return self::$hasLockColumn;
		$table = MAIN_DB_PREFIX . $this->table_element;
		$sql = "SHOW COLUMNS FROM " . $table . " LIKE 'is_lock'";
		$res = $this->db->query($sql);
		$has = $res && $this->db->num_rows($res) > 0;
		if ($res) $this->db->free($res);
		self::$hasLockColumn = $has;
		return $has;
	}

	private function hasEntityColumn()
	{
		if (self::$hasEntityColumn !== null) return self::$hasEntityColumn;
		$table = MAIN_DB_PREFIX . $this->table_element;
		$sql = "SHOW COLUMNS FROM " . $table . " LIKE 'entity'";
		$res = $this->db->query($sql);
		$has = $res && $this->db->num_rows($res) > 0;
		if ($res) $this->db->free($res);
		self::$hasEntityColumn = $has;
		return $has;
	}
}

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
 * Service for KreaClock kiosk bootstrap and idempotent punch ingestion.
 */
class KreaClockService
{
	/** @var DoliDB */
	private $db;

	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct(DoliDB $db)
	{
		$this->db = $db;
	}

	/**
	 * Return the active employee pool and suggested next punch direction.
	 *
	 * @param User   $apiUser Authenticated API user
	 * @param string $stationCode Stable kiosk station code
	 * @return array<string,mixed>
	 * @throws KreaClockValidationException
	 * @throws KreaClockConfigurationException
	 */
	public function getBootstrap(User $apiUser, $stationCode)
	{
		global $conf;

		$stationCode = $this->normalizeStationCode($stationCode);
		$entity = (int) $conf->entity;
		$storeId = $this->getCurrentStoreId();
		$groupIds = $this->getEmployeeGroupIds();

		$sql = "SELECT DISTINCT u.rowid, u.firstname, u.lastname, ue.zs_employee_id";
		$sql .= ", last_event.direction AS last_direction, last_event.captured_at AS last_punch_at";
		$sql .= " FROM " . MAIN_DB_PREFIX . "user AS u";
		$sql .= " INNER JOIN " . MAIN_DB_PREFIX . "user_extrafields AS ue ON ue.fk_object = u.rowid";
		if (!empty($groupIds)) {
			$sql .= " INNER JOIN " . MAIN_DB_PREFIX . "usergroup_user AS ugu ON ugu.fk_user = u.rowid";
			$sql .= " AND ugu.entity = " . $entity;
			$sql .= " AND ugu.fk_usergroup IN (" . implode(',', $groupIds) . ")";
		}
		$sql .= " LEFT JOIN " . MAIN_DB_PREFIX . "dolizsynch_kreaclock_event AS last_event";
		$sql .= " ON last_event.rowid = (";
		$sql .= " SELECT e2.rowid FROM " . MAIN_DB_PREFIX . "dolizsynch_kreaclock_event AS e2";
		$sql .= " WHERE e2.entity = " . $entity;
		$sql .= " AND e2.fk_user = u.rowid AND e2.status = 'accepted'";
		$sql .= " ORDER BY e2.captured_at DESC, e2.rowid DESC LIMIT 1";
		$sql .= " )";
		$sql .= " WHERE u.statut = 1";
		$sql .= " AND ue.zs_employee_id IS NOT NULL AND ue.zs_employee_id > 0";
		if (empty($groupIds)) {
			$sql .= " AND u.entity = " . $entity;
		}
		$sql .= " ORDER BY u.firstname, u.lastname, u.rowid";

		$resql = $this->db->query($sql);
		if (!$resql) {
			dol_syslog(__METHOD__ . ' employee query failed: ' . $this->db->lasterror(), LOG_ERR);
			throw new KreaClockStorageException('Unable to load the KreaClock employee pool');
		}

		$employees = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$lastDirection = (int) $obj->last_direction;
			$employees[] = array(
				'user_id' => (int) $obj->rowid,
				'zonesoft_employee_id' => (int) $obj->zs_employee_id,
				'display_name' => trim((string) $obj->firstname . ' ' . (string) $obj->lastname),
				'suggested_direction' => $lastDirection === 1 ? 2 : 1,
				'last_punch_at' => !empty($obj->last_punch_at) ? $this->formatServerDateTime($obj->last_punch_at) : null,
			);
		}
		$this->db->free($resql);

		return array(
			'entity' => $entity,
			'store_id' => $storeId,
			'station_code' => $stationCode,
			'server_time' => date(DATE_ATOM),
			'employees' => $employees,
		);
	}

	/**
	 * Atomically store the kiosk event and corresponding raw punch row.
	 *
	 * @param User                $apiUser Authenticated API user
	 * @param array<string,mixed> $payload Request payload
	 * @return array<string,mixed>
	 * @throws KreaClockValidationException
	 * @throws KreaClockConfigurationException
	 * @throws KreaClockConflictException
	 */
	public function createPunch(User $apiUser, array $payload)
	{
		global $conf;

		$entity = (int) $conf->entity;
		$clientEventId = $this->normalizeClientEventId($this->readPayloadString($payload, 'client_event_id'));
		$stationCode = $this->normalizeStationCode($this->readPayloadString($payload, 'station_code'));
		$employeeUserId = $this->readPayloadInt($payload, 'user_id');
		$direction = $this->readPayloadInt($payload, 'direction');
		if (!in_array($direction, array(1, 2), true)) {
			throw new KreaClockValidationException('direction must be 1 (in) or 2 (out)');
		}

		$recognitionMethod = $this->normalizeRecognitionMethod($this->readPayloadString($payload, 'recognition_method'));
		$recognitionConfidence = $this->normalizeConfidence(isset($payload['recognition_confidence']) ? $payload['recognition_confidence'] : null);
		$capturedAt = $this->normalizeCapturedAt($this->readPayloadString($payload, 'captured_at'));
		$clientTimezoneOffsetMinutes = (int) ($capturedAt->getOffset() / 60);
		$storedCapturedAt = $capturedAt->setTimezone(new DateTimeZone(date_default_timezone_get()));
		$storeId = $this->getCurrentStoreId();
		$employee = $this->fetchAuthorizedEmployee($employeeUserId, $entity);

		$existing = $this->fetchEventByClientId($entity, $clientEventId);
		if ($existing !== null) {
			$existing['duplicate'] = true;
			return $existing;
		}

		$this->db->begin();
		try {
			$this->assertMinimumPunchInterval($entity, (int) $employee['zonesoft_employee_id'], $storedCapturedAt);

			$sql = "INSERT INTO " . MAIN_DB_PREFIX . "dolizsynch_kreaclock_event (";
			$sql .= "entity, client_event_id, station_code, fk_user, empid_zs, loja_zs, direction";
			$sql .= ", captured_at, client_timezone_offset_minutes, received_at, recognition_method";
			$sql .= ", recognition_confidence, fk_api_user, status";
			$sql .= ") VALUES (";
			$sql .= $entity . ",";
			$sql .= " '" . $this->db->escape($clientEventId) . "',";
			$sql .= " '" . $this->db->escape($stationCode) . "',";
			$sql .= " " . $employeeUserId . ",";
			$sql .= " " . ((int) $employee['zonesoft_employee_id']) . ",";
			$sql .= " " . $storeId . ",";
			$sql .= " " . $direction . ",";
			$sql .= " '" . $this->db->escape($storedCapturedAt->format('Y-m-d H:i:s')) . "',";
			$sql .= " " . $clientTimezoneOffsetMinutes . ",";
			$sql .= " '" . $this->db->idate(dol_now()) . "',";
			$sql .= " '" . $this->db->escape($recognitionMethod) . "',";
			$sql .= $recognitionConfidence === null ? " NULL," : " " . ((float) $recognitionConfidence) . ",";
			$sql .= " " . ((int) $apiUser->id) . ",";
			$sql .= " 'pending')";

			if (!$this->db->query($sql)) {
				if (stripos((string) $this->db->lasterror(), 'duplicate') !== false) {
					$this->db->rollback();
					$duplicate = $this->fetchEventByClientId($entity, $clientEventId);
					if ($duplicate !== null) {
						$duplicate['duplicate'] = true;
						return $duplicate;
					}
				}
				throw new KreaClockStorageException('Unable to create the KreaClock audit event');
			}
			$eventId = (int) $this->db->last_insert_id(MAIN_DB_PREFIX . 'dolizsynch_kreaclock_event');

			$sql = "INSERT INTO " . MAIN_DB_PREFIX . "dolizsynch_zspunchclock_synch (";
			$sql .= "entity, date_creation, loja_zs, sentido, empid_zs, datahora, dataval";
			$sql .= ", id_zs, synch, zsbms_synch, pairid, fk_user_creat, fk_user_modif";
			$sql .= ") VALUES (";
			$sql .= $entity . ",";
			$sql .= " '" . $this->db->idate(dol_now()) . "',";
			$sql .= " " . $storeId . ",";
			$sql .= " " . $direction . ",";
			$sql .= " " . ((int) $employee['zonesoft_employee_id']) . ",";
			$sql .= " '" . $this->db->escape($storedCapturedAt->format('Y-m-d H:i:s')) . "',";
			$sql .= " '" . $this->db->escape($storedCapturedAt->format('Y-m-d')) . "',";
			// Pairing deliberately ignores NULL id_zs rows as synthetic. A negative,
			// event-scoped source id keeps KreaClock punches pairable without
			// colliding with positive ZoneSoft register ids.
			$sql .= " " . (0 - $eventId) . ", 0, 0, NULL,";
			$sql .= " " . ((int) $apiUser->id) . ", NULL)";

			if (!$this->db->query($sql)) {
				throw new KreaClockStorageException('Unable to create the raw punch record');
			}
			$rawPunchId = (int) $this->db->last_insert_id(MAIN_DB_PREFIX . 'dolizsynch_zspunchclock_synch');

			$sql = "UPDATE " . MAIN_DB_PREFIX . "dolizsynch_kreaclock_event";
			$sql .= " SET fk_raw_punch = " . $rawPunchId . ", status = 'accepted'";
			$sql .= " WHERE rowid = " . $eventId . " AND entity = " . $entity;
			if (!$this->db->query($sql)) {
				throw new KreaClockStorageException('Unable to link the KreaClock event to the raw punch');
			}

			$this->db->commit();
		} catch (Throwable $e) {
			$this->db->rollback();
			if ($e instanceof KreaClockException) {
				throw $e;
			}
			dol_syslog(__METHOD__ . ' transaction failed: ' . $e->getMessage(), LOG_ERR);
			throw new KreaClockStorageException('Unable to store the punch event');
		}

		return array(
			'event_id' => $eventId,
			'client_event_id' => $clientEventId,
			'user_id' => $employeeUserId,
			'direction' => $direction,
			'captured_at' => $capturedAt->format(DATE_ATOM),
			'duplicate' => false,
			'suggested_next_direction' => $direction === 1 ? 2 : 1,
		);
	}

	/**
	 * @param int    $entity Entity id
	 * @param string $clientEventId Client UUID
	 * @return array<string,mixed>|null
	 */
	private function fetchEventByClientId($entity, $clientEventId)
	{
		$sql = "SELECT rowid, client_event_id, fk_user, direction, captured_at";
		$sql .= " FROM " . MAIN_DB_PREFIX . "dolizsynch_kreaclock_event";
		$sql .= " WHERE entity = " . ((int) $entity);
		$sql .= " AND client_event_id = '" . $this->db->escape($clientEventId) . "'";
		$sql .= " AND status = 'accepted' LIMIT 1";
		$resql = $this->db->query($sql);
		if (!$resql) {
			throw new KreaClockStorageException('Unable to check KreaClock event idempotency');
		}
		$obj = $this->db->fetch_object($resql);
		$this->db->free($resql);
		if (!$obj) {
			return null;
		}

		return array(
			'event_id' => (int) $obj->rowid,
			'client_event_id' => (string) $obj->client_event_id,
			'user_id' => (int) $obj->fk_user,
			'direction' => (int) $obj->direction,
			'captured_at' => $this->formatServerDateTime($obj->captured_at),
			'duplicate' => false,
			'suggested_next_direction' => (int) $obj->direction === 1 ? 2 : 1,
		);
	}

	/**
	 * @param int $userId Employee user id
	 * @param int $entity Current entity
	 * @return array<string,int>
	 */
	private function fetchAuthorizedEmployee($userId, $entity)
	{
		if ($userId <= 0) {
			throw new KreaClockValidationException('user_id must be greater than zero');
		}

		$groupIds = $this->getEmployeeGroupIds();
		$sql = "SELECT DISTINCT u.rowid, ue.zs_employee_id";
		$sql .= " FROM " . MAIN_DB_PREFIX . "user AS u";
		$sql .= " INNER JOIN " . MAIN_DB_PREFIX . "user_extrafields AS ue ON ue.fk_object = u.rowid";
		if (!empty($groupIds)) {
			$sql .= " INNER JOIN " . MAIN_DB_PREFIX . "usergroup_user AS ugu ON ugu.fk_user = u.rowid";
			$sql .= " AND ugu.entity = " . ((int) $entity);
			$sql .= " AND ugu.fk_usergroup IN (" . implode(',', $groupIds) . ")";
		}
		$sql .= " WHERE u.rowid = " . ((int) $userId) . " AND u.statut = 1";
		$sql .= " AND ue.zs_employee_id IS NOT NULL AND ue.zs_employee_id > 0";
		if (empty($groupIds)) {
			$sql .= " AND u.entity = " . ((int) $entity);
		}
		$sql .= " LIMIT 1";

		$resql = $this->db->query($sql);
		if (!$resql) {
			throw new KreaClockStorageException('Unable to validate the punch employee');
		}
		$obj = $this->db->fetch_object($resql);
		$this->db->free($resql);
		if (!$obj) {
			throw new KreaClockValidationException('Employee is not active or authorized in the current entity');
		}

		return array(
			'user_id' => (int) $obj->rowid,
			'zonesoft_employee_id' => (int) $obj->zs_employee_id,
		);
	}

	/**
	 * Reject accidental repeat punches while preserving explicit later corrections.
	 *
	 * @param int               $entity Entity id
	 * @param int               $employeeId ZoneSoft employee id
	 * @param DateTimeImmutable $capturedAt Capture time
	 * @return void
	 */
	private function assertMinimumPunchInterval($entity, $employeeId, DateTimeImmutable $capturedAt)
	{
		$minimumSeconds = max(0, min(300, (int) getDolGlobalInt('KREACLOCK_MIN_PUNCH_INTERVAL_SECONDS', 30)));
		if ($minimumSeconds === 0) {
			return;
		}

		$sql = "SELECT datahora FROM " . MAIN_DB_PREFIX . "dolizsynch_zspunchclock_synch";
		$sql .= " WHERE entity = " . ((int) $entity);
		$sql .= " AND empid_zs = " . ((int) $employeeId);
		$sql .= " ORDER BY datahora DESC, rowid DESC LIMIT 1 FOR UPDATE";
		$resql = $this->db->query($sql);
		if (!$resql) {
			throw new KreaClockStorageException('Unable to validate the minimum punch interval');
		}
		$obj = $this->db->fetch_object($resql);
		$this->db->free($resql);
		if (!$obj || empty($obj->datahora)) {
			return;
		}

		$lastTimestamp = strtotime($obj->datahora);
		if ($lastTimestamp !== false && abs($capturedAt->getTimestamp() - $lastTimestamp) < $minimumSeconds) {
			throw new KreaClockConflictException('A punch for this employee was already recorded moments ago');
		}
	}

	/** @return array<int> */
	private function getEmployeeGroupIds()
	{
		$raw = trim((string) getDolGlobalString('DOLIZSYNCH_SHIFT_EMPLOYEE_GROUPS', ''));
		$groupIds = array();
		if ($raw !== '') {
			foreach (preg_split('/\s*,\s*/', $raw) as $value) {
				$id = (int) $value;
				if ($id > 0) {
					$groupIds[$id] = $id;
				}
			}
		}
		if (empty($groupIds)) {
			$fallback = (int) getDolGlobalInt('DOLIZSYNCH_SHIFT_EMPLOYEE_GROUP', 0);
			if ($fallback > 0) {
				$groupIds[$fallback] = $fallback;
			}
		}

		return array_values($groupIds);
	}

	/** @return int */
	private function getCurrentStoreId()
	{
		$store = trim((string) getDolGlobalString('ZS_API_STORE', ''));
		if ($store === '' || !ctype_digit($store) || (int) $store <= 0) {
			throw new KreaClockConfigurationException('ZS_API_STORE is not configured for the current entity');
		}

		return (int) $store;
	}

	/** @return DateTimeImmutable */
	private function normalizeCapturedAt($value)
	{
		if (!preg_match('/(Z|[+\-]\d{2}:\d{2})$/', $value)) {
			throw new KreaClockValidationException('captured_at must be ISO 8601 with an explicit timezone offset');
		}
		try {
			$capturedAt = new DateTimeImmutable($value);
		} catch (Exception $e) {
			throw new KreaClockValidationException('captured_at is invalid');
		}

		$now = new DateTimeImmutable('now');
		$futureTolerance = max(0, min(3600, (int) getDolGlobalInt('KREACLOCK_FUTURE_TOLERANCE_SECONDS', 300)));
		$maxOfflineDays = max(0, min(31, (int) getDolGlobalInt('KREACLOCK_MAX_OFFLINE_DAYS', 7)));
		if ($capturedAt->getTimestamp() > $now->getTimestamp() + $futureTolerance) {
			throw new KreaClockValidationException('captured_at is too far in the future');
		}
		if ($capturedAt->getTimestamp() < $now->modify('-' . $maxOfflineDays . ' days')->getTimestamp()) {
			throw new KreaClockValidationException('captured_at is older than the allowed offline retention period');
		}

		return $capturedAt;
	}

	/** @return string */
	private function normalizeStationCode($value)
	{
		$value = trim((string) $value);
		if ($value === '' || strlen($value) > 64 || !preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $value)) {
			throw new KreaClockValidationException('station_code must use 1 to 64 letters, numbers, dots, underscores, or hyphens');
		}

		return $value;
	}

	/** @return string */
	private function normalizeClientEventId($value)
	{
		$value = strtolower(trim((string) $value));
		if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $value)) {
			throw new KreaClockValidationException('client_event_id must be a valid UUID');
		}

		return $value;
	}

	/** @return string */
	private function normalizeRecognitionMethod($value)
	{
		$value = strtolower(trim((string) $value));
		$allowed = array('face', 'pin', 'badge', 'fingerprint_external', 'manual_supervised');
		if (!in_array($value, $allowed, true)) {
			throw new KreaClockValidationException('recognition_method is not supported');
		}

		return $value;
	}

	/** @return float|null */
	private function normalizeConfidence($value)
	{
		if ($value === null || $value === '') {
			return null;
		}
		if (!is_numeric($value) || (float) $value < 0 || (float) $value > 1) {
			throw new KreaClockValidationException('recognition_confidence must be between 0 and 1');
		}

		return round((float) $value, 6);
	}

	/** @return string */
	private function readPayloadString(array $payload, $key)
	{
		if (!array_key_exists($key, $payload) || is_array($payload[$key]) || is_object($payload[$key])) {
			throw new KreaClockValidationException('Missing or invalid field: ' . $key);
		}

		return trim((string) $payload[$key]);
	}

	/** @return int */
	private function readPayloadInt(array $payload, $key)
	{
		if (!array_key_exists($key, $payload) || filter_var($payload[$key], FILTER_VALIDATE_INT) === false) {
			throw new KreaClockValidationException('Missing or invalid integer field: ' . $key);
		}

		return (int) $payload[$key];
	}

	/** @return string */
	private function formatServerDateTime($value)
	{
		$dateTime = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', (string) $value, new DateTimeZone(date_default_timezone_get()));
		return $dateTime ? $dateTime->format(DATE_ATOM) : (string) $value;
	}
}

class KreaClockException extends RuntimeException
{
}

class KreaClockValidationException extends KreaClockException
{
}

class KreaClockConfigurationException extends KreaClockException
{
}

class KreaClockConflictException extends KreaClockException
{
}

class KreaClockStorageException extends KreaClockException
{
}

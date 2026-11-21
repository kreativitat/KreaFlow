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
 * \file class/api_dolizsynch.class.php
 * \ingroup dolizsynch
 * \brief REST API endpoints for timeregister sync and reporting.
 */

if (!class_exists('Dolizsynch')) {
	/**
	 * @access protected
	 * @class Dolizsynch
	 */
	class Dolizsynch extends DolibarrApi
	{
		/**
		 * Keep API class instantiation independent from parent constructor signature
		 * differences across Dolibarr builds.
		 */
		public function __construct()
		{
			$parentClass = get_parent_class($this);
			if (!$parentClass || !method_exists($parentClass, '__construct')) {
				return;
			}

			try {
				$reflection = new ReflectionMethod($parentClass, '__construct');
				if ($reflection->getNumberOfRequiredParameters() === 0) {
					parent::__construct();
				}
			} catch (\Throwable $e) {
				// Keep constructor side-effect free. Endpoint methods handle API errors.
			}
		}

		/** @url GET /timeregisters/sync */
		public function timeregisterSync($lastupdate = null, $store = '')
		{
			$this->assertPunchClockReadPermission();

			try {
				$lastupdate = $this->normalizeDateTimeParameter($lastupdate, 'lastupdate');
				$storeValue = $this->normalizeStoreParameter($store);
				$api = $this->createApiClient();

				$instances = $api->getTimeregisterInstancesToSync($lastupdate, $storeValue);
			} catch (ZSValidationException $e) {
				$this->throwApiError(400, $e->getMessage());
			} catch (ZSAPIException $e) {
				dol_syslog(__METHOD__ . ' API error: ' . $e->getMessage(), LOG_ERR);
				$this->throwApiError(502, 'Failed to fetch timeregister instances to sync');
			} catch (\Throwable $e) {
				if ($this->isRestExceptionObject($e)) {
					throw $e;
				}
				dol_syslog(__METHOD__ . ' fatal error: ' . $e->getMessage(), LOG_ERR);
				$this->throwApiError(503, 'Unable to fetch timeregister instances right now');
			}

			return array(
				'status' => 'success',
				'count' => count($instances),
				'instances' => $instances,
			);
		}

		/** @url GET /timeregisters/period */
		public function timeregisterPeriod($startdatetime = null, $enddatetime = null, $limit = 250, $store = '')
		{
			$this->assertPunchClockReadPermission();

			try {
				$startDateTime = $this->normalizeDateTimeParameter($startdatetime, 'startdatetime');
				$endDateTime = $this->normalizeDateTimeParameter($enddatetime, 'enddatetime');
				$this->validateDateRange($startDateTime, $endDateTime);
				$limitValue = $this->normalizeLimitParameter($limit);
				$storeValue = $this->normalizeStoreParameter($store);
				$api = $this->createApiClient();

				$instances = $api->getTimeregisterInstancesByPeriod($startDateTime, $endDateTime, $limitValue, $storeValue);
			} catch (ZSValidationException $e) {
				$this->throwApiError(400, $e->getMessage());
			} catch (ZSAPIException $e) {
				dol_syslog(__METHOD__ . ' API error: ' . $e->getMessage(), LOG_ERR);
				$this->throwApiError(502, 'Failed to fetch timeregister instances for period');
			} catch (\Throwable $e) {
				if ($this->isRestExceptionObject($e)) {
					throw $e;
				}
				dol_syslog(__METHOD__ . ' fatal error: ' . $e->getMessage(), LOG_ERR);
				$this->throwApiError(503, 'Unable to fetch timeregister period right now');
			}

			return array(
				'status' => 'success',
				'count' => count($instances),
				'instances' => $instances,
			);
		}

		/** @url GET /reports/punchclock */
		public function reportPunchclock($startdatetime = null, $enddatetime = null, $limit = 250, $store = '')
		{
			$this->assertPunchClockReadPermission();

			try {
				$startDateTime = $this->normalizeDateTimeParameter($startdatetime, 'startdatetime');
				$endDateTime = $this->normalizeDateTimeParameter($enddatetime, 'enddatetime');
				$this->validateDateRange($startDateTime, $endDateTime);
				$limitValue = $this->normalizeLimitParameter($limit);
				$storeValue = $this->normalizeStoreParameter($store);
				$api = $this->createApiClient();

				$report = $api->getPunchClockReport($startDateTime, $endDateTime, $storeValue, $limitValue);
			} catch (ZSValidationException $e) {
				$this->throwApiError(400, $e->getMessage());
			} catch (ZSAPIException $e) {
				dol_syslog(__METHOD__ . ' API error: ' . $e->getMessage(), LOG_ERR);
				$this->throwApiError(502, 'Failed to generate punch clock report');
			} catch (\Throwable $e) {
				if ($this->isRestExceptionObject($e)) {
					throw $e;
				}
				dol_syslog(__METHOD__ . ' fatal error: ' . $e->getMessage(), LOG_ERR);
				$this->throwApiError(503, 'Unable to generate punch clock report right now');
			}

			return array(
				'status' => 'success',
				'report' => $report,
			);
		}

		/** @url GET /reports/timesheet */
		public function reportTimesheet($startdatetime = null, $enddatetime = null, $limit = 250, $store = '')
		{
			$this->assertPunchClockReadPermission();

			try {
				$startDateTime = $this->normalizeDateTimeParameter($startdatetime, 'startdatetime');
				$endDateTime = $this->normalizeDateTimeParameter($enddatetime, 'enddatetime');
				$this->validateDateRange($startDateTime, $endDateTime);
				$limitValue = $this->normalizeLimitParameter($limit);
				$storeValue = $this->normalizeStoreParameter($store);
				$api = $this->createApiClient();

				$report = $api->getTimesheetReport($startDateTime, $endDateTime, $storeValue, $limitValue);
			} catch (ZSValidationException $e) {
				$this->throwApiError(400, $e->getMessage());
			} catch (ZSAPIException $e) {
				dol_syslog(__METHOD__ . ' API error: ' . $e->getMessage(), LOG_ERR);
				$this->throwApiError(502, 'Failed to generate timesheet report');
			} catch (\Throwable $e) {
				if ($this->isRestExceptionObject($e)) {
					throw $e;
				}
				dol_syslog(__METHOD__ . ' fatal error: ' . $e->getMessage(), LOG_ERR);
				$this->throwApiError(503, 'Unable to generate timesheet report right now');
			}

			return array(
				'status' => 'success',
				'report' => $report,
			);
		}

		/** @url GET /reports/punchclock/frominstances */
		public function reportPunchclockFromInstancesGet($instancesjson = null)
		{
			try {
				$decodedPayload = $this->decodeJsonPayloadParameter($instancesjson, 'instancesjson');
				$instances = $this->extractInstancesList($decodedPayload);
				$api = $this->createApiClient(false);
				$report = $api->buildPunchClockReportFromInstances($instances);
			} catch (\Throwable $e) {
				if ($this->isRestExceptionObject($e)) {
					throw $e;
				}
				dol_syslog(__METHOD__ . ' fatal error: ' . $e->getMessage(), LOG_ERR);
				$this->throwApiError(503, 'Unable to build punch clock report from instances right now');
			}

			return array(
				'status' => 'success',
				'report' => $report,
			);
		}

		/** @url POST /reports/punchclock/frominstances */
		public function reportPunchclockFromInstancesPost($request_data = null)
		{
			try {
				$instances = $this->extractInstancesList($this->readBodyPayload($request_data));
				$api = $this->createApiClient(false);
				$report = $api->buildPunchClockReportFromInstances($instances);
			} catch (\Throwable $e) {
				if ($this->isRestExceptionObject($e)) {
					throw $e;
				}
				dol_syslog(__METHOD__ . ' fatal error: ' . $e->getMessage(), LOG_ERR);
				$this->throwApiError(503, 'Unable to build punch clock report from instances right now');
			}

			return array(
				'status' => 'success',
				'report' => $report,
			);
		}

		/** @url GET /reports/timesheet/frominstances */
		public function reportTimesheetFromInstancesGet($instancesjson = null)
		{
			try {
				$decodedPayload = $this->decodeJsonPayloadParameter($instancesjson, 'instancesjson');
				$instances = $this->extractInstancesList($decodedPayload);
				$api = $this->createApiClient(false);
				$report = $api->buildTimesheetReportFromInstances($instances);
			} catch (\Throwable $e) {
				if ($this->isRestExceptionObject($e)) {
					throw $e;
				}
				dol_syslog(__METHOD__ . ' fatal error: ' . $e->getMessage(), LOG_ERR);
				$this->throwApiError(503, 'Unable to build timesheet report from instances right now');
			}

			return array(
				'status' => 'success',
				'report' => $report,
			);
		}

		/** @url POST /reports/timesheet/frominstances */
		public function reportTimesheetFromInstancesPost($request_data = null)
		{
			try {
				$instances = $this->extractInstancesList($this->readBodyPayload($request_data));
				$api = $this->createApiClient(false);
				$report = $api->buildTimesheetReportFromInstances($instances);
			} catch (\Throwable $e) {
				if ($this->isRestExceptionObject($e)) {
					throw $e;
				}
				dol_syslog(__METHOD__ . ' fatal error: ' . $e->getMessage(), LOG_ERR);
				$this->throwApiError(503, 'Unable to build timesheet report from instances right now');
			}

			return array(
				'status' => 'success',
				'report' => $report,
			);
		}

		/** @url GET /punchclock/bootstrap */
		public function punchclockBootstrap($station_code = '')
		{
			global $db, $user;

			$this->assertPunchClockWritePermission();
			try {
				require_once DOL_DOCUMENT_ROOT . '/custom/kreaflow/class/kreaclockservice.class.php';
				$service = new KreaClockService($db);
				$bootstrap = $service->getBootstrap($user, $station_code);
			} catch (KreaClockValidationException $e) {
				$this->throwApiError(400, $e->getMessage());
			} catch (KreaClockConfigurationException $e) {
				$this->throwApiError(503, $e->getMessage());
			} catch (Throwable $e) {
				if ($this->isRestExceptionObject($e)) {
					throw $e;
				}
				dol_syslog(__METHOD__ . ' fatal error: ' . $e->getMessage(), LOG_ERR);
				$this->throwApiError(503, 'Unable to load KreaClock bootstrap data');
			}

			return array(
				'status' => 'success',
				'bootstrap' => $bootstrap,
			);
		}

		/** @url POST /punchclock/events */
		public function punchclockEventCreate($request_data = null)
		{
			global $db, $user;

			$this->assertPunchClockWritePermission();
			try {
				require_once DOL_DOCUMENT_ROOT . '/custom/kreaflow/class/kreaclockservice.class.php';
				$payload = $this->readBodyPayload($request_data);
				$service = new KreaClockService($db);
				$punch = $service->createPunch($user, $payload);
			} catch (KreaClockValidationException $e) {
				$this->throwApiError(400, $e->getMessage());
			} catch (KreaClockConflictException $e) {
				$this->throwApiError(409, $e->getMessage());
			} catch (KreaClockConfigurationException $e) {
				$this->throwApiError(503, $e->getMessage());
			} catch (Throwable $e) {
				if ($this->isRestExceptionObject($e)) {
					throw $e;
				}
				dol_syslog(__METHOD__ . ' fatal error: ' . $e->getMessage(), LOG_ERR);
				$this->throwApiError(503, 'Unable to store the KreaClock punch event');
			}

			return array(
				'status' => 'success',
				'punch' => $punch,
			);
		}

		private function assertPunchClockReadPermission()
		{
			global $user;

			if (!is_object($user) || empty($user->id) || !$user->hasRight('dolizsynch', 'zspunchclock', 'read')) {
				$this->throwApiError(403, 'KreaFlow punch clock read permission is required');
			}
		}

		private function assertPunchClockWritePermission()
		{
			global $user;

			if (!is_object($user) || empty($user->id) || !$user->hasRight('dolizsynch', 'zspunchclock', 'write')) {
				$this->throwApiError(403, 'KreaFlow punch clock write permission is required');
			}
		}

		private function createApiClient($requireApiConfiguration = true)
		{
			global $db;

			try {
				require_once DOL_DOCUMENT_ROOT . '/custom/kreaflow/class/zsapisynch.class.php';

				if (!class_exists('ZSAPISynch')) {
					$this->throwApiError(503, 'Unable to load ZoneSoft API client class');
				}

				return new ZSAPISynch($db, (bool) $requireApiConfiguration);
			} catch (ZSConfigException $e) {
				$this->throwApiError(503, 'ZoneSoft API is not fully configured: ' . $e->getMessage());
			} catch (\Throwable $e) {
				dol_syslog(__METHOD__ . ' initialization fatal error: ' . $e->getMessage(), LOG_ERR);
				$this->throwApiError(503, 'Unable to initialize ZoneSoft API client');
			}
		}

		private function normalizeDateTimeParameter($value, $name)
		{
			$rawValue = trim((string) $value);
			if ($rawValue === '') {
				$this->throwApiError(400, "Missing required parameter: {$name}");
			}

			$formats = array('Y-m-d H:i:s', 'Y-m-d\TH:i:s', 'Y-m-d\TH:i:sP');
			foreach ($formats as $format) {
				$dateTime = DateTime::createFromFormat($format, $rawValue);
				$errors = DateTime::getLastErrors();
				$errorCount = is_array($errors) ? (int) $errors['error_count'] : 0;
				$warningCount = is_array($errors) ? (int) $errors['warning_count'] : 0;
				if (
					$dateTime instanceof DateTime
					&& $warningCount === 0
					&& $errorCount === 0
				) {
					return $dateTime->format('Y-m-d H:i:s');
				}
			}

			$this->throwApiError(400, "Invalid datetime format for {$name}. Expected YYYY-MM-DD HH:MM:SS");
		}

		private function validateDateRange($startDateTime, $endDateTime)
		{
			$startTimestamp = strtotime($startDateTime);
			$endTimestamp = strtotime($endDateTime);
			if ($startTimestamp === false || $endTimestamp === false || $startTimestamp > $endTimestamp) {
				$this->throwApiError(400, 'Invalid date range: startdatetime must be before or equal to enddatetime');
			}
		}

		private function normalizeLimitParameter($value)
		{
			if ($value === null || $value === '') {
				return 250;
			}

			if (!is_numeric($value)) {
				$this->throwApiError(400, 'Invalid limit value. Expected integer between 1 and 1000');
			}

			$limit = (int) $value;
			if ($limit < 1 || $limit > 1000) {
				$this->throwApiError(400, 'Invalid limit value. Expected integer between 1 and 1000');
			}

			return $limit;
		}

		private function normalizeStoreParameter($store)
		{
			global $conf, $db;

			$storeValue = trim((string) $store);
			if ($storeValue === '') {
				return null;
			}

			$sql = "SELECT rowid FROM " . MAIN_DB_PREFIX . "const";
			$sql .= " WHERE name = 'ZS_API_STORE'";
			$sql .= " AND entity = " . ((int) $conf->entity);
			$sql .= " AND value = '" . $db->escape($storeValue) . "'";
			$sql .= " LIMIT 1";

			$resql = $db->query($sql);
			if (!$resql) {
				dol_syslog(__METHOD__ . ' store validation SQL error: ' . $db->lasterror(), LOG_ERR);
				$this->throwApiError(503, 'Unable to validate store parameter');
			}

			if ($db->num_rows($resql) <= 0) {
				$this->throwApiError(400, 'Invalid store for current entity scope');
			}

			return $storeValue;
		}

		private function decodeJsonPayloadParameter($value, $name)
		{
			$jsonString = trim((string) $value);
			if ($jsonString === '') {
				$this->throwApiError(400, "Missing required parameter: {$name}");
			}

			$decoded = json_decode($jsonString, true);
			if (!is_array($decoded) || json_last_error() !== JSON_ERROR_NONE) {
				$this->throwApiError(400, "Invalid JSON payload for parameter {$name}");
			}

			return $decoded;
		}

		private function readBodyPayload($requestData)
		{
			if (is_object($requestData)) {
				$requestData = get_object_vars($requestData);
			}
			if (is_array($requestData)) {
				return $requestData;
			}

			$rawBody = file_get_contents('php://input');
			$rawBody = is_string($rawBody) ? trim($rawBody) : '';
			if ($rawBody === '') {
				$this->throwApiError(400, 'Missing JSON body');
			}

			$decoded = json_decode($rawBody, true);
			if (!is_array($decoded) || json_last_error() !== JSON_ERROR_NONE) {
				$this->throwApiError(400, 'Invalid JSON body');
			}

			return $decoded;
		}

		private function extractInstancesList(array $payload)
		{
			if ($this->isSequentialArray($payload)) {
				return $payload;
			}

			if (array_key_exists('instances', $payload)) {
				return $this->normalizeInstancesContainer($payload['instances']);
			}

			if (array_key_exists('timeregister', $payload)) {
				return $this->normalizeInstancesContainer($payload['timeregister']);
			}

			$this->throwApiError(400, 'Invalid instances payload format. Expected array, {instances:[...]}, or {timeregister:[...]}');
		}

		private function normalizeInstancesContainer($value)
		{
			if (is_object($value)) {
				$value = get_object_vars($value);
			}
			if (!is_array($value)) {
				$this->throwApiError(400, 'Invalid instances container. Expected array data');
			}

			if ($this->isSequentialArray($value)) {
				return $value;
			}

			return array($value);
		}

		private function throwApiError($statusCode, $message)
		{
			$code = (int) $statusCode;
			$text = (string) $message;

			$exception = $this->createRestExceptionInstance($code, $text);
			if ($exception instanceof Exception) {
				throw $exception;
			}

			// Final fallback: return controlled JSON error even if Restler exception
			// classes are unavailable/incompatible in the runtime.
			if (!headers_sent()) {
				http_response_code($code);
				header('Content-Type: application/json; charset=utf-8');
			}
			echo json_encode(array(
				'error' => array(
					'code' => $code,
					'message' => $text,
				),
			));
			exit;
		}

		private function createRestExceptionInstance($statusCode, $message)
		{
			$candidateClasses = array(
				'\Luracast\Restler\RestException',
				'RestException',
			);

			foreach ($candidateClasses as $className) {
				if (!class_exists($className)) {
					continue;
				}

				$instance = $this->instantiateRestException($className, $statusCode, $message);
				if ($instance instanceof Exception) {
					return $instance;
				}
			}

			return null;
		}

		private function instantiateRestException($className, $statusCode, $message)
		{
			// Most common signatures across Restler variants.
			try {
				return new $className($statusCode, $message);
			} catch (\Throwable $e) {
				// Try next signature.
			}

			try {
				return new $className($message, $statusCode);
			} catch (\Throwable $e) {
				// Try reflection fallback.
			}

			try {
				$reflection = new ReflectionClass($className);
				$constructor = $reflection->getConstructor();
				if (!$constructor) {
					return $reflection->newInstance();
				}

				$parameters = $constructor->getParameters();
				$parameterCount = count($parameters);
				if ($parameterCount >= 2) {
					$firstParamName = strtolower((string) $parameters[0]->getName());
					if (strpos($firstParamName, 'status') !== false || strpos($firstParamName, 'http') !== false || strpos($firstParamName, 'code') !== false) {
						return $reflection->newInstance($statusCode, $message);
					}

					return $reflection->newInstance($message, $statusCode);
				}

				if ($parameterCount === 1) {
					$firstParamName = strtolower((string) $parameters[0]->getName());
					if (strpos($firstParamName, 'status') !== false || strpos($firstParamName, 'http') !== false || strpos($firstParamName, 'code') !== false) {
						return $reflection->newInstance($statusCode);
					}

					return $reflection->newInstance($message);
				}
			} catch (\Throwable $e) {
				return null;
			}

			return null;
		}

		private function isRestExceptionObject($throwable)
		{
			if (!is_object($throwable)) {
				return false;
			}

			return is_a($throwable, 'Luracast\Restler\RestException') || is_a($throwable, 'RestException');
		}

		private function isSequentialArray(array $array)
		{
			if ($array === array()) {
				return true;
			}

			return array_keys($array) === range(0, count($array) - 1);
		}
	}
}

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
 * \file        class/test.class.php
 * \ingroup     ZSAPISync
 * \brief       This file is a CRUD class file for Test (Create/Read/Update/Delete)
 */

// Put here all includes required by your class file


require_once DOL_DOCUMENT_ROOT . '/core/class/commonobject.class.php';
require_once DOL_DOCUMENT_ROOT . '/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT . '/custom/kreaflow/class/zsapihttpclient.class.php';
require_once DOL_DOCUMENT_ROOT . '/custom/kreaflow/class/zsapiexception.class.php';


/**
 * API Logic for ZoneSoft integration in Dolibarr
 */
class ZSAPISynch extends CommonObject
{
	// Use Dolibarr's global $conf object to access configuration variables
	private static $host;
	private static $version;

	private $interface = null;
	private $options = null;

	/**
	 * Constructor for ZSAPISync.
	 *
	 * Initializes the API connection settings and loads configuration options.
	 *
	 * @param object $db Database connection object.
	 * @param object $conf Configuration object.
	 */
	public function __construct(DoliDB $db, $requireApiConfiguration = true)
	{
		global $conf, $langs;
		$this->db = $db;

		// Define the host and version from the configuration
		self::$host = $conf->global->ZS_API_HOST ?? '';
		self::$version = $conf->global->ZS_API_VERSION ?? '';

		// Store API credentials in options or as class properties
		$this->options = array(
			'app_key' => $conf->global->ZS_APP_KEY ?? '',
			'app_secret' => $conf->global->ZS_APP_SECRET ?? '',
			'client_id' => $conf->global->ZS_CLIENT_ID ?? '',
		);

		if ($requireApiConfiguration) {
			$this->assertApiConfiguration();
		}

		if (!getDolGlobalInt('MAIN_SHOW_TECHNICAL_ID') && isset($this->fields['rowid']) && !empty($this->fields['ref'])) {
			$this->fields['rowid']['visible'] = 0;
		}
		if (!isModEnabled('multicompany') && isset($this->fields['entity'])) {
			$this->fields['entity']['enabled'] = 0;
		}

		// Unset fields that are disabled
		foreach ($this->fields as $key => $val) {
			if (isset($val['enabled']) && empty($val['enabled'])) {
				unset($this->fields[$key]);
			}
		}

		// Translate some data of arrayofkeyval
		if (is_object($langs)) {
			foreach ($this->fields as $key => $val) {
				if (!empty($val['arrayofkeyval']) && is_array($val['arrayofkeyval'])) {
					foreach ($val['arrayofkeyval'] as $key2 => $val2) {
						$this->fields[$key]['arrayofkeyval'][$key2] = $langs->trans($val2);
					}
				}
			}
		}
	}

	/**
	 * Ensure required ZoneSoft API configuration is available.
	 *
	 * @return void
	 * @throws ZSConfigException
	 */
	private function assertApiConfiguration()
	{
		$missingConfig = [];
		if (empty(self::$host)) {
			$missingConfig[] = 'ZS_API_HOST';
		}
		if (empty(self::$version)) {
			$missingConfig[] = 'ZS_API_VERSION';
		}
		if (empty($this->options['app_key'])) {
			$missingConfig[] = 'ZS_APP_KEY';
		}
		if (empty($this->options['app_secret'])) {
			$missingConfig[] = 'ZS_APP_SECRET';
		}
		if (empty($this->options['client_id'])) {
			$missingConfig[] = 'ZS_CLIENT_ID';
		}

		if (!empty($missingConfig)) {
			throw new ZSConfigException("Missing required settings: " . implode(', ', $missingConfig), 0, null, ['missing_config' => $missingConfig]);
		}
	}

	// Public Methods

	/**
	 * Sets the interface for API communication.
	 *
	 * @param string $interface The interface to be set for the API.
	 */
	public function setInterface($interface)
	{
		$this->interface = $interface;
	}

	/**
	 * Retrieves the current API interface.
	 *
	 * @return string|null The currently set interface.
	 */
	public function getInterface()
	{
		return $this->interface;
	}

	/**
	 * Executes an API request.
	 *
	 * Sends a request to the specified ZoneSoft API endpoint.
	 *
	 * @param string $action The API endpoint to call.
	 * @param array $payload The data to send to the API.
	 * @return array The API response
	 * @throws Exception On API errors or missing parameters
	 */
	public function execute($action, $payload)
	{
		// Validate required parameters
		if (empty($this->interface)) {
			throw new ZSValidationException("Interface not set. Call setInterface() first.");
		}
		if (empty($action)) {
			throw new ZSValidationException("Action parameter is required");
		}

		// Construct the full URL for the API request
		$url = self::$host . '/' . self::$version . '/' . $this->interface . '/' . $action;

		try {
			return ZSAPIHttpClient::post($url, $payload, $this->options);
		} catch (Exception $e) {
			throw new ZSAPIException("Failed to execute API call to $action", 0, $e, ['url' => $url, 'action' => $action]);
		}
	}

	/**
	 * Fetcher from ZoneSoft API.
	 *
	 * @return array|false Array of invoice headers or null on failure.
	 */
	public function fetchzs($interface, $argument, $action)
	{
		$normalizedInterface = strtolower(trim((string) $interface));
		$normalizedArgument = strtolower(trim((string) $argument));
		$this->setInterface($normalizedInterface);

		// Listings require a root model object (even empty): {"model":{}}
		$payload = [
			$normalizedArgument => (object) []
		];

		// Execute the API request using the new method
		$response = $this->execute($action, $payload);

		// Log and return the response
		if ($response && isset($response['Response']) && $response['Response']['StatusCode'] == 200) {
			return $response;
		} else {
			dol_syslog("Failed to fetch data from ZoneSoft", LOG_ERR);
			return false;
		}
	}

	/**
	 * Fetch PaymentDoc instances linked to a document.
	 *
	 * @param string $doc Document type.
	 * @param string $serie Document series.
	 * @param int|string $numero Document number.
	 * @param string|null $store Optional store/company identifier.
	 * @return array List of PaymentDoc instances.
	 */
	public function getPaymentDocInstancesFromDocument($doc, $serie, $numero, $store = null)
	{
		return $this->getDocumentInstancesFromDocumentAction(
			'getPaymentDocInstancesFromDocument',
			'paymentdoc',
			$doc,
			$serie,
			$numero,
			$store
		);
	}

	/**
	 * Fetch CounterBalance instances linked to a document.
	 *
	 * @param string $doc Document type.
	 * @param string $serie Document series.
	 * @param int|string $numero Document number.
	 * @param string|null $store Optional store/company identifier.
	 * @return array List of CounterBalance instances.
	 */
	public function getCounterBalancesInstancesFromDocument($doc, $serie, $numero, $store = null)
	{
		return $this->getDocumentInstancesFromDocumentAction(
			'getCounterBalancesInstancesFromDocument',
			'counterbalance',
			$doc,
			$serie,
			$numero,
			$store
		);
	}

	/**
	 * Execute a document-related action and normalize returned content instances.
	 *
	 * @param string $action Action name.
	 * @param string $contentKey Response content key.
	 * @param string $doc Document type.
	 * @param string $serie Document series.
	 * @param int|string $numero Document number.
	 * @param string|null $store Optional store/company identifier.
	 * @return array Normalized list of instances.
	 */
	public function getDocumentInstancesFromDocumentAction($action, $contentKey, $doc, $serie, $numero, $store = null)
	{
		$this->setInterface('documents');

		$payload = [
			'document' => [
				'doc' => (string) $doc,
				'serie' => (string) $serie,
				'numero' => (string) $numero
			]
		];

		$response = ($store !== null && $store !== '')
			? $this->executeForStore($action, $payload, (string) $store)
			: $this->execute($action, $payload);

		if (!is_array($response) || empty($response['Response']) || !is_array($response['Response'])) {
			return [];
		}

		$statusCode = (int) ($response['Response']['StatusCode'] ?? 0);
		if ($statusCode === 204) {
			return [];
		}

		if ($statusCode !== 200) {
			dol_syslog(
				"ZSAPISynch::getDocumentInstancesFromDocumentAction unexpected status code for {$action}: {$statusCode}",
				LOG_WARNING
			);
			return [];
		}

		$content = $response['Response']['Content'] ?? [];
		if (!is_array($content) || !array_key_exists($contentKey, $content)) {
			return [];
		}

		return $this->normalizeDocumentActionInstances($content[$contentKey]);
	}

	/**
	 * Fetch timeregister instances changed since a given timestamp.
	 *
	 * @param string $lastUpdate Last update timestamp in format Y-m-d H:i:s.
	 * @param string|null $store Optional store/company identifier.
	 * @return array Normalized list of timeregister instances.
	 */
	public function getTimeregisterInstancesToSync($lastUpdate, $store = null)
	{
		$lastUpdateString = trim((string) $lastUpdate);
		if ($lastUpdateString === '') {
			throw new ZSValidationException('lastUpdate parameter is required');
		}

		$this->setInterface('timeregisters');

		$payload = array(
			'timeregister' => array(
				'lastupdate' => $lastUpdateString,
			),
		);

		$response = ($store !== null && $store !== '')
			? $this->executeForStore('getInstancesToSync', $payload, (string) $store)
			: $this->execute('getInstancesToSync', $payload);

		return $this->extractTimeregisterInstancesFromResponse($response, 'getInstancesToSync');
	}

	/**
	 * Fetch timeregister instances within a datetime range.
	 *
	 * @param string $startDateTime Start datetime in format Y-m-d H:i:s.
	 * @param string $endDateTime End datetime in format Y-m-d H:i:s.
	 * @param int $limit Maximum number of instances to return.
	 * @param string|null $store Optional store/company identifier.
	 * @return array Normalized list of timeregister instances.
	 */
	public function getTimeregisterInstancesByPeriod($startDateTime, $endDateTime, $limit = 250, $store = null)
	{
		$start = trim((string) $startDateTime);
		$end = trim((string) $endDateTime);
		if ($start === '' || $end === '') {
			throw new ZSValidationException('startDateTime and endDateTime parameters are required');
		}

		$maxRecords = (int) $limit;
		if ($maxRecords <= 0) {
			throw new ZSValidationException('limit parameter must be greater than 0');
		}

		$this->setInterface('timeregisters');

		$condition = sprintf(
			'datahora >= "%s" AND datahora <= "%s"',
			$start,
			$end
		);

		$pageSize = ($maxRecords < 250) ? $maxRecords : 250;
		$offset = 0;
		$allInstances = array();

		while (count($allInstances) < $maxRecords) {
			$payload = array(
				'timeregister' => array(
					'condition' => $condition,
					'limit' => $pageSize,
					'offset' => $offset,
					'order' => 'datahora ASC',
				),
			);

			$response = ($store !== null && $store !== '')
				? $this->executeForStore('getInstances', $payload, (string) $store)
				: $this->execute('getInstances', $payload);

			$batch = $this->extractTimeregisterInstancesFromResponse($response, 'getInstances');
			if (empty($batch)) {
				break;
			}

			$remaining = $maxRecords - count($allInstances);
			if (count($batch) > $remaining) {
				$batch = array_slice($batch, 0, $remaining);
			}

			$allInstances = array_merge($allInstances, $batch);

			if (count($batch) < $pageSize) {
				break;
			}

			$offset += $pageSize;
		}

		return $allInstances;
	}

	/**
	 * Build a punch clock report from timeregister instances.
	 *
	 * @param array $instances Timeregister instances.
	 * @return array Structured punch clock report.
	 */
	public function buildPunchClockReportFromInstances(array $instances)
	{
		$normalized = $this->normalizeTimeregisterInstancesForReports($instances);
		$pairing = $this->pairTimeregisterInstancesForReports($normalized);

		return array(
			'generated_at' => date('Y-m-d H:i:s'),
			'source_instances' => count($instances),
			'normalized_instances' => count($normalized),
			'paired_entries' => count($pairing['pairs']),
			'unmatched_entries' => count($pairing['unmatched_in']) + count($pairing['unmatched_out']),
			'unmatched_in_count' => count($pairing['unmatched_in']),
			'unmatched_out_count' => count($pairing['unmatched_out']),
			'pairs' => $pairing['pairs'],
			'unmatched' => array(
				'in' => $pairing['unmatched_in'],
				'out' => $pairing['unmatched_out'],
			),
		);
	}

	/**
	 * Build a timesheet report from timeregister instances.
	 *
	 * @param array $instances Timeregister instances.
	 * @return array Structured timesheet report.
	 */
	public function buildTimesheetReportFromInstances(array $instances)
	{
		$normalized = $this->normalizeTimeregisterInstancesForReports($instances);
		$pairing = $this->pairTimeregisterInstancesForReports($normalized);

		$employees = array();
		$days = array();
		$totalSeconds = 0;

		foreach ($pairing['pairs'] as $pair) {
			$seconds = (int) $pair['duration_seconds'];
			$totalSeconds += $seconds;

			$employeeKey = $pair['store'] . '|' . $pair['empid'];
			if (!isset($employees[$employeeKey])) {
				$employees[$employeeKey] = array(
					'store' => $pair['store'],
					'empid' => $pair['empid'],
					'employee_name' => $pair['employee_name'],
					'total_seconds' => 0,
					'total_hours' => 0.0,
					'pair_count' => 0,
					'days' => array(),
				);
			}

			$employees[$employeeKey]['total_seconds'] += $seconds;
			$employees[$employeeKey]['pair_count']++;

			$workDate = substr($pair['checkin'], 0, 10);
			if (!isset($employees[$employeeKey]['days'][$workDate])) {
				$employees[$employeeKey]['days'][$workDate] = array(
					'date' => $workDate,
					'total_seconds' => 0,
					'total_hours' => 0.0,
					'pair_count' => 0,
				);
			}

			$employees[$employeeKey]['days'][$workDate]['total_seconds'] += $seconds;
			$employees[$employeeKey]['days'][$workDate]['pair_count']++;

			if (!isset($days[$workDate])) {
				$days[$workDate] = array(
					'date' => $workDate,
					'total_seconds' => 0,
					'total_hours' => 0.0,
					'pair_count' => 0,
				);
			}

			$days[$workDate]['total_seconds'] += $seconds;
			$days[$workDate]['pair_count']++;
		}

		foreach ($employees as &$employeeData) {
			$employeeData['total_hours'] = round($employeeData['total_seconds'] / 3600, 2);
			ksort($employeeData['days']);

			foreach ($employeeData['days'] as &$dayData) {
				$dayData['total_hours'] = round($dayData['total_seconds'] / 3600, 2);
			}
			unset($dayData);

			$employeeData['days'] = array_values($employeeData['days']);
		}
		unset($employeeData);

		ksort($days);
		foreach ($days as &$dayData) {
			$dayData['total_hours'] = round($dayData['total_seconds'] / 3600, 2);
		}
		unset($dayData);

		return array(
			'generated_at' => date('Y-m-d H:i:s'),
			'source_instances' => count($instances),
			'normalized_instances' => count($normalized),
			'total_pairs' => count($pairing['pairs']),
			'total_employees' => count($employees),
			'total_days' => count($days),
			'total_seconds' => $totalSeconds,
			'total_hours' => round($totalSeconds / 3600, 2),
			'employees' => array_values($employees),
			'days' => array_values($days),
			'unmatched' => array(
				'in' => $pairing['unmatched_in'],
				'out' => $pairing['unmatched_out'],
			),
		);
	}

	/**
	 * Fetch timeregister instances by period and build a punch clock report.
	 *
	 * @param string $startDateTime Start datetime in format Y-m-d H:i:s.
	 * @param string $endDateTime End datetime in format Y-m-d H:i:s.
	 * @param string|null $store Optional store/company identifier.
	 * @param int $limit Maximum number of instances to return.
	 * @return array Structured punch clock report.
	 */
	public function getPunchClockReport($startDateTime, $endDateTime, $store = null, $limit = 250)
	{
		$instances = $this->getTimeregisterInstancesByPeriod($startDateTime, $endDateTime, $limit, $store);
		return $this->buildPunchClockReportFromInstances($instances);
	}

	/**
	 * Fetch timeregister instances by period and build a timesheet report.
	 *
	 * @param string $startDateTime Start datetime in format Y-m-d H:i:s.
	 * @param string $endDateTime End datetime in format Y-m-d H:i:s.
	 * @param string|null $store Optional store/company identifier.
	 * @param int $limit Maximum number of instances to return.
	 * @return array Structured timesheet report.
	 */
	public function getTimesheetReport($startDateTime, $endDateTime, $store = null, $limit = 250)
	{
		$instances = $this->getTimeregisterInstancesByPeriod($startDateTime, $endDateTime, $limit, $store);
		return $this->buildTimesheetReportFromInstances($instances);
	}

	/**
	 * Execute API request for a specific store/company using entity-specific credentials.
	 *
	 * @param string $action The API endpoint to call
	 * @param array $payload The data to send to the API
	 * @param string $company The company/store identifier
	 * @return array The API response
	 * @throws Exception On API errors or missing parameters
	 */
	public function executeForStore($action, $payload, $company)
	{
		// Validate required parameters
		if (empty($this->interface)) {
			throw new ZSValidationException("Interface not set. Call setInterface() first.");
		}
		if (empty($action)) {
			throw new ZSValidationException("Action parameter is required");
		}
		if ($company === null || $company === '') {
			throw new ZSValidationException("Company parameter is required");
		}

		// Get company-specific options
		$options = $this->getCompanySpecificOptions($company);

		// Construct the full URL for the API request
		$url = self::$host . '/' . self::$version . '/' . $this->interface . '/' . $action;

		try {
			return ZSAPIHttpClient::post($url, $payload, $options);
		} catch (Exception $e) {
			throw new ZSAPIException("Failed to execute API call for company $company", 0, $e, ['company' => $company, 'action' => $action]);
		}
	}

	/**
	 * Execute API request for product updates with company-specific client_id
	 *
	 * @param string $action The API endpoint to call
	 * @param array $payload The data to send to the API
	 * @param string $company The company identifier
	 * @return array The API response
	 * @throws Exception On API errors or missing parameters
	 */
	public function executeUpdateProducts($action, $payload, $company)
	{
		return $this->executeForStore($action, $payload, $company);
	}

	/**
	 * Execute API request for product creation with company-specific client_id
	 *
	 * @param string $action The API endpoint to call
	 * @param array $payload The data to send to the API
	 * @param string $company The company identifier
	 * @return array The API response
	 * @throws Exception On API errors or missing parameters
	 */
	public function executeCreateProducts($action, $payload, $company)
	{
		return $this->executeForStore($action, $payload, $company);
	}

	/**
	 * Synchronize one Dolibarr product with ZSBMS by product reference.
	 *
	 * This reuses the same runtime flow as the product-card "forceSync" action:
	 * - fetch product from ZSBMS by codigo (Dolibarr ref)
	 * - persist/update local shadow table
	 * - update Dolibarr product from the fetched ZSBMS payload
	 *
	 * @param string $productRef Dolibarr product reference (used as ZSBMS codigo)
	 * @param bool $forceEnableSync When true, force zs_synch = 1 before synchronization
	 * @return array<string,mixed>
	 */
	public function syncProductByRefWithZsbms($productRef, $forceEnableSync = false)
	{
		global $conf;

		$cleanRef = trim((string) $productRef);
		if ($cleanRef === '') {
			$this->error = 'Product reference is required';
			return array(
				'success' => false,
				'product_id' => 0,
				'product_ref' => '',
				'zsbms_codigo' => '',
				'message' => $this->error,
			);
		}

		$product = $this->fetchProductByRefInEntityScope($cleanRef);
		if (!$product || empty($product->id)) {
			$this->error = "Dolibarr product not found for reference '{$cleanRef}' in accessible entity scope";
			dol_syslog(__METHOD__ . ': ' . $this->error, LOG_WARNING);
			return array(
				'success' => false,
				'product_id' => 0,
				'product_ref' => $cleanRef,
				'zsbms_codigo' => '',
				'message' => $this->error,
			);
		}

		if (!empty($forceEnableSync)) {
			$this->forceProductSyncFlag($product);
		}

		require_once DOL_DOCUMENT_ROOT . '/custom/kreaflow/class/zsprodsynch.class.php';
		$zsProductSync = new ZSProductSynch($this->db);

		$previousBypassExists = isset($conf->global->bypass_product_modify_trigger);
		$previousBypassValue = $previousBypassExists ? $conf->global->bypass_product_modify_trigger : null;
		$conf->global->bypass_product_modify_trigger = 1;

		try {
			$externalProductDetails = $zsProductSync->getZoneSoftProductById($product->ref);
			if (!$externalProductDetails || empty($externalProductDetails->product)) {
				$this->error = "Product '{$product->ref}' was not found in ZSBMS";
				dol_syslog(__METHOD__ . ': ' . $this->error, LOG_WARNING);
				return array(
					'success' => false,
					'product_id' => (int) $product->id,
					'product_ref' => (string) $product->ref,
					'zsbms_codigo' => '',
					'message' => $this->error,
				);
			}

			$insertResult = $zsProductSync->insertProductToLocalTable($externalProductDetails);
			if (!$insertResult) {
				$syncError = trim((string) $zsProductSync->error);
				$this->error = "Failed to persist local ZS shadow data for product '{$product->ref}'" . ($syncError !== '' ? (': ' . $syncError) : '');
				dol_syslog(__METHOD__ . ': ' . $this->error, LOG_ERR);
				return array(
					'success' => false,
					'product_id' => (int) $product->id,
					'product_ref' => (string) $product->ref,
					'zsbms_codigo' => isset($externalProductDetails->product->codigo) ? (string) $externalProductDetails->product->codigo : '',
					'message' => $this->error,
				);
			}

			$updateResult = $zsProductSync->updateDolibarrProductFromZoneSoft($externalProductDetails->product);
			if (!$updateResult) {
				$syncError = trim((string) $zsProductSync->error);
				$this->error = "Failed to update Dolibarr product '{$product->ref}' from ZSBMS payload" . ($syncError !== '' ? (': ' . $syncError) : '');
				dol_syslog(__METHOD__ . ': ' . $this->error, LOG_ERR);
				return array(
					'success' => false,
					'product_id' => (int) $product->id,
					'product_ref' => (string) $product->ref,
					'zsbms_codigo' => isset($externalProductDetails->product->codigo) ? (string) $externalProductDetails->product->codigo : '',
					'message' => $this->error,
				);
			}

			$zsbmsCodigo = isset($externalProductDetails->product->codigo) ? (string) $externalProductDetails->product->codigo : '';
			$this->error = '';

			dol_syslog(__METHOD__ . ": Product '{$product->ref}' synchronized successfully from ZSBMS (codigo='{$zsbmsCodigo}')", LOG_INFO);

			return array(
				'success' => true,
				'product_id' => (int) $product->id,
				'product_ref' => (string) $product->ref,
				'zsbms_codigo' => $zsbmsCodigo,
				'message' => 'Product synchronized successfully',
			);
		} catch (Exception $e) {
			$this->error = 'Unexpected synchronization error: ' . $e->getMessage();
			dol_syslog(__METHOD__ . ': ' . $this->error, LOG_ERR);
			return array(
				'success' => false,
				'product_id' => (int) $product->id,
				'product_ref' => (string) $product->ref,
				'zsbms_codigo' => '',
				'message' => $this->error,
			);
		} finally {
			if ($previousBypassExists) {
				$conf->global->bypass_product_modify_trigger = $previousBypassValue;
			} else {
				unset($conf->global->bypass_product_modify_trigger);
			}
		}
	}

	/**
	 * Get company-specific API options
	 *
	 * @param string $company The company identifier
	 * @return array API options with company-specific client_id if available
	 */
	private function getCompanySpecificOptions($company)
	{
		global $db, $conf;

		// Start with default options
		$options = $this->options;
		$currentEntity = (int) ($conf->entity ?? 1);

		// Resolve store -> entity across all entities.
		// Prefer the current entity when there are duplicate ZS_API_STORE values.
		$escapedCompany = $db->escape($company);
		$sql = "SELECT entity FROM " . MAIN_DB_PREFIX . "const ";
		$sql .= "WHERE name = 'ZS_API_STORE' ";
		$sql .= "AND value = '" . $escapedCompany . "' ";
		$sql .= "ORDER BY (entity = " . $currentEntity . ") DESC, entity ASC ";
		$sql .= "LIMIT 1";
		$resql = $db->query($sql);

		if ($resql && $db->num_rows($resql) > 0) {
			$obj = $db->fetch_object($resql);
			$entity = (int) $obj->entity;

			// Use the client_id from the entity owning this store mapping.
			$sql_client = "SELECT value FROM " . MAIN_DB_PREFIX . "const WHERE name = 'ZS_CLIENT_ID' AND entity = " . $entity . " LIMIT 1";
			$resql_client = $db->query($sql_client);

			if ($resql_client && $db->num_rows($resql_client) > 0) {
				$obj_client = $db->fetch_object($resql_client);
				$options['client_id'] = $obj_client->value;
			} else {
				dol_syslog("ZSAPISynch: No ZS_CLIENT_ID found for mapped store $company (entity $entity), using default.", LOG_WARNING);
			}
		} else {
			dol_syslog(
				"ZSAPISynch: Store $company not mapped in any entity. Using default current-entity client_id.",
				LOG_WARNING
			);
		}

		return $options;
	}

	/**
	 * Resolve one product by ref in the current multicompany visibility scope.
	 *
	 * @param string $productRef
	 * @return Product|false
	 */
	private function fetchProductByRefInEntityScope($productRef)
	{
		global $conf;

		require_once DOL_DOCUMENT_ROOT . '/product/class/product.class.php';

		$sql = "SELECT rowid FROM " . MAIN_DB_PREFIX . "product";
		$sql .= " WHERE ref = '" . $this->db->escape((string) $productRef) . "'";

		if (function_exists('getEntity')) {
			$entityList = getEntity('product');
			if (!empty($entityList)) {
				$sql .= " AND entity IN (" . $entityList . ")";
			} else {
				$sql .= " AND entity = " . ((int) ($conf->entity ?? 1));
			}
		} else {
			$sql .= " AND entity = " . ((int) ($conf->entity ?? 1));
		}

		$currentEntity = (int) ($conf->entity ?? 1);
		$sql .= " ORDER BY (entity = " . $currentEntity . ") DESC, rowid DESC";
		$sql .= " " . $this->db->plimit(1);

		$resql = $this->db->query($sql);
		if (!$resql || $this->db->num_rows($resql) <= 0) {
			return false;
		}

		$obj = $this->db->fetch_object($resql);
		if (!$obj || empty($obj->rowid)) {
			return false;
		}

		$product = new Product($this->db);
		if ($product->fetch((int) $obj->rowid) <= 0) {
			return false;
		}

		return $product;
	}

	/**
	 * Force zs_synch flag before an explicit API-triggered sync, when requested.
	 *
	 * @param Product $product
	 * @return void
	 */
	private function forceProductSyncFlag($product)
	{
		if (!is_object($product) || empty($product->id)) {
			return;
		}

		if (
			(empty($product->array_options) || !array_key_exists('options_zs_synch', $product->array_options))
			&& method_exists($product, 'fetch_optionals')
		) {
			$product->fetch_optionals($product->id);
		}

		$product->array_options['options_zs_synch'] = 1;
		if (method_exists($product, 'insertExtraFields')) {
			$product->insertExtraFields();
		}

		$sql = "UPDATE " . MAIN_DB_PREFIX . "product_extrafields";
		$sql .= " SET zs_synch = 1";
		$sql .= " WHERE fk_object = " . ((int) $product->id);
		$this->db->query($sql);

		$sql = "UPDATE " . MAIN_DB_PREFIX . "dolizsynch_zsproduct";
		$sql .= " SET restricted = 0";
		$sql .= " WHERE fk_product = " . ((int) $product->id);
		$this->db->query($sql);
	}

	/**
	 * Normalize action response content into a list of instances.
	 *
	 * @param mixed $instances Raw content value.
	 * @return array
	 */
	private function normalizeDocumentActionInstances($instances)
	{
		if ($instances === null) {
			return [];
		}

		if (is_object($instances)) {
			$instances = get_object_vars($instances);
		}

		if (!is_array($instances)) {
			return [];
		}

		if ($this->isAssociativeArray($instances)) {
			return [$instances];
		}

		return $instances;
	}

	/**
	 * Extract and normalize timeregister instances from API response.
	 *
	 * @param mixed $response API response.
	 * @param string $action Action name for diagnostics.
	 * @return array Normalized list of instances.
	 * @throws ZSAPIException If API status is not successful.
	 */
	private function extractTimeregisterInstancesFromResponse($response, $action)
	{
		if (!is_array($response) || empty($response['Response']) || !is_array($response['Response'])) {
			throw new ZSAPIException("Invalid API response while calling {$action}");
		}

		$statusCode = (int) ($response['Response']['StatusCode'] ?? 0);
		if ($statusCode === 204) {
			return array();
		}

		if ($statusCode !== 200) {
			$statusMessage = (string) ($response['Response']['StatusMessage'] ?? 'Unknown error');
			throw new ZSAPIException(
				"ZoneSoft API returned status {$statusCode} while calling {$action}: {$statusMessage}",
				$statusCode
			);
		}

		$content = $response['Response']['Content'] ?? array();
		if (is_object($content)) {
			$content = get_object_vars($content);
		}
		if (!is_array($content) || !array_key_exists('timeregister', $content)) {
			return array();
		}

		return $this->normalizeDocumentActionInstances($content['timeregister']);
	}

	/**
	 * Normalize raw timeregister rows for report generation.
	 *
	 * @param array $instances Raw instances.
	 * @return array Normalized instances.
	 */
	private function normalizeTimeregisterInstancesForReports(array $instances)
	{
		$normalized = array();

		foreach ($instances as $instance) {
			if (is_object($instance)) {
				$instance = get_object_vars($instance);
			}
			if (!is_array($instance)) {
				continue;
			}

			$empid = $this->readFirstAvailableValue($instance, array('empid', 'empid_zs', 'employeeid', 'employee_id', 'emp'));
			if ($empid === null || $empid === '') {
				continue;
			}

			$sentido = $this->normalizeTimeregisterDirection(
				$this->readFirstAvailableValue($instance, array('sentido', 'direction', 'inout', 'type'))
			);
			if ($sentido === null) {
				continue;
			}

			$datahora = $this->normalizeTimeregisterDateTime(
				$this->readFirstAvailableValue($instance, array('datahora', 'datetime', 'timestamp'))
			);
			if ($datahora === null) {
				continue;
			}

			$timestamp = strtotime($datahora);
			if ($timestamp === false) {
				continue;
			}

			$dataval = $this->readFirstAvailableValue($instance, array('dataval', 'date'));
			if ($dataval === null || $dataval === '') {
				$dataval = substr($datahora, 0, 10);
			}

			$normalized[] = array(
				'store' => (string) ($this->readFirstAvailableValue($instance, array('loja', 'loja_zs', 'store', 'store_id', 'loja_id')) ?? ''),
				'empid' => (string) $empid,
				'employee_name' => (string) ($this->readFirstAvailableValue($instance, array('empnome', 'empname', 'employee_name', 'name', 'fullname', 'full_name')) ?? ''),
				'sentido' => (int) $sentido,
				'datahora' => $datahora,
				'dataval' => (string) $dataval,
				'id' => $this->readFirstAvailableValue($instance, array('id', 'id_zs', 'rowid')),
				'_timestamp' => (int) $timestamp,
			);
		}

		usort($normalized, function ($a, $b) {
			$keyA = $a['store'] . '|' . $a['empid'];
			$keyB = $b['store'] . '|' . $b['empid'];
			if ($keyA !== $keyB) {
				return strcmp($keyA, $keyB);
			}
			if ($a['_timestamp'] === $b['_timestamp']) {
				return strcmp((string) ($a['id'] ?? ''), (string) ($b['id'] ?? ''));
			}

			return ($a['_timestamp'] < $b['_timestamp']) ? -1 : 1;
		});

		return $normalized;
	}

	/**
	 * Pair IN/OUT timeregister rows per store and employee.
	 *
	 * @param array $instances Normalized instances.
	 * @return array Pairing output with pairs and unmatched rows.
	 */
	private function pairTimeregisterInstancesForReports(array $instances)
	{
		$openEntries = array();
		$pairs = array();
		$unmatchedIn = array();
		$unmatchedOut = array();

		foreach ($instances as $instance) {
			$key = $instance['store'] . '|' . $instance['empid'];

			if ((int) $instance['sentido'] === 1) {
				if (!isset($openEntries[$key])) {
					$openEntries[$key] = array();
				}
				$openEntries[$key][] = $instance;
				continue;
			}

			if ((int) $instance['sentido'] === 2) {
				if (!empty($openEntries[$key])) {
					$entry = array_shift($openEntries[$key]);
					$durationSeconds = (int) ($instance['_timestamp'] - $entry['_timestamp']);
					if ($durationSeconds < 0) {
						$durationSeconds = 0;
					}

					$pairs[] = array(
						'store' => $entry['store'],
						'empid' => $entry['empid'],
						'employee_name' => ($entry['employee_name'] !== '' ? $entry['employee_name'] : $instance['employee_name']),
						'dataval' => $entry['dataval'],
						'checkin' => $entry['datahora'],
						'checkout' => $instance['datahora'],
						'duration_seconds' => $durationSeconds,
						'duration_hhmm' => $this->formatDurationAsHourMinute($durationSeconds),
						'entry_id' => $entry['id'],
						'exit_id' => $instance['id'],
					);
				} else {
					$unmatchedOut[] = array(
						'store' => $instance['store'],
						'empid' => $instance['empid'],
						'employee_name' => $instance['employee_name'],
						'dataval' => $instance['dataval'],
						'datahora' => $instance['datahora'],
						'id' => $instance['id'],
					);
				}
			}
		}

		foreach ($openEntries as $entries) {
			foreach ($entries as $entry) {
				$unmatchedIn[] = array(
					'store' => $entry['store'],
					'empid' => $entry['empid'],
					'employee_name' => $entry['employee_name'],
					'dataval' => $entry['dataval'],
					'datahora' => $entry['datahora'],
					'id' => $entry['id'],
				);
			}
		}

		return array(
			'pairs' => $pairs,
			'unmatched_in' => $unmatchedIn,
			'unmatched_out' => $unmatchedOut,
		);
	}

	/**
	 * Read first non-empty value from an instance row.
	 *
	 * @param array $instance Source row.
	 * @param array $keys Candidate keys.
	 * @return mixed|null Value when found, null otherwise.
	 */
	private function readFirstAvailableValue(array $instance, array $keys)
	{
		foreach ($keys as $key) {
			if (array_key_exists($key, $instance) && $instance[$key] !== null && $instance[$key] !== '') {
				return $instance[$key];
			}
		}

		return null;
	}

	/**
	 * Normalize timeregister direction.
	 *
	 * @param mixed $value Direction value.
	 * @return int|null 1 for IN, 2 for OUT, null when unknown.
	 */
	private function normalizeTimeregisterDirection($value)
	{
		if ($value === null || $value === '') {
			return null;
		}

		if (is_numeric($value)) {
			$intValue = (int) $value;
			if ($intValue === 1 || $intValue === 2) {
				return $intValue;
			}
		}

		$normalized = strtolower(trim((string) $value));
		$mapping = array(
			'in' => 1,
			'entrada' => 1,
			'entry' => 1,
			'checkin' => 1,
			'out' => 2,
			'saida' => 2,
			'exit' => 2,
			'checkout' => 2,
		);

		return $mapping[$normalized] ?? null;
	}

	/**
	 * Normalize datetime strings into Y-m-d H:i:s.
	 *
	 * @param mixed $value Datetime value.
	 * @return string|null Normalized datetime or null when invalid.
	 */
	private function normalizeTimeregisterDateTime($value)
	{
		$rawValue = trim((string) $value);
		if ($rawValue === '') {
			return null;
		}

		$formats = array('Y-m-d H:i:s', 'Y-m-d\TH:i:s', 'Y-m-d\TH:i:sP', 'Y-m-d');
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
				if ($format === 'Y-m-d') {
					$dateTime->setTime(0, 0, 0);
				}

				return $dateTime->format('Y-m-d H:i:s');
			}
		}

		$timestamp = strtotime($rawValue);
		if ($timestamp === false) {
			return null;
		}

		return date('Y-m-d H:i:s', $timestamp);
	}

	/**
	 * Format seconds as HH:MM.
	 *
	 * @param int $seconds Duration in seconds.
	 * @return string Duration formatted as HH:MM.
	 */
	private function formatDurationAsHourMinute($seconds)
	{
		$totalSeconds = max(0, (int) $seconds);
		$hours = (int) floor($totalSeconds / 3600);
		$minutes = (int) floor(($totalSeconds % 3600) / 60);

		return sprintf('%02d:%02d', $hours, $minutes);
	}

	/**
	 * Check if an array is associative.
	 *
	 * @param array $array
	 * @return bool
	 */
	private function isAssociativeArray(array $array)
	{
		if ($array === []) {
			return false;
		}

		return array_keys($array) !== range(0, count($array) - 1);
	}
}

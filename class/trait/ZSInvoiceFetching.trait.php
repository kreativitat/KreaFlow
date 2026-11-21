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
 * \file        class/trait/ZSInvoiceFetching.trait.php
 * \ingroup     dolizsynch
 * \brief       Invoice fetching methods from ZoneSoft API for ZSInvoiceSynch
 */

/**
 * Trait ZSInvoiceFetching
 *
 * Handles fetching invoice data from ZoneSoft API:
 * - Fetch invoice headers
 * - Fetch invoice lines
 * - Fetch within date ranges
 * - Handle API responses and errors
 */
trait ZSInvoiceFetching
{
	/**
	 * Return the only ZoneSoft store authorized for customer invoices in the active entity.
	 *
	 * The inter-entity store map is intentionally not an invoice-import ownership map.
	 *
	 * @return int Positive store id, or 0 when configuration is invalid
	 */
	private function getConfiguredInvoiceStoreId()
	{
		global $conf;

		$rawStore = trim((string) ($conf->global->ZS_API_STORE ?? ''));
		if ($rawStore === '' || !ctype_digit($rawStore) || (int) $rawStore <= 0) {
			return 0;
		}

		return (int) $rawStore;
	}

	/**
	 * Test whether one ZoneSoft invoice header belongs to the requested store.
	 *
	 * @param object|array $header ZoneSoft invoice header
	 * @param int $expectedStoreId Expected store id
	 * @return bool
	 */
	private function isInvoiceHeaderForStore($header, $expectedStoreId)
	{
		$headerStore = null;
		if (is_object($header) && isset($header->loja)) {
			$headerStore = $header->loja;
		} elseif (is_array($header) && array_key_exists('loja', $header)) {
			$headerStore = $header['loja'];
		}

		$rawHeaderStore = trim((string) $headerStore);
		return $expectedStoreId > 0
			&& $rawHeaderStore !== ''
			&& ctype_digit($rawHeaderStore)
			&& (int) $rawHeaderStore === (int) $expectedStoreId;
	}

	/**
	 * Remove foreign or unscoped invoice headers from an API response.
	 *
	 * @param array<int,object|array> $headers Raw headers
	 * @param int $expectedStoreId Expected store id
	 * @param string $operation Caller used for logging
	 * @return array<int,object|array>
	 */
	private function filterInvoiceHeadersByStore(array $headers, $expectedStoreId, $operation = '')
	{
		$allowedHeaders = array();
		$rejectedCount = 0;

		foreach ($headers as $header) {
			if ($this->isInvoiceHeaderForStore($header, $expectedStoreId)) {
				$allowedHeaders[] = $header;
				continue;
			}

			$rejectedCount++;
		}

		if ($rejectedCount > 0) {
			$this->logOperationWarning(
				($operation !== '' ? $operation : __METHOD__),
				'Rejected invoice headers outside the active entity store scope',
				array(
					'expected_store' => (int) $expectedStoreId,
					'rejected_count' => $rejectedCount,
				)
			);
		}

		return $allowedHeaders;
	}

	/**
	 * Return the stable ZoneSoft document-header order used for offset pagination.
	 *
	 * Ordering only by the business date leaves every document from that date tied.
	 * ZoneSoft may then move tied rows between offset pages, producing duplicates and
	 * omissions. The timestamp and fiscal identity fields make the order deterministic.
	 *
	 * @return string
	 */
	private function getZoneSoftInvoiceHeaderOrder()
	{
		return 'datahora DESC, doc DESC, serie DESC, numero DESC';
	}

	/**
	 * Build the fiscal identity key used to deduplicate overlapping API pages.
	 *
	 * @param object|array $header ZoneSoft document header
	 * @return string
	 */
	private function getZoneSoftInvoiceHeaderKey($header)
	{
		$readField = static function ($source, $field) {
			if (is_object($source) && isset($source->{$field})) {
				return trim((string) $source->{$field});
			}
			if (is_array($source) && array_key_exists($field, $source)) {
				return trim((string) $source[$field]);
			}

			return '';
		};

		$doc = $readField($header, 'doc');
		$serie = $readField($header, 'serie');
		$numero = $readField($header, 'numero');
		$data = $readField($header, 'data');

		if ($doc === '' && $serie === '' && $numero === '' && $data === '') {
			return '';
		}

		return $doc . '|' . $serie . '|' . $numero . '|' . $data;
	}

	/**
	 * Fetch routine imports with an optional recent calendar-day overlap.
	 * A date window catches late uploads without scanning the full fiscal history.
	 */
	public function fetchInvoiceHeadersForImport()
	{
		global $conf;

		$lookback = trim((string) ($conf->global->ZS_INVOICE_IMPORT_LOOKBACK_DAYS ?? '0'));
		if (!ctype_digit($lookback) || (int) $lookback > 31) {
			$this->logOperationError(__METHOD__, 'ZS_INVOICE_IMPORT_LOOKBACK_DAYS must be an integer from 0 to 31');
			return false;
		}
		if ((int) $lookback === 0) {
			return $this->fetchInvoiceHeaders(null, 0, !empty($conf->global->ZS_INVOICE_IMPORT_PAGINATE_FULLY));
		}

		$today = new DateTimeImmutable('today', $this->getSynchronizationTimezone());
		return $this->fetchInvoiceHeadersWithinDateRange(
			$today->modify('-' . (int) $lookback . ' days')->format('Y-m-d'),
			$today->format('Y-m-d')
		);
	}

	/**
	 * Fetch invoice headers from ZoneSoft API
	 *
	 * @param int|null $store Store ID (uses global config if null)
	 * @param int $offset Pagination offset
	 * @param bool $paginateFully When true, iterates all pages using limit/offset
	 * @return object|false Invoice headers object or false on failure
	 */
	public function fetchInvoiceHeaders($store = null, $offset = 0, $paginateFully = false)
	{
		global $conf;

		$operation = __METHOD__;

		// Set the interface for fetching documents (invoices)
		$this->zoneSoftApi->setInterface('documents');

		$configuredStoreId = $this->getConfiguredInvoiceStoreId();
		if ($configuredStoreId <= 0) {
			$this->logOperationError($operation, 'ZS_API_STORE must identify one positive numeric store for the active entity');
			return false;
		}

		// An explicit store remains supported only when it is the active entity's store.
		$store = $store ?: $configuredStoreId;
		$store = trim((string) $store);
		$storeId = (ctype_digit($store) ? (int) $store : 0);
		if ($storeId <= 0 || $storeId !== $configuredStoreId) {
			$this->logOperationError($operation, 'Requested invoice store is outside the active entity scope', [
				'store_raw' => $store,
				'configured_store' => $configuredStoreId,
				'config_key' => 'ZS_API_STORE'
			]);
			return false;
		}
		$useStoreScopedRequest = ($storeId > 0 && method_exists($this->zoneSoftApi, 'executeForStore'));

		$offset = max(0, (int) $offset);
		$limit = isset($conf->global->ZS_GET_NUMBER_OF_INVOICES_LIMIT) ? (int) $conf->global->ZS_GET_NUMBER_OF_INVOICES_LIMIT : 250;
		if ($limit < 1 || $limit > 250) {
			$limit = 250;
		}

		$this->logDebug($operation, "Fetching invoice headers", [
			'store' => $store,
			'store_id' => $storeId,
			'use_store_scoped_request' => $useStoreScopedRequest,
			'offset' => $offset,
			'limit' => $limit,
			'paginate_fully' => !empty($paginateFully)
		]);

		$allHeaders = [];
		$currentOffset = $offset;
		$pageCount = 0;
		$maxPages = isset($conf->global->ZS_GET_NUMBER_OF_INVOICES_MAX_PAGES) ? (int) $conf->global->ZS_GET_NUMBER_OF_INVOICES_MAX_PAGES : 200;
		$maxPages = max(1, min(2000, $maxPages));
		$lastPageFingerprint = '';
		$consecutiveDuplicatePageCount = 0;
		$seenHeaderKeys = array();

		do {
			$payload = [
				"document" => [
					"order" => $this->getZoneSoftInvoiceHeaderOrder(),
					"limit" => $limit,
					"offset" => $currentOffset,
				]
			];
			if ($storeId > 0) {
				$payload['document']['loja'] = $storeId;
			}

			try {
				$rawResponse = $useStoreScopedRequest
					? $this->zoneSoftApi->executeForStore('getDocumentsHeaders', $payload, (string) $storeId)
					: $this->zoneSoftApi->execute('getDocumentsHeaders', $payload);
			} catch (Exception $e) {
				$this->logOperationError($operation, "Exception while fetching invoice headers", [
					'store' => $store,
					'store_id' => $storeId,
					'offset' => $currentOffset,
					'limit' => $limit,
					'paginate_fully' => !empty($paginateFully),
					'exception' => $e->getMessage()
				]);
				return false;
			}

			$response = json_decode(json_encode($rawResponse));
			$statusCode = isset($response->Response->StatusCode) ? (int) $response->Response->StatusCode : 0;

			if ($statusCode !== 200 && $statusCode !== 204) {
				$this->logOperationError($operation, "Failed to fetch invoice headers", [
					'store' => $store,
					'offset' => $currentOffset,
					'status_code' => ($statusCode > 0 ? $statusCode : 'unknown')
				]);
				return false;
			}

			$headers = [];
			if ($statusCode === 200 && isset($response->Response->Content->document) && is_array($response->Response->Content->document)) {
				$headers = $response->Response->Content->document;
			}
			$rawHeaders = $headers;
			$headers = $this->filterExcludedZsbmsDocumentHeaders($headers, $operation);
			$headers = $this->filterInvoiceHeadersByStore($headers, $storeId, $operation);

			$pageCount++;
			$pageCountItems = count($rawHeaders);
			$pageAllowedItems = count($headers);

			$this->logDebug($operation, "Fetched invoice headers page", [
				'store' => $store,
				'store_id' => $storeId,
				'offset' => $currentOffset,
				'limit' => $limit,
				'page' => $pageCount,
				'count' => $pageAllowedItems,
				'raw_count' => $pageCountItems,
				'status_code' => $statusCode
			]);

			if ($pageCountItems > 0) {
				$firstKey = $this->getZoneSoftInvoiceHeaderKey($rawHeaders[0]);
				$lastKey = $this->getZoneSoftInvoiceHeaderKey($rawHeaders[$pageCountItems - 1]);
				$pageFingerprint = $firstKey . '||' . $lastKey . '||' . $pageCountItems;
				if ($paginateFully && $pageFingerprint === $lastPageFingerprint) {
					$consecutiveDuplicatePageCount++;
					if ($consecutiveDuplicatePageCount >= 2) {
						$this->logOperationWarning($operation, "Stopping full pagination due repeated page fingerprint", [
							'store' => $store,
							'offset' => $currentOffset,
							'page' => $pageCount,
							'duplicate_pages' => $consecutiveDuplicatePageCount,
							'fingerprint' => $pageFingerprint
						]);
						break;
					}
				} else {
					$lastPageFingerprint = $pageFingerprint;
					$consecutiveDuplicatePageCount = 0;
					if ($pageAllowedItems > 0) {
						foreach ($headers as $header) {
							$headerKey = $this->getZoneSoftInvoiceHeaderKey($header);
							if ($headerKey !== '' && isset($seenHeaderKeys[$headerKey])) {
								continue;
							}
							if ($headerKey !== '') {
								$seenHeaderKeys[$headerKey] = true;
							}
							$allHeaders[] = $header;
						}
					}
				}
			}

			if ($paginateFully && $pageCount >= $maxPages) {
				$this->logOperationWarning($operation, "Stopping full pagination due max pages safety cap", [
					'store' => $store,
					'max_pages' => $maxPages,
					'last_offset' => $currentOffset,
					'limit' => $limit
				]);
				break;
			}

			if (!$paginateFully || $pageCountItems < $limit || $statusCode === 204) {
				break;
			}

			$currentOffset += $limit;
		} while (true);

		// Reverse once at the end to keep global chronological order (oldest -> newest).
		if (!empty($allHeaders)) {
			$allHeaders = array_reverse($allHeaders);
		}

		$this->logDebug($operation, "Successfully fetched invoice headers", [
			'store' => $store,
			'store_id' => $storeId,
			'count' => count($allHeaders),
			'offset' => $offset,
			'pages_fetched' => $pageCount,
			'paginate_fully' => !empty($paginateFully)
		]);

		return (object) [
			'document' => $allHeaders
		];
	}

	/**
	 * Fetch invoice headers within a date range
	 *
	 * @param string $initialDate Start date (Y-m-d)
	 * @param string $endDate End date (Y-m-d)
	 * @param int|null $store Store ID
	 * @return object|false Invoice headers or false on failure
	 */
	public function fetchInvoiceHeadersWithinDateRange($initialDate, $endDate, $store = null)
	{
		global $conf;

		$operation = __METHOD__;

		$this->zoneSoftApi->setInterface('documents');
		$configuredStoreId = $this->getConfiguredInvoiceStoreId();
		if ($configuredStoreId <= 0) {
			$this->logOperationError($operation, 'ZS_API_STORE must identify one positive numeric store for the active entity');
			return false;
		}

		$store = $store ?: $configuredStoreId;
		$store = trim((string) $store);
		$storeId = (ctype_digit($store) ? (int) $store : 0);
		if ($storeId <= 0 || $storeId !== $configuredStoreId) {
			$this->logOperationError($operation, 'Requested invoice store is outside the active entity scope', [
				'store_raw' => $store,
				'configured_store' => $configuredStoreId,
				'config_key' => 'ZS_API_STORE'
			]);
			return false;
		}
		$useStoreScopedRequest = ($storeId > 0 && method_exists($this->zoneSoftApi, 'executeForStore'));

		$this->logDebug($operation, "Fetching invoice headers by date range", [
			'store' => $store,
			'store_id' => $storeId,
			'use_store_scoped_request' => $useStoreScopedRequest,
			'initial_date' => $initialDate,
			'end_date' => $endDate
		]);

		// Use pagination with manual date filtering (API doesn't properly support date range)
		$offset = 0;
		$limit = 250;
		$allHeaders = [];
		$startTimestamp = strtotime($initialDate . ' 00:00:00');
		$endTimestamp = strtotime($endDate . ' 23:59:59');
		$hasMorePages = true;
		$lastPageHadMatch = true;
		$seenHeaderKeys = array();

		while ($hasMorePages && $lastPageHadMatch) {
			$payload = [
				"document" => [
					"order" => $this->getZoneSoftInvoiceHeaderOrder(),
					"limit" => $limit,
					"offset" => $offset,
				]
			];
			if ($storeId > 0) {
				$payload['document']['loja'] = $storeId;
			}

			$this->logDebug($operation, "Fetching page", [
				'offset' => $offset,
				'limit' => $limit
			]);

			try {
				$rawResponse = $useStoreScopedRequest
					? $this->zoneSoftApi->executeForStore('getDocumentsHeaders', $payload, (string) $storeId)
					: $this->zoneSoftApi->execute('getDocumentsHeaders', $payload);
				$response = json_decode(json_encode($rawResponse));

				if ($response && isset($response->Response) && $response->Response->StatusCode == 200) {
					$headers = $response->Response->Content->document ?? [];

					if (empty($headers)) {
						// No more headers to fetch
						$this->logDebug($operation, "No more headers returned by API");
						break;
					}

					$this->logDebug($operation, "Processing page headers", [
						'headers_count' => count($headers)
					]);

					$rawHeaders = $headers;
					$rawHeaderCount = count($rawHeaders);
					$headers = $this->filterExcludedZsbmsDocumentHeaders($headers, $operation);
					$headers = $this->filterInvoiceHeadersByStore($headers, $storeId, $operation);

					// Track if we found any matches in this page
					$currentPageMatches = 0;
					// A page containing only foreign-store rows cannot prove that the
					// requested store has moved before the target date range.
					$allBeforeRange = !empty($headers);

					// Filter raw headers by date range so excluded document types do not affect pagination stop conditions.
					foreach ($rawHeaders as $header) {
						if (!$this->isInvoiceHeaderForStore($header, $storeId)) {
							continue;
						}

						$headerTimestamp = strtotime($header->data);

						// If header is within range, include it
						if ($headerTimestamp >= $startTimestamp && $headerTimestamp <= $endTimestamp) {
							if (!$this->isExcludedZsbmsDocumentType($header->doc ?? '')) {
								$headerKey = $this->getZoneSoftInvoiceHeaderKey($header);
								if ($headerKey === '' || !isset($seenHeaderKeys[$headerKey])) {
									if ($headerKey !== '') {
										$seenHeaderKeys[$headerKey] = true;
									}
									$allHeaders[] = $header;
									$currentPageMatches++;
								}
							}
							$allBeforeRange = false;
						}

						// If header is after end date (newer), keep looking
						if ($headerTimestamp > $endTimestamp) {
							$allBeforeRange = false;
						}
					}

					$this->logDebug($operation, "Page processing results", [
						'matches_found' => $currentPageMatches,
						'total_headers_so_far' => count($allHeaders),
						'all_before_range' => $allBeforeRange,
						'raw_headers_count' => $rawHeaderCount,
						'allowed_headers_count' => count($headers)
					]);

					// If all headers in this page are before the start date (older than range),
					// we can stop - no need to fetch more pages
					if ($allBeforeRange) {
						$this->logDebug($operation, "All headers in page are before start date - stopping pagination");
						break;
					}

					// Move to next page
					$offset += $limit;

					// If we got fewer headers than the limit, we've reached the end of all data
					if ($rawHeaderCount < $limit) {
						$this->logDebug($operation, "Received fewer headers than limit - reached end of data");
						$hasMorePages = false;
					}

				} else {
					$statusCode = isset($response->Response->StatusCode) ? $response->Response->StatusCode : 'unknown';

					$this->logOperationError($operation, "Failed to fetch headers page", [
						'store' => $store,
						'offset' => $offset,
						'status_code' => $statusCode
					]);

					return false;
				}
			} catch (Exception $e) {
				$this->logOperationError($operation, "Exception while fetching headers page", [
					'store' => $store,
					'offset' => $offset,
					'exception' => $e->getMessage()
				]);

				return false;
			}
		}

		$this->logDebug($operation, "Successfully fetched headers by date range", [
			'store' => $store,
			'count' => count($allHeaders),
			'date_range' => "{$initialDate} to {$endDate}",
			'pages_fetched' => ceil($offset / $limit)
		]);

		// Reverse the array so invoices are processed from oldest to newest
		// This ensures Dolibarr invoice creation order matches chronological order
		$allHeaders = array_reverse($allHeaders);

		// Return in the same format as the API response
		return (object)[
			'document' => $allHeaders
		];
	}

	/**
	 * Fetch complete invoice documents within a date range.
	 *
	 * ZoneSoft does not reliably apply date filters to this endpoint, so the
	 * method walks deterministic pages and filters locally. Store ownership is
	 * enforced on every response row and overlapping pages are deduplicated.
	 *
	 * @param string $initialDate Start date (Y-m-d)
	 * @param string $endDate End date (Y-m-d)
	 * @param int|null $store Store ID
	 * @return object|false Invoice documents or false on failure
	 */
	public function fetchInvoiceDocumentsWithinDateRange($initialDate, $endDate, $store = null)
	{
		global $conf;

		$operation = __METHOD__;
		$this->zoneSoftApi->setInterface('documents');

		$configuredStoreId = $this->getConfiguredInvoiceStoreId();
		$store = $store ?: $configuredStoreId;
		$rawStore = trim((string) $store);
		$storeId = ctype_digit($rawStore) ? (int) $rawStore : 0;
		if ($configuredStoreId <= 0 || $storeId !== $configuredStoreId) {
			$this->logOperationError($operation, 'Requested invoice store is outside the active entity scope', array(
				'store_raw' => $rawStore,
				'configured_store' => $configuredStoreId,
			));
			return false;
		}

		$startTimestamp = strtotime($initialDate . ' 00:00:00');
		$endTimestamp = strtotime($endDate . ' 23:59:59');
		if ($startTimestamp === false || $endTimestamp === false || $startTimestamp > $endTimestamp) {
			$this->logOperationError($operation, 'Invalid invoice document date range', array(
				'initial_date' => $initialDate,
				'end_date' => $endDate,
			));
			return false;
		}

		$limit = isset($conf->global->ZS_GET_NUMBER_OF_INVOICES_LIMIT) ? (int) $conf->global->ZS_GET_NUMBER_OF_INVOICES_LIMIT : 250;
		if ($limit < 1 || $limit > 250) {
			$limit = 250;
		}
		$maxPages = isset($conf->global->ZS_GET_NUMBER_OF_INVOICES_MAX_PAGES) ? (int) $conf->global->ZS_GET_NUMBER_OF_INVOICES_MAX_PAGES : 200;
		$maxPages = max(1, min(2000, $maxPages));

		$allDocuments = array();
		$seenDocumentKeys = array();
		$lastPageFingerprint = '';
		$consecutiveDuplicatePageCount = 0;
		$offset = 0;
		$pageCount = 0;

		do {
			$payload = array(
				'document' => array(
					'order' => $this->getZoneSoftInvoiceHeaderOrder(),
					'limit' => $limit,
					'offset' => $offset,
					'loja' => $storeId,
				),
			);

			try {
				$rawResponse = $this->zoneSoftApi->executeForStore('getInstances', $payload, (string) $storeId);
			} catch (Exception $e) {
				$this->logOperationError($operation, 'Exception while fetching invoice documents', array(
					'store' => $storeId,
					'offset' => $offset,
					'exception' => $e->getMessage(),
				));
				return false;
			}

			$response = json_decode(json_encode($rawResponse));
			$statusCode = isset($response->Response->StatusCode) ? (int) $response->Response->StatusCode : 0;
			if ($statusCode !== 200 && $statusCode !== 204) {
				$this->logOperationError($operation, 'Failed to fetch invoice documents', array(
					'store' => $storeId,
					'offset' => $offset,
					'status_code' => ($statusCode > 0 ? $statusCode : 'unknown'),
				));
				return false;
			}

			$rawDocuments = array();
			if ($statusCode === 200 && isset($response->Response->Content->document) && is_array($response->Response->Content->document)) {
				$rawDocuments = $response->Response->Content->document;
			}
			$rawCount = count($rawDocuments);
			$pageCount++;

			if ($rawCount > 0) {
				$firstKey = $this->getZoneSoftInvoiceHeaderKey($rawDocuments[0]);
				$lastKey = $this->getZoneSoftInvoiceHeaderKey($rawDocuments[$rawCount - 1]);
				$pageFingerprint = $firstKey . '||' . $lastKey . '||' . $rawCount;
				if ($pageFingerprint === $lastPageFingerprint) {
					$consecutiveDuplicatePageCount++;
					if ($consecutiveDuplicatePageCount >= 2) {
						$this->logOperationError($operation, 'Invoice document pagination repeated the same page', array(
							'store' => $storeId,
							'offset' => $offset,
						));
						return false;
					}
				} else {
					$lastPageFingerprint = $pageFingerprint;
					$consecutiveDuplicatePageCount = 0;
				}
			}

			$documents = $this->filterExcludedZsbmsDocumentHeaders($rawDocuments, $operation);
			$documents = $this->filterInvoiceHeadersByStore($documents, $storeId, $operation);
			$storeRowsSeen = 0;
			$allStoreRowsBeforeRange = true;

			foreach ($rawDocuments as $rawDocument) {
				if (!$this->isInvoiceHeaderForStore($rawDocument, $storeId)) {
					continue;
				}
				$storeRowsSeen++;
				$rawDate = is_object($rawDocument) ? ($rawDocument->data ?? '') : ($rawDocument['data'] ?? '');
				$rawTimestamp = strtotime((string) $rawDate);
				if ($rawTimestamp === false || $rawTimestamp >= $startTimestamp) {
					$allStoreRowsBeforeRange = false;
				}
			}

			foreach ($documents as $document) {
				$documentDate = is_object($document) ? ($document->data ?? '') : ($document['data'] ?? '');
				$documentTimestamp = strtotime((string) $documentDate);
				if ($documentTimestamp === false || $documentTimestamp < $startTimestamp || $documentTimestamp > $endTimestamp) {
					continue;
				}

				$documentKey = $this->getZoneSoftInvoiceHeaderKey($document);
				if ($documentKey !== '' && isset($seenDocumentKeys[$documentKey])) {
					continue;
				}
				if ($documentKey !== '') {
					$seenDocumentKeys[$documentKey] = true;
				}
				$allDocuments[] = $document;
			}

			if ($storeRowsSeen > 0 && $allStoreRowsBeforeRange) {
				break;
			}
			if ($statusCode === 204 || $rawCount < $limit) {
				break;
			}
			if ($pageCount >= $maxPages) {
				$this->logOperationError($operation, 'Invoice document pagination exceeded the max pages safety cap', array(
					'store' => $storeId,
					'max_pages' => $maxPages,
				));
				return false;
			}

			$offset += $limit;
		} while (true);

		if (!empty($allDocuments)) {
			$allDocuments = array_reverse($allDocuments);
		}

		$this->logDebug($operation, 'Successfully fetched invoice documents by date range', array(
			'store' => $storeId,
			'count' => count($allDocuments),
			'initial_date' => $initialDate,
			'end_date' => $endDate,
			'pages_fetched' => $pageCount,
		));

		return (object) array('document' => $allDocuments);
	}

	/**
	 * Fetch invoice lines from ZoneSoft API
	 *
	 * This method is already enhanced with logging in the main class
	 * It's included here for completeness of the fetching trait
	 *
	 * @param string $doc Document type
	 * @param string $serie Document series
	 * @param int $numero Document number
	 * @param string $data Document date
	 * @param int|null $store Store ID
	 * @return array Array with 'data' or 'error' key
	 */
	// fetchInvoiceLines is already enhanced in main class
}

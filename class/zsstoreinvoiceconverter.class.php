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
 * \file        class/zsstoreinvoiceconverter.class.php
 * \ingroup     dolizsynch
 * \brief       Class to convert customer invoices from the configured seller entity
 *              to supplier invoices in other entities (buyers). Also embeds store/entity
 *              mapping helpers previously provided by StoreEntityInvoiceConverter.
 *
 * This class replicates the functionality of the Google Apps Script that:
 * 1. Fetches invoices from Zone Soft for the configured seller entity
 * 2. Maps products from seller to buyer using product equivalence table
 * 3. Creates supplier invoices in buyer entities
 * 4. Handles rounding adjustments
 */

require_once DOL_DOCUMENT_ROOT . '/core/class/commonobject.class.php';
require_once DOL_DOCUMENT_ROOT . '/core/lib/date.lib.php';
require_once DOL_DOCUMENT_ROOT . '/societe/class/societe.class.php';
require_once DOL_DOCUMENT_ROOT . '/fourn/class/fournisseur.facture.class.php';
require_once DOL_DOCUMENT_ROOT . '/custom/kreaflow/class/zsinvoicesynch.class.php';
require_once DOL_DOCUMENT_ROOT . '/custom/kreaflow/class/zsinvoicetaxcalculator.class.php';
require_once DOL_DOCUMENT_ROOT . '/custom/kreaflow/class/dolizsynchstockreversal.class.php';
require_once DOL_DOCUMENT_ROOT . '/custom/kreaflow/class/zspropertystockservice.class.php';
require_once DOL_DOCUMENT_ROOT . '/custom/kreaflow/lib/dolizsynch.lib.php';
require_once DOL_DOCUMENT_ROOT . '/product/class/product.class.php';

/**
 * Class for ZSStoreInvoiceConverter
 * Converts customer invoices from seller entity to supplier invoices in buyer entities
 */
class ZSStoreInvoiceConverter extends CommonObject
{
	/**
	 * @var string Module name
	 */
	public $module = 'dolizsynch';

	/**
	 * @var string Element name
	 */
	public $element = 'zsstoreinvoiceconverter';

	/**
	 * @var DoliDB Database handler
	 */
	public $db;

	/**
	 * @var ZsInvoiceSynch ZoneSoft Invoice Sync handler
	 */
	public $zsInvoiceSync;

	/**
	 * @var array Product equivalence cache
	 */
	private $productEquivalenceCache = array();

	/**
	 * @var array Loaded store mapping (from DB)
	 */
	private $storeData = array();

	/**
	 * @var int Configured rounding product id
	 */
	private $roundingProductId = 0;

	/**
	 * @var int Configured payment term id
	 */
	private $paymentTermId = 0;

	/**
	 * @var array<int,int> Cache of default warehouse IDs by entity for supplier validation
	 */
	private $validationWarehouseByEntity = array();

	/**
	 * Load one entity's complete Dolibarr configuration.
	 *
	 * Changing only $conf->entity leaves entity-specific constants from the
	 * previous company in memory and is unsafe for multicompany operations.
	 *
	 * @param int $entity Entity to activate
	 * @return bool True on success
	 */
	private function activateEntityConfiguration($entity)
	{
		global $conf;

		$entity = (int) $entity;
		if ($entity <= 0 || $conf->setEntityValues($this->db, $entity) < 0) {
			$this->error = 'Unable to load Dolibarr configuration for entity ' . $entity;
			dol_syslog(__METHOD__ . ' - ' . $this->error, LOG_ERR);
			return false;
		}

		return true;
	}

	/**
	 * Constructor
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct(DoliDB $db)
	{
		$this->db = $db;
		$this->zsInvoiceSync = new ZsInvoiceSynch($db);
		$this->roundingProductId = (int) getDolGlobalInt('ZS_ROUNDING_PRODUCT_ID', 0);
		$this->paymentTermId = (int) getDolGlobalInt('ZS_PAYMENT_TERM_ID', 0);
		$this->loadStoreData();
	}

	/**
	 * Return Dolibarr entity id from a given ZoneSoft store identifier.
	 *
	 * @param string $zsbmsStore
	 * @return int|null
	 */
	private function getEntityFromZsbmsStore($zsbmsStore)
	{
		foreach ($this->storeData as $store) {
			if ($store['zsbms_store'] === (string) $zsbmsStore || $store['zsbmsStore'] === (string) $zsbmsStore) {
				return (int) ($store['store_dolibarr'] ?? $store['storeDolibarr']);
			}
		}
		return null;
	}

	/**
	 * Load store mapping from DB; fallback to defaults if empty/missing.
	 */
	private function loadStoreData()
	{
		global $conf;

		$table = MAIN_DB_PREFIX . 'dolizsynch_storemap';
		$sql = "SELECT store, zsbms_store, store_dolibarr, store_nif";
		$sql .= " FROM " . $table;
		$sql .= " WHERE entity IN (0, " . ((int) $conf->entity) . ")";
		$sql .= " ORDER BY rowid";

		$resql = $this->db->query($sql);
		if ($resql) {
			while ($obj = $this->db->fetch_object($resql)) {
				$this->storeData[] = array(
					'store' => $obj->store,
					'zsbms_store' => $obj->zsbms_store,
					'store_dolibarr' => (int) $obj->store_dolibarr,
					'store_nif' => $obj->store_nif,
				);
			}
			$this->db->free($resql);
		} else {
			dol_syslog(__METHOD__ . ' unable to load store map: ' . $this->db->lasterror(), LOG_WARNING);
		}

		// No defaults here; setup seeds table initially.
	}

	/**
	 * Main control function - Entry point for invoice processing
	 * Equivalent to Google Apps Script controlFunction()
	 * Requires multicompany module
	 *
	 * @param string|null $targetDate Target date (Y-m-d format), null for today
	 * @return int >0 if OK, <0 if error
	 */
	public function processInterEntityInvoices($targetDate = null)
	{
		global $conf;


		// Check if multicompany is enabled
		if (!isModEnabled('multicompany')) {
			$this->error = "Multicompany module is required for inter-entity invoice conversion";
			dol_syslog(__METHOD__ . " - " . $this->error, LOG_ERR);
			return -1;
		}

		// Check if this feature is enabled for current entity
		if (!$this->isEnabledForCurrentEntity()) {
			$this->error = "Inter-entity invoice conversion is not enabled for this entity";
			dol_syslog(__METHOD__ . " - " . $this->error, LOG_WARNING);
			return -1;
		}

		// Determine target date
		if (empty($targetDate)) {
			$targetDate = date('Y-m-d');
		}

		dol_syslog(__METHOD__ . " - Processing invoices for date: $targetDate", LOG_INFO);

		// Get seller entity configuration
		$sellerEntity = $this->getSellerEntity();
		if ($sellerEntity < 0) {
			$this->error = "No seller entity configured";
			return -1;
		}

		// Get invoices for the day from ZoneSoft
		$invoices = $this->getDayDocumentInvoices($sellerEntity, $targetDate);
		if ($invoices === false) {
			$this->error = "Failed to retrieve invoices from ZoneSoft";
			return -1;
		}

		if (empty($invoices)) {
			dol_syslog(__METHOD__ . " - No invoices found for date: $targetDate", LOG_INFO);
			return 0;
		}

		dol_syslog(__METHOD__ . " - Found " . count($invoices) . " invoices to process", LOG_INFO);

		// Process each invoice
		$successCount = 0;
		$skippedCount = 0;
		$errorCount = 0;
		$errorDetails = array();

		foreach ($invoices as $invoice) {
			$this->error = '';
			$result = $this->processInvoice($invoice);
			if ($result > 0) {
				$successCount++;
			} elseif ($result === 0) {
				$skippedCount++;
			} else {
				$errorCount++;
				$invoice = is_object($invoice) ? (array) $invoice : $invoice;
				$invoiceNumber = trim((string) ($invoice['doc'] ?? '') . ' ' . (string) ($invoice['serie'] ?? '') . '/' . (string) ($invoice['numero'] ?? ''));
				$errorDetails[] = $invoiceNumber . ': ' . ($this->error ?: 'unknown processing error');
			}
		}

		$summary = "Inter-entity conversion for $targetDate: $successCount processed/already complete, $skippedCount skipped, $errorCount errors";
		dol_syslog(__METHOD__ . " - " . $summary, $errorCount > 0 ? LOG_ERR : LOG_INFO);

		if ($errorCount > 0) {
			$this->error = $summary . '. ' . implode('; ', array_values(array_unique($errorDetails)));
			$this->output = $this->error;
			return -1;
		}

		$this->output = $summary;
		return 0;
	}

	/**
	 * Backward-compatible Dolibarr cron entrypoint.
	 *
	 * Cron rows created before the 1.28.x rollback may still reference this
	 * method. Keep the adapter side-effect free and delegate all processing,
	 * entity checks, transactions, and error handling to the canonical method.
	 *
	 * @return int Zero on success, negative on failure
	 */
	public function runScheduledInterEntityInvoices()
	{
		$result = $this->processInterEntityInvoices();
		if ($result < 0) {
			$this->output = $this->error ?: 'Inter-entity invoice conversion failed';
			return -1;
		}

		if (empty($this->output)) {
			$this->output = 'Inter-entity invoice conversion completed successfully';
		}

		return 0;
	}

	/**
	 * Get invoices for a specific date from ZoneSoft
	 * Equivalent to getDayDocumentInvoices() + getDayInvoices() in Google Apps Script
	 *
	 * @param int $sellerEntity Seller entity ID
	 * @param string $targetDate Target date (Y-m-d)
	 * @return array|false Array of invoices or false on error
	 */
	private function getDayDocumentInvoices($sellerEntity, $targetDate)
	{
		global $conf;


		// Get ZoneSoft store ID from seller entity configuration
		// Each entity has ZS_API_STORE configured in llx_const
		$zsbmsStoreId = getDolGlobalString('ZS_API_STORE');

		if (empty($zsbmsStoreId)) {
			$this->error = "ZS_API_STORE not configured for seller entity $sellerEntity";
			dol_syslog(__METHOD__ . " - " . $this->error, LOG_ERR);
			return false;
		}


		// Fetch every document page required for this date. A single latest page can
		// omit valid inter-entity invoices on high-volume days.
		$monthStart = date('Y-m-01', strtotime($targetDate));
		$response = $this->zsInvoiceSync->fetchInvoiceDocumentsWithinDateRange($monthStart, $targetDate, $zsbmsStoreId);

		if ($response === false) {
			$this->error = "Failed to retrieve invoices from ZoneSoft";
			dol_syslog(__METHOD__ . " - Failed to retrieve invoices from ZoneSoft", LOG_ERR);
			return false;
		}

		// Extract invoices from response
		$allInvoices = array();

		// Check if response has document property
		if (isset($response->document)) {
			$documents = $response->document;

			// Convert to array if it's an object
			if (is_object($documents)) {
				$documents = (array) $documents;
			}

			$totalCount = count($documents);

			// Filter invoices for target date
			foreach ($documents as $invoice) {
				// Convert invoice to array if it's an object
				if (is_object($invoice)) {
					$invoice = (array) $invoice;
				}

				if (dolizsynch_is_excluded_zsbms_document_type($invoice['doc'] ?? '')) {
					dol_syslog(__METHOD__ . " - Skipping excluded inter-entity document type " . dolizsynch_normalize_zsbms_document_type($invoice['doc'] ?? ''), LOG_INFO);
					continue;
				}

				if (empty($invoice['data'])) {
					continue;
				}

				$invoiceDate = (string) $invoice['data'];
				$matchesTargetDate = ($invoiceDate === $targetDate);
				$matchesCurrentMonthCancellation = (
					$this->isZSInvoiceCanceled($invoice)
					&& substr($invoiceDate, 0, 7) === substr((string) $targetDate, 0, 7)
				);

				if ($matchesTargetDate || $matchesCurrentMonthCancellation) {
					// Validate invoice has required data
					if ($this->validateZSInvoice($invoice)) {
						$allInvoices[] = $invoice;
					} else {
						dol_syslog(__METHOD__ . " - Invoice validation failed", LOG_WARNING);
					}
				}
			}
		} else {
			dol_syslog(__METHOD__ . " - Response has no 'document' property", LOG_WARNING);
		}

		dol_syslog(__METHOD__ . " - Found " . count($allInvoices) . " invoices for $targetDate", LOG_INFO);

		return $allInvoices;
	}

	/**
	 * Validate ZoneSoft invoice has required fields
	 *
	 * @param array $zsInvoice ZoneSoft invoice data
	 * @return bool True if valid
	 */
	private function validateZSInvoice($zsInvoice)
	{
		if (empty($zsInvoice['doc']) || empty($zsInvoice['serie']) || empty($zsInvoice['numero'])) {
			dol_syslog(__METHOD__ . " - Invalid invoice: missing doc/serie/numero", LOG_WARNING);
			return false;
		}

		if (dolizsynch_is_excluded_zsbms_document_type($zsInvoice['doc'] ?? '')) {
			dol_syslog(__METHOD__ . " - Skipping excluded inter-entity document type " . dolizsynch_normalize_zsbms_document_type($zsInvoice['doc'] ?? ''), LOG_INFO);
			return false;
		}

		if ($this->isZSInvoiceCanceled($zsInvoice)) {
			return true;
		}

		if (empty($zsInvoice['vendas'])) {
			return false;
		}

		// Check if vendas is an object or array (both are valid)
		if (!is_array($zsInvoice['vendas']) && !is_object($zsInvoice['vendas'])) {
			dol_syslog(__METHOD__ . " - Invoice vendas field is not array or object", LOG_WARNING);
			return false;
		}

		if (empty($zsInvoice['contribuinte'])) {
			dol_syslog(__METHOD__ . " - Invoice missing customer NIF, skipping", LOG_WARNING);
			return false;
		}

		return true;
	}

	/**
	 * Process a single invoice - convert to supplier invoice in target entity
	 * Equivalent to postToDolibarr() in Google Apps Script
	 *
	 * @param array $zsInvoice ZoneSoft invoice data
	 * @return int >0 if OK, <0 if error
	 */
	private function processInvoice($zsInvoice)
	{
		global $user;

		// Convert stdClass to array if needed
		if (is_object($zsInvoice)) {
			$zsInvoice = (array) $zsInvoice;
		}

		$invoiceRef = $zsInvoice['doc'] . '_' . $zsInvoice['serie'] . '_' . $zsInvoice['numero'] . '_' .
			date('Ymd', strtotime($zsInvoice['data']));


		try {
			if (dolizsynch_is_excluded_zsbms_document_type($zsInvoice['doc'] ?? '')) {
				dol_syslog(__METHOD__ . " - Skipping excluded inter-entity document $invoiceRef", LOG_INFO);
				return 0;
			}

			if ($this->isZSInvoiceCanceled($zsInvoice)) {
				return $this->processCanceledSourceInvoiceForExistingTargets($zsInvoice, $invoiceRef);
			}

			// Get target entity based on customer NIF
			$targetEntity = $this->getTargetEntityByNIF($zsInvoice['contribuinte']);
			if ($targetEntity < 0) {
				// Skip silently - already logged in getTargetEntityByNIF()
				return 0; // Return 0 to indicate skipped (not an error)
			}

			// Lock the entity-scoped tracking identity before checking idempotency.
			$this->db->begin();

			// Check if already processed. A stale tracking row can be repaired here
			// when a matching replacement supplier invoice already exists.
			if ($this->isInvoiceAlreadyProcessed($invoiceRef, $targetEntity, $zsInvoice)) {
				$this->db->commit();
				dol_syslog(__METHOD__ . " - Invoice already processed: $invoiceRef", LOG_INFO);
				return 1;
			}

			$trackingId = 0;

			// Map invoice lines (convert products)
			$mappedResult = $this->mapInvoiceLines($zsInvoice, $targetEntity);
			if ($mappedResult === false) {
				dol_syslog(__METHOD__ . " - Failed to map invoice lines: " . $this->error, LOG_ERR);
				$this->db->rollback();
				return -1;
			}
			$mappedLines = $mappedResult['lines'];
			$monetaryPlan = $mappedResult['monetary_plan'];

			// Create supplier invoice in target entity
			$reusedExistingInvoice = false;
			$invoiceId = $this->createSupplierInvoice($zsInvoice, $mappedLines, $targetEntity, $reusedExistingInvoice);
			if ($invoiceId < 0) {
				dol_syslog(__METHOD__ . " - Failed to create supplier invoice: " . $this->error, LOG_ERR);
				$this->db->rollback();
				return -1;
			}
			if ($reusedExistingInvoice) {
				if (!$this->verifyExistingSupplierInvoiceMonetaryPlan($invoiceId, $targetEntity, $monetaryPlan)) {
					dol_syslog(__METHOD__ . " - Existing supplier invoice failed exact verification: " . $this->error, LOG_ERR);
					$this->db->rollback();
					return -1;
				}
				$expectedPropertyPlans = count(array_filter($mappedLines, static function ($line) {
					return !empty($line['property_stock_plan']);
				}));
				if ($expectedPropertyPlans > 0) {
					$propertyStockService = new ZSPropertyStockService($this->db);
					if (!$propertyStockService->verifyExistingPlans($invoiceId, $targetEntity, $expectedPropertyPlans)) {
						$this->error = 'An existing supplier invoice cannot be adopted because its required property stock plans are missing.';
						$this->db->rollback();
						return -1;
					}
				}

				$trackingId = $this->saveInvoiceTracking($zsInvoice, $invoiceId, $targetEntity);
				if ($trackingId < 0) {
					dol_syslog(__METHOD__ . " - Failed to track verified existing supplier invoice", LOG_ERR);
					$this->db->rollback();
					return -1;
				}

				$this->db->commit();
				dol_syslog(__METHOD__ . " - Linked verified existing supplier invoice {$invoiceId} to {$invoiceRef}", LOG_INFO);
				return $invoiceId;
			}

			// Handle rounding if needed
			$roundingResult = $this->handleRounding($invoiceId, $monetaryPlan, $targetEntity);
			if ($roundingResult < 0) {
				$this->error = $this->error ?: "Failed to reconcile supplier invoice totals";
				dol_syslog(__METHOD__ . " - " . $this->error, LOG_ERR);
				$this->db->rollback();
				return -1;
			}

			// Save tracking record
			$trackingId = $this->saveInvoiceTracking($zsInvoice, $invoiceId, $targetEntity);
			if ($trackingId < 0) {
				dol_syslog(__METHOD__ . " - Failed to save tracking record", LOG_ERR);
				$this->db->rollback();
				return -1;
			}

			$this->db->commit();

			if ($this->isFinalSupplierInvoiceValidationEnabled()) {
				// Final task: validate supplier invoice through Dolibarr API operation supplierinvoicesValidate.
				if (!$this->validateSupplierInvoiceFinalTask($invoiceId, $targetEntity)) {
					$cleanupOk = $this->deleteInvoiceTracking($trackingId, $targetEntity);
					if (!$cleanupOk) {
						$this->error .= " | Tracking cleanup failed after validation error";
					}
					dol_syslog(__METHOD__ . " - Supplier invoice validation failed for ID $invoiceId: " . $this->error, LOG_ERR);
					return -1;
				}
			} else {
				dol_syslog(__METHOD__ . " - Final supplier invoice validation is disabled for invoice ID $invoiceId", LOG_INFO);
			}

			dol_syslog(__METHOD__ . " - Successfully processed invoice: $invoiceRef -> ID: $invoiceId", LOG_INFO);

			return $invoiceId;
		} catch (Exception $e) {
			$this->db->rollback();
			$this->error = "Exception processing invoice $invoiceRef: " . $e->getMessage();
			dol_syslog(__METHOD__ . " - " . $this->error . " at " . $e->getFile() . ":" . $e->getLine(), LOG_ERR);
			return -1;
		}
	}

	/**
	 * Check whether the ZoneSoft source invoice is canceled/nullified.
	 *
	 * @param array $zsInvoice ZoneSoft invoice data
	 * @return bool True when the source invoice is canceled
	 */
	private function isZSInvoiceCanceled($zsInvoice)
	{
		if (is_object($zsInvoice)) {
			$zsInvoice = (array) $zsInvoice;
		}

		$candidateFields = array('anulado', 'cancelado', 'canceled', 'cancelled');
		foreach ($candidateFields as $field) {
			if (!array_key_exists($field, $zsInvoice)) {
				continue;
			}

			$value = $zsInvoice[$field];
			if (is_numeric($value) && (int) $value === 1) {
				return true;
			}

			$normalized = strtolower(trim((string) $value));
			if (in_array($normalized, array('1', 'true', 'yes', 'sim', 'anulado', 'cancelado', 'canceled', 'cancelled'), true)) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Propagate source cancellation to already-synced destination invoices.
	 *
	 * Existing inter-entity tracking is authoritative because canceled source payloads may no longer
	 * carry enough data to resolve the target entity as a normal invoice conversion.
	 *
	 * @param array $zsInvoice ZoneSoft invoice data
	 * @param string $invoiceRef Internal ZoneSoft invoice reference
	 * @return int >0 if OK, <0 if error
	 */
	private function processCanceledSourceInvoiceForExistingTargets($zsInvoice, $invoiceRef)
	{
		$sourceEntity = $this->getSellerEntity();
		if ($sourceEntity <= 0) {
			$this->error = "Invalid source entity for inter-entity cancellation";
			return -1;
		}

		$trackingRows = $this->fetchInterEntityTrackingRowsBySourceRef($invoiceRef, $sourceEntity);
		if (!empty($trackingRows)) {
			$handled = 0;
			$errors = array();

			foreach ($trackingRows as $tracking) {
				$result = $this->processCanceledSourceInvoice($zsInvoice, $invoiceRef, (int) $tracking->target_entity);
				if ($result < 0) {
					$errors[] = $this->error;
					continue;
				}
				$handled += max(1, (int) $result);
			}

			if (!empty($errors)) {
				$this->error = implode('; ', array_values(array_unique($errors)));
				return -1;
			}

			return $handled;
		}

		if (!empty($zsInvoice['contribuinte'])) {
			$targetEntity = $this->getTargetEntityByNIF($zsInvoice['contribuinte']);
			if ($targetEntity > 0) {
				return $this->processCanceledSourceInvoice($zsInvoice, $invoiceRef, $targetEntity);
			}
		}

		dol_syslog(__METHOD__ . " - Source invoice {$invoiceRef} is canceled, but no synced inter-entity destination invoice was found.", LOG_INFO);
		return 1;
	}

	/**
	 * Fetch all inter-entity tracking rows for one source reference.
	 *
	 * @param string $invoiceRef Source invoice reference
	 * @param int $sourceEntity Source entity
	 * @return array<int,stdClass>
	 */
	private function fetchInterEntityTrackingRowsBySourceRef($invoiceRef, $sourceEntity)
	{
		$sql = "SELECT rowid, target_entity, fk_facture_fourn_dest";
		$sql .= " FROM " . MAIN_DB_PREFIX . "dolizsynch_interentity_invoice";
		$sql .= " WHERE source_invoice_ref = '" . $this->db->escape($invoiceRef) . "'";
		$sql .= " AND source_entity = " . ((int) $sourceEntity);
		$sql .= " AND entity = " . ((int) $sourceEntity);
		$sql .= " ORDER BY rowid ASC";

		$resql = $this->db->query($sql);
		if (!$resql) {
			dol_syslog(__METHOD__ . " - Failed to fetch inter-entity invoice tracking rows: " . $this->db->lasterror(), LOG_ERR);
			return array();
		}

		$rows = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$rows[] = $obj;
		}

		return $rows;
	}

	/**
	 * Propagate source cancellation to the destination supplier invoice.
	 *
	 * Draft destination invoices are deleted because they are not legally finalized yet.
	 * Non-draft destination invoices are canceled through Dolibarr's supplier invoice lifecycle.
	 *
	 * @param array $zsInvoice ZoneSoft invoice data
	 * @param string $invoiceRef Internal ZoneSoft invoice reference
	 * @param int $targetEntity Buyer entity ID
	 * @return int >0 if OK, <0 if error
	 */
	private function processCanceledSourceInvoice($zsInvoice, $invoiceRef, $targetEntity)
	{
		$sourceEntity = $this->getSellerEntity();
		if ($sourceEntity <= 0 || (int) $targetEntity <= 0) {
			$this->error = "Invalid source or target entity for inter-entity cancellation";
			return -1;
		}

		dol_syslog(__METHOD__ . " - Processing source cancellation for {$invoiceRef}, source entity {$sourceEntity}, target entity {$targetEntity}", LOG_INFO);

		$tracking = $this->fetchInterEntityTracking($invoiceRef, $targetEntity, $sourceEntity);
		$invoiceId = !empty($tracking) ? (int) $tracking->fk_facture_fourn_dest : 0;

		if ($invoiceId <= 0) {
			$invoiceId = $this->findDestinationSupplierInvoiceForSource($zsInvoice, $targetEntity);
		}

		if ($invoiceId <= 0) {
			$message = "Source invoice {$invoiceRef} is canceled; no destination supplier invoice exists in entity {$targetEntity}.";
			if (!empty($tracking)) {
				$this->updateInterEntityTrackingStatus((int) $tracking->rowid, $sourceEntity, $targetEntity, 0, 1, $message);
			} else {
				if ($this->saveCancellationTracking($zsInvoice, 0, $targetEntity, $message) < 0) {
					return -1;
				}
			}
			dol_syslog(__METHOD__ . " - " . $message, LOG_INFO);
			return 1;
		}

		if (empty($tracking)) {
			$trackingId = $this->saveCancellationTracking($zsInvoice, $invoiceId, $targetEntity, "Source invoice {$invoiceRef} is canceled; destination supplier invoice pending cancellation.");
			if ($trackingId < 0) {
				return -1;
			}
		} else {
			$trackingId = (int) $tracking->rowid;
		}

		$this->db->begin();
		try {
			$action = '';
			$message = '';
			$destinationInvoiceId = $invoiceId;

			if (!$this->cancelOrDeleteDestinationSupplierInvoice($invoiceId, $targetEntity, $invoiceRef, $trackingId, $sourceEntity, $action, $message)) {
				$this->db->rollback();
				$this->updateInterEntityTrackingStatus($trackingId, $sourceEntity, $targetEntity, $invoiceId, -1, $this->error);
				return -1;
			}

			if ($action === 'deleted') {
				$destinationInvoiceId = 0;
			}

			if (!$this->updateInterEntityTrackingStatus($trackingId, $sourceEntity, $targetEntity, $destinationInvoiceId, 1, $message)) {
				$this->db->rollback();
				return -1;
			}

			$this->db->commit();
			dol_syslog(__METHOD__ . " - " . $message, LOG_INFO);
			return 1;
		} catch (Exception $e) {
			$this->db->rollback();
			$this->error = "Exception propagating inter-entity cancellation for {$invoiceRef}: " . $e->getMessage();
			dol_syslog(__METHOD__ . " - " . $this->error, LOG_ERR);
			return -1;
		}
	}

	/**
	 * Fetch the tracking row for one source invoice and target entity.
	 *
	 * @param string $invoiceRef Source invoice reference
	 * @param int $targetEntity Target entity
	 * @param int $sourceEntity Source entity
	 * @return stdClass|null
	 */
	private function fetchInterEntityTracking($invoiceRef, $targetEntity, $sourceEntity)
	{
		$sql = "SELECT rowid, entity, source_entity, source_invoice_ref, target_entity, fk_facture_fourn_dest";
		$sql .= " FROM " . MAIN_DB_PREFIX . "dolizsynch_interentity_invoice";
		$sql .= " WHERE source_invoice_ref = '" . $this->db->escape($invoiceRef) . "'";
		$sql .= " AND target_entity = " . ((int) $targetEntity);
		$sql .= " AND source_entity = " . ((int) $sourceEntity);
		$sql .= " AND entity = " . ((int) $sourceEntity);
		$sql .= " LIMIT 1";

		$resql = $this->db->query($sql);
		if (!$resql) {
			dol_syslog(__METHOD__ . " - Failed to fetch inter-entity invoice tracking: " . $this->db->lasterror(), LOG_ERR);
			return null;
		}

		$obj = $this->db->fetch_object($resql);
		return $obj ?: null;
	}

	/**
	 * Find the destination supplier invoice for a source invoice when tracking is incomplete.
	 *
	 * @param array $zsInvoice ZoneSoft invoice data
	 * @param int $targetEntity Target entity
	 * @return int Supplier invoice ID or 0
	 */
	private function findDestinationSupplierInvoiceForSource($zsInvoice, $targetEntity)
	{
		$sellerEntity = $this->getSellerEntity();
		$sellerNif = $this->getEntityNIF($sellerEntity);
		if (empty($sellerNif)) {
			dol_syslog(__METHOD__ . " - Seller entity {$sellerEntity} has no NIF configured; cannot resolve destination supplier invoice", LOG_WARNING);
			return 0;
		}

		$supplierId = $this->getSupplierByNIF($targetEntity, $sellerNif);
		if ($supplierId <= 0) {
			return 0;
		}

		return $this->findSupplierInvoiceByRefSupplier($this->buildSupplierInvoiceRefSupplier($zsInvoice), $targetEntity, $supplierId);
	}

	/**
	 * Cancel or delete the destination supplier invoice according to its lifecycle state.
	 *
	 * @param int $invoiceId Supplier invoice ID
	 * @param int $targetEntity Target entity
	 * @param string $sourceInvoiceRef Source invoice reference
	 * @param int $trackingId Tracking row ID
	 * @param int $sourceEntity Source entity
	 * @param string $action Output action: deleted, canceled, already_canceled
	 * @param string $message Output log/audit message
	 * @return bool True on success
	 */
	private function cancelOrDeleteDestinationSupplierInvoice($invoiceId, $targetEntity, $sourceInvoiceRef, $trackingId, $sourceEntity, &$action, &$message)
	{
		global $conf, $user;

		$targetEntity = (int) $targetEntity;
		$previousEntity = (int) $conf->entity;
		if (!$this->activateEntityConfiguration($targetEntity)) {
			return false;
		}

		try {
			$invoice = new FactureFournisseur($this->db);
			$fetchResult = $invoice->fetch((int) $invoiceId);
			if ($fetchResult <= 0) {
				$this->error = "Failed to fetch destination supplier invoice {$invoiceId} in entity {$targetEntity}";
				return false;
			}

			$invoiceEntity = isset($invoice->entity) ? (int) $invoice->entity : $this->getSupplierInvoiceEntity($invoiceId);
			if ($invoiceEntity !== $targetEntity) {
				$this->error = "Destination supplier invoice {$invoiceId} belongs to entity {$invoiceEntity}, not target entity {$targetEntity}";
				return false;
			}

			$status = $this->getSupplierInvoiceStatus($invoice, $targetEntity);
			if ($this->isSupplierInvoiceCanceledStatus($status)) {
				$action = 'already_canceled';
				$message = "Destination supplier invoice {$invoice->ref} was already canceled for source {$sourceInvoiceRef}.";
				return true;
			}

			if ($this->isSupplierInvoiceDraftStatus($status)) {
				if (!$this->updateInterEntityTrackingStatus($trackingId, $sourceEntity, $targetEntity, 0, 1, "Deleting draft destination supplier invoice {$invoiceId} because source {$sourceInvoiceRef} is canceled.")) {
					return false;
				}

				$deleteResult = $invoice->delete($user);
				if ($deleteResult < 0) {
					$this->error = $invoice->error ?: "Failed to delete draft destination supplier invoice {$invoiceId}";
					return false;
				}

				$action = 'deleted';
				$message = "Deleted draft destination supplier invoice {$invoiceId} because source {$sourceInvoiceRef} is canceled.";
				return true;
			}

			$this->db->begin();
			$reversal = new DoliZSynchStockReversal($this->db);
			$reverseResult = $reversal->reverseInvoiceMovements($invoice, 'invoice_supplier', $user);
			if ($reverseResult < 0) {
				$this->error = $reversal->error;
				$this->db->rollback();
				return false;
			}

			$cancelResult = $this->cancelSupplierInvoiceObject($invoice, $user);
			if ($cancelResult < 0) {
				$this->error = $invoice->error ?: "Failed to cancel destination supplier invoice {$invoice->ref}";
				$this->db->rollback();
				return false;
			}
			$this->db->commit();

			$action = 'canceled';
			$message = "Canceled destination supplier invoice {$invoice->ref} because source {$sourceInvoiceRef} is canceled.";
			return true;
		} finally {
			$this->activateEntityConfiguration($previousEntity);
		}
	}

	/**
	 * Resolve supplier invoice status without assuming one Dolibarr property name.
	 *
	 * @param FactureFournisseur $invoice Supplier invoice object
	 * @param int $targetEntity Target entity
	 * @return int Status value, -1 if unknown
	 */
	private function getSupplierInvoiceStatus($invoice, $targetEntity)
	{
		foreach (array('statut', 'status', 'fk_statut') as $property) {
			if (isset($invoice->{$property}) && is_numeric($invoice->{$property})) {
				return (int) $invoice->{$property};
			}
		}

		$sql = "SELECT fk_statut";
		$sql .= " FROM " . MAIN_DB_PREFIX . "facture_fourn";
		$sql .= " WHERE rowid = " . ((int) $invoice->id);
		$sql .= " AND entity = " . ((int) $targetEntity);
		$sql .= " LIMIT 1";

		$resql = $this->db->query($sql);
		if (!$resql) {
			dol_syslog(__METHOD__ . " - Failed to fetch supplier invoice status: " . $this->db->lasterror(), LOG_ERR);
			return -1;
		}

		$obj = $this->db->fetch_object($resql);
		if (!$obj || !is_numeric($obj->fk_statut)) {
			return -1;
		}

		return (int) $obj->fk_statut;
	}

	/**
	 * Resolve supplier invoice entity from the database.
	 *
	 * @param int $invoiceId Supplier invoice ID
	 * @return int Entity ID or 0 when not found
	 */
	private function getSupplierInvoiceEntity($invoiceId)
	{
		$sql = "SELECT entity";
		$sql .= " FROM " . MAIN_DB_PREFIX . "facture_fourn";
		$sql .= " WHERE rowid = " . ((int) $invoiceId);
		$sql .= " LIMIT 1";

		$resql = $this->db->query($sql);
		if (!$resql) {
			dol_syslog(__METHOD__ . " - Failed to fetch supplier invoice entity: " . $this->db->lasterror(), LOG_ERR);
			return 0;
		}

		$obj = $this->db->fetch_object($resql);
		if (!$obj) {
			return 0;
		}

		return (int) $obj->entity;
	}

	/**
	 * Check whether a supplier invoice is still draft.
	 *
	 * @param int $status Supplier invoice status
	 * @return bool
	 */
	private function isSupplierInvoiceDraftStatus($status)
	{
		$draftStatuses = array(0);
		if (defined('FactureFournisseur::STATUS_DRAFT')) {
			$draftStatuses[] = (int) constant('FactureFournisseur::STATUS_DRAFT');
		}

		return in_array((int) $status, array_unique($draftStatuses), true);
	}

	/**
	 * Check whether a supplier invoice is already canceled/abandoned.
	 *
	 * @param int $status Supplier invoice status
	 * @return bool
	 */
	private function isSupplierInvoiceCanceledStatus($status)
	{
		$canceledStatuses = array(3);
		foreach (array('STATUS_ABANDONED', 'STATUS_CANCELED', 'STATUS_CANCELLED') as $constantName) {
			$constant = 'FactureFournisseur::' . $constantName;
			if (defined($constant)) {
				$canceledStatuses[] = (int) constant($constant);
			}
		}

		return in_array((int) $status, array_unique($canceledStatuses), true);
	}

	/**
	 * Cancel a supplier invoice through the available Dolibarr object method.
	 *
	 * @param FactureFournisseur $invoice Supplier invoice object
	 * @param User $user User object
	 * @return int Method result
	 */
	private function cancelSupplierInvoiceObject($invoice, $user)
	{
		if (method_exists($invoice, 'setCanceled')) {
			return $invoice->setCanceled($user);
		}

		if (method_exists($invoice, 'set_canceled')) {
			try {
				$method = new ReflectionMethod($invoice, 'set_canceled');
				$parameterCount = $method->getNumberOfParameters();
				if ($parameterCount >= 3) {
					return $invoice->set_canceled($user, '', 'Canceled because the source inter-entity invoice is canceled.');
				}
				if ($parameterCount >= 2) {
					return $invoice->set_canceled($user, 'Canceled because the source inter-entity invoice is canceled.');
				}
			} catch (ReflectionException $e) {
				dol_syslog(__METHOD__ . " - Reflection failed while resolving supplier invoice cancellation signature: " . $e->getMessage(), LOG_WARNING);
			}

			return $invoice->set_canceled($user);
		}

		if (method_exists($invoice, 'cancel')) {
			return $invoice->cancel($user);
		}

		$this->error = "No compatible supplier invoice cancellation method found";
		return -1;
	}

	/**
	 * Synchronize destination invoices after the source customer invoice is canceled or deleted in Dolibarr.
	 *
	 * @param int $sourceInvoiceId Source customer invoice ID
	 * @param string $originAction Trigger/action name that requested synchronization
	 * @param bool $manageTransaction Open/commit per-destination transaction when true
	 * @return int Number of destination invoices handled, 0 when no tracking exists, <0 on error
	 */
	public function synchronizeCanceledSourceInvoice($sourceInvoiceId, $originAction = '', $manageTransaction = true)
	{
		global $conf;

		$sourceInvoiceId = (int) $sourceInvoiceId;
		$sourceEntity = (int) $conf->entity;
		if ($sourceInvoiceId <= 0 || $sourceEntity <= 0) {
			$this->error = "Invalid source invoice for inter-entity cancellation synchronization";
			return -1;
		}

		if (!$this->isEnabledForCurrentEntity()) {
			return 0;
		}

		$trackingRows = $this->fetchInterEntityTrackingRowsForSourceInvoice($sourceInvoiceId, $sourceEntity);
		if (empty($trackingRows)) {
			return 0;
		}

		$handled = 0;
		$errors = array();

		foreach ($trackingRows as $tracking) {
			$trackingId = (int) $tracking->rowid;
			$targetEntity = (int) $tracking->target_entity;
			$destinationInvoiceId = (int) $tracking->fk_facture_fourn_dest;
			$sourceInvoiceRef = (string) $tracking->source_invoice_ref;

			if ((int) $tracking->fk_facture_source <= 0) {
				$this->linkTrackingToSourceInvoice($trackingId, $sourceInvoiceId, $sourceEntity, $targetEntity);
			}

			if ($destinationInvoiceId <= 0) {
				$message = "Source customer invoice {$sourceInvoiceId} was canceled/deleted by {$originAction}; no destination supplier invoice exists for {$sourceInvoiceRef}.";
				if ($this->updateInterEntityTrackingStatus($trackingId, $sourceEntity, $targetEntity, 0, 1, $message)) {
					$handled++;
				}
				continue;
			}

			if ($manageTransaction) {
				$this->db->begin();
			}

			$action = '';
			$message = '';
			if (!$this->cancelOrDeleteDestinationSupplierInvoice($destinationInvoiceId, $targetEntity, $sourceInvoiceRef, $trackingId, $sourceEntity, $action, $message)) {
				if ($manageTransaction) {
					$this->db->rollback();
				}
				$error = "Failed to synchronize destination supplier invoice {$destinationInvoiceId} for source {$sourceInvoiceRef}: " . $this->error;
				$errors[] = $error;
				$this->updateInterEntityTrackingStatus($trackingId, $sourceEntity, $targetEntity, $destinationInvoiceId, -1, $error);
				continue;
			}

			$destinationInvoiceIdAfter = ($action === 'deleted') ? 0 : $destinationInvoiceId;
			if (!$this->updateInterEntityTrackingStatus($trackingId, $sourceEntity, $targetEntity, $destinationInvoiceIdAfter, 1, $message)) {
				if ($manageTransaction) {
					$this->db->rollback();
				}
				$errors[] = $this->error;
				continue;
			}

			if ($manageTransaction) {
				$this->db->commit();
			}
			$handled++;
		}

		if (!empty($errors)) {
			$this->error = implode('; ', $errors);
			return -1;
		}

		return $handled;
	}

	/**
	 * Fetch tracking rows related to a source customer invoice.
	 *
	 * @param int $sourceInvoiceId Source customer invoice ID
	 * @param int $sourceEntity Source entity
	 * @return array<int,stdClass>
	 */
	private function fetchInterEntityTrackingRowsForSourceInvoice($sourceInvoiceId, $sourceEntity)
	{
		$invoiceRefs = $this->getSourceInvoiceRefsByFacture($sourceInvoiceId, $sourceEntity);
		$conditions = array("fk_facture_source = " . ((int) $sourceInvoiceId));

		if (!empty($invoiceRefs)) {
			$escapedRefs = array();
			foreach ($invoiceRefs as $invoiceRef) {
				$escapedRefs[] = "'" . $this->db->escape($invoiceRef) . "'";
			}
			$conditions[] = "source_invoice_ref IN (" . implode(',', $escapedRefs) . ")";
		}

		$sql = "SELECT rowid, source_invoice_ref, fk_facture_source, target_entity, fk_facture_fourn_dest";
		$sql .= " FROM " . MAIN_DB_PREFIX . "dolizsynch_interentity_invoice";
		$sql .= " WHERE entity = " . ((int) $sourceEntity);
		$sql .= " AND source_entity = " . ((int) $sourceEntity);
		$sql .= " AND (" . implode(' OR ', $conditions) . ")";
		$sql .= " ORDER BY rowid ASC";

		$resql = $this->db->query($sql);
		if (!$resql) {
			dol_syslog(__METHOD__ . " - Failed to fetch source invoice tracking rows: " . $this->db->lasterror(), LOG_ERR);
			return array();
		}

		$rows = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$rows[] = $obj;
		}

		return $rows;
	}

	/**
	 * Resolve source ZoneSoft references from a Dolibarr customer invoice.
	 *
	 * @param int $sourceInvoiceId Source customer invoice ID
	 * @param int $sourceEntity Source entity
	 * @return array<int,string>
	 */
	private function getSourceInvoiceRefsByFacture($sourceInvoiceId, $sourceEntity)
	{
		$invoiceRefs = array();

		$sql = "SELECT invoice_ref";
		$sql .= " FROM " . MAIN_DB_PREFIX . "dolizsynch_zsinvoicesynch";
		$sql .= " WHERE fk_facture = " . ((int) $sourceInvoiceId);
		$sql .= " AND entity = " . ((int) $sourceEntity);
		$sql .= " AND invoice_ref IS NOT NULL";

		$resql = $this->db->query($sql);
		if ($resql) {
			while ($obj = $this->db->fetch_object($resql)) {
				if (!empty($obj->invoice_ref)) {
					$invoiceRefs[] = (string) $obj->invoice_ref;
				}
			}
		} else {
			dol_syslog(__METHOD__ . " - Failed to fetch source invoice refs from sync table: " . $this->db->lasterror(), LOG_WARNING);
		}

		$sql = "SELECT fe.invoice_number";
		$sql .= " FROM " . MAIN_DB_PREFIX . "facture as f";
		$sql .= " INNER JOIN " . MAIN_DB_PREFIX . "facture_extrafields as fe ON fe.fk_object = f.rowid";
		$sql .= " WHERE f.rowid = " . ((int) $sourceInvoiceId);
		$sql .= " AND f.entity = " . ((int) $sourceEntity);
		$sql .= " AND fe.invoice_number IS NOT NULL";
		$sql .= " LIMIT 1";

		$resql = $this->db->query($sql);
		if ($resql) {
			$obj = $this->db->fetch_object($resql);
			if ($obj && !empty($obj->invoice_number)) {
				$invoiceRefs[] = (string) $obj->invoice_number;
			}
		} else {
			dol_syslog(__METHOD__ . " - Failed to fetch source invoice ref from extrafields: " . $this->db->lasterror(), LOG_WARNING);
		}

		return array_values(array_unique($invoiceRefs));
	}

	/**
	 * Persist source invoice link on legacy tracking rows when it can now be resolved.
	 *
	 * @param int $trackingId Tracking row ID
	 * @param int $sourceInvoiceId Source customer invoice ID
	 * @param int $sourceEntity Source entity
	 * @param int $targetEntity Target entity
	 * @return bool True on success
	 */
	private function linkTrackingToSourceInvoice($trackingId, $sourceInvoiceId, $sourceEntity, $targetEntity)
	{
		$sql = "UPDATE " . MAIN_DB_PREFIX . "dolizsynch_interentity_invoice";
		$sql .= " SET fk_facture_source = " . ((int) $sourceInvoiceId);
		$sql .= " WHERE rowid = " . ((int) $trackingId);
		$sql .= " AND entity = " . ((int) $sourceEntity);
		$sql .= " AND source_entity = " . ((int) $sourceEntity);
		$sql .= " AND target_entity = " . ((int) $targetEntity);
		$sql .= " AND (fk_facture_source IS NULL OR fk_facture_source <= 0)";
		$sql .= " LIMIT 1";

		if (!$this->db->query($sql)) {
			dol_syslog(__METHOD__ . " - Failed to link tracking row {$trackingId} to source invoice {$sourceInvoiceId}: " . $this->db->lasterror(), LOG_WARNING);
			return false;
		}

		return true;
	}

	/**
	 * Map invoice lines - convert seller products to buyer products
	 * Equivalent to the product equivalence logic in Google Apps Script
	 * Simple 1:1 mapping
	 *
	 * @param array $zsInvoice ZoneSoft invoice payload
	 * @param int $targetEntity Target entity ID (not used in lookup, just for logging)
	 * @return array|false Mapped lines and exact monetary plan, or false on error
	 */
	private function mapInvoiceLines($zsInvoice, $targetEntity)
	{
		global $conf;

		if (is_object($zsInvoice)) {
			$zsInvoice = (array) $zsInvoice;
		}
		if (!is_array($zsInvoice)) {
			$this->error = 'Malformed inter-entity invoice payload';
			return false;
		}
		$zsLines = $zsInvoice['vendas'] ?? array();
		$propertyMovements = $zsInvoice['movimentospropriedades'] ?? array();
		// Convert vendas to array if it's an object
		if (is_object($zsLines)) {
			$zsLines = (array) $zsLines;
		}
		if (is_object($propertyMovements)) {
			$propertyMovements = (array) $propertyMovements;
		}
		if (!is_array($propertyMovements)) {
			$propertyMovements = array();
		}

		$mappedLines = array();
		$taxLines = array();
		$unmappedProducts = array();
		$pricesIncludeVat = !empty($zsInvoice['ivaincluido']);
		$propertyStockService = new ZSPropertyStockService($this->db);
		$propertyStockRules = array();
		$previousEntity = (int) $conf->entity;
		if (!$this->activateEntityConfiguration((int) $targetEntity)) {
			return false;
		}
		$configuredPropertyStockId = getDolGlobalInt('DOLIZSYNCH_PROPERTY_STOCK_SOURCE_PROPERTY_ID');
		if (!$this->activateEntityConfiguration($previousEntity)) {
			return false;
		}
		try {
			$globalDiscount = ZSDecimal::normalize((string) ($zsInvoice['dpercent'] ?? '0'));
		} catch (InvalidArgumentException $e) {
			$this->error = 'Inter-entity invoice contains an invalid global discount';
			return false;
		}

		foreach ($zsLines as $line) {
			// Convert stdClass to array if needed
			if (is_object($line)) {
				$line = (array) $line;
			}

			// Get product equivalence - 'codigo' field contains the source product ref
			$sourceProductRef = $line['codigo'] ?? '';
			if (empty($sourceProductRef)) {
				$unmappedProducts[] = '[missing source reference]';
				continue;
			}

			// Debug: Log available fields and their values

			$destProduct = $this->getProductEquivalence($sourceProductRef, $targetEntity);
			if ($destProduct === false) {
				$unmappedProducts[] = $sourceProductRef;
				dol_syslog(__METHOD__ . " - No product equivalence for product ref: $sourceProductRef", LOG_WARNING);
				continue;
			}

			$qtyRaw = $line['qtd'] ?? ($line['quantidade'] ?? ($line['qty'] ?? ($line['qnt'] ?? '')));
			try {
				$qty = ZSDecimal::normalize((string) $qtyRaw);
				$price = ZSDecimal::normalize((string) ($line['punit'] ?? ''));
				$vat = ZSDecimal::normalize((string) ($line['iva'] ?? '0'));
				$discount = ZSDecimal::normalize((string) ($line['desconto2'] ?? '0'));
			} catch (InvalidArgumentException $e) {
				$this->error = 'Inter-entity invoice contains an invalid decimal line value';
				return false;
			}
			if (ZSDecimal::compare($qty, '0') <= 0 || ZSDecimal::compare($price, '0') < 0
				|| ZSDecimal::compare($vat, '0') < 0 || ZSDecimal::compare($vat, '100') > 0
				|| ZSDecimal::compare($discount, '0') < 0 || ZSDecimal::compare($discount, '100') > 0) {
				$this->error = 'Inter-entity invoice contains an invalid line sign or percentage';
				return false;
			}
			$effectiveDiscount = ZSDecimal::subtract(
				'100',
				ZSDecimal::divideByPowerOfTen(
					ZSDecimal::multiply(ZSDecimal::subtract('100', $discount), ZSDecimal::subtract('100', $globalDiscount)),
					2
				)
			);

			$mappedLine = array(
				'fk_product' => $destProduct['id'],
				'desc' => (string) ($line['descricao'] ?? ''),
				'qty' => $qty,
				'subprice' => $price,
				'tva_tx' => $vat,
				'remise_percent' => $effectiveDiscount,
				'product_type' => 0,
				'price_base_type' => $pricesIncludeVat ? 'TTC' : 'HT',
			);
			$destProductId = (int) $destProduct['id'];
			if ($configuredPropertyStockId > 0 && !array_key_exists($destProductId, $propertyStockRules)) {
				if (!$this->activateEntityConfiguration((int) $targetEntity)) {
					return false;
				}
				$propertyStockRuleError = '';
				try {
					$propertyStockRules[$destProductId] = $propertyStockService->fetchRule($destProductId, (int) $targetEntity, true);
					$propertyStockRuleError = $propertyStockService->error;
				} catch (Throwable $e) {
					$propertyStockRuleError = 'Unable to validate the destination property stock BOM: ' . $e->getMessage();
				}
				if (!$this->activateEntityConfiguration($previousEntity)) {
					return false;
				}
				if ($propertyStockRuleError !== '') {
					$this->error = $propertyStockRuleError;
					return false;
				}
			}
			$propertyStockRule = $configuredPropertyStockId > 0
				? ($propertyStockRules[$destProductId] ?? null)
				: null;
			if ($propertyStockRule !== null) {
				if (!empty($propertyStockRule['shared'])) {
					// The BOM selection is shared, but source-property ownership remains entity-specific.
					$propertyStockRule['property_id'] = $configuredPropertyStockId;
				} elseif ((int) $propertyStockRule['property_id'] !== $configuredPropertyStockId) {
					$propertyStockRule = null;
				}
			}
			if ($propertyStockRule !== null) {
				try {
					$mappedLine['property_stock_plan'] = ZSPropertyStockService::buildLinePlan(
						$line,
						$propertyMovements,
						$propertyStockRule
					);
				} catch (InvalidArgumentException $e) {
					$this->error = 'Inter-entity invoice line ' . (string) $sourceProductRef . ' rejected: ' . $e->getMessage();
					return false;
				}
			}

			$mappedLines[] = $mappedLine;
			$taxLine = array(
				'quantity' => $qty,
				'unit_price' => $price,
				'discount_percent' => $discount,
				'vat_rate' => $vat,
				'exemption' => trim((string) ($line['motivo_isencao'] ?? $line['isencao'] ?? '')),
			);
			if (array_key_exists('liquido', $line) && array_key_exists('total', $line)) {
				$taxLine['source_total_ht'] = (string) $line['liquido'];
				$taxLine['source_total_ttc'] = (string) $line['total'];
			}
			$taxLines[] = $taxLine;
		}

		if (empty($mappedLines) || !empty($unmappedProducts) || count($mappedLines) !== count($zsLines)) {
			$this->error = "Inter-entity invoice rejected because every line must have an explicit product mapping. Unmapped products: " . implode(', ', array_unique($unmappedProducts));
			dol_syslog(__METHOD__ . " - " . $this->error, LOG_ERR);
			return false;
		}

		$sourceContext = array(
			'external_id' => (string) ($zsInvoice['loja'] ?? '') . '|' . (string) ($zsInvoice['doc'] ?? '') . '|' . (string) ($zsInvoice['serie'] ?? '') . '|' . (string) ($zsInvoice['numero'] ?? ''),
			'invoice_number' => (string) ($zsInvoice['doc'] ?? '') . '_' . (string) ($zsInvoice['serie'] ?? '') . '_' . (string) ($zsInvoice['numero'] ?? ''),
		);
		try {
			$monetaryPlan = ZSInvoiceTaxCalculator::reconcileSource(
				$taxLines,
				$globalDiscount,
				$pricesIncludeVat,
				(string) ($zsInvoice['liquido'] ?? ''),
				null,
				(string) ($zsInvoice['total'] ?? ''),
				$sourceContext
			);
		} catch (Throwable $reportedModeException) {
			$alternatePricesIncludeVat = !$pricesIncludeVat;
			try {
				$monetaryPlan = ZSInvoiceTaxCalculator::reconcileSource(
					$taxLines,
					$globalDiscount,
					$alternatePricesIncludeVat,
					(string) ($zsInvoice['liquido'] ?? ''),
					null,
					(string) ($zsInvoice['total'] ?? ''),
					$sourceContext
				);
				$pricesIncludeVat = $alternatePricesIncludeVat;
				foreach ($mappedLines as &$mappedLine) {
					$mappedLine['price_base_type'] = $pricesIncludeVat ? 'TTC' : 'HT';
				}
				unset($mappedLine);
				dol_syslog(__METHOD__ . " - Source VAT-included flag did not reconcile; accepted the opposite price mode after exact line/header reconciliation for " . $sourceContext['invoice_number'], LOG_WARNING);
			} catch (Throwable $alternateModeException) {
				$this->error = $reportedModeException->getMessage() . ' | Alternate price mode: ' . $alternateModeException->getMessage();
				return false;
			}
		}

		return array('lines' => $mappedLines, 'monetary_plan' => $monetaryPlan);
	}

	/**
	 * Create supplier invoice in target entity
	 *
	 * @param array $zsInvoice ZoneSoft invoice data
	 * @param array $lines Mapped invoice lines
	 * @param int $targetEntity Target entity ID
	 * @param bool $reusedExisting Set to true when an existing supplier invoice is returned
	 * @return int Invoice ID if OK, <0 if error
	 */
	private function createSupplierInvoice($zsInvoice, $lines, $targetEntity, &$reusedExisting = false)
	{
		global $user, $conf;
		$reusedExisting = false;

		// Normalize invoice data for reference building
		if (is_object($zsInvoice)) {
			$zsInvoice = (array) $zsInvoice;
		}

		// Get seller NIF from configuration
		$sellerEntity = $this->getSellerEntity();
		$sellerNif = $this->getEntityNIF($sellerEntity);

		if (empty($sellerNif)) {
			$this->error = "Seller entity $sellerEntity has no NIF configured in MAIN_INFO_TVAINTRA";
			dol_syslog(__METHOD__ . " - " . $this->error, LOG_ERR);
			return -1;
		}

		// Find supplier in target entity with seller's NIF
		$supplierId = $this->getSupplierByNIF($targetEntity, $sellerNif);

		if ($supplierId <= 0) {
			$this->error = "No supplier found in entity $targetEntity with NIF $sellerNif. Please create a supplier representing the seller.";
			dol_syslog(__METHOD__ . " - " . $this->error, LOG_ERR);
			return -1;
		}


		$targetEntity = (int) $targetEntity;
		$previousEntity = (int) $conf->entity;
		if (!$this->activateEntityConfiguration($targetEntity)) {
			return -1;
		}

		try {
			$invoiceDate = $this->resolveDestinationSupplierInvoiceDate($zsInvoice['data'] ?? '');
			if ($invoiceDate <= 0) {
				$this->error = "Invalid or implausible source invoice date";
				dol_syslog(__METHOD__ . " - " . $this->error, LOG_ERR);
				return -1;
			}

			// Create supplier invoice in destination entity context so numbering template is resolved per destination entity.
			$invoice = new FactureFournisseur($this->db);
			$invoice->entity = $targetEntity;
			$invoice->socid = $supplierId;
			$invoice->type = FactureFournisseur::TYPE_STANDARD;
			$invoice->date = $invoiceDate;
			$invoice->date_echeance = $invoice->date + (30 * 86400); // 30 days payment term

			// Build supplier reference
			$invoiceNumber = $this->buildSupplierInvoiceRefSupplier($zsInvoice);

			// Avoid duplicate creation when invoice already exists
			$existingInvoiceId = $this->findSupplierInvoiceByRefSupplier($invoiceNumber, $targetEntity, $supplierId);
			if ($existingInvoiceId > 0) {
				dol_syslog(__METHOD__ . " - Supplier invoice already exists with ref '$invoiceNumber' in entity $targetEntity (ID=$existingInvoiceId)", LOG_INFO);
				$reusedExisting = true;
				return $existingInvoiceId;
			}

			$invoice->ref_supplier = $invoiceNumber;
			$invoice->note_public = "Imported by KreaFlow - Inter-Entity Invoice Converter";
			if ($this->paymentTermId > 0) {
				$invoice->cond_reglement_id = $this->paymentTermId;
			}

			// Create invoice without lines first
			$invoiceId = $invoice->create($user);
			if ($invoiceId < 0) {
				$isDuplicateRefError = $this->isDuplicateSupplierInvoiceError($invoice);
				if ($isDuplicateRefError) {
					$existingInvoiceId = $this->findSupplierInvoiceByRefSupplier($invoiceNumber, $targetEntity, $supplierId);
					if ($existingInvoiceId > 0) {
						dol_syslog(__METHOD__ . " - Duplicate supplier ref detected, using existing invoice ID $existingInvoiceId", LOG_INFO);
						$reusedExisting = true;
						return $existingInvoiceId;
					}
				}

				$this->error = $invoice->error ?: 'Failed to create supplier invoice';
				$this->errors = $invoice->errors;
				if (!empty($this->db->lasterror())) {
					$this->error .= ' - ' . $this->db->lasterror();
				}
				return -1;
			}

			// Add lines
			$propertyStockService = new ZSPropertyStockService($this->db);
			foreach ($lines as $line) {
				// Debug: Log what we're about to add

				// FactureFournisseur::addline signature:
				// addline($desc, $pu, $txtva, $txlocaltax1, $txlocaltax2, $qty, $fk_product, $remise_percent, ...)
				// NOTE: Different from Facture::addline! VAT comes BEFORE quantity for supplier invoices
				$result = $invoice->addline(
					$line['desc'] ?? '',     // 1. description
					$line['subprice'],       // 2. pu - unit price excl. tax
					$line['tva_tx'],         // 3. txtva - VAT rate
					0,                       // 4. txlocaltax1
					0,                       // 5. txlocaltax2
					$line['qty'],            // 6. qty - quantity (POSITION 6, NOT 3!)
					$line['fk_product'],     // 7. fk_product
					$line['remise_percent'], // 8. remise_percent
					0,                       // 9. date_start
					0,                       // 10. date_end
					0,                       // 11. fk_code_ventilation
					0,                       // 12. info_bits
					$line['price_base_type'], // 13. source price mode
					$line['product_type'],   // 14. type
					-1,                      // 15. rang
					0,                       // 16. notrigger
					array(),                 // 17. array_options
					null,                    // 18. fk_unit
					0,                       // 19. origin_id
					0,                       // 20. pu_devise
					'',                      // 21. ref_supplier
					0,                       // 22. special_code
					0,                       // 23. fk_parent_line
					0                        // 24. fk_remise_except
				);

				if ($result < 0) {
					$this->error = $invoice->error;
					return -1;
				}
				if (!empty($line['property_stock_plan']) && !$propertyStockService->savePlan(
					$targetEntity,
					$invoiceId,
					(int) $result,
					$line['property_stock_plan'],
					(int) $user->id
				)) {
					$this->error = $propertyStockService->error;
					return -1;
				}
			}

			return $invoiceId;
		} finally {
			$this->activateEntityConfiguration($previousEntity);
		}
	}

	/**
	 * Verify an existing destination invoice without rewriting its lines.
	 *
	 * A destination created by another approved integration may already own the
	 * supplier reference. It can be linked only when the validated entity-scoped
	 * document, base currency, header, and line sums exactly match the source plan.
	 *
	 * @param int $invoiceId Existing supplier invoice ID
	 * @param int $targetEntity Target entity ID
	 * @param array $monetaryPlan Exact source monetary plan
	 * @return bool True when the existing invoice is safe to link
	 */
	private function verifyExistingSupplierInvoiceMonetaryPlan($invoiceId, $targetEntity, array $monetaryPlan)
	{
		$invoiceId = (int) $invoiceId;
		$targetEntity = (int) $targetEntity;
		if ($invoiceId <= 0 || $targetEntity <= 0 || empty($monetaryPlan['lines'])) {
			$this->error = 'Existing supplier invoice verification received an invalid target or monetary plan';
			return false;
		}

		$sql = "SELECT fk_statut, total_ht, total_tva, total_ttc, multicurrency_tx,";
		$sql .= " multicurrency_total_ht, multicurrency_total_tva, multicurrency_total_ttc";
		$sql .= " FROM " . MAIN_DB_PREFIX . "facture_fourn";
		$sql .= " WHERE rowid = " . $invoiceId;
		$sql .= " AND entity = " . $targetEntity;
		$sql .= " LIMIT 1 FOR UPDATE";
		$resql = $this->db->query($sql);
		$invoice = $resql ? $this->db->fetch_object($resql) : null;
		if (!$invoice) {
			$this->error = 'Existing supplier invoice is outside the destination entity';
			return false;
		}
		$status = (int) $invoice->fk_statut;
		if ($this->isSupplierInvoiceDraftStatus($status) || $this->isSupplierInvoiceCanceledStatus($status)) {
			$this->error = 'Existing supplier invoice is not in a validated active state';
			return false;
		}

		try {
			$currencyRate = ZSDecimal::normalize((string) ($invoice->multicurrency_tx ?? '1'));
		} catch (InvalidArgumentException $e) {
			$this->error = 'Existing supplier invoice has an invalid currency rate';
			return false;
		}
		if (ZSDecimal::compare($currencyRate, '1') !== 0) {
			$this->error = 'Existing supplier invoice is not in the source base currency';
			return false;
		}

		$sql = "SELECT COUNT(*) AS line_count,";
		$sql .= " COALESCE(SUM(total_ht), 0) AS total_ht,";
		$sql .= " COALESCE(SUM(tva), 0) AS total_tva,";
		$sql .= " COALESCE(SUM(total_ttc), 0) AS total_ttc,";
		$sql .= " COALESCE(SUM(multicurrency_total_ht), 0) AS multicurrency_total_ht,";
		$sql .= " COALESCE(SUM(multicurrency_total_tva), 0) AS multicurrency_total_tva,";
		$sql .= " COALESCE(SUM(multicurrency_total_ttc), 0) AS multicurrency_total_ttc,";
		$sql .= " SUM(CASE WHEN ROUND(total_ttc - total_ht - tva, 2) <> 0 THEN 1 ELSE 0 END) AS invalid_lines";
		$sql .= " FROM " . MAIN_DB_PREFIX . "facture_fourn_det";
		$sql .= " WHERE fk_facture_fourn = " . $invoiceId;
		$resql = $this->db->query($sql);
		$lineSums = $resql ? $this->db->fetch_object($resql) : null;
		if (!$lineSums || (int) $lineSums->line_count <= 0 || (int) $lineSums->invalid_lines !== 0) {
			$this->error = 'Existing supplier invoice lines are missing or do not satisfy HT plus VAT equals TTC';
			return false;
		}

		$expected = array(
			ZSDecimal::round((string) $monetaryPlan['total_ht'], 2),
			ZSDecimal::round((string) $monetaryPlan['total_tva'], 2),
			ZSDecimal::round((string) $monetaryPlan['total_ttc'], 2),
		);
		$header = array(
			ZSDecimal::round((string) $invoice->total_ht, 2),
			ZSDecimal::round((string) $invoice->total_tva, 2),
			ZSDecimal::round((string) $invoice->total_ttc, 2),
		);
		$headerMulticurrency = array(
			ZSDecimal::round((string) $invoice->multicurrency_total_ht, 2),
			ZSDecimal::round((string) $invoice->multicurrency_total_tva, 2),
			ZSDecimal::round((string) $invoice->multicurrency_total_ttc, 2),
		);
		$storedLines = array(
			ZSDecimal::round((string) $lineSums->total_ht, 2),
			ZSDecimal::round((string) $lineSums->total_tva, 2),
			ZSDecimal::round((string) $lineSums->total_ttc, 2),
		);
		$storedMulticurrencyLines = array(
			ZSDecimal::round((string) $lineSums->multicurrency_total_ht, 2),
			ZSDecimal::round((string) $lineSums->multicurrency_total_tva, 2),
			ZSDecimal::round((string) $lineSums->multicurrency_total_ttc, 2),
		);

		if ($header !== $expected || $storedLines !== $expected
			|| $headerMulticurrency !== $expected || $storedMulticurrencyLines !== $expected
			|| ZSDecimal::compare($header[2], ZSDecimal::add($header[0], $header[1])) !== 0) {
			$this->error = 'Existing supplier invoice header or line sums do not match the source monetary plan';
			return false;
		}

		return true;
	}

	/**
	 * Resolve the accounting date for the destination supplier invoice.
	 *
	 * ZoneSoft may expose the next business date before that calendar day starts. The source
	 * date remains preserved in inter-entity tracking, but a purchase and its stock movements
	 * must never be posted in the future.
	 *
	 * @param string $sourceDate ZoneSoft invoice date in Y-m-d format
	 * @return int Unix timestamp, or 0 when invalid
	 */
	private function resolveDestinationSupplierInvoiceDate($sourceDate)
	{
		$sourceDate = trim((string) $sourceDate);
		if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $sourceDate)) {
			return 0;
		}

		$sourceTimestamp = dol_stringtotime($sourceDate . ' 12:00:00', false);
		if ($sourceTimestamp <= 0 || dol_print_date($sourceTimestamp, '%Y-%m-%d', 'tzserver') !== $sourceDate) {
			return 0;
		}

		$today = dol_print_date(dol_now(), '%Y-%m-%d', 'tzserver');
		$oldestAllowedDate = dol_print_date(dol_now() - (366 * 86400), '%Y-%m-%d', 'tzserver');
		if ($sourceDate < $oldestAllowedDate) {
			return 0;
		}
		$effectiveDate = ($sourceDate > $today ? $today : $sourceDate);

		return (int) dol_stringtotime($effectiveDate . ' 12:00:00', false);
	}

	/**
	 * Build supplier invoice ref_supplier value from ZoneSoft invoice.
	 *
	 * @param array $zsInvoice
	 * @return string
	 */
	private function buildSupplierInvoiceRefSupplier($zsInvoice)
	{
		if (is_object($zsInvoice)) {
			$zsInvoice = (array) $zsInvoice;
		}

		$invoiceNumber = ($zsInvoice['doc'] ?? '') . ' ' . ($zsInvoice['serie'] ?? '') . '/' . ($zsInvoice['numero'] ?? '');

		// Handle special serie modification
		if (!empty($zsInvoice['serie']) && strpos($zsInvoice['serie'], '1A23Z1') !== false) {
			$invoiceNumber .= ' ' . date('Ymd', strtotime($zsInvoice['data']));
		}

		return trim($invoiceNumber);
	}

	/**
	 * Find an existing supplier invoice by ref_supplier within an entity.
	 *
	 * @param string $refSupplier
	 * @param int $entity
	 * @param int $supplierId Optional supplier id (fk_soc) to disambiguate references
	 * @return int Invoice ID if found, 0 otherwise
	 */
	private function findSupplierInvoiceByRefSupplier($refSupplier, $entity, $supplierId = 0)
	{
		$sql = "SELECT rowid";
		$sql .= " FROM " . MAIN_DB_PREFIX . "facture_fourn";
		$sql .= " WHERE ref_supplier = '" . $this->db->escape($refSupplier) . "'";
		$sql .= " AND entity = " . ((int) $entity);
		if ((int) $supplierId > 0) {
			$sql .= " AND fk_soc = " . ((int) $supplierId);
		}
		$sql .= " ORDER BY rowid DESC";
		$sql .= " LIMIT 1";

		$resql = $this->db->query($sql);
		if ($resql) {
			$obj = $this->db->fetch_object($resql);
			if ($obj) {
				return (int) $obj->rowid;
			}
		}

		return 0;
	}

	/**
	 * Detect duplicate reference errors when creating supplier invoices.
	 *
	 * @param FactureFournisseur|null $invoice
	 * @return bool
	 */
	private function isDuplicateSupplierInvoiceError($invoice = null)
	{
		$messages = array();

		if (is_object($invoice)) {
			if (!empty($invoice->error)) {
				$messages[] = (string) $invoice->error;
			}
			if (!empty($invoice->errors) && is_array($invoice->errors)) {
				foreach ($invoice->errors as $err) {
					$messages[] = (string) $err;
				}
			}
		}

		if (!empty($this->db->lasterror())) {
			$messages[] = (string) $this->db->lasterror();
		}

		foreach ($messages as $message) {
			$normalized = strtolower($message);
			if (
				strpos($normalized, 'errorrefalreadyexists') !== false
				|| strpos($normalized, 'db_error_record_already_exists') !== false
				|| strpos($normalized, 'duplicate entry') !== false
				|| strpos($normalized, 'uk_facture_fourn_ref_supplier') !== false
				|| strpos($normalized, 'already exists') !== false
			) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Reconcile supplier-invoice lines and header with the exact source plan.
	 *
	 * @param int $invoiceId Supplier invoice ID
	 * @param array $monetaryPlan Exact source monetary plan
	 * @param int $targetEntity Target entity
	 * @return int >0 if OK, 0 if no rounding needed, <0 if error
	 */
	private function handleRounding($invoiceId, $monetaryPlan, $targetEntity)
	{
		$targetEntity = (int) $targetEntity;
		$sql = "SELECT rowid FROM " . MAIN_DB_PREFIX . "facture_fourn";
		$sql .= " WHERE rowid = " . (int) $invoiceId;
		$sql .= " AND entity = " . $targetEntity;
		$sql .= " FOR UPDATE";
		$resql = $this->db->query($sql);
		if (!$resql || !$this->db->fetch_object($resql)) {
			$this->error = 'Supplier invoice reconciliation target is outside the destination entity';
			return -1;
		}
		$invoice = new FactureFournisseur($this->db);
		$result = $invoice->fetch($invoiceId);
		if ($result < 0 || (int) $invoice->entity !== $targetEntity) {
			$this->error = $invoice->error ?: 'Unable to fetch the supplier invoice in its destination entity';
			return -1;
		}
		if (!is_array($monetaryPlan) || empty($monetaryPlan['lines'])) {
			$this->error = 'The exact supplier-invoice monetary plan is missing';
			return -1;
		}
		try {
			$multicurrencyRate = ZSDecimal::normalize((string) ($invoice->multicurrency_tx ?? '1'));
		} catch (InvalidArgumentException $e) {
			$this->error = 'The supplier invoice contains an invalid currency conversion rate';
			return -1;
		}
		if (ZSDecimal::compare($multicurrencyRate, '1') !== 0) {
			$this->error = 'ZoneSoft monetary reconciliation currently supports base-currency supplier invoices only';
			return -1;
		}
		$roundingMode = ($monetaryPlan['rounding_method'] ?? '') === ZSInvoiceTaxCalculator::ROUND_VAT_GROUP ? '1' : '0';
		if ($invoice->update_price(1, $roundingMode) <= 0
			|| $invoice->fetch($invoiceId) <= 0
			|| $invoice->fetch_lines() < 0) {
			$this->error = $invoice->error ?: 'Dolibarr supplier-invoice rounding failed';
			return -1;
		}

		$sign = ((int) $invoice->type === FactureFournisseur::TYPE_CREDIT_NOTE) ? -1 : 1;
		$storedLines = array_values((array) $invoice->lines);
		$plannedLines = array_values((array) $monetaryPlan['lines']);
		if (count($storedLines) !== count($plannedLines)) {
			$this->error = 'Dolibarr supplier-invoice line count does not match the monetary plan';
			return -1;
		}
		foreach ($storedLines as $index => $line) {
			$planned = $plannedLines[$index];
			$line->total_ht = $sign < 0 ? ZSDecimal::negate($planned['total_ht']) : $planned['total_ht'];
			$line->total_tva = $sign < 0 ? ZSDecimal::negate($planned['total_tva']) : $planned['total_tva'];
			$line->total_ttc = $sign < 0 ? ZSDecimal::negate($planned['total_ttc']) : $planned['total_ttc'];
			$line->total_localtax1 = '0.00';
			$line->total_localtax2 = '0.00';
			if ($line->update_total() <= 0) {
				$this->error = $line->error ?: 'Unable to persist exact supplier-invoice line totals';
				return -1;
			}
			$sql = 'UPDATE ' . MAIN_DB_PREFIX . 'facture_fourn_det';
			$sql .= " SET multicurrency_total_ht = '" . $this->db->escape($line->total_ht) . "'";
			$sql .= ", multicurrency_total_tva = '" . $this->db->escape($line->total_tva) . "'";
			$sql .= ", multicurrency_total_ttc = '" . $this->db->escape($line->total_ttc) . "'";
			$sql .= ' WHERE rowid = ' . (int) ($line->id ?? $line->rowid);
			$sql .= ' AND fk_facture_fourn = ' . (int) $invoiceId;
			$sql .= ' AND EXISTS (SELECT 1 FROM ' . MAIN_DB_PREFIX . 'facture_fourn f';
			$sql .= ' WHERE f.rowid = ' . (int) $invoiceId;
			$sql .= ' AND f.entity = ' . $targetEntity . ')';
			if (!$this->db->query($sql)) {
				$this->error = 'Unable to persist exact supplier-invoice multicurrency line totals';
				return -1;
			}
		}
		if ($invoice->update_price(1, 'none') <= 0
			|| $invoice->fetch($invoiceId) <= 0
			|| $invoice->fetch_lines() < 0) {
			$this->error = $invoice->error ?: 'Unable to sum exact supplier-invoice line totals';
			return -1;
		}

		$expected = array(
			ZSDecimal::round($sign < 0 ? ZSDecimal::negate($monetaryPlan['total_ht']) : $monetaryPlan['total_ht'], 2),
			ZSDecimal::round($sign < 0 ? ZSDecimal::negate($monetaryPlan['total_tva']) : $monetaryPlan['total_tva'], 2),
			ZSDecimal::round($sign < 0 ? ZSDecimal::negate($monetaryPlan['total_ttc']) : $monetaryPlan['total_ttc'], 2),
		);
		$stored = array(
			ZSDecimal::round((string) $invoice->total_ht, 2),
			ZSDecimal::round((string) $invoice->total_tva, 2),
			ZSDecimal::round((string) $invoice->total_ttc, 2),
		);
		$storedMulticurrency = array(
			ZSDecimal::round((string) $invoice->multicurrency_total_ht, 2),
			ZSDecimal::round((string) $invoice->multicurrency_total_tva, 2),
			ZSDecimal::round((string) $invoice->multicurrency_total_ttc, 2),
		);
		$lineSums = array('0', '0', '0');
		$lineMulticurrencySums = array('0', '0', '0');
		foreach ((array) $invoice->lines as $line) {
			$lineSums[0] = ZSDecimal::add($lineSums[0], (string) $line->total_ht);
			$lineSums[1] = ZSDecimal::add($lineSums[1], (string) $line->total_tva);
			$lineSums[2] = ZSDecimal::add($lineSums[2], (string) $line->total_ttc);
			$lineMulticurrencySums[0] = ZSDecimal::add($lineMulticurrencySums[0], (string) $line->multicurrency_total_ht);
			$lineMulticurrencySums[1] = ZSDecimal::add($lineMulticurrencySums[1], (string) $line->multicurrency_total_tva);
			$lineMulticurrencySums[2] = ZSDecimal::add($lineMulticurrencySums[2], (string) $line->multicurrency_total_ttc);
		}
		$lineSums = array_map(function ($value) {
			return ZSDecimal::round($value, 2);
		}, $lineSums);
		$lineMulticurrencySums = array_map(function ($value) {
			return ZSDecimal::round($value, 2);
		}, $lineMulticurrencySums);
		if ($stored !== $expected || $lineSums !== $expected
			|| $storedMulticurrency !== $expected || $lineMulticurrencySums !== $expected
			|| ZSDecimal::compare($stored[2], ZSDecimal::add($stored[0], $stored[1])) !== 0) {
			$context = (array) ($monetaryPlan['source_context'] ?? array());
			$this->error = 'Supplier invoice lines/header do not match the source monetary plan; external_id=' . ($context['external_id'] ?? 'unknown')
				. '; invoice=' . ($context['invoice_number'] ?? 'unknown')
				. '; source=' . implode('/', $expected)
				. '; stored=' . implode('/', $stored)
				. '; line_sums=' . implode('/', $lineSums)
				. '; stored_multicurrency=' . implode('/', $storedMulticurrency)
				. '; line_multicurrency_sums=' . implode('/', $lineMulticurrencySums)
				. '; difference=' . ZSDecimal::subtract($stored[0], $expected[0]) . '/'
				. ZSDecimal::subtract($stored[1], $expected[1]) . '/'
				. ZSDecimal::subtract($stored[2], $expected[2]);
			return -1;
		}

		return 1;
	}

	/**
	 * Check whether final supplier invoice validation is enabled.
	 *
	 * The setup toggle was removed; existing stored constants must not re-enable
	 * automatic validation of generated supplier invoices.
	 *
	 * @return bool
	 */
	private function isFinalSupplierInvoiceValidationEnabled()
	{
		return false;
	}

	/**
	 * Validate supplier invoice as final task.
	 *
	 * Primary path: Dolibarr REST endpoint /api/index.php/supplierinvoices/{id}/validate
	 * (supplierinvoicesValidate operation from API explorer).
	 * Fallback path: native object validate() to keep conversion production-safe if API call cannot be used.
	 *
	 * @param int $invoiceId Supplier invoice ID
	 * @param int $targetEntity Buyer entity
	 * @return bool
	 */
	private function validateSupplierInvoiceFinalTask($invoiceId, $targetEntity)
	{
		$invoiceId = (int) $invoiceId;
		$targetEntity = (int) $targetEntity;

		if ($invoiceId <= 0 || $targetEntity <= 0) {
			$this->error = "Invalid invoice/entity for supplier invoice validation";
			return false;
		}

		if ($this->isSupplierInvoiceValidated($invoiceId, $targetEntity)) {
			return true;
		}

		$warehouseId = $this->getSupplierInvoiceValidationWarehouseId($targetEntity);
		if ($warehouseId <= 0) {
			dol_syslog(__METHOD__ . " - MAIN_DEFAULT_WAREHOUSE is not configured for entity {$targetEntity}. Supplier invoice {$invoiceId} will be validated without stock movement.", LOG_WARNING);
		}

		$apiHttpCode = 0;
		$apiResponseBody = '';
		if ($this->validateSupplierInvoiceWithApiEndpoint($invoiceId, $targetEntity, $warehouseId, $apiHttpCode, $apiResponseBody)) {
			return true;
		}

		$apiError = $this->error;
		if ($this->isSupplierInvoiceValidated($invoiceId, $targetEntity)) {
			dol_syslog(__METHOD__ . " - supplierinvoicesValidate returned non-success HTTP code {$apiHttpCode}, but invoice $invoiceId is now validated. Keeping as success.", LOG_WARNING);
			return true;
		}

		if ($this->validateSupplierInvoiceWithObjectFallback($invoiceId, $targetEntity, $warehouseId)) {
			dol_syslog(__METHOD__ . " - supplierinvoicesValidate endpoint unavailable/failed; used safe fallback validate() for invoice $invoiceId. API error: " . $apiError, LOG_WARNING);
			return true;
		}

		$fallbackError = $this->error;
		$this->error = trim($apiError . ' | Fallback validate() failed: ' . $fallbackError, " |");
		return false;
	}

	/**
	 * Validate supplier invoice through Dolibarr REST API endpoint.
	 *
	 * Endpoint: POST /api/index.php/supplierinvoices/{id}/validate
	 *
	 * @param int $invoiceId Supplier invoice ID
	 * @param int $targetEntity Buyer entity id
	 * @param int $warehouseId Warehouse used to generate stock movements on validation
	 * @param int $httpCode Output HTTP status code
	 * @param string $responseBody Output response body
	 * @return bool
	 */
	private function validateSupplierInvoiceWithApiEndpoint($invoiceId, $targetEntity, $warehouseId, &$httpCode, &$responseBody)
	{
		$httpCode = 0;
		$responseBody = '';

		if (!function_exists('curl_init')) {
			$this->error = "cURL extension is required for supplierinvoicesValidate endpoint call";
			return false;
		}

		$apiBaseUrl = $this->getDolibarrApiBaseUrl();
		if (empty($apiBaseUrl)) {
			$this->error = "Could not determine Dolibarr API base URL";
			return false;
		}

		$payload = array(
			'idwarehouse' => max(0, (int) $warehouseId),
			'notrigger' => 0,
		);
		$payloadJson = json_encode($payload);
		if (json_last_error() !== JSON_ERROR_NONE) {
			$this->error = "Failed to encode supplierinvoicesValidate payload: " . json_last_error_msg();
			return false;
		}

		$queryParams = array();
		if ((int) $targetEntity > 0) {
			$queryParams['DOLAPIENTITY'] = (int) $targetEntity;
		}

		$apiKey = $this->getDolibarrApiKeyForValidation();

		$url = rtrim($apiBaseUrl, '/') . '/supplierinvoices/' . ((int) $invoiceId) . '/validate';
		if (!empty($queryParams)) {
			$url .= '?' . http_build_query($queryParams, '', '&');
		}

		$headers = array(
			'Accept: application/json',
			'Content-Type: application/json',
		);
		if ($apiKey !== '') {
			$headers[] = 'DOLAPIKEY: ' . $apiKey;
		}
		if ((int) $targetEntity > 0) {
			$headers[] = 'DOLAPIENTITY: ' . ((int) $targetEntity);
		}
		$cookieHeader = $this->getCurrentSessionCookieHeader();
		if ($cookieHeader !== '') {
			$headers[] = 'Cookie: ' . $cookieHeader;
		}

		$curl = curl_init($url);
		if ($curl === false) {
			$this->error = "Unable to initialize cURL for supplier invoice validation";
			return false;
		}

		curl_setopt_array($curl, array(
			CURLOPT_POST => true,
			CURLOPT_POSTFIELDS => $payloadJson,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_HTTPHEADER => $headers,
			CURLOPT_TIMEOUT => 20,
			CURLOPT_CONNECTTIMEOUT => 5,
			CURLOPT_SSL_VERIFYHOST => 2,
			CURLOPT_SSL_VERIFYPEER => true,
		));

		$responseEncoded = curl_exec($curl);
		$curlErrno = (int) curl_errno($curl);
		$curlError = (string) curl_error($curl);
		$httpCode = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
		curl_close($curl);

		if ($responseEncoded === false) {
			$this->error = "supplierinvoicesValidate call failed (cURL errno {$curlErrno}): {$curlError}";
			return false;
		}

		$responseBody = (string) $responseEncoded;

		if ($httpCode >= 200 && $httpCode < 300) {
			return true;
		}

		$apiError = $this->extractApiErrorMessage($responseBody);
		$this->error = "supplierinvoicesValidate failed with HTTP {$httpCode}" . ($apiError !== '' ? ": {$apiError}" : '');
		return false;
	}

	/**
	 * Resolve Dolibarr API base URL.
	 *
	 * @return string
	 */
	private function getDolibarrApiBaseUrl()
	{
		$serverUrl = trim((string) getDolGlobalString('MAIN_SERVER_URL'));
		if ($serverUrl !== '') {
			$serverUrl = rtrim($serverUrl, '/');
			if (substr($serverUrl, -14) === '/api/index.php') {
				return $serverUrl;
			}
			return $serverUrl . '/api/index.php';
		}

		$host = $_SERVER['HTTP_HOST'] ?? '';
		if ($host === '') {
			return '';
		}

		$scheme = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') ? 'https' : 'http';
		return $scheme . '://' . $host . rtrim((string) DOL_URL_ROOT, '/') . '/api/index.php';
	}

	/**
	 * Get API key for supplier invoice validation endpoint.
	 *
	 * @return string
	 */
	private function getDolibarrApiKeyForValidation()
	{
		global $user;

		if (is_object($user) && !empty($user->api_key)) {
			return trim((string) $user->api_key);
		}

		return trim((string) getDolGlobalString('DOLIZSYNCH_DOLIBARR_API_KEY'));
	}

	/**
	 * Build current PHP session cookie header when available.
	 *
	 * @return string
	 */
	private function getCurrentSessionCookieHeader()
	{
		if (php_sapi_name() === 'cli') {
			return '';
		}

		$sessionId = (string) session_id();
		$sessionName = (string) session_name();
		if ($sessionId === '' || $sessionName === '') {
			return '';
		}

		return $sessionName . '=' . $sessionId;
	}

	/**
	 * Extract compact error message from API response body.
	 *
	 * @param string $responseBody
	 * @return string
	 */
	private function extractApiErrorMessage($responseBody)
	{
		$responseBody = trim((string) $responseBody);
		if ($responseBody === '') {
			return '';
		}

		$decoded = json_decode($responseBody, true);
		if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
			$candidates = array(
				$decoded['error']['message'] ?? '',
				$decoded['error']['code'] ?? '',
				$decoded['message'] ?? '',
				$decoded['error'] ?? '',
			);

			foreach ($candidates as $candidate) {
				if (is_scalar($candidate) && trim((string) $candidate) !== '') {
					return trim((string) $candidate);
				}
			}
		}

		$singleLine = preg_replace('/\s+/', ' ', $responseBody);
		if (strlen($singleLine) > 240) {
			$singleLine = substr($singleLine, 0, 240) . '...';
		}
		return $singleLine;
	}

	/**
	 * Fallback validation path using native object method.
	 *
	 * @param int $invoiceId
	 * @param int $targetEntity
	 * @param int $warehouseId
	 * @return bool
	 */
	private function validateSupplierInvoiceWithObjectFallback($invoiceId, $targetEntity, $warehouseId)
	{
		global $conf, $user;

		$targetEntity = (int) $targetEntity;
		$previousEntity = (int) $conf->entity;
		if (!$this->activateEntityConfiguration($targetEntity)) {
			return false;
		}

		try {
			$invoice = new FactureFournisseur($this->db);
			$fetchResult = $invoice->fetch((int) $invoiceId);
			if ($fetchResult <= 0) {
				$this->error = "Failed to fetch supplier invoice {$invoiceId} for fallback validation";
				return false;
			}

			if ((int) $invoice->statut > 0) {
				return true;
			}

			$validationResult = $this->validateSupplierInvoiceObject($invoice, $user, $warehouseId);
			if ($validationResult <= 0) {
				$this->error = $invoice->error ?: "Fallback supplier invoice validation failed";
				return false;
			}

			return true;
		} finally {
			$this->activateEntityConfiguration($previousEntity);
		}
	}

	/**
	 * Call supplier invoice validate() while remaining compatible with Dolibarr signatures.
	 *
	 * @param FactureFournisseur $invoice
	 * @param User $user
	 * @param int $warehouseId
	 * @return int
	 */
	private function validateSupplierInvoiceObject($invoice, $user, $warehouseId)
	{
		$warehouseId = (int) $warehouseId;

		try {
			$method = new ReflectionMethod($invoice, 'validate');
			$parameterCount = $method->getNumberOfParameters();

			if ($warehouseId > 0) {
				if ($parameterCount >= 3) {
					return $invoice->validate($user, '', $warehouseId);
				}
				if ($parameterCount >= 2) {
					return $invoice->validate($user, $warehouseId);
				}
			}
		} catch (ReflectionException $e) {
			dol_syslog(__METHOD__ . " - Reflection failed while resolving supplier invoice validate() signature: " . $e->getMessage(), LOG_WARNING);
		}

		return $invoice->validate($user);
	}

	/**
	 * Resolve default warehouse ID for supplier invoice validation in a target entity.
	 *
	 * @param int $targetEntity
	 * @return int
	 */
	private function getSupplierInvoiceValidationWarehouseId($targetEntity)
	{
		$targetEntity = (int) $targetEntity;
		if ($targetEntity <= 0) {
			return 0;
		}

		if (array_key_exists($targetEntity, $this->validationWarehouseByEntity)) {
			return (int) $this->validationWarehouseByEntity[$targetEntity];
		}

		$sql = "SELECT value";
		$sql .= " FROM " . MAIN_DB_PREFIX . "const";
		$sql .= " WHERE name = 'MAIN_DEFAULT_WAREHOUSE'";
		$sql .= " AND entity IN (" . $targetEntity . ", 0)";
		$sql .= " ORDER BY CASE WHEN entity = " . $targetEntity . " THEN 0 ELSE 1 END, rowid DESC";
		$sql .= " LIMIT 1";

		$warehouseId = 0;
		$resql = $this->db->query($sql);
		if ($resql) {
			$obj = $this->db->fetch_object($resql);
			if ($obj && is_numeric($obj->value)) {
				$warehouseId = max(0, (int) $obj->value);
			}
		} else {
			dol_syslog(__METHOD__ . " - Failed to fetch MAIN_DEFAULT_WAREHOUSE for entity {$targetEntity}: " . $this->db->lasterror(), LOG_ERR);
		}

		$this->validationWarehouseByEntity[$targetEntity] = $warehouseId;
		return $warehouseId;
	}

	/**
	 * Check if supplier invoice is already validated in target entity.
	 *
	 * @param int $invoiceId
	 * @param int $targetEntity
	 * @return bool
	 */
	private function isSupplierInvoiceValidated($invoiceId, $targetEntity)
	{
		$sql = "SELECT fk_statut";
		$sql .= " FROM " . MAIN_DB_PREFIX . "facture_fourn";
		$sql .= " WHERE rowid = " . ((int) $invoiceId);
		$sql .= " AND entity = " . ((int) $targetEntity);
		$sql .= " LIMIT 1";

		$resql = $this->db->query($sql);
		if (!$resql) {
			return false;
		}

		$obj = $this->db->fetch_object($resql);
		if (!$obj) {
			return false;
		}

		return ((int) $obj->fk_statut > 0);
	}

	/**
	 * Delete tracking record when post-commit validation fails.
	 *
	 * @param int $trackingId
	 * @param int $targetEntity
	 * @return bool
	 */
	private function deleteInvoiceTracking($trackingId, $targetEntity)
	{
		global $conf;

		$sql = "DELETE FROM " . MAIN_DB_PREFIX . "dolizsynch_interentity_invoice";
		$sql .= " WHERE rowid = " . ((int) $trackingId);
		$sql .= " AND target_entity = " . ((int) $targetEntity);
		$sql .= " AND entity = " . ((int) $conf->entity);
		$sql .= " LIMIT 1";

		$resql = $this->db->query($sql);
		if (!$resql) {
			dol_syslog(__METHOD__ . " - Failed to cleanup tracking row {$trackingId}: " . $this->db->lasterror(), LOG_ERR);
			return false;
		}

		return true;
	}

	/**
	 * Resolve product entities visible from a target entity.
	 *
	 * Multicompany can share buyer products from another entity (entity 1 in
	 * production). Product equivalence must follow Dolibarr's native product
	 * visibility scope instead of requiring product.entity = target entity.
	 *
	 * @param int $targetEntity Target/buyer entity ID
	 * @return array<int,int> Visible product entity IDs
	 */
	private function getTargetProductEntityIds($targetEntity)
	{
		global $conf;

		$targetEntity = (int) $targetEntity;
		if ($targetEntity <= 0) {
			return array(0);
		}

		$entityIds = array(0, $targetEntity);
		if (!function_exists('getEntity')) {
			return array_values(array_unique($entityIds));
		}

		$previousEntity = (int) $conf->entity;
		if (!$this->activateEntityConfiguration($targetEntity)) {
			throw new RuntimeException($this->error);
		}
		try {
			$rawScope = (string) getEntity('product');
		} finally {
			$this->activateEntityConfiguration($previousEntity);
		}

		foreach (explode(',', $rawScope) as $candidate) {
			$candidateId = (int) trim($candidate);
			if ($candidateId >= 0) {
				$entityIds[] = $candidateId;
			}
		}

		return array_values(array_unique($entityIds));
	}

	/**
	 * Return a SQL-safe product entity scope for a target entity.
	 *
	 * @param int $targetEntity Target/buyer entity ID
	 * @return string Comma-separated integer entity list
	 */
	private function getTargetProductEntitySql($targetEntity)
	{
		return implode(',', array_map('intval', $this->getTargetProductEntityIds($targetEntity)));
	}

	/**
	 * Get product equivalence from source to destination
	 * Simple 1:1 mapping - no target entity or conversion factor
	 *
	 * Uses four-stage lookup:
	 * 1. Primary: llx_dolizsynch_product_equivalence table (explicit mappings)
	 * 2. Fallback 2: llx_product_fournisseur_price table (ref_fourn mapping)
	 * 3. Fallback 3: Label-based matching with suffix replacement (configurable, disabled by default)
	 * 4. Fallback 4: The source product itself when it is natively shared and no buyer product exists
	 *
	 * @param string $sourceProductRef Source product reference (from ZoneSoft codigo field)
	 * @param int $targetEntity Target/buyer entity ID
	 * @return array|false Product info or false if not found
	 */
	private function getProductEquivalence($sourceProductRef, $targetEntity)
	{
		$cacheKey = $sourceProductRef . '_' . $targetEntity;

		if (isset($this->productEquivalenceCache[$cacheKey])) {
			return $this->productEquivalenceCache[$cacheKey];
		}

		// Resolve the source product through the seller entity's native product
		// visibility scope. Some ZoneSoft products are shared Dolibarr products.
		$sellerEntity = $this->getSellerEntity();
		$sellerProductEntitySql = $this->getTargetProductEntitySql($sellerEntity);
		$sql = "SELECT rowid, entity";
		$sql .= " FROM " . MAIN_DB_PREFIX . "product";
		$sql .= " WHERE ref = '" . $this->db->escape($sourceProductRef) . "'";
		$sql .= " AND entity IN (" . $sellerProductEntitySql . ")";
		$sql .= " ORDER BY (entity = " . ((int) $sellerEntity) . ") DESC, rowid DESC";
		$sql .= " LIMIT 2";


		$resql = $this->db->query($sql);
		if (!$resql) {
			dol_syslog(__METHOD__ . " - Database error: " . $this->db->lasterror(), LOG_ERR);
			return false;
		}

		$sourceCandidates = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$sourceCandidates[] = $obj;
		}
		if (empty($sourceCandidates)) {
			return false;
		}
		if (count($sourceCandidates) > 1
			&& (int) $sourceCandidates[0]->entity !== (int) $sellerEntity) {
			dol_syslog(__METHOD__ . " - Ambiguous shared source product ref '$sourceProductRef' for seller entity " . ((int) $sellerEntity), LOG_WARNING);
			return false;
		}

		$sourceProductId = (int) $sourceCandidates[0]->rowid;
		$sourceProductEntity = (int) $sourceCandidates[0]->entity;

		// Now get the equivalence record using source product ID
		$targetProductEntitySql = $this->getTargetProductEntitySql($targetEntity);
		$sql = "SELECT pe.fk_product_source, pe.fk_product_dest";
		$sql .= " FROM " . MAIN_DB_PREFIX . "dolizsynch_product_equivalence as pe";
		$sql .= " INNER JOIN " . MAIN_DB_PREFIX . "product as pd ON pd.rowid = pe.fk_product_dest";
		$sql .= " WHERE pe.fk_product_source = " . ((int) $sourceProductId);
		$sql .= " AND pe.active = 1";
		$sql .= " AND pe.entity IN (0, " . ((int) $sellerEntity) . ")";
		$sql .= " AND pd.entity IN (" . $targetProductEntitySql . ")";
		$sql .= " ORDER BY (pe.entity = " . ((int) $sellerEntity) . ") DESC, pe.rowid DESC";
		$sql .= " LIMIT 1";


		$resql = $this->db->query($sql);
		if ($resql) {
			$obj = $this->db->fetch_object($resql);
			if ($obj) {
				// Return the destination product info from equivalence table
				$result = array(
					'id' => $obj->fk_product_dest,
					'source_id' => $obj->fk_product_source
				);
				$this->productEquivalenceCache[$cacheKey] = $result;
				return $result;
			}
		}

		// No equivalence found in dolizsynch_product_equivalence table
		// Try fallback: Look in product_fournisseur_price table in BUYER entity
		// Logic: In buyer entity, look for products where ref_fourn matches the seller's product reference

		// Look in BUYER entity for supplier price records where ref_fourn = source product reference
		$sql = "SELECT pfp.fk_product, pfp.ref_fourn";
		$sql .= " FROM " . MAIN_DB_PREFIX . "product_fournisseur_price as pfp";
		$sql .= " WHERE pfp.ref_fourn = '" . $this->db->escape($sourceProductRef) . "'";
		$sql .= " AND pfp.entity IN (" . ((int) $targetEntity) . ", 0)"; // Check buyer entity and shared entity
		$sql .= " LIMIT 1";


		$resql = $this->db->query($sql);
		if (!$resql) {
			dol_syslog(__METHOD__ . " - Database error on fallback lookup: " . $this->db->lasterror(), LOG_ERR);
			return false;
		}

		$obj = $this->db->fetch_object($resql);
		if (!$obj || empty($obj->fk_product)) {
			return $this->getLabelOrSharedProductFallback($sourceProductId, $sourceProductEntity, $sourceProductRef, $targetEntity, $cacheKey);
		}

		$destProductId = $obj->fk_product;

		// Verify the product exists in buyer entity
		$sql = "SELECT rowid, ref";
		$sql .= " FROM " . MAIN_DB_PREFIX . "product";
		$sql .= " WHERE rowid = " . ((int) $destProductId);
		$sql .= " AND entity IN (" . $targetProductEntitySql . ")";
		$sql .= " ORDER BY (entity = " . ((int) $targetEntity) . ") DESC, rowid DESC";
		$sql .= " LIMIT 1";

		$resql = $this->db->query($sql);
		if (!$resql) {
			dol_syslog(__METHOD__ . " - Database error verifying product: " . $this->db->lasterror(), LOG_ERR);
			return false;
		}

		$obj = $this->db->fetch_object($resql);
		if (!$obj) {
			return $this->getLabelOrSharedProductFallback($sourceProductId, $sourceProductEntity, $sourceProductRef, $targetEntity, $cacheKey);
		}

		// Found via fallback 2!
		$result = array(
			'id' => $obj->rowid,
			'source_id' => $sourceProductId
		);
		$this->productEquivalenceCache[$cacheKey] = $result;
		dol_syslog(__METHOD__ . " - FALLBACK 2 SUCCESS: Seller product ref '$sourceProductRef' (ID $sourceProductId) -> Buyer product '{$obj->ref}' (ID {$obj->rowid}) via ref_fourn", LOG_INFO);
		return $result;
	}

	/**
	 * Prefer a buyer-visible product match before reusing the source product.
	 *
	 * @param int $sourceProductId Source product ID
	 * @param int $sourceProductEntity Source product entity
	 * @param string $sourceProductRef Source product reference
	 * @param int $targetEntity Target/buyer entity
	 * @param string $cacheKey Product-equivalence cache key
	 * @return array|false Product info or false if not found
	 */
	private function getLabelOrSharedProductFallback($sourceProductId, $sourceProductEntity, $sourceProductRef, $targetEntity, $cacheKey)
	{
		$result = $this->getProductByLabelFallback($sourceProductId, $sourceProductRef, $targetEntity, $cacheKey);
		if ($result !== false) {
			return $result;
		}

		// The source product itself is the final fallback only. A destination-owned
		// supplier-reference or label match must always take precedence.
		if (!in_array((int) $sourceProductEntity, $this->getTargetProductEntityIds($targetEntity), true)) {
			return false;
		}

		$result = array(
			'id' => (int) $sourceProductId,
			'source_id' => (int) $sourceProductId,
		);
		$this->productEquivalenceCache[$cacheKey] = $result;
		dol_syslog(__METHOD__ . " - SHARED PRODUCT SUCCESS: Source product ref '$sourceProductRef' (ID $sourceProductId, entity $sourceProductEntity) is visible in target entity " . ((int) $targetEntity), LOG_INFO);

		return $result;
	}

	/**
	 * Find one unambiguous product by label in the target entity's native product scope.
	 *
	 * A target-owned product takes precedence over shared products. Shared matches are
	 * accepted only when exactly one candidate remains, so an ambiguous label fails closed.
	 *
	 * @param string $label Product label to match
	 * @param int $targetEntity Target/buyer entity ID
	 * @param int $sourceProductId Source product ID to exclude
	 * @return object|false Product row or false when missing/ambiguous
	 */
	private function findUniqueVisibleProductByLabel($label, $targetEntity, $sourceProductId)
	{
		$sql = "SELECT rowid, ref, label, entity";
		$sql .= " FROM " . MAIN_DB_PREFIX . "product";
		$sql .= " WHERE label = '" . $this->db->escape($label) . "'";
		$sql .= " AND entity IN (" . $this->getTargetProductEntitySql($targetEntity) . ")";
		$sql .= " AND rowid <> " . ((int) $sourceProductId);
		$sql .= " ORDER BY (entity = " . ((int) $targetEntity) . ") DESC, rowid DESC";
		$sql .= " LIMIT 2";

		$resql = $this->db->query($sql);
		if (!$resql) {
			dol_syslog(__METHOD__ . " - Database error searching visible products: " . $this->db->lasterror(), LOG_ERR);
			return false;
		}

		$candidates = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$candidates[] = $obj;
		}
		$this->db->free($resql);

		if (empty($candidates)) {
			return false;
		}

		if (count($candidates) === 1) {
			return $candidates[0];
		}
		if ((int) $candidates[0]->entity === (int) $targetEntity
			&& (int) $candidates[1]->entity !== (int) $targetEntity) {
			return $candidates[0];
		}

		dol_syslog(__METHOD__ . " - Ambiguous shared product label '" . $label . "' for target entity " . ((int) $targetEntity), LOG_WARNING);
		return false;
	}

	/**
	 * Third fallback: Try label-based matching in the target product visibility scope.
	 * This method is called after primary and ref_fourn fallbacks fail.
	 *
	 * @param int $sourceProductId Source product ID in seller entity
	 * @param string $sourceProductRef Source product reference
	 * @param int $targetEntity Target/buyer entity ID
	 * @param string $cacheKey Cache key for storing result
	 * @return array|false Product info or false if not found
	 */
	private function getProductByLabelFallback($sourceProductId, $sourceProductRef, $targetEntity, $cacheKey)
	{
		// Check if label-based fallback is enabled
		if (!getDolGlobalString('DOLIZSYNCH_INTERENTITY_LABEL_FALLBACK_ENABLED')) {
			return false;
		}

		// Get suffix configuration
		$sellerSuffix = getDolGlobalString('DOLIZSYNCH_INTERENTITY_SELLER_SUFFIX', "'RV");
		$buyerSuffix = getDolGlobalString('DOLIZSYNCH_INTERENTITY_BUYER_SUFFIX', "'CL");

		$sellerEntity = $this->getSellerEntity();

		// Get the seller product label
		$sql = "SELECT label";
		$sql .= " FROM " . MAIN_DB_PREFIX . "product";
		$sql .= " WHERE rowid = " . ((int) $sourceProductId);
		$sql .= " AND entity = " . ((int) $sellerEntity);
		$sql .= " LIMIT 1";

		$resql = $this->db->query($sql);
		if (!$resql) {
			dol_syslog(__METHOD__ . " - Database error getting seller product label: " . $this->db->lasterror(), LOG_ERR);
			return false;
		}

		$obj = $this->db->fetch_object($resql);
		if (!$obj || empty($obj->label)) {
			return false;
		}

		$sellerLabel = $obj->label;

		$candidateLabels = array();
		if ($sellerSuffix !== '' && $buyerSuffix !== '') {
			$sellerSuffixLen = strlen($sellerSuffix);
			if ($sellerSuffixLen > 0 && substr($sellerLabel, -$sellerSuffixLen) === $sellerSuffix) {
				$candidateLabels['converted'] = substr($sellerLabel, 0, -$sellerSuffixLen) . $buyerSuffix;
			}
		}
		$candidateLabels['exact'] = $sellerLabel;

		foreach ($candidateLabels as $matchType => $candidateLabel) {
			$obj = $this->findUniqueVisibleProductByLabel($candidateLabel, $targetEntity, $sourceProductId);
			if (!$obj) {
				continue;
			}

			$result = array(
				'id' => $obj->rowid,
				'source_id' => $sourceProductId
			);
			$this->productEquivalenceCache[$cacheKey] = $result;
			dol_syslog(__METHOD__ . " - FALLBACK 3 SUCCESS ($matchType label): Seller product '$sourceProductRef' (label: '$sellerLabel') -> visible buyer product '{$obj->ref}' (label: '{$obj->label}', ID {$obj->rowid}, entity {$obj->entity})", LOG_INFO);
			return $result;
		}

		return false;
	}

	/**
	 * Get target entity by customer NIF
	 * Uses MAIN_INFO_TVAINTRA configuration to map NIF to entity
	 *
	 * @param string $nif Customer NIF/Tax ID (with or without PT prefix)
	 * @return int Entity ID or <0 if not found
	 */
	private function getTargetEntityByNIF($nif)
	{
		// Skip common non-NIF values (retail/cash sales)
		$invalidNifs = ['Consumidor final', 'consumidor final', 'CONSUMIDOR FINAL', '999999990', '999999999', 'N/A', 'n/a'];
		if (in_array($nif, $invalidNifs)) {
			dol_syslog(__METHOD__ . " - Skipping invoice with non-company NIF: $nif (retail/cash sale)", LOG_INFO);
			return -1;
		}

		// Normalize NIF - remove PT prefix if exists
		$normalizedNif = str_replace('PT', '', $nif);

		if (isset($this->storeMappingCache['nif_' . $normalizedNif])) {
			return $this->storeMappingCache['nif_' . $normalizedNif];
		}

		// Search for entity with this NIF in MAIN_INFO_TVAINTRA configuration
		$sql = "SELECT entity";
		$sql .= " FROM " . MAIN_DB_PREFIX . "const";
		$sql .= " WHERE name = 'MAIN_INFO_TVAINTRA'";
		$sql .= " AND (value = '" . $this->db->escape($normalizedNif) . "'";
		$sql .= " OR value = 'PT" . $this->db->escape($normalizedNif) . "')";
		$sql .= " LIMIT 1";

		$resql = $this->db->query($sql);
		if ($resql) {
			$obj = $this->db->fetch_object($resql);
			if ($obj) {
				$entityId = $obj->entity;

				// Validate that this entity is in the configured target entities list
				if (!$this->isValidTargetEntity($entityId)) {
					dol_syslog(__METHOD__ . " - Entity $entityId found for NIF $normalizedNif, but not in configured target entities", LOG_WARNING);
					return -1;
				}

				$this->storeMappingCache['nif_' . $normalizedNif] = $entityId;
				return $entityId;
			}
		}

		return -1;
	}

	/**
	 * Check if entity is in the configured target entities list
	 *
	 * @param int $entityId Entity ID to validate
	 * @return bool True if valid target entity
	 */
	private function isValidTargetEntity($entityId)
	{
		global $conf;

		$targetEntitiesConfig = getDolGlobalString('DOLIZSYNCH_INTERENTITY_TARGET_ENTITIES');

		// If no configuration, allow all entities (backward compatibility)
		if (empty($targetEntitiesConfig)) {
			return true;
		}

		// Parse comma-separated list
		$targetEntities = array_map('trim', explode(',', $targetEntitiesConfig));

		return in_array((string)$entityId, $targetEntities);
	}

	/**
	 * Get entity NIF from MAIN_INFO_TVAINTRA configuration
	 *
	 * @param int $entity Entity ID
	 * @return string|false NIF without PT prefix, or false if not found
	 */
	private function getEntityNIF($entity)
	{
		$sql = "SELECT value";
		$sql .= " FROM " . MAIN_DB_PREFIX . "const";
		$sql .= " WHERE name = 'MAIN_INFO_TVAINTRA'";
		$sql .= " AND entity = " . ((int) $entity);
		$sql .= " LIMIT 1";


		$resql = $this->db->query($sql);
		if ($resql) {
			$obj = $this->db->fetch_object($resql);
			if ($obj && !empty($obj->value)) {
				// Remove all letters (PT, pt, Pt, etc.) and spaces
				$normalized = preg_replace('/[^0-9]/', '', $obj->value);
				return $normalized;
			}
		}

		return false;
	}

	/**
	 * Get supplier ID by seller NIF
	 * Suppliers are shared across entities in multicompany, so we don't filter by entity
	 *
	 * @param int $targetEntity Target entity ID (not used in query, kept for logging)
	 * @param string $sellerNif Seller NIF (without PT prefix)
	 * @return int Supplier ID or <0 if not found
	 */
	private function getSupplierByNIF($targetEntity, $sellerNif)
	{
		// Normalize NIF - remove all variations of PT prefix and spaces
		$normalizedNif = trim(str_replace(array('PT', 'pt', 'Pt', ' '), '', $sellerNif));


		// Search in tva_intra field (VAT number) using REPLACE to handle all variations: an optional country prefix and whitespace.
		// Note: Suppliers are shared across entities, so we don't filter by entity
		$sql = "SELECT rowid, nom, tva_intra, entity";
		$sql .= " FROM " . MAIN_DB_PREFIX . "societe";
		$sql .= " WHERE fournisseur = 1";
		$sql .= " AND REPLACE(REPLACE(REPLACE(REPLACE(tva_intra, 'PT', ''), 'pt', ''), 'Pt', ''), ' ', '') = '" . $this->db->escape($normalizedNif) . "'";
		$sql .= " LIMIT 1";


		$resql = $this->db->query($sql);
		if ($resql) {
			$obj = $this->db->fetch_object($resql);
			if ($obj) {
				dol_syslog(__METHOD__ . " - Found supplier: ID={$obj->rowid}, Name={$obj->nom}, VAT={$obj->tva_intra}, Entity={$obj->entity}", LOG_INFO);
				return $obj->rowid;
			}
		}

		dol_syslog(__METHOD__ . " - No supplier found with normalized NIF: $normalizedNif", LOG_WARNING);
		return -1;
	}

	/**
	 * Get seller entity (always the current entity)
	 * The seller entity is where customer invoices are created
	 *
	 * @return int Current entity ID or <0 if error
	 */
	private function getSellerEntity()
	{
		global $conf;

		// Seller entity is always the current entity
		$sellerEntity = (int) $conf->entity;

		if ($sellerEntity <= 0) {
			$this->error = "Invalid current entity.";
			return -1;
		}

		return $sellerEntity;
	}

	/**
	 * Check if invoice already processed
	 *
	 * @param string $invoiceRef Invoice reference
	 * @param int $targetEntity Target entity
	 * @param array $zsInvoice ZoneSoft invoice data
	 * @return bool True if already processed or safely re-linked
	 */
	private function isInvoiceAlreadyProcessed($invoiceRef, $targetEntity, $zsInvoice)
	{
		$sellerEntity = $this->getSellerEntity();
		$sql = "SELECT ie.rowid, ff.rowid as destination_id";
		$sql .= " FROM " . MAIN_DB_PREFIX . "dolizsynch_interentity_invoice as ie";
		$sql .= " LEFT JOIN " . MAIN_DB_PREFIX . "facture_fourn as ff";
		$sql .= " ON ff.rowid = ie.fk_facture_fourn_dest";
		$sql .= " AND ff.entity = " . ((int) $targetEntity);
		$sql .= " WHERE ie.source_invoice_ref = '" . $this->db->escape($invoiceRef) . "'";
		$sql .= " AND ie.target_entity = " . ((int) $targetEntity);
		$sql .= " AND ie.source_entity = " . ((int) $sellerEntity);
		$sql .= " AND ie.entity = " . ((int) $sellerEntity);
		$sql .= " ORDER BY ie.rowid DESC LIMIT 1 FOR UPDATE";

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = 'Unable to lock inter-entity invoice tracking: ' . $this->db->lasterror();
			throw new RuntimeException($this->error);
		}

		$obj = $this->db->fetch_object($resql);
		if (!$obj) {
			return false;
		}
		if (!empty($obj->destination_id)) {
			return true;
		}

		dol_syslog(__METHOD__ . " - Tracking row " . ((int) $obj->rowid) . " has no entity-scoped destination supplier invoice; reconciling " . $invoiceRef, LOG_WARNING);

		$sellerNif = $this->getEntityNIF($sellerEntity);
		$supplierId = $sellerNif !== '' ? $this->getSupplierByNIF($targetEntity, $sellerNif) : 0;
		$refSupplier = $this->buildSupplierInvoiceRefSupplier($zsInvoice);
		$replacementInvoiceId = $supplierId > 0
			? $this->findSupplierInvoiceByRefSupplier($refSupplier, $targetEntity, $supplierId)
			: 0;
		if ($replacementInvoiceId <= 0) {
			return false;
		}

		$sql = "SELECT total_ttc FROM " . MAIN_DB_PREFIX . "facture_fourn";
		$sql .= " WHERE rowid = " . ((int) $replacementInvoiceId);
		$sql .= " AND entity = " . ((int) $targetEntity);
		$sql .= " LIMIT 1 FOR UPDATE";
		$resql = $this->db->query($sql);
		$replacement = $resql ? $this->db->fetch_object($resql) : null;
		if (!$replacement) {
			$this->error = 'Unable to verify replacement supplier invoice for stale tracking';
			throw new RuntimeException($this->error);
		}

		$sourceTotal = ZSDecimal::round((string) ($zsInvoice['total'] ?? ''), 2);
		$destinationTotal = ZSDecimal::round((string) $replacement->total_ttc, 2);
		if (ZSDecimal::compare($sourceTotal, $destinationTotal) !== 0) {
			$this->error = "Replacement supplier invoice {$replacementInvoiceId} total {$destinationTotal} does not match source {$sourceTotal}";
			throw new RuntimeException($this->error);
		}

		if ($this->saveInvoiceTracking($zsInvoice, $replacementInvoiceId, $targetEntity) < 0) {
			throw new RuntimeException($this->error ?: 'Unable to repair stale inter-entity tracking');
		}

		dol_syslog(__METHOD__ . " - Re-linked stale tracking row " . ((int) $obj->rowid) . " to supplier invoice " . $replacementInvoiceId, LOG_WARNING);
		return true;
	}

	/**
	 * Save invoice tracking record
	 *
	 * @param array $zsInvoice ZoneSoft invoice data
	 * @param int $invoiceId Created supplier invoice ID
	 * @param int $targetEntity Target entity
	 * @return int Record ID if OK, <0 if error
	 */
	private function saveInvoiceTracking($zsInvoice, $invoiceId, $targetEntity)
	{
		global $user;

		$sellerEntity = $this->getSellerEntity();
		$invoiceRef = $zsInvoice['doc'] . '_' . $zsInvoice['serie'] . '_' . $zsInvoice['numero'] . '_' .
			date('Ymd', strtotime($zsInvoice['data']));
		$invoiceNumber = $zsInvoice['doc'] . ' ' . $zsInvoice['serie'] . '/' . $zsInvoice['numero'];
		$sourceInvoiceId = $this->findSourceCustomerInvoiceIdByInvoiceRef($invoiceRef, $sellerEntity);
		$destInvoiceRef = $this->getSupplierInvoiceDisplayRef($invoiceId, $targetEntity);

		$now = dol_now();
		$table = MAIN_DB_PREFIX . "dolizsynch_interentity_invoice";
		$sql = "SELECT rowid, entity, source_entity FROM " . $table;
		$sql .= " WHERE source_invoice_ref = '" . $this->db->escape($invoiceRef) . "'";
		$sql .= " AND target_entity = " . ((int) $targetEntity);
		$sql .= " LIMIT 1 FOR UPDATE";
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = 'Unable to lock inter-entity tracking before persistence: ' . $this->db->lasterror();
			return -1;
		}
		$existingTracking = $this->db->fetch_object($resql);
		if ($existingTracking) {
			if ((int) $existingTracking->entity !== (int) $sellerEntity
				|| (int) $existingTracking->source_entity !== (int) $sellerEntity) {
				$this->error = 'Inter-entity tracking identity is already owned by another source entity';
				return -1;
			}

			$sql = "UPDATE " . $table . " SET";
			$sql .= " source_invoice_number = '" . $this->db->escape($invoiceNumber) . "',";
			$sql .= " source_invoice_date = '" . $this->db->idate(strtotime($zsInvoice['data'])) . "',";
			$sql .= " fk_facture_source = " . ($sourceInvoiceId > 0 ? (int) $sourceInvoiceId : "NULL") . ",";
			$sql .= " fk_facture_fourn_dest = " . ((int) $invoiceId) . ",";
			$sql .= " dest_invoice_ref = '" . $this->db->escape($destInvoiceRef) . "',";
			$sql .= " zs_doc_type = '" . $this->db->escape($zsInvoice['doc']) . "',";
			$sql .= " zs_serie = '" . $this->db->escape($zsInvoice['serie']) . "',";
			$sql .= " zs_numero = '" . $this->db->escape($zsInvoice['numero']) . "',";
			$sql .= " zs_contribuinte = '" . $this->db->escape($zsInvoice['contribuinte'] ?? '') . "',";
			$sql .= " zs_total = " . $this->db->escape(ZSDecimal::round((string) $zsInvoice['total'], 4)) . ",";
			$sql .= " synch = 1, synch_date = '" . $this->db->idate($now) . "',";
			$sql .= " error_message = NULL, retry_count = 0,";
			$sql .= " processed = 1, process_date = '" . $this->db->idate($now) . "',";
			$sql .= " fk_user_modif = " . ((int) $user->id);
			$sql .= " WHERE rowid = " . ((int) $existingTracking->rowid);
			$sql .= " AND entity = " . ((int) $sellerEntity);
			$sql .= " AND source_entity = " . ((int) $sellerEntity);
			$sql .= " AND target_entity = " . ((int) $targetEntity);
			if (!$this->db->query($sql)) {
				$this->error = $this->db->lasterror();
				return -1;
			}

			return (int) $existingTracking->rowid;
		}

		$sql = "INSERT INTO " . $table . " (";
		$sql .= " entity, source_entity, source_invoice_ref, source_invoice_number,";
		$sql .= " source_invoice_date, fk_facture_source, target_entity, fk_facture_fourn_dest, dest_invoice_ref,";
		$sql .= " zs_doc_type, zs_serie, zs_numero, zs_contribuinte, zs_total,";
		$sql .= " synch, synch_date, processed, process_date,";
		$sql .= " date_creation, fk_user_creat";
		$sql .= ") VALUES (";
		$sql .= " " . $sellerEntity . ",";
		$sql .= " " . $sellerEntity . ",";
		$sql .= " '" . $this->db->escape($invoiceRef) . "',";
		$sql .= " '" . $this->db->escape($invoiceNumber) . "',";
		$sql .= " '" . $this->db->idate(strtotime($zsInvoice['data'])) . "',";
		$sql .= " " . ($sourceInvoiceId > 0 ? (int) $sourceInvoiceId : "NULL") . ",";
		$sql .= " " . $targetEntity . ",";
		$sql .= " " . $invoiceId . ",";
		$sql .= " '" . $this->db->escape($destInvoiceRef) . "',";
		$sql .= " '" . $this->db->escape($zsInvoice['doc']) . "',";
		$sql .= " '" . $this->db->escape($zsInvoice['serie']) . "',";
		$sql .= " '" . $this->db->escape($zsInvoice['numero']) . "',";
		$sql .= " '" . $this->db->escape($zsInvoice['contribuinte'] ?? '') . "',";
		$sql .= " " . $this->db->escape(ZSDecimal::round((string) $zsInvoice['total'], 4)) . ",";
		$sql .= " 1,"; // synch = 1 (success)
		$sql .= " '" . $this->db->idate($now) . "',";
		$sql .= " 1,"; // processed = 1
		$sql .= " '" . $this->db->idate($now) . "',";
		$sql .= " '" . $this->db->idate($now) . "',";
		$sql .= " " . $user->id;
		$sql .= ")";

		$resql = $this->db->query($sql);
		if ($resql) {
			return $this->db->last_insert_id(MAIN_DB_PREFIX . "dolizsynch_interentity_invoice");
		}

		$this->error = $this->db->lasterror();
		return -1;
	}

	/**
	 * Save a tracking record for a canceled source invoice.
	 *
	 * @param array $zsInvoice ZoneSoft invoice data
	 * @param int $invoiceId Destination supplier invoice ID, or 0 when none exists
	 * @param int $targetEntity Target entity
	 * @param string $message Private tracking note
	 * @return int Record ID if OK, <0 if error
	 */
	private function saveCancellationTracking($zsInvoice, $invoiceId, $targetEntity, $message)
	{
		global $user;

		if (is_object($zsInvoice)) {
			$zsInvoice = (array) $zsInvoice;
		}

		$sellerEntity = $this->getSellerEntity();
		$invoiceRef = $zsInvoice['doc'] . '_' . $zsInvoice['serie'] . '_' . $zsInvoice['numero'] . '_' .
			date('Ymd', strtotime($zsInvoice['data']));
		$invoiceNumber = $zsInvoice['doc'] . ' ' . $zsInvoice['serie'] . '/' . $zsInvoice['numero'];
		$sourceInvoiceId = $this->findSourceCustomerInvoiceIdByInvoiceRef($invoiceRef, $sellerEntity);
		$destInvoiceRef = ((int) $invoiceId > 0) ? $this->getSupplierInvoiceDisplayRef($invoiceId, $targetEntity) : '';
		$now = dol_now();
		$userId = (is_object($user) && !empty($user->id)) ? (int) $user->id : 0;

		$sql = "INSERT INTO " . MAIN_DB_PREFIX . "dolizsynch_interentity_invoice (";
		$sql .= " entity, source_entity, source_invoice_ref, source_invoice_number,";
		$sql .= " source_invoice_date, fk_facture_source, target_entity, fk_facture_fourn_dest, dest_invoice_ref,";
		$sql .= " zs_doc_type, zs_serie, zs_numero, zs_contribuinte, zs_total,";
		$sql .= " synch, synch_date, processed, process_date,";
		$sql .= " date_creation, fk_user_creat, note_private";
		$sql .= ") VALUES (";
		$sql .= " " . ((int) $sellerEntity) . ",";
		$sql .= " " . ((int) $sellerEntity) . ",";
		$sql .= " '" . $this->db->escape($invoiceRef) . "',";
		$sql .= " '" . $this->db->escape($invoiceNumber) . "',";
		$sql .= " '" . $this->db->idate(strtotime($zsInvoice['data'])) . "',";
		$sql .= " " . ($sourceInvoiceId > 0 ? (int) $sourceInvoiceId : "NULL") . ",";
		$sql .= " " . ((int) $targetEntity) . ",";
		$sql .= " " . ((int) $invoiceId > 0 ? (int) $invoiceId : "NULL") . ",";
		$sql .= " '" . $this->db->escape($destInvoiceRef) . "',";
		$sql .= " '" . $this->db->escape($zsInvoice['doc']) . "',";
		$sql .= " '" . $this->db->escape($zsInvoice['serie']) . "',";
		$sql .= " '" . $this->db->escape($zsInvoice['numero']) . "',";
		$sql .= " '" . $this->db->escape($zsInvoice['contribuinte'] ?? '') . "',";
		$sql .= " " . $this->db->escape(ZSDecimal::round((string) ($zsInvoice['total'] ?? '0'), 4)) . ",";
		$sql .= " 1,";
		$sql .= " '" . $this->db->idate($now) . "',";
		$sql .= " 1,";
		$sql .= " '" . $this->db->idate($now) . "',";
		$sql .= " '" . $this->db->idate($now) . "',";
		$sql .= " " . ($userId > 0 ? $userId : "NULL") . ",";
		$sql .= " '" . $this->db->escape($message) . "'";
		$sql .= ")";

		$resql = $this->db->query($sql);
		if ($resql) {
			return $this->db->last_insert_id(MAIN_DB_PREFIX . "dolizsynch_interentity_invoice");
		}

		$this->error = $this->db->lasterror();
		dol_syslog(__METHOD__ . " - Failed to save cancellation tracking for {$invoiceRef}: " . $this->error, LOG_ERR);
		return -1;
	}

	/**
	 * Update inter-entity tracking status after cancellation handling.
	 *
	 * @param int $trackingId Tracking row ID
	 * @param int $sourceEntity Source entity
	 * @param int $targetEntity Target entity
	 * @param int $destinationInvoiceId Destination supplier invoice ID, or 0 when deleted/missing
	 * @param int $synch Synchronization status
	 * @param string $message Audit/error message
	 * @return bool True on success
	 */
	private function updateInterEntityTrackingStatus($trackingId, $sourceEntity, $targetEntity, $destinationInvoiceId, $synch, $message)
	{
		global $user;

		$userId = (is_object($user) && !empty($user->id)) ? (int) $user->id : 0;

		$sql = "UPDATE " . MAIN_DB_PREFIX . "dolizsynch_interentity_invoice";
		$sql .= " SET fk_facture_fourn_dest = " . ((int) $destinationInvoiceId > 0 ? (int) $destinationInvoiceId : "NULL");
		$sql .= ", synch = " . ((int) $synch);
		$sql .= ", synch_date = NOW()";
		$sql .= ", processed = 1";
		$sql .= ", process_date = NOW()";
		$sql .= ", fk_user_modif = " . ($userId > 0 ? $userId : "NULL");
		$sql .= ", note_private = '" . $this->db->escape($message) . "'";
		$sql .= ", error_message = " . ((int) $synch < 0 ? "'" . $this->db->escape($message) . "'" : "NULL");
		$sql .= " WHERE rowid = " . ((int) $trackingId);
		$sql .= " AND source_entity = " . ((int) $sourceEntity);
		$sql .= " AND target_entity = " . ((int) $targetEntity);
		$sql .= " AND entity = " . ((int) $sourceEntity);
		$sql .= " LIMIT 1";

		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			dol_syslog(__METHOD__ . " - Failed to update inter-entity tracking row {$trackingId}: " . $this->error, LOG_ERR);
			return false;
		}

		return true;
	}

	/**
	 * Find the source customer invoice linked to the ZoneSoft reference.
	 *
	 * @param string $invoiceRef Source invoice reference
	 * @param int $sourceEntity Source entity
	 * @return int Customer invoice ID or 0
	 */
	private function findSourceCustomerInvoiceIdByInvoiceRef($invoiceRef, $sourceEntity)
	{
		$sql = "SELECT f.rowid";
		$sql .= " FROM " . MAIN_DB_PREFIX . "dolizsynch_zsinvoicesynch as zs";
		$sql .= " INNER JOIN " . MAIN_DB_PREFIX . "facture as f ON f.rowid = zs.fk_facture";
		$sql .= " WHERE zs.invoice_ref = '" . $this->db->escape($invoiceRef) . "'";
		$sql .= " AND zs.entity = " . ((int) $sourceEntity);
		$sql .= " AND f.entity = " . ((int) $sourceEntity);
		$sql .= " AND zs.fk_facture > 0";
		$sql .= " LIMIT 1";

		$resql = $this->db->query($sql);
		if ($resql) {
			$obj = $this->db->fetch_object($resql);
			if ($obj) {
				return (int) $obj->rowid;
			}
		} else {
			dol_syslog(__METHOD__ . " - Failed to find source invoice from sync table: " . $this->db->lasterror(), LOG_WARNING);
		}

		$sql = "SELECT f.rowid";
		$sql .= " FROM " . MAIN_DB_PREFIX . "facture as f";
		$sql .= " INNER JOIN " . MAIN_DB_PREFIX . "facture_extrafields as fe ON fe.fk_object = f.rowid";
		$sql .= " WHERE fe.invoice_number = '" . $this->db->escape($invoiceRef) . "'";
		$sql .= " AND f.entity = " . ((int) $sourceEntity);
		$sql .= " LIMIT 1";

		$resql = $this->db->query($sql);
		if ($resql) {
			$obj = $this->db->fetch_object($resql);
			if ($obj) {
				return (int) $obj->rowid;
			}
		} else {
			dol_syslog(__METHOD__ . " - Failed to find source invoice from extrafields: " . $this->db->lasterror(), LOG_WARNING);
		}

		return 0;
	}

	/**
	 * Return a display reference for a supplier invoice in its entity.
	 *
	 * @param int $invoiceId Supplier invoice ID
	 * @param int $targetEntity Target entity
	 * @return string Supplier reference or Dolibarr reference
	 */
	private function getSupplierInvoiceDisplayRef($invoiceId, $targetEntity)
	{
		$sql = "SELECT ref, ref_supplier";
		$sql .= " FROM " . MAIN_DB_PREFIX . "facture_fourn";
		$sql .= " WHERE rowid = " . ((int) $invoiceId);
		$sql .= " AND entity = " . ((int) $targetEntity);
		$sql .= " LIMIT 1";

		$resql = $this->db->query($sql);
		if (!$resql) {
			dol_syslog(__METHOD__ . " - Failed to fetch supplier invoice reference: " . $this->db->lasterror(), LOG_WARNING);
			return '';
		}

		$obj = $this->db->fetch_object($resql);
		if (!$obj) {
			return '';
		}

		return !empty($obj->ref_supplier) ? (string) $obj->ref_supplier : (string) $obj->ref;
	}

	/**
	 * Check if inter-entity invoice conversion is enabled
	 *
	 * @return bool True if enabled
	 */
	private function isEnabledForCurrentEntity()
	{
		global $conf;

		// Check if enabled - feature can be used from any entity
		// The seller entity configuration determines which entity's invoices will be processed
		return !empty($conf->global->DOLIZSYNCH_INTERENTITY_INVOICE_ENABLED);
	}
}

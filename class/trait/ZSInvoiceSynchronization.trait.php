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
 * \file        class/trait/ZSInvoiceSynchronization.trait.php
 * \ingroup     dolizsynch
 * \brief       Invoice synchronization methods for ZSInvoiceSynch
 */

/**
 * Trait ZSInvoiceSynchronization
 *
 * Handles invoice synchronization operations:
 * - Save invoice headers
 * - Check if invoice already saved
 * - Set synchronization status
 * - Check if invoice is imported
 */
trait ZSInvoiceSynchronization
{
	/**
	 * Save invoice headers to the database
	 *
	 * @param object $invoiceHeaders Invoice headers object from ZoneSoft
	 * @return bool True on success, false on failure
	 */
	public function saveInvoiceHeaders($invoiceHeaders)
	{
		global $user, $db, $conf;

		$operation = __METHOD__;
		$actor = ($user instanceof User) ? $user : null;
		if (method_exists($this, 'resolveInvoiceSyncActorUser')) {
			$resolvedActor = $this->resolveInvoiceSyncActorUser($operation);
			if ($resolvedActor instanceof User) {
				$actor = $resolvedActor;
			}
		}
		$actorId = ($actor instanceof User && !empty($actor->id)) ? (int) $actor->id : 0;
		if ($actorId <= 0) {
			$this->logOperationError($operation, "No valid actor user resolved for invoice header persistence", [
				'current_entity' => (int) ($conf->entity ?? 1)
			]);
			return false;
		}

		$configuredStoreId = $this->getConfiguredInvoiceStoreId();
		if ($configuredStoreId <= 0) {
			$this->logOperationError($operation, 'ZS_API_STORE must identify one positive numeric store for the active entity');
			return false;
		}

		if (!is_object($invoiceHeaders) || !isset($invoiceHeaders->document)) {
			$this->logOperationError($operation, "Invalid invoice headers data", [
				'is_object' => is_object($invoiceHeaders),
				'has_document' => isset($invoiceHeaders->document)
			]);
			return false;
		}

		$document = $invoiceHeaders->document;

		if (!is_array($document) && !($document instanceof Traversable)) {
			$this->logOperationError($operation, "Invoice headers document is not iterable", [
				'type' => gettype($document)
			]);
			return false;
		}

		$documentCount = (is_array($document) || $document instanceof Countable) ? count($document) : null;

		// If there are simply no headers to save, treat as a successful no-op
		if ($documentCount === 0) {
			$this->logDebug($operation, "No invoice headers to save", ['total' => 0]);
			return true;
		}

		$savedCount = 0;
		$skippedCount = 0;
		$foreignStoreCount = 0;
		$errorCount = 0;

		foreach ($invoiceHeaders->document as $zsInvoice) {
			if (!$this->isInvoiceHeaderForStore($zsInvoice, $configuredStoreId)) {
				$skippedCount++;
				$foreignStoreCount++;
				$this->logOperationWarning($operation, 'Rejected invoice header outside the active entity store scope', [
					'configured_store' => $configuredStoreId,
					'header_store' => (string) ($zsInvoice->loja ?? ''),
					'doc_type' => (string) ($zsInvoice->doc ?? ''),
					'serie' => (string) ($zsInvoice->serie ?? ''),
					'numero' => (string) ($zsInvoice->numero ?? ''),
				]);
				continue;
			}

			if ($this->isExcludedZsbmsDocumentType($zsInvoice->doc ?? '')) {
				$skippedCount++;
				$this->logDebug($operation, "Skipping excluded ZSBMS document header", [
					'doc_type' => dolizsynch_normalize_zsbms_document_type($zsInvoice->doc ?? ''),
					'serie' => (string) ($zsInvoice->serie ?? ''),
					'numero' => (string) ($zsInvoice->numero ?? ''),
					'data' => (string) ($zsInvoice->data ?? ''),
					'config_key' => 'DOLIZSYNCH_EXCLUDED_ZSBMS_DOCUMENT_TYPES'
				]);
				continue;
			}

			// Format: FT_9A2401_15202_20240710
			$invoice_ref = $zsInvoice->doc . '_' . $zsInvoice->serie . '_' . $zsInvoice->numero . '_' . date('Ymd', strtotime($zsInvoice->data));
			$normalizedHeaderDate = null;
			$headerTs = strtotime((string) ($zsInvoice->data ?? ''));
			if ($headerTs !== false) {
				$normalizedHeaderDate = date('Y-m-d', $headerTs);
			}

			if (!$this->acquireInvoiceRefLock($invoice_ref, 10)) {
				$skippedCount++;
				$this->logDebug($operation, "Could not acquire invoice lock, skipping header for retry", [
					'invoice_ref' => $invoice_ref
				]);
				continue;
			}

			try {
				$otherEntityRef = $this->findInvoiceRefInOtherEntity($invoice_ref);
				if (!empty($otherEntityRef) && isset($otherEntityRef->entity)) {
					$this->logDebug($operation, "Invoice ref exists in another entity; continuing with current-entity header persistence", [
						'invoice_ref' => $invoice_ref,
						'other_entity' => (int) $otherEntityRef->entity
					]);
				}

				// Check if already saved
				if ($this->isInvoiceAlreadySaved($invoice_ref)) {
					$skippedCount++;
					$this->syncExistingHeaderWithImportedInvoice($invoice_ref, $zsInvoice->data ?? null, $zsInvoice->total ?? null);

					// Check if canceled
					if ($zsInvoice->anulado == 1) {
						$this->handleCanceledInvoice($invoice_ref, $db);
					}

					continue;
				}

				// Set invoice data
				$this->setInvoiceData($zsInvoice, $invoice_ref);
				$this->fk_user_creat = $actorId;
				$this->fk_user_modif = $actorId;

				// Reset object-level error state before each insert attempt
				$this->error = '';
				$this->errors = array();

				// Create sync record
				$resultSync = $this->create($actor);

				if ($resultSync < 0) {
					if ($this->isDuplicateSyncRecordError($db)) {
						$skippedCount++;
						$this->syncExistingHeaderWithImportedInvoice($invoice_ref, $zsInvoice->data ?? null, $zsInvoice->total ?? null);

						// If a duplicate record already exists and invoice is canceled, keep cancellation state in sync
						if (!empty($zsInvoice->anulado) && (int) $zsInvoice->anulado === 1 && $this->isInvoiceAlreadySaved($invoice_ref)) {
							$this->handleCanceledInvoice($invoice_ref, $db);
						}

						$this->logDebug($operation, "Skipping duplicate invoice header", [
							'invoice_ref' => $invoice_ref,
							'error' => $this->error
						]);
					} else {
						$errorCount++;
						$this->logOperationError($operation, "Failed to create sync record", [
							'invoice_ref' => $invoice_ref,
							'error' => $this->error
						]);
					}
				} else {
					$savedCount++;
				}
			} finally {
				$this->releaseInvoiceRefLock($invoice_ref);
			}
		}

		$this->logOperationSuccess($operation, [
			'total' => count($invoiceHeaders->document),
			'saved' => $savedCount,
			'skipped' => $skippedCount,
			'foreign_store_rejected' => $foreignStoreCount,
			'errors' => $errorCount
		]);

		return $errorCount === 0;
	}

	/**
	 * Check whether the last sync record creation failure was due to duplicate key/reference.
	 *
	 * @param DoliDB|null $db Database object (optional, used to inspect last SQL error)
	 * @return bool
	 */
	private function isDuplicateSyncRecordError($db = null)
	{
		$errorMessages = array();

		if (!empty($this->error)) {
			$errorMessages[] = (string) $this->error;
		}

		if (!empty($this->errors) && is_array($this->errors)) {
			foreach ($this->errors as $error) {
				$errorMessages[] = (string) $error;
			}
		}

		if ($db && method_exists($db, 'lasterror')) {
			$sqlError = (string) $db->lasterror();
			if ($sqlError !== '') {
				$errorMessages[] = $sqlError;
			}
		}

		foreach ($errorMessages as $message) {
			$normalized = strtolower($message);
			if (
				strpos($normalized, 'errorrefalreadyexists') !== false
				|| strpos($normalized, 'db_error_record_already_exists') !== false
				|| strpos($normalized, 'duplicate entry') !== false
				|| strpos($normalized, 'already exists') !== false
			) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Find an invoice reference that already exists in another entity.
	 *
	 * @param string $invoiceRef
	 * @return object|null
	 */
	private function findInvoiceRefInOtherEntity($invoiceRef)
	{
		global $db, $conf;

		$sql = "SELECT rowid, entity, fk_facture, synch";
		$sql .= " FROM " . MAIN_DB_PREFIX . "dolizsynch_zsinvoicesynch";
		$sql .= " WHERE invoice_ref = '" . $db->escape($invoiceRef) . "'";
		$sql .= " AND entity <> " . ((int) $conf->entity);
		$sql .= " ORDER BY rowid DESC";
		$sql .= " LIMIT 1";

		$resql = $db->query($sql);
		if (!$resql) {
			$this->logSqlError(__METHOD__, $db, ['invoice_ref' => $invoiceRef]);
			return null;
		}

		$obj = $db->fetch_object($resql);
		return $obj ?: null;
	}

	/**
	 * Check whether current DB backend supports MySQL advisory locks.
	 *
	 * @param DoliDB|null $db
	 * @return bool
	 */
	private function supportsInvoiceRefLocks($db = null)
	{
		if (!is_object($db)) {
			return false;
		}

		$className = strtolower(get_class($db));
		return (strpos($className, 'mysql') !== false || strpos($className, 'mysqli') !== false);
	}

	/**
	 * Build lock name for one invoice reference.
	 *
	 * @param string $invoiceRef
	 * @return string
	 */
	private function getInvoiceRefLockName($invoiceRef)
	{
		global $conf;

		$entity = (int) ($conf->entity ?? 1);
		return 'dzs_invref_' . $entity . '_' . substr(md5((string) $invoiceRef), 0, 24);
	}

	/**
	 * Acquire per-invoice lock to avoid concurrent duplicate inserts.
	 *
	 * @param string $invoiceRef
	 * @param int $timeout Seconds
	 * @return bool
	 */
	private function acquireInvoiceRefLock($invoiceRef, $timeout = 2)
	{
		global $db;

		if (!$this->supportsInvoiceRefLocks($db)) {
			return true;
		}

		$lockName = $this->getInvoiceRefLockName($invoiceRef);
		$sql = "SELECT GET_LOCK('" . $db->escape($lockName) . "', " . ((int) $timeout) . ") AS lock_ok";
		$resql = $db->query($sql);
		if (!$resql) {
			$this->logSqlError(__METHOD__, $db, [
				'invoice_ref' => $invoiceRef,
				'lock_name' => $lockName
			]);
			return false;
		}

		$obj = $db->fetch_object($resql);

		if (!$obj || !isset($obj->lock_ok)) {
			$this->logOperationError(__METHOD__, "GET_LOCK returned no result row", [
				'invoice_ref' => $invoiceRef,
				'lock_name' => $lockName
			]);
			return false;
		}

		if ($obj->lock_ok === null) {
			// NULL means an error occurred (e.g. lock name too long or internal MySQL error)
			$this->logOperationError(__METHOD__, "GET_LOCK returned NULL (internal lock error)", [
				'invoice_ref' => $invoiceRef,
				'lock_name' => $lockName
			]);
			return false;
		}

		if ((int) $obj->lock_ok === 0) {
			// 0 means the timeout elapsed and another connection still holds the lock
			$this->logDebug(__METHOD__, "GET_LOCK timed out waiting for lock", [
				'invoice_ref' => $invoiceRef,
				'lock_name' => $lockName,
				'timeout' => $timeout
			]);
			return false;
		}

		return true;
	}

	/**
	 * Release per-invoice lock.
	 *
	 * @param string $invoiceRef
	 * @return void
	 */
	private function releaseInvoiceRefLock($invoiceRef)
	{
		global $db;

		if (!$this->supportsInvoiceRefLocks($db)) {
			return;
		}

		$lockName = $this->getInvoiceRefLockName($invoiceRef);
		$sql = "SELECT RELEASE_LOCK('" . $db->escape($lockName) . "') AS released";
		$resql = $db->query($sql);
		if ($resql) {
			$obj = $db->fetch_object($resql);
			if ($obj && $obj->released === null) {
				// NULL means the named lock does not exist — likely already released or never acquired
				$this->logOperationError(__METHOD__, "RELEASE_LOCK returned NULL: lock did not exist", [
					'invoice_ref' => $invoiceRef,
					'lock_name' => $lockName
				]);
			}
		}
	}

	/**
	 * If invoice already exists in Dolibarr, mark the existing header as synchronized.
	 *
	 * @param string $invoiceRef Invoice reference
	 * @param string|null $invoiceDate Invoice date (optional)
	 * @param string|null $sourceTtc Authoritative gross total (optional)
	 * @return bool True when imported invoice exists (header update may or may not be needed)
	 */
	private function syncExistingHeaderWithImportedInvoice($invoiceRef, $invoiceDate = null, $sourceTtc = null)
	{
		global $db, $conf;

		$normalizedDate = null;
		if ($invoiceDate !== null && trim((string) $invoiceDate) !== '') {
			$timestamp = strtotime((string) $invoiceDate);
			if ($timestamp !== false) {
				$normalizedDate = date('Y-m-d', $timestamp);
			}
		}

		$existingFactureId = (int) $this->isInvoiceExists($invoiceRef, $normalizedDate);
		if ($existingFactureId <= 0) {
			return false;
		}

		$targetStatus = ZSSyncStatus::OK;
		$monetaryError = null;
		$facture = new Facture($db);
		if ($facture->fetch($existingFactureId) <= 0) {
			return false;
		}
		if ((int) $facture->statut === Facture::STATUS_DRAFT) {
			// Keep draft invoices in pending sync so they can be validated by sync flow.
			$targetStatus = ZSSyncStatus::NOT_SYNCHRONIZED;
		} elseif (!$this->verifyStoredCustomerInvoiceConsistency($facture, $sourceTtc)) {
			$targetStatus = ZSSyncStatus::FAILED;
			$monetaryError = $this->error;
		}

		$sql = "UPDATE " . MAIN_DB_PREFIX . "dolizsynch_zsinvoicesynch";
		$sql .= " SET synch = " . $targetStatus . ",";
		$sql .= " fk_facture = " . $existingFactureId . ",";
		$sql .= ' error_message = ' . ($monetaryError === null ? 'NULL' : "'" . $db->escape($monetaryError) . "'") . ',';
		$sql .= " date_modification = NOW()";
		$sql .= " WHERE invoice_ref = '" . $db->escape($invoiceRef) . "'";
		$sql .= " AND entity = " . ((int) $conf->entity);
		$sql .= " AND (synch <> " . $targetStatus . " OR fk_facture IS NULL OR fk_facture <= 0)";

		$resql = $db->query($sql);
		if (!$resql) {
			$this->logSqlError(__METHOD__, $db, [
				'invoice_ref' => $invoiceRef,
				'fk_facture' => $existingFactureId
			]);
			return true;
		}

		if ($db->affected_rows($resql) > 0) {
			$this->logDebug(__METHOD__, "Header sync state refreshed from existing Dolibarr invoice", [
				'invoice_ref' => $invoiceRef,
				'fk_facture' => $existingFactureId,
				'synch' => $targetStatus
			]);
		}

		return true;
	}

	/**
	 * Handle canceled invoice during header save
	 *
	 * @param string $invoice_ref Invoice reference
	 * @param DoliDB $db Database object
	 * @return void
	 */
	private function handleCanceledInvoice($invoice_ref, $db)
	{
		global $conf;

		$operation = __METHOD__;

		// Query to get fk_facture
		$sql  = "SELECT fk_facture ";
		$sql .= "FROM " . MAIN_DB_PREFIX . "dolizsynch_zsinvoicesynch ";
		$sql .= "WHERE invoice_ref = '" . $db->escape($invoice_ref) . "' ";
		$sql .= "AND entity = " . ((int) $conf->entity) . " ";
		$sql .= "ORDER BY fk_facture DESC ";
		$sql .= "LIMIT 1";

		$resql = $db->query($sql);

		if (!$resql) {
			$this->logSqlError($operation, $db, [
				'invoice_ref' => $invoice_ref,
				'operation' => 'fetch_fk_facture'
			]);
			return;
		}

		$invoiceIdObj = $db->fetch_object($resql);

		if (!$invoiceIdObj) {
			return;
		}

		$invoiceId = (int) ($invoiceIdObj->fk_facture ?? 0);

		// Always mark canceled headers as needing cancellation processing.
		$sqlUpdate  = "UPDATE " . MAIN_DB_PREFIX . "dolizsynch_zsinvoicesynch ";
		$sqlUpdate .= "SET anulado_zs = 1, synch = " . ZSSyncStatus::CANCELED_NEEDS_CREDIT . ", date_modification = NOW() ";
		$sqlUpdate .= "WHERE invoice_ref = '" . $db->escape($invoice_ref) . "' ";
		$sqlUpdate .= "AND entity = " . ((int) $conf->entity);
		if (!$db->query($sqlUpdate)) {
			$this->logSqlError($operation, $db, [
				'invoice_ref' => $invoice_ref,
				'fk_facture' => $invoiceId,
				'operation' => 'mark_as_canceled_needs_processing'
			]);
			return;
		}

		$this->logDebug($operation, "Marked invoice as canceled from headers", [
			'invoice_ref' => $invoice_ref,
			'fk_facture' => $invoiceId,
			'synch' => ZSSyncStatus::CANCELED_NEEDS_CREDIT
		]);

		// If the invoice already exists in Dolibarr, try to cancel it immediately.
		if ($invoiceId > 0) {
			$cancelResult = $this->fixCanceledInvoiceByFkFacture($invoiceId);
			if (!empty($cancelResult['success'])) {
				$sqlFinalize  = "UPDATE " . MAIN_DB_PREFIX . "dolizsynch_zsinvoicesynch ";
				$sqlFinalize .= "SET anulado_zs = 1, stock_reversed = 1, synch = " . ZSSyncStatus::OK . ", date_modification = NOW() ";
				$sqlFinalize .= "WHERE invoice_ref = '" . $db->escape($invoice_ref) . "' ";
				$sqlFinalize .= "AND entity = " . ((int) $conf->entity);
				if (!$db->query($sqlFinalize)) {
					$this->logSqlError($operation, $db, [
						'invoice_ref' => $invoice_ref,
						'fk_facture' => $invoiceId,
						'operation' => 'finalize_immediate_cancellation'
					]);
					return;
				}

				$this->logDebug($operation, "Canceled invoice immediately after header sync", [
					'invoice_ref' => $invoice_ref,
					'fk_facture' => $invoiceId
				]);
			} else {
				$this->logOperationWarning($operation, "Immediate cancellation failed; invoice left queued for cancellation retry", [
					'invoice_ref' => $invoice_ref,
					'fk_facture' => $invoiceId,
					'message' => is_array($cancelResult) ? (string) ($cancelResult['message'] ?? '') : ''
				]);
			}
		}
	}

	/**
	 * Resolve the number of people from ZoneSoft invoice payload.
	 *
	 * Supports detailed payload shape (`documentos_pessoas.pessoas`) and
	 * a direct `pessoas` field when present in header payloads.
	 *
	 * @param mixed $zsInvoice
	 * @return int|null
	 */
	private function resolveInvoicePeopleCount($zsInvoice)
	{
		$pessoasValue = null;

		if (is_object($zsInvoice) && isset($zsInvoice->documentos_pessoas)) {
			$pessoasValue = $this->extractPeopleValueFromPayload($zsInvoice->documentos_pessoas);
		} elseif (is_array($zsInvoice) && array_key_exists('documentos_pessoas', $zsInvoice)) {
			$pessoasValue = $this->extractPeopleValueFromPayload($zsInvoice['documentos_pessoas']);
		}

		if ($pessoasValue === null || $pessoasValue === '') {
			$pessoasValue = $this->extractPeopleValueFromPayload($zsInvoice);
		}

		if ($pessoasValue === null || $pessoasValue === '') {
			return null;
		}

		if (is_numeric($pessoasValue)) {
			return (int) $pessoasValue;
		}

		$normalized = trim((string) $pessoasValue);
		if ($normalized === '' || !is_numeric($normalized)) {
			return null;
		}

		return (int) $normalized;
	}

	/**
	 * Extract scalar `pessoas` value from object/array payload recursively.
	 *
	 * @param mixed $payload
	 * @return mixed|null
	 */
	private function extractPeopleValueFromPayload($payload)
	{
		if (is_object($payload)) {
			if (isset($payload->pessoas) && !is_array($payload->pessoas) && !is_object($payload->pessoas)) {
				return $payload->pessoas;
			}
			$payload = get_object_vars($payload);
		}

		if (!is_array($payload)) {
			return null;
		}

		if (array_key_exists('pessoas', $payload) && !is_array($payload['pessoas']) && !is_object($payload['pessoas'])) {
			return $payload['pessoas'];
		}

		foreach ($payload as $value) {
			if (!is_array($value) && !is_object($value)) {
				continue;
			}

			$nested = $this->extractPeopleValueFromPayload($value);
			if ($nested !== null && $nested !== '') {
				return $nested;
			}
		}

		return null;
	}

	/**
	 * Set invoice data from ZoneSoft invoice object
	 *
	 * @param object $zsInvoice ZoneSoft invoice
	 * @param string $invoice_ref Generated invoice reference
	 * @return void
	 */
	private function setInvoiceData($zsInvoice, $invoice_ref)
	{
		global $conf;

		$this->entity = (int) ($conf->entity ?? 1);
		$this->doc = $zsInvoice->doc;
		$this->doc_num = $zsInvoice->numero;
		$this->doc_serie = $zsInvoice->serie;
		$this->invoice_ref = $invoice_ref;
		$this->contribuinte_zs = $zsInvoice->contribuinte;
		$this->countrycode_zs = $zsInvoice->countrycode;
		$this->data_zs = $zsInvoice->data;
		$this->datahora_zs = $zsInvoice->datahora;
		$this->liquido_zs = $zsInvoice->liquido;
		$this->total_zs = $zsInvoice->total;
		$this->pagamento_zs = $zsInvoice->pagamento;
		$this->pago_zs = $zsInvoice->pago;
		$this->anulado_zs = is_null($zsInvoice->anulado) ? 0 : $zsInvoice->anulado;
		$this->loja_zs = $zsInvoice->loja;
		$this->tipo_zs = is_null($zsInvoice->tipo) ? 0 : $zsInvoice->tipo;
		$this->mesa_zs = $zsInvoice->mesa;
		$this->emp_zs = $zsInvoice->emp;
		$peopleCount = $this->resolveInvoicePeopleCount($zsInvoice);
		$this->pessoas_zs = ($peopleCount === null ? 1 : $peopleCount);
		$this->synch = ZSSyncStatus::NOT_SYNCHRONIZED;
	}

	/**
	 * Check if invoice is already saved in sync table
	 *
	 * @param string $invoice_ref Invoice reference
	 * @return bool True if already saved, false otherwise
	 */
	private function isInvoiceAlreadySaved($invoice_ref)
	{
		global $db, $conf;

		$sql  = "SELECT COUNT(*) as count ";
		$sql .= "FROM " . MAIN_DB_PREFIX . "dolizsynch_zsinvoicesynch ";
		$sql .= "WHERE invoice_ref = '" . $db->escape($invoice_ref) . "' ";
		$sql .= "AND entity = " . ((int) $conf->entity);

		$resql = $db->query($sql);

		if ($resql) {
			$obj = $db->fetch_object($resql);
			return ($obj->count > 0);
		} else {
			$this->logSqlError(__METHOD__, $db, [
				'invoice_ref' => $invoice_ref
			]);
			return false;
		}
	}

	/**
	 * Check if invoice has been imported to Dolibarr
	 *
	 * @param string $invoiceRef Invoice reference
	 * @return bool True if imported and synchronized, false otherwise
	 */
	public function isInvoiceImported($invoiceRef)
	{
		global $db, $conf;

		$operation = __METHOD__;

		$sql  = "SELECT synch, fk_facture ";
		$sql .= "FROM " . MAIN_DB_PREFIX . "dolizsynch_zsinvoicesynch ";
		$sql .= "WHERE invoice_ref = '" . $db->escape($invoiceRef) . "' ";
		$sql .= "AND entity = " . ((int) $conf->entity);

		$resql = $db->query($sql);

		if ($resql) {
			$obj = $db->fetch_object($resql);

			if ($obj) {
				$isImported = ($obj->synch == ZSSyncStatus::OK && $obj->fk_facture > 0);

				$this->logDebug($operation, "Invoice import status checked", [
					'invoice_ref' => $invoiceRef,
					'is_imported' => $isImported,
					'sync_status' => $obj->synch,
					'fk_facture' => $obj->fk_facture
				]);

				return $isImported;
			}

			$this->logDebug($operation, "Invoice not found in sync table", [
				'invoice_ref' => $invoiceRef
			]);

			return false;
		} else {
			$this->logSqlError($operation, $db, [
				'invoice_ref' => $invoiceRef
			]);
			return false;
		}
	}

	/**
	 * Set synchronization status (private method)
	 *
	 * @param string $invoiceRef Invoice reference
	 * @param int $status Status code
	 * @param string|null $errorMessage Error message
	 * @param array $context Additional context
	 * @return bool True on success, false on failure
	 */
	private function setSynchStatus($invoiceRef, $status, $errorMessage = null, array $context = [])
	{
		try {
			return $this->syncManager->setSyncStatus($invoiceRef, $status, $errorMessage, $context);
		} catch (Exception $e) {
			$this->logOperationError(__METHOD__, "Exception setting sync status", [
				'invoice_ref' => $invoiceRef,
				'status' => $status,
				'exception' => $e->getMessage()
			]);
			return false;
		}
	}

	/**
	 * Set synchronization status (public method with enhanced logging)
	 *
	 * @param string $invoiceRef Invoice reference
	 * @param int $status Status code
	 * @param string|null $errorMessage Error message
	 * @param array $context Additional context
	 * @return bool True on success, false on failure
	 */
	public function setSyncStatus($invoiceRef, $status, $errorMessage = null, array $context = [])
	{
		$operation = __METHOD__;

		try {
			$result = $this->syncManager->setSyncStatus($invoiceRef, $status, $errorMessage, $context);

			$this->logDebug($operation, "Sync status updated", [
				'invoice_ref' => $invoiceRef,
				'status' => $status,
				'status_name' => ZSSyncStatus::getStatusDescription($status),
				'error_message' => $errorMessage,
				'success' => $result
			]);

			return $result;

		} catch (Exception $e) {
			$this->logOperationError($operation, "Exception setting sync status", [
				'invoice_ref' => $invoiceRef,
				'status' => $status,
				'error_message' => $errorMessage,
				'exception' => $e->getMessage()
			]);

			return false;
		}
	}
}

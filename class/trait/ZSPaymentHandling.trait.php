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
 * \file        class/trait/ZSPaymentHandling.trait.php
 * \ingroup     dolizsynch
 * \brief       Payment and credit note handling methods for ZSInvoiceSynch
 */

/**
 * Trait ZSPaymentHandling
 *
 * Handles payment operations and credit notes:
 * - Apply payments to invoices
 * - Get payment type and account mappings
 * - Create credit notes
 * - Remove payments from invoices
 */
trait ZSPaymentHandling
{
	/**
	 * Get Dolibarr payment type and account from ZoneSoft payment info
	 *
	 * @param int $pagamento_zs ZoneSoft payment type ID
	 * @param int $loja_zs Store ID
	 * @return array|false Array with 'payment_type_id' and 'account_id' or false
	 */
	private function getDolibarrPaymentTypeAndAccount($pagamento_zs, $loja_zs)
	{
		global $db, $conf;

		$operation = __METHOD__;

		$entityIds = [0, (int) $conf->entity];
		$entityIds = array_values(array_unique($entityIds));
		$entitySql = implode(',', $entityIds);
		$defaultPaymentTypeId = (int) ($conf->global->ZS_DEFAULT_PAYMENT_TYPE ?? 0);
		$defaultBankAccountId = (int) ($conf->global->ZS_DEFAULT_BANK_ACCOUNT ?? 0);
		$cashRefundPaymentTypeId = (int) ($conf->global->ZS_CASH_PAYMENT_TYPE_ID ?? 0);

		$sql  = "SELECT fk_paiement, fk_account ";
		$sql .= "FROM " . MAIN_DB_PREFIX . "dolizsynch_zspaymenttype ";
		$sql .= "WHERE paymenttype_id = " . (int)$pagamento_zs . " ";
		$sql .= "AND loja_zs = " . (int)$loja_zs . " ";
		$sql .= "AND entity IN (" . $entitySql . ") ";
		$sql .= "ORDER BY entity DESC ";
		$sql .= "LIMIT 1";

		$resql = $db->query($sql);

		if ($resql) {
			$obj = $db->fetch_object($resql);

			if ($obj) {
				$mappedPaymentTypeId = (int) ($obj->fk_paiement ?? 0);
				$mappedAccountId = (int) ($obj->fk_account ?? 0);
				if ($mappedPaymentTypeId > 0) {
					if ($mappedAccountId <= 0 && $defaultBankAccountId > 0) {
						$mappedAccountId = $defaultBankAccountId;
					}

					$this->logDebug($operation, "Found payment mapping", [
						'zs_payment_type' => $pagamento_zs,
						'store' => $loja_zs,
						'entity' => (int) $conf->entity,
						'dolibarr_payment_type' => $mappedPaymentTypeId,
						'dolibarr_account' => $mappedAccountId
					]);

					return [
						'payment_type_id' => $mappedPaymentTypeId,
						'account_id' => $mappedAccountId
					];
				}

				$this->logOperationWarning($operation, "Payment mapping exists but has no Dolibarr payment type configured, trying defaults", [
					'zs_payment_type' => $pagamento_zs,
					'store' => $loja_zs,
					'entity' => (int) $conf->entity
				]);
			}
		} else {
			$this->logSqlError($operation, $db, [
				'zs_payment_type' => $pagamento_zs,
				'store' => $loja_zs,
				'entity' => (int) $conf->entity
			]);
		}

		if ($defaultPaymentTypeId > 0) {
			$defaultPaymentCode = $this->resolveDolibarrPaymentCode($defaultPaymentTypeId);
			if ($defaultPaymentCode !== '') {
				$this->logOperationWarning($operation, "Using default payment mapping fallback", [
					'zs_payment_type' => $pagamento_zs,
					'store' => $loja_zs,
					'entity' => (int) $conf->entity,
					'default_payment_type_id' => $defaultPaymentTypeId,
					'default_bank_account_id' => $defaultBankAccountId
				]);

				$this->logDebug($operation, "Using payment mapping fallback", [
					'zs_payment_type' => $pagamento_zs,
					'store' => $loja_zs,
					'entity' => (int) $conf->entity,
					'dolibarr_payment_type' => $defaultPaymentTypeId,
					'dolibarr_account' => $defaultBankAccountId
				]);

				return [
					'payment_type_id' => $defaultPaymentTypeId,
					'account_id' => $defaultBankAccountId
				];
			}

			$this->logOperationWarning($operation, "Configured default payment type is invalid in current entity scope", [
				'default_payment_type_id' => $defaultPaymentTypeId,
				'entity' => (int) $conf->entity
			]);
		}

		if ($cashRefundPaymentTypeId > 0) {
			$cashPaymentCode = $this->resolveDolibarrPaymentCode($cashRefundPaymentTypeId);
			if ($cashPaymentCode !== '') {
				$this->logOperationWarning($operation, "Using cash-refund payment type fallback", [
					'zs_payment_type' => $pagamento_zs,
					'store' => $loja_zs,
					'entity' => (int) $conf->entity,
					'cash_payment_type_id' => $cashRefundPaymentTypeId,
					'default_bank_account_id' => $defaultBankAccountId
				]);

				$this->logDebug($operation, "Using cash-refund payment fallback", [
					'zs_payment_type' => $pagamento_zs,
					'store' => $loja_zs,
					'entity' => (int) $conf->entity,
					'dolibarr_payment_type' => $cashRefundPaymentTypeId,
					'dolibarr_account' => $defaultBankAccountId
				]);

				return [
					'payment_type_id' => $cashRefundPaymentTypeId,
					'account_id' => $defaultBankAccountId
				];
			}

			$this->logOperationWarning($operation, "Configured cash-refund payment type is invalid in current entity scope", [
				'cash_payment_type_id' => $cashRefundPaymentTypeId,
				'entity' => (int) $conf->entity
			]);
		}

		$this->logOperationWarning($operation, "Payment mapping not found", [
			'zs_payment_type' => $pagamento_zs,
			'store' => $loja_zs,
			'entity' => (int) $conf->entity
		]);

		return false;
	}

	/**
	 * Resolve Dolibarr payment code for a payment method id with entity fallback.
	 *
	 * @param int $paymentTypeId Dolibarr payment type id (c_paiement.id)
	 * @return string Empty string if not found
	 */
	private function resolveDolibarrPaymentCode($paymentTypeId)
	{
		global $db, $conf;

		$paymentTypeId = (int) $paymentTypeId;
		if ($paymentTypeId <= 0) {
			return '';
		}

		$entityIds = [(int) $conf->entity, 0];
		if ((int) $conf->entity !== 1) {
			$entityIds[] = 1;
		}
		$entityIds = array_values(array_unique($entityIds));
		$entitySql = implode(',', $entityIds);

		$sql = "SELECT code FROM " . MAIN_DB_PREFIX . "c_paiement ";
		$sql .= "WHERE id = " . $paymentTypeId . " ";
		$sql .= "AND entity IN (" . $entitySql . ") ";
		$sql .= "ORDER BY CASE entity ";
		$sql .= "WHEN " . (int) $conf->entity . " THEN 0 ";
		$sql .= "WHEN 0 THEN 1 ";
		$sql .= "WHEN 1 THEN 2 ";
		$sql .= "ELSE 3 END ";
		$sql .= "LIMIT 1";

		$resql = $db->query($sql);
		if ($resql) {
			$obj = $db->fetch_object($resql);
			if ($obj && !empty($obj->code)) {
				return (string) $obj->code;
			}
		}

		return '';
	}

	/**
	 * Apply payment to invoice
	 *
	 * @param int $invoiceId Dolibarr invoice ID
	 * @param int $paymentTypeId Dolibarr payment type ID
	 * @param int $accountid Bank account ID
	 * @param float $amount Payment amount
	 * @param int $paymentDate Payment date (timestamp)
	 * @return bool True on success, false on failure
	 */
	private function applyPaymentToInvoice($invoiceId, $paymentTypeId, $accountid, $amount, $paymentDate)
	{
		global $db, $conf;

		// Serialize the remaining-balance check and payment creation. Without a
		// lock, two cron workers can both see an unpaid invoice and both pay it.
		$baseline = (int) $db->transaction_opened;
		if (!$db->begin()) {
			$this->error = 'Unable to start invoice payment transaction';
			return false;
		}
		$savepoint = $baseline > 0 ? 'kf_payment_' . (int) $invoiceId . '_' . $baseline : null;
		$completed = false;
		try {
			if ($savepoint !== null && !$db->query('SAVEPOINT ' . $savepoint)) {
				$savepoint = null;
				$this->error = 'Unable to protect nested invoice payment';
				return false;
			}
			$sql = 'SELECT rowid FROM ' . MAIN_DB_PREFIX . 'facture';
			$sql .= ' WHERE rowid = ' . (int) $invoiceId . ' AND entity = ' . (int) $conf->entity . ' FOR UPDATE';
			$result = $db->query($sql);
			if (!$result || !$db->fetch_object($result)) {
				$this->error = 'Unable to lock the active-entity invoice for payment';
				return false;
			}
			if (!$this->applyPaymentToInvoiceLocked($invoiceId, $paymentTypeId, $accountid, $amount, $paymentDate)) {
				return false;
			}
			if ((int) $db->transaction_opened !== $baseline + 1) {
				$this->error = 'Unbalanced invoice payment transaction';
				return false;
			}
			$completed = (bool) $db->commit();
			return $completed;
		} catch (Throwable $e) {
			$this->error = 'Invoice payment failed: ' . $e->getMessage();
			return false;
		} finally {
			if ($savepoint !== null) {
				if (!$completed) {
					$db->query('ROLLBACK TO SAVEPOINT ' . $savepoint);
				}
				$db->query('RELEASE SAVEPOINT ' . $savepoint);
			}
			while ((int) $db->transaction_opened > $baseline) {
				$db->rollback();
			}
		}
	}

	/** @return bool */
	private function applyPaymentToInvoiceLocked($invoiceId, $paymentTypeId, $accountid, $amount, $paymentDate)
	{
		global $user, $db;

		$operation = __METHOD__;

		$paymentTimestamp = 0;
		if (is_numeric($paymentDate)) {
			$paymentTimestamp = (int) $paymentDate;
		} else {
			$paymentTimestamp = strtotime((string) $paymentDate);
			if ($paymentTimestamp === false) {
				$paymentTimestamp = 0;
			}
		}
		if ($paymentTimestamp <= 0) {
			$paymentTimestamp = dol_now();
			$this->logOperationWarning($operation, "Invalid payment date, falling back to current timestamp", [
				'invoice_id' => $invoiceId,
				'raw_payment_date' => $paymentDate
			]);
		}

		$this->logDebug($operation, "Applying payment to invoice", [
			'invoice_id' => $invoiceId,
			'payment_type_id' => $paymentTypeId,
			'account_id' => $accountid,
			'amount' => $amount,
			'payment_date' => date('Y-m-d', $paymentTimestamp)
		]);

		try {
			$invoice = new Facture($db);
			if ($invoice->fetch((int) $invoiceId) <= 0) {
				$this->error = 'Failed to fetch invoice before payment: ' . (string) $invoice->error;
				$this->logOperationError($operation, "Failed to fetch invoice before payment", [
					'invoice_id' => $invoiceId,
					'error' => $invoice->error
				]);
				return false;
			}
			if (!$this->verifyStoredCustomerInvoiceConsistency($invoice, $amount)) {
				return false;
			}

			$remainingToPay = (float) $invoice->getRemainToPay();
			$amount = (float) $amount;

			// Idempotency guard: if already fully paid, do not create another payment on retries.
			if ($remainingToPay <= 0.00001) {
				$this->logDebug($operation, "Invoice already paid, skipping payment creation", [
					'invoice_id' => $invoiceId,
					'remaining_to_pay' => $remainingToPay
				]);
				return true;
			}

			if ($amount <= 0.00001) {
				$this->logOperationWarning($operation, "Invalid payment amount, skipping", [
					'invoice_id' => $invoiceId,
					'amount' => $amount
				]);
				return true;
			}

			$amountToApply = min($amount, $remainingToPay);
			$amountToApply = (float) price2num($amountToApply, 'MU');

			if ($amountToApply <= 0.00001) {
				$this->logDebug($operation, "Nothing to pay after remain-to-pay adjustment", [
					'invoice_id' => $invoiceId,
					'requested_amount' => $amount,
					'remaining_to_pay' => $remainingToPay
				]);
				return true;
			}

			// Idempotency guard: check if a payment already exists for this invoice on the same date.
			// Prevents duplicate payments on retries when the sync previously failed after payment creation.
			$paymentDate_ymd = date('Y-m-d', $paymentTimestamp);
			$sqlExisting  = "SELECT p.rowid FROM " . MAIN_DB_PREFIX . "paiement p";
			$sqlExisting .= " INNER JOIN " . MAIN_DB_PREFIX . "paiement_facture pf ON pf.fk_paiement = p.rowid";
			$sqlExisting .= " WHERE pf.fk_facture = " . ((int) $invoiceId);
			$sqlExisting .= " AND DATE(p.datep) = '" . $db->escape($paymentDate_ymd) . "'";
			$sqlExisting .= " LIMIT 1";
			$resExisting = $db->query($sqlExisting);
			if ($resExisting && $db->num_rows($resExisting) > 0) {
				$this->logDebug($operation, "Payment already exists for this invoice on the same date, skipping duplicate", [
					'invoice_id' => $invoiceId,
					'payment_date' => $paymentDate_ymd
				]);
				return true;
			}

			require_once DOL_DOCUMENT_ROOT . '/compta/paiement/class/paiement.class.php';

			$paiement = new Paiement($db);
			$paiement->datepaye = $paymentTimestamp;
			$paiement->amounts = array($invoiceId => $amountToApply);
			$paiement->paiementid = $paymentTypeId;
			$paymentCode = $this->resolveDolibarrPaymentCode($paymentTypeId);
			if ($paymentCode !== '') {
				$paiement->paiementcode = $paymentCode;
			}
			$paiement->num_paiement = '';
			$paiement->note = 'Payment from ZoneSoft';

			// Create payment
			$paymentId = $paiement->create($user, 1); // 1 = close paid invoices

			if ($paymentId <= 0) {
				$this->error = 'Failed to create payment: ' . (string) $paiement->error;
				$this->logOperationError($operation, "Failed to create payment", [
					'invoice_id' => $invoiceId,
					'error' => $paiement->error
				]);
				return false;
			}

			// Only add to bank if account is specified
			if (!empty($accountid) && $accountid > 0) {
				$label = 'Payment for invoice ' . $invoiceId;
				$result = $paiement->addPaymentToBank($user, 'payment', $label, $accountid, '', '');

				if ($result < 0) {
					// Keep sync idempotent: payment already exists even if bank linking failed.
					$this->logOperationWarning($operation, "Payment created but failed to add payment to bank", [
						'invoice_id' => $invoiceId,
						'payment_id' => $paymentId,
						'account_id' => $accountid,
						'error' => $paiement->error
					]);
				}

				if ($result >= 0) {
					$this->logDebug($operation, "Payment added to bank account", [
						'invoice_id' => $invoiceId,
						'payment_id' => $paymentId,
						'account_id' => $accountid,
						'payment_code' => $paymentCode
					]);
				}
			} else {
				$this->logOperationWarning($operation, "No bank account configured, payment created without bank entry", [
					'invoice_id' => $invoiceId,
					'payment_id' => $paymentId,
					'account_id' => $accountid
				]);
			}

			$this->logOperationSuccess($operation, [
				'invoice_id' => $invoiceId,
				'payment_id' => $paymentId,
				'amount' => $amountToApply,
				'requested_amount' => $amount,
				'remaining_before_payment' => $remainingToPay
			]);

			return true;

		} catch (Exception $e) {
			$this->error = 'Exception applying payment: ' . (string) $e->getMessage();
			$this->logOperationError($operation, "Exception applying payment", [
				'invoice_id' => $invoiceId,
				'exception' => $e->getMessage()
			]);
			return false;
		}
	}

	/**
	 * Apply payment to Dolibarr invoice from ZoneSoft invoice details
	 *
	 * @param int $dolibarrInvoiceId Dolibarr invoice ID
	 * @param object $zsInvoiceDetails ZoneSoft invoice details (full response with ->document)
	 * @return bool True on success, false on failure
	 */
	public function applyPaymentToDolibarrInvoice($dolibarrInvoiceId, $zsInvoiceDetails)
	{
		global $db;

		$operation = __METHOD__;

		// Extract document from response structure
		$document = $zsInvoiceDetails->document ?? $zsInvoiceDetails;

		// Get payment mapping
		$paymentMapping = $this->getDolibarrPaymentTypeAndAccount(
			$document->pagamento,
			$document->loja
		);

		if (!$paymentMapping) {
			$this->error = 'Payment mapping not found for ZoneSoft payment ' . (string) ($document->pagamento ?? '') . ' store ' . (string) ($document->loja ?? '');
			$this->logOperationError($operation, "Payment mapping not found", [
				'invoice_id' => $dolibarrInvoiceId,
				'zs_payment_type' => $document->pagamento,
				'store' => $document->loja
			]);
			return false;
		}

		// Apply payment
		return $this->applyPaymentToInvoice(
			$dolibarrInvoiceId,
			$paymentMapping['payment_type_id'],
			$paymentMapping['account_id'],
			$document->total,
			strtotime($document->data)
		);
	}

	/**
	 * Create credit note from invoice
	 *
	 * @param int $invoiceId Source invoice ID
	 * @return int|false Credit note ID on success, false on failure
	 */
	public function createCreditNoteFromInvoice($invoiceId)
	{
		global $user, $db, $conf;

		$operation = __METHOD__;
		$transactionDepth = $this->getDatabaseTransactionDepth($db);

		$this->logDebug($operation, "Creating credit note", [
			'source_invoice_id' => $invoiceId
		]);

		try {
			if (!$db->begin()) {
				throw new RuntimeException('Unable to begin credit-note transaction');
			}

			// Load source invoice
			$sourceInvoice = new Facture($db);
			if ($sourceInvoice->fetch((int) $invoiceId) <= 0
				|| (int) ($sourceInvoice->entity ?? 0) !== (int) ($conf->entity ?? 0)) {
				throw new RuntimeException('Source invoice was not found in the active entity');
			}
			if ($sourceInvoice->fetch_lines() < 0 || empty($sourceInvoice->lines)) {
				throw new RuntimeException($sourceInvoice->error ?: 'Source invoice has no lines');
			}
			try {
				$sourceHt = ZSDecimal::normalize((string) $sourceInvoice->total_ht);
				$sourceVat = ZSDecimal::normalize((string) $sourceInvoice->total_tva);
				$sourceTtc = ZSDecimal::normalize((string) $sourceInvoice->total_ttc);
			} catch (InvalidArgumentException $e) {
				throw new RuntimeException('Source invoice contains invalid monetary totals');
			}
			if (ZSDecimal::compare($sourceHt, '0') < 0 || ZSDecimal::compare($sourceVat, '0') < 0
				|| ZSDecimal::compare($sourceTtc, '0') < 0) {
				throw new RuntimeException('Source invoice for credit-note creation must use positive monetary magnitudes');
			}

			// Create credit note
			$creditNote = new Facture($db);
			$creditNote->socid = $sourceInvoice->socid;
			$creditNote->type = Facture::TYPE_CREDIT_NOTE;
			$creditNote->fk_facture_source = $invoiceId;
			$creditNote->date = dol_now();
			$creditNote->note_private = 'Credit note for canceled invoice from ZoneSoft';
			$this->normalizeFactureCreateHeader($creditNote);

			// Create the credit note
			$creditNoteId = $creditNote->create($user);

			if ($creditNoteId <= 0) {
				throw new RuntimeException($creditNote->error ?: 'Failed to create credit note');
			}

			$taxLines = [];
			foreach ($sourceInvoice->lines as $lineIndex => $line) {
				try {
					$quantity = ZSDecimal::normalize((string) ($line->qty ?? ''));
					$unitPrice = ZSDecimal::normalize((string) ($line->subprice ?? ''));
					$vatRate = ZSDecimal::normalize((string) ($line->tva_tx ?? '0'));
					$discount = ZSDecimal::normalize((string) ($line->remise_percent ?? '0'));
					$lineHt = ZSDecimal::normalize((string) ($line->total_ht ?? ''));
					$lineTtc = ZSDecimal::normalize((string) ($line->total_ttc ?? ''));
					$localTax1 = ZSDecimal::normalize((string) ($line->localtax1_tx ?? '0'));
					$localTax2 = ZSDecimal::normalize((string) ($line->localtax2_tx ?? '0'));
				} catch (InvalidArgumentException $e) {
					throw new RuntimeException('Source invoice line ' . ($lineIndex + 1) . ' contains an invalid decimal value');
				}
				if (ZSDecimal::compare($quantity, '0') <= 0 || ZSDecimal::compare($unitPrice, '0') < 0
					|| ZSDecimal::compare($lineHt, '0') < 0 || ZSDecimal::compare($lineTtc, '0') < 0) {
					throw new RuntimeException('Source invoice line ' . ($lineIndex + 1) . ' has an unsupported sign');
				}
				if (ZSDecimal::compare($localTax1, '0') !== 0 || ZSDecimal::compare($localTax2, '0') !== 0) {
					throw new RuntimeException('Credit-note copying with non-zero local taxes requires a dedicated adapter');
				}

				$taxLines[] = [
					'quantity' => $quantity,
					'unit_price' => $unitPrice,
					'discount_percent' => $discount,
					'vat_rate' => $vatRate,
					'source_total_ht' => $lineHt,
					'source_total_ttc' => $lineTtc,
				];

				$result = $creditNote->addline(
					(string) ($line->desc ?? ''),
					$unitPrice,
					$quantity,
					$vatRate,
					$localTax1,
					$localTax2,
					(int) ($line->fk_product ?? 0),
					$discount,
					$line->date_start ?? '',
					$line->date_end ?? '',
					0,
					0,
					(int) ($line->special_code ?? 0),
					'HT',
					'0',
					(int) ($line->product_type ?? 0),
					-1,
					0,
					'',
					0,
					0,
					$line->fk_unit ?? null,
					'0',
					(string) ($line->ref_ext ?? ''),
					(array) ($line->array_options ?? []),
					100,
					0,
					null,
					'0',
					'',
					1
				);

				if ($result < 0) {
					throw new RuntimeException($creditNote->error ?: 'Failed to add credit-note line ' . ($lineIndex + 1));
				}
			}

			$creditPlan = ZSInvoiceTaxCalculator::reconcileSource(
				$taxLines,
				'0',
				false,
				$sourceHt,
				$sourceVat,
				$sourceTtc,
				[
					'external_id' => 'dolibarr|' . (int) $sourceInvoice->id,
					'invoice_number' => (string) $sourceInvoice->ref,
				]
			);
			if (!$this->reconcileCustomerInvoiceTotals($creditNote, ['monetary_plan' => $creditPlan])) {
				throw new RuntimeException($this->error ?: 'Credit-note totals could not be reconciled');
			}

			if (!$this->validateInvoiceWithMonetaryPlan(
				$creditNote,
				$user,
				(int) ($conf->global->MAIN_DEFAULT_WAREHOUSE ?? 0),
				(string) $sourceInvoice->ref,
				$creditPlan
			)) {
				throw new RuntimeException($this->error ?: 'Failed to validate and reconcile credit note');
			}
			if (!$db->commit()) {
				throw new RuntimeException('Unable to commit credit-note transaction');
			}

			$this->logOperationSuccess($operation, [
				'source_invoice_id' => $invoiceId,
				'credit_note_id' => $creditNoteId,
				'credit_note_ref' => $creditNote->ref
			]);

			return $creditNoteId;

		} catch (Throwable $e) {
			$this->rollbackDatabaseTransactionToDepth($db, $transactionDepth);
			$this->error = $e->getMessage();
			$this->logOperationError($operation, "Exception creating credit note", [
				'source_invoice_id' => $invoiceId,
				'exception' => $e->getMessage()
			]);
			return false;
		}
	}

	/**
	 * Read the current Dolibarr nested-transaction depth when available.
	 *
	 * @param DoliDB $database Database handler
	 * @return int|null Transaction depth, or null for non-Dolibarr test doubles
	 */
	private function getDatabaseTransactionDepth($database)
	{
		if (is_object($database) && property_exists($database, 'transaction_opened')) {
			return max(0, (int) $database->transaction_opened);
		}

		return null;
	}

	/**
	 * Roll back every nested level opened after a captured transaction depth.
	 *
	 * Dolibarr lifecycle methods own nested transactions. A thrown trigger can
	 * bypass their normal rollback, so one rollback call is not always enough to
	 * reach the transaction level owned by this caller.
	 *
	 * @param DoliDB $database Database handler
	 * @param int|null $targetDepth Transaction depth captured before begin()
	 * @return bool True when the requested depth was restored
	 */
	private function rollbackDatabaseTransactionToDepth($database, $targetDepth)
	{
		if ($targetDepth === null) {
			return (bool) $database->rollback();
		}

		$rollbackSucceeded = true;
		$remainingAttempts = 64;
		while ((int) $database->transaction_opened > $targetDepth && $remainingAttempts > 0) {
			$depthBeforeRollback = (int) $database->transaction_opened;
			$result = $database->rollback();
			$depthAfterRollback = (int) $database->transaction_opened;
			if (!$result || $depthAfterRollback >= $depthBeforeRollback) {
				$rollbackSucceeded = false;
				break;
			}
			$remainingAttempts--;
		}

		return $rollbackSucceeded && (int) $database->transaction_opened === $targetDepth;
	}

	/**
	 * Return the current Dolibarr lifecycle status of an invoice.
	 *
	 * Dolibarr keeps the legacy `statut` alias alongside `status`; using the
	 * highest value keeps compatibility with versions that populate both fields
	 * at different points of the object lifecycle.
	 *
	 * @param Facture $invoice Invoice object
	 * @return int
	 */
	private function getInvoiceLifecycleStatus($invoice)
	{
		return max((int) ($invoice->status ?? 0), (int) ($invoice->statut ?? 0));
	}

	/**
	 * Reopen an active paid invoice before its dedicated payments are removed.
	 *
	 * This is cancellation-only behavior. Generic payment cleanup also runs for
	 * draft rebuilds and legacy abandoned invoices, so callers must opt in.
	 *
	 * @param Facture $invoice Invoice object
	 * @return bool True when the invoice is validated and unpaid
	 */
	private function reopenInvoiceForCancellationPaymentRemoval($invoice)
	{
		global $user;

		$invoiceId = (int) ($invoice->id ?? 0);
		$status = $this->getInvoiceLifecycleStatus($invoice);
		$isPaid = !empty($invoice->paye);

		if ($invoiceId <= 0) {
			$this->error = 'Invalid invoice before payment removal';
			return false;
		}

		if ($status === Facture::STATUS_ABANDONED) {
			$this->error = 'Canceled invoice payment cleanup requires explicit operator review';
			return false;
		}

		if ($status <= Facture::STATUS_VALIDATED && !$isPaid) {
			return true;
		}

		if (!method_exists($invoice, 'setUnpaid') && !method_exists($invoice, 'set_unpaid')) {
			$this->error = 'Dolibarr invoice reopening lifecycle is unavailable';
			return false;
		}

		$result = method_exists($invoice, 'setUnpaid')
			? $invoice->setUnpaid($user)
			: $invoice->set_unpaid($user);
		if ($result < 0) {
			$this->error = $invoice->error ?: 'Unable to reopen paid invoice before payment removal';
			return false;
		}

		if ($invoice->fetch($invoiceId) <= 0) {
			$this->error = $invoice->error ?: 'Unable to reload invoice after reopening it';
			return false;
		}

		$status = $this->getInvoiceLifecycleStatus($invoice);
		if ($status !== Facture::STATUS_VALIDATED || !empty($invoice->paye)) {
			$this->error = 'Invoice did not reach the validated unpaid state before payment removal';
			return false;
		}

		return true;
	}

	/**
	 * Remove payments from an invoice.
	 *
	 * @param Facture $invoice Invoice object
	 * @param bool $reopenForCancellation Reopen an active paid invoice through Facture::setUnpaid()
	 * @return bool True on success, false on failure
	 */
	private function removePaymentsFromInvoice($invoice, $reopenForCancellation = false)
	{
		global $user, $db, $langs, $conf;

		$operation = __METHOD__;
		$invoiceId = (int) ($invoice->id ?? 0);
		$entity = (int) ($conf->entity ?? 0);
		$this->error = '';

		if ($invoiceId <= 0 || $entity <= 0) {
			$this->error = 'Invalid entity-scoped invoice payment-removal context';
			return false;
		}

		if (!class_exists('AccountLine')) {
			require_once DOL_DOCUMENT_ROOT . '/compta/bank/class/account.class.php';
		}

		$this->logDebug($operation, "Removing payments from invoice", [
			'invoice_id' => $invoiceId,
			'invoice_ref' => $invoice->ref
		]);

		$transactionDepth = $this->getDatabaseTransactionDepth($db);
		if (!$db->begin()) {
			$this->error = 'Unable to start the invoice payment-removal transaction';
			return false;
		}
		try {
			$sqlLock = "SELECT rowid FROM " . MAIN_DB_PREFIX . "facture";
			$sqlLock .= " WHERE rowid = " . $invoiceId;
			$sqlLock .= " AND entity = " . $entity;
			$sqlLock .= " FOR UPDATE";
			$resLock = $db->query($sqlLock);
			if (!$resLock || !$db->fetch_object($resLock)) {
				$this->error = 'Unable to lock the entity-scoped invoice before payment removal';
				$this->rollbackDatabaseTransactionToDepth($db, $transactionDepth);
				return false;
			}

			if ($invoice->fetch($invoiceId) <= 0) {
				$this->error = $invoice->error ?: 'Unable to reload invoice before payment removal';
				$this->rollbackDatabaseTransactionToDepth($db, $transactionDepth);
				return false;
			}

			// Build a complete removal plan before firing any invoice or payment trigger.
			$sql = "SELECT fk_paiement AS payment_id";
			$sql .= " FROM " . MAIN_DB_PREFIX . "paiement_facture";
			$sql .= " WHERE fk_facture = " . $invoiceId;
			$sql .= " ORDER BY fk_paiement FOR UPDATE";
			$resql = $db->query($sql);
			if (!$resql) {
				$this->error = 'Unable to build the invoice payment-removal plan: ' . $db->lasterror();
				$this->logSqlError($operation, $db, [
					'invoice_id' => $invoiceId,
					'operation' => 'payment_removal_preflight'
				]);
				$this->rollbackDatabaseTransactionToDepth($db, $transactionDepth);
				return false;
			}

			$paymentIds = [];
			while ($obj = $db->fetch_object($resql)) {
				$paymentId = (int) ($obj->payment_id ?? 0);
				if ($paymentId <= 0) {
					$this->error = 'Invoice payment allocation references an invalid payment';
					$this->rollbackDatabaseTransactionToDepth($db, $transactionDepth);
					return false;
				}
				$paymentIds[$paymentId] = $paymentId;
			}

			foreach ($paymentIds as $paymentId) {
				$sqlPayment = "SELECT rowid AS payment_exists, entity AS payment_entity, fk_bank";
				$sqlPayment .= " FROM " . MAIN_DB_PREFIX . "paiement";
				$sqlPayment .= " WHERE rowid = " . $paymentId;
				$sqlPayment .= " FOR UPDATE";
				$resPayment = $db->query($sqlPayment);
				$objPayment = $resPayment ? $db->fetch_object($resPayment) : false;
				if (!$objPayment || (int) ($objPayment->payment_exists ?? 0) !== $paymentId) {
					$this->error = 'Invoice payment allocation references a missing payment';
					$this->rollbackDatabaseTransactionToDepth($db, $transactionDepth);
					return false;
				}
				if ((int) ($objPayment->payment_entity ?? 0) !== $entity) {
					$this->error = 'Invoice payment does not belong to the active entity';
					$this->rollbackDatabaseTransactionToDepth($db, $transactionDepth);
					return false;
				}

				$sqlLinks = "SELECT fk_facture";
				$sqlLinks .= " FROM " . MAIN_DB_PREFIX . "paiement_facture";
				$sqlLinks .= " WHERE fk_paiement = " . $paymentId;
				$sqlLinks .= " ORDER BY fk_facture FOR UPDATE";
				$resLinks = $db->query($sqlLinks);
				if (!$resLinks) {
					$this->error = 'Unable to verify the invoices linked to payment ' . $paymentId;
					$this->rollbackDatabaseTransactionToDepth($db, $transactionDepth);
					return false;
				}
				$linkedInvoiceIds = [];
				while ($objLink = $db->fetch_object($resLinks)) {
					$linkedInvoiceId = (int) ($objLink->fk_facture ?? 0);
					if ($linkedInvoiceId > 0) {
						$linkedInvoiceIds[$linkedInvoiceId] = true;
					}
				}
				if (count($linkedInvoiceIds) !== 1 || !isset($linkedInvoiceIds[$invoiceId])) {
					$this->error = 'Payment ' . $paymentId . ' is shared with another invoice and cannot be removed automatically';
					$this->rollbackDatabaseTransactionToDepth($db, $transactionDepth);
					return false;
				}

				$bankLineId = (int) ($objPayment->fk_bank ?? 0);
				if ($bankLineId > 0) {
					$sqlBank = "SELECT rowid AS bank_line_id, fk_account, rappro AS bank_reconciled";
					$sqlBank .= " FROM " . MAIN_DB_PREFIX . "bank";
					$sqlBank .= " WHERE rowid = " . $bankLineId;
					$sqlBank .= " FOR UPDATE";
					$resBank = $db->query($sqlBank);
					$objBank = $resBank ? $db->fetch_object($resBank) : false;
					$bankAccountId = (int) ($objBank->fk_account ?? 0);
					if (!$objBank || (int) ($objBank->bank_line_id ?? 0) !== $bankLineId || $bankAccountId <= 0) {
						$this->error = 'Payment ' . $paymentId . ' references a missing bank line';
						$this->rollbackDatabaseTransactionToDepth($db, $transactionDepth);
						return false;
					}

					$sqlBankAccount = "SELECT rowid";
					$sqlBankAccount .= " FROM " . MAIN_DB_PREFIX . "bank_account";
					$sqlBankAccount .= " WHERE rowid = " . $bankAccountId;
					$sqlBankAccount .= " AND entity IN (" . getEntity('bank_account') . ")";
					$sqlBankAccount .= " FOR UPDATE";
					$resBankAccount = $db->query($sqlBankAccount);
					if (!$resBankAccount || !$db->fetch_object($resBankAccount)) {
						$this->error = 'Payment ' . $paymentId . ' bank line is outside the active entity scope';
						$this->rollbackDatabaseTransactionToDepth($db, $transactionDepth);
						return false;
					}
					if (!empty($objBank->bank_reconciled)) {
						$this->error = 'Payment ' . $paymentId . ' is linked to a reconciled bank line';
						$this->rollbackDatabaseTransactionToDepth($db, $transactionDepth);
						return false;
					}

					if (!getDolGlobalString('BANK_ALLOW_TRANSACTION_DELETION_EVEN_IF_IN_ACCOUNTING')) {
						$sqlAccounting = "SELECT COUNT(rowid) AS nb";
						$sqlAccounting .= " FROM " . MAIN_DB_PREFIX . "accounting_bookkeeping";
						$sqlAccounting .= " WHERE doc_type = 'bank'";
						$sqlAccounting .= " AND fk_doc = " . $bankLineId;
						$resAccounting = $db->query($sqlAccounting);
						if (!$resAccounting) {
							$this->error = 'Unable to verify whether payment ' . $paymentId . ' is posted to accounting';
							$this->rollbackDatabaseTransactionToDepth($db, $transactionDepth);
							return false;
						}
						$objAccounting = $db->fetch_object($resAccounting);
						if (!empty($objAccounting->nb)) {
							$this->error = 'Payment ' . $paymentId . ' bank line is already posted to accounting';
							$this->rollbackDatabaseTransactionToDepth($db, $transactionDepth);
							return false;
						}
					}
				}
			}

			if ($reopenForCancellation && !$this->reopenInvoiceForCancellationPaymentRemoval($invoice)) {
				$this->rollbackDatabaseTransactionToDepth($db, $transactionDepth);
				return false;
			}

			$removedCount = 0;
			$failedPaymentIds = [];
			$paymentErrorMessages = [];

			foreach ($paymentIds as $paymentId) {
				if (!class_exists('Paiement')) {
					require_once DOL_DOCUMENT_ROOT . '/compta/paiement/class/paiement.class.php';
				}

				$payment = new Paiement($db);
				if ($payment->fetch($paymentId) > 0) {
					$result = $payment->delete($user);

					if ($result > 0) {
						$removedCount++;
					} else {
						$failedPaymentIds[] = $paymentId;
						$paymentError = trim((string) $payment->error);
						if ($paymentError === '') {
							$paymentError = 'Unknown payment deletion error';
						}
						$translatedPaymentError = $langs->trans($paymentError);
						if ($translatedPaymentError === $paymentError) {
							$paymentErrorMessages[] = 'Payment ' . $paymentId . ': ' . $paymentError;
						} else {
							$paymentErrorMessages[] = 'Payment ' . $paymentId . ': ' . $translatedPaymentError . ' [' . $paymentError . ']';
						}
						$this->logOperationWarning($operation, "Failed to delete payment", [
							'invoice_id' => $invoiceId,
							'payment_id' => $paymentId,
							'error' => $payment->error
						]);
					}
				} else {
					$failedPaymentIds[] = $paymentId;
					$paymentErrorMessages[] = 'Payment ' . $paymentId . ': failed to load linked payment before deletion';
					$this->logOperationWarning($operation, "Failed to fetch payment before delete", [
						'invoice_id' => $invoiceId,
						'payment_id' => $paymentId
					]);
				}
			}

			if (!empty($failedPaymentIds)) {
				$this->error = implode('; ', array_values(array_unique($paymentErrorMessages)));
				$this->logOperationError($operation, "Not all linked payments could be removed", [
					'invoice_id' => $invoiceId,
					'failed_payment_ids' => $failedPaymentIds,
					'error' => $this->error
				]);
				$this->rollbackDatabaseTransactionToDepth($db, $transactionDepth);
				return false;
			}

			// Safety check: ensure no payment links remain for this invoice.
			$sqlCheck = "SELECT COUNT(*) AS cnt FROM " . MAIN_DB_PREFIX . "paiement_facture";
			$sqlCheck .= " WHERE fk_facture = " . $invoiceId;
			$resCheck = $db->query($sqlCheck);
			if (!$resCheck) {
				$this->error = 'Unable to verify remaining invoice payment links: ' . $db->lasterror();
				$this->logSqlError($operation, $db, [
					'invoice_id' => $invoiceId,
					'operation' => 'verify_remaining_links'
				]);
				$this->rollbackDatabaseTransactionToDepth($db, $transactionDepth);
				return false;
			}
			$objCheck = $db->fetch_object($resCheck);
			$remainingLinks = (int) ($objCheck->cnt ?? 0);
			if ($remainingLinks > 0) {
				$this->error = 'Payment links remain after deletion for invoice ' . (int) $invoice->id;
				$this->logOperationError($operation, "Payment links remain after deletion", [
					'invoice_id' => $invoiceId,
					'remaining_links' => $remainingLinks
				]);
				$this->rollbackDatabaseTransactionToDepth($db, $transactionDepth);
				return false;
			}

			$this->logDebug($operation, "Payments removed", [
				'invoice_id' => $invoiceId,
				'removed_count' => $removedCount
			]);

			if ($transactionDepth !== null && (int) $db->transaction_opened !== $transactionDepth + 1) {
				$this->error = 'Unbalanced nested transaction after invoice payment removal';
				$this->rollbackDatabaseTransactionToDepth($db, $transactionDepth);
				return false;
			}
			if (!$db->commit()) {
				$this->error = 'Unable to commit the invoice payment-removal transaction';
				$this->rollbackDatabaseTransactionToDepth($db, $transactionDepth);
				return false;
			}
			return true;

		} catch (Throwable $e) {
			$this->error = $e->getMessage();
			$this->logOperationError($operation, "Exception removing payments", [
				'invoice_id' => $invoiceId,
				'exception' => $e->getMessage(),
				'exception_class' => get_class($e)
			]);
			if (!$this->rollbackDatabaseTransactionToDepth($db, $transactionDepth)) {
				$this->error .= ' (transaction rollback did not restore the original nesting level)';
			}
			return false;
		}
	}
}

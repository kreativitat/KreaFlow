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
 * \file        class/trait/ZSInvoiceCreation.trait.php
 * \ingroup     dolizsynch
 * \brief       Invoice creation and line management methods for ZSInvoiceSynch
 */

/**
 * Trait ZSInvoiceCreation
 *
 * Handles the creation and updating of invoices in Dolibarr, including:
 * - Creating invoices from ZoneSoft data
 * - Adding invoice lines
 * - Managing invoice validation
 * - Handling adjustments and totals
 */
trait ZSInvoiceCreation
{
	/**
	 * Get invoice type code from document type
	 *
	 * @param string $doc Document type from ZoneSoft
	 * @return int Dolibarr invoice type constant
	 */
	private function getInvoiceType($doc)
	{
		// Map document types
		switch ($doc) {
			case 'FT':
				return Facture::TYPE_STANDARD;
			case 'FR':
				return Facture::TYPE_REPLACEMENT;
			case 'NC':
				return Facture::TYPE_CREDIT_NOTE;
			case 'ND':
				return Facture::TYPE_DEPOSIT;
			default:
				return Facture::TYPE_STANDARD;
		}
	}

	/**
	 * Create invoice in Dolibarr
	 *
	 * @param Facture $facture Invoice object
	 * @param array $dolibarrInvoice Invoice data
	 * @return int Invoice ID on success, false on failure
	 */
	private function createInvoice($facture, $dolibarrInvoice)
	{
		global $user, $conf;

		$operation = __METHOD__;
		$this->error = '';
		$this->errors = array();

		$this->logDebug($operation, "Creating invoice", [
			'ref_client' => $dolibarrInvoice['ref_client'],
			'customer_id' => $dolibarrInvoice['socid'],
			'total_ht' => $dolibarrInvoice['total_ht']
		]);

		// Set invoice properties
		$facture->socid = $dolibarrInvoice['socid'];
		$facture->date = $dolibarrInvoice['date'];
		$facture->type = $this->getInvoiceType($dolibarrInvoice['doc_type']);
		$facture->ref_client = $dolibarrInvoice['ref_client'];
		$facture->note_public = $dolibarrInvoice['note_public'] ?? 'Imported with KreaFlow';
		$facture->note_private = $dolibarrInvoice['note_private'] ?? '';
		$this->normalizeFactureCreateHeader($facture, $dolibarrInvoice);

		// Set extra fields (array_options) before creation
		if (isset($dolibarrInvoice['array_options'])) {
			$facture->array_options = $dolibarrInvoice['array_options'];
		}

		// Set datec to match Zone Soft datetime (use server timezone - Lisbon)
		if (!empty($dolibarrInvoice['array_options']['options_invoice_date_time'])) {
			$facture->date_creation = $dolibarrInvoice['array_options']['options_invoice_date_time'];
		}

		// Create the invoice
		$facture->error = '';
		$facture->errors = array();
		$result = $facture->create($user);

		if ($result <= 0) {
			$createErrors = [];
			foreach (array_merge((array) $facture->errors, array($facture->error)) as $nativeError) {
				if (is_scalar($nativeError) && trim((string) $nativeError) !== '') {
					$createErrors[] = trim((string) $nativeError);
				}
			}
			$createErrors = array_values(array_unique($createErrors));
			if (empty($createErrors) && is_object($this->db) && method_exists($this->db, 'lasterror')) {
				$databaseError = trim((string) $this->db->lasterror());
				if ($databaseError !== '') {
					$createErrors[] = $databaseError;
				}
			}
			if (empty($createErrors)) {
				$createErrors[] = 'Native invoice creation failed with result ' . (int) $result;
			}
			$this->errors = $createErrors;
			$this->error = implode('; ', $createErrors);

			$this->logOperationError($operation, "Failed to create invoice", [
				'ref_client' => $dolibarrInvoice['ref_client'],
				'creation_errors' => $createErrors,
				'error' => $this->error
			]);

			return false;
		}

		$this->logDebug($operation, "Invoice created successfully", [
			'invoice_id' => $result,
			'ref' => $facture->ref,
			'ref_client' => $facture->ref_client
		]);

		return $result;
	}

	/**
	 * Add lines to an invoice
	 *
	 * @param Facture $facture Invoice object
	 * @param array $lines Array of invoice lines
	 * @param int $ivaIncluido Whether VAT is included (1) or not (0)
	 * @return bool True on success, false on failure
	 */
	private function addInvoiceLines($facture, $lines, $ivaIncluido)
	{
		global $db;

		$operation = __METHOD__;
		$addedLines = 0;
		$failedLines = 0;
		$lineFailureMessages = [];

		foreach ($lines as $lineIndex => $lineData) {
			$requestedProductId = (int) ($lineData['fk_product'] ?? 0);
			$fallbackProductId = $this->getConfiguredGenericInvoiceProductId();
			$productIdToUse = $this->resolveInvoiceLineProductId($requestedProductId, $fallbackProductId, $lineData, $lineIndex, $operation);

			// Get the price base type and prices from line data
			$price_base_type = $lineData['price_base_type'] ?? 'TTC';
			$pu_ht = $lineData['pu_ht'] ?? 0;
			$pu_ttc = $lineData['pu_ttc'] ?? 0;

			// Use the appropriate price based on price_base_type
			if ($price_base_type == 'HT') {
				// Pass HT price to avoid Dolibarr TTC->HT->TTC conversion issues
				$price = $pu_ht;
			} else {
				// Pass TTC price for TTC mode
				$price = $pu_ttc;
			}

			$result = $this->addCustomerInvoiceLineUsingNativeApi(
				$facture,
				$lineData,
				$productIdToUse,
				$price_base_type,
				(string) $price
			);

				if ($result > 0) {
					$addedLines++;
				} else {
					$lineErrors = $this->extractFactureErrors($facture);
					$retryUsedGeneric = false;

					if (
						$productIdToUse > 0 &&
						$fallbackProductId > 0 &&
						$productIdToUse !== $fallbackProductId &&
						$this->shouldRetryWithGenericProduct($lineErrors)
					) {
						$productIdToUse = $fallbackProductId;
						$retryUsedGeneric = true;

					$result = $this->addCustomerInvoiceLineUsingNativeApi(
						$facture,
						$lineData,
						$productIdToUse,
						$price_base_type,
						(string) $price
					);

						if ($result > 0) {
							$addedLines++;
							$this->logOperationWarning($operation, "Retried invoice line with configured generic product", [
								'invoice_id' => $facture->id,
								'line_index' => $lineIndex,
								'line_desc' => substr((string) ($lineData['desc'] ?? ''), 0, 50),
								'requested_product_id' => $requestedProductId,
								'fallback_product_id' => $fallbackProductId
							]);
							continue;
						}

						$lineErrors = $this->extractFactureErrors($facture);
					}

					$failedLines++;
					$lineErrorText = !empty($lineErrors) ? implode('; ', $lineErrors) : 'Unknown error while adding line';
					$lineFailureMessages[] = 'Line ' . ((int) $lineIndex + 1) . ' (' . substr((string) ($lineData['desc'] ?? ''), 0, 50) . '): ' . $lineErrorText;

					$this->logOperationWarning($operation, "Failed to add invoice line", [
						'invoice_id' => $facture->id,
						'line_index' => $lineIndex,
						'line_desc' => substr($lineData['desc'], 0, 50),
						'calculated_price' => $price,
						'iva_incluido' => $ivaIncluido,
						'requested_product_id' => $requestedProductId,
						'used_product_id' => $productIdToUse,
						'generic_product_id' => $fallbackProductId,
						'retried_with_generic' => $retryUsedGeneric,
						'line_errors' => $lineErrors
					]);
				}
			}

		if ($failedLines > 0) {
			$this->error = implode(' | ', $lineFailureMessages);

			$invoiceRef = (string) ($facture->ref_client ?? '');
			if ($invoiceRef === '' && !empty($facture->array_options['options_invoice_number'])) {
				$invoiceRef = (string) $facture->array_options['options_invoice_number'];
			}
			if ($invoiceRef !== '') {
				$this->setSyncStatus($invoiceRef, ZSSyncStatus::SAVE_UPDATE_ERROR, $this->error, [
					'facture_id' => (int) $facture->id,
					'failed_lines' => $failedLines,
					'total_lines' => count($lines)
				]);
			}

			$this->logOperationWarning($operation, "Some lines failed to add", [
				'invoice_id' => $facture->id,
				'added_lines' => $addedLines,
				'failed_lines' => $failedLines,
				'total_lines' => count($lines),
				'error' => $this->error
			]);
		} else {
			$this->logDebug($operation, "All lines added successfully", [
				'invoice_id' => $facture->id,
				'total_lines' => $addedLines
			]);
		}

		return $failedLines === 0;
	}

	/**
	 * Call the Dolibarr 23 customer-invoice line API with its exact argument order.
	 *
	 * Header recalculation is deferred until all lines have been inserted so one
	 * rounding method can be applied consistently to the complete invoice.
	 *
	 * @param Facture $facture Invoice
	 * @param array $lineData Mapped line
	 * @param int $productId Product ID
	 * @param string $priceBaseType HT or TTC
	 * @param string $price Exact decimal unit price
	 * @return int
	 */
	private function addCustomerInvoiceLineUsingNativeApi($facture, array $lineData, $productId, $priceBaseType, $price)
	{
		return $facture->addline(
			(string) ($lineData['desc'] ?? ''),
			$priceBaseType === 'HT' ? $price : '0',
			$lineData['qty'],
			$lineData['tva_tx'],
			0,
			0,
			(int) $productId,
			$lineData['remise_percent'],
			$lineData['date_start'],
			$lineData['date_end'],
			0,
			0,
			0,
			$priceBaseType,
			$priceBaseType === 'TTC' ? $price : '0',
			0,
			-1,
			0,
			'',
			0,
			0,
			null,
			'0',
			'',
			(array) ($lineData['array_options'] ?? array()),
			100,
			0,
			null,
			'0',
			'',
			1
		);
	}

	/**
	 * Return configured generic product id for missing ZoneSoft product mappings.
	 *
	 * @return int
	 */
	private function getConfiguredGenericInvoiceProductId()
	{
		global $conf;

		return (int) ($conf->global->ZS_GENERIC_PRODUCT_ID ?? 0);
	}

	/**
	 * Resolve the product id to use for one invoice line.
	 *
	 * @param int $requestedProductId
	 * @param int $fallbackProductId
	 * @param array $lineData
	 * @param int $lineIndex
	 * @param string $operation
	 * @return int
	 */
	private function resolveInvoiceLineProductId($requestedProductId, $fallbackProductId, array $lineData, $lineIndex, $operation)
	{
		if ($requestedProductId > 0) {
			return $requestedProductId;
		}

		if ($fallbackProductId > 0) {
			$this->logOperationWarning($operation, "Missing line product mapping, using configured generic product", [
				'line_index' => $lineIndex,
				'line_desc' => substr((string) ($lineData['desc'] ?? ''), 0, 50),
				'generic_product_id' => $fallbackProductId
			]);
			return $fallbackProductId;
		}

		return 0;
	}

	/**
	 * Determine whether a failed line should be retried with the generic product.
	 *
	 * @param array $lineErrors
	 * @return bool
	 */
	private function shouldRetryWithGenericProduct(array $lineErrors)
	{
		if (empty($lineErrors)) {
			return false;
		}

		foreach ($lineErrors as $lineError) {
			$normalized = strtolower((string) $lineError);
			if (strpos($normalized, 'errorproductiddoesnotexists') !== false) {
				return true;
			}
			if (strpos($normalized, 'productiddoesnotexists') !== false) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Read current Dolibarr line insertion errors from the invoice object.
	 *
	 * @param Facture $facture
	 * @return array
	 */
	private function extractFactureErrors($facture)
	{
		$lineErrors = [];
		if (!empty($facture->errors) && is_array($facture->errors)) {
			$lineErrors = $facture->errors;
		} elseif (!empty($facture->error)) {
			$lineErrors[] = $facture->error;
		}

		return $lineErrors;
	}

	/**
	 * Reject the legacy artificial rounding-line API.
	 *
	 * @param Facture $facture Invoice object
	 * @param mixed $expectedTotalHt Expected total without tax
	 * @param mixed $expectedTotalTtc Expected total with tax
	 * @param int $adjustmentProductId Product ID for adjustment line
	 * @return bool True on success, false on failure
	 */
	public function addInvoiceAdjustmentLine($facture, $expectedTotalHt, $expectedTotalTtc, $adjustmentProductId)
	{
		$this->error = 'Artificial invoice rounding lines are disabled; reconcile the residual within its VAT group';
		$this->logOperationError(__METHOD__, $this->error, [
			'invoice_id' => (int) ($facture->id ?? 0),
			'expected_ht' => (string) $expectedTotalHt,
			'expected_ttc' => (string) $expectedTotalTtc,
		]);

		return false;
	}
}

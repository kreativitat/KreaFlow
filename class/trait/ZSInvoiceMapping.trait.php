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
 * \file        class/trait/ZSInvoiceMapping.trait.php
 * \ingroup     dolizsynch
 * \brief       Data mapping methods between ZoneSoft and Dolibarr for invoices
 */

/**
 * Trait ZSInvoiceMapping
 *
 * Handles data transformation and mapping:
 * - Map ZoneSoft invoice to Dolibarr format
 * - Map payment types
 * - Map product codes
 * - Generate references
 */
require_once dirname(__DIR__).'/zsinvoiceproperties.class.php';

trait ZSInvoiceMapping
{
	/**
	 * Recognize a fixed discount independently confirmed by source line cents.
	 * Source line cents must independently confirm the fixed-amount interpretation;
	 * the invoice calculator still validates every line, VAT group and header.
	 */
	private function isSourceConfirmedFixedDiscount($gross, $amount, $percent, $sourceTtc, $vat, $globalDiscount, $pricesIncludeVat)
	{
		if ($sourceTtc === null || ZSDecimal::compare($gross, '0') <= 0
			|| ZSDecimal::compare($amount, '0') <= 0 || ZSDecimal::compare($amount, $gross) > 0
			|| ZSDecimal::compare($percent, '0') <= 0) {
			return false;
		}
		$net = ZSDecimal::divideByPowerOfTen(
			ZSDecimal::multiply(ZSDecimal::subtract($gross, $amount), ZSDecimal::subtract('100', $globalDiscount)), 2
		);
		$ttc = $pricesIncludeVat ? $net : ZSDecimal::divideByPowerOfTen(ZSDecimal::multiply($net, ZSDecimal::add('100', $vat)), 2);
		return ZSDecimal::compare(ZSDecimal::round($ttc, 2), ZSDecimal::round($sourceTtc, 2)) === 0;
	}

	/**
	 * Convert a ZoneSoft local date string to a server-local timestamp.
	 *
	 * @param string|null $value
	 * @return int
	 */
	private function convertZsDateToTimestamp($value)
	{
		$rawValue = trim((string) $value);
		if ($rawValue === '') {
			return 0;
		}

		if (function_exists('dol_stringtotime')) {
			// ZoneSoft sends local business time. The second argument is a GMT
			// boolean, not a display timezone mode, so it must remain false.
			$timestamp = dol_stringtotime($rawValue, false);
			return ($timestamp === false) ? 0 : (int) $timestamp;
		}

		$timestamp = strtotime($rawValue);
		return ($timestamp === false) ? 0 : (int) $timestamp;
	}

	/**
	 * Map ZoneSoft invoice to Dolibarr invoice format
	 *
	 * @param object $zsInvoice ZoneSoft invoice object
	 * @return array|false Mapped Dolibarr invoice array or false on error
	 */
	public function mapZSToDolibarrInvoice($zsInvoice)
	{
		global $conf;

		$operation = __METHOD__;
		$this->error = '';

		$this->logDebug($operation, "Mapping ZS invoice to Dolibarr", [
			'doc' => $zsInvoice->doc ?? 'unknown',
			'numero' => $zsInvoice->numero ?? 'unknown'
		]);

		try {
			if ($this->isExcludedZsbmsDocumentType($zsInvoice->doc ?? '')) {
				$this->error = "Document type " . dolizsynch_normalize_zsbms_document_type($zsInvoice->doc ?? '') . " is excluded from invoice synchronization";
				$this->logOperationWarning($operation, $this->error, [
					'doc' => $zsInvoice->doc ?? '',
					'serie' => $zsInvoice->serie ?? '',
					'numero' => $zsInvoice->numero ?? '',
					'config_key' => 'DOLIZSYNCH_EXCLUDED_ZSBMS_DOCUMENT_TYPES'
				]);
				return false;
			}

			// Preserve optional reference evidence without replacing the fiscal POS reference.
			$saleProperties = ZSInvoiceProperties::extract(
				$zsInvoice,
				(int) ($conf->global->ZS_API_STORE ?? 0)
			);

			// Build customer identifier with country code
			$customer = ($zsInvoice->countrycode ?? 'PT') . ($zsInvoice->contribuinte ?? '');
			$name = $zsInvoice->nome ?? 'Cliente Genérico';

			// Validate customer
			$customer_id = $this->customerValidation($customer, $name);

			if ($customer_id <= 0) {
				$this->logOperationError($operation, "Customer validation failed", [
					'contribuinte' => $zsInvoice->contribuinte ?? 'unknown',
					'customer' => $customer
				]);
				return false;
			}

			try {
				$sourceTotalTtc = ZSDecimal::normalize((string) ($zsInvoice->total ?? ''));
				$sourceTotalHt = ZSDecimal::normalize((string) ($zsInvoice->liquido ?? ''));
				$globalDiscount = ZSDecimal::normalize((string) ($zsInvoice->dpercent ?? '0'));
				$globalDiscountAmount = ZSDecimal::normalize((string) ($zsInvoice->descontos ?? '0'));
			} catch (InvalidArgumentException $e) {
				$this->error = 'ZoneSoft invoice contains an invalid monetary value';
				return false;
			}
			$signedCreditNote = ($zsInvoice->doc ?? '') === 'NC' && ZSDecimal::compare($sourceTotalTtc, '0') < 0;
			if ($signedCreditNote && ZSDecimal::compare($sourceTotalHt, '0') > 0) {
				$this->error = 'ZoneSoft credit note contains inconsistent header signs';
				return false;
			}
			if (ZSDecimal::compare($globalDiscount, '0') < 0 || ZSDecimal::compare($globalDiscount, '100') > 0
				|| ZSDecimal::compare($globalDiscountAmount, '0') < 0) {
				$this->error = 'ZoneSoft invoice contains an invalid global discount';
				return false;
			}

			// Map basic invoice data - using original field names that match ZoneSoft API
			$dolibarrInvoice = [
				'date' => strtotime($zsInvoice->data),  // Convert date string to Unix timestamp
				'total' => $sourceTotalTtc,
				'liquido' => $sourceTotalHt,
				'socid' => $customer_id,
				'customer_id' => $customer_id,  // Keep for backward compatibility
				'ref_client' => $zsInvoice->doc . '_' . $zsInvoice->serie . '_' . $zsInvoice->numero . '_' . date('Ymd', strtotime($zsInvoice->data)),
				'doc_type' => $zsInvoice->doc,
				'source_store' => (int) ($zsInvoice->loja ?? 0),
				'total_ht' => $sourceTotalHt,
				'note_public' => '',
				'note_private' => '',
				'remise_percent' => $globalDiscount,
				'remise_absolue' => $globalDiscountAmount,
					'lines' => [],
					'array_options' => [
						'options_invoice_number' => $zsInvoice->doc . '_' . $zsInvoice->serie . '_' . $zsInvoice->numero . '_' . date('Ymd', strtotime($zsInvoice->data)),
						'options_invoice_date_time' => $this->convertZsDateToTimestamp($zsInvoice->datahora ?? ''),
						'options_invoice_date' => $this->convertZsDateToTimestamp($zsInvoice->data ?? ''),
						'options_vendor_employee' => $zsInvoice->emp ?? ''
					]
				];

			$dolibarrInvoice['array_options'] = array_merge($dolibarrInvoice['array_options'], ZSInvoiceProperties::options($saleProperties));

			// Map invoice lines - ZoneSoft uses 'vendas' (sales), not 'linhas' (lines)
			if (isset($zsInvoice->vendas) && is_array($zsInvoice->vendas)) {
				// Get ZS_IVA_INCLUIDO configuration from Dolibarr global settings
				// IMPORTANT: DO NOT use $zsInvoice->ivaincluido from JSON - that field doesn't determine calculation mode
				// The global ZS_IVA_INCLUIDO is configured in admin/setup.php and applies to ALL invoices
				$ivaIncluido = !empty($conf->global->ZS_IVA_INCLUIDO) ? 1 : 0;

				$this->logDebug($operation, "Starting line mapping", [
					'iva_incluido_config' => $ivaIncluido,
					'line_count' => count($zsInvoice->vendas)
				]);

				$taxLines = [];
				foreach ($zsInvoice->vendas as $item) {
					if (!is_object($item)) {
						$this->error = 'ZoneSoft invoice contains a malformed line';
						return false;
					}
					$itemQty = ZSDecimal::normalize((string) ($item->qtd ?? '0'));
					$itemTotal = ZSDecimal::normalize((string) ($item->total ?? '0'));
					$itemPunit = ZSDecimal::normalize((string) ($item->punit ?? '0'));
					$itemLiquido = isset($item->liquido) ? ZSDecimal::normalize((string) $item->liquido) : null;
					$itemVat = ZSDecimal::normalize((string) ($item->iva ?? '0'));
					$itemDiscountPercent = ZSDecimal::normalize((string) ($item->desconto2 ?? '0'));
					$itemDiscountAmount = ZSDecimal::normalize((string) ($item->desconto ?? '0'));

					if ($signedCreditNote) {
						if (ZSDecimal::compare($itemQty, '0') > 0 || ZSDecimal::compare($itemTotal, '0') > 0
							|| ($itemLiquido !== null && ZSDecimal::compare($itemLiquido, '0') > 0)) {
							$this->error = 'ZoneSoft credit note contains inconsistent line signs';
							return false;
						}
						// ZoneSoft returns signed quantities and amounts. The exact plan
						// uses magnitudes; Dolibarr applies the credit-note sign once.
						$itemQty = ZSDecimal::negate($itemQty);
						$itemTotal = ZSDecimal::negate($itemTotal);
						$itemLiquido = $itemLiquido !== null ? ZSDecimal::negate($itemLiquido) : null;
						$itemDiscountAmount = ZSDecimal::negate($itemDiscountAmount);
					}

					foreach ([$itemQty, $itemPunit, $itemTotal, $itemDiscountPercent] as $magnitude) {
						if (ZSDecimal::compare($magnitude, '0') < 0) {
							$this->error = 'ZoneSoft invoice line contains an unexpected negative magnitude';
							return false;
						}
					}
					// A negative fixed discount is a ZoneSoft line surcharge used to
					// reconcile fractional quantities to authoritative line cents.
					// Keep the signed value and let the exact monetary plan validate it.
					if ($itemLiquido !== null && ZSDecimal::compare($itemLiquido, '0') < 0) {
						$this->error = 'ZoneSoft invoice line contains an unexpected negative net total';
						return false;
					}
					if (ZSDecimal::compare($itemDiscountPercent, '100') > 0
						|| ZSDecimal::compare($itemVat, '0') < 0
						|| ZSDecimal::compare($itemVat, '100') > 0) {
						$this->error = 'ZoneSoft invoice line contains an invalid discount or VAT rate';
						return false;
					}
					if (ZSDecimal::compare($itemQty, '0') === 0
						&& ZSDecimal::compare($itemTotal, '0') === 0
						&& ($itemLiquido === null || ZSDecimal::compare($itemLiquido, '0') === 0)) {
						continue;
					}

					$lineGross = ZSDecimal::multiply($itemQty, $itemPunit);
					$lineAfterDiscount = ZSDecimal::divideByPowerOfTen(
						ZSDecimal::multiply($lineGross, ZSDecimal::subtract('100', $itemDiscountPercent)),
						2
					);
					$percentageDiscountAmount = ZSDecimal::subtract($lineGross, $lineAfterDiscount);
					$effectiveFixedDiscountAmount = $itemDiscountAmount;
					$effectiveLineDiscountPercent = $itemDiscountPercent;
					if (ZSDecimal::compare($itemDiscountPercent, '0') > 0
						&& ZSDecimal::compare($itemDiscountAmount, '0') > 0
						&& ZSDecimal::compare(
							ZSDecimal::round($itemDiscountAmount, 2),
							ZSDecimal::round($percentageDiscountAmount, 2)
						) === 0) {
						// ZoneSoft may expose the same discount as both its percentage and
						// rounded monetary value. The monetary value is evidence, not a
						// second discount, when both representations reconcile at cents.
						$effectiveFixedDiscountAmount = '0';
					} elseif ($itemLiquido !== null && $this->isSourceConfirmedFixedDiscount(
						$lineGross, $itemDiscountAmount, $itemDiscountPercent, $itemTotal,
						$itemVat, $globalDiscount, (bool) $ivaIncluido
					)) {
						// The fixed amount owns the cents when source totals confirm it.
						// desconto2 may describe another price basis; retain it as evidence.
						// The calculator still checks source HT, VAT and invoice totals.
						$effectiveLineDiscountPercent = '0';
						$lineAfterDiscount = $lineGross;
					}
					if (ZSDecimal::compare($effectiveFixedDiscountAmount, $lineAfterDiscount) > 0) {
						$this->error = 'ZoneSoft invoice line fixed discount exceeds the discounted line amount';
						return false;
					}
					$lineAfterDiscount = ZSDecimal::subtract($lineAfterDiscount, $effectiveFixedDiscountAmount);
					$lineAfterDiscount = ZSDecimal::divideByPowerOfTen(
						ZSDecimal::multiply($lineAfterDiscount, ZSDecimal::subtract('100', $globalDiscount)),
						2
					);
					$effectiveDiscount = ZSDecimal::compare($lineGross, '0') === 0
						? '0'
						: ZSDecimal::divide(
							ZSDecimal::multiply(ZSDecimal::subtract($lineGross, $lineAfterDiscount), '100'),
							$lineGross
						);
					$priceBaseType = $ivaIncluido ? 'TTC' : 'HT';
					$puTtc = $ivaIncluido
						? $itemPunit
						: ZSDecimal::divideByPowerOfTen(ZSDecimal::multiply($itemPunit, ZSDecimal::add('100', $itemVat)), 2);
					$puHt = $ivaIncluido
						? ZSDecimal::divide(ZSDecimal::multiply($itemPunit, '100'), ZSDecimal::add('100', $itemVat))
						: $itemPunit;
					$dolibarrProductId = $this->findDolibarrProdIdFromCodigo($item->codigo, $item);

					$taxLine = [
						'quantity' => $itemQty,
						'unit_price' => $itemPunit,
						'discount_percent' => $effectiveLineDiscountPercent,
						'discount_amount' => $effectiveFixedDiscountAmount,
						'vat_rate' => $itemVat,
						'exemption' => trim((string) ($item->motivo_isencao ?? $item->isencao ?? '')),
					];
					if ($itemLiquido !== null) {
						$taxLine['source_total_ht'] = $itemLiquido;
						$taxLine['source_total_ttc'] = $itemTotal;
					}
					$taxLines[] = $taxLine;

					$line = [
						'fk_product' => $dolibarrProductId,
						'qty' => $itemQty,
						'tva_tx' => $itemVat,
						'remise_percent' => $effectiveDiscount,
						'remise' => $effectiveFixedDiscountAmount,
						'pu_ht' => $puHt,
						'pu_ttc' => $puTtc,
						'price_base_type' => $priceBaseType,
						'desc' => $item->descricao,
						'date_start' => $this->convertZsDateToTimestamp($item->datahora ?? ''),
						'date_end' => '',
						'source_remise_percent' => $itemDiscountPercent,
						'array_options' => [
							'options_zs_posto' => $item->posto ?? '',
							'options_zs_origem' => $item->origem ?? '',
							'options_zs_prod_vendor_employee' => $item->empid ?? '',
						]
					];

					$dolibarrInvoice['lines'][] = $line;
				}

				$sourceContext = [
					'external_id' => (string) ($zsInvoice->loja ?? '') . '|' . (string) ($zsInvoice->doc ?? '') . '|' . (string) ($zsInvoice->serie ?? '') . '|' . (string) ($zsInvoice->numero ?? ''),
					'invoice_number' => $dolibarrInvoice['ref_client'],
				];
				$dolibarrInvoice['monetary_plan'] = ZSInvoiceTaxCalculator::reconcileSource(
					$taxLines,
					$globalDiscount,
					(bool) $ivaIncluido,
					$signedCreditNote ? ZSDecimal::absolute($sourceTotalHt) : $sourceTotalHt,
					null,
					$signedCreditNote ? ZSDecimal::absolute($sourceTotalTtc) : $sourceTotalTtc,
					$sourceContext
				);
				if (!empty($dolibarrInvoice['monetary_plan']['source_split_normalization'])) {
					$this->logOperationWarning($operation, 'Normalized VAT-inclusive source HT/VAT split from authoritative TTC', array_merge(
						$sourceContext,
						$dolibarrInvoice['monetary_plan']['source_split_normalization']
					));
				}
				if (!empty($dolibarrInvoice['monetary_plan']['source_ht_reconciliation'])) {
					$this->logOperationWarning($operation, 'Accepted VAT-exclusive authoritative source HT within one-cent displayed-input tolerance', array_merge(
						$sourceContext,
						$dolibarrInvoice['monetary_plan']['source_ht_reconciliation']
					));
				}
			}

			$this->logDebug($operation, "Successfully mapped invoice", [
				'ref_client' => $dolibarrInvoice['array_options']['options_invoice_number'],
				'socid' => $customer_id,
				'line_count' => count($dolibarrInvoice['lines'])
			]);

			return $dolibarrInvoice;

		} catch (Throwable $e) {
			$this->error = $e->getMessage();
			$this->logOperationError($operation, "Exception during mapping", [
				'exception' => $e->getMessage(),
				'doc' => $zsInvoice->doc ?? 'unknown'
			]);
			return false;
		}
	}

	/**
	 * Map ZoneSoft invoice line to Dolibarr line format
	 *
	 * @param object $zsLine ZoneSoft line object
	 * @return array Dolibarr line array
	 */
	private function mapZSLineToDolibarrLine($zsLine)
	{
		global $conf;

		// Find Dolibarr product ID
		$productId = $this->findDolibarrProdIdFromCodigo(
			$zsLine->codigo ?? '',
			$zsLine
		);

		return [
			'desc' => $zsLine->descricao ?? '',
			'subprice' => $zsLine->preco ?? 0,
			'qty' => $zsLine->quantidade ?? 1,
			'tva_tx' => $zsLine->iva ?? 0,
			'localtax1_tx' => 0,
			'localtax2_tx' => 0,
			'fk_product' => $productId,
			'remise_percent' => $zsLine->desconto ?? 0,
			'date_start' => null,
			'date_end' => null,
			'fk_fournprice' => null,
			'pa_ht' => 0,
			'label' => $zsLine->descricao ?? '',
			'product_type' => 0,
			'rang' => $zsLine->ordem ?? -1,
			'special_code' => 0,
			'fk_parent_line' => 0,
			'fk_unit' => null
		];
	}

	/**
	 * Generate Dolibarr invoice reference from ZoneSoft invoice
	 *
	 * @param object $zsInvoice ZoneSoft invoice
	 * @return string Invoice reference
	 */
	public function generateDolibarrInvoiceRef($zsInvoice)
	{
		return sprintf(
			'%s_%s_%s_%s',
			$zsInvoice->doc ?? 'FT',
			$zsInvoice->serie ?? '',
			$zsInvoice->numero ?? '',
			date('Ymd', strtotime($zsInvoice->data ?? 'now'))
		);
	}

	/**
	 * Map ZoneSoft payment type to Dolibarr payment type and bank account
	 *
	 * @param int $zsPaymentTypeId ZoneSoft payment type ID
	 * @return array|null Array with 'fk_paiement' and 'fk_account' or null if not found
	 */
	public function mapZAPIToDolibarrPaymentType($zsPaymentTypeId)
	{
		global $db, $conf;

		$operation = __METHOD__;
		$store = $conf->global->ZS_API_STORE;

		$sql = "SELECT fk_paiement, fk_account FROM " . MAIN_DB_PREFIX . "dolizsynch_zspaymenttype ";
		$sql .= "WHERE paymenttype_id = " . intval($zsPaymentTypeId) . " ";
		$sql .= "AND loja_zs = " . intval($store);

		$resql = $db->query($sql);

		if ($resql && $obj = $db->fetch_object($resql)) {
			$this->logDebug($operation, "Mapped payment type", [
				'zs_type_id' => $zsPaymentTypeId,
				'dolibarr_type_id' => $obj->fk_paiement,
				'account_id' => $obj->fk_account
			]);

			// Return both fk_paiement and fk_account in an associative array
			return array(
				'fk_paiement' => $obj->fk_paiement,
				'fk_account' => $obj->fk_account
			);
		}

		if (!$resql) {
			$this->logSqlError($operation, $db, [
				'zs_payment_type_id' => $zsPaymentTypeId,
				'store' => $store
			]);
		}

		$this->logOperationWarning($operation, "Payment type mapping not found", [
			'zs_type_id' => $zsPaymentTypeId,
			'store' => $store
		]);

		return null; // Return null if mapping not found
	}

	/**
	 * Find Dolibarr product ID from ZoneSoft product code
	 *
	 * @param string $zoneSoftProductId ZoneSoft product code
	 * @param object|null $zoneSoftProductDetails Additional product details
	 * @return int Dolibarr product ID or generic product ID
	 */
	public function findDolibarrProdIdFromCodigo($zoneSoftProductId, $zoneSoftProductDetails = null)
	{
		global $db, $conf;

		$operation = __METHOD__;

		// Search in products table
		$sql = "SELECT rowid FROM " . MAIN_DB_PREFIX . "product ";
		$sql .= "WHERE ref = '" . $db->escape($zoneSoftProductId) . "' ";
		$sql .= "LIMIT 1";

		$resql = $db->query($sql);

		if ($resql) {
			$obj = $db->fetch_object($resql);
			if ($obj) {
				$this->logDebug($operation, "Found product by ref", [
					'zs_product_code' => $zoneSoftProductId,
					'dolibarr_product_id' => $obj->rowid
				]);
				return $obj->rowid;
			}
		}

		// Product not found, check if auto-creation is enabled
		if ($conf->global->ZS_AUTO_CREATE_PRODUCTS ?? false) {
			$this->logDebug($operation, "Product not found, auto-creation enabled", [
				'zs_product_code' => $zoneSoftProductId
			]);

			// Create product from ZoneSoft data
			$productId = $this->createProductFromZoneSoft($zoneSoftProductId, $zoneSoftProductDetails);

			if ($productId > 0) {
				return $productId;
			}
		}

		// Return generic product ID
		$genericProductId = $conf->global->ZS_GENERIC_PRODUCT_ID ?? 0;

		$this->logDebug($operation, "Using generic product", [
			'zs_product_code' => $zoneSoftProductId,
			'generic_product_id' => $genericProductId
		]);

		return $genericProductId;
	}

	/**
	 * Create product from ZoneSoft data
	 *
	 * @param string $productCode Product code
	 * @param object|null $productDetails Product details from ZoneSoft
	 * @return int Product ID on success, 0 on failure
	 */
	private function createProductFromZoneSoft($productCode, $productDetails = null)
	{
		global $user, $db, $conf;

		$operation = __METHOD__;

		try {
			$actor = (!empty($user) && !empty($user->id)) ? $user : new User($db);
			if (empty($actor->id)) {
				$actor->id = 0;
			}

			$product = new Product($db);
			$product->ref = trim((string) $productCode);
			$product->label = trim((string) ($productDetails->descricao ?? $productCode));
			if ($product->label === '') {
				$product->label = $product->ref;
			}
			if (strlen($product->label) > 255) {
				$product->label = substr($product->label, 0, 255);
			}
			$product->type = 0; // Product (not service)
			$product->status = 1; // On sale
			$product->status_buy = 0;
			$product->price_base_type = strtoupper(trim((string) ($conf->global->PRODUCT_PRICE_BASE_TYPE ?? 'HT'))) === 'TTC' ? 'TTC' : 'HT';
			$product->price = (float) price2num((string) ($productDetails->preco ?? 0), 'MU');
			$product->tva_tx = (float) price2num((string) ($productDetails->iva ?? 0), 'MU');
			$product->price_label = '';
			$product->barcode = '';
			$product->accountancy_code_buy = '';
			$product->accountancy_code_buy_intra = '';
			$product->accountancy_code_buy_export = '';
			$product->accountancy_code_sell = '';
			$product->accountancy_code_sell_intra = '';
			$product->accountancy_code_sell_export = '';

			$result = $product->create($actor);

			if ($result > 0) {
				$this->logOperationSuccess($operation, [
					'product_code' => $productCode,
					'product_id' => $result
				]);
				return $result;
			} else {
				$this->logOperationError($operation, "Failed to create product", [
					'product_code' => $productCode,
					'error' => $product->error
				]);
				return 0;
			}

		} catch (Exception $e) {
			$this->logOperationError($operation, "Exception creating product", [
				'product_code' => $productCode,
				'exception' => $e->getMessage()
			]);
			return 0;
		}
	}
}

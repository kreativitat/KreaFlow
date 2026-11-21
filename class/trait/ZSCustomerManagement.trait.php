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
 * \file        class/trait/ZSCustomerManagement.trait.php
 * \ingroup     dolizsynch
 * \brief       Customer management methods for ZSInvoiceSynch
 */

/**
 * Trait ZSCustomerManagement
 *
 * Handles customer-related operations:
 * - Customer validation
 * - Customer creation
 * - VAT/NIF validation
 * - Customer search and matching
 */
trait ZSCustomerManagement
{
	/**
	 * Validate and create customer if needed
	 *
	 * @param string $customer Customer VAT number
	 * @param string $name Customer name
	 * @return int Customer ID (socid) or 0 if default customer
	 */
	public function customerValidation($customer, $name)
	{
		global $conf;

		$operation = __METHOD__;

		$this->logDebug($operation, "Validating customer", [
			'vat_number' => $customer,
			'name' => $name
		]);

		// Get the default "consumidor final" customer ID
		$defaultConsumidorFinalId = $conf->global->ZS_CONSUMIDOR_FINAL ?? 0;

		// Check if VAT contains dashes (generic/consumidor final customer)
		if (preg_match('/--/', $customer)) {
			if ($defaultConsumidorFinalId > 0) {
				$this->logDebug($operation, "Generic customer detected (contains --), using default consumer final", [
					'vat_number' => $customer,
					'socid' => $defaultConsumidorFinalId
				]);
				return $defaultConsumidorFinalId;
			} else {
				$this->logOperationError($operation, "Generic customer detected but ZS_CONSUMIDOR_FINAL not configured", [
					'vat_number' => $customer
				]);
				return 0;
			}
		}

		// Try to find existing customer by VAT
		$socid = $this->findSocidsByPartialVAT($customer);

		if ($socid > 0) {
			$this->logDebug($operation, "Found existing customer", [
				'vat_number' => $customer,
				'socid' => $socid
			]);
			return $socid;
		}

		// Validate NIF before creating new customer
		if (!$this->nifValidation($customer)) {
			if ($defaultConsumidorFinalId > 0) {
				$this->logDebug($operation, "Invalid NIF, using default consumer final", [
					'vat_number' => $customer,
					'socid' => $defaultConsumidorFinalId
				]);
				return $defaultConsumidorFinalId;
			} else {
				$this->logOperationError($operation, "Invalid NIF and ZS_CONSUMIDOR_FINAL not configured", [
					'vat_number' => $customer
				]);
				return 0;
			}
		}

		// Check if automatic customer creation is allowed
		if (empty($conf->global->ZS_CREATE_NEW_CUSTOMERS)) {
			if ($defaultConsumidorFinalId > 0) {
				$this->logDebug($operation, "Auto-creation disabled, using default consumer final", [
					'vat_number' => $customer,
					'socid' => $defaultConsumidorFinalId
				]);
				return $defaultConsumidorFinalId;
			} else {
				$this->logOperationError($operation, "Auto-creation disabled and ZS_CONSUMIDOR_FINAL not configured", [
					'vat_number' => $customer
				]);
				return 0;
			}
		}

		// Customer not found, create new one
		$this->logDebug($operation, "Customer not found, creating new", [
			'vat_number' => $customer,
			'name' => $name
		]);

		return $this->createCustomerWithVAT($customer, $name);
	}

	/**
	 * Create customer with VAT number
	 *
	 * @param string $vatNumber VAT number
	 * @param string $socName Customer name
	 * @return int Customer ID on success, 0 on failure
	 */
	public function createCustomerWithVAT($vatNumber, $socName)
	{
		global $user, $db, $conf;

		$operation = __METHOD__;

		$this->logDebug($operation, "Creating customer", [
			'vat_number' => $vatNumber,
			'name' => $socName
		]);

		$societe = new Societe($db);
		$societe->name = $socName;
		$societe->client = 1;
		$societe->tva_intra = $vatNumber;
		// Avoid null local-tax fields that can trigger PHP 8.1+ warnings in core Societe update path.
		$societe->localtax1_assuj = 0;
		$societe->localtax2_assuj = 0;
		$societe->localtax1_value = '0';
		$societe->localtax2_value = '0';

		// Sanitize customer code - remove invalid characters for Dolibarr
		// Replace dashes and other special chars with underscores
		$customerCode = preg_replace('/[^A-Z0-9]/i', '_', $vatNumber);
		$societe->code_client = $customerCode;

		$this->logDebug($operation, "Sanitized customer code", [
			'original_vat' => $vatNumber,
			'customer_code' => $customerCode
		]);

		$result = $societe->create($user);

		if ($result < 0) {
			foreach ($societe->errors as $errmsg) {
				$this->logOperationError($operation, "Failed to create customer", [
					'vat_number' => $vatNumber,
					'name' => $socName,
					'customer_code' => $customerCode,
					'error' => $errmsg
				]);
			}
			return 0;
		}

		$this->logOperationSuccess($operation, [
			'vat_number' => $vatNumber,
			'name' => $socName,
			'socid' => $result,
			'code_client' => $customerCode
		]);

		return $result;
	}

	/**
	 * Find customer by partial VAT match
	 *
	 * @param string $partialVATNumber Partial VAT number to search
	 * @return int Customer ID or 0 if not found
	 */
	public function findSocidsByPartialVAT($partialVATNumber)
	{
		global $db;

		$operation = __METHOD__;

		$sql = "SELECT rowid FROM " . MAIN_DB_PREFIX . "societe ";
		$sql .= "WHERE tva_intra LIKE '%" . $db->escape($partialVATNumber) . "%' ";
		$sql .= "LIMIT 1";

		$resql = $db->query($sql);

		if ($resql) {
			$obj = $db->fetch_object($resql);
			if ($obj) {
				$this->logDebug($operation, "Found customer by partial VAT", [
					'partial_vat' => $partialVATNumber,
					'socid' => $obj->rowid
				]);
				return $obj->rowid;
			}
		} else {
			$this->logSqlError($operation, $db, [
				'partial_vat' => $partialVATNumber
			]);
		}

		return 0;
	}

	/**
	 * Validate Portuguese NIF (VAT number)
	 *
	 * @param string $nif NIF to validate
	 * @param bool $ignoreFirst Whether to ignore first character
	 * @return bool True if valid, false otherwise
	 */
	public function nifValidation($nif, $ignoreFirst = true)
	{
		$operation = __METHOD__;

		// Remove non-numeric characters
		$nif = preg_replace('/[^0-9]/', '', $nif);

		// Check length
		if (strlen($nif) != 9) {
			$this->logDebug($operation, "Invalid NIF length", [
				'nif' => $nif,
				'length' => strlen($nif)
			]);
			return false;
		}

		// Validate check digit
		$check = 0;
		for ($i = 0; $i < 8; $i++) {
			$check += $nif[$i] * (9 - $i);
		}

		$check = 11 - ($check % 11);
		if ($check >= 10) {
			$check = 0;
		}

		$isValid = ($check == $nif[8]);

		if (!$isValid) {
			$this->logDebug($operation, "Invalid NIF check digit", [
				'nif' => $nif,
				'expected_check' => $check,
				'actual_check' => $nif[8]
			]);
		}

		return $isValid;
	}

	/**
	 * Create customer with enhanced error recovery
	 *
	 * @param array $customerData Customer data array
	 * @return int Customer ID on success, 0 on failure
	 */
	public function createCustomerWithRecovery(array $customerData)
	{
		$operation = __METHOD__;

		$this->logOperationStart($operation, [
			'vat_number' => $customerData['vat_number'] ?? 'unknown',
			'name' => $customerData['name'] ?? 'unknown'
		]);

		try {
			// First attempt: standard creation
			$socid = $this->createCustomerWithVAT(
				$customerData['vat_number'],
				$customerData['name']
			);

			if ($socid > 0) {
				return $this->cronSuccess($operation, [
					'socid' => $socid,
					'method' => 'standard_creation'
				]);
			}

			// Second attempt: check if customer already exists
			$socid = $this->findSocidsByPartialVAT($customerData['vat_number']);

			if ($socid > 0) {
				$this->logOperationWarning($operation, "Customer already exists", [
					'vat_number' => $customerData['vat_number'],
					'socid' => $socid
				]);
				return $socid;
			}

			// Third attempt: try with modified name
			if (isset($customerData['alternative_name'])) {
				$socid = $this->createCustomerWithVAT(
					$customerData['vat_number'],
					$customerData['alternative_name']
				);

				if ($socid > 0) {
					$this->logOperationWarning($operation, "Created with alternative name", [
						'socid' => $socid,
						'original_name' => $customerData['name'],
						'alternative_name' => $customerData['alternative_name']
					]);
					return $socid;
				}
			}

			// All attempts failed
			$this->logOperationError($operation, "All customer creation attempts failed", [
				'vat_number' => $customerData['vat_number']
			]);

			return 0;

		} catch (Exception $e) {
			$this->logOperationError($operation, "Exception during customer creation", [
				'vat_number' => $customerData['vat_number'],
				'exception' => $e->getMessage()
			]);
			return 0;
		}
	}
}

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
//require_once DOL_DOCUMENT_ROOT . '/product/class/product.class.php';
require_once DOL_DOCUMENT_ROOT . '/custom/kreaflow/class/zsapisynch.class.php';
require_once DOL_DOCUMENT_ROOT . '/custom/kreaflow/class/zsprodsynch.class.php';



/**
 * API Logic for ZoneSoft integration in Dolibarr
 */
class ZSDictionairSynch
{
	public $error = '';
	public $errors = array();

	/**
	 * Constructor for ZSDictionairSynch
	 *
	 * @param object $db Database connection object
	 */
	public function __construct($db)
	{

		$this->db = $db;
		$this->zoneSoftApi = new ZSAPISynch($db);
	}

	/**
	 * Build compact key-based summary for tax payload logging.
	 *
	 * @param mixed $value
	 * @return string
	 */
	private function summarizeValueForLog($value)
	{
		if (is_object($value)) {
			$keys = array_keys(get_object_vars($value));
			$preview = array_slice($keys, 0, 10);
			$preview = array_map('strval', $preview);
			return 'object(' . get_class($value) . ', keys=[' . implode(', ', $preview) . (count($keys) > 10 ? ', ...' : '') . '])';
		}
		if (is_array($value)) {
			$keys = array_keys($value);
			$preview = array_slice($keys, 0, 10);
			$preview = array_map('strval', $preview);
			return 'array(keys=[' . implode(', ', $preview) . (count($keys) > 10 ? ', ...' : '') . '])';
		}
		if (is_string($value)) {
			$value = str_replace(array("\r", "\n"), array('\r', '\n'), $value);
			return strlen($value) > 256 ? substr($value, 0, 256) . '... [truncated]' : $value;
		}
		if (is_bool($value)) {
			return $value ? 'true' : 'false';
		}
		if ($value === null) {
			return 'null';
		}

		return (string) $value;
	}

	/**
	 * Validate that the current store is explicitly configured for the active entity.
	 *
	 * This prevents dictionary writes under a mismatched entity/store pair when
	 * constants are stale or inherited from another scope.
	 *
	 * @param int $store
	 * @param int $entity
	 * @param string $context
	 * @return bool
	 */
	private function validateEntityStoreScope($store, $entity, $context)
	{
		if ((int) $store <= 0) {
			dol_syslog($context . ": Missing or invalid ZS_API_STORE for entity " . ((int) $entity), LOG_ERR);
			return false;
		}

		$sql = "SELECT rowid FROM " . MAIN_DB_PREFIX . "const ";
		$sql .= "WHERE name = 'ZS_API_STORE' ";
		$sql .= "AND entity = " . ((int) $entity) . " ";
		$sql .= "AND value = '" . $this->db->escape((string) ((int) $store)) . "' ";
		$sql .= $this->db->plimit(1);
		$resql = $this->db->query($sql);
		if (!$resql) {
			dol_syslog($context . ": Failed to validate store/entity mapping - " . $this->db->lasterror(), LOG_ERR);
			return false;
		}

		if ($this->db->num_rows($resql) <= 0) {
			dol_syslog(
				$context . ": Store/entity mismatch detected (store=" . ((int) $store) . ", entity=" . ((int) $entity) . "). "
				. "Aborting dictionary sync to avoid cross-entity data contamination.",
				LOG_ERR
			);
			return false;
		}

		return true;
	}

	/**
	 * Fetches families from ZoneSoft API and saves or updates them in the database.
	 *
	 * @return bool True on success, false on failure.
	 */
	public function storezsfamilies()
	{
		global $conf;
		$store = (int) ($conf->global->ZS_API_STORE ?? 0);
		$entity = (int) $conf->entity;
		if (!$this->validateEntityStoreScope($store, $entity, __METHOD__)) {
			return false;
		}

		$response = json_decode(json_encode($this->zoneSoftApi->fetchzs('Families', 'family', 'getInstances')));

		if ($response && isset($response->Response) && $response->Response->StatusCode == 200) {
			$families = $response->Response->Content->family;

			if (empty($families)) {
				dol_syslog("No families found in the response", LOG_INFO);
				return true; // No families to process
			}

			foreach ($families as $family) {
				// Check if parent family exists (subfamily_id = 0)
				$sqlCheckFamily = "SELECT rowid FROM " . MAIN_DB_PREFIX . "dolizsynch_zsfamily ";
				$sqlCheckFamily .= "WHERE loja_zs = " . $store . " AND entity = " . $entity . " AND family_id = " . (int) $family->codigo;
				$sqlCheckFamily .= " AND subfamily_id = 0";
				$resqlCheckFamily = $this->db->query($sqlCheckFamily);

				if ($resqlCheckFamily && $this->db->num_rows($resqlCheckFamily) > 0) {
					// Family exists, update it
					$sqlUpdateFamily = "UPDATE " . MAIN_DB_PREFIX . "dolizsynch_zsfamily SET ";
					$sqlUpdateFamily .= "family_name = '" . $this->db->escape($family->descricao) . "' ";
					$sqlUpdateFamily .= "WHERE loja_zs = " . $store . " AND entity = " . $entity . " AND family_id = " . (int) $family->codigo;
					$sqlUpdateFamily .= " AND subfamily_id = 0";
					if (!$this->db->query($sqlUpdateFamily)) {
						dol_syslog("Error updating family in database: " . $this->db->lasterror(), LOG_ERR);
						return false;
					}
				} else {
					// Family does not exist, insert parent family row with subfamily_id = 0
					$sqlInsertFamily = "INSERT INTO " . MAIN_DB_PREFIX . "dolizsynch_zsfamily (entity, loja_zs, family_id, family_name, subfamily_id, subfamily_name) VALUES ";
					$sqlInsertFamily .= "(" . $entity . ", " . $store . ", " . $this->db->escape($family->codigo) . ", '" . $this->db->escape($family->descricao) . "', 0, '-')";
					if (!$this->db->query($sqlInsertFamily)) {
						$insertError = (string) $this->db->lasterror();
						dol_syslog("Error inserting family into database: " . $insertError, LOG_ERR);
						if (stripos($insertError, 'unique_lojazs_family_subfamily') !== false) {
							dol_syslog(
								"Detected multicompany unique-key collision on dolizsynch_zsfamily. "
								. "Expected unique index includes entity (entity, loja_zs, family_id, subfamily_id). "
								. "Run module SQL migrations before retrying families sync.",
								LOG_ERR
							);
						}
						return false;
					}
				}

				// Handle subfamilies - check if property exists and is not empty
				if (isset($family->subfamilies) && is_array($family->subfamilies) && !empty($family->subfamilies)) {
					foreach ($family->subfamilies as $subfamily) {
						$sqlSub = "INSERT INTO " . MAIN_DB_PREFIX . "dolizsynch_zsfamily (entity, loja_zs, family_id, family_name, subfamily_id, subfamily_name) VALUES ";
						$sqlSub .= "(" . $entity . ", " . $store . ", " . $this->db->escape($family->codigo) . ", '" . $this->db->escape($family->descricao) . "', ";
						$sqlSub .= $this->db->escape($subfamily->codigo) . ", '" . $this->db->escape($subfamily->descricao) . "')";
						$sqlSub .= " ON DUPLICATE KEY UPDATE subfamily_name = '" . $this->db->escape($subfamily->descricao) . "', family_name = '" . $this->db->escape($family->descricao) . "'";

						if (!$this->db->query($sqlSub)) {
							dol_syslog("Error inserting/updating subfamily in database: " . $this->db->lasterror(), LOG_ERR);
							return false;
						}
					}
				}
			}

			dol_syslog("Families and subfamilies fetched and updated successfully", LOG_INFO);
			return true;
		} else {
			$errorMsg = "Failed to fetch families from ZoneSoft";
			if ($response) {
				$errorMsg .= " - StatusCode: " . (isset($response->Response->StatusCode) ? $response->Response->StatusCode : 'unknown');
				if (isset($response->Response->Message)) {
					$errorMsg .= " - Message: " . $response->Response->Message;
				}
			} else {
				$errorMsg .= " - No response received";
			}
			dol_syslog($errorMsg, LOG_ERR);
			return false;
		}
	}

	/**
	 * Fetches categories from ZoneSoft API and saves or updates them in the database.
	 *
	 * @return bool True on success, false on failure.
	 */

	public function storezscategories()
	{
		global $conf;
		$store = (int) ($conf->global->ZS_API_STORE ?? 0);
		$entity = (int) $conf->entity;

		$response = json_decode(json_encode($this->zoneSoftApi->fetchzs('Categories', 'category', 'getInstances')));

		if ($response && isset($response->Response) && $response->Response->StatusCode == 200) {
			$categories = $response->Response->Content->category;
			if (empty($categories)) {
				dol_syslog("No categories found in the response", LOG_INFO);
				return true; // No categories to process
			}

			foreach ($categories as $category) {
				// Check if category exists
				$sqlCheckCategory = "SELECT rowid FROM " . MAIN_DB_PREFIX . "dolizsynch_zscategory ";
				$sqlCheckCategory .= "WHERE loja_zs = " . $store . " AND entity = " . $entity . " AND category_id = " . (int) $category->codigo;
				$resqlCheckCategory = $this->db->query($sqlCheckCategory);

				if ($resqlCheckCategory && $this->db->num_rows($resqlCheckCategory) > 0) {
					// Category exists, update category and its subcategories
					$sqlUpdateCategory = "UPDATE " . MAIN_DB_PREFIX . "dolizsynch_zscategory SET ";
					$sqlUpdateCategory .= "category_name = '" . $this->db->escape($category->descricao) . "' ";
					$sqlUpdateCategory .= "WHERE loja_zs = " . $store . " AND entity = " . $entity . " AND category_id = " . (int) $category->codigo;
					if (!$this->db->query($sqlUpdateCategory)) {
						dol_syslog("Error updating category in database: " . $this->db->lasterror(), LOG_ERR);
						return false;
					}
				} else {
					// Category does not exist, insert category
					$sqlInsertCategory = "INSERT INTO " . MAIN_DB_PREFIX . "dolizsynch_zscategory (entity, loja_zs, category_id, category_name) VALUES ";
					$sqlInsertCategory .= "(" . $entity . ", " . $store . ", " . $this->db->escape($category->codigo) . ", '" . $this->db->escape($category->descricao) . "')";
					if (!$this->db->query($sqlInsertCategory)) {
						dol_syslog("Error inserting category into database: " . $this->db->lasterror(), LOG_ERR);
						return false;
					}
				}

				// Handle subcategories
				if (!isset($category->subcategories) || !is_array($category->subcategories)) {
					continue;
				}
				foreach ($category->subcategories as $subcategory) {
					$sqlSub = "INSERT INTO " . MAIN_DB_PREFIX . "dolizsynch_zscategory (entity, loja_zs, category_id, category_name, subcategory_id, subcategory_name) VALUES ";
					$sqlSub .= "(" . $entity . ", " . $store . ", " . $this->db->escape($category->codigo) . ", '" . $this->db->escape($category->descricao) . "', ";
					$sqlSub .= $this->db->escape($subcategory->codigo) . ", '" . $this->db->escape($subcategory->descricao) . "')";
					$sqlSub .= " ON DUPLICATE KEY UPDATE subcategory_name = '" . $this->db->escape($subcategory->descricao) . "'";

					if (!$this->db->query($sqlSub)) {
						dol_syslog("Error inserting/updating subcategory in database: " . $this->db->lasterror(), LOG_ERR);
						return false;
					}
				}
			}

			dol_syslog("Categories and subcategories fetched and updated successfully", LOG_INFO);
			return true;
		} else {
			dol_syslog("Failed to fetch categories from ZoneSoft", LOG_ERR);
			return false;
		}
	}

	/**
	 * Fetches Payment Types from ZoneSoft API and saves or updates them in the database.
	 *
	 * @return bool True on success, false on failure.
	 */
	public function storezspaymenttypes()
	{
		global $conf;
		$store = (int) ($conf->global->ZS_API_STORE ?? 0);
		$entity = (int) $conf->entity;

		$response = json_decode(json_encode($this->zoneSoftApi->fetchzs('Paymenttypes', 'paymenttype', 'getInstances')));

		if ($response && isset($response->Response) && $response->Response->StatusCode == 200) {
			$paymentTypes = $response->Response->Content->paymenttype;
			if (empty($paymentTypes)) {
				dol_syslog("No Payment Types found in the response", LOG_INFO);
				return true; // No payment type to process
			}

			foreach ($paymentTypes as $paymentType) {
				// Check if payment types exists
				$sqlCheckPaymentType = "SELECT rowid FROM " . MAIN_DB_PREFIX . "dolizsynch_zspaymenttype ";
				$sqlCheckPaymentType .= "WHERE loja_zs = " . $store . " AND entity = " . $entity . " AND paymenttype_id = " . (int) $paymentType->codigo;
				$resqlCheckPaymentType = $this->db->query($sqlCheckPaymentType);

				if ($resqlCheckPaymentType && $this->db->num_rows($resqlCheckPaymentType) > 0) {
					// Payment type exists, update PaymentType and its subfamilies
					$sqlUpdatePaymentType = "UPDATE " . MAIN_DB_PREFIX . "dolizsynch_zspaymenttype SET ";
					$sqlUpdatePaymentType .= "paymenttype_name = '" . $this->db->escape($paymentType->descricao) . "', ";
					$sqlUpdatePaymentType .= "cash = '" . $this->db->escape($paymentType->depositar) . "' ";
					$sqlUpdatePaymentType .= "WHERE loja_zs = " . $store . " AND entity = " . $entity . " AND paymenttype_id = " . (int) $paymentType->codigo;
					if (!$this->db->query($sqlUpdatePaymentType)) {
						dol_syslog("Error updating Payment Type in database: " . $this->db->lasterror(), LOG_ERR);
						return false;
					}
				} else {
					// PaymentType does not exist, insert PaymentType
					$sqlInsertPaymentType = "INSERT INTO " . MAIN_DB_PREFIX . "dolizsynch_zspaymenttype (entity, loja_zs, paymenttype_id, paymenttype_name, cash) VALUES ";
					$sqlInsertPaymentType .= "(" . $entity . ", " . $store . ", " . $this->db->escape($paymentType->codigo) . ", '" . $this->db->escape($paymentType->descricao) . "', '" . $this->db->escape($paymentType->depositar) . "')";
					if (!$this->db->query($sqlInsertPaymentType)) {
						dol_syslog("Error inserting Payment Type into database: " . $this->db->lasterror(), LOG_ERR);
						return false;
					}
				}
			}

			dol_syslog("Payment types fetched and updated successfully", LOG_INFO);
			return true;
		} else {
			dol_syslog("Failed to fetch Payment types from ZoneSoft", LOG_ERR);
			return false;
		}
	}

	/**
	 * Fetches Units from ZoneSoft API and saves or updates them in the database.
	 *
	 * @return bool True on success, false on failure.
	 */
	public function storezsunits()
	{
		global $conf;
		$store = (int) ($conf->global->ZS_API_STORE ?? 0);
		$entity = (int) $conf->entity;

		$unitsResponse = json_decode(json_encode($this->zoneSoftApi->fetchzs('Units', 'unit', 'getInstances')));

		if ($unitsResponse && isset($unitsResponse->Response) && $unitsResponse->Response->StatusCode == 200) {
			$units = $unitsResponse->Response->Content->unit;
			if (empty($units)) {
				dol_syslog("No Units found in the response", LOG_INFO);
				return true; // No units to process
			}

			foreach ($units as $unit) {
				// Check if unit exists
				$sqlCheckUnit = "SELECT rowid FROM " . MAIN_DB_PREFIX . "dolizsynch_zsunit ";
				$sqlCheckUnit .= "WHERE loja_zs = " . $store . " AND entity = " . $entity . " AND unit_id = " . (int) $unit->codigo;
				$resqlCheckUnit = $this->db->query($sqlCheckUnit);

				if ($resqlCheckUnit && $this->db->num_rows($resqlCheckUnit) > 0) {
					// Unit exists, update Unit
					$sqlUpdateUnit = "UPDATE " . MAIN_DB_PREFIX . "dolizsynch_zsunit SET ";
					$sqlUpdateUnit .= "unit_name = '" . $this->db->escape($unit->descricao) . "' ";
					$sqlUpdateUnit .= "WHERE loja_zs = " . $store . " AND entity = " . $entity . " AND unit_id = " . (int) $unit->codigo;
					if (!$this->db->query($sqlUpdateUnit)) {
						dol_syslog("Error updating Unit in database: " . $this->db->lasterror(), LOG_ERR);
						return false;
					}
				} else {
					// Unit does not exist, insert Unit
					$sqlInsertUnit = "INSERT INTO " . MAIN_DB_PREFIX . "dolizsynch_zsunit (entity, loja_zs, unit_id, unit_name) VALUES ";
					$sqlInsertUnit .= "(" . $entity . ", " . $store . ", " . $this->db->escape($unit->codigo) . ", '" . $this->db->escape($unit->descricao) . "')";
					if (!$this->db->query($sqlInsertUnit)) {
						dol_syslog("Error inserting Unit into database: " . $this->db->lasterror(), LOG_ERR);
						return false;
					}
				}
			}

			dol_syslog("Units fetched and updated successfully", LOG_INFO);
			return true;
		} else {
			dol_syslog("Failed to fetch Units from ZoneSoft", LOG_ERR);
			return false;
		}
	}

	/**
	 * Fetches ProdCentres from ZoneSoft API and saves or updates them in the database.
	 *
	 * @return bool True on success, false on failure.
	 */
	public function storezsproductioncentres()
	{
		global $conf;
		$store = (int) ($conf->global->ZS_API_STORE ?? 0);
		$entity = (int) $conf->entity;

		$prodCentresResponse = json_decode(json_encode($this->zoneSoftApi->fetchzs('Productioncentres', 'productioncentre', 'getInstances')));

		if ($prodCentresResponse && isset($prodCentresResponse->Response) && $prodCentresResponse->Response->StatusCode == 200) {
			$prodCentres = $prodCentresResponse->Response->Content->productioncentre;
			if (empty($prodCentres)) {
				dol_syslog("No ProdCentres found in the response", LOG_INFO);
				return true; // No prodCentres to process
			}

			foreach ($prodCentres as $prodCentre) {
				// Check if prodCentre exists
				$sqlCheckUnit = "SELECT rowid FROM " . MAIN_DB_PREFIX . "dolizsynch_zsprodcentre ";
				$sqlCheckUnit .= "WHERE loja_zs = " . $store . " AND entity = " . $entity . " AND prodcentre_id = " . (int) $prodCentre->codigo;
				$resqlCheckUnit = $this->db->query($sqlCheckUnit);

				if ($resqlCheckUnit && $this->db->num_rows($resqlCheckUnit) > 0) {
					// Unit exists, update Unit
					$sqlUpdateUnit = "UPDATE " . MAIN_DB_PREFIX . "dolizsynch_zsprodcentre SET ";
					$sqlUpdateUnit .= "prodcentre_name = '" . $this->db->escape($prodCentre->descricao) . "' ";
					$sqlUpdateUnit .= "WHERE loja_zs = " . $store . " AND entity = " . $entity . " AND prodcentre_id = " . (int) $prodCentre->codigo;
					if (!$this->db->query($sqlUpdateUnit)) {
						dol_syslog("Error updating Unit in database: " . $this->db->lasterror(), LOG_ERR);
						return false;
					}
				} else {
					// Unit does not exist, insert Unit
					$sqlInsertUnit = "INSERT INTO " . MAIN_DB_PREFIX . "dolizsynch_zsprodcentre (entity, loja_zs, prodcentre_id, prodcentre_name) VALUES ";
					$sqlInsertUnit .= "(" . $entity . ", " . $store . ", " . $this->db->escape($prodCentre->codigo) . ", '" . $this->db->escape($prodCentre->descricao) . "')";
					if (!$this->db->query($sqlInsertUnit)) {
						dol_syslog("Error inserting Unit into database: " . $this->db->lasterror(), LOG_ERR);
						return false;
					}
				}
			}

			dol_syslog("ProdCentres fetched and updated successfully", LOG_INFO);
			return true;
		} else {
			dol_syslog("Failed to fetch ProdCentres from ZoneSoft", LOG_ERR);
			return false;
		}
	}

	/**
	 * Fetches Groups from ZoneSoft API and saves or updates them in the database.
	 *
	 * @return bool True on success, false on failure.
	 */
	public function storezsgroups()
	{
		global $conf;
		$store = (int) ($conf->global->ZS_API_STORE ?? 0);
		$entity = (int) $conf->entity;

		$groupsResponse = json_decode(json_encode($this->zoneSoftApi->fetchzs('Groups', 'group', 'getInstances')));

		if ($groupsResponse && isset($groupsResponse->Response) && $groupsResponse->Response->StatusCode == 200) {
			$groups = isset($groupsResponse->Response->Content->group) ? $groupsResponse->Response->Content->group : array();
			if (empty($groups)) {
				dol_syslog("No Groups found in the response", LOG_INFO);
				return true; // No groups to process
			}
			if (!is_array($groups)) {
				$groups = array($groups);
			}

			foreach ($groups as $group) {
				if (!isset($group->codigo)) {
					continue;
				}

				$groupId = (int) $group->codigo;
				$groupName = isset($group->descricao) ? $this->db->escape((string) $group->descricao) : '';

				$sql = "INSERT INTO " . MAIN_DB_PREFIX . "dolizsynch_zsgroup (entity, loja_zs, group_id, group_name) VALUES ";
				$sql .= "(" . $entity . ", " . $store . ", " . $groupId . ", '" . $groupName . "')";
				$sql .= " ON DUPLICATE KEY UPDATE group_name = '" . $groupName . "', entity = " . $entity;

				if (!$this->db->query($sql)) {
					dol_syslog("Error inserting/updating group in database: " . $this->db->lasterror(), LOG_ERR);
					return false;
				}
			}

			dol_syslog("Groups fetched and updated successfully", LOG_INFO);
			return true;
		} else {
			dol_syslog("Failed to fetch Groups from ZoneSoft", LOG_ERR);
			return false;
		}
	}

	/**
	 * Normalize a tax rate value to decimal string format (xx.xx).
	 *
	 * @param mixed $value
	 * @return string
	 */
	private function normalizeTaxRate($value)
	{
		$value = trim((string) $value);
		if ($value === '') {
			return '';
		}

		if (function_exists('price2num')) {
			$numericValue = price2num($value, 'MU');
		} else {
			$numericValue = (float) str_replace(',', '.', $value);
		}

		if ($numericValue === '' || $numericValue === null || !is_numeric($numericValue)) {
			return '';
		}

		return number_format((float) $numericValue, 2, '.', '');
	}

	/**
	 * Normalize free-text for relaxed matching.
	 *
	 * @param string $value
	 * @return string
	 */
	private function normalizeTaxText($value)
	{
		$value = trim((string) $value);
		if ($value === '') {
			return '';
		}
		$value = function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
		$value = strtr(
			$value,
			array(
				'á' => 'a',
				'à' => 'a',
				'ã' => 'a',
				'â' => 'a',
				'é' => 'e',
				'è' => 'e',
				'ê' => 'e',
				'í' => 'i',
				'ì' => 'i',
				'î' => 'i',
				'ó' => 'o',
				'ò' => 'o',
				'õ' => 'o',
				'ô' => 'o',
				'ú' => 'u',
				'ù' => 'u',
				'û' => 'u',
				'ç' => 'c',
			)
		);
		return $value;
	}

	/**
	 * Extract first numeric percentage from text.
	 *
	 * @param string $value
	 * @return string
	 */
	private function extractTaxRateFromText($value)
	{
		$value = trim((string) $value);
		if ($value === '') {
			return '';
		}

		if (preg_match('/(-?\d+(?:[.,]\d+)?)/', $value, $matches)) {
			return $this->normalizeTaxRate($matches[1]);
		}

		return '';
	}

	/**
	 * Resolve a tax rate from known tax labels when API omits numeric value.
	 *
	 * @param string $label
	 * @return string
	 */
	private function inferTaxRateFromLabel($label)
	{
		$label = $this->normalizeTaxText($label);
		if ($label === '') {
			return '';
		}

		if (strpos($label, 'isento') !== false) {
			return '0.00';
		}
		if (strpos($label, 'reduz') !== false) {
			return '6.00';
		}
		if (strpos($label, 'intermed') !== false) {
			return '13.00';
		}
		if (strpos($label, 'normal') !== false) {
			return '23.00';
		}
		if (strpos($label, 'intracomunit') !== false) {
			return '0.00';
		}

		return '';
	}

	/**
	 * Recursively try to extract tax rate from any object/array payload.
	 *
	 * @param mixed $node
	 * @return string
	 */
	private function extractTaxRateRecursive($node)
	{
		if (is_scalar($node)) {
			return $this->extractTaxRateFromText((string) $node);
		}

		$items = array();
		if (is_object($node)) {
			$items = get_object_vars($node);
		} elseif (is_array($node)) {
			$items = $node;
		}

		if (empty($items)) {
			return '';
		}

		$priorityKeys = array('factor', 'taxa', 'valor', 'value', 'tax_value', 'percentagem', 'percent', 'iva', 'taxaiva', 'rate', 'taxrate');
		foreach ($priorityKeys as $priorityKey) {
			foreach ($items as $key => $value) {
				$keyNormalized = $this->normalizeTaxText((string) $key);
				if ($keyNormalized !== $priorityKey) {
					continue;
				}
				$rate = $this->extractTaxRateRecursive($value);
				if ($rate !== '') {
					return $rate;
				}
			}
		}

		foreach ($items as $key => $value) {
			$keyNormalized = $this->normalizeTaxText((string) $key);
			if (
				strpos($keyNormalized, 'id') !== false
				|| strpos($keyNormalized, 'cod') !== false
				|| strpos($keyNormalized, 'rowid') !== false
				|| strpos($keyNormalized, 'loja') !== false
				|| strpos($keyNormalized, 'entity') !== false
				|| strpos($keyNormalized, 'activ') !== false
				|| strpos($keyNormalized, 'ativ') !== false
				|| strpos($keyNormalized, 'active') !== false
				|| strpos($keyNormalized, 'inativo') !== false
				|| strpos($keyNormalized, 'lastupdate') !== false
				|| strpos($keyNormalized, 'date') !== false
				|| strpos($keyNormalized, 'data') !== false
				|| strpos($keyNormalized, 'error') !== false
			) {
				continue;
			}
			$rate = $this->extractTaxRateRecursive($value);
			if ($rate !== '') {
				return $rate;
			}
		}

		return '';
	}

	/**
	 * Extract tax rate from API tax object using strict-to-loose strategy.
	 *
	 * @param object $tax
	 * @return string
	 */
	private function extractTaxRateFromTaxObject($tax)
	{
		if (!is_object($tax)) {
			return '';
		}

		// 1) Preferred numeric fields
		$rateKeys = array('factor', 'taxa', 'valor', 'value', 'tax_value', 'percentagem', 'percent', 'iva', 'taxaiva', 'rate', 'taxrate');
		foreach ($rateKeys as $rateKey) {
			if (isset($tax->{$rateKey}) && $tax->{$rateKey} !== '') {
				$rate = $this->normalizeTaxRate($tax->{$rateKey});
				if ($rate !== '') {
					return $rate;
				}
			}
		}

		// 2) Recursive scan for non-id numeric values, including strings like "V 23.00%"
		$recursiveRate = $this->extractTaxRateRecursive($tax);
		if ($recursiveRate !== '') {
			return $recursiveRate;
		}

		// 3) Last resort mapping by Portuguese common tax names
		$nameKeys = array('descricao', 'description', 'nome', 'name', 'label');
		foreach ($nameKeys as $nameKey) {
			if (isset($tax->{$nameKey}) && trim((string) $tax->{$nameKey}) !== '') {
				$inferred = $this->inferTaxRateFromLabel((string) $tax->{$nameKey});
				if ($inferred !== '') {
					return $inferred;
				}
			}
		}

		return '';
	}

	/**
	 * Ensure taxes dictionary table exists for runtime synchronizations.
	 *
	 * @return bool
	 */
	private function ensureZsTaxTableExists()
	{
		$sql = "CREATE TABLE IF NOT EXISTS " . MAIN_DB_PREFIX . "dolizsynch_zstax (";
		$sql .= "rowid INTEGER AUTO_INCREMENT PRIMARY KEY,";
		$sql .= "entity INTEGER DEFAULT 1 NOT NULL,";
		$sql .= "loja_zs INTEGER DEFAULT 1 NOT NULL,";
		$sql .= "tax_id INTEGER NOT NULL,";
		$sql .= "tax_name VARCHAR(255) NOT NULL,";
		$sql .= "tax_value DECIMAL(10, 2) DEFAULT 0.00 NOT NULL,";
		$sql .= "active INTEGER DEFAULT 1 NOT NULL,";
		$sql .= "tms TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,";
		$sql .= "UNIQUE KEY unique_lojazs_taxid (loja_zs, tax_id),";
		$sql .= "synch INTEGER DEFAULT 0 NOT NULL";
		$sql .= ")";

		if (!$this->db->query($sql)) {
			dol_syslog("Error creating taxes dictionary table: " . $this->db->lasterror(), LOG_ERR);
			return false;
		}

		return true;
	}

	/**
	 * Extract taxes list from API response content.
	 *
	 * @param object|null $content
	 * @return array
	 */
	private function extractTaxesFromContent($content)
	{
		if (!is_object($content)) {
			return array();
		}

		$candidateKeys = array('tax', 'taxes', 'iva');
		$contentVars = get_object_vars($content);
		foreach ($contentVars as $key => $value) {
			if (!in_array(strtolower((string) $key), $candidateKeys, true)) {
				continue;
			}
			if (is_array($value)) {
				return $value;
			}
			if (is_object($value)) {
				return array($value);
			}
		}

		return array();
	}

	/**
	 * Fetches Taxes from ZoneSoft API and saves or updates them in the database.
	 *
	 * @return bool True on success, false on failure.
	 */
	public function storezstaxes()
	{
		global $conf;
		$store = (int) ($conf->global->ZS_API_STORE ?? 0);
		$entity = (int) $conf->entity;

		if (!$this->ensureZsTaxTableExists()) {
			return false;
		}

		$taxesResponse = json_decode(json_encode($this->zoneSoftApi->fetchzs('Taxes', 'tax', 'getInstances')));
		if (!$taxesResponse || !isset($taxesResponse->Response) || (int) $taxesResponse->Response->StatusCode !== 200) {
			$taxesResponse = json_decode(json_encode($this->zoneSoftApi->fetchzs('Taxes', 'tax', 'getActiveInstances')));
		}

		if ($taxesResponse && isset($taxesResponse->Response) && (int) $taxesResponse->Response->StatusCode === 200) {
			$content = isset($taxesResponse->Response->Content) ? $taxesResponse->Response->Content : null;
			$taxes = $this->extractTaxesFromContent($content);

			if (empty($taxes)) {
				dol_syslog("No Taxes found in the response", LOG_INFO);
				return true;
			}

			$sqlDelete = "DELETE FROM " . MAIN_DB_PREFIX . "dolizsynch_zstax WHERE loja_zs = " . $store . " AND entity = " . $entity;
			if (!$this->db->query($sqlDelete)) {
				dol_syslog("Error clearing taxes dictionary before sync: " . $this->db->lasterror(), LOG_ERR);
				return false;
			}

			foreach ($taxes as $tax) {
				if (!is_object($tax)) {
					continue;
				}

				$taxName = '';
				$taxRate = '';
				$taxId = null;

				$idKeys = array('codigo', 'id', 'tax_id', 'codtaxa', 'codiva');
				foreach ($idKeys as $idKey) {
					if (isset($tax->{$idKey}) && $tax->{$idKey} !== '') {
						$taxId = (int) $tax->{$idKey};
						break;
					}
				}

				$nameKeys = array('descricao', 'description', 'nome', 'name');
				foreach ($nameKeys as $nameKey) {
					if (isset($tax->{$nameKey}) && trim((string) $tax->{$nameKey}) !== '') {
						$taxName = trim((string) $tax->{$nameKey});
						break;
					}
				}

				$taxRate = $this->extractTaxRateFromTaxObject($tax);

				if ($taxRate === '') {
					dol_syslog("Skipping tax entry without detectable rate: " . $this->summarizeValueForLog($tax), LOG_WARNING);
					continue;
				}

				if ($taxId === null) {
					$taxId = (int) round(((float) $taxRate) * 100);
				}

				if ($taxName === '') {
					$taxName = $taxRate . '%';
				}

				$active = 1;
				if (isset($tax->inativo)) {
					$active = ((int) $tax->inativo === 1) ? 0 : 1;
				} elseif (isset($tax->inactive)) {
					$active = ((int) $tax->inactive === 1) ? 0 : 1;
				} elseif (isset($tax->active)) {
					$active = ((int) $tax->active === 1) ? 1 : 0;
				} elseif (isset($tax->activa)) {
					$active = ((int) $tax->activa === 1) ? 1 : 0;
				} elseif (isset($tax->ativo)) {
					$active = ((int) $tax->ativo === 1) ? 1 : 0;
				}

				$sql = "INSERT INTO " . MAIN_DB_PREFIX . "dolizsynch_zstax (entity, loja_zs, tax_id, tax_name, tax_value, active) VALUES ";
				$sql .= "(" . $entity . ", " . $store . ", " . ((int) $taxId) . ", '" . $this->db->escape($taxName) . "', " . ((float) $taxRate) . ", " . ((int) $active) . ")";
				$sql .= " ON DUPLICATE KEY UPDATE tax_name = '" . $this->db->escape($taxName) . "', tax_value = " . ((float) $taxRate) . ", active = " . ((int) $active) . ", entity = " . $entity;

				if (!$this->db->query($sql)) {
					dol_syslog("Error inserting/updating tax in database: " . $this->db->lasterror(), LOG_ERR);
					return false;
				}
			}

			dol_syslog("Taxes fetched and updated successfully", LOG_INFO);
			return true;
		}

		dol_syslog("Failed to fetch Taxes from ZoneSoft", LOG_ERR);
		return false;
	}

	/**
	 * Ensure generic master-data cache table exists.
	 *
	 * @return bool
	 */
	private function ensureZsMasterDataTableExists()
	{
		$sql = "CREATE TABLE IF NOT EXISTS " . MAIN_DB_PREFIX . "dolizsynch_zsmasterdata (";
		$sql .= "rowid INTEGER AUTO_INCREMENT PRIMARY KEY,";
		$sql .= "entity INTEGER DEFAULT 1 NOT NULL,";
		$sql .= "loja_zs INTEGER DEFAULT 1 NOT NULL,";
		$sql .= "interface_name VARCHAR(64) NOT NULL,";
		$sql .= "instance_key VARCHAR(190) NOT NULL,";
		$sql .= "label VARCHAR(255) DEFAULT '' NOT NULL,";
		$sql .= "active INTEGER DEFAULT 1 NOT NULL,";
		$sql .= "payload_json LONGTEXT,";
		$sql .= "tms TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,";
		$sql .= "UNIQUE KEY uniq_store_interface_instance (loja_zs, interface_name, instance_key),";
		$sql .= "KEY idx_store_interface (loja_zs, interface_name)";
		$sql .= ")";

		if (!$this->db->query($sql)) {
			$this->error = "Error creating generic master-data table: " . $this->db->lasterror();
			$this->errors[] = $this->error;
			dol_syslog($this->error, LOG_ERR);
			return false;
		}

		return true;
	}

	/**
	 * Normalize value into a list of instances.
	 *
	 * @param mixed $value
	 * @return array
	 */
	private function normalizeMasterDataInstancesList($value)
	{
		if ($value === null) {
			return array();
		}

		if (is_object($value)) {
			$value = get_object_vars($value);
		}

		if (!is_array($value)) {
			return array();
		}

		$isAssoc = array_keys($value) !== range(0, count($value) - 1);
		if ($isAssoc) {
			return array($value);
		}

		return $value;
	}

	/**
	 * Extract instances array from API response content.
	 *
	 * @param mixed $content
	 * @param array $candidateKeys
	 * @return array
	 */
	private function extractMasterDataInstancesFromContent($content, array $candidateKeys)
	{
		if (is_object($content)) {
			$content = get_object_vars($content);
		}

		if (!is_array($content)) {
			return array();
		}

		$candidateLookup = array();
		foreach ($candidateKeys as $key) {
			$candidateLookup[strtolower((string) $key)] = true;
		}

		foreach ($content as $key => $value) {
			$keyNormalized = strtolower((string) $key);
			if (!isset($candidateLookup[$keyNormalized])) {
				continue;
			}

			$instances = $this->normalizeMasterDataInstancesList($value);
			if (!empty($instances)) {
				return $instances;
			}
		}

		foreach ($content as $value) {
			$instances = $this->normalizeMasterDataInstancesList($value);
			if (!empty($instances)) {
				return $instances;
			}
		}

		return array();
	}

	/**
	 * Recursively extract first matching scalar value from an instance.
	 *
	 * @param mixed $node
	 * @param array $candidateKeys
	 * @return string
	 */
	private function extractMasterDataScalar($node, array $candidateKeys)
	{
		$candidateLookup = array();
		foreach ($candidateKeys as $key) {
			$candidateLookup[strtolower((string) $key)] = true;
		}

		$stack = array($node);
		while (!empty($stack)) {
			$current = array_pop($stack);

			if (is_object($current)) {
				$current = get_object_vars($current);
			}

			if (!is_array($current)) {
				continue;
			}

			foreach ($current as $key => $value) {
				$keyNormalized = strtolower((string) $key);

				if (isset($candidateLookup[$keyNormalized]) && !is_array($value) && !is_object($value) && $value !== '' && $value !== null) {
					return trim((string) $value);
				}

				if (is_array($value) || is_object($value)) {
					$stack[] = $value;
				}
			}
		}

		return '';
	}

	/**
	 * Resolve canonical instance key for generic master-data cache.
	 *
	 * @param mixed $instance
	 * @return string
	 */
	private function resolveMasterDataInstanceKey($instance)
	{
		$key = $this->extractMasterDataScalar(
			$instance,
			array('codigo', 'id', 'rowid', 'code', 'chave', 'key', 'numero', 'num')
		);

		if ($key === '') {
			$payloadJson = json_encode($instance, JSON_UNESCAPED_UNICODE);
			if ($payloadJson === false || $payloadJson === null) {
				$payloadJson = serialize($instance);
			}
			$key = 'hash_' . md5((string) $payloadJson);
		}

		return substr($key, 0, 190);
	}

	/**
	 * Resolve display label for generic master-data instance.
	 *
	 * @param mixed $instance
	 * @return string
	 */
	private function resolveMasterDataInstanceLabel($instance)
	{
		$label = $this->extractMasterDataScalar(
			$instance,
			array('descricao', 'description', 'nome', 'name', 'designacao', 'designation', 'label')
		);

		if ($label === '') {
			$label = $this->resolveMasterDataInstanceKey($instance);
		}

		return substr($label, 0, 255);
	}

	/**
	 * Resolve active status from generic master-data instance.
	 *
	 * @param mixed $instance
	 * @return int
	 */
	private function resolveMasterDataInstanceActive($instance)
	{
		$inactive = $this->extractMasterDataScalar($instance, array('inativo', 'inactive'));
		if ($inactive !== '') {
			return ((int) $inactive === 1) ? 0 : 1;
		}

		$active = $this->extractMasterDataScalar($instance, array('ativo', 'active', 'activa'));
		if ($active !== '') {
			return ((int) $active === 1) ? 1 : 0;
		}

		return 1;
	}

	/**
	 * Synchronize one generic master-data interface into cache table.
	 *
	 * @param string $interfaceName API interface name.
	 * @param string $rootKey Root key used by listing payload.
	 * @param array $contentKeys Candidate response content keys.
	 * @return bool
	 */
	private function synchronizeGenericMasterDataInterface($interfaceName, $rootKey, array $contentKeys = array())
	{
		global $conf;

		$store = (int) ($conf->global->ZS_API_STORE ?? 0);
		$entity = (int) $conf->entity;
		$interfaceSlug = strtolower(trim((string) $interfaceName));
		$rootKeySlug = strtolower(trim((string) $rootKey));
		$candidateContentKeys = !empty($contentKeys) ? $contentKeys : array($rootKeySlug);
		if (!$this->validateEntityStoreScope($store, $entity, __METHOD__ . ':' . $interfaceSlug)) {
			$this->error = 'The configured ZoneSoft store does not belong to the active entity.';
			$this->errors[] = $this->error;
			return false;
		}

		if (!$this->ensureZsMasterDataTableExists()) {
			return false;
		}

		$payload = array(
			$rootKeySlug => (object) array()
		);

		try {
			$this->zoneSoftApi->setInterface($interfaceSlug);
			$rawResponse = $this->zoneSoftApi->execute('getInstances', $payload);
		} catch (Exception $e) {
			$this->error = "Failed to fetch {$interfaceName} from ZoneSoft - Exception: " . $e->getMessage();
			$this->errors[] = $this->error;
			dol_syslog($this->error, LOG_ERR);
			return false;
		}

		// ZSAPIHttpClient returns [] for HTTP 204 (no content).
		// Normalize it into a response object so this flow can handle it as success.
		if (is_array($rawResponse) && empty($rawResponse)) {
			$response = (object) array(
				'Response' => (object) array(
					'StatusCode' => 204
				)
			);
		} else {
			$response = json_decode(json_encode($rawResponse));
		}

		if (!$response || !isset($response->Response)) {
			$this->error = "Failed to fetch {$interfaceName} from ZoneSoft (no response)";
			$this->errors[] = $this->error;
			dol_syslog($this->error, LOG_ERR);
			return false;
		}

		$statusCode = (int) ($response->Response->StatusCode ?? 0);
		if ($statusCode === 204) {
			$sqlDeleteEmpty = "DELETE FROM " . MAIN_DB_PREFIX . "dolizsynch_zsmasterdata ";
			$sqlDeleteEmpty .= "WHERE loja_zs = " . $store . " AND entity = " . $entity . " AND interface_name = '" . $this->db->escape($interfaceSlug) . "'";
			$this->db->query($sqlDeleteEmpty);
			dol_syslog("No instances returned for {$interfaceName}; cache cleared for store {$store}", LOG_INFO);
			return true;
		}

		if ($statusCode !== 200) {
			$errorSuffix = '';
			$statusMessage = trim((string) ($response->Response->StatusMessage ?? ''));
			$errorMessage = trim((string) ($response->Response->ErrorMessage ?? ''));
			$effectiveError = $errorMessage !== '' ? $errorMessage : $statusMessage;
			if ($effectiveError !== '') {
				$errorSuffix = " - " . $effectiveError;
			}

			$this->error = "Failed to fetch {$interfaceName} from ZoneSoft - StatusCode: " . $statusCode . $errorSuffix;
			$this->errors[] = $this->error;
			dol_syslog($this->error, LOG_ERR);
			return false;
		}

		$content = isset($response->Response->Content) ? $response->Response->Content : null;
		$instances = $this->extractMasterDataInstancesFromContent($content, $candidateContentKeys);
		if (empty($instances)) {
			dol_syslog("No parseable instances found for {$interfaceName} content keys", LOG_WARNING);
			return true;
		}

		$sqlDelete = "DELETE FROM " . MAIN_DB_PREFIX . "dolizsynch_zsmasterdata ";
		$sqlDelete .= "WHERE loja_zs = " . $store . " AND entity = " . $entity . " AND interface_name = '" . $this->db->escape($interfaceSlug) . "'";
		if (!$this->db->query($sqlDelete)) {
			$this->error = "Error clearing cached {$interfaceName} instances: " . $this->db->lasterror();
			$this->errors[] = $this->error;
			dol_syslog($this->error, LOG_ERR);
			return false;
		}

		$stored = 0;
		foreach ($instances as $instance) {
			$instanceKey = $this->resolveMasterDataInstanceKey($instance);
			$label = $this->resolveMasterDataInstanceLabel($instance);
			$active = $this->resolveMasterDataInstanceActive($instance);
			$payloadJson = json_encode($instance, JSON_UNESCAPED_UNICODE);
			if ($payloadJson === false || $payloadJson === null) {
				$payloadJson = '{}';
			}

			$sqlInsert = "INSERT INTO " . MAIN_DB_PREFIX . "dolizsynch_zsmasterdata ";
			$sqlInsert .= "(entity, loja_zs, interface_name, instance_key, label, active, payload_json) VALUES ";
			$sqlInsert .= "(" . $entity . ", " . $store . ", '" . $this->db->escape($interfaceSlug) . "', '" . $this->db->escape($instanceKey) . "', ";
			$sqlInsert .= "'" . $this->db->escape($label) . "', " . ((int) $active) . ", '" . $this->db->escape($payloadJson) . "')";
			$sqlInsert .= " ON DUPLICATE KEY UPDATE label = '" . $this->db->escape($label) . "', active = " . ((int) $active) . ", payload_json = '" . $this->db->escape($payloadJson) . "', entity = " . $entity;

			if (!$this->db->query($sqlInsert)) {
				$this->error = "Error inserting {$interfaceName} instance into cache: " . $this->db->lasterror();
				$this->errors[] = $this->error;
				dol_syslog($this->error, LOG_ERR);
				return false;
			}
			$stored++;
		}

		dol_syslog("Synchronized {$stored} {$interfaceName} instances into generic master-data cache", LOG_INFO);
		return true;
	}

	/**
	 * Sync options interface into generic master-data cache.
	 *
	 * @return bool
	 */
	public function storezsoptions()
	{
		return $this->synchronizeGenericMasterDataInterface(
			'Options',
			'option',
			array('option', 'options')
		);
	}

	/**
	 * Sync characteristics interface into generic master-data cache.
	 *
	 * @return bool
	 */
	public function storezscharacteristics()
	{
		return $this->synchronizeGenericMasterDataInterface(
			'Characteristics',
			'characteristic',
			array('characteristic', 'characteristics')
		);
	}

	/**
	 * Sync property definitions into the entity/store master-data cache.
	 *
	 * @return bool
	 */
	public function storezsproperties()
	{
		return $this->synchronizeGenericMasterDataInterface(
			'Properties',
			'property',
			array('property', 'properties')
		);
	}

	/**
	 * Sync subfamilies interface into generic master-data cache.
	 *
	 * @return bool
	 */
	public function storezssubfamilies()
	{
		return $this->synchronizeGenericMasterDataInterface(
			'Subfamilies',
			'subfamily',
			array('subfamily', 'subfamilies')
		);
	}

	/**
	 * Synchronize master data used by runtime flows.
	 *
	 * Excludes clients and suppliers by request.
	 *
	 * @return bool
	 */
	public function synchronizeMasterDataCoreWithoutClientsSuppliers()
	{
		$this->error = '';
		$this->errors = array();

		$allOk = true;
		$steps = array(
			'paymenttypes' => 'storezspaymenttypes',
			'taxes' => 'storezstaxes',
			'families' => 'storezsfamilies',
			'subfamilies' => 'storezssubfamilies',
			'options' => 'storezsoptions',
			'characteristics' => 'storezscharacteristics'
		);

		foreach ($steps as $stepName => $methodName) {
			try {
				$result = $this->{$methodName}();
				if (!$result) {
					$allOk = false;
					dol_syslog("Master-data sync step failed: {$stepName}", LOG_WARNING);
				}
			} catch (Exception $e) {
				$allOk = false;
				$message = "Exception on master-data sync step {$stepName}: " . $e->getMessage();
				$this->errors[] = $message;
				dol_syslog($message, LOG_ERR);
			}
		}

		if (!$allOk && empty($this->error)) {
			$this->error = 'One or more master-data sync steps failed';
		}

		return $allOk;
	}

	function getMappingRow($mapping, $rowId, $type)
	{
		global $db, $conf, $langs;
		$store = (int) $conf->global->ZS_API_STORE;
		$storeProducts = (int) $conf->global->ZS_API_STORE;
		$currentEntity = (int) $conf->entity;

		require_once DOL_DOCUMENT_ROOT . '/categories/class/categorie.class.php';
		require_once DOL_DOCUMENT_ROOT . '/core/class/html.formcategory.class.php';
		$form = new Form($db);

		// Variables for SQL and dropdown options
		$sqlZapi = "";
		$sqlDolibarr = "";
		$zapiOptions = array();
		$dolibarrOptions = array();
		$nameZapiDropdown = "";
		$nameDolibarrDropdown = "";
		$dbRowId = isset($mapping->rowid) ? (int) $mapping->rowid : 0;

		// SQL querys
		switch ($type) {
			case 'family':
				$sqlZapi = "SELECT family_id, family_name, subfamily_id, subfamily_name FROM " . MAIN_DB_PREFIX . "dolizsynch_zsfamily WHERE loja_zs = " . $storeProducts . " AND entity IN (0, " . $currentEntity . ") ORDER BY family_name ASC";
				$sqlDolibarr = "SELECT rowid, label FROM " . MAIN_DB_PREFIX . "categorie WHERE type = 0 AND entity IN (0, " . getEntity('categorie') . ") ORDER BY label ASC";
				$nameZapiDropdown = "zsFamily";
				$nameDolibarrDropdown = "name_doli_dropdown_family";
				break;
			case 'paymentType':
				$sqlZapi = "SELECT paymenttype_id, paymenttype_name FROM " . MAIN_DB_PREFIX . "dolizsynch_zspaymenttype WHERE loja_zs = " . $store . " AND entity IN (0, " . $currentEntity . ") ORDER BY paymenttype_name ASC";
				$sqlDolibarr = "SELECT id, code, libelle FROM " . MAIN_DB_PREFIX . "c_paiement WHERE entity IN (0, " . getEntity('c_paiement') . ") ORDER BY libelle";
				$nameZapiDropdown = "zapiPayment";
				$nameDolibarrDropdown = "name_doli_dropdown_paymenttype";
				break;
			case 'bankAccount':
				$sqlZapi = "SELECT paymenttype_id, paymenttype_name FROM " . MAIN_DB_PREFIX . "dolizsynch_zspaymenttype WHERE loja_zs = " . $store . " AND entity IN (0, " . $currentEntity . ") ORDER BY paymenttype_name ASC";
				$sqlDolibarr = "SELECT rowid, ref, label FROM " . MAIN_DB_PREFIX . "bank_account WHERE entity IN (0, " . getEntity('bank_account') . ") ORDER BY label ASC";
				$nameZapiDropdown = "zapiBankAccount";
				$nameDolibarrDropdown = "name_doli_dropdown_bankaccount";
				break;
			case 'unit':
				$sqlZapi = "SELECT unit_id, unit_name FROM " . MAIN_DB_PREFIX . "dolizsynch_zsunit WHERE loja_zs = " . $store . " AND entity IN (0, " . $currentEntity . ") ORDER BY unit_name ASC";
				$sqlDolibarr = "SELECT rowid, label FROM " . MAIN_DB_PREFIX . "c_units WHERE active = 1 ORDER BY label ASC";
				$nameZapiDropdown = "zapiUnit";
				$nameDolibarrDropdown = "name_doli_dropdown_unit";
				break;

			default:
				// Handle other types or error
				dol_syslog("Invalid mapping type: " . $type, LOG_ERR);
				return 'Invalid mapping type';
		}

		// Fetch Zapi options
		$resqlZapi = $db->query($sqlZapi);

		// Fetch Dolibarr options
		$resqlDolibarr = $db->query($sqlDolibarr);

		// Test delete when ready
		if ($resqlZapi) {
			while ($obj = $db->fetch_object($resqlZapi)) {
				$zapiOptions[] = $obj;
				// Log each fetched object

			}
		} else {
			// Log SQL error
			dol_syslog("SQL Error on fetching Zapi Options: " . $db->lasterror(), LOG_ERR);
		}

		// Test delete when ready
		if ($resqlDolibarr) {
			while ($obj = $db->fetch_object($resqlDolibarr)) {
				$dolibarrOptions[] = $obj;
				// Log each fetched object
			}
		} else {
			// Log SQL error
			dol_syslog("SQL Error on fetching Dolibarr Options: " . $db->lasterror(), LOG_ERR);
		}

		// Zapi dropdown
		switch ($type) {
			case 'family':
				$html = '<div id="familymappingremove_' . $rowId . '" class="mappingRow dz-map-row" data-rowid="' . $dbRowId . '" style="margin-bottom: 10px;">';
				$html .= '<table class="noborder" style="width: auto;">';
				$html .= '<tr>';

				$html .= '<td style="padding-right: 10px; font-size: 12px;">';
				$html .= '<select name="' . $nameZapiDropdown . '[' . $rowId . ']" style="width: 300px;">';
				$html .= '<option value="">' . $langs->trans("SelectZapiOptionFamily") . '</option>';
				foreach ($zapiOptions as $option) {
					// Ensure that both family_id and subfamily_id are set before using them
					if (isset($option->family_id) && isset($option->subfamily_id)) {
						$selected = ($option->family_id == $mapping->family_id && $option->subfamily_id == $mapping->subfamily_id) ? ' selected' : '';
						$html .= '<option value="' . $option->family_id . '_' . $option->subfamily_id . '"' . $selected . '>';
						if ($option->subfamily_name == '-' || $option->subfamily_name == '') {
							$html .= htmlspecialchars($option->family_name);
						} else {
							$html .= htmlspecialchars($option->family_name . ' >> ' . $option->subfamily_name);
						}
						$html .= '</option>';
					}
				}
				$html .= '</select>';
				$html .= '</td>';
				break;
			case 'paymentType':
				$html = '<div id="mappingPaymentRemove_' . $rowId . '" class="mappingRow dz-map-row" data-rowid="' . $dbRowId . '" style="padding-right: 10px;">';
				$html .= '<table class="noborder" style="width: auto;">';
				$html .= '<tr>';

				$html .= '<td style="padding-right: 10px; font-size: 12px;">';
				$html .= '<select name="' . $nameZapiDropdown . '[' . $rowId . ']" style="width: 300px;">';
				$html .= '<option value="">' . $langs->trans("SelectZapiOptionPaymentType") . '</option>';
				foreach ($zapiOptions as $option) {
					// Check if $paymenttype_id exists
					if (isset($option->paymenttype_id)) {
						$selected = (isset($mapping->paymenttype_id) && $option->paymenttype_id == $mapping->paymenttype_id) ? ' selected' : '';
						$html .= '<option value="' . $option->paymenttype_id . '"' . $selected . '>';
						$html .= htmlspecialchars($option->paymenttype_name);
						$html .= '</option>';
					}
				}
				$html .= '</select>';
				$html .= '</td>';
				break;

			case 'bankAccount':
				$html = '<div id="mappingBankAccountRemove_' . $rowId . '" class="mappingRow dz-map-row" data-rowid="' . $dbRowId . '" style="padding-right: 10px;">';
				$html .= '<table class="noborder" style="width: auto;">';
				$html .= '<tr>';

				$html .= '<td style="padding-right: 10px; font-size: 12px;">';
				$html .= '<select name="' . $nameZapiDropdown . '[' . $rowId . ']" style="width: 300px;">';
				$html .= '<option value="">' . $langs->trans("SelectZapiOptionPaymentType") . '</option>';
				foreach ($zapiOptions as $option) {
					// Check if $paymenttype_id exists
					if (isset($option->paymenttype_id)) {
						$selected = (isset($mapping->paymenttype_id) && $option->paymenttype_id == $mapping->paymenttype_id) ? ' selected' : '';
						$html .= '<option value="' . $option->paymenttype_id . '"' . $selected . '>';
						$html .= htmlspecialchars($option->paymenttype_name);
						$html .= '</option>';
					}
				}
				$html .= '</select>';
				$html .= '</td>';
				break;
			case 'unit':
				$html = '<div id="unitmappingremove_' . $rowId . '" class="mappingRow dz-map-row" data-rowid="' . $dbRowId . '" style="padding-right: 10px;">';
				$html .= '<table class="noborder" style="width: auto;">';
				$html .= '<tr>';

				$html .= '<td style="padding-right: 10px; font-size: 12px;">';
				$html .= '<select name="' . $nameZapiDropdown . '[' . $rowId . ']" style="width: 300px;">';
				$html .= '<option value="">' . $langs->trans("SelectZapiOptionUnit") . '</option>';
				foreach ($zapiOptions as $option) {
					// Check if $unit_id exists
					if (isset($option->unit_id)) {
						$selected = (isset($mapping->unit_id) && $option->unit_id == $mapping->unit_id) ? ' selected' : '';
						$html .= '<option value="' . $option->unit_id . '"' . $selected . '>';
						$html .= htmlspecialchars($option->unit_name);
						$html .= '</option>';
					}
				}
				$html .= '</select>';
				$html .= '</td>';
				break;

			default:
				// Handle other types or error
				dol_syslog("Invalid mapping type: " . $type, LOG_ERR);
				return 'Invalid mapping type';
		}

		// Dolibarr dropdown
		switch ($type) {
			case 'family':
				$html .= '<td style="padding-right: 10px; font-size: 13.8px;">';
				$tmptitle = $langs->trans('TextoSelectorCategorias');
				// Verify if $mapping->fk_categorie is set before using it
				$selectedCategorie = isset($mapping->fk_categorie) ? $mapping->fk_categorie : '';
				$cate_arbo = $form->select_all_categories(Categorie::TYPE_PRODUCT, null, 'parent', null, null, 1);
				$html .= $form->selectarray($nameDolibarrDropdown . '[' . $rowId . ']', $cate_arbo, $selectedCategorie, $tmptitle, 0, 0, '', 0, 0, 0, 0, 'maxwidth300', 0);
				$html .= '</td>';
				break;
			case 'paymentType':
				$html .= '<td style="padding-right: 10px; font-size: 12px;">';
				$html .= '<select name="' . $nameDolibarrDropdown . '[' . $rowId . ']" style="width: 300px;">';
				$html .= '<option value="">' . $langs->trans("SelectDolibarrOptionPaymentType") . '</option>';
				foreach ($dolibarrOptions as $option) {
					// Verify if $mapping->fk_paiement is set before comparing
					$selected = isset($mapping->fk_paiement) && ($option->id == $mapping->fk_paiement) ? ' selected' : '';
					$html .= '<option value="' . $option->id . '"' . $selected . '>';
					$html .= htmlspecialchars($option->libelle);
					$html .= '</option>';
				}
				$html .= '</select>';
				$html .= '</td>';
				break;
			case 'bankAccount':
				$html .= '<td style="padding-right: 10px; font-size: 12px;">';
				$html .= '<select name="' . $nameDolibarrDropdown . '[' . $rowId . ']" style="width: 300px;">';
				$html .= '<option value="">' . $langs->trans("SelectDolibarrOptionBankAccount") . '</option>';
				foreach ($dolibarrOptions as $option) {
					// Verify if $mapping->fk_account is set before comparing
					$selected = isset($mapping->fk_account) && ($option->rowid == $mapping->fk_account) ? ' selected' : '';
					$html .= '<option value="' . $option->rowid . '"' . $selected . '>';
					$html .= htmlspecialchars($option->label); // Adjust the property if different in your object
					$html .= '</option>';
				}
				$html .= '</select>';
				$html .= '</td>';
				break;
			case 'unit':
				$html .= '<td style="padding-right: 10px; font-size: 12px;">';
				$html .= '<select name="' . $nameDolibarrDropdown . '[' . $rowId . ']" style="width: 300px;">';
				$html .= '<option value="">' . $langs->trans("SelectDolibarrOptionUnit") . '</option>';
				foreach ($dolibarrOptions as $option) {
					// Verify if $mapping->fk_unit is set before comparing
					$selected = isset($mapping->fk_unit) && ($option->rowid == $mapping->fk_unit) ? ' selected' : '';
					$html .= '<option value="' . $option->rowid . '"' . $selected . '>';
					$html .= htmlspecialchars($option->label); // Adjust the property if different in your object
					$html .= '</option>';
				}
				$html .= '</select>';
				$html .= '</td>';
				break;
			default:
				// Handle other types or error
				dol_syslog("Invalid mapping type: " . $type, LOG_ERR);
				return 'Invalid mapping type';
		}

		// Add and remove buttons
		$html .= '<td class="nowrap dz-map-row-actions">';
		$html .= '<a class="button dz-map-row-add" onclick="addMappingRow(\'' . $type . '\')">+</a>';
		if ($rowId != '0') {
			$html .= '<a class="button dz-map-row-remove" onclick="removeMappingRow(' . $rowId . ', \'' . $type . '\')">-</a>';
		} else {
			$html .= '<span class="button dz-map-row-remove-placeholder" aria-hidden="true">-</span>';
		}
		$html .= '</td>';

		$html .= '</tr>';
		$html .= '</table>';
		$html .= '</div>'; // End of row

		return $html;
	}

	function fetchExistingMappings($sql)
	{
		global $db;

		$resql = $db->query($sql);
		$mappings = array();
		if ($resql) {
			while ($obj = $db->fetch_object($resql)) {
				$mappings[] = $obj;
			}
		} else {
			dol_syslog($db->lasterror(), LOG_ERR);
		}
		return $mappings;
	}

	/**
	 * Wrapper method for synchronizing families
	 * Calls storezsfamilies()
	 *
	 * @return bool True on success, false on failure
	 */
	public function synchronizeFamilies()
	{
		return $this->storezsfamilies();
	}

	/**
	 * Wrapper method for synchronizing categories
	 * Calls storezscategories()
	 *
	 * @return bool True on success, false on failure
	 */
	public function synchronizeCategories()
	{
		return $this->storezscategories();
	}

	/**
	 * Wrapper method for synchronizing payment types
	 * Calls storezspaymenttypes()
	 *
	 * @return bool True on success, false on failure
	 */
	public function synchronizePaymentTypes()
	{
		return $this->storezspaymenttypes();
	}

	/**
	 * Wrapper method for synchronizing units
	 * Calls storezsunits()
	 *
	 * @return bool True on success, false on failure
	 */
	public function synchronizeUnits()
	{
		return $this->storezsunits();
	}

	/**
	 * Wrapper method for synchronizing production centers
	 * Calls storezsproductioncentres()
	 *
	 * @return bool True on success, false on failure
	 */
	public function synchronizeProductionCenters()
	{
		return $this->storezsproductioncentres();
	}

	/**
	 * Wrapper method for synchronizing groups
	 * Calls storezsgroups()
	 *
	 * @return bool True on success, false on failure
	 */
	public function synchronizeGroups()
	{
		return $this->storezsgroups();
	}

	/**
	 * Wrapper method for synchronizing taxes
	 * Calls storezstaxes()
	 *
	 * @return bool True on success, false on failure
	 */
	public function synchronizeTaxes()
	{
		return $this->storezstaxes();
	}

	/**
	 * Wrapper method for synchronizing generic options cache.
	 *
	 * @return bool
	 */
	public function synchronizeOptions()
	{
		return $this->storezsoptions();
	}

	/**
	 * Wrapper method for synchronizing generic characteristics cache.
	 *
	 * @return bool
	 */
	public function synchronizeCharacteristics()
	{
		return $this->storezscharacteristics();
	}

	/**
	 * Wrapper method for synchronizing the property dictionary cache.
	 *
	 * @return bool
	 */
	public function synchronizeProperties()
	{
		return $this->storezsproperties();
	}

	/**
	 * Wrapper method for synchronizing master-data without clients/suppliers.
	 *
	 * @return bool
	 */
	public function synchronizeMasterDataCore()
	{
		return $this->synchronizeMasterDataCoreWithoutClientsSuppliers();
	}

	/**
	 * Synchronize ZoneSoft products into local shadow table with pagination.
	 * Runs in bounded batches to avoid timeouts on very large catalogs.
	 *
	 * @return bool True on success, false on failure
	 */
	public function synchronizeProducts()
	{
		global $conf;

		$offset = !empty($conf->global->ZS_GET_PRODUCTS_OFFSET) ? (int) $conf->global->ZS_GET_PRODUCTS_OFFSET : 1;
		if ($offset <= 0) {
			$offset = 1;
		}

		$maxBatchesPerRun = !empty($conf->global->ZS_SYNC_PRODUCTS_MAX_BATCHES_PER_RUN) ? (int) $conf->global->ZS_SYNC_PRODUCTS_MAX_BATCHES_PER_RUN : 10;
		if ($maxBatchesPerRun <= 0) {
			$maxBatchesPerRun = 10;
		}

		$productSync = new ZSProductSynch($this->db);
		$result = $productSync->fetchAndInsertZsProductsLocalTableChunk($offset, $maxBatchesPerRun);

		$nextOffset = !empty($result['next_offset']) ? (int) $result['next_offset'] : 1;
		if ($nextOffset <= 0) {
			$nextOffset = 1;
		}
		dolibarr_set_const($this->db, 'ZS_GET_PRODUCTS_OFFSET', $nextOffset);

		if (empty($result['ok'])) {
			$message = "Products sync failed at offset {$offset}.";
			if (!empty($result['error'])) {
				$message .= ' ' . (string) $result['error'];
			}
			if (!empty($result['processed_products']) || !empty($result['failed_products'])) {
				$message .= " Processed: " . (int) $result['processed_products'] . ", failed: " . (int) $result['failed_products'] . ".";
			}
			setEventMessages($message, null, 'errors', 1);
			return false;
		}

		$processedProducts = (int) ($result['processed_products'] ?? 0);
		$failedProducts = (int) ($result['failed_products'] ?? 0);
		$processedBatches = (int) ($result['processed_batches'] ?? 0);

		if (!empty($result['completed'])) {
			$message = "Products sync completed. Batches: {$processedBatches}, processed: {$processedProducts}";
			if ($failedProducts > 0) {
				$message .= ", failed: {$failedProducts}";
			}
			$message .= ".";
			setEventMessages($message, null, $failedProducts > 0 ? 'warnings' : 'mesgs', 1);
			return true;
		}

		$message = "Products sync processed {$processedProducts} products in {$processedBatches} batch(es).";
		$message .= " Large catalog detected: run sync again to continue from offset {$nextOffset}.";
		if ($failedProducts > 0) {
			$message .= " Failed products in this run: {$failedProducts}.";
			setEventMessages($message, null, 'warnings', 1);
		} else {
			setEventMessages($message, null, 'mesgs', 1);
		}

		return true;
	}
}

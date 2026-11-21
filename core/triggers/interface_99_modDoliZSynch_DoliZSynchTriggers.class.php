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
 * \file    core/triggers/interface_99_modDoliZSynch_DoliZSynchTriggers.class.php
 * \ingroup dolizsynch
 * \brief   DoliZSynch trigger handler.
 */

require_once DOL_DOCUMENT_ROOT . '/core/triggers/dolibarrtriggers.class.php';
require_once DOL_DOCUMENT_ROOT . '/custom/kreaflow/class/zsprodsynch.class.php';
require_once DOL_DOCUMENT_ROOT . '/custom/kreaflow/class/zsstoreinvoiceconverter.class.php';
require_once DOL_DOCUMENT_ROOT . '/custom/kreaflow/class/zspropertystockservice.class.php';


/**
 *  Class of triggers for DoliZSynch module
 */
class InterfaceDoliZSynchTriggers extends DolibarrTriggers
{
	/**
	 * Constructor
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;

		$this->name = preg_replace('/^Interface/i', '', get_class($this));
		$this->family = "demo";
		$this->description = "KreaFlow triggers.";
		// 'development', 'experimental', 'dolibarr' or version
		$this->version = 'development';
		$this->picto = 'dolizsynch@kreaflow';
	}

	/**
	 * Trigger name
	 *
	 * @return string Name of trigger file
	 */
	public function getName()
	{
		return $this->name;
	}

	/**
	 * Trigger description
	 *
	 * @return string Description of trigger file
	 */
	public function getDesc()
	{
		return $this->description;
	}


	/**
	 * Function called when a Dolibarrr business event is done.
	 * All functions "runTrigger" are triggered if file
	 * is inside directory core/triggers
	 *
	 * @param string 		$action 	Event action code
	 * @param CommonObject 	$object 	Object
	 * @param User 			$user 		Object user
	 * @param Translate 	$langs 		Object langs
	 * @param Conf 			$conf 		Object conf
	 * @return int              		<0 if KO, 0 if no triggered ran, >0 if OK
	 */
	public function runTrigger($action, $object, User $user, Translate $langs, Conf $conf)
	{

		if (!isModEnabled('dolizsynch')) {
			return 0; // If module is not enabled, we do nothing
		}

		if ($action === 'MRP_MO_PRODUCED') {
			$moId = !empty($object->id) ? (int) $object->id : (!empty($object->rowid) ? (int) $object->rowid : 0);
			$propertyStockService = new ZSPropertyStockService($this->db);
			$result = $propertyStockService->reconcileProducedMo($moId, $user, (int) $conf->entity);
			if ($result < 0) {
				$this->errors[] = $propertyStockService->error;
				dol_syslog(__METHOD__ . ': ' . $propertyStockService->error, LOG_ERR);
				return -1;
			}
			return 0;
		}

		if ($action === 'STOCK_MOVEMENT') {
			$movementId = !empty($object->id) ? (int) $object->id : (!empty($object->rowid) ? (int) $object->rowid : 0);
			$propertyStockService = new ZSPropertyStockService($this->db);
			$result = $propertyStockService->reconcileProducedMovement($movementId, $user, (int) $conf->entity);
			if ($result < 0) {
				$this->errors[] = $propertyStockService->error;
				dol_syslog(__METHOD__ . ': ' . $propertyStockService->error, LOG_ERR);
				return -1;
			}
			return 0;
		}

		if (in_array($action, array('BILL_CANCEL', 'BILL_DELETE'), true)) {
			return $this->synchronizeInterEntitySourceInvoiceCancellation($object, $action);
		}

		// Disable automatic ZSBMS synchronization on product create
		if ($action === 'PRODUCT_CREATE') {
			return 0;
		}

		// Check if ZS_API_STORE is configured (required for ZoneSoft API calls only)
		if (empty($conf->global->ZS_API_STORE) && $action !== 'PRODUCT_DELETE') {
			dol_syslog("KreaFlow: ZS_API_STORE configuration is not set. Please configure the Zone Soft store number in module setup.", LOG_WARNING);
			setEventMessages("KreaFlow: ZS_API_STORE configuration is missing. Please configure the Zone Soft store number in module setup.", null, 'warnings');
			return 0;
		}

		$loja_zs = !empty($conf->global->ZS_API_STORE) ? $conf->global->ZS_API_STORE : null;

		// Check for the bypass flag for the PRODUCT_MODIFY action
		if ($action == 'PRODUCT_MODIFY' && !empty($conf->global->bypass_product_modify_trigger)) {
			return 0; // Bypass the trigger logic
		}

		// Or you can execute some code here
		switch ($action) {

				//case 'PRODUCT_CREATE':
				//	$productSync = new ZSProductSynch($this->db);
				//	$createSuccess = $productSync->createZoneSoftProduct($object, $loja_zs);
				//	if ($createSuccess) {
				//		$productSync->updateZsbmsProduct($object);
				//		return true; // Successfully updated
				//	} else {
				//		dol_syslog("ZSProductSync::createZoneSoftProduct: Failed to update product {$object->ref} in llx_dolizsynch_zsproduct table", LOG_ERR);
				//		return false; // Update failed
				//	}
				//
				//	$action = 'confirm_create_zs_product';
				//	break;

			case 'PRODUCT_CREATE':
				$productSync = new ZSProductSynch($this->db);

				// For new products, check if they should be created in ZSBMS
				$store = $conf->global->ZS_API_STORE;

				// Check if product ref is an integer before proceeding
				if (!is_numeric($object->ref) || !ctype_digit($object->ref)) {
					dol_syslog("ZSProductTrigger: Product {$object->ref} has non-integer reference, skipping ZSBMS creation", LOG_INFO);
					return 0;
				}

				// Check if product exists in sync table for ANY store first
				$sql = "SELECT loja_zs, codigo FROM " . MAIN_DB_PREFIX . "dolizsynch_zsproduct WHERE fk_product = " . (int)$object->id;
				$resql = $this->db->query($sql);


				// Check if sync is disabled via extrafield
				$synchEnabled = isset($object->array_options['options_zs_synch']) ? (int)$object->array_options['options_zs_synch'] : 0;
				if ($synchEnabled === 0) {
					dol_syslog("ZSProductTrigger: Product {$object->ref} has zs_synch = 0, skipping ZSBMS prompt/sync", LOG_INFO);
				} elseif ($resql && $this->db->num_rows($resql) == 0) {
					// Product doesn't exist in sync table at all, offer to create in ZSBMS
					dol_syslog("ZSProductTrigger: Product {$object->ref} not found in sync table, offering ZSBMS creation", LOG_INFO);
					$this->promptForZsbmsCreation($object, $store);
				} else {
					// Product exists in sync table, check if it's for the current store
					$existingStores = [];
					while ($obj = $this->db->fetch_object($resql)) {
						$existingStores[] = $obj->loja_zs;
					}

					dol_syslog("ZSProductTrigger: Product {$object->ref} exists in sync table for stores: " . implode(',', $existingStores) . ". Current store: $store", LOG_INFO);

					if (in_array($store, $existingStores)) {
						// Product exists for current store, just update
						$productSync->updateZsbmsProduct($object);
					} else {
						// Product exists for other stores but not current store, offer to create
						dol_syslog("ZSProductTrigger: Product {$object->ref} exists for other stores but not current store $store, offering ZSBMS creation", LOG_INFO);
						$this->promptForZsbmsCreation($object, $store);
					}
				}

				$action = 'confirm_create_zs_product';
				break;

			case 'PRODUCT_PRICE_MODIFY':
				if (!$this->isAllowedProductPriceModifyAutosyncContext()) {
					$productId = !empty($object->id) ? (int) $object->id : 0;
					$productRef = !empty($object->ref) ? (string) $object->ref : '';
					$scriptPath = $this->resolveRequestScriptPath();
					dol_syslog(
						"KreaFlow: PRODUCT_PRICE_MODIFY autosync skipped (context not allowed) for product id={$productId} ref={$productRef} script={$scriptPath}.",
						LOG_INFO
					);
					break;
				}

				$priceLevel = !empty($object->level) ? (int) $object->level : 1;
				if ($priceLevel <= 0) {
					$priceLevel = 1;
				}
				if (!isset($object->array_options['options_zs_synch']) && method_exists($object, 'fetch_optionals')) {
					$optionalResult = $object->fetch_optionals();
					if ($optionalResult < 0) {
						dol_syslog(
							"KreaFlow: PRODUCT_PRICE_MODIFY autosync skipped because product {$object->ref} extrafields could not be loaded.",
							LOG_WARNING
						);
						break;
					}
				}
				$productSync = new ZSProductSynch($this->db);
				$productSync->updateZsbmsProduct($object, false, array($priceLevel));
				$action = 'confirm_update_zs_product';
				break;

			case 'PRODUCT_MODIFY':
				if (!$this->isAllowedProductModifyAutosyncContext()) {
					$productId = !empty($object->id) ? (int) $object->id : 0;
					$productRef = !empty($object->ref) ? (string) $object->ref : '';
					$scriptPath = $this->resolveRequestScriptPath();
					$requestAction = GETPOST('action', 'alpha');
					dol_syslog(
						"KreaFlow: PRODUCT_MODIFY autosync skipped (context not allowed) for product id={$productId} ref={$productRef} script={$scriptPath} action={$requestAction}.",
						LOG_INFO
					);
					break;
				}

				$productSync = new ZSProductSynch($this->db);
				$productSync->updateZsbmsProduct($object);
				$action = 'confirm_update_zs_product';
				break;

			case 'PRODUCT_DELETE':
				$productId = !empty($object->id) ? (int) $object->id : 0;
				if ($productId > 0) {
					$propertyStockService = new ZSPropertyStockService($this->db);
					if (!$propertyStockService->deleteRulesForProduct($productId)) {
						$this->errors[] = $propertyStockService->error;
						return -1;
					}
					$fallbackCodigo = null;
					if (!empty($object->ref) && ctype_digit((string) $object->ref)) {
						$fallbackCodigo = (int) $object->ref;
					}

					dol_syslog("KreaFlow: PRODUCT_DELETE trigger - deleting product in ZSBMS (deleteInstances) and local shadow table for product ID: " . $productId, LOG_INFO);
					try {
						$productSync = new ZSProductSynch($this->db);
						$productSync->deleteProductFromZoneSoftAndLocalTable($productId, $fallbackCodigo, $loja_zs);
					} catch (Exception $e) {
						dol_syslog("KreaFlow: PRODUCT_DELETE trigger - exception while deleting in ZSBMS/local table for product ID {$productId}: " . $e->getMessage(), LOG_ERR);

						// Fallback: at least clean local table to avoid orphan rows
						$sql = "DELETE FROM " . MAIN_DB_PREFIX . "dolizsynch_zsproduct WHERE fk_product = " . $productId;
						if (!$this->db->query($sql)) {
							dol_syslog("KreaFlow: PRODUCT_DELETE trigger - fallback local delete failed for product ID {$productId}: " . $this->db->lasterror(), LOG_ERR);
						}
					}
				} else {
					dol_syslog("KreaFlow: PRODUCT_DELETE trigger - missing product id, nothing to delete from ZSBMS/local table", LOG_WARNING);
				}
				break;

			default:
				break;
		}

		return 0;
	}

	/**
	 * Redirect to prompt user for ZSBMS product creation
	 *
	 * @param object $product The product object
	 * @param string $store The store identifier
	 */
	private function promptForZsbmsCreation($product, $store)
	{
		global $langs;

		// Debug product object details
		dol_syslog("ZSProductTrigger: promptForZsbmsCreation called with product ID: " . ($product->id ?? 'NULL') . ", REF: '" . ($product->ref ?? 'NULL') . "', STORE: {$store}", LOG_INFO);

		// Store the product ID, reference, and store in session for deferred prompt display
		// This avoids interrupting the database transaction with an immediate redirect
		if (session_status() == PHP_SESSION_NONE) {
			session_start();
		}

		// Store session variables for later use
		$_SESSION['zsbms_create_product_id'] = $product->id;
		$_SESSION['zsbms_create_product_ref'] = $product->ref;
		$_SESSION['zsbms_create_store'] = $store;
		$_SESSION['zsbms_show_prompt'] = true; // Flag to show prompt on next page load

		// Debug logging to verify session data is stored
		dol_syslog("ZSProductTrigger: Storing in session for deferred prompt - ID: {$product->id}, REF: '{$product->ref}', STORE: {$store}", LOG_INFO);

		// Don't redirect immediately - let the transaction complete first
		// The prompt will be shown on the next product page load via doActions hook
	}

	/**
	 * Synchronize inter-entity destination invoices when a source customer invoice is canceled/deleted.
	 *
	 * @param CommonObject $object Source customer invoice object
	 * @param string $action Trigger action
	 * @return int 0 if OK/no-op, <0 on blocking synchronization failure
	 */
	private function synchronizeInterEntitySourceInvoiceCancellation($object, $action)
	{
		if (!isModEnabled('multicompany') || !getDolGlobalString('DOLIZSYNCH_INTERENTITY_INVOICE_ENABLED')) {
			return 0;
		}

		$sourceInvoiceId = !empty($object->id) ? (int) $object->id : 0;
		if ($sourceInvoiceId <= 0) {
			return 0;
		}

		try {
			$converter = new ZSStoreInvoiceConverter($this->db);
			$result = $converter->synchronizeCanceledSourceInvoice($sourceInvoiceId, $action, false);
			if ($result < 0) {
				dol_syslog("KreaFlow: {$action} inter-entity destination synchronization failed for source invoice {$sourceInvoiceId}: " . $converter->error, LOG_ERR);
				if (function_exists('setEventMessages')) {
					setEventMessages("KreaFlow: failed to synchronize destination inter-entity invoice cancellation: " . $converter->error, null, 'errors');
				}
				return -1;
			}

			if ($result > 0) {
				dol_syslog("KreaFlow: {$action} synchronized {$result} inter-entity destination invoice(s) for source invoice {$sourceInvoiceId}", LOG_INFO);
			}
		} catch (Exception $e) {
			dol_syslog("KreaFlow: {$action} inter-entity destination synchronization exception for source invoice {$sourceInvoiceId}: " . $e->getMessage(), LOG_ERR);
			if (function_exists('setEventMessages')) {
				setEventMessages("KreaFlow: inter-entity cancellation synchronization exception: " . $e->getMessage(), null, 'errors');
			}
			return -1;
		}

		return 0;
	}

	/**
	 * Resolve current HTTP script path when available.
	 *
	 * @return string
	 */
	private function resolveRequestScriptPath()
	{
		$path = '';

		if (!empty($_SERVER['SCRIPT_NAME'])) {
			$path = (string) $_SERVER['SCRIPT_NAME'];
		} elseif (!empty($_SERVER['PHP_SELF'])) {
			$path = (string) $_SERVER['PHP_SELF'];
		} elseif (!empty($_SERVER['REQUEST_URI'])) {
			$uriPath = parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH);
			if (is_string($uriPath)) {
				$path = $uriPath;
			}
		}

		return strtolower(trim($path));
	}

	/**
	 * Check if PRODUCT_MODIFY autosync is allowed for current request context.
	 *
	 * Allowed contexts:
	 * - /product/card.php
	 * - /custom/kreaflow/zsproduct.php
	 * - /custom/kreaflow/sellPrice.php when action is update_vat
	 *
	 * @return bool
	 */
	private function isAllowedProductModifyAutosyncContext()
	{
		$scriptPath = $this->resolveRequestScriptPath();
		if ($scriptPath === '') {
			return false;
		}

		if (strpos($scriptPath, '/product/card.php') !== false) {
			return true;
		}

		if (strpos($scriptPath, '/custom/kreaflow/zsproduct.php') !== false) {
			return true;
		}

		if (strpos($scriptPath, '/custom/kreaflow/sellprice.php') !== false) {
			$requestAction = GETPOST('action', 'alpha');
			// Price saves already synchronize each edited level through PRODUCT_PRICE_MODIFY.
			return $requestAction === 'update_vat';
		}

		return false;
	}

	/**
	 * Check if PRODUCT_PRICE_MODIFY autosync is allowed for the current request context.
	 *
	 * Inbound API and cron synchronizations also call Product::updatePrice(). Limiting the
	 * outbound handler to explicit price-edit pages prevents those imports from echoing back.
	 *
	 * @return bool
	 */
	private function isAllowedProductPriceModifyAutosyncContext()
	{
		$scriptPath = $this->resolveRequestScriptPath();
		if ($scriptPath === '') {
			return false;
		}

		if (strpos($scriptPath, '/product/price.php') !== false) {
			$requestAction = GETPOST('action', 'alpha');
			$allowedActions = array('update_price', 'update_level_price');
			return in_array($requestAction, $allowedActions, true);
		}

		if (strpos($scriptPath, '/custom/kreaflow/sellprice.php') !== false) {
			$requestAction = GETPOST('action', 'alpha');
			$allowedActions = array('update_price', 'update_sim_price');
			return in_array($requestAction, $allowedActions, true);
		}

		return false;
	}
}

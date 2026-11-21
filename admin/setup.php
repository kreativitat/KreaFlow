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
 * \file    dolizsynch/admin/setup.php
 * \ingroup dolizsynch
 * \brief   DoliZSynch setup page.
 */

// Load Dolibarr environment
$res = 0;
// Try main.inc.php into web root known defined into CONTEXT_DOCUMENT_ROOT (not always defined)
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) {
	$res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"] . "/main.inc.php";
}
// Try main.inc.php into web root detected using web root calculated from SCRIPT_FILENAME
$tmp = empty($_SERVER['SCRIPT_FILENAME']) ? '' : $_SERVER['SCRIPT_FILENAME'];
$tmp2 = realpath(__FILE__);
$i = strlen($tmp) - 1;
$j = strlen($tmp2) - 1;
while ($i > 0 && $j > 0 && isset($tmp[$i]) && isset($tmp2[$j]) && $tmp[$i] == $tmp2[$j]) {
	$i--;
	$j--;
}
if (!$res && $i > 0 && file_exists(substr($tmp, 0, ($i + 1)) . "/main.inc.php")) {
	$res = @include substr($tmp, 0, ($i + 1)) . "/main.inc.php";
}
if (!$res && $i > 0 && file_exists(dirname(substr($tmp, 0, ($i + 1))) . "/main.inc.php")) {
	$res = @include dirname(substr($tmp, 0, ($i + 1))) . "/main.inc.php";
}
// Try main.inc.php using relative path
if (!$res && file_exists("../../main.inc.php")) {
	$res = @include "../../main.inc.php";
}
if (!$res && file_exists("../../../main.inc.php")) {
	$res = @include "../../../main.inc.php";
}
if (!$res) {
	die("Include of main fails");
}

global $langs, $user, $conf;

// Libraries
require_once DOL_DOCUMENT_ROOT . "/core/lib/admin.lib.php";
require_once DOL_DOCUMENT_ROOT . "/custom/kreaflow/lib/dolizsynch.lib.php";
require_once DOL_DOCUMENT_ROOT . "/core/lib/company.lib.php";
require_once DOL_DOCUMENT_ROOT . "/core/class/html.formcompany.class.php";
require_once DOL_DOCUMENT_ROOT . "/categories/class/categorie.class.php";
require_once DOL_DOCUMENT_ROOT . "/custom/kreaflow/class/zsapisynch.class.php";
require_once DOL_DOCUMENT_ROOT . "/custom/kreaflow/class/zsdictionairsynch.class.php";
require_once DOL_DOCUMENT_ROOT . "/custom/kreaflow/class/zsinvoicesynch.class.php";
require_once DOL_DOCUMENT_ROOT . '/product/class/product.class.php';
require_once DOL_DOCUMENT_ROOT . '/custom/kreaflow/class/zspunchclock.class.php';
require_once DOL_DOCUMENT_ROOT . '/custom/kreaflow/class/zsstoreinvoiceconverter.class.php';
require_once DOL_DOCUMENT_ROOT . '/custom/kreaflow/class/zsproductpropertyservice.class.php';


// Translations
$langs->loadLangs(array("admin", "dolizsynch@kreaflow"));

// Initialize technical object to manage hooks of page. Note that conf->hooks_modules contains array of hook context
$hookmanager->initHooks(array('dolizsynchsetup', 'globalsetup'));

// Access control
if (!$user->admin) {
	accessforbidden();
}

// Parameters
$action = GETPOST('action', 'aZ09');
$backtopage = GETPOST('backtopage', 'alpha');
$modulepart = GETPOST('modulepart', 'aZ09');    // Used by actions_setmoduleoptions.inc.php

$value = GETPOST('value', 'alpha');
$label = GETPOST('label', 'alpha');
$scandir = GETPOST('scan_dir', 'alpha');
$type = 'zspunchclock';


$error = 0;
$setupnotempty = 0;

// Set this to 1 to use the factory to manage constants. Warning, the generated module will be compatible with version v15+ only
$useFormSetup = 1;

if (!class_exists('FormSetup')) {
	// For retrocompatibility Dolibarr < 16.0
	if (floatval(DOL_VERSION) < 16.0 && !class_exists('FormSetup')) {
		require_once __DIR__ . '/../backport/v16/core/class/html.formsetup.class.php';
	} else {
		require_once DOL_DOCUMENT_ROOT . '/core/class/html.formsetup.class.php';
	}
}

// Fetch bank accounts for current entity
$sql = "SELECT b.rowid, b.label
        FROM " . MAIN_DB_PREFIX . "bank_account as b
        WHERE b.entity = " . $conf->entity . "
        ORDER BY b.label ASC";
$resql = $db->query($sql);
$TBankAccounts = array();
if ($resql) {
	while ($obj = $db->fetch_object($resql)) {
		$TBankAccounts[$obj->rowid] = $obj->label;
	}
} else {
	dol_print_error($db);
}

$formSetup = new FormSetup($db);
$form = new Form($db);
$formcompany = new FormCompany($db);

$formSetup->htmlBeforeOutputForm .= '
<link rel="stylesheet" href="' . dol_buildpath('/kreaflow/vendor/bootstrap4/bootstrap.min.css', 1) . '">
<link rel="stylesheet" href="' . dol_buildpath('/kreaflow/vendor/tempusdominus-bootstrap-4/tempusdominus-bootstrap-4.min.css', 1) . '">
<script src="' . dol_buildpath('/kreaflow/vendor/moment/moment.min.js', 1) . '"></script>
<script src="' . dol_buildpath('/kreaflow/vendor/bootstrap4/bootstrap.bundle.min.js', 1) . '"></script>
<script src="' . dol_buildpath('/kreaflow/vendor/tempusdominus-bootstrap-4/tempusdominus-bootstrap-4.min.js', 1) . '"></script>
';

/*
 *
 *
 * Configuration ZS_API_HOST
 *
 *
 */
$formSetup->newItem('ZS_API_HOST_TITLE')->setAsTitle();

// Setup conf ZS_API_HOST as a simple string input
// ZonSoft API Host
$item = $formSetup->newItem('ZS_API_HOST');
$item->defaultFieldValue = 'https://api.zonesoft.org';

// Setup conf ZS_API_VERSION as a simple string input
// ZonSoft API Version
$item = $formSetup->newItem('ZS_API_VERSION');
$item->defaultFieldValue = 'v3';

// Setup conf ZS_APP_KEY as a simple string input
$item = $formSetup->newItem('ZS_APP_KEY');

// Setup conf ZS_APP_SECRET as a simple string input
$item = $formSetup->newItem('ZS_APP_SECRET');

// Setup conf ZS_CLIENT_ID as a simple string input
$item = $formSetup->newItem('ZS_CLIENT_ID');


// Setup conf ZS_API_STORE as a simple string input
// Numero de Loja ZonSoft
$item = $formSetup->newItem('ZS_API_STORE');
$item->fieldAttr['type'] = 'number'; // HTML5 input type number
$item->fieldAttr['min'] = '1'; // Set minimum value to 0 or any value you prefer
$item->fieldAttr['max'] = '100'; // Set maximum value to 0 or any value you prefer
$item->fieldAttr['placeholder'] = $langs->trans('ZS_API_STORE_PLACEHOLDER');
$item->fieldAttr['step'] = '1'; // Allow only integer values
$item->defaultFieldValue = '1'; // Default value if you need one

// Prepare the package-count property options for the inter-entity section below.
$configuredPropertyStockId = getDolGlobalInt('DOLIZSYNCH_PROPERTY_STOCK_SOURCE_PROPERTY_ID');
$propertyStockOptions = array(0 => $langs->trans('DoliZSynchPropertyStockSetupNotConfigured'));
$propertyStockLoadError = '';
$propertyStore = getDolGlobalInt('ZS_API_STORE');
if ($propertyStore > 0) {
	try {
		$propertyService = new ZSProductPropertyService($db);
		$propertyDefinitions = $propertyService->fetchDefinitionsForStore($propertyStore);
		foreach ($propertyDefinitions as $propertyId => $definition) {
			if (!empty($definition['ativo']) && ($definition['tipo_dados'] ?? '') === 'numeric') {
				$propertyStockOptions[(int) $propertyId] = (string) $definition['nome'] . ' (#' . (int) $propertyId . ')';
			}
		}
	} catch (Exception $e) {
		$propertyStockLoadError = $e->getMessage();
		dol_syslog(__METHOD__ . ': Unable to load ZSBMS properties for setup: ' . $propertyStockLoadError, LOG_WARNING);
	}
}
if ($configuredPropertyStockId > 0 && !isset($propertyStockOptions[$configuredPropertyStockId])) {
	$propertyStockOptions[$configuredPropertyStockId] = $langs->trans(
		'DoliZSynchPropertyStockSetupUnavailable',
		$configuredPropertyStockId
	);
}

// Store/entity mapping editor
if ($action === 'savestoremap') {
	if (GETPOST('token') !== $_SESSION['newtoken']) {
		accessforbidden('Wrong CSRF token');
	}

	$stores = (array) GETPOST('store', 'array');
	$zsbmsStores = (array) GETPOST('zsbms_store', 'array');
	$storeDolibarr = (array) GETPOST('store_dolibarr', 'array');
	$storeNif = (array) GETPOST('store_nif', 'array');
	$rowids = (array) GETPOST('rowid', 'array');
	$deleteFlags = (array) GETPOST('delete_row', 'array');

	$db->begin();
	$errorEdit = 0;

	foreach ($stores as $idx => $storeVal) {
		$rowid = isset($rowids[$idx]) ? (int) $rowids[$idx] : 0;
		$delete = !empty($deleteFlags[$idx]);

		$storeVal = trim((string) $storeVal);
		$zsbmsVal = trim((string) ($zsbmsStores[$idx] ?? ''));
		$storeDol = isset($storeDolibarr[$idx]) ? (int) $storeDolibarr[$idx] : 0;
		$storeNifVal = trim((string) ($storeNif[$idx] ?? ''));

		if (!$delete && $rowid <= 0 && $storeVal === '' && $zsbmsVal === '' && $storeDol <= 0 && $storeNifVal === '') {
			continue;
		}

		if ($delete && $rowid > 0) {
			$sql = "DELETE FROM " . $tableStoreMap . " WHERE rowid = " . $rowid . " AND entity IN (0, " . ((int) $conf->entity) . ")";
			if (!$db->query($sql)) {
				$errorEdit++;
			}
			continue;
		}

		if ($storeVal === '' || $zsbmsVal === '' || $storeDol <= 0) {
			$errorEdit++;
			continue;
		}

		if ($rowid > 0) {
			$sql = "UPDATE " . $tableStoreMap . " SET";
			$sql .= " store = '" . $db->escape($storeVal) . "',";
			$sql .= " zsbms_store = '" . $db->escape($zsbmsVal) . "',";
			$sql .= " store_dolibarr = " . (int) $storeDol . ",";
			$sql .= " store_nif = " . ($storeNifVal !== '' ? "'" . $db->escape($storeNifVal) . "'" : "NULL");
			$sql .= " WHERE rowid = " . $rowid . " AND entity IN (0, " . ((int) $conf->entity) . ")";
		} else {
			$sql = "INSERT INTO " . $tableStoreMap . " (store, zsbms_store, store_dolibarr, store_nif, entity, date_creation)";
			$sql .= " VALUES (";
			$sql .= "'" . $db->escape($storeVal) . "',";
			$sql .= "'" . $db->escape($zsbmsVal) . "',";
			$sql .= (int) $storeDol . ",";
			$sql .= ($storeNifVal !== '' ? "'" . $db->escape($storeNifVal) . "'" : "NULL") . ",";
			$sql .= ((int) $conf->entity) . ",";
			$sql .= "'" . $db->idate(dol_now()) . "'";
			$sql .= ")";
		}

		if (!$db->query($sql)) {
			$errorEdit++;
		}
	}

	if ($errorEdit > 0) {
		$db->rollback();
		setEventMessages($langs->trans('ErrorUpdateStoreMap'), null, 'errors');
	} else {
		$db->commit();
		setEventMessages($langs->trans('StoreMapSaved'), null, 'mesgs');
	}
}

// Mapping rows are configured by the administrator; never seed deployment data.
$tableStoreMap = $db->prefix() . 'dolizsynch_storemap';
$sqlCreateStoreMap = "CREATE TABLE IF NOT EXISTS " . $tableStoreMap . " (";
$sqlCreateStoreMap .= " rowid INTEGER AUTO_INCREMENT PRIMARY KEY,";
$sqlCreateStoreMap .= " store VARCHAR(10) NOT NULL,";
$sqlCreateStoreMap .= " zsbms_store VARCHAR(10) NOT NULL,";
$sqlCreateStoreMap .= " store_dolibarr INTEGER NOT NULL,";
$sqlCreateStoreMap .= " store_nif VARCHAR(32) DEFAULT NULL,";
$sqlCreateStoreMap .= " entity INTEGER NOT NULL DEFAULT 0,";
$sqlCreateStoreMap .= " date_creation DATETIME NULL,";
$sqlCreateStoreMap .= " tms TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,";
$sqlCreateStoreMap .= " UNIQUE INDEX uk_dolizsynch_storemap_zsbms (zsbms_store, entity)";
$sqlCreateStoreMap .= ") ENGINE=innodb;";

$resCreate = $db->query($sqlCreateStoreMap);
if (!$resCreate) {
	setEventMessages($langs->trans('ErrorCreateStoreMapTable') . ' ' . $db->lasterror(), null, 'errors');
}


/*
 *
 *
 * Titulo de separador PRODUCTS
 * Sincronização de produtos
 *
 *
 */
$formSetup->newItem('ZS_SYNC_PRODUCTS_TITLE')->setAsTitle();

// Setup conf ZS_GET_NUMBER_OF_PRODUCTS_LIMIT as a simple string input
// Lote de produtos sincronizados em cada consulta (recomendado 15)
$item = $formSetup->newItem('ZS_GET_NUMBER_OF_PRODUCTS_LIMIT');
$item->fieldAttr['type'] = 'number'; // HTML5 input type number
$item->fieldAttr['min'] = '1'; // Set minimum value to 0 or any value you prefer
$item->fieldAttr['max'] = '100'; // Set maximum value to 0 or any value you prefer
$item->fieldAttr['placeholder'] = $langs->trans('ZS_GET_NUMBER_OF_PRODUCTS_LIMIT_PLACEHOLDER');
$item->fieldAttr['step'] = '1'; // Allow only integer values
$item->defaultFieldValue = '15'; // Default value if you need one

// Setup conf ZS_GET_PRODUCTS_OFFSET as a simple string input
// Offset de lote de produtos sincronizados em cada consulta (recomendado igual ao anterior)
$item = $formSetup->newItem('ZS_GET_PRODUCTS_OFFSET');
$item->fieldAttr['type'] = 'number'; // HTML5 input type number
$item->fieldAttr['min'] = '1'; // Set minimum value to 0 or any value you prefer
$item->fieldAttr['max'] = '100'; // Set maximum value to 0 or any value you prefer
$item->fieldAttr['placeholder'] = $langs->trans('ZS_GET_PRODUCTS_OFFSET_PLACEHOLDER');
$item->fieldAttr['step'] = '1'; // Allow only integer values
$item->defaultFieldValue = '15'; // Default value if you need one

// Check if MultiCompany module is active
// Sincronizar apenas produtos alterados nos ultimos M minutos
if (!empty($conf->multicompany->enabled)) {
	// Setup conf ZS_PRODUTS_CHANGED_LAST_X_MINUTES as a simple string input
	$item = $formSetup->newItem('ZS_PRODUTS_CHANGED_LAST_X_MINUTES');
	$item->fieldAttr['type'] = 'number'; // HTML5 input type number
	$item->fieldAttr['min'] = '1'; // Set minimum value to 0 or any value you prefer
	$item->fieldAttr['max'] = '1440'; // Set maximum value to 0 or any value you prefer
	$item->fieldAttr['placeholder'] = $langs->trans('ZS_PRODUTS_CHANGED_LAST_X_MINUTES_PLACEHOLDER');
	$item->fieldAttr['step'] = '1'; // Allow only integer values
	$item->defaultFieldValue = '24'; // Default value if you need one
}

// Setup conf ZS_DEFAULT_PRODUCT_UNIT
// Unidades de produtos ZoneSoft por defeito para sincronização de produtos
$sql = "SELECT unit_id, unit_name FROM  " . MAIN_DB_PREFIX . "dolizsynch_zsunit ORDER BY unit_name ASC";
$resql = $db->query($sql);
$TField = array();
if ($resql) {
	while ($obj = $db->fetch_object($resql)) {
		$TField[$obj->unit_id] = $obj->unit_name;
	}
} else {
	dol_print_error($db);
}
$item = $formSetup->newItem('ZS_DEFAULT_PRODUCT_UNIT');
$item->setAsSelect($TField);
$item->defaultFieldValue = '';

// Setup conf ZS_DEFAULT_PRODUCT_FAMILY
// Familia de produtos ZoneSoft por defeito para sincronização de produtos
$sql = "SELECT CASE WHEN subfamily_id = 0 THEN family_id ELSE subfamily_id END AS id,
        CASE WHEN subfamily_name = '-' THEN family_name ELSE CONCAT(family_name, ' >> ', subfamily_name) END AS family_concatenated
        FROM " . MAIN_DB_PREFIX . "dolizsynch_zsfamily ORDER BY family_concatenated ASC";
$resql = $db->query($sql);
$TField = array();
if ($resql) {
	while ($obj = $db->fetch_object($resql)) {
		$TField[$obj->id] = $obj->family_concatenated;
	}
} else {
	dol_print_error($db);
}
$item = $formSetup->newItem('ZS_DEFAULT_PRODUCT_FAMILY');
$item->setAsSelect($TField);
$item->defaultFieldValue = '';

// Setup conf ZS_DEFAULT_PRODUCT_CATEGORY
// Categoria de produtos ZoneSoft por defeito para sincronização de produtos
$sql = "SELECT CASE WHEN subcategory_id = 0 THEN category_id ELSE subcategory_id END AS id,
        CASE WHEN subcategory_name = '-' THEN category_name ELSE CONCAT(category_name, ' >> ', subcategory_name) END AS category_concatenated
        FROM " . MAIN_DB_PREFIX . "dolizsynch_zscategory ORDER BY category_concatenated ASC";
$resql = $db->query($sql);
$TField = array();
if ($resql) {
	while ($obj = $db->fetch_object($resql)) {
		$TField[$obj->id] = $obj->category_concatenated;
	}
} else {
	dol_print_error($db);
}
$item = $formSetup->newItem('ZS_DEFAULT_PRODUCT_CATEGORY');
$item->setAsSelect($TField);
$item->defaultFieldValue = '';

// Setup conf ZS_DEFAULT_BUTTON_BACKGROUND_COLOR
// Cor de fundo do botão de produto por defeito (ZS)
$item = $formSetup->newItem('ZS_DEFAULT_BUTTON_BACKGROUND_COLOR');
$item->fieldAttr['type'] = 'color';
$item->fieldAttr['style'] = 'width: 100px; height: 40px; cursor: pointer;';
$item->defaultFieldValue = '#696969'; // RGB(105,105,105)
$item->helpText = $langs->trans('ZS_DEFAULT_BUTTON_BACKGROUND_COLOR_HELP');
// Custom display for view mode (showing color preview)
$currentBgColor = getDolGlobalString('ZS_DEFAULT_BUTTON_BACKGROUND_COLOR', '#696969');
$item->fieldOutputOverride = '<input type="color" value="' . dol_escape_htmltag($currentBgColor) . '" disabled style="width: 60px; height: 40px; margin-right: 10px; vertical-align: middle;"> ' . dol_escape_htmltag($currentBgColor);

// Setup conf ZS_DEFAULT_BUTTON_FONT_COLOR
// Cor da letra do botão de produto por defeito (ZS)
$item = $formSetup->newItem('ZS_DEFAULT_BUTTON_FONT_COLOR');
$item->fieldAttr['type'] = 'color';
$item->fieldAttr['style'] = 'width: 100px; height: 40px; cursor: pointer;';
$item->defaultFieldValue = '#ffffff'; // RGB(255,255,255)
$item->helpText = $langs->trans('ZS_DEFAULT_BUTTON_FONT_COLOR_HELP');
// Custom display for view mode (showing color preview)
$currentFontColor = getDolGlobalString('ZS_DEFAULT_BUTTON_FONT_COLOR', '#ffffff');
$item->fieldOutputOverride = '<input type="color" value="' . dol_escape_htmltag($currentFontColor) . '" disabled style="width: 60px; height: 40px; margin-right: 10px; vertical-align: middle;"> ' . dol_escape_htmltag($currentFontColor);

/*
 *
 *
 * Titulo de separador Invoices
	 * Invoice synchronization
 *
 *
 */
$formSetup->newItem('ZS_SYNC_INVOICES_TITLE')->setAsTitle();

// Products used by invoice synchronization and monetary reconciliation.
$sql = "SELECT rowid, label FROM " . MAIN_DB_PREFIX . "product WHERE entity IN (0, " . getEntity('product') . ") ORDER BY label ASC";
$resql = $db->query($sql);
$invoiceProductOptions = array();
if ($resql) {
	while ($obj = $db->fetch_object($resql)) {
		$invoiceProductOptions[$obj->rowid] = $obj->label;
	}
} else {
	dol_print_error($db);
}

$item = $formSetup->newItem('ZS_ADJUSTMENT_PRODUCT_ID');
$item->setAsSelect($invoiceProductOptions);
$item->defaultFieldValue = '';

$item = $formSetup->newItem('ZS_GENERIC_PRODUCT_ID');
$item->setAsSelect($invoiceProductOptions);
$item->defaultFieldValue = '';

// Credit-note refund behavior belongs to invoice synchronization.
$item = $formSetup->newItem('ZS_CREDIT_NOTE_REFUND_MODE');
$item->setAsSelect(array('original' => $langs->trans('OriginalPaymentMethod'), 'cash' => $langs->trans('CashRefund')));
$item->defaultFieldValue = 'original';

$sql = "SELECT id, code, libelle FROM " . MAIN_DB_PREFIX . "c_paiement WHERE active = 1 ORDER BY libelle ASC";
$resql = $db->query($sql);
$cashPaymentTypeOptions = array();
if ($resql) {
	while ($obj = $db->fetch_object($resql)) {
		$cashPaymentTypeOptions[$obj->id] = $obj->libelle . ' (' . $obj->code . ')';
	}
} else {
	dol_print_error($db);
}
$item = $formSetup->newItem('ZS_CASH_PAYMENT_TYPE_ID');
$item->setAsSelect($cashPaymentTypeOptions);
$item->defaultFieldValue = '';

// Enable/disable manual ZSBMS invoice creator page/menu (off by default)
$item = $formSetup->newItem('DOLIZSYNCH_ZSBMS_INVOICE_CREATOR_ENABLED');
$item->setAsYesNo();
$item->defaultFieldValue = '0';
$item->helpText = $langs->trans('DOLIZSYNCH_ZSBMS_INVOICE_CREATOR_ENABLED_HELP');

$item = $formSetup->newItem('DOLIZSYNCH_EXCLUDED_ZSBMS_DOCUMENT_TYPES');
$item->defaultFieldValue = 'EC';
$item->helpText = $langs->trans('DOLIZSYNCH_EXCLUDED_ZSBMS_DOCUMENT_TYPES_HELP');

// Setup IVA included flag.
$formSetup->newItem('ZS_IVA_INCLUIDO')->setAsYesNo();

// Setup conf ZS_CREATE_NEW_CUSTOMERS as a yes/no selector
// Create a new customer during invoice import when none exists.
$formSetup->newItem('ZS_CREATE_NEW_CUSTOMERS')->setAsYesNo();

// Setup conf ZS_CONSUMIDOR_FINAL
// ID de Terceiro para atribuição de fatura de cliente "CONSUMIDOR FINAL"
$sql = "SELECT rowid, nom FROM " . MAIN_DB_PREFIX . "societe ORDER BY nom ASC";
$resql = $db->query($sql);
$TField = array();
if ($resql) {
	while ($obj = $db->fetch_object($resql)) {
		$TField[$obj->rowid] = $obj->nom;
	}
} else {
	dol_print_error($db);
}
$item = $formSetup->newItem('ZS_CONSUMIDOR_FINAL');
$item->setAsSelect($TField);
$item->defaultFieldValue = '';

// Setup conf ZS_DEFAULT_PAYMENT_TYPE
// Metodo de pagamento ZoneSoft por defeito para sincronização de Faturas
$sql = "SELECT paymenttype_id, paymenttype_name FROM  " . MAIN_DB_PREFIX . "dolizsynch_zspaymenttype ORDER BY paymenttype_name ASC";
$resql = $db->query($sql);
$TField = array();
if ($resql) {
	while ($obj = $db->fetch_object($resql)) {
		$TField[$obj->paymenttype_id] = $obj->paymenttype_name;
	}
} else {
	dol_print_error($db);
}
$item = $formSetup->newItem('ZS_DEFAULT_PAYMENT_TYPE');
$item->setAsSelect($TField);
$item->defaultFieldValue = '';

// Setup conf ZS_GET_NUMBER_OF_INVOICES_LIMIT as a number input
// Numero maximo de recibos recolhidos no download
$item = $formSetup->newItem('ZS_GET_NUMBER_OF_INVOICES_LIMIT');
$item->fieldAttr['type'] = 'number'; // HTML5 input type number
$item->fieldAttr['min'] = '1'; // Set minimum value to 0 or any value you prefer
$item->fieldAttr['max'] = '250'; // Set maximum value to 0 or any value you prefer
$item->fieldAttr['placeholder'] = $langs->trans('ZS_GET_NUMBER_OF_INVOICES_LIMIT_PLACEHOLDER');

// Setup conf ZS_MAX_NUMBER_OF_INVOICES_IN_EACH_SYNCHRONIZATION as a number input
// Numero maximo de recibos sincronizados por cada chamada de sincronização
$item = $formSetup->newItem('ZS_MAX_NUMBER_OF_INVOICES_IN_EACH_SYNCHRONIZATION');
$item->fieldAttr['type'] = 'number'; // HTML5 input type number
$item->fieldAttr['min'] = '1'; // Set minimum value to 0 or any value you prefer
$item->fieldAttr['max'] = '250'; // Set maximum value to 0 or any value you prefer
$item->fieldAttr['placeholder'] = $langs->trans('ZS_MAX_NUMBER_OF_INVOICES_IN_EACH_SYNCHRONIZATION_PLACEHOLDER');
$item->fieldAttr['step'] = '1'; // Allow only integer values
$item->defaultFieldValue = '6'; // Default value if you need one

// Setup conf ZS_MAX_SYNCH_TRIES_PER_INVOICE as a number input
// Maximum number of synchronization attempts per invoice before abandoning it
$item = $formSetup->newItem('ZS_MAX_SYNCH_TRIES_PER_INVOICE');
$item->fieldAttr['type'] = 'number';
$item->fieldAttr['min'] = '1';
$item->fieldAttr['max'] = '50';
$item->fieldAttr['placeholder'] = $langs->trans('ZS_MAX_SYNCH_TRIES_PER_INVOICE_PLACEHOLDER');
$item->fieldAttr['step'] = '1';
$item->defaultFieldValue = '3';

// Setup conf ZS_DEFAULT_BANK_ACCOUNT
// Conta bancária pré-definida para sincronização de Faturas
$item = $formSetup->newItem('ZS_DEFAULT_BANK_ACCOUNT');
$item->setAsSelect($TBankAccounts);
$item->defaultFieldValue = '';
$item->fieldAttr['placeholder'] = $langs->trans('ZS_DEFAULT_BANK_ACCOUNT_PLACEHOLDER');

/*
 * Inter-Entity Invoice Conversion
 * Enable conversion of customer invoices from seller entity to buyer entities
 * ONLY AVAILABLE WITH MULTICOMPANY MODULE
 */
if (isModEnabled('multicompany')) {
	$formSetup->newItem('ZS_INTERENTITY_INVOICE_TITLE')->setAsTitle();

	// Enable/Disable Inter-Entity Invoice Conversion
	$formSetup->newItem('DOLIZSYNCH_INTERENTITY_INVOICE_ENABLED')->setAsYesNo();

	// Exact-stock source property used by destination product rules.
	$item = $formSetup->newItem('DOLIZSYNCH_PROPERTY_STOCK_SOURCE_PROPERTY_ID');
	$item->setAsSelect($propertyStockOptions);
	$item->defaultFieldValue = '0';
	$item->helpText = $langs->trans('DOLIZSYNCH_PROPERTY_STOCK_SOURCE_PROPERTY_ID_HELP');
	if ($propertyStockLoadError !== '') {
		$item->helpText .= ' ' . $langs->trans('DoliZSynchProductPropertiesLoadFailed') . ': '
			. ZSProductPropertyService::decodeDisplayText($propertyStockLoadError);
	}

	// Rounding product for inter-entity invoices.
	$item = $formSetup->newItem('ZS_ROUNDING_PRODUCT_ID');
	$item->fieldAttr['type'] = 'number';
	$item->fieldAttr['min'] = '0';
	$item->defaultFieldValue = '7050';

	// Payment term for inter-entity invoices.
	$item = $formSetup->newItem('ZS_PAYMENT_TERM_ID');
	$item->fieldAttr['type'] = 'number';
	$item->fieldAttr['min'] = '0';
	$item->defaultFieldValue = '97';

	// Show entity selector only if the feature is enabled
	if (getDolGlobalString('DOLIZSYNCH_INTERENTITY_INVOICE_ENABLED')) {
		// Fetch ALL active entities (excluding current entity)
		$sql = "SELECT e.rowid AS entity_id, e.label AS entity_name";
		$sql .= " FROM " . MAIN_DB_PREFIX . "entity e";
		$sql .= " WHERE e.active = 1";
		$sql .= " AND e.rowid != " . ((int) $conf->entity); // Exclude current entity
		$sql .= " ORDER BY e.label";

		$resql = $db->query($sql);
		if (!$resql) {
			dol_print_error($db);
		}

		$targetEntities = array();
		while ($obj = $db->fetch_object($resql)) {
			$targetEntities[$obj->entity_id] = $obj->entity_name ? $obj->entity_name : 'Entity ' . $obj->entity_id;
		}
		$db->free($resql);

		// Add target entities selector (multi-select, excludes current entity automatically)
		$item = $formSetup->newItem('DOLIZSYNCH_INTERENTITY_TARGET_ENTITIES');
		$item->setAsMultiSelect($targetEntities);
		$item->helpText = $langs->trans('DOLIZSYNCH_INTERENTITY_TARGET_ENTITIES_HELP');

		// Add label-based product mapping fallback toggle
		$item = $formSetup->newItem('DOLIZSYNCH_INTERENTITY_LABEL_FALLBACK_ENABLED');
		$item->setAsYesNo();
		$item->helpText = $langs->trans('InterEntityEnableLabelFallbackDesc');

		// Add suffix fields (always visible)
		$item = $formSetup->newItem('DOLIZSYNCH_INTERENTITY_SELLER_SUFFIX');
		$item->defaultFieldValue = "'RV";
		$item->helpText = $langs->trans('InterEntitySellerSuffixDesc');

		$item = $formSetup->newItem('DOLIZSYNCH_INTERENTITY_BUYER_SUFFIX');
		$item->defaultFieldValue = "'CL";
		$item->helpText = $langs->trans('InterEntityBuyerSuffixDesc');
	}
}



// DO NOT DELETE EXAMPLES
// Dropdown menu to choose ZS_CONSUMIDOR_FINAL
//$thirdpartySelectHtml = $formcompany->select_thirdparty_list($conf->global->ZS_CONSUMIDOR_FINAL ?? '', 'ZS_CONSUMIDOR_FINAL', '', 1);
//$item = $formSetup->newItem('ZS_CONSUMIDOR_FINAL');
//$item->fieldInputOverride = $thirdpartySelectHtml;
//$item->defaultFieldValue = '';
//// Dropdown menu to choose ZS_DEFAULT_PRODUCT_CATEGORY
//$productCategorySelectHtml = $form->select_all_categories(Categorie::TYPE_PRODUCT, $conf->global->ZS_DEFAULT_PRODUCT_CATEGORY ?? '', 'ZS_DEFAULT_PRODUCT_CATEGORY');
//$item = $formSetup->newItem('ZS_DEFAULT_PRODUCT_CATEGORY');
//$item->fieldInputOverride = $productCategorySelectHtml;
//$item->defaultFieldValue = '';

// Setup conf DOLIZSYNCH_MYPARAM2 as a simple textarea input but we replace the text of field title
//$item = $formSetup->newItem('DOLIZSYNCH_MYPARAM2');
//$item->nameText = $item->getNameText() . ' more html text ';

// Setup conf DOLIZSYNCH_MYPARAM3
//$item = $formSetup->newItem('DOLIZSYNCH_MYPARAM3');
//$item->setAsThirdpartyType();

// Setup conf DOLIZSYNCH_MYPARAM4 as a yes/no selector.
//$formSetup->newItem('DOLIZSYNCH_MYPARAM4')->setAsYesNo();

// Setup conf DOLIZSYNCH_MYPARAM5 Selector dropdown
//$formSetup->newItem('DOLIZSYNCH_MYPARAM5')->setAsEmailTemplate('thirdparty');

// Setup conf DOLIZSYNCH_MYPARAM6 for automatic password configuration.
//$formSetup->newItem('DOLIZSYNCH_MYPARAM6')->setAsSecureKey()->enabled = 1; // disabled

// Setup conf DOLIZSYNCH_MYPARAM7 selector de produtos
//$formSetup->newItem('DOLIZSYNCH_MYPARAM7')->setAsProduct();

// Titulo de separador
//$formSetup->newItem('Title')->setAsTitle();

// Setup conf DOLIZSYNCH_MYPARAM8  Selector dropdown
//$item = $formSetup->newItem('DOLIZSYNCH_MYPARAM8');
//$TField = array(
//  'test01' => $langs->trans('test01'),
//  'test02' => $langs->trans('test02'),
//  'test03' => $langs->trans('test03'),
//  'test04' => $langs->trans('test04'),
//  'test05' => $langs->trans('test05'),
//  'test06' => $langs->trans('test06'),
//);
//$item->setAsMultiSelect($TField);
//$item->helpText = $langs->transnoentities('DOLIZSYNCH_MYPARAM8');


// Setup conf DOLIZSYNCH_MYPARAM9
//$formSetup->newItem('DOLIZSYNCH_MYPARAM9')->setAsSelect($TField);


// Setup conf DOLIZSYNCH_MYPARAM10 selector de cores
//$item = $formSetup->newItem('DOLIZSYNCH_MYPARAM10');
//$item->setAsColor();
//$item->defaultFieldValue = '#FF0000';
//$item->nameText = $item->getNameText() . ' more html text ';
//$item->fieldInputOverride = '';
//$item->helpText = $langs->transnoentities('AnHelpMessage');
//$item->fieldValue = '';
//$item->fieldAttr = array() ; // fields attribute only for compatible fields like input text
//$item->fieldOverride = false; // set this var to override field output will override $fieldInputOverride and $fieldOutputOverride too
//$item->fieldInputOverride = false; // set this var to override field input
//$item->fieldOutputOverride = false; // set this var to override field output

// HTTP HOST
// $item = $formSetup->newItem('NO_PARAM_JUST_TEXT');
// $item->fieldOverride = (empty($_SERVER['HTTPS']) ? 'http://' : 'https://') . $_SERVER['HTTP_HOST'];
// $item->cssClass = 'minwidth500';


$setupnotempty += count($formSetup->items);


$dirmodels = array_merge(array('/'), (array) $conf->modules_parts['models']);


/*
 *
 * Actions for sinchronization
 *
 */

// For retrocompatibility Dolibarr < 15.0
if (versioncompare(explode('.', DOL_VERSION), array(15)) < 0 && $action == 'update' && !empty($user->admin)) {
	$formSetup->saveConfFromPost();
}

include DOL_DOCUMENT_ROOT . '/core/actions_setmoduleoptions.inc.php';

if ($action == 'updateMask') {
	$maskconst = GETPOST('maskconst', 'aZ09');
	$maskvalue = GETPOST('maskvalue', 'alpha');

	if ($maskconst && preg_match('/_MASK$/', $maskconst)) {
		$res = dolibarr_set_const($db, $maskconst, $maskvalue, 'chaine', 0, '', $conf->entity);
		if (!($res > 0)) {
			$error++;
		}
	}

	if (!$error) {
		setEventMessages($langs->trans("SetupSaved"), null, 'mesgs');
	} else {
		setEventMessages($langs->trans("Error"), null, 'errors');
	}
} elseif ($action == 'specimen') {
	$modele = GETPOST('module', 'alpha');
	$tmpobjectkey = GETPOST('object');

	$tmpobject = new $tmpobjectkey($db);
	$tmpobject->initAsSpecimen();

	// Search template files
	$file = '';
	$classname = '';
	$filefound = 0;
	$dirmodels = array_merge(array('/'), (array) $conf->modules_parts['models']);
	foreach ($dirmodels as $reldir) {
		$file = dol_buildpath($reldir . "core/modules/kreaflow/doc/pdf_" . $modele . "_" . strtolower($tmpobjectkey) . ".modules.php", 0);
		if (file_exists($file)) {
			$filefound = 1;
			$classname = "pdf_" . $modele . "_" . strtolower($tmpobjectkey);
			break;
		}
	}

	if ($filefound) {
		require_once $file;

		$module = new $classname($db);

		if ($module->write_file($tmpobject, $langs) > 0) {
			header("Location: " . DOL_URL_ROOT . "/document.php?modulepart=dolizsynch-" . strtolower($tmpobjectkey) . "&file=SPECIMEN.pdf");
			return;
		} else {
			setEventMessages($module->error, null, 'errors');
			dol_syslog($module->error, LOG_ERR);
		}
	} else {
		setEventMessages($langs->trans("ErrorModuleNotFound"), null, 'errors');
		dol_syslog($langs->trans("ErrorModuleNotFound"), LOG_ERR);
	}
} elseif ($action == 'setmod') {
	// TODO Check if numbering module chosen can be activated by calling method canBeActivated
	$tmpobjectkey = GETPOST('object');
	if (!empty($tmpobjectkey)) {
		$constforval = 'DOLIZSYNCH_' . strtoupper($tmpobjectkey) . "_ADDON";
		dolibarr_set_const($db, $constforval, $value, 'chaine', 0, '', $conf->entity);
	}
} elseif ($action == 'set') {
	// Activate a model
	$ret = addDocumentModel($value, $type, $label, $scandir);
} elseif ($action == 'del') {
	$ret = delDocumentModel($value, $type);
	if ($ret > 0) {
		$tmpobjectkey = GETPOST('object');
		if (!empty($tmpobjectkey)) {
			$constforval = 'DOLIZSYNCH_' . strtoupper($tmpobjectkey) . '_ADDON_PDF';
			if (getDolGlobalString($constforval) == "$value") {
				dolibarr_del_const($db, $constforval, $conf->entity);
			}
		}
	}
} elseif ($action == 'setdoc') {
	// Set or unset default model
	$tmpobjectkey = GETPOST('object');
	if (!empty($tmpobjectkey)) {
		$constforval = 'DOLIZSYNCH_' . strtoupper($tmpobjectkey) . '_ADDON_PDF';
		if (dolibarr_set_const($db, $constforval, $value, 'chaine', 0, '', $conf->entity)) {
			// The constant that was read before the new set
			// We therefore requires a variable to have a coherent view
			$conf->global->{$constforval} = $value;
		}

		// We disable/enable the document template (into llx_document_model table)
		$ret = delDocumentModel($value, $type);
		if ($ret > 0) {
			$ret = addDocumentModel($value, $type, $label, $scandir);
		}
	}
} elseif ($action == 'unsetdoc') {
	$tmpobjectkey = GETPOST('object');
	if (!empty($tmpobjectkey)) {
		$constforval = 'DOLIZSYNCH_' . strtoupper($tmpobjectkey) . '_ADDON_PDF';
		dolibarr_del_const($db, $constforval, $conf->entity);
	}
}

// Force Synch Invoice
if (GETPOST('forceSynchInvoice', 'alpha') !== '' || GETPOST('forceSynchInvoice2', 'alpha') !== '') {
	// Sanitize input values
	$doc = GETPOST('doc', 'alphanohtml');
	$serie = GETPOST('serie', 'alphanohtml');
	$numero = GETPOST('numero', 'alphanohtml');
	$data = GETPOST('data', 'alphanohtml');
	$store = $conf->global->ZS_API_STORE; // Ensure the store is retrieved from the global configuration

	$invoiceIdentifier = "$doc $serie $numero ($data)";
	dol_syslog("Starting invoice import process for: $invoiceIdentifier, store=$store", LOG_INFO);

	try {
		$startTime = microtime(true);

		$invoiceSync = new ZSInvoiceSynch($db);

		// Fetch the invoice
		$zsInvoice = $invoiceSync->fetchInvoiceLines($doc, $serie, $numero, $data, $store);

		// Check if fetched data is valid
		if (!$zsInvoice || !is_array($zsInvoice)) {
			throw new Exception($langs->trans("DoliZSynchErrorInvoiceNotFound", $doc, $serie, $numero));
		}

		if (!empty($zsInvoice['data']->document)) {
			// Save the invoice header to the dolizsynch_zsinvoicesynch table
			if (!$invoiceSync->saveInvoiceHeader($zsInvoice['data']->document)) {
				throw new Exception($langs->trans("ErrorSavingInvoiceHeader") . ": " . ($invoiceSync->error ?? 'Unknown error'));
			}
		}


		if (empty($zsInvoice)) {
			throw new Exception($langs->trans("DoliZSynchErrorInvoiceNotFound", $doc, $serie, $numero));
		}

		if ($doc === 'RC') {
			// Register the RC Document
			if ($invoiceSync->payAllInvoicesFromRC($zsInvoice)) {
			} else {
				throw new Exception($langs->trans("ErrorRegisterRCDocument") . ": " . ($invoiceSync->error ?? 'Unknown error'));
			}
		} else {
			$dataObj = new DateTime($data);
			$dataFormatted = $dataObj->format('Ymd');

			$invoiceRef = "{$doc}_{$serie}_{$numero}_{$dataFormatted}";

			// Check if invoice has already been imported and synchronized
			if ($invoiceSync->isInvoiceImported($invoiceRef)) {
				throw new Exception($langs->trans("DoliZSynchErrorInvoicAlreadyImported", $doc, $serie, $numero));
			}

			// Process the fetched invoice details
			if (isset($zsInvoice['data']->document) && is_object($zsInvoice['data']->document)) {
				$dolibarrInvoice = $invoiceSync->mapZSToDolibarrInvoice($zsInvoice['data']->document);
			} else {
				throw new Exception($langs->trans("DoliZSynchErrorInvalidInvoiceFormat"));
			}

			if (!$dolibarrInvoice) {
				throw new Exception($langs->trans("ErrorMappingInvoice") . ": " . ($invoiceSync->error ?? 'Unknown error'));
			}

			// Save or update the invoice in Dolibarr
			$dolibarrInvoiceId = $invoiceSync->createOrUpdateDolibarrInvoice($dolibarrInvoice);
			if ($dolibarrInvoiceId < 0) {
				throw new Exception($langs->trans("ErrorSavingInvoice") . ": " . ($invoiceSync->error ?? 'Unknown error'));
			}


			// Check if the invoice is paid
			if (isset($zsInvoice['data']->document->pago) && $zsInvoice['data']->document->pago == 1) {
				// Apply payment to the created invoice
				$paymentApplied = $invoiceSync->applyPaymentToDolibarrInvoice($dolibarrInvoiceId, $zsInvoice['data']);
				if (!$paymentApplied) {
					throw new Exception($langs->trans("ErrorApplyingPayment"));
				}
			} elseif (isset($zsInvoice['data']->document->pago) && $zsInvoice['data']->document->pago == 0 && $doc !== "FA" && $doc !== "RC") {
				// Set the invoice as paid
				$invoice = new Facture($db);
				if ($invoice->fetch($dolibarrInvoiceId) <= 0) {
					throw new Exception($langs->trans("ErrorFetchingInvoice") . ": " . ($invoice->error ?? 'Unknown error'));
				}

				if ($invoice->setPaid($user) < 0) {
					throw new Exception($langs->trans("ErrorSettingInvoicePaid") . ": " . ($invoice->error ?? 'Unknown error'));
				}
			}
		}

		$endTime = microtime(true);
		$executionTime = round($endTime - $startTime, 2);

		// Success message
		$message = $langs->trans("SingleInvoiceSynchronizationSuccess") . " ($invoiceIdentifier)";
		$message .= " (" . $langs->trans("ExecutionTime") . ": {$executionTime}s)";
		setEventMessages($message, null, 'mesgs');
		dol_syslog("Single invoice synchronization completed successfully: $invoiceIdentifier in {$executionTime}s", LOG_INFO);
	} catch (Exception $e) {
		$errorMsg = $langs->trans("SingleInvoiceSynchronizationError") . " ($invoiceIdentifier): " . $e->getMessage();
		setEventMessages($errorMsg, null, 'errors');
		dol_syslog("Single invoice synchronization failed: $invoiceIdentifier - " . $e->getMessage(), LOG_ERR);
	}

	// Redirect to prevent form resubmission
	header("Location: " . $_SERVER['PHP_SELF'] . "?mainmenu=home&leftmenu=setup");
	exit;
}

// Force Synch Invoice by Date Range
$initialDate = '';
$endDate = '';
if (GETPOST('forceSynchInvoiceByDateRange', 'alpha') !== '') {
	$initialDate = GETPOST('initial_date', 'alphanohtml');
	$endDate = GETPOST('end_date', 'alphanohtml');

	dol_syslog("Starting invoice synchronization by date range: from $initialDate to $endDate", LOG_INFO);

	try {
		$invoiceSync = new ZSInvoiceSynch($db);
		$startTime = microtime(true);
		$result = $invoiceSync->fetchInvoiceHeadersWithinDateRange($initialDate, $endDate, null);
		$endTime = microtime(true);
		$executionTime = round($endTime - $startTime, 2);

		if ($result) {
			$count = is_array($result) ? count($result) : 1;
			$message = $langs->trans("InvoiceDateRangeSynchronizationSuccess") . " ($initialDate - $endDate)";
			$message .= " (" . $langs->trans("ProcessedCount") . ": {$count}, " . $langs->trans("ExecutionTime") . ": {$executionTime}s)";
			setEventMessages($message, null, 'mesgs');
			dol_syslog("Invoice date range synchronization completed successfully: {$count} invoices processed in {$executionTime}s", LOG_INFO);
		} else {
			$errorMsg = $langs->trans("InvoiceDateRangeSynchronizationError") . " ($initialDate - $endDate)";
			if (!empty($invoiceSync->error)) {
				$errorMsg .= ": " . $invoiceSync->error;
			}
			setEventMessages($errorMsg, null, 'errors');
			dol_syslog("Invoice date range synchronization failed: " . ($invoiceSync->error ?? 'Unknown error'), LOG_ERR);
		}
	} catch (Exception $e) {
		setEventMessages($langs->trans("InvoiceDateRangeSynchronizationException") . ": " . $e->getMessage(), null, 'errors');
		dol_syslog("Invoice date range synchronization exception: " . $e->getMessage(), LOG_ERR);
	}

	// Redirect to prevent form resubmission
	header("Location: " . $_SERVER['PHP_SELF'] . "?mainmenu=home&leftmenu=setup");
	exit;
}

// Handle form submission for saving the default bank account
if ($_SERVER['REQUEST_METHOD'] == 'POST' && $action == 'update') {
	$selectedBankAccount = GETPOST('ZS_DEFAULT_BANK_ACCOUNT', 'int');
	if ($selectedBankAccount) {
		dolibarr_set_const($db, 'ZS_DEFAULT_BANK_ACCOUNT', $selectedBankAccount, 'chaine', 0, '', $conf->entity);
	}
}

// Fetch Time-Registers by Date Range
$initialDate = '';
$endDate     = '';
if (GETPOST('fetchTimeRegisters', 'alpha') !== '') {
	// Sanitize input values
	$initialDate = GETPOST('punch_initial_date', 'alphanohtml');
	$endDate     = GETPOST('punch_end_date', 'alphanohtml');

	dol_syslog("Starting time-register fetch: from $initialDate to $endDate", LOG_INFO);

	try {
		// Instantiate your punch-clock sync class
		$punchSync = new ZSPunchClock_Synch($db);
		$startTime = microtime(true);

		// Call the method
		$registers = $punchSync->storezspunchclockPeriod($initialDate, $endDate);

		$endTime = microtime(true);
		$executionTime = round($endTime - $startTime, 2);

		// Handle response with comprehensive feedback
		if ($registers === false) {
			$errorMsg = $langs->trans("TimeRegistersFetchError") . " ($initialDate - $endDate)";
			if (!empty($punchSync->error)) {
				$errorMsg .= ": " . $punchSync->error;
			}
			setEventMessages($errorMsg, null, 'errors');
			dol_syslog("Time-registers fetch failed: " . ($punchSync->error ?? 'Unknown error'), LOG_ERR);
		} elseif (empty($registers)) {
			$message = $langs->trans("NoTimeRegistersFound") . " ($initialDate - $endDate)";
			$message .= " (" . $langs->trans("ExecutionTime") . ": {$executionTime}s)";
			setEventMessages($message, null, 'warnings');
			dol_syslog("No time-registers found between $initialDate and $endDate, completed in {$executionTime}s", LOG_INFO);
		} else {
			$count = is_array($registers) ? count($registers) : 1;
			$message = $langs->trans("TimeRegistersFetchSuccess") . " ($initialDate - $endDate)";
			$message .= " (" . $langs->trans("ProcessedCount") . ": {$count}, " . $langs->trans("ExecutionTime") . ": {$executionTime}s)";
			setEventMessages($message, null, 'mesgs');
			dol_syslog("Time-registers fetch completed successfully: {$count} registers processed in {$executionTime}s", LOG_INFO);
		}
	} catch (Exception $e) {
		setEventMessages($langs->trans("TimeRegistersFetchException") . ": " . $e->getMessage(), null, 'errors');
		dol_syslog("Time-registers fetch exception: " . $e->getMessage(), LOG_ERR);
	}

	// Redirect back to avoid form resubmission
	header("Location: " . $_SERVER['PHP_SELF'] . "?mainmenu=home&leftmenu=setup");
	exit;
}

/*
 *
 * Actions for synchronization - Process POST requests BEFORE HTML output
 *
 */

// ALL SYNC ACTIONS HAVE BEEN MOVED TO admin/sync_tools.php
// Products, Units, Families, Categories, Payment Types, Invoices Headers, Invoices, Fix Canceled Invoices
// Please use Tools > DoliZSynch Synchronization Tools instead

// ZS products units to local table
if (GETPOST('synchUnits', 'alpha') !== '') {
	dol_syslog("Starting units synchronization from ZoneSoft", LOG_INFO);

	try {
		$synchDictionairs = new ZSDictionairSynch($db);
		$startTime = microtime(true);
		$result = $synchDictionairs->storezsunits();
		$endTime = microtime(true);
		$executionTime = round($endTime - $startTime, 2);

		if ($result) {
			$message = $langs->trans("UnitsSynchronizationSuccess") . " (" . $langs->trans("ExecutionTime") . ": {$executionTime}s)";
			setEventMessages($message, null, 'mesgs');
			dol_syslog("Units synchronization completed successfully in {$executionTime}s", LOG_INFO);
		} else {
			$errorMsg = $langs->trans("UnitsSynchronizationError");
			if (!empty($synchDictionairs->error)) {
				$errorMsg .= ": " . $synchDictionairs->error;
			}
			setEventMessages($errorMsg, null, 'errors');
			dol_syslog("Units synchronization failed: " . ($synchDictionairs->error ?? 'Unknown error'), LOG_ERR);
		}
	} catch (Exception $e) {
		setEventMessages($langs->trans("UnitsSynchronizationException") . ": " . $e->getMessage(), null, 'errors');
		dol_syslog("Units synchronization exception: " . $e->getMessage(), LOG_ERR);
	}

	// Redirect to prevent form resubmission
	header("Location: " . $_SERVER['PHP_SELF'] . "?mainmenu=home&leftmenu=setup");
	exit;
}

// ZS products prodcentre to local table
if (GETPOST('prodCentre', 'alpha') !== '') {
	dol_syslog("Starting production centres synchronization from ZoneSoft", LOG_INFO);

	try {
		$synchDictionairs = new ZSDictionairSynch($db);
		$startTime = microtime(true);
		$result = $synchDictionairs->storezsproductioncentres();
		$endTime = microtime(true);
		$executionTime = round($endTime - $startTime, 2);

		if ($result) {
			$message = $langs->trans("ProductionCentresSynchronizationSuccess") . " (" . $langs->trans("ExecutionTime") . ": {$executionTime}s)";
			setEventMessages($message, null, 'mesgs');
			dol_syslog("Production centres synchronization completed successfully in {$executionTime}s", LOG_INFO);
		} else {
			$errorMsg = $langs->trans("ProductionCentresSynchronizationError");
			if (!empty($synchDictionairs->error)) {
				$errorMsg .= ": " . $synchDictionairs->error;
			}
			setEventMessages($errorMsg, null, 'errors');
			dol_syslog("Production centres synchronization failed: " . ($synchDictionairs->error ?? 'Unknown error'), LOG_ERR);
		}
	} catch (Exception $e) {
		setEventMessages($langs->trans("ProductionCentresSynchronizationException") . ": " . $e->getMessage(), null, 'errors');
		dol_syslog("Production centres synchronization exception: " . $e->getMessage(), LOG_ERR);
	}

	// Redirect to prevent form resubmission
	header("Location: " . $_SERVER['PHP_SELF'] . "?mainmenu=home&leftmenu=setup");
	exit;
}

// ZS families to local table
if (GETPOST('synchFamilies', 'alpha') !== '') {
	dol_syslog("Starting families synchronization from ZoneSoft", LOG_INFO);

	try {
		$synchDictionairs = new ZSDictionairSynch($db);
		$startTime = microtime(true);
		$result = $synchDictionairs->storezsfamilies();
		$endTime = microtime(true);
		$executionTime = round($endTime - $startTime, 2);

		if ($result) {
			$message = $langs->trans("FamiliesSynchronizationSuccess") . " (" . $langs->trans("ExecutionTime") . ": {$executionTime}s)";
			setEventMessages($message, null, 'mesgs');
			dol_syslog("Families synchronization completed successfully in {$executionTime}s", LOG_INFO);
		} else {
			$errorMsg = $langs->trans("FamiliesSynchronizationError");
			if (!empty($synchDictionairs->error)) {
				$errorMsg .= ": " . $synchDictionairs->error;
			}
			setEventMessages($errorMsg, null, 'errors');
			dol_syslog("Families synchronization failed: " . ($synchDictionairs->error ?? 'Unknown error'), LOG_ERR);
		}
	} catch (Exception $e) {
		setEventMessages($langs->trans("FamiliesSynchronizationException") . ": " . $e->getMessage(), null, 'errors');
		dol_syslog("Families synchronization exception: " . $e->getMessage(), LOG_ERR);
	}

	// Redirect to prevent form resubmission
	header("Location: " . $_SERVER['PHP_SELF'] . "?mainmenu=home&leftmenu=setup");
	exit;
}

// ZS categories to local table
if (GETPOST('synchCategories', 'alpha') !== '') {
	dol_syslog("Starting categories synchronization from ZoneSoft", LOG_INFO);

	try {
		$synchDictionairs = new ZSDictionairSynch($db);
		$startTime = microtime(true);
		$result = $synchDictionairs->storezscategories();
		$endTime = microtime(true);
		$executionTime = round($endTime - $startTime, 2);

		if ($result) {
			$message = $langs->trans("CategoriesSynchronizationSuccess") . " (" . $langs->trans("ExecutionTime") . ": {$executionTime}s)";
			setEventMessages($message, null, 'mesgs');
			dol_syslog("Categories synchronization completed successfully in {$executionTime}s", LOG_INFO);
		} else {
			$errorMsg = $langs->trans("CategoriesSynchronizationError");
			if (!empty($synchDictionairs->error)) {
				$errorMsg .= ": " . $synchDictionairs->error;
			}
			setEventMessages($errorMsg, null, 'errors');
			dol_syslog("Categories synchronization failed: " . ($synchDictionairs->error ?? 'Unknown error'), LOG_ERR);
		}
	} catch (Exception $e) {
		setEventMessages($langs->trans("CategoriesSynchronizationException") . ": " . $e->getMessage(), null, 'errors');
		dol_syslog("Categories synchronization exception: " . $e->getMessage(), LOG_ERR);
	}

	// Redirect to prevent form resubmission
	header("Location: " . $_SERVER['PHP_SELF'] . "?mainmenu=home&leftmenu=setup");
	exit;
}

// ZS payment types to local table
if (GETPOST('synchPaimentTypes', 'alpha') !== '') {
	dol_syslog("Starting payment types synchronization from ZoneSoft", LOG_INFO);

	try {
		$synchDictionairs = new ZSDictionairSynch($db);
		$startTime = microtime(true);
		$result = $synchDictionairs->storezspaymenttypes();
		$endTime = microtime(true);
		$executionTime = round($endTime - $startTime, 2);

		if ($result) {
			$message = $langs->trans("PaymentTypesSynchronizationSuccess") . " (" . $langs->trans("ExecutionTime") . ": {$executionTime}s)";
			setEventMessages($message, null, 'mesgs');
			dol_syslog("Payment types synchronization completed successfully in {$executionTime}s", LOG_INFO);
		} else {
			$errorMsg = $langs->trans("PaymentTypesSynchronizationError");
			if (!empty($synchDictionairs->error)) {
				$errorMsg .= ": " . $synchDictionairs->error;
			}
			setEventMessages($errorMsg, null, 'errors');
			dol_syslog("Payment types synchronization failed: " . ($synchDictionairs->error ?? 'Unknown error'), LOG_ERR);
		}
	} catch (Exception $e) {
		setEventMessages($langs->trans("PaymentTypesSynchronizationException") . ": " . $e->getMessage(), null, 'errors');
		dol_syslog("Payment types synchronization exception: " . $e->getMessage(), LOG_ERR);
	}

	// Redirect to prevent form resubmission
	header("Location: " . $_SERVER['PHP_SELF'] . "?mainmenu=home&leftmenu=setup");
	exit;
}

// ZS Force synch invoices headers
if (GETPOST('synchInvoicesHeaders', 'alpha') !== '') {
	dol_syslog("Starting invoice headers synchronization from ZoneSoft", LOG_INFO);

	try {
		$zoneSoftInvoices = new ZSInvoiceSynch($db);
		$startTime = microtime(true);
		$invoiceHeaders = $zoneSoftInvoices->fetchInvoiceHeaders(null, 0);
		$endTime = microtime(true);
		$executionTime = round($endTime - $startTime, 2);

		if ($invoiceHeaders) {
			$result = $zoneSoftInvoices->saveInvoiceHeaders($invoiceHeaders);
			if ($result) {
				$count = is_array($invoiceHeaders) ? count($invoiceHeaders) : 1;
				$message = $langs->trans("InvoiceHeadersSynchronizationSuccess") . " (" . $langs->trans("ProcessedCount") . ": {$count}, " . $langs->trans("ExecutionTime") . ": {$executionTime}s)";
				setEventMessages($message, null, 'mesgs');
				dol_syslog("Invoice headers synchronization completed successfully: {$count} headers processed in {$executionTime}s", LOG_INFO);
			} else {
				$errorMsg = $langs->trans("InvoiceHeadersSynchronizationSaveError");
				if (!empty($zoneSoftInvoices->error)) {
					$errorMsg .= ": " . $zoneSoftInvoices->error;
				}
				setEventMessages($errorMsg, null, 'errors');
				dol_syslog("Invoice headers synchronization save failed: " . ($zoneSoftInvoices->error ?? 'Unknown error'), LOG_ERR);
			}
		} else {
			$errorMsg = $langs->trans("InvoiceHeadersSynchronizationFetchError");
			if (!empty($zoneSoftInvoices->error)) {
				$errorMsg .= ": " . $zoneSoftInvoices->error;
			}
			setEventMessages($errorMsg, null, 'errors');
			dol_syslog("Invoice headers synchronization failed to fetch: " . ($zoneSoftInvoices->error ?? 'Unknown error'), LOG_ERR);
		}
	} catch (Exception $e) {
		setEventMessages($langs->trans("InvoiceHeadersSynchronizationException") . ": " . $e->getMessage(), null, 'errors');
		dol_syslog("Invoice headers synchronization exception: " . $e->getMessage(), LOG_ERR);
	}

	// Redirect to prevent form resubmission
	header("Location: " . $_SERVER['PHP_SELF'] . "?mainmenu=home&leftmenu=setup");
	exit;
}

// ZS Force synch invoices
if (GETPOST('synchInvoices', 'alpha') !== '') {
	dol_syslog("Starting invoices synchronization from ZoneSoft", LOG_INFO);

	try {
		$zoneSoftInvoices = new ZSInvoiceSynch($db);
		$startTime = microtime(true);
		$result = $zoneSoftInvoices->synchronizeAllUnsynchronizedInvoicesByStore();
		$endTime = microtime(true);
		$executionTime = round($endTime - $startTime, 2);

		dol_syslog("Invoices synchronization completed in {$executionTime}s", LOG_INFO);

		// Messages are already set in the synchronizeAllUnsynchronizedInvoicesByStore() method
	} catch (Exception $e) {
		setEventMessages($langs->trans("InvoicesSynchronizationException") . ": " . $e->getMessage(), null, 'errors', 1);
		dol_syslog("Invoices synchronization exception: " . $e->getMessage(), LOG_ERR);
	}

	// Redirect to prevent form resubmission
	header("Location: " . $_SERVER['PHP_SELF'] . "?mainmenu=home&leftmenu=setup");
	exit;
}

// Fix canceled invoices between dates
if (GETPOST('fixCanceledInvoices', 'alpha') !== '') {
	$dateStart = GETPOST('fix_date_start', 'alpha');
	$dateEnd = GETPOST('fix_date_end', 'alpha');

	if (empty($dateStart) || empty($dateEnd)) {
		setEventMessages($langs->trans("PleaseSelectBothDates"), null, 'errors', 1);
	} else {
		dol_syslog("Starting fix for canceled invoices between $dateStart and $dateEnd", LOG_INFO);

		try {
			$zoneSoftInvoices = new ZSInvoiceSynch($db);
			$startTime = microtime(true);
			$results = $zoneSoftInvoices->fixCanceledInvoicesBetweenDates($dateStart, $dateEnd);
			$endTime = microtime(true);
			$executionTime = round($endTime - $startTime, 2);

			// Build result message
			$message = "Fixed canceled invoices in {$executionTime}s:<br>";
			$message .= "✓ Success: {$results['success']}<br>";
			if ($results['failed'] > 0) {
				$message .= "✗ Failed: {$results['failed']}<br>";
			}
			if ($results['skipped'] > 0) {
				$message .= "⊘ Skipped: {$results['skipped']}<br>";
			}

			if (!empty($results['errors'])) {
				$message .= "<br><strong>Errors:</strong><br>" . implode('<br>', $results['errors']);
				setEventMessages($message, null, 'warnings', 1);
			} else {
				setEventMessages($message, null, 'mesgs', 1);
			}

			$errorCount = !empty($results['errors']) && is_array($results['errors']) ? count($results['errors']) : 0;
			dol_syslog(
				"Fix canceled invoices completed: success=" . ((int) $results['success'])
				. " failed=" . ((int) $results['failed'])
				. " skipped=" . ((int) $results['skipped'])
				. " errors_count=" . $errorCount,
				LOG_INFO
			);
		} catch (Exception $e) {
			setEventMessages($langs->trans("FixCanceledInvoicesException") . ": " . $e->getMessage(), null, 'errors', 1);
			dol_syslog("Fix canceled invoices exception: " . $e->getMessage(), LOG_ERR);
		}
	}

	// Redirect to prevent form resubmission
	header("Location: " . $_SERVER['PHP_SELF'] . "?mainmenu=home&leftmenu=setup");
	exit;
}

/*
 *
 * View for sinchronization
 *
 */

$help_url = '';
$page_name = "DoliZSynchSetup";

// Add custom CSS for select2 multi-select full width
$moreCss = '<link rel="stylesheet" type="text/css" href="' . dol_buildpath('/kreaflow/css/dolizsynch.css', 1) . '">';

llxHeader($moreCss, $langs->trans($page_name), $help_url);

// Subheader
$linkback = '<a href="' . ($backtopage ? $backtopage : DOL_URL_ROOT . '/admin/modules.php?restore_lastsearch_values=1') . '">' . $langs->trans("BackToModuleList") . '</a>';

print load_fiche_titre($langs->trans($page_name), $linkback, 'title_setup');

// Configuration header
$head = dolizsynchAdminPrepareHead();
print dol_get_fiche_head($head, 'settings', $langs->trans($page_name), -1, "dolizsynch@kreaflow");

// Setup page goes here
echo '<span class="opacitymedium">' . $langs->trans("DoliZSynchSetupPage") . '</span><br><br>';


if ($action == 'edit') {
	print $formSetup->generateOutput(true);
	print '<br>';
} elseif (!empty($formSetup->items)) {
	print $formSetup->generateOutput();
	print '<div class="tabsAction">';
	print '<a class="butAction" href="' . $_SERVER["PHP_SELF"] . '?action=edit&token=' . newToken() . '">' . $langs->trans("Modify") . '</a>';
	print '</div>';
} else {
	print '<br>' . $langs->trans("NothingToSetup");
}

$moduledir = 'dolizsynch';
// Automatically scan module class directory for objects
$myTmpObjects = array();
$classDir = __DIR__ . '/../class';
foreach (glob($classDir . '/*.class.php') as $file) {
	$base = basename($file, '.class.php');
	if (preg_match('/^zs/i', $base)) {
		$myTmpObjects[$base] = array(
			'label' => ucwords(str_replace('_', ' ', $base)),
			'includerefgeneration' => 0,
			'includedocgeneration' => 0,
			'class' => ucfirst($base)
		);
	}
}

foreach ($myTmpObjects as $myTmpObjectKey => $myTmpObjectArray) {
	if ($myTmpObjectArray['includerefgeneration']) {
		/*
         * Orders Numbering model
         */
		$setupnotempty++;

		print load_fiche_titre($langs->trans("NumberingModules", $myTmpObjectArray['label']), '', '');

		print '<table class="noborder centpercent">';
		print '<tr class="liste_titre">';
		print '<td>' . $langs->trans("Name") . '</td>';
		print '<td>' . $langs->trans("Description") . '</td>';
		print '<td class="nowrap">' . $langs->trans("Example") . '</td>';
		print '<td class="center" width="60">' . $langs->trans("Status") . '</td>';
		print '<td class="center" width="16">' . $langs->trans("ShortInfo") . '</td>';
		print '</tr>' . "\n";

		clearstatcache();

		foreach ($dirmodels as $reldir) {
			$dir = dol_buildpath($reldir . "core/modules/" . $moduledir);

			if (is_dir($dir)) {
				$handle = opendir($dir);
				if (is_resource($handle)) {
					while (($file = readdir($handle)) !== false) {
						if (strpos($file, 'mod_' . strtolower($myTmpObjectKey) . '_') === 0 && substr($file, dol_strlen($file) - 3, 3) == 'php') {
							$file = substr($file, 0, dol_strlen($file) - 4);

							require_once $dir . '/' . $file . '.php';

							$module = new $file($db);

							// Show modules according to features level
							if ($module->version == 'development' && getDolGlobalInt('MAIN_FEATURES_LEVEL') < 2) {
								continue;
							}
							if ($module->version == 'experimental' && getDolGlobalInt('MAIN_FEATURES_LEVEL') < 1) {
								continue;
							}

							if ($module->isEnabled()) {
								dol_include_once('/' . $moduledir . '/class/' . strtolower($myTmpObjectKey) . '.class.php');

								print '<tr class="oddeven"><td>' . $module->name . "</td><td>\n";
								print $module->info();
								print '</td>';

								// Show example of numbering model
								print '<td class="nowrap">';
								$tmp = $module->getExample();
								if (preg_match('/^Error/', $tmp)) {
									$langs->load("errors");
									print '<div class="error">' . $langs->trans($tmp) . '</div>';
								} elseif ($tmp == 'NotConfigured') {
									print $langs->trans($tmp);
								} else {
									print $tmp;
								}
								print '</td>' . "\n";

								print '<td class="center">';
								$constforvar = 'DOLIZSYNCH_' . strtoupper($myTmpObjectKey) . '_ADDON';
								if (getDolGlobalString($constforvar) == $file) {
									print img_picto($langs->trans("Activated"), 'switch_on');
								} else {
									print '<a href="' . $_SERVER["PHP_SELF"] . '?action=setmod&token=' . newToken() . '&object=' . strtolower($myTmpObjectKey) . '&value=' . urlencode($file) . '">';
									print img_picto($langs->trans("Disabled"), 'switch_off');
									print '</a>';
								}
								print '</td>';

								$nameofclass = $myTmpObjectArray['class'];
								$mytmpinstance = new $nameofclass($db);
								$mytmpinstance->initAsSpecimen();

								// Info
								$htmltooltip = '';
								$htmltooltip .= '' . $langs->trans("Version") . ': <b>' . $module->getVersion() . '</b><br>';

								$nextval = $module->getNextValue($mytmpinstance);
								if ("$nextval" != $langs->trans("NotAvailable")) {  // Keep " on nextval
									$htmltooltip .= '' . $langs->trans("NextValue") . ': ';
									if ($nextval) {
										if (preg_match('/^Error/', $nextval) || $nextval == 'NotConfigured') {
											$nextval = $langs->trans($nextval);
										}
										$htmltooltip .= $nextval . '<br>';
									} else {
										$htmltooltip .= $langs->trans($module->error) . '<br>';
									}
								}

								print '<td class="center">';
								print $form->textwithpicto('', $htmltooltip, 1, 0);
								print '</td>';

								print "</tr>\n";
							}
						}
					}
					closedir($handle);
				}
			}
		}
		print "</table><br>\n";
	}

	if ($myTmpObjectArray['includedocgeneration']) {
		/*
         * Document templates generators
         */
		$setupnotempty++;
		$type = strtolower($myTmpObjectKey);

		print load_fiche_titre($langs->trans("DocumentModules", $myTmpObjectKey), '', '');

		// Load array def with activated templates
		$def = array();
		$sql = "SELECT nom";
		$sql .= " FROM " . MAIN_DB_PREFIX . "document_model";
		$sql .= " WHERE type = '" . $db->escape($type) . "'";
		$sql .= " AND entity = " . $conf->entity;
		$resql = $db->query($sql);
		if ($resql) {
			$i = 0;
			$num_rows = $db->num_rows($resql);
			while ($i < $num_rows) {
				$array = $db->fetch_array($resql);
				array_push($def, $array[0]);
				$i++;
			}
		} else {
			dol_print_error($db);
		}

		print "<table class=\"noborder\" width=\"100%\">\n";
		print "<tr class=\"liste_titre\">\n";
		print '<td>' . $langs->trans("Name") . '</td>';
		print '<td>' . $langs->trans("Description") . '</td>';
		print '<td class="center" width="60">' . $langs->trans("Status") . "</td>\n";
		print '<td class="center" width="60">' . $langs->trans("Default") . "</td>\n";
		print '<td class="center" width="38">' . $langs->trans("ShortInfo") . '</td>';
		print '<td class="center" width="38">' . $langs->trans("Preview") . '</td>';
		print "</tr>\n";

		clearstatcache();

		foreach ($dirmodels as $reldir) {
			foreach (array('', '/doc') as $valdir) {
				$realpath = $reldir . "core/modules/" . $moduledir . $valdir;
				$dir = dol_buildpath($realpath);

				if (is_dir($dir)) {
					$handle = opendir($dir);
					if (is_resource($handle)) {
						while (($file = readdir($handle)) !== false) {
							$filelist[] = $file;
						}
						closedir($handle);
						arsort($filelist);

						foreach ($filelist as $file) {
							if (preg_match('/\.modules\.php$/i', $file) && preg_match('/^(pdf_|doc_)/', $file)) {
								if (file_exists($dir . '/' . $file)) {
									$name = substr($file, 4, dol_strlen($file) - 16);
									$classname = substr($file, 0, dol_strlen($file) - 12);

									require_once $dir . '/' . $file;
									$module = new $classname($db);

									$modulequalified = 1;
									if ($module->version == 'development' && getDolGlobalInt('MAIN_FEATURES_LEVEL') < 2) {
										$modulequalified = 0;
									}
									if ($module->version == 'experimental' && getDolGlobalInt('MAIN_FEATURES_LEVEL') < 1) {
										$modulequalified = 0;
									}

									if ($modulequalified) {
										print '<tr class="oddeven"><td width="100">';
										print(empty($module->name) ? $name : $module->name);
										print "</td><td>\n";
										if (method_exists($module, 'info')) {
											print $module->info($langs);
										} else {
											print $module->description;
										}
										print '</td>';

										// Active
										if (in_array($name, $def)) {
											print '<td class="center">' . "\n";
											print '<a href="' . $_SERVER["PHP_SELF"] . '?action=del&token=' . newToken() . '&value=' . urlencode($name) . '">';
											print img_picto($langs->trans("Enabled"), 'switch_on');
											print '</a>';
											print '</td>';
										} else {
											print '<td class="center">' . "\n";
											print '<a href="' . $_SERVER["PHP_SELF"] . '?action=set&token=' . newToken() . '&value=' . urlencode($name) . '&scan_dir=' . urlencode($module->scandir) . '&label=' . urlencode($module->name) . '">' . img_picto($langs->trans("Disabled"), 'switch_off') . '</a>';
											print "</td>";
										}

										// Default
										print '<td class="center">';
										$constforvar = 'DOLIZSYNCH_' . strtoupper($myTmpObjectKey) . '_ADDON_PDF';
										if (getDolGlobalString($constforvar) == $name) {
											//print img_picto($langs->trans("Default"), 'on');
											// Even if choice is the default value, we allow to disable it. Replace this with previous line if you need to disable unset
											print '<a href="' . $_SERVER["PHP_SELF"] . '?action=unsetdoc&token=' . newToken() . '&object=' . urlencode(strtolower($myTmpObjectKey)) . '&value=' . urlencode($name) . '&scan_dir=' . urlencode($module->scandir) . '&label=' . urlencode($module->name) . '&amp;type=' . urlencode($type) . '" alt="' . $langs->trans("Disable") . '">' . img_picto($langs->trans("Enabled"), 'on') . '</a>';
										} else {
											print '<a href="' . $_SERVER["PHP_SELF"] . '?action=setdoc&token=' . newToken() . '&object=' . urlencode(strtolower($myTmpObjectKey)) . '&value=' . urlencode($name) . '&scan_dir=' . urlencode($module->scandir) . '&label=' . urlencode($module->name) . '" alt="' . $langs->trans("Default") . '">' . img_picto($langs->trans("Disabled"), 'off') . '</a>';
										}
										print '</td>';

										// Info
										$htmltooltip = '' . $langs->trans("Name") . ': ' . $module->name;
										$htmltooltip .= '<br>' . $langs->trans("Type") . ': ' . ($module->type ? $module->type : $langs->trans("Unknown"));
										if ($module->type == 'pdf') {
											$htmltooltip .= '<br>' . $langs->trans("Width") . '/' . $langs->trans("Height") . ': ' . $module->page_largeur . '/' . $module->page_hauteur;
										}
										$htmltooltip .= '<br>' . $langs->trans("Path") . ': ' . preg_replace('/^\//', '', $realpath) . '/' . $file;

										$htmltooltip .= '<br><br><u>' . $langs->trans("FeaturesSupported") . ':</u>';
										$htmltooltip .= '<br>' . $langs->trans("Logo") . ': ' . yn($module->option_logo, 1, 1);
										$htmltooltip .= '<br>' . $langs->trans("MultiLanguage") . ': ' . yn($module->option_multilang, 1, 1);

										print '<td class="center">';
										print $form->textwithpicto('', $htmltooltip, 1, 0);
										print '</td>';

										// Preview
										print '<td class="center">';
										if ($module->type == 'pdf') {
											$newname = preg_replace('/_' . preg_quote(strtolower($myTmpObjectKey), '/') . '/', '', $name);
											print '<a href="' . $_SERVER["PHP_SELF"] . '?action=specimen&module=' . urlencode($newname) . '&object=' . urlencode($myTmpObjectKey) . '">' . img_object($langs->trans("Preview"), 'pdf') . '</a>';
										} else {
											print img_object($langs->trans("PreviewNotAvailable"), 'generic');
										}
										print '</td>';

										print "</tr>\n";
									}
								}
							}
						}
					}
				}
			}
		}

		print '</table>';
	}
}

if (empty($setupnotempty)) {
	print '<br>' . $langs->trans("NothingToSetup");
}

if (GETPOST('generate_cron', 'alpha') !== '') {
	// Handle the form submission for cron job configuration
	// [Insert the form handling PHP code here]
}

// ALL SYNC TOOLS HAVE BEEN MOVED TO admin/sync_tools.php
// Please use Tools > DoliZSynch Synchronization Tools to access:
// - Force sync specific document
// - Force sync invoice headers by date range
// - Force sync punch clock by date range
// - Force sync all invoices
// - Fix canceled invoices between dates
// - Dictionary sync (products, units, families, categories, payment types)

// Page end
print dol_get_fiche_end();

llxFooter();
$db->close();

?>

<!-- HTML for the loading GIF -->
<div id="loadingDiv" style="display:none; position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%);">
	<img src="../img/dolizsynch_synch.gif" alt="Loading..." style="width: 102px; height: 102px;" />
</div>
<!-- JavaScript to show/hide the loading GIF -->
<script type="text/javascript">
	function showLoadingGif() {
		document.getElementById('loadingDiv').style.display = 'block';
	}
</script>

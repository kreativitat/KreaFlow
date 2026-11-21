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
 * \file    dolizsynch/admin/mapping.php
 * \ingroup dolizsynch
 * \brief   Mapping of data from module DoliZSynch.
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

// Libraries
require_once DOL_DOCUMENT_ROOT . '/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT . '/core/lib/functions2.lib.php';
require_once '../lib/dolizsynch.lib.php';
require_once DOL_DOCUMENT_ROOT . '/core/class/html.form.class.php';
require_once DOL_DOCUMENT_ROOT . '/categories/class/categorie.class.php';
require_once DOL_DOCUMENT_ROOT . '/core/class/html.formcategory.class.php';
require_once DOL_DOCUMENT_ROOT . '/custom/kreaflow/class/zsdictionairsynch.class.php';

// Translations
$langs->loadLangs(array("errors", "admin", "dolizsynch@kreaflow"));

// Access control
if (!$user->admin) {
	accessforbidden();
}

// Parameters
$action = GETPOST('action', 'aZ09');
$backtopage = GETPOST('backtopage', 'alpha');

if (empty($conf->global->ZS_API_STORE)) {
	$message = $langs->trans("ZS_API_STORE_not_set");
	accessforbidden($message);
} else {
	$store = (int) $conf->global->ZS_API_STORE;
}
$storeproducts = $store;

/**
 * Simple CSRF guard for this page.
 */
function dolizsynch_check_token()
{
	if (GETPOST('token') !== $_SESSION['newtoken']) {
		accessforbidden('Wrong CSRF token');
	}
}

/**
 * Render one store/entity mapping row.
 *
 * @param stdClass $mapping       Row data
 * @param string   $rowId         Row identifier for DOM
 * @param int      $defaultEntity Default entity value for new rows
 * @return string
 */
function dolizsynch_render_storemap_row($mapping, $rowId, $defaultEntity)
{
	global $langs;

	$store = isset($mapping->store) ? $mapping->store : '';
	$zsbmsStore = isset($mapping->zsbms_store) ? $mapping->zsbms_store : '';
	$storeDolibarr = isset($mapping->store_dolibarr) ? (int) $mapping->store_dolibarr : '';
	$storeNif = isset($mapping->store_nif) ? $mapping->store_nif : '';
	$entity = isset($mapping->entity) ? (int) $mapping->entity : (int) $defaultEntity;
	$dbRowId = isset($mapping->rowid) ? (int) $mapping->rowid : 0;

	$html = '<div id="storemaprow_' . $rowId . '" class="mappingRow dz-map-row" data-rowid="' . $dbRowId . '">';
	$html .= '<table class="noborder" style="width: auto;">';
	$html .= '<tr>';

	$html .= '<td style="padding-right: 10px;"><input type="text" name="storemap_store[]" class="maxwidth100" value="' . dol_escape_htmltag($store) . '" placeholder="' . $langs->trans('Store') . '"></td>';
	$html .= '<td style="padding-right: 10px;"><input type="text" name="storemap_zsbms[]" class="maxwidth75" value="' . dol_escape_htmltag($zsbmsStore) . '" placeholder="' . $langs->trans('ZSBMSStore') . '"></td>';
	$html .= '<td style="padding-right: 10px;"><input type="number" name="storemap_store_dolibarr[]" class="maxwidth75" min="0" value="' . dol_escape_htmltag($storeDolibarr) . '" placeholder="' . $langs->trans('DolibarrEntity') . '"></td>';
	$html .= '<td style="padding-right: 10px;"><input type="text" name="storemap_store_nif[]" class="maxwidth150" value="' . dol_escape_htmltag($storeNif) . '" placeholder="' . $langs->trans('StoreNIF') . '"></td>';
	$html .= '<td style="padding-right: 10px;"><input type="number" name="storemap_entity[]" class="maxwidth50" min="0" value="' . dol_escape_htmltag($entity) . '" placeholder="' . $langs->trans('Entity') . '"></td>';
	$html .= '<td class="nowrap dz-map-row-actions">';
	$html .= '<input type="hidden" name="storemap_rowid[]" value="' . $dbRowId . '">';
	$html .= '<a class="button dz-map-row-add" onclick="addMappingRow(\'storemap\')">+</a>';
	if ($rowId !== '0') {
		$html .= '<a class="button dz-map-row-remove" onclick="removeMappingRow(\'' . $rowId . '\', \'storemap\')">-</a>';
	} else {
		$html .= '<span class="button dz-map-row-remove-placeholder" aria-hidden="true">-</span>';
	}
	$html .= '</td>';

	$html .= '</tr>';
	$html .= '</table>';
	$html .= '</div>';

	return $html;
}

/*
 * Actions
 */
if ($action && $_SERVER['REQUEST_METHOD'] === 'POST') {
	dolizsynch_check_token();

	$dictionair = new ZSDictionairSynch($db);
	$mappingType = GETPOST('mappingType', 'alpha');
	$rowId = GETPOST('rowId', 'alphanohtml');

	if ($action === 'addrow') {
		$typeMap = array('family', 'paymentType', 'bankAccount', 'unit', 'storemap');
		if ($mappingType === 'storemap') {
			print dolizsynch_render_storemap_row(new stdClass(), $rowId, $conf->entity);
		} elseif (in_array($mappingType, $typeMap, true)) {
			print $dictionair->getMappingRow(new stdClass(), $rowId, $mappingType);
		}
		exit;
	} elseif ($action === 'removerow') {
		// Logic to remove the row from the database
		if ($mappingType === 'family') {
			// Remove logic for family
		} elseif ($mappingType === 'paymentType') {
			// Remove logic for paymentType
		} elseif ($mappingType === 'bankAccount') {
			// Remove logic for bankAccount
		} elseif ($mappingType === 'unit') {
			// Remove logic for unit
		}
		exit;
	}
}

// Save form data
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === '') {
	dolizsynch_check_token();
	$dictionair = new ZSDictionairSynch($db);

	$errors = 0;
	$successCount = 0;

	dol_syslog('Starting mapping form save operation', LOG_INFO);

	// Remove Family Mappings
	$removalSpecs = array(
		'removedFamilyRows' => array(
			'mode' => 'delete',
			'table' => MAIN_DB_PREFIX . 'dolizsynch_zsfamily',
			'field' => 'rowid',
			'label' => 'family mapping',
			'where' => " AND entity IN (0, " . ((int) $conf->entity) . ") AND loja_zs = " . ((int) $storeproducts),
		),
		'removedPaymentTypeRows' => array(
			'mode' => 'delete',
			'table' => MAIN_DB_PREFIX . 'dolizsynch_zspaymenttype',
			'field' => 'rowid',
			'label' => 'payment type mapping',
			'where' => " AND entity IN (0, " . ((int) $conf->entity) . ") AND loja_zs = " . ((int) $store),
		),
		'removedBankAccountRows' => array(
			'mode' => 'update',
			'table' => MAIN_DB_PREFIX . 'dolizsynch_zspaymenttype',
			'field' => 'rowid',
			'label' => 'bank account mapping',
			'set' => 'fk_account = NULL',
			'where' => " AND entity IN (0, " . ((int) $conf->entity) . ") AND loja_zs = " . ((int) $store),
		),
		'removedUnitRows' => array(
			'mode' => 'delete',
			'table' => MAIN_DB_PREFIX . 'dolizsynch_zsunit',
			'field' => 'rowid',
			'label' => 'unit mapping',
			'where' => " AND entity IN (0, " . ((int) $conf->entity) . ") AND loja_zs = " . ((int) $store),
		),
		'removedStoreMapRows' => array(
			'mode' => 'delete',
			'table' => MAIN_DB_PREFIX . 'dolizsynch_storemap',
			'field' => 'rowid',
			'label' => 'store map',
			'where' => " AND entity IN (0, " . ((int) $conf->entity) . ")",
		),
	);
	foreach ($removalSpecs as $postKey => $spec) {
		$raw = trim((string) GETPOST($postKey, 'alphanohtml'));
		if ($raw === '') continue;
		$ids = array_filter(array_map('trim', explode(',', $raw)), 'strlen');
		foreach ($ids as $rowId) {
			if (!ctype_digit($rowId)) continue;
			$clause = !empty($spec['where']) ? $spec['where'] : '';
			$sql = ($spec['mode'] === 'delete')
				? "DELETE FROM " . $spec['table'] . " WHERE " . $spec['field'] . " = " . ((int) $rowId) . $clause
				: "UPDATE " . $spec['table'] . " SET " . $spec['set'] . " WHERE " . $spec['field'] . " = " . ((int) $rowId) . $clause;
			if (!$db->query($sql)) {
				$errors++;
				dol_syslog('Error removing ' . $spec['label'] . ' row ' . $rowId . ': ' . $db->lasterror(), LOG_ERR);
			} else {
				$successCount++;
				dol_syslog('Removed ' . $spec['label'] . ' row ' . $rowId, LOG_INFO);
			}
		}
	}

	$removedStoreMapIds = array();
	$rawRemovedStoreMap = trim((string) GETPOST('removedStoreMapRows', 'alphanohtml'));
	if ($rawRemovedStoreMap !== '') {
		foreach (array_filter(array_map('trim', explode(',', $rawRemovedStoreMap)), 'strlen') as $rowId) {
			if (ctype_digit($rowId)) {
				$removedStoreMapIds[] = (int) $rowId;
			}
		}
	}

	// Save Family Mappings
	if (is_array(GETPOST('zsFamily', 'array'))) {
		foreach ((array) GETPOST('zsFamily', 'array') as $index => $zsFamily) {
			$doliCat = (int) GETPOST('name_doli_dropdown_family', 'array')[$index];
			if ($doliCat <= 0) continue;
			$familyParts = explode('_', $zsFamily);
			if (count($familyParts) < 2) continue;
			$family_id = (int) $familyParts[0];
			$subfamily_id = (int) $familyParts[1];
			$sqlUpdate = "UPDATE " . MAIN_DB_PREFIX . "dolizsynch_zsfamily
			              SET fk_categorie = " . $doliCat . "
			              WHERE family_id = " . $family_id . "
			              AND subfamily_id = " . $subfamily_id . "
			              AND loja_zs = " . ((int) $storeproducts) . "
			              AND entity IN (0, " . ((int) $conf->entity) . ")";
			if (!$db->query($sqlUpdate)) {
				$errors++;
				dol_syslog('Error updating family mapping: ' . $db->lasterror(), LOG_ERR);
			} else {
				$successCount++;
			}
		}
	}

	// Save Payment Types Mappings
	if (is_array(GETPOST('zapiPayment', 'array'))) {
		foreach ((array) GETPOST('zapiPayment', 'array') as $index => $zsPaymentType) {
			$doliPayment = (int) GETPOST('name_doli_dropdown_paymenttype', 'array')[$index];
			$zsPaymentTypeId = (int) $zsPaymentType;
			if ($doliPayment > 0 && $zsPaymentTypeId > 0) {
				$sqlUpdate = "UPDATE " . MAIN_DB_PREFIX . "dolizsynch_zspaymenttype
				              SET fk_paiement = " . $doliPayment . "
				              WHERE paymenttype_id = " . $zsPaymentTypeId . "
				              AND loja_zs = " . ((int) $store) . "
				              AND entity IN (0, " . ((int) $conf->entity) . ")";
				if (!$db->query($sqlUpdate)) {
					$errors++;
					dol_syslog('Error updating payment type mapping: ' . $db->lasterror(), LOG_ERR);
				} else {
					$successCount++;
				}
			}
		}
	}

	// Save Bank Account Mappings
	if (is_array(GETPOST('zapiBankAccount', 'array'))) {
		foreach ((array) GETPOST('zapiBankAccount', 'array') as $index => $zsPaymentType) {
			$doliAccount = (int) GETPOST('name_doli_dropdown_bankaccount', 'array')[$index];
			$zsPaymentTypeId = (int) $zsPaymentType;
			if ($doliAccount > 0 && $zsPaymentTypeId > 0) {
				$sqlUpdate = "UPDATE " . MAIN_DB_PREFIX . "dolizsynch_zspaymenttype
				              SET fk_account = " . $doliAccount . "
				              WHERE paymenttype_id = " . $zsPaymentTypeId . "
				              AND loja_zs = " . ((int) $store) . "
				              AND entity IN (0, " . ((int) $conf->entity) . ")";
				if (!$db->query($sqlUpdate)) {
					$errors++;
					dol_syslog('Error updating bank account mapping: ' . $db->lasterror(), LOG_ERR);
				} else {
					$successCount++;
				}
			}
		}
	}

	// Save Unit Mappings
	if (is_array(GETPOST('zapiUnit', 'array'))) {
		foreach ((array) GETPOST('zapiUnit', 'array') as $index => $zsUnit) {
			$doliUnit = (int) GETPOST('name_doli_dropdown_unit', 'array')[$index];
			$zsUnitId = (int) $zsUnit;
			if ($doliUnit > 0 && $zsUnitId > 0) {
				$sqlUpdate = "UPDATE " . MAIN_DB_PREFIX . "dolizsynch_zsunit
				              SET fk_unit = " . $doliUnit . "
				              WHERE unit_id = " . $zsUnitId . "
				              AND loja_zs = " . ((int) $store) . "
				              AND entity IN (0, " . ((int) $conf->entity) . ")";
				if (!$db->query($sqlUpdate)) {
					$errors++;
					dol_syslog('Error updating unit mapping: ' . $db->lasterror(), LOG_ERR);
				} else {
					$successCount++;
				}
			}
		}
	}

	// Save store/entity mappings
	$storeValues = (array) GETPOST('storemap_store', 'array');
	$zsbmsValues = (array) GETPOST('storemap_zsbms', 'array');
	$storeDolValues = (array) GETPOST('storemap_store_dolibarr', 'array');
	$storeNifValues = (array) GETPOST('storemap_store_nif', 'array');
	$entityValues = (array) GETPOST('storemap_entity', 'array');
	$rowIdValues = (array) GETPOST('storemap_rowid', 'array');

	if (!empty($storeValues)) {
		foreach ($storeValues as $idx => $storeVal) {
			$dbRowId = isset($rowIdValues[$idx]) ? (int) $rowIdValues[$idx] : 0;
			if ($dbRowId > 0 && in_array($dbRowId, $removedStoreMapIds, true)) {
				// Already removed above, skip
				continue;
			}

			$storeVal = trim((string) $storeVal);
			$zsbmsVal = trim((string) ($zsbmsValues[$idx] ?? ''));
			$storeDol = (int) ($storeDolValues[$idx] ?? 0);
			$storeNif = trim((string) ($storeNifValues[$idx] ?? ''));
			$entityVal = (int) ($entityValues[$idx] ?? $conf->entity);

			if ($storeVal === '' && $zsbmsVal === '' && $storeDol === 0 && $storeNif === '' && $dbRowId === 0) {
				continue; // empty line
			}

			if ($storeVal === '' || $zsbmsVal === '' || $storeDol <= 0) {
				$errors++;
				dol_syslog('Store map line skipped due to missing required fields (store/zsbms/entity)', LOG_WARNING);
				continue;
			}

			if ($dbRowId > 0) {
				$sql = "UPDATE " . MAIN_DB_PREFIX . "dolizsynch_storemap SET";
				$sql .= " store = '" . $db->escape($storeVal) . "',";
				$sql .= " zsbms_store = '" . $db->escape($zsbmsVal) . "',";
				$sql .= " store_dolibarr = " . (int) $storeDol . ",";
				$sql .= " store_nif = " . ($storeNif !== '' ? "'" . $db->escape($storeNif) . "'" : "NULL") . ",";
				$sql .= " entity = " . (int) $entityVal;
				$sql .= " WHERE rowid = " . $dbRowId . " AND entity IN (0, " . ((int) $conf->entity) . ")";
			} else {
				$sql = "INSERT INTO " . MAIN_DB_PREFIX . "dolizsynch_storemap (store, zsbms_store, store_dolibarr, store_nif, entity, date_creation)";
				$sql .= " VALUES (";
				$sql .= "'" . $db->escape($storeVal) . "',";
				$sql .= "'" . $db->escape($zsbmsVal) . "',";
				$sql .= (int) $storeDol . ",";
				$sql .= ($storeNif !== '' ? "'" . $db->escape($storeNif) . "'" : "NULL") . ",";
				$sql .= (int) $entityVal . ",";
				$sql .= "'" . $db->idate(dol_now()) . "'";
				$sql .= ")";
			}

			if (!$db->query($sql)) {
				$errors++;
				dol_syslog('Error saving store map line: ' . $db->lasterror(), LOG_ERR);
			} else {
				$successCount++;
			}
		}
	}

	// Provide user feedback
	if ($errors > 0) {
		if ($successCount > 0) {
			$message = $langs->trans("MappingPartialSuccess", $successCount, $errors);
			setEventMessages($message, null, 'warnings');
		} else {
			$message = $langs->trans("MappingUpdateError", $errors);
			setEventMessages($message, null, 'errors');
		}
	} elseif ($successCount > 0) {
		$message = $langs->trans("MappingUpdateSuccess", $successCount);
		setEventMessages($message, null, 'mesgs');
	} else {
		$message = $langs->trans("MappingNoChanges");
		setEventMessages($message, null, 'warnings');
	}

	dol_syslog('Mapping form save completed: ' . $successCount . ' successful operations, ' . $errors . ' errors', $errors > 0 ? LOG_ERR : LOG_INFO);

	header("Location: " . $_SERVER['PHP_SELF'] . "?scroll=" . GETPOST('scroll', 'int'));
	exit;
}

// View
$form = new Form($db);
$dictionair = new ZSDictionairSynch($db);

$help_url = '';
$page_name = "DoliZSynchMapping";

llxHeader('', $langs->trans($page_name), $help_url);

// Subheader
$linkback = '<a href="' . ($backtopage ? $backtopage : DOL_URL_ROOT . '/admin/modules.php?restore_lastsearch_values=1') . '">' . $langs->trans("BackToModuleList") . '</a>';

print load_fiche_titre($langs->trans($page_name), $linkback, 'title_setup');

// Configuration header
$head = dolizsynchAdminPrepareHead();
print dol_get_fiche_head($head, 'mapping', $langs->trans($page_name), 0, 'dolizsynch@kreaflow');

// Subtitle
echo '<span class="opacitymedium">' . $langs->trans("DoliZSynchMappingPage") . '</span><br><br>';

// Fetch existing mappings with proper error handling
try {
	$sqlFamily = "SELECT * FROM " . MAIN_DB_PREFIX . "dolizsynch_zsfamily WHERE fk_categorie IS NOT NULL AND fk_categorie != 0 AND loja_zs = " . ((int) $storeproducts) . " AND entity IN (0, " . ((int) $conf->entity) . ") ORDER BY family_name ASC";
	$existingFamilyMappings = $dictionair->fetchExistingMappings($sqlFamily);

	$sqlPaymentType = "SELECT * FROM " . MAIN_DB_PREFIX . "dolizsynch_zspaymenttype WHERE fk_paiement IS NOT NULL AND fk_paiement != 0 AND loja_zs = " . ((int) $store) . " AND entity IN (0, " . ((int) $conf->entity) . ")";
	$existingPaymentTypeMappings = $dictionair->fetchExistingMappings($sqlPaymentType);

	$sqlBankAccount = "SELECT * FROM " . MAIN_DB_PREFIX . "dolizsynch_zspaymenttype WHERE fk_account IS NOT NULL AND fk_account != 0 AND loja_zs = " . ((int) $store) . " AND entity IN (0, " . ((int) $conf->entity) . ")";
	$existingBankAccountMappings = $dictionair->fetchExistingMappings($sqlBankAccount);

	$sqlUnit = "SELECT * FROM " . MAIN_DB_PREFIX . "dolizsynch_zsunit WHERE fk_unit IS NOT NULL AND fk_unit != 0 AND loja_zs = " . ((int) $store) . " AND entity IN (0, " . ((int) $conf->entity) . ")";
	$existingUnitMappings = $dictionair->fetchExistingMappings($sqlUnit);

$sqlStoreMap = "SELECT rowid, store, zsbms_store, store_dolibarr, store_nif, entity FROM " . MAIN_DB_PREFIX . "dolizsynch_storemap";
$sqlStoreMap .= " WHERE entity IN (0, " . ((int) $conf->entity) . ")";
$sqlStoreMap .= " ORDER BY entity ASC, store ASC";
$existingStoreMaps = $dictionair->fetchExistingMappings($sqlStoreMap);
} catch (Exception $e) {
	dol_syslog('Error fetching existing mappings: ' . $e->getMessage(), LOG_ERR);
	setEventMessages($langs->trans('ErrorFetchingMappings') . ': ' . $e->getMessage(), null, 'errors');
	$existingFamilyMappings = [];
	$existingPaymentTypeMappings = [];
	$existingBankAccountMappings = [];
	$existingUnitMappings = [];
	$existingStoreMaps = [];
}
$interEntityEnabled = getDolGlobalString('DOLIZSYNCH_INTERENTITY_INVOICE_ENABLED');

$maxFamilyRowId = 0;
foreach ($existingFamilyMappings as $mapping) {
	$maxFamilyRowId = max($maxFamilyRowId, (int) $mapping->rowid);
}
$maxPaymentTypeRowId = 0;
foreach ($existingPaymentTypeMappings as $mapping) {
	$maxPaymentTypeRowId = max($maxPaymentTypeRowId, (int) $mapping->rowid);
}
$maxBankAccountRowId = 0;
foreach ($existingBankAccountMappings as $mapping) {
	$maxBankAccountRowId = max($maxBankAccountRowId, (int) $mapping->rowid);
}
$maxUnitRowId = 0;
foreach ($existingUnitMappings as $mapping) {
	$maxUnitRowId = max($maxUnitRowId, (int) $mapping->rowid);
}
$maxStoreMapRowId = 0;
foreach ($existingStoreMaps as $mapping) {
	$maxStoreMapRowId = max($maxStoreMapRowId, (int) $mapping->rowid);
}

$mappingSections = array(
	'family' => array(
		'title' => $langs->trans('TitleMapFamilies'),
		'formId' => 'familyMappingForm',
		'removedFieldId' => 'removedFamilyRows',
		'scrollFieldId' => 'scrollFamily',
		'rowsContainerId' => 'mappingFamilyRows',
		'mappings' => $existingFamilyMappings,
	),
	'paymentType' => array(
		'title' => $langs->trans('TitleMapPaymentTypes'),
		'formId' => 'paymentTypeMappingForm',
		'removedFieldId' => 'removedPaymentTypeRows',
		'scrollFieldId' => 'scrollPaymentType',
		'rowsContainerId' => 'mappingPaymentTypeRows',
		'mappings' => $existingPaymentTypeMappings,
	),
	'bankAccount' => array(
		'title' => $langs->trans('TitleMapBankAccounts'),
		'formId' => 'bankAccountMappingForm',
		'removedFieldId' => 'removedBankAccountRows',
		'scrollFieldId' => 'scrollBankAccount',
		'rowsContainerId' => 'mappingBankAccountRows',
		'mappings' => $existingBankAccountMappings,
	),
	'unit' => array(
		'title' => $langs->trans('TitleMapUnits'),
		'formId' => 'unitMappingForm',
		'removedFieldId' => 'removedUnitRows',
		'scrollFieldId' => 'scrollUnit',
		'rowsContainerId' => 'mappingUnitRows',
		'mappings' => $existingUnitMappings,
	),
);

print '<style type="text/css">
.dz-map-toolbar { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 10px; }
.dz-map-legend { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; margin-bottom: 10px; font-size: 12px; font-weight: 600; color: #4f5b66; }
.dz-map-grid { display: grid; grid-template-columns: 1fr; gap: 14px; }
.dz-map-card { border: 1px solid #d9e2ec; border-radius: 12px; background: linear-gradient(180deg, #f8fafc 0%, #ffffff 100%); box-shadow: 0 2px 6px rgba(8, 32, 58, 0.06); padding: 12px; }
.dz-map-card-head { display: flex; justify-content: space-between; align-items: center; gap: 8px; margin-bottom: 10px; }
.dz-map-card-title { display: inline-flex; align-items: center; gap: 8px; font-weight: 600; color: #22303c; }
.dz-map-count { display: inline-flex; align-items: center; justify-content: center; min-width: 28px; height: 20px; padding: 0 6px; border-radius: 999px; background: #e7f0ff; color: #004d92; font-size: 11px; }
.dz-map-card-tools { display: inline-flex; align-items: center; gap: 8px; }
.dz-map-filter { width: 170px; }
.dz-map-card-body { margin-top: 4px; }
.dz-map-card-footer { margin-top: 10px; }
.dz-map-rows .mappingRow { margin-bottom: 8px; }
.dz-map-row table { width: 100% !important; }
.dz-map-row select { width: 100% !important; max-width: 100%; }
.dz-map-row input[type="text"], .dz-map-row input[type="number"] { width: 100%; box-sizing: border-box; }
.dz-map-row td.nowrap { white-space: nowrap; width: 1%; }
.dz-map-row td.dz-map-row-actions { width: 142px; min-width: 142px; white-space: nowrap; text-align: right; }
.dz-map-row td.dz-map-row-actions .button { min-width: 58px; text-align: center; margin-left: 6px; }
.dz-map-row td.dz-map-row-actions .button:first-child { margin-left: 0; }
.dz-map-row .button { min-width: 26px; text-align: center; }
.dz-map-row .dz-map-row-remove-placeholder { visibility: hidden; pointer-events: none; }
@media (max-width: 1100px) {
	.dz-map-row table tr { display: flex; flex-wrap: wrap; gap: 8px; }
	.dz-map-row table td { display: block; flex: 1 1 220px; padding-right: 0 !important; }
	.dz-map-row table td.nowrap { flex: 0 0 auto; display: inline-flex; align-items: center; gap: 6px; }
	.dz-map-row table td.dz-map-row-actions { width: auto; min-width: 0; text-align: left; }
	.dz-map-row table td.dz-map-row-actions .button { margin-left: 0; }
}
</style>';

print '<div class="dz-map-toolbar">';
foreach ($mappingSections as $type => $section) {
	print '<a class="button button-small" href="#dz-map-section-' . $type . '">' . dol_escape_htmltag($section['title']) . '</a>';
}
if ($interEntityEnabled) {
	print '<a class="button button-small" href="#dz-map-section-storemap">' . $langs->trans('TitleMapStoreEntity') . '</a>';
}
print '</div>';

print '<div class="dz-map-legend">';
print '<div>' . $langs->trans("MappingsOriginZoneSoft") . '</div>';
print '<div>' . $langs->trans("MappingsDestinDolibarr") . '</div>';
print '</div>';

print '<div class="dz-map-grid">';
foreach ($mappingSections as $type => $section) {
	$bodyId = 'dzMapBody_' . $type;
	print '<a id="dz-map-section-' . $type . '" name="dz-map-section-' . $type . '"></a>';
	print '<form method="POST" action="' . $_SERVER['PHP_SELF'] . '" id="' . $section['formId'] . '" class="dz-map-form dz-map-card" data-mapping-type="' . $type . '" data-scroll-id="' . $section['scrollFieldId'] . '">';
	print '<input type="hidden" name="token" value="' . $_SESSION['newtoken'] . '">';
	print '<input type="hidden" name="' . $section['removedFieldId'] . '" id="' . $section['removedFieldId'] . '" value="">';
	print '<input type="hidden" name="scroll" id="' . $section['scrollFieldId'] . '" value="">';
	print '<div class="dz-map-card-head">';
	print '<div class="dz-map-card-title">' . dol_escape_htmltag($section['title']) . '<span id="dzCount-' . $type . '" class="dz-map-count">0</span></div>';
	print '<div class="dz-map-card-tools">';
	print '<input type="text" class="flat dz-map-filter" data-mapping-type="' . $type . '" placeholder="' . dol_escape_htmltag($langs->trans("Search")) . '">';
	print '<button type="button" class="button button-small dz-map-toggle" data-target="' . $bodyId . '" aria-expanded="1">-</button>';
	print '</div>';
	print '</div>';
	print '<div id="' . $bodyId . '" class="dz-map-card-body">';
	print '<div id="' . $section['rowsContainerId'] . '" class="dz-map-rows">';
	if (count($section['mappings']) > 0) {
		$firstRow = true;
		foreach ($section['mappings'] as $mapping) {
			$rowId = $firstRow ? '0' : (int) $mapping->rowid;
			print $dictionair->getMappingRow($mapping, $rowId, $type);
			$firstRow = false;
		}
	} else {
		print $dictionair->getMappingRow(new stdClass(), 0, $type);
	}
	print '</div>';
	print '<div class="dz-map-card-footer">';
	print '<input type="submit" class="button button-save" value="' . $langs->trans("Save") . '" onclick="saveScrollPosition(\'' . $section['scrollFieldId'] . '\')">';
	print '</div>';
	print '</div>';
	print '</form>';
}

if ($interEntityEnabled) {
	$storeMapBodyId = 'dzMapBody_storemap';
	print '<a id="storemap" name="storemap"></a>';
	print '<a id="dz-map-section-storemap" name="dz-map-section-storemap"></a>';
	print '<form method="POST" action="' . $_SERVER['PHP_SELF'] . '" id="storeMapForm" class="dz-map-form dz-map-card" data-mapping-type="storemap" data-scroll-id="scrollStoreMap">';
	print '<input type="hidden" name="token" value="' . $_SESSION['newtoken'] . '">';
	print '<input type="hidden" name="removedStoreMapRows" id="removedStoreMapRows" value="">';
	print '<input type="hidden" name="scroll" id="scrollStoreMap" value="">';
	print '<div class="dz-map-card-head">';
	print '<div class="dz-map-card-title">' . $langs->trans('TitleMapStoreEntity') . '<span id="dzCount-storemap" class="dz-map-count">0</span></div>';
	print '<div class="dz-map-card-tools">';
	print '<input type="text" class="flat dz-map-filter" data-mapping-type="storemap" placeholder="' . dol_escape_htmltag($langs->trans("Search")) . '">';
	print '<button type="button" class="button button-small dz-map-toggle" data-target="' . $storeMapBodyId . '" aria-expanded="1">-</button>';
	print '</div>';
	print '</div>';
	print '<div id="' . $storeMapBodyId . '" class="dz-map-card-body">';
	print '<table class="noborder" style="width: 100%; margin-top: 2px;">';
	print '<tr class="liste_titre">';
	print '<th style="padding-right: 10px;">' . $langs->trans('Store') . '</th>';
	print '<th style="padding-right: 10px;">' . $langs->trans('ZSBMSStore') . '</th>';
	print '<th style="padding-right: 10px;">' . $langs->trans('DolibarrEntity') . '</th>';
	print '<th style="padding-right: 10px;">' . $langs->trans('StoreNIF') . '</th>';
	print '<th style="padding-right: 10px;">' . $langs->trans('Entity') . '</th>';
	print '<th class="center" style="padding-right: 10px;">' . $langs->trans('Action') . '</th>';
	print '</tr>';
	print '</table>';
	print '<div id="storeMapRows" class="dz-map-rows">';
	if (count($existingStoreMaps) > 0) {
		$firstRow = true;
		foreach ($existingStoreMaps as $mapping) {
			$rowId = $firstRow ? '0' : (int) $mapping->rowid;
			print dolizsynch_render_storemap_row($mapping, $rowId, $conf->entity);
			$firstRow = false;
		}
	} else {
		print dolizsynch_render_storemap_row(new stdClass(), 0, $conf->entity);
	}
	print '</div>';
	print '<div class="dz-map-card-footer">';
	print '<input type="submit" class="button button-save" value="' . $langs->trans("Save") . '" onclick="saveScrollPosition(\'scrollStoreMap\')">';
	print '</div>';
	print '</div>';
	print '</form>';
}
print '</div>';

// Page end
print dol_get_fiche_end();
llxFooter();
$db->close();
?>

<script type="text/javascript">
	var mappingConfig = {
		family: {
			nextRowId: <?php echo (int) ($maxFamilyRowId + 1); ?>,
			containerId: 'mappingFamilyRows',
			removedFieldId: 'removedFamilyRows',
			rowPrefix: 'familymappingremove_'
		},
		paymentType: {
			nextRowId: <?php echo (int) ($maxPaymentTypeRowId + 1); ?>,
			containerId: 'mappingPaymentTypeRows',
			removedFieldId: 'removedPaymentTypeRows',
			rowPrefix: 'mappingPaymentRemove_'
		},
		bankAccount: {
			nextRowId: <?php echo (int) ($maxBankAccountRowId + 1); ?>,
			containerId: 'mappingBankAccountRows',
			removedFieldId: 'removedBankAccountRows',
			rowPrefix: 'mappingBankAccountRemove_'
		},
		unit: {
			nextRowId: <?php echo (int) ($maxUnitRowId + 1); ?>,
			containerId: 'mappingUnitRows',
			removedFieldId: 'removedUnitRows',
			rowPrefix: 'unitmappingremove_'
		}
		<?php if ($interEntityEnabled) { ?>,
		storemap: {
			nextRowId: <?php echo (int) ($maxStoreMapRowId + 1); ?>,
			containerId: 'storeMapRows',
			removedFieldId: 'removedStoreMapRows',
			rowPrefix: 'storemaprow_'
		}
		<?php } ?>
	};

	function getMappingSearchText(row) {
		var parts = [];
		var selectedOptions = row.querySelectorAll('select');
		for (var i = 0; i < selectedOptions.length; i++) {
			var select = selectedOptions[i];
			if (select.selectedIndex >= 0 && select.options[select.selectedIndex]) {
				parts.push(select.options[select.selectedIndex].text);
			}
		}
		var typedValues = row.querySelectorAll('input[type="text"], input[type="number"]');
		for (var j = 0; j < typedValues.length; j++) {
			parts.push(typedValues[j].value || '');
		}
		return parts.join(' ').toLowerCase();
	}

	function updateSectionCounter(mappingType) {
		var config = mappingConfig[mappingType];
		if (!config) return;

		var container = document.getElementById(config.containerId);
		if (!container) return;

		var rows = container.querySelectorAll('.mappingRow');
		var total = rows.length;
		var visible = 0;
		for (var i = 0; i < rows.length; i++) {
			if (rows[i].style.display !== 'none') {
				visible++;
			}
		}

		var badge = document.getElementById('dzCount-' + mappingType);
		if (badge) {
			badge.textContent = (visible === total) ? String(total) : (String(visible) + '/' + String(total));
		}
	}

	function filterSectionRows(mappingType) {
		var config = mappingConfig[mappingType];
		if (!config) return;

		var input = document.querySelector('.dz-map-filter[data-mapping-type="' + mappingType + '"]');
		var container = document.getElementById(config.containerId);
		if (!container) return;

		var filterValue = input ? input.value.toLowerCase().trim() : '';
		var rows = container.querySelectorAll('.mappingRow');

		for (var i = 0; i < rows.length; i++) {
			var haystack = getMappingSearchText(rows[i]);
			rows[i].style.display = (!filterValue || haystack.indexOf(filterValue) !== -1) ? '' : 'none';
		}

		updateSectionCounter(mappingType);
	}

	function addMappingRow(mappingType) {
		var config = mappingConfig[mappingType];
		if (!config) {
			alert('Unknown mapping type');
			return;
		}

		var currentRowId = config.nextRowId++;
		var xhr = new XMLHttpRequest();
		xhr.open('POST', '<?php echo $_SERVER['PHP_SELF']; ?>', true);
		xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
		xhr.onreadystatechange = function() {
			if (xhr.readyState !== 4) return;
			if (xhr.status === 200) {
				var container = document.getElementById(config.containerId);
				if (container) {
					container.insertAdjacentHTML('beforeend', xhr.responseText);
					filterSectionRows(mappingType);
				}
			} else {
				alert('An error occurred: ' + xhr.statusText);
			}
		};
		xhr.send('action=addrow&rowId=' + encodeURIComponent(currentRowId) + '&mappingType=' + encodeURIComponent(mappingType) + '&token=' + encodeURIComponent('<?php echo $_SESSION['newtoken']; ?>'));
	}

	function removeMappingRow(rowId, mappingType) {
		var config = mappingConfig[mappingType];
		if (!config) return;

		var row = document.getElementById(config.rowPrefix + rowId);
		if (!row) return;

		var removedRowsInput = document.getElementById(config.removedFieldId);
		var dbRowId = parseInt(row.getAttribute('data-rowid') || '0', 10);
		if (removedRowsInput && dbRowId > 0) {
			var current = removedRowsInput.value ? removedRowsInput.value.split(',') : [];
			var rowIdString = String(dbRowId);
			if (current.indexOf(rowIdString) === -1) {
				current.push(rowIdString);
				removedRowsInput.value = current.join(',');
			}
		}

		row.parentNode.removeChild(row);
		updateSectionCounter(mappingType);
	}

	function saveScrollPosition(hiddenFieldId) {
		var hiddenField = document.getElementById(hiddenFieldId);
		if (!hiddenField) return;
		hiddenField.value = window.pageYOffset || document.documentElement.scrollTop || 0;
	}

	function toggleMapSection(button) {
		var targetId = button.getAttribute('data-target');
		var target = document.getElementById(targetId);
		if (!target) return;

		var expanded = button.getAttribute('aria-expanded') === '1';
		target.style.display = expanded ? 'none' : '';
		button.setAttribute('aria-expanded', expanded ? '0' : '1');
		button.textContent = expanded ? '+' : '-';
	}

	document.addEventListener('DOMContentLoaded', function() {
		Object.keys(mappingConfig).forEach(function(mappingType) {
			updateSectionCounter(mappingType);
		});

		var filters = document.querySelectorAll('.dz-map-filter');
		for (var i = 0; i < filters.length; i++) {
			filters[i].addEventListener('input', function() {
				filterSectionRows(this.getAttribute('data-mapping-type'));
			});
		}

		var toggles = document.querySelectorAll('.dz-map-toggle');
		for (var j = 0; j < toggles.length; j++) {
			toggles[j].addEventListener('click', function() {
				toggleMapSection(this);
			});
		}

		var forms = document.querySelectorAll('.dz-map-form');
		for (var k = 0; k < forms.length; k++) {
			forms[k].addEventListener('submit', function() {
				var scrollFieldId = this.getAttribute('data-scroll-id');
				if (scrollFieldId) {
					saveScrollPosition(scrollFieldId);
				}
			});
		}

		var params = new URLSearchParams(window.location.search);
		var scrollParam = parseInt(params.get('scroll') || '0', 10);
		if (!isNaN(scrollParam) && scrollParam > 0) {
			window.scrollTo(0, scrollParam);
		}
	});
</script>

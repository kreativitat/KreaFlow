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
 * DoliZSynch product tab
 */

// Load Dolibarr environment
require '../../main.inc.php';

require_once DOL_DOCUMENT_ROOT . '/core/lib/product.lib.php';
require_once DOL_DOCUMENT_ROOT . '/core/class/html.form.class.php';
require_once DOL_DOCUMENT_ROOT . '/product/class/product.class.php';
require_once DOL_DOCUMENT_ROOT . '/custom/kreaflow/class/actions_dolizsynch.class.php';
require_once DOL_DOCUMENT_ROOT . '/custom/kreaflow/class/zsproductpropertyservice.class.php';
require_once DOL_DOCUMENT_ROOT . '/custom/kreaflow/class/zspropertystockservice.class.php';

$langs->loadLangs(array('products', 'dolizsynch@kreaflow'));

if (!function_exists('dolizsynch_menu_json_has_levels')) {
	/**
	 * Returns true when JSON is valid and contains at least one level.
	 *
	 * @param string|null $json Raw JSON from DB
	 * @return bool
	 */
	function dolizsynch_menu_json_has_levels($json)
	{
		if ($json === null) {
			return false;
		}

		$str = trim((string) $json);
		if ($str === '' || strtolower($str) === 'null') {
			return false;
		}

		$decoded = json_decode($str, true);
		if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
			return count($decoded) > 0;
		}

		// Defensive: sometimes data ends up HTML-escaped in DB (e.g. &quot;)
		$repaired = html_entity_decode($str, ENT_QUOTES | ENT_HTML5);
		$decoded2 = json_decode($repaired, true);
		if (json_last_error() === JSON_ERROR_NONE && is_array($decoded2)) {
			return count($decoded2) > 0;
		}

		return false;
	}
}

if (!function_exists('dolizsynch_pick_menu_from_sql')) {
	/**
	 * Runs a query that returns a "niveismenu" column and picks the newest non-empty menu JSON.
	 *
	 * @param DoliDB $db
	 * @param string $sql
	 * @return string Empty string when not found
	 */
	function dolizsynch_pick_menu_from_sql($db, $sql)
	{
		$resql = $db->query($sql);
		if (!$resql) {
			return '';
		}

		while ($obj = $db->fetch_object($resql)) {
			$candidate = isset($obj->niveismenu) ? (string) $obj->niveismenu : '';
			if (dolizsynch_menu_json_has_levels($candidate)) {
				// Return repaired JSON if it was HTML-escaped
				$repaired = html_entity_decode($candidate, ENT_QUOTES | ENT_HTML5);
				$decoded = json_decode($repaired, true);
				if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
					return $repaired;
				}
				return $candidate;
			}
		}

		return '';
	}
}

$id = GETPOST('id', 'int');
$action = GETPOST('action', 'aZ09');

if (empty($id)) {
	accessforbidden();
}

$object = new Product($db);
$result = $object->fetch($id);
if ($result <= 0) {
	accessforbidden();
}

// Authorize the parent before any module-specific read or write.
$allowedProductEntities = array_map('intval', explode(',', getEntity('product')));
if (!isModEnabled('dolizsynch') || !in_array((int) $object->entity, $allowedProductEntities, true)) {
    accessforbidden();
}
restrictedArea($user, (int) $object->type === 1 ? 'service' : 'produit', $object->id, 'product&product', '', '');
$canEditProduct = empty($user->socid) && $user->hasRight((int) $object->type === 1 ? 'service' : 'produit', 'creer');
if (in_array($action, array('edit', 'update'), true) && !$canEditProduct) {
    accessforbidden();
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedToken = (string) GETPOST('token', 'alphanohtml');
    if (!$canEditProduct || $postedToken === '' || (!hash_equals((string) ($_SESSION['token'] ?? ''), $postedToken)
            && !hash_equals((string) ($_SESSION['newtoken'] ?? ''), $postedToken))) {
        accessforbidden();
    }
}

// Current ZS store (per entity)
$storeZs = !empty($conf->global->ZS_API_STORE) ? (int) $conf->global->ZS_API_STORE : 0;
// Current "composed type" (composto) so we can show menu builder only for Menu products (value=2)
$compostoForPage = '4';
$sqlComposto = "SELECT composto, loja_zs FROM " . MAIN_DB_PREFIX . "dolizsynch_zsproduct";
$sqlComposto .= " WHERE fk_product = " . ((int) $object->id);
$sqlComposto .= " AND composto IS NOT NULL";
if ($storeZs > 0) {
	$sqlComposto .= " ORDER BY (loja_zs = " . ((int) $storeZs) . ") DESC, rowid DESC";
} else {
	$sqlComposto .= " ORDER BY rowid DESC";
}
$sqlComposto .= " " . $db->plimit(1);
$resComposto = $db->query($sqlComposto);
if ($resComposto && ($objComposto = $db->fetch_object($resComposto))) {
	$compostoForPage = (string) $objComposto->composto;
}
// Prefer posted value (so the UI matches user selection immediately after save/validation)
$postComposto = GETPOST('DOLIZSYNCH_ZS_COMPOSTO', 'alphanohtml');
if ($postComposto !== '') {
	$compostoForPage = (string) $postComposto;
}
$isMenuComposto = ((string) $compostoForPage === '2');

// Load current "balanca" (Introducao de quantidade) for this product
$balancaForPage = '';
$sqlBalanca = "SELECT balanca, loja_zs FROM " . MAIN_DB_PREFIX . "dolizsynch_zsproduct";
$sqlBalanca .= " WHERE fk_product = " . ((int) $object->id);
$sqlBalanca .= " AND balanca IS NOT NULL";
if ($storeZs > 0) {
	$sqlBalanca .= " ORDER BY (loja_zs = " . ((int) $storeZs) . ") DESC, rowid DESC";
} else {
	$sqlBalanca .= " ORDER BY rowid DESC";
}
$sqlBalanca .= " " . $db->plimit(1);
$resBalanca = $db->query($sqlBalanca);
if ($resBalanca && ($objBalanca = $db->fetch_object($resBalanca))) {
	$balancaForPage = (string) $objBalanca->balanca;
}
$postBalanca = GETPOST('DOLIZSYNCH_ZS_BALANCA', 'alphanohtml');
if ($postBalanca !== '') {
	$balancaForPage = (string) $postBalanca;
}

// Load stored menu (niveismenu) for this product
$menuJsonForField = '[]';
$menuDecodedForJs = array();
// We try to resolve the ZS codigo for this Dolibarr product so menus can be shared across entities.
$zsCodigoForMenu = '';
$sqlCodigo = "SELECT codigo FROM " . MAIN_DB_PREFIX . "dolizsynch_zsproduct";
$sqlCodigo .= " WHERE fk_product = " . ((int) $object->id);
$sqlCodigo .= " ORDER BY rowid DESC " . $db->plimit(1);
$resCodigo = $db->query($sqlCodigo);
if ($resCodigo && ($objCodigo = $db->fetch_object($resCodigo)) && !empty($objCodigo->codigo)) {
	$zsCodigoForMenu = (string) $objCodigo->codigo;
}
// Fallback: if product ref is numeric, it is usually the ZS codigo
if ($zsCodigoForMenu === '' && !empty($object->ref) && preg_match('/^[0-9]+$/', (string) $object->ref)) {
	$zsCodigoForMenu = (string) $object->ref;
}
// Last resort: find mapping by product ref across any entity (when products are duplicated across entities)
if ($zsCodigoForMenu === '' && !empty($object->ref)) {
	$ref = $db->escape((string) $object->ref);
	$sqlCodigo = "SELECT zp.codigo FROM " . MAIN_DB_PREFIX . "dolizsynch_zsproduct zp";
	$sqlCodigo .= " INNER JOIN " . MAIN_DB_PREFIX . "product p ON p.rowid = zp.fk_product";
	$sqlCodigo .= " WHERE p.ref = '" . $ref . "'";
	$sqlCodigo .= " AND zp.codigo IS NOT NULL AND zp.codigo <> ''";
	$sqlCodigo .= " ORDER BY zp.rowid DESC " . $db->plimit(1);
	$resCodigo = $db->query($sqlCodigo);
	if ($resCodigo && ($objCodigo = $db->fetch_object($resCodigo)) && !empty($objCodigo->codigo)) {
		$zsCodigoForMenu = (string) $objCodigo->codigo;
	}
}

// Prefer loading by ZS codigo (menus are shared across entities/stores)
if ($zsCodigoForMenu !== '') {
	$code = $db->escape($zsCodigoForMenu);
	$sqlMenu = "SELECT niveismenu FROM " . MAIN_DB_PREFIX . "dolizsynch_zsproduct";
	$sqlMenu .= " WHERE codigo = '" . $code . "'";
	$sqlMenu .= " AND niveismenu IS NOT NULL";
	// Prefer current store first, then newest
	if ($storeZs > 0) {
		$sqlMenu .= " ORDER BY (loja_zs = " . ((int) $storeZs) . ") DESC, rowid DESC";
	} else {
		$sqlMenu .= " ORDER BY rowid DESC";
	}
	$sqlMenu .= " " . $db->plimit(200);
	$picked = dolizsynch_pick_menu_from_sql($db, $sqlMenu);
	if ($picked !== '') {
		$menuJsonForField = $picked;
	}
}

// Fallback by fk_product (older records or when codigo missing)
if (($menuJsonForField === '' || $menuJsonForField === '[]') && (int) $object->id > 0) {
	$sqlMenu = "SELECT niveismenu FROM " . MAIN_DB_PREFIX . "dolizsynch_zsproduct";
	$sqlMenu .= " WHERE fk_product = " . ((int) $object->id);
	$sqlMenu .= " AND niveismenu IS NOT NULL";
	if ($storeZs > 0) {
		$sqlMenu .= " ORDER BY (loja_zs = " . ((int) $storeZs) . ") DESC, rowid DESC";
	} else {
		$sqlMenu .= " ORDER BY rowid DESC";
	}
	$sqlMenu .= " " . $db->plimit(200);
	$picked = dolizsynch_pick_menu_from_sql($db, $sqlMenu);
	if ($picked !== '') {
		$menuJsonForField = $picked;
	}
}

// If we just posted a menu, keep it in-memory so the UI re-hydrates even if DB failed.
$postMenu = trim((string) GETPOST('DOLIZSYNCH_ZS_NIVEISMENU', 'none'));
if ($postMenu !== '') {
	$postedMenuCandidates = array(
		$postMenu,
		html_entity_decode($postMenu, ENT_QUOTES | ENT_HTML5),
		stripslashes($postMenu),
		stripslashes(html_entity_decode($postMenu, ENT_QUOTES | ENT_HTML5)),
	);
	$seenPostedMenuCandidates = array();
	foreach ($postedMenuCandidates as $candidate) {
		$candidate = (string) $candidate;
		if ($candidate === '' || isset($seenPostedMenuCandidates[$candidate])) {
			continue;
		}
		$seenPostedMenuCandidates[$candidate] = true;
		$decodedCandidate = json_decode($candidate, true);
		if (json_last_error() === JSON_ERROR_NONE && is_array($decodedCandidate)) {
			$encodedCandidate = json_encode($decodedCandidate, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
			$menuJsonForField = ($encodedCandidate === false ? $candidate : $encodedCandidate);
			break;
		}
	}
}

// -----------------------------
// Production centres (primary/secondary/informative)
// -----------------------------
$prodCentres = array();
$currentPrimaryCentre = 0;
$currentSecondaryCentres = array();
$currentInformativeCentres = array();

// Load centres dictionary for current store
$centresSql = "SELECT prodcentre_id, prodcentre_name FROM " . MAIN_DB_PREFIX . "dolizsynch_zsprodcentre";
$centresSql .= " WHERE 1=1";
if ($storeZs > 0) {
	$centresSql .= " AND loja_zs = " . (int) $storeZs;
}
$centresSql .= " ORDER BY prodcentre_name ASC";
$resCentres = $db->query($centresSql);
if ($resCentres) {
	while ($row = $db->fetch_object($resCentres)) {
		$prodCentres[(int) $row->prodcentre_id] = $row->prodcentre_name;
	}
}

// Load current product centres from ZS table
$prodCentresSql = "SELECT cozinha, produtoscentrosprod FROM " . MAIN_DB_PREFIX . "dolizsynch_zsproduct WHERE fk_product = " . ((int) $object->id);
if ($storeZs > 0) {
	$prodCentresSql .= " AND loja_zs = " . (int) $storeZs;
}
$prodCentresSql .= " ORDER BY rowid DESC " . $db->plimit(1);
$resProdCentres = $db->query($prodCentresSql);
if ($resProdCentres && ($row = $db->fetch_object($resProdCentres))) {
	$currentPrimaryCentre = (int) $row->cozinha;
	if (!empty($row->produtoscentrosprod)) {
		$decodedCentres = json_decode($row->produtoscentrosprod, true);
		if (json_last_error() === JSON_ERROR_NONE && is_array($decodedCentres)) {
			foreach ($decodedCentres as $c) {
				$cid = isset($c['centro']) ? (int) $c['centro'] : 0;
				$informativo = !empty($c['informativo']) ? 1 : 0;
				if ($cid <= 0) continue;
				if ($informativo) {
					$currentInformativeCentres[$cid] = true;
				} else {
					$currentSecondaryCentres[$cid] = true;
				}
			}
		}
	}
}

// Handle production centre save
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'update') {
	$primaryPosted = GETPOST('DOLIZSYNCH_ZS_PRIMARY_CENTRE', 'int');
	$secondaryPosted = GETPOST('DOLIZSYNCH_ZS_SECONDARY_CENTRES', 'array');
	$informativePosted = GETPOST('DOLIZSYNCH_ZS_INFORMATIVE_CENTRES', 'array');

	$primaryValue = (int) $primaryPosted;
	$secondaryIds = is_array($secondaryPosted) ? array_filter(array_map('intval', $secondaryPosted)) : array();
	$informativeIds = is_array($informativePosted) ? array_filter(array_map('intval', $informativePosted)) : array();

	// Build produtoscentrosprod payload similar to ZSBMS
	$productCodeForCentre = ($zsCodigoForMenu !== '' ? $zsCodigoForMenu : ((ctype_digit((string) $object->ref)) ? (int) $object->ref : (int) $object->id));
	$centresPayload = array();

	if ($primaryValue > 0) {
		$centresPayload[] = array(
			'centro' => $primaryValue,
			'codigo' => $productCodeForCentre,
			'informativo' => 0,
			'loja' => $storeZs
		);
	}
	foreach ($secondaryIds as $cid) {
		$centresPayload[] = array(
			'centro' => $cid,
			'codigo' => $productCodeForCentre,
			'informativo' => 0,
			'loja' => $storeZs
		);
	}
	foreach ($informativeIds as $cid) {
		$centresPayload[] = array(
			'centro' => $cid,
			'codigo' => $productCodeForCentre,
			'informativo' => 1,
			'loja' => $storeZs
		);
	}

	// Update DB
	$centresJson = $db->escape(json_encode($centresPayload));
	$sqlUpdateCentres = "UPDATE " . MAIN_DB_PREFIX . "dolizsynch_zsproduct SET";
	$sqlUpdateCentres .= " cozinha = " . ((int) $primaryValue) . ",";
	$sqlUpdateCentres .= " produtoscentrosprod = '" . $centresJson . "'";
	$sqlUpdateCentres .= " WHERE fk_product = " . ((int) $object->id);
	if ($storeZs > 0) {
		$sqlUpdateCentres .= " AND loja_zs = " . (int) $storeZs;
	}

	$resUpd = $db->query($sqlUpdateCentres);
	if ($resUpd) {
		setEventMessages($langs->trans("RecordSaved"), null, 'mesgs');
		$currentPrimaryCentre = $primaryValue;
		$currentSecondaryCentres = array();
		foreach ($secondaryIds as $cid) $currentSecondaryCentres[$cid] = true;
		$currentInformativeCentres = array();
		foreach ($informativeIds as $cid) $currentInformativeCentres[$cid] = true;
	} else {
		setEventMessages($langs->trans("Error") . ' : ' . $db->lasterror(), null, 'errors');
	}
}

// Decode menu JSON for JS/view rendering
if (!empty($menuJsonForField)) {
	$tmpDecoded = json_decode($menuJsonForField, true);
	if (json_last_error() === JSON_ERROR_NONE && is_array($tmpDecoded)) {
		$menuDecodedForJs = $tmpDecoded;
	}
}

// Hydrate missing item descriptions (product items) so edit UI is filled on refresh
if (!empty($menuDecodedForJs) && is_array($menuDecodedForJs)) {
	$codesToResolve = array();
	foreach ($menuDecodedForJs as $lvl) {
		if (empty($lvl['niveismenuext']) || !is_array($lvl['niveismenuext'])) {
			continue;
		}
		foreach ($lvl['niveismenuext'] as $it) {
			$fixo = isset($it['fixo']) ? (int) $it['fixo'] : 0;
			if ($fixo !== 0 && $fixo !== 1) {
				continue;
			}
			$codigo = isset($it['codigo']) ? trim((string) $it['codigo']) : '';
			$desc = isset($it['descricao']) ? trim((string) $it['descricao']) : '';
			if ($desc !== '') {
				continue;
			}
			if ($codigo !== '' && ctype_digit($codigo)) {
				$codesToResolve[(int) $codigo] = true;
			}
		}
	}

	if (!empty($codesToResolve)) {
		$zsProductDescByCodigo = array();
		$sqlCodes = implode(',', array_keys($codesToResolve));
		// Don't hard-filter by store: menus are shared across entities/stores, but prefer the current store when duplicates exist.
		$sqlProd = "SELECT rowid, codigo, descricao, loja_zs FROM " . MAIN_DB_PREFIX . "dolizsynch_zsproduct";
		$sqlProd .= " WHERE codigo IN (" . $sqlCodes . ")";
		$sqlProd .= " AND descricao IS NOT NULL AND descricao <> ''";
		if ($storeZs > 0) {
			$sqlProd .= " ORDER BY (loja_zs = " . ((int) $storeZs) . ") DESC, rowid DESC";
		} else {
			$sqlProd .= " ORDER BY rowid DESC";
		}
		$resProd = $db->query($sqlProd);
		if ($resProd) {
			while ($objProd = $db->fetch_object($resProd)) {
				$key = (string) $objProd->codigo;
				if (!isset($zsProductDescByCodigo[$key])) {
					$zsProductDescByCodigo[$key] = (string) $objProd->descricao;
				}
			}
		}

		foreach ($menuDecodedForJs as $lvlIdx => $lvl) {
			if (empty($lvl['niveismenuext']) || !is_array($lvl['niveismenuext'])) {
				continue;
			}
			foreach ($lvl['niveismenuext'] as $itIdx => $it) {
				$fixo = isset($it['fixo']) ? (int) $it['fixo'] : 0;
				if ($fixo !== 0 && $fixo !== 1) {
					continue;
				}
				$codigo = isset($it['codigo']) ? trim((string) $it['codigo']) : '';
				$desc = isset($it['descricao']) ? trim((string) $it['descricao']) : '';
				if ($desc !== '') {
					continue;
				}
				if ($codigo !== '' && isset($zsProductDescByCodigo[$codigo])) {
					$menuDecodedForJs[$lvlIdx]['niveismenuext'][$itIdx]['descricao'] = $zsProductDescByCodigo[$codigo];
				}
			}
		}
	}
}

// Family options (ZS)
$familyOptions = array();
$sqlFamilies = "SELECT family_id, family_name, subfamily_id, subfamily_name FROM " . MAIN_DB_PREFIX . "dolizsynch_zsfamily";
$sqlFamilies .= ($storeZs > 0 ? " WHERE loja_zs = " . $storeZs : "");
$sqlFamilies .= " ORDER BY family_name ASC, subfamily_name ASC";
$resFam = $db->query($sqlFamilies);
if ($resFam) {
	$seenFamilyValues = array();
	while ($objFam = $db->fetch_object($resFam)) {
		$label = ($objFam->subfamily_name == '-' || $objFam->subfamily_name == '') ? $objFam->family_name : $objFam->family_name . ' >> ' . $objFam->subfamily_name;
		$value = $objFam->family_id . '_' . $objFam->subfamily_id;
		if (isset($seenFamilyValues[$value])) {
			continue;
		}
		$seenFamilyValues[$value] = true;
		$familyOptions[] = array(
			'value' => $value,
			'label' => $label,
		);
	}
}
// Debug logs (enable with constant DOLIZSYNCH_DEBUG_MENU_BUILDER=1)
if (!empty($conf->global->DOLIZSYNCH_DEBUG_MENU_BUILDER)) {
	dol_syslog(__METHOD__ . ' menuJsonForField=' . (string) $menuJsonForField, LOG_INFO);
	dol_syslog(__METHOD__ . ' menuDecodedForJs count=' . (is_array($menuDecodedForJs) ? count($menuDecodedForJs) : 0), LOG_INFO);
}

// Load extrafields for downstream logic
if (method_exists($object, 'fetch_optionals')) {
	$object->fetch_optionals($object->id);
}

// Security check
if ($object->type == $object::TYPE_PRODUCT) {
	restrictedArea($user, 'produit', $object->id, 'product&product', '', '');
} else {
	restrictedArea($user, 'service', $object->id, 'product&product', '', '');
}

// Permission was checked before module-specific processing.
if (in_array($action, array('edit', 'update'), true) && !$canEditProduct) {
	accessforbidden();
}

// Product properties are stored on the entity-scoped Dolibarr product's ZSBMS shadow row.
$propertyService = new ZSProductPropertyService($db);
$propertyDefinitions = array();
$propertyDictionaryLoaded = false;
$propertyLoadError = '';
$storedPropertyState = array(
	'codigo' => '',
	'produtos_propriedades' => null,
	'caracteristicas' => null,
);
try {
	$storedPropertyState = $propertyService->fetchStoredProductState((int) $object->id, $storeZs);
} catch (Exception $e) {
	$propertyLoadError = $e->getMessage();
}

$propertyProductCode = trim((string) $storedPropertyState['codigo']);
if ($propertyProductCode === '') {
	$propertyProductCode = $zsCodigoForMenu !== '' ? $zsCodigoForMenu : (string) $object->ref;
}
$currentPropertyRelations = array();
try {
	$currentPropertyRelations = ZSProductPropertyService::normalizeRelations(
		$storedPropertyState['produtos_propriedades'],
		$propertyProductCode
	);
} catch (Exception $e) {
	$propertyLoadError = $e->getMessage();
}
$productHasCharacteristics = ZSProductPropertyService::hasRows($storedPropertyState['caracteristicas']);
$propertyStockService = new ZSPropertyStockService($db);
$propertyStockRule = $propertyStockService->fetchRule((int) $object->id, (int) $conf->entity, false);
$propertyStockLoadError = $propertyStockService->error;
$propertyStockBomCandidates = $propertyStockService->fetchDismantlingBomCandidates((int) $object->id, (int) $conf->entity);
$propertyStockBoms = array_filter($propertyStockBomCandidates, static function ($bom) {
	return !empty($bom['eligible']);
});
if ($propertyStockService->error !== '') {
	$propertyStockLoadError = trim($propertyStockLoadError . ' ' . $propertyStockService->error);
}

if ($storeZs > 0) {
	try {
		$propertyDefinitions = $propertyService->fetchDefinitionsForStore($storeZs);
		$propertyDictionaryLoaded = true;
	} catch (Exception $e) {
		$propertyLoadError = $e->getMessage();
		dol_syslog(__METHOD__ . ': Unable to load ZSBMS product properties: ' . $propertyLoadError, LOG_WARNING);
	}
} elseif ($propertyLoadError === '') {
	$propertyLoadError = $langs->trans('DoliZSynchProductPropertiesStoreRequired');
}

$configuredStockPropertyId = getDolGlobalInt('DOLIZSYNCH_PROPERTY_STOCK_SOURCE_PROPERTY_ID');
$configuredStockPropertyDefinition = null;
if (
	$configuredStockPropertyId > 0
	&& isset($propertyDefinitions[$configuredStockPropertyId])
	&& !empty($propertyDefinitions[$configuredStockPropertyId]['ativo'])
	&& ($propertyDefinitions[$configuredStockPropertyId]['tipo_dados'] ?? '') === 'numeric'
) {
	$configuredStockPropertyDefinition = $propertyDefinitions[$configuredStockPropertyId];
}

$dolizsynchActions = new ActionsDoliZSynch($db);

$allowedPropertyIdsForSave = array_keys($propertyDefinitions);
foreach ($currentPropertyRelations as $relation) {
	$allowedPropertyIdsForSave[] = (int) $relation['cod_propriedade'];
}
$allowedPropertyIdsForSave = array_values(array_unique(array_filter(array_map('intval', $allowedPropertyIdsForSave))));
$parameters = array(
	'id' => $object->id,
	'ref' => $object->ref,
	'context' => 'productcard:dolizsynchproducttab',
	'from_dolizsynch_tab' => 1,
	'from_dolizsynch_page' => 1,
	'zs_property_ids' => $allowedPropertyIdsForSave,
	'zs_property_dictionary_loaded' => $propertyDictionaryLoaded ? 1 : 0,
	'zs_property_stock_definition' => $configuredStockPropertyDefinition,
);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	$actionResult = $dolizsynchActions->doActions($parameters, $object, $action, $hookmanager);
	if ($actionResult < 0) {
		setEventMessages($dolizsynchActions->error, $dolizsynchActions->errors, 'errors');
	}

	// After processing, fall back to view mode
	if ($action === 'update') {
		try {
			$storedPropertyState = $propertyService->fetchStoredProductState((int) $object->id, $storeZs);
			$propertyProductCode = trim((string) $storedPropertyState['codigo']);
			if ($propertyProductCode === '') {
				$propertyProductCode = $zsCodigoForMenu !== '' ? $zsCodigoForMenu : (string) $object->ref;
			}
			$currentPropertyRelations = ZSProductPropertyService::normalizeRelations(
				$storedPropertyState['produtos_propriedades'],
				$propertyProductCode
			);
			$productHasCharacteristics = ZSProductPropertyService::hasRows($storedPropertyState['caracteristicas']);
			$propertyStockRule = $propertyStockService->fetchRule((int) $object->id, (int) $conf->entity, false);
			$propertyStockLoadError = $propertyStockService->error;
			$propertyStockBomCandidates = $propertyStockService->fetchDismantlingBomCandidates((int) $object->id, (int) $conf->entity);
			$propertyStockBoms = array_filter($propertyStockBomCandidates, static function ($bom) {
				return !empty($bom['eligible']);
			});
			if ($propertyStockService->error !== '') {
				$propertyStockLoadError = trim($propertyStockLoadError . ' ' . $propertyStockService->error);
			}
		} catch (Exception $e) {
			setEventMessages($langs->trans('Error') . ': ' . $e->getMessage(), null, 'errors');
		}
		$action = '';
	}
}

$title = $langs->trans('DoliZSynchTab');

llxHeader('', $title);

$head = product_prepare_head($object);
print dol_fiche_head($head, 'dolizsynch', $langs->trans('ProductCard'), -1, $object->picto);

$linkback = '<a href="' . DOL_URL_ROOT . '/product/list.php">' . $langs->trans('BackToList') . '</a>';
dol_banner_tab($object, 'ref', $linkback, 1, 'ref', 'ref', '', '');

print '<div class="clearboth"></div>';

print '<form method="POST" action="' . $_SERVER['PHP_SELF'] . '?id=' . $object->id . '">';
print '<input type="hidden" name="token" value="' . newToken() . '">';
print '<input type="hidden" name="id" value="' . $object->id . '">';
print '<input type="hidden" name="dolizsynch_page" value="1">';
print '<input type="hidden" name="dolizsynch_form" value="1">';
print '<input type="hidden" name="action" value="' . ($action === 'edit' ? 'update' : $action) . '">';

print '<table class="border centpercent">';

$displayAction = ($action === 'edit') ? 'edit' : $action;
$hookHtml = '';
$actionResult = $dolizsynchActions->formObjectOptions($parameters, $object, $displayAction, $hookmanager);
if ($actionResult < 0) {
	setEventMessages($dolizsynchActions->error, $dolizsynchActions->errors, 'errors');
}
if (!empty($dolizsynchActions->resprints)) {
	// Remove "(ZS)" suffix from labels on this dedicated page (context is already ZS-specific)
	$hookHtml = preg_replace('/\\s*\\(ZS\\)\\s*/', '', (string) $dolizsynchActions->resprints);
	print $hookHtml;
}
$hookHasBalanca = ($hookHtml !== '' && strpos($hookHtml, 'DOLIZSYNCH_ZS_BALANCA') !== false);

$labelPrimary = $langs->trans("DoliZSynchPrimaryProductionCentre");
if ($labelPrimary === "DoliZSynchPrimaryProductionCentre") $labelPrimary = "Centro de Produção (Primário)";
$labelSecondary = $langs->trans("DoliZSynchSecondaryProductionCentres");
if ($labelSecondary === "DoliZSynchSecondaryProductionCentres") $labelSecondary = "Centros de Produção Secundários";
$labelInformative = $langs->trans("DoliZSynchInformativeProductionCentres");
if ($labelInformative === "DoliZSynchInformativeProductionCentres") $labelInformative = "Centros de Produção Informativos";
$labelNone = $langs->trans("NoProdCentre");
if ($labelNone === "NoProdCentre") $labelNone = "Não definido";
$labelNoOrders = "Não faz pedidos";
$labelPrinters = "Lista de impressoras";
$labelZoneDefined = "Definido pela zona";
$labelBalanca = preg_replace('/\\s*\\(ZS\\)\\s*/', '', $langs->trans("DoliZSynchProdutoBalanca"));
if ($labelBalanca === "DoliZSynchProdutoBalanca") $labelBalanca = "Introdução de quantidade";
$balancaOptions = array(
	'0' => $langs->trans('DoliZSynchProductBalancaNormal'),
	'1' => $langs->trans('DoliZSynchProductBalancaBalanca'),
	'2' => $langs->trans('DoliZSynchProductBalancaPedirQuantidade'),
	'3' => $langs->trans('DoliZSynchProductBalancaQuantidadePendente'),
	'4' => $langs->trans('DoliZSynchProductBalancaPedeValor'),
);
$balancaOptionsWithEmpty = array('' => '') + $balancaOptions;

$centreOptionsHtml = '';
if (!empty($prodCentres)) {
	$centreOptionsHtml .= '<option value="0"' . ($currentPrimaryCentre === 0 ? ' selected' : '') . '>' . dol_escape_htmltag($labelNoOrders) . '</option>';
	$centreOptionsHtml .= '<option value="-1"' . ($currentPrimaryCentre === -1 ? ' selected' : '') . '>' . dol_escape_htmltag($labelPrinters) . '</option>';
	$centreOptionsHtml .= '<option value="-2"' . ($currentPrimaryCentre === -2 ? ' selected' : '') . '>' . dol_escape_htmltag($labelZoneDefined) . '</option>';
	foreach ($prodCentres as $cid => $cname) {
		$selected = ($cid === $currentPrimaryCentre) ? ' selected' : '';
		$centreOptionsHtml .= '<option value="' . (int) $cid . '"' . $selected . '>' . dol_escape_htmltag($cname) . '</option>';
	}
}

$centreDisabled = ($action === 'edit') ? '' : ' disabled';

if (!$hookHasBalanca) {
	$balancaSelected = ($balancaForPage === '' ? '0' : $balancaForPage);
	print '<tr id="row-balanca"><td class="titlefield" style="padding-top:10px;">' . dol_escape_htmltag($labelBalanca) . '</td><td style="padding-top:10px;" colspan="3">';
	print '<select name="DOLIZSYNCH_ZS_BALANCA" id="DOLIZSYNCH_ZS_BALANCA" class="flat" style="min-width:240px; color:#000; background-color:#fff;"' . $centreDisabled . '>';
	foreach ($balancaOptionsWithEmpty as $value => $label) {
		$selected = ((string) $balancaSelected === (string) $value) ? ' selected' : '';
		print '<option value="' . dol_escape_htmltag($value) . '"' . $selected . ' style="color:#000; background-color:#fff;">' . dol_escape_htmltag($label) . '</option>';
	}
	print '</select>';
	print '</td></tr>';
}

// Secondary centres
$rowPrimaryStyle = ($isMenuComposto ? ' style="display:none;"' : '');
$rowSecondaryStyle = ($isMenuComposto ? ' style="display:none;"' : ($currentPrimaryCentre > 0 ? '' : ' style="display:none;"'));
$rowInformativeStyle = ($isMenuComposto ? ' style="display:none;"' : ($currentPrimaryCentre > 0 ? '' : ' style="display:none;"'));
$rowPrinterStyle = ($isMenuComposto ? ' style="display:none;"' : ($currentPrimaryCentre === -1 ? '' : ' style="display:none;"'));

// Primary production centre selector
print '<tr id="row-primary-centre"' . $rowPrimaryStyle . '><td class="titlefield" style="padding-top:10px;">' . dol_escape_htmltag($labelPrimary) . '</td><td style="padding-top:10px;" colspan="3">';
print '<select name="DOLIZSYNCH_ZS_PRIMARY_CENTRE" id="DOLIZSYNCH_ZS_PRIMARY_CENTRE" class="flat" style="min-width:240px;"' . $centreDisabled . '>';
print $centreOptionsHtml;
print '</select>';
if (function_exists('ajax_combobox')) {
	print ajax_combobox('DOLIZSYNCH_ZS_PRIMARY_CENTRE', array(), 0, 0, 'resolve', '0', 'minwidth240');
}
print '</td></tr>';

// Secondary centres
print '<tr id="row-secondary-centres"' . $rowSecondaryStyle . '><td class="titlefield" style="padding-top:10px;">' . dol_escape_htmltag($labelSecondary) . '</td><td style="padding-top:10px;" colspan="3">';
if (!empty($prodCentres)) {
	print '<div class="prodcentre-list">';
	foreach ($prodCentres as $cid => $cname) {
		$checked = isset($currentSecondaryCentres[$cid]) ? ' checked' : '';
		print '<label class="prodcentre-pill"><input type="checkbox" name="DOLIZSYNCH_ZS_SECONDARY_CENTRES[]" value="' . (int) $cid . '"' . $checked . $centreDisabled . '> ' . dol_escape_htmltag($cname) . '</label>';
	}
	print '</div>';
} else {
	print dol_escape_htmltag($labelNone);
}
print '</td></tr>';

// Informative centres
$rowInformativeStyle = ($isMenuComposto ? ' style="display:none;"' : ($currentPrimaryCentre > 0 ? '' : ' style="display:none;"'));
print '<tr id="row-informative-centres"' . $rowInformativeStyle . '><td class="titlefield" style="padding-top:10px;">' . dol_escape_htmltag($labelInformative) . '</td><td style="padding-top:10px;" colspan="3">';
if (!empty($prodCentres)) {
	print '<div class="prodcentre-list">';
	foreach ($prodCentres as $cid => $cname) {
		$checked = isset($currentInformativeCentres[$cid]) ? ' checked' : '';
		print '<label class="prodcentre-pill"><input type="checkbox" name="DOLIZSYNCH_ZS_INFORMATIVE_CENTRES[]" value="' . (int) $cid . '"' . $checked . $centreDisabled . '> ' . dol_escape_htmltag($cname) . '</label>';
	}
	print '</div>';
} else {
	print dol_escape_htmltag($labelNone);
}
print '</td></tr>';

// Product properties
$propertyTrans = static function ($key) use ($langs) {
	return ZSProductPropertyService::decodeDisplayText($langs->trans($key));
};
$propertyUsageLabels = array(
	0 => $propertyTrans('DoliZSynchPropertyNotApplicable'),
	1 => $propertyTrans('DoliZSynchPropertyOptional'),
	2 => $propertyTrans('DoliZSynchPropertyMandatory'),
);
$propertyDataTypeLabels = array(
	'string' => $propertyTrans('DoliZSynchPropertyDataTypeString'),
	'date' => $propertyTrans('DoliZSynchPropertyDataTypeDate'),
	'datetime' => $propertyTrans('DoliZSynchPropertyDataTypeDateTime'),
	'numeric' => $propertyTrans('DoliZSynchPropertyDataTypeNumeric'),
);
$propertyOrderLabels = array(
	'nofx' => $propertyTrans('DoliZSynchPropertyOrderNone'),
	'fifo' => $propertyTrans('DoliZSynchPropertyOrderFifo'),
	'lifo' => $propertyTrans('DoliZSynchPropertyOrderLifo'),
);
foreach ($currentPropertyRelations as $relation) {
	$relationPropertyId = (int) $relation['cod_propriedade'];
	if ($relationPropertyId > 0 && !isset($propertyDefinitions[$relationPropertyId])) {
		$propertyDefinitions[$relationPropertyId] = array(
			'id' => $relationPropertyId,
			'nome' => '#' . $relationPropertyId,
			'compra' => 0,
			'ativo' => 0,
			'ordenacao' => 'nofx',
			'tipo_dados' => 'string',
			'valores_multiplos' => 0,
			'venda' => 0,
		);
	}
}
$propertiesJsonForField = json_encode($currentPropertyRelations, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if ($propertiesJsonForField === false) {
	$propertiesJsonForField = '[]';
}
print '<tr id="row-product-properties"><td class="titlefield tdtop" style="padding-top:10px;">' . dol_escape_htmltag($propertyTrans('DoliZSynchProductProperties')) . '</td><td style="padding-top:10px;" colspan="3">';
print '<input type="hidden" name="DOLIZSYNCH_ZS_PRODUCT_PROPERTIES" id="DOLIZSYNCH_ZS_PRODUCT_PROPERTIES" value="' . dol_escape_htmltag($propertiesJsonForField) . '">';
if ($propertyLoadError !== '') {
	print '<div class="warning">' . dol_escape_htmltag($propertyTrans('DoliZSynchProductPropertiesLoadFailed')) . ': ' . dol_escape_htmltag(ZSProductPropertyService::decodeDisplayText($propertyLoadError)) . '</div>';
}
if ($productHasCharacteristics) {
	print '<div class="warning">' . dol_escape_htmltag($propertyTrans('DoliZSynchProductPropertiesCharacteristicsConflict')) . '</div>';
}

if ($action === 'edit') {
	print '<div class="dzs-property-picker">';
	print '<div><label for="dzs-property-select">' . dol_escape_htmltag($propertyTrans('DoliZSynchProperty')) . '</label><br>';
	print '<select id="dzs-property-select" class="flat minwidth300"' . (!$propertyDictionaryLoaded || $productHasCharacteristics ? ' disabled' : '') . '></select> ';
	print '<button type="button" class="button smallpaddingimp" id="dzs-property-add"' . (!$propertyDictionaryLoaded || $productHasCharacteristics ? ' disabled' : '') . '>' . dol_escape_htmltag($propertyTrans('DoliZSynchPropertyConfirm')) . '</button></div>';
	print '<div id="dzs-property-details" class="dzs-property-details"></div>';
	print '</div>';
	print '<div id="dzs-property-relations"></div>';
} elseif (empty($currentPropertyRelations)) {
	print '<span class="opacitymedium">' . dol_escape_htmltag($propertyTrans('DoliZSynchProductPropertiesNone')) . '</span>';
} else {
	print '<div class="div-table-responsive"><table class="noborder centpercent dzs-property-table">';
	print '<tr class="liste_titre">';
	print '<th>' . dol_escape_htmltag($propertyTrans('DoliZSynchProperty')) . '</th>';
	print '<th>' . dol_escape_htmltag($propertyTrans('DoliZSynchPropertyDataType')) . '</th>';
	print '<th>' . dol_escape_htmltag($propertyTrans('DoliZSynchPropertySale')) . '</th>';
	print '<th>' . dol_escape_htmltag($propertyTrans('DoliZSynchPropertyPurchase')) . '</th>';
	print '<th>' . dol_escape_htmltag($propertyTrans('DoliZSynchPropertyMultipleValues')) . '</th>';
	print '<th>' . dol_escape_htmltag($propertyTrans('DoliZSynchPropertySalePriority')) . '</th>';
	print '<th>' . dol_escape_htmltag($propertyTrans('DoliZSynchPropertyStatus')) . '</th>';
	print '</tr>';
	foreach ($currentPropertyRelations as $relation) {
		$propertyId = (int) $relation['cod_propriedade'];
		$definition = $propertyDefinitions[$propertyId];
		print '<tr class="oddeven">';
		print '<td>' . dol_escape_htmltag($definition['nome']) . ' <span class="opacitymedium">(#' . $propertyId . ')</span></td>';
		print '<td>' . dol_escape_htmltag($propertyDataTypeLabels[$definition['tipo_dados']] ?? $definition['tipo_dados']) . '</td>';
		print '<td>' . dol_escape_htmltag($propertyUsageLabels[(int) $definition['venda']] ?? '') . '</td>';
		print '<td>' . dol_escape_htmltag($propertyUsageLabels[(int) $definition['compra']] ?? '') . '</td>';
		print '<td>' . dol_escape_htmltag(!empty($definition['valores_multiplos']) ? $propertyTrans('DoliZSynchPropertyYes') : $propertyTrans('DoliZSynchPropertyNo')) . '</td>';
		print '<td>' . dol_escape_htmltag($propertyOrderLabels[$definition['ordenacao']] ?? $definition['ordenacao']) . '</td>';
		print '<td>' . dol_escape_htmltag(!empty($relation['estado']) ? $propertyTrans('DoliZSynchPropertyActive') : $propertyTrans('DoliZSynchPropertyInactive')) . '</td>';
		print '</tr>';
	}
	print '</table></div>';
}
print '</td></tr>';

// Exact-unit stock conversion is available only when setup selects a valid numeric property.
$stockRuleMatchesSetup = $configuredStockPropertyDefinition !== null
	&& (!empty($propertyStockRule['shared'])
		|| (int) ($propertyStockRule['property_id'] ?? 0) === $configuredStockPropertyId);
$stockRuleCanReuseSelection = !empty($propertyStockRule['active'])
	&& $stockRuleMatchesSetup;
$stockRuleBomId = $stockRuleCanReuseSelection && !empty($propertyStockRule['fk_bom'])
	? (int) $propertyStockRule['fk_bom']
	: 0;
$stockRuleBomLineId = $stockRuleCanReuseSelection && !empty($propertyStockRule['fk_bom_line'])
	? (int) $propertyStockRule['fk_bom_line']
	: 0;
$stockRuleOutputProduct = $stockRuleCanReuseSelection && !empty($propertyStockRule['fk_product_output'])
	? (int) $propertyStockRule['fk_product_output']
	: 0;
$stockRuleHasValidatedSelection = isset($propertyStockBoms[$stockRuleBomId]['outputs'][$stockRuleBomLineId])
	&& (int) $propertyStockBoms[$stockRuleBomId]['outputs'][$stockRuleBomLineId]['fk_product'] === $stockRuleOutputProduct;
$stockRuleActive = $stockRuleCanReuseSelection && $stockRuleHasValidatedSelection;
$stockPropertyName = $configuredStockPropertyDefinition !== null
	? trim((string) $configuredStockPropertyDefinition['nome'])
	: '';
$sharedStockRuleNotice = '';
if ($stockRuleActive && !empty($propertyStockRule['shared'])) {
	$sharedOutput = $propertyStockBoms[$stockRuleBomId]['outputs'][$stockRuleBomLineId];
	$sharedOutputQuantity = (string) $sharedOutput['qty'];
	$sharedOutputLabel = trim((string) $sharedOutput['product_label']);
	$sharedOutputRef = trim((string) $sharedOutput['product_ref']);
	if ($sharedOutputLabel === '') {
		$sharedOutputLabel = $sharedOutputRef;
	} elseif ($sharedOutputRef !== '') {
		$sharedOutputLabel .= ' (' . $sharedOutputRef . ')';
	}
	$sharedStockRuleNotice = '<div class="dzs-property-stock-shared">';
	$sharedStockRuleNotice .= '<div><span class="fa fa-share-alt" aria-hidden="true"></span> ';
	$sharedRuleText = ZSProductPropertyService::decodeDisplayText(
		$langs->trans('DoliZSynchPropertyStockSharedBomRule', $stockPropertyName)
	);
	$sharedStockRuleNotice .= dol_escape_htmltag($sharedRuleText) . '</div>';
	$sharedStockRuleNotice .= '<div class="dzs-property-stock-formula"><strong>';
	$sharedStockRuleNotice .= dol_escape_htmltag($sharedOutputQuantity) . '</strong> <span aria-hidden="true">×</span> <strong>';
	$sharedStockRuleNotice .= dol_escape_htmltag($sharedOutputLabel) . '</strong></div></div>';
}

if ($configuredStockPropertyDefinition !== null) {
	print '<tr id="row-property-stock-rule"><td class="titlefield tdtop" style="padding-top:10px;">' . dol_escape_htmltag($langs->trans('DoliZSynchPropertyStockRule')) . '</td>';
	print '<td style="padding-top:10px;" colspan="3">';
	if ($propertyStockLoadError !== '') {
		print '<div class="warning">' . dol_escape_htmltag($langs->trans('DoliZSynchPropertyStockMigrationRequired')) . '</div>';
	} else {
		print '<input type="hidden" name="DOLIZSYNCH_PROPERTY_STOCK_PRESENT" value="1">';
	}
	if ($action === 'edit') {
		$propertyStockControlDisabled = $propertyStockLoadError !== '' ? ' disabled' : '';
		print '<div class="div-table-responsive"><table class="noborder centpercent dzs-property-table">';
		print '<tr class="liste_titre"><th><span class="fa fa-cubes" aria-hidden="true"></span> ';
		print dol_escape_htmltag($langs->trans('DoliZSynchPropertyStockConfiguration')) . '</th>';
		print '<th><span class="opacitymedium">' . dol_escape_htmltag($langs->trans('DoliZSynchPropertyStockConfigurationDesc')) . '</span></th></tr>';
		print '<tr class="oddeven"><td>' . dol_escape_htmltag($langs->trans('DoliZSynchPropertyStockTriggerProperty')) . '</td><td>';
		print '<span class="fa fa-tag" aria-hidden="true"></span> ';
		print '<span>' . dol_escape_htmltag((string) $configuredStockPropertyDefinition['nome']) . '</span>';
		print '<div class="opacitymedium">(#' . $configuredStockPropertyId . ' - ';
		print dol_escape_htmltag($langs->trans('DoliZSynchPropertyStockConfiguredInSetup')) . ')</div></td></tr>';
		print '<tr class="oddeven"><td><label for="DOLIZSYNCH_PROPERTY_STOCK_BOM_ID">';
		print dol_escape_htmltag($langs->trans('DoliZSynchPropertyStockBom')) . '</label></td><td>';
		print '<select class="flat minwidth300 maxwidth500" id="DOLIZSYNCH_PROPERTY_STOCK_BOM_ID" name="DOLIZSYNCH_PROPERTY_STOCK_BOM_ID"' . $propertyStockControlDisabled . '>';
		print '<option value="0">' . dol_escape_htmltag($langs->trans('DoliZSynchPropertyStockNoBom')) . '</option>';
		foreach ($propertyStockBomCandidates as $bom) {
			$bomLabel = $bom['ref'];
			if ($bom['label'] !== '') {
				$bomLabel .= ' - ' . $bom['label'];
			}
			$bomLabel .= ' (#' . (int) $bom['id'] . ')';
			if (empty($bom['eligible'])) {
				$reasonArgs = $bom['reason_args'];
				$reason = $langs->trans($bom['reason_key'], ...$reasonArgs);
				$bomLabel .= ' — ' . $reason;
			}
			print '<option value="' . (int) $bom['id'] . '"'
				. (!empty($bom['eligible']) && (int) $bom['id'] === $stockRuleBomId ? ' selected' : '')
				. (empty($bom['eligible']) ? ' disabled' : '') . '>';
			print dol_escape_htmltag($bomLabel) . '</option>';
		}
		print '</select>';
		if ($sharedStockRuleNotice !== '') {
			print $sharedStockRuleNotice;
		}
		print '</td></tr>';
		print '</table></div>';
		foreach ($propertyStockBomCandidates as $bom) {
			if (!empty($bom['eligible'])) {
				continue;
			}
			$reason = $langs->trans($bom['reason_key'], ...$bom['reason_args']);
			$bomLabel = $bom['ref'] . ($bom['label'] !== '' ? ' - ' . $bom['label'] : '');
			print '<div class="opacitymedium small"><span class="fa fa-exclamation-triangle" aria-hidden="true"></span> ';
			print '<a href="' . DOL_URL_ROOT . '/bom/bom_card.php?id=' . (int) $bom['id'] . '">';
			print dol_escape_htmltag($bomLabel) . ' (#' . (int) $bom['id'] . ')</a>: ';
			print dol_escape_htmltag($reason) . '</div>';
		}
		if (empty($propertyStockBoms)) {
			print '<div class="warning">' . dol_escape_htmltag($langs->trans('DoliZSynchPropertyStockNoValidatedBom')) . '</div>';
		}
		print '<div class="opacitymedium small"><span class="fa fa-info-circle" aria-hidden="true"></span> ';
		print dol_escape_htmltag($langs->trans('DoliZSynchPropertyStockHelp')) . '</div>';
	} elseif (!$stockRuleActive) {
		print '<div class="dzs-property-stock-empty"><span class="fa fa-pause-circle" aria-hidden="true"></span><span>';
		print dol_escape_htmltag($langs->trans('DoliZSynchPropertyStockDisabled')) . '</span></div>';
	} else {
		$selectedBom = $propertyStockBoms[$stockRuleBomId];
		$bomLabel = $selectedBom['ref'] . ($selectedBom['label'] !== '' ? ' - ' . $selectedBom['label'] : '');
		print '<div class="dzs-property-stock-summary">';
		print '<div class="dzs-property-stock-status"><span class="fa fa-check-circle" aria-hidden="true"></span> ';
		print dol_escape_htmltag($langs->trans('DoliZSynchPropertyStockActiveState')) . '</div>';
		print '<div><strong>' . dol_escape_htmltag((string) $configuredStockPropertyDefinition['nome']) . '</strong></div>';
		print '<div><strong>' . dol_escape_htmltag($langs->trans('DoliZSynchPropertyStockBom')) . ':</strong> ' . dol_escape_htmltag($bomLabel) . '</div>';
		if ($sharedStockRuleNotice !== '') {
			print $sharedStockRuleNotice;
		}
		print '</div>';
	}
	print '</td></tr>';
}

// Printers list (only visible when primary = -1)
print '<tr id="row-printer-centres"' . $rowPrinterStyle . '><td class="titlefield" style="padding-top:10px;">' . dol_escape_htmltag($labelPrinters) . '</td><td style="padding-top:10px;" colspan="3">';
if (!empty($prodCentres)) {
	print '<div class="prodcentre-list">';
	foreach ($prodCentres as $cid => $cname) {
		// reuse secondary selection for printers; no special flag in DB yet
		print '<label class="prodcentre-pill"><input type="checkbox" name="DOLIZSYNCH_ZS_SECONDARY_CENTRES[]" value="' . (int) $cid . '"' . $centreDisabled . '> ' . dol_escape_htmltag($cname) . '</label>';
	}
	print '</div>';
} else {
	print dol_escape_htmltag($labelNone);
}
print '</td></tr>';

// Inline Menu Builder (ZS) - migrated from working test/product_tab_menu_builder.php
print '<tr id="DOLIZSYNCH_ZS_MENU_BLOCK" data-composto="' . dol_escape_htmltag((string) $compostoForPage) . '"' . ($isMenuComposto ? '' : ' style="display:none;"') . '>';
$labelMenu = preg_replace('/\\s*\\(ZS\\)\\s*/', '', $langs->trans("DoliZSynchProductMenu"));
print '<td class="tdtop">' . dol_escape_htmltag($labelMenu) . '</td><td colspan="3">';
print '<input type="hidden" name="DOLIZSYNCH_ZS_NIVEISMENU" id="DOLIZSYNCH_ZS_NIVEISMENU" value="' . dol_escape_htmltag($menuJsonForField) . '">';
print '<input type="hidden" name="DOLIZSYNCH_ZS_NIVEISMENU_CLEARED" id="DOLIZSYNCH_ZS_NIVEISMENU_CLEARED" value="0">';
if ($action === 'edit') {
	print '<div class="mb-actions" style="margin-top:4px; margin-bottom:6px; display:flex; gap:8px; align-items:center;">';
	print '<button type="button" class="button smallpaddingimp" id="mb-add-level">Adicionar nível</button>';
	print '<button type="button" class="button smallpaddingimp" id="mb-clear">Limpar tudo</button>';
	print '<span class="opacitymedium">Construa os níveis e itens do menu (será guardado em niveismenu).</span>';
	print '</div>';
}
print '<div id="mb-builder" style="margin-top:8px;"></div>';
print '</td></tr>';

//print '</tbody>';
print '</table>';

// Move production centre rows (and menu builder row) into the main config tbody to avoid extra borders/lines
print '<script>
(function(){
	const mainBlock = document.querySelector("tbody.dolizsynch-config-block");
	if (!mainBlock) return;
	const ids = ["row-prodcentre-title","row-balanca","row-primary-centre","row-secondary-centres","row-informative-centres","row-product-properties","row-property-stock-rule","row-printer-centres","DOLIZSYNCH_ZS_MENU_BLOCK"];
	ids.forEach(function(id){
		const el = document.getElementById(id);
		if (el && el.parentNode && el.parentNode !== mainBlock) {
			mainBlock.appendChild(el);
		}
	});
	const balancaField = document.getElementById("DOLIZSYNCH_ZS_BALANCA");
	const compostoField = document.getElementById("DOLIZSYNCH_ZS_COMPOSTO");
	const balancaRow = balancaField ? balancaField.closest("tr") : null;
	const compostoRow = compostoField ? compostoField.closest("tr") : null;
	const balancaNextRow = balancaRow ? balancaRow.nextElementSibling : null;
	if (balancaRow && compostoRow && balancaRow.parentNode === compostoRow.parentNode && balancaNextRow !== compostoRow) {
		balancaRow.parentNode.insertBefore(compostoRow, balancaNextRow);
	}
})();
</script>';

// Inline styles for menu builder and production centre pills
print <<<CSS
	<style>
	.mb-builder-empty { padding: 8px; color: #555; border: 1px dashed #ccc; border-radius: 6px; background: #fafafa; }
	.mb-card { border: 1px solid #dfe7f3; border-radius: 8px; padding: 10px; margin-bottom: 10px; background: #f9fbff; }
		.mb-head { display: flex; gap: 26px 52px; flex-wrap: wrap; align-items: center; }
		.mb-head label { display: inline-flex; align-items: center; gap: 8px; }
		.mb-head input[data-role="level-descricao"] { min-width: 340px; }
	.mb-card-title { font-weight: 700; color: #111827; margin-right: 10px; }
	.mb-head-actions { margin-left: auto; display: flex; align-items: center; gap: 8px; }
	.mb-items { margin-top: 8px; }
	.mb-item { display: grid; grid-template-columns: 140px 1fr 110px 120px 110px 90px 70px; gap: 6px; align-items: center; margin-top: 6px; }
	.mb-item-header { margin-top: 0; padding: 2px 0 6px; border-bottom: 1px solid #e5e7eb; font-size: 12px; font-weight: 600; color: #6b7280; text-transform: uppercase; letter-spacing: 0.02em; }
	.mb-item-header div { white-space: nowrap; }
	@media (max-width: 1100px) { .mb-item { grid-template-columns: 120px 1fr 100px 120px 100px 90px 70px; } }
	.mb-item input[type="text"], .mb-item input[type="number"], .mb-item select { width: 100%; box-sizing: border-box; }
	.mb-prod-picker { display: grid; grid-template-columns: 120px 1fr; gap: 6px; align-items: center; }
	.mb-family-picker select { width: 100%; }
	.mb-remove { display: flex; justify-content: flex-end; gap: 4px; }
	.mb-icon-btn { display: inline-flex; align-items: center; justify-content: center; width: 32px; height: 26px; border: 1px solid transparent; border-radius: 4px; background: transparent; cursor: pointer; }
	.mb-icon-btn:disabled { opacity: 0.3; cursor: not-allowed; }
	.mb-icon-btn .fas { font-size: 13px; }
	.mb-desc-wrap { position: relative; }
	.mb-suggest { position: absolute; top: 34px; left: 0; right: 0; background: #fff; border: 1px solid #d0d7e5; border-radius: 4px; max-height: 220px; overflow: auto; z-index: 50; box-shadow: 0 8px 18px rgba(0,0,0,0.12); display: none; }
	.mb-suggest.visible { display: block; }
	.mb-suggest .mb-sug-item { padding: 6px 8px; cursor: pointer; }
	.mb-suggest .mb-sug-item:hover { background: #f1f5ff; }
	.mb-suggest .mb-sug-empty { padding: 6px 8px; color: #6b7280; }
	.prodcentre-list { display: flex; flex-wrap: wrap; gap: 8px 14px; margin-top: 6px; }
		.prodcentre-pill { display: inline-flex; align-items: center; gap: 8px; padding: 6px 10px; border: 1px solid #dfe3eb; border-radius: 8px; background: #f9fafc; font-size: 0.95em; white-space: nowrap; }
		.prodcentre-pill input { margin: 0; }
		.dzs-property-picker { display: grid; grid-template-columns: minmax(320px, 0.9fr) minmax(360px, 1.1fr); gap: 18px; align-items: start; margin-bottom: 12px; }
		.dzs-property-details { display: grid; grid-template-columns: repeat(2, minmax(160px, 1fr)); gap: 6px 18px; padding: 9px 12px; border: 1px solid #dfe3eb; border-radius: 6px; background: #f9fafc; }
		.dzs-property-details:empty { display: none; }
		.dzs-property-detail-label { color: #6b7280; margin-right: 5px; }
		.dzs-property-table th, .dzs-property-table td { vertical-align: middle; }
		.dzs-property-actions { white-space: nowrap; text-align: right; }
			.dzs-property-stock-empty { display: inline-flex; align-items: center; gap: 8px; color: #6b7280; padding: 8px 0; }
			.dzs-property-stock-summary { display: flex; flex-direction: column; align-items: flex-start; gap: 6px; padding: 9px 12px; border: 1px solid rgba(47, 133, 90, .28); border-radius: 6px; background: rgba(47, 133, 90, .06); max-width: 1000px; }
			.dzs-property-stock-status { color: #28734d; font-weight: 600; }
			.dzs-property-stock-shared { display: flex; flex-direction: column; gap: 3px; margin-top: 6px; color: #5f6873; }
			.dzs-property-stock-summary > .dzs-property-stock-shared { width: 100%; box-sizing: border-box; padding-top: 8px; border-top: 1px solid rgba(47, 133, 90, .18); }
			.dzs-property-stock-formula { color: inherit; }
			@media (max-width: 700px) { .dzs-property-picker { grid-template-columns: 1fr; } }
			</style>
CSS;

$propertyDefinitionsForJs = json_encode($propertyDefinitions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
$propertyRelationsForJs = json_encode($currentPropertyRelations, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
$propertyProductCodeForJs = json_encode(ctype_digit((string) $propertyProductCode) ? (int) $propertyProductCode : $propertyProductCode, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
$canAddPropertiesForJs = ($propertyDictionaryLoaded && !$productHasCharacteristics) ? 'true' : 'false';
$propertyLabelsForJs = json_encode(array(
	'property' => $propertyTrans('DoliZSynchProperty'),
	'dataType' => $propertyTrans('DoliZSynchPropertyDataType'),
	'sale' => $propertyTrans('DoliZSynchPropertySale'),
	'purchase' => $propertyTrans('DoliZSynchPropertyPurchase'),
	'multiple' => $propertyTrans('DoliZSynchPropertyMultipleValues'),
	'priority' => $propertyTrans('DoliZSynchPropertySalePriority'),
	'status' => $propertyTrans('DoliZSynchPropertyStatus'),
	'actions' => $propertyTrans('DoliZSynchPropertyActions'),
	'active' => $propertyTrans('DoliZSynchPropertyActive'),
	'inactive' => $propertyTrans('DoliZSynchPropertyInactive'),
	'yes' => $propertyTrans('DoliZSynchPropertyYes'),
	'no' => $propertyTrans('DoliZSynchPropertyNo'),
	'remove' => $propertyTrans('DoliZSynchPropertyRemove'),
	'noneAvailable' => $propertyTrans('DoliZSynchProductPropertiesNoAvailable'),
	'noneAssigned' => $propertyTrans('DoliZSynchProductPropertiesNone'),
	'usage' => $propertyUsageLabels,
	'dataTypes' => $propertyDataTypeLabels,
	'orders' => $propertyOrderLabels,
), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

if ($action === 'edit') {
	print <<<JS
<script>
(function() {
	"use strict";
	const definitions = {$propertyDefinitionsForJs} || {};
	let relations = {$propertyRelationsForJs} || [];
	const productCode = {$propertyProductCodeForJs};
	const canAddProperties = {$canAddPropertiesForJs};
	const labels = {$propertyLabelsForJs};
	const hidden = document.getElementById("DOLIZSYNCH_ZS_PRODUCT_PROPERTIES");
	const picker = document.getElementById("dzs-property-select");
	const addButton = document.getElementById("dzs-property-add");
	const details = document.getElementById("dzs-property-details");
	const relationsHost = document.getElementById("dzs-property-relations");
	if (!hidden || !picker || !addButton || !details || !relationsHost) return;

	function getDefinition(id) {
		return definitions[String(id)] || definitions[id] || {
			id: Number(id), nome: "#" + id, compra: 0, ativo: 0,
			ordenacao: "nofx", tipo_dados: "string", valores_multiplos: 0, venda: 0
		};
	}

	function usageLabel(value) {
		return (labels.usage && (labels.usage[String(value)] || labels.usage[value])) || String(value);
	}

	function dataTypeLabel(value) {
		return (labels.dataTypes && labels.dataTypes[value]) || value;
	}

	function orderLabel(value) {
		return (labels.orders && labels.orders[value]) || value;
	}

	function syncHidden() {
		relations.sort(function(left, right) {
			return Number(left.cod_propriedade) - Number(right.cod_propriedade);
		});
		hidden.value = JSON.stringify(relations.map(function(relation) {
			const id = Number(relation.cod_propriedade || relation.uid || 0);
			return {
				cod_produto: productCode,
				cod_propriedade: id,
				uid: id,
				estado: Number(relation.estado) === 1 ? 1 : 0
			};
		}));
	}

	function appendDetail(label, value) {
		const item = document.createElement("div");
		const caption = document.createElement("span");
		caption.className = "dzs-property-detail-label";
		caption.textContent = label + ":";
		item.appendChild(caption);
		item.appendChild(document.createTextNode(value));
		details.appendChild(item);
	}

	function renderDetails() {
		details.replaceChildren();
		const id = Number(picker.value || 0);
		if (id <= 0) return;
		const definition = getDefinition(id);
		appendDetail(labels.dataType, dataTypeLabel(definition.tipo_dados));
		appendDetail(labels.priority, orderLabel(definition.ordenacao));
		appendDetail(labels.multiple, Number(definition.valores_multiplos) === 1 ? labels.yes : labels.no);
		appendDetail(labels.purchase, usageLabel(Number(definition.compra)));
		appendDetail(labels.sale, usageLabel(Number(definition.venda)));
	}

	function createCell(row, value) {
		const cell = document.createElement("td");
		cell.textContent = value;
		row.appendChild(cell);
		return cell;
	}

	function renderRelations() {
		relationsHost.replaceChildren();
		if (relations.length === 0) {
			const empty = document.createElement("span");
			empty.className = "opacitymedium";
			empty.textContent = labels.noneAssigned;
			relationsHost.appendChild(empty);
			return;
		}

		const wrapper = document.createElement("div");
		wrapper.className = "div-table-responsive";
		const table = document.createElement("table");
		table.className = "noborder centpercent dzs-property-table";
		const header = document.createElement("tr");
		header.className = "liste_titre";
		[labels.property, labels.dataType, labels.sale, labels.purchase, labels.multiple, labels.priority, labels.status, labels.actions].forEach(function(label) {
			const th = document.createElement("th");
			th.textContent = label;
			header.appendChild(th);
		});
		table.appendChild(header);

		relations.forEach(function(relation) {
			const id = Number(relation.cod_propriedade || relation.uid || 0);
			const definition = getDefinition(id);
			const row = document.createElement("tr");
			row.className = "oddeven";
			createCell(row, definition.nome + " (#" + id + ")");
			createCell(row, dataTypeLabel(definition.tipo_dados));
			createCell(row, usageLabel(Number(definition.venda)));
			createCell(row, usageLabel(Number(definition.compra)));
			createCell(row, Number(definition.valores_multiplos) === 1 ? labels.yes : labels.no);
			createCell(row, orderLabel(definition.ordenacao));

			const statusCell = document.createElement("td");
			const status = document.createElement("select");
			status.className = "flat";
			[[1, labels.active], [0, labels.inactive]].forEach(function(optionData) {
				const option = document.createElement("option");
				option.value = String(optionData[0]);
				option.textContent = optionData[1];
				option.selected = Number(relation.estado) === optionData[0];
				status.appendChild(option);
			});
			status.addEventListener("change", function() {
				relation.estado = Number(status.value) === 1 ? 1 : 0;
				syncHidden();
			});
			statusCell.appendChild(status);
			row.appendChild(statusCell);

			const actionsCell = document.createElement("td");
			actionsCell.className = "dzs-property-actions";
			const remove = document.createElement("button");
			remove.type = "button";
			remove.className = "button smallpaddingimp";
			remove.textContent = labels.remove;
			remove.addEventListener("click", function() {
				relations = relations.filter(function(item) {
					return Number(item.cod_propriedade || item.uid || 0) !== id;
				});
				syncHidden();
				render();
			});
			actionsCell.appendChild(remove);
			row.appendChild(actionsCell);
			table.appendChild(row);
		});

		wrapper.appendChild(table);
		relationsHost.appendChild(wrapper);
	}

	function renderPicker() {
		const previous = picker.value;
		picker.disabled = !canAddProperties;
		addButton.disabled = !canAddProperties;
		picker.replaceChildren();
		const assigned = new Set(relations.map(function(relation) {
			return Number(relation.cod_propriedade || relation.uid || 0);
		}));
		Object.keys(definitions).forEach(function(key) {
			const definition = getDefinition(key);
			const id = Number(definition.id || key);
			if (id <= 0 || Number(definition.ativo) !== 1 || assigned.has(id)) return;
			const option = document.createElement("option");
			option.value = String(id);
			option.textContent = definition.nome;
			picker.appendChild(option);
		});
		if (previous && Array.from(picker.options).some(function(option) { return option.value === previous; })) {
			picker.value = previous;
		}
		if (picker.options.length === 0) {
			const option = document.createElement("option");
			option.value = "";
			option.textContent = labels.noneAvailable;
			picker.appendChild(option);
			addButton.disabled = true;
		}
		renderDetails();
	}

	function render() {
		renderPicker();
		renderRelations();
	}

	picker.addEventListener("change", renderDetails);
	addButton.addEventListener("click", function() {
		const id = Number(picker.value || 0);
		if (id <= 0) return;
		relations.push({cod_produto: productCode, cod_propriedade: id, uid: id, estado: 1});
		syncHidden();
		render();
	});

	syncHidden();
	render();
})();
</script>
JS;
}

$isEditMode = ($action === 'edit');
$menuProductCodeForJs = ($zsCodigoForMenu !== '' ? $zsCodigoForMenu : ($object->ref ? $object->ref : $object->id));
$jsProductCode = json_encode($menuProductCodeForJs);
$jsFamilyOptions = json_encode($familyOptions);
$jsInitialState = json_encode($menuDecodedForJs);
$jsIsEditMode = $isEditMode ? 'true' : 'false';
// Use a relative URL so browser fetch keeps same-origin cookies (avoid login redirect issues)
$jsProductSearchUrl = DOL_URL_ROOT . '/custom/kreaflow/ajax/zsproducts_search.php?store=' . ((int) $storeZs) . '&term=';

$builderScript = <<<JS
<script>
	(function() {
		const isEditMode = {$jsIsEditMode};
		const builder = document.getElementById("mb-builder");
	const btnAddLevel = document.getElementById("mb-add-level");
			const btnClear = document.getElementById("mb-clear");
			const hidden = document.getElementById("DOLIZSYNCH_ZS_NIVEISMENU");
			const clearedFlag = document.getElementById("DOLIZSYNCH_ZS_NIVEISMENU_CLEARED");
			const productMenuCode = {$jsProductCode};
			const menuCode = (typeof productMenuCode === "string" && /^[0-9]+$/.test(productMenuCode)) ? parseInt(productMenuCode, 10) : productMenuCode;
			const familyOptions = {$jsFamilyOptions};
			const productSearchUrl = "{$jsProductSearchUrl}";
			const rowSecondary = document.getElementById("row-secondary-centres");
			const rowInformative = document.getElementById("row-informative-centres");
			const rowPrinters = document.getElementById("row-printer-centres");
			let state = [];
		let searchTimers = new WeakMap();
		let dirty = false;

		// Toggle visibility of centre blocks depending on primary selection (runs even if menu builder is hidden)
		function toggleCentreBlocks() {
			const compostoEl = document.getElementById("DOLIZSYNCH_ZS_COMPOSTO");
			const compostoCurrent = compostoEl ? String(compostoEl.value || "") : String(document.getElementById("DOLIZSYNCH_ZS_MENU_BLOCK")?.getAttribute("data-composto") || "");
			if (compostoCurrent === "2") {
				if (rowSecondary) rowSecondary.style.display = "none";
				if (rowInformative) rowInformative.style.display = "none";
				if (rowPrinters) rowPrinters.style.display = "none";
				console.log("[ZS Centres] skip toggle (menu composto=2) - hide all centre rows");
				return;
			}
			const primaryCentreField = document.getElementById("DOLIZSYNCH_ZS_PRIMARY_CENTRE");
			const rawVal = primaryCentreField ? primaryCentreField.value : null;
			const val = primaryCentreField ? parseInt(rawVal || "0", 10) : 0;
			if (val === -1) {
				if (rowSecondary) { rowSecondary.style.display = "none"; }
				if (rowInformative) { rowInformative.style.display = "none"; }
				if (rowPrinters) { rowPrinters.style.display = ""; }
			} else if (val > 0) {
				if (rowSecondary) { rowSecondary.style.display = ""; }
				if (rowInformative) { rowInformative.style.display = ""; }
				if (rowPrinters) { rowPrinters.style.display = "none"; }
			} else {
				if (rowSecondary) { rowSecondary.style.display = "none"; }
				if (rowInformative) { rowInformative.style.display = "none"; }
				if (rowPrinters) { rowPrinters.style.display = "none"; }
			}
		}

		function initCentreToggle() {
			const primaryCentreField = document.getElementById("DOLIZSYNCH_ZS_PRIMARY_CENTRE");
			if (primaryCentreField) {
				primaryCentreField.addEventListener("change", toggleCentreBlocks);
			}
			if (typeof window.jQuery !== "undefined") {
				window.jQuery(document).on("change select2:select select2:clear", "#DOLIZSYNCH_ZS_PRIMARY_CENTRE", toggleCentreBlocks);
			}
			window.addEventListener("load", toggleCentreBlocks);
			window.setTimeout(toggleCentreBlocks, 0);
			window.setTimeout(toggleCentreBlocks, 150);
			toggleCentreBlocks();
		}

		initCentreToggle();

		if (!hidden || !builder) {
			return;
		}

	// Try to hydrate state from PHP JSON or hidden field
	try {
		const parsed = {$jsInitialState};
		if (Array.isArray(parsed)) state = parsed;
	} catch (e) {}
		if ((!state || !state.length) && hidden.value) {
			try { const p = JSON.parse(hidden.value); if (Array.isArray(p)) state = p; }
			catch (e1) { try { const repaired = hidden.value.replace(/&quot;/g, '"'); const p2 = JSON.parse(repaired); if (Array.isArray(p2)) state = p2; } catch (e2) {} }
		}

		function markDirty() { dirty = true; if (clearedFlag) clearedFlag.value = "0"; }

	function escapeHtml(str){ if(str===null||str===undefined) return ""; return String(str).replace(/[&<>"']/g,c=>({"&":"&amp;","<":"&lt;",">":"&gt;","\"":"&quot;","'":"&#39;"}[c])); }
	function formatPriceForView(value){
		if (value === null || value === undefined) return "";
		const raw = String(value).trim();
		if (raw === "") return "";
		const normalized = raw.replace(",", ".");
		const num = parseFloat(normalized);
		if (isNaN(num)) return raw;
		return num.toFixed(2);
	}
	function familyLabelFor(code){
		if(!code) return "";
		const c = String(code);
		const exact = familyOptions.find(opt => String(opt.value) === c);
		if (exact) return exact.label;
		const familyCode = c.indexOf("_") >= 0 ? c.split("_")[0] : c;
		const base = familyOptions.find(opt => String(opt.value) === (familyCode + "_0"));
		if (base) return base.label;
		const any = familyOptions.find(opt => String(opt.value).startsWith(familyCode + "_"));
		return any ? any.label : "";
	}
	function familySelectValueForCodigo(code){
		if(!code) return "";
		const c = String(code);
		if (c.indexOf("_") >= 0) return c;
		const base = familyOptions.find(opt => String(opt.value) === (c + "_0"));
		if (base) return String(base.value);
		const any = familyOptions.find(opt => String(opt.value).startsWith(c + "_"));
		return any ? String(any.value) : "";
	}

			function normalizeLevel(level, idx){
				const safe = Object.assign({descricao:"", menu: menuCode, nivel: idx+1, obrigatorio:0, ordem:0, niveismenuext:[], _collapsed:0}, level||{});
				safe.nivel = idx+1;
				safe.menu = menuCode;
				safe.obrigatorio = safe.nivel===1 ? 1 : (safe.obrigatorio ? 1 : 0);
				safe.ordem = parseInt(safe.ordem,10); if(isNaN(safe.ordem)) safe.ordem = 0;
				if(!Array.isArray(safe.niveismenuext)) safe.niveismenuext = [];
				// Always keep 1 empty row available in UI (even if nothing is saved yet)
			if(!safe.niveismenuext.length){
				safe.niveismenuext = [{codigo:"", descricao:"", preco:"", fixo:1, opcaopvp:0, valcomplementos:1, produtodefault:0}];
			}
			return safe;
		}
			function normalizeItem(item, level){
				const safe = Object.assign({codigo:"", descricao:"", preco:"", fixo:1, opcaopvp:0, valcomplementos:1, produtodefault:0, menu:level.menu, nivel:level.nivel}, item||{});
				safe.fixo = parseInt(safe.fixo,10); if(isNaN(safe.fixo)||[0,1,2].indexOf(safe.fixo)===-1) safe.fixo=1;
				safe.opcaopvp = parseInt(safe.opcaopvp,10); if(isNaN(safe.opcaopvp)) safe.opcaopvp=0;
				safe.valcomplementos = safe.valcomplementos ? 1 : 0;
				safe.produtodefault = safe.produtodefault ? 1 : 0;
				// Force menu/nivel to match the parent level (important when reordering)
				safe.menu = level.menu;
				safe.nivel = level.nivel;
				// Normalize codigo to numeric where possible (except family/subfamily composite codes like "44_18")
				let codigoStr = String(safe.codigo || "").trim();
				if (codigoStr !== "" && /^[0-9]+$/.test(codigoStr)) {
					safe.codigo = parseInt(codigoStr, 10);
				} else {
					safe.codigo = codigoStr;
				}
				return safe;
			}
		function isEmptyItem(item){
			if(!item) return true;
			const codigo = String(item.codigo || "").trim();
			const descricao = String(item.descricao || "").trim();
			const preco = String(item.preco || "").trim();
			const hasPrice = preco !== "" && preco !== "0" && preco !== "0.0" && preco !== "0.00";
			const op = parseInt(item.opcaopvp, 10);
			const hasPvp = !isNaN(op) && op >= 1 && op <= 10;
			return codigo === "" && descricao === "" && !hasPrice && !hasPvp;
		}

	function cleanedState(){
		return state.map((lvl, idx)=>{
			const L = normalizeLevel(lvl, idx);
				const items = [];
				L.niveismenuext.forEach(it=>{
					const n = normalizeItem(it, L);
					if(n.codigo==="") return;
					let pvpNum = null;
					const op = parseInt(n.opcaopvp, 10);
					if (op === 1) {
						const pvp = parseInt(n.preco, 10);
						if (!isNaN(pvp) && pvp >= 1 && pvp <= 10) {
							// Legacy representation: opcaopvp=1 and preco carries PVP table number.
							pvpNum = pvp;
						} else {
							// Current representation for explicit PVP 1 with empty/zero numeric price.
							pvpNum = 1;
						}
					} else if (!isNaN(op) && op >= 1 && op <= 10) {
						// Current representation: opcaopvp carries the selected PVP table (1..10).
						pvpNum = op;
					}
					if(pvpNum!==null){
						n.opcaopvp = pvpNum;
						// When PVP is selected, keep numeric price neutral.
						n.preco = 0;
					} else {
						n.opcaopvp = 0;
						const euro = parseFloat(n.preco);
						n.preco = (n.preco === "" || isNaN(euro)) ? 0 : euro;
					}
					items.push(n);
				});
			return {descricao:L.descricao, menu:L.menu, nivel:L.nivel, obrigatorio:L.obrigatorio, ordem:L.ordem, niveismenuext: items};
		}).filter(l=>l.descricao!=="" || (l.niveismenuext && l.niveismenuext.length));
	}
	function syncHidden(force){ if(!hidden) return; if(!force && !dirty) return; hidden.value = JSON.stringify(cleanedState()); }
		function fetchProductLabel(code, idx, iIdx, row){
			if(!code) return;
			fetch(productSearchUrl + encodeURIComponent(code))
				.then(r=>{ if(!r.ok) throw new Error("Product search failed"); return r.json(); })
			.then(list=>{
				if(!Array.isArray(list) || !list.length) return;
				const first = list.find(it => it && (it.ref===code || (it.label && it.label.indexOf(code)>=0))) || list[0];
				const label = first && (first.label || first.value) ? (first.label || first.value) : "";
					if(!label) return;
					if(state[idx] && state[idx].niveismenuext[iIdx]) state[idx].niveismenuext[iIdx].descricao = label;
					if(row){ const descInput = row.querySelector('input[data-role="descricao"]'); if(descInput) descInput.value = label; }
					if (isEditMode) syncHidden(true);
				})
				.catch(()=>{});
		}

		function render(){
				builder.innerHTML = "";
				if(!state.length){
					const empty = document.createElement("div"); empty.className="mb-builder-empty"; empty.textContent="Sem níveis definidos. Adicione um nível para começar."; builder.appendChild(empty); if (isEditMode) syncHidden(true); return;
				}
				state.forEach((lvl, idx)=>{
					const L = normalizeLevel(lvl, idx); state[idx]=L;
					const card = document.createElement("div"); card.className="mb-card"; card.dataset.levelIndex=String(idx);
					const head = document.createElement("div"); head.className="mb-head";
					const upDisabled = (idx <= 0) ? " disabled" : "";
					const downDisabled = (idx >= state.length - 1) ? " disabled" : "";
					const ro = isEditMode ? "" : ' readonly="readonly"';
					const dis = isEditMode ? "" : " disabled";
					const obrigatorioDisabled = (L.nivel === 1 || !isEditMode) ? " disabled" : "";
					head.innerHTML = '<span class="mb-card-title">Nível #' + escapeHtml(L.nivel) + '</span>'
						+ '<input type="text" class="flat" data-role="level-descricao" value="' + escapeHtml(L.descricao) + '" placeholder="Descrição do nível"' + ro + '>'
						+ '<label><input type="checkbox" data-role="obrigatorio"' + (L.obrigatorio ? ' checked' : '') + obrigatorioDisabled + '> Obrigatório</label>'
						+ '<label style="display:flex; align-items:center; gap:4px;">Ordem <input type="number" data-role="ordem" class="flat" value="' + escapeHtml(L.ordem) + '" style="width:70px;"' + ro + '></label>'
						+ (isEditMode ? (
							'<div class="mb-head-actions">'
								+ '<button type="button" class="mb-icon-btn" data-role="move-up" title="Subir nível"' + upDisabled + '><span class="fas fa-arrow-up"></span></button>'
								+ '<button type="button" class="mb-icon-btn" data-role="move-down" title="Descer nível"' + downDisabled + '><span class="fas fa-arrow-down"></span></button>'
								+ '<button type="button" class="button smallpaddingimp" data-role="remove-level" title="Remover nível">Remover nível</button>'
							+ '</div>'
						) : '');
					card.appendChild(head);

			const itemsWrap = document.createElement("div"); itemsWrap.className="mb-items";
					const headerRow = document.createElement("div"); headerRow.className="mb-item mb-item-header";
					headerRow.innerHTML =
						'<div>Tipo</div>' +
						'<div>Produto/Família</div>' +
						'<div>Preço (€)</div>' +
						'<div>PVP</div>' +
						'<div>Compl.</div>' +
						'<div>Predef.</div>' +
						'<div>Ações</div>';
					itemsWrap.appendChild(headerRow);
					L.niveismenuext.forEach((it,iIdx)=>{
						const I = normalizeItem(it, L); L.niveismenuext[iIdx]=I;
					let pvpNum = "";
					const op = parseInt(I.opcaopvp, 10);
					if (op === 1) {
						const pvp = parseInt(I.preco, 10);
						if (!isNaN(pvp) && pvp >= 1 && pvp <= 10) {
							pvpNum = pvp;
						} else {
							// When PVP mode is enabled and preco is empty/zero, keep PVP 1 selected in UI.
							pvpNum = 1;
						}
					} else if (!isNaN(op) && op >= 1 && op <= 10) {
						// Legacy/defensive: opcaopvp sometimes carries the PVP table number
						pvpNum = op;
					}
					const priceDisabled = pvpNum ? " disabled" : "";
						const priceValue = pvpNum ? "" : formatPriceForView(I.preco);
					if(I.fixo===2 && (!I.descricao || I.descricao==="")){ const lbl=familyLabelFor(I.codigo); if(lbl) I.descricao=lbl; }
					const selectedFamilyVal = familySelectValueForCodigo(I.codigo);
					const familySelect = '<select data-role="family-select" class="flat"' + (isEditMode ? '' : ' disabled') + '><option value=""></option>' + familyOptions.map(opt=>'<option value="' + escapeHtml(opt.value) + '"' + (String(opt.value)===String(selectedFamilyVal) ? ' selected' : '') + '>' + escapeHtml(opt.label) + '</option>').join("") + '</select>';
						const isProductPicker = (I.fixo===0 || I.fixo===1);
					const prodPickerDisplay = isProductPicker ? "grid" : "none";
					const familyPickerDisplay = isProductPicker ? "none" : "block";

						const removeDisabled = (L.niveismenuext.length === 1 && isEmptyItem(I)) ? " disabled" : "";
						const rowActions = isEditMode
							? (
								'<div class="mb-remove">' +
									'<button type="button" class="mb-icon-btn" data-role="add-item" title="Adicionar item"><span class="fas fa-plus" style="color:#28a745;"></span></button>' +
									'<button type="button" class="mb-icon-btn" data-role="remove-item" title="Remover item"' + removeDisabled + '><span class="fas fa-minus" style="color:#dc3545;"></span></button>' +
								'</div>'
							)
							: '<div class="mb-remove"></div>';
							const row = document.createElement("div"); row.className="mb-item"; row.dataset.itemIndex=iIdx.toString();
							row.innerHTML =
							'<select data-role="fixo" class="flat" title="Tipo"' + dis + '>' +
							'<option value="1"' + ((I.fixo===1 || I.fixo===0) ? ' selected' : '') + '>Produto</option>' +
							'<option value="2"' + (I.fixo===2 ? ' selected' : '') + '>Família</option>' +
							'</select>' +
						'<div class="mb-picker">' +
								'<div class="mb-prod-picker" style="display:' + prodPickerDisplay + ';">' +
									'<input type="text" class="flat" data-role="codigo" value="' + escapeHtml(I.codigo) + '" placeholder="Código" readonly="readonly">' +
									'<div class="mb-desc-wrap">' +
										'<input type="text" class="flat mb-search" data-role="descricao" value="' + escapeHtml(I.descricao) + '" placeholder="Descrição (buscar ref/label)"' + ro + '>' +
										'<div class="mb-suggest"></div>' +
									'</div>' +
								'</div>' +
							'<div class="mb-family-picker" style="display:' + familyPickerDisplay + ';">' + familySelect + '</div>' +
						'</div>' +
						'<input type="number" step="0.01" class="flat" data-role="preco" value="' + priceValue + '" placeholder="Preço (€)"' + priceDisabled + ro + '>' +
						'<select data-role="pvp" class="flat" title="Tabela PVP"' + dis + '>' +
							'<option value=""' + (pvpNum==="" ? ' selected' : '') + '>PVP nenhum</option>' +
							'<option value="1"' + (pvpNum===1 ? ' selected' : '') + '>PVP 1</option>' +
							'<option value="2"' + (pvpNum===2 ? ' selected' : '') + '>PVP 2</option>' +
							'<option value="3"' + (pvpNum===3 ? ' selected' : '') + '>PVP 3</option>' +
						'<option value="4"' + (pvpNum===4 ? ' selected' : '') + '>PVP 4</option>' +
						'<option value="5"' + (pvpNum===5 ? ' selected' : '') + '>PVP 5</option>' +
						'<option value="6"' + (pvpNum===6 ? ' selected' : '') + '>PVP 6</option>' +
						'<option value="7"' + (pvpNum===7 ? ' selected' : '') + '>PVP 7</option>' +
						'<option value="8"' + (pvpNum===8 ? ' selected' : '') + '>PVP 8</option>' +
							'<option value="9"' + (pvpNum===9 ? ' selected' : '') + '>PVP 9</option>' +
							'<option value="10"' + (pvpNum===10 ? ' selected' : '') + '>PVP 10</option>' +
						'</select>' +
						'<label title="Permite complementos"><input type="checkbox" data-role="valcomplementos"' + (I.valcomplementos ? ' checked' : '') + dis + '> Compl.</label>' +
							'<label title="Item predefinido"><input type="checkbox" data-role="produtodefault"' + (I.produtodefault ? ' checked' : '') + dis + '> Predef.</label>' +
							rowActions;
						itemsWrap.appendChild(row);
						if ((I.fixo === 0 || I.fixo === 1) && I.codigo) {
							const codeStr = String(I.codigo);
							const descStr = String(I.descricao || "").trim();
						if (descStr === "" || descStr === codeStr) {
							fetchProductLabel(I.codigo, idx, iIdx, row);
						}
					}
				});
				card.appendChild(itemsWrap);
				builder.appendChild(card);
				});
				// Always push current state into hidden so saving never writes empty
				if (isEditMode) syncHidden(true);
			}

	function handleInputChange(ev){
		const role = ev.target.getAttribute("data-role"); if(!role) return;
		const card = ev.target.closest(".mb-card"); const idx = card ? parseInt(card.dataset.levelIndex||"-1",10) : -1; if(idx<0 || !state[idx]) return;
		if(role==="level-descricao"){ state[idx].descricao = ev.target.value; markDirty(); }
		if(role==="obrigatorio"){ state[idx].obrigatorio = ev.target.checked ? 1 : 0; if(state[idx].nivel===1){ state[idx].obrigatorio=1; ev.target.checked=true; } markDirty(); }
		if(role==="ordem"){ state[idx].ordem = ev.target.value; markDirty(); }
			const row = ev.target.closest(".mb-item");
			if(row){
				const iIdx = parseInt(row.dataset.itemIndex,10); const item = state[idx].niveismenuext[iIdx]; if(!item) return;
			if(role==="codigo"){ item.codigo = ev.target.value; markDirty(); if((item.fixo===0 || item.fixo===1) && item.codigo && (!item.descricao || item.descricao.trim()==="")) fetchProductLabel(item.codigo, idx, iIdx, row); }
				if(role==="descricao"){
					item.descricao = ev.target.value;
					// If user typed a code/ref in the description field, treat it as a ref search and bind it to codigo
					if ((item.fixo === 0 || item.fixo === 1) && ev.type === "change") {
						const m = String(ev.target.value || "").match(/^#?\s*([0-9]+)/);
						if (m && m[1]) {
							const code = m[1];
							if (code && String(item.codigo || "") !== code) {
								item.codigo = code;
								const codeInput = row.querySelector('input[data-role="codigo"]');
								if (codeInput) codeInput.value = code;
								fetchProductLabel(code, idx, iIdx, row);
							}
						}
					}
					markDirty();
				}
				if(role==="preco"){ item.preco = ev.target.value; item.opcaopvp=0; const pvpSel=row.querySelector('select[data-role="pvp"]'); if(pvpSel) pvpSel.value=""; ev.target.disabled=false; if(ev.type==="change"){ const formatted = formatPriceForView(ev.target.value); ev.target.value = formatted; item.preco = formatted; } markDirty(); }
				if(role==="pvp"){ const v=parseInt(ev.target.value,10); const price=row.querySelector('input[data-role="preco"]'); if(!isNaN(v)&&v>=1&&v<=10){ item.opcaopvp=v; item.preco=0; if(price){price.value=""; price.disabled=true;} } else { item.opcaopvp=0; if(price){price.disabled=false;} } markDirty(); }
			if(role==="fixo"){ item.fixo=parseInt(ev.target.value,10); if(isNaN(item.fixo)) item.fixo=1; const prod=row.querySelector(".mb-prod-picker"); const fam=row.querySelector(".mb-family-picker"); if(prod&&fam){ if(item.fixo===2){prod.style.display="none"; fam.style.display="block"; const lbl=familyLabelFor(item.codigo); if(lbl){ item.descricao=lbl; const desc=row.querySelector('input[data-role="descricao"]'); if(desc) desc.value=lbl; } } else {prod.style.display="grid"; fam.style.display="none";} } markDirty(); }
				if(role==="family-select"){ const sel=ev.target; const val=sel.value; const label=sel.options && sel.selectedIndex>=0 ? sel.options[sel.selectedIndex].text : ""; item.codigo = (val ? String(val) : ""); item.descricao=label; markDirty(); syncHidden(true); return; }
				if(role==="valcomplementos") item.valcomplementos = ev.target.checked ? 1 : 0;
				if(role==="produtodefault"){ item.produtodefault = ev.target.checked ? 1 : 0; if(item.produtodefault){ state[idx].niveismenuext.forEach((it2,j)=>{ if(j!==iIdx) it2.produtodefault=0; }); render(); return; } }
			}
				syncHidden(true);
			}

		if (isEditMode) {
			builder.addEventListener("input", handleInputChange);
			builder.addEventListener("change", handleInputChange);

			builder.addEventListener("click", function(ev){
				const roleEl = ev.target.closest("[data-role]"); const role = roleEl ? roleEl.getAttribute("data-role") : null; if(!role) return;
				const card = roleEl.closest(".mb-card"); const idx = card ? parseInt(card.dataset.levelIndex||"-1",10) : -1; if(idx<0 || !state[idx]) return;
			if(role==="move-up"){
				if(idx > 0){
					const tmp = state[idx - 1];
					state[idx - 1] = state[idx];
					state[idx] = tmp;
					dirty = true;
					render();
				}
				return;
			}
			if(role==="move-down"){
				if(idx < state.length - 1){
					const tmp = state[idx + 1];
					state[idx + 1] = state[idx];
					state[idx] = tmp;
					dirty = true;
					render();
				}
				return;
			}
			if(role==="add-item"){ state[idx].niveismenuext.push({codigo:"", descricao:"", preco:"", fixo:1, opcaopvp:0, valcomplementos:1, produtodefault:0}); render(); return; }
			if(role==="remove-level"){ state.splice(idx,1); render(); return; }
			if(role==="remove-item"){
				const row=roleEl.closest(".mb-item"); if(!row) return;
				const iIdx=parseInt(row.dataset.itemIndex,10);
				if (state[idx].niveismenuext.length <= 1) {
					// Keep one empty row so user never ends up with an empty level
					state[idx].niveismenuext[0] = {codigo:"", descricao:"", preco:"", fixo:1, opcaopvp:0, valcomplementos:1, produtodefault:0};
				} else {
					state[idx].niveismenuext.splice(iIdx,1);
				}
				dirty = true;
				render();
					return;
				}
			});

		builder.addEventListener("input", function(ev){
		const role = ev.target.getAttribute("data-role"); if(role!=="descricao") return;
		const row = ev.target.closest(".mb-item"); if(!row) return;
		const itemIdx = parseInt(row.dataset.itemIndex,10);
		const card = ev.target.closest(".mb-card");
		const idx = card ? parseInt(card.dataset.levelIndex||"-1",10) : -1;
		const item = (idx>=0 && state[idx]) ? state[idx].niveismenuext[itemIdx] : null;
		const term = ev.target.value.trim();
		const suggestBox = row.querySelector(".mb-suggest");
		if(!suggestBox || !item || (item.fixo!==0 && item.fixo!==1)) return;
		if(term.length<2){ suggestBox.classList.remove("visible"); suggestBox.innerHTML=""; return; }
		if(searchTimers.has(ev.target)) clearTimeout(searchTimers.get(ev.target));
		const t = setTimeout(()=>{
			fetch(productSearchUrl + encodeURIComponent(term))
				.then(r=>{ if(!r.ok) throw new Error("Product search failed"); return r.json(); })
				.then(list=>{
					if(!Array.isArray(list)){ suggestBox.classList.remove("visible"); suggestBox.innerHTML=""; return; }
					let html=""; list.forEach(it=>{ const ref=it && it.ref ? String(it.ref) : ""; const desc=it && it.label ? String(it.label) : (it && it.value ? String(it.value) : ""); if(!ref && !desc) return; html += '<div class="mb-sug-item" data-ref="' + escapeHtml(ref) + '" data-desc="' + escapeHtml(desc) + '">' + escapeHtml(ref) + ' - ' + escapeHtml(desc) + '</div>'; });
					if(!html) html = '<div class="mb-sug-empty">Sem resultados</div>';
					suggestBox.innerHTML = html; suggestBox.classList.add("visible");
				})
				.catch(()=>{ suggestBox.innerHTML = '<div class="mb-sug-empty">Erro a procurar</div>'; suggestBox.classList.add("visible"); });
		},250);
			searchTimers.set(ev.target, t);
		});

		builder.addEventListener("click", function(ev){
		const sugItem = ev.target.closest(".mb-sug-item"); if(!sugItem) return;
		const rowEl = sugItem.closest(".mb-item"); if(!rowEl) return;
		const ref = sugItem.getAttribute("data-ref") || ""; const desc = sugItem.getAttribute("data-desc") || "";
		const codeInput = rowEl.querySelector('input[data-role="codigo"]'); const descInput = rowEl.querySelector('input[data-role="descricao"]'); const suggestBox = rowEl.querySelector(".mb-suggest");
		const card = rowEl.closest(".mb-card"); const idx = card ? parseInt(card.dataset.levelIndex||"-1",10) : -1; const iIdx = parseInt(rowEl.dataset.itemIndex,10);
			if(codeInput) codeInput.value = ref; if(descInput) descInput.value = desc; if(suggestBox){ suggestBox.classList.remove("visible"); suggestBox.innerHTML=""; }
			if(idx>=0 && state[idx] && !isNaN(iIdx)){ state[idx].niveismenuext[iIdx].codigo = ref; state[idx].niveismenuext[iIdx].descricao = desc; markDirty(); syncHidden(true); }
		});
	}

				if(btnAddLevel){
					btnAddLevel.addEventListener("click", function(){
						const nextIdx = state.length;
						state.push({
						descricao: "",
						obrigatorio: 1,
						ordem: 0,
						menu: productMenuCode,
						nivel: nextIdx + 1,
						niveismenuext: [
							{codigo: "", descricao: "", preco: "", fixo: 1, opcaopvp: 0, valcomplementos: 1, produtodefault: 0}
						],
						_collapsed: 0
					});
					dirty = true;
					render();
				});
			}
		if(btnClear){ btnClear.addEventListener("click", function(){ if(confirm("Limpar todos os níveis?")){ state=[]; dirty=true; if(clearedFlag) clearedFlag.value="1"; render(); } }); }

		// Force sync before submitting the form to avoid saving empty data
			const form = hidden.closest("form");
			if(form && isEditMode){
				form.addEventListener("submit", function(){ syncHidden(true); });
			}

		render();
	})();
	</script>
JS;
print $builderScript;

// Toggle menu builder row visibility when user changes "Tipo de Produto (Menu, Venda, Mercadoria)" (composto)
print '<script>
(function(){
	const menuRow = document.getElementById("DOLIZSYNCH_ZS_MENU_BLOCK");
	if (!menuRow) return;
	const compostoSelect = document.getElementById("DOLIZSYNCH_ZS_COMPOSTO");
	const rowPrimary = document.getElementById("row-primary-centre");
	const rowSecondary = document.getElementById("row-secondary-centres");
	const rowInformative = document.getElementById("row-informative-centres");
	const rowPrinters = document.getElementById("row-printer-centres");
	const primarySelect = document.getElementById("DOLIZSYNCH_ZS_PRIMARY_CENTRE");
	const isMenu = (menuRow.getAttribute("data-composto") === "2");

	function showCentreRowsBasedOnPrimary() {
		const primarySelectEl = document.getElementById("DOLIZSYNCH_ZS_PRIMARY_CENTRE");
		const val = primarySelectEl ? parseInt(primarySelectEl.value || "0", 10) : 0;
		if (rowPrimary) rowPrimary.style.display = "";
		if (val === -1) {
			if (rowSecondary) rowSecondary.style.display = "none";
			if (rowInformative) rowInformative.style.display = "none";
			if (rowPrinters) rowPrinters.style.display = "";
		} else if (val > 0) {
			if (rowSecondary) rowSecondary.style.display = "";
			if (rowInformative) rowInformative.style.display = "";
			if (rowPrinters) rowPrinters.style.display = "none";
		} else {
			if (rowSecondary) rowSecondary.style.display = "none";
			if (rowInformative) rowInformative.style.display = "none";
			if (rowPrinters) rowPrinters.style.display = "none";
		}
	}

	function toggleMenuRow(){
		const current = compostoSelect ? String(compostoSelect.value || "") : String(menuRow.getAttribute("data-composto") || "");
		menuRow.style.display = (current === "2") ? "" : "none";
		// Hide all centre rows when menu is selected
		if (current === "2") {
			if (rowPrimary) rowPrimary.style.display = "none";
			if (rowSecondary) rowSecondary.style.display = "none";
			if (rowInformative) rowInformative.style.display = "none";
			if (rowPrinters) rowPrinters.style.display = "none";
		} else {
			showCentreRowsBasedOnPrimary();
		}
	}
		function handlePrimaryChange() {
			const current = compostoSelect ? String(compostoSelect.value || "") : String(menuRow.getAttribute("data-composto") || "");
			if (current !== "2") {
				showCentreRowsBasedOnPrimary();
			}
		}
		if (compostoSelect) compostoSelect.addEventListener("change", toggleMenuRow);
		if (primarySelect) primarySelect.addEventListener("change", handlePrimaryChange);
		if (typeof window.jQuery !== "undefined") {
			window.jQuery("#DOLIZSYNCH_ZS_COMPOSTO").on("select2:select select2:clear change", toggleMenuRow);
			window.jQuery(document).on("change select2:select select2:clear", "#DOLIZSYNCH_ZS_PRIMARY_CENTRE", handlePrimaryChange);
		}
	// Run once at startup
	toggleMenuRow();
})();
</script>';

print '<div class="center" style="margin-top: 12px;">';
if ($action === 'edit') {
	print '<input type="submit" class="button button-save" value="' . $langs->trans('Save') . '">';
	print '&nbsp;';
	print '<a class="button" href="' . $_SERVER['PHP_SELF'] . '?id=' . $object->id . '">' . $langs->trans('Cancel') . '</a>';
} elseif ($canEditProduct) {
	print '<a class="button" href="' . $_SERVER['PHP_SELF'] . '?id=' . $object->id . '&action=edit">' . $langs->trans('Modify') . '</a>';
}
print '</div>';

print '</form>';

dol_fiche_end();

llxFooter();
$db->close();

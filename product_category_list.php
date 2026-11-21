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
 * \file    dolizsynch/product_category_list.php
 * \ingroup dolizsynch
 * \brief   Products on sale grouped by selected ZSBMS families.
 */

$res = 0;
if (!$res && !empty($_SERVER['CONTEXT_DOCUMENT_ROOT'])) {
	$res = @include $_SERVER['CONTEXT_DOCUMENT_ROOT'] . '/main.inc.php';
}
$tmp = empty($_SERVER['SCRIPT_FILENAME']) ? '' : $_SERVER['SCRIPT_FILENAME'];
$tmp2 = realpath(__FILE__);
$i = strlen($tmp) - 1;
$j = strlen($tmp2) - 1;
while ($i > 0 && $j > 0 && isset($tmp[$i]) && isset($tmp2[$j]) && $tmp[$i] === $tmp2[$j]) {
	$i--;
	$j--;
}
if (!$res && $i > 0 && file_exists(substr($tmp, 0, ($i + 1)) . '/main.inc.php')) {
	$res = @include substr($tmp, 0, ($i + 1)) . '/main.inc.php';
}
if (!$res && $i > 0 && file_exists(dirname(substr($tmp, 0, ($i + 1))) . '/main.inc.php')) {
	$res = @include dirname(substr($tmp, 0, ($i + 1))) . '/main.inc.php';
}
if (!$res && file_exists('../main.inc.php')) {
	$res = @include '../main.inc.php';
}
if (!$res && file_exists('../../main.inc.php')) {
	$res = @include '../../main.inc.php';
}
if (!$res) {
	die('Include of main fails');
}

require_once DOL_DOCUMENT_ROOT . '/core/class/html.form.class.php';

$langs->loadLangs(array('main', 'products', 'dolizsynch@kreaflow'));

if (empty($conf->dolizsynch->enabled)) {
	accessforbidden($langs->trans('ErrorModuleNotEnabled'));
}
if (empty($user->rights->produit->lire) && empty($user->admin)) {
	accessforbidden();
}

/**
 * Check whether a table exists.
 *
 * @param DoliDB $db Database handler.
 * @param string $tableShortName Table short name without prefix.
 * @return bool
 */
function dzs_table_exists($db, $tableShortName)
{
	$sql = "SHOW TABLES LIKE '" . $db->escape(MAIN_DB_PREFIX . $tableShortName) . "'";
	$resql = $db->query($sql);
	if (!$resql) {
		return false;
	}
	$exists = ($db->num_rows($resql) > 0);
	$db->free($resql);

	return $exists;
}

/**
 * Normalize Dolibarr category id list.
 *
 * @param array|string $rawValues Raw values.
 * @return int[]
 */
function dzs_sanitize_category_ids($rawValues)
{
	$out = array();
	if (!is_array($rawValues)) {
		$rawValues = strlen(trim((string) $rawValues)) ? explode(',', (string) $rawValues) : array();
	}
	foreach ($rawValues as $rawValue) {
		$categoryId = (int) $rawValue;
		if ($categoryId > 0) {
			$out[$categoryId] = $categoryId;
		}
	}

	return array_values($out);
}

/**
 * Parse ZSBMS family key.
 *
 * @param string $key Key in format "store:family:subfamily".
 * @param int    $store Output store id.
 * @param int    $family Output family id.
 * @param int    $subfamily Output subfamily id.
 * @return bool
 */
function dzs_parse_zs_category_key($key, &$store, &$family, &$subfamily)
{
	$key = trim((string) $key);
	if (!preg_match('/^([0-9]+):([0-9]+):([0-9]+)$/', $key, $matches)) {
		return false;
	}

	$store = (int) $matches[1];
	$family = (int) $matches[2];
	$subfamily = (int) $matches[3];

	return ($store > 0 && $family > 0 && $subfamily >= 0);
}

/**
 * Build ZSBMS family key.
 *
 * @param int $store Store id.
 * @param int $family Family id.
 * @param int $subfamily Subfamily id.
 * @return string
 */
function dzs_build_zs_category_key($store, $family, $subfamily)
{
	return ((int) $store) . ':' . ((int) $family) . ':' . ((int) $subfamily);
}

/**
 * Normalize ZSBMS family key list.
 *
 * @param array|string $rawValues Raw values.
 * @return string[]
 */
function dzs_sanitize_zs_category_keys($rawValues)
{
	$out = array();
	if (!is_array($rawValues)) {
		$rawValues = strlen(trim((string) $rawValues)) ? explode(',', (string) $rawValues) : array();
	}
	foreach ($rawValues as $rawValue) {
		$store = 0;
		$family = 0;
		$subfamily = 0;
		if (dzs_parse_zs_category_key((string) $rawValue, $store, $family, $subfamily)) {
			$key = dzs_build_zs_category_key($store, $family, $subfamily);
			$out[$key] = $key;
		}
	}

	return array_values($out);
}

/**
 * Build URL parameter string for page links.
 *
 * @param string[] $categoryKeys Selected ZSBMS family keys.
 * @param int      $editMode Edit mode flag.
 * @return string
 */
function dzs_build_category_params($categoryKeys, $editMode)
{
	$param = '&editmode=' . ((int) $editMode);
	foreach ($categoryKeys as $categoryKey) {
		$param .= '&categories[]=' . urlencode((string) $categoryKey);
	}

	return $param;
}

/**
 * Build a DOM-safe id fragment.
 *
 * @param string $value Raw value.
 * @return string
 */
function dzs_build_dom_id_fragment($value)
{
	$out = preg_replace('/[^a-zA-Z0-9_-]+/', '-', (string) $value);
	$out = trim((string) $out, '-');

	return ($out !== '') ? $out : 'list';
}

/**
 * Format TTC price with VAT included suffix.
 *
 * @param string|float|null $value Value to format.
 * @param Translate         $langs Translation handler.
 * @return string
 */
function dzs_format_price_ttc($value, $langs)
{
	if ($value === null || $value === '') {
		return '';
	}

	return '<span class="amount">' . price($value) . ' <span class="dzs-vat-suffix">(C/IVA)</span></span>';
}

/**
 * Return true when a retalho flag value is enabled.
 *
 * @param mixed $value Raw value.
 * @return bool
 */
function dzs_is_retalho_enabled_value($value)
{
	if (is_bool($value)) {
		return $value;
	}
	if (is_numeric($value)) {
		return ((int) $value === 1);
	}

	return (trim((string) $value) === '1');
}

/**
 * Check whether a store exists in product_company CSV list.
 *
 * @param string|null $productCompany Raw product_company value.
 * @param int         $store Store id.
 * @return bool
 */
function dzs_store_in_product_company($productCompany, $store)
{
	$raw = trim((string) $productCompany);
	if ($raw === '') {
		return false;
	}

	$store = (int) $store;
	if ($store <= 0) {
		return false;
	}

	$parts = explode(',', $raw);
	foreach ($parts as $part) {
		$part = trim($part);
		if ($part !== '' && ctype_digit($part) && ((int) $part) === $store) {
			return true;
		}
	}

	return false;
}

/**
 * Check whether retalho visibility is enabled for a given store.
 *
 * Supports both legacy scalar storage (`1`) and JSON map storage
 * (`{"12":"1","13":"0"}` / `{"12":1}`).
 *
 * @param string|null $retalhoByStore Raw retalho_by_store field content.
 * @param int         $store Store id.
 * @param string|null $productCompany Raw product_company CSV field.
 * @param int         $originStore Origin `loja_zs` from zsproduct row.
 * @return bool
 */
function dzs_is_retalho_visible_for_store($retalhoByStore, $store, $productCompany = '', $originStore = 0)
{
	$raw = trim((string) $retalhoByStore);
	$store = (int) $store;
	$originStore = (int) $originStore;

	$storeInCompany = dzs_store_in_product_company($productCompany, $store);
	$productCompanyMissing = (trim((string) $productCompany) === '');
	$retalhoMissing = ($raw === '' || $raw === '[]' || $raw === '{}');

	if ($retalhoMissing) {
		if ($storeInCompany) {
			// Mirror product-card default: missing store key means "sold" for selected product_company stores.
			return true;
		}
		// Legacy fallback for old rows without product_company/retalho map:
		// use origin store only.
		if ($productCompanyMissing && $originStore > 0) {
			return ($originStore === $store);
		}
		return false;
	}

	if (dzs_is_retalho_enabled_value($raw)) {
		return true;
	}

	$decoded = json_decode($raw, true);
	if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded) || empty($decoded)) {
		return false;
	}

	$storeKey = (string) $store;
	if (array_key_exists($storeKey, $decoded)) {
		return dzs_is_retalho_enabled_value($decoded[$storeKey]);
	}

	if ($storeInCompany) {
		// Compatibility with rows that have product_company store but no explicit retalho map key.
		return true;
	}

	// Compatibility fallback for historic rows with a single-store JSON map
	// keyed by an unexpected identifier.
	if (count($decoded) === 1) {
		$singleValue = reset($decoded);
		return dzs_is_retalho_enabled_value($singleValue);
	}

	return false;
}

/**
 * Fetch ZSBMS family options and metadata.
 *
 * @param DoliDB $db Database handler.
 * @param int    $entity Current entity.
 * @param int    $storeProducts Current entity ZSBMS store.
 * @param array  $metaOut Output metadata keyed by ZSBMS family key.
 * @return array<string,string>
 */
function dzs_get_zs_family_options($db, $entity, $storeProducts, &$metaOut)
{
	$options = array();
	$metaOut = array();

	$sql = 'SELECT DISTINCT loja_zs, family_id, subfamily_id, family_name, subfamily_name';
	$sql .= ' FROM ' . MAIN_DB_PREFIX . 'dolizsynch_zsfamily';
	$sql .= ' WHERE entity IN (0, ' . ((int) $entity) . ')';
	if ((int) $storeProducts > 0) {
		$sql .= ' AND loja_zs = ' . ((int) $storeProducts);
	}
	$sql .= ' ORDER BY loja_zs ASC, family_name ASC, subfamily_name ASC, family_id ASC, subfamily_id ASC';

	$resql = $db->query($sql);
	if ($resql) {
		while ($obj = $db->fetch_object($resql)) {
			$store = (int) $obj->loja_zs;
			$family = (int) $obj->family_id;
			$subfamily = (int) $obj->subfamily_id;
			if ($store <= 0 || $family <= 0 || $subfamily < 0) {
				continue;
			}

			$familyName = trim((string) $obj->family_name);
			$subfamilyName = trim((string) $obj->subfamily_name);
			if ($familyName === '') {
				$familyName = '#' . $family;
			}
			if ($subfamily > 0 && $subfamilyName === '') {
				$subfamilyName = '#' . $subfamily;
			}

			$key = dzs_build_zs_category_key($store, $family, $subfamily);
			$label = $familyName;
			if ($subfamily > 0) {
				$label .= ' > ' . $subfamilyName;
			}

			$options[$key] = $label;
			$metaOut[$key] = array(
				'store' => $store,
				'family_id' => $family,
				'subfamily_id' => $subfamily,
				'family_name' => $familyName,
				'subfamily_name' => $subfamilyName,
				'label' => $label,
			);
		}
		$db->free($resql);
	}

	return $options;
}

/**
 * Map Dolibarr category ids to ZSBMS family keys.
 *
 * @param DoliDB $db Database handler.
 * @param int    $entity Current entity.
 * @param int    $storeProducts Current entity ZSBMS store.
 * @param int[]  $dolCategoryIds Dolibarr category ids.
 * @return string[]
 */
function dzs_map_dolibarr_to_zs_keys($db, $entity, $storeProducts, $dolCategoryIds)
{
	$out = array();
	$dolCategoryIds = dzs_sanitize_category_ids($dolCategoryIds);
	if (empty($dolCategoryIds)) {
		return array();
	}

	$sql = 'SELECT DISTINCT loja_zs, family_id, subfamily_id';
	$sql .= ' FROM ' . MAIN_DB_PREFIX . 'dolizsynch_zsfamily';
	$sql .= ' WHERE entity IN (0, ' . ((int) $entity) . ')';
	if ((int) $storeProducts > 0) {
		$sql .= ' AND loja_zs = ' . ((int) $storeProducts);
	}
	$sql .= ' AND fk_categorie IN (' . implode(',', array_map('intval', $dolCategoryIds)) . ')';
	$sql .= ' ORDER BY loja_zs ASC, family_id ASC, subfamily_id ASC';

	$resql = $db->query($sql);
	if ($resql) {
		while ($obj = $db->fetch_object($resql)) {
			$store = (int) $obj->loja_zs;
			$family = (int) $obj->family_id;
			$subfamily = (int) $obj->subfamily_id;
			if ($store > 0 && $family > 0 && $subfamily >= 0) {
				$key = dzs_build_zs_category_key($store, $family, $subfamily);
				$out[$key] = $key;
			}
		}
		$db->free($resql);
	}

	return array_values($out);
}

$form = new Form($db);

$sortFieldMap = array(
	'p.ref' => 'p.ref',
	'p.label' => 'p.label',
);
$legacySortFieldMap = array(
	'ref' => 'p.ref',
	'label' => 'p.label',
	'cost_price' => 'p.label',
	'p.cost_price' => 'p.label',
);
$sortfieldInput = GETPOST('sortfield', 'aZ09comma');
if (isset($legacySortFieldMap[$sortfieldInput])) {
	$sortfieldSql = $legacySortFieldMap[$sortfieldInput];
} elseif (isset($sortFieldMap[$sortfieldInput])) {
	$sortfieldSql = $sortFieldMap[$sortfieldInput];
} else {
	$sortfieldSql = 'p.label';
}
$sortfield = $sortfieldSql;
$sortorder = strtoupper(GETPOST('sortorder', 'aZ09comma'));
if (!in_array($sortorder, array('ASC', 'DESC'), true)) {
	$sortorder = 'ASC';
}

$canEditCategoryFilters = (!empty($user->admin) || !empty($user->rights->produit->creer) || !empty($user->rights->categorie->creer));

$editMode = (GETPOSTINT('editmode') > 0 && $canEditCategoryFilters) ? 1 : 0;
$actionSave = (GETPOST('savefilters', 'alpha') !== '');
$storeProducts = (int) ($conf->global->ZS_API_STORE ?? 0);

if ($actionSave && !$canEditCategoryFilters) {
	accessforbidden();
}

$requestRawCategories = GETPOST('categories', 'array');
$requestCategories = dzs_sanitize_zs_category_keys($requestRawCategories);
if (empty($requestCategories)) {
	$requestCategories = dzs_map_dolibarr_to_zs_keys($db, (int) $conf->entity, $storeProducts, dzs_sanitize_category_ids($requestRawCategories));
}

if ($editMode && $actionSave) {
	$db->begin();

	$saveOk = dolibarr_set_const($db, 'DOLIZSYNCH_PRODUCTLIST_CATEGORIES', implode(',', $requestCategories), 'chaine', 0, '', $conf->entity);

	if ($saveOk) {
		$db->commit();
		setEventMessages($langs->trans('Saved'), null, 'mesgs');
	} else {
		$db->rollback();
		setEventMessages($db->lasterror(), null, 'errors');
	}

	$redirect = $_SERVER['PHP_SELF'] . '?mainmenu=products&leftmenu=product_produtosdevenda';
	$redirect .= dzs_build_category_params($requestCategories, 0);
	$redirect .= '&sortfield=' . urlencode($sortfield);
	$redirect .= '&sortorder=' . urlencode($sortorder);
	header('Location: ' . $redirect);
	exit;
}

if (!empty($requestCategories)) {
	$selectedCategories = $requestCategories;
} else {
	$savedRawCategories = (string) getDolGlobalString('DOLIZSYNCH_PRODUCTLIST_CATEGORIES', '');
	$savedCategories = dzs_sanitize_zs_category_keys($savedRawCategories);
	if (!empty($savedCategories)) {
		$selectedCategories = $savedCategories;
	} else {
		$selectedCategories = dzs_map_dolibarr_to_zs_keys($db, (int) $conf->entity, $storeProducts, dzs_sanitize_category_ids($savedRawCategories));
	}
}

$tablesReady = dzs_table_exists($db, 'dolizsynch_zsfamily') && dzs_table_exists($db, 'dolizsynch_zsproduct');
$title = $langs->trans('DoliZSynchVisibleCategoriesTitle');

llxHeader('', $title, '', '', 0, 0, '', '', '', 'mod-dolizsynch page-product-category-list');

print '<style>';
print '.degema-table-fixed{table-layout:fixed;}';
print '.degema-product-filter{margin-top:18px;}';
print '.degema-product-filter .tagtable{width:100%;display:table;}';
print '.degema-product-filter .tagtr{display:table-row;}';
print '.degema-product-filter .tagtd{display:table-cell;vertical-align:middle;padding:4px 8px 4px 0;}';
print '.degema-fixed-btn{min-width:110px;}';
print '.dzs-family-selector-disabled{pointer-events:none;opacity:0.7;}';
print '.dzs-vat-suffix{font-size:0.68em;}';
print '.degema-table-fixed .wrapcolumntitle{white-space:normal!important;overflow:visible!important;text-overflow:clip!important;line-height:1.2;}';
print '.dzs-list-toolbar{margin:12px 0 14px;}';
print '.dzs-list-filter-label{display:block;margin-bottom:5px;font-weight:600;}';
print '.dzs-list-filter-input{max-width:420px;width:100%;}';
print '.dzs-family-list-card{margin-bottom:14px;}';
print '.dzs-list-toggle{margin-right:8px;border:0;background:transparent;color:inherit;padding:0;cursor:pointer;line-height:1;}';
print '.dzs-list-toggle:focus{outline:1px dotted #666;outline-offset:2px;}';
print '.dzs-family-list-card.dzs-list-collapsed .dzs-family-list-body{display:none;}';
print '.dzs-family-list-card.dzs-list-hidden{display:none;}';
print '.dzs-list-filter-empty{display:none;margin-bottom:10px;}';
print '.dzs-list-filter-empty.dzs-list-filter-empty-visible{display:block;}';
print '</style>';

print '<div class="fiche">';
print load_fiche_titre($title, '', 'product');

if (!$tablesReady) {
	setEventMessages($langs->trans('DoliZSynchVisibleCategoriesTablesMissing'), null, 'errors');
}

$entityFilterProduct = getEntity('product');
$zsFamilyMeta = array();
$zsFamilyOptions = ($tablesReady && $storeProducts > 0) ? dzs_get_zs_family_options($db, (int) $conf->entity, $storeProducts, $zsFamilyMeta) : array();

if (empty($selectedCategories) && !empty($zsFamilyOptions)) {
	$selectedCategories = array_slice(array_keys($zsFamilyOptions), 0, 14);
}
$selectedCategories = array_values(array_filter($selectedCategories, static function ($categoryKey) use ($zsFamilyOptions) {
	return isset($zsFamilyOptions[$categoryKey]);
}));

$param = '&mainmenu=products&leftmenu=product_produtosdevenda';
$param .= dzs_build_category_params($selectedCategories, $editMode);

print '<div class="dzs-list-toolbar">';
print '<label for="dzs-list-name-filter" class="dzs-list-filter-label">' . dol_escape_htmltag($langs->trans('DoliZSynchVisibleCategoriesFilterListName')) . '</label>';
print '<input type="text" id="dzs-list-name-filter" class="flat dzs-list-filter-input" value="" placeholder="' . dol_escape_htmltag($langs->trans('DoliZSynchVisibleCategoriesFilterPlaceholder')) . '">';
print '</div>';
print '<div id="dzs-list-filter-empty" class="opacitymedium dzs-list-filter-empty">' . dol_escape_htmltag($langs->trans('NoRecordFound')) . '</div>';

$printedSection = 0;
foreach ($selectedCategories as $categoryKey) {
	if (empty($zsFamilyMeta[$categoryKey])) {
		continue;
	}
	$meta = $zsFamilyMeta[$categoryKey];
	$store = (int) $meta['store'];
	$familyId = (int) $meta['family_id'];
	$subfamilyId = (int) $meta['subfamily_id'];

	$printedSection++;
	$categoryTitle = dol_escape_htmltag($meta['family_name']);
	if ($subfamilyId > 0) {
		$categoryTitle .= ' &gt; ' . dol_escape_htmltag($meta['subfamily_name']);
	}
	$listBodyId = 'dzs-family-list-body-' . dzs_build_dom_id_fragment($categoryKey);

	print '<div class="dzs-family-list-card" data-list-key="' . dol_escape_htmltag($categoryKey) . '" data-list-name="' . dol_escape_htmltag((string) $meta['label']) . '">';

	print '<table class="centpercent notopnoleftnoright table-fiche-title">';
	print '<tr class="toptitle">';
	print '<td class="nobordernopadding valignmiddle col-title">';
	print '<button type="button" class="dzs-list-toggle" aria-expanded="true" aria-controls="' . dol_escape_htmltag($listBodyId) . '" title="' . dol_escape_htmltag($langs->trans('ShowHide')) . '">';
	print '<span class="fas fa-chevron-down dzs-list-toggle-icon"></span>';
	print '</button>';
	print '<div class="titre inline-block"><span class="inline-block valignmiddle">' . $categoryTitle . '</span></div>';
	print '</td>';
	print '</tr>';
	print '</table>';

	print '<div id="' . dol_escape_htmltag($listBodyId) . '" class="dzs-family-list-body">';
	print '<div class="div-table-responsive">';
	print '<table class="tagtable liste degema-table-fixed">';
	print '<colgroup>';
	print '<col style="width:10%">';
	print '<col style="width:23%">';
	print '<col style="width:20%">';
	print '<col style="width:11%">';
	print '<col style="width:7%">';
	print '<col style="width:14.5%">';
	print '<col style="width:14.5%">';
	print '</colgroup>';
	print '<tr class="liste_titre">';
	print_liste_field_titre($langs->trans('Ref'), $_SERVER['PHP_SELF'], 'p.ref', '', $param, '', $sortfield, $sortorder);
	print_liste_field_titre($langs->trans('Label'), $_SERVER['PHP_SELF'], 'p.label', '', $param, '', $sortfield, $sortorder);
	print '<th class="wrapcolumntitle liste_titre" title="' . dol_escape_htmltag($langs->trans('DoliZSynchDescricaoCurta')) . '">' . dol_escape_htmltag($langs->trans('DoliZSynchDescricaoCurta')) . '</th>';
	print '<th class="wrapcolumntitle right liste_titre" title="' . dol_escape_htmltag($langs->trans('CostPrice')) . '">' . dol_escape_htmltag($langs->trans('CostPrice')) . '</th>';
	print '<th class="wrapcolumntitle right liste_titre" title="' . dol_escape_htmltag($langs->trans('VAT')) . '">' . dol_escape_htmltag($langs->trans('VAT')) . '</th>';
	print '<th class="wrapcolumntitle right liste_titre" title="' . dol_escape_htmltag($langs->trans('SellingPrice') . ' 1') . '">' . dol_escape_htmltag($langs->trans('SellingPrice') . ' 1') . '</th>';
	print '<th class="wrapcolumntitle right liste_titre" title="' . dol_escape_htmltag($langs->trans('SellingPrice') . ' 2') . '">' . dol_escape_htmltag($langs->trans('SellingPrice') . ' 2') . '</th>';
	print '</tr>';

	$lineCount = 0;
	if ($tablesReady) {
		$sql = 'SELECT DISTINCT';
		$sql .= ' p.rowid, p.ref, p.label, p.cost_price, p.price_ttc';
		$sql .= ', zp.retalho_by_store, zp.product_company, zp.loja_zs, zp.descricaocurta AS zs_descricaocurta';
		$sql .= ', zp.iva AS zs_iva';
		$sql .= ', (SELECT pp.price_ttc FROM ' . MAIN_DB_PREFIX . 'product_price AS pp';
		$sql .= ' WHERE pp.fk_product = p.rowid AND pp.price_level = 1 AND pp.entity IN (' . $entityFilterProduct . ')';
		$sql .= ' ORDER BY pp.date_price DESC, pp.rowid DESC LIMIT 1) AS sale_price1_ttc';
		$sql .= ', (SELECT pp.price_ttc FROM ' . MAIN_DB_PREFIX . 'product_price AS pp';
		$sql .= ' WHERE pp.fk_product = p.rowid AND pp.price_level = 2 AND pp.entity IN (' . $entityFilterProduct . ')';
		$sql .= ' ORDER BY pp.date_price DESC, pp.rowid DESC LIMIT 1) AS sale_price2_ttc';
		$sql .= ' FROM ' . MAIN_DB_PREFIX . 'product AS p';
		$sql .= ' INNER JOIN ' . MAIN_DB_PREFIX . 'dolizsynch_zsproduct AS zp';
		$sql .= ' ON zp.fk_product = p.rowid';
		$sql .= ' WHERE p.entity IN (' . $entityFilterProduct . ')';
		$sql .= ' AND zp.familia = ' . $familyId;
		if ($subfamilyId > 0) {
			$sql .= ' AND zp.subfam = ' . $subfamilyId;
		}
		$sql .= ' ORDER BY ' . $sortfieldSql . ' ' . $sortorder;
		if ($sortfieldSql !== 'p.label') {
			$sql .= ', p.label ASC';
		}
		$sql .= ', p.rowid ASC';

		$resql = $db->query($sql);
		if (!$resql) {
			setEventMessages($db->lasterror(), null, 'errors');
		} else {
			while ($obj = $db->fetch_object($resql)) {
				if (!dzs_is_retalho_visible_for_store(
					isset($obj->retalho_by_store) ? $obj->retalho_by_store : '',
					$store,
					isset($obj->product_company) ? $obj->product_company : '',
					isset($obj->loja_zs) ? (int) $obj->loja_zs : 0
				)) {
					continue;
				}

				$lineCount++;
				$productUrl = DOL_URL_ROOT . '/custom/kreaflow/zsproduct.php?id=' . ((int) $obj->rowid) . '&save_lastsearch_values=1';
				$price1Value = ($obj->sale_price1_ttc !== null && $obj->sale_price1_ttc !== '') ? $obj->sale_price1_ttc : $obj->price_ttc;
				$vatValue = ($obj->zs_iva === null || $obj->zs_iva === '') ? '' : (price($obj->zs_iva) . '%');

				print '<tr class="oddeven">';
				print '<td class="nowraponall">';
				print '<a target="_blank" href="' . dol_escape_htmltag($productUrl) . '" class="nowraponall classforajaxtooltip">';
				print '<span class="fas fa-cube paddingright" style=" color: #a69944;"></span>';
				print '<span class="aaa">' . dol_escape_htmltag($obj->ref) . '</span>';
				print '</a>';
				print '</td>';
				print '<td>' . dol_escape_htmltag($obj->label) . '</td>';
				print '<td>' . dol_escape_htmltag((string) ($obj->zs_descricaocurta ?? '')) . '</td>';
				print '<td class="right">' . (($obj->cost_price === null || $obj->cost_price === '') ? '' : price($obj->cost_price)) . '</td>';
				print '<td class="right nowraponall">' . $vatValue . '</td>';
				print '<td class="right nowraponall">' . dzs_format_price_ttc($price1Value, $langs) . '</td>';
				print '<td class="right nowraponall">' . dzs_format_price_ttc($obj->sale_price2_ttc, $langs) . '</td>';
				print '</tr>';
			}
			$db->free($resql);
		}
	}

	if ($lineCount === 0) {
		print '<tr class="oddeven"><td colspan="7" class="opacitymedium">' . $langs->trans('NoRecordFound') . '</td></tr>';
	}

	print '</table>';
	print '</div>';
	print '</div>';
	print '</div>';
}

if ($printedSection === 0) {
	print '<div class="opacitymedium marginbottom">' . $langs->trans('NoRecordFound') . '</div>';
}

$categorySelectorName = $editMode ? 'categories' : 'categories_locked';
$categorySelectorAttrs = 'id="categories"' . ($editMode ? '' : ' disabled="disabled" aria-disabled="true" tabindex="-1"');
$categorySelector = $form->multiselectarray($categorySelectorName, $zsFamilyOptions, $selectedCategories, 0, 0, 'minwidth100 widthcentpercentminusxx', 0, '700px', '', $categorySelectorAttrs);
if (!$editMode) {
	$categorySelector = '<div class="dzs-family-selector-disabled">' . $categorySelector . '</div>';
}

print '<form method="POST" action="' . dol_escape_htmltag($_SERVER['PHP_SELF']) . '" class="degema-product-filter">';
print '<input type="hidden" name="mainmenu" value="products">';
print '<input type="hidden" name="leftmenu" value="product_produtosdevenda">';
print '<input type="hidden" name="sortfield" value="' . dol_escape_htmltag($sortfield) . '">';
print '<input type="hidden" name="sortorder" value="' . dol_escape_htmltag($sortorder) . '">';
print '<input type="hidden" name="editmode" value="' . ((int) $editMode) . '">';

print '<div class="tagtable">';
print '<div class="tagtr">';
print '<div class="tagtd">' . $categorySelector . '</div>';
print '<div class="tagtd right">';

if ($editMode) {
	$cancelUrl = $_SERVER['PHP_SELF'] . '?mainmenu=products&leftmenu=product_produtosdevenda';
	$cancelUrl .= dzs_build_category_params($selectedCategories, 0);
	$cancelUrl .= '&sortfield=' . urlencode($sortfield);
	$cancelUrl .= '&sortorder=' . urlencode($sortorder);

	print '<input type="submit" class="button button-save degema-fixed-btn" name="savefilters" value="' . dol_escape_htmltag($langs->trans('Save')) . '">';
	print '&nbsp;<a class="button button-cancel degema-fixed-btn" href="' . dol_escape_htmltag($cancelUrl) . '">' . dol_escape_htmltag($langs->trans('Cancel')) . '</a>';
} else {
	if ($canEditCategoryFilters) {
		$editUrl = $_SERVER['PHP_SELF'] . '?mainmenu=products&leftmenu=product_produtosdevenda';
		$editUrl .= dzs_build_category_params($selectedCategories, 1);
		$editUrl .= '&sortfield=' . urlencode($sortfield);
		$editUrl .= '&sortorder=' . urlencode($sortorder);

		print '<button type="button" class="button button-save degema-fixed-btn" onclick="window.location.href=\'' . dol_escape_htmltag($editUrl) . '\';">';
		print dol_escape_htmltag($langs->trans('Edit'));
		print '</button>';
	}
}

print '</div>';
print '</div>';
print '</div>';
print '</form>';

if ($printedSection > 0) {
	$uiStateStorageKey = 'dolizsynch.productlist.ui.entity-' . ((int) $conf->entity) . '.store-' . ((int) $storeProducts);
	print '<script>';
	print '(function () {';
	print 'var filterInput = document.getElementById("dzs-list-name-filter");';
	print 'if (!filterInput) { return; }';
	print 'var emptyStateNode = document.getElementById("dzs-list-filter-empty");';
	print 'var cards = Array.prototype.slice.call(document.querySelectorAll(".dzs-family-list-card"));';
	print 'if (!cards.length) { return; }';
	print 'var storageKey = ' . json_encode($uiStateStorageKey) . ';';
	print 'var state = { filter: "", collapsed: {} };';
	print 'function saveState() {';
	print '	try { window.localStorage.setItem(storageKey, JSON.stringify(state)); } catch (error) {}';
	print '}';
	print 'function loadState() {';
	print '	try {';
	print '		var raw = window.localStorage.getItem(storageKey);';
	print '		if (!raw) { return; }';
	print '		var parsed = JSON.parse(raw);';
	print '		if (parsed && typeof parsed === "object") {';
	print '			if (typeof parsed.filter === "string") { state.filter = parsed.filter; }';
	print '			if (parsed.collapsed && typeof parsed.collapsed === "object") { state.collapsed = parsed.collapsed; }';
	print '		}';
	print '	} catch (error) {}';
	print '}';
	print 'function setCollapsed(card, collapsed) {';
	print '	var toggle = card.querySelector(".dzs-list-toggle");';
	print '	var icon = card.querySelector(".dzs-list-toggle-icon");';
	print '	card.classList.toggle("dzs-list-collapsed", collapsed);';
	print '	if (toggle) { toggle.setAttribute("aria-expanded", collapsed ? "false" : "true"); }';
	print '	if (icon) {';
	print '		icon.classList.toggle("fa-chevron-down", !collapsed);';
	print '		icon.classList.toggle("fa-chevron-right", collapsed);';
	print '	}';
	print '}';
	print 'function applyFilter() {';
	print '	var query = (filterInput.value || "").toLowerCase().trim();';
	print '	var visibleCount = 0;';
	print '	cards.forEach(function (card) {';
	print '		var listName = (card.getAttribute("data-list-name") || "").toLowerCase();';
	print '		var visible = (query === "" || listName.indexOf(query) !== -1);';
	print '		card.classList.toggle("dzs-list-hidden", !visible);';
	print '		if (visible) { visibleCount++; }';
	print '	});';
	print '	if (emptyStateNode) {';
	print '		emptyStateNode.classList.toggle("dzs-list-filter-empty-visible", visibleCount === 0);';
	print '	}';
	print '}';
	print 'loadState();';
	print 'cards.forEach(function (card) {';
	print '	var key = card.getAttribute("data-list-key") || "";';
	print '	var collapsed = (state.collapsed[key] === 1 || state.collapsed[key] === "1" || state.collapsed[key] === true);';
	print '	setCollapsed(card, collapsed);';
	print '	var toggle = card.querySelector(".dzs-list-toggle");';
	print '	if (!toggle) { return; }';
	print '	toggle.addEventListener("click", function () {';
	print '		var nowCollapsed = !card.classList.contains("dzs-list-collapsed");';
	print '		setCollapsed(card, nowCollapsed);';
	print '		state.collapsed[key] = nowCollapsed ? 1 : 0;';
	print '		saveState();';
	print '	});';
	print '});';
	print 'if (typeof state.filter === "string" && state.filter !== "") {';
	print '	filterInput.value = state.filter;';
	print '}';
	print 'filterInput.addEventListener("input", function () {';
	print '	state.filter = filterInput.value || "";';
	print '	applyFilter();';
	print '	saveState();';
	print '});';
	print 'applyFilter();';
	print '})();';
	print '</script>';
}

print '</div>';

llxFooter();
$db->close();

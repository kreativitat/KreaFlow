<?php
/* Copyright (C) 2004-2017  Laurent Destailleur     <eldy@users.sourceforge.net>
 * Copyright (C) 2024       Frederic France         <frederic.france@free.fr>
 * Copyright (C) 2026       Kreativität Works <mail@kreativitat.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
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
 * \file    kreaflow/admin/setup.php
 * \ingroup kreaflow
 * \brief   KreaFlow setup page.
 */

$res = 0;
if (!$res && !empty($_SERVER['CONTEXT_DOCUMENT_ROOT'])) {
	$res = @include $_SERVER['CONTEXT_DOCUMENT_ROOT'].'/main.inc.php';
}
$tmp = empty($_SERVER['SCRIPT_FILENAME']) ? '' : $_SERVER['SCRIPT_FILENAME'];
$tmp2 = realpath(__FILE__);
$i = strlen($tmp) - 1;
$j = strlen($tmp2) - 1;
while ($i > 0 && $j > 0 && isset($tmp[$i]) && isset($tmp2[$j]) && $tmp[$i] === $tmp2[$j]) {
	$i--;
	$j--;
}
if (!$res && $i > 0 && file_exists(substr($tmp, 0, ($i + 1)).'/main.inc.php')) {
	$res = @include substr($tmp, 0, ($i + 1)).'/main.inc.php';
}
if (!$res && $i > 0 && file_exists(dirname(substr($tmp, 0, ($i + 1))).'/main.inc.php')) {
	$res = @include dirname(substr($tmp, 0, ($i + 1))).'/main.inc.php';
}
if (!$res && file_exists('../../main.inc.php')) {
	$res = @include '../../main.inc.php';
}
if (!$res && file_exists('../../../main.inc.php')) {
	$res = @include '../../../main.inc.php';
}
if (!$res) {
	die('Include of main fails');
}

require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php';
require_once DOL_DOCUMENT_ROOT.'/categories/class/categorie.class.php';
require_once __DIR__.'/../lib/kreaflow.lib.php';

$langs->loadLangs(array('admin', 'categories', 'products', 'kreaflow@kreaflow'));

if (!$user->admin) {
	accessforbidden();
}

$action = GETPOST('action', 'aZ09');
$backtopage = GETPOST('backtopage', 'alpha');

$stockForecastEntityOptions = array();
$defaultStockForecastEntityIds = array();
$sqlEntities = 'SELECT rowid, label';
$sqlEntities .= ' FROM '.MAIN_DB_PREFIX.'entity';
$sqlEntities .= ' WHERE active = 1';
$sqlEntities .= ' ORDER BY rowid';
$resqlEntities = $db->query($sqlEntities);
if ($resqlEntities) {
	while ($entityObject = $db->fetch_object($resqlEntities)) {
		$entityId = (int) $entityObject->rowid;
		$entityLabel = trim((string) $entityObject->label);
		if ($entityId <= 0) {
			continue;
		}
		if ($entityLabel === '') {
			$entityLabel = 'Entity '.$entityId;
		}
		$stockForecastEntityOptions[$entityId] = $entityLabel;
	}
	$db->free($resqlEntities);
} else {
	dol_syslog('KreaFlow setup could not load multicompany entities: '.$db->lasterror(), LOG_WARNING);
	$currentEntityId = (int) $conf->entity;
	if ($currentEntityId > 0) {
		$stockForecastEntityOptions[$currentEntityId] = 'Entity '.$currentEntityId;
	}
}

if ($action === 'set_product_layout') {
	$token = GETPOST('token', 'alpha');
	$validTokens = array_filter(array(newToken(), currentToken()));
	if ($token === '' || !in_array($token, $validTokens, true)) {
		accessforbidden($langs->trans('ErrorBadOrExpiredToken'));
	}

	$postedCategories = GETPOST('categories', 'array:int');
	if (!is_array($postedCategories)) {
		$postedCategories = array();
	}

	$categoryIdsByKey = array();
	foreach ($postedCategories as $postedCategoryId) {
		$categoryId = (int) $postedCategoryId;
		if ($categoryId <= 0 || isset($categoryIdsByKey[$categoryId])) {
			continue;
		}
		$categoryIdsByKey[$categoryId] = $categoryId;
	}

	$filterToSell = GETPOSTINT('filter_tosell') > 0 ? 1 : 0;
	$historyLimitDays = GETPOSTINT('history_limit_days');
	if ($historyLimitDays <= 0) {
		$historyLimitDays = 15;
	}
	$fechoEditLimitDays = GETPOSTINT('fecho_edit_limit_days');
	if ($fechoEditLimitDays <= 0) {
		$fechoEditLimitDays = 3;
	}
	$categoriesCsv = implode(',', array_values($categoryIdsByKey));
	$postedStockForecastEntities = GETPOST('stock_forecast_entities', 'array:int');
	if (!is_array($postedStockForecastEntities)) {
		$postedStockForecastEntities = array();
	}
	$stockForecastEntityIdsByKey = array();
	foreach ($postedStockForecastEntities as $postedEntityId) {
		$entityId = (int) $postedEntityId;
		if ($entityId <= 0 || !isset($stockForecastEntityOptions[$entityId])) {
			continue;
		}
		$stockForecastEntityIdsByKey[$entityId] = $entityId;
	}
	$stockForecastEntitiesCsv = !empty($stockForecastEntityIdsByKey)
		? implode(',', array_values($stockForecastEntityIdsByKey))
		: '0';

	$error = 0;
	if (dolibarr_set_const($db, 'KREAFLOW_PRODUCTLIST_CATEGORIES', $categoriesCsv, 'chaine', 0, '', $conf->entity) <= 0) {
		$error++;
	}
	if (dolibarr_set_const($db, 'KREAFLOW_PRODUCTLIST_FILTER_TOSELL', (string) $filterToSell, 'chaine', 0, '', $conf->entity) <= 0) {
		$error++;
	}
	if (dolibarr_set_const($db, 'KREAFLOW_HISTORY_LIMIT_DAYS', (string) $historyLimitDays, 'entier', 0, '', $conf->entity) <= 0) {
		$error++;
	}
	if (dolibarr_set_const($db, 'KREAFLOW_FECHO_EDIT_LIMIT_DAYS', (string) $fechoEditLimitDays, 'entier', 0, '', $conf->entity) <= 0) {
		$error++;
	}
	if (dolibarr_set_const($db, 'KREAFLOW_STOCK_FORECAST_ENTITIES', $stockForecastEntitiesCsv, 'chaine', 0, '', 0) <= 0) {
		$error++;
	}

	if ($error > 0) {
		setEventMessages($langs->trans('Error'), null, 'errors');
	} else {
		setEventMessages($langs->trans('SetupSaved'), null, 'mesgs');
		header('Location: '.$_SERVER['PHP_SELF']);
		$db->close();
		exit;
	}
}

$savedCategoriesRaw = trim((string) getDolGlobalString('KREAFLOW_PRODUCTLIST_CATEGORIES'));
$savedCategoryIdsByKey = array();
if ($savedCategoriesRaw !== '') {
	$parts = explode(',', $savedCategoriesRaw);
	foreach ($parts as $part) {
		$categoryId = (int) trim((string) $part);
		if ($categoryId <= 0 || isset($savedCategoryIdsByKey[$categoryId])) {
			continue;
		}
		$savedCategoryIdsByKey[$categoryId] = $categoryId;
	}
}
$savedCategoryIds = array_values($savedCategoryIdsByKey);

$savedStockForecastEntitiesRaw = '';
$sqlStockForecastConfig = 'SELECT value';
$sqlStockForecastConfig .= ' FROM '.MAIN_DB_PREFIX.'const';
$sqlStockForecastConfig .= " WHERE name = 'KREAFLOW_STOCK_FORECAST_ENTITIES'";
$sqlStockForecastConfig .= ' AND entity = 0';
$sqlStockForecastConfig .= ' LIMIT 1';
$resqlStockForecastConfig = $db->query($sqlStockForecastConfig);
if ($resqlStockForecastConfig) {
	$stockForecastConfigObject = $db->fetch_object($resqlStockForecastConfig);
	if ($stockForecastConfigObject && isset($stockForecastConfigObject->value)) {
		$savedStockForecastEntitiesRaw = trim((string) $stockForecastConfigObject->value);
	}
	$db->free($resqlStockForecastConfig);
}
$savedStockForecastEntityIdsByKey = array();
if ($savedStockForecastEntitiesRaw === '') {
	$savedStockForecastEntityIdsByKey = $defaultStockForecastEntityIds;
} else {
	foreach (explode(',', $savedStockForecastEntitiesRaw) as $savedEntityId) {
		$entityId = (int) trim((string) $savedEntityId);
		if ($entityId <= 0 || !isset($stockForecastEntityOptions[$entityId])) {
			continue;
		}
		$savedStockForecastEntityIdsByKey[$entityId] = $entityId;
	}
}
$savedStockForecastEntityIds = array_values($savedStockForecastEntityIdsByKey);

if (!GETPOSTISSET('categories')) {
	$_POST['categories'] = $savedCategoryIds;
	$_REQUEST['categories'] = $savedCategoryIds;
}
if (!GETPOSTISSET('stock_forecast_entities')) {
	$_POST['stock_forecast_entities'] = $savedStockForecastEntityIds;
	$_REQUEST['stock_forecast_entities'] = $savedStockForecastEntityIds;
}

$filterToSellRaw = getDolGlobalString('KREAFLOW_PRODUCTLIST_FILTER_TOSELL');
$filterToSell = ($filterToSellRaw === '' || (int) $filterToSellRaw === 1) ? 1 : 0;
if (GETPOSTISSET('filter_tosell')) {
	$filterToSell = GETPOSTINT('filter_tosell') > 0 ? 1 : 0;
}

$historyLimitDays = (int) getDolGlobalInt('KREAFLOW_HISTORY_LIMIT_DAYS', 15);
if ($historyLimitDays <= 0) {
	$historyLimitDays = 15;
}
if (GETPOSTISSET('history_limit_days')) {
	$postedHistoryLimitDays = GETPOSTINT('history_limit_days');
	if ($postedHistoryLimitDays > 0) {
		$historyLimitDays = $postedHistoryLimitDays;
	}
}

$fechoEditLimitDays = (int) getDolGlobalInt('KREAFLOW_FECHO_EDIT_LIMIT_DAYS', 3);
if ($fechoEditLimitDays <= 0) {
	$fechoEditLimitDays = 3;
}
if (GETPOSTISSET('fecho_edit_limit_days')) {
	$postedFechoEditLimitDays = GETPOSTINT('fecho_edit_limit_days');
	if ($postedFechoEditLimitDays > 0) {
		$fechoEditLimitDays = $postedFechoEditLimitDays;
	}
}

$form = new Form($db);

$help_url = '';
$title = 'KreaFlowSetup';

llxHeader('', $langs->trans($title), $help_url, '', 0, 0, '', '', '', 'mod-kreaflow page-admin');

$linkback = '<a href="'.($backtopage ? $backtopage : DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1').'">'.$langs->trans('BackToModuleList').'</a>';
print load_fiche_titre($langs->trans($title), $linkback, 'title_setup');

$head = kreaflowAdminPrepareHead();
print dol_get_fiche_head($head, 'mobile_settings', $langs->trans($title), -1, 'kreaflow@kreaflow');

echo '<span class="opacitymedium">'.$langs->trans('KreaFlowSetupPage').'</span><br><br>';

echo '<form method="POST" action="'.$_SERVER['PHP_SELF'].'">';
echo '<input type="hidden" name="token" value="'.newToken().'">';
echo '<input type="hidden" name="action" value="set_product_layout">';

echo '<table class="noborder centpercent">';
echo '<tr class="liste_titre">';
echo '<th colspan="2">'.$langs->trans('KreaFlowProductLayoutSetup').'</th>';
echo '</tr>';

echo '<tr class="oddeven">';
echo '<td class="tdtop minwidth300">'.$langs->trans('KREAFLOW_PRODUCTLIST_CATEGORIES').'</td>';
echo '<td>';
echo '<div class="maxwidthonsmartphone" style="max-width:720px;">';
echo $form->selectCategories(Categorie::TYPE_PRODUCT, 'categories', '', '', '', '', '', '', '');
echo '</div>';
echo '<div class="opacitymedium small">'.$langs->trans('KREAFLOW_PRODUCTLIST_CATEGORIESTooltip').'</div>';
echo '</td>';
echo '</tr>';

echo '<tr class="oddeven">';
echo '<td>'.$langs->trans('KREAFLOW_PRODUCTLIST_FILTER_TOSELL').'</td>';
echo '<td>';
echo '<label for="filter_tosell" class="nowraponall">';
echo '<input type="checkbox" id="filter_tosell" name="filter_tosell" value="1"'.($filterToSell ? ' checked' : '').'> ';
echo $langs->trans('KREAFLOW_PRODUCTLIST_FILTER_TOSELLTooltip');
echo '</label>';
echo '</td>';
echo '</tr>';

echo '<tr class="oddeven">';
echo '<td>'.$langs->trans('KREAFLOW_HISTORY_LIMIT_DAYS').'</td>';
echo '<td>';
echo '<input type="number" min="1" step="1" class="flat maxwidth100" id="history_limit_days" name="history_limit_days" value="'.((int) $historyLimitDays).'">';
echo '<div class="opacitymedium small">'.$langs->trans('KREAFLOW_HISTORY_LIMIT_DAYSTooltip').'</div>';
echo '</td>';
echo '</tr>';

echo '<tr class="oddeven">';
echo '<td>'.$langs->trans('KREAFLOW_FECHO_EDIT_LIMIT_DAYS').'</td>';
echo '<td>';
echo '<input type="number" min="1" step="1" class="flat maxwidth100" id="fecho_edit_limit_days" name="fecho_edit_limit_days" value="'.((int) $fechoEditLimitDays).'">';
echo '<div class="opacitymedium small">'.$langs->trans('KREAFLOW_FECHO_EDIT_LIMIT_DAYSTooltip').'</div>';
echo '</td>';
echo '</tr>';

echo '<tr class="oddeven">';
echo '<td class="tdtop">'.$langs->trans('KREAFLOW_STOCK_FORECAST_ENTITIES').'</td>';
echo '<td>';
echo '<div class="maxwidthonsmartphone" style="max-width:720px;">';
echo '<select class="flat" id="stock_forecast_entities" name="stock_forecast_entities[]" multiple>';
foreach ($stockForecastEntityOptions as $entityId => $entityLabel) {
	$selected = isset($savedStockForecastEntityIdsByKey[$entityId]) ? ' selected' : '';
	echo '<option value="'.((int) $entityId).'"'.$selected.'>'.dol_escape_htmltag($entityLabel).'</option>';
}
echo '</select>';
echo '</div>';
echo '<div class="opacitymedium small">'.$langs->trans('KREAFLOW_STOCK_FORECAST_ENTITIESTooltip').'</div>';
echo '</td>';
echo '</tr>';

echo '</table>';

echo '<div class="tabsAction">';
echo '<button type="submit" class="button button-save">'.$langs->trans('Save').'</button>';
echo '</div>';

echo '</form>';

if (empty($savedCategoryIds)) {
	echo '<p class="opacitymedium">'.$langs->trans('KreaFlowProductLayoutNoCategoryConfigured').'</p>';
} else {
	echo '<p class="opacitymedium">'.$langs->trans('KreaFlowProductLayoutConfiguredIds').': '.dol_escape_htmltag(implode(', ', $savedCategoryIds)).'</p>';
}

echo '<script>
	jQuery(function($) {
		var $catSelect = $("#categories");
		if ($catSelect.length) {
			$catSelect.select2({
				width: "100%",
				closeOnSelect: false
			});
			$(".button_categories").remove();
		}
		var $stockForecastEntities = $("#stock_forecast_entities");
		if ($stockForecastEntities.length) {
			$stockForecastEntities.select2({
				width: "100%",
				closeOnSelect: false
			});
		}
	});
</script>';

print dol_get_fiche_end();
llxFooter();
$db->close();

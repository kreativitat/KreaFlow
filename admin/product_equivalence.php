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
 * \file        admin/product_equivalence.php
 * \ingroup     dolizsynch
 * \brief       Product Equivalence Mapping Management
 *              Maps products from configured seller entity to buyer entities
 */

// Load Dolibarr environment
$res = 0;
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) {
	$res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"] . "/main.inc.php";
}
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
if (!$res && file_exists("../../main.inc.php")) {
	$res = @include "../../main.inc.php";
}
if (!$res && file_exists("../../../main.inc.php")) {
	$res = @include "../../../main.inc.php";
}
if (!$res) {
	die("Include of main fails");
}

global $langs, $user, $db, $conf;

// Check if multicompany module is enabled
if (!isModEnabled('multicompany')) {
	accessforbidden('Multicompany module is required for this feature');
}

// Check if Inter-Entity Invoice Converter is enabled
if (!getDolGlobalString('DOLIZSYNCH_INTERENTITY_INVOICE_ENABLED')) {
	accessforbidden('Inter-Entity Invoice Converter must be enabled in KreaFlow Setup to access this page');
}

// Libraries
require_once DOL_DOCUMENT_ROOT . "/core/lib/admin.lib.php";
require_once DOL_DOCUMENT_ROOT . "/custom/kreaflow/lib/dolizsynch.lib.php";
require_once DOL_DOCUMENT_ROOT . '/product/class/product.class.php';
require_once DOL_DOCUMENT_ROOT . '/core/class/html.form.class.php';
require_once DOL_DOCUMENT_ROOT . '/core/class/html.formother.class.php';

// Translations
$langs->loadLangs(array("admin", "products", "dolizsynch@kreaflow"));

// Access control
if (!$user->admin) {
	accessforbidden();
}

// Parameters
$action = GETPOST('action', 'aZ09');
$confirm = GETPOST('confirm', 'alpha');
$id = GETPOST('id', 'int');

// Actions
if ($action == 'add' && GETPOST('add', 'alpha') !== '') {
	$fk_product_source = GETPOST('fk_product_source', 'int');
	$fk_product_dest = GETPOST('fk_product_dest', 'int');

	if ($fk_product_source && $fk_product_dest) {
		// Check if mapping already exists
		$sql = "SELECT COUNT(*) as nb FROM " . MAIN_DB_PREFIX . "dolizsynch_product_equivalence";
		$sql .= " WHERE fk_product_source = " . ((int) $fk_product_source);
		$sql .= " AND fk_product_dest = " . ((int) $fk_product_dest);
		$sql .= " AND active = 1";

		$resql = $db->query($sql);
		if ($resql) {
			$obj = $db->fetch_object($resql);
			if ($obj->nb > 0) {
				setEventMessages($langs->trans("ProductEquivalenceAlreadyExists"), null, 'errors');
			} else {
				// Insert new mapping (simple 1:1)
				$sql = "INSERT INTO " . MAIN_DB_PREFIX . "dolizsynch_product_equivalence (";
				$sql .= " entity, fk_product_source, fk_product_dest,";
				$sql .= " active, date_creation, fk_user_creat";
				$sql .= ") VALUES (";
				$sql .= " " . $conf->entity . ",";
				$sql .= " " . $fk_product_source . ",";
				$sql .= " " . $fk_product_dest . ",";
				$sql .= " 1,";
				$sql .= " '" . $db->idate(dol_now()) . "',";
				$sql .= " " . $user->id;
				$sql .= ")";

				if ($db->query($sql)) {
					setEventMessages($langs->trans("ProductEquivalenceAdded"), null, 'mesgs');
					header("Location: " . $_SERVER['PHP_SELF']);
					exit;
				} else {
					setEventMessages($db->lasterror(), null, 'errors');
				}
			}
		}
	} else {
		setEventMessages($langs->trans("ErrorFieldRequired"), null, 'errors');
	}
}

if ($action == 'delete' && $id > 0) {
	$sql = "UPDATE " . MAIN_DB_PREFIX . "dolizsynch_product_equivalence";
	$sql .= " SET active = 0";
	$sql .= " WHERE rowid = " . ((int) $id);

	if ($db->query($sql)) {
		setEventMessages($langs->trans("ProductEquivalenceDeleted"), null, 'mesgs');
		header("Location: " . $_SERVER['PHP_SELF']);
		exit;
	} else {
		setEventMessages($db->lasterror(), null, 'errors');
	}
}

/*
 * View
 */

$form = new Form($db);
$formother = new FormOther($db);

$page_name = "ProductEquivalenceMapping";
llxHeader('', $langs->trans($page_name));

// Subheader
$linkback = '<a href="' . DOL_URL_ROOT . '/admin/modules.php?restore_lastsearch_values=1">' . $langs->trans("BackToModuleList") . '</a>';
print load_fiche_titre($langs->trans($page_name), $linkback, 'title_setup');

// Configuration header
$head = dolizsynchAdminPrepareHead();
print dol_get_fiche_head($head, 'product_equivalence', $langs->trans("ModuleSetup"), -1, 'dolizsynch@kreaflow');

// Info box
print info_admin($langs->trans("ProductEquivalenceInfo") . "<br>" .
	$langs->trans("RequiresMulticompany") . "<br>" .
	$langs->trans("SimpleOneToOneMapping"));

// Add new mapping form
print '<form method="POST" action="' . $_SERVER['PHP_SELF'] . '">';
print '<input type="hidden" name="token" value="' . newToken() . '">';
print '<input type="hidden" name="action" value="add">';

print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<th colspan="3">' . $langs->trans("AddProductEquivalence") . '</th>';
print '</tr>';

print '<tr class="oddeven">';
print '<td class="fieldrequired" width="40%">' . $langs->trans("SourceProduct") . ' (' . $langs->trans("CurrentEntity") . ')</td>';
print '<td width="40%">';
print $form->select_produits('', 'fk_product_source', '', 0, 0, -1, 2, '', 0, array(), 0, '1', 0, 'maxwidth400');
print '<br><span class="opacitymedium">' . $langs->trans("ProductFromSellerEntity") . '</span>';
print '</td>';
print '<td rowspan="2" class="center" width="20%">';
print '<input type="submit" class="button" name="add" value="' . $langs->trans("Add") . '">';
print '</td>';
print '</tr>';

print '<tr class="oddeven">';
print '<td class="fieldrequired">' . $langs->trans("DestinationProduct") . ' (' . $langs->trans("OtherEntity") . ')</td>';
print '<td>';
print $form->select_produits('', 'fk_product_dest', '', 0, 0, -1, 2, '', 0, array(), 0, '1', 0, 'maxwidth400');
print '<br><span class="opacitymedium">' . $langs->trans("ProductInBuyerEntity") . '</span>';
print '</td>';
print '</tr>';

print '</table>';
print '</form>';

print '<br>';

// List existing mappings
print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<th>' . $langs->trans("SourceProduct") . '</th>';
print '<th>' . $langs->trans("SourceRef") . '</th>';
print '<th>' . $langs->trans("DestinationProduct") . '</th>';
print '<th>' . $langs->trans("DestinationRef") . '</th>';
print '<th class="center">' . $langs->trans("Action") . '</th>';
print '</tr>';

$sql = "SELECT pe.rowid,";
$sql .= " ps.rowid as source_id, ps.ref as source_ref, ps.label as source_label, ps.entity as source_entity,";
$sql .= " pd.rowid as dest_id, pd.ref as dest_ref, pd.label as dest_label, pd.entity as dest_entity";
$sql .= " FROM " . MAIN_DB_PREFIX . "dolizsynch_product_equivalence as pe";
$sql .= " INNER JOIN " . MAIN_DB_PREFIX . "product as ps ON ps.rowid = pe.fk_product_source";
$sql .= " INNER JOIN " . MAIN_DB_PREFIX . "product as pd ON pd.rowid = pe.fk_product_dest";
$sql .= " WHERE pe.active = 1";
$sql .= " AND pe.entity = " . $conf->entity;
$sql .= " ORDER BY ps.ref ASC";

$resql = $db->query($sql);
if ($resql) {
	$num = $db->num_rows($resql);
	$i = 0;

	if ($num == 0) {
		print '<tr class="oddeven"><td colspan="5" class="center">' . $langs->trans("NoProductEquivalence") . '</td></tr>';
	}

	while ($i < $num) {
		$obj = $db->fetch_object($resql);

		// Build product URLs
		$source_url = dol_buildpath('/product/card.php', 1) . '?id=' . $obj->source_id;
		$dest_url = dol_buildpath('/product/card.php', 1) . '?id=' . $obj->dest_id;

		print '<tr class="oddeven">';
		print '<td>' . $obj->source_label . ' <span class="opacitymedium">(Entity ' . $obj->source_entity . ')</span></td>';
		print '<td><strong><a href="' . $source_url . '" target="_blank">' . $obj->source_ref . '</a></strong></td>';
		print '<td>' . $obj->dest_label . ' <span class="opacitymedium">(Entity ' . $obj->dest_entity . ')</span></td>';
		print '<td><strong><a href="' . $dest_url . '" target="_blank">' . $obj->dest_ref . '</a></strong></td>';
		print '<td class="center">';
		print '<a href="' . $_SERVER['PHP_SELF'] . '?action=delete&id=' . $obj->rowid . '&token=' . newToken() . '" class="reposition">';
		print img_delete();
		print '</a>';
		print '</td>';
		print '</tr>';

		$i++;
	}

	$db->free($resql);
} else {
	print '<tr><td colspan="5">' . $db->lasterror() . '</td></tr>';
}

print '</table>';

print dol_get_fiche_end();

llxFooter();
$db->close();

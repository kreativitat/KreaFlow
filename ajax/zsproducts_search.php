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
 * Ajax endpoint to search ZS products (codigo/descricao) from local table.
 *
 * Used by the menu builder on zsproduct.php.
 */

if (!defined('NOTOKENRENEWAL')) {
	define('NOTOKENRENEWAL', 1);
}
if (!defined('NOREQUIREMENU')) {
	define('NOREQUIREMENU', '1');
}
if (!defined('NOREQUIREHTML')) {
	define('NOREQUIREHTML', '1');
}
if (!defined('NOREQUIREAJAX')) {
	define('NOREQUIREAJAX', '1');
}
if (!defined('NOREQUIRESOC')) {
	define('NOREQUIRESOC', '1');
}
if (!defined('NOCSRFCHECK')) {
	define('NOCSRFCHECK', '1');
}

require '../../../main.inc.php';

top_httphead('application/json');

if (empty($user->id)) {
	http_response_code(401);
	echo json_encode(array());
	exit;
}
if (!isModEnabled('dolizsynch') || (empty($user->rights->produit->lire) && empty($user->rights->service->lire))) {
	http_response_code(403);
	echo json_encode(array());
	exit;
}

$termRaw = trim((string) GETPOST('term', 'restricthtml'));
$term = $termRaw;
// Allow users to paste/search codes like "#7401 (P)" or "7401 - ..."
if ($term !== '' && preg_match('/^#?\s*([0-9]+)/', $term, $m)) {
	$term = $m[1];
} elseif ($term !== '' && $term[0] === '#') {
	$term = ltrim($term, "# \t\n\r\0\x0B");
}
$store = GETPOSTINT('store');
if (empty($store)) {
	$store = !empty($conf->global->ZS_API_STORE) ? (int) $conf->global->ZS_API_STORE : 0;
}

$out = array();
if ($term !== '') {
	$like = $db->escape('%' . $term . '%');
	$termIsNumeric = ctype_digit($term);
	$productEntities = getEntity('product');
	if ($productEntities === '') {
		$productEntities = (string) ((int) $conf->entity);
	}
	$sqlBase = "SELECT zp.codigo, COALESCE(NULLIF(zp.descricao, ''), p.label) AS descricao";
	$sqlBase .= " FROM " . MAIN_DB_PREFIX . "dolizsynch_zsproduct AS zp";
	$sqlBase .= " INNER JOIN " . MAIN_DB_PREFIX . "product AS p ON p.rowid = zp.fk_product";
	$sqlWhere = " WHERE p.entity IN (" . $productEntities . ")";
	$sqlWhere .= " AND (CAST(zp.codigo AS CHAR) LIKE '" . $like . "'";
	$sqlWhere .= " OR zp.descricao LIKE '" . $like . "'";
	$sqlWhere .= " OR p.ref LIKE '" . $like . "'";
	$sqlWhere .= " OR p.label LIKE '" . $like . "')";
	$sqlStore = ($store > 0 ? " AND zp.loja_zs = " . ((int) $store) : "");
	$sqlOrder = ($termIsNumeric
		? " ORDER BY (zp.codigo = " . ((int) $term) . ") DESC, zp.codigo ASC"
		: " ORDER BY descricao ASC, zp.codigo ASC");
	$sqlLimit = " " . $db->plimit(20);

	$resql = $db->query($sqlBase . $sqlWhere . $sqlStore . $sqlOrder . $sqlLimit);
	// If store-specific search returns nothing, retry within the same entity scope without the store filter.
	if ($store > 0 && $resql && (int) $db->num_rows($resql) === 0) {
		$resql = $db->query($sqlBase . $sqlWhere . $sqlOrder . $sqlLimit);
	}
	if ($resql) {
		$seen = array();
		while ($obj = $db->fetch_object($resql)) {
			$ref = (string) $obj->codigo;
			if ($ref === '' || isset($seen[$ref])) {
				continue;
			}
			$seen[$ref] = true;
			$out[] = array(
				'ref' => $ref,
				'label' => (string) $obj->descricao,
				'value' => $ref . ' - ' . (string) $obj->descricao,
			);
		}
	}
}

echo json_encode($out);
exit;

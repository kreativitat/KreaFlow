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

$bootstrap = '/var/www/html/main.inc.php';
if (!is_file($bootstrap)) {
	echo "SKIP: installed Dolibarr integration is available only in lamp-php81\n";
	exit(0);
}

if (!defined('NOLOGIN')) {
	define('NOLOGIN', 1);
}
require_once $bootstrap;
require_once DOL_DOCUMENT_ROOT . '/bom/class/bom.class.php';
require_once DOL_DOCUMENT_ROOT . '/product/class/product.class.php';
require_once dirname(__DIR__) . '/core/modules/bom/mod_bom_dolizsynchid.php';

global $conf, $db;

$model = new mod_bom_dolizsynchid();

if ($model->getNextValue(null, (object) array('id' => 357)) !== 'BO_0000357') {
	throw new RuntimeException('BOM #357 did not resolve to BO_0000357.');
}
if ($model->getNextValue(null, (object) array('rowid' => 366)) !== 'BO_0000366') {
	throw new RuntimeException('The rowid fallback did not resolve BOM #366 to BO_0000366.');
}
if ($model->getNextValue(null, (object) array()) !== -1) {
	throw new RuntimeException('A BOM without a persisted technical ID did not fail closed.');
}
if (mod_bom_dolizsynchid::formatReference(12345678) !== 'BO_12345678') {
	throw new RuntimeException('BOM IDs longer than seven digits were truncated.');
}

$originalBomAddon = $conf->global->BOM_ADDON ?? null;
$conf->global->BOM_ADDON = 'mod_bom_dolizsynchid';
$bom = new BOM($db);
$bom->id = 357;
$bom->rowid = 357;
$resolvedReference = $bom->getNextNumRef(new Product($db));
if ($originalBomAddon === null) {
	unset($conf->global->BOM_ADDON);
} else {
	$conf->global->BOM_ADDON = $originalBomAddon;
}
if ($resolvedReference !== 'BO_0000357') {
	throw new RuntimeException('Dolibarr did not discover the DoliZSynch BOM numbering model.');
}

echo "PASS: BOM references are deterministic functions of bom_bom.rowid\n";

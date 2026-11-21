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

require_once dirname(__DIR__) . '/class/zsproductpropertyservice.class.php';

$failures = array();

function assertProductPropertySame($expected, $actual, $label)
{
	global $failures;
	if ($expected !== $actual) {
		$failures[] = $label;
		echo '[FAIL] ' . $label . "\n";
		return;
	}
	echo '[PASS] ' . $label . "\n";
}

function assertProductPropertyThrows($callback, $label)
{
	global $failures;
	try {
		$callback();
	} catch (InvalidArgumentException $e) {
		echo '[PASS] ' . $label . "\n";
		return;
	}
	$failures[] = $label;
	echo '[FAIL] ' . $label . "\n";
}

$definitions = ZSProductPropertyService::normalizeDefinitions(array(
	array(
		'id' => 12,
		'nome' => 'LOTE',
		'compra' => 2,
		'ativo' => 1,
		'ordenacao' => 'fifo',
		'tipo_dados' => 'string',
		'valores_multiplos' => 0,
		'venda' => 1,
	),
	array(
		'id' => 11,
		'nome' => 'N&amp;Uacute;MERO EMBALAGENS',
		'compra' => 1,
		'ativo' => 0,
		'ordenacao' => 'unexpected',
		'tipo_dados' => 'date',
		'valores_multiplos' => 1,
		'venda' => 2,
	),
));
assertProductPropertySame(array(12, 11), array_keys($definitions), 'definitions are keyed and sorted by decoded property name');
assertProductPropertySame('NÚMERO EMBALAGENS', $definitions[11]['nome'], 'HTML entities in property names are decoded to UTF-8');
assertProductPropertySame('nofx', $definitions[11]['ordenacao'], 'unknown sale priority fails closed to no effect');
assertProductPropertySame(2, $definitions[11]['venda'], 'sale requirement is preserved');
assertProductPropertySame('Não', ZSProductPropertyService::decodeDisplayText('N&amp;atilde;o'), 'double-encoded Portuguese text is decoded to UTF-8');

$relations = ZSProductPropertyService::normalizeRelations(array(
	array('uid' => 12, 'estado' => 0),
	array('cod_propriedade' => 11, 'estado' => 1),
	array('cod_propriedade' => 12, 'estado' => 1),
), '2600079', array(11, 12));
assertProductPropertySame(2, count($relations), 'duplicate property relations are deduplicated');
assertProductPropertySame(2600079, $relations[0]['cod_produto'], 'numeric product identifiers stay numeric');
assertProductPropertySame(11, $relations[0]['cod_propriedade'], 'relations are sorted by property identifier');
assertProductPropertySame(1, $relations[1]['estado'], 'the final duplicate relation state wins');
assertProductPropertySame(12, $relations[1]['uid'], 'uid aliases the canonical property identifier');
$defaultStateRelation = ZSProductPropertyService::normalizeRelations(array(array('uid' => 11)), '10', array(11));
assertProductPropertySame(1, $defaultStateRelation[0]['estado'], 'a missing relation state uses the documented enabled default');

assertProductPropertyThrows(static function () {
	ZSProductPropertyService::normalizeRelations('[{"uid":99}]', '10', array(11, 12));
}, 'relations outside the loaded dictionary are rejected');
assertProductPropertyThrows(static function () {
	ZSProductPropertyService::normalizeRelations('{"uid":12}', '10');
}, 'non-list property JSON is rejected');
assertProductPropertySame(false, ZSProductPropertyService::hasRows('[]'), 'an empty characteristics list does not conflict');
assertProductPropertySame(true, ZSProductPropertyService::hasRows('[{"id":1}]'), 'a populated characteristics list conflicts');
assertProductPropertySame(true, ZSProductPropertyService::hasRows('invalid'), 'invalid non-empty characteristics fail closed');

$productPageSource = file_get_contents(dirname(__DIR__) . '/zsproduct.php');
$syncToolsSource = file_get_contents(dirname(__DIR__) . '/admin/sync_tools.php');
$dictionarySource = file_get_contents(dirname(__DIR__) . '/class/zsdictionairsynch.class.php');
assertProductPropertySame(true, strpos($productPageSource, "propertyTrans('DoliZSynchPropertyStatus')") !== false, 'property status uses its decoded dedicated translation key');
assertProductPropertySame(true, strpos($productPageSource, "propertyTrans('DoliZSynchPropertyActions')") !== false, 'property actions use their decoded dedicated translation key');
assertProductPropertySame(true, strpos($productPageSource, "propertyTrans('DoliZSynchPropertyRemove')") !== false, 'property removal uses its decoded dedicated translation key');
assertProductPropertySame(true, strpos($productPageSource, 'new ActionsDoliZSynch($db)') !== false, 'dedicated product tab invokes only its module-owned action controller');
assertProductPropertySame(false, strpos($productPageSource, "initHooks(array('productcard', 'globalcard'))") !== false, 'dedicated product tab does not load unrelated product-card hooks');
assertProductPropertySame(false, strpos($productPageSource, "executeHooks('formObjectOptions'") !== false, 'dedicated product tab does not render unrelated module fields');
assertProductPropertySame(false, strpos($productPageSource, "executeHooks('doActions'") !== false, 'dedicated product tab does not submit data to unrelated modules');
assertProductPropertySame(true, strpos($syncToolsSource, "GETPOST('synchProperties', 'alpha')") !== false, 'sync tools expose a dedicated property dictionary action');
assertProductPropertySame(true, strpos($syncToolsSource, 'dolizsynch_sync_tools_require_secure_post();') !== false, 'property synchronization requires the secured POST path');
assertProductPropertySame(true, strpos($syncToolsSource, "'submit' => 'synchProperties'") !== false, 'property synchronization is rendered in the dictionary tools section');
assertProductPropertySame(true, strpos($dictionarySource, 'public function synchronizeProperties()') !== false, 'dictionary service exposes property synchronization');
assertProductPropertySame(true, strpos($dictionarySource, "'Properties',\n\t\t\t'property',") !== false, 'property synchronization uses the documented ZoneSoft Properties interface');
assertProductPropertySame(true, strpos($dictionarySource, "validateEntityStoreScope(\$store, \$entity, __METHOD__ . ':' . \$interfaceSlug)") !== false, 'property dictionary caching validates the active entity and configured store');

$portugueseTranslations = file_get_contents(dirname(__DIR__) . '/langs/pt_PT/dolizsynch.lang');
assertProductPropertySame(true, strpos($portugueseTranslations, 'DoliZSynchPropertyStatus = Estado') !== false, 'Portuguese property status is Estado');
assertProductPropertySame(true, strpos($portugueseTranslations, 'DoliZSynchPropertyActions = Ações') !== false, 'Portuguese property actions are Ações');
assertProductPropertySame(true, strpos($portugueseTranslations, 'DoliZSynchPropertyActive = Ativo') !== false, 'Portuguese active relation state is Ativo');
assertProductPropertySame(true, strpos($portugueseTranslations, 'DoliZSynchPropertyRemove = Remover') !== false, 'Portuguese remove action is Remover');
assertProductPropertySame(true, strpos($portugueseTranslations, 'DoliZSynchPropertyNo = Não') !== false, 'Portuguese negative value is readable UTF-8');

if (!empty($failures)) {
	echo "\n" . count($failures) . " product property test(s) failed.\n";
	exit(1);
}

echo "\nAll product property tests passed.\n";

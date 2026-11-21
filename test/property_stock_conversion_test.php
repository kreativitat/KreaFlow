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

require_once dirname(__DIR__) . '/class/zspropertystockservice.class.php';

$failures = array();

function assertPropertyStockSame($expected, $actual, $label)
{
	global $failures;
	if ($expected !== $actual) {
		$failures[] = $label;
		echo '[FAIL] ' . $label . "\n";
		return;
	}
	echo '[PASS] ' . $label . "\n";
}

function assertPropertyStockThrows($callback, $label)
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

$rule = array(
	'fk_product' => 7250,
	'fk_bom' => 214,
	'fk_bom_line' => 901,
	'fk_product_output' => 2826,
	'property_id' => 3,
	'property_name' => 'NUMERO EMBALAGENS',
	'units_per_package' => '10',
);
$line = array(
	'id' => 44,
	'codigo' => 2438,
	'qtd' => '1.5',
	'uid_propriedade' => 9001,
);
$movements = array(
	array('id' => 9001, 'idpropriedade' => 3, 'valor_propriedade' => '2'),
);
$plan = ZSPropertyStockService::buildLinePlan($line, $movements, $rule);
assertPropertyStockSame('2', $plan['package_count'], 'source property is preserved as the package count');
assertPropertyStockSame('10', $plan['units_per_package'], 'the BOM output quantity defines destination units per package');
assertPropertyStockSame('20', $plan['target_units'], 'package count is multiplied by the BOM output quantity');
assertPropertyStockSame(7250, $plan['fk_product_input'], 'the destination purchase product remains the stock input');
assertPropertyStockSame(214, $plan['fk_bom'], 'the selected dismantling BOM is preserved in the immutable plan');
assertPropertyStockSame(901, $plan['fk_bom_line'], 'the selected BOM output line is preserved in the immutable plan');
assertPropertyStockSame(2826, $plan['fk_product_output'], 'the BOM-derived unit product is the exact stock output');

$valuation = ZSPropertyStockService::buildValuationEvidence('1.8', '15.40', '0', '27.72', '10');
assertPropertyStockSame('1.8', $valuation['source_purchase_qty'], 'valuation evidence preserves the purchased weight');
assertPropertyStockSame('15.4', $valuation['source_unit_cost'], 'valuation evidence preserves the net purchase cost per kilogram');
assertPropertyStockSame('27.72', $valuation['source_stock_value'], 'valuation evidence preserves the full-precision purchased stock value');
assertPropertyStockSame('27.72', $valuation['source_line_total_ht'], 'valuation evidence preserves the legal supplier invoice line total');
assertPropertyStockSame('2.772', $valuation['exact_unit_cost'], 'exact unit cost divides purchased value by package-derived units');
$discountedValuation = ZSPropertyStockService::buildValuationEvidence('1.8', '15.40', '10', '24.95', '10');
assertPropertyStockSame('13.86', $discountedValuation['source_unit_cost'], 'valuation evidence applies the supplier line discount to unit cost');
assertPropertyStockSame('24.948', $discountedValuation['source_stock_value'], 'valuation evidence retains discounted stock value precision independently from legal cents');
assertPropertyStockSame('2.4948', $discountedValuation['exact_unit_cost'], 'discounted purchase value is allocated across exact units');
assertPropertyStockThrows(static function () {
	ZSPropertyStockService::buildValuationEvidence('1.8', '15.40', '101', '27.72', '10');
}, 'invalid invoice valuation percentages fail closed');

$decimalRule = $rule;
$decimalRule['units_per_package'] = '8.33333';
$decimalPlan = ZSPropertyStockService::buildLinePlan($line, $movements, $decimalRule);
assertPropertyStockSame('16.66666', $decimalPlan['target_units'], 'positive decimal BOM output quantities are multiplied without being rounded to whole units');

$namedMovement = array(array(
	'id' => 9001,
	'idpropriedade' => 99,
	'nome_propriedade' => 'N&Uacute;MERO   EMBALAGENS',
	'valor' => '1',
));
$namedRule = $rule;
$namedRule['property_name'] = 'NÚMERO EMBALAGENS';
$namedPlan = ZSPropertyStockService::buildLinePlan($line, $namedMovement, $namedRule);
assertPropertyStockSame('10', $namedPlan['target_units'], 'decoded normalized property name is a safe fallback when store IDs differ');
assertPropertyStockSame('NÚMERO   EMBALAGENS', $namedPlan['property_name'], 'the immutable plan preserves the decoded source movement property label');

assertPropertyStockThrows(static function () use ($line, $rule) {
	ZSPropertyStockService::buildLinePlan($line, array(), $rule);
}, 'configured lines without source property evidence fail closed');
assertPropertyStockThrows(static function () use ($line, $movements, $rule) {
	$invalid = $movements;
	$invalid[0]['valor_propriedade'] = '1.5';
	ZSPropertyStockService::buildLinePlan($line, $invalid, $rule);
}, 'fractional package counts fail closed');
assertPropertyStockThrows(static function () use ($line, $movements, $rule) {
	$ambiguous = array($movements[0], $movements[0]);
	ZSPropertyStockService::buildLinePlan($line, $ambiguous, $rule);
}, 'duplicate source property evidence fails closed');

$converterSource = file_get_contents(dirname(__DIR__) . '/class/zsstoreinvoiceconverter.class.php');
assertPropertyStockSame(true, strpos($converterSource, "['movimentospropriedades']") !== false, 'inter-entity mapping reads property movements from the full source document');
assertPropertyStockSame(true, strpos($converterSource, 'savePlan(') !== false, 'supplier invoice creation persists the exact line plan');
$triggerSource = file_get_contents(dirname(__DIR__) . '/core/triggers/interface_99_modDoliZSynch_DoliZSynchTriggers.class.php');
assertPropertyStockSame(true, strpos($triggerSource, "\$action === 'MRP_MO_PRODUCED'") !== false, 'native completed-MO lifecycle drives reconciliation after execution lines exist');
assertPropertyStockSame(true, strpos($triggerSource, "\$action === 'STOCK_MOVEMENT'") !== false, 'stock-movement reconciliation remains a backward-compatible fallback');
$serviceSource = file_get_contents(dirname(__DIR__) . '/class/zspropertystockservice.class.php');
assertPropertyStockSame(true, strpos($serviceSource, "WHERE entity=' . \$entity") !== false, 'property stock rules are explicitly entity-scoped');
assertPropertyStockSame(true, strpos($serviceSource, "setOrigin('invoice_supplier', \$invoiceId)") !== false, 'correction movements remain owned by the supplier invoice cancellation ledger');
assertPropertyStockSame(true, strpos($serviceSource, 'deleteRulesForProduct') !== false, 'product deletion removes live rule references while preserving historical plans');
assertPropertyStockSame(true, strpos($serviceSource, 'fetchValidatedDismantlingBoms') !== false, 'eligible choices are loaded from validated dismantling BOMs owned by the purchase product');
assertPropertyStockSame(true, strpos($serviceSource, '$lineCount !== 1') !== false, 'only BOMs with one unambiguous stock output are eligible');
assertPropertyStockSame(true, strpos($serviceSource, 'fetchDismantlingBomCandidates') !== false, 'ineligible dismantling BOMs remain available for UI diagnostics');
assertPropertyStockSame(true, strpos($serviceSource, "getEntity('bom')") !== false, 'eligible BOMs use Dolibarr native multicompany visibility scope');
assertPropertyStockSame(true, strpos($serviceSource, 'bom.entity=0') !== false, 'legacy rules for shared BOMs are visible outside their original configuration entity');
assertPropertyStockSame(true, strpos($serviceSource, '$ruleEntity = (int) $selection[\'bom_entity\'] === 0 ? 0 : $entity') !== false, 'shared BOM rules are persisted once under entity zero');
assertPropertyStockSame(true, strpos($serviceSource, 'legacy_rule.entity<>0') !== false, 'saving a shared rule retires legacy entity-owned shared-BOM copies');
assertPropertyStockSame(true, strpos($serviceSource, 'mo.fk_bom') !== false, 'stock reconciliation is bound to the exact BOM used by the manufacturing order');
assertPropertyStockSame(true, strpos($serviceSource, 'fk_bom=IF(status=0,VALUES(fk_bom),fk_bom)') !== false, 'the selected BOM is persisted in pending plans without rewriting processed evidence');
assertPropertyStockSame(true, strpos($serviceSource, 'units_per_package=VALUES(units_per_package)') !== false, 'rule persistence snapshots the BOM output quantity per package');
assertPropertyStockSame(true, strpos($serviceSource, 'ZSDecimal::multiply($packages, $unitsPerPackage)') !== false, 'plan building multiplies packages by BOM units without floating-point arithmetic');
assertPropertyStockSame(true, strpos($serviceSource, 'Exact stock quantity must equal package count multiplied by the BOM output quantity.') !== false, 'plan persistence enforces the package-times-BOM invariant');
assertPropertyStockSame(true, strpos($serviceSource, 'buildValuationEvidence') !== false, 'plan persistence snapshots full-precision supplier invoice valuation evidence');
assertPropertyStockSame(true, strpos($serviceSource, 'Automatic dismantling value does not reconcile with the supplier invoice stock movement.') !== false, 'stock correction fails closed when MO and native supplier movement valuation differ');
assertPropertyStockSame(true, strpos($serviceSource, '$nativeSourceStockValue') !== false, 'native MO output is reconciled against Dolibarr supplier movement valuation before net exact correction');
assertPropertyStockSame(true, strpos($serviceSource, 'ZSDecimal::divide($sourceStockValue, $targetUnits, 8)') !== false, 'exact correction price uses supplier purchase value divided by exact units');
assertPropertyStockSame(true, strpos($serviceSource, 'IF(status=0,VALUES(source_stock_value),source_stock_value)') !== false, 'processed valuation evidence is immutable on duplicate plan saves');
assertPropertyStockSame(true, strpos($serviceSource, 'fetchInvoicePlans') !== false, 'supplier invoice lines can read their immutable exact-stock audit evidence');
$actionsSource = file_get_contents(dirname(__DIR__) . '/class/actions_dolizsynch.class.php');
assertPropertyStockSame(true, strpos($actionsSource, "empty(\$user->rights->produit->creer)") !== false, 'configurator writes retain Dolibarr product write permission enforcement');
assertPropertyStockSame(false, strpos($actionsSource, 'zs_product_property_ids') !== false, 'destination purchase product does not need the source property assigned');
assertPropertyStockSame(false, strpos($actionsSource, "GETPOSTINT('DOLIZSYNCH_PROPERTY_STOCK_PROPERTY_ID')") !== false, 'product submissions cannot override the setup-owned source property');
assertPropertyStockSame(false, strpos($actionsSource, "GETPOSTINT('DOLIZSYNCH_PROPERTY_STOCK_OUTPUT_PRODUCT')") !== false, 'product submissions cannot choose an arbitrary output product');
assertPropertyStockSame(true, strpos($actionsSource, "GETPOSTINT('DOLIZSYNCH_PROPERTY_STOCK_BOM_ID')") !== false, 'product submissions select the authoritative dismantling BOM');
assertPropertyStockSame(false, strpos($actionsSource, "GETPOSTINT('DOLIZSYNCH_PROPERTY_STOCK_BOM_LINE_ID')") !== false, 'product submissions cannot choose a BOM output line');
assertPropertyStockSame(false, strpos($actionsSource, "GETPOSTINT('DOLIZSYNCH_PROPERTY_STOCK_UNITS_PER_PACKAGE')") !== false, 'product submissions cannot provide a stock multiplier');
assertPropertyStockSame(false, strpos($actionsSource, "GETPOSTINT('DOLIZSYNCH_PROPERTY_STOCK_ACTIVE')") !== false, 'the selected BOM itself controls whether conversion is active');
$productPageSource = file_get_contents(dirname(__DIR__) . '/zsproduct.php');
assertPropertyStockSame(true, strpos($productPageSource, 'if ($configuredStockPropertyDefinition !== null)') !== false, 'exact-stock form is hidden until setup resolves a valid source property');
assertPropertyStockSame(false, strpos($productPageSource, 'DOLIZSYNCH_PROPERTY_STOCK_PROPERTY_ID') !== false, 'product form does not expose a second source-property selector');
assertPropertyStockSame(true, strpos($productPageSource, '<div class="opacitymedium">(#') !== false, 'source-property setup metadata is rendered below the property name');
assertPropertyStockSame(2, substr_count($productPageSource, 'div-table-responsive"><table class="noborder centpercent dzs-property-table'), 'exact-stock editor reuses the same wrapper and table classes as the property editor');
assertPropertyStockSame(false, strpos($productPageSource, 'dzs-property-stock-table') !== false, 'exact-stock editor has no table-specific border styling');
assertPropertyStockSame(false, strpos($productPageSource, 'dzs-property-stock-fieldset') !== false, 'exact-stock editor has no fieldset border surrounding the native table');
assertPropertyStockSame(false, strpos($productPageSource, '<th class="opacitymedium">') !== false, 'header opacity does not alter the complete table cell border or background');
assertPropertyStockSame(false, strpos($productPageSource, 'dzs-property-stock-toggle-track') !== false, 'exact-stock editor has no obsolete custom colored toggle');
assertPropertyStockSame(true, strpos($productPageSource, 'DOLIZSYNCH_PROPERTY_STOCK_BOM_ID') !== false, 'exact-stock editor exposes validated dismantling BOM selection');
assertPropertyStockSame(false, strpos($productPageSource, 'DOLIZSYNCH_PROPERTY_STOCK_BOM_LINE_ID') !== false, 'exact-stock editor does not expose a second BOM-output choice');
assertPropertyStockSame(false, strpos($productPageSource, 'DOLIZSYNCH_PROPERTY_STOCK_UNITS_PER_PACKAGE') !== false, 'exact-stock editor does not expose a unit multiplier');
assertPropertyStockSame(false, strpos($productPageSource, 'DOLIZSYNCH_PROPERTY_STOCK_ACTIVE') !== false, 'exact-stock editor does not expose a redundant activation checkbox');
assertPropertyStockSame(false, strpos($productPageSource, 'DOLIZSYNCH_PROPERTY_STOCK_OUTPUT_PRODUCT') !== false, 'exact-stock editor no longer renders the generic product selector');
assertPropertyStockSame(false, strpos($productPageSource, 'select_produits($stockRuleOutputProduct') !== false, 'exact-stock editor cannot select a product independently from a BOM');
assertPropertyStockSame(true, strpos($productPageSource, '$stockRuleCanReuseSelection') !== false, 'disabled or stale rules do not silently preselect and reactivate their old BOM');
assertPropertyStockSame(false, strpos($productPageSource, "['units_per_package'] ?? '') === '1'") !== false, 'saved BOM selections are not restricted to the obsolete multiplier value of one');
assertPropertyStockSame(true, strpos($productPageSource, 'fetchDismantlingBomCandidates') !== false, 'the product selector explains why an unavailable dismantling BOM cannot be selected');
$setupSource = file_get_contents(dirname(__DIR__) . '/admin/setup.php');
assertPropertyStockSame(true, strpos($setupSource, "newItem('DOLIZSYNCH_PROPERTY_STOCK_SOURCE_PROPERTY_ID')") !== false, 'setup owns the package-count property selection');
assertPropertyStockSame(false, stripos($setupSource, 'NUMERO EMBALAGENS') !== false, 'setup does not identify the package-count property by a hard-coded name');
assertPropertyStockSame(1, substr_count($setupSource, "newItem('ZS_INTERENTITY_INVOICE_TITLE')->setAsTitle()"), 'inter-entity setup uses one unambiguous section heading');
assertPropertyStockSame(true, strpos($setupSource, "newItem('ZS_INTERENTITY_INVOICE_TITLE')->setAsTitle()") < strpos($setupSource, "newItem('DOLIZSYNCH_PROPERTY_STOCK_SOURCE_PROPERTY_ID')"), 'package-count property is grouped under inter-entity setup');
assertPropertyStockSame(true, strpos($setupSource, "newItem('ZS_SYNC_INVOICES_TITLE')->setAsTitle()") < strpos($setupSource, "newItem('ZS_ADJUSTMENT_PRODUCT_ID')"), 'invoice-only product defaults are grouped under invoice synchronization');
assertPropertyStockSame(false, strpos($setupSource, "/kreaflow/admin/mapping.php#storemap") !== false, 'setup does not render the broken store/entity mapping shortcut');
$converterSource = file_get_contents(dirname(__DIR__) . '/class/zsstoreinvoiceconverter.class.php');
assertPropertyStockSame(true, strpos($converterSource, "getDolGlobalInt('DOLIZSYNCH_PROPERTY_STOCK_SOURCE_PROPERTY_ID')") !== false, 'conversion reads the target entity setup property');
assertPropertyStockSame(true, strpos($converterSource, "\$propertyStockRule['property_id'] = \$configuredPropertyStockId") !== false, 'shared BOM rules bind to each destination entity package property at conversion time');
assertPropertyStockSame(true, strpos($converterSource, "(int) \$propertyStockRule['property_id'] !== \$configuredPropertyStockId") !== false, 'stale entity-specific product rules are ignored after setup changes');
assertPropertyStockSame(1, preg_match(
	'/array_key_exists\(\$destProductId, \$propertyStockRules\).*?activateEntityConfiguration\(\(int\) \$targetEntity\).*?fetchRule\(\$destProductId, \(int\) \$targetEntity, true\).*?activateEntityConfiguration\(\$previousEntity\)/s',
	$converterSource
), 'destination BOM visibility is validated under the target entity configuration and then restored');
$schemaSource = file_get_contents(dirname(__DIR__) . '/sql/llx_dolizsynch.sql');
assertPropertyStockSame(true, substr_count($schemaSource, 'fk_bom                  integer NOT NULL') >= 2, 'fresh rule and plan schemas require the selected BOM identity');
assertPropertyStockSame(true, substr_count($schemaSource, 'fk_bom_line             integer NOT NULL') >= 2, 'fresh rule and plan schemas require the selected BOM output line');
assertPropertyStockSame(true, strpos($schemaSource, 'source_stock_value      decimal(24,8) NOT NULL') !== false, 'fresh plan schema stores full-precision purchase stock value');
assertPropertyStockSame(true, strpos($schemaSource, 'exact_unit_cost         decimal(24,8) NOT NULL') !== false, 'fresh plan schema stores audited exact unit cost');
$upgradeSource = file_get_contents(dirname(__DIR__) . '/sql/upgrade/1.32.0.sql');
assertPropertyStockSame(true, strpos($upgradeSource, 'SET active = 0') !== false, 'upgrade disables ambiguous legacy rules without a BOM');
assertPropertyStockSame(true, strpos($upgradeSource, 'SET status = 2') !== false, 'upgrade quarantines pending legacy plans without a BOM');
$simplificationUpgradeSource = file_get_contents(dirname(__DIR__) . '/sql/upgrade/1.33.0.sql');
assertPropertyStockSame(true, strpos($simplificationUpgradeSource, 'units_per_package <> 1') !== false, 'simplification upgrade disables obsolete multiplier rules and plans');
assertPropertyStockSame(true, strpos($simplificationUpgradeSource, 'bom_lines.line_count, 0) <> 1') !== false, 'simplification upgrade rejects BOMs without exactly one output line');
$packageUpgradeSource = file_get_contents(dirname(__DIR__) . '/sql/upgrade/1.34.0.sql');
assertPropertyStockSame(true, strpos($packageUpgradeSource, 'SET stock_rule.units_per_package = bom_line.qty') !== false, 'package upgrade refreshes rule snapshots from the selected BOM output quantity');
assertPropertyStockSame(true, strpos($packageUpgradeSource, 'stock_plan.status = 2') !== false, 'package upgrade quarantines incompatible pending plans instead of rewriting financial evidence');
$valuationUpgradeSource = file_get_contents(dirname(__DIR__) . '/sql/upgrade/1.36.0.sql');
assertPropertyStockSame(true, strpos($valuationUpgradeSource, 'ADD COLUMN IF NOT EXISTS source_stock_value') !== false, 'valuation upgrade adds backward-compatible purchase value evidence');
assertPropertyStockSame(true, strpos($valuationUpgradeSource, 'ADD COLUMN IF NOT EXISTS exact_unit_cost') !== false, 'valuation upgrade adds backward-compatible exact unit cost evidence');
assertPropertyStockSame(true, strpos($actionsSource, 'DoliZSynchPropertyStockBomWarning') !== false, 'the native BOM card renders exact-stock package guidance through the module hook');
assertPropertyStockSame(true, strpos($actionsSource, "tr.field_qty td.titlefieldmiddle.fieldname_qty") !== false, 'configured exact-stock BOM cards locate the native weight quantity label');
assertPropertyStockSame(true, strpos($actionsSource, "tr.field_qty td.valuefield.fieldname_qty") !== false, 'configured exact-stock BOM cards preserve the native weight quantity value cell');
assertPropertyStockSame(true, strpos($actionsSource, "mb_strtolower(\$propertyName, 'UTF-8')") !== false, 'the configured property name is lowercased safely for the package quantity label');
assertPropertyStockSame(true, strpos($actionsSource, "DoliZSynchPropertyStockBomPackageQuantity', \$quantityPropertyName") !== false, 'the package quantity label uses the configured property name dynamically');
assertPropertyStockSame(true, strpos($actionsSource, "packageRow.className='field_dolizsynch_package_basis dolizsynch-property-stock-package-basis'") !== false, 'the one-package basis is rendered as a separate native-style BOM row');
assertPropertyStockSame(true, strpos($actionsSource, "packageText.className='valignmiddle'") !== false, 'the package quantity text uses the native Dolibarr field-label alignment');
assertPropertyStockSame(true, strpos($actionsSource, "packageText.style.padding='0'") !== false, 'the package quantity text resets padding like the native product field');
assertPropertyStockSame(true, strpos($actionsSource, "packageText.style.paddingRight='2px'") !== false, 'the package quantity text keeps the native two-pixel separation before its tooltip');
assertPropertyStockSame(true, strpos($actionsSource, "DoliZSynchPropertyStockBomWeightQuantity") !== false, 'the native BOM quantity is labelled separately as nominal weight');
assertPropertyStockSame(true, strpos($actionsSource, "fa-exclamation-triangle paddingright") !== false, 'configured exact-stock BOM quantity values use the native icon padding class');
assertPropertyStockSame(true, strpos($actionsSource, "warningIcon.style.color='#a69944'") !== false, 'configured exact-stock BOM quantity values use the native product icon color');
assertPropertyStockSame(true, strpos($actionsSource, 'packageValue.appendChild(warningIcon)') !== false, 'the warning icon is inserted before the one-package basis value');
assertPropertyStockSame(true, strpos($actionsSource, 'packageTitle.appendChild(help)') !== false, 'the explanatory tooltip is appended after the configured property label');
assertPropertyStockSame(true, strpos($actionsSource, "classfortooltip valignmiddle") !== false, 'configured exact-stock BOM quantity labels include native tooltip help');
assertPropertyStockSame(true, strpos($actionsSource, "DoliZSynchPropertyStockBomPackageQuantityHelp") !== false, 'the package quantity tooltip is translated and populated from the active rule');
assertPropertyStockSame(true, strpos($actionsSource, "DoliZSynchPropertyStockBomNominalEstimate") !== false, 'the BOM card explains the nominal weight-based unit cost separately from audited invoice valuation');
assertPropertyStockSame(true, strpos($actionsSource, 'if ($isConfiguredRule)') !== false, 'unconfigured BOM candidates retain the native quantity label');
assertPropertyStockSame(false, strpos($actionsSource, 'MOD(bom_line.qty, 1)=0') !== false, 'decimal-output BOM cards retain exact-stock package guidance');
assertPropertyStockSame(true, strpos($actionsSource, 'objectLineView_BeforeProductExtrafield') !== false, 'supplier invoice lines render immutable exact-stock audit evidence through a native line hook');
assertPropertyStockSame(true, strpos($actionsSource, 'DoliZSynchPropertyStockAuditValuation') !== false, 'supplier invoice audit renders purchase value and exact unit cost');
$productSource = file_get_contents(dirname(__DIR__) . '/zsproduct.php');
assertPropertyStockSame(true, strpos($productSource, "DoliZSynchPropertyStockSharedBomRule', \$stockPropertyName") !== false, 'shared-BOM notice names the configured source property dynamically');
assertPropertyStockSame(true, strpos($productSource, 'decodeDisplayText') !== false, 'shared-BOM notice decodes legacy HTML entities before final escaping');
assertPropertyStockSame(true, strpos($productSource, "\$sharedOutputQuantity = (string) \$sharedOutput['qty']") !== false, 'shared-BOM formula uses the selected BOM output quantity without hard-coded or integer-only conversion');
assertPropertyStockSame(true, strpos($productSource, "dol_escape_htmltag(\$sharedOutputQuantity)") !== false, 'shared-BOM notice renders the configured units-per-package quantity');
assertPropertyStockSame(false, strpos($productSource, 'fa-long-arrow-right') !== false, 'read-only exact-stock summary uses a vertical hierarchy without ambiguous arrows');
assertPropertyStockSame(true, strpos($productSource, 'flex-direction: column; align-items: flex-start') !== false, 'read-only exact-stock summary keeps status, property, BOM, scope, and formula on ordered lines');
$portugueseTranslations = file_get_contents(dirname(__DIR__) . '/langs/pt_PT/dolizsynch.lang');
assertPropertyStockSame(true, strpos($portugueseTranslations, 'DoliZSynchPropertyStockBomPackageQuantity = Quant. (%s)') !== false, 'Portuguese BOM quantity label abbreviates quantity before the configured property');
assertPropertyStockSame(true, strpos($portugueseTranslations, 'DoliZSynchPropertyStockBomWeightQuantity = Quant. (peso)') !== false, 'Portuguese BOM weight label is distinct from the package basis');
assertPropertyStockSame(true, strpos($actionsSource, "help.style.padding='0'") !== false, 'the information tooltip resets padding like the native product field');
assertPropertyStockSame(true, strpos($actionsSource, "help.style.paddingRight='2px'") !== false, 'the information tooltip uses the native product-field right padding');
assertPropertyStockSame(false, strpos($actionsSource, "help.style.marginLeft") !== false, 'the information icon has no injected margin');
assertPropertyStockSame(false, strpos($actionsSource, "warningIcon.style.margin") !== false, 'the warning icon relies on native padding without an injected margin');
assertPropertyStockSame(true, strpos($portugueseTranslations, 'DoliZSynchPropertyStockBomPackageQuantityHelp') !== false, 'Portuguese package quantity help explains the exact-stock calculation');
assertPropertyStockSame(true, strpos($portugueseTranslations, 'DoliZSynchPropertyStockBomNominalEstimate = Estimativa nominal da BOM:') !== false, 'Portuguese nominal preview distinguishes recipe pricing from invoice valuation');
assertPropertyStockSame(true, strpos($portugueseTranslations, 'DoliZSynchPropertyStockAuditValuation = Valorização da compra:') !== false, 'Portuguese audit explains exact purchase valuation');
assertPropertyStockSame(true, strpos($portugueseTranslations, 'com a propriedade "%s" configurada') !== false, 'Portuguese shared-BOM notice inserts the configured property name');

if (!empty($failures)) {
	echo "\n" . count($failures) . " property stock conversion test(s) failed.\n";
	exit(1);
}

echo "\nAll property stock conversion tests passed.\n";

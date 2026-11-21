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
require_once DOL_DOCUMENT_ROOT . '/fourn/class/fournisseur.facture.class.php';
require_once DOL_DOCUMENT_ROOT . '/bom/class/bom.class.php';
require_once DOL_DOCUMENT_ROOT . '/product/class/product.class.php';
require_once DOL_DOCUMENT_ROOT . '/user/class/user.class.php';
require_once dirname(__DIR__) . '/class/zspropertystockservice.class.php';
require_once dirname(__DIR__) . '/class/actions_dolizsynch.class.php';

global $db, $conf, $user;

$baseline = (int) $db->transaction_opened;
$originalUser = $user;
$failure = '';
$evidence = array();
$invoiceId = 0;
$moIds = array();
$movementIds = array();
$planId = 0;
$ruleId = 0;
$originalRule = null;
$originalBomStatus = null;
$invoiceRef = 'DZS-E2E-PROP-' . date('YmdHis');

try {
	$entity = (int) $conf->entity;
	if ($entity !== 1) {
		throw new RuntimeException('The localhost fixture must run in entity 1');
	}

	$sql = 'SELECT rowid FROM ' . MAIN_DB_PREFIX . "product WHERE ref='4538' AND entity=" . $entity . $db->plimit(1);
	$resql = $db->query($sql);
	$input = $resql ? $db->fetch_object($resql) : null;
	$sql = 'SELECT rowid FROM ' . MAIN_DB_PREFIX . "product WHERE ref='712' AND entity=" . $entity . $db->plimit(1);
	$resql = $db->query($sql);
	$output = $resql ? $db->fetch_object($resql) : null;
	if (!$input || !$output) {
		throw new RuntimeException('Products 4538 and 712 are required');
	}

	$sql = 'SELECT b.rowid, b.status, bl.rowid AS line_id, bl.qty';
	$sql .= ' FROM ' . MAIN_DB_PREFIX . 'bom_bom b';
	$sql .= ' INNER JOIN ' . MAIN_DB_PREFIX . 'bom_bomline bl ON bl.fk_bom=b.rowid';
	$sql .= ' WHERE b.rowid=83 AND b.fk_product=' . (int) $input->rowid . ' AND b.bomtype=1';
	$sql .= ' AND bl.fk_product=' . (int) $output->rowid . ' ORDER BY b.rowid DESC' . $db->plimit(1);
	$resql = $db->query($sql);
	$bom = $resql ? $db->fetch_object($resql) : null;
	if (!$bom || (int) $bom->rowid !== 83 || ZSDecimal::compare((string) $bom->qty, '10') !== 0) {
		throw new RuntimeException('Localhost BOM #83 must define 10 destination units per package');
	}
	$originalBomStatus = (int) $bom->status;

	$sql = 'SELECT * FROM ' . MAIN_DB_PREFIX . 'dolizsynch_property_stock_rule';
	$sql .= ' WHERE entity=' . $entity . ' AND fk_product=' . (int) $input->rowid . $db->plimit(1);
	$resql = $db->query($sql);
	$originalRuleRow = $resql ? $db->fetch_object($resql) : null;
	$originalRule = $originalRuleRow ? (array) $originalRuleRow : null;

	$warehouseId = (int) getDolGlobalInt('KREAPRODUCTS_DISMANTLE_WAREHOUSE');
	if ($warehouseId <= 0) {
		$warehouseId = (int) getDolGlobalInt('MAIN_DEFAULT_WAREHOUSE');
	}
	$sql = 'SELECT rowid FROM ' . MAIN_DB_PREFIX . 'entrepot';
	$sql .= ' WHERE rowid=' . $warehouseId . ' AND entity=' . $entity . ' AND statut=1';
	$resql = $db->query($sql);
	if (!$resql || !$db->fetch_object($resql)) {
		throw new RuntimeException('Configured dismantling warehouse is unavailable');
	}

	$sql = 'SELECT rowid FROM ' . MAIN_DB_PREFIX . 'societe';
	$sql .= ' WHERE entity=' . $entity . ' AND fournisseur=1 AND status=1 ORDER BY rowid' . $db->plimit(1);
	$resql = $db->query($sql);
	$supplier = $resql ? $db->fetch_object($resql) : null;
	$sql = 'SELECT rowid FROM ' . MAIN_DB_PREFIX . 'user';
	$sql .= ' WHERE statut=1 AND entity IN (0, ' . $entity . ') ORDER BY admin DESC, rowid' . $db->plimit(1);
	$resql = $db->query($sql);
	$actorRow = $resql ? $db->fetch_object($resql) : null;
	$actor = new User($db);
	if (!$supplier || !$actorRow || $actor->fetch((int) $actorRow->rowid) <= 0) {
		throw new RuntimeException('Supplier or lifecycle actor is unavailable');
	}
	$actor->loadRights('', 0);
	$user = $actor;

	if (!$db->begin()) {
		throw new RuntimeException('Unable to begin rollback-only transaction');
	}
	$bomObject = new Bom($db);
	if ($bomObject->fetch((int) $bom->rowid) <= 0) {
		throw new RuntimeException($bomObject->error ?: 'Unable to load BOM #83');
	}
	if ((int) $bomObject->status !== 1 && $bomObject->validate($actor) <= 0) {
		throw new RuntimeException($bomObject->error ?: 'Unable to validate BOM #83 inside rollback-only test');
	}
	$evidence['bom_original_status'] = $originalBomStatus;
	$evidence['bom_test_status'] = (int) $bomObject->status;

	$service = new ZSPropertyStockService($db);
	$candidates = $service->fetchDismantlingBomCandidates((int) $input->rowid, $entity);
	if (empty($candidates[83]['eligible'])) {
		throw new RuntimeException('Localhost BOM #83 must be an eligible exact-stock candidate');
	}
	if (!$db->query('UPDATE ' . MAIN_DB_PREFIX . 'bom_bomline SET qty=8.33333 WHERE rowid=' . (int) $bom->line_id)) {
		throw new RuntimeException('Unable to exercise decimal BOM output eligibility');
	}
	$decimalCandidates = $service->fetchDismantlingBomCandidates((int) $input->rowid, $entity);
	if (empty($decimalCandidates[83]['eligible'])
		|| (string) $decimalCandidates[83]['outputs'][(int) $bom->line_id]['qty'] !== '8.33333') {
		throw new RuntimeException('Positive decimal BOM output quantities must remain eligible');
	}
	if (!$db->query('DELETE FROM ' . MAIN_DB_PREFIX . 'dolizsynch_property_stock_rule'
		. ' WHERE entity=0 AND fk_product=' . (int) $input->rowid)) {
		throw new RuntimeException('Unable to isolate the legacy shared-BOM rule fixture');
	}
	$sql = 'INSERT INTO ' . MAIN_DB_PREFIX . 'dolizsynch_property_stock_rule';
	$sql .= ' (entity, fk_product, property_id, property_name, units_per_package, fk_bom, fk_bom_line,';
	$sql .= ' fk_product_output, active, date_creation, fk_user_creat, fk_user_modif) VALUES (';
	$sql .= $entity . ', ' . (int) $input->rowid . ", 3, 'NUMERO EMBALAGENS', '8.33333', 83, ";
	$sql .= (int) $bom->line_id . ', ' . (int) $output->rowid . ", 1, '" . $db->idate(dol_now()) . "', ";
	$sql .= (int) $actor->id . ', ' . (int) $actor->id . ')';
	$sql .= ' ON DUPLICATE KEY UPDATE property_id=VALUES(property_id), property_name=VALUES(property_name),';
	$sql .= ' units_per_package=VALUES(units_per_package), fk_bom=VALUES(fk_bom),';
	$sql .= ' fk_bom_line=VALUES(fk_bom_line), fk_product_output=VALUES(fk_product_output),';
	$sql .= ' active=1, fk_user_modif=VALUES(fk_user_modif)';
	if (!$db->query($sql)) {
		throw new RuntimeException('Unable to create the legacy entity-owned shared-BOM rule fixture');
	}
	if ($conf->setEntityValues($db, 8) < 0) {
		throw new RuntimeException('Unable to switch to entity 8 for legacy shared-rule visibility testing');
	}
	$legacySharedRule = $service->fetchRule((int) $input->rowid, 8, true);
	$legacySharedRuleError = $service->error;
	if ($conf->setEntityValues($db, $entity) < 0) {
		throw new RuntimeException('Unable to restore entity 1 after legacy shared-rule visibility testing');
	}
	if (!$legacySharedRule || empty($legacySharedRule['shared'])
		|| (int) $legacySharedRule['entity'] !== $entity || (int) $legacySharedRule['fk_bom'] !== 83) {
		throw new RuntimeException($legacySharedRuleError ?: 'Entity 8 did not inherit the legacy entity-owned shared-BOM rule');
	}
	if (!$service->saveRule((int) $input->rowid, $entity, 3, 'NUMERO EMBALAGENS', (int) $bom->rowid, (int) $actor->id)) {
		throw new RuntimeException($service->error);
	}
	$rule = $service->fetchRule((int) $input->rowid, $entity, true);
	if (!$rule) {
		throw new RuntimeException($service->error ?: 'Unable to reload active exact-stock rule');
	}
	if ((int) $rule['entity'] !== 0 || empty($rule['shared'])) {
		throw new RuntimeException('A rule using entity-0 BOM #83 must be stored as a shared entity-0 rule');
	}
	$sql = 'SELECT active FROM ' . MAIN_DB_PREFIX . 'dolizsynch_property_stock_rule';
	$sql .= ' WHERE entity=' . $entity . ' AND fk_product=' . (int) $input->rowid . ' AND fk_bom=83';
	$resql = $db->query($sql);
	$legacyRuleRow = $resql ? $db->fetch_object($resql) : null;
	if (!$legacyRuleRow || (int) $legacyRuleRow->active !== 0) {
		throw new RuntimeException('Saving the shared rule did not retire its legacy entity-owned copy');
	}
	if ($conf->setEntityValues($db, 8) < 0) {
		throw new RuntimeException('Unable to switch to entity 8 for shared-rule visibility testing');
	}
	$sharedEntityRule = $service->fetchRule((int) $input->rowid, 8, true);
	$sharedEntityRuleError = $service->error;
	if ($conf->setEntityValues($db, $entity) < 0) {
		throw new RuntimeException('Unable to restore entity 1 after shared-rule visibility testing');
	}
	if (!$sharedEntityRule || empty($sharedEntityRule['shared']) || (int) $sharedEntityRule['fk_bom'] !== 83) {
		throw new RuntimeException($sharedEntityRuleError ?: 'Entity 8 did not inherit the entity-0 BOM configuration');
	}
	$originalPhpSelf = $_SERVER['PHP_SELF'] ?? null;
	$_SERVER['PHP_SELF'] = '/bom/bom_card.php';
	$bomActions = new ActionsDoliZSynch($db);
	$bomHookAction = '';
	$bomActions->formObjectOptions(array(), $bomObject, $bomHookAction, null);
	$bomGuidanceHtml = (string) $bomActions->resprints;
	if ($originalPhpSelf === null) {
		unset($_SERVER['PHP_SELF']);
	} else {
		$_SERVER['PHP_SELF'] = $originalPhpSelf;
	}
	$evidence['bom_package_quantity_label_rendered'] = strpos(
		$bomGuidanceHtml,
		'dolizsynch-property-stock-package-basis'
	) !== false && strpos($bomGuidanceHtml, 'packageText.textContent="Quant. (numero embalagens)"') !== false;
	if (empty($evidence['bom_package_quantity_label_rendered'])) {
		throw new RuntimeException('Configured exact-stock BOM guidance did not render a separate one-package basis');
	}
	$evidence['bom_weight_quantity_label_rendered'] = strpos(
		$bomGuidanceHtml,
		'quantityText.textContent="Quant. (peso)"'
	) !== false && strpos($bomGuidanceHtml, 'dolizsynch-property-stock-weight-value') !== false;
	if (empty($evidence['bom_weight_quantity_label_rendered'])) {
		throw new RuntimeException('Configured exact-stock BOM guidance did not preserve and identify the nominal weight quantity');
	}
	$evidence['bom_package_warning_icon_rendered'] = strpos(
		$bomGuidanceHtml,
		'fa-exclamation-triangle paddingright'
	) !== false && strpos($bomGuidanceHtml, "warningIcon.style.color='#a69944'") !== false;
	$evidence['bom_package_tooltip_rendered'] = strpos(
		$bomGuidanceHtml,
		'classfortooltip valignmiddle'
	) !== false && strpos($bomGuidanceHtml, 'fa-info-circle em088 opacityhigh') !== false
		&& strpos($bomGuidanceHtml, "packageText.style.paddingRight='2px'") !== false
		&& strpos($bomGuidanceHtml, "help.style.paddingRight='2px'") !== false;
	$evidence['bom_package_warning_before_value'] = strpos(
		$bomGuidanceHtml,
		'packageValue.appendChild(warningIcon)'
	) !== false && strpos($bomGuidanceHtml, 'packageTitle.appendChild(help)') !== false;
	$evidence['bom_nominal_cost_preview_rendered'] = strpos(
		$bomGuidanceHtml,
		'Estimativa nominal da BOM:'
	) !== false && strpos($bomGuidanceHtml, 'peso real faturado') !== false;
	if (
		empty($evidence['bom_package_warning_icon_rendered'])
		|| empty($evidence['bom_package_tooltip_rendered'])
		|| empty($evidence['bom_package_warning_before_value'])
		|| empty($evidence['bom_nominal_cost_preview_rendered'])
	) {
		throw new RuntimeException('Configured exact-stock BOM package basis did not render its warning, tooltip, and nominal cost preview');
	}
	$ruleId = (int) $rule['id'];

	$invoice = new FactureFournisseur($db);
	$invoice->entity = $entity;
	$invoice->socid = (int) $supplier->rowid;
	$invoice->type = FactureFournisseur::TYPE_STANDARD;
	$invoice->date = dol_now();
	$invoice->date_echeance = dol_now() + 86400;
	$invoice->ref_supplier = $invoiceRef;
	$invoice->note_private = 'Rollback-only exact-stock integration test';
	$invoiceId = (int) $invoice->create($actor);
	if ($invoiceId <= 0) {
		throw new RuntimeException($invoice->error ?: 'Unable to create supplier invoice');
	}

	$invoiceLineId = (int) $invoice->addline(
		'4538 exact-stock integration test', '15.42', '23', 0, 0, '0.72', (int) $input->rowid,
		0, 0, 0, 0, 0, 'HT', 0, -1, 0, array(), null, 0, 0, '', 0, 0, 0
	);
	if ($invoiceLineId <= 0) {
		throw new RuntimeException($invoice->error ?: 'Unable to create supplier invoice line');
	}

	$plan = ZSPropertyStockService::buildLinePlan(
		array('uid_propriedade' => 991337),
		array(array('id' => 991337, 'idpropriedade' => 3, 'valor_propriedade' => '2')),
		$rule
	);
	if (!$service->savePlan($entity, $invoiceId, $invoiceLineId, $plan, (int) $actor->id)) {
		throw new RuntimeException($service->error);
	}
	if (!$service->savePlan($entity, $invoiceId, $invoiceLineId, $plan, (int) $actor->id)) {
		throw new RuntimeException($service->error ?: 'Pending exact-stock plan idempotency failed');
	}
	$auditPlans = $service->fetchInvoicePlans($invoiceId, $entity);
	if (empty($auditPlans[$invoiceLineId])
		|| (string) $auditPlans[$invoiceLineId]['package_count'] !== '2'
		|| (string) $auditPlans[$invoiceLineId]['units_per_package'] !== '8.33333'
		|| (string) $auditPlans[$invoiceLineId]['target_units'] !== '16.66666') {
		throw new RuntimeException('Supplier invoice line audit did not read its immutable package conversion plan');
	}

	$validateResult = $invoice->validate($actor, '', $warehouseId, 0);
	$invoice->fetch($invoiceId);
	$evidence['validation_result'] = (int) $validateResult;
	$evidence['purchase_quantity'] = '0.72';
	$evidence['package_count'] = '2';
	$evidence['units_per_package'] = (string) $plan['units_per_package'];
	$evidence['target_units'] = (string) $plan['target_units'];
	$evidence['invoice_status'] = (int) $invoice->status;
	$evidence['invoice_error'] = (string) $invoice->error;

	$sql = 'SELECT rowid, status, target_units, source_purchase_qty, source_unit_cost, source_stock_value,';
	$sql .= ' source_line_total_ht, exact_unit_cost, fk_stock_movement_out, fk_stock_movement_in, error_message';
	$sql .= ' FROM ' . MAIN_DB_PREFIX . 'dolizsynch_property_stock_plan';
	$sql .= ' WHERE entity=' . $entity . ' AND fk_facture_fourn=' . $invoiceId;
	$resql = $db->query($sql);
	$planRow = $resql ? $db->fetch_object($resql) : null;
	if ($planRow) {
		$planId = (int) $planRow->rowid;
	}
	$evidence['plan'] = $planRow ? (array) $planRow : null;

	$sql = 'SELECT rowid, ref, fk_bom, qty, status FROM ' . MAIN_DB_PREFIX . 'mrp_mo';
	$sql .= " WHERE entity=" . $entity . " AND note_private LIKE 'Auto dismantle from invoice_supplier #" . $invoiceId . ",%'";
	$resql = $db->query($sql);
	$evidence['manufacturing_orders'] = array();
	while ($resql && ($row = $db->fetch_object($resql))) {
		$moIds[] = (int) $row->rowid;
		$evidence['manufacturing_orders'][] = (array) $row;
	}

	$sql = 'SELECT sm.rowid, sm.origintype, sm.fk_origin, sm.fk_product, sm.value, sm.price,';
	$sql .= ' sm.inventorycode, mp.role, mp.fk_mo';
	$sql .= ' FROM ' . MAIN_DB_PREFIX . 'stock_mouvement sm';
	$sql .= ' LEFT JOIN ' . MAIN_DB_PREFIX . 'mrp_production mp ON mp.fk_stock_movement=sm.rowid';
	$sql .= " WHERE (sm.origintype='invoice_supplier' AND sm.fk_origin=" . $invoiceId . ')';
	if (!empty($moIds)) {
		$sql .= " OR (sm.origintype='mo' AND sm.fk_origin IN (" . implode(',', $moIds) . '))';
	}
	$sql .= ' ORDER BY sm.rowid';
	$resql = $db->query($sql);
	$evidence['stock_movements'] = array();
	$netOutput = '0';
	while ($resql && ($row = $db->fetch_object($resql))) {
		$movementIds[] = (int) $row->rowid;
		$evidence['stock_movements'][] = (array) $row;
		if ((int) $row->fk_product === (int) $output->rowid) {
			$netOutput = ZSDecimal::add($netOutput, (string) $row->value);
		}
	}
	$evidence['net_output_units'] = $netOutput;

	if ($validateResult <= 0) {
		throw new RuntimeException($invoice->error ?: 'Supplier invoice validation failed');
	}
	if ((string) $plan['units_per_package'] !== '8.33333' || (string) $plan['target_units'] !== '16.66666') {
		throw new RuntimeException('The plan did not multiply package count by the BOM output quantity');
	}
	if (!$planRow || (int) $planRow->status !== 1 || empty($planRow->fk_stock_movement_out) || empty($planRow->fk_stock_movement_in)) {
		throw new RuntimeException('Exact-stock plan was not reconciled after MO completion');
	}
	if (ZSDecimal::compare((string) $planRow->source_purchase_qty, '0.72') !== 0
		|| ZSDecimal::compare((string) $planRow->source_unit_cost, '15.42') !== 0
		|| ZSDecimal::compare((string) $planRow->source_stock_value, '11.1024') !== 0
		|| ZSDecimal::compare((string) $planRow->source_line_total_ht, '11.1') !== 0
		|| ZSDecimal::compare((string) $planRow->exact_unit_cost, '0.66614427') !== 0) {
		throw new RuntimeException('Exact-stock plan did not preserve supplier purchase valuation evidence');
	}
	if (ZSDecimal::compare($netOutput, '16.66666') !== 0) {
		throw new RuntimeException('Final output stock does not equal the exact package-derived quantity');
	}
	$exactMovementPrice = null;
	foreach ($evidence['stock_movements'] as $stockMovement) {
		if ((int) $stockMovement['rowid'] === (int) $planRow->fk_stock_movement_in) {
			$exactMovementPrice = (string) $stockMovement['price'];
			break;
		}
	}
	if ($exactMovementPrice === null || ZSDecimal::compare($exactMovementPrice, '0.6661') !== 0) {
		throw new RuntimeException('Exact-stock correction movement did not use the audited exact unit cost');
	}
	$exactOutput = new Product($db);
	$exactOutputFetch = $exactOutput->fetch((int) $output->rowid);
	$evidence['output_cost_price'] = $exactOutputFetch > 0 ? (string) $exactOutput->cost_price : null;
	if ($exactOutputFetch <= 0 || ZSDecimal::compare((string) $exactOutput->cost_price, '0.6661') !== 0) {
		throw new RuntimeException('KreaProducts did not persist the exact correction cost on the destination product');
	}
	$evidence['exact_unit_cost'] = (string) $planRow->exact_unit_cost;
	$auditLine = null;
	foreach ($invoice->lines as $invoiceLine) {
		if ((int) $invoiceLine->id === $invoiceLineId) {
			$auditLine = $invoiceLine;
			break;
		}
	}
	if (!$auditLine) {
		throw new RuntimeException('Unable to reload the converted supplier invoice line for audit rendering');
	}
	$auditActions = new ActionsDoliZSynch($db);
	$auditAction = '';
	$auditActions->objectLineView_BeforeProductExtrafield(array('line' => $auditLine), $invoice, $auditAction, null);
	$auditHtml = (string) $auditActions->resprints;
	$evidence['audit_html_rendered'] = strpos($auditHtml, '/bom/bom_card.php?id=83') !== false
		&& strpos($auditHtml, '/product/card.php?id=' . (int) $output->rowid) !== false
		&& strpos($auditHtml, '#' . $planId) !== false
		&& strpos($auditHtml, '0,6661') !== false;
	if (empty($evidence['audit_html_rendered'])) {
		throw new RuntimeException('Supplier invoice line exact-stock audit block was not rendered from its immutable plan');
	}
} catch (Throwable $exception) {
	$failure = $exception->getMessage() . ' at ' . $exception->getFile() . ':' . $exception->getLine();
} finally {
	while ((int) $db->transaction_opened > $baseline) {
		$db->rollback();
	}
	// Dolibarr's nested lifecycle counter can reach zero before mysqli sends the
	// outer rollback; force the rollback-only harness transaction closed before
	// verifying that no fixture data remains.
	$db->query('ROLLBACK');
	$user = $originalUser;
}

$cleanup = array();
$checks = array(
	'invoice' => $invoiceId > 0 ? 'SELECT COUNT(*) AS nb FROM ' . MAIN_DB_PREFIX . 'facture_fourn WHERE rowid=' . $invoiceId : '',
	'mo' => !empty($moIds) ? 'SELECT COUNT(*) AS nb FROM ' . MAIN_DB_PREFIX . 'mrp_mo WHERE rowid IN (' . implode(',', $moIds) . ')' : '',
	'movements' => !empty($movementIds) ? 'SELECT COUNT(*) AS nb FROM ' . MAIN_DB_PREFIX . 'stock_mouvement WHERE rowid IN (' . implode(',', $movementIds) . ')' : '',
	'plan' => $planId > 0 ? 'SELECT COUNT(*) AS nb FROM ' . MAIN_DB_PREFIX . 'dolizsynch_property_stock_plan WHERE rowid=' . $planId : '',
);
foreach ($checks as $name => $sql) {
	if ($sql === '') {
		$cleanup[$name] = 0;
		continue;
	}
	$resql = $db->query($sql);
	$row = $resql ? $db->fetch_object($resql) : null;
	$cleanup[$name] = (int) ($row->nb ?? -1);
}
$sql = 'SELECT status FROM ' . MAIN_DB_PREFIX . 'bom_bom WHERE rowid=83';
$resql = $db->query($sql);
$row = $resql ? $db->fetch_object($resql) : null;
$cleanup['bom_status_changed'] = (!$row || (int) $row->status !== (int) $originalBomStatus) ? 1 : 0;
$sql = 'SELECT * FROM ' . MAIN_DB_PREFIX . 'dolizsynch_property_stock_rule';
$sql .= ' WHERE entity=1 AND fk_product=' . (isset($input->rowid) ? (int) $input->rowid : 0) . $db->plimit(1);
$resql = $db->query($sql);
$row = $resql ? $db->fetch_object($resql) : null;
$restoredRule = $row ? (array) $row : null;
$cleanup['rule_changed'] = $restoredRule === $originalRule ? 0 : 1;
$evidence['cleanup'] = $cleanup;

echo json_encode($evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
if (array_sum($cleanup) !== 0) {
	$failure = trim($failure . ' Rollback cleanup failed.');
}
if ($failure !== '') {
	fwrite(STDERR, 'FAIL: ' . $failure . "\n");
	exit(1);
}

echo "PASS: 0.72 purchase units used decimal BOM #83 output while 2 packages produced exactly 16.66666 destination units; all data rolled back\n";

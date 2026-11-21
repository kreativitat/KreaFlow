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

$root = dirname(__DIR__);
$failures = array();

function assertContains($needle, $haystack, $label)
{
	global $failures;
	if (strpos($haystack, $needle) === false) {
		$failures[] = $label;
		echo "[FAIL] " . $label . "\n";
		return;
	}
	echo "[PASS] " . $label . "\n";
}

function assertNotContains($needle, $haystack, $label)
{
	global $failures;
	if (strpos($haystack, $needle) !== false) {
		$failures[] = $label;
		echo "[FAIL] " . $label . "\n";
		return;
	}
	echo "[PASS] " . $label . "\n";
}

$mutatingEndpoints = array(
	'ajax/zsbms_invoice_create.php',
	'ajax/zsbms_product_create.php',
	'ajax/zsproduct_prefill.php',
);
foreach ($mutatingEndpoints as $relativePath) {
	$source = file_get_contents($root . '/' . $relativePath);
	assertContains("REQUEST_METHOD'] !== 'POST'", $source, $relativePath . ' requires POST');
	assertContains("hash_equals((string) \$_SESSION['newtoken'], \$token)", $source, $relativePath . ' validates CSRF token');
	assertNotContains("define('NOCSRFCHECK', '1')", $source, $relativePath . ' does not disable CSRF');
}

$invoiceEndpoint = file_get_contents($root . '/ajax/zsbms_invoice_create.php');
assertContains("hasRight('dolizsynch', 'zsbmsinvoicecreator', 'read')", $invoiceEndpoint, 'invoice export checks module permission');
assertContains("getEntity('invoice')", $invoiceEndpoint, 'invoice export checks entity scope');

$productCreateEndpoint = file_get_contents($root . '/ajax/zsbms_product_create.php');
assertContains("rights->produit->creer", $productCreateEndpoint, 'product creation checks write permission');
assertContains("getEntity('product')", $productCreateEndpoint, 'product creation checks entity scope');

$productSearchEndpoint = file_get_contents($root . '/ajax/zsproducts_search.php');
assertContains("getEntity('product')", $productSearchEndpoint, 'menu product search uses the authorized product entity scope');
assertContains('INNER JOIN " . MAIN_DB_PREFIX . "product AS p ON p.rowid = zp.fk_product', $productSearchEndpoint, 'menu product search scopes ZS rows through their Dolibarr product');
assertNotContains('WHERE entity IN (0, ', $productSearchEndpoint, 'menu product search does not treat ZS row ownership as product visibility');

$productHook = file_get_contents($root . '/class/actions_dolizsynch.class.php');
assertContains('token: csrfToken', $productHook, 'product UI sends CSRF token to creation endpoint');
assertContains("empty(\$user->rights->produit->creer)", $productHook, 'product property writes require Dolibarr product write permission');
assertContains("GETPOST('DOLIZSYNCH_ZS_PRODUCT_PROPERTIES', 'none')", $productHook, 'product property relations use the dedicated product form payload');
assertContains('ZSProductPropertyService::normalizeRelations(', $productHook, 'product property relations are normalized server-side before persistence');
assertContains('ZSProductPropertyService::hasRows($existingCaracteristicasFromDb)', $productHook, 'product properties cannot coexist with ZSBMS characteristics');

$syncTools = file_get_contents($root . '/admin/sync_tools.php');
assertContains("\$_SERVER['REQUEST_METHOD'] !== 'POST'", $syncTools, 'property dictionary synchronization requires POST');
assertContains("GETPOST('token', 'alphanohtml') !== (string) (\$_SESSION['newtoken'] ?? '')", $syncTools, 'property dictionary synchronization validates the Dolibarr CSRF token');
assertContains("GETPOST('synchProperties', 'alpha')", $syncTools, 'property dictionary synchronization has a dedicated administrator action');
assertContains('$securedActions = array(', $syncTools, 'sync tools centralize state-changing action security');
foreach (array(
	'saveProductPriceSync',
	'synchYesterdayInvoices',
	'synchSpecificDocument',
	'synchInvoicesHeaders',
	'synchInvoices',
	'synchInterEntityInvoices',
	'synchMasterDataCore',
	'synchProperties',
	'synchProducts',
	'synchUnits',
	'synchProdCenters',
	'synchCategories',
	'synchGroups',
	'storeZsPunchclock',
	'pairTimeRegisters',
	'synchPunchClockBetweenDates',
) as $securedAction) {
	assertContains("'" . $securedAction . "'", $syncTools, 'sync tools secure action ' . $securedAction);
}
assertContains('dolizsynch_sync_tools_require_secure_post();', $syncTools, 'sync tool dispatch enters the secured POST path before synchronization');

foreach (array(
	'fixMissingReferences',
	'fixCanceledInvoices',
	'fixByFkFacture',
	'OPEN_ZSBMS_INVOICE_CREATOR',
	'OpenCustomCreator',
	'synchFamilies',
	'synchTaxes',
	'synchPaymentTypes',
) as $deprecatedTool) {
	assertNotContains($deprecatedTool, $syncTools, 'sync tools omit deprecated entry ' . $deprecatedTool);
}

$syncToolSections = array(
	'InvoiceSynchronizationTools',
	'InterEntitySynchronizationTools',
	'ProductCatalogSynchronization',
	'MasterDataSynchronization',
	'PunchClockSync',
);
$previousSectionPosition = -1;
foreach ($syncToolSections as $sectionKey) {
	$sectionPosition = strrpos($syncTools, "trans('" . $sectionKey . "')");
	if ($sectionKey === 'PunchClockSync' && $sectionPosition === false) {
		$sectionPosition = strrpos($syncTools, 'trans("PunchClockSync")');
	}
	if ($sectionPosition === false || $sectionPosition <= $previousSectionPosition) {
		$failures[] = 'sync tools preserve operational section order at ' . $sectionKey;
		echo "[FAIL] sync tools preserve operational section order at " . $sectionKey . "\n";
		continue;
	}
	echo "[PASS] sync tools preserve operational section order at " . $sectionKey . "\n";
	$previousSectionPosition = $sectionPosition;
}

$interEntityConverter = file_get_contents($root . '/class/zsstoreinvoiceconverter.class.php');
assertContains("require_once DOL_DOCUMENT_ROOT . '/core/lib/date.lib.php';", $interEntityConverter, 'inter-entity cron loads its Dolibarr date dependency explicitly');
assertContains('private function activateEntityConfiguration($entity)', $interEntityConverter, 'inter-entity operations centralize native entity configuration switching');
assertContains('$conf->setEntityValues($this->db, $entity)', $interEntityConverter, 'inter-entity operations reload entity-specific Dolibarr constants');
assertNotContains('$conf->entity = $targetEntity;', $interEntityConverter, 'inter-entity operations never switch only the numeric entity identifier');
assertContains('resolveDestinationSupplierInvoiceDate', $interEntityConverter, 'inter-entity supplier invoices use the guarded accounting date');
assertContains('$sourceDate > $today ? $today : $sourceDate', $interEntityConverter, 'future supplier invoice dates are capped at today');
assertContains('$sourceDate < $oldestAllowedDate', $interEntityConverter, 'implausibly old supplier invoice dates are rejected');
assertContains('$invoice->date = $invoiceDate;', $interEntityConverter, 'supplier invoice creation uses the guarded accounting date');
assertContains("reverseInvoiceMovements(\$invoice, 'invoice_supplier'", $interEntityConverter, 'supplier invoice cancellation reverses its exact stock ledger');
assertContains('public function runScheduledInterEntityInvoices()', $interEntityConverter, 'legacy persisted inter-entity cron method remains callable');
assertContains('$result = $this->processInterEntityInvoices();', $interEntityConverter, 'legacy cron adapter delegates to the canonical conversion lifecycle');
assertContains('fetchInvoiceDocumentsWithinDateRange($monthStart, $targetDate, $zsbmsStoreId)', $interEntityConverter, 'inter-entity conversion fetches every document page needed for the target month');
assertContains('getTargetProductEntitySql($targetEntity)', $interEntityConverter, 'inter-entity product lookup follows native multicompany product visibility');
assertContains('private function getLabelOrSharedProductFallback(', $interEntityConverter, 'buyer product mappings are resolved before shared source-product reuse');
assertContains('The source product itself is the final fallback only', $interEntityConverter, 'shared source products cannot override buyer-specific products');
assertContains('SHARED PRODUCT SUCCESS', $interEntityConverter, 'a product shared natively by seller and buyer requires no synthetic equivalence row');
assertContains('Ambiguous shared product label', $interEntityConverter, 'label fallback fails closed for ambiguous shared products');
assertContains('Source VAT-included flag did not reconcile', $interEntityConverter, 'source price mode can change only after alternate exact reconciliation');
assertContains('LEFT JOIN " . MAIN_DB_PREFIX . "facture_fourn as ff', $interEntityConverter, 'inter-entity idempotency verifies the destination supplier invoice still exists');
assertContains('Inter-entity tracking identity is already owned by another source entity', $interEntityConverter, 'tracking repair cannot take ownership from another source entity');
assertContains('UPDATE " . $table . " SET', $interEntityConverter, 'stale inter-entity tracking is repaired in place');
assertContains('if ($errorCount > 0)', $interEntityConverter, 'inter-entity cron reports partial invoice failures as errors');

$invoiceSync = file_get_contents($root . '/class/zsinvoicesynch.class.php');
assertContains('cancelCustomerInvoiceWithStockReversal', $invoiceSync, 'customer invoice cancellation uses the atomic stock reversal path');
assertContains('public function fixCanceledInvoiceByFkFacture', $invoiceSync, 'automatic invoice cancellation retains its internal recovery lifecycle');
assertNotContains('Stock is automatically returned by setCanceled()', $invoiceSync, 'customer cancellation does not assume setCanceled changes stock');
assertContains("Regular cron synchronization must always revisit the newest page", $invoiceSync, 'regular multi-store invoice cron always fetches the newest header page');
assertContains("updateStoreCursor(\$store, 0, \$headersCount, 'ok', 'latest_headers_synced')", $invoiceSync, 'regular multi-store invoice cron does not persist a historical page offset');
assertContains('dolizsynch_storemap belongs to inter-entity supplier conversion', $invoiceSync, 'customer invoice cron does not treat the inter-entity map as store ownership');
assertContains('return $storeId > 0 ? [(string) $storeId] : [];', $invoiceSync, 'customer invoice cron uses only the active entity configured store');
assertContains('source store is outside the active entity scope', $invoiceSync, 'invoice conversion rejects foreign source stores');

$invoiceFetching = file_get_contents($root . '/class/trait/ZSInvoiceFetching.trait.php');
assertContains('filterInvoiceHeadersByStore', $invoiceFetching, 'invoice API responses are filtered by active entity store');
assertContains('Requested invoice store is outside the active entity scope', $invoiceFetching, 'foreign invoice fetches fail closed');
assertContains('public function fetchInvoiceDocumentsWithinDateRange', $invoiceFetching, 'full invoice documents support deterministic date-range pagination');
assertContains("executeForStore('getInstances'", $invoiceFetching, 'full invoice pagination uses the ZoneSoft document instance operation');

$taxCalculator = file_get_contents($root . '/class/zsinvoicetaxcalculator.class.php');
assertContains("ZSDecimal::compare(\$lineTtcDifference, '0.01') > 0", $taxCalculator, 'VAT-inclusive authoritative line TTC tolerance is capped at one cent');

$invoiceSynchronization = file_get_contents($root . '/class/trait/ZSInvoiceSynchronization.trait.php');
assertContains('Rejected invoice header outside the active entity store scope', $invoiceSynchronization, 'foreign invoice headers are rejected before persistence');

$stockReversal = file_get_contents($root . '/class/dolizsynchstockreversal.class.php');
assertContains('SUM(value) AS net_value', $stockReversal, 'stock reversal derives quantities from the exact movement ledger');
assertContains("label='\".\$this->db->escape('Canceled: '.\$invoice->ref)", $stockReversal, 'stock reversal nets legacy partial cancellation movements');
assertContains("setOrigin('dolizsynch_cancel'", $stockReversal, 'stock reversals use a dedicated non-invoice origin');
assertContains("'DZS-CANCEL-'", $stockReversal, 'stock reversals use deterministic idempotency keys');
assertContains("' AND entity='.\$entity.' FOR UPDATE'", $stockReversal, 'stock reversal locks the entity-scoped invoice');

$descriptor = file_get_contents($root . '/compatibility/dolizsynch/descriptor.php');
assertContains("f.entity IN (0, '", $descriptor, 'dictionary query is entity-scoped');
assertNotContains('run_sql($allVersionsSql', $descriptor, 'activation does not replay cumulative migrations');
assertContains("'method' => 'runScheduledInterEntityInvoices'", $descriptor, 'persisted and new inter-entity cron rows use the compatible scheduled entrypoint');
assertContains("'method' => 'checkHealthAndNotify'", $descriptor, 'sync-health cron implementation remains available for future development');
assertContains("'test' => 'false'", $descriptor, 'sync-health cron registration is disabled');
assertContains("'fk_menu' => 'fk_mainmenu=tools'", $descriptor, 'administrator synchronization tools use the native Dolibarr Tools menu');
assertContains("'leftmenu' => 'dolizsynch_sync_tools'", $descriptor, 'administrator synchronization tools have a stable left-menu identifier');
assertContains("'/kreaflow/admin/sync_tools.php?mainmenu=tools&leftmenu=dolizsynch_sync_tools'", $descriptor, 'administrator synchronization tools preserve native Tools navigation context');
assertContains("'perms' => '\$user->admin'", $descriptor, 'administrator synchronization tools menu is restricted to administrators');

$setupPage = file_get_contents($root . '/admin/setup.php');
assertNotContains("newItem('DOLIZSYNCH_SYNC_HEALTH_TITLE')", $setupPage, 'sync-health settings are hidden from module setup');
assertNotContains("newItem('DOLIZSYNCH_ENABLE_DEBUG_LOGS')", $setupPage, 'debug logging setting is hidden from module setup');
$adminHead = file_get_contents($root . '/lib/dolizsynch.lib.php');
assertNotContains('/kreaflow/admin/sync_health.php', $adminHead, 'sync-health page is hidden from module navigation');
assertNotContains('/kreaflow/admin/sync_tools.php', $adminHead, 'sync tools are removed from module setup navigation');
$portugueseTranslations = file_get_contents($root . '/langs/pt_PT/dolizsynch.lang');
foreach (array(
	'InvoiceCorrectionTools = Ferramentas de correção de faturas',
	'FIX_MISSING_REFERENCES = Corrigir referências de faturas em falta',
	'FIX_MISSING_REFERENCES_DESC = Localizar e atualizar faturas com fk_facture=0 através do campo adicional invoice_number',
	'FIX_CANCELED_INVOICES_BETWEEN_DATES = Corrigir faturas canceladas entre datas',
	'FIX_CANCELED_INVOICES_DESC = Corrigir faturas que deveriam estar canceladas, mas não estão',
	'FIX_BY_FK_FACTURE = Corrigir fatura cancelada por ID',
	'FIX_BY_FK_FACTURE_DESC = Corrigir uma fatura específica que deveria estar cancelada através do ID da fatura (fk_facture)',
	'SYNCH_INTERENTITY_INVOICES = Sincronizar Faturas Inter-Entidades',
	'SYNCH_INTERENTITY_INVOICES_DESC = Processar conversão de faturas de cliente da entidade vendedora para faturas de fornecedor nas entidades compradoras',
) as $translation) {
	assertContains($translation, $portugueseTranslations, 'Portuguese sync-tools translation is available for ' . strtok($translation, ' '));
}
$syncMonitor = file_get_contents($root . '/class/zssyncmonitor.class.php');
assertContains('public const FEATURE_ENABLED = false;', $syncMonitor, 'sync-health implementation is retained behind a disabled feature gate');
assertContains('if (!self::FEATURE_ENABLED)', $syncMonitor, 'persisted sync-health cron calls exit without processing');
$syncHealthPage = file_get_contents($root . '/admin/sync_health.php');
assertContains('if (!ZSSyncMonitor::FEATURE_ENABLED)', $syncHealthPage, 'direct sync-health page access is disabled');
$loggerTrait = file_get_contents($root . '/class/trait/zslogger.trait.php');
assertContains('verbose module logging is disabled', $loggerTrait, 'verbose debug logging remains hard-disabled while hidden');

$apiDoor = file_get_contents($root . '/class/api_dolizsynch.class.php');
assertContains("hasRight('dolizsynch', 'zspunchclock', 'write')", $apiDoor, 'KreaClock API requires punch-clock write permission');
assertContains('/punchclock/bootstrap', $apiDoor, 'KreaClock bootstrap endpoint is registered');
assertContains('/punchclock/events', $apiDoor, 'KreaClock event endpoint is registered');

$kreaClockService = file_get_contents($root . '/class/kreaclockservice.class.php');
assertContains('client_event_id', $kreaClockService, 'KreaClock ingestion uses a stable idempotency key');
assertContains('AND ugu.entity = ', $kreaClockService, 'KreaClock employee groups are entity-scoped');
assertContains("status = 'accepted'", $kreaClockService, 'KreaClock audit event is accepted only after raw punch creation');
assertContains('$this->db->rollback()', $kreaClockService, 'KreaClock ingestion rolls back partial writes');
assertContains('(0 - $eventId)', $kreaClockService, 'KreaClock raw punches remain eligible for the existing pairing workflow');
assertContains("'manual_supervised'", $kreaClockService, 'KreaClock records supervised manual integration tests honestly');

$schema = file_get_contents($root . '/sql/llx_dolizsynch.sql');
assertNotContains("\nALTER TABLE ", $schema, 'base schema has no executable ALTER TABLE statements');
assertNotContains("\nCREATE INDEX ", $schema, 'base schema has no standalone CREATE INDEX statements');

$versionFile = trim(file_get_contents($root . '/VERSION'));
if (!preg_match('/\$this->version\s*=\s*\'([^\']+)\'/', $descriptor, $matches)) {
	$failures[] = 'descriptor version can be parsed';
	echo "[FAIL] descriptor version can be parsed\n";
} elseif ($versionFile !== $matches[1]) {
	$failures[] = 'VERSION matches module descriptor';
	echo "[FAIL] VERSION matches module descriptor\n";
} else {
	echo "[PASS] VERSION matches module descriptor\n";
}

$changeLog = file_get_contents($root . '/ChangeLog.md');
assertContains('## [' . $versionFile . '] - ', $changeLog, 'ChangeLog matches module version');

if (!is_file($root . '/LICENSE') || strpos(file_get_contents($root . '/LICENSE'), 'GNU GENERAL PUBLIC LICENSE') === false) {
	$failures[] = 'full GPL license is present';
	echo "[FAIL] full GPL license is present\n";
} else {
	echo "[PASS] full GPL license is present\n";
}

$licenseIterator = new RecursiveIteratorIterator(
	new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
);
foreach ($licenseIterator as $file) {
	if (!$file->isFile()) {
		continue;
	}
	$relative = substr($file->getPathname(), strlen($root) + 1);
	if (strpos($relative, 'backport/') === 0 || strtolower($file->getExtension()) !== 'php') {
		continue;
	}
	$source = file_get_contents($file->getPathname());
	assertContains('Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>', $source, $relative . ' has canonical author signature');
	assertContains('either version 3 of the License', $source, $relative . ' has GPL-3.0-or-later header');
}

if (!empty($failures)) {
	exit(1);
}

echo "All security boundary checks passed.\n";

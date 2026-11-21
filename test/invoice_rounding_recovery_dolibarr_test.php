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

// Run only in the local integration container. All business rows roll back.
if (!is_file('/var/www/html/master.inc.php')) {
	echo "SKIP: local Dolibarr integration container required\n";
	exit(0);
}
require '/var/www/html/master.inc.php';
if ($dolibarr_main_db_name !== 'kreaflow_invoicesync_test') {
	throw new RuntimeException('Refusing to run outside the disposable invoice-sync fixture');
}
require_once dirname(__DIR__) . '/class/zsinvoicesynch.class.php';
// Payment lifecycle is under test; suppress filesystem-only PDF regeneration.
$conf->global->MAIN_DISABLE_PDF_AUTOUPDATE = 1;

class RoundingRecoveryStatusRecorder
{
	public $events = [];
	public function setSyncStatus($ref, $status, $error = null, array $context = [])
	{
		$this->events[] = [$ref, $status, $error, $context];
		return true;
	}
}

class RoundingRecoveryHarness extends ZSInvoiceSynch
{
	public $mappedFixture;
	public $sourceCalls = 0;
	public function __construct($db)
	{
		$this->db = $db;
		$this->syncManager = new RoundingRecoveryStatusRecorder();
	}
	public function fetchInvoiceLines($doc, $serie, $numero, $date, $store = null)
	{
		$this->sourceCalls++;
		return ['data' => (object) ['document' => (object) []]];
	}
	public function mapZSToDolibarrInvoice($document)
	{
		return $this->mappedFixture;
	}
}

$checks = 0;
$assert = function ($condition, $message) use (&$checks) {
	if (!$condition) {
		throw new RuntimeException($message);
	}
	$checks++;
};
$invoke = function ($object, $method, ...$args) {
	$r = new ReflectionMethod($object, $method);
	$r->setAccessible(true);
	return $r->invoke($object, ...$args);
};
$baseline = (int) $db->transaction_opened;
$originalUser = $user;
$failure = '';
try {
	$entity = (int) $conf->entity;
	$r = $db->query('SELECT rowid FROM ' . MAIN_DB_PREFIX . 'user WHERE admin=1 AND statut=1 ORDER BY rowid LIMIT 1');
	$actor = $db->fetch_object($r);
	$user = new User($db);
	$assert($actor && $user->fetch((int) $actor->rowid) > 0, 'An integration actor is required');
	$user->loadRights();
	$r = $db->query('SELECT rowid FROM ' . MAIN_DB_PREFIX . 'societe WHERE entity=' . $entity . ' AND client IN(1,2,3) LIMIT 1');
	$soc = $db->fetch_object($r);
	$assert((bool) $soc, 'An active-entity customer is required');
	$db->begin();
	$f = new Facture($db);
	$f->socid = (int) $soc->rowid;
	$f->type = Facture::TYPE_STANDARD;
	$f->date = dol_now();
	$f->ref_client = 'FT_ROUNDTEST_' . date('His');
	$assert($f->create($user, 1) > 0, 'Create rollback-only split-bill fixture');
	// A partial local database restore may leave orphan lines/payment links above
	// facture's AUTO_INCREMENT. Give only this new fixture an unused identifier.
	$r = $db->query('SELECT GREATEST((SELECT COALESCE(MAX(rowid),0) FROM ' . MAIN_DB_PREFIX . 'facture),'
		. '(SELECT COALESCE(MAX(fk_facture),0) FROM ' . MAIN_DB_PREFIX . 'facturedet),'
		. '(SELECT COALESCE(MAX(fk_facture),0) FROM ' . MAIN_DB_PREFIX . 'paiement_facture))+1 id');
	$freshId = (int) $db->fetch_object($r)->id;
	$assert((bool) $db->query('UPDATE ' . MAIN_DB_PREFIX . 'facture SET rowid=' . $freshId . ' WHERE rowid=' . (int) $f->id), 'Isolate fixture from restored orphan records: ' . $db->lasterror());
	$f->id = $f->rowid = $freshId;
	$f->fetch_lines();
	$assert(count($f->lines) === 0, 'Fixture starts without invoice lines');

	// Invented fractional rows exercise two VAT rates without business evidence.
	$inputs = [
		['0.25', '1.00', '13', '0.22', '0.25'],
		['0.125', '2.00', '23', '0.20', '0.25'],
		['0.5', '0.75', '13', '0.34', '0.38'],
	];
	$taxLines = [];
	foreach ($inputs as [$qty, $price, $vat, $ht, $ttc]) {
		$assert($f->addline('Split bill rounding fixture', 0, $qty, $vat, 0, 0, 0, 0, '', '', 0, 0, 0,
			'TTC', $price, 1, -1, 0, '', 0, 0, null, '0', '', [], 100, 0, null, '0', '', 1) > 0,
			'Add fractional source line');
		$taxLines[] = ['quantity' => $qty, 'unit_price' => $price, 'vat_rate' => $vat,
			'source_total_ht' => $ht, 'source_total_ttc' => $ttc];
	}
	$plan = ZSInvoiceTaxCalculator::reconcileSource($taxLines, '0', true, '0.76', null, '0.88');
	$assert([$plan['total_ht'], $plan['total_tva'], $plan['total_ttc']] === ['0.76', '0.12', '0.88'], 'Source cents use decimal half-up and balance');
	$sync = new RoundingRecoveryHarness($db);
	$mapped = ['monetary_plan' => $plan];
	$assert($invoke($sync, 'reconcileCustomerInvoiceTotals', $f, $mapped), 'Persist source cents');
	$assert($invoke($sync, 'verifyStoredCustomerInvoiceConsistency', $f, '0.88'), 'Header and source TTC agree');

	// Construct an inconsistent state: exact source lines but a recalculated header.
	$db->query('UPDATE ' . MAIN_DB_PREFIX . 'facture SET fk_statut=1,total_tva=0.11,total_ttc=0.87 WHERE rowid=' . (int) $f->id);
	$assert(!$invoke($sync, 'verifyStoredCustomerInvoiceConsistency', $f, '0.88'), 'Detect a one-cent header drift');
	$assert($invoke($sync, 'handleDuplicateInvoice', $f->ref_client, $f->id, $mapped) === false, 'Duplicate detection rejects an inconsistent validated invoice');
	$assert(end($sync->syncManager->events)[1] === ZSSyncStatus::FAILED, 'Inconsistent duplicate cannot be marked OK');

	// Draft recovery must reconcile before/after native validation and keep the plan.
	$db->query('UPDATE ' . MAIN_DB_PREFIX . 'facture SET fk_statut=0 WHERE rowid=' . (int) $f->id);
	$assert($invoke($sync, 'reconcileCustomerInvoiceTotals', $f, $mapped), 'Prepare exact draft totals');
	$invalidPlan = $plan;
	$invalidPlan['lines'][] = $plan['lines'][0];
	$depth = (int) $db->transaction_opened;
	$previousRounding = $conf->global->MAIN_ROUNDOFTOTAL_NOT_TOTALOFROUND ?? null;
	$assert(!$invoke($sync, 'validateInvoiceWithMonetaryPlan', $f, $user, 0, $f->ref_client, $invalidPlan), 'Reject a plan that cannot be restored after native validation');
	$f->fetch($f->id);
	$assert((int) $f->statut === Facture::STATUS_DRAFT, 'Failed validation/reconciliation leaves the invoice in draft');
	$assert((int) $db->transaction_opened === $depth, 'Failed validation restores transaction depth');
	$assert(($conf->global->MAIN_ROUNDOFTOTAL_NOT_TOTALOFROUND ?? null) === $previousRounding, 'Failed validation restores the caller rounding setting');
	$assert($invoke($sync, 'verifyStoredCustomerInvoiceConsistency', $f, '0.88'), 'Failed validation preserves exact source cents');
	$assert((int) $invoke($sync, 'handleDuplicateInvoice', $f->ref_client, $f->id, $mapped) === (int) $f->id, 'Recover draft duplicate using the source plan');
	$assert($invoke($sync, 'verifyStoredCustomerInvoiceConsistency', $f, '0.88'), 'Recovery preserves all three source line totals');
	$assert($invoke($sync, 'verifyCustomerInvoiceMonetaryPlan', $f, $plan, 1), 'Recovery preserves base/multicurrency header and lines');
	$assert(!$invoke($sync, 'verifyStoredCustomerInvoiceConsistency', $f, '0.89'), 'Reject a consistent invoice with the wrong source total');
	$assert((int) $invoke($sync, 'handleDuplicateInvoice', $f->ref_client, $f->id, $mapped) === (int) $f->id, 'Repeated duplicate recovery is idempotent');

	// The queue retry has a separate recovery branch; exercise it with a real
	// queue row and an in-memory source adapter, without contacting ZoneSoft.
	$sync->mappedFixture = $mapped;
	$db->query('UPDATE ' . MAIN_DB_PREFIX . 'facture SET fk_statut=0 WHERE rowid=' . (int) $f->id);
	$store = (int) $conf->global->ZS_API_STORE;
	$ref = $db->escape((string) $f->ref_client);
	$assert((bool) $db->query('INSERT INTO ' . MAIN_DB_PREFIX . 'dolizsynch_zsinvoicesynch'
		. '(entity,doc,doc_serie,doc_num,data_zs,total_zs,liquido_zs,loja_zs,pago_zs,anulado_zs,fk_user_creat,date_creation,invoice_ref,fk_facture,synch)'
		. " VALUES($entity,'FT','ROUNDTEST',1,CURRENT_DATE,0.88,0.76,$store,0,0," . (int) $user->id . ",NOW(),'$ref'," . (int) $f->id . ',0)'), 'Create rollback-only retry queue row');
	$staleRow = (object) ['invoice_ref' => $f->ref_client, 'fk_facture' => 0];
	$assert($sync->synchronizeLatestUnsynchronisedInvoice($staleRow), 'Queue lock reloads stale rows and recovers the linked draft');
	$assert($sync->sourceCalls === 1, 'Draft queue retry rebuilds the source monetary plan');
	$assert($invoke($sync, 'verifyCustomerInvoiceMonetaryPlan', $f, $plan, 1), 'Queue draft retry retains every source line cent');
	$db->query('UPDATE ' . MAIN_DB_PREFIX . 'facture SET total_tva=0.11,total_ttc=0.87 WHERE rowid=' . (int) $f->id);
	$assert(!$sync->synchronizeLatestUnsynchronisedInvoice($staleRow), 'Queue recovery rejects header drift before payments');
	$assert($sync->sourceCalls === 1, 'Invalid validated invoice is rejected without a source fetch');
	$assert($invoke($sync, 'syncExistingHeaderWithImportedInvoice', $f->ref_client, date('Y-m-d'), '0.88'), 'Header refresh finds existing invoice');
	$r = $db->query('SELECT synch FROM ' . MAIN_DB_PREFIX . "dolizsynch_zsinvoicesynch WHERE invoice_ref='$ref' AND entity=$entity");
	$assert((int) $db->fetch_object($r)->synch === ZSSyncStatus::FAILED, 'Header-only refresh cannot hide the monetary failure');
	$assert($invoke($sync, 'reconcileCustomerInvoiceTotals', $f, $mapped), 'Restore fixture source totals');
	$assert($invoke($sync, 'syncExistingHeaderWithImportedInvoice', $f->ref_client, date('Y-m-d'), '0.88'), 'Header refresh accepts verified source cents');
	$r = $db->query('SELECT synch FROM ' . MAIN_DB_PREFIX . "dolizsynch_zsinvoicesynch WHERE invoice_ref='$ref' AND entity=$entity");
	$assert((int) $db->fetch_object($r)->synch === ZSSyncStatus::OK, 'Verified header refresh can mark OK');
	$f->fetch_lines();
	$firstLine = (int) $f->lines[0]->id;
	$secondLine = (int) $f->lines[1]->id;
	$db->query('UPDATE ' . MAIN_DB_PREFIX . "facturedet SET total_ht=total_ht+0.01,total_ttc=total_ttc+0.01 WHERE rowid=$firstLine");
	$db->query('UPDATE ' . MAIN_DB_PREFIX . "facturedet SET total_ht=total_ht-0.01,total_ttc=total_ttc-0.01 WHERE rowid=$secondLine");
	$assert($invoke($sync, 'verifyStoredCustomerInvoiceConsistency', $f, '0.88'), 'Aggregate consistency alone cannot detect offsetting line errors');
	$assert(!$invoke($sync, 'verifyCustomerInvoiceMonetaryPlan', $f, $plan, 1), 'Source verification rejects offsetting errors on individual lines');
	$assert($invoke($sync, 'reconcileCustomerInvoiceTotals', $f, $mapped), 'Restore every source line after offsetting-error test');

	$r = $db->query('SELECT id FROM ' . MAIN_DB_PREFIX . 'c_paiement WHERE active=1 ORDER BY id LIMIT 1');
	$paymentType = $db->fetch_object($r);
	$assert((bool) $paymentType, 'A native payment type is required');
	$depth = (int) $db->transaction_opened;
	$assert(!$invoke($sync, 'applyPaymentToInvoice', $f->id, $paymentType->id, 0, '0.89', dol_now()), 'Do not pay a mismatched invoice');
	$assert((int) $db->transaction_opened === $depth, 'Failed payment restores the caller transaction depth');
	$assert($invoke($sync, 'applyPaymentToInvoice', $f->id, $paymentType->id, 0, '0.88', dol_now()), 'Apply one source payment under the invoice lock: ' . $sync->error);
	$assert($invoke($sync, 'applyPaymentToInvoice', $f->id, $paymentType->id, 0, '0.88', dol_now()), 'Repeated payment is a no-op');
	$r = $db->query('SELECT COUNT(*) n,SUM(amount) amount FROM ' . MAIN_DB_PREFIX . 'paiement_facture WHERE fk_facture=' . (int) $f->id);
	$paid = $db->fetch_object($r);
	$assert((int) $paid->n === 1 && ZSDecimal::round((string) $paid->amount, 2) === '0.88', 'Exactly one payment exists for exactly EUR 0.88: ' . json_encode($paid));
	$assert((int) $db->transaction_opened === $depth, 'Successful payment preserves the caller transaction depth');
} catch (Throwable $e) {
	$failure = $e->getMessage();
} finally {
	while ((int) $db->transaction_opened > $baseline) {
		$db->rollback();
	}
	$user = $originalUser;
}
if ($failure !== '') {
	fwrite(STDERR, 'FAIL: ' . $failure . "\n");
	exit(1);
}
echo 'PASS: ' . $checks . " split-bill rounding/recovery/payment checks; all business rows rolled back\n";

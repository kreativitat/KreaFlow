<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

// Local integration only. No source/API calls, stock products, payments, or sends.
if (PHP_SAPI !== 'cli' || !is_file('/var/www/html/master.inc.php')) {
	echo "SKIP: local Dolibarr integration container required\n";
	exit(0);
}
require '/var/www/html/master.inc.php';
require_once dirname(__DIR__) . '/class/zsinvoicesynch.class.php';
require_once DOL_DOCUMENT_ROOT . '/core/class/discount.class.php';

class CreditNoteFixtureStatusRecorder
{
	public function setSyncStatus($reference, $status, $error = null, array $context = []) { return true; }
}

class CreditNoteDolibarrHarness extends ZSInvoiceSynch
{
	private $fixtureCustomer;
	public function __construct($db, $customer)
	{
		$this->db = $db;
		$this->fixtureCustomer = $customer;
		$this->syncManager = new CreditNoteFixtureStatusRecorder();
	}
	public function customerValidation($customer, $name) { return $this->fixtureCustomer; }
	public function findDolibarrProdIdFromCodigo($code, $line = null) { return 0; }
	public function fetchInvoiceLines($doc, $serie, $numero, $date, $store = null)
	{
		throw new RuntimeException('Integration fixture must never contact a source API');
	}
}

// Exercise native invoice persistence/validation without invoking subscribers
// that could send notifications or call an external integration.
class CreditNoteFixtureInvoice extends Facture
{
	public $fixtureEvents = [];
	public function call_trigger($triggerName, $actor)
	{
		$this->fixtureEvents[] = $triggerName;
		return 0;
	}
}

$checks = 0;
$assert = static function ($condition, $message) use (&$checks) {
	if (!$condition) {
		throw new RuntimeException($message);
	}
	$checks++;
};
$invoke = static function ($object, $method, ...$args) {
	$reflection = new ReflectionMethod($object, $method);
	$reflection->setAccessible(true);
	return $reflection->invoke($object, ...$args);
};
$baseline = (int) $db->transaction_opened;
$originalUser = $user;
$originalIncludeVat = $conf->global->ZS_IVA_INCLUIDO ?? null;
$failure = '';
$invoiceId = 0;
$originalId = 0;
$discountId = 0;

try {
	$entity = (int) $conf->entity;
	$result = $db->query('SELECT rowid FROM ' . MAIN_DB_PREFIX . 'societe WHERE entity=' . $entity . ' AND client IN(1,2,3) ORDER BY rowid LIMIT 1');
	$customer = $result ? $db->fetch_object($result) : null;
	$assert((bool) $customer, 'A current-entity customer is required');
	$result = $db->query('SELECT rowid FROM ' . MAIN_DB_PREFIX . 'user WHERE admin=1 AND statut=1 AND entity IN(0,' . $entity . ') ORDER BY rowid LIMIT 1');
	$actor = $result ? $db->fetch_object($result) : null;
	$user = new User($db);
	$assert($actor && $user->fetch((int) $actor->rowid) > 0, 'An active test lifecycle actor is required');
	$user->loadRights();
	$conf->global->ZS_IVA_INCLUIDO = 1;
	$sync = new CreditNoteDolibarrHarness($db, (int) $customer->rowid);
	$source = (object) [
		'doc' => 'NC', 'serie' => 'CREDITTEST', 'numero' => 1, 'data' => '2020-01-01',
		'countrycode' => 'PT', 'contribuinte' => '', 'nome' => 'Synthetic customer',
		'loja' => (int) ($conf->global->ZS_API_STORE ?? 0), 'emp' => '',
		'total' => '-103.29', 'liquido' => '-91.41', 'dpercent' => '0', 'descontos' => '0',
		'vendas' => [(object) ['codigo' => 1, 'descricao' => 'Synthetic credit line', 'qtd' => '-11', 'punit' => '9.39', 'total' => '-103.29', 'liquido' => '-91.41', 'iva' => '13', 'desconto' => '0', 'desconto2' => '0']],
	];
	$mapped = $sync->mapZSToDolibarrInvoice($source);
	$assert(is_array($mapped), 'Map the signed source fixture: ' . $sync->error);
	$assert($db->begin(), 'Begin rollback-only native integration transaction');
	$invoice = new CreditNoteFixtureInvoice($db);
	$invoice->socid = (int) $customer->rowid;
	$invoice->date = dol_now();
	$invoice->type = Facture::TYPE_CREDIT_NOTE;
	$invoice->ref_client = 'NC_CREDITTEST_' . bin2hex(random_bytes(6));
	$invoice->ref_customer = $invoice->ref_client;
	$assert($invoice->create($user) > 0, 'Create native rollback-only credit note: ' . $invoice->error);

	// Restored local snapshots may have orphan detail/payment identifiers beyond
	// the invoice sequence. Keep this synthetic fixture above those identifiers.
	$result = $db->query('SELECT GREATEST((SELECT COALESCE(MAX(rowid),0) FROM ' . MAIN_DB_PREFIX . 'facture),'
		. '(SELECT COALESCE(MAX(fk_facture),0) FROM ' . MAIN_DB_PREFIX . 'facturedet),'
		. '(SELECT COALESCE(MAX(fk_facture),0) FROM ' . MAIN_DB_PREFIX . 'paiement_facture))+1 id');
	$freshId = $result ? (int) $db->fetch_object($result)->id : 0;
	$assert($freshId > 0 && $db->query('UPDATE ' . MAIN_DB_PREFIX . 'facture SET rowid=' . $freshId . ' WHERE rowid=' . (int) $invoice->id), 'Isolate native fixture from restored orphan records');
	$invoice->id = $invoice->rowid = $invoiceId = $freshId;
	$line = $mapped['lines'][0];
	$assert($invoke($sync, 'addCustomerInvoiceLineUsingNativeApi', $invoice, $line, 0, 'TTC', $line['pu_ttc']) > 0, 'Native API stores the mapped credit line');
	$assert($invoke($sync, 'reconcileCustomerInvoiceTotals', $invoice, $mapped), 'Reconcile exact source credit cents: ' . $sync->error);
	$assert($invoke($sync, 'validateInvoiceWithMonetaryPlan', $invoice, $user, 0, $invoice->ref_client, $mapped['monetary_plan']), 'Validate the native credit note with the exact source plan: ' . $sync->error . ' ' . $invoice->error . ' ' . implode('; ', $invoice->errors));
	$assert($invoice->fetch($invoiceId) > 0 && $invoice->fetch_lines() >= 0, 'Reload native credit note');
	$assert((int) $invoice->type === Facture::TYPE_CREDIT_NOTE && (int) $invoice->statut === Facture::STATUS_VALIDATED, 'Native credit note is validated');
	$expected = ['-91.41', '-11.88', '-103.29'];
	$fields = ['total_ht', 'total_tva', 'total_ttc'];
	$assert(count($invoice->lines) === 1, 'Exactly one native line is stored');
	foreach ($fields as $index => $field) {
		$assert(ZSDecimal::round((string) $invoice->{$field}, 2) === $expected[$index], 'Native credit header ' . $field . ' has exactly one negative sign');
		$assert(ZSDecimal::round((string) $invoice->{'multicurrency_' . $field}, 2) === $expected[$index], 'Native credit multicurrency header ' . $field . ' matches');
		$assert(ZSDecimal::round((string) $invoice->lines[0]->{$field}, 2) === $expected[$index], 'Native credit source line ' . $field . ' matches');
		$assert(ZSDecimal::round((string) $invoice->lines[0]->{'multicurrency_' . $field}, 2) === $expected[$index], 'Native credit multicurrency line ' . $field . ' matches');
	}
	$assert(ZSDecimal::compare((string) $invoice->lines[0]->qty, '11') === 0, 'Native credit quantity remains positive');
	$assert(ZSDecimal::compare((string) $invoice->lines[0]->subprice, '0') < 0, 'Native credit unit price applies the negative sign');
	$assert(in_array('BILL_VALIDATE', $invoice->fixtureEvents, true), 'Native validation reaches its trigger boundary without external callbacks');
	$assert($invoke($sync, 'verifyStoredCustomerInvoiceConsistency', $invoice, '-103.29'), 'Signed source TTC is accepted on a later verification');
	$assert($invoke($sync, 'verifyStoredCustomerInvoiceConsistency', $invoice, '103.29'), 'Legacy positive source TTC is accepted on a later verification');
	$assert(!$invoke($sync, 'verifyStoredCustomerInvoiceConsistency', $invoice, '-103.30'), 'Incorrect signed source TTC is rejected');
	$assert(!$invoke($sync, 'verifyStoredCustomerInvoiceConsistency', $invoice, '103.30'), 'Incorrect legacy source TTC is rejected');
	$assert($invoke($sync, 'reconcileCustomerInvoiceTotals', $invoice, $mapped), 'Repeated native monetary reconciliation is idempotent');
	$assert($invoice->fetch_lines() >= 0 && count($invoice->lines) === 1 && ZSDecimal::round((string) $invoice->total_ttc, 2) === '-103.29', 'Repeated reconciliation preserves one line and exact signed TTC');
	$result = $db->query('SELECT COUNT(*) n FROM ' . MAIN_DB_PREFIX . 'paiement_facture WHERE fk_facture=' . $invoiceId);
	$assert($result && (int) $db->fetch_object($result)->n === 0, 'Native fixture creates no payment');

	// A source-backed compensation reduces the original invoice's outstanding
	// amount through a native credit allocation, preserving its fiscal lines.
	$original = new CreditNoteFixtureInvoice($db);
	$original->socid = (int) $customer->rowid;
	$original->date = dol_now();
	$original->type = Facture::TYPE_STANDARD;
	$original->ref_client = 'FA_CREDITTEST_' . bin2hex(random_bytes(6));
	$original->ref_customer = $original->ref_client;
	$assert($original->create($user) > 0, 'Create synthetic original invoice');
	$originalId = (int) $original->id;
	$assert($invoke($sync, 'addCustomerInvoiceLineUsingNativeApi', $original, $line, 0, 'TTC', $line['pu_ttc']) > 0, 'Create original invoice source line');
	$assert($invoke($sync, 'reconcileCustomerInvoiceTotals', $original, $mapped), 'Reconcile original invoice source cents');
	$assert($invoke($sync, 'validateInvoiceWithMonetaryPlan', $original, $user, 0, $original->ref_client, $mapped['monetary_plan']), 'Validate original invoice before credit allocation');
	$assert(ZSDecimal::round((string) $original->getRemainToPay(0), 2) === '103.29', 'Original invoice starts fully outstanding');
	$discount = new DiscountAbsolute($db);
	$discount->socid = $discount->fk_soc = (int) $customer->rowid;
	$discount->discount_type = 0;
	$discount->fk_facture_source = $invoiceId;
	$discount->description = '(CREDIT_NOTE)';
	$discount->tva_tx = '13';
	$discount->multicurrency_code = 'EUR';
	$discount->multicurrency_tx = 1;
	foreach (['ht' => '91.41', 'tva' => '11.88', 'ttc' => '103.29'] as $part => $value) {
		$discount->{'total_' . $part} = $discount->{'amount_' . $part} = $value;
		$discount->{'multicurrency_total_' . $part} = $discount->{'multicurrency_amount_' . $part} = $value;
	}
	$assert($discount->create($user) > 0, 'Convert native credit note into available credit: ' . $discount->error);
	$discountId = (int) $discount->id;
	$assert($discount->link_to_invoice(0, $originalId, 1) > 0, 'Allocate credit against the original invoice without modifying invoice lines');
	$assert(ZSDecimal::round((string) $original->getRemainToPay(0), 2) === '0.00', 'Native credit allocation settles the original outstanding balance');
	$assert($invoice->setPaid($user) > 0 && $original->setPaid($user) > 0, 'Close converted credit and fully offset original through native lifecycle');
	$assert($original->fetch($originalId) > 0 && $original->fetch_lines() >= 0, 'Reload original invoice after credit allocation');
	$assert(count($original->lines) === 1 && ZSDecimal::round((string) $original->total_ttc, 2) === '103.29', 'Original invoice fiscal total and line count are preserved');
	$assert(ZSDecimal::round((string) $original->lines[0]->total_ttc, 2) === '103.29', 'Original source line remains unchanged by allocation');
	$reloadedDiscount = new DiscountAbsolute($db);
	$assert($reloadedDiscount->fetch(0, $invoiceId) > 0 && (int) $reloadedDiscount->id === $discountId && (int) $reloadedDiscount->fk_facture === $originalId, 'Existing native allocation can be identified before any retry');
	$result = $db->query('SELECT COUNT(*) n FROM ' . MAIN_DB_PREFIX . 'paiement_facture WHERE fk_facture IN(' . $invoiceId . ',' . $originalId . ')');
	$assert($result && (int) $db->fetch_object($result)->n === 0, 'Credit compensation creates no payment or bank-payment link');
} catch (Throwable $e) {
	$failure = $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine();
} finally {
	while ((int) $db->transaction_opened > $baseline) {
		$db->rollback();
	}
	$user = $originalUser;
	if ($originalIncludeVat === null) {
		unset($conf->global->ZS_IVA_INCLUIDO);
	} else {
		$conf->global->ZS_IVA_INCLUIDO = $originalIncludeVat;
	}
}
if ($failure === '' && $invoiceId > 0) {
	$result = $db->query('SELECT COUNT(*) n FROM ' . MAIN_DB_PREFIX . 'facture WHERE rowid IN(' . $invoiceId . ',' . $originalId . ')');
	if (!$result || (int) $db->fetch_object($result)->n !== 0) {
		$failure = 'Rollback did not remove the synthetic native credit note';
	}
	$result = $db->query('SELECT COUNT(*) n FROM ' . MAIN_DB_PREFIX . 'societe_remise_except WHERE rowid=' . $discountId);
	if (!$result || (int) $db->fetch_object($result)->n !== 0) {
		$failure = 'Rollback did not remove the synthetic credit allocation';
	}
}
if ($failure !== '') {
	fwrite(STDERR, 'FAIL: ' . $failure . "\n");
	exit(1);
}
echo 'PASS: ' . $checks . " native credit-note checks; business rows rolled back; external callbacks suppressed\n";

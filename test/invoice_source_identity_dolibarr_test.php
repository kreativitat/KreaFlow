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

// This suite requires the disposable fixture; it never contacts ZoneSoft.
if (PHP_SAPI !== 'cli' || !is_file('/var/www/html/master.inc.php')) {
	echo "SKIP: isolated Dolibarr integration container required\n";
	exit(0);
}
require '/var/www/html/master.inc.php';
if ($dolibarr_main_db_name !== 'kreaflow_invoicesync_test') {
	throw new RuntimeException('Refusing to run outside the disposable invoice-sync fixture');
}
require_once dirname(__DIR__) . '/class/zsinvoicesynch.class.php';

class InvoiceSourceIdentityFakeApi
{
	public $response;
	public $exception;
	public $calls = 0;

	public function setInterface($interface)
	{
	}

	public function executeForStore($operation, array $payload, $store)
	{
		$this->calls++;
		if ($this->exception) {
			throw $this->exception;
		}
		return $this->response;
	}
}

class InvoiceSourceIdentityStatusRecorder
{
	public $events = [];

	public function setSyncStatus($ref, $status, $message = null, array $context = [])
	{
		$this->events[] = [$ref, $status, $message, $context];
		return true;
	}
}

class InvoiceSourceIdentityHarness extends ZSInvoiceSynch
{
	public $mappingCalls = 0;
	public $creationCalls = 0;
	public $customerCalls = 0;
	public $receiptCalls = 0;

	public function __construct($db)
	{
		$this->db = $db;
		$this->zoneSoftApi = new InvoiceSourceIdentityFakeApi();
		$this->syncManager = new InvoiceSourceIdentityStatusRecorder();
	}

	public function customerValidation($customer, $name)
	{
		$this->customerCalls++;
		return 1;
	}

	public function mapZSToDolibarrInvoice($source)
	{
		$this->mappingCalls++;
		throw new RuntimeException('Unexpected mapping of unverified source');
	}

	public function createOrUpdateDolibarrInvoice($mapped)
	{
		$this->creationCalls++;
		throw new RuntimeException('Unexpected native financial creation');
	}

	public function payAllInvoicesFromRC($source)
	{
		$this->receiptCalls++;
		return true;
	}
}

class InvoiceSourceIdentityIncompleteHarness extends InvoiceSourceIdentityHarness
{
	public function fetchInvoiceLines($doc, $serie, $numero, $date, $store = null)
	{
		return ['data' => (object) ['document' => new stdClass()]];
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
$financialCounts = static function () use ($db) {
	$counts = [];
	foreach (['facture', 'facturedet', 'paiement', 'paiement_facture', 'bank', 'bank_url', 'stock_mouvement'] as $table) {
		$result = $db->query('SELECT COUNT(*) AS n FROM ' . MAIN_DB_PREFIX . $table);
		if (!$result) {
			throw new RuntimeException('Unable to verify synthetic table ' . $table);
		}
		$counts[$table] = (int) $db->fetch_object($result)->n;
	}
	return $counts;
};
$user->fetch(1);
$user->loadRights();
$sync = new InvoiceSourceIdentityHarness($db);
$source = (object) [
	'loja' => 18, 'doc' => 'FA', 'serie' => 'SOURCE', 'numero' => 1, 'data' => '2026-10-01',
	'vendas' => [(object) ['codigo' => 1, 'qtd' => 1, 'punit' => 10, 'iva' => 0, 'total' => 10, 'liquido' => 10]],
];
$response = static function ($document, $status = 200) {
	return ['Response' => ['StatusCode' => $status, 'Content' => ['document' => $document]]];
};
$row = (object) [
	'invoice_ref' => 'FA_SOURCE_1_20261001', 'doc' => 'FA', 'doc_serie' => 'SOURCE', 'doc_num' => 1,
	'data_zs' => '2026-10-01', 'datahora_zs' => '2026-10-01 12:00:00', 'loja_zs' => 18,
	'fk_facture' => 0, 'synch' => 8, 'anulado_zs' => 0, 'pago_zs' => 0,
	'total_zs' => 10, 'liquido_zs' => 10, 'contribuinte_zs' => '', 'emp_zs' => '', 'sync_context' => null,
];
$before = $financialCounts();
$baseline = (int) $db->transaction_opened;
$db->begin();
$failed = false;
try {
	$sync->zoneSoftApi->response = $response($source);
	$assert(isset($sync->fetchInvoiceLines('FA', 'SOURCE', 1, '2026-10-01', 18)['data']), 'Exact source fiscal identity and sales lines are accepted');

	$cases = [];
	foreach (['loja' => 99, 'doc' => 'FT', 'serie' => 'OTHER', 'numero' => 10, 'data' => '2026-09-30'] as $field => $wrongValue) {
		$wrong = clone $source;
		$wrong->{$field} = $wrongValue;
		$cases[] = [$field, $response($wrong), null, $field === 'data' ? 'date_mismatch' : 'source_identity_mismatch', $field === 'data' ? 22 : 7];
	}
	$incomplete = clone $source;
	unset($incomplete->vendas);
	$cases[] = ['missing sales lines', $response($incomplete), null, 'incomplete_source', 7];
	$incomplete = clone $source;
	$incomplete->vendas = [];
	$cases[] = ['empty sales lines', $response($incomplete), null, 'incomplete_source', 7];
	$cases[] = ['missing source document', $response(null), null, 'incomplete_source', 7];
	$cases[] = ['HTTP 401', $response(null, 401), null, 'authentication_failed', 21];
	$cases[] = ['HTTP 204', [], null, 'invoice_not_found', 23];
	$cases[] = ['HTTP 503', $response(null, 503), null, 'fetch_failed', 2];
	$cases[] = ['API error', null, new ZSAPIException('Empty response from API', 503), 'api_exception', 2];
	$cases[] = ['authentication exception', null, new ZSAPIException('Authentication refused', 401), 'authentication_failed', 21];
	foreach ($cases as [$label, $reply, $exception, $error, $status]) {
		$sync->zoneSoftApi->response = $reply;
		$sync->zoneSoftApi->exception = $exception;
		$details = $sync->fetchInvoiceLines('FA', 'SOURCE', 1, '2026-10-01', 18);
		$assert(($details['error'] ?? '') === $error, $label . ' is classified correctly');
		$assert($invoke($sync, 'synchronizeLatestUnsynchronisedInvoiceLocked', clone $row) === false, $label . ' refuses synchronization');
		$event = end($sync->syncManager->events);
		$assert($event[1] === $status && trim($event[2]) !== '', $label . ' preserves a meaningful failure status/message');
		$assert($sync->mappingCalls === 0 && $sync->creationCalls === 0 && $sync->customerCalls === 0, $label . ' cannot reach mapping or native financial creation');
		$assert($financialCounts() === $before, $label . ' leaves every financial and stock table unchanged');
	}
	$incompleteSync = new InvoiceSourceIdentityIncompleteHarness($db);
	$assert($invoke($incompleteSync, 'synchronizeLatestUnsynchronisedInvoiceLocked', clone $row) === false, 'Creation boundary independently refuses an incomplete source adapter response');
	$assert($incompleteSync->mappingCalls === 0 && $incompleteSync->creationCalls === 0 && $financialCounts() === $before, 'Incomplete adapter data cannot bypass the sales-line requirement');

	$sync->zoneSoftApi->exception = null;
	$receipt = clone $source;
	$receipt->doc = 'RC';
	unset($receipt->vendas);
	$sync->zoneSoftApi->response = $response($receipt);
	$assert(isset($sync->fetchInvoiceLines('RC', 'SOURCE', 1, '2026-10-01', 18)['data']), 'Receipts retain exact identity validation without requiring invoice sales lines');
	$receiptRow = clone $row;
	$receiptRow->doc = 'RC';
	$receiptRow->invoice_ref = 'RC_SOURCE_1_20261001';
	$assert($invoke($sync, 'synchronizeLatestUnsynchronisedInvoiceLocked', $receiptRow) === true && $sync->receiptCalls === 1, 'Receipts use their separate reconciliation path');
	$assert($sync->creationCalls === 0 && $financialCounts() === $before, 'Receipt exemption never invokes invoice creation');
	echo 'OK: ' . $checks . " source identity and fail-closed integration checks\n";
} catch (Throwable $e) {
	fwrite(STDERR, 'FAIL: ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n");
	$failed = true;
} finally {
	while ((int) $db->transaction_opened > $baseline) {
		$db->rollback();
	}
}
if ($failed) {
	exit(1);
}

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
 * Isolated regression checks for paid customer-invoice cancellation.
 *
 * Usage:
 * - php test/paid_invoice_cancellation_test.php
 */

if (!defined('DOL_DOCUMENT_ROOT')) {
	define('DOL_DOCUMENT_ROOT', __DIR__);
}
if (!defined('MAIN_DB_PREFIX')) {
	define('MAIN_DB_PREFIX', 'llx_');
}
if (!defined('LOG_ERR')) {
	define('LOG_ERR', 3);
}
if (!defined('LOG_WARNING')) {
	define('LOG_WARNING', 4);
}
if (!defined('LOG_INFO')) {
	define('LOG_INFO', 6);
}

function getEntity($element)
{
	return '2';
}

function getDolGlobalString($name, $default = '')
{
	return $default;
}

class AccountLine
{
}

class DoliZSynchPaymentCancellationFakeResult
{
	public $rows = array();
	public $index = 0;

	public function __construct(array $rows)
	{
		$this->rows = $rows;
	}
}

class DoliZSynchPaymentCancellationFakeDb
{
	public $events = array();
	public $beginCount = 0;
	public $commitCount = 0;
	public $rollbackCount = 0;
	public $physicalCommitCount = 0;
	public $physicalRollbackCount = 0;
	public $transaction_opened = 0;
	public $queries = array();

	private $config = array();
	private $paymentLinks = array();
	private $invoiceState = array();
	private $snapshot = null;
	private $invoice = null;

	public function __construct(array $config)
	{
		$this->config = $config;
		$this->transaction_opened = (int) $config['initial_transaction_depth'];
		$this->paymentLinks = array_values($config['payment_links']);
		$this->invoiceState = array(
			'status' => (int) $config['invoice_status'],
			'statut' => (int) $config['invoice_status'],
			'paye' => (int) $config['invoice_paye'],
		);
	}

	public function attachInvoice($invoice)
	{
		$this->invoice = $invoice;
	}

	public function begin()
	{
		$this->events[] = 'db.begin';
		$this->beginCount++;
		if (!empty($this->config['begin_failure']) && $this->transaction_opened === (int) $this->config['initial_transaction_depth']) {
			return false;
		}
		if ($this->transaction_opened === 0) {
			$this->snapshot = array(
				'payment_links' => $this->paymentLinks,
				'invoice_state' => $this->invoiceState,
			);
		}
		$this->transaction_opened++;
		return true;
	}

	public function commit()
	{
		$this->events[] = 'db.commit';
		$this->commitCount++;
		if ($this->transaction_opened <= 0) {
			return false;
		}
		if ($this->transaction_opened > 1) {
			$this->transaction_opened--;
			return true;
		}
		if (!empty($this->config['commit_failure'])) {
			return false;
		}
		$this->transaction_opened = 0;
		$this->physicalCommitCount++;
		$this->snapshot = null;
		return true;
	}

	public function rollback()
	{
		$this->events[] = 'db.rollback';
		$this->rollbackCount++;
		if ($this->transaction_opened <= 0) {
			return false;
		}
		if ($this->transaction_opened > 1) {
			$this->transaction_opened--;
			return true;
		}
		$this->transaction_opened = 0;
		$this->physicalRollbackCount++;
		if (is_array($this->snapshot)) {
			$this->paymentLinks = $this->snapshot['payment_links'];
			$this->invoiceState = $this->snapshot['invoice_state'];
			if ($this->invoice) {
				$this->invoice->importState($this->invoiceState);
			}
		}
		$this->snapshot = null;
		return true;
	}

	public function query($sql)
	{
		$this->queries[] = $sql;

		if (strpos($sql, 'FROM ' . MAIN_DB_PREFIX . 'facture') !== false && strpos($sql, 'FOR UPDATE') !== false) {
			if (!empty($this->config['lock_failure'])) {
				return false;
			}
			return new DoliZSynchPaymentCancellationFakeResult(array(array('rowid' => (int) $this->config['invoice_id'])));
		}

		if (strpos($sql, 'SELECT fk_paiement AS payment_id FROM ' . MAIN_DB_PREFIX . 'paiement_facture') !== false) {
			$rows = array();
			$paymentIds = $this->paymentLinks;
			sort($paymentIds);
			foreach ($paymentIds as $paymentId) {
				$rows[] = array('payment_id' => (int) $paymentId);
			}
			return new DoliZSynchPaymentCancellationFakeResult($rows);
		}

		if (strpos($sql, 'FROM ' . MAIN_DB_PREFIX . 'paiement WHERE') !== false) {
			$paymentId = 0;
			if (preg_match('/rowid\s*=\s*(\d+)/', $sql, $matches)) {
				$paymentId = (int) $matches[1];
			}
			if (!isset($this->config['payments'][$paymentId])) {
				return new DoliZSynchPaymentCancellationFakeResult(array());
			}
			$payment = $this->config['payments'][$paymentId];
			return new DoliZSynchPaymentCancellationFakeResult(array(array(
				'payment_exists' => (int) $payment['payment_exists'],
				'payment_entity' => (int) $payment['payment_entity'],
				'fk_bank' => (int) $payment['fk_bank'],
			)));
		}

		if (strpos($sql, 'SELECT fk_facture FROM ' . MAIN_DB_PREFIX . 'paiement_facture') !== false) {
			$paymentId = 0;
			if (preg_match('/fk_paiement\s*=\s*(\d+)/', $sql, $matches)) {
				$paymentId = (int) $matches[1];
			}
			$rows = array();
			if (isset($this->config['payments'][$paymentId])) {
				$linkedInvoiceIds = $this->config['payments'][$paymentId]['linked_invoice_ids'];
				sort($linkedInvoiceIds);
				foreach ($linkedInvoiceIds as $linkedInvoiceId) {
					$rows[] = array('fk_facture' => (int) $linkedInvoiceId);
				}
			}
			return new DoliZSynchPaymentCancellationFakeResult($rows);
		}

		if (strpos($sql, 'FROM ' . MAIN_DB_PREFIX . 'bank WHERE') !== false) {
			$bankLineId = 0;
			if (preg_match('/rowid\s*=\s*(\d+)/', $sql, $matches)) {
				$bankLineId = (int) $matches[1];
			}
			foreach ($this->config['payments'] as $payment) {
				if ((int) $payment['fk_bank'] !== $bankLineId || !empty($payment['bank_line_missing'])) {
					continue;
				}
				return new DoliZSynchPaymentCancellationFakeResult(array(array(
					'bank_line_id' => (int) $payment['bank_line_id'],
					'fk_account' => (int) $payment['bank_account_id'],
					'bank_reconciled' => (int) $payment['bank_reconciled'],
				)));
			}
			return new DoliZSynchPaymentCancellationFakeResult(array());
		}

		if (strpos($sql, 'FROM ' . MAIN_DB_PREFIX . 'bank_account WHERE') !== false) {
			$bankAccountId = 0;
			if (preg_match('/rowid\s*=\s*(\d+)/', $sql, $matches)) {
				$bankAccountId = (int) $matches[1];
			}
			foreach ($this->config['payments'] as $payment) {
				if ((int) $payment['bank_account_id'] === $bankAccountId && empty($payment['bank_account_missing'])) {
					return new DoliZSynchPaymentCancellationFakeResult(array(array('rowid' => $bankAccountId)));
				}
			}
			return new DoliZSynchPaymentCancellationFakeResult(array());
		}

		if (strpos($sql, 'FROM ' . MAIN_DB_PREFIX . 'accounting_bookkeeping') !== false) {
			$bankLineId = 0;
			if (preg_match('/fk_doc\s*=\s*(\d+)/', $sql, $matches)) {
				$bankLineId = (int) $matches[1];
			}
			$count = 0;
			foreach ($this->config['payments'] as $payment) {
				if ((int) $payment['fk_bank'] === $bankLineId) {
					$count = (int) $payment['accounting_count'];
					break;
				}
			}
			return new DoliZSynchPaymentCancellationFakeResult(array(array('nb' => $count)));
		}

		if (strpos($sql, 'SELECT COUNT(*) AS cnt FROM ' . MAIN_DB_PREFIX . 'paiement_facture') !== false) {
			return new DoliZSynchPaymentCancellationFakeResult(array(array('cnt' => count($this->paymentLinks))));
		}

		throw new RuntimeException('Unexpected SQL in cancellation test: ' . $sql);
	}

	public function fetch_object($result)
	{
		if (!($result instanceof DoliZSynchPaymentCancellationFakeResult)) {
			return false;
		}
		if ($result->index >= count($result->rows)) {
			return false;
		}
		$row = $result->rows[$result->index];
		$result->index++;
		return (object) $row;
	}

	public function lasterror()
	{
		return 'Synthetic database failure';
	}

	public function hasPayment($paymentId)
	{
		return isset($this->config['payments'][$paymentId])
			&& (int) $this->config['payments'][$paymentId]['payment_exists'] === (int) $paymentId;
	}

	public function shouldFailPaymentDelete($paymentId)
	{
		return in_array((int) $paymentId, $this->config['delete_failures'], true);
	}

	public function shouldThrowPaymentDelete($paymentId)
	{
		return in_array((int) $paymentId, $this->config['delete_throw_ids'], true);
	}

	public function deletePayment($paymentId)
	{
		$remaining = array();
		foreach ($this->paymentLinks as $linkedPaymentId) {
			if ((int) $linkedPaymentId !== (int) $paymentId) {
				$remaining[] = (int) $linkedPaymentId;
			}
		}
		$this->paymentLinks = $remaining;
	}

	public function getPaymentLinks()
	{
		$links = $this->paymentLinks;
		sort($links);
		return $links;
	}

	public function getInvoiceState()
	{
		return $this->invoiceState;
	}

	public function setInvoiceState($status, $paye)
	{
		$this->invoiceState = array(
			'status' => (int) $status,
			'statut' => (int) $status,
			'paye' => (int) $paye,
		);
	}
}

class Facture
{
	const STATUS_DRAFT = 0;
	const STATUS_VALIDATED = 1;
	const STATUS_CLOSED = 2;
	const STATUS_ABANDONED = 3;

	public $id = 0;
	public $ref = '';
	public $status = 0;
	public $statut = 0;
	public $paye = 0;
	public $error = '';

	private $db;
	private $fetchResults = array();
	private $setUnpaidResult = 1;
	private $setUnpaidChangesState = true;
	private $setUnpaidThrows = false;

	public function __construct($db, array $config = array())
	{
		$this->db = $db;
		$this->id = (int) $config['invoice_id'];
		$this->ref = (string) $config['invoice_ref'];
		$this->fetchResults = array_values($config['invoice_fetch_results']);
		$this->setUnpaidResult = (int) $config['set_unpaid_result'];
		$this->setUnpaidChangesState = !empty($config['set_unpaid_changes_state']);
		$this->setUnpaidThrows = !empty($config['set_unpaid_throws']);
		$this->importState($db->getInvoiceState());
	}

	public function importState(array $state)
	{
		$this->status = (int) $state['status'];
		$this->statut = (int) $state['statut'];
		$this->paye = (int) $state['paye'];
	}

	public function fetch($invoiceId)
	{
		$this->db->events[] = 'invoice.fetch';
		$result = count($this->fetchResults) ? (int) array_shift($this->fetchResults) : 1;
		if ($result <= 0 || (int) $invoiceId !== $this->id) {
			$this->error = 'Synthetic invoice refetch failure';
			return -1;
		}
		$this->importState($this->db->getInvoiceState());
		return 1;
	}

	public function setUnpaid($user)
	{
		$this->db->events[] = 'invoice.setUnpaid';
		if (!$this->db->begin()) {
			$this->error = 'Synthetic invoice nested transaction failure';
			return -1;
		}
		if ($this->setUnpaidThrows) {
			$this->db->setInvoiceState(self::STATUS_VALIDATED, 0);
			$this->importState($this->db->getInvoiceState());
			throw new RuntimeException('Synthetic setUnpaid trigger exception');
		}
		if ($this->setUnpaidResult < 0) {
			$this->error = 'Synthetic invoice reopen failure';
			$this->db->rollback();
			return -1;
		}
		if ($this->setUnpaidChangesState) {
			$this->db->setInvoiceState(self::STATUS_VALIDATED, 0);
			$this->importState($this->db->getInvoiceState());
		}
		$this->db->commit();
		return $this->setUnpaidResult;
	}
}

class Paiement
{
	public $error = '';

	private $db;
	private $id = 0;

	public function __construct($db)
	{
		$this->db = $db;
	}

	public function fetch($paymentId)
	{
		$this->id = (int) $paymentId;
		$this->db->events[] = 'payment.fetch:' . $this->id;
		if (!$this->db->hasPayment($this->id)) {
			$this->error = 'Synthetic payment fetch failure';
			return -1;
		}
		return 1;
	}

	public function delete($user)
	{
		$this->db->events[] = 'payment.delete:' . $this->id;
		if (!$this->db->begin()) {
			$this->error = 'Synthetic payment nested transaction failure';
			return -1;
		}
		if ($this->db->shouldThrowPaymentDelete($this->id)) {
			$this->db->deletePayment($this->id);
			throw new RuntimeException('Synthetic payment-delete trigger exception');
		}
		if ($this->db->shouldFailPaymentDelete($this->id)) {
			$this->error = 'Synthetic payment deletion failure';
			$this->db->rollback();
			return -1;
		}
		$this->db->deletePayment($this->id);
		$this->db->commit();
		return 1;
	}
}

class DoliZSynchPaymentCancellationFakeLangs
{
	public function trans($key)
	{
		return $key;
	}
}

require_once dirname(__DIR__) . '/class/trait/ZSPaymentHandling.trait.php';

class DoliZSynchPaymentCancellationHarness
{
	use ZSPaymentHandling {
		removePaymentsFromInvoice as public runPaymentRemoval;
	}

	public $error = '';

	public function logDebug($operation, $message, array $context = array())
	{
	}

	public function logOperationWarning($operation, $message, array $context = array())
	{
	}

	public function logOperationError($operation, $message, array $context = array())
	{
	}

	public function logSqlError($operation, $db, array $context = array())
	{
	}
}

$failures = array();

function cancellationAssertSame($expected, $actual, $label)
{
	global $failures;
	if ($expected !== $actual) {
		$failures[] = $label;
		echo '[FAIL] ' . $label . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . PHP_EOL;
		return;
	}
	echo '[PASS] ' . $label . PHP_EOL;
}

function cancellationAssertTrue($condition, $label)
{
	cancellationAssertSame(true, (bool) $condition, $label);
}

function cancellationAssertContains($needle, $haystack, $label)
{
	cancellationAssertTrue(strpos((string) $haystack, (string) $needle) !== false, $label);
}

function cancellationHasEvent(array $events, $event)
{
	return in_array($event, $events, true);
}

function cancellationAssertNoEvent(array $events, $event, $label)
{
	cancellationAssertSame(false, cancellationHasEvent($events, $event), $label);
}

function cancellationAssertEventBefore(array $events, $firstEvent, $secondEvent, $label)
{
	$firstPosition = array_search($firstEvent, $events, true);
	$secondPosition = array_search($secondEvent, $events, true);
	cancellationAssertTrue($firstPosition !== false && $secondPosition !== false && $firstPosition < $secondPosition, $label);
}

function cancellationPayment($paymentId, array $overrides = array())
{
	$defaults = array(
		'payment_id' => (int) $paymentId,
		'payment_exists' => (int) $paymentId,
		'payment_entity' => 2,
		'fk_bank' => 0,
		'bank_line_id' => 0,
		'bank_reconciled' => 0,
		'bank_account_id' => 0,
		'bank_line_missing' => false,
		'bank_account_missing' => false,
		'linked_invoice_ids' => array(1001),
		'accounting_count' => 0,
	);
	return array_merge($defaults, $overrides);
}

function runCancellationPaymentScenario(array $overrides = array(), $reopenForCancellation = true)
{
	global $db, $user, $langs, $conf;

	$defaults = array(
		'invoice_id' => 1001,
		'invoice_ref' => 'SYNTHETIC-CANCEL-1',
		'invoice_status' => Facture::STATUS_CLOSED,
		'invoice_paye' => 1,
		'invoice_fetch_results' => array(1, 1),
		'set_unpaid_result' => 1,
		'set_unpaid_changes_state' => true,
		'set_unpaid_throws' => false,
		'payment_links' => array(101),
		'payments' => array(101 => cancellationPayment(101)),
		'delete_failures' => array(),
		'delete_throw_ids' => array(),
		'lock_failure' => false,
		'begin_failure' => false,
		'commit_failure' => false,
		'initial_transaction_depth' => 0,
	);
	$config = array_merge($defaults, $overrides);

	$db = new DoliZSynchPaymentCancellationFakeDb($config);
	$user = (object) array('id' => 1);
	$langs = new DoliZSynchPaymentCancellationFakeLangs();
	$conf = (object) array('entity' => 2, 'global' => new stdClass());

	$invoice = new Facture($db, $config);
	$db->attachInvoice($invoice);
	$harness = new DoliZSynchPaymentCancellationHarness();
	$result = $harness->runPaymentRemoval($invoice, $reopenForCancellation);

	return array(
		'result' => $result,
		'error' => $harness->error,
		'db' => $db,
		'invoice' => $invoice,
	);
}

$scenario = runCancellationPaymentScenario();
cancellationAssertSame(true, $scenario['result'], 'closed paid invoice payment removal succeeds');
cancellationAssertEventBefore($scenario['db']->events, 'invoice.setUnpaid', 'payment.delete:101', 'native reopen occurs before payment deletion');
cancellationAssertSame(1, $scenario['db']->physicalCommitCount, 'successful payment removal commits the physical transaction once');
cancellationAssertSame(0, $scenario['db']->rollbackCount, 'successful payment removal does not roll back');
cancellationAssertSame(0, $scenario['db']->transaction_opened, 'successful payment removal restores transaction depth to zero');
cancellationAssertSame(array(), $scenario['db']->getPaymentLinks(), 'successful payment removal deletes the invoice payment link');
cancellationAssertSame(Facture::STATUS_VALIDATED, $scenario['invoice']->status, 'successful native reopen leaves invoice validated');
cancellationAssertSame(0, $scenario['invoice']->paye, 'successful native reopen leaves invoice unpaid');

$scenario = runCancellationPaymentScenario(array('set_unpaid_result' => -1));
cancellationAssertSame(false, $scenario['result'], 'native reopen failure rejects payment removal');
cancellationAssertContains('Synthetic invoice reopen failure', $scenario['error'], 'native reopen failure preserves invoice error');
cancellationAssertNoEvent($scenario['db']->events, 'payment.delete:101', 'native reopen failure prevents payment deletion');
cancellationAssertSame(1, $scenario['db']->physicalRollbackCount, 'native reopen failure rolls back the physical transaction');
cancellationAssertSame(0, $scenario['db']->transaction_opened, 'native reopen failure restores transaction depth to zero');
cancellationAssertSame(array(101), $scenario['db']->getPaymentLinks(), 'native reopen failure preserves payment links');
cancellationAssertSame(Facture::STATUS_CLOSED, $scenario['invoice']->status, 'native reopen failure preserves closed status');
cancellationAssertSame(1, $scenario['invoice']->paye, 'native reopen failure preserves paid flag');

$scenario = runCancellationPaymentScenario(array('invoice_fetch_results' => array(1, -1)));
cancellationAssertSame(false, $scenario['result'], 'invoice refetch failure after reopen rejects payment removal');
cancellationAssertContains('Synthetic invoice refetch failure', $scenario['error'], 'invoice refetch failure is preserved');
cancellationAssertNoEvent($scenario['db']->events, 'payment.delete:101', 'invoice refetch failure prevents payment deletion');
cancellationAssertSame(1, $scenario['db']->physicalRollbackCount, 'invoice refetch failure rolls back');
cancellationAssertSame(0, $scenario['db']->transaction_opened, 'invoice refetch failure restores transaction depth to zero');
cancellationAssertSame(array(101), $scenario['db']->getPaymentLinks(), 'invoice refetch failure preserves payment links');
cancellationAssertSame(Facture::STATUS_CLOSED, $scenario['invoice']->status, 'invoice refetch failure restores closed status');
cancellationAssertSame(1, $scenario['invoice']->paye, 'invoice refetch failure restores paid flag');

$scenario = runCancellationPaymentScenario(array('set_unpaid_changes_state' => false));
cancellationAssertSame(false, $scenario['result'], 'native reopen state verification rejects a false-success lifecycle call');
cancellationAssertContains('validated unpaid state', $scenario['error'], 'native reopen state verification reports the invalid state');
cancellationAssertNoEvent($scenario['db']->events, 'payment.delete:101', 'native reopen state verification prevents payment deletion');
cancellationAssertSame(1, $scenario['db']->physicalRollbackCount, 'native reopen state verification failure rolls back');
cancellationAssertSame(0, $scenario['db']->transaction_opened, 'native reopen state verification restores transaction depth to zero');

$scenario = runCancellationPaymentScenario(array(
	'payments' => array(101 => cancellationPayment(101, array('linked_invoice_ids' => array(1001, 1002)))),
));
cancellationAssertSame(false, $scenario['result'], 'shared payment preflight rejects automatic removal');
cancellationAssertContains('shared with another invoice', $scenario['error'], 'shared payment preflight explains the safety rejection');
cancellationAssertNoEvent($scenario['db']->events, 'invoice.setUnpaid', 'shared payment preflight prevents invoice reopening');
cancellationAssertNoEvent($scenario['db']->events, 'payment.delete:101', 'shared payment preflight prevents payment deletion');
cancellationAssertSame(1, $scenario['db']->physicalRollbackCount, 'shared payment preflight rolls back');
cancellationAssertSame(0, $scenario['db']->transaction_opened, 'shared payment preflight restores transaction depth to zero');

$scenario = runCancellationPaymentScenario(array(
	'payments' => array(101 => cancellationPayment(101, array(
		'fk_bank' => 501,
		'bank_line_id' => 501,
		'bank_account_id' => 41,
		'bank_reconciled' => 1,
	))),
));
cancellationAssertSame(false, $scenario['result'], 'reconciled payment preflight rejects automatic removal');
cancellationAssertContains('reconciled bank line', $scenario['error'], 'reconciled payment preflight explains the safety rejection');
cancellationAssertNoEvent($scenario['db']->events, 'invoice.setUnpaid', 'reconciled payment preflight prevents invoice reopening');
cancellationAssertNoEvent($scenario['db']->events, 'payment.delete:101', 'reconciled payment preflight prevents payment deletion');
cancellationAssertSame(1, $scenario['db']->physicalRollbackCount, 'reconciled payment preflight rolls back');
cancellationAssertSame(0, $scenario['db']->transaction_opened, 'reconciled payment preflight restores transaction depth to zero');

$scenario = runCancellationPaymentScenario(array(
	'payments' => array(101 => cancellationPayment(101, array(
		'fk_bank' => 501,
		'bank_line_id' => 501,
		'bank_account_id' => 41,
		'accounting_count' => 1,
	))),
));
cancellationAssertSame(false, $scenario['result'], 'accounted payment preflight rejects automatic removal');
cancellationAssertContains('posted to accounting', $scenario['error'], 'accounted payment preflight explains the safety rejection');
cancellationAssertNoEvent($scenario['db']->events, 'invoice.setUnpaid', 'accounted payment preflight prevents invoice reopening');
cancellationAssertNoEvent($scenario['db']->events, 'payment.delete:101', 'accounted payment preflight prevents payment deletion');
cancellationAssertSame(1, $scenario['db']->physicalRollbackCount, 'accounted payment preflight rolls back');
cancellationAssertSame(0, $scenario['db']->transaction_opened, 'accounted payment preflight restores transaction depth to zero');

$scenario = runCancellationPaymentScenario(array(
	'invoice_status' => Facture::STATUS_VALIDATED,
	'invoice_paye' => 0,
	'invoice_fetch_results' => array(1),
));
cancellationAssertSame(true, $scenario['result'], 'validated unpaid invoice payment removal succeeds');
cancellationAssertNoEvent($scenario['db']->events, 'invoice.setUnpaid', 'validated unpaid invoice skips native reopen');
cancellationAssertTrue(cancellationHasEvent($scenario['db']->events, 'payment.delete:101'), 'validated unpaid invoice deletes its payment');
cancellationAssertSame(1, $scenario['db']->physicalCommitCount, 'validated unpaid payment removal commits');
cancellationAssertSame(0, $scenario['db']->transaction_opened, 'validated unpaid payment removal restores transaction depth to zero');

$scenario = runCancellationPaymentScenario(array(
	'payment_links' => array(101, 202),
	'payments' => array(
		101 => cancellationPayment(101),
		202 => cancellationPayment(202),
	),
	'delete_failures' => array(202),
));
cancellationAssertSame(false, $scenario['result'], 'second payment deletion failure rejects the complete operation');
cancellationAssertEventBefore($scenario['db']->events, 'payment.delete:101', 'payment.delete:202', 'multi-payment removal attempts payments deterministically');
cancellationAssertSame(1, $scenario['db']->physicalRollbackCount, 'second payment deletion failure rolls back the physical transaction once');
cancellationAssertSame(0, $scenario['db']->physicalCommitCount, 'second payment deletion failure does not physically commit');
cancellationAssertSame(0, $scenario['db']->transaction_opened, 'second payment deletion failure restores transaction depth to zero');
cancellationAssertSame(array(101, 202), $scenario['db']->getPaymentLinks(), 'second payment deletion failure restores all payment links');
cancellationAssertSame(Facture::STATUS_CLOSED, $scenario['invoice']->status, 'second payment deletion failure restores closed status');
cancellationAssertSame(1, $scenario['invoice']->paye, 'second payment deletion failure restores paid flag');

$scenario = runCancellationPaymentScenario(array('set_unpaid_throws' => true));
cancellationAssertSame(false, $scenario['result'], 'thrown setUnpaid trigger rejects payment removal');
cancellationAssertContains('Synthetic setUnpaid trigger exception', $scenario['error'], 'thrown setUnpaid trigger error is preserved');
cancellationAssertSame(2, $scenario['db']->rollbackCount, 'thrown setUnpaid trigger unwinds both nested transaction levels');
cancellationAssertSame(1, $scenario['db']->physicalRollbackCount, 'thrown setUnpaid trigger performs one physical rollback');
cancellationAssertSame(0, $scenario['db']->transaction_opened, 'thrown setUnpaid trigger restores transaction depth to zero');
cancellationAssertSame(array(101), $scenario['db']->getPaymentLinks(), 'thrown setUnpaid trigger preserves payment links');
cancellationAssertSame(Facture::STATUS_CLOSED, $scenario['invoice']->status, 'thrown setUnpaid trigger restores closed status');
cancellationAssertSame(1, $scenario['invoice']->paye, 'thrown setUnpaid trigger restores paid flag');
cancellationAssertNoEvent($scenario['db']->events, 'payment.delete:101', 'thrown setUnpaid trigger prevents payment deletion');

$scenario = runCancellationPaymentScenario(array('delete_throw_ids' => array(101)));
cancellationAssertSame(false, $scenario['result'], 'thrown payment-delete trigger rejects payment removal');
cancellationAssertContains('Synthetic payment-delete trigger exception', $scenario['error'], 'thrown payment-delete trigger error is preserved');
cancellationAssertSame(2, $scenario['db']->rollbackCount, 'thrown payment-delete trigger unwinds both nested transaction levels');
cancellationAssertSame(1, $scenario['db']->physicalRollbackCount, 'thrown payment-delete trigger performs one physical rollback');
cancellationAssertSame(0, $scenario['db']->transaction_opened, 'thrown payment-delete trigger restores transaction depth to zero');
cancellationAssertSame(array(101), $scenario['db']->getPaymentLinks(), 'thrown payment-delete trigger physically restores its deleted link');
cancellationAssertSame(Facture::STATUS_CLOSED, $scenario['invoice']->status, 'thrown payment-delete trigger restores closed status');
cancellationAssertSame(1, $scenario['invoice']->paye, 'thrown payment-delete trigger restores paid flag');

$scenario = runCancellationPaymentScenario(array('begin_failure' => true));
cancellationAssertSame(false, $scenario['result'], 'payment-removal begin failure is rejected');
cancellationAssertContains('Unable to start', $scenario['error'], 'payment-removal begin failure reports the transaction error');
cancellationAssertSame(0, $scenario['db']->transaction_opened, 'payment-removal begin failure leaves transaction depth at zero');
cancellationAssertSame(0, $scenario['db']->physicalRollbackCount, 'payment-removal begin failure does not roll back a transaction that never opened');
cancellationAssertNoEvent($scenario['db']->events, 'invoice.fetch', 'payment-removal begin failure performs no invoice work');
cancellationAssertNoEvent($scenario['db']->events, 'invoice.setUnpaid', 'payment-removal begin failure performs no lifecycle mutation');
cancellationAssertNoEvent($scenario['db']->events, 'payment.delete:101', 'payment-removal begin failure performs no payment deletion');

$scenario = runCancellationPaymentScenario(array('commit_failure' => true));
cancellationAssertSame(false, $scenario['result'], 'payment-removal physical commit failure is rejected');
cancellationAssertContains('Unable to commit', $scenario['error'], 'payment-removal physical commit failure reports the transaction error');
cancellationAssertSame(0, $scenario['db']->physicalCommitCount, 'payment-removal commit failure does not physically commit');
cancellationAssertSame(1, $scenario['db']->physicalRollbackCount, 'payment-removal commit failure physically rolls back');
cancellationAssertSame(0, $scenario['db']->transaction_opened, 'payment-removal commit failure restores transaction depth to zero');
cancellationAssertSame(array(101), $scenario['db']->getPaymentLinks(), 'payment-removal commit failure restores payment links');
cancellationAssertSame(Facture::STATUS_CLOSED, $scenario['invoice']->status, 'payment-removal commit failure restores closed status');
cancellationAssertSame(1, $scenario['invoice']->paye, 'payment-removal commit failure restores paid flag');

function cancellationExtractMethodBody($source, $methodName)
{
	$methodPosition = strpos($source, 'function ' . $methodName . '(');
	if ($methodPosition === false) {
		return '';
	}
	$openingBrace = strpos($source, '{', $methodPosition);
	if ($openingBrace === false) {
		return '';
	}
	$depth = 0;
	$length = strlen($source);
	for ($position = $openingBrace; $position < $length; $position++) {
		$character = $source[$position];
		if ($character === '{') {
			$depth++;
		} elseif ($character === '}') {
			$depth--;
			if ($depth === 0) {
				return substr($source, $openingBrace + 1, $position - $openingBrace - 1);
			}
		}
	}
	return '';
}

function cancellationRegexPosition($pattern, $subject)
{
	if (!preg_match($pattern, $subject, $matches, PREG_OFFSET_CAPTURE)) {
		return false;
	}
	return (int) $matches[0][1];
}

$invoiceSynchronizationSource = file_get_contents(dirname(__DIR__) . '/class/zsinvoicesynch.class.php');
$cancellationMethod = cancellationExtractMethodBody($invoiceSynchronizationSource, 'cancelCustomerInvoiceWithStockReversal');
$beginPosition = strpos($cancellationMethod, '$db->begin()');
$paymentRemovalPosition = cancellationRegexPosition(
	'/\$this->removePaymentsFromInvoice\s*\(\s*\$invoice\s*,\s*true(?:\s*,\s*false)?\s*\)/',
	$cancellationMethod
);
$stockReversalPosition = $paymentRemovalPosition === false
	? false
	: strpos($cancellationMethod, '$this->returnStockForInvoice($invoice)', $paymentRemovalPosition);
$cancelPosition = $stockReversalPosition === false
	? false
	: strpos($cancellationMethod, '$invoice->setCanceled($user)', $stockReversalPosition);
$commitPosition = $cancelPosition === false
	? false
	: strpos($cancellationMethod, '$db->commit()', $cancelPosition);

cancellationAssertTrue(
	$beginPosition !== false
	&& $paymentRemovalPosition !== false
	&& $stockReversalPosition !== false
	&& $cancelPosition !== false
	&& $commitPosition !== false
	&& $beginPosition < $paymentRemovalPosition
	&& $paymentRemovalPosition < $stockReversalPosition
	&& $stockReversalPosition < $cancelPosition
	&& $cancelPosition < $commitPosition,
	'customer cancellation transaction reopens/removes payments before stock reversal and setCanceled'
);

if (!empty($failures)) {
	echo count($failures) . ' paid invoice cancellation regression test(s) failed.' . PHP_EOL;
	exit(1);
}

echo 'All paid invoice cancellation regression tests passed.' . PHP_EOL;

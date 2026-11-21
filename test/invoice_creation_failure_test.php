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

require_once dirname(__DIR__) . '/class/trait/ZSInvoiceCreation.trait.php';

class Facture
{
	const TYPE_STANDARD = 0;
	const TYPE_REPLACEMENT = 1;
	const TYPE_CREDIT_NOTE = 2;
	const TYPE_DEPOSIT = 3;
}

class InvoiceCreationFailureDatabase
{
	public $error = '';

	public function lasterror()
	{
		return $this->error;
	}
}

class InvoiceCreationFailureNativeInvoice
{
	public $error = '';
	public $errors = array();
	public $nextError = '';
	public $nextErrors = array();
	public $result = -1;
	public $ref = '(PROV42)';
	public $socid;
	public $date;
	public $type;
	public $ref_client;
	public $note_public;
	public $note_private;
	public $array_options;
	public $errorStateBeforeCreate;

	public function create($user)
	{
		$this->errorStateBeforeCreate = array($this->error, $this->errors);
		$this->error = $this->nextError;
		$this->errors = $this->nextErrors;
		return $this->result;
	}
}

class InvoiceCreationFailureHarness
{
	use ZSInvoiceCreation;

	public $db;
	public $error = '';
	public $errors = array();
	public $loggedFailure;

	public function __construct()
	{
		$this->db = new InvoiceCreationFailureDatabase();
	}

	public function attempt($invoice)
	{
		return $this->createInvoice($invoice, array(
			'ref_client' => 'FT_TEST_1_20261119',
			'socid' => 1,
			'date' => 1795046400,
			'doc_type' => 'FT',
			'total_ht' => '10.00',
			'array_options' => array('options_invoice_number' => 'FT_TEST_1_20261119'),
		));
	}

	private function normalizeFactureCreateHeader($invoice, array $header)
	{
	}

	public function logDebug($operation, $message, array $context)
	{
	}

	public function logOperationError($operation, $message, array $context)
	{
		$this->loggedFailure = $context;
	}
}

$user = new stdClass();
$conf = (object) array('global' => new stdClass());
$checks = 0;
$assertSame = static function ($expected, $actual, $message) use (&$checks) {
	$checks++;
	if ($expected !== $actual) {
		fwrite(STDERR, 'FAIL: ' . $message . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true) . "\n");
		exit(1);
	}
};

$harness = new InvoiceCreationFailureHarness();
$invoice = new InvoiceCreationFailureNativeInvoice();
$invoice->nextErrors = array('Lock wait timeout exceeded; try restarting transaction');
$assertSame(false, $harness->attempt($invoice), 'native negative result fails');
$assertSame('Lock wait timeout exceeded; try restarting transaction', $harness->error, 'native error array survives on sync object');
$assertSame($harness->error, $harness->loggedFailure['error'], 'log and propagated failure agree');

$invoice->nextErrors = array();
$invoice->nextError = 'Invoice trigger failed';
$assertSame(false, $harness->attempt($invoice), 'native scalar error fails');
$assertSame('Invoice trigger failed', $harness->error, 'scalar failure replaces the previous attempt');
$assertSame(array('', array()), $invoice->errorStateBeforeCreate, 'native stale error state is cleared before each attempt');

$invoice->nextErrors = array('Repeated error', '', 'Repeated error', '  Second error  ');
$invoice->nextError = 'Repeated error';
$harness->attempt($invoice);
$assertSame(array('Repeated error', 'Second error'), $harness->errors, 'native error representations are combined and deduplicated');

$invoice->nextErrors = array();
$invoice->nextError = '';
$harness->db->error = 'Deadlock found when trying to get lock';
$harness->attempt($invoice);
$assertSame('Deadlock found when trying to get lock', $harness->error, 'database error is preserved when native object has no details');

$harness->db->error = '';
$harness->attempt($invoice);
$assertSame('Native invoice creation failed with result -1', $harness->error, 'unexplained failure has an actionable nonempty fallback');

$invoice->result = 0;
$assertSame(false, $harness->attempt($invoice), 'zero result is not a created invoice');
$assertSame('Native invoice creation failed with result 0', $harness->error, 'zero result retains failure details');

$invoice->result = 42;
$assertSame(42, $harness->attempt($invoice), 'positive result returns the created invoice id');
$assertSame('', $harness->error, 'success clears the prior failure');
$assertSame(array(), $harness->errors, 'success clears prior native error arrays');

print 'OK: ' . $checks . " invoice creation failure checks\n";

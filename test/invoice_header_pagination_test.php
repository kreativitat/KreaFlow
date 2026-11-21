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

require_once dirname(__DIR__) . '/class/trait/ZSInvoiceFetching.trait.php';

class InvoiceHeaderPaginationFakeApi
{
	public $payloads = array();
	public $operations = array();

	public function setInterface($interface)
	{
		return $interface === 'documents';
	}

	public function executeForStore($operation, array $payload, $store)
	{
		$this->payloads[] = $payload;
		$this->operations[] = $operation;
		$offset = (int) $payload['document']['offset'];
		$pages = array(
			0 => array(
				(object) array('doc' => 'FT', 'serie' => 'A', 'numero' => 3, 'data' => '2026-08-17', 'datahora' => '2026-08-17 12:03:00', 'loja' => 18),
				(object) array('doc' => 'FT', 'serie' => 'A', 'numero' => 2, 'data' => '2026-08-17', 'datahora' => '2026-08-17 12:02:00', 'loja' => 18),
			),
			2 => array(
				(object) array('doc' => 'FT', 'serie' => 'A', 'numero' => 2, 'data' => '2026-08-17', 'datahora' => '2026-08-17 12:02:00', 'loja' => 18),
				(object) array('doc' => 'FT', 'serie' => 'A', 'numero' => 1, 'data' => '2026-08-17', 'datahora' => '2026-08-17 12:01:00', 'loja' => 18),
			),
			4 => array(),
		);

		return array(
			'Response' => array(
				'StatusCode' => empty($pages[$offset]) ? 204 : 200,
				'Content' => array('document' => $pages[$offset]),
			),
		);
	}
}

class InvoiceHeaderPaginationHarness
{
	use ZSInvoiceFetching;

	public $zoneSoftApi;

	public function __construct()
	{
		$this->zoneSoftApi = new InvoiceHeaderPaginationFakeApi();
	}

	public function order()
	{
		return $this->getZoneSoftInvoiceHeaderOrder();
	}

	public function filterByStore(array $headers, $storeId)
	{
		return $this->filterInvoiceHeadersByStore($headers, $storeId, __METHOD__);
	}

	public function filterExcludedZsbmsDocumentHeaders(array $headers, $operation)
	{
		return $headers;
	}

	public function isExcludedZsbmsDocumentType($doc)
	{
		return false;
	}

	public function logDebug($operation, $message, array $context = array())
	{
	}

	public function logOperationError($operation, $message, array $context = array())
	{
	}

	public function logOperationWarning($operation, $message, array $context = array())
	{
	}
}

class InvoiceImportWindowHarness
{
	use ZSInvoiceFetching;

	public function getSynchronizationTimezone()
	{
		return new DateTimeZone('Europe/Lisbon');
	}

	public function fetchInvoiceHeaders($store = null, $offset = 0, $paginateFully = false)
	{
		return array('recent_page', $store, $offset, $paginateFully);
	}

	public function fetchInvoiceHeadersWithinDateRange($start, $end, $store = null)
	{
		return array('date_window', $start, $end, $store);
	}

	public function logOperationError($operation, $message, array $context = array())
	{
	}
}

$failures = array();
$assertSame = static function ($expected, $actual, $label) use (&$failures) {
	if ($expected !== $actual) {
		$failures[] = $label;
		fwrite(STDERR, '[FAIL] ' . $label . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . "\n");
		return;
	}

	echo '[PASS] ' . $label . "\n";
};

$conf = new stdClass();
$conf->global = new stdClass();
$conf->global->ZS_API_STORE = 18;
$conf->global->ZS_GET_NUMBER_OF_INVOICES_LIMIT = 2;
$conf->global->ZS_GET_NUMBER_OF_INVOICES_MAX_PAGES = 10;

$harness = new InvoiceHeaderPaginationHarness();
$expectedOrder = 'datahora DESC, doc DESC, serie DESC, numero DESC';
$assertSame($expectedOrder, $harness->order(), 'header order has deterministic timestamp and fiscal tie-breakers');

$result = $harness->fetchInvoiceHeaders(18, 0, true);
$keys = array_map(static function ($header) {
	return $header->doc . '|' . $header->serie . '|' . $header->numero . '|' . $header->data;
}, $result->document);

$assertSame(array('FT|A|1|2026-08-17', 'FT|A|2|2026-08-17', 'FT|A|3|2026-08-17'), $keys, 'overlapping pages are deduplicated and returned oldest first');

$documentsResult = $harness->fetchInvoiceDocumentsWithinDateRange('2026-08-17', '2026-08-17', 18);
$documentKeys = array_map(static function ($document) {
	return $document->doc . '|' . $document->serie . '|' . $document->numero . '|' . $document->data;
}, $documentsResult->document);
$assertSame(array('FT|A|1|2026-08-17', 'FT|A|2|2026-08-17', 'FT|A|3|2026-08-17'), $documentKeys, 'complete documents are paginated, deduplicated, and returned oldest first');
$assertSame(array('getDocumentsHeaders', 'getDocumentsHeaders', 'getDocumentsHeaders', 'getInstances', 'getInstances', 'getInstances'), $harness->zoneSoftApi->operations, 'header and full-document pagination use their correct API operations');
$scopedHeaders = $harness->filterByStore(array(
	(object) array('loja' => 18, 'numero' => 1),
	(object) array('loja' => 2, 'numero' => 2),
	(object) array('numero' => 3),
), 18);
$assertSame(array(1), array_map(static function ($header) {
	return $header->numero;
}, $scopedHeaders), 'foreign and unscoped headers are rejected');
$assertSame(false, $harness->fetchInvoiceHeaders(2, 0, false), 'explicit foreign-store fetch is rejected');
foreach ($harness->zoneSoftApi->payloads as $index => $payload) {
	$assertSame($expectedOrder, $payload['document']['order'], 'page ' . ($index + 1) . ' uses deterministic header order');
}

$window = new InvoiceImportWindowHarness();
$conf->global->ZS_INVOICE_IMPORT_LOOKBACK_DAYS = '0';
$conf->global->ZS_INVOICE_IMPORT_PAGINATE_FULLY = 0;
$assertSame(array('recent_page', null, 0, false), $window->fetchInvoiceHeadersForImport(), 'unset overlap retains legacy recent-page import');
$conf->global->ZS_INVOICE_IMPORT_PAGINATE_FULLY = 1;
$assertSame(array('recent_page', null, 0, true), $window->fetchInvoiceHeadersForImport(), 'legacy full-pagination option is preserved');
$conf->global->ZS_INVOICE_IMPORT_LOOKBACK_DAYS = '1';
$today = new DateTimeImmutable('today', new DateTimeZone('Europe/Lisbon'));
$assertSame(array('date_window', $today->modify('-1 day')->format('Y-m-d'), $today->format('Y-m-d'), null),
	$window->fetchInvoiceHeadersForImport(), 'overlap reads all yesterday and today headers instead of only the newest page');
$conf->global->ZS_INVOICE_IMPORT_LOOKBACK_DAYS = '31';
$assertSame(array('date_window', $today->modify('-31 days')->format('Y-m-d'), $today->format('Y-m-d'), null),
	$window->fetchInvoiceHeadersForImport(), 'maximum configured overlap remains bounded');
foreach (array('-1', '32', '1.5', 'invalid') as $invalidLookback) {
	$conf->global->ZS_INVOICE_IMPORT_LOOKBACK_DAYS = $invalidLookback;
	$assertSame(false, $window->fetchInvoiceHeadersForImport(), 'invalid overlap fails explicitly: ' . $invalidLookback);
}

if ($failures) {
	echo count($failures) . " invoice header pagination test(s) failed.\n";
	exit(1);
}

echo "All invoice header pagination tests passed.\n";

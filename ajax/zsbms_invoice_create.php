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

// Ajax endpoint to create an invoice in ZSBMS from a Dolibarr invoice

if (!defined('NOTOKENRENEWAL')) {
	define('NOTOKENRENEWAL', 1);
}
if (!defined('NOREQUIREMENU')) {
	define('NOREQUIREMENU', '1');
}
if (!defined('NOREQUIREHTML')) {
	define('NOREQUIREHTML', '1');
}
if (!defined('NOREQUIREAJAX')) {
	define('NOREQUIREAJAX', '1');
}
if (!defined('NOREQUIRESOC')) {
	define('NOREQUIRESOC', '1');
}
require '../../../main.inc.php';
require_once DOL_DOCUMENT_ROOT . '/compta/facture/class/facture.class.php';
require_once DOL_DOCUMENT_ROOT . '/custom/kreaflow/class/zsinvoicesynch.class.php';

top_httphead('application/json');

$dzs_response_sent = false;

/**
 * Send JSON response and exit.
 *
 * @param array $payload
 * @param int   $httpcode
 * @return void
 */
function dzs_send_json($payload, $httpcode = 200)
{
	global $dzs_response_sent;
	$dzs_response_sent = true;
	while (ob_get_level()) {
		ob_end_clean();
	}
	http_response_code($httpcode);
	header('Content-Type: application/json');
	echo json_encode($payload);
	exit;
}

register_shutdown_function(function () use (&$dzs_response_sent) {
	if ($dzs_response_sent) {
		return;
	}
	$error = error_get_last();
	if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
		while (ob_get_level()) {
			ob_end_clean();
		}
		http_response_code(500);
		header('Content-Type: application/json');
		echo json_encode(['success' => false, 'error' => 'Fatal error: ' . $error['message']]);
	}
});

if (empty($user->id)) {
	dzs_send_json(['success' => false, 'error' => 'Access denied.'], 401);
}
if (!isModEnabled('dolizsynch') || !$user->hasRight('dolizsynch', 'zsbmsinvoicecreator', 'read') || empty($user->rights->facture->creer)) {
	dzs_send_json(['success' => false, 'error' => 'Insufficient permissions.'], 403);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
	dzs_send_json(['success' => false, 'error' => 'Method not allowed.'], 405);
}
$token = (string) GETPOST('token', 'alphanohtml');
if ($token === '' || empty($_SESSION['newtoken']) || !hash_equals((string) $_SESSION['newtoken'], $token)) {
	dzs_send_json(['success' => false, 'error' => 'Invalid CSRF token.'], 403);
}

$invoiceId = GETPOSTINT('invoice_id');
if ($invoiceId <= 0) {
	dzs_send_json(['success' => false, 'error' => 'Invalid invoice id.'], 400);
}
$invoice = new Facture($db);
if ($invoice->fetch($invoiceId) <= 0) {
	dzs_send_json(['success' => false, 'error' => 'Invoice not found.'], 404);
}
$allowedEntities = array_map('intval', explode(',', (string) getEntity('invoice')));
if (!in_array((int) $invoice->entity, $allowedEntities, true)) {
	dzs_send_json(['success' => false, 'error' => 'Invoice is outside the active entity scope.'], 403);
}

$docType = strtoupper(trim((string) GETPOST('doc_type', 'aZ09')));
if ($docType === '') {
	$docType = 'FT';
}

$store = GETPOSTINT('store');
if ($store <= 0) {
	$store = (int) ($conf->global->ZS_API_STORE ?? 0);
}

$clientId = GETPOSTINT('client_id');
$supplierId = GETPOSTINT('supplier_id');
$paymentType = GETPOSTINT('payment_type');
if ($paymentType <= 0) {
	$paymentType = (int) ($conf->global->ZS_DEFAULT_PAYMENT_TYPE ?? 1);
}

$rawIvaIncluido = GETPOST('iva_incluido', 'alpha');
if ($rawIvaIncluido !== '') {
	$ivaIncluido = ((int) $rawIvaIncluido) > 0 ? 1 : 0;
} else {
	$ivaIncluido = !empty($conf->global->ZS_IVA_INCLUIDO) ? 1 : 0;
}

$options = [
	'doc' => $docType,
	'store' => $store,
	'client_id' => $clientId,
	'supplier_id' => $supplierId,
	'payment_type' => $paymentType,
	'iva_incluido' => $ivaIncluido,
];

$emp = GETPOST('emp', 'alpha');
if ($emp !== '') {
	$options['emp'] = (int) $emp;
}

$fallbackProductCode = GETPOSTINT('fallback_product_code');
if ($fallbackProductCode > 0) {
	$options['fallback_product_code'] = $fallbackProductCode;
}

try {
	$zsInvoiceSync = new ZSInvoiceSynch($db);
	$result = $zsInvoiceSync->createZsbmsInvoiceFromDolibarr($invoiceId, $options);

	if (!empty($result['success'])) {
		dzs_send_json([
			'success' => true,
			'invoice_id' => $invoiceId,
			'document_ref' => $result['document_ref'] ?? '',
			'status_code' => $result['status_code'] ?? 0,
			'status_message' => $result['status_message'] ?? '',
			'interface' => $result['interface'] ?? '',
			'response' => $result['response'] ?? null,
		], 200);
	}

	dzs_send_json([
		'success' => false,
		'invoice_id' => $invoiceId,
		'error' => $result['error'] ?? 'Failed to create ZSBMS invoice.',
		'status_code' => $result['status_code'] ?? 0,
		'status_message' => $result['status_message'] ?? '',
		'response' => $result['response'] ?? null,
	], 400);
} catch (Exception $e) {
	dzs_send_json(['success' => false, 'error' => 'Error creating ZSBMS invoice: ' . $e->getMessage()], 500);
}

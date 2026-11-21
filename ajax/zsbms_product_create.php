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

// Ajax endpoint to create a product instance in ZSBMS (ZoneSoft)

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
require_once DOL_DOCUMENT_ROOT . '/product/class/product.class.php';
require_once DOL_DOCUMENT_ROOT . '/custom/kreaflow/class/zsprodsynch.class.php';

top_httphead('application/json');

$dzs_response_sent = false;

/**
 * Send JSON response and exit
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

// Fallback for fatal errors to avoid empty responses
register_shutdown_function(function () use (&$dzs_response_sent) {
	if ($dzs_response_sent) {
		return;
	}
	$error = error_get_last();
	if ($error && in_array($error['type'], array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR))) {
		while (ob_get_level()) {
			ob_end_clean();
		}
		http_response_code(500);
		header('Content-Type: application/json');
		echo json_encode(array('success' => false, 'error' => 'Erro fatal: ' . $error['message']));
	}
});

// Basic security
if (empty($user->id)) {
	dzs_send_json(array('success' => false, 'error' => 'Acesso negado.'), 401);
}
if (!isModEnabled('dolizsynch') || (empty($user->rights->produit->creer) && empty($user->rights->service->creer))) {
	dzs_send_json(array('success' => false, 'error' => 'Insufficient permissions.'), 403);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
	dzs_send_json(array('success' => false, 'error' => 'Method not allowed.'), 405);
}
$token = (string) GETPOST('token', 'alphanohtml');
if ($token === '' || empty($_SESSION['newtoken']) || !hash_equals((string) $_SESSION['newtoken'], $token)) {
	dzs_send_json(array('success' => false, 'error' => 'Invalid CSRF token.'), 403);
}

$productId = GETPOSTINT('product_id');
if (empty($productId)) {
	dzs_send_json(array('success' => false, 'error' => 'ID do produto invalido.'), 400);
}

// Resolve store
$store = GETPOSTINT('store');
if (empty($store)) {
	$store = !empty($conf->global->ZS_API_STORE) ? (int) $conf->global->ZS_API_STORE : 0;
}
if (empty($store)) {
	dzs_send_json(array('success' => false, 'error' => 'Loja ZSBMS nao configurada.'), 400);
}

$numberingMethod = GETPOST('numbering_method', 'aZ09');
if ($numberingMethod !== 'automatic' && $numberingMethod !== 'manual') {
	$numberingMethod = 'manual';
}

// Load product
$product = new Product($db);
if ($product->fetch($productId) <= 0) {
	dzs_send_json(array('success' => false, 'error' => 'Produto nao encontrado.'), 404);
}
$allowedEntities = array_map('intval', explode(',', (string) getEntity('product')));
if (!in_array((int) $product->entity, $allowedEntities, true)) {
	dzs_send_json(array('success' => false, 'error' => 'Product is outside the active entity scope.'), 403);
}

$ref = (string) ($product->ref ?? '');
$refNumeric = ($ref !== '' && ctype_digit($ref));

if ($numberingMethod === 'manual' && !$refNumeric) {
	dzs_send_json(array('success' => false, 'error' => 'Referencia do produto nao e numerica. Use numeracao automatica.'), 400);
}

try {
	$zsSync = new ZSProductSynch($db);
	$useAutomaticNumbering = ($numberingMethod === 'automatic');

	$ok = $zsSync->createZoneSoftProductInteractive($product, $store, $useAutomaticNumbering);
	if (!$ok) {
		$errorMsg = !empty($zsSync->error) ? $zsSync->error : 'Falha ao criar produto no ZSBMS.';
		dzs_send_json(array('success' => false, 'error' => $errorMsg), 500);
	}

	dzs_send_json(array(
		'success' => true,
		'store' => $store,
		'new_ref' => (string) ($product->ref ?? ''),
		'message' => 'Produto criado no ZSBMS.',
	), 200);
} catch (Exception $e) {
	dzs_send_json(array('success' => false, 'error' => 'Erro ao criar produto: ' . $e->getMessage()), 500);
}

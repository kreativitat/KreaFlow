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
 * \file    dolizsynch/zsbms_invoice_creator.php
 * \ingroup dolizsynch
 * \brief   Manual UI to create ZSBMS invoices and invoice lines.
 */

// Load Dolibarr environment
$res = 0;
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) {
	$res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"] . "/main.inc.php";
}
$tmp = empty($_SERVER['SCRIPT_FILENAME']) ? '' : $_SERVER['SCRIPT_FILENAME'];
$tmp2 = realpath(__FILE__);
$i = strlen($tmp) - 1;
$j = strlen($tmp2) - 1;
while ($i > 0 && $j > 0 && isset($tmp[$i]) && isset($tmp2[$j]) && $tmp[$i] == $tmp2[$j]) {
	$i--;
	$j--;
}
if (!$res && $i > 0 && file_exists(substr($tmp, 0, ($i + 1)) . "/main.inc.php")) {
	$res = @include substr($tmp, 0, ($i + 1)) . "/main.inc.php";
}
if (!$res && $i > 0 && file_exists(dirname(substr($tmp, 0, ($i + 1))) . "/main.inc.php")) {
	$res = @include dirname(substr($tmp, 0, ($i + 1))) . "/main.inc.php";
}
if (!$res && file_exists("../../main.inc.php")) {
	$res = @include "../../main.inc.php";
}
if (!$res && file_exists("../../../main.inc.php")) {
	$res = @include "../../../main.inc.php";
}
if (!$res) {
	die("Include of main fails");
}

global $db, $langs, $user, $conf;

require_once DOL_DOCUMENT_ROOT . "/custom/kreaflow/class/zsinvoicesynch.class.php";
require_once DOL_DOCUMENT_ROOT . "/compta/facture/class/facture.class.php";
require_once DOL_DOCUMENT_ROOT . "/societe/class/societe.class.php";
require_once DOL_DOCUMENT_ROOT . "/core/class/html.form.class.php";

$langs->loadLangs(array("bills", "dolizsynch@kreaflow"));

if (
	empty($user->id)
	|| !$user->hasRight('dolizsynch', 'zsbmsinvoicecreator', 'read')
) {
	accessforbidden();
}

if (empty($conf->global->DOLIZSYNCH_ZSBMS_INVOICE_CREATOR_ENABLED)) {
	accessforbidden();
}

/**
 * Escape values for HTML attributes.
 *
 * @param mixed $value
 * @return string
 */
function dzs_h($value)
{
	return dol_escape_htmltag((string) $value);
}

/**
 * Return line value from a posted array safely.
 *
 * @param array $arr
 * @param int   $idx
 * @return string
 */
function dzs_line_val(array $arr, $idx)
{
	return isset($arr[$idx]) ? (string) $arr[$idx] : '';
}

/**
 * Format a decimal result for form fields and ZSBMS payload values.
 *
 * @param float $value
 * @param int   $maxDecimals
 * @param int   $minDecimals
 * @return string
 */
function dzs_format_decimal_result($value, $maxDecimals, $minDecimals = 0)
{
	$maxDecimals = max(0, min(9, (int) $maxDecimals));
	$minDecimals = max(0, min($maxDecimals, (int) $minDecimals));
	$value = (float) $value;
	if (!is_finite($value)) {
		$value = 0.0;
	}

	$text = number_format($value, $maxDecimals, '.', '');
	if ($maxDecimals <= 0) {
		return $text;
	}

	while (strlen($text) > 1 && substr($text, -1) === '0') {
		$decimalPos = strpos($text, '.');
		if ($decimalPos === false || (strlen($text) - $decimalPos - 1) <= $minDecimals) {
			break;
		}
		$text = substr($text, 0, -1);
	}
	if (substr($text, -1) === '.') {
		$text = substr($text, 0, -1);
	}

	return $text;
}

/**
 * Evaluate a restricted decimal arithmetic expression.
 *
 * Supports only numbers, +, -, *, / and parentheses. This intentionally avoids eval().
 *
 * @param string $raw
 * @param bool   $ok
 * @return float
 */
function dzs_evaluate_decimal_expression($raw, &$ok)
{
	$ok = false;
	$raw = trim((string) $raw);
	if ($raw === '') {
		return 0.0;
	}

	$tokens = array();
	$offset = 0;
	$length = strlen($raw);
	while ($offset < $length) {
		if (!preg_match('/\G\s*([+\-*\/()]|(?:\d+(?:[.,]\d*)?|[.,]\d+))\s*/', $raw, $matches, 0, $offset)) {
			return 0.0;
		}
		$token = $matches[1];
		if (preg_match('/^[\d.,]+$/', $token)) {
			$token = str_replace(',', '.', $token);
			if (!is_numeric($token)) {
				return 0.0;
			}
		}
		$tokens[] = $token;
		$offset += strlen($matches[0]);
	}

	if (empty($tokens)) {
		return 0.0;
	}

	$pos = 0;
	$count = count($tokens);
	$parseExpression = null;
	$parseTerm = null;
	$parseFactor = null;

	$parseFactor = function () use (&$tokens, &$pos, &$count, &$parseExpression, &$parseFactor) {
		if ($pos >= $count) {
			return null;
		}

		$token = $tokens[$pos];
		if ($token === '+') {
			$pos++;
			return $parseFactor();
		}
		if ($token === '-') {
			$pos++;
			$value = $parseFactor();
			return ($value === null) ? null : (-1 * $value);
		}
		if ($token === '(') {
			$pos++;
			$value = $parseExpression();
			if ($value === null || $pos >= $count || $tokens[$pos] !== ')') {
				return null;
			}
			$pos++;
			return $value;
		}
		if (is_numeric($token)) {
			$pos++;
			return (float) $token;
		}

		return null;
	};

	$parseTerm = function () use (&$tokens, &$pos, &$count, &$parseFactor) {
		$value = $parseFactor();
		if ($value === null) {
			return null;
		}

		while ($pos < $count && ($tokens[$pos] === '*' || $tokens[$pos] === '/')) {
			$operator = $tokens[$pos++];
			$right = $parseFactor();
			if ($right === null) {
				return null;
			}
			if ($operator === '*') {
				$value *= $right;
			} else {
				if (abs($right) < 0.000000000001) {
					return null;
				}
				$value /= $right;
			}
		}

		return $value;
	};

	$parseExpression = function () use (&$tokens, &$pos, &$count, &$parseTerm) {
		$value = $parseTerm();
		if ($value === null) {
			return null;
		}

		while ($pos < $count && ($tokens[$pos] === '+' || $tokens[$pos] === '-')) {
			$operator = $tokens[$pos++];
			$right = $parseTerm();
			if ($right === null) {
				return null;
			}
			$value = ($operator === '+') ? ($value + $right) : ($value - $right);
		}

		return $value;
	};

	$result = $parseExpression();
	if ($result === null || $pos !== $count || !is_finite((float) $result)) {
		return 0.0;
	}

	$ok = true;
	return (float) $result;
}

/**
 * Normalize calculated unit price and quantity fields before validation and payload creation.
 *
 * @param array<int,string> $lineQtd
 * @param array<int,string> $linePunit
 * @param int               $unitPriceDecimals
 * @return array<int,string>
 */
function dzs_normalize_line_calculation_arrays(array &$lineQtd, array &$linePunit, $unitPriceDecimals)
{
	$errors = array();
	$maxRows = max(count($lineQtd), count($linePunit));
	for ($i = 0; $i < $maxRows; $i++) {
		foreach (array('qtd' => 6, 'punit' => max(2, (int) $unitPriceDecimals)) as $field => $decimals) {
			$currentValue = ($field === 'qtd') ? dzs_line_val($lineQtd, $i) : dzs_line_val($linePunit, $i);
			$currentValue = trim((string) $currentValue);
			if ($currentValue === '') {
				continue;
			}

			$ok = false;
			$result = dzs_evaluate_decimal_expression($currentValue, $ok);
			if (!$ok) {
				$errors[] = 'line ' . ($i + 1) . ' ' . (($field === 'qtd') ? 'quantity' : 'unit price');
				continue;
			}

			if ($field === 'qtd') {
				$lineQtd[$i] = dzs_format_decimal_result($result, $decimals, 0);
			} else {
				$linePunit[$i] = dzs_format_decimal_result($result, $decimals, 2);
			}
		}
	}

	return $errors;
}

/**
 * Translate with fallback when key does not exist.
 *
 * @param Translate $langs
 * @param string    $key
 * @param string    $fallback
 * @return string
 */
function dzs_t($langs, $key, $fallback = '')
{
	$translated = (string) $langs->trans($key);
	if ($fallback !== '' && ($translated === '' || $translated === $key)) {
		return $fallback;
	}

	return $translated;
}

/**
 * Store one-time created document data across the post-submit redirect.
 *
 * @param string $documentRef
 * @param string $pdfUrl
 * @return void
 */
function dzs_store_created_document_notice($documentRef, $pdfUrl)
{
	if (!isset($_SESSION) || !is_array($_SESSION)) {
		return;
	}

	$_SESSION['dolizsynch_zsbms_invoice_creator_last_created'] = array(
		'document_ref' => trim((string) $documentRef),
		'pdf_url' => trim((string) $pdfUrl),
	);
}

/**
 * Read and clear one-time created document data after redirect.
 *
 * @return array{document_ref:string,pdf_url:string}
 */
function dzs_pop_created_document_notice()
{
	$notice = array(
		'document_ref' => '',
		'pdf_url' => '',
	);

	if (!isset($_SESSION) || !is_array($_SESSION) || empty($_SESSION['dolizsynch_zsbms_invoice_creator_last_created'])) {
		return $notice;
	}

	$raw = $_SESSION['dolizsynch_zsbms_invoice_creator_last_created'];
	unset($_SESSION['dolizsynch_zsbms_invoice_creator_last_created']);

	if (is_array($raw)) {
		$notice['document_ref'] = trim((string) ($raw['document_ref'] ?? ''));
		$notice['pdf_url'] = trim((string) ($raw['pdf_url'] ?? ''));
	}

	return $notice;
}

/**
 * Build a clean GET URL after a successful manual creation.
 *
 * @param int    $originInvoiceId
 * @param string $backtopage
 * @return string
 */
function dzs_build_post_success_redirect_url($originInvoiceId, $backtopage)
{
	$params = array();
	$originInvoiceId = (int) $originInvoiceId;
	if ($originInvoiceId > 0) {
		$params['origin_invoice_id'] = $originInvoiceId;
	}

	$backtopage = trim((string) $backtopage);
	if ($backtopage !== '') {
		$params['backtopage'] = $backtopage;
	}

	$url = (string) ($_SERVER['PHP_SELF'] ?? 'zsbms_invoice_creator.php');
	if (!empty($params)) {
		$url .= '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
	}

	return $url;
}

/**
 * Create a local one-use submit key for the manual creator form.
 *
 * @return string
 */
function dzs_create_manual_submission_key()
{
	try {
		if (function_exists('random_bytes')) {
			return bin2hex(random_bytes(16));
		}
	} catch (Exception $e) {
		// Fall back below on runtimes without random_bytes support.
	}

	return sha1(uniqid('', true) . '|' . mt_rand());
}

/**
 * Normalize a manual submit key received from the browser.
 *
 * @param string $key
 * @return string
 */
function dzs_normalize_manual_submission_key($key)
{
	$key = strtolower((string) preg_replace('/[^a-f0-9]/i', '', (string) $key));
	$length = strlen($key);
	if ($length < 16 || $length > 64) {
		return '';
	}

	return $key;
}

/**
 * Drop stale manual creator submission states from the current session.
 *
 * @return void
 */
function dzs_cleanup_manual_submission_registry()
{
	if (!isset($_SESSION) || !is_array($_SESSION)) {
		return;
	}

	$sessionKey = 'dolizsynch_zsbms_invoice_creator_submissions';
	if (empty($_SESSION[$sessionKey]) || !is_array($_SESSION[$sessionKey])) {
		$_SESSION[$sessionKey] = array();
		return;
	}

	$now = time();
	foreach ($_SESSION[$sessionKey] as $key => $state) {
		if (!is_array($state)) {
			unset($_SESSION[$sessionKey][$key]);
			continue;
		}

		$status = (string) ($state['status'] ?? '');
		$createdAt = (int) ($state['created_at'] ?? 0);
		$ttl = ($status === 'processing') ? 1800 : 86400;
		if ($createdAt <= 0 || ($now - $createdAt) > $ttl) {
			unset($_SESSION[$sessionKey][$key]);
		}
	}
}

/**
 * Return the current form submit key, creating one when needed.
 *
 * @return string
 */
function dzs_get_manual_submission_key()
{
	if (!isset($_SESSION) || !is_array($_SESSION)) {
		return dzs_create_manual_submission_key();
	}

	dzs_cleanup_manual_submission_registry();
	$sessionKey = 'dolizsynch_zsbms_invoice_creator_current_submit_key';
	$currentKey = dzs_normalize_manual_submission_key((string) ($_SESSION[$sessionKey] ?? ''));
	if ($currentKey === '') {
		$currentKey = dzs_create_manual_submission_key();
		$_SESSION[$sessionKey] = $currentKey;
	}

	return $currentKey;
}

/**
 * Rotate the rendered form submit key after a successful create.
 *
 * @return void
 */
function dzs_rotate_manual_submission_key()
{
	if (!isset($_SESSION) || !is_array($_SESSION)) {
		return;
	}

	$_SESSION['dolizsynch_zsbms_invoice_creator_current_submit_key'] = dzs_create_manual_submission_key();
}

/**
 * Normalize data recursively for a stable submission fingerprint.
 *
 * @param mixed $value
 * @return mixed
 */
function dzs_normalize_manual_submission_fingerprint_value($value)
{
	if (is_object($value)) {
		$value = get_object_vars($value);
	}

	if (!is_array($value)) {
		return $value;
	}

	$normalized = array();
	foreach ($value as $key => $item) {
		$normalized[$key] = dzs_normalize_manual_submission_fingerprint_value($item);
	}

	if (!dzs_is_sequential_array($normalized)) {
		ksort($normalized, SORT_STRING);
	}

	return $normalized;
}

/**
 * Build a stable fingerprint for a submitted manual creator payload.
 *
 * @param array $payload
 * @param int   $entity
 * @param int   $userId
 * @return string
 */
function dzs_build_manual_submission_fingerprint(array $payload, $entity, $userId)
{
	$fingerprintPayload = array(
		'entity' => (int) $entity,
		'user_id' => (int) $userId,
		'payload' => dzs_normalize_manual_submission_fingerprint_value($payload),
	);

	$json = json_encode($fingerprintPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	if ($json === false) {
		$json = serialize($fingerprintPayload);
	}

	return hash('sha256', (string) $json);
}

/**
 * Reserve a manual creator submit key before remote ZSBMS side effects.
 *
 * @param string $key
 * @param string $fingerprint
 * @return array<string,mixed>
 */
function dzs_reserve_manual_submission_key($key, $fingerprint)
{
	$key = dzs_normalize_manual_submission_key($key);
	$fingerprint = trim((string) $fingerprint);
	if (!isset($_SESSION) || !is_array($_SESSION) || $key === '' || $fingerprint === '') {
		return array('status' => 'reserved');
	}

	dzs_cleanup_manual_submission_registry();
	$sessionKey = 'dolizsynch_zsbms_invoice_creator_submissions';
	if (empty($_SESSION[$sessionKey]) || !is_array($_SESSION[$sessionKey])) {
		$_SESSION[$sessionKey] = array();
	}

	$existing = isset($_SESSION[$sessionKey][$key]) && is_array($_SESSION[$sessionKey][$key]) ? $_SESSION[$sessionKey][$key] : array();
	$existingStatus = (string) ($existing['status'] ?? '');
	$existingFingerprint = (string) ($existing['fingerprint'] ?? '');
	if ($existingStatus === 'success') {
		$existing['status'] = ($existingFingerprint === $fingerprint) ? 'success' : 'used';
		return $existing;
	}
	if ($existingStatus === 'processing') {
		return array(
			'status' => ($existingFingerprint === $fingerprint) ? 'processing' : 'used',
		);
	}

	$_SESSION[$sessionKey][$key] = array(
		'status' => 'processing',
		'fingerprint' => $fingerprint,
		'created_at' => time(),
		'updated_at' => time(),
	);

	return array('status' => 'reserved');
}

/**
 * Release a processing submit key after a failed create attempt.
 *
 * @param string $key
 * @param string $fingerprint
 * @return void
 */
function dzs_release_manual_submission_key($key, $fingerprint)
{
	$key = dzs_normalize_manual_submission_key($key);
	$fingerprint = trim((string) $fingerprint);
	if (!isset($_SESSION) || !is_array($_SESSION) || $key === '' || $fingerprint === '') {
		return;
	}

	$sessionKey = 'dolizsynch_zsbms_invoice_creator_submissions';
	if (empty($_SESSION[$sessionKey][$key]) || !is_array($_SESSION[$sessionKey][$key])) {
		return;
	}

	$state = $_SESSION[$sessionKey][$key];
	if ((string) ($state['status'] ?? '') === 'processing' && (string) ($state['fingerprint'] ?? '') === $fingerprint) {
		unset($_SESSION[$sessionKey][$key]);
	}
}

/**
 * Mark a submit key as successfully processed.
 *
 * @param string $key
 * @param string $fingerprint
 * @param string $documentRef
 * @param string $pdfUrl
 * @return void
 */
function dzs_mark_manual_submission_success($key, $fingerprint, $documentRef, $pdfUrl)
{
	$key = dzs_normalize_manual_submission_key($key);
	$fingerprint = trim((string) $fingerprint);
	if (!isset($_SESSION) || !is_array($_SESSION) || $key === '' || $fingerprint === '') {
		return;
	}

	$sessionKey = 'dolizsynch_zsbms_invoice_creator_submissions';
	if (empty($_SESSION[$sessionKey]) || !is_array($_SESSION[$sessionKey])) {
		$_SESSION[$sessionKey] = array();
	}

	$_SESSION[$sessionKey][$key] = array(
		'status' => 'success',
		'fingerprint' => $fingerprint,
		'document_ref' => trim((string) $documentRef),
		'pdf_url' => trim((string) $pdfUrl),
		'created_at' => time(),
		'updated_at' => time(),
	);
}


/**
 * Load available ZSBMS payment types from local dictionary table.
 *
 * @param DoliDB $db
 * @param int    $store
 * @param int    $entity
 * @return array<int,string>
 */
function dzs_load_payment_type_options($db, $store, $entity)
{
	$options = array();
	$entityIds = array((int) $entity, 0);
	if ((int) $entity !== 1) {
		$entityIds[] = 1;
	}
	$entityIds = array_values(array_unique(array_map('intval', $entityIds)));
	$entitySql = implode(',', $entityIds);

	$whereVariants = array();
	if ((int) $store > 0) {
		$whereVariants[] = " WHERE entity IN (" . $entitySql . ") AND loja_zs = " . ((int) $store);
	}
	$whereVariants[] = " WHERE entity IN (" . $entitySql . ")";
	$whereVariants[] = "";

	foreach ($whereVariants as $whereClause) {
		$sql = "SELECT paymenttype_id, paymenttype_name FROM " . MAIN_DB_PREFIX . "dolizsynch_zspaymenttype";
		$sql .= $whereClause;
		$sql .= " ORDER BY paymenttype_name ASC";

		$resql = $db->query($sql);
		if (!$resql) {
			continue;
		}

		while ($obj = $db->fetch_object($resql)) {
			$paymentTypeId = (int) ($obj->paymenttype_id ?? 0);
			if ($paymentTypeId <= 0 || isset($options[$paymentTypeId])) {
				continue;
			}

			$paymentTypeName = trim((string) ($obj->paymenttype_name ?? ''));
			if ($paymentTypeName === '') {
				$paymentTypeName = 'ID ' . $paymentTypeId;
			}
			$options[$paymentTypeId] = $paymentTypeName;
		}

		$db->free($resql);
		if (!empty($options)) {
			break;
		}
	}

	return $options;
}

/**
 * Normalize payment type labels to uppercase ASCII-like tokens.
 *
 * @param string $label
 * @return string
 */
function dzs_normalize_payment_type_label($label)
{
	$normalized = strtoupper(trim((string) $label));
	if ($normalized === '') {
		return '';
	}

	$normalized = strtr($normalized, array(
		'Á' => 'A', 'À' => 'A', 'Â' => 'A', 'Ã' => 'A', 'Ä' => 'A',
		'É' => 'E', 'È' => 'E', 'Ê' => 'E', 'Ë' => 'E',
		'Í' => 'I', 'Ì' => 'I', 'Î' => 'I', 'Ï' => 'I',
		'Ó' => 'O', 'Ò' => 'O', 'Ô' => 'O', 'Õ' => 'O', 'Ö' => 'O',
		'Ú' => 'U', 'Ù' => 'U', 'Û' => 'U', 'Ü' => 'U',
		'Ç' => 'C',
	));
	$normalized = preg_replace('/[^A-Z0-9]+/', ' ', $normalized);
	$normalized = preg_replace('/\s+/', ' ', (string) $normalized);

	return trim((string) $normalized);
}

/**
 * Tokenize product search query into lowercase terms.
 *
 * @param string $query
 * @return array<int,string>
 */
function dzs_tokenize_product_search_query($query)
{
	$raw = trim((string) $query);
	if ($raw === '') {
		return array();
	}

	$tokens = preg_split('/[^\p{L}\p{N}]+/u', $raw);
	if (!is_array($tokens)) {
		return array();
	}

	$out = array();
	foreach ($tokens as $token) {
		$token = trim((string) $token);
		if ($token === '') {
			continue;
		}
		if (function_exists('mb_strtolower')) {
			$token = mb_strtolower($token, 'UTF-8');
		} else {
			$token = strtolower($token);
		}
		$out[$token] = $token;
	}

	return array_values($out);
}

/**
 * Resolve default payment type, prioritizing transfer-like labels.
 *
 * @param array<int,string> $paymentTypeOptions
 * @param int               $fallbackId
 * @return int
 */
function dzs_resolve_default_payment_type(array $paymentTypeOptions, $fallbackId = 0)
{
	$fallbackId = (int) $fallbackId;
	foreach ($paymentTypeOptions as $paymentTypeId => $paymentTypeName) {
		$pid = (int) $paymentTypeId;
		if ($pid <= 0) {
			continue;
		}

		$normalizedName = dzs_normalize_payment_type_label($paymentTypeName);
		if ($normalizedName === '') {
			continue;
		}

		if (strpos($normalizedName, 'TRANSFER') !== false) {
			return $pid;
		}
		if (strpos($normalizedName, 'TRF') !== false && strpos($normalizedName, 'BANC') !== false) {
			return $pid;
		}
	}

	if ($fallbackId > 0 && isset($paymentTypeOptions[$fallbackId])) {
		return $fallbackId;
	}

	$paymentTypeKeys = array_keys($paymentTypeOptions);
	if (!empty($paymentTypeKeys)) {
		return (int) $paymentTypeKeys[0];
	}

	return ($fallbackId > 0 ? $fallbackId : 1);
}

/**
 * Check whether a DB table has a specific column.
 *
 * @param DoliDB $db
 * @param string $table
 * @param string $column
 * @return bool
 */
function dzs_table_has_column($db, $table, $column)
{
	static $cache = array();

	$key = $table . '|' . $column;
	if (isset($cache[$key])) {
		return $cache[$key];
	}

	$sql = "SHOW COLUMNS FROM " . $table . " LIKE '" . $db->escape($column) . "'";
	$resql = $db->query($sql);
	$hasColumn = ($resql && $db->num_rows($resql) > 0);
	if ($resql) {
		$db->free($resql);
	}

	$cache[$key] = $hasColumn;
	return $hasColumn;
}

/**
 * Resolve employee group ids configured for this entity (same rule used by schedule pages).
 *
 * @return array<int,int>
 */
function dzs_get_shift_employee_group_ids()
{
	$raw = trim((string) getDolGlobalString('DOLIZSYNCH_SHIFT_EMPLOYEE_GROUPS', ''));
	$ids = array();

	if ($raw !== '') {
		$parts = preg_split('/\s*,\s*/', $raw);
		foreach ($parts as $part) {
			$id = (int) $part;
			if ($id > 0) {
				$ids[$id] = $id;
			}
		}
	}

	if (empty($ids)) {
		$single = (int) getDolGlobalInt('DOLIZSYNCH_SHIFT_EMPLOYEE_GROUP', 0);
		if ($single > 0) {
			$ids[$single] = $single;
		}
	}

	return array_values($ids);
}

/**
 * Load employee options (ZSBMS employee id => label) from configured schedule usergroups.
 *
 * @param DoliDB $db
 * @param int    $entity
 * @return array<int,string>
 */
function dzs_load_store_employee_options($db, $entity)
{
	$groupIds = dzs_get_shift_employee_group_ids();
	if (empty($groupIds)) {
		return array();
	}

	$userExtrafieldsTable = MAIN_DB_PREFIX . 'user_extrafields';
	$hasExtrafieldsTable = dzs_table_has_column($db, $userExtrafieldsTable, 'fk_object');
	$hasZsEmployeeId = $hasExtrafieldsTable && dzs_table_has_column($db, $userExtrafieldsTable, 'zs_employee_id');
	$hasHideFlag = $hasExtrafieldsTable && dzs_table_has_column($db, $userExtrafieldsTable, 'zs_user_hide');
	if (!$hasZsEmployeeId) {
		return array();
	}

	$sql = "SELECT DISTINCT u.rowid, u.firstname, u.lastname, u.login";
	$sql .= ", COALESCE(ue.zs_employee_id, 0) AS zs_employee_id";
	if ($hasHideFlag) {
		$sql .= ", COALESCE(ue.zs_user_hide, 0) AS zs_user_hide";
	} else {
		$sql .= ", 0 AS zs_user_hide";
	}
	$sql .= " FROM " . MAIN_DB_PREFIX . "user AS u";
	$sql .= " INNER JOIN " . MAIN_DB_PREFIX . "usergroup_user AS ugu ON ugu.fk_user = u.rowid";
	$sql .= " AND ugu.fk_usergroup IN (" . implode(',', array_map('intval', $groupIds)) . ")";
	$sql .= " AND ugu.entity = " . ((int) $entity);
	$sql .= " LEFT JOIN " . $userExtrafieldsTable . " AS ue ON ue.fk_object = u.rowid";
	$sql .= " WHERE 1=1";
	$sql .= " AND u.statut = 1";
	if ($hasHideFlag) {
		$sql .= " AND COALESCE(ue.zs_user_hide, 0) = 0";
	}
	$sql .= " ORDER BY u.lastname, u.firstname, u.rowid";

	$resql = $db->query($sql);
	if (!$resql) {
		return array();
	}

	$options = array();
	while ($obj = $db->fetch_object($resql)) {
		if ((int) ($obj->zs_user_hide ?? 0) === 1) {
			continue;
		}

		$zsEmployeeId = (int) ($obj->zs_employee_id ?? 0);
		if ($zsEmployeeId <= 0 || isset($options[$zsEmployeeId])) {
			continue;
		}

		$label = trim((string) (($obj->firstname ?? '') . ' ' . ($obj->lastname ?? '')));
		if ($label === '') {
			$label = trim((string) ($obj->login ?? ''));
		}
		if ($label === '') {
			$label = 'User #' . ((int) ($obj->rowid ?? 0));
		}

		$options[$zsEmployeeId] = $label;
	}
	$db->free($resql);

	return $options;
}

/**
 * Check whether an array is a sequential list (0..n-1).
 *
 * @param array $array
 * @return bool
 */
function dzs_is_sequential_array(array $array)
{
	if ($array === array()) {
		return true;
	}

	return array_keys($array) === range(0, count($array) - 1);
}

/**
 * Normalize raw ZSAPI response into an object structure.
 *
 * @param mixed $rawResponse
 * @return object
 */
function dzs_zs_normalize_response($rawResponse)
{
	if (is_array($rawResponse) && empty($rawResponse)) {
		return (object) array(
			'Response' => (object) array(
				'StatusCode' => 204,
				'Content' => (object) array(),
			),
		);
	}

	$normalized = json_decode(json_encode($rawResponse));
	if (!is_object($normalized)) {
		$normalized = (object) array(
			'Response' => (object) array(
				'StatusCode' => 0,
				'StatusMessage' => 'Invalid response format',
			),
		);
	}

	return $normalized;
}

/**
 * Build a concise API error message from ZSAPI response.
 *
 * @param object $response
 * @param string $fallback
 * @return string
 */
function dzs_zs_response_error_text($response, $fallback = 'Unknown ZSBMS API error')
{
	$statusMessage = trim((string) ($response->Response->StatusMessage ?? ''));
	$errorMessage = trim((string) ($response->Response->ErrorMessage ?? ''));
	$statusCode = (int) ($response->Response->StatusCode ?? 0);

	if ($errorMessage !== '') {
		return $errorMessage;
	}
	if ($statusMessage !== '') {
		return $statusMessage;
	}
	if ($statusCode > 0) {
		return 'Unexpected status code: ' . $statusCode;
	}

	return (string) $fallback;
}

/**
 * Normalize a mixed/object API value into list of associative arrays.
 *
 * @param mixed $value
 * @return array<int,array<string,mixed>>
 */
function dzs_zs_normalize_instance_list($value)
{
	if (is_object($value)) {
		$value = get_object_vars($value);
	}
	if (!is_array($value)) {
		return array();
	}

	$items = dzs_is_sequential_array($value) ? $value : array($value);
	$list = array();
	foreach ($items as $item) {
		if (is_object($item)) {
			$item = get_object_vars($item);
		}
		if (is_array($item)) {
			$list[] = $item;
		}
	}

	return $list;
}

/**
 * Extract client instances from normalized ZSAPI response.
 *
 * @param object $response
 * @return array<int,array<string,mixed>>
 */
function dzs_zs_extract_clients_from_response($response)
{
	if (!isset($response->Response) || !isset($response->Response->Content)) {
		return array();
	}

	$content = $response->Response->Content;
	if (is_object($content)) {
		$content = get_object_vars($content);
	}
	if (!is_array($content)) {
		return array();
	}

	foreach (array('client', 'clients') as $key) {
		if (array_key_exists($key, $content)) {
			$instances = dzs_zs_normalize_instance_list($content[$key]);
			if (!empty($instances)) {
				return $instances;
			}
		}
	}

	foreach ($content as $candidate) {
		$instances = dzs_zs_normalize_instance_list($candidate);
		if (!empty($instances) && isset($instances[0]['codigo'])) {
			return $instances;
		}
	}

	return array();
}

/**
 * Quote a value for ZSAPI listing condition strings.
 *
 * @param string $value
 * @return string
 */
function dzs_zs_quote_condition_value($value)
{
	return str_replace("'", "''", trim((string) $value));
}

/**
 * Build VAT variants used to match client records in ZSBMS.
 *
 * @param string $vatRaw
 * @return array<int,string>
 */
function dzs_build_vat_candidates($vatRaw)
{
	$raw = trim((string) $vatRaw);
	if ($raw === '') {
		return array();
	}

	$upper = strtoupper($raw);
	$compact = preg_replace('/\s+/', '', $upper);
	$alnum = preg_replace('/[^A-Z0-9]/', '', $compact);

	$candidates = array();
	if (preg_match('/^[A-Z]{2}[0-9]+$/', $alnum)) {
		$candidates[] = substr($alnum, 2);
	}
	if ($alnum !== '') {
		$candidates[] = $alnum;
	}
	$digitsOnly = preg_replace('/[^0-9]/', '', $alnum);
	if ($digitsOnly !== '') {
		$candidates[] = $digitsOnly;
	}

	return array_values(array_unique($candidates));
}

/**
 * Execute a ZSAPI action using store-specific credentials when available.
 *
 * @param object $zoneSoftApi
 * @param string $action
 * @param array  $payload
 * @param int    $store
 * @return mixed
 */
function dzs_execute_zs_action($zoneSoftApi, $action, array $payload, $store = 0)
{
	$store = (int) $store;
	if ($store > 0 && method_exists($zoneSoftApi, 'executeForStore')) {
		return $zoneSoftApi->executeForStore($action, $payload, (string) $store);
	}

	return $zoneSoftApi->execute($action, $payload);
}

/**
 * Find a ZSBMS client code using a listing condition.
 *
 * @param object $zoneSoftApi
 * @param string $condition
 * @param int    $store
 * @return int
 */
function dzs_find_zsbms_client_code($zoneSoftApi, $condition, $store = 0)
{
	$condition = trim((string) $condition);
	if ($condition === '') {
		return 0;
	}

	$zoneSoftApi->setInterface('clients');
	$payload = array(
		'client' => array(
			'condition' => $condition,
			'limit' => 1,
		),
	);

	$rawResponse = dzs_execute_zs_action($zoneSoftApi, 'getInstances', $payload, (int) $store);
	$response = dzs_zs_normalize_response($rawResponse);
	$statusCode = (int) ($response->Response->StatusCode ?? 0);
	if ($statusCode === 204) {
		return 0;
	}
	if ($statusCode !== 200) {
		throw new Exception(dzs_zs_response_error_text($response, 'Unable to search client in ZSBMS'));
	}

	$clients = dzs_zs_extract_clients_from_response($response);
	if (empty($clients)) {
		return 0;
	}

	return (int) ($clients[0]['codigo'] ?? 0);
}

/**
 * Search ZSBMS clients by condition and return normalized client rows.
 *
 * @param object $zoneSoftApi
 * @param string $condition
 * @param int    $store
 * @param int    $limit
 * @return array<int,array<string,mixed>>
 */
function dzs_search_zsbms_clients($zoneSoftApi, $condition, $store = 0, $limit = 20)
{
	$condition = trim((string) $condition);
	if ($condition === '') {
		return array();
	}

	$limit = max(1, min(50, (int) $limit));
	$zoneSoftApi->setInterface('clients');
	$payload = array(
		'client' => array(
			'condition' => $condition,
			'limit' => $limit,
		),
	);

	$rawResponse = dzs_execute_zs_action($zoneSoftApi, 'getInstances', $payload, (int) $store);
	$response = dzs_zs_normalize_response($rawResponse);
	$statusCode = (int) ($response->Response->StatusCode ?? 0);
	if ($statusCode === 204) {
		return array();
	}
	if ($statusCode !== 200) {
		throw new Exception(dzs_zs_response_error_text($response, 'Unable to search client in ZSBMS'));
	}

	return dzs_zs_extract_clients_from_response($response);
}

/**
 * Resolve ZSBMS client by VAT candidates, tolerating formatted/prefixed VAT values.
 *
 * @param object            $zoneSoftApi
 * @param array<int,string> $vatCandidates
 * @param int               $store
 * @return int
 */
function dzs_find_zsbms_client_code_by_vat_candidates($zoneSoftApi, array $vatCandidates, $store = 0)
{
	$normalizedLookup = array();
	foreach ($vatCandidates as $candidate) {
		$candidateVariants = dzs_build_vat_candidates((string) $candidate);
		foreach ($candidateVariants as $variant) {
			$normalized = dzs_normalize_vat_lookup((string) $variant);
			if ($normalized !== '') {
				$normalizedLookup[$normalized] = $normalized;
			}
		}
	}

	if (empty($normalizedLookup)) {
		return 0;
	}

	$normalizedCandidates = array_values($normalizedLookup);

	// Fast exact lookup first.
	foreach ($normalizedCandidates as $vatCandidate) {
		$condition = "contribuinte = '" . dzs_zs_quote_condition_value($vatCandidate) . "'";
		$existingCode = dzs_find_zsbms_client_code($zoneSoftApi, $condition, (int) $store);
		if ($existingCode > 0) {
			return $existingCode;
		}
	}

	// Fallback to LIKE and post-filter with normalized VAT comparison.
	foreach ($normalizedCandidates as $vatCandidate) {
		$condition = "contribuinte LIKE '%" . dzs_zs_quote_condition_value($vatCandidate) . "%'";
		$clients = dzs_search_zsbms_clients($zoneSoftApi, $condition, (int) $store, 25);
		if (empty($clients)) {
			continue;
		}

		foreach ($clients as $client) {
			if (!is_array($client)) {
				continue;
			}

			$clientCode = (int) ($client['codigo'] ?? 0);
			if ($clientCode <= 0) {
				continue;
			}

			$clientVatRaw = '';
			foreach (array('contribuinte', 'nif', 'vat', 'taxid', 'tax_id') as $vatKey) {
				$vatValue = trim((string) ($client[$vatKey] ?? ''));
				if ($vatValue !== '') {
					$clientVatRaw = $vatValue;
					break;
				}
			}
			if ($clientVatRaw === '') {
				continue;
			}

			$clientVatVariants = dzs_build_vat_candidates($clientVatRaw);
			foreach ($clientVatVariants as $clientVatVariant) {
				$normalizedClientVat = dzs_normalize_vat_lookup((string) $clientVatVariant);
				if ($normalizedClientVat !== '' && isset($normalizedLookup[$normalizedClientVat])) {
					return $clientCode;
				}
			}
		}
	}

	return 0;
}

/**
 * Get next candidate ZSBMS client code.
 *
 * @param object $zoneSoftApi
 * @param int    $store
 * @return int
 */
function dzs_get_next_zsbms_client_code($zoneSoftApi, $store = 0)
{
	$zoneSoftApi->setInterface('clients');
	$payload = array(
		'client' => array(
			'order' => 'codigo;DESC',
			'limit' => 1,
		),
	);

	$rawResponse = dzs_execute_zs_action($zoneSoftApi, 'getInstances', $payload, (int) $store);
	$response = dzs_zs_normalize_response($rawResponse);
	$statusCode = (int) ($response->Response->StatusCode ?? 0);
	if ($statusCode === 204) {
		return 2;
	}
	if ($statusCode !== 200) {
		throw new Exception(dzs_zs_response_error_text($response, 'Unable to get next ZSBMS client code'));
	}

	$clients = dzs_zs_extract_clients_from_response($response);
	if (empty($clients)) {
		return 2;
	}

	$topCode = (int) ($clients[0]['codigo'] ?? 0);
	if ($topCode < 1) {
		$topCode = 1;
	}

	return max(2, $topCode + 1);
}

/**
 * Build a safe client payload for clients/saveInstances.
 *
 * @param Societe $thirdparty
 * @param int     $clientCode
 * @param string  $vatValue
 * @return array<string,mixed>
 */
function dzs_build_zsbms_client_payload(Societe $thirdparty, $clientCode, $vatValue = '')
{
	$name = trim((string) ($thirdparty->name ?? ''));
	if ($name === '') {
		$name = 'Cliente ' . ((int) $clientCode);
	}

	$zipRaw = trim((string) ($thirdparty->zip ?? ''));
	$zipDigits = preg_replace('/[^0-9]/', '', $zipRaw);
	$zip4 = (strlen($zipDigits) >= 4) ? substr($zipDigits, 0, 4) : '';
	$zip3 = (strlen($zipDigits) >= 7) ? substr($zipDigits, 4, 3) : '';

	$countryCode = strtoupper(trim((string) ($thirdparty->country_code ?? 'PT')));
	if ($countryCode === '') {
		$countryCode = 'PT';
	}

	$payload = array(
		'codigo' => (int) $clientCode,
		'nome' => substr($name, 0, 50),
		'datacriacao' => dol_print_date(dol_now(), '%Y-%m-%d %H:%M:%S'),
		'bloqueado' => 0,
		'morada' => substr(trim((string) ($thirdparty->address ?? '')), 0, 250),
		'codpostal' => $zip4,
		'codpostal1' => $zip3,
		'localidade' => substr(trim((string) ($thirdparty->town ?? '')), 0, 50),
		'telefone' => substr(trim((string) ($thirdparty->phone ?? '')), 0, 50),
		'telemovel' => substr(trim((string) ($thirdparty->phone_mobile ?? '')), 0, 50),
		'email' => substr(trim((string) ($thirdparty->email ?? '')), 0, 50),
		'pais' => substr($countryCode, 0, 4),
		'desconto' => 0,
		'mododesconto' => 0,
		'limitecredito' => 0,
		'retencao' => 0,
		'percentagemretencao' => 0,
		'isento' => 0,
		'sujeitopassivo' => 0,
		'ivacaixa' => 0,
		'motivo' => '0',
	);

	$vatValue = trim((string) $vatValue);
	if ($vatValue !== '') {
		$payload['contribuinte'] = substr($vatValue, 0, 15);
	}

	return $payload;
}

/**
 * Create a client in ZSBMS.
 *
 * @param object               $zoneSoftApi
 * @param array<string,mixed>  $clientPayload
 * @param int                  $store
 * @return int
 */
function dzs_create_zsbms_client($zoneSoftApi, array $clientPayload, $store = 0)
{
	$zoneSoftApi->setInterface('clients');
	$payload = array(
		'client' => array($clientPayload),
	);

	$rawResponse = dzs_execute_zs_action($zoneSoftApi, 'saveInstances', $payload, (int) $store);
	$response = dzs_zs_normalize_response($rawResponse);
	$statusCode = (int) ($response->Response->StatusCode ?? 0);
	if (!in_array($statusCode, array(200, 201), true)) {
		throw new Exception(dzs_zs_response_error_text($response, 'Unable to create client in ZSBMS'));
	}

	$clients = dzs_zs_extract_clients_from_response($response);
	if (!empty($clients) && !empty($clients[0]['codigo'])) {
		return (int) $clients[0]['codigo'];
	}

	return (int) ($clientPayload['codigo'] ?? 0);
}

/**
 * Collect unique product codes from manual invoice lines.
 *
 * @param array $lines
 * @return array<int,int>
 */
function dzs_collect_manual_line_codes(array $lines)
{
	$codes = array();

	foreach ($lines as $line) {
		if (!is_array($line)) {
			continue;
		}
		$codeRaw = trim((string) ($line['codigo'] ?? ''));
		if ($codeRaw === '' || !ctype_digit($codeRaw)) {
			continue;
		}
		$code = (int) $codeRaw;
		if ($code <= 0) {
			continue;
		}
		$codes[$code] = $code;
	}

	return array_values($codes);
}

/**
 * Fetch existing ZSBMS product codes for the current store.
 *
 * @param object        $zoneSoftApi
 * @param array<int,int> $codes
 * @param int           $store
 * @return array<int,int>
 */
function dzs_fetch_existing_zsbms_product_codes($zoneSoftApi, array $codes, $store = 0)
{
	$normalized = array();
	foreach ($codes as $code) {
		$codeInt = (int) $code;
		if ($codeInt > 0) {
			$normalized[$codeInt] = $codeInt;
		}
	}
	$normalized = array_values($normalized);
	if (empty($normalized)) {
		return array();
	}

	$zoneSoftApi->setInterface('products');
	$payload = array(
		'product' => array(
			'condition' => 'codigo IN (' . implode(',', $normalized) . ')',
			'limit' => count($normalized),
		),
	);

	$rawResponse = dzs_execute_zs_action($zoneSoftApi, 'getInstances', $payload, (int) $store);
	$response = dzs_zs_normalize_response($rawResponse);
	$statusCode = (int) ($response->Response->StatusCode ?? 0);

	if ($statusCode === 204) {
		return array();
	}
	if ($statusCode !== 200) {
		throw new Exception(dzs_zs_response_error_text($response, 'Unable to validate selected products in ZSBMS'));
	}

	$content = $response->Response->Content ?? null;
	if (!is_object($content) || !property_exists($content, 'product')) {
		return array();
	}

	$productsRaw = $content->product;
	$products = array();
	if (is_array($productsRaw)) {
		$products = $productsRaw;
	} elseif (is_object($productsRaw)) {
		$products = array(get_object_vars($productsRaw));
	}

	$existing = array();
	foreach ($products as $product) {
		if (is_object($product)) {
			$product = get_object_vars($product);
		}
		if (!is_array($product)) {
			continue;
		}
		$productCode = (int) ($product['codigo'] ?? 0);
		if ($productCode > 0) {
			$existing[$productCode] = $productCode;
		}
	}

	return array_values($existing);
}

/**
 * Fetch existing generic ZSBMS entity codes (codigo) for a given interface/entity.
 *
 * @param object         $zoneSoftApi
 * @param string         $interface
 * @param string         $entityKey
 * @param array<int,int> $codes
 * @param int            $store
 * @return array<int,int>
 */
function dzs_fetch_existing_zsbms_codes($zoneSoftApi, $interface, $entityKey, array $codes, $store = 0)
{
	$normalized = array();
	foreach ($codes as $code) {
		$codeInt = (int) $code;
		if ($codeInt > 0) {
			$normalized[$codeInt] = $codeInt;
		}
	}
	$normalized = array_values($normalized);
	if (empty($normalized)) {
		return array();
	}

	$interface = trim((string) $interface);
	$entityKey = trim((string) $entityKey);
	if ($interface === '' || $entityKey === '') {
		return array();
	}

	$zoneSoftApi->setInterface($interface);
	$payload = array(
		$entityKey => array(
			'condition' => 'codigo IN (' . implode(',', $normalized) . ')',
			'limit' => count($normalized),
		),
	);

	$rawResponse = dzs_execute_zs_action($zoneSoftApi, 'getInstances', $payload, (int) $store);
	$response = dzs_zs_normalize_response($rawResponse);
	$statusCode = (int) ($response->Response->StatusCode ?? 0);

	if ($statusCode === 204) {
		return array();
	}
	if ($statusCode !== 200) {
		throw new Exception(dzs_zs_response_error_text($response, 'Unable to validate ZSBMS reference data'));
	}

	$content = $response->Response->Content ?? null;
	if (!is_object($content) || !property_exists($content, $entityKey)) {
		return array();
	}

	$instances = dzs_zs_normalize_instance_list($content->{$entityKey});
	$existing = array();
	foreach ($instances as $instance) {
		$instanceCode = (int) ($instance['codigo'] ?? 0);
		if ($instanceCode > 0) {
			$existing[$instanceCode] = $instanceCode;
		}
	}

	return array_values($existing);
}

/**
 * Fetch first available codigo for a given ZSBMS interface/entity.
 *
 * @param object $zoneSoftApi
 * @param string $interface
 * @param string $entityKey
 * @param int    $store
 * @return int
 */
function dzs_fetch_first_zsbms_code($zoneSoftApi, $interface, $entityKey, $store = 0)
{
	$interface = trim((string) $interface);
	$entityKey = trim((string) $entityKey);
	if ($interface === '' || $entityKey === '') {
		return 0;
	}

	$zoneSoftApi->setInterface($interface);
	$payload = array(
		$entityKey => array(
			'order' => 'codigo;ASC',
			'limit' => 1,
		),
	);

	$rawResponse = dzs_execute_zs_action($zoneSoftApi, 'getInstances', $payload, (int) $store);
	$response = dzs_zs_normalize_response($rawResponse);
	$statusCode = (int) ($response->Response->StatusCode ?? 0);

	if ($statusCode === 204) {
		return 0;
	}
	if ($statusCode !== 200) {
		throw new Exception(dzs_zs_response_error_text($response, 'Unable to fetch ZSBMS reference data'));
	}

	$content = $response->Response->Content ?? null;
	if (!is_object($content) || !property_exists($content, $entityKey)) {
		return 0;
	}

	$instances = dzs_zs_normalize_instance_list($content->{$entityKey});
	if (empty($instances)) {
		return 0;
	}

	return (int) ($instances[0]['codigo'] ?? 0);
}

/**
 * Resolve existing ZSBMS client by VAT/name or create it from Dolibarr thirdparty.
 *
 * @param object  $zoneSoftApi
 * @param Societe $thirdparty
 * @param int     $store
 * @return array<string,mixed>
 */
function dzs_resolve_or_create_zsbms_client($zoneSoftApi, Societe $thirdparty, $store = 0)
{
	$clientName = trim((string) ($thirdparty->name ?? ''));
	if ($clientName === '') {
		throw new Exception('Selected client has no name');
	}

	$vatCandidates = dzs_build_vat_candidates((string) ($thirdparty->tva_intra ?? ''));
	$existingCode = dzs_find_zsbms_client_code_by_vat_candidates($zoneSoftApi, $vatCandidates, (int) $store);
	if ($existingCode > 0) {
		return array(
			'client_id' => $existingCode,
			'created' => false,
			'matched_by' => 'vat',
		);
	}

	$nameCondition = "nome = '" . dzs_zs_quote_condition_value($clientName) . "'";
	$existingByName = dzs_find_zsbms_client_code($zoneSoftApi, $nameCondition, (int) $store);
	if ($existingByName > 0) {
		return array(
			'client_id' => $existingByName,
			'created' => false,
			'matched_by' => 'name',
		);
	}

	$nextCode = dzs_get_next_zsbms_client_code($zoneSoftApi, (int) $store);
	$vatForCreate = !empty($vatCandidates) ? $vatCandidates[0] : '';
	$clientPayload = dzs_build_zsbms_client_payload($thirdparty, $nextCode, $vatForCreate);

	try {
		$createdCode = dzs_create_zsbms_client($zoneSoftApi, $clientPayload, (int) $store);
	} catch (Exception $e) {
		// If concurrent creation happened, retry one lookup by VAT/name.
		$existingCode = dzs_find_zsbms_client_code_by_vat_candidates($zoneSoftApi, $vatCandidates, (int) $store);
		if ($existingCode > 0) {
			return array(
				'client_id' => $existingCode,
				'created' => false,
				'matched_by' => 'vat',
			);
		}
		$existingByName = dzs_find_zsbms_client_code($zoneSoftApi, $nameCondition, (int) $store);
		if ($existingByName > 0) {
			return array(
				'client_id' => $existingByName,
				'created' => false,
				'matched_by' => 'name',
			);
		}
		throw $e;
	}

	return array(
		'client_id' => $createdCode,
		'created' => true,
		'matched_by' => 'created',
	);
}

/**
 * Normalize VAT/NIF value for lookups.
 *
 * @param string $value
 * @return string
 */
function dzs_normalize_vat_lookup($value)
{
	$value = strtoupper(trim((string) $value));
	$value = preg_replace('/[^A-Z0-9]/', '', $value);
	return (string) $value;
}

/**
 * Search Dolibarr customers by VAT/NIF.
 *
 * @param DoliDB $db
 * @param string $vatQuery
 * @param bool   $exact
 * @param int    $limit
 * @return array<int,array<string,mixed>>
 */
function dzs_search_dolibarr_clients_by_vat($db, $vatQuery, $exact = false, $limit = 10)
{
	$normalized = dzs_normalize_vat_lookup($vatQuery);
	if ($normalized === '') {
		return array();
	}

	$normalizedNoPrefix = $normalized;
	if (preg_match('/^[A-Z]{2}[0-9]+$/', $normalized)) {
		$normalizedNoPrefix = substr($normalized, 2);
	}

	$vatExpr = "UPPER(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(s.tva_intra,''), ' ', ''), '-', ''), '.', ''), '/', ''))";
	$vatExprNoPt = "REPLACE(" . $vatExpr . ", 'PT', '')";

	$escapedNormalized = $db->escape($normalized);
	$escapedNoPrefix = $db->escape($normalizedNoPrefix);
	$conditions = array();
	if ($exact) {
		$conditions[] = $vatExpr . " = '" . $escapedNormalized . "'";
		if ($normalizedNoPrefix !== '' && $normalizedNoPrefix !== $normalized) {
			$conditions[] = $vatExprNoPt . " = '" . $escapedNoPrefix . "'";
		}
	} else {
		$conditions[] = $vatExpr . " LIKE '%" . $escapedNormalized . "%'";
		if ($normalizedNoPrefix !== '' && $normalizedNoPrefix !== $normalized) {
			$conditions[] = $vatExprNoPt . " LIKE '%" . $escapedNoPrefix . "%'";
		}
	}

	$entities = getEntity('societe');
	$sql = "SELECT s.rowid, s.nom, s.tva_intra FROM " . MAIN_DB_PREFIX . "societe AS s";
	$sql .= " WHERE s.entity IN (" . $entities . ")";
	$sql .= " AND s.client IN (1,3)";
	$sql .= " AND COALESCE(s.tva_intra, '') <> ''";
	$sql .= " AND (" . implode(' OR ', $conditions) . ")";

	if ($exact) {
		$sql .= " ORDER BY CASE";
		$sql .= " WHEN " . $vatExpr . " = '" . $escapedNormalized . "' THEN 0";
		if ($normalizedNoPrefix !== '' && $normalizedNoPrefix !== $normalized) {
			$sql .= " WHEN " . $vatExprNoPt . " = '" . $escapedNoPrefix . "' THEN 1";
		}
		$sql .= " ELSE 2 END, s.nom ASC";
	} else {
		$sql .= " ORDER BY CASE";
		$sql .= " WHEN " . $vatExpr . " LIKE '" . $escapedNormalized . "%' THEN 0";
		if ($normalizedNoPrefix !== '' && $normalizedNoPrefix !== $normalized) {
			$sql .= " WHEN " . $vatExprNoPt . " LIKE '" . $escapedNoPrefix . "%' THEN 1";
		}
		$sql .= " ELSE 2 END, s.nom ASC";
	}

	$sql .= " LIMIT " . max(1, (int) $limit);

	$resql = $db->query($sql);
	if (!$resql) {
		return array();
	}

	$list = array();
	while ($obj = $db->fetch_object($resql)) {
		$list[] = array(
			'id' => (int) ($obj->rowid ?? 0),
			'name' => trim((string) ($obj->nom ?? '')),
			'vat' => trim((string) ($obj->tva_intra ?? '')),
		);
	}
	$db->free($resql);

	return $list;
}

/**
 * Parse a boolean-like retail flag value.
 *
 * @param mixed $value
 * @return bool
 */
function dzs_is_retail_flag_enabled($value)
{
	$txt = strtolower(trim((string) $value));
	if ($txt === '') {
		return false;
	}
	if (in_array($txt, array('1', 'true', 'yes', 'y', 'on'), true)) {
		return true;
	}
	if (in_array($txt, array('0', 'false', 'no', 'n', 'off'), true)) {
		return false;
	}
	if (is_numeric($txt)) {
		return ((int) $txt) === 1;
	}

	return false;
}

/**
 * Check if retalho_by_store enables retail sales for the given store.
 *
 * @param string $retalhoByStoreRaw
 * @param int    $store
 * @return bool
 */
function dzs_is_product_retail_enabled_for_store($retalhoByStoreRaw, $store)
{
	$store = (int) $store;
	if ($store <= 0) {
		return false;
	}

	$raw = trim((string) $retalhoByStoreRaw);
	if ($raw === '') {
		return false;
	}

	$decoded = json_decode($raw, true);
	if (is_array($decoded)) {
		$storeKey = (string) ((int) $store);
		if ($storeKey !== '' && array_key_exists($storeKey, $decoded)) {
			return dzs_is_retail_flag_enabled($decoded[$storeKey]);
		}
		if (array_key_exists((int) $store, $decoded)) {
			return dzs_is_retail_flag_enabled($decoded[(int) $store]);
		}
		return false;
	}

	return dzs_is_retail_flag_enabled($raw);
}

/**
 * Search sellable products for invoice picker.
 * Only products present in dolizsynch_zsproduct and enabled in retalho_by_store for current store are returned.
 *
 * @param DoliDB $db
 * @param string $query
 * @param int    $store
 * @param int    $limit
 * @param string $priceBaseType
 * @return array<int,array<string,mixed>>
 */
function dzs_search_dolibarr_products_for_invoice($db, $query, $store = 0, $limit = 20, $priceBaseType = 'HT')
{
	$q = trim((string) $query);
	if ($q === '') {
		return array();
	}

	$store = (int) $store;
	if ($store <= 0) {
		return array();
	}

	$priceBaseType = strtoupper(trim((string) $priceBaseType));
	if ($priceBaseType !== 'TTC') {
		$priceBaseType = 'HT';
	}

	$searchTokens = dzs_tokenize_product_search_query($q);
	if (empty($searchTokens)) {
		return array();
	}

	$limit = max(1, min(50, (int) $limit));
	$fetchLimit = max($limit * 6, 120);
	$entities = getEntity('product');
	$priceEntities = getEntity('productprice');
	$queryLower = function_exists('mb_strtolower') ? mb_strtolower($q, 'UTF-8') : strtolower($q);
	$queryLowerLike = '%' . $db->escape($queryLower) . '%';
	$queryLowerPrefix = $db->escape($queryLower) . '%';
	$tokenAndParts = array();
	foreach ($searchTokens as $searchToken) {
		$tokenEscaped = $db->escape($searchToken);
		$tokenAndParts[] = "("
			. "LOWER(COALESCE(p.ref, '')) LIKE '%" . $tokenEscaped . "%'"
			. " OR LOWER(COALESCE(p.label, '')) LIKE '%" . $tokenEscaped . "%'"
			. " OR CAST(z.codigo AS CHAR) LIKE '%" . $tokenEscaped . "%'"
			. ")";
	}
	$rawFallbackClause = "("
		. "LOWER(COALESCE(p.ref, '')) LIKE '" . $queryLowerLike . "'"
		. " OR LOWER(COALESCE(p.label, '')) LIKE '" . $queryLowerLike . "'"
		. " OR CAST(z.codigo AS CHAR) LIKE '%" . $db->escape($q) . "%'"
		. ")";
	$tokenClause = (!empty($tokenAndParts)) ? "(" . implode(' AND ', $tokenAndParts) . ")" : '';
	$searchClause = $rawFallbackClause;
	if ($tokenClause !== '') {
		$searchClause = "(" . $rawFallbackClause . " OR " . $tokenClause . ")";
	}

	$sql = "SELECT p.rowid, p.ref, p.label, p.price, p.price_ttc, p.tva_tx";
	$sql .= ", z.codigo, z.retalho_by_store, z.loja_zs";
	$sql .= ", (SELECT pp.price FROM " . MAIN_DB_PREFIX . "product_price AS pp";
	$sql .= " WHERE pp.fk_product = p.rowid AND pp.price_level = 1 AND pp.entity IN (" . $priceEntities . ") ORDER BY pp.date_price DESC, pp.rowid DESC";
	$sql .= " " . $db->plimit(1) . ") AS latest_price";
	$sql .= ", (SELECT pp.price_ttc FROM " . MAIN_DB_PREFIX . "product_price AS pp";
	$sql .= " WHERE pp.fk_product = p.rowid AND pp.price_level = 1 AND pp.entity IN (" . $priceEntities . ") ORDER BY pp.date_price DESC, pp.rowid DESC";
	$sql .= " " . $db->plimit(1) . ") AS latest_price_ttc";
	$sql .= ", (SELECT pp.tva_tx FROM " . MAIN_DB_PREFIX . "product_price AS pp";
	$sql .= " WHERE pp.fk_product = p.rowid AND pp.price_level = 1 AND pp.entity IN (" . $priceEntities . ") ORDER BY pp.date_price DESC, pp.rowid DESC";
	$sql .= " " . $db->plimit(1) . ") AS latest_tva_tx";
	$sql .= " FROM " . MAIN_DB_PREFIX . "dolizsynch_zsproduct AS z";
	$sql .= " INNER JOIN " . MAIN_DB_PREFIX . "product AS p ON p.rowid = z.fk_product";
	$sql .= " WHERE p.entity IN (" . $entities . ")";
	$sql .= " AND p.tosell = 1";
	$sql .= " AND z.fk_product > 0";
	$sql .= " AND z.codigo IS NOT NULL AND z.codigo <> ''";
	$sql .= " AND CAST(z.codigo AS CHAR) REGEXP '^[0-9]+$'";
	$sql .= " AND " . $searchClause;
	$sql .= " ORDER BY CASE";
	$sql .= " WHEN LOWER(COALESCE(p.ref, '')) LIKE '" . $queryLowerPrefix . "' THEN 0";
	$sql .= " WHEN LOWER(COALESCE(p.label, '')) LIKE '" . $queryLowerPrefix . "' THEN 1";
	$sql .= " ELSE 2 END";
	$sql .= ", (z.loja_zs = " . $store . ") DESC";
	$sql .= ", z.rowid DESC";
	$sql .= ", p.ref ASC";
	$sql .= " " . $db->plimit($fetchLimit);

	$resql = $db->query($sql);
	if (!$resql) {
		return array();
	}

	$list = array();
	$seenProductIds = array();
	while ($obj = $db->fetch_object($resql)) {
		$rowid = (int) ($obj->rowid ?? 0);
		if ($rowid <= 0 || isset($seenProductIds[$rowid])) {
			continue;
		}

		if (!dzs_is_product_retail_enabled_for_store((string) ($obj->retalho_by_store ?? ''), $store)) {
			continue;
		}

		$zsCode = trim((string) ($obj->codigo ?? ''));
		if ($zsCode === '' || !ctype_digit($zsCode)) {
			continue;
		}

		$priceRaw = null;
		if ($priceBaseType === 'TTC') {
			$priceRaw = ($obj->latest_price_ttc ?? null);
			if ($priceRaw === null || $priceRaw === '') {
				$priceRaw = ($obj->price_ttc ?? 0);
			}
		} else {
			$priceRaw = ($obj->latest_price ?? null);
			if ($priceRaw === null || $priceRaw === '') {
				$priceRaw = ($obj->price ?? 0);
			}
		}
		$priceValue = (float) price2num((string) $priceRaw, 'MU');
		if (!is_finite($priceValue)) {
			$priceValue = 0.0;
		}
		$vatTx = (float) price2num((string) (($obj->latest_tva_tx ?? '') !== '' ? $obj->latest_tva_tx : ($obj->tva_tx ?? 0)), 'MU');
		if (!is_finite($vatTx) || $vatTx < 0) {
			$vatTx = 0.0;
		}

		$list[] = array(
			'id' => $rowid,
			'ref' => trim((string) ($obj->ref ?? '')),
			'label' => trim((string) ($obj->label ?? '')),
			'code' => (string) ((int) $zsCode),
			'code_source' => 'zs',
			'price' => $priceValue,
			'vat_tx' => $vatTx,
		);
		$seenProductIds[$rowid] = true;

		if (count($list) >= $limit) {
			break;
		}
	}
	$db->free($resql);

	return $list;
}

/**
 * Load product VAT rates for current entity scope.
 *
 * @param DoliDB        $db
 * @param array<int,mixed> $productIds
 * @return array<int,float>
 */
function dzs_load_product_vat_rates($db, array $productIds)
{
	$ids = array();
	foreach ($productIds as $productId) {
		$id = (int) $productId;
		if ($id > 0) {
			$ids[$id] = $id;
		}
	}

	if (empty($ids)) {
		return array();
	}

	$entities = getEntity('product');
	$sql = "SELECT rowid, tva_tx FROM " . MAIN_DB_PREFIX . "product";
	$sql .= " WHERE entity IN (" . $entities . ")";
	$sql .= " AND rowid IN (" . implode(',', array_map('intval', $ids)) . ")";

	$resql = $db->query($sql);
	if (!$resql) {
		return array();
	}

	$rates = array();
	while ($obj = $db->fetch_object($resql)) {
		$rowid = (int) ($obj->rowid ?? 0);
		if ($rowid <= 0) {
			continue;
		}

		$vatTx = (float) price2num((string) ($obj->tva_tx ?? 0), 'MU');
		if (!is_finite($vatTx) || $vatTx < 0) {
			$vatTx = 0.0;
		}

		$rates[$rowid] = $vatTx;
	}
	$db->free($resql);

	return $rates;
}

/**
 * Load active warehouses for current entity.
 *
 * @param DoliDB $db
 * @param int    $entity
 * @return array<int,string>
 */
function dzs_load_warehouse_options($db, $entity)
{
	$options = array();
	$entities = getEntity('stock');
	$hasStatut = dzs_table_has_column($db, MAIN_DB_PREFIX . 'entrepot', 'statut');

	$sql = "SELECT e.rowid, e.ref, e.lieu";
	$sql .= " FROM " . MAIN_DB_PREFIX . "entrepot AS e";
	$sql .= " WHERE e.entity IN (" . $entities . ")";
	if ($hasStatut) {
		$sql .= " AND e.statut = 1";
	}
	$sql .= " ORDER BY e.ref ASC, e.rowid ASC";

	$resql = $db->query($sql);
	if (!$resql) {
		return $options;
	}

	while ($obj = $db->fetch_object($resql)) {
		$id = (int) ($obj->rowid ?? 0);
		if ($id <= 0) {
			continue;
		}
		$ref = trim((string) ($obj->ref ?? ''));
		$lieu = trim((string) ($obj->lieu ?? ''));
		$label = $ref;
		if ($label === '') {
			$label = ($lieu !== '' ? $lieu : ('Warehouse #' . $id));
		} elseif ($lieu !== '') {
			$label .= ' - ' . $lieu;
		}
		$options[$id] = $label;
	}
	$db->free($resql);

	return $options;
}

$originInvoiceId = GETPOSTINT('origin_invoice_id');
$backtopage = trim((string) GETPOST('backtopage', 'none'));
$action = trim((string) GETPOST('action', 'aZ09'));

$requestedDocType = strtoupper(trim((string) GETPOST('doc_type', 'aZ09')));
$docType = $requestedDocType;
if ($docType === '' || $docType === 'FA') {
	$docType = 'EC';
}
// Always use the store configured for the current entity in module setup.
$store = (int) ($conf->global->ZS_API_STORE ?? 0);
$socId = GETPOST('socid', 'int');
$paymentType = GETPOSTINT('payment_type');

if ($action === 'getClientVat') {
	$socIdLookup = GETPOSTINT('socid');
	header('Content-Type: application/json; charset=UTF-8');

	if ($socIdLookup <= 0) {
		echo json_encode(
			array(
				'ok' => 0,
				'vat' => '',
				'name' => '',
				'address' => '',
				'zip' => '',
				'town' => '',
				'country' => '',
				'email' => '',
			),
			JSON_UNESCAPED_UNICODE
		);
		exit;
	}

	$thirdpartyLookup = new Societe($db);
	if ($thirdpartyLookup->fetch($socIdLookup) <= 0) {
		echo json_encode(
			array(
				'ok' => 0,
				'vat' => '',
				'name' => '',
				'address' => '',
				'zip' => '',
				'town' => '',
				'country' => '',
				'email' => '',
			),
			JSON_UNESCAPED_UNICODE
		);
		exit;
	}

	echo json_encode(
		array(
			'ok' => 1,
			'vat' => trim((string) ($thirdpartyLookup->tva_intra ?? '')),
			'name' => trim((string) ($thirdpartyLookup->name ?? '')),
			'address' => trim((string) ($thirdpartyLookup->address ?? '')),
			'zip' => trim((string) ($thirdpartyLookup->zip ?? '')),
			'town' => trim((string) ($thirdpartyLookup->town ?? '')),
			'country' => trim((string) ($thirdpartyLookup->country_label ?? $thirdpartyLookup->country ?? $thirdpartyLookup->country_code ?? '')),
			'email' => trim((string) ($thirdpartyLookup->email ?? '')),
		),
		JSON_UNESCAPED_UNICODE
	);
	exit;
}

if ($action === 'searchClientByVat') {
	$vatQuery = trim((string) GETPOST('q', 'restricthtml'));
	$limit = GETPOSTINT('limit');
	if ($limit <= 0) {
		$limit = 10;
	}
	$limit = min(25, $limit);

	$items = dzs_search_dolibarr_clients_by_vat($db, $vatQuery, false, $limit);
	header('Content-Type: application/json; charset=UTF-8');
	echo json_encode(
		array(
			'ok' => 1,
			'items' => $items,
		),
		JSON_UNESCAPED_UNICODE
	);
	exit;
}

if ($action === 'getClientByVat') {
	$vatQuery = trim((string) GETPOST('vat', 'restricthtml'));
	$items = dzs_search_dolibarr_clients_by_vat($db, $vatQuery, true, 1);
	$item = !empty($items) ? $items[0] : null;

	header('Content-Type: application/json; charset=UTF-8');
	echo json_encode(
		array(
			'ok' => (!empty($item) ? 1 : 0),
			'item' => $item,
		),
		JSON_UNESCAPED_UNICODE
	);
	exit;
}

if ($action === 'searchProduct') {
	$query = trim((string) GETPOST('q', 'restricthtml'));
	$limit = GETPOSTINT('limit');
	if ($limit <= 0) {
		$limit = 20;
	}
	$limit = min(50, $limit);
	$priceBaseType = strtoupper(trim((string) ($conf->global->PRODUCT_PRICE_BASE_TYPE ?? 'HT')));

	$items = dzs_search_dolibarr_products_for_invoice($db, $query, (int) $store, $limit, $priceBaseType);
	header('Content-Type: application/json; charset=UTF-8');
	echo json_encode(
		array(
			'ok' => 1,
			'items' => $items,
		),
		JSON_UNESCAPED_UNICODE
	);
	exit;
}

$paymentTypeOptions = dzs_load_payment_type_options($db, $store, (int) $conf->entity);
$hasSyncedPaymentTypeOptions = !empty($paymentTypeOptions);
if (!$hasSyncedPaymentTypeOptions) {
	if ($paymentType > 0) {
		$paymentTypeOptions[$paymentType] = 'ID ' . $paymentType;
	}
} elseif ($paymentType > 0 && !isset($paymentTypeOptions[$paymentType])) {
	$paymentTypeOptions[$paymentType] = 'ID ' . $paymentType;
}

$employeeOptions = dzs_load_store_employee_options($db, (int) $conf->entity);
$hasStoreEmployeeOptions = !empty($employeeOptions);

$warehouseOptions = dzs_load_warehouse_options($db, (int) $conf->entity);
$warehouseCount = count($warehouseOptions);
$showWarehouseInput = ($warehouseCount > 1);
$defaultWarehouseId = (int) ($conf->global->MAIN_DEFAULT_WAREHOUSE ?? 0);
if ($defaultWarehouseId <= 0 || !isset($warehouseOptions[$defaultWarehouseId])) {
	$warehouseKeys = array_keys($warehouseOptions);
	if (!empty($warehouseKeys)) {
		$defaultWarehouseId = (int) $warehouseKeys[0];
	}
}
if ($defaultWarehouseId > 0 && !isset($warehouseOptions[$defaultWarehouseId])) {
	$warehouseOptions[$defaultWarehouseId] = 'ID ' . $defaultWarehouseId;
}

// Use Dolibarr system default price base type (product setup): TTC => VAT included, HT => VAT excluded.
$systemPriceBaseType = strtoupper(trim((string) ($conf->global->PRODUCT_PRICE_BASE_TYPE ?? 'HT')));
$ivaIncluido = ($systemPriceBaseType === 'TTC') ? 1 : 0;
$unitPriceDecimals = (int) ($conf->global->MAIN_MAX_DECIMALS_UNIT ?? 2);
if ($unitPriceDecimals < 0) {
	$unitPriceDecimals = 2;
}
if ($unitPriceDecimals > 9) {
	$unitPriceDecimals = 9;
}
$emp = GETPOST('emp', 'int');
if ($emp > 0 && !isset($employeeOptions[$emp])) {
	$emp = 0;
}

$deliveryMorada = GETPOST('delivery_morada', 'restricthtml');
$deliveryContacto = GETPOST('delivery_contacto', 'restricthtml');
$deliveryDataentrega = GETPOST('delivery_dataentrega', 'restricthtml');
$deliveryDataentregaTouched = GETPOST('delivery_dataentrega_touched', 'int');
$deliveryObs = GETPOST('delivery_obs', 'restricthtml');
$levantamento = GETPOST('levantamento', 'restricthtml');
$documentSerie = trim((string) GETPOST('document_serie', 'none'));
$documentNumber = GETPOSTINT('document_number');
$documentDate = trim((string) GETPOST('document_date', 'restricthtml'));
$documentHash = trim((string) GETPOST('document_hash', 'none'));
$documentHashcontrol2 = trim((string) GETPOST('document_hashcontrol2', 'none'));
$manualSubmitKey = dzs_get_manual_submission_key();

$deliveryDataentrega = trim((string) $deliveryDataentrega);
if ($deliveryDataentrega !== '') {
	if (preg_match('/^(\d{4}-\d{2}-\d{2})$/', $deliveryDataentrega, $m)) {
		$deliveryDataentrega = $m[1];
	} elseif (preg_match('/^(\d{4}-\d{2}-\d{2})\s+/', $deliveryDataentrega, $m)) {
		$deliveryDataentrega = $m[1];
	} else {
		$deliveryTs = dol_stringtotime($deliveryDataentrega);
		$deliveryDataentrega = (!empty($deliveryTs) ? dol_print_date($deliveryTs, '%Y-%m-%d') : '');
	}
}
if ($deliveryDataentrega === '' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
	$deliveryDataentrega = dol_print_date(dol_now(), '%Y-%m-%d');
}

// Keep pickup date aligned with delivery date for manual creator payloads.
$levantamento = trim((string) $levantamento);
if ($deliveryDataentrega !== '') {
	$levantamento = $deliveryDataentrega;
} else {
	$levantamento = '';
}

$documentDate = trim((string) $documentDate);
if ($documentDate !== '') {
	if (preg_match('/^(\d{4}-\d{2}-\d{2})$/', $documentDate, $m)) {
		$documentDate = $m[1];
	} elseif (preg_match('/^(\d{4}-\d{2}-\d{2})\s+/', $documentDate, $m)) {
		$documentDate = $m[1];
	} else {
		$documentTs = dol_stringtotime($documentDate);
		$documentDate = (!empty($documentTs) ? dol_print_date($documentTs, '%Y-%m-%d') : '');
	}
}

$lineCodigo = (array) GETPOST('line_codigo', 'array');
$lineProductId = (array) GETPOST('line_product_id', 'array');
$lineQtd = (array) GETPOST('line_qtd', 'array');
$linePunit = (array) GETPOST('line_punit', 'array');
$lineDesconto = (array) GETPOST('line_desconto', 'array');
$lineVatTx = (array) GETPOST('line_vat_tx', 'array');
$lineObs = (array) GETPOST('line_obs', 'array');
$lineArmazem = (array) GETPOST('line_armazem', 'array');

if (empty($lineCodigo) && empty($lineProductId) && empty($lineQtd) && empty($linePunit) && empty($lineDesconto) && empty($lineVatTx) && empty($lineObs) && empty($lineArmazem)) {
	$lineCodigo = array('');
	$lineProductId = array('');
	$lineQtd = array('1');
	$linePunit = array('');
	$lineDesconto = array('0');
	$lineVatTx = array('0');
	$lineObs = array('');
	$lineArmazem = array($defaultWarehouseId > 0 ? (string) $defaultWarehouseId : '');
}

$lineCalculationErrors = dzs_normalize_line_calculation_arrays($lineQtd, $linePunit, $unitPriceDecimals);

$originInvoiceDate = '';
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $originInvoiceId > 0) {
	$originInvoice = new Facture($db);
	if ($originInvoice->fetch($originInvoiceId) > 0) {
		$originInvoice->fetch_lines();
		if (!empty($originInvoice->date)) {
			$originInvoiceDate = dol_print_date($originInvoice->date, '%Y-%m-%d');
		} elseif (!empty($originInvoice->datef)) {
			$originInvoiceDate = dol_print_date($originInvoice->datef, '%Y-%m-%d');
		}
		if ($socId <= 0) {
			$socId = (int) $originInvoice->socid;
		}

		if (!empty($originInvoice->lines) && is_array($originInvoice->lines)) {
			$lineCodigo = array();
			$lineProductId = array();
			$lineQtd = array();
			$linePunit = array();
			$lineDesconto = array();
			$lineVatTx = array();
			$lineObs = array();
			$lineArmazem = array();

				foreach ($originInvoice->lines as $line) {
				$qty = (float) ($line->qty ?? 0);
				if (abs($qty) < 0.0000001) {
					continue;
				}

				$codigo = '';
				if (!empty($line->product_ref) && ctype_digit((string) $line->product_ref)) {
					$codigo = (string) ((int) $line->product_ref);
				} elseif (!empty($line->fk_product)) {
					$codigo = (string) ((int) $line->fk_product);
				}

				$lineCodigo[] = $codigo;
					$lineProductId[] = !empty($line->fk_product) ? (string) ((int) $line->fk_product) : '';
					$lineQtd[] = (string) price2num(abs($qty), 'MS');
					$lineVatRate = (float) price2num((string) ($line->tva_tx ?? 0), 'MU');
					if (!is_finite($lineVatRate) || $lineVatRate < 0) {
						$lineVatRate = 0.0;
					}
					$lineUnitPrice = (float) ($line->subprice ?? 0);
					if ($ivaIncluido) {
						if (isset($line->pu_ttc)) {
							$lineUnitPrice = (float) $line->pu_ttc;
						} else {
							$lineUnitPrice = $lineUnitPrice * (1 + ($lineVatRate / 100));
						}
					}
					$linePunit[] = (string) price2num(abs($lineUnitPrice), 'MS');
					$lineDesconto[] = (string) price2num(abs((float) ($line->remise_percent ?? 0)), 'MS');
					$lineVatTx[] = (string) price2num($lineVatRate, 'MS');
					$lineObs[] = '';
					$lineWarehouse = (int) ($line->fk_warehouse ?? 0);
					if ($lineWarehouse <= 0) {
						$lineWarehouse = (int) $defaultWarehouseId;
					}
					$lineArmazem[] = ($lineWarehouse > 0 ? (string) $lineWarehouse : '');
				}

					if (empty($lineCodigo)) {
						$lineCodigo = array('');
						$lineProductId = array('');
						$lineQtd = array('1');
						$linePunit = array('');
						$lineDesconto = array('0');
						$lineVatTx = array('0');
						$lineObs = array('');
						$lineArmazem = array($defaultWarehouseId > 0 ? (string) $defaultWarehouseId : '');
					}
				}
	}
}

if ($documentDate === '' && $originInvoiceDate !== '' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
	$documentDate = $originInvoiceDate;
}
if ($documentDate === '' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
	$documentDate = dol_print_date(dol_now(), '%Y-%m-%d');
}

$lineRows = max(count($lineCodigo), count($lineProductId), count($lineQtd), count($linePunit), count($lineDesconto), count($lineVatTx), count($lineObs), count($lineArmazem));
$productIdsForVat = array();
for ($i = 0; $i < $lineRows; $i++) {
	$currentProductId = trim((string) dzs_line_val($lineProductId, $i));
	$currentVatTx = trim((string) dzs_line_val($lineVatTx, $i));
	if ($currentVatTx === '' && $currentProductId !== '' && ctype_digit($currentProductId)) {
		$productIdsForVat[] = (int) $currentProductId;
	}
}
$productVatRates = dzs_load_product_vat_rates($db, $productIdsForVat);
for ($i = 0; $i < $lineRows; $i++) {
	$currentProductId = trim((string) dzs_line_val($lineProductId, $i));
	$currentVatTx = trim((string) dzs_line_val($lineVatTx, $i));
	if ($currentVatTx === '' && $currentProductId !== '' && ctype_digit($currentProductId) && isset($productVatRates[(int) $currentProductId])) {
		$currentVatTx = (string) price2num($productVatRates[(int) $currentProductId], 'MS');
	}
	if ($currentVatTx === '') {
		$currentVatTx = '0';
	}

	$lineVatTx[$i] = (string) price2num($currentVatTx, 'MS');
}

if ($defaultWarehouseId > 0) {
	$warehouseRows = max(count($lineCodigo), count($lineQtd), count($linePunit), count($lineDesconto), count($lineVatTx), count($lineObs), count($lineArmazem));
	for ($i = 0; $i < $warehouseRows; $i++) {
		$currentWarehouse = trim((string) dzs_line_val($lineArmazem, $i));
		if ($currentWarehouse === '') {
			$lineArmazem[$i] = (string) $defaultWarehouseId;
		}
	}
}

$selectedThirdparty = null;
$selectedClientVat = '';
$selectedClientInputLabel = '';
$selectedClientAddress = '';
$selectedClientZip = '';
$selectedClientTown = '';
$selectedClientCountry = '';
$selectedClientEmail = '';
$createdDocumentRef = '';
$createdDocumentPdfUrl = '';
$createdDocumentNotice = dzs_pop_created_document_notice();
if ($createdDocumentNotice['document_ref'] !== '' || $createdDocumentNotice['pdf_url'] !== '') {
	$createdDocumentRef = $createdDocumentNotice['document_ref'];
	$createdDocumentPdfUrl = $createdDocumentNotice['pdf_url'];
}
if ($socId > 0) {
	$selectedThirdparty = new Societe($db);
	if ($selectedThirdparty->fetch($socId) > 0) {
		$selectedClientVat = trim((string) ($selectedThirdparty->tva_intra ?? ''));
		$selectedClientInputLabel = trim((string) ($selectedThirdparty->name ?? ''));
		$selectedClientAddress = trim((string) ($selectedThirdparty->address ?? ''));
		$selectedClientZip = trim((string) ($selectedThirdparty->zip ?? ''));
		$selectedClientTown = trim((string) ($selectedThirdparty->town ?? ''));
		$selectedClientCountry = trim((string) ($selectedThirdparty->country_label ?? $selectedThirdparty->country ?? $selectedThirdparty->country_code ?? ''));
		$selectedClientEmail = trim((string) ($selectedThirdparty->email ?? ''));
	} else {
		$socId = 0;
		$selectedThirdparty = null;
	}
}

if (GETPOST('createManualZsbmsInvoice', 'alpha') !== '' || GETPOSTINT('manual_submit_intent') > 0) {
	$rawLines = [];
	$maxRows = max(count($lineCodigo), count($lineProductId), count($lineQtd), count($linePunit), count($lineDesconto), count($lineVatTx), count($lineObs), count($lineArmazem));
		for ($row = 0; $row < $maxRows; $row++) {
			$armazemLineValue = trim((string) dzs_line_val($lineArmazem, $row));
			if ($armazemLineValue === '' && $defaultWarehouseId > 0) {
				$armazemLineValue = (string) $defaultWarehouseId;
			}
			$rawLines[] = [
				'codigo' => dzs_line_val($lineCodigo, $row),
				'qtd' => dzs_line_val($lineQtd, $row),
				'punit' => dzs_line_val($linePunit, $row),
				'desconto' => dzs_line_val($lineDesconto, $row),
				'vat_tx' => dzs_line_val($lineVatTx, $row),
				'obs' => dzs_line_val($lineObs, $row),
				'armazem' => $armazemLineValue,
			];
		}

		$postedManualSubmitKey = dzs_normalize_manual_submission_key((string) GETPOST('manual_submit_key', 'aZ09'));
		if ($postedManualSubmitKey === '') {
			$postedManualSubmitKey = $manualSubmitKey;
		}
		$manualSubmissionFingerprint = dzs_build_manual_submission_fingerprint(
			array(
				'doc' => $docType,
				'origin_invoice_id' => (int) $originInvoiceId,
				'store' => (int) $store,
				'socid' => (int) $socId,
				'payment_type' => (int) $paymentType,
				'emp' => (int) $emp,
				'ivaincluido' => (int) $ivaIncluido,
				'delivery_morada' => (string) $deliveryMorada,
				'delivery_contacto' => (string) $deliveryContacto,
				'delivery_dataentrega' => (string) $deliveryDataentrega,
				'delivery_dataentrega_touched' => (int) $deliveryDataentregaTouched,
				'delivery_obs' => (string) $deliveryObs,
				'levantamento' => (string) $levantamento,
				'document_serie' => (string) $documentSerie,
				'document_number' => (int) $documentNumber,
				'document_date' => (string) $documentDate,
				'document_hash' => (string) $documentHash,
				'document_hashcontrol2' => (string) $documentHashcontrol2,
				'lines' => $rawLines,
			),
			(int) $conf->entity,
			(int) $user->id
		);
		$manualSubmissionReserved = false;

		try {
			if (!empty($lineCalculationErrors)) {
				throw new Exception('Invalid calculation in ' . implode(', ', $lineCalculationErrors) . '.');
			}

			if ($requestedDocType === 'FA') {
				throw new Exception(dzs_t($langs, "ZSBMSFADocumentsDisabled", "FA documents are disabled in this creator."));
			}
			if (!$hasStoreEmployeeOptions) {
				throw new Exception(dzs_t($langs, "ZSBMSEmployeeMissing", "No active employees were found in the configured schedule group for this entity/store."));
			}
			if ($emp <= 0 || !isset($employeeOptions[$emp])) {
				throw new Exception(dzs_t($langs, "ZSBMSEmployeeSelectionRequired", "Select an active employee before creating the document."));
			}

				if ($socId <= 0) {
					throw new Exception(dzs_t($langs, "ZSBMSClientSelectionRequired", "Select a client before creating the document."));
				}

				$selectedThirdpartyForSubmit = new Societe($db);
		if ($selectedThirdpartyForSubmit->fetch($socId) <= 0) {
			throw new Exception(dzs_t($langs, "ZSBMSSelectedClientNotFound", "Selected client was not found in Dolibarr."));
		}

		$submissionReservation = dzs_reserve_manual_submission_key($postedManualSubmitKey, $manualSubmissionFingerprint);
		$submissionReservationStatus = (string) ($submissionReservation['status'] ?? '');
		if ($submissionReservationStatus === 'success') {
			$existingDocumentRef = trim((string) ($submissionReservation['document_ref'] ?? ''));
			$existingPdfUrl = trim((string) ($submissionReservation['pdf_url'] ?? ''));
			if ($existingDocumentRef !== '' || $existingPdfUrl !== '') {
				dzs_store_created_document_notice($existingDocumentRef, $existingPdfUrl);
			}
			setEventMessages(dzs_t($langs, "ZSBMSManualSubmissionAlreadyProcessed", "This form submission was already processed; the existing ZSBMS document was not created again."), null, 'warnings', 1);
			header('Location: ' . dzs_build_post_success_redirect_url($originInvoiceId, $backtopage));
			exit;
		}
		if ($submissionReservationStatus === 'processing') {
			throw new Exception(dzs_t($langs, "ZSBMSManualSubmissionProcessing", "This form submission is already being processed. Wait for the current request to finish before submitting again."));
		}
		if ($submissionReservationStatus === 'used') {
			throw new Exception(dzs_t($langs, "ZSBMSManualSubmissionAlreadyUsed", "This form was already used to create a document. Reload the page before creating another ZSBMS document."));
		}
		$manualSubmissionReserved = true;

		$zsInvoiceSync = new ZSInvoiceSynch($db);

		$resolveResult = dzs_resolve_or_create_zsbms_client($zsInvoiceSync->zoneSoftApi, $selectedThirdpartyForSubmit, $store);
		$resolvedZsbmsClientId = (int) ($resolveResult['client_id'] ?? 0);
		if ($resolvedZsbmsClientId <= 0) {
			throw new Exception(dzs_t($langs, "ZSBMSClientResolveFailed", "Unable to find or create this client in ZSBMS."));
		}

		$selectedClientVat = trim((string) ($selectedThirdpartyForSubmit->tva_intra ?? ''));
		$selectedClientInputLabel = trim((string) ($selectedThirdpartyForSubmit->name ?? ''));
		$selectedClientAddress = trim((string) ($selectedThirdpartyForSubmit->address ?? ''));
		$selectedClientZip = trim((string) ($selectedThirdpartyForSubmit->zip ?? ''));
		$selectedClientTown = trim((string) ($selectedThirdpartyForSubmit->town ?? ''));
		$selectedClientCountry = trim((string) ($selectedThirdpartyForSubmit->country_label ?? $selectedThirdpartyForSubmit->country ?? $selectedThirdpartyForSubmit->country_code ?? ''));
		$selectedClientEmail = trim((string) ($selectedThirdpartyForSubmit->email ?? ''));

		$clientAddressParts = array();
		foreach (array($selectedThirdpartyForSubmit->address ?? '', $selectedThirdpartyForSubmit->zip ?? '', $selectedThirdpartyForSubmit->town ?? '') as $addressPart) {
			$addressPart = trim((string) $addressPart);
			if ($addressPart !== '') {
				$clientAddressParts[] = $addressPart;
			}
		}
		$clientFullAddress = implode(' ', $clientAddressParts);
		$clientPhone = trim((string) ($selectedThirdpartyForSubmit->phone ?? ''));
		if ($clientPhone === '') {
			$clientPhone = trim((string) ($selectedThirdpartyForSubmit->phone_mobile ?? ''));
		}

			$manualData = [
				'doc' => $docType,
				'store' => $store,
				'cliente' => $resolvedZsbmsClientId,
				'pagamento' => $paymentType,
				'emp' => $emp,
				'ivaincluido' => $ivaIncluido,
				'vendas' => $rawLines,
				'document_serie' => $documentSerie,
				'document_number' => $documentNumber,
				'document_date' => $documentDate,
				'document_hash' => $documentHash,
				'document_hashcontrol2' => $documentHashcontrol2,
				'nome' => trim((string) ($selectedThirdpartyForSubmit->name ?? '')),
				'contribuinte' => $selectedClientVat,
				'countrycode' => trim((string) ($selectedThirdpartyForSubmit->country_code ?? 'PT')),
				'morada' => $clientFullAddress,
				'telefone' => $clientPhone,
			];
			$isWorkingDocument = (strtoupper(trim((string) $docType)) === 'EC');

			$deliveryData = [];
			$deliveryMoradaTrim = trim((string) $deliveryMorada);
			$deliveryContactoTrim = trim((string) $deliveryContacto);
			$deliveryDataentregaTrim = trim((string) $deliveryDataentrega);
			$deliveryObsTrim = trim((string) $deliveryObs);
			$deliveryDateTouched = ((int) $deliveryDataentregaTouched > 0);

			if ($deliveryMoradaTrim !== '') {
				$deliveryData['morada'] = $deliveryMoradaTrim;
			}
			if ($deliveryContactoTrim !== '') {
				$deliveryData['contacto'] = $deliveryContactoTrim;
			}
			if ($deliveryObsTrim !== '') {
				$deliveryData['obs'] = $deliveryObsTrim;
			}
			if ($deliveryDataentregaTrim !== '' && (!empty($deliveryData) || $deliveryDateTouched)) {
				$deliveryData['dataentrega'] = $deliveryDataentregaTrim;
			}
			if (!empty($deliveryData)) {
				$manualData['delivery'] = $deliveryData;
			}

				if (trim((string) $levantamento) !== '') {
					$manualData['levantamento'] = trim((string) $levantamento);
				}

				$selectedCodes = dzs_collect_manual_line_codes((array) ($manualData['vendas'] ?? array()));
				if (!empty($selectedCodes)) {
					$existingCodes = dzs_fetch_existing_zsbms_product_codes($zsInvoiceSync->zoneSoftApi, $selectedCodes, (int) $store);
					$existingLookup = array();
					foreach ($existingCodes as $existingCode) {
						$existingLookup[(int) $existingCode] = true;
					}

					$missingCodes = array();
					foreach ($selectedCodes as $selectedCode) {
						$selectedCode = (int) $selectedCode;
						if ($selectedCode > 0 && empty($existingLookup[$selectedCode])) {
							$missingCodes[] = $selectedCode;
						}
					}

					if (!empty($missingCodes)) {
						sort($missingCodes, SORT_NUMERIC);
						$missingCodes = array_values(array_unique($missingCodes));
						$missingMsg = dzs_t($langs, "ZSBMSProductsNotFound", "Selected products are not available in ZSBMS: %s");
						throw new Exception(sprintf($missingMsg, implode(', ', $missingCodes)));
					}
				}

				$preflightWarnings = array();

				try {
					$currentClientId = (int) ($manualData['cliente'] ?? 0);
					$existingClientCodes = dzs_fetch_existing_zsbms_codes(
						$zsInvoiceSync->zoneSoftApi,
						'clients',
						'client',
						array($currentClientId),
						(int) $store
					);
					if ($currentClientId > 0 && empty($existingClientCodes)) {
						$manualData['cliente'] = 0;
						$preflightWarnings[] = dzs_t(
							$langs,
							"ZSBMSClientFallbackToZero",
							"Selected client was not found by code in ZSBMS. Using client 0."
						);
					}
				} catch (Exception $e) {
					dol_syslog(__FILE__ . ' preflight client check warning: ' . $e->getMessage(), LOG_WARNING);
				}

				try {
					$currentPaymentType = (int) ($manualData['pagamento'] ?? 0);
					$validPaymentType = 0;

					if ($currentPaymentType > 0) {
						$existingPaymentCodes = dzs_fetch_existing_zsbms_codes(
							$zsInvoiceSync->zoneSoftApi,
							'paymenttypes',
							'paymenttype',
							array($currentPaymentType),
							(int) $store
						);
						if (!empty($existingPaymentCodes)) {
							$validPaymentType = (int) $existingPaymentCodes[0];
						}
					}

					if ($validPaymentType <= 0) {
						$firstPaymentType = dzs_fetch_first_zsbms_code(
							$zsInvoiceSync->zoneSoftApi,
							'paymenttypes',
							'paymenttype',
							(int) $store
						);
						if ($firstPaymentType > 0) {
							$validPaymentType = (int) $firstPaymentType;
						}
					}

					if ($validPaymentType > 0 && $validPaymentType !== $currentPaymentType) {
						$manualData['pagamento'] = $validPaymentType;
						$paymentType = $validPaymentType;
						$preflightWarnings[] = dzs_t(
							$langs,
							"ZSBMSPaymentTypeAutoAdjusted",
							"Payment type was auto-adjusted to an existing ZSBMS payment."
						);
					}
				} catch (Exception $e) {
					dol_syslog(__FILE__ . ' preflight payment type check warning: ' . $e->getMessage(), LOG_WARNING);
				}

				if (!empty($preflightWarnings)) {
					foreach ($preflightWarnings as $warningText) {
						$warningText = trim((string) $warningText);
						if ($warningText !== '') {
							setEventMessages($warningText, null, 'warnings', 1);
						}
					}
				}

						$hasMissingPropertiesError = function ($apiResult) {
							if (!is_array($apiResult) || ((int) ($apiResult['status_code'] ?? 0) !== 422)) {
								return false;
							}
						$errorText = (string) ($apiResult['error'] ?? '');
						if (stripos($errorText, 'Missing properties') !== false) {
							return true;
						}
						if (
							stripos($errorText, '"code":null') !== false
							&& stripos($errorText, '"message":null') !== false
						) {
							return true;
						}
						$instanceErrors = $apiResult['instance_errors'] ?? [];
						if (!is_array($instanceErrors)) {
							return false;
						}
						foreach ($instanceErrors as $instanceError) {
							$propertyText = trim((string) ($instanceError['property'] ?? ''));
							$lineText = trim((string) ($instanceError['error'] ?? ''));
							if (stripos($lineText, 'Missing properties') !== false) {
								return true;
							}
							if (
								strcasecmp($propertyText, 'document') === 0
								&& stripos($lineText, '"code":null') !== false
								&& stripos($lineText, '"message":null') !== false
							) {
								return true;
							}
						}
						return false;
					};

				$result = $zsInvoiceSync->createZsbmsInvoiceFromManualData($manualData);
				$manualDataRetry = $manualData;

				if (empty($result['success']) && in_array(strtoupper(trim((string) $docType)), array('FT', 'VD'), true) && $hasMissingPropertiesError($result) && ((int) ($manualDataRetry['ivaincluido'] ?? 0) !== 1)) {
					$manualDataRetry['ivaincluido'] = 1;
					$result = $zsInvoiceSync->createZsbmsInvoiceFromManualData($manualDataRetry);
					if (!empty($result['success'])) {
						setEventMessages(dzs_t($langs, "ZSBMSIvaAutoFallback", "ZSBMS requires ivaincluido=1 for FT/VD. The invoice was retried automatically."), null, 'warnings', 1);
					}
				}

				if (!empty($result['success'])) {
					$usedClientCode = 0;
				$resultPayload = (array) ($result['payload'] ?? array());
				$usedEntityKey = trim((string) ($result['entity'] ?? 'invoicedocument'));
				$usedEntityPayload = ($usedEntityKey !== '' && array_key_exists($usedEntityKey, $resultPayload)) ? $resultPayload[$usedEntityKey] : null;
				if (is_array($usedEntityPayload)) {
					if (isset($usedEntityPayload[0]) && is_array($usedEntityPayload[0])) {
						$usedClientCode = (int) ($usedEntityPayload[0]['cliente'] ?? 0);
					} else {
						$usedClientCode = (int) ($usedEntityPayload['cliente'] ?? 0);
					}
				}
				$requestedClientCode = (int) ($manualData['cliente'] ?? 0);
				if ($requestedClientCode > 0 && $usedClientCode === 0) {
					setEventMessages(
						dzs_t(
							$langs,
							"ZSBMSInvoiceClientFallbackToZero",
							"ZSBMS rejected the selected client for this document payload. The document was created with client 0."
						),
						null,
						'warnings',
						1
					);
				}

					$documentRef = !empty($result['document_ref']) ? $result['document_ref'] : '#';
					$createdDocumentRef = $documentRef;
					$createdDocumentPdfUrl = trim((string) ($result['pdf_url'] ?? ''));
					setEventMessages($langs->trans("ZSBMSInvoiceCreateSuccess") . " (" . $documentRef . ")", null, 'mesgs', 1);
				if (!empty($resolveResult['created'])) {
				$createdClientMessage = dzs_t($langs, "ZSBMSClientAutoCreated", "Client was created in ZSBMS with ID %s.");
				setEventMessages(sprintf($createdClientMessage, $resolvedZsbmsClientId), null, 'warnings', 1);
			}

				dzs_store_created_document_notice($createdDocumentRef, $createdDocumentPdfUrl);
				dzs_mark_manual_submission_success($postedManualSubmitKey, $manualSubmissionFingerprint, $createdDocumentRef, $createdDocumentPdfUrl);
				dzs_rotate_manual_submission_key();
				header('Location: ' . dzs_build_post_success_redirect_url($originInvoiceId, $backtopage));
				exit;
			} else {
			$errorText = !empty($result['error']) ? $result['error'] : $langs->trans("ZSBMSInvoiceCreateFailed");
			if (!empty($manualSubmissionReserved)) {
				dzs_release_manual_submission_key($postedManualSubmitKey, $manualSubmissionFingerprint);
				$manualSubmissionReserved = false;
			}
			setEventMessages($langs->trans("ZSBMSInvoiceCreateFailed") . ": " . $errorText, null, 'errors', 1);
		}
	} catch (Exception $e) {
		if (!empty($manualSubmissionReserved)) {
			dzs_release_manual_submission_key($postedManualSubmitKey, $manualSubmissionFingerprint);
		}
		setEventMessages($langs->trans("ZSBMSInvoiceCreateException") . ": " . $e->getMessage(), null, 'errors', 1);
	}
}

$lineCount = max(1, count($lineCodigo), count($lineProductId), count($lineQtd), count($linePunit), count($lineDesconto), count($lineVatTx), count($lineObs), count($lineArmazem));

$page_name = "ZSBMSInvoiceCreatorPage";

llxHeader('', $langs->trans($page_name));

$linkbackurl = '';
if (!empty($backtopage)) {
	$linkbackurl = $backtopage;
} elseif ($originInvoiceId > 0) {
	$linkbackurl = DOL_URL_ROOT . '/compta/facture/card.php?id=' . ((int) $originInvoiceId);
}
$linkback = '';
if ($linkbackurl !== '') {
	$linkback = '<a href="' . dol_escape_htmltag($linkbackurl) . '">' . $langs->trans("Back") . '</a>';
}
print load_fiche_titre($langs->trans($page_name), $linkback, 'title_setup');
print '<span class="opacitymedium">' . $langs->trans("ZSBMSInvoiceCreatorDescription") . '</span><br><br>';

$nonce = getNonce();
$formAction = dol_escape_htmltag($_SERVER['PHP_SELF']);
$form = new Form($db);
$clientVatLookupUrl = $formAction . '?action=getClientVat';
$clientVatSearchUrl = $formAction . '?action=searchClientByVat';
$clientVatResolveUrl = $formAction . '?action=getClientByVat';
$productSearchUrl = $formAction . '?action=searchProduct';
$clientSelectHtml = '';
$hadCompanySearchSetting = isset($conf->global->COMPANY_USE_SEARCH_TO_SELECT);
$savedCompanySearchSetting = $hadCompanySearchSetting ? $conf->global->COMPANY_USE_SEARCH_TO_SELECT : null;
$conf->global->COMPANY_USE_SEARCH_TO_SELECT = 1;
$clientSelectHtml = $form->select_company($socId, 'socid', '(s.client:IN:1,3)', 'SelectThirdParty', 0, 0, array(), 0, 'dzs-input-client', '', $selectedClientInputLabel, 1, array(), false, array(), 0);
if ($hadCompanySearchSetting) {
	$conf->global->COMPANY_USE_SEARCH_TO_SELECT = $savedCompanySearchSetting;
} else {
	unset($conf->global->COMPANY_USE_SEARCH_TO_SELECT);
}

$txtInvoiceHeader = dzs_t($langs, "ZSBMSInvoiceHeader", "Invoice header");
$txtDeliveryData = dzs_t($langs, "DeliveryData", "Delivery data");
$txtInvoiceLines = dzs_t($langs, "ZSBMSInvoiceLines", "Invoice lines");
$txtDuplicate = dzs_t($langs, "Duplicate", "Duplicate");
$txtRemoveEmptyLines = dzs_t($langs, "RemoveEmptyLines", "Remove empty lines");
$txtClearLines = dzs_t($langs, "ClearLines", "Clear lines");
$txtActiveLines = dzs_t($langs, "ActiveLines", "Active lines");
$txtTotalQuantity = dzs_t($langs, "TotalQuantity", "Total quantity");
$txtEstimatedAmount = dzs_t($langs, "EstimatedAmount", "Estimated amount");
$txtRows = dzs_t($langs, "Rows", "Rows");
$txtDocumentType = dzs_t($langs, "DocType", "Type");
$txtClientSelect = dzs_t($langs, "ZSBMSClientSelection", "Client");
$txtClientVat = dzs_t($langs, "ZSBMSClientVAT", "VAT ID");
$txtEntityData = dzs_t($langs, "ThirdParty", "Entity data");
$txtPaymentType = dzs_t($langs, "ZSBMSPaymentType", "ZSBMS payment type");
$txtPaymentTypeEmpty = dzs_t($langs, "ZSBMSPaymentTypeEmpty", "No payment selected");
$txtPaymentTypeMissing = dzs_t($langs, "ZSBMSPaymentTypeMissing", "No payment types were found. Sync payment types in setup.");
$txtEmployeeSelect = dzs_t($langs, "ZSBMSEmployeeSelect", "Select an employee");
$txtEmployeeMissing = dzs_t($langs, "ZSBMSEmployeeMissing", "No active employees were found in the configured schedule group for this entity/store.");
$txtEmployeeRequired = dzs_t($langs, "ZSBMSEmployeeSelectionRequired", "Select an active employee before creating the document.");
$txtLine = dzs_t($langs, "Line", "Line");
$txtTotal = dzs_t($langs, "Total", "Total");
$txtHT = dzs_t($langs, "HT", "HT");
$txtTTC = dzs_t($langs, "TTC", "TTC");
$txtHTLineLabel = 'IVA EXC.';
$txtTTCLineLabel = 'IVA INC.';
$txtReference = dzs_t($langs, "Ref", "Reference");
$txtDescription = dzs_t($langs, "Description", "Description");
$txtQtyShort = dzs_t($langs, "Qty", "Qty.");
$txtUnitPriceShort = dzs_t($langs, "UnitPrice", "Unit price");
$txtDiscountShort = dzs_t($langs, "Discount", "Discount");
$txtAddProduct = dzs_t($langs, "AddLine", "Add line");
$txtCreatedPdfKicker = dzs_t($langs, "ZSBMSCreatedPdfKicker", "PDF ZSBMS");
$txtCreatedPdfTitle = dzs_t($langs, "ZSBMSCreatedPdfTitle", "PDF pronto");
$txtCreatedPdfMessage = dzs_t($langs, "ZSBMSCreatedPdfOpenOnlyMessage", "O documento %s foi criado com sucesso. Pretende abrir o PDF agora?");
$txtOpenPdf = dzs_t($langs, "ZSBMSOpenPdf", "Abrir PDF");
$txtLater = dzs_t($langs, "Later", "Mais tarde");
$txtRefreshKeepDraftTitle = dzs_t($langs, "ZSBMSRefreshKeepDraftTitle", "Reload this page and restore the current draft values.");
$docTypeLabels = array(
	'CI' => dzs_t($langs, "ZSBMSDocTypeCI", "CI - Consumo Interno"),
	'CM' => dzs_t($langs, "ZSBMSDocTypeCM", "CM - Consultas de Mesa"),
	'FS' => dzs_t($langs, "ZSBMSDocTypeFS", "FS - Fatura Simplificada"),
	'FT' => dzs_t($langs, "ZSBMSDocTypeFT", "FT - Fatura Recibo"),
	'NC' => dzs_t($langs, "ZSBMSDocTypeNC", "NC - Notas de Credito"),
	'OC' => dzs_t($langs, "ZSBMSDocTypeOC", "OC - Orcamentos"),
	'RC' => dzs_t($langs, "ZSBMSDocTypeRC", "RC - Recibos"),
	'VD' => dzs_t($langs, "ZSBMSDocTypeVD", "VD - Venda a Dinheiro"),
	'EC' => dzs_t($langs, "ZSBMSDocTypeEC", "EC - Encomenda de Cliente"),
);
$currentDocTypeLabel = $docTypeLabels[$docType] ?? $docType;
$txtTotalHtTtc = trim($txtTotal . ' ' . $txtHT . ' / ' . $txtTTC);
$txtEstimatedAmountHt = trim($txtEstimatedAmount . ' ' . $txtHT);
$txtEstimatedAmountTtc = trim($txtEstimatedAmount . ' ' . $txtTTC);

$jsMessages = array(
	'needLine' => dzs_t($langs, "ZSBMSNeedAtLeastOneLine", "Add at least one valid line before submitting."),
	'invalidRows' => dzs_t($langs, "ZSBMSInvalidRowsDetails", "Please review {count} invalid line(s): {rows}."),
	'lineReference' => html_entity_decode($txtReference, ENT_QUOTES, 'UTF-8'),
	'lineQuantity' => html_entity_decode($txtQtyShort, ENT_QUOTES, 'UTF-8'),
	'lineUnitPrice' => html_entity_decode($txtUnitPriceShort, ENT_QUOTES, 'UTF-8'),
	'lineDiscount' => html_entity_decode($txtDiscountShort, ENT_QUOTES, 'UTF-8'),
	'needClient' => dzs_t($langs, "ZSBMSClientSelectionRequired", "Select a client before creating the document."),
	'needEmployee' => $txtEmployeeRequired,
	'noEmployees' => $txtEmployeeMissing,
);

print '<style nonce="' . $nonce . '">
#dzs-invoice-creator {
	--dzs-border: #d8e2eb;
	--dzs-muted: #5f7388;
	--dzs-heading: #16324f;
	--dzs-primary: #156f63;
	--dzs-primary-dark: #0f584e;
	--dzs-primary-soft: #e6f4f1;
	--dzs-action-grey: #4d4d4d;
	--dzs-action-grey-dark: #424242;
	--dzs-accent: #db8b2b;
	--dzs-danger-soft: #ffebe8;
	--dzs-input-radius: 8px;
	font-family: "Avenir Next", "Trebuchet MS", "Segoe UI", sans-serif;
	background: linear-gradient(136deg, #edf6ff 0%, #f7fbf5 55%, #fff8ec 100%);
	border: 1px solid var(--dzs-border);
	border-radius: 14px;
	padding: 16px;
}
#dzs-invoice-creator .dzs-card {
	background: #fff;
	border: 1px solid var(--dzs-border);
	border-radius: 12px;
	padding: 16px;
	margin-bottom: 12px;
	box-shadow: 0 8px 20px rgba(22, 50, 79, 0.06);
	opacity: 0;
	transform: translateY(8px);
	animation: dzsRise 260ms ease forwards;
}
#dzs-invoice-creator .dzs-card:nth-of-type(2) { animation-delay: 45ms; }
#dzs-invoice-creator .dzs-card:nth-of-type(3) { animation-delay: 90ms; }
#dzs-invoice-creator .dzs-card:last-child { margin-bottom: 0; }
#dzs-invoice-creator .dzs-card-head {
	display: flex;
	justify-content: space-between;
	align-items: center;
	flex-wrap: wrap;
	gap: 10px;
	margin-bottom: 12px;
}
#dzs-invoice-creator .dzs-card-title {
	font-size: 1.08rem;
	font-weight: 700;
	color: var(--dzs-heading);
}
#dzs-invoice-creator .dzs-pills {
	display: flex;
	gap: 8px;
	flex-wrap: wrap;
}
#dzs-invoice-creator .dzs-pill {
	display: inline-flex;
	align-items: center;
	gap: 6px;
	padding: 5px 10px;
	background: var(--dzs-primary-soft);
	border: 1px solid #c5dfd9;
	border-radius: 999px;
	color: var(--dzs-heading);
	font-size: 12px;
}
#dzs-invoice-creator .dzs-pill strong {
	font-weight: 700;
}
#dzs-invoice-creator .dzs-grid {
	display: grid;
	grid-template-columns: repeat(2, minmax(200px, 1fr));
	gap: 12px;
}
#dzs-invoice-creator .dzs-grid-3 {
	grid-template-columns: repeat(3, minmax(180px, 1fr));
}
#dzs-invoice-creator .dzs-field {
	display: flex;
	flex-direction: column;
	gap: 6px;
}
#dzs-invoice-creator .dzs-field label {
	font-size: 12px;
	font-weight: 700;
	color: var(--dzs-muted);
	text-transform: uppercase;
	letter-spacing: 0.02em;
}
#dzs-invoice-creator .dzs-field input,
#dzs-invoice-creator .dzs-field select {
	width: 100%;
	min-height: 36px;
	border: 1px solid #c7d3df;
	border-top-left-radius: var(--dzs-input-radius) !important;
	border-top-right-radius: var(--dzs-input-radius) !important;
	border-bottom-left-radius: var(--dzs-input-radius) !important;
	border-bottom-right-radius: var(--dzs-input-radius) !important;
	border-radius: var(--dzs-input-radius) !important;
	padding: 6px 10px;
	box-sizing: border-box;
}
#dzs-invoice-creator .dzs-field input:focus,
#dzs-invoice-creator .dzs-field select:focus,
#dzs-invoice-creator .dzs-field input:focus-visible,
#dzs-invoice-creator .dzs-field select:focus-visible,
#dzs-invoice-creator .dzs-field input:active,
#dzs-invoice-creator .dzs-field select:active {
	border-color: #78b3a8;
	box-shadow: 0 0 0 3px rgba(21, 111, 99, 0.12);
	border-top-left-radius: var(--dzs-input-radius) !important;
	border-top-right-radius: var(--dzs-input-radius) !important;
	border-bottom-left-radius: var(--dzs-input-radius) !important;
	border-bottom-right-radius: var(--dzs-input-radius) !important;
	border-radius: var(--dzs-input-radius) !important;
	outline: none;
}
#dzs-invoice-creator .dzs-field input[readonly] {
	background: #f3f8fc;
	color: #3a546d;
	border-top-left-radius: var(--dzs-input-radius) !important;
	border-top-right-radius: var(--dzs-input-radius) !important;
	border-bottom-left-radius: var(--dzs-input-radius) !important;
	border-bottom-right-radius: var(--dzs-input-radius) !important;
	border-radius: var(--dzs-input-radius) !important;
}
#dzs-invoice-creator .dzs-field #search_socid,
#dzs-invoice-creator .dzs-field input[id^="dzs-"],
#dzs-invoice-creator .dzs-field select[id^="dzs-"],
#dzs-invoice-creator .dzs-field textarea[id^="dzs-"] {
	border-top-left-radius: var(--dzs-input-radius) !important;
	border-top-right-radius: var(--dzs-input-radius) !important;
	border-radius: var(--dzs-input-radius) !important;
	border-bottom-left-radius: var(--dzs-input-radius) !important;
	border-bottom-right-radius: var(--dzs-input-radius) !important;
}
#dzs-invoice-creator .dzs-field #search_socid[class*="ui-corner-"],
#dzs-invoice-creator .dzs-field input[id^="dzs-"][class*="ui-corner-"],
#dzs-invoice-creator .dzs-field select[id^="dzs-"][class*="ui-corner-"],
#dzs-invoice-creator .dzs-field textarea[id^="dzs-"][class*="ui-corner-"] {
	border-radius: var(--dzs-input-radius) !important;
}
#dzs-invoice-creator .select2-container--default .select2-selection--single,
#dzs-invoice-creator .select2-container--default.select2-container--open .select2-selection--single,
#dzs-invoice-creator .select2-container--default.select2-container--focus .select2-selection--single {
	min-height: 36px;
	border: 1px solid #c7d3df;
	background: #fff;
	border-radius: var(--dzs-input-radius) !important;
	border-bottom-left-radius: var(--dzs-input-radius) !important;
	border-bottom-right-radius: var(--dzs-input-radius) !important;
}
#dzs-invoice-creator .dzs-field .select2-container {
	width: 100% !important;
}
#dzs-invoice-creator .select2-container--default .select2-selection--single .select2-selection__rendered {
	min-height: 34px;
	padding: 3px 32px 3px 10px;
	line-height: 28px;
	color: #243f59;
}
#dzs-invoice-creator .select2-container--default .select2-selection--single .select2-selection__placeholder {
	color: #6b7f92;
}
#dzs-invoice-creator .select2-container--default .select2-selection--single .select2-selection__arrow {
	height: 34px;
	right: 7px;
}
#dzs-invoice-creator .select2-container--default.select2-container--open .select2-selection--single,
#dzs-invoice-creator .select2-container--default.select2-container--focus .select2-selection--single {
	border-color: #78b3a8;
	box-shadow: 0 0 0 3px rgba(21, 111, 99, 0.12);
	outline: none;
}
#dzs-invoice-creator .select2-container--default.select2-container--disabled .select2-selection--single {
	background: #f3f8fc;
	color: #3a546d;
}
#dzs-invoice-creator .select2-container--default.select2-container--open.select2-container--below .select2-selection--single,
#dzs-invoice-creator .select2-container--default.select2-container--open.select2-container--below .select2-selection--multiple,
#dzs-invoice-creator .select2-container--default.select2-container--open.select2-container--above .select2-selection--single,
#dzs-invoice-creator .select2-container--default.select2-container--open.select2-container--above .select2-selection--multiple,
#dzs-invoice-creator .select2-container--classic.select2-container--open.select2-container--below .select2-selection--single,
#dzs-invoice-creator .select2-container--classic.select2-container--open.select2-container--below .select2-selection--multiple,
#dzs-invoice-creator .select2-container--classic.select2-container--open.select2-container--above .select2-selection--single,
#dzs-invoice-creator .select2-container--classic.select2-container--open.select2-container--above .select2-selection--multiple {
	border-top-left-radius: var(--dzs-input-radius) !important;
	border-top-right-radius: var(--dzs-input-radius) !important;
	border-bottom-left-radius: var(--dzs-input-radius) !important;
	border-bottom-right-radius: var(--dzs-input-radius) !important;
	border-radius: var(--dzs-input-radius) !important;
}
#dzs-invoice-creator .ui-corner-top,
#dzs-invoice-creator .ui-corner-bottom,
#dzs-invoice-creator .ui-corner-left,
#dzs-invoice-creator .ui-corner-right,
#dzs-invoice-creator .ui-corner-all {
	border-top-left-radius: var(--dzs-input-radius) !important;
	border-top-right-radius: var(--dzs-input-radius) !important;
	border-bottom-left-radius: var(--dzs-input-radius) !important;
	border-bottom-right-radius: var(--dzs-input-radius) !important;
	border-radius: var(--dzs-input-radius) !important;
}
#dzs-invoice-creator .dzs-field .small {
	line-height: 1.35;
}
#dzs-invoice-creator .dzs-line-composer {
	padding: 10px 12px;
	border: 1px solid #d7e2ec;
	border-radius: 10px;
	background: #fbfdff;
	margin-bottom: 10px;
}
#dzs-invoice-creator .dzs-line-grid {
	display: grid;
	grid-template-columns: minmax(120px, 1.2fr) minmax(220px, 2.2fr) minmax(120px, 1fr) minmax(100px, 0.9fr) minmax(100px, 0.9fr) minmax(100px, 0.9fr) auto;
	gap: 8px;
	align-items: end;
}
#dzs-invoice-creator .dzs-line-grid .dzs-field {
	gap: 4px;
}
#dzs-invoice-creator .dzs-line-grid .dzs-field label {
	font-size: 11px;
}
#dzs-invoice-creator .dzs-line-grid .dzs-field input {
	min-height: 34px;
	border-top-left-radius: var(--dzs-input-radius) !important;
	border-top-right-radius: var(--dzs-input-radius) !important;
	border-bottom-left-radius: var(--dzs-input-radius) !important;
	border-bottom-right-radius: var(--dzs-input-radius) !important;
	border-radius: var(--dzs-input-radius) !important;
}
#dzs-invoice-creator .dzs-line-composer-action {
	display: flex;
	align-items: end;
}
#dzs-invoice-creator #dzs-composer-add {
	height: 36px;
	min-width: 110px;
}
#dzs-invoice-creator #dzs-composer-add[disabled] {
	opacity: 0.45;
	cursor: not-allowed;
}
.ui-autocomplete {
	z-index: 1200;
}
#dzs-invoice-creator .dzs-lines-wrap {
	overflow-x: auto;
}
#dzs-invoice-creator .dzs-lines-head,
#dzs-invoice-creator .dzs-line-row {
	display: grid;
	grid-template-columns: 54px minmax(120px, 1fr) minmax(220px, 2fr) minmax(120px, 1fr) minmax(100px, 0.9fr) minmax(100px, 0.9fr) minmax(100px, 0.9fr) minmax(110px, 1fr) 90px;
	gap: 8px;
	align-items: center;
	min-width: 1040px;
}
#dzs-invoice-creator.dzs-no-warehouse .dzs-lines-head,
#dzs-invoice-creator.dzs-no-warehouse .dzs-line-row {
	grid-template-columns: 54px minmax(120px, 1fr) minmax(220px, 2fr) minmax(120px, 1fr) minmax(100px, 0.9fr) minmax(100px, 0.9fr) minmax(110px, 1fr) 90px;
	min-width: 940px;
}
#dzs-invoice-creator .dzs-lines-head {
	margin-bottom: 6px;
	padding: 0 8px;
	color: #5d7287;
	font-size: 11px;
	font-weight: 700;
	text-transform: uppercase;
	letter-spacing: 0.03em;
}
#dzs-invoice-creator .dzs-lines-head .right {
	text-align: right;
}
#dzs-invoice-creator .dzs-lines-list {
	display: flex;
	flex-direction: column;
	gap: 8px;
}
#dzs-invoice-creator .dzs-line-row {
	padding: 8px;
	border: 1px solid #d6e1eb;
	border-radius: 10px;
	background: #ffffff;
}
#dzs-invoice-creator .dzs-line-row.oddevenpair {
	background: #fbfdff;
}
#dzs-invoice-creator .dzs-line-row > div {
	min-width: 0;
}
#dzs-invoice-creator .dzs-line-row input,
#dzs-invoice-creator .dzs-line-row select {
	width: 100%;
	min-width: 0;
	border-top-left-radius: var(--dzs-input-radius) !important;
	border-top-right-radius: var(--dzs-input-radius) !important;
	border-bottom-left-radius: var(--dzs-input-radius) !important;
	border-bottom-right-radius: var(--dzs-input-radius) !important;
	border-radius: var(--dzs-input-radius) !important;
}
#dzs-invoice-creator .dzs-input-codigo[readonly] {
	background: #f8fbff;
	cursor: default;
}
#dzs-invoice-creator .dzs-input-codigo.dzs-ref-link {
	cursor: pointer;
	color: #35536f;
}
#dzs-invoice-creator .dzs-input-codigo.dzs-ref-link:hover {
	color: #1f4568;
}
#dzs-invoice-creator .dzs-input-codigo.dzs-ref-link.is-disabled {
	cursor: default;
	color: inherit;
	text-decoration: none;
	opacity: 0.45;
}
#dzs-invoice-creator .dzs-line-vat-hint {
	display: inline;
	margin-left: 3px;
	font-size: 10px;
	line-height: 1.2;
	color: inherit;
	white-space: nowrap;
}
#dzs-invoice-creator .dzs-input-punit {
	text-align: right;
}
#dzs-invoice-creator .dzs-input-qtd,
#dzs-invoice-creator .dzs-input-desconto {
	text-align: right;
}
#dzs-invoice-creator .dzs-input-desconto[type="number"] {
	appearance: textfield;
	-moz-appearance: textfield;
}
#dzs-invoice-creator .dzs-input-desconto[type="number"]::-webkit-outer-spin-button,
#dzs-invoice-creator .dzs-input-desconto[type="number"]::-webkit-inner-spin-button {
	-webkit-appearance: none;
	margin: 0;
}
#dzs-invoice-creator .dzs-line-row input:focus,
#dzs-invoice-creator .dzs-line-row input:active,
#dzs-invoice-creator .dzs-line-row input:focus-visible,
#dzs-invoice-creator .dzs-line-row select:focus,
#dzs-invoice-creator .dzs-line-row select:active,
#dzs-invoice-creator .dzs-line-row select:focus-visible {
	border-top-left-radius: var(--dzs-input-radius) !important;
	border-top-right-radius: var(--dzs-input-radius) !important;
	border-bottom-left-radius: var(--dzs-input-radius) !important;
	border-bottom-right-radius: var(--dzs-input-radius) !important;
	border-radius: var(--dzs-input-radius) !important;
}
#dzs-invoice-creator input:not([type="hidden"]):not([type="checkbox"]):not([type="radio"]):not(.button),
#dzs-invoice-creator select,
#dzs-invoice-creator textarea,
#dzs-invoice-creator .ui-autocomplete-input,
#dzs-invoice-creator input.flat,
#dzs-invoice-creator select.flat,
#dzs-invoice-creator .select2-container--default .select2-selection--single {
	border-top-left-radius: var(--dzs-input-radius) !important;
	border-top-right-radius: var(--dzs-input-radius) !important;
	border-bottom-left-radius: var(--dzs-input-radius) !important;
	border-bottom-right-radius: var(--dzs-input-radius) !important;
	border-radius: var(--dzs-input-radius) !important;
}
#dzs-invoice-creator input:not([type="hidden"]):not([type="checkbox"]):not([type="radio"]):not(.button):focus,
#dzs-invoice-creator input:not([type="hidden"]):not([type="checkbox"]):not([type="radio"]):not(.button):focus-visible,
#dzs-invoice-creator input:not([type="hidden"]):not([type="checkbox"]):not([type="radio"]):not(.button):active,
#dzs-invoice-creator select:focus,
#dzs-invoice-creator select:focus-visible,
#dzs-invoice-creator select:active,
#dzs-invoice-creator textarea:focus,
#dzs-invoice-creator textarea:focus-visible,
#dzs-invoice-creator textarea:active,
#dzs-invoice-creator input.ui-corner-top,
#dzs-invoice-creator input.ui-corner-bottom,
#dzs-invoice-creator input.ui-corner-left,
#dzs-invoice-creator input.ui-corner-right,
#dzs-invoice-creator input.ui-corner-all,
#dzs-invoice-creator select.ui-corner-top,
#dzs-invoice-creator select.ui-corner-bottom,
#dzs-invoice-creator select.ui-corner-left,
#dzs-invoice-creator select.ui-corner-right,
#dzs-invoice-creator select.ui-corner-all,
#dzs-invoice-creator textarea.ui-corner-top,
#dzs-invoice-creator textarea.ui-corner-bottom,
#dzs-invoice-creator textarea.ui-corner-left,
#dzs-invoice-creator textarea.ui-corner-right,
#dzs-invoice-creator textarea.ui-corner-all,
#dzs-invoice-creator .select2-container--default.select2-container--open .select2-selection--single,
#dzs-invoice-creator .select2-container--default.select2-container--focus .select2-selection--single {
	border-top-left-radius: var(--dzs-input-radius) !important;
	border-top-right-radius: var(--dzs-input-radius) !important;
	border-bottom-left-radius: var(--dzs-input-radius) !important;
	border-bottom-right-radius: var(--dzs-input-radius) !important;
	border-radius: var(--dzs-input-radius) !important;
}
#dzs-invoice-creator .dzs-line-number {
	min-width: 46px;
	font-weight: 700;
	color: #3f5872;
}
#dzs-invoice-creator .dzs-line-number-wrap {
	display: flex;
	align-items: center;
	justify-content: center;
}
#dzs-invoice-creator .dzs-line-total-wrap {
	display: flex;
	flex-direction: column;
	align-items: flex-end;
	gap: 6px;
	min-width: 128px;
}
#dzs-invoice-creator .dzs-line-total-block {
	display: flex;
	align-items: baseline;
	gap: 6px;
}
#dzs-invoice-creator .dzs-line-total-label {
	font-size: 10px;
	font-weight: 700;
	letter-spacing: 0.04em;
	text-transform: uppercase;
	color: var(--dzs-muted);
}
#dzs-invoice-creator .dzs-line-total {
	min-width: 74px;
	font-weight: 700;
	text-align: right;
	color: #243f59;
}
#dzs-invoice-creator .dzs-row-actions {
	display: flex;
	gap: 6px;
	justify-content: flex-end;
}
#dzs-invoice-creator .dzs-action-btn {
	width: 34px;
	min-width: 34px;
	height: 34px;
	padding: 0;
	font-size: 18px;
	line-height: 1;
}
#dzs-invoice-creator .dzs-line-row.dzs-row-invalid {
	background: var(--dzs-danger-soft);
	border-color: #e3bac0;
}
#dzs-invoice-creator .dzs-toolbar {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
}
#dzs-invoice-creator .dzs-toolbar .button {
	margin: 0;
}
#dzs-invoice-creator .dzs-toolbar #dzs-refresh-keep-draft {
	width: 54px;
	min-width: 54px;
	align-self: stretch;
	box-sizing: border-box;
	padding: 0;
	display: inline-flex;
	align-items: center;
	justify-content: center;
	color: #fff !important;
	line-height: 1;
}
#dzs-invoice-creator .dzs-toolbar #dzs-refresh-keep-draft:active {
	transform: translateY(1px);
}
#dzs-invoice-creator .dzs-toolbar #dzs-refresh-keep-draft:focus,
#dzs-invoice-creator .dzs-toolbar #dzs-refresh-keep-draft:focus-visible {
	outline: none;
	box-shadow: 0 0 0 3px rgba(21, 111, 99, 0.28);
}
#dzs-invoice-creator .dzs-toolbar #dzs-refresh-keep-draft .dzs-refresh-icon {
	width: 25px;
	height: 25px;
	display: block;
}
#dzs-invoice-creator .dzs-toolbar #dzs-refresh-keep-draft .dzs-refresh-icon path {
	stroke: #fff;
}
#dzs-invoice-creator .dzs-visually-hidden {
	position: absolute !important;
	width: 1px !important;
	height: 1px !important;
	padding: 0 !important;
	margin: -1px !important;
	overflow: hidden !important;
	clip: rect(0, 0, 0, 0) !important;
	white-space: nowrap !important;
	border: 0 !important;
}
#dzs-invoice-creator .dzs-toolbar .button.button-save {
	background: var(--dzs-action-grey);
	border-color: var(--dzs-action-grey);
}
#dzs-invoice-creator .dzs-toolbar .button.button-save:hover {
	background: var(--dzs-action-grey-dark);
	border-color: var(--dzs-action-grey-dark);
}
#dzs-invoice-creator .dzs-summary {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
	gap: 10px;
	margin-top: 12px;
}
#dzs-invoice-creator .dzs-summary-item {
	padding: 10px 12px;
	border-radius: 10px;
	border: 1px solid #d2dce5;
	background: #fbfdff;
}
#dzs-invoice-creator .dzs-summary-item span {
	display: block;
	font-size: 11px;
	color: var(--dzs-muted);
	text-transform: uppercase;
	letter-spacing: 0.03em;
}
#dzs-invoice-creator .dzs-summary-item strong {
	display: block;
	font-size: 1.06rem;
	margin-top: 4px;
	color: var(--dzs-heading);
}
#dzs-invoice-creator .dzs-summary-item.dzs-summary-total {
	background: #fff4e2;
	border-color: #efcf9b;
}
.dzs-hidden,
#dzs-invoice-creator .dzs-hidden {
	display: none !important;
}
body.dzs-modal-open {
	overflow: hidden;
}
#dzs-created-pdf-modal {
	position: fixed;
	inset: 0;
	display: flex;
	align-items: center;
	justify-content: center;
	padding: 20px;
	background: rgba(10, 28, 45, 0.48);
	z-index: 1400;
}
#dzs-created-pdf-modal .dzs-modal-panel {
	width: min(100%, 420px);
	background: linear-gradient(180deg, #fffef8 0%, #ffffff 100%);
	border: 1px solid #d8e2eb;
	border-radius: 16px;
	box-shadow: 0 24px 60px rgba(18, 42, 65, 0.22);
	padding: 22px;
}
#dzs-created-pdf-modal .dzs-modal-kicker {
	display: inline-flex;
	align-items: center;
	gap: 6px;
	padding: 5px 10px;
	border-radius: 999px;
	background: #eef8f5;
	border: 1px solid #cbe4dc;
	color: #13584f;
	font-size: 11px;
	font-weight: 700;
	text-transform: uppercase;
	letter-spacing: 0.04em;
}
#dzs-created-pdf-modal .dzs-modal-title {
	margin: 14px 0 8px;
	font-size: 1.2rem;
	font-weight: 700;
	color: var(--dzs-heading);
}
#dzs-created-pdf-modal .dzs-modal-text {
	margin: 0;
	color: #40586f;
	line-height: 1.5;
}
#dzs-created-pdf-modal .dzs-modal-actions {
	display: flex;
	flex-wrap: wrap;
	gap: 10px;
	margin-top: 18px;
}
#dzs-created-pdf-modal .dzs-modal-actions .button {
	margin: 0;
}
#dzs-created-pdf-modal .dzs-modal-actions .button.button-save {
	background: var(--dzs-primary);
	border-color: var(--dzs-primary);
}
#dzs-created-pdf-modal .dzs-modal-actions .button.button-save:hover {
	background: var(--dzs-primary-dark);
	border-color: var(--dzs-primary-dark);
}
@keyframes dzsRise {
	from { opacity: 0; transform: translateY(8px); }
	to { opacity: 1; transform: translateY(0); }
}
@media (max-width: 1050px) {
	#dzs-invoice-creator .dzs-grid-3 {
		grid-template-columns: repeat(2, minmax(170px, 1fr));
	}
	#dzs-invoice-creator .dzs-line-grid {
		grid-template-columns: repeat(3, minmax(150px, 1fr));
	}
	#dzs-invoice-creator .dzs-line-composer-action {
		grid-column: span 3;
	}
}
@media (max-width: 760px) {
	#dzs-invoice-creator {
		padding: 12px;
	}
	#dzs-invoice-creator .dzs-grid,
	#dzs-invoice-creator .dzs-grid-3 {
		grid-template-columns: 1fr;
	}
	#dzs-invoice-creator .dzs-summary {
		grid-template-columns: 1fr;
	}
	#dzs-invoice-creator .dzs-toolbar {
		flex-direction: column;
		align-items: stretch;
	}
	#dzs-invoice-creator .dzs-line-grid {
		grid-template-columns: 1fr;
	}
	#dzs-invoice-creator .dzs-line-composer-action {
		grid-column: auto;
	}
	#dzs-created-pdf-modal .dzs-modal-actions {
		flex-direction: column;
	}
}
</style>';

if ($createdDocumentPdfUrl !== '') {
	$createdPdfMessage = (strpos($txtCreatedPdfMessage, '%s') !== false)
		? sprintf($txtCreatedPdfMessage, ($createdDocumentRef !== '' ? $createdDocumentRef : '#'))
		: ($txtCreatedPdfMessage . ' ' . ($createdDocumentRef !== '' ? $createdDocumentRef : '#'));
	print '<div id="dzs-created-pdf-modal" role="dialog" aria-modal="true" aria-labelledby="dzs-created-pdf-title">';
	print '<div class="dzs-modal-panel">';
	print '<span class="dzs-modal-kicker">' . dzs_h($txtCreatedPdfKicker) . '</span>';
	print '<div class="dzs-modal-title" id="dzs-created-pdf-title">' . dzs_h($txtCreatedPdfTitle) . '</div>';
	print '<p class="dzs-modal-text">' . dzs_h($createdPdfMessage) . '</p>';
	print '<div class="dzs-modal-actions">';
	print '<a class="button button-save" id="dzs-open-created-pdf" href="' . dzs_h($createdDocumentPdfUrl) . '" target="_blank" rel="noopener">' . dzs_h($txtOpenPdf) . '</a>';
	print '<button type="button" class="button" id="dzs-close-created-pdf-modal">' . dzs_h($txtLater) . '</button>';
	print '</div>';
	print '</div>';
	print '</div>';
}
?>
	<div id="dzs-invoice-creator"<?php print (!$showWarehouseInput ? ' class="dzs-no-warehouse"' : ''); ?>>
	<form method="post" action="<?php print $formAction; ?>" id="dzs-invoice-form">
		<input type="hidden" name="token" value="<?php print newToken(); ?>">
		<input type="hidden" name="manual_submit_intent" value="1">
		<input type="hidden" name="manual_submit_key" value="<?php print dzs_h($manualSubmitKey); ?>">
		<input type="hidden" name="origin_invoice_id" value="<?php print ((int) $originInvoiceId); ?>">
		<?php if ($backtopage !== '') { ?>
			<input type="hidden" name="backtopage" value="<?php print dzs_h($backtopage); ?>">
		<?php } ?>

		<div class="dzs-card">
			<div class="dzs-card-head">
				<div class="dzs-card-title"><?php print dzs_h($txtInvoiceHeader); ?></div>
				<div class="dzs-pills">
					<span class="dzs-pill"><?php print dzs_h($txtDocumentType); ?>: <strong id="dzs-pill-doc-type"><?php print dzs_h($currentDocTypeLabel); ?></strong></span>
					<span class="dzs-pill"><?php print dzs_h($txtRows); ?>: <strong id="dzs-pill-rows"><?php print ((int) $lineCount); ?></strong></span>
				</div>
			</div>
				<div class="dzs-grid dzs-grid-3">
					<div class="dzs-field">
						<label for="dzs-doc-type"><?php print $langs->trans("DocType"); ?></label>
						<select name="doc_type" id="dzs-doc-type">
							<option value="FT"<?php print ($docType === 'FT' ? ' selected' : ''); ?>><?php print dzs_h($docTypeLabels['FT'] ?? 'FT'); ?></option>
							<option value="VD"<?php print ($docType === 'VD' ? ' selected' : ''); ?>><?php print dzs_h($docTypeLabels['VD'] ?? 'VD'); ?></option>
							<option value="EC"<?php print ($docType === 'EC' ? ' selected' : ''); ?>><?php print dzs_h($docTypeLabels['EC'] ?? 'EC'); ?></option>
						</select>
					</div>
					<div class="dzs-field">
						<label for="search_socid"><?php print dzs_h($txtClientSelect); ?></label>
						<?php print $clientSelectHtml; ?>
					</div>
					<div class="dzs-field">
						<label for="dzs-client-vat"><?php print dzs_h($txtClientVat); ?></label>
						<input type="text" id="dzs-client-vat" value="<?php print dzs_h($selectedClientVat); ?>" autocomplete="off">
					</div>
					<div class="dzs-field">
						<label for="dzs-payment-type"><?php print dzs_h($txtPaymentType); ?></label>
						<select id="dzs-payment-type" name="payment_type" class="flat dzs-select-payment-type" data-placeholder="<?php print dzs_h($txtPaymentTypeEmpty); ?>">
							<option value=""<?php print ($paymentType <= 0 ? ' selected' : ''); ?>><?php print dzs_h($txtPaymentTypeEmpty); ?></option>
							<?php foreach ($paymentTypeOptions as $paymentTypeId => $paymentTypeName) { ?>
								<option value="<?php print ((int) $paymentTypeId); ?>"<?php print (((int) $paymentType === (int) $paymentTypeId) ? ' selected' : ''); ?>><?php print dzs_h($paymentTypeName); ?></option>
							<?php } ?>
						</select>
						<?php if (!$hasSyncedPaymentTypeOptions) { ?>
							<span class="opacitymedium small"><?php print dzs_h($txtPaymentTypeMissing); ?></span>
						<?php } ?>
					</div>
						<div class="dzs-field">
							<label for="dzs-emp">EMP *</label>
							<select id="dzs-emp" name="emp" class="flat dzs-select-emp" data-placeholder="<?php print dzs_h($txtEmployeeSelect); ?>" required<?php print (!$hasStoreEmployeeOptions ? ' disabled' : ''); ?>>
								<option value=""><?php print dzs_h($txtEmployeeSelect); ?></option>
								<?php foreach ($employeeOptions as $employeeId => $employeeLabel) { ?>
									<option value="<?php print ((int) $employeeId); ?>"<?php print (((int) $emp === (int) $employeeId) ? ' selected' : ''); ?>>
										<?php print dzs_h($employeeLabel); ?>
									</option>
								<?php } ?>
					</select>
					<?php if (!$hasStoreEmployeeOptions) { ?>
						<span class="opacitymedium small"><?php print dzs_h($txtEmployeeMissing); ?></span>
					<?php } ?>
				</div>
			</div>
			</div>

			<div class="dzs-card">
				<div class="dzs-card-head">
					<div class="dzs-card-title"><?php print dzs_h($txtEntityData); ?></div>
				</div>
				<div class="dzs-grid dzs-grid-3">
					<div class="dzs-field">
						<label for="dzs-client-address">Morada</label>
						<input type="text" id="dzs-client-address" value="<?php print dzs_h($selectedClientAddress); ?>" readonly>
					</div>
					<div class="dzs-field">
						<label for="dzs-client-zip">Codigo Postal</label>
						<input type="text" id="dzs-client-zip" value="<?php print dzs_h($selectedClientZip); ?>" readonly>
					</div>
					<div class="dzs-field">
						<label for="dzs-client-town">Localidade</label>
						<input type="text" id="dzs-client-town" value="<?php print dzs_h($selectedClientTown); ?>" readonly>
					</div>
					<div class="dzs-field">
						<label for="dzs-client-country">Pais</label>
						<input type="text" id="dzs-client-country" value="<?php print dzs_h($selectedClientCountry); ?>" readonly>
					</div>
					<div class="dzs-field">
						<label for="dzs-client-email">Email</label>
						<input type="text" id="dzs-client-email" value="<?php print dzs_h($selectedClientEmail); ?>" readonly>
					</div>
				</div>
			</div>

			<div class="dzs-card">
				<div class="dzs-card-head">
					<div class="dzs-card-title"><?php print dzs_h($txtDeliveryData); ?></div>
				</div>
			<div class="dzs-grid">
				<div class="dzs-field">
					<label for="dzs-delivery-morada"><?php print $langs->trans("DeliveryAddress"); ?></label>
					<input type="text" id="dzs-delivery-morada" name="delivery_morada" value="<?php print dzs_h($deliveryMorada); ?>">
				</div>
				<div class="dzs-field">
					<label for="dzs-delivery-contacto"><?php print $langs->trans("DeliveryContact"); ?></label>
					<input type="text" id="dzs-delivery-contacto" name="delivery_contacto" value="<?php print dzs_h($deliveryContacto); ?>">
				</div>
				<div class="dzs-field">
					<label for="dzs-delivery-dataentrega"><?php print $langs->trans("DeliveryDate"); ?></label>
					<input type="date" id="dzs-delivery-dataentrega" name="delivery_dataentrega" value="<?php print dzs_h($deliveryDataentrega); ?>">
					<input type="hidden" id="dzs-delivery-dataentrega-touched" name="delivery_dataentrega_touched" value="<?php print (((int) $deliveryDataentregaTouched > 0) ? '1' : '0'); ?>">
				</div>
				<div class="dzs-field">
					<label for="dzs-delivery-obs"><?php print $langs->trans("Notes"); ?></label>
					<input type="text" id="dzs-delivery-obs" name="delivery_obs" value="<?php print dzs_h($deliveryObs); ?>">
				</div>
				<div class="dzs-field" data-field="pickup">
					<label for="dzs-levantamento"><?php print $langs->trans("PickupDate"); ?></label>
					<input type="date" id="dzs-levantamento" name="levantamento" value="<?php print dzs_h($levantamento); ?>" readonly>
				</div>
			</div>
		</div>

			<div class="dzs-card">
					<div class="dzs-card-head">
						<div class="dzs-card-title"><?php print dzs_h($txtInvoiceLines); ?></div>
					</div>

						<div class="dzs-lines-wrap">
						<div class="dzs-lines-head">
							<div class="center"><?php print dzs_h($txtLine); ?></div>
							<div><?php print dzs_h($txtReference); ?></div>
							<div><?php print dzs_h($txtDescription); ?></div>
							<div><?php print dzs_h($txtUnitPriceShort); ?></div>
							<div><?php print dzs_h($txtQtyShort); ?></div>
							<div><?php print dzs_h($txtDiscountShort); ?> (%)</div>
							<?php if ($showWarehouseInput) { ?>
								<div><?php print $langs->trans("LineWarehouse"); ?></div>
							<?php } ?>
							<div class="right"><?php print dzs_h($txtTotalHtTtc); ?></div>
							<div class="right"><?php print $langs->trans("Action"); ?></div>
						</div>
						<div class="dzs-lines-list" id="dzs-lines-list">
								<?php for ($idx = 0; $idx < $lineCount; $idx++) {
									$codigoVal = dzs_line_val($lineCodigo, $idx);
									$productIdVal = trim((string) dzs_line_val($lineProductId, $idx));
									if ($productIdVal !== '' && !ctype_digit($productIdVal)) {
										$productIdVal = '';
									}
									$productIdInt = ($productIdVal !== '' ? ((int) $productIdVal) : 0);
									$productCardHref = ($productIdInt > 0 ? (DOL_URL_ROOT . '/product/card.php?id=' . $productIdInt) : '');
									$productCardDisabledClass = ($productIdInt > 0 ? '' : ' is-disabled');
									$productCardTitle = ($productIdInt > 0 ? dzs_t($langs, "ShowProduct", "Open product") : '');
									$qtdVal = dzs_line_val($lineQtd, $idx);
									if (trim((string) $qtdVal) === '') {
										$qtdVal = '1';
									}
								$punitVal = dzs_line_val($linePunit, $idx);
								$descontoVal = dzs_line_val($lineDesconto, $idx);
								$vatTxVal = dzs_line_val($lineVatTx, $idx);
								if (trim((string) $descontoVal) === '') {
									$descontoVal = '0';
								}
								$obsVal = dzs_line_val($lineObs, $idx);
								$armazemVal = dzs_line_val($lineArmazem, $idx);
								$armazemVal = trim((string) $armazemVal);
								if ($armazemVal === '' && $defaultWarehouseId > 0) {
									$armazemVal = (string) $defaultWarehouseId;
								}
							?>
									<div class="<?php print (($idx % 2 === 0) ? 'oddeven' : 'oddevenpair'); ?> dzs-line-row">
										<div class="dzs-line-number-wrap">
											<span class="dzs-line-number"><?php print ((int) ($idx + 1)); ?></span>
										</div>
											<div>
												<input type="text" name="line_codigo[]" class="flat dzs-input-codigo dzs-ref-link<?php print $productCardDisabledClass; ?>" value="<?php print dzs_h($codigoVal); ?>" autocomplete="off" data-default="" data-product-href="<?php print dzs_h($productCardHref); ?>" title="<?php print dzs_h($productCardTitle); ?>" readonly>
												<input type="hidden" name="line_product_id[]" class="dzs-input-product-id" value="<?php print dzs_h($productIdVal); ?>" data-default="">
												<input type="hidden" name="line_vat_tx[]" class="dzs-input-vat-tx" value="<?php print dzs_h($vatTxVal); ?>" data-default="0">
											</div>
									<div>
										<input type="text" name="line_obs[]" class="flat dzs-input-obs" value="<?php print dzs_h($obsVal); ?>" autocomplete="off" data-default="">
									</div>
									<div>
										<input type="text" name="line_punit[]" class="flat dzs-input-punit" value="<?php print dzs_h($punitVal); ?>" placeholder="0.00" data-default="">
									</div>
									<div>
										<input type="text" name="line_qtd[]" class="flat dzs-input-qtd" value="<?php print dzs_h($qtdVal); ?>" placeholder="1" data-default="1">
									</div>
									<div>
											<input type="number" name="line_desconto[]" class="flat dzs-input-desconto" value="<?php print dzs_h($descontoVal); ?>" placeholder="0" min="0" max="100" step="1" data-default="0">
									</div>
									<?php if ($showWarehouseInput) { ?>
										<div>
											<select name="line_armazem[]" class="flat dzs-input-armazem" data-default="<?php print dzs_h($defaultWarehouseId > 0 ? (string) $defaultWarehouseId : ''); ?>">
												<?php if ($defaultWarehouseId > 0 && isset($warehouseOptions[$defaultWarehouseId])) { ?>
													<option value="<?php print ((int) $defaultWarehouseId); ?>" selected>
														<?php print dzs_h($warehouseOptions[$defaultWarehouseId]); ?>
													</option>
												<?php } else { ?>
													<?php foreach ($warehouseOptions as $warehouseId => $warehouseLabel) { ?>
														<option value="<?php print ((int) $warehouseId); ?>"<?php print (((string) $armazemVal === (string) ((int) $warehouseId)) ? ' selected' : ''); ?>>
															<?php print dzs_h($warehouseLabel); ?>
														</option>
													<?php } ?>
												<?php } ?>
											</select>
										</div>
									<?php } else { ?>
										<input type="hidden" name="line_armazem[]" class="dzs-input-armazem" value="<?php print dzs_h($armazemVal); ?>" data-default="<?php print dzs_h($defaultWarehouseId > 0 ? (string) $defaultWarehouseId : ''); ?>">
									<?php } ?>
									<div class="dzs-line-total-wrap">
										<div class="dzs-line-total-block">
											<span class="dzs-line-total-label"><?php print dzs_h($txtHTLineLabel); ?></span>
											<span class="dzs-line-total dzs-line-total-ht" data-total-ht="0">0.00</span>
										</div>
										<div class="dzs-line-total-block">
											<span class="dzs-line-total-label"><?php print dzs_h($txtTTCLineLabel); ?><span class="dzs-line-vat-hint"></span></span>
											<span class="dzs-line-total dzs-line-total-ttc" data-total-ttc="0">0.00</span>
										</div>
									</div>
									<div class="dzs-row-actions">
										<button type="button" class="button dzs-action-btn dzs-insert-line" title="<?php print dzs_h($langs->trans("AddLine")); ?>">+</button>
										<button type="button" class="button button-delete dzs-action-btn dzs-remove-line" title="<?php print dzs_h($langs->trans("RemoveLine")); ?>">-</button>
									</div>
								</div>
							<?php } ?>
						</div>
				</div>

			<div class="dzs-summary">
				<div class="dzs-summary-item">
					<span><?php print dzs_h($txtActiveLines); ?></span>
					<strong id="dzs-summary-lines">0</strong>
				</div>
				<div class="dzs-summary-item">
					<span><?php print dzs_h($txtTotalQuantity); ?></span>
					<strong id="dzs-summary-qty">0.000</strong>
				</div>
				<div class="dzs-summary-item dzs-summary-total">
					<span><?php print dzs_h($txtEstimatedAmountHt); ?></span>
					<strong id="dzs-summary-amount-ht">0.00</strong>
				</div>
				<div class="dzs-summary-item dzs-summary-total">
					<span><?php print dzs_h($txtEstimatedAmountTtc); ?></span>
					<strong id="dzs-summary-amount-ttc">0.00</strong>
				</div>
			</div>
		</div>

		<div class="dzs-card">
			<div class="dzs-toolbar">
				<button type="button" class="button button-save" id="dzs-refresh-keep-draft" title="<?php print dzs_h($txtRefreshKeepDraftTitle); ?>" aria-label="<?php print dzs_h($txtRefreshKeepDraftTitle); ?>">
					<svg class="dzs-refresh-icon" viewBox="0 0 24 24" fill="none" aria-hidden="true" focusable="false">
						<path d="M21 12a9 9 0 0 1-15.36 6.36" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"></path>
						<path d="M3 12A9 9 0 0 1 18.36 5.64" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"></path>
						<path d="M18.5 2.75v3.5h-3.5" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"></path>
						<path d="M5.5 21.25v-3.5h3.5" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"></path>
					</svg>
					<span class="dzs-visually-hidden"><?php print dzs_h($txtRefreshKeepDraftTitle); ?></span>
				</button>
				<input type="submit" class="button button-save" name="createManualZsbmsInvoice" value="<?php print dzs_h($langs->trans("CreateInvoiceInZSBMS")); ?>">
			</div>
		</div>
	</form>
</div>

<script nonce="<?php print $nonce; ?>">
document.addEventListener("DOMContentLoaded", function () {
	var form = document.getElementById("dzs-invoice-form");
	var linesList = document.getElementById("dzs-lines-list");
	var docTypeSelect = document.getElementById("dzs-doc-type");
	var docTypePill = document.getElementById("dzs-pill-doc-type");
	var clientIdInput = document.getElementById("socid");
	var clientSearchInput = document.getElementById("search_socid");
	var clientVatInput = document.getElementById("dzs-client-vat");
	var clientAddressInput = document.getElementById("dzs-client-address");
	var clientZipInput = document.getElementById("dzs-client-zip");
	var clientTownInput = document.getElementById("dzs-client-town");
	var clientCountryInput = document.getElementById("dzs-client-country");
	var clientEmailInput = document.getElementById("dzs-client-email");
	var clientVatLookupUrl = <?php print json_encode($clientVatLookupUrl); ?>;
	var clientVatSearchUrl = <?php print json_encode($clientVatSearchUrl); ?>;
	var clientVatResolveUrl = <?php print json_encode($clientVatResolveUrl); ?>;
	var productSearchUrl = <?php print json_encode($productSearchUrl); ?>;
	var productCardBaseUrl = <?php print json_encode(DOL_URL_ROOT . '/product/card.php?id='); ?>;
	var docTypeLabels = <?php print json_encode($docTypeLabels); ?>;
	var openProductTitle = <?php print json_encode(dzs_t($langs, "ShowProduct", "Open product")); ?>;
	var defaultWarehouseId = <?php print ((int) $defaultWarehouseId); ?>;
	var showWarehouseInput = <?php print ($showWarehouseInput ? 'true' : 'false'); ?>;
	var invoicePricesIncludeVat = <?php print ($ivaIncluido ? 'true' : 'false'); ?>;
	var unitPriceDecimals = <?php print ((int) $unitPriceDecimals); ?>;
	var unitPriceMinDecimals = 2;
	var unitPriceMaxDecimals = Math.max(unitPriceDecimals, unitPriceMinDecimals);
	var composerCodigoInput = document.getElementById("dzs-composer-codigo");
	var composerObsInput = document.getElementById("dzs-composer-obs");
	var composerPunitInput = document.getElementById("dzs-composer-punit");
	var composerQtdInput = document.getElementById("dzs-composer-qtd");
	var composerDescontoInput = document.getElementById("dzs-composer-desconto");
	var composerArmazemInput = document.getElementById("dzs-composer-armazem");
	var composerAddBtn = document.getElementById("dzs-composer-add");
	var rowsPill = document.getElementById("dzs-pill-rows");
	var summaryLines = document.getElementById("dzs-summary-lines");
	var summaryQty = document.getElementById("dzs-summary-qty");
	var summaryAmountHt = document.getElementById("dzs-summary-amount-ht");
	var summaryAmountTtc = document.getElementById("dzs-summary-amount-ttc");
	var refreshKeepDraftBtn = document.getElementById("dzs-refresh-keep-draft");
	var paymentTypeSelect = document.getElementById("dzs-payment-type");
	var empSelect = document.getElementById("dzs-emp");
	var pickupField = form ? form.querySelector('[data-field="pickup"]') : null;
	var pickupDateInput = document.getElementById("dzs-levantamento");
	var deliveryDateInput = document.getElementById("dzs-delivery-dataentrega");
	var deliveryDateTouchedInput = document.getElementById("dzs-delivery-dataentrega-touched");
	var createdPdfModal = document.getElementById("dzs-created-pdf-modal");
	var openCreatedPdfBtn = document.getElementById("dzs-open-created-pdf");
	var closeCreatedPdfModalBtn = document.getElementById("dzs-close-created-pdf-modal");
	var messages = <?php print json_encode($jsMessages); ?>;
	var submitLocked = false;

	if (!form || !linesList) {
		return;
	}

	var creatorRoot = document.getElementById("dzs-invoice-creator");
	var roundedRadiusPx = "8px";
	var roundedSelector = 'input:not([type="hidden"]):not([type="checkbox"]):not([type="radio"]):not(.button), select, textarea, .ui-autocomplete-input, .select2-selection--single, .select2-selection--multiple, .select2-search__field';
	var refreshDraftStorageKey = "dzs_invoice_creator_refresh_draft_v1:" + String(window.location.pathname || "") + ":" + String(window.location.search || "");
	var refreshDraftPendingKey = refreshDraftStorageKey + ":pending";

	function setCreatedPdfModalOpen(isOpen) {
		if (!createdPdfModal) return;
		createdPdfModal.classList.toggle("dzs-hidden", !isOpen);
		document.body.classList.toggle("dzs-modal-open", !!isOpen);
		if (isOpen && openCreatedPdfBtn) {
			window.setTimeout(function () {
				openCreatedPdfBtn.focus();
			}, 0);
		}
	}

	function isRoundedControl(el) {
		if (!(el instanceof HTMLElement)) return false;
		if (!creatorRoot || !creatorRoot.contains(el)) return false;
		if (el.classList.contains("button")) return false;
		if (el.matches('input[type="hidden"], input[type="checkbox"], input[type="radio"]')) return false;
		return el.matches(roundedSelector);
	}

	function enforceRoundedControl(el) {
		if (!isRoundedControl(el)) return;
		el.style.setProperty("border-top-left-radius", roundedRadiusPx, "important");
		el.style.setProperty("border-top-right-radius", roundedRadiusPx, "important");
		el.style.setProperty("border-bottom-left-radius", roundedRadiusPx, "important");
		el.style.setProperty("border-bottom-right-radius", roundedRadiusPx, "important");
		el.style.setProperty("border-radius", roundedRadiusPx, "important");
	}

	function enforceRoundedFromNode(node) {
		if (!(node instanceof HTMLElement)) return;
		if (isRoundedControl(node)) {
			enforceRoundedControl(node);
		}
		if (typeof node.querySelectorAll === "function") {
			node.querySelectorAll(roundedSelector).forEach(enforceRoundedControl);
		}
	}

	function enforceRoundedControls() {
		if (!creatorRoot) return;
		enforceRoundedFromNode(creatorRoot);
	}

	function getRows() {
		return Array.prototype.slice.call(linesList.querySelectorAll(".dzs-line-row"));
	}

	function getInput(row, name) {
		return row.querySelector('input[name="' + name + '"], select[name="' + name + '"], textarea[name="' + name + '"]');
	}

	function escapeAttributeValue(value) {
		return String(value || "").replace(/\\/g, "\\\\").replace(/"/g, '\\"');
	}

	function canUseSessionStorage() {
		try {
			if (!window.sessionStorage) {
				return false;
			}
			var testKey = "__dzs_refresh_test__";
			window.sessionStorage.setItem(testKey, "1");
			window.sessionStorage.removeItem(testKey);
			return true;
		} catch (e) {
			return false;
		}
	}

	function buildRefreshDraftPayload() {
		var payload = {
			version: 1,
			created_at: Date.now(),
			values: {}
		};

		form.querySelectorAll("input[name], select[name], textarea[name]").forEach(function (field) {
			if (!(field instanceof HTMLInputElement || field instanceof HTMLSelectElement || field instanceof HTMLTextAreaElement)) {
				return;
			}

			var fieldName = String(field.name || "").trim();
			if (fieldName === "" || fieldName === "token" || fieldName === "manual_submit_key") {
				return;
			}
			if (field.disabled) {
				return;
			}

			if (!Object.prototype.hasOwnProperty.call(payload.values, fieldName)) {
				payload.values[fieldName] = [];
			}

			if (field instanceof HTMLInputElement && (field.type === "checkbox" || field.type === "radio")) {
				if (field.checked) {
					payload.values[fieldName].push(String(field.value || ""));
				}
				return;
			}

			payload.values[fieldName].push(String(field.value || ""));
		});

		return payload;
	}

	function ensureLineRowCount(targetCount) {
		var wanted = parseInt(String(targetCount || "1"), 10);
		if (!isFinite(wanted) || wanted < 1) {
			wanted = 1;
		}

		var rows = getRows();
		if (!rows.length) {
			return;
		}

		while (rows.length < wanted) {
			var baseRow = rows[rows.length - 1];
			var clone = baseRow.cloneNode(true);
			resetRowInputs(clone);
			linesList.appendChild(clone);
			rows.push(clone);
		}

		while (rows.length > wanted && rows.length > 1) {
			var removed = rows.pop();
			if (removed && removed.parentNode) {
				removed.parentNode.removeChild(removed);
			}
		}
	}

	function saveDraftForRefresh() {
		if (!canUseSessionStorage()) {
			return false;
		}

		try {
			var payload = buildRefreshDraftPayload();
			window.sessionStorage.setItem(refreshDraftStorageKey, JSON.stringify(payload));
			window.sessionStorage.setItem(refreshDraftPendingKey, "1");
			return true;
		} catch (e) {
			return false;
		}
	}

	function clearDraftForRefresh() {
		if (!canUseSessionStorage()) {
			return;
		}
		try {
			window.sessionStorage.removeItem(refreshDraftPendingKey);
			window.sessionStorage.removeItem(refreshDraftStorageKey);
		} catch (e) {
			// Ignore storage cleanup errors.
		}
	}

	function restoreDraftAfterRefresh() {
		if (!canUseSessionStorage()) {
			return false;
		}

		var pending = "";
		try {
			pending = String(window.sessionStorage.getItem(refreshDraftPendingKey) || "");
		} catch (e) {
			return false;
		}
		if (pending !== "1") {
			return false;
		}

		var rawPayload = "";
		try {
			rawPayload = String(window.sessionStorage.getItem(refreshDraftStorageKey) || "");
		} catch (e) {
			clearDraftForRefresh();
			return false;
		}
		if (rawPayload === "") {
			clearDraftForRefresh();
			return false;
		}

		var parsed = null;
		try {
			parsed = JSON.parse(rawPayload);
		} catch (e) {
			clearDraftForRefresh();
			return false;
		}
		if (!parsed || typeof parsed !== "object" || typeof parsed.values !== "object" || parsed.values === null) {
			clearDraftForRefresh();
			return false;
		}

		var valueMap = parsed.values;
		var lineFieldNames = [
			"line_codigo[]",
			"line_product_id[]",
			"line_vat_tx[]",
			"line_obs[]",
			"line_punit[]",
			"line_qtd[]",
			"line_desconto[]",
			"line_armazem[]"
		];
		var targetRows = 1;
		lineFieldNames.forEach(function (fieldName) {
			var fieldValues = valueMap[fieldName];
			if (Array.isArray(fieldValues) && fieldValues.length > targetRows) {
				targetRows = fieldValues.length;
			}
		});
		ensureLineRowCount(targetRows);

		Object.keys(valueMap).forEach(function (fieldName) {
			var fieldValues = valueMap[fieldName];
			if (!Array.isArray(fieldValues)) {
				return;
			}

			var selector = '[name="' + escapeAttributeValue(fieldName) + '"]';
			var fields = form.querySelectorAll(selector);
			if (!fields.length) {
				return;
			}

			if (fieldName.indexOf("line_") === 0 && fields.length < fieldValues.length) {
				ensureLineRowCount(fieldValues.length);
				fields = form.querySelectorAll(selector);
			}

			fields.forEach(function (field, index) {
				var value = (index < fieldValues.length) ? String(fieldValues[index] || "") : "";
				if (field instanceof HTMLInputElement && (field.type === "checkbox" || field.type === "radio")) {
					field.checked = fieldValues.indexOf(String(field.value || "")) !== -1;
					return;
				}
				field.value = value;
			});
		});

		clearDraftForRefresh();
		return true;
	}

	function focusAndSelectInput(input) {
		if (!(input instanceof HTMLElement)) return;
		if (input.disabled) return;
		input.focus();
		if (input instanceof HTMLInputElement || input instanceof HTMLTextAreaElement) {
			if (typeof input.select === "function") {
				input.select();
			}
		}
	}

	function normalizeRawNumber(value) {
		var txt = String(value === null || typeof value === "undefined" ? "" : value).trim();
		if (txt === "") return "";
		txt = txt.replace(/\s+/g, "");
		if (txt.indexOf(",") !== -1 && txt.indexOf(".") !== -1) {
			if (txt.lastIndexOf(",") > txt.lastIndexOf(".")) {
				txt = txt.replace(/\./g, "");
				txt = txt.replace(/,/g, ".");
			} else {
				txt = txt.replace(/,/g, "");
			}
		} else {
			txt = txt.replace(/,/g, ".");
		}
		return txt;
	}

	function tokenizeCalculationExpression(value) {
		var raw = String(value === null || typeof value === "undefined" ? "" : value);
		var tokens = [];
		var offset = 0;
		var pattern = /^\s*([+\-*\/()]|(?:\d+(?:[.,]\d*)?|[.,]\d+))\s*/;
		while (offset < raw.length) {
			var match = pattern.exec(raw.slice(offset));
			if (!match) {
				return null;
			}
			var token = match[1];
			if (/^[\d.,]+$/.test(token)) {
				token = token.replace(/,/g, ".");
				if (!isFinite(Number(token))) {
					return null;
				}
			}
			tokens.push(token);
			offset += match[0].length;
		}
		return tokens;
	}

	function calculateDecimalExpression(value) {
		var raw = String(value === null || typeof value === "undefined" ? "" : value).trim();
		if (raw === "") {
			return { ok: true, value: 0 };
		}

		var tokens = tokenizeCalculationExpression(raw);
		if (!tokens || !tokens.length) {
			return { ok: false, value: 0 };
		}

		var pos = 0;
		function parseExpression() {
			var value = parseTerm();
			if (value === null) return null;
			while (pos < tokens.length && (tokens[pos] === "+" || tokens[pos] === "-")) {
				var operator = tokens[pos++];
				var right = parseTerm();
				if (right === null) return null;
				value = (operator === "+") ? (value + right) : (value - right);
			}
			return value;
		}
		function parseTerm() {
			var value = parseFactor();
			if (value === null) return null;
			while (pos < tokens.length && (tokens[pos] === "*" || tokens[pos] === "/")) {
				var operator = tokens[pos++];
				var right = parseFactor();
				if (right === null) return null;
				if (operator === "*") {
					value *= right;
				} else {
					if (Math.abs(right) < 0.000000000001) return null;
					value /= right;
				}
			}
			return value;
		}
		function parseFactor() {
			if (pos >= tokens.length) return null;
			var token = tokens[pos];
			if (token === "+") {
				pos++;
				return parseFactor();
			}
			if (token === "-") {
				pos++;
				var negated = parseFactor();
				return negated === null ? null : -negated;
			}
			if (token === "(") {
				pos++;
				var inner = parseExpression();
				if (inner === null || pos >= tokens.length || tokens[pos] !== ")") return null;
				pos++;
				return inner;
			}
			if (isFinite(Number(token))) {
				pos++;
				return Number(token);
			}
			return null;
		}

		var result = parseExpression();
		if (result === null || pos !== tokens.length || !isFinite(result)) {
			return { ok: false, value: 0 };
		}
		return { ok: true, value: result };
	}

	function parseNumber(value) {
		var calculated = calculateDecimalExpression(value);
		return calculated.ok ? calculated.value : 0;
	}

	function formatNumber(value, decimals) {
		var safe = Number(value);
		if (!isFinite(safe)) safe = 0;
		return safe.toLocaleString(undefined, {
			minimumFractionDigits: decimals,
			maximumFractionDigits: decimals
		});
	}

	function serializeNumber(value, decimals, minDecimals) {
		var safe = Number(value);
		if (!isFinite(safe)) safe = 0;
		var txt = safe.toFixed(decimals);
		var minDec = parseInt(String(minDecimals || 0), 10);
		if (!isFinite(minDec) || minDec < 0) {
			minDec = 0;
		}

		if (minDec <= 0) {
			return txt.replace(/\.?0+$/, "");
		}

		var dotPos = txt.indexOf(".");
		if (dotPos === -1) {
			return txt;
		}
		while (txt.endsWith("0") && (txt.length - dotPos - 1) > minDec) {
			txt = txt.slice(0, -1);
		}
		if (txt.endsWith(".")) {
			txt = txt.slice(0, -1);
		}
		return txt;
	}

	function normalizeCalculationInput(input, decimals, minDecimals) {
		if (!(input instanceof HTMLInputElement)) return true;
		var raw = String(input.value || "").trim();
		if (raw === "") return true;
		var calculated = calculateDecimalExpression(raw);
		if (!calculated.ok) {
			return false;
		}
		input.value = serializeNumber(calculated.value, decimals, minDecimals || 0);
		return true;
	}

	function toggleFieldVisibility(fieldWrapper, show) {
		if (!fieldWrapper) return;
		fieldWrapper.classList.toggle("dzs-hidden", !show);
		fieldWrapper.querySelectorAll("input, select, textarea").forEach(function (input) {
			input.disabled = !show;
		});
	}

	function syncDocTypeVisibility() {
		if (!docTypeSelect) return;
		var selected = String(docTypeSelect.value || "").toUpperCase();
		var isWorkingDocument = (selected === "EC");
		toggleFieldVisibility(pickupField, isWorkingDocument);
		if (docTypePill) {
			docTypePill.textContent = docTypeLabels[selected] || selected || "-";
		}
	}

	function setClientVat(value) {
		if (!clientVatInput) return;
		clientVatInput.value = String(value || "");
	}

	function setClientFieldValue(input, value) {
		if (!input) return;
		input.value = String(value || "");
	}

	function setClientDetails(data) {
		var details = data || {};
		setClientFieldValue(clientAddressInput, details.address || "");
		setClientFieldValue(clientZipInput, details.zip || "");
		setClientFieldValue(clientTownInput, details.town || "");
		setClientFieldValue(clientCountryInput, details.country || "");
		setClientFieldValue(clientEmailInput, details.email || "");
	}

	function normalizeVatKey(value) {
		return String(value || "")
			.toUpperCase()
			.replace(/[^A-Z0-9]/g, "");
	}

	var vatSuggestionsByKey = Object.create(null);
	var vatSearchToken = 0;
	var clientDataBySocidCache = Object.create(null);
	var clientVatFetchToken = 0;
	var lastClientSocid = "";

	function storeVatSuggestion(item) {
		if (!item || !item.vat) return;
		var rawVat = String(item.vat || "").trim();
		var keyRaw = rawVat.toUpperCase();
		var keyNorm = normalizeVatKey(rawVat);
		vatSuggestionsByKey[keyRaw] = item;
		if (keyNorm) {
			vatSuggestionsByKey[keyNorm] = item;
		}
	}

	function fetchClientVatBySocid(socid) {
		if (!clientVatLookupUrl) return;
		var numericSocid = parseInt(String(socid || "0"), 10);
		if (!isFinite(numericSocid) || numericSocid <= 0) {
			setClientVat("");
			setClientDetails({});
			return;
		}
		var cacheKey = String(numericSocid);
		if (Object.prototype.hasOwnProperty.call(clientDataBySocidCache, cacheKey)) {
			setClientVat(clientDataBySocidCache[cacheKey].vat || "");
			setClientDetails(clientDataBySocidCache[cacheKey]);
			return;
		}

		var currentFetchToken = ++clientVatFetchToken;
		var url = clientVatLookupUrl + "&socid=" + encodeURIComponent(String(numericSocid));
		fetch(url, {
			credentials: "same-origin",
			headers: { "X-Requested-With": "XMLHttpRequest" }
		})
			.then(function (response) {
				if (!response.ok) {
					throw new Error("Unable to fetch VAT");
				}
				return response.json();
			})
			.then(function (data) {
				if (currentFetchToken !== clientVatFetchToken) return;
				if (data && Number(data.ok) === 1) {
					clientDataBySocidCache[cacheKey] = {
						vat: String(data.vat || ""),
						name: String(data.name || ""),
						address: String(data.address || ""),
						zip: String(data.zip || ""),
						town: String(data.town || ""),
						country: String(data.country || ""),
						email: String(data.email || "")
					};
					setClientVat(clientDataBySocidCache[cacheKey].vat);
					setClientDetails(clientDataBySocidCache[cacheKey]);
				} else {
					clientDataBySocidCache[cacheKey] = {
						vat: "",
						name: "",
						address: "",
						zip: "",
						town: "",
						country: "",
						email: ""
					};
					setClientVat("");
					setClientDetails({});
				}
			})
			.catch(function () {
				if (currentFetchToken !== clientVatFetchToken) return;
				setClientVat("");
				setClientDetails({});
			});
	}

	function fetchVatSuggestions(query, done) {
		if (typeof done !== "function") {
			done = function () {};
		}
		if (!clientVatSearchUrl) {
			done([]);
			return;
		}
		var q = String(query || "").trim();
		if (q.length < 2) {
			vatSuggestionsByKey = Object.create(null);
			done([]);
			return;
		}

		var currentToken = ++vatSearchToken;
		var url = clientVatSearchUrl + "&q=" + encodeURIComponent(q) + "&limit=12";
		fetch(url, {
			credentials: "same-origin",
			headers: { "X-Requested-With": "XMLHttpRequest" }
		})
			.then(function (response) {
				if (!response.ok) {
					throw new Error("Unable to search VAT");
				}
				return response.json();
			})
				.then(function (data) {
					if (currentToken !== vatSearchToken) return;
					if (data && Number(data.ok) === 1 && Array.isArray(data.items)) {
						vatSuggestionsByKey = Object.create(null);
						data.items.forEach(storeVatSuggestion);
						done(data.items);
					} else {
						vatSuggestionsByKey = Object.create(null);
						done([]);
					}
				})
				.catch(function () {
					if (currentToken !== vatSearchToken) return;
					vatSuggestionsByKey = Object.create(null);
					done([]);
				});
	}

	function applyClientSelectionFromVat(item) {
		if (!item) return;
		var socid = parseInt(String(item.id || "0"), 10);
		if (!isFinite(socid) || socid <= 0) return;

		var socidText = String(socid);
		clientDataBySocidCache[socidText] = {
			vat: String(item.vat || ""),
			name: String(item.name || ""),
			address: "",
			zip: "",
			town: "",
			country: "",
			email: ""
		};
		lastClientSocid = socidText;

		if (clientIdInput) {
			clientIdInput.value = socidText;
			clientIdInput.dispatchEvent(new Event("change", { bubbles: true }));
		}
		if (clientSearchInput) {
			clientSearchInput.value = String(item.name || "");
		}
		setClientVat(item.vat || "");
		fetchClientVatBySocid(socidText);
	}

	function resolveClientByVatInput(value) {
		var vatText = String(value || "").trim();
		if (vatText === "") return;

		var fromCache = vatSuggestionsByKey[vatText.toUpperCase()] || vatSuggestionsByKey[normalizeVatKey(vatText)];
		if (fromCache) {
			applyClientSelectionFromVat(fromCache);
			return;
		}

		if (!clientVatResolveUrl) return;
		var url = clientVatResolveUrl + "&vat=" + encodeURIComponent(vatText);
		fetch(url, {
			credentials: "same-origin",
			headers: { "X-Requested-With": "XMLHttpRequest" }
		})
			.then(function (response) {
				if (!response.ok) {
					throw new Error("Unable to resolve VAT");
				}
				return response.json();
			})
			.then(function (data) {
				if (data && Number(data.ok) === 1 && data.item) {
					applyClientSelectionFromVat(data.item);
				}
			})
				.catch(function () {
					// Intentionally ignored: user can continue with name selection.
				});
	}

	function setupVatAutocomplete() {
		if (!clientVatInput) return;

		var jq = window.jQuery || window.$;
		if (jq && jq.ui && typeof jq.fn.autocomplete === "function") {
			var $vatInput = jq(clientVatInput);
			$vatInput.autocomplete({
				minLength: 2,
				delay: 120,
				source: function (request, response) {
					fetchVatSuggestions(request.term, function (items) {
						var mapped = (items || []).map(function (item) {
							var vatTxt = String(item.vat || "");
							var nameTxt = String(item.name || "");
							var label = (nameTxt !== "") ? (vatTxt + " - " + nameTxt) : vatTxt;
							return {
								label: label,
								value: vatTxt,
								itemData: item
							};
						});
						response(mapped);
					});
				},
				select: function (event, ui) {
					if (ui && ui.item && ui.item.itemData) {
						applyClientSelectionFromVat(ui.item.itemData);
						return false;
					}
				}
			});

			$vatInput.on("change", function () {
				resolveClientByVatInput(clientVatInput.value || "");
			});
			$vatInput.on("blur", function () {
				window.setTimeout(function () {
					resolveClientByVatInput(clientVatInput.value || "");
				}, 120);
			});
			return;
		}

		clientVatInput.addEventListener("change", function () {
			resolveClientByVatInput(clientVatInput.value || "");
		});
		clientVatInput.addEventListener("blur", function () {
			window.setTimeout(function () {
				resolveClientByVatInput(clientVatInput.value || "");
			}, 120);
		});
	}

	function setupSearchableSelect(select, allowClear) {
		if (!select) return;
		var jq = window.jQuery || window.$;
		if (!(jq && typeof jq.fn.select2 === "function")) {
			return;
		}

		jq(select).select2({
			width: "100%",
			placeholder: String(select.getAttribute("data-placeholder") || ""),
			minimumResultsForSearch: 0,
			allowClear: !!allowClear
		});
	}

	function syncClientVatFromSelection(forceReload) {
		if (!clientIdInput) return;
		var currentSocid = String(clientIdInput.value || "").trim();
		if (!forceReload && currentSocid === lastClientSocid) {
			return;
		}
		lastClientSocid = currentSocid;
		if (Object.prototype.hasOwnProperty.call(clientDataBySocidCache, currentSocid)) {
			setClientVat(clientDataBySocidCache[currentSocid].vat || "");
			setClientDetails(clientDataBySocidCache[currentSocid]);
			return;
		}
		fetchClientVatBySocid(currentSocid);
	}

	var productSearchToken = 0;
	var productSearchCache = Object.create(null);

	function fetchProductSuggestions(query, done) {
		if (typeof done !== "function") {
			done = function () {};
		}
		if (!productSearchUrl) {
			done([]);
			return;
		}

		var q = String(query || "").trim();
		if (q.length < 1) {
			done([]);
			return;
		}

		var cacheKey = q.toUpperCase();
		if (Object.prototype.hasOwnProperty.call(productSearchCache, cacheKey)) {
			done(productSearchCache[cacheKey]);
			return;
		}

		var currentToken = ++productSearchToken;
		var url = productSearchUrl + "&q=" + encodeURIComponent(q) + "&limit=20";
		fetch(url, {
			credentials: "same-origin",
			headers: { "X-Requested-With": "XMLHttpRequest" }
		})
			.then(function (response) {
				if (!response.ok) {
					throw new Error("Unable to search products");
				}
				return response.json();
			})
			.then(function (data) {
				if (currentToken !== productSearchToken) {
					done([]);
					return;
				}
				var items = (data && Number(data.ok) === 1 && Array.isArray(data.items)) ? data.items : [];
				productSearchCache[cacheKey] = items;
				done(items);
			})
			.catch(function () {
				if (currentToken !== productSearchToken) {
					done([]);
					return;
				}
				productSearchCache[cacheKey] = [];
				done([]);
			});
	}

		function applyProductSelectionToRow(row, item) {
			if (!row || !item) return;

			var codeInput = getInput(row, "line_codigo[]");
			var productIdInput = getInput(row, "line_product_id[]");
			var obsInput = getInput(row, "line_obs[]");
			var punitInput = getInput(row, "line_punit[]");
			var vatTxInput = getInput(row, "line_vat_tx[]");
			var armazemInput = getInput(row, "line_armazem[]");
			var resolvedCode = String(item.code || "").trim();
			var resolvedLabel = String(item.label || "").trim();
			var resolvedPrice = Number(item.price);
			var resolvedVatTx = Number(item.vat_tx);
			var resolvedProductId = parseInt(String(item.id || ""), 10);
			if (!isFinite(resolvedProductId) || resolvedProductId <= 0) {
				resolvedProductId = 0;
			}
			if (!isFinite(resolvedVatTx) || resolvedVatTx < 0) {
				resolvedVatTx = 0;
			}

			if (codeInput) {
				codeInput.value = resolvedCode;
			}
			if (productIdInput) {
				productIdInput.value = (resolvedProductId > 0 ? String(resolvedProductId) : "");
			}
			if (obsInput) {
				obsInput.value = resolvedLabel;
			}
			if (punitInput && isFinite(resolvedPrice) && resolvedPrice >= 0) {
				punitInput.value = serializeNumber(resolvedPrice, unitPriceMaxDecimals, unitPriceMinDecimals);
			}
			if (vatTxInput) {
				vatTxInput.value = serializeNumber(resolvedVatTx, 4, 0);
			}
			if (armazemInput && defaultWarehouseId > 0) {
				armazemInput.value = String(defaultWarehouseId);
			}
			setRowProductCardLink(row, resolvedProductId);
			updateRowVatHint(row);

			row.classList.remove("dzs-row-invalid");
			updateSummary();
		}

		function setRowProductCardLink(row, productId) {
			if (!row) return;
			var codeInput = getInput(row, "line_codigo[]");
			if (!(codeInput instanceof HTMLInputElement)) return;

			var pid = parseInt(String(productId || ""), 10);
			if (!isFinite(pid) || pid <= 0) {
				codeInput.setAttribute("data-product-href", "");
				codeInput.classList.add("is-disabled");
				codeInput.removeAttribute("title");
				return;
			}

			codeInput.setAttribute("data-product-href", productCardBaseUrl + String(pid));
			codeInput.classList.remove("is-disabled");
			codeInput.setAttribute("title", openProductTitle);
		}

	function setupProductAutocomplete() {
		var jq = window.jQuery || window.$;
		if (!(jq && jq.ui && typeof jq.fn.autocomplete === "function")) {
			return;
		}

			function ensureAutocompleteOnInput(input) {
				if (!(input instanceof HTMLInputElement)) return;
				if (!input.classList.contains("dzs-input-obs")) return;
				if (input.getAttribute("data-dzs-product-ac") === "1") return;

			input.setAttribute("data-dzs-product-ac", "1");
			var $input = jq(input);
				$input.autocomplete({
					minLength: 1,
					delay: 100,
					source: function (request, response) {
					fetchProductSuggestions(request.term, function (items) {
						var mapped = (items || []).map(function (item) {
							var refTxt = String(item.ref || "");
							var labelTxt = String(item.label || "");
							var codeTxt = String(item.code || "");
							var label = refTxt;
							if (labelTxt !== "") {
								label = (label !== "" ? (label + " - " + labelTxt) : labelTxt);
							}
							if (codeTxt !== "" && codeTxt !== refTxt) {
								label += " [" + codeTxt + "]";
							}
							return {
								label: label,
								value: label,
								itemData: item
							};
						});
							response(mapped);
						});
					},
					response: function () {
						input.classList.remove("ui-autocomplete-loading");
					},
					close: function () {
						input.classList.remove("ui-autocomplete-loading");
					},
					focus: function () {
						// Keep current input value while browsing suggestions.
						return false;
					},
					select: function (event, ui) {
						if (ui && ui.item && ui.item.itemData) {
							var row = input.closest(".dzs-line-row");
							applyProductSelectionToRow(row, ui.item.itemData);
							input.classList.remove("ui-autocomplete-loading");
							return false;
						}
					}
				});
			}

			getRows().forEach(function (row) {
				ensureAutocompleteOnInput(getInput(row, "line_obs[]"));
				var rowProductId = parseInt(String((getInput(row, "line_product_id[]") || { value: "" }).value || ""), 10);
				setRowProductCardLink(row, rowProductId);
			});

		linesList.addEventListener("focusin", function (event) {
			var target = event.target;
			if (!(target instanceof HTMLInputElement)) return;
			ensureAutocompleteOnInput(target);
		});
	}

		function resetRowInputs(row) {
		row.querySelectorAll("input, select, textarea").forEach(function (field) {
			var defaultValue = field.getAttribute("data-default");
			field.value = (defaultValue !== null) ? defaultValue : "";
			if (field instanceof HTMLInputElement) {
				field.removeAttribute("data-dzs-product-ac");
				if (field.classList.contains("ui-autocomplete-input")) {
					field.classList.remove("ui-autocomplete-input");
				}
			}
		});

			var armazemInput = getInput(row, "line_armazem[]");
			if (armazemInput && defaultWarehouseId > 0 && String(armazemInput.value || "").trim() === "") {
				armazemInput.value = String(defaultWarehouseId);
			}
			setRowProductCardLink(row, 0);
			updateRowVatHint(row);
			row.classList.remove("dzs-row-invalid");
		}

	function refreshRowDecorators() {
		getRows().forEach(function (row, index) {
			var numberCell = row.querySelector(".dzs-line-number");
			if (numberCell) numberCell.textContent = String(index + 1);
			row.classList.remove("oddeven", "oddevenpair");
			row.classList.add((index % 2 === 0) ? "oddeven" : "oddevenpair");
			updateRowVatHint(row);
		});
		if (rowsPill) {
			rowsPill.textContent = String(getRows().length);
		}
	}

		function isRowEmpty(row) {
			var codigoRaw = (getInput(row, "line_codigo[]") || { value: "" }).value.trim();
			var qtdRaw = (getInput(row, "line_qtd[]") || { value: "" }).value.trim();
			var punitRaw = (getInput(row, "line_punit[]") || { value: "" }).value.trim();
			var descontoRaw = (getInput(row, "line_desconto[]") || { value: "" }).value.trim();
			var obsRaw = (getInput(row, "line_obs[]") || { value: "" }).value.trim();
			var armazemRaw = (getInput(row, "line_armazem[]") || { value: "" }).value.trim();
			var descontoNum = parseNumber(descontoRaw);
			var qtdNum = parseNumber(qtdRaw);
			var qtdIsDefault = (qtdRaw === "" || Math.abs(qtdNum - 1) < 0.0000001);
			var armazemIsDefault = (defaultWarehouseId > 0 && armazemRaw === String(defaultWarehouseId));

			return codigoRaw === ""
				&& qtdIsDefault
				&& punitRaw === ""
				&& obsRaw === ""
				&& (armazemRaw === "" || armazemIsDefault)
				&& (descontoRaw === "" || Math.abs(descontoNum) < 0.0000001);
		}

	function getRowVatRate(row) {
		var vatRate = parseNumber((getInput(row, "line_vat_tx[]") || { value: "" }).value);
		if (!isFinite(vatRate) || vatRate < 0) {
			vatRate = 0;
		}
		return vatRate;
	}

	function updateRowVatHint(row) {
		if (!row) return;
		var hint = row.querySelector(".dzs-line-vat-hint");
		if (!hint) return;
		var vatRate = getRowVatRate(row);
		hint.textContent = " " + serializeNumber(vatRate, 4, 0) + "%";
	}

	function updateLineTotal(row) {
		var qtd = parseNumber((getInput(row, "line_qtd[]") || { value: "" }).value);
		var punit = parseNumber((getInput(row, "line_punit[]") || { value: "" }).value);
		var desconto = parseNumber((getInput(row, "line_desconto[]") || { value: "" }).value);
		var vatRate = getRowVatRate(row);
		if (desconto < 0) desconto = 0;
		if (desconto > 100) desconto = 100;

		var inputTotal = qtd * punit * (1 - (desconto / 100));
		if (!isFinite(inputTotal) || inputTotal < 0) inputTotal = 0;

		var totalHt = inputTotal;
		var totalTtc = inputTotal;
		if (invoicePricesIncludeVat) {
			totalHt = inputTotal / (1 + (vatRate / 100));
		} else {
			totalTtc = inputTotal * (1 + (vatRate / 100));
		}
		if (!isFinite(totalHt) || totalHt < 0) totalHt = 0;
		if (!isFinite(totalTtc) || totalTtc < 0) totalTtc = 0;

		var totalHtCell = row.querySelector(".dzs-line-total-ht");
		if (totalHtCell) {
			totalHtCell.textContent = formatNumber(totalHt, 2);
			totalHtCell.setAttribute("data-total-ht", totalHt.toFixed(6));
		}
		var totalTtcCell = row.querySelector(".dzs-line-total-ttc");
		if (totalTtcCell) {
			totalTtcCell.textContent = formatNumber(totalTtc, 2);
			totalTtcCell.setAttribute("data-total-ttc", totalTtc.toFixed(6));
		}
		return { ht: totalHt, ttc: totalTtc };
	}

	function updateSummary() {
		var activeLines = 0;
		var qtyTotal = 0;
		var amountTotalHt = 0;
		var amountTotalTtc = 0;

		getRows().forEach(function (row) {
			var rowQty = parseNumber((getInput(row, "line_qtd[]") || { value: "" }).value);
			var rowTotal = updateLineTotal(row);
			if (!isRowEmpty(row)) {
				activeLines++;
				qtyTotal += rowQty;
				amountTotalHt += rowTotal.ht;
				amountTotalTtc += rowTotal.ttc;
			}
		});

		if (summaryLines) summaryLines.textContent = String(activeLines);
		if (summaryQty) summaryQty.textContent = formatNumber(qtyTotal, 3);
		if (summaryAmountHt) summaryAmountHt.textContent = formatNumber(amountTotalHt, 2);
		if (summaryAmountTtc) summaryAmountTtc.textContent = formatNumber(amountTotalTtc, 2);
	}

	function getFirstEmptyRow() {
		var rows = getRows();
		for (var i = 0; i < rows.length; i++) {
			if (isRowEmpty(rows[i])) {
				return rows[i];
			}
		}
		return null;
	}

	function getComposerValues() {
		return {
			codigo: String((composerCodigoInput && composerCodigoInput.value) || "").trim(),
			obs: String((composerObsInput && composerObsInput.value) || "").trim(),
			punit: String((composerPunitInput && composerPunitInput.value) || "").trim(),
			qtd: String((composerQtdInput && composerQtdInput.value) || "").trim(),
			desconto: String((composerDescontoInput && composerDescontoInput.value) || "").trim(),
			armazem: String((composerArmazemInput && composerArmazemInput.value) || "").trim()
		};
	}

	function canAddComposerLine() {
		if (!composerAddBtn) return false;
		var values = getComposerValues();
		var codigoOk = /^[0-9]+$/.test(values.codigo) && parseInt(values.codigo, 10) > 0;
		var qtdOk = parseNumber(values.qtd) > 0;
		var punitOk = values.punit !== "" && parseNumber(values.punit) >= 0;
		var descontoOk = values.desconto === "" || (parseNumber(values.desconto) >= 0 && parseNumber(values.desconto) <= 100);
		return codigoOk && qtdOk && punitOk && descontoOk;
	}

	function updateComposerAddState() {
		if (!composerAddBtn) return;
		composerAddBtn.disabled = !canAddComposerLine();
	}

	function clearComposerValues() {
		if (composerCodigoInput) composerCodigoInput.value = "";
		if (composerObsInput) composerObsInput.value = "";
		if (composerPunitInput) composerPunitInput.value = "";
		if (composerQtdInput) composerQtdInput.value = "1";
		if (composerDescontoInput) composerDescontoInput.value = "0";
		if (composerArmazemInput) composerArmazemInput.value = "";
		updateComposerAddState();
	}

		function applyValuesToRow(row, values) {
			var codeInput = getInput(row, "line_codigo[]");
			var productIdInput = getInput(row, "line_product_id[]");
			var obsInput = getInput(row, "line_obs[]");
			var punitInput = getInput(row, "line_punit[]");
			var qtdInput = getInput(row, "line_qtd[]");
			var descontoInput = getInput(row, "line_desconto[]");
			var armazemInput = getInput(row, "line_armazem[]");

			if (codeInput) codeInput.value = String(parseInt(values.codigo, 10) || "");
			if (productIdInput) productIdInput.value = "";
			if (obsInput) obsInput.value = values.obs;
			if (punitInput) punitInput.value = serializeNumber(parseNumber(values.punit), unitPriceMaxDecimals, unitPriceMinDecimals);
			if (qtdInput) qtdInput.value = serializeNumber(parseNumber(values.qtd), 6);
			if (descontoInput) descontoInput.value = (values.desconto === "") ? "" : serializeNumber(parseNumber(values.desconto), 4);
			if (armazemInput) {
			var armazemValue = (/^[0-9]+$/.test(values.armazem) ? String(parseInt(values.armazem, 10)) : "");
			if (armazemValue === "" && defaultWarehouseId > 0) {
				armazemValue = String(defaultWarehouseId);
				}
				armazemInput.value = armazemValue;
			}
			setRowProductCardLink(row, 0);
			row.classList.remove("dzs-row-invalid");
		}

	function addLineFromComposer() {
		if (!canAddComposerLine()) return;

		var values = getComposerValues();
		var targetRow = getFirstEmptyRow();
		if (!targetRow) {
			var rows = getRows();
			if (!rows.length) return;
			targetRow = rows[rows.length - 1].cloneNode(true);
			resetRowInputs(targetRow);
			linesList.appendChild(targetRow);
		}

		applyValuesToRow(targetRow, values);
		refreshRowDecorators();
		updateSummary();
		clearComposerValues();
		if (composerCodigoInput) composerCodigoInput.focus();
	}

	function addEmptyRow(referenceRow) {
		var rows = getRows();
		if (!rows.length) return;
		var baseRow = referenceRow || rows[rows.length - 1];
		if (!baseRow) return;
		var clone = baseRow.cloneNode(true);
		resetRowInputs(clone);
		if (referenceRow && referenceRow.parentNode) {
			referenceRow.parentNode.insertBefore(clone, referenceRow.nextSibling);
		} else {
			linesList.appendChild(clone);
		}
			refreshRowDecorators();
			updateSummary();
			var obsInput = getInput(clone, "line_obs[]");
			if (obsInput) {
				obsInput.focus();
			}
			return clone;
		}

	function pruneEmptyRowsKeepOne() {
		var rows = getRows();
		if (!rows.length) return;
		var firstRow = rows[0];
		rows.forEach(function (row) {
			if (rows.length > 1 && isRowEmpty(row)) {
				row.remove();
			}
		});
		if (getRows().length === 0 && firstRow) {
			resetRowInputs(firstRow);
			linesList.appendChild(firstRow);
		}
		refreshRowDecorators();
		updateSummary();
	}

	function normalizeNumericInputs() {
		getRows().forEach(function (row) {
			[
				{ name: "line_qtd[]", decimals: 6 },
				{ name: "line_punit[]", decimals: unitPriceMaxDecimals, minDecimals: unitPriceMinDecimals },
				{ name: "line_desconto[]", decimals: 4 }
				].forEach(function (field) {
					var input = getInput(row, field.name);
					if (!input) return;
					var raw = input.value.trim();
					if (raw === "") return;
					if (field.name === "line_desconto[]") {
						var descontoValue = parseNumber(raw);
						if (!isFinite(descontoValue)) descontoValue = 0;
						if (descontoValue < 0) descontoValue = 0;
						if (descontoValue > 100) descontoValue = 100;
						raw = serializeNumber(descontoValue, field.decimals);
						input.value = raw;
						return;
					}
					normalizeCalculationInput(input, field.decimals, field.minDecimals || 0);
				});
			});
	}

	function enforceDefaultWarehouseOnRows() {
		if (defaultWarehouseId <= 0) return;
		getRows().forEach(function (row) {
			var armazemInput = getInput(row, "line_armazem[]");
			if (!armazemInput) return;
			armazemInput.value = String(defaultWarehouseId);
		});
	}

		function clampDiscountInputValue(input, applyFormat) {
			if (!(input instanceof HTMLInputElement)) return;
			if (!input.classList.contains("dzs-input-desconto")) return;

		var raw = String(input.value || "").trim();
		if (raw === "") {
			if (applyFormat) {
				input.value = "0";
			}
			return;
		}

		var value = parseNumber(raw);
		if (!isFinite(value)) {
			value = 0;
		}
		if (value < 0) value = 0;
		if (value > 100) value = 100;

			if (applyFormat) {
				input.value = serializeNumber(value, 4);
			} else if (value === 0 || value === 100) {
				input.value = String(value);
			}
		}

	function formatInvalidRowsMessage(check) {
		// Named placeholders survive Dolibarr's server-side translation formatting.
		return String(messages.invalidRows || "Please review {count} invalid line(s): {rows}.")
			.replace("{count}", String(check.invalidRows))
			.replace("{rows}", check.invalidDetails.join("; "));
	}

	function validateRows() {
		var hasAnyLine = false;
		var invalidRows = 0;
		var invalidDetails = [];
		getRows().forEach(function (row, index) {
			row.classList.remove("dzs-row-invalid");
			if (isRowEmpty(row)) return;

			hasAnyLine = true;
			var codeRaw = (getInput(row, "line_codigo[]") || { value: "" }).value.trim();
			var qty = parseNumber((getInput(row, "line_qtd[]") || { value: "" }).value);
			var punit = parseNumber((getInput(row, "line_punit[]") || { value: "" }).value);
			var qtyCheck = calculateDecimalExpression((getInput(row, "line_qtd[]") || { value: "" }).value);
			var punitCheck = calculateDecimalExpression((getInput(row, "line_punit[]") || { value: "" }).value);
			var descontoInput = (getInput(row, "line_desconto[]") || { value: "" });
			var descontoRaw = descontoInput.value.trim();
			var desconto = parseNumber(descontoRaw);

			var codeOk = /^[0-9]+$/.test(codeRaw) && parseInt(codeRaw, 10) > 0;
			var qtyOk = qtyCheck.ok && qty > 0;
			var punitOk = punitCheck.ok && punit >= 0;
			var descontoOk = (descontoRaw === "") || (desconto >= 0 && desconto <= 100);
			if (!codeOk || !qtyOk || !punitOk || !descontoOk) {
				invalidRows++;
				var fields = [];
				if (!codeOk) fields.push(messages.lineReference);
				if (!qtyOk) fields.push(messages.lineQuantity);
				if (!punitOk) fields.push(messages.lineUnitPrice);
				if (!descontoOk) fields.push(messages.lineDiscount);
				invalidDetails.push(String(index + 1) + " (" + fields.join(", ") + ")");
				row.classList.add("dzs-row-invalid");
			}
		});

		return {
			hasAnyLine: hasAnyLine,
			invalidRows: invalidRows,
			invalidDetails: invalidDetails
		};
	}

	if (composerAddBtn) {
		composerAddBtn.addEventListener("click", function () {
			addLineFromComposer();
		});
	}

	[composerCodigoInput, composerObsInput, composerPunitInput, composerQtdInput, composerDescontoInput, composerArmazemInput].forEach(function (input) {
		if (!input) return;
		input.addEventListener("input", updateComposerAddState);
		input.addEventListener("keydown", function (event) {
			if (event.key === "Enter") {
				event.preventDefault();
				addLineFromComposer();
			}
		});
	});

		linesList.addEventListener("click", function (event) {
			var target = event.target;
			if (!(target instanceof HTMLElement)) return;

			var refInput = target.closest("input.dzs-input-codigo.dzs-ref-link");
			if (refInput instanceof HTMLInputElement) {
				var productHref = String(refInput.getAttribute("data-product-href") || "").trim();
				if (productHref !== "") {
					event.preventDefault();
					window.open(productHref, "_blank", "noopener");
					refInput.blur();
					return;
				}
			}

			var insertBtn = target.closest(".dzs-insert-line");
			if (insertBtn) {
				event.preventDefault();
			var insertAfterRow = insertBtn.closest(".dzs-line-row");
			if (!insertAfterRow) return;
			addEmptyRow(insertAfterRow);
			return;
		}

		var removeBtn = target.closest(".dzs-remove-line");
		if (removeBtn) {
			event.preventDefault();
			var removeRow = removeBtn.closest(".dzs-line-row");
			if (!removeRow) return;
			var rows = getRows();
			if (rows.length <= 1) {
				resetRowInputs(removeRow);
			} else {
				removeRow.remove();
			}
			refreshRowDecorators();
			updateSummary();
		}
	});

		linesList.addEventListener("input", function (event) {
			var target = event.target;
			if (!(target instanceof HTMLElement)) return;
			var row = target.closest(".dzs-line-row");
			if (!row) return;
			if (target.classList.contains("dzs-input-obs") && event.isTrusted) {
				var codeInput = getInput(row, "line_codigo[]");
				var productIdInput = getInput(row, "line_product_id[]");
				if (codeInput) {
					codeInput.value = "";
				}
				if (productIdInput) {
					productIdInput.value = "";
				}
				setRowProductCardLink(row, 0);
			}
			if (target.classList.contains("dzs-input-armazem") && defaultWarehouseId > 0) {
				target.value = String(defaultWarehouseId);
			}
		if (target.classList.contains("dzs-input-desconto")) {
			clampDiscountInputValue(target, false);
		}
			if (target.classList.contains("dzs-input-vat-tx")) {
				updateRowVatHint(row);
			}
			row.classList.remove("dzs-row-invalid");
			updateSummary();
		});

		linesList.addEventListener("keydown", function (event) {
			if (event.key !== "Enter") return;
			if (event.shiftKey || event.altKey || event.ctrlKey || event.metaKey) return;

			var target = event.target;
			if (!(target instanceof HTMLElement)) return;
			var row = target.closest(".dzs-line-row");
			if (!row) return;

			var isObs = target.classList.contains("dzs-input-obs");
			var isPunit = target.classList.contains("dzs-input-punit");
			var isQtd = target.classList.contains("dzs-input-qtd");
			var isDesconto = target.classList.contains("dzs-input-desconto");
			if (!isObs && !isPunit && !isQtd && !isDesconto) return;

			// Let jQuery UI autocomplete use Enter to select an item when suggestion list is open.
			if (isObs && String(target.getAttribute("aria-expanded") || "").toLowerCase() === "true") {
				return;
			}

			event.preventDefault();

			if (isObs) {
				var punitInput = getInput(row, "line_punit[]");
				if (punitInput) focusAndSelectInput(punitInput);
				return;
			}
			if (isPunit) {
				var qtdInput = getInput(row, "line_qtd[]");
				if (qtdInput) focusAndSelectInput(qtdInput);
				return;
			}
			if (isQtd) {
				var descontoInput = getInput(row, "line_desconto[]");
				if (descontoInput) focusAndSelectInput(descontoInput);
				return;
			}
			if (isDesconto) {
				clampDiscountInputValue(target, true);
				updateSummary();

				var nextRow = row.nextElementSibling;
				if (!(nextRow instanceof HTMLElement) || !nextRow.classList.contains("dzs-line-row")) {
					nextRow = addEmptyRow(row);
				}

				if (nextRow) {
					var nextObsInput = getInput(nextRow, "line_obs[]");
					if (nextObsInput) focusAndSelectInput(nextObsInput);
				}
			}
		});

		linesList.addEventListener("blur", function (event) {
			var target = event.target;
			if (!(target instanceof HTMLInputElement)) return;
		if (target.classList.contains("dzs-input-qtd") || target.classList.contains("dzs-input-punit") || target.classList.contains("dzs-input-desconto")) {
			var raw = target.value.trim();
			if (raw !== "") {
				var decimals = target.classList.contains("dzs-input-desconto") ? 4 : (target.classList.contains("dzs-input-punit") ? unitPriceDecimals : 6);
				var minDecimals = target.classList.contains("dzs-input-punit") ? unitPriceMinDecimals : 0;
				if (target.classList.contains("dzs-input-punit")) {
					decimals = unitPriceMaxDecimals;
				}
				if (target.classList.contains("dzs-input-desconto")) {
					clampDiscountInputValue(target, true);
				} else {
					normalizeCalculationInput(target, decimals, minDecimals);
				}
			} else if (target.classList.contains("dzs-input-desconto")) {
				target.value = "0";
			}
			updateSummary();
		}
	}, true);

	if (docTypeSelect) {
		docTypeSelect.addEventListener("change", function () {
			syncDocTypeVisibility();
		});
	}
	if (clientIdInput) {
		clientIdInput.addEventListener("change", function () {
			syncClientVatFromSelection(false);
		});
	}
	if (clientSearchInput) {
		var onClientSearchSelected = function () {
			window.setTimeout(function () {
				syncClientVatFromSelection(false);
			}, 0);
		};
		clientSearchInput.addEventListener("change", onClientSearchSelected);

		var jqClient = window.jQuery || window.$;
		if (jqClient) {
			jqClient(clientSearchInput).on("autocompleteselect autocompletechange change", onClientSearchSelected);
		}
	}
	restoreDraftAfterRefresh();
	if (deliveryDateInput) {
		var syncPickupDateFromDeliveryDate = function () {
			if (!pickupDateInput) return;
			pickupDateInput.value = String(deliveryDateInput.value || "").trim();
		};
		syncPickupDateFromDeliveryDate();
	}
	if (deliveryDateInput && deliveryDateTouchedInput) {
		var markDeliveryDateTouched = function () {
			deliveryDateTouchedInput.value = "1";
		};
		var onDeliveryDateChange = function () {
			markDeliveryDateTouched();
			if (pickupDateInput) {
				pickupDateInput.value = String(deliveryDateInput.value || "").trim();
			}
		};
		deliveryDateInput.addEventListener("input", onDeliveryDateChange);
		deliveryDateInput.addEventListener("change", onDeliveryDateChange);
	}
	setupVatAutocomplete();
	setupSearchableSelect(paymentTypeSelect, true);
	setupSearchableSelect(empSelect, false);
	setupProductAutocomplete();
	enforceRoundedControls();

	if (creatorRoot) {
		["focusin", "focusout", "click", "mousedown", "mouseup", "keydown", "keyup", "input", "change"].forEach(function (eventName) {
			creatorRoot.addEventListener(eventName, function (event) {
				enforceRoundedFromNode(event.target);
				window.requestAnimationFrame(enforceRoundedControls);
			}, true);
		});

		var roundedObserver = new MutationObserver(function (mutations) {
			var needsRefresh = false;
			mutations.forEach(function (mutation) {
				if (mutation.type === "attributes") {
					enforceRoundedFromNode(mutation.target);
					needsRefresh = true;
				}
				if (mutation.type === "childList") {
					mutation.addedNodes.forEach(function (node) {
						enforceRoundedFromNode(node);
					});
					if (mutation.addedNodes.length > 0) {
						needsRefresh = true;
					}
				}
			});
			if (needsRefresh) {
				window.requestAnimationFrame(enforceRoundedControls);
			}
		});
		roundedObserver.observe(creatorRoot, {
			subtree: true,
			childList: true,
			attributes: true,
			attributeFilter: ["class", "style"]
		});
	}

	var jqRound = window.jQuery || window.$;
	if (jqRound) {
		jqRound(document).on("select2:open select2:close select2:select autocompleteopen autocompleteclose autocompleteselect", function () {
			window.setTimeout(enforceRoundedControls, 0);
		});
	}
	window.setTimeout(enforceRoundedControls, 0);
	window.setTimeout(enforceRoundedControls, 120);
	window.setTimeout(enforceRoundedControls, 300);

	if (createdPdfModal) {
		setCreatedPdfModalOpen(true);
		createdPdfModal.addEventListener("click", function (event) {
			if (event.target === createdPdfModal) {
				setCreatedPdfModalOpen(false);
			}
		});
		document.addEventListener("keydown", function (event) {
			if (event.key === "Escape" && !createdPdfModal.classList.contains("dzs-hidden")) {
				setCreatedPdfModalOpen(false);
			}
		});
	}
	if (closeCreatedPdfModalBtn) {
		closeCreatedPdfModalBtn.addEventListener("click", function () {
			setCreatedPdfModalOpen(false);
		});
	}
	if (openCreatedPdfBtn) {
		openCreatedPdfBtn.addEventListener("click", function () {
			setCreatedPdfModalOpen(false);
		});
	}
	if (refreshKeepDraftBtn) {
		refreshKeepDraftBtn.addEventListener("click", function () {
			saveDraftForRefresh();
			window.location.reload();
		});
	}

	form.addEventListener("submit", function (event) {
		pruneEmptyRowsKeepOne();
		enforceDefaultWarehouseOnRows();
		normalizeNumericInputs();
		var check = validateRows();
		updateSummary();
		var selectedClientId = clientIdInput ? parseInt(String(clientIdInput.value || "0"), 10) : 0;
		var selectedEmpId = empSelect ? parseInt(String(empSelect.value || "0"), 10) : 0;
		if (!selectedClientId || selectedClientId <= 0) {
			event.preventDefault();
			window.alert(String(messages.needClient || "Select a client before creating the document."));
			return;
		}
		if (!empSelect || empSelect.disabled) {
			event.preventDefault();
			window.alert(String(messages.noEmployees || "No active employees were found in the configured schedule group for this entity/store."));
			return;
		}
		if (!selectedEmpId || selectedEmpId <= 0) {
			event.preventDefault();
			window.alert(String(messages.needEmployee || "Select an active employee before creating the document."));
			return;
		}
		if (!check.hasAnyLine) {
			event.preventDefault();
			window.alert(String(messages.needLine || "Add at least one valid line before submitting."));
			return;
		}
		if (check.invalidRows > 0) {
			event.preventDefault();
			window.alert(formatInvalidRowsMessage(check));
			var firstInvalidRow = linesList.querySelector(".dzs-row-invalid");
			if (firstInvalidRow) firstInvalidRow.scrollIntoView({ block: "center" });
			return;
		}
		if (submitLocked) {
			event.preventDefault();
			return;
		}
		submitLocked = true;
		form.querySelectorAll('input[type="submit"], button[type="submit"]').forEach(function (button) {
			button.disabled = true;
		});
		clearDraftForRefresh();
	});

	syncDocTypeVisibility();
	syncClientVatFromSelection(true);
	enforceDefaultWarehouseOnRows();
	refreshRowDecorators();
	updateSummary();
	updateComposerAddState();
});
</script>
<?php
llxFooter();
$db->close();

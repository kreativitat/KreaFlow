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
 * \file        class/trait/zslogger.trait.php
 * \ingroup     dolizsynch
 * \brief       Enhanced logging trait for DoliZSynch module
 */

/**
 * Trait ZSLogger
 *
 * Provides enhanced logging capabilities with context, structured output,
 * and consistent return value handling for cron jobs.
 *
 * ## Dolibarr Cron Job Return Value Convention:
 * - `0` or string starting with "0|" = Success
 * - Negative integer (e.g., `-1`) = Error
 * - String (descriptive message) = Can indicate success or error depending on context
 * - Positive integer > 0 = Success with count
 *
 * ## Log Level Guidelines:
 * - LOG_ERR: Errors that prevent operation completion
 * - LOG_WARNING: Issues that don't prevent completion but need attention
 * - LOG_INFO: Important operational events (start, completion, counts)
 * - LOG_DEBUG: Detailed flow information for debugging
 */
trait ZSLogger
{
	/**
	 * Truncate and normalize one log string value.
	 *
	 * @param string $value
	 * @param int $maxLength
	 * @return string
	 */
	protected function truncateLogString($value, $maxLength = 512)
	{
		$value = str_replace(array("\r", "\n"), array('\r', '\n'), (string) $value);
		$maxLength = max(64, (int) $maxLength);
		if (strlen($value) > $maxLength) {
			return substr($value, 0, $maxLength) . '... [truncated]';
		}

		return $value;
	}

	/**
	 * Normalize a value for safe log serialization.
	 *
	 * @param mixed $value
	 * @param int $depth
	 * @param int $maxDepth
	 * @param int $maxItems
	 * @return mixed
	 */
	protected function normalizeValueForLog($value, $depth = 0, $maxDepth = 2, $maxItems = 20)
	{
		if ($value === null || is_int($value) || is_float($value)) {
			return $value;
		}
		if (is_bool($value)) {
			return $value ? 'true' : 'false';
		}
		if (is_string($value)) {
			return $this->truncateLogString($value, 512);
		}
		if (is_resource($value)) {
			return 'resource(' . get_resource_type($value) . ')';
		}
		if ($depth >= $maxDepth) {
			return '[depth_truncated]';
		}

		if (is_object($value)) {
			$className = get_class($value);
			if ($value instanceof Exception) {
				return $className . ': ' . $this->truncateLogString($value->getMessage(), 256);
			}
			$vars = get_object_vars($value);
			$keys = array_keys($vars);
			$preview = array_slice($keys, 0, max(1, (int) $maxItems));
			$preview = array_map('strval', $preview);
			return 'object(' . $className . ', props=' . count($keys) . ', keys=[' . implode(', ', $preview) . (count($keys) > $maxItems ? ', ...' : '') . '])';
		}

		if (is_array($value)) {
			$out = array();
			$count = 0;
			foreach ($value as $key => $child) {
				$count++;
				if ($count > $maxItems) {
					$out['__truncated_items'] = count($value) - $maxItems;
					break;
				}
				$out[(string) $key] = $this->normalizeValueForLog($child, $depth + 1, $maxDepth, $maxItems);
			}

			return $out;
		}

		return gettype($value);
	}

	/**
	 * Encode context to bounded JSON safe for syslog backends.
	 *
	 * @param array $context
	 * @param int $maxLength
	 * @return string
	 */
	protected function encodeContextForLog(array $context, $maxLength = 2048)
	{
		$normalized = $this->normalizeValueForLog($context, 0, 2, 20);
		$json = json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		if ($json === false) {
			$json = '{"context":"encoding_failed"}';
		}

		return $this->truncateLogString($json, $maxLength);
	}

	/**
	 * Build one short, bounded preview for arbitrary data.
	 *
	 * @param mixed $value
	 * @param int $maxLength
	 * @return string
	 */
	protected function summarizeValueForLog($value, $maxLength = 240)
	{
		$normalized = $this->normalizeValueForLog($value, 0, 1, 10);
		if (is_string($normalized)) {
			return $this->truncateLogString($normalized, $maxLength);
		}
		if (is_int($normalized) || is_float($normalized)) {
			return (string) $normalized;
		}
		if ($normalized === null) {
			return 'null';
		}
		if (is_array($normalized)) {
			$encoded = json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
			if ($encoded !== false) {
				return $this->truncateLogString($encoded, $maxLength);
			}
		}

		return $this->truncateLogString((string) gettype($value), $maxLength);
	}

	/**
	 * Whether verbose debug logging is explicitly enabled for the module.
	 *
	 * @return bool
	 */
	protected function isDebugLogEnabled()
	{
		// Retained for future diagnostics development; verbose module logging is disabled.
		return false;
	}

	/**
	 * Log operation start with context
	 *
	 * @param string $operation Operation name (e.g., "importInvoicesHeaders")
	 * @param array $context Optional context data
	 * @return void
	 */
	protected function logOperationStart($operation, array $context = [])
	{
		$contextStr = !empty($context) ? ' | Context: ' . $this->encodeContextForLog($context) : '';
		dol_syslog(
			"[ZSLOG] START: {$operation}{$contextStr}",
			LOG_INFO
		);
	}

	/**
	 * Log operation success with context
	 *
	 * @param string $operation Operation name
	 * @param array $context Optional context data (counts, IDs, etc.)
	 * @return void
	 */
	protected function logOperationSuccess($operation, array $context = [])
	{
		$contextStr = !empty($context) ? ' | Context: ' . $this->encodeContextForLog($context) : '';
		dol_syslog(
			"[ZSLOG] SUCCESS: {$operation}{$contextStr}",
			LOG_INFO
		);
	}

	/**
	 * Log operation failure with detailed error information
	 *
	 * @param string $operation Operation name
	 * @param string $errorMessage Error description
	 * @param array $context Optional context data (invoice_ref, error codes, etc.)
	 * @return void
	 */
	protected function logOperationError($operation, $errorMessage, array $context = [])
	{
		// Include $this->error and $this->errors if available
		if (isset($this->error) && !empty($this->error)) {
			$context['object_error'] = $this->error;
		}
		if (isset($this->errors) && !empty($this->errors)) {
			$context['object_errors'] = $this->errors;
		}

		$contextStr = !empty($context) ? ' | Context: ' . $this->encodeContextForLog($context) : '';
		dol_syslog(
			"[ZSLOG] ERROR: {$operation} | Message: {$errorMessage}{$contextStr}",
			LOG_ERR
		);
	}

	/**
	 * Log operation warning (non-fatal issues)
	 *
	 * @param string $operation Operation name
	 * @param string $warningMessage Warning description
	 * @param array $context Optional context data
	 * @return void
	 */
	protected function logOperationWarning($operation, $warningMessage, array $context = [])
	{
		$contextStr = !empty($context) ? ' | Context: ' . $this->encodeContextForLog($context) : '';
		dol_syslog(
			"[ZSLOG] WARNING: {$operation} | Message: {$warningMessage}{$contextStr}",
			LOG_WARNING
		);
	}

	/**
	 * Log detailed debug information
	 *
	 * @param string $operation Operation name
	 * @param string $message Debug message
	 * @param array $context Optional context data
	 * @return void
	 */
	protected function logDebug($operation, $message, array $context = [])
	{
		if (!$this->isDebugLogEnabled()) {
			return;
		}

		$contextStr = !empty($context) ? ' | Context: ' . $this->encodeContextForLog($context) : '';
		dol_syslog(
			"[ZSLOG] DEBUG: {$operation} | {$message}{$contextStr}",
			LOG_DEBUG
		);
	}

	/**
	 * Format a successful cron job return value
	 *
	 * Returns 0 for Dolibarr cron success, and logs the success message
	 *
	 * @param string $operation Operation name
	 * @param array $context Context data (processed count, success count, etc.)
	 * @return int Always returns 0 (success)
	 */
	protected function cronSuccess($operation, array $context = [])
	{
		$this->logOperationSuccess($operation, $context);
		return 0;
	}

	/**
	 * Format a failed cron job return value
	 *
	 * Returns error message string for Dolibarr cron display, and logs the error
	 *
	 * @param string $operation Operation name
	 * @param string $errorMessage Error description
	 * @param array $context Optional context data
	 * @return string Error message for cron job display
	 */
	protected function cronError($operation, $errorMessage, array $context = [])
	{
		$this->logOperationError($operation, $errorMessage, $context);

		// Return formatted error message for cron job display
		return "ERROR: {$errorMessage}";
	}

	/**
	 * Format a cron job partial success (some items processed, some failed)
	 *
	 * @param string $operation Operation name
	 * @param int $successCount Number of successful items
	 * @param int $failedCount Number of failed items
	 * @param array $context Optional context data
	 * @return int|string Returns 0 if any success, or an error string when every item failed
	 */
	protected function cronPartialSuccess($operation, $successCount, $failedCount, array $context = [])
	{
		$successCount = max(0, (int) $successCount);
		$failedCount = max(0, (int) $failedCount);
		$context['success_count'] = $successCount;
		$context['failed_count'] = $failedCount;
		$context['total_count'] = $successCount + $failedCount;

		if ($failedCount > 0 && $successCount === 0) {
			return $this->cronError(
				$operation,
				"Operation failed: 0 succeeded, {$failedCount} failed",
				$context
			);
		}

		if ($failedCount > 0) {
			$this->logOperationWarning(
				$operation,
				"Partial success: {$successCount} succeeded, {$failedCount} failed",
				$context
			);
		} else {
			$this->logOperationSuccess($operation, $context);
		}

		// Dolibarr treats zero as success; only partial or complete success reaches this point.
		return 0;
	}

	/**
	 * Log SQL query error with context
	 *
	 * @param string $operation Operation name
	 * @param DoliDB $db Database object
	 * @param array $context Optional context (query snippet, parameters, etc.)
	 * @return void
	 */
	protected function logSqlError($operation, $db, array $context = [])
	{
		$context['sql_error'] = $db->lasterror();
		$this->logOperationError($operation, "SQL query failed", $context);
	}

	/**
	 * Log API call with request and response details
	 *
	 * @param string $operation Operation name
	 * @param string $endpoint API endpoint
	 * @param array $payload Request payload
	 * @param mixed $response API response
	 * @param bool $success Whether the call succeeded
	 * @return void
	 */
	protected function logApiCall($operation, $endpoint, $payload, $response, $success)
	{
		$payloadPreview = $this->summarizeValueForLog($payload, 600);
		$responseCode = 'unknown';
		if (is_object($response) && isset($response->Response->StatusCode)) {
			$responseCode = $response->Response->StatusCode;
		} elseif (is_array($response) && isset($response['Response']['StatusCode'])) {
			$responseCode = $response['Response']['StatusCode'];
		}

		$context = [
			'endpoint' => $endpoint,
			'payload_size_estimate' => strlen($payloadPreview),
			'response_code' => $responseCode
		];

		if ($success) {
			$this->logDebug($operation, "API call successful: {$endpoint}", $context);
		} else {
			$context['response_preview'] = $this->summarizeValueForLog($response, 400);
			$this->logOperationError($operation, "API call failed: {$endpoint}", $context);
		}
	}

	/**
	 * Create standardized context array for invoice operations
	 *
	 * @param string $invoiceRef Invoice reference
	 * @param int|null $fkFacture Dolibarr invoice ID
	 * @param int|null $syncStatus Synchronization status
	 * @return array Context array
	 */
	protected function createInvoiceContext($invoiceRef, $fkFacture = null, $syncStatus = null)
	{
		$context = ['invoice_ref' => $invoiceRef];

		if ($fkFacture !== null) {
			$context['fk_facture'] = $fkFacture;
		}

		if ($syncStatus !== null) {
			$context['sync_status'] = $syncStatus;
			$context['sync_status_name'] = ZSSyncStatus::getStatusDescription($syncStatus);
		}

		return $context;
	}
}

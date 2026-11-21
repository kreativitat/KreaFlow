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
 * \file        class/zsapihttpclient.class.php
 * \ingroup     DoliZSynch
 * \brief       HTTP client utility for ZoneSoft API requests
 */

require_once DOL_DOCUMENT_ROOT . '/custom/kreaflow/class/zsapiexception.class.php';

/**
 * HTTP Client for ZoneSoft API
 */
class ZSAPIHttpClient
{
    /**
     * Maximum retry attempts for transient API failures.
     *
     * @var int
     */
    const MAX_RETRIES = 4;

    /**
     * Base delay in milliseconds for exponential backoff.
     *
     * @var int
     */
    const BACKOFF_BASE_MS = 300;

    /**
     * Maximum delay in milliseconds for exponential backoff.
     *
     * @var int
     */
    const BACKOFF_MAX_MS = 10000;

    /**
     * Send a POST request to ZoneSoft API
     *
     * @param string $url The API endpoint URL
     * @param array $payload The data to send
     * @param array $options API credentials (app_key, app_secret, client_id)
     * @return array|false The API response or false on failure
     * @throws Exception On cURL errors or JSON encoding/decoding errors
     */
    public static function post($url, $payload, $options)
    {
        // Validate required options
        $required = ['app_key', 'app_secret', 'client_id'];
        foreach ($required as $key) {
            if (empty($options[$key])) {
                throw new ZSAPIException("Missing required API option: $key");
            }
        }

        // Convert payload to JSON
        $jsonPayload = json_encode($payload);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new ZSAPIException("JSON encode error: " . json_last_error_msg());
        }

        // Generate HMAC SHA256 signature
        $signature = hash_hmac('sha256', $jsonPayload, $options['app_secret']);

        $headers = [
            'Content-Type: application/json',
            'X-ZS-SIGNATURE: ' . $signature,
            'X-ZS-CLIENT-ID: ' . $options['client_id'],
            'X-ZS-APP-KEY: ' . $options['app_key']
        ];

        $maxAttempts = self::MAX_RETRIES + 1;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            // Initialize cURL per attempt to avoid state leakage between retries.
            $curl = curl_init($url);
            if ($curl === false) {
                throw new ZSAPIException("cURL initialization failed");
            }

            // Configure cURL options
            curl_setopt_array($curl, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $jsonPayload,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_CONNECTTIMEOUT => 10
            ]);

            // Execute request
            $responseEncoded = curl_exec($curl);
            $httpCode = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
            $curlErrno = (int) curl_errno($curl);
            $curlError = curl_error($curl);
            curl_close($curl);

            // Handle transport-level failures.
            if ($responseEncoded === false) {
                if ($attempt < $maxAttempts && self::isRetryableCurlError($curlErrno)) {
                    self::sleepWithJitter(
                        $attempt,
                        "cURL errno {$curlErrno}",
                        [
                            'url' => $url,
                            'http_code' => $httpCode,
                            'curl_errno' => $curlErrno,
                        ]
                    );
                    continue;
                }

                $errorMsg = "cURL error (code $curlErrno): $curlError";
                if ($httpCode > 0) {
                    $errorMsg .= " (HTTP $httpCode)";
                }

                dol_syslog("ZSAPIHttpClient::post cURL failed: " . $errorMsg, LOG_ERR);
                self::recordApiHealthEvent(
                    'api_retry_exhausted',
                    'API transport failure after retries',
                    [
                        'url' => $url,
                        'attempt' => $attempt,
                        'max_attempts' => $maxAttempts,
                        'http_code' => $httpCode,
                        'curl_errno' => $curlErrno,
                    ],
                    'ERROR'
                );
                throw new ZSAPIException($errorMsg, $httpCode, null, ['url' => $url, 'curl_errno' => $curlErrno]);
            }

            // Handle HTTP 204 No Content - this is a valid success response with no data
            if ($httpCode === 204) {
                return array();
            }

            // Check for empty response before JSON decode
            if (empty($responseEncoded)) {
                if ($attempt < $maxAttempts && self::isRetryableStatusCode($httpCode)) {
                    self::sleepWithJitter(
                        $attempt,
                        "HTTP {$httpCode} with empty body",
                        [
                            'url' => $url,
                            'http_code' => $httpCode,
                        ]
                    );
                    continue;
                }

                throw new ZSAPIException("Empty response from API", $httpCode, null, ['url' => $url, 'response' => '']);
            }

            // Decode JSON response
            $response = json_decode($responseEncoded, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                if ($attempt < $maxAttempts && self::isRetryableStatusCode($httpCode)) {
                    self::sleepWithJitter(
                        $attempt,
                        "JSON decode after HTTP {$httpCode}",
                        [
                            'url' => $url,
                            'http_code' => $httpCode,
                        ]
                    );
                    continue;
                }

                throw new ZSAPIException(
                    "JSON decode error: " . json_last_error_msg(),
                    $httpCode,
                    null,
                    [
                        'url' => $url,
                        'response_bytes' => strlen((string) $responseEncoded),
                        'response_hash' => hash('sha256', (string) $responseEncoded)
                    ]
                );
            }

            $apiStatusCode = isset($response['Response']['StatusCode']) ? (int) $response['Response']['StatusCode'] : 0;
            $retryableStatus = self::isRetryableStatusCode($apiStatusCode) || self::isRetryableStatusCode($httpCode);

            if ($retryableStatus && $attempt < $maxAttempts) {
                self::sleepWithJitter(
                    $attempt,
                    "API status {$apiStatusCode}" . ($httpCode > 0 ? " (HTTP {$httpCode})" : ""),
                    [
                        'url' => $url,
                        'api_status_code' => $apiStatusCode,
                        'http_code' => $httpCode,
                    ]
                );
                continue;
            }

            if ($retryableStatus && $attempt >= $maxAttempts) {
                if (self::isRateLimitStatus($apiStatusCode, $httpCode)) {
                    self::recordApiHealthEvent(
                        'api_rate_limit',
                        'Rate limit response with no retries left',
                        [
                            'url' => $url,
                            'attempt' => $attempt,
                            'max_attempts' => $maxAttempts,
                            'api_status_code' => $apiStatusCode,
                            'http_code' => $httpCode,
                        ],
                        'WARNING'
                    );
                }

                self::recordApiHealthEvent(
                    'api_retry_exhausted',
                    'Retryable API status exhausted retry budget',
                    [
                        'url' => $url,
                        'attempt' => $attempt,
                        'max_attempts' => $maxAttempts,
                        'api_status_code' => $apiStatusCode,
                        'http_code' => $httpCode,
                    ],
                    'ERROR'
                );
            }

            return $response;
        }

        // Defensive fallback: loop always returns or throws.
        throw new ZSAPIException("Unexpected API client retry termination", 0, null, ['url' => $url]);
    }

    /**
     * Determines if an HTTP or API status code is transient/retryable.
     *
     * @param int $statusCode HTTP or API status code
     * @return bool
     */
    private static function isRetryableStatusCode($statusCode)
    {
        return in_array((int) $statusCode, [429, 500, 502, 503, 504], true);
    }

    /**
     * Determines if a cURL error code is transient/retryable.
     *
     * @param int $curlErrno cURL errno
     * @return bool
     */
    private static function isRetryableCurlError($curlErrno)
    {
        return in_array((int) $curlErrno, [6, 7, 18, 28, 35, 52, 55, 56, 92], true);
    }

    /**
     * Sleeps using exponential backoff with full jitter.
     *
     * @param int $attempt Current attempt number (1-based)
     * @param string $reason Retry reason for logs
     * @param array<string,mixed> $context Optional retry context
     * @return void
     */
    private static function sleepWithJitter($attempt, $reason, array $context = [])
    {
        $exponentialMs = (int) min(self::BACKOFF_MAX_MS, self::BACKOFF_BASE_MS * pow(2, max(0, (int) $attempt - 1)));
        $sleepMs = mt_rand(0, max(0, $exponentialMs));

        $logContext = array_merge($context, [
            'attempt' => (int) $attempt,
            'sleep_ms' => (int) $sleepMs,
            'reason' => (string) $reason,
        ]);

        dol_syslog(
            "ZSAPIHttpClient::post retry scheduled: " . self::encodeLogContext($logContext),
            LOG_WARNING
        );

        self::recordApiHealthEvent(
            self::resolveRetryCategory($reason),
            "Retry scheduled: " . (string) $reason,
            $logContext,
            'WARNING'
        );

        if ($sleepMs > 0) {
            usleep($sleepMs * 1000);
        }
    }

    /**
     * Resolve retry category for health metrics.
     *
     * @param string $reason
     * @return string
     */
    private static function resolveRetryCategory($reason)
    {
        $reason = (string) $reason;
        if (strpos($reason, '429') !== false) {
            return 'api_rate_limit';
        }
        if (stripos($reason, 'cURL errno') !== false) {
            return 'api_network_retry';
        }
        return 'api_retry';
    }

    /**
     * Check if either API status or HTTP status indicates rate limiting.
     *
     * @param int $apiStatusCode
     * @param int $httpCode
     * @return bool
     */
    private static function isRateLimitStatus($apiStatusCode, $httpCode)
    {
        return ((int) $apiStatusCode === 429 || (int) $httpCode === 429);
    }

    /**
     * Whether this request URL should log raw JSON payload for invoice debugging.
     *
     * @param string $url
     * @return bool
     */
    private static function shouldLogRawInvoicePayload($url)
    {
        $url = strtolower((string) $url);

        return (
            strpos($url, '/invoicedocuments/saveinstance') !== false
            || strpos($url, '/workingdocuments/saveinstance') !== false
            || strpos($url, '/documents/saveinstances') !== false
        );
    }

    /**
     * Persist API health events for dashboard visibility.
     *
     * @param string $category
     * @param string $message
     * @param array<string,mixed> $context
     * @param string $severity
     * @return void
     */
    private static function recordApiHealthEvent($category, $message, array $context = [], $severity = 'WARNING')
    {
        global $db, $conf;

        if (!defined('MAIN_DB_PREFIX') || !isset($db) || !is_object($db) || !method_exists($db, 'query') || !method_exists($db, 'escape')) {
            return;
        }

        $table = MAIN_DB_PREFIX . 'dolizsynch_error_log';
        $entity = (isset($conf) && is_object($conf) && isset($conf->entity)) ? (int) $conf->entity : 1;
        $contextJson = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($contextJson === false) {
            $contextJson = '{}';
        }
        $nowTs = function_exists('dol_now') ? dol_now() : time();

        $sql = "INSERT INTO " . $table . " (entity, message, severity, category, context, date_creation) VALUES (";
        $sql .= $entity . ", ";
        $sql .= "'" . $db->escape((string) $message) . "', ";
        $sql .= "'" . $db->escape(substr((string) $severity, 0, 20)) . "', ";
        $sql .= "'" . $db->escape(substr((string) $category, 0, 50)) . "', ";
        $sql .= "'" . $db->escape((string) $contextJson) . "', ";
        $sql .= "'" . $db->idate($nowTs) . "'";
        $sql .= ")";

        $db->query($sql);
    }

    /**
     * Build a non-sensitive summary for request payload logs.
     *
     * @param array $payload
     * @param string $jsonPayload
     * @return array<string, mixed>
     */
    private static function buildPayloadLogSummary(array $payload, $jsonPayload)
    {
        $entities = [];
        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                $entities[(string) $key] = [
                    'is_list' => self::isListArray($value),
                    'count' => count($value),
                    'sample_keys' => array_slice(array_keys($value), 0, 12),
                ];
            } else {
                $entities[(string) $key] = ['type' => gettype($value)];
            }
        }

        return [
            'bytes' => strlen((string) $jsonPayload),
            'payload_hash' => hash('sha256', (string) $jsonPayload),
            'root_keys' => array_keys($payload),
            'entities' => $entities,
        ];
    }

    /**
     * Build a non-sensitive summary for response logs.
     *
     * @param array $response
     * @param string $responseEncoded
     * @param int $httpCode
     * @return array<string, mixed>
     */
    private static function buildResponseLogSummary(array $response, $responseEncoded, $httpCode)
    {
        $responseNode = (isset($response['Response']) && is_array($response['Response'])) ? $response['Response'] : [];
        $content = (isset($responseNode['Content']) && is_array($responseNode['Content'])) ? $responseNode['Content'] : [];

        $contentSummary = [];
        foreach ($content as $key => $value) {
            if (is_array($value)) {
                $contentSummary[(string) $key] = [
                    'is_list' => self::isListArray($value),
                    'count' => count($value),
                ];
            } else {
                $contentSummary[(string) $key] = ['type' => gettype($value)];
            }
        }

        return [
            'http_code' => (int) $httpCode,
            'api_status_code' => (int) ($responseNode['StatusCode'] ?? 0),
            'has_error_message' => !empty($responseNode['ErrorMessage']),
            'response_bytes' => strlen((string) $responseEncoded),
            'response_hash' => hash('sha256', (string) $responseEncoded),
            'content_keys' => array_keys($content),
            'content_summary' => $contentSummary,
        ];
    }

    /**
     * Safe JSON encoder for structured logs.
     *
     * @param array<string, mixed> $context
     * @return string
     */
    private static function encodeLogContext(array $context)
    {
        $encoded = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            return '{"log_context":"encoding_error"}';
        }
        return $encoded;
    }

    /**
     * Detect if an array is list-like (sequential integer keys).
     *
     * @param array $value
     * @return bool
     */
    private static function isListArray(array $value)
    {
        if ($value === []) {
            return true;
        }

        return array_keys($value) === range(0, count($value) - 1);
    }
}

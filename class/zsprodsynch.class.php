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
 * \file        class/test.class.php
 * \ingroup     dolizsynch
 * \brief       This file is a CRUD class file for Test (Create/Read/Update/Delete)
 */

// Put here all includes required by your class file

require_once DOL_DOCUMENT_ROOT . '/core/class/commonobject.class.php';
require_once DOL_DOCUMENT_ROOT . '/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT . '/custom/kreaflow/class/zsapisynch.class.php';
require_once DOL_DOCUMENT_ROOT . '/product/class/product.class.php';
require_once DOL_DOCUMENT_ROOT . '/user/class/user.class.php';
require_once __DIR__ . '/zsstoreprices.class.php';

/**
 * Class ZSProductSync
 *
 * Handles synchronization of product data between Dolibarr and ZoneSoft.
 * This class includes methods for updating products, and mapping products
 * between the two systems, and fetching product data from ZoneSoft.
 */
class ZSProductSynch
{
    public $error = '';
    private $db; // Database connection
    private $zoneSoftApi; // Instance of ZoneSoft API
    private $hasKreaAliasExtrafield = null;

    const PRICE_SCHEDULE_PENDING = 0;
    const PRICE_SCHEDULE_DONE = 1;
    const PRICE_SCHEDULE_ERROR = -1;
    const PRICE_SCHEDULE_CANCELED = 9;
    const PRICE_SCHEDULE_RULE_ENABLED = 0;
    const PRICE_SCHEDULE_RULE_DONE = 1;
    const PRICE_SCHEDULE_RULE_ERROR = -1;
    const PRICE_SCHEDULE_RULE_CANCELED = 9;
    const PRICE_SCHEDULE_RULE_TYPE_ONCE_WINDOW = 'once_window';
    const PRICE_SCHEDULE_RULE_TYPE_DAILY_WINDOW = 'daily_window';
    const PRICE_SCHEDULE_RULE_TYPE_WEEKLY_WINDOW = 'weekly_window';
    const PRICE_SCHEDULE_RULE_TYPE_MONTHLY_WINDOW = 'monthly_window';
    const PRICE_SCHEDULE_RULE_TYPE_YEARLY_WINDOW = 'yearly_window';
    const KREA_ALIAS_EXTRAFIELD = 'kreap_alias';

    /**
     * Constructor for ZSProductSync
     *
     * @param object $db Database connection object
     * @param object|null $zoneSoftApi Optional API adapter for isolated tests
     */
    public function __construct($db, $zoneSoftApi = null)
    {
        $this->db = $db;
        $this->zoneSoftApi = $zoneSoftApi ?: new ZSAPISynch($db);
    }

    /**
     * Build a bounded response summary for logging.
     *
     * @param mixed $value
     * @param int $maxLength
     * @return string
     */
    private function summarizeValueForLog($value, $maxLength = 512)
    {
        $maxLength = max(64, (int) $maxLength);

        if ($value === null) {
            return 'null';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        if (is_string($value)) {
            $value = str_replace(array("\r", "\n"), array('\r', '\n'), $value);
            if (strlen($value) > $maxLength) {
                return substr($value, 0, $maxLength) . '... [truncated]';
            }
            return $value;
        }
        if (is_array($value)) {
            $keys = array_keys($value);
            $preview = array_slice($keys, 0, 10);
            $preview = array_map('strval', $preview);
            $summary = 'array(count=' . count($value) . ', keys=[' . implode(', ', $preview) . (count($keys) > 10 ? ', ...' : '') . '])';
            return strlen($summary) > $maxLength ? substr($summary, 0, $maxLength) . '... [truncated]' : $summary;
        }
        if (is_object($value)) {
            $vars = get_object_vars($value);
            $keys = array_keys($vars);
            $preview = array_slice($keys, 0, 10);
            $preview = array_map('strval', $preview);
            $summary = 'object(' . get_class($value) . ', props=' . count($keys) . ', keys=[' . implode(', ', $preview) . (count($keys) > 10 ? ', ...' : '') . '])';
            return strlen($summary) > $maxLength ? substr($summary, 0, $maxLength) . '... [truncated]' : $summary;
        }

        return gettype($value);
    }

    /**
     * Extract per-instance validation errors from a ZSBMS response payload.
     *
     * @param array $response API response
     * @param string|null $entityKey Preferred entity key inside Response.Content
     * @return array<int, array<string, string>>
     */
    private function extractInstanceErrorsFromApiResponse(array $response, $entityKey = null)
    {
        $errors = [];
        $content = $response['Response']['Content'] ?? null;
        if (!is_array($content)) {
            return $errors;
        }

        if ($entityKey !== null && array_key_exists($entityKey, $content)) {
            $content = [$entityKey => $content[$entityKey]];
        }

        $walker = function ($node, $path = '') use (&$walker, &$errors) {
            if (!is_array($node)) {
                return;
            }

            if (!empty($node['_errors']) && is_array($node['_errors'])) {
                foreach ($node['_errors'] as $item) {
                    if (!is_array($item)) {
                        continue;
                    }
                    $errors[] = [
                        'path' => (string) $path,
                        'property' => (string) ($item['property'] ?? ''),
                        'error' => (string) ($item['error'] ?? ''),
                    ];
                }
            }

            foreach ($node as $key => $child) {
                if ($key === '_errors' || !is_array($child)) {
                    continue;
                }

                $segment = (string) $key;
                if (ctype_digit($segment)) {
                    $childPath = $path === '' ? '[' . $segment . ']' : $path . '[' . $segment . ']';
                } else {
                    $childPath = $path === '' ? $segment : $path . '.' . $segment;
                }

                $walker($child, $childPath);
            }
        };

        $walker($content, '');

        return $errors;
    }

    /**
     * Build a compact summary from parsed instance errors.
     *
     * @param array<int, array<string, string>> $instanceErrors
     * @param int $maxItems
     * @return string
     */
    private function buildInstanceErrorsSummary(array $instanceErrors, $maxItems = 3)
    {
        if (empty($instanceErrors)) {
            return '';
        }

        $items = [];
        $slice = array_slice($instanceErrors, 0, max(1, (int) $maxItems));
        foreach ($slice as $err) {
            $prefix = '';
            if (!empty($err['path'])) {
                $prefix .= $err['path'] . ': ';
            }
            if (!empty($err['property'])) {
                $prefix .= $err['property'] . ' - ';
            }
            $items[] = trim($prefix . (string) ($err['error'] ?? 'Validation error'));
        }

        $summary = implode(' | ', $items);
        $extra = count($instanceErrors) - count($slice);
        if ($extra > 0) {
            $summary .= ' | +' . $extra . ' more validation error(s)';
        }

        return $summary;
    }

    /**
     * Extract fallback textual API errors from Response.Content when _errors/ErrorMessage are absent.
     *
     * @param mixed $content
     * @param int $maxItems
     * @return string
     */
    private function extractApiErrorSummaryFromContent($content, $maxItems = 3)
    {
        if (is_object($content)) {
            $content = json_decode(json_encode($content), true);
        }
        if (!is_array($content)) {
            return '';
        }

        $messages = array();
        $appendMessage = function ($value) use (&$messages) {
            if (!is_scalar($value)) {
                return;
            }

            $message = trim((string) $value);
            if ($message === '') {
                return;
            }

            $hash = strtolower($message);
            if (!isset($messages[$hash])) {
                $messages[$hash] = $message;
            }
        };

        $walker = function ($node) use (&$walker, &$appendMessage) {
            if (is_object($node)) {
                $node = json_decode(json_encode($node), true);
            }
            if (!is_array($node)) {
                return;
            }

            foreach ($node as $key => $value) {
                $keyLower = strtolower((string) $key);

                if (
                    in_array($keyLower, array('error', 'errormessage', 'statusmessage', 'message', 'detail', 'description', 'reason'), true)
                    && is_scalar($value)
                ) {
                    $appendMessage($value);
                    continue;
                }

                if ($keyLower === '_errors' && is_array($value)) {
                    foreach ($value as $errorItem) {
                        if (!is_array($errorItem)) {
                            continue;
                        }
                        if (array_key_exists('error', $errorItem)) {
                            $appendMessage($errorItem['error']);
                        } elseif (array_key_exists('message', $errorItem)) {
                            $appendMessage($errorItem['message']);
                        }
                    }
                    continue;
                }

                if (is_array($value) || is_object($value)) {
                    $walker($value);
                    continue;
                }

                if (is_scalar($value) && (strpos($keyLower, 'error') !== false || strpos($keyLower, 'message') !== false)) {
                    $appendMessage($value);
                }
            }
        };

        $walker($content);

        if (empty($messages)) {
            return '';
        }

        $maxItems = max(1, (int) $maxItems);
        $messages = array_values($messages);
        $messages = array_slice($messages, 0, $maxItems);

        return implode(' | ', $messages);
    }

    /**
     * Detect immutable-description errors from status/error text when _errors are unavailable.
     *
     * @param string $statusMessage
     * @param string $errorMessage
     * @param string $contentErrorSummary
     * @return bool
     */
    private function hasImmutableProductDescricaoSignature($statusMessage, $errorMessage, $contentErrorSummary = '')
    {
        $combined = strtolower(trim((string) $statusMessage . ' ' . (string) $errorMessage . ' ' . (string) $contentErrorSummary));
        if ($combined === '') {
            return false;
        }

        $mentionsDescricao = (
            strpos($combined, 'descricao') !== false
            || strpos($combined, 'description') !== false
            || strpos($combined, 'products.descricao') !== false
        );
        if (!$mentionsDescricao) {
            return false;
        }

        $immutableHints = array(
            'cannot be modified',
            'cannot modify',
            'read only',
            'readonly',
            'immutable',
            'nao pode ser alterada',
            'nao pode ser modificada',
            'nao pode ser modificad'
        );

        foreach ($immutableHints as $hint) {
            if (strpos($combined, $hint) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Detect immutable-description validation errors returned by ZSBMS.
     *
     * @param array<int, array<string, string>> $instanceErrors
     * @return bool
     */
    private function hasImmutableProductDescricaoError(array $instanceErrors)
    {
        if (empty($instanceErrors)) {
            return false;
        }

        foreach ($instanceErrors as $errorItem) {
            $property = strtolower(trim((string) ($errorItem['property'] ?? '')));
            if ($property === '' || strpos($property, 'products.descricao') === false) {
                continue;
            }

            $errorMessage = strtolower((string) ($errorItem['error'] ?? ''));
            if (strpos($errorMessage, 'cannot be modified') !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Fetch the authoritative ZSBMS product before updating an existing instance.
     *
     * @param mixed $codigo Product code used by ZSBMS
     * @param int $store ZSBMS store identifier
     * @return array|null Remote product, or null when unavailable
     */
    private function fetchRemoteProductForUpdate($codigo, $store)
    {
        if (!is_numeric($codigo) || (int) $codigo <= 0 || (int) $store <= 0) {
            return null;
        }

        try {
            $response = $this->zoneSoftApi->executeUpdateProducts(
                'getInstance',
                array('product' => array('codigo' => (int) $codigo)),
                (int) $store
            );
        } catch (Throwable $e) {
            dol_syslog(__METHOD__ . ': unable to read product ' . ((int) $codigo)
                . ' from store ' . ((int) $store) . ': ' . $e->getMessage(), LOG_WARNING);
            return null;
        }

        if (!is_array($response) || !isset($response['Response']) || !is_array($response['Response'])) {
            dol_syslog(__METHOD__ . ': invalid response for product ' . ((int) $codigo)
                . ' in store ' . ((int) $store), LOG_WARNING);
            return null;
        }

        $statusCode = (int) ($response['Response']['StatusCode'] ?? 0);
        $remoteProduct = $response['Response']['Content']['product'] ?? null;
        if ($statusCode !== 200 || !is_array($remoteProduct)) {
            dol_syslog(__METHOD__ . ': product ' . ((int) $codigo) . ' was not readable in store '
                . ((int) $store) . ' (status ' . $statusCode . ')', LOG_WARNING);
            return null;
        }

        if (isset($remoteProduct[0]) && is_array($remoteProduct[0])) {
            $remoteProduct = $remoteProduct[0];
        }

        return $remoteProduct;
    }

    /**
     * Remove response-only metadata recursively before sending data to saveInstances.
     *
     * @param mixed $value Payload value
     * @return mixed Sanitized payload value
     */
    private function removeZsbmsResponseMetadata($value)
    {
        if (!is_array($value)) {
            return $value;
        }

        $sanitized = array();
        foreach ($value as $key => $item) {
            if ($key === '_errors' || $key === 'lastupdate') {
                continue;
            }
            $sanitized[$key] = $this->removeZsbmsResponseMetadata($item);
        }

        return $sanitized;
    }

    /**
     * Preserve store-owned values that ZSBMS rejects on existing transactional products.
     *
     * @param array $productDetails Outgoing product model
     * @param array $remoteProduct Current ZSBMS product model
     * @param bool|array<int, int> $overrideVariablePrices True for all levels, or the explicit levels allowed to replace -1
     * @return array Constrained outgoing model
     */
    private function applyRemoteProductUpdateConstraints(array $productDetails, array $remoteProduct, $overrideVariablePrices)
    {
        if (!empty($remoteProduct['datacriacao'])) {
            $productDetails['datacriacao'] = $remoteProduct['datacriacao'];
        }

        for ($level = 1; $level <= 10; $level++) {
            $ttcField = $level === 1 ? 'precovenda' : 'pvp' . $level;
            $htField = $level === 1 ? 'pvp1siva' : 'pvp' . $level . 'siva';
            if (
                !$this->shouldOverrideVariablePriceLevel($overrideVariablePrices, $level)
                && array_key_exists($ttcField, $remoteProduct)
                && array_key_exists($htField, $remoteProduct)
                && (float) $remoteProduct[$ttcField] === -1.0
                && (float) $remoteProduct[$htField] === -1.0
            ) {
                $productDetails[$ttcField] = -1;
                $productDetails[$htField] = -1;
            }
        }

        return $this->removeZsbmsResponseMetadata($productDetails);
    }

    /**
     * Check whether an explicit Dolibarr price change may replace a remote variable-price marker.
     *
     * @param bool|array<int, int> $overrideVariablePrices True for all levels, or a list of allowed levels
     * @param int $level Price level being evaluated
     * @return bool
     */
    private function shouldOverrideVariablePriceLevel($overrideVariablePrices, $level)
    {
        if ($overrideVariablePrices === true) {
            return true;
        }
        if (!is_array($overrideVariablePrices)) {
            return false;
        }

        foreach ($overrideVariablePrices as $overrideLevel) {
            if ((int) $overrideLevel === (int) $level) {
                return true;
            }
        }

        return false;
    }

    /**
     * Ensure `product.kreap_alias` exists (created as KreaProducts extrafield) and migrate legacy mirror values.
     *
     * @return bool
     */
    private function ensureKreaAliasExtrafield()
    {
        if ($this->hasKreaAliasExtrafield !== null) {
            return (bool) $this->hasKreaAliasExtrafield;
        }

        $exists = false;
        $res = $this->db->DDLDescTable(MAIN_DB_PREFIX . 'product_extrafields', self::KREA_ALIAS_EXTRAFIELD);
        if ($res) {
            $exists = ($this->db->num_rows($res) > 0);
            $this->db->free($res);
        }

        if (!$exists) {
            require_once DOL_DOCUMENT_ROOT . '/core/class/extrafields.class.php';
            $extrafields = new ExtraFields($this->db);
            $fieldLabel = 'Krea Alias';
            $fieldHelp = 'Mirror of ZoneSoft short description (descricaocurta) synchronized by KreaFlow';
            $extrafields->addExtraField(
                self::KREA_ALIAS_EXTRAFIELD,
                $fieldLabel,
                'varchar',
                305,
                255,
                'product',
                0,
                0,
                '',
                '',
                1,
                '',
                1,
                $fieldHelp,
                '',
                '',
                'kreaproducts@kreaproducts',
                '1'
            );
            $extrafields->updateExtraField(
                self::KREA_ALIAS_EXTRAFIELD,
                $fieldLabel,
                'varchar',
                305,
                255,
                'product',
                0,
                0,
                '',
                '',
                1,
                '',
                1,
                $fieldHelp,
                '',
                '',
                'kreaproducts@kreaproducts',
                '1'
            );

            $res = $this->db->DDLDescTable(MAIN_DB_PREFIX . 'product_extrafields', self::KREA_ALIAS_EXTRAFIELD);
            if ($res) {
                $exists = ($this->db->num_rows($res) > 0);
                $this->db->free($res);
            }
        }

        if ($exists) {
            $legacyExists = false;
            $resLegacy = $this->db->DDLDescTable(MAIN_DB_PREFIX . 'product_extrafields', 'kreap_zs_descricaocurta');
            if ($resLegacy) {
                $legacyExists = ($this->db->num_rows($resLegacy) > 0);
                $this->db->free($resLegacy);
            }

            if ($legacyExists) {
                $entityList = getEntity('product');
                $sqlLegacy = "UPDATE " . MAIN_DB_PREFIX . "product_extrafields pe";
                $sqlLegacy .= " INNER JOIN " . MAIN_DB_PREFIX . "product p ON p.rowid = pe.fk_object";
                $sqlLegacy .= " SET pe." . self::KREA_ALIAS_EXTRAFIELD . " = pe.kreap_zs_descricaocurta";
                $sqlLegacy .= " WHERE (pe." . self::KREA_ALIAS_EXTRAFIELD . " IS NULL OR pe." . self::KREA_ALIAS_EXTRAFIELD . " = '')";
                $sqlLegacy .= " AND pe.kreap_zs_descricaocurta IS NOT NULL AND pe.kreap_zs_descricaocurta <> ''";
                if (!empty($entityList)) {
                    $sqlLegacy .= " AND p.entity IN (" . $entityList . ")";
                }
                $this->db->query($sqlLegacy);
            }
        }

        $this->hasKreaAliasExtrafield = $exists;
        return $exists;
    }

    /**
     * Keep the KreaProducts mirror extrafield aligned with ZS short description.
     *
     * @param int $productId Dolibarr product id
     * @param string $descricaoCurta ZoneSoft short description
     * @return bool
     */
    private function syncKreaAliasMirror($productId, $descricaoCurta)
    {
        $productId = (int) $productId;
        if ($productId <= 0) {
            return false;
        }

        if (!$this->ensureKreaAliasExtrafield()) {
            return false;
        }

        $entityList = getEntity('product');
        $shortDesc = trim((string) $descricaoCurta);
        $valueSql = ($shortDesc === '') ? 'NULL' : ("'" . $this->db->escape($shortDesc) . "'");

        $sql = "INSERT INTO " . MAIN_DB_PREFIX . "product_extrafields (fk_object, " . self::KREA_ALIAS_EXTRAFIELD . ") ";
        $sql .= "SELECT p.rowid, " . $valueSql . " FROM " . MAIN_DB_PREFIX . "product p";
        $sql .= " WHERE p.rowid = " . $productId;
        if (!empty($entityList)) {
            $sql .= " AND p.entity IN (" . $entityList . ")";
        }
        $sql .= " ON DUPLICATE KEY UPDATE " . self::KREA_ALIAS_EXTRAFIELD . " = " . $valueSql;

        if (!$this->db->query($sql)) {
            dol_syslog(__METHOD__ . " - Failed to sync mirror extrafield for product {$productId}: " . $this->db->lasterror(), LOG_WARNING);
            return false;
        }

        return true;
    }

    /**
     * Persist parsed per-instance errors to generic error log table.
     *
     * @param string $category Error category
     * @param string $message Message text
     * @param array<int, array<string, string>> $instanceErrors Parsed errors
     * @param array $context Additional context
     * @return void
     */
    private function persistInstanceErrorsToErrorLog($category, $message, array $instanceErrors, array $context = [])
    {
        global $conf;

        if (empty($instanceErrors)) {
            return;
        }

        $payload = array_merge($context, ['instance_errors' => $instanceErrors]);
        $contextJson = json_encode($payload, JSON_UNESCAPED_UNICODE);
        if ($contextJson === false) {
            $contextJson = json_encode(['instance_errors' => $instanceErrors]);
        }

        $sql = "INSERT INTO " . MAIN_DB_PREFIX . "dolizsynch_error_log (entity, message, severity, category, context, date_creation) VALUES (";
        $sql .= (int) ($conf->entity ?? 1) . ", ";
        $sql .= "'" . $this->db->escape((string) $message) . "', ";
        $sql .= "'ERROR', ";
        $sql .= "'" . $this->db->escape((string) $category) . "', ";
        $sql .= "'" . $this->db->escape((string) $contextJson) . "', ";
        $sql .= "'" . $this->db->idate(dol_now()) . "'";
        $sql .= ")";

        if (!$this->db->query($sql)) {
            dol_syslog(__METHOD__ . ": Failed to persist instance errors to dolizsynch_error_log: " . $this->db->lasterror(), LOG_WARNING);
        }
    }

    /**
     * Normalize ZoneSoft product listing responses to an array of product objects.
     *
     * @param mixed $response Raw API response
     * @param string $operation Operation label for logs
     * @return array|false Array of stdClass products (empty array for 204/no content) or false on invalid/error response
     */
    private function normalizeProductListFromApiResponse($response, $operation)
    {
        if (!is_array($response) || !isset($response['Response']) || !is_array($response['Response'])) {
            dol_syslog($operation . ": Invalid response received from ZoneSoft", LOG_ERR);
            return false;
        }

        $statusCode = isset($response['Response']['StatusCode']) ? (int) $response['Response']['StatusCode'] : 0;

        if ($statusCode === 204) {
            return [];
        }

        if ($statusCode !== 200 && $statusCode !== 201) {
            $statusMessage = isset($response['Response']['StatusMessage']) ? (string) $response['Response']['StatusMessage'] : '';
            $errorMessage = isset($response['Response']['ErrorMessage']) ? (string) $response['Response']['ErrorMessage'] : '';
            $effectiveError = ($errorMessage !== '' ? $errorMessage : $statusMessage);
            $this->error = trim($effectiveError);
            dol_syslog(
                $operation . ": API error (status {$statusCode})" . ($this->error !== '' ? " - {$this->error}" : ''),
                LOG_ERR
            );
            return false;
        }

        $content = isset($response['Response']['Content']) && is_array($response['Response']['Content'])
            ? $response['Response']['Content']
            : [];
        $products = $content['product'] ?? [];

        if (is_object($products)) {
            $products = [$products];
        } elseif (is_array($products) && !$this->isListArray($products)) {
            // API may return a single product object map instead of a list.
            $products = [$products];
        } elseif (!is_array($products)) {
            $products = [];
        }

        $normalized = [];
        foreach ($products as $product) {
            if (is_array($product)) {
                $product = (object) $product;
            }
            if (!is_object($product)) {
                continue;
            }
            $normalized[] = $product;
        }

        return $normalized;
    }

    /**
     * Check if an array is a sequential list.
     *
     * @param array $value
     * @return bool
     */
    private function isListArray(array $value)
    {
        if ($value === []) {
            return true;
        }

        return array_keys($value) === range(0, count($value) - 1);
    }

    /**
     * Normalize a value into int when possible (keeps original when not numeric).
     *
     * @param mixed $value
     * @return mixed
     */
    private function coerceNumeric($value)
    {
        if ($value === null) {
            return 0;
        }
        if (is_int($value)) {
            return $value;
        }
        if (is_float($value)) {
            return (int) $value;
        }
        $str = trim((string) $value);
        if ($str === '') {
            return 0;
        }
        if (is_numeric($str)) {
            // int if looks like int, float otherwise
            return (strpos($str, '.') === false && strpos($str, ',') === false) ? (int) $str : (float) str_replace(',', '.', $str);
        }
        return $value;
    }

    /**
     * Decode a JSON-compatible value into an array.
     *
     * @param mixed $value
     * @return array
     */
    private function decodeJsonArrayField($value)
    {
        if ($value === null) {
            return array();
        }

        if (is_array($value)) {
            return $value;
        }

        if (is_object($value)) {
            $decodedObject = json_decode(json_encode($value), true);
            return is_array($decodedObject) ? $decodedObject : array();
        }

        $raw = trim((string) $value);
        if ($raw === '' || strtolower($raw) === 'null') {
            return array();
        }

        $decoded = json_decode($raw, true);
        if (json_last_error() === JSON_ERROR_NONE && is_string($decoded)) {
            // Handle double-encoded JSON strings
            $decoded2 = json_decode($decoded, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $decoded = $decoded2;
            }
        }
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return $decoded;
        }

        $repaired = html_entity_decode($raw, ENT_QUOTES | ENT_HTML5);
        if ($repaired !== $raw) {
            $decoded = json_decode($repaired, true);
            if (json_last_error() === JSON_ERROR_NONE && is_string($decoded)) {
                $decoded2 = json_decode($decoded, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $decoded = $decoded2;
                }
            }
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return $decoded;
            }
        }

        return array();
    }

    /**
     * Decode a JSON-compatible value into an array or null for payload fields.
     * Returns null when source is empty/invalid to avoid forcing [] into optional API fields.
     *
     * @param mixed $value
     * @return array|null
     */
    private function decodeJsonArrayFieldForPayload($value)
    {
        if ($value === null) {
            return null;
        }

        if (is_array($value)) {
            return $value;
        }

        if (is_object($value)) {
            $decodedObject = json_decode(json_encode($value), true);
            return is_array($decodedObject) ? $decodedObject : null;
        }

        $raw = trim((string) $value);
        if ($raw === '' || strtolower($raw) === 'null') {
            return null;
        }

        $decoded = json_decode($raw, true);
        if (json_last_error() === JSON_ERROR_NONE && is_string($decoded)) {
            // Handle double-encoded JSON strings
            $decoded2 = json_decode($decoded, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $decoded = $decoded2;
            }
        }
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return $decoded;
        }

        $repaired = html_entity_decode($raw, ENT_QUOTES | ENT_HTML5);
        if ($repaired !== $raw) {
            $decoded = json_decode($repaired, true);
            if (json_last_error() === JSON_ERROR_NONE && is_string($decoded)) {
                $decoded2 = json_decode($decoded, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $decoded = $decoded2;
                }
            }
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
    }

    /**
     * Check whether a store is mapped in current entity scope (via ZS_API_STORE constants).
     *
     * @param int|string $store
     * @return bool
     */
    private function isStoreMappedForCurrentEntity($store)
    {
        global $conf;

        $store = (int) $store;
        if ($store <= 0) {
            return false;
        }

        $currentEntity = (int) ($conf->entity ?? 1);
        $sql = "SELECT rowid FROM " . MAIN_DB_PREFIX . "const";
        $sql .= " WHERE name = 'ZS_API_STORE'";
        $sql .= " AND value = '" . $this->db->escape((string) $store) . "'";
        $sql .= " AND entity = " . $currentEntity;
        $sql .= " LIMIT 1";

        $resql = $this->db->query($sql);
        if (!$resql) {
            dol_syslog(__METHOD__ . ": Failed to validate store mapping for {$store}: " . $this->db->lasterror(), LOG_WARNING);
            return false;
        }

        $isMapped = ($this->db->num_rows($resql) > 0);
        $this->db->free($resql);

        return $isMapped;
    }

    /**
     * Normalize nested product arrays to the target store/codigo for saveInstances payload.
     *
     * @param array $items
     * @param int|string $store
     * @param mixed $codigoForPayload
     * @return array
     */
    private function normalizeNestedPayloadItemsForStore(array $items, $store, $codigoForPayload = null)
    {
        if (!$this->isListArray($items)) {
            return $items;
        }

        $store = (int) $store;
        $normalizedCodigo = null;
        if ($codigoForPayload !== null && $codigoForPayload !== '') {
            $normalizedCodigo = $this->coerceNumeric($codigoForPayload);
        }

        $normalized = array();
        foreach ($items as $item) {
            if (is_object($item)) {
                $item = json_decode(json_encode($item), true);
            }

            if (!is_array($item)) {
                $normalized[] = $item;
                continue;
            }

            if (array_key_exists('loja', $item)) {
                $item['loja'] = $store;
            }

            if ($normalizedCodigo !== null) {
                if (array_key_exists('codigo', $item)) {
                    $item['codigo'] = $normalizedCodigo;
                }
                if (array_key_exists('cod_produto', $item)) {
                    $item['cod_produto'] = $normalizedCodigo;
                }
            }

            $normalized[] = $item;
        }

        return $normalized;
    }

    /**
     * Normalize option value representation to API-friendly scalar string.
     *
     * @param mixed $value
     * @return string
     */
    private function normalizeProdutoOpcaoValor($value)
    {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if ($value === null) {
            return '0';
        }

        if (is_numeric($value)) {
            $number = 0 + $value;
            if (is_float($number) && floor($number) == $number) {
                $number = (int) $number;
            }
            return (string) $number;
        }

        $normalized = trim((string) $value);
        if ($normalized === '') {
            return '0';
        }

        $lower = strtolower($normalized);
        if ($lower === 'true') {
            return '1';
        }
        if ($lower === 'false') {
            return '0';
        }

        return $normalized;
    }

    /**
     * Normalize produto_opcoes into a dynamic option list expected by ZSBMS.
     * Supports both list format and map format, without hardcoded option names.
     *
     * @param mixed $rawOptions
     * @param mixed $productCode
     * @param mixed $store
     * @param mixed $legacyFuelValue
     * @param int|null $digitalMenuValue Explicit Digital Menu selection, or null to preserve it
     * @return array<int, array<string, mixed>>
     */
    private function normalizeProdutoOpcoesForPayload($rawOptions, $productCode, $store, $legacyFuelValue = null, $digitalMenuValue = null)
    {
        $normalizedByKey = array();
        $defaultCodigo = $this->coerceNumeric($productCode);
        $defaultLoja = (int) $store;

        $upsertOption = function ($opcao, $valor, $codigo = null, $loja = null, $errors = null) use (&$normalizedByKey, $defaultCodigo, $defaultLoja) {
            $opcao = trim((string) $opcao);
            if ($opcao === '') {
                return;
            }

            $key = strtoupper($opcao);
            $normalizedByKey[$key] = array(
                'codigo' => $this->coerceNumeric(($codigo !== null && $codigo !== '') ? $codigo : $defaultCodigo),
                'loja' => (int) (($loja !== null && $loja !== '') ? $loja : $defaultLoja),
                'opcao' => $opcao,
                'valor' => $this->normalizeProdutoOpcaoValor($valor),
                '_errors' => is_array($errors) ? $errors : array(),
            );
        };

        $options = $this->decodeJsonArrayField($rawOptions);
        if (!empty($options)) {
            if ($this->isListArray($options)) {
                foreach ($options as $item) {
                    if (is_object($item)) {
                        $item = json_decode(json_encode($item), true);
                    }

                    if (is_array($item) && array_key_exists('opcao', $item)) {
                        $upsertOption(
                            $item['opcao'],
                            $item['valor'] ?? ($item['value'] ?? '0'),
                            $item['codigo'] ?? null,
                            $item['loja'] ?? null,
                            $item['_errors'] ?? array()
                        );
                        continue;
                    }

                    if (is_array($item) && !$this->isListArray($item)) {
                        foreach ($item as $mapKey => $mapValue) {
                            $upsertOption($mapKey, $mapValue);
                        }
                        continue;
                    }

                    if (is_string($item) || is_numeric($item)) {
                        $upsertOption($item, '1');
                    }
                }
            } else {
                foreach ($options as $mapKey => $mapValue) {
                    if (is_array($mapValue) || is_object($mapValue)) {
                        $nestedValue = $this->decodeJsonArrayField($mapValue);
                        if (is_array($nestedValue) && array_key_exists('opcao', $nestedValue)) {
                            $upsertOption(
                                $nestedValue['opcao'],
                                $nestedValue['valor'] ?? ($nestedValue['value'] ?? '0'),
                                $nestedValue['codigo'] ?? null,
                                $nestedValue['loja'] ?? null,
                                $nestedValue['_errors'] ?? array()
                            );
                            continue;
                        }
                    }

                    $upsertOption($mapKey, $mapValue);
                }
            }
        }

        // Backward compatibility for legacy local "fuel" column.
        if ($legacyFuelValue !== null && $legacyFuelValue !== '' && !isset($normalizedByKey['FUEL'])) {
            $upsertOption('FUEL', $legacyFuelValue);
        }

        // The API persists Digital Menu as a named product option, not a root property.
        if ($digitalMenuValue !== null) {
            $upsertOption('EMENTA_DIGITAL', (int) (bool) $digitalMenuValue);
        }

        return array_values($normalizedByKey);
    }

    /** Read the API option, retaining the legacy root field only as a fallback. */
    public static function getDigitalMenuValue($rawOptions, $legacyValue = null)
    {
        $options = is_string($rawOptions) ? json_decode($rawOptions, true) : $rawOptions;
        foreach ((array) $options as $key => $item) {
            if (is_object($item)) {
                $item = (array) $item;
            }
            if (is_array($item) && strtoupper(trim((string) ($item['opcao'] ?? ''))) === 'EMENTA_DIGITAL') {
                return (int) (bool) ($item['valor'] ?? 0);
            }
            if (strtoupper((string) $key) === 'EMENTA_DIGITAL' && is_scalar($item)) {
                return (int) (bool) $item;
            }
        }
        return $legacyValue === null ? null : (int) (bool) $legacyValue;
    }

    /**
     * Extract one option value from normalized produto_opcoes list.
     *
     * @param array<int,mixed> $produtoOpcoes
     * @param string $optionName
     * @return string|null
     */
    private function findProdutoOpcaoValor(array $produtoOpcoes, $optionName)
    {
        $target = strtoupper(trim((string) $optionName));
        if ($target === '') {
            return null;
        }

        foreach ($produtoOpcoes as $item) {
            if (!is_array($item)) {
                continue;
            }
            $currentOption = strtoupper(trim((string) ($item['opcao'] ?? '')));
            if ($currentOption !== $target) {
                continue;
            }

            return $this->normalizeProdutoOpcaoValor($item['valor'] ?? '0');
        }

        return null;
    }

    /**
     * Normalize menu item code (codigo) for ZSBMS payload.
     * - For family-like codes (e.g. "44_0"), keeps only the family id.
     *
     * @param mixed $rawCodigo
     * @param int   $fixo
     * @return int|null
     */
    private function normalizeNiveismenuItemCodigo($rawCodigo, $fixo)
    {
        $raw = trim((string) $rawCodigo);
        if ($raw === '') {
            return null;
        }

        // Family picker can store "family_subfamily" as value - ZSBMS expects just numeric id here.
        if ((int) $fixo === 2 && strpos($raw, '_') !== false) {
            $parts = explode('_', $raw);
            $raw = trim((string) $parts[0]);
        }

        if (!is_numeric($raw)) {
            dol_syslog("ZSProductSync::updateZsbmsProduct: Invalid menu item codigo='{$rawCodigo}' (fixo={$fixo}) - skipping item", LOG_WARNING);
            return null;
        }

        return (int) $raw;
    }

    /**
     * Normalize niveismenu JSON (from DB/UI) into the strict structure used by ZSBMS.
     *
     * @param string $rawJson
     * @param mixed  $menuCode
     * @return array
     */
    private function normalizeNiveismenuForZsbmsPayload($rawJson, $menuCode)
    {
        $raw = (string) $rawJson;

        $decoded = json_decode($raw, true);
        if (json_last_error() === JSON_ERROR_NONE && is_string($decoded)) {
            // Double-encoded JSON
            $decoded2 = json_decode($decoded, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $decoded = $decoded2;
            }
        }

        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
            // Try to repair if the JSON was HTML-escaped by mistake
            $repaired = html_entity_decode($raw, ENT_QUOTES | ENT_HTML5);
            if ($repaired !== $raw) {
                $decoded = json_decode($repaired, true);
                if (json_last_error() === JSON_ERROR_NONE && is_string($decoded)) {
                    $decoded2 = json_decode($decoded, true);
                    if (json_last_error() === JSON_ERROR_NONE) {
                        $decoded = $decoded2;
                    }
                }
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                    dol_syslog("ZSProductSync::updateZsbmsProduct: niveismenu JSON repaired via html_entity_decode()", LOG_WARNING);
                }
            }
        }

        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
            dol_syslog("ZSProductSync::updateZsbmsProduct: Failed to decode niveismenu JSON (" . json_last_error_msg() . "), sending empty array", LOG_WARNING);
            return array();
        }

        $menuValue = $this->coerceNumeric($menuCode);

        $out = array();
        $pos = 0;
        foreach ($decoded as $lvl) {
            if (!is_array($lvl)) {
                continue;
            }
            $pos++;

            $nivel = isset($lvl['nivel']) && $lvl['nivel'] !== '' ? (int) $lvl['nivel'] : $pos;
            if ($nivel <= 0) {
                $nivel = $pos;
            }

            $descricao = isset($lvl['descricao']) ? (string) $lvl['descricao'] : '';
            $obrigatorio = !empty($lvl['obrigatorio']) ? 1 : 0;
            if ($nivel === 1) {
                $obrigatorio = 1;
            }
            $ordem = (isset($lvl['ordem']) && $lvl['ordem'] !== '' && $lvl['ordem'] !== null && is_numeric($lvl['ordem'])) ? (int) $lvl['ordem'] : 0;

            $itemsOut = array();
            if (!empty($lvl['niveismenuext']) && is_array($lvl['niveismenuext'])) {
                foreach ($lvl['niveismenuext'] as $it) {
                    if (!is_array($it)) {
                        continue;
                    }
                    $fixo = isset($it['fixo']) && $it['fixo'] !== '' ? (int) $it['fixo'] : 0;
                    $codigo = $this->normalizeNiveismenuItemCodigo($it['codigo'] ?? '', $fixo);
                    if ($codigo === null) {
                        continue;
                    }

                    $opcaopvp = isset($it['opcaopvp']) && $it['opcaopvp'] !== '' && $it['opcaopvp'] !== null ? (int) $it['opcaopvp'] : 0;

                    $precoRaw = $it['preco'] ?? 0;
                    $preco = 0;
                    if ($precoRaw === '' || $precoRaw === null) {
                        $preco = 0;
                    } elseif (is_numeric($precoRaw)) {
                        $preco = 0 + $precoRaw;
                    } else {
                        $preco = 0;
                    }

                    $valcomplementos = !empty($it['valcomplementos']) ? 1 : 0;
                    $produtodefault = !empty($it['produtodefault']) ? 1 : 0;

                    $itemsOut[] = array(
                        'codigo' => $codigo,
                        'fixo' => $fixo,
                        'menu' => $menuValue,
                        'nivel' => $nivel,
                        'opcaopvp' => $opcaopvp,
                        'preco' => $preco,
                        'produtodefault' => $produtodefault,
                        'valcomplementos' => $valcomplementos,
                        '_errors' => array(),
                    );
                }
            }

            $out[] = array(
                'descricao' => $descricao,
                'menu' => $menuValue,
                'niveismenuext' => $itemsOut,
                'nivel' => $nivel,
                'obrigatorio' => $obrigatorio,
                'ordem' => $ordem,
                '_errors' => array(),
            );
        }

        return $out;
    }

    /**
     *
     *
     * Partial synch of products from ZS to Dolibarr
     *
     *
     */

    /**
     * Synchronizes specific instances of products that were changed from ZoneSoft to Dolibarr.
     *
     * This function fetches specific instances of products from ZoneSoft and updates
     * them in Dolibarr.
     */
    public function getInstancesToSyncFromZoneSoftProductsToDolibarr()
    {
        global $conf;

        $offset = !empty($conf->global->ZS_GET_PRODUCTS_OFFSET) ? (int) $conf->global->ZS_GET_PRODUCTS_OFFSET : 1;
        $limit = !empty($conf->global->ZS_GET_NUMBER_OF_PRODUCTS_LIMIT) ? (int) $conf->global->ZS_GET_NUMBER_OF_PRODUCTS_LIMIT : 5;
        if ($limit <= 0) {
            $limit = 5;
        }
        $allSuccess = true;

        $this->zoneSoftApi->setInterface('products');

        while (true) {
            $products = $this->getZoneSoftProducts($offset, $limit);

            if ($products === false) {
                dol_syslog("Failed to fetch products from ZoneSoft at offset {$offset}", LOG_ERR);
                $allSuccess = false;
                break;
            }

            if (empty($products)) {
                break;
            }

            foreach ($products as $zoneSoftProduct) {
                $updateSuccess = $this->updateDolibarrProductFromZoneSoft($zoneSoftProduct);
                $insertSuccess = $this->insertProductToLocalTable((object) ['product' => $zoneSoftProduct]);

                if (!$updateSuccess || !$insertSuccess) {
                    $allSuccess = false;
                }
            }

            if (count($products) < $limit) {
                break;
            }

            // Update the configuration value for offset
            $offset += $limit;
            dolibarr_set_const($this->db, 'ZS_GET_PRODUCTS_OFFSET', $offset);
        }

        return $allSuccess;
    }

    /**
     * Fetches products from ZoneSoft that need to be synchronized.
     *
     * This method fetches a batch of products from ZoneSoft that have been updated
     * within a specified number of hours. It uses the 'getInstancesToSync' API action.
     *
     * @param int $offset The offset for pagination in the ZoneSoft product list.
     * @return array|false Returns an array of products or false on failure.
     */
    public function getZoneSoftProductsToSync($offset)
    {
        global $conf;
        $limit = !empty($conf->global->ZS_GET_NUMBER_OF_PRODUCTS_LIMIT) ? (int) $conf->global->ZS_GET_NUMBER_OF_PRODUCTS_LIMIT : 250;
        if ($limit <= 0) {
            $limit = 250;
        }
        $this->zoneSoftApi->setInterface('products');

        // Synch only products changed in the last X minutes
        $synchMinutes = $conf->global->ZS_PRODUTS_CHANGED_LAST_X_MINUTES;

        // Timestamp for $synchMinutes minutes ago
        $dateTime = new DateTime();
        $dateTime->modify("-{$synchMinutes} minutes");
        $lastUpdate = $dateTime->format('Y-m-d H:i:s');

        // Define the payload for the request
        $payload = [
            "product" => [
                "lastupdate" => $lastUpdate,
                "offset" => $offset,
                "limit" => $limit
            ]
        ];

        $response = $this->zoneSoftApi->execute('getInstancesToSync', $payload);

        return $this->normalizeProductListFromApiResponse($response, __METHOD__);
    }

    /**
     * Updates a Dolibarr product with data from a ZoneSoft product.
     *
     * @param object $zoneSoftProduct Product object from ZoneSoft.
     * @return bool True on success, false on failure.
     */
    public function updateDolibarrProductFromZoneSoft($zoneSoftProduct)
    {
        global $user, $conf, $langs;

        // Correctly initialize $store
        $store = $conf->global->ZS_API_STORE;

        $product = new Product($this->db);

        // Find the Dolibarr product ID based on $zoneSoftProduct->codigo
        $dolibarrProductId = $this->findDolibarrProductId($zoneSoftProduct->codigo, $zoneSoftProduct, true);
        if (!$dolibarrProductId) {
            dol_syslog("No corresponding Dolibarr product found for ZoneSoft product ID: {$zoneSoftProduct->codigo}", LOG_ERR);
            return false;
        }

        if ($product->fetch($dolibarrProductId) > 0) {
            try {
                $storePriceState = (new ZSStorePrices($this->db))->fetch($product->id);
            } catch (Throwable $e) {
                $this->error = $e->getMessage();
                return false;
            }
            $product->label = $zoneSoftProduct->descricao;
            $product->tva_tx = $zoneSoftProduct->iva;
            $product->array_options['options_alias'] = $zoneSoftProduct->descricaocurta;
            if ($this->ensureKreaAliasExtrafield()) {
                $product->array_options['options_' . self::KREA_ALIAS_EXTRAFIELD] = $zoneSoftProduct->descricaocurta;
            }

            // Initialize variable price flag
            $hasVariablePrice = false;

            // Determine the product's price_base_type
            $priceBaseType = !empty($product->price_base_type) ? $product->price_base_type : 'HT';

            // IMPORTANT: First update the llx_dolizsynch_zsproduct table with fresh data from ZoneSoft
            // This ensures we're reading the latest prices (including variable prices with -1 values)
            dol_syslog("Updating llx_dolizsynch_zsproduct table with fresh data from ZoneSoft for product {$zoneSoftProduct->codigo}", LOG_INFO);

            // Log the price data we're about to update
            $priceData = [
                'precovenda' => $zoneSoftProduct->precovenda ?? 'null',
                'pvp1siva' => $zoneSoftProduct->pvp1siva ?? 'null',
                'pvp2' => $zoneSoftProduct->pvp2 ?? 'null',
                'pvp2siva' => $zoneSoftProduct->pvp2siva ?? 'null',
                'pvp3' => $zoneSoftProduct->pvp3 ?? 'null',
                'pvp3siva' => $zoneSoftProduct->pvp3siva ?? 'null',
            ];

            $updateResult = $this->insertProductToLocalTable((object)['product' => $zoneSoftProduct]);
            if (!$updateResult) {
                dol_syslog("CRITICAL: Failed to update llx_dolizsynch_zsproduct table for product {$zoneSoftProduct->codigo}. Check database errors above.", LOG_ERR);
                // This is a critical error - if we can't update the ZS table, the sync will use old data
                return false;
            } else {
                dol_syslog("Successfully updated llx_dolizsynch_zsproduct table for product {$zoneSoftProduct->codigo}", LOG_INFO);
            }

            // Fetch the product data from dolizsynch_zsproduct
            $sql = "SELECT * FROM " . MAIN_DB_PREFIX . "dolizsynch_zsproduct WHERE fk_product = " . (int)$dolibarrProductId . " AND loja_zs = " . (int)$store;
            $resql = $this->db->query($sql);
            if ($resql) {
                $obj = $this->db->fetch_object($resql);
                if ($obj) {
                    // Log what we read from the table to verify the update worked
                    $readPriceData = [
                        'precovenda' => $obj->precovenda ?? 'null',
                        'pvp1siva' => $obj->pvp1siva ?? 'null',
                        'pvp2' => $obj->pvp2 ?? 'null',
                        'pvp2siva' => $obj->pvp2siva ?? 'null',
                        'pvp3' => $obj->pvp3 ?? 'null',
                        'pvp3siva' => $obj->pvp3siva ?? 'null',
                    ];
                    // Fetch PRODUIT_MULTIPRICES_LIMIT
                    $produitMultipricesLimit = !empty($conf->global->PRODUIT_MULTIPRICES_LIMIT) ? $conf->global->PRODUIT_MULTIPRICES_LIMIT : 1;

                    // Check if multiprices are enabled
                    $multiPricesEnabled = !empty($conf->global->PRODUIT_MULTIPRICES);

                    if ($storePriceState['enabled']) {
                        // Store prices are independent; retain the common Dolibarr price.
                    } elseif ($multiPricesEnabled) {
                        for ($i = 1; $i <= $produitMultipricesLimit; $i++) {
                            if ($i == 1) {
                                // Level 1 has special field names
                                $priceField = ($priceBaseType == 'HT') ? 'pvp1siva' : 'precovenda';
                                $priceFieldAlt = ($priceBaseType == 'HT') ? 'precovenda' : 'pvp1siva';
                            } else {
                                $priceField = ($priceBaseType == 'HT') ? 'pvp' . $i . 'siva' : 'pvp' . $i;
                                $priceFieldAlt = ($priceBaseType == 'HT') ? 'pvp' . $i : 'pvp' . $i . 'siva';
                            }

                            // Check if the price field exists and is not null
                            // Send all prices including 0, but skip only if the field doesn't exist
                            if (isset($obj->$priceField) && $obj->$priceField !== null) {
                                $price = (float)$obj->$priceField;
                                $priceAlt = isset($obj->$priceFieldAlt) && $obj->$priceFieldAlt !== null ? (float)$obj->$priceFieldAlt : null;
                                $tva_tx = (float)$obj->iva;

                                // Check for "Preço Variável" (Variable Price)
                                // Variable price can be indicated by:
                                // 1. Both prices are exactly -1
                                // 2. TTC price is -1 and HT price is -1 divided by (1 + VAT rate)
                                // 3. HT price is -1 and TTC price is -1 multiplied by (1 + VAT rate)
                                $isVariablePrice = false;

                                if ($price == -1 && $priceAlt == -1) {
                                    // Both are exactly -1
                                    $isVariablePrice = true;
                                } elseif ($priceBaseType == 'TTC' && $price == -1 && $priceAlt !== null && $tva_tx > 0) {
                                    // TTC is -1, check if HT is approximately -1 / (1 + VAT/100)
                                    $expectedHtPrice = -1 / (1 + ($tva_tx / 100));
                                    if (abs($priceAlt - $expectedHtPrice) < 0.01) {
                                        $isVariablePrice = true;
                                    }
                                } elseif ($priceBaseType == 'HT' && $priceAlt == -1 && $price !== null && $tva_tx > 0) {
                                    // HT is -1, check if TTC is approximately -1 * (1 + VAT/100)
                                    $expectedTtcPrice = -1 * (1 + ($tva_tx / 100));
                                    if (abs($price - $expectedTtcPrice) < 0.01) {
                                        $isVariablePrice = true;
                                    }
                                }

                                if ($isVariablePrice) {
                                    // Variable price: store -1 in Dolibarr multiprice to mirror ZS flag
                                    dol_syslog("Product ID {$product->id}, level $i: Variable price confirmed. Setting multiprice to -1.", LOG_INFO);
                                    $price = -1;
                                    $hasVariablePrice = true; // Mark product as having variable pricing
                                }

                                // Prepare parameters for updatePrice
                                $oldprice = $price;
                                $oldpricebasetype = $priceBaseType;
                                $oldminprice = 0; // Do not force min price to -1
                                $oldnpr = 0; // Non-deductible VAT rate (set as needed)
                                $localtaxarray = array(); // Local taxes if any
                                $vatratecode = ''; // VAT rate code if applicable

                                // Update the price for this level
                                $ret = $product->updatePrice(
                                    $oldprice,              // param 1: Price
                                    $oldpricebasetype,      // param 2: Price base type ('HT' or 'TTC')
                                    $user,                  // param 3: User object
                                    $tva_tx,                // param 4: VAT rate
                                    $oldminprice,           // param 5: Minimum price
                                    $i,                     // param 6: Price level
                                    $oldnpr,                // param 7: Non-deductible VAT rate
                                    0,                      // param 8: Minimum price in TTC
                                    0,                      // param 9: Minimum price base type
                                    $localtaxarray,         // param 10: Local taxes array
                                    $vatratecode            // param 11: VAT rate code
                                );


                                if ($ret < 0) {
                                    dol_syslog("Failed to update price level $i for product ID {$product->id}: " . $product->error, LOG_ERR);
                                    setEventMessages($product->error, $product->errors, 'errors');
                                }

                                // Always force the database row to -1 for variable prices, even if updatePrice refused negatives
                                if ($isVariablePrice) {
                                    $this->forceDolibarrPriceLevelToVariable($product->id, $i);

                                    // Keep in-memory multiprice caches aligned (do not touch min fields)
                                    $product->multiprices[$i] = -1;
                                    $product->multiprices_ttc[$i] = -1;
                                }
                            }
                        }
                    } else {
                        // If multiprices are not enabled, update the standard price
                        $priceField = ($priceBaseType == 'HT') ? 'pvp1siva' : 'precovenda';
                        $priceFieldAlt = ($priceBaseType == 'HT') ? 'precovenda' : 'pvp1siva';

                        if (isset($obj->$priceField) && $obj->$priceField !== null) {
                            $price = (float)$obj->$priceField;
                            $priceAlt = isset($obj->$priceFieldAlt) && $obj->$priceFieldAlt !== null ? (float)$obj->$priceFieldAlt : null;
                            $tva_tx = (float)$obj->iva;

                            // Check for "Preço Variável" (Variable Price)
                            // Variable price can be indicated by:
                            // 1. Both prices are exactly -1
                            // 2. TTC price is -1 and HT price is -1 divided by (1 + VAT rate)
                            // 3. HT price is -1 and TTC price is -1 multiplied by (1 + VAT rate)
                            $isVariablePrice = false;

                            if ($price == -1 && $priceAlt == -1) {
                                // Both are exactly -1
                                $isVariablePrice = true;
                            } elseif ($priceBaseType == 'TTC' && $price == -1 && $priceAlt !== null && $tva_tx > 0) {
                                // TTC is -1, check if HT is approximately -1 / (1 + VAT/100)
                                $expectedHtPrice = -1 / (1 + ($tva_tx / 100));
                                if (abs($priceAlt - $expectedHtPrice) < 0.01) {
                                    $isVariablePrice = true;
                                }
                            } elseif ($priceBaseType == 'HT' && $priceAlt == -1 && $price !== null && $tva_tx > 0) {
                                // HT is -1, check if TTC is approximately -1 * (1 + VAT/100)
                                $expectedTtcPrice = -1 * (1 + ($tva_tx / 100));
                                if (abs($price - $expectedTtcPrice) < 0.01) {
                                    $isVariablePrice = true;
                                }
                            }

                            if ($isVariablePrice) {
                                // Variable price: store -1 in Dolibarr multiprice to mirror ZS flag
                                dol_syslog("Product ID {$product->id}: Variable price confirmed. Setting multiprice to -1.", LOG_INFO);
                                $price = -1;
                                $hasVariablePrice = true; // Mark product as having variable pricing
                            }

                            // Prepare parameters for updatePrice
                            $oldprice = $price;
                            $oldpricebasetype = $priceBaseType;
                            $oldminprice = 0; // Do not force min price to -1
                            $oldnpr = 0; // Non-deductible VAT rate (set as needed)
                            $localtaxarray = array(); // Local taxes if any
                            $vatratecode = ''; // VAT rate code if applicable

                            // Update the price
                            $ret = $product->updatePrice(
                                $oldprice,              // param 1: Price
                                $oldpricebasetype,      // param 2: Price base type ('HT' or 'TTC')
                                $user,                  // param 3: User object
                                $tva_tx,                // param 4: VAT rate
                                $oldminprice,           // param 5: Minimum price
                                1,                      // param 6: Price level (1 for standard price)
                                $oldnpr,                // param 7: Non-deductible VAT rate
                                0,                      // param 8: Minimum price in TTC
                                0,                      // param 9: Minimum price base type
                                $localtaxarray,         // param 10: Local taxes array
                                $vatratecode            // param 11: VAT rate code
                            );


                            if ($ret < 0) {
                                dol_syslog("Failed to update standard price for product ID {$product->id}: " . $product->error, LOG_ERR);
                                setEventMessages($product->error, $product->errors, 'errors');
                            }

                            // Always force the database row to -1 for variable prices, even if updatePrice refused negatives
                            if ($isVariablePrice) {
                                $this->forceDolibarrPriceLevelToVariable($product->id, 1);

                                // Sync base caches
                                $product->price = -1;
                                $product->price_ttc = -1;
                                $product->price_min = -1;
                                $product->price_min_ttc = -1;
                            }
                        }
                    }

                    // Suppress triggers during this update to prevent recursion
                    if ($product->update($product->id, $user, 1) > 0) {
                        dol_syslog("Product updated successfully in Dolibarr: " . $product->ref, LOG_INFO);

                        // Reload product to refresh multiprices array after updates
                        $product->fetch($dolibarrProductId);

                        return true;
                    } else {
                        dol_syslog("Failed to update product in Dolibarr: " . $product->error, LOG_ERR);
                        return false;
                    }
                } else {
                    dol_syslog("Product data not found in dolizsynch_zsproduct for fk_product: {$dolibarrProductId}", LOG_ERR);
                    return false;
                }
            } else {
                dol_syslog("Error fetching product data from dolizsynch_zsproduct: " . $this->db->lasterror(), LOG_ERR);
                return false;
            }
        } else {
            dol_syslog("Dolibarr product not found for ID: {$dolibarrProductId}", LOG_ERR);
            return false;
        }
    }

    /**
     * Get configuration value for a specific entity
     *
     * @param string $constName Name of the configuration variable
     * @param int $entityId Entity ID
     * @return mixed Value of the configuration variable or null if not found
     */
	private function getEntityConfigValue($constName, $entityId)
	{
		$sql = "SELECT value FROM " . MAIN_DB_PREFIX . "const WHERE name = '" . $this->db->escape($constName) . "' AND entity = " . (int)$entityId;
		$resql = $this->db->query($sql);
		if ($resql) {
			$obj = $this->db->fetch_object($resql);
			if ($obj) {
				return $obj->value;
			}
		}
		return null;
	}

	/**
	 * Force the latest product_price row for a level to -1 values (price/min HT/TTC) to flag variable price in Dolibarr.
	 * Uses direct SQL to bypass any protection against negative prices.
	 *
	 * @param int $productId Dolibarr product id
	 * @param int $level     Price level (1..n)
	 * @return bool True on success, false otherwise
	 */
	public function forceDolibarrPriceLevelToVariable($productId, $level)
	{
		global $conf;

		$productId = (int) $productId;
		$level = (int) $level;

		if ($productId <= 0 || $level <= 0) {
			dol_syslog(__METHOD__ . " - Invalid productId/level: $productId / $level", LOG_WARNING);
			return false;
		}

		$entityList = getEntity('productprice'); // respect entity restriction


		// First try to UPDATE existing rows for this product/level in entity scope (do NOT touch min fields)
		$now = $this->db->idate(dol_now());
		$sqlUpdate = "UPDATE " . MAIN_DB_PREFIX . "product_price SET";
		$sqlUpdate .= " price = -1, price_ttc = -1, date_price = '" . $now . "'";
		$sqlUpdate .= " WHERE fk_product = " . $productId . " AND price_level = " . $level;
		if (!empty($entityList)) {
			$sqlUpdate .= " AND entity IN (" . $entityList . ")";
		}

		$updRes = $this->db->query($sqlUpdate);
		if ($updRes) {
			$affected = $this->db->affected_rows($updRes);
			if ($affected > 0) {
				dol_syslog(__METHOD__ . " - Updated $affected rows to -1 for product $productId level $level", LOG_INFO);
				return true;
			}
		} else {
			dol_syslog(__METHOD__ . " - Error updating existing price rows: " . $this->db->lasterror(), LOG_ERR);
		}

		// No existing row affected: insert a new one with -1 values
		$productRow = null;
		$sqlProd = "SELECT price_base_type, tva_tx, tva_npr, default_vat_code FROM " . MAIN_DB_PREFIX . "product WHERE rowid = " . $productId . " LIMIT 1";
		$resProd = $this->db->query($sqlProd);
		if ($resProd) {
			$productRow = $this->db->fetch_object($resProd);
			$this->db->free($resProd);
		}

		$priceBaseType = (!empty($productRow->price_base_type)) ? $productRow->price_base_type : 'HT';
		$tvaTx = isset($productRow->tva_tx) ? (float) $productRow->tva_tx : 0;
		$tvaNpr = isset($productRow->tva_npr) ? (int) $productRow->tva_npr : 0;
		$defaultVatCode = isset($productRow->default_vat_code) ? $productRow->default_vat_code : '';

		$currentEntity = !empty($conf->entity) ? (int) $conf->entity : 1;
		$sqlInsert = "INSERT INTO " . MAIN_DB_PREFIX . "product_price (fk_product, entity, price_level, price, price_ttc, price_base_type, price_min, price_min_ttc, tva_tx, tva_npr, default_vat_code, date_price)";
		$sqlInsert .= " VALUES (" . $productId . ", " . $currentEntity . ", " . $level . ", -1, -1, '" . $this->db->escape($priceBaseType) . "', 0, 0, " . ((float)$tvaTx) . ", " . $tvaNpr . ", '" . $this->db->escape($defaultVatCode) . "', '" . $now . "')";

		// If a row already exists, the insert will fail on unique constraints; in that case try a fallback update without touching min fields
		if (!$this->db->query($sqlInsert)) {
			// If insert failed due to existing row, fallback to update without min fields
			$errorMsg = $this->db->lasterror();
			dol_syslog(__METHOD__ . " - Insert failed, trying update fallback: " . $errorMsg, LOG_WARNING);

			$sqlUpdateFallback = "UPDATE " . MAIN_DB_PREFIX . "product_price SET";
			$sqlUpdateFallback .= " price = -1, price_ttc = -1, date_price = '" . $now . "'";
			$sqlUpdateFallback .= " WHERE fk_product = " . $productId . " AND price_level = " . $level;
			$sqlUpdateFallback .= " AND entity = " . $currentEntity;

			if (!$this->db->query($sqlUpdateFallback)) {
				dol_syslog(__METHOD__ . " - Fallback update failed: " . $this->db->lasterror(), LOG_ERR);
				return false;
			}

			dol_syslog(__METHOD__ . " - Updated existing price row to -1 via fallback for product $productId level $level", LOG_INFO);
			return true;
		}

		dol_syslog(__METHOD__ . " - Inserted variable price row (-1) for product $productId level $level", LOG_INFO);
		return true;
	}

	/**
	 *
	 *
	 * Download all poducts from ZoneSoft to Local Table
	 *
	 *
	 */

	/**
	 * Fetch and insert a bounded number of ZoneSoft product pages into local table.
	 * Useful for UI-triggered syncs where catalogs are too large for one request.
	 *
	 * @param int $offset Starting offset in ZoneSoft product list
	 * @param int $maxBatches Maximum number of pages to process in this run
	 * @return array{ok:bool,completed:bool,processed_batches:int,processed_products:int,failed_products:int,next_offset:int,limit:int,error:string}
	 */
	public function fetchAndInsertZsProductsLocalTableChunk($offset = 1, $maxBatches = 10)
	{
		global $conf;

		$offset = (int) $offset;
		if ($offset <= 0) {
			$offset = 1;
		}

		$maxBatches = (int) $maxBatches;
		if ($maxBatches <= 0) {
			$maxBatches = 10;
		}

		$limit = !empty($conf->global->ZS_GET_NUMBER_OF_PRODUCTS_LIMIT) ? (int) $conf->global->ZS_GET_NUMBER_OF_PRODUCTS_LIMIT : 15;
		if ($limit <= 0) {
			$limit = 15;
		}

		$hardFailure = false;
		$errorMessage = '';
		$processedBatches = 0;
		$processedProducts = 0;
		$failedProducts = 0;
		$completed = false;

		$this->zoneSoftApi->setInterface('products');

		while ($processedBatches < $maxBatches) {
			$products = $this->getZoneSoftProductsToLocalTable($offset);

			if ($products === false) {
				$hardFailure = true;
				$errorMessage = "Failed to fetch products from ZoneSoft at offset {$offset}";
				dol_syslog(__METHOD__ . ": {$errorMessage}", LOG_ERR);
				break;
			}

			if (empty($products)) {
				$completed = true;
				$offset = 1;
				break;
			}

			$processedBatches++;
			$currentBatchCount = count($products);

			foreach ($products as $product) {
				$processedProducts++;
				$insertSuccess = $this->insertProductToLocalTable((object) ['product' => $product]);
				if (!$insertSuccess) {
					$failedProducts++;
					dol_syslog(__METHOD__ . ": Failed to insert product at offset {$offset}", LOG_ERR);
				}
			}

			if ($currentBatchCount < $limit) {
				$completed = true;
				$offset = 1;
				break;
			}

			$offset += $limit;
		}

		return array(
			'ok' => !$hardFailure,
			'completed' => $completed,
			'processed_batches' => $processedBatches,
			'processed_products' => $processedProducts,
			'failed_products' => $failedProducts,
			'next_offset' => $offset,
			'limit' => $limit,
			'error' => $errorMessage
		);
	}

    /**
     * Fetches all products from ZoneSoft and inserts them into the local database.
     *  CAN BE A CRON TASK
     *
     *  @return	int 0 if OK, < 0 if KO (this function is used also by cron so only 0 is OK)
     */
    public function fetchAndInsertAllZsProductsLocalTable()
    {
        global $conf, $db;

        $result = dolibarr_set_const($this->db, 'ZS_GET_PRODUCTS_OFFSET', 1);
        if ($result > 0) {
            // Configuration value updated successfully
            dol_syslog("Configuration value 'ZS_GET_PRODUCTS_OFFSET' updated to 1.", LOG_INFO);
        } else {
            // Failed to update the configuration value
            dol_syslog("Failed to update configuration value 'ZS_GET_PRODUCTS_OFFSET' to 1.", LOG_WARNING);
        }

        // Load the last offset, default to 0 if not set
        $offset = !empty($conf->global->ZS_GET_PRODUCTS_OFFSET) ? (int) $conf->global->ZS_GET_PRODUCTS_OFFSET : 1;
        $limit = !empty($conf->global->ZS_GET_NUMBER_OF_PRODUCTS_LIMIT) ? (int) $conf->global->ZS_GET_NUMBER_OF_PRODUCTS_LIMIT : 5;
        if ($limit <= 0) {
            $limit = 5;
        }
        $allSuccess = true;

        $this->zoneSoftApi->setInterface('products');

        while (true) {
            $products = $this->getZoneSoftProductsToLocalTable($offset);

            if ($products === false) {
                dol_syslog("Failed to fetch response from ZoneSoft at offset {$offset}", LOG_ERR);
                $allSuccess = false;
                break; // Exit the loop if fetching fails
            }

            if (empty($products)) {
                break; // Exit the loop if no more responses are returned
            }

            foreach ($products as $product) {
                $insertSuccess = $this->insertProductToLocalTable((object) ['product' => $product]);
                if (!$insertSuccess) {
                    dol_syslog("Failed to insert response into the local table at offset {$offset}", LOG_ERR);
                    $allSuccess = false;
                }
            }

            if (count($products) < $limit) {
                break;
            }

            // Update the configuration value
            $offset += $limit;
            dolibarr_set_const($this->db, 'ZS_GET_PRODUCTS_OFFSET', $offset);
        }

        // Update the configuration value
        $offset = 1;

        return $allSuccess ? 0 : -1; // Return 0 for success, -1 for failure as per cron requirements
    }

    /**
     * Process pending scheduled product price updates and sync them to ZSBMS.
     * CAN BE A CRON TASK
     *
     * @param int $limit Maximum number of rows to process in one run.
     * @return int 0 if OK, < 0 if KO (Dolibarr cron convention)
     */
    public function processScheduledPriceUpdates($limit = 100)
    {
        $limit = (int) $limit;
        if ($limit <= 0 || $limit > 1000) {
            $limit = 100;
        }

        if (!$this->ensureScheduledPriceRuleTable()) {
            return -1;
        }

        $cronUser = $this->getFallbackCronUser();
        $oneShotResult = $this->processOneShotScheduledPriceUpdates($limit, $cronUser);
        $rulesResult = $this->processWindowScheduledPriceRules(max(50, min(1000, $limit * 5)), $cronUser);

        return ($oneShotResult < 0 || $rulesResult < 0) ? -1 : 0;
    }

    /**
     * Process classic one-shot scheduled rows.
     *
     * @param int $limit
     * @param User $cronUser
     * @return int
     */
    private function processOneShotScheduledPriceUpdates($limit, $cronUser)
    {
        $rows = $this->fetchDueScheduledPriceUpdates($limit);
        if ($rows === false) {
            return -1;
        }
        if (empty($rows)) {
            return 0;
        }

        $actorId = (is_object($cronUser) && !empty($cronUser->id)) ? (int) $cronUser->id : 0;
        $processed = 0;
        $success = 0;
        $failed = 0;

        foreach ($rows as $row) {
            $processed++;
            $result = $this->applyScheduledPriceUpdate($row, $cronUser);

            if ($result === true) {
                if ($this->markScheduledPriceUpdateResult((int) $row->rowid, self::PRICE_SCHEDULE_DONE, '', $actorId)) {
                    $success++;
                } else {
                    $failed++;
                }
            } else {
                $failed++;
                $errorMessage = is_string($result) && $result !== '' ? $result : 'Unknown error while processing scheduled price update';
                $this->markScheduledPriceUpdateResult((int) $row->rowid, self::PRICE_SCHEDULE_ERROR, $errorMessage, $actorId);
            }
        }

        dol_syslog(
            __METHOD__ . " - Processed={$processed}, success={$success}, failed={$failed}",
            $failed > 0 ? LOG_WARNING : LOG_INFO
        );

        return ($failed > 0) ? -1 : 0;
    }

    /**
     * Fetch rows that are due to be processed.
     *
     * @param int $limit
     * @return array|false
     */
    private function fetchDueScheduledPriceUpdates($limit)
    {
        global $conf;

        $entity = !empty($conf->entity) ? (int) $conf->entity : 1;
        $now = $this->db->idate(dol_now());

        $sql = "SELECT rowid, fk_product, product_ref, price_level, price_base_type, price_value, scheduled_at";
        $sql .= " FROM " . MAIN_DB_PREFIX . "dolizsynch_price_schedule";
        $sql .= " WHERE entity = " . $entity;
        $sql .= " AND status = " . self::PRICE_SCHEDULE_PENDING;
        $sql .= " AND scheduled_at <= '" . $now . "'";
        $sql .= " ORDER BY scheduled_at ASC, rowid ASC";
        $sql .= $this->db->plimit((int) $limit, 0);

        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->error = "Error fetching scheduled prices: " . $this->db->lasterror();
            dol_syslog(__METHOD__ . " - " . $this->error, LOG_ERR);
            return false;
        }

        $rows = array();
        while ($obj = $this->db->fetch_object($resql)) {
            $rows[] = $obj;
        }
        $this->db->free($resql);

        return $rows;
    }

    /**
     * Resolve a cron actor user for write operations.
     *
     * @return User
     */
    private function getFallbackCronUser()
    {
        global $user;

        if (is_object($user) && !empty($user->id)) {
            return $user;
        }

        $cronUser = new User($this->db);
        if ($cronUser->fetch(1) > 0) {
            return $cronUser;
        }

        // Last resort object with id=0 to avoid null access inside updatePrice.
        $cronUser->id = 0;
        return $cronUser;
    }

    /**
     * Execute a single scheduled price update row.
     *
     * @param object $row Scheduled row object.
     * @param User $actor User used in updatePrice.
     * @return bool|string True on success, otherwise error message.
     */
    private function applyScheduledPriceUpdate($row, $actor)
    {
        $rowId = (int) $row->rowid;
        $productId = $this->resolveScheduledProductId($row);
        if ($productId <= 0) {
            return "Product not found for row {$rowId}";
        }

        $priceLevel = (int) $row->price_level;
        if ($priceLevel <= 0) {
            $priceLevel = 1;
        }

        $baseType = strtoupper(trim((string) $row->price_base_type));
        if ($baseType !== 'HT' && $baseType !== 'TTC') {
            $baseType = 'TTC';
        }

        $newPrice = (float) $row->price_value;
        if ($newPrice < 0) {
            return "Negative prices are not allowed for scheduled updates";
        }

        $productRef = trim((string) ($row->product_ref ?? ''));
        $context = "row {$rowId}";
        if ($productRef !== '') {
            $context .= " (product {$productRef})";
        }

        return $this->applyPriceForScheduleTarget($productId, $priceLevel, $baseType, $newPrice, $actor, $context);
    }

    /**
     * Apply one price to one product level and synchronize to ZSBMS.
     *
     * @param int $productId
     * @param int $priceLevel
     * @param string $baseType
     * @param float $newPrice
     * @param User $actor
     * @param string $context
     * @return bool|string
     */
    private function applyPriceForScheduleTarget($productId, $priceLevel, $baseType, $newPrice, $actor, $context = '')
    {
        $productId = (int) $productId;
        $priceLevel = (int) $priceLevel;
        $baseType = strtoupper(trim((string) $baseType));
        $newPrice = (float) $newPrice;

        if ($productId <= 0) {
            return "Invalid product id";
        }
        if ($priceLevel <= 0) {
            $priceLevel = 1;
        }
        if ($baseType !== 'HT' && $baseType !== 'TTC') {
            $baseType = 'TTC';
        }
        if ($newPrice < 0) {
            return "Negative prices are not allowed";
        }

        $product = new Product($this->db);
        if ($product->fetch($productId) <= 0) {
            return "Unable to fetch product ID {$productId}";
        }
        if (method_exists($product, 'fetch_optionals')) {
            $product->fetch_optionals();
        }

        $taxContext = $this->getPriceLevelTaxContext($product, $priceLevel);
        $vatRate = isset($taxContext['vat']) ? (float) $taxContext['vat'] : 0.0;
        $defaultVatCode = !empty($taxContext['default_vat_code']) ? (string) $taxContext['default_vat_code'] : '';
        $npr = isset($taxContext['npr']) ? (int) $taxContext['npr'] : 0;
        $localtaxesArray = (isset($taxContext['localtaxes_array']) && is_array($taxContext['localtaxes_array']))
            ? $taxContext['localtaxes_array']
            : array('0' => '0', '1' => 0, '2' => '0', '3' => 0);

        $ret = $product->updatePrice(
            $newPrice,
            $baseType,
            $actor,
            $vatRate,
            0,
            $priceLevel,
            $npr,
            0,
            0,
            $localtaxesArray,
            $defaultVatCode
        );

        if ($ret < 0) {
            $err = !empty($product->error) ? $product->error : $this->db->lasterror();
            return "Dolibarr price update failed for product {$product->ref}: " . $err;
        }

        // Reload product after updatePrice to refresh price caches.
        $product->fetch($productId);
        if (method_exists($product, 'fetch_optionals')) {
            $product->fetch_optionals();
        }

        $syncOk = $this->updateZsbmsProduct($product, true, true);
        if (!$syncOk) {
            $syncError = !empty($this->error) ? $this->error : 'Unknown ZSBMS synchronization error';
            return "ZSBMS sync failed for product {$product->ref}: " . $syncError;
        }

        dol_syslog(
            __METHOD__ . " - Scheduled price applied for product {$product->ref}, level {$priceLevel}, {$baseType}={$newPrice}" . ($context !== '' ? " ({$context})" : ""),
            LOG_INFO
        );

        return true;
    }

    /**
     * Read the current price value for a product level/base.
     *
     * @param int $productId
     * @param int $priceLevel
     * @param string $baseType
     * @return float|null
     */
    private function getCurrentProductLevelPrice($productId, $priceLevel, $baseType)
    {
        $productId = (int) $productId;
        $priceLevel = (int) $priceLevel;
        $baseType = strtoupper(trim((string) $baseType));

        if ($productId <= 0) {
            return null;
        }
        if ($priceLevel <= 0) {
            $priceLevel = 1;
        }
        if ($baseType !== 'HT' && $baseType !== 'TTC') {
            $baseType = 'TTC';
        }

        $product = new Product($this->db);
        if ($product->fetch($productId) <= 0) {
            return null;
        }

        $raw = null;
        if ($priceLevel === 1) {
            $raw = ($baseType === 'HT') ? $product->price : $product->price_ttc;
        } else {
            $raw = ($baseType === 'HT')
                ? (isset($product->multiprices[$priceLevel]) ? $product->multiprices[$priceLevel] : null)
                : (isset($product->multiprices_ttc[$priceLevel]) ? $product->multiprices_ttc[$priceLevel] : null);
        }

        if ($raw === null || $raw === '') {
            return null;
        }

        if (!is_numeric($raw)) {
            return null;
        }

        return (float) $raw;
    }

    /**
     * Resolve product id from scheduled row data.
     *
     * @param object $row
     * @return int
     */
    private function resolveScheduledProductId($row)
    {
        $productId = isset($row->fk_product) ? (int) $row->fk_product : 0;
        if ($productId > 0) {
            return $productId;
        }

        $productRef = trim((string) ($row->product_ref ?? ''));
        if ($productRef === '') {
            return 0;
        }

        $entityList = getEntity('product');
        $sql = "SELECT rowid FROM " . MAIN_DB_PREFIX . "product";
        $sql .= " WHERE ref = '" . $this->db->escape($productRef) . "'";
        if (!empty($entityList)) {
            $sql .= " AND entity IN (" . $entityList . ")";
        }
        $sql .= " ORDER BY rowid ASC LIMIT 1";

        $resql = $this->db->query($sql);
        if ($resql && $this->db->num_rows($resql) > 0) {
            $obj = $this->db->fetch_object($resql);
            $this->db->free($resql);
            return (int) $obj->rowid;
        }

        return 0;
    }

    /**
     * Get VAT rate to use for a specific price level.
     *
     * @param Product $product
     * @param int $priceLevel
     * @return float
     */
    private function getVatRateForLevel($product, $priceLevel)
    {
        $context = $this->getPriceLevelTaxContext($product, $priceLevel);
        return isset($context['vat']) ? (float) $context['vat'] : 0.0;
    }

    /**
     * Resolve VAT context for a product price level.
     * Mirrors sellPrice behavior to avoid collapsing HT/TTC when VAT metadata exists.
     *
     * @param Product $product
     * @param int $priceLevel
     * @return array{vat:float,default_vat_code:string,npr:int,localtaxes_array:array}
     */
    private function getPriceLevelTaxContext($product, $priceLevel)
    {
        global $mysoc;

        $priceLevel = (int) $priceLevel;
        if ($priceLevel <= 0) {
            $priceLevel = 1;
        }

        $vat = (isset($product->tva_tx) && $product->tva_tx !== null && $product->tva_tx !== '')
            ? (float) $product->tva_tx
            : 0.0;
        $defaultVatCode = !empty($product->default_vat_code) ? (string) $product->default_vat_code : '';
        $npr = 0;
        $localtaxesArray = array('0' => '0', '1' => 0, '2' => '0', '3' => 0);

        if (isset($product->multiprices_tva_tx[$priceLevel]) && $product->multiprices_tva_tx[$priceLevel] !== '') {
            $vat = (float) $product->multiprices_tva_tx[$priceLevel];
        }

        $entityList = getEntity('productprice');
        $sql = "SELECT tva_tx, default_vat_code, recuperableonly, localtax1_tx, localtax1_type, localtax2_tx, localtax2_type";
        $sql .= " FROM " . MAIN_DB_PREFIX . "product_price";
        $sql .= " WHERE fk_product = " . ((int) $product->id);
        $sql .= " AND price_level = " . $priceLevel;
        if (!empty($entityList)) {
            $sql .= " AND entity IN (" . $entityList . ")";
        }
        $sql .= " ORDER BY date_price DESC, rowid DESC LIMIT 1";

        $resql = $this->db->query($sql);
        if ($resql && $this->db->num_rows($resql) > 0) {
            $obj = $this->db->fetch_object($resql);
            if ($obj) {
                if (isset($obj->tva_tx) && $obj->tva_tx !== null && $obj->tva_tx !== '' && (float) $obj->tva_tx > 0) {
                    $vat = (float) $obj->tva_tx;
                }
                if (!empty($obj->default_vat_code)) {
                    $defaultVatCode = (string) $obj->default_vat_code;
                }
                if (isset($obj->recuperableonly) && $obj->recuperableonly !== null && $obj->recuperableonly !== '') {
                    $npr = (int) $obj->recuperableonly;
                }
                if (isset($obj->localtax1_type) && $obj->localtax1_type !== null && $obj->localtax1_type !== '') {
                    $localtaxesArray['0'] = (string) $obj->localtax1_type;
                }
                if (isset($obj->localtax1_tx) && $obj->localtax1_tx !== null && $obj->localtax1_tx !== '') {
                    $localtaxesArray['1'] = (float) $obj->localtax1_tx;
                }
                if (isset($obj->localtax2_type) && $obj->localtax2_type !== null && $obj->localtax2_type !== '') {
                    $localtaxesArray['2'] = (string) $obj->localtax2_type;
                }
                if (isset($obj->localtax2_tx) && $obj->localtax2_tx !== null && $obj->localtax2_tx !== '') {
                    $localtaxesArray['3'] = (float) $obj->localtax2_tx;
                }
            }
        }
        if ($resql) {
            $this->db->free($resql);
        }

        if ($vat <= 0 && isset($product->tva_tx) && $product->tva_tx !== null && (float) $product->tva_tx > 0) {
            $vat = (float) $product->tva_tx;
        }

        if ($vat <= 0 && $defaultVatCode !== '') {
            $sqlVat = "SELECT t.taux";
            $sqlVat .= " FROM " . MAIN_DB_PREFIX . "c_tva as t";
            if (!empty($mysoc->country_code)) {
                $sqlVat .= ", " . MAIN_DB_PREFIX . "c_country as c";
                $sqlVat .= " WHERE t.fk_pays = c.rowid";
                $sqlVat .= " AND c.code = '" . $this->db->escape($mysoc->country_code) . "'";
            } else {
                $sqlVat .= " WHERE 1 = 1";
            }
            $sqlVat .= " AND t.code = '" . $this->db->escape($defaultVatCode) . "'";
            $sqlVat .= " AND t.active = 1";
            $sqlVat .= " ORDER BY t.taux DESC LIMIT 1";

            $resqlVat = $this->db->query($sqlVat);
            if ($resqlVat && $this->db->num_rows($resqlVat) > 0) {
                $objVat = $this->db->fetch_object($resqlVat);
                if ($objVat && isset($objVat->taux) && $objVat->taux !== null && $objVat->taux !== '') {
                    $vat = (float) $objVat->taux;
                }
            }
            if ($resqlVat) {
                $this->db->free($resqlVat);
            }
        }

        return array(
            'vat' => (float) $vat,
            'default_vat_code' => $defaultVatCode,
            'npr' => (int) $npr,
            'localtaxes_array' => $localtaxesArray,
        );
    }

    /**
     * Update processing status for one scheduled row.
     *
     * @param int $rowId
     * @param int $status
     * @param string $message
     * @param int $actorId
     * @return bool
     */
    private function markScheduledPriceUpdateResult($rowId, $status, $message, $actorId = 0)
    {
        global $conf, $user;

        $rowId = (int) $rowId;
        $status = (int) $status;
        $actorId = (int) $actorId;
        $entity = !empty($conf->entity) ? (int) $conf->entity : 1;
        $now = $this->db->idate(dol_now());
        $userId = $actorId > 0 ? $actorId : ((is_object($user) && !empty($user->id)) ? (int) $user->id : 0);
        $safeMessage = trim((string) $message);
        if (strlen($safeMessage) > 4000) {
            $safeMessage = substr($safeMessage, 0, 4000);
        }

        $sql = "UPDATE " . MAIN_DB_PREFIX . "dolizsynch_price_schedule SET";
        $sql .= " status = " . $status . ",";
        $sql .= " date_processed = '" . $now . "',";
        $sql .= " error_message = " . ($safeMessage === '' ? "NULL" : "'" . $this->db->escape($safeMessage) . "'") . ",";
        $sql .= " fk_user_modif = " . $userId;
        $sql .= " WHERE rowid = " . $rowId;
        $sql .= " AND entity = " . $entity;

        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->error = "Unable to update scheduled price row {$rowId}: " . $this->db->lasterror();
            dol_syslog(__METHOD__ . " - " . $this->error, LOG_ERR);
            return false;
        }

        return true;
    }

    /**
     * Ensure promotion rule table exists.
     *
     * @return bool
     */
    private function ensureScheduledPriceRuleTable()
    {
        static $checked = false;
        static $ok = false;

        if ($checked) {
            return $ok;
        }

        $table = MAIN_DB_PREFIX . "dolizsynch_price_schedule_rule";
        $sql = "CREATE TABLE IF NOT EXISTS " . $table . " (";
        $sql .= " rowid INTEGER AUTO_INCREMENT PRIMARY KEY NOT NULL,";
        $sql .= " entity INTEGER NOT NULL DEFAULT 1,";
        $sql .= " fk_product INTEGER NULL,";
        $sql .= " product_ref VARCHAR(128) NOT NULL,";
        $sql .= " price_level INTEGER NOT NULL DEFAULT 1,";
        $sql .= " price_base_type VARCHAR(3) NOT NULL DEFAULT 'TTC',";
        $sql .= " promo_price_value DOUBLE(24,8) NOT NULL DEFAULT 0,";
        $sql .= " rule_type VARCHAR(16) NOT NULL DEFAULT 'once_window',";
        $sql .= " start_at DATETIME NULL,";
        $sql .= " end_at DATETIME NULL,";
        $sql .= " weekly_day SMALLINT NULL,";
        $sql .= " weekly_start_time TIME NULL,";
        $sql .= " weekly_end_time TIME NULL,";
        $sql .= " is_active SMALLINT NOT NULL DEFAULT 0,";
        $sql .= " last_base_price DOUBLE(24,8) NULL,";
        $sql .= " last_start_at DATETIME NULL,";
        $sql .= " last_end_at DATETIME NULL,";
        $sql .= " status SMALLINT NOT NULL DEFAULT 0,";
        $sql .= " error_message TEXT,";
        $sql .= " date_processed DATETIME NULL,";
        $sql .= " source VARCHAR(32) NOT NULL DEFAULT 'manual_grid',";
        $sql .= " fk_user_creat INTEGER NULL,";
        $sql .= " fk_user_modif INTEGER NULL,";
        $sql .= " date_creation DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,";
        $sql .= " tms TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,";
        $sql .= " INDEX idx_dzs_price_schedule_rule_state (entity, status, rule_type),";
        $sql .= " INDEX idx_dzs_price_schedule_rule_product (fk_product),";
        $sql .= " INDEX idx_dzs_price_schedule_rule_start (start_at),";
        $sql .= " INDEX idx_dzs_price_schedule_rule_end (end_at)";
        $sql .= " ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        $ok = (bool) $this->db->query($sql);
        if (!$ok) {
            $this->error = "Unable to create promotion rule table: " . $this->db->lasterror();
            dol_syslog(__METHOD__ . " - " . $this->error, LOG_ERR);
        }
        $checked = true;
        return $ok;
    }

    /**
     * Process temporary/recurring promotion rules.
     *
     * @param int $limit
     * @param User $actor
     * @return int
     */
    private function processWindowScheduledPriceRules($limit, $actor)
    {
        global $conf;

        $limit = (int) $limit;
        if ($limit <= 0) {
            $limit = 100;
        }

        $entity = !empty($conf->entity) ? (int) $conf->entity : 1;
        $sql = "SELECT rowid, fk_product, product_ref, price_level, price_base_type, promo_price_value, rule_type,";
        $sql .= " start_at, end_at, weekly_day, weekly_start_time, weekly_end_time, is_active, last_base_price";
        $sql .= ", status";
        $sql .= " FROM " . MAIN_DB_PREFIX . "dolizsynch_price_schedule_rule";
        $sql .= " WHERE entity = " . $entity;
        $sql .= " AND (status = " . self::PRICE_SCHEDULE_RULE_ENABLED;
        $sql .= " OR (status = " . self::PRICE_SCHEDULE_RULE_CANCELED . " AND is_active = 1))";
        $sql .= " ORDER BY rowid ASC";
        $sql .= $this->db->plimit($limit, 0);

        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->error = "Error fetching scheduled price rules: " . $this->db->lasterror();
            dol_syslog(__METHOD__ . " - " . $this->error, LOG_ERR);
            return -1;
        }

        $rules = array();
        while ($obj = $this->db->fetch_object($resql)) {
            $rules[] = $obj;
        }
        $this->db->free($resql);

        if (empty($rules)) {
            return 0;
        }

        $actorId = (is_object($actor) && !empty($actor->id)) ? (int) $actor->id : 0;
        $transitions = 0;
        $noops = 0;
        $failed = 0;

        foreach ($rules as $rule) {
            $result = $this->evaluateScheduledPriceRule($rule, $actor, $actorId);
            if ($result === true) {
                $transitions++;
            } elseif ($result === null) {
                $noops++;
            } else {
                $failed++;
                $errorMessage = is_string($result) && $result !== '' ? $result : 'Unknown rule processing error';
                $safeMessage = $errorMessage;
                if (strlen($safeMessage) > 4000) {
                    $safeMessage = substr($safeMessage, 0, 4000);
                }
                $this->updateScheduledPriceRuleState((int) $rule->rowid, array(
                    "status = " . self::PRICE_SCHEDULE_RULE_ERROR,
                    "date_processed = '" . $this->db->idate(dol_now()) . "'",
                    "error_message = '" . $this->db->escape($safeMessage) . "'",
                    "fk_user_modif = " . $actorId
                ));
            }
        }

        dol_syslog(
            __METHOD__ . " - transitions={$transitions}, idle={$noops}, failed={$failed}",
            $failed > 0 ? LOG_WARNING : LOG_INFO
        );

        return ($failed > 0) ? -1 : 0;
    }

    /**
     * Evaluate one rule and apply transitions (start/revert) when needed.
     *
     * @param object $rule
     * @param User $actor
     * @param int $actorId
     * @return bool|null|string true when transition applied, null when no-op, string on error
     */
    private function evaluateScheduledPriceRule($rule, $actor, $actorId)
    {
        $rowId = (int) $rule->rowid;
        $ruleType = trim((string) $rule->rule_type);
        $supportedTypes = array(
            self::PRICE_SCHEDULE_RULE_TYPE_ONCE_WINDOW,
            self::PRICE_SCHEDULE_RULE_TYPE_DAILY_WINDOW,
            self::PRICE_SCHEDULE_RULE_TYPE_WEEKLY_WINDOW,
            self::PRICE_SCHEDULE_RULE_TYPE_MONTHLY_WINDOW,
            self::PRICE_SCHEDULE_RULE_TYPE_YEARLY_WINDOW,
        );
        if (!in_array($ruleType, $supportedTypes, true)) {
            return "Unsupported rule type for row {$rowId}";
        }

        $productId = $this->resolveScheduledProductId($rule);
        if ($productId <= 0) {
            return "Product not found for rule {$rowId}";
        }

        $priceLevel = (int) $rule->price_level;
        if ($priceLevel <= 0) {
            $priceLevel = 1;
        }

        $baseType = strtoupper(trim((string) $rule->price_base_type));
        if ($baseType !== 'HT' && $baseType !== 'TTC') {
            $baseType = 'TTC';
        }

        $promoPrice = (float) $rule->promo_price_value;
        if ($promoPrice < 0) {
            return "Negative promo prices are not allowed (rule {$rowId})";
        }

        $nowTs = dol_now();
        $nowSql = $this->db->idate($nowTs);
        $isActive = ((int) $rule->is_active === 1);
        $ruleStatus = isset($rule->status) ? (int) $rule->status : self::PRICE_SCHEDULE_RULE_ENABLED;
        $forceDeactivate = ($ruleStatus === self::PRICE_SCHEDULE_RULE_CANCELED);
        $shouldBeActive = false;

        if ($ruleType === self::PRICE_SCHEDULE_RULE_TYPE_ONCE_WINDOW) {
            $startTs = $this->dbDatetimeToTimestamp($rule->start_at ?? null);
            $endTs = $this->dbDatetimeToTimestamp($rule->end_at ?? null);
            if (empty($startTs) || empty($endTs)) {
                return "Start/end datetime is required for one-off rule {$rowId}";
            }
            if ($endTs <= $startTs) {
                return "End datetime must be greater than start datetime for rule {$rowId}";
            }

            if (!$isActive && $nowTs >= $endTs) {
                if (!$this->updateScheduledPriceRuleState($rowId, array(
                    "status = " . self::PRICE_SCHEDULE_RULE_DONE,
                    "is_active = 0",
                    "date_processed = '" . $nowSql . "'",
                    "error_message = NULL",
                    "fk_user_modif = " . ((int) $actorId)
                ))) {
                    return "Unable to mark expired one-off rule {$rowId} as done";
                }
                return true;
            }

            $shouldBeActive = ($nowTs >= $startTs && $nowTs < $endTs);
        } elseif ($ruleType === self::PRICE_SCHEDULE_RULE_TYPE_DAILY_WINDOW) {
            $shouldBeActive = $this->isDailyRuleActiveNow($rule, $nowTs);
        } else {
            if ($ruleType === self::PRICE_SCHEDULE_RULE_TYPE_WEEKLY_WINDOW) {
                $shouldBeActive = $this->isWeeklyRuleActiveNow($rule, $nowTs);
            } elseif ($ruleType === self::PRICE_SCHEDULE_RULE_TYPE_MONTHLY_WINDOW) {
                $shouldBeActive = $this->isMonthlyRuleActiveNow($rule, $nowTs);
            } else {
                $shouldBeActive = $this->isYearlyRuleActiveNow($rule, $nowTs);
            }
        }
        if ($forceDeactivate) {
            $shouldBeActive = false;
        }

        if ($shouldBeActive && !$isActive) {
            $basePrice = $this->getCurrentProductLevelPrice($productId, $priceLevel, $baseType);
            if ($basePrice === null) {
                $basePrice = 0.0;
            }

            $apply = $this->applyPriceForScheduleTarget(
                $productId,
                $priceLevel,
                $baseType,
                $promoPrice,
                $actor,
                "rule {$rowId} start"
            );
            if ($apply !== true) {
                return $apply;
            }

            if (!$this->updateScheduledPriceRuleState($rowId, array(
                "status = " . self::PRICE_SCHEDULE_RULE_ENABLED,
                "is_active = 1",
                "last_base_price = " . ((float) $basePrice),
                "last_start_at = '" . $nowSql . "'",
                "date_processed = '" . $nowSql . "'",
                "error_message = NULL",
                "fk_user_modif = " . ((int) $actorId)
            ))) {
                return "Unable to update start state for rule {$rowId}";
            }

            return true;
        }

        if (!$shouldBeActive && $isActive) {
            if (!isset($rule->last_base_price) || $rule->last_base_price === null || $rule->last_base_price === '') {
                return "Missing base price snapshot to revert rule {$rowId}";
            }

            $restorePrice = (float) $rule->last_base_price;
            if ($restorePrice < 0) {
                $restorePrice = 0;
            }

            $apply = $this->applyPriceForScheduleTarget(
                $productId,
                $priceLevel,
                $baseType,
                $restorePrice,
                $actor,
                "rule {$rowId} end"
            );
            if ($apply !== true) {
                return $apply;
            }

            $newStatus = ($ruleType === self::PRICE_SCHEDULE_RULE_TYPE_ONCE_WINDOW)
                ? self::PRICE_SCHEDULE_RULE_DONE
                : self::PRICE_SCHEDULE_RULE_ENABLED;
            if ($forceDeactivate) {
                $newStatus = self::PRICE_SCHEDULE_RULE_CANCELED;
            }

            if (!$this->updateScheduledPriceRuleState($rowId, array(
                "status = " . $newStatus,
                "is_active = 0",
                "last_base_price = NULL",
                "last_end_at = '" . $nowSql . "'",
                "date_processed = '" . $nowSql . "'",
                "error_message = NULL",
                "fk_user_modif = " . ((int) $actorId)
            ))) {
                return "Unable to update end state for rule {$rowId}";
            }

            return true;
        }

        return null;
    }

    /**
     * Check if a weekly rule is currently inside its active window.
     *
     * @param object $rule
     * @param int $nowTs
     * @return bool
     */
    private function isWeeklyRuleActiveNow($rule, $nowTs)
    {
        $weekDay = (int) $rule->weekly_day;
        if ($weekDay < 0 || $weekDay > 6) {
            return false;
        }

        $startTime = $this->normalizeSqlTime((string) ($rule->weekly_start_time ?? ''));
        $endTime = $this->normalizeSqlTime((string) ($rule->weekly_end_time ?? ''));
        if ($startTime === '' || $endTime === '' || $startTime === $endTime) {
            return false;
        }

        $todayWeekDay = (int) date('w', $nowTs);
        $nowTime = date('H:i:s', $nowTs);

        if ($startTime < $endTime) {
            return ($todayWeekDay === $weekDay && $nowTime >= $startTime && $nowTime < $endTime);
        }

        // Overnight window (for example 22:00 -> 02:00).
        $nextDay = ($weekDay + 1) % 7;
        if ($todayWeekDay === $weekDay && $nowTime >= $startTime) {
            return true;
        }
        if ($todayWeekDay === $nextDay && $nowTime < $endTime) {
            return true;
        }

        return false;
    }

    /**
     * Check if a daily rule is currently inside its active window.
     *
     * @param object $rule
     * @param int $nowTs
     * @return bool
     */
    private function isDailyRuleActiveNow($rule, $nowTs)
    {
        $template = $this->getRecurringRuleTemplate($rule);
        if ($template === false) {
            return false;
        }

        $candidateToday = mktime(
            (int) $template['hour'],
            (int) $template['minute'],
            (int) $template['second'],
            (int) date('n', $nowTs),
            (int) date('j', $nowTs),
            (int) date('Y', $nowTs)
        );
        $candidateYesterday = $candidateToday - 86400;

        return $this->isNowInsideAnyCandidateWindow(
            $nowTs,
            array($candidateToday, $candidateYesterday),
            (int) $template['duration']
        );
    }

    /**
     * Check if a monthly rule is currently inside its active window.
     *
     * @param object $rule
     * @param int $nowTs
     * @return bool
     */
    private function isMonthlyRuleActiveNow($rule, $nowTs)
    {
        $template = $this->getRecurringRuleTemplate($rule);
        if ($template === false) {
            return false;
        }

        $year = (int) date('Y', $nowTs);
        $month = (int) date('n', $nowTs);
        $current = $this->buildMonthlyCandidateStart($template, $year, $month);

        $prevYear = $year;
        $prevMonth = $month - 1;
        if ($prevMonth <= 0) {
            $prevMonth = 12;
            $prevYear--;
        }
        $previous = $this->buildMonthlyCandidateStart($template, $prevYear, $prevMonth);

        return $this->isNowInsideAnyCandidateWindow(
            $nowTs,
            array($current, $previous),
            (int) $template['duration']
        );
    }

    /**
     * Check if a yearly rule is currently inside its active window.
     *
     * @param object $rule
     * @param int $nowTs
     * @return bool
     */
    private function isYearlyRuleActiveNow($rule, $nowTs)
    {
        $template = $this->getRecurringRuleTemplate($rule);
        if ($template === false) {
            return false;
        }

        $year = (int) date('Y', $nowTs);
        $current = $this->buildYearlyCandidateStart($template, $year);
        $previous = $this->buildYearlyCandidateStart($template, $year - 1);

        return $this->isNowInsideAnyCandidateWindow(
            $nowTs,
            array($current, $previous),
            (int) $template['duration']
        );
    }

    /**
     * Extract recurring template from rule datetimes.
     *
     * @param object $rule
     * @return array|false
     */
    private function getRecurringRuleTemplate($rule)
    {
        $startTs = $this->dbDatetimeToTimestamp($rule->start_at ?? null);
        $endTs = $this->dbDatetimeToTimestamp($rule->end_at ?? null);
        if (empty($startTs) || empty($endTs) || $endTs <= $startTs) {
            return false;
        }

        return array(
            'month' => (int) date('n', $startTs),
            'day' => (int) date('j', $startTs),
            'hour' => (int) date('G', $startTs),
            'minute' => (int) date('i', $startTs),
            'second' => (int) date('s', $startTs),
            'duration' => (int) ($endTs - $startTs),
        );
    }

    /**
     * Build monthly candidate start timestamp from template.
     *
     * @param array $template
     * @param int $year
     * @param int $month
     * @return int
     */
    private function buildMonthlyCandidateStart($template, $year, $month)
    {
        $year = (int) $year;
        $month = (int) $month;
        $maxDay = cal_days_in_month(CAL_GREGORIAN, $month, $year);
        $day = min((int) $template['day'], (int) $maxDay);

        return mktime(
            (int) $template['hour'],
            (int) $template['minute'],
            (int) $template['second'],
            $month,
            $day,
            $year
        );
    }

    /**
     * Build yearly candidate start timestamp from template.
     *
     * @param array $template
     * @param int $year
     * @return int
     */
    private function buildYearlyCandidateStart($template, $year)
    {
        $year = (int) $year;
        $month = (int) $template['month'];
        if ($month < 1) $month = 1;
        if ($month > 12) $month = 12;

        $maxDay = cal_days_in_month(CAL_GREGORIAN, $month, $year);
        $day = min((int) $template['day'], (int) $maxDay);

        return mktime(
            (int) $template['hour'],
            (int) $template['minute'],
            (int) $template['second'],
            $month,
            $day,
            $year
        );
    }

    /**
     * Check if now is inside at least one candidate window.
     *
     * @param int $nowTs
     * @param array<int,int> $candidates
     * @param int $duration
     * @return bool
     */
    private function isNowInsideAnyCandidateWindow($nowTs, $candidates, $duration)
    {
        $duration = (int) $duration;
        if ($duration <= 0) {
            return false;
        }

        foreach ($candidates as $candidateStart) {
            $candidateStart = (int) $candidateStart;
            if ($candidateStart <= 0) continue;
            if ($nowTs >= $candidateStart && $nowTs < ($candidateStart + $duration)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Normalize SQL time string into HH:MM:SS.
     *
     * @param string $raw
     * @return string
     */
    private function normalizeSqlTime($raw)
    {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return '';
        }

        if (preg_match('/^\d{2}:\d{2}$/', $raw)) {
            $raw .= ':00';
        }
        if (!preg_match('/^\d{2}:\d{2}:\d{2}$/', $raw)) {
            return '';
        }

        $ts = strtotime('1970-01-01 ' . $raw);
        if (empty($ts)) {
            return '';
        }

        return date('H:i:s', $ts);
    }

    /**
     * Convert DB datetime value to timestamp.
     *
     * @param mixed $value
     * @return int
     */
    private function dbDatetimeToTimestamp($value)
    {
        if (empty($value)) {
            return 0;
        }

        $ts = $this->db->jdate($value);
        if (!empty($ts)) {
            return (int) $ts;
        }

        $ts = strtotime((string) $value);
        return empty($ts) ? 0 : (int) $ts;
    }

    /**
     * Update one rule row state.
     *
     * @param int $rowId
     * @param array<int,string> $setClauses
     * @return bool
     */
    private function updateScheduledPriceRuleState($rowId, $setClauses)
    {
        global $conf;

        $rowId = (int) $rowId;
        if ($rowId <= 0 || !is_array($setClauses) || empty($setClauses)) {
            return false;
        }

        $entity = !empty($conf->entity) ? (int) $conf->entity : 1;
        $sql = "UPDATE " . MAIN_DB_PREFIX . "dolizsynch_price_schedule_rule SET ";
        $sql .= implode(", ", $setClauses);
        $sql .= " WHERE rowid = " . $rowId;
        $sql .= " AND entity = " . $entity;

        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->error = "Unable to update scheduled price rule {$rowId}: " . $this->db->lasterror();
            dol_syslog(__METHOD__ . " - " . $this->error, LOG_ERR);
            return false;
        }

        return true;
    }

    /**
     * Fetches ZoneSoft products with pagination.
     *
     * @param int $offset Pagination offset
     * @return array|false Array of products or false on failure
     */
    private function getZoneSoftProductsToLocalTable($offset)
    {
        global $conf;
        $limit = !empty($conf->global->ZS_GET_NUMBER_OF_PRODUCTS_LIMIT) ? (int) $conf->global->ZS_GET_NUMBER_OF_PRODUCTS_LIMIT : 250;
        if ($limit <= 0) {
            $limit = 250;
        }

        $this->zoneSoftApi->setInterface('products');

        $payload = [
            "product" => [
                "offset" => $offset,
                "limit" => $limit
            ]
        ];

        $response = $this->zoneSoftApi->execute('getInstances', $payload);

        return $this->normalizeProductListFromApiResponse($response, __METHOD__);
    }

    public function insertProductToLocalTable($product)
    {
        global $conf, $user;

        // Ensure $product is an object
        if (!isset($product->product)) {
            dol_syslog("insertProductToLocalTable(): 'product' key is missing in the product object.", LOG_ERR);
            return false;
        }
        $productData = $product->product;

        // Log the contents of $productData

        // Validate the product data before proceeding
        if (empty($productData->codigo)) {
            dol_syslog("insertProductToLocalTable(): Product code ('codigo') is empty or missing.", LOG_ERR);
            return false;
        }

        // Prepare variables, accessing data directly using object notation
        $pvpArray = [
            "pvpmeia10siva" => $productData->pvpmeia10siva ?? 0,
            "pvpmeia1siva" => $productData->pvpmeia1siva ?? 0,
            "pvpmeia2" => $productData->pvpmeia2 ?? 0,
            "pvpmeia2siva" => $productData->pvpmeia2siva ?? 0,
            "pvpmeia3" => $productData->pvpmeia3 ?? 0,
            "pvpmeia3siva" => $productData->pvpmeia3siva ?? 0,
            "pvpmeia4" => $productData->pvpmeia4 ?? 0,
            "pvpmeia4siva" => $productData->pvpmeia4siva ?? 0,
            "pvpmeia5" => $productData->pvpmeia5 ?? 0,
            "pvpmeia5siva" => $productData->pvpmeia5siva ?? 0,
            "pvpmeia6" => $productData->pvpmeia6 ?? 0,
            "pvpmeia6siva" => $productData->pvpmeia6siva ?? 0,
            "pvpmeia7" => $productData->pvpmeia7 ?? 0,
            "pvpmeia7siva" => $productData->pvpmeia7siva ?? 0,
            "pvpmeia8" => $productData->pvpmeia8 ?? 0,
            "pvpmeia8siva" => $productData->pvpmeia8siva ?? 0,
            "pvpmeia9" => $productData->pvpmeia9 ?? 0,
            "pvpmeia9siva" => $productData->pvpmeia9siva ?? 0,
        ];
        $pvpArrayJson = json_encode($pvpArray);

        // Fetch existing retalho_by_store data from the database
        $fk_product = 0;
        if (!empty($productData->fk_product_override)) {
            $fk_product = (int) $productData->fk_product_override;
        }
        if (empty($fk_product)) {
            $fk_product = (int) $this->findDolibarrProductId($productData->codigo, $productData, true);
        }
        if (empty($fk_product)) {
            dol_syslog("insertProductToLocalTable(): Could not resolve fk_product for codigo {$productData->codigo}", LOG_ERR);
            return false;
        }
        $loja_zs = $this->db->escape($conf->global->ZS_API_STORE);

        $sqlExistingRetalho = "SELECT retalho_by_store FROM " . MAIN_DB_PREFIX . "dolizsynch_zsproduct WHERE fk_product = $fk_product LIMIT 1";
        $resExistingRetalho = $this->db->query($sqlExistingRetalho);
        $existingRetalhoArray = [];

        if ($resExistingRetalho) {
            $objExistingRetalho = $this->db->fetch_object($resExistingRetalho);
            if ($objExistingRetalho) {
                if (!empty($objExistingRetalho->retalho_by_store)) {
                    $existingRetalhoArray = json_decode($objExistingRetalho->retalho_by_store, true);
                    if (json_last_error() !== JSON_ERROR_NONE || !is_array($existingRetalhoArray)) {
                        $existingRetalhoArray = [];
                    }
                }
            } else {
            }
        } else {
            dol_syslog("DEBUG: Query failed: " . $this->db->lasterror(), LOG_ERR);
        }

        // Fetch existing product companies (stores) from the database
        $sqlCompanies = "SELECT product_company FROM " . MAIN_DB_PREFIX . "dolizsynch_zsproduct WHERE fk_product = $fk_product LIMIT 1";
        $resCompanies = $this->db->query($sqlCompanies);
        $activeCompanies = [];

        if ($resCompanies) {
            $objCompanies = $this->db->fetch_object($resCompanies);
            if ($objCompanies && !empty($objCompanies->product_company)) {
                $activeCompanies = array_map('trim', explode(',', $objCompanies->product_company));
            }
        } else {
            dol_syslog("insertProductToLocalTable(): Failed to fetch active companies for product ID {$fk_product}. Error: " . $this->db->lasterror(), LOG_ERR);
        }

        // Ensure product_company is set with current ZS store (and keep existing values)
        $productCompanyValues = array();
        foreach ($activeCompanies as $company) {
            $company = trim((string) $company);
            if ($company !== '' && ctype_digit($company)) {
                $productCompanyValues[] = $company;
            }
        }

        if (isset($productData->product_company) && $productData->product_company !== null && $productData->product_company !== '') {
            if (is_array($productData->product_company)) {
                $incomingCompanies = $productData->product_company;
            } else {
                $incomingCompanies = explode(',', (string) $productData->product_company);
            }
            foreach ($incomingCompanies as $company) {
                $company = trim((string) $company);
                if ($company !== '' && ctype_digit($company)) {
                    $productCompanyValues[] = $company;
                }
            }
        }

        $storeForProductCompany = '';
        if (!empty($conf->global->ZS_API_STORE)) {
            $storeForProductCompany = (string) $conf->global->ZS_API_STORE;
        } elseif (!empty($productData->loja)) {
            $storeForProductCompany = (string) $productData->loja;
        } elseif (!empty($productData->loja_zs)) {
            $storeForProductCompany = (string) $productData->loja_zs;
        }
        $storeForProductCompany = trim($storeForProductCompany);
        if ($storeForProductCompany !== '' && ctype_digit($storeForProductCompany)) {
            $productCompanyValues[] = $storeForProductCompany;
        }

        $productCompanyValues = array_values(array_unique($productCompanyValues));
        sort($productCompanyValues, SORT_NUMERIC);
        $product_company = $this->db->escape(implode(',', $productCompanyValues));


        // Start with existing data or empty array
        $retalhoArray = !empty($existingRetalhoArray) ? $existingRetalhoArray : [];

        // Update only the current store's retalho value from API data (do not auto-fill other stores)
        $currentStoreId = 0;
        if (!empty($conf->global->ZS_API_STORE)) {
            $currentStoreId = (int) $conf->global->ZS_API_STORE;
        } elseif (!empty($productData->loja)) {
            $currentStoreId = (int) $productData->loja;
        } elseif (!empty($productData->loja_zs)) {
            $currentStoreId = (int) $productData->loja_zs;
        }

        if ($currentStoreId > 0) {
            $retalhoValueForStore = null;

            if (isset($productData->retalho) && $productData->retalho !== null && $productData->retalho !== '') {
                if (is_array($productData->retalho)) {
                    if (array_key_exists((string) $currentStoreId, $productData->retalho)) {
                        $retalhoValueForStore = $productData->retalho[(string) $currentStoreId];
                    } elseif (array_key_exists($currentStoreId, $productData->retalho)) {
                        $retalhoValueForStore = $productData->retalho[$currentStoreId];
                    } elseif (count($productData->retalho) === 1) {
                        $retalhoValueForStore = reset($productData->retalho);
                    }
                } elseif (is_string($productData->retalho) && strpos($productData->retalho, '{') === 0) {
                    $apiRetalhoArray = json_decode($productData->retalho, true);
                    if (is_array($apiRetalhoArray)) {
                        if (array_key_exists((string) $currentStoreId, $apiRetalhoArray)) {
                            $retalhoValueForStore = $apiRetalhoArray[(string) $currentStoreId];
                        } elseif (array_key_exists($currentStoreId, $apiRetalhoArray)) {
                            $retalhoValueForStore = $apiRetalhoArray[$currentStoreId];
                        } elseif (count($apiRetalhoArray) === 1) {
                            $retalhoValueForStore = reset($apiRetalhoArray);
                        }
                    }
                } else {
                    $retalhoValueForStore = $productData->retalho;
                }
            }

            $retalhoValueForStore = trim((string) ($retalhoValueForStore ?? ''));
            if ($retalhoValueForStore === '') {
                $retalhoValueForStore = isset($retalhoArray[(string) $currentStoreId]) ? (string) $retalhoArray[(string) $currentStoreId] : '1';
            }

            $retalhoArray[(string) $currentStoreId] = (string) $retalhoValueForStore;
        }

        // Keep retalho_by_store keys aligned with product_company stores
        if (!empty($productCompanyValues)) {
            $allowedStores = array();
            foreach ($productCompanyValues as $storeId) {
                $storeId = trim((string) $storeId);
                if ($storeId !== '') {
                    $allowedStores[$storeId] = true;
                }
            }
            if (!empty($allowedStores)) {
                $retalhoArray = array_intersect_key($retalhoArray, $allowedStores);
            }
        }

        // Encode final array
        $retalhoJson = json_encode($retalhoArray);

        if (json_last_error() !== JSON_ERROR_NONE) {
            dol_syslog("insertProductToLocalTable(): JSON encoding of retalho failed. Error: " . json_last_error_msg(), LOG_ERR);
            return false;
        }

        // Escape the JSON string
        $retalhoEscaped = $this->db->escape($retalhoJson);

        // Apagar menus se composto não for menu - set to empty if not a menu type
        $shouldClearMenu = ($productData->composto != '2');

        // Escape variables to prevent SQL injection
        $armazem = $this->db->escape($productData->armazem ?? '1');
        $array_preco_meia = $this->db->escape($pvpArrayJson);
        $autoquebra = $this->db->escape($productData->autoquebra ?? '0');
        $balanca = $this->db->escape($productData->balanca ?? '0');
        $categoria = $this->db->escape($productData->categoria ?? $conf->global->ZS_DEFAULT_PRODUCT_CATEGORY);
        $codbarras = $this->db->escape($productData->codbarras ?? '');
        $codigo = $this->db->escape($productData->codigo ?? '');
        $codigopp = $this->db->escape($productData->codigopp ?? '');
        $composto = $this->db->escape($productData->composto ?? '');
        $compra = $this->db->escape($productData->compra ?? '0');
        $consumominimo = $this->db->escape($productData->consumominimo ?? '0');
        $cozinha = $this->db->escape($productData->cozinha ?? '0');
        // datacriacao is a ZSBMS field; date_creation is for the local shadow table record
        $datacriacaoRaw = '';
        if (isset($productData->datacriacao)) {
            $datacriacaoRaw = trim((string) $productData->datacriacao);
        }
        if ($datacriacaoRaw === '') {
            $datacriacaoRaw = date('Y-m-d');
        }
        $datacriacao = $this->db->escape($datacriacaoRaw);

        $date_creation = $this->db->escape(date('Y-m-d H:i:s'));
        $descricao = $this->db->escape($productData->descricao ?? '');
        $descricaocurta = $this->db->escape($productData->descricaocurta ?? '');
        $dosedesc = $this->db->escape($productData->dosedesc ?? '0');
        $entity = (int) $conf->entity;
        $excluirdescontos = $this->db->escape($productData->excluirdescontos ?? '0');
        $ementadigital = self::getDigitalMenuValue($productData->produto_opcoes ?? array(), $productData->ementadigital ?? null) ?? 'NULL';
        $familia = $this->db->escape($productData->familia ?? '');
        $fundo = $this->db->escape($productData->fundo ?? '');
        $grupo = $this->db->escape($productData->grupo ?? '0');
        $isencao = $this->db->escape($productData->isencao ?? '');
        $iva = (float) $productData->iva;
        $iva2 = isset($productData->iva2) ? (float) $productData->iva2 : $iva;
        $ivacompra = isset($productData->ivacompra) ? (float) $productData->ivacompra : $iva;
        $ivarevenda = isset($productData->ivarevenda) ? (float) $productData->ivarevenda : $iva;
        $letra = $this->db->escape($productData->letra ?? '');
        $listseparado = $this->db->escape($productData->listseparado ?? '0');
        $loja_zs = $this->db->escape($conf->global->ZS_API_STORE);
        $margembruta = $this->db->escape($productData->margembruta ?? '0');
        $maxopcoes = $this->db->escape($productData->maxopcoes ?? '0');
        $meiadose = $this->db->escape($productData->meiadose ?? '0');
        $meiadosedesc = $this->db->escape($productData->meiadosedesc ?? '0');
        $obs = $this->db->escape($productData->obs ?? '');
        $ordem = $this->db->escape($productData->ordem ?? '0');
        $ordemlocal = $this->db->escape($productData->ordemlocal ?? '0');
        $ordemtop = $this->db->escape($productData->ordemtop ?? '0');
        $percentagemretencao = $this->db->escape($productData->percentagemretencao ?? '0');
        $precomeia = $this->db->escape($productData->precomeia ?? '0');
        $precocompra = (float) $productData->precocompra;
        $precominimo = $this->db->escape($productData->precominimo ?? '0');
        $precovenda = (float) $productData->precovenda;
        $precorevenda = $this->db->escape($productData->precorevenda ?? '0');
        $prepagamento = $this->db->escape($productData->prepagamento ?? '0');
        $produtoscentrosprodDecoded = isset($productData->produtoscentrosprod) ? $this->decodeJsonArrayField($productData->produtoscentrosprod) : null;
        $produtoscentrosprodJson = ($produtoscentrosprodDecoded === null) ? '' : json_encode($produtoscentrosprodDecoded);
        $produtoscentrosprod = $this->db->escape($produtoscentrosprodJson !== false ? $produtoscentrosprodJson : '');
        $pvp1siva = (float) $productData->pvp1siva;
        $pvp2 = (float) $productData->pvp2;
        $pvp2siva = (float) $productData->pvp2siva;
        $pvp3 = (float) $productData->pvp3;
        $pvp3siva = (float) $productData->pvp3siva;
        $pvp4 = (float) $productData->pvp4;
        $pvp4siva = (float) $productData->pvp4siva;
        $pvp5 = (float) $productData->pvp5;
        $pvp5siva = (float) $productData->pvp5siva;
        $pvp6 = (float) $productData->pvp6;
        $pvp6siva = (float) $productData->pvp6siva;
        $pvp7 = (float) $productData->pvp7;
        $pvp7siva = (float) $productData->pvp7siva;
        $pvp8 = (float) $productData->pvp8;
        $pvp8siva = (float) $productData->pvp8siva;
        $pvp9 = (float) $productData->pvp9;
        $pvp9siva = (float) $productData->pvp9siva;
        $pvp10 = (float) $productData->pvp10;
        $pvp10siva = (float) $productData->pvp10siva;
        $qtdmeia = $this->db->escape($productData->qtdmeia ?? '0');
        $referencia = $this->db->escape($productData->referencia ?? '');
        $retencao = (float) $productData->retencao;
        $revenda = $this->db->escape($productData->revenda ?? '0');
        $stocks = $this->db->escape($productData->stocks ?? '0');
        $subcategoria = $this->db->escape($productData->subcategoria ?? '');
        $subfam = $this->db->escape($productData->subfam ?? '');
        $tara = $this->db->escape($productData->tara ?? '0');
        // Normalize time prep value; empty strings are invalid for TIME columns
        $tempoprepRaw = isset($productData->tempoprep) ? trim((string) $productData->tempoprep) : '';
        if ($tempoprepRaw === '') {
            $tempoprepRaw = '00:00:00';
        }
        $tempoprep = $this->db->escape($tempoprepRaw);
        $tiposaft = $this->db->escape($productData->tiposaft ?? 'P');
        $topo = $this->db->escape($productData->topo ?? '0');
        $transferivel = $this->db->escape($productData->transferivel ?? '0');
        $storeForProdutoOpcoes = 0;
        if (!empty($productData->loja)) {
            $storeForProdutoOpcoes = (int) $productData->loja;
        } elseif (!empty($productData->loja_zs)) {
            $storeForProdutoOpcoes = (int) $productData->loja_zs;
        } elseif (!empty($conf->global->ZS_API_STORE)) {
            $storeForProdutoOpcoes = (int) $conf->global->ZS_API_STORE;
        }

        $hasProdutoOpcoesInput = isset($productData->produto_opcoes) || isset($productData->fuel);
        $normalizedProdutoOpcoes = $hasProdutoOpcoesInput
            ? $this->normalizeProdutoOpcoesForPayload(
                $productData->produto_opcoes ?? array(),
                $productData->codigo ?? '',
                $storeForProdutoOpcoes,
                $productData->fuel ?? null
            )
            : array();
        $uncompra = $this->db->escape($productData->uncompra ?? '');
        $unidade = $this->db->escape($productData->unidade ?? '');
        $uninventario = $this->db->escape($productData->uninventario ?? '');
        $ultprecovenda = (float) $productData->ultprecovenda;
        $vendersemstock = (int) $productData->vendersemstock;
        $caracteristicasDecoded = isset($productData->caracteristicas) ? $this->decodeJsonArrayField($productData->caracteristicas) : null;
        $caracteristicasJson = ($caracteristicasDecoded === null) ? '' : json_encode($caracteristicasDecoded);
        $caracteristicas = $this->db->escape($caracteristicasJson !== false ? $caracteristicasJson : '');

        $complementaresDecoded = isset($productData->complementares) ? $this->decodeJsonArrayField($productData->complementares) : null;
        $complementaresJson = ($complementaresDecoded === null) ? '' : json_encode($complementaresDecoded);
        $complementares = $this->db->escape($complementaresJson !== false ? $complementaresJson : '');

        $niveismenuDecoded = isset($productData->niveismenu) ? $this->decodeJsonArrayField($productData->niveismenu) : null;
        $niveismenuJson = $shouldClearMenu ? '' : (($niveismenuDecoded === null) ? '' : json_encode($niveismenuDecoded));
        $niveismenu = $this->db->escape($niveismenuJson !== false ? $niveismenuJson : '');

        $produtoOpcoesJson = $hasProdutoOpcoesInput ? json_encode($normalizedProdutoOpcoes) : '';
        $produto_opcoes = $this->db->escape($produtoOpcoesJson !== false ? $produtoOpcoesJson : '');

        $produtosPropriedadesDecoded = isset($productData->produtos_propriedades) ? $this->decodeJsonArrayField($productData->produtos_propriedades) : null;
        $produtosPropriedadesJson = ($produtosPropriedadesDecoded === null) ? '' : json_encode($produtosPropriedadesDecoded);
        $produtos_propriedades = $this->db->escape($produtosPropriedadesJson !== false ? $produtosPropriedadesJson : '');

        $produtosCodigosBarrasDecoded = isset($productData->produtos_codigosbarras) ? $this->decodeJsonArrayField($productData->produtos_codigosbarras) : null;
        $produtosCodigosBarrasJson = ($produtosCodigosBarrasDecoded === null) ? '' : json_encode($produtosCodigosBarrasDecoded);
        $produtos_codigosbarras = $this->db->escape($produtosCodigosBarrasJson !== false ? $produtosCodigosBarrasJson : '');

        $produtosTaxasDecoded = isset($productData->produtos_taxas) ? $this->decodeJsonArrayField($productData->produtos_taxas) : null;
        $produtosTaxasJson = ($produtosTaxasDecoded === null) ? '' : json_encode($produtosTaxasDecoded);
        $produtos_taxas = $this->db->escape($produtosTaxasJson !== false ? $produtosTaxasJson : '');

        $produtosTaxasBrDecoded = isset($productData->produtos_taxas_br) ? $this->decodeJsonArrayField($productData->produtos_taxas_br) : null;
        $produtosTaxasBrJson = ($produtosTaxasBrDecoded === null) ? '' : json_encode($produtosTaxasBrDecoded);
        $produtos_taxas_br = $this->db->escape($produtosTaxasBrJson !== false ? $produtosTaxasBrJson : '');

        $fuelNormalized = null;
        if (isset($productData->fuel)) {
            $fuelNormalized = $this->normalizeProdutoOpcaoValor($productData->fuel);
        }
        if ($fuelNormalized === null) {
            $fuelNormalized = $this->findProdutoOpcaoValor($normalizedProdutoOpcoes, 'FUEL');
        }
        $fuel = (int) (($fuelNormalized !== null && (int) $fuelNormalized > 0) ? 1 : 0);
        $retalhoEscaped = $this->db->escape($retalhoJson);

        $userId = (!empty($user) && is_object($user) && !empty($user->id)) ? (int) $user->id : 1;

        // Prepare the SQL statement
        $sql = "INSERT INTO " . MAIN_DB_PREFIX . "dolizsynch_zsproduct (
                armazem, 
                array_preco_meia, 
                autoquebra, 
                balanca, 
                categoria,
                codbarras,
                codigo,
                codigopp,
                composto, 
                compra, 
                consumominimo, 
                cozinha, 
                datacriacao, 
                descricao, 
                descricaocurta, 
                dosedesc, 
                entity, 
                excluirdescontos,
                ementadigital,
                familia, 
                fk_product, 
                fundo, 
                grupo, 
                isencao, 
                iva, 
                iva2, 
                ivacompra, 
                ivarevenda, 
                letra, 
                listseparado, 
                loja_zs, 
                margembruta, 
                maxopcoes, 
                meiadose, 
                meiadosedesc, 
                obs, 
                ordem, 
                ordemlocal, 
                ordemtop, 
                percentagemretencao, 
                precocompra, 
                precovenda, 
                precorevenda, 
                precomeia, 
                precominimo, 
                prepagamento, 
                produtoscentrosprod, 
                pvp10, 
                pvp10siva, 
                pvp1siva, 
                pvp2, 
                pvp2siva, 
                pvp3, 
                pvp3siva, 
                pvp4, 
                pvp4siva, 
                pvp5, 
                pvp5siva, 
                pvp6, 
                pvp6siva, 
                pvp7, 
                pvp7siva, 
                pvp8, 
                pvp8siva, 
                pvp9, 
                pvp9siva, 
                qtdmeia, 
                referencia, 
                retalho_by_store, 
                retencao, 
                revenda, 
                stocks, 
                subcategoria, 
                subfam, 
                tara, 
                tempoprep, 
                tiposaft, 
                topo, 
                transferivel, 
                fuel, 
                ultprecovenda, 
                caracteristicas, 
                complementares, 
                niveismenu, 
                produto_opcoes, 
                produtos_propriedades, 
                produtos_codigosbarras, 
                produtos_taxas, 
                produtos_taxas_br, 
                uncompra,
                unidade,
                uninventario,
                product_company,
                vendersemstock,
                date_creation,
                fk_user_creat,
                fk_user_modif
            ) VALUES (
                '$armazem', 
                '$array_preco_meia', 
                '$autoquebra', 
                '$balanca', 
                '$categoria',
                '$codbarras',
                '$codigo',
                '$codigopp',
                '$composto', 
                '$compra', 
                '$consumominimo', 
                '$cozinha', 
                '$datacriacao', 
                '$descricao', 
                '$descricaocurta', 
                '$dosedesc', 
                '$entity', 
                '$excluirdescontos',
                $ementadigital,
                '$familia', 
                '$fk_product', 
                '$fundo', 
                '$grupo', 
                '$isencao', 
                '$iva', 
                '$iva2', 
                '$ivacompra', 
                '$ivarevenda', 
                '$letra', 
                '$listseparado', 
                '$loja_zs', 
                '$margembruta', 
                '$maxopcoes', 
                '$meiadose', 
                '$meiadosedesc', 
                '$obs', 
                '$ordem', 
                '$ordemlocal', 
                '$ordemtop', 
                '$percentagemretencao', 
                '$precocompra', 
                '$precovenda', 
                '$precorevenda', 
                '$precomeia', 
                '$precominimo', 
                '$prepagamento', 
                '$produtoscentrosprod', 
                '$pvp10', 
                '$pvp10siva', 
                '$pvp1siva', 
                '$pvp2', 
                '$pvp2siva', 
                '$pvp3', 
                '$pvp3siva', 
                '$pvp4', 
                '$pvp4siva', 
                '$pvp5', 
                '$pvp5siva', 
                '$pvp6', 
                '$pvp6siva', 
                '$pvp7', 
                '$pvp7siva', 
                '$pvp8', 
                '$pvp8siva', 
                '$pvp9', 
                '$pvp9siva', 
                '$qtdmeia', 
                '$referencia', 
                '$retalhoEscaped', 
                '$retencao', 
                '$revenda', 
                '$stocks', 
                '$subcategoria', 
                '$subfam', 
                '$tara', 
                '$tempoprep', 
                '$tiposaft', 
                '$topo', 
                '$transferivel', 
                '$fuel', 
                '$ultprecovenda', 
                '$caracteristicas', 
                '$complementares', 
                '$niveismenu', 
                '$produto_opcoes', 
                '$produtos_propriedades', 
                '$produtos_codigosbarras', 
                '$produtos_taxas', 
                '$produtos_taxas_br', 
                '$uncompra',
                '$unidade',
                '$uninventario',
                '$product_company',
                '$vendersemstock',
                '$date_creation',
                " . $userId . ",
                " . $userId . "
            ) ON DUPLICATE KEY UPDATE 
                armazem = '$armazem', 
                array_preco_meia = '$array_preco_meia', 
                autoquebra = '$autoquebra', 
                balanca = '$balanca', 
                categoria = '$categoria',
                codbarras = '$codbarras',
                codigo = '$codigo',
                codigopp = '$codigopp',
                composto = '$composto', 
                compra = '$compra', 
                consumominimo = '$consumominimo', 
                cozinha = '$cozinha', 
                datacriacao = '$datacriacao', 
                descricao = '$descricao', 
                descricaocurta = '$descricaocurta', 
                dosedesc = '$dosedesc', 
                entity = '$entity', 
                excluirdescontos = '$excluirdescontos',
                ementadigital = COALESCE($ementadigital, ementadigital),
                familia = '$familia', 
                fk_product = '$fk_product', 
                fundo = '$fundo', 
                grupo = '$grupo', 
                isencao = '$isencao', 
                iva = '$iva', 
                iva2 = '$iva2', 
                ivacompra = '$ivacompra', 
                ivarevenda = '$ivarevenda', 
                letra = '$letra', 
                listseparado = '$listseparado', 
                loja_zs = '$loja_zs', 
                margembruta = '$margembruta', 
                maxopcoes = '$maxopcoes', 
                meiadose = '$meiadose', 
                meiadosedesc = '$meiadosedesc', 
                obs = '$obs', 
                ordem = '$ordem', 
                ordemlocal = '$ordemlocal', 
                ordemtop = '$ordemtop', 
                percentagemretencao = '$percentagemretencao', 
                precocompra = '$precocompra', 
                precovenda = '$precovenda', 
                precorevenda = '$precorevenda', 
                precomeia = '$precomeia', 
                precominimo = '$precominimo', 
                prepagamento = '$prepagamento', 
                produtoscentrosprod = '$produtoscentrosprod', 
                pvp10 = '$pvp10', 
                pvp10siva = '$pvp10siva', 
                pvp1siva = '$pvp1siva', 
                pvp2 = '$pvp2', 
                pvp2siva = '$pvp2siva', 
                pvp3 = '$pvp3', 
                pvp3siva = '$pvp3siva', 
                pvp4 = '$pvp4', 
                pvp4siva = '$pvp4siva', 
                pvp5 = '$pvp5', 
                pvp5siva = '$pvp5siva', 
                pvp6 = '$pvp6', 
                pvp6siva = '$pvp6siva', 
                pvp7 = '$pvp7', 
                pvp7siva = '$pvp7siva', 
                pvp8 = '$pvp8', 
                pvp8siva = '$pvp8siva', 
                pvp9 = '$pvp9', 
                pvp9siva = '$pvp9siva', 
                qtdmeia = '$qtdmeia', 
                referencia = '$referencia', 
                retalho_by_store = '$retalhoEscaped', 
                retencao = '$retencao', 
                revenda = '$revenda', 
                stocks = '$stocks', 
                subcategoria = '$subcategoria', 
                subfam = '$subfam', 
                tara = '$tara', 
                tempoprep = '$tempoprep', 
                tiposaft = '$tiposaft', 
                topo = '$topo', 
                transferivel = '$transferivel', 
                fuel = '$fuel', 
                ultprecovenda = '$ultprecovenda', 
                caracteristicas = '$caracteristicas', 
                complementares = '$complementares', 
                niveismenu = '$niveismenu', 
                produto_opcoes = '$produto_opcoes', 
                produtos_propriedades = '$produtos_propriedades', 
                produtos_codigosbarras = '$produtos_codigosbarras', 
                produtos_taxas = '$produtos_taxas', 
                produtos_taxas_br = '$produtos_taxas_br', 
                uncompra = '$uncompra',
                unidade = '$unidade',
                uninventario = '$uninventario',
                product_company = '$product_company',
                vendersemstock = '$vendersemstock',
                date_creation = IF(date_creation IS NULL, '$date_creation', date_creation),
                fk_user_modif = " . $userId;

        // Execute the query
        if (!$this->db->query($sql)) {
            dol_syslog("SQL Error in insertProductToLocalTable(): " . $this->db->lasterror(), LOG_ERR);
            dol_syslog("Failed SQL Query: " . $sql, LOG_ERR);
            return false;
        } else {
        }

        $this->syncKreaAliasMirror($fk_product, isset($productData->descricaocurta) ? (string) $productData->descricaocurta : '');

        return true;
    }

    /**
     *
     *
     * Other auxiliary methods
     *
     *
     */

    /**
     * Find an existing Dolibarr product by ref, with optional auto-create fallback.
     *
     * @param string $zoneSoftProductId The ZoneSoft product code (codigo).
     * @param object|null $zoneSoftProductData Optional ZoneSoft payload used for auto-create.
     * @param bool $createIfMissing Create the product when missing.
     * @return int Dolibarr product rowid or 0 when unresolved.
     */
    function findDolibarrProductId($zoneSoftProductId, $zoneSoftProductData = null, $createIfMissing = true)
    {
        $ref = trim((string) $zoneSoftProductId);
        if ($ref === '') {
            return 0;
        }

        $entityList = getEntity('product');
        $sql = "SELECT rowid FROM " . MAIN_DB_PREFIX . "product";
        $sql .= " WHERE ref = '" . $this->db->escape($ref) . "'";
        if (!empty($entityList)) {
            $sql .= " AND entity IN (" . $entityList . ")";
        }
        $sql .= " ORDER BY rowid ASC";
        $sql .= " " . $this->db->plimit(1);

        $resql = $this->db->query($sql);
        if ($resql) {
            if ($this->db->num_rows($resql) > 0) {
                $obj = $this->db->fetch_object($resql);
                $this->db->free($resql);
                return (int) $obj->rowid;
            }
            $this->db->free($resql);
        } else {
            dol_syslog("Error in findDolibarrProductId: " . $this->db->lasterror(), LOG_ERR);
            return 0;
        }

        if (!$createIfMissing) {
            return 0;
        }

        $createResult = $this->createDolibarrProductFromZoneSoftData($ref, $zoneSoftProductData);
        if ($createResult > 0) {
            return $createResult;
        }

        return 0;
    }

    /**
     * Create a Dolibarr product from ZoneSoft payload when ref does not exist.
     *
     * @param string $ref
     * @param object|null $zoneSoftProductData
     * @return int Newly created (or concurrently created) Dolibarr product rowid, 0 on failure.
     */
    private function createDolibarrProductFromZoneSoftData($ref, $zoneSoftProductData = null)
    {
        global $conf, $user;

        $ref = trim((string) $ref);
        if ($ref === '') {
            return 0;
        }

        $actor = (!empty($user) && !empty($user->id)) ? $user : $this->getFallbackCronUser();
        if (empty($actor) || !isset($actor->id)) {
            $actor = new User($this->db);
            $actor->id = 0;
        }

        $label = trim((string) ($zoneSoftProductData->descricao ?? ''));
        if ($label === '') {
            $label = trim((string) ($zoneSoftProductData->descricaocurta ?? ''));
        }
        if ($label === '') {
            $label = $ref;
        }
        if (strlen($label) > 255) {
            $label = substr($label, 0, 255);
        }

        $vatRaw = (string) ($zoneSoftProductData->iva ?? '0');
        $vat = (float) price2num($vatRaw, 'MU');
        if (!is_finite($vat) || $vat < 0) {
            $vat = 0.0;
        }

        $priceBaseType = strtoupper(trim((string) ($conf->global->PRODUCT_PRICE_BASE_TYPE ?? 'HT')));
        if ($priceBaseType !== 'TTC') {
            $priceBaseType = 'HT';
        }

        $priceRaw = ($priceBaseType === 'TTC')
            ? (string) ($zoneSoftProductData->precovenda ?? $zoneSoftProductData->pvp1 ?? $zoneSoftProductData->pvp1siva ?? '0')
            : (string) ($zoneSoftProductData->pvp1siva ?? $zoneSoftProductData->precovenda ?? $zoneSoftProductData->pvp1 ?? '0');
        $price = (float) price2num($priceRaw, 'MU');
        if (!is_finite($price) || $price < 0) {
            $price = 0.0;
        }

        $product = new Product($this->db);
        $product->ref = $ref;
        $product->label = $label;
        $product->type = 0;
        $product->status = 1;
        $product->status_buy = 0;
        $product->price_base_type = $priceBaseType;
        $product->price = $price;
        $product->price_label = '';
        $product->tva_tx = $vat;
        $product->barcode = '';
        $product->accountancy_code_buy = '';
        $product->accountancy_code_buy_intra = '';
        $product->accountancy_code_buy_export = '';
        $product->accountancy_code_sell = '';
        $product->accountancy_code_sell_intra = '';
        $product->accountancy_code_sell_export = '';

        $createResult = $product->create($actor);
        if ($createResult > 0) {
            dol_syslog("createDolibarrProductFromZoneSoftData(): Created Dolibarr product ref={$ref} rowid={$createResult}", LOG_INFO);
            return (int) $createResult;
        }

        // Race-safe fallback: if another process created it first, resolve by ref again.
        $existingId = (int) $this->findDolibarrProductId($ref, null, false);
        if ($existingId > 0) {
            return $existingId;
        }

        $errorText = trim((string) ($product->error ?? ''));
        if ($errorText === '') {
            $errorText = $this->db->lasterror();
        }
        dol_syslog("createDolibarrProductFromZoneSoftData(): Failed to create Dolibarr product ref={$ref}. Error: {$errorText}", LOG_ERR);

        return 0;
    }

    /**
     * Finds the corresponding Dolibarr product ID for a given ZoneSoft product ID.
     *
     * @param string $zoneSoftProductRef The ZoneSoft product ID.
     * @return int|null The ZoneSoft product ID, or null if not found.
     */
    function findZSProductById($zoneSoftProductId)
    {
        global $db;

        $sql = "SELECT * FROM " . MAIN_DB_PREFIX . "dolizsynch_zsproduct WHERE codigo = '" . $db->escape($zoneSoftProductId) . "'";
        $resql = $db->query($sql);
        if ($resql) {
            if ($db->num_rows($resql) > 0) {
                $resultArray = $db->fetch_array($resql);

                // Log response
                //$responseJson = json_encode($resultArray, JSON_PRETTY_PRINT);
                //dol_syslog("ZSProductSync::findZSProductById: " . $responseJson);

                return $resultArray;
            }
        } else {
            // Handle query error
            dol_syslog("ZSProductSync::findZSProductById: Error in SQL query: " . $db->lasterror(), LOG_ERR);
        }

        return false;
    }

    /**
     * Deletes local ZoneSoft shadow table entries for a Dolibarr product.
     *
     * This removes rows from llx*_dolizsynch_zsproduct where fk_product matches llx*_product.rowid.
     *
     * @param int $fkProduct Dolibarr product id (rowid)
     * @return bool True on success, false on failure
     */
    public function deleteProductFromLocalTable($fkProduct)
    {
        $fkProduct = (int) $fkProduct;
        if ($fkProduct <= 0) {
            $this->error = 'Invalid fk_product';
            dol_syslog(__METHOD__ . ": Invalid fk_product={$fkProduct}", LOG_ERR);
            return false;
        }

        $sql = "DELETE FROM " . MAIN_DB_PREFIX . "dolizsynch_zsproduct WHERE fk_product = " . $fkProduct;
        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->error = $this->db->lasterror();
            dol_syslog(__METHOD__ . ": Error deleting dolizsynch_zsproduct for fk_product={$fkProduct}: " . $this->error, LOG_ERR);
            return false;
        }

        $affectedRows = $this->db->affected_rows($resql);
        dol_syslog(__METHOD__ . ": Deleted {$affectedRows} dolizsynch_zsproduct row(s) for fk_product={$fkProduct}", LOG_INFO);

        return true;
    }

    /**
     * Deletes product instances in ZoneSoft (ZSBMS) using Products/deleteInstances.
     *
     * @param int[] $productCodes Array of ZoneSoft product codes (codigo)
     * @param int|null $store Optional store/company id for company-scoped credentials
     * @return bool True on success, false on failure
     */
    public function deleteZoneSoftProductInstances(array $productCodes, $store = null)
    {
        $codes = array();
        foreach ($productCodes as $code) {
            $codeInt = (int) $code;
            if ($codeInt > 0) {
                $codes[] = $codeInt;
            }
        }

        if (empty($codes)) {
            $this->error = 'Invalid product code(s)';
            dol_syslog(__METHOD__ . ": No valid product codes provided", LOG_ERR);
            return false;
        }

        $payload = array(
            'product' => array_map(function ($codeInt) {
                return array('codigo' => $codeInt);
            }, $codes),
        );

        $this->zoneSoftApi->setInterface('products');

        try {
            $storeInt = ($store !== null) ? (int) $store : 0;
            if ($storeInt > 0) {
                $response = $this->zoneSoftApi->executeUpdateProducts('deleteInstances', $payload, $storeInt);
            } else {
                $response = $this->zoneSoftApi->execute('deleteInstances', $payload);
            }
        } catch (Exception $e) {
            $this->error = $e->getMessage();
            dol_syslog(__METHOD__ . ": Exception calling ZSBMS deleteInstances: " . $this->error, LOG_ERR);
            return false;
        }

        // Some endpoints may return HTTP 204 with no body.
        if (empty($response)) {
            dol_syslog(__METHOD__ . ": ZSBMS deleteInstances returned empty response (treated as success). Codes=" . implode(',', $codes), LOG_INFO);
            return true;
        }

        if (!isset($response['Response'])) {
            $this->error = 'Invalid response received from ZoneSoft API';
            dol_syslog(__METHOD__ . ": " . $this->error . " | Summary: " . $this->summarizeValueForLog($response), LOG_ERR);
            return false;
        }

        $statusCode = isset($response['Response']['StatusCode']) ? (int) $response['Response']['StatusCode'] : 0;
        if ($statusCode === 200) {
            dol_syslog(__METHOD__ . ": ZSBMS deleteInstances OK. Codes=" . implode(',', $codes) . " Store=" . (int) $store, LOG_INFO);
            return true;
        }

        $errorMessage = '';
        if (!empty($response['Response']['ErrorMessage'])) {
            $errorMessage = (string) $response['Response']['ErrorMessage'];
        } elseif (!empty($response['Response']['StatusMessage'])) {
            $errorMessage = (string) $response['Response']['StatusMessage'];
        } else {
            $errorMessage = 'Unknown error';
        }

        $this->error = "ZSBMS deleteInstances failed. Status code: {$statusCode}. Error: {$errorMessage}";
        dol_syslog(__METHOD__ . ": " . $this->error . " | Codes=" . implode(',', $codes) . " Store=" . (int) $store, LOG_ERR);

        return false;
    }

    /**
     * Deletes a single product instance in ZoneSoft (ZSBMS).
     *
     * @param int $productCode ZoneSoft product code (codigo)
     * @param int|null $store Optional store/company id for company-scoped credentials
     * @return bool True on success, false on failure
     */
    public function deleteZoneSoftProductInstance($productCode, $store = null)
    {
        return $this->deleteZoneSoftProductInstances(array($productCode), $store);
    }

    /**
     * Deletes the product instance in ZSBMS (if possible) and cleans local shadow table.
     *
     * @param int $fkProduct Dolibarr product id (rowid)
     * @param int|string|null $fallbackCodigo Fallback ZoneSoft code (typically product ref)
     * @param int|string|null $fallbackStore Fallback store/company id
     * @return bool True if local cleanup succeeded and remote deletion (when attempted) succeeded
     */
    public function deleteProductFromZoneSoftAndLocalTable($fkProduct, $fallbackCodigo = null, $fallbackStore = null)
    {
        global $conf;

        $fkProduct = (int) $fkProduct;
        if ($fkProduct <= 0) {
            $this->error = 'Invalid fk_product';
            dol_syslog(__METHOD__ . ": Invalid fk_product={$fkProduct}", LOG_ERR);
            return false;
        }

        $codigo = 0;
        $store = 0;

        // Prefer mapping from local shadow table (if present)
        $sql = "SELECT codigo, loja_zs FROM " . MAIN_DB_PREFIX . "dolizsynch_zsproduct WHERE fk_product = " . $fkProduct . " LIMIT 1";
        $resql = $this->db->query($sql);
        if ($resql) {
            $obj = $this->db->fetch_object($resql);
            if ($obj) {
                if (!empty($obj->codigo)) {
                    $codigo = (int) $obj->codigo;
                }
                if (!empty($obj->loja_zs)) {
                    $store = (int) $obj->loja_zs;
                }
            }
        } else {
            dol_syslog(__METHOD__ . ": Failed to read dolizsynch_zsproduct mapping for fk_product={$fkProduct}: " . $this->db->lasterror(), LOG_ERR);
        }

        // Fallback to provided codigo (typically the Dolibarr product ref)
        if ($codigo <= 0 && $fallbackCodigo !== null) {
            $fallbackCodigoStr = (string) $fallbackCodigo;
            if ($fallbackCodigoStr !== '' && ctype_digit($fallbackCodigoStr)) {
                $codigo = (int) $fallbackCodigoStr;
            }
        }

        // Resolve store/company id
        if ($store <= 0) {
            if ($fallbackStore !== null) {
                $store = (int) $fallbackStore;
            } elseif (!empty($conf->global->ZS_API_STORE)) {
                $store = (int) $conf->global->ZS_API_STORE;
            }
        }

        $remoteOk = true;
        if ($codigo > 0) {
            $remoteOk = $this->deleteZoneSoftProductInstance($codigo, $store > 0 ? $store : null);
        } else {
            dol_syslog(__METHOD__ . ": No ZoneSoft codigo found for fk_product={$fkProduct}; skipping remote deleteInstances.", LOG_INFO);
        }

        $localOk = $this->deleteProductFromLocalTable($fkProduct);

        return ($remoteOk && $localOk);
    }

    /**
     * Fetches products from ZoneSoft with pagination.
     *
     * @param int $offset The offset for pagination.
     * @param int $limit The number of products to fetch per request.
     * @return array|false Returns an array of products or false on failure.
     */
    public function getZoneSoftProducts($offset, $limit)
    {
        $limit = (int) $limit;
        if ($limit <= 0) {
            $limit = 250;
        }

        $payload = [
            "product" => [
                "offset" => $offset,
                "limit" => $limit
            ]
        ];

        $response = $this->zoneSoftApi->execute('getInstances', $payload);
        return $this->normalizeProductListFromApiResponse($response, __METHOD__);
    }

    /**
     * Fetches a specific product from ZoneSoft by ID.
     *
     * @param string $productId Product ID in ZoneSoft
     * @return array|null Product data or null if not found
     */
    public function getZoneSoftProductById($productId)
    {
        global $conf;

        $this->zoneSoftApi->setInterface('products');

        // Define the payload for the request
        $payload = [
            "product" => [
                "codigo" => $productId
            ]
        ];

        $response = json_decode(json_encode($this->zoneSoftApi->execute('getInstance', $payload))); // crate object

        // Check the response to avoid any unnecessary recursive calls
        if ($response && isset($response->Response)) {
            if ($response->Response->StatusCode == 200) {
                // Ensure you are returning the correct content here without causing a recursive call
                return $response->Response->Content;
            } elseif ($response->Response->StatusCode == 204) {
                dol_syslog("ZSProductSync::getZoneSoftProductById: The requested instance {$productId} was not found (Status 204)", LOG_INFO);
                return false; // Product not found is not an error, it's expected behavior
            } elseif ($response->Response->StatusCode == 400) {
                dol_syslog("ZSProductSync::getZoneSoftProductById: Request malformed", LOG_ERR);
            } else {
                dol_syslog("ZSProductSync::getZoneSoftProductById: Unknown error with status code: " . $response->Response->StatusCode, LOG_ERR);
            }
            return false;
        } else {
            dol_syslog("ZSProductSync::getZoneSoftProductById: Invalid response received while fetching product with ID {$productId} from ZoneSoft", LOG_ERR);
            return false;
        }
    }

    /**
     * Debug version of getZoneSoftProductById that returns raw response
     */
    public function getZoneSoftProductByIdDebug($productId)
    {
        global $conf;

        $this->zoneSoftApi->setInterface('products');

        // Define the payload for the request
        $payload = [
            "product" => [
                "codigo" => $productId
            ]
        ];

        return $this->zoneSoftApi->execute('getInstance', $payload);
    }

    /**
     * Get the next available product codigo from ZoneSoft API
     *
     * @return int|false Next available codigo or false on failure
     */
    public function getNextProductCodigo()
    {
        global $conf;

        $this->zoneSoftApi->setInterface('products');

        // Define the payload for getNextCodigo - empty product object as per documentation
        $payload = [
            "product" => []
        ];

        $response = json_decode(json_encode($this->zoneSoftApi->execute('getNextCodigo', $payload)));

        // Check the response
        if ($response && isset($response->Response)) {
            if ($response->Response->StatusCode == 200) {
                // Extract the codigo from the response
                if (isset($response->Response->Content->product->codigo)) {
                    return (int)$response->Response->Content->product->codigo;
                } elseif (isset($response->Response->Content->codigo)) {
                    return (int)$response->Response->Content->codigo;
                } else {
                    dol_syslog("ZSProductSync::getNextProductCodigo: No codigo found in response", LOG_ERR);
                    return false;
                }
            } elseif ($response->Response->StatusCode == 400) {
                dol_syslog("ZSProductSync::getNextProductCodigo: Request malformed", LOG_ERR);
                return false;
            } else {
                dol_syslog("ZSProductSync::getNextProductCodigo: Unknown error with status code: " . $response->Response->StatusCode, LOG_ERR);
                return false;
            }
        } else {
            dol_syslog("ZSProductSync::getNextProductCodigo: Invalid response received", LOG_ERR);
            return false;
        }
    }

    /**
     * Check if product exists in specific ZSBMS store
     */
    public function getZoneSoftProductByIdInStore($productId, $store)
    {
        global $conf;

        $this->zoneSoftApi->setInterface('products');

        // Define the payload for the request including store
        $payload = [
            "product" => [
                "codigo" => $productId,
                "loja_zs" => $store
            ]
        ];

        $response = json_decode(json_encode($this->zoneSoftApi->execute('getInstance', $payload)));

        // Check the response to avoid any unnecessary recursive calls
        if ($response && isset($response->Response)) {
            if ($response->Response->StatusCode == 200) {
                // Check if the returned product is actually for the requested store
                $content = $response->Response->Content;
                if ($content && isset($content->product) && isset($content->product->loja) && $content->product->loja == $store) {
                    return $content;
                } else {
                    // Add detailed debugging for store mismatch
                    $foundStore = isset($content->product->loja) ? $content->product->loja : 'UNKNOWN';
                    dol_syslog("ZSProductSync::getZoneSoftProductByIdInStore: Product {$productId} found in store {$foundStore} but requested store {$store}", LOG_INFO);
                    return false;
                }
            } elseif ($response->Response->StatusCode == 204) {
                dol_syslog("ZSProductSync::getZoneSoftProductByIdInStore: Product {$productId} not found in store {$store} (Status 204)", LOG_INFO);
                return false;
            } elseif ($response->Response->StatusCode == 400) {
                dol_syslog("ZSProductSync::getZoneSoftProductByIdInStore: Request malformed for store {$store}", LOG_ERR);
            } else {
                dol_syslog("ZSProductSync::getZoneSoftProductByIdInStore: Unknown error with status code: " . $response->Response->StatusCode . " for store {$store}", LOG_ERR);
            }
            return false;
        } else {
            dol_syslog("ZSProductSync::getZoneSoftProductByIdInStore: Invalid response received while fetching product {$productId} from store {$store}", LOG_ERR);
            return false;
        }
    }

    /**
     * Mark on ZoneSoft all instances of the Model Entity to be synchronized
     * Mark all instances of the Model Entity to be synchronized.
     *
     * @return array|false Array of products or false on failure
     */
    public function setFullSyncModelEntity($productDetails)
    {
        global $conf;

        $this->zoneSoftApi->setInterface('products');

        // Define the payload for the request
        $payload = [
            "product" => [$productDetails]
        ];

        $response = json_decode(json_encode($this->zoneSoftApi->execute('setFullSync', $payload)));

        if ($response && isset($response->Response) && $response->Response->StatusCode == 200) {
            return $response->Response->Content;
        } else {
            dol_syslog("Failed to fetch products from ZoneSoft", LOG_ERR);
            return false;
        }
    }

    /**
     * Get the latest price for each level of a product.
     *
     * @param int $productId The ID of the product.
     * @return array An associative array of the latest prices by level.
     */
    public function getLatestPriceByLevel($productId)
    {
        global $db; // Dolibarr global database handler

        $sql = "SELECT p.fk_product, p.price_level, p.date_price AS latest_date, p.price, p.price_ttc, p.price_base_type
				FROM " . MAIN_DB_PREFIX . "product_price p
				INNER JOIN (
    				SELECT fk_product, price_level, MAX(date_price) AS max_date
    				FROM " . MAIN_DB_PREFIX . "product_price
    				WHERE fk_product =  " . (int)$productId . "
    			GROUP BY fk_product, price_level) AS latest_prices ON p.fk_product = latest_prices.fk_product
                   AND p.price_level = latest_prices.price_level
                   AND p.date_price = latest_prices.max_date
				WHERE p.fk_product =  " . (int)$productId . "
				ORDER BY p.date_price DESC, p.price_level";

        $resql = $db->query($sql);
        if ($resql) {
            $latestPrices = array();
            while ($obj = $db->fetch_object($resql)) {
                $latestPrices[$obj->fk_product][$obj->price_level] = array(
                    'price' => $obj->price,
                    'price_ttc' => $obj->price_ttc,
                    'price_base_type' => $obj->price_base_type
                );
            }
            return $latestPrices;
        } else {
            throw new Exception($db->lasterror());
        }
    }

    /**
     * Build the restricted ZSBMS payload used by the scheduled price-only synchronization.
     *
     * Identity fields are required by ZSBMS to address the existing product. Every other
     * field is either a Dolibarr selling price level or the Dolibarr cost price.
     *
     * @param Product $product Dolibarr product loaded in the active entity
     * @param int $store ZSBMS store configured for the active entity
     * @return array|false Restricted saveInstances payload, or false on failure
     */
    public function buildZsbmsPricePayload($product, $store)
    {
        global $conf;

        $this->error = '';
        $productId = (int) ($product->id ?? 0);
        $store = (int) $store;
        if ($productId <= 0 || $store <= 0) {
            $this->error = 'A valid Dolibarr product and ZSBMS store are required for price synchronization';
            return false;
        }

        $sql = 'SELECT codigo FROM ' . MAIN_DB_PREFIX . 'dolizsynch_zsproduct';
        $sql .= ' WHERE fk_product=' . $productId . ' ORDER BY rowid DESC ' . $this->db->plimit(1);
        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->error = $this->db->lasterror();
            return false;
        }
        $mapping = $this->db->fetch_object($resql);
        $this->db->free($resql);
        if (!$mapping) {
            $this->error = "Product '{$product->ref}' has no KreaFlow ZSBMS mapping";
            return false;
        }

        $codigo = isset($mapping->codigo) && is_numeric($mapping->codigo) ? (int) $mapping->codigo : 0;
        if ($codigo <= 0 && !empty($product->ref) && ctype_digit((string) $product->ref)) {
            $codigo = (int) $product->ref;
        }
        if ($codigo <= 0) {
            $this->error = "Product '{$product->ref}' has no valid numeric ZSBMS product code";
            return false;
        }

        $details = array(
            'loja' => $store,
            'codigo' => $codigo,
            'precocompra' => $this->formatZsbmsPrice($product->cost_price ?? 0),
        );

        $multiPricesEnabled = !empty($conf->global->PRODUIT_MULTIPRICES);
        $priceLevelLimit = $multiPricesEnabled
            ? (int) getDolGlobalInt('PRODUIT_MULTIPRICES_LIMIT', 5)
            : 1;
        if ($priceLevelLimit <= 0) {
            $priceLevelLimit = 1;
        }
        if ($priceLevelLimit > 10) {
            $this->error = 'ZSBMS supports at most 10 Dolibarr selling price levels';
            return false;
        }

        for ($level = 1; $level <= $priceLevelLimit; $level++) {
            $latestPrice = $multiPricesEnabled ? $this->fetchLatestEntityPriceLevel($productId, $level) : null;
            if ($latestPrice === false) {
                return false;
            }

            if (is_object($latestPrice)) {
                $priceHt = $latestPrice->price;
                $priceTtc = $latestPrice->price_ttc;
            } elseif ($level === 1) {
                $priceHt = isset($product->multiprices[$level])
                    ? $product->multiprices[$level]
                    : ($product->price ?? 0);
                $priceTtc = isset($product->multiprices_ttc[$level])
                    ? $product->multiprices_ttc[$level]
                    : ($product->price_ttc ?? 0);
            } else {
                $priceHt = $product->multiprices[$level] ?? 0;
                $priceTtc = $product->multiprices_ttc[$level] ?? 0;
            }

            $ttcField = $level === 1 ? 'precovenda' : 'pvp' . $level;
            $htField = $level === 1 ? 'pvp1siva' : 'pvp' . $level . 'siva';
            $details[$ttcField] = $this->formatZsbmsPrice($priceTtc);
            $details[$htField] = $this->formatZsbmsPrice($priceHt);
        }

        try {
            $details = (new ZSStorePrices($this->db))->apply($product, $store, $details);
        } catch (Throwable $e) {
            $this->error = $e->getMessage();
            return false;
        }
        return array('product' => array($details));
    }

    /**
     * Update only Dolibarr selling-price levels and cost price in one ZSBMS store.
     *
     * @param Product $product Dolibarr product loaded in the active entity
     * @param int $store ZSBMS store configured for the active entity
     * @return bool
     */
    public function updateZsbmsProductPricesForStore($product, $store)
    {
        $payload = $this->buildZsbmsPricePayload($product, $store);
        if ($payload === false) {
            dol_syslog(__METHOD__ . ': ' . $this->error, LOG_ERR);
            return false;
        }

        $this->zoneSoftApi->setInterface('products');
        try {
            $response = $this->zoneSoftApi->executeUpdateProducts('saveInstances', $payload, (int) $store);
        } catch (Throwable $e) {
            $this->error = "ZSBMS price synchronization failed for product '{$product->ref}': " . $e->getMessage();
            dol_syslog(__METHOD__ . ': ' . $this->error, LOG_ERR);
            return false;
        }

        if (!is_array($response) || !isset($response['Response']) || !is_array($response['Response'])) {
            $this->error = "Invalid ZSBMS response while updating prices for product '{$product->ref}'";
            dol_syslog(__METHOD__ . ': ' . $this->error, LOG_ERR);
            return false;
        }

        $statusCode = (int) ($response['Response']['StatusCode'] ?? 0);
        if ($statusCode === 201) {
            dol_syslog(
                __METHOD__ . ': synchronized selling prices and cost price for product '
                . ((int) $product->id) . ' in store ' . ((int) $store),
                LOG_INFO
            );
            $this->error = '';
            return true;
        }

        $statusMessage = trim((string) ($response['Response']['StatusMessage'] ?? ''));
        $errorMessage = trim((string) ($response['Response']['ErrorMessage'] ?? ''));
        $contentError = $this->extractApiErrorSummaryFromContent($response['Response']['Content'] ?? null);
        $instanceErrors = $this->extractInstanceErrorsFromApiResponse($response, 'product');
        $instanceErrorSummary = $this->buildInstanceErrorsSummary($instanceErrors);
        $effectiveError = $instanceErrorSummary ?: ($errorMessage ?: ($contentError ?: ($statusMessage ?: 'Unknown error')));
        $this->error = "Failed to update prices for product '{$product->ref}'. Status code: {$statusCode}. Error: {$effectiveError}";
        dol_syslog(__METHOD__ . ': ' . $this->error, LOG_ERR);
        $this->persistInstanceErrorsToErrorLog(
            'product_price_sync',
            'ZSBMS product price synchronization failed',
            $instanceErrors,
            array(
                'product_id' => (int) $product->id,
                'product_ref' => (string) $product->ref,
                'store' => (int) $store,
                'status_code' => $statusCode,
                'status_message' => $statusMessage,
            )
        );

        return false;
    }

    /**
     * Fetch the most recent price row for one level within native product-price visibility.
     *
     * @param int $productId Product ID
     * @param int $priceLevel Dolibarr selling price level
     * @return object|null|false Price row, null when absent, false on query failure
     */
    private function fetchLatestEntityPriceLevel($productId, $priceLevel)
    {
        $sql = 'SELECT price, price_ttc FROM ' . MAIN_DB_PREFIX . 'product_price';
        $sql .= ' WHERE fk_product=' . ((int) $productId);
        $sql .= ' AND entity IN (' . getEntity('productprice') . ')';
        $sql .= ' AND price_level=' . ((int) $priceLevel);
        $sql .= ' ORDER BY date_price DESC, rowid DESC ' . $this->db->plimit(1);
        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->error = $this->db->lasterror();
            return false;
        }
        $row = $this->db->fetch_object($resql);
        $this->db->free($resql);
        return $row ?: null;
    }

    /**
     * Format a Dolibarr price for the ZSBMS product-price interface.
     *
     * @param mixed $value Price value
     * @return string
     */
    private function formatZsbmsPrice($value)
    {
        return number_format((float) $value, 2, '.', '');
    }

    /**
     *
     *
     * Auto update changes
     *
     *
     */

    /**
     * Updates a product in ZoneSoft using data from a Dolibarr product.
     *
     * @param Product $product The Dolibarr product to sync to ZoneSoft
     * @param bool $forceSync Force synchronization when the product opt-in is disabled
     * @param bool|array<int, int> $overrideVariablePrices True for all levels, or the explicit levels allowed to replace -1
     * @return bool True on success, false on failure.
     */
    public function updateZsbmsProduct($product, $forceSync = false, $overrideVariablePrices = false)
    {
        global $conf, $db, $langs;

        // Initialize result variable
        $updateSuccess = false;
        $updateErrors = array();

        // Check if synchronization should be skipped
        $synchEnabled = isset($product->array_options['options_zs_synch']) ? (int)$product->array_options['options_zs_synch'] : 0;
        if ($synchEnabled === 0 && !$forceSync) {
            dol_syslog("ZSProductSync::updateZsbmsProduct: Skipping synchronization for product ID {$product->id} due to zs_synch = 0.", LOG_INFO);
            return false;
        }
        if ($synchEnabled === 0 && $forceSync) {
            dol_syslog("ZSProductSync::updateZsbmsProduct: Forced synchronization for product ID {$product->id} despite zs_synch = 0.", LOG_WARNING);
        }

        try {
            (new ZSStorePrices($this->db))->fetch($product->id);
        } catch (Throwable $e) {
            $this->error = $e->getMessage();
            return false;
        }

        // CRITICAL: First, update the llx_dolizsynch_zsproduct table with current Dolibarr prices
        // This ensures the table reflects the latest price changes made in Dolibarr
        dol_syslog("ZSProductSync::updateZsbmsProduct: Updating ZS table with current Dolibarr prices for product ID {$product->id}", LOG_INFO);
        $zsTableUpdated = $this->updateZsTablePricesFromDolibarr($product, $overrideVariablePrices);
        if (!$zsTableUpdated) {
            $this->error = "Failed to update local ZS shadow table for product '{$product->ref}'";
            dol_syslog("ZSProductSync::updateZsbmsProduct: " . $this->error, LOG_ERR);
            return false;
        }

        // Fetch and parse stores to update
        $sql = "SELECT * FROM " . MAIN_DB_PREFIX . "dolizsynch_zsproduct WHERE fk_product = " . (int)$product->id . " ORDER BY rowid DESC LIMIT 1";
        $resql = $db->query($sql);
        if ($resql) {
            $obj = $db->fetch_object($resql);
            if ($obj) {
                $product_company = array();
                if (!empty($obj->product_company)) {
                    $product_company_array = explode(',', (string) $obj->product_company);
                    foreach ($product_company_array as $companyValue) {
                        $companyValue = trim((string) $companyValue);
                        if ($companyValue !== '' && ctype_digit($companyValue) && ((int) $companyValue) > 0) {
                            $product_company[] = (string) ((int) $companyValue);
                        }
                    }
                }

                $savedDescricaoCurta = $obj->descricaocurta;
                $savedCodigo = $obj->codigo ?? '';
                $savedCodigoPP = $obj->codigopp ?? '';
                $savedCodbarras = $obj->codbarras ?? '';
                $savedCompra = $obj->compra ?? 0;
                $savedObs = $obj->obs ?? '';
                $savedPercentagemRetencao = $obj->percentagemretencao ?? 0;
                $savedUltPrecoVenda = $obj->ultprecovenda ?? 0;
                //$savedRetalho = $obj->retalho_by_store;
                $savedRetalhoValues = json_decode($obj->retalho_by_store, true);
                if (json_last_error() !== JSON_ERROR_NONE || !is_array($savedRetalhoValues)) {
                    $savedRetalhoValues = array();
                }
                $savedComposto = $obj->composto;
                $savedFamilia = $obj->familia;
                $savedSubFam = $obj->subfam;
                $savedCategoria = $obj->categoria;
                $savedSubCategoria = $obj->subcategoria;
                $savedVenderSemStock = $obj->vendersemstock;
                $savedUnidade = $obj->unidade;
                $savedUncompra = $obj->uncompra;
                $savedUninventario = $obj->uninventario;
                $savedBackgroundColor = $obj->fundo;
                $savedFontColor = $obj->letra;
                $savedArmazem = $obj->armazem;
                $savedAutoquebra = $obj->autoquebra;
                $savedBalanca = $obj->balanca;
                $savedConsumoMinimo = $obj->consumominimo;
                $savedCozinha = $obj->cozinha;
                $savedDoseDesc = $obj->dosedesc;
                $savedExcluirDescontos = $obj->excluirdescontos;
                $savedGrupo = $obj->grupo;
                $savedIsencao = $obj->isencao;
                $savedIva2 = $obj->iva2 ? $obj->iva2 : $obj->iva;
                $savedIvaCompra = $obj->ivacompra ? $obj->ivacompra : $obj->iva;
                $savedIvaRevenda = $obj->ivarevenda ? $obj->ivarevenda : $obj->iva;
                $savedListSeparado = $obj->listseparado;
                $savedMargemBruta = $obj->margembruta;
                $savedMaxOpcoes = $obj->maxopcoes;
                $savedMeiaDose = $obj->meiadose;
                $savedMeiaDoseDesc = $obj->meiadosedesc;
                $savedOrdem = $obj->ordem;
                $savedOrdemLocal = $obj->ordemlocal;
                $savedOrdemTop = $obj->ordemtop;
                $savedPrecoMeia = $obj->precomeia;
                $savedPrecoMinimo = $obj->precominimo;
                $savedPrecoRevenda = $obj->precorevenda;
                $savedPrePagamento = $obj->prepagamento;
                $savedProdutosCentrosProd = $obj->produtoscentrosprod;
                $savedArrayPrecoMeia = $obj->array_preco_meia;
                $savedQtdMeia = $obj->qtdmeia;
                $savedReferencia = $obj->referencia;
                $savedRetencao = $obj->retencao;
                $savedRevenda = $obj->revenda;
                $savedStocks = $obj->stocks;
                $savedTara = $obj->tara;
                $savedTempoPrep = $obj->tempoprep;
                $savedTipoSaft = $obj->tiposaft;
                $savedTopo = $obj->topo;
                $savedTransferivel = $obj->transferivel;
                $savedCaracteristicas = $obj->caracteristicas;
                $savedComplementares = $obj->complementares;
                $savedNiveismenu = $obj->niveismenu;
                $savedProduto_opcoes = $obj->produto_opcoes;
                $savedProdutos_propriedades = $obj->produtos_propriedades;
                $savedProdutos_codigosbarras = $obj->produtos_codigosbarras;
                $savedProdutosCodigosBarrasWasNull = (property_exists($obj, 'produtos_codigosbarras') && $obj->produtos_codigosbarras === null);
                $savedProdutos_taxas = $obj->produtos_taxas ?? '';
                $savedProdutos_taxas_br = $obj->produtos_taxas_br ?? '';
                $savedFuel = $obj->fuel ?? null;

                $retalhoStores = array();
                foreach (array_keys($savedRetalhoValues) as $storeKey) {
                    $storeKey = trim((string) $storeKey);
                    if ($storeKey !== '' && ctype_digit($storeKey) && ((int) $storeKey) > 0) {
                        $retalhoStores[] = (string) ((int) $storeKey);
                    }
                }

                // Source of truth: retalho_by_store keys; keep product_company as fallback/complement.
                $storesToUpdate = array_values(array_unique(array_merge($retalhoStores, $product_company)));
                if (empty($storesToUpdate) && !empty($obj->loja_zs) && ctype_digit((string) $obj->loja_zs) && ((int) $obj->loja_zs) > 0) {
                    $storesToUpdate[] = (string) ((int) $obj->loja_zs);
                }
                if (empty($storesToUpdate) && !empty($conf->global->ZS_API_STORE) && ctype_digit((string) $conf->global->ZS_API_STORE)) {
                    $storesToUpdate[] = (string) ((int) $conf->global->ZS_API_STORE);
                }

                if (empty($storesToUpdate)) {
                    dol_syslog("ZSProductSync::updateZsbmsProduct: No stores found for product ID {$product->id}. Skipping synchronization.", LOG_WARNING);
                    return false;
                }

                sort($storesToUpdate, SORT_NUMERIC);
                $storesToUpdate = array_values(array_unique($storesToUpdate));

            } else {
                dol_syslog("No product company found in the database.", LOG_WARNING);
                return false;
            }
        } else {
            dol_syslog("Database query failed.", LOG_ERR);
            return false;
        }

        // Check if multi-prices is enabled
        $multiPricesEnabled = !empty($conf->global->PRODUIT_MULTIPRICES);

        // Set the API interface to 'Products'.
        $this->zoneSoftApi->setInterface('products');

        $updateSuccess = true;

        foreach ($storesToUpdate as $storeValue) {
            $store = (int) $storeValue;
            if ($store <= 0) {
                continue;
            }

            //$this->createZoneSoftProduct($product, $store);

            $retalhoValue = 1; // Default to 1 if not set
            if (array_key_exists((string) $store, $savedRetalhoValues)) {
                $retalhoRawValue = $savedRetalhoValues[(string) $store];
                if ($retalhoRawValue !== '' && $retalhoRawValue !== null && is_numeric($retalhoRawValue)) {
                    $retalhoValue = (int) $retalhoRawValue;
                }
            } elseif (array_key_exists($store, $savedRetalhoValues)) {
                $retalhoRawValue = $savedRetalhoValues[$store];
                if ($retalhoRawValue !== '' && $retalhoRawValue !== null && is_numeric($retalhoRawValue)) {
                    $retalhoValue = (int) $retalhoRawValue;
                }
            }
            $costPrice = (isset($product->cost_price) && $product->cost_price !== '') ? (float) $product->cost_price : 0.0;

            // Normalize codigopp to integer as required by the API
            if ($savedCodigoPP !== '' && $savedCodigoPP !== null && ctype_digit((string) $savedCodigoPP)) {
                $codigoppValue = (int) $savedCodigoPP;
            } else {
                $codigoppValue = 0;
            }

            $codigoForPayload = (!empty($savedCodigo) ? $savedCodigo : $product->ref);
            $produtosCentrosProdPayload = $this->decodeJsonArrayFieldForPayload($savedProdutosCentrosProd);
            $arrayPrecoMeiaPayload = $this->decodeJsonArrayFieldForPayload($savedArrayPrecoMeia);
            $caracteristicasPayload = $this->decodeJsonArrayFieldForPayload($savedCaracteristicas);
            $complementaresPayload = $this->decodeJsonArrayFieldForPayload($savedComplementares);
            $produtosPropriedadesPayload = $this->decodeJsonArrayFieldForPayload($savedProdutos_propriedades);
            $produtosCodigosBarrasPayload = $this->decodeJsonArrayFieldForPayload($savedProdutos_codigosbarras);
            $produtosTaxasPayload = $this->decodeJsonArrayFieldForPayload($savedProdutos_taxas);
            $produtosTaxasBrPayload = $this->decodeJsonArrayFieldForPayload($savedProdutos_taxas_br);
            $produtoOpcoesPayload = $this->normalizeProdutoOpcoesForPayload($savedProduto_opcoes, $codigoForPayload, $store, $savedFuel);
            if (empty($produtoOpcoesPayload)) {
                $produtoOpcoesPayload = null;
            }
            if (is_array($produtosCentrosProdPayload)) {
                $produtosCentrosProdPayload = $this->normalizeNestedPayloadItemsForStore($produtosCentrosProdPayload, $store, $codigoForPayload);
            }
            if (is_array($produtoOpcoesPayload)) {
                $produtoOpcoesPayload = $this->normalizeNestedPayloadItemsForStore($produtoOpcoesPayload, $store, $codigoForPayload);
            }
            if (is_array($produtosPropriedadesPayload)) {
                $produtosPropriedadesPayload = $this->normalizeNestedPayloadItemsForStore($produtosPropriedadesPayload, $store, $codigoForPayload);
            }
            if (is_array($produtosCodigosBarrasPayload)) {
                $produtosCodigosBarrasPayload = $this->normalizeNestedPayloadItemsForStore($produtosCodigosBarrasPayload, $store, $codigoForPayload);
            }

            // ZSBMS constraint: caracteristicas and produtos_propriedades are mutually exclusive.
            if (!empty($caracteristicasPayload) && !empty($produtosPropriedadesPayload)) {
                $produtosPropriedadesPayload = null;
            }

            // Prepare product details for API call
            $productDetails = [
                'loja' => $store,
                'codigo' => $codigoForPayload,
                'descricao' => $product->label,
                'descricaocurta' => !empty($savedDescricaoCurta) ? $savedDescricaoCurta : '',
                'codigopp' => $codigoppValue,
                'codbarras' => !empty($savedCodbarras) ? $savedCodbarras : '',
                'familia' => !empty($savedFamilia) ? $savedFamilia : $conf->global->ZS_DEFAULT_PRODUCT_CATEGORY,
                'subfam' => !empty($savedSubFam) ? $savedSubFam : 0,
                'categoria' => !empty($savedCategoria) ? $savedCategoria : $conf->global->ZS_DEFAULT_PRODUCT_CATEGORY,
                'subcategoria' => !empty($savedSubCategoria) ? $savedSubCategoria : 0,
                'unidade' => !empty($savedUnidade) ? $savedUnidade : $conf->global->ZS_DEFAULT_PRODUCT_UNIT,
                'uncompra' => !empty($savedUncompra) ? $savedUncompra : $conf->global->ZS_DEFAULT_PRODUCT_UNIT,
                'uninventario' => !empty($savedUninventario) ? $savedUninventario : $conf->global->ZS_DEFAULT_PRODUCT_UNIT,
                'datacriacao' => date('Y-m-d'), // Consider setting an appropriate date
                'vendersemstock' => !empty($savedVenderSemStock) ? $savedVenderSemStock : 1,
                'precocompra' => number_format($costPrice, 2, '.', ''),
                'retalho' => $retalhoValue,
                'composto' => $savedComposto,
                'compra' => $savedCompra,
                'fundo' => $savedBackgroundColor,
                'letra' => $savedFontColor,
                'armazem' => $savedArmazem,
                'autoquebra' => $savedAutoquebra,
                'balanca' => $savedBalanca,
                'consumominimo' => $savedConsumoMinimo,
                'cozinha' => $savedCozinha,
                'dosedesc' => $savedDoseDesc,
                'excluirdescontos' => $savedExcluirDescontos,
                'grupo' => $savedGrupo,
                'isencao' => $savedIsencao,
                'iva2' => $savedIva2,
                'ivacompra' => $savedIvaCompra,
                'ivarevenda' => $savedIvaRevenda,
                'listseparado' => $savedListSeparado,
                'margembruta' => $savedMargemBruta,
                'maxopcoes' => $savedMaxOpcoes,
                'meiadose' => $savedMeiaDose,
                'meiadosedesc' => $savedMeiaDoseDesc,
                'obs' => $savedObs,
                'ordem' => $savedOrdem,
                'ordemlocal' => $savedOrdemLocal,
                'ordemtop' => $savedOrdemTop,
                'percentagemretencao' => $savedPercentagemRetencao,
                'precomeia' => $savedPrecoMeia,
                'precominimo' => $savedPrecoMinimo,
                'precorevenda' => $savedPrecoRevenda,
                'prepagamento' => $savedPrePagamento,
                'qtdmeia' => $savedQtdMeia,
                'referencia' => $savedReferencia,
                'retencao' => $savedRetencao,
                'revenda' => $savedRevenda,
                'stocks' => $savedStocks,
                'tara' => $savedTara,
                'tempoprep' => $savedTempoPrep,
                'tiposaft' => $savedTipoSaft,
                'topo' => $savedTopo,
                'transferivel' => $savedTransferivel,
                'ultprecovenda' => $savedUltPrecoVenda,
                'niveismenu' => $this->normalizeNiveismenuForZsbmsPayload($savedNiveismenu, $product->ref),
            ];
            if ($produtosCentrosProdPayload !== null) {
                $productDetails['produtoscentrosprod'] = $produtosCentrosProdPayload;
            }
            if ($arrayPrecoMeiaPayload !== null) {
                $productDetails['array_preco_meia'] = $arrayPrecoMeiaPayload;
            }
            if ($caracteristicasPayload !== null) {
                $productDetails['caracteristicas'] = $caracteristicasPayload;
            }
            if ($complementaresPayload !== null) {
                $productDetails['complementares'] = $complementaresPayload;
            }
            if ($produtoOpcoesPayload !== null) {
                $productDetails['produto_opcoes'] = $produtoOpcoesPayload;
            }
            if ($produtosPropriedadesPayload !== null) {
                $productDetails['produtos_propriedades'] = $produtosPropriedadesPayload;
            }
            if ($savedProdutosCodigosBarrasWasNull) {
                $productDetails['produtos_codigosbarras'] = null;
            } elseif ($produtosCodigosBarrasPayload !== null) {
                $productDetails['produtos_codigosbarras'] = $produtosCodigosBarrasPayload;
            }
            if ($produtosTaxasPayload !== null) {
                $productDetails['produtos_taxas'] = $produtosTaxasPayload;
            }
            if ($produtosTaxasBrPayload !== null) {
                $productDetails['produtos_taxas_br'] = $produtosTaxasBrPayload;
            }

            // ALWAYS USE PRICES FROM llx_dolizsynch_zsproduct TABLE
            // This table is the source of truth for what should be sent to ZsBMS
            if ($multiPricesEnabled) {
                // Level 1 price handling - always use ZS table values
                $productDetails['precovenda'] = number_format($obj->precovenda, 2, '.', '');
                $productDetails['pvp1siva'] = number_format($obj->pvp1siva, 2, '.', '');

                //$productDetails['iva'] = $product->multiprices_tva_tx['1'];
                $productDetails['iva'] = number_format($product->tva_tx, 0, '.', '');

                // Loop through other price levels - always use ZS table values
                foreach ($product->multiprices as $level => $price) {
                    if ($level == 1) continue; // Already handled above

                    // Always send values from ZS table
                    $pvpField = 'pvp' . $level;
                    $pvpsivaField = 'pvp' . $level . 'siva';

                    if (isset($obj->$pvpField) && isset($obj->$pvpsivaField)) {
                        $productDetails[$pvpField] = number_format($obj->$pvpField, 2, '.', '');
                        $productDetails[$pvpsivaField] = number_format($obj->$pvpsivaField, 2, '.', '');
                    }
                }
            } else {
                // Single price mode - always use ZS table values
                $productDetails['precovenda'] = number_format($obj->precovenda, 2, '.', '');
                $productDetails['pvp1siva'] = number_format($obj->pvp1siva, 2, '.', '');
                $productDetails['iva'] = number_format($product->tva_tx, 0, '.', '');
            }

            $remoteProduct = $this->fetchRemoteProductForUpdate($codigoForPayload, $store);
            // An explicit local checkbox wins. Untouched products retain this store's remote option.
            $digitalMenuValue = $obj->ementadigital ?? self::getDigitalMenuValue(
                $remoteProduct['produto_opcoes'] ?? ($productDetails['produto_opcoes'] ?? array()),
                $remoteProduct['ementadigital'] ?? null
            );
            $optionsForUpdate = array_merge(
                $this->normalizeProdutoOpcoesForPayload($remoteProduct['produto_opcoes'] ?? array(), $codigoForPayload, $store),
                $productDetails['produto_opcoes'] ?? array()
            );
            if ($optionsForUpdate || $digitalMenuValue !== null) {
                $productDetails['produto_opcoes'] = $this->normalizeProdutoOpcoesForPayload(
                    $optionsForUpdate, $codigoForPayload, $store, null, $digitalMenuValue
                );
            }
            if (is_array($remoteProduct)) {
                $productDetails = $this->applyRemoteProductUpdateConstraints(
                    $productDetails,
                    $remoteProduct,
                    $overrideVariablePrices
                );
            } else {
                $productDetails = $this->removeZsbmsResponseMetadata($productDetails);
            }

            try {
                $productDetails = (new ZSStorePrices($this->db))->apply($product, $store, $productDetails);
            } catch (Throwable $e) {
                $this->error = $e->getMessage();
                return false;
            }

            $retriedWithRemoteDescricao = false;
            while (true) {
                $productData = [
                    'product' => [$productDetails]
                ];

                // Execute the API request to save/update the product
                $response = $this->zoneSoftApi->executeUpdateProducts('saveInstances', $productData, $store);

                // Handle the response from the API
                if ($response && isset($response['Response'])) {
                    $statusCode = (int) ($response['Response']['StatusCode'] ?? 0);
                    $statusMessage = (string) ($response['Response']['StatusMessage'] ?? '');
                    $errorMessage = trim((string) ($response['Response']['ErrorMessage'] ?? ''));
                    $contentErrorSummary = $this->extractApiErrorSummaryFromContent($response['Response']['Content'] ?? null);
                    $instanceErrors = $this->extractInstanceErrorsFromApiResponse($response, 'product');
                    $instanceErrorSummary = $this->buildInstanceErrorsSummary($instanceErrors);

                    if (
                        $statusCode === 422
                        && !$retriedWithRemoteDescricao
                        && is_array($remoteProduct)
                        && isset($remoteProduct['descricao'])
                        && trim((string) $remoteProduct['descricao']) !== ''
                        && (string) $productDetails['descricao'] !== (string) $remoteProduct['descricao']
                        && (
                            $this->hasImmutableProductDescricaoError($instanceErrors)
                            || $this->hasImmutableProductDescricaoSignature($statusMessage, $errorMessage, $contentErrorSummary)
                        )
                    ) {
                        $productDetails['descricao'] = $remoteProduct['descricao'];
                        $retriedWithRemoteDescricao = true;
                        dol_syslog(
                            "ZSProductSync::updateZsbmsProduct: Product '{$product->ref}' store '{$store}' rejected descricao update; retrying saveInstances with the current remote descricao.",
                            LOG_WARNING
                        );
                        continue;
                    }

                    if ($statusCode == 201) {
                        // Check if 'product' is not null
                        dol_syslog("ZSProductSync::updateZsbmsProduct: Product updated successfully in ZoneSoft.", LOG_INFO);
                        $successMessage = "Product '{$product->ref}' updated in store '{$store}'.";
                        setEventMessages($successMessage, null, 'mesgs');
                    } else {
                        // Handle other StatusCodes as errors
                        $effectiveError = '';
                        if ($instanceErrorSummary !== '') {
                            $effectiveError = $instanceErrorSummary;
                        } elseif ($errorMessage !== '') {
                            $effectiveError = $errorMessage;
                        } elseif ($contentErrorSummary !== '') {
                            $effectiveError = $contentErrorSummary;
                        } elseif (trim($statusMessage) !== '') {
                            $effectiveError = trim($statusMessage);
                        } else {
                            $effectiveError = 'Unknown error';
                        }

                        $this->error = "Failed to update product '{$product->ref}'. Status code: {$statusCode}. Error: {$effectiveError}";
                        $updateErrors[] = $this->error;
                        dol_syslog("ZSProductSync::updateZsbmsProduct: " . $this->error, LOG_ERR);
                        dol_syslog(
                            "ZSProductSync::updateZsbmsProduct: Payload/response summary for failed update | payload="
                            . $this->summarizeValueForLog($productData, 2048)
                            . " | response="
                            . $this->summarizeValueForLog($response, 2048),
                            LOG_ERR
                        );
                        $this->persistInstanceErrorsToErrorLog(
                            'product_sync',
                            'ZSBMS product synchronization failed',
                            $instanceErrors,
                            [
                                'product_id' => (int) ($product->id ?? 0),
                                'product_ref' => (string) ($product->ref ?? ''),
                                'store' => (int) $store,
                                'status_code' => $statusCode,
                                'status_message' => (string) $statusMessage,
                            ]
                        );
                        $updateSuccess = false;
                    }
                } else {
                    // Invalid or empty response
                    $this->error = "Invalid response received from ZoneSoft API for product '{$product->ref}'.";
                    $updateErrors[] = $this->error;
                    dol_syslog("ZSProductSync::updateZsbmsProduct: " . $this->error, LOG_ERR);
                    $updateSuccess = false;
                }

                break;
            }
        }

        if ($updateSuccess) {
            $this->error = '';
        } elseif (!empty($updateErrors)) {
            $this->error = implode(' | ', array_values(array_unique($updateErrors)));
        }

        return $updateSuccess;
    }

    /**
     * Updates the llx_dolizsynch_zsproduct table with current Dolibarr prices
     * This is called when prices are changed in Dolibarr to keep the ZS table in sync
     *
     * @param Product $product The Dolibarr product object
     * @param bool|array<int, int> $overrideVariablePrices True for all levels, or the explicit levels allowed to replace -1
     * @return bool True on success, false on failure
     */
    private function updateZsTablePricesFromDolibarr($product, $overrideVariablePrices = false)
    {
        global $conf, $db, $user;


        if (!$this->ensureZsProductShadowRow($product)) {
            dol_syslog("ZSProductSync::updateZsTablePricesFromDolibarr: Unable to ensure shadow row for product ID {$product->id}", LOG_ERR);
            return false;
        }

        // First, fetch current ZS table values to check for variable prices
        $sql = "SELECT * FROM " . MAIN_DB_PREFIX . "dolizsynch_zsproduct WHERE fk_product = " . (int)$product->id . " ORDER BY rowid DESC LIMIT 1";
        $resql = $db->query($sql);
        $currentZsData = null;
        if ($resql) {
            $currentZsData = $db->fetch_object($resql);
        }

        // Check if multiprices are enabled
        $multiPricesEnabled = !empty($conf->global->PRODUIT_MULTIPRICES);

        // Build UPDATE query for prices
        $updateFields = [];

        if ($multiPricesEnabled) {
            // Get the multiprices limit
            $produitMultipricesLimit = !empty($conf->global->PRODUIT_MULTIPRICES_LIMIT) ? $conf->global->PRODUIT_MULTIPRICES_LIMIT : 5;

            // Update all price levels
            for ($i = 1; $i <= $produitMultipricesLimit; $i++) {
                // Set field names based on level
                if ($i == 1) {
                    $fieldTTC = 'precovenda';
                    $fieldHT = 'pvp1siva';
                } else {
                    $fieldTTC = 'pvp' . $i;
                    $fieldHT = 'pvp' . $i . 'siva';
                }

                // Check if this level is currently a variable price in ZS table
                $isCurrentlyVariable = false;
                if ($currentZsData && isset($currentZsData->$fieldTTC) && isset($currentZsData->$fieldHT)) {
                    $isCurrentlyVariable = ($currentZsData->$fieldTTC == -1 && $currentZsData->$fieldHT == -1);
                }

                if ($isCurrentlyVariable && !$this->shouldOverrideVariablePriceLevel($overrideVariablePrices, $i)) {
                    // This is a variable price - preserve the -1 values, don't overwrite with Dolibarr prices
                    // Don't add to updateFields - keep existing -1 values
                    continue;
                }
                if ($isCurrentlyVariable && $this->shouldOverrideVariablePriceLevel($overrideVariablePrices, $i)) {
                    dol_syslog("ZSProductSync::updateZsTablePricesFromDolibarr: Level $i is variable price, applying explicit Dolibarr price override", LOG_INFO);
                }

                // Not a variable price - get latest prices directly from database
                // Query the llx_product_price table for the most recent price at this level
                $sqlPrice = "SELECT price, price_ttc FROM " . MAIN_DB_PREFIX . "product_price";
                $sqlPrice .= " WHERE fk_product = " . (int)$product->id;
                $sqlPrice .= " AND entity IN (" . getEntity('productprice') . ")";
                $sqlPrice .= " AND price_level = " . (int)$i;
                $sqlPrice .= " ORDER BY date_price DESC, rowid DESC LIMIT 1";

                $resqlPrice = $db->query($sqlPrice);
                if ($resqlPrice && $db->num_rows($resqlPrice) > 0) {
                    $objPrice = $db->fetch_object($resqlPrice);
                    $priceHT = (float)$objPrice->price;
                    $priceTTC = (float)$objPrice->price_ttc;
                } else {
                    // Fallback to product object if query fails
                    if ($i == 1) {
                        $priceHT = isset($product->multiprices[$i]) ? (float)$product->multiprices[$i] : (float)$product->price;
                        $priceTTC = isset($product->multiprices_ttc[$i]) ? (float)$product->multiprices_ttc[$i] : (float)$product->price_ttc;
                    } else {
                        $priceHT = isset($product->multiprices[$i]) ? (float)$product->multiprices[$i] : 0;
                        $priceTTC = isset($product->multiprices_ttc[$i]) ? (float)$product->multiprices_ttc[$i] : 0;
                    }
                }

                // Add to update fields
                $updateFields[] = "$fieldTTC = " . $db->escape($priceTTC);
                $updateFields[] = "$fieldHT = " . $db->escape($priceHT);

            }
        } else {
            // Single price mode
            // Check if level 1 is variable
            $isCurrentlyVariable = false;
            if ($currentZsData && isset($currentZsData->precovenda) && isset($currentZsData->pvp1siva)) {
                $isCurrentlyVariable = ($currentZsData->precovenda == -1 && $currentZsData->pvp1siva == -1);
            }

            if (!$isCurrentlyVariable || $this->shouldOverrideVariablePriceLevel($overrideVariablePrices, 1)) {
                $priceHT = (float)$product->price;
                $priceTTC = (float)$product->price_ttc;

                $updateFields[] = "precovenda = " . $db->escape($priceTTC);
                $updateFields[] = "pvp1siva = " . $db->escape($priceHT);

            } else {
            }
        }

        // Also update VAT rate
        $updateFields[] = "iva = " . $db->escape($product->tva_tx);

        if (empty($updateFields)) {
            dol_syslog("ZSProductSync::updateZsTablePricesFromDolibarr: No price fields to update", LOG_WARNING);
            return false;
        }

        // Execute UPDATE
        $userId = (is_object($user) && !empty($user->id)) ? (int) $user->id : 0;
        $updateFields[] = "fk_user_modif = " . $userId;
        $sql = "UPDATE " . MAIN_DB_PREFIX . "dolizsynch_zsproduct SET ";
        $sql .= implode(", ", $updateFields);
        $sql .= " WHERE fk_product = " . (int)$product->id;


        $resql = $db->query($sql);
        if (!$resql) {
            dol_syslog("ZSProductSync::updateZsTablePricesFromDolibarr: Error updating prices: " . $db->lasterror(), LOG_ERR);
            return false;
        }

        $affectedRows = $db->affected_rows($resql);
        dol_syslog("ZSProductSync::updateZsTablePricesFromDolibarr: Successfully updated prices for product ID {$product->id}. Affected rows: $affectedRows", LOG_INFO);

        return true;
    }

    /**
     * Ensure shadow row exists in llx*_dolizsynch_zsproduct for the product.
     * This guarantees scheduled updates can always mirror prices in local ZS table.
     *
     * @param Product $product
     * @return bool
     */
    private function ensureZsProductShadowRow($product)
    {
        global $conf, $user;

        $productId = (int) $product->id;
        if ($productId <= 0) {
            $this->error = "Invalid product id";
            return false;
        }

        $sqlCheck = "SELECT rowid FROM " . MAIN_DB_PREFIX . "dolizsynch_zsproduct WHERE fk_product = " . $productId . " LIMIT 1";
        $resqlCheck = $this->db->query($sqlCheck);
        if ($resqlCheck) {
            if ($this->db->num_rows($resqlCheck) > 0) {
                $this->db->free($resqlCheck);
                return true;
            }
            $this->db->free($resqlCheck);
        } else {
            $this->error = $this->db->lasterror();
            dol_syslog(__METHOD__ . " - Error checking shadow row for product {$productId}: " . $this->error, LOG_ERR);
            return false;
        }

        $store = (!empty($conf->global->ZS_API_STORE) && ctype_digit((string) $conf->global->ZS_API_STORE))
            ? (int) $conf->global->ZS_API_STORE
            : 1;
        if ($store <= 0) {
            $store = 1;
        }
        $retalhoByStore = json_encode(array((string) $store => '1'));
        if ($retalhoByStore === false) {
            $retalhoByStore = '{"1":"1"}';
        }

        $codigoValue = null;
        if (!empty($product->ref) && ctype_digit((string) $product->ref)) {
            $codigoValue = (int) $product->ref;
        }

        $nowSql = $this->db->idate(dol_now());
        $userId = (is_object($user) && !empty($user->id)) ? (int) $user->id : 0;
        $entity = !empty($conf->entity) ? (int) $conf->entity : 1;

        $sqlInsert = "INSERT INTO " . MAIN_DB_PREFIX . "dolizsynch_zsproduct (";
        $sqlInsert .= "entity, fk_product, loja_zs, codigo, descricao, datacriacao, date_creation, retalho_by_store, product_company, fk_user_creat, fk_user_modif";
        $sqlInsert .= ") VALUES (";
        $sqlInsert .= $entity . ", ";
        $sqlInsert .= $productId . ", ";
        $sqlInsert .= $store . ", ";
        $sqlInsert .= ($codigoValue === null ? "NULL" : $codigoValue) . ", ";
        $sqlInsert .= "'" . $this->db->escape((string) $product->label) . "', ";
        $sqlInsert .= "'" . $this->db->escape(date('Y-m-d')) . "', ";
        $sqlInsert .= "'" . $nowSql . "', ";
        $sqlInsert .= "'" . $this->db->escape($retalhoByStore) . "', ";
        $sqlInsert .= "'" . $this->db->escape((string) $store) . "', ";
        $sqlInsert .= $userId . ", ";
        $sqlInsert .= $userId;
        $sqlInsert .= ")";
        $sqlInsert .= " ON DUPLICATE KEY UPDATE";
        $sqlInsert .= " loja_zs = " . $store . ",";
        $sqlInsert .= " descricao = '" . $this->db->escape((string) $product->label) . "',";
        $sqlInsert .= " retalho_by_store = COALESCE(NULLIF(retalho_by_store, ''), '" . $this->db->escape($retalhoByStore) . "'),";
        $sqlInsert .= " product_company = COALESCE(NULLIF(product_company, ''), '" . $this->db->escape((string) $store) . "'),";
        $sqlInsert .= " fk_user_modif = " . $userId;

        if (!$this->db->query($sqlInsert)) {
            $this->error = $this->db->lasterror();
            dol_syslog(__METHOD__ . " - Error creating shadow row for product {$productId}: " . $this->error, LOG_ERR);
            return false;
        }

        dol_syslog(__METHOD__ . " - Shadow row ensured for product {$productId}", LOG_INFO);
        return true;
    }

    /**
     * Create a new product in ZoneSoft or update if it already exists.
     *
     * @param Product $objectProduct The product object to create/update in ZoneSoft.
     * @param string $store The company identifier.
     * @return bool True if the operation is successful, false otherwise.
     */
    public function createZoneSoftProduct($objectProduct, $store)
    {
        global $user, $conf;


        // Get product from ZoneSoft
        $product = $this->getZoneSoftProductById($objectProduct->ref);

        if ($product && !empty($product->product->codigo)) {
            $productCode = $product->product->codigo;

            // If the product exists locally, update it
            if ($this->findZSProductById($productCode)) {
                $updateSuccess = $this->updateDolibarrProductFromZoneSoft($product->product);
                if ($updateSuccess) {
                    return true; // Successfully updated
                } else {
                    dol_syslog("ZSProductSync::createZoneSoftProduct: Failed to update product {$objectProduct->ref} in llx_dolizsynch_zsproduct table", LOG_ERR);
                    return false; // Update failed
                }
            } else {
                // If the product doesn't exist locally, insert it
                $insertSuccess = $this->insertProductToLocalTable($product);
                if ($insertSuccess) {
                    return true; // Successfully inserted
                } else {
                    dol_syslog("ZSProductSync::createZoneSoftProduct: Failed to insert product {$objectProduct->ref} in llx_dolizsynch_zsproduct table", LOG_ERR);
                    return false; // Insert failed
                }
            }
        } else {
            // Product doesn't exist in ZSBMS, create it
            dol_syslog("ZSProductSync::createZoneSoftProduct: Product {$objectProduct->ref} not found in ZSBMS. Creating new product.", LOG_INFO);

            // Fetch retalho_by_store and tempoprep values from database for this product
            $retalhoValue = '{"' . $store . '":"1"}'; // Default value
            $tempoprepValue = '00:00:00';
            $sql = "SELECT retalho_by_store, tempoprep FROM " . MAIN_DB_PREFIX . "dolizsynch_zsproduct WHERE fk_product = " . (int) $objectProduct->id;
            $resql = $this->db->query($sql);
            if ($resql) {
                $obj = $this->db->fetch_object($resql);
                if ($obj && !empty($obj->retalho_by_store)) {
                    $retalhoValue = $obj->retalho_by_store;
                }
                if ($obj && !empty($obj->tempoprep)) {
                    $tempoprepValue = trim((string) $obj->tempoprep);
                }
            }

            // Prepare product data for creation
            if (
                method_exists($objectProduct, 'fetch_optionals')
                && (
                    empty($objectProduct->array_options)
                    || (!array_key_exists('options_' . self::KREA_ALIAS_EXTRAFIELD, $objectProduct->array_options)
                        && !array_key_exists('options_alias', $objectProduct->array_options))
                )
            ) {
                $objectProduct->fetch_optionals((int) $objectProduct->id);
            }
            $mirrorShortDesc = '';
            if (!empty($objectProduct->array_options['options_' . self::KREA_ALIAS_EXTRAFIELD])) {
                $mirrorShortDesc = (string) $objectProduct->array_options['options_' . self::KREA_ALIAS_EXTRAFIELD];
            } elseif (!empty($objectProduct->array_options['options_alias'])) {
                $mirrorShortDesc = (string) $objectProduct->array_options['options_alias'];
            }

            $productDetails = [
                'codigo' => $objectProduct->ref,
                'descricao' => $objectProduct->label,
                'descricaocurta' => $mirrorShortDesc,
                'iva' => $objectProduct->tva_tx,
                'precovenda' => $objectProduct->price_ttc ?: $objectProduct->price,
                'precocompra' => $objectProduct->cost_price ?: 0,
                'categoria' => $conf->global->ZS_DEFAULT_PRODUCT_CATEGORY ?? 1,
                'familia' => $conf->global->ZS_DEFAULT_PRODUCT_FAMILY ?? 1,
                'unidade' => $conf->global->ZS_DEFAULT_PRODUCT_UNIT ?? 'UN',
                'uncompra' => $conf->global->ZS_DEFAULT_PRODUCT_UNIT ?? 'UN',
                'uninventario' => $conf->global->ZS_DEFAULT_PRODUCT_UNIT ?? 'UN',
                'datacriacao' => date('Y-m-d'),
                'vendersemstock' => 1,
                'pvp1siva' => $objectProduct->price,
                'tiposaft' => 'P',
                'retalho' => $retalhoValue,
                'tempoprep' => $tempoprepValue
            ];

            // Create payload using the existing preparePayload method
            $payload = $this->preparePayload($productDetails, $store);

            // Set the API interface to 'products'
            $this->zoneSoftApi->setInterface('products');

            try {
                // Execute the API request to create the product
                // Use the same method as updateZsbmsProduct (which works)
                $response = $this->zoneSoftApi->executeUpdateProducts('saveInstances', $payload, $store);

                // Log the raw response
                $responseJson = json_encode($response, JSON_PRETTY_PRINT);

                // Handle the response from the API
                if ($response && isset($response['Response'])) {
                    $statusCode = $response['Response']['StatusCode'];

                    if ($statusCode == 201) {
                        dol_syslog("ZSProductSync::createZoneSoftProduct: Product {$objectProduct->ref} created successfully in ZSBMS.", LOG_INFO);

                        // Now fetch the created product and insert it into local table
                        $createdProduct = $this->getZoneSoftProductById($objectProduct->ref);
                        if ($createdProduct && !empty($createdProduct->product->codigo)) {
                            $insertSuccess = $this->insertProductToLocalTable($createdProduct);
                            if ($insertSuccess) {
                                dol_syslog("ZSProductSync::createZoneSoftProduct: Created product {$objectProduct->ref} inserted in local database.", LOG_INFO);
                                return true;
                            } else {
                                dol_syslog("ZSProductSync::createZoneSoftProduct: Failed to insert created product {$objectProduct->ref} in local database.", LOG_ERR);
                                return false;
                            }
                        } else {
                            dol_syslog("ZSProductSync::createZoneSoftProduct: Could not fetch created product {$objectProduct->ref} from ZSBMS.", LOG_ERR);
                            return false;
                        }
                    } else {
                        $errorMessage = isset($response['Response']['ErrorMessage']) ? $response['Response']['ErrorMessage'] : 'Unknown error';
                        dol_syslog("ZSProductSync::createZoneSoftProduct: Failed to create product {$objectProduct->ref} in ZSBMS. Status code: {$statusCode}. Error: {$errorMessage}", LOG_ERR);
                        return false;
                    }
                } else {
                    dol_syslog("ZSProductSync::createZoneSoftProduct: Invalid response received from ZSBMS API for product {$objectProduct->ref}.", LOG_ERR);
                    return false;
                }
            } catch (Exception $e) {
                dol_syslog("ZSProductSync::createZoneSoftProduct: Exception occurred while creating product {$objectProduct->ref}: " . $e->getMessage(), LOG_ERR);
                return false;
            }
        }
    }

    /**
     * Create product in ZoneSoft with interactive options for numbering
     *
     * @param object $objectProduct The Dolibarr product object
     * @param string $store The company identifier
     * @param bool $useAutomaticNumbering Whether to use automatic numbering from ZSBMS
     * @return bool Success status
     */
    public function createZoneSoftProductInteractive($objectProduct, $store, $useAutomaticNumbering = false)
    {
        global $user, $conf;

        // Add validation to ensure product has required data
        if (empty($objectProduct->label)) {
            dol_syslog("ZSProductSync::createZoneSoftProductInteractive: Product label is empty!", LOG_ERR);
            $this->error = "Product label is required for ZSBMS creation";
            return false;
        }

        // Fetch retalho_by_store and tempoprep values from database for this product and company
        $retalhoCode = 1; // Default value
        $tempoprepValue = '00:00:00';
        $sql = "SELECT retalho_by_store, tempoprep FROM " . MAIN_DB_PREFIX . "dolizsynch_zsproduct WHERE fk_product = " . (int) $objectProduct->id;
        $resql = $this->db->query($sql);
        if ($resql) {
            $obj = $this->db->fetch_object($resql);
            if ($obj && !empty($obj->retalho_by_store)) {
                $retalhoArray = json_decode($obj->retalho_by_store, true);
                if (is_array($retalhoArray) && isset($retalhoArray[(string)$store])) {
                    $retalhoCode = (int)$retalhoArray[(string)$store];
                }
            }
            if ($obj && !empty($obj->tempoprep)) {
                $tempoprepValue = trim((string) $obj->tempoprep);
            }
        }

        if (!preg_match('/^(\d{1,2}):(\d{1,2})(?::(\d{1,2}))?$/', $tempoprepValue, $matchesTempo)) {
            $tempoprepValue = '00:00:00';
        } else {
            $h = (int) $matchesTempo[1];
            $m = (int) $matchesTempo[2];
            $s = isset($matchesTempo[3]) ? (int) $matchesTempo[3] : 0;
            if ($h > 23 || $m > 59 || $s > 59) {
                $tempoprepValue = '00:00:00';
            } else {
                $tempoprepValue = sprintf('%02d:%02d:%02d', $h, $m, $s);
            }
        }

        // Get product code - handle automatic vs manual numbering
        $productCode = null;
        if (!$useAutomaticNumbering) {
            // Manual numbering - use existing product reference
            $productCode = $objectProduct->ref;
            if (empty($productCode) && !empty($_SESSION['zsbms_create_product_ref'])) {
                $productCode = $_SESSION['zsbms_create_product_ref'];
            }
            if (empty($productCode)) {
                $productCode = 'TEMP_' . time();
            }
        }
        // For automatic numbering, leave $productCode as null - ZSBMS will generate it

        // CAMPOS OBRIGATÓRIOS confirmados pela ZoneSoft:
        // loja, codigo, descricao, iva, familia, unidade, datacriacao, vendersemstock
        $productDetails = [
            'loja' => (int)$store,
            'descricao' => substr($objectProduct->label ?: 'Product', 0, 50),
            'iva' => (float)($objectProduct->tva_tx ?: 0),
            'familia' => (int)($conf->global->ZS_DEFAULT_PRODUCT_FAMILY ?? 0),
            'unidade' => (int)($conf->global->ZS_DEFAULT_PRODUCT_UNIT ?? 0),
            'datacriacao' => date('Y-m-d'),
            'vendersemstock' => 1,
            'retalho' => $retalhoCode,  // Use the fetched retalho code for this company
            'tempoprep' => $tempoprepValue,
        ];

        // Handle codigo field for automatic vs manual numbering
        if ($useAutomaticNumbering) {
            // Get next available codigo from ZoneSoft API
            $nextCodigo = $this->getNextProductCodigo();
            if ($nextCodigo === false) {
                dol_syslog("ZSProductSync::createZoneSoftProductInteractive: Failed to get next codigo", LOG_ERR);
                $this->error = "Failed to get next product code from ZSBMS";
                return false;
            }
            $productDetails['codigo'] = $nextCodigo;
            dol_syslog("ZSProductSync::createZoneSoftProductInteractive: Using automatic codigo: {$nextCodigo}", LOG_INFO);
        } else {
            // Use provided codigo for manual numbering
            $productDetails['codigo'] = $productCode;
        }


        // Create payload - match the exact structure from working updateZsbmsProduct method
        // Working updateZsbmsProduct uses: {"product": [productDetails]}
        $payload = [
            'product' => [$productDetails]  // Array with single element, like updateZsbmsProduct
        ];

        // Log the payload for debugging (same as updateZsbmsProduct)
        $payloadView = json_encode($payload, JSON_PRETTY_PRINT);


        // Set the API interface to 'products'
        $this->zoneSoftApi->setInterface('products');

        try {
            // Validate payload before sending
            if (empty($payload['product']) || !is_array($payload['product'])) {
                dol_syslog("ZSProductSync::createZoneSoftProductInteractive: Invalid payload structure", LOG_ERR);
                $this->error = "Invalid payload structure for ZSBMS API";
                return false;
            }

            // Execute the API request to create the product
            // Use the same method as updateZsbmsProduct (which works)
            $response = $this->zoneSoftApi->executeUpdateProducts('saveInstances', $payload, $store);

            // Log the raw response
            $responseJson = json_encode($response, JSON_PRETTY_PRINT);

            // Handle the response from the API
            if ($response && isset($response['Response'])) {
                $statusCode = $response['Response']['StatusCode'];

                if ($statusCode == 201) {
                    dol_syslog("ZSProductSync::createZoneSoftProductInteractive: Product created successfully in ZSBMS.", LOG_INFO);

                    // If using automatic numbering, update the Dolibarr product ref with the codigo we obtained
                    if ($useAutomaticNumbering && isset($nextCodigo)) {
                        dol_syslog("ZSProductSync::createZoneSoftProductInteractive: Updating product ref from {$objectProduct->ref} to {$nextCodigo}", LOG_INFO);

                        // Update the product reference in Dolibarr
                        $oldRef = $objectProduct->ref;
                        $objectProduct->ref = (string)$nextCodigo;
                        $updateResult = $objectProduct->update($objectProduct->id, $user, 0);

                        if ($updateResult > 0) {
                            dol_syslog("ZSProductSync::createZoneSoftProductInteractive: Product ref updated successfully from {$oldRef} to {$nextCodigo}", LOG_INFO);
                        } else {
                            dol_syslog("ZSProductSync::createZoneSoftProductInteractive: Failed to update product ref to {$nextCodigo}. Error: " . $objectProduct->error, LOG_ERR);
                            // Revert to old ref if update failed
                            $objectProduct->ref = $oldRef;
                        }
                    }

                    // Create a product object to insert into local table using the payload data we sent
                    // Add missing fields that insertProductToLocalTable expects with proper default values
                    $productDataForLocal = $productDetails;
                    $productDataForLocal['composto'] = 0;  // Default value for composto
                    $productDataForLocal['tiposaft'] = 'P';  // Default value for tiposaft
                    $productDataForLocal['fundo'] = '#808080';  // Default background color
                    $productDataForLocal['letra'] = '#ffffff';  // Default font color
                    $productDataForLocal['subcategoria'] = 0;  // Default value for subcategoria
                    $productDataForLocal['subfam'] = 0;  // Default value for subfam
                    $productDataForLocal['grupo'] = 0;  // Default value for grupo
                    $productDataForLocal['armazem'] = 0;  // Default value for armazem
                    $productDataForLocal['topo'] = 0;  // Default value for topo
                    $productDataForLocal['cozinha'] = 0;  // Default value for cozinha
                    $productDataForLocal['balanca'] = 0;  // Default value for balanca
                    $productDataForLocal['stocks'] = 0;  // Default value for stocks
                    $productDataForLocal['meiadose'] = 0;  // Default value for meiadose
                    $productDataForLocal['qtdmeia'] = 0;  // Default value for qtdmeia
                    $productDataForLocal['ordemtop'] = 0;  // Default value for ordemtop
                    $productDataForLocal['ordem'] = 0;  // Default value for ordem
                    $productDataForLocal['ordemlocal'] = 0;  // Default value for ordemlocal
                    $productDataForLocal['listseparado'] = 0;  // Default value for listseparado
                    $productDataForLocal['maxopcoes'] = 0;  // Default value for maxopcoes
                    $productDataForLocal['prepagamento'] = 0;  // Default value for prepagamento
                    $productDataForLocal['transferivel'] = 0;  // Default value for transferivel
                    $productDataForLocal['uncompra'] = (int)($conf->global->ZS_DEFAULT_PRODUCT_UNIT ?? 0);  // Default value for uncompra
                    $productDataForLocal['uninventario'] = (int)($conf->global->ZS_DEFAULT_PRODUCT_UNIT ?? 0);  // Default value for uninventario
                    $productDataForLocal['revenda'] = 0;  // Default value for revenda
                    $productDataForLocal['retencao'] = 0;  // Default value for retencao
                    $productDataForLocal['excluirdescontos'] = 0;  // Default value for excluirdescontos
                    $productDataForLocal['restricted'] = 0;  // Default value for restricted
                    // Add price fields
                    $productDataForLocal['precocompra'] = 0;  // Default purchase price
                    $productDataForLocal['precovenda'] = 0;  // Default sale price
                    $productDataForLocal['ultprecovenda'] = 0;  // Default last sale price
                    // Add all pvp fields (retail prices)
                    $productDataForLocal['pvp1siva'] = 0;
                    $productDataForLocal['pvp2'] = 0;
                    $productDataForLocal['pvp2siva'] = 0;
                    $productDataForLocal['pvp3'] = 0;
                    $productDataForLocal['pvp3siva'] = 0;
                    $productDataForLocal['pvp4'] = 0;
                    $productDataForLocal['pvp4siva'] = 0;
                    $productDataForLocal['pvp5'] = 0;
                    $productDataForLocal['pvp5siva'] = 0;
                    $productDataForLocal['pvp6'] = 0;
                    $productDataForLocal['pvp6siva'] = 0;
                    $productDataForLocal['pvp7'] = 0;
                    $productDataForLocal['pvp7siva'] = 0;
                    $productDataForLocal['pvp8'] = 0;
                    $productDataForLocal['pvp8siva'] = 0;
                    $productDataForLocal['pvp9'] = 0;
                    $productDataForLocal['pvp9siva'] = 0;
                    $productDataForLocal['pvp10'] = 0;
                    $productDataForLocal['pvp10siva'] = 0;
                    // Add other missing fields
                    $productDataForLocal['categoria'] = (int)($conf->global->ZS_DEFAULT_PRODUCT_CATEGORY ?? 0);
                    $productDataForLocal['tempoprep'] = '00:00:00';  // Default prep time
                    $productDataForLocal['iva2'] = $productDataForLocal['iva'];  // Same as main IVA
                    $productDataForLocal['ivacompra'] = $productDataForLocal['iva'];  // Same as main IVA
                    $productDataForLocal['ivarevenda'] = $productDataForLocal['iva'];  // Same as main IVA
                    $productDataForLocal['compra'] = 0;  // Default
                    $productDataForLocal['consumominimo'] = 0;  // Default
                    $productDataForLocal['dosedesc'] = 0;  // Default
                    $productDataForLocal['meiadosedesc'] = '';  // Default
                    $productDataForLocal['autoquebra'] = 0;  // Default
                    $productDataForLocal['margembruta'] = 0;  // Default
                    $productDataForLocal['precomeia'] = 0;  // Default
                    $productDataForLocal['precominimo'] = 0;  // Default
                    $productDataForLocal['precorevenda'] = 0;  // Default
                    $productDataForLocal['percentagemretencao'] = 0;  // Default
                    $productDataForLocal['tara'] = 0;  // Default
                    $productDataForLocal['codbarras'] = '';  // Default
                    $productDataForLocal['codigopp'] = '';  // Default
                    $productDataForLocal['descricaocurta'] = '';  // Default
                    $productDataForLocal['obs'] = '';  // Default
                    $productDataForLocal['referencia'] = '';  // Default
                    $productDataForLocal['isencao'] = '';  // Default
                    // For local database, store retalho_by_store as JSON structure per store with string keys and values
                    $productDataForLocal['retalho_by_store'] = json_encode([(string) $store => (string) $retalhoCode]);
                    // Save the current store in product_company field
                    $productDataForLocal['product_company'] = (string) $store;
                    // Ensure local insert resolves the correct Dolibarr product id (do not rely on ref lookup)
                    $productDataForLocal['fk_product_override'] = (int) $objectProduct->id;

                    $createdProduct = (object) ['product' => (object) $productDataForLocal];
                    $insertSuccess = $this->insertProductToLocalTable($createdProduct);

                    if ($insertSuccess) {
                        dol_syslog("ZSProductSync::createZoneSoftProductInteractive: Created product inserted in local database.", LOG_INFO);
                    } else {
                        dol_syslog("ZSProductSync::createZoneSoftProductInteractive: Warning - Failed to insert created product in local database. Error: " . $this->db->lasterror(), LOG_WARNING);
                        dol_syslog("ZSProductSync::createZoneSoftProductInteractive: Product was created successfully in ZSBMS, continuing despite local sync issue.", LOG_INFO);
                        // Set a non-critical error message but still return success since ZSBMS creation succeeded
                        $this->error = "Product created in ZSBMS but local sync had issues: " . $this->db->lasterror();
                    }

                    // Return true since the main operation (ZSBMS creation) succeeded
                    return true;
                } else {
                    $errorMessage = isset($response['Response']['ErrorMessage']) ? $response['Response']['ErrorMessage'] : 'Unknown error';
                    dol_syslog("ZSProductSync::createZoneSoftProductInteractive: Failed to create product in ZSBMS. Status code: {$statusCode}. Error: {$errorMessage}", LOG_ERR);
                    return false;
                }
            } else {
                dol_syslog("ZSProductSync::createZoneSoftProductInteractive: Invalid response received from ZSBMS API.", LOG_ERR);
                return false;
            }
        } catch (Exception $e) {
            dol_syslog("ZSProductSync::createZoneSoftProductInteractive: Exception occurred while creating product: " . $e->getMessage(), LOG_ERR);
            return false;
        }
    }

    /**
     * Prepare the payload for creating a product in ZoneSoft.
     *
     * @param array $product The product array containing necessary details.
     * @param string $store The company identifier.
     * @return array The prepared payload.
     */
    public function preparePayload($product, $store)
    {
        global $conf, $db;

        $pvpArray = [
            "pvpmeia10siva" => $product['pvpmeia10siva'] ?? 0,
            "pvpmeia1siva"  => $product['pvpmeia1siva'] ?? 0,
            "pvpmeia2"       => $product['pvpmeia2'] ?? 0,
            "pvpmeia2siva"   => $product['pvpmeia2siva'] ?? 0,
            "pvpmeia3"       => $product['pvpmeia3'] ?? 0,
            "pvpmeia3siva"   => $product['pvpmeia3siva'] ?? 0,
            "pvpmeia4"       => $product['pvpmeia4'] ?? 0,
            "pvpmeia4siva"   => $product['pvpmeia4siva'] ?? 0,
            "pvpmeia5"       => $product['pvpmeia5'] ?? 0,
            "pvpmeia5siva"   => $product['pvpmeia5siva'] ?? 0,
            "pvpmeia6"       => $product['pvpmeia6'] ?? 0,
            "pvpmeia6siva"   => $product['pvpmeia6siva'] ?? 0,
            "pvpmeia7"       => $product['pvpmeia7'] ?? 0,
            "pvpmeia7siva"   => $product['pvpmeia7siva'] ?? 0,
            "pvpmeia8"       => $product['pvpmeia8'] ?? 0,
            "pvpmeia8siva"   => $product['pvpmeia8siva'] ?? 0,
            "pvpmeia9"       => $product['pvpmeia9'] ?? 0,
            "pvpmeia9siva"   => $product['pvpmeia9siva'] ?? 0,
            "pvp10"          => $product['pvp10'] ?? 0,
            "pvp10siva"      => $product['pvp10siva'] ?? 0,
        ];

        $array_preco_meia = $pvpArray;
        $produtoscentrosprod = isset($product['produtoscentrosprod']) ? $this->decodeJsonArrayField($product['produtoscentrosprod']) : array();
        $caracteristicas = isset($product['caracteristicas']) ? $this->decodeJsonArrayField($product['caracteristicas']) : array();
        $complementares = isset($product['complementares']) ? $this->decodeJsonArrayField($product['complementares']) : array();
        $niveismenu = isset($product['niveismenu']) ? $this->decodeJsonArrayField($product['niveismenu']) : array();
        $produto_opcoes = $this->normalizeProdutoOpcoesForPayload(
            $product['produto_opcoes'] ?? array(),
            $product['codigo'] ?? '',
            $store,
            $product['fuel'] ?? null,
            $product['ementadigital'] ?? null
        );
        $produtos_propriedades = isset($product['produtos_propriedades']) ? $this->decodeJsonArrayField($product['produtos_propriedades']) : array();
        $produtos_codigosbarras = isset($product['produtos_codigosbarras']) ? $this->decodeJsonArrayField($product['produtos_codigosbarras']) : array();
        $produtos_taxas = isset($product['produtos_taxas']) ? $this->decodeJsonArrayField($product['produtos_taxas']) : array();
        $produtos_taxas_br = isset($product['produtos_taxas_br']) ? $this->decodeJsonArrayField($product['produtos_taxas_br']) : array();

        $tempoprep = isset($product['tempoprep']) ? trim((string) $product['tempoprep']) : '00:00:00';
        if (!preg_match('/^(\d{1,2}):(\d{1,2})(?::(\d{1,2}))?$/', $tempoprep, $matches)) {
            $tempoprep = '00:00:00';
        } else {
            $hours = (int) $matches[1];
            $minutes = (int) $matches[2];
            $seconds = isset($matches[3]) ? (int) $matches[3] : 0;
            if ($hours > 23 || $minutes > 59 || $seconds > 59) {
                $tempoprep = '00:00:00';
            } else {
                $tempoprep = sprintf('%02d:%02d:%02d', $hours, $minutes, $seconds);
            }
        }

        // Extract retalho code for the specific company
        $retalhoCode = 1; // Default value
        if (isset($product['retalho_by_store']) && !empty($product['retalho_by_store'])) {
            $retalhoArray = $this->decodeJsonArrayField($product['retalho_by_store']);
            if (is_array($retalhoArray) && isset($retalhoArray[(string)$store])) {
                $retalhoCode = (int)$retalhoArray[(string)$store];
            }
        }

        // Prepare the product data for the API
        // Create base product data
        $productData = [
            "loja"                => $store,
            "descricao"           => $product['descricao'],
            "iva"                 => number_format($product['iva'], 0, '.', '') ? number_format($product['iva'], 0, '.', '') : number_format(23, 0, '.', ''),
            "familia"             => $conf->global->ZS_DEFAULT_PRODUCT_FAMILY ?? '1',
            "unidade"             => $conf->global->ZS_DEFAULT_PRODUCT_UNIT,
            "datacriacao"         => date('Y-m-d'),
            "vendersemstock"      => 1,
            "retalho"             => $retalhoCode,
            "precovenda"          => number_format($product['precovenda'], 2, '.', ''),
            "preco_meia"          => $array_preco_meia ?? '',
            "armazem"             => $db->escape($product['armazem'] ?? '1'),
            "autoquebra"          => $db->escape($product['autoquebra'] ?? '0'),
            "balanca"             => $db->escape($product['balanca'] ?? '0'),
            "categoria"           => $product['categoria'] ?? 1,
            "codbarras"           => $product['codbarras'] ?? '',
            "composto"            => $product['composto'] ?? 0,
            "compra"              => $product['compra'] ?? 0,
            "consumominimo"       => $db->escape($product['consumominimo'] ?? '0'),
            "cozinha"             => $db->escape($product['cozinha'] ?? '0'),
            "descricaocurta"      => $db->escape($product['descricaocurta'] ?? ''),
            "dosedesc"            => $db->escape($product['dosedesc'] ?? ''),
            "entity"              => $conf->entity ?? 1,
            "excluirdescontos"    => $db->escape($product['excluirdescontos'] ?? '0'),
            "fundo"               => $product['fundo'] ?? 0,
            "grupo"               => $db->escape($product['grupo'] ?? 0),
            "isencao"             => $db->escape($product['isencao'] ?? ''),
            "iva2"                => $product['iva2'] ?? 0,
            "ivacompra"           => $product['ivacompra'] ?? 0,
            "ivarevenda"          => $product['ivarevenda'] ?? 0,
            "letra"               => $product['letra'] ?? '',
            "listseparado"        => $db->escape($product['listseparado'] ?? '0'),
            "margembruta"         => $db->escape($product['margembruta'] ?? '0'),
            "maxopcoes"           => $db->escape($product['maxopcoes'] ?? '0'),
            "meiadose"            => $db->escape($product['meiadose'] ?? '0'),
            "meiadosedesc"        => $db->escape($product['meiadosedesc'] ?? ''),
            "obs"                 => $db->escape($product['obs'] ?? ''),
            "ordem"               => $db->escape($product['ordem'] ?? '0'),
            "ordemlocal"          => $db->escape($product['ordemlocal'] ?? '0'),
            "ordemtop"            => $db->escape($product['ordemtop'] ?? '0'),
            "percentagemretencao" => $product['percentagemretencao'] ?? '0',
            "precomeia"           => $db->escape($product['precomeia'] ?? '0'),
            "precocompra"         => $product['precocompra'] ?? 0,
            "precominimo"         => $db->escape($product['precominimo'] ?? '0'),
            "precorevenda"        => $db->escape($product['precorevenda'] ?? '0'),
            "prepagamento"        => $db->escape($product['prepagamento'] ?? '0'),
            "tempoprep"           => $tempoprep,
            "produtoscentrosprod" => $produtoscentrosprod ?? array(),
            "caracteristicas"     => $caracteristicas ?? array(),
            "complementares"      => $complementares ?? array(),
            "niveismenu"          => $niveismenu ?? array(),
            "produto_opcoes"      => $produto_opcoes ?? array(),
            "produtos_propriedades" => $produtos_propriedades ?? array(),
            "produtos_codigosbarras" => $produtos_codigosbarras ?? array(),
            "produtos_taxas"      => $produtos_taxas ?? array(),
            "produtos_taxas_br"   => $produtos_taxas_br ?? array(),
        ];

        // Only add codigo if it's provided and not empty
        if (isset($product['codigo']) && !empty($product['codigo'])) {
            $productData['codigo'] = $product['codigo'];
        }

        $payload = [
            "product" => [
                $productData
            ]
        ];

        return $payload;
    }

    public function getClientID($store)
    {
        // Map company to entity
        $escapedCompany = $this->db->escape($store);
        $sql = "SELECT entity FROM " . MAIN_DB_PREFIX . "const WHERE name = 'ZS_API_STORE' AND value = '" . $escapedCompany . "'";

        $resql = $this->db->query($sql);
        if ($resql) {
            $obj = $this->db->fetch_object($resql);
            if ($obj) {
                $entity = (int)$obj->entity;
            } else {
                dol_syslog("No entity found for company $store", LOG_ERR);
                return false;
            }
        } else {
            dol_syslog("Database query failed: " . $this->db->lasterror(), LOG_ERR);
            return false;
        }

        // Retrieve client_id for the entity
        $sql = "SELECT value FROM " . MAIN_DB_PREFIX . "const WHERE name = 'ZS_CLIENT_ID' AND entity = " . $entity;

        $resql = $this->db->query($sql);
        if ($resql) {
            $obj = $this->db->fetch_object($resql);
            if ($obj) {
                $client_id = $obj->value;
                return $client_id;
            } else {
                dol_syslog("No client_id found for entity $entity", LOG_ERR);
                return false;
            }
        } else {
            dol_syslog("Database query failed: " . $this->db->lasterror(), LOG_ERR);
            return false;
        }
    }

    /**
     * Update variable prices in the local dolizsynch_zsproduct table
     *
     * This method updates the precovenda and pvp{X}siva fields to -1 when
     * a price level is marked as variable, or restores them to 0 when unmarked.
     *
     * @param int $productId Dolibarr product ID
     * @param array $variablePrices Array where keys are price levels (1-10) and values are 1 if variable
     * @return int <0 if error, >=0 if success
     */
	public function updateVariablePricesInLocalTable($productId, $variablePrices)
	{
		global $conf, $user;


		if (empty($productId)) {
			$this->error = "Product ID is required";
			return -1;
		}

		$userId = (is_object($user) && !empty($user->id)) ? (int)$user->id : 0;

        // Fetch the existing record
        $sql = "SELECT * FROM " . MAIN_DB_PREFIX . "dolizsynch_zsproduct WHERE fk_product = " . (int)$productId;
        if (!empty($conf->global->ZS_API_STORE)) {
            $sql .= " AND loja_zs = " . (int)$conf->global->ZS_API_STORE;
        }
        $sql .= " LIMIT 1";

        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->error = "Error fetching product: " . $this->db->lasterror();
            dol_syslog(__METHOD__ . " - " . $this->error, LOG_ERR);
            return -1;
        }

        $obj = $this->db->fetch_object($resql);
        if (!$obj) {
            // No Zone Soft product record exists - this is OK, just skip
            dol_syslog(__METHOD__ . " - No Zone Soft product record found for product ID: " . $productId, LOG_INFO);
            return 0;
        }

        // Build UPDATE statement for variable prices
        $updateFields = array();

        for ($i = 1; $i <= 10; $i++) {
            $isVariable = !empty($variablePrices[$i]);

            if ($i == 1) {
                // Level 1 uses special field names
                $fieldHT = 'pvp1siva';
                $fieldTTC = 'precovenda';
            } else {
                $fieldHT = 'pvp' . $i . 'siva';
                $fieldTTC = 'pvp' . $i;
            }

            if ($isVariable) {
                // Set both prices to -1 (variable price indicator)
                $updateFields[] = $fieldHT . " = -1";
                $updateFields[] = $fieldTTC . " = -1";
            } else {
                // Check if it was previously -1, if so, set to 0
                $currentHT = isset($obj->$fieldHT) ? (float)$obj->$fieldHT : 0;
                $currentTTC = isset($obj->$fieldTTC) ? (float)$obj->$fieldTTC : 0;

                if ($currentHT == -1 && $currentTTC == -1) {
                    // Was variable, now set to 0 (user unchecked)
                    $updateFields[] = $fieldHT . " = 0";
                    $updateFields[] = $fieldTTC . " = 0";
                }
                // Otherwise, leave the existing price values untouched
            }
        }

        if (empty($updateFields)) {
            // No changes needed
            return 0;
        }

		// Execute UPDATE
		if ($userId > 0) {
			$updateFields[] = "fk_user_modif = " . $userId;
		}
        $sql = "UPDATE " . MAIN_DB_PREFIX . "dolizsynch_zsproduct SET ";
        $sql .= implode(", ", $updateFields);
        $sql .= " WHERE fk_product = " . (int)$productId;
        if (!empty($conf->global->ZS_API_STORE)) {
            $sql .= " AND loja_zs = " . (int)$conf->global->ZS_API_STORE;
        }

        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->error = "Error updating variable prices: " . $this->db->lasterror();
            dol_syslog(__METHOD__ . " - " . $this->error, LOG_ERR);
            return -1;
        }

        dol_syslog(__METHOD__ . " - Successfully updated variable prices for product ID: " . $productId, LOG_INFO);
        return 1;
    }

    /**
     * Sync variable prices to Zone Soft BMS via API
     *
     * This method fetches the complete product from Zone Soft, updates the variable price fields,
     * and sends the complete product object back to ZS BMS.
     *
     * @param int $productId Dolibarr product ID
     * @param array $variablePrices Array where keys are price levels (1-10) and values are 1 if variable
     * @return int <0 if error, >=0 if success
     */
    public function syncVariablePricesToZoneSoft($productId, $variablePrices)
    {
        global $conf;


        if (empty($productId)) {
            $this->error = "Product ID is required";
            return -1;
        }

        // Fetch the Zone Soft product code from local table
        $sql = "SELECT codigo FROM " . MAIN_DB_PREFIX . "dolizsynch_zsproduct WHERE fk_product = " . (int)$productId;
        if (!empty($conf->global->ZS_API_STORE)) {
            $sql .= " AND loja_zs = " . (int)$conf->global->ZS_API_STORE;
        }
        $sql .= " LIMIT 1";

        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->error = "Error fetching Zone Soft product code: " . $this->db->lasterror();
            dol_syslog(__METHOD__ . " - " . $this->error, LOG_ERR);
            return -1;
        }

        $obj = $this->db->fetch_object($resql);
        if (!$obj || empty($obj->codigo)) {
            $this->error = "Zone Soft product code not found for product ID: " . $productId;
            dol_syslog(__METHOD__ . " - " . $this->error, LOG_WARNING);
            return -1;
        }

        $zsProductCode = $obj->codigo;

        // Fetch the COMPLETE product from Zone Soft
        $zsProductData = $this->getZoneSoftProductById($zsProductCode);

        if (!$zsProductData || empty($zsProductData->product)) {
            $this->error = "Failed to fetch product from Zone Soft: code " . $zsProductCode;
            dol_syslog(__METHOD__ . " - " . $this->error, LOG_ERR);
            return -1;
        }

        // Convert to array for easier manipulation
        $productArray = json_decode(json_encode($zsProductData->product), true);

        // Update variable price fields in the product array
        $hasChanges = false;

        for ($i = 1; $i <= 10; $i++) {
            $isVariable = !empty($variablePrices[$i]);

            if ($i == 1) {
                // Level 1 uses special field names
                $fieldHT = 'pvp1siva';
                $fieldTTC = 'precovenda';
            } else {
                $fieldHT = 'pvp' . $i . 'siva';
                $fieldTTC = 'pvp' . $i;
            }

            if ($isVariable) {
                // Set both prices to -1 (variable price indicator)
                $productArray[$fieldHT] = -1;
                $productArray[$fieldTTC] = -1;
                $hasChanges = true;
            } else {
                // Check if it was previously -1, if so, set to 0
                if (isset($productArray[$fieldHT]) && isset($productArray[$fieldTTC])) {
                    if ($productArray[$fieldHT] == -1 && $productArray[$fieldTTC] == -1) {
                        // Was variable, now set to 0 (user unchecked)
                        $productArray[$fieldHT] = 0;
                        $productArray[$fieldTTC] = 0;
                        $hasChanges = true;
                    }
                }
            }
        }

        if (!$hasChanges) {
            // No changes needed
            dol_syslog(__METHOD__ . " - No variable price changes to sync for product code: " . $zsProductCode, LOG_INFO);
            return 0;
        }

        // Send the COMPLETE product object back to Zone Soft
        $this->zoneSoftApi->setInterface('products');

        $payload = array(
            'product' => $productArray
        );


        try {
            $response = $this->zoneSoftApi->execute('updateProduct', $payload);

            if ($response && isset($response['Response']) && $response['Response']['StatusCode'] == 200) {
                dol_syslog(__METHOD__ . " - Successfully synced variable prices to Zone Soft for product code: " . $zsProductCode, LOG_INFO);
                return 1;
            } else {
                $statusCode = isset($response['Response']['StatusCode']) ? $response['Response']['StatusCode'] : 'unknown';
                $responseContent = isset($response['Response']['Content']) ? json_encode($response['Response']['Content']) : 'no content';
                $this->error = "Zone Soft API returned error status: " . $statusCode . " - " . $responseContent;
                dol_syslog(__METHOD__ . " - " . $this->error, LOG_ERR);
                return -1;
            }
        } catch (Exception $e) {
            $this->error = "Exception while syncing to Zone Soft: " . $e->getMessage();
            dol_syslog(__METHOD__ . " - " . $this->error, LOG_ERR);
            return -1;
        }
    }
}

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
 * Product-property dictionary and relation normalization service.
 */
class ZSProductPropertyService
{
	/** @var DoliDB */
	private $db;

	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * Load the property-related shadow fields for one authorized Dolibarr product.
	 *
	 * The caller must apply Dolibarr's product restrictedArea() check first. The
	 * product row ID is then the entity-scoped parent boundary for this lookup.
	 *
	 * @param int $productId Dolibarr product row ID
	 * @param int $store Preferred ZSBMS store
	 * @return array{codigo:string,produtos_propriedades:mixed,caracteristicas:mixed}
	 */
	public function fetchStoredProductState($productId, $store)
	{
		$productId = (int) $productId;
		$store = (int) $store;
		$state = array(
			'codigo' => '',
			'produtos_propriedades' => null,
			'caracteristicas' => null,
		);
		if ($productId <= 0) {
			return $state;
		}

		$sql = 'SELECT codigo, produtos_propriedades, caracteristicas';
		$sql .= ' FROM ' . MAIN_DB_PREFIX . 'dolizsynch_zsproduct';
		$sql .= ' WHERE fk_product = ' . $productId;
		if ($store > 0) {
			$sql .= ' ORDER BY (loja_zs = ' . $store . ') DESC, rowid DESC';
		} else {
			$sql .= ' ORDER BY rowid DESC';
		}
		$sql .= ' ' . $this->db->plimit(1);

		$resql = $this->db->query($sql);
		if (!$resql) {
			throw new RuntimeException('Could not load the stored product property state: ' . $this->db->lasterror());
		}
		$row = $this->db->fetch_object($resql);
		if ($row) {
			$state['codigo'] = trim((string) ($row->codigo ?? ''));
			$state['produtos_propriedades'] = $row->produtos_propriedades ?? null;
			$state['caracteristicas'] = $row->caracteristicas ?? null;
		}
		$this->db->free($resql);

		return $state;
	}

	/**
	 * Fetch the property dictionary for one ZSBMS store.
	 *
	 * @param int $store ZSBMS store identifier
	 * @return array<int,array<string,mixed>> Definitions keyed by property ID
	 * @throws Exception When the API cannot provide a valid response
	 */
	public function fetchDefinitionsForStore($store)
	{
		$store = (int) $store;
		if ($store <= 0) {
			throw new InvalidArgumentException('A positive ZSBMS store is required to load product properties.');
		}

		require_once DOL_DOCUMENT_ROOT . '/custom/kreaflow/class/zsapisynch.class.php';

		$api = new ZSAPISynch($this->db);
		$api->setInterface('properties');
		$response = $api->executeForStore('getInstances', array(
			'property' => array(
				'order' => 'nome ASC',
				'limit' => 500,
			),
		), (string) $store);

		if (is_object($response)) {
			$response = json_decode(json_encode($response), true);
		}
		if (!is_array($response) || !isset($response['Response'])) {
			throw new RuntimeException('ZSBMS returned an invalid property dictionary response.');
		}

		$responseEnvelope = $response['Response'];
		if (is_object($responseEnvelope)) {
			$responseEnvelope = json_decode(json_encode($responseEnvelope), true);
		}
		$statusCode = is_array($responseEnvelope) ? (int) ($responseEnvelope['StatusCode'] ?? 0) : 0;
		if ($statusCode === 204) {
			return array();
		}
		if ($statusCode !== 200) {
			$error = is_array($responseEnvelope) ? trim((string) ($responseEnvelope['ErrorMessage'] ?? '')) : '';
			throw new RuntimeException($error !== '' ? $error : 'ZSBMS could not load the property dictionary.');
		}

		$content = $responseEnvelope['Content'] ?? array();
		return self::normalizeDefinitions(self::extractDefinitionRows($content));
	}

	/**
	 * Normalize property definitions returned by ZSBMS.
	 *
	 * @param mixed $rows Raw definition rows
	 * @return array<int,array<string,mixed>> Definitions keyed by property ID
	 */
	public static function normalizeDefinitions($rows)
	{
		if (is_object($rows)) {
			$rows = json_decode(json_encode($rows), true);
		}
		if (!is_array($rows)) {
			return array();
		}
		if (!self::isListArray($rows)) {
			$rows = array($rows);
		}

		$definitions = array();
		foreach ($rows as $row) {
			if (is_object($row)) {
				$row = json_decode(json_encode($row), true);
			}
			if (!is_array($row)) {
				continue;
			}

			$id = (int) ($row['id'] ?? $row['codigo'] ?? $row['cod_propriedade'] ?? $row['uid'] ?? 0);
			if ($id <= 0) {
				continue;
			}

			$dataType = strtolower(trim((string) ($row['tipo_dados'] ?? 'string')));
			if (!in_array($dataType, array('string', 'date', 'datetime', 'numeric'), true)) {
				$dataType = 'string';
			}
			$order = strtolower(trim((string) ($row['ordenacao'] ?? 'nofx')));
			if (!in_array($order, array('nofx', 'fifo', 'lifo'), true)) {
				$order = 'nofx';
			}
			$name = trim(self::decodeDisplayText($row['nome'] ?? ''));
			if ($name === '') {
				$name = '#' . $id;
			}

			$definitions[$id] = array(
				'id' => $id,
				'nome' => $name,
				'compra' => self::normalizeUsage($row['compra'] ?? 0),
				'ativo' => array_key_exists('ativo', $row) && empty($row['ativo']) ? 0 : 1,
				'ordenacao' => $order,
				'tipo_dados' => $dataType,
				'valores_multiplos' => empty($row['valores_multiplos']) ? 0 : 1,
				'venda' => self::normalizeUsage($row['venda'] ?? 0),
			);
		}

		uasort($definitions, static function ($left, $right) {
			return strcasecmp((string) $left['nome'], (string) $right['nome']);
		});

		return $definitions;
	}

	/**
	 * Decode HTML entities received from ZSBMS or legacy language files for safe UTF-8 display.
	 *
	 * The caller must still escape the returned value for its output context.
	 *
	 * @param mixed $value Display value
	 * @return string UTF-8 display value
	 */
	public static function decodeDisplayText($value)
	{
		$decoded = (string) $value;
		for ($pass = 0; $pass < 3; $pass++) {
			$next = html_entity_decode($decoded, ENT_QUOTES | ENT_HTML5, 'UTF-8');
			if ($next === $decoded) {
				break;
			}
			$decoded = $next;
		}

		return $decoded;
	}

	/**
	 * Normalize product-property relations for local persistence and ZSBMS payloads.
	 *
	 * @param mixed $value Raw JSON, array, or object
	 * @param int|string $productCode ZSBMS product identifier
	 * @param int[] $allowedPropertyIds Optional property dictionary boundary
	 * @return array<int,array<string,int|string>>
	 * @throws InvalidArgumentException On invalid JSON, rows, or unknown property IDs
	 */
	public static function normalizeRelations($value, $productCode, array $allowedPropertyIds = array())
	{
		$rows = self::decodeArray($value);
		$productCode = trim((string) $productCode);
		if ($productCode === '') {
			throw new InvalidArgumentException('The ZSBMS product identifier is required for product properties.');
		}
		$normalizedProductCode = ctype_digit($productCode) ? (int) $productCode : $productCode;

		$allowed = array();
		foreach ($allowedPropertyIds as $allowedId) {
			$allowedId = (int) $allowedId;
			if ($allowedId > 0) {
				$allowed[$allowedId] = true;
			}
		}

		$relations = array();
		foreach ($rows as $row) {
			if (is_object($row)) {
				$row = json_decode(json_encode($row), true);
			}
			if (!is_array($row)) {
				throw new InvalidArgumentException('Each product property relation must be an object.');
			}

			$propertyId = (int) ($row['cod_propriedade'] ?? $row['uid'] ?? $row['id'] ?? 0);
			if ($propertyId <= 0) {
				throw new InvalidArgumentException('Each product property relation requires a positive property identifier.');
			}
			if (!empty($allowed) && !isset($allowed[$propertyId])) {
				throw new InvalidArgumentException('Property #' . $propertyId . ' is outside the current ZSBMS property dictionary.');
			}

			$relations[$propertyId] = array(
				'cod_produto' => $normalizedProductCode,
				'cod_propriedade' => $propertyId,
				'uid' => $propertyId,
				'estado' => array_key_exists('estado', $row) && empty($row['estado']) ? 0 : 1,
			);
		}

		ksort($relations, SORT_NUMERIC);
		return array_values($relations);
	}

	/**
	 * Determine whether a JSON-compatible field contains one or more rows.
	 * Invalid non-empty source is treated conservatively as populated.
	 *
	 * @param mixed $value JSON-compatible field
	 * @return bool
	 */
	public static function hasRows($value)
	{
		if ($value === null) {
			return false;
		}
		if (is_array($value)) {
			return count($value) > 0;
		}
		if (is_object($value)) {
			return count(get_object_vars($value)) > 0;
		}

		$raw = trim((string) $value);
		if ($raw === '' || strtolower($raw) === 'null' || $raw === '[]') {
			return false;
		}
		$decoded = json_decode(html_entity_decode($raw, ENT_QUOTES | ENT_HTML5), true);
		return json_last_error() === JSON_ERROR_NONE && is_array($decoded) ? count($decoded) > 0 : true;
	}

	/**
	 * @param mixed $content API response content
	 * @return array
	 */
	private static function extractDefinitionRows($content)
	{
		if (is_object($content)) {
			$content = json_decode(json_encode($content), true);
		}
		if (!is_array($content)) {
			return array();
		}
		foreach (array('property', 'properties') as $key) {
			if (array_key_exists($key, $content)) {
				$value = $content[$key];
				if (is_object($value)) {
					$value = json_decode(json_encode($value), true);
				}
				return is_array($value) ? $value : array();
			}
		}
		return self::isListArray($content) ? $content : array();
	}

	/**
	 * @param mixed $value JSON-compatible value
	 * @return array
	 * @throws InvalidArgumentException On invalid JSON or non-list data
	 */
	private static function decodeArray($value)
	{
		if (is_object($value)) {
			$value = json_decode(json_encode($value), true);
		}
		if (is_array($value)) {
			$decoded = $value;
		} else {
			$raw = trim((string) $value);
			if ($raw === '' || strtolower($raw) === 'null') {
				return array();
			}
			$decoded = json_decode(html_entity_decode($raw, ENT_QUOTES | ENT_HTML5), true);
			if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
				throw new InvalidArgumentException('Product properties must be a valid JSON array.');
			}
		}

		if (!self::isListArray($decoded)) {
			throw new InvalidArgumentException('Product properties must be a JSON list.');
		}
		return $decoded;
	}

	/**
	 * @param mixed $value Property usage value
	 * @return int
	 */
	private static function normalizeUsage($value)
	{
		$value = (int) $value;
		return ($value >= 0 && $value <= 2) ? $value : 0;
	}

	/**
	 * @param array $value Array to inspect
	 * @return bool
	 */
	private static function isListArray(array $value)
	{
		if ($value === array()) {
			return true;
		}
		return array_keys($value) === range(0, count($value) - 1);
	}
}

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
 * \file    dolizsynch/class/actions_dolizsynch.class.php
 * \ingroup dolizsynch
 * \brief   Example hook overload.
 *
 * Put detailed description here.
 */

/**
 * Class ActionsDoliZSynch
 */
require_once DOL_DOCUMENT_ROOT . '/custom/kreaflow/class/zsproductpropertyservice.class.php';
require_once DOL_DOCUMENT_ROOT . '/custom/kreaflow/class/zspropertystockservice.class.php';

class ActionsDoliZSynch
{
	const KREA_ALIAS_EXTRAFIELD = 'kreap_alias';

	/**
	 * @var DoliDB Database handler.
	 */
	public $db;

	/**
	 * @var string Error code (or message)
	 */
	public $error = '';

	/**
	 * @var array Errors
	 */
	public $errors = array();


	/**
	 * @var array Hook results. Propagated to $hookmanager->resArray for later reuse
	 */
	public $results = array();

	/**
	 * @var string String displayed by executeHook() immediately after return
	 */
	public $resprints;

	/**
	 * @var int        Priority of hook (50 is used if value is not defined)
	 */
	public $priority;

	/**
	 * @var bool       Flag to show ZSBMS creation prompt
	 */
	public $showPrompt = false;

	/**
	 * @var int        Product ID for the prompt
	 */
	public $promptProductId;

	/**
	 * @var object     Product object for the prompt
	 */
	public $promptObject;

	/**
	 * @var bool|null Cache for KreaProducts mirror extrafield column availability.
	 */
	private $hasKreaAliasExtrafield = null;

	/** @var array<int,array<int,array<string,mixed>>> Exact-stock plans cached by supplier invoice ID. */
	private $propertyStockPlansByInvoice = array();


	/**
	 * Constructor
	 *
	 *  @param        DoliDB        $db      Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * Normalize preparation time values to HH:MM:SS.
	 *
	 * @param string $value Raw value from form/database
	 * @return string
	 */
	private function normalizePrepTime($value)
	{
		$default = '00:00:00';
		$value = trim((string) $value);

		if ($value === '' || $value === '__:__:__') {
			return $default;
		}

		if (preg_match('/^(\d{1,2}):(\d{1,2})(?::(\d{1,2}))?$/', $value, $matches)) {
			$hours = (int) $matches[1];
			$minutes = (int) $matches[2];
			$seconds = isset($matches[3]) ? (int) $matches[3] : 0;

			if ($hours >= 0 && $hours <= 23 && $minutes >= 0 && $minutes <= 59 && $seconds >= 0 && $seconds <= 59) {
				return sprintf('%02d:%02d:%02d', $hours, $minutes, $seconds);
			}
		}

		return $default;
	}

	/**
	 * Extract preparation time from POST source.
	 * Supports either split selects (hour/minute/second) or legacy text field.
	 *
	 * @param array $source
	 * @return string
	 */
	private function extractPrepTimeFromSource(array $source)
	{
		if (
			isset($source['DOLIZSYNCH_TEMPOPREP_HOUR'])
			|| isset($source['DOLIZSYNCH_TEMPOPREP_MINUTE'])
			|| isset($source['DOLIZSYNCH_TEMPOPREP_SECOND'])
		) {
			$hour = isset($source['DOLIZSYNCH_TEMPOPREP_HOUR']) ? (int) $source['DOLIZSYNCH_TEMPOPREP_HOUR'] : 0;
			$minute = isset($source['DOLIZSYNCH_TEMPOPREP_MINUTE']) ? (int) $source['DOLIZSYNCH_TEMPOPREP_MINUTE'] : 0;
			$second = isset($source['DOLIZSYNCH_TEMPOPREP_SECOND']) ? (int) $source['DOLIZSYNCH_TEMPOPREP_SECOND'] : 0;

			if ($hour < 0 || $hour > 23) {
				$hour = 0;
			}
			if ($minute < 0 || $minute > 59) {
				$minute = 0;
			}
			if ($second < 0 || $second > 59) {
				$second = 0;
			}

			return sprintf('%02d:%02d:%02d', $hour, $minute, $second);
		}

		return $this->normalizePrepTime(isset($source['DOLIZSYNCH_TEMPOPREP']) ? $source['DOLIZSYNCH_TEMPOPREP'] : '');
	}

	/**
	 * Normalize IVA value for dropdown matching/storage.
	 *
	 * @param mixed $value
	 * @return string
	 */
	private function normalizeTaxSelectionValue($value)
	{
		$value = trim((string) $value);
		if ($value === '') {
			return '';
		}

		if (function_exists('price2num')) {
			$numericValue = price2num($value, 'MU');
		} else {
			$numericValue = (float) str_replace(',', '.', $value);
		}

		if ($numericValue === '' || $numericValue === null || !is_numeric($numericValue)) {
			return '';
		}

		return number_format((float) $numericValue, 2, '.', '');
	}

	/**
	 * Normalize HEX color to #rrggbb and fallback when invalid.
	 *
	 * @param string $value
	 * @param string $fallback
	 * @return string
	 */
	private function normalizeHexColor($value, $fallback)
	{
		$fallback = trim((string) $fallback);
		if (!preg_match('/^#[0-9a-fA-F]{6}$/', $fallback)) {
			$fallback = '#000000';
		}

		$value = trim((string) $value);
		if (!preg_match('/^#[0-9a-fA-F]{6}$/', $value)) {
			return strtolower($fallback);
		}

		return strtolower($value);
	}

	/**
	 * Normalize a posted JSON array field and protect DB value when input is missing/invalid.
	 *
	 * @param string|null $postedValue
	 * @param string|null $existingValue
	 * @param string $fieldLogLabel
	 * @return string
	 */
	private function normalizePostedJsonArrayField($postedValue, $existingValue, $fieldLogLabel)
	{
		$label = trim((string) $fieldLogLabel);
		if ($label === '') {
			$label = 'json_array_field';
		}

		$existingValue = (string) $existingValue;
		$existingTrim = trim($existingValue);
		$jsonValue = trim((string) $postedValue);

		if ($jsonValue === '' || strtolower($jsonValue) === 'null') {
			if ($existingTrim !== '' && strtolower($existingTrim) !== 'null') {
				dol_syslog("KreaFlow: " . $label . " missing from POST, keeping DB value (len=" . strlen($existingValue) . ")", LOG_WARNING);
				return $existingValue;
			}
			return '[]';
		}

		$candidates = array(
			array('value' => $jsonValue, 'source' => 'raw'),
			array('value' => html_entity_decode($jsonValue, ENT_QUOTES | ENT_HTML5), 'source' => 'html_entity_decode'),
			array('value' => stripslashes($jsonValue), 'source' => 'stripslashes'),
			array('value' => stripslashes(html_entity_decode($jsonValue, ENT_QUOTES | ENT_HTML5)), 'source' => 'html_entity_decode+stripslashes'),
		);
		$seenCandidates = array();
		foreach ($candidates as $candidate) {
			$candidateValue = (string) $candidate['value'];
			if ($candidateValue === '' || isset($seenCandidates[$candidateValue])) {
				continue;
			}
			$seenCandidates[$candidateValue] = true;

			$decodedCandidate = json_decode($candidateValue, true);
			if (json_last_error() !== JSON_ERROR_NONE || !is_array($decodedCandidate)) {
				continue;
			}

			$normalized = json_encode($decodedCandidate, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
			if ($candidate['source'] !== 'raw') {
				dol_syslog("KreaFlow: " . $label . " JSON repaired using " . $candidate['source'], LOG_WARNING);
			}
			return ($normalized === false ? $candidateValue : $normalized);
		}

		if ($existingTrim !== '' && strtolower($existingTrim) !== 'null') {
			dol_syslog("KreaFlow: Invalid " . $label . " JSON in POST, keeping DB value (len=" . strlen($existingValue) . ")", LOG_ERR);
			return $existingValue;
		}

		dol_syslog("KreaFlow: Invalid " . $label . " JSON in POST and no DB fallback, saving empty []", LOG_ERR);
		return '[]';
	}

	/**
	 * Decode a JSON array field using tolerant candidates.
	 *
	 * @param string|null $value
	 * @return array<int,mixed>|null
	 */
	private function decodeJsonArrayField($value)
	{
		$raw = trim((string) $value);
		if ($raw === '' || strtolower($raw) === 'null') {
			return array();
		}

		$candidates = array(
			$raw,
			html_entity_decode($raw, ENT_QUOTES | ENT_HTML5),
			stripslashes($raw),
			stripslashes(html_entity_decode($raw, ENT_QUOTES | ENT_HTML5)),
		);
		$seen = array();
		foreach ($candidates as $candidate) {
			$candidate = (string) $candidate;
			if ($candidate === '' || isset($seen[$candidate])) {
				continue;
			}
			$seen[$candidate] = true;
			$decoded = json_decode($candidate, true);
			if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
				return $decoded;
			}
		}

		return null;
	}

	/**
	 * Normalize barcode list rows to a stable payload.
	 *
	 * @param array<int,mixed> $rows
	 * @return array<int,array<string,mixed>>
	 */
	private function normalizeProdutosCodigosBarrasRows(array $rows)
	{
		$normalizedRows = array();
		foreach ($rows as $row) {
			if (!is_array($row)) {
				continue;
			}

			$codbarras = trim((string) (isset($row['codbarras']) ? $row['codbarras'] : ''));
			$codigoRaw = isset($row['codigo']) ? $row['codigo'] : '';
			$codigo = '';
			if ($codigoRaw !== '' && $codigoRaw !== null) {
				$codigoStr = trim((string) $codigoRaw);
				if ($codigoStr !== '') {
					$codigo = ctype_digit($codigoStr) ? (int) $codigoStr : $codigoStr;
				}
			}

			$descricao = trim((string) (isset($row['descricao']) ? $row['descricao'] : ''));
			$qtdRaw = isset($row['qtd']) ? $row['qtd'] : 0;
			$totalRaw = isset($row['total']) ? $row['total'] : 0;
			$qtd = is_numeric($qtdRaw) ? (float) $qtdRaw : 0;
			$total = is_numeric($totalRaw) ? (float) $totalRaw : 0;
			if ($qtd == (int) $qtd) {
				$qtd = (int) $qtd;
			}
			if ($total == (int) $total) {
				$total = (int) $total;
			}

			$errors = array();
			if (isset($row['_errors']) && is_array($row['_errors'])) {
				foreach ($row['_errors'] as $errorEntry) {
					$errorText = trim((string) $errorEntry);
					if ($errorText !== '') {
						$errors[] = $errorText;
					}
				}
			}

			$codigoCheck = is_int($codigo) ? (string) $codigo : trim((string) $codigo);
			$isEmptyRow = ($codbarras === '' && $codigoCheck === '' && $descricao === '' && (float) $qtd == 0.0 && (float) $total == 0.0);
			if ($isEmptyRow) {
				continue;
			}

			$normalizedRows[] = array(
				'codbarras' => $codbarras,
				'codigo' => $codigo,
				'descricao' => $descricao,
				'qtd' => $qtd,
				'total' => $total,
				'_errors' => $errors,
			);
		}

		return $normalizedRows;
	}

	/**
	 * Normalize barcode list JSON for UI/storage.
	 *
	 * @param string|null $value
	 * @return string
	 */
	private function normalizeProdutosCodigosBarrasJson($value)
	{
		$decoded = $this->decodeJsonArrayField($value);
		if (!is_array($decoded)) {
			return '[]';
		}

		$normalizedRows = $this->normalizeProdutosCodigosBarrasRows($decoded);
		$encoded = json_encode($normalizedRows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		return ($encoded === false ? '[]' : $encoded);
	}

	/**
	 * Resolve module-level default colors configured in setup.
	 *
	 * @param object $conf
	 * @return array{0:string,1:string}
	 */
	private function getDefaultProductButtonColors($conf)
	{
		$defaultBackgroundColor = '#696969';
		$defaultFontColor = '#ffffff';

		if (is_object($conf) && !empty($conf->global) && is_object($conf->global)) {
			$defaultBackgroundColor = $this->normalizeHexColor(
				isset($conf->global->ZS_DEFAULT_BUTTON_BACKGROUND_COLOR) ? $conf->global->ZS_DEFAULT_BUTTON_BACKGROUND_COLOR : '',
				$defaultBackgroundColor
			);
			$defaultFontColor = $this->normalizeHexColor(
				isset($conf->global->ZS_DEFAULT_BUTTON_FONT_COLOR) ? $conf->global->ZS_DEFAULT_BUTTON_FONT_COLOR : '',
				$defaultFontColor
			);
		}

		return array($defaultBackgroundColor, $defaultFontColor);
	}

	/**
	 * Build a bounded, non-recursive value summary safe for logs/debugbar.
	 *
	 * @param mixed $value
	 * @param int $maxItems
	 * @return string
	 */
	private function summarizeValueForLog($value, $maxItems = 8)
	{
		$maxItems = max(1, (int) $maxItems);

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
			if (strlen($value) > 512) {
				return substr($value, 0, 512) . '... [truncated]';
			}
			return $value;
		}
		if (is_array($value)) {
			$keys = array_keys($value);
			$preview = array_slice($keys, 0, $maxItems);
			$preview = array_map('strval', $preview);
			return 'array(count=' . count($value) . ', keys=[' . implode(', ', $preview) . (count($keys) > $maxItems ? ', ...' : '') . '])';
		}
		if (is_object($value)) {
			$props = array_keys(get_object_vars($value));
			$preview = array_slice($props, 0, $maxItems);
			$preview = array_map('strval', $preview);
			return 'object(' . get_class($value) . ', props=' . count($props) . ', keys=[' . implode(', ', $preview) . (count($props) > $maxItems ? ', ...' : '') . '])';
		}

		return gettype($value);
	}

	/**
	 * Build compact exception text without stack trace payload.
	 *
	 * @param Exception $exception
	 * @return string
	 */
	private function summarizeExceptionForLog($exception)
	{
		if (!$exception instanceof Exception) {
			return 'Unknown exception';
		}

		return get_class($exception) . ': ' . $exception->getMessage() . ' @ ' . $exception->getFile() . ':' . $exception->getLine();
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
	 * Mirror `descricaocurta` into KreaProducts extrafield, when available.
	 *
	 * @param int $productId
	 * @param string $descricaoCurta
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
	 * Load synced ZSBMS taxes for current store.
	 *
	 * @param int $storeProducts
	 * @return array
	 */
	private function loadZsTaxOptions($storeProducts)
	{
		$options = array();
		$sql = "SELECT tax_id, tax_name, tax_value ";
		$sql .= "FROM " . MAIN_DB_PREFIX . "dolizsynch_zstax ";
		$sql .= "WHERE loja_zs = " . ((int) $storeProducts) . " ";
		$sql .= "AND active = 1 ";
		$sql .= "ORDER BY tax_value ASC, tax_name ASC";

		$resql = $this->db->query($sql);
		if (!$resql) {
			$sqlError = (string) $this->db->lasterror();
			if (
				stripos($sqlError, "doesn't exist") !== false
				|| stripos($sqlError, 'no such table') !== false
				|| stripos($sqlError, 'undefined table') !== false
			) {
				dol_syslog("ZS taxes table not found yet, returning empty tax options", LOG_WARNING);
			} else {
				dol_syslog("SQL Error on fetching ZS taxes: " . $sqlError, LOG_ERR);
			}
			return $options;
		}

		while ($obj = $this->db->fetch_object($resql)) {
			$normalizedValue = $this->normalizeTaxSelectionValue($obj->tax_value);
			if ($normalizedValue === '') {
				continue;
			}

			$label = $normalizedValue . '%';
			$taxName = trim((string) $obj->tax_name);
			if ($taxName !== '') {
				$label .= ' - ' . $taxName;
			}

			$options[] = array(
				'id' => (string) $obj->tax_id,
				'value' => $normalizedValue,
				'label' => $label,
			);
		}

		return $options;
	}

	/**
	 * Render ZSBMS tax dropdown.
	 *
	 * @param string $fieldName
	 * @param mixed $selectedValue
	 * @param array $taxOptions
	 * @return string
	 */
	private function renderZsTaxSelect($fieldName, $selectedValue, array $taxOptions)
	{
		$selectedNormalized = $this->normalizeTaxSelectionValue($selectedValue);
		$selectedFound = false;

		$selectId = $fieldName . '_SELECT';
		$html = '<select class="flat minwidth200" name="' . htmlspecialchars($fieldName) . '" id="' . htmlspecialchars($selectId) . '">';
		$html .= '<option value=""></option>';

		foreach ($taxOptions as $taxOption) {
			$optionValue = isset($taxOption['value']) ? (string) $taxOption['value'] : '';
			if ($optionValue === '') {
				continue;
			}
			$optionLabel = isset($taxOption['label']) ? (string) $taxOption['label'] : $optionValue . '%';
			$isSelected = ($selectedNormalized !== '' && $optionValue === $selectedNormalized);
			if ($isSelected) {
				$selectedFound = true;
			}
			$html .= '<option value="' . htmlspecialchars($optionValue) . '"' . ($isSelected ? ' selected' : '') . '>' . htmlspecialchars($optionLabel) . '</option>';
		}

		if (!$selectedFound && $selectedNormalized !== '') {
			$html .= '<option value="' . htmlspecialchars($selectedNormalized) . '" selected>' . htmlspecialchars($selectedNormalized . '%') . '</option>';
		}

		$html .= '</select>';
		return $html;
	}

	/**
	 * Resolve display label for IVA values based on synced ZSBMS taxes.
	 *
	 * @param mixed $selectedValue
	 * @param array $taxOptions
	 * @return string
	 */
	private function getZsTaxDisplayLabel($selectedValue, array $taxOptions)
	{
		$selectedNormalized = $this->normalizeTaxSelectionValue($selectedValue);
		if ($selectedNormalized === '') {
			return '';
		}

		foreach ($taxOptions as $taxOption) {
			if (!isset($taxOption['value'])) {
				continue;
			}
			if ((string) $taxOption['value'] === $selectedNormalized) {
				return isset($taxOption['label']) ? (string) $taxOption['label'] : ($selectedNormalized . '%');
			}
		}

		return $selectedNormalized . '%';
	}

	/**
	 * Execute action
	 *
	 * @param    array            $parameters        Array of parameters
	 * @param    CommonObject    $object            The object to process
	 * @param    string            $action            'add', 'update', 'view'
	 * @return    int                                <0 if KO,
	 *                                                =0 if OK but we want to process standard actions too,
	 *                                                >0 if OK and we want to replace standard actions.
	 */
	public function getNomUrl($parameters, &$object, &$action)
	{
		global $db, $langs, $conf, $user;
		$this->resprints = '';
		return 0;
	}

	/**
	 * Execute action completeTabsHead
	 *
	 * Hide DoliZSynch product tab when synchronization is disabled on the product.
	 *
	 * @param   array        $parameters   Hook metadatas (head, mode, object, ...)
	 * @param   CommonObject $object       The object to process
	 * @param   string       $action       Current action
	 * @param   HookManager  $hookmanager  Hook manager
	 * @return  int                       <0 if KO, 0 if OK (continue), >0 to replace standard code
	 */
	public function completeTabsHead(&$parameters, &$object, &$action, $hookmanager)
	{
		if (empty($parameters['object']) || !is_object($parameters['object'])) {
			return 0;
		}
		if (empty($parameters['head']) || !is_array($parameters['head'])) {
			return 0;
		}

		// Only applies to product/service cards (element = 'product')
		if (empty($parameters['object']->element) || $parameters['object']->element !== 'product') {
			return 0;
		}
		if (empty($parameters['object']->id)) {
			return 0;
		}

		$mode = isset($parameters['mode']) ? (string) $parameters['mode'] : '';
		$changed = false;

		// Ensure extrafields are loaded so zs_synch can be evaluated
		if (
			method_exists($parameters['object'], 'fetch_optionals')
			&& (empty($parameters['object']->array_options) || !array_key_exists('options_zs_synch', $parameters['object']->array_options))
		) {
			$parameters['object']->fetch_optionals($parameters['object']->id);
		}

		$zsSynch = null;
		if (isset($parameters['object']->array_options['options_zs_synch'])) {
			$zsSynch = (int) $parameters['object']->array_options['options_zs_synch'];
		}

		// If extrafield isn't defined for this entity, read the raw value directly.
		if ($zsSynch === null) {
			$sqlSynch = "SELECT zs_synch FROM " . MAIN_DB_PREFIX . "product_extrafields WHERE fk_object = " . ((int) $parameters['object']->id);
			$resSynch = $this->db->query($sqlSynch);
			if ($resSynch && ($rowSynch = $this->db->fetch_object($resSynch))) {
				if ($rowSynch->zs_synch === '1' || $rowSynch->zs_synch === 1) {
					$zsSynch = 1;
				} elseif ($rowSynch->zs_synch === '0' || $rowSynch->zs_synch === 0) {
					$zsSynch = 0;
				}
			}
		}

		if ($zsSynch === null) {
			$zsSynch = 0;
		}

		if ($zsSynch === 0 && !empty($parameters['object']->ref)) {
			$globalSynch = $this->getGlobalZsSynchByRef($parameters['object']->ref);
			if ($globalSynch !== null) {
				$zsSynch = (int) $globalSynch;
			}
		}

		// Hide DoliZSynch tab when synchronization is disabled
		if ($zsSynch === 0) {
			foreach ($parameters['head'] as $key => $tab) {
				if (is_array($tab) && !empty($tab[2]) && $tab[2] === 'dolizsynch') {
					unset($parameters['head'][$key]);
					$changed = true;
					break;
				}
			}
		}

		// Enforce desired sort order for product tabs (run on final pass)
		if ($mode === 'remove') {
			$desiredOrder = array(
				'card',
				'dolizsynch',
				'dolizsynch_sellprice',
				'krea_suppliers',
				'krea_subproduct',
				'krea_producttree',
				'stock',
			);

			$originalKeys = array();
			foreach ($parameters['head'] as $tab) {
				$originalKeys[] = (is_array($tab) && isset($tab[2])) ? (string) $tab[2] : '';
			}

			$reordered = array();
			$used = array();
			foreach ($desiredOrder as $wantedKey) {
				foreach ($parameters['head'] as $idx => $tab) {
					if (isset($used[$idx])) {
						continue;
					}
					if (is_array($tab) && !empty($tab[2]) && $tab[2] === $wantedKey) {
						$reordered[] = $tab;
						$used[$idx] = true;
						break;
					}
				}
			}
			foreach ($parameters['head'] as $idx => $tab) {
				if (!isset($used[$idx])) {
					$reordered[] = $tab;
				}
			}

			$reorderedKeys = array();
			foreach ($reordered as $tab) {
				$reorderedKeys[] = (is_array($tab) && isset($tab[2])) ? (string) $tab[2] : '';
			}

			if ($reorderedKeys !== $originalKeys) {
				$parameters['head'] = $reordered;
				$changed = true;
			}
		}

		$parameters['head'] = array_values($parameters['head']);

		if ($changed && defined('DOL_VERSION') && (int) DOL_VERSION < 14) {
			$this->results = $parameters['head'];
			return 1;
		}

		return 0;
	}

	/**
	 * Overloading the doActions function : replacing the parent's function with the one below
	 *
	 * @param   array           $parameters     Hook metadatas (context, etc...)
	 * @param   CommonObject    $object         The object to process
	 * @param   string          $action         Current action (if set). Generally create or edit or null
	 * @param   HookManager     $hookmanager    Hook manager propagated to allow calling another hook
	 * @return  int                             < 0 on error, 0 on success, 1 to replace standard code
	 */
	public function doActions($parameters, &$object, &$action, $hookmanager)
	{
		global $db, $conf, $langs, $user;

		$userId = (!empty($user) && is_object($user) && !empty($user->id)) ? (int) $user->id : 1;

		// This hook is executed in many contexts (including ones where $object can be a stdClass).
		// Only run DoliZSynch logic for product/service cards.
		if (
			!is_object($object)
			|| !property_exists($object, 'element')
			|| $object->element !== 'product'
			|| !property_exists($object, 'id')
		) {
			return 0;
		}

		$isDolizsynchPageContext = (
			!empty($parameters['from_dolizsynch_tab'])
			|| !empty($parameters['from_dolizsynch_page'])
			|| GETPOST('dolizsynch_form', 'alpha') === '1'
			|| GETPOST('dolizsynch_page', 'int')
		);
		if ($isDolizsynchPageContext && $_SERVER['REQUEST_METHOD'] === 'POST' && !empty($object->id)) {
			$visibleEntities = array_map('intval', explode(',', getEntity('product')));
			if (!in_array((int) $object->entity, $visibleEntities, true) || !empty($user->socid)) {
				accessforbidden();
			}
		}
		if (
			$isDolizsynchPageContext
			&& $_SERVER['REQUEST_METHOD'] === 'POST'
			&& in_array($action, array('update', 'create', 'add', 'edit'), true)
			&& empty($user->rights->produit->creer)
		) {
			accessforbidden();
		}

		if (
			$isDolizsynchPageContext
			&& $_SERVER['REQUEST_METHOD'] === 'POST'
			&& GETPOSTINT('DOLIZSYNCH_PROPERTY_STOCK_PRESENT') === 1
		) {
			$bomId = GETPOSTINT('DOLIZSYNCH_PROPERTY_STOCK_BOM_ID');
			$ruleActive = $bomId > 0;
			$stockPropertyDefinition = !empty($parameters['zs_property_stock_definition'])
				&& is_array($parameters['zs_property_stock_definition'])
				? $parameters['zs_property_stock_definition']
				: null;
			$propertyId = (int) ($stockPropertyDefinition['id'] ?? 0);
			$propertyName = (string) ($stockPropertyDefinition['nome'] ?? '');

			if ($ruleActive && $stockPropertyDefinition === null) {
				$this->error = $langs->trans('DoliZSynchPropertyStockSourcePropertyRequired');
				return -1;
			}
			if ($ruleActive && ($stockPropertyDefinition['tipo_dados'] ?? '') !== 'numeric') {
				$this->error = $langs->trans('DoliZSynchPropertyStockNumericPropertyRequired');
				return -1;
			}

			$stockService = new ZSPropertyStockService($db);
			if (!$stockService->saveRule(
				(int) $object->id,
				(int) $conf->entity,
				$propertyId,
				$propertyName,
				$bomId,
				$userId
			)) {
				$this->error = $stockService->error;
				return -1;
			}
		}


		if ($_SERVER['REQUEST_METHOD'] !== 'POST' || GETPOST('options_zs_synch', 'alphanohtml') === '') {
			if (
				method_exists($object, 'fetch_optionals')
				&& (empty($object->array_options) || !array_key_exists('options_zs_synch', $object->array_options))
			) {
				$object->fetch_optionals($object->id);
			}
			$globalSynch = $this->getGlobalZsSynchByRef($object->ref);
			if ($globalSynch !== null) {
				if (empty($object->array_options)) {
					$object->array_options = array();
				}
				$object->array_options['options_zs_synch'] = (int) $globalSynch;
			}
		}

		// Hard-disable automatic ZSBMS product sync/prompt flows
		$zsbmsProductAutoSyncDisabled = true;

		// Optional ZSBMS prefill (used as fallback when enabling sync)
		if (!empty($_GET['prefill_zsbms']) && $_GET['prefill_zsbms'] == '1' && !empty($object->id)) {
			require_once DOL_DOCUMENT_ROOT . '/product/class/product.class.php';
			require_once DOL_DOCUMENT_ROOT . '/custom/kreaflow/class/zsprodsynch.class.php';

			$store = !empty($conf->global->ZS_API_STORE) ? $conf->global->ZS_API_STORE : null;
			if (empty($store)) {
				setEventMessages($langs->trans("Error") . ': Loja ZSBMS nao configurada.', null, 'errors');
			} elseif (empty($object->ref) || !ctype_digit((string) $object->ref)) {
				setEventMessages($langs->trans("Error") . ': Referencia do produto nao e numerica. Impossivel obter dados do ZSBMS.', null, 'errors');
			} else {
				try {
					$zsSync = new ZSProductSynch($db);
					$zsProduct = $zsSync->getZoneSoftProductByIdInStore($object->ref, $store);
					if ($zsProduct && !empty($zsProduct->product)) {
						if ($zsSync->insertProductToLocalTable($zsProduct)) {
							setEventMessages($langs->trans("ProductCreatedInZoneSoft"), null, 'mesgs');
						} else {
							setEventMessages($langs->trans("Error") . ': Falha ao gravar dados do ZSBMS localmente.', null, 'errors');
						}
					} else {
						setEventMessages($langs->trans("Error") . ': Produto nao encontrado no ZSBMS para esta loja.', null, 'errors');
					}
				} catch (Exception $e) {
					setEventMessages($langs->trans("Error") . ': ' . $e->getMessage(), null, 'errors');
				}
			}

			// Redirect back to edit page without the prefill flag
			header('Location: ' . $_SERVER['PHP_SELF'] . '?id=' . (int)$object->id . '&action=edit');
			exit;
		}


		// Check for deferred prompt flag from trigger (after transaction completion)
		if (!$zsbmsProductAutoSyncDisabled && !empty($_SESSION['zsbms_show_prompt']) && !empty($_SESSION['zsbms_create_product_id'])) {
			$productId = $_SESSION['zsbms_create_product_id'];
			$productRef = $_SESSION['zsbms_create_product_ref'];
			$store = $_SESSION['zsbms_create_store'];

			// Check if product ref is an integer before showing prompt
			if (!is_numeric($productRef) || !ctype_digit($productRef)) {
				dol_syslog("KreaFlow: Product {$productRef} has non-integer reference, skipping ZSBMS prompt", LOG_INFO);
				// Clear the flag and skip the prompt
				unset($_SESSION['zsbms_show_prompt']);
				unset($_SESSION['zsbms_create_product_id']);
				unset($_SESSION['zsbms_create_product_ref']);
				unset($_SESSION['zsbms_create_store']);
				return 0;
			}

			dol_syslog("KreaFlow: Found deferred prompt flag for product ID {$productId}, REF: '{$productRef}', redirecting now", LOG_INFO);

			// Clear the flag to prevent repeated redirects
			unset($_SESSION['zsbms_show_prompt']);

			// Now redirect to show the prompt - transaction should be complete
			$redirectUrl = DOL_URL_ROOT . '/product/card.php?id=' . $productId . '&show_zsbms_prompt=1';
			header('Location: ' . $redirectUrl);
			exit;
		}

		// Check if product was created without sync and show informational message
		if (!empty($_SESSION['dolizsynch_product_created_without_sync'])) {
			setEventMessages($langs->trans("DoliZSynchProductCreatedWithoutSync"), null, 'mesgs');
			unset($_SESSION['dolizsynch_product_created_without_sync']);
		}

		// Handle product deletion - clean up related records before deletion
		if (($action == 'confirm_delete' || $action == 'delete') && !empty($object->id)) {
			$productId = (int)$object->id;

			dol_syslog("KreaFlow: Product deletion detected for product ID: $productId, cleaning up related records", LOG_INFO);

			// Delete from llx_dolizsynch_product_equivalence (both as source and destination)
			$deleteEquivSql = "DELETE FROM " . MAIN_DB_PREFIX . "dolizsynch_product_equivalence WHERE fk_product_source = " . $productId . " OR fk_product_dest = " . $productId;
			if ($db->query($deleteEquivSql)) {
				dol_syslog("KreaFlow: Deleted product equivalence records for product $productId", LOG_INFO);
			} else {
				dol_syslog("KreaFlow: Failed to delete product equivalence records for product $productId: " . $db->lasterror(), LOG_ERR);
			}
		}

		// Handle ZSBMS product creation from the interactive prompt
		if (!$zsbmsProductAutoSyncDisabled && $action == 'sync_from_add') {
			// Get product ID from URL parameter
			$productId = !empty($_GET['id']) ? (int)$_GET['id'] : 0;

			if ($productId > 0) {
				// Always fetch the product data properly
				require_once DOL_DOCUMENT_ROOT . '/product/class/product.class.php';
				$product = new Product($db);
				$fetchResult = $product->fetch($productId);

				if ($fetchResult > 0) {
					$object = $product;
					dol_syslog("KreaFlow: Product fetched successfully - ID: {$object->id}, ref: '{$object->ref}', label: '{$object->label}', price: {$object->price}, price_ttc: {$object->price_ttc}, tva_tx: {$object->tva_tx}, cost_price: {$object->cost_price}", LOG_INFO);

					// Additional validation to ensure we have required data
					if (empty($object->label)) {
						dol_syslog("KreaFlow: Warning - Product label is empty for product ID: $productId", LOG_WARNING);
					}
					if (empty($object->ref)) {
						dol_syslog("KreaFlow: Warning - Product reference is empty for product ID: $productId", LOG_WARNING);
					}
				} else {
					dol_syslog("KreaFlow: Failed to fetch product with ID: $productId, fetch result: $fetchResult", LOG_ERR);
					setEventMessages('Error: Could not load product data', null, 'errors');
					return;
				}

				dol_syslog("KreaFlow: Processing sync_from_add action for product {$object->id}", LOG_INFO);

				// Check if user wants to create in ZSBMS
				if (GETPOST('create_in_zsbms', 'alpha') == 'yes') {
					$numberingMethod = GETPOST('numbering_method', 'alpha') ?: 'automatic';
					$store = $conf->global->ZS_API_STORE;

					require_once DOL_DOCUMENT_ROOT . '/custom/kreaflow/class/zsprodsynch.class.php';
					$zsProductSync = new ZSProductSynch($db);

					try {
						if ($numberingMethod == 'automatic') {
							// Use automatic numbering from ZSBMS
							$result = $zsProductSync->createZoneSoftProductInteractive($object, $store, true);
						} else {
							// Use current product reference
							$result = $zsProductSync->createZoneSoftProductInteractive($object, $store, false);
						}

						if ($result) {
							setEventMessages($langs->trans("ProductCreatedInZoneSoft"), null, 'mesgs');
						} else {
							$error = $zsProductSync->error ? $zsProductSync->error : 'Unknown error';
							setEventMessages($langs->trans("ErrorCreatingProductInZoneSoft") . ': ' . $error, null, 'errors');
						}
					} catch (Exception $e) {
						setEventMessages('Error creating product in ZSBMS: ' . $e->getMessage(), null, 'errors');
						dol_syslog("KreaFlow: Exception in sync_from_add: " . $e->getMessage(), LOG_ERR);
					}
				} else {
					dol_syslog("KreaFlow: User chose not to create product in ZSBMS", LOG_INFO);
				}

				// Clear session data if it exists
				if (session_status() == PHP_SESSION_NONE) {
					session_start();
				}
				unset($_SESSION['zsbms_create_product_id']);
				unset($_SESSION['zsbms_create_product_ref']);
				unset($_SESSION['zsbms_create_store']);

				// Redirect back to product page
				header('Location: ' . DOL_URL_ROOT . '/product/card.php?id=' . $productId);
				exit;
			} else {
				dol_syslog("KreaFlow: No valid product ID for sync_from_add action", LOG_ERR);
			}
		}

		// Check if we should show the ZSBMS creation prompt
		if (!$zsbmsProductAutoSyncDisabled && isset($_GET['show_zsbms_prompt']) && $_GET['show_zsbms_prompt'] == '1') {
			if (session_status() == PHP_SESSION_NONE) {
				session_start();
			}

			// Get product ID from URL parameter or object
			$currentProductId = !empty($_GET['id']) ? (int)$_GET['id'] : $object->id;


			// Check if product ID matches the session
			if (isset($_SESSION['zsbms_create_product_id']) && $_SESSION['zsbms_create_product_id'] == $currentProductId) {
				dol_syslog("KreaFlow: Marking prompt for display for product {$currentProductId}", LOG_INFO);

				// Ensure object has the correct ID
				if (empty($object->id)) {
					$object->id = $currentProductId;
				}

				// Check if product already exists in ZSBMS using API (not just sync table)
				$store = $conf->global->ZS_API_STORE;

				// First, get the product reference to check in ZSBMS
				require_once DOL_DOCUMENT_ROOT . '/product/class/product.class.php';
				$productToCheck = new Product($db);
				$fetchResult = $productToCheck->fetch($currentProductId);

				$existsInZSBMS = false;
				$productRef = '';

				if ($fetchResult > 0) {
					// Get product reference - try multiple sources for reliability
					$productRef = $productToCheck->ref;
					$refSource = 'object';

					if (empty($productRef)) {
						// Try session data first (from trigger)
						if (!empty($_SESSION['zsbms_create_product_ref']) && $_SESSION['zsbms_create_product_id'] == $currentProductId) {
							$productRef = $_SESSION['zsbms_create_product_ref'];
							$refSource = 'session data';
						} else {
							// If ref is empty, try to get it directly from database with force refresh
							$sql = "SELECT ref FROM " . MAIN_DB_PREFIX . "product WHERE rowid = " . (int)$currentProductId;
							$resql = $db->query($sql);
							if ($resql && $db->num_rows($resql) > 0) {
								$obj = $db->fetch_object($resql);
								$productRef = $obj->ref;
								$refSource = 'database query';
							}
						}
					}

					dol_syslog("KreaFlow: Product ID {$currentProductId} - Fetched ref: '{$productRef}' (from {$refSource})", LOG_INFO);

					if (!empty($productRef)) {
						// Check directly in ZSBMS API if product exists
						require_once DOL_DOCUMENT_ROOT . '/custom/kreaflow/class/zsprodsynch.class.php';
						$zsProductSync = new ZSProductSynch($db);

						try {
							dol_syslog("KreaFlow: Checking if product {$productRef} exists in ZSBMS store {$store} via API", LOG_INFO);

							// Check product in specific store
							$zsbmsProduct = $zsProductSync->getZoneSoftProductByIdInStore($productRef, $store);

							// Enhanced logging for debugging

							if ($zsbmsProduct && isset($zsbmsProduct->product) && !empty($zsbmsProduct->product->codigo)) {
								$existsInZSBMS = true;
								dol_syslog("KreaFlow: Product {$productRef} found in ZSBMS store {$store} with code: {$zsbmsProduct->product->codigo}", LOG_INFO);

								// If product exists in ZSBMS but not in sync table, sync it
								$sql = "SELECT * FROM " . MAIN_DB_PREFIX . "dolizsynch_zsproduct WHERE fk_product = " . (int)$currentProductId;
								$sql .= " ORDER BY (loja_zs = " . (int)$store . ") DESC, rowid DESC " . $db->plimit(1);
								$resql = $db->query($sql);
								if ($resql && $db->num_rows($resql) == 0) {
									dol_syslog("KreaFlow: Product exists in ZSBMS store {$store} but not in sync table, updating sync table", LOG_INFO);
									$insertResult = $zsProductSync->insertProductToLocalTable($zsbmsProduct);
									dol_syslog("KreaFlow: Insert to sync table result: " . ($insertResult ? 'success' : 'failed'), LOG_INFO);
								}
							} else {
								if ($zsbmsProduct === false) {
									dol_syslog("KreaFlow: API returned false - product {$productRef} definitely not found in ZSBMS store {$store}", LOG_INFO);
								} elseif ($zsbmsProduct === null) {
									dol_syslog("KreaFlow: API returned null - product {$productRef} not found in ZSBMS store {$store}", LOG_INFO);
								} else {
									dol_syslog("KreaFlow: API returned unexpected response for product {$productRef} in store {$store}. Type: " . gettype($zsbmsProduct) . " | Summary: " . $this->summarizeValueForLog($zsbmsProduct), LOG_WARNING);
								}
							}
						} catch (Exception $e) {
							dol_syslog("KreaFlow: Exception checking product in ZSBMS: " . $this->summarizeExceptionForLog($e), LOG_ERR);
							// On API error, fall back to sync table check
							$sql = "SELECT loja_zs FROM " . MAIN_DB_PREFIX . "dolizsynch_zsproduct WHERE fk_product = " . (int)$currentProductId;
							$resql = $db->query($sql);
							if ($resql && $db->num_rows($resql) > 0) {
								$existsInZSBMS = true;
								dol_syslog("KreaFlow: API check failed, falling back to sync table - product exists", LOG_INFO);
							} else {
								dol_syslog("KreaFlow: API check failed, falling back to sync table - product NOT found", LOG_INFO);
							}
						}
					} else {
						dol_syslog("KreaFlow: Product {$currentProductId} has empty reference, cannot check ZSBMS API", LOG_WARNING);
					}
				}

				if (!$existsInZSBMS) {
					// Product doesn't exist in ZSBMS, show the prompt
					dol_syslog("KreaFlow: Product {$productRef} (ID: {$currentProductId}) not found in ZSBMS, showing creation prompt", LOG_INFO);
					$this->showPrompt = true;
					$this->promptProductId = $currentProductId;
					$this->promptObject = $object;
				} else {
					// Product already exists in ZSBMS, skip the prompt
					dol_syslog("KreaFlow: Product {$productRef} (ID: {$currentProductId}) already exists in ZSBMS, skipping creation prompt", LOG_INFO);
				}

				// Clear the session data
				unset($_SESSION['zsbms_create_product_id']);
				unset($_SESSION['zsbms_create_product_ref']);
				unset($_SESSION['zsbms_create_store']);
			}
		}

		$storeProducts = $conf->global->ZS_API_STORE;

		require_once DOL_DOCUMENT_ROOT . '/custom/kreaflow/class/zsprodsynch.class.php';

		// Get the stores from ZS_STORES_PRODUCT_CHANGES configuration
		$storesProductChangesString = $conf->global->ZS_STORES_PRODUCT_CHANGES;
		$storesProductChangesArray = [];
		if (!empty($storesProductChangesString)) {
			$storesProductChangesArray = explode(',', $storesProductChangesString);
			$storesProductChangesArray = array_map('trim', $storesProductChangesArray);
			$storesProductChangesArray = array_map('intval', $storesProductChangesArray);
		}
		list($defaultBackgroundColor, $defaultFontColor) = $this->getDefaultProductButtonColors($conf);

		$fk_product = $object->id;
		$descricaocurta = GETPOST('DOLIZSYNCH_DESCRICAO_CURTA', 'alphanohtml');
		$codigopp = GETPOST('DOLIZSYNCH_CODIGOPP', 'alphanohtml');
		// Validate PLU maximum value
		if (!empty($codigopp) && is_numeric($codigopp) && intval($codigopp) > 999999) {
			$codigopp = '999999'; // Cap at maximum value
		}
		// Codbarras (max 20 chars in database)
		$codbarras = GETPOST('DOLIZSYNCH_CODBARRAS', 'alphanohtml');
		if (strlen($codbarras) > 20) {
			setEventMessages($langs->trans("WarningBarcodeWasTruncated", strlen($codbarras), 20), null, 'warnings');
			dol_syslog("KreaFlow: Barcode truncated from " . strlen($codbarras) . " to 20 characters", LOG_WARNING);
			$codbarras = substr($codbarras, 0, 20);
		}
		// Note: retalho is now handled per-company via radio buttons in retalho_for_company_X fields
		$composto_raw = GETPOST('DOLIZSYNCH_ZS_COMPOSTO', 'alphanohtml');
		$composto = ($composto_raw !== '' && is_numeric($composto_raw)) ? $composto_raw : '4'; // Default: Venda (Sem Stock)
		$balanca_raw = GETPOST('DOLIZSYNCH_ZS_BALANCA', 'alphanohtml');
		$balanca = ($balanca_raw !== '' && is_numeric($balanca_raw)) ? $balanca_raw : '0';
		$quebraFimDeDia_value = GETPOST('DOLIZSYNCH_ZS_QUEBRA_FIM_DIA') ? '1' : '0';
		$venderSemStock_value = GETPOST('DOLIZSYNCH_ZS_VENDER_SEM_STOCK') ? '1' : '0';
		$retencao_value = GETPOST('DOLIZSYNCH_ZS_RETENCAO') ? '1' : '0';
		$isencao = GETPOST('DOLIZSYNCH_ZS_ISENCAO', 'alphanohtml');
		$iva2_raw = GETPOST('DOLIZSYNCH_ZS_IVA2', 'alphanohtml');
		$ivacompra_raw = GETPOST('DOLIZSYNCH_ZS_IVACOMPRA', 'alphanohtml');
		$ivarevenda_raw = GETPOST('DOLIZSYNCH_ZS_IVAREVENDA', 'alphanohtml');
		$iva2_norm = preg_replace('/\s*\(.*\)/', '', (string) $iva2_raw);
		$ivacompra_norm = preg_replace('/\s*\(.*\)/', '', (string) $ivacompra_raw);
		$ivarevenda_norm = preg_replace('/\s*\(.*\)/', '', (string) $ivarevenda_raw);
		$iva2_norm = preg_replace('/\*/', '', $iva2_norm);
		$ivacompra_norm = preg_replace('/\*/', '', $ivacompra_norm);
		$ivarevenda_norm = preg_replace('/\*/', '', $ivarevenda_norm);
		if (function_exists('price2num')) {
			$iva2_value = price2num($iva2_norm, 'MU');
			$ivacompra_value = price2num($ivacompra_norm, 'MU');
			$ivarevenda_value = price2num($ivarevenda_norm, 'MU');
		} else {
			$iva2_value = (float) str_replace(',', '.', (string) $iva2_norm);
			$ivacompra_value = (float) str_replace(',', '.', (string) $ivacompra_norm);
			$ivarevenda_value = (float) str_replace(',', '.', (string) $ivarevenda_norm);
		}
		$fk_product_value = is_numeric($fk_product) ? $fk_product : 'NULL';

		// Handle additional form fields that are used in UPDATE queries
		$savedReferencia = GETPOST('DOLIZSYNCH_REFERENCIA', 'alphanohtml');
		$savedPrepagamento = GETPOST('DOLIZSYNCH_PREPAGAMENTO') ? '1' : '0';
		$savedTopo = GETPOST('DOLIZSYNCH_TOPO') ? '1' : '0';
		$savedRevenda = GETPOST('DOLIZSYNCH_REVENDA') ? '1' : '0';
		$savedExcluirdescontos = GETPOST('DOLIZSYNCH_EXCLUIRDESCONTOS') ? '1' : '0';
		$savedEmentaDigital = GETPOST('DOLIZSYNCH_EMENTADIGITAL') ? '1' : '0';
		$savedConsumoMinimo = GETPOST('DOLIZSYNCH_CONSUMOMINIMO') ? '1' : '0';
		$savedFuel = GETPOST('DOLIZSYNCH_FUEL') ? '1' : '0';
		$savedTransferivel = GETPOST('DOLIZSYNCH_TRANSFERIVEL') ? '1' : '0';
		$savedTempoPrep = $this->extractPrepTimeFromSource(array(
			'DOLIZSYNCH_TEMPOPREP_HOUR'   => GETPOST('DOLIZSYNCH_TEMPOPREP_HOUR', 'int'),
			'DOLIZSYNCH_TEMPOPREP_MINUTE' => GETPOST('DOLIZSYNCH_TEMPOPREP_MINUTE', 'int'),
			'DOLIZSYNCH_TEMPOPREP_SECOND' => GETPOST('DOLIZSYNCH_TEMPOPREP_SECOND', 'int'),
			'DOLIZSYNCH_TEMPOPREP'        => GETPOST('DOLIZSYNCH_TEMPOPREP', 'alpha'),
		));
		// Niveis Menu (ZS)
		$savedNiveismenu = GETPOST('DOLIZSYNCH_ZS_NIVEISMENU', 'none');
		$savedProdutosCodigosBarras = GETPOST('DOLIZSYNCH_PRODUTOS_CODIGOSBARRAS', 'none');
		$savedProdutosPropriedades = GETPOST('DOLIZSYNCH_ZS_PRODUCT_PROPERTIES', 'none');

		// Initialize $retalhoValuesJson
		$retalhoValuesJson = '{}'; // Default to empty JSON object

		// Fetch $TFieldRetalho if not already defined
		$TFieldRetalho = array(
			'1' => $langs->trans('DoliZSynchRetalhoVendidoClienteFinal'),
			'0' => $langs->trans('DoliZSynchRetalhoSoBackOffice'),
			'2' => $langs->trans('DoliZSynchRetalhoDescontinuado'),
		);

		// Handle retalho per-company radio button values (copied from working edit logic)
		$retalhoValuesJson = '{}'; // Default empty JSON object
		$retalhoValuesArray = array();
		foreach ($_POST as $key => $value) {
			if (strpos($key, 'retalho_for_company_') === 0) {
				$companyId = str_replace('retalho_for_company_', '', $key);
				if (!ctype_digit((string) $companyId)) {
					continue; // ignore keys with non-numeric company IDs
				}
				$retalhoCode = (int) $value;
				if ($retalhoCode < 0 || $retalhoCode > 2) {
					$retalhoCode = 1;
				}
				$retalhoValuesArray[(int) $companyId] = $retalhoCode;
			}
		}
		if (!empty($retalhoValuesArray)) {
			$retalhoValuesJson = json_encode($retalhoValuesArray);
		}

		// Existing availability map from DB (fallback for stores not present in current POST payload)
		$existingRetalhoValuesArray = array();
		if ((int) $object->id > 0) {
			$sqlExistingRetalho = "SELECT retalho_by_store FROM " . MAIN_DB_PREFIX . "dolizsynch_zsproduct";
			$sqlExistingRetalho .= " WHERE fk_product = " . ((int) $object->id);
			if ((int) $storeProducts > 0) {
				$sqlExistingRetalho .= " ORDER BY (loja_zs = " . ((int) $storeProducts) . ") DESC, rowid DESC";
			} else {
				$sqlExistingRetalho .= " ORDER BY rowid DESC";
			}
			$sqlExistingRetalho .= " " . $db->plimit(1);
			$resExistingRetalho = $db->query($sqlExistingRetalho);
			if ($resExistingRetalho && ($objExistingRetalho = $db->fetch_object($resExistingRetalho))) {
				$decodedExistingRetalho = json_decode((string) $objExistingRetalho->retalho_by_store, true);
				if (json_last_error() === JSON_ERROR_NONE && is_array($decodedExistingRetalho)) {
					foreach ($decodedExistingRetalho as $storeId => $retalhoCode) {
						$storeId = trim((string) $storeId);
						if ($storeId === '' || !ctype_digit($storeId)) {
							continue;
						}
						$retalhoCode = (int) $retalhoCode;
						if ($retalhoCode < 0 || $retalhoCode > 2) {
							$retalhoCode = 1;
						}
						$existingRetalhoValuesArray[(int) $storeId] = $retalhoCode;
					}
				}
			}
		}

		// Handle other form fields
		$backgroundColorValue = $this->normalizeHexColor(GETPOST('DOLIZSYNCH_ZS_BACKGROUND_COLOR', 'alphanohtml'), $defaultBackgroundColor);
		$fontColorValue = $this->normalizeHexColor(GETPOST('DOLIZSYNCH_ZS_FONT_COLOR', 'alphanohtml'), $defaultFontColor);
		$tiposaft = GETPOST('DOLIZSYNCH_ZS_TIPO_PRODUTO', 'alpha') ?: 'P';
		$unidade_raw = GETPOST('DOLIZSYNCH_PRODUCT_UNIDADE', 'int');
		$unidade = ($unidade_raw !== '' && is_numeric($unidade_raw)) ? $unidade_raw : (!empty($conf->global->ZS_DEFAULT_PRODUCT_UNIT) ? $conf->global->ZS_DEFAULT_PRODUCT_UNIT : 1);
		$uncompra_raw = GETPOST('DOLIZSYNCH_PRODUCT_UNCOMPRA', 'int');
		$uncompra = ($uncompra_raw !== '' && is_numeric($uncompra_raw)) ? $uncompra_raw : $unidade;
		$uninventario_raw = GETPOST('DOLIZSYNCH_PRODUCT_UNINVENTARIO', 'int');
		$uninventario = ($uninventario_raw !== '' && is_numeric($uninventario_raw)) ? $uninventario_raw : $unidade;

		// Handle family and subfamily
		$familia = 0;
		$subfam = 0;
		$familyRaw = GETPOST('DOLIZSYNCH_ZS_FAMILY', 'alphanohtml');
		if ($familyRaw !== '') {
			$familyParts = explode('_', $familyRaw);
			$familia = isset($familyParts[0]) && is_numeric($familyParts[0]) ? $familyParts[0] : (!empty($conf->global->ZS_DEFAULT_PRODUCT_FAMILY) ? $conf->global->ZS_DEFAULT_PRODUCT_FAMILY : 0);
			$subfam = isset($familyParts[1]) && is_numeric($familyParts[1]) ? $familyParts[1] : 0;
		} else {
			$familia = !empty($conf->global->ZS_DEFAULT_PRODUCT_FAMILY) ? $conf->global->ZS_DEFAULT_PRODUCT_FAMILY : 0;
		}

		// Handle category and subcategory
		$categoria = 0;
		$subcategoria = 0;
		$categoryRaw = GETPOST('DOLIZSYNCH_ZS_CATEGORY', 'alphanohtml');
		if ($categoryRaw !== '') {
			$categoryParts = explode('_', $categoryRaw);
			$categoria = isset($categoryParts[0]) && is_numeric($categoryParts[0]) ? $categoryParts[0] : (!empty($conf->global->ZS_DEFAULT_PRODUCT_CATEGORY) ? $conf->global->ZS_DEFAULT_PRODUCT_CATEGORY : 0);
			$subcategoria = isset($categoryParts[1]) && is_numeric($categoryParts[1]) ? $categoryParts[1] : 0;
		} else {
			$categoria = !empty($conf->global->ZS_DEFAULT_PRODUCT_CATEGORY) ? $conf->global->ZS_DEFAULT_PRODUCT_CATEGORY : 0;
		}

		// Handle group
		$grupo = (int) GETPOST('DOLIZSYNCH_ZS_GROUP', 'int');

		// Set value variables for SQL
		$familia_value = $familia ? $familia : 'NULL';
		// Keep explicit zero for subfamily/subcategory to match option values like "family_0" on reload
		$subfam_value = is_numeric($subfam) ? (int) $subfam : 0;
		$categoria_value = $categoria ? $categoria : 'NULL';
		$subcategoria_value = is_numeric($subcategoria) ? (int) $subcategoria : 0;
		$grupo_value = is_numeric($grupo) ? (int) $grupo : 0;
		$unidade_value = is_numeric($unidade) ? $unidade : 'NULL';
		$uncompra_value = is_numeric($uncompra) ? $uncompra : 'NULL';
		$uninventario_value = is_numeric($uninventario) ? $uninventario : 'NULL';

		// Handle product companies
		$productCompanyRaw = GETPOST('DOLIZSYNCH_ZS_PRODUCT_COMPANY', 'array');
		if (!empty($productCompanyRaw) && is_array($productCompanyRaw)) {
			$product_company = array_map('trim', $productCompanyRaw);
			$product_company_escaped = array_map([$db, 'escape'], $product_company);
			$product_company_value = implode(',', $product_company_escaped);
		} else {
			// For new products, use stores from ZS_STORES_PRODUCT_CHANGES or fallback to existing logic
			if ($action == 'create' && !empty($storesProductChangesArray)) {
				$product_company = $storesProductChangesArray;
				$product_company_escaped = array_map([$db, 'escape'], $product_company);
				$product_company_value = implode(',', $product_company_escaped);
			} else {
				// For updates, try to get from database first
				$sql = "SELECT * FROM " . MAIN_DB_PREFIX . "dolizsynch_zsproduct WHERE fk_product = " . (int)$object->id;
				if ((int) $storeProducts > 0) {
					$sql .= " ORDER BY (loja_zs = " . ((int) $storeProducts) . ") DESC, rowid DESC";
				} else {
					$sql .= " ORDER BY rowid DESC";
				}
				$sql .= " " . $db->plimit(1);
				$resql = $db->query($sql);

				if ($resql) {
					$obj = $db->fetch_object($resql);
					if ($obj) {
						$product_company_string = $obj->product_company;
						// Convert the string to an array by splitting on commas
						$product_company = explode(',', $product_company_string);
						// Trim spaces from the elements
						$product_company = array_map('trim', $product_company);
						$product_company_escaped = array_map([$db, 'escape'], $product_company);
						$product_company_value = implode(',', $product_company_escaped);
					} else {
						$product_company = [];
						$product_company_value = '';
						dol_syslog("No product company found in the database.", LOG_WARNING);
					}
				} else {
					$product_company = [];
					$product_company_value = '';
					dol_syslog("Database query failed.", LOG_ERR);
				}
			}
		}

		// Keep retalho_by_store aligned with selected product_company stores and preserve existing values when a store key is missing in POST.
		$allowedStores = array();
		if (!empty($product_company) && is_array($product_company)) {
			foreach ($product_company as $storeId) {
				$storeId = trim((string) $storeId);
				if ($storeId !== '' && ctype_digit($storeId)) {
					$allowedStores[(int) $storeId] = true;
				}
			}
		}
		if (!empty($allowedStores)) {
			$mergedRetalhoValues = array();
			foreach (array_keys($allowedStores) as $storeId) {
				if (array_key_exists($storeId, $retalhoValuesArray)) {
					$mergedRetalhoValues[(int) $storeId] = (int) $retalhoValuesArray[$storeId];
					continue;
				}
				if (array_key_exists($storeId, $existingRetalhoValuesArray)) {
					$mergedRetalhoValues[(int) $storeId] = (int) $existingRetalhoValuesArray[$storeId];
					continue;
				}
				$mergedRetalhoValues[(int) $storeId] = 1;
			}
			$retalhoValuesArray = $mergedRetalhoValues;
		} else {
			$retalhoValuesArray = array();
		}
		$retalhoValuesJson = !empty($retalhoValuesArray) ? json_encode($retalhoValuesArray) : '{}';

		// Validation: Keep sync flag aligned with selected stores
		if ($_SERVER['REQUEST_METHOD'] === 'POST') {
			$rawSynchValue = GETPOST('options_zs_synch', 'alphanohtml');
			if ($rawSynchValue !== '') {
				$savedSynch = (int) $rawSynchValue;
			} elseif (isset($object->array_options['options_zs_synch'])) {
				$savedSynch = (int)$object->array_options['options_zs_synch'];
			} else {
				$savedSynch = 0;
			}

			// Store info in session to show message after product creation if sync is disabled
			if ($savedSynch === 0 && in_array($action, array('add', 'create'), true)) {
				if (session_status() == PHP_SESSION_NONE) {
					session_start();
				}
				$_SESSION['dolizsynch_product_created_without_sync'] = true;
			}
		}

		if (in_array($action, array('update', 'create', 'add', 'edit'), true) && $object->id && $_SERVER['REQUEST_METHOD'] === 'POST') {

			// Only handle form submissions coming from the DoliZSynch page
			if (!$isDolizsynchPageContext) {
				return 0;
			}

			$menuClearedFlag = (GETPOST('DOLIZSYNCH_ZS_NIVEISMENU_CLEARED', 'int') === 1);

			// Escape string variables
			$descricaocurta_escaped = $db->escape($descricaocurta);
			$backgroundColorValue_escaped = $db->escape($backgroundColorValue);
			$fontColorValue_escaped = $db->escape($fontColorValue);
			$tiposaft_escaped = $db->escape($tiposaft);
			$tempoprep_escaped = $db->escape($savedTempoPrep);

			dol_syslog("KreaFlow: Processing product {$object->ref} with action: $action", LOG_INFO);

			// Determine which stores to create/update records for
			$targetStores = [];
			$zsRecordCreated = false;

			if (in_array($action, array('create', 'add'), true) && !empty($storesProductChangesArray)) {
				// For new products, create in all stores from ZS_STORES_PRODUCT_CHANGES
				$targetStores = $storesProductChangesArray;
				dol_syslog("KreaFlow: Creating new product in all ZS_STORES_PRODUCT_CHANGES stores: " . implode(',', $targetStores), LOG_INFO);
			} else {
				// For updates, use the traditional single store approach
				$targetStores = [$storeProducts];
				dol_syslog("KreaFlow: Updating existing product in store: $storeProducts", LOG_INFO);
			}

			if ((int)$savedSynch === 0) {
				// Keep row, just mark as restricted/disabled without deleting
				$productIdCleanup = (int)$object->id;
				if ($productIdCleanup > 0) {
					$restrictSql = "UPDATE " . MAIN_DB_PREFIX . "dolizsynch_zsproduct SET restricted = 1 WHERE fk_product = " . $productIdCleanup;
					if (!$db->query($restrictSql)) {
						dol_syslog("KreaFlow: Failed to mark dolizsynch_zsproduct as restricted for product {$object->id}. Error: " . $db->lasterror(), LOG_ERR);
					} else {
						dol_syslog("KreaFlow: Marked dolizsynch_zsproduct records as restricted for product {$object->id} because sync is disabled (no deletion performed).", LOG_INFO);
					}

					$deleteEquivSql = "DELETE FROM " . MAIN_DB_PREFIX . "dolizsynch_product_equivalence WHERE fk_product_source = " . $productIdCleanup . " OR fk_product_dest = " . $productIdCleanup;
					if (!$db->query($deleteEquivSql)) {
						dol_syslog("KreaFlow: Failed to delete dolizsynch_product_equivalence rows for product {$object->id}. Error: " . $db->lasterror(), LOG_ERR);
					} else {
						dol_syslog("KreaFlow: Removed dolizsynch_product_equivalence records for product {$object->id} because sync is disabled.", LOG_INFO);
					}
				}
			} else {
				// Process each target store
				foreach ($targetStores as $currentStore) {
					$storeValue = is_numeric($currentStore) ? $currentStore : '0';

					// Compute menu JSON now that POST/DB values are loaded
					$existingMenuFromDb = '';
					$existingProdutosCodigosBarrasFromDb = '';
					$existingProdutosPropriedadesFromDb = null;
					$existingCaracteristicasFromDb = null;
					$existingZsProductCode = '';
					$sqlMenuDb = "SELECT codigo, niveismenu, produtos_codigosbarras, produtos_propriedades, caracteristicas FROM " . MAIN_DB_PREFIX . "dolizsynch_zsproduct";
					$sqlMenuDb .= " WHERE fk_product = " . ((int) $object->id);
					if ((int) $storeValue > 0) {
						$sqlMenuDb .= " ORDER BY (loja_zs = " . ((int) $storeValue) . ") DESC, rowid DESC";
					} else {
						$sqlMenuDb .= " ORDER BY rowid DESC";
					}
					$sqlMenuDb .= " " . $db->plimit(1);
					$resMenuDb = $db->query($sqlMenuDb);
					if ($resMenuDb && ($objMenuDb = $db->fetch_object($resMenuDb))) {
						$existingZsProductCode = trim((string) ($objMenuDb->codigo ?? ''));
						$existingMenuFromDb = (string) $objMenuDb->niveismenu;
						$existingProdutosCodigosBarrasFromDb = (string) $objMenuDb->produtos_codigosbarras;
						$existingProdutosPropriedadesFromDb = $objMenuDb->produtos_propriedades ?? null;
						$existingCaracteristicasFromDb = $objMenuDb->caracteristicas ?? null;
					}

					$niveismenuJson = trim((string) $savedNiveismenu);
					if ($niveismenuJson === '' || strtolower($niveismenuJson) === 'null') {
						// Do not wipe existing menu if nothing was sent by the form
						if (!empty($existingMenuFromDb) && strtolower(trim($existingMenuFromDb)) !== 'null') {
							$niveismenuJson = (string) $existingMenuFromDb;
							dol_syslog("KreaFlow: Menu missing from POST, keeping DB value (len=" . strlen($niveismenuJson) . ")", LOG_WARNING);
						} else {
							$niveismenuJson = '[]';
						}
					}

					// Validate JSON and try robust repairs (HTML entities, escaped quotes, or both).
					$tmpCheck = json_decode($niveismenuJson, true);
					if (json_last_error() !== JSON_ERROR_NONE || !is_array($tmpCheck)) {
						$menuCandidates = array();
						$seenCandidates = array();

						$menuCandidates[] = array('value' => $niveismenuJson, 'source' => 'raw');
						$decodedHtml = html_entity_decode($niveismenuJson, ENT_QUOTES | ENT_HTML5);
						$menuCandidates[] = array('value' => $decodedHtml, 'source' => 'html_entity_decode');
						$menuCandidates[] = array('value' => stripslashes($niveismenuJson), 'source' => 'stripslashes');
						$menuCandidates[] = array('value' => stripslashes($decodedHtml), 'source' => 'html_entity_decode+stripslashes');

						$menuWasRepaired = false;
						foreach ($menuCandidates as $candidate) {
							$candidateValue = (string) $candidate['value'];
							if (isset($seenCandidates[$candidateValue])) {
								continue;
							}
							$seenCandidates[$candidateValue] = true;
							if ($candidateValue === '') {
								continue;
							}

							$tmpCandidate = json_decode($candidateValue, true);
							if (json_last_error() === JSON_ERROR_NONE && is_array($tmpCandidate)) {
								$normalizedCandidate = json_encode($tmpCandidate, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
								$niveismenuJson = ($normalizedCandidate === false ? $candidateValue : $normalizedCandidate);
								$menuWasRepaired = ($candidate['source'] !== 'raw');
								if ($menuWasRepaired) {
									dol_syslog("KreaFlow: Menu JSON repaired using " . $candidate['source'], LOG_WARNING);
								}
								break;
							}
						}

						if (!$menuWasRepaired) {
							if (!empty($existingMenuFromDb)) {
								$niveismenuJson = (string) $existingMenuFromDb;
								dol_syslog("KreaFlow: Invalid menu JSON in POST, keeping DB value (len=" . strlen($niveismenuJson) . ")", LOG_ERR);
							} else {
								$niveismenuJson = '[]';
								dol_syslog("KreaFlow: Invalid menu JSON in POST and no DB fallback, saving empty []", LOG_ERR);
							}
						}
					}

					// Safety: avoid wiping an existing menu if UI sent an empty [] by mistake.
					// Only allow clearing when user explicitly pressed the "Limpar tudo" button (flag=1).
					$tmpCheckFinal = json_decode($niveismenuJson, true);
					if (!$menuClearedFlag && json_last_error() === JSON_ERROR_NONE && is_array($tmpCheckFinal) && count($tmpCheckFinal) === 0) {
						$existingTrim = trim((string) $existingMenuFromDb);
						if ($existingTrim !== '' && strtolower($existingTrim) !== 'null' && $existingTrim !== '[]') {
							$niveismenuJson = (string) $existingMenuFromDb;
							dol_syslog("KreaFlow: POSTed menu is empty [] but DB has non-empty menu; keeping DB menu (set DOLIZSYNCH_ZS_NIVEISMENU_CLEARED=1 to force clear)", LOG_WARNING);
						}
					}

					$niveismenuEscaped = $db->escape($niveismenuJson);
					$produtosCodigosBarrasJson = $this->normalizePostedJsonArrayField(
						$savedProdutosCodigosBarras,
						$existingProdutosCodigosBarrasFromDb,
						'produtos_codigosbarras'
					);
					$decodedProdutosCodigosBarras = $this->decodeJsonArrayField($produtosCodigosBarrasJson);
					$normalizedProdutosCodigosBarrasRows = is_array($decodedProdutosCodigosBarras)
						? $this->normalizeProdutosCodigosBarrasRows($decodedProdutosCodigosBarras)
						: array();
					$produtosCodigosBarrasSqlValue = 'NULL';
					if (!empty($normalizedProdutosCodigosBarrasRows)) {
						$produtosCodigosBarrasJson = json_encode(
							$normalizedProdutosCodigosBarrasRows,
							JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
						);
						if ($produtosCodigosBarrasJson !== false) {
							$produtosCodigosBarrasSqlValue = "'" . $db->escape($produtosCodigosBarrasJson) . "'";
						}
					}

					$produtosPropriedadesSqlValue = $existingProdutosPropriedadesFromDb === null
						? 'NULL'
						: "'" . $db->escape((string) $existingProdutosPropriedadesFromDb) . "'";
					if (trim((string) $savedProdutosPropriedades) !== '') {
						$productCodeForProperties = $existingZsProductCode !== '' ? $existingZsProductCode : (string) $object->ref;
						$allowedPropertyIds = !empty($parameters['zs_property_dictionary_loaded']) && !empty($parameters['zs_property_ids']) && is_array($parameters['zs_property_ids'])
							? $parameters['zs_property_ids']
							: array();
						try {
							$normalizedPropertyRows = ZSProductPropertyService::normalizeRelations(
								$savedProdutosPropriedades,
								$productCodeForProperties,
								$allowedPropertyIds
							);
							if (!empty($normalizedPropertyRows) && ZSProductPropertyService::hasRows($existingCaracteristicasFromDb)) {
								throw new InvalidArgumentException($langs->trans('DoliZSynchProductPropertiesCharacteristicsConflict'));
							}
							$normalizedPropertiesJson = json_encode(
								$normalizedPropertyRows,
								JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
							);
							if ($normalizedPropertiesJson === false) {
								throw new RuntimeException('Could not encode the product property relations.');
							}
							$produtosPropriedadesSqlValue = "'" . $db->escape($normalizedPropertiesJson) . "'";
						} catch (Exception $e) {
							setEventMessages($langs->trans('Error') . ': ' . $e->getMessage(), null, 'errors');
							dol_syslog(__METHOD__ . ': Rejected product properties for product ' . $object->ref . ': ' . $e->getMessage(), LOG_WARNING);
						}
					}

					// Always use the properly constructed JSON from form data (no defaults)
					$currentRetalhoJson = $retalhoValuesJson;
					$currentRetalhoJsonEscaped = $db->escape($currentRetalhoJson);

					// Check if record exists for this product
					$sql_check = "SELECT 1 FROM " . MAIN_DB_PREFIX . "dolizsynch_zsproduct WHERE fk_product = $fk_product_value";
					$sql_check .= " " . $db->plimit(1);
					$resql_check = $db->query($sql_check);

					if ($resql_check) {
						$num_rows = $db->num_rows($resql_check);
						if ($num_rows > 0) {
							// Record exists - UPDATE only if this is not a creation action
							if ($action != 'create') {
								$sql = "UPDATE " . MAIN_DB_PREFIX . "dolizsynch_zsproduct SET ";
								$sql .= "descricaocurta = '$descricaocurta_escaped', ";
								$sql .= "loja_zs = " . (int)$storeValue . ", ";
								$sql .= "codigopp = " . (empty($codigopp) ? "NULL" : "'" . $db->escape($codigopp) . "'") . ", ";
								$sql .= "codbarras = '" . $db->escape($codbarras) . "', ";
								$sql .= "produtos_codigosbarras = " . $produtosCodigosBarrasSqlValue . ", ";
								$sql .= "produtos_propriedades = " . $produtosPropriedadesSqlValue . ", ";
								$sql .= "retalho_by_store = '" . $currentRetalhoJsonEscaped . "', ";
								$sql .= "composto = $composto, ";
								$sql .= "balanca = $balanca, ";
								$sql .= "isencao = '" . $db->escape($isencao) . "', ";
								$sql .= "iva2 = " . $iva2_value . ", ";
								$sql .= "ivacompra = " . $ivacompra_value . ", ";
								$sql .= "ivarevenda = " . $ivarevenda_value . ", ";
								$sql .= "familia = $familia_value, ";
								$sql .= "subfam = $subfam_value, ";
								$sql .= "categoria = $categoria_value, ";
								$sql .= "subcategoria = $subcategoria_value, ";
								$sql .= "grupo = $grupo_value, ";
								$sql .= "product_company = '$product_company_value', ";
								$sql .= "autoquebra = $quebraFimDeDia_value, ";
								$sql .= "vendersemstock = $venderSemStock_value, ";
								$sql .= "retencao = $retencao_value, ";
								$sql .= "unidade = $unidade_value, ";
								$sql .= "uncompra = $uncompra_value, ";
								$sql .= "uninventario = $uninventario_value, ";
								$sql .= "tempoprep = '" . $tempoprep_escaped . "', ";
								$sql .= "fundo = '$backgroundColorValue_escaped', ";
								$sql .= "letra = '$fontColorValue_escaped', ";
								$sql .= "tiposaft = '$tiposaft_escaped', ";
								$sql .= "referencia = '" . $db->escape($savedReferencia) . "', ";
								$sql .= "niveismenu = '" . $niveismenuEscaped . "', ";

								// Debug log the values before using them

								$sql .= "prepagamento = " . (int)$savedPrepagamento . ", ";
								$sql .= "topo = " . (int)$savedTopo . ", ";
								$sql .= "revenda = " . (int)$savedRevenda . ", ";
								$sql .= "excluirdescontos = " . (int)$savedExcluirdescontos . ", ";
								$sql .= "consumominimo = " . (int)$savedConsumoMinimo . ", ";
								$sql .= "ementadigital = " . (int)$savedEmentaDigital . ", ";
								$sql .= "fuel = " . (int)$savedFuel . ", ";
								$sql .= "transferivel = " . (int)$savedTransferivel . ", ";
								$sql .= "date_creation = IF(date_creation IS NULL, NOW(), date_creation), ";
								$sql .= "fk_user_modif = " . $userId . ", ";
								$sql .= "restricted = " . (int)($savedSynch ? 0 : 1) . " ";
								$sql .= "WHERE fk_product = $fk_product_value";
							} else {
								dol_syslog("KreaFlow: Record already exists for product {$object->ref} in store $currentStore, skipping duplicate creation", LOG_INFO);
								continue;
							}
						} else {
							// Record doesn't exist - INSERT
							$sql = "INSERT INTO " . MAIN_DB_PREFIX . "dolizsynch_zsproduct (
                        fk_product,
                        loja_zs,
                        descricaocurta,
						codigopp,
						codbarras,
						produtos_codigosbarras,
						produtos_propriedades,
						retalho_by_store,
                        composto,
                        balanca,
                        isencao,
                        iva2,
                        ivacompra,
                        ivarevenda,
                        familia,
                        subfam,
                        categoria,
                        subcategoria,
                        grupo,
                        product_company,
                        autoquebra,
                        vendersemstock,
                        retencao,
                        unidade,
                        uncompra,
                        uninventario,
                        tempoprep,
                        fundo,
                        letra,
                        tiposaft,
                        referencia,
                        niveismenu,
                        prepagamento,
                        topo,
                        revenda,
                        excluirdescontos,
                        consumominimo,
                        ementadigital,
                        fuel,
                        transferivel,
                        restricted,
                        date_creation,
                        fk_user_creat,
                        fk_user_modif
                    ) VALUES (
                        $fk_product_value,
                        $storeValue,
                        '$descricaocurta_escaped',
						" . (empty($codigopp) ? "NULL" : "'" . $db->escape($codigopp) . "'") . ",
						'" . $db->escape($codbarras) . "',
							" . $produtosCodigosBarrasSqlValue . ",
							" . $produtosPropriedadesSqlValue . ",
						'$currentRetalhoJsonEscaped',
                        $composto,
                        $balanca,
                        '" . $db->escape($isencao) . "',
                        $iva2_value,
                        $ivacompra_value,
                        $ivarevenda_value,
                        $familia_value,
                        $subfam_value,
                        $categoria_value,
                        $subcategoria_value,
                        $grupo_value,
                        '$product_company_value',
                        $quebraFimDeDia_value,
                        $venderSemStock_value,
                        $retencao_value,
                        $unidade_value,
                        $uncompra_value,
                        $uninventario_value,
                        '" . $tempoprep_escaped . "',
                        '$backgroundColorValue_escaped',
                        '$fontColorValue_escaped',
                        '$tiposaft_escaped',
                        '" . $db->escape($savedReferencia) . "',
                        '" . $niveismenuEscaped . "',
                        " . (int)$savedPrepagamento . ",
                        " . (int)$savedTopo . ",
                        " . (int)$savedRevenda . ",
                        " . (int)$savedExcluirdescontos . ",
                        " . (int)$savedConsumoMinimo . ",
                        " . (int)$savedEmentaDigital . ",
                        " . (int)$savedFuel . ",
                        " . (int)$savedTransferivel . ",
                        " . (int)($savedSynch ? 0 : 1) . ",
                        NOW(),
                        " . $userId . ",
                        " . $userId . "
                    )";

							$zsRecordCreated = true;
						}

						// Execute the query
						$resql = $db->query($sql);
						if (!$resql) {
							dol_syslog("KreaFlow: Database error for product {$object->ref} in store $currentStore: " . $db->lasterror(), LOG_ERR);
							dol_print_error($db);
						} else {
							$this->syncKreaAliasMirror((int) $object->id, $descricaocurta);

							// After successful database update, sync changes to ZSBMS
							if ($action == 'update' && !empty($savedSynch)) {
								require_once DOL_DOCUMENT_ROOT . '/custom/kreaflow/class/zsprodsynch.class.php';
								$zsProductSync = new ZSProductSynch($db);

								// Reload the product object to get fresh data
								$object->fetch($object->id);
								$object->fetch_optionals();

								$syncResult = $zsProductSync->updateZsbmsProduct($object);
								if ($syncResult) {
									dol_syslog("KreaFlow: Product {$object->ref} successfully synced to ZSBMS", LOG_INFO);
									setEventMessages($langs->trans("ProductUpdatedInZSBMS"), null, 'mesgs');
								} else {
									dol_syslog("KreaFlow: Failed to sync product {$object->ref} to ZSBMS: " . $zsProductSync->error, LOG_WARNING);
									if (!empty($zsProductSync->error)) {
										setEventMessages($langs->trans("WarningProductNotSyncedToZSBMS") . ': ' . $zsProductSync->error, null, 'warnings');
									}
								}
							}

							// Since product data is no longer store-specific, break after first successful update
							if ($action != 'create') {
								break;
							}

							// Show success message only for the first store to avoid spam
							if ($currentStore == $targetStores[0]) {
								$storeCount = count($targetStores);
								if ($action == 'create' && $storeCount > 1) {
									setEventMessages($langs->trans("ProductCreatedInMultipleStores", $storeCount), null, 'mesgs');
								}
							}
						}
					} else {
						dol_syslog("KreaFlow: Error checking existence of record for store $currentStore: " . $db->lasterror(), LOG_ERR);
					}
				}
			}

			// Note: redirects to forceSync are now triggered only from the checkbox JS (sync toggle).
		}

		// Handle the action when user confirms creation of ZoneSoft product
		if ($action == 'create_zone_soft_product' && GETPOST('confirm', 'alpha') !== '') {

			// Check if we have a valid product_company array
			if (!empty($product_company) && is_array($product_company)) {
				foreach ($product_company as $company) {
					// Call the createZoneSoftProduct method for each product company
					$zsProductSync = new ZSProductSynch($db);
					$result = $zsProductSync->createZoneSoftProduct($object, $company);

					if (isset($result) && $result === true) {
						setEventMessages($langs->trans("ProductCreatedInZoneSoft"), null, 'mesgs');
					} else {
						$error_msg = isset($zsProductSync->error) ? $zsProductSync->error : '';
						setEventMessages($langs->trans("ErrorCreatingProductInZoneSoft") . ': ' . $error_msg, null, 'errors');
					}
				}
			} else {
				dol_syslog("No product companies to process.", LOG_WARNING);
			}

			// Redirect back to the product page
			header("Location: " . $_SERVER['PHP_SELF'] . "?id=" . $object->id);
			exit;
		}

		return 0;
	}

	/**
	 * Hide internal invoice extra field on invoice card.
	 *
	 * @param array        $parameters   Hook parameters
	 * @param CommonObject $object       Current object
	 * @param string       $action       Current action
	 * @param HookManager  $hookmanager  Hook manager
	 * @return int
	 */
	public function formConfirm($parameters, &$object, &$action, $hookmanager)
	{
		$context = isset($parameters['context']) ? (string) $parameters['context'] : '';
		if (strpos($context, 'invoicecard') === false) {
			return 0;
		}

		if (
			!is_object($object)
			|| !property_exists($object, 'element')
			|| $object->element !== 'facture'
			|| empty($object->id)
		) {
			return 0;
		}

		// Always hide internal flag "created_without_zs" on invoice UI.
		$this->resprints = '<script nonce="' . getNonce() . '">(function(){'
			. 'var cells=document.querySelectorAll("td[id^=\\"facture_extras_created_without_zs_\\"]");'
			. 'for(var i=0;i<cells.length;i++){var tr=cells[i].closest("tr");if(tr){tr.style.display="none";}}'
			. 'var input=document.getElementById("options_created_without_zs");'
			. 'if(input){var tr2=input.closest("tr");if(tr2){tr2.style.display="none";}}'
			. '})();</script>';

		return 0;
	}

	/**
	 * Display immutable package-conversion evidence below a converted supplier invoice line.
	 *
	 * @param array        $parameters Hook parameters
	 * @param CommonObject $object     Supplier invoice
	 * @param string       $action     Current action
	 * @param HookManager  $hookmanager Hook manager
	 * @return int
	 */
	public function objectLineView_BeforeProductExtrafield($parameters, &$object, &$action, $hookmanager)
	{
		global $conf, $langs;

		$this->resprints = '';
		$line = $parameters['line'] ?? null;
		if (!is_object($object) || ($object->element ?? '') !== 'invoice_supplier'
			|| empty($object->id) || (int) ($object->entity ?? 0) !== (int) $conf->entity
			|| !is_object($line) || empty($line->id)) {
			return 0;
		}

		$invoiceId = (int) $object->id;
		if (!array_key_exists($invoiceId, $this->propertyStockPlansByInvoice)) {
			$stockService = new ZSPropertyStockService($this->db);
			$this->propertyStockPlansByInvoice[$invoiceId] = $stockService->fetchInvoicePlans($invoiceId, (int) $conf->entity);
			if ($stockService->error !== '') {
				dol_syslog(__METHOD__ . ': ' . $stockService->error, LOG_ERR);
			}
		}

		$lineId = (int) $line->id;
		if (empty($this->propertyStockPlansByInvoice[$invoiceId][$lineId])) {
			return 0;
		}

		$langs->load('dolizsynch@kreaflow');
		$plan = $this->propertyStockPlansByInvoice[$invoiceId][$lineId];
		$status = (int) $plan['status'];
		$statusKey = $status === 1
			? 'DoliZSynchPropertyStockAuditApplied'
			: ($status === 2 ? 'DoliZSynchPropertyStockAuditReview' : 'DoliZSynchPropertyStockAuditPending');
		$statusClass = $status === 1 ? 'badge-status6' : ($status === 2 ? 'badge-status8' : 'badge-status4');

		$propertyName = trim((string) $plan['property_name']);
		for ($pass = 0; $pass < 3; $pass++) {
			$decodedName = html_entity_decode($propertyName, ENT_QUOTES | ENT_HTML5, 'UTF-8');
			if ($decodedName === $propertyName) {
				break;
			}
			$propertyName = $decodedName;
		}
		$packageCount = price((float) $plan['package_count'], 0, $langs, 0, 0, -1);
		$unitsPerPackage = price((float) $plan['units_per_package'], 0, $langs, 0, 0, -1);
		$targetUnits = price((float) $plan['target_units'], 0, $langs, 0, 0, -1);
		$bomName = trim((string) $plan['bom_ref'] . ' - ' . (string) $plan['bom_label'], ' -');
		if ($bomName === '') {
			$bomName = '#' . (int) $plan['fk_bom'];
		}
		$outputName = trim((string) $plan['output_product_ref'] . ' - ' . (string) $plan['output_product_label'], ' -');
		if ($outputName === '') {
			$outputName = '#' . (int) $plan['fk_product_output'];
		}

		$html = '<div class="clearboth"></div><div class="small" style="margin-top:6px">';
		$html .= '<span class="fa fa-archive pictofixedwidth" aria-hidden="true"></span>';
		$html .= '<strong>' . dol_escape_htmltag($langs->trans('DoliZSynchPropertyStockAuditTitle')) . '</strong> ';
		$html .= '<span class="badge ' . $statusClass . '">' . dol_escape_htmltag($langs->trans($statusKey)) . '</span>';
		$html .= '<div><span class="opacitymedium">' . dol_escape_htmltag($propertyName) . ' (#' . (int) $plan['property_id'] . '):</span> ';
		$html .= dol_escape_htmltag($langs->trans(
			'DoliZSynchPropertyStockAuditFormula',
			$packageCount,
			$unitsPerPackage,
			$targetUnits
		));
		$html .= ' — <a href="' . DOL_URL_ROOT . '/product/card.php?id=' . (int) $plan['fk_product_output'] . '">';
		$html .= dol_escape_htmltag($outputName) . '</a></div>';
		if ($plan['source_purchase_qty'] !== null && $plan['source_unit_cost'] !== null
			&& $plan['source_stock_value'] !== null && $plan['source_line_total_ht'] !== null
			&& $plan['exact_unit_cost'] !== null) {
			$purchaseQty = price((float) $plan['source_purchase_qty'], 0, $langs, 0, 0, -1);
			$sourceUnitCost = price((float) $plan['source_unit_cost'], '', '', 0, 0, 4, $conf->currency);
			$sourceStockValue = price((float) $plan['source_stock_value'], '', '', 0, 0, 4, $conf->currency);
			$sourceLineTotalHt = price((float) $plan['source_line_total_ht'], '', '', 0, 0, 2, $conf->currency);
			$exactUnitCost = price((float) $plan['exact_unit_cost'], '', '', 0, 0, 4, $conf->currency);
			$html .= '<div><span class="opacitymedium">';
			$html .= dol_escape_htmltag($langs->trans(
				'DoliZSynchPropertyStockAuditValuation',
				$purchaseQty,
				$sourceUnitCost,
				$sourceStockValue,
				$exactUnitCost
			));
			$html .= '</span></div>';
			$html .= '<div class="opacitymedium">';
			$html .= dol_escape_htmltag($langs->trans('DoliZSynchPropertyStockAuditLineTotal', $sourceLineTotalHt));
			$html .= '</div>';
		}
		$html .= '<div class="opacitymedium">';
		$html .= dol_escape_htmltag($langs->trans('DoliZSynchPropertyStockAuditPlan')) . ' #' . (int) $plan['id'];
		$html .= ' · <a href="' . DOL_URL_ROOT . '/bom/bom_card.php?id=' . (int) $plan['fk_bom'] . '">';
		$html .= dol_escape_htmltag($bomName) . '</a>';
		$html .= ' · ' . dol_escape_htmltag($langs->trans('DoliZSynchPropertyStockAuditSourceMovement'));
		$html .= ' #' . (int) $plan['property_movement_id'];
		if ((int) $plan['fk_stock_movement_out'] > 0 || (int) $plan['fk_stock_movement_in'] > 0) {
			$html .= ' · ' . dol_escape_htmltag($langs->trans(
				'DoliZSynchPropertyStockAuditCorrectionMovements',
				(int) $plan['fk_stock_movement_out'],
				(int) $plan['fk_stock_movement_in']
			));
		}
		$html .= '</div>';
		if (trim((string) $plan['error_message']) !== '') {
			$html .= '<div class="warning">' . dol_escape_htmltag((string) $plan['error_message']) . '</div>';
		}
		$html .= '</div>';

		$this->resprints = $html;
		return 0;
	}

	/**
	 * Render additional form options for product allergens.
	 *
	 * This method displays an allergens selector on the product card. In create/edit mode, it shows a multi‐select field
	 * populated with active allergens from the dictionary table (llx_c_allergens). In view (read-only) mode, it displays the
	 * allergens associated with the product.
	 *
	 * @param array        $parameters   Hook parameters. Should contain a key 'context' (e.g., "productcard").
	 * @param CommonObject $object       The product object.
	 * @param string       $action       Current action ("create", "edit", or view).
	 * @param HookManager  $hookmanager  Hook manager object.
	 * @return int                       0 on success.
	 */
	public function formObjectOptions($parameters, &$object, &$action, $hookmanager)
	{
		global $db, $langs, $conf, $form;

		$isDolizsynchPageContext = (
			!empty($parameters['from_dolizsynch_tab'])
			|| !empty($parameters['from_dolizsynch_page'])
			|| GETPOST('dolizsynch_form', 'alpha') === '1'
			|| GETPOST('dolizsynch_page', 'int')
		);
		if (!$isDolizsynchPageContext) {
			if ($this->isBomCardContext($object)) {
				$langs->load('dolizsynch@kreaflow');
				$this->resprints = $this->renderPropertyStockBomWarning((int) $object->id, (int) $conf->entity);
				return 0;
			}
			// On standard product card (extrafields), show a modal when enabling zs_synch if product is missing in ZSBMS.
			$context = isset($parameters['context']) ? (string) $parameters['context'] : '';
			if (strpos($context, 'productcard') !== false && in_array($action, array('edit', 'edit_extras'), true) && !empty($object->id)) {
				$langs->load('dolizsynch@kreaflow');
				$this->resprints = $this->renderZsbmsCreateModalOnZsSynch((int) $object->id);
			}
			return 0;
		}

		$storeProducts = $conf->global->ZS_API_STORE;
		$store = $conf->global->ZS_API_STORE;
		list($defaultBackgroundColor, $defaultFontColor) = $this->getDefaultProductButtonColors($conf);

		$this->resprints = '';
		$synchFromRequest = false;

		// Initialize arrays to prevent foreach warnings
		$zapiOptionsCategories = [];
		$zapiOptionsFamilies = [];
		$zapiOptionsGroups = [];
		$zapiOptionsUnidades = [];
		$zapiOptionsTaxes = [];

		require_once DOL_DOCUMENT_ROOT . '/custom/kreaflow/class/zsdictionairsynch.class.php';
		require_once DOL_DOCUMENT_ROOT . '/custom/kreaflow/class/zsprodsynch.class.php';

		// Check if the configuration value is not empty
		$storesProductChangesString = $conf->global->ZS_STORES_PRODUCT_CHANGES;
		if (!empty($storesProductChangesString)) {
			$storesProductChangesArray = explode(',', $storesProductChangesString);
			$storesProductChangesArray = array_map('trim', $storesProductChangesArray);
			$storesProductChangesArray = array_map('intval', $storesProductChangesArray);
		} else {
			$storesProductChangesArray = [];
		}

		// Initialize saved variables
		$savedDescricaoCurta = '';
		$savedCodigoPP = '';
		$savedCodbarras = '';
		$savedProdutosCodigosBarras = '[]';
		$savedRetalho = '{}';
		$savedRetalhoValues = array();
		$defaultComposto = '4'; // Venda (Sem Stock)
		$savedComposto = $defaultComposto;
		$savedBalanca = '0';
		$savedFamilia = $conf->global->ZS_DEFAULT_PRODUCT_FAMILY;
		$savedSubFam = '0';
		$savedCategoria = $conf->global->ZS_DEFAULT_PRODUCT_CATEGORY;
		$savedSubCategoria = '0';
		$savedGrupo = '0';
		$savedProductCompanies = '';
		$savedProductCompaniesArray = []; // Start with empty array instead of defaults
		$savedQuebraFimDeDia = '1';
		$savedVenderSemStock = '1';
		$savedIsencao = '';
		$savedIva2 = 0;
		$savedIvaCompra = 0;
		$savedIvaRevenda = 0;
		$savedUnidade = $conf->global->ZS_DEFAULT_PRODUCT_UNIT;
		$savedUncompra = $conf->global->ZS_DEFAULT_PRODUCT_UNIT;
		$savedUninventario = $conf->global->ZS_DEFAULT_PRODUCT_UNIT;
		$savedBackgroundColor = $defaultBackgroundColor;
		$savedFontColor = $defaultFontColor;
		$savedTipoSaft = 'P';
		$savedNiveismenu = '';
		$loadedNiveismenuFromDb = '';
		$loadedProdutosCodigosBarrasFromDb = '';
		$savedRetencao = '0';
		// Treat undefined sync as disabled so the ZoneSoft block stays hidden by default
		$defaultSynch = 0;
		$savedSynch = null; // Will be resolved from extrafields/db; fallback to default later
		$synchWasInitially = $defaultSynch;
		$savedReferencia = '';
		$savedPrepagamento = '0';
		$savedTopo = '0';
		$savedRevenda = '0';
		$savedExcluirdescontos = '0';
		$savedEmentaDigital = '0';
		$savedConsumoMinimo = '0';
		$savedFuel = '0';
		$savedTransferivel = '0';
		$savedTempoPrep = '00:00:00';
		$zsProductRowFound = false;

		if (in_array('productcard', explode(':', $parameters['context']))) {
			// Hide all DoliZSynch UI on product creation
			if ($action === 'create') {
				return 0;
			}

			$scriptName = isset($_SERVER['PHP_SELF']) ? (string) $_SERVER['PHP_SELF'] : '';
			$isNativeProductCardPage = (strpos($scriptName, '/product/card.php') !== false);
			$showSectionSeparator = (
				$isNativeProductCardPage
				&& empty($parameters['from_dolizsynch_tab'])
				&& empty($parameters['from_dolizsynch_page'])
			);

			// Make sure extrafields are loaded so zs_synch is accurate in edit mode
			if (method_exists($object, 'fetch_optionals')) {
				$object->fetch_optionals($object->id);
			}
			if (isset($object->array_options['options_zs_synch'])) {
				$savedSynch = (int) $object->array_options['options_zs_synch'];
			}

			// Fetch the data from the database for the given product ID
			$sql = "SELECT * FROM " . MAIN_DB_PREFIX . "dolizsynch_zsproduct ";
			$sql .= "WHERE fk_product = " . (int) $object->id;
			if ((int) $storeProducts > 0) {
				$sql .= " ORDER BY (loja_zs = " . ((int) $storeProducts) . ") DESC, rowid DESC";
			} else {
				$sql .= " ORDER BY rowid DESC";
			}
			$sql .= " " . $db->plimit(1);

			$resql = $db->query($sql);
			if ($resql) {
				$obj = $db->fetch_object($resql);
				if ($obj) {
					$zsProductRowFound = true;
					$savedDescricaoCurta = $obj->descricaocurta;
					$savedCodigoPP = $obj->codigopp ?? '';
					$savedCodbarras = $obj->codbarras ?? '';
					$loadedProdutosCodigosBarrasFromDb = (string) ($obj->produtos_codigosbarras ?? '');
					$savedProdutosCodigosBarras = $this->normalizeProdutosCodigosBarrasJson($loadedProdutosCodigosBarrasFromDb);
					$savedRetalho = $obj->retalho_by_store;
					// Parse the 'retalho_by_store' JSON string into an array
					$savedRetalhoValues = json_decode($savedRetalho, true);
					if (json_last_error() !== JSON_ERROR_NONE || !is_array($savedRetalhoValues)) {
						$savedRetalhoValues = array();
					}
					$savedComposto = (isset($obj->composto) && $obj->composto !== '') ? (string) $obj->composto : $defaultComposto;
					$savedBalanca = $obj->balanca;
					$baseIva = isset($obj->iva) ? $obj->iva : 0;
					$savedIsencao = isset($obj->isencao) ? (string) $obj->isencao : '';
					$savedIva2 = (isset($obj->iva2) && $obj->iva2 !== '') ? $obj->iva2 : $baseIva;
					$savedIvaCompra = (isset($obj->ivacompra) && $obj->ivacompra !== '') ? $obj->ivacompra : $baseIva;
					$savedIvaRevenda = (isset($obj->ivarevenda) && $obj->ivarevenda !== '') ? $obj->ivarevenda : $baseIva;
					$savedFamilia = $obj->familia;
					$savedSubFam = ($obj->subfam === null || $obj->subfam === '') ? '0' : (string) $obj->subfam;
					$savedCategoria = $obj->categoria;
					$savedSubCategoria = ($obj->subcategoria === null || $obj->subcategoria === '') ? '0' : (string) $obj->subcategoria;
					$savedGrupo = ($obj->grupo === null || $obj->grupo === '') ? '0' : (string) $obj->grupo;
					$savedProductCompanies = $obj->product_company;
					$savedProductCompaniesArray = !empty($savedProductCompanies) ? explode(',', $savedProductCompanies) : array();
					$savedQuebraFimDeDia = $obj->autoquebra;
					$savedVenderSemStock = $obj->vendersemstock;
					$savedUnidade = $obj->unidade;
					$savedUncompra = $obj->uncompra;
					$savedUninventario = $obj->uninventario;
					$savedBackgroundColor = $this->normalizeHexColor($obj->fundo, $defaultBackgroundColor);
					$savedFontColor = $this->normalizeHexColor($obj->letra, $defaultFontColor);
					$savedTipoSaft = $obj->tiposaft;
					$savedNiveismenu = $obj->niveismenu;
					$loadedNiveismenuFromDb = $savedNiveismenu;
					$savedRetencao = $obj->retencao;
					$savedReferencia = $obj->referencia ?? '';
					$savedPrepagamento = $obj->prepagamento ?? '0';
					$savedTopo = $obj->topo ?? '0';
					$savedRevenda = $obj->revenda ?? '0';
					$savedExcluirdescontos = $obj->excluirdescontos ?? '0';
					require_once __DIR__ . '/zsprodsynch.class.php';
					$savedEmentaDigital = $obj->ementadigital ?? ZSProductSynch::getDigitalMenuValue($obj->produto_opcoes ?? array()) ?? 0;
					$savedConsumoMinimo = $obj->consumominimo ?? '0';
					$savedFuel = $obj->fuel ?? '0';
					$savedTransferivel = $obj->transferivel ?? '0';
					$savedTempoPrep = $this->normalizePrepTime($obj->tempoprep ?? '00:00:00');
				}

				// Prefer product extrafield as the source of truth for sync flag
				if (isset($object->array_options['options_zs_synch'])) {
					$savedSynch = (int)$object->array_options['options_zs_synch'];
				}

				// If still undefined, look directly into product extrafields table
				if ($savedSynch === '' || $savedSynch === null) {
					$sqlSynch = "SELECT zs_synch FROM " . MAIN_DB_PREFIX . "product_extrafields WHERE fk_object = " . ((int)$object->id);
					$resSynch = $db->query($sqlSynch);
					if ($resSynch && ($rowSynch = $db->fetch_object($resSynch))) {
						if ($rowSynch->zs_synch === '0' || $rowSynch->zs_synch === 0) {
							$savedSynch = 0;
						} elseif ($rowSynch->zs_synch === '1' || $rowSynch->zs_synch === 1) {
							$savedSynch = 1;
						}
					}
				}

				// Default to sync enabled when undefined/null
				if ($savedSynch === '' || $savedSynch === null) {
					$savedSynch = $defaultSynch;
				}

				// Track initial sync state after loading stored values
				$synchWasInitially = (int)$savedSynch;

				// Explicit override via GET to keep sync enabled after auto-redirects
				if (!empty($_GET['set_zs_synch'])) {
					$savedSynch = 1;
					$synchFromRequest = true;
					if (empty($object->array_options)) {
						$object->array_options = array();
					}
					$object->array_options['options_zs_synch'] = 1;
				}
			}

			// Override saved values with session data if validation failed
			if (session_status() == PHP_SESSION_NONE) {
				session_start();
			}
			if (isset($_SESSION['dolizsynch_validation_failed']) && $_SESSION['dolizsynch_validation_failed'] && isset($_SESSION['dolizsynch_form_data'])) {
				$sessionData = $_SESSION['dolizsynch_form_data'];

				// Override all form values with session data
				$savedDescricaoCurta = isset($sessionData['DOLIZSYNCH_DESCRICAOCURTA']) ? $sessionData['DOLIZSYNCH_DESCRICAOCURTA'] : $savedDescricaoCurta;
				$savedCodigoPP = isset($sessionData['DOLIZSYNCH_CODIGOPP']) ? $sessionData['DOLIZSYNCH_CODIGOPP'] : $savedCodigoPP;
				$savedCodbarras = isset($sessionData['DOLIZSYNCH_CODBARRAS']) ? $sessionData['DOLIZSYNCH_CODBARRAS'] : $savedCodbarras;
				if (isset($sessionData['DOLIZSYNCH_PRODUTOS_CODIGOSBARRAS'])) {
					$savedProdutosCodigosBarras = $this->normalizeProdutosCodigosBarrasJson($sessionData['DOLIZSYNCH_PRODUTOS_CODIGOSBARRAS']);
				}
				$savedComposto = isset($sessionData['DOLIZSYNCH_ZS_COMPOSTO']) ? $sessionData['DOLIZSYNCH_ZS_COMPOSTO'] : $savedComposto;
				$savedBalanca = isset($sessionData['DOLIZSYNCH_ZS_BALANCA']) ? $sessionData['DOLIZSYNCH_ZS_BALANCA'] : $savedBalanca;
				$savedQuebraFimDeDia = isset($sessionData['DOLIZSYNCH_QUEBRA_FIM_DIA']) ? $sessionData['DOLIZSYNCH_QUEBRA_FIM_DIA'] : $savedQuebraFimDeDia;
				$savedVenderSemStock = isset($sessionData['DOLIZSYNCH_VENDER_SEM_STOCK']) ? $sessionData['DOLIZSYNCH_VENDER_SEM_STOCK'] : $savedVenderSemStock;
				$savedIsencao = isset($sessionData['DOLIZSYNCH_ZS_ISENCAO']) ? $sessionData['DOLIZSYNCH_ZS_ISENCAO'] : $savedIsencao;
				$savedIva2 = isset($sessionData['DOLIZSYNCH_ZS_IVA2']) ? $sessionData['DOLIZSYNCH_ZS_IVA2'] : $savedIva2;
				$savedIvaCompra = isset($sessionData['DOLIZSYNCH_ZS_IVACOMPRA']) ? $sessionData['DOLIZSYNCH_ZS_IVACOMPRA'] : $savedIvaCompra;
				$savedIvaRevenda = isset($sessionData['DOLIZSYNCH_ZS_IVAREVENDA']) ? $sessionData['DOLIZSYNCH_ZS_IVAREVENDA'] : $savedIvaRevenda;
				$sessionBackgroundColor = $savedBackgroundColor;
				$sessionFontColor = $savedFontColor;
				if (isset($sessionData['DOLIZSYNCH_ZS_BACKGROUND_COLOR'])) {
					$sessionBackgroundColor = $sessionData['DOLIZSYNCH_ZS_BACKGROUND_COLOR'];
				} elseif (isset($sessionData['DOLIZSYNCH_BACKGROUND_COLOR'])) {
					$sessionBackgroundColor = $sessionData['DOLIZSYNCH_BACKGROUND_COLOR'];
				}
				if (isset($sessionData['DOLIZSYNCH_ZS_FONT_COLOR'])) {
					$sessionFontColor = $sessionData['DOLIZSYNCH_ZS_FONT_COLOR'];
				} elseif (isset($sessionData['DOLIZSYNCH_FONT_COLOR'])) {
					$sessionFontColor = $sessionData['DOLIZSYNCH_FONT_COLOR'];
				}
				$savedBackgroundColor = $this->normalizeHexColor($sessionBackgroundColor, $savedBackgroundColor);
				$savedFontColor = $this->normalizeHexColor($sessionFontColor, $savedFontColor);
				$savedTipoSaft = isset($sessionData['DOLIZSYNCH_ZS_TIPO_SAFT']) ? $sessionData['DOLIZSYNCH_ZS_TIPO_SAFT'] : $savedTipoSaft;
				$savedNiveismenu = isset($sessionData['DOLIZSYNCH_MENU']) ? $sessionData['DOLIZSYNCH_MENU'] : $savedNiveismenu;
				$savedRetencao = isset($sessionData['DOLIZSYNCH_RETENCAO']) ? $sessionData['DOLIZSYNCH_RETENCAO'] : $savedRetencao;
				$savedReferencia = isset($sessionData['DOLIZSYNCH_REFERENCIA']) ? $sessionData['DOLIZSYNCH_REFERENCIA'] : $savedReferencia;
				$savedPrepagamento = isset($sessionData['DOLIZSYNCH_PREPAGAMENTO']) ? $sessionData['DOLIZSYNCH_PREPAGAMENTO'] : $savedPrepagamento;
				$savedTopo = isset($sessionData['DOLIZSYNCH_TOPO']) ? $sessionData['DOLIZSYNCH_TOPO'] : $savedTopo;
				$savedRevenda = isset($sessionData['DOLIZSYNCH_REVENDA']) ? $sessionData['DOLIZSYNCH_REVENDA'] : $savedRevenda;
				$savedExcluirdescontos = isset($sessionData['DOLIZSYNCH_EXCLUIR_DESCONTOS']) ? $sessionData['DOLIZSYNCH_EXCLUIR_DESCONTOS'] : $savedExcluirdescontos;
				$savedEmentaDigital = isset($sessionData['DOLIZSYNCH_EMENTADIGITAL']) ? $sessionData['DOLIZSYNCH_EMENTADIGITAL'] : $savedEmentaDigital;
				$savedConsumoMinimo = isset($sessionData['DOLIZSYNCH_CONSUMOMINIMO']) ? $sessionData['DOLIZSYNCH_CONSUMOMINIMO'] : $savedConsumoMinimo;
				$savedFuel = isset($sessionData['DOLIZSYNCH_FUEL']) ? $sessionData['DOLIZSYNCH_FUEL'] : $savedFuel;
				$savedTransferivel = isset($sessionData['DOLIZSYNCH_TRANSFERIVEL']) ? $sessionData['DOLIZSYNCH_TRANSFERIVEL'] : $savedTransferivel;
				if (
					isset($sessionData['DOLIZSYNCH_TEMPOPREP'])
					|| isset($sessionData['DOLIZSYNCH_TEMPOPREP_HOUR'])
					|| isset($sessionData['DOLIZSYNCH_TEMPOPREP_MINUTE'])
					|| isset($sessionData['DOLIZSYNCH_TEMPOPREP_SECOND'])
				) {
					$savedTempoPrep = $this->extractPrepTimeFromSource($sessionData);
				}
				if (isset($sessionData['options_zs_synch'])) {
					$savedSynch = (int)$sessionData['options_zs_synch'];
					$synchFromRequest = true;
				} elseif (isset($sessionData['DOLIZSYNCH_ZS_SYNCH'])) {
					$savedSynch = $sessionData['DOLIZSYNCH_ZS_SYNCH'] ? 1 : 0;
					$synchFromRequest = true;
				}

				// Handle multi-select company field
				if (isset($sessionData['DOLIZSYNCH_ZS_PRODUCT_COMPANY']) && is_array($sessionData['DOLIZSYNCH_ZS_PRODUCT_COMPANY'])) {
					$savedProductCompaniesArray = $sessionData['DOLIZSYNCH_ZS_PRODUCT_COMPANY'];
					$savedProductCompanies = implode(',', $savedProductCompaniesArray);
				}

				// Handle select fields that combine multiple values
				if (isset($sessionData['DOLIZSYNCH_ZS_FAMILY'])) {
					$familyParts = explode('_', $sessionData['DOLIZSYNCH_ZS_FAMILY']);
					if (count($familyParts) == 2) {
						$savedFamilia = $familyParts[0];
						$savedSubFam = $familyParts[1];
					}
				}

				if (isset($sessionData['DOLIZSYNCH_ZS_CATEGORY'])) {
					$categoryParts = explode('_', $sessionData['DOLIZSYNCH_ZS_CATEGORY']);
					if (count($categoryParts) == 2) {
						$savedCategoria = $categoryParts[0];
						$savedSubCategoria = $categoryParts[1];
					}
				}
				if (isset($sessionData['DOLIZSYNCH_ZS_GROUP'])) {
					$savedGrupo = $sessionData['DOLIZSYNCH_ZS_GROUP'];
				}

				if (isset($sessionData['DOLIZSYNCH_ZS_UNIT'])) {
					$savedUnidade = $sessionData['DOLIZSYNCH_ZS_UNIT'];
				}
				if (isset($sessionData['DOLIZSYNCH_ZS_UNCOMPRA'])) {
					$savedUncompra = $sessionData['DOLIZSYNCH_ZS_UNCOMPRA'];
				}
				if (isset($sessionData['DOLIZSYNCH_ZS_UNINVENTARIO'])) {
					$savedUninventario = $sessionData['DOLIZSYNCH_ZS_UNINVENTARIO'];
				}

				// Handle retalho (radio button) values
				$savedRetalhoValues = array();
				foreach ($sessionData as $key => $value) {
					if (strpos($key, 'retalho_for_company_') === 0) {
						$companyId = str_replace('retalho_for_company_', '', $key);
						$savedRetalhoValues[$companyId] = $value;
					}
				}
				$savedRetalho = json_encode($savedRetalhoValues);

				// Clear session data after using it
				unset($_SESSION['dolizsynch_form_data']);
				unset($_SESSION['dolizsynch_validation_failed']);
			}

			// Overwrite with GETPOST data if the form was submitted
			// Short Description
			if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($action, array('edit', 'update', 'create', 'add'), true)) {
				$savedDescricaoCurta = GETPOST('DOLIZSYNCH_DESCRICAO_CURTA', 'alphanohtml');
				// CodigoPP
				$savedCodigoPP = GETPOST('DOLIZSYNCH_CODIGOPP', 'alphanohtml');
				// Codbarras (max 20 chars in database - already validated and truncated earlier)
				$savedCodbarras = substr(GETPOST('DOLIZSYNCH_CODBARRAS', 'alphanohtml'), 0, 20);
				$postedProdutosCodigosBarras = GETPOST('DOLIZSYNCH_PRODUTOS_CODIGOSBARRAS', 'none');
				if (trim((string) $postedProdutosCodigosBarras) !== '') {
					$savedProdutosCodigosBarras = $this->normalizeProdutosCodigosBarrasJson($postedProdutosCodigosBarras);
				} elseif ($loadedProdutosCodigosBarrasFromDb !== '') {
					$savedProdutosCodigosBarras = $this->normalizeProdutosCodigosBarrasJson($loadedProdutosCodigosBarrasFromDb);
				}
				// Retalho - handle per-company radio button values
				$postedRetalhoValues = array();
				foreach ($_POST as $key => $value) {
					if (strpos($key, 'retalho_for_company_') === 0) {
						$companyId = str_replace('retalho_for_company_', '', $key);
						if (!ctype_digit((string) $companyId)) {
							continue;
						}
						$retalhoCode = (int) $value;
						if ($retalhoCode < 0 || $retalhoCode > 2) {
							$retalhoCode = 1;
						}
						$postedRetalhoValues[(int) $companyId] = $retalhoCode;
					}
				}
				if (!is_array($savedRetalhoValues)) {
					$savedRetalhoValues = array();
				}
				$savedRetalhoValues = array_replace($savedRetalhoValues, $postedRetalhoValues);
				if (!empty($savedRetalhoValues)) {
					$savedRetalho = json_encode($savedRetalhoValues);
				}
				// Composto
				$postedComposto = GETPOST('DOLIZSYNCH_ZS_COMPOSTO', 'alphanohtml');
				$savedComposto = ($postedComposto !== '') ? $postedComposto : $defaultComposto;
				// Balanca
				$savedBalanca = GETPOST('DOLIZSYNCH_ZS_BALANCA', 'alphanohtml');
				// Family and Subfamily
				$rawFamily = GETPOST('DOLIZSYNCH_ZS_FAMILY', 'alphanohtml');
				if ($rawFamily !== '') {
					list($savedFamilia, $savedSubFam) = explode('_', $rawFamily) + [0 => '', 1 => ''];
				} else {
					$savedFamilia = '';
					$savedSubFam = '';
				}
				// Category and Subcategory
				$rawCategory = GETPOST('DOLIZSYNCH_ZS_CATEGORY', 'alphanohtml');
				if ($rawCategory !== '') {
					list($savedCategoria, $savedSubCategoria) = explode('_', $rawCategory) + [0 => '', 1 => ''];
				} else {
					$savedCategoria = '';
					$savedSubCategoria = '';
				}
				$rawGrupo = GETPOST('DOLIZSYNCH_ZS_GROUP', 'alphanohtml');
				$savedGrupo = ($rawGrupo !== '') ? $rawGrupo : '0';
				// Product Companies
				$savedProductCompaniesArray = GETPOST('DOLIZSYNCH_ZS_PRODUCT_COMPANY', 'array');
				if (!empty($savedProductCompaniesArray) && is_array($savedProductCompaniesArray)) {
					$savedProductCompanies = implode(',', $savedProductCompaniesArray);
				} else {
					$savedProductCompaniesArray = array();
					$savedProductCompanies = '';
				}
				// Quebra fim de dia
				$savedQuebraFimDeDia = GETPOST('DOLIZSYNCH_ZS_QUEBRA_FIM_DIA') ? 1 : 0;
				// Vender Sem Stock
				$savedVenderSemStock = GETPOST('DOLIZSYNCH_ZS_VENDER_SEM_STOCK') ? 1 : 0;
				// Retençao
				$savedRetencao = GETPOST('DOLIZSYNCH_ZS_RETENCAO') ? 1 : 0;
				// Isencao e IVA (ZS)
				$savedIsencao = GETPOST('DOLIZSYNCH_ZS_ISENCAO', 'alphanohtml');
				$savedIva2 = GETPOST('DOLIZSYNCH_ZS_IVA2', 'alphanohtml');
				$savedIvaCompra = GETPOST('DOLIZSYNCH_ZS_IVACOMPRA', 'alphanohtml');
				$savedIvaRevenda = GETPOST('DOLIZSYNCH_ZS_IVAREVENDA', 'alphanohtml');
				// Sync flag (checked = sync enabled)
				$rawSynchDisplay = GETPOST('options_zs_synch', 'alphanohtml');
				if ($rawSynchDisplay !== '') {
					$savedSynch = (int) $rawSynchDisplay;
					$synchFromRequest = true;
				}
				// Ensure sync has a value when empty
				if ($savedSynch === '' || $savedSynch === null) {
					$savedSynch = $defaultSynch;
				}
				// New fields
				$savedReferencia = GETPOST('DOLIZSYNCH_REFERENCIA', 'alphanohtml');
				$savedPrepagamento = GETPOST('DOLIZSYNCH_PREPAGAMENTO') ? 1 : 0;
				$savedTopo = GETPOST('DOLIZSYNCH_TOPO') ? 1 : 0;
				$savedRevenda = GETPOST('DOLIZSYNCH_REVENDA') ? 1 : 0;
				$savedExcluirdescontos = GETPOST('DOLIZSYNCH_EXCLUIRDESCONTOS') ? 1 : 0;
				$savedEmentaDigital = GETPOST('DOLIZSYNCH_EMENTADIGITAL') ? 1 : 0;
				$savedConsumoMinimo = GETPOST('DOLIZSYNCH_CONSUMOMINIMO') ? 1 : 0;
				$savedFuel = GETPOST('DOLIZSYNCH_FUEL') ? 1 : 0;
				$savedTransferivel = GETPOST('DOLIZSYNCH_TRANSFERIVEL') ? 1 : 0;
				$savedTempoPrep = $this->extractPrepTimeFromSource(array(
					'DOLIZSYNCH_TEMPOPREP_HOUR'   => GETPOST('DOLIZSYNCH_TEMPOPREP_HOUR', 'int'),
					'DOLIZSYNCH_TEMPOPREP_MINUTE' => GETPOST('DOLIZSYNCH_TEMPOPREP_MINUTE', 'int'),
					'DOLIZSYNCH_TEMPOPREP_SECOND' => GETPOST('DOLIZSYNCH_TEMPOPREP_SECOND', 'int'),
					'DOLIZSYNCH_TEMPOPREP'        => GETPOST('DOLIZSYNCH_TEMPOPREP', 'alpha'),
				));
				// Unidade
				$savedUnidade = GETPOST('DOLIZSYNCH_PRODUCT_UNIDADE', 'alphanohtml');
				// Unidade Compra
				$savedUncompra = GETPOST('DOLIZSYNCH_PRODUCT_UNCOMPRA', 'alphanohtml');
				// Unidade Inventario
				$savedUninventario = GETPOST('DOLIZSYNCH_PRODUCT_UNINVENTARIO', 'alphanohtml');
				// Background Color
				$savedBackgroundColor = $this->normalizeHexColor(GETPOST('DOLIZSYNCH_ZS_BACKGROUND_COLOR', 'alphanohtml'), $savedBackgroundColor);
				// Font Color
				$savedFontColor = $this->normalizeHexColor(GETPOST('DOLIZSYNCH_ZS_FONT_COLOR', 'alphanohtml'), $savedFontColor);
				// Tipo Saft
				$savedTipoSaft = GETPOST('DOLIZSYNCH_ZS_TIPO_PRODUTO', 'alphanohtml');
				// Niveis Menu (if applicable)
				$rawNiveismenu = trim((string) GETPOST('DOLIZSYNCH_ZS_NIVEISMENU', 'none'));
				if ($rawNiveismenu !== '') {
					$menuCandidates = array(
						$rawNiveismenu,
						html_entity_decode($rawNiveismenu, ENT_QUOTES | ENT_HTML5),
						stripslashes($rawNiveismenu),
						stripslashes(html_entity_decode($rawNiveismenu, ENT_QUOTES | ENT_HTML5)),
					);
					$normalizedPostedMenu = '';
					$seenMenuCandidates = array();
					foreach ($menuCandidates as $candidateValue) {
						$candidateValue = (string) $candidateValue;
						if ($candidateValue === '' || isset($seenMenuCandidates[$candidateValue])) {
							continue;
						}
						$seenMenuCandidates[$candidateValue] = true;
						$decodedCandidate = json_decode($candidateValue, true);
						if (json_last_error() === JSON_ERROR_NONE && is_array($decodedCandidate)) {
							$encodedCandidate = json_encode($decodedCandidate, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
							$normalizedPostedMenu = ($encodedCandidate === false ? $candidateValue : $encodedCandidate);
							break;
						}
					}
					$savedNiveismenu = ($normalizedPostedMenu !== '' ? $normalizedPostedMenu : $rawNiveismenu);
					if (trim((string) $savedNiveismenu) === '' && !empty($loadedNiveismenuFromDb)) {
						// Do not wipe existing menu if nothing was sent
						$savedNiveismenu = $loadedNiveismenuFromDb;
					}
					// Keep last submitted menu in session as an extra guard
					if (session_status() == PHP_SESSION_NONE) {
						session_start();
					}
					$_SESSION['dolizsynch_last_menu'] = $savedNiveismenu;
				} else {
					$savedNiveismenu = ''; // Or set to a default value if needed
				}
			}

			// Final fallback and normalization for sync flag
			if ($savedSynch === '' || $savedSynch === null) {
				$savedSynch = 0;
			}
			// Prefer freshly loaded array_options if available
			if (isset($object->array_options['options_zs_synch'])) {
				$savedSynch = (int) $object->array_options['options_zs_synch'];
			}
			// Double-check from DB if still zero and we have an ID (avoid stale array_options)
			if ((int) $savedSynch === 0 && !empty($object->id)) {
				$sqlSynchDirect = "SELECT zs_synch FROM " . MAIN_DB_PREFIX . "product_extrafields WHERE fk_object = " . ((int) $object->id);
				$resSynchDirect = $db->query($sqlSynchDirect);
				if ($resSynchDirect && ($rowSynchDirect = $db->fetch_object($resSynchDirect))) {
					if ($rowSynchDirect->zs_synch === '1' || $rowSynchDirect->zs_synch === 1) {
						$savedSynch = 1;
					}
				}
			}
			if (!$synchFromRequest && !empty($object->ref)) {
				$globalSynch = $this->getGlobalZsSynchByRef($object->ref);
				if ($globalSynch !== null) {
					$savedSynch = (int) $globalSynch;
					if (empty($object->array_options)) {
						$object->array_options = array();
					}
					$object->array_options['options_zs_synch'] = (int) $globalSynch;
				}
			}
			$savedSynch = (int)$savedSynch;
			$synchWasInitially = $savedSynch;


			$TFieldRetalho = array(
				'1' => $langs->trans('DoliZSynchRetalhoVendidoClienteFinal'),
				'0' => $langs->trans('DoliZSynchRetalhoSoBackOffice'),
				'2' => $langs->trans('DoliZSynchRetalhoDescontinuado'),
			);

				$TFieldComposto = array(
					'0' => $langs->trans('DoliZSynchProductCompostoVendaMercadoria'),
					'2' => $langs->trans('DoliZSynchProductCompostoMenu'),
					'3' => $langs->trans('DoliZSynchProductCompostoMercadoria'),
					'4' => $langs->trans('DoliZSynchProductCompostoVenda'),
					'5' => $langs->trans('DoliZSynchProductCompostoTransformado'),
					'6' => $langs->trans('DoliZSynchProductCompostoProducao'),
				);
				$TFieldCompostoWithEmpty = array('' => '') + $TFieldComposto;

				$TFieldBalanca = array(
					'0' => $langs->trans('DoliZSynchProductBalancaNormal'),
					'1' => $langs->trans('DoliZSynchProductBalancaBalanca'),
					'2' => $langs->trans('DoliZSynchProductBalancaPedirQuantidade'),
					'3' => $langs->trans('DoliZSynchProductBalancaQuantidadePendente'),
					'4' => $langs->trans('DoliZSynchProductBalancaPedeValor'),
				);
				$TFieldBalancaWithEmpty = array('' => '') + $TFieldBalanca;

			$TFieldTipoProduto = array(
				'P' => $langs->trans('DoliZSynchTypeProductProdutos'),
				'S' => $langs->trans('DoliZSynchTypeProductServicos'),
				'O' => $langs->trans('DoliZSynchTypeProductOutros'),
				'I' => $langs->trans('DoliZSynchTypeProductImpostos'),
				'SC' => $langs->trans('DoliZSynchTypeProductSaidaCaixa'),
				'EC' => $langs->trans('DoliZSynchTypeProductEntradaCaixa'),
				'V' => $langs->trans('DoliZSynchTypeProductVoucher'),
				'CM' => $langs->trans('DoliZSynchTypeProductConsumoMinimo'),
				'CC' => $langs->trans('DoliZSynchTypeProductCarregamentoCashless'),
			);

			$TFieldIsencao = array(
				'0' => '[0] - Selecione o Motivo de Isenção',
				'M01' => '[M01] - Artigo 16.º, n.º 6 do CIVA',
				'M02' => '[M02] - Artigo 6.º do Decreto-Lei n.º 198/90, de 19 de Junho',
				'M03' => '[M03] - Exigibilidade de caixa',
				'M04' => '[M04] - Isento artigo 13.º do CIVA',
				'M05' => '[M05] - Isento artigo 14.º do CIVA',
				'M16' => '[M16] - Isento artigo 14.º do RITI',
				'M06' => '[M06] - Isento artigo 15.º do CIVA',
				'M07' => '[M07] - Isento artigo 9.º do CIVA',
				'M26' => '[M26] - Isenção de IVA com direito à dedução no cabaz alimentar',
				'M41' => '[M41] - IVA - autoliquidação',
				'M40' => '[M40] - IVA - autoliquidação',
				'M33' => '[M33] - IVA - autoliquidação',
				'M32' => '[M32] - IVA - autoliquidação',
				'M31' => '[M31] - IVA - autoliquidação',
				'M30' => '[M30] - IVA - autoliquidação',
				'M08' => '[M08] - IVA - autoliquidação',
				'M42' => '[M42] - IVA - autoliquidação',
				'M43' => '[M43] - IVA - autoliquidação',
				'M34' => '[M34] - IVA - autoliquidação',
				'M09' => '[M09] - IVA - não confere direito a dedução',
				'M10' => '[M10] - IVA - Regime de isenção',
				'M20' => '[M20] - IVA - Regime forfetário',
				'M21' => '[M21] - IVA - não confere direito à dedução (ou expressão similar)',
				'M25' => '[M25] - Mercadorias à consignação',
				'M99' => '[M99] - Não sujeito ou não tributado',
				'M19' => '[M19] - Outras isenções',
				'M12' => '[M12] - Regime da margem de lucro - Agências de viagens',
				'M13' => '[M13] - Regime da margem de lucro - Bens em segunda mão',
				'M14' => '[M14] - Regime da margem de lucro - Objetos de arte',
				'M15' => '[M15] - Regime da margem de lucro - Objetos de coleção e antiguidades',
				'M11' => '[M11] - Regime particular do tabaco',
			);

			// Setup conf ZS_STORES_PRODUCT_CHANGES
			$sql = "SELECT e.rowid AS entity_id, e.label AS entity_name, c2.value AS zs_api_store ";
			$sql .= "FROM " . MAIN_DB_PREFIX . "entity e ";
			$sql .= "JOIN " . MAIN_DB_PREFIX . "const c1 ON e.rowid = c1.entity ";
			$sql .= "LEFT JOIN " . MAIN_DB_PREFIX . "const c2 ON e.rowid = c2.entity AND c2.name = 'ZS_API_STORE' ";
			$sql .= "WHERE c1.name = 'MAIN_MODULE_DOLIZSYNCH' AND c1.value = '1'";
			$resql = $db->query($sql);
			if (!$resql) {
				//
			}
			$TFieldProduct = [];
			while ($obj = $db->fetch_object($resql)) {
				// Display entity label and save ZS_API_STORE
				$TFieldProduct[$obj->zs_api_store] = $obj->entity_name;
			}

			// Initialize arrays to prevent undefined variable errors
			$zapiOptionsFamilies = array();
			$zapiOptionsCategories = array();
			$zapiOptionsGroups = array();
			$zapiOptionsUnits = array();
			$zapiOptionsTaxes = array();

			$sql = "SELECT family_id, family_name, subfamily_id, subfamily_name ";
			$sql .= "FROM " . MAIN_DB_PREFIX . "dolizsynch_zsfamily ";
			$sql .= "WHERE loja_zs = " . $storeProducts . " ";
			$sql .= "ORDER BY family_name ASC";
			$resqlZapi = $db->query($sql); // Use $sql, not $sqlZapi
			if ($resqlZapi) {
				while ($obj = $db->fetch_object($resqlZapi)) {
					$zapiOptionsFamilies[] = $obj;
				}
			} else {
				dol_syslog("SQL Error on fetching Zapi Options: " . $db->lasterror(), LOG_ERR);
			}

			$sql = "SELECT category_id, category_name, subcategory_id, subcategory_name ";
			$sql .= "FROM " . MAIN_DB_PREFIX . "dolizsynch_zscategory ";
			$sql .= "WHERE loja_zs = " . $storeProducts . " ";
			$sql .= "ORDER BY category_name ASC";
			$resqlZapi = $db->query($sql); // Use $sql, not $sqlZapi
			if ($resqlZapi) {
				while ($obj = $db->fetch_object($resqlZapi)) {
					$zapiOptionsCategories[] = $obj;
				}
			} else {
				dol_syslog("SQL Error on fetching Zapi Options: " . $db->lasterror(), LOG_ERR);
			}

			$sql = "SELECT group_id, group_name ";
			$sql .= "FROM " . MAIN_DB_PREFIX . "dolizsynch_zsgroup ";
			$sql .= "WHERE loja_zs = " . $storeProducts . " ";
			$sql .= "ORDER BY group_name ASC";
			$resqlZapi = $db->query($sql);
			if ($resqlZapi) {
				while ($obj = $db->fetch_object($resqlZapi)) {
					$zapiOptionsGroups[] = $obj;
				}
			} else {
				dol_syslog("SQL Error on fetching ZS groups: " . $db->lasterror(), LOG_ERR);
			}

			$sql = "SELECT unit_id, unit_name ";
			$sql .= "FROM " . MAIN_DB_PREFIX . "dolizsynch_zsunit ";
			$sql .= "WHERE loja_zs = " . $storeProducts . " ";
			$sql .= "ORDER BY unit_name ASC";
			$resqlZapi = $db->query($sql); // Use $sql, not $sqlZapi
			if ($resqlZapi) {
				while ($obj = $db->fetch_object($resqlZapi)) {
					$zapiOptionsUnits[] = $obj;
				}
			} else {
				dol_syslog("SQL Error on fetching Zapi Options: " . $db->lasterror(), LOG_ERR);
			}

			$zapiOptionsTaxes = $this->loadZsTaxOptions($storeProducts);

			if ($action == 'create' || $action == 'edit') {
				if ($showSectionSeparator) {
					$this->resprints .= '<tr class="liste_titre" style="background: transparent !important;"><td colspan="4" style="background: transparent !important;">' . $langs->trans("DoliZSynchProductTitle") . '</td></tr>' . "\n";
				}

				// Add CSS to ensure hidden rows are properly hidden
				$this->resprints .= '<style>
						.company-retalho-hidden { display: none !important; }
						.zs-hidden { display: none !important; }
					</style>' . "\n";

				// Wrap the block so it can be repositioned after other module sections
				$this->resprints .= '<tbody class="dolizsynch-config-block dolizsynch-config-block-edit" data-zs-synch="' . (int) $savedSynch . '">';

				// Forçar sincro ZS (LOJA X) -> Dolibarr
				$this->resprints .= '<tr class="dzs-row"><td class="titlefield">' . str_replace('store', $storeProducts, $langs->trans("DoliZSynchForceSynchProductToZoneSoft")) . '</td><td colspan="3">';
				$this->resprints .= '<a class="button" href="' . $_SERVER['PHP_SELF'] . '?id=' . $object->id . '&action=forceSync&redirect=edit">' . $langs->trans("DoliZSynchForceSyncButton") . '</a>';
				$this->resprints .= '</td></tr>';

				// Loja para actualizacao do produto (ZS)
				$this->resprints .= '<tr class="dzs-row"><td style="vertical-align: top;">' . $langs->trans("DoliZSynchProductCompany") . '</td><td colspan="3">';
				$this->resprints .= '<div style="display: flex; align-items: flex-start; gap: 4px;">';
				// Add Select All buttons only if there are more than one stores
				if (count($TFieldProduct) > 1) {
					$this->resprints .= '<div style="display: flex; flex-direction: row; gap: 2px; margin-right: 4px;">';
					$this->resprints .= '<span id="select-all-stores" title="Seleccionar Todos" style="cursor: pointer; font-size: 14px; color: #4c4c4c; display: block; text-align: center; width: 18px; height: 18px; line-height: 18px; border-radius: 3px; background: #f8f9fa; border: 1px solid #ddd;">▣</span>';
					$this->resprints .= '<span id="deselect-all-stores" title="Limpar Todos" style="cursor: pointer; font-size: 14px; color: #4c4c4c; display: block; text-align: center; width: 18px; height: 18px; line-height: 18px; border-radius: 3px; background: #f8f9fa; border: 1px solid #ddd;">☐</span>';
					$this->resprints .= '</div>';
				}
				$this->resprints .= '<div style="flex: 1;">';
				$this->resprints .= $form->multiselectarray('DOLIZSYNCH_ZS_PRODUCT_COMPANY', $TFieldProduct, $savedProductCompaniesArray, 0, 0, 'minwidth500', 0, '100%', '', 'id="DOLIZSYNCH_ZS_PRODUCT_COMPANY"');
				$this->resprints .= '</div>';
				$this->resprints .= '</div>';
				$this->resprints .= '</td></tr>';

				// DEBUG: Show current state

				// Disponibilidade header - show/hide based on selected companies
				$headerDisplayStyle = !empty($savedProductCompaniesArray) ? '' : ' style="display:none;"';
				$this->resprints .= '<tr class="dzs-row" id="disponibilidade-header"' . $headerDisplayStyle . '><td style="width: 200px;">' . $langs->trans("RetalhoForCompany") . '</td><td colspan="3">';
				$this->resprints .= '<div style="display: flex; align-items: center;">';
				$headerOptions = $TFieldRetalho;
				foreach ($headerOptions as $optionValue => $optionLabel) {
					$this->resprints .= '<div style="width: 120px; text-align: center; font-size: 12px;">' . htmlspecialchars($optionLabel) . '</div>';
				}
				$this->resprints .= '</div>';
				$this->resprints .= '</td></tr>';

				// Render only selected companies initially, all others hidden
				foreach ($TFieldProduct as $companyId => $companyLabel) {
					$dropdownName = 'retalho_for_company_' . htmlspecialchars($companyId);
					$savedDropdownValue = isset($savedRetalhoValues[$companyId]) ? (string) $savedRetalhoValues[$companyId] : '1';
					if ($_SERVER['REQUEST_METHOD'] === 'POST') {
						$rawDdVal = GETPOST($dropdownName, 'alphanohtml');
						if ($rawDdVal !== '' && in_array($rawDdVal, array('0', '1', '2'), true)) {
							$savedDropdownValue = $rawDdVal;
						}
					}
					$dropdownOptions = $TFieldRetalho;

					// Check if this company is in the selected array (handle both strings and integers)
					$companyIdStr = (string)$companyId;
					$isCompanySelected = false;
					foreach ($savedProductCompaniesArray as $selectedId) {
						if ((string)$selectedId === $companyIdStr) {
							$isCompanySelected = true;
							break;
						}
					}

					$cssClass = $isCompanySelected ? 'company-retalho-container dzs-row' : 'company-retalho-container company-retalho-hidden dzs-row';
					$this->resprints .= '<tr class="' . $cssClass . '" data-company-id="' . htmlspecialchars($companyId) . '" style="' . ($isCompanySelected ? '' : 'display:none !important;') . '">';
					$this->resprints .= '<td style="text-align: right;">' . htmlspecialchars($companyLabel) . '</td><td colspan="3">';

					// Radio button group aligned with headers
					$this->resprints .= '<div style="display: flex; align-items: center;">';
					foreach ($dropdownOptions as $optionValue => $optionLabel) {
						$checked = ($savedDropdownValue === (string)$optionValue) ? ' checked' : '';
						$radioId = $dropdownName . '_' . $optionValue;
						$this->resprints .= '<div style="width: 120px; text-align: center; display: flex; justify-content: center; align-items: center;">';
						$this->resprints .= '<input type="radio" name="' . $dropdownName . '" id="' . $radioId . '" value="' . htmlspecialchars($optionValue) . '"' . $checked . ' style="transform: scale(1.3);">';
						$this->resprints .= '</div>';
					}
					$this->resprints .= '</div>';

					$this->resprints .= '</td></tr>';
				} // Add JavaScript to handle dynamic updating with Select2
				$this->resprints .= '<script type="text/javascript">
        			$(document).ready(function() {
        			    var multiselect = $("#DOLIZSYNCH_ZS_PRODUCT_COMPANY");
        			    var companyDropdowns = $(".company-retalho-container");

        			    // Initialize Select2 if not already initialized
        			    if (!multiselect.hasClass("select2-hidden-accessible")) {
        			        multiselect.select2();
        			    }

        			    function updateCompanyDropdowns() {
	        			        var selectedCompanies = multiselect.val() || [];

        			        // Show/hide header based on whether any companies are selected
        			        var headerRow = $("#disponibilidade-header");
        			        if (selectedCompanies.length > 0) {
        			            headerRow.show();
        			        } else {
        			            headerRow.hide();
        			        }

        			        companyDropdowns.each(function() {
	        			            var container = $(this);
	        			            var companyId = container.data("company-id").toString();

	        			            if ($.inArray(companyId, selectedCompanies) !== -1) {
	        			                // Remove hidden class and set display to table-row with !important
	        			                container.removeClass("company-retalho-hidden");
	        			                var currentStyle = container.attr("style") || "";
	        			                currentStyle = currentStyle.replace(/display\s*:\s*none\s*!important\s*;?/gi, "");
	        			                container.attr("style", currentStyle + "display: table-row !important;");
	        			            } else {
	        			                // Add hidden class and set display to none with !important
	        			                container.addClass("company-retalho-hidden");
	        			                var currentStyle = container.attr("style") || "";
	        			                if (currentStyle.indexOf("display:none !important") === -1) {
	        			                    currentStyle = currentStyle.replace(/display\s*:\s*[^;]*\s*!important\s*;?/gi, "");
	        			                    container.attr("style", currentStyle + "display:none !important;");
	        			                }
	        			            }
        			        });
        			    }

        			    multiselect.on("change", updateCompanyDropdowns);

        			    // Initial call to set the correct visibility on page load
        			    updateCompanyDropdowns();
        			});
        			</script>';

				// CodigoPP (ZS)
				$this->resprints .= '<tr><td>' . $langs->trans("DoliZSynchCodigoPP") . '</td><td colspan="3">';
				$this->resprints .= '<input type="number" class="flat" name="DOLIZSYNCH_CODIGOPP" value="' . (!empty($savedCodigoPP) ? $savedCodigoPP : '') . '" min="0" max="999999" size="50" />';
				$this->resprints .= '</td></tr>';

				// Codbarras (ZS)
				$this->resprints .= '<tr><td>' . $langs->trans("DoliZSynchCodbarras") . '</td><td colspan="3">';
				$this->resprints .= '<input type="text" class="flat" name="DOLIZSYNCH_CODBARRAS" value="' . (!empty($savedCodbarras) ? $savedCodbarras : '') . '" size="50" />';
				$this->resprints .= '</td></tr>';

				// Produtos codigos de barras (ZS) - graphical editor with hidden JSON payload
				$barcodeListLabel = $langs->trans("DoliZSynchProdutosCodigosBarras");
				if ($barcodeListLabel === "DoliZSynchProdutosCodigosBarras") {
					$barcodeListLabel = "Barcode list";
				}
				$barcodeRowsForJs = $this->decodeJsonArrayField($savedProdutosCodigosBarras);
				if (!is_array($barcodeRowsForJs)) {
					$barcodeRowsForJs = array();
				}
				$barcodeRowsForJs = $this->normalizeProdutosCodigosBarrasRows($barcodeRowsForJs);
				$pricesIncludeVat = !empty($conf->global->ZS_IVA_INCLUIDO);
				$level1Vat = 0.0;
				if (isset($object->multiprices_tva_tx) && is_array($object->multiprices_tva_tx) && isset($object->multiprices_tva_tx[1]) && $object->multiprices_tva_tx[1] !== '') {
					$level1Vat = (float) $object->multiprices_tva_tx[1];
				} elseif (isset($object->tva_tx) && $object->tva_tx !== '') {
					$level1Vat = (float) $object->tva_tx;
				}

				$hasLevel1SellPriceHt = false;
				$level1SellPriceHt = 0.0;
				if (isset($object->multiprices) && is_array($object->multiprices) && isset($object->multiprices[1]) && $object->multiprices[1] !== '') {
					$candidateLevel1SellPriceHt = (float) $object->multiprices[1];
					if ($candidateLevel1SellPriceHt >= 0) {
						$level1SellPriceHt = $candidateLevel1SellPriceHt;
						$hasLevel1SellPriceHt = true;
					}
				} elseif (isset($object->price) && $object->price !== '') {
					$candidateLevel1SellPriceHt = (float) $object->price;
					if ($candidateLevel1SellPriceHt >= 0) {
						$level1SellPriceHt = $candidateLevel1SellPriceHt;
						$hasLevel1SellPriceHt = true;
					}
				}

				$hasLevel1SellPriceTtc = false;
				$level1SellPriceTtc = 0.0;
				if (isset($object->multiprices_ttc) && is_array($object->multiprices_ttc) && isset($object->multiprices_ttc[1]) && $object->multiprices_ttc[1] !== '') {
					$candidateLevel1SellPriceTtc = (float) $object->multiprices_ttc[1];
					if ($candidateLevel1SellPriceTtc >= 0) {
						$level1SellPriceTtc = $candidateLevel1SellPriceTtc;
						$hasLevel1SellPriceTtc = true;
					}
				} elseif (isset($object->price_ttc) && $object->price_ttc !== '') {
					$candidateLevel1SellPriceTtc = (float) $object->price_ttc;
					if ($candidateLevel1SellPriceTtc >= 0) {
						$level1SellPriceTtc = $candidateLevel1SellPriceTtc;
						$hasLevel1SellPriceTtc = true;
					}
				}

				$level1SellPrice = 0.0;
				if ($pricesIncludeVat) {
					if ($hasLevel1SellPriceTtc) {
						$level1SellPrice = $level1SellPriceTtc;
					} elseif ($hasLevel1SellPriceHt) {
						$level1SellPrice = $level1SellPriceHt * (1 + ($level1Vat / 100));
					}
				} else {
					if ($hasLevel1SellPriceHt) {
						$level1SellPrice = $level1SellPriceHt;
					} elseif ($hasLevel1SellPriceTtc) {
						$vatFactor = 1 + ($level1Vat / 100);
						$level1SellPrice = ($vatFactor > 0) ? ($level1SellPriceTtc / $vatFactor) : $level1SellPriceTtc;
					}
				}

				if (!is_finite($level1SellPrice) || $level1SellPrice < 0) {
					$level1SellPrice = 0.0;
				}
				$defaultBarcodeCodigo = trim((string) $object->ref);
				if ($defaultBarcodeCodigo === '' && !empty($object->id)) {
					$defaultBarcodeCodigo = (string) ((int) $object->id);
				}
				if (preg_match('/^[0-9]+$/', $defaultBarcodeCodigo)) {
					$defaultBarcodeCodigo = (int) $defaultBarcodeCodigo;
				}
				if (empty($barcodeRowsForJs)) {
					$barcodeRowsForJs[] = array(
						'codbarras' => '',
						'codigo' => $defaultBarcodeCodigo,
						'descricao' => '',
						'qtd' => 0,
						'total' => 0,
						'_errors' => array(),
					);
				}
				$barcodeRowsJsonForJs = json_encode($barcodeRowsForJs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
				if ($barcodeRowsJsonForJs === false) {
					$barcodeRowsJsonForJs = '[]';
				}
				$barcodeHiddenPayload = $this->normalizeProdutosCodigosBarrasJson($savedProdutosCodigosBarras);
				$barcodeBuilderId = 'dzs-barcode-builder-' . ((int) $object->id);
				$barcodeHiddenId = 'DOLIZSYNCH_PRODUTOS_CODIGOSBARRAS';
				$jsBarcodeRows = $barcodeRowsJsonForJs;
				$jsBarcodeDefaultCodigo = json_encode($defaultBarcodeCodigo);
				$jsBarcodeBuilderId = json_encode($barcodeBuilderId);
				$jsBarcodeHiddenId = json_encode($barcodeHiddenId);
				$jsLevel1SellPrice = json_encode((float) $level1SellPrice);
				$this->resprints .= '<tr><td class="tdtop">' . dol_escape_htmltag($barcodeListLabel) . '</td><td colspan="3">';
				$this->resprints .= '<input type="hidden" name="' . dol_escape_htmltag($barcodeHiddenId) . '" id="' . dol_escape_htmltag($barcodeHiddenId) . '" value="' . dol_escape_htmltag($barcodeHiddenPayload) . '">';
				$this->resprints .= '<div id="' . dol_escape_htmltag($barcodeBuilderId) . '" class="dzs-barcode-editor"></div>';
				$this->resprints .= '<style>
						.dzs-barcode-editor { border: 1px solid #dfe7f3; border-radius: 8px; padding: 10px; margin-top: 8px; background: #f9fbff; }
						.dzs-barcode-editor table { width: 100%; border-collapse: collapse; }
						.dzs-barcode-editor th, .dzs-barcode-editor td { padding: 6px; border-bottom: 1px solid #e5e7eb; vertical-align: middle; }
						.dzs-barcode-editor th { margin-top: 0; padding: 2px 6px 6px; font-size: 12px; font-weight: 600; color: #6b7280; text-transform: uppercase; letter-spacing: 0.02em; text-align: left; }
						.dzs-barcode-editor input[type="text"], .dzs-barcode-editor input[type="number"] { width: 100%; box-sizing: border-box; }
							.dzs-barcode-editor .dzs-remove-wrap { width: 128px; text-align: right; }
							.dzs-barcode-editor .mb-remove { display: inline-flex; gap: 4px; align-items: center; justify-content: flex-end; }
							.dzs-barcode-editor .mb-icon-btn { display: inline-flex; align-items: center; justify-content: center; width: 32px; height: 26px; border: 1px solid transparent; border-radius: 4px; background: transparent; cursor: pointer; }
							.dzs-barcode-editor .mb-icon-btn:disabled { opacity: 0.3; cursor: not-allowed; }
							.dzs-barcode-editor .mb-icon-btn .fas { font-size: 13px; }
						</style>';
				$this->resprints .= '<script>
					(function() {
							var hidden = document.getElementById(' . $jsBarcodeHiddenId . ');
							var builder = document.getElementById(' . $jsBarcodeBuilderId . ');
							if (!hidden || !builder) return;

							var defaultCodigo = ' . $jsBarcodeDefaultCodigo . ';
							var level1SellPrice = parseNumber(' . $jsLevel1SellPrice . ');
							var rows = Array.isArray(' . $jsBarcodeRows . ') ? ' . $jsBarcodeRows . ' : [];

						function escapeHtml(str) {
							return String(str === null || str === undefined ? "" : str)
								.replace(/&/g, "&amp;")
								.replace(/</g, "&lt;")
								.replace(/>/g, "&gt;")
								.replace(/"/g, "&quot;")
								.replace(/\'/g, "&#39;");
						}

						function parseNumber(val) {
							var raw = String(val === null || val === undefined ? "" : val).trim().replace(",", ".");
							if (raw === "") return 0;
							var num = Number(raw);
							return isFinite(num) ? num : 0;
						}

						function formatFixed(val, decimals) {
							return parseNumber(val).toFixed(decimals);
						}

						function formatTotalDisplayValue(val) {
							return parseNumber(val) === 0 ? "AUTO" : formatFixed(val, 2);
						}

						function emptyRow() {
							return {
								codbarras: "",
								codigo: (defaultCodigo !== "" ? defaultCodigo : ""),
								descricao: "",
								qtd: 0,
								total: 0,
								_errors: []
							};
						}

						function ensureVisibleRow() {
							if (!rows.length) rows.push(emptyRow());
						}

						function normalizeForHidden() {
							var normalized = [];
							rows.forEach(function(row) {
								var codbarras = String(row.codbarras || "").trim();
								var codigoRaw = String(row.codigo === null || row.codigo === undefined ? "" : row.codigo).trim();
								var descricao = String(row.descricao || "").trim();
								var qtd = parseNumber(row.qtd);
								var total = parseNumber(row.total);
								if (codbarras === "" && descricao === "" && qtd === 0 && total === 0) return;
								if (codigoRaw === "" && defaultCodigo !== "" && defaultCodigo !== null && defaultCodigo !== undefined) {
									codigoRaw = String(defaultCodigo);
								}
								var codigo = (/^[0-9]+$/.test(codigoRaw) ? parseInt(codigoRaw, 10) : codigoRaw);
								normalized.push({
									codbarras: codbarras,
									codigo: codigo,
									descricao: descricao,
									qtd: qtd,
									total: total,
									_errors: []
								});
							});
							return normalized;
						}

						function syncHidden() {
							hidden.value = JSON.stringify(normalizeForHidden());
						}

						function render() {
							ensureVisibleRow();
							var html = "" +
								"<table class=\"noborder\">" +
								"<thead><tr>" +
								"<th>Barcode</th><th>Description</th><th>Qty</th><th>Total</th><th class=\"dzs-remove-wrap\"></th>" +
								"</tr></thead><tbody>";
							rows.forEach(function(row, idx) {
								html += "<tr data-idx=\"" + idx + "\">";
								html += "<td><input type=\"text\" class=\"flat\" data-role=\"codbarras\" value=\"" + escapeHtml(row.codbarras || "") + "\"></td>";
								html += "<td><input type=\"text\" class=\"flat\" data-role=\"descricao\" value=\"" + escapeHtml(row.descricao || "") + "\"></td>";
								html += "<td><input type=\"number\" step=\"0.001\" class=\"flat\" data-role=\"qtd\" value=\"" + escapeHtml(row.qtd === null || row.qtd === undefined ? "0" : row.qtd) + "\"></td>";
								html += "<td><input type=\"text\" inputmode=\"decimal\" class=\"flat\" data-role=\"total\" value=\"" + escapeHtml(formatTotalDisplayValue(row.total)) + "\"></td>";
								html += "<td class=\"dzs-remove-wrap\"><div class=\"mb-remove\">";
								html += "<button type=\"button\" class=\"mb-icon-btn\" data-role=\"calc-total\" title=\"Total = Qty x Level 1 sell price\"><span class=\"fas fa-calculator\" style=\"color:#0d6efd;\"></span></button>";
								html += "<button type=\"button\" class=\"mb-icon-btn\" data-role=\"add-item\" title=\"Add barcode\"><span class=\"fas fa-plus\" style=\"color:#28a745;\"></span></button>";
								html += "<button type=\"button\" class=\"mb-icon-btn\" data-role=\"remove-item\" title=\"Remove barcode\"><span class=\"fas fa-minus\" style=\"color:#dc3545;\"></span></button>";
								html += "</div></td>";
								html += "</tr>";
							});
							html += "</tbody></table>";
							builder.innerHTML = html;
							syncHidden();
						}

						builder.addEventListener("input", function(event) {
							var rowEl = event.target.closest("tr[data-idx]");
							if (!rowEl) return;
							var idx = parseInt(rowEl.getAttribute("data-idx"), 10);
							if (!rows[idx]) return;
							var role = event.target.getAttribute("data-role");
							if (!role) return;
							rows[idx][role] = event.target.value;
							syncHidden();
						});

						builder.addEventListener("blur", function(event) {
							if (event.target.getAttribute("data-role") !== "total") return;
							var rowEl = event.target.closest("tr[data-idx]");
							if (!rowEl) return;
							var idx = parseInt(rowEl.getAttribute("data-idx"), 10);
							if (!rows[idx]) return;
							var parsedTotal = parseNumber(event.target.value);
							if (parsedTotal === 0) {
								rows[idx].total = 0;
								event.target.value = "AUTO";
							} else {
								var normalizedTotal = formatFixed(parsedTotal, 2);
								rows[idx].total = normalizedTotal;
								event.target.value = normalizedTotal;
							}
							syncHidden();
						}, true);

						builder.addEventListener("focusin", function(event) {
							if (event.target.getAttribute("data-role") !== "total") return;
							if (String(event.target.value || "").trim().toUpperCase() === "AUTO") {
								event.target.value = "";
							}
						});

						builder.addEventListener("click", function(event) {
							var btn = event.target.closest("button[data-role=\"add-item\"], button[data-role=\"calc-total\"], button[data-role=\"remove-item\"]");
							if (!btn) return;
							var rowEl = btn.closest("tr[data-idx]");
							if (!rowEl) return;
							var idx = parseInt(rowEl.getAttribute("data-idx"), 10);
							if (isNaN(idx)) return;
							if (btn.getAttribute("data-role") === "add-item") {
								rows.splice(idx + 1, 0, emptyRow());
								render();
								return;
							}
							if (btn.getAttribute("data-role") === "calc-total") {
								rows[idx].total = formatFixed(parseNumber(rows[idx].qtd) * level1SellPrice, 2);
								render();
								return;
							}
							if (rows.length <= 1) {
								rows[0] = emptyRow();
								render();
								return;
							}
							rows.splice(idx, 1);
							render();
						});

						render();
					})();
					</script>';
				$this->resprints .= '<div class="opacitymedium" style="margin-top:6px;">If Total is 0 (AUTO), price is calculated automatically.</div>';
				$this->resprints .= '</td></tr>';

				// Descricao curta (ZS)
				$this->resprints .= '<tr><td>' . $langs->trans("DoliZSynchDescricaoCurta") . '</td><td colspan="3">';
				$this->resprints .= '<input type="text" class="flat" name="DOLIZSYNCH_DESCRICAO_CURTA" value="' . (!empty($savedDescricaoCurta) ? $savedDescricaoCurta : '') . '" size="50" />';
				$this->resprints .= '</td></tr>';

				// Referencia (ZS)
				$this->resprints .= '<tr><td>' . $langs->trans("DoliZSynchReferencia") . '</td><td colspan="3">';
				$this->resprints .= '<input type="text" class="flat" name="DOLIZSYNCH_REFERENCIA" value="' . (!empty($savedReferencia) ? $savedReferencia : '') . '" size="50" />';
				$this->resprints .= '</td></tr>';

				// Tempo de preparação (ZS)
				$this->resprints .= '<tr><td>' . $langs->trans("DoliZSynchTempoPrep") . '</td><td colspan="3">';
				$prepParts = explode(':', $this->normalizePrepTime($savedTempoPrep));
				$prepHour = isset($prepParts[0]) ? (int) $prepParts[0] : 0;
				$prepMinute = isset($prepParts[1]) ? (int) $prepParts[1] : 0;
				$prepSecond = isset($prepParts[2]) ? (int) $prepParts[2] : 0;

				$this->resprints .= '<select class="flat" name="DOLIZSYNCH_TEMPOPREP_HOUR">';
				for ($i = 0; $i <= 23; $i++) {
					$selected = ($prepHour === $i) ? ' selected="selected"' : '';
					$this->resprints .= '<option value="' . $i . '"' . $selected . '>' . sprintf('%02d', $i) . '</option>';
				}
				$this->resprints .= '</select>';
				$this->resprints .= ' : ';

				$this->resprints .= '<select class="flat" name="DOLIZSYNCH_TEMPOPREP_MINUTE">';
				for ($i = 0; $i <= 59; $i++) {
					$selected = ($prepMinute === $i) ? ' selected="selected"' : '';
					$this->resprints .= '<option value="' . $i . '"' . $selected . '>' . sprintf('%02d', $i) . '</option>';
				}
				$this->resprints .= '</select>';
				$this->resprints .= ' : ';

				$this->resprints .= '<select class="flat" name="DOLIZSYNCH_TEMPOPREP_SECOND">';
				for ($i = 0; $i <= 59; $i++) {
					$selected = ($prepSecond === $i) ? ' selected="selected"' : '';
					$this->resprints .= '<option value="' . $i . '"' . $selected . '>' . sprintf('%02d', $i) . '</option>';
				}
				$this->resprints .= '</select>';
				$this->resprints .= '<span class="opacitymedium" style="margin-left:8px;">hh:mm:ss</span>';
				$this->resprints .= '</td></tr>';

				// Tipo de Balanca (ZS)
				$currentValue = '';
				$this->resprints .= '<tr><td>' . $langs->trans("DoliZSynchProdutoBalanca") . '</td><td colspan="3">';
				$savedBalancaValue = (string) $savedBalanca;
				$this->resprints .= '<select name="DOLIZSYNCH_ZS_BALANCA" id="DOLIZSYNCH_ZS_BALANCA" class="minwidth200" style="color:#000; background-color:#fff;">';
				foreach ($TFieldBalancaWithEmpty as $value => $label) {
					$selected = ($savedBalancaValue === (string) $value) ? ' selected="selected"' : '';
					$this->resprints .= '<option value="' . htmlspecialchars($value) . '"' . $selected . ' style="color:#000; background-color:#fff;">' . htmlspecialchars($label) . '</option>';
				}
				$this->resprints .= '</select>';
				$this->resprints .= '</td></tr>';

				//Familia do produto (ZS)
				$this->resprints .= '<tr id="DOLIZSYNCH_ZS_FAMILY"><td>' . $langs->trans("DoliZSynchProductFamily") . '</td><td colspan="3">';
				$savedFamilyValue = $savedFamilia . '_' . $savedSubFam; // Combine saved values to match the option's value format
				$this->resprints .= '<select name="DOLIZSYNCH_ZS_FAMILY" id="DOLIZSYNCH_ZS_FAMILY_SELECT" class="minwidth500">';
				$this->resprints .= '<option value=""' . (empty($savedFamilia) && empty($savedSubFam) ? ' selected' : '') . '></option>'; // Add empty option
				foreach ($zapiOptionsFamilies as $option) {
					$optionValue = $option->family_id . '_' . $option->subfamily_id;
					$selected = ($savedFamilyValue == $optionValue) ? ' selected' : '';
					$label = ($option->subfamily_name == '-' || $option->subfamily_name == '') ? $option->family_name : $option->family_name . ' >> ' . $option->subfamily_name;
					$this->resprints .= '<option value="' . htmlspecialchars($optionValue) . '"' . $selected . '>' . htmlspecialchars($label) . '</option>';
				}
				$this->resprints .= '</select>';
				$this->resprints .= '</td></tr>';

				// Categoria do produto (ZS)
				$this->resprints .= '<tr id="DOLIZSYNCH_ZS_CATEGORY"><td>' . $langs->trans("DoliZSynchProductCategory") . '</td><td colspan="3">';
				$savedCategoryValue = $savedCategoria . '_' . $savedSubCategoria;
				$this->resprints .= '<select name="DOLIZSYNCH_ZS_CATEGORY" id="DOLIZSYNCH_ZS_CATEGORY_SELECT" class="minwidth500">';
				$this->resprints .= '<option value=""' . (empty($savedCategoria) && empty($savedSubCategoria) ? ' selected' : '') . '></option>';
				foreach ($zapiOptionsCategories as $option) {
					$optionValue = $option->category_id . '_' . $option->subcategory_id;
					$selected = ($savedCategoryValue == $optionValue) ? ' selected' : '';
					$label = ($option->subcategory_name == '-' || $option->subcategory_name == '') ? $option->category_name : $option->category_name . ' >> ' . $option->subcategory_name;
					$this->resprints .= '<option value="' . htmlspecialchars($optionValue) . '"' . $selected . '>' . htmlspecialchars($label) . '</option>';
				}
				$this->resprints .= '</select>';
				$this->resprints .= '</td></tr>';

				// Grupo do produto (ZS)
				$this->resprints .= '<tr id="DOLIZSYNCH_ZS_GROUP"><td>' . $langs->trans("DoliZSynchProductGroup") . '</td><td colspan="3">';
				$this->resprints .= '<select name="DOLIZSYNCH_ZS_GROUP" id="DOLIZSYNCH_ZS_GROUP_SELECT" class="minwidth500">';
				$this->resprints .= '<option value="0"' . ((string) $savedGrupo === '0' ? ' selected' : '') . '></option>';
				foreach ($zapiOptionsGroups as $option) {
					$optionValue = (string) $option->group_id;
					$selected = ((string) $savedGrupo === $optionValue) ? ' selected' : '';
					$label = $option->group_name ? $option->group_name : '';
					$this->resprints .= '<option value="' . htmlspecialchars($optionValue) . '"' . $selected . '>' . htmlspecialchars($label) . '</option>';
				}
				$this->resprints .= '</select>';
				$this->resprints .= '</td></tr>';

				// Unidade (ZS)
				$this->resprints .= '<tr><td>' . $langs->trans("DoliZSynchProductUnit") . '</td><td colspan="3">';
				$this->resprints .= '<select name="DOLIZSYNCH_PRODUCT_UNIDADE" id="DOLIZSYNCH_PRODUCT_UNIDADE_SELECT" class="minwidth500">';
				$this->resprints .= '<option value=""' . (empty($savedUnidade) ? ' selected' : '') . '></option>';
				foreach ($zapiOptionsUnits as $option) {
					$optionValue = $option->unit_id;
					$selected = ($savedUnidade == $optionValue) ? ' selected' : '';
					$label = $option->unit_name ? $option->unit_name : '';
					$this->resprints .= '<option value="' . htmlspecialchars($optionValue) . '"' . $selected . '>' . htmlspecialchars($label) . '</option>';
				}
				$this->resprints .= '</select>';
				$this->resprints .= '</td></tr>';

				// Unidade de compra (ZS)
				$this->resprints .= '<tr><td>' . $langs->trans("DoliZSynchProductUnitCompra") . '</td><td colspan="3">';
				$this->resprints .= '<select name="DOLIZSYNCH_PRODUCT_UNCOMPRA" id="DOLIZSYNCH_PRODUCT_UNCOMPRA_SELECT" class="minwidth500">';
				$this->resprints .= '<option value=""' . (empty($savedUncompra) ? ' selected' : '') . '></option>';
				foreach ($zapiOptionsUnits as $option) {
					$optionValue = $option->unit_id;
					$selected = ($savedUncompra == $optionValue) ? ' selected' : '';
					$label = $option->unit_name ? $option->unit_name : '';
					$this->resprints .= '<option value="' . htmlspecialchars($optionValue) . '"' . $selected . '>' . htmlspecialchars($label) . '</option>';
				}
				$this->resprints .= '</select>';
				$this->resprints .= '</td></tr>';

				// Unidade de inventario (ZS)
				$this->resprints .= '<tr><td>' . $langs->trans("DoliZSynchProductUnitInventario") . '</td><td colspan="3">';
				$this->resprints .= '<select name="DOLIZSYNCH_PRODUCT_UNINVENTARIO" id="DOLIZSYNCH_PRODUCT_UNINVENTARIO_SELECT" class="minwidth500">';
				$this->resprints .= '<option value=""' . (empty($savedUninventario) ? ' selected' : '') . '></option>';
				foreach ($zapiOptionsUnits as $option) {
					$optionValue = $option->unit_id;
					$selected = ($savedUninventario == $optionValue) ? ' selected' : '';
					$label = $option->unit_name ? $option->unit_name : '';
					$this->resprints .= '<option value="' . htmlspecialchars($optionValue) . '"' . $selected . '>' . htmlspecialchars($label) . '</option>';
				}
				$this->resprints .= '</select>';
				$this->resprints .= '</td></tr>';

				// Tipo de Produto (ZS)
				$currentValue = '';
				$this->resprints .= '<tr><td>' . $langs->trans("DoliZSynchProcuctTipoProduto") . '</td><td colspan="3">';
				$this->resprints .= '<select name="DOLIZSYNCH_ZS_TIPO_PRODUTO" id="DOLIZSYNCH_ZS_TIPO_PRODUTO_SELECT" class="minwidth200">';
				$this->resprints .= '<option value=""' . (empty($savedTipoSaft) ? ' selected' : '') . '></option>';
				foreach ($TFieldTipoProduto as $value => $label) {
					$selected = ($savedTipoSaft == $value) ? ' selected="selected"' : '';
					$this->resprints .= '<option value="' . htmlspecialchars($value) . '"' . $selected . '>' . htmlspecialchars($label) . '</option>';
				}
				$this->resprints .= '</select>';
				$this->resprints .= '</td></tr>';

				// Isencao (ZS)
				$this->resprints .= '<tr><td>' . $langs->trans("DoliZSynchIsencao") . '</td><td colspan="3">';
				$currentValue = (string) $savedIsencao;
				if ($currentValue === '') {
					$currentValue = '0';
				}
				$this->resprints .= '<select name="DOLIZSYNCH_ZS_ISENCAO" id="DOLIZSYNCH_ZS_ISENCAO" class="minwidth200">';
				foreach ($TFieldIsencao as $value => $label) {
					$selected = ((string) $currentValue === (string) $value) ? ' selected="selected"' : '';
					$this->resprints .= '<option value="' . htmlspecialchars($value) . '"' . $selected . '>' . htmlspecialchars($label) . '</option>';
				}
				$this->resprints .= '</select>';
				$this->resprints .= '</td></tr>';

				// IVA 2 (ZS)
				$this->resprints .= '<tr><td>' . $langs->trans("DoliZSynchIva2") . '</td><td colspan="3">';
				$this->resprints .= $this->renderZsTaxSelect("DOLIZSYNCH_ZS_IVA2", $savedIva2, $zapiOptionsTaxes);
				$this->resprints .= '</td></tr>';

				// IVA Compra (ZS)
				$this->resprints .= '<tr><td>' . $langs->trans("DoliZSynchIvaCompra") . '</td><td colspan="3">';
				$this->resprints .= $this->renderZsTaxSelect("DOLIZSYNCH_ZS_IVACOMPRA", $savedIvaCompra, $zapiOptionsTaxes);
				$this->resprints .= '</td></tr>';

				// IVA Revenda (ZS)
				$this->resprints .= '<tr><td>' . $langs->trans("DoliZSynchIvaRevenda") . '</td><td colspan="3">';
				$this->resprints .= $this->renderZsTaxSelect("DOLIZSYNCH_ZS_IVAREVENDA", $savedIvaRevenda, $zapiOptionsTaxes);
				$this->resprints .= '</td></tr>';

				// Retencao na fonte
				$checked = $savedRetencao ? 'checked' : '';
				$this->resprints .= '<tr id="DOLIZSYNCH_ZS_RETENCAO"><td>' . $langs->trans("DoliZSynchRetencao") . '</td><td colspan="3">';
				$this->resprints .= '<input type="checkbox" name="DOLIZSYNCH_ZS_RETENCAO" ' . $checked . ' value="1">';
				$this->resprints .= '</td></tr>';

				// Prepagamento (ZS)
				$checked = $savedPrepagamento ? 'checked' : '';
				$this->resprints .= '<tr><td>' . $langs->trans("DoliZSynchPrepagamento") . '</td><td colspan="3">';
				$this->resprints .= '<input type="checkbox" name="DOLIZSYNCH_PREPAGAMENTO" ' . $checked . ' value="1">';
				$this->resprints .= '</td></tr>';

				// Topo (ZS)
				$checked = $savedTopo ? 'checked' : '';
				$this->resprints .= '<tr><td>' . $langs->trans("DoliZSynchTopo") . '</td><td colspan="3">';
				$this->resprints .= '<input type="checkbox" name="DOLIZSYNCH_TOPO" ' . $checked . ' value="1">';
				$this->resprints .= '</td></tr>';

				// Revenda (ZS)
				$checked = $savedRevenda ? 'checked' : '';
				$this->resprints .= '<tr><td>' . $langs->trans("DoliZSynchRevenda") . '</td><td colspan="3">';
				$this->resprints .= '<input type="checkbox" name="DOLIZSYNCH_REVENDA" ' . $checked . ' value="1">';
				$this->resprints .= '</td></tr>';

				// Excluir dos descontos (ZS)
				$checked = $savedExcluirdescontos ? 'checked' : '';
				$this->resprints .= '<tr><td>' . $langs->trans("DoliZSynchExcluirDescontos") . '</td><td colspan="3">';
				$this->resprints .= '<input type="checkbox" name="DOLIZSYNCH_EXCLUIRDESCONTOS" ' . $checked . ' value="1">';
				$this->resprints .= '</td></tr>';

				// Additional ZSBMS sales option
				$checked = $savedConsumoMinimo ? 'checked' : '';
				$this->resprints .= '<tr><td>' . $langs->trans("DoliZSynchConsumoMinimo") . '</td><td colspan="3">';
				$this->resprints .= '<input type="checkbox" name="DOLIZSYNCH_CONSUMOMINIMO" ' . $checked . ' value="1">';
				$this->resprints .= '</td></tr>';

				// Additional ZSBMS sales option
				$checked = $savedEmentaDigital ? 'checked' : '';
				$this->resprints .= '<tr><td>' . $langs->trans("DoliZSynchEmentaDigital") . '</td><td colspan="3">';
				$this->resprints .= '<input type="checkbox" name="DOLIZSYNCH_EMENTADIGITAL" ' . $checked . ' value="1">';
				$this->resprints .= '</td></tr>';

				// Fuel (ZS)
				$checked = $savedFuel ? 'checked' : '';
				$this->resprints .= '<tr><td>' . $langs->trans("DoliZSynchFuel") . '</td><td colspan="3">';
				$this->resprints .= '<input type="checkbox" name="DOLIZSYNCH_FUEL" ' . $checked . ' value="1">';
				$this->resprints .= '</td></tr>';

				// Transferivel (ZS)
				$checked = $savedTransferivel ? 'checked' : '';
				$this->resprints .= '<tr><td>' . $langs->trans("DoliZSynchTransferivel") . '</td><td colspan="3">';
				$this->resprints .= '<input type="checkbox" name="DOLIZSYNCH_TRANSFERIVEL" ' . $checked . ' value="1">';
				$this->resprints .= '</td></tr>';



				// Cor de fundo do botão de produto (ZS)
				$this->resprints .= '<tr><td>' . $langs->trans("DoliZSynchProductBackgroundColorSelector") . '</td><td colspan="3">';
				$this->resprints .= '<input type="color" name="DOLIZSYNCH_ZS_BACKGROUND_COLOR" value="' . dol_escape_htmltag($savedBackgroundColor) . '">';
				$this->resprints .= '</td></tr>';

				// Cor da letra do botão de produto (ZS)
				$this->resprints .= '<tr><td>' . $langs->trans("DoliZSynchProductFontColorSelector") . '</td><td colspan="3">';
				$this->resprints .= '<input type="color" name="DOLIZSYNCH_ZS_FONT_COLOR" value="' . dol_escape_htmltag($savedFontColor) . '">';
				$this->resprints .= '</td></tr>';

				// Tipo de Produto (Menu, Venda, Mercadoria) (ZS)
				$currentValue = '';
				$this->resprints .= '<tr><td>' . $langs->trans("DoliZSynchProdutoComposto") . '</td><td colspan="3">';
				$savedCompostoValue = (string) $savedComposto;
				$this->resprints .= '<select name="DOLIZSYNCH_ZS_COMPOSTO" id="DOLIZSYNCH_ZS_COMPOSTO" class="minwidth200" style="color:#000; background-color:#fff;">';
				foreach ($TFieldCompostoWithEmpty as $value => $label) {
					$selected = ($savedCompostoValue === (string) $value) ? ' selected="selected"' : '';
					$this->resprints .= '<option value="' . htmlspecialchars($value) . '"' . $selected . ' style="color:#000; background-color:#fff;">' . htmlspecialchars($label) . '</option>';
				}
				$this->resprints .= '</select>';
				$this->resprints .= '</td></tr>';
				$this->resprints .= '<style>#select2-DOLIZSYNCH_ZS_BALANCA-container,#select2-DOLIZSYNCH_ZS_COMPOSTO-container,#select2-DOLIZSYNCH_ZS_BALANCA-container .placeholder,#select2-DOLIZSYNCH_ZS_COMPOSTO-container .placeholder{color:#000 !important;}</style>';
				if (function_exists('ajax_combobox')) {
					$this->resprints .= ajax_combobox('DOLIZSYNCH_ZS_BALANCA', array(), 0, 0, 'resolve', '-1', 'minwidth200');
					$this->resprints .= ajax_combobox('DOLIZSYNCH_ZS_FAMILY_SELECT', array(), 0, 0, 'resolve', '0', 'minwidth500');
					$this->resprints .= ajax_combobox('DOLIZSYNCH_ZS_CATEGORY_SELECT', array(), 0, 0, 'resolve', '0', 'minwidth500');
					$this->resprints .= ajax_combobox('DOLIZSYNCH_ZS_GROUP_SELECT', array(), 0, 0, 'resolve', '0', 'minwidth500');
					$this->resprints .= ajax_combobox('DOLIZSYNCH_PRODUCT_UNIDADE_SELECT', array(), 0, 0, 'resolve', '0', 'minwidth500');
					$this->resprints .= ajax_combobox('DOLIZSYNCH_PRODUCT_UNCOMPRA_SELECT', array(), 0, 0, 'resolve', '0', 'minwidth500');
					$this->resprints .= ajax_combobox('DOLIZSYNCH_PRODUCT_UNINVENTARIO_SELECT', array(), 0, 0, 'resolve', '0', 'minwidth500');
					$this->resprints .= ajax_combobox('DOLIZSYNCH_ZS_TIPO_PRODUTO_SELECT', array(), 0, 0, 'resolve', '0', 'minwidth200');
					$this->resprints .= ajax_combobox('DOLIZSYNCH_ZS_ISENCAO', array(), 0, 0, 'resolve', '0', 'minwidth200');
					$this->resprints .= ajax_combobox('DOLIZSYNCH_ZS_IVA2_SELECT', array(), 0, 0, 'resolve', '0', 'minwidth200');
					$this->resprints .= ajax_combobox('DOLIZSYNCH_ZS_IVACOMPRA_SELECT', array(), 0, 0, 'resolve', '0', 'minwidth200');
					$this->resprints .= ajax_combobox('DOLIZSYNCH_ZS_IVAREVENDA_SELECT', array(), 0, 0, 'resolve', '0', 'minwidth200');
					$this->resprints .= ajax_combobox('DOLIZSYNCH_ZS_COMPOSTO', array(), 0, 0, 'resolve', '-1', 'minwidth200');
				}
				$this->resprints .= '<script>
				document.addEventListener("DOMContentLoaded", function() {
					if (typeof window.jQuery === "undefined" || typeof window.jQuery.fn.select2 === "undefined") {
						return;
					}

					function normalizeZsSelectSearch(value) {
						var normalized = (value || "").toString();
						if (typeof normalized.normalize === "function") {
							normalized = normalized.normalize("NFD").replace(/[\u0300-\u036f]/g, "");
						}
						return normalized
							.toLowerCase()
							.replace(/&gt;/g, " ")
							.replace(/&amp;/g, " ")
							.replace(/[^a-z0-9]+/g, " ")
							.replace(/\s+/g, " ")
							.trim();
					}

					function appendSingularTokens(searchText) {
						var tokens = searchText.split(" ");
						var enriched = searchText;
						for (var i = 0; i < tokens.length; i++) {
							if (tokens[i].length > 3 && tokens[i].slice(-1) === "s") {
								enriched += " " + tokens[i].slice(0, -1);
							}
						}
						return enriched;
					}

					function zsSelectMatcher(params, data) {
						var term = normalizeZsSelectSearch(params.term);
						if (term === "") {
							return data;
						}

						if (data.children && data.children.length) {
							var matchedChildren = [];
							for (var childIndex = 0; childIndex < data.children.length; childIndex++) {
								var matchedChild = zsSelectMatcher(params, data.children[childIndex]);
								if (matchedChild !== null) {
									matchedChildren.push(matchedChild);
								}
							}
							if (matchedChildren.length) {
								var matchedData = window.jQuery.extend({}, data);
								matchedData.children = matchedChildren;
								return matchedData;
							}
							return null;
						}

						var optionText = data.text || "";
						if (data.element) {
							optionText += " " + (data.element.textContent || data.element.innerText || "");
							optionText += " " + (data.element.getAttribute("data-search") || "");
						}

						var haystack = appendSingularTokens(normalizeZsSelectSearch(optionText));
						haystack += " " + haystack.replace(/\s+/g, "");
						var keywords = term.split(" ");
						for (var keywordIndex = 0; keywordIndex < keywords.length; keywordIndex++) {
							if (keywords[keywordIndex] !== "" && haystack.indexOf(keywords[keywordIndex]) === -1) {
								return null;
							}
						}
						return data;
					}

					function installZsSelectMatcher(selector) {
						var select = window.jQuery(selector);
						if (!select.length) {
							return;
						}

						function install() {
							var instance = select.data("select2");
							if (!instance || !instance.options || !instance.options.options) {
								return false;
							}
							instance.options.options.matcher = zsSelectMatcher;
							return true;
						}

						if (!install()) {
							window.setTimeout(install, 100);
							window.setTimeout(install, 500);
						}
					}

					installZsSelectMatcher("#DOLIZSYNCH_ZS_FAMILY_SELECT");
					installZsSelectMatcher("#DOLIZSYNCH_ZS_CATEGORY_SELECT");
				});
				</script>';

				// Quebra de fim de dia (ZS) - Only show for composto value 5
				$checked = $savedQuebraFimDeDia ? 'checked' : '';
				$this->resprints .= '<tr id="DOLIZSYNCH_ZS_QUEBRA_FIM_DIA"><td>' . $langs->trans("DoliZSynchQuebraFimDia") . '</td><td colspan="3">';
				$this->resprints .= '<input type="checkbox" name="DOLIZSYNCH_ZS_QUEBRA_FIM_DIA" ' . $checked . ' value="1">';
				$this->resprints .= '</td></tr>';

				// Permitir vender sem stock (ZS) - Only show for composto value 5
				$checked = $savedVenderSemStock ? 'checked' : '';
				$this->resprints .= '<tr id="DOLIZSYNCH_ZS_VENDER_SEM_STOCK"><td>' . $langs->trans("DoliZSynchVenderSemStock") . '</td><td colspan="3">';
				$this->resprints .= '<input type="checkbox" name="DOLIZSYNCH_ZS_VENDER_SEM_STOCK" ' . $checked . ' value="1">';
				$this->resprints .= '</td></tr>';

				// Menu (ZS) builder
				// Skip builder rendering if we're on the dedicated zsproduct page (from_dolizsynch_page) or the tab (from_dolizsynch_tab),
				// because those pages render their own inline builder to avoid duplicate IDs/inputs.
				if (empty($parameters['from_dolizsynch_tab']) && empty($parameters['from_dolizsynch_page'])) {
					$menuDecodedForJs = array();
					$menuJsonForField = '[]';
					if (!empty($savedNiveismenu)) {
						$tmpMenu = json_decode($savedNiveismenu, true);
						if (json_last_error() === JSON_ERROR_NONE && is_array($tmpMenu)) {
							$menuDecodedForJs = $tmpMenu;
							$menuJsonForField = $savedNiveismenu;
						}
					}
					if ($menuJsonForField === '[]' && !empty($savedNiveismenu)) {
						$menuJsonForField = $savedNiveismenu;
					}

					// External builder shortcut (opens standalone page)
					$this->resprints .= '<tr><td class="tdtop">' . dol_escape_htmltag($langs->trans("DoliZSynchProductMenu")) . '</td><td colspan="3">';
					$this->resprints .= '<input type="hidden" name="DOLIZSYNCH_ZS_NIVEISMENU" id="DOLIZSYNCH_ZS_NIVEISMENU" value="' . dol_escape_htmltag($menuJsonForField) . '">';
					$this->resprints .= '<div style="margin-bottom:6px;"><a class="button smallpaddingimp" target="_blank" href="' . dol_buildpath('/custom/kreaflow/test/product_tab_menu_builder.php', 1) . '?id=' . (int) $object->id . '">' . $langs->trans("Open") . ' builder</a> <span class="opacitymedium">Abre o builder completo noutra página.</span></div>';
					$this->resprints .= '<div id="dzs-menu-builder"></div>';
					$this->resprints .= '<div style="margin-top:6px; display:flex; gap:8px; align-items:center;">';
					$this->resprints .= '<button type="button" class="button smallpaddingimp" id="dzs-menu-add-level">' . $langs->trans("Add") . ' nível</button>';
					$this->resprints .= '<button type="button" class="button smallpaddingimp" id="dzs-menu-clear">' . $langs->trans("Delete") . ' tudo</button>';
					$this->resprints .= '<span class="opacitymedium">Construa os níveis e itens do menu (será guardado em niveismenu).</span>';
					$this->resprints .= '</div>';

					$initialPreview = $this->renderMenuCardsRow($menuJsonForField, $store);
					$this->resprints .= '<div id="dzs-menu-preview" style="margin-top:12px;">' . $initialPreview . '</div>';

					$this->resprints .= '<style>
						.dzs-builder-card {border:1px solid #dfe7f3; border-radius:8px; padding:10px; margin-bottom:10px; background:#f9fbff;}
						.dzs-builder-card.dragging {opacity:0.6;}
						.dzs-builder-head {display:flex; gap:6px; flex-wrap:wrap; align-items:center;}
						.dzs-builder-head input[type="text"],
						.dzs-builder-head input[type="number"] {width:140px;}
						.dzs-item-row {display:grid; grid-template-columns:28px 90px 1fr 80px 90px 110px 110px 90px 40px; gap:6px; align-items:center; margin-top:6px;}
						.dzs-item-row.dragging {opacity:0.6;}
						.dzs-item-row input[type="text"],
						.dzs-item-row input[type="number"] {width:100%; box-sizing:border-box;}
						.dzs-item-row select {width:100%;}
						.dzs-item-row .dzs-remove {text-align:center;}
						.dzs-item-row .dzs-remove button {min-width:32px;}
						#dzs-menu-builder .dzs-card-title {font-weight:700;}
						.dzs-builder-empty {padding:8px; color:#555;}
						.dzs-handle {cursor:grab; color:#555; user-select:none;}
					</style>';

					$this->resprints .= '<script>
(function(){
	const hidden = document.getElementById("DOLIZSYNCH_ZS_NIVEISMENU");
	const container = document.getElementById("dzs-menu-builder");
	const addBtn = document.getElementById("dzs-menu-add-level");
	const clearBtn = document.getElementById("dzs-menu-clear");
	const previewDiv = document.getElementById("dzs-menu-preview");
	if(!hidden || !container){ return; }

	const productMenuCode = ' . json_encode($object->ref ? $object->ref : $object->id) . ';
	let state = [];
	let dragData = null;
	try {
		const parsed = ' . json_encode($menuDecodedForJs) . ';
		if (Array.isArray(parsed)) { state = parsed; }
	} catch (e) {}
	// Fallback: parse from hidden field if PHP-decoded state is empty
	if ((!state || !state.length) && hidden && hidden.value) {
		try {
			const parsedHidden = JSON.parse(hidden.value);
			if (Array.isArray(parsedHidden)) state = parsedHidden;
		} catch (e1) {
			try {
				const repaired = hidden.value.replace(/&quot;/g, \'"\');
				const parsedHidden2 = JSON.parse(repaired);
				if (Array.isArray(parsedHidden2)) state = parsedHidden2;
			} catch (e2) {}
		}
	}

	function normalizeLevel(level, idx){
		const safe = Object.assign({
			descricao: "",
			menu: productMenuCode,
			nivel: idx + 1,
			obrigatorio: 0,
			ordem: 0,
			niveismenuext: []
		}, level || {});
		safe.nivel = idx + 1;
		safe.obrigatorio = safe.obrigatorio ? 1 : 0;
		safe.ordem = parseInt(safe.ordem, 10); if(isNaN(safe.ordem)) safe.ordem = 0;
		if(!Array.isArray(safe.niveismenuext)){ safe.niveismenuext = []; }
		return safe;
	}

	function normalizeItem(item, level){
		const safe = Object.assign({
			codigo: "",
			descricao: "",
			preco: "",
			fixo: 1,
			opcaopvp: 0,
			valcomplementos: 1,
			produtodefault: 0,
			fk_product: item && item.fk_product ? item.fk_product : null
		}, item || {});
		safe.codigo = safe.codigo !== null && safe.codigo !== undefined ? safe.codigo : "";
		safe.preco = safe.preco === "" || safe.preco === null ? "" : safe.preco;
		safe.fixo = parseInt(safe.fixo, 10); if(isNaN(safe.fixo)) safe.fixo = 1;
		safe.opcaopvp = safe.opcaopvp ? 1 : 0;
		safe.valcomplementos = safe.valcomplementos ? 1 : 0;
		safe.produtodefault = safe.produtodefault ? 1 : 0;
		safe.menu = productMenuCode;
		safe.nivel = level.nivel;
		safe._errors = [];
		return safe;
	}

	function syncHidden(){
		const cleaned = state.map((lvl, idx) => {
			const normalizedLevel = normalizeLevel(lvl, idx);
			const items = normalizedLevel.niveismenuext.map(it => normalizeItem(it, normalizedLevel)).filter(it => (it.codigo !== "" && it.codigo !== null));
			return {
				descricao: normalizedLevel.descricao,
				menu: productMenuCode,
				nivel: normalizedLevel.nivel,
				obrigatorio: normalizedLevel.obrigatorio ? 1 : 0,
				ordem: normalizedLevel.ordem || 0,
				niveismenuext: items,
				_errors: []
			};
		}).filter(lvl => lvl.descricao !== "" || (lvl.niveismenuext && lvl.niveismenuext.length));
		hidden.value = JSON.stringify(cleaned);
	}

	function render(){
		container.innerHTML = "";
		if(!state.length){
			const empty = document.createElement("div");
			empty.className = "dzs-builder-empty";
			empty.textContent = "Sem níveis definidos. Adicione um nível para começar.";
			container.appendChild(empty);
		}
		state.forEach((lvl, idx) => {
			const levelObj = normalizeLevel(lvl, idx);
			state[idx] = levelObj; // keep state normalized (renumber after deletions)
			const card = document.createElement("div");
			card.className = "dzs-builder-card";
			card.dataset.index = idx.toString();
			card.setAttribute("draggable", "true");
			card.innerHTML = `
				<div class="dzs-builder-head">
					<span class="dzs-handle" title="Arrastar nível" data-role="drag-level">&#9776;</span>
					<span class="dzs-card-title">Nível #${levelObj.nivel}</span>
					<input type="text" class="flat" data-role="descricao" placeholder="Descrição" value="${dolEscape(levelObj.descricao)}">
					<label><input type="checkbox" data-role="obrigatorio" ${levelObj.obrigatorio ? "checked" : ""}> Obrigatório</label>
					<label style="display:flex; align-items:center; gap:4px;">Ordem <input type="number" data-role="ordem" class="flat" value="${levelObj.ordem}" style="width:70px;"></label>
					<button type="button" class="button smallpaddingimp" data-role="add-item">+ Item</button>
					<button type="button" class="button smallpaddingimp" data-role="remove-level" style="margin-left:auto;">Remover nível</button>
				</div>
				<div class="dzs-items"></div>
			`;
			const itemsWrap = card.querySelector(".dzs-items");
			levelObj.niveismenuext.forEach((it, itemIdx) => {
				const itemObj = normalizeItem(it, levelObj);
				const row = document.createElement("div");
				row.className = "dzs-item-row";
				row.dataset.itemIndex = itemIdx.toString();
				row.setAttribute("draggable", "true");
				row.innerHTML = `
					<span class="dzs-handle" title="Arrastar item" data-role="drag-item">&#9776;</span>
					<input type="text" class="flat" data-role="codigo" placeholder="Código" value="${dolEscape(itemObj.codigo)}">
					<input type="text" class="flat" data-role="descricao" placeholder="Descrição" value="${dolEscape(itemObj.descricao || "")}">
					<input type="number" step="0.01" class="flat" data-role="preco" placeholder="Preço" value="${itemObj.preco !== null && itemObj.preco !== undefined ? itemObj.preco : ""}">
					<select data-role="fixo" class="flat">
						<option value="1" ${itemObj.fixo === 1 ? "selected" : ""}>Produto</option>
						<option value="0" ${itemObj.fixo === 0 ? "selected" : ""}>Flexível</option>
						<option value="3" ${itemObj.fixo === 3 ? "selected" : ""}>Família/Subfamília</option>
					</select>
					<label><input type="checkbox" data-role="opcaopvp" ${itemObj.opcaopvp ? "checked" : ""}> opcaopvp</label>
					<label><input type="checkbox" data-role="valcomplementos" ${itemObj.valcomplementos ? "checked" : ""}> valcomplementos</label>
					<label><input type="checkbox" data-role="produtodefault" ${itemObj.produtodefault ? "checked" : ""}> default</label>
					<div class="dzs-remove"><button type="button" class="button smallpaddingimp" data-role="remove-item">x</button></div>
				`;
				itemsWrap.appendChild(row);
			});
			container.appendChild(card);
		});
		syncHidden();
		renderPreview();
	}

	function dolEscape(str){
		if(str === null || str === undefined) return "";
		return String(str).replace(/[&<>"\']/g, function(c){
			return {"&":"&amp;","<":"&lt;",">":"&gt;","\"":"&quot;","\'":"&#39;"}[c];
		});
	}

	function renderPreview(){
		if(!previewDiv){ return; }
		if(!state.length){ previewDiv.innerHTML = ""; return; }
		let html = "<div class=\"dzs-menu\">";
		state.forEach((lvl, idx) => {
			const levelObj = normalizeLevel(lvl, idx);
			html += "<div class=\"dzs-card\">";
			html += "<div class=\"dzs-head\"><span class=\"dzs-level\">#" + levelObj.nivel + "</span><span class=\"dzs-label\">" + dolEscape(levelObj.descricao) + "</span>" + (levelObj.obrigatorio ? "<span class=\"dzs-pill\">Obrigatório</span>" : "") + "</div>";
			if(levelObj.niveismenuext && levelObj.niveismenuext.length){
				html += "<div class=\"dzs-list\">";
				levelObj.niveismenuext.forEach((it) => {
					const price = (it.preco !== "" && it.preco !== null && it.preco !== undefined && !isNaN(parseFloat(it.preco))) ? "(" + parseFloat(it.preco).toFixed(2) + ")" : "";
					html += "<div class=\"dzs-row\"><span class=\"dzs-code\">#" + dolEscape(it.codigo) + "</span><span class=\"dzs-desc\">" + dolEscape(it.descricao || "") + "</span><span class=\"dzs-price\">" + price + "</span></div>";
				});
				html += "</div>";
			}
			html += "</div>";
		});
		html += "</div>";
		previewDiv.innerHTML = html;
	}

	container.addEventListener("dragstart", function(ev){
		const levelCard = ev.target.closest(".dzs-builder-card");
		const itemRow = ev.target.closest(".dzs-item-row");
		if(levelCard && ev.target.closest("[data-role=\"drag-level\"]")){
			dragData = {type:"level", levelIndex: parseInt(levelCard.dataset.index,10)};
			levelCard.classList.add("dragging");
			ev.dataTransfer.effectAllowed = "move";
		} else if(itemRow && ev.target.closest("[data-role=\"drag-item\"]")){
			const lvlCard = ev.target.closest(".dzs-builder-card");
			if(!lvlCard) return;
			dragData = {type:"item", levelIndex: parseInt(lvlCard.dataset.index,10), itemIndex: parseInt(itemRow.dataset.itemIndex,10)};
			itemRow.classList.add("dragging");
			ev.dataTransfer.effectAllowed = "move";
		}
	});

	container.addEventListener("dragend", function(ev){
		container.querySelectorAll(".dragging").forEach(el => el.classList.remove("dragging"));
		dragData = null;
	});

	container.addEventListener("dragover", function(ev){
		if(!dragData){ return; }
		ev.preventDefault();
		ev.dataTransfer.dropEffect = "move";
	});

	container.addEventListener("drop", function(ev){
		if(!dragData){ return; }
		ev.preventDefault();
		if(dragData.type === "level"){
			const targetCard = ev.target.closest(".dzs-builder-card");
			let targetIdx = targetCard ? parseInt(targetCard.dataset.index,10) : state.length-1;
			if(isNaN(targetIdx)){ targetIdx = state.length-1; }
			const fromIdx = dragData.levelIndex;
			if(fromIdx === targetIdx) return;
			const moving = state.splice(fromIdx,1)[0];
			if(fromIdx < targetIdx) targetIdx -= 1;
			state.splice(targetIdx,0,moving);
			render();
			return;
		}
		if(dragData.type === "item"){
			const targetRow = ev.target.closest(".dzs-item-row");
			const targetCard = ev.target.closest(".dzs-builder-card");
			if(!targetCard){ return; }
			const toLevelIdx = parseInt(targetCard.dataset.index,10);
			const fromLevelIdx = dragData.levelIndex;
			const fromItemIdx = dragData.itemIndex;
			if(isNaN(toLevelIdx) || isNaN(fromLevelIdx) || isNaN(fromItemIdx)){ return; }
			const fromLevel = state[fromLevelIdx];
			const item = fromLevel && fromLevel.niveismenuext ? fromLevel.niveismenuext.splice(fromItemIdx,1)[0] : null;
			if(!item){ return; }
			const toLevel = state[toLevelIdx];
			if(!toLevel.niveismenuext){ toLevel.niveismenuext = []; }
			let insertIdx = toLevel.niveismenuext.length;
			if(targetRow && targetRow.dataset.itemIndex){
				insertIdx = parseInt(targetRow.dataset.itemIndex,10);
				if(fromLevelIdx === toLevelIdx && fromItemIdx < insertIdx){ insertIdx -= 1; }
			}
			if(insertIdx < 0) insertIdx = 0;
			if(insertIdx > toLevel.niveismenuext.length) insertIdx = toLevel.niveismenuext.length;
			toLevel.niveismenuext.splice(insertIdx,0,item);
			render();
		}
	});

	container.addEventListener("input", function(ev){
		const target = ev.target;
		const levelCard = target.closest(".dzs-builder-card");
		if(!levelCard) return;
		const idx = parseInt(levelCard.dataset.index, 10);
		if(isNaN(idx) || !state[idx]) return;
		const role = target.getAttribute("data-role");
		if(role === "descricao"){
			state[idx].descricao = target.value;
		} else if (role === "obrigatorio"){
			state[idx].obrigatorio = target.checked ? 1 : 0;
		} else if (role === "ordem"){
			state[idx].ordem = target.value;
		} else if (role){
			const row = target.closest(".dzs-item-row");
			if(row){
				const itemIdx = parseInt(row.dataset.itemIndex, 10);
				if(!state[idx].niveismenuext[itemIdx]) return;
				if(role === "codigo" || role === "descricao"){
					state[idx].niveismenuext[itemIdx][role] = target.value;
				} else if (role === "preco" || role === "fixo"){
					state[idx].niveismenuext[itemIdx][role] = target.value;
				} else if (role === "opcaopvp" || role === "valcomplementos" || role === "produtodefault"){
					state[idx].niveismenuext[itemIdx][role] = target.checked ? 1 : 0;
				}
			}
		}
		syncHidden();
	});

	container.addEventListener("click", function(ev){
		const target = ev.target;
		const levelCard = target.closest(".dzs-builder-card");
		if(!levelCard) return;
		const idx = parseInt(levelCard.dataset.index, 10);
		if(isNaN(idx)) return;
		const role = target.getAttribute("data-role");
		if(role === "add-item"){
			if(!state[idx].niveismenuext) state[idx].niveismenuext = [];
			state[idx].niveismenuext.push({codigo:\"\", descricao:\"\", preco:\"\", fixo:1, opcaopvp:0, valcomplementos:1, produtodefault:0});
			render();
		} else if (role === "remove-level"){
			state.splice(idx,1);
			render();
		} else if (role === "remove-item"){
			const row = target.closest(".dzs-item-row");
			if(!row) return;
			const itemIdx = parseInt(row.dataset.itemIndex, 10);
			if(!isNaN(itemIdx) && state[idx].niveismenuext){ state[idx].niveismenuext.splice(itemIdx,1); }
			render();
		}
	});

	if(addBtn){
		addBtn.addEventListener("click", function(){
			state.push({descricao:\"\", obrigatorio:1, ordem:0, menu:productMenuCode, nivel: state.length+1, niveismenuext: []});
			render();
		});
	}
	if(clearBtn){
		clearBtn.addEventListener("click", function(){
			if(confirm("Limpar todos os níveis?")){
				state = [];
				render();
			}
		});
	}

	render();
})();
</script>';
				} // End: Skip builder on zsproduct.php

				$this->resprints .= '</tbody>';
			} else {
				// Wrap the view block so it can be repositioned after other module sections
				$this->resprints .= '<tbody class="dolizsynch-config-block dolizsynch-config-block-view" data-zs-synch="' . (int) $savedSynch . '">';

				if ($showSectionSeparator) {
					$this->resprints .= '<tr class="liste_titre" style="background: transparent !important;"><td colspan="4" style="background: transparent !important;">' . $langs->trans("DoliZSynchProductTitle") . '</td></tr>' . "\n";
				}

				// Forçar sincro ZS (LOJA X) -> Dolibarr
				$this->resprints .= '<tr class="dzs-row"><td class="titlefield">' . str_replace('store', $storeProducts, $langs->trans("DoliZSynchForceSynchProductToZoneSoft")) . '</td><td colspan="3">';
				$this->resprints .= '<a class="button" href="' . $_SERVER['PHP_SELF'] . '?id=' . $object->id . '&action=forceSync&redirect=default">' . $langs->trans("DoliZSynchForceSyncButton") . '</a>';
				$this->resprints .= '</td></tr>';

				// Loja para actualizacao do produto (ZS)
				$this->resprints .= '<tr class="dzs-row"><td>' . $langs->trans("DoliZSynchProductCompany") . '</td><td colspan="3">';
				if (!empty($savedProductCompaniesArray)) {
					foreach ($savedProductCompaniesArray as $productCompanyId) {
						$label = isset($TFieldProduct[$productCompanyId]) ? $TFieldProduct[$productCompanyId] : $langs->trans("Unknown");
						$this->resprints .= '<div class="refidno multicompany-entity-card-container">';
						$this->resprints .= '<span class="fa fa-globe"></span><span class="multiselect-selected-title-text">' . htmlspecialchars($label) . '</span>';
						$this->resprints .= "</div>\n";
					}
				} else {
					$this->resprints .= $langs->trans("NoneSelected");
				}
				$this->resprints .= "</td></tr>\n";

				// Disponibilidade (render with the same radio layout as edit mode)
				if (!empty($savedProductCompaniesArray)) {
					// Header row
					$this->resprints .= '<tr class="dzs-row"><td style="width: 200px;">' . $langs->trans("RetalhoForCompany") . '</td><td colspan="3">';
					$this->resprints .= '<div style="display: flex; align-items: center;">';
					foreach ($TFieldRetalho as $optionValue => $optionLabel) {
						$this->resprints .= '<div style="width: 120px; text-align: center; font-size: 12px;">' . htmlspecialchars($optionLabel) . '</div>';
					}
					$this->resprints .= '</div>';
					$this->resprints .= '</td></tr>';

					foreach ($savedProductCompaniesArray as $companyId) {
						if (!isset($TFieldProduct[$companyId])) {
							continue;
						}

						$companyLabel = $TFieldProduct[$companyId];
						$dropdownOptions = $TFieldRetalho;
						$savedDropdownValue = isset($savedRetalhoValues[$companyId]) ? (string) $savedRetalhoValues[$companyId] : '1';

						$this->resprints .= '<tr class="company-retalho-container dzs-row" data-company-id="' . htmlspecialchars($companyId) . '">';
						$this->resprints .= '<td style="text-align: right;">' . htmlspecialchars($companyLabel) . '</td><td colspan="3">';
						$this->resprints .= '<div style="display: flex; align-items: center;">';

						foreach ($dropdownOptions as $optionValue => $optionLabel) {
							$checked = ($savedDropdownValue === (string) $optionValue) ? ' checked' : '';
							$radioId = 'retalho_for_company_' . htmlspecialchars($companyId) . '_' . htmlspecialchars($optionValue);
							$this->resprints .= '<div style="width: 120px; text-align: center; display: flex; justify-content: center; align-items: center;">';
							$this->resprints .= '<input type="radio" name="retalho_for_company_' . htmlspecialchars($companyId) . '" id="' . $radioId . '" value="' . htmlspecialchars($optionValue) . '"' . $checked . ' style="transform: scale(1.3);" disabled>';
							$this->resprints .= '</div>';
						}

						$this->resprints .= '</div>';
						$this->resprints .= '</td></tr>';
					}
				}

				// CodigoPP (ZS)
				$this->resprints .= '<tr><td>' . $langs->trans("DoliZSynchCodigoPP") . '</td><td colspan="3">';
				$this->resprints .= htmlspecialchars(!empty($savedCodigoPP) ? $savedCodigoPP : $langs->trans("None"));
				$this->resprints .= '</td></tr>';

				// Codbarras (ZS)
				$this->resprints .= '<tr><td>' . $langs->trans("DoliZSynchCodbarras") . '</td><td colspan="3">';
				$this->resprints .= htmlspecialchars(!empty($savedCodbarras) ? $savedCodbarras : $langs->trans("None"));
				$this->resprints .= '</td></tr>';

				// Produtos codigos de barras (ZS)
				$barcodeListLabel = $langs->trans("DoliZSynchProdutosCodigosBarras");
				if ($barcodeListLabel === "DoliZSynchProdutosCodigosBarras") {
					$barcodeListLabel = "Barcode list";
				}
				$barcodeRowsView = $this->decodeJsonArrayField($savedProdutosCodigosBarras);
				if (!is_array($barcodeRowsView)) {
					$barcodeRowsView = array();
				}
				$barcodeRowsView = $this->normalizeProdutosCodigosBarrasRows($barcodeRowsView);
				if (!empty($barcodeRowsView)) {
					$this->resprints .= '<tr><td class="tdtop">' . dol_escape_htmltag($barcodeListLabel) . '</td><td colspan="3">';
					$this->resprints .= '<style>
							.dzs-barcode-view { border: 1px solid #dfe7f3; border-radius: 8px; padding: 10px; margin-top: 8px; background: #f9fbff; }
							.dzs-barcode-view table { width: 100%; border-collapse: collapse; }
							.dzs-barcode-view th, .dzs-barcode-view td { padding: 6px; border-bottom: 1px solid #e5e7eb; vertical-align: middle; }
							.dzs-barcode-view th { margin-top: 0; padding: 2px 6px 6px; font-size: 12px; font-weight: 600; color: #6b7280; text-transform: uppercase; letter-spacing: 0.02em; text-align: left; }
							.dzs-barcode-view input[type="text"], .dzs-barcode-view input[type="number"] { width: 100%; box-sizing: border-box; }
							.dzs-barcode-view input[disabled] { background: #f3f4f6; color: #6b7280; cursor: not-allowed; }
						</style>';
					$this->resprints .= '<div class="dzs-barcode-view">';
					$this->resprints .= '<table class="noborder">';
					$this->resprints .= '<thead><tr><th>Barcode</th><th>Description</th><th>Qty</th><th>Total</th></tr></thead><tbody>';
					foreach ($barcodeRowsView as $barcodeRow) {
						$codbarrasValue = isset($barcodeRow['codbarras']) ? (string) $barcodeRow['codbarras'] : '';
						$descricaoValue = isset($barcodeRow['descricao']) ? (string) $barcodeRow['descricao'] : '';
						$qtdValueRaw = isset($barcodeRow['qtd']) ? $barcodeRow['qtd'] : 0;
						$qtdValue = is_numeric($qtdValueRaw) ? (string) $qtdValueRaw : (string) $qtdValueRaw;
						$totalValueRaw = isset($barcodeRow['total']) ? $barcodeRow['total'] : 0;
						if (is_numeric($totalValueRaw)) {
							$totalValueFloat = (float) $totalValueRaw;
							$totalValue = ($totalValueFloat == 0.0) ? 'AUTO' : number_format($totalValueFloat, 2, '.', '');
						} else {
							$totalValue = (string) $totalValueRaw;
						}
						$this->resprints .= '<tr>';
						$this->resprints .= '<td><input type="text" class="flat" value="' . dol_escape_htmltag($codbarrasValue) . '" readonly="readonly" disabled="disabled"></td>';
						$this->resprints .= '<td><input type="text" class="flat" value="' . dol_escape_htmltag($descricaoValue) . '" readonly="readonly" disabled="disabled"></td>';
						$this->resprints .= '<td><input type="number" step="0.001" class="flat" value="' . dol_escape_htmltag($qtdValue) . '" readonly="readonly" disabled="disabled"></td>';
						$this->resprints .= '<td><input type="text" class="flat" value="' . dol_escape_htmltag($totalValue) . '" readonly="readonly" disabled="disabled"></td>';
						$this->resprints .= '</tr>';
					}
					$this->resprints .= '</tbody></table>';
					$this->resprints .= '</div>';
					$this->resprints .= '</td></tr>';
				}

				// Descricao curta (ZS)
				$this->resprints .= '<tr><td>' . $langs->trans("DoliZSynchDescricaoCurta") . '</td><td colspan="3">';
				$this->resprints .= htmlspecialchars(!empty($savedDescricaoCurta) ? $savedDescricaoCurta : $langs->trans("None"));
				$this->resprints .= '</td></tr>';

				// Referencia (ZS)
				$this->resprints .= '<tr><td>' . $langs->trans("DoliZSynchReferencia") . '</td><td colspan="3">';
				$this->resprints .= htmlspecialchars(!empty($savedReferencia) ? $savedReferencia : $langs->trans("None"));
				$this->resprints .= '</td></tr>';

				// Tempo de preparação (ZS)
				$this->resprints .= '<tr><td>' . $langs->trans("DoliZSynchTempoPrep") . '</td><td colspan="3">';
				$this->resprints .= htmlspecialchars($savedTempoPrep ?: '00:00:00');
				$this->resprints .= '</td></tr>';

				// Tipo de Balanca (ZS)
				$currentValue = $savedBalanca ?? '';
				$selectedLabel = isset($TFieldBalanca[$currentValue]) ? $TFieldBalanca[$currentValue] : 'Not Set';
				$this->resprints .= '<tr><td>' . $langs->trans("DoliZSynchProdutoBalanca") . '</td><td colspan="3">';
				$this->resprints .= htmlspecialchars($selectedLabel);
				$this->resprints .= '</td></tr>';

				// Tipo de Produto (ZS)
				$currentValue = $savedTipoSaft ?? '';
				$selectedLabel = isset($TFieldTipoProduto[$currentValue]) ? $TFieldTipoProduto[$currentValue] : 'Not Set';
				$this->resprints .= '<tr><td>' . $langs->trans("DoliZSynchProcuctTipoProduto") . '</td><td colspan="3">';
				$this->resprints .= htmlspecialchars($selectedLabel);
				$this->resprints .= '</td></tr>';

				// Isencao (ZS)
				$this->resprints .= '<tr><td>' . $langs->trans("DoliZSynchIsencao") . '</td><td colspan="3">';
				$currentValue = (string) $savedIsencao;
				if ($currentValue === '') {
					$currentValue = '0';
				}
				if (isset($TFieldIsencao[$currentValue])) {
					$this->resprints .= htmlspecialchars($TFieldIsencao[$currentValue]);
				} else {
					$this->resprints .= htmlspecialchars($savedIsencao !== '' ? $savedIsencao : $langs->trans("NotSelected"));
				}
				$this->resprints .= '</td></tr>';

				// IVA 2 (ZS)
				$this->resprints .= '<tr><td>' . $langs->trans("DoliZSynchIva2") . '</td><td colspan="3">';
				$iva2Label = $this->getZsTaxDisplayLabel($savedIva2, $zapiOptionsTaxes);
				$this->resprints .= htmlspecialchars($iva2Label !== '' ? $iva2Label : $langs->trans("NotSelected"));
				$this->resprints .= '</td></tr>';

				// IVA Compra (ZS)
				$this->resprints .= '<tr><td>' . $langs->trans("DoliZSynchIvaCompra") . '</td><td colspan="3">';
				$ivaCompraLabel = $this->getZsTaxDisplayLabel($savedIvaCompra, $zapiOptionsTaxes);
				$this->resprints .= htmlspecialchars($ivaCompraLabel !== '' ? $ivaCompraLabel : $langs->trans("NotSelected"));
				$this->resprints .= '</td></tr>';

				// IVA Revenda (ZS)
				$this->resprints .= '<tr><td>' . $langs->trans("DoliZSynchIvaRevenda") . '</td><td colspan="3">';
				$ivaRevendaLabel = $this->getZsTaxDisplayLabel($savedIvaRevenda, $zapiOptionsTaxes);
				$this->resprints .= htmlspecialchars($ivaRevendaLabel !== '' ? $ivaRevendaLabel : $langs->trans("NotSelected"));
				$this->resprints .= '</td></tr>';

				//Familia do produto (ZS)
				$this->resprints .= '<tr id="DOLIZSYNCH_ZS_FAMILY"><td>' . $langs->trans("DoliZSynchProductFamily") . '</td><td colspan="3">';
				$selectedLabel = '';
				foreach ($zapiOptionsFamilies as $option) {
					if ($savedFamilia == $option->family_id && $savedSubFam == $option->subfamily_id) {
						$selectedLabel = ($option->subfamily_name == '-' || $option->subfamily_name == '') ? $option->family_name : $option->family_name . ' >> ' . $option->subfamily_name;
						break;
					}
				}
				if (!empty($selectedLabel)) {
					$this->resprints .= htmlspecialchars($selectedLabel);
				} else {
					$this->resprints .= $langs->trans("NotSelected");
				}
				$this->resprints .= "</td></tr>\n";

				// Categoria do produto (ZS)
				$this->resprints .= '<tr id="DOLIZSYNCH_ZS_CATEGORY"><td>' . $langs->trans("DoliZSynchProductCategory") . '</td><td colspan="3">';
				$selectedLabel = '';
				foreach ($zapiOptionsCategories as $option) {
					if ($savedCategoria == $option->category_id && $savedSubCategoria == $option->subcategory_id) {
						$selectedLabel = ($option->subcategory_name == '-' || $option->subcategory_name == '') ? $option->category_name : $option->category_name . ' >> ' . $option->subcategory_name;
						break;
					}
				}
				if (!empty($selectedLabel)) {
					$this->resprints .= htmlspecialchars($selectedLabel);
				} else {
					$this->resprints .= $langs->trans("NotSelected");
				}
				$this->resprints .= "</td></tr>\n";

				// Grupo do produto (ZS)
				$this->resprints .= '<tr id="DOLIZSYNCH_ZS_GROUP"><td>' . $langs->trans("DoliZSynchProductGroup") . '</td><td colspan="3">';
				$selectedLabel = '';
				foreach ($zapiOptionsGroups as $option) {
					if ((string) $savedGrupo === (string) $option->group_id) {
						$selectedLabel = $option->group_name;
						break;
					}
				}
				if (!empty($selectedLabel)) {
					$this->resprints .= htmlspecialchars($selectedLabel);
				} else {
					$this->resprints .= $langs->trans("NotSelected");
				}
				$this->resprints .= "</td></tr>\n";

				// Unidade (ZS)
				$unitName = "";
				foreach ($zapiOptionsUnits as $option) {
					if ($savedUnidade == $option->unit_id) {
						$unitName = $option->unit_name;
						break; // Once the matching unit is found, no need to continue the loop
					}
				}
				$this->resprints .= '<tr><td>' . $langs->trans("DoliZSynchProductUnit") . '</td><td colspan="3">';
				$this->resprints .= htmlspecialchars($unitName ?: $langs->trans("NotSelected")); // Show unit name or "Not Selected"
				$this->resprints .= '</td></tr>';

				// Unidade de compra (ZS)
				$unitCompraName = "";
				foreach ($zapiOptionsUnits as $option) {
					if ($savedUncompra == $option->unit_id) {
						$unitCompraName = $option->unit_name;
						break;
					}
				}
				$this->resprints .= '<tr><td>' . $langs->trans("DoliZSynchProductUnitCompra") . '</td><td colspan="3">';
				$this->resprints .= htmlspecialchars($unitCompraName ?: $langs->trans("NotSelected"));
				$this->resprints .= '</td></tr>';

				// Unidade de inventario (ZS)
				$unitInventarioName = "";
				foreach ($zapiOptionsUnits as $option) {
					if ($savedUninventario == $option->unit_id) {
						$unitInventarioName = $option->unit_name;
						break;
					}
				}
				$this->resprints .= '<tr><td>' . $langs->trans("DoliZSynchProductUnitInventario") . '</td><td colspan="3">';
				$this->resprints .= htmlspecialchars($unitInventarioName ?: $langs->trans("NotSelected"));
				$this->resprints .= '</td></tr>';

				// Retenção na fonte (ZS)
				$checked = $savedRetencao ? 'checked' : '';
				$this->resprints .= '<tr id="DOLIZSYNCH_ZS_RETENCAO"><td>' . $langs->trans("DoliZSynchRetencao") . '</td><td colspan="3">';
				$this->resprints .= '<input type="checkbox" name="DOLIZSYNCH_ZS_RETENCAO" ' . $checked . ' value="1" disabled>';
				$this->resprints .= '</td></tr>';

				// Prepagamento (ZS)
				$checked = $savedPrepagamento ? 'checked' : '';
				$this->resprints .= '<tr><td>' . $langs->trans("DoliZSynchPrepagamento") . '</td><td colspan="3">';
				$this->resprints .= '<input type="checkbox" name="DOLIZSYNCH_PREPAGAMENTO" ' . $checked . ' value="1" disabled>';
				$this->resprints .= '</td></tr>';

				// Topo (ZS)
				$checked = $savedTopo ? 'checked' : '';
				$this->resprints .= '<tr><td>' . $langs->trans("DoliZSynchTopo") . '</td><td colspan="3">';
				$this->resprints .= '<input type="checkbox" name="DOLIZSYNCH_TOPO" ' . $checked . ' value="1" disabled>';
				$this->resprints .= '</td></tr>';

				// Revenda (ZS)
				$checked = $savedRevenda ? 'checked' : '';
				$this->resprints .= '<tr><td>' . $langs->trans("DoliZSynchRevenda") . '</td><td colspan="3">';
				$this->resprints .= '<input type="checkbox" name="DOLIZSYNCH_REVENDA" ' . $checked . ' value="1" disabled>';
				$this->resprints .= '</td></tr>';

				// Excluir dos descontos (ZS)
				$checked = $savedExcluirdescontos ? 'checked' : '';
				$this->resprints .= '<tr><td>' . $langs->trans("DoliZSynchExcluirDescontos") . '</td><td colspan="3">';
				$this->resprints .= '<input type="checkbox" name="DOLIZSYNCH_EXCLUIRDESCONTOS" ' . $checked . ' value="1" disabled>';
				$this->resprints .= '</td></tr>';

				// Additional ZSBMS sales option
				$checked = $savedConsumoMinimo ? 'checked' : '';
				$this->resprints .= '<tr><td>' . $langs->trans("DoliZSynchConsumoMinimo") . '</td><td colspan="3">';
				$this->resprints .= '<input type="checkbox" name="DOLIZSYNCH_CONSUMOMINIMO" ' . $checked . ' value="1" disabled>';
				$this->resprints .= '</td></tr>';

				// Additional ZSBMS sales option
				$checked = $savedEmentaDigital ? 'checked' : '';
				$this->resprints .= '<tr><td>' . $langs->trans("DoliZSynchEmentaDigital") . '</td><td colspan="3">';
				$this->resprints .= '<input type="checkbox" name="DOLIZSYNCH_EMENTADIGITAL" ' . $checked . ' value="1" disabled>';
				$this->resprints .= '</td></tr>';

				// Fuel (ZS)
				$checked = $savedFuel ? 'checked' : '';
				$this->resprints .= '<tr><td>' . $langs->trans("DoliZSynchFuel") . '</td><td colspan="3">';
				$this->resprints .= '<input type="checkbox" name="DOLIZSYNCH_FUEL" ' . $checked . ' value="1" disabled>';
				$this->resprints .= '</td></tr>';

				// Transferivel (ZS)
				$checked = $savedTransferivel ? 'checked' : '';
				$this->resprints .= '<tr><td>' . $langs->trans("DoliZSynchTransferivel") . '</td><td colspan="3">';
				$this->resprints .= '<input type="checkbox" name="DOLIZSYNCH_TRANSFERIVEL" ' . $checked . ' value="1" disabled>';
				$this->resprints .= '</td></tr>';

				// Cor de fundo do botão de produto (ZS)
				$this->resprints .= '<tr><td>' . $langs->trans("DoliZSynchProductBackgroundColorSelector") . '</td><td colspan="3">';
				$this->resprints .= '<input type="color" name="DOLIZSYNCH_ZS_BACKGROUND_COLOR" disabled value="' . dol_escape_htmltag($savedBackgroundColor) . '">';
				$this->resprints .= '</td></tr>';

				// Cor da letra do botão de produto (ZS)
				$this->resprints .= '<tr><td>' . $langs->trans("DoliZSynchProductFontColorSelector") . '</td><td colspan="3">';
				$this->resprints .= '<input type="color" name="DOLIZSYNCH_ZS_FONT_COLOR" disabled value="' . dol_escape_htmltag($savedFontColor) . '">';
				$this->resprints .= '</td></tr>';

				// Tipo de Produto (Menu, Venda, Mercadoria) (ZS)
				$currentValue = $savedComposto ?? '';
				$selectedLabel = isset($TFieldComposto[$currentValue]) ? $TFieldComposto[$currentValue] : 'Not Set';
				$this->resprints .= '<tr><td>' . $langs->trans("DoliZSynchProdutoComposto") . '</td><td colspan="3">';
				$this->resprints .= htmlspecialchars($selectedLabel);
				$this->resprints .= '</td></tr>';

				// Quebra de fim de dia (ZS) - Only show for composto value 5
				$checked = $savedQuebraFimDeDia ? 'checked' : '';
				$this->resprints .= '<tr id="DOLIZSYNCH_ZS_QUEBRA_FIM_DIA"><td>' . $langs->trans("DoliZSynchQuebraFimDia") . '</td><td colspan="3">';
				$this->resprints .= '<input type="checkbox" name="DOLIZSYNCH_ZS_QUEBRA_FIM_DIA" ' . $checked . ' value="1" disabled>';
				$this->resprints .= '</td></tr>';

				// Permitir vender sem stock (ZS) - Only show for composto value 5
				$checked = $savedVenderSemStock ? 'checked' : '';
				$this->resprints .= '<tr id="DOLIZSYNCH_ZS_VENDER_SEM_STOCK"><td>' . $langs->trans("DoliZSynchVenderSemStock") . '</td><td colspan="3">';
				$this->resprints .= '<input type="checkbox" name="DOLIZSYNCH_ZS_VENDER_SEM_STOCK" ' . $checked . ' value="1" disabled>';
				$this->resprints .= '</td></tr>';

				// Menu - pretty layout when data exists
				// Skip menu rendering if we're on zsproduct.php (from_dolizsynch_tab), as that page has its own display
				if (empty($parameters['from_dolizsynch_tab'])) {
					$menuJsonToRender = '';

					// Prefer menu already loaded for this page.
					if (!empty($savedNiveismenu) && $savedNiveismenu !== '[]' && $savedNiveismenu !== 'null') {
						$menuJsonToRender = $savedNiveismenu;
					} else {
						// Fallback: menus are shared across entities/stores, so try to fetch from any store.
						$menuData = $this->getZsMenuData((int) $object->id);
						if (!empty($menuData) && is_array($menuData)) {
							$tmpJson = json_encode($menuData);
							if ($tmpJson !== false) {
								$menuJsonToRender = $tmpJson;
							}
						}
					}

					if (!empty($menuJsonToRender) && $menuJsonToRender !== '[]' && $menuJsonToRender !== 'null') {
						$menuRow = $this->renderMenuCardsRow($menuJsonToRender, $store);
						if ($menuRow !== '') {
							$this->resprints .= $menuRow;
						}
					}
				}

				$this->resprints .= '</tbody>';
			}

			// ADD JAVASCRIPT FOR COMPOSTO VISIBILITY AND SYNC FUNCTIONALITY - WORKS IN ALL VIEWS
			$this->resprints .= "
				<script>
				document.addEventListener('DOMContentLoaded', function() {
				    // Add a small delay to ensure all content is loaded
					    setTimeout(function() {
					        // In edit mode, move the block after the last tbody to avoid invalid DOM placement
					        if (zsCheckbox) {
					            document.querySelectorAll('.dolizsynch-config-block').forEach(function(block) {
					                var table = block.closest('table');
					                if (table) {
					                    var tbodies = table.querySelectorAll('tbody');
					                    if (tbodies.length) {
					                        tbodies[tbodies.length - 1].after(block);
					                    }
					                }
					            });
					        }
					        // Toggle ZoneSoft block visibility; avoid moving block in view mode to prevent table redraw breaking borders
					        var zsBlocks = document.querySelectorAll('.dolizsynch-config-block');
					        var zsCheckbox = document.getElementById('options_zs_synch');

					        function toggleZsBlocks(useCheckbox) {
					            zsBlocks.forEach(function(block) {
					                var dataSynch = block.getAttribute('data-zs-synch');
					                var show = (useCheckbox && zsCheckbox) ? zsCheckbox.checked : (dataSynch !== '0');
					                // Keep data attribute aligned
					                block.setAttribute('data-zs-synch', show ? '1' : '0');
					                block.style.display = show ? '' : 'none';
					            });
					        }

					        // Initial state uses data attribute (from backend) to avoid timing issues with checkbox rendering
					        toggleZsBlocks(false);
					        if (zsCheckbox) {
					            zsCheckbox.addEventListener('change', function() {
					                toggleZsBlocks(true);
					            });
					        }
					    var compostoSelect = document.getElementById('DOLIZSYNCH_ZS_COMPOSTO');
					    var balancaSelect = document.getElementById('DOLIZSYNCH_ZS_BALANCA');
					    var familiaRow = document.getElementById('DOLIZSYNCH_ZS_FAMILY');
					    var categoriaRow = document.getElementById('DOLIZSYNCH_ZS_CATEGORY');
					    var quebraRow = document.getElementById('DOLIZSYNCH_ZS_QUEBRA_FIM_DIA');
				    var venderSemStockRow = document.getElementById('DOLIZSYNCH_ZS_VENDER_SEM_STOCK');
				    var venderRetencao = document.getElementById('DOLIZSYNCH_ZS_RETENCAO');
				    // Minimal state (no hide logic tied to zs_synch)
				    var storesMultiselect = document.getElementById('DOLIZSYNCH_ZS_PRODUCT_COMPANY');

				    function handleCompostoChange() {
				        // Get elements each time to ensure they exist
				        var familiaRowEl = document.getElementById('DOLIZSYNCH_ZS_FAMILY');
				        var categoriaRowEl = document.getElementById('DOLIZSYNCH_ZS_CATEGORY');
				        var quebraRowEl = document.getElementById('DOLIZSYNCH_ZS_QUEBRA_FIM_DIA');
				        var venderSemStockRowEl = document.getElementById('DOLIZSYNCH_ZS_VENDER_SEM_STOCK');

			        var value = '';

			        // Get value from select (edit mode) or from saved value (view mode)
			        if (compostoSelect) {
			            value = compostoSelect.value;
			        } else {
			            // In view mode, get the value from the saved composto
			            value = '" . $savedComposto . "';
			        }

				        if (value === '3') {
			            if (familiaRowEl) familiaRowEl.style.display = 'none';
			            if (categoriaRowEl) categoriaRowEl.style.display = '';
			            if (quebraRowEl) quebraRowEl.style.display = 'none';
			            if (venderSemStockRowEl) venderSemStockRowEl.style.display = 'none';
			        } else if (value === '2' || value === '4') {
			            // Value '2' is Menu - hide categoria
			            if (familiaRowEl) familiaRowEl.style.display = '';
				            if (categoriaRowEl) {
				                categoriaRowEl.style.display = 'none';
				            }
			            if (quebraRowEl) quebraRowEl.style.display = 'none';
			            if (venderSemStockRowEl) venderSemStockRowEl.style.display = 'none';
			        } else if (value === '5') {
			            if (familiaRowEl) familiaRowEl.style.display = '';
			            if (categoriaRowEl) categoriaRowEl.style.display = 'none';
			            if (quebraRowEl) quebraRowEl.style.display = '';
			            if (venderSemStockRowEl) venderSemStockRowEl.style.display = '';
			        } else {
			            if (familiaRowEl) familiaRowEl.style.display = '';
			            if (categoriaRowEl) categoriaRowEl.style.display = '';
			            if (quebraRowEl) quebraRowEl.style.display = 'none';
			            if (venderSemStockRowEl) venderSemStockRowEl.style.display = 'none';
			        }
			    }

				    // Listen for changes to the select element (edit mode)
				    if (compostoSelect) {
				        compostoSelect.addEventListener('change', handleCompostoChange);
				    }
				    if (typeof window.jQuery !== 'undefined') {
				        window.jQuery('#DOLIZSYNCH_ZS_COMPOSTO').on('select2:select select2:clear change', handleCompostoChange);
				    }

				    // Initialize the function on page load to set the correct display state
				    handleCompostoChange();

			    // Call again after a longer delay to ensure all elements are loaded
			    setTimeout(function() {
			        handleCompostoChange();
			    }, 1000);

			    // Select All/Deselect All functionality for stores
			    var selectAllBtn = document.getElementById('select-all-stores');
			    var deselectAllBtn = document.getElementById('deselect-all-stores');

			    if (selectAllBtn && storesMultiselect) {
			        selectAllBtn.addEventListener('click', function(e) {
			            e.preventDefault();
			            // Select all options in the multiselect
			            var options = storesMultiselect.querySelectorAll('option');
			            for (var i = 0; i < options.length; i++) {
			                options[i].selected = true;
			            }
			            // Trigger change event to update any dependencies
			            var event = new Event('change', { bubbles: true });
			            storesMultiselect.dispatchEvent(event);

			            // Update Select2 if it exists
			            if (typeof jQuery !== 'undefined' && jQuery(storesMultiselect).hasClass('select2-hidden-accessible')) {
			                jQuery(storesMultiselect).trigger('change');
			            }
			        });
			    }

			    if (deselectAllBtn && storesMultiselect) {
			        deselectAllBtn.addEventListener('click', function(e) {
			            e.preventDefault();
			            // Deselect all options in the multiselect
			            var options = storesMultiselect.querySelectorAll('option');
			            for (var i = 0; i < options.length; i++) {
			                options[i].selected = false;
			            }
			            // Trigger change event to update any dependencies
			            var event = new Event('change', { bubbles: true });
			            storesMultiselect.dispatchEvent(event);

			            // Update Select2 if it exists
			            if (typeof jQuery !== 'undefined' && jQuery(storesMultiselect).hasClass('select2-hidden-accessible')) {
			                jQuery(storesMultiselect).trigger('change');
			            }
			        });
			    }

			    }, 100); // 100ms delay
			});
			</script>";

			if ($action == 'forceSync' && $object->id) {
				// If requested, persist sync flag before forcing sync
				if (!empty($_GET['set_zs_synch'])) {
					if (empty($object->array_options) || !array_key_exists('options_zs_synch', $object->array_options)) {
						if (method_exists($object, 'fetch_optionals')) {
							$object->fetch_optionals($object->id);
						}
					}
					$object->array_options['options_zs_synch'] = 1;
					if (method_exists($object, 'insertExtraFields')) {
						$object->insertExtraFields();
						dol_syslog("KreaFlow: Forced zs_synch to 1 before forceSync for product {$object->id}", LOG_INFO);
					}
					// Also persist directly to tables to avoid defaults overriding it
					$db->query("UPDATE " . MAIN_DB_PREFIX . "product_extrafields SET zs_synch = 1 WHERE fk_object = " . ((int) $object->id));
					$db->query("UPDATE " . MAIN_DB_PREFIX . "dolizsynch_zsproduct SET restricted = 0 WHERE fk_product = " . ((int) $object->id));
				}

				// Get the redirect parameter
				$redirectTo = isset($_GET['redirect']) ? $_GET['redirect'] : 'default';

				// Instantiate the synchronization class
				$conf->global->bypass_product_modify_trigger = 1;
				$zsProductSync = new ZSProductSynch($db);

				// Get external product details
				$externalProductDetails = $zsProductSync->getZoneSoftProductById($object->ref);

				if ($externalProductDetails) {
					// Synchronize product
					$zsProductSync->insertProductToLocalTable($externalProductDetails);
					$zsProductSync->updateDolibarrProductFromZoneSoft($externalProductDetails->product);
					unset($conf->global->bypass_product_modify_trigger);

					// Build the redirect URL
					$redirectUrl = $_SERVER['PHP_SELF'] . '?id=' . $object->id;
					if ($redirectTo == 'edit') {
						$redirectUrl .= '&action=edit&sync=success';
					}

					// Use JavaScript to redirect
					echo '<script type="text/javascript">';
					echo 'window.location.href = "' . $redirectUrl . '";';
					echo '</script>';
				} else {
					// Handle failure - Product not found in ZSBMS
					unset($conf->global->bypass_product_modify_trigger);

					// If we are on the dedicated DoliZSynch page/tab, trigger the ZSBMS create modal on reload
					$isDolizsynchPageContext = (!empty($parameters['from_dolizsynch_page']) || GETPOST('dolizsynch_page', 'int') || GETPOST('dolizsynch_form', 'alpha'));
					if ($isDolizsynchPageContext) {
						$redirectUrl = $_SERVER['PHP_SELF'] . '?id=' . $object->id . '&show_zsbms_create=1';
						if ($redirectTo == 'edit') {
							$redirectUrl .= '&action=edit';
						}
						echo '<script type="text/javascript">';
						echo 'window.location.href = "' . $redirectUrl . '";';
						echo '</script>';
						exit;
					}

					// Check if we should prompt for product creation
					if (GETPOST('create_in_zsbms', 'alpha') === '') {
						// Show prompt to create product in ZSBMS
						echo '<div class="fiche">';
						echo '<div class="fichehalfright">';
						echo '<div class="fichehalfleft">';
						echo '<div class="titre">' . $langs->trans("ProductNotFoundInZSBMS") . '</div>';
						echo '<form method="post" action="' . $_SERVER['PHP_SELF'] . '?id=' . $object->id . '">';
						echo '<input type="hidden" name="token" value="' . newToken() . '">';
						echo '<input type="hidden" name="action" value="' . $action . '">';
						echo '<input type="hidden" name="redirectTo" value="' . $redirectTo . '">';
						echo '<p>' . $langs->trans("ProductNotFoundCreatePrompt", $object->ref) . '</p>';

						// Show which store will be used
						$storeDisplay = $conf->global->ZS_API_STORE;
						echo '<p><strong>' . $langs->trans("Store") . ':</strong> ' . $storeDisplay . '</p>';
						echo '<table class="border centpercent">';
						echo '<tr><td width="30%">' . $langs->trans("CreateProductInZSBMS") . '</td>';
						echo '<td><input type="radio" name="create_in_zsbms" value="yes" id="create_yes"> <label for="create_yes">' . $langs->trans("Yes") . '</label>';
						echo '&nbsp;&nbsp;<input type="radio" name="create_in_zsbms" value="no" id="create_no" checked> <label for="create_no">' . $langs->trans("No") . '</label></td></tr>';
						echo '<tr id="numbering_row" style="display:none;"><td>' . $langs->trans("NumberingMethod") . '</td>';
						echo '<td><input type="radio" name="numbering_method" value="automatic" id="auto_num" checked> <label for="auto_num">' . $langs->trans("AutomaticNumbering") . '</label>';
						echo '&nbsp;&nbsp;<input type="radio" name="numbering_method" value="manual" id="manual_num"> <label for="manual_num">' . $langs->trans("UseCurrentRef") . '</label></td></tr>';
						echo '</table>';
						echo '<br><div class="center">';
						echo '<input type="submit" class="button" value="' . $langs->trans("Confirm") . '">';
						echo '&nbsp;<input type="button" class="button" value="' . $langs->trans("Cancel") . '" onclick="history.back();">';
						echo '</div>';
						echo '</form>';
						echo '</div></div></div>';

						// JavaScript to show/hide numbering options
						echo '<script type="text/javascript">';
						echo 'document.addEventListener("DOMContentLoaded", function() {';
						echo '  var createYes = document.getElementById("create_yes");';
						echo '  var createNo = document.getElementById("create_no");';
						echo '  var numberingRow = document.getElementById("numbering_row");';
						echo '  function toggleNumbering() {';
						echo '    if (createYes.checked) {';
						echo '      numberingRow.style.display = "table-row";';
						echo '    } else {';
						echo '      numberingRow.style.display = "none";';
						echo '    }';
						echo '  }';
						echo '  createYes.addEventListener("change", toggleNumbering);';
						echo '  createNo.addEventListener("change", toggleNumbering);';
						echo '});';
						echo '</script>';
						return; // Stop execution to show the form
					} else {
						// Process the user's choice
						if (GETPOST('create_in_zsbms', 'alpha') === 'yes') {
							// User wants to create the product in ZSBMS
							$useAutomaticNumbering = (GETPOST('numbering_method', 'alpha') === 'automatic');

							// Create the product in ZSBMS
							$zsProductSync = new ZSProductSynch($db);

							// Determine the store to use - fallback to default if not configured
							$storeToUse = $conf->global->ZS_API_STORE;

							$success = $zsProductSync->createZoneSoftProductInteractive($object, $storeToUse, $useAutomaticNumbering);

							if ($success) {
								$redirectUrl = $_SERVER['PHP_SELF'] . '?id=' . $object->id;
								if ($redirectTo == 'edit') {
									$redirectUrl .= '&action=edit&sync=created';
								}
							} else {
								$redirectUrl = $_SERVER['PHP_SELF'] . '?id=' . $object->id;
								if ($redirectTo == 'edit') {
									$redirectUrl .= '&action=edit&sync=create_failed';
								}
							}
						} else {
							// User chose not to create the product
							$redirectUrl = $_SERVER['PHP_SELF'] . '?id=' . $object->id;
							if ($redirectTo == 'edit') {
								$redirectUrl .= '&action=edit&sync=skipped';
							}
						}

						// Use JavaScript to redirect
						echo '<script type="text/javascript">';
						echo 'window.location.href = "' . $redirectUrl . '";';
						echo '</script>';
					}
				}

				// Since we're in a function that may continue rendering the page,
				// we might consider adding an exit to prevent further processing.
				exit;
			}
		}

		if ($action == 'confirm_create_zs_product') {
			$this->resprints .= '<div class="warning">';
			$this->resprints .= $langs->trans("ProductNotFoundInZSBMS");
			$this->resprints .= '</div>';

			// Display Yes and No buttons
			$this->resprints .= '<form method="POST" action="' . $_SERVER['PHP_SELF'] . '?id=' . $object->id . '">';
			$this->resprints .= '<input type="hidden" name="action" value="create_zone_soft_product">';
			$this->resprints .= '<input type="submit" class="button" name="confirm" value="' . $langs->trans("Yes") . '">';
			$this->resprints .= '<input type="submit" class="button" name="cancel" value="' . $langs->trans("No") . '">';
			$this->resprints .= '</form>';
		}

		// Debug logging for prompt state
		dol_syslog("KreaFlow: formObjectOptions called - showPrompt: " . ($this->showPrompt ? 'true' : 'false') . ", promptProductId: " . ($this->promptProductId ?? 'null') . ", object->id: {$object->id}, URL show_zsbms_prompt: " . (isset($_GET['show_zsbms_prompt']) ? $_GET['show_zsbms_prompt'] : 'not set'), LOG_INFO);

		// Check if we should show the ZSBMS creation prompt
		if ($this->showPrompt && $this->promptProductId && $object->id == $this->promptProductId) {
			// Final safety check: verify product doesn't exist in ZSBMS via API before showing prompt
			$existsInZSBMS = false;

			// Get product reference - try multiple sources for reliability
			$productRef = $object->ref;
			$refSource = 'object';


			if (empty($productRef) && !empty($object->id)) {
				// Try session data first (from trigger)
				if (!empty($_SESSION['zsbms_create_product_ref']) && $_SESSION['zsbms_create_product_id'] == $object->id) {
					$productRef = $_SESSION['zsbms_create_product_ref'];
					$refSource = 'session data';
					dol_syslog("KreaFlow: FINAL CHECK - Retrieved ref from session: '{$productRef}'", LOG_INFO);
				} else {
					dol_syslog("KreaFlow: FINAL CHECK - Session data not available or mismatched, trying POST data", LOG_INFO);
					// Try POST data (from form submission)
					$productRef = GETPOST('ref', 'alphanohtml');
					if (!empty($productRef)) {
						$refSource = 'POST data';
						dol_syslog("KreaFlow: FINAL CHECK - Retrieved ref from POST: '{$productRef}'", LOG_INFO);
					} else {
						dol_syslog("KreaFlow: FINAL CHECK - POST data not available, trying database", LOG_INFO);
						// If ref is empty, try to get it directly from database
						$sql = "SELECT ref FROM " . MAIN_DB_PREFIX . "product WHERE rowid = " . (int)$object->id;
						$resql = $db->query($sql);
						if ($resql && $db->num_rows($resql) > 0) {
							$obj = $db->fetch_object($resql);
							$productRef = $obj->ref;
							$refSource = 'database query';
							dol_syslog("KreaFlow: FINAL CHECK - Retrieved ref from database: '{$productRef}'", LOG_INFO);
						} else {
							dol_syslog("KreaFlow: FINAL CHECK - Database query failed or returned no results", LOG_WARNING);
						}
					}
				}
			}

			dol_syslog("KreaFlow: FINAL CHECK - Product ID {$object->id} - Using ref: '{$productRef}' (from {$refSource})", LOG_INFO);

			if (!empty($productRef)) {
				require_once DOL_DOCUMENT_ROOT . '/custom/kreaflow/class/zsprodsynch.class.php';
				$zsProductSync = new ZSProductSynch($db);

				// Get current store
				$currentStore = !empty($conf->global->ZS_API_STORE) ? $conf->global->ZS_API_STORE : '1';

				try {
					dol_syslog("KreaFlow: FINAL CHECK - Verifying product {$productRef} in ZSBMS store {$currentStore} via API", LOG_INFO);

					// Check product in specific store.
					$zsbmsProduct = $zsProductSync->getZoneSoftProductByIdInStore($productRef, $currentStore);

					if ($zsbmsProduct && isset($zsbmsProduct->product) && !empty($zsbmsProduct->product->codigo)) {
						$existsInZSBMS = true;
						dol_syslog("KreaFlow: FINAL CHECK - Product {$productRef} found in ZSBMS store {$currentStore} with code: {$zsbmsProduct->product->codigo}, NOT showing prompt", LOG_WARNING);
					} else {
						if ($zsbmsProduct === false) {
							dol_syslog("KreaFlow: FINAL CHECK - API returned false - product {$productRef} definitely not found in ZSBMS store {$currentStore}", LOG_INFO);
						} elseif ($zsbmsProduct === null) {
							dol_syslog("KreaFlow: FINAL CHECK - API returned null - product {$productRef} not found in ZSBMS store {$currentStore}", LOG_INFO);
						} else {
							dol_syslog("KreaFlow: FINAL CHECK - API returned unexpected response for product {$productRef} in store {$currentStore}. Type: " . gettype($zsbmsProduct) . " | Summary: " . $this->summarizeValueForLog($zsbmsProduct), LOG_WARNING);
						}
					}
				} catch (Exception $e) {
					dol_syslog("KreaFlow: FINAL CHECK - API error: " . $this->summarizeExceptionForLog($e), LOG_ERR);
				}
			} else {
				dol_syslog("KreaFlow: FINAL CHECK - Product {$object->id} has empty reference, cannot check ZSBMS API", LOG_WARNING);
			}

			if ($existsInZSBMS) {
				$this->showPrompt = false; // Prevent showing prompt
				dol_syslog("KreaFlow: FINAL CHECK - Prompt prevented because product exists in ZSBMS", LOG_INFO);
				return;
			}

			dol_syslog("KreaFlow: FINAL CHECK - Product {$productRef} does not exist in ZSBMS, but prompt display is disabled", LOG_INFO);

			// Reset the flag
			$this->showPrompt = false;
		}

		// Auto-open ZSBMS creation modal on DoliZSynch page when requested (e.g., after failed forceSync).
		if ($isDolizsynchPageContext && GETPOST('show_zsbms_create', 'int') && !empty($object->id)) {
			$returnUrl = $_SERVER['PHP_SELF'] . '?id=' . (int) $object->id;
			$actionParam = GETPOST('action', 'aZ09');
			if ($actionParam === 'edit' || $action === 'edit') {
				$returnUrl .= '&action=edit';
			}
			$this->resprints .= $this->renderZsbmsCreateModalOnZsSynch((int) $object->id, true, $returnUrl);
		}
	}

	/**
	 * Add a column header for ZS Menu on the product composition card.
	 *
	 * @param array        $parameters Hook parameters (unused here)
	 * @param CommonObject $object     Current object
	 * @param string       $action     Current action
	 * @param HookManager  $hookmanager Hook manager
	 * @return int
	 */
	public function printFieldListTitle($parameters, &$object, &$action, $hookmanager)
	{
		// Disabled
		return 0;
	}

	/**
	 * Add ZS Menu content per line on the product composition card.
	 *
	 * @param array        $parameters Hook parameters (unused here)
	 * @param CommonObject $object     Current line object (product)
	 * @param string       $action     Current action
	 * @param HookManager  $hookmanager Hook manager
	 * @return int
	 */
	public function printFieldListValue($parameters, &$object, &$action, $hookmanager)
	{
		// Disabled
		return 0;
	}

	/**
	 * Check whether the current hook belongs to the native BOM card.
	 *
	 * @param object $object Hook object
	 * @return bool
	 */
	private function isBomCardContext($object)
	{
		$script = isset($_SERVER['PHP_SELF']) ? basename((string) $_SERVER['PHP_SELF']) : '';
		return $script === 'bom_card.php' && is_object($object) && !empty($object->id);
	}

	/**
	 * Render exact-stock guidance for the dismantling BOM selected by an active rule.
	 *
	 * @param int $bomId BOM ID
	 * @param int $entity Active entity
	 * @return string Hook HTML
	 */
	private function renderPropertyStockBomWarning($bomId, $entity)
	{
		global $langs;

		$bomId = (int) $bomId;
		$entity = (int) $entity;
		if ($bomId <= 0 || $entity <= 0) {
			return '';
		}

		$sql = 'SELECT stock_rule.property_name, bom.qty AS bom_qty, bom_line.qty,';
		$sql .= ' product.ref, product.label, input_product.cost_price, input_product.pmp';
		$sql .= ' FROM ' . MAIN_DB_PREFIX . 'dolizsynch_property_stock_rule stock_rule';
		$sql .= ' INNER JOIN ' . MAIN_DB_PREFIX . 'bom_bom bom ON bom.rowid=stock_rule.fk_bom AND bom.bomtype=1';
		$sql .= ' INNER JOIN ' . MAIN_DB_PREFIX . 'product input_product ON input_product.rowid=bom.fk_product';
		$sql .= ' INNER JOIN ' . MAIN_DB_PREFIX . 'bom_bomline bom_line';
		$sql .= ' ON bom_line.rowid=stock_rule.fk_bom_line AND bom_line.fk_bom=bom.rowid';
		$sql .= ' INNER JOIN ' . MAIN_DB_PREFIX . 'product product ON product.rowid=bom_line.fk_product';
		$sql .= ' WHERE stock_rule.active=1';
		$sql .= ' AND (stock_rule.entity IN (0, ' . $entity . ') OR bom.entity=0)';
		$sql .= ' AND stock_rule.fk_bom=' . $bomId . ' AND bom.entity IN (0, ' . getEntity('bom') . ')';
		$sql .= ' AND input_product.entity IN (' . getEntity('product') . ')';
		$sql .= ' AND product.entity IN (' . getEntity('product') . ')';
		$sql .= ' ORDER BY CASE WHEN stock_rule.entity=' . $entity . ' THEN 0 WHEN stock_rule.entity=0 THEN 1 ELSE 2 END,';
		$sql .= ' stock_rule.rowid ASC';
		$sql .= ' ' . $this->db->plimit(1);
		$resql = $this->db->query($sql);
		$row = $resql ? $this->db->fetch_object($resql) : null;
		$isConfiguredRule = (bool) $row;
		$warningKey = 'DoliZSynchPropertyStockBomWarning';
		if (!$row) {
			$sql = 'SELECT bom.qty AS bom_qty, bom_line.qty, product.ref, product.label,';
			$sql .= ' input_product.cost_price, input_product.pmp';
			$sql .= ' FROM ' . MAIN_DB_PREFIX . 'bom_bom bom';
			$sql .= ' INNER JOIN ' . MAIN_DB_PREFIX . 'product input_product ON input_product.rowid=bom.fk_product';
			$sql .= ' INNER JOIN ' . MAIN_DB_PREFIX . 'bom_bomline bom_line ON bom_line.fk_bom=bom.rowid';
			$sql .= ' INNER JOIN ' . MAIN_DB_PREFIX . 'product product ON product.rowid=bom_line.fk_product';
			$sql .= ' WHERE bom.rowid=' . $bomId . ' AND bom.bomtype=1';
			$sql .= ' AND bom.entity IN (0, ' . getEntity('bom') . ')';
			$sql .= ' AND input_product.entity IN (' . getEntity('product') . ')';
			$sql .= ' AND product.entity IN (' . getEntity('product') . ')';
			$sql .= ' AND bom_line.qty>0';
			$sql .= ' AND (SELECT COUNT(*) FROM ' . MAIN_DB_PREFIX . 'bom_bomline count_line';
			$sql .= ' WHERE count_line.fk_bom=bom.rowid)=1';
			$sql .= ' ' . $this->db->plimit(1);
			$resql = $this->db->query($sql);
			$row = $resql ? $this->db->fetch_object($resql) : null;
			if (!$row) {
				return '';
			}
			$row->property_name = $langs->trans('DoliZSynchPropertyStockConfiguredPackageProperty');
			$warningKey = 'DoliZSynchPropertyStockBomCandidateWarning';
		}

		$propertyName = trim((string) $row->property_name);
		for ($pass = 0; $pass < 3; $pass++) {
			$decodedPropertyName = html_entity_decode($propertyName, ENT_QUOTES | ENT_HTML5, 'UTF-8');
			if ($decodedPropertyName === $propertyName) {
				break;
			}
			$propertyName = $decodedPropertyName;
		}
		$outputName = trim((string) $row->ref . ' - ' . (string) $row->label, ' -');
		$outputQty = price((float) $row->qty, 0, $langs, 0, 0, -1);
		$nominalEstimate = '';
		$costPrice = trim((string) $row->cost_price) !== '' ? (string) $row->cost_price : '0';
		$averageCost = trim((string) $row->pmp) !== '' ? (string) $row->pmp : '0';
		$inputCost = ZSDecimal::compare($costPrice, '0') > 0
			? ZSDecimal::normalize($costPrice)
			: ZSDecimal::normalize($averageCost);
		// This is a display-only recipe estimate. Final valuation always comes from immutable supplier-invoice evidence.
		if (
			$isConfiguredRule
			&& ZSDecimal::compare((string) $row->bom_qty, '0') > 0
			&& ZSDecimal::compare((string) $row->qty, '0') > 0
			&& ZSDecimal::compare($inputCost, '0') > 0
		) {
			$nominalPackageCost = ZSDecimal::multiply((string) $row->bom_qty, $inputCost);
			$nominalUnitCost = ZSDecimal::divide($nominalPackageCost, (string) $row->qty, 8);
			$nominalUnitResult = $langs->trans(
				'DoliZSynchPropertyStockBomNominalUnitResult',
				$outputQty,
				price((float) $nominalUnitCost, 0, $langs, 0, 0, 4)
			);
			$nominalEstimate = $langs->trans(
				'DoliZSynchPropertyStockBomNominalEstimate',
				price((float) $row->bom_qty, 0, $langs, 0, 0, -1),
				price((float) $inputCost, 0, $langs, 0, 0, 4),
				price((float) $nominalPackageCost, 0, $langs, 0, 0, 4),
				$nominalUnitResult
			);
		}
		$html = '<tr class="dolizsynch-property-stock-bom-warning"><td colspan="2">';
		$html .= '<div class="warning">';
		$html .= '<strong>' . img_picto('', 'warning') . ' ' . dol_escape_htmltag($langs->trans('DoliZSynchPropertyStockBomWarningTitle')) . '</strong><br>';
		$html .= $langs->trans(
			$warningKey,
			$propertyName,
			$outputQty,
			$outputName
		);
		$html .= '</div>';
		if ($nominalEstimate !== '') {
			$html .= '<div class="info">' . $nominalEstimate . '</div>';
		}
		if ($isConfiguredRule) {
			$quantityPropertyName = function_exists('mb_strtolower')
				? mb_strtolower($propertyName, 'UTF-8')
				: strtolower($propertyName);
			$packageQuantityLabel = json_encode(
				$langs->trans('DoliZSynchPropertyStockBomPackageQuantity', $quantityPropertyName),
				JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
			);
			$packageQuantityHelpText = $langs->trans(
				'DoliZSynchPropertyStockBomPackageQuantityHelp',
				$propertyName,
				$outputQty,
				$outputName
			);
			for ($pass = 0; $pass < 3; $pass++) {
				$decodedHelpText = html_entity_decode($packageQuantityHelpText, ENT_QUOTES | ENT_HTML5, 'UTF-8');
				if ($decodedHelpText === $packageQuantityHelpText) {
					break;
				}
				$packageQuantityHelpText = $decodedHelpText;
			}
			$packageQuantityHelp = json_encode(
				$packageQuantityHelpText,
				JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
			);
			$weightQuantityLabel = json_encode(
				$langs->trans('DoliZSynchPropertyStockBomWeightQuantity'),
				JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
			);
			$weightQuantityHelp = json_encode(
				$langs->trans('DoliZSynchPropertyStockBomWeightQuantityHelp'),
				JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
			);
			// A configured exact-stock BOM is defined per one source package; the invoice property supplies the real count.
			$packageBasisValue = json_encode(
				price(1, 0, $langs, 0, 0, 0),
				JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
			);
			$html .= '<script>';
			$html .= '(function(){var updateQuantityLabel=function(){';
			$html .= "var quantityLabel=document.querySelector('tr.field_qty td.titlefieldmiddle.fieldname_qty');";
			$html .= "var quantityValue=document.querySelector('tr.field_qty td.valuefield.fieldname_qty');";
			$html .= 'if(!quantityLabel||!quantityValue){return;}';
			$html .= "if(document.querySelector('tr.field_dolizsynch_package_basis')){return;}";
			$html .= "quantityLabel.textContent='';";
			$html .= "var quantityText=document.createElement('span');";
			$html .= "quantityText.className='valignmiddle';";
			$html .= "quantityText.style.padding='0';";
			$html .= "quantityText.style.paddingRight='2px';";
			$html .= 'quantityText.textContent=' . $weightQuantityLabel . ';';
			$html .= 'quantityLabel.appendChild(quantityText);';
			$html .= "quantityLabel.classList.add('dolizsynch-property-stock-weight-quantity');";
			$html .= "quantityValue.classList.add('dolizsynch-property-stock-weight-value');";
			$html .= "var weightHelp=document.createElement('span');";
			$html .= "weightHelp.className='classfortooltip valignmiddle';";
			$html .= "weightHelp.style.padding='0';";
			$html .= "weightHelp.style.paddingRight='2px';";
			$html .= 'weightHelp.setAttribute(\'title\',' . $weightQuantityHelp . ');';
			$html .= "var weightHelpIcon=document.createElement('span');";
			$html .= "weightHelpIcon.className='fas fa-info-circle em088 opacityhigh';";
			$html .= "weightHelpIcon.style.verticalAlign='middle';";
			$html .= "weightHelpIcon.style.cursor='help';";
			$html .= "weightHelpIcon.setAttribute('aria-hidden','true');";
			$html .= 'weightHelp.appendChild(weightHelpIcon);quantityLabel.appendChild(weightHelp);';
			$html .= "var packageRow=document.createElement('tr');";
			$html .= "packageRow.className='field_dolizsynch_package_basis dolizsynch-property-stock-package-basis';";
			$html .= "var packageTitle=document.createElement('td');";
			$html .= "packageTitle.className='titlefieldmiddle fieldname_dolizsynch_package_basis dolizsynch-property-stock-package-quantity';";
			$html .= "var packageText=document.createElement('span');";
			$html .= "packageText.className='valignmiddle';";
			$html .= "packageText.style.padding='0';";
			$html .= "packageText.style.paddingRight='2px';";
			$html .= 'packageText.textContent=' . $packageQuantityLabel . ';';
			$html .= 'packageTitle.appendChild(packageText);';
			$html .= "var packageValue=document.createElement('td');";
			$html .= "packageValue.className='valuefield fieldname_dolizsynch_package_basis dolizsynch-property-stock-package-value';";
			$html .= "var warningIcon=document.createElement('span');";
			$html .= "warningIcon.className='fas fa-exclamation-triangle paddingright';";
			$html .= "warningIcon.style.color='#a69944';";
			$html .= "warningIcon.setAttribute('aria-hidden','true');";
			$html .= 'packageValue.appendChild(warningIcon);';
			$html .= 'packageValue.appendChild(document.createTextNode(' . $packageBasisValue . '));';
			$html .= "var help=document.createElement('span');";
			$html .= "help.className='classfortooltip valignmiddle';";
			$html .= "help.style.padding='0';";
			$html .= "help.style.paddingRight='2px';";
			$html .= 'help.setAttribute(\'title\',' . $packageQuantityHelp . ');';
			$html .= "var helpIcon=document.createElement('span');";
			$html .= "helpIcon.className='fas fa-info-circle em088 opacityhigh';";
			$html .= "helpIcon.style.verticalAlign='middle';";
			$html .= "helpIcon.style.cursor='help';";
			$html .= "helpIcon.setAttribute('aria-hidden','true');";
			$html .= 'help.appendChild(helpIcon);packageTitle.appendChild(help);';
			$html .= 'packageRow.appendChild(packageTitle);packageRow.appendChild(packageValue);';
			$html .= 'quantityLabel.parentNode.parentNode.insertBefore(packageRow,quantityLabel.parentNode);';
			$html .= '};';
			$html .= "if(document.readyState==='loading'){document.addEventListener('DOMContentLoaded',updateQuantityLabel);}";
			$html .= 'else{updateQuantityLabel();}})();';
			$html .= '</script>';
		}
		$html .= '</td></tr>';

		return $html;
	}

	/**
	 * Render the ZS menu as the styled card layout used on the product view tab.
	 *
	 * @param string $menuJson JSON string saved in niveismenu column
	 * @param int    $store    Store id (needed for family lookup when fixo = 3)
	 * @return string          HTML table row (or empty string if nothing to render)
	 */
	private function renderMenuCardsRow($menuJson, $store)
	{
		global $langs;

		if (empty($menuJson) || $menuJson === '[]' || $menuJson === 'null') {
			return '';
		}

		$menuData = json_decode($menuJson, true);
		if (json_last_error() !== JSON_ERROR_NONE || !is_array($menuData)) {
			// Defensive: sometimes JSON is HTML-escaped (&quot;) or double-encoded.
			$repaired = html_entity_decode((string) $menuJson, ENT_QUOTES | ENT_HTML5);
			$menuData = json_decode($repaired, true);
			if (json_last_error() !== JSON_ERROR_NONE || !is_array($menuData)) {
				return '';
			}
		}

		static $stylePrintedMenu = false;

		$html = '';
		if (!$stylePrintedMenu) {
			$html .= '<style>
				.dzs-menu { display:flex; flex-direction:column; gap:10px; }
				.dzs-card { border:1px solid #e5e9f0; border-radius:10px; background:#f9fbfd; padding:10px 12px; box-shadow:0 2px 6px rgba(20,40,60,0.08); }
				.dzs-head { display:flex; align-items:center; gap:10px; margin-bottom:8px; }
				.dzs-level { display:inline-flex; align-items:center; justify-content:center; padding:6px 10px; border-radius:20px; background:var(--colorbackhmenu1, #2c7be5); color:#fff; font-weight:800; letter-spacing:0.2px; }
				.dzs-label { font-weight:700; color:#0f172a; }
				.dzs-pill { padding:3px 8px; border-radius:999px; background:#ffeaea; color:#c0392b; font-weight:700; font-size:11px; text-transform:uppercase; letter-spacing:0.2px; }
				.dzs-list { display:grid; grid-template-columns:90px 1fr auto; gap:6px 10px; align-items:center; }
				.dzs-menu .dzs-row { display:contents; }
				.dzs-code { font-weight:800; color:#1f2937; }
				.dzs-desc { color:#111827; font-size:13px; }
				.dzs-price { color:#111827; font-weight:700; }
				.dzs-card a:link,
				.dzs-card a:visited,
				.dzs-card a:hover,
				.dzs-card a:active,
				.dzs-card .classlink { color:#000 !important; text-decoration:none !important; }
			</style>';
			$stylePrintedMenu = true;
		}

		$menuCards = '';
		foreach ($menuData as $menu) {
			$nivel = isset($menu['nivel']) ? (int) $menu['nivel'] : '';
			$descricao = isset($menu['descricao']) ? dol_escape_htmltag($menu['descricao']) : '';
			$isReq = !empty($menu['obrigatorio']);

			$rowsHtml = '';
			if (!empty($menu['niveismenuext']) && is_array($menu['niveismenuext'])) {
				foreach ($menu['niveismenuext'] as $produto) {
					$code = isset($produto['codigo']) ? dol_escape_htmltag((string) $produto['codigo']) : '';
					$fixo = isset($produto['fixo']) ? (int) $produto['fixo'] : 1;
					$descProd = '';
					$linkedProductId = 0;

					if (isset($produto['descricao']) && $produto['descricao'] !== '') {
						$descProd = dol_escape_htmltag($produto['descricao']);
						if (!empty($produto['fk_product'])) {
							$linkedProductId = (int) $produto['fk_product'];
						}
					} elseif ($fixo === 2) {
						// Family (prefer base row subfamily_id=0)
						$sql_family = "SELECT family_name";
						$sql_family .= " FROM " . MAIN_DB_PREFIX . "dolizsynch_zsfamily";
						$sql_family .= " WHERE family_id = " . (int) $code;
						$sql_family .= " AND subfamily_id = 0";
						if (!empty($store)) {
							$sql_family .= " ORDER BY (loja_zs = " . (int) $store . ") DESC, rowid DESC";
						} else {
							$sql_family .= " ORDER BY rowid DESC";
						}
						$sql_family .= " " . $this->db->plimit(1);
						$resql_family = $this->db->query($sql_family);
						if ($resql_family && ($obj_family = $this->db->fetch_object($resql_family))) {
							$descProd = dol_escape_htmltag((string) $obj_family->family_name);
						}
					} elseif ($fixo === 3) {
						// Subfamily (legacy): prefer current store, but fallback to any store.
						$sql_family = "SELECT family_name, subfamily_name FROM " . MAIN_DB_PREFIX . "dolizsynch_zsfamily";
						$sql_family .= " WHERE subfamily_id = " . (int) $code;
						if (!empty($store)) {
							$sql_family_store = $sql_family . " AND loja_zs = " . (int) $store . " ORDER BY rowid DESC " . $this->db->plimit(1);
							$resql_family = $this->db->query($sql_family_store);
							if (!$resql_family || !$this->db->num_rows($resql_family)) {
								$resql_family = $this->db->query($sql_family . " ORDER BY rowid DESC " . $this->db->plimit(1));
							}
						} else {
							$resql_family = $this->db->query($sql_family . " ORDER BY rowid DESC " . $this->db->plimit(1));
						}

						if ($resql_family && ($obj_family = $this->db->fetch_object($resql_family))) {
							$descProd = (string) $obj_family->family_name;
							if (!empty($obj_family->subfamily_name) && $obj_family->subfamily_name != '-') {
								$descProd .= ' / ' . $obj_family->subfamily_name;
							}
							$descProd = dol_escape_htmltag($descProd);
						}
					} else {
						// Product: prefer current store, but fallback to any store.
						$sql_product = "SELECT fk_product, descricao FROM " . MAIN_DB_PREFIX . "dolizsynch_zsproduct";
						$sql_product .= " WHERE codigo = " . (int) $code;
						if (!empty($store)) {
							$sql_product .= " ORDER BY (loja_zs = " . (int) $store . ") DESC, rowid DESC";
						} else {
							$sql_product .= " ORDER BY rowid DESC";
						}
						$sql_product .= " " . $this->db->plimit(1);
						$resql_product = $this->db->query($sql_product);
						if ($resql_product && ($obj_product = $this->db->fetch_object($resql_product))) {
							$descProd = dol_escape_htmltag($obj_product->descricao);
							$linkedProductId = (int) $obj_product->fk_product;
						}
					}

					$preco = isset($produto['preco']) ? price2num($produto['preco'], 'MU') : 0;
					$priceStr = ($preco > 0) ? '(' . price($preco, '', '', 0, 2, 2) . ')' : '';
					$typeLabel = ($fixo === 2 || $fixo === 3) ? ' (F)' : ' (P)';
					$codeHtml = '#' . $code . $typeLabel;
					if ($linkedProductId > 0) {
						$codeHtml = '<a href="' . DOL_URL_ROOT . '/product/card.php?id=' . $linkedProductId . '" target="_blank">#' . $code . '</a>' . $typeLabel;
					}

					$rowsHtml .= '<div class="dzs-row"><span class="dzs-code">' . $codeHtml . '</span><span class="dzs-desc">' . $descProd . '</span><span class="dzs-price">' . $priceStr . '</span></div>';
				}
			}

			$menuCards .= '<div class="dzs-card">';
			$menuCards .= '<div class="dzs-head"><span class="dzs-level">#' . dol_escape_htmltag($nivel) . '</span><span class="dzs-label">' . $descricao . '</span>' . ($isReq ? '<span class="dzs-pill">Obrigatório</span>' : '') . '</div>';
			if ($rowsHtml !== '') {
				$menuCards .= '<div class="dzs-list">' . $rowsHtml . '</div>';
			}
			$menuCards .= '</div>';
		}

		if ($menuCards === '') {
			return '';
		}

		$titleMenu = str_replace('(ZS)', '', $langs->trans("DoliZSynchProductMenu"));
		$titleMenu = trim($titleMenu);

		$html .= '<tr><td class="tdtop">' . dol_escape_htmltag($titleMenu) . '</td><td colspan="3"><div class="dzs-menu">' . $menuCards . '</div></td></tr>';

		return $html;
	}

	/**
	 * Fetch and cache menu data for a given product.
	 *
	 * @param int $productId Product ID
	 * @return array
	 */
	private function getZsMenuData($productId)
	{
		static $menuCache = array();

		if (isset($menuCache[$productId])) {
			return $menuCache[$productId];
		}

		$menuCache[$productId] = array();

		// Menus are shared across entities/stores, so we try in this order:
		// 1) fk_product (any store) newest non-empty menu
		// 2) codigo (any store) newest non-empty menu

		$foundMenu = array();
		$menuJsonCandidate = '';

		// 1) Try by fk_product first (in case codigo mapping is missing)
		$sql = "SELECT niveismenu FROM " . MAIN_DB_PREFIX . "dolizsynch_zsproduct";
		$sql .= " WHERE fk_product = " . ((int) $productId);
		$sql .= " AND niveismenu IS NOT NULL";
		$sql .= " ORDER BY rowid DESC";
		$sql .= " " . $this->db->plimit(50);
		$resql = $this->db->query($sql);
		if ($resql) {
			while ($obj = $this->db->fetch_object($resql)) {
				if (!$obj || empty($obj->niveismenu)) {
					continue;
				}
				$menuJsonCandidate = trim((string) $obj->niveismenu);
				if ($menuJsonCandidate === '' || strtolower($menuJsonCandidate) === 'null') {
					continue;
				}

				$decoded = json_decode($menuJsonCandidate, true);
				if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
					$repaired = html_entity_decode($menuJsonCandidate, ENT_QUOTES | ENT_HTML5);
					$decoded = json_decode($repaired, true);
				}

				if (json_last_error() === JSON_ERROR_NONE && is_array($decoded) && count($decoded) > 0) {
					$foundMenu = $decoded;
					break;
				}
			}
		}

		// 2) Fallback by codigo (ZS code) - helps when products are duplicated across entities with different fk_product
		if (empty($foundMenu)) {
			$codigo = '';

			$sql = "SELECT codigo FROM " . MAIN_DB_PREFIX . "dolizsynch_zsproduct";
			$sql .= " WHERE fk_product = " . ((int) $productId);
			$sql .= " ORDER BY rowid DESC " . $this->db->plimit(1);
			$resql = $this->db->query($sql);
			if ($resql && ($obj = $this->db->fetch_object($resql)) && !empty($obj->codigo)) {
				$codigo = (string) $obj->codigo;
			} else {
				$sql = "SELECT ref FROM " . MAIN_DB_PREFIX . "product WHERE rowid = " . ((int) $productId) . " " . $this->db->plimit(1);
				$resql = $this->db->query($sql);
				if ($resql && ($obj = $this->db->fetch_object($resql)) && !empty($obj->ref) && preg_match('/^[0-9]+$/', (string) $obj->ref)) {
					$codigo = (string) $obj->ref;
				}
			}

			if ($codigo !== '') {
				$sql = "SELECT niveismenu FROM " . MAIN_DB_PREFIX . "dolizsynch_zsproduct";
				$sql .= " WHERE codigo = '" . $this->db->escape($codigo) . "'";
				$sql .= " AND niveismenu IS NOT NULL";
				$sql .= " ORDER BY rowid DESC";
				$sql .= " " . $this->db->plimit(50);
				$resql = $this->db->query($sql);
				if ($resql) {
					while ($obj = $this->db->fetch_object($resql)) {
						if (!$obj || empty($obj->niveismenu)) {
							continue;
						}
						$menuJsonCandidate = trim((string) $obj->niveismenu);
						if ($menuJsonCandidate === '' || strtolower($menuJsonCandidate) === 'null') {
							continue;
						}

						$decoded = json_decode($menuJsonCandidate, true);
						if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
							$repaired = html_entity_decode($menuJsonCandidate, ENT_QUOTES | ENT_HTML5);
							$decoded = json_decode($repaired, true);
						}

						if (json_last_error() === JSON_ERROR_NONE && is_array($decoded) && count($decoded) > 0) {
							$foundMenu = $decoded;
							break;
						}
					}
				}
			}
		}

		if (!empty($foundMenu)) {
			$menuCache[$productId] = $foundMenu;
		}

		return $menuCache[$productId];
	}

	/**
	 * Resolve global ZS synch value across entities by product ref.
	 *
	 * @param string $productRef
	 * @return int|null 1/0 when found, null when ref is empty or not found
	 */
	private function getGlobalZsSynchByRef($productRef)
	{
		$ref = trim((string) $productRef);
		if ($ref === '') {
			return null;
		}

		$sql = "SELECT MAX(COALESCE(pe.zs_synch, 0)) as zs_synch";
		$sql .= " FROM " . MAIN_DB_PREFIX . "product p";
		$sql .= " LEFT JOIN " . MAIN_DB_PREFIX . "product_extrafields pe ON pe.fk_object = p.rowid";
		$sql .= " WHERE p.ref = '" . $this->db->escape($ref) . "'";

		$resql = $this->db->query($sql);
		if ($resql && ($obj = $this->db->fetch_object($resql))) {
			if ($obj->zs_synch === null) {
				return null;
			}
			return ((string) $obj->zs_synch === '1' || (int) $obj->zs_synch === 1) ? 1 : 0;
		}

		return null;
	}

	/**
	 * Render a compact badge for one menu level.
	 *
	 * @param array $menu  Menu entry data
	 * @param int   $index Position in array (fallback for level)
	 * @return string
	 */
	private function renderZsMenuChip($menu, $index)
	{
		global $langs;

		$level = isset($menu['nivel']) ? (int) $menu['nivel'] : ($index + 1);
		$labelRaw = isset($menu['descricao']) ? $menu['descricao'] : $langs->trans("DoliZSynchProductMenu");
		$label = dol_escape_htmltag(dol_trunc($labelRaw, 38));

		$isRequired = !empty($menu['obrigatorio']);
		$pillClass = $isRequired ? 'zs-menu-pill required' : 'zs-menu-pill';
		$pillText = $langs->trans("Required") . ': ' . ($isRequired ? $langs->trans("Yes") : $langs->trans("No"));

		$levelText = '#' . $level;

		return '<div class="zs-menu-chip">'
			. '<span class="zs-menu-level">' . $levelText . '</span>'
			. '<span class="zs-menu-label">' . $label . '</span>'
			. '<span class="' . $pillClass . '">' . dol_escape_htmltag($pillText) . '</span>'
			. '</div>';
	}

	/**
	 * Show the ZSBMS creation prompt for a newly created product
	 *
	 * @param object $object The product object
	 */
	private function showZsbmsCreationPrompt($object)
	{
		global $conf, $langs;

		// Start capturing output
		ob_start();

		echo '<div class="fiche">';
		echo '<div class="fichehalfright">';
		echo '<div class="fichehalfleft">';
		echo '<div class="titre">' . $langs->trans("ProductCreatedInDolibarr") . '</div>';
		echo '<form method="post" action="' . $_SERVER['PHP_SELF'] . '?id=' . $object->id . '">';
		echo '<input type="hidden" name="token" value="' . newToken() . '">';
		echo '<input type="hidden" name="action" value="sync_from_add">';
		echo '<input type="hidden" name="redirectTo" value="view">';
		echo '<p>' . $langs->trans("ProductCreatedCreateInZSBMS", $object->ref) . '</p>';

		// Show which store will be used
		$storeDisplay = $conf->global->ZS_API_STORE;
		echo '<p><strong>' . $langs->trans("Store") . ':</strong> ' . $storeDisplay . '</p>';
		echo '<table class="border centpercent">';
		echo '<tr><td width="30%">' . $langs->trans("CreateProductInZSBMS") . '</td>';
		echo '<td><input type="radio" name="create_in_zsbms" value="yes" id="create_yes"> <label for="create_yes">' . $langs->trans("Yes") . '</label>';
		echo '&nbsp;&nbsp;<input type="radio" name="create_in_zsbms" value="no" id="create_no" checked> <label for="create_no">' . $langs->trans("No") . '</label></td></tr>';
		echo '<tr id="numbering_row" style="display:none;"><td>' . $langs->trans("NumberingMethod") . '</td>';
		echo '<td><input type="radio" name="numbering_method" value="automatic" id="auto_num" checked> <label for="auto_num">' . $langs->trans("AutomaticNumbering") . '</label>';
		echo '&nbsp;&nbsp;<input type="radio" name="numbering_method" value="manual" id="manual_num"> <label for="manual_num">' . $langs->trans("UseCurrentRef") . '</label></td></tr>';
		echo '</table>';
		echo '<br><div class="center">';
		echo '<input type="submit" class="button" value="' . $langs->trans("Confirm") . '">';
		echo '&nbsp;<input type="button" class="button" value="' . $langs->trans("Cancel") . '" onclick="window.location.href=\'/product/card.php?id=' . $object->id . '\';">';
		echo '</div>';
		echo '</form>';
		echo '</div></div></div>';

		// JavaScript to show/hide numbering options
		echo '<script type="text/javascript">';
		echo 'document.addEventListener("DOMContentLoaded", function() {';
		echo '  var createYes = document.getElementById("create_yes");';
		echo '  var createNo = document.getElementById("create_no");';
		echo '  var numberingRow = document.getElementById("numbering_row");';
		echo '  function toggleNumbering() {';
		echo '    if (createYes.checked) {';
		echo '      numberingRow.style.display = "table-row";';
		echo '    } else {';
		echo '      numberingRow.style.display = "none";';
		echo '    }';
		echo '  }';
		echo '  createYes.addEventListener("change", toggleNumbering);';
		echo '  createNo.addEventListener("change", toggleNumbering);';
		echo '});';
		echo '</script>';

		// Get the output and clean the buffer
		$output = ob_get_contents();
		ob_end_clean();

		// Output directly to the browser
		echo $output;
		exit;
	}

	/**
	 * Inject JS that checks ZSBMS existence and prompts to create the product.
	 *
	 * @param int    $productId
	 * @param bool   $autoOpen  When true, auto-trigger the check (no form interception).
	 * @param string $returnUrl Optional URL to replace the current one after auto-open.
	 * @return string
	 */
	private function renderZsbmsCreateModalOnZsSynch($productId, $autoOpen = false, $returnUrl = '')
	{
		global $langs;

		$productId = (int) $productId;
		if ($productId <= 0) {
			return '';
		}

		$checkUrl = DOL_URL_ROOT . '/custom/kreaflow/ajax/zsbms_product_exists.php';
		$createUrl = DOL_URL_ROOT . '/custom/kreaflow/ajax/zsbms_product_create.php';

		$i18n = array(
			'title' => $langs->trans('CreateProductInZSBMS'),
			'notFound' => $langs->trans('ProductNotFoundInZSBMS'),
			'store' => $langs->trans('Store'),
			'numberingMethod' => $langs->trans('NumberingMethod'),
			'automatic' => $langs->trans('AutomaticNumbering'),
			'manual' => $langs->trans('UseCurrentRef'),
			'create' => $langs->trans('Confirm'),
			'cancel' => $langs->trans('Cancel'),
			'checking' => $langs->trans('Checking'),
			'creating' => $langs->trans('Creating'),
			'created' => $langs->trans('ProductCreatedInZoneSoft'),
		);

		$i18nJson = json_encode($i18n);
		$checkUrlJs = json_encode($checkUrl);
		$createUrlJs = json_encode($createUrl);
		$autoOpenJs = $autoOpen ? 'true' : 'false';
		$returnUrlJs = json_encode($returnUrl);
		$submitOnCreateJs = $autoOpen ? 'false' : 'true';
		$csrfTokenJs = json_encode(newToken());

		return '<script type="text/javascript">
(function() {
	function dzsReady(fn) {
		if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", fn);
		else fn();
	}

	dzsReady(function() {
		var dzsAutoOpen = ' . $autoOpenJs . ';
		var dzsReturnUrl = ' . $returnUrlJs . ';
		var dzsSubmitOnCreate = ' . $submitOnCreateJs . ';
		var zsCheckbox = document.getElementById("options_zs_synch") || document.querySelector("input[name=\'options_zs_synch\']");
		if (!zsCheckbox && !dzsAutoOpen) return;

			var productId = ' . ((int) $productId) . ';
			var checkUrl = ' . $checkUrlJs . ';
			var createUrl = ' . $createUrlJs . ';
			var i18n = ' . $i18nJson . ';
			var csrfToken = ' . $csrfTokenJs . ';
			function dzsDecodeHtml(str) {
				if (!str || str.indexOf("&") === -1) return str;
				var txt = document.createElement("textarea");
				txt.innerHTML = str;
				return txt.value;
			}
			for (var k in i18n) {
				if (Object.prototype.hasOwnProperty.call(i18n, k)) {
					i18n[k] = dzsDecodeHtml(i18n[k]);
				}
			}

			var initialChecked = zsCheckbox ? !!zsCheckbox.checked : false;
			var dzsForm = zsCheckbox ? (zsCheckbox.form || zsCheckbox.closest("form")) : null;
			if (!dzsForm && !dzsAutoOpen) return;

			var dzsAllowSubmit = false;
			var dzsPending = false;
			var dzsSubmitter = null;
			var dzsLastSubmitter = null;

			var dialogId = "dzs-zsbms-create-dialog";

			// Capture last clicked submitter to detect Save vs Cancel (older browsers).
			if (dzsForm) {
				(function() {
					var btns = dzsForm.querySelectorAll("input[type=\'submit\'], button[type=\'submit\']");
					for (var i = 0; i < btns.length; i++) {
						btns[i].addEventListener("click", function() { dzsLastSubmitter = this; });
					}
				})();
			}

			function isCancelSubmitter(btn) {
				if (!btn) return false;
				if (btn.name === "cancel") return true;
				if (btn.classList && btn.classList.contains("button-cancel")) return true;
				return false;
			}

			function proceedSubmit() {
				if (!dzsSubmitOnCreate || !dzsForm) {
					if (dzsReturnUrl) {
						window.location.href = dzsReturnUrl;
					} else if (dzsAutoOpen) {
						window.location.reload();
					}
					return;
				}
				dzsAllowSubmit = true;
				if (dzsForm.requestSubmit) {
					if (dzsSubmitter && dzsSubmitter.form === dzsForm) dzsForm.requestSubmit(dzsSubmitter);
					else dzsForm.requestSubmit();
				} else {
					dzsForm.submit();
				}
			}

			function ensureDialog() {
				var el = document.getElementById(dialogId);
				if (el) return el;

			el = document.createElement("div");
			el.id = dialogId;
			el.style.display = "none";
			el.innerHTML =
				\'<div class="dzs-zsbms-modal">\' +
				\'<p class="dzs-msg" style="margin:0 0 10px 0;"></p>\' +
				\'<div class="dzs-numbering">\' +
				\'<div style="font-weight:700; margin-bottom:6px;">\' + (i18n.numberingMethod || "Numbering") + \'</div>\' +
				\'<label style="display:block; margin:4px 0;"><input type="radio" name="dzs_numbering_method" value="manual" checked> \' + (i18n.manual || "Use current ref") + \'</label>\' +
				\'<label style="display:block; margin:4px 0;"><input type="radio" name="dzs_numbering_method" value="automatic"> \' + (i18n.automatic || "Automatic") + \'</label>\' +
				\'<div class="dzs-hint" style="margin-top:6px; color:#666; font-size:12px;"></div>\' +
				\'</div>\' +
				\'<div class="dzs-error" style="display:none; margin-top:10px; color:#b00020;"></div>\' +
				\'</div>\';

			document.body.appendChild(el);
			return el;
		}

		function setDialogError(msg) {
			var el = document.getElementById(dialogId);
			if (!el) return;
			var err = el.querySelector(".dzs-error");
			if (!err) return;
			if (msg) {
				err.textContent = msg;
				err.style.display = "";
			} else {
				err.textContent = "";
				err.style.display = "none";
			}
		}

			function openDialog(resp) {
				var el = ensureDialog();
				setDialogError("");

			var msg = (i18n.notFound || "Product not found in ZSBMS") + (resp && resp.store ? (" (" + (i18n.store || "Store") + ": " + resp.store + ")") : "");
			var msgEl = el.querySelector(".dzs-msg");
			if (msgEl) msgEl.textContent = msg;

			var canManual = !!(resp && resp.ref_numeric);
			var manualRadio = el.querySelector("input[name=\'dzs_numbering_method\'][value=\'manual\']");
			var autoRadio = el.querySelector("input[name=\'dzs_numbering_method\'][value=\'automatic\']");
			var hint = el.querySelector(".dzs-hint");

			if (manualRadio) manualRadio.disabled = !canManual;
			if (!canManual && autoRadio) autoRadio.checked = true;

			if (hint) {
				hint.textContent = canManual ? "" : (resp && resp.product_ref ? ("Ref \'" + resp.product_ref + "\' nao e numerica; use numeracao automatica.") : "Referencia nao numerica; use numeracao automatica.");
			}

				function revertCheckbox() {
					if (zsCheckbox) zsCheckbox.checked = !!initialChecked;
				}

				function doCreate($dlg) {
					setDialogError("");
				var methodEl = el.querySelector("input[name=\'dzs_numbering_method\']:checked");
				var method = methodEl ? methodEl.value : "manual";

				if (!canManual && method === "manual") {
					method = "automatic";
				}

					if (window.jQuery) {
						jQuery.ajax({
							url: createUrl,
							method: "POST",
							dataType: "json",
							data: { product_id: productId, numbering_method: method, token: csrfToken }
						}).done(function(r) {
							if (!r || !r.success) {
								setDialogError((r && r.error) ? r.error : "Erro ao criar produto.");
								return;
							}

							if ($dlg && $dlg.dialog) {
								$dlg.dialog("close");
							}

							// If automatic numbering was used, reload the page to show the new ref
							if (method === "automatic") {
								if (dzsReturnUrl) {
									window.location.href = dzsReturnUrl;
								} else {
									window.location.reload();
								}
								return;
							}

							if (dzsSubmitOnCreate) {
								if (zsCheckbox) zsCheckbox.checked = true;

								// If ref changed, update the ref field if present
								if (r.new_ref) {
									var refInput = document.getElementById("ref") || document.querySelector("input[name=\'ref\']");
									if (refInput) refInput.value = r.new_ref;
								}

								proceedSubmit();
							} else {
								if (dzsReturnUrl) {
									window.location.href = dzsReturnUrl;
								} else {
									window.location.reload();
								}
							}
						}).fail(function(xhr) {
							setDialogError("Erro ao criar produto.");
						});
					} else {
						// Fallback
						alert("Erro: jQuery nao disponivel.");
					}
				}

			if (window.jQuery && jQuery.fn && jQuery.fn.dialog) {
				var $dlg = jQuery(el);
				$dlg.dialog({
					modal: true,
					width: 520,
					title: i18n.title || "ZSBMS",
					closeOnEscape: true,
					buttons: [
						{
							text: i18n.create || "Create",
							click: function() { doCreate($dlg); }
						},
							{
								text: i18n.cancel || "Cancel",
								click: function() { revertCheckbox(); $dlg.dialog("close"); }
							}
						],
						close: function() { setDialogError(""); }
					});
				} else {
					// Fallback without jQuery UI
					if (confirm(msg + "\\n\\nCriar produto no ZSBMS?")) {
						doCreate(null);
					} else {
						revertCheckbox();
					}
				}
			}

			function checkExists() {
				if (!(window.jQuery)) return;
				if (dzsPending) return;
				dzsPending = true;
				if (zsCheckbox) zsCheckbox.disabled = true;
				jQuery.getJSON(checkUrl, { product_id: productId })
					.done(function(resp) {
						if (zsCheckbox) zsCheckbox.disabled = false;
						dzsPending = false;
						if (!resp || !resp.success) {
							alert((resp && resp.error) ? resp.error : "Erro ao verificar produto no ZSBMS.");
							return;
						}

						if (resp.exists) {
							if (dzsSubmitOnCreate) {
								proceedSubmit();
							} else if (dzsReturnUrl) {
								window.location.href = dzsReturnUrl;
							} else if (dzsAutoOpen) {
								window.location.reload();
							}
							return;
						}

						// Not found -> prompt create
						openDialog(resp);
					})
					.fail(function() {
						if (zsCheckbox) zsCheckbox.disabled = false;
						dzsPending = false;
						alert("Erro ao verificar produto no ZSBMS.");
					});
			}

			if (dzsForm && dzsSubmitOnCreate) {
				dzsForm.addEventListener("submit", function(e) {
					if (dzsAllowSubmit) {
						dzsAllowSubmit = false;
						return;
					}
					var submitter = (e && e.submitter) ? e.submitter : dzsLastSubmitter;
					dzsSubmitter = submitter || null;
					if (isCancelSubmitter(submitter)) {
						return;
					}

					// Only intercept when enabling zs_synch (unchecked -> checked)
					if (initialChecked || !zsCheckbox || !zsCheckbox.checked) {
						return;
					}

					e.preventDefault();
					checkExists();
				});
			}

			if (dzsAutoOpen) {
				if (dzsReturnUrl && window.history && window.history.replaceState) {
					window.history.replaceState(null, document.title, dzsReturnUrl);
				}
				checkExists();
			}
		});
	})();
	</script>';
	}
}

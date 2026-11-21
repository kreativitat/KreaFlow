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
 * \file    dolizsynch/price_schedule.php
 * \ingroup dolizsynch
 * \brief   Row-based scheduled price updates with searchable product selector.
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
if (!$res && file_exists("../main.inc.php")) {
	$res = @include "../main.inc.php";
}
if (!$res && file_exists("../../main.inc.php")) {
	$res = @include "../../main.inc.php";
}
if (!$res) {
	die("Include of main fails");
}

require_once DOL_DOCUMENT_ROOT . '/core/class/html.form.class.php';
require_once DOL_DOCUMENT_ROOT . '/core/lib/date.lib.php';
require_once DOL_DOCUMENT_ROOT . '/core/lib/price.lib.php';
require_once DOL_DOCUMENT_ROOT . '/product/class/product.class.php';
require_once DOL_DOCUMENT_ROOT . '/custom/kreaflow/class/zsprodsynch.class.php';

$langs->loadLangs(array('main', 'other', 'products', 'dolizsynch@kreaflow'));

if (empty($conf->dolizsynch->enabled)) {
	accessforbidden($langs->trans("ErrorModuleNotEnabled"));
}
$canRead = (!empty($user->rights->produit->lire) || !empty($user->rights->service->lire) || !empty($user->admin));
$canWrite = (!empty($user->rights->produit->creer) || !empty($user->rights->service->creer) || !empty($user->admin));
if (!$canRead) {
	accessforbidden();
}

/**
 * Require valid CSRF token.
 *
 * @return void
 */
function dzs_require_token()
{
	if (GETPOST('token', 'alpha') !== $_SESSION['newtoken']) {
		accessforbidden('Wrong CSRF token');
	}
}

/**
 * Parse localized number into float.
 *
 * @param string $raw
 * @return float|null
 */
function dzs_parse_price_value($raw)
{
	$raw = trim((string) $raw);
	if ($raw === '') {
		return null;
	}

	$raw = str_replace(' ', '', $raw);
	if (strpos($raw, ',') !== false && strpos($raw, '.') !== false) {
		$lastComma = strrpos($raw, ',');
		$lastDot = strrpos($raw, '.');
		if ($lastComma > $lastDot) {
			$raw = str_replace('.', '', $raw);
			$raw = str_replace(',', '.', $raw);
		} else {
			$raw = str_replace(',', '', $raw);
		}
	} else {
		$raw = str_replace(',', '.', $raw);
	}

	if (!is_numeric($raw)) {
		return null;
	}
	return (float) $raw;
}

/**
 * Display label for base type.
 *
 * @param string $baseType
 * @return string
 */
function dzs_base_type_label($baseType)
{
	$baseType = strtoupper(trim((string) $baseType));
	return ($baseType === 'HT') ? 'S/IVA' : 'C/IVA';
}

/**
 * Normalize recurrence type.
 *
 * @param string $value
 * @return string
 */
function dzs_normalize_recurrence_type($value)
{
	$value = strtolower(trim((string) $value));
	if ($value === 'weekly') return 'weekly';
	if ($value === 'daily' || $value === 'dayly') return 'daily';
	if ($value === 'monthly' || $value === 'montly') return 'monthly';
	if ($value === 'yearly' || $value === 'annual' || $value === 'annually' || $value === 'anual' || $value === 'anually' || $value === 'anuly') return 'yearly';
	return 'once';
}

/**
 * Map recurrence label to stored rule type.
 *
 * @param string $recurrenceType
 * @return string
 */
function dzs_rule_type_from_recurrence($recurrenceType)
{
	$recurrenceType = dzs_normalize_recurrence_type($recurrenceType);
	if ($recurrenceType === 'daily') return ZSProductSynch::PRICE_SCHEDULE_RULE_TYPE_DAILY_WINDOW;
	if ($recurrenceType === 'weekly') return ZSProductSynch::PRICE_SCHEDULE_RULE_TYPE_WEEKLY_WINDOW;
	if ($recurrenceType === 'monthly') return ZSProductSynch::PRICE_SCHEDULE_RULE_TYPE_MONTHLY_WINDOW;
	if ($recurrenceType === 'yearly') return ZSProductSynch::PRICE_SCHEDULE_RULE_TYPE_YEARLY_WINDOW;
	return ZSProductSynch::PRICE_SCHEDULE_RULE_TYPE_ONCE_WINDOW;
}

/**
 * Map stored rule type to editor recurrence value.
 *
 * @param string $ruleType
 * @return string
 */
function dzs_recurrence_from_rule_type($ruleType)
{
	$ruleType = trim((string) $ruleType);
	if ($ruleType === ZSProductSynch::PRICE_SCHEDULE_RULE_TYPE_DAILY_WINDOW) return 'daily';
	if ($ruleType === ZSProductSynch::PRICE_SCHEDULE_RULE_TYPE_WEEKLY_WINDOW) return 'weekly';
	if ($ruleType === ZSProductSynch::PRICE_SCHEDULE_RULE_TYPE_MONTHLY_WINDOW) return 'monthly';
	if ($ruleType === ZSProductSynch::PRICE_SCHEDULE_RULE_TYPE_YEARLY_WINDOW) return 'yearly';
	return 'once';
}

/**
 * Ensure promotion rule table exists.
 *
 * @param DoliDB $db
 * @return bool
 */
function dzs_ensure_price_schedule_rule_table($db)
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

	$ok = (bool) $db->query($sql);
	$checked = true;

	return $ok;
}

/**
 * Format numeric value for editor input.
 *
 * @param mixed $value
 * @return string
 */
function dzs_price_value_for_input($value)
{
	if ($value === null || $value === '') {
		return '';
	}
	if (!is_numeric($value)) {
		$value = price2num((string) $value, 'MU');
	}
	if (!is_numeric($value)) {
		return '';
	}

	$numeric = (float) $value;
	$formatted = number_format($numeric, 6, '.', '');

	// Keep significant precision but always show at least 2 decimals.
	$formatted = rtrim($formatted, '0');
	if (substr($formatted, -1) === '.') {
		$formatted .= '00';
	} elseif (strpos($formatted, '.') === false) {
		$formatted .= '.00';
	} else {
		$decimals = strlen($formatted) - strpos($formatted, '.') - 1;
		if ($decimals < 2) {
			$formatted .= str_repeat('0', 2 - $decimals);
		}
	}

	if ($formatted === '-0.00' || $formatted === '-0') {
		$formatted = '0.00';
	}
	return $formatted;
}

function dzs_editor_draft_table()
{
	return MAIN_DB_PREFIX . "dolizsynch_price_schedule_draft";
}

/**
 * Ensure draft table exists (safety for upgrades not yet reinitialized).
 *
 * @param DoliDB $db
 * @return bool
 */
function dzs_ensure_editor_draft_table($db)
{
	static $checked = false;
	static $ok = false;

	if ($checked) {
		return $ok;
	}

	$table = dzs_editor_draft_table();
	$sql = "CREATE TABLE IF NOT EXISTS " . $table . " (";
	$sql .= " rowid INTEGER AUTO_INCREMENT PRIMARY KEY NOT NULL,";
	$sql .= " entity INTEGER NOT NULL DEFAULT 1,";
	$sql .= " fk_user INTEGER NOT NULL,";
	$sql .= " line_order INTEGER NOT NULL DEFAULT 1,";
	$sql .= " fk_product INTEGER NULL,";
	$sql .= " price_base_type VARCHAR(3) NOT NULL DEFAULT 'TTC',";
	$sql .= " scheduled_at DATETIME NOT NULL,";
	$sql .= " scheduled_end_at DATETIME NULL,";
	$sql .= " recurrence_type VARCHAR(16) NOT NULL DEFAULT 'once',";
	$sql .= " prices_json TEXT,";
	$sql .= " date_creation DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,";
	$sql .= " tms TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,";
	$sql .= " UNIQUE KEY uk_dzs_price_schedule_draft_user_line (entity, fk_user, line_order),";
	$sql .= " INDEX idx_dzs_price_schedule_draft_user (entity, fk_user),";
	$sql .= " INDEX idx_dzs_price_schedule_draft_product (fk_product)";
	$sql .= " ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

	$ok = (bool) $db->query($sql);
	if ($ok) {
		$columnsToEnsure = array(
			'scheduled_end_at' => "ALTER TABLE " . $table . " ADD COLUMN scheduled_end_at DATETIME NULL AFTER scheduled_at",
			'recurrence_type' => "ALTER TABLE " . $table . " ADD COLUMN recurrence_type VARCHAR(16) NOT NULL DEFAULT 'once' AFTER scheduled_end_at",
		);
		foreach ($columnsToEnsure as $colName => $alterSql) {
			$resCol = $db->query("SHOW COLUMNS FROM " . $table . " LIKE '" . $db->escape($colName) . "'");
			$exists = ($resCol && $db->num_rows($resCol) > 0);
			if ($resCol) $db->free($resCol);
			if (!$exists) {
				if (!$db->query($alterSql)) {
					$ok = false;
					break;
				}
			}
		}
	}
	$checked = true;

	return $ok;
}

/**
 * Normalize editor rows before save/load.
 *
 * @param array<int,array<string,mixed>> $rows
 * @param int $maxPriceLevels
 * @param string $defaultDatetime
 * @return array<int,array<string,mixed>>
 */
function dzs_normalize_editor_rows($rows, $maxPriceLevels, $defaultDatetime)
{
	$out = array();
	if (!is_array($rows)) {
		return array(dzs_new_editor_row($maxPriceLevels, $defaultDatetime));
	}

	$maxRows = 200;
	$count = 0;
	foreach ($rows as $row) {
		$count++;
		if ($count > $maxRows) {
			break;
		}

		$productId = (int) ($row['product_id'] ?? 0);
		$baseType = strtoupper(trim((string) ($row['price_base_type'] ?? 'TTC')));
		if ($baseType !== 'HT' && $baseType !== 'TTC') {
			$baseType = 'TTC';
		}
		$scheduledAt = trim((string) ($row['scheduled_at'] ?? ''));
		if ($scheduledAt === '') {
			$scheduledAt = $defaultDatetime;
		}
		$scheduledEndAt = trim((string) ($row['scheduled_end_at'] ?? ''));
		$recurrenceType = dzs_normalize_recurrence_type((string) ($row['recurrence_type'] ?? 'once'));
		$editRuleRowId = (int) ($row['edit_rule_rowid'] ?? 0);
		if ($editRuleRowId < 0) $editRuleRowId = 0;
		$editRuleRowId = (int) ($row['edit_rule_rowid'] ?? 0);
		if ($editRuleRowId < 0) $editRuleRowId = 0;

		$prices = array();
		for ($level = 1; $level <= $maxPriceLevels; $level++) {
			$prices[$level] = trim((string) ($row['prices'][$level] ?? ''));
		}

		$out[] = array(
			'product_id' => $productId,
			'price_base_type' => $baseType,
			'scheduled_at' => $scheduledAt,
			'scheduled_end_at' => $scheduledEndAt,
			'recurrence_type' => $recurrenceType,
			'edit_rule_rowid' => $editRuleRowId,
			'prices' => $prices
		);
	}

	if (empty($out)) {
		$out[] = dzs_new_editor_row($maxPriceLevels, $defaultDatetime);
	}

	return $out;
}

/**
 * Load legacy drafts that were stored in the schedule table.
 *
 * @param DoliDB $db
 * @param int $entity
 * @param int $userId
 * @param int $maxPriceLevels
 * @param string $defaultDatetime
 * @return array<int,array<string,mixed>>
 */
function dzs_load_legacy_editor_draft_rows($db, $entity, $userId, $maxPriceLevels, $defaultDatetime)
{
	$sql = "SELECT fk_product, price_base_type, scheduled_at, error_message";
	$sql .= " FROM " . MAIN_DB_PREFIX . "dolizsynch_price_schedule";
	$sql .= " WHERE entity = " . ((int) $entity);
	$sql .= " AND source = 'manual_grid_draft'";
	$sql .= " AND fk_user_creat = " . ((int) $userId);
	$sql .= " ORDER BY rowid ASC";

	$resql = $db->query($sql);
	if (!$resql) {
		return array();
	}

	$rows = array();
	while ($obj = $db->fetch_object($resql)) {
		$baseType = strtoupper(trim((string) $obj->price_base_type));
		if ($baseType !== 'HT' && $baseType !== 'TTC') {
			$baseType = 'TTC';
		}

		$scheduledAt = $defaultDatetime;
		if (!empty($obj->scheduled_at)) {
			$ts = $db->jdate($obj->scheduled_at);
			if (!empty($ts)) {
				$scheduledAt = date('Y-m-d\TH:i', $ts);
			}
		}

		$prices = array();
		for ($level = 1; $level <= $maxPriceLevels; $level++) {
			$prices[$level] = '';
		}
		$editRuleRowId = 0;

		$rawPayload = trim((string) $obj->error_message);
		if ($rawPayload !== '') {
			$decoded = json_decode($rawPayload, true);
			if (is_array($decoded) && isset($decoded['prices']) && is_array($decoded['prices'])) {
				for ($level = 1; $level <= $maxPriceLevels; $level++) {
					if (isset($decoded['prices'][(string) $level])) {
						$prices[$level] = trim((string) $decoded['prices'][(string) $level]);
					}
				}
			}
			if (is_array($decoded) && isset($decoded['edit_rule_rowid'])) {
				$editRuleRowId = (int) $decoded['edit_rule_rowid'];
				if ($editRuleRowId < 0) $editRuleRowId = 0;
			}
		}

		$rows[] = array(
			'product_id' => (int) $obj->fk_product,
			'price_base_type' => $baseType,
			'scheduled_at' => $scheduledAt,
			'prices' => $prices
		);
	}
	$db->free($resql);

	return dzs_normalize_editor_rows($rows, $maxPriceLevels, $defaultDatetime);
}

/**
 * Cleanup legacy drafts from schedule table after migration.
 *
 * @param DoliDB $db
 * @param int $entity
 * @param int $userId
 * @return void
 */
function dzs_cleanup_legacy_editor_drafts($db, $entity, $userId)
{
	$sql = "DELETE FROM " . MAIN_DB_PREFIX . "dolizsynch_price_schedule";
	$sql .= " WHERE entity = " . ((int) $entity);
	$sql .= " AND source = 'manual_grid_draft'";
	$sql .= " AND fk_user_creat = " . ((int) $userId);
	$db->query($sql);
}

/**
 * Persist current editor rows in llx_dolizsynch_price_schedule_draft.
 *
 * @param DoliDB $db
 * @param int $entity
 * @param int $userId
 * @param array<int,array<string,mixed>> $rows
 * @param int $maxPriceLevels
 * @param string $defaultDatetime
 * @return bool
 */
function dzs_save_editor_draft($db, $entity, $userId, $rows, $maxPriceLevels, $defaultDatetime)
{
	if (!dzs_ensure_editor_draft_table($db)) {
		return false;
	}

	$rows = dzs_normalize_editor_rows($rows, $maxPriceLevels, $defaultDatetime);
	$table = dzs_editor_draft_table();
	$entity = (int) $entity;
	$userId = (int) $userId;

	$db->begin();

	$sqlDelete = "DELETE FROM " . $table;
	$sqlDelete .= " WHERE entity = " . $entity;
	$sqlDelete .= " AND fk_user = " . $userId;
	if (!$db->query($sqlDelete)) {
		$db->rollback();
		return false;
	}

	$lineOrder = 1;
	foreach ($rows as $row) {
		$productId = (int) ($row['product_id'] ?? 0);
		if ($productId <= 0) $productId = 0;

		$baseType = strtoupper(trim((string) ($row['price_base_type'] ?? 'TTC')));
		if ($baseType !== 'HT' && $baseType !== 'TTC') {
			$baseType = 'TTC';
		}

		$scheduledAtInput = trim((string) ($row['scheduled_at'] ?? ''));
		if ($scheduledAtInput === '') {
			$scheduledAtInput = $defaultDatetime;
		}
		$scheduledNorm = str_replace('T', ' ', $scheduledAtInput);
		$scheduledTs = dol_stringtotime($scheduledNorm, 'dayhour');
		if (empty($scheduledTs)) {
			$scheduledTs = strtotime($scheduledNorm);
		}
		if (empty($scheduledTs)) {
			$fallbackNorm = str_replace('T', ' ', $defaultDatetime);
			$scheduledTs = dol_stringtotime($fallbackNorm, 'dayhour');
			if (empty($scheduledTs)) {
				$scheduledTs = dol_now() + 3600;
			}
		}
		$scheduledSql = $db->idate($scheduledTs);

		$scheduledEndInput = trim((string) ($row['scheduled_end_at'] ?? ''));
		$scheduledEndSqlValue = "NULL";
		if ($scheduledEndInput !== '') {
			$endNorm = str_replace('T', ' ', $scheduledEndInput);
			$endTs = dol_stringtotime($endNorm, 'dayhour');
			if (empty($endTs)) {
				$endTs = strtotime($endNorm);
			}
			if (!empty($endTs)) {
				$scheduledEndSqlValue = "'" . $db->idate($endTs) . "'";
			}
		}

		$recurrenceType = dzs_normalize_recurrence_type((string) ($row['recurrence_type'] ?? 'once'));

		$prices = array();
		for ($level = 1; $level <= $maxPriceLevels; $level++) {
			$prices[(string) $level] = trim((string) ($row['prices'][$level] ?? ''));
		}
		$payload = json_encode(array('prices' => $prices, 'edit_rule_rowid' => $editRuleRowId), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		if ($payload === false) {
			$payload = '{}';
		}

		$sqlInsert = "INSERT INTO " . $table . " (";
		$sqlInsert .= "entity, fk_user, line_order, fk_product, price_base_type, scheduled_at, scheduled_end_at, recurrence_type, prices_json, date_creation";
		$sqlInsert .= ") VALUES (";
		$sqlInsert .= $entity . ", ";
		$sqlInsert .= $userId . ", ";
		$sqlInsert .= ((int) $lineOrder) . ", ";
		$sqlInsert .= ($productId > 0 ? $productId : "NULL") . ", ";
		$sqlInsert .= "'" . $db->escape($baseType) . "', ";
		$sqlInsert .= "'" . $scheduledSql . "', ";
		$sqlInsert .= $scheduledEndSqlValue . ", ";
		$sqlInsert .= "'" . $db->escape($recurrenceType) . "', ";
		$sqlInsert .= "'" . $db->escape($payload) . "', ";
		$sqlInsert .= "'" . $db->idate(dol_now()) . "'";
		$sqlInsert .= ")";

		if (!$db->query($sqlInsert)) {
			$db->rollback();
			return false;
		}
		$lineOrder++;
	}

	$db->commit();
	dzs_cleanup_legacy_editor_drafts($db, $entity, $userId);
	return true;
}

/**
 * Load persisted editor rows from llx_dolizsynch_price_schedule_draft rows.
 *
 * @param DoliDB $db
 * @param int $entity
 * @param int $userId
 * @param int $maxPriceLevels
 * @param string $defaultDatetime
 * @return array<int,array<string,mixed>>
 */
function dzs_load_editor_draft($db, $entity, $userId, $maxPriceLevels, $defaultDatetime)
{
	if (!dzs_ensure_editor_draft_table($db)) {
		$legacyRows = dzs_load_legacy_editor_draft_rows($db, $entity, $userId, $maxPriceLevels, $defaultDatetime);
		if (!empty($legacyRows)) {
			return $legacyRows;
		}
		return array(dzs_new_editor_row($maxPriceLevels, $defaultDatetime));
	}

	$table = dzs_editor_draft_table();
	$sql = "SELECT fk_product, price_base_type, scheduled_at, scheduled_end_at, recurrence_type, prices_json";
	$sql .= " FROM " . $table;
	$sql .= " WHERE entity = " . ((int) $entity);
	$sql .= " AND fk_user = " . ((int) $userId);
	$sql .= " ORDER BY line_order ASC, rowid ASC";

	$resql = $db->query($sql);
	if (!$resql) {
		$legacyRows = dzs_load_legacy_editor_draft_rows($db, $entity, $userId, $maxPriceLevels, $defaultDatetime);
		if (!empty($legacyRows)) {
			dzs_save_editor_draft($db, $entity, $userId, $legacyRows, $maxPriceLevels, $defaultDatetime);
			return $legacyRows;
		}
		return array(dzs_new_editor_row($maxPriceLevels, $defaultDatetime));
	}

	$rows = array();
	while ($obj = $db->fetch_object($resql)) {
		$baseType = strtoupper(trim((string) $obj->price_base_type));
		if ($baseType !== 'HT' && $baseType !== 'TTC') {
			$baseType = 'TTC';
		}

		$scheduledAt = $defaultDatetime;
		if (!empty($obj->scheduled_at)) {
			$ts = $db->jdate($obj->scheduled_at);
			if (!empty($ts)) {
				$scheduledAt = date('Y-m-d\TH:i', $ts);
			}
		}
		$scheduledEndAt = '';
		if (!empty($obj->scheduled_end_at)) {
			$tsEnd = $db->jdate($obj->scheduled_end_at);
			if (!empty($tsEnd)) {
				$scheduledEndAt = date('Y-m-d\TH:i', $tsEnd);
			}
		}
		$recurrenceType = dzs_normalize_recurrence_type((string) ($obj->recurrence_type ?? 'once'));

		$prices = array();
		for ($level = 1; $level <= $maxPriceLevels; $level++) {
			$prices[$level] = '';
		}

		$rawPayload = trim((string) $obj->prices_json);
		if ($rawPayload !== '') {
			$decoded = json_decode($rawPayload, true);
			if (is_array($decoded) && isset($decoded['prices']) && is_array($decoded['prices'])) {
				for ($level = 1; $level <= $maxPriceLevels; $level++) {
					if (isset($decoded['prices'][(string) $level])) {
						$prices[$level] = trim((string) $decoded['prices'][(string) $level]);
					}
				}
			}
		}

		$rows[] = array(
			'product_id' => (int) $obj->fk_product,
			'price_base_type' => $baseType,
			'scheduled_at' => $scheduledAt,
			'scheduled_end_at' => $scheduledEndAt,
			'recurrence_type' => $recurrenceType,
			'edit_rule_rowid' => $editRuleRowId,
			'prices' => $prices
		);
	}

	$db->free($resql);

	if (empty($rows)) {
		$legacyRows = dzs_load_legacy_editor_draft_rows($db, $entity, $userId, $maxPriceLevels, $defaultDatetime);
		if (!empty($legacyRows)) {
			dzs_save_editor_draft($db, $entity, $userId, $legacyRows, $maxPriceLevels, $defaultDatetime);
			return $legacyRows;
		}
		return array(dzs_new_editor_row($maxPriceLevels, $defaultDatetime));
	}

	return dzs_normalize_editor_rows($rows, $maxPriceLevels, $defaultDatetime);
}

/**
 * Parse comma-separated numeric row ids.
 *
 * @param string $raw
 * @return int[]
 */
function dzs_parse_row_ids($raw)
{
	$ids = array();
	foreach (explode(',', (string) $raw) as $part) {
		$part = trim($part);
		if ($part !== '' && ctype_digit($part)) {
			$ids[(int) $part] = (int) $part;
		}
	}
	return array_values($ids);
}

/**
 * Find product by id with cache.
 *
 * @param DoliDB $db
 * @param int $productId
 * @param string $entityList
 * @param array<int,array<string,mixed>|null> $cache
 * @return array<string,mixed>|null
 */
function dzs_lookup_product_by_id($db, $productId, $entityList, &$cache)
{
	$productId = (int) $productId;
	if ($productId <= 0) {
		return null;
	}
	if (array_key_exists($productId, $cache)) {
		return $cache[$productId];
	}

	$sql = "SELECT rowid, ref, label";
	$sql .= " FROM " . MAIN_DB_PREFIX . "product";
	$sql .= " WHERE rowid = " . $productId;
	if (!empty($entityList)) {
		$sql .= " AND entity IN (" . $entityList . ")";
	}
	$sql .= " LIMIT 1";

	$resql = $db->query($sql);
	if (!$resql || $db->num_rows($resql) === 0) {
		if ($resql) {
			$db->free($resql);
		}
		$cache[$productId] = null;
		return null;
	}

	$obj = $db->fetch_object($resql);
	$db->free($resql);

	$cache[$productId] = array(
		'rowid' => (int) $obj->rowid,
		'ref' => (string) $obj->ref,
		'label' => (string) $obj->label
	);
	return $cache[$productId];
}

/**
 * Create one empty editor row.
 *
 * @param int $maxPriceLevels
 * @param string $defaultDatetime
 * @return array<string,mixed>
 */
function dzs_new_editor_row($maxPriceLevels, $defaultDatetime)
{
	$row = array(
		'product_id' => 0,
		'price_base_type' => 'TTC',
		'scheduled_at' => $defaultDatetime,
		'scheduled_end_at' => '',
		'recurrence_type' => 'once',
		'edit_rule_rowid' => 0,
		'prices' => array()
	);

	for ($level = 1; $level <= $maxPriceLevels; $level++) {
		$row['prices'][$level] = '';
	}

	return $row;
}

/**
 * Collect editor rows from POST.
 *
 * @param int $maxPriceLevels
 * @param string $defaultDatetime
 * @return array<int,array<string,mixed>>
 */
function dzs_collect_editor_rows_from_post($maxPriceLevels, $defaultDatetime)
{
	$productIds = GETPOST('product_id', 'array');
	$baseTypes = GETPOST('price_base_type', 'array');
	$scheduledAts = GETPOST('scheduled_at', 'array');
	$scheduledEndAts = GETPOST('scheduled_end_at', 'array');
	$recurrenceTypes = GETPOST('recurrence_type', 'array');
	$editRuleRowIds = GETPOST('edit_rule_rowid', 'array');
	$productSelects = array();

	if (!is_array($productIds)) $productIds = array();
	if (!is_array($baseTypes)) $baseTypes = array();
	if (!is_array($scheduledAts)) $scheduledAts = array();
	if (!is_array($scheduledEndAts)) $scheduledEndAts = array();
	if (!is_array($recurrenceTypes)) $recurrenceTypes = array();
	if (!is_array($editRuleRowIds)) $editRuleRowIds = array();
	if (is_array($_POST)) {
		foreach ($_POST as $key => $value) {
			if (preg_match('/^product_select_(\d+)$/', (string) $key, $matches)) {
				$productSelects[(int) $matches[1]] = (int) $value;
			}
		}
	}

	$priceArrays = array();
	for ($level = 1; $level <= $maxPriceLevels; $level++) {
		$key = 'price_l' . $level;
		$tmp = GETPOST($key, 'array');
		$priceArrays[$level] = is_array($tmp) ? $tmp : array();
	}

	$rowCount = max(count($productIds), count($baseTypes), count($scheduledAts), count($scheduledEndAts), count($recurrenceTypes), count($editRuleRowIds));
	if (!empty($productSelects)) {
		$rowCount = max($rowCount, max(array_keys($productSelects)) + 1);
	}
	for ($level = 1; $level <= $maxPriceLevels; $level++) {
		$rowCount = max($rowCount, count($priceArrays[$level]));
	}
	if ($rowCount <= 0) {
		return array();
	}

	$rows = array();
	for ($i = 0; $i < $rowCount; $i++) {
		$baseType = isset($baseTypes[$i]) ? strtoupper(trim((string) $baseTypes[$i])) : 'TTC';
		if ($baseType !== 'HT' && $baseType !== 'TTC') {
			$baseType = 'TTC';
		}

		$scheduledAt = isset($scheduledAts[$i]) ? trim((string) $scheduledAts[$i]) : '';
		if ($scheduledAt === '') {
			$scheduledAt = $defaultDatetime;
		}
		$scheduledEndAt = isset($scheduledEndAts[$i]) ? trim((string) $scheduledEndAts[$i]) : '';
		$recurrenceType = dzs_normalize_recurrence_type(isset($recurrenceTypes[$i]) ? (string) $recurrenceTypes[$i] : 'once');
		$editRuleRowId = isset($editRuleRowIds[$i]) ? (int) $editRuleRowIds[$i] : 0;
		if ($editRuleRowId < 0) $editRuleRowId = 0;

		$row = array(
			'product_id' => isset($productSelects[$i]) ? (int) $productSelects[$i] : (isset($productIds[$i]) ? (int) $productIds[$i] : 0),
			'price_base_type' => $baseType,
			'scheduled_at' => $scheduledAt,
			'scheduled_end_at' => $scheduledEndAt,
			'recurrence_type' => $recurrenceType,
			'edit_rule_rowid' => $editRuleRowId,
			'prices' => array()
		);
		for ($level = 1; $level <= $maxPriceLevels; $level++) {
			$row['prices'][$level] = isset($priceArrays[$level][$i]) ? trim((string) $priceArrays[$level][$i]) : '';
		}
		$rows[] = $row;
	}

	return $rows;
}

/**
 * Human status label.
 *
 * @param int $status
 * @param Translate $langs
 * @return string
 */
function dzs_group_status_label($status, $langs)
{
	if ($status === ZSProductSynch::PRICE_SCHEDULE_PENDING) return $langs->trans("PriceScheduleStatusPending");
	if ($status === ZSProductSynch::PRICE_SCHEDULE_DONE) return $langs->trans("PriceScheduleStatusDone");
	if ($status === ZSProductSynch::PRICE_SCHEDULE_ERROR) return $langs->trans("PriceScheduleStatusError");
	if ($status === ZSProductSynch::PRICE_SCHEDULE_CANCELED) return $langs->trans("PriceScheduleStatusCanceled");
	if ($status === 99) return $langs->trans("PriceScheduleStatusMixed");
	return (string) $status;
}

/**
 * Badge CSS by status.
 *
 * @param int $status
 * @return string
 */
function dzs_group_status_class($status)
{
	if ($status === ZSProductSynch::PRICE_SCHEDULE_PENDING) return 'badge-status4';
	if ($status === ZSProductSynch::PRICE_SCHEDULE_DONE) return 'badge-status6';
	if ($status === ZSProductSynch::PRICE_SCHEDULE_ERROR) return 'badge-status8';
	if ($status === ZSProductSynch::PRICE_SCHEDULE_CANCELED) return 'badge-status9';
	if ($status === 99) return 'badge-status5';
	return 'badge-status0';
}

/**
 * Human label for promotion rule status.
 *
 * @param int $status
 * @param int $isActive
 * @param Translate $langs
 * @return string
 */
function dzs_rule_status_label($status, $isActive, $langs)
{
	$status = (int) $status;
	$isActive = (int) $isActive;
	if ($status === ZSProductSynch::PRICE_SCHEDULE_RULE_ENABLED) {
		return ($isActive === 1) ? $langs->trans("PriceScheduleRuleStatusActive") : $langs->trans("PriceScheduleRuleStatusEnabled");
	}
	if ($status === ZSProductSynch::PRICE_SCHEDULE_RULE_DONE) return $langs->trans("PriceScheduleStatusDone");
	if ($status === ZSProductSynch::PRICE_SCHEDULE_RULE_ERROR) return $langs->trans("PriceScheduleStatusError");
	if ($status === ZSProductSynch::PRICE_SCHEDULE_RULE_CANCELED) return $langs->trans("PriceScheduleStatusCanceled");
	return (string) $status;
}

/**
 * Badge class for promotion rule status.
 *
 * @param int $status
 * @param int $isActive
 * @return string
 */
function dzs_rule_status_class($status, $isActive)
{
	$status = (int) $status;
	$isActive = (int) $isActive;
	if ($status === ZSProductSynch::PRICE_SCHEDULE_RULE_ENABLED) {
		return ($isActive === 1) ? 'badge-status6' : 'badge-status4';
	}
	if ($status === ZSProductSynch::PRICE_SCHEDULE_RULE_DONE) return 'badge-status6';
	if ($status === ZSProductSynch::PRICE_SCHEDULE_RULE_ERROR) return 'badge-status8';
	if ($status === ZSProductSynch::PRICE_SCHEDULE_RULE_CANCELED) return 'badge-status9';
	return 'badge-status0';
}

$form = new Form($db);
$action = GETPOST('action', 'aZ09');
$writeActions = array('editrule', 'savedraft', 'addrows', 'cancelgroup', 'retrygroup', 'deleterule', 'disablerule', 'enablerule');
$isWriteIntent = in_array($action, $writeActions, true)
	|| ($_SERVER['REQUEST_METHOD'] === 'POST' && (GETPOSTISSET('editor_add_row') || GETPOSTISSET('editor_del_row')));
if ($isWriteIntent && !$canWrite) {
	accessforbidden();
}

$multiPricesEnabled = (!empty($conf->global->PRODUIT_MULTIPRICES) || !empty($conf->global->PRODUIT_CUSTOMER_PRICES_BY_QTY_MULTIPRICES));
$maxPriceLevels = $multiPricesEnabled ? (int) $conf->global->PRODUIT_MULTIPRICES_LIMIT : 1;
if ($maxPriceLevels <= 0) $maxPriceLevels = 1;
$maxPriceLevels = min(10, $maxPriceLevels);

$entityListProducts = getEntity('product');
$productByIdCache = array();
$defaultEditorDatetime = date('Y-m-d\TH:i', dol_now() + 3600);
$priceRuleTableReady = dzs_ensure_price_schedule_rule_table($db);

if ($action === 'getproductprices') {
	$productId = GETPOSTINT('product_id');
	$baseType = strtoupper(GETPOST('base_type', 'alpha'));
	if ($baseType !== 'HT' && $baseType !== 'TTC') {
		$baseType = 'TTC';
	}

	$response = array(
		'ok' => false,
		'base_type' => $baseType,
		'prices' => array(),
		'error' => ''
	);

	for ($level = 1; $level <= $maxPriceLevels; $level++) {
		$response['prices'][(string) $level] = '';
	}

	if ($productId > 0) {
		$sqlCheck = "SELECT rowid";
		$sqlCheck .= " FROM " . MAIN_DB_PREFIX . "product";
		$sqlCheck .= " WHERE rowid = " . ((int) $productId);
		if (!empty($entityListProducts)) {
			$sqlCheck .= " AND entity IN (" . $entityListProducts . ")";
		}
		$sqlCheck .= " LIMIT 1";

		$resqlCheck = $db->query($sqlCheck);
		if ($resqlCheck && $db->num_rows($resqlCheck) > 0) {
			$productTmp = new Product($db);
			if ($productTmp->fetch($productId) > 0) {
				for ($level = 1; $level <= $maxPriceLevels; $level++) {
					if ($level === 1) {
						$rawPrice = ($baseType === 'HT') ? $productTmp->price : $productTmp->price_ttc;
					} else {
						$rawPrice = ($baseType === 'HT')
							? (isset($productTmp->multiprices[$level]) ? $productTmp->multiprices[$level] : '')
							: (isset($productTmp->multiprices_ttc[$level]) ? $productTmp->multiprices_ttc[$level] : '');
					}
					$response['prices'][(string) $level] = dzs_price_value_for_input($rawPrice);
				}
				$response['ok'] = true;
			} else {
				$response['error'] = 'Product not found';
			}
		} else {
			$response['error'] = 'Invalid product';
		}
		if ($resqlCheck) {
			$db->free($resqlCheck);
		}
	} else {
		$response['error'] = 'Missing product';
	}

	header('Content-Type: application/json; charset=UTF-8');
	header('Cache-Control: no-store, no-cache, must-revalidate');
	print json_encode($response);
	$db->close();
	exit;
}

$editorRows = ($_SERVER['REQUEST_METHOD'] === 'POST')
	? dzs_collect_editor_rows_from_post($maxPriceLevels, $defaultEditorDatetime)
	: dzs_load_editor_draft($db, (int) $conf->entity, (int) $user->id, $maxPriceLevels, $defaultEditorDatetime);

if ($action === 'editrule' && $canWrite) {
	dzs_require_token();

	if (!$priceRuleTableReady) {
		setEventMessages($langs->trans("PriceScheduleRuleTableUnavailable"), null, 'errors');
		header("Location: " . $_SERVER['PHP_SELF']);
		exit;
	}

	$ruleId = GETPOSTINT('id');
	if ($ruleId <= 0) {
		setEventMessages($langs->trans("PriceScheduleInvalidSelection"), null, 'errors');
		header("Location: " . $_SERVER['PHP_SELF']);
		exit;
	}

	$sqlRule = "SELECT rowid, fk_product, product_ref, price_level, price_base_type, promo_price_value, rule_type,";
	$sqlRule .= " start_at, end_at, status, is_active";
	$sqlRule .= " FROM " . MAIN_DB_PREFIX . "dolizsynch_price_schedule_rule";
	$sqlRule .= " WHERE entity = " . ((int) $conf->entity);
	$sqlRule .= " AND rowid = " . ((int) $ruleId);
	$sqlRule .= " LIMIT 1";

	$resqlRule = $db->query($sqlRule);
	if (!$resqlRule || $db->num_rows($resqlRule) === 0) {
		if ($resqlRule) $db->free($resqlRule);
		setEventMessages($langs->trans("PriceScheduleRuleNotFound"), null, 'errors');
		header("Location: " . $_SERVER['PHP_SELF']);
		exit;
	}

	$objRule = $db->fetch_object($resqlRule);
	$db->free($resqlRule);

	if ((int) $objRule->is_active === 1) {
		setEventMessages($langs->trans("PriceScheduleRuleActiveEditBlocked"), null, 'errors');
		header("Location: " . $_SERVER['PHP_SELF']);
		exit;
	}

	$row = dzs_new_editor_row($maxPriceLevels, $defaultEditorDatetime);
	$row['product_id'] = (int) $objRule->fk_product;
	$row['price_base_type'] = strtoupper(trim((string) $objRule->price_base_type));
	if ($row['price_base_type'] !== 'HT' && $row['price_base_type'] !== 'TTC') $row['price_base_type'] = 'TTC';
	$row['recurrence_type'] = dzs_recurrence_from_rule_type((string) $objRule->rule_type);
	$row['edit_rule_rowid'] = (int) $objRule->rowid;

	$startTs = !empty($objRule->start_at) ? $db->jdate($objRule->start_at) : 0;
	if (!empty($startTs)) {
		$row['scheduled_at'] = date('Y-m-d\TH:i', $startTs);
	}
	$endTs = !empty($objRule->end_at) ? $db->jdate($objRule->end_at) : 0;
	if (!empty($endTs)) {
		$row['scheduled_end_at'] = date('Y-m-d\TH:i', $endTs);
	}

	$level = (int) $objRule->price_level;
	if ($level <= 0) $level = 1;
	if ($level > $maxPriceLevels) $level = $maxPriceLevels;
	$row['prices'][$level] = dzs_price_value_for_input((float) $objRule->promo_price_value);

	$editorRows = array($row);
	dzs_save_editor_draft($db, (int) $conf->entity, (int) $user->id, $editorRows, $maxPriceLevels, $defaultEditorDatetime);
	setEventMessages($langs->trans("PriceScheduleRuleLoadedForEdit", $ruleId), null, 'mesgs');

	header("Location: " . $_SERVER['PHP_SELF']);
	exit;
}

if ($action === 'savedraft' && $_SERVER['REQUEST_METHOD'] === 'POST' && $canWrite) {
	dzs_require_token();
	$saved = dzs_save_editor_draft($db, (int) $conf->entity, (int) $user->id, $editorRows, $maxPriceLevels, $defaultEditorDatetime);

	header('Content-Type: application/json; charset=UTF-8');
	header('Cache-Control: no-store, no-cache, must-revalidate');
	print json_encode(array('ok' => $saved ? true : false));
	$db->close();
	exit;
}

// Per-line + button.
if ($canWrite && $_SERVER['REQUEST_METHOD'] === 'POST' && GETPOSTISSET('editor_add_row')) {
	dzs_require_token();

	$idx = GETPOSTINT('editor_add_row');
	if (empty($editorRows)) {
		$editorRows = array(dzs_new_editor_row($maxPriceLevels, $defaultEditorDatetime));
	} else {
		$idx = max(0, min($idx, count($editorRows) - 1));
		array_splice($editorRows, $idx + 1, 0, array(dzs_new_editor_row($maxPriceLevels, $defaultEditorDatetime)));
	}
	dzs_save_editor_draft($db, (int) $conf->entity, (int) $user->id, $editorRows, $maxPriceLevels, $defaultEditorDatetime);
	$action = '';
}

// Per-line - button.
if ($canWrite && $_SERVER['REQUEST_METHOD'] === 'POST' && GETPOSTISSET('editor_del_row')) {
	dzs_require_token();

	$idx = GETPOSTINT('editor_del_row');
	if (empty($editorRows)) {
		$editorRows = array(dzs_new_editor_row($maxPriceLevels, $defaultEditorDatetime));
	} else {
		$idx = max(0, min($idx, count($editorRows) - 1));
		array_splice($editorRows, $idx, 1);
		if (empty($editorRows)) {
			$editorRows = array(dzs_new_editor_row($maxPriceLevels, $defaultEditorDatetime));
		}
	}
	dzs_save_editor_draft($db, (int) $conf->entity, (int) $user->id, $editorRows, $maxPriceLevels, $defaultEditorDatetime);
	$action = '';
}

// Save rows to queue.
if ($action === 'addrows' && $_SERVER['REQUEST_METHOD'] === 'POST' && $canWrite) {
	dzs_require_token();

	$insertedRows = 0;
	$insertedRules = 0;
	$lineErrors = array();
	$scheduleRowIndex = GETPOSTISSET('schedule_row') ? GETPOSTINT('schedule_row') : -1;

	if ($scheduleRowIndex >= 0 && ($scheduleRowIndex < 0 || $scheduleRowIndex >= count($editorRows))) {
		$lineErrors[] = $langs->trans("ErrorBadValueForParameter", "schedule_row");
	}

	if (empty($editorRows)) {
		$lineErrors[] = $langs->trans("PriceScheduleErrorMissingLines");
	} else {
		foreach ($editorRows as $rowIndex => $row) {
			if ($scheduleRowIndex >= 0 && $rowIndex !== $scheduleRowIndex) {
				continue;
			}

				$lineNo = $rowIndex + 1;
				$productId = (int) $row['product_id'];
				$baseType = strtoupper(trim((string) $row['price_base_type']));
				$scheduledAtInput = trim((string) $row['scheduled_at']);
				$scheduledEndInput = trim((string) ($row['scheduled_end_at'] ?? ''));
				$recurrenceType = dzs_normalize_recurrence_type((string) ($row['recurrence_type'] ?? 'once'));
				$editRuleRowId = (int) ($row['edit_rule_rowid'] ?? 0);
				if ($editRuleRowId < 0) $editRuleRowId = 0;
				$prices = is_array($row['prices']) ? $row['prices'] : array();

				if ($baseType !== 'HT' && $baseType !== 'TTC') {
					$baseType = 'TTC';
				}

			$hasPriceValue = false;
			foreach ($prices as $priceRaw) {
				if (trim((string) $priceRaw) !== '') {
					$hasPriceValue = true;
					break;
					}
				}
				$rowHasData = ($productId > 0 || $scheduledAtInput !== '' || $scheduledEndInput !== '' || $hasPriceValue);
				if (!$rowHasData) {
					continue;
				}

			if ($productId <= 0) {
				$lineErrors[] = $langs->trans("PriceScheduleLineMissingRef", $lineNo);
				continue;
			}
			if ($scheduledAtInput === '') {
				$lineErrors[] = $langs->trans("PriceScheduleLineMissingDate", $lineNo);
				continue;
			}
			if (!$hasPriceValue) {
				$lineErrors[] = $langs->trans("PriceScheduleLineNoPrices", $lineNo);
				continue;
			}

			$effectiveNorm = str_replace('T', ' ', $scheduledAtInput);
			$effectiveTs = dol_stringtotime($effectiveNorm, 'dayhour');
			if (empty($effectiveTs)) {
				$effectiveTs = strtotime($effectiveNorm);
			}
			if (empty($effectiveTs)) {
					$lineErrors[] = $langs->trans("PriceScheduleErrorInvalidDate") . ' (#' . $lineNo . ')';
					continue;
				}
				$effectiveSql = $db->idate($effectiveTs);

				$effectiveEndTs = 0;
				$effectiveEndSql = '';
				if ($scheduledEndInput !== '') {
					$endNorm = str_replace('T', ' ', $scheduledEndInput);
					$effectiveEndTs = dol_stringtotime($endNorm, 'dayhour');
					if (empty($effectiveEndTs)) {
						$effectiveEndTs = strtotime($endNorm);
					}
					if (empty($effectiveEndTs)) {
						$lineErrors[] = $langs->trans("PriceScheduleErrorInvalidDate") . ' (#' . $lineNo . ')';
						continue;
					}
					if ($effectiveEndTs <= $effectiveTs) {
						$lineErrors[] = $langs->trans("PriceScheduleLineEndMustBeAfterStart", $lineNo);
						continue;
					}
					$effectiveEndSql = $db->idate($effectiveEndTs);
				}

				$product = dzs_lookup_product_by_id($db, $productId, $entityListProducts, $productByIdCache);
				if (empty($product)) {
					$lineErrors[] = $langs->trans("PriceScheduleLineProductNotFound", $lineNo, '#' . $productId);
				continue;
			}

			$parsedByLevel = array();
			foreach ($prices as $level => $priceRaw) {
				$priceRaw = trim((string) $priceRaw);
				if ($priceRaw === '') continue;

				$parsedValue = dzs_parse_price_value($priceRaw);
				if ($parsedValue === null || $parsedValue < 0) {
					$lineErrors[] = $langs->trans("PriceScheduleLineInvalidPrice", $lineNo, dol_escape_htmltag($product['ref']));
					$parsedByLevel = array();
					break;
				}
				$parsedByLevel[(int) $level] = $parsedValue;
			}
				if (empty($parsedByLevel)) {
					continue;
				}

				$isRecurringType = in_array($recurrenceType, array('daily', 'weekly', 'monthly', 'yearly'), true);
				$isRule = ($isRecurringType || $effectiveEndTs > 0);
				if ($isRecurringType && $effectiveEndTs <= 0) {
					$lineErrors[] = $langs->trans("PriceScheduleLineEndRequiredForRecurrence", $lineNo);
					continue;
				}
				if ($editRuleRowId > 0 && !$isRule) {
					$lineErrors[] = $langs->trans("PriceScheduleRuleEditNeedsPromotion", $lineNo);
					continue;
				}
				if ($isRule && !$priceRuleTableReady) {
					$lineErrors[] = $langs->trans("PriceScheduleRuleTableUnavailable");
					continue;
				}

				if ($isRule) {
					$ruleType = dzs_rule_type_from_recurrence($recurrenceType);
					$weekDay = ($ruleType === ZSProductSynch::PRICE_SCHEDULE_RULE_TYPE_WEEKLY_WINDOW) ? ((int) date('w', $effectiveTs)) : null;
					$weeklyStart = ($ruleType === ZSProductSynch::PRICE_SCHEDULE_RULE_TYPE_WEEKLY_WINDOW) ? date('H:i:s', $effectiveTs) : null;
					$weeklyEnd = ($ruleType === ZSProductSynch::PRICE_SCHEDULE_RULE_TYPE_WEEKLY_WINDOW) ? date('H:i:s', $effectiveEndTs) : null;

					if ($editRuleRowId > 0) {
						if (count($parsedByLevel) !== 1) {
							$lineErrors[] = $langs->trans("PriceScheduleRuleEditSinglePriceLevel", $lineNo);
							continue;
						}

						$sqlCurrentRule = "SELECT rowid, is_active";
						$sqlCurrentRule .= " FROM " . MAIN_DB_PREFIX . "dolizsynch_price_schedule_rule";
						$sqlCurrentRule .= " WHERE entity = " . ((int) $conf->entity);
						$sqlCurrentRule .= " AND rowid = " . ((int) $editRuleRowId);
						$sqlCurrentRule .= " LIMIT 1";
						$resqlCurrentRule = $db->query($sqlCurrentRule);
						if (!$resqlCurrentRule || $db->num_rows($resqlCurrentRule) === 0) {
							if ($resqlCurrentRule) $db->free($resqlCurrentRule);
							$lineErrors[] = $langs->trans("PriceScheduleRuleNotFound");
							continue;
						}
						$objCurrentRule = $db->fetch_object($resqlCurrentRule);
						$db->free($resqlCurrentRule);
						if ((int) $objCurrentRule->is_active === 1) {
							$lineErrors[] = $langs->trans("PriceScheduleRuleActiveEditBlocked");
							continue;
						}

						$level = 1;
						$promoPrice = 0;
						foreach ($parsedByLevel as $tmpLevel => $tmpPrice) {
							$level = (int) $tmpLevel;
							$promoPrice = (float) $tmpPrice;
							break;
						}
						$sqlUpdateRule = "UPDATE " . MAIN_DB_PREFIX . "dolizsynch_price_schedule_rule SET";
						$sqlUpdateRule .= " fk_product = " . ((int) $product['rowid']) . ",";
						$sqlUpdateRule .= " product_ref = '" . $db->escape($product['ref']) . "',";
						$sqlUpdateRule .= " price_level = " . ((int) $level) . ",";
						$sqlUpdateRule .= " price_base_type = '" . $db->escape($baseType) . "',";
						$sqlUpdateRule .= " promo_price_value = " . ((float) $promoPrice) . ",";
						$sqlUpdateRule .= " rule_type = '" . $db->escape($ruleType) . "',";
						$sqlUpdateRule .= " start_at = '" . $effectiveSql . "',";
						$sqlUpdateRule .= " end_at = '" . $db->escape($effectiveEndSql) . "',";
						$sqlUpdateRule .= " weekly_day = " . (($weekDay === null) ? "NULL" : ((int) $weekDay)) . ",";
						$sqlUpdateRule .= " weekly_start_time = " . (($weeklyStart === null) ? "NULL" : ("'" . $db->escape($weeklyStart) . "'")) . ",";
						$sqlUpdateRule .= " weekly_end_time = " . (($weeklyEnd === null) ? "NULL" : ("'" . $db->escape($weeklyEnd) . "'")) . ",";
						$sqlUpdateRule .= " status = " . ZSProductSynch::PRICE_SCHEDULE_RULE_ENABLED . ",";
						$sqlUpdateRule .= " is_active = 0,";
						$sqlUpdateRule .= " last_base_price = NULL,";
						$sqlUpdateRule .= " last_start_at = NULL,";
						$sqlUpdateRule .= " last_end_at = NULL,";
						$sqlUpdateRule .= " error_message = NULL,";
						$sqlUpdateRule .= " date_processed = NULL,";
						$sqlUpdateRule .= " fk_user_modif = " . ((int) $user->id);
						$sqlUpdateRule .= " WHERE entity = " . ((int) $conf->entity);
						$sqlUpdateRule .= " AND rowid = " . ((int) $editRuleRowId);
						$sqlUpdateRule .= " LIMIT 1";

						if ($db->query($sqlUpdateRule)) {
							$insertedRules++;
							$editorRows[$rowIndex]['edit_rule_rowid'] = 0;
						} else {
							$lineErrors[] = $langs->trans("PriceScheduleRuleEditFailed");
						}

						continue;
					}

					foreach ($parsedByLevel as $level => $priceValue) {
						$sqlRule = "INSERT INTO " . MAIN_DB_PREFIX . "dolizsynch_price_schedule_rule (";
						$sqlRule .= "entity, fk_product, product_ref, price_level, price_base_type, promo_price_value, rule_type,";
						$sqlRule .= "start_at, end_at, weekly_day, weekly_start_time, weekly_end_time, status, source, fk_user_creat, date_creation";
						$sqlRule .= ") VALUES (";
						$sqlRule .= ((int) $conf->entity) . ", ";
						$sqlRule .= ((int) $product['rowid']) . ", ";
						$sqlRule .= "'" . $db->escape($product['ref']) . "', ";
						$sqlRule .= ((int) $level) . ", ";
						$sqlRule .= "'" . $db->escape($baseType) . "', ";
						$sqlRule .= ((float) $priceValue) . ", ";
						$sqlRule .= "'" . $db->escape($ruleType) . "', ";
						$sqlRule .= "'" . $effectiveSql . "', ";
						$sqlRule .= "'" . $db->escape($effectiveEndSql) . "', ";
						$sqlRule .= (($weekDay === null) ? "NULL" : ((int) $weekDay)) . ", ";
						$sqlRule .= (($weeklyStart === null) ? "NULL" : ("'" . $db->escape($weeklyStart) . "'")) . ", ";
						$sqlRule .= (($weeklyEnd === null) ? "NULL" : ("'" . $db->escape($weeklyEnd) . "'")) . ", ";
						$sqlRule .= ZSProductSynch::PRICE_SCHEDULE_RULE_ENABLED . ", ";
						$sqlRule .= "'manual_grid_rule', ";
						$sqlRule .= ((int) $user->id) . ", ";
						$sqlRule .= "'" . $db->idate(dol_now()) . "'";
						$sqlRule .= ")";

						if ($db->query($sqlRule)) {
							$insertedRules++;
						} else {
							$lineErrors[] = $langs->trans("PriceScheduleLineInsertFailed", $lineNo, dol_escape_htmltag($product['ref']));
						}
					}

					continue;
				}

				foreach ($parsedByLevel as $level => $priceValue) {
					$sqlInsert = "INSERT INTO " . MAIN_DB_PREFIX . "dolizsynch_price_schedule (";
					$sqlInsert .= "entity, fk_product, product_ref, price_level, price_base_type, price_value, scheduled_at, status, source, fk_user_creat, date_creation";
				$sqlInsert .= ") VALUES (";
				$sqlInsert .= ((int) $conf->entity) . ", ";
				$sqlInsert .= ((int) $product['rowid']) . ", ";
				$sqlInsert .= "'" . $db->escape($product['ref']) . "', ";
				$sqlInsert .= ((int) $level) . ", ";
				$sqlInsert .= "'" . $db->escape($baseType) . "', ";
				$sqlInsert .= ((float) $priceValue) . ", ";
				$sqlInsert .= "'" . $effectiveSql . "', ";
				$sqlInsert .= ZSProductSynch::PRICE_SCHEDULE_PENDING . ", ";
				$sqlInsert .= "'manual_grid', ";
				$sqlInsert .= ((int) $user->id) . ", ";
				$sqlInsert .= "'" . $db->idate(dol_now()) . "'";
				$sqlInsert .= ")";

				if ($db->query($sqlInsert)) {
					$insertedRows++;
				} else {
					$lineErrors[] = $langs->trans("PriceScheduleLineInsertFailed", $lineNo, dol_escape_htmltag($product['ref']));
				}
			}
		}
	}

	if ($insertedRows > 0 || $insertedRules > 0) {
		// Process due rows immediately so users don't have to wait for the next cron tick.
		$scheduledSync = new ZSProductSynch($db);
		$scheduledSync->processScheduledPriceUpdates(500);
		$successMessages = array();
		if ($insertedRows > 0) {
			$successMessages[] = $langs->trans("PriceScheduleRowsQueuedSimple", $insertedRows);
		}
		if ($insertedRules > 0) {
			$successMessages[] = $langs->trans("PriceScheduleRulesQueuedSimple", $insertedRules);
		}
		setEventMessages(implode('<br>', $successMessages), null, 'mesgs');
	}
	if (!empty($lineErrors)) {
		setEventMessages(implode('<br>', array_slice($lineErrors, 0, 20)), null, 'errors');
	}

	dzs_save_editor_draft($db, (int) $conf->entity, (int) $user->id, $editorRows, $maxPriceLevels, $defaultEditorDatetime);

	header("Location: " . $_SERVER['PHP_SELF']);
	exit;
}

if (empty($editorRows)) {
	$editorRows = array(dzs_new_editor_row($maxPriceLevels, $defaultEditorDatetime));
}

// Group actions on queued rows.
if (($action === 'cancelgroup' || $action === 'retrygroup') && $canWrite) {
	dzs_require_token();

	$ids = dzs_parse_row_ids(GETPOST('ids', 'alphanohtml'));
	if (empty($ids)) {
		setEventMessages($langs->trans("PriceScheduleInvalidSelection"), null, 'errors');
		header("Location: " . $_SERVER['PHP_SELF']);
		exit;
	}

	$idList = implode(',', array_map('intval', $ids));
	if ($action === 'cancelgroup') {
		$sql = "UPDATE " . MAIN_DB_PREFIX . "dolizsynch_price_schedule";
		$sql .= " SET status = " . ZSProductSynch::PRICE_SCHEDULE_CANCELED;
		$sql .= ", date_processed = '" . $db->idate(dol_now()) . "'";
		$sql .= ", fk_user_modif = " . ((int) $user->id);
		$sql .= " WHERE entity = " . ((int) $conf->entity);
		$sql .= " AND status = " . ZSProductSynch::PRICE_SCHEDULE_PENDING;
		$sql .= " AND rowid IN (" . $idList . ")";
		if ($db->query($sql)) {
			setEventMessages($langs->trans("PriceScheduleRowsCanceled"), null, 'mesgs');
		} else {
			setEventMessages($langs->trans("PriceScheduleRowCancelFailed") . ' ' . $db->lasterror(), null, 'errors');
		}
	} else {
		$sql = "UPDATE " . MAIN_DB_PREFIX . "dolizsynch_price_schedule";
		$sql .= " SET status = " . ZSProductSynch::PRICE_SCHEDULE_PENDING;
		$sql .= ", error_message = NULL";
		$sql .= ", date_processed = NULL";
		$sql .= ", fk_user_modif = " . ((int) $user->id);
		$sql .= " WHERE entity = " . ((int) $conf->entity);
		$sql .= " AND status = " . ZSProductSynch::PRICE_SCHEDULE_ERROR;
		$sql .= " AND rowid IN (" . $idList . ")";
		if ($db->query($sql)) {
			$scheduledSync = new ZSProductSynch($db);
			$scheduledSync->processScheduledPriceUpdates(500);
			setEventMessages($langs->trans("PriceScheduleRowsRetried"), null, 'mesgs');
		} else {
			setEventMessages($langs->trans("PriceScheduleRowRetryFailed") . ' ' . $db->lasterror(), null, 'errors');
		}
	}

	header("Location: " . $_SERVER['PHP_SELF']);
	exit;
}

// Group actions on promotion rules.
if ($action === 'deleterule' && $canWrite) {
	dzs_require_token();

	if (!$priceRuleTableReady) {
		setEventMessages($langs->trans("PriceScheduleRuleTableUnavailable"), null, 'errors');
		header("Location: " . $_SERVER['PHP_SELF']);
		exit;
	}

	$ruleId = GETPOSTINT('id');
	if ($ruleId <= 0) {
		setEventMessages($langs->trans("PriceScheduleInvalidSelection"), null, 'errors');
		header("Location: " . $_SERVER['PHP_SELF']);
		exit;
	}

	$sqlRule = "SELECT rowid, is_active";
	$sqlRule .= " FROM " . MAIN_DB_PREFIX . "dolizsynch_price_schedule_rule";
	$sqlRule .= " WHERE entity = " . ((int) $conf->entity);
	$sqlRule .= " AND rowid = " . ((int) $ruleId);
	$sqlRule .= " LIMIT 1";
	$resqlRule = $db->query($sqlRule);
	if (!$resqlRule || $db->num_rows($resqlRule) === 0) {
		if ($resqlRule) $db->free($resqlRule);
		setEventMessages($langs->trans("PriceScheduleRuleNotFound"), null, 'errors');
		header("Location: " . $_SERVER['PHP_SELF']);
		exit;
	}
	$objRule = $db->fetch_object($resqlRule);
	$db->free($resqlRule);

	if ((int) $objRule->is_active === 1) {
		$sqlCancel = "UPDATE " . MAIN_DB_PREFIX . "dolizsynch_price_schedule_rule";
		$sqlCancel .= " SET status = " . ZSProductSynch::PRICE_SCHEDULE_RULE_CANCELED . ",";
		$sqlCancel .= " date_processed = '" . $db->idate(dol_now()) . "',";
		$sqlCancel .= " fk_user_modif = " . ((int) $user->id);
		$sqlCancel .= " WHERE entity = " . ((int) $conf->entity);
		$sqlCancel .= " AND rowid = " . ((int) $ruleId);
		$sqlCancel .= " LIMIT 1";
		if (!$db->query($sqlCancel)) {
			setEventMessages($langs->trans("PriceScheduleRuleDeleteFailed") . ' ' . $db->lasterror(), null, 'errors');
			header("Location: " . $_SERVER['PHP_SELF']);
			exit;
		}

		$scheduledSync = new ZSProductSynch($db);
		$scheduledSync->processScheduledPriceUpdates(500);

		$sqlCheck = "SELECT is_active FROM " . MAIN_DB_PREFIX . "dolizsynch_price_schedule_rule";
		$sqlCheck .= " WHERE entity = " . ((int) $conf->entity);
		$sqlCheck .= " AND rowid = " . ((int) $ruleId);
		$sqlCheck .= " LIMIT 1";
		$resqlCheck = $db->query($sqlCheck);
		if ($resqlCheck && $db->num_rows($resqlCheck) > 0) {
			$objCheck = $db->fetch_object($resqlCheck);
			$db->free($resqlCheck);
			if ((int) $objCheck->is_active === 1) {
				setEventMessages($langs->trans("PriceScheduleRuleDeleteFailed"), null, 'errors');
				header("Location: " . $_SERVER['PHP_SELF']);
				exit;
			}
		}
	}

	$sqlDelete = "DELETE FROM " . MAIN_DB_PREFIX . "dolizsynch_price_schedule_rule";
	$sqlDelete .= " WHERE entity = " . ((int) $conf->entity);
	$sqlDelete .= " AND rowid = " . ((int) $ruleId);
	$sqlDelete .= " LIMIT 1";
	if ($db->query($sqlDelete)) {
		setEventMessages($langs->trans("PriceScheduleRuleDeleted"), null, 'mesgs');
	} else {
		setEventMessages($langs->trans("PriceScheduleRuleDeleteFailed") . ' ' . $db->lasterror(), null, 'errors');
	}

	header("Location: " . $_SERVER['PHP_SELF']);
	exit;
}

if (($action === 'disablerule' || $action === 'enablerule') && $canWrite) {
	dzs_require_token();

	$ids = dzs_parse_row_ids(GETPOST('ids', 'alphanohtml'));
	if (empty($ids)) {
		setEventMessages($langs->trans("PriceScheduleInvalidSelection"), null, 'errors');
		header("Location: " . $_SERVER['PHP_SELF']);
		exit;
	}

	if (!$priceRuleTableReady) {
		setEventMessages($langs->trans("PriceScheduleRuleTableUnavailable"), null, 'errors');
		header("Location: " . $_SERVER['PHP_SELF']);
		exit;
	}

	$idList = implode(',', array_map('intval', $ids));
	$sql = "UPDATE " . MAIN_DB_PREFIX . "dolizsynch_price_schedule_rule";
	if ($action === 'disablerule') {
		$sql .= " SET status = " . ZSProductSynch::PRICE_SCHEDULE_RULE_CANCELED;
		$sql .= ", date_processed = '" . $db->idate(dol_now()) . "'";
		$sql .= ", fk_user_modif = " . ((int) $user->id);
		$sql .= " WHERE entity = " . ((int) $conf->entity);
		$sql .= " AND status IN (" . ZSProductSynch::PRICE_SCHEDULE_RULE_ENABLED . "," . ZSProductSynch::PRICE_SCHEDULE_RULE_ERROR . ")";
		$sql .= " AND rowid IN (" . $idList . ")";
		if ($db->query($sql)) {
			$scheduledSync = new ZSProductSynch($db);
			$scheduledSync->processScheduledPriceUpdates(500);
			setEventMessages($langs->trans("PriceScheduleRulesDisabled"), null, 'mesgs');
		} else {
			setEventMessages($langs->trans("PriceScheduleRuleDisableFailed") . ' ' . $db->lasterror(), null, 'errors');
		}
	} else {
		$sql .= " SET status = " . ZSProductSynch::PRICE_SCHEDULE_RULE_ENABLED;
		$sql .= ", error_message = NULL";
		$sql .= ", date_processed = NULL";
		$sql .= ", fk_user_modif = " . ((int) $user->id);
		$sql .= " WHERE entity = " . ((int) $conf->entity);
		$sql .= " AND status IN (" . ZSProductSynch::PRICE_SCHEDULE_RULE_CANCELED . "," . ZSProductSynch::PRICE_SCHEDULE_RULE_ERROR . ")";
		$sql .= " AND rowid IN (" . $idList . ")";
		if ($db->query($sql)) {
			$scheduledSync = new ZSProductSynch($db);
			$scheduledSync->processScheduledPriceUpdates(500);
			setEventMessages($langs->trans("PriceScheduleRulesEnabled"), null, 'mesgs');
		} else {
			setEventMessages($langs->trans("PriceScheduleRuleEnableFailed") . ' ' . $db->lasterror(), null, 'errors');
		}
	}

	header("Location: " . $_SERVER['PHP_SELF']);
	exit;
}

// List filters
$limit = GETPOSTINT('limit');
if ($limit <= 0) $limit = !empty($conf->liste_limit) ? (int) $conf->liste_limit : 25;

$sortfield = GETPOST('sortfield', 'aZ09comma');
$sortorder = strtoupper(GETPOST('sortorder', 'aZ09comma'));
$allowedSortFields = array('product_ref', 'product_label', 'price_base_type', 'price_level', 'price_value', 'scheduled_at', 'status', 'date_processed', 'date_creation');
if (!in_array($sortfield, $allowedSortFields, true)) $sortfield = 'scheduled_at';
if ($sortorder !== 'ASC' && $sortorder !== 'DESC') $sortorder = 'DESC';

$statusFilter = GETPOST('status_filter', 'alpha');
$allowedStatusFilters = array('all', 'pending', 'done', 'error', 'canceled');
if (!in_array($statusFilter, $allowedStatusFilters, true)) $statusFilter = 'all';

$page = GETPOSTISSET('pageplusone') ? (GETPOSTINT('pageplusone') - 1) : GETPOSTINT('page');
if ($page < 0) $page = 0;
$offset = $limit * $page;

$sqlFrom = " FROM " . MAIN_DB_PREFIX . "dolizsynch_price_schedule s";
$sqlFrom .= " LEFT JOIN " . MAIN_DB_PREFIX . "product p ON p.rowid = s.fk_product";
$sqlWhere = " WHERE s.entity = " . ((int) $conf->entity);
$sqlWhere .= " AND (s.source IS NULL OR s.source <> 'manual_grid_draft')";
if ($statusFilter === 'pending') $sqlWhere .= " AND s.status = " . ZSProductSynch::PRICE_SCHEDULE_PENDING;
if ($statusFilter === 'done') $sqlWhere .= " AND s.status = " . ZSProductSynch::PRICE_SCHEDULE_DONE;
if ($statusFilter === 'error') $sqlWhere .= " AND s.status = " . ZSProductSynch::PRICE_SCHEDULE_ERROR;
if ($statusFilter === 'canceled') $sqlWhere .= " AND s.status = " . ZSProductSynch::PRICE_SCHEDULE_CANCELED;

$sqlCount = "SELECT COUNT(*) as nbtotalofrecords" . $sqlFrom . $sqlWhere;
$nbtotalofrecords = 0;
if (!getDolGlobalInt('MAIN_DISABLE_FULL_SCANLIST')) {
	$resqlCount = $db->query($sqlCount);
	if ($resqlCount) {
		$objCount = $db->fetch_object($resqlCount);
		$nbtotalofrecords = (int) ($objCount->nbtotalofrecords ?? 0);
		$db->free($resqlCount);
		if (($page * $limit) > $nbtotalofrecords) {
			$page = 0;
			$offset = 0;
		}
	}
}

$sqlSelect = "SELECT";
$sqlSelect .= " s.rowid as rowid,";
$sqlSelect .= " s.fk_product as fk_product,";
$sqlSelect .= " s.product_ref as product_ref,";
$sqlSelect .= " COALESCE(p.label,'') as product_label,";
$sqlSelect .= " s.price_base_type as price_base_type,";
$sqlSelect .= " s.price_level as price_level,";
$sqlSelect .= " s.price_value as price_value,";
$sqlSelect .= " s.scheduled_at as scheduled_at,";
$sqlSelect .= " s.status as status,";
$sqlSelect .= " s.date_processed as date_processed,";
$sqlSelect .= " s.error_message as error_message,";
$sqlSelect .= " s.date_creation as date_creation";

$sql = $sqlSelect . $sqlFrom . $sqlWhere;
if ($sortfield === 'product_ref') {
	$sql .= " ORDER BY s.product_ref " . $sortorder;
} elseif ($sortfield === 'product_label') {
	$sql .= " ORDER BY product_label " . $sortorder;
} elseif ($sortfield === 'price_base_type') {
	$sql .= " ORDER BY s.price_base_type " . $sortorder;
} elseif ($sortfield === 'price_level') {
	$sql .= " ORDER BY s.price_level " . $sortorder;
} elseif ($sortfield === 'price_value') {
	$sql .= " ORDER BY s.price_value " . $sortorder;
} elseif ($sortfield === 'status') {
	$sql .= " ORDER BY s.status " . $sortorder;
} elseif ($sortfield === 'date_processed') {
	$sql .= " ORDER BY s.date_processed " . $sortorder;
} elseif ($sortfield === 'date_creation') {
	$sql .= " ORDER BY s.date_creation " . $sortorder;
} else {
	$sql .= " ORDER BY s.scheduled_at " . $sortorder;
}
$sql .= $db->plimit($limit + 1, $offset);

$resql = $db->query($sql);
if (!$resql) {
	llxHeader('', $langs->trans("PriceScheduleTitle"));
	print '<div class="error">' . dol_escape_htmltag($db->lasterror()) . '</div>';
	llxFooter();
	$db->close();
	exit;
}

$num = $db->num_rows($resql);
$ruleRows = array();
if ($priceRuleTableReady) {
	$sqlRule = "SELECT r.rowid, r.fk_product, r.product_ref, COALESCE(p.label,'') as product_label,";
	$sqlRule .= " r.price_base_type, r.price_level, r.promo_price_value, r.rule_type,";
	$sqlRule .= " r.start_at, r.end_at, r.weekly_day, r.weekly_start_time, r.weekly_end_time,";
	$sqlRule .= " r.status, r.is_active, r.error_message, r.date_processed, r.date_creation";
	$sqlRule .= " FROM " . MAIN_DB_PREFIX . "dolizsynch_price_schedule_rule r";
	$sqlRule .= " LEFT JOIN " . MAIN_DB_PREFIX . "product p ON p.rowid = r.fk_product";
	$sqlRule .= " WHERE r.entity = " . ((int) $conf->entity);
	$sqlRule .= " ORDER BY r.date_creation DESC, r.rowid DESC";
	$sqlRule .= $db->plimit(500, 0);

	$resqlRule = $db->query($sqlRule);
	if ($resqlRule) {
		while ($objRule = $db->fetch_object($resqlRule)) {
			$ruleRows[] = $objRule;
		}
		$db->free($resqlRule);
	}
}
$param = '';
if ($statusFilter !== 'all') $param .= '&status_filter=' . urlencode($statusFilter);

$title = $langs->trans("PriceScheduleTitle");
llxHeader('', $title);
print load_fiche_titre($title, '', 'generic');
print '<div class="opacitymedium" style="margin-bottom:6px;">' . $langs->trans("PriceScheduleGridHelp") . '</div>';
print '<style nonce="' . getNonce() . '">';
print '#dzs-editor-form{margin-bottom:0 !important;}';
print '#dzs-editor-form .dzs-editor-table-wrap{min-height:0 !important; margin-bottom:0 !important; padding-bottom:0 !important;}';
print '#id-right .div-table-responsive > table.tagtable.liste{margin-bottom:0 !important;}';
print '#id-right .table-fiche-title{margin-top:16px !important; margin-bottom:8px !important;}';
print '#id-right .fiche > table.table-fiche-title:first-of-type{margin-top:0 !important;}';
print '#id-right a.button.dzs-btn-compact{padding:3px 8px !important; font-size:0.86em !important; line-height:1.2 !important;}';
print '</style>';

if ($canWrite) {
	$predefinedPlaceholder = $langs->trans("PredefinedProductsAndServices");
	print '<form id="dzs-editor-form" method="POST" action="' . dol_escape_htmltag($_SERVER['PHP_SELF']) . '">';
	print '<input type="hidden" name="token" value="' . newToken() . '">';
	print '<input type="hidden" name="action" value="addrows">';

	print '<div class="div-table-responsive dzs-editor-table-wrap">';
	print '<table class="tagtable nobottomiftotal liste centpercent">';
	print '<tr class="liste_titre">';
	print '<th>' . $langs->trans("Ref") . '</th>';
	print '<th>' . $langs->trans("PriceScheduleBasePrice") . '</th>';
	for ($level = 1; $level <= $maxPriceLevels; $level++) {
		print '<th class="center">P' . $level . '</th>';
	}
	print '<th>' . $langs->trans("PriceScheduleRecurrence") . '</th>';
	print '<th>' . $langs->trans("PriceScheduleEffectiveAt") . '</th>';
	print '<th>' . $langs->trans("PriceScheduleEndAt") . '</th>';
	print '<th class="center">' . $langs->trans("Status") . '</th>';
	print '<th class="right">' . $langs->trans("PriceScheduleEvents") . '</th>';
	print '</tr>';

	foreach ($editorRows as $idx => $row) {
		$productId = (int) $row['product_id'];
		$baseType = strtoupper(trim((string) $row['price_base_type']));
		if ($baseType !== 'HT' && $baseType !== 'TTC') $baseType = 'TTC';
		$scheduledAt = trim((string) $row['scheduled_at']);
		if ($scheduledAt === '') $scheduledAt = $defaultEditorDatetime;
		$scheduledEndAt = trim((string) ($row['scheduled_end_at'] ?? ''));
		$recurrenceType = dzs_normalize_recurrence_type((string) ($row['recurrence_type'] ?? 'once'));
		$editRuleRowId = (int) ($row['edit_rule_rowid'] ?? 0);
		if ($editRuleRowId < 0) $editRuleRowId = 0;

		$isReady = ($productId > 0 && $scheduledAt !== '');
		if ($isReady) {
			$hasPrice = false;
			for ($level = 1; $level <= $maxPriceLevels; $level++) {
				$tmpVal = isset($row['prices'][$level]) ? trim((string) $row['prices'][$level]) : '';
				if ($tmpVal === '') continue;
				$parsedTmp = dzs_parse_price_value($tmpVal);
				if ($parsedTmp !== null && $parsedTmp >= 0) {
					$hasPrice = true;
					break;
				}
			}
			$isReady = $hasPrice;
		}
		if ($isReady && ($recurrenceType !== 'once' || $scheduledEndAt !== '')) {
			$isReady = false;
			if ($scheduledEndAt !== '') {
				$startTsCheck = strtotime(str_replace('T', ' ', $scheduledAt));
				$endTsCheck = strtotime(str_replace('T', ' ', $scheduledEndAt));
				if (!empty($startTsCheck) && !empty($endTsCheck) && $endTsCheck > $startTsCheck) {
					$isReady = true;
				}
			}
		}

		print '<tr class="' . (($idx % 2 === 0) ? 'oddeven' : 'oddevenpair') . '" data-row="' . ((int) $idx) . '">';
		print '<td>';
		print '<input type="hidden" name="edit_rule_rowid[' . $idx . ']" value="' . ((int) $editRuleRowId) . '">';
		print $form->select_produits($productId, 'product_select_' . $idx, '', getDolGlobalInt('PRODUIT_LIMIT_SIZE'), 0, 1, 2, '', 1, array(), 0, $predefinedPlaceholder, 0, 'maxwidth500 widthcentpercentminusx');
		print '</td>';
		print '<td>';
		print '<select id="price_base_type_' . $idx . '" name="price_base_type[' . $idx . ']" class="flat minwidth75 dzs-base-type" data-row="' . ((int) $idx) . '">';
		print '<option value="TTC"' . ($baseType === 'TTC' ? ' selected' : '') . '>C/IVA</option>';
		print '<option value="HT"' . ($baseType === 'HT' ? ' selected' : '') . '>S/IVA</option>';
		print '</select>';
		print '</td>';

		for ($level = 1; $level <= $maxPriceLevels; $level++) {
			$priceRaw = isset($row['prices'][$level]) ? (string) $row['prices'][$level] : '';
			$parsedForDisplay = dzs_parse_price_value($priceRaw);
			$priceVal = ($parsedForDisplay !== null) ? dzs_price_value_for_input($parsedForDisplay) : $priceRaw;
			print '<td><input type="text" class="flat maxwidth75 right dzs-price-input" data-row="' . ((int) $idx) . '" data-level="' . ((int) $level) . '" name="price_l' . $level . '[' . $idx . ']" value="' . dol_escape_htmltag($priceVal) . '" placeholder="0.00"></td>';
		}

		print '<td>';
		print '<select class="flat minwidth120" name="recurrence_type[' . $idx . ']">';
		print '<option value="once"' . ($recurrenceType === 'once' ? ' selected' : '') . '>' . dol_escape_htmltag($langs->trans("PriceScheduleRecurrenceOnce")) . '</option>';
		print '<option value="daily"' . ($recurrenceType === 'daily' ? ' selected' : '') . '>' . dol_escape_htmltag($langs->trans("PriceScheduleRecurrenceDaily")) . '</option>';
		print '<option value="weekly"' . ($recurrenceType === 'weekly' ? ' selected' : '') . '>' . dol_escape_htmltag($langs->trans("PriceScheduleRecurrenceWeekly")) . '</option>';
		print '<option value="monthly"' . ($recurrenceType === 'monthly' ? ' selected' : '') . '>' . dol_escape_htmltag($langs->trans("PriceScheduleRecurrenceMonthly")) . '</option>';
		print '<option value="yearly"' . ($recurrenceType === 'yearly' ? ' selected' : '') . '>' . dol_escape_htmltag($langs->trans("PriceScheduleRecurrenceYearly")) . '</option>';
		print '</select>';
		print '</td>';
		print '<td><input type="datetime-local" class="flat minwidth200" name="scheduled_at[' . $idx . ']" value="' . dol_escape_htmltag($scheduledAt) . '"></td>';
		print '<td><input type="datetime-local" class="flat minwidth200" name="scheduled_end_at[' . $idx . ']" value="' . dol_escape_htmltag($scheduledEndAt) . '"></td>';
		print '<td class="center nowrap"><span class="badge ' . ($isReady ? 'badge-status6' : 'badge-status8') . '">' . ($isReady ? 'Pronta' : 'Incompleta') . '</span></td>';
		print '<td style="white-space: nowrap; text-align: right;">';
		if ($editRuleRowId > 0) {
			print '<span class="opacitymedium small" style="margin-right:6px;">#' . ((int) $editRuleRowId) . '</span>';
		}
		print '<button type="submit" name="schedule_row" value="' . $idx . '" title="Agendar linha" style="margin-right: 7px; background: transparent; border: 0; padding: 0; cursor: pointer;">';
		print '<span class="fas fa-clock" style="color: #2f7ad0;"></span>';
		print '</button>';
		print '<button type="submit" name="editor_add_row" value="' . $idx . '" title="Adicionar linha" style="margin-right: 5px; background: transparent; border: 0; padding: 0; cursor: pointer;">';
		print '<span class="fas fa-plus" style="color: #28a745;"></span>';
		print '</button>';
		print '<button type="submit" name="editor_del_row" value="' . $idx . '" title="Eliminar linha" style="background: transparent; border: 0; padding: 0; cursor: pointer;">';
		print '<span class="fas fa-minus" style="color: #dc3545;"></span>';
		print '</button>';
		print '</td>';
		print '</tr>';
	}

	print '</table>';
	print '</div>';
	print '</form>';

	print '<script nonce="' . getNonce() . '">';
	print 'jQuery(function($){';
	print 'var endpoint = ' . json_encode($_SERVER['PHP_SELF'] . '?action=getproductprices') . ';';
	print 'var draftEndpoint = ' . json_encode($_SERVER['PHP_SELF'] . '?action=savedraft') . ';';
	print 'function formatPriceValue(value){';
	print 'if (value === null || typeof value === "undefined") return "";';
	print 'var raw = ("" + value).trim();';
	print 'if (raw === "") return "";';
	print 'raw = raw.replace(/\\s+/g, "");';
	print 'if (raw.indexOf(",") !== -1 && raw.indexOf(".") !== -1) {';
	print 'if (raw.lastIndexOf(",") > raw.lastIndexOf(".")) { raw = raw.replace(/\\./g, ""); raw = raw.replace(/,/g, "."); }';
	print 'else { raw = raw.replace(/,/g, ""); }';
	print '} else { raw = raw.replace(/,/g, "."); }';
	print 'var num = Number(raw);';
	print 'if (!isFinite(num)) return value;';
	print 'var txt = num.toFixed(6).replace(/0+$/, "");';
	print 'if (txt.slice(-1) === ".") txt += "00";';
	print 'else if (txt.indexOf(".") === -1) txt += ".00";';
	print 'else { var dec = txt.length - txt.indexOf(".") - 1; if (dec === 0) txt += "00"; else if (dec === 1) txt += "0"; }';
	print 'if (txt === "-0.00" || txt === "-0") txt = "0.00";';
	print 'return txt;';
	print '}';
	print 'function normalizePriceInput($input){';
	print 'if (!$input || !$input.length) return;';
	print 'var current = $input.val();';
	print 'var normalized = formatPriceValue(current);';
	print 'if (normalized !== current) $input.val(normalized);';
	print '}';
	print 'function saveDraftAsync(){';
	print 'var editor = $("#dzs-editor-form");';
	print 'if (!editor.length) return;';
	print 'var payload = editor.serializeArray();';
	print 'payload.push({name: "action", value: "savedraft"});';
	print '$.ajax({url: draftEndpoint, type: "POST", data: payload});';
	print '}';
	print 'function applyPrices(rowIndex, prices){';
	print 'if (!prices) return;';
	print '$.each(prices, function(level, value){';
	print 'var selector = "input[name=\'price_l"+level+"["+rowIndex+"]\']";';
	print 'var $input = $(selector);';
	print '$input.val(formatPriceValue((value === null || typeof value === "undefined") ? "" : value));';
	print '});';
	print '}';
	print 'function refreshRowPrices(rowIndex){';
	print 'var productId = parseInt($("#product_select_" + rowIndex).val(), 10) || 0;';
	print 'if (productId <= 0) return;';
	print 'var baseType = ($("#price_base_type_" + rowIndex).val() || "TTC").toUpperCase();';
	print '$.getJSON(endpoint, { product_id: productId, base_type: baseType }).done(function(resp){';
	print 'if (resp && resp.ok) {';
	print 'applyPrices(rowIndex, resp.prices);';
	print 'saveDraftAsync();';
	print '}';
	print '});';
	print '}';
	print '$(document).on("change", "[id^=\'product_select_\']", function(){';
	print 'var rowIndex = $(this).attr("id").replace("product_select_", "");';
	print 'refreshRowPrices(rowIndex);';
	print '});';
	print '$(document).on("change", ".dzs-base-type", function(){';
	print 'refreshRowPrices($(this).data("row"));';
	print '});';
	print '$(document).on("blur", ".dzs-price-input", function(){';
	print 'normalizePriceInput($(this));';
	print '});';
	print '$(document).on("change", "#dzs-editor-form input, #dzs-editor-form select", function(){';
	print 'saveDraftAsync();';
	print '});';
	print '});';
	print '</script>';
}

$tokenAction = newToken();
print load_fiche_titre($langs->trans("PriceScheduleRulesTitle"), '', '');
print '<div class="div-table-responsive">';
print '<table class="tagtable nobottomiftotal liste centpercent">';
print '<tr class="liste_titre">';
print '<td>' . $langs->trans("Ref") . '</td>';
print '<td>' . $langs->trans("Label") . '</td>';
print '<td>' . $langs->trans("PriceScheduleBasePrice") . '</td>';
print '<td class="center">' . $langs->trans("PriceLevel") . '</td>';
print '<td>' . $langs->trans("PriceSchedulePromoPrice") . '</td>';
print '<td>' . $langs->trans("PriceScheduleRecurrence") . '</td>';
print '<td>' . $langs->trans("PriceScheduleWindow") . '</td>';
print '<td>' . $langs->trans("Status") . '</td>';
print '<td>' . $langs->trans("PriceScheduleDateProcessed") . '</td>';
print '<td>' . $langs->trans("PriceScheduleError") . '</td>';
print '<td class="right">' . $langs->trans("PriceScheduleEvents") . '</td>';
print '</tr>';

$weekdayLabels = array(
	0 => $langs->trans("Sunday"),
	1 => $langs->trans("Monday"),
	2 => $langs->trans("Tuesday"),
	3 => $langs->trans("Wednesday"),
	4 => $langs->trans("Thursday"),
	5 => $langs->trans("Friday"),
	6 => $langs->trans("Saturday")
);
$r = 0;
foreach ($ruleRows as $objRule) {
	$rowCss = ($r % 2 === 0) ? 'oddeven' : 'oddevenpair';
	$statusCode = (int) $objRule->status;
	$isActiveRule = (int) $objRule->is_active;
	$statusLabel = dzs_rule_status_label($statusCode, $isActiveRule, $langs);
	$statusClass = dzs_rule_status_class($statusCode, $isActiveRule);
	$errorMsg = trim((string) $objRule->error_message);
	$dateProcessed = !empty($objRule->date_processed) ? dol_print_date($db->jdate($objRule->date_processed), 'dayhour') : '';
	$refHtml = dol_escape_htmltag($objRule->product_ref);
	if (!empty($objRule->fk_product)) {
		$refHtml = '<a href="' . dol_escape_htmltag(DOL_URL_ROOT . '/product/card.php?id=' . ((int) $objRule->fk_product)) . '">' . $refHtml . '</a>';
	}

	$ruleType = trim((string) $objRule->rule_type);
	$ruleTypeLabel = $langs->trans("PriceScheduleRecurrenceOnce");
	if ($ruleType === ZSProductSynch::PRICE_SCHEDULE_RULE_TYPE_DAILY_WINDOW) $ruleTypeLabel = $langs->trans("PriceScheduleRecurrenceDaily");
	if ($ruleType === ZSProductSynch::PRICE_SCHEDULE_RULE_TYPE_WEEKLY_WINDOW) $ruleTypeLabel = $langs->trans("PriceScheduleRecurrenceWeekly");
	if ($ruleType === ZSProductSynch::PRICE_SCHEDULE_RULE_TYPE_MONTHLY_WINDOW) $ruleTypeLabel = $langs->trans("PriceScheduleRecurrenceMonthly");
	if ($ruleType === ZSProductSynch::PRICE_SCHEDULE_RULE_TYPE_YEARLY_WINDOW) $ruleTypeLabel = $langs->trans("PriceScheduleRecurrenceYearly");
	$windowLabel = '';
	if ($ruleType === ZSProductSynch::PRICE_SCHEDULE_RULE_TYPE_WEEKLY_WINDOW) {
		$day = isset($weekdayLabels[(int) $objRule->weekly_day]) ? $weekdayLabels[(int) $objRule->weekly_day] : ('#' . ((int) $objRule->weekly_day));
		$windowLabel = $day . ' ' . dol_escape_htmltag((string) $objRule->weekly_start_time) . ' - ' . dol_escape_htmltag((string) $objRule->weekly_end_time);
	} elseif ($ruleType === ZSProductSynch::PRICE_SCHEDULE_RULE_TYPE_DAILY_WINDOW) {
		$startClock = !empty($objRule->start_at) ? date('H:i', $db->jdate($objRule->start_at)) : '';
		$endClock = !empty($objRule->end_at) ? date('H:i', $db->jdate($objRule->end_at)) : '';
		$windowLabel = $startClock . ' - ' . $endClock;
	} elseif ($ruleType === ZSProductSynch::PRICE_SCHEDULE_RULE_TYPE_MONTHLY_WINDOW) {
		$startTs = !empty($objRule->start_at) ? $db->jdate($objRule->start_at) : 0;
		$endTs = !empty($objRule->end_at) ? $db->jdate($objRule->end_at) : 0;
		if (!empty($startTs) && !empty($endTs)) {
			$windowLabel = $langs->trans("Day") . ' ' . date('d H:i', $startTs) . ' - ' . date('d H:i', $endTs);
		}
	} elseif ($ruleType === ZSProductSynch::PRICE_SCHEDULE_RULE_TYPE_YEARLY_WINDOW) {
		$startTs = !empty($objRule->start_at) ? $db->jdate($objRule->start_at) : 0;
		$endTs = !empty($objRule->end_at) ? $db->jdate($objRule->end_at) : 0;
		if (!empty($startTs) && !empty($endTs)) {
			$windowLabel = date('d/m H:i', $startTs) . ' - ' . date('d/m H:i', $endTs);
		}
	} else {
		$startLabel = !empty($objRule->start_at) ? dol_print_date($db->jdate($objRule->start_at), 'dayhour') : '';
		$endLabel = !empty($objRule->end_at) ? dol_print_date($db->jdate($objRule->end_at), 'dayhour') : '';
		$windowLabel = trim($startLabel . ' - ' . $endLabel);
	}

	print '<tr class="' . $rowCss . '">';
	print '<td>' . $refHtml . '</td>';
	print '<td>' . dol_escape_htmltag($objRule->product_label) . '</td>';
	print '<td class="nowrap">' . dol_escape_htmltag(dzs_base_type_label($objRule->price_base_type)) . '</td>';
	print '<td class="center nowrap">' . ((int) $objRule->price_level) . '</td>';
	print '<td class="right nowrap">' . price((float) $objRule->promo_price_value, 0, $langs, 1, 2, 2) . '</td>';
	print '<td class="nowrap">' . dol_escape_htmltag($ruleTypeLabel) . '</td>';
	print '<td class="nowrap">' . $windowLabel . '</td>';
	print '<td class="nowrap"><span class="badge ' . $statusClass . '">' . dol_escape_htmltag($statusLabel) . '</span></td>';
	print '<td class="nowrap">' . dol_escape_htmltag($dateProcessed) . '</td>';
	if ($errorMsg !== '') {
		print '<td title="' . dol_escape_htmltag($errorMsg) . '">' . dol_escape_htmltag(dol_trunc($errorMsg, 120)) . '</td>';
	} else {
		print '<td></td>';
	}

	print '<td class="right nowrap">';
	$ruleId = (int) $objRule->rowid;
	if ($canWrite && $ruleId > 0) {
		$deleteUrl = $_SERVER['PHP_SELF'] . '?action=deleterule&id=' . urlencode((string) $ruleId) . '&token=' . urlencode($tokenAction);
		print '<a class="reposition" href="' . dol_escape_htmltag($deleteUrl) . '" title="' . dol_escape_htmltag($langs->trans("Delete")) . '">';
		print '<span class="fas fa-trash marginleftonly" style="color: #444;" title="' . dol_escape_htmltag($langs->trans("Delete")) . '"></span>';
		print '</a>';
		if ($statusCode === ZSProductSynch::PRICE_SCHEDULE_RULE_ENABLED || $statusCode === ZSProductSynch::PRICE_SCHEDULE_RULE_ERROR) {
			$disableUrl = $_SERVER['PHP_SELF'] . '?action=disablerule&ids=' . urlencode((string) $ruleId) . '&token=' . urlencode($tokenAction);
			print ' &nbsp;<a class="reposition" href="' . dol_escape_htmltag($disableUrl) . '" title="' . dol_escape_htmltag($langs->trans("Disable")) . '">';
			print '<span class="fas fa-pause marginleftonly" style="color: #444;" title="' . dol_escape_htmltag($langs->trans("Disable")) . '"></span>';
			print '</a>';
		}
		if ($statusCode === ZSProductSynch::PRICE_SCHEDULE_RULE_CANCELED || $statusCode === ZSProductSynch::PRICE_SCHEDULE_RULE_ERROR) {
			$enableUrl = $_SERVER['PHP_SELF'] . '?action=enablerule&ids=' . urlencode((string) $ruleId) . '&token=' . urlencode($tokenAction);
			print ' &nbsp;<a class="reposition" href="' . dol_escape_htmltag($enableUrl) . '" title="' . dol_escape_htmltag($langs->trans("Enable")) . '">';
			print '<span class="fas fa-play marginleftonly" style="color: #444;" title="' . dol_escape_htmltag($langs->trans("Enable")) . '"></span>';
			print '</a>';
		}
	}
	print '</td>';
	print '</tr>';
	$r++;
}

if ($r === 0) {
	print '<tr class="oddeven"><td colspan="11" class="opacitymedium">' . $langs->trans("NoRecordFound") . '</td></tr>';
}

print '</table>';
print '</div>';

$listTitle = $langs->trans("PriceScheduleQueuedRows");

print_barre_liste($listTitle, $page, $_SERVER['PHP_SELF'], $param, $sortfield, $sortorder, '', $num, $nbtotalofrecords, '', 0, '', '', $limit);

print '<div class="div-table-responsive">';
print '<table class="tagtable nobottomiftotal liste centpercent">';
print '<tr class="liste_titre">';
print_liste_field_titre($langs->trans("Ref"), $_SERVER['PHP_SELF'], "product_ref", $param, '', '', $sortfield, $sortorder);
print_liste_field_titre($langs->trans("Label"), $_SERVER['PHP_SELF'], "product_label", $param, '', '', $sortfield, $sortorder);
print_liste_field_titre($langs->trans("PriceScheduleBasePrice"), $_SERVER['PHP_SELF'], "price_base_type", $param, '', '', $sortfield, $sortorder);
print_liste_field_titre($langs->trans("PriceLevel"), $_SERVER['PHP_SELF'], "price_level", $param, 'class="center"', '', $sortfield, $sortorder);
print_liste_field_titre($langs->trans("Price"), $_SERVER['PHP_SELF'], "price_value", $param, '', '', $sortfield, $sortorder);
print_liste_field_titre($langs->trans("PriceScheduleEffectiveAt"), $_SERVER['PHP_SELF'], "scheduled_at", $param, '', '', $sortfield, $sortorder);
print_liste_field_titre($langs->trans("Status"), $_SERVER['PHP_SELF'], "status", $param, '', '', $sortfield, $sortorder);
print_liste_field_titre($langs->trans("PriceScheduleDateProcessed"), $_SERVER['PHP_SELF'], "date_processed", $param, '', '', $sortfield, $sortorder);
print_liste_field_titre($langs->trans("PriceScheduleError"), $_SERVER['PHP_SELF'], "", $param);
print_liste_field_titre($langs->trans("PriceScheduleEvents"), $_SERVER['PHP_SELF'], "", $param, 'class="right"');
print '</tr>';

$i = 0;
while ($obj = $db->fetch_object($resql)) {
	$rowCss = ($i % 2 === 0) ? 'oddeven' : 'oddevenpair';
	$statusCode = (int) $obj->status;
	$statusLabel = dzs_group_status_label($statusCode, $langs);
	$statusClass = dzs_group_status_class($statusCode);
	$refHtml = dol_escape_htmltag($obj->product_ref);
	if (!empty($obj->fk_product)) {
		$refHtml = '<a href="' . dol_escape_htmltag(DOL_URL_ROOT . '/product/card.php?id=' . ((int) $obj->fk_product)) . '">' . $refHtml . '</a>';
	}
	$dateProcessed = !empty($obj->date_processed) ? dol_print_date($db->jdate($obj->date_processed), 'dayhour') : '';
	$errorMsg = trim((string) $obj->error_message);

	print '<tr class="' . $rowCss . '">';
	print '<td>' . $refHtml . '</td>';
	print '<td>' . dol_escape_htmltag($obj->product_label) . '</td>';
	print '<td class="nowrap">' . dol_escape_htmltag(dzs_base_type_label($obj->price_base_type)) . '</td>';
	print '<td class="center nowrap">' . ((int) $obj->price_level) . '</td>';
	print '<td class="right nowrap">' . price((float) $obj->price_value, 0, $langs, 1, 2, 2) . '</td>';
	print '<td class="nowrap">' . dol_print_date($db->jdate($obj->scheduled_at), 'dayhour') . '</td>';
	print '<td class="nowrap"><span class="badge ' . $statusClass . '">' . dol_escape_htmltag($statusLabel) . '</span></td>';
	print '<td class="nowrap">' . dol_escape_htmltag($dateProcessed) . '</td>';
	if ($errorMsg !== '') {
		print '<td title="' . dol_escape_htmltag($errorMsg) . '">' . dol_escape_htmltag(dol_trunc($errorMsg, 120)) . '</td>';
	} else {
		print '<td></td>';
	}

	print '<td class="right nowrap">';
	$rowId = (int) $obj->rowid;
	if ($canWrite && $rowId > 0) {
		if ((int) $obj->status === ZSProductSynch::PRICE_SCHEDULE_PENDING) {
			$cancelUrl = $_SERVER['PHP_SELF'] . '?action=cancelgroup&ids=' . urlencode((string) $rowId) . '&token=' . urlencode($tokenAction);
			print '<a class="button button-delete dzs-btn-compact" href="' . dol_escape_htmltag($cancelUrl) . '">' . dol_escape_htmltag($langs->trans("Cancel")) . '</a> ';
		}
		if ((int) $obj->status === ZSProductSynch::PRICE_SCHEDULE_ERROR) {
			$retryUrl = $_SERVER['PHP_SELF'] . '?action=retrygroup&ids=' . urlencode((string) $rowId) . '&token=' . urlencode($tokenAction);
			print '<a class="button" href="' . dol_escape_htmltag($retryUrl) . '">' . dol_escape_htmltag($langs->trans("Retry")) . '</a>';
		}
	}
	print '</td>';
	print '</tr>';
	$i++;
}

if ($i === 0) {
	print '<tr class="oddeven"><td colspan="10" class="opacitymedium">' . $langs->trans("NoRecordFound") . '</td></tr>';
}

print '</table>';
print '</div>';

$db->free($resql);

llxFooter();
$db->close();

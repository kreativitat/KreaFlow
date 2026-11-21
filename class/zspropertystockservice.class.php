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
 * \file       class/zspropertystockservice.class.php
 * \ingroup    dolizsynch
 * \brief      Property-driven exact-unit stock conversion service.
 */

require_once __DIR__ . '/zsdecimal.class.php';

/**
 * Persist property stock rules and reconcile automatic dismantling output.
 */
class ZSPropertyStockService
{
	/** @var DoliDB */
	private $db;

	/** @var string */
	public $error = '';

	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * Fetch the effective rule for a destination product.
	 *
	 * Rules linked to a BOM owned by entity 0 are shared. A legacy entity-owned
	 * row for such a BOM remains readable from every entity until the next save
	 * canonicalizes it as an entity-0 rule.
	 *
	 * @param int $productId Destination product ID
	 * @param int $entity Entity ID
	 * @param bool $activeOnly Return only active rules
	 * @return array|null Rule or null
	 */
	public function fetchRule($productId, $entity, $activeOnly = false)
	{
		$this->error = '';
		$productId = (int) $productId;
		$entity = (int) $entity;
		if ($productId <= 0 || $entity <= 0) {
			return null;
		}

		$sql = 'SELECT stock_rule.rowid, stock_rule.entity, stock_rule.fk_product,';
		$sql .= ' stock_rule.property_id, stock_rule.property_name, stock_rule.units_per_package,';
		$sql .= ' stock_rule.fk_bom, stock_rule.fk_bom_line, stock_rule.fk_product_output, stock_rule.active,';
		$sql .= ' bom.entity AS bom_entity';
		$sql .= ' FROM ' . MAIN_DB_PREFIX . 'dolizsynch_property_stock_rule stock_rule';
		$sql .= ' INNER JOIN ' . MAIN_DB_PREFIX . 'bom_bom bom ON bom.rowid=stock_rule.fk_bom';
		$sql .= ' WHERE stock_rule.fk_product=' . $productId;
		$sql .= ' AND (stock_rule.entity IN (0, ' . $entity . ') OR bom.entity=0)';
		if ($activeOnly) {
			$sql .= ' AND stock_rule.active=1';
		}
		$sql .= ' ORDER BY stock_rule.active DESC, stock_rule.rowid ASC';
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = 'Unable to read property stock rules: ' . $this->db->lasterror();
			dol_syslog(__METHOD__ . ': ' . $this->error, LOG_ERR);
			return null;
		}
		$rows = array();
		while ($row = $this->db->fetch_object($resql)) {
			$rows[] = $row;
		}
		$this->db->free($resql);
		if (empty($rows)) {
			return null;
		}

		$row = null;
		foreach ($rows as $candidate) {
			if ((int) $candidate->active === 1 && (int) $candidate->entity === $entity) {
				$row = $candidate;
				break;
			}
		}
		if ($row === null) {
			foreach ($rows as $candidate) {
				if ((int) $candidate->active === 1 && (int) $candidate->entity === 0) {
					$row = $candidate;
					break;
				}
			}
		}
		if ($row === null) {
			$sharedSignature = '';
			foreach ($rows as $candidate) {
				if ((int) $candidate->active !== 1 || (int) $candidate->bom_entity !== 0) {
					continue;
				}
				$signature = implode(':', array(
					(int) $candidate->fk_bom,
					(int) $candidate->fk_bom_line,
					(int) $candidate->fk_product_output,
				));
				if ($sharedSignature !== '' && $sharedSignature !== $signature) {
					$this->error = 'Conflicting entity-owned rules exist for the same shared dismantling BOM product.';
					return null;
				}
				$sharedSignature = $signature;
				if ($row === null) {
					$row = $candidate;
				}
			}
		}
		if ($row === null && !$activeOnly) {
			foreach ($rows as $candidate) {
				if ((int) $candidate->entity === $entity || (int) $candidate->entity === 0) {
					$row = $candidate;
					break;
				}
			}
		}
		if ($row === null) {
			return null;
		}

		$rule = array(
			'id' => (int) $row->rowid,
			'entity' => (int) $row->entity,
			'fk_product' => (int) $row->fk_product,
			'property_id' => (int) $row->property_id,
			'property_name' => (string) $row->property_name,
			'units_per_package' => ZSDecimal::normalize((string) $row->units_per_package),
			'fk_bom' => (int) $row->fk_bom,
			'fk_bom_line' => (int) $row->fk_bom_line,
			'fk_product_output' => (int) $row->fk_product_output,
			'active' => (int) $row->active,
			'bom_entity' => (int) $row->bom_entity,
			'shared' => (int) $row->bom_entity === 0 ? 1 : 0,
		);
		if ($activeOnly) {
			$selection = $this->resolveValidatedDismantlingSelection(
				$productId,
				(int) $rule['fk_bom'],
				$entity
			);
			if ($selection === null
				|| (int) $selection['fk_bom_line'] !== (int) $rule['fk_bom_line']
				|| (int) $selection['fk_product_output'] !== (int) $rule['fk_product_output']) {
				if ($this->error === '') {
					$this->error = 'The configured dismantling BOM output no longer matches the stock rule.';
				}
				return null;
			}
			$rule['units_per_package'] = (string) $selection['units_per_package'];
		}

		return $rule;
	}

	/**
	 * Save or disable a product rule using the selected BOM's ownership scope.
	 *
	 * @param int $productId Input product
	 * @param int $entity Entity ID
	 * @param int $propertyId ZSBMS property ID
	 * @param string $propertyName Trusted dictionary label
	 * @param int $bomId Validated dismantling BOM
	 * @param int $userId Acting user
	 * @return bool True on success
	 */
	public function saveRule($productId, $entity, $propertyId, $propertyName, $bomId, $userId)
	{
		$productId = (int) $productId;
		$entity = (int) $entity;
		$propertyId = (int) $propertyId;
		$bomId = (int) $bomId;
		$userId = (int) $userId;
		$propertyName = trim((string) $propertyName);

		if ($bomId <= 0) {
			$effectiveRule = $this->fetchRule($productId, $entity, false);
			if ($this->error !== '') {
				return false;
			}
			$sql = 'UPDATE ' . MAIN_DB_PREFIX . 'dolizsynch_property_stock_rule SET active=0,';
			$sql .= ' fk_user_modif=' . $userId;
			$sql .= ' WHERE fk_product=' . $productId;
			if (!empty($effectiveRule['shared'])) {
				$sql .= ' AND fk_bom=' . (int) $effectiveRule['fk_bom'];
			} else {
				$sql .= ' AND entity=' . $entity;
			}
			if (!$this->db->query($sql)) {
				$this->error = 'Unable to disable the property stock rule: ' . $this->db->lasterror();
				return false;
			}
			return true;
		}

		if ($productId <= 0 || $entity <= 0 || $propertyId <= 0 || $propertyName === '' || $bomId <= 0) {
			$this->error = 'The property stock rule is incomplete.';
			return false;
		}
		if (!$this->isVisibleProduct($productId, $entity)) {
			$this->error = 'The purchased product is not visible in the active entity.';
			return false;
		}
		$selection = $this->resolveValidatedDismantlingSelection($productId, $bomId, $entity);
		if ($selection === null) {
			return false;
		}
		$bomLineId = (int) $selection['fk_bom_line'];
		$outputProductId = (int) $selection['fk_product_output'];
		$unitsPerPackage = (string) $selection['units_per_package'];
		$ruleEntity = (int) $selection['bom_entity'] === 0 ? 0 : $entity;

		if (!$this->db->begin()) {
			$this->error = 'Unable to start the property stock rule transaction.';
			return false;
		}

		$sql = 'INSERT INTO ' . MAIN_DB_PREFIX . 'dolizsynch_property_stock_rule';
		$sql .= ' (entity, fk_product, property_id, property_name, units_per_package, fk_bom, fk_bom_line, fk_product_output,';
		$sql .= ' active, date_creation, fk_user_creat, fk_user_modif) VALUES (';
		$sql .= $ruleEntity . ', ' . $productId . ', ' . $propertyId . ", '" . $this->db->escape($propertyName) . "', ";
		$sql .= "'" . $this->db->escape($unitsPerPackage) . "', " . $bomId . ', ' . $bomLineId . ', ' . $outputProductId . ', 1, ';
		$sql .= "'" . $this->db->idate(dol_now()) . "', " . $userId . ', ' . $userId . ')';
		$sql .= ' ON DUPLICATE KEY UPDATE property_id=VALUES(property_id), property_name=VALUES(property_name),';
		$sql .= ' units_per_package=VALUES(units_per_package), fk_bom=VALUES(fk_bom),';
		$sql .= ' fk_bom_line=VALUES(fk_bom_line), fk_product_output=VALUES(fk_product_output),';
		$sql .= ' active=1, fk_user_modif=VALUES(fk_user_modif)';
		if (!$this->db->query($sql)) {
			$this->error = 'Unable to save the property stock rule: ' . $this->db->lasterror();
			$this->db->rollback();
			return false;
		}
		if ($ruleEntity === 0) {
			$sql = 'UPDATE ' . MAIN_DB_PREFIX . 'dolizsynch_property_stock_rule legacy_rule';
			$sql .= ' INNER JOIN ' . MAIN_DB_PREFIX . 'bom_bom legacy_bom ON legacy_bom.rowid=legacy_rule.fk_bom';
			$sql .= ' SET legacy_rule.active=0, legacy_rule.fk_user_modif=' . $userId;
			$sql .= ' WHERE legacy_rule.entity<>0 AND legacy_rule.fk_product=' . $productId;
			$sql .= ' AND legacy_rule.active=1 AND legacy_bom.entity=0';
			if (!$this->db->query($sql)) {
				$this->error = 'Unable to retire legacy entity-owned copies of the shared stock rule: ' . $this->db->lasterror();
				$this->db->rollback();
				return false;
			}
		}
		if (!$this->db->commit()) {
			$this->error = 'Unable to commit the property stock rule.';
			$this->db->rollback();
			return false;
		}

		return true;
	}

	/**
	 * Remove active configuration references when a Dolibarr product is deleted.
	 * Historical invoice plans remain immutable audit evidence.
	 *
	 * @param int $productId Deleted product ID
	 * @return bool True on success
	 */
	public function deleteRulesForProduct($productId)
	{
		$productId = (int) $productId;
		if ($productId <= 0) {
			return true;
		}
		$sql = 'DELETE FROM ' . MAIN_DB_PREFIX . 'dolizsynch_property_stock_rule';
		$sql .= ' WHERE fk_product=' . $productId . ' OR fk_product_output=' . $productId;
		if (!$this->db->query($sql)) {
			$this->error = 'Unable to remove property stock rules for the deleted product: ' . $this->db->lasterror();
			return false;
		}
		return true;
	}

	/**
	 * Fetch dismantling BOM candidates and explain why each one is or is not eligible.
	 *
	 * @param int $inputProductId Purchased product
	 * @param int $entity Active entity
	 * @return array<int,array<string,mixed>> BOMs keyed by row ID
	 */
	public function fetchDismantlingBomCandidates($inputProductId, $entity)
	{
		global $conf;

		$this->error = '';
		$inputProductId = (int) $inputProductId;
		$entity = (int) $entity;
		if ($inputProductId <= 0 || $entity <= 0 || (int) $conf->entity !== $entity) {
			return array();
		}

		$sql = 'SELECT b.rowid AS bom_id, b.entity AS bom_entity, b.ref AS bom_ref, b.label AS bom_label, b.status AS bom_status,';
		$sql .= ' bl.rowid AS bom_line_id, bl.fk_product AS output_product_id, bl.qty AS output_qty,';
		$sql .= ' p.ref AS output_product_ref, p.label AS output_product_label, p.fk_product_type AS output_product_type,';
		$sql .= ' CASE WHEN p.rowid IS NOT NULL AND p.entity IN (' . getEntity('product') . ') THEN 1 ELSE 0 END AS output_visible';
		$sql .= ' FROM ' . MAIN_DB_PREFIX . 'bom_bom b';
		$sql .= ' LEFT JOIN ' . MAIN_DB_PREFIX . 'bom_bomline bl ON bl.fk_bom=b.rowid';
		$sql .= ' LEFT JOIN ' . MAIN_DB_PREFIX . 'product p ON p.rowid=bl.fk_product';
		$sql .= ' WHERE b.fk_product=' . $inputProductId;
		$sql .= ' AND b.bomtype=1 AND b.entity IN (0, ' . getEntity('bom') . ')';
		$sql .= ' ORDER BY b.ref ASC, b.rowid ASC, bl.position ASC, bl.rowid ASC';
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = 'Unable to load dismantling BOM candidates: ' . $this->db->lasterror();
			return array();
		}

		$boms = array();
		while ($row = $this->db->fetch_object($resql)) {
			$bomId = (int) $row->bom_id;
			if ($bomId <= 0) {
				continue;
			}
			if (!isset($boms[$bomId])) {
				$boms[$bomId] = array(
					'id' => $bomId,
					'entity' => (int) $row->bom_entity,
					'ref' => trim((string) $row->bom_ref),
					'label' => trim((string) $row->bom_label),
					'status' => (int) $row->bom_status,
					'eligible' => false,
					'reason_key' => '',
					'reason_args' => array(),
					'lines' => array(),
					'outputs' => array(),
				);
			}
			$lineId = (int) $row->bom_line_id;
			if ($lineId <= 0) {
				continue;
			}
			$boms[$bomId]['lines'][$lineId] = array(
				'id' => $lineId,
				'fk_product' => (int) $row->output_product_id,
				'qty_raw' => (string) $row->output_qty,
				'product_ref' => trim((string) $row->output_product_ref),
				'product_label' => trim((string) $row->output_product_label),
				'product_type' => $row->output_product_type === null ? null : (int) $row->output_product_type,
				'visible' => !empty($row->output_visible),
			);
		}
		$this->db->free($resql);

		foreach ($boms as $bomId => &$bom) {
			if ((int) $bom['status'] !== 1) {
				$bom['reason_key'] = 'DoliZSynchPropertyStockBomReasonNotValidated';
				continue;
			}
			$lineCount = count($bom['lines']);
			if ($lineCount === 0) {
				$bom['reason_key'] = 'DoliZSynchPropertyStockBomReasonNoOutput';
				continue;
			}
			if ($lineCount !== 1) {
				$bom['reason_key'] = 'DoliZSynchPropertyStockBomReasonMultipleOutputs';
				$bom['reason_args'] = array($lineCount);
				continue;
			}

			$lineId = (int) array_key_first($bom['lines']);
			$line = $bom['lines'][$lineId];
			if ((int) $line['fk_product'] <= 0 || $line['product_type'] === null || (int) $line['product_type'] !== 0) {
				$bom['reason_key'] = 'DoliZSynchPropertyStockBomReasonInvalidOutput';
				continue;
			}
			if ((int) $line['fk_product'] === $inputProductId) {
				$bom['reason_key'] = 'DoliZSynchPropertyStockBomReasonSameProduct';
				continue;
			}
			if (empty($line['visible'])) {
				$bom['reason_key'] = 'DoliZSynchPropertyStockBomReasonInvisibleOutput';
				continue;
			}
			try {
				$outputQty = ZSDecimal::normalize((string) $line['qty_raw']);
			} catch (InvalidArgumentException $e) {
				$bom['reason_key'] = 'DoliZSynchPropertyStockBomReasonInvalidQuantity';
				continue;
			}
			if (ZSDecimal::compare($outputQty, '0') <= 0) {
				$bom['reason_key'] = 'DoliZSynchPropertyStockBomReasonNonPositiveQuantity';
				$bom['reason_args'] = array($outputQty);
				continue;
			}
			$bom['eligible'] = true;
			$bom['outputs'][$lineId] = array(
				'id' => $lineId,
				'fk_product' => (int) $line['fk_product'],
				'qty' => $outputQty,
				'product_ref' => (string) $line['product_ref'],
				'product_label' => (string) $line['product_label'],
			);
		}
		unset($bom);

		return $boms;
	}

	/**
	 * Fetch validated dismantling BOMs and their entity-visible output lines.
	 *
	 * @param int $inputProductId Purchased product
	 * @param int $entity Active entity
	 * @return array<int,array<string,mixed>> BOMs keyed by row ID
	 */
	public function fetchValidatedDismantlingBoms($inputProductId, $entity)
	{
		$candidates = $this->fetchDismantlingBomCandidates($inputProductId, $entity);
		return array_filter($candidates, static function ($bom) {
			return !empty($bom['eligible']);
		});
	}

	/**
	 * Build the exact stock plan for one source sale line.
	 *
	 * @param array $line ZoneSoft sale line
	 * @param array $movements Header movimentospropriedades rows
	 * @param array $rule Active destination rule
	 * @return array Exact plan
	 * @throws InvalidArgumentException On missing, ambiguous, or invalid source evidence
	 */
	public static function buildLinePlan(array $line, array $movements, array $rule)
	{
		$lineMovementId = (int) ($line['uid_propriedade'] ?? 0);
		if ($lineMovementId <= 0) {
			throw new InvalidArgumentException('The configured stock property is missing from the source invoice line.');
		}

		$matches = array();
		foreach ($movements as $movement) {
			if (is_object($movement)) {
				$movement = (array) $movement;
			}
			if (!is_array($movement)) {
				continue;
			}
			$movementId = (int) ($movement['id'] ?? ($movement['uid'] ?? 0));
			$propertyId = (int) ($movement['idpropriedade'] ?? ($movement['cod_propriedade'] ?? ($movement['propriedade'] ?? 0)));
			$movementName = self::normalizeName((string) ($movement['nome_propriedade'] ?? ($movement['nome'] ?? '')));
			$ruleName = self::normalizeName((string) ($rule['property_name'] ?? ''));
			$propertyMatches = $propertyId === (int) $rule['property_id'];
			if (!$propertyMatches && $movementName !== '' && $ruleName !== '') {
				$propertyMatches = $movementName === $ruleName;
			}
			if ($movementId === $lineMovementId && $propertyMatches) {
				$matches[] = $movement;
			}
		}

		if (count($matches) !== 1) {
			throw new InvalidArgumentException(count($matches) === 0
				? 'The configured stock property value was not found for the source invoice line.'
				: 'The source invoice line contains ambiguous stock property values.');
		}

		$value = $matches[0]['valor_propriedade'] ?? ($matches[0]['valor'] ?? ($matches[0]['value'] ?? ''));
		try {
			$packages = ZSDecimal::normalize((string) $value);
		} catch (InvalidArgumentException $e) {
			throw new InvalidArgumentException('The configured stock property contains an invalid numeric value.');
		}
		if (ZSDecimal::compare($packages, '0') <= 0 || !self::isWholeNumberStatic($packages)) {
			throw new InvalidArgumentException('Package count must be a positive whole number.');
		}
		if ((int) ($rule['fk_bom'] ?? 0) <= 0 || (int) ($rule['fk_bom_line'] ?? 0) <= 0
			|| (int) ($rule['fk_product_output'] ?? 0) <= 0) {
			throw new InvalidArgumentException('The property stock rule has no valid dismantling BOM output.');
		}
		try {
			$unitsPerPackage = ZSDecimal::normalize((string) ($rule['units_per_package'] ?? ''));
		} catch (InvalidArgumentException $e) {
			throw new InvalidArgumentException('The BOM output quantity per package is invalid.');
		}
		if (ZSDecimal::compare($unitsPerPackage, '0') <= 0) {
			throw new InvalidArgumentException('The BOM output quantity per package must be positive.');
		}
		$targetUnits = ZSDecimal::multiply($packages, $unitsPerPackage);
		$sourcePropertyName = trim((string) ($matches[0]['nome_propriedade'] ?? ($matches[0]['nome'] ?? '')));
		if ($sourcePropertyName === '') {
			$sourcePropertyName = (string) ($rule['property_name'] ?? '');
		}
		$sourcePropertyName = self::decodeName($sourcePropertyName);

		return array(
			'property_movement_id' => $lineMovementId,
			'property_id' => (int) $rule['property_id'],
			'property_name' => $sourcePropertyName,
			'package_count' => $packages,
			'units_per_package' => $unitsPerPackage,
			'target_units' => $targetUnits,
			'fk_product_input' => (int) $rule['fk_product'],
			'fk_bom' => (int) $rule['fk_bom'],
			'fk_bom_line' => (int) $rule['fk_bom_line'],
			'fk_product_output' => (int) $rule['fk_product_output'],
		);
	}

	/**
	 * Build immutable purchase-value evidence for one exact-stock invoice line.
	 *
	 * Stock value intentionally retains purchase-price precision instead of using
	 * the legally rounded invoice-line total. The latter is retained separately
	 * for audit and reconciliation display.
	 *
	 * @param string $purchaseQty Purchased quantity in the supplier unit
	 * @param string $unitPrice Unit purchase price excluding VAT
	 * @param string $discountPercent Line discount percentage
	 * @param string $lineTotalHt Legally rounded supplier-invoice line total
	 * @param string $targetUnits Exact destination units
	 * @return array<string,string> Normalized valuation evidence
	 * @throws InvalidArgumentException On invalid monetary evidence
	 */
	public static function buildValuationEvidence($purchaseQty, $unitPrice, $discountPercent, $lineTotalHt, $targetUnits)
	{
		$purchaseQty = ZSDecimal::normalize((string) $purchaseQty);
		$unitPrice = ZSDecimal::normalize((string) $unitPrice);
		$discountPercent = ZSDecimal::normalize((string) $discountPercent);
		$lineTotalHt = ZSDecimal::normalize((string) $lineTotalHt);
		$targetUnits = ZSDecimal::normalize((string) $targetUnits);
		if (ZSDecimal::compare($purchaseQty, '0') <= 0
			|| ZSDecimal::compare($unitPrice, '0') < 0
			|| ZSDecimal::compare($discountPercent, '0') < 0
			|| ZSDecimal::compare($discountPercent, '100') > 0
			|| ZSDecimal::compare($lineTotalHt, '0') < 0
			|| ZSDecimal::compare($targetUnits, '0') <= 0) {
			throw new InvalidArgumentException('Invalid supplier invoice valuation evidence.');
		}

		$sourceUnitCost = ZSDecimal::divideByPowerOfTen(
			ZSDecimal::multiply($unitPrice, ZSDecimal::subtract('100', $discountPercent)),
			2
		);
		$sourceStockValue = ZSDecimal::multiply($purchaseQty, $sourceUnitCost);
		$exactUnitCost = ZSDecimal::divide($sourceStockValue, $targetUnits, 8);

		return array(
			'source_purchase_qty' => $purchaseQty,
			'source_unit_cost' => $sourceUnitCost,
			'source_stock_value' => $sourceStockValue,
			'source_line_total_ht' => $lineTotalHt,
			'exact_unit_cost' => $exactUnitCost,
		);
	}

	/**
	 * Persist a plan after Dolibarr creates its supplier invoice line.
	 *
	 * @param int $entity Target entity
	 * @param int $invoiceId Supplier invoice ID
	 * @param int $invoiceLineId Supplier invoice line ID
	 * @param array $plan Exact plan
	 * @param int $userId Acting user
	 * @return bool True on success
	 */
	public function savePlan($entity, $invoiceId, $invoiceLineId, array $plan, $userId)
	{
		$entity = (int) $entity;
		$invoiceId = (int) $invoiceId;
		$invoiceLineId = (int) $invoiceLineId;
		$userId = (int) $userId;
		if ($entity <= 0 || $invoiceId <= 0 || $invoiceLineId <= 0
			|| (int) ($plan['fk_bom'] ?? 0) <= 0 || (int) ($plan['fk_bom_line'] ?? 0) <= 0
			|| (int) ($plan['fk_product_output'] ?? 0) <= 0) {
			$this->error = 'Invalid supplier invoice property stock plan context.';
			return false;
		}
		try {
			$packageCount = ZSDecimal::normalize((string) ($plan['package_count'] ?? ''));
			$unitsPerPackage = ZSDecimal::normalize((string) ($plan['units_per_package'] ?? ''));
			$targetUnits = ZSDecimal::normalize((string) ($plan['target_units'] ?? ''));
			if (ZSDecimal::compare($packageCount, '0') <= 0 || !self::isWholeNumberStatic($packageCount)
				|| ZSDecimal::compare($unitsPerPackage, '0') <= 0
				|| ZSDecimal::compare(ZSDecimal::multiply($packageCount, $unitsPerPackage), $targetUnits) !== 0) {
				$this->error = 'Exact stock quantity must equal package count multiplied by the BOM output quantity.';
				return false;
			}
		} catch (InvalidArgumentException $e) {
			$this->error = 'Invalid exact stock quantity evidence.';
			return false;
		}

		$sqlEvidence = 'SELECT invoice_line.fk_product, invoice_line.qty, invoice_line.pu_ht AS subprice,';
		$sqlEvidence .= ' COALESCE(invoice_line.remise_percent, 0) AS remise_percent,';
		$sqlEvidence .= ' COALESCE(invoice_line.total_ht, 0) AS total_ht';
		$sqlEvidence .= ' FROM ' . MAIN_DB_PREFIX . 'facture_fourn invoice';
		$sqlEvidence .= ' INNER JOIN ' . MAIN_DB_PREFIX . 'facture_fourn_det invoice_line';
		$sqlEvidence .= ' ON invoice_line.fk_facture_fourn=invoice.rowid';
		$sqlEvidence .= ' WHERE invoice.rowid=' . $invoiceId . ' AND invoice.entity=' . $entity;
		$sqlEvidence .= ' AND invoice_line.rowid=' . $invoiceLineId;
		$sqlEvidence .= ' ' . $this->db->plimit(1);
		$resEvidence = $this->db->query($sqlEvidence);
		$invoiceLine = $resEvidence ? $this->db->fetch_object($resEvidence) : null;
		if (!$resEvidence) {
			$this->error = 'Unable to load the destination supplier invoice valuation: ' . $this->db->lasterror();
			return false;
		}
		if (!$invoiceLine) {
			$this->error = 'Unable to bind exact stock valuation to the destination supplier invoice line.';
			return false;
		}
		if ((int) $invoiceLine->fk_product !== (int) $plan['fk_product_input']) {
			$this->error = 'The destination supplier invoice line product does not match the exact-stock input product.';
			return false;
		}
		try {
			$valuation = self::buildValuationEvidence(
				(string) $invoiceLine->qty,
				(string) $invoiceLine->subprice,
				(string) $invoiceLine->remise_percent,
				(string) $invoiceLine->total_ht,
				$targetUnits
			);
		} catch (InvalidArgumentException $e) {
			$this->error = $e->getMessage();
			return false;
		}

		$sql = 'INSERT INTO ' . MAIN_DB_PREFIX . 'dolizsynch_property_stock_plan';
		$sql .= ' (entity, fk_facture_fourn, fk_facture_fourn_det, property_movement_id, property_id,';
		$sql .= ' property_name, package_count, units_per_package, target_units, fk_product_input,';
		$sql .= ' fk_bom, fk_bom_line, fk_product_output, source_purchase_qty, source_unit_cost,';
		$sql .= ' source_stock_value, source_line_total_ht, exact_unit_cost, status, date_creation, fk_user_creat, fk_user_modif) VALUES (';
		$sql .= $entity . ', ' . $invoiceId . ', ' . $invoiceLineId . ', ' . (int) $plan['property_movement_id'] . ', ';
		$sql .= (int) $plan['property_id'] . ", '" . $this->db->escape((string) $plan['property_name']) . "', ";
		$sql .= "'" . $this->db->escape((string) $plan['package_count']) . "', '" . $this->db->escape((string) $plan['units_per_package']) . "', ";
		$sql .= "'" . $this->db->escape((string) $plan['target_units']) . "', " . (int) $plan['fk_product_input'] . ', ';
		$sql .= (int) $plan['fk_bom'] . ', ' . (int) $plan['fk_bom_line'] . ', ' . (int) $plan['fk_product_output'] . ', ';
		$sql .= "'" . $this->db->escape($valuation['source_purchase_qty']) . "', ";
		$sql .= "'" . $this->db->escape($valuation['source_unit_cost']) . "', ";
		$sql .= "'" . $this->db->escape($valuation['source_stock_value']) . "', ";
		$sql .= "'" . $this->db->escape($valuation['source_line_total_ht']) . "', ";
		$sql .= "'" . $this->db->escape($valuation['exact_unit_cost']) . "'";
		$sql .= ", 0, '" . $this->db->idate(dol_now()) . "', " . $userId . ', ' . $userId . ')';
		$sql .= ' ON DUPLICATE KEY UPDATE property_movement_id=IF(status=0,VALUES(property_movement_id),property_movement_id),';
		$sql .= ' property_id=IF(status=0,VALUES(property_id),property_id),';
		$sql .= ' property_name=IF(status=0,VALUES(property_name),property_name),';
		$sql .= ' package_count=IF(status=0,VALUES(package_count),package_count),';
		$sql .= ' units_per_package=IF(status=0,VALUES(units_per_package),units_per_package),';
		$sql .= ' target_units=IF(status=0,VALUES(target_units),target_units),';
		$sql .= ' fk_product_input=IF(status=0,VALUES(fk_product_input),fk_product_input),';
		$sql .= ' fk_bom=IF(status=0,VALUES(fk_bom),fk_bom),';
		$sql .= ' fk_bom_line=IF(status=0,VALUES(fk_bom_line),fk_bom_line),';
		$sql .= ' fk_product_output=IF(status=0,VALUES(fk_product_output),fk_product_output),';
		$sql .= ' source_purchase_qty=IF(status=0,VALUES(source_purchase_qty),source_purchase_qty),';
		$sql .= ' source_unit_cost=IF(status=0,VALUES(source_unit_cost),source_unit_cost),';
		$sql .= ' source_stock_value=IF(status=0,VALUES(source_stock_value),source_stock_value),';
		$sql .= ' source_line_total_ht=IF(status=0,VALUES(source_line_total_ht),source_line_total_ht),';
		$sql .= ' exact_unit_cost=IF(status=0,VALUES(exact_unit_cost),exact_unit_cost),';
		$sql .= ' fk_user_modif=IF(status=0,VALUES(fk_user_modif),fk_user_modif)';
		if (!$this->db->query($sql)) {
			$this->error = 'Unable to persist the supplier invoice property stock plan: ' . $this->db->lasterror();
			return false;
		}

		return true;
	}

	/**
	 * Fetch immutable exact-stock audit plans for one supplier invoice.
	 *
	 * @param int $invoiceId Supplier invoice ID
	 * @param int $entity Active entity
	 * @return array<int,array<string,mixed>> Plans keyed by supplier invoice line ID
	 */
	public function fetchInvoicePlans($invoiceId, $entity)
	{
		global $conf;

		$this->error = '';
		$invoiceId = (int) $invoiceId;
		$entity = (int) $entity;
		if ($invoiceId <= 0 || $entity <= 0 || (int) $conf->entity !== $entity) {
			return array();
		}

		$sql = 'SELECT stock_plan.rowid, stock_plan.fk_facture_fourn_det, stock_plan.property_movement_id,';
		$sql .= ' stock_plan.property_id, stock_plan.property_name, stock_plan.package_count,';
		$sql .= ' stock_plan.units_per_package, stock_plan.target_units, stock_plan.fk_product_input,';
		$sql .= ' stock_plan.fk_bom, stock_plan.fk_bom_line, stock_plan.fk_product_output, stock_plan.status,';
		$sql .= ' stock_plan.source_purchase_qty, stock_plan.source_unit_cost, stock_plan.source_stock_value,';
		$sql .= ' stock_plan.source_line_total_ht, stock_plan.exact_unit_cost,';
		$sql .= ' stock_plan.fk_stock_movement_out, stock_plan.fk_stock_movement_in, stock_plan.error_message,';
		$sql .= ' stock_plan.date_creation, stock_plan.date_processed, bom.ref AS bom_ref, bom.label AS bom_label,';
		$sql .= ' output_product.ref AS output_product_ref, output_product.label AS output_product_label';
		$sql .= ' FROM ' . MAIN_DB_PREFIX . 'dolizsynch_property_stock_plan stock_plan';
		$sql .= ' INNER JOIN ' . MAIN_DB_PREFIX . 'facture_fourn invoice';
		$sql .= ' ON invoice.rowid=stock_plan.fk_facture_fourn AND invoice.entity=stock_plan.entity';
		$sql .= ' INNER JOIN ' . MAIN_DB_PREFIX . 'facture_fourn_det invoice_line';
		$sql .= ' ON invoice_line.rowid=stock_plan.fk_facture_fourn_det';
		$sql .= ' AND invoice_line.fk_facture_fourn=invoice.rowid';
		$sql .= ' LEFT JOIN ' . MAIN_DB_PREFIX . 'bom_bom bom ON bom.rowid=stock_plan.fk_bom';
		$sql .= ' LEFT JOIN ' . MAIN_DB_PREFIX . 'product output_product ON output_product.rowid=stock_plan.fk_product_output';
		$sql .= ' WHERE stock_plan.entity=' . $entity . ' AND stock_plan.fk_facture_fourn=' . $invoiceId;
		$sql .= ' ORDER BY stock_plan.fk_facture_fourn_det ASC';
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = 'Unable to load supplier invoice exact-stock audit plans: ' . $this->db->lasterror();
			dol_syslog(__METHOD__ . ': ' . $this->error, LOG_ERR);
			return array();
		}

		$plans = array();
		while ($row = $this->db->fetch_object($resql)) {
			$lineId = (int) $row->fk_facture_fourn_det;
			$plans[$lineId] = array(
				'id' => (int) $row->rowid,
				'fk_facture_fourn_det' => $lineId,
				'property_movement_id' => (int) $row->property_movement_id,
				'property_id' => (int) $row->property_id,
				'property_name' => (string) $row->property_name,
				'package_count' => ZSDecimal::normalize((string) $row->package_count),
				'units_per_package' => ZSDecimal::normalize((string) $row->units_per_package),
				'target_units' => ZSDecimal::normalize((string) $row->target_units),
				'fk_product_input' => (int) $row->fk_product_input,
				'fk_bom' => (int) $row->fk_bom,
				'fk_bom_line' => (int) $row->fk_bom_line,
				'fk_product_output' => (int) $row->fk_product_output,
				'source_purchase_qty' => $row->source_purchase_qty === null ? null : ZSDecimal::normalize((string) $row->source_purchase_qty),
				'source_unit_cost' => $row->source_unit_cost === null ? null : ZSDecimal::normalize((string) $row->source_unit_cost),
				'source_stock_value' => $row->source_stock_value === null ? null : ZSDecimal::normalize((string) $row->source_stock_value),
				'source_line_total_ht' => $row->source_line_total_ht === null ? null : ZSDecimal::normalize((string) $row->source_line_total_ht),
				'exact_unit_cost' => $row->exact_unit_cost === null ? null : ZSDecimal::normalize((string) $row->exact_unit_cost),
				'status' => (int) $row->status,
				'fk_stock_movement_out' => (int) $row->fk_stock_movement_out,
				'fk_stock_movement_in' => (int) $row->fk_stock_movement_in,
				'error_message' => (string) $row->error_message,
				'date_creation' => (string) $row->date_creation,
				'date_processed' => (string) $row->date_processed,
				'bom_ref' => (string) $row->bom_ref,
				'bom_label' => (string) $row->bom_label,
				'output_product_ref' => (string) $row->output_product_ref,
				'output_product_label' => (string) $row->output_product_label,
			);
		}
		$this->db->free($resql);

		return $plans;
	}

	/**
	 * Reconcile a completed native dismantling output movement.
	 *
	 * @param int $movementId Stock movement ID
	 * @param User $user Acting user
	 * @param int $entity Active entity
	 * @return int 1 when reconciled, 0 when unrelated/deferred, -1 on failure
	 */
	public function reconcileProducedMovement($movementId, $user, $entity)
	{
		$movementId = (int) $movementId;
		$entity = (int) $entity;
		if ($movementId <= 0 || $entity <= 0) {
			return 0;
		}

		$sql = 'SELECT sm.rowid, sm.fk_product AS output_product, sm.inventorycode, mp.fk_mo,';
		$sql .= ' mo.entity, mo.fk_product AS input_product, mo.fk_bom, mo.note_private';
		$sql .= ' FROM ' . MAIN_DB_PREFIX . 'stock_mouvement sm';
		$sql .= ' INNER JOIN ' . MAIN_DB_PREFIX . 'mrp_production mp ON mp.fk_stock_movement=sm.rowid AND mp.role=\'produced\'';
		$sql .= ' INNER JOIN ' . MAIN_DB_PREFIX . 'mrp_mo mo ON mo.rowid=mp.fk_mo';
		$sql .= ' WHERE sm.rowid=' . $movementId . " AND sm.origintype='mo' AND sm.value>0";
		$sql .= ' AND mo.entity=' . $entity;
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = 'Unable to inspect the stock movement: ' . $this->db->lasterror();
			return -1;
		}
		if (!($row = $this->db->fetch_object($resql))) {
			return 0;
		}
		if (!preg_match('/invoice_supplier\s+#([0-9]+)/', (string) $row->note_private, $matches)) {
			return 0;
		}

		$invoiceId = (int) $matches[1];
		$inputProductId = (int) $row->input_product;
		$outputProductId = (int) $row->output_product;
		$bomId = (int) $row->fk_bom;
		return $this->reconcilePlanGroup($entity, $invoiceId, $inputProductId, $bomId, $outputProductId, $user);
	}

	/**
	 * Reconcile the produced output after the native MO completion event.
	 *
	 * @param int $moId Manufacturing order ID
	 * @param User $user Acting user
	 * @param int $entity Active entity
	 * @return int 1 when reconciled, 0 when unrelated/deferred, -1 on failure
	 */
	public function reconcileProducedMo($moId, $user, $entity)
	{
		$moId = (int) $moId;
		$entity = (int) $entity;
		if ($moId <= 0 || $entity <= 0) {
			return 0;
		}

		$sql = 'SELECT sm.rowid';
		$sql .= ' FROM ' . MAIN_DB_PREFIX . 'mrp_mo mo';
		$sql .= ' INNER JOIN ' . MAIN_DB_PREFIX . 'mrp_production mp ON mp.fk_mo=mo.rowid AND mp.role=\'produced\'';
		$sql .= ' INNER JOIN ' . MAIN_DB_PREFIX . 'stock_mouvement sm ON sm.rowid=mp.fk_stock_movement';
		$sql .= ' WHERE mo.rowid=' . $moId . ' AND mo.entity=' . $entity;
		$sql .= " AND sm.origintype='mo' AND sm.value>0 ORDER BY sm.rowid";
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = 'Unable to inspect completed manufacturing output: ' . $this->db->lasterror();
			return -1;
		}
		$movementIds = array();
		while ($row = $this->db->fetch_object($resql)) {
			$movementIds[] = (int) $row->rowid;
		}
		if (empty($movementIds)) {
			return 0;
		}
		if (count($movementIds) !== 1) {
			$this->error = 'Completed manufacturing order has multiple produced stock outputs.';
			return -1;
		}

		return $this->reconcileProducedMovement($movementIds[0], $user, $entity);
	}

	/**
	 * Verify that an adopted invoice already owns every required plan.
	 *
	 * @param int $invoiceId Supplier invoice ID
	 * @param int $entity Target entity
	 * @param int $expectedCount Required plan count
	 * @return bool True when plans already exist
	 */
	public function verifyExistingPlans($invoiceId, $entity, $expectedCount)
	{
		$sql = 'SELECT COUNT(*) AS nb FROM ' . MAIN_DB_PREFIX . 'dolizsynch_property_stock_plan';
		$sql .= ' WHERE entity=' . (int) $entity . ' AND fk_facture_fourn=' . (int) $invoiceId;
		$resql = $this->db->query($sql);
		if (!$resql || !($row = $this->db->fetch_object($resql))) {
			return false;
		}
		return (int) $row->nb === (int) $expectedCount;
	}

	/**
	 * @param int $entity Entity
	 * @param int $invoiceId Supplier invoice
	 * @param int $inputProductId Purchased product
	 * @param int $bomId Dismantling BOM used by the MO
	 * @param int $outputProductId Unit product
	 * @param User $user Acting user
	 * @return int Result
	 */
	private function reconcilePlanGroup($entity, $invoiceId, $inputProductId, $bomId, $outputProductId, $user)
	{
		$sqlPlans = 'SELECT stock_plan.rowid, stock_plan.target_units, stock_plan.fk_bom_line,';
		$sqlPlans .= ' stock_plan.source_purchase_qty, stock_plan.source_unit_cost, stock_plan.source_stock_value,';
		$sqlPlans .= ' stock_plan.source_line_total_ht, stock_plan.exact_unit_cost,';
		$sqlPlans .= ' invoice_line.fk_product AS current_product, invoice_line.qty AS current_qty,';
		$sqlPlans .= ' invoice_line.pu_ht AS current_subprice,';
		$sqlPlans .= ' COALESCE(invoice_line.remise_percent, 0) AS current_discount,';
		$sqlPlans .= ' COALESCE(invoice_line.total_ht, 0) AS current_total_ht';
		$sqlPlans .= ' FROM ' . MAIN_DB_PREFIX . 'dolizsynch_property_stock_plan stock_plan';
		$sqlPlans .= ' INNER JOIN ' . MAIN_DB_PREFIX . 'facture_fourn invoice';
		$sqlPlans .= ' ON invoice.rowid=stock_plan.fk_facture_fourn AND invoice.entity=stock_plan.entity';
		$sqlPlans .= ' INNER JOIN ' . MAIN_DB_PREFIX . 'facture_fourn_det invoice_line';
		$sqlPlans .= ' ON invoice_line.rowid=stock_plan.fk_facture_fourn_det';
		$sqlPlans .= ' AND invoice_line.fk_facture_fourn=invoice.rowid';
		$sqlPlans .= ' WHERE stock_plan.entity=' . $entity . ' AND stock_plan.fk_facture_fourn=' . $invoiceId;
		$sqlPlans .= ' AND stock_plan.fk_product_input=' . $inputProductId . ' AND stock_plan.fk_bom=' . $bomId;
		$sqlPlans .= ' AND stock_plan.fk_product_output=' . $outputProductId;
		$sqlPlans .= ' AND stock_plan.status=0 FOR UPDATE';
		$resPlans = $this->db->query($sqlPlans);
		if (!$resPlans) {
			$this->error = 'Unable to lock property stock plans: ' . $this->db->lasterror();
			return -1;
		}
		$planIds = array();
		$planValuations = array();
		$bomLineId = 0;
		$targetUnits = '0';
		$sourceStockValue = '0';
		$nativeSourceStockValue = '0';
		while ($plan = $this->db->fetch_object($resPlans)) {
			$planId = (int) $plan->rowid;
			$planTargetUnits = ZSDecimal::normalize((string) $plan->target_units);
			if ((int) $plan->current_product !== $inputProductId) {
				$this->error = 'The exact-stock plan no longer matches its supplier invoice product.';
				return -1;
			}
			try {
				$currentValuation = self::buildValuationEvidence(
					(string) $plan->current_qty,
					(string) $plan->current_subprice,
					(string) $plan->current_discount,
					(string) $plan->current_total_ht,
					$planTargetUnits
				);
			} catch (InvalidArgumentException $e) {
				$this->error = $e->getMessage();
				return -1;
			}
			$storedFields = array(
				'source_purchase_qty',
				'source_unit_cost',
				'source_stock_value',
				'source_line_total_ht',
				'exact_unit_cost',
			);
			foreach ($storedFields as $storedField) {
				if ($plan->{$storedField} !== null
					&& ZSDecimal::compare((string) $plan->{$storedField}, $currentValuation[$storedField]) !== 0) {
					$this->error = 'The supplier invoice valuation evidence changed after the exact-stock plan was created.';
					return -1;
				}
			}

			$planIds[] = $planId;
			$planValuations[$planId] = $currentValuation;
			if ($bomLineId === 0) {
				$bomLineId = (int) $plan->fk_bom_line;
			} elseif ($bomLineId !== (int) $plan->fk_bom_line) {
				$this->error = 'Property stock plans resolve to multiple output lines of the same BOM.';
				return -1;
			}
			$targetUnits = ZSDecimal::add($targetUnits, $planTargetUnits);
			$sourceStockValue = ZSDecimal::add($sourceStockValue, $currentValuation['source_stock_value']);
			$nativeSourceStockValue = ZSDecimal::add(
				$nativeSourceStockValue,
				ZSDecimal::multiply((string) $plan->current_qty, (string) $plan->current_subprice)
			);
		}
		if (empty($planIds)) {
			$sqlMismatch = 'SELECT rowid FROM ' . MAIN_DB_PREFIX . 'dolizsynch_property_stock_plan';
			$sqlMismatch .= ' WHERE entity=' . $entity . ' AND fk_facture_fourn=' . $invoiceId;
			$sqlMismatch .= ' AND fk_product_input=' . $inputProductId . ' AND status=0';
			$sqlMismatch .= ' AND fk_bom<>' . $bomId . ' ' . $this->db->plimit(1);
			$resMismatch = $this->db->query($sqlMismatch);
			if (!$resMismatch) {
				$this->error = 'Unable to verify the configured dismantling BOM: ' . $this->db->lasterror();
				return -1;
			}
			if ($this->db->fetch_object($resMismatch)) {
				$this->error = 'Automatic dismantling used a different BOM from the property stock plan.';
				return -1;
			}
			return 0;
		}

		$notePrefix = 'Auto dismantle from invoice_supplier #' . $invoiceId . ',%';
		$sqlProduction = 'SELECT COUNT(DISTINCT mo.rowid) AS mo_count, COUNT(DISTINCT sm.fk_entrepot) AS warehouse_count,';
		$sqlProduction .= ' MIN(sm.fk_entrepot) AS warehouse_id, SUM(sm.value) AS produced_qty,';
		$sqlProduction .= ' SUM(sm.value * sm.price) AS produced_value';
		$sqlProduction .= ' FROM ' . MAIN_DB_PREFIX . 'mrp_mo mo';
		$sqlProduction .= ' INNER JOIN ' . MAIN_DB_PREFIX . 'mrp_production mp ON mp.fk_mo=mo.rowid';
		$sqlProduction .= " AND mp.role='produced' AND mp.fk_product=" . $outputProductId;
		$sqlProduction .= ' INNER JOIN ' . MAIN_DB_PREFIX . 'stock_mouvement sm ON sm.rowid=mp.fk_stock_movement';
		$sqlProduction .= ' WHERE mo.entity=' . $entity . ' AND mo.fk_product=' . $inputProductId;
		$sqlProduction .= ' AND mo.fk_bom=' . $bomId;
		$sqlProduction .= " AND mo.note_private LIKE '" . $this->db->escape($notePrefix) . "'";
		$resProduction = $this->db->query($sqlProduction);
		if (!$resProduction || !($production = $this->db->fetch_object($resProduction))) {
			$this->error = 'Unable to inspect automatic dismantling output: ' . $this->db->lasterror();
			return -1;
		}
		if ((int) $production->mo_count < count($planIds)) {
			return 0;
		}
		if ((int) $production->mo_count !== count($planIds) || (int) $production->warehouse_count !== 1) {
			$this->error = 'Automatic dismantling output does not match the configured property stock plan.';
			return -1;
		}

		$producedQty = ZSDecimal::normalize((string) $production->produced_qty);
		$producedValue = ZSDecimal::normalize((string) $production->produced_value);
		$warehouseId = (int) $production->warehouse_id;
		if (ZSDecimal::compare($producedQty, '0') <= 0 || ZSDecimal::compare($producedValue, '0') < 0
			|| ZSDecimal::compare($targetUnits, '0') <= 0 || $warehouseId <= 0) {
			$this->error = 'Automatic dismantling produced invalid quantity, value, or warehouse evidence.';
			return -1;
		}
		// Native MO movements are persisted through floating-point quantities. Reconcile at
		// four monetary decimals so decimal BOM outputs cannot create false micro-residuals.
		if (ZSDecimal::compare(ZSDecimal::round($producedValue, 4), ZSDecimal::round($nativeSourceStockValue, 4)) !== 0) {
			$this->error = 'Automatic dismantling value does not reconcile with the supplier invoice stock movement.';
			return -1;
		}

		$outKey = 'DZS-PROP-OUT-' . $entity . '-' . $invoiceId . '-' . $bomId . '-' . $inputProductId . '-' . $outputProductId;
		$inKey = 'DZS-PROP-IN-' . $entity . '-' . $invoiceId . '-' . $bomId . '-' . $inputProductId . '-' . $outputProductId;
		if ($this->movementExists($outKey) || $this->movementExists($inKey)) {
			$this->error = 'A partial or previously applied property stock correction already exists.';
			return -1;
		}

		require_once DOL_DOCUMENT_ROOT . '/product/stock/class/mouvementstock.class.php';
		$weightedOutputPrice = ZSDecimal::divide($producedValue, $producedQty, 8);
		$exactUnitPrice = ZSDecimal::divide($sourceStockValue, $targetUnits, 8);
		$label = 'Property stock conversion for supplier invoice #' . $invoiceId;

		$movementOut = new MouvementStock($this->db);
		$movementOut->setOrigin('invoice_supplier', $invoiceId);
		$outResult = $movementOut->_create(
			$user,
			$outputProductId,
			$warehouseId,
			0 - (float) $producedQty,
			2,
			(float) $weightedOutputPrice,
			$label,
			$outKey,
			dol_now(),
			0,
			0,
			'',
			!isModEnabled('productbatch'),
			0,
			1
		);
		if ($outResult <= 0) {
			$this->error = 'Unable to remove calculated dismantling output: ' . ($movementOut->error ?: 'unknown error');
			return -1;
		}

		$movementIn = new MouvementStock($this->db);
		$movementIn->setOrigin('invoice_supplier', $invoiceId);
		$inResult = $movementIn->_create(
			$user,
			$outputProductId,
			$warehouseId,
			(float) $targetUnits,
			3,
			(float) $exactUnitPrice,
			$label,
			$inKey,
			dol_now(),
			0,
			0,
			'',
			!isModEnabled('productbatch'),
			0,
			1
		);
		if ($inResult <= 0) {
			$this->error = 'Unable to create exact property-derived units: ' . ($movementIn->error ?: 'unknown error');
			return -1;
		}

		foreach ($planValuations as $planId => $valuation) {
			$sqlEvidence = 'UPDATE ' . MAIN_DB_PREFIX . 'dolizsynch_property_stock_plan SET';
			$sqlEvidence .= " source_purchase_qty='" . $this->db->escape($valuation['source_purchase_qty']) . "',";
			$sqlEvidence .= " source_unit_cost='" . $this->db->escape($valuation['source_unit_cost']) . "',";
			$sqlEvidence .= " source_stock_value='" . $this->db->escape($valuation['source_stock_value']) . "',";
			$sqlEvidence .= " source_line_total_ht='" . $this->db->escape($valuation['source_line_total_ht']) . "',";
			$sqlEvidence .= " exact_unit_cost='" . $this->db->escape($valuation['exact_unit_cost']) . "'";
			$sqlEvidence .= ' WHERE rowid=' . (int) $planId . ' AND entity=' . $entity . ' AND status=0';
			$resEvidenceUpdate = $this->db->query($sqlEvidence);
			if (!$resEvidenceUpdate) {
				$this->error = 'Unable to finalize the supplier invoice valuation evidence.';
				return -1;
			}
		}

		$sqlUpdate = 'UPDATE ' . MAIN_DB_PREFIX . 'dolizsynch_property_stock_plan SET status=1,';
		$sqlUpdate .= ' fk_stock_movement_out=' . (int) $outResult . ', fk_stock_movement_in=' . (int) $inResult . ',';
		$sqlUpdate .= " date_processed='" . $this->db->idate(dol_now()) . "', fk_user_modif=" . (int) $user->id;
		$sqlUpdate .= ' WHERE rowid IN (' . implode(',', $planIds) . ') AND status=0';
		$resUpdate = $this->db->query($sqlUpdate);
		if (!$resUpdate || $this->db->affected_rows($resUpdate) !== count($planIds)) {
			$this->error = 'Unable to finalize the property stock plan.';
			return -1;
		}

		return 1;
	}

	/** @return bool */
	private function movementExists($inventoryCode)
	{
		$sql = 'SELECT rowid FROM ' . MAIN_DB_PREFIX . 'stock_mouvement';
		$sql .= " WHERE inventorycode='" . $this->db->escape($inventoryCode) . "'";
		$sql .= ' ' . $this->db->plimit(1);
		$resql = $this->db->query($sql);
		return $resql && (bool) $this->db->fetch_object($resql);
	}

	/** @return bool */
	private function isVisibleProduct($productId, $entity)
	{
		global $conf;

		if ((int) $conf->entity !== (int) $entity) {
			return false;
		}
		$sql = 'SELECT rowid FROM ' . MAIN_DB_PREFIX . 'product';
		$sql .= ' WHERE rowid=' . (int) $productId . ' AND fk_product_type=0';
		$sql .= ' AND entity IN (' . getEntity('product') . ')';
		$sql .= ' ' . $this->db->plimit(1);
		$resql = $this->db->query($sql);
		return $resql && (bool) $this->db->fetch_object($resql);
	}

	/**
	 * Resolve and validate one BOM-owned output selection.
	 *
	 * @param int $inputProductId Purchased product
	 * @param int $bomId Dismantling BOM
	 * @param int $entity Active entity
	 * @return array|null Selection or null
	 */
	private function resolveValidatedDismantlingSelection($inputProductId, $bomId, $entity)
	{
		$boms = $this->fetchValidatedDismantlingBoms($inputProductId, $entity);
		$bomId = (int) $bomId;
		if (!isset($boms[$bomId])) {
			$this->error = 'Select a validated dismantling BOM with exactly one entity-visible stock output.';
			return null;
		}

		$outputs = $boms[$bomId]['outputs'];
		$bomLineId = (int) array_key_first($outputs);
		$output = $outputs[$bomLineId];
		if (!$this->isVisibleProduct((int) $output['fk_product'], $entity)) {
			$this->error = 'The selected BOM output product is not visible in the active entity.';
			return null;
		}

		return array(
			'fk_bom' => $bomId,
			'bom_entity' => (int) $boms[$bomId]['entity'],
			'fk_bom_line' => $bomLineId,
			'fk_product_output' => (int) $output['fk_product'],
			'units_per_package' => (string) $output['qty'],
		);
	}

	/** @return bool */
	private static function isWholeNumberStatic($value)
	{
		$value = ZSDecimal::normalize((string) $value);
		return preg_match('/^-?[0-9]+$/', $value) === 1;
	}

	/** @return string */
	private static function normalizeName($value)
	{
		$value = self::decodeName($value);
		$value = function_exists('mb_strtoupper') ? mb_strtoupper(trim($value), 'UTF-8') : strtoupper(trim($value));
		return preg_replace('/\s+/u', ' ', $value);
	}

	/** @return string */
	private static function decodeName($value)
	{
		$value = trim((string) $value);
		for ($pass = 0; $pass < 3; $pass++) {
			$decoded = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
			if ($decoded === $value) {
				break;
			}
			$value = $decoded;
		}
		return $value;
	}
}

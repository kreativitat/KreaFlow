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
 * \file       class/dolizsynchstockreversal.class.php
 * \ingroup    dolizsynch
 * \brief      Exact, idempotent invoice stock reversal service.
 */

require_once DOL_DOCUMENT_ROOT.'/product/stock/class/mouvementstock.class.php';

/**
 * Reverse the net stock ledger owned by a Dolibarr invoice.
 */
class DoliZSynchStockReversal
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
	 * Reverse the outstanding stock effect of an invoice.
	 *
	 * The caller owns the surrounding transaction. The invoice row is locked so two
	 * cancellation workers cannot create the same reversal concurrently.
	 *
	 * @param CommonObject $invoice    Customer or supplier invoice
	 * @param string       $originType Stock movement origin type used by Dolibarr
	 * @param User         $user       Acting user
	 * @return int                      Number of reversal movements, -1 on failure
	 */
	public function reverseInvoiceMovements($invoice, $originType, $user)
	{
		global $conf;

		$invoiceId = (int) $invoice->id;
		$entity = (int) $conf->entity;
		$table = ($originType === 'facture') ? 'facture' : (($originType === 'invoice_supplier') ? 'facture_fourn' : '');
		if ($invoiceId <= 0 || $entity <= 0 || $table === '') {
			$this->error = 'Invalid invoice stock-reversal context';
			return -1;
		}

		$sqlLock = 'SELECT rowid FROM '.MAIN_DB_PREFIX.$table;
		$sqlLock .= ' WHERE rowid='.$invoiceId.' AND entity='.$entity.' FOR UPDATE';
		$resLock = $this->db->query($sqlLock);
		if (!$resLock || !$this->db->fetch_object($resLock)) {
			$this->error = 'Unable to lock the entity-scoped invoice before stock reversal';
			return -1;
		}

		$sql = 'SELECT fk_product, fk_entrepot, COALESCE(batch, \'\') AS batch,';
		$sql .= ' MAX(eatby) AS eatby, MAX(sellby) AS sellby, SUM(value) AS net_value';
		$sql .= ' FROM '.MAIN_DB_PREFIX.'stock_mouvement';
		$sql .= " WHERE ((origintype='".$this->db->escape($originType)."' AND fk_origin=".$invoiceId.')';
		$sql .= " OR ((origintype IS NULL OR origintype='') AND label='".$this->db->escape('Canceled: '.$invoice->ref)."'))";
		$sql .= ' AND fk_entrepot IN (SELECT rowid FROM '.MAIN_DB_PREFIX.'entrepot WHERE entity IN ('.getEntity('stock').'))';
		$sql .= ' GROUP BY fk_product, fk_entrepot, COALESCE(batch, \'\')';
		$sql .= ' HAVING ABS(SUM(value)) > 0.00000001';
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = 'Unable to read invoice stock movements: '.$this->db->lasterror();
			return -1;
		}

		$created = 0;
		while ($row = $this->db->fetch_object($resql)) {
			$productId = (int) $row->fk_product;
			$warehouseId = (int) $row->fk_entrepot;
			$batch = (string) $row->batch;
			$netValue = (float) $row->net_value;
			if ($productId <= 0 || $warehouseId <= 0) {
				$this->error = 'Invoice stock ledger contains an invalid product or warehouse';
				return -1;
			}

			$key = 'DZS-CANCEL-'.$originType.'-'.$invoiceId.'-'.substr(sha1($productId.'|'.$warehouseId.'|'.$batch), 0, 20);
			$sqlExisting = 'SELECT rowid FROM '.MAIN_DB_PREFIX.'stock_mouvement';
			$sqlExisting .= " WHERE inventorycode='".$this->db->escape($key)."' LIMIT 1";
			$resExisting = $this->db->query($sqlExisting);
			if (!$resExisting) {
				$this->error = 'Unable to verify stock-reversal idempotency';
				return -1;
			}
			if ($this->db->fetch_object($resExisting)) {
				continue;
			}

			$inverseQty = 0 - $netValue;
			$movement = new MouvementStock($this->db);
			$movement->setOrigin('dolizsynch_cancel', $invoiceId);
			$label = 'Canceled: '.$invoice->ref;
			$eatby = !empty($row->eatby) ? $this->db->jdate($row->eatby) : 0;
			$sellby = !empty($row->sellby) ? $this->db->jdate($row->sellby) : 0;
			$skipBatch = !isModEnabled('productbatch');
			$type = ($inverseQty > 0) ? 3 : 2;
			$result = $movement->_create(
				$user,
				$productId,
				$warehouseId,
				$inverseQty,
				$type,
				0,
				$label,
				$key,
				dol_now(),
				$eatby,
				$sellby,
				$batch,
				$skipBatch,
				0,
				1
			);
			if ($result <= 0) {
				$this->error = 'Stock reversal failed for product '.$productId.', warehouse '.$warehouseId.': '.($movement->error ?: 'unknown error');
				return -1;
			}
			$created++;
		}

		return $created;
	}
}

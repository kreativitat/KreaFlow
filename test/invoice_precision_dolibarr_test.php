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

$master = '/var/www/html/master.inc.php';
if (!is_file($master)) {
	echo "SKIP: installed Dolibarr integration is available only in lamp-php81\n";
	exit(0);
}

require_once $master;
require_once DOL_DOCUMENT_ROOT . '/compta/facture/class/facture.class.php';
require_once DOL_DOCUMENT_ROOT . '/fourn/class/fournisseur.facture.class.php';
require_once DOL_DOCUMENT_ROOT . '/user/class/user.class.php';
require_once dirname(__DIR__) . '/class/zsinvoicetaxcalculator.class.php';
require_once dirname(__DIR__) . '/class/zsinvoicesynch.class.php';
require_once dirname(__DIR__) . '/class/zsstoreinvoiceconverter.class.php';

global $db, $conf, $langs, $user;

$entity = (int) $conf->entity;
$baseline = (int) $db->transaction_opened;
$generatedFile = '';
$temporaryPdf = '/tmp/dolizsynch_invoice_precision.pdf';
$failure = '';
$originalUser = $user;

try {
	$sql = 'SELECT rowid FROM ' . MAIN_DB_PREFIX . 'societe';
	$sql .= ' WHERE entity = ' . $entity . ' AND client IN (1, 2, 3)';
	$sql .= ' ORDER BY rowid' . $db->plimit(1);
	$resql = $db->query($sql);
	$thirdparty = $resql ? $db->fetch_object($resql) : null;
	if (!$thirdparty) {
		throw new RuntimeException('No current-entity customer is available for the rollback-only integration test');
	}
	$sql = 'SELECT rowid FROM ' . MAIN_DB_PREFIX . 'societe';
	$sql .= ' WHERE entity = ' . $entity . ' AND fournisseur = 1';
	$sql .= ' ORDER BY rowid' . $db->plimit(1);
	$resql = $db->query($sql);
	$supplier = $resql ? $db->fetch_object($resql) : null;
	if (!$supplier) {
		throw new RuntimeException('No current-entity supplier is available for the rollback-only integration test');
	}

	$sql = 'SELECT rowid FROM ' . MAIN_DB_PREFIX . 'user';
	$sql .= ' WHERE statut = 1 AND entity IN (0, ' . $entity . ')';
	$sql .= ' ORDER BY admin DESC, rowid' . $db->plimit(1);
	$resql = $db->query($sql);
	$actorRow = $resql ? $db->fetch_object($resql) : null;
	$actor = new User($db);
	if (!$actorRow || $actor->fetch((int) $actorRow->rowid) <= 0) {
		throw new RuntimeException('No active lifecycle actor is available for the rollback-only integration test');
	}
	$actor->loadRights('', 0);
	$user = $actor;

	if (!$db->begin()) {
		throw new RuntimeException('Unable to begin rollback-only integration transaction');
	}
	$invoice = new Facture($db);
	$invoice->socid = (int) $thirdparty->rowid;
	$invoice->date = dol_now();
	$invoice->type = Facture::TYPE_STANDARD;
	$invoice->ref_client = 'DOLIZSYNCH-PRECISION-' . date('YmdHis');
	$invoice->ref_customer = $invoice->ref_client;
	$invoice->note_private = '';
	$invoice->note_public = '';
	$invoice->note = '';
	if ($invoice->create($actor) <= 0) {
		throw new RuntimeException($invoice->error ?: 'Unable to create rollback-only invoice');
	}

	for ($i = 1; $i <= 2; $i++) {
		$result = $invoice->addline(
			'Precision base ' . $i,
			'2.37',
			'1',
			'23',
			0,
			0,
			0,
			0,
			'',
			'',
			0,
			0,
			0,
			'HT',
			0,
			1,
			-1,
			0,
			'',
			0,
			0,
			null,
			'0',
			'',
			[],
			100,
			0,
			null,
			'0',
			'',
			1
		);
		if ($result <= 0) {
			throw new RuntimeException($invoice->error ?: 'Unable to add rollback-only precision line');
		}
	}
	if ($invoice->update_price(1, '1') <= 0 || $invoice->fetch((int) $invoice->id) <= 0 || $invoice->fetch_lines() < 0) {
		throw new RuntimeException($invoice->error ?: 'Native tax-group reconciliation failed');
	}

	$lineHt = '0';
	$lineVat = '0';
	$lineTtc = '0';
	foreach ($invoice->lines as $line) {
		$lineHt = ZSDecimal::add($lineHt, (string) $line->total_ht);
		$lineVat = ZSDecimal::add($lineVat, (string) $line->total_tva);
		$lineTtc = ZSDecimal::add($lineTtc, (string) $line->total_ttc);
	}
	if (ZSDecimal::round((string) $invoice->total_ht, 2) !== '4.74'
		|| ZSDecimal::round((string) $invoice->total_tva, 2) !== '1.09'
		|| ZSDecimal::round((string) $invoice->total_ttc, 2) !== '5.83'
		|| ZSDecimal::round($lineHt, 2) !== '4.74'
		|| ZSDecimal::round($lineVat, 2) !== '1.09'
		|| ZSDecimal::round($lineTtc, 2) !== '5.83') {
		throw new RuntimeException('Installed Dolibarr line and header totals did not reconcile');
	}

	$sql = 'SELECT f.total_ht, f.total_tva, f.total_ttc,';
	$sql .= ' SUM(fd.total_ht) AS lines_ht, SUM(fd.total_tva) AS lines_tva, SUM(fd.total_ttc) AS lines_ttc';
	$sql .= ' FROM ' . MAIN_DB_PREFIX . 'facture f';
	$sql .= ' INNER JOIN ' . MAIN_DB_PREFIX . 'facturedet fd ON fd.fk_facture = f.rowid';
	$sql .= ' WHERE f.rowid = ' . (int) $invoice->id . ' AND f.entity = ' . $entity;
	$sql .= ' GROUP BY f.rowid, f.total_ht, f.total_tva, f.total_ttc';
	$resql = $db->query($sql);
	$stored = $resql ? $db->fetch_object($resql) : null;
	if (!$stored
		|| ZSDecimal::round((string) $stored->total_ht, 2) !== '4.74'
		|| ZSDecimal::round((string) $stored->total_tva, 2) !== '1.09'
		|| ZSDecimal::round((string) $stored->total_ttc, 2) !== '5.83'
		|| ZSDecimal::round((string) $stored->lines_ht, 2) !== '4.74'
		|| ZSDecimal::round((string) $stored->lines_tva, 2) !== '1.09'
		|| ZSDecimal::round((string) $stored->lines_ttc, 2) !== '5.83') {
		throw new RuntimeException('Direct database invoice values did not reconcile');
	}

	if ($invoice->generateDocument('crabe', $langs) <= 0) {
		throw new RuntimeException($invoice->error ?: 'Unable to render the rollback-only invoice PDF');
	}
	$generatedFile = (string) ($invoice->last_main_doc ?? '');
	if ($generatedFile === '') {
		$invoiceDirectory = (string) ($conf->facture->multidir_output[$entity] ?? '');
		if ($invoiceDirectory !== '' && $invoiceDirectory[0] !== '/') {
			$invoiceDirectory = rtrim((string) DOL_DATA_ROOT, '/') . '/' . ltrim($invoiceDirectory, '/');
		}
		$safeRef = dol_sanitizeFileName((string) $invoice->ref);
		$generatedFile = $invoiceDirectory . '/' . $safeRef . '/' . $safeRef . '.pdf';
	}
	if ($generatedFile !== '' && $generatedFile[0] !== '/') {
		$generatedFile = rtrim((string) DOL_DATA_ROOT, '/') . '/' . ltrim($generatedFile, '/');
	}
	if ($generatedFile === '' || !is_file($generatedFile) || !copy($generatedFile, $temporaryPdf)) {
		throw new RuntimeException('The generated invoice PDF could not be captured: ' . $generatedFile);
	}

	$synchronizer = new ZSInvoiceSynch($db);
	$sourcePlan = ZSInvoiceTaxCalculator::calculate([
		['quantity' => '1', 'unit_price' => '2.37', 'vat_rate' => '23'],
		['quantity' => '1', 'unit_price' => '2.37', 'vat_rate' => '23'],
	]);
	$validateMethod = new ReflectionMethod($synchronizer, 'validateInvoiceWithMonetaryPlan');
	$validateMethod->setAccessible(true);
	if (!$validateMethod->invoke($synchronizer, $invoice, $actor, 0, (string) $invoice->ref_client, $sourcePlan)) {
		throw new RuntimeException($synchronizer->error ?: 'Unable to validate source invoice with its monetary plan');
	}
	if ($invoice->fetch((int) $invoice->id) <= 0 || $invoice->fetch_lines() < 0
		|| ZSDecimal::round((string) $invoice->multicurrency_total_ht, 2) !== '4.74'
		|| ZSDecimal::round((string) $invoice->multicurrency_total_tva, 2) !== '1.09'
		|| ZSDecimal::round((string) $invoice->multicurrency_total_ttc, 2) !== '5.83') {
		throw new RuntimeException('Validated customer multicurrency totals did not reconcile');
	}
	$beforeRepeat = serialize([
		(string) $invoice->total_ht,
		(string) $invoice->total_tva,
		(string) $invoice->total_ttc,
		array_map(function ($line) {
			return [(string) $line->total_ht, (string) $line->total_tva, (string) $line->total_ttc];
		}, $invoice->lines),
	]);
	$reconcileMethod = new ReflectionMethod($synchronizer, 'reconcileCustomerInvoiceTotals');
	$reconcileMethod->setAccessible(true);
	if (!$reconcileMethod->invoke($synchronizer, $invoice, ['monetary_plan' => $sourcePlan])
		|| $invoice->fetch((int) $invoice->id) <= 0 || $invoice->fetch_lines() < 0) {
		throw new RuntimeException($synchronizer->error ?: 'Repeated customer reconciliation failed');
	}
	$afterRepeat = serialize([
		(string) $invoice->total_ht,
		(string) $invoice->total_tva,
		(string) $invoice->total_ttc,
		array_map(function ($line) {
			return [(string) $line->total_ht, (string) $line->total_tva, (string) $line->total_ttc];
		}, $invoice->lines),
	]);
	if ($afterRepeat !== $beforeRepeat) {
		throw new RuntimeException('Repeated customer reconciliation changed stored cents');
	}
	$creditNoteId = $synchronizer->createCreditNoteFromInvoice((int) $invoice->id);
	if ($creditNoteId <= 0) {
		throw new RuntimeException($synchronizer->error ?: 'Production credit-note copy failed');
	}
	$creditNote = new Facture($db);
	if ($creditNote->fetch((int) $creditNoteId) <= 0 || $creditNote->fetch_lines() < 0
		|| ZSDecimal::round((string) $creditNote->total_ht, 2) !== '-4.74'
		|| ZSDecimal::round((string) $creditNote->total_tva, 2) !== '-1.09'
		|| ZSDecimal::round((string) $creditNote->total_ttc, 2) !== '-5.83'
		|| ZSDecimal::round((string) $creditNote->multicurrency_total_ttc, 2) !== '-5.83') {
		throw new RuntimeException('Credit note did not preserve one exact negative sign: '
			. (string) $creditNote->total_ht . '/' . (string) $creditNote->total_tva . '/' . (string) $creditNote->total_ttc);
	}

	$fractionalInvoice = new Facture($db);
	$fractionalInvoice->socid = (int) $thirdparty->rowid;
	$fractionalInvoice->date = dol_now();
	$fractionalInvoice->type = Facture::TYPE_STANDARD;
	$fractionalInvoice->ref_client = 'DOLIZSYNCH-FRACTIONAL-' . date('YmdHis');
	$fractionalInvoice->ref_customer = $fractionalInvoice->ref_client;
	if ($fractionalInvoice->create($actor) <= 0) {
		throw new RuntimeException($fractionalInvoice->error ?: 'Unable to create fractional invoice');
	}
	for ($i = 1; $i <= 2; $i++) {
		$result = $fractionalInvoice->addline(
			'Fractional precision ' . $i,
			'1.005',
			'1',
			'23',
			0,
			0,
			0,
			0,
			'',
			'',
			0,
			0,
			0,
			'HT',
			0,
			1,
			-1,
			0,
			'',
			0,
			0,
			null,
			'0',
			'',
			[],
			100,
			0,
			null,
			'0',
			'',
			1
		);
		if ($result <= 0) {
			throw new RuntimeException($fractionalInvoice->error ?: 'Unable to add fractional line');
		}
	}
	$fractionalPlan = ZSInvoiceTaxCalculator::calculate([
		['quantity' => '1', 'unit_price' => '1.005', 'vat_rate' => '23'],
		['quantity' => '1', 'unit_price' => '1.005', 'vat_rate' => '23'],
	]);
	$synchronizer = new ZSInvoiceSynch($db);
	$persistMethod = new ReflectionMethod($synchronizer, 'persistExactCustomerInvoiceLineTotals');
	$persistMethod->setAccessible(true);
	if ($fractionalInvoice->update_price(1, '1') <= 0
		|| $fractionalInvoice->fetch((int) $fractionalInvoice->id) <= 0
		|| $fractionalInvoice->fetch_lines() < 0
		|| !$persistMethod->invoke($synchronizer, $fractionalInvoice, $fractionalPlan, 1)
		|| $fractionalInvoice->update_price(1, 'none') <= 0
		|| $fractionalInvoice->fetch((int) $fractionalInvoice->id) <= 0) {
		throw new RuntimeException($synchronizer->error ?: 'Exact fractional persistence failed');
	}
	if (ZSDecimal::round((string) $fractionalInvoice->total_ht, 2) !== '2.01'
		|| ZSDecimal::round((string) $fractionalInvoice->total_tva, 2) !== '0.46'
		|| ZSDecimal::round((string) $fractionalInvoice->total_ttc, 2) !== '2.47'
		|| ZSDecimal::round((string) $fractionalInvoice->multicurrency_total_ttc, 2) !== '2.47') {
		throw new RuntimeException('Exact fractional persistence did not reconcile to 2.01/0.46/2.47');
	}

	$supplierInvoice = new FactureFournisseur($db);
	$supplierInvoice->entity = $entity;
	$supplierInvoice->socid = (int) $supplier->rowid;
	$supplierInvoice->type = FactureFournisseur::TYPE_STANDARD;
	$supplierInvoice->date = dol_now();
	$supplierInvoice->date_echeance = dol_now() + 86400;
	$supplierInvoice->ref_supplier = 'DOLIZSYNCH-PRECISION-' . date('YmdHis');
	if ($supplierInvoice->create($actor) <= 0) {
		throw new RuntimeException($supplierInvoice->error ?: 'Unable to create supplier precision invoice');
	}
	for ($i = 1; $i <= 2; $i++) {
		$result = $supplierInvoice->addline(
			'Supplier precision base ' . $i,
			'2.37',
			'23',
			0,
			0,
			'1',
			0,
			0,
			0,
			0,
			0,
			0,
			'HT',
			1,
			-1,
			0,
			[],
			null,
			0,
			0,
			'',
			0,
			0,
			0
		);
		if ($result <= 0) {
			throw new RuntimeException($supplierInvoice->error ?: 'Unable to add supplier precision line');
		}
	}
	$supplierPlan = ZSInvoiceTaxCalculator::calculate([
		['quantity' => '1', 'unit_price' => '2.37', 'vat_rate' => '23'],
		['quantity' => '1', 'unit_price' => '2.37', 'vat_rate' => '23'],
	]);
	$converter = new ZSStoreInvoiceConverter($db);
	$supplierRounding = new ReflectionMethod($converter, 'handleRounding');
	$supplierRounding->setAccessible(true);
	if ($supplierRounding->invoke($converter, (int) $supplierInvoice->id, $supplierPlan, $entity) <= 0
		|| $supplierInvoice->fetch((int) $supplierInvoice->id) <= 0
		|| $supplierInvoice->fetch_lines() < 0
		|| ZSDecimal::round((string) $supplierInvoice->total_ht, 2) !== '4.74'
		|| ZSDecimal::round((string) $supplierInvoice->total_tva, 2) !== '1.09'
		|| ZSDecimal::round((string) $supplierInvoice->total_ttc, 2) !== '5.83'
		|| ZSDecimal::round((string) $supplierInvoice->multicurrency_total_ttc, 2) !== '5.83') {
		throw new RuntimeException($converter->error ?: 'Supplier invoice did not reconcile to 4.74/1.09/5.83');
	}
} catch (Throwable $e) {
	$failure = $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine();
} finally {
	while ((int) $db->transaction_opened > $baseline) {
		$db->rollback();
	}
	$user = $originalUser;
	if ($generatedFile !== '' && is_file($generatedFile)) {
		@unlink($generatedFile);
	}
	if ($generatedFile !== '') {
		@rmdir(dirname($generatedFile));
	}
}

if ($failure !== '') {
	fwrite(STDERR, 'FAIL: ' . $failure . "\n");
	exit(1);
}

echo "PASS: Dolibarr 23.0.3 reconciled customer and supplier invoices, rendered a 4.74/1.09/5.83 PDF, created an exact credit note, and persisted fractional 2.01/0.46/2.47\n";

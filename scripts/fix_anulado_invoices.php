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

if (PHP_SAPI !== 'cli') {
	http_response_code(403);
	exit('This maintenance script is available only from the command line.');
}

/**
 * \file    dolizsynch/scripts/fix_anulado_invoices.php
 * \ingroup dolizsynch
 * \brief   Script to fix invoices marked as anulado (canceled) in Zone Soft but not canceled in Dolibarr
 *
 * This script identifies invoices that are marked as anulado_zs = 1 in the sync table
 * but are not actually canceled in Dolibarr (fk_statut != 3). It fixes them by:
 * 1. Creating a credit note (avoir) for the invoice
 * 2. Properly canceling the invoice in Dolibarr (set_canceled method)
 * 3. Updating the sync table with the credit note reference
 *
 * Usage: php scripts/fix_anulado_invoices.php [options]
 * Options:
 *   --dry-run    : Show what would be fixed without making changes
 *   --limit=N    : Limit to N invoices (default: 30)
 *   --invoice=ID : Fix only a specific invoice ID
 *   --ref=REF    : Fix only a specific invoice reference
 */

// Load Dolibarr environment
$res = 0;
// Try main.inc.php into web root known defined into CONTEXT_DOCUMENT_ROOT
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) {
	$res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"] . "/main.inc.php";
}
// Try main.inc.php into web root detected using web root calculated from SCRIPT_FILENAME
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
// Try main.inc.php using relative path
if (!$res && file_exists("../../main.inc.php")) {
	$res = @include "../../main.inc.php";
}
if (!$res && file_exists("../../../main.inc.php")) {
	$res = @include "../../../main.inc.php";
}
if (!$res) {
	die("Include of main fails");
}

require_once DOL_DOCUMENT_ROOT . '/compta/facture/class/facture.class.php';
require_once DOL_DOCUMENT_ROOT . '/compta/paiement/class/paiement.class.php';
dol_include_once('/kreaflow/class/zsinvoicesynch.class.php');

// Parse command line arguments
$options = getopt('', ['dry-run', 'limit:', 'invoice:', 'ref:']);
$dryRun = isset($options['dry-run']);
$limit = isset($options['limit']) ? (int)$options['limit'] : 30; // Default limit of 30
$specificInvoiceId = isset($options['invoice']) ? (int)$options['invoice'] : 0;
$specificRef = isset($options['ref']) ? $options['ref'] : '';

// Output header
echo "\n";
echo "================================================================================\n";
echo "KreaFlow - Fix Anulado (Canceled) Invoices\n";
echo "================================================================================\n";
echo "Mode: " . ($dryRun ? "DRY RUN (no changes will be made)" : "LIVE (changes will be applied)") . "\n";
if ($limit > 0) {
	echo "Limit: Processing up to $limit invoice(s)\n";
}
if ($specificInvoiceId > 0) {
	echo "Specific Invoice ID: $specificInvoiceId\n";
}
if (!empty($specificRef)) {
	echo "Specific Reference: $specificRef\n";
}
echo "================================================================================\n\n";

/**
 * Find all invoices marked as anulado but not canceled in Dolibarr
 *
 * @param DoliDB $db Database handler
 * @param int $specificId Specific invoice ID to fix (0 for all)
 * @param string $specificRef Specific invoice reference to fix (empty for all)
 * @param int $limit Maximum number of invoices to process
 * @return array Array of invoice data
 */
function findAnuladoInvoices($db, $specificId = 0, $specificRef = '', $limit = 0)
{
	$sql = "SELECT zs.rowid as sync_id, zs.fk_facture, zs.invoice_ref, zs.credit_ref, zs.anulado_zs, zs.synch,";
	$sql .= " f.ref, f.ref_client, f.fk_statut, f.paye, f.fk_soc, f.datef, f.total_ttc";
	$sql .= " FROM " . MAIN_DB_PREFIX . "dolizsynch_zsinvoicesynch as zs";
	$sql .= " LEFT JOIN " . MAIN_DB_PREFIX . "facture as f ON f.rowid = zs.fk_facture";
	$sql .= " WHERE zs.anulado_zs = 1"; // Marked as canceled in ZS
	$sql .= " AND zs.fk_facture > 0"; // Has a linked invoice
	$sql .= " AND f.fk_statut != 3"; // Not canceled in Dolibarr (STATUS_ABANDONED = 3)
	$sql .= " AND f.entity IN (" . getEntity('invoice') . ")";

	if ($specificId > 0) {
		$sql .= " AND f.rowid = " . ((int) $specificId);
	}

	if (!empty($specificRef)) {
		$sql .= " AND zs.invoice_ref = '" . $db->escape($specificRef) . "'";
	}

	$sql .= " ORDER BY f.datef DESC, f.rowid DESC";

	if ($limit > 0) {
		$sql .= $db->plimit($limit);
	}

	$resql = $db->query($sql);

	if (!$resql) {
		echo "ERROR: Failed to query invoices: " . $db->lasterror() . "\n";
		return [];
	}

	$invoices = [];
	while ($obj = $db->fetch_object($resql)) {
		$invoices[] = [
			'sync_id' => $obj->sync_id,
			'id' => $obj->fk_facture,
			'ref' => $obj->ref,
			'invoice_ref' => $obj->invoice_ref,
			'ref_client' => $obj->ref_client,
			'status' => $obj->fk_statut,
			'paid' => $obj->paye,
			'socid' => $obj->fk_soc,
			'date' => $obj->datef,
			'total' => $obj->total_ttc,
			'credit_ref' => $obj->credit_ref,
			'synch_status' => $obj->synch
		];
	}

	return $invoices;
}

/**
 * Fix a single invoice by creating credit note and canceling it
 *
 * @param Facture $invoice Invoice object
 * @param User $user User object
 * @param array $invoiceData Invoice data array
 * @param bool $dryRun Whether to perform dry run
 * @return bool Success status
 */
function fixAnuladoInvoice($invoice, $user, $invoiceData, $dryRun = false)
{
	global $db, $conf;

	echo "Processing Invoice ID: {$invoiceData['id']}\n";
	echo "  Invoice Ref: {$invoiceData['ref']}\n";
	echo "  ZS Ref: {$invoiceData['invoice_ref']}\n";
	echo "  Client Ref: {$invoiceData['ref_client']}\n";
	echo "  Date: {$invoiceData['date']}\n";
	echo "  Total: " . price($invoiceData['total']) . "\n";
	echo "  Status: {$invoiceData['status']} (Dolibarr status)\n";
	echo "  Credit Ref: " . ($invoiceData['credit_ref'] ? $invoiceData['credit_ref'] : 'None') . "\n";
	echo "  Sync Status: {$invoiceData['synch_status']}\n";

	if ($dryRun) {
		if (empty($invoiceData['credit_ref'])) {
			echo "  [DRY RUN] Would create credit note and cancel invoice\n";
		} else {
			echo "  [DRY RUN] Would cancel invoice (credit note already exists)\n";
		}
		echo "  ✓ Dry run complete\n\n";
		return true;
	}

	// Start transaction
	$db->begin();

	try {
		$creditNoteId = null;

		// Step 1: Create credit note if it doesn't exist
		if (empty($invoiceData['credit_ref']) || $invoiceData['credit_ref'] == 0) {
			echo "  Step 1: Creating credit note...\n";

			// Use ZSInvoiceSynch class method to create credit note
			$zsInvoice = new ZSInvoiceSynch($db);
			$creditNoteId = $zsInvoice->createCreditNoteFromSourceInvoice($invoice->id, $user);

			if ($creditNoteId < 0) {
				throw new Exception("Failed to create credit note: " . $zsInvoice->error);
			}

			// Fetch the credit note to get its reference
			$creditNote = new Facture($db);
			if ($creditNote->fetch($creditNoteId) <= 0) {
				throw new Exception("Failed to fetch created credit note");
			}

			echo "  Credit note created: {$creditNote->ref} (ID: $creditNoteId)\n";

			// Update sync table with credit note reference
			$sql = "UPDATE " . MAIN_DB_PREFIX . "dolizsynch_zsinvoicesynch";
			$sql .= " SET credit_ref = " . ((int) $creditNoteId);
			$sql .= " WHERE invoice_ref = '" . $db->escape($invoiceData['invoice_ref']) . "'";

			if (!$db->query($sql)) {
				throw new Exception("Failed to update sync table with credit_ref: " . $db->lasterror());
			}
		} else {
			echo "  Step 1: Credit note already exists (ID: {$invoiceData['credit_ref']})\n";
		}

		// Step 2: Cancel the invoice in Dolibarr
		echo "  Step 2: Canceling invoice in Dolibarr...\n";

		// Refresh invoice object
		$invoice->fetch($invoiceData['id']);

		// Use set_canceled method with reason
		$result = $invoice->set_canceled($user, Facture::CLOSE_CODE_REPLACED, 'Anulado em Zone Soft');

		if ($result < 0) {
			throw new Exception("Failed to cancel invoice: " . $invoice->error);
		}

		echo "  Invoice canceled successfully\n";

		// Step 3: Update sync status
		echo "  Step 3: Updating sync status...\n";

		$sql = "UPDATE " . MAIN_DB_PREFIX . "dolizsynch_zsinvoicesynch";
		$sql .= " SET synch = 3"; // CREDIT_PROCESSED
		$sql .= " WHERE invoice_ref = '" . $db->escape($invoiceData['invoice_ref']) . "'";

		if (!$db->query($sql)) {
			throw new Exception("Failed to update sync status: " . $db->lasterror());
		}

		// Commit transaction
		$db->commit();

		echo "  ✓ Successfully fixed anulado invoice\n";
		echo "    Invoice {$invoiceData['ref']} canceled in Dolibarr\n\n";

		return true;

	} catch (Exception $e) {
		$db->rollback();
		echo "  ✗ ERROR: " . $e->getMessage() . "\n\n";
		return false;
	}
}

// Main execution
echo "Step 1: Finding invoices marked as anulado but not canceled...\n";
$anuladoInvoices = findAnuladoInvoices($db, $specificInvoiceId, $specificRef, $limit);

$totalFound = count($anuladoInvoices);
echo "Found $totalFound invoice(s) to fix\n\n";

if ($totalFound === 0) {
	echo "No invoices found that need fixing.\n";
	exit(0);
}

// Display summary
echo "Summary of invoices to fix:\n";
echo "----------------------------------------\n";
foreach ($anuladoInvoices as $inv) {
	echo "ID: {$inv['id']}, Ref: {$inv['ref']}, ZS Ref: {$inv['invoice_ref']}, Total: " . price($inv['total']) . "\n";
}
echo "----------------------------------------\n\n";

if ($dryRun) {
	echo "NOTE: Running in DRY RUN mode - no changes will be made\n\n";
} else {
	echo "WARNING: This will create credit notes and cancel invoices in the database!\n";
	echo "Press Ctrl+C within 5 seconds to cancel...\n";
	sleep(5);
	echo "\nProceeding with fixes...\n\n";
}

// Process each invoice
$successCount = 0;
$failCount = 0;

foreach ($anuladoInvoices as $invoiceData) {
	$invoice = new Facture($db);
	if ($invoice->fetch($invoiceData['id']) > 0) {
		if (fixAnuladoInvoice($invoice, $user, $invoiceData, $dryRun)) {
			$successCount++;
		} else {
			$failCount++;
		}
	} else {
		echo "ERROR: Could not load invoice ID {$invoiceData['id']}\n\n";
		$failCount++;
	}
}

// Final summary
echo "================================================================================\n";
echo "Fix Complete\n";
echo "================================================================================\n";
echo "Total invoices processed: $totalFound\n";
echo "Successfully fixed: $successCount\n";
echo "Failed: $failCount\n";
echo "================================================================================\n";

if ($dryRun) {
	echo "\nThis was a DRY RUN. Run without --dry-run to apply changes.\n";
}

echo "\n";
exit($failCount > 0 ? 1 : 0);

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
 * \file    dolizsynch/scripts/fix_synch3_invoices.php
 * \ingroup dolizsynch
 * \brief   Script to fix invoices with synch=3 (CANCELED_NEEDS_CREDIT) that should be canceled instead
 *
 * This script identifies invoices that have synch=3 and anulado_zs=1 but have not been
 * properly canceled in Dolibarr. It fixes them by resetting synch=0 so they can be
 * processed by the main synchronization logic which will:
 * 1. Remove payments
 * 2. Return stock
 * 3. Cancel the invoice
 * 4. Set final synch status to OK
 *
 * Usage: php scripts/fix_synch3_invoices.php [options]
 * Options:
 *   --dry-run    : Show what would be fixed without making changes
 *   --limit=N    : Limit to N invoices (default: all)
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

// Parse command line arguments
$options = getopt('', ['dry-run', 'limit:', 'invoice:', 'ref:']);
$dryRun = isset($options['dry-run']);
$limit = isset($options['limit']) ? (int)$options['limit'] : 0; // Default: all invoices
$specificInvoiceId = isset($options['invoice']) ? (int)$options['invoice'] : 0;
$specificRef = isset($options['ref']) ? $options['ref'] : '';

// Output header
echo "\n";
echo "================================================================================\n";
echo "KreaFlow - Fix synch=3 (CANCELED_NEEDS_CREDIT) Invoices\n";
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
 * Find all invoices with synch=3 and anulado_zs=1
 *
 * @param DoliDB $db Database handler
 * @param int $specificId Specific invoice ID to fix (0 for all)
 * @param string $specificRef Specific reference to fix (empty for all)
 * @param int $limit Maximum number of invoices to process
 * @return array Array of invoice data
 */
function findSynch3Invoices($db, $specificId = 0, $specificRef = '', $limit = 0)
{
	$sql = "SELECT zs.rowid as sync_id, zs.fk_facture, zs.invoice_ref, zs.credit_ref, zs.anulado_zs, zs.synch,";
	$sql .= " f.ref, f.ref_client, f.fk_statut, f.paye, f.fk_soc, f.datef, f.total_ttc";
	$sql .= " FROM " . MAIN_DB_PREFIX . "dolizsynch_zsinvoicesynch as zs";
	$sql .= " LEFT JOIN " . MAIN_DB_PREFIX . "facture as f ON f.rowid = zs.fk_facture";
	$sql .= " WHERE zs.synch = 3"; // CANCELED_NEEDS_CREDIT
	$sql .= " AND zs.anulado_zs = 1"; // Marked as canceled in ZS
	$sql .= " AND (f.entity IN (" . getEntity('invoice') . ") OR zs.fk_facture = 0)";

	if ($specificId > 0) {
		$sql .= " AND zs.fk_facture = " . ((int) $specificId);
	}

	if (!empty($specificRef)) {
		$sql .= " AND zs.invoice_ref = '" . $db->escape($specificRef) . "'";
	}

	$sql .= " ORDER BY zs.rowid DESC";

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
 * Fix a single invoice by resetting synch status to 0
 *
 * @param array $invoiceData Invoice data array
 * @param bool $dryRun Whether to perform dry run
 * @return bool Success status
 */
function fixSynch3Invoice($invoiceData, $dryRun = false)
{
	global $db;

	echo "Processing Invoice:\n";
	echo "  Sync ID: {$invoiceData['sync_id']}\n";
	echo "  ZS Ref: {$invoiceData['invoice_ref']}\n";

	if ($invoiceData['id'] > 0) {
		echo "  Invoice ID: {$invoiceData['id']}\n";
		echo "  Invoice Ref: {$invoiceData['ref']}\n";
		echo "  Client Ref: {$invoiceData['ref_client']}\n";
		echo "  Date: {$invoiceData['date']}\n";
		echo "  Total: " . price($invoiceData['total']) . "\n";
		echo "  Dolibarr Status: {$invoiceData['status']}\n";

		// Check if already canceled
		if ($invoiceData['status'] == 3) {
			echo "  [INFO] Invoice already canceled in Dolibarr (status=3)\n";
			if (!$dryRun) {
				// Update to synch=1 (OK) since it's already canceled
				$sql = "UPDATE " . MAIN_DB_PREFIX . "dolizsynch_zsinvoicesynch";
				$sql .= " SET synch = 1";
				$sql .= " WHERE invoice_ref = '" . $db->escape($invoiceData['invoice_ref']) . "'";

				if (!$db->query($sql)) {
					echo "  ✗ ERROR: Failed to update sync status: " . $db->lasterror() . "\n\n";
					return false;
				}
				echo "  ✓ Updated sync status to OK (already canceled)\n\n";
			} else {
				echo "  [DRY RUN] Would update sync status to OK\n\n";
			}
			return true;
		}
	} else {
		echo "  Invoice ID: Not created yet (fk_facture=0)\n";
	}

	echo "  Current Sync Status: {$invoiceData['synch_status']} (CANCELED_NEEDS_CREDIT)\n";
	echo "  Credit Ref: " . ($invoiceData['credit_ref'] ? $invoiceData['credit_ref'] : 'None') . "\n";

	if ($dryRun) {
		echo "  [DRY RUN] Would reset synch=0 to allow proper cancellation processing\n";
		echo "  ✓ Dry run complete\n\n";
		return true;
	}

	// Reset synch status to 0 (NOT_SYNCHRONIZED)
	// This will cause the synchronization process to pick it up and properly:
	// 1. Create the invoice if needed (fk_facture=0)
	// 2. Remove payments
	// 3. Return stock
	// 4. Cancel the invoice using setCanceled()
	// 5. Set final synch status to OK
	$sql = "UPDATE " . MAIN_DB_PREFIX . "dolizsynch_zsinvoicesynch";
	$sql .= " SET synch = 0"; // NOT_SYNCHRONIZED
	$sql .= " WHERE invoice_ref = '" . $db->escape($invoiceData['invoice_ref']) . "'";

	if (!$db->query($sql)) {
		echo "  ✗ ERROR: Failed to reset sync status: " . $db->lasterror() . "\n\n";
		return false;
	}

	echo "  ✓ Successfully reset synch=0\n";
	echo "    Invoice will be processed by synchronizeAllUnsynchronizedInvoicesByStore\n\n";

	return true;
}

// Main execution
echo "Step 1: Finding invoices with synch=3 and anulado_zs=1...\n";
$synch3Invoices = findSynch3Invoices($db, $specificInvoiceId, $specificRef, $limit);

$totalFound = count($synch3Invoices);
echo "Found $totalFound invoice(s) to fix\n\n";

if ($totalFound === 0) {
	echo "No invoices found that need fixing.\n";
	exit(0);
}

// Display summary
echo "Summary of invoices to fix:\n";
echo "----------------------------------------\n";
foreach ($synch3Invoices as $inv) {
	$statusText = $inv['id'] > 0 ? "Exists (status={$inv['status']})" : "Not created";
	echo "Sync ID: {$inv['sync_id']}, ZS Ref: {$inv['invoice_ref']}, Dolibarr: $statusText\n";
}
echo "----------------------------------------\n\n";

if ($dryRun) {
	echo "NOTE: Running in DRY RUN mode - no changes will be made\n\n";
} else {
	echo "WARNING: This will reset sync status in the database!\n";
	echo "Press Ctrl+C within 5 seconds to cancel...\n";
	sleep(5);
	echo "\nProceeding with fixes...\n\n";
}

// Process each invoice
$successCount = 0;
$failCount = 0;

foreach ($synch3Invoices as $invoiceData) {
	if (fixSynch3Invoice($invoiceData, $dryRun)) {
		$successCount++;
	} else {
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
} else {
	echo "\nNext steps:\n";
	echo "1. Run the synchronization process to cancel the invoices:\n";
	echo "   - Go to KreaFlow module admin page\n";
	echo "   - Click 'Synchronize Invoices' button\n";
	echo "2. The invoices will be properly canceled with:\n";
	echo "   - Payments removed\n";
	echo "   - Stock returned to warehouse\n";
	echo "   - Invoice status set to canceled (status=3)\n";
	echo "   - Sync status set to OK (synch=1)\n";
}

echo "\n";
exit($failCount > 0 ? 1 : 0);

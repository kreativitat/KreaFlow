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
 * \file    dolizsynch/scripts/fix_prov_invoices.php
 * \ingroup dolizsynch
 * \brief   Script to fix invoices with PROV references by properly validating them
 *
 * This script identifies invoices that have PROV references (provisional references)
 * that are in paid status but were never properly validated. It fixes them by:
 * 1. Reverting the invoice to draft status temporarily
 * 2. Properly validating the invoice to get a permanent reference
 * 3. Re-applying the paid status with existing payments
 *
 * Usage: php scripts/fix_prov_invoices.php [options]
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

// Parse command line arguments
$options = getopt('', ['dry-run', 'limit:', 'invoice:', 'ref:']);
$dryRun = isset($options['dry-run']);
$limit = isset($options['limit']) ? (int)$options['limit'] : 30; // Default limit of 30
$specificInvoiceId = isset($options['invoice']) ? (int)$options['invoice'] : 0;
$specificRef = isset($options['ref']) ? $options['ref'] : '';

// Output header
echo "\n";
echo "================================================================================\n";
echo "KreaFlow - Fix PROV Invoice References\n";
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
 * Find all invoices with PROV references that are in paid status
 *
 * @param DoliDB $db Database handler
 * @param int $specificId Specific invoice ID to fix (0 for all)
 * @param string $specificRef Specific reference to fix (empty for all)
 * @param int $limit Maximum number of invoices to process
 * @return array Array of invoice data
 */
function findProvInvoices($db, $specificId = 0, $specificRef = '', $limit = 0)
{
	$sql = "SELECT f.rowid, f.ref, f.ref_client, f.fk_statut, f.paye, f.fk_soc, f.datef, f.total_ttc";
	$sql .= " FROM " . MAIN_DB_PREFIX . "facture as f";
	$sql .= " WHERE f.ref LIKE '%PROV%'";
	$sql .= " AND f.fk_statut = 2"; // STATUS_VALIDATED but with paid status
	$sql .= " AND f.paye = 1"; // Marked as paid
	$sql .= " AND f.entity IN (" . getEntity('invoice') . ")";

	if ($specificId > 0) {
		$sql .= " AND f.rowid = " . ((int) $specificId);
	}

	if (!empty($specificRef)) {
		$sql .= " AND f.ref = '" . $db->escape($specificRef) . "'";
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
			'id' => $obj->rowid,
			'ref' => $obj->ref,
			'ref_client' => $obj->ref_client,
			'status' => $obj->fk_statut,
			'paid' => $obj->paye,
			'socid' => $obj->fk_soc,
			'date' => $obj->datef,
			'total' => $obj->total_ttc
		];
	}

	return $invoices;
}

/**
 * Get all payments associated with an invoice
 *
 * @param DoliDB $db Database handler
 * @param int $invoiceId Invoice ID
 * @return array Array of payment data
 */
function getInvoicePayments($db, $invoiceId)
{
	$sql = "SELECT p.rowid, p.datep, p.amount, p.fk_paiement";
	$sql .= " FROM " . MAIN_DB_PREFIX . "paiement_facture as pf";
	$sql .= " LEFT JOIN " . MAIN_DB_PREFIX . "paiement as p ON p.rowid = pf.fk_paiement";
	$sql .= " WHERE pf.fk_facture = " . ((int) $invoiceId);
	$sql .= " ORDER BY p.datep ASC";

	$resql = $db->query($sql);

	if (!$resql) {
		return [];
	}

	$payments = [];
	while ($obj = $db->fetch_object($resql)) {
		$payments[] = [
			'id' => $obj->rowid,
			'date' => $obj->datep,
			'amount' => $obj->amount,
			'type' => $obj->fk_paiement
		];
	}

	return $payments;
}

/**
 * Fix a single invoice by properly validating it
 *
 * @param Facture $invoice Invoice object
 * @param User $user User object
 * @param array $invoiceData Invoice data array
 * @param bool $dryRun Whether to perform dry run
 * @return bool Success status
 */
function fixInvoice($invoice, $user, $invoiceData, $dryRun = false)
{
	global $db, $conf;

	echo "Processing Invoice ID: {$invoiceData['id']}\n";
	echo "  Current Ref: {$invoiceData['ref']}\n";
	echo "  Client Ref: {$invoiceData['ref_client']}\n";
	echo "  Date: {$invoiceData['date']}\n";
	echo "  Total: " . price($invoiceData['total']) . "\n";
	echo "  Status: {$invoiceData['status']} (Paid: {$invoiceData['paid']})\n";

	// Get payments before modification
	$payments = getInvoicePayments($db, $invoiceData['id']);
	echo "  Payments found: " . count($payments) . "\n";

	if ($dryRun) {
		echo "  [DRY RUN] Would revert to draft, validate, and re-apply paid status\n";
		echo "  ✓ Dry run complete\n\n";
		return true;
	}

	// Start transaction
	$db->begin();

	try {
		// Step 1: Revert invoice to draft status
		// We need to manually update the database since there's no built-in method to revert paid invoice
		echo "  Step 1: Reverting to draft status...\n";

		$sql = "UPDATE " . MAIN_DB_PREFIX . "facture";
		$sql .= " SET fk_statut = 0, paye = 0"; // STATUS_DRAFT
		$sql .= " WHERE rowid = " . ((int) $invoiceData['id']);

		$resql = $db->query($sql);
		if (!$resql) {
			throw new Exception("Failed to revert invoice to draft: " . $db->lasterror());
		}

		// Clear the cached object
		$invoice->fetch($invoiceData['id']);

		// Step 2: Validate the invoice properly
		echo "  Step 2: Validating invoice...\n";

		$warehouseId = $conf->global->MAIN_DEFAULT_WAREHOUSE ?? '';
		$result = $invoice->validate($user, '', $warehouseId);

		if ($result < 0) {
			throw new Exception("Validation failed: " . $invoice->error);
		}

		// Fetch the new reference
		$invoice->fetch($invoiceData['id']);
		echo "  New Ref: {$invoice->ref}\n";

		// Step 3: Re-apply paid status if payments exist
		if (!empty($payments)) {
			echo "  Step 3: Re-applying paid status...\n";

			// The payments should still be linked, we just need to update the paid flag
			$remainToPay = $invoice->getSommePaiement();

			if (abs($invoice->total_ttc - $remainToPay) < 0.01) {
				$result = $invoice->set_paid($user);
				if ($result < 0) {
					throw new Exception("Failed to set paid status: " . $invoice->error);
				}
				echo "  Paid status applied\n";
			} else {
				echo "  Warning: Invoice not fully paid (remain: " . price($invoice->total_ttc - $remainToPay) . ")\n";
			}
		}

		// Commit transaction
		$db->commit();

		echo "  ✓ Successfully fixed invoice\n";
		echo "    Old Ref: {$invoiceData['ref']} → New Ref: {$invoice->ref}\n\n";

		return true;
	} catch (Exception $e) {
		$db->rollback();
		echo "  ✗ ERROR: " . $e->getMessage() . "\n\n";
		return false;
	}
}

// Main execution
echo "Step 1: Finding invoices with PROV references...\n";
$provInvoices = findProvInvoices($db, $specificInvoiceId, $specificRef, $limit);

$totalFound = count($provInvoices);
echo "Found $totalFound invoice(s) to fix\n\n";

if ($totalFound === 0) {
	echo "No invoices found that need fixing.\n";
	exit(0);
}

// Display summary
echo "Summary of invoices to fix:\n";
echo "----------------------------------------\n";
foreach ($provInvoices as $inv) {
	echo "ID: {$inv['id']}, Ref: {$inv['ref']}, Total: " . price($inv['total']) . "\n";
}
echo "----------------------------------------\n\n";

if ($dryRun) {
	echo "NOTE: Running in DRY RUN mode - no changes will be made\n\n";
} else {
	echo "WARNING: This will modify invoices in the database!\n";
	echo "Press Ctrl+C within 5 seconds to cancel...\n";
	sleep(5);
	echo "\nProceeding with fixes...\n\n";
}

// Process each invoice
$successCount = 0;
$failCount = 0;

foreach ($provInvoices as $invoiceData) {
	$invoice = new Facture($db);
	if ($invoice->fetch($invoiceData['id']) > 0) {
		if (fixInvoice($invoice, $user, $invoiceData, $dryRun)) {
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

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
 * \file        scripts/fix_zsinvoicesynch_schema.php
 * \ingroup     DoliZSynch
 * \brief       Emergency fix script to add missing columns to zsinvoicesynch table
 *
 * This script adds the missing columns that ZSSyncManager requires:
 * - date_modification
 * - error_message
 * - sync_context
 *
 * Run this script to immediately fix the database schema issue causing the invoice sync errors.
 *
 * Usage: php scripts/fix_zsinvoicesynch_schema.php
 */

// Change this following line to use the correct relative path from htdocs or from html
$res = 0;
if (!$res && file_exists("../../main.inc.php")) {
	$res = include "../../main.inc.php";
}
if (!$res && file_exists("../../../main.inc.php")) {
	$res = include "../../../main.inc.php";
}
if (!$res && file_exists("../../../../main.inc.php")) {
	$res = include "../../../../main.inc.php";
}
if (!$res && file_exists("../../../../../main.inc.php")) {
	$res = include "../../../../../main.inc.php";
}
if (!$res) {
	die("Include of main fails. Check path to main.inc.php");
}

// Check we have database access
if (!isset($db) || !$db) {
	die("Database connection not available");
}

$tableName = MAIN_DB_PREFIX . "dolizsynch_zsinvoicesynch";

echo "KreaFlow Schema Fix Script\n";
echo "===========================\n\n";
echo "Checking table: " . $tableName . "\n\n";

// Check if date_modification column exists
$sql = "SHOW COLUMNS FROM " . $tableName . " LIKE 'date_modification'";
$result = $db->query($sql);

if (!$result) {
	die("Error checking for date_modification column: " . $db->lasterror() . "\n");
}

if ($db->num_rows($result) == 0) {
	echo "Missing columns detected. Adding required columns for ZSSyncManager...\n\n";

	$migrationSql = [
		"ALTER TABLE " . $tableName . " ADD COLUMN date_modification datetime NULL AFTER synch",
		"ALTER TABLE " . $tableName . " ADD COLUMN error_message text NULL AFTER date_modification",
		"ALTER TABLE " . $tableName . " ADD COLUMN sync_context text NULL AFTER error_message",
		"UPDATE " . $tableName . " SET date_modification = date_creation WHERE date_modification IS NULL"
	];

	$success = true;
	foreach ($migrationSql as $sql) {
		echo "Running: " . $sql . "\n";
		$result = $db->query($sql);
		if (!$result) {
			echo "ERROR: " . $db->lasterror() . "\n";
			$success = false;
			break;
		} else {
			echo "SUCCESS\n";
		}
	}

	if ($success) {
		echo "\n✅ Schema migration completed successfully!\n";
		echo "The zsinvoicesynch table now has all required columns for ZSSyncManager.\n";
		echo "Invoice synchronization errors should be resolved.\n";
	} else {
		echo "\n❌ Schema migration failed!\n";
		echo "Please check the errors above and fix manually.\n";
		exit(1);
	}
} else {
	echo "✅ Schema is already up to date!\n";
	echo "The date_modification column already exists, so all required columns should be present.\n";
}

echo "\nChecking unique index on invoice_ref...\n";
$targetIndexName = 'idx_dolizsynch_zsinvoicesynch_invoice_ref';
$dropCandidates = array('1', 'idx_dolizsynch_zsinvoicesynch_invoice_ref', 'idx_dolizsynch_zsinvoicesynch_invoice_ref_entity');

foreach ($dropCandidates as $dropIndex) {
	$sqlDrop = "ALTER TABLE " . $tableName . " DROP INDEX IF EXISTS `" . $db->escape($dropIndex) . "`";
	echo "Running: " . $sqlDrop . "\n";
	if (!$db->query($sqlDrop)) {
		echo "ERROR dropping index " . $dropIndex . ": " . $db->lasterror() . "\n";
		exit(1);
	}
}

$sqlAdd = "ALTER TABLE " . $tableName . " ADD UNIQUE " . $targetIndexName . " (invoice_ref, entity)";
echo "Running: " . $sqlAdd . "\n";
if (!$db->query($sqlAdd)) {
	echo "ERROR creating composite index: " . $db->lasterror() . "\n";
	echo "NOTE: If you have duplicated (invoice_ref, entity) rows, clean them first.\n";
	exit(1);
}
echo "SUCCESS\n";

echo "\nChecking facture_extrafields index on invoice_number...\n";
$factureExtraTable = MAIN_DB_PREFIX . "facture_extrafields";
$invoiceNumberIndexCandidates = array('invoice_number', 'idx_facture_extrafields_invoice_number');

foreach ($invoiceNumberIndexCandidates as $dropIndex) {
	$sqlDrop = "ALTER TABLE " . $factureExtraTable . " DROP INDEX IF EXISTS `" . $db->escape($dropIndex) . "`";
	echo "Running: " . $sqlDrop . "\n";
	if (!$db->query($sqlDrop)) {
		echo "ERROR dropping index " . $dropIndex . ": " . $db->lasterror() . "\n";
		exit(1);
	}
}

$sqlAddNonUnique = "ALTER TABLE " . $factureExtraTable . " ADD INDEX idx_facture_extrafields_invoice_number (invoice_number)";
echo "Running: " . $sqlAddNonUnique . "\n";
if (!$db->query($sqlAddNonUnique)) {
	echo "ERROR creating non-unique invoice_number index: " . $db->lasterror() . "\n";
	exit(1);
}
echo "SUCCESS\n";

echo "\nScript completed.\n";
?>

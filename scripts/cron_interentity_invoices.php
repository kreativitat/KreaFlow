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
 * \file        scripts/cron_interentity_invoices.php
 * \ingroup     dolizsynch
 * \brief       Cron script for inter-entity invoice conversion
 *              Converts customer invoices from seller entity to supplier invoices in buyer entities
 *
 * This script can be called:
 * - From command line: php scripts/cron_interentity_invoices.php [date]
 * - From Dolibarr cron module
 * - From external cron job
 */

// Load Dolibarr environment
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
if (!$res) {
	die("Include of main.inc.php failed");
}

require_once DOL_DOCUMENT_ROOT . '/custom/kreaflow/class/zsstoreinvoiceconverter.class.php';

global $db, $user, $conf, $langs;

// Load translations
$langs->loadLangs(array("main", "dolizsynch@kreaflow"));

// Check if running from CLI or web
$sapi_type = php_sapi_name();
$script_file = basename(__FILE__);
$path = __DIR__ . '/';

// Ensure user exists for database operations
if (!is_object($user) || empty($user->id)) {
	if (php_sapi_name() === 'cli') {
		// Running from CLI - load admin user
		$result = $user->fetch('', 'admin');
		if ($result <= 0) {
			print "Error: Could not load admin user\n";
			exit(1);
		}
	} else {
		// Running from web - require login
		if (!$user->rights->dolizsynch->admin) {
			accessforbidden();
		}
	}
}

// Parse command line arguments
$targetDate = null;
if ($sapi_type == 'cli') {
	// Get date from command line argument if provided
	if (!empty($argv[1])) {
		$targetDate = $argv[1];
		// Validate date format
		if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $targetDate)) {
			print "Error: Invalid date format. Use YYYY-MM-DD\n";
			print "Usage: php $script_file [YYYY-MM-DD]\n";
			exit(1);
		}
	}
}

// Log start
$message = "Starting inter-entity invoice conversion";
if ($targetDate) {
	$message .= " for date: $targetDate";
} else {
	$message .= " for today";
}
dol_syslog($message, LOG_INFO);
if ($sapi_type == 'cli') {
	print "$message\n";
}

// Create converter instance
$converter = new ZSStoreInvoiceConverter($db);

// Process invoices
$result = $converter->processInterEntityInvoices($targetDate);

if ($result > 0) {
	$message = "Success: Processed $result invoice(s)";
	dol_syslog($message, LOG_INFO);
	if ($sapi_type == 'cli') {
		print "$message\n";
	}
	exit(0);
} elseif ($result == 0) {
	$message = "Info: No invoices found to process";
	dol_syslog($message, LOG_INFO);
	if ($sapi_type == 'cli') {
		print "$message\n";
	}
	exit(0);
} else {
	$message = "Error: " . $converter->error;
	dol_syslog($message, LOG_ERR);
	if ($sapi_type == 'cli') {
		print "$message\n";
		if (!empty($converter->errors)) {
			foreach ($converter->errors as $error) {
				print "  - $error\n";
			}
		}
	}
	exit(1);
}

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
 * Document serving script for DoliZSynch Leave module
 * Handles secure access and delivery of leave-related documents
 */

require '../../../main.inc.php';
require_once DOL_DOCUMENT_ROOT . '/core/lib/files.lib.php';
require_once __DIR__ . '/../class/zsleaves.class.php';

global $conf, $db, $langs, $user;

// Check module is enabled
if (empty($conf->dolizsynch->enabled)) {
	accessforbidden('Module not enabled');
}

// Check user has permission to read
if (empty($user->rights->dolizsynch->shift->read)) {
	accessforbidden('No permission to access leaves');
}

// Get request parameters
$action = GETPOST('action', 'aZ09');
$original_file = GETPOST('file', 'alpha');
$modulepart = GETPOST('modulepart', 'aZ09');  // Should be 'dolizsynch'
$entity = GETPOSTINT('entity') ? GETPOSTINT('entity') : $conf->entity;

// Sanitize and validate file path
if (!$original_file) {
	dol_print_error_email('ErrorFileNameMissing');
	exit;
}

// The file path should be: leaves/{id}/filename.ext
// Extract the leave ID from the path
$matches = array();
if (preg_match('/^leaves\/(\d+)\/(.+)$/', $original_file, $matches)) {
	$leave_id = (int) $matches[1];
	$filename = $matches[2];
} else {
	accessforbidden('Invalid file path format');
	exit;
}

// Security check: verify the leave exists and user has access
$leave = new ZSLeaves($db);
$result = $leave->fetch($leave_id);
if ($result <= 0) {
	accessforbidden('Leave record not found');
	exit;
}

// Check if user has write permission for delete actions
if ($action === 'delete' && empty($user->rights->dolizsynch->shift->write)) {
	accessforbidden('No permission to delete documents');
	exit;
}

// Build the full file path
$upload_dir_base = $conf->dolizsynch->multidir_output[$entity];
$original_file_osencoded = dol_sanitizePathName($original_file);
$fullpath_original_file_osencoded = $upload_dir_base . '/' . $original_file_osencoded;

// Security: Check file is within allowed directory
$fullpath_original_file_osencoded = dol_sanitizePathName($fullpath_original_file_osencoded);
if (!preg_match('/\.\./', $fullpath_original_file_osencoded)) {
	$original_file_osencoded = $fullpath_original_file_osencoded;
}

// Verify file exists
if (!dol_is_file($original_file_osencoded)) {
	dol_print_error_email('ErrorFileNotFound', $original_file);
	exit;
}

// Handle actions
if ($action === 'delete') {
	// Delete the file
	$result = dol_delete_file($original_file_osencoded);
	if ($result) {
		setEventMessages($langs->trans("FileWasRemoved"), null, 'mesgs');
	} else {
		setEventMessages($langs->trans("ErrorFailedToDeleteFile"), null, 'errors');
	}
	// Redirect back to the leave card
	header('Location: ' . dol_buildpath('/kreaflow/leaves/card.php?id=' . $leave_id, 1));
	exit;
}

// Default action: serve the file
$attachment = true;
if (GETPOSTINT('attachment')) {
	$attachment = GETPOSTINT('attachment');
}
$type = dol_mimetype($original_file_osencoded, '', 2);

// Output the file
top_httphead($type);
header('Content-Description: File Transfer');
header('Content-Type: ' . $type);
if ($attachment) {
	header('Content-Disposition: attachment; filename="' . basename($original_file_osencoded) . '"');
} else {
	header('Content-Disposition: inline; filename="' . basename($original_file_osencoded) . '"');
}
header('Content-Transfer-Encoding: binary');
header('Content-Length: ' . dol_filesize($original_file_osencoded));
header('Pragma: public');

// Read file and output
readfileLowMemory($original_file_osencoded);
exit;

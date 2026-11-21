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
 * \file    dolizsynch/lib/dolizsynch.lib.php
 * \ingroup dolizsynch
 * \brief   Library files with common functions for DoliZSynch
 */

/**
 * Prepare admin pages header
 *
 * @return array
 */
function dolizsynchAdminPrepareHead()
{
	global $langs, $conf;

	// global $db;
	// $extrafields = new ExtraFields($db);
	// $extrafields->fetch_name_optionals_label('zspunchclock');

	$langs->loadLangs(array("dolizsynch@kreaflow", "kreaflow@kreaflow"));

	$h = 0;
	$head = array();

	$head[$h][0] = dol_buildpath("/kreaflow/admin/setup.php", 1);
	$head[$h][1] = $langs->trans("Settings");
	$head[$h][2] = 'settings';
	$h++;

	$head[$h][0] = dol_buildpath("/kreaflow/admin/mapping.php", 1);
	$head[$h][1] = $langs->trans("Mapping");
	$head[$h][2] = 'mapping';
	$h++;

	$head[$h][0] = dol_buildpath("/kreaflow/admin/setup_planner.php", 1);
	$head[$h][1] = $langs->trans("PlannerSetupTab");
	$head[$h][2] = 'planner_setup';
	$h++;

	$head[$h][0] = dol_buildpath("/kreaflow/admin/telegram_setup.php", 1);
	$head[$h][1] = $langs->trans("TelegramSetup");
	$head[$h][2] = 'telegram_setup';
	$h++;

	$head[$h++] = array(dol_buildpath('/kreaflow/admin/mobile_setup.php', 1), $langs->trans('KreaFlowMobileSettings'), 'mobile_settings');
	$head[$h++] = array(dol_buildpath('/kreaflow/admin/kitchen.php', 1), $langs->trans('KitchenSettings'), 'kitchen');

	// Product Equivalence tab - only show if feature is enabled and multicompany is active
	if (isModEnabled('multicompany') && getDolGlobalString('DOLIZSYNCH_INTERENTITY_INVOICE_ENABLED')) {
		$head[$h][0] = dol_buildpath("/kreaflow/admin/product_equivalence.php", 1);
		$head[$h][1] = $langs->trans("ProductEquivalence");
		$head[$h][2] = 'product_equivalence';
		$h++;
	}

	/*
	$head[$h][0] = dol_buildpath("/kreaflow/admin/zspunchclock_extrafields.php", 1);
	$head[$h][1] = $langs->trans("ExtraFields");
	$nbExtrafields = is_countable($extrafields->attributes['zspunchclock']['label']) ? count($extrafields->attributes['zspunchclock']['label']) : 0;
	if ($nbExtrafields > 0) {
		$head[$h][1] .= ' <span class="badge">' . $nbExtrafields . '</span>';
	}
	$head[$h][2] = 'zspunchclock_extrafields';
	$h++;
	*/

	$head[$h][0] = dol_buildpath("/kreaflow/admin/about.php", 1);
	$head[$h][1] = $langs->trans("About");
	$head[$h][2] = 'about';
	$h++;

	// Show more tabs from modules
	// Entries must be declared in modules descriptor with line
	//$this->tabs = array(
	//	'entity:+tabname:Title:@kreaflow:/kreaflow/mypage.php?id=__ID__'
	//); // to add new tab
	//$this->tabs = array(
	//	'entity:-tabname:Title:@kreaflow:/kreaflow/mypage.php?id=__ID__'
	//); // to remove a tab
	complete_head_from_modules($conf, $langs, null, $head, $h, 'dolizsynch@kreaflow');

	complete_head_from_modules($conf, $langs, null, $head, $h, 'dolizsynch@kreaflow', 'remove');

	return $head;
}

/**
 * Normalize a ZSBMS document type code.
 *
 * @param string $docType Raw document type
 * @return string Normalized document type
 */
function dolizsynch_normalize_zsbms_document_type($docType)
{
	return strtoupper(trim((string) $docType));
}

/**
 * Return document types excluded from invoice synchronization flows.
 *
 * The default excludes EC customer-order documents so they cannot be imported as
 * Dolibarr invoices or exported as inter-entity invoice documents.
 *
 * @param string|null $rawValue Optional raw configuration value
 * @return array<int,string>
 */
function dolizsynch_get_excluded_zsbms_document_types($rawValue = null)
{
	if ($rawValue === null) {
		$rawValue = getDolGlobalString('DOLIZSYNCH_EXCLUDED_ZSBMS_DOCUMENT_TYPES', 'EC');
	}

	$tokens = preg_split('/[,\s;|]+/', (string) $rawValue, -1, PREG_SPLIT_NO_EMPTY);
	if (empty($tokens)) {
		return array();
	}

	$documentTypes = array();
	foreach ($tokens as $token) {
		$documentType = dolizsynch_normalize_zsbms_document_type($token);
		if ($documentType === '') {
			continue;
		}

		$documentTypes[$documentType] = $documentType;
	}

	return array_values($documentTypes);
}

/**
 * Check whether a ZSBMS document type is excluded from invoice synchronization flows.
 *
 * @param string $docType Raw document type
 * @param string|null $rawValue Optional raw configuration value
 * @return bool
 */
function dolizsynch_is_excluded_zsbms_document_type($docType, $rawValue = null)
{
	$documentType = dolizsynch_normalize_zsbms_document_type($docType);
	if ($documentType === '') {
		return false;
	}

	return in_array($documentType, dolizsynch_get_excluded_zsbms_document_types($rawValue), true);
}

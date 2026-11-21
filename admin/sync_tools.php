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
 * \file    dolizsynch/admin/sync_tools.php
 * \ingroup dolizsynch
 * \brief   DoliZSynch synchronization tools page.
 */

// Load Dolibarr environment
$res = 0;
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) {
	$res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"] . "/main.inc.php";
}
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
if (!$res && file_exists("../../main.inc.php")) {
	$res = @include "../../main.inc.php";
}
if (!$res && file_exists("../../../main.inc.php")) {
	$res = @include "../../../main.inc.php";
}
if (!$res) {
	die("Include of main fails");
}

global $langs, $user, $conf;

// Libraries
require_once DOL_DOCUMENT_ROOT . "/core/lib/admin.lib.php";
require_once DOL_DOCUMENT_ROOT . "/core/class/html.form.class.php";
require_once DOL_DOCUMENT_ROOT . "/custom/kreaflow/lib/dolizsynch.lib.php";
require_once DOL_DOCUMENT_ROOT . "/custom/kreaflow/class/zsinvoicesynch.class.php";
require_once DOL_DOCUMENT_ROOT . "/custom/kreaflow/class/zsdictionairsynch.class.php";
require_once DOL_DOCUMENT_ROOT . '/custom/kreaflow/class/zspunchclock.class.php';
require_once DOL_DOCUMENT_ROOT . '/custom/kreaflow/class/zsproductpricecron.class.php';

// Translations
$langs->loadLangs(array("admin", "dolizsynch@kreaflow"));

// Access control
if (!$user->admin) {
	accessforbidden();
}

// Parameters
$action = GETPOST('action', 'aZ09');
$backtopage = GETPOST('backtopage', 'alpha');

/**
 * Redirect back to this page with optional query parameters.
 *
 * @param array $params
 * @return void
 */
function dolizsynch_sync_tools_redirect($params = array())
{
	$params = array_merge(array(
		'mainmenu' => 'tools',
		'leftmenu' => 'dolizsynch_sync_tools',
	), $params);
	$location = $_SERVER['PHP_SELF'] . '?' . http_build_query($params);
	header("Location: " . $location);
	exit;
}

/**
 * Build compact exception summary safe for logging.
 *
 * @param Throwable $exception
 * @return string
 */
function dolizsynch_sync_tools_exception_summary($exception)
{
	if (!$exception instanceof Throwable) {
		return 'Unknown exception';
	}

	return get_class($exception) . ': ' . $exception->getMessage() . ' @ ' . $exception->getFile() . ':' . $exception->getLine();
}

/**
 * Render a section header row.
 *
 * @param string $title
 * @return void
 */
function dolizsynch_sync_tools_print_section_header($title)
{
	print '<tr class="liste_titre">';
	print '<th colspan="2">' . $title . '</th>';
	print '</tr>';
}

/**
 * Render a standard tool row.
 *
 * @param string $titleHtml
 * @param string $descriptionHtml
 * @param string $actionHtml
 * @return void
 */
function dolizsynch_sync_tools_print_tool_row($titleHtml, $descriptionHtml, $actionHtml)
{
	print '<tr class="oddeven">';
	print '<td>' . $titleHtml;
	if ($descriptionHtml !== '') {
		print '<br><span class="opacitymedium">' . $descriptionHtml . '</span>';
	}
	print '</td>';
	print '<td class="right" style="text-align:right;">' . $actionHtml . '</td>';
	print '</tr>';
}

/**
 * Build a standard inline POST form with CSRF token.
 *
 * @param string $innerHtml
 * @param string $action
 * @param string $extraAttributes
 * @return string
 */
function dolizsynch_sync_tools_build_form($innerHtml, $action = '', $extraAttributes = 'style="display:inline-block;"')
{
	$html = '<form action="' . htmlspecialchars($action, ENT_QUOTES, 'UTF-8') . '" method="post"';
	if ($extraAttributes !== '') {
		$html .= ' ' . $extraAttributes;
	}
	$html .= '>';
	$html .= '<input type="hidden" name="token" value="' . newToken() . '">';
	$html .= $innerHtml;
	$html .= '</form>';
	return $html;
}

/**
 * Require an authenticated POST carrying the current Dolibarr CSRF token.
 *
 * @return void
 */
function dolizsynch_sync_tools_require_secure_post()
{
	if ($_SERVER['REQUEST_METHOD'] !== 'POST'
		|| GETPOST('token', 'alphanohtml') !== (string) ($_SESSION['newtoken'] ?? '')) {
		accessforbidden('Invalid CSRF token');
	}
}

/**
 * Escape a value for safe HTML output.
 *
 * @param mixed $value
 * @return string
 */
function dolizsynch_sync_tools_escape($value)
{
	return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

/**
 * Normalize translated UI text so legacy HTML entities render as UTF-8 characters.
 *
 * @param mixed $value
 * @return string
 */
function dolizsynch_sync_tools_normalize_translation($value)
{
	return html_entity_decode((string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

/**
 * Escape translated UI text after normalizing any HTML entities back to UTF-8.
 *
 * @param mixed $value
 * @return string
 */
function dolizsynch_sync_tools_escape_translation($value)
{
	return dolizsynch_sync_tools_escape(dolizsynch_sync_tools_normalize_translation($value));
}

/**
 * Normalize a user-entered date into Y-m-d using the current Dolibarr short date format.
 *
 * @param string $value Raw date input
 * @return string Empty string when parsing fails
 */
function dolizsynch_sync_tools_normalize_user_date($value)
{
	global $langs;

	$value = trim((string) $value);
	if ($value === '') {
		return '';
	}

	$patterns = array();
	$jqueryFormat = trim((string) $langs->trans("FormatDateShortJQueryInput"));
	switch ($jqueryFormat) {
		case 'dd/mm/yy':
			$patterns = array('d/m/Y', 'd/m/y');
			break;
		case 'mm/dd/yy':
			$patterns = array('m/d/Y', 'm/d/y');
			break;
		case 'yy-mm-dd':
			$patterns = array('Y-m-d');
			break;
		default:
			$patterns = array('Y-m-d', 'd/m/Y', 'd/m/y', 'm/d/Y', 'm/d/y');
	}

	foreach ($patterns as $pattern) {
		$date = DateTime::createFromFormat('!' . $pattern, $value);
		$errors = DateTime::getLastErrors();
		if ($errors === false) {
			$errors = array('warning_count' => 0, 'error_count' => 0);
		}
		if ($date instanceof DateTime && empty($errors['warning_count']) && empty($errors['error_count'])) {
			return $date->format('Y-m-d');
		}
	}

	$timestamp = strtotime($value);
	if ($timestamp === false) {
		return '';
	}

	return date('Y-m-d', $timestamp);
}

/**
 * Render a Dolibarr date picker as HTML.
 *
 * @param Form $form Form helper
 * @param string $prefix Input prefix/name
 * @param string $value Current value
 * @return string
 */
function dolizsynch_sync_tools_render_date_picker($form, $prefix, $value)
{
	ob_start();
	print $form->selectDate($value, $prefix, 0, 0, 1, '', 1, 0, 0, '', '', '', '', 1);
	return trim(ob_get_clean());
}

/**
 * Build a standard action form with optional controls and confirmation.
 *
 * @param string $submitName
 * @param string $submitLabel
 * @param string $controlsHtml
 * @param array  $options
 * @return string
 */
function dolizsynch_sync_tools_build_action_form($submitName, $submitLabel, $controlsHtml = '', $options = array())
{
	$wrapperStyle = isset($options['wrapper_style']) ? (string) $options['wrapper_style'] : 'display:flex; gap:5px; align-items:center; justify-content:flex-end; flex-wrap:wrap;';
	$buttonClass = isset($options['button_class']) ? (string) $options['button_class'] : 'button';
	$buttonStyle = isset($options['button_style']) ? (string) $options['button_style'] : 'min-width:120px;';
	$action = isset($options['action']) ? (string) $options['action'] : '';
	$formAttributes = isset($options['form_attributes']) ? (string) $options['form_attributes'] : 'style="display:inline-block;"';

	$submitButton = '<input type="submit" class="' . dolizsynch_sync_tools_escape($buttonClass) . '" name="' . dolizsynch_sync_tools_escape($submitName) . '" value="' . dolizsynch_sync_tools_escape($submitLabel) . '"';
	if ($buttonStyle !== '') {
		$submitButton .= ' style="' . dolizsynch_sync_tools_escape($buttonStyle) . '"';
	}
	$submitButton .= '>';

	$innerHtml = '<div style="' . dolizsynch_sync_tools_escape($wrapperStyle) . '">' . $controlsHtml . $submitButton . '</div>';

	$confirmText = isset($options['confirm']) ? (string) $options['confirm'] : '';
	if ($confirmText !== '') {
		$confirmJs = 'return confirm(' . json_encode($confirmText) . ');';
		$formAttributes = trim($formAttributes . ' onsubmit="' . dolizsynch_sync_tools_escape($confirmJs) . '"');
	}

	return dolizsynch_sync_tools_build_form($innerHtml, $action, $formAttributes);
}

// Initialize objects
$zoneSoftInvoices = new ZSInvoiceSynch($db);
$zoneSoftDictionary = new ZSDictionairSynch($db);

/*
 * Actions
 */

$securedActions = array(
	'saveProductPriceSync',
	'synchYesterdayInvoices',
	'synchSpecificDocument',
	'synchInvoicesHeaders',
	'synchInvoices',
	'synchInterEntityInvoices',
	'synchMasterDataCore',
	'synchProperties',
	'synchProducts',
	'synchUnits',
	'synchProdCenters',
	'synchCategories',
	'synchGroups',
	'storeZsPunchclock',
	'pairTimeRegisters',
	'synchPunchClockBetweenDates',
);
foreach ($securedActions as $securedAction) {
	if (GETPOST($securedAction, 'alpha') !== '') {
		dolizsynch_sync_tools_require_secure_post();
		break;
	}
}

// Save the entity-owned scheduled product price synchronization settings.
if (GETPOST('saveProductPriceSync', 'alpha') !== '') {
	try {
		$priceSyncScheduler = new ZSProductPriceCron($db);
		if (!$priceSyncScheduler->saveConfiguration(
			(int) $conf->entity,
			GETPOSTINT(ZSProductPriceCron::CONFIG_ENABLED) > 0,
			GETPOST(ZSProductPriceCron::CONFIG_START_TIME, 'alphanohtml') ?: '04:00',
			GETPOST(ZSProductPriceCron::CONFIG_REPEAT_INTERVAL, 'alphanohtml') ?: '00:00',
			(int) $user->id,
			$langs->trans('CronJobTitleProductPriceSynchronization'),
			$langs->trans('CronJobCommentProductPriceSynchronization'),
			$langs->trans('CronJobTitleProductPriceSynchronizationRecovery'),
			$langs->trans('CronJobCommentProductPriceSynchronizationRecovery')
		)) {
			throw new RuntimeException($priceSyncScheduler->error);
		}
		setEventMessages($langs->trans('SetupSaved'), null, 'mesgs');
	} catch (Throwable $exception) {
		setEventMessages(
			$langs->trans('DoliZSynchProductPriceSyncSetupError', $exception->getMessage()),
			null,
			'errors'
		);
		dol_syslog(
			'KreaFlow sync tools: unable to configure scheduled product price synchronization: '
			. dolizsynch_sync_tools_exception_summary($exception),
			LOG_ERR
		);
	}

	dolizsynch_sync_tools_redirect();
}

// ============================================================================
// SECTION 1: INVOICE SYNCHRONIZATION
// ============================================================================

// Sync yesterday's invoices (headers + invoices) - ALL without limit
if (GETPOST('synchYesterdayInvoices', 'alpha') !== '') {
	try {
		$startTime = microtime(true);
		$result = $zoneSoftInvoices->synchronizeYesterdayInvoices();
		$endTime = microtime(true);
		$executionTime = round($endTime - $startTime, 2);

		if ($result === 0) {
			setEventMessages($langs->trans("YesterdayInvoicesSyncSuccess") . " ({$executionTime}s)", null, 'mesgs', 1);
		} else {
			setEventMessages($langs->trans("YesterdayInvoicesSyncFailed"), null, 'errors', 1);
		}
		dol_syslog("Yesterday invoices synchronization completed in {$executionTime}s with result: $result", LOG_INFO);
	} catch (Exception $e) {
		setEventMessages($langs->trans("YesterdayInvoicesSyncException") . ": " . $e->getMessage(), null, 'errors', 1);
	}
	dolizsynch_sync_tools_redirect();
}

// Force sync specific document
if (GETPOST('synchSpecificDocument', 'alpha') !== '') {
	$docDate = GETPOST('doc_date', 'alpha');
	$normalizedDocDate = dolizsynch_sync_tools_normalize_user_date($docDate);
	$docType = strtoupper(trim((string) GETPOST('doc_type', 'alphanohtml')));
	$docSerie = trim((string) GETPOST('doc_serie', 'alphanohtml'));
	$docNumber = GETPOST('doc_number', 'int');
	$replaceExisting = GETPOST('replace_existing', 'int') ? 1 : 0;

	if (empty($docDate) || empty($docType) || empty($docSerie) || empty($docNumber)) {
		setEventMessages($langs->trans("PleaseProvideAllDocumentFields"), null, 'errors', 1);
	} elseif ($normalizedDocDate === '') {
		setEventMessages($langs->trans("InvalidDateFormat"), null, 'errors', 1);
	} else {
		dol_syslog("Forcing sync for specific document: $docType-$docSerie-$docNumber on $docDate", LOG_INFO);
		try {
			$zoneSoftInvoices->syncSpecificDocument($normalizedDocDate, $docType, $docSerie, $docNumber, $replaceExisting);
		} catch (Exception $e) {
			setEventMessages($langs->trans("DocumentSyncException") . ": " . $e->getMessage(), null, 'errors', 1);
		}
	}
	// Redirect with document data persistence
	dolizsynch_sync_tools_redirect(array(
		'doc_date' => ($normalizedDocDate !== '' ? $normalizedDocDate : $docDate),
		'doc_type' => $docType,
		'doc_serie' => $docSerie,
		'doc_number' => $docNumber,
		'replace_existing' => $replaceExisting
	));
}

// Force sync invoice headers by date
if (GETPOST('synchInvoicesHeaders', 'alpha') !== '') {
	$dateStart = GETPOST('headers_date_start', 'alpha');
	$dateEnd = GETPOST('headers_date_end', 'alpha');

	if (empty($dateStart) || empty($dateEnd)) {
		setEventMessages($langs->trans("PleaseSelectBothDates"), null, 'errors', 1);
	} else {
		try {
			// Date picker already provides yyyy-mm-dd format
			$startTime = microtime(true);
			$synchedCount = $zoneSoftInvoices->synchronizeInvoiceHeadersByDate($dateStart, $dateEnd);
			$endTime = microtime(true);
			$executionTime = round($endTime - $startTime, 2);

			if ($synchedCount === false) {
				setEventMessages($langs->trans("InvoiceHeadersSyncFailed"), null, 'errors', 1);
			} else {
				setEventMessages($langs->trans("InvoiceHeadersSyncSuccess") . ": $synchedCount " . $langs->trans("headers") . " ({$executionTime}s)", null, 'mesgs', 1);
			}
		} catch (Exception $e) {
			setEventMessages($langs->trans("InvoiceHeadersSynchronizationException") . ": " . $e->getMessage(), null, 'errors', 1);
		}
	}
	// Redirect with date persistence
	dolizsynch_sync_tools_redirect(array(
		'headers_date_start' => $dateStart,
		'headers_date_end' => $dateEnd
	));
}

// Force sync all invoices
if (GETPOST('synchInvoices', 'alpha') !== '') {
	try {
		$startTime = microtime(true);
		$result = method_exists($zoneSoftInvoices, 'synchronizeInvoicesAcrossMappedStores')
			? $zoneSoftInvoices->synchronizeInvoicesAcrossMappedStores()
			: $zoneSoftInvoices->synchronizeAllUnsynchronizedInvoicesByStore();
		$endTime = microtime(true);
		$executionTime = round($endTime - $startTime, 2);
		dol_syslog("Invoices synchronization completed in {$executionTime}s", LOG_INFO);

		if (is_string($result) && stripos($result, 'ERROR:') === 0) {
			setEventMessages($result, null, 'errors', 1);
		}
	} catch (Exception $e) {
		setEventMessages($langs->trans("InvoicesSynchronizationException") . ": " . $e->getMessage(), null, 'errors', 1);
	}
	dolizsynch_sync_tools_redirect();
}

// ============================================================================
// SECTION 2: INTER-ENTITY SYNCHRONIZATION
// ============================================================================

if (GETPOST('synchInterEntityInvoices', 'alpha') !== '') {
	$targetDate = GETPOST('interentity_date', 'alpha');

	if (!isModEnabled('multicompany')) {
		setEventMessages($langs->trans("RequiresMulticompany"), null, 'errors', 1);
	} elseif (!getDolGlobalString('DOLIZSYNCH_INTERENTITY_INVOICE_ENABLED')) {
		setEventMessages($langs->trans("InterEntityInvoiceNotEnabled"), null, 'errors', 1);
	} else {
		try {
			require_once DOL_DOCUMENT_ROOT . '/custom/kreaflow/class/zsstoreinvoiceconverter.class.php';

			$converter = new ZSStoreInvoiceConverter($db);
			if (empty($targetDate)) {
				$targetDate = date('Y-m-d');
			} elseif (strpos($targetDate, '/') !== false) {
				$dateParts = explode('/', $targetDate);
				if (count($dateParts) === 3) {
					$targetDate = $dateParts[2] . '-' . $dateParts[1] . '-' . $dateParts[0];
				}
			}

			$startTime = microtime(true);
			$result = $converter->processInterEntityInvoices($targetDate);
			$executionTime = round(microtime(true) - $startTime, 2);

			if ($result > 0) {
				$message = $langs->trans("InterEntityInvoicesSyncSuccess") . " ({$result} " . $langs->trans("invoices") . " " . $langs->trans("processed") . " " . $langs->trans("in") . " {$executionTime}s)";
				setEventMessages($message, null, 'mesgs', 1);
			} elseif ($result === 0) {
				setEventMessages($langs->trans("NoInterEntityInvoicesToProcess"), null, 'warnings', 1);
			} else {
				$error = $converter->error ? $converter->error : $langs->trans("InterEntityInvoicesSyncFailed");
				setEventMessages($error, null, 'errors', 1);
			}
		} catch (Exception $e) {
			setEventMessages($langs->trans("InterEntityInvoicesSyncException") . ": " . $e->getMessage(), null, 'errors', 1);
		}
	}

	dolizsynch_sync_tools_redirect(array('interentity_date' => $targetDate));
}

// ============================================================================
// SECTION 3: PRODUCT AND MASTER-DATA SYNCHRONIZATION
// ============================================================================

// Sync property definitions
if (GETPOST('synchProperties', 'alpha') !== '') {
	try {
		$result = $zoneSoftDictionary->synchronizeProperties();
		if ($result) {
			setEventMessages($langs->trans('PropertiesSyncSuccess'), null, 'mesgs', 1);
		} else {
			$error = trim((string) $zoneSoftDictionary->error);
			setEventMessages($error !== '' ? $error : $langs->trans('PropertiesSyncFailed'), null, 'errors', 1);
		}
	} catch (Exception $e) {
		setEventMessages($langs->trans('PropertiesSyncException') . ': ' . $e->getMessage(), null, 'errors', 1);
	}
	dolizsynch_sync_tools_redirect();
}

// Sync products
if (GETPOST('synchProducts', 'alpha') !== '') {
	try {
		$zoneSoftDictionary->synchronizeProducts();
		// Messages are set in the method
	} catch (Exception $e) {
		setEventMessages($langs->trans("ProductsSyncException") . ": " . $e->getMessage(), null, 'errors', 1);
	}
	dolizsynch_sync_tools_redirect();
}

// Sync units
if (GETPOST('synchUnits', 'alpha') !== '') {
	try {
		$zoneSoftDictionary->synchronizeUnits();
		setEventMessages($langs->trans("UnitsSyncSuccess"), null, 'mesgs', 1);
	} catch (Exception $e) {
		setEventMessages($langs->trans("UnitsSyncException") . ": " . $e->getMessage(), null, 'errors', 1);
	}
	dolizsynch_sync_tools_redirect();
}

// Sync production centers
if (GETPOST('synchProdCenters', 'alpha') !== '') {
	try {
		$zoneSoftDictionary->synchronizeProductionCenters();
		setEventMessages($langs->trans("ProdCentersSyncSuccess"), null, 'mesgs', 1);
	} catch (Exception $e) {
		setEventMessages($langs->trans("ProdCentersSyncException") . ": " . $e->getMessage(), null, 'errors', 1);
	}
	dolizsynch_sync_tools_redirect();
}

// Sync categories
if (GETPOST('synchCategories', 'alpha') !== '') {
	try {
		$zoneSoftDictionary->synchronizeCategories();
		setEventMessages($langs->trans("CategoriesSyncSuccess"), null, 'mesgs', 1);
	} catch (Exception $e) {
		setEventMessages($langs->trans("CategoriesSyncException") . ": " . $e->getMessage(), null, 'errors', 1);
	}
	dolizsynch_sync_tools_redirect();
}

// Sync groups
if (GETPOST('synchGroups', 'alpha') !== '') {
	try {
		$zoneSoftDictionary->synchronizeGroups();
		setEventMessages($langs->trans("GroupsSyncSuccess"), null, 'mesgs', 1);
	} catch (Exception $e) {
		setEventMessages($langs->trans("GroupsSyncException") . ": " . $e->getMessage(), null, 'errors', 1);
	}
	dolizsynch_sync_tools_redirect();
}

// Sync master data
if (GETPOST('synchMasterDataCore', 'alpha') !== '') {
	try {
		$result = $zoneSoftDictionary->synchronizeMasterDataCore();
		if ($result) {
			setEventMessages($langs->trans('MasterDataSyncSuccess'), null, 'mesgs', 1);
		} else {
			$errorParts = array();
			if (!empty($zoneSoftDictionary->error)) {
				$errorParts[] = $zoneSoftDictionary->error;
			}
			if (!empty($zoneSoftDictionary->errors) && is_array($zoneSoftDictionary->errors)) {
				$errorParts = array_merge($errorParts, array_slice($zoneSoftDictionary->errors, 0, 3));
			}
			$errorParts = array_values(array_unique(array_filter($errorParts, function ($item) {
				return trim((string) $item) !== '';
			})));
			$errorMessage = $langs->trans('MasterDataSyncPartial');
			if (!empty($errorParts)) {
				$errorMessage .= ": " . implode(' | ', $errorParts);
			}
			setEventMessages($errorMessage, null, 'warnings', 1);
		}
	} catch (Exception $e) {
		setEventMessages($langs->trans('MasterDataSyncException') . ': ' . $e->getMessage(), null, 'errors', 1);
	}
	dolizsynch_sync_tools_redirect();
}

// ============================================================================
// SECTION 4: PUNCH CLOCK SYNCHRONIZATION
// ============================================================================

// Sync punch clock entries between dates (headers only)
if (GETPOST('synchPunchClockBetweenDates', 'alpha') !== '') {
	dol_syslog("SYNC_TOOLS: Punch clock headers sync button clicked", LOG_INFO);
	$dateStart = GETPOST('punchclock_date_start', 'alpha');
	$dateEnd = GETPOST('punchclock_date_end', 'alpha');
	dol_syslog("SYNC_TOOLS: Date range: $dateStart to $dateEnd", LOG_INFO);

	if (empty($dateStart) || empty($dateEnd)) {
		dol_syslog("SYNC_TOOLS: ERROR - Missing dates", LOG_ERR);
		setEventMessages($langs->trans("PleaseSelectBothDates"), null, 'errors', 1);
	} else {
		try {
			// Use ZSPunchClock_Synch class for header sync
			require_once DOL_DOCUMENT_ROOT . '/custom/kreaflow/class/zspunchclock_synch.class.php';

			dol_syslog("SYNC_TOOLS: Instantiating ZSPunchClock_Synch class", LOG_INFO);
			$punchSync = new ZSPunchClock_Synch($db);

			// Convert dates to datetime format for API
			$startDateTime = $dateStart . ' 00:00:00';
			$endDateTime = $dateEnd . ' 23:59:59';

			$startTime = microtime(true);

			// Fetch and store punch clock entries from ZoneSoft API (headers only)
			dol_syslog("SYNC_TOOLS: Calling storezspunchclockPeriod method", LOG_INFO);
			$result = $punchSync->storezspunchclockPeriod($startDateTime, $endDateTime);

			$endTime = microtime(true);
			$executionTime = round($endTime - $startTime, 2);

			if ($result) {
				dol_syslog("SYNC_TOOLS: Headers sync successful in {$executionTime}s", LOG_INFO);
				$message = $langs->trans("PunchClockHeadersSyncSuccess") . " ({$executionTime}s)";
				setEventMessages($message, null, 'mesgs', 1);
			} else {
				dol_syslog("SYNC_TOOLS: Headers sync failed", LOG_ERR);
				$syncError = !empty($punchSync->error) ? $punchSync->error : $langs->trans("PunchClockHeadersSyncFailed");
				setEventMessages($syncError, null, 'errors', 1);
			}
		} catch (Exception $e) {
			dol_syslog("SYNC_TOOLS: EXCEPTION - " . dolizsynch_sync_tools_exception_summary($e), LOG_ERR);
			setEventMessages($langs->trans("PunchClockHeadersSyncException") . ": " . $e->getMessage(), null, 'errors', 1);
		}
		dolizsynch_sync_tools_redirect(array(
			'punchclock_date_start' => $dateStart,
			'punchclock_date_end' => $dateEnd
		));
	}
}

// Pair time registers (using cron method - no parameters like cron)
if (GETPOST('pairTimeRegisters', 'alpha') !== '') {
	dol_syslog("SYNC_TOOLS: Pair time registers button clicked", LOG_INFO);

	try {
		// Use TimeRegisterImport class (same as cron job)
		require_once DOL_DOCUMENT_ROOT . '/custom/kreaflow/class/zstimeregisterimport.class.php';

		dol_syslog("SYNC_TOOLS: Instantiating TimeRegisterImport class", LOG_INFO);
		$timeRegImport = new TimeRegisterImport($db);

		$startTime = microtime(true);

		// Call performPairTimeRegisters with NO parameters (same as cron job)
		dol_syslog("SYNC_TOOLS: Calling performPairTimeRegisters method (no parameters - same as cron)", LOG_INFO);
		$result = $timeRegImport->performPairTimeRegisters();

		$endTime = microtime(true);
		$executionTime = round($endTime - $startTime, 2);

		// performPairTimeRegisters returns 0 for success, -1 for error
		if ($result === 0) {
			dol_syslog("SYNC_TOOLS: Pairing successful in {$executionTime}s", LOG_INFO);
			$message = $langs->trans("PairTimeRegistersSuccess") . " ({$executionTime}s)";
			setEventMessages($message, null, 'mesgs', 1);
		} else {
			dol_syslog("SYNC_TOOLS: Pairing failed", LOG_ERR);
			setEventMessages($langs->trans("PairTimeRegistersFailed"), null, 'errors', 1);
		}
	} catch (Exception $e) {
		dol_syslog("SYNC_TOOLS: EXCEPTION - " . dolizsynch_sync_tools_exception_summary($e), LOG_ERR);
		setEventMessages($langs->trans("PairTimeRegistersException") . ": " . $e->getMessage(), null, 'errors', 1);
	}
	dolizsynch_sync_tools_redirect();
}

// Store ZS punch clock data (using cron method)
if (GETPOST('storeZsPunchclock', 'alpha') !== '') {
	dol_syslog("SYNC_TOOLS: Store ZS punch clock button clicked", LOG_INFO);

	try {
		// Use TimeRegisterImport class (same as cron job)
		require_once DOL_DOCUMENT_ROOT . '/custom/kreaflow/class/zstimeregisterimport.class.php';

		dol_syslog("SYNC_TOOLS: Instantiating TimeRegisterImport class", LOG_INFO);
		$timeRegImport = new TimeRegisterImport($db);

		$startTime = microtime(true);

		// Call performStoreZsPunchclock (same as cron job)
		dol_syslog("SYNC_TOOLS: Calling performStoreZsPunchclock method", LOG_INFO);
		$result = $timeRegImport->performStoreZsPunchclock();

		$endTime = microtime(true);
		$executionTime = round($endTime - $startTime, 2);

		// performStoreZsPunchclock returns 0 for success, -1 for error
		if ($result === 0) {
			dol_syslog("SYNC_TOOLS: Store ZS punch clock successful in {$executionTime}s", LOG_INFO);
			$message = $langs->trans("StoreZsPunchclockSuccess") . " ({$executionTime}s)";
			setEventMessages($message, null, 'mesgs', 1);
		} else {
			dol_syslog("SYNC_TOOLS: Store ZS punch clock failed", LOG_ERR);
			setEventMessages($langs->trans("StoreZsPunchclockFailed"), null, 'errors', 1);
		}
	} catch (Exception $e) {
		dol_syslog("SYNC_TOOLS: EXCEPTION - " . dolizsynch_sync_tools_exception_summary($e), LOG_ERR);
		setEventMessages($langs->trans("StoreZsPunchclockException") . ": " . $e->getMessage(), null, 'errors', 1);
	}
	dolizsynch_sync_tools_redirect();
}

/*
 * View
 */

$help_url = '';
$page_name = "DoliZSynchSyncTools";

llxHeader('', $langs->trans($page_name), $help_url);
$form = new Form($db);

// Subheader
$linkback = '<a href="' . ($backtopage ? $backtopage : DOL_URL_ROOT . '/core/tools.php?mainmenu=tools') . '">' . $langs->trans("Tools") . '</a>';

print load_fiche_titre($langs->trans($page_name), $linkback, 'title_setup');

// Page description
print '<span class="opacitymedium">' . $langs->trans("DoliZSynchSyncToolsDescription") . '</span><br><br>';

// Scheduled selling and cost price synchronization configuration.
$priceSyncEnabled = getDolGlobalInt(ZSProductPriceCron::CONFIG_ENABLED);
$priceSyncStartTime = getDolGlobalString(ZSProductPriceCron::CONFIG_START_TIME, '04:00');
$priceSyncRepeatInterval = getDolGlobalString(ZSProductPriceCron::CONFIG_REPEAT_INTERVAL, '00:00');
try {
	$priceSyncStartTime = ZSProductPriceCron::normalizeTimeValue($priceSyncStartTime);
} catch (InvalidArgumentException $exception) {
	$priceSyncStartTime = '04:00';
}
try {
	$priceSyncRepeatInterval = ZSProductPriceCron::normalizeTimeValue($priceSyncRepeatInterval);
} catch (InvalidArgumentException $exception) {
	$priceSyncRepeatInterval = '00:00';
}

print '<form action="' . dolizsynch_sync_tools_escape($_SERVER['PHP_SELF']) . '" method="post">';
print '<input type="hidden" name="token" value="' . newToken() . '">';
print '<table class="noborder centpercent">';
dolizsynch_sync_tools_print_section_header(
	dolizsynch_sync_tools_escape_translation($langs->trans('DOLIZSYNCH_PRODUCT_PRICE_SYNC_TITLE'))
);
print '<tr class="oddeven">';
print '<td>' . $form->textwithpicto(
	dolizsynch_sync_tools_escape_translation($langs->trans('DOLIZSYNCH_PRODUCT_PRICE_SYNC_ENABLED')),
	dolizsynch_sync_tools_escape_translation($langs->trans('DOLIZSYNCH_PRODUCT_PRICE_SYNC_ENABLED_HELP'))
) . '</td>';
print '<td class="right">';
print '<input type="checkbox" class="flat" name="' . ZSProductPriceCron::CONFIG_ENABLED . '" value="1"'
	. ($priceSyncEnabled ? ' checked' : '') . '>';
print '</td>';
print '</tr>';
print '<tr class="oddeven">';
print '<td>' . $form->textwithpicto(
	dolizsynch_sync_tools_escape_translation($langs->trans('DOLIZSYNCH_PRODUCT_PRICE_SYNC_START_TIME')),
	dolizsynch_sync_tools_escape_translation($langs->trans('DOLIZSYNCH_PRODUCT_PRICE_SYNC_START_TIME_HELP'))
) . '</td>';
print '<td class="right"><input type="time" class="flat" name="' . ZSProductPriceCron::CONFIG_START_TIME
	. '" value="' . dolizsynch_sync_tools_escape($priceSyncStartTime) . '" step="60" required></td>';
print '</tr>';
print '<tr class="oddeven">';
print '<td>' . $form->textwithpicto(
	dolizsynch_sync_tools_escape_translation($langs->trans('DOLIZSYNCH_PRODUCT_PRICE_SYNC_REPEAT_INTERVAL')),
	dolizsynch_sync_tools_escape_translation($langs->trans('DOLIZSYNCH_PRODUCT_PRICE_SYNC_REPEAT_INTERVAL_HELP'))
) . '</td>';
print '<td class="right"><input type="time" class="flat" name="' . ZSProductPriceCron::CONFIG_REPEAT_INTERVAL
	. '" value="' . dolizsynch_sync_tools_escape($priceSyncRepeatInterval)
	. '" min="00:00" max="23:59" step="60" required></td>';
print '</tr>';
print '</table>';
print '<div class="tabsAction">';
print '<input type="submit" class="button button-save" name="saveProductPriceSync" value="'
	. dolizsynch_sync_tools_escape_translation($langs->trans('Save')) . '">';
print '</div>';
print '</form>';
print '<br>';

// Synchronization tools table
print '<table class="noborder centpercent">';

// ============================================================================
// SECTION 1: INVOICE SYNCHRONIZATION
// ============================================================================
print '<tr class="liste_titre">';
print '<th>' . $langs->trans("ToolsColumn") . '</th>';
print '<th class="right" width="400">' . $langs->trans("Action") . '</th>';
print '</tr>';
dolizsynch_sync_tools_print_section_header($langs->trans('InvoiceSynchronizationTools'));

// Sync yesterday's invoices (headers + invoices)
$formHtml = dolizsynch_sync_tools_build_form(
	'<input type="submit" class="button" name="synchYesterdayInvoices" value="' . $langs->trans("Sync") . '" style="min-width:120px;">'
);
dolizsynch_sync_tools_print_tool_row(
	'<strong>' . $langs->trans("SYNCH_YESTERDAY_INVOICES") . '</strong>',
	$langs->trans("SYNCH_YESTERDAY_INVOICES_DESC"),
	$formHtml
);

// Force sync invoice headers by date
$headersDateStart = GETPOST('headers_date_start', 'alpha');
$headersDateEnd = GETPOST('headers_date_end', 'alpha');
$controlsHtml = '<input type="date" name="headers_date_start" value="' . dolizsynch_sync_tools_escape($headersDateStart) . '" required style="max-width:150px;">'
	. '<span>' . $langs->trans("to") . '</span>'
	. '<input type="date" name="headers_date_end" value="' . dolizsynch_sync_tools_escape($headersDateEnd) . '" required style="max-width:150px;">';
$formHtml = dolizsynch_sync_tools_build_action_form('synchInvoicesHeaders', $langs->trans("Sync"), $controlsHtml);
dolizsynch_sync_tools_print_tool_row(
	'<strong>' . $langs->trans("FORCE_SYNC_INVOICE_HEADERS_BY_DATE") . '</strong>',
	$langs->trans("FORCE_SYNC_INVOICE_HEADERS_BY_DATE_DESC"),
	$formHtml
);

// Force sync all invoices
// Get count of invoices to be synced (single-store mode) or mapped-store count (multi-store mode)
$mappedStoresCount = 0;
$sqlMappedStores = "SELECT COUNT(DISTINCT zsbms_store) AS countstores";
$sqlMappedStores .= " FROM " . MAIN_DB_PREFIX . "dolizsynch_storemap";
$sqlMappedStores .= " WHERE entity IN (0, " . ((int) $conf->entity) . ")";
$sqlMappedStores .= " AND zsbms_store IS NOT NULL AND zsbms_store <> ''";
$resqlMappedStores = $db->query($sqlMappedStores);
if ($resqlMappedStores) {
	$objMappedStores = $db->fetch_object($resqlMappedStores);
	$mappedStoresCount = (int) ($objMappedStores->countstores ?? 0);
}

$description = $langs->trans("SYNCH_ALL_ZS_INVOICES_DESC");
if ($mappedStoresCount > 1) {
	$description .= " (multi-store queue enabled: " . $mappedStoresCount . " stores)";
} else {
	$store = $conf->global->ZS_API_STORE;
	$maxSynchNumInvoices = $conf->global->ZS_MAX_NUMBER_OF_INVOICES_IN_EACH_SYNCHRONIZATION;
	$sql = "SELECT COUNT(*) as count FROM " . MAIN_DB_PREFIX . "dolizsynch_zsinvoicesynch ";
	$sql .= "WHERE synch IN (0, 2, 3, 21, 22, 23) ";
	$sql .= "AND loja_zs = " . $db->escape($store);
	$resql = $db->query($sql);
	$invoiceCount = 0;
	if ($resql) {
		$obj = $db->fetch_object($resql);
		$invoiceCount = $obj->count;
	}
	$invoicesToSync = min($invoiceCount, $maxSynchNumInvoices);
	if ($invoiceCount > 0) {
		$description .= " (" . $invoicesToSync . " " . $langs->trans("invoices") . " " . $langs->trans("toSync") . ")";
	}
}
$formHtml = dolizsynch_sync_tools_build_form(
	'<input type="submit" class="button" name="synchInvoices" value="' . $langs->trans("Sync") . '" style="min-width:120px;">'
);
dolizsynch_sync_tools_print_tool_row(
	'<strong>' . $langs->trans("SYNCH_ALL_ZS_INVOICES") . '</strong>',
	$description,
	$formHtml
);

// Force sync specific document
$docDate = GETPOST('doc_date', 'alpha');
$docType = GETPOST('doc_type', 'alpha');
$docSerie = GETPOST('doc_serie', 'alpha');
$docNumber = GETPOST('doc_number', 'int');
$replaceExisting = GETPOST('replace_existing', 'int') ? 1 : 0;
$replaceConfirmJs = "if (this.elements['replace_existing'] && this.elements['replace_existing'].checked) { return confirm(" . json_encode(dolizsynch_sync_tools_normalize_translation($langs->trans("ForceSyncSpecificDocumentReplaceConfirm"))) . "); } return true;";
$docDatePickerHtml = dolizsynch_sync_tools_render_date_picker($form, 'doc_date', $docDate);
$controlsHtml = '<span style="display:inline-flex; align-items:center;">' . $docDatePickerHtml . '</span>'
	. '<input type="text" name="doc_type" value="' . dolizsynch_sync_tools_escape($docType) . '" placeholder="' . dolizsynch_sync_tools_escape_translation($langs->trans("DocType")) . '" required style="max-width:60px;">'
	. '<input type="text" name="doc_serie" value="' . dolizsynch_sync_tools_escape($docSerie) . '" placeholder="' . dolizsynch_sync_tools_escape_translation($langs->trans("Serie")) . '" required style="max-width:60px;">'
	. '<input type="number" name="doc_number" value="' . dolizsynch_sync_tools_escape($docNumber) . '" placeholder="' . dolizsynch_sync_tools_escape_translation($langs->trans("Number")) . '" required style="max-width:80px;">'
	. '<span style="display:flex; flex-direction:column; align-items:flex-start; gap:2px; min-width:260px;">'
	. '<label style="display:inline-flex; align-items:center; gap:4px; white-space:nowrap;">'
	. '<input type="checkbox" name="replace_existing" value="1"' . ($replaceExisting ? ' checked' : '') . '>'
	. '<span>' . dolizsynch_sync_tools_escape_translation($langs->trans("ForceSyncSpecificDocumentReplace")) . '</span>'
	. '</label>'
	. '<span class="opacitymedium" style="text-align:left;">' . dolizsynch_sync_tools_escape_translation($langs->trans("ForceSyncSpecificDocumentReplaceHelp")) . '</span>'
	. '</span>';
$formHtml = dolizsynch_sync_tools_build_action_form('synchSpecificDocument', $langs->trans("Sync"), $controlsHtml, array(
	'form_attributes' => 'style="display:inline-block;" onsubmit="' . dolizsynch_sync_tools_escape($replaceConfirmJs) . '"'
));
dolizsynch_sync_tools_print_tool_row(
	'<strong>' . $langs->trans("FORCE_SYNC_SPECIFIC_DOCUMENT") . '</strong>',
	$langs->trans("FORCE_SYNC_SPECIFIC_DOCUMENT_DESC"),
	$formHtml
);

// Inter-Entity Invoice Conversion (only show if multicompany and feature enabled in ANY entity)
// Check if the feature is enabled in ANY entity, not just the current one
// This is because inter-entity conversion works across entities
$interEntityEnabled = false;

// First check current entity
if (getDolGlobalString('DOLIZSYNCH_INTERENTITY_INVOICE_ENABLED')) {
	$interEntityEnabled = true;
} else {
	// Check if enabled in any entity via database query
	$sql = "SELECT COUNT(*) as nb FROM " . MAIN_DB_PREFIX . "const";
	$sql .= " WHERE name = 'DOLIZSYNCH_INTERENTITY_INVOICE_ENABLED'";
	$sql .= " AND value = '1'";

	$resql = $db->query($sql);
	if ($resql) {
		$obj = $db->fetch_object($resql);
		if ($obj && $obj->nb > 0) {
			$interEntityEnabled = true;
		}
	}
}

if (isModEnabled('multicompany') && $interEntityEnabled) {
	dolizsynch_sync_tools_print_section_header($langs->trans('InterEntitySynchronizationTools'));
	$interentityDate = GETPOST('interentity_date', 'alpha');
	if (empty($interentityDate)) {
		$interentityDate = date('Y-m-d'); // Default to today
	}
	$controlsHtml = '<input type="date" name="interentity_date" value="' . dolizsynch_sync_tools_escape($interentityDate) . '" style="max-width:150px;">';
	$formHtml = dolizsynch_sync_tools_build_action_form('synchInterEntityInvoices', $langs->trans("Sync"), $controlsHtml);
	dolizsynch_sync_tools_print_tool_row(
		'<strong>' . $langs->trans("SYNCH_INTERENTITY_INVOICES") . '</strong>',
		$langs->trans("SYNCH_INTERENTITY_INVOICES_DESC"),
		$formHtml
	);
}

dolizsynch_sync_tools_print_section_header($langs->trans('ProductCatalogSynchronization'));
$productSyncRows = array(
	array(
		'label' => $langs->trans('SyncProducts'),
		'description' => $langs->trans('SyncProductsDescription'),
		'submit' => 'synchProducts',
	),
	array(
		'label' => $langs->trans('SyncProperties'),
		'description' => $langs->trans('SyncPropertiesDescription'),
		'submit' => 'synchProperties',
	),
);
foreach ($productSyncRows as $row) {
	$formHtml = dolizsynch_sync_tools_build_form(
		'<input type="submit" class="button" name="' . $row['submit'] . '" value="' . $langs->trans("Sync") . '" style="min-width:120px;">'
	);
	dolizsynch_sync_tools_print_tool_row('<strong>' . $row['label'] . '</strong>', $row['description'], $formHtml);
}

dolizsynch_sync_tools_print_section_header($langs->trans('MasterDataSynchronization'));
$formHtml = dolizsynch_sync_tools_build_form(
	'<input type="submit" class="button" name="synchMasterDataCore" value="' . $langs->trans("Sync") . '" style="min-width:120px;">'
);
dolizsynch_sync_tools_print_tool_row(
	'<strong>' . $langs->trans('SyncMasterDataCore') . '</strong>',
	$langs->trans('SyncMasterDataCoreDescription'),
	$formHtml
);

$dictionarySyncRows = array(
	array('label' => $langs->trans('SyncUnits'), 'description' => $langs->trans('SyncUnitsDescription'), 'submit' => 'synchUnits'),
	array('label' => $langs->trans('SyncProdCenters'), 'description' => $langs->trans('SyncProdCentersDescription'), 'submit' => 'synchProdCenters'),
	array('label' => $langs->trans('SyncCategories'), 'description' => $langs->trans('SyncCategoriesDescription'), 'submit' => 'synchCategories'),
	array('label' => $langs->trans('SyncGroups'), 'description' => $langs->trans('SyncGroupsDescription'), 'submit' => 'synchGroups'),
);
foreach ($dictionarySyncRows as $row) {
	$formHtml = dolizsynch_sync_tools_build_form(
		'<input type="submit" class="button" name="' . $row['submit'] . '" value="' . $langs->trans("Sync") . '" style="min-width:120px;">'
	);
	dolizsynch_sync_tools_print_tool_row('<strong>' . $row['label'] . '</strong>', $row['description'], $formHtml);
}

dolizsynch_sync_tools_print_section_header($langs->trans("PunchClockSync"));

// Store ZS punch clock data (step 1 - fetch from API)
$formHtml = dolizsynch_sync_tools_build_form(
	'<input type="submit" class="button" name="storeZsPunchclock" value="' . $langs->trans("Process") . '" style="min-width:120px;">',
	$_SERVER['PHP_SELF']
);
dolizsynch_sync_tools_print_tool_row(
	'<strong>' . $langs->trans("STORE_ZS_PUNCHCLOCK") . '</strong>',
	$langs->trans("STORE_ZS_PUNCHCLOCK_DESC"),
	$formHtml
);

// Pair time registers (step 2 - pair and process to tasks)
$formHtml = dolizsynch_sync_tools_build_form(
	'<input type="submit" class="button" name="pairTimeRegisters" value="' . $langs->trans("Process") . '" style="min-width:120px;">',
	$_SERVER['PHP_SELF']
);
dolizsynch_sync_tools_print_tool_row(
	'<strong>' . $langs->trans("PAIR_TIME_REGISTERS") . '</strong>',
	$langs->trans("PAIR_TIME_REGISTERS_DESC"),
	$formHtml
);

// Sync punch clock headers between dates (alternative - sync specific date range)
$punchclockDateStart = GETPOST('punchclock_date_start', 'alpha');
$punchclockDateEnd = GETPOST('punchclock_date_end', 'alpha');
$controlsHtml = '<input type="date" name="punchclock_date_start" value="' . dolizsynch_sync_tools_escape($punchclockDateStart) . '" required style="max-width:150px;">'
	. '<span>' . $langs->trans("to") . '</span>'
	. '<input type="date" name="punchclock_date_end" value="' . dolizsynch_sync_tools_escape($punchclockDateEnd) . '" required style="max-width:150px;">';
$formHtml = dolizsynch_sync_tools_build_action_form('synchPunchClockBetweenDates', $langs->trans("Sync"), $controlsHtml, array(
	'action' => $_SERVER['PHP_SELF'],
));
dolizsynch_sync_tools_print_tool_row(
	'<strong>' . $langs->trans("SYNCH_PUNCHCLOCK_BETWEEN_DATES") . '</strong>',
	$langs->trans("SYNCH_PUNCHCLOCK_BETWEEN_DATES_DESC"),
	$formHtml
);

print '</table>';

llxFooter();
$db->close();

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
 * \file    dolizsynch/admin/about.php
 * \ingroup dolizsynch
 * \brief   About page of module DoliZSynch.
 */

// Load Dolibarr environment
$res = 0;
// Try main.inc.php into web root known defined into CONTEXT_DOCUMENT_ROOT (not always defined)
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

// Libraries
require_once DOL_DOCUMENT_ROOT . '/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT . '/core/lib/functions2.lib.php';
require_once '../lib/dolizsynch.lib.php';

// Translations
$langs->loadLangs(array("errors", "admin", "dolizsynch@kreaflow"));

// Access control
if (!$user->admin) {
	accessforbidden();
}

// Parameters
$action = GETPOST('action', 'aZ09');
$backtopage = GETPOST('backtopage', 'alpha');


/*
 * Actions
 */

// None


/*
 * View
 */

$help_url = '';
$page_name = "DoliZSynchAbout";

llxHeader('', $langs->trans($page_name), $help_url);

// Subheader
$linkback = '<a href="' . ($backtopage ? $backtopage : DOL_URL_ROOT . '/admin/modules.php?restore_lastsearch_values=1') . '">' . $langs->trans("BackToModuleList") . '</a>';

print load_fiche_titre($langs->trans($page_name), $linkback, 'title_setup');

// Configuration header
$head = dolizsynchAdminPrepareHead();
print dol_get_fiche_head($head, 'about', $langs->trans($page_name), 0, 'dolizsynch@kreaflow');

dol_include_once('/kreaflow/core/modules/modKreaFlow.class.php');
$tmpmodule = new modKreaFlow($db);
$logoUrl = dol_buildpath('/custom/kreaflow/img/kreaflow-logo-transparent.png', 1);
$moduleName = $tmpmodule->getName();
$moduleVersion = $tmpmodule->getVersion();
$editorName = $tmpmodule->editor_name;
$editorUrl = $tmpmodule->editor_url;
$supportEmail = 'mail@kreativitat.com';
$overviewImage = dol_buildpath('/custom/kreaflow/img/dolizsynch.png', 1);
$flowImage = dol_buildpath('/custom/kreaflow/img/dolizsynch_synch.gif', 1);

print '<div class="moduledesclong">';
print '<h1>KreaFlow for Dolibarr ERP/CRM</h1>';
print '<p>KreaFlow is an enterprise integration suite for <a target="_blank" rel="noopener noreferrer" href="https://www.dolibarr.org">Dolibarr ERP/CRM</a> that connects finance, products, workforce operations, and monitoring with Zone Soft. It is designed for production environments that require automation, traceability, multicompany awareness, and reliable day-to-day operations.</p>';
print '<p>The module combines synchronization engines, mapping controls, corrective tools, product/price extensions, punch-clock and scheduling workflows, and operational observability to reduce manual interventions and improve data integrity across entities and stores.</p>';

print '<h2>Core capabilities</h2>';

print '<h3>1) Financial synchronization and recovery</h3>';
print '<ul>';
print '<li>Automated import of invoice headers and full invoice synchronization from Zone Soft.</li>';
print '<li>Queue-based synchronization of pending invoices by mapped store/entity scope.</li>';
print '<li>Dedicated synchronization of yesterday documents and date-range header imports.</li>';
print '<li>Force-sync for a specific document (date, type, series, number).</li>';
print '<li>Corrective tools for canceled invoices and missing invoice references.</li>';
print '<li>Manual ZSBMS document creator for controlled exception workflows.</li>';
print '<li>Optional inter-entity invoice conversion flow (multicompany mode).</li>';
print '</ul>';

print '<h3>2) Master data and mapping consistency</h3>';
print '<ul>';
print '<li>Master-data synchronization for products, units, families, categories, groups, taxes, payment types, and production centers.</li>';
print '<li>Mapping screens for families/categories, payment methods, bank accounts, and unit conversions.</li>';
print '<li>Store/entity mapping table for multicompany and multi-store deployments.</li>';
print '<li>Dictionary-backed synchronization and reconciliation helpers.</li>';
print '<li>Product equivalence management for inter-entity conversion scenarios.</li>';
print '</ul>';

print '<h3>3) Product governance and pricing operations</h3>';
print '<ul>';
print '<li>Custom KreaFlow product tab with Zone Soft-oriented product controls and metadata.</li>';
print '<li>Replacement of the standard selling price tab with a KreaFlow-focused pricing screen.</li>';
print '<li>Per-product sell-price synchronization controls in the sell-price summary: automatic sell-price update toggle and markup percentage.</li>';
print '<li>Inline editing workflow for sell-price markup and direct ON/OFF toggle action for automatic sell-price updates.</li>';
print '<li>Compact top summary layout in the no-customer product view: 2 rows combining type/default tax and sell-price sync controls.</li>';
print '<li>Scheduled price update engine and queue processing (automatic cron execution).</li>';
print '<li>Price scheduling workflows to plan, activate, cancel, retry, and audit price update batches.</li>';
print '<li>Hooks and validations to keep product-related synchronization data coherent.</li>';
print '</ul>';

print '<h3>4) Workforce operations: punch clock, schedules, and leaves</h3>';
print '<ul>';
print '<li>Punch-clock ingestion from Zone Soft plus automated pairing of IN/OUT time registers.</li>';
print '<li>Date-range punch-clock synchronization for controlled replay/recovery.</li>';
print '<li>Shift planner suite (employees view, weekly bars, daily bars, print and audit views).</li>';
print '<li>Leave and absence management (registration, annual vacations, quotas, and HR monthly report).</li>';
print '<li>Role-based permissions for punch-clock, shifts, task management, vacation editing, and HR reporting.</li>';
print '</ul>';

print '<h3>5) Monitoring, notifications, and diagnostics</h3>';
print '<ul>';
print '<li>Sync Health dashboard with cross-domain metrics (invoices, products, punch-clock, API behavior).</li>';
print '<li>Error trends, top failures, latency indicators, pending/processing counters, and operational windows.</li>';
print '<li>Health notifications with configurable thresholds and cooldown strategy.</li>';
print '<li>Telegram integration for missed punch notifications and operational alerting.</li>';
print '<li>Diagnostic tooling for class/trait integrity and deployment troubleshooting.</li>';
print '</ul>';

print '<h3>6) Automation and cron orchestration</h3>';
print '<p>The module registers production cron jobs for invoice import/synchronization, punch-clock storage/pairing, inter-entity conversion, health checks, notification routines, and scheduled price updates.</p>';
print '<table>';
print '<thead><tr><th>Cron method</th><th>Frequency</th><th>Purpose</th></tr></thead>';
print '<tbody>';
print '<tr><td><code>importInvoicesHeaders</code></td><td>15 min</td><td>Fetch and store invoice headers.</td></tr>';
print '<tr><td><code>synchronizeAllUnsynchronizedInvoicesByStore</code></td><td>15 min</td><td>Process pending invoice queue by store.</td></tr>';
print '<tr><td><code>synchronizeYesterdayInvoices</code></td><td>24 h</td><td>Replay previous-day synchronization.</td></tr>';
print '<tr><td><code>performStoreZsPunchclock</code></td><td>15 min</td><td>Collect punch-clock entries.</td></tr>';
print '<tr><td><code>performPairTimeRegisters</code></td><td>24 h</td><td>Pair IN/OUT and prepare operational records.</td></tr>';
print '<tr><td><code>processInterEntityInvoices</code></td><td>60 min</td><td>Run inter-entity conversion (when enabled).</td></tr>';
print '<tr><td><code>notifyMissedPunches</code></td><td>24 h</td><td>Alert missing punch scenarios.</td></tr>';
print '<tr><td><code>checkHealthAndNotify</code></td><td>60 min</td><td>Evaluate sync health and send alerts.</td></tr>';
print '<tr><td><code>processScheduledPriceUpdates</code></td><td>5 min</td><td>Execute queued scheduled price updates.</td></tr>';
print '</tbody>';
print '</table>';

print '<h2>Admin surfaces</h2>';
print '<ul>';
print '<li><strong>Setup:</strong> API credentials, invoice/product sync behavior, health and notification thresholds, inter-entity options.</li>';
print '<li><strong>Mapping:</strong> operational cross-system mappings and store/entity definitions.</li>';
print '<li><strong>Planner setup:</strong> punch-clock, shift windows, leave colors/rules, HR report behavior.</li>';
print '<li><strong>Sync Tools:</strong> force/recovery actions for invoices and master data.</li>';
print '<li><strong>Sync Health:</strong> operational observability dashboard and test notification actions.</li>';
print '<li><strong>Telegram Setup:</strong> chat/API key management and notification tests.</li>';
print '<li><strong>Specialized pages:</strong> product equivalence, extrafields setup, manual ZSBMS creator, and diagnostics.</li>';
print '</ul>';

print '<h2>Requirements</h2>';
print '<ul>';
print '<li>Dolibarr >= 11</li>';
print '<li>PHP >= 7.0</li>';
print '<li>MySQL or MariaDB</li>';
print '<li>Operational access to Zone Soft API endpoints and credentials.</li>';
print '<li>Recommended enabled modules: Invoices, Products, Stock, Third parties, HRM (for workforce flows), and multicompany (for inter-entity scenarios).</li>';
print '</ul>';

print '<h2>Installation and go-live</h2>';
print '<ol>';
print '<li>Copy the module to <code>custom/dolizsynch</code>.</li>';
print '<li>Enable it in <strong>Configuration -&gt; Modules/Applications</strong>.</li>';
print '<li>Configure API and synchronization constants in setup pages.</li>';
print '<li>Complete mappings (families/categories, payment types, accounts, units, stores/entities).</li>';
print '<li>Run initial master-data synchronization and validate dictionary consistency.</li>';
print '<li>Run initial invoice-header and full invoice synchronization, then validate the resulting synchronization records.</li>';
print '</ol>';

print '<h2>Configuration (main constants)</h2>';
print '<table>';
print '<thead><tr><th>Constant</th><th>Description</th></tr></thead>';
print '<tbody>';
print '<tr><td><code>ZS_API_HOST</code></td><td>Zone Soft API endpoint host.</td></tr>';
print '<tr><td><code>ZS_API_VERSION</code></td><td>Target API version.</td></tr>';
print '<tr><td><code>ZS_APP_KEY</code></td><td>API application key.</td></tr>';
print '<tr><td><code>ZS_APP_SECRET</code></td><td>API application secret.</td></tr>';
print '<tr><td><code>ZS_CLIENT_ID</code></td><td>Client identifier for API authentication.</td></tr>';
print '<tr><td><code>ZS_API_STORE</code></td><td>Default Zone Soft store used by synchronization routines.</td></tr>';
print '<tr><td><code>ZS_IVA_INCLUIDO</code></td><td>VAT-included behavior for invoice data treatment.</td></tr>';
print '<tr><td><code>ZS_DEFAULT_PAYMENT_TYPE</code></td><td>Fallback payment type mapping.</td></tr>';
print '<tr><td><code>ZS_GET_NUMBER_OF_INVOICES_LIMIT</code></td><td>Header-fetch page size for invoices.</td></tr>';
print '<tr><td><code>ZS_MAX_NUMBER_OF_INVOICES_IN_EACH_SYNCHRONIZATION</code></td><td>Maximum invoices processed per synchronization run.</td></tr>';
print '<tr><td><code>DOLIZSYNCH_ZSBMS_INVOICE_CREATOR_ENABLED</code></td><td>Enable manual ZSBMS invoice creator workflows.</td></tr>';
print '<tr><td><code>DOLIZSYNCH_INTERENTITY_INVOICE_ENABLED</code></td><td>Enable inter-entity invoice conversion flow.</td></tr>';
print '<tr><td><code>ZS_TELEGRAM_CHAT_ID</code></td><td>Telegram chat target for notifications.</td></tr>';
print '<tr><td><code>ZS_TELEGRAM_API_KEY</code></td><td>Telegram bot/API key for alert delivery.</td></tr>';
print '<tr><td><code>ZS_PUNCH_CLOCK_ACTIVATE</code></td><td>Enable punch-clock synchronization workflows.</td></tr>';
print '<tr><td><code>DOLIZSYNCH_SHIFT_DAY_START</code></td><td>Planner day-start boundary.</td></tr>';
print '<tr><td><code>DOLIZSYNCH_SHIFT_MINUTE_STEP</code></td><td>Shift-planner granularity (minutes).</td></tr>';
print '</tbody>';
print '</table>';

print '<h2>Permissions model</h2>';
print '<ul>';
print '<li>Punch-clock rights: read, write, delete.</li>';
print '<li>Shift rights: read, write, delete, task management, employee-hours editing.</li>';
print '<li>HR/report rights: schedule report view, vacation view/edit, schedule audit log view.</li>';
print '<li>Manual ZSBMS invoice creator access right.</li>';
print '</ul>';

print '<h2>License</h2>';
print '<ul>';
print '<li>GPL-3.0-or-later for open-source distribution.</li>';
print '<li>Proprietary license available for commercial/closed-source deployments.</li>';
print '</ul>';

print '<h2>Support and links</h2>';
print '<ul>';
print '<li>Website: <a target="_blank" rel="noopener noreferrer" href="https://www.kreativitat.com">https://www.kreativitat.com</a></li>';
print '<li>GitHub: <a target="_blank" rel="noopener noreferrer" href="https://github.com/kreativitat">https://github.com/kreativitat</a></li>';
print '<li>Support: <a href="mailto:' . dol_escape_htmltag($supportEmail) . '">' . dol_escape_htmltag($supportEmail) . '</a></li>';
print '</ul>';

print '<h2>Disclaimer</h2>';
print '<p>This module is provided \"as is\", without warranties of any kind, express or implied, including merchantability and fitness for a particular purpose. The user is solely responsible for configuration quality, data validation, and legal/accounting compliance in their jurisdiction.</p>';
print '</div>';

// Custom about block with branding and key facts
$logoUrl = dol_buildpath('/custom/kreaflow/img/kreaflow-logo-transparent.png', 1);
$moduleName = $tmpmodule->getName();
$moduleVersion = $tmpmodule->getVersion();
$editorName = $tmpmodule->editor_name;
$editorUrl = $tmpmodule->editor_url;
$supportEmail = 'mail@kreativitat.com';

print '<div class="fichecenter" style="margin-top: 18px; display:flex; gap:24px; align-items:flex-start; flex-wrap:wrap;">';

print '<div style="flex:1 1 260px; max-width:320px; text-align:center;">';
print '<img src="' . $logoUrl . '" alt="' . dol_escape_htmltag($moduleName) . '" style="max-width: 260px; height: auto;">';
print '</div>';

print '<div style="flex:2 1 340px; min-width:320px;">';
print '<table class="border centpercent tableforfield">';
print '<tr><td class="titlefield">' . $langs->trans('KreapAboutModuleLabel') . '</td><td>' . dol_escape_htmltag($moduleName) . '</td></tr>';
print '<tr><td class="titlefield">' . $langs->trans('KreapAboutVersionLabel') . '</td><td>' . dol_escape_htmltag($moduleVersion) . '</td></tr>';
print '<tr><td class="titlefield">' . $langs->trans('KreapAboutEditorLabel') . '</td><td><a href="' . dol_escape_htmltag($editorUrl) . '" target="_blank" rel="noopener noreferrer">' . dol_escape_htmltag($editorName) . '</a></td></tr>';
print '<tr><td class="titlefield">' . $langs->trans('KreapAboutLicenseLabel') . '</td><td>' . $langs->trans('KreapAboutLicenseValue') . '</td></tr>';
print '<tr><td class="titlefield">' . $langs->trans('KreapAboutSupportLabel') . '</td><td><a href="mailto:' . dol_escape_htmltag($supportEmail) . '">' . dol_escape_htmltag($supportEmail) . '</a></td></tr>';
print '<tr><td class="titlefield">' . $langs->trans('KreapAboutWebsiteLabel') . '</td><td><a href="' . dol_escape_htmltag($editorUrl) . '" target="_blank" rel="noopener noreferrer">' . dol_escape_htmltag($editorUrl) . '</a></td></tr>';
print '</table>';
print '</div>';


// Page end
print dol_get_fiche_end();
llxFooter();
$db->close();

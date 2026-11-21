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
 * \file        zspunchclock/zspunchclock_all_list.php
 * \ingroup     dolizsynch
 * \brief       Date-range list for paired punch-clock records.
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
if (!$res && file_exists("../main.inc.php")) {
	$res = @include "../main.inc.php";
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

require_once DOL_DOCUMENT_ROOT . '/core/class/html.form.class.php';
require_once DOL_DOCUMENT_ROOT . '/core/lib/date.lib.php';

/**
 * Normalize a YYYY-MM-DD date received from a list filter.
 *
 * @param string $value Raw date value
 * @return string
 */
function dolizsynch_punchclock_list_normalize_date($value)
{
	$value = trim((string) $value);
	if ($value !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
		return $value;
	}

	return '';
}

/**
 * Format a duration stored in seconds as HH:MM.
 *
 * @param int|string|null $seconds Duration in seconds
 * @return string
 */
function dolizsynch_punchclock_list_format_duration($seconds)
{
	if ($seconds === null || $seconds === '') {
		return '';
	}

	$seconds = max(0, (int) $seconds);
	$hours = floor($seconds / 3600);
	$minutes = floor(($seconds % 3600) / 60);

	return sprintf('%02d:%02d', $hours, $minutes);
}

global $conf, $db, $hookmanager, $langs, $user;

$langs->loadLangs(array('dolizsynch@kreaflow', 'other', 'users', 'projects'));

if (!isModEnabled('dolizsynch')) {
	accessforbidden('Module dolizsynch not enabled');
}
if ($user->socid > 0) {
	accessforbidden();
}
if (empty($user->rights->dolizsynch->zspunchclock->read)) {
	accessforbidden();
}

$form = new Form($db);
$hookmanager->initHooks(array('zspunchclockalllist'));

$action = GETPOST('action', 'aZ09') ? GETPOST('action', 'aZ09') : 'list';
$contextpage = GETPOST('contextpage', 'aZ') ? GETPOST('contextpage', 'aZ') : 'zspunchclockalllist';
$optioncss = GETPOST('optioncss', 'aZ');
$kreatoolsExportAll = GETPOSTINT('kreatools_export_all');

$limit = GETPOST('limit', 'int') ? GETPOST('limit', 'int') : $conf->liste_limit;
$page = GETPOSTISSET('pageplusone') ? (GETPOST('pageplusone', 'int') - 1) : GETPOST('page', 'int');
if (empty($page) || $page < 0 || GETPOST('button_search', 'alpha') || GETPOST('button_removefilter', 'alpha')) {
	$page = 0;
}
$offset = $limit * $page;
$pageprev = $page - 1;
$pagenext = $page + 1;

$sortfield = GETPOST('sortfield', 'aZ09comma');
$sortorder = strtoupper(GETPOST('sortorder', 'aZ09comma'));
$allowedSortFields = array(
	't.rowid' => 't.rowid',
	't.dataval' => 't.dataval',
	't.datahora_in' => 't.datahora_in',
	't.datahora_out' => 't.datahora_out',
	't.duration' => 't.duration',
	't.empid_zs' => 't.empid_zs',
	't.real_entry' => 't.real_entry',
	't.tms' => 't.tms',
	'employee_fullname' => 'employee_fullname',
	'task_ref' => 'task_ref',
);
if (empty($sortfield) || !isset($allowedSortFields[$sortfield])) {
	$sortfield = 't.dataval';
}
if ($sortorder !== 'ASC' && $sortorder !== 'DESC') {
	$sortorder = 'DESC';
}

$search_fullname = GETPOST('search_fullname', 'alphanohtml');
$search_empid_zs = GETPOST('search_empid_zs', 'alphanohtml');
$search_source = GETPOST('search_source', 'alpha');
$search_task = GETPOST('search_task', 'alphanohtml');
$search_date_start = dolizsynch_punchclock_list_normalize_date(GETPOST('search_date_start', 'alpha'));
$search_date_end = dolizsynch_punchclock_list_normalize_date(GETPOST('search_date_end', 'alpha'));
$legacy_search_date = dolizsynch_punchclock_list_normalize_date(GETPOST('search_date', 'alpha'));

if (GETPOST('button_removefilter', 'alpha')) {
	$search_fullname = '';
	$search_empid_zs = '';
	$search_source = '';
	$search_task = '';
	$search_date_start = '';
	$search_date_end = '';
	$legacy_search_date = '';
}

if ($legacy_search_date !== '' && $search_date_start === '' && $search_date_end === '') {
	$search_date_start = $legacy_search_date;
	$search_date_end = $legacy_search_date;
}

if ($search_date_start === '' && $search_date_end === '') {
	$search_date_start = date('Y-m-01');
	$search_date_end = date('Y-m-d');
}
if ($search_date_start !== '' && $search_date_end === '') {
	$search_date_end = $search_date_start;
}
if ($search_date_end !== '' && $search_date_start === '') {
	$search_date_start = $search_date_end;
}
if ($search_date_start > $search_date_end) {
	$tmpdate = $search_date_start;
	$search_date_start = $search_date_end;
	$search_date_end = $tmpdate;
}

$arrayfields = array(
	't.dataval' => array('label' => $langs->trans('PunchClockReferenceDay'), 'checked' => 1, 'enabled' => 1, 'position' => 10),
	'employee_fullname' => array('label' => $langs->trans('zspunchclock_fullname'), 'checked' => 1, 'enabled' => 1, 'position' => 20),
	't.empid_zs' => array('label' => $langs->trans('zspunchclock_employee_id'), 'checked' => 1, 'enabled' => 1, 'position' => 30),
	't.datahora_in' => array('label' => $langs->trans('CheckInTime'), 'checked' => 1, 'enabled' => 1, 'position' => 40),
	't.datahora_out' => array('label' => $langs->trans('CheckOutTime'), 'checked' => 1, 'enabled' => 1, 'position' => 50),
	't.duration' => array('label' => $langs->trans('ShiftDuration'), 'checked' => 1, 'enabled' => 1, 'position' => 60),
	't.real_entry' => array('label' => $langs->trans('PunchClockSource'), 'checked' => 1, 'enabled' => 1, 'position' => 70),
	'task_ref' => array('label' => $langs->trans('PunchClockTask'), 'checked' => 1, 'enabled' => 1, 'position' => 80),
	't.tms' => array('label' => $langs->trans('PunchClockModifiedBy'), 'checked' => 1, 'enabled' => 1, 'position' => 90),
);
$arrayfields = dol_sort_array($arrayfields, 'position');

include DOL_DOCUMENT_ROOT . '/core/actions_changeselectedfields.inc.php';

$currentEntity = (int) $conf->entity;
$userEntityList = getEntity('user');
if ($userEntityList === '') {
	$userEntityList = (string) (int) $conf->entity;
}
$projectEntityList = getEntity('project');
if ($projectEntityList === '') {
	$projectEntityList = (string) (int) $conf->entity;
}

$employeeSubquery = "SELECT ue.zs_employee_id, MIN(u.rowid) AS rowid, MIN(u.firstname) AS firstname, MIN(u.lastname) AS lastname,";
$employeeSubquery .= " TRIM(CONCAT(COALESCE(MIN(u.firstname), ''), ' ', COALESCE(MIN(u.lastname), ''))) AS fullname";
$employeeSubquery .= " FROM " . MAIN_DB_PREFIX . "user AS u";
$employeeSubquery .= " INNER JOIN " . MAIN_DB_PREFIX . "user_extrafields AS ue ON ue.fk_object = u.rowid";
$employeeSubquery .= " WHERE u.entity IN (" . $userEntityList . ")";
$employeeSubquery .= " AND u.statut = 1";
$employeeSubquery .= " AND ue.zs_employee_id IS NOT NULL AND ue.zs_employee_id > 0";
$employeeSubquery .= " GROUP BY ue.zs_employee_id";

$sqlFrom = " FROM " . MAIN_DB_PREFIX . "dolizsynch_zspunchclock AS t";
$sqlFrom .= " LEFT JOIN " . MAIN_DB_PREFIX . "user AS u ON t.fk_user = u.rowid AND u.entity IN (" . $userEntityList . ")";
$sqlFrom .= " LEFT JOIN (" . $employeeSubquery . ") AS eu ON eu.zs_employee_id = t.empid_zs";
$sqlFrom .= " LEFT JOIN " . MAIN_DB_PREFIX . "user AS um ON t.fk_user_modif = um.rowid AND um.entity IN (" . $userEntityList . ")";
$sqlFrom .= " LEFT JOIN " . MAIN_DB_PREFIX . "projet_task AS task ON t.fk_task = task.rowid";
$sqlFrom .= " LEFT JOIN " . MAIN_DB_PREFIX . "projet AS project ON task.fk_projet = project.rowid AND project.entity IN (" . $projectEntityList . ")";
$sqlFrom .= " WHERE t.entity = " . $currentEntity;

if ($search_date_start !== '') {
	$sqlFrom .= " AND t.dataval >= '" . $db->escape($search_date_start) . "'";
}
if ($search_date_end !== '') {
	$sqlFrom .= " AND t.dataval <= '" . $db->escape($search_date_end) . "'";
}
if ($search_fullname !== '') {
	$sqlFrom .= " AND (TRIM(CONCAT(COALESCE(u.firstname, ''), ' ', COALESCE(u.lastname, ''))) LIKE '%" . $db->escape($search_fullname) . "%'";
	$sqlFrom .= " OR eu.fullname LIKE '%" . $db->escape($search_fullname) . "%')";
}
if ($search_empid_zs !== '') {
	$sqlFrom .= " AND t.empid_zs LIKE '%" . $db->escape($search_empid_zs) . "%'";
}
if ($search_source !== '' && in_array($search_source, array('0', '1'), true)) {
	$sqlFrom .= " AND t.real_entry = " . (int) $search_source;
}
if ($search_task !== '') {
	$sqlFrom .= " AND project.rowid IS NOT NULL";
	$sqlFrom .= " AND (task.ref LIKE '%" . $db->escape($search_task) . "%' OR task.label LIKE '%" . $db->escape($search_task) . "%')";
}

$sql = "SELECT t.rowid, t.entity, t.ref, t.empid_zs, t.dataval, t.datahora_in, t.datahora_out,";
$sql .= " t.duration, t.real_entry, t.fk_task, t.tms,";
$sql .= " CASE WHEN u.rowid IS NOT NULL THEN TRIM(CONCAT(COALESCE(u.firstname, ''), ' ', COALESCE(u.lastname, ''))) ELSE eu.fullname END AS employee_fullname,";
$sql .= " COALESCE(u.rowid, eu.rowid) AS fk_user_resolved,";
$sql .= " CASE WHEN project.rowid IS NOT NULL THEN task.rowid ELSE NULL END AS task_rowid,";
$sql .= " CASE WHEN project.rowid IS NOT NULL THEN task.ref ELSE NULL END AS task_ref,";
$sql .= " CASE WHEN project.rowid IS NOT NULL THEN task.label ELSE NULL END AS task_label,";
$sql .= " TRIM(CONCAT(COALESCE(um.firstname, ''), ' ', COALESCE(um.lastname, ''))) AS modif_fullname";
$sql .= $sqlFrom;
$sql .= " ORDER BY " . $allowedSortFields[$sortfield] . " " . $sortorder . ", t.dataval DESC, t.datahora_in DESC, t.rowid DESC";

$nbtotalofrecords = '';
$disableFullScanList = getDolGlobalInt('MAIN_DISABLE_FULL_SCANLIST');
if (!$disableFullScanList) {
	$sqlforcount = "SELECT COUNT(DISTINCT t.rowid) AS nbtotalofrecords" . $sqlFrom;
	$resql_count = $db->query($sqlforcount);
	if ($resql_count) {
		$objforcount = $db->fetch_object($resql_count);
		$nbtotalofrecords = (int) $objforcount->nbtotalofrecords;
		$db->free($resql_count);
	} else {
		dol_print_error($db);
	}
	if (($page * $limit) > $nbtotalofrecords) {
		$page = 0;
		$offset = 0;
	}
}

if ($kreatoolsExportAll) {
	$page = 0;
	$offset = 0;
	$limit = 0;
}

$sqltotals = "SELECT COUNT(DISTINCT t.rowid) AS total_records,";
$sqltotals .= " COUNT(DISTINCT CASE WHEN t.empid_zs > 0 THEN t.empid_zs END) AS total_employees,";
$sqltotals .= " SUM(COALESCE(t.duration, 0)) AS total_duration";
$sqltotals .= $sqlFrom;
$totalRecords = 0;
$totalEmployees = 0;
$totalDuration = 0;
if (!$disableFullScanList) {
	$resqltotals = $db->query($sqltotals);
	if ($resqltotals) {
		$objtotals = $db->fetch_object($resqltotals);
		$totalRecords = (int) $objtotals->total_records;
		$totalEmployees = (int) $objtotals->total_employees;
		$totalDuration = (int) $objtotals->total_duration;
		$db->free($resqltotals);
	}
}

if (!$kreatoolsExportAll) {
	$sql .= $db->plimit($limit + 1, $offset);
}
$resql = $db->query($sql);
if (!$resql) {
	dol_print_error($db);
	exit;
}
$num = $db->num_rows($resql);

$param = '';
if ($contextpage != '') {
	$param .= '&contextpage=' . urlencode($contextpage);
}
if ($limit > 0 && $limit != $conf->liste_limit) {
	$param .= '&limit=' . urlencode($limit);
}
if ($search_date_start !== '') {
	$param .= '&search_date_start=' . urlencode($search_date_start);
}
if ($search_date_end !== '') {
	$param .= '&search_date_end=' . urlencode($search_date_end);
}
if ($search_fullname !== '') {
	$param .= '&search_fullname=' . urlencode($search_fullname);
}
if ($search_empid_zs !== '') {
	$param .= '&search_empid_zs=' . urlencode($search_empid_zs);
}
if ($search_source !== '') {
	$param .= '&search_source=' . urlencode($search_source);
}
if ($search_task !== '') {
	$param .= '&search_task=' . urlencode($search_task);
}
if ($optioncss != '') {
	$param .= '&optioncss=' . urlencode($optioncss);
}

$kreatoolsExportParams = array(
	'action' => 'list',
	'contextpage' => $contextpage,
	'sortfield' => $sortfield,
	'sortorder' => $sortorder,
	'page' => 0,
	'kreatools_export_all' => 1,
);
if ($search_date_start !== '') {
	$kreatoolsExportParams['search_date_start'] = $search_date_start;
}
if ($search_date_end !== '') {
	$kreatoolsExportParams['search_date_end'] = $search_date_end;
}
if ($search_fullname !== '') {
	$kreatoolsExportParams['search_fullname'] = $search_fullname;
}
if ($search_empid_zs !== '') {
	$kreatoolsExportParams['search_empid_zs'] = $search_empid_zs;
}
if ($search_source !== '') {
	$kreatoolsExportParams['search_source'] = $search_source;
}
if ($search_task !== '') {
	$kreatoolsExportParams['search_task'] = $search_task;
}
if ($optioncss != '') {
	$kreatoolsExportParams['optioncss'] = $optioncss;
}
$kreatoolsExportUrl = $_SERVER["PHP_SELF"] . '?' . http_build_query($kreatoolsExportParams, '', '&');

$title = $langs->trans('ZSPunchClockAllList');

llxHeader('', $title);

print '<style>
.dolizsynch-punchclock-list tr.liste_titre > th,
.dolizsynch-punchclock-list tr.liste_titre > td {
	vertical-align: middle;
	padding-top: 10px;
	padding-bottom: 10px;
	line-height: 1.25;
	white-space: nowrap;
}
.dolizsynch-punchclock-list tr.liste_titre_filter > td {
	vertical-align: middle;
	padding-top: 8px;
	padding-bottom: 8px;
}
.dolizsynch-punchclock-list tr.liste_titre_filter input.flat {
	margin-top: 0;
	margin-bottom: 0;
}
.dolizsynch-punchclock-list .dolizsynch-date-filter {
	display: inline-flex;
	flex-direction: column;
	gap: 4px;
	align-items: flex-start;
}
.dolizsynch-punchclock-list .dolizsynch-date-filter input[type="date"] {
	box-sizing: border-box;
	min-width: 132px;
}
.dolizsynch-punchclock-list .dolizsynch-datetime-column {
	min-width: 150px;
	text-align: right !important;
	white-space: nowrap;
}
.dolizsynch-punchclock-list tr.liste_titre .dolizsynch-datetime-column a {
	display: inline;
	text-align: inherit !important;
}
.dolizsynch-punchclock-list .dolizsynch-list-action-cell {
	text-align: center;
	vertical-align: middle;
	white-space: nowrap;
}
.dolizsynch-punchclock-list .dolizsynch-list-action-cell button {
	margin: 0 1px;
}
</style>';

print '<form method="POST" id="searchFormList" action="' . dol_escape_htmltag($_SERVER["PHP_SELF"]) . '">' . "\n";
print '<input type="hidden" name="token" value="' . newToken() . '">';
print '<input type="hidden" name="formfilteraction" id="formfilteraction" value="list">';
print '<input type="hidden" name="action" value="list">';
print '<input type="hidden" name="sortfield" value="' . dol_escape_htmltag($sortfield) . '">';
print '<input type="hidden" name="sortorder" value="' . dol_escape_htmltag($sortorder) . '">';
print '<input type="hidden" name="page" value="' . (int) $page . '">';
print '<input type="hidden" name="contextpage" value="' . dol_escape_htmltag($contextpage) . '">';
if ($optioncss != '') {
	print '<input type="hidden" name="optioncss" value="' . dol_escape_htmltag($optioncss) . '">';
}

print_barre_liste($title, $page, $_SERVER["PHP_SELF"], $param, $sortfield, $sortorder, '', $num, $nbtotalofrecords, 'clock', 0, '', '', $limit);

$varpage = empty($contextpage) ? $_SERVER["PHP_SELF"] : $contextpage;
$checkboxleft = getDolGlobalString('MAIN_CHECKBOX_LEFT_COLUMN', '');
$selectedfields = $form->multiSelectArrayWithCheckbox('selectedfields', $arrayfields, $varpage, $checkboxleft);
$filterButtonsCell = '<td class="liste_titre dolizsynch-list-action-cell">';
$filterButtonsCell .= '<button type="submit" class="liste_titre button_search" name="button_search" value="x">' . img_picto($langs->trans("Search"), 'search') . '</button>';
$filterButtonsCell .= '<button type="submit" class="liste_titre button_removefilter" name="button_removefilter" value="x">' . img_picto($langs->trans("RemoveFilter"), 'remove') . '</button>';
$filterButtonsCell .= '</td>';

print '<div class="div-table-responsive">';
$tableAttributes = 'class="tagtable liste dolizsynch-punchclock-list"';
if ($kreatoolsExportUrl !== '') {
	$tableAttributes .= ' data-kreatools-export-source="dolizsynch-zspunchclock-all-list"';
	$tableAttributes .= ' data-kreatools-export-url="' . dol_escape_htmltag($kreatoolsExportUrl) . '"';
	$tableAttributes .= ' data-kreatools-export-title="' . dol_escape_htmltag($title) . '"';
}
print '<table ' . $tableAttributes . '>' . "\n";

print '<tr class="liste_titre">';
if ($checkboxleft) {
	print_liste_field_titre($selectedfields, $_SERVER["PHP_SELF"], '', '', '', '', $sortfield, $sortorder, 'center maxwidthsearch ');
}
if (!empty($arrayfields['t.dataval']['checked'])) {
	print_liste_field_titre($arrayfields['t.dataval']['label'], $_SERVER["PHP_SELF"], 't.dataval', '', $param, '', $sortfield, $sortorder);
}
if (!empty($arrayfields['employee_fullname']['checked'])) {
	print_liste_field_titre($arrayfields['employee_fullname']['label'], $_SERVER["PHP_SELF"], 'employee_fullname', '', $param, '', $sortfield, $sortorder);
}
if (!empty($arrayfields['t.empid_zs']['checked'])) {
	print_liste_field_titre($arrayfields['t.empid_zs']['label'], $_SERVER["PHP_SELF"], 't.empid_zs', '', $param, 'class="right"', $sortfield, $sortorder);
}
if (!empty($arrayfields['t.datahora_in']['checked'])) {
	print_liste_field_titre($arrayfields['t.datahora_in']['label'], $_SERVER["PHP_SELF"], 't.datahora_in', '', $param, '', $sortfield, $sortorder, 'right dolizsynch-datetime-column ');
}
if (!empty($arrayfields['t.datahora_out']['checked'])) {
	print_liste_field_titre($arrayfields['t.datahora_out']['label'], $_SERVER["PHP_SELF"], 't.datahora_out', '', $param, '', $sortfield, $sortorder, 'right dolizsynch-datetime-column ');
}
if (!empty($arrayfields['t.duration']['checked'])) {
	print_liste_field_titre($arrayfields['t.duration']['label'], $_SERVER["PHP_SELF"], 't.duration', '', $param, 'class="right"', $sortfield, $sortorder);
}
if (!empty($arrayfields['t.real_entry']['checked'])) {
	print_liste_field_titre($arrayfields['t.real_entry']['label'], $_SERVER["PHP_SELF"], 't.real_entry', '', $param, 'class="center"', $sortfield, $sortorder);
}
if (!empty($arrayfields['task_ref']['checked'])) {
	print_liste_field_titre($arrayfields['task_ref']['label'], $_SERVER["PHP_SELF"], 'task_ref', '', $param, '', $sortfield, $sortorder);
}
if (!empty($arrayfields['t.tms']['checked'])) {
	print_liste_field_titre($arrayfields['t.tms']['label'], $_SERVER["PHP_SELF"], 't.tms', '', $param, '', $sortfield, $sortorder);
}
if (!$checkboxleft) {
	print_liste_field_titre($selectedfields, $_SERVER["PHP_SELF"], '', '', '', '', $sortfield, $sortorder, 'center maxwidthsearch ');
}
print '</tr>' . "\n";

print '<tr class="liste_titre_filter">';
if ($checkboxleft) {
	print $filterButtonsCell;
}
if (!empty($arrayfields['t.dataval']['checked'])) {
	print '<td class="liste_titre nowrap">';
	print '<div class="dolizsynch-date-filter">';
	print '<div class="nowrapfordate">';
	print '<input type="date" class="flat minwidth100" name="search_date_start" value="' . dol_escape_htmltag($search_date_start) . '" title="' . dol_escape_htmltag($langs->trans('PunchClockDateStart')) . '">';
	print '</div>';
	print '<div class="nowrapfordate">';
	print '<input type="date" class="flat minwidth100" name="search_date_end" value="' . dol_escape_htmltag($search_date_end) . '" title="' . dol_escape_htmltag($langs->trans('PunchClockDateEnd')) . '">';
	print '</div>';
	print '</div>';
	print '</td>';
}
if (!empty($arrayfields['employee_fullname']['checked'])) {
	print '<td class="liste_titre"><input type="text" class="flat maxwidth150" name="search_fullname" value="' . dol_escape_htmltag($search_fullname) . '"></td>';
}
if (!empty($arrayfields['t.empid_zs']['checked'])) {
	print '<td class="liste_titre right"><input type="text" class="flat width75 right" name="search_empid_zs" value="' . dol_escape_htmltag($search_empid_zs) . '"></td>';
}
if (!empty($arrayfields['t.datahora_in']['checked'])) {
	print '<td class="liste_titre dolizsynch-datetime-column"></td>';
}
if (!empty($arrayfields['t.datahora_out']['checked'])) {
	print '<td class="liste_titre dolizsynch-datetime-column"></td>';
}
if (!empty($arrayfields['t.duration']['checked'])) {
	print '<td class="liste_titre"></td>';
}
if (!empty($arrayfields['t.real_entry']['checked'])) {
	print '<td class="liste_titre center">';
	print $form->selectarray('search_source', array('1' => $langs->trans('PunchClockSourceZoneSoft'), '0' => $langs->trans('PunchClockSourceManual')), $search_source, 1, 0, 0, '', 0, 0, 0, '', 'minwidth100');
	print '</td>';
}
if (!empty($arrayfields['task_ref']['checked'])) {
	print '<td class="liste_titre"><input type="text" class="flat maxwidth125" name="search_task" value="' . dol_escape_htmltag($search_task) . '"></td>';
}
if (!empty($arrayfields['t.tms']['checked'])) {
	print '<td class="liste_titre"></td>';
}
if (!$checkboxleft) {
	print $filterButtonsCell;
}
print '</tr>' . "\n";

$i = 0;
$imaxinloop = ($limit ? min($num, $limit) : $num);
$pageTotalRecords = 0;
$pageTotalEmployees = array();
$pageTotalDuration = 0;
while ($i < $imaxinloop) {
	$obj = $db->fetch_object($resql);
	$duration = $obj->duration;
	if (($duration === null || $duration === '') && !empty($obj->datahora_in) && !empty($obj->datahora_out)) {
		$duration = strtotime($obj->datahora_out) - strtotime($obj->datahora_in);
	}
	$pageTotalRecords++;
	if (!empty($obj->empid_zs)) {
		$pageTotalEmployees[(int) $obj->empid_zs] = true;
	}
	$pageTotalDuration += max(0, (int) $duration);

	print '<tr class="oddeven">';
	if ($checkboxleft) {
		print '<td></td>';
	}
	if (!empty($arrayfields['t.dataval']['checked'])) {
		print '<td class="nowraponall">' . dol_print_date($db->jdate($obj->dataval), 'day') . '</td>';
	}
	if (!empty($arrayfields['employee_fullname']['checked'])) {
		print '<td>' . dol_escape_htmltag($obj->employee_fullname ?: '') . '</td>';
	}
	if (!empty($arrayfields['t.empid_zs']['checked'])) {
		print '<td class="right">' . dol_escape_htmltag($obj->empid_zs) . '</td>';
	}
	if (!empty($arrayfields['t.datahora_in']['checked'])) {
		print '<td class="right nowraponall dolizsynch-datetime-column">' . (!empty($obj->datahora_in) ? dol_print_date($db->jdate($obj->datahora_in), 'dayhour') : '') . '</td>';
	}
	if (!empty($arrayfields['t.datahora_out']['checked'])) {
		print '<td class="right nowraponall dolizsynch-datetime-column">' . (!empty($obj->datahora_out) ? dol_print_date($db->jdate($obj->datahora_out), 'dayhour') : '') . '</td>';
	}
	if (!empty($arrayfields['t.duration']['checked'])) {
		print '<td class="right nowraponall">' . dolizsynch_punchclock_list_format_duration($duration) . '</td>';
	}
	if (!empty($arrayfields['t.real_entry']['checked'])) {
		print '<td class="center nowraponall">';
		if ((int) $obj->real_entry === 1) {
			print '<span class="badge badge-status4">' . dol_escape_htmltag($langs->trans('PunchClockSourceZoneSoft')) . '</span>';
		} else {
			print '<span class="badge badge-status2">' . dol_escape_htmltag($langs->trans('PunchClockSourceManual')) . '</span>';
		}
		print '</td>';
	}
	if (!empty($arrayfields['task_ref']['checked'])) {
		print '<td>';
		if (!empty($obj->task_rowid)) {
			$taskLabel = trim((string) $obj->task_ref . ' ' . (string) $obj->task_label);
			print '<a href="' . DOL_URL_ROOT . '/projet/tasks/time.php?id=' . (int) $obj->task_rowid . '&withproject=1">';
			print dol_escape_htmltag($taskLabel !== '' ? $taskLabel : $obj->task_ref);
			print '</a>';
		}
		print '</td>';
	}
	if (!empty($arrayfields['t.tms']['checked'])) {
		print '<td class="nowraponall">';
		if (!empty($obj->modif_fullname)) {
			print dol_escape_htmltag($obj->modif_fullname) . '<br>';
		}
		print '<span class="opacitymedium">' . (!empty($obj->tms) ? dol_print_date($db->jdate($obj->tms), 'dayhour') : '') . '</span>';
		print '</td>';
	}
	if (!$checkboxleft) {
		print '<td></td>';
	}
	print '</tr>' . "\n";
	$i++;
}

if ($disableFullScanList) {
	$totalRecords = $pageTotalRecords;
	$totalEmployees = count($pageTotalEmployees);
	$totalDuration = $pageTotalDuration;
}

if ($num == 0) {
	$totalnboffields = 1;
	foreach ($arrayfields as $field) {
		if (!empty($field['checked'])) {
			$totalnboffields++;
		}
	}
	print '<tr class="oddeven"><td colspan="' . (int) $totalnboffields . '" class="opacitymedium center">' . $langs->trans('NoRecordFound') . '</td></tr>' . "\n";
}

print '<tr class="liste_total">';
if ($checkboxleft) {
	print '<td></td>';
}
$firstTotalCell = true;
foreach ($arrayfields as $fieldKey => $field) {
	if (empty($field['checked'])) {
		continue;
	}
	if ($firstTotalCell) {
		print '<td>' . $langs->trans('PunchClockRows') . ': ' . (int) $totalRecords . '</td>';
		$firstTotalCell = false;
	} elseif ($fieldKey === 't.empid_zs') {
		print '<td class="right">' . $langs->trans('Employees') . ': ' . (int) $totalEmployees . '</td>';
	} elseif ($fieldKey === 't.duration') {
		print '<td class="right">' . dolizsynch_punchclock_list_format_duration($totalDuration) . '</td>';
	} else {
		print '<td></td>';
	}
}
if (!$checkboxleft) {
	print '<td></td>';
}
print '</tr>' . "\n";

print '</table>';
print '</div>';
print '</form>';

$db->free($resql);

llxFooter();
$db->close();

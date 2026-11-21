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

/* DoliZSynch leave list (uses llx_dolizsynch_leave) following Dolibarr list infrastructure */

require '../../../main.inc.php';
require_once DOL_DOCUMENT_ROOT . '/core/class/html.form.class.php';
require_once DOL_DOCUMENT_ROOT . '/core/lib/date.lib.php';
require_once DOL_DOCUMENT_ROOT . '/user/class/user.class.php';
require_once __DIR__ . '/../class/zsleaves.class.php';
require_once __DIR__ . '/../lib/shiftplanner.lib.php';

global $db, $langs, $user, $conf, $hookmanager;

$langs->loadLangs(array('users', 'other', 'holiday', 'main'));

if (empty($conf->dolizsynch->enabled)) {
	accessforbidden();
}
if (empty($user->rights->dolizsynch->shift->read)) {
	accessforbidden();
}
if ($user->socid > 0) {
	accessforbidden();
}

$action = GETPOST('action', 'aZ09');
$massaction = GETPOST('massaction', 'alpha');
$show_files = GETPOSTINT('show_files');
$confirm = GETPOST('confirm', 'alpha');
$cancel = GETPOST('cancel', 'alpha');
$toselect = GETPOST('toselect', 'array');
$contextpage = GETPOST('contextpage', 'aZ') ?: 'dolizsynchholidaylist';
$mode = GETPOST('mode', 'alpha');
$backtopage = GETPOST('backtopage', 'alpha');
$optioncss = GETPOST('optioncss', 'aZ');

$id = GETPOSTINT('id');

$limit = GETPOSTINT('limit') ? GETPOSTINT('limit') : $conf->liste_limit;
$sortfield = GETPOST('sortfield', 'aZ09comma') ?: 'l.date_start';
$sortorder = GETPOST('sortorder', 'aZ09comma') ?: 'DESC';
$page = GETPOSTISSET('pageplusone') ? (GETPOSTINT('pageplusone') - 1) : GETPOSTINT("page");
if (empty($page) || $page < 0) {
	$page = 0;
}
$offset = $limit * $page;
$pageprev = $page - 1;
$pagenext = $page + 1;

$search_all = trim(GETPOST('search_all', 'alphanohtml'));
$search_employee = GETPOST('search_employee', 'intcomma');
$search_type = GETPOST('search_type', 'intcomma');
$search_day_start = GETPOST('search_cxdate_dtstartday', 'int');
$search_month_start = GETPOST('search_cxdate_dtstartmonth', 'int');
$search_year_start = GETPOST('search_cxdate_dtstartyear', 'int');
$search_day_end = GETPOST('search_cxdate_dtendday', 'int');
$search_month_end = GETPOST('search_cxdate_dtendmonth', 'int');
$search_year_end = GETPOST('search_cxdate_dtendyear', 'int');

$legacy_day_start = GETPOST('search_day_start', 'int');
$legacy_month_start = GETPOST('search_month_start', 'int');
$legacy_year_start = GETPOST('search_year_start', 'int');
$legacy_day_end = GETPOST('search_day_end', 'int');
$legacy_month_end = GETPOST('search_month_end', 'int');
$legacy_year_end = GETPOST('search_year_end', 'int');

if (empty($search_day_start) && !empty($legacy_day_start)) $search_day_start = $legacy_day_start;
if (empty($search_month_start) && !empty($legacy_month_start)) $search_month_start = $legacy_month_start;
if (empty($search_year_start) && !empty($legacy_year_start)) $search_year_start = $legacy_year_start;
if (empty($search_day_end) && !empty($legacy_day_end)) $search_day_end = $legacy_day_end;
if (empty($search_month_end) && !empty($legacy_month_end)) $search_month_end = $legacy_month_end;
if (empty($search_year_end) && !empty($legacy_year_end)) $search_year_end = $legacy_year_end;

$object = new ZSLeaves($db);
$hookmanager->initHooks(array('dolizsynchholidaylist'));

$maxShiftHours = (float) getDolGlobalString('DOLIZSYNCH_SHIFT_MAX_HOURS', '8');
if ($maxShiftHours <= 0) {
	$maxShiftHours = 8;
}
$dayMinutesBase = $maxShiftHours * 60;

if ($cancel) {
	$action = 'list';
	$massaction = '';
}
if (!GETPOST('confirmmassaction', 'alpha') && $massaction != 'presend' && $massaction != 'confirm_presend') {
	$massaction = '';
}

// Purge search criteria
if (GETPOST('button_removefilter_x', 'alpha') || GETPOST('button_removefilter.x', 'alpha') || GETPOST('button_removefilter', 'alpha')) {
	$search_all = '';
	$search_employee = '';
	$search_type = '';
	$search_day_start = $search_month_start = $search_year_start = '';
	$search_day_end = $search_month_end = $search_year_end = '';
	$toselect = array();
}
if (GETPOST('button_removefilter_x', 'alpha') || GETPOST('button_removefilter.x', 'alpha') || GETPOST('button_removefilter', 'alpha')
	|| GETPOST('button_search_x', 'alpha') || GETPOST('button_search.x', 'alpha') || GETPOST('button_search', 'alpha')) {
	$massaction = '';
}

// Mass actions (disabled)
$arrayofmassactions = array();
$massactionbutton = '';

/*
 * View
 */

$form = new Form($db);
$search_start_ts = 0;
if (!empty($search_day_start) && !empty($search_month_start) && !empty($search_year_start)) {
	$search_start_ts = dol_mktime(0, 0, 0, $search_month_start, $search_day_start, $search_year_start);
}
$search_end_ts = 0;
if (!empty($search_day_end) && !empty($search_month_end) && !empty($search_year_end)) {
	$search_end_ts = dol_mktime(23, 59, 59, $search_month_end, $search_day_end, $search_year_end);
}

$title = $langs->trans('Férias licenças e faltas');
$help_url = '';

llxHeader('', $title, $help_url, '', 0, 0, '', '', '', 'bodyforlist mod-holiday page-list');

$dayChangeHourDefault = shiftplanner_get_day_change_hour();

$sql = "SELECT";
$sql .= " l.rowid,";
$sql .= " l.fk_user,";
$sql .= " l.fk_type,";
$sql .= " l.date_start,";
$sql .= " l.date_end,";
$sql .= " l.halfday,";
$sql .= " l.minutes_total,";
$sql .= " l.description,";
$sql .= " l.date_creation,";
$sql .= " l.tms,";
$sql .= " u.lastname as user_lastname,";
$sql .= " u.firstname as user_firstname,";
$sql .= " u.login as user_login,";
$sql .= " u.statut as user_status,";
$sql .= " u.photo as user_photo,";
$sql .= " t.label as type_label, t.code as type_code,";
$sql .= " l.day_change_hour";
$sql .= " FROM " . MAIN_DB_PREFIX . "dolizsynch_leave as l";
$sql .= " LEFT JOIN " . MAIN_DB_PREFIX . "user as u ON u.rowid = l.fk_user";
$sql .= " LEFT JOIN " . MAIN_DB_PREFIX . "c_holiday_types as t ON t.rowid = l.fk_type";
$sql .= " WHERE (l.entity IS NULL OR l.entity IN (0, " . ((int) $conf->entity) . "))";

if (!empty($search_all)) {
	$sql .= natural_search(array('l.description', 'u.lastname', 'u.firstname', 'u.login'), $search_all);
}
if (!empty($search_employee) && $search_employee != -1) {
	$sql .= " AND l.fk_user = " . $db->escape($search_employee);
}
if (!empty($search_type) && $search_type != -1) {
	$sql .= " AND l.fk_type = " . $db->escape($search_type);
}
$sql .= dolSqlDateFilter("l.date_start", $search_day_start, $search_month_start, $search_year_start);
$sql .= dolSqlDateFilter("l.date_end", $search_day_end, $search_month_end, $search_year_end);

$nbtotalofrecords = 0;
if (!getDolGlobalInt('MAIN_DISABLE_FULL_SCANLIST')) {
	$sqlforcount = preg_replace('/SELECT .* FROM/i', 'SELECT COUNT(*) as nbtotalofrecords FROM', $sql);
	$resql = $db->query($sqlforcount);
	if ($resql) {
		$objforcount = $db->fetch_object($resql);
		$nbtotalofrecords = (int) $objforcount->nbtotalofrecords;
		if (($page * $limit) > $nbtotalofrecords) {
			$page = 0;
			$offset = 0;
		}
		$db->free($resql);
	}
}

$sql .= $db->order($sortfield, $sortorder);
if ($limit) {
	$sql .= $db->plimit($limit + 1, $offset);
}

$resql = $db->query($sql);
if (!$resql) {
	dol_print_error($db);
	exit;
}
$num = $db->num_rows($resql);
$arrayofselected = is_array($toselect) ? $toselect : array();

$param = '';
if (!empty($mode)) $param .= '&mode=' . urlencode($mode);
if (!empty($contextpage) && $contextpage != $_SERVER["PHP_SELF"]) $param .= '&contextpage=' . urlencode($contextpage);
if ($limit > 0 && $limit != $conf->liste_limit) $param .= '&limit=' . ((int) $limit);
if ($optioncss != '') $param .= '&optioncss=' . urlencode($optioncss);
if ($search_all) $param .= '&search_all=' . urlencode($search_all);
if ($search_employee) $param .= '&search_employee=' . urlencode($search_employee);
if ($search_type) $param .= '&search_type=' . urlencode($search_type);
if ($search_day_start) $param .= '&search_cxdate_dtstartday=' . urlencode($search_day_start);
if ($search_month_start) $param .= '&search_cxdate_dtstartmonth=' . urlencode($search_month_start);
if ($search_year_start) $param .= '&search_cxdate_dtstartyear=' . urlencode($search_year_start);
if ($search_day_end) $param .= '&search_cxdate_dtendday=' . urlencode($search_day_end);
if ($search_month_end) $param .= '&search_cxdate_dtendmonth=' . urlencode($search_month_end);
if ($search_year_end) $param .= '&search_cxdate_dtendyear=' . urlencode($search_year_end);

$newcardbutton = '';
if (!empty($user->rights->dolizsynch->shift->write)) {
	$newcardbutton = dolGetButtonTitle($langs->trans('MenuAddCP'), '', 'fa fa-plus-circle', dol_buildpath('/kreaflow/leaves/card.php', 1) . '?action=create', '', 1);
}

print '<form method="POST" id="searchFormList" action="' . $_SERVER["PHP_SELF"] . '">' . "\n";
if ($optioncss != '') {
	print '<input type="hidden" name="optioncss" value="' . $optioncss . '">';
}
print '<input type="hidden" name="token" value="' . newToken() . '">';
print '<input type="hidden" name="formfilteraction" id="formfilteraction" value="list">';
print '<input type="hidden" name="action" value="' . ($action == 'edit' ? 'update' : 'list') . '">';
print '<input type="hidden" name="contextpage" value="' . dol_escape_htmltag($contextpage) . '">';
print '<input type="hidden" name="sortfield" value="' . dol_escape_htmltag($sortfield) . '">';
print '<input type="hidden" name="sortorder" value="' . dol_escape_htmltag($sortorder) . '">';
print '<input type="hidden" name="page" value="' . $page . '">';
print '<input type="hidden" name="page_y" value="">';
print '<input type="hidden" name="mode" value="' . dol_escape_htmltag($mode) . '">';

print_barre_liste($title, $page, $_SERVER["PHP_SELF"], $param, $sortfield, $sortorder, $massactionbutton, $num, $nbtotalofrecords, 'title_hrm', 0, $newcardbutton, '', $limit, 0, 0, 1);

print '<div class="div-table-responsive">';
print '<table class="tagtable nobottomiftotal liste">';

// Filters
print '<tr class="liste_titre_filter">';
print '<td class="liste_titre">';
print '<input class="flat maxwidth50" type="text" name="search_all" placeholder="' . dol_escape_htmltag($langs->trans("Search")) . '" value="' . dol_escape_htmltag($search_all) . '">';
print '</td>';

print '<td class="liste_titre left">';
print $form->select_dolusers($search_employee, "search_employee", 1, null, 0, '', '', '0', 0, 0, '', 0, '', 'maxwidth125');
print '</td>';

print '<td class="liste_titre">';
$typeOptions = $object->getTypes(1);
print $form->selectarray('search_type', $typeOptions, $search_type, 1, 0, 0, '', 0, 0, 0, '', 'maxwidth100', 1);
print '</td>';

print '<td class="liste_titre center nowraponall">';
print $form->selectDate($search_start_ts ?: '', 'search_cxdate_dtstart', 0, 0, 1, '', 1, 0, 0, '', '', '', '', 1, '', $langs->trans("From"));
print '</td>';

print '<td class="liste_titre center nowraponall">';
print $form->selectDate($search_end_ts ?: '', 'search_cxdate_dtend', 0, 0, 1, '', 1, 0, 0, '', '', '', '', 1, '', $langs->trans("To"));
print '</td>';
print '<td class="liste_titre">&nbsp;</td>';
print '<td class="liste_titre">&nbsp;</td>';

print '<td class="liste_titre">&nbsp;</td>';
print '<td class="liste_titre">&nbsp;</td>';
print '<td class="liste_titre center">';
$searchpicto = $form->showFilterButtons();
print $searchpicto;
print '</td>';
print '</tr>' . "\n";

// Titles
print '<tr class="liste_titre">';
print_liste_field_titre($langs->trans("Ref"), $_SERVER["PHP_SELF"], "l.rowid", "", $param, '', $sortfield, $sortorder);
print_liste_field_titre($langs->trans("Employee"), $_SERVER["PHP_SELF"], "u.lastname", "", $param, '', $sortfield, $sortorder);
print_liste_field_titre($langs->trans("Type"), $_SERVER["PHP_SELF"], "t.label", "", $param, '', $sortfield, $sortorder);
print_liste_field_titre($langs->trans("DateStart"), $_SERVER["PHP_SELF"], "l.date_start", "", $param, '', $sortfield, $sortorder, 'center ');
print_liste_field_titre($langs->trans("DateEnd"), $_SERVER["PHP_SELF"], "l.date_end", "", $param, '', $sortfield, $sortorder, 'center ');
print_liste_field_titre($langs->trans("NbDays"), $_SERVER["PHP_SELF"], "", "", $param, '', $sortfield, $sortorder, 'right ');
print_liste_field_titre($langs->trans("WorkingDays"), $_SERVER["PHP_SELF"], "", "", $param, '', $sortfield, $sortorder, 'right ');
print_liste_field_titre($langs->trans("HalfDay"), $_SERVER["PHP_SELF"], "l.halfday", "", $param, '', $sortfield, $sortorder, 'center ');
print_liste_field_titre($langs->trans("Minutes"), $_SERVER["PHP_SELF"], "l.minutes_total", "", $param, '', $sortfield, $sortorder, 'right ');
print_liste_field_titre($langs->trans("Description"), $_SERVER["PHP_SELF"], "", "", $param, '', $sortfield, $sortorder);
print '</tr>' . "\n";

$i = 0;
$totalminutes = 0;
$totalDays = 0;
$totalWorkingDays = 0;
while ($i < $num) {
	$obj = $db->fetch_object($resql);
	if (!$obj) {
		break;
	}

	$leaveLink = dol_buildpath('/kreaflow/leaves/card.php', 1) . '?id=' . ((int) $obj->rowid);

	print '<tr class="oddeven">';
	print '<td class="nowrap"><a href="' . $leaveLink . '">LV' . (int) $obj->rowid . '</a></td>';

	print '<td class="tdoverflowmax125">';
	$usertmp = new User($db);
	if ($obj->fk_user && $usertmp->fetch($obj->fk_user) > 0) {
		print $usertmp->getNomUrl(-1, 'leave');
	} else {
		print dol_escape_htmltag($obj->user_lastname . ' ' . $obj->user_firstname);
	}
	print '</td>';

	$labelType = $obj->type_label ?: $obj->fk_type;
	print '<td class="tdoverflowmax100" title="' . dol_escape_htmltag($labelType) . '">' . dol_escape_htmltag($labelType) . '</td>';

	$startTs = $db->jdate($obj->date_start);
	$endTs = $db->jdate($obj->date_end);
	$rowDayChange = isset($obj->day_change_hour) && $obj->day_change_hour !== null ? (int) $obj->day_change_hour : $dayChangeHourDefault;
	$startRef = $startTs ? shiftplanner_operational_boundary_for_timestamp($startTs, $rowDayChange) : 0;
	$endRef = $endTs ? shiftplanner_operational_boundary_for_timestamp($endTs - 1, $rowDayChange) : 0; // end is exclusive

	print '<td class="center">' . ($startRef ? dol_print_date($startRef, 'day') : '') . '</td>';
	print '<td class="center">' . ($endRef ? dol_print_date($endRef, 'day') : '') . '</td>';
	$daysCount = 0.0;
	$workingDaysCount = 0.0;
	if ($startRef && $endRef) {
		$daysCount = floor(($endRef - $startRef) / 86400) + 1;
		$workingDaysCount = num_open_day($startRef, $endRef, 0, 1, $obj->halfday);
	}

	// Adjust for partial leaves: prefer explicit minutes_total when present, otherwise use half-day flag
	if ((int) $obj->halfday === 3 && !empty($obj->minutes_total)) {
		$partialFraction = min(1, $obj->minutes_total / $dayMinutesBase); // fraction of a day based on configured shift length
		if ($partialFraction > 0) {
			$daysCount = $partialFraction;
			$workingDaysCount = $partialFraction;
		}
	} elseif ((int) $obj->halfday === 2 && $startRef && $endRef) {
		$minutes = max(0, (int) round(($endRef - $startRef) / 60));
		$partialFraction = min(1, $minutes / $dayMinutesBase);
		if ($partialFraction > 0) {
			$daysCount = $partialFraction;
			$workingDaysCount = $partialFraction;
		}
	} elseif ((int) $obj->halfday === 1 && $daysCount > 0) {
		$daysCount = min($daysCount, 0.5);
		$workingDaysCount = min($workingDaysCount, 0.5);
	}

	$daysCount = round($daysCount, 2);
	$workingDaysCount = round($workingDaysCount, 2);

	print '<td class="right">' . price($daysCount, 2, '', 1) . '</td>';
	print '<td class="right">' . price($workingDaysCount, 2, '', 1) . '</td>';
	$partialLabel = '';
	if ((int) $obj->halfday === 0) $partialLabel = $langs->trans("FullDay");
	elseif ((int) $obj->halfday === 1) $partialLabel = $langs->trans("PartialDay");
	elseif ((int) $obj->halfday === 2) $partialLabel = $langs->trans("PeriodTime");
	elseif ((int) $obj->halfday === 3) $partialLabel = $langs->trans("DurationMinutes");
	print '<td class="center">' . dol_escape_htmltag($partialLabel) . '</td>';
	print '<td class="right">' . price($obj->minutes_total, 0, '', 1) . '</td>';
	print '<td>' . dol_escape_htmltag($obj->description) . '</td>';
	print '</tr>';

	$totalDays += $daysCount;
	$totalWorkingDays += $workingDaysCount;
	$totalminutes += (int) $obj->minutes_total;
	$i++;
}

if ($num == 0) {
	print '<tr><td colspan="8"><span class="opacitymedium">' . $langs->trans("NoRecordFound") . '</span></td></tr>';
} else {
	print '<tr class="total">';
	print '<td colspan="5" class="right">' . $langs->trans("Total") . '</td>';
	print '<td class="right">' . price(round($totalDays, 2), 2, '', 1) . '</td>';
	print '<td class="right">' . price(round($totalWorkingDays, 2), 2, '', 1) . '</td>';
	print '<td class="center">&nbsp;</td>';
	print '<td class="right">' . price($totalminutes, 0, '', 1) . '</td>';
	print '<td></td>';
	print '</tr>';
}

print '</table>';
print '</div>';
print '</form>';

if ($nbtotalofrecords > $limit || $page > 0) {
	print '<div class="center paginate">';
	print $form->showpages($_SERVER["PHP_SELF"], $page, $nbtotalofrecords, $limit, $param);
	print '</div>';
}

llxFooter();
$db->close();

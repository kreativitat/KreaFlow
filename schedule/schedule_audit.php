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

/* Schedule audit log list */

require '../../../main.inc.php';
require_once DOL_DOCUMENT_ROOT . '/core/class/html.form.class.php';
require_once DOL_DOCUMENT_ROOT . '/core/lib/date.lib.php';

global $db, $langs, $user, $conf, $hookmanager;

$langs->loadLangs(array('main', 'other', 'users', 'dolizsynch@kreaflow'));

if (empty($conf->dolizsynch->enabled)) {
	accessforbidden('Module KreaFlow is not enabled');
}
if (empty($user->rights->dolizsynch->shift->schedule_audit_view)) {
	accessforbidden('You do not have permission to view schedule audit logs');
}
if ($user->socid > 0) {
	accessforbidden();
}

$action = GETPOST('action', 'aZ09');
$contextpage = GETPOST('contextpage', 'aZ') ?: 'dolizsynchscheduleaudit';
$mode = GETPOST('mode', 'alpha');

$limit = GETPOSTINT('limit') ? GETPOSTINT('limit') : $conf->liste_limit;
$sortfield = GETPOST('sortfield', 'aZ09comma') ?: 'l.date_creation';
$sortorder = GETPOST('sortorder', 'aZ09comma') ?: 'DESC';
$page = GETPOSTISSET('pageplusone') ? (GETPOSTINT('pageplusone') - 1) : GETPOSTINT('page');
if (empty($page) || $page < 0) {
	$page = 0;
}
$offset = $limit * $page;

$search_all = trim(GETPOST('search_all', 'alphanohtml'));
$search_action = GETPOST('search_action', 'alphanohtml');
$search_object_type = GETPOST('search_object_type', 'alpha');
$search_actor = GETPOSTINT('search_actor');
$search_target = GETPOSTINT('search_target');
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

if (GETPOST('button_removefilter_x', 'alpha') || GETPOST('button_removefilter.x', 'alpha') || GETPOST('button_removefilter', 'alpha')) {
	$search_all = '';
	$search_action = '';
	$search_object_type = '';
	$search_actor = 0;
	$search_target = 0;
	$search_day_start = $search_month_start = $search_year_start = '';
	$search_day_end = $search_month_end = $search_year_end = '';
}

$search_actor_filter = $search_actor > 0 ? $search_actor : 0;
$search_target_filter = $search_target > 0 ? $search_target : 0;
$search_actor_select = $search_actor > 0 ? $search_actor : -1;
$search_target_select = $search_target > 0 ? $search_target : -1;

function dz_schedule_log_summary($contextRaw)
{
	if ($contextRaw === null || $contextRaw === '') {
		return '';
	}
	$data = json_decode($contextRaw, true);
	if (!is_array($data)) {
		return $contextRaw;
	}
	$parts = array();
	foreach (array('shift_id', 'leave_id', 'dayoff_id') as $key) {
		if (isset($data[$key]) && $data[$key] !== '') {
			$parts[] = $key . '=' . $data[$key];
		}
	}
	if (!empty($data['start'])) {
		$parts[] = 'start=' . $data['start'];
	}
	if (!empty($data['end'])) {
		$parts[] = 'end=' . $data['end'];
	}
	if (isset($data['type_id']) && $data['type_id'] !== '') {
		$parts[] = 'type=' . $data['type_id'];
	}
	if (!empty($data['label'])) {
		$parts[] = 'label=' . $data['label'];
	}
	if (!empty($data['changes']) && is_array($data['changes'])) {
		$parts[] = 'changes=' . implode(',', array_keys($data['changes']));
	}

	return !empty($parts) ? implode(' | ', $parts) : $contextRaw;
}

$actionOptions = array(
	'' => $langs->trans('All'),
	'shift_create' => 'shift_create',
	'shift_update' => 'shift_update',
	'shift_delete' => 'shift_delete',
	'shift_convert_to_leave' => 'shift_convert_to_leave',
	'leave_create' => 'leave_create',
	'leave_update' => 'leave_update',
	'leave_delete' => 'leave_delete',
	'leave_convert_to_shift' => 'leave_convert_to_shift',
	'dayoff_create' => 'dayoff_create',
	'dayoff_delete' => 'dayoff_delete',
	'week_copy' => 'week_copy',
);

$objectOptions = array(
	'' => $langs->trans('All'),
	'shift' => 'shift',
	'leave' => 'leave',
	'dayoff' => 'dayoff',
	'week' => 'week',
	'schedule' => 'schedule',
);

$form = new Form($db);

$search_start_ts = 0;
if (!empty($search_day_start) && !empty($search_month_start) && !empty($search_year_start)) {
	$search_start_ts = dol_mktime(0, 0, 0, $search_month_start, $search_day_start, $search_year_start);
}
$search_end_ts = 0;
if (!empty($search_day_end) && !empty($search_month_end) && !empty($search_year_end)) {
	$search_end_ts = dol_mktime(23, 59, 59, $search_month_end, $search_day_end, $search_year_end);
}

$title = $langs->trans('ScheduleAuditLog');

llxHeader('', $title, '', '', 0, 0, '', '', '', 'bodyforlist mod-holiday page-list');

$sql = "SELECT";
$sql .= " l.rowid,";
$sql .= " l.action,";
$sql .= " l.object_type,";
$sql .= " l.object_id,";
$sql .= " l.fk_user_actor,";
$sql .= " l.fk_user_target,";
$sql .= " l.context,";
$sql .= " l.date_creation,";
$sql .= " ua.login as actor_login, ua.firstname as actor_firstname, ua.lastname as actor_lastname,";
$sql .= " ut.login as target_login, ut.firstname as target_firstname, ut.lastname as target_lastname";
$sql .= " FROM " . MAIN_DB_PREFIX . "dolizsynch_schedule_log as l";
$sql .= " LEFT JOIN " . MAIN_DB_PREFIX . "user as ua ON ua.rowid = l.fk_user_actor";
$sql .= " LEFT JOIN " . MAIN_DB_PREFIX . "user as ut ON ut.rowid = l.fk_user_target";
$sql .= " WHERE l.entity = " . ((int) $conf->entity);

if (!empty($search_all)) {
	$sql .= natural_search(array('l.action', 'l.object_type', 'l.context', 'ua.login', 'ua.lastname', 'ua.firstname', 'ut.login', 'ut.lastname', 'ut.firstname'), $search_all);
}
if (!empty($search_action)) {
	$sql .= " AND l.action = '" . $db->escape($search_action) . "'";
}
if (!empty($search_object_type)) {
	$sql .= " AND l.object_type = '" . $db->escape($search_object_type) . "'";
}
if (!empty($search_actor_filter)) {
	$sql .= " AND l.fk_user_actor = " . (int) $search_actor_filter;
}
if (!empty($search_target_filter)) {
	$sql .= " AND l.fk_user_target = " . (int) $search_target_filter;
}
if (!empty($search_start_ts)) {
	$sql .= " AND l.date_creation >= '" . $db->idate($search_start_ts) . "'";
}
if (!empty($search_end_ts)) {
	$sql .= " AND l.date_creation <= '" . $db->idate($search_end_ts) . "'";
}

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
$sql .= $db->plimit($limit + 1, $offset);

$resql = $db->query($sql);
if (!$resql) {
	print $db->lasterror();
	$db->close();
	exit;
}

$num = $db->num_rows($resql);

$param = '';
if ($search_all !== '') $param .= '&search_all=' . urlencode($search_all);
if ($search_action !== '') $param .= '&search_action=' . urlencode($search_action);
if ($search_object_type !== '') $param .= '&search_object_type=' . urlencode($search_object_type);
if (!empty($search_actor_filter)) $param .= '&search_actor=' . urlencode($search_actor_filter);
if (!empty($search_target_filter)) $param .= '&search_target=' . urlencode($search_target_filter);
if (!empty($search_day_start)) $param .= '&search_cxdate_dtstartday=' . urlencode($search_day_start);
if (!empty($search_month_start)) $param .= '&search_cxdate_dtstartmonth=' . urlencode($search_month_start);
if (!empty($search_year_start)) $param .= '&search_cxdate_dtstartyear=' . urlencode($search_year_start);
if (!empty($search_day_end)) $param .= '&search_cxdate_dtendday=' . urlencode($search_day_end);
if (!empty($search_month_end)) $param .= '&search_cxdate_dtendmonth=' . urlencode($search_month_end);
if (!empty($search_year_end)) $param .= '&search_cxdate_dtendyear=' . urlencode($search_year_end);
if (!empty($limit)) $param .= '&limit=' . urlencode($limit);

print '<form method="GET" action="' . $_SERVER["PHP_SELF"] . '">';
print '<input type="hidden" name="action" value="' . dol_escape_htmltag($action) . '">';
print '<input type="hidden" name="contextpage" value="' . dol_escape_htmltag($contextpage) . '">';
print '<input type="hidden" name="sortfield" value="' . dol_escape_htmltag($sortfield) . '">';
print '<input type="hidden" name="sortorder" value="' . dol_escape_htmltag($sortorder) . '">';
print '<input type="hidden" name="page" value="' . $page . '">';
print '<input type="hidden" name="page_y" value="">';
print '<input type="hidden" name="mode" value="' . dol_escape_htmltag($mode) . '">';
print '<input type="hidden" name="limit" value="' . (int) $limit . '">';

print_barre_liste($title, $page, $_SERVER["PHP_SELF"], $param, $sortfield, $sortorder, '', $num, $nbtotalofrecords, 'title_hrm', 0, '', '', $limit, 0, 0, 1);

print '<div class="div-table-responsive">';
print '<table class="tagtable nobottomiftotal liste">';

print '<tr class="liste_titre_filter">';
print '<td class="liste_titre">';
print '<input class="flat maxwidth50" type="text" name="search_all" placeholder="' . dol_escape_htmltag($langs->trans("Search")) . '" value="' . dol_escape_htmltag($search_all) . '">';
print '</td>';
print '<td class="liste_titre center nowraponall">';
print '<div class="nowrap">';
print $form->selectDate($search_start_ts ?: '', 'search_cxdate_dtstart', 0, 0, 1, '', 1, 0, 0, '', '', '', '', 1, '', $langs->trans("From"));
print '</div>';
print '<div class="nowrap">';
print $form->selectDate($search_end_ts ?: '', 'search_cxdate_dtend', 0, 0, 1, '', 1, 0, 0, '', '', '', '', 1, '', $langs->trans("To"));
print '</div>';
print '</td>';
print '<td class="liste_titre">';
print $form->selectarray('search_action', $actionOptions, $search_action, 0, 0, 0, '', 0, 0, 0, '', 'maxwidth150');
print '</td>';
print '<td class="liste_titre">';
print $form->selectarray('search_object_type', $objectOptions, $search_object_type, 0, 0, 0, '', 0, 0, 0, '', 'maxwidth100');
print '</td>';
print '<td class="liste_titre">';
print $form->select_dolusers($search_actor_select, "search_actor", 1, null, 0, '', '', '0', 0, 0, '', 0, '', 'maxwidth125');
print '</td>';
print '<td class="liste_titre">';
print $form->select_dolusers($search_target_select, "search_target", 1, null, 0, '', '', '0', 0, 0, '', 0, '', 'maxwidth125');
print '</td>';
print '<td class="liste_titre center">';
print $form->showFilterButtons();
print '</td>';
print '</tr>' . "\n";

print '<tr class="liste_titre">';
print_liste_field_titre($langs->trans("Ref"), $_SERVER["PHP_SELF"], "l.rowid", "", $param, '', $sortfield, $sortorder);
print_liste_field_titre($langs->trans("Date"), $_SERVER["PHP_SELF"], "l.date_creation", "", $param, '', $sortfield, $sortorder, 'center ');
print_liste_field_titre($langs->trans("Action"), $_SERVER["PHP_SELF"], "l.action", "", $param, '', $sortfield, $sortorder);
print_liste_field_titre($langs->trans("Type"), $_SERVER["PHP_SELF"], "l.object_type", "", $param, '', $sortfield, $sortorder);
print_liste_field_titre($langs->trans("User"), $_SERVER["PHP_SELF"], "ua.login", "", $param, '', $sortfield, $sortorder);
print_liste_field_titre($langs->trans("Employee"), $_SERVER["PHP_SELF"], "ut.login", "", $param, '', $sortfield, $sortorder);
print_liste_field_titre($langs->trans("Description"), $_SERVER["PHP_SELF"], "", "", $param, '', $sortfield, $sortorder);
print '</tr>' . "\n";

$i = 0;
while ($i < min($num, $limit)) {
	$obj = $db->fetch_object($resql);
	if (!$obj) {
		break;
	}

	$actorName = trim($obj->actor_firstname . ' ' . $obj->actor_lastname);
	if ($actorName === '') {
		$actorName = $obj->actor_login ?: ('User ' . (int) $obj->fk_user_actor);
	}
	$targetName = trim($obj->target_firstname . ' ' . $obj->target_lastname);
	if ($targetName === '') {
		$targetName = $obj->target_login ?: ($obj->fk_user_target ? ('User ' . (int) $obj->fk_user_target) : '');
	}

	$contextSummary = dz_schedule_log_summary($obj->context);
	$contextSummary = trim((string) $contextSummary);
	$contextDisplay = $contextSummary !== '' ? dol_trunc($contextSummary, 120) : '';
	$contextTitle = $contextSummary !== '' ? $contextSummary : '';

	$objectLabel = $obj->object_type;
	if (!empty($obj->object_id)) {
		$objectLabel .= ' #' . $obj->object_id;
	}

	print '<tr class="oddeven">';
	print '<td class="nowrap">LOG' . (int) $obj->rowid . '</td>';
	print '<td class="center">' . dol_print_date($db->jdate($obj->date_creation), 'dayhour') . '</td>';
	print '<td class="nowrap">' . dol_escape_htmltag($obj->action) . '</td>';
	print '<td class="nowrap">' . dol_escape_htmltag($objectLabel) . '</td>';
	print '<td class="tdoverflowmax150">' . dol_escape_htmltag($actorName) . '</td>';
	print '<td class="tdoverflowmax150">' . dol_escape_htmltag($targetName) . '</td>';
	print '<td class="tdoverflowmax300" title="' . dol_escape_htmltag($contextTitle) . '">' . dol_escape_htmltag($contextDisplay) . '</td>';
	print '</tr>';

	$i++;
}

if ($num == 0) {
	print '<tr><td colspan="7"><span class="opacitymedium">' . $langs->trans("NoRecordFound") . '</span></td></tr>';
}

print '</table>';
print '</div>';
print '</form>';

$db->free($resql);

llxFooter();
$db->close();

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

// Simplified leave card for DoliZSynch (uses llx_dolizsynch_leave)

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
require_once DOL_DOCUMENT_ROOT . '/core/class/html.formfile.class.php';
require_once DOL_DOCUMENT_ROOT . '/core/class/link.class.php';
require_once DOL_DOCUMENT_ROOT . '/core/lib/date.lib.php';
require_once DOL_DOCUMENT_ROOT . '/user/class/user.class.php';
require_once DOL_DOCUMENT_ROOT . '/core/lib/files.lib.php';
require_once DOL_DOCUMENT_ROOT . '/core/lib/images.lib.php';
require_once __DIR__ . '/../class/zsleaves.class.php';

global $db, $langs, $user, $conf;

$langs->loadLangs(array('main', 'holiday', 'other'));

if (empty($conf->dolizsynch->enabled)) accessforbidden();
if (empty($user->rights->dolizsynch->shift->read)) accessforbidden();

$getDateWithTime = function ($prefix, $fallbackTs = 0) {
	$year = GETPOSTINT($prefix . 'year');
	$month = GETPOSTINT($prefix . 'month');
	$day = GETPOSTINT($prefix . 'day');
	$hour = GETPOSTINT($prefix . 'hour');
	$min = GETPOSTINT($prefix . 'min');

	if ($year && $month && $day) {
		$ts = dol_mktime($hour, $min, 0, $month, $day, $year);
		if ($ts > 0) return $ts;
	}

	$alt = GETPOSTDATE($prefix);
	return $alt ?: $fallbackTs;
};

$action = GETPOST('action', 'aZ09');
$confirm = GETPOST('confirm', 'alpha');
$id = GETPOSTINT('id');

$object = new ZSLeaves($db);
$form = new Form($db);
$formfile = new FormFile($db);

$partialMode = GETPOSTINT('halfday');
$dayChangeHour = (int) getDolGlobalString('ZS_PUNCH_DAY_CHANGE_HOUR', 0);

$currentEntity = (int) $conf->entity;

// Allowed employees = any user linked to a group in the current entity
$includeEmployees = $object->getEntityEmployees($currentEntity, 1);

// Preload object data when an id is provided (needed for file paths and permissions)
if ($id > 0) {
	$object->fetch($id);
}

// -----------------------------------------------------------------------------
// Files setup (DOCUMENTS / LINKS) - must be ready before including actions
// -----------------------------------------------------------------------------
$modulepart = 'dolizsynch'; // external module name for docs
$entityforfiles = !empty($object->entity) ? (int) $object->entity : (int) $conf->entity;

if (empty($conf->dolizsynch->multidir_output)) {
	$conf->dolizsynch->multidir_output = array();
}
if (empty($conf->dolizsynch->dir_output)) {
	$conf->dolizsynch->dir_output = DOL_DATA_ROOT . (($entityforfiles > 1) ? '/' . $entityforfiles : '') . '/dolizsynch';
}
if (empty($conf->dolizsynch->multidir_output[$entityforfiles])) {
	$conf->dolizsynch->multidir_output[$entityforfiles] = $conf->dolizsynch->dir_output;
}
$upload_dir_base = $conf->dolizsynch->multidir_output[$entityforfiles];

// Subdirectory for this object: leaves/{id}
$subdir = $object->id ? 'leaves/' . dol_sanitizeFileName($object->id) : '';
$upload_dir = ($object->id && $upload_dir_base) ? $upload_dir_base . '/' . $subdir : '';
$relativepathwithnofile = $object->id ? $subdir . '/' : '';

$canEdit = !empty($user->rights->dolizsynch->shift->write);

$targetWeekIso = $object->date_start
	? dol_print_date($object->date_start, '%Y-%m-%d')
	: dol_print_date(dol_now('tzuser'), '%Y-%m-%d');
$scheduleLinkUrl = dol_buildpath('/kreaflow/schedule/schedule_employees.php', 1) . '?week=' . urlencode($targetWeekIso);

function buildTimestampFromPost($prefix, $hour = 0, $min = 0)
{
	$y = GETPOSTINT($prefix . 'year');
	$m = GETPOSTINT($prefix . 'month');
	$d = GETPOSTINT($prefix . 'day');
	if ($y && $m && $d) {
		return dol_mktime($hour, $min, 0, $m, $d, $y);
	}
	return 0;
}

function injectHolidayIconLink($url)
{
	if (empty($url)) return;
	print '<script>
document.addEventListener("DOMContentLoaded", function() {
	const selector = ".infobox-holiday .fa-umbrella-beach, .fa-umbrella-beach.infobox-holiday, .infobox-holiday.fas.fa-umbrella-beach";
	const icon = document.querySelector(selector);
	if (!icon) return;
	let link = icon.closest("a");
	if (!link) {
		link = document.createElement("a");
		icon.parentNode.insertBefore(link, icon);
		link.appendChild(icon);
	}
	link.href = ' . json_encode($url) . ';
	link.target = "_blank";
	if (!link.rel || link.rel.indexOf("noopener") === -1) {
		link.rel = (link.rel ? link.rel + " " : "") + "noopener";
	}
});
</script>';
}

// -----------------------------------------------------------------------------
// Actions
// -----------------------------------------------------------------------------

if ($action === 'add' && !empty($user->rights->dolizsynch->shift->write)) {
	$object->fk_user = GETPOSTINT('fk_user');
	$object->fk_type = GETPOSTINT('fk_type');
	$object->halfday = in_array($partialMode, array(1, 2, 3), true) ? $partialMode : 0;
	$object->description = trim(GETPOST('description', 'restricthtml'));

	if ($partialMode === 1) { // half day (0.5 day)
		$object->date_start = $getDateWithTime('date_start');
		$object->date_end = $getDateWithTime('date_end');
		$object->minutes_total = 0;
	} elseif ($partialMode === 2) { // period with times
		$object->date_start = buildTimestampFromPost('date_start', GETPOSTINT('date_starthour'), GETPOSTINT('date_startmin'));
		$object->date_end = buildTimestampFromPost('date_end', GETPOSTINT('date_endhour'), GETPOSTINT('date_endmin'));
		$object->minutes_total = 0;
	} elseif ($partialMode === 3) { // minutes only
		$object->date_start = buildTimestampFromPost('date_start', $dayChangeHour, 0);
		$minutesVal = GETPOSTINT('minutes_total');
		$minutesVal = max(0, $minutesVal);
		$object->minutes_total = $minutesVal;
		$object->date_end = $object->date_start ? ($object->date_start + ($minutesVal * 60)) : 0;
	} else { // full day
		$object->date_start = $getDateWithTime('date_start');
		$object->date_end = $getDateWithTime('date_end');
		$object->minutes_total = 0;
	}

	if ($object->date_end && $object->date_start && $object->date_end < $object->date_start) {
		setEventMessages($langs->trans("ErrorEndDateBeforeStartDate"), null, 'errors');
		$action = 'create';
	} elseif (
		in_array($partialMode, array(1, 2, 3), true)
		&& $object->date_end && $object->date_start
		&& dol_print_date($object->date_start, '%Y-%m-%d') !== dol_print_date($object->date_end, '%Y-%m-%d')
	) {
		setEventMessages($langs->trans("ErrorPartialLeaveSameDay"), null, 'errors');
		$action = 'create';
	} else {
		$res = $object->create($user);
		if ($res > 0) {
			header('Location: ' . dol_buildpath('/kreaflow/leaves/card.php', 1) . '?id=' . $object->id);
			exit;
		}
		setEventMessages($object->error ? $object->error : $langs->trans("Error"), $object->errors, 'errors');
		$action = 'create';
	}
} elseif ($action === 'update' && !empty($user->rights->dolizsynch->shift->write)) {
	if ($object->fetch($id) > 0) {
		$object->fk_user = GETPOSTINT('fk_user');
		$object->fk_type = GETPOSTINT('fk_type');
		$object->halfday = in_array($partialMode, array(1, 2, 3), true) ? $partialMode : 0;
		$object->description = trim(GETPOST('description', 'restricthtml'));

		if ($partialMode === 1) {
			$object->date_start = $getDateWithTime('date_start', $object->date_start);
			$object->date_end = $getDateWithTime('date_end', $object->date_end);
			$object->minutes_total = 0;
		} elseif ($partialMode === 2) {
			$object->date_start = buildTimestampFromPost('date_start', GETPOSTINT('date_starthour'), GETPOSTINT('date_startmin'));
			$object->date_end = buildTimestampFromPost('date_end', GETPOSTINT('date_endhour'), GETPOSTINT('date_endmin'));
			$object->minutes_total = 0;
		} elseif ($partialMode === 3) {
			$object->date_start = buildTimestampFromPost('date_start', $dayChangeHour, 0);
			$minutesVal = max(0, GETPOSTINT('minutes_total'));
			$object->minutes_total = $minutesVal;
			$object->date_end = $object->date_start ? ($object->date_start + ($minutesVal * 60)) : 0;
		} else {
			$object->date_start = $getDateWithTime('date_start', $object->date_start);
			$object->date_end = $getDateWithTime('date_end', $object->date_end);
			$object->minutes_total = 0;
		}

		if ($object->date_end && $object->date_start && $object->date_end < $object->date_start) {
			setEventMessages($langs->trans("ErrorEndDateBeforeStartDate"), null, 'errors');
			$action = 'edit';
		} elseif (
			in_array($partialMode, array(1, 2, 3), true)
			&& $object->date_end && $object->date_start
			&& dol_print_date($object->date_start, '%Y-%m-%d') !== dol_print_date($object->date_end, '%Y-%m-%d')
		) {
			setEventMessages($langs->trans("ErrorPartialLeaveSameDay"), null, 'errors');
			$action = 'edit';
		} else {
			$res = $object->update($user);
			if ($res > 0) {
				setEventMessages($langs->trans("RecordSaved"), null, 'mesgs');
				$action = '';
			} else {
				setEventMessages($object->error ? $object->error : $langs->trans("Error"), $object->errors, 'errors');
				$action = 'edit';
			}
		}
	}
} elseif ($action === 'confirm_delete' && $confirm === 'yes' && !empty($user->rights->dolizsynch->shift->write)) {
	if ($object->fetch($id) > 0) {
		$object->delete($user);
		header('Location: ' . dol_buildpath('/kreaflow/leaves/list.php', 1));
		exit;
	}
}

$partialModeCurrent = ($object->halfday >= 0 && $object->halfday <= 3) ? (int) $object->halfday : 0;
if ($partialModeCurrent === 0 && $object->minutes_total > 0) {
	$partialModeCurrent = 3;
}

// Document actions (upload/delete/link) - only when we have an object id
if (!empty($object->id) && $upload_dir) {
	dol_mkdir($upload_dir);
	// Set all permission variables that actions_linkedfiles.inc.php needs
	$permissiontoadd = $canEdit;
	$permissiontodelete = $canEdit;
	$permission = $canEdit;
	$permtoedit = $canEdit;
	// This include uses $modulepart, $object, $upload_dir, $relativepathwithnofile, etc.
	include DOL_DOCUMENT_ROOT . '/core/actions_linkedfiles.inc.php';
}

// -----------------------------------------------------------------------------
// View / Edit / Create
// -----------------------------------------------------------------------------

$typeOptions = $object->getTypes(1);

$displayref = $object->id ? (!empty($object->ref) ? $object->ref : 'LV' . (int) $object->id) : '';
$title = $object->id ? $langs->trans("ShiftPlannerLeavetitle") . ' ' . dol_escape_htmltag($displayref) : $langs->trans("New") . ' ' . $langs->trans("ShiftPlannerLeavetitle");
llxHeader('', $title);

$linkback = '<a href="' . dol_buildpath('/kreaflow/leaves/list.php', 1) . '">' . $langs->trans("BackToList") . '</a>';

print load_fiche_titre($title, $linkback, 'holiday');

// CREATE
if ($action === 'create' || (!$id && $canEdit)) {
	print '<form method="POST" action="' . $_SERVER["PHP_SELF"] . '">';
	print '<input type="hidden" name="token" value="' . newToken() . '">';
	print '<input type="hidden" name="action" value="add">';

	print dol_get_fiche_head();

	print '<table class="border centpercent tableforfieldcreate">';
	print '<tr><td class="titlefieldcreate fieldrequired">' . $langs->trans("Employee") . '</td><td>';
	print $form->select_dolusers($object->fk_user ?: $user->id, 'fk_user', 0, '', 0, $includeEmployees, '', 0, 0, 0, '', 0, '', '', 1);
	print '</td></tr>';
	print '<tr><td class="titlefieldcreate fieldrequired">' . $langs->trans("Type") . '</td><td>';
	print $form->selectarray('fk_type', $typeOptions, $object->fk_type, 0, 0, 0, '', 0, 0, 0, '', '', 1);
	print '</td></tr>';

	print '<tr><td class="titlefieldcreate">' . $langs->trans("PartialDay") . '</td><td>';
	$halfOpts = array(
		0 => $langs->trans("FullDay"),
		1 => $langs->trans("HalfDay"),
		2 => $langs->trans("PeriodTime"),
		3 => $langs->trans("DurationMinutes"),
	);
	$partialSelectVal = GETPOSTISSET('halfday') ? $partialMode : $partialModeCurrent;
	print '<input type="hidden" name="halfday" id="halfday_select" value="' . (int)$partialSelectVal . '">';
	foreach ($halfOpts as $key => $label) {
		$checked = ($key == $partialSelectVal) ? ' checked' : '';
		print '<label style="margin-right: 15px; cursor: pointer;">';
		print '<input type="radio" name="halfday_radio" value="' . (int)$key . '" class="halfday_radio"' . $checked . ' style="margin-right: 5px;">';
		print dol_escape_htmltag($label);
		print '</label>';
	}
	print '</td></tr>';

	print '<tr id="row_fullday_period"><td class="titlefieldcreate">' . $langs->trans("DateStartEnd") . '</td><td>';
	print $form->selectDate($object->date_start ?: dol_now(), 'date_start', 0, 0, 0, 'formstart', 1);
	print ' &nbsp; ' . $form->selectDate($object->date_end ?: dol_now(), 'date_end', 0, 0, 0, 'formend', 1);
	print '</td></tr>';

	print '<tr id="row_period_date" style="display:none;"><td class="titlefieldcreate">' . $langs->trans("Date") . '</td><td>';
	print $form->selectDate($object->date_start ?: dol_now(), 'date_start', 0, 0, 0, 'formstart', 1);
	print '</td></tr>';

	print '<tr id="row_period_times" style="display:none;"><td class="titlefieldcreate">' . $langs->trans("PeriodTime") . '</td><td>';
	$startHour = dol_print_date($object->date_start ?: dol_now(), '%H');
	$startMin = dol_print_date($object->date_start ?: dol_now(), '%M');
	$endHour = dol_print_date($object->date_end ?: dol_now(), '%H');
	$endMin = dol_print_date($object->date_end ?: dol_now(), '%M');

	print '<span class="opacitymedium">' . $langs->trans("Start") . ':</span> ';
	print '<select class="flat valignmiddle maxwidth50" id="date_starthour" name="date_starthour">';
	for ($i = 0; $i < 24; $i++) {
		$val = sprintf('%02d', $i);
		$selected = ($val == $startHour) ? ' selected' : '';
		print '<option value="' . $val . '"' . $selected . '>' . $val . '</option>';
	}
	print '</select>:<select class="flat valignmiddle maxwidth50" id="date_startmin" name="date_startmin">';
	for ($i = 0; $i < 60; $i++) {
		$val = sprintf('%02d', $i);
		$selected = ($val == $startMin) ? ' selected' : '';
		print '<option value="' . $val . '"' . $selected . '>' . $val . '</option>';
	}
	print '</select>';

	print ' &nbsp; <span class="opacitymedium">' . $langs->trans("End") . ':</span> ';
	print '<select class="flat valignmiddle maxwidth50" id="date_endhour" name="date_endhour">';
	for ($i = 0; $i < 24; $i++) {
		$val = sprintf('%02d', $i);
		$selected = ($val == $endHour) ? ' selected' : '';
		print '<option value="' . $val . '"' . $selected . '>' . $val . '</option>';
	}
	print '</select>:<select class="flat valignmiddle maxwidth50" id="date_endmin" name="date_endmin">';
	for ($i = 0; $i < 60; $i++) {
		$val = sprintf('%02d', $i);
		$selected = ($val == $endMin) ? ' selected' : '';
		print '<option value="' . $val . '"' . $selected . '>' . $val . '</option>';
	}
	print '</select>';
	print '</td></tr>';

	print '<tr id="row_minutes_date" style="display:none;"><td class="titlefieldcreate">' . $langs->trans("Date") . '</td><td>';
	print $form->selectDate($object->date_start ?: dol_now(), 'date_start', 0, 0, 0, 'formstart', 1);
	print '</td></tr>';

	print '<tr id="row_minutes"><td class="titlefieldcreate">' . $langs->trans("DurationMinutes") . '</td><td>';
	print '<div style="display: inline-flex; align-items: center; gap: 3px;">';
	print '<button type="button" id="minutes_decrease" class="button buttongen" style="padding: 2px 6px; font-size: 12px; min-width: auto;">−</button>';
	print '<input type="number" name="minutes_total" id="minutes_total" value="' . (int) $object->minutes_total . '" class="maxwidth100" min="0" step="15" style="text-align: center;">';
	print '<button type="button" id="minutes_increase" class="button buttongen" style="padding: 2px 6px; font-size: 12px; min-width: auto;">+</button>';
	print '<span class="opacitymedium" style="margin-left: 5px;">' . $langs->trans("minutes") . '</span>';
	print '</div>';
	print '</td></tr>';

	print '<tr><td class="titlefieldcreate">' . $langs->trans("Description") . '</td><td>';
	print '<textarea name="description" class="quatrevingtpercent" rows="3">' . dol_escape_htmltag($object->description) . '</textarea>';
	print '</td></tr>';
	print '</table>';

	print dol_get_fiche_end();

	print '<div class="center">';
	print '<input type="submit" class="button button-add" value="' . $langs->trans("Create") . '">';
	print '&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;';
	print '<input type="button" class="button button-cancel" value="' . $langs->trans("Cancel") . '" onclick="window.location.href=\'' . dol_buildpath('/kreaflow/leaves/list.php', 1) . '\';">';
	print '</div>';
	print '</form>';
}
// EDIT
elseif ($action === 'edit' && $id && $object->id && $canEdit) {
	$head = array();
	$head[] = array('#', $langs->trans("Card"), 'card');
	dol_fiche_head($head, 'card', $langs->trans("ShiftPlannerLeavetitle"), -1, 'holiday');
	injectHolidayIconLink($scheduleLinkUrl);
	injectHolidayIconLink($scheduleLinkUrl);

	print '<form method="POST" action="' . $_SERVER["PHP_SELF"] . '">';
	print '<input type="hidden" name="token" value="' . newToken() . '">';
	print '<input type="hidden" name="action" value="update">';
	print '<input type="hidden" name="id" value="' . (int) $object->id . '">';

	print '<table class="border centpercent tableforfield">';
	// ... (Same Edit Form fields) ...
	print '<tr><td class="titlefield fieldrequired">' . $langs->trans("Employee") . '</td><td>';
	print $form->select_dolusers($object->fk_user, 'fk_user', 0, '', 0, $includeEmployees);
	print '</td></tr>';

	print '<tr><td class="titlefield fieldrequired">' . $langs->trans("Type") . '</td><td>';
	print $form->selectarray('fk_type', $typeOptions, $object->fk_type, 0, 0, 0, '', 0, 0, 0, '', '', 1);
	print '</td></tr>';

	print '<tr><td class="titlefield">' . $langs->trans("PartialDay") . '</td><td>';
	$halfOpts = array(
		0 => $langs->trans("FullDay"),
		1 => $langs->trans("HalfDay"),
		2 => $langs->trans("PeriodTime"),
		3 => $langs->trans("DurationMinutes"),
	);
	$partialSelectVal = GETPOSTISSET('halfday') ? $partialMode : $partialModeCurrent;
	print '<input type="hidden" name="halfday" id="halfday_select" value="' . (int)$partialSelectVal . '">';
	foreach ($halfOpts as $key => $label) {
		$checked = ($key == $partialSelectVal) ? ' checked' : '';
		print '<label style="margin-right: 15px; cursor: pointer;">';
		print '<input type="radio" name="halfday_radio" value="' . (int)$key . '" class="halfday_radio"' . $checked . ' style="margin-right: 5px;">';
		print dol_escape_htmltag($label);
		print '</label>';
	}
	print '</td></tr>';

	print '<tr id="row_fullday_period"><td class="titlefield">' . $langs->trans("DateStartEnd") . '</td><td>';
	print $form->selectDate($object->date_start, 'date_start', 0, 0, 0, '', 1);
	print ' &nbsp; ' . $form->selectDate($object->date_end, 'date_end', 0, 0, 0, '', 1);
	print '</td></tr>';

	print '<tr id="row_period_date" style="display:none;"><td class="titlefield">' . $langs->trans("Date") . '</td><td>';
	print $form->selectDate($object->date_start, 'date_start', 0, 0, 0, '', 1);
	print '</td></tr>';

	print '<tr id="row_period_times" style="display:none;"><td class="titlefield">' . $langs->trans("PeriodTime") . '</td><td>';
	$startHour = dol_print_date($object->date_start, '%H');
	$startMin = dol_print_date($object->date_start, '%M');
	$endHour = dol_print_date($object->date_end, '%H');
	$endMin = dol_print_date($object->date_end, '%M');

	print '<span class="opacitymedium">' . $langs->trans("Start") . ':</span> ';
	print '<select class="flat valignmiddle maxwidth50" id="date_starthour" name="date_starthour">';
	for ($i = 0; $i < 24; $i++) {
		$val = sprintf('%02d', $i);
		$selected = ($val == $startHour) ? ' selected' : '';
		print '<option value="' . $val . '"' . $selected . '>' . $val . '</option>';
	}
	print '</select>:<select class="flat valignmiddle maxwidth50" id="date_startmin" name="date_startmin">';
	for ($i = 0; $i < 60; $i++) {
		$val = sprintf('%02d', $i);
		$selected = ($val == $startMin) ? ' selected' : '';
		print '<option value="' . $val . '"' . $selected . '>' . $val . '</option>';
	}
	print '</select>';

	print ' &nbsp; <span class="opacitymedium">' . $langs->trans("End") . ':</span> ';
	print '<select class="flat valignmiddle maxwidth50" id="date_endhour" name="date_endhour">';
	for ($i = 0; $i < 24; $i++) {
		$val = sprintf('%02d', $i);
		$selected = ($val == $endHour) ? ' selected' : '';
		print '<option value="' . $val . '"' . $selected . '>' . $val . '</option>';
	}
	print '</select>:<select class="flat valignmiddle maxwidth50" id="date_endmin" name="date_endmin">';
	for ($i = 0; $i < 60; $i++) {
		$val = sprintf('%02d', $i);
		$selected = ($val == $endMin) ? ' selected' : '';
		print '<option value="' . $val . '"' . $selected . '>' . $val . '</option>';
	}
	print '</select>';
	print '</td></tr>';

	print '<tr id="row_minutes_date" style="display:none;"><td class="titlefield">' . $langs->trans("Date") . '</td><td>';
	print $form->selectDate($object->date_start, 'date_start', 0, 0, 0, '', 1);
	print '</td></tr>';

	print '<tr id="row_minutes"><td class="titlefield">' . $langs->trans("DurationMinutes") . '</td><td>';
	print '<div style="display: inline-flex; align-items: center; gap: 3px;">';
	print '<button type="button" id="minutes_decrease" class="button buttongen" style="padding: 2px 6px; font-size: 12px; min-width: auto;">−</button>';
	print '<input type="number" name="minutes_total" id="minutes_total" value="' . (int) $object->minutes_total . '" class="maxwidth100" min="0" step="15" style="text-align: center;">';
	print '<button type="button" id="minutes_increase" class="button buttongen" style="padding: 2px 6px; font-size: 12px; min-width: auto;">+</button>';
	print '<span class="opacitymedium" style="margin-left: 5px;">' . $langs->trans("minutes") . '</span>';
	print '</div>';
	print '</td></tr>';

	print '<tr><td class="titlefield">' . $langs->trans("Description") . '</td><td>';
	print '<textarea name="description" class="quatrevingtpercent" rows="3">' . dol_escape_htmltag($object->description) . '</textarea>';
	print '</td></tr>';
	print '</table>';

	print '<div class="tabsAction">';
	print '<input type="submit" class="butAction" value="' . $langs->trans("Save") . '">';
	print ' <a class="butActionRefused" href="' . dol_buildpath('/kreaflow/leaves/card.php', 1) . '?id=' . (int) $object->id . '">' . $langs->trans("Cancel") . '</a>';
	print '</div>';
	print '</form>';

	dol_fiche_end();

	// === DOCUMENTS/LINKS (EDIT) ===
	if (!empty($upload_dir) && !empty($object->id)) {
		print "\n\n";
		print '<!-- DEBUG: Documents section (EDIT mode) -->' . "\n";
		print '<!-- modulepart=' . htmlspecialchars($modulepart) . ', upload_dir=' . htmlspecialchars($upload_dir) . ' -->' . "\n";
		print '<!-- relativepathwithnofile=' . htmlspecialchars($relativepathwithnofile) . ' -->' . "\n";

		// Ensure directory exists
		dol_mkdir($upload_dir);

		// Show existing files while editing
		$sortfield = GETPOST('sortfield', 'aZ09comma');
		$sortorder = GETPOST('sortorder', 'aZ09comma');
		if (empty($sortfield)) $sortfield = 'name';
		if (empty($sortorder)) $sortorder = 'ASC';

		$filearray = dol_dir_list($upload_dir, 'files', 0, '', '(\.meta|_preview.*\.png)$', $sortfield, (strtolower($sortorder) == 'desc' ? SORT_DESC : SORT_ASC), 1);
		print '<!-- Found ' . (is_array($filearray) ? count($filearray) : 0) . ' files -->' . "\n";

		// Set all permission variables that the template expects
		$permissiontoadd = $canEdit;
		$permissiontodelete = $canEdit;
		$permission = $canEdit;
		$permtoedit = $canEdit;
		$param = '&id=' . (int) $object->id . '&action=edit';

		// Include the template - it handles all rendering
		include DOL_DOCUMENT_ROOT . '/core/tpl/document_actions_post_headers.tpl.php';
	}
}
// VIEW
elseif ($id) {
	$head = array();
	$head[] = array('#', $langs->trans("Card"), 'card');
	dol_fiche_head($head, 'card', $langs->trans("ShiftPlannerLeavetitle"), -1, 'holiday');

	print '<table class="border centpercent tableforfield">';
	$refLabel = $object->ref ? $object->ref : ('LV' . (int) $object->id);
	$refLink = '<a href="' . dol_escape_htmltag($scheduleLinkUrl) . '" class="infobox-holiday-link" target="_blank" rel="noopener"><span class="fas fa-umbrella-beach em088 infobox-holiday" aria-hidden="true"></span> ' . dol_escape_htmltag($refLabel) . '</a>';
	print '<tr><td class="titlefield">' . $langs->trans("Ref") . '</td><td>' . $refLink . '</td></tr>';
	print '<tr><td class="titlefield">' . $langs->trans("Employee") . '</td><td>';
	$usertmp = new User($db);
	if ($usertmp->fetch($object->fk_user) > 0) {
		print $usertmp->getNomUrl(-1);
	} else {
		print (int) $object->fk_user;
	}
	print '</td></tr>';
	print '<tr><td class="titlefield">' . $langs->trans("Type") . '</td><td>' . dol_escape_htmltag(!empty($typeOptions[$object->fk_type]['label']) ? $typeOptions[$object->fk_type]['label'] : $object->fk_type) . '</td></tr>';

	$minutesDisplay = (int) $object->minutes_total;
	$mode = ($object->halfday !== null) ? (int) $object->halfday : 0;
	if ($mode === 0 && $minutesDisplay > 0) {
		$mode = 3;
	}

	if ($mode === 0) {
		// Full day: show only dates. Adjust end date by day change hour (if set) and treat end as inclusive.
		$endDisplayTs = $object->date_end;
		$dayChangeSeconds = max(0, (int) $dayChangeHour) * 3600;
		if ($endDisplayTs) {
			// Treat as inclusive end-of-day and shift by configured change hour
			$endDisplayTs -= 1;
			if ($dayChangeSeconds) {
				$endDisplayTs -= $dayChangeSeconds;
			}
		}
		print '<tr><td class="titlefield">' . $langs->trans("DateStart") . '</td><td>' . dol_print_date($object->date_start, 'day') . '</td></tr>';
		print '<tr><td class="titlefield">' . $langs->trans("DateEnd") . '</td><td>' . dol_print_date($endDisplayTs, 'day') . '</td></tr>';
		print '<tr><td class="titlefield">' . $langs->trans("PartialDay") . '</td><td>' . $langs->trans("FullDay") . '</td></tr>';
	} elseif ($mode === 1) {
		print '<tr><td class="titlefield">' . $langs->trans("Date") . '</td><td>' . dol_print_date($object->date_start, 'day') . '</td></tr>';
		print '<tr><td class="titlefield">' . $langs->trans("PartialDay") . '</td><td>' . $langs->trans("HalfDay") . '</td></tr>';
		print '<tr><td class="titlefield">' . $langs->trans("DateEnd") . '</td><td>' . dol_print_date($object->date_end, 'day') . '</td></tr>';
	} elseif ($mode === 2) {
		print '<tr><td class="titlefield">' . $langs->trans("Date") . '</td><td>' . dol_print_date($object->date_start, 'day') . '</td></tr>';
		print '<tr><td class="titlefield">' . $langs->trans("PartialDay") . '</td><td>' . $langs->trans("PeriodTime") . '</td></tr>';
		print '<tr><td class="titlefield">' . $langs->trans("PeriodTime") . '</td><td>' . dol_print_date($object->date_start, 'hour') . ' - ' . dol_print_date($object->date_end, 'hour') . '</td></tr>';
	} elseif ($mode === 3) {
		print '<tr><td class="titlefield">' . $langs->trans("Date") . '</td><td>' . dol_print_date($object->date_start, 'day') . '</td></tr>';
		print '<tr><td class="titlefield">' . $langs->trans("PartialDay") . '</td><td>' . $langs->trans("DurationMinutes") . '</td></tr>';
		print '<tr><td class="titlefield">' . $langs->trans("DurationMinutes") . '</td><td>' . $minutesDisplay . '</td></tr>';
	}

	print '<tr><td class="titlefield">' . $langs->trans("Description") . '</td><td>' . nl2br(dol_escape_htmltag($object->description)) . '</td></tr>';
	print '</table>';

	print '<div class="tabsAction">';
	if ($canEdit) {
		print '<a class="butAction" href="' . $_SERVER["PHP_SELF"] . '?action=edit&id=' . $object->id . '">' . $langs->trans("Modify") . '</a>';
		print '<a class="butActionDelete" href="' . $_SERVER["PHP_SELF"] . '?action=delete&id=' . $object->id . '">' . $langs->trans("Delete") . '</a>';
	}
	print ' <a class="butActionRefused" href="' . dol_buildpath('/kreaflow/leaves/list.php', 1) . '">' . $langs->trans("Cancel") . '</a>';
	print '</div>';

	dol_fiche_end();

	// === DOCUMENTS/LINKS (VIEW) ===
	if (!empty($upload_dir) && !empty($object->id)) {
		print "\n\n";
		print '<!-- DEBUG: Documents section (VIEW mode) -->' . "\n";
		print '<!-- modulepart=' . htmlspecialchars($modulepart) . ', upload_dir=' . htmlspecialchars($upload_dir) . ' -->' . "\n";
		print '<!-- relativepathwithnofile=' . htmlspecialchars($relativepathwithnofile) . ' -->' . "\n";

		// Ensure directory exists
		dol_mkdir($upload_dir);

		// 1. Get the list of files
		$sortfield = GETPOST('sortfield', 'aZ09comma');
		$sortorder = GETPOST('sortorder', 'aZ09comma');
		if (empty($sortfield)) $sortfield = 'name';
		if (empty($sortorder)) $sortorder = 'ASC';

		$filearray = dol_dir_list($upload_dir, 'files', 0, '', '(\.meta|_preview.*\.png)$', $sortfield, (strtolower($sortorder) == 'desc' ? SORT_DESC : SORT_ASC), 1);
		print '<!-- Found ' . (is_array($filearray) ? count($filearray) : 0) . ' files -->' . "\n";

		// 2. Set all permission variables that the template expects
		$permissiontoadd = $canEdit;
		$permissiontodelete = $canEdit;
		$permission = $canEdit;
		$permtoedit = $canEdit;
		$param = '&id=' . $object->id;

		// 3. Include the template - it handles all rendering
		include DOL_DOCUMENT_ROOT . '/core/tpl/document_actions_post_headers.tpl.php';
	}

	// *** FIX: Removed duplicate/broken code block that was here ***
}

// Delete confirmation popup
if ($action === 'delete' && $id && $canEdit) {
	$formconfirm = $form->formconfirm($_SERVER["PHP_SELF"] . '?id=' . $id, $langs->trans("DeleteLeave"), $langs->trans("ConfirmDeleteLeave"), 'confirm_delete', array(), 0, 1);
	print $formconfirm;
}

// ... (Rest of JS Code is fine) ...
if (($action === 'create' || $action === 'edit') && $canEdit) {
	print '<script type="text/javascript">
    jQuery(document).ready(function($) {
        function togglePartial() {
            var halfSelect = document.getElementById("halfday_select");
            var mode = halfSelect ? parseInt(halfSelect.value, 10) : 0;

            var fullDayPeriodRow = document.getElementById("row_fullday_period");
            var periodDateRow = document.getElementById("row_period_date");
            var periodTimesRow = document.getElementById("row_period_times");
            var minutesDateRow = document.getElementById("row_minutes_date");
            var minutesRow = document.getElementById("row_minutes");
            var minutesInput = document.getElementById("minutes_total");

            if (mode === 0) {
                if (fullDayPeriodRow) fullDayPeriodRow.style.display = "";
                if (periodDateRow) periodDateRow.style.display = "none";
                if (periodTimesRow) periodTimesRow.style.display = "none";
                if (minutesDateRow) minutesDateRow.style.display = "none";
                if (minutesRow) minutesRow.style.display = "none";
                if (minutesInput) minutesInput.value = "0";
            } else if (mode === 1) {
                if (fullDayPeriodRow) fullDayPeriodRow.style.display = "";
                if (periodDateRow) periodDateRow.style.display = "none";
                if (periodTimesRow) periodTimesRow.style.display = "none";
                if (minutesDateRow) minutesDateRow.style.display = "none";
                if (minutesRow) minutesRow.style.display = "none";
                if (minutesInput) minutesInput.value = "0";
            } else if (mode === 2) {
                if (fullDayPeriodRow) fullDayPeriodRow.style.display = "none";
                if (periodDateRow) periodDateRow.style.display = "";
                if (periodTimesRow) periodTimesRow.style.display = "";
                if (minutesDateRow) minutesDateRow.style.display = "none";
                if (minutesRow) minutesRow.style.display = "none";
                if (minutesInput) minutesInput.value = "0";
            } else if (mode === 3) {
                if (fullDayPeriodRow) fullDayPeriodRow.style.display = "none";
                if (periodDateRow) periodDateRow.style.display = "none";
                if (periodTimesRow) periodTimesRow.style.display = "none";
                if (minutesDateRow) minutesDateRow.style.display = "";
                if (minutesRow) minutesRow.style.display = "";
            }

            if (mode === 2) {
                syncEndDateToStart();
            }
        }

        function syncEndDateToStart() {
            var halfSelect = document.getElementById("halfday_select");
            if (!halfSelect) return;
            var mode = parseInt(halfSelect.value, 10);

            if (mode === 2) {
                var startDay = document.querySelector(\'select[name="date_startday"]\');
                var startMonth = document.querySelector(\'select[name="date_startmonth"]\');
                var startYear = document.querySelector(\'select[name="date_startyear"]\');
                var endDay = document.querySelector(\'select[name="date_endday"]\');
                var endMonth = document.querySelector(\'select[name="date_endmonth"]\');
                var endYear = document.querySelector(\'select[name="date_endyear"]\');

                if (startDay && endDay && startMonth && endMonth && startYear && endYear) {
                    endDay.value = startDay.value;
                    endMonth.value = startMonth.value;
                    endYear.value = startYear.value;
                }
            }
        }

        $(".halfday_radio").on("change", function() {
            var newValue = $(this).val();
            $("#halfday_select").val(newValue);
            togglePartial();
        });

        [\'select[name="date_startday"]\', \'select[name="date_startmonth"]\', \'select[name="date_startyear"]\'].forEach(function(selector) {
            $(selector).on("change", syncEndDateToStart);
        });

        $("#minutes_increase").on("click", function() {
            var input = $("#minutes_total");
            var currentVal = parseInt(input.val()) || 0;
            var step = parseInt(input.attr("step")) || 15;
            input.val(currentVal + step);
        });

        $("#minutes_decrease").on("click", function() {
            var input = $("#minutes_total");
            var currentVal = parseInt(input.val()) || 0;
            var step = parseInt(input.attr("step")) || 15;
            var min = parseInt(input.attr("min")) || 0;
            var newVal = Math.max(min, currentVal - step);
            input.val(newVal);
        });

        setTimeout(function() {
            togglePartial();
        }, 100);
    });
    </script>';
}

llxFooter();
$db->close();

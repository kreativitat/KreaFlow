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

global $langs, $user, $conf, $hookmanager;

// Libraries
require_once DOL_DOCUMENT_ROOT . "/core/lib/admin.lib.php";
require_once DOL_DOCUMENT_ROOT . "/custom/kreaflow/lib/dolizsynch.lib.php";
require_once DOL_DOCUMENT_ROOT . "/custom/kreaflow/lib/shiftplanner.lib.php";

// Translations
$langs->loadLangs(array("admin", "dolizsynch@kreaflow"));

// Hooks
$hookmanager->initHooks(array('dolizsynchsetup', 'globalsetup'));

// Access control
if (!$user->admin) {
	accessforbidden();
}

// Parameters
$action = GETPOST('action', 'aZ09');
$backtopage = GETPOST('backtopage', 'alpha');

$error = 0;
$setupnotempty = 0;
$employeeWeekNotesKey = 'DOLIZSYNCH_SHIFT_EMPLOYEE_WEEK_NOTES_ENABLED';
$notesDeleteAction = ($action === ('del_' . $employeeWeekNotesKey));

// Keep explicit off state visible in setup output even when Dolibarr removed the row.
if (!isset($conf->global->{$employeeWeekNotesKey})) {
	$conf->global->{$employeeWeekNotesKey} = '0';
}

/**
 * Normalize setup toggle values to 0/1.
 *
 * @param mixed $raw Raw value from request.
 * @param int $default Default normalized value.
 * @return int
 */
function shiftplannerNormalizeToggleValue($raw, $default = 1)
{
	if ($raw === null || $raw === '') {
		return ((int) $default > 0) ? 1 : 0;
	}
	if (is_bool($raw)) {
		return $raw ? 1 : 0;
	}
	$value = trim(strtolower((string) $raw));
	if (in_array($value, array('1', 'true', 'on', 'yes'), true)) {
		return 1;
	}
	if (in_array($value, array('0', '-1', 'false', 'off', 'no'), true)) {
		return 0;
	}
	return ((int) $raw > 0) ? 1 : 0;
}

// Fail-safe: persist weekly-notes toggle for both classic POST submit and ajax-style set_* actions.
$notesTogglePosted = function_exists('GETPOSTISSET') ? GETPOSTISSET($employeeWeekNotesKey) : (isset($_REQUEST[$employeeWeekNotesKey]));
$constNameParam = GETPOST('constname', 'alpha');
$actionSetKey = '';
if (!empty($action) && strpos($action, 'set_') === 0) {
	$actionSetKey = substr($action, 4);
}
$isNotesSetAction = ($actionSetKey === $employeeWeekNotesKey) || ($constNameParam === $employeeWeekNotesKey);
$notesSetValuePosted = function_exists('GETPOSTISSET') ? GETPOSTISSET('value') : isset($_REQUEST['value']);
if ($notesTogglePosted || ($isNotesSetAction && $notesSetValuePosted)) {
	$rawToggle = $notesTogglePosted ? GETPOST($employeeWeekNotesKey, 'alpha') : GETPOST('value', 'alpha');
	$normalizedToggle = shiftplannerNormalizeToggleValue($rawToggle, 1);
	dolibarr_set_const($db, $employeeWeekNotesKey, $normalizedToggle, 'chaine', 0, '', $conf->entity);
}

$useFormSetup = 1;
if (!class_exists('FormSetup')) {
	if (floatval(DOL_VERSION) < 16.0 && !class_exists('FormSetup')) {
		require_once __DIR__ . '/../backport/v16/core/class/html.formsetup.class.php';
	} else {
		require_once DOL_DOCUMENT_ROOT . '/core/class/html.formsetup.class.php';
	}
}

$formSetup = new FormSetup($db);

$formSetup->htmlBeforeOutputForm .= '
<link rel="stylesheet" href="' . dol_buildpath('/kreaflow/vendor/bootstrap4/bootstrap.min.css', 1) . '">
<link rel="stylesheet" href="' . dol_buildpath('/kreaflow/vendor/tempusdominus-bootstrap-4/tempusdominus-bootstrap-4.min.css', 1) . '">
<script src="' . dol_buildpath('/kreaflow/vendor/moment/moment.min.js', 1) . '"></script>
<script src="' . dol_buildpath('/kreaflow/vendor/bootstrap4/bootstrap.bundle.min.js', 1) . '"></script>
<script src="' . dol_buildpath('/kreaflow/vendor/tempusdominus-bootstrap-4/tempusdominus-bootstrap-4.min.js', 1) . '"></script>
<style>
.dz-leave-map-wrap { display:flex; flex-direction:column; gap:8px; }
.dz-leave-map-list { display:flex; flex-direction:column; gap:6px; }
.dz-leave-map-actions { margin-top:6px; }
.dz-leave-pill { display:flex; align-items:flex-start; gap:10px; padding:6px 8px; border:1px solid #ddd; border-radius:4px; background:#fafafa; flex-wrap:wrap; }
.dz-leave-pill-color { width:28px; height:18px; border:1px solid #ccc; border-radius:2px; display:inline-block; }
.dz-leave-pill-label { font-weight:600; word-break:break-word; white-space:normal; }
.dz-leave-pill-width { font-family:monospace; color:#666; }
.dz-leave-pill-manual { font-size:11px; color:#a33; font-weight:600; }
.dz-leave-pill-expr { font-family:monospace; color:#555; word-break:break-word; white-space:normal; flex:1 1 240px; }
.dz-leave-pill-actions { margin-left:auto; display:flex; gap:6px; align-self:flex-start; }
.dz-leave-map-list-view .dz-leave-pill { background:#fff; }
.dz-leave-icon-btn { border:1px solid #d0d0d0; background:#f7f7f7; color:#444; padding:6px 8px; border-radius:4px; cursor:pointer; min-width:32px; }
.dz-leave-icon-btn:hover { background:#ececec; color:#222; }
.dz-leave-icon-btn.delete { color:#a00; }
.dz-leave-icon-btn.delete:hover { background:#f4e2e2; }
.dz-leave-pill-actions .dz-leave-icon-btn { display:flex; align-items:center; justify-content:center; }
</style>
';

/*
 * Picagens de Ponto
 */
$formSetup->newItem('ZS_PUNCH_CLOCK')->setAsTitle();

$item = $formSetup->newItem('ZS_PUNCH_CLOCK_ACTIVATE');
$item->setAsYesNo();
$item->fieldAttr['placeholder'] = $langs->trans('ZS_PUNCH_CLOCK_ACTIVATE_PLACEHOLDER');

$itemKey = 'ZS_PUNCH_CLOCK_NUMBER_OF_DAYS';
$item = $formSetup->newItem($itemKey);
$item->fieldAttr['type'] = 'number';
$item->fieldAttr['min'] = '0';
$item->fieldAttr['max'] = '7';
$item->fieldAttr['placeholder'] = $langs->trans('ZS_PUNCH_CLOCK_NUMBER_OF_DAYS_PLACEHOLDER');
$item->fieldAttr['step'] = '1';
$item->defaultFieldValue = '1';

$itemKey = 'ZS_PUNCH_DAY_CHANGE_HOUR';
$item = $formSetup->newItem($itemKey);
$item->fieldAttr['type'] = 'number';
$item->fieldAttr['min'] = '0';
$item->fieldAttr['max'] = '12';
$item->fieldAttr['placeholder'] = $langs->trans('ZS_PUNCH_DAY_CHANGE_HOUR_PLACEHOLDER');
$item->fieldAttr['step'] = '1';
$item->defaultFieldValue = '6';

/*
 * Shift Planner window
 */
$formSetup->newItem('SHIFT_PLANNER_SETTINGS_TITLE')->setAsTitle();

$shiftDayStartValue = getDolGlobalString('DOLIZSYNCH_SHIFT_DAY_START', '07:00');
$shiftDayEndValue = getDolGlobalString('DOLIZSYNCH_SHIFT_DAY_END', '00:00');

$item = $formSetup->newItem('DOLIZSYNCH_SHIFT_DAY_START');
$item->nameText = $langs->trans('DOLIZSYNCH_SHIFT_DAY_START_DAY_END');
$item->helpText = $langs->trans('ShiftPlannerDayWindowHelp');
$minuteStep = shiftplanner_validate_minute_step($conf->global->DOLIZSYNCH_SHIFT_MINUTE_STEP ?? 30);
$item->fieldInputOverride = '
	<div class="shift-day-window-inputs" style="display:flex;gap:20px;flex-wrap:wrap;align-items:center;">
		<div style="display:flex;align-items:center;gap:8px;">
			<label style="margin:0;font-weight:600;">' . dol_escape_htmltag($langs->trans('DOLIZSYNCH_SHIFT_DAY_START')) . ':</label>
			<div class="input-group input-group-sm date" id="shift-day-start-picker" data-target-input="nearest" style="width:110px;">
				<input type="text" name="DOLIZSYNCH_SHIFT_DAY_START" value="' . dol_escape_htmltag($shiftDayStartValue ?: '07:00') . '" class="form-control form-control-sm datetimepicker-input" data-target="#shift-day-start-picker" data-toggle="datetimepicker" style="cursor:pointer;" />
				<div class="input-group-append" data-target="#shift-day-start-picker" data-toggle="datetimepicker" style="cursor:pointer;">
					<div class="input-group-text p-1">🕒</div>
				</div>
			</div>
		</div>
		<div style="display:flex;align-items:center;gap:8px;">
			<label style="margin:0;font-weight:600;">' . dol_escape_htmltag($langs->trans('DOLIZSYNCH_SHIFT_DAY_END')) . ':</label>
			<div class="input-group input-group-sm date" id="shift-day-end-picker" data-target-input="nearest" style="width:110px;">
				<input type="text" name="DOLIZSYNCH_SHIFT_DAY_END" value="' . dol_escape_htmltag($shiftDayEndValue ?: '00:00') . '" class="form-control form-control-sm datetimepicker-input" data-target="#shift-day-end-picker" data-toggle="datetimepicker" style="cursor:pointer;" />
				<div class="input-group-append" data-target="#shift-day-end-picker" data-toggle="datetimepicker" style="cursor:pointer;">
					<div class="input-group-text p-1">🕒</div>
				</div>
			</div>
		</div>
	</div>
';
$item->fieldOutputOverride = dol_escape_htmltag($shiftDayStartValue ?: '07:00') . ' &rarr; ' . dol_escape_htmltag($shiftDayEndValue ?: '00:00');

$hiddenEndItem = $formSetup->newItem('DOLIZSYNCH_SHIFT_DAY_END');
$hiddenEndItem->enabled = 0;
$hiddenEndItem->defaultFieldValue = '00:00';

$item = $formSetup->newItem('DOLIZSYNCH_SHIFT_MINUTE_STEP');
$item->fieldAttr['type'] = 'number';
$item->fieldAttr['min'] = '1';
$item->fieldAttr['max'] = '60';
$item->fieldAttr['step'] = '1';
$item->defaultFieldValue = '30';
$item->helpText = $langs->trans('ShiftPlannerMinuteStepHelp');

$item = $formSetup->newItem('DOLIZSYNCH_SHIFT_MAX_HOURS');
$item->fieldAttr['type'] = 'number';
$item->fieldAttr['min'] = '0.5';
$item->fieldAttr['max'] = '24';
$item->fieldAttr['step'] = '0.5';
$item->defaultFieldValue = '8';
$item->helpText = $langs->trans('ShiftPlannerMaxShiftHoursHelp');

$item = $formSetup->newItem('DOLIZSYNCH_SHIFT_USE_EXTRAFIELD_HOURS');
$item->setAsYesNo();
$item->defaultFieldValue = '0';
$item->helpText = $langs->trans('ShiftPlannerUseExtrafieldHoursHelp');

// Vacation defaults (for downstream pages)
$defaultVacationDays = 0;
$carryoverCapDays = 0;

// Shift badge visibility
$formSetup->newItem('DOLIZSYNCH_SHIFT_BADGE_TITLE')->setAsTitle();

$item = $formSetup->newItem('DOLIZSYNCH_SHIFT_SHOW_BADGE_NORMAL');
$item->setAsYesNo();
$item->defaultFieldValue = '1';
$item->desc = $langs->trans('Show normal (N) badge on shifts');

$item = $formSetup->newItem('DOLIZSYNCH_SHIFT_SHOW_BADGE_SPECIAL');
$item->setAsYesNo();
$item->defaultFieldValue = '1';
$item->desc = $langs->trans('Show special (E) badge on shifts');

$item = $formSetup->newItem('DOLIZSYNCH_SHIFT_CAP_NORMAL_TO_CONTRACT');
$item->setAsYesNo();
$item->defaultFieldValue = '0';
$item->desc = $langs->trans('Limit normal (N) hours summary to contracted hours');

// Per-employee weekly notes visibility
$formSetup->newItem('DOLIZSYNCH_SHIFT_NOTES_TITLE')->setAsTitle();

$item = $formSetup->newItem($employeeWeekNotesKey);
$item->setAsYesNo();
$item->defaultFieldValue = '1';
$item->helpText = $langs->trans('DOLIZSYNCH_SHIFT_EMPLOYEE_WEEK_NOTES_ENABLED_HELP');

// Persist badge visibility if form posted (manual safeguard)
if (in_array($action, array('edit', 'update'), true) && $_SERVER['REQUEST_METHOD'] === 'POST') {
	$badgeKeys = array(
		'DOLIZSYNCH_SHIFT_SHOW_BADGE_NORMAL',
		'DOLIZSYNCH_SHIFT_SHOW_BADGE_SPECIAL',
		$employeeWeekNotesKey,
	);
	foreach ($badgeKeys as $key) {
		$isPosted = function_exists('GETPOSTISSET') ? GETPOSTISSET($key) : (isset($_POST[$key]) || isset($_GET[$key]));
		if (!$isPosted) {
			continue;
		}
		$val = (int) GETPOST($key, 'int');
		dolibarr_set_const($db, $key, $val, 'chaine', 0, '', $conf->entity);
	}
}

$sql = "SELECT rowid, nom, entity FROM " . MAIN_DB_PREFIX . "usergroup WHERE entity IN (0, " . ((int) $conf->entity) . ") ORDER BY nom ASC";
$resql = $db->query($sql);
$TGroups = array('0' => $langs->trans('ShiftPlannerEmployeeGroupNotConfigured'));
if ($resql) {
	while ($obj = $db->fetch_object($resql)) {
		$label = $obj->nom;
		if (!empty($obj->entity)) {
			$label .= ' (E' . (int) $obj->entity . ')';
		}
		$TGroups[$obj->rowid] = $label;
	}
} else {
	dol_print_error($db);
}
$selectedGroupIds = array();
$rawGroupList = getDolGlobalString('DOLIZSYNCH_SHIFT_EMPLOYEE_GROUPS', '');
if ($rawGroupList !== '') {
	$selectedGroupIds = array_filter(array_map('intval', preg_split('/\s*,\s*/', $rawGroupList)), function ($id) {
		return $id > 0;
	});
}
if (empty($selectedGroupIds)) {
	$fallbackGroup = (int) getDolGlobalInt('DOLIZSYNCH_SHIFT_EMPLOYEE_GROUP', 0);
	if ($fallbackGroup > 0) {
		$selectedGroupIds = array($fallbackGroup);
	}
}
$item = $formSetup->newItem('DOLIZSYNCH_SHIFT_EMPLOYEE_GROUPS');
$item->nameText = $langs->trans('ShiftPlannerEmployeeGroupLabel');
$item->desc = $langs->trans('ShiftPlannerEmployeeGroupHelp');
$selectOptions = '';
foreach ($TGroups as $groupId => $groupLabel) {
	if ((int) $groupId <= 0) {
		continue;
	}
	$selected = in_array((int) $groupId, $selectedGroupIds, true) ? ' selected' : '';
	$selectOptions .= '<option value="' . (int) $groupId . '"' . $selected . '>' . dol_escape_htmltag($groupLabel) . '</option>';
}
$item->fieldInputOverride = '
	<div class="divsearchfield">
		<input type="hidden" name="DOLIZSYNCH_SHIFT_EMPLOYEE_GROUPS_multiselect" value="1">
		<input type="hidden" name="DOLIZSYNCH_SHIFT_EMPLOYEE_GROUPS_CSV" id="DOLIZSYNCH_SHIFT_EMPLOYEE_GROUPS_CSV" value="">
		<select id="DOLIZSYNCH_SHIFT_EMPLOYEE_GROUPS" class="multiselect multiselectononeline minwidth300" multiple="multiple" name="DOLIZSYNCH_SHIFT_EMPLOYEE_GROUPS[]">
			' . $selectOptions . '
		</select>
	</div>
	' . ajax_combobox('DOLIZSYNCH_SHIFT_EMPLOYEE_GROUPS', array(), 0, 0, 'resolve', '-1', 'minwidth300') . '
	<script>
	(function() {
		var selectEl = document.getElementById("DOLIZSYNCH_SHIFT_EMPLOYEE_GROUPS");
		var hiddenEl = document.getElementById("DOLIZSYNCH_SHIFT_EMPLOYEE_GROUPS_CSV");
		if (!selectEl || !hiddenEl) return;
		function syncGroups() {
			var values = [];
			for (var i = 0; i < selectEl.options.length; i++) {
				var opt = selectEl.options[i];
				if (opt.selected) values.push(opt.value);
			}
			hiddenEl.value = values.join(",");
		}
		selectEl.addEventListener("change", syncGroups);
		var formEl = selectEl.closest("form");
		if (formEl) {
			formEl.addEventListener("submit", syncGroups);
		} else {
			document.addEventListener("submit", syncGroups, true);
		}
		syncGroups();
	})();
	</script>
';

if (!empty($_POST['DOLIZSYNCH_SHIFT_EMPLOYEE_GROUPS_CSV'])) {
	$_POST['DOLIZSYNCH_SHIFT_EMPLOYEE_GROUPS'] = $_POST['DOLIZSYNCH_SHIFT_EMPLOYEE_GROUPS_CSV'];
} elseif (!empty($_POST['DOLIZSYNCH_SHIFT_EMPLOYEE_GROUPS']) && is_array($_POST['DOLIZSYNCH_SHIFT_EMPLOYEE_GROUPS'])) {
	$_POST['DOLIZSYNCH_SHIFT_EMPLOYEE_GROUPS'] = implode(',', array_map('intval', $_POST['DOLIZSYNCH_SHIFT_EMPLOYEE_GROUPS']));
}
$selectedLabels = array();
foreach ($selectedGroupIds as $groupId) {
	if (isset($TGroups[$groupId])) {
		$selectedLabels[] = $TGroups[$groupId];
	}
}
$item->fieldOutputOverride = !empty($selectedLabels)
	? dol_escape_htmltag(implode(', ', $selectedLabels))
	: dol_escape_htmltag($langs->trans('ShiftPlannerEmployeeGroupNotConfigured'));

if (in_array($action, array('edit', 'update'), true) && $_SERVER['REQUEST_METHOD'] === 'POST') {
	$rawGroups = GETPOST('DOLIZSYNCH_SHIFT_EMPLOYEE_GROUPS', 'array');
	if (!is_array($rawGroups) || empty($rawGroups)) {
		$rawCsv = GETPOST('DOLIZSYNCH_SHIFT_EMPLOYEE_GROUPS', 'alpha');
		if ($rawCsv !== '') {
			$rawGroups = preg_split('/\s*,\s*/', $rawCsv);
		} else {
			$rawGroups = array();
		}
	}
	$groups = array();
	if (is_array($rawGroups)) {
		foreach ($rawGroups as $gid) {
			$gid = (int) $gid;
			if ($gid > 0) {
				$groups[$gid] = $gid;
			}
		}
	}
	$groupsCsv = implode(',', array_values($groups));
	dolibarr_set_const($db, 'DOLIZSYNCH_SHIFT_EMPLOYEE_GROUPS', $groupsCsv, 'chaine', 0, '', $conf->entity);
	$primaryGroup = !empty($groups) ? (int) array_values($groups)[0] : 0;
	dolibarr_set_const($db, 'DOLIZSYNCH_SHIFT_EMPLOYEE_GROUP', $primaryGroup, 'chaine', 0, '', $conf->entity);
}

// Leave colors per type (entity-aware)
$formSetup->newItem('DOLIZSYNCH_LEAVE_COLORS_TITLE')->setAsTitle();

// Consolidated color configuration
$dayOffBg = getDolGlobalString('DOLIZSYNCH_DAYOFF_COLOR', '#bfbfbf');
$dayOffFont = getDolGlobalString('DOLIZSYNCH_DAYOFF_FONT_COLOR', '#000000');
$item = $formSetup->newItem('DOLIZSYNCH_DAYOFF_COLOR');
$item->fieldAttr['type'] = 'color';
$item->defaultFieldValue = $dayOffBg;
$item->desc = $langs->trans('ShiftPlannerDayOffColorHelp');
$item->fieldInputOverride = '
	<div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
		<div style="display:flex;align-items:center;gap:6px;">
			<label style="margin:0;font-weight:600;">' . dol_escape_htmltag($langs->trans('Background')) . '</label>
			<input type="color" name="DOLIZSYNCH_DAYOFF_COLOR" value="' . dol_escape_htmltag($dayOffBg) . '" style="width:60px;height:32px;cursor:pointer;">
			<input type="text" value="' . dol_escape_htmltag($dayOffBg) . '" readonly style="width:90px;text-transform:uppercase;">
		</div>
		<div style="display:flex;align-items:center;gap:6px;">
			<label style="margin:0;font-weight:600;">' . dol_escape_htmltag($langs->trans('FontColor')) . '</label>
			<input type="color" name="DOLIZSYNCH_DAYOFF_FONT_COLOR" value="' . dol_escape_htmltag($dayOffFont) . '" style="width:60px;height:32px;cursor:pointer;">
			<input type="text" value="' . dol_escape_htmltag($dayOffFont) . '" readonly style="width:90px;text-transform:uppercase;">
		</div>
		<div style="padding:6px 10px;border-radius:4px;border:1px solid #ddd;background:' . dol_escape_htmltag($dayOffBg) . ';color:' . dol_escape_htmltag($dayOffFont) . ';">
			Text
		</div>
	</div>
';
$item->fieldOutputOverride = '
	<div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
		<div style="display:flex;align-items:center;gap:6px;">
			<span style="display:inline-block;width:24px;height:24px;border:1px solid #ccc;background:' . dol_escape_htmltag($dayOffBg) . ';"></span>
			<span style="font-family:monospace;">' . dol_escape_htmltag(strtoupper($dayOffBg)) . '</span>
		</div>
		<div style="display:flex;align-items:center;gap:6px;">
			<span style="display:inline-block;width:24px;height:24px;border:1px solid #ccc;background:' . dol_escape_htmltag($dayOffFont) . ';"></span>
			<span style="font-family:monospace;">' . dol_escape_htmltag(strtoupper($dayOffFont)) . '</span>
		</div>
		<div style="padding:6px 10px;border-radius:4px;border:1px solid #ddd;background:' . dol_escape_htmltag($dayOffBg) . ';color:' . dol_escape_htmltag($dayOffFont) . ';">
			Text
		</div>
	</div>
';

$leaveBg = getDolGlobalString('DOLIZSYNCH_LEAVE_COLOR', '#cfcfcf');
$leaveFont = getDolGlobalString('DOLIZSYNCH_LEAVE_FONT_COLOR', '#000000');
$item = $formSetup->newItem('DOLIZSYNCH_LEAVE_COLOR');
$item->fieldAttr['type'] = 'color';
$item->defaultFieldValue = $leaveBg;
$item->desc = $langs->trans('ShiftPlannerLeaveColorHelp');
$item->fieldInputOverride = '
	<div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
		<div style="display:flex;align-items:center;gap:6px;">
			<label style="margin:0;font-weight:600;">' . dol_escape_htmltag($langs->trans('Background')) . '</label>
			<input type="color" name="DOLIZSYNCH_LEAVE_COLOR" value="' . dol_escape_htmltag($leaveBg) . '" style="width:60px;height:32px;cursor:pointer;">
			<input type="text" value="' . dol_escape_htmltag($leaveBg) . '" readonly style="width:90px;text-transform:uppercase;">
		</div>
		<div style="display:flex;align-items:center;gap:6px;">
			<label style="margin:0;font-weight:600;">' . dol_escape_htmltag($langs->trans('FontColor')) . '</label>
			<input type="color" name="DOLIZSYNCH_LEAVE_FONT_COLOR" value="' . dol_escape_htmltag($leaveFont) . '" style="width:60px;height:32px;cursor:pointer;">
			<input type="text" value="' . dol_escape_htmltag($leaveFont) . '" readonly style="width:90px;text-transform:uppercase;">
		</div>
		<div style="padding:6px 10px;border-radius:4px;border:1px solid #ddd;background:' . dol_escape_htmltag($leaveBg) . ';color:' . dol_escape_htmltag($leaveFont) . ';">
			Text
		</div>
	</div>
';
$item->fieldOutputOverride = '
	<div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
		<div style="display:flex;align-items:center;gap:6px;">
			<span style="display:inline-block;width:24px;height:24px;border:1px solid #ccc;background:' . dol_escape_htmltag($leaveBg) . ';"></span>
			<span style="font-family:monospace;">' . dol_escape_htmltag(strtoupper($leaveBg)) . '</span>
		</div>
		<div style="display:flex;align-items:center;gap:6px;">
			<span style="display:inline-block;width:24px;height:24px;border:1px solid #ccc;background:' . dol_escape_htmltag($leaveFont) . ';"></span>
			<span style="font-family:monospace;">' . dol_escape_htmltag(strtoupper($leaveFont)) . '</span>
		</div>
		<div style="padding:6px 10px;border-radius:4px;border:1px solid #ddd;background:' . dol_escape_htmltag($leaveBg) . ';color:' . dol_escape_htmltag($leaveFont) . ';">
			Text
		</div>
	</div>
';

$employmentBg = getDolGlobalString('DOLIZSYNCH_EMPLOYMENT_COLOR', '#d9d9d9');
$employmentFont = getDolGlobalString('DOLIZSYNCH_EMPLOYMENT_FONT_COLOR', '#000000');
$item = $formSetup->newItem('DOLIZSYNCH_EMPLOYMENT_COLOR');
$item->fieldAttr['type'] = 'color';
$item->defaultFieldValue = $employmentBg;
$item->desc = $langs->trans('ShiftPlannerEmploymentColorHelp');
$item->fieldInputOverride = '
	<div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
		<div style="display:flex;align-items:center;gap:6px;">
			<label style="margin:0;font-weight:600;">' . dol_escape_htmltag($langs->trans('Background')) . '</label>
			<input type="color" name="DOLIZSYNCH_EMPLOYMENT_COLOR" value="' . dol_escape_htmltag($employmentBg) . '" style="width:60px;height:32px;cursor:pointer;">
			<input type="text" value="' . dol_escape_htmltag($employmentBg) . '" readonly style="width:90px;text-transform:uppercase;">
		</div>
		<div style="display:flex;align-items:center;gap:6px;">
			<label style="margin:0;font-weight:600;">' . dol_escape_htmltag($langs->trans('FontColor')) . '</label>
			<input type="color" name="DOLIZSYNCH_EMPLOYMENT_FONT_COLOR" value="' . dol_escape_htmltag($employmentFont) . '" style="width:60px;height:32px;cursor:pointer;">
			<input type="text" value="' . dol_escape_htmltag($employmentFont) . '" readonly style="width:90px;text-transform:uppercase;">
		</div>
		<div style="padding:6px 10px;border-radius:4px;border:1px solid #ddd;background:' . dol_escape_htmltag($employmentBg) . ';color:' . dol_escape_htmltag($employmentFont) . ';">
			Text
		</div>
	</div>
';
$item->fieldOutputOverride = '
		<div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
			<div style="display:flex;align-items:center;gap:6px;">
				<span style="display:inline-block;width:24px;height:24px;border:1px solid #ccc;background:' . dol_escape_htmltag($employmentBg) . ';"></span>
				<span style="font-family:monospace;">' . dol_escape_htmltag(strtoupper($employmentBg)) . '</span>
			</div>
			<div style="display:flex;align-items:center;gap:6px;">
				<span style="display:inline-block;width:24px;height:24px;border:1px solid #ccc;background:' . dol_escape_htmltag($employmentFont) . ';"></span>
				<span style="font-family:monospace;">' . dol_escape_htmltag(strtoupper($employmentFont)) . '</span>
			</div>
			<div style="padding:6px 10px;border-radius:4px;border:1px solid #ddd;background:' . dol_escape_htmltag($employmentBg) . ';color:' . dol_escape_htmltag($employmentFont) . ';">
				Text
			</div>
		</div>
	';

$missedBg = getDolGlobalString('DOLIZSYNCH_MISSED_COLOR', '#f8d7da');
$missedFont = getDolGlobalString('DOLIZSYNCH_MISSED_FONT_COLOR', '#7f1d1d');
$item = $formSetup->newItem('DOLIZSYNCH_MISSED_COLOR');
$item->fieldAttr['type'] = 'color';
$item->defaultFieldValue = $missedBg;
$item->desc = $langs->trans('ShiftPlannerMissedColorHelp');
$item->fieldInputOverride = '
		<div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
			<div style="display:flex;align-items:center;gap:6px;">
				<label style="margin:0;font-weight:600;">' . dol_escape_htmltag($langs->trans('Background')) . '</label>
				<input type="color" name="DOLIZSYNCH_MISSED_COLOR" value="' . dol_escape_htmltag($missedBg) . '" style="width:60px;height:32px;cursor:pointer;">
				<input type="text" value="' . dol_escape_htmltag($missedBg) . '" readonly style="width:90px;text-transform:uppercase;">
			</div>
			<div style="display:flex;align-items:center;gap:6px;">
				<label style="margin:0;font-weight:600;">' . dol_escape_htmltag($langs->trans('FontColor')) . '</label>
				<input type="color" name="DOLIZSYNCH_MISSED_FONT_COLOR" value="' . dol_escape_htmltag($missedFont) . '" style="width:60px;height:32px;cursor:pointer;">
				<input type="text" value="' . dol_escape_htmltag($missedFont) . '" readonly style="width:90px;text-transform:uppercase;">
			</div>
			<div style="padding:6px 10px;border-radius:4px;border:1px solid #ddd;background:' . dol_escape_htmltag($missedBg) . ';color:' . dol_escape_htmltag($missedFont) . ';">
				Text
			</div>
		</div>
	';
$item->fieldOutputOverride = '
		<div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
			<div style="display:flex;align-items:center;gap:6px;">
				<span style="display:inline-block;width:24px;height:24px;border:1px solid #ccc;background:' . dol_escape_htmltag($missedBg) . ';"></span>
				<span style="font-family:monospace;">' . dol_escape_htmltag(strtoupper($missedBg)) . '</span>
			</div>
			<div style="display:flex;align-items:center;gap:6px;">
				<span style="display:inline-block;width:24px;height:24px;border:1px solid #ccc;background:' . dol_escape_htmltag($missedFont) . ';"></span>
				<span style="font-family:monospace;">' . dol_escape_htmltag(strtoupper($missedFont)) . '</span>
			</div>
			<div style="padding:6px 10px;border-radius:4px;border:1px solid #ddd;background:' . dol_escape_htmltag($missedBg) . ';color:' . dol_escape_htmltag($missedFont) . ';">
				Text
			</div>
		</div>
	';

$sqlLeaveTypes = "SELECT code, label FROM " . MAIN_DB_PREFIX . "c_holiday_types WHERE active = 1";
if (!empty($conf->entity)) {
	$sqlLeaveTypes .= " AND entity IN (0, " . ((int) $conf->entity) . ")";
}
$sqlLeaveTypes .= " ORDER BY label";
$resLeaveTypes = $db->query($sqlLeaveTypes);
if ($resLeaveTypes) {
	while ($obj = $db->fetch_object($resLeaveTypes)) {
		$codeUpper = strtoupper($obj->code);
		$constName = 'DOLIZSYNCH_LEAVE_COLOR_' . $codeUpper;
		$defaultColor = getDolGlobalString($constName, getDolGlobalString('DOLIZSYNCH_LEAVE_COLOR', '#cfcfcf'));
		$fontConst = 'DOLIZSYNCH_LEAVE_FONT_COLOR_' . $codeUpper;
		$defaultFontColor = getDolGlobalString($fontConst, '#000000');

		$item = $formSetup->newItem($constName);
		$item->label = $obj->label ?: $obj->code;
		$item->nameText = $obj->label ?: $obj->code;
		$item->fieldAttr['type'] = 'color';
		$item->defaultFieldValue = $defaultColor;
		$item->desc = $langs->trans('DoliZSynchLeaveColorHelp', $obj->label ?: $obj->code);

		$previewText = 'Text';
		$item->fieldInputOverride = '
			<div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
				<div style="display:flex;align-items:center;gap:6px;">
					<label style="margin:0;font-weight:600;">' . dol_escape_htmltag($langs->trans('Background')) . '</label>
					<input type="color" name="' . $constName . '" value="' . dol_escape_htmltag($defaultColor) . '" style="width:60px;height:32px;cursor:pointer;">
					<input type="text" value="' . dol_escape_htmltag($defaultColor) . '" readonly style="width:90px;text-transform:uppercase;">
				</div>
				<div style="display:flex;align-items:center;gap:6px;">
					<label style="margin:0;font-weight:600;">' . dol_escape_htmltag($langs->trans('FontColor')) . '</label>
					<input type="color" name="' . $fontConst . '" value="' . dol_escape_htmltag($defaultFontColor) . '" style="width:60px;height:32px;cursor:pointer;">
					<input type="text" value="' . dol_escape_htmltag($defaultFontColor) . '" readonly style="width:90px;text-transform:uppercase;">
				</div>
				<div style="padding:6px 10px;border-radius:4px;border:1px solid #ddd;background:' . dol_escape_htmltag($defaultColor) . ';color:' . dol_escape_htmltag($defaultFontColor) . ';">
					' . $previewText . '
				</div>
			</div>
		';

		$item->fieldOutputOverride = '
			<div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
				<div style="display:flex;align-items:center;gap:6px;">
					<span style="display:inline-block;width:24px;height:24px;border:1px solid #ccc;background:' . dol_escape_htmltag($defaultColor) . ';"></span>
					<span style="font-family:monospace;">' . dol_escape_htmltag(strtoupper($defaultColor)) . '</span>
				</div>
				<div style="display:flex;align-items:center;gap:6px;">
					<span style="display:inline-block;width:24px;height:24px;border:1px solid #ccc;background:' . dol_escape_htmltag($defaultFontColor) . ';"></span>
					<span style="font-family:monospace;">' . dol_escape_htmltag(strtoupper($defaultFontColor)) . '</span>
				</div>
				<div style="padding:6px 10px;border-radius:4px;border:1px solid #ddd;background:' . dol_escape_htmltag($defaultColor) . ';color:' . dol_escape_htmltag($defaultFontColor) . ';">
					' . $previewText . '
				</div>
			</div>
		';
	}
	$db->free($resLeaveTypes);
}
$sqlLeaveTypes = "SELECT code, label FROM " . MAIN_DB_PREFIX . "c_holiday_types WHERE active = 1";
if (!empty($conf->entity)) {
	$sqlLeaveTypes .= " AND entity IN (0, " . ((int) $conf->entity) . ")";
}
$sqlLeaveTypes .= " ORDER BY label";
$resLeaveTypes = $db->query($sqlLeaveTypes);
if ($resLeaveTypes) {
	while ($obj = $db->fetch_object($resLeaveTypes)) {
		$codeUpper = strtoupper($obj->code);
		$constName = 'DOLIZSYNCH_LEAVE_COLOR_' . $codeUpper;
		$defaultColor = getDolGlobalString($constName, getDolGlobalString('DOLIZSYNCH_LEAVE_COLOR', '#cfcfcf'));
		$fontConst = 'DOLIZSYNCH_LEAVE_FONT_COLOR_' . $codeUpper;
		$defaultFontColor = getDolGlobalString($fontConst, '#000000');

		$item = $formSetup->newItem($constName);
		// Force human-readable label to avoid missing translation warnings on dynamic keys
		$item->label = $obj->label ?: $obj->code;
		$item->nameText = $obj->label ?: $obj->code;
		$item->fieldAttr['type'] = 'color'; // base type (unused because of override)
		$item->defaultFieldValue = $defaultColor;
		$item->desc = $langs->trans('DoliZSynchLeaveColorHelp', $obj->label ?: $obj->code);

		// Combined background + font color inputs with preview
		$previewText = 'Text';
		$item->fieldInputOverride = '
			<div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
				<div style="display:flex;align-items:center;gap:6px;">
					<label style="margin:0;font-weight:600;">' . dol_escape_htmltag($langs->trans('Background')) . '</label>
					<input type="color" name="' . $constName . '" value="' . dol_escape_htmltag($defaultColor) . '" style="width:60px;height:32px;cursor:pointer;">
					<input type="text" value="' . dol_escape_htmltag($defaultColor) . '" readonly style="width:90px;text-transform:uppercase;">
				</div>
				<div style="display:flex;align-items:center;gap:6px;">
					<label style="margin:0;font-weight:600;">' . dol_escape_htmltag($langs->trans('FontColor')) . '</label>
					<input type="color" name="' . $fontConst . '" value="' . dol_escape_htmltag($defaultFontColor) . '" style="width:60px;height:32px;cursor:pointer;">
					<input type="text" value="' . dol_escape_htmltag($defaultFontColor) . '" readonly style="width:90px;text-transform:uppercase;">
				</div>
				<div style="padding:6px 10px;border-radius:4px;border:1px solid #ddd;background:' . dol_escape_htmltag($defaultColor) . ';color:' . dol_escape_htmltag($defaultFontColor) . ';">
					' . $previewText . '
				</div>
			</div>
		';

		$item->fieldOutputOverride = '
			<div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
				<div style="display:flex;align-items:center;gap:6px;">
					<span style="display:inline-block;width:24px;height:24px;border:1px solid #ccc;background:' . dol_escape_htmltag($defaultColor) . ';"></span>
					<span style="font-family:monospace;">' . dol_escape_htmltag(strtoupper($defaultColor)) . '</span>
				</div>
				<div style="display:flex;align-items:center;gap:6px;">
					<span style="display:inline-block;width:24px;height:24px;border:1px solid #ccc;background:' . dol_escape_htmltag($defaultFontColor) . ';"></span>
					<span style="font-family:monospace;">' . dol_escape_htmltag(strtoupper($defaultFontColor)) . '</span>
				</div>
				<div style="padding:6px 10px;border-radius:4px;border:1px solid #ddd;background:' . dol_escape_htmltag($defaultColor) . ';color:' . dol_escape_htmltag($defaultFontColor) . ';">
					' . $previewText . '
				</div>
			</div>
		';
	}
	$db->free($resLeaveTypes);
}

// Leave columns mapping (visual pills + hidden textarea)
$formSetup->newItem('DOLIZSYNCH_REPORT_LEAVE_COLUMNS_TITLE')->setAsTitle();

$dayoffAbsenceKey = 'DOLIZSYNCH_REPORT_DAYOFF_AS_ABSENCE';
if (!isset($conf->global->$dayoffAbsenceKey)) {
	dolibarr_set_const($db, $dayoffAbsenceKey, '1', 'chaine', 0, '', $conf->entity);
	$conf->global->$dayoffAbsenceKey = 1;
}
$item = $formSetup->newItem($dayoffAbsenceKey);
$item->setAsYesNo();
$item->defaultFieldValue = '1';
$item->helpText = $langs->trans('DOLIZSYNCH_REPORT_DAYOFF_AS_ABSENCE_HELP');
if (!empty($conf->use_javascript_ajax)) {
	$item->fieldInputOverride = ajax_constantonoff($dayoffAbsenceKey, array(), $conf->entity, 0, 0, 0, 2, 0, 1);
}

$cleanLeaveMap = function ($rawMap) {
	$camelToSnakeUpper = function ($s) {
		$s = trim((string) $s);
		if ($s === '') return '';
		if (preg_match('/^[A-Z0-9_]+$/', $s)) {
			return strtoupper($s);
		}
		$s = preg_replace('/(?<!^)([A-Z])/', '_$1', $s);
		return strtoupper($s);
	};
	$rawMap = (string) $rawMap;
	$rawMap = str_replace(array("\r\n", "\r", "\n"), ' ', $rawMap);
	$rawMap = str_replace(array("\\r\\n", "\\n", "\\r"), ' ', $rawMap);
	$rawMap = preg_replace('/\s+/', ' ', $rawMap);
	$parts = array_filter(array_map('trim', explode(';', $rawMap)));
	$cleanParts = array();
	foreach ($parts as $part) {
		if ($part === '') continue;
		$sub = explode(':', $part, 2);
		$labelPart = trim($sub[0]);
		$exprPart = isset($sub[1]) ? trim($sub[1]) : '';
		$labelPart = str_replace(array("\r\n", "\r", "\n", "\\r\\n", "\\n", "\\r"), ' ', $labelPart);
		$exprPart = str_replace(array("\r\n", "\r", "\n", "\\r\\n", "\\n", "\\r"), ' ', $exprPart);
		$labelPart = preg_replace('/\s+/', ' ', trim($labelPart));
		$exprPart = preg_replace('/\s+/', ' ', trim($exprPart));
		$exprPart = preg_replace_callback('/[A-Za-z][A-Za-z0-9_]*/', function ($m) use ($camelToSnakeUpper) {
			return $camelToSnakeUpper($m[0]);
		}, $exprPart);
		$cleanParts[] = $labelPart . ':' . $exprPart;
	}
	return implode(";", $cleanParts);
};

if (!empty($_POST['DOLIZSYNCH_REPORT_LEAVE_COLUMNS'])) {
	$_POST['DOLIZSYNCH_REPORT_LEAVE_COLUMNS'] = $cleanLeaveMap($_POST['DOLIZSYNCH_REPORT_LEAVE_COLUMNS']);
	$rawLeaveColumns = $_POST['DOLIZSYNCH_REPORT_LEAVE_COLUMNS'];
} else {
	$rawLeaveColumns = $cleanLeaveMap(getDolGlobalString('DOLIZSYNCH_REPORT_LEAVE_COLUMNS', ''));
}
$escapedLeaveColumns = dol_escape_htmltag($rawLeaveColumns);
$item = $formSetup->newItem('DOLIZSYNCH_REPORT_LEAVE_COLUMNS');
$item->label = $langs->transnoentities('DOLIZSYNCH_REPORT_LEAVE_COLUMNS');
$item->fieldAttr['type'] = 'textarea';
$item->fieldAttr['rows'] = '6';
$item->fieldAttr['style'] = 'min-width: 420px; display:none;';
$item->fieldAttr['placeholder'] = 'Vacations:VAC+FERIAS;Sickness:BAIXA+BAIXA_SEG;Total:BAIXA+BAIXA_SEG-ABS';
$item->defaultFieldValue = $rawLeaveColumns;
$item->desc = $langs->trans("Configure leave columns per entity.");
$item->helpText = $langs->trans('LeaveColumnsHelpExtended');
$builtinVars = array('PUBLIC_HOLIDAY', 'PUBLIC_HOLIDAY_WORKED', 'PUBLIC_HOLIDAY_WORKED_HOURS', 'SCHEDULED', 'WORKED', 'TRAINING_HOURS');
$availableLeaveCodes = array();
$sqlCodes = "SELECT DISTINCT UPPER(code) AS c FROM " . MAIN_DB_PREFIX . "c_holiday_types WHERE active = 1";
if (!empty($conf->entity)) {
	$sqlCodes .= " AND entity IN (0," . ((int) $conf->entity) . ")";
}
$resCodes = $db->query($sqlCodes);
if ($resCodes) {
	while ($o = $db->fetch_object($resCodes)) {
		if (!empty($o->c)) $availableLeaveCodes[] = $o->c;
	}
	$db->free($resCodes);
}
$availableVars = array_merge($builtinVars, $availableLeaveCodes);
$availableVars = array_unique($availableVars);
$varsHint = implode(', ', $availableVars);
$item->fieldInputOverride = '
	<div class="dz-leave-map-wrap">
		<textarea name="DOLIZSYNCH_REPORT_LEAVE_COLUMNS" id="dz-leave-map-raw" style="display:none;">' . $escapedLeaveColumns . '</textarea>
		<div class="dz-leave-map-list" id="dz-leave-map-list"></div>
		<div class="dz-leave-map-actions">
			<button type="button" class="dz-leave-icon-btn" id="dz-leave-map-add" title="' . dol_escape_htmltag($langs->trans('Add')) . '"><span class="fa fa-plus"></span></button>
		</div>
	</div>
	<div class="modal fade" id="dzLeaveMapModal" tabindex="-1" role="dialog" aria-hidden="true">
		<div class="modal-dialog" role="document">
			<div class="modal-content">
				<div class="modal-header">
					<h5 class="modal-title">' . dol_escape_htmltag($langs->trans('Edit')) . '</h5>
					<button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
				</div>
				<div class="modal-body">
					<div class="form-group">
						<label>' . dol_escape_htmltag($langs->trans('Label')) . '</label>
						<input type="text" class="form-control" id="dz-leave-map-label" autocomplete="off">
					</div>
					<div class="form-group">
						<label>' . dol_escape_htmltag($langs->trans('Color')) . '</label>
						<div style="display:flex;gap:8px;align-items:center;">
							<input type="color" id="dz-leave-map-color" style="width:60px;height:34px;cursor:pointer;">
							<input type="text" id="dz-leave-map-color-text" class="form-control" style="max-width:120px;text-transform:uppercase;" autocomplete="off">
						</div>
					</div>
					<div class="form-group">
						<label>' . dol_escape_htmltag($langs->trans('Width')) . ' (px, optional)</label>
						<input type="number" class="form-control" id="dz-leave-map-width" min="30" max="400" step="10">
					</div>
					<div class="form-group">
						<label>' . dol_escape_htmltag($langs->trans('Expression')) . '</label>
						<textarea class="form-control" id="dz-leave-map-expr" rows="3" placeholder="EXPR"></textarea>
						<small class="form-text text-muted" style="font-size: 11px; line-height: 1.2;">' . dol_escape_htmltag($langs->trans('AvailableVariables')) . ': ' . dol_escape_htmltag($varsHint) . '</small>
					</div>
					<div class="form-group" style="margin-top:12px; display:flex; align-items:center; gap:8px;">
						<input type="checkbox" class="form-check-input" id="dz-leave-map-manual" style="margin:0; position:static;">
						<label class="form-check-label" for="dz-leave-map-manual" style="margin:0;">' . dol_escape_htmltag($langs->trans('Manual')) . '</label>
					</div>
				</div>
				<div class="modal-footer">
					<button type="button" class="dz-leave-icon-btn delete mr-auto" id="dz-leave-map-delete" title="' . dol_escape_htmltag($langs->trans('Delete')) . '"><span class="fa fa-trash"></span></button>
					<button type="button" class="dz-leave-icon-btn" data-dismiss="modal" title="' . dol_escape_htmltag($langs->trans('Cancel')) . '"><span class="fa fa-times"></span></button>
					<button type="button" class="dz-leave-icon-btn" id="dz-leave-map-save" title="' . dol_escape_htmltag($langs->trans('Save')) . '"><span class="fa fa-save"></span></button>
				</div>
			</div>
		</div>
	</div>
';

$outputPills = array();
$parts = array_filter(array_map('trim', explode(';', $rawLeaveColumns)));
foreach ($parts as $idx => $part) {
	$firstColon = strpos($part, ':');
	$labelPart = $firstColon !== false ? substr($part, 0, $firstColon) : $part;
	$expr = $firstColon !== false ? trim(substr($part, $firstColon + 1)) : '';
	$tokens = array_filter(array_map('trim', explode('|', $labelPart)));
	$label = count($tokens) ? array_shift($tokens) : ('Column ' . ($idx + 1));
	// Aggressively strip ALL newline variants
	$label = str_replace(array("\r\n", "\r", "\n", "\\r\\n", "\\n", "\\r"), ' ', $label);
	$label = preg_replace('/\s+/', ' ', trim($label));
	$expr = str_replace(array("\r\n", "\r", "\n", "\\r\\n", "\\n", "\\r"), ' ', $expr);
	$expr = preg_replace('/\s+/', ' ', trim($expr));
	$color = '#dcdcdc';
	foreach ($tokens as $tok) {
		if (stripos($tok, 'color=') === 0) {
			$color = substr($tok, 6);
		} elseif (strcasecmp($tok, 'gray') === 0 || strcasecmp($tok, 'grey') === 0) {
			$color = '#e0e0e0';
		}
	}
	$outputPills[] = array('label' => $label, 'expr' => $expr, 'color' => $color);
}
$pillHtml = '<div class="dz-leave-map-list dz-leave-map-list-view">';
if (!empty($outputPills)) {
	foreach ($outputPills as $pill) {
		$pillHtml .= '<div class="dz-leave-pill">
			<span class="dz-leave-pill-color" style="background:' . dol_escape_htmltag($pill["color"]) . ';"></span>
			<span class="dz-leave-pill-label">' . dol_escape_htmltag($pill["label"]) . '</span>
			<span class="dz-leave-pill-expr">' . dol_escape_htmltag($pill["expr"]) . '</span>
		</div>';
	}
} else {
	$pillHtml .= '<div class="opacitymedium">' . dol_escape_htmltag($langs->trans('NoData')) . '</div>';
}
$pillHtml .= '</div>';
$item->fieldOutputOverride = $pillHtml;

$sql = "SELECT rowid, label FROM " . MAIN_DB_PREFIX . "c_country WHERE active = 1 ORDER BY label ASC";
$resql = $db->query($sql);
$TCountries = array();
if ($resql) {
	while ($obj = $db->fetch_object($resql)) {
		$TCountries[$obj->rowid] = $obj->label;
	}
	$db->free($resql);
}
$item = $formSetup->newItem('DOLIZSYNCH_PUBLIC_HOLIDAY_COUNTRY');
$item->label = $langs->transnoentities('DOLIZSYNCH_PUBLIC_HOLIDAY_COUNTRY');
$item->setAsSelect($TCountries);
$item->defaultFieldValue = getDolGlobalInt('DOLIZSYNCH_PUBLIC_HOLIDAY_COUNTRY', 25);
$item->desc = $langs->trans('ShiftPlannerHolidayCountryHelp');

$formSetup->htmlAfterOutputForm .= '
<script>
jQuery(function($){
	if ($.fn.datetimepicker) {
		var stepping = ' . (int) $minuteStep . ';
		$("#shift-day-start-picker").datetimepicker({
			format: "HH:mm",
			stepping: stepping,
			allowInputToggle: true,
			icons: { time: "fa fa-clock", up: "fa fa-chevron-up", down: "fa fa-chevron-down" }
		});
		$("#shift-day-end-picker").datetimepicker({
			format: "HH:mm",
			stepping: stepping,
			allowInputToggle: true,
			icons: { time: "fa fa-clock", up: "fa fa-chevron-up", down: "fa fa-chevron-down" }
		});
	}
});
</script>';

$formSetup->htmlAfterOutputForm .= '
<script>
jQuery(function($){
	var rawEl = $("#dz-leave-map-raw");
	if (!rawEl.length) return;
	try {

	var listEl = $("#dz-leave-map-list");
	var addBtn = $("#dz-leave-map-add");
	var modal = $("#dzLeaveMapModal");
	var labelInput = $("#dz-leave-map-label");
var colorInput = $("#dz-leave-map-color");
var colorTextInput = $("#dz-leave-map-color-text");
var exprInput = $("#dz-leave-map-expr");
var widthInput = $("#dz-leave-map-width");
var saveBtn = $("#dz-leave-map-save");
var deleteBtn = $("#dz-leave-map-delete");
	var manualCheckbox = $("#dz-leave-map-manual");
	var currentIndex = null;
	var fallbackColor = "#dcdcdc";
	var noDataText = "' . dol_escape_js($langs->transnoentities('NoData')) . '";
	function camelToSnakeUpper(str) {
		if (!str) return "";
		if (/^[A-Z0-9_]+$/.test(str.trim())) {
			return str.trim().toUpperCase();
		}
		return str.replace(/([a-z0-9])([A-Z])/g, "$1_$2").toUpperCase();
	}

	function normalizeColor(val) {
		if (!val) return fallbackColor;
		val = val.trim();
		if (val[0] !== "#") val = "#" + val;
		if (val.length === 4) {
			val = "#" + val[1] + val[1] + val[2] + val[2] + val[3] + val[3];
		}
		return val.toUpperCase();
	}

	function stripAllNewlines(str) {
		if (!str) return "";
		// Strip ALL newline variants: real (\r\n, \r, \n) and escaped (\\r\\n, \\n, \\r)
		str = str.replace(/\r\n|\r|\n/g, " ");
		str = str.replace(/\\r\\n|\\n|\\r/gi, " ");
		// Collapse multiple spaces
		str = str.replace(/\s+/g, " ");
		return str.trim();
	}

	function parseRaw(raw) {
		raw = raw || "";
		// First, strip all newline variants from the entire raw string
		raw = stripAllNewlines(raw);
		// Split by semicolon
		var parts = raw.split(";").map(function(p){ return p.trim(); }).filter(Boolean);
		return parts.map(function(part, idx){
			// Additional cleaning per part
			part = stripAllNewlines(part);
			var firstColon = part.indexOf(":");
			var labelPart = firstColon >= 0 ? part.substring(0, firstColon) : part;
			var expr = firstColon >= 0 ? part.substring(firstColon + 1).trim() : "";
			// Clean label and expression
			labelPart = stripAllNewlines(labelPart);
			expr = stripAllNewlines(expr);
			var tokens = labelPart.split("|").map(function(t){ return stripAllNewlines(t); }).filter(Boolean);
			var label = tokens.length ? tokens.shift() : ("Column " + (idx + 1));
			var color = fallbackColor;
			var options = [];
			tokens.forEach(function(tok){
				if (/^color=/i.test(tok)) {
					var cVal = tok.substring(6);
					if (cVal) color = cVal;
				} else if (/^w=/i.test(tok)) {
					options.push(tok);
				} else if (tok.toLowerCase() === "gray" || tok.toLowerCase() === "grey") {
					color = "#e0e0e0";
					options.push(tok);
				} else if (tok.toLowerCase() === "manual") {
					options.push(tok);
				} else {
					options.push(tok);
				}
			});
			var isManual = options.some(function(o){ return o.toLowerCase() === "manual"; });
			var widthOpt = "";
			options.forEach(function(o){
				if (/^w=/i.test(o)) widthOpt = o.substring(2);
			});
			// Final cleanup
			label = stripAllNewlines(label);
			expr = stripAllNewlines(expr);
			return { label: label, expr: expr, color: normalizeColor(color), options: options, manual: isManual, width: widthOpt };
		});
	}

	var entries = parseRaw(rawEl.val());

	function serialize() {
		return entries.map(function(e){
			var opts = (e.options || []).slice().filter(Boolean);
			opts = opts.filter(function(opt){ return !/^color=/i.test(opt); });
			opts = opts.filter(function(opt){ return opt.toLowerCase() !== "manual"; });
			opts = opts.filter(function(opt){ return !/^w=/i.test(opt); });
			if (e.manual) {
				opts.push("manual");
			}
			if (e.color) {
				opts.push("color=" + normalizeColor(e.color));
			}
			if (e.width) {
				var wClean = String(e.width).trim();
				if (wClean !== "" && !isNaN(wClean)) {
					opts.push("w=" + wClean);
				}
			}
			// Use stripAllNewlines for aggressive cleaning
			var labelSan = stripAllNewlines(e.label || "");
			var exprSan = stripAllNewlines(e.expr || "");
			exprSan = exprSan.replace(/[A-Za-z][A-Za-z0-9_]*/g, function(tok){ return camelToSnakeUpper(tok); });
			var labelPart = [ labelSan ].concat(opts).filter(Boolean).join("|");
			return labelPart + ":" + exprSan;
		}).join(";");
	}

	function rebuildTextarea() {
		rawEl.val(serialize());
	}

	function renderList() {
		listEl.empty();
		if (!entries.length) {
			listEl.append($("<div>").addClass("opacitymedium").text(noDataText));
			rebuildTextarea();
			return;
		}
		entries.forEach(function(e, idx){
			var pill = $("<div>").addClass("dz-leave-pill");
			pill.append($("<span>").addClass("dz-leave-pill-color").css("background", e.color));
			pill.append($("<span>").addClass("dz-leave-pill-label").text(e.label || ("Column " + (idx + 1))));
			if (e.width) {
				pill.append($("<span>").addClass("dz-leave-pill-width").text("w=" + e.width + "px"));
			}
			pill.append($("<span>").addClass("dz-leave-pill-expr").text(e.expr || ""));
			if (e.manual) {
				pill.append($("<span>").addClass("dz-leave-pill-manual").text("' . dol_escape_js($langs->transnoentities('Manual')) . '"));
			}
			var actions = $("<div>").addClass("dz-leave-pill-actions");
			var editBtn = $("<button type=\"button\" class=\"dz-leave-icon-btn\"><span class=\"fa fa-edit\"></span></button>").attr("title","' . dol_escape_js($langs->transnoentities('Edit')) . '");
			editBtn.on("click", function(){ openModal(idx); });
			var delBtn = $("<button type=\"button\" class=\"dz-leave-icon-btn delete\"><span class=\"fa fa-trash\"></span></button>").attr("title","' . dol_escape_js($langs->transnoentities('Delete')) . '");
			delBtn.on("click", function(){
				if (!confirm("' . dol_escape_js($langs->transnoentities('ConfirmDelete')) . '")) return;
				entries.splice(idx,1);
				renderList();
			});
			actions.append(editBtn);
			actions.append(delBtn);
			pill.append(actions);
			listEl.append(pill);
		});
		rebuildTextarea();
		// Overwrite the raw value with sanitized serialization to avoid persisting escaped newlines
		rawEl.val(serialize());
	}

	function openModal(idx) {
		currentIndex = idx;
		var e = entries[idx] || { label: "", expr: "", color: fallbackColor };
		labelInput.val(e.label);
		colorInput.val(normalizeColor(e.color));
		colorTextInput.val(normalizeColor(e.color));
		exprInput.val(e.expr);
		widthInput.val(e.width || "");
		manualCheckbox.prop("checked", !!e.manual);
		modal.modal("show");
	}

	saveBtn.on("click", function(){
		if (currentIndex === null) return;
		var updated = entries[currentIndex] || { options: [] };
		// Aggressively clean all input values
		updated.label = stripAllNewlines(labelInput.val()) || ("Column " + (currentIndex + 1));
		updated.color = normalizeColor(colorInput.val() || colorTextInput.val() || fallbackColor);
		updated.expr = camelToSnakeUpper(stripAllNewlines(exprInput.val()));
		updated.width = widthInput.val().trim();
		updated.manual = manualCheckbox.is(":checked");
		if (!Array.isArray(updated.options)) updated.options = [];
		entries[currentIndex] = updated;
		renderList();
		modal.modal("hide");
	});

	addBtn.on("click", function(){
		entries.push({ label: "' . dol_escape_js($langs->transnoentities('New')) . '", expr: "", color: fallbackColor, options: [] });
		renderList();
		openModal(entries.length - 1);
	});

	deleteBtn.on("click", function(){
		if (currentIndex === null) return;
		if (!confirm("' . dol_escape_js($langs->transnoentities('ConfirmDelete')) . '")) return;
		entries.splice(currentIndex,1);
		modal.modal("hide");
		renderList();
	});

	colorInput.on("input", function(){
		colorTextInput.val(normalizeColor(colorInput.val()));
	});
	colorTextInput.on("input", function(){
		var normalized = normalizeColor(colorTextInput.val());
		colorInput.val(normalized);
	});

	renderList();
	} catch (e) {
		console.error(e);
		rawEl.show();
	}
});
</script>';

$setupnotempty += count($formSetup->items);

// Save - Aggressively strip all newline artifacts
if (!empty($_POST['DOLIZSYNCH_REPORT_LEAVE_COLUMNS'])) {
	$rawMap = (string) $_POST['DOLIZSYNCH_REPORT_LEAVE_COLUMNS'];
	// Strip ALL newline variants (real and escaped) - convert to spaces
	$rawMap = str_replace(array("\r\n", "\r", "\n"), ' ', $rawMap);
	$rawMap = str_replace(array("\\r\\n", "\\n", "\\r"), ' ', $rawMap);
	$rawMap = preg_replace('/\s+/', ' ', $rawMap);
	// Split by semicolon and clean each part
	$parts = array_filter(array_map('trim', explode(';', $rawMap)));
	$cleanParts = array();
	foreach ($parts as $part) {
		if ($part === '') continue;
		$sub = explode(':', $part, 2);
		$labelPart = trim($sub[0]);
		$exprPart = isset($sub[1]) ? trim($sub[1]) : '';
		// Final aggressive cleanup - remove any remaining newline artifacts
		$labelPart = str_replace(array("\r\n", "\r", "\n", "\\r\\n", "\\n", "\\r"), ' ', $labelPart);
		$exprPart = str_replace(array("\r\n", "\r", "\n", "\\r\\n", "\\n", "\\r"), ' ', $exprPart);
		$labelPart = preg_replace('/\s+/', ' ', trim($labelPart));
		$exprPart = preg_replace('/\s+/', ' ', trim($exprPart));
		$cleanParts[] = $labelPart . ':' . $exprPart;
	}
	$_POST['DOLIZSYNCH_REPORT_LEAVE_COLUMNS'] = implode(";", $cleanParts);
}

include DOL_DOCUMENT_ROOT . '/core/actions_setmoduleoptions.inc.php';

// Native ajax yes/no "off" action can delete the constant; restore as explicit 0.
if ($notesDeleteAction) {
	dolibarr_set_const($db, $employeeWeekNotesKey, '0', 'chaine', 0, '', $conf->entity);
	$conf->global->{$employeeWeekNotesKey} = '0';
}

// Force PRG after save so freshly saved values are reloaded (avoids stale pills/colors)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($error)) {
	header('Location: ' . $_SERVER['PHP_SELF']);
	exit;
}

if ($action == 'updateMask') {
	$maskconst = GETPOST('maskconst', 'aZ09');
	$maskvalue = GETPOST('maskvalue', 'alpha');

	if ($maskconst && preg_match('/_MASK$/', $maskconst)) {
		$res = dolibarr_set_const($db, $maskconst, $maskvalue, 'chaine', 0, '', $conf->entity);
		if (!($res > 0)) {
			$error++;
		}
	}

	if (!$error) {
		setEventMessages($langs->trans("SetupSaved"), null, 'mesgs');
	} else {
		setEventMessages($langs->trans("Error"), null, 'errors');
	}
}

/*
 * View
 */
$help_url = '';
$page_name = "DoliZSynchPlannerSetup";

$moreCss = '<link rel="stylesheet" type="text/css" href="' . dol_buildpath('/kreaflow/css/dolizsynch.css', 1) . '">';

llxHeader($moreCss, $langs->trans($page_name), $help_url);

$linkback = '<a href="' . ($backtopage ? $backtopage : DOL_URL_ROOT . '/admin/modules.php?restore_lastsearch_values=1') . '">' . $langs->trans("BackToModuleList") . '</a>';

print load_fiche_titre($langs->trans($page_name), $linkback, 'title_setup');

$head = dolizsynchAdminPrepareHead();
print dol_get_fiche_head($head, 'planner_setup', $langs->trans($page_name), -1, "dolizsynch@kreaflow");

echo '<span class="opacitymedium">' . $langs->trans("DoliZSynchPlannerSetupDesc") . '</span><br><br>';

if ($action == 'edit') {
	print $formSetup->generateOutput(true);
	print '<br>';
} else {
	$hasItems = !empty($formSetup->items);
	if ($hasItems) {
		print $formSetup->generateOutput();
		print '<div class="tabsAction">';
		print '<a class="butAction" href="' . $_SERVER["PHP_SELF"] . '?action=edit&token=' . newToken() . '">' . $langs->trans("Modify") . '</a>';
		print '</div>';
	} else {
		print '<br>' . $langs->trans("NothingToSetup");
	}
}

dol_fiche_end();

llxFooter();
$db->close();

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
 * \file    schedule_employees.php
 * \ingroup dolizsynch
 * \brief   Schedule view by employees.
 *
 * README SNIPPET:
 * - License key: Home → Setup → Other setup → add constant FULLCALENDAR_SCHEDULER_KEY.
 * - Show menu: enable module + grant rights (Users & Groups → Permissions → DoliZSynch → Shift Planner read/write).
 * - CDN fallback: place Scheduler bundle under /custom/kreaflow/vendor/fullcalendar/, or uncomment the CDN block below.
 *
 * ACCEPTANCE TESTS (manual):
 * - User with read+write: loads weekly timeline, can create via select+label, drag between employees, resize shift, delete via click.
 * - User with read only: events load, but create/update/delete are blocked.
 */

require '../../../main.inc.php';
require_once __DIR__ . '/../lib/shiftplanner.lib.php';

global $conf, $langs, $user;

$langs->loadLangs(['main', 'errors', 'dolizsynch@kreaflow']);
$weekPickerLang = !empty($langs->defaultlang) ? substr($langs->defaultlang, 0, 2) : 'en';

if (empty($user->rights->dolizsynch->shift->read)) {
	accessforbidden();
}

$title = 'HORÁRIO SEMANAL';
$helpUrl = '';
$requestedWeek = '';
$rawWeek = GETPOST('week', 'alpha');
if (empty($rawWeek) && isset($_GET['week'])) {
	$rawWeek = trim($_GET['week']);
}
if (!empty($rawWeek) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $rawWeek)) {
	$requestedWeek = $rawWeek;
}
if ($requestedWeek === '') {
	// Default to the current week (user timezone) when nothing is requested
	$requestedWeek = dol_print_date(dol_now('tzuser'), '%Y-%m-%d');
}

$licenseKey = getDolGlobalString('FULLCALENDAR_SCHEDULER_KEY', 'YOUR-LICENSE-KEY');
$apiUrl = dol_buildpath('/kreaflow/class/zsshift.class.php', 1);
$dayWindow = shiftplanner_get_day_window();
$normalizeLeaveCode = function ($code) {
	$code = trim((string) $code);
	if ($code === '') return '';
	if (function_exists('dol_string_unaccent')) {
		$code = dol_string_unaccent($code);
	} else {
		$code = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $code);
	}
	// Replace non-alnum with underscore, collapse repeats
	$code = preg_replace('/[^A-Za-z0-9]+/', '_', $code);
	$code = preg_replace('/_{2,}/', '_', $code);
	$code = trim($code, '_');
	return strtoupper($code);
};
$dayOffColor = getDolGlobalString('DOLIZSYNCH_DAYOFF_COLOR', '#bfbfbf');
$leaveColorFallback = getDolGlobalString('DOLIZSYNCH_LEAVE_COLOR', '#cfcfcf');
$leaveFontDefault = getDolGlobalString('DOLIZSYNCH_LEAVE_FONT_COLOR', '#000000');
$dayOffColorFont = getDolGlobalString('DOLIZSYNCH_DAYOFF_FONT_COLOR', '#000000');
$futureColor = getDolGlobalString('DOLIZSYNCH_EMPLOYMENT_COLOR', '#d9d9d9');
$missedColor = getDolGlobalString('DOLIZSYNCH_MISSED_COLOR', '#f8d7da');
$missedFontColor = getDolGlobalString('DOLIZSYNCH_MISSED_FONT_COLOR', '#7f1d1d');
$showBadgeNormal = (int) getDolGlobalInt('DOLIZSYNCH_SHIFT_SHOW_BADGE_NORMAL', 0);
$showBadgeSpecial = (int) getDolGlobalInt('DOLIZSYNCH_SHIFT_SHOW_BADGE_SPECIAL', 0);
$capNormalToContract = (int) getDolGlobalInt('DOLIZSYNCH_SHIFT_CAP_NORMAL_TO_CONTRACT', 0);
$maxShiftHours = (float) getDolGlobalString('DOLIZSYNCH_SHIFT_MAX_HOURS', '8');
$includeForeignShifts = (int) getDolGlobalInt('DOLIZSYNCH_SHIFT_INCLUDE_FOREIGN', 1);
$employeeWeekNotesEnabled = false;
$sqlNotesFlag = "SELECT value FROM " . MAIN_DB_PREFIX . "const";
$sqlNotesFlag .= " WHERE name = 'DOLIZSYNCH_SHIFT_EMPLOYEE_WEEK_NOTES_ENABLED'";
$sqlNotesFlag .= " AND entity = " . ((int) $conf->entity);
$sqlNotesFlag .= " LIMIT 1";
$resqlNotesFlag = $db->query($sqlNotesFlag);
if ($resqlNotesFlag) {
	if ($objNotesFlag = $db->fetch_object($resqlNotesFlag)) {
		$employeeWeekNotesEnabled = ((int) $objNotesFlag->value > 0);
	}
	$db->free($resqlNotesFlag);
}
$useExtrafieldHours = shiftplanner_use_extrafield_hours();
$contractedHoursSource = $useExtrafieldHours
	? $langs->trans('ShiftPlannerHoursSourceExtrafield')
	: $langs->trans('ShiftPlannerHoursSourceWeeklyHours');
$shiftLabels = array(
	'dayoff' => $langs->transnoentities('ShiftPlannerDayOffLabel'),
	'leave' => $langs->transnoentities('ShiftPlannerLeaveLabel'),
	'optionShift' => $langs->transnoentities('ShiftPlannerOptionShift'),
	'optionDayOff' => $langs->transnoentities('ShiftPlannerOptionDayOff'),
	'optionLeave' => $langs->transnoentities('ShiftPlannerOptionLeave'),
	'optionMissed' => $langs->transnoentities('ShiftPlannerOptionMissed'),
	'shiftStatusLabel' => $langs->transnoentities('ShiftPlannerShiftStatusLabel'),
	'shiftStatusScheduled' => $langs->transnoentities('ShiftPlannerShiftStatusScheduled'),
	'shiftStatusMissed' => $langs->transnoentities('ShiftPlannerShiftStatusMissed'),
	'shiftStatusMissedUnjustified' => $langs->transnoentities('ShiftPlannerShiftStatusMissedUnjustified'),
	'shiftStatusMissedJustified' => $langs->transnoentities('ShiftPlannerShiftStatusMissedJustified'),
	'missedShiftBadge' => $langs->transnoentities('ShiftPlannerMissedBadgeLabel'),
	'missedAbsenceTypeMissing' => $langs->transnoentities('ShiftPlannerMissedAbsenceTypeMissing'),
	'employeeOnDayOff' => $langs->transnoentities('ShiftPlannerEmployeeOnDayOff'),
	'employeeOnLeave' => $langs->transnoentities('ShiftPlannerEmployeeOnLeave'),
	'futureEmployment' => $langs->transnoentities('ShiftPlannerFutureEmploymentLabel'),
	'dayBeforeHire' => $langs->transnoentities('ShiftPlannerDayBeforeHireLabel'),
	'employeeBeforeHire' => $langs->transnoentities('ShiftPlannerEmployeeBeforeHire'),
	'afterEmployment' => $langs->transnoentities('ShiftPlannerAfterEmploymentLabel'),
	'dayAfterEnd' => $langs->transnoentities('ShiftPlannerDayAfterEndLabel'),
	'employeeAfterEnd' => $langs->transnoentities('ShiftPlannerEmployeeAfterEnd'),
	'confirmRemoveDayOff' => $langs->transnoentities('ShiftPlannerConfirmRemoveDayOff'),
	'confirmRemoveLeave' => $langs->transnoentities('ShiftPlannerConfirmRemoveLeave'),
	'confirmMissedAbsenceConversion' => $langs->transnoentities('ShiftPlannerConfirmMissedAbsenceConversion'),
	'reconvertToShift' => $langs->transnoentities('ShiftPlannerReconvertToShift'),
	'reconvertToShiftTooltip' => $langs->transnoentities('ShiftPlannerReconvertToShiftTooltip'),
	'confirmReconvertToShift' => $langs->transnoentities('ShiftPlannerConfirmReconvertToShift'),
	'reconvertToShiftError' => $langs->transnoentities('ShiftPlannerReconvertToShiftError'),
	'shiftDescriptionLabel' => $langs->transnoentities('ShiftPlannerShiftDescriptionLabel'),
	'descriptionBadgeLabel' => $langs->transnoentities('ShiftPlannerDescriptionBadgeLabel'),
	'copyBlocked' => $langs->transnoentities('ShiftPlannerCopyBlocked'),
	'selectDayOffDate' => $langs->transnoentities('ShiftPlannerSelectDayOffDate'),
	'selectLeaveType' => $langs->transnoentities('ShiftPlannerSelectLeaveType'),
	'fillLeaveDates' => $langs->transnoentities('ShiftPlannerFillLeaveDates'),
	'invalidLeaveRange' => $langs->transnoentities('ShiftPlannerInvalidLeaveRange'),
	'errorCreateDayOff' => $langs->transnoentities('ShiftPlannerErrorCreateDayOff'),
	'errorDeleteDayOff' => $langs->transnoentities('ShiftPlannerErrorDeleteDayOff'),
	'errorCreateLeave' => $langs->transnoentities('ShiftPlannerErrorCreateLeave'),
	'errorDeleteLeave' => $langs->transnoentities('ShiftPlannerErrorDeleteLeave'),
	'wrongEntity' => $langs->transnoentities('ShiftPlannerWrongEntity'),
	'cannotMoveDayOff' => $langs->transnoentities('ShiftPlannerCannotMoveDayOff'),
	'cannotMoveLeave' => $langs->transnoentities('ShiftPlannerCannotMoveLeave'),
	'notePrefix' => $langs->transnoentities('ShiftPlannerNotePrefix'),
	'recurringHint' => $langs->transnoentities('ShiftPlannerRecurringHint'),
	'leaveHoursOption' => $langs->transnoentities('ShiftPlannerLeaveHoursOption'),
	'leaveMinutesOption' => $langs->transnoentities('ShiftPlannerLeaveMinutesOption'),
	'leaveHoursStart' => $langs->transnoentities('ShiftPlannerLeaveHoursStart'),
	'leaveHoursEnd' => $langs->transnoentities('ShiftPlannerLeaveHoursEnd'),
	'leaveHoursRequired' => $langs->transnoentities('ShiftPlannerLeaveHoursRequired'),
	'leaveHoursSameDay' => $langs->transnoentities('ShiftPlannerLeaveHoursSameDay'),
	'leaveHalfMorningLabel' => $langs->transnoentities('ShiftPlannerLeaveHalfMorningLabel'),
	'leaveHalfAfternoonLabel' => $langs->transnoentities('ShiftPlannerLeaveHalfAfternoonLabel'),
	'copyRowNextWeek' => $langs->transnoentities('ShiftPlannerCopyRowNextWeek'),
	'copyRowConfirm' => $langs->transnoentities('ShiftPlannerCopyRowConfirm'),
	'weekPickerThisWeek' => $langs->transnoentities('ShiftPlannerWeekPickerThisWeek'),
	'employeeColumnLabel' => $langs->transnoentities('ShiftPlannerEmployeeColumnLabel'),
	'employeeSortLabel' => $langs->transnoentities('ShiftPlannerEmployeeSortLabel'),
	'employeeSortName' => $langs->transnoentities('ShiftPlannerEmployeeSortName'),
	'employeeSortTask' => $langs->transnoentities('ShiftPlannerEmployeeSortTask'),
	'employeeHeaderTooltip' => $langs->transnoentities('ShiftPlannerEmployeeHeaderTooltip'),
	'employeeNameTooltip' => $langs->transnoentities('ShiftPlannerEmployeeNameTooltip'),
	'employeeHoursTooltip' => $langs->transnoentities('ShiftPlannerEmployeeHoursTooltip'),
	'employeeMainTaskTooltip' => $langs->transnoentities('ShiftPlannerEmployeeTaskTooltip'),
	'employeeHeadingTooltip' => $langs->transnoentities('ShiftPlannerEmployeeHeadingTooltip'),
	'employeeWeekNoteColumnLabel' => $langs->transnoentities('ShiftPlannerEmployeeWeekNoteColumnLabel'),
	'employeeWeekNotePlaceholder' => $langs->transnoentities('ShiftPlannerEmployeeWeekNotePlaceholder'),
	'employeeWeekNoteSave' => $langs->transnoentities('ShiftPlannerEmployeeWeekNoteSave'),
	'employeeWeekNoteSaveError' => $langs->transnoentities('ShiftPlannerEmployeeWeekNoteSaveError'),
	'mainTaskLabel' => $langs->transnoentities('ShiftPlannerMainTaskLabel'),
	'mainTaskNone' => $langs->transnoentities('ShiftPlannerMainTaskNone'),
	'mainTaskPlaceholder' => $langs->transnoentities('ShiftPlannerMainTaskPlaceholder'),
	'mainTaskSaveError' => $langs->transnoentities('ShiftPlannerMainTaskSaveError'),
	'manageEmployeesTitle' => $langs->transnoentities('ShiftPlannerManageEmployeesTitle'),
	'manageEmployeesHint' => $langs->transnoentities('ShiftPlannerManageEmployeesHint'),
	'manageEmployeesSearch' => $langs->transnoentities('ShiftPlannerManageEmployeesSearch'),
	'manageEmployeesSave' => $langs->transnoentities('ShiftPlannerManageEmployeesSave'),
	'manageEmployeesCancel' => $langs->transnoentities('ShiftPlannerManageEmployeesCancel'),
	'manageEmployeesEmpty' => $langs->transnoentities('ShiftPlannerManageEmployeesEmpty'),
	'manageEmployeesLoading' => $langs->transnoentities('ShiftPlannerManageEmployeesLoading'),
	'manageEmployeesNoGroup' => $langs->transnoentities('ShiftPlannerManageEmployeesNoGroup'),
	'manageEmployeesApplySuccess' => $langs->transnoentities('ShiftPlannerManageEmployeesApplySuccess'),
	'employeeActiveTag' => $langs->transnoentities('ShiftPlannerEmployeeActiveTag'),
	'employeeInactiveTag' => $langs->transnoentities('ShiftPlannerEmployeeInactiveTag'),
	'employeeStatusToggleError' => $langs->transnoentities('ShiftPlannerEmployeeStatusToggleError'),
	'employeeStatusToggleSuccess' => $langs->transnoentities('ShiftPlannerEmployeeStatusToggleSuccess'),
	'maxShiftHoursExceeded' => $langs->transnoentities('ShiftPlannerMaxShiftHoursExceeded'),
);

$leaveTypes = array();
$leaveTypeColors = array();
$leaveTypeFontColors = array();
$sqlLeaveTypes = "SELECT rowid, code, label FROM " . MAIN_DB_PREFIX . "c_holiday_types WHERE active = 1";
if (!empty($conf->entity)) {
	$sqlLeaveTypes .= " AND entity IN (0, " . ((int) $conf->entity) . ")";
}
$sqlLeaveTypes .= " ORDER BY label";
$resLeaveTypes = $db->query($sqlLeaveTypes);
if ($resLeaveTypes) {
	while ($obj = $db->fetch_object($resLeaveTypes)) {
		$codeUpper = strtoupper($obj->code);
		$codeKey = $normalizeLeaveCode($obj->code);
		$labelKey = $normalizeLeaveCode($obj->label);
		$typeColor = getDolGlobalString('DOLIZSYNCH_LEAVE_COLOR_' . $codeUpper, $leaveColorFallback);
		$typeFontColor = getDolGlobalString('DOLIZSYNCH_LEAVE_FONT_COLOR_' . $codeUpper, '#000000');
		$leaveTypeColors[$codeKey] = $typeColor;
		$leaveTypeColors[$codeUpper] = $typeColor;
		$leaveTypeColors[(string) $obj->rowid] = $typeColor;
		if ($labelKey !== '') {
			$leaveTypeColors[$labelKey] = $typeColor;
		}
		$leaveTypeFontColors[$codeKey] = $typeFontColor;
		$leaveTypeFontColors[$codeUpper] = $typeFontColor;
		$leaveTypeFontColors[(string) $obj->rowid] = $typeFontColor;
		if ($labelKey !== '') {
			$leaveTypeFontColors[$labelKey] = $typeFontColor;
		}
		$leaveTypes[] = array(
			'id' => (int) $obj->rowid,
			'code' => $obj->code,
			'code_normalized' => $codeKey,
			'label' => $obj->label,
			'color' => $typeColor,
			'font_color' => $typeFontColor,
		);
	}
	$db->free($resLeaveTypes);
}

$holidayCountryId = (int) getDolGlobalInt('DOLIZSYNCH_PUBLIC_HOLIDAY_COUNTRY', 25);
$weekStartForPublicHolidays = shiftplanner_resolve_week_start_date($requestedWeek);
$publicHolidays = shiftplanner_fetch_public_holidays($db, $holidayCountryId, $weekStartForPublicHolidays);

$fcVendorRel = '/kreaflow/vendor/fullcalendar';
$fcJsRel = $fcVendorRel . '/index.global.min.js';
$fcJsPath = dol_buildpath($fcJsRel, 0);
$jsReadable = is_string($fcJsPath) && $fcJsPath !== '' && @is_readable($fcJsPath);
$hasLocalAssets = $jsReadable;

if ($hasLocalAssets) {
	$fcJs = dol_buildpath($fcJsRel, 1);
} else {
	// CDN fallback (v6+ has CSS embedded in JS)
	$fcJs = 'https://cdn.jsdelivr.net/npm/fullcalendar-scheduler@6.1.10/index.global.min.js';
}

$assetWarning = '';
if (!$hasLocalAssets) {
	$assetWarning = 'FullCalendar assets not found under vendor/fullcalendar. Using CDN fallback.';
}

llxHeader('', $title, $helpUrl);
$scheduleCssPath = __DIR__ . '/../css/schedule.css';
$scheduleCssVersion = @filemtime($scheduleCssPath);
$scheduleCssUrl = dol_buildpath('/kreaflow/css/schedule.css', 1);
if ($scheduleCssVersion) {
	$scheduleCssUrl .= '?v=' . $scheduleCssVersion;
}
?>
<link rel="stylesheet" type="text/css" href="<?php echo $scheduleCssUrl; ?>">
<link rel="stylesheet"
	href="<?php echo dol_buildpath('/kreaflow/vendor/tempusdominus-bootstrap-4/tempusdominus-bootstrap-4.min.css', 1); ?>">
<link rel="stylesheet"
	href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-datepicker/1.9.0/css/bootstrap-datepicker3.min.css">
<style>
	:root {
		--shift-dayoff-color: <?php echo dol_escape_htmltag($dayOffColor);
								?>;
		--shift-leave-default-color: <?php echo dol_escape_htmltag($leaveColorFallback);
											?>;
		--shift-future-default-color: <?php echo dol_escape_htmltag($futureColor);
											?>;
		--shift-missed-default-color: <?php echo dol_escape_htmltag($missedColor);
											?>;
	}
	/* Enforce uniform day column widths (all columns except the first) */
	#shift-table {
		table-layout: fixed;
	}
	#shift-table th.time-col {
		width: 160px;
		min-width: 160px;
	}
	#shift-table th:not(.time-col):not(.week-note-header),
	#shift-table td[data-date] {
		width: 160px !important;
		min-width: 160px !important;
	}
	#shift-table th.week-note-header,
	#shift-table td.week-note-cell {
		width: 220px !important;
		min-width: 220px !important;
	}
</style>
<script>
window.SCHEDULE_PRINT_URL = '<?php echo dol_escape_js(dol_buildpath('/kreaflow/schedule/schedule_print.php', 1)); ?>';
	window.SHIFT_DAY_START_MINUTES = <?php echo (int) $dayWindow['start_minutes']; ?>;
	window.SHIFT_DAY_END_MINUTES = <?php echo (int) $dayWindow['end_minutes']; ?>;
	window.SHIFT_MINUTE_STEP = <?php echo (int) $dayWindow['minute_step']; ?>;
	window.SHIFT_WEEK_PICKER_LANG = '<?php echo dol_escape_js($weekPickerLang); ?>';
	window.SHIFT_WEEK_PICKER_THIS_WEEK =
		'<?php echo dol_escape_js($langs->transnoentities('ShiftPlannerWeekPickerThisWeek')); ?>';
	window.SHIFT_MAX_SHIFT_HOURS = <?php echo json_encode($maxShiftHours); ?>;
	window.SHIFT_ENTITY = <?php echo (int) $conf->entity; ?>;
</script>
<script src="<?php echo dol_buildpath('/kreaflow/vendor/moment/moment.min.js', 1); ?>"></script>
<script
	src="<?php echo dol_buildpath('/kreaflow/vendor/tempusdominus-bootstrap-4/tempusdominus-bootstrap-4.min.js', 1); ?>">
</script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-datepicker/1.9.0/js/bootstrap-datepicker.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-datepicker/1.9.0/locales/bootstrap-datepicker.pt.min.js">
</script>
<div class="page-header-row" style="margin: 20px;">
	<a href="<?php echo dol_buildpath('/kreaflow/schedule/schedule_bars.php', 1); ?>" class="btn-switch btn-switch-compact">
		&rarr; Vista
	</a>
	<h1 class="page-title"><?php echo dol_escape_htmltag($title); ?></h1>
</div>
<div class="week-selector">
	<div class="week-selector-left">
		<button id="btn-prev-week" class="btn-nav">&larr; Semana Anterior</button>
		<button id="btn-next-week" class="btn-nav">Próxima Semana &rarr;</button>
	</div>
	<div class="week-selector-center">
		<div class="week-picker-wrapper">
			<div class="week-picker-input">
				<input type="text" id="week-picker" class="week-picker" aria-label="Selecionar semana" lang="pt"
					readonly autocomplete="off" inputmode="none">
				<button type="button" id="week-picker-trigger" class="week-picker-trigger"
					aria-label="Abrir calendário">
					<i class="fa fa-calendar-alt" aria-hidden="true"></i>
				</button>
			</div>
			<div id="week-range" class="week-range"></div>
		</div>
	</div>
	<?php if (!empty($user->rights->dolizsynch->shift->write)) { ?>
		<div class="week-selector-action">
			<button id="btn-copy-week" class="btn-nav btn-icon" type="button" title="Copiar Semana"
				aria-label="Copiar Semana">
				<i class="fa fa-copy" aria-hidden="true"></i>
			</button>
			<button id="btn-print-week" class="btn-nav btn-icon" type="button" title="Gerar PDF" aria-label="Gerar PDF">
				<i class="fa fa-print" aria-hidden="true"></i>
			</button>
		</div>
	<?php } ?>
	<button id="btn-today" class="btn-nav btn-hidden-today" type="button">Hoje</button>
</div>

<div class="shift-legend shift-legend-tasks">
	<div class="legend-row">
		<button id="legend-manage-tasks" class="btn-manage-tasks"
			<?php if (empty($user->rights->dolizsynch->shift->manage_tasks)) { ?> disabled
			style="opacity: 0.5; cursor: not-allowed;" title="Sem permissão para gerir tarefas"
			<?php } ?>>Tarefas</button>
		<span id="legend-tasks">Carregando...</span>
	</div>
</div>

<div id="shift-table-container" style="overflow-x: auto; margin: 20px;">
	<table id="shift-table" class="shift-planner-table">
		<thead id="table-header">
			<!-- Will be populated by JavaScript -->
		</thead>
		<tbody id="table-body">
			<!-- Will be populated by JavaScript -->
		</tbody>
	</table>
</div>
<div id="leave-code-legend" class="leave-code-legend" style="display:none;"></div>
<div id="week-notes" class="week-notes" aria-live="polite">
	<div class="week-notes-header">
		<div id="week-notes-title" class="week-notes-title">Notas da semana</div>
		<button type="button" id="week-notes-save" class="btn-nav btn-notes-save">Guardar</button>
	</div>
	<textarea id="week-notes-text" rows="3"
		placeholder="Escreva notas desta semana (guardadas neste navegador)..."></textarea>
</div>

<div id="employee-manager-modal" class="employee-manager-modal" aria-hidden="true">
	<div class="employee-manager-backdrop" data-employee-manager-close></div>
	<div class="employee-manager-dialog" role="dialog" aria-modal="true" aria-labelledby="employee-manager-title">
		<div class="employee-manager-header">
			<h3 id="employee-manager-title">
				<?php echo dol_escape_htmltag($langs->trans('ShiftPlannerManageEmployeesTitle')); ?></h3>
			<button type="button" class="employee-manager-close" data-employee-manager-close
				aria-label="Fechar">&times;</button>
		</div>
		<p class="employee-manager-hint">
			<?php echo dol_escape_htmltag($langs->trans('ShiftPlannerManageEmployeesHint')); ?></p>
		<div class="employee-manager-search">
			<input type="text" id="employee-manager-search"
				placeholder="<?php echo dol_escape_htmltag($langs->trans('ShiftPlannerManageEmployeesSearch')); ?>"
				autocomplete="off">
		</div>
		<div id="employee-manager-feedback" class="employee-manager-feedback" role="alert" style="display:none;"></div>
		<div id="employee-manager-list" class="employee-manager-list" role="listbox" aria-live="polite"></div>
		<div id="employee-manager-empty" class="employee-manager-empty" style="display:none;">
			<?php echo dol_escape_htmltag($langs->trans('ShiftPlannerManageEmployeesEmpty')); ?></div>
		<div class="employee-manager-footer">
			<button type="button" id="employee-manager-cancel"
				class="btn-nav btn-copy-cancel"><?php echo dol_escape_htmltag($langs->trans('ShiftPlannerManageEmployeesCancel')); ?></button>
			<button type="button" id="employee-manager-save"
				class="btn-nav btn-copy-confirm"><?php echo dol_escape_htmltag($langs->trans('ShiftPlannerManageEmployeesSave')); ?></button>
		</div>
	</div>
</div>

<?php if (!empty($user->rights->dolizsynch->shift->write)) { ?>
	<div id="week-copy-modal" class="week-copy-modal" aria-hidden="true">
		<div class="week-copy-backdrop" data-week-copy-close></div>
		<div class="week-copy-dialog" role="dialog" aria-modal="true" aria-labelledby="week-copy-title">
			<h3 id="week-copy-title">Copiar Semana</h3>
			<p class="week-copy-hint">Selecione a semana de origem e a semana de destino. Todos os turnos da semana de
				origem serão copiados para a semana destino.</p>
			<div class="week-copy-field">
				<label for="week-copy-source">Semana de origem</label>
				<div class="week-picker-input">
					<input type="text" id="week-copy-source" class="week-picker" readonly autocomplete="off"
						inputmode="none">
					<button type="button" id="week-copy-source-trigger" class="week-picker-trigger"
						aria-label="Abrir calendário">
						<i class="fa fa-calendar-alt" aria-hidden="true"></i>
					</button>
				</div>
			</div>
			<div class="week-copy-field">
				<label for="week-copy-target">Semana de destino</label>
				<div class="week-picker-input">
					<input type="text" id="week-copy-target" class="week-picker" readonly autocomplete="off"
						inputmode="none">
					<button type="button" id="week-copy-target-trigger" class="week-picker-trigger"
						aria-label="Abrir calendário">
						<i class="fa fa-calendar-alt" aria-hidden="true"></i>
					</button>
				</div>
			</div>
			<div class="week-copy-field week-copy-mode-field">
				<span class="week-copy-mode-label">Modo de cópia</span>
				<label class="week-copy-mode-option">
					<input type="radio" name="week-copy-mode" value="merge" checked>
					<span>Mesclar (seguro)</span>
				</label>
				<label class="week-copy-mode-option">
					<input type="radio" name="week-copy-mode" value="replace">
					<span>Substituir (destrutivo)</span>
				</label>
				<p id="week-copy-replace-warning" class="week-copy-replace-warning" style="display:none;">Replace mode deletes existing shifts/dayoffs in target week before copying.</p>
			</div>
			<div class="week-copy-actions">
				<button type="button" class="btn-nav btn-copy-cancel" id="week-copy-cancel">Cancelar</button>
				<button type="button" class="btn-nav btn-copy-confirm" id="week-copy-confirm">Confirmar</button>
			</div>
		</div>
	</div>

	<div id="quota-modal" class="quota-modal" aria-hidden="true">
		<div class="quota-backdrop" data-quota-close></div>
		<div class="quota-dialog" role="dialog" aria-modal="true" aria-labelledby="quota-modal-title">
			<h3 id="quota-modal-title">Editar Horas Semanais</h3>
			<p class="quota-hint"><?php echo dol_escape_htmltag($langs->trans('ShiftPlannerQuotaHint', $contractedHoursSource)); ?></p>
			<div class="quota-field">
				<label>Funcionário</label>
				<div class="quota-emp-meta">
					<div id="quota-emp-name" class="quota-emp-name">—</div>
					<div id="quota-emp-task" class="quota-emp-task">—</div>
				</div>
			</div>
			<div class="quota-field" id="quota-hours-field">
				<label for="quota-input">Horas semanais</label>
				<input type="number" step="0.1" id="quota-input" placeholder="Ex.: 40">
			</div>
			<div class="quota-field" id="quota-task-field">
				<label
					for="quota-task-select"><?php echo dol_escape_htmltag($langs->trans('ShiftPlannerMainTaskLabel')); ?></label>
				<select id="quota-task-select"></select>
				<div id="quota-task-readonly" class="quota-task-readonly" style="display:none;">—</div>
			</div>
			<div class="quota-actions">
				<button type="button" id="quota-cancel" class="btn-nav btn-copy-cancel">Cancelar</button>
				<button type="button" id="quota-save" class="btn-nav btn-copy-confirm">Guardar</button>
			</div>
		</div>
	</div>
<?php } ?>



<script>
	// Pass PHP variables to JavaScript
	window.SHIFT_API_URL = '<?php echo dol_escape_js($apiUrl); ?>';
	window.SHIFT_CAN_WRITE = <?php echo !empty($user->rights->dolizsynch->shift->write) ? 'true' : 'false'; ?>;
	window.SHIFT_CAN_DELETE = <?php echo !empty($user->rights->dolizsynch->shift->delete) ? 'true' : 'false'; ?>;
	window.SHIFT_CAN_MANAGE_TASKS =
		<?php echo !empty($user->rights->dolizsynch->shift->manage_tasks) ? 'true' : 'false'; ?>;
	window.SHIFT_CAN_EDIT_EMPLOYEE_HOURS =
		<?php echo !empty($user->rights->dolizsynch->shift->edit_employee_hours) ? 'true' : 'false'; ?>;
	window.SHIFT_CAN_MANAGE_EMPLOYEES = <?php echo !empty($user->rights->dolizsynch->shift->write) ? 'true' : 'false'; ?>;
	window.SHIFT_CAN_ASSIGN_MAIN_TASK = <?php echo !empty($user->rights->dolizsynch->shift->write) ? 'true' : 'false'; ?>;
	window.SHIFT_INIT_WEEK = '<?php echo dol_escape_js($requestedWeek); ?>';
	window.SHIFT_INCLUDE_FOREIGN = <?php echo $includeForeignShifts ? 'true' : 'false'; ?>;
	window.SHIFT_LEAVE_TYPES =
		<?php echo json_encode($leaveTypes, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
	window.SHIFT_PUBLIC_HOLIDAYS =
		<?php echo json_encode($publicHolidays, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
	window.SHIFT_COLORS =
			<?php echo json_encode(array('dayoff' => $dayOffColor, 'leave' => $leaveColorFallback, 'future' => $futureColor, 'missed' => $missedColor, 'missedFont' => $missedFontColor, 'leaveTypes' => $leaveTypeColors, 'leaveFonts' => $leaveTypeFontColors, 'leaveFontDefault' => $leaveFontDefault, 'dayoffFont' => $dayOffColorFont), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
	window.SHIFT_LABELS =
		<?php echo json_encode($shiftLabels, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
	window.SHIFT_BADGE_SHOW_NORMAL = <?php echo $showBadgeNormal ? 'true' : 'false'; ?>;
	window.SHIFT_BADGE_SHOW_SPECIAL = <?php echo $showBadgeSpecial ? 'true' : 'false'; ?>;
	window.SHIFT_EMPLOYEE_WEEK_NOTES_ENABLED = <?php echo $employeeWeekNotesEnabled ? '1' : '0'; ?>;
	window.SHIFT_CAP_NORMAL_TO_CONTRACT = <?php echo $capNormalToContract ? 'true' : 'false'; ?>;
	window.SHIFT_USE_EXTRAFIELD_HOURS = <?php echo $useExtrafieldHours ? 'true' : 'false'; ?>;
	window.SHIFT_CONTRACT_HOURS_SOURCE = '<?php echo dol_escape_js($contractedHoursSource); ?>';
</script>
<?php
$scheduleJsPath = __DIR__ . '/../scripts/schedule.js';
$scheduleJsVersion = @filemtime($scheduleJsPath);
$scheduleJsUrl = dol_buildpath('/kreaflow/scripts/schedule.js', 1);
if ($scheduleJsVersion) {
	$scheduleJsUrl .= '?v=' . $scheduleJsVersion;
}
?>
<script src="<?php echo $scheduleJsUrl; ?>"></script>

<?php
llxFooter();
$db->close();

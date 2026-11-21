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
 * \file    schedule_bars.php
 * \ingroup dolizsynch
 * \brief   Schedule view with bars.
 */

require '../../../main.inc.php';
require_once __DIR__ . '/../lib/shiftplanner.lib.php';

global $conf, $langs, $user;

$langs->loadLangs(['main', 'errors', 'dolizsynch@kreaflow']);
$weekPickerLang = !empty($langs->defaultlang) ? substr($langs->defaultlang, 0, 2) : 'en';

if (empty($user->rights->dolizsynch->shift->read)) {
	accessforbidden();
}

$editMode = GETPOST('edit_mode', 'int') ? 1 : 0;
$hasWriteRight = !empty($user->rights->dolizsynch->shift->write);
$effectiveCanWrite = $hasWriteRight && $editMode;

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

$apiUrl = dol_buildpath('/kreaflow/class/zsshift.class.php', 1);
$dayWindow = shiftplanner_get_day_window();
$maxShiftHours = (float) getDolGlobalString('DOLIZSYNCH_SHIFT_MAX_HOURS', '8');
$useExtrafieldHours = shiftplanner_use_extrafield_hours();
$contractedHoursSource = $useExtrafieldHours
	? $langs->trans('ShiftPlannerHoursSourceExtrafield')
	: $langs->trans('ShiftPlannerHoursSourceWeeklyHours');
$holidayCountryId = (int) getDolGlobalInt('DOLIZSYNCH_PUBLIC_HOLIDAY_COUNTRY', 25);
$weekStartForPublicHolidays = shiftplanner_resolve_week_start_date($requestedWeek);
$publicHolidays = shiftplanner_fetch_public_holidays($db, $holidayCountryId, $weekStartForPublicHolidays);

$toggleUrl = '';
$toggleLabel = '';
if ($hasWriteRight) {
	$toggleParams = array();
	if (!empty($requestedWeek)) {
		$toggleParams['week'] = $requestedWeek;
	}
	$toggleParams['edit_mode'] = $editMode ? 0 : 1;
	$toggleUrl = $_SERVER['PHP_SELF'] . '?' . http_build_query($toggleParams);
	$toggleLabel = $editMode ? $langs->trans('LeaveEditMode') : $langs->trans('EnterEditMode');
}

llxHeader('', $title, $helpUrl);
?>
<link rel="stylesheet" type="text/css" href="<?php echo dol_buildpath('/kreaflow/css/schedule.css', 1); ?>">
<link rel="stylesheet"
	href="<?php echo dol_buildpath('/kreaflow/vendor/tempusdominus-bootstrap-4/tempusdominus-bootstrap-4.min.css', 1); ?>">
<link rel="stylesheet"
	href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-datepicker/1.9.0/css/bootstrap-datepicker3.min.css">
<script>
window.SCHEDULE_PRINT_URL = '<?php echo dol_escape_js(dol_buildpath('/kreaflow/schedule/schedule_print.php', 1)); ?>';
	window.SHIFT_DAY_START_MINUTES = <?php echo (int) $dayWindow['start_minutes']; ?>;
	window.SHIFT_DAY_END_MINUTES = <?php echo (int) $dayWindow['end_minutes']; ?>;
	window.SHIFT_MINUTE_STEP = <?php echo (int) $dayWindow['minute_step']; ?>;
	window.SHIFT_WEEK_PICKER_LANG = '<?php echo dol_escape_js($weekPickerLang); ?>';
	window.SHIFT_WEEK_PICKER_THIS_WEEK =
		'<?php echo dol_escape_js($langs->transnoentities('ShiftPlannerWeekPickerThisWeek')); ?>';
	window.SHIFT_MAX_SHIFT_HOURS = <?php echo json_encode($maxShiftHours); ?>;
	window.SHIFT_PUBLIC_HOLIDAYS =
		<?php echo json_encode($publicHolidays, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
	window.SHIFT_LABELS =
		<?php echo json_encode(array('wrongEntity' => $langs->transnoentities('ShiftPlannerWrongEntity')), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
	window.SHIFT_CAN_WRITE = <?php echo $effectiveCanWrite ? 'true' : 'false'; ?>;
	window.SHIFT_CAN_DELETE = <?php echo $effectiveCanWrite ? 'true' : 'false'; ?>;
	window.SHIFT_EDIT_MODE = <?php echo $editMode ? 'true' : 'false'; ?>;
window.SHIFT_DAY_VIEW_BASE = '<?php echo dol_escape_js(dol_buildpath('/kreaflow/schedule/schedule_bars_horizontal.php', 1)); ?>';
</script>
<script src="<?php echo dol_buildpath('/kreaflow/vendor/moment/moment.min.js', 1); ?>"></script>
<script
	src="<?php echo dol_buildpath('/kreaflow/vendor/tempusdominus-bootstrap-4/tempusdominus-bootstrap-4.min.js', 1); ?>">
</script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-datepicker/1.9.0/js/bootstrap-datepicker.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-datepicker/1.9.0/locales/bootstrap-datepicker.pt.min.js">
</script>

<div class="page-header-row" style="margin: 20px;">
	<a href="<?php echo dol_buildpath('/kreaflow/schedule/schedule_employees.php', 1); ?>"
		class="btn-switch btn-switch-compact">
		&larr; Vista
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
	<?php if ($hasWriteRight) { ?>
		<div class="week-selector-action">
			<a href="<?php echo dol_escape_htmltag($toggleUrl); ?>" class="btn-nav btn-icon" title="<?php echo dol_escape_htmltag($toggleLabel); ?>" aria-label="<?php echo dol_escape_htmltag($toggleLabel); ?>">
				<?php if ($editMode) { ?>
					<i class="fa fa-unlock-alt" aria-hidden="true"></i>
				<?php } else { ?>
					<i class="fa fa-lock" aria-hidden="true"></i>
				<?php } ?>
			</a>
		</div>
	<?php } ?>
	<button id="btn-today" class="btn-nav btn-hidden-today" type="button">Hoje</button>
</div>

<?php if ($effectiveCanWrite) { ?>
	<div id="week-copy-modal" class="week-copy-modal" aria-hidden="true">
		<div class="week-copy-backdrop" data-week-copy-close></div>
		<div class="week-copy-dialog" role="dialog" aria-modal="true" aria-labelledby="week-copy-title">
			<h3 id="week-copy-title">Copiar Semana</h3>
			<p class="week-copy-hint">Copie todos os turnos de uma semana para outra. Esta ação adicionará novos turnos na
				semana de destino.</p>
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
<?php } ?>

<div class="shift-legend shift-legend-tasks">
	<div class="legend-row">
		<button id="legend-manage-tasks" class="btn-manage-tasks"
			<?php if (empty($user->rights->dolizsynch->shift->manage_tasks)) { ?> disabled
			style="opacity: 0.5; cursor: not-allowed;" title="Sem permissão para gerir tarefas"
			<?php } ?>>Tarefas</button>
		<span id="legend-tasks">Carregando...</span>
	</div>
</div>

<div class="shift-legend shift-legend-employees">
	<div class="legend-row legend-row-employees">
		<div id="legend-employees">Carregando...</div>
	</div>
</div>

<div id="timegrid-container" style="overflow-x: auto; margin: 20px;">
	<table id="timegrid-table" class="timegrid-table">
		<thead id="table-header">
			<!-- Will be populated by JavaScript -->
		</thead>
		<tbody id="table-body">
			<!-- Will be populated by JavaScript -->
		</tbody>
	</table>
</div>


<script>
	// Pass PHP variables to JavaScript
	window.SHIFT_API_URL = '<?php echo dol_escape_js($apiUrl); ?>';
	window.SHIFT_INIT_WEEK = '<?php echo dol_escape_js($requestedWeek); ?>';
	window.SHIFT_CAN_MANAGE_TASKS =
		<?php echo !empty($user->rights->dolizsynch->shift->manage_tasks) ? 'true' : 'false'; ?>;
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

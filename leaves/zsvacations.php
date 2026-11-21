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
 * \file    zsvacations.php
 * \ingroup dolizsynch
 * \brief   Annual vacation overview by employee.
 */

require '../../../main.inc.php';
require_once __DIR__ . '/../class/zsleaves.class.php';
require_once __DIR__ . '/../lib/shiftplanner.lib.php';

global $db, $conf, $langs, $user;

if (!function_exists('dolizsynchCountWeekdays')) {
	/**
	 * Count number of weekdays (Mon-Fri) between two timestamps inclusive.
	 *
	 * @param int $startTs
	 * @param int $endTs
	 * @return int
	 */
	function dolizsynchCountWeekdays($startTs, $endTs)
	{
		$startTs = (int) $startTs;
		$endTs = (int) $endTs;

		if ($endTs < $startTs) {
			return 0;
		}

		static $utcTimezone = null;
		if ($utcTimezone === null) {
			$utcTimezone = new DateTimeZone('UTC');
		}

		$startDate = new DateTime('@' . $startTs);
		$startDate->setTimezone($utcTimezone);
		$startDate->setTime(0, 0, 0);

		$endDate = new DateTime('@' . $endTs);
		$endDate->setTimezone($utcTimezone);
		$endDate->setTime(0, 0, 0);

		$diffDays = (int) $startDate->diff($endDate)->format('%a') + 1;
		$fullWeeks = (int) floor($diffDays / 7);
		$remainingDays = $diffDays % 7;

		$weekdayCount = $fullWeeks * 5;
		$startDow = (int) $startDate->format('N');

		for ($i = 0; $i < $remainingDays; $i++) {
			$weekday = (($startDow - 1 + $i) % 7) + 1;
			if ($weekday <= 5) {
				$weekdayCount++;
			}
		}

		return $weekdayCount;
	}
}

if (!function_exists('zsvacations_table_has_column')) {
	function zsvacations_table_has_column(DoliDB $db, $table, $column)
	{
		$sql = "SHOW COLUMNS FROM " . $table . " LIKE '" . $db->escape($column) . "'";
		$res = $db->query($sql);
		if (!$res) {
			return false;
		}
		$has = ($db->num_rows($res) > 0);
		$db->free($res);
		return $has;
	}
}

if (!function_exists('zsvacations_parse_id_list')) {
	function zsvacations_parse_id_list($raw)
	{
		$values = array();
		if (is_array($raw)) {
			$values = $raw;
		} else {
			$rawStr = trim((string) $raw);
			if ($rawStr === '') {
				return array();
			}
			$values = preg_split('/\s*,\s*/', $rawStr);
		}
		$ids = array();
		foreach ($values as $value) {
			$id = (int) $value;
			if ($id > 0) {
				$ids[$id] = $id;
			}
		}
		return array_values($ids);
	}
}

if (!function_exists('zsvacations_decode_overrides')) {
	function zsvacations_decode_overrides($raw)
	{
		$result = array(
			'forced_user_ids' => array(),
			'hidden_user_ids' => array(),
			'is_json' => false,
		);
		$rawStr = (string) $raw;
		if ($rawStr === '') {
			return $result;
		}
		$decoded = json_decode($rawStr, true);
		if (
			is_array($decoded) &&
			(array_key_exists('forced_user_ids', $decoded) || array_key_exists('hidden_user_ids', $decoded))
		) {
			$result['is_json'] = true;
			$forced = array();
			if (isset($decoded['forced_user_ids']) && is_array($decoded['forced_user_ids'])) {
				$forced = $decoded['forced_user_ids'];
			}
			$hidden = array();
			if (isset($decoded['hidden_user_ids']) && is_array($decoded['hidden_user_ids'])) {
				$hidden = $decoded['hidden_user_ids'];
			}
			$result['forced_user_ids'] = zsvacations_parse_id_list($forced);
			$result['hidden_user_ids'] = zsvacations_parse_id_list($hidden);
		}
		return $result;
	}
}

if (!function_exists('zsvacations_encode_overrides')) {
	function zsvacations_encode_overrides(array $forcedIds = array(), array $hiddenIds = array(), $forceJson = false)
	{
		$forcedIds = zsvacations_parse_id_list($forcedIds);
		$hiddenIds = zsvacations_parse_id_list($hiddenIds);
		if (!$forceJson && empty($forcedIds) && empty($hiddenIds)) {
			return '';
		}
		$payload = array(
			'forced_user_ids' => $forcedIds,
			'hidden_user_ids' => $hiddenIds,
		);
		$encoded = json_encode($payload, JSON_UNESCAPED_UNICODE);
		return $encoded === false ? '' : $encoded;
	}
}

$langs->loadLangs(['main', 'errors', 'dolizsynch@kreaflow']);

if (empty($user->rights->dolizsynch->shift->schedule_vacations_view)) {
	accessforbidden();
}

$notesTable = MAIN_DB_PREFIX . "dolizsynch_year_notes";
$notesHasForced = zsvacations_table_has_column($db, $notesTable, 'forced_user_ids');
$notesHasHidden = zsvacations_table_has_column($db, $notesTable, 'hidden_user_ids');
$notesHasUpdatedBy = zsvacations_table_has_column($db, $notesTable, 'updated_by');

$action = GETPOST('action', 'alpha');
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'get_year_overrides') {
	$year = (int) GETPOST('year', 'int');
	if ($year <= 0) {
		http_response_code(400);
		echo json_encode(['error' => 'Invalid year']);
		exit;
	}
	$sql = "SELECT note";
	if ($notesHasForced) {
		$sql .= ", forced_user_ids";
	}
	if ($notesHasHidden) {
		$sql .= ", hidden_user_ids";
	}
	$sql .= " FROM " . $notesTable;
	$sql .= " WHERE year = " . $year;
	$sql .= " AND entity IN (0, " . ((int) $conf->entity) . ")";
	$sql .= " ORDER BY entity DESC";
	$res = $db->query($sql);
	if (!$res) {
		http_response_code(500);
		echo json_encode(['error' => $db->lasterror()]);
		exit;
	}
	$forcedIds = array();
	$hiddenIds = array();
	if ($obj = $db->fetch_object($res)) {
		if ($notesHasForced) {
			$forcedIds = zsvacations_parse_id_list($obj->forced_user_ids ?? '');
		}
		if ($notesHasHidden) {
			$hiddenIds = zsvacations_parse_id_list($obj->hidden_user_ids ?? '');
		}
		if (!empty($obj->note)) {
			$decoded = zsvacations_decode_overrides($obj->note);
			if (!empty($decoded['is_json'])) {
				if (empty($forcedIds)) {
					$forcedIds = $decoded['forced_user_ids'];
				}
				if (empty($hiddenIds)) {
					$hiddenIds = $decoded['hidden_user_ids'];
				}
			}
		}
	}
	$db->free($res);
	echo json_encode(array('forced_user_ids' => $forcedIds, 'hidden_user_ids' => $hiddenIds));
	exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'save_year_overrides') {
	$year = (int) GETPOST('year', 'int');
	if ($year <= 0) {
		http_response_code(400);
		echo json_encode(['error' => 'Invalid year']);
		exit;
	}
	$forcedIds = zsvacations_parse_id_list(GETPOST('forced_user_ids', 'none'));
	$hiddenIds = zsvacations_parse_id_list(GETPOST('hidden_user_ids', 'none'));
	$notePayload = zsvacations_encode_overrides($forcedIds, $hiddenIds, true);

	$columns = array('entity', 'year', 'note');
	$values = array(
		(int) $conf->entity,
		$year,
		"'" . $db->escape($notePayload) . "'",
	);
	if ($notesHasForced) {
		$forcedCsv = implode(',', array_map('intval', $forcedIds));
		$columns[] = 'forced_user_ids';
		$values[] = $forcedCsv !== '' ? "'" . $db->escape($forcedCsv) . "'" : "NULL";
	}
	if ($notesHasHidden) {
		$hiddenCsv = implode(',', array_map('intval', $hiddenIds));
		$columns[] = 'hidden_user_ids';
		$values[] = $hiddenCsv !== '' ? "'" . $db->escape($hiddenCsv) . "'" : "NULL";
	}
	if ($notesHasUpdatedBy) {
		$columns[] = 'updated_by';
		$values[] = (int) $user->id;
	}
	$sqlSave = "REPLACE INTO " . $notesTable . " (" . implode(', ', $columns) . ")";
	$sqlSave .= " VALUES (" . implode(', ', $values) . ")";
	if (!$db->query($sqlSave)) {
		http_response_code(500);
		echo json_encode(['error' => $db->lasterror()]);
		exit;
	}
	echo json_encode(['success' => true]);
	exit;
}

$todayYear = (int) date('Y');
$selectedYear = (int) GETPOST('year', 'int');
if ($selectedYear <= 0) {
	$selectedYear = $todayYear;
}

$leavesHelper = new ZSLeaves($db);
$leaveTable = MAIN_DB_PREFIX . $leavesHelper->table_element;
$leaveEntityFilter = "(l.entity IS NULL OR l.entity IN (0, " . ((int) $conf->entity) . "))";
$allowedEmployees = array_values(array_map('intval', array_unique($leavesHelper->getEntityEmployeesForYear($selectedYear, $conf->entity, 2, 'u.firstname'))));
$allowedEmployees = array_values(array_filter($allowedEmployees, function ($id) {
	return $id > 0;
}));

$vacTypeIds = [];
$allTypes = $leavesHelper->getTypes(1);
$vacCodes = array('CP', 'VAC', 'VACATION', 'FERIAS', 'FERIA', 'FERIE');
foreach ($allTypes as $typeId => $row) {
	$codeUpper = strtoupper(trim((string) ($row['code'] ?? '')));
	if ($codeUpper !== '' && in_array($codeUpper, $vacCodes, true)) {
		$vacTypeIds[(int) $typeId] = (int) $typeId;
	}
}
if (empty($vacTypeIds) && !empty($allTypes)) {
	foreach ($allTypes as $typeId => $row) {
		$label = trim((string) ($row['label'] ?? ''));
		if ($label === '') {
			continue;
		}
		$labelLower = dol_strtolower($label);
		if (strpos($labelLower, 'fer') !== false || strpos($labelLower, 'vac') !== false) {
			$vacTypeIds[(int) $typeId] = (int) $typeId;
		}
	}
}
if (empty($vacTypeIds)) {
	$vacTypeIds = array(-1);
}
$vacTypeSql = implode(',', array_map('intval', $vacTypeIds));

$dbType = strtolower((string) ($db->type ?? ''));
$isPgsql = strpos($dbType, 'pgsql') !== false;
$yearStartExpr = $isPgsql
	? "EXTRACT(YEAR FROM l.date_start)"
	: "YEAR(l.date_start)";
$yearEndExpr = $isPgsql
	? "EXTRACT(YEAR FROM l.date_end)"
	: "YEAR(l.date_end)";
$years = [];
$sqlYears = "SELECT DISTINCT year_value FROM (";
$sqlYears .= " SELECT " . $yearStartExpr . " AS year_value FROM " . $leaveTable . " l";
$sqlYears .= " WHERE " . $leaveEntityFilter;
$sqlYears .= " AND l.fk_type IN (" . $vacTypeSql . ")";
$sqlYears .= " UNION ";
$sqlYears .= " SELECT " . $yearEndExpr . " AS year_value FROM " . $leaveTable . " l";
$sqlYears .= " WHERE " . $leaveEntityFilter;
$sqlYears .= " AND l.fk_type IN (" . $vacTypeSql . ")";
$sqlYears .= ") AS year_rows";
$sqlYears .= " ORDER BY year_value DESC";
$resYears = $db->query($sqlYears);
if ($resYears) {
	while ($objYear = $db->fetch_object($resYears)) {
		if ($objYear && $objYear->year_value !== null) {
			$years[] = (int) $objYear->year_value;
		}
	}
	$db->free($resYears);
}
if (empty($years)) {
	$years[] = $selectedYear;
}
$years[] = $todayYear;
$years[] = $selectedYear;
$years = array_values(array_unique(array_filter($years, function ($year) {
	return (int) $year > 0;
})));
rsort($years, SORT_NUMERIC);
if (!in_array($selectedYear, $years, true)) {
	$selectedYear = (int) $years[0];
}

$rangeStartTs = dol_mktime(0, 0, 0, 1, 1, $selectedYear);
$rangeEndTs = dol_mktime(23, 59, 59, 12, 31, $selectedYear);
$rangeStartSql = $db->idate($rangeStartTs);
$rangeEndSql = $db->idate($rangeEndTs);

$leaveEmployees = [];
$sqlEmployees = "SELECT DISTINCT l.fk_user";
$sqlEmployees .= " FROM " . $leaveTable . " l";
$sqlEmployees .= " WHERE " . $leaveEntityFilter;
$sqlEmployees .= " AND l.fk_type IN (" . $vacTypeSql . ")";
$sqlEmployees .= " AND l.date_end >= '" . $db->escape($rangeStartSql) . "'";
$sqlEmployees .= " AND l.date_start <= '" . $db->escape($rangeEndSql) . "'";
$resEmployees = $db->query($sqlEmployees);
if ($resEmployees) {
	while ($objEmp = $db->fetch_object($resEmployees)) {
		$empId = (int) ($objEmp->fk_user ?? 0);
		if ($empId > 0) {
			$leaveEmployees[$empId] = $empId;
		}
	}
	$db->free($resEmployees);
}
if (!empty($leaveEmployees)) {
	$allowedEmployees = array_values(array_unique(array_merge($allowedEmployees, array_values($leaveEmployees))));
}
if (empty($allowedEmployees)) {
	$allowedEmployees = array(-1);
}

$leavesByEmployee = [];
$employees = [];

$sql = "SELECT l.rowid, l.fk_user, l.date_start, l.date_end, l.halfday,";
$sql .= " u.firstname, u.lastname";
$sql .= " FROM " . $leaveTable . " l";
$sql .= " INNER JOIN " . MAIN_DB_PREFIX . "user u ON u.rowid = l.fk_user";
$sql .= " WHERE " . $leaveEntityFilter;
$sql .= " AND l.fk_type IN (" . $vacTypeSql . ")";
if (!empty($allowedEmployees)) {
	$sql .= " AND u.rowid IN (" . implode(',', array_map('intval', $allowedEmployees)) . ")";
}
$sql .= " AND l.date_end >= '" . $db->escape($rangeStartSql) . "'";
$sql .= " AND l.date_start <= '" . $db->escape($rangeEndSql) . "'";
$sql .= " ORDER BY u.firstname, u.lastname, l.date_start";

$resql = $db->query($sql);
if ($resql) {
	while ($obj = $db->fetch_object($resql)) {
		$empId = (int) $obj->fk_user;
		if (!isset($employees[$empId])) {
			$fullName = trim(($obj->firstname ?: '') . ' ' . ($obj->lastname ?: ''));
			$employees[$empId] = $fullName ?: ('Funcionario #' . $empId);
			$leavesByEmployee[$empId] = [];
		}
		$startTs = shiftplannerNormalizeTimestamp($db, $obj->date_start);
		$endTs = shiftplannerNormalizeTimestamp($db, $obj->date_end);
		if (!$startTs || !$endTs) {
			continue;
		}
		$periodStart = max($startTs, $rangeStartTs);
		$periodEnd = min($endTs, $rangeEndTs);
		if ($periodEnd < $periodStart) {
			continue;
		}
		$durationDays = dolizsynchCountWeekdays($periodStart, $periodEnd);
		$totalDays = (($periodEnd - $periodStart) / 86400) + 1;
		$halfdayVal = (int) $obj->halfday;
		if ($halfdayVal === 1) {
			$durationDays -= 0.5;
			$totalDays -= 0.5;
		} elseif ($halfdayVal === 2 || $halfdayVal === 3) {
			// Treat period/minutes as partial day
			$durationDays = min($durationDays, 1);
			$totalDays = min($totalDays, 1);
		}
		$durationDays = round(max(0, $durationDays), 1);
		$totalDays = round(max(0, $totalDays), 1);
			$leavesByEmployee[$empId][] = [
				'id' => (int) $obj->rowid,
				'ref' => 'LV' . (int) $obj->rowid,
				'start' => $periodStart,
				'end' => $periodEnd,
				'days' => $durationDays,
				'total_days' => $totalDays,
			];
	}
	$db->free($resql);
}

ksort($employees, SORT_NUMERIC);
$pageTitle = $langs->trans('VacationsYearTitle', $selectedYear);
llxHeader('', $pageTitle);
?>
<link rel="stylesheet" type="text/css" href="<?php echo dol_buildpath('/kreaflow/css/schedule_vacations.css', 1); ?>">
<script>
	window.VACATIONS_PRINT_URL = '<?php echo dol_escape_js(dol_buildpath('/kreaflow/leaves/zsvacations_print.php', 1)); ?>';
</script>
<script src="<?php echo dol_buildpath('/kreaflow/scripts/schedule_vacations.js', 1); ?>"></script>

<div class="vacations-container">
	<div class="vacations-header">
	<h2 class="vacations-title">
		<span class="vacations-title-text"><?php echo dol_escape_htmltag($pageTitle); ?></span>
		<button type="button" id="manage-employees-btn" class="vacations-title-btn"
			aria-label="<?php echo dol_escape_htmltag($langs->trans('ShiftPlannerManageEmployeesHint')); ?>"
			title="<?php echo dol_escape_htmltag($langs->trans('ShiftPlannerManageEmployeesHint')); ?>"></button>
	</h2>
		<div class="vacations-filters">
			<form method="get" id="vacations-filter-form">
				<label for="vacations-year"><?php echo $langs->trans('Year'); ?>:</label>
				<select name="year" id="vacations-year">
					<?php foreach ($years as $year): ?>
						<option value="<?php echo (int) $year; ?>"
							<?php echo ($year == $selectedYear) ? 'selected' : ''; ?>>
							<?php echo (int) $year; ?>
						</option>
					<?php endforeach; ?>
				</select>
			</form>
			<a class="butAction" href="<?php echo dol_buildpath('/kreaflow/leaves/month_report.php', 1) . '?year=' . (int) $selectedYear; ?>"><?php echo dol_escape_htmltag($langs->trans('VacationMapTitle', $selectedYear)); ?></a>
			<button type="button" class="vacations-print-btn">
				<?php echo dol_escape_htmltag($langs->trans('Print')); ?>
			</button>
		</div>
	</div>

	<?php if (!empty($leavesByEmployee)): ?>
		<?php foreach ($employees as $empId => $name): ?>
			<?php $records = $leavesByEmployee[$empId] ?? []; ?>
			<?php if (empty($records)) continue; ?>
			<?php
			$totalWorkdays = 0;
			$totalCalendarDays = 0;
			foreach ($records as $rec) {
				$totalWorkdays += $rec['days'];
				$totalCalendarDays += $rec['total_days'];
			}
			?>
				<div class="vacations-employee" data-emp-id="<?php echo (int) $empId; ?>" data-emp-name="<?php echo dol_escape_htmltag($name); ?>">
					<h3>
						<?php
						$empLink = dol_buildpath('/user/card.php', 1) . '?id=' . (int) $empId;
						echo '<a href="' . dol_escape_htmltag($empLink) . '" target="_blank" rel="noopener noreferrer">' . dol_escape_htmltag($name) . '</a>';
						?> - <?php echo number_format($totalCalendarDays, 1); ?> Dias corridos
						(<?php echo number_format($totalWorkdays, 1); ?> Dias uteis)
					</h3>
					<table class="vacations-table">
						<thead>
							<tr>
								<th class="col-ref"><?php echo $langs->trans('Ref'); ?></th>
								<th class="col-date"><?php echo $langs->trans('DateStart'); ?></th>
								<th class="col-date"><?php echo $langs->trans('EndDate'); ?></th>
								<th class="col-days">Dia&#115; uteis</th>
								<th class="col-days">Dia&#115; corridos</th>
							</tr>
					</thead>
						<tbody>
							<?php foreach ($records as $record): ?>
								<tr>
									<td>
										<?php
										$refLabel = $record['ref'] ?: ('#' . (int) $record['id']);
										$refLink = dol_buildpath('/kreaflow/leaves/card.php', 1) . '?id=' . (int) $record['id'];
										echo '<a href="' . dol_escape_htmltag($refLink) . '" target="_blank" rel="noopener noreferrer">' . dol_escape_htmltag($refLabel) . '</a>';
										?>
									</td>
									<td><?php echo dol_print_date($record['start'], '%d/%m/%Y'); ?></td>
									<td><?php echo dol_print_date($record['end'], '%d/%m/%Y'); ?></td>
									<td><?php echo number_format($record['days'], 1); ?> Dia&#115; uteis</td>
									<td><?php echo number_format($record['total_days'] ?? 0, 1); ?> Dia&#115; corridos</td>
								</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		<?php endforeach; ?>
	<?php else: ?>
		<div class="no-records">
			<?php echo $langs->trans('NoDataFound'); ?>
		</div>
	<?php endif; ?>
</div>

<div id="employee-manager-modal" class="employee-manager-modal" aria-hidden="true">
	<div class="employee-manager-backdrop" data-employee-manager-close></div>
	<div class="employee-manager-dialog" role="dialog" aria-modal="true" aria-labelledby="employee-manager-title">
		<div class="employee-manager-header">
			<h3 id="employee-manager-title"><?php echo $langs->transnoentities('ShiftPlannerManageEmployeesTitle'); ?></h3>
			<button type="button" class="employee-manager-close" data-employee-manager-close aria-label="<?php echo dol_escape_htmltag($langs->transnoentities('Close')); ?>">&times;</button>
		</div>
		<p class="employee-manager-hint"><?php echo $langs->transnoentities('ShiftPlannerManageEmployeesHint'); ?></p>
		<div class="employee-manager-search">
			<input type="text" id="employee-manager-search" placeholder="<?php echo dol_escape_htmltag($langs->transnoentities('ShiftPlannerManageEmployeesSearch')); ?>">
		</div>
		<div id="employee-manager-feedback" class="employee-manager-feedback" role="alert" style="display:none;"></div>
		<div id="employee-manager-list" class="employee-manager-list" role="listbox" aria-live="polite"></div>
		<div id="employee-manager-empty" class="employee-manager-empty" style="display:none;">
			<?php echo $langs->transnoentities('ShiftPlannerManageEmployeesEmpty'); ?>
		</div>
		<div class="employee-manager-footer">
			<button type="button" id="employee-manager-cancel" class="button"><?php echo $langs->transnoentities('ShiftPlannerManageEmployeesCancel'); ?></button>
			<button type="button" id="employee-manager-save" class="button"><?php echo $langs->transnoentities('ShiftPlannerManageEmployeesSave'); ?></button>
		</div>
	</div>
</div>

<script>
	(function () {
		const selectedYear = <?php echo (int) $selectedYear; ?>;
		const manageBtn = document.getElementById("manage-employees-btn");
		const titleEl = document.querySelector(".vacations-title");
		const modal = document.getElementById("employee-manager-modal");
		const listEl = document.getElementById("employee-manager-list");
		const emptyEl = document.getElementById("employee-manager-empty");
		const searchInput = document.getElementById("employee-manager-search");
		const saveBtn = document.getElementById("employee-manager-save");
		const cancelBtn = document.getElementById("employee-manager-cancel");
		const backdropTriggers = document.querySelectorAll("[data-employee-manager-close]");
		if ((!manageBtn && !titleEl) || !modal || !listEl) return;

		let employees = [];
		let overridesLoaded = false;
		let storedForced = new Set();
		let storedHidden = new Set();
		let draftHidden = new Set();

		function normalizeSearchText(value) {
			if (!value) return "";
			return value
				.toString()
				.normalize("NFD")
				.replace(/[\u0300-\u036f]/g, "")
				.toLowerCase();
		}

		function escapeHtml(value) {
			return String(value || "")
				.replace(/&/g, "&amp;")
				.replace(/</g, "&lt;")
				.replace(/>/g, "&gt;")
				.replace(/"/g, "&quot;")
				.replace(/'/g, "&#39;");
		}

		function collectEmployees() {
			employees = [];
			document.querySelectorAll(".vacations-employee[data-emp-id]").forEach((row) => {
				const id = row.dataset.empId || "";
				if (!id) return;
				const name = row.dataset.empName || "";
				employees.push({
					id: String(id),
					name: name,
					row: row,
				});
			});
		}

		function applyVisibility(hiddenSet) {
			employees.forEach((emp) => {
				const isHidden = hiddenSet && hiddenSet.has(emp.id);
				emp.row.classList.toggle("vacations-employee-hidden", isHidden);
				emp.row.dataset.hidden = isHidden ? "1" : "0";
			});
		}

		async function fetchYearOverrides() {
			const payload = new URLSearchParams({
				action: "get_year_overrides",
				year: selectedYear,
			});
			const resp = await fetch(window.location.href, {
				method: "POST",
				headers: { "Content-Type": "application/x-www-form-urlencoded" },
				body: payload,
			});
			if (!resp.ok) {
				throw new Error(`HTTP ${resp.status}`);
			}
			const data = await resp.json();
			return {
				forced: Array.isArray(data.forced_user_ids) ? data.forced_user_ids : [],
				hidden: Array.isArray(data.hidden_user_ids) ? data.hidden_user_ids : [],
			};
		}

		async function saveYearOverrides(forcedSet, hiddenSet) {
			const payload = new URLSearchParams({
				action: "save_year_overrides",
				year: selectedYear,
				forced_user_ids: Array.from(forcedSet).join(","),
				hidden_user_ids: Array.from(hiddenSet).join(","),
			});
			const resp = await fetch(window.location.href, {
				method: "POST",
				headers: { "Content-Type": "application/x-www-form-urlencoded" },
				body: payload,
			});
			let data = null;
			try {
				data = await resp.json();
			} catch (err) {
				data = null;
			}
			if (!resp.ok || !data || !data.success) {
				throw new Error((data && data.error) ? data.error : `HTTP ${resp.status}`);
			}
			return true;
		}

		async function loadOverrides(force = false) {
			if (overridesLoaded && !force) return;
			const data = await fetchYearOverrides();
			storedForced = new Set((data.forced || []).map((id) => String(id)));
			storedHidden = new Set((data.hidden || []).map((id) => String(id)));
			overridesLoaded = true;
		}

		function renderList() {
			const term = normalizeSearchText(searchInput && searchInput.value ? searchInput.value.trim() : "");
			const filtered = employees.filter((emp) => {
				if (!term) return true;
				const haystack = normalizeSearchText(`${emp.name} ${emp.id}`);
				return haystack.includes(term);
			});
			if (emptyEl) {
				emptyEl.style.display = filtered.length ? "none" : "block";
			}
			if (!filtered.length) {
				listEl.innerHTML = "";
				return;
			}
			listEl.innerHTML = filtered
				.map((emp) => {
					const checked = draftHidden.has(emp.id) ? "" : "checked";
					return `<label class="employee-manager-item">
						<input type="checkbox" data-user-id="${emp.id}" ${checked}>
						<div class="employee-manager-info">
							<span class="employee-manager-name">${escapeHtml(emp.name || emp.id)}</span>
						</div>
					</label>`;
				})
				.join("");
		}

		async function openModal() {
			collectEmployees();
			try {
				await loadOverrides();
			} catch (err) {
				overridesLoaded = false;
				storedForced = new Set();
				storedHidden = new Set();
			}
			draftHidden = new Set(storedHidden);
			if (searchInput) {
				searchInput.value = "";
			}
			renderList();
			modal.classList.add("open");
			modal.setAttribute("aria-hidden", "false");
		}

		function closeModal() {
			modal.classList.remove("open");
			modal.setAttribute("aria-hidden", "true");
		}

		if (manageBtn) {
			manageBtn.addEventListener("click", openModal);
		}
		if (titleEl) {
			titleEl.addEventListener("click", openModal);
		}
		if (searchInput) {
			searchInput.addEventListener("input", () => {
				renderList();
			});
		}
		if (listEl) {
			listEl.addEventListener("change", (e) => {
				const target = e.target;
				if (!target || !target.matches('input[type="checkbox"][data-user-id]')) return;
				const userId = target.dataset.userId;
				if (!userId) return;
				if (target.checked) {
					draftHidden.delete(String(userId));
				} else {
					draftHidden.add(String(userId));
				}
			});
		}
		if (saveBtn) {
			saveBtn.addEventListener("click", async () => {
				saveBtn.disabled = true;
				try {
					await saveYearOverrides(storedForced, draftHidden);
					storedHidden = new Set(draftHidden);
					applyVisibility(storedHidden);
					closeModal();
				} catch (err) {
					saveBtn.disabled = false;
					closeModal();
				}
			});
		}
		if (cancelBtn) {
			cancelBtn.addEventListener("click", closeModal);
		}
		if (backdropTriggers && backdropTriggers.length) {
			backdropTriggers.forEach((trigger) => {
				trigger.addEventListener("click", closeModal);
			});
		}

		collectEmployees();
		loadOverrides()
			.then(() => {
				if (storedHidden.size) {
					applyVisibility(storedHidden);
				}
			})
			.catch(() => {});
	})();
</script>

<?php
llxFooter();
$db->close();

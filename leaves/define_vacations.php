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

// Vacation balance setup (custom leave storage)

require '../../../main.inc.php';
require_once DOL_DOCUMENT_ROOT . '/core/class/html.form.class.php';
require_once DOL_DOCUMENT_ROOT . '/core/class/html.formother.class.php';
require_once DOL_DOCUMENT_ROOT . '/core/lib/date.lib.php';
require_once DOL_DOCUMENT_ROOT . '/user/class/user.class.php';
require_once __DIR__ . '/../class/zsleaves.class.php';
require_once __DIR__ . '/../lib/shiftplanner.lib.php';

global $db, $langs, $user, $conf, $hookmanager;

$langs->loadLangs(array('users', 'other', 'holiday', 'hrm', 'main'));

if (empty($conf->dolizsynch->enabled)) {
	accessforbidden();
}
if (empty($user->rights->dolizsynch->shift->schedule_vacations_view)) {
	accessforbidden();
}
if ($user->socid > 0) {
	accessforbidden();
}

$action = GETPOST('action', 'aZ09');
$contextpage = GETPOST('contextpage', 'aZ') ?: 'definevacationslist';
$optioncss = GETPOST('optioncss', 'alpha');
$search_name = GETPOST('search_name', 'alphanohtml');
$statusFilter = GETPOSTISSET('status_filter') ? (int) GETPOST('status_filter', 'int') : 1; // default active
$validStatusFilters = array(0, 1, 2); // inactive, active, all
if (!in_array($statusFilter, $validStatusFilters, true)) {
	$statusFilter = 1;
}

$clearFilter = GETPOST('button_removefilter', 'alpha') || GETPOST('button_removefilter_x', 'alpha') || GETPOST('button_removefilter_y', 'alpha');
if ($clearFilter) {
	$search_name = '';
	$_POST['search_name'] = '';
	$statusFilter = 2;
	$_POST['status_filter'] = 2;
}

$yearInput = GETPOST('year', 'int');
if (GETPOSTISSET('year') && (int) $yearInput > 0) {
	$yearFilter = (int) $yearInput;
} else {
	$yearFilter = (int) date('Y');
}
if (GETPOST('year_prev', 'alpha')) $yearFilter--;
if (GETPOST('year_next', 'alpha')) $yearFilter++;
if ($yearFilter < 2000) $yearFilter = 2000;
if ($yearFilter > 2100) $yearFilter = 2100;

$limit = GETPOSTINT('limit') ? GETPOSTINT('limit') : $conf->liste_limit;
$allowedSortFields = array(
	'u.lastname' => 'u.lastname',
	'u.firstname' => 'u.firstname',
	'u.login' => 'u.login',
	'u.rowid' => 'u.rowid',
);
$sortfieldRaw = GETPOST('sortfield', 'none');
$sortfield = isset($allowedSortFields[$sortfieldRaw]) ? $sortfieldRaw : 'u.firstname';
$sortorder = strtoupper((string) GETPOST('sortorder', 'alpha')) === 'DESC' ? 'DESC' : 'ASC';
$page = GETPOSTISSET('pageplusone') ? (GETPOSTINT('pageplusone') - 1) : GETPOSTINT("page");
$page = $clearFilter ? 0 : $page;
if ($page < 0) $page = 0;
$offset = $limit * $page;

$editUserId = GETPOSTINT('edit_quota_user');

$hookmanager->initHooks(array('definevacationslist'));
$form = new Form($db);
$formother = new FormOther($db);
$leaves = new ZSLeaves($db);

$canEdit = !empty($user->rights->dolizsynch->shift->schedule_vacations_edit);
$childIds = $user->getAllChildIds(1);
$canSeeAll = !empty($user->rights->dolizsynch->shift->schedule_vacations_view);
$token = newToken();

$maxShiftHours = (float) getDolGlobalString('DOLIZSYNCH_SHIFT_MAX_HOURS', '8');
if ($maxShiftHours <= 0) $maxShiftHours = 8;
$dayMinutesBase = $maxShiftHours * 60;
$defaultVacationDays = 0;
$carryoverCapDays = 0;

$employeeSortField = isset($allowedSortFields[$sortfield]) ? $sortfield : 'u.firstname';
$employeeSortOrder = (strtolower((string) $sortorder) === 'desc') ? 'DESC' : 'ASC';

$allowedEmployees = array_unique(array_map('intval', array_values($leaves->getEntityEmployeesForYear($yearFilter, $conf->entity, $statusFilter, $employeeSortField, $employeeSortOrder))));
if (!$canSeeAll) {
	$childConstraint = array_unique(array_merge($childIds, array($user->id)));
	if (!empty($childConstraint)) {
		$allowedEmployees = array_values(array_intersect($allowedEmployees, $childConstraint));
	} else {
		$allowedEmployees = array();
	}
}
if (empty($allowedEmployees)) {
	$allowedEmployees = array(-1);
}

$quotaTable = $db->prefix() . 'dolizsynch_leave_quota';

// Cancel edit
if ($canEdit && GETPOST('cancel', 'alpha') !== '') {
	$redirectParams = array(
		'year' => $yearFilter,
		'search_name' => $search_name,
		'status_filter' => $statusFilter,
		'limit' => $limit,
		'sortfield' => $sortfield,
		'sortorder' => $sortorder,
		'page' => $page,
	);
	$q = http_build_query(array_filter($redirectParams, function ($v) { return $v !== '' && $v !== null; }));
	header('Location: ' . $_SERVER["PHP_SELF"] . ($q ? '?' . $q : ''));
	exit;
}

// Bulk apply quotas to all allowed employees
if ($action === 'update' && $canEdit && GETPOSTISSET('bulk_apply')) {
	$bulkYear = GETPOST('bulk_year', 'int');
	$bulkYear = GETPOSTISSET('bulk_year') ? (int) $bulkYear : $yearFilter;
	if ($bulkYear < 2000) $bulkYear = 2000;
	if ($bulkYear > 2100) $bulkYear = 2100;
	$bulkValues = GETPOST('bulk_value', 'array');
	$db->begin();
	$err = 0;
	foreach ($leaves->getTypes(1) as $typeId => $rowType) {
		$raw = isset($bulkValues[$typeId]) ? trim((string) $bulkValues[$typeId]) : '';
		if ($raw === '') continue;
		$days = price2num($raw, 5);
		foreach ($allowedEmployees as $empId) {
			if ($empId <= 0) continue;
			$sql = "REPLACE INTO " . $quotaTable . " (fk_user, fk_type, year, days_total, note, created_by, entity)";
			$sql .= " VALUES (" . (int) $empId . ", " . (int) $typeId . ", " . (int) $bulkYear . ", ";
			$sql .= $db->escape($days) . ", NULL, " . (int) $user->id . ", " . (int) $conf->entity . ")";
			if (!$db->query($sql)) $err++;
		}
	}
	if ($err) {
		$db->rollback();
		setEventMessages($langs->trans("Error"), null, 'errors');
	} else {
		$db->commit();
		setEventMessages($langs->trans("RecordSaved"), null, 'mesgs');
		header("Location: " . $_SERVER["PHP_SELF"] . '?year=' . (int) $bulkYear);
		exit;
	}
}

// Vacation types (codes CP/VAC/VACATION/FERIAS). Fallback to first available.
$allTypes = $leaves->getTypes(1);
$vacTypes = array();
foreach ($allTypes as $tid => $row) {
	$code = strtoupper((string) $row['code']);
	if (in_array($code, array('CP', 'VAC', 'VACATION', 'FERIAS'), true)) {
		$vacTypes[$tid] = $row;
	}
}
if (empty($vacTypes) && !empty($allTypes)) {
	$first = array_key_first($allTypes);
	$vacTypes[$first] = $allTypes[$first];
}

// Save per-user
if ($action === 'update' && $canEdit && GETPOSTISSET('update_quota')) {
	$userKeys = array_keys(GETPOST('update_quota', 'array'));
	$userId = (int) reset($userKeys);
	if ($userId > 0) {
		$db->begin();
		$err = 0;
		foreach ($vacTypes as $typeId => $row) {
			$valArr = GETPOST('nb_holiday_' . (int) $typeId, 'array');
			$val = isset($valArr[$userId]) ? trim((string) $valArr[$userId]) : '';
			$days = ($val === '' ? null : price2num($val, 5));
			$noteArr = GETPOST('note_holiday', 'array');
			$note = isset($noteArr[$userId]) ? trim((string) $noteArr[$userId]) : '';

			if ($days === null && $note === '') {
				$sqlDel = "DELETE FROM " . $quotaTable . " WHERE fk_user = " . $userId . " AND fk_type = " . (int) $typeId . " AND entity IN (0, " . ((int) $conf->entity) . ") AND year = " . (int) $yearFilter;
				if (!$db->query($sqlDel)) $err++;
				continue;
			}

			$sql = "REPLACE INTO " . $quotaTable . " (fk_user, fk_type, year, days_total, note, created_by, entity)";
			$sql .= " VALUES (" . $userId . ", " . (int) $typeId . ", " . (int) $yearFilter . ", ";
			$sql .= ($days === null ? "0" : $db->escape($days)) . ", ";
			$sql .= ($note !== '' ? "'" . $db->escape($note) . "'" : "NULL") . ", ";
			$sql .= (int) $user->id . ", " . (int) $conf->entity . ")";
			if (!$db->query($sql)) $err++;
		}
		if ($err) {
			$db->rollback();
			setEventMessages($langs->trans("Error"), null, 'errors');
		} else {
			$db->commit();
			setEventMessages($langs->trans("RecordSaved"), null, 'mesgs');
			header("Location: " . $_SERVER["PHP_SELF"]);
			exit;
		}
	}
}

// Quotas map
$quotaMap = array();
$sqlQuota = "SELECT fk_user, fk_type, year, days_total, note FROM " . $quotaTable . " WHERE entity IN (0, " . ((int) $conf->entity) . ") AND year IN (" . (int) $yearFilter . ", 0) ORDER BY year DESC";
$resq = $db->query($sqlQuota);
if ($resq) {
	while ($o = $db->fetch_object($resq)) {
		$uid = (int) $o->fk_user;
		$tid = (int) $o->fk_type;
		if (!isset($quotaMap[$uid][$tid]) || (int) $o->year === (int) $yearFilter) {
			$quotaMap[$uid][$tid] = array('days' => (float) $o->days_total, 'note' => (string) $o->note);
		}
	}
	$db->free($resq);
}
$prevYear = $yearFilter - 1;
$prevQuotaMap = array();
$sqlPrevQuota = "SELECT fk_user, fk_type, year, days_total FROM " . $quotaTable . " WHERE entity IN (0, " . ((int) $conf->entity) . ") AND year IN (" . (int) $prevYear . ", 0) ORDER BY year DESC";
$resqPrev = $db->query($sqlPrevQuota);
if ($resqPrev) {
	while ($op = $db->fetch_object($resqPrev)) {
		$uid = (int) $op->fk_user;
		$tid = (int) $op->fk_type;
		if (!isset($prevQuotaMap[$uid][$tid]) || (int) $op->year === (int) $prevYear) {
			$prevQuotaMap[$uid][$tid] = (float) $op->days_total;
		}
	}
	$db->free($resqPrev);
}
$usageMap = $leaves->getUsageByUser($allowedEmployees, array_keys($vacTypes), $yearFilter, $dayMinutesBase);
$prevUsageMap = $leaves->getUsageByUser($allowedEmployees, array_keys($vacTypes), $prevYear, $dayMinutesBase);

// Employees list with pagination
$usersqlbase = " FROM " . MAIN_DB_PREFIX . "user as u";
$usersqlbase .= " WHERE u.employee = 1";
$usersqlbase .= " AND u.entity IN (" . getEntity('user') . ")";
if (!empty($allowedEmployees)) {
	$usersqlbase .= " AND u.rowid IN (" . $db->sanitize(implode(',', $allowedEmployees)) . ")";
}
if (!empty($search_name)) {
	$usersqlbase .= natural_search(array('u.firstname', 'u.lastname', 'u.login'), $search_name);
}

$nbtotalofrecords = 0;
$sqlcount = "SELECT COUNT(*) as cnt" . $usersqlbase;
$resCount = $db->query($sqlcount);
if ($resCount) {
	$objCount = $db->fetch_object($resCount);
	$nbtotalofrecords = $objCount ? (int) $objCount->cnt : 0;
	$db->free($resCount);
}

$usersql = "SELECT u.rowid, u.lastname, u.firstname, u.login, u.statut, u.employee, u.fk_user" . $usersqlbase;
$usersql .= $db->order($sortfield, $sortorder);
$usersql .= $db->plimit($limit + 1, $offset);
$resUsers = $db->query($usersql);
$employees = array();
$num = 0;
if ($resUsers) {
	$num = $db->num_rows($resUsers);
	$idx = 0;
	while ($u = $db->fetch_object($resUsers)) {
		if ($idx < $limit) {
			$employees[] = $u;
		}
		$idx++;
	}
	$db->free($resUsers);
}

$title = $langs->trans('MenuConfCP');
llxHeader('', $title, '', '', 0, 0, '', '', '', 'mod-holiday page-define_holiday');

print '<form method="POST" id="searchFormList" action="' . $_SERVER["PHP_SELF"] . '">';
if ($optioncss != '') print '<input type="hidden" name="optioncss" value="' . dol_escape_htmltag($optioncss) . '">';
print '<input type="hidden" name="token" value="' . $token . '">';
print '<input type="hidden" name="action" value="update">';
print '<input type="hidden" name="sortfield" value="' . dol_escape_htmltag($sortfield) . '">';
print '<input type="hidden" name="sortorder" value="' . dol_escape_htmltag($sortorder) . '">';
print '<input type="hidden" name="page" value="' . (int) $page . '">';
print '<input type="hidden" name="contextpage" value="' . dol_escape_htmltag($contextpage) . '">';
if ($editUserId) print '<input type="hidden" name="edit_quota_user" value="' . (int) $editUserId . '">';

// ----- List header -----
$param = '&year=' . urlencode($yearFilter);
if ($search_name) $param .= '&search_name=' . urlencode($search_name);
if ($statusFilter !== 2) $param .= '&status_filter=' . urlencode($statusFilter);
if ($limit && $limit != $conf->liste_limit) $param .= '&limit=' . urlencode($limit);
if (empty($nbtotalofrecords)) $nbtotalofrecords = $num;

print_barre_liste(
	$title,
	$page,
	$_SERVER["PHP_SELF"],
	$param,
	$sortfield,
	$sortorder,
	'',
	$num,
	$nbtotalofrecords,
	'title_hrm',
	0,
	'',
	'',
	$limit
);

// Remove spinners on number inputs styled with no-spin
print '<style>input.no-spin[type=number]::-webkit-inner-spin-button,input.no-spin[type=number]::-webkit-outer-spin-button{-webkit-appearance:none;margin:0;}input.no-spin[type=number]{-moz-appearance:textfield;}</style>';

// Year selector outside table with extra spacing
print '<div class="paddingtop5 paddingbottom10" style="margin-bottom: 10px;">';
print $langs->trans("Year") . ' ';
print '<input type="number" name="year" value="' . (int) $yearFilter . '" class="maxwidth75 no-spin" min="2000" max="2100" inputmode="numeric" pattern="[0-9]*" onwheel="return false;">';
print ' <button type="submit" name="year_prev" value="1" class="button smallpaddingimp" title="' . dol_escape_htmltag($langs->trans("Previous")) . '">&lt;</button>';
print ' <button type="submit" name="year_next" value="1" class="button smallpaddingimp" title="' . dol_escape_htmltag($langs->trans("Next")) . '">&gt;</button>';
print '</div>';

// ----- Table -----
print '<div class="div-table-responsive">';
print '<table class="tagtable liste" id="tablelines3">';

// Filter row
print '<tr class="liste_titre_filter">';

// Employee filter with year selector inline
print '<td class="liste_titre">';
print '<div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;">';
print '<input type="text" name="search_name" value="' . dol_escape_htmltag($search_name) . '" class="maxwidth100" placeholder="' . dol_escape_htmltag($langs->trans("Search")) . '">';
print '<select name="status_filter" class="flat">';
print '<option value="2"' . ($statusFilter === 2 ? ' selected' : '') . '>' . dol_escape_htmltag($langs->trans("All")) . '</option>';
print '<option value="1"' . ($statusFilter === 1 ? ' selected' : '') . '>' . dol_escape_htmltag($langs->trans("Active")) . '</option>';
print '<option value="0"' . ($statusFilter === 0 ? ' selected' : '') . '>' . dol_escape_htmltag($langs->trans("Inactive")) . '</option>';
print '</select>';
print '</div>';
print '</td>';

// One empty filter cell per type (3 cols per type)
foreach ($vacTypes as $key => $val) {
	print '<td class="liste_titre center"></td><td class="liste_titre center"></td><td class="liste_titre center"></td>';
}

// Note filter
print '<td class="liste_titre">&nbsp;</td>';

// Filter buttons
print '<td class="liste_titre center">';
print $form->showFilterButtons();
print '</td>';

print '</tr>';

// Titles row
print '<tr class="liste_titre">';

// Employee column
print_liste_field_titre(
	$langs->trans("Employee"),
	$_SERVER["PHP_SELF"],
	"u.firstname",
	"",
	$param,
	'',
	$sortfield,
	$sortorder
);

// One column per vacation type (total / used / remaining)
foreach ($vacTypes as $val) {
	$labeltype = ($langs->trans($val['code']) != $val['code']) ? $langs->trans($val['code']) : $val['label'];
	print_liste_field_titre($labeltype . ' (' . $langs->trans("Total") . ')', $_SERVER["PHP_SELF"], '', $param, '', 'align="center"');
	print_liste_field_titre($langs->trans("Used"), $_SERVER["PHP_SELF"], '', $param, '', 'align="center"');
	print_liste_field_titre($langs->trans("Remaining"), $_SERVER["PHP_SELF"], '', $param, '', 'align="center"');
}

// Note
print_liste_field_titre($langs->trans("Note"), $_SERVER["PHP_SELF"], '', $param);

// Action column
print_liste_field_titre('', $_SERVER["PHP_SELF"], '', $param, '', 'align="center"');

print '</tr>';

// ----- Lines -----
$userstatic = new User($db);
$shown = 0;

foreach ($employees as $emp) {
	$shown++;
	$userstatic->id = $emp->rowid;
	$userstatic->lastname = $emp->lastname;
	$userstatic->firstname = $emp->firstname;
	$userstatic->status = $emp->statut;
	$userstatic->employee = $emp->employee;

	$isEditingRow = $canEdit && $editUserId === (int) $emp->rowid;

	print '<tr class="oddeven">';

	// Employee
	print '<td>';
	print $userstatic->getNomUrl(-1, '', 0, 0, 0); // no truncation
	print '</td>';

	// Per vacation type
	foreach ($vacTypes as $typeId => $rowType) {
		$current = isset($quotaMap[$emp->rowid][$typeId]) ? $quotaMap[$emp->rowid][$typeId] : array('days' => '', 'note' => '');
		$hasCurrent = ($current['days'] !== '' && $current['days'] !== null);

		if ($hasCurrent) {
			$totalDays = price2num($current['days'], 5);
		} else {
			$prevTotal = isset($prevQuotaMap[$emp->rowid][$typeId]) ? price2num($prevQuotaMap[$emp->rowid][$typeId], 5) : $defaultVacationDays;
			$usedPrev = isset($prevUsageMap[$emp->rowid][$typeId]) ? (float) $prevUsageMap[$emp->rowid][$typeId] : 0.0;
			$carry = min(max($prevTotal - $usedPrev, 0), $carryoverCapDays);
			$totalDays = $defaultVacationDays + $carry;
		}

		$usedDays = isset($usageMap[$emp->rowid][$typeId]) ? (float) $usageMap[$emp->rowid][$typeId] : 0.0;
		$remainingDays = max(0, round($totalDays - $usedDays, 2));
		$val = ($totalDays !== '' ? price2num($totalDays, 5) : '');

		print '<td class="center">';
		if ($isEditingRow && $canEdit) {
			print '<input type="text" class="maxwidth50 center" name="nb_holiday_' . (int) $typeId . '[' . (int) $emp->rowid . ']" value="' . dol_escape_htmltag($val) . '">';
		} else {
			print dol_escape_htmltag($val);
		}
		print '</td>';

		print '<td class="center">';
		print price(round($usedDays, 2), 2, '', 1);
		print '</td>';

		print '<td class="center">';
		print price($remainingDays, 2, '', 1);
		print '</td>';
	}

	// Note (one per employee)
	$noteVal = '';
	if (!empty($quotaMap[$emp->rowid])) {
		foreach ($quotaMap[$emp->rowid] as $entry) {
			if (!empty($entry['note'])) {
				$noteVal = $entry['note'];
				break;
			}
		}
	}

	print '<td>';
	if ($isEditingRow && $canEdit) {
		print '<input type="text" class="maxwidthonsmartphone" name="note_holiday[' . (int) $emp->rowid . ']" value="' . dol_escape_htmltag($noteVal) . '" size="30">';
	} else {
		print dol_escape_htmltag($noteVal);
	}
	print '</td>';

	// Action cell
	print '<td class="center">';
	if ($canEdit) {
		if ($isEditingRow) {
			print '<input type="submit" class="smallpaddingimp nomargingtop nomarginbottom button" name="update_quota[' . (int) $emp->rowid . ']" value="' . dol_escape_htmltag($langs->trans("Save")) . '">';
			print ' <input type="submit" class="smallpaddingimp nomargingtop nomarginbottom button button-cancel" name="cancel" value="' . dol_escape_htmltag($langs->trans("Cancel")) . '">';
		} else {
			$editParams = array(
				'year' => $yearFilter,
				'search_name' => $search_name,
				'status_filter' => $statusFilter,
				'limit' => $limit,
				'sortfield' => $sortfield,
				'sortorder' => $sortorder,
				'page' => $page,
				'edit_quota_user' => (int) $emp->rowid,
			);
			$editQuery = http_build_query(array_filter($editParams, function ($v) {
				return $v !== '' && $v !== null;
			}));
			print '<a class="reposition" href="' . $_SERVER["PHP_SELF"] . ($editQuery ? '?' . dol_escape_htmltag($editQuery) : '') . '" title="' . dol_escape_htmltag($langs->trans("Edit")) . '">';
			print img_edit('', 1);
			print '</a>';
		}
	}
	print '</td>';

	print '</tr>';
}

if ($shown === 0) {
	$cols = 1 + (count($vacTypes) * 3) + 2; // Employee + (total/used/remain per type) + note + action
	print '<tr><td colspan="' . (int) $cols . '"><span class="opacitymedium">' . $langs->trans("NoRecordFound") . '</span></td></tr>';
}

print '</table>';
print '</div>';

// --- Bulk edit section ---
if ($canEdit && !empty($vacTypes) && !empty($allowedEmployees)) {
	$bulkYearVal = $yearFilter;

	print '<div class="center paddingtop8">';
	print '<a href="#" id="openBulkModal" class="butAction">' . dol_escape_htmltag($langs->trans("BulkEdit")) . '</a>';
	print '</div>';

	print '<div id="bulkModal" style="display:none; position:fixed; top:0; left:0; right:0; bottom:0; background:rgba(0,0,0,0.35); z-index:1000; align-items:center; justify-content:center;">';
	print '<div style="background:#fff; padding:20px; border-radius:4px; min-width:320px; max-width:480px; margin:0 auto;">';
	print '<h3 style="margin-top:0;">' . dol_escape_htmltag($langs->trans("BulkEdit")) . '</h3>';

	print '<div class="form-group">';
	print '<label>' . $langs->trans("Year") . '</label> ';
	print '<span>' . (int) $bulkYearVal . '</span>';
	print '<input type="hidden" name="bulk_year" value="' . (int) $bulkYearVal . '">';
	print '</div>';

	foreach ($vacTypes as $typeId => $rowType) {
		$labeltype = ($langs->trans($rowType['code']) != $rowType['code']) ? $langs->trans($rowType['code']) : $rowType['label'];
		print '<div class="form-group" style="margin-top:8px;">';
		print '<label>' . dol_escape_htmltag($labeltype) . '</label> ';
		print '<input type="text" class="width100" name="bulk_value[' . (int) $typeId . ']" value="">';
		print '</div>';
	}

	print '<div class="center" style="margin-top:12px;">';
	print '<input type="submit" class="smallpaddingimp nomargingtop nomarginbottom button" name="bulk_apply" value="' . dol_escape_htmltag($langs->trans("Save")) . '">';
	print ' <input type="button" id="closeBulkModal" class="smallpaddingimp nomargingtop nomarginbottom button button-cancel" value="' . dol_escape_htmltag($langs->trans("Cancel")) . '">';
	print '</div>';

	print '</div>';
	print '</div>';

	print '<script>
(function() {
	var modal = document.getElementById("bulkModal");
	var openBtn = document.getElementById("openBulkModal");
	var closeBtn = document.getElementById("closeBulkModal");
	if (openBtn && modal) {
		openBtn.addEventListener("click", function(e) {
			e.preventDefault();
			modal.style.display = "flex";
		});
	}
	if (closeBtn && modal) {
		closeBtn.addEventListener("click", function(e) {
			e.preventDefault();
			modal.style.display = "none";
		});
	}
})();
</script>';
}

print '</form>';

llxFooter();
$db->close();

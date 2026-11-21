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

// Exercise the real PDF cell loop and rendering functions without a database or PDF engine.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}
$sourcePath = getenv('DZS_PRINT_SOURCE') ?: __DIR__ . '/../schedule/schedule_print.php';
$source = file_get_contents($sourcePath);
eval(substr($source, strpos($source, 'function shiftplannerExtractWeekStart(')));
function dol_escape_htmltag($value) { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
function dol_stringtotime($value) { return strtotime($value); }
$langs = new class {
    public function transnoentities($key) { return $key; }
};
$employeeLoop = strpos($source, 'foreach ($employees as $emp)');
$cellLoopStart = strpos($source, "\tforeach (\$days as \$day)", $employeeLoop);
$cellLoopEnd = strpos($source, "\t\$table .= '</tr>';", $cellLoopStart);
$cellLoop = substr($source, $cellLoopStart, $cellLoopEnd - $cellLoopStart);
function renderFixture(array $leaves, array $shifts = [], $dayOff = null)
{
    global $cellLoop, $langs;
    $empId = 99;
    $emp = [];
    $days = [['date' => '2026-08-22']];
    $leaveByDate = [$empId => ['2026-08-22' => $leaves]];
    $shiftsByEmployeeDay = [$empId => ['2026-08-22' => $shifts]];
    $dayoffMap = [$empId => ['2026-08-22' => $dayOff]];
    $holidayMap = [];
    $blockedBg = $holidayBg = '#e0e0e0';
    $dayColWidth = '12.57%';
    $table = '';
    eval($cellLoop);
    return $table;
}
function checkPrint($condition, $message)
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
    echo 'PASS: ' . $message . PHP_EOL;
}
$morning = ['code' => 'Falta_Injus', 'is_hours' => 1, 'hours_range' => '12:00-16:00'];
$evening = ['code' => 'Falta_Injus', 'is_hours' => 1, 'hours_range' => '19:00-23:00'];
$html = renderFixture([$morning, $evening]);
checkPrint(substr_count($html, 'Falta_Injus') === 2 && strpos($html, '12:00 - 16:00') < strpos($html, '19:00 - 23:00'), 'Both Simmon Saturday intervals print in order');
$shifts = [['start' => '2026-08-22 08:00:00', 'end' => '2026-08-22 11:00:00', 'task_label' => 'Kitchen & service']];
$html = renderFixture([$morning, $evening], $shifts);
checkPrint(substr_count($html, 'Falta_Injus') === 2 && strpos($html, '08:00-11:00 (Kitchen &amp; service) (3h)') !== false, 'Partial absences coexist with worked shifts and retain worked hours');
$full = ['code' => 'Ferias', 'description' => 'Holiday <note>'];
$html = renderFixture([$full]);
checkPrint(strpos($html, '<strong>Ferias</strong>') !== false && strpos($html, 'background-color:#e0e0e0') !== false && strpos($html, '&lt;note&gt;') !== false, 'Full-day formatting and escaping remain intact');
$html = renderFixture([$morning, $full, $evening], $shifts);
checkPrint(substr_count($html, 'Falta_Injus') === 2 && strpos($html, 'Ferias') !== false && strpos($html, '08:00-11:00') === false, 'Full-day leave retains all absence evidence and suppresses worked shifts');
$html = renderFixture([$morning, $evening], [], ['is_recurring' => 1]);
checkPrint(strpos($html, 'ShiftPlannerDayOffLabel') !== false && strpos($html, 'Falta_Injus') === false, 'Day-off precedence remains intact');
checkPrint(strpos(renderFixture([]), '—') !== false, 'Empty cells retain their placeholder');
$html = renderFixture([['minutes_only' => 1, 'hours_total_minutes' => 30, 'code' => 'Leave'], ['halfday' => 1, 'code' => 'Leave']]);
checkPrint(strpos($html, '00:30') !== false && strpos($html, 'ShiftPlannerLeaveHalfMorningLabel') !== false, 'Minutes-only and half-day absences both print');

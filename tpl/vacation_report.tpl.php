<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 */
$escape = function ($text) { return dol_escape_htmltag((string) $text); };
$number = function ($value) { return $value === null ? '—' : KreaFlowVacationReport::number($value); };
$shortDays = array('ShortSunday', 'ShortMonday', 'ShortTuesday', 'ShortWednesday', 'ShortThursday', 'ShortFriday', 'ShortSaturday');
$summaryFields = array('scheduled' => 'VacationMapScheduled', 'entitlement' => 'VacationMapEntitlement',
	'unplanned' => 'VacationMapUnplanned', 'elapsed' => 'VacationMapElapsed', 'remaining' => 'VacationMapRemaining');
?>
<p class="opacitymedium kf-vacation-note"><?php echo $escape($langs->trans('VacationMapMethod', $today)); ?></p>
<?php if (!empty($report['invalid_records'])): ?><div class="warning"><?php echo $escape($langs->trans('VacationMapInvalidRecords', $report['invalid_records'])); ?></div><?php endif; ?>
<?php if (empty($report['has_vacation_type'])): ?>
<div class="warning"><?php echo $escape($langs->trans('VacationMapNoType')); ?></div>
<?php endif; ?>
<?php if (!$report['employees']): ?>
<div class="info"><?php echo $escape($langs->trans('NoDataFound')); ?></div>
<?php else: ?>
<nav class="kf-vacation-jump" aria-label="<?php echo $escape($langs->trans('VacationMapNavigation')); ?>">
<a href="#vacation-summary"><?php echo $escape($langs->trans('VacationMapSummary')); ?></a>
<?php foreach ($months as $month => $monthName): if ($selectedMonth && $month !== $selectedMonth) continue; ?>
<a href="#vacation-month-<?php echo $month; ?>"><?php echo $escape($monthName); ?></a>
<?php endforeach; ?>
</nav>
<section class="kf-vacation-section" id="vacation-summary">
<h2><?php echo $escape($langs->trans('VacationMapSummary')); ?> · <?php echo (int) $selectedYear; ?></h2>
<p class="kf-vacation-print-company"><?php echo $escape($companyName); ?></p>
<table class="liste kf-vacation-grid kf-vacation-summary">
<caption class="sr-only"><?php echo $escape($langs->trans('VacationMapSummary')); ?></caption>
<thead><tr class="liste_titre"><th scope="col"><?php echo $escape($langs->trans('VacationMapEmployee')); ?></th>
<?php foreach ($months as $month => $label): ?><th scope="col" title="<?php echo $escape($label); ?>"><?php echo $escape($langs->trans('MonthShort' . sprintf('%02d', $month))); ?></th><?php endforeach; ?>
<?php foreach ($summaryFields as $label): ?><th scope="col"><?php echo $escape($langs->trans($label)); ?></th><?php endforeach; ?>
</tr></thead><tbody>
<?php foreach ($report['employees'] as $employee): ?>
<tr class="oddeven"><th scope="row"><?php echo $escape($employee['name']); ?></th>
<?php foreach ($employee['months'] as $value): ?><td><?php echo $number($value); ?></td><?php endforeach; ?>
<?php foreach ($summaryFields as $field => $label): ?><td><?php echo $number($employee[$field]); ?></td><?php endforeach; ?>
</tr><?php endforeach; ?>
</tbody></table>
<div class="kf-vacation-mobile">
<?php foreach ($report['employees'] as $employee): ?>
<article class="kf-vacation-card"><h3><?php echo $escape($employee['name']); ?></h3>
<dl class="kf-vacation-month-totals">
<?php foreach ($employee['months'] as $month => $value): ?><div><dt><?php echo $escape($months[$month]); ?></dt><dd><?php echo $number($value); ?></dd></div><?php endforeach; ?>
</dl><dl class="kf-vacation-balances">
<?php foreach ($summaryFields as $field => $label): ?><div><dt><?php echo $escape($langs->trans($label)); ?></dt><dd><?php echo $number($employee[$field]); ?></dd></div><?php endforeach; ?>
</dl></article><?php endforeach; ?>
</div></section>
<p class="kf-vacation-legend"><span class="kf-vacation-swatch"></span> <?php echo $escape($langs->trans('VacationMapScheduled')); ?> <span class="kf-vacation-swatch kf-vacation-weekend"></span> <?php echo $escape($langs->trans('VacationMapWeekend')); ?> <span class="kf-vacation-swatch kf-vacation-holiday"></span> <?php echo $escape($langs->trans('VacationMapPublicHoliday')); ?></p>
<?php foreach ($months as $month => $monthName): if ($selectedMonth && $month !== $selectedMonth) continue;
	$first = new DateTimeImmutable(sprintf('%04d-%02d-01', $selectedYear, $month), new DateTimeZone('Europe/Lisbon'));
	$daysInMonth = (int) $first->format('t');
?>
<section class="kf-vacation-section" id="vacation-month-<?php echo $month; ?>">
<h2><?php echo $escape($monthName); ?> · <?php echo (int) $selectedYear; ?></h2>
<p class="kf-vacation-print-company"><?php echo $escape($companyName); ?></p>
<table class="liste kf-vacation-grid kf-vacation-calendar">
<caption class="sr-only"><?php echo $escape($monthName . ' ' . $selectedYear); ?></caption>
<thead><tr class="liste_titre"><th scope="col"><?php echo $escape($langs->trans('VacationMapEmployee')); ?></th><th scope="col"><?php echo $escape($langs->trans('VacationMapEmployeeNumber')); ?></th>
<?php for ($day = 1; $day <= $daysInMonth; $day++): $date = $first->modify('+' . ($day - 1) . ' days'); ?>
<th scope="col" class="<?php echo isset($report['holidays'][$date->format('Y-m-d')]) ? 'kf-vacation-holiday' : ((int) $date->format('N') >= 6 ? 'kf-vacation-weekend' : ''); ?>"><span><?php echo $day; ?></span><small><?php echo $escape($langs->trans($shortDays[(int) $date->format('w')])); ?></small></th>
<?php endfor; ?><th scope="col"><?php echo $escape($langs->trans('Total')); ?></th></tr></thead><tbody>
<?php foreach ($report['employees'] as $employee): ?>
<tr class="oddeven"><th scope="row"><?php echo $escape($employee['name']); ?></th><td><?php echo $escape($employee['number']); ?></td>
<?php for ($day = 1; $day <= $daysInMonth; $day++): $iso = sprintf('%04d-%02d-%02d', $selectedYear, $month, $day); $value = $employee['days'][$iso] ?? 0; ?>
<td class="<?php echo $value ? 'kf-vacation-mark' : ((int) $first->modify('+' . ($day - 1) . ' days')->format('N') >= 6 ? 'kf-vacation-weekend' : ''); ?>"><?php echo $value ? $number($value) : ''; ?></td>
<?php endfor; ?><td class="kf-vacation-total"><?php echo $number($employee['months'][$month]); ?></td></tr>
<?php endforeach; ?></tbody></table>
<div class="kf-vacation-mobile">
<?php foreach ($report['employees'] as $employee): ?>
<article class="kf-vacation-card"><h3><?php echo $escape($employee['name']); ?></h3>
<p><?php echo $escape($langs->trans('VacationMapEmployeeNumber')); ?>: <?php echo $escape($employee['number'] ?: '—'); ?> · <?php echo $escape($langs->trans('Total')); ?>: <strong><?php echo $number($employee['months'][$month]); ?></strong></p>
<div class="kf-vacation-phone-calendar" role="list" aria-label="<?php echo $escape($monthName); ?>">
<?php for ($dow = 1; $dow <= 7; $dow++): ?><span class="kf-vacation-weekday"><?php echo $escape($langs->trans($shortDays[$dow % 7])); ?></span><?php endfor; ?>
<?php for ($blank = 1; $blank < (int) $first->format('N'); $blank++): ?><span aria-hidden="true"></span><?php endfor; ?>
<?php for ($day = 1; $day <= $daysInMonth; $day++): $iso = sprintf('%04d-%02d-%02d', $selectedYear, $month, $day); $value = $employee['days'][$iso] ?? 0; ?>
<span role="listitem" class="<?php echo $value ? 'kf-vacation-mark' : ''; ?>" aria-label="<?php echo $escape($iso . ': ' . $number($value)); ?>"><?php echo $day; ?><?php if ($value): ?><small><?php echo $number($value); ?></small><?php endif; ?></span>
<?php endfor; ?></div></article>
<?php endforeach; ?></div></section>
<?php endforeach; endif; ?>

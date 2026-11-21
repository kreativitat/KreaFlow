<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;

/** Use Dolibarr's bundled Excel writer; never persist employee exports on the server. */
function kreaflow_vacation_report_workbook(array $report, $selectedMonth, $companyName, array $months, $langs)
{
	require_once DOL_DOCUMENT_ROOT . '/includes/phpoffice/phpspreadsheet/src/autoloader.php';
	require_once DOL_DOCUMENT_ROOT . '/includes/Psr/autoloader.php';
	$book = new Spreadsheet();
	$book->getProperties()->setCreator('Kreativität Works')->setTitle($langs->trans('VacationMapTitle', $report['year']));
	$summary = $book->getActiveSheet();
	$summary->setTitle($langs->trans('VacationMapSummary'));
	$fields = array('scheduled' => 'VacationMapScheduled', 'entitlement' => 'VacationMapEntitlement',
		'unplanned' => 'VacationMapUnplanned', 'elapsed' => 'VacationMapElapsed', 'remaining' => 'VacationMapRemaining');
	$headers = array_merge(array($langs->trans('VacationMapEmployee'), $langs->trans('VacationMapEmployeeNumber')), array_values($months));
	foreach ($fields as $label) $headers[] = $langs->trans($label);
	$writeText = function ($sheet, $cell, $text) { $sheet->setCellValueExplicit($cell, (string) $text, DataType::TYPE_STRING); };
	$prepare = function ($sheet, $title, $headers) use ($writeText, $companyName, $report, $langs) {
		$last = Coordinate::stringFromColumnIndex(count($headers));
		$writeText($sheet, 'A1', $title);
		$writeText($sheet, 'A2', $companyName);
		$method = $langs->trans('VacationMapMethod', $report['today']);
		if (!empty($report['invalid_records'])) $method .= ' ' . $langs->trans('VacationMapInvalidRecords', $report['invalid_records']);
		$writeText($sheet, 'A3', $method);
		$sheet->mergeCells('A3:' . $last . '3');
		$sheet->getStyle('A3')->getAlignment()->setWrapText(true);
		$sheet->getRowDimension(3)->setRowHeight(45);
		foreach ($headers as $i => $label) $writeText($sheet, Coordinate::stringFromColumnIndex($i + 1) . '5', $label);
		$sheet->getStyle('A5:' . $last . '5')->getFill()->setFillType('solid')->getStartColor()->setRGB('27634C');
		$sheet->getStyle('A5:' . $last . '5')->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
		$sheet->getStyle('A5:' . $last . '5')->getAlignment()->setWrapText(true);
		$sheet->getRowDimension(5)->setRowHeight(32);
		$sheet->getStyle('A1')->getFont()->setBold(true)->setSize(16);
		$sheet->getColumnDimension('A')->setWidth(38);
		$sheet->getColumnDimension('B')->setWidth(12);
		$sheet->freezePane('C6');
		$sheet->setShowGridlines(false);
		$sheet->getPageSetup()->setOrientation('landscape')->setPaperSize(9)->setFitToWidth(1)->setFitToHeight(0);
		$sheet->getPageSetup()->setRowsToRepeatAtTopByStartAndEnd(1, 5);
	};
	$prepare($summary, $langs->trans('VacationMapTitle', $report['year']), $headers);
	foreach (array_values($report['employees']) as $index => $employee) {
		$row = $index + 6;
		$writeText($summary, 'A' . $row, $employee['name']);
		$writeText($summary, 'B' . $row, $employee['number']);
		$values = array_values($employee['months']);
		foreach ($fields as $field => $label) $values[] = $employee[$field];
		foreach ($values as $i => $value) if ($value !== null) $summary->setCellValue(Coordinate::stringFromColumnIndex($i + 3) . $row, $value);
	}
	$lastRow = max(6, count($report['employees']) + 5);
	$summary->getStyle('C6:S' . $lastRow)->getNumberFormat()->setFormatCode('0.####');
	foreach (range(3, 19) as $col) $summary->getColumnDimension(Coordinate::stringFromColumnIndex($col))->setWidth($col < 15 ? 11 : 18);
	foreach ($months as $month => $label) {
		if ($selectedMonth && $selectedMonth !== $month) continue;
		$sheet = $book->createSheet();
		$sheet->setTitle($label);
		$first = new DateTimeImmutable(sprintf('%04d-%02d-01', $report['year'], $month));
		$count = (int) $first->format('t');
		$headers = array($langs->trans('VacationMapEmployee'), $langs->trans('VacationMapEmployeeNumber'));
		for ($day = 1; $day <= $count; $day++) $headers[] = $day . "\n" . $langs->trans(array('ShortSunday','ShortMonday','ShortTuesday','ShortWednesday','ShortThursday','ShortFriday','ShortSaturday')[(int) $first->modify('+' . ($day - 1) . ' days')->format('w')]);
		$headers[] = $langs->trans('Total');
		$prepare($sheet, $label . ' ' . $report['year'], $headers);
		for ($day = 1; $day <= $count; $day++) {
			$column = Coordinate::stringFromColumnIndex($day + 2);
			$sheet->getColumnDimension($column)->setWidth(5);
			if ((int) $first->modify('+' . ($day - 1) . ' days')->format('N') >= 6) {
				$sheet->getStyle($column . '6:' . $column . $lastRow)->getFill()->setFillType('solid')->getStartColor()->setRGB('EEF0F2');
			}
		}
		$totalColumn = Coordinate::stringFromColumnIndex($count + 3);
		$sheet->getColumnDimension($totalColumn)->setWidth(10);
		foreach (array_values($report['employees']) as $index => $employee) {
			$row = $index + 6;
			$writeText($sheet, 'A' . $row, $employee['name']);
			$writeText($sheet, 'B' . $row, $employee['number']);
			for ($day = 1; $day <= $count; $day++) {
				$iso = sprintf('%04d-%02d-%02d', $report['year'], $month, $day);
				if (empty($employee['days'][$iso])) continue;
				$cell = Coordinate::stringFromColumnIndex($day + 2) . $row;
				$sheet->setCellValue($cell, $employee['days'][$iso]);
				$sheet->getStyle($cell)->getFill()->setFillType('solid')->getStartColor()->setRGB('D3EEE0');
			}
			$sheet->setCellValue($totalColumn . $row, $employee['months'][$month]);
		}
		$sheet->getStyle('C6:' . $totalColumn . $lastRow)->getNumberFormat()->setFormatCode('0.####');
	}
	$book->setActiveSheetIndex(0);
	return $book;
}

function kreaflow_vacation_report_xlsx(array $report, $selectedMonth, $companyName, array $months, $langs)
{
	$book = kreaflow_vacation_report_workbook($report, $selectedMonth, $companyName, $months, $langs);
	header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
	header('Content-Disposition: attachment; filename="vacations_' . (int) $report['year'] . '.xlsx"');
	header('Cache-Control: private, no-store');
	$writer = new Xlsx($book);
	$writer->save('php://output');
}

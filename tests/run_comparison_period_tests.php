<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License,
 * or (at your option) any later version.
 */
define('DOL_DOCUMENT_ROOT', dirname(__DIR__, 3));
require_once __DIR__.'/../class/KreaFlowMobileApi.class.php';
date_default_timezone_set('Europe/Lisbon');
$cases = array(
 array('2026-09-01','2026-09-30','2025-09-01','2025-09-30','calendar'),
 array('2026-01-01','2026-01-31','2025-01-01','2025-01-31','calendar'),
 array('2026-02-01','2026-02-28','2025-02-01','2025-02-28','calendar'),
 array('2024-02-01','2024-02-29','2023-02-01','2023-02-28','calendar'),
 array('2025-02-01','2025-02-28','2024-02-01','2024-02-29','calendar'),
 array('2026-09-01','2026-09-28','2025-09-02','2025-09-29','operational'),
 array('2024-02-01','2024-02-28','2023-02-02','2023-03-01','operational'),
 array('2026-09-20','2026-09-20','2025-09-21','2025-09-21','operational'),
 array('2026-09-18','2026-09-20','2025-09-19','2025-09-21','operational'),
 array('2026-08-17','2026-09-20','2025-08-18','2025-09-21','operational'),
 array('2026-08-16','2026-09-20','2025-08-16','2025-09-20','calendar'),
 array('2024-02-29','2024-04-04','2023-02-28','2023-04-04','calendar'),
 array('2026-03-01','2026-04-04','2025-03-02','2025-04-05','operational'),
 array('2026-01-01','2026-12-31','2025-01-01','2025-12-31','calendar'),
 array('2026-01-01','2026-01-07','2025-01-02','2025-01-08','operational')
);
foreach ($cases as $case) {
 $actual = KreaFlowMobileApi::getComparisonPeriod($case[0], $case[1]);
 if ($actual !== array('date_start'=>$case[2], 'date_end'=>$case[3], 'comparison_mode'=>$case[4])) {
  throw new RuntimeException('Unexpected comparison for '.json_encode($case));
 }
 if ($case[4] === 'operational') {
  foreach (array(0=>2,1=>3) as $current=>$previous) {
   if (date('N',strtotime($case[$current])) !== date('N',strtotime($case[$previous]))) throw new RuntimeException('Weekday mismatch');
  }
 }
}
echo "PASS: complete months (28/29/30/31 days), partial months, one day, weekend, 35/36-day boundary, leap day, DST, year and year boundary\n";

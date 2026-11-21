<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com> */
define('DOL_DOCUMENT_ROOT', getenv('DOLIBARR_TEST_ROOT') ?: dirname(__DIR__, 3));
require_once __DIR__ . '/../class/KreaFlowVacationReport.class.php';
function checkVacation($ok, $message) { if (!$ok) throw new RuntimeException($message); }
$employee = (object) array('rowid'=>823, 'firstname'=>'Synthetic', 'lastname'=>'Employee', 'ref_employee'=>'0012');
$make = function ($start, $end, $mode = 0, $minutes = 0, $cutoff = 3) {
 return (object) array('fk_user'=>823,'date_start'=>$start,'date_end'=>$end,'halfday'=>$mode,'minutes_total'=>$minutes,'day_change_hour'=>$cutoff);
};
$project = function ($records, $year = 2026, $quotas = array()) use ($employee) {
 return KreaFlowVacationReport::project($year, array($employee), $records, $quotas, '2026-11-16');
};
foreach (array(array('2026-03-28 03:00:00','2026-03-31 03:00:00'), array('2026-10-24 03:00:00','2026-10-27 03:00:00')) as $range) {
 $r=$project(array($make($range[0],$range[1]))); checkVacation($r['employees'][823]['scheduled'] === 3, 'DST must preserve three calendar days');
}
$r=$project(array($make('2025-12-30 03:00:00','2026-01-03 03:00:00')));
checkVacation($r['employees'][823]['scheduled'] === 2, 'Clip at working-year boundary');
$r=$project(array($make('2024-02-28 03:00:00','2024-03-01 03:00:00')),2024);
checkVacation($r['employees'][823]['scheduled'] === 2, 'Leap day retained');
$r=$project(array($make('2026-01-01 02:00:00','2026-01-01 06:00:00',2)));
checkVacation(abs($r['employees'][823]['scheduled']-.375)<.00001, 'Hourly leave allocated by interval intersection');
foreach (array(array(1,0,.5),array(2,0,.25),array(3,120,.25)) as $partial) {
 $r=$project(array($make('2026-01-01 03:00:00','2026-01-01 05:00:00',$partial[0],$partial[1])));
 checkVacation(abs($r['employees'][823]['scheduled']-$partial[2])<.00001, 'Partial duration');
}
$r=$project(array($make('2026-01-01 00:00:00','2026-01-01 00:00:00'), $make('2026-01-02 00:00:00','2026-01-04 00:00:00')));
checkVacation($r['employees'][823]['scheduled'] === 4, 'Inclusive standalone card dates');
$r=$project(array($make('2026-01-01 00:00:00','2026-01-02 00:00:00',0,0,0)));
checkVacation($r['employees'][823]['scheduled'] === 1, 'Midnight planner half-open contract');
$leave=$make('2026-01-01 03:00:00','2026-01-02 03:00:00');
$r=$project(array($leave,$leave));
checkVacation($r['employees'][823]['scheduled'] === 1, 'Overlap cannot duplicate a calendar absence');
checkVacation($r['employees'][823]['entitlement'] === null, 'Missing quota must remain unavailable');
$r=$project(array($make('invalid','invalid')));
checkVacation($r['invalid_records'] === 1, 'Bad records disclosed');
$conf=(object) array('entity'=>7,'dolizsynch'=>(object)array('enabled'=>1));
$user=(object) array('socid'=>0,'rights'=>(object)array('dolizsynch'=>(object)array('shift'=>(object)array('schedule_vacations_view'=>1))));
checkVacation(KreaFlowVacationReport::canRead($conf,$user),'Authorized internal reader');
$user->socid=12; checkVacation(!KreaFlowVacationReport::canRead($conf,$user),'External denied');
$user->socid=0; $user->rights->dolizsynch->shift->schedule_vacations_view=0;
checkVacation(!KreaFlowVacationReport::canRead($conf,$user),'Permission denied');
class VacationNoQueryDb { public function query($sql) { throw new RuntimeException('QUERIED'); } }
try { KreaFlowVacationReport::load(new VacationNoQueryDb(),$conf,$user,2026,'2026-11-16'); throw new RuntimeException('Access allowed'); }
catch (RuntimeException $e) { checkVacation($e->getMessage()==='Vacation report access denied','Deny before SQL'); }
print "Vacation report regression checks passed\n";

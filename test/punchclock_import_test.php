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

// Run the real import and cron classes with recording API/database boundaries.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}
class CommonObject {
    public $db; public $error = ''; public $errors = array(); public $zoneSoftApi;
    public function fetchCommon($id, $ref, $filter, $extras) {
        foreach ($this->db->pairRows as $row) if ($row->rowid === $id) {
            foreach ((array) $row as $key=>$value) $this->$key=$value;
            $this->id=$id; return 1;
        }
        return 0;
    }
    public function updateCommon($user,$notrigger) {
        foreach ($this->db->pairRows as $row) if ($row->rowid === $this->id) { $row->pairid=$this->pairid; return 1; }
        return -1;
    }
}
class User { public $id = 42; }
class DoliDB
{
    public $queries = array();
    public $rows = array();
    public $saved = array();
    public $failLookup = false;
    public $rollbacks = 0;
    public $commits = 0;
    public $pairRows = null;
    public function begin() { $this->saved = $this->rows; }
    public function commit() { $this->commits++; return 1; }
    public function rollback() { $this->rows = $this->saved; $this->rollbacks++; }
    public function escape($value) { return addslashes((string) $value); }
    public function lasterror() { return 'fixture database failure'; }
    public function query($sql) {
        $this->queries[] = $sql;
        if ($this->failLookup) { return false; }
        if ($this->pairRows !== null) {
            check(strpos($sql,'SELECT * FROM test_dolizsynch_zspunchclock_synch')===0,'Pairing fixture must never synthesize a punch');
            $rows=array_filter($this->pairRows,function($r) use($sql){
                foreach (array('entity','sentido','synch','loja_zs','empid_zs') as $field)
                    if (preg_match('/(?:WHERE|AND) '.$field.' = [\x27]?(\d+)/',$sql,$m) && (string)$r->$field!==$m[1]) return false;
                if (strpos($sql,'AND pairid IS NULL')!==false && $r->pairid!==null) return false;
                if (strpos($sql,'AND (pairid IS NULL OR pairid = 0)')!==false && !empty($r->pairid)) return false;
                if (strpos($sql,'id_zs IS NOT NULL')!==false && $r->id_zs===null) return false;
                preg_match_all('/AND datahora (>=|<=|<|>) [\x27]([^\x27]+)[\x27]/',$sql,$matches,PREG_SET_ORDER);
                foreach ($matches as $m) {
                    if ($m[1]==='>=' && $r->datahora<$m[2] || $m[1]==='<=' && $r->datahora>$m[2]
                        || $m[1]==='>' && $r->datahora<=$m[2] || $m[1]==='<' && $r->datahora>=$m[2]) return false;
                }
                return true;
            });
            usort($rows,function($a,$b) use($sql){$c=strcmp($a->datahora,$b->datahora);return strpos($sql,'ORDER BY datahora DESC')!==false?-$c:$c;});
            if(strpos($sql,'LIMIT 1')!==false)$rows=array_slice($rows,0,1);
            return (object)array('count'=>count($rows),'records'=>array_map(function($r){return clone $r;},$rows),'index'=>0);
        }
        preg_match('/WHERE entity = (\d+)/', $sql, $match);
        // A matching foreign-company punch must never suppress the current-company row.
        preg_match("/empid_zs = '(\\d+)'/", $sql, $employee);
        $count = count(array_filter($this->rows, function ($id) use ($employee) { return $id === (int) ($employee[1] ?? 0); }));
        return (object) array('count' => isset($match[1]) && (int) $match[1] === 5 ? $count : 1);
    }
    public function num_rows($result) { return $result->count; }
    public function fetch_object($result) { return isset($result->records[$result->index??0])?$result->records[$result->index++]:false; }
}
class ZSAPISynch
{
    public static $response;
    public function __construct($db) {}
    public function setInterface($name) {}
    public function execute($action, $payload) {
        if (self::$response instanceof Exception) { throw self::$response; }
        return self::$response;
    }
}
class ZSPunchClock {
    public static $calls = array();
    public static $ranges = array();
    public function __construct($db) {}
    public function isPunchClockEnabled() { return true; }
    public function integratePunchClockData($from=null,$to=null) { self::$calls[] = 'integrate'; self::$ranges[] = array($from,$to); return 0; }
    public function processTimeRegistersToTasks($from=null,$to=null) { self::$calls[] = 'tasks'; self::$ranges[] = array($from,$to); return true; }
}
function dol_syslog($message, $level = 0) {}
function dol_now($mode = '') { return 1234567890; }
function getDolGlobalInt($name) { return 0; }
function isModEnabled($name) { return true; }
define('MAIN_DB_PREFIX', 'test_');
$conf = (object) array('entity' => 5, 'global' => (object) array('ZS_PUNCH_CLOCK_ACTIVATE' => 1, 'ZS_API_STORE' => 9, 'ZS_PUNCH_CLOCK_NUMBER_OF_DAYS' => 2));
$langs = null;
$user = new User();
$source = file_get_contents(dirname(__DIR__) . '/class/zspunchclock_synch.class.php');
eval(substr($source, strpos($source, 'class ZSPunchClock_Synch extends CommonObject')));
require dirname(__DIR__) . '/class/zstimeregisterimport.class.php';
class PairingRangeProbe extends TimeRegisterImport {
    public function range($now,$from=null,$to=null) { return $this->pairingDateRange(new DateTimeImmutable($now),$from,$to); }
}
class RecordingPunchImport extends ZSPunchClock_Synch
{
    public $failInsertAt = 0;
    public $attempts = 0;
    public function importStart($now, $days) { return $this->getTimeRegisterImportStart(new DateTimeImmutable($now), $days); }
    public function create(User $user, $notrigger = false) {
        $this->attempts++;
        if ($this->attempts === $this->failInsertAt) { return -1; }
        $this->db->rows[] = $this->empid_zs;
        return count($this->db->rows);
    }
}
function check($condition, $message) { if (!$condition) { throw new RuntimeException($message); } }
function fixture($response) {
    global $db;
    $db = new DoliDB();
    ZSAPISynch::$response = $response;
    return new RecordingPunchImport($db);
}
function response($records, $status = 200) {
    return array('Response' => array('StatusCode' => $status, 'StatusMessage' => 'OK', 'Content' => array('timeregister' => $records)));
}
$record = array('empid' => 17, 'datahora' => '2026-09-14 09:00:00', 'sentido' => 1, 'dataval' => '2026-09-14', 'id' => 111);
foreach (array(array(), response(array()), response(array(), 204)) as $empty) {
    $import = fixture($empty);
    check($import->storezspunchclock() === true, 'Valid empty response must succeed');
    check(!$db->queries && !$db->rows, 'Empty response must not touch attendance data');
    $cron = new TimeRegisterImport($db);
    check($cron->performStoreZsPunchclock() === 0 && $cron->output !== '', 'Empty import must have native cron success and output');
}
foreach (array(null, false, array('Response' => array('StatusCode' => 200)), response('invalid'), response(array(), 500), new RuntimeException('fixture transport failure')) as $invalid) {
    $import = fixture($invalid);
    check($import->storezspunchclock() === false && $import->error !== '', 'Invalid/failed response must fail');
    $cron = new TimeRegisterImport($db);
    check($cron->performStoreZsPunchclock() === -1 && $cron->error !== '', 'Real failure must reach cron output');
    check(!$db->rows, 'Failed response must not write');
}
$import = fixture(response(array($record)));
check($import->storezspunchclock() === true && count($db->rows) === 1, 'Foreign-company duplicate must not suppress import');
check(strpos($db->queries[0], 'WHERE entity = 5 AND loja_zs') !== false, 'Duplicate check must use current entity');
check($import->storezspunchclock() === true && count($db->rows) === 1, 'Repeated import must remain idempotent');
$import = fixture(response(array($record)));
$db->failLookup = true;
check($import->storezspunchclock() === false && $db->rollbacks === 1 && !$db->commits, 'Lookup error must rollback');
$import = fixture(response(array($record)));
$import->failInsertAt = 1;
check($import->storezspunchclock() === false && $db->rollbacks === 1 && !$db->rows, 'Negative create return must fail and rollback');
$otherRecord = $record;
$otherRecord['empid'] = 18;
$import = fixture(response(array($record, $otherRecord)));
$import->failInsertAt = 2;
check($import->storezspunchclock() === false && $db->rollbacks === 1 && !$db->rows, 'Later insert failure must rollback earlier inserts');
$import = fixture(response(array($record, array('empid' => 18))));
check($import->storezspunchclock() === false && $db->rollbacks === 1 && !$db->rows, 'Malformed later record must rollback the whole batch');

foreach (array('UTC','Europe/Lisbon','Europe/Zurich') as $zone) {
    date_default_timezone_set($zone);
    $import = fixture(array());
    foreach (array('2026-10-26T08:00:00Z'=>'2026-10-24 00:00:00+01:00', '2026-03-30T08:00:00Z'=>'2026-03-28 00:00:00+00:00') as $now=>$expected) {
        check($import->importStart($now, 2)->format('Y-m-d H:i:sP') === $expected, 'Calendar replay start must retain complete DST days in '.$zone);
    }
    $db->queries = array();
    $future = (new DateTimeImmutable('tomorrow', new DateTimeZone('Europe/Lisbon')))->format('Y-m-d');
    check($import->pairTimeRegisters($future, $future) && !$db->queries, 'Open/future operational days must never be synthesized');
}
$import = fixture(array());
$db->failLookup = true;
check($import->pairTimeRegisters('2026-01-01','2026-01-02') === false, 'Pairing SQL failures must not be reported as success');

foreach (array(array(), new RuntimeException('source offline')) as $response) {
    $import = fixture($response);
    ZSPunchClock::$calls = array();
    ZSPunchClock::$ranges = array();
    $cron = new TimeRegisterImport($db);
    $result = $cron->performPairTimeRegisters();
    if ($response instanceof Exception) {
        check($result === -1 && !ZSPunchClock::$calls && !$db->queries, 'Source failure must prevent pairing, synthetic rows and task integration');
    } else {
        check($result === 0 && ZSPunchClock::$calls === array('integrate','tasks'), 'Successful source refresh must allow complete-day integration');
        check(strpos($db->queries[0], 'WHERE entity = 5 AND sentido = 1') !== false
            && strpos($db->queries[0], 'AND datahora >= ') !== false, 'Pairing must remain scoped to the active entity and operational dates');
        check(ZSPunchClock::$ranges[0] === ZSPunchClock::$ranges[1] && ZSPunchClock::$ranges[0][0] !== null, 'The same bounded dates must reach attendance and task integration');
    }
}
$conf->global->ZS_PUNCH_DAY_CHANGE_HOUR = 3;
$import = fixture(new RuntimeException('source offline'));
ZSPunchClock::$calls = array();
$cron = new TimeRegisterImport($db);
check($cron->performPairTimeRegisters('2026-10-25','2026-10-25') === -1 && !ZSPunchClock::$calls && !$db->queries, 'Explicit recovery must stop safely on a source exception');
foreach (array('UTC','Europe/Lisbon','Europe/Zurich') as $zone) {
    date_default_timezone_set($zone);
    $probe = new PairingRangeProbe($db);
    foreach (array(
        '2026-10-26T02:59:59Z'=>array('2026-10-23','2026-10-24'),
        '2026-10-26T03:00:00Z'=>array('2026-10-23','2026-10-24'),
        '2026-10-26T03:00:01Z'=>array('2026-10-24','2026-10-25'),
        '2026-10-25T00:30:00Z'=>array('2026-10-22','2026-10-23'),
        '2026-10-25T01:30:00Z'=>array('2026-10-22','2026-10-23'),
        '2026-03-29T02:00:01Z'=>array('2026-03-27','2026-03-28'),
    ) as $now=>$expected) check($probe->range($now) === $expected, 'Closed-day replay dates must be stable across DST in '.$zone);
    check($probe->range('2026-10-26T12:00:00Z','2026-10-25','2026-10-26') === array('2026-10-25','2026-10-25'), 'Explicit recovery must clamp the open operational day');
    check($probe->range('2026-10-26T12:00:00Z','2026-10-26','2026-10-26') === array(), 'A recovery containing only the open day must be a no-op');
}
$mainSource = file_get_contents(dirname(__DIR__).'/class/zspunchclock.class.php');
$mainSource = substr($mainSource, strpos($mainSource, 'class ZSPunchClock extends CommonObject'));
$mainSource = preg_replace('/class ZSPunchClock extends CommonObject/', 'class ScopedIntegrationProbe extends CommonObject', $mainSource, 1);
eval($mainSource);
$db = new DoliDB();
$probe = new ScopedIntegrationProbe($db);
check($probe->integratePunchClockData() === 0, 'Empty active-entity integration must succeed');
check(strpos($db->queries[0], 'WHERE e1.entity = 5') !== false
    && strpos($db->queries[0], 'e1.entity = e2.entity') !== false
    && strpos($db->queries[0], 'e1.empid_zs = e2.empid_zs') !== false, 'Real integration must restrict the entity and pair ownership');
$db->queries = array();
check($probe->processTimeRegistersToTasks() === true && strpos($db->queries[0], 'WHERE entity = 5 AND synch = 0') !== false, 'Task integration must select only the active company');
$db->queries = array();
check($probe->processTimeRegistersToTasks('2026-10-25','2026-10-25') === true
    && strpos($db->queries[0], "dataval >= '2026-10-25'") !== false
    && strpos($db->queries[0], "dataval <= '2026-10-25'") !== false, 'A date recovery must never process older pending task entries');
$db->queries = array();
check($probe->integratePunchClockData('2026-10-25','2026-10-25') === 0
    && strpos($db->queries[0], "e1.datahora >= '2026-10-25 03:00:01'") !== false
    && strpos($db->queries[0], "e1.datahora <= '2026-10-26 03:00:00'") !== false, 'Attendance recovery must use exact local boundaries rather than POS fiscal dates');
$db->queries = array();
check($probe->processTimeRegistersToTasks('2026-02-30','2026-03-01') === false && !$db->queries, 'Invalid recovery dates must not reach SQL');
class AttendanceDayProbe extends ScopedIntegrationProbe { public function day($time){return $this->attendanceDay($time);} }
foreach (array('Europe/Lisbon','Europe/Zurich','UTC') as $zone) {
    date_default_timezone_set($zone);$probe=new AttendanceDayProbe($db);
    check($probe->day('2026-10-26 03:00:00')==='2026-10-25' && $probe->day('2026-10-26 03:00:01')==='2026-10-26','Attendance date must follow the Lisbon cutoff in '.$zone);
    check($probe->day('2026-10-25 08:00:00')==='2026-10-25','A lagged POS fiscal date must not move attendance to yesterday');
}
foreach (array(null,0) as $entryPair) {
    $db=new DoliDB();$db->pairRows=array();
    foreach (array(array(1,1,'2026-10-25 08:00:00','2026-10-24',$entryPair),array(2,2,'2026-10-25 16:00:00','2026-10-25',null),array(3,1,'2026-10-26 08:00:00','2026-10-25',null)) as $r)
        $db->pairRows[]=(object)array('rowid'=>$r[0],'entity'=>5,'loja_zs'=>9,'empid_zs'=>17,'sentido'=>$r[1],'datahora'=>$r[2],'dataval'=>$r[3],'pairid'=>$r[4],'synch'=>0,'id_zs'=>0);
    $import=new ZSPunchClock_Synch($db);$method=new ReflectionMethod($import,'processOrphansInRange');$method->setAccessible(true);
    foreach(array(1,2) as $direction)check($method->invoke($import,new DateTimeZone('Europe/Lisbon'),$direction,'2026-10-25','2026-10-25',false),'Pairing must succeed with lagged fiscal dates');
    check($db->pairRows[0]->pairid===1 && $db->pairRows[1]->pairid===1 && $db->pairRows[2]->pairid===null,'Real IN/OUT must share the IN identifier; current-day punch stays open');
}
echo "PASS: punch import empty responses, failures, cron output, entity isolation, replay and rollback\n";

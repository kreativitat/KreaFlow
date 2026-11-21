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

// Exercise production API control flow with a recording external-client boundary.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}
class DolibarrApi {}
class RestException extends Exception
{
    public function __construct($statusCode, $message) { parent::__construct($message, $statusCode); }
}
class AttendanceApiTestUser
{
    public $id;
    private $read;
    private $write;
    public function __construct($id, $read, $write = false)
    {
        $this->id = $id;
        $this->read = $read;
        $this->write = $write;
    }
    public function hasRight($module, $object, $permission)
    {
        return $module === 'dolizsynch' && $object === 'zspunchclock'
            && ($permission === 'read' ? $this->read : $this->write);
    }
}
class AttendanceApiTestDb
{
    public $queries = array();
    public function escape($value) { return addslashes($value); }
    public function query($sql)
    {
        $this->queries[] = $sql;
        return strpos($sql, 'entity = 2') !== false && strpos($sql, "value = '4'") !== false ? 1 : 2;
    }
    public function num_rows($result) { return $result === 1 ? 1 : 0; }
}
class ZSAPISynch
{
    public static $clients = 0;
    public static $calls = array();
    public function __construct($db, $configured = true) { self::$clients++; }
    public function __call($method, $args)
    {
        self::$calls[] = array($method, $args);
        return array(array('fixture' => true));
    }
}
function dol_syslog($message, $level = 0) {}
define('MAIN_DB_PREFIX', 'llx_');
$conf = (object) array('entity' => 2);
$db = new AttendanceApiTestDb();
// Replace only the external-client include, leaving all endpoint and authorization code intact.
$source = file_get_contents(dirname(__DIR__) . '/class/api_dolizsynch.class.php');
$include = "require_once DOL_DOCUMENT_ROOT . '/custom/kreaflow/class/zsapisynch.class.php';";
if (substr_count($source, $include) !== 1) {
    throw new RuntimeException('Unexpected API dependency declaration');
}
eval('?>' . str_replace($include, '', $source));
$api = new Dolizsynch();
$endpoints = array(
    'timeregisterSync' => array('2026-09-01 00:00:00', '4'),
    'timeregisterPeriod' => array('2026-09-01 00:00:00', '2026-09-02 00:00:00', 250, '4'),
    'reportPunchclock' => array('2026-09-01 00:00:00', '2026-09-02 00:00:00', 250, '4'),
    'reportTimesheet' => array('2026-09-01 00:00:00', '2026-09-02 00:00:00', 250, '4'),
);
$failures = 0;
$checks = 0;
function checkAttendanceApi($condition, $label)
{
    global $failures, $checks;
    $checks++;
    if (!$condition) {
        $failures++;
        echo '[FAIL] ' . $label . "\n";
    }
}
foreach ($endpoints as $method => $args) {
    foreach (array('missing user' => null, 'missing ID' => new AttendanceApiTestUser(0, true),
        'denied read' => new AttendanceApiTestUser(99, false),
        'write only' => new AttendanceApiTestUser(99, false, true)) as $label => $user) {
        ZSAPISynch::$clients = 0;
        ZSAPISynch::$calls = array();
        $db->queries = array();
        $status = 0;
        try { $api->$method(...$args); } catch (RestException $e) { $status = $e->getCode(); }
        checkAttendanceApi($status === 403, $method . ' rejects ' . $label);
        checkAttendanceApi(ZSAPISynch::$clients === 0 && ZSAPISynch::$calls === array() && $db->queries === array(),
            $method . ' denies before database/client access: ' . $label);
    }
    $user = new AttendanceApiTestUser(99, false);
    $status = 0;
    try { $api->$method(); } catch (RestException $e) { $status = $e->getCode(); }
    checkAttendanceApi($status === 403, $method . ' checks permission before parameter validation');

    $user = new AttendanceApiTestUser(99, true);
    ZSAPISynch::$clients = 0;
    ZSAPISynch::$calls = array();
    $result = $api->$method(...$args);
    checkAttendanceApi($result['status'] === 'success' && ZSAPISynch::$clients === 1 && count(ZSAPISynch::$calls) === 1,
        $method . ' allows attendance readers');
    $resultKey = strpos($method, 'timeregister') === 0 ? 'instances' : 'report';
    checkAttendanceApi($result[$resultKey] === array(array('fixture' => true)), $method . ' preserves response data');

    $foreignArgs = $args;
    $foreignArgs[count($foreignArgs) - 1] = '18';
    ZSAPISynch::$clients = 0;
    ZSAPISynch::$calls = array();
    $status = 0;
    try { $api->$method(...$foreignArgs); } catch (RestException $e) { $status = $e->getCode(); }
    checkAttendanceApi($status === 400 && ZSAPISynch::$clients === 0 && ZSAPISynch::$calls === array(),
        $method . ' still rejects foreign stores before external access');
}
$user = new AttendanceApiTestUser(99, true);
foreach (array('punchclockBootstrap', 'punchclockEventCreate') as $method) {
    $status = 0;
    try { $api->$method(); } catch (RestException $e) { $status = $e->getCode(); }
    checkAttendanceApi($status === 403, $method . ' still requires write permission');
}
echo $checks . ' attendance API checks; ' . $failures . " failures\n";
exit($failures ? 1 : 0);

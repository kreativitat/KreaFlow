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


// Transfer native filesystem registrations only; business identities remain stable.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit(1); }
$options = getopt('', array('apply', 'backup-dir:', 'user-id:'));
define('NOLOGIN', 1); define('NOREQUIREMENU', 1); define('NOREQUIREHTML', 1);
require dirname(__DIR__, 3).'/main.inc.php';

function kreaflowPartRows($db, $sql)
{
    $res = $db->query($sql);
    if (!$res) { throw new RuntimeException($db->lasterror()); }
    $rows = array();
    while ($row = $db->fetch_object($res)) { $rows[] = (array) $row; }
    return $rows;
}
$actor = new User($db);
if ($actor->fetch(isset($options['user-id']) ? (int) $options['user-id'] : 1) <= 0 || empty($actor->admin)) {
    throw new RuntimeException('An existing administrator is required');
}
$db->begin();
try {
    $active = kreaflowPartRows($db, 'SELECT name, entity FROM '.MAIN_DB_PREFIX."const WHERE name IN ('MAIN_MODULE_DOLIZSYNCH','MAIN_MODULE_KREAFLOW') AND value='1' AND entity >= 0 FOR UPDATE");
    $sync = array(); $mobile = array();
    foreach ($active as $row) {
        if ($row['name'] === 'MAIN_MODULE_DOLIZSYNCH') { $sync[] = (int) $row['entity']; }
        else { $mobile[] = (int) $row['entity']; }
    }
    if (array_diff($sync, $mobile)) { throw new RuntimeException('Every synchronization company must already have KreaFlow enabled'); }
    $running = kreaflowPartRows($db, 'SELECT rowid FROM '.MAIN_DB_PREFIX."cronjob WHERE entity >= 0 AND (classesname LIKE '/kreaflow/%' OR classesname LIKE '/dolizsynch/%') AND processing <> 0");
    if ($running) { throw new RuntimeException('A synchronization job is running; retry after it finishes'); }
    $names = array();
    foreach (array('DOLIZSYNCH', 'KREAFLOW') as $module) {
        foreach (array('HOOKS','TRIGGERS','MODELS','API') as $part) { $names[] = "'MAIN_MODULE_".$module.'_'.$part."'"; }
    }
    // Native module-part constants are entity-scoped, with entity 0 for shared hooks.
    $rows = kreaflowPartRows($db, 'SELECT * FROM '.MAIN_DB_PREFIX.'const WHERE entity >= 0 AND name IN ('.implode(',', $names).') ORDER BY entity,name,rowid FOR UPDATE');
    $index = array();
    foreach ($rows as $row) {
        $key = $row['entity'].':'.$row['name'];
        if (isset($index[$key])) { throw new RuntimeException('Duplicate native module-part constant'); }
        $index[$key] = $row;
    }
    $changes = array();
    foreach ($rows as $row) {
        if (strpos($row['name'], 'MAIN_MODULE_DOLIZSYNCH_') !== 0) { continue; }
        $target = str_replace('MAIN_MODULE_DOLIZSYNCH_', 'MAIN_MODULE_KREAFLOW_', $row['name']);
        $key = $row['entity'].':'.$target;
        if (isset($index[$key])) {
            if ($row['value'] === '0' || $row['value'] === '') { continue; }
            if ($index[$key]['value'] !== $row['value']) { throw new RuntimeException('Conflicting target registration: '.$target); }
            $changes[] = array('before'=>$row, 'field'=>'value', 'value'=>'0');
        } else {
            $changes[] = array('before'=>$row, 'field'=>'name', 'value'=>$target);
        }
    }
    if (isset($options['apply']) && $changes) {
        $dir = isset($options['backup-dir']) ? realpath($options['backup-dir']) : false;
        if (!$dir || !is_writable($dir)) { throw new RuntimeException('An existing private backup directory is required'); }
        $file = $dir.'/parts-before-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(4)).'.json';
        $json = json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        if ($json === false || file_put_contents($file, $json, LOCK_EX) === false || !chmod($file, 0600)) { throw new RuntimeException('Backup failed'); }
    }
    foreach ($changes as $change) {
        $row = $change['before']; $field = $change['field'];
        // dolibarr_set_const replaces IDs; preserve each native registration row instead.
        if (!$db->query('UPDATE '.MAIN_DB_PREFIX.'const SET '.$field."='".$db->escape($change['value'])."' WHERE rowid=".(int) $row['rowid'].' AND entity='.(int) $row['entity'])) { throw new RuntimeException($db->lasterror()); }
        $after = kreaflowPartRows($db, 'SELECT * FROM '.MAIN_DB_PREFIX.'const WHERE rowid='.(int) $row['rowid'].' AND entity='.(int) $row['entity']);
        if (count($after) !== 1) { throw new RuntimeException('Registration readback failed'); }
        foreach ($row as $key=>$value) {
            $expected = $key === $field ? $change['value'] : $value;
            if ($key !== 'tms' && $after[0][$key] !== $expected) { throw new RuntimeException('Unexpected registration change: '.$key); }
        }
    }
    if (isset($options['apply'])) { $db->commit(); } else { $db->rollback(); }
    echo json_encode(array('applied'=>isset($options['apply']), 'registrations'=>count($changes), 'synchronization_entities'=>$sync, 'ids_entities_activation_rights'=>'preserved'))."\n";
} catch (Throwable $e) {
    $db->rollback(); fwrite(STDERR, $e->getMessage()."\n"); exit(1);
}
$db->close();

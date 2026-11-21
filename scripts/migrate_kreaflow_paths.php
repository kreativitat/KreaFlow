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


// Metadata-only migration: retain IDs, permissions, schedules and entity ownership.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit(1); }
$options = getopt('', array('apply', 'backup-dir:', 'user-id:'));
define('NOLOGIN', 1); define('NOREQUIREMENU', 1); define('NOREQUIREHTML', 1);
require dirname(__DIR__, 3).'/main.inc.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/menubase.class.php';
require_once DOL_DOCUMENT_ROOT.'/cron/class/cronjob.class.php';

function kreaflowMigrationRows($db, $sql)
{
    $res = $db->query($sql);
    if (!$res) { throw new RuntimeException($db->lasterror()); }
    $rows = array();
    while ($row = $db->fetch_object($res)) { $rows[] = (array) $row; }
    return $rows;
}
function kreaflowMigrationPath($value)
{
    return str_replace(array('/dolizsynch/', '@dolizsynch'), array('/kreaflow/', '@kreaflow'), (string) $value);
}
function kreaflowMigrationStable($before, $after, $allowed)
{
    foreach ($before as $key => $value) {
        if (!in_array($key, $allowed, true) && $value !== $after[$key]) {
            throw new RuntimeException('Unexpected metadata change: '.$key.' on row '.$before['rowid']);
        }
    }
}
$actor = new User($db);
if ($actor->fetch(isset($options['user-id']) ? (int) $options['user-id'] : 1) <= 0 || empty($actor->admin)) {
    throw new RuntimeException('An existing administrator is required');
}
$db->begin();
try {
    $menus = kreaflowMigrationRows($db, 'SELECT * FROM '.MAIN_DB_PREFIX."menu WHERE module IN ('dolizsynch','kreaflow') AND entity >= 0 AND (url LIKE '%/dolizsynch/%' OR prefix LIKE '%/dolizsynch/%' OR langs LIKE '%@dolizsynch%') ORDER BY rowid FOR UPDATE");
    $jobs = kreaflowMigrationRows($db, 'SELECT * FROM '.MAIN_DB_PREFIX."cronjob WHERE entity >= 0 AND classesname LIKE '/dolizsynch/%' ORDER BY rowid FOR UPDATE");
    $tabs = kreaflowMigrationRows($db, 'SELECT * FROM '.MAIN_DB_PREFIX."const WHERE name LIKE 'MAIN_MODULE_DOLIZSYNCH_TABS_%' AND entity >= 0 AND (value LIKE '%/dolizsynch/%' OR value LIKE '%@dolizsynch%') ORDER BY rowid FOR UPDATE");
    foreach ($jobs as $row) {
        if (!empty($row['processing'])) { throw new RuntimeException('A synchronization job is running; retry after it finishes'); }
    }
    if (isset($options['apply']) && ($menus || $jobs || $tabs)) {
        $directory = isset($options['backup-dir']) ? realpath($options['backup-dir']) : false;
        if (!$directory || !is_writable($directory)) { throw new RuntimeException('An existing private backup directory is required'); }
        $file = $directory.'/metadata-before-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(4)).'.json';
        $json = json_encode(array('menus'=>$menus,'cronjobs'=>$jobs,'tabs'=>$tabs), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        if ($json === false || file_put_contents($file, $json, LOCK_EX) === false || !chmod($file, 0600)) {
            throw new RuntimeException('Metadata backup failed');
        }
    }
    foreach ($menus as $row) {
        $menu = new Menubase($db);
        if ($menu->fetch((int) $row['rowid']) <= 0 || (int) $menu->entity !== (int) $row['entity']) { throw new RuntimeException('Menu entity mismatch'); }
        // Menubase::update normalizes unrelated legacy fields (including leftmenu).
        // Update only source paths so historical metadata remains byte-for-byte intact.
        $sets = array();
        foreach (array('url', 'prefix', 'langs') as $key) {
            $sets[] = $key."='".$db->escape(kreaflowMigrationPath($row[$key]))."'";
        }
        if (!$db->query('UPDATE '.MAIN_DB_PREFIX.'menu SET '.implode(',', $sets).' WHERE rowid='.(int) $row['rowid'].' AND entity='.(int) $row['entity'])) {
            throw new RuntimeException('Menu path update failed: '.$db->lasterror());
        }
        $after = kreaflowMigrationRows($db, 'SELECT * FROM '.MAIN_DB_PREFIX.'menu WHERE rowid='.(int) $row['rowid'].' AND entity='.(int) $row['entity']);
        if (count($after) !== 1) { throw new RuntimeException('Menu readback failed'); }
        kreaflowMigrationStable($row, $after[0], array('url','prefix','langs','tms'));
        foreach (array('url','prefix','langs') as $key) {
            if ($after[0][$key] !== kreaflowMigrationPath($row[$key])) { throw new RuntimeException('Menu path readback failed'); }
        }
    }
    foreach ($jobs as $row) {
        $job = new Cronjob($db);
        if ($job->fetch((int) $row['rowid']) <= 0 || (int) $job->entity !== (int) $row['entity']) { throw new RuntimeException('Cron entity mismatch'); }
        // Do not run the full Cronjob update lifecycle for a source-path relocation.
        // Existing scheduling, execution state and ownership must remain unchanged.
        if (!$db->query('UPDATE '.MAIN_DB_PREFIX."cronjob SET classesname='".$db->escape(kreaflowMigrationPath($row['classesname']))."' WHERE rowid=".(int) $row['rowid'].' AND entity='.(int) $row['entity'])) {
            throw new RuntimeException('Cron path update failed: '.$db->lasterror());
        }
        $after = kreaflowMigrationRows($db, 'SELECT * FROM '.MAIN_DB_PREFIX.'cronjob WHERE rowid='.(int) $row['rowid'].' AND entity='.(int) $row['entity']);
        if (count($after) !== 1) { throw new RuntimeException('Cron readback failed'); }
        kreaflowMigrationStable($row, $after[0], array('classesname','tms'));
        if ($after[0]['classesname'] !== kreaflowMigrationPath($row['classesname'])) { throw new RuntimeException('Cron path readback failed'); }
    }
    foreach ($tabs as $row) {
        // dolibarr_set_const replaces the row; preserve its ID and metadata here.
        if (!$db->query('UPDATE '.MAIN_DB_PREFIX."const SET value='".$db->escape(kreaflowMigrationPath($row['value']))."' WHERE rowid=".(int) $row['rowid'].' AND entity='.(int) $row['entity'])) {
            throw new RuntimeException('Tab path update failed: '.$db->lasterror());
        }
        $after = kreaflowMigrationRows($db, 'SELECT * FROM '.MAIN_DB_PREFIX.'const WHERE rowid='.(int) $row['rowid'].' AND entity='.(int) $row['entity']);
        if (count($after) !== 1) { throw new RuntimeException('Tab readback failed'); }
        kreaflowMigrationStable($row, $after[0], array('value', 'tms'));
        if ($after[0]['value'] !== kreaflowMigrationPath($row['value'])) { throw new RuntimeException('Tab path readback failed'); }
    }
    if (isset($options['apply'])) { $db->commit(); } else { $db->rollback(); }
    echo json_encode(array('applied'=>isset($options['apply']), 'menus'=>count($menus), 'cronjobs'=>count($jobs), 'tabs'=>count($tabs), 'ids_permissions_schedules_entities'=>'preserved'))."\n";
} catch (Throwable $e) {
    $db->rollback();
    fwrite(STDERR, $e->getMessage()."\n");
    exit(1);
}
$db->close();

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

// CLI-only, metadata-only migration. Default execution rolls back all changes.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit(1); }
$options = getopt('', array('apply', 'backup-dir:', 'user-id:'));
define('NOLOGIN', 1); define('NOREQUIREMENU', 1); define('NOREQUIREHTML', 1);
require dirname(__DIR__, 3).'/main.inc.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/menubase.class.php';
require_once dirname(__DIR__).'/core/modules/modKreaFlow.class.php';
require_once dirname(__DIR__).'/compatibility/dolizsynch/descriptor.php';

function kreaflowToolsRows($db, $sql)
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
$templates = array();
foreach (array(new modKreaFlow($db), new modDoliZSynch($db)) as $module) {
    foreach ($module->menu as $entry) {
        if (in_array($entry['leftmenu'], array('kreaflow', 'kreaflow_mobile', 'dolizsynch_sync_tools'), true)) {
            $templates[$entry['leftmenu']] = $entry;
        }
    }
}
if (count($templates) !== 3) { throw new RuntimeException('Expected all three menu definitions'); }
$db->begin();
try {
    $rows = kreaflowToolsRows($db, 'SELECT * FROM '.MAIN_DB_PREFIX."menu WHERE (module='kreaflow' AND leftmenu IN ('kreaflow','kreaflow_mobile')) OR (module='dolizsynch' AND leftmenu='dolizsynch_sync_tools') ORDER BY entity,rowid FOR UPDATE");
    $parents = array(); $entities = array(); $children = array();
    foreach ($rows as $row) {
        if ($row['menu_handler'] !== 'all' || $row['mainmenu'] !== 'tools' || $row['type'] !== 'left') { throw new RuntimeException('Unexpected menu context'); }
        $entity = (int) $row['entity'];
        if ($row['leftmenu'] === 'kreaflow') {
            if (isset($parents[$entity])) { throw new RuntimeException('Duplicate parent scope'); }
            $parents[$entity] = $row;
        } else {
            $children[] = $row;
            if ($row['leftmenu'] === 'kreaflow_mobile') {
                if ($entity <= 0 || isset($entities[$entity])) { throw new RuntimeException('Unexpected mobile scope'); }
                $entities[$entity] = true;
            }
        }
    }
    if (!$entities) { throw new RuntimeException('No existing mobile menu scopes'); }
    $activeSync = kreaflowToolsRows($db, 'SELECT entity FROM '.MAIN_DB_PREFIX."const WHERE name='MAIN_MODULE_DOLIZSYNCH' AND value='1' AND entity > 0");
    foreach ($activeSync as $row) {
        if (!isset($entities[(int) $row['entity']])) { throw new RuntimeException('Synchronization scope lacks a mobile group; review before migration'); }
    }
    foreach ($parents as $entity => $row) {
        if (!isset($entities[$entity])) { throw new RuntimeException('Unexpected existing parent scope'); }
    }
    if (isset($options['apply'])) {
        $directory = isset($options['backup-dir']) ? realpath($options['backup-dir']) : false;
        if (!$directory || !is_writable($directory)) { throw new RuntimeException('A private existing backup directory is required'); }
        $file = $directory.'/menus-before-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(4)).'.json';
        $json = json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        if ($json === false || file_put_contents($file, $json, LOCK_EX) === false || !chmod($file, 0600)) { throw new RuntimeException('Menu backup failed'); }
    }
    $created = array(); $updated = array();
    $definition = $templates['kreaflow'];
    foreach ($entities as $entity => $unused) {
        if (!isset($parents[$entity])) {
            $parent = new Menubase($db);
            $parent->menu_handler = 'all'; $parent->module = 'kreaflow';
            $parent->fk_menu = -1; $parent->fk_mainmenu = 'tools'; $parent->fk_leftmenu = '';
            foreach (array('type','mainmenu','leftmenu','prefix','url','langs','position','perms','target','user','enabled') as $key) { $parent->$key = $definition[$key]; }
            $parent->title = $definition['titre']; $parent->entity = $entity;
            // Native duplicate detection uses conf->entity, not the object's entity.
            $previousEntity = $conf->entity;
            try {
                $conf->entity = $entity;
                $id = $parent->create($actor);
            } finally {
                $conf->entity = $previousEntity;
            }
            if ($id <= 0) { throw new RuntimeException('Native parent creation failed: '.$parent->error); }
            $created[] = $id;
            $readback = kreaflowToolsRows($db, 'SELECT * FROM '.MAIN_DB_PREFIX.'menu WHERE rowid='.(int) $id.' AND entity='.(int) $entity);
            if (count($readback) !== 1) { throw new RuntimeException('Parent entity readback failed'); }
            $parents[$entity] = $readback[0];
        }
        foreach (array('titre','url','perms','enabled','leftmenu','mainmenu','langs','prefix') as $key) {
            if ($parents[$entity][$key] !== $definition[$key]) { throw new RuntimeException('Parent definition mismatch: '.$key); }
        }
        if ((int) $parents[$entity]['fk_menu'] !== -1 || $parents[$entity]['fk_mainmenu'] !== 'tools' || !empty($parents[$entity]['fk_leftmenu'])) { throw new RuntimeException('Parent hierarchy mismatch'); }
    }
    foreach ($children as $row) {
        $entity = (int) $row['entity'];
        if ($entity < 0 || ($entity > 0 && !isset($entities[$entity]))) { throw new RuntimeException('Child entity mismatch'); }
        $expected = array('fk_menu'=>'-1', 'fk_mainmenu'=>'tools', 'fk_leftmenu'=>'kreaflow', 'titre'=>$templates[$row['leftmenu']]['titre'], 'prefix'=>'', 'position'=>(string) $templates[$row['leftmenu']]['position']);
        $changes = array();
        foreach ($expected as $key => $value) {
            if ((string) $row[$key] !== $value) { $changes[] = $key."='".$db->escape($value)."'"; }
        }
        if ($changes) {
            // Menubase::update normalizes unrelated legacy fields; preserve them exactly.
            if (!$db->query('UPDATE '.MAIN_DB_PREFIX.'menu SET '.implode(',', $changes).' WHERE rowid='.(int) $row['rowid'].' AND entity='.$entity)) { throw new RuntimeException($db->lasterror()); }
            $updated[] = (int) $row['rowid'];
        }
        $after = kreaflowToolsRows($db, 'SELECT * FROM '.MAIN_DB_PREFIX.'menu WHERE rowid='.(int) $row['rowid'].' AND entity='.$entity);
        if (count($after) !== 1) { throw new RuntimeException('Child readback failed'); }
        foreach ($row as $key => $value) {
            if ($key === 'tms') { continue; }
            $wanted = array_key_exists($key, $expected) ? $expected[$key] : $value;
            if ($after[0][$key] !== $wanted) { throw new RuntimeException('Unexpected child metadata change: '.$key); }
        }
    }
    if (isset($options['apply'])) { $db->commit(); } else { $db->rollback(); }
    echo json_encode(array('applied'=>isset($options['apply']), 'parents_created'=>$created, 'children_updated'=>$updated, 'entities'=>array_keys($entities), 'child_ids_permissions_urls_entities'=>'preserved'))."\n";
} catch (Throwable $e) {
    $db->rollback(); fwrite(STDERR, $e->getMessage()."\n"); exit(1);
}
$db->close();

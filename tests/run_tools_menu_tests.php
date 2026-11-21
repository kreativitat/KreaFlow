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

// Read-only native descriptor and permission regression; no database writes.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit(1); }
define('NOLOGIN', 1); define('NOREQUIREMENU', 1); define('NOREQUIREHTML', 1);
require dirname(__DIR__, 3).'/main.inc.php';
require_once dirname(__DIR__).'/core/modules/modKreaFlow.class.php';
require_once dirname(__DIR__).'/compatibility/dolizsynch/descriptor.php';
$entries = array();
foreach (array(new modKreaFlow($db), new modDoliZSynch($db)) as $module) {
    foreach ($module->menu as $entry) {
        if (in_array($entry['leftmenu'], array('kreaflow','kreaflow_mobile','dolizsynch_sync_tools'), true)) {
            if (isset($entries[$entry['leftmenu']])) { throw new RuntimeException('Duplicate menu definition'); }
            $entries[$entry['leftmenu']] = $entry;
        }
    }
}
if (count($entries) !== 3 || $entries['kreaflow']['fk_menu'] !== 'fk_mainmenu=tools') { throw new RuntimeException('Missing Tools group'); }
foreach (array('kreaflow_mobile','dolizsynch_sync_tools') as $key) {
    if ($entries[$key]['fk_menu'] !== 'fk_mainmenu=tools,fk_leftmenu=kreaflow') { throw new RuntimeException('Missing submenu relation'); }
}
if ($entries['kreaflow_mobile']['url'] !== '/kreaflow/mobile.php' || $entries['dolizsynch_sync_tools']['perms'] !== '$user->admin') { throw new RuntimeException('Changed destination or sync guard'); }
// Only this process's request context changes; native permission evaluation stays in use.
$savedModules = $conf->modules;
$savedUser = $user;
$checks = 0;
foreach (array(false,true) as $mobileEnabled) foreach (array(false,true) as $syncEnabled) foreach (array(false,true) as $administrator) foreach (array(false,true) as $reader) {
    $conf->modules = array();
    if ($mobileEnabled) { $conf->modules['kreaflow'] = 1; }
    if ($syncEnabled) { $conf->modules['dolizsynch'] = 1; }
    $user = new User($db); $user->admin = (int) $administrator;
    $user->rights->kreaflow = (object) array('mobile'=>(object) array('read'=>(int) $reader));
    $actual = (bool) eval('return ('.$entries['kreaflow']['enabled'].') && ('.$entries['kreaflow']['perms'].');');
    $expected = ($mobileEnabled && $reader) || ($syncEnabled && $administrator);
    if ($actual !== $expected) { throw new RuntimeException('Parent visibility differs from permitted children: '.json_encode(array($mobileEnabled,$syncEnabled,$administrator,$reader,isModEnabled('kreaflow'),isModEnabled('dolizsynch'),$user->hasRight('kreaflow','mobile','read'),$actual,$expected)));  }
    $checks++;
}
$conf->modules = $savedModules; $user = $savedUser;
echo $checks." permission combinations and native submenu definitions passed.\n";
$db->close();

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


if (PHP_SAPI !== 'cli') { http_response_code(403); exit(1); }
define('NOLOGIN',1); define('NOREQUIREMENU',1); define('NOREQUIREHTML',1);
require dirname(__DIR__,3).'/main.inc.php';
require_once __DIR__.'/../core/modules/modKreaFlow.class.php';
require_once __DIR__.'/../core/modules/modDoliZSynch.class.php';
require_once __DIR__.'/../class/actions_kreaflow.class.php';
$mobile = new modKreaFlow($db); $sync = new modDoliZSynch($db);
if ($mobile->numero !== 156561 || $sync->numero !== 156558 || $sync->rights_class !== 'dolizsynch' || !$sync->hidden) { throw new RuntimeException('Identity changed'); }
foreach (array('triggers','models','api') as $part) {
    if (empty($mobile->module_parts[$part]) || !empty($sync->module_parts[$part])) { throw new RuntimeException('Invalid part owner: '.$part); }
}
if (empty($mobile->module_parts['hooks']['data']) || !empty($sync->module_parts['hooks']['data'])) { throw new RuntimeException('Invalid hook owner'); }
$originalModules=$conf->modules;
try {
    unset($conf->modules['dolizsynch']);
    $hook = new ActionsKreaFlow($db);
    $methods=array('getNomUrl','completeTabsHead','doActions','formConfirm','objectLineView_BeforeProductExtrafield','formObjectOptions','printFieldListTitle','printFieldListValue');
    foreach ($methods as $method) {
        $params=array(); $object=new stdClass(); $action='';
        if ($hook->$method($params,$object,$action,null) !== 0 || $params || (array)$object || $action !== '') { throw new RuntimeException('Mobile-only hook side effect: '.$method); }
    }
    $conf->modules['dolizsynch']=1;
    $params=array();$object=new stdClass();$action='';
    if ($hook->getNomUrl($params,$object,$action) !== 0) { throw new RuntimeException('Active delegation failed'); }
} finally { $conf->modules=$originalModules; }
echo "Stable identities, single registration owner, eight inactive hook guards and active delegation passed.\n";
$db->close();

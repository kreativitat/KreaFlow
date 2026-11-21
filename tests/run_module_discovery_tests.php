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

// Read-only native module discovery regression.
if (PHP_SAPI !== 'cli') { exit(1); }
define('NOLOGIN',1); define('NOREQUIREMENU',1); define('NOREQUIREHTML',1);
require dirname(__DIR__, 3).'/main.inc.php';
require_once DOL_DOCUMENT_ROOT.'/custom/dolizsynch/core/modules/modDoliZSynch.class.php';
require_once DOL_DOCUMENT_ROOT.'/custom/kreaflow/core/modules/modKreaFlow.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/functions2.lib.php';
$discovered = array();
foreach (dolGetModulesDirs() as $directory) {
    foreach (array('modDoliZSynch', 'modKreaFlow') as $name) {
        if (is_readable($directory.$name.'.class.php')) { $discovered[$name][] = $directory; }
    }
}
foreach (array('modDoliZSynch', 'modKreaFlow') as $name) {
    if (empty($discovered[$name]) || count($discovered[$name]) !== 1) { throw new RuntimeException('Duplicate or missing native module discovery: '.$name); }
}
echo json_encode(array('native_module_discovery'=>$discovered))."\n";
$sync=new modDoliZSynch($db); $mobile=new modKreaFlow($db);
if ($sync->numero!==156558 || $mobile->numero!==156561 || $sync->version!==trim(file_get_contents(dirname(__DIR__).'/VERSION')) || $mobile->version!==trim(file_get_contents(dirname(__DIR__).'/VERSION')) || !$sync->hidden || $mobile->hidden || $sync->rights_class!=='dolizsynch' || $mobile->rights_class!=='kreaflow') throw new RuntimeException('Descriptor identity failed');
if (empty($sync->module_parts['triggers']) || !empty($mobile->module_parts['triggers'])) throw new RuntimeException('Duplicate or missing trigger registration');
foreach ($sync->menu as $entry) if (isset($entry['url']) && strpos($entry['url'], '/dolizsynch/')!==false) throw new RuntimeException('Old menu URL');
$mobileUrl=false; foreach($mobile->menu as $entry) if (isset($entry['url']) && strpos($entry['url'],'/kreaflow/mobile.php')!==false) $mobileUrl=true;
if (!$mobileUrl) throw new RuntimeException('Missing mobile entry');
$source=(new ReflectionClass('modDoliZSynch'))->getFileName();
if (strpos($source,'/custom/kreaflow/compatibility/')===false) throw new RuntimeException('Legacy implementation loaded');
$langs->load('dolizsynch@kreaflow');$langs->load('kreaflow@kreaflow');
echo json_encode(array('versions'=>array($sync->version,$mobile->version),'visible_module'=>$mobile->name,'hidden_provider'=>$sync->name,'mobile_entry'=>true,'trigger_registration'=>'single','sync_permissions'=>count($sync->rights),'mobile_permissions'=>count($mobile->rights),'source'=>$source,'label'=>$langs->trans('ModuleKreaFlowName')))."\n";
$db->close();

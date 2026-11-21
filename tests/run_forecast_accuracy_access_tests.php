<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License,
 * or (at your option) any later version.
 */
define('DOL_DOCUMENT_ROOT', dirname(__DIR__,3));
define('MAIN_DB_PREFIX','test_');
require_once __DIR__.'/../class/KreaFlowMobileApi.class.php';
function getDolGlobalInt($name,$default=0){return $default;}
function isModEnabled($name){return true;}
function dol_include_once($path){require_once DOL_DOCUMENT_ROOT.'/custom'.$path;}
class AccuracyMobileUser {
    public $dashboard=false;
    public function hasRight(...$args){return $args[0]==='kreaflow' && $this->dashboard;}
}
class AccuracyMobileDb { public $queries=0; public function query($sql){$this->queries++;throw new RuntimeException('Unexpected SQL');} }
class AccuracyMobileLangs {public function trans($key){return $key;}public function transnoentities($key){return $key;}public function load($key){} }
$db=new AccuracyMobileDb();$user=new AccuracyMobileUser();$api=new KreaFlowMobileApi($db,$user,new AccuracyMobileLangs(),(object)array('entity'=>8));
try {$api->getForecastAccuracy('2026-09-01','2026-09-19');throw new RuntimeException('Missing dashboard denial');}
catch(KreaFlowApiException $e){if($e->httpCode!==403)throw $e;}
$user->dashboard=true;
$r=$api->getForecastAccuracy(date('Y-m-d',strtotime('-7 days')),date('Y-m-d',strtotime('-1 day')));
if($r['status']!=='restricted'||$db->queries!==0)throw new RuntimeException('Reports history permission must precede SQL');
echo "Mobile forecast accuracy: Dashboard and historical permission boundaries passed.\n";

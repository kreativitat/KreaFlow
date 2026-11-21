<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License,
 * or (at your option) any later version.
 */
if (PHP_SAPI !== 'cli' || getenv('KREAFLOW_TEST_LOCAL') !== '1') { exit('Local database test opt-in required.'); }
define('DOL_DOCUMENT_ROOT', '/var/www/html');
require DOL_DOCUMENT_ROOT.'/conf/conf.php';
if ($dolibarr_main_db_host !== 'database') { throw new RuntimeException('Local Docker database required'); }
define('MAIN_DB_PREFIX', 'kf_test_'.bin2hex(random_bytes(4)).'_');
function dol_syslog($message,$level=0,$ident=0) {}
function dol_now(){return time();}
function dol_print_date($time,$format,$gm=false){return date('Y-m-d H:i:s',$time);}
$conf=(object)array('entity'=>42,'global'=>(object)array(),'db'=>(object)array('character_set'=>'utf8mb4','dolibarr_main_db_collation'=>'utf8mb4_unicode_ci'));
require DOL_DOCUMENT_ROOT.'/core/db/mysqli.class.php';
require __DIR__.'/../class/KreaFlowEatsAnalysisStore.class.php';
require __DIR__.'/../class/KreaFlowEatsAnalysis.class.php';
$db=new DoliDBMysqli('mysqli',$dolibarr_main_db_host,$dolibarr_main_db_user,$dolibarr_main_db_pass,$dolibarr_main_db_name,3306);
$db2=new DoliDBMysqli('mysqli',$dolibarr_main_db_host,$dolibarr_main_db_user,$dolibarr_main_db_pass,$dolibarr_main_db_name,3306);
if (!$db->ok || !$db2->ok) { throw new RuntimeException('Database unavailable'); }
$checks=0;
function checkStore($condition,$message){global $checks;if(!$condition)throw new RuntimeException($message);$checks++;}
function expectStoreCode(callable $callback,$code){try{$callback();}catch(RuntimeException $e){checkStore($e->getCode()===$code,'Expected rejection code '.$code);return;}throw new RuntimeException('Unexpected success');}
$table=MAIN_DB_PREFIX.'kreaflow_eats_analysis';
try {
    $schema=str_replace('llx_',MAIN_DB_PREFIX,preg_replace('/^--.*$/m','',file_get_contents(__DIR__.'/../sql/llx_kreaflow_eats_analysis.sql')));
    checkStore((bool)$db->query($schema),'Initial schema');checkStore((bool)$db->query($schema),'Repeat schema is idempotent');
    $a=new KreaFlowEatsAnalysisStore($db,42);$b=new KreaFlowEatsAnalysisStore($db2,42);$other=new KreaFlowEatsAnalysisStore($db,77);
    $facts=array('current'=>array('period'=>array('start'=>'2026-09-21','end'=>'2026-09-27','previous'=>true,'next'=>false),'profit_eur'=>14.2),
        'previous'=>null,'weather'=>array('location'=>'Braga','state'=>'unavailable','days'=>array()));
    $calls=0;$answer=array('sections'=>array('summary'=>'Saved once'),'generated_at'=>'2026-10-03','model'=>'fixture');
    $generate=function()use(&$calls,$answer){$calls++;return $answer;};
    $first=$a->remember('2026-09-21','2026-09-27',$facts,901,function()use($b,$facts,$generate){
        expectStoreCode(function()use($b,$facts,$generate){$b->remember('2026-09-21','2026-09-27',$facts,902,$generate);},409);
        return $generate();
    });
    checkStore($first===$answer && $calls===1,'Single concurrent provider call');
    checkStore($b->remember('2026-09-21','2026-09-27',$facts,902,$generate)===$answer && $calls===1,'Different user/connection reuses database result');
    $db->query("UPDATE ".$table." SET datec='2020-01-01',tms='2020-01-01'");
    $facts['current']['period']['next']=true;
    checkStore($a->remember('2026-09-21','2026-09-27',$facts,901,$generate)===$answer && $calls===1,'Age and navigation changes do not regenerate');
    checkStore($other->previousWeather('2026-09-21','2026-09-27')===array(),'No cross-entity weather cache read');
    $other->remember('2026-09-21','2026-09-27',$facts,901,$generate);checkStore($calls===2,'Different entity cannot reuse financial analysis');
    $facts['current']['profit_eur']=15.2;
    $a->remember('2026-09-21','2026-09-27',$facts,901,$generate);checkStore($calls===3,'Changed financial reference generates once');
    $a->remember('2026-09-21','2026-09-27',$facts,901,$generate);checkStore($calls===3,'Changed version persists');
    $facts['current']['profit_eur']=null;
    $a->remember('2026-09-21','2026-09-27',$facts,901,$generate);checkStore($calls===4,'Restricted cost view cannot reuse privileged output');
    $facts['weather']['days']=array(array('date'=>'2026-09-21','temperature_c'=>20.0,'rain_mm'=>2.0));$facts['weather']['state']='partial';
    $a->remember('2026-09-21','2026-09-27',$facts,901,$generate);checkStore($calls===5,'Changed weather reference generates once');
    $weather=$a->previousWeather('2026-09-21','2026-09-27');checkStore(count($weather['days'])===1,'Weather snapshot persisted');
    $fallback=KreaFlowEatsAnalysis::preserveWeather(array('location'=>'Braga','state'=>'unavailable','days'=>array()),$weather);
    checkStore($fallback==$weather,'Weather outage preserves known evidence without forcing generation');
    $changed=KreaFlowEatsAnalysis::preserveWeather(array('location'=>'Braga','state'=>'partial','days'=>array(array('date'=>'2026-09-21','temperature_c'=>21.0,'rain_mm'=>null))),$weather);
    checkStore($changed['days'][0]['temperature_c']===21.0 && $changed['days'][0]['rain_mm']==2.0,'Weather revisions retained; missing cells preserve history');
    $facts['current']['profit_eur']=99;
    $failure=function()use(&$calls){$calls++;throw new RuntimeException('Simulated provider failure');};
    expectStoreCode(function()use($a,$facts,$failure){$a->remember('2026-09-21','2026-09-27',$facts,901,$failure);},502);
    expectStoreCode(function()use($b,$facts,$generate){$b->remember('2026-09-21','2026-09-27',$facts,902,$generate);},502);
    checkStore($calls===6,'Failed generation is not replayed');
    $db->query('UPDATE '.$table.' SET status=0 WHERE entity=42 AND status=2');
    expectStoreCode(function()use($b,$facts,$generate){$b->remember('2026-09-21','2026-09-27',$facts,902,$generate);},409);
    checkStore($calls===6,'Interrupted generation is not replayed');
    echo "$checks database persistence and concurrency checks passed.\n";
} finally {
    $db->query('DROP TABLE IF EXISTS '.$table);$db2->close();$db->close();
}

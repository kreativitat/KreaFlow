<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License,
 * or (at your option) any later version.
 */
if (PHP_SAPI !== 'cli') { exit; }
define('DOL_DOCUMENT_ROOT', dirname(__DIR__, 3));
require_once __DIR__.'/../class/KreaFlowMobileApi.class.php';
require_once __DIR__.'/../class/KreaFlowKitchenClient.class.php';
function isModEnabled($name) { return $GLOBALS['enabled']; }
function getDolGlobalInt($name, $default=0) { return $default; }
function getDolGlobalString($name, $default='') { return $GLOBALS['kitchenConfigPath'] ?? $default; }
function dol_include_once($path) { require_once DOL_DOCUMENT_ROOT.'/custom'.$path; }
function dol_syslog($message, $level=0) {}
class KitchenUser { public $id=7; public $socid=0; public $denied=''; public function hasRight(...$parts) { return implode('.', $parts)!==$this->denied; } }
class KitchenLangs { public function trans($key) { return $key; } }
class KitchenDb { public function query($sql) { throw new RuntimeException('Unexpected SQL'); } }
class TestKitchenClient extends KreaFlowKitchenClient {
    public $calls=0; public $url; public $result;
    protected function fetch($url,$token) { $this->calls++; $this->url=$url; return $this->result; }
}
$checks=0;
function verifyKitchen($condition) { global $checks; if (!$condition) { throw new RuntimeException('Check '.($checks+1).' failed'); } $checks++; }
function deniedKitchen($fn,$code) { try { $fn(); throw new RuntimeException('Expected denial'); } catch (KreaFlowApiException $e) { verifyKitchen($e->httpCode===$code); } }
$GLOBALS['enabled']=true;
$user=new KitchenUser(); $api=new KreaFlowMobileApi(new KitchenDb(),$user,new KitchenLangs(),(object)array('entity'=>42));
putenv('KREAFLOW_KITCHEN_CONFIG_FILE');
foreach (array('kreaflow.mobile.read','kreaflow.dashboard.read','kreaflow.kitchen.read') as $right) {
    $user->denied=$right; deniedKitchen(function() use ($api) { $api->getKitchenDashboard(); },403);
}
$user->denied=''; $user->socid=1; deniedKitchen(function() use ($api) { $api->getKitchenDashboard(); },403);
$user->socid=0; $user->id=0; deniedKitchen(function() use ($api) { $api->getKitchenDashboard(); },401);
$user->id=7; $GLOBALS['enabled']=false; deniedKitchen(function() use ($api) { $api->getKitchenDashboard(); },403);
$GLOBALS['enabled']=true; deniedKitchen(function() { (new KreaFlowKitchenClient(42))->read(); },503);
$file=tempnam(sys_get_temp_dir(),'kitchen-test-');
try {
    $source='12345678-1234-1234-1234-123456789012';
    $config=array('42'=>array('enabled'=>true,'url'=>'https://kitchen.example.invalid','token'=>str_repeat('k',48),'source_id'=>$source));
    file_put_contents($file,json_encode($config)); putenv('KREAFLOW_KITCHEN_CONFIG_FILE='.$file);
    $client=new TestKitchenClient(42);
    $data=array('entity'=>42,'source_id'=>$source,'items'=>array(),'products'=>array(),'counts'=>array(),'timings'=>array(),'generated_at'=>'2026-10-03T12:00:00Z');
    $client->result=array('http_code'=>200,'content'=>json_encode($data));
    $result=$client->read('2026-10-02');
    verifyKitchen(!isset($result['source_id']) && $result['entity']===42);
    putenv('KREAFLOW_KITCHEN_CONFIG_FILE'); $GLOBALS['kitchenConfigPath']=$file;
    verifyKitchen($client->read()['entity']===42);
    unset($GLOBALS['kitchenConfigPath']); putenv('KREAFLOW_KITCHEN_CONFIG_FILE='.$file);
    $client->read('2026-10-02');
    verifyKitchen($client->url==='https://kitchen.example.invalid/integrations/kreaflow/kitchen/42?since=2026-10-02');
    $foreign=new TestKitchenClient(43); deniedKitchen(function() use ($foreign) { $foreign->read(); },503); verifyKitchen($foreign->calls===0);
    foreach (array(array('entity'=>43),array('source_id'=>'foreign'),array('items'=>null)) as $bad) {
        $client->result['content']=json_encode(array_merge($data,$bad)); deniedKitchen(function() use ($client) { $client->read(); },502);
    }
    $client->result=array('http_code'=>403); deniedKitchen(function() use ($client) { $client->read(); },503);
    $config['42']['url']='http://kitchen.example.invalid'; file_put_contents($file,json_encode($config));
    $before=$client->calls; deniedKitchen(function() use ($client) { $client->read(); },503); verifyKitchen($client->calls===$before);
} finally { unlink($file); putenv('KREAFLOW_KITCHEN_CONFIG_FILE'); }
require_once __DIR__.'/../class/KreaFlowKitchenSettings.class.php';
$conf = (object) array('global' => (object) array('ZS_PUNCH_DAY_CHANGE_HOUR' => 3));
foreach (array('2026-10-04T02:59:59+01:00'=>'2026-10-03', '2026-10-04T03:00:00+01:00'=>'2026-10-03', '2026-10-04T03:00:01+01:00'=>'2026-10-04') as $instant=>$day) {
    verifyKitchen(KreaFlowKitchenSettings::workingDay(new DateTimeImmutable($instant))['day'] === $day);
}
$dst = KreaFlowKitchenSettings::workingDay(new DateTimeImmutable('2026-10-24T12:00:00+01:00'));
verifyKitchen(strtotime($dst['window_end'])-strtotime($dst['window_start']) === 25*3600);
verifyKitchen(KreaFlowKitchenSettings::defaults('EXAMPLE_A') === array());
verifyKitchen(KreaFlowKitchenSettings::defaults('EXAMPLE_B') === array());
verifyKitchen(KreaFlowKitchenSettings::defaults('OTHER') === array());
$scoped = KreaFlowKitchenSettings::scope(array('items'=>array(array('centre'=>'1','status'=>'ready','duration_seconds'=>10),array('centre'=>'4','status'=>'ready','duration_seconds'=>100)), 'products'=>array(array('centre'=>'1'),array('centre'=>'4')), 'stations'=>array(array('id'=>'1'),array('id'=>'4'))), array('1'));
verifyKitchen(count($scoped['items']) === 1 && count($scoped['products']) === 1 && count($scoped['stations']) === 1);
verifyKitchen($scoped['counts']['ready'] === 1 && $scoped['timings']['average_seconds'] === 10);
verifyKitchen(KreaFlowKitchenSettings::scope($scoped, array())['timings']['samples'] === 0);
echo "$checks kitchen checks passed.\n";

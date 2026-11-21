<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License,
 * or (at your option) any later version.
 */
if (PHP_SAPI !== 'cli') { exit; }
require __DIR__.'/run_eats_week_tests.php';
require __DIR__.'/../class/KreaFlowEatsAnalysis.class.php';
function newToken(){return 'valid';} function currentToken(){return 'valid';}
$week['analytics'] = array('invoices'=>2,'sales'=>2000,'days'=>array(array('date'=>'2026-09-21','sales'=>2000,'invoices'=>2)),
 'can_products'=>true,'products'=>array(array('rowid'=>12,'ref'=>'private-ref','label'=>'<b>Menu</b>','amount'=>2000,'quantity'=>2)));
$facts=KreaFlowEatsAnalysis::facts($week);
verifyEats($facts['net_eur']===16.2 && $facts['profit_eur']===14.2,'Convert cents once');
verifyEats($facts['top_products'][0]['label']==='Menu' && !isset($facts['top_products'][0]['ref']),'Aggregate allowlist strips HTML and references');
verifyEats(!isset($facts['payouts']) && $facts['transfers_by_state']['Por conciliar']===1,'Only transfer status counts leave server');
$week['costs']['state']='forbidden';$week['analytics']['can_products']=false;
$restricted=KreaFlowEatsAnalysis::facts($week);
verifyEats($restricted['profit_eur']===null && $restricted['known_cost_eur']===null && !$restricted['top_products'],'Restricted amounts never reach model');
$week['economics']['has_data']=false;$empty=KreaFlowEatsAnalysis::facts($week);
verifyEats($empty['net_eur']===null,'No report is not zero revenue');
$weather=KreaFlowEatsAnalysis::weather(array('daily'=>array('time'=>array('2026-09-20','2026-09-21','2026-09-22'),'temperature_2m_mean'=>array(20,0,null),'precipitation_sum'=>array(0,0,null))),'2026-09-21','2026-09-22');
verifyEats($weather['state']==='partial' && count($weather['days'])===2,'Weather range and missing coverage');
verifyEats($weather['days'][0]['temperature_c']===0.0 && $weather['days'][1]['rain_mm']===null,'Real zero retained, missing rain not zero');
verifyEats(KreaFlowEatsAnalysis::weather(array(),'2026-09-21','2026-09-27')['state']==='unavailable','Weather outage reported explicitly');
$sections=array_fill_keys(array('summary','performance','products','weather','reconciliation','actions'),'<b>Text</b>');
verifyEats(KreaFlowEatsAnalysis::validate(json_encode($sections))['summary']==='Text','Model output is plain text');
foreach(array('not json','{}',json_encode(array_merge($sections,array('summary'=>array('x')))),json_encode(array_merge($sections,array('summary'=>str_repeat('x',1201))))) as $bad){
 try{KreaFlowEatsAnalysis::validate($bad);throw new LogicException('Accepted invalid response');}catch(RuntimeException $e){verifyEats(true,'Reject malformed model response');}
}
$user->id=1;$user->denied='';
foreach(array('kreaflow.mobile.read','kreaflow.dashboard.read','kreaeats.read','facture.lire','fournisseur.facture.lire','banque.lire') as $right){
 $user->denied=$right;
 try{$api->analyseEatsWeek('2026-09-21',42,'valid');throw new LogicException('Permission bypass');}catch(KreaFlowApiException $e){verifyEats($e->httpCode===403,'Analysis permission denied before SQL');}
}
$user->denied='';
foreach(array(array(43,'valid'),array(42,''),array(42,'invalid')) as $input){
 try{$api->analyseEatsWeek('2026-09-21',$input[0],$input[1]);throw new LogicException('Scope/CSRF bypass');}catch(KreaFlowApiException $e){verifyEats($e->httpCode===403,'Entity mismatch and invalid CSRF denied before SQL');}
}
echo "$checks total weekly and analysis checks passed.\n";

function getDolGlobalString($name){return $GLOBALS['eatsConfig'][$name] ?? '';}
$GLOBALS['eatsConfig']=array('KREAPRODUCTS_LLM_PROVIDER'=>'openrouter','KREAPRODUCTS_LLM_MODEL'=>'fixture','KREAPRODUCTS_LLM_API_KEY'=>'fixture-key');
verifyEats(KreaFlowEatsAnalysis::available(),'Configured enabled integration available');
foreach(array('ai','kreaproducts') as $module){
 $GLOBALS['eatsDisabledModules']=array($module);
 verifyEats(!KreaFlowEatsAnalysis::available(),'Disabled module hides analysis');
 try{$api->analyseEatsWeek('2026-09-21',42,'valid');throw new LogicException('Disabled feature accessed SQL');}catch(KreaFlowApiException $e){verifyEats($e->httpCode===403,'Disabled direct route denies before data/cache reads');}
}
$GLOBALS['eatsDisabledModules']=array();
foreach(array_keys($GLOBALS['eatsConfig']) as $name){$old=$GLOBALS['eatsConfig'][$name];$GLOBALS['eatsConfig'][$name]='';verifyEats(!KreaFlowEatsAnalysis::available(),'Unconfigured LLM disabled');$GLOBALS['eatsConfig'][$name]=$old;}
echo "$checks total weekly, analysis and activation checks passed.\n";

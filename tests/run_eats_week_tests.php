<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License,
 * or (at your option) any later version.
 */
if (PHP_SAPI !== 'cli') { exit; }
define('DOL_DOCUMENT_ROOT', dirname(__DIR__,3));
require_once __DIR__.'/../class/KreaFlowMobileApi.class.php';
require_once __DIR__.'/../class/KreaFlowEatsDashboard.class.php';
require_once __DIR__.'/../../kreaeats/class/kreaeatsprofitability.class.php';
$checks=0;
function verifyEats($condition,$message){global $checks;if(!$condition)throw new RuntimeException($message);$checks++;}
function isModEnabled($name){return $GLOBALS['eatsEnabled'] && !in_array($name, $GLOBALS['eatsDisabledModules'] ?? array(), true);}
function getDolGlobalInt($name,$default=0){return $name==='KREAFLOW_HISTORY_LIMIT_DAYS' ? 30 : $default;}
class EatsUser {public $denied=''; public function hasRight(...$p){return implode('.',$p)!==$this->denied;}}
class EatsDb {public function query($sql){throw new RuntimeException('Unexpected SQL');}}
class EatsLangs {public function trans($key){return $key;}}
$user=new EatsUser();$GLOBALS['eatsEnabled']=true;
$api=new KreaFlowMobileApi(new EatsDb(),$user,new EatsLangs(),(object)array('entity'=>42));
foreach(array('kreaflow.mobile.read','kreaflow.dashboard.read','kreaeats.read','facture.lire','fournisseur.facture.lire','banque.lire') as $right){
 $user->denied=$right;
 try{$api->getEatsWeek('2026-09-21');throw new RuntimeException('Permission bypass');}catch(KreaFlowApiException $e){verifyEats($e->httpCode===403,'Expected permission denial');}
}
$user->denied='';$GLOBALS['eatsEnabled']=false;
try{$api->getEatsWeek('2026-09-21');throw new RuntimeException('Disabled bypass');}catch(KreaFlowApiException $e){verifyEats($e->httpCode===403,'Module disabled denies before SQL');}
$GLOBALS['eatsEnabled']=true;
try{$api->getEatsWeek('2026-99-99');throw new RuntimeException('Date bypass');}catch(KreaFlowApiException $e){verifyEats($e->httpCode===400,'Reject invalid date');}
class EatsStub {
 public $forbidden=false;
 public $pages=1;
 public function settlements($store,$offset,$start,$end){$out=[];for($i=$offset;$i<min($this->pages,$offset+51);$i++)$out[]=array('rowid'=>$i+1,'date_start'=>'2026-09-20','date_end'=>'2026-09-28');return $out;}
 public function settlement($id){$rows=[];foreach(array('2026-09-20','2026-09-21','2026-09-27','2026-09-28') as $i=>$date)$rows[]=array('source_row'=>$i+1,'order_date'=>$date,'gross'=>1000,'fees'=>-200,'adjustments'=>10,'net'=>810,'components'=>array('Marketplace Fee after discount (incl VAT)'=>-190));return array('rowid'=>$id,'evidence_json'=>json_encode($rows));}
 public function summary($s){return array('state'=>'review');}
 public function saleProfitability($s,$selected,$summary){return array('known_cost'=>$this->forbidden ? 0 : count($selected)*100,'matched'=>count($selected),'total'=>count($selected),'partial'=>true,'state'=>$this->forbidden?'forbidden':'available');}
 public function salesAnalytics($start,$end,$metric){return array('start'=>$start,'end'=>$end,'metric'=>$metric);}
}
$stub=new EatsStub();$view=new KreaFlowEatsDashboard($stub);$week=$view->week('2026-09-21','2026-09-27','quantity');
verifyEats($week['economics']['sales']===2000 && $week['economics']['net']===1620,'Only two movements fall within Monday-Sunday');
verifyEats($week['costs']['known_cost']===200 && $week['costs']['profit']===1420,'Only selected movement costs deducted once');
verifyEats($week['analytics']['start']==='2026-09-21' && $week['analytics']['metric']==='quantity','Native analytics receives exact week and ranking');
$next=$view->week('2026-09-28','2026-10-04');verifyEats($next['economics']['net']===810,'Transfer spanning weeks does not repeat the full payout');
$empty=$view->week('2026-10-05','2026-10-11');verifyEats(!$empty['economics']['has_data'] && $empty['costs']['profit']===null,'No report is not zero confirmed sales');
$stub->forbidden=true;$hidden=$view->week('2026-09-21','2026-09-27');verifyEats($hidden['costs']['profit']===null && $hidden['costs']['known_cost']===null,'Cost permissions hide amounts');
$stub->forbidden=false;$stub->pages=52;$pages=$view->week('2026-09-21','2026-09-27');verifyEats(count($pages['payouts'])===52 && $pages['economics']['net']===52*1620,'Lookahead pagination neither drops nor duplicates settlements');
// Native history clamping occurs before adapter access.
$user->denied='kreaflow.history.readall';$method=new ReflectionMethod($api,'normalizeDateRange');$method->setAccessible(true);
$range=$method->invoke($api,'2000-01-03','2000-01-09',false,false);
verifyEats($range['start_date']>='2000-01-10','Restricted history cannot reach requested old week');
echo "$checks weekly KreaEats checks passed.\n";

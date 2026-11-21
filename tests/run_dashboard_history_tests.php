<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License,
 * or (at your option) any later version.
 */
define('DOL_DOCUMENT_ROOT', dirname(__DIR__, 3));
require_once __DIR__.'/../class/KreaFlowMobileApi.class.php';
function getDolGlobalInt($key, $default = 0) { return $default; }
function getEntity($type) { return '2,3'; }
class HistoryTestUser {
 public $allowed = true;
 public function hasRight(...$args) { return $this->allowed; }
}
class HistoryTestDb {
 public $queries = array();
 public $rows = array();
 public $synced = true;
 public function prefix() { return 'test_'; }
 public function escape($value) { return addslashes($value); }
 public function query($sql) {
  $this->queries[] = $sql;
  if (strpos($sql, 'WHERE 1 = 0') !== false) { return $this->synced; }
  return true;
 }
 public function free($result) {}
 public function fetch_object($result) { return count($this->rows) ? (object) array_shift($this->rows) : false; }
}
function checkHistory($ok, $message) { if (!$ok) { throw new RuntimeException($message); } }
$db = new HistoryTestDb(); $user = new HistoryTestUser();
$api = new KreaFlowMobileApi($db, $user, new stdClass(), (object) array('entity' => 2));
$method = new ReflectionMethod($api, 'fetchPreviousYearSales'); $method->setAccessible(true);
$db->rows = array(array('revenue_ht'=>100.10,'revenue_ttc'=>123.12), array('revenue_ht'=>-20.10,'revenue_ttc'=>-23.12));
$r = $method->invoke($api, '2024-02-29', '2024-03-02');
checkHistory($r['date_start']==='2023-03-02' && $r['date_end']==='2023-03-04', 'Leap-day range');
checkHistory(abs($r['revenue_ttc']-100)<0.001 && $r['status']==='available', 'Signed totals');
$sql = end($db->queries);
checkHistory(strpos($sql,'z.entity = 2')!==false && strpos($sql,'facturedet')===false, 'Entity and revenue-only query');
$user->allowed=false; $db->queries=array();
$r=$method->invoke($api,'2026-09-13','2026-09-13');
checkHistory($r['status']==='restricted' && !isset($r['revenue_ttc']) && count($db->queries)===0, 'Permission before SQL');
$user->allowed=true; $db->rows=array();
$r=$method->invoke($api,'2026-09-13','2026-09-13');
checkHistory($r['status']==='empty', 'Missing history');
$db->synced=false;
$r=$method->invoke($api,'2026-09-13','2026-09-13');
checkHistory(strpos(end($db->queries),'f.entity IN (2,3)')!==false, 'Native invoice sharing');
echo "PASS: leap-day dates, signed totals, missing history, permission denial before SQL, synchronized entity scope and native sharing\n";

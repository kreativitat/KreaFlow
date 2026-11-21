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

// Run against a disposable synthetic Dolibarr database, never production.
if (PHP_SAPI !== 'cli' || !is_file('/var/www/html/master.inc.php')) {
    echo "SKIP: isolated Dolibarr integration container required\n";
    exit(0);
}
require '/var/www/html/master.inc.php';
if ($dolibarr_main_db_name !== 'kreaflow_invoicesync_test') {
    throw new RuntimeException('Refusing to run outside the disposable invoice-sync fixture');
}
require_once dirname(__DIR__) . '/class/zsinvoicesynch.class.php';
class InvoiceSyncRetryHarness extends ZSInvoiceSynch
{
    public $selected = [];
    public $sourceCalls = 0;
    public $selectionOnly = false;
    public function __construct($db)
    {
        $this->db = $db;
        $this->syncManager = new ZSSyncManager($db, 'dolizsynch_zsinvoicesynch', 'invoice_ref');
    }
    public function synchronizeLatestUnsynchronisedInvoice($row)
    {
        if ($this->selectionOnly) { $this->selected[] = (int) $row->rowid; return true; }
        return parent::synchronizeLatestUnsynchronisedInvoice($row);
    }
    public function fetchInvoiceLines($doc, $serie, $numero, $date, $store = null)
    {
        $this->sourceCalls++;
        throw new RuntimeException('SYNTHETIC_SOURCE_BOUNDARY');
    }
}
$checks = 0;
$assert = static function ($condition, $message) use (&$checks) {
    if (!$condition) { throw new RuntimeException($message); }
    $checks++;
};
$sql = static function ($statement) use ($db) {
    if (!$db->query($statement)) { throw new RuntimeException($db->lasterror()); }
};
$invoke = static function ($object, $method, ...$args) {
    $r = new ReflectionMethod($object, $method); $r->setAccessible(true);
    return $r->invoke($object, ...$args);
};
$p = MAIN_DB_PREFIX;
$sql("CREATE TABLE IF NOT EXISTS {$p}dolizsynch_zsinvoicesynch (
 rowid INT AUTO_INCREMENT PRIMARY KEY,entity INT NOT NULL,invoice_ref VARCHAR(100),doc VARCHAR(6),doc_serie VARCHAR(20),doc_num INT,
 data_zs DATE,datahora_zs DATETIME,total_zs DECIMAL(24,8),liquido_zs DECIMAL(24,8),pago_zs INT DEFAULT 0,
 anulado_zs INT DEFAULT 0,loja_zs INT,fk_facture INT DEFAULT 0,synch INT,sync_context TEXT,error_message TEXT,
 fk_user_creat INT DEFAULT 1,date_creation DATETIME,date_modification DATETIME,pessoas_zs INT DEFAULT 1)");
$user->fetch(1); $user->loadRights();
$conf->global->ZS_MAX_SYNCH_TRIES_PER_INVOICE = 3;
$sync = new InvoiceSyncRetryHarness($db);
$manager = new ZSSyncManager($db, 'dolizsynch_zsinvoicesynch', 'invoice_ref');
$read = static function ($id) use ($db, $p) {
    $r = $db->query("SELECT * FROM {$p}dolizsynch_zsinvoicesynch WHERE rowid=" . (int) $id);
    return $db->fetch_object($r);
};
$insert = static function ($id, $status, $entity = 1, $store = 18, $fk = 0, array $context = []) use ($sql, $db, $p) {
    $sql("INSERT INTO {$p}dolizsynch_zsinvoicesynch(rowid,entity,invoice_ref,doc,doc_serie,doc_num,data_zs,datahora_zs,total_zs,liquido_zs,loja_zs,fk_facture,synch,sync_context,date_creation)
 VALUES(" . (int) $id . ',' . (int) $entity . ",'FT_SYNTHETIC_" . (int) $id . "','FA','SYNTHETIC'," . (int) $id . ",'2026-11-17','2026-11-17 12:00:00',10,10," . (int) $store . ',' . (int) $fk . ',' . (int) $status . ",'" . $db->escape(json_encode($context)) . "','2026-11-17 12:00:00')");
};
$baseline = (int) $db->transaction_opened;
$db->begin();
try {
    $sql("DELETE FROM {$p}dolizsynch_zsinvoicesynch");
    $sql("DELETE FROM {$p}facturedet WHERE fk_facture IN(999003,999004)");
    $sql("DELETE FROM {$p}facture WHERE rowid IN(999003,999004)");
    $assert(ZSSyncStatus::isRetryable(8) && in_array(8, ZSSyncStatus::getSynchronizableStatuses(), true), 'Save failures are eligible and retry-bounded');
    $insert(1,8); $insert(2,11); $insert(3,6); $insert(4,4); $insert(5,99); $insert(6,8,2); $insert(7,8,1,99);
    $sync->selectionOnly = true;
    $result = $invoke($sync,'synchronizeUnsynchronizedInvoicesForStore',18,50,'fixture');
    sort($sync->selected);
    $assert($sync->selected === [1,2], 'Store queue selects failed saves and queued retries, excluding blocked/foreign rows');
    $assert($result['success'] === 2, 'Queue reports actual selected successes');
    $sync->selected = [];
    $assert($sync->synchronizeAllUnsynchronizedInvoicesForDate('2026-11-17') === 0, 'Date-specific queue completes successfully');
    sort($sync->selected);
    $assert($sync->selected === [1,2], 'Date-specific recovery uses the same eligible statuses and company/store scope');
    $sync->selectionOnly = false;
    $assert($sync->synchronizeLatestUnsynchronisedInvoice($read(3)) === false && $sync->sourceCalls === 0, 'Fresh blocked state prevents stale workers from retrying');
    $stale = $read(1); $sql("UPDATE {$p}dolizsynch_zsinvoicesynch SET synch=6 WHERE rowid=1");
    $assert($sync->synchronizeLatestUnsynchronisedInvoice($stale) === false && $sync->sourceCalls === 0, 'Eligibility is rechecked after the reference lock and reload');
    $insert(8,8,1,18,999001,['facture_id'=>999001,'retry_attempts'=>2,'retry_status'=>8,'evidence'=>'preserved']);
    try { $sync->synchronizeLatestUnsynchronisedInvoice($read(8)); throw new RuntimeException('Recreation was not reached'); }
    catch (RuntimeException $e) { $assert($e->getMessage()==='SYNTHETIC_SOURCE_BOUNDARY', 'Confirmed missing partial reaches source-backed recreation'); }
    $row=$read(8); $context=json_decode($row->sync_context,true);
    $assert((int)$row->fk_facture===0 && $context['facture_id']===0 && $context['missing_facture_id']===999001, 'Missing link is cleared in live row and context with prior ID retained');
    $assert((int)$row->synch===8 && $context['retry_attempts']===2 && $context['evidence']==='preserved', 'Missing-link cleanup neither resets nor increments retry history');
    $manager->setSyncStatus($row->invoice_ref,8,'Synthetic repeated save failure');
    $row=$read(8); $assert((int)$row->synch===6 && json_decode($row->sync_context,true)['retry_attempts']===3, 'Next real failure reaches the configured cap after missing-link cleanup');
    $calls=$sync->sourceCalls;
    $assert($sync->synchronizeLatestUnsynchronisedInvoice($row)===false && $sync->sourceCalls===$calls, 'Exhausted save retries stop before source or financial work');
    $insert(9,8,1,18,0,['facture_id'=>999002]);
    $manager->setSyncStatus($read(9)->invoice_ref,2,'Synthetic failure');
    $assert((int)$read(9)->fk_facture===0, 'Historical context cannot resurrect a deleted invoice link');
    $manager->setSyncStatus($read(9)->invoice_ref,1,null,['facture_id'=>123]);
    $assert((int)$read(9)->fk_facture===123, 'Explicit success context records its verified invoice link');
    $manager->setSyncStatus($read(9)->invoice_ref,0,null,['facture_id'=>0]);
    $assert((int)$read(9)->fk_facture===0, 'Explicit zero clears the live invoice link');
    $sql("INSERT INTO {$p}facture(rowid,ref,entity,type,fk_soc,datef,fk_statut,total_ht,total_tva,total_ttc,multicurrency_total_ht,multicurrency_total_tva,multicurrency_total_ttc)
 VALUES(999003,'SYNTHETIC_VALID',1,0,1,'2026-11-17',1,10,0,10,10,0,10),(999004,'SYNTHETIC_FOREIGN',2,0,1,'2026-11-17',1,10,0,10,10,0,10)");
    $sql("INSERT INTO {$p}facturedet(fk_facture,description,qty,subprice,tva_tx,total_ht,total_tva,total_ttc,multicurrency_total_ht,multicurrency_total_tva,multicurrency_total_ttc,product_type,rang)
 VALUES(999003,'Synthetic service',1,10,0,10,0,10,10,0,10,1,1)");
    $insert(10,8,1,18,999003);
    $assert($sync->synchronizeLatestUnsynchronisedInvoice($read(10))===true, 'Validated linked invoice is recovered without recreation');
    $assert((int)$read(10)->synch===1 && (int)$read(10)->fk_facture===999003 && $sync->sourceCalls===$calls, 'Linked recovery verifies native totals and retains its unique link');
    $plan = ZSInvoiceTaxCalculator::calculate([['quantity'=>'1','unit_price'=>'10','vat_rate'=>'0']]);
    $assert($invoke($sync,'handleDuplicateInvoice',$read(9)->invoice_ref,999003,['monetary_plan'=>$plan])===999003, 'Exact-plan duplicate recovery reuses a verified native invoice');
    $assert((int)$read(9)->fk_facture===999003 && (int)$read(9)->synch===1, 'Duplicate recovery explicitly restores the cleared live invoice link');
    $insert(11,8,1,18,999004);
    $assert($sync->synchronizeLatestUnsynchronisedInvoice($read(11))===false && $sync->sourceCalls===$calls, 'Foreign-company invoice link fails closed');
    $insert(12,8,1,99);
    $assert($sync->synchronizeLatestUnsynchronisedInvoice($read(12))===false && $sync->sourceCalls===$calls, 'Foreign-store row fails closed');
    $sql("UPDATE {$p}facturedet SET total_ttc=11 WHERE fk_facture=999003");
    $insert(13,8,1,18,999003);
    $assert($sync->synchronizeLatestUnsynchronisedInvoice($read(13))===false && (int)$read(13)->synch!==1, 'Inconsistent native line totals cannot become successful on recovery');

    $sql("DELETE FROM {$p}dolizsynch_zsinvoicesynch");
    $insert(20,8,1,18,999001,['facture_id'=>999001,'retry_attempts'=>2,'evidence'=>'preserve cutoff exclusion']);
    $insert(21,8); $insert(22,11); $insert(23,8); $insert(24,8,2);
    $sql("UPDATE {$p}dolizsynch_zsinvoicesynch SET data_zs='2026-11-16',datahora_zs='2026-11-18 12:00:00' WHERE rowid=20");
    $sql("UPDATE {$p}dolizsynch_zsinvoicesynch SET data_zs='2026-11-18' WHERE rowid=22");
    $sql("UPDATE {$p}dolizsynch_zsinvoicesynch SET data_zs=NULL WHERE rowid=23");
    unset($conf->global->ZS_INVOICE_SYNC_MIN_DATE);
    $sync->selectionOnly = true; $sync->selected = [];
    $invoke($sync,'synchronizeUnsynchronizedInvoicesForStore',18,50,'fixture');
    sort($sync->selected);
    $assert($sync->selected===[20,21,22,23], 'Unset minimum date preserves legacy store-queue selection');
    $sync->selected = [];
    $assert($sync->synchronizeAllUnsynchronizedInvoicesForDate(null)===0, 'Unset minimum date preserves date-queue execution');
    sort($sync->selected);
    $assert($sync->selected===[20,21,22,23], 'Unset minimum date preserves legacy date-queue selection including unknown dates');

    $conf->global->ZS_INVOICE_SYNC_MIN_DATE = '2026-11-17';
    $oldEvidence = json_encode($read(20));
    $sync->selected = [];
    $invoke($sync,'synchronizeUnsynchronizedInvoicesForStore',18,50,'fixture');
    sort($sync->selected);
    $assert($sync->selected===[21,22], 'Store queue includes the fiscal-date boundary and newer dates, excluding older and unknown dates');
    $sync->selected = [];
    $assert($sync->synchronizeAllUnsynchronizedInvoicesForDate(null)===0, 'Minimum date permits eligible date-queue execution');
    sort($sync->selected);
    $assert($sync->selected===[21,22], 'Date queue applies the same inclusive fiscal-date floor');
    $sync->selected = [];
    $assert($sync->synchronizeAllUnsynchronizedInvoicesForDate('2026-11-16')===0 && $sync->selected===[], 'An explicit older date cannot override the configured floor');
    $assert($sync->synchronizeAllUnsynchronizedInvoicesForDate('2026-11-17')===0 && $sync->selected===[21], 'An explicit boundary date remains eligible');

    $sync->selectionOnly = false; $calls = $sync->sourceCalls;
    $stale = $read(20); $stale->data_zs = '2026-11-17';
    $assert($sync->synchronizeLatestUnsynchronisedInvoice($stale)===false && $sync->sourceCalls===$calls, 'Locked reload rejects an older fiscal date despite newer stale input and datetime');
    $assert(json_encode($read(20))===$oldEvidence, 'Cutoff rejection preserves status, missing invoice link, retry counters and all queue evidence');
    $unknownEvidence = json_encode($read(23));
    $assert($sync->synchronizeLatestUnsynchronisedInvoice($read(23))===false && $sync->sourceCalls===$calls, 'Configured floor rejects an unknown fiscal date before source work');
    $assert(json_encode($read(23))===$unknownEvidence, 'Unknown-date rejection leaves the queue unchanged');
    try { $sync->synchronizeLatestUnsynchronisedInvoice($read(21)); throw new RuntimeException('Boundary source was not reached'); }
    catch (RuntimeException $e) { $assert($e->getMessage()==='SYNTHETIC_SOURCE_BOUNDARY', 'Exact minimum date reaches the ordinary source-backed lifecycle'); }

    foreach (['2026-02-30','2026-02-29','17/11/2026','2026-11-17 00:00:00','invalid','0'] as $invalidMinimum) {
        $conf->global->ZS_INVOICE_SYNC_MIN_DATE = $invalidMinimum;
        $sync->selectionOnly = true; $sync->selected = [];
        $result = $invoke($sync,'synchronizeUnsynchronizedInvoicesForStore',18,50,'fixture');
        $assert($result['processed']===0 && $sync->selected===[] && strpos($result['sql_error'],'ZS_INVOICE_SYNC_MIN_DATE')!==false, 'Invalid minimum date closes store selection: '.$invalidMinimum);
        foreach (['synchronizeAllUnsynchronizedInvoicesForDate','synchronizeAllUnsynchronizedInvoicesByStore','synchronizeInvoicesAcrossMappedStores'] as $method) {
            $result = $sync->$method();
            $assert(is_string($result) && strpos($result,'ZS_INVOICE_SYNC_MIN_DATE')!==false && $sync->selected===[], 'Invalid minimum date stops '.$method.' with an actionable error');
        }
        $sync->selectionOnly = false; $calls = $sync->sourceCalls;
        $assert($sync->synchronizeLatestUnsynchronisedInvoice($read(20))===false && $sync->sourceCalls===$calls && json_encode($read(20))===$oldEvidence, 'Invalid configuration blocks direct recovery without queue changes');
    }
    $conf->global->ZS_INVOICE_SYNC_MIN_DATE = '2024-02-29';
    $assert($invoke($sync,'getInvoiceSyncMinimumDate')==='2024-02-29', 'Strict date validation accepts a real leap day');
    unset($conf->global->ZS_INVOICE_SYNC_MIN_DATE);
    try { $sync->synchronizeLatestUnsynchronisedInvoice($read(20)); throw new RuntimeException('Unset legacy source was not reached'); }
    catch (RuntimeException $e) { $assert($e->getMessage()==='SYNTHETIC_SOURCE_BOUNDARY', 'Unset minimum date preserves direct legacy recovery'); }
    echo 'OK: '.$checks." invoice sync retry integration checks\n";
} catch (Throwable $e) {
    fwrite(STDERR,'FAIL: '.$e->getMessage()."\n".$e->getTraceAsString()."\n"); $failed = true;
}

finally { while ((int) $db->transaction_opened > $baseline) { $db->rollback(); } }
if (!empty($failed)) { exit(1); }

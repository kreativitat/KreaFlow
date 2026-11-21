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

$bootstrap = '/var/www/html/main.inc.php';
if (!is_file($bootstrap)) {
	echo "SKIP: installed Dolibarr integration is available only in lamp-php81\n";
	exit(0);
}

if (!defined('NOLOGIN')) {
	define('NOLOGIN', 1);
}
require_once $bootstrap;
require_once DOL_DOCUMENT_ROOT . '/product/class/product.class.php';
require_once dirname(__DIR__) . '/class/zsproductpricecron.class.php';
require_once dirname(__DIR__) . '/class/zsprodsynch.class.php';
require_once dirname(__DIR__) . '/compatibility/dolizsynch/descriptor.php';

global $db, $conf, $user;

class ZSProductPriceSyncFakeApi
{
	public $interface = '';
	public $action = '';
	public $payload = array();
	public $store = 0;

	public function setInterface($interface)
	{
		$this->interface = (string) $interface;
	}

	public function executeUpdateProducts($action, $payload, $store)
	{
		$this->action = (string) $action;
		$this->payload = $payload;
		$this->store = (int) $store;
		return array('Response' => array('StatusCode' => 201));
	}
}

class ZSProductPriceSyncFakeWorker
{
	public $error = '';
	public $attempts = array();
	public $failOnceProductId = 0;

	public function updateZsbmsProductPricesForStore($product, $store)
	{
		$productId = (int) $product->id;
		$this->attempts[$productId] = ($this->attempts[$productId] ?? 0) + 1;
		if ($productId === $this->failOnceProductId && $this->attempts[$productId] === 1) {
			$this->error = 'Intentional first-attempt failure';
			return false;
		}
		$this->error = '';
		return (int) $store > 0;
	}
}

$failure = '';
$evidence = array();
$transactionStarted = false;

try {
	$setupSource = file_get_contents(dirname(__DIR__) . '/admin/setup.php');
	$syncToolsSource = file_get_contents(dirname(__DIR__) . '/admin/sync_tools.php');
	if ($setupSource === false || $syncToolsSource === false) {
		throw new RuntimeException('Unable to inspect the administrative configuration pages');
	}
	foreach (array(
		'DOLIZSYNCH_PRODUCT_PRICE_SYNC_TITLE',
		ZSProductPriceCron::CONFIG_ENABLED,
		ZSProductPriceCron::CONFIG_START_TIME,
		ZSProductPriceCron::CONFIG_REPEAT_INTERVAL,
	) as $configurationField) {
		if (strpos($setupSource, $configurationField) !== false) {
			throw new RuntimeException('The general setup page still owns product price scheduler field ' . $configurationField);
		}
		if (strpos($syncToolsSource, $configurationField) === false) {
			throw new RuntimeException('The synchronization tools page is missing product price scheduler field ' . $configurationField);
		}
	}
	if (strpos($syncToolsSource, "GETPOST('saveProductPriceSync', 'alpha')") === false
		|| strpos($syncToolsSource, 'dolizsynch_sync_tools_require_secure_post();') === false
		|| strpos($syncToolsSource, 'saveConfiguration(') === false) {
		throw new RuntimeException('The synchronization tools page does not expose the secure scheduler save lifecycle');
	}
	$evidence['configuration_page'] = 'admin/sync_tools.php';

	$migration = file_get_contents(dirname(__DIR__) . '/sql/upgrade/1.35.0.sql');
	if ($migration === false) {
		throw new RuntimeException('Unable to read the 1.35.0 migration');
	}
	preg_match_all('/CREATE TABLE IF NOT EXISTS[\s\S]*?ENGINE=innodb;/i', $migration, $matches);
	if (count($matches[0]) !== 2) {
		throw new RuntimeException('The 1.35.0 migration must define both recoverable queue tables');
	}
	foreach ($matches[0] as $statement) {
		$statement = preg_replace('/\bllx_/', MAIN_DB_PREFIX, $statement);
		if (!$db->query(rtrim($statement, ';'))) {
			throw new RuntimeException($db->lasterror());
		}
	}

	foreach (array('dolizsynch_product_price_sync_run', 'dolizsynch_product_price_sync_item') as $table) {
		$resql = $db->query('SHOW TABLES LIKE \'' . $db->escape(MAIN_DB_PREFIX . $table) . '\'');
		if (!$resql || $db->num_rows($resql) !== 1) {
			throw new RuntimeException('Missing local queue table ' . $table);
		}
	}
	$evidence['queue_schema'] = 'installed';

	$module = new modDoliZSynch($db);
	foreach ($module->cronjobs as $job) {
		if ((int) ($job['status'] ?? -1) !== 0) {
			throw new RuntimeException('Every module cron descriptor must be inactive by default');
		}
	}
	$evidence['cron_descriptors_inactive'] = count($module->cronjobs);

	$reference = dol_mktime(0, 0, 0, 8, 23, 2026);
	$nextDaily = ZSProductPriceCron::calculateNextRun('04:00', '00:00', $reference);
	$nextThirtyMinutes = ZSProductPriceCron::calculateNextRun('04:00', '00:30', dol_mktime(4, 10, 0, 8, 23, 2026));
	if (date('Y-m-d H:i', $nextDaily) !== '2026-08-23 04:00' || date('Y-m-d H:i', $nextThirtyMinutes) !== '2026-08-23 04:30') {
		throw new RuntimeException('Start-time alignment or repeat-interval calculation is incorrect');
	}
	$evidence['schedule'] = array('daily' => date('H:i', $nextDaily), 'repeat' => date('H:i', $nextThirtyMinutes));

	$entity = (int) $conf->entity;
	$store = (int) getDolGlobalInt('ZS_API_STORE');
	if ($entity <= 0 || $store <= 0) {
		throw new RuntimeException('The local entity requires ZS_API_STORE for this integration test');
	}

	$scheduler = new ZSProductPriceCron($db);
	$eligibleIds = $scheduler->fetchEligibleProductIds($entity, $store);
	if ($eligibleIds === false) {
		throw new RuntimeException($scheduler->error);
	}
	$evidence['eligible_product_count'] = count($eligibleIds);

	$sql = 'SELECT product.rowid FROM ' . MAIN_DB_PREFIX . 'product product';
	$sql .= ' INNER JOIN ' . MAIN_DB_PREFIX . 'dolizsynch_zsproduct zsproduct ON zsproduct.fk_product=product.rowid';
	$sql .= ' WHERE product.entity IN (' . getEntity('product') . ') AND zsproduct.codigo>0';
	$sql .= ' ORDER BY product.rowid ASC ' . $db->plimit(1);
	$resql = $db->query($sql);
	$row = $resql ? $db->fetch_object($resql) : null;
	if (!$row) {
		throw new RuntimeException('The local fixture has no mapped product for payload verification');
	}

	$product = new Product($db);
	if ($product->fetch((int) $row->rowid) <= 0) {
		throw new RuntimeException($product->error ?: 'Unable to load payload test product');
	}
	$product->fetch_optionals((int) $product->id);
	$fakeApi = new ZSProductPriceSyncFakeApi();
	$productSynch = new ZSProductSynch($db, $fakeApi);
	$expectedPayload = $productSynch->buildZsbmsPricePayload($product, $store);
	if ($expectedPayload === false || !$productSynch->updateZsbmsProductPricesForStore($product, $store)) {
		throw new RuntimeException($productSynch->error ?: 'The price-only API adapter failed');
	}
	if ($fakeApi->interface !== 'products' || $fakeApi->action !== 'saveInstances' || $fakeApi->store !== $store) {
		throw new RuntimeException('The price-only updater addressed the wrong ZSBMS interface or store');
	}
	if ($fakeApi->payload !== $expectedPayload) {
		throw new RuntimeException('The API did not receive the verified restricted price payload');
	}

	$details = $fakeApi->payload['product'][0] ?? array();
	$levelLimit = !empty($conf->global->PRODUIT_MULTIPRICES)
		? max(1, (int) getDolGlobalInt('PRODUIT_MULTIPRICES_LIMIT', 5))
		: 1;
	$allowedFields = array('loja', 'codigo', 'precocompra');
	for ($level = 1; $level <= $levelLimit; $level++) {
		$allowedFields[] = $level === 1 ? 'precovenda' : 'pvp' . $level;
		$allowedFields[] = $level === 1 ? 'pvp1siva' : 'pvp' . $level . 'siva';
	}
	$actualFields = array_keys($details);
	sort($allowedFields);
	sort($actualFields);
	if ($actualFields !== $allowedFields) {
		throw new RuntimeException('The scheduled payload contains fields other than identity, selling prices, or cost price');
	}
	if ((string) $details['precocompra'] !== number_format((float) $product->cost_price, 2, '.', '')) {
		throw new RuntimeException('The payload cost price does not match Dolibarr cost_price');
	}
	for ($level = 1; $level <= $levelLimit; $level++) {
		$priceHt = null;
		$priceTtc = null;
		if (!empty($conf->global->PRODUIT_MULTIPRICES)) {
			$sql = 'SELECT price, price_ttc FROM ' . MAIN_DB_PREFIX . 'product_price';
			$sql .= ' WHERE fk_product=' . (int) $product->id;
			$sql .= ' AND entity IN (' . getEntity('productprice') . ') AND price_level=' . $level;
			$sql .= ' ORDER BY date_price DESC, rowid DESC ' . $db->plimit(1);
			$resql = $db->query($sql);
			$priceRow = $resql ? $db->fetch_object($resql) : null;
			if ($priceRow) {
				$priceHt = $priceRow->price;
				$priceTtc = $priceRow->price_ttc;
			}
		}
		if ($priceHt === null) {
			$priceHt = $level === 1
				? ($product->multiprices[$level] ?? $product->price)
				: ($product->multiprices[$level] ?? 0);
			$priceTtc = $level === 1
				? ($product->multiprices_ttc[$level] ?? $product->price_ttc)
				: ($product->multiprices_ttc[$level] ?? 0);
		}
		$ttcField = $level === 1 ? 'precovenda' : 'pvp' . $level;
		$htField = $level === 1 ? 'pvp1siva' : 'pvp' . $level . 'siva';
		if ((string) $details[$ttcField] !== number_format((float) $priceTtc, 2, '.', '')
			|| (string) $details[$htField] !== number_format((float) $priceHt, 2, '.', '')) {
			throw new RuntimeException('Payload selling price level ' . $level . ' does not match Dolibarr');
		}
	}
	$evidence['payload_fields'] = $actualFields;
	$evidence['payload_cost_price'] = $details['precocompra'];

	if (!empty($eligibleIds)) {
		$previousEnabled = $conf->global->{ZSProductPriceCron::CONFIG_ENABLED} ?? null;
		$hadPreviousEnabled = isset($conf->global->{ZSProductPriceCron::CONFIG_ENABLED});
		$conf->global->{ZSProductPriceCron::CONFIG_ENABLED} = '1';
		$worker = new ZSProductPriceSyncFakeWorker();
		$worker->failOnceProductId = (int) $eligibleIds[0];
		$queueScheduler = new ZSProductPriceCron($db, $worker);
		if (!$db->begin()) {
			throw new RuntimeException('Unable to start queue recovery rollback transaction');
		}
		$transactionStarted = true;
		if ($queueScheduler->runScheduledPriceSynchronization() < 0) {
			throw new RuntimeException($queueScheduler->error ?: 'Primary queue execution failed');
		}
		$sql = 'SELECT rowid FROM ' . MAIN_DB_PREFIX . 'dolizsynch_product_price_sync_run';
		$sql .= ' WHERE entity=' . $entity . ' AND active_key=' . $entity . $db->plimit(1);
		$resql = $db->query($sql);
		$activeRun = $resql ? $db->fetch_object($resql) : null;
		if (!$activeRun) {
			throw new RuntimeException('The retryable product did not leave a resumable active queue');
		}
		$sql = 'UPDATE ' . MAIN_DB_PREFIX . 'dolizsynch_product_price_sync_item';
		$sql .= " SET next_attempt_at='" . $db->idate(dol_now() - 1) . "'";
		$sql .= ' WHERE fk_run=' . (int) $activeRun->rowid . ' AND fk_product=' . (int) $worker->failOnceProductId;
		if (!$db->query($sql) || $queueScheduler->recoverInterruptedPriceSynchronization() < 0) {
			throw new RuntimeException($queueScheduler->error ?: 'Recovery queue execution failed');
		}
		$sql = 'SELECT status, total_count, synchronized_count, error_count FROM ';
		$sql .= MAIN_DB_PREFIX . 'dolizsynch_product_price_sync_run WHERE rowid=' . (int) $activeRun->rowid;
		$resql = $db->query($sql);
		$completedRun = $resql ? $db->fetch_object($resql) : null;
		if (!$completedRun || (int) $completedRun->status !== 2
			|| (int) $completedRun->total_count !== count($eligibleIds)
			|| (int) $completedRun->synchronized_count !== count($eligibleIds)
			|| (int) $completedRun->error_count !== 0
			|| ($worker->attempts[$worker->failOnceProductId] ?? 0) !== 2) {
			throw new RuntimeException('The recovery worker did not resume and complete the interrupted product');
		}
		$evidence['recovery_queue'] = array(
			'products' => (int) $completedRun->total_count,
			'retried_product' => (int) $worker->failOnceProductId,
			'attempts' => (int) $worker->attempts[$worker->failOnceProductId],
		);
		$db->rollback();
		$transactionStarted = false;
		if ($hadPreviousEnabled) {
			$conf->global->{ZSProductPriceCron::CONFIG_ENABLED} = $previousEnabled;
		} else {
			unset($conf->global->{ZSProductPriceCron::CONFIG_ENABLED});
		}
	}

	if (!$db->begin()) {
		throw new RuntimeException('Unable to start cron registration rollback transaction');
	}
	$transactionStarted = true;
	if (!$scheduler->synchronizeCronJobs(
		$entity,
		true,
		'04:00',
		'00:30',
		(int) ($user->id ?? 0),
		'TEST primary product price synchronization',
		'TEST primary comment',
		'TEST recovery product price synchronization',
		'TEST recovery comment',
		false
	)) {
		throw new RuntimeException($scheduler->error);
	}
	$sql = 'SELECT methodename, status, unitfrequency FROM ' . MAIN_DB_PREFIX . 'cronjob';
	$sql .= " WHERE module_name='dolizsynch' AND entity=" . $entity;
	$sql .= " AND objectname='ZSProductPriceCron' ORDER BY methodename";
	$resql = $db->query($sql);
	$cronRows = array();
	while ($resql && ($cronRow = $db->fetch_object($resql))) {
		$cronRows[$cronRow->methodename] = array('status' => (int) $cronRow->status, 'seconds' => (int) $cronRow->unitfrequency);
	}
	if (count($cronRows) !== 2
		|| ($cronRows[ZSProductPriceCron::PRIMARY_METHOD]['status'] ?? 0) !== 1
		|| ($cronRows[ZSProductPriceCron::PRIMARY_METHOD]['seconds'] ?? 0) !== 1800
		|| ($cronRows[ZSProductPriceCron::RECOVERY_METHOD]['status'] ?? 0) !== 1
		|| ($cronRows[ZSProductPriceCron::RECOVERY_METHOD]['seconds'] ?? 0) !== 300) {
		throw new RuntimeException('Feature activation did not create and activate the exact two entity-owned cron jobs');
	}
	$evidence['activated_cron_rows'] = $cronRows;
	$db->rollback();
	$transactionStarted = false;
} catch (Throwable $e) {
	$failure = $e->getMessage();
} finally {
	if ($transactionStarted) {
		$db->rollback();
	}
}

if ($failure !== '') {
	fwrite(STDERR, "FAIL: {$failure}\n");
	exit(1);
}

echo "PASS: product price synchronization integration\n";
echo json_encode($evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";

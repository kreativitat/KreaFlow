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

/**
 * Entity-scoped scheduler and recoverable worker for ZSBMS selling and cost price synchronization.
 */
class ZSProductPriceCron
{
	public const CONFIG_ENABLED = 'DOLIZSYNCH_PRODUCT_PRICE_SYNC_ENABLED';
	public const CONFIG_START_TIME = 'DOLIZSYNCH_PRODUCT_PRICE_SYNC_START_TIME';
	public const CONFIG_REPEAT_INTERVAL = 'DOLIZSYNCH_PRODUCT_PRICE_SYNC_REPEAT_INTERVAL';

	public const PRIMARY_METHOD = 'runScheduledPriceSynchronization';
	public const RECOVERY_METHOD = 'recoverInterruptedPriceSynchronization';

	private const RUN_SEEDING = 0;
	private const RUN_ACTIVE = 1;
	private const RUN_COMPLETED = 2;
	private const RUN_COMPLETED_WITH_ERRORS = 3;

	private const ITEM_PENDING = 0;
	private const ITEM_PROCESSING = 1;
	private const ITEM_SYNCHRONIZED = 2;
	private const ITEM_RETRY = 3;
	private const ITEM_FAILED = 4;
	private const ITEM_SKIPPED = 5;

	private const MAX_ATTEMPTS = 3;
	private const RETRY_DELAY_SECONDS = 300;
	private const WORKER_LEASE_SECONDS = 1800;
	private const COMPLETED_RETENTION_DAYS = 30;

	/** @var DoliDB */
	private $db;

	/** @var ZSProductSynch|null */
	private $productSynch;

	/** @var string */
	public $error = '';

	/** @var string */
	public $output = '';

	/**
	 * @param DoliDB $db Database handler
	 * @param object|null $productSynch Optional product synchronizer for tests
	 */
	public function __construct($db, $productSynch = null)
	{
		$this->db = $db;
		$this->productSynch = $productSynch;
	}

	/**
	 * Run the scheduled selling and cost price synchronization for the active entity.
	 *
	 * @return int 0 on success or deferred retry, -1 on final failure
	 */
	public function runScheduledPriceSynchronization()
	{
		return $this->processEntityRun(false);
	}

	/**
	 * Resume an interrupted synchronization after its worker lease expires.
	 *
	 * @return int 0 on success or when no recovery is required, -1 on final failure
	 */
	public function recoverInterruptedPriceSynchronization()
	{
		return $this->processEntityRun(true);
	}

	/**
	 * Normalize an HH:MM value.
	 *
	 * @param string $value Submitted value
	 * @return string
	 * @throws InvalidArgumentException When invalid
	 */
	public static function normalizeTimeValue($value)
	{
		$value = trim((string) $value);
		if (!preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/', $value)) {
			throw new InvalidArgumentException('Time values must use the HH:MM format');
		}

		return $value;
	}

	/**
	 * Convert an HH:MM repeat interval to seconds. 00:00 means once per day.
	 *
	 * @param string $value Repeat interval
	 * @return int
	 */
	public static function repeatIntervalToSeconds($value)
	{
		$value = self::normalizeTimeValue($value);
		list($hours, $minutes) = array_map('intval', explode(':', $value));
		$seconds = ($hours * 3600) + ($minutes * 60);

		return $seconds > 0 ? $seconds : 86400;
	}

	/**
	 * Calculate the next execution aligned to the configured start time.
	 *
	 * A 00:00 repeat interval means the next daily start. Positive intervals
	 * remain aligned to today's configured start and then repeat continuously.
	 *
	 * @param string $startTime HH:MM start time
	 * @param string $repeatInterval HH:MM repeat interval
	 * @param int|null $nowTimestamp Reference timestamp
	 * @return int
	 */
	public static function calculateNextRun($startTime, $repeatInterval, $nowTimestamp = null)
	{
		$startTime = self::normalizeTimeValue($startTime);
		$repeatInterval = self::normalizeTimeValue($repeatInterval);
		$nowTimestamp = $nowTimestamp === null ? dol_now() : (int) $nowTimestamp;
		$timezone = new DateTimeZone(date_default_timezone_get());
		$now = (new DateTimeImmutable('@' . $nowTimestamp))->setTimezone($timezone);
		list($hours, $minutes) = array_map('intval', explode(':', $startTime));
		$base = $now->setTime($hours, $minutes, 0);
		$rawRepeatSeconds = self::repeatIntervalToRawSeconds($repeatInterval);

		if ($rawRepeatSeconds === 0) {
			if ($base->getTimestamp() <= $nowTimestamp) {
				$base = $base->modify('+1 day');
			}
			return $base->getTimestamp();
		}

		if ($base->getTimestamp() > $nowTimestamp) {
			return $base->getTimestamp();
		}

		$elapsed = $nowTimestamp - $base->getTimestamp();
		$cycles = intdiv($elapsed, $rawRepeatSeconds) + 1;
		return $base->getTimestamp() + ($cycles * $rawRepeatSeconds);
	}

	/**
	 * Persist setup constants and create/update both entity-owned cron jobs.
	 *
	 * @param int $entity Entity ID
	 * @param bool $enabled Feature state
	 * @param string $startTime HH:MM start time
	 * @param string $repeatInterval HH:MM repeat interval
	 * @param int $actorId User making the change
	 * @param string $primaryLabel Translated primary job label
	 * @param string $primaryComment Translated primary job comment
	 * @param string $recoveryLabel Translated recovery job label
	 * @param string $recoveryComment Translated recovery job comment
	 * @return bool
	 */
	public function saveConfiguration(
		$entity,
		$enabled,
		$startTime,
		$repeatInterval,
		$actorId,
		$primaryLabel,
		$primaryComment,
		$recoveryLabel,
		$recoveryComment
	) {
		$entity = (int) $entity;
		$actorId = (int) $actorId;
		$startTime = self::normalizeTimeValue($startTime);
		$repeatInterval = self::normalizeTimeValue($repeatInterval);
		if ($entity <= 0) {
			$this->error = 'Invalid entity for product price synchronization';
			return false;
		}

		require_once DOL_DOCUMENT_ROOT . '/core/lib/admin.lib.php';
		$this->db->begin();
		$constantValues = array(
			self::CONFIG_ENABLED => $enabled ? '1' : '0',
			self::CONFIG_START_TIME => $startTime,
			self::CONFIG_REPEAT_INTERVAL => $repeatInterval,
		);
		foreach ($constantValues as $name => $value) {
			if (dolibarr_set_const($this->db, $name, $value, 'chaine', 0, '', $entity) <= 0) {
				$this->error = 'Unable to save product price synchronization setting ' . $name;
				$this->db->rollback();
				return false;
			}
		}

		if (!$this->synchronizeCronJobs(
			$entity,
			(bool) $enabled,
			$startTime,
			$repeatInterval,
			$actorId,
			$primaryLabel,
			$primaryComment,
			$recoveryLabel,
			$recoveryComment,
			false
		)) {
			$this->db->rollback();
			return false;
		}

		$this->db->commit();
		return true;
	}

	/**
	 * Ensure both cron rows exist and match the current entity configuration.
	 *
	 * @param int $entity Entity ID
	 * @param bool $enabled Feature state
	 * @param string $startTime HH:MM start time
	 * @param string $repeatInterval HH:MM repeat interval
	 * @param int $actorId User ID
	 * @param string $primaryLabel Primary label
	 * @param string $primaryComment Primary comment
	 * @param string $recoveryLabel Recovery label
	 * @param string $recoveryComment Recovery comment
	 * @param bool $manageTransaction Start/commit transaction
	 * @return bool
	 */
	public function synchronizeCronJobs(
		$entity,
		$enabled,
		$startTime,
		$repeatInterval,
		$actorId,
		$primaryLabel,
		$primaryComment,
		$recoveryLabel,
		$recoveryComment,
		$manageTransaction = true
	) {
		$entity = (int) $entity;
		$startTime = self::normalizeTimeValue($startTime);
		$repeatInterval = self::normalizeTimeValue($repeatInterval);
		if ($entity <= 0) {
			$this->error = 'Invalid entity for product price cron registration';
			return false;
		}

		if ($manageTransaction) {
			$this->db->begin();
		}

		$primaryNext = self::calculateNextRun($startTime, $repeatInterval);
		$primaryUnit = self::repeatIntervalToSeconds($repeatInterval);
		$recoveryNext = dol_now() + self::RETRY_DELAY_SECONDS;
		$test = 'isModEnabled("dolizsynch") && getDolGlobalInt("' . self::CONFIG_ENABLED . '")';

		$ok = $this->upsertCronJob(
			$entity,
			self::PRIMARY_METHOD,
			$primaryLabel,
			$primaryComment,
			$enabled,
			$primaryUnit,
			$primaryNext,
			$actorId,
			$test
		);
		if ($ok) {
			$ok = $this->upsertCronJob(
				$entity,
				self::RECOVERY_METHOD,
				$recoveryLabel,
				$recoveryComment,
				$enabled,
				self::RETRY_DELAY_SECONDS,
				$recoveryNext,
				$actorId,
				$test
			);
		}

		if ($manageTransaction) {
			if ($ok) {
				$this->db->commit();
			} else {
				$this->db->rollback();
			}
		}

		return $ok;
	}

	/**
	 * Return the current entity's eligible product IDs without external writes.
	 *
	 * @param int $entity Entity ID
	 * @param int $store ZSBMS store ID
	 * @return array|false
	 */
	public function fetchEligibleProductIds($entity, $store)
	{
		global $conf;

		$entity = (int) $entity;
		$store = (int) $store;
		if ($entity <= 0 || $store <= 0) {
			$this->error = 'A valid entity and ZSBMS store are required';
			return false;
		}
		if ((int) ($conf->entity ?? 0) !== $entity) {
			$this->error = 'Product eligibility must be evaluated in the active Dolibarr entity context';
			return false;
		}

		$sql = 'SELECT product.rowid, zsproduct.retalho_by_store';
		$sql .= ' FROM ' . MAIN_DB_PREFIX . 'product product';
		$sql .= ' INNER JOIN ' . MAIN_DB_PREFIX . 'product_extrafields product_extra';
		$sql .= ' ON product_extra.fk_object=product.rowid AND product_extra.zs_synch=1';
		$sql .= ' INNER JOIN ' . MAIN_DB_PREFIX . 'dolizsynch_zsproduct zsproduct';
		$sql .= ' ON zsproduct.fk_product=product.rowid';
		$sql .= ' WHERE product.entity IN (' . getEntity('product') . ')';
		$sql .= ' AND product.tosell=1 AND product.fk_product_type=0';
		$sql .= ' AND zsproduct.composto IN (0, 4)';
		$sql .= ' ORDER BY product.rowid ASC';

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return false;
		}

		$productIds = array();
		while ($row = $this->db->fetch_object($resql)) {
			if (self::isStoreAvailabilityActive($row->retalho_by_store, $store)) {
				$productIds[(int) $row->rowid] = (int) $row->rowid;
			}
		}
		$this->db->free($resql);

		return array_values($productIds);
	}

	/**
	 * Test whether an availability JSON value marks the requested store as Venda.
	 *
	 * @param mixed $rawAvailability Stored retalho_by_store JSON
	 * @param int $store ZSBMS store ID
	 * @return bool
	 */
	public static function isStoreAvailabilityActive($rawAvailability, $store)
	{
		$store = (int) $store;
		if ($store <= 0) {
			return false;
		}

		$availability = is_array($rawAvailability)
			? $rawAvailability
			: json_decode((string) $rawAvailability, true);
		if (!is_array($availability)) {
			return false;
		}

		$key = (string) $store;
		return array_key_exists($key, $availability) && (string) $availability[$key] === '1';
	}

	/**
	 * Execute or resume an entity-owned queue.
	 *
	 * @param bool $recoveryOnly Whether this is the recovery cron
	 * @return int
	 */
	private function processEntityRun($recoveryOnly)
	{
		global $conf;

		$this->error = '';
		$this->output = '';
		if (!getDolGlobalInt(self::CONFIG_ENABLED)) {
			$this->output = 'Product price synchronization is disabled for this entity';
			return 0;
		}

		$entity = !empty($conf->entity) ? (int) $conf->entity : 0;
		$store = getDolGlobalInt('ZS_API_STORE');
		if ($entity <= 0 || $store <= 0) {
			$this->error = 'The active entity has no valid ZSBMS store configuration';
			return -1;
		}

		$run = $this->fetchActiveRun($entity);
		if (!$run && !$recoveryOnly) {
			$run = $this->createRunSnapshot($entity, $store);
		}
		if (!$run) {
			if ($this->error !== '') {
				return -1;
			}
			$this->output = 'No interrupted product price synchronization requires recovery';
			return 0;
		}
		if ((int) $run->status === self::RUN_COMPLETED) {
			$this->output = 'No eligible products were found for this entity and store';
			return 0;
		}

		$workerToken = $this->acquireRunLease((int) $run->rowid, $entity);
		if ($workerToken === '') {
			if ($this->error !== '') {
				return -1;
			}
			$this->output = 'Another product price synchronization worker is still active';
			return 0;
		}

		$result = 0;
		try {
			$result = $this->processRunItems((int) $run->rowid, $entity, $store, $workerToken);
		} catch (Throwable $e) {
			$this->error = $e->getMessage();
			dol_syslog(__METHOD__ . ': ' . $this->error, LOG_ERR);
			$result = -1;
		} finally {
			$this->releaseRunLease((int) $run->rowid, $entity, $workerToken);
		}

		return $result;
	}

	/**
	 * Process every currently claimable item in one run.
	 *
	 * @param int $runId Run ID
	 * @param int $entity Entity ID
	 * @param int $store ZSBMS store
	 * @param string $workerToken Worker lease token
	 * @return int
	 */
	private function processRunItems($runId, $entity, $store, $workerToken)
	{
		require_once DOL_DOCUMENT_ROOT . '/product/class/product.class.php';
		if ($this->productSynch === null) {
			require_once __DIR__ . '/zsprodsynch.class.php';
			$this->productSynch = new ZSProductSynch($this->db);
		}

		$processed = 0;
		$synchronized = 0;
		$skipped = 0;
		$failed = 0;
		while (true) {
			$item = $this->claimNextItem($runId, $entity, $workerToken);
			if (!$item) {
				if ($this->error !== '') {
					return -1;
				}
				break;
			}

			$processed++;
			if (!$this->extendRunLease($runId, $entity, $workerToken)) {
				return -1;
			}
			$productId = (int) $item->fk_product;
			if (!$this->isProductEligibleNow($productId, $entity, $store)) {
				if ($this->error !== '') {
					return -1;
				}
				if (!$this->finishItem((int) $item->rowid, $entity, $workerToken, self::ITEM_SKIPPED, 'Product is no longer eligible in this entity')) {
					return -1;
				}
				$skipped++;
				continue;
			}

			$product = new Product($this->db);
			if ($product->fetch($productId) <= 0) {
				if (!$this->finishItem((int) $item->rowid, $entity, $workerToken, self::ITEM_SKIPPED, 'Product is no longer available')) {
					return -1;
				}
				$skipped++;
				continue;
			}
			if (method_exists($product, 'fetch_optionals')) {
				$product->fetch_optionals($productId);
			}

			try {
				$ok = $this->productSynch->updateZsbmsProductPricesForStore($product, $store);
			} catch (Throwable $e) {
				$ok = false;
				$this->productSynch->error = $e->getMessage();
			}

			if ($ok) {
				if (!$this->finishItem((int) $item->rowid, $entity, $workerToken, self::ITEM_SYNCHRONIZED, '')) {
					return -1;
				}
				$synchronized++;
			} else {
				$error = trim((string) ($this->productSynch->error ?? 'Unknown ZSBMS product synchronization error'));
				$status = ((int) $item->attempts >= self::MAX_ATTEMPTS) ? self::ITEM_FAILED : self::ITEM_RETRY;
				if (!$this->finishItem((int) $item->rowid, $entity, $workerToken, $status, $error)) {
					return -1;
				}
				if ($status === self::ITEM_FAILED) {
					$failed++;
				}
			}
		}

		$completion = $this->completeRunIfTerminal($runId, $entity, $workerToken);
		$this->output = 'Processed=' . $processed . ', synchronized=' . $synchronized
			. ', skipped=' . $skipped . ', failed=' . $failed;
		dol_syslog(__METHOD__ . ': entity=' . $entity . ', store=' . $store . ', ' . $this->output, $completion < 0 ? LOG_WARNING : LOG_INFO);

		return $completion < 0 ? -1 : 0;
	}

	/**
	 * Create an immutable snapshot of currently eligible products.
	 *
	 * @param int $entity Entity ID
	 * @param int $store ZSBMS store
	 * @return object|false
	 */
	private function createRunSnapshot($entity, $store)
	{
		$this->purgeCompletedRuns($entity);
		$this->db->begin();
		$productIds = $this->fetchEligibleProductIds($entity, $store);
		if ($productIds === false) {
			$this->db->rollback();
			return false;
		}

		$nowSql = $this->db->idate(dol_now());
		$sql = 'INSERT INTO ' . MAIN_DB_PREFIX . 'dolizsynch_product_price_sync_run';
		$sql .= ' (entity, active_key, store_id, status, scheduled_for, date_creation, total_count) VALUES (';
		$sql .= $entity . ', ' . $entity . ', ' . $store . ', ' . self::RUN_SEEDING . ", '" . $nowSql . "', '" . $nowSql . "', " . count($productIds) . ')';
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			$this->db->rollback();
			$active = $this->fetchActiveRun($entity);
			if ($active) {
				$this->error = '';
				return $active;
			}
			return false;
		}

		$runId = (int) $this->db->last_insert_id(MAIN_DB_PREFIX . 'dolizsynch_product_price_sync_run');
		foreach ($productIds as $productId) {
			$sql = 'INSERT INTO ' . MAIN_DB_PREFIX . 'dolizsynch_product_price_sync_item';
			$sql .= ' (entity, fk_run, fk_product, status, attempts, date_creation) VALUES (';
			$sql .= $entity . ', ' . $runId . ', ' . ((int) $productId) . ', ' . self::ITEM_PENDING . ", 0, '" . $nowSql . "')";
			if (!$this->db->query($sql)) {
				$this->error = $this->db->lasterror();
				$this->db->rollback();
				return false;
			}
		}

		if (empty($productIds)) {
			$sql = 'UPDATE ' . MAIN_DB_PREFIX . 'dolizsynch_product_price_sync_run';
			$sql .= ' SET status=' . self::RUN_COMPLETED . ', active_key=NULL, started_at=\'' . $nowSql . '\', completed_at=\'' . $nowSql . '\'';
			$sql .= ' WHERE rowid=' . $runId . ' AND entity=' . $entity;
		} else {
			$sql = 'UPDATE ' . MAIN_DB_PREFIX . 'dolizsynch_product_price_sync_run';
			$sql .= ' SET status=' . self::RUN_ACTIVE . ', started_at=\'' . $nowSql . '\', heartbeat_at=\'' . $nowSql . '\'';
			$sql .= ' WHERE rowid=' . $runId . ' AND entity=' . $entity;
		}
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			$this->db->rollback();
			return false;
		}

		$this->db->commit();
		return $this->fetchRun($runId, $entity);
	}

	/**
	 * Fetch an active run.
	 *
	 * @param int $entity Entity ID
	 * @return object|false
	 */
	private function fetchActiveRun($entity)
	{
		$sql = 'SELECT * FROM ' . MAIN_DB_PREFIX . 'dolizsynch_product_price_sync_run';
		$sql .= ' WHERE entity=' . ((int) $entity) . ' AND active_key=' . ((int) $entity);
		$sql .= ' AND status IN (' . self::RUN_SEEDING . ', ' . self::RUN_ACTIVE . ')';
		$sql .= ' ORDER BY rowid DESC ' . $this->db->plimit(1);
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return false;
		}
		$row = $this->db->fetch_object($resql);
		$this->db->free($resql);
		return $row ?: false;
	}

	/**
	 * Fetch one run.
	 *
	 * @param int $runId Run ID
	 * @param int $entity Entity ID
	 * @return object|false
	 */
	private function fetchRun($runId, $entity)
	{
		$sql = 'SELECT * FROM ' . MAIN_DB_PREFIX . 'dolizsynch_product_price_sync_run';
		$sql .= ' WHERE rowid=' . ((int) $runId) . ' AND entity=' . ((int) $entity);
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return false;
		}
		$row = $this->db->fetch_object($resql);
		$this->db->free($resql);
		return $row ?: false;
	}

	/**
	 * Acquire the single worker lease for a run.
	 *
	 * @param int $runId Run ID
	 * @param int $entity Entity ID
	 * @return string Empty when another worker owns the lease
	 */
	private function acquireRunLease($runId, $entity)
	{
		try {
			$token = bin2hex(random_bytes(16));
		} catch (Throwable $e) {
			$token = sha1(uniqid((string) mt_rand(), true));
		}
		$nowSql = $this->db->idate(dol_now());
		$leaseSql = $this->db->idate(dol_now() + self::WORKER_LEASE_SECONDS);
		$sql = 'UPDATE ' . MAIN_DB_PREFIX . 'dolizsynch_product_price_sync_run SET';
		$sql .= " worker_token='" . $this->db->escape($token) . "', worker_lease_until='" . $leaseSql . "', heartbeat_at='" . $nowSql . "'";
		$sql .= ' WHERE rowid=' . ((int) $runId) . ' AND entity=' . ((int) $entity);
		$sql .= ' AND status=' . self::RUN_ACTIVE;
		$sql .= " AND (worker_token IS NULL OR worker_token='' OR worker_lease_until IS NULL OR worker_lease_until<='" . $nowSql . "')";
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return '';
		}
		if ($this->db->affected_rows($resql) !== 1) {
			return '';
		}
		return $token;
	}

	/**
	 * Extend the current worker lease.
	 *
	 * @param int $runId Run ID
	 * @param int $entity Entity ID
	 * @param string $token Worker token
	 * @return bool
	 */
	private function extendRunLease($runId, $entity, $token)
	{
		$nowSql = $this->db->idate(dol_now());
		$leaseSql = $this->db->idate(dol_now() + self::WORKER_LEASE_SECONDS);
		$sql = 'UPDATE ' . MAIN_DB_PREFIX . 'dolizsynch_product_price_sync_run SET';
		$sql .= " worker_lease_until='" . $leaseSql . "', heartbeat_at='" . $nowSql . "'";
		$sql .= ' WHERE rowid=' . ((int) $runId) . ' AND entity=' . ((int) $entity);
		$sql .= " AND worker_token='" . $this->db->escape($token) . "'";
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return false;
		}
		if ($this->db->affected_rows($resql) !== 1) {
			$sql = 'SELECT rowid FROM ' . MAIN_DB_PREFIX . 'dolizsynch_product_price_sync_run';
			$sql .= ' WHERE rowid=' . ((int) $runId) . ' AND entity=' . ((int) $entity);
			$sql .= " AND worker_token='" . $this->db->escape($token) . "'";
			$sql .= " AND worker_lease_until>'" . $nowSql . "'" . $this->db->plimit(1);
			$check = $this->db->query($sql);
			$ownedRun = $check ? $this->db->fetch_object($check) : null;
			if ($check) {
				$this->db->free($check);
			}
			if (!$ownedRun) {
				$this->error = $check ? 'The product price synchronization worker lease was lost' : $this->db->lasterror();
				return false;
			}
		}
		return true;
	}

	/**
	 * Release a worker lease owned by this process.
	 *
	 * @param int $runId Run ID
	 * @param int $entity Entity ID
	 * @param string $token Worker token
	 * @return void
	 */
	private function releaseRunLease($runId, $entity, $token)
	{
		$sql = 'UPDATE ' . MAIN_DB_PREFIX . 'dolizsynch_product_price_sync_run';
		$sql .= ' SET worker_token=NULL, worker_lease_until=NULL';
		$sql .= ' WHERE rowid=' . ((int) $runId) . ' AND entity=' . ((int) $entity);
		$sql .= " AND worker_token='" . $this->db->escape($token) . "'";
		$this->db->query($sql);
	}

	/**
	 * Atomically claim one pending or due retry item.
	 *
	 * @param int $runId Run ID
	 * @param int $entity Entity ID
	 * @param string $workerToken Worker token
	 * @return object|false
	 */
	private function claimNextItem($runId, $entity, $workerToken)
	{
		$nowSql = $this->db->idate(dol_now());
		$this->db->begin();
		$sql = 'SELECT rowid FROM ' . MAIN_DB_PREFIX . 'dolizsynch_product_price_sync_run';
		$sql .= ' WHERE rowid=' . ((int) $runId) . ' AND entity=' . ((int) $entity);
		$sql .= ' AND status=' . self::RUN_ACTIVE;
		$sql .= " AND worker_token='" . $this->db->escape($workerToken) . "'";
		$sql .= " AND worker_lease_until>'" . $nowSql . "' FOR UPDATE";
		$resql = $this->db->query($sql);
		$ownedRun = $resql ? $this->db->fetch_object($resql) : null;
		if (!$resql) {
			$this->error = $this->db->lasterror();
			$this->db->rollback();
			return false;
		}
		if (!$ownedRun) {
			$this->error = 'The product price synchronization worker no longer owns this run';
			$this->db->rollback();
			return false;
		}

		$sql = 'UPDATE ' . MAIN_DB_PREFIX . 'dolizsynch_product_price_sync_item';
		$sql .= ' SET status=' . self::ITEM_FAILED . ', worker_token=NULL, lease_until=NULL, next_attempt_at=NULL,';
		$sql .= " error_message='Worker stopped after the final permitted attempt'";
		$sql .= ' WHERE fk_run=' . ((int) $runId) . ' AND entity=' . ((int) $entity);
		$sql .= ' AND status=' . self::ITEM_PROCESSING . ' AND attempts>=' . self::MAX_ATTEMPTS;
		$sql .= " AND lease_until IS NOT NULL AND lease_until<='" . $nowSql . "'";
		if (!$this->db->query($sql)) {
			$this->db->rollback();
			$this->error = $this->db->lasterror();
			return false;
		}

		$sql = 'UPDATE ' . MAIN_DB_PREFIX . 'dolizsynch_product_price_sync_item';
		$sql .= ' SET status=' . self::ITEM_RETRY . ", worker_token=NULL, lease_until=NULL, next_attempt_at='" . $nowSql . "'";
		$sql .= ' WHERE fk_run=' . ((int) $runId) . ' AND entity=' . ((int) $entity);
		$sql .= ' AND status=' . self::ITEM_PROCESSING . ' AND attempts<' . self::MAX_ATTEMPTS;
		$sql .= " AND lease_until IS NOT NULL AND lease_until<='" . $nowSql . "'";
		if (!$this->db->query($sql)) {
			$this->db->rollback();
			$this->error = $this->db->lasterror();
			return false;
		}

		$sql = 'SELECT * FROM ' . MAIN_DB_PREFIX . 'dolizsynch_product_price_sync_item';
		$sql .= ' WHERE fk_run=' . ((int) $runId) . ' AND entity=' . ((int) $entity);
		$sql .= ' AND attempts<' . self::MAX_ATTEMPTS;
		$sql .= ' AND (status=' . self::ITEM_PENDING;
		$sql .= ' OR (status=' . self::ITEM_RETRY . " AND (next_attempt_at IS NULL OR next_attempt_at<='" . $nowSql . "')))";
		$sql .= ' ORDER BY rowid ASC ' . $this->db->plimit(1) . ' FOR UPDATE';
		$resql = $this->db->query($sql);
		$item = $resql ? $this->db->fetch_object($resql) : null;
		if (!$resql) {
			$this->error = $this->db->lasterror();
			$this->db->rollback();
			return false;
		}
		if (!$item) {
			$this->db->commit();
			return false;
		}

		$leaseSql = $this->db->idate(dol_now() + self::WORKER_LEASE_SECONDS);
		$sql = 'UPDATE ' . MAIN_DB_PREFIX . 'dolizsynch_product_price_sync_item SET';
		$sql .= ' status=' . self::ITEM_PROCESSING . ', attempts=attempts+1,';
		$sql .= " worker_token='" . $this->db->escape($workerToken) . "', lease_until='" . $leaseSql;
		$sql .= "', last_attempt_at='" . $nowSql . "', next_attempt_at=NULL";
		$sql .= ' WHERE rowid=' . ((int) $item->rowid) . ' AND entity=' . ((int) $entity);
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			$this->db->rollback();
			return false;
		}
		$this->db->commit();
		$item->attempts = ((int) $item->attempts) + 1;
		return $item;
	}

	/**
	 * Mark one queue item after processing.
	 *
	 * @param int $itemId Item ID
	 * @param int $entity Entity ID
	 * @param string $workerToken Worker token that owns the run
	 * @param string $workerToken Worker token that owns the item
	 * @param int $status Final/retry status
	 * @param string $error Error or skip reason
	 * @return bool
	 */
	private function finishItem($itemId, $entity, $workerToken, $status, $error)
	{
		$nowSql = $this->db->idate(dol_now());
		$sql = 'UPDATE ' . MAIN_DB_PREFIX . 'dolizsynch_product_price_sync_item SET';
		$sql .= ' status=' . ((int) $status) . ', worker_token=NULL, lease_until=NULL,';
		$sql .= " error_message='" . $this->db->escape((string) $error) . "'";
		if ($status === self::ITEM_SYNCHRONIZED) {
			$sql .= ", synchronized_at='" . $nowSql . "'";
		}
		if ($status === self::ITEM_RETRY) {
			$sql .= ", next_attempt_at='" . $this->db->idate(dol_now() + self::RETRY_DELAY_SECONDS) . "'";
		} else {
			$sql .= ', next_attempt_at=NULL';
		}
		$sql .= ' WHERE rowid=' . ((int) $itemId) . ' AND entity=' . ((int) $entity);
		$sql .= ' AND status=' . self::ITEM_PROCESSING;
		$sql .= " AND worker_token='" . $this->db->escape($workerToken) . "'";
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return false;
		}
		if ($this->db->affected_rows($resql) !== 1) {
			$this->error = 'The product price queue item lease was lost before its result could be recorded';
			return false;
		}
		return true;
	}

	/**
	 * Recheck every eligibility boundary immediately before the API call.
	 *
	 * @param int $productId Product ID
	 * @param int $entity Entity ID
	 * @param int $store ZSBMS store
	 * @return bool
	 */
	private function isProductEligibleNow($productId, $entity, $store)
	{
		$sql = 'SELECT zsproduct.retalho_by_store FROM ' . MAIN_DB_PREFIX . 'product product';
		$sql .= ' INNER JOIN ' . MAIN_DB_PREFIX . 'product_extrafields product_extra';
		$sql .= ' ON product_extra.fk_object=product.rowid AND product_extra.zs_synch=1';
		$sql .= ' INNER JOIN ' . MAIN_DB_PREFIX . 'dolizsynch_zsproduct zsproduct';
		$sql .= ' ON zsproduct.fk_product=product.rowid';
		$sql .= ' WHERE product.rowid=' . ((int) $productId);
		$sql .= ' AND product.entity IN (' . getEntity('product') . ')';
		$sql .= ' AND product.tosell=1 AND product.fk_product_type=0';
		$sql .= ' AND zsproduct.composto IN (0, 4)';
		$sql .= ' ' . $this->db->plimit(1);
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return false;
		}
		$row = $this->db->fetch_object($resql);
		$this->db->free($resql);
		return $row && self::isStoreAvailabilityActive($row->retalho_by_store, $store);
	}

	/**
	 * Complete a run only when every item is terminal.
	 *
	 * @param int $runId Run ID
	 * @param int $entity Entity ID
	 * @return int 1 complete, 0 still pending, -1 complete with failures
	 */
	private function completeRunIfTerminal($runId, $entity, $workerToken)
	{
		$sql = 'SELECT status, COUNT(*) AS item_count FROM ' . MAIN_DB_PREFIX . 'dolizsynch_product_price_sync_item';
		$sql .= ' WHERE fk_run=' . ((int) $runId) . ' AND entity=' . ((int) $entity) . ' GROUP BY status';
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		$counts = array();
		while ($row = $this->db->fetch_object($resql)) {
			$counts[(int) $row->status] = (int) $row->item_count;
		}
		$this->db->free($resql);
		$openCount = ($counts[self::ITEM_PENDING] ?? 0) + ($counts[self::ITEM_PROCESSING] ?? 0) + ($counts[self::ITEM_RETRY] ?? 0);
		if ($openCount > 0) {
			return 0;
		}

		$failed = $counts[self::ITEM_FAILED] ?? 0;
		$synchronized = $counts[self::ITEM_SYNCHRONIZED] ?? 0;
		$skipped = $counts[self::ITEM_SKIPPED] ?? 0;
		$status = $failed > 0 ? self::RUN_COMPLETED_WITH_ERRORS : self::RUN_COMPLETED;
		$nowSql = $this->db->idate(dol_now());
		$sql = 'UPDATE ' . MAIN_DB_PREFIX . 'dolizsynch_product_price_sync_run SET';
		$sql .= ' status=' . $status . ', active_key=NULL, completed_at=\'' . $nowSql . '\',';
		$sql .= ' synchronized_count=' . ((int) $synchronized) . ', skipped_count=' . ((int) $skipped) . ', error_count=' . ((int) $failed);
		$sql .= ' WHERE rowid=' . ((int) $runId) . ' AND entity=' . ((int) $entity);
		$sql .= " AND worker_token='" . $this->db->escape($workerToken) . "'";
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		if ($this->db->affected_rows($resql) !== 1) {
			$this->error = 'The product price synchronization worker lease was lost before run completion';
			return -1;
		}
		if ($failed > 0) {
			$this->error = $failed . ' product price synchronization item(s) failed after ' . self::MAX_ATTEMPTS . ' attempts';
			return -1;
		}
		return 1;
	}

	/**
	 * Remove completed queue evidence after the retention period.
	 *
	 * @param int $entity Entity ID
	 * @return void
	 */
	private function purgeCompletedRuns($entity)
	{
		$cutoff = $this->db->idate(dol_now() - (self::COMPLETED_RETENTION_DAYS * 86400));
		$sql = 'DELETE item FROM ' . MAIN_DB_PREFIX . 'dolizsynch_product_price_sync_item item';
		$sql .= ' INNER JOIN ' . MAIN_DB_PREFIX . 'dolizsynch_product_price_sync_run run ON run.rowid=item.fk_run';
		$sql .= ' WHERE run.entity=' . ((int) $entity) . ' AND run.active_key IS NULL';
		$sql .= " AND run.completed_at IS NOT NULL AND run.completed_at<'" . $cutoff . "'";
		if (!$this->db->query($sql)) {
			dol_syslog(__METHOD__ . ': unable to purge retained items: ' . $this->db->lasterror(), LOG_WARNING);
			return;
		}
		$sql = 'DELETE FROM ' . MAIN_DB_PREFIX . 'dolizsynch_product_price_sync_run';
		$sql .= ' WHERE entity=' . ((int) $entity) . ' AND active_key IS NULL';
		$sql .= " AND completed_at IS NOT NULL AND completed_at<'" . $cutoff . "'";
		if (!$this->db->query($sql)) {
			dol_syslog(__METHOD__ . ': unable to purge retained runs: ' . $this->db->lasterror(), LOG_WARNING);
		}
	}

	/**
	 * Insert or normalize one entity-owned cron row while preserving its history.
	 *
	 * @param int $entity Entity ID
	 * @param string $method Method name
	 * @param string $label Label
	 * @param string $comment Comment
	 * @param bool $enabled Status
	 * @param int $unitFrequency Seconds
	 * @param int $nextRun Next run timestamp
	 * @param int $actorId User ID
	 * @param string $test Runtime condition
	 * @return bool
	 */
	private function upsertCronJob($entity, $method, $label, $comment, $enabled, $unitFrequency, $nextRun, $actorId, $test)
	{
		$table = MAIN_DB_PREFIX . 'cronjob';
		$sql = 'SELECT rowid, COALESCE(nbrun, 0) AS nbrun, datelastrun FROM ' . $table;
		$sql .= " WHERE module_name='dolizsynch' AND entity=" . ((int) $entity);
		$sql .= " AND objectname='ZSProductPriceCron' AND methodename='" . $this->db->escape($method) . "'";
		$sql .= ' ORDER BY COALESCE(nbrun, 0) DESC, datelastrun DESC, rowid ASC';
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return false;
		}
		$rows = array();
		while ($row = $this->db->fetch_object($resql)) {
			$rows[] = (int) $row->rowid;
		}
		$this->db->free($resql);

		$nextRunSql = $this->db->idate((int) $nextRun);
		$nowSql = $this->db->idate(dol_now());
		$fields = array(
			"label='" . $this->db->escape((string) $label) . "'",
			"jobtype='method'",
			"classesname='/kreaflow/class/zsproductpricecron.class.php'",
			"objectname='ZSProductPriceCron'",
			"methodename='" . $this->db->escape($method) . "'",
			"params=''",
			"md5params='" . md5('') . "'",
			"module_name='dolizsynch'",
			'priority=2',
			'frequency=1',
			'unitfrequency=' . ((int) $unitFrequency),
			'maxrun=0',
			'autodelete=0',
			'status=' . ($enabled ? 1 : 0),
			"test='" . $this->db->escape($test) . "'",
			"note='" . $this->db->escape((string) $comment) . "'",
			"datestart='" . $nextRunSql . "'",
			"datenextrun='" . $nextRunSql . "'",
			'fk_user_mod=' . ((int) $actorId),
		);

		if (empty($rows)) {
			$sql = 'INSERT INTO ' . $table;
			$sql .= ' (datec, label, jobtype, classesname, objectname, methodename, params, md5params, module_name,';
			$sql .= ' priority, frequency, unitfrequency, maxrun, nbrun, autodelete, status, processing, test,';
			$sql .= ' fk_user_author, fk_user_mod, note, datestart, datenextrun, entity) VALUES (';
			$sql .= "'" . $nowSql . "', '" . $this->db->escape((string) $label) . "', 'method',";
			$sql .= " '/kreaflow/class/zsproductpricecron.class.php', 'ZSProductPriceCron', '" . $this->db->escape($method) . "',";
			$sql .= " '', '" . md5('') . "', 'dolizsynch', 2, 1, " . ((int) $unitFrequency) . ', 0, 0, 0,';
			$sql .= ($enabled ? ' 1,' : ' 0,') . " 0, '" . $this->db->escape($test) . "', " . ((int) $actorId) . ', ' . ((int) $actorId) . ',';
			$sql .= " '" . $this->db->escape((string) $comment) . "', '" . $nextRunSql . "', '" . $nextRunSql . "', " . ((int) $entity) . ')';
			if (!$this->db->query($sql)) {
				$this->error = $this->db->lasterror();
				return false;
			}
			return true;
		}

		$keepId = array_shift($rows);
		$sql = 'UPDATE ' . $table . ' SET ' . implode(', ', $fields) . ' WHERE rowid=' . ((int) $keepId) . ' AND entity=' . ((int) $entity);
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			return false;
		}
		if (!empty($rows)) {
			$sql = 'UPDATE ' . $table . ' SET status=0 WHERE entity=' . ((int) $entity) . ' AND rowid IN (' . implode(',', $rows) . ')';
			if (!$this->db->query($sql)) {
				$this->error = $this->db->lasterror();
				return false;
			}
		}

		return true;
	}

	/**
	 * Convert an HH:MM duration to raw seconds, preserving 00:00 as zero.
	 *
	 * @param string $value HH:MM value
	 * @return int
	 */
	private static function repeatIntervalToRawSeconds($value)
	{
		list($hours, $minutes) = array_map('intval', explode(':', self::normalizeTimeValue($value)));
		return ($hours * 3600) + ($minutes * 60);
	}
}

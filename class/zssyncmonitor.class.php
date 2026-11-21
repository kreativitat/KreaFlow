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
 * \file        class/zssyncmonitor.class.php
 * \ingroup     dolizsynch
 * \brief       Sync health monitoring and notifications for DoliZSynch
 */

require_once DOL_DOCUMENT_ROOT . '/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT . '/core/class/CMailFile.class.php';
require_once DOL_DOCUMENT_ROOT . '/custom/kreaflow/class/zssyncstatus.class.php';
require_once DOL_DOCUMENT_ROOT . '/custom/kreaflow/class/trait/zslogger.trait.php';

class ZSSyncMonitor
{
	use ZSLogger;

	/** Sync-health UI and notifications are retained for future development but currently disabled. */
	public const FEATURE_ENABLED = false;

	/** @var DoliDB */
	private $db;

	/** @var array<string,bool> */
	private $tableExistsCache = [];

	public function __construct(DoliDB $db)
	{
		$this->db = $db;
	}

	/**
	 * Get invoice synchronization health summary for the last N hours.
	 *
	 * @param int $windowHours
	 * @return array
	 */
	public function getInvoiceHealthSummary($windowHours)
	{
		global $conf;

		$windowHours = $this->normalizeWindowHours($windowHours);
		$successStatuses = $this->getSuccessStatuses();
		$errorStatuses = $this->getErrorStatuses();
		$processingStatuses = $this->getProcessingStatuses();
		$pendingStatuses = $this->getPendingStatuses();

		$sql = "SELECT";
		$sql .= " COUNT(*) as total,";
		$sql .= " SUM(CASE WHEN synch IN (" . implode(',', $successStatuses) . ")";
		$sql .= " AND synch NOT IN (" . implode(',', $pendingStatuses) . ") THEN 1 ELSE 0 END) as success_count,";
		$sql .= " SUM(CASE WHEN synch IN (" . implode(',', $errorStatuses) . ") THEN 1 ELSE 0 END) as error_count,";
		$sql .= " SUM(CASE WHEN synch IN (" . implode(',', $processingStatuses) . ") THEN 1 ELSE 0 END) as processing_count,";
		$sql .= " SUM(CASE WHEN synch IN (" . implode(',', $pendingStatuses) . ") THEN 1 ELSE 0 END) as pending_count,";
		$sql .= " AVG(CASE WHEN synch IN (" . implode(',', $successStatuses) . ")";
		$sql .= " AND date_creation IS NOT NULL AND date_modification IS NOT NULL";
		$sql .= " AND date_creation >= DATE_SUB(NOW(), INTERVAL " . (int) $windowHours . " HOUR)";
		$sql .= " THEN TIMESTAMPDIFF(SECOND, date_creation, date_modification) END) as avg_latency_seconds";
		$sql .= " FROM " . MAIN_DB_PREFIX . "dolizsynch_zsinvoicesynch";
		$sql .= " WHERE entity = " . (int) $conf->entity;
		$sql .= " AND COALESCE(date_modification, date_creation, tms) >= DATE_SUB(NOW(), INTERVAL " . (int) $windowHours . " HOUR)";

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->logOperationError(__METHOD__, "Failed to fetch invoice health summary", [
				'sql_error' => $this->db->lasterror(),
			]);

			return [
				'window_hours' => $windowHours,
				'total' => 0,
				'success_count' => 0,
				'error_count' => 0,
				'processing_count' => 0,
				'pending_count' => 0,
				'processed_total' => 0,
				'success_rate' => 0,
				'avg_latency_seconds' => null,
				'p95_latency_seconds' => null,
				'max_latency_seconds' => null,
				'generated_at' => time(),
			];
		}

		$obj = $this->db->fetch_object($resql);
		$total = (int) ($obj->total ?? 0);
		$successCount = (int) ($obj->success_count ?? 0);
		$errorCount = (int) ($obj->error_count ?? 0);
		$processingCount = (int) ($obj->processing_count ?? 0);
		$pendingCount = (int) ($obj->pending_count ?? 0);
		$processedTotal = $successCount + $errorCount;
		$successRate = $processedTotal > 0 ? round(($successCount / $processedTotal) * 100, 2) : 0;
		$avgLatencySeconds = $obj->avg_latency_seconds !== null ? (float) $obj->avg_latency_seconds : null;

		$latencyStats = $this->getLatencyStatistics($windowHours, $successStatuses);

		return [
			'window_hours' => $windowHours,
			'total' => $total,
			'success_count' => $successCount,
			'error_count' => $errorCount,
			'processing_count' => $processingCount,
			'pending_count' => $pendingCount,
			'processed_total' => $processedTotal,
			'success_rate' => $successRate,
			'avg_latency_seconds' => $avgLatencySeconds,
			'p95_latency_seconds' => $latencyStats['p95'],
			'max_latency_seconds' => $latencyStats['max'],
			'generated_at' => time(),
		];
	}

	/**
	 * Get error trend by day for the last N days.
	 *
	 * @param int $days
	 * @return array
	 */
	public function getInvoiceErrorTrend($days)
	{
		global $conf;

		$days = $this->normalizeTrendDays($days);
		$errorStatuses = $this->getErrorStatuses();

		$sql = "SELECT DATE(COALESCE(date_modification, date_creation, tms)) as day, COUNT(*) as count";
		$sql .= " FROM " . MAIN_DB_PREFIX . "dolizsynch_zsinvoicesynch";
		$sql .= " WHERE entity = " . (int) $conf->entity;
		$sql .= " AND synch IN (" . implode(',', $errorStatuses) . ")";
		$sql .= " AND COALESCE(date_modification, date_creation, tms) >= DATE_SUB(CURDATE(), INTERVAL " . (int) ($days - 1) . " DAY)";
		$sql .= " GROUP BY day ORDER BY day ASC";

		$trendMap = [];
		$startDate = new DateTime('-' . ($days - 1) . ' days');
		for ($i = 0; $i < $days; $i++) {
			$trendMap[$startDate->format('Y-m-d')] = 0;
			$startDate->modify('+1 day');
		}

		$resql = $this->db->query($sql);
		if ($resql) {
			while ($obj = $this->db->fetch_object($resql)) {
				$day = $obj->day;
				$trendMap[$day] = (int) $obj->count;
			}
		} else {
			$this->logOperationError(__METHOD__, "Failed to fetch error trend", [
				'sql_error' => $this->db->lasterror(),
			]);
		}

		$trend = [];
		foreach ($trendMap as $day => $count) {
			$trend[] = [
				'date' => $day,
				'count' => $count,
			];
		}

		return $trend;
	}

	/**
	 * Get top error messages for the last N hours.
	 *
	 * @param int $windowHours
	 * @param int $limit
	 * @return array
	 */
	public function getTopInvoiceErrors($windowHours, $limit = 10)
	{
		global $conf;

		$windowHours = $this->normalizeWindowHours($windowHours);
		$limit = max(1, (int) $limit);
		$errorStatuses = $this->getErrorStatuses();

		$sql = "SELECT error_message, COUNT(*) as count";
		$sql .= " FROM " . MAIN_DB_PREFIX . "dolizsynch_zsinvoicesynch";
		$sql .= " WHERE entity = " . (int) $conf->entity;
		$sql .= " AND synch IN (" . implode(',', $errorStatuses) . ")";
		$sql .= " AND error_message IS NOT NULL AND error_message <> ''";
		$sql .= " AND COALESCE(date_modification, date_creation, tms) >= DATE_SUB(NOW(), INTERVAL " . (int) $windowHours . " HOUR)";
		$sql .= " GROUP BY error_message ORDER BY count DESC";
		$sql .= $this->db->plimit($limit, 0);

		$results = [];
		$resql = $this->db->query($sql);
		if ($resql) {
			while ($obj = $this->db->fetch_object($resql)) {
				$results[] = [
					'message' => (string) $obj->error_message,
					'count' => (int) $obj->count,
				];
			}
		} else {
			$this->logOperationError(__METHOD__, "Failed to fetch top errors", [
				'sql_error' => $this->db->lasterror(),
			]);
		}

		return $results;
	}

	/**
	 * Get product synchronization health summary for the last N hours.
	 *
	 * @param int $windowHours
	 * @return array
	 */
	public function getProductHealthSummary($windowHours)
	{
		global $conf;

		$windowHours = $this->normalizeWindowHours($windowHours);
		$entity = (int) $conf->entity;

		$summary = [
			'window_hours' => $windowHours,
			'available' => false,
			'total_products' => 0,
			'enabled_for_sync' => 0,
			'mapped_products' => 0,
			'enabled_unmapped' => 0,
			'recent_local_updates' => 0,
			'recent_error_count' => 0,
			'last_local_update' => null,
			'generated_at' => time(),
		];

		if (
			!$this->tableExists('product')
			|| !$this->tableExists('product_extrafields')
			|| !$this->tableExists('dolizsynch_zsproduct')
		) {
			return $summary;
		}

		$summary['available'] = true;

		$sql = "SELECT";
		$sql .= " COUNT(p.rowid) as total_products,";
		$sql .= " SUM(CASE WHEN COALESCE(pe.zs_synch, 0) = 1 THEN 1 ELSE 0 END) as enabled_for_sync,";
		$sql .= " SUM(CASE WHEN COALESCE(pe.zs_synch, 0) = 1 AND zsmap.fk_product IS NULL THEN 1 ELSE 0 END) as enabled_unmapped";
		$sql .= " FROM " . MAIN_DB_PREFIX . "product as p";
		$sql .= " LEFT JOIN " . MAIN_DB_PREFIX . "product_extrafields as pe ON pe.fk_object = p.rowid";
		$sql .= " LEFT JOIN (";
		$sql .= " SELECT DISTINCT fk_product FROM " . MAIN_DB_PREFIX . "dolizsynch_zsproduct";
		$sql .= " WHERE fk_product IS NOT NULL";
		$sql .= " AND entity IN (0, " . $entity . ")";
		$sql .= " ) as zsmap ON zsmap.fk_product = p.rowid";
		$sql .= " WHERE p.entity IN (0, " . $entity . ")";

		$resql = $this->db->query($sql);
		if ($resql) {
			$obj = $this->db->fetch_object($resql);
			$summary['total_products'] = (int) ($obj->total_products ?? 0);
			$summary['enabled_for_sync'] = (int) ($obj->enabled_for_sync ?? 0);
			$summary['enabled_unmapped'] = (int) ($obj->enabled_unmapped ?? 0);
			$this->db->free($resql);
		} else {
			$this->logOperationError(__METHOD__, "Failed to fetch product enablement summary", [
				'sql_error' => $this->db->lasterror(),
			]);
		}

		$sqlMap = "SELECT";
		$sqlMap .= " COUNT(DISTINCT fk_product) as mapped_products,";
		$sqlMap .= " SUM(CASE WHEN tms >= DATE_SUB(NOW(), INTERVAL " . (int) $windowHours . " HOUR) THEN 1 ELSE 0 END) as recent_local_updates,";
		$sqlMap .= " MAX(tms) as last_local_update";
		$sqlMap .= " FROM " . MAIN_DB_PREFIX . "dolizsynch_zsproduct";
		$sqlMap .= " WHERE entity IN (0, " . $entity . ")";
		$sqlMap .= " AND fk_product IS NOT NULL";

		$resqlMap = $this->db->query($sqlMap);
		if ($resqlMap) {
			$objMap = $this->db->fetch_object($resqlMap);
			$summary['mapped_products'] = (int) ($objMap->mapped_products ?? 0);
			$summary['recent_local_updates'] = (int) ($objMap->recent_local_updates ?? 0);
			$summary['last_local_update'] = $objMap->last_local_update ?? null;
			$this->db->free($resqlMap);
		} else {
			$this->logOperationError(__METHOD__, "Failed to fetch product mapping summary", [
				'sql_error' => $this->db->lasterror(),
			]);
		}

		if ($this->tableExists('dolizsynch_error_log')) {
			$sqlErr = "SELECT COUNT(*) as cnt";
			$sqlErr .= " FROM " . MAIN_DB_PREFIX . "dolizsynch_error_log";
			$sqlErr .= " WHERE entity = " . $entity;
			$sqlErr .= " AND date_creation >= DATE_SUB(NOW(), INTERVAL " . (int) $windowHours . " HOUR)";
			$sqlErr .= " AND (category = 'product_sync' OR category LIKE 'product%')";

			$resqlErr = $this->db->query($sqlErr);
			if ($resqlErr) {
				$objErr = $this->db->fetch_object($resqlErr);
				$summary['recent_error_count'] = (int) ($objErr->cnt ?? 0);
				$this->db->free($resqlErr);
			}
		}

		return $summary;
	}

	/**
	 * Get punch clock synchronization health summary for the last N hours.
	 *
	 * @param int $windowHours
	 * @return array
	 */
	public function getPunchclockHealthSummary($windowHours)
	{
		global $conf;

		$windowHours = $this->normalizeWindowHours($windowHours);
		$entity = (int) $conf->entity;

		$summary = [
			'window_hours' => $windowHours,
			'available' => false,
			'total_rows' => 0,
			'pending_rows' => 0,
			'unpaired_rows' => 0,
			'synthetic_rows' => 0,
			'recent_deleted_rows' => 0,
			'recent_imported_rows' => 0,
			'integrated_recent' => 0,
			'pending_integration' => 0,
			'generated_at' => time(),
		];

		if (!$this->tableExists('dolizsynch_zspunchclock_synch')) {
			return $summary;
		}

		$summary['available'] = true;

		$sql = "SELECT";
		$sql .= " COUNT(*) as total_rows,";
		$sql .= " SUM(CASE WHEN COALESCE(synch, 0) = 0 THEN 1 ELSE 0 END) as pending_rows,";
		$sql .= " SUM(CASE WHEN COALESCE(synch, 0) = 0 AND (pairid IS NULL OR pairid = 0) THEN 1 ELSE 0 END) as unpaired_rows,";
		$sql .= " SUM(CASE WHEN COALESCE(synch, 0) = 0";
		$sql .= " AND (COALESCE(zsbms_synch, 0) = 0)";
		$sql .= " AND (id_zs IS NULL OR id_zs = 0)";
		$sql .= " THEN 1 ELSE 0 END) as synthetic_rows,";
		$sql .= " SUM(CASE WHEN synch IN (3, 4)";
		$sql .= " AND tms >= DATE_SUB(NOW(), INTERVAL " . (int) $windowHours . " HOUR)";
		$sql .= " THEN 1 ELSE 0 END) as recent_deleted_rows,";
		$sql .= " SUM(CASE WHEN COALESCE(zsbms_synch, 0) = 1";
		$sql .= " AND tms >= DATE_SUB(NOW(), INTERVAL " . (int) $windowHours . " HOUR)";
		$sql .= " THEN 1 ELSE 0 END) as recent_imported_rows";
		$sql .= " FROM " . MAIN_DB_PREFIX . "dolizsynch_zspunchclock_synch";
		$sql .= " WHERE entity IN (0, " . $entity . ")";

		$resql = $this->db->query($sql);
		if ($resql) {
			$obj = $this->db->fetch_object($resql);
			$summary['total_rows'] = (int) ($obj->total_rows ?? 0);
			$summary['pending_rows'] = (int) ($obj->pending_rows ?? 0);
			$summary['unpaired_rows'] = (int) ($obj->unpaired_rows ?? 0);
			$summary['synthetic_rows'] = (int) ($obj->synthetic_rows ?? 0);
			$summary['recent_deleted_rows'] = (int) ($obj->recent_deleted_rows ?? 0);
			$summary['recent_imported_rows'] = (int) ($obj->recent_imported_rows ?? 0);
			$this->db->free($resql);
		} else {
			$this->logOperationError(__METHOD__, "Failed to fetch punch clock sync summary", [
				'sql_error' => $this->db->lasterror(),
			]);
		}

		if ($this->tableExists('dolizsynch_zspunchclock')) {
			$sqlInt = "SELECT";
			$sqlInt .= " SUM(CASE WHEN COALESCE(synch, 0) = 1";
			$sqlInt .= " AND COALESCE(tms, date_creation) >= DATE_SUB(NOW(), INTERVAL " . (int) $windowHours . " HOUR)";
			$sqlInt .= " THEN 1 ELSE 0 END) as integrated_recent,";
			$sqlInt .= " SUM(CASE WHEN COALESCE(synch, 0) = 0 THEN 1 ELSE 0 END) as pending_integration";
			$sqlInt .= " FROM " . MAIN_DB_PREFIX . "dolizsynch_zspunchclock";
			$sqlInt .= " WHERE entity IN (0, " . $entity . ")";

			$resqlInt = $this->db->query($sqlInt);
			if ($resqlInt) {
				$objInt = $this->db->fetch_object($resqlInt);
				$summary['integrated_recent'] = (int) ($objInt->integrated_recent ?? 0);
				$summary['pending_integration'] = (int) ($objInt->pending_integration ?? 0);
				$this->db->free($resqlInt);
			}
		}

		return $summary;
	}

	/**
	 * Get API retry/rate-limit health summary for the last N hours.
	 *
	 * @param int $windowHours
	 * @return array
	 */
	public function getApiHealthSummary($windowHours)
	{
		global $conf;

		$windowHours = $this->normalizeWindowHours($windowHours);
		$entity = (int) $conf->entity;

		$summary = [
			'window_hours' => $windowHours,
			'available' => false,
			'total_events' => 0,
			'retry_count' => 0,
			'rate_limit_count' => 0,
			'network_retry_count' => 0,
			'exhausted_count' => 0,
			'last_event_at' => null,
			'rate_limit_share' => 0.0,
			'generated_at' => time(),
		];

		if (!$this->tableExists('dolizsynch_error_log')) {
			return $summary;
		}

		$summary['available'] = true;

		$sql = "SELECT";
		$sql .= " COUNT(*) as total_events,";
		$sql .= " SUM(CASE WHEN category = 'api_rate_limit' THEN 1 ELSE 0 END) as rate_limit_count,";
		$sql .= " SUM(CASE WHEN category = 'api_retry' THEN 1 ELSE 0 END) as generic_retry_count,";
		$sql .= " SUM(CASE WHEN category = 'api_network_retry' THEN 1 ELSE 0 END) as network_retry_count,";
		$sql .= " SUM(CASE WHEN category = 'api_retry_exhausted' THEN 1 ELSE 0 END) as exhausted_count,";
		$sql .= " MAX(date_creation) as last_event_at";
		$sql .= " FROM " . MAIN_DB_PREFIX . "dolizsynch_error_log";
		$sql .= " WHERE entity = " . $entity;
		$sql .= " AND category LIKE 'api%'";
		$sql .= " AND date_creation >= DATE_SUB(NOW(), INTERVAL " . (int) $windowHours . " HOUR)";

		$resql = $this->db->query($sql);
		if ($resql) {
			$obj = $this->db->fetch_object($resql);
			$rateLimitCount = (int) ($obj->rate_limit_count ?? 0);
			$genericRetryCount = (int) ($obj->generic_retry_count ?? 0);
			$networkRetryCount = (int) ($obj->network_retry_count ?? 0);
			$retryCount = $rateLimitCount + $genericRetryCount + $networkRetryCount;

			$summary['total_events'] = (int) ($obj->total_events ?? 0);
			$summary['rate_limit_count'] = $rateLimitCount;
			$summary['network_retry_count'] = $networkRetryCount;
			$summary['retry_count'] = $retryCount;
			$summary['exhausted_count'] = (int) ($obj->exhausted_count ?? 0);
			$summary['last_event_at'] = $obj->last_event_at ?? null;
			$summary['rate_limit_share'] = $retryCount > 0 ? round(($rateLimitCount / $retryCount) * 100, 2) : 0.0;

			$this->db->free($resql);
		} else {
			$this->logOperationError(__METHOD__, "Failed to fetch API health summary", [
				'sql_error' => $this->db->lasterror(),
			]);
		}

		return $summary;
	}

	/**
	 * Get top product sync errors from error log.
	 *
	 * @param int $windowHours
	 * @param int $limit
	 * @return array
	 */
	public function getTopProductErrors($windowHours, $limit = 10)
	{
		global $conf;

		if (!$this->tableExists('dolizsynch_error_log')) {
			return [];
		}

		$windowHours = $this->normalizeWindowHours($windowHours);
		$limit = max(1, (int) $limit);

		$sql = "SELECT message, COUNT(*) as count";
		$sql .= " FROM " . MAIN_DB_PREFIX . "dolizsynch_error_log";
		$sql .= " WHERE entity = " . (int) $conf->entity;
		$sql .= " AND date_creation >= DATE_SUB(NOW(), INTERVAL " . (int) $windowHours . " HOUR)";
		$sql .= " AND (category = 'product_sync' OR category LIKE 'product%')";
		$sql .= " GROUP BY message ORDER BY count DESC";
		$sql .= $this->db->plimit($limit, 0);

		$results = [];
		$resql = $this->db->query($sql);
		if ($resql) {
			while ($obj = $this->db->fetch_object($resql)) {
				$results[] = [
					'message' => (string) $obj->message,
					'count' => (int) $obj->count,
				];
			}
			$this->db->free($resql);
		}

		return $results;
	}

	/**
	 * Get top API retry/rate-limit events from error log.
	 *
	 * @param int $windowHours
	 * @param int $limit
	 * @return array
	 */
	public function getTopApiEvents($windowHours, $limit = 10)
	{
		global $conf;

		if (!$this->tableExists('dolizsynch_error_log')) {
			return [];
		}

		$windowHours = $this->normalizeWindowHours($windowHours);
		$limit = max(1, (int) $limit);

		$sql = "SELECT message, COUNT(*) as count";
		$sql .= " FROM " . MAIN_DB_PREFIX . "dolizsynch_error_log";
		$sql .= " WHERE entity = " . (int) $conf->entity;
		$sql .= " AND date_creation >= DATE_SUB(NOW(), INTERVAL " . (int) $windowHours . " HOUR)";
		$sql .= " AND category LIKE 'api%'";
		$sql .= " GROUP BY message ORDER BY count DESC";
		$sql .= $this->db->plimit($limit, 0);

		$results = [];
		$resql = $this->db->query($sql);
		if ($resql) {
			while ($obj = $this->db->fetch_object($resql)) {
				$results[] = [
					'message' => (string) $obj->message,
					'count' => (int) $obj->count,
				];
			}
			$this->db->free($resql);
		}

		return $results;
	}

	/**
	 * Cron hook: check health and send notifications if needed.
	 *
	 * @return int|string 0 on success, or error string for cron
	 */
	public function checkHealthAndNotify()
	{
		if (!self::FEATURE_ENABLED) {
			return 0;
		}

		$operation = __METHOD__;
		$windowHours = getDolGlobalInt('DOLIZSYNCH_NOTIFY_WINDOW_HOURS', 24);
		$summary = $this->getInvoiceHealthSummary($windowHours);

		$issues = $this->buildIssues($summary);
		if (empty($issues)) {
			$this->logOperationSuccess($operation, ['status' => 'ok']);
			return 0;
		}

		if (!$this->notificationsEnabled()) {
			$this->logOperationWarning($operation, "Notifications disabled or not configured", [
				'issues' => $issues,
			]);
			return 0;
		}

		$cooldownMinutes = getDolGlobalInt('DOLIZSYNCH_NOTIFY_COOLDOWN_MINUTES', 60);
		if (!$this->shouldSendNotification($issues, $cooldownMinutes)) {
			return 0;
		}

		$subjectPrefix = trim(getDolGlobalString('DOLIZSYNCH_NOTIFY_EMAIL_SUBJECT_PREFIX', '[KreaFlow]'));
		$subject = ($subjectPrefix !== '' ? $subjectPrefix . ' ' : '') . 'Sync health alert';
		$message = $this->buildAlertMessage($summary, $issues, $windowHours);

		$sent = $this->sendNotification($subject, $message, false);
		if (!$sent) {
			$this->logOperationError($operation, "Failed to send notification", [
				'issues' => $issues,
			]);
			return "ERROR: Failed to send notification";
		}

		$this->recordNotificationState($issues);
		$this->logOperationSuccess($operation, [
			'issues' => $issues,
		]);

		return 0;
	}

	/**
	 * Send a test notification using current configuration.
	 *
	 * @param bool $force
	 * @return bool
	 */
	public function sendTestNotification($force = false)
	{
		if (!self::FEATURE_ENABLED) {
			return false;
		}

		$subjectPrefix = trim(getDolGlobalString('DOLIZSYNCH_NOTIFY_EMAIL_SUBJECT_PREFIX', '[KreaFlow]'));
		$subject = ($subjectPrefix !== '' ? $subjectPrefix . ' ' : '') . 'Test notification';

		$message = "KreaFlow test notification\n";
		$message .= "Generated at: " . dol_print_date(dol_now(), 'dayhour') . "\n";
		$message .= "If you received this message, notifications are configured correctly.";

		return $this->sendNotification($subject, $message, $force);
	}

	/**
	 * Format duration in seconds into human-readable string.
	 *
	 * @param float|int|null $seconds
	 * @return string
	 */
	public function formatDuration($seconds)
	{
		if ($seconds === null) {
			return '-';
		}

		$seconds = (int) round($seconds);
		if ($seconds < 60) {
			return $seconds . 's';
		}

		$minutes = (int) floor($seconds / 60);
		$seconds = $seconds % 60;
		$hours = (int) floor($minutes / 60);
		$minutes = $minutes % 60;
		$days = (int) floor($hours / 24);
		$hours = $hours % 24;

		$parts = [];
		if ($days > 0) {
			$parts[] = $days . 'd';
		}
		if ($hours > 0) {
			$parts[] = $hours . 'h';
		}
		if ($minutes > 0) {
			$parts[] = $minutes . 'm';
		}
		if (empty($parts)) {
			$parts[] = $seconds . 's';
		}

		return implode(' ', $parts);
	}

	private function sendNotification($subject, $message, $force)
	{
		return $this->sendEmailNotification($subject, $message, $force);
	}

	private function sendEmailNotification($subject, $message, $force)
	{
		if (!$force && !getDolGlobalInt('DOLIZSYNCH_NOTIFY_EMAIL_ENABLED', 0)) {
			return false;
		}

		$recipients = trim(getDolGlobalString('DOLIZSYNCH_NOTIFY_EMAIL_TO', ''));
		if ($recipients === '') {
			if ($force) {
				$this->logOperationWarning(__METHOD__, "Email recipients not configured");
			}
			return false;
		}

		$from = trim(getDolGlobalString('MAIN_MAIL_EMAIL_FROM'));
		if ($from === '') {
			$from = trim(getDolGlobalString('MAIN_INFO_SOCIETE_MAIL'));
		}
		if ($from === '') {
			$this->logOperationError(__METHOD__, "Email sender not configured");
			return false;
		}

		$mail = new CMailFile(
			$subject,
			$recipients,
			$from,
			$message,
			[],
			[],
			[],
			'',
			'',
			0,
			0,
			'',
			'',
			'',
			'',
			'standard',
			$from
		);

		$result = $mail->sendfile();
		if (!$result) {
			$this->logOperationError(__METHOD__, "Failed to send email", [
				'error' => $mail->error,
			]);
			return false;
		}

		return true;
	}

	private function notificationsEnabled()
	{
		return getDolGlobalInt('DOLIZSYNCH_NOTIFY_EMAIL_ENABLED', 0)
			&& trim(getDolGlobalString('DOLIZSYNCH_NOTIFY_EMAIL_TO', '')) !== '';
	}

	private function shouldSendNotification(array $issues, $cooldownMinutes)
	{
		$lastSent = (int) getDolGlobalInt('DOLIZSYNCH_NOTIFY_LAST_SENT_TS', 0);
		$lastHash = (string) getDolGlobalString('DOLIZSYNCH_NOTIFY_LAST_HASH', '');
		$currentHash = sha1(json_encode($issues));

		if ($lastSent > 0 && $cooldownMinutes > 0) {
			if ((time() - $lastSent) < ($cooldownMinutes * 60) && $currentHash === $lastHash) {
				$this->logOperationWarning(__METHOD__, "Notification suppressed due to cooldown", [
					'last_sent' => $lastSent,
					'cooldown_minutes' => $cooldownMinutes,
				]);
				return false;
			}
		}

		return true;
	}

	private function recordNotificationState(array $issues)
	{
		global $conf;

		$hash = sha1(json_encode($issues));
		dolibarr_set_const($this->db, 'DOLIZSYNCH_NOTIFY_LAST_SENT_TS', time(), 'int', 0, '', (int) $conf->entity);
		dolibarr_set_const($this->db, 'DOLIZSYNCH_NOTIFY_LAST_HASH', $hash, 'chaine', 0, '', (int) $conf->entity);
	}

	private function buildIssues(array $summary)
	{
		$issues = [];

		$errorThreshold = getDolGlobalInt('DOLIZSYNCH_NOTIFY_ERROR_THRESHOLD', 5);
		$successRateThreshold = getDolGlobalInt('DOLIZSYNCH_NOTIFY_SUCCESS_RATE_THRESHOLD', 90);
		$latencyThresholdMinutes = getDolGlobalInt('DOLIZSYNCH_NOTIFY_LATENCY_THRESHOLD_MINUTES', 30);

		if ($summary['error_count'] >= $errorThreshold && $errorThreshold > 0) {
			$issues[] = "Error count {$summary['error_count']} >= threshold {$errorThreshold}";
		}

		if ($summary['processed_total'] > 0 && $summary['success_rate'] < $successRateThreshold) {
			$issues[] = "Success rate {$summary['success_rate']}% < threshold {$successRateThreshold}%";
		}

		if ($summary['avg_latency_seconds'] !== null && $latencyThresholdMinutes > 0) {
			$avgMinutes = round($summary['avg_latency_seconds'] / 60, 1);
			if ($avgMinutes > $latencyThresholdMinutes) {
				$issues[] = "Avg latency {$avgMinutes} min > threshold {$latencyThresholdMinutes} min";
			}
		}

		return $issues;
	}

	private function buildAlertMessage(array $summary, array $issues, $windowHours)
	{
		$lines = [];
		$lines[] = 'KreaFlow sync health alert';
		$lines[] = 'Window: last ' . (int) $windowHours . ' hours';
		$lines[] = '';
		$lines[] = 'Issues:';
		foreach ($issues as $issue) {
			$lines[] = '- ' . $issue;
		}

		$lines[] = '';
		$lines[] = 'Summary:';
		$lines[] = 'Total: ' . $summary['total'];
		$lines[] = 'Processed: ' . $summary['processed_total'];
		$lines[] = 'Success: ' . $summary['success_count'];
		$lines[] = 'Errors: ' . $summary['error_count'];
		$lines[] = 'Success rate: ' . $summary['success_rate'] . '%';

		if ($summary['avg_latency_seconds'] !== null) {
			$lines[] = 'Avg latency: ' . $this->formatDuration($summary['avg_latency_seconds']);
		}
		if ($summary['p95_latency_seconds'] !== null) {
			$lines[] = 'P95 latency: ' . $this->formatDuration($summary['p95_latency_seconds']);
		}
		if ($summary['max_latency_seconds'] !== null) {
			$lines[] = 'Max latency: ' . $this->formatDuration($summary['max_latency_seconds']);
		}

		$lines[] = '';
		$lines[] = 'Generated at: ' . dol_print_date(dol_now(), 'dayhour');

		return implode("\n", $lines);
	}

	private function getLatencyStatistics($windowHours, array $successStatuses)
	{
		global $conf;

		$stats = [
			'p95' => null,
			'max' => null,
		];

		$sql = "SELECT TIMESTAMPDIFF(SECOND, date_creation, date_modification) as latency_seconds";
		$sql .= " FROM " . MAIN_DB_PREFIX . "dolizsynch_zsinvoicesynch";
		$sql .= " WHERE entity = " . (int) $conf->entity;
		$sql .= " AND synch IN (" . implode(',', $successStatuses) . ")";
		$sql .= " AND date_creation IS NOT NULL AND date_modification IS NOT NULL";
		$sql .= " AND date_creation >= DATE_SUB(NOW(), INTERVAL " . (int) $windowHours . " HOUR)";
		$sql .= " AND COALESCE(date_modification, date_creation, tms) >= DATE_SUB(NOW(), INTERVAL " . (int) $windowHours . " HOUR)";
		$sql .= " ORDER BY latency_seconds ASC";
		$sql .= $this->db->plimit(5000, 0);

		$latencies = [];
		$resql = $this->db->query($sql);
		if ($resql) {
			while ($obj = $this->db->fetch_object($resql)) {
				if ($obj->latency_seconds !== null) {
					$latencies[] = (int) $obj->latency_seconds;
				}
			}
		}

		if (empty($latencies)) {
			return $stats;
		}

		sort($latencies);
		$stats['max'] = max($latencies);
		$stats['p95'] = $this->calculatePercentile($latencies, 0.95);

		return $stats;
	}

	private function calculatePercentile(array $values, $percentile)
	{
		$count = count($values);
		if ($count === 0) {
			return null;
		}

		$index = (int) ceil($percentile * $count) - 1;
		$index = max(0, min($index, $count - 1));

		return $values[$index];
	}

	/**
	 * Check if a database table exists (cached).
	 *
	 * @param string $tableWithoutPrefix
	 * @return bool
	 */
	private function tableExists($tableWithoutPrefix)
	{
		$table = MAIN_DB_PREFIX . $tableWithoutPrefix;
		if (array_key_exists($table, $this->tableExistsCache)) {
			return (bool) $this->tableExistsCache[$table];
		}

		$sql = "SHOW TABLES LIKE '" . $this->db->escape($table) . "'";
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->tableExistsCache[$table] = false;
			return false;
		}

		$exists = ($this->db->num_rows($resql) > 0);
		$this->db->free($resql);
		$this->tableExistsCache[$table] = $exists;

		return $exists;
	}

	private function normalizeWindowHours($windowHours)
	{
		$windowHours = (int) $windowHours;
		if ($windowHours < 1) {
			$windowHours = 1;
		}
		if ($windowHours > 720) {
			$windowHours = 720;
		}

		return $windowHours;
	}

	private function normalizeTrendDays($days)
	{
		$days = (int) $days;
		if ($days < 1) {
			$days = 1;
		}
		if ($days > 90) {
			$days = 90;
		}

		return $days;
	}

	private function getSuccessStatuses()
	{
		return [
			(int) ZSSyncStatus::OK,
			(int) ZSSyncStatus::CREDIT_PROCESSED,
		];
	}

	private function getErrorStatuses()
	{
		return [
			(int) ZSSyncStatus::FAILED,
			(int) ZSSyncStatus::VALIDATION_ERROR,
			(int) ZSSyncStatus::MAPPING_ERROR,
			(int) ZSSyncStatus::SAVE_UPDATE_ERROR,
			(int) ZSSyncStatus::CREDIT_CREATION_FAILED,
			(int) ZSSyncStatus::AUTHENTICATION_FAILED,
			(int) ZSSyncStatus::DATE_MISMATCH,
			(int) ZSSyncStatus::INVOICE_NOT_FOUND,
			(int) ZSSyncStatus::RECEIPT_ERROR_SYNCH,
		];
	}

	private function getProcessingStatuses()
	{
		return [
			(int) ZSSyncStatus::PROCESSING,
			(int) ZSSyncStatus::RETRY_QUEUED,
		];
	}

	private function getPendingStatuses()
	{
		return [
			(int) ZSSyncStatus::NOT_SYNCHRONIZED,
		];
	}
}

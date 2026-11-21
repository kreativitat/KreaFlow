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
 * \file       class/zstimeregisterimport.class.php
 * \ingroup    dolizsynch
 * \brief      Time Register Import wrapper class for cron jobs
 */

// Load required classes with fallback for different contexts
if (!class_exists('ZSPunchClock_Synch')) {
	if (function_exists('dol_include_once')) {
		dol_include_once('/kreaflow/class/zspunchclock_synch.class.php');
	}
	if (!class_exists('ZSPunchClock_Synch')) {
		$fallbacks = array();
		if (defined('DOL_DOCUMENT_ROOT')) {
			$fallbacks[] = rtrim(DOL_DOCUMENT_ROOT, '/') . '/custom/kreaflow/class/zspunchclock_synch.class.php';
		}
		$fallbacks[] = __DIR__ . '/zspunchclock_synch.class.php';
		foreach ($fallbacks as $fallback) {
			if (is_readable($fallback)) {
				require_once $fallback;
				break;
			}
		}
	}
}

if (!class_exists('ZSPunchClock')) {
	if (function_exists('dol_include_once')) {
		dol_include_once('/kreaflow/class/zspunchclock.class.php');
	}
	if (!class_exists('ZSPunchClock')) {
		$fallbacks = array();
		if (defined('DOL_DOCUMENT_ROOT')) {
			$fallbacks[] = rtrim(DOL_DOCUMENT_ROOT, '/') . '/custom/kreaflow/class/zspunchclock.class.php';
		}
		$fallbacks[] = __DIR__ . '/zspunchclock.class.php';
		foreach ($fallbacks as $fallback) {
			if (is_readable($fallback)) {
				require_once $fallback;
				break;
			}
		}
	}
}

/**
 * TimeRegisterImport class
 *
 * Wrapper class for punch clock synchronization used by Dolibarr cron jobs
 */
class TimeRegisterImport
{
	public $error = '';
	public $output = '';
	private $db;
	private $zstimeRegister;
	private $zstimeRegister2;
	private $featureEnabled = false;
	private $dependenciesLoaded = false;

	public function __construct($db)
	{
		$this->db = $db;
		$this->dependenciesLoaded = class_exists('ZSPunchClock_Synch') && class_exists('ZSPunchClock');
		if (!$this->dependenciesLoaded) {
			$missing = array();
			if (!class_exists('ZSPunchClock_Synch')) {
				$missing[] = 'ZSPunchClock_Synch';
			}
			if (!class_exists('ZSPunchClock')) {
				$missing[] = 'ZSPunchClock';
			}
			dol_syslog(__CLASS__ . '::' . __FUNCTION__ . ' missing dependencies: ' . implode(', ', $missing), LOG_ERR);
			return;
		}

		$this->zstimeRegister = new ZSPunchClock_Synch($this->db);
		$this->zstimeRegister2 = new ZSPunchClock($this->db);

		$syncHelperEnabled = ($this->zstimeRegister instanceof ZSPunchClock_Synch) ? $this->zstimeRegister->isPunchClockEnabled() : false;
		$mainHandlerEnabled = ($this->zstimeRegister2 instanceof ZSPunchClock) ? $this->zstimeRegister2->isPunchClockEnabled() : false;


		$this->featureEnabled = ($syncHelperEnabled && $mainHandlerEnabled);
		if (!$this->featureEnabled) {
			dol_syslog(__CLASS__ . '::' . __FUNCTION__ . ' punch clock disabled (synchHelperEnabled=' . ($syncHelperEnabled ? 'yes' : 'no') . ' mainHandlerEnabled=' . ($mainHandlerEnabled ? 'yes' : 'no') . ')', LOG_WARNING);
		}

	}

	private function guardReady($context)
	{
		if (!$this->dependenciesLoaded) {
			dol_syslog($context . " failed: missing punch clock dependencies", LOG_ERR);
			return -1;
		}
		if (!$this->featureEnabled) {
			dol_syslog($context . " skipped: punch clock feature disabled", LOG_WARNING);
			return 0;
		}
		return 1;
	}

	public function importTimeRegisters()
	{
		$ready = $this->guardReady(__METHOD__);
		if ($ready !== 1) {
			return $ready;
		}

		dol_syslog("Starting import of time registers...", LOG_INFO);

		if ($this->performStoreZsPunchclock() < 0) {
			return -1;
		}
		if ($this->performPairTimeRegisters() < 0) {
			return -1;
		}

		return 0;
	}

	public function performStoreZsPunchclock()
	{
		$ready = $this->guardReady(__METHOD__);
		if ($ready !== 1) {
			return $ready;
		}

		$this->error = '';
		$this->output = '';
		$result = $this->zstimeRegister->storezspunchclock();
		if ($result) {
			$this->output = 'Time register import completed successfully';
			dol_syslog("Time registers stored successfully.", LOG_INFO);
			return 0; // Success
		} else {
			$this->error = $this->zstimeRegister->error ?: 'Failed to store time registers';
			$this->output = $this->error;
			dol_syslog("Failed to store time registers.", LOG_ERR);
			return -1; // Error
		}
	}

	public function performPairTimeRegisters($dateFrom = null, $dateTo = null, $allowRepair = false)
	{
		global $conf;
		$ready = $this->guardReady(__METHOD__);
		if ($ready !== 1) {
			return $ready;
		}

		$explicitRange = $dateFrom !== null || $dateTo !== null;
		$now = new DateTimeImmutable('now', new DateTimeZone('Europe/Lisbon'));
		$hour = max(0, min(23, (int) ($conf->global->ZS_PUNCH_DAY_CHANGE_HOUR ?? 0)));
		try {
			$range = $this->pairingDateRange($now, $dateFrom, $dateTo);
		} catch (InvalidArgumentException $e) {
			$this->error = $e->getMessage();
			$this->output = $this->error;
			return -1;
		}
		if (!$range) {
			$this->output = 'No complete operational days in the requested range';
			return 0;
		}
		list($dateFrom, $dateTo) = $range;

		// Refresh exactly the requested recovery window before synthesizing counterparts.
		if ($explicitRange) {
			$start = new DateTimeImmutable($dateFrom . sprintf(' %02d:00:00', $hour), new DateTimeZone('Europe/Lisbon'));
			$end = (new DateTimeImmutable($dateTo . sprintf(' %02d:00:00', $hour), new DateTimeZone('Europe/Lisbon')))->modify('+1 day');
			$start = $start->modify($hour > 0 ? '+1 second' : '+0 seconds');
			$end = $end->modify($hour > 0 ? '+0 seconds' : '-1 second');
			try {
				$refreshed = $this->zstimeRegister->storezspunchclockPeriod($start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s'));
			} catch (Throwable $e) {
				$refreshed = false;
			}
			if (!$refreshed) {
				$this->error = $this->zstimeRegister->error ?: 'Failed to refresh the requested attendance source';
				$this->output = $this->error;
				return -1;
			}
		} elseif ($this->performStoreZsPunchclock() < 0) {
			return -1;
		}

		$hasError = false;

		$result = $this->zstimeRegister->pairTimeRegisters($dateFrom, $dateTo, $allowRepair);
		if ($result) {
			dol_syslog("Time registers paired successfully.", LOG_INFO);
		} else {
			dol_syslog("Failed to pair time registers.", LOG_ERR);
			$this->error = $this->zstimeRegister->error ?: 'Failed to pair time registers';
			$this->output = $this->error;
			return -1;
		}

		$result = $this->zstimeRegister2->integratePunchClockData($dateFrom, $dateTo);
		// integratePunchClockData returns 0 on success, -1 on error
		if ($result < 0) {
			dol_syslog("Failed to integrate punch clock data.", LOG_ERR);
			$this->error = $this->zstimeRegister2->error ?: 'Failed to integrate punch clock data';
			$this->output = $this->error;
			return -1;
		}

		$result = $this->zstimeRegister2->processTimeRegistersToTasks($dateFrom, $dateTo);
		if ($result) {
			dol_syslog("Time registers processed to tasks successfully.", LOG_INFO);
		} else {
			dol_syslog("Failed to process time registers to tasks.", LOG_ERR);
			$hasError = true;
		}

		// Note: Missed punch notifications are handled by the dedicated cron job
		// that runs notifyMissedPunches() on a scheduled basis (every 24 hours)

		// Return 0 for success, -1 for error (Dolibarr cron convention)
		$this->error = $hasError ? 'Punch clock pairing/integration failed' : '';
		$this->output = $hasError ? $this->error : 'Complete operational days paired and integrated successfully';
		return $hasError ? -1 : 0;
	}

	/** Closed local calendar days only; automatic retries stay within the configured overlap. */
	protected function pairingDateRange(DateTimeImmutable $now, $dateFrom, $dateTo)
	{
		global $conf;
		$now = $now->setTimezone(new DateTimeZone('Europe/Lisbon'));
		$hour = max(0, min(23, (int) ($conf->global->ZS_PUNCH_DAY_CHANGE_HOUR ?? 0)));
		$today = $now->setTime(0, 0, 0);
		$boundary = $today->setTime($hour, 0, 0);
		if ($now < $boundary || ($hour > 0 && $now == $boundary)) {
			$today = $today->modify('-1 day');
		}
		$lastComplete = $today->modify('-1 day')->format('Y-m-d');
		$days = max(1, (int) ($conf->global->ZS_PUNCH_CLOCK_NUMBER_OF_DAYS ?? 1));
		$dateFrom = $dateFrom ?? $today->modify('-' . $days . ' days')->format('Y-m-d');
		$dateTo = $dateTo ?? $lastComplete;
		foreach (array($dateFrom, $dateTo) as $date) {
			if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', (string) $date, $m)
				|| !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
				throw new InvalidArgumentException('Invalid attendance recovery date');
			}
		}
		if ($dateFrom > $dateTo) {
			throw new InvalidArgumentException('Invalid attendance recovery range');
		}
		$dateTo = min($dateTo, $lastComplete);
		return $dateFrom > $dateTo ? array() : array($dateFrom, $dateTo);
	}

	/**
	 * Performs flexible pairing for a specific date range with re-pairing capability
	 *
	 * @param string $dateFrom Start date in 'Y-m-d' format
	 * @param string $dateTo End date in 'Y-m-d' format
	 * @param bool $allowRepair Whether to allow re-pairing of existing pairs containing automatic entries
	 */
	public function performFlexiblePairTimeRegisters($dateFrom, $dateTo, $allowRepair = true)
	{
		return $this->performPairTimeRegisters($dateFrom, $dateTo, $allowRepair);
	}

	/**
	 * Synchronize punch clock entries between dates
	 *
	 * This method fetches all punch clock entries from ZoneSoft API between the specified dates
	 * (handling pagination automatically - no 250 limit), stores them in the database,
	 * and then pairs them with their corresponding in/out entries.
	 *
	 * @param string $startDate Start date in 'Y-m-d' format
	 * @param string $endDate End date in 'Y-m-d' format
	 * @param bool $allowRepair Whether to allow re-pairing of existing pairs (default: true)
	 * @return int 0 on success, -1 on error
	 */
	public function synchronizePunchClockBetweenDates($startDate, $endDate, $allowRepair = true)
	{
		$ready = $this->guardReady(__METHOD__);
		if ($ready !== 1) {
			return $ready;
		}

		dol_syslog(__METHOD__ . " - Synchronizing punch clock from $startDate to $endDate (allowRepair=" . ($allowRepair ? 'yes' : 'no') . ")", LOG_INFO);

		// Initialize the synch helper if not already done
		if (!isset($this->zstimeRegister)) {
			$this->zstimeRegister = new ZSPunchClock_Synch($this->db);
		}
		if (!isset($this->zstimeRegister2)) {
			$this->zstimeRegister2 = new ZSPunchClock($this->db);
		}

		$hasError = false;

		// Step 1: Fetch and store punch clock entries from ZoneSoft API
		// This method already handles pagination internally (fetches all pages)
		dol_syslog("Step 1: Fetching and storing punch clock entries from ZoneSoft API...", LOG_INFO);

		// Convert dates to datetime format for API
		$startDateTime = $startDate . ' 00:00:00';
		$endDateTime = $endDate . ' 23:59:59';

		$result = $this->zstimeRegister->storezspunchclockPeriod($startDateTime, $endDateTime);
		if (!$result) {
			dol_syslog("Failed to store punch clock entries for period $startDate to $endDate", LOG_ERR);
			return -1;
		}
		dol_syslog("Punch clock entries stored successfully", LOG_INFO);

		// Step 2: Pair the time registers (match IN with OUT entries)
		dol_syslog("Step 2: Pairing time registers...", LOG_INFO);
		$result = $this->zstimeRegister->pairTimeRegisters($startDate, $endDate, $allowRepair);
		if (!$result) {
			dol_syslog("Failed to pair time registers", LOG_ERR);
			$hasError = true;
		} else {
			dol_syslog("Time registers paired successfully", LOG_INFO);
		}

		// Step 3: Integrate punch clock data (create records in main punch clock table)
		dol_syslog("Step 3: Integrating punch clock data...", LOG_INFO);
		$result = $this->zstimeRegister2->integratePunchClockData($startDate, $endDate);
		if ($result < 0) {
			dol_syslog("Failed to integrate punch clock data", LOG_ERR);
			$hasError = true;
		} else {
			dol_syslog("Punch clock data integrated successfully", LOG_INFO);
		}

		// Step 4: Process time registers to tasks (create TimeSpent entries)
		dol_syslog("Step 4: Processing time registers to tasks...", LOG_INFO);
		$result = $this->zstimeRegister2->processTimeRegistersToTasks($startDate, $endDate);
		if (!$result) {
			dol_syslog("Failed to process time registers to tasks", LOG_ERR);
			$hasError = true;
		} else {
			dol_syslog("Time registers processed to tasks successfully", LOG_INFO);
		}

		// Step 5: Notify about missed punches (optional, don't fail if this fails)
		dol_syslog("Step 5: Checking for missed punches...", LOG_INFO);
		$notifyResult = $this->zstimeRegister->notifyMissedPunches();
		if ($notifyResult < 0) {
			dol_syslog("Failed to send missed punches notification (non-critical)", LOG_WARNING);
		}

		if ($hasError) {
			dol_syslog("Punch clock synchronization completed with errors", LOG_ERR);
			return -1;
		}

		dol_syslog("Punch clock synchronization completed successfully for period $startDate to $endDate", LOG_INFO);
		return 0;
	}
}

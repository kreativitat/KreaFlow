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
 * Lightweight automated checks for copyWeek merge/replace behavior.
 *
 * Usage:
 * - php test/copy_week_modes_test.php
 */

$scenarioName = '';
foreach ($argv as $arg) {
	if (strpos($arg, '--scenario=') === 0) {
		$scenarioName = substr($arg, 11);
		break;
	}
}

if ($scenarioName !== '') {
	runScenarioProcess($scenarioName);
	return;
}

runAllScenarios();

function runAllScenarios()
{
	$scenarioChecks = array(
		'merge_no_delete' => function ($payload, $meta, &$errors) {
			assertSameValue(true, !empty($payload['success']), 'merge_no_delete should succeed', $errors);
			assertSameValue('merge', (string) ($payload['mode'] ?? ''), 'merge_no_delete should return mode=merge', $errors);
			assertSameValue(0, (int) ($payload['inserted_shifts'] ?? -1), 'merge_no_delete inserted_shifts', $errors);
			assertSameValue(1, (int) ($payload['skipped'] ?? -1), 'merge_no_delete skipped', $errors);
			assertSameValue(1, (int) (($payload['skipped_conflicts']['target_shift'] ?? 0)), 'merge_no_delete skipped_conflicts.target_shift', $errors);
			assertSameValue(0, (int) ($payload['deleted_shifts'] ?? -1), 'merge_no_delete deleted_shifts', $errors);
			assertSameValue(0, (int) ($payload['deleted_dayoffs'] ?? -1), 'merge_no_delete deleted_dayoffs', $errors);
			assertTrue(!hasDeleteQuery($meta), 'merge_no_delete should not execute DELETE', $errors);
		},
		'replace_deletes_and_reinserts' => function ($payload, $meta, &$errors) {
			assertSameValue(true, !empty($payload['success']), 'replace_deletes_and_reinserts should succeed', $errors);
			assertSameValue('replace', (string) ($payload['mode'] ?? ''), 'replace_deletes_and_reinserts should return mode=replace', $errors);
			assertSameValue(1, (int) ($payload['inserted_shifts'] ?? -1), 'replace_deletes_and_reinserts inserted_shifts', $errors);
			assertSameValue(1, (int) ($payload['inserted_dayoffs'] ?? -1), 'replace_deletes_and_reinserts inserted_dayoffs', $errors);
			assertSameValue(3, (int) ($payload['deleted_shifts'] ?? -1), 'replace_deletes_and_reinserts deleted_shifts', $errors);
			assertSameValue(2, (int) ($payload['deleted_dayoffs'] ?? -1), 'replace_deletes_and_reinserts deleted_dayoffs', $errors);
			assertTrue(findQuery($meta, 'DELETE FROM llx_dolizsynch_shift') !== '', 'replace_deletes_and_reinserts should delete shifts', $errors);
			assertTrue(findQuery($meta, 'DELETE FROM llx_dolizsynch_dayoff') !== '', 'replace_deletes_and_reinserts should delete dayoffs', $errors);
		},
		'employee_scope_replace' => function ($payload, $meta, &$errors) {
			assertSameValue(true, !empty($payload['success']), 'employee_scope_replace should succeed', $errors);
			$shiftDelete = findQuery($meta, 'DELETE FROM llx_dolizsynch_shift');
			$dayoffDelete = findQuery($meta, 'DELETE FROM llx_dolizsynch_dayoff');
			assertTrue($shiftDelete !== '' && strpos($shiftDelete, 'AND fk_user = 101') !== false, 'employee_scope_replace must scope shift delete by employee_id', $errors);
			assertTrue($dayoffDelete !== '' && strpos($dayoffDelete, 'AND fk_user = 101') !== false, 'employee_scope_replace must scope dayoff delete by employee_id', $errors);
		},
		'source_empty_merge' => function ($payload, $meta, &$errors) {
			assertSameValue(true, !empty($payload['success']), 'source_empty_merge should succeed', $errors);
			assertSameValue(0, (int) ($payload['inserted_shifts'] ?? -1), 'source_empty_merge inserted_shifts', $errors);
			assertSameValue(0, (int) ($payload['inserted_dayoffs'] ?? -1), 'source_empty_merge inserted_dayoffs', $errors);
			assertSameValue(0, (int) ($payload['deleted_shifts'] ?? -1), 'source_empty_merge deleted_shifts', $errors);
			assertSameValue(0, (int) ($payload['deleted_dayoffs'] ?? -1), 'source_empty_merge deleted_dayoffs', $errors);
			assertTrue(!hasDeleteQuery($meta), 'source_empty_merge should not execute DELETE', $errors);
		},
		'source_empty_replace' => function ($payload, $meta, &$errors) {
			assertSameValue(true, !empty($payload['success']), 'source_empty_replace should succeed', $errors);
			assertSameValue(0, (int) ($payload['inserted_shifts'] ?? -1), 'source_empty_replace inserted_shifts', $errors);
			assertSameValue(0, (int) ($payload['inserted_dayoffs'] ?? -1), 'source_empty_replace inserted_dayoffs', $errors);
			assertSameValue(4, (int) ($payload['deleted_shifts'] ?? -1), 'source_empty_replace deleted_shifts', $errors);
			assertSameValue(2, (int) ($payload['deleted_dayoffs'] ?? -1), 'source_empty_replace deleted_dayoffs', $errors);
		},
		'dayoff_delete_entity_filter' => function ($payload, $meta, &$errors) {
			assertSameValue(true, !empty($payload['success']), 'dayoff_delete_entity_filter should succeed', $errors);
			$dayoffDelete = findQuery($meta, 'DELETE FROM llx_dolizsynch_dayoff');
			assertTrue(
				$dayoffDelete !== '' && strpos($dayoffDelete, 'AND entity = 1') !== false,
				'dayoff_delete_entity_filter should include entity scope in DELETE',
				$errors
			);
		},
		'merge_leave_conflict' => function ($payload, $meta, &$errors) {
			assertSameValue(true, !empty($payload['success']), 'merge_leave_conflict should succeed', $errors);
			assertSameValue('merge', (string) ($payload['mode'] ?? ''), 'merge_leave_conflict should return mode=merge', $errors);
			assertSameValue(0, (int) ($payload['inserted_shifts'] ?? -1), 'merge_leave_conflict inserted_shifts', $errors);
			assertSameValue(1, (int) ($payload['skipped'] ?? -1), 'merge_leave_conflict skipped', $errors);
			assertSameValue(1, (int) (($payload['skipped_conflicts']['target_leave'] ?? 0)), 'merge_leave_conflict skipped_conflicts.target_leave', $errors);
			assertTrue(!hasDeleteQuery($meta), 'merge_leave_conflict should not execute DELETE', $errors);
		},
	);

	foreach (array('autumn_forward', 'autumn_reverse', 'spring_forward', 'spring_reverse', 'autumn_overnight') as $transition) {
		foreach (array('Europe/Lisbon', 'Europe/Zurich', 'UTC') as $zone) {
			$name = 'dst_'.$transition.'_'.$zone;
			$scenarioChecks[$name] = function ($payload, $meta, &$errors) use ($name) {
				$config = buildScenarioConfig($name);
				$sql = findQuery($meta, 'INSERT INTO llx_dolizsynch_shift');
				assertTrue(!empty($payload['success']), $name.' must succeed', $errors);
				foreach ($config['expected_times'] as $expected) {
					assertTrue(strpos($sql, "'".$expected."'") !== false, $name.' must retain wall time '.$expected.'; SQL='.$sql, $errors);
				}
				$dayoffSql = findQuery($meta, 'INSERT INTO llx_dolizsynch_dayoff');
				assertTrue(strpos($dayoffSql, "'".substr($config['expected_times'][0], 0, 10)."'") !== false, $name.' must retain the day-off calendar date', $errors);
			};
		}
	}

	$failures = 0;
	foreach ($scenarioChecks as $scenario => $check) {
		$result = executeScenarioSubprocess($scenario);
		$errors = array();
		if ($result['exit_code'] !== 0) {
			$errors[] = 'scenario process exited with code ' . $result['exit_code'];
		}
		if (!is_array($result['payload'])) {
			$errors[] = 'scenario response is not valid JSON';
		} else {
			$check($result['payload'], $result['meta'], $errors);
		}

		if (empty($errors)) {
			echo "[PASS] " . $scenario . PHP_EOL;
		} else {
			$failures++;
			echo "[FAIL] " . $scenario . PHP_EOL;
			foreach ($errors as $error) {
				echo "  - " . $error . PHP_EOL;
			}
			if (!empty($result['stdout'])) {
				echo "  - stdout: " . trim($result['stdout']) . PHP_EOL;
			}
		}
	}

	if ($failures > 0) {
		exit(1);
	}
}

function executeScenarioSubprocess($scenarioName)
{
	$metaFile = tempnam(sys_get_temp_dir(), 'copy_week_meta_');
	$script = __FILE__;
	$cmd = 'COPY_WEEK_TEST_META=' . escapeshellarg($metaFile) . ' php ' . escapeshellarg($script) . ' --scenario=' . escapeshellarg($scenarioName);
	$output = array();
	$exitCode = 0;
	exec($cmd . ' 2>&1', $output, $exitCode);
	$stdout = implode("\n", $output);

	$payload = json_decode(trim($stdout), true);
	$meta = array();
	if (is_file($metaFile)) {
		$metaRaw = file_get_contents($metaFile);
		$decodedMeta = json_decode((string) $metaRaw, true);
		if (is_array($decodedMeta)) {
			$meta = $decodedMeta;
		}
		@unlink($metaFile);
	}

	return array(
		'exit_code' => $exitCode,
		'stdout' => $stdout,
		'payload' => $payload,
		'meta' => $meta,
	);
}

function runScenarioProcess($scenarioName)
{
	$scenario = buildScenarioConfig($scenarioName);
	date_default_timezone_set($scenario['timezone'] ?? 'UTC');
	if (empty($scenario)) {
		echo json_encode(array('success' => false, 'error' => 'Unknown scenario'));
		exit(1);
	}

	if (!defined('LOG_INFO')) define('LOG_INFO', 6);
	if (!defined('LOG_ERR')) define('LOG_ERR', 3);
	if (!defined('LOG_WARNING')) define('LOG_WARNING', 4);
	if (!defined('MAIN_DB_PREFIX')) define('MAIN_DB_PREFIX', 'llx_');
	if (!defined('DOL_DOCUMENT_ROOT')) define('DOL_DOCUMENT_ROOT', __DIR__);
	error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);

	if (!class_exists('DoliDB')) {
		class DoliDB
		{
			public $scenario;
			public $queries = array();
			public $lastError = '';
			public $inTransaction = false;
			public $committed = false;
			public $rolledBack = false;

			public function __construct(array $scenario)
			{
				$this->scenario = $scenario;
			}

			public function query($sql)
			{
				$this->queries[] = $sql;
				$trimmed = ltrim((string) $sql);

				if (stripos($trimmed, "SHOW COLUMNS FROM " . MAIN_DB_PREFIX . "dolizsynch_dayoff LIKE 'is_lock'") === 0) {
					$rows = !empty($this->scenario['has_dayoff_lock']) ? array(array('Field' => 'is_lock')) : array();
					return new CopyWeekFakeResult($rows, 0);
				}
				if (stripos($trimmed, "SHOW COLUMNS FROM " . MAIN_DB_PREFIX . "dolizsynch_dayoff LIKE 'entity'") === 0) {
					$rows = !empty($this->scenario['has_dayoff_entity']) ? array(array('Field' => 'entity')) : array();
					return new CopyWeekFakeResult($rows, 0);
				}
				if (stripos($trimmed, "SELECT rowid, fk_user, fk_task, label, date_start, date_end, color, note FROM ") === 0) {
					return new CopyWeekFakeResult($this->scenario['source_shifts'], 0);
				}
				if (stripos($trimmed, "SELECT rowid, fk_user, date_dayoff, description") === 0) {
					return new CopyWeekFakeResult($this->scenario['source_dayoffs'], 0);
				}
				if (stripos($trimmed, "SELECT fk_user, date_start FROM ") === 0) {
					return new CopyWeekFakeResult($this->scenario['existing_target_shifts'], 0);
				}
				if (stripos($trimmed, "SELECT fk_user, date_dayoff, is_recurring FROM ") === 0) {
					return new CopyWeekFakeResult($this->scenario['existing_target_dayoffs'], 0);
				}
				if (stripos($trimmed, "SELECT fk_user, date_start, date_end, halfday, minutes_total") === 0) {
					return new CopyWeekFakeResult($this->scenario['existing_target_leaves'], 0);
				}
				if (stripos($trimmed, "DELETE FROM " . MAIN_DB_PREFIX . "dolizsynch_shift") === 0) {
					return new CopyWeekFakeResult(array(), (int) $this->scenario['delete_shifts_affected']);
				}
				if (stripos($trimmed, "DELETE FROM " . MAIN_DB_PREFIX . "dolizsynch_dayoff") === 0) {
					return new CopyWeekFakeResult(array(), (int) $this->scenario['delete_dayoffs_affected']);
				}
				if (stripos($trimmed, "INSERT INTO ") === 0) {
					return new CopyWeekFakeResult(array(), 1);
				}

				return new CopyWeekFakeResult(array(), 0);
			}

			public function fetch_object($result)
			{
				if (!($result instanceof CopyWeekFakeResult)) {
					return false;
				}
				if ($result->index >= count($result->rows)) {
					return false;
				}
				$row = $result->rows[$result->index];
				$result->index++;
				return (object) $row;
			}

			public function free($result)
			{
				return true;
			}

			public function num_rows($result)
			{
				if (!($result instanceof CopyWeekFakeResult)) {
					return 0;
				}
				return count($result->rows);
			}

			public function affected_rows($result)
			{
				if (!($result instanceof CopyWeekFakeResult)) {
					return 0;
				}
				return (int) $result->affected;
			}

			public function begin()
			{
				$this->inTransaction = true;
				return true;
			}

			public function commit()
			{
				$this->committed = true;
				$this->inTransaction = false;
				return true;
			}

			public function rollback()
			{
				$this->rolledBack = true;
				$this->inTransaction = false;
				return true;
			}

			public function escape($value)
			{
				return addslashes((string) $value);
			}

			public function jdate($value)
			{
				if (is_numeric($value)) {
					return (int) $value;
				}
				$ts = strtotime((string) $value);
				return $ts === false ? 0 : $ts;
			}

			public function idate($value)
			{
				if (!is_numeric($value)) {
					return (string) $value;
				}
				return date('Y-m-d H:i:s', (int) $value);
			}

			public function lasterror()
			{
				return $this->lastError;
			}
		}
	}

	if (!class_exists('CopyWeekFakeResult')) {
		class CopyWeekFakeResult
		{
			public $rows;
			public $index = 0;
			public $affected = 0;

			public function __construct(array $rows, $affected)
			{
				$this->rows = $rows;
				$this->affected = (int) $affected;
			}
		}
	}

	if (!class_exists('User')) {
		class User
		{
			public $id;
			public $login;
			public $entity;
			public $rights;
		}
	}

	if (!function_exists('GETPOST')) {
		function GETPOST($key, $type = '')
		{
			global $COPY_WEEK_TEST_REQUEST;
			return isset($COPY_WEEK_TEST_REQUEST[$key]) ? $COPY_WEEK_TEST_REQUEST[$key] : '';
		}
	}
	if (!function_exists('dol_stringtotime')) {
		function dol_stringtotime($value)
		{
			$ts = strtotime((string) $value);
			return $ts === false ? false : $ts;
		}
	}
	if (!function_exists('dol_print_date')) {
		function dol_print_date($timestamp, $format)
		{
			if (!is_numeric($timestamp)) {
				$timestamp = strtotime((string) $timestamp);
			}
			if ($timestamp === false || $timestamp === null) {
				return '';
			}
			$phpFormat = strtr((string) $format, array(
				'%Y' => 'Y',
				'%m' => 'm',
				'%d' => 'd',
				'%H' => 'H',
				'%M' => 'i',
				'%S' => 's',
			));
			return date($phpFormat, (int) $timestamp);
		}
	}
	if (!function_exists('dol_now')) {
		function dol_now()
		{
			return time();
		}
	}
	if (!function_exists('dol_syslog')) {
		function dol_syslog($message, $level = LOG_INFO)
		{
			return;
		}
	}
	if (!function_exists('dol_mktime')) {
		function dol_mktime($hour, $minute, $second, $month, $day, $year)
		{
			return mktime($hour, $minute, $second, $month, $day, $year);
		}
	}
	if (!function_exists('getDolGlobalString')) {
		function getDolGlobalString($key, $default = '')
		{
			return $default;
		}
	}
	if (!function_exists('getDolGlobalInt')) {
		function getDolGlobalInt($key, $default = 0)
		{
			return (int) $default;
		}
	}

	global $COPY_WEEK_TEST_REQUEST;
	$COPY_WEEK_TEST_REQUEST = $scenario['request'];

	$db = new DoliDB($scenario);
	$conf = (object) array(
		'entity' => 1,
		'global' => (object) array(),
	);
	$user = new User();
	$user->id = 999;
	$user->login = 'tester';
	$user->entity = 1;
	$user->rights = (object) array(
		'dolizsynch' => (object) array(
			'shift' => (object) array(
				'write' => 1,
			),
		),
	);

	$GLOBALS['db'] = $db;
	$GLOBALS['conf'] = $conf;

	$metaFile = getenv('COPY_WEEK_TEST_META');
	if (!empty($metaFile)) {
		register_shutdown_function(function () use ($db, $metaFile) {
			$meta = array(
				'status_code' => http_response_code(),
				'queries' => $db->queries,
				'committed' => (bool) $db->committed,
				'rolled_back' => (bool) $db->rolledBack,
			);
			file_put_contents($metaFile, json_encode($meta));
		});
	}

	require_once __DIR__ . '/../class/zsshiftplanneractions.class.php';

	ZSShiftplannerActions::copyWeek($db, MAIN_DB_PREFIX . 'dolizsynch_shift', MAIN_DB_PREFIX . 'dolizsynch_task', $user);
}

function buildScenarioConfig($name)
{
	$base = array(
		'request' => array(
			'source_week' => '2026-03-02',
			'target_week' => '2026-03-09',
		),
		'has_dayoff_lock' => true,
		'has_dayoff_entity' => true,
		'source_shifts' => array(),
		'source_dayoffs' => array(),
		'existing_target_shifts' => array(),
		'existing_target_dayoffs' => array(),
		'existing_target_leaves' => array(),
		'delete_shifts_affected' => 0,
		'delete_dayoffs_affected' => 0,
	);

	if (strpos($name, 'dst_') === 0) {
		$parts = explode('_', substr($name, 4));
		$zone = array_pop($parts);
		$transition = implode('_', $parts);
		$dates = array(
			'autumn_forward' => array('2026-10-12','2026-10-19','2026-10-18 12:00:00','2026-10-18 16:00:00','2026-10-25 12:00:00','2026-10-25 16:00:00'),
			'autumn_reverse' => array('2026-10-19','2026-10-12','2026-10-25 12:00:00','2026-10-25 16:00:00','2026-10-18 12:00:00','2026-10-18 16:00:00'),
			'spring_forward' => array('2026-03-16','2026-03-23','2026-03-22 12:00:00','2026-03-22 16:00:00','2026-03-29 12:00:00','2026-03-29 16:00:00'),
			'spring_reverse' => array('2026-03-23','2026-03-16','2026-03-29 12:00:00','2026-03-29 16:00:00','2026-03-22 12:00:00','2026-03-22 16:00:00'),
			'autumn_overnight' => array('2026-10-12','2026-10-19','2026-10-17 22:00:00','2026-10-18 02:00:00','2026-10-24 22:00:00','2026-10-25 02:00:00'),
		);
		$d = $dates[$transition];
		$base['timezone'] = $zone;
		$base['request'] = array('source_week'=>$d[0], 'target_week'=>$d[1], 'copy_mode'=>'merge');
		$base['source_shifts'][] = array('rowid'=>71,'fk_user'=>101,'fk_task'=>11,'label'=>'DST shift','date_start'=>$d[2],'date_end'=>$d[3],'color'=>'#444444','note'=>'');
		$base['source_dayoffs'][] = array('rowid'=>72,'fk_user'=>102,'date_dayoff'=>substr($d[2],0,10),'description'=>'Day off','is_lock'=>0);
		$base['expected_times'] = array($d[4],$d[5]);
		return $base;
	}

	switch ($name) {
		case 'merge_no_delete':
			$base['request']['copy_mode'] = 'merge';
			$base['source_shifts'][] = array(
				'rowid' => 1,
				'fk_user' => 101,
				'fk_task' => 11,
				'label' => 'S1',
				'date_start' => '2026-03-03 10:00:00',
				'date_end' => '2026-03-03 14:00:00',
				'color' => '#111111',
				'note' => '',
			);
			$base['existing_target_shifts'][] = array(
				'fk_user' => 101,
				'date_start' => '2026-03-10 09:00:00',
			);
			return $base;

		case 'replace_deletes_and_reinserts':
			$base['request']['copy_mode'] = 'replace';
			$base['source_shifts'][] = array(
				'rowid' => 2,
				'fk_user' => 101,
				'fk_task' => 11,
				'label' => 'S2',
				'date_start' => '2026-03-02 11:00:00',
				'date_end' => '2026-03-02 15:00:00',
				'color' => '#222222',
				'note' => 'note',
			);
			$base['source_dayoffs'][] = array(
				'rowid' => 3,
				'fk_user' => 101,
				'date_dayoff' => '2026-03-04',
				'description' => 'D1',
				'is_lock' => 0,
				'entity' => 1,
			);
			$base['delete_shifts_affected'] = 3;
			$base['delete_dayoffs_affected'] = 2;
			return $base;

		case 'employee_scope_replace':
			$base['request']['copy_mode'] = 'replace';
			$base['request']['employee_id'] = 101;
			$base['source_shifts'][] = array(
				'rowid' => 4,
				'fk_user' => 101,
				'fk_task' => 11,
				'label' => 'S3',
				'date_start' => '2026-03-05 12:00:00',
				'date_end' => '2026-03-05 16:00:00',
				'color' => '#333333',
				'note' => '',
			);
			$base['delete_shifts_affected'] = 1;
			$base['delete_dayoffs_affected'] = 1;
			return $base;

		case 'source_empty_merge':
			$base['request']['copy_mode'] = 'merge';
			return $base;

		case 'source_empty_replace':
			$base['request']['copy_mode'] = 'replace';
			$base['delete_shifts_affected'] = 4;
			$base['delete_dayoffs_affected'] = 2;
			return $base;

		case 'dayoff_delete_entity_filter':
			$base['request']['copy_mode'] = 'replace';
			$base['delete_dayoffs_affected'] = 1;
			return $base;

		case 'merge_leave_conflict':
			$base['request']['copy_mode'] = 'merge';
			$base['source_shifts'][] = array(
				'rowid' => 5,
				'fk_user' => 101,
				'fk_task' => 11,
				'label' => 'S4',
				'date_start' => '2026-03-03 09:00:00',
				'date_end' => '2026-03-03 13:00:00',
				'color' => '#444444',
				'note' => '',
			);
			$base['existing_target_leaves'][] = array(
				'fk_user' => 101,
				'date_start' => '2026-03-10 00:00:00',
				'date_end' => '2026-03-11 00:00:00',
				'halfday' => 0,
				'minutes_total' => 0,
				'day_change_hour' => 0,
			);
			return $base;

		default:
			return array();
	}
}

function findQuery($meta, $needle)
{
	if (empty($meta['queries']) || !is_array($meta['queries'])) {
		return '';
	}
	foreach ($meta['queries'] as $query) {
		if (strpos((string) $query, $needle) !== false) {
			return (string) $query;
		}
	}
	return '';
}

function hasDeleteQuery($meta)
{
	if (empty($meta['queries']) || !is_array($meta['queries'])) {
		return false;
	}
	foreach ($meta['queries'] as $query) {
		if (stripos((string) $query, 'DELETE FROM ') === 0) {
			return true;
		}
	}
	return false;
}

function assertTrue($condition, $message, array &$errors)
{
	if (!$condition) {
		$errors[] = $message;
	}
}

function assertSameValue($expected, $actual, $message, array &$errors)
{
	if ($expected !== $actual) {
		$errors[] = $message . ' (expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . ')';
	}
}

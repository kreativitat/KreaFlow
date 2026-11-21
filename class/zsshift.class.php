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
 * \file    zsshift.class.php
 * \ingroup dolizsynch
 * \brief   Backend API for Shift Planner FullCalendar integration (class wrapper).
 */
require '../../../main.inc.php';
require_once DOL_DOCUMENT_ROOT . '/core/lib/price.lib.php';
require_once DOL_DOCUMENT_ROOT . '/core/lib/functions.lib.php';
require_once DOL_DOCUMENT_ROOT . '/user/class/user.class.php';
require_once DOL_DOCUMENT_ROOT . '/custom/kreaflow/class/zsdayoff.class.php';
require_once __DIR__ . '/../lib/shiftplanner.lib.php';
require_once __DIR__ . '/zsshiftplanneractions.class.php';

class ZSShiftApi
{
	/** @var DoliDB */
	private $db;
	/** @var User */
	private $user;
	/** @var Conf */
	private $conf;

	public function __construct($db, $user, $conf)
	{
		$this->db = $db;
		$this->user = $user;
		$this->conf = $conf;
	}

	/**
	 * Proxy unknown method calls to legacy global functions to keep API callable as a class.
	 *
	 * @param string $name
	 * @param array  $arguments
	 * @return mixed
	 */
	public function __call($name, $arguments)
	{
		if (function_exists($name)) {
			return call_user_func_array($name, $arguments);
		}

		throw new BadMethodCallException('Unknown method ' . $name);
	}

	public function init()
	{
		header('Content-Type: application/json; charset=utf-8');

		register_shutdown_function(function () {
			$lastError = error_get_last();
			if (empty($lastError)) {
				return;
			}
			$fatalTypes = array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR);
			if (!in_array($lastError['type'], $fatalTypes, true)) {
				return;
			}
			$detail = $lastError['message'] . ' in ' . ($lastError['file'] ?? '') . ':' . ($lastError['line'] ?? '');
			error_log('[dolizsynch] Fatal error: ' . $detail);
			@file_put_contents(__DIR__ . '/fatal.log', '[' . date('c') . '] ' . $detail . PHP_EOL, FILE_APPEND);
			if (!headers_sent()) {
				http_response_code(500);
				header('Content-Type: application/json; charset=utf-8');
			}
			echo json_encode([
				'error' => 'Fatal error',
				'details' => $detail,
			]);
		});

		if (empty($this->user->id)) {
			http_response_code(401);
			echo json_encode(['error' => 'Authentication required']);
			exit;
		}

		if (empty($this->user->rights->dolizsynch->shift->read)) {
			http_response_code(403);
			echo json_encode(['error' => 'Forbidden']);
			exit;
		}
	}

	public function dispatch()
	{
		global $db, $user, $conf;

		$action = GETPOST('action', 'alphanohtml');
		$shiftTable = MAIN_DB_PREFIX . 'dolizsynch_shift';
		$taskTable = MAIN_DB_PREFIX . 'dolizsynch_shift_task';
		$leaveTable = MAIN_DB_PREFIX . 'dolizsynch_leave';

		switch ($action) {
			case 'resources':
				ZSShiftplannerActions::listResources($db);
				break;
			case 'employee_pool':
				ZSShiftplannerActions::listEmployeePool($db);
				break;
			case 'employee_pool_update':
				ZSShiftplannerActions::updateEmployeePool($db, $user);
				break;
			case 'employee_status_update':
				ZSShiftplannerActions::updateEmployeeStatus($db, $user);
				break;
			case 'list':
				ZSShiftplannerActions::listEvents($db, $shiftTable, $taskTable);
				break;
			case 'create':
				ZSShiftplannerActions::createShift($db, $shiftTable, $taskTable, $user);
				break;
			case 'update':
				ZSShiftplannerActions::updateShift($db, $shiftTable, $taskTable, $user);
				break;
			case 'delete':
				ZSShiftplannerActions::deleteShift($db, $shiftTable, $user);
				break;
			case 'task_list':
				ZSShiftplannerActions::listTasks($db, $taskTable);
				break;
			case 'task_create':
				ZSShiftplannerActions::createTask($db, $taskTable, $user);
				break;
			case 'task_update':
				ZSShiftplannerActions::updateTask($db, $taskTable, $user);
				break;
			case 'task_delete':
				ZSShiftplannerActions::deleteTask($db, $taskTable, $user);
				break;
			case 'update_quota':
				ZSShiftplannerActions::updateQuota($db, $taskTable, $user);
				break;
			case 'public_holidays':
				ZSShiftplannerActions::listPublicHolidays($db);
				break;
			case 'dayoff_create':
				ZSShiftplannerActions::createDayOff($db, $shiftTable, $user);
				break;
			case 'create_dayoff': // alias used by JS
				ZSShiftplannerActions::createDayOff($db, $shiftTable, $user);
				break;
			case 'dayoff_delete':
				ZSShiftplannerActions::deleteDayOff($db, $user);
				break;
			case 'delete_dayoff': // alias used by JS
				ZSShiftplannerActions::deleteDayOff($db, $user);
				break;
			case 'leave_create':
				ZSShiftplannerActions::createLeave($db, $shiftTable, $leaveTable, $user);
				break;
			case 'create_leave': // alias used by JS
				ZSShiftplannerActions::createLeave($db, $shiftTable, $leaveTable, $user);
				break;
			case 'convert_shift_to_leave':
				ZSShiftplannerActions::convertShiftToLeave($db, $shiftTable, $leaveTable, $user);
				break;
			case 'convert_leave_to_shift':
				ZSShiftplannerActions::convertLeaveToShift($db, $shiftTable, $leaveTable, $user, $taskTable);
				break;
			case 'leave_delete':
				ZSShiftplannerActions::deleteLeave($db, $leaveTable, $user);
				break;
			case 'delete_leave': // alias used by JS
				ZSShiftplannerActions::deleteLeave($db, $leaveTable, $user);
				break;
			case 'leave_update':
				ZSShiftplannerActions::updateLeave($db, $shiftTable, $leaveTable, $user);
				break;
			case 'update_leave': // alias used by JS
				ZSShiftplannerActions::updateLeave($db, $shiftTable, $leaveTable, $user);
				break;
			case 'availability':
				ZSShiftplannerActions::listAvailability($db, $leaveTable);
				break;
			case 'copy_week':
				ZSShiftplannerActions::copyWeek($db, $shiftTable, $taskTable, $user);
				break;
			case 'week_notes_get':
				ZSShiftplannerActions::getWeekNotes($db, $user);
				break;
			case 'week_notes_save':
				ZSShiftplannerActions::saveWeekNotes($db, $user);
				break;
			case 'week_employee_overrides_get':
				ZSShiftplannerActions::getWeekEmployeeOverrides($db, $user);
				break;
			case 'week_employee_overrides_save':
				ZSShiftplannerActions::saveWeekEmployeeOverrides($db, $user);
				break;
			default:
				http_response_code(400);
				echo json_encode(['error' => 'Unknown action']);
		}
	}
}

$api = new ZSShiftApi($db, $user, $conf);
$api->init();
$api->dispatch();

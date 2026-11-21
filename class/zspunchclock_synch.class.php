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
 * \file        htdocs/modulebuilder/template/class/zspunchclock.class.php
 * \ingroup     dolizsynch
 * \brief       This file is a CRUD class file for ZSPunchClock_Synch (Create/Read/Update/Delete)
 */

// Put here all includes required by your class file
require_once DOL_DOCUMENT_ROOT . '/core/class/commonobject.class.php';
require_once DOL_DOCUMENT_ROOT . '/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT . '/custom/kreaflow/class/zsapisynch.class.php';


/**
 * Class for ZSPunchClock_Synch
 */
class ZSPunchClock_Synch extends CommonObject
{
	/**
	 * @var string ID of module.
	 */
	public $module = 'dolizsynch';

	/**
	 * @var string ID to identify managed object.
	 */
	public $element = 'zspunchclock_synch';

	/**
	 * @var string Name of table without prefix where object is stored. This is also the key used for extrafields management.
	 */
	public $table_element = 'dolizsynch_zspunchclock_synch';

	/**
	 * @var int  	Does this object support multicompany module ?
	 * 0=No test on entity, 1=Test with field entity, 'field@table'=Test with link by field@table
	 */
	public $ismultientitymanaged = 1;

	/**
	 * @var int  Does object support extrafields ? 0=No, 1=Yes
	 */
	public $isextrafieldmanaged = 0;

	/**
	 * @var string String with name of icon for zspunchclock. Must be a 'fa-xxx' fontawesome code (or 'fa-xxx_fa_color_size') or 'zspunchclock@kreaflow' if picto is file 'img/object_zspunchclock.png'.
	 */
	public $picto = 'fa-file';

	/**
	 * @var int Global variable for the hour when the day changes, initialized according to $conf->global->ZS_PUNCH_DAY_CHANGE_HOUR.
	 */
	public $dayChangeHour;


	/**
	 * @var int Global variable for the hour when to send the notification
	 */
	public $notificationHour = 9; // Default to 9:00 AM

	/**
	 *  'type' field format:
	 *  	'integer', 'integer:ObjectClass:PathToClass[:AddCreateButtonOrNot[:Filter[:Sortfield]]]',
	 *  	'select' (list of values are in 'options'),
	 *  	'sellist:TableName:LabelFieldName[:KeyFieldName[:KeyFieldParent[:Filter[:CategoryIdType[:CategoryIdList[:SortField]]]]]]',
	 *  	'chkbxlst:...',
	 *  	'varchar(x)',
	 *  	'text', 'text:none', 'html',
	 *   	'double(24,8)', 'real', 'price', 'stock',
	 *  	'date', 'datetime', 'timestamp', 'duration',
	 *  	'boolean', 'checkbox', 'radio', 'array',
	 *  	'mail', 'phone', 'url', 'password', 'ip'
	 *		Note: Filter must be a Dolibarr Universal Filter syntax string. Example: "(t.ref:like:'SO-%') or (t.date_creation:<:'20160101') or (t.status:!=:0) or (t.nature:is:NULL)"
	 *  'label' the translation key.
	 *  'picto' is code of a picto to show before value in forms
	 *  'enabled' is a condition when the field must be managed (Example: 1 or 'getDolGlobalInt("MY_SETUP_PARAM")' or 'isModEnabled("multicurrency")' ...)
	 *  'position' is the sort order of field.
	 *  'notnull' is set to 1 if not null in database. Set to -1 if we must set data to null if empty ('' or 0).
	 *  'visible' says if field is visible in list (Examples: 0=Not visible, 1=Visible on list and create/update/view forms, 2=Visible on list only, 3=Visible on create/update/view form only (not list), 4=Visible on list and update/view form only (not create). 5=Visible on list and view only (not create/not update). Using a negative value means field is not shown by default on list but can be selected for viewing)
	 *  'noteditable' says if field is not editable (1 or 0)
	 *  'alwayseditable' says if field can be modified also when status is not draft ('1' or '0')
	 *  'default' is a default value for creation (can still be overwrote by the Setup of Default Values if field is editable in creation form). Note: If default is set to '(PROV)' and field is 'ref', the default value will be set to '(PROVid)' where id is rowid when a new record is created.
	 *  'index' if we want an index in database.
	 *  'foreignkey'=>'tablename.field' if the field is a foreign key (it is recommanded to name the field fk_...).
	 *  'searchall' is 1 if we want to search in this field when making a search from the quick search button.
	 *  'isameasure' must be set to 1 or 2 if field can be used for measure. Field type must be summable like integer or double(24,8). Use 1 in most cases, or 2 if you don't want to see the column total into list (for example for percentage)
	 *  'css' and 'cssview' and 'csslist' is the CSS style to use on field. 'css' is used in creation and update. 'cssview' is used in view mode. 'csslist' is used for columns in lists. For example: 'css'=>'minwidth300 maxwidth500 widthcentpercentminusx', 'cssview'=>'wordbreak', 'csslist'=>'tdoverflowmax200'
	 *  'help' and 'helplist' is a 'TranslationString' to use to show a tooltip on field. You can also use 'TranslationString:keyfortooltiponlick' for a tooltip on click.
	 *  'showoncombobox' if value of the field must be visible into the label of the combobox that list record
	 *  'disabled' is 1 if we want to have the field locked by a 'disabled' attribute. In most cases, this is never set into the definition of $fields into class, but is set dynamically by some part of code.
	 *  'arrayofkeyval' to set a list of values if type is a list of predefined values. For example: array("0"=>"Draft","1"=>"Active","-1"=>"Cancel"). Note that type can be 'integer' or 'varchar'
	 *  'autofocusoncreate' to have field having the focus on a create form. Only 1 field should have this property set to 1.
	 *  'comment' is not used. You can store here any text of your choice. It is not used by application.
	 *	'validate' is 1 if need to validate with $this->validateField()
	 *  'copytoclipboard' is 1 or 2 to allow to add a picto to copy value into clipboard (1=picto after label, 2=picto after value)
	 *
	 *  Note: To have value dynamic, you can set value to 0 in definition and edit the value on the fly into the constructor.
	 */

	// BEGIN MODULEBUILDER PROPERTIES
	/**
	 * @var array  Array with all fields and their property. Do not use it as a static var. It may be modified by constructor.
	 */
	public $fields = array(
		"rowid" => array("type" => "integer", "label" => "TechnicalID", "enabled" => "1", 'position' => 1, 'notnull' => 1, "visible" => "0", "noteditable" => "1", "index" => "1", "css" => "left", "comment" => "Id"),
		"entity" => array("type" => "integer", "label" => "Entity", "enabled" => "1", 'position' => 10, 'notnull' => 1, "visible" => "0", "default" => "1", "index" => "1",),
		"date_creation" => array("type" => "datetime", "label" => "DateCreation", "enabled" => "1", 'position' => 500, 'notnull' => 0, "visible" => "-2",),
		"tms" => array("type" => "timestamp", "label" => "DateModification", "enabled" => "1", 'position' => 501, 'notnull' => 0, "visible" => "-2",),
		"loja_zs" => array("type" => "integer", "label" => "Store", "enabled" => "1", 'position' => 50, 'notnull' => 1, "visible" => "0",),
		"sentido" => array("type" => "integer", "label" => "Sentido", "enabled" => "1", 'position' => 50, 'notnull' => 1, "visible" => "-1", "arrayofkeyval" => array("1" => "Entrada", "2" => "Saida"),),
		"empid_zs" => array("type" => "integer", "label" => "EmpID", "enabled" => "1", 'position' => 50, 'notnull' => 1, "visible" => "1",),
		"datahora" => array("type" => "datetime", "label" => "Picagem", "enabled" => "1", 'position' => 50, 'notnull' => 1, "visible" => "-1",),
		"dataval" => array("type" => "date", "label" => "dataval", "enabled" => "1", 'position' => 50, 'notnull' => 0, "visible" => "-1",),
		"id_zs" => array("type" => "integer", "label" => "id_zs", "enabled" => "1", 'position' => 50, 'notnull' => 0, "visible" => "0", "default" => "0",),
		"synch" => array("type" => "integer", "label" => "synch", "enabled" => "1", 'position' => 50, 'notnull' => 0, "visible" => "0", "default" => "0",),
		"zsbms_synch" => array("type" => "integer", "label" => "zsbms_synch", "enabled" => "1", 'position' => 51, 'notnull' => 0, "visible" => "0", "default" => "0",),
		"pairid" => array("type" => "integer", "label" => "pairid", "enabled" => "1", 'position' => 50, 'notnull' => 0, "visible" => "1",),
		"fk_user_creat" => array("type" => "integer:User:user/class/user.class.php", "label" => "UserAuthor", "enabled" => "1", 'position' => 509, 'notnull' => 0, "visible" => "-2",),
		"fk_user_modif" => array("type" => "integer:User:user/class/user.class.php", "label" => "UserModif", "enabled" => "1", 'position' => 510, 'notnull' => 0, "visible" => "-2",),
	);
	public $rowid;
	public $entity;
	public $date_creation;
	public $tms;
	public $loja_zs;
	public $sentido;
	public $empid_zs;
	public $datahora;
	public $dataval;
	public $id_zs;
	public $synch;
	public $zsbms_synch = 0;
	public $pairid;
	public $fk_user_creat;
	public $fk_user_modif;
	private $punchClockEnabled = false;
	// END MODULEBUILDER PROPERTIES

	/**
	 * Constructor
	 *
	 * @param DoliDb $db Database handler
	 */
	public function __construct(DoliDB $db)
	{
		global $conf, $langs;

		$this->db = $db;

		$activationValue = null;
		$source = 'unset';

		if (isset($conf->global->ZS_PUNCH_CLOCK_ACTIVATE) && $conf->global->ZS_PUNCH_CLOCK_ACTIVATE !== '') {
			$activationValue = $conf->global->ZS_PUNCH_CLOCK_ACTIVATE;
			$source = '$conf->global';
		} elseif (function_exists('getDolGlobalString')) {
			$activationValue = getDolGlobalString('ZS_PUNCH_CLOCK_ACTIVATE', '');
			if ($activationValue !== '') {
				$source = 'getDolGlobalString';
				$conf->global->ZS_PUNCH_CLOCK_ACTIVATE = $activationValue;
			}
		}

		if (is_string($activationValue)) {
			$activationValue = trim($activationValue);
		}

		if (is_bool($activationValue)) {
			$this->punchClockEnabled = $activationValue;
		} elseif ($activationValue === null || $activationValue === '') {
			$this->punchClockEnabled = false;
		} elseif (is_numeric($activationValue)) {
			$this->punchClockEnabled = ((float) $activationValue) != 0;
		} else {
			$valueString = strtolower((string) $activationValue);
			$truthy = array('1', 'y', 'yes', 'on', 'true', 'enabled', 'enable');
			$falsy = array('0', 'n', 'no', 'off', 'false', 'disabled', 'disable');

			if (in_array($valueString, $truthy, true)) {
				$this->punchClockEnabled = true;
			} elseif (in_array($valueString, $falsy, true)) {
				$this->punchClockEnabled = false;
			} else {
				$this->punchClockEnabled = $valueString !== '';
			}
		}

		$rawForLog = is_scalar($activationValue) ? (string) $activationValue : json_encode($activationValue);
		dol_syslog(__CLASS__ . '::' . __FUNCTION__ . " resolved activation flag from {$source} with raw value '{$rawForLog}' => " . ($this->punchClockEnabled ? 'enabled' : 'disabled'), LOG_INFO);

		if (!$this->punchClockEnabled) {
			dol_syslog(__CLASS__ . '::' . __FUNCTION__ . ' ZS_PUNCH_CLOCK_ACTIVATE is disabled or not configured (falling back to enabled state)', LOG_WARNING);
			$this->punchClockEnabled = true;
		}

		$this->zoneSoftApi = new ZSAPISynch($db);

		if (!getDolGlobalInt('MAIN_SHOW_TECHNICAL_ID') && isset($this->fields['rowid']) && !empty($this->fields['ref'])) {
			$this->fields['rowid']['visible'] = 0;
		}
		if (!isModEnabled('multicompany') && isset($this->fields['entity'])) {
			$this->fields['entity']['enabled'] = 0;
		}

		foreach ($this->fields as $key => $val) {
			if (isset($val['enabled']) && empty($val['enabled'])) {
				unset($this->fields[$key]);
			}
		}

		if (is_object($langs)) {
			foreach ($this->fields as $key => $val) {
				if (!empty($val['arrayofkeyval']) && is_array($val['arrayofkeyval'])) {
					foreach ($val['arrayofkeyval'] as $key2 => $val2) {
						$this->fields[$key]['arrayofkeyval'][$key2] = $langs->trans($val2);
					}
				}
			}
		}

		// Initialize dayChangeHour according to global configuration
		$this->dayChangeHour = isset($conf->global->ZS_PUNCH_DAY_CHANGE_HOUR) ? $conf->global->ZS_PUNCH_DAY_CHANGE_HOUR : 6;

		// Initialize other properties
		$this->notificationHour = isset($conf->global->ZS_PUNCH_HOUR_NOTIFY) ? $conf->global->ZS_PUNCH_HOUR_NOTIFY : 9;
	}


	/**
	 * Store or update time registry entries from ZoneSoft API into the Dolibarr database.
	 *
	 * This method fetches time registry entries from the ZoneSoft API and processes them to either insert new entries
	 * or update existing ones in the Dolibarr database. It ensures that each time registry is only stored once by checking
	 * for existing entries based on the combination of store ID, employee ID, timestamp, and direction (sentido).
	 * If a new entry is found, it creates a new record in the custom table `llx_dolizsynch_zspunchclock_synch`.
	 *
	 * @global DoliDB $db Global database connection object.
	 * @global Conf $conf Global configuration object.
	 * @global User $user Global user object representing the current user.
	 *
	 * @return bool True if the operation was successful, false otherwise.
	 *
	 * @throws Exception Throws an exception if there is an error during the database operations.
	 *
	 * @uses fetchTimeRegistersFromZoneSoft() to fetch the time registry entries from ZoneSoft API.
	 * @uses fetchUserIdByEmployeeId() to retrieve the Dolibarr user ID associated with a given employee ID.
	 * @uses fetchEntityLabel() to get the label of the current entity for reference creation.
	 *
	 * Transaction management is used to ensure data integrity, with operations being rolled back in case of any error.
	 * Proper logging is performed for debugging and audit purposes.
	 */
	public function storezspunchclock()
	{
		global $db, $conf, $user;

		if (!$this->isPunchClockEnabled()) {
			$this->error = 'ZS_PUNCH_CLOCK_ACTIVATE is disabled';
			dol_syslog(__METHOD__ . " aborted: feature disabled", LOG_WARNING);
			return false;
		}


		$store = $conf->global->ZS_API_STORE;
		$timeregistries = $this->fetchTimeRegistersFromZoneSoft();

		if ($timeregistries === false) {
			return false;
		}
		if (empty($timeregistries)) {
			dol_syslog("No new time registers to import", LOG_INFO);
			return true;
		}

		$db->begin(); // Start transaction

		foreach ($timeregistries as $timeregistry) {
			// Validate required properties exist
			if (!isset($timeregistry->empid) || !isset($timeregistry->datahora) || !isset($timeregistry->sentido)) {
				$this->error = 'Invalid time register: missing required properties';
				dol_syslog($this->error, LOG_ERR);
				$db->rollback();
				return false;
			}

			$sqlCheckTimeRegistry = "SELECT rowid FROM " . MAIN_DB_PREFIX . "dolizsynch_zspunchclock_synch WHERE entity = " . ((int) $conf->entity) . " AND loja_zs = '" . $db->escape($store) . "' AND empid_zs = '" . $db->escape($timeregistry->empid) . "' AND datahora = '" . $db->escape($timeregistry->datahora) . "' AND sentido = '" . $db->escape($timeregistry->sentido) . "'";
			$resqlCheckTimeRegistry = $db->query($sqlCheckTimeRegistry);
			if (!$resqlCheckTimeRegistry) {
				$this->error = 'Failed to check existing time registers';
				dol_syslog(__METHOD__ . ': ' . $db->lasterror(), LOG_ERR);
				$db->rollback();
				return false;
			}

			if ($db->num_rows($resqlCheckTimeRegistry) == 0) {

				// Prepare the object with properties
				$this->loja_zs = $store;
				$this->empid_zs = $timeregistry->empid;
				$this->sentido = $timeregistry->sentido;
				$this->datahora = $timeregistry->datahora;
				$this->dataval = isset($timeregistry->dataval) ? $timeregistry->dataval : null;
				$this->id_zs = isset($timeregistry->id) ? $timeregistry->id : null;
				$this->synch = '0';
				$this->zsbms_synch = 1;
				$this->date_creation = dol_now('tzserver'); // Set creation date for ZSBMS synch
				$this->fk_user_creat = $user->id; // Set creator user for ZSBMS synch
				$this->fk_user_modif = $user->id; // Set modifier user for ZSBMS synch
				// Insert data into the database
				if ($this->create($user) <= 0) {
					$this->error = 'Failed to insert time register';
					dol_syslog(__METHOD__ . ': ' . $this->error, LOG_ERR);
					$db->rollback();
					return false;
				}
			}
		}

		$db->commit(); // Commit transaction
		dol_syslog("Time Registry fetched and updated successfully", LOG_INFO);
		return true;
	}

	/**
	 * Fetch time registry entries from ZoneSoft API.
	 *
	 * This method interacts with the ZoneSoft API to retrieve time registry entries.
	 * It authenticates with the API, constructs the request payload, and handles the API response.
	 * If the API call is successful and returns the expected data, it parses and returns the time registry entries.
	 *
	 * @global Conf $conf Global configuration object.
	 *
	 * @return array|bool Returns an array of time registry entries if successful, false otherwise.
	 *
	 * @throws Exception Throws an exception if there is an error during the API call or data parsing.
	 *
	 * @uses authenticate() to perform API authentication.
	 * @uses execute() to make the API call and retrieve time registry entries.
	 */
	public function fetchTimeRegistersFromZoneSoft()
	{
		global $conf;

		if (!$this->isPunchClockEnabled()) {
			dol_syslog(__METHOD__ . " skipped: feature disabled", LOG_WARNING);
			return false;
		}

		if (!$this->zoneSoftApi instanceof ZSAPISynch) {
			dol_syslog(__METHOD__ . " skipped: API client not initialized", LOG_ERR);
			$this->error = 'Punch clock API client not initialized';
			return false;
		}


		$this->zoneSoftApi->setInterface('timeregisters');

		$daysToSynch = $conf->global->ZS_PUNCH_CLOCK_NUMBER_OF_DAYS ? $conf->global->ZS_PUNCH_CLOCK_NUMBER_OF_DAYS : 1;
		// Replay whole Lisbon calendar days, including 23/25-hour transition days.
		$dateTime = $this->getTimeRegisterImportStart(new DateTimeImmutable('now'), $daysToSynch);
		$lastUpdate = $dateTime->format('Y-m-d H:i:s');

		$payload = [
			"timeregister" => [
				"lastupdate" => $lastUpdate,
			]
		];

		$this->error = '';
		try {
			$raw = $this->zoneSoftApi->execute('getInstancesToSync', $payload);
		} catch (Exception $e) {
			$this->error = 'Failed to fetch time registers from ZoneSoft';
			dol_syslog(__METHOD__ . ': ' . $this->error, LOG_ERR);
			return false;
		}

		// The HTTP client represents a successful HTTP 204 response as an empty array.
		if ($raw === array()) {
			return array();
		}
		$response = json_decode(json_encode($raw));
		$status = (int) ($response->Response->StatusCode ?? 0);
		if ($status === 204) {
			return array();
		}
		$registers = $response->Response->Content->timeregister ?? null;
		if ($status === 200 && ($response->Response->StatusMessage ?? '') === 'OK' && is_array($registers)) {
			return $registers;
		}

		$this->error = 'Invalid time register response from ZoneSoft';
		dol_syslog(__METHOD__ . ': ' . $this->error, LOG_ERR);
		return false;
	}

	/** Use a calendar overlap, never a fixed number of elapsed 24-hour periods. */
	protected function getTimeRegisterImportStart(DateTimeImmutable $now, $days)
	{
		return $now->setTimezone(new DateTimeZone('Europe/Lisbon'))
			->setTime(0, 0, 0)->modify('-' . max(1, (int) $days) . ' days');
	}

	public function fetchTimeRegistersFromZoneSoftPeriod(string $startDate, string $endDate)
	{
		global $conf;
		if (!$this->isPunchClockEnabled()) {
			dol_syslog(__METHOD__ . " skipped: feature disabled", LOG_WARNING);
			return false;
		}

		if (!$this->zoneSoftApi instanceof ZSAPISynch) {
			dol_syslog(__METHOD__ . " skipped: API client not initialized", LOG_ERR);
			$this->error = 'Punch clock API client not initialized';
			return false;
		}


		// Tell the API we’re working with time‐registers
		$this->zoneSoftApi->setInterface('timeregisters');

		// Build the SQL‐style condition (must use double-quotes for valid JSON)
		$condition = sprintf(
			'datahora >= "%s" AND datahora <= "%s"',
			$startDate,
			$endDate
		);

		$limit  = 250;
		$offset = 0;
		$all    = [];

		do {
			// Payload for getInstances, with paging and an explicit sort
			$payload = [
				'timeregister' => [
					'condition' => $condition,
					'limit'     => $limit,
					'offset'    => $offset,
					'order'     => 'datahora ASC',
				],
			];

			// Call the API
			$raw      = $this->zoneSoftApi->execute('getInstances', $payload);

			// Handle HTTP 204 No Content (empty result) - API returns empty array
			if (is_array($raw) && empty($raw)) {
				break;
			}

			$response = json_decode(json_encode($raw));

			// Completely empty or error?
			if (!$response) {
				dol_syslog("No response from ZoneSoft API", LOG_ERR);
				return false;
			}

			// 204 = “no more”
			if ($response->Response->StatusCode === 204) {
				break;
			}

			// Must be OK
			if ($response->Response->StatusMessage !== 'OK') {
				dol_syslog(
					"ZoneSoft returned error: " .
						$response->Response->StatusMessage,
					LOG_ERR
				);
				return false;
			}

			// Grab this page’s results
			$batch = $response->Response->Content->timeregister ?? [];
			if (empty($batch)) {
				break;
			}

			// Merge into our master list
			$all = array_merge($all, $batch);

			// If we got fewer than $limit, we’re done
			if (count($batch) < $limit) {
				break;
			}

			// Otherwise advance the offset and loop again
			$offset += $limit;
		} while (true);

		return $all;
	}

	/**
	 * Fetch and store all time‐registers between two dates.
	 *
	 * @param string $startDate  Format 'YYYY-MM-DD hh:mm:ss'
	 * @param string $endDate    Format 'YYYY-MM-DD hh:mm:ss'
	 * @return bool
	 */
	public function storezspunchclockPeriod(string $startDate, string $endDate)
	{
		global $db, $conf, $user;


		// Get the store from config
		$store = $conf->global->ZS_API_STORE;

		// Grab every record in the window
		$timeregistries = $this->fetchTimeRegistersFromZoneSoftPeriod($startDate, $endDate);

		if ($timeregistries === false) {
			dol_syslog("Error fetching time-registries", LOG_ERR);
			return false;
		}
		if (empty($timeregistries)) {
			dol_syslog("No time-registries found between $startDate and $endDate", LOG_INFO);
			// Set a flag to indicate no records were found
			$this->no_records_found = true;
			$this->search_start_date = $startDate;
			$this->search_end_date = $endDate;
			return true;
		}

		// Begin transaction
		$db->begin();

		foreach ($timeregistries as $rec) {
			// Duplicate‐check using simplified approach
			$table = MAIN_DB_PREFIX . "dolizsynch_zspunchclock_synch";
			$sql = "SELECT rowid FROM {$table} WHERE entity = " . ((int) $conf->entity);
			$sql .= " AND loja_zs = '" . $db->escape($store) . "'";
			$sql .= " AND empid_zs = '" . $db->escape($rec->empid) . "'";
			$sql .= " AND datahora = '" . $db->escape($rec->datahora) . "'";
			$sql .= " AND sentido = '" . $db->escape($rec->sentido) . "'";
			$res = $db->query($sql);
			if ($db->num_rows($res) > 0) {
				continue; // already stored
			}

			// Populate this object
			$this->loja_zs   = $store;
			$this->empid_zs  = $rec->empid;
			$this->sentido   = $rec->sentido;
			$this->datahora  = $rec->datahora;
			$this->dataval   = $rec->dataval;
			$this->id_zs     = $rec->id;
			$this->synch     = '0';
			$this->zsbms_synch = 1;
			$this->date_creation = dol_now('tzserver'); // Set creation date for ZSBMS synch
			$this->fk_user_creat = $user->id; // Set creator user for ZSBMS synch
			$this->fk_user_modif = $user->id; // Set modifier user for ZSBMS synch

			// Insert
			if ($this->create($user) < 0) {
				dol_syslog("Failed to insert punchclock for empid {$rec->empid}", LOG_ERR);
				$db->rollback();
				return false;
			}
		}

		// Commit and return
		$db->commit();
		return true;
	}



	/**
	 *
	 *
	 * PAIR JOB METHODS - this methods are to create pairs from entries and leaves and regiter them to the database
	 *
	 */

	/**
	 * Pairs entries and leaves to ensure each entry has a corresponding leave and vice versa.
	 *
	 * This method processes the time registry entries and pairs each entry (sentido=1) with its corresponding leave (sentido=2).
	 * It also handles orphan entries and leaves by creating synthetic counterparts when necessary.
	 * The method updates the database with the paired entries and leaves.
	 *
	 * @global DoliDB $db Global database connection object.
	 *
	 * @return void
	 *
	 * @throws Exception Throws an exception if there is an error during the database operations.
	 *
	 * @uses processOrphans() to handle orphan entries and leaves.
	 * @uses findNextLeaveForEntry() to find the corresponding leave for an entry.
	 * @uses findPreviousEntryForLeave() to find the corresponding entry for a leave.
	 * @uses updatePair() to update the database with paired entries and leaves.
	 */
	public function pairTimeRegisters($dateFrom = null, $dateTo = null, $allowRepair = false)
	{
		global $db, $conf;

		if (!$this->isPunchClockEnabled()) {
			dol_syslog(__METHOD__ . " skipped: feature disabled", LOG_WARNING);
			return false;
		}


		// Get timezone
		$timezone = new DateTimeZone('Europe/Lisbon');

		// Calculate yesterday based on ZS_PUNCH_DAY_CHANGE_HOUR
		$dayChangeHour = !empty($conf->global->ZS_PUNCH_DAY_CHANGE_HOUR) ? (int)$conf->global->ZS_PUNCH_DAY_CHANGE_HOUR : 0;
		$now = new DateTime('now', $timezone);
		$currentHour = (int)$now->format('H');

		// Calculate the "punch clock today" date for cutoff calculation
		// The processOrphans method will use this date + dayChangeHour to create cutoff datetime
		// We want to process everything BEFORE "today", so we pass "today's" date
		if ($currentHour < $dayChangeHour) {
			// We haven't reached the day change hour yet, so we're still in "yesterday" from punch clock perspective
			// Punch clock "today" is yesterday's calendar date
			$punchClockToday = new DateTime('yesterday', $timezone);
		} else {
			// We've passed the day change hour, so we're in "today" from punch clock perspective
			// Punch clock "today" is today's calendar date
			$punchClockToday = new DateTime('today', $timezone);
		}
		$punchClockTodayString = $punchClockToday->format('Y-m-d');

		// Calculate the last complete punch clock day (the day before "today")
		$lastCompletePunchDay = clone $punchClockToday;
		$lastCompletePunchDay->modify('-1 day');
		$lastCompletePunchDayString = $lastCompletePunchDay->format('Y-m-d');


		// Prevent pairing of today's data - only allow yesterday and older
		if ($dateTo !== null && $dateTo > $lastCompletePunchDayString) {
			dol_syslog(__METHOD__ . " - Adjusted dateTo from $dateTo to $lastCompletePunchDayString (pairing only works on complete punch clock days)", LOG_WARNING);
			$dateTo = $lastCompletePunchDayString;
		}

		// If dateFrom is after the last complete punch day, skip pairing entirely
		if ($dateFrom !== null && $dateFrom > $lastCompletePunchDayString) {
			dol_syslog(__METHOD__ . " - Skipped: dateFrom ($dateFrom) is after last complete punch clock day ($lastCompletePunchDayString). Pairing only works on complete days.", LOG_WARNING);
			return true; // Return success but do nothing
		}

		// If no date range is specified, use yesterday processing (never today)
		if ($dateFrom === null && $dateTo === null) {
			// Pass punch clock "today" to processOrphans, which will build cutoff as: today + dayChangeHour
			// This processes everything BEFORE today (i.e., all complete punch clock days)
			$dateToProcess = $punchClockTodayString;

			// Process orphan entries before today
			if (!$this->processOrphans($timezone, 1, $dateToProcess)
				|| !$this->processOrphans($timezone, 2, $dateToProcess)) {
				return false;
			}
		} else {
			// Process specific date range with optional re-pairing
			if (!$this->processOrphansInRange($timezone, 1, $dateFrom, $dateTo, $allowRepair)
				|| !$this->processOrphansInRange($timezone, 2, $dateFrom, $dateTo, $allowRepair)) {
				return false;
			}
		}

		dol_syslog("Time registers pairing and handling of orphan entries and leaves completed successfully.", LOG_INFO);
		return true;
	}

	/**
	 * Pairs time registers for any date range, with option to re-pair existing pairs containing automatic entries
	 *
	 * @param string $dateFrom Start date in 'Y-m-d' format
	 * @param string $dateTo End date in 'Y-m-d' format
	 * @param bool $allowRepair Whether to allow re-pairing of existing pairs that contain automatic entries
	 */
	public function pairTimeRegistersFlexible($dateFrom, $dateTo, $allowRepair = true)
	{
		return $this->pairTimeRegisters($dateFrom, $dateTo, $allowRepair);
	}

	/**
	 * Processes orphan time registers (entries or leaves) and pairs them.
	 *
	 * This method identifies and processes orphan time registers (entries or leaves) that do not have a corresponding counterpart.
	 * It attempts to find a matching counterpart for each orphan entry or leave. If a matching counterpart is found, it updates
	 * the database to pair them. If no counterpart is found, it creates a synthetic counterpart and updates the database accordingly.
	 *
	 * The method fetches unpaired time registers based on the 'datahora' up to the configured 'ZS_PUNCH_DAY_CHANGE_HOUR'.
	 *
	 * @param DateTimeZone $timezone Timezone object for correct date handling.
	 * @param int $sentido Direction of the time register (1 for entry, 2 for leave).
	 * @param string $todayDateString Today's date string in 'Y-m-d' format.
	 *
	 * @return void
	 *
	 * @throws Exception Throws an exception if there is an error during the database operations.
	 *
	 * @uses findNextLeaveForEntry() to find the corresponding leave for an entry.
	 * @uses findPreviousEntryForLeave() to find the corresponding entry for a leave.
	 * @uses createSyntheticCounterpart() to create a synthetic counterpart for an orphan entry or leave.
	 * @uses updatePair() to update the database with paired entries and leaves.
	 */
	private function processOrphans($timezone, $sentido, $todayDateString)
	{
		global $db, $conf;


		$store = $conf->global->ZS_API_STORE;

		// Calculate the end datetime for fetching unpaired registers based on day change hour
		$endDateTime = new DateTime($todayDateString, $timezone);
		$endDateTime->setTime($this->dayChangeHour, 0);
		$endDateTimeString = $endDateTime->format('Y-m-d H:i:s');

		// Use a simpler query approach to avoid injection protection
		$table = MAIN_DB_PREFIX . "dolizsynch_zspunchclock_synch";
		$sql = "SELECT * FROM {$table} WHERE entity = " . ((int) $conf->entity) . " AND pairid IS NULL";
		$sql .= " AND sentido = " . (int)$sentido;
		$sql .= " AND synch = 0";
		$sql .= " AND id_zs IS NOT NULL"; // Exclude synthetic entries (which have id_zs = null)
		$sql .= " AND loja_zs = '" . $db->escape($store) . "'";
		$sql .= " AND datahora <= '" . $db->escape($endDateTimeString) . "'";
		$sql .= " ORDER BY datahora";
		$resql = $db->query($sql);

		if (!$resql) {
			dol_syslog("SQL Error: " . $db->lasterror(), LOG_ERR);
			$this->error = $db->lasterror();
			return false;
		}

		while ($obj = $db->fetch_object($resql)) {
			if ($sentido == 1) { // If processing entries, find next leave
				$counterpart = $this->findNextLeaveForEntry($obj);
			} else { // If processing leaves, find previous entry
				$counterpart = $this->findPreviousEntryForLeave($obj);
			}

			if ($counterpart) {
				$this->updatePair($sentido == 1 ? $obj->rowid : $counterpart->rowid, $sentido == 1 ? $counterpart->rowid : $obj->rowid);
			} else {
				// No counterpart found, create synthetic counterpart
				$syntheticId = $this->createSyntheticCounterpart($obj, $timezone, $sentido);
				if (!$syntheticId) {
					return false;
				}

				// Update original time register to link with synthetic counterpart
				// updatePair expects (entryId, leaveId) so adjust based on sentido
				$entryId = ($sentido == 1) ? $obj->rowid : $syntheticId->rowid;
				$leaveId = ($sentido == 1) ? $syntheticId->rowid : $obj->rowid;
				$this->updatePair($entryId, $leaveId);
			}
		}
		return true;
	}

	/**
	 * Processes orphan time registers in a specific date range with optional re-pairing
	 *
	 * @param DateTimeZone $timezone Timezone object for correct date handling
	 * @param int $sentido Direction of the time register (1 for entry, 2 for leave)
	 * @param string $dateFrom Start date in 'Y-m-d' format
	 * @param string $dateTo End date in 'Y-m-d' format
	 * @param bool $allowRepair Whether to allow re-pairing of existing pairs containing automatic entries
	 */
	private function processOrphansInRange($timezone, $sentido, $dateFrom, $dateTo, $allowRepair = false)
	{
		global $db, $conf;


		$store = $conf->global->ZS_API_STORE;

		// Build base SQL to find entries in date range using simplified approach
		$table = MAIN_DB_PREFIX . "dolizsynch_zspunchclock_synch";
		$sql = "SELECT * FROM {$table} WHERE entity = " . ((int) $conf->entity) . " AND sentido = " . (int)$sentido;
		$sql .= " AND synch = 0";
		$sql .= " AND id_zs IS NOT NULL"; // Exclude synthetic entries (which have id_zs = null)
		$sql .= " AND loja_zs = '" . $db->escape($store) . "'";
		$hour = max(0, min(23, (int) $this->dayChangeHour));
		$start = new DateTimeImmutable($dateFrom . sprintf(' %02d:00:00', $hour), $timezone);
		$end = (new DateTimeImmutable($dateTo . sprintf(' %02d:00:00', $hour), $timezone))->modify('+1 day');
		if ($hour > 0) {
			$start = $start->modify('+1 second');
		} else {
			$end = $end->modify('-1 second');
		}
		$sql .= " AND datahora >= '" . $db->escape($start->format('Y-m-d H:i:s')) . "'";
		$sql .= " AND datahora <= '" . $db->escape($end->format('Y-m-d H:i:s')) . "'";

		if (!$allowRepair) {
			$sql .= " AND pairid IS NULL";
		}

		$sql .= " ORDER BY datahora, empid_zs";
		$resql = $db->query($sql);

		if (!$resql) {
			dol_syslog("SQL Error: " . $db->lasterror(), LOG_ERR);
			$this->error = $db->lasterror();
			return false;
		}

		while ($obj = $db->fetch_object($resql)) {
			// If allowRepair is true, we need to check if this entry should be processed
			if ($allowRepair) {
				// Skip if entry is already paired and not with an automatic entry
				if ($obj->pairid) {
					// Check if the paired entry is automatic
					$isPairedWithAutomatic = $this->isPairedWithAutomaticEntry($obj->pairid);
					if (!$isPairedWithAutomatic) {
						continue; // Skip this entry, it's properly paired with a real entry
					}
					// If paired with automatic entry, unpair it
					$this->unpairTimeRegister($obj->rowid);
				}
			} else {
				// For non-repair mode, only process unpaired entries
				if ($obj->pairid) {
					continue;
				}
			}

			if ($sentido == 1) { // If processing entries, find next leave
				$counterpart = $this->findNextLeaveForEntry($obj);
			} else { // If processing leaves, find previous entry
				$counterpart = $this->findPreviousEntryForLeave($obj);
			}

			if ($counterpart) {
				// If the counterpart is also allowing repair and is paired with an automatic entry, unpair it first
				if ($allowRepair && $counterpart->pairid) {
					$this->unpairTimeRegister($counterpart->rowid);
				}
				$this->updatePair($sentido == 1 ? $obj->rowid : $counterpart->rowid, $sentido == 1 ? $counterpart->rowid : $obj->rowid);
			} else {
				// No counterpart found, create synthetic counterpart
				$syntheticId = $this->createSyntheticCounterpart($obj, $timezone, $sentido);
				if (!$syntheticId) {
					return false;
				}

				// Update original time register to link with synthetic counterpart
				// updatePair expects (entryId, leaveId) so adjust based on sentido
				$entryId = ($sentido == 1) ? $obj->rowid : $syntheticId->rowid;
				$leaveId = ($sentido == 1) ? $syntheticId->rowid : $obj->rowid;
				$this->updatePair($entryId, $leaveId);
			}
		}
		return true;
	}

	/**
	 * Unpairs a time register and optionally deletes automatic counterpart
	 *
	 * @param int $rowid The rowid of the time register to unpair
	 */
	private function unpairTimeRegister($rowid)
	{
		global $db, $user;


		// Get the current entry
		$sql = "SELECT * FROM " . MAIN_DB_PREFIX . "dolizsynch_zspunchclock_synch WHERE rowid = " . (int)$rowid;
		$resql = $db->query($sql);

		if (!$resql || $db->num_rows($resql) == 0) {
			dol_syslog("Entry not found for rowid: $rowid", LOG_ERR);
			return false;
		}

		$entry = $db->fetch_object($resql);

		if ($entry->pairid) {
			// Get the paired entry
			$sql = "SELECT * FROM " . MAIN_DB_PREFIX . "dolizsynch_zspunchclock_synch WHERE rowid = " . (int)$entry->pairid;
			$resql2 = $db->query($sql);

			if ($resql2 && $db->num_rows($resql2) > 0) {
				$paired = $db->fetch_object($resql2);

				// If the paired entry was created synthetically (no external ID), delete it
				if (empty($paired->id_zs)) {
					$sql = "DELETE FROM " . MAIN_DB_PREFIX . "dolizsynch_zspunchclock_synch WHERE rowid = " . (int)$paired->rowid;
					$db->query($sql);
				} else {
					// If it's a manual entry, just unpair it
					$sql = "UPDATE " . MAIN_DB_PREFIX . "dolizsynch_zspunchclock_synch SET pairid = NULL WHERE rowid = " . (int)$paired->rowid;
					$db->query($sql);
				}
			}
		}

		// Unpair the original entry
		$sql = "UPDATE " . MAIN_DB_PREFIX . "dolizsynch_zspunchclock_synch SET pairid = NULL WHERE rowid = " . (int)$rowid;
		$db->query($sql);

		return true;
	}

	/**
	 * Checks if a paired entry is an automatic (synthetic) entry
	 *
	 * @param int $pairid The rowid of the paired entry
	 * @return bool True if the paired entry is automatic, false otherwise
	 */
	private function isPairedWithAutomaticEntry($pairid)
	{
		global $db;

		$sql = "SELECT id_zs FROM " . MAIN_DB_PREFIX . "dolizsynch_zspunchclock_synch WHERE rowid = " . (int)$pairid;
		$resql = $db->query($sql);

		if ($resql && $db->num_rows($resql) > 0) {
			$obj = $db->fetch_object($resql);
			return empty($obj->id_zs);
		}

		return false; // Entry not found or error
	}


	/**
	 * Calculates the date value (dataval) based on the day change hour.
	 *
	 * This method calculates the date value (dataval) for a given timestamp (datahora).
	 * It adjusts the date based on the configured day change hour. If the timestamp is before the day change hour,
	 * the date is adjusted to the previous day.
	 *
	 * @param string $datahora Timestamp of the time register in 'Y-m-d H:i:s' format.
	 *
	 * @return string Calculated date value in 'Y-m-d' format.
	 *
	 * @throws Exception Throws an exception if there is an error during date calculation.
	 */
	private function calculateDataVal($datahora)
	{
		$date = new DateTime($datahora, new DateTimeZone('Europe/Lisbon'));

		if ($this->dayChangeHour > 0 && $date <= (clone $date)->setTime((int) $this->dayChangeHour, 0, 0)) {
			// If the hour is before the day change hour, subtract one day
			$date->modify('-1 day');
		}

		return $date->format('Y-m-d');
	}

	/**
	 * Resolve the Dolibarr user id for a ZoneSoft employee id.
	 *
	 * @param int $empId
	 * @return int|null
	 */
	private function getUserIdForEmployee($empId)
	{
		global $db;

		$empId = (int) $empId;
		if ($empId <= 0) {
			return null;
		}

		$sql = "SELECT fk_object FROM " . MAIN_DB_PREFIX . "user_extrafields WHERE zs_employee_id = '" . $db->escape($empId) . "' LIMIT 1";
		$resql = $db->query($sql);
		if ($resql && $db->num_rows($resql) > 0) {
			$obj = $db->fetch_object($resql);
			return (int) $obj->fk_object;
		}

		return null;
	}

	/**
	 * Try to infer the missing counterpart time using the weekly timetable (shift planner).
	 *
	 * We prefer using the scheduled shift boundaries before falling back to synthetic +/-1s entries,
	 * so that day closing uses planned hours when punches are missing.
	 *
	 * @param object        $timeRegister
	 * @param bool          $isEntry      True when the orphan is an IN punch (so we need OUT time)
	 * @param DateTimeZone  $timezone
	 * @return array<string, string>|null Array with keys datahora/dataval or null when no schedule match is found
	 */
	private function resolveCounterpartFromSchedule($timeRegister, $isEntry, DateTimeZone $timezone)
	{
		global $db;

		$fkUser = $this->getUserIdForEmployee($timeRegister->empid_zs ?? 0);
		if (empty($fkUser)) {
			return null;
		}

		try {
			$base = new DateTime($timeRegister->datahora, $timezone);
		} catch (Exception $e) {
			return null;
		}

		// Build the punch-day window based on the configured change hour
		$windowStart = clone $base;
		$windowStart->setTime((int) $this->dayChangeHour, 0, 0);
		if ($base < $windowStart || ($this->dayChangeHour > 0 && $base == $windowStart)) {
			$windowStart->modify('-1 day');
		}
		$windowEnd = clone $windowStart;
		$windowEnd->modify('+1 day');

		$sql = "SELECT rowid, date_start, date_end";
		$sql .= " FROM " . MAIN_DB_PREFIX . "dolizsynch_shift";
		$sql .= " WHERE fk_user = " . ((int) $fkUser);
		$sql .= " AND entity IN (" . getEntity('dolizsynch_shift') . ")";
		$sql .= " AND date_end > '" . $db->escape($windowStart->format('Y-m-d H:i:s')) . "'";
		$sql .= " AND date_start < '" . $db->escape($windowEnd->format('Y-m-d H:i:s')) . "'";
		$sql .= " ORDER BY date_start ASC";

		$resql = $db->query($sql);
		if (!$resql) {
			dol_syslog(__METHOD__ . " schedule lookup failed: " . $db->lasterror(), LOG_ERR);
			return null;
		}

		$best = null;
		while ($obj = $db->fetch_object($resql)) {
			try {
				$startDt = new DateTime($obj->date_start, $timezone);
				$endDt = new DateTime($obj->date_end, $timezone);
			} catch (Exception $e) {
				continue;
			}

			$coversPunch = ($base >= $startDt && $base <= $endDt);
			$distance = $coversPunch ? 0 : min(abs($base->getTimestamp() - $startDt->getTimestamp()), abs($base->getTimestamp() - $endDt->getTimestamp()));

			if ($best === null
				|| $coversPunch && !$best['covers']
				|| ($coversPunch === $best['covers'] && $distance < $best['distance'])) {
				$best = array(
					'start' => $startDt,
					'end' => $endDt,
					'covers' => $coversPunch,
					'distance' => $distance,
				);
				// Perfect match, we can stop early
				if ($coversPunch && $distance === 0) {
					break;
				}
			}
		}
		$db->free($resql);

		if ($best === null) {
			return null;
		}

		$target = $isEntry ? $best['end'] : $best['start'];
		// Make sure we do not create an impossible interval
		if ($isEntry && $target <= $base) {
			return null;
		}
		if (!$isEntry && $target >= $base) {
			return null;
		}

		$targetString = $target->format('Y-m-d H:i:s');

		return array(
			'datahora' => $targetString,
			'dataval' => $this->calculateDataVal($targetString),
		);
	}


	/**
	 * Finds the previous entry for a leave that doesn't already have a pair.
	 *
	 * This method searches the database to find a previous entry (sentido=1) that corresponds to a given leave (sentido=2).
	 * It looks for an entry with the same employee ID and store ID, and a timestamp earlier than the leave timestamp but before the end of the day,
	 * considering the configured day change hour.
	 * The method returns the previous entry if found, otherwise it returns null.
	 *
	 * @param object $leave Leave object containing the time register details.
	 *
	 * @return object|null Returns the previous entry object if found, null otherwise.
	 *
	 * @throws Exception Throws an exception if there is an error during the database query.
	 */
	private function findPreviousEntryForLeave($leave)
	{
		global $db, $conf;


		// Calculate the end datetime for fetching entries based on day change hour
		$dayChangeHour = $this->dayChangeHour;
		$endDateTime = new DateTime($leave->datahora, new DateTimeZone('Europe/Lisbon'));
		$endDateTime->setTime($dayChangeHour, 0);
		if ($endDateTime < new DateTime($leave->datahora, new DateTimeZone('Europe/Lisbon'))) {
			$endDateTime->modify('+1 day');
		}
		$endDateTimeString = $endDateTime->format('Y-m-d H:i:s');
		$startDateTime = new DateTimeImmutable($leave->datahora, new DateTimeZone('Europe/Lisbon'));
		$boundary = $startDateTime->setTime((int) $dayChangeHour, 0, 0);
		if ($startDateTime < $boundary || ($dayChangeHour > 0 && $startDateTime == $boundary)) {
			$boundary = $boundary->modify('-1 day');
		}
		if ($dayChangeHour > 0) {
			$boundary = $boundary->modify('+1 second');
		}

		// Use simplified query approach to avoid injection protection
		$table = MAIN_DB_PREFIX . "dolizsynch_zspunchclock_synch";
		$sql = "SELECT * FROM {$table} WHERE entity = " . ((int) $conf->entity);
		$sql .= " AND empid_zs = '" . $db->escape($leave->empid_zs) . "'";
		$sql .= " AND loja_zs = '" . $db->escape($leave->loja_zs) . "'";
		$sql .= " AND datahora < '" . $db->escape($leave->datahora) . "'";
		$sql .= " AND datahora >= '" . $db->escape($boundary->format('Y-m-d H:i:s')) . "'";
		$sql .= " AND datahora <= '" . $db->escape($endDateTimeString) . "'";
		$sql .= " AND sentido = 1";
		$sql .= " AND synch = 0";
		$sql .= " AND (pairid IS NULL OR pairid = 0)";
		$sql .= " ORDER BY datahora DESC LIMIT 1";

		$resql = $db->query($sql);
		if ($resql && $db->num_rows($resql) > 0) {
			return $db->fetch_object($resql);
		}

		return null;
	}


	/**
	 * Finds the next leave for an entry that doesn't already have a pair.
	 *
	 * This method searches the database to find the next leave (sentido=2) that corresponds to a given entry (sentido=1).
	 * It looks for a leave with the same employee ID and store ID, and a timestamp later than the entry timestamp but before the end of the day,
	 * considering the configured day change hour.
	 * The method returns the next leave if found, otherwise it returns null.
	 *
	 * @param object $entry Entry object containing the time register details.
	 *
	 * @return object|null Returns the next leave object if found, null otherwise.
	 *
	 * @throws Exception Throws an exception if there is an error during the database query.
	 */
	private function findNextLeaveForEntry($entry)
	{
		global $db, $conf;


		// Calculate the end datetime for fetching leaves based on day change hour
		$dayChangeHour = $this->dayChangeHour;
		$endDateTime = new DateTime($entry->datahora, new DateTimeZone('Europe/Lisbon'));
		$endDateTime->setTime($dayChangeHour, 0);
		if ($endDateTime < new DateTime($entry->datahora, new DateTimeZone('Europe/Lisbon'))) {
			$endDateTime->modify('+1 day');
		}
		$endDateTimeString = $endDateTime->format('Y-m-d H:i:s');

		// Use simplified query approach to avoid injection protection
		$table = MAIN_DB_PREFIX . "dolizsynch_zspunchclock_synch";
		$sql = "SELECT * FROM {$table} WHERE entity = " . ((int) $conf->entity);
		$sql .= " AND empid_zs = '" . $db->escape($entry->empid_zs) . "'";
		$sql .= " AND loja_zs = '" . $db->escape($entry->loja_zs) . "'";
		$sql .= " AND datahora > '" . $db->escape($entry->datahora) . "'";
		$sql .= " AND datahora <= '" . $db->escape($endDateTimeString) . "'";
		$sql .= " AND sentido = 2";
		$sql .= " AND synch = 0";
		$sql .= " AND pairid IS NULL";
		$sql .= " ORDER BY datahora ASC LIMIT 1";
		$resql = $db->query($sql);
		if ($resql && $db->num_rows($resql) > 0) {
			return $db->fetch_object($resql);
		}
		return null;
	}

	/**
	 * Updates the pair ID for both entry and leave.
	 *
	 * This method updates the database to pair an entry and a leave by setting their pair IDs.
	 * The entry points to the leave, and the leave points to the entry (mutual reference).
	 *
	 * @param int $entryId ID of the entry record.
	 * @param int $leaveId ID of the leave record.
	 *
	 * @return void
	 *
	 * @throws Exception Throws an exception if there is an error during the database update.
	 */
	private function updatePair($entryId, $leaveId)
	{
		global $user;


		$entry = new self($this->db);
		$leave = new self($this->db);

		// Update entry - points to itself (entry's rowid) - OLD SYSTEM
		if ($entry->fetch($entryId) > 0) {
			$entry->pairid = $entryId;
			$entry->update($user);
		} else {
			dol_syslog("ERROR: Failed to fetch entry with rowid=$entryId", LOG_ERR);
		}

		// Update leave - points to entry's rowid (same as entry) - OLD SYSTEM
		if ($leave->fetch($leaveId) > 0) {
			$leave->pairid = $entryId;
			$leave->update($user);
		} else {
			dol_syslog("ERROR: Failed to fetch leave with rowid=$leaveId", LOG_ERR);
		}
	}

	/**
	 * Creates a synthetic counterpart for an orphan entry or leave.
	 *
	 * This method creates a synthetic counterpart for an orphan entry or leave.
	 * If an entry is orphaned (missing a corresponding leave), it creates a synthetic leave.
	 * If a leave is orphaned (missing a corresponding entry), it creates a synthetic entry.
	 * The synthetic counterpart is inserted into the database, and the pair IDs are updated accordingly.
	 *
	 * @param object $timeRegister Time register object containing the details.
	 * @param DateTimeZone $timezone Timezone object for correct date handling.
	 * @param int $sentido Direction of the time register (1 for entry, 2 for leave).
	 *
	 * @return object|bool Returns the synthetic time register object if successful, false otherwise.
	 *
	 * @throws Exception Throws an exception if there is an error during the creation or database insertion.
	 *
	 * @uses calculateDataVal() to calculate the date value for the synthetic counterpart.
	 * @uses create() to insert the synthetic counterpart into the database.
	 */
	private function createSyntheticCounterpart($timeRegister, $timezone, $sentido)
	{
		global $db, $user;


		// Determine whether we're dealing with an entry or leave based on 'sentido'
		$isEntry = ($sentido == 1);
		$syntheticSentido = $isEntry ? 2 : 1; // If original is entry, synthetic is leave, and vice versa

		$datetime = new DateTime($timeRegister->datahora, $timezone);

		// Try to use the timetable to close the day before falling back to +/-1 second counterpart
		$scheduleCounterpart = $this->resolveCounterpartFromSchedule($timeRegister, $isEntry, $timezone);
		if ($scheduleCounterpart) {
			$datetime = new DateTime($scheduleCounterpart['datahora'], $timezone);
		} elseif ($isEntry) {
			// For synthetic leave, add 1 second
			$datetime->add(new DateInterval('PT1S'));
		} else {
			// For synthetic entry, subtract 1 second
			$datetime->sub(new DateInterval('PT1S'));
		}
		$datahora = $datetime->format('Y-m-d H:i:s');

		// Create synthetic time register object
		$syntheticTimeRegister = new self($db);
		$syntheticTimeRegister->loja_zs = $timeRegister->loja_zs;
		$syntheticTimeRegister->sentido = $syntheticSentido;
		$syntheticTimeRegister->empid_zs = $timeRegister->empid_zs;
		$syntheticTimeRegister->datahora = $datahora;
		$syntheticTimeRegister->dataval = $scheduleCounterpart['dataval'] ?? $this->calculateDataVal($datahora);
		$syntheticTimeRegister->synch = '0'; // Mark as unsynced (will be synced when pair is processed)
		$syntheticTimeRegister->zsbms_synch = 0;
		$syntheticTimeRegister->id_zs = null;
		// Set user tracking fields
		$syntheticTimeRegister->fk_user_creat = $user->id;
		$syntheticTimeRegister->fk_user_modif = $user->id;
		$syntheticTimeRegister->date_creation = dol_now();
		// Don't pre-set pairid - will be set by updatePair() after creation

		if ($syntheticTimeRegister->create($user) > 0) {
			return $syntheticTimeRegister; // Now $syntheticTimeRegister should have a valid ->rowid
		} else {
			dol_syslog("Failed to create synthetic time register: " . $syntheticTimeRegister->error, LOG_ERR);
			return false;
		}
	}

	/**
	 *
	 *
	 * END OF PAIR JOB METHODS
	 *
	 *
	 */

	/**
	 * Sends a message to a specified Telegram chat using the Telegram Bot API.
	 *
	 * This method constructs a message and sends it to a specified Telegram chat using the Telegram Bot API.
	 * It uses the configured API token and chat ID to authenticate and send the message. The message is URL-encoded
	 * to ensure proper transmission over HTTP.
	 *
	 * @param string $message The message text to be sent to the Telegram chat.
	 *
	 * @global Conf $conf Global configuration object containing Telegram API credentials.
	 *
	 * @return void
	 *
	 * @throws Exception Throws an exception if there is an error during the API call or message sending.
	 */
	public function sendMessageToTelegram($message)
	{
		global $conf;


		$apiToken = !empty($conf->global->ZS_TELEGRAM_API_KEY) ? $conf->global->ZS_TELEGRAM_API_KEY : null;
		$chatId = !empty($conf->global->ZS_TELEGRAM_CHAT_ID) ? $conf->global->ZS_TELEGRAM_CHAT_ID : null;

		if (empty($apiToken) || empty($chatId)) {
			dol_syslog("Telegram API Token or Chat ID not configured in module settings.", LOG_ERR);
			return false;
		}

		$apiUrl = "https://api.telegram.org/bot";

		$url = $apiUrl . $apiToken . "/sendMessage?parse_mode=HTML&chat_id=" . $chatId . "&text=" . urlencode($message);

		// Use cURL or any other method to send the request
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		$response = curl_exec($ch);
		$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);

		// Check if the request was successful
		$responseData = json_decode($response, true);
		if ($httpCode == 200 && isset($responseData['ok']) && $responseData['ok'] === true) {
			return true;
		}

		return false;
	}

	/**
	 * Checks and notifies if there are missed punches from the previous day.
	 *
	 * This method checks the database for missed punches (shifts less than one minute) from the previous day.
	 * It identifies the employees with missed punches and sends a notification message to a specified Telegram chat.
	 *
	 * @global DoliDB $db Global database connection object.
	 * @global Conf $conf Global configuration object.
	 *
	 * @return void
	 *
	 * @throws Exception Throws an exception if there is an error during the database query or message sending.
	 *
	 * @uses sendMessageToTelegram() to send the notification message.
	 * @uses getUserIdByEmpId() to retrieve the Dolibarr user ID associated with a given employee ID.
	 */
	public function notifyMissedPunches()
	{
		global $db, $conf;


		// Determine the notification time
		$timezone = new DateTimeZone('Europe/Lisbon');
		$now = new DateTime('now', $timezone);

		// Determine the date for the previous day considering the day change hour
		$previousDay = clone $now;
		$previousDay->modify('-1 day');
		$previousDayStr = $previousDay->format('Y-m-d');

		// Find pairs where the IN and OUT times are less than 1 minute apart (60 seconds)
		// This indicates a missed/failed punch that needs correction
		$sql = "
			SELECT DISTINCT pc.empid_zs, pc.fk_pairid, pc.datahora_in, pc.datahora_out, pc.duration
			FROM " . MAIN_DB_PREFIX . "dolizsynch_zspunchclock as pc
			WHERE DATE(pc.dataval) = '" . $db->escape($previousDayStr) . "'
			AND pc.entity = " . intval($conf->entity) . "
			AND pc.duration < 60
			AND pc.duration > 0
			ORDER BY pc.empid_zs
		";
		$resql = $db->query($sql);

		if (!$resql) {
			dol_syslog("SQL Error: " . $db->lasterror(), LOG_ERR);
			return -1; // Return error code for Dolibarr cron
		}

		$employeeData = [];
		$recordCount = 0;

		while ($obj = $db->fetch_object($resql)) {
			$recordCount++;

			$userId = $this->getUserIdByEmpId($obj->empid_zs);
			if ($userId) {
				$user = new User($db);
				if ($user->fetch($userId) > 0) {
					$userName = trim($user->firstname . ' ' . $user->lastname);

					// Group by employee
					if (!isset($employeeData[$obj->empid_zs])) {
						$employeeData[$obj->empid_zs] = [
							'name' => $userName,
							'count' => 0
						];
					}
					$employeeData[$obj->empid_zs]['count']++;
				}
			}
		}

		dol_syslog("Total employees with missed punches: " . count($employeeData), LOG_INFO);

		if (!empty($employeeData)) {
			$dateStr = $previousDay->format('d-m-Y'); // Format: DD-MM-YYYY
			$employeeList = [];

			foreach ($employeeData as $empId => $data) {
				$countStr = $data['count'] > 1 ? " ({$data['count']} picagens)" : "";
				$employeeList[] = $data['name'] . $countStr;
			}

			$employeeListStr = implode(', ', $employeeList);
			$message = "Funcionários com falhas na picagem de ponto no dia " . $dateStr . ": " . $employeeListStr . ". ";
			$message .= "Por favor, verifiquem e procedam à correção. ";
			$message .= "⚠️ ATENÇÃO: Se não for corrigido, os funcionários ficarão marcados como ausentes e não serão pagos pelo dia.";

			$result = $this->sendMessageToTelegram($message);

			if ($result < 0) {
				dol_syslog("Failed to send Telegram notification", LOG_ERR);
			} else {
				dol_syslog("Telegram notification sent successfully", LOG_INFO);
			}
		} else {
			dol_syslog("No missed punches found for notification", LOG_INFO);
		}

		// Return 0 for success (Dolibarr cron convention)
		return 0;
	}

	/**
	 * Retrieve the Dolibarr user ID by employee ID.
	 *
	 * This method retrieves the Dolibarr user ID associated with a given employee ID.
	 * It searches the database for a record in the user_extrafields table that matches the employee ID.
	 * If a matching record is found, it returns the corresponding user ID.
	 *
	 * @param int $empId Employee ID.
	 *
	 * @global DoliDB $db Global database connection object.
	 *
	 * @return int|bool The user ID if found, false otherwise.
	 *
	 * @throws Exception Throws an exception if there is an error during the database query.
	 */
	private function getUserIdByEmpId($empId)
	{
		global $db;


		$sql = "SELECT fk_object FROM " . MAIN_DB_PREFIX . "user_extrafields WHERE zs_employee_id = '" . $db->escape($empId) . "'";
		$resql = $db->query($sql);

		if ($resql) {
			if ($db->num_rows($resql) > 0) {
				$obj = $db->fetch_object($resql);
				return $obj->fk_object;
			} else {
				dol_syslog("No user found for employee ID: " . $empId, LOG_WARNING);
				return false; // No user found
			}
		} else {
			dol_syslog("Error fetching user by employee ID: " . $db->lasterror(), LOG_ERR);
			return false; // Query failed
		}
	}



















	/**
	 * Create object directly into database without using createCommon.
	 *
	 * @param  User $user      User that creates
	 * @param  bool $notrigger false=launch triggers after, true=disable triggers
	 * @return int             Return integer <0 if KO, Id of created object if OK
	 */
	public function create(User $user, $notrigger = false)
	{
		global $db, $conf;


		$error = 0;

		// Adjust the SQL query to match your table structure
		$sql = "INSERT INTO " . MAIN_DB_PREFIX . $this->table_element . " (";
		$sql .= "entity, date_creation, loja_zs, sentido, empid_zs, datahora, dataval, id_zs, synch, pairid, zsbms_synch, fk_user_creat, fk_user_modif";
		$sql .= ") VALUES (";
		$sql .= intval($conf->entity) . ", ";
		$sql .= (!empty($this->date_creation) ? "'" . $db->idate($this->date_creation) . "'" : 'NULL') . ", ";
		$sql .= "'" . $db->escape($this->loja_zs) . "', ";
		$sql .= intval($this->sentido) . ", ";
		$sql .= "'" . $db->escape($this->empid_zs) . "', ";
		$sql .= "'" . $db->escape($this->datahora) . "', ";
		$sql .= "'" . $db->escape($this->dataval) . "', ";
		$sql .= intval($this->id_zs) . ", ";
		$sql .= "'" . $db->escape($this->synch) . "', ";
		$sql .= (is_null($this->pairid) ? 'NULL' : intval($this->pairid)) . ", ";
		$sql .= intval($this->zsbms_synch) . ", ";
		$sql .= (!empty($this->fk_user_creat) ? intval($this->fk_user_creat) : 'NULL') . ", ";
		$sql .= (!empty($this->fk_user_modif) ? intval($this->fk_user_modif) : 'NULL');
		$sql .= ")";

		$db->begin(); // Start transaction

		$resql = $db->query($sql);
		if (!$resql) {
			$error++;
			$this->errors[] = $db->lasterror();
		}

		if (!$error) {
			$this->id = $db->last_insert_id($this->table_element);
			$this->rowid = $this->id; // Set rowid for consistency

			// Triggers
			if (!$notrigger) {
				// Call triggers
				$result = $this->call_trigger(strtoupper(get_class($this)) . '_CREATE', $user);
				if ($result < 0) {
					$error++;
				}
				// End call triggers
			}
		}

		// Commit or rollback
		if ($error) {
			$db->rollback();
			return -1;
		} else {
			$db->commit();
			return $this->id;
		}
	}




































	//public function create(User $user, $notrigger = false)
	//{
	//	$resultcreate = $this->createCommon($user, $notrigger);
	//
	//	//$resultvalidate = $this->validate($user, $notrigger);
	//
	//	return $resultcreate;
	//}

	/**
	 * Clone an object into another one
	 *
	 * @param  	User 	$user      	User that creates
	 * @param  	int 	$fromid     Id of object to clone
	 * @return 	mixed 				New object created, <0 if KO
	 */
	public function createFromClone(User $user, $fromid)
	{
		global $langs, $extrafields;
		$error = 0;


		$object = new self($this->db);

		$this->db->begin();

		// Load source object
		$result = $object->fetchCommon($fromid);
		if ($result > 0 && !empty($object->table_element_line)) {
			$object->fetchLines();
		}

		// get lines so they will be clone
		//foreach($this->lines as $line)
		//	$line->fetch_optionals();

		// Reset some properties
		unset($object->id);
		unset($object->fk_user_creat);
		unset($object->import_key);

		// Clear fields
		if (property_exists($object, 'ref')) {
			$object->ref = empty($this->fields['ref']['default']) ? "Copy_Of_" . $object->ref : $this->fields['ref']['default'];
		}
		if (property_exists($object, 'label')) {
			$object->label = empty($this->fields['label']['default']) ? $langs->trans("CopyOf") . " " . $object->label : $this->fields['label']['default'];
		}
		if (property_exists($object, 'status')) {
			$object->status = self::STATUS_DRAFT;
		}
		if (property_exists($object, 'date_creation')) {
			$object->date_creation = dol_now('gmt');
		}
		if (property_exists($object, 'date_modification')) {
			$object->date_modification = null;
		}
		// ...
		// Clear extrafields that are unique
		if (is_array($object->array_options) && count($object->array_options) > 0) {
			$extrafields->fetch_name_optionals_label($this->table_element);
			foreach ($object->array_options as $key => $option) {
				$shortkey = preg_replace('/options_/', '', $key);
				if (!empty($extrafields->attributes[$this->table_element]['unique'][$shortkey])) {
					//var_dump($key);
					//var_dump($clonedObj->array_options[$key]); exit;
					unset($object->array_options[$key]);
				}
			}
		}

		// Create clone
		$object->context['createfromclone'] = 'createfromclone';
		$result = $object->createCommon($user);
		if ($result < 0) {
			$error++;
			$this->setErrorsFromObject($object);
		}

		if (!$error) {
			// copy internal contacts
			if ($this->copy_linked_contact($object, 'internal') < 0) {
				$error++;
			}
		}

		if (!$error) {
			// copy external contacts if same company
			if (!empty($object->socid) && property_exists($this, 'fk_soc') && $this->fk_soc == $object->socid) {
				if ($this->copy_linked_contact($object, 'external') < 0) {
					$error++;
				}
			}
		}

		unset($object->context['createfromclone']);

		// End
		if (!$error) {
			$this->db->commit();
			return $object;
		} else {
			$this->db->rollback();
			return -1;
		}
	}

	/**
	 * Load object in memory from the database
	 *
	 * @param 	int    	$id   			Id object
	 * @param 	string 	$ref  			Ref
	 * @param	int		$noextrafields	0=Default to load extrafields, 1=No extrafields
	 * @param	int		$nolines		0=Default to load extrafields, 1=No extrafields
	 * @return 	int     				Return integer <0 if KO, 0 if not found, >0 if OK
	 */
	public function fetch($id, $ref = null, $noextrafields = 0, $nolines = 0)
	{
		$result = $this->fetchCommon($id, $ref, '', $noextrafields);
		if ($result > 0 && !empty($this->table_element_line) && empty($nolines)) {
			$this->fetchLines($noextrafields);
		}
		return $result;
	}

	/**
	 * Load object lines in memory from the database
	 *
	 * @param	int		$noextrafields	0=Default to load extrafields, 1=No extrafields
	 * @return 	int         			Return integer <0 if KO, 0 if not found, >0 if OK
	 */
	public function fetchLines($noextrafields = 0)
	{
		$this->lines = array();

		$result = $this->fetchLinesCommon('', $noextrafields);
		return $result;
	}


	/**
	 * Load list of objects in memory from the database. Using a fetchAll is a bad practice, instead try to forge you optimized and limited SQL request.
	 *
	 * @param  string      $sortorder    Sort Order
	 * @param  string      $sortfield    Sort field
	 * @param  int         $limit        limit
	 * @param  int         $offset       Offset
	 * @param  array       $filter       Filter array. Example array('mystringfield'=>'value', 'myintfield'=>4, 'customsql'=>...)
	 * @param  string      $filtermode   Filter mode (AND or OR)
	 * @return array|int                 int <0 if KO, array of pages if OK
	 */
	public function fetchAll($sortorder = '', $sortfield = '', $limit = 0, $offset = 0, array $filter = array(), $filtermode = 'AND')
	{

		$records = array();

		$sql = "SELECT ";
		$sql .= $this->getFieldList('t');
		$sql .= " FROM " . $this->db->prefix() . $this->table_element . " as t";
		if (isset($this->isextrafieldmanaged) && $this->isextrafieldmanaged == 1) {
			$sql .= " LEFT JOIN " . $this->db->prefix() . $this->table_element . "_extrafields as te ON te.fk_object = t.rowid";
		}
		if (isset($this->ismultientitymanaged) && $this->ismultientitymanaged == 1) {
			$sql .= " WHERE t.entity IN (" . getEntity($this->element) . ")";
		} else {
			$sql .= " WHERE 1 = 1";
		}
		// Manage filter
		$sqlwhere = array();
		if (count($filter) > 0) {
			foreach ($filter as $key => $value) {
				$columnName = preg_replace('/^t\./', '', $key);
				if ($key === 'customsql') {
					// Never use 'customsql' with a value from user input since it is injected as is. The value must be hard coded.
					$sqlwhere[] = $value;
					continue;
				} elseif (isset($this->fields[$columnName])) {
					$type = $this->fields[$columnName]['type'];
					if (preg_match('/^integer/', $type)) {
						if (ist($value)) {
							// single value
							$sqlwhere[] = $key . " = " . intval($value);
						} elseif (is_array($value)) {
							if (empty($value)) {
								continue;
							}
							$sqlwhere[] = $key . ' IN (' . $this->db->sanitize(implode(',', array_map('intval', $value))) . ')';
						}
						continue;
					} elseif (in_array($type, array('date', 'datetime', 'timestamp'))) {
						$sqlwhere[] = $key . " = '" . $this->db->idate($value) . "'";
						continue;
					}
				}

				// when the $key doesn't fall into the previously handled categories, we do as if the column were a varchar/text
				if (is_array($value) && count($value)) {
					$value = implode(',', array_map(function ($v) {
						return "'" . $this->db->sanitize($this->db->escape($v)) . "'";
					}, $value));
					$sqlwhere[] = $key . ' IN (' . $this->db->sanitize($value, true) . ')';
				} elseif (is_scalar($value)) {
					if (strpos($value, '%') === false) {
						$sqlwhere[] = $key . " = '" . $this->db->sanitize($this->db->escape($value)) . "'";
					} else {
						$sqlwhere[] = $key . " LIKE '%" . $this->db->escape($this->db->escapeforlike($value)) . "%'";
					}
				}
			}
		}
		if (count($sqlwhere) > 0) {
			$sql .= " AND (" . implode(" " . $filtermode . " ", $sqlwhere) . ")";
		}

		if (!empty($sortfield)) {
			$sql .= $this->db->order($sortfield, $sortorder);
		}
		if (!empty($limit)) {
			$sql .= $this->db->plimit($limit, $offset);
		}

		$resql = $this->db->query($sql);
		if ($resql) {
			$num = $this->db->num_rows($resql);
			$i = 0;
			while ($i < ($limit ? min($limit, $num) : $num)) {
				$obj = $this->db->fetch_object($resql);

				$record = new self($this->db);
				$record->setVarsFromFetchObj($obj);

				$records[$record->id] = $record;

				$i++;
			}
			$this->db->free($resql);

			return $records;
		} else {
			$this->errors[] = 'Error ' . $this->db->lasterror();
			dol_syslog(__METHOD__ . ' ' . join(',', $this->errors), LOG_ERR);

			return -1;
		}
	}

	/**
	 * Update object into database
	 *
	 * @param  User $user      User that modifies
	 * @param  bool $notrigger false=launch triggers after, true=disable triggers
	 * @return int             Return integer <0 if KO, >0 if OK
	 */
	public function update(User $user, $notrigger = false)
	{
		return $this->updateCommon($user, $notrigger);
	}

	/**
	 * Delete object in database
	 *
	 * @param User $user       User that deletes
	 * @param bool $notrigger  false=launch triggers, true=disable triggers
	 * @return int             Return integer <0 if KO, >0 if OK
	 */
	public function delete(User $user, $notrigger = false)
	{
		return $this->deleteCommon($user, $notrigger);
		//return $this->deleteCommon($user, $notrigger, 1);
	}

	/**
	 *  Delete a line of object in database
	 *
	 *	@param  User	$user       User that delete
	 *  @param	int		$idline		Id of line to delete
	 *  @param 	bool 	$notrigger  false=launch triggers after, true=disable triggers
	 *  @return int         		>0 if OK, <0 if KO
	 */
	public function deleteLine(User $user, $idline, $notrigger = false)
	{
		if ($this->status < 0) {
			$this->error = 'ErrorDeleteLineNotAllowedByObjectStatus';
			return -2;
		}

		return $this->deleteLineCommon($user, $idline, $notrigger);
	}


	/**
	 *	Validate object
	 *
	 *	@param		User	$user     		User making status change
	 *  @param		int		$notrigger		1=Does not execute triggers, 0= execute triggers
	 *	@return  	int						Return integer <=0 if OK, 0=Nothing done, >0 if KO
	 */
	public function validate($user, $notrigger = 0)
	{
		global $conf, $langs;

		require_once DOL_DOCUMENT_ROOT . '/core/lib/files.lib.php';

		$error = 0;

		// Protection
		if ($this->status == self::STATUS_VALIDATED) {
			dol_syslog(get_class($this) . "::validate action abandonned: already validated", LOG_WARNING);
			return 0;
		}

		if (!$user->hasRight('dolizsynch', 'zspunchclock', 'write')) {
			$this->error = 'NotEnoughPermissions';
			dol_syslog(get_class($this) . "::validate " . $this->error, LOG_ERR);
			return -1;
		}

		$now = dol_now('gmt');

		$this->db->begin();

		// Define new ref
		if (!$error && (preg_match('/^[\(]?PROV/i', $this->ref) || empty($this->ref))) { // empty should not happened, but when it occurs, the test save life
			$num = $this->getNextNumRef();
		} else {
			$num = $this->ref;
		}
		$this->newref = $num;

		if (!empty($num)) {
			// Validate
			$sql = "UPDATE " . MAIN_DB_PREFIX . $this->table_element;
			$sql .= " SET ref = '" . $this->db->escape($num) . "',";
			$sql .= " status = " . self::STATUS_VALIDATED;
			if (!empty($this->fields['date_validation'])) {
				$sql .= ", date_validation = '" . $this->db->idate($now) . "'";
			}
			if (!empty($this->fields['fk_user_valid'])) {
				$sql .= ", fk_user_valid = " . ((int) $user->id);
			}
			$sql .= " WHERE rowid = " . ((int) $this->id);

			$resql = $this->db->query($sql);
			if (!$resql) {
				dol_print_error($this->db);
				$this->error = $this->db->lasterror();
				$error++;
			}

			if (!$error && !$notrigger) {
				// Call trigger
				$result = $this->call_trigger('MYOBJECT_VALIDATE', $user);
				if ($result < 0) {
					$error++;
				}
				// End call triggers
			}
		}

		if (!$error) {
			$this->oldref = $this->ref;

			// Rename directory if dir was a temporary ref
			if (preg_match('/^[\(]?PROV/i', $this->ref)) {
				// Now we rename also files into index
				$sql = 'UPDATE ' . MAIN_DB_PREFIX . "ecm_files set filename = CONCAT('" . $this->db->escape($this->newref) . "', SUBSTR(filename, " . (strlen($this->ref) + 1) . ")), filepath = 'zspunchclock_synch/" . $this->db->escape($this->newref) . "'";
				$sql .= " WHERE filename LIKE '" . $this->db->escape($this->ref) . "%' AND filepath = 'zspunchclock_synch/" . $this->db->escape($this->ref) . "' and entity = " . $conf->entity;
				$resql = $this->db->query($sql);
				if (!$resql) {
					$error++;
					$this->error = $this->db->lasterror();
				}
				$sql = 'UPDATE ' . MAIN_DB_PREFIX . "ecm_files set filepath = 'zspunchclock_synch/" . $this->db->escape($this->newref) . "'";
				$sql .= " WHERE filepath = 'zspunchclock_synch/" . $this->db->escape($this->ref) . "' and entity = " . $conf->entity;
				$resql = $this->db->query($sql);
				if (!$resql) {
					$error++;
					$this->error = $this->db->lasterror();
				}

				// We rename directory ($this->ref = old ref, $num = new ref) in order not to lose the attachments
				$oldref = dol_sanitizeFileName($this->ref);
				$newref = dol_sanitizeFileName($num);
				$dirsource = $conf->dolizsynch->dir_output . '/zspunchclock_synch/' . $oldref;
				$dirdest = $conf->dolizsynch->dir_output . '/zspunchclock_synch/' . $newref;
				if (!$error && file_exists($dirsource)) {
					if (@rename($dirsource, $dirdest)) {
						// Rename docs starting with $oldref with $newref
						$listoffiles = dol_dir_list($conf->dolizsynch->dir_output . '/zspunchclock_synch/' . $newref, 'files', 1, '^' . preg_quote($oldref, '/'));
						foreach ($listoffiles as $fileentry) {
							$dirsource = $fileentry['name'];
							$dirdest = preg_replace('/^' . preg_quote($oldref, '/') . '/', $newref, $dirsource);
							$dirsource = $fileentry['path'] . '/' . $dirsource;
							$dirdest = $fileentry['path'] . '/' . $dirdest;
							@rename($dirsource, $dirdest);
						}
					}
				}
			}
		}

		// Set new ref and current status
		if (!$error) {
			$this->ref = $num;
			$this->status = self::STATUS_VALIDATED;
		}

		if (!$error) {
			$this->db->commit();
			return 1;
		} else {
			$this->db->rollback();
			return -1;
		}
	}

	/**
	 *	Set draft status
	 *
	 *	@param	User	$user			Object user that modify
	 *  @param	int		$notrigger		1=Does not execute triggers, 0=Execute triggers
	 *	@return	int						Return integer <0 if KO, >0 if OK
	 */
	public function setDraft($user, $notrigger = 0)
	{
		// Protection
		if ($this->status <= self::STATUS_DRAFT) {
			return 0;
		}

		if (!$user->hasRight('dolizsynch', 'zspunchclock', 'write')) {
			$this->error = 'Permission denied';
			return -1;
		}

		return $this->setStatusCommon($user, self::STATUS_DRAFT, $notrigger, 'MYMODULE_MYOBJECT_UNVALIDATE');
	}

	/**
	 *	Set cancel status
	 *
	 *	@param	User	$user			Object user that modify
	 *  @param	int		$notrigger		1=Does not execute triggers, 0=Execute triggers
	 *	@return	int						Return integer <0 if KO, 0=Nothing done, >0 if OK
	 */
	public function cancel($user, $notrigger = 0)
	{
		// Protection
		if ($this->status != self::STATUS_VALIDATED) {
			return 0;
		}

		if (!$user->hasRight('dolizsynch', 'zspunchclock', 'write')) {
			$this->error = 'Permission denied';
			return -1;
		}

		return $this->setStatusCommon($user, self::STATUS_CANCELED, $notrigger, 'MYMODULE_MYOBJECT_CANCEL');
	}

	/**
	 *	Set back to validated status
	 *
	 *	@param	User	$user			Object user that modify
	 *  @param	int		$notrigger		1=Does not execute triggers, 0=Execute triggers
	 *	@return	int						Return integer <0 if KO, 0=Nothing done, >0 if OK
	 */
	public function reopen($user, $notrigger = 0)
	{
		// Protection
		if ($this->status == self::STATUS_VALIDATED) {
			return 0;
		}

		if (!$user->hasRight('dolizsynch', 'zspunchclock', 'write')) {
			$this->error = 'Permission denied';
			return -1;
		}

		return $this->setStatusCommon($user, self::STATUS_VALIDATED, $notrigger, 'MYMODULE_MYOBJECT_REOPEN');
	}

	/**
	 *	Load the info information in the object
	 *
	 *	@param  int		$id       Id of object
	 *	@return	void
	 */
	public function info($id)
	{
		$sql = "SELECT rowid,";
		$sql .= " date_creation as datec, tms as datem";
		if (!empty($this->fields['date_validation'])) {
			$sql .= ", date_validation as datev";
		}
		if (!empty($this->fields['fk_user_creat'])) {
			$sql .= ", fk_user_creat";
		}
		if (!empty($this->fields['fk_user_modif'])) {
			$sql .= ", fk_user_modif";
		}
		if (!empty($this->fields['fk_user_valid'])) {
			$sql .= ", fk_user_valid";
		}
		$sql .= " FROM " . MAIN_DB_PREFIX . $this->table_element . " as t";
		$sql .= " WHERE t.rowid = " . ((int) $id);

		$result = $this->db->query($sql);
		if ($result) {
			if ($this->db->num_rows($result)) {
				$obj = $this->db->fetch_object($result);

				$this->id = $obj->rowid;

				if (!empty($this->fields['fk_user_creat'])) {
					$this->user_creation_id = $obj->fk_user_creat;
				}
				if (!empty($this->fields['fk_user_modif'])) {
					$this->user_modification_id = $obj->fk_user_modif;
				}
				if (!empty($this->fields['fk_user_valid'])) {
					$this->user_validation_id = $obj->fk_user_valid;
				}
				$this->date_creation     = $this->db->jdate($obj->datec);
				$this->date_modification = empty($obj->datem) ? '' : $this->db->jdate($obj->datem);
				if (!empty($obj->datev)) {
					$this->date_validation   = empty($obj->datev) ? '' : $this->db->jdate($obj->datev);
				}
			}

			$this->db->free($result);
		} else {
			dol_print_error($this->db);
		}
	}

	/**
	 *  Returns the reference to the following non used object depending on the active numbering module.
	 *
	 *  @return string      		Object free reference
	 */
	public function getNextNumRef()
	{
		global $langs, $conf;
		$langs->load("dolizsynch@kreaflow");

		if (!getDolGlobalString('MYMODULE_MYOBJECT_ADDON')) {
			$conf->global->MYMODULE_MYOBJECT_ADDON = 'mod_zspunchclock_synch_standard';
		}

		if (getDolGlobalString('MYMODULE_MYOBJECT_ADDON')) {
			$mybool = false;

			$file = getDolGlobalString('MYMODULE_MYOBJECT_ADDON') . ".php";
			$classname = getDolGlobalString('MYMODULE_MYOBJECT_ADDON');

			// Include file with class
			$dirmodels = array_merge(array('/'), (array) $conf->modules_parts['models']);
			foreach ($dirmodels as $reldir) {
				$dir = dol_buildpath($reldir . "core/modules/kreaflow/");

				// Load file with numbering class (if found)
				$mybool |= @include_once $dir . $file;
			}

			if ($mybool === false) {
				dol_print_error('', "Failed to include file " . $file);
				return '';
			}

			if (class_exists($classname)) {
				$obj = new $classname();
				$numref = $obj->getNextValue($this);

				if ($numref != '' && $numref != '-1') {
					return $numref;
				} else {
					$this->error = $obj->error;
					//dol_print_error($this->db,get_class($this)."::getNextNumRef ".$obj->error);
					return "";
				}
			} else {
				print $langs->trans("Error") . " " . $langs->trans("ClassNotFound") . ' ' . $classname;
				return "";
			}
		} else {
			print $langs->trans("ErrorNumberingModuleNotSetup", $this->element);
			return "";
		}
	}

	/**
	 * Action executed by scheduler
	 * CAN BE A CRON TASK. In such a case, parameters come from the schedule job setup field 'Parameters'
	 * Use public function doScheduledJob($param1, $param2, ...) to get parameters
	 *
	 * @return	int			0 if OK, <>0 if KO (this function is used also by cron so only 0 is OK)
	 */
	public function doScheduledJob()
	{
		//global $conf, $langs;

		//$conf->global->SYSLOG_FILE = 'DOL_DATA_ROOT/dolibarr_mydedicatedlogfile.log';

		$error = 0;
		$this->output = '';
		$this->error = '';

		$now = dol_now('gmt');

		$this->db->begin();

		// ...

		$this->db->commit();

		return $error;
	}



	/**
	 *
	 * OBSOLETO
	 *
	 */

	/**
	 * Send a Telegram message for missed punches.
	 *
	 * @param int $empId Employee ID to notify about missed punches.
	 */
	public function sendTelegramMessageMissedPunch($empId)
	{
		global $db;


		if ($empId <= 0) {
			dol_syslog("User ID not set or invalid", LOG_ERR);
			return;
		}

		$sql = "SELECT fk_object FROM " . MAIN_DB_PREFIX . "user_extrafields WHERE zs_employee_id = '" . $db->escape($empId) . "'";
		$resql = $db->query($sql);

		if ($resql) {
			if ($db->num_rows($resql) > 0) {
				$obj = $db->fetch_object($resql);
				$userID = $obj->fk_object; // Correctly accessing the userID
			} else {
				dol_syslog("No user found for employee ID: " . $empId, LOG_WARNING);
				return false; // No user found
			}
		} else {
			dol_syslog("Error fetching user by employee ID: " . $db->lasterror(), LOG_ERR);
			return false; // Query failed
		}

		$user = new User($db);
		if ($user->fetch($userID) > 0) { // Correctly checking the fetch result
			// Concatenate the first name and last name to get the full name.
			$message = "O funcionário ";
			$message .= trim($user->firstname . ' ' . $user->lastname);
			$message .= " tem picagens de ponto com falhas, por favor verifiquem e procedam à correcção das mesmas.";
			$this->sendMessageToTelegram($message);
		} else {
			dol_syslog("User not found or error fetching user", LOG_ERR);
			return;
		}
	}

	public function isPunchClockEnabled()
	{
		return (bool) $this->punchClockEnabled;
	}
}

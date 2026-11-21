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

require_once DOL_DOCUMENT_ROOT . '/core/class/commonobject.class.php';
require_once DOL_DOCUMENT_ROOT . '/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT . '/core/class/timespent.class.php';
require_once DOL_DOCUMENT_ROOT . '/custom/kreaflow/class/zsapisynch.class.php';
require_once DOL_DOCUMENT_ROOT . '/projet/class/project.class.php';
require_once DOL_DOCUMENT_ROOT . '/projet/class/task.class.php';
require_once DOL_DOCUMENT_ROOT . '/custom/kreaflow/class/zspunchclock_synch.class.php';

/**
 * Class for ZSPunchClock
 */
class ZSPunchClock extends CommonObject
{
	/**
	 * @var string ID of module.
	 */
	public $module = 'dolizsynch';

	/**
	 * @var string ID to identify managed object.
	 */
	public $element = 'zspunchclock';

	/**
	 * @var string Name of table without prefix where object is stored. This is also the key used for extrafields management.
	 */
	public $table_element = 'dolizsynch_zspunchclock';

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

	private $originalUser;
	private $punchClockEnabled = false;


	const STATUS_DRAFT = 0;
	const STATUS_VALIDATED = 1;
	const STATUS_CANCELED = 9;

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
		"ref" => array("type" => "varchar(128)", "label" => "Ref", "enabled" => "1", 'position' => 20, 'notnull' => 0, "visible" => "1", "index" => "1", "searchall" => "1", "showoncombobox" => "1", "validate" => "1", "comment" => "Reference of object"),
		"loja_zs" => array("type" => "integer", "label" => "Store", "enabled" => "1", 'position' => 50, 'notnull' => 0, "visible" => "0",),
		"empid_zs" => array("type" => "integer", "label" => "EmpID", "enabled" => "1", 'position' => 50, 'notnull' => 0, "visible" => "1",),
		"fk_user" => array("type" => "integer:User:user/class/user.class.php", "label" => "User", "enabled" => "1", 'position' => 50, 'notnull' => 0, "visible" => "-1", "csslist" => "tdoverflowmax150",),
		"dataval" => array("type" => "date", "label" => "dataval", "enabled" => "1", 'position' => 50, 'notnull' => 0, "visible" => "-1",),
		"sentido_in" => array("type" => "integer", "label" => "sentido_in", "enabled" => "1", 'position' => 50, 'notnull' => 0, "visible" => "-1", "arrayofkeyval" => array("1" => "Entrada", "2" => "Saida"),),
		"datahora_in" => array("type" => "datetime", "label" => "checkin", "enabled" => "1", 'position' => 50, 'notnull' => 0, "visible" => "-1",),
		"id_zs_in" => array("type" => "integer", "label" => "id_zs_in", "enabled" => "1", 'position' => 50, 'notnull' => 0, "visible" => "0", "default" => "0",),
		"sentido_out" => array("type" => "integer", "label" => "sentido_out", "enabled" => "1", 'position' => 50, 'notnull' => 0, "visible" => "-1", "arrayofkeyval" => array("1" => "Entrada", "2" => "Saida"),),
		"datahora_out" => array("type" => "datetime", "label" => "checkout", "enabled" => "1", 'position' => 50, 'notnull' => 0, "visible" => "-1",),
		"id_zs_out" => array("type" => "integer", "label" => "id_zs_out", "enabled" => "1", 'position' => 50, 'notnull' => 0, "visible" => "0", "default" => "0",),
		"duration" => array("type" => "integer", "label" => "duration", "enabled" => "1", 'position' => 50, 'notnull' => 0, "visible" => "1", "isameasure" => "1",),
		"fk_pairid" => array("type" => "integer", "label" => "pairid", "enabled" => "1", 'position' => 50, 'notnull' => 0, "visible" => "0",),
		"fk_task" => array("type" => "integer:task:projet/class/task.class.php:1", "label" => "Dia de trabalho", "enabled" => "isModEnabled('project')", 'position' => 52, 'notnull' => -1, "visible" => "-1", "index" => "1", "css" => "maxwidth500 widthcentpercentminusxx", "csslist" => "tdoverflowmax150", "validate" => "1",),
		"import_key" => array("type" => "varchar(14)", "label" => "ImportId", "enabled" => "1", 'position' => 1000, 'notnull' => -1, "visible" => "0",),
		"fk_user_creat" => array("type" => "integer:User:user/class/user.class.php", "label" => "UserAuthor", "enabled" => "1", 'position' => 510, 'notnull' => 1, "visible" => "0", "csslist" => "tdoverflowmax150",),
		"fk_user_modif" => array("type" => "integer:user:user/class/user.class.php", "label" => "UserModif", "enabled" => "1", 'position' => 511, 'notnull' => -1, "visible" => "0", "csslist" => "tdoverflowmax150",),
		"note_public" => array("type" => "html", "label" => "NotePublic", "enabled" => "1", 'position' => 61, 'notnull' => 0, "visible" => "0", "cssview" => "wordbreak", "validate" => "1",),
		"date_creation" => array("type" => "datetime", "label" => "DateCreation", "enabled" => "1", 'position' => 500, 'notnull' => 1, "visible" => "0",),
		"tms" => array("type" => "timestamp", "label" => "DateModification", "enabled" => "1", 'position' => 501, 'notnull' => 0, "visible" => "-2",),
		"status" => array("type" => "integer", "label" => "Status", "enabled" => "1", 'position' => 2000, 'notnull' => 1, "visible" => "0", "index" => "1", "arrayofkeyval" => array("0" => "Rascunho", "1" => "Validado", "9" => "Cancelado"), "validate" => "1",),
		"synch" => array("type" => "integer", "label" => "synch", "enabled" => "1", 'position' => 50, 'notnull' => 0, "visible" => "0", "default" => "0",),
		"real_entry" => array("type" => "integer", "label" => "RealEntry", "enabled" => "1", 'position' => 51, 'notnull' => 0, "visible" => "0", "default" => "1",),
		"fk_timespent" => array("type" => "integer", "label" => "fk_timespent", "enabled" => "1", 'position' => 50, 'notnull' => 0, "visible" => "0",),
		"position" => array("type" => "integer", "label" => "position", "enabled" => "1", 'position' => 50, 'notnull' => 0, "visible" => "0", "default" => "0",),
	);
	public $rowid;
	public $entity;
	public $ref;
	public $loja_zs;
	public $empid_zs;
	public $fk_user;
	public $dataval;
	public $sentido_in;
	public $datahora_in;
	public $id_zs_in;
	public $sentido_out;
	public $datahora_out;
	public $id_zs_out;
	public $duration;
	public $fk_pairid;
	public $fk_task;
	public $import_key;
	public $fk_user_creat;
	public $fk_user_modif;
	public $note_public;
	public $date_creation;
	public $tms;
	public $status;
	public $synch;
	public $real_entry = 1;
	public $fk_timespent;
	public $position;
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

		// Resolve punch clock activation flag inline to avoid deprecation handler issues
		$activationValue = null;
		if (isset($conf->global->ZS_PUNCH_CLOCK_ACTIVATE) && $conf->global->ZS_PUNCH_CLOCK_ACTIVATE !== '') {
			$activationValue = $conf->global->ZS_PUNCH_CLOCK_ACTIVATE;
		} elseif (function_exists('getDolGlobalString')) {
			$activationValue = getDolGlobalString('ZS_PUNCH_CLOCK_ACTIVATE', '');
		}

		// Normalize the value
		if (is_bool($activationValue)) {
			$this->punchClockEnabled = $activationValue;
		} elseif (is_numeric($activationValue)) {
			$this->punchClockEnabled = ((float) $activationValue) != 0;
		} elseif (is_string($activationValue)) {
			$valueString = strtolower(trim($activationValue));
			$truthy = array('1', 'y', 'yes', 'on', 'true', 'enabled', 'enable');
			$falsy = array('0', 'n', 'no', 'off', 'false', 'disabled', 'disable');
			if (in_array($valueString, $truthy, true)) {
				$this->punchClockEnabled = true;
			} elseif (in_array($valueString, $falsy, true)) {
				$this->punchClockEnabled = false;
			} elseif ($valueString !== '') {
				$this->punchClockEnabled = true;
			} else {
				$this->punchClockEnabled = false;
			}
		} else {
			$this->punchClockEnabled = false;
		}

		if (!$this->punchClockEnabled) {
			dol_syslog(__CLASS__ . '::' . __FUNCTION__ . ' ZS_PUNCH_CLOCK_ACTIVATE is disabled or not configured (falling back to enabled state)', LOG_WARNING);
			$this->punchClockEnabled = true;
			dol_syslog(__CLASS__ . '::' . __FUNCTION__ . ' After fallback, punchClockEnabled = ' . var_export($this->punchClockEnabled, true), LOG_WARNING);
		}
		$this->originalUser = null;

		if (!getDolGlobalInt('MAIN_SHOW_TECHNICAL_ID') && isset($this->fields['rowid']) && !empty($this->fields['ref'])) {
			$this->fields['rowid']['visible'] = 0;
		}
		if (!isModEnabled('multicompany') && isset($this->fields['entity'])) {
			$this->fields['entity']['enabled'] = 0;
		}

		// Example to show how to set values of fields definition dynamically
		/*if ($user->hasRight('dolizsynch', 'zspunchclock', 'read')) {
			$this->fields['myfield']['visible'] = 1;
			$this->fields['myfield']['noteditable'] = 0;
		}*/

		// Unset fields that are disabled
		foreach ($this->fields as $key => $val) {
			if (isset($val['enabled']) && empty($val['enabled'])) {
				unset($this->fields[$key]);
			}
		}

		// Translate some data of arrayofkeyval
		if (is_object($langs)) {
			foreach ($this->fields as $key => $val) {
				if (!empty($val['arrayofkeyval']) && is_array($val['arrayofkeyval'])) {
					foreach ($val['arrayofkeyval'] as $key2 => $val2) {
						$this->fields[$key]['arrayofkeyval'][$key2] = $langs->trans($val2);
					}
				}
			}
		}
	}


	public function isPunchClockEnabled()
	{
		return (bool) $this->punchClockEnabled;
	}


	public function integratePunchClockData($dateFrom = null, $dateTo = null)
	{
		global $user, $conf;


		$sql = "SELECT ";
		$sql .= "e1.entity AS entity, ";
		$sql .= "e1.loja_zs AS loja_zs, ";
		$sql .= "e1.empid_zs AS empid_zs, ";
		$sql .= "e1.dataval AS dataval, ";
		$sql .= "e1.sentido AS sentido_in, ";
		$sql .= "e1.datahora AS datahora_in, ";
		$sql .= "e1.id_zs AS id_zs_in, ";
		$sql .= "e1.zsbms_synch AS zsbms_synch_in, ";
		$sql .= "e1.fk_user_creat AS fk_user_creat_in, ";
		$sql .= "e1.fk_user_modif AS fk_user_modif_in, ";
		$sql .= "e2.sentido AS sentido_out, ";
		$sql .= "e2.datahora AS datahora_out, ";
		$sql .= "e2.id_zs AS id_zs_out, ";
		$sql .= "e2.zsbms_synch AS zsbms_synch_out, ";
		$sql .= "e2.fk_user_creat AS fk_user_creat_out, ";
		$sql .= "e2.fk_user_modif AS fk_user_modif_out, ";
		$sql .= "e1.rowid AS fk_pairid ";
		$sql .= "FROM " . MAIN_DB_PREFIX . "dolizsynch_zspunchclock_synch e1 ";
		$sql .= "JOIN " . MAIN_DB_PREFIX . "dolizsynch_zspunchclock_synch e2 ";
		$sql .= "ON e1.pairid = e2.pairid ";
		$sql .= "AND e1.entity = e2.entity AND e1.loja_zs = e2.loja_zs AND e1.empid_zs = e2.empid_zs ";
		$sql .= "AND e1.sentido = 1 ";
		$sql .= "AND e2.sentido = 2 ";
		$sql .= "WHERE e1.entity = " . ((int) $conf->entity) . " AND (e1.synch = 0 or e1.synch is null) ";
		$sql .= "AND (e2.synch = 0 or e2.synch is null)";
		$dateSql = $this->attendanceDateCondition('e1.', $dateFrom, $dateTo, true);
		if ($dateSql === null) {
			return -1;
		}
		$sql .= $dateSql;

		$resql = $this->db->query($sql);
		if (!$resql) {
			dol_syslog($this->db->lasterror(), LOG_ERR);
			return -1;
		}

		while ($obj = $this->db->fetch_object($resql)) {
			$fk_user = $this->fetchUserIdByEmployeeId($obj->empid_zs);
			$entityLabel = $this->fetchEntityLabel();
			if (empty($entityLabel)) {
				$entityLabel = 'Entity' . (int) $obj->entity;
			}

			$userPart = $fk_user ? (string) $fk_user : 'user0';
			$pairPart = $obj->fk_pairid ? (string) $obj->fk_pairid : uniqid('pair', false);
			$tz = new DateTimeZone('Europe/Lisbon');
			$dtIn  = new DateTime((string) $obj->datahora_in, $tz);
			$dtOut = new DateTime((string) $obj->datahora_out, $tz);
			$refLabel = $entityLabel . '_' . $dtIn->format('Ymd') . '_' . $userPart . '_' . $pairPart;
			$duration = abs($dtIn->getTimestamp() - $dtOut->getTimestamp());

			if ($obj->fk_pairid && $this->isPairAlreadyIntegrated($obj->fk_pairid)) {
				// Update both records (both have same pairid)
				$sql_update = "UPDATE " . MAIN_DB_PREFIX . "dolizsynch_zspunchclock_synch SET synch = 1 WHERE pairid = " . ((int) $obj->fk_pairid);
				$this->db->query($sql_update);
				continue;
			}

			// Prepare the new ZSPunchClock object
			$zspunchclock = new ZSPunchClock($this->db);
			$zspunchclock->entity = $obj->entity;
			$zspunchclock->ref = $refLabel;
			$zspunchclock->loja_zs = $obj->loja_zs;
			$zspunchclock->empid_zs = $obj->empid_zs;
			$zspunchclock->fk_user = $fk_user;
			$zspunchclock->dataval = $this->attendanceDay($obj->datahora_in);
			$zspunchclock->sentido_in = $obj->sentido_in;
			$zspunchclock->datahora_in = $obj->datahora_in;
			$zspunchclock->id_zs_in = $obj->id_zs_in;
			$zspunchclock->sentido_out = $obj->sentido_out;
			$zspunchclock->datahora_out = $obj->datahora_out;
			$zspunchclock->id_zs_out = $obj->id_zs_out;
			$zspunchclock->duration = $duration;
			$zspunchclock->fk_pairid = $obj->fk_pairid;
				$zspunchclock->synch = '0';
				// Check if at least one entry in the pair is synthetic (zsbms_synch = 0)
			$isSynthetic = ($obj->zsbms_synch_in == 0 || $obj->zsbms_synch_out == 0);
			$zspunchclock->real_entry = $isSynthetic ? 0 : 1;

			// Copy user info from the synthetic entry (zsbms_synch = 0)
			if ($obj->zsbms_synch_in == 0) {
				// IN is synthetic - copy user fields from IN entry
				$zspunchclock->fk_user_creat = $obj->fk_user_creat_in ?: $user->id;
				$zspunchclock->fk_user_modif = $obj->fk_user_modif_in ?: $user->id;
			} elseif ($obj->zsbms_synch_out == 0) {
				// OUT is synthetic - copy user fields from OUT entry
				$zspunchclock->fk_user_creat = $obj->fk_user_creat_out ?: $user->id;
				$zspunchclock->fk_user_modif = $obj->fk_user_modif_out ?: $user->id;
			}


			// Assuming duration and other necessary fields are handled appropriately

			if ($zspunchclock->create($user) > 0) {
				// If synthetic, update the user fields after creation (create() overwrites them)
				if ($isSynthetic) {
					$updateUserSql = "UPDATE " . MAIN_DB_PREFIX . "dolizsynch_zspunchclock SET ";
					if ($obj->zsbms_synch_in == 0) {
						$updateUserSql .= "fk_user_creat = " . (int)($obj->fk_user_creat_in ?: $user->id) . ", ";
						$updateUserSql .= "fk_user_modif = " . (int)($obj->fk_user_modif_in ?: $user->id);
					} else {
						$updateUserSql .= "fk_user_creat = " . (int)($obj->fk_user_creat_out ?: $user->id) . ", ";
						$updateUserSql .= "fk_user_modif = " . (int)($obj->fk_user_modif_out ?: $user->id);
					}
					$updateUserSql .= " WHERE rowid = " . (int)$zspunchclock->id;
					$this->db->query($updateUserSql);
				}

				// Update both records (both have same pairid)
				$sql_update = "UPDATE " . MAIN_DB_PREFIX . "dolizsynch_zspunchclock_synch SET synch = 1 WHERE pairid = " . ((int) $obj->fk_pairid);
				$this->db->query($sql_update);
			} else {
				dol_syslog("Failed to create ZSPunchClock record for empid_zs=" . $obj->empid_zs . " datahora_in=" . $obj->datahora_in . " loja_zs=" . $obj->loja_zs . ": " . $zspunchclock->error, LOG_ERR);
			}
		}

		return 0; // Success
	}

	private function isPairAlreadyIntegrated($pairId)
	{
		if (empty($pairId)) {
			return false;
		}

		global $conf;
		$sql = "SELECT rowid FROM " . MAIN_DB_PREFIX . "dolizsynch_zspunchclock WHERE fk_pairid = " . ((int) $pairId) . " AND entity = " . ((int) $conf->entity) . " LIMIT 1";
		$resql = $this->db->query($sql);

		if (!$resql) {
			dol_syslog(__METHOD__ . " SQL error " . $this->db->lasterror(), LOG_ERR);
			return false;
		}

		$already = $this->db->num_rows($resql) > 0;
		$this->db->free($resql);

		return $already;
	}

	/**
	 * Fetches the Dolibarr user ID (fk_object) associated with a given employee ID (zs_employee_id) from llx_user_extrafields.
	 *
	 * @param int $empId Employee ID to search for.
	 * @return int|false Returns the Dolibarr user ID (fk_object) if found, false otherwise.
	 */
	private function fetchUserIdByEmployeeId($empId)
	{
		global $db;


		$sql = "SELECT fk_object FROM " . MAIN_DB_PREFIX . "user_extrafields WHERE zs_employee_id = '" . $db->escape($empId) . "'";
		$resql = $db->query($sql);

		if ($resql) {
			if ($db->num_rows($resql) > 0) {
				$obj = $db->fetch_object($resql);
				return (int)$obj->fk_object; // Return the Dolibarr user ID associated with the employee ID
			} else {
				dol_syslog("No user found for employee ID: " . $empId, LOG_WARNING);
				return false; // No user found for this employee ID
			}
		} else {
			dol_syslog("Error fetching user by employee ID: " . $db->lasterror(), LOG_ERR);
			return false; // Query failed to execute
		}
	}

	/**
	 * Fetches the label of the current entity.
	 *
	 * @global object $db The global database connection object.
	 * @global object $conf The global configuration object.
	 * @return string|false The label of the current entity or false on failure.
	 */
	function fetchEntityLabel()
	{
		global $db, $conf;


		// SQL to get the label of the current entity
		$sql = "SELECT label FROM " . MAIN_DB_PREFIX . "entity WHERE rowid = " . $conf->entity;

		$resql = $db->query($sql);
		if ($resql) {
			if ($db->num_rows($resql) > 0) {
				$obj = $db->fetch_object($resql);
				return $obj->label; // Return the label of the current entity
			} else {
				// No entity found with the given ID
				return false;
			}
		} else {
			// Query failed to execute
			return false;
		}
	}

	public function processTimeRegistersToTasks($dateFrom = null, $dateTo = null)
	{
		global $db, $user, $conf;


		$dateSql = $this->attendanceDateCondition('', $dateFrom, $dateTo);
		if ($dateSql === null) {
			return false;
		}
		$sql = "SELECT * FROM " . MAIN_DB_PREFIX . "dolizsynch_zspunchclock WHERE entity = " . ((int) $conf->entity) . " AND synch = 0 AND sentido_in = 1" . $dateSql . " ORDER BY loja_zs, dataval, datahora_in";
		$resql = $db->query($sql);

		if (!$resql) {
			dol_syslog("Failed to fetch time registers: " . $db->lasterror(), LOG_ERR);
		}

		$currentLojaZs = null;
		$currentDate = null;
		$taskId = null;
		$minDateTime = null;
		$maxDateTime = null;

		while ($obj = $db->fetch_object($resql)) {
			// Only proceed if duration > 0
			if ($obj->duration > 0) {
				// Check if we've moved to a new store or a new day, or if it's the first record
				if ($currentLojaZs !== $obj->loja_zs || $currentDate !== $obj->dataval || $taskId === null) {
					// If moving to a new task, update the previous task's end time
					if ($taskId !== null) {
						$this->updateTaskEndTime($taskId, $maxDateTime);
					}

					// Reset min/max times for the new task
					$minDateTime = $obj->datahora_in;
					$maxDateTime = $obj->datahora_in;

					// Update current store/day tracking
					$currentLojaZs = $obj->loja_zs;
					$currentDate = $obj->dataval;

					// Create or update the task, passing the initial start time
					$taskId = $this->createOrUpdateDailyTask($currentLojaZs, $currentDate, $minDateTime, $maxDateTime);
				} else {
					// Update min/max times based on current record
					if ($obj->datahora_in < $minDateTime) {
						$minDateTime = $obj->datahora_in;
						$this->updateTaskStartTime($taskId, $minDateTime);
					}
					if ($obj->datahora_out > $maxDateTime) {
						$maxDateTime = $obj->datahora_out;
						// The end time will be updated when moving to a new task or at the end of processing
					}
				}

				// Register time spent for the current time register
				$timeSpentId = $this->registerTimeSpent($taskId, $obj);

				// Update the time register to mark it as synchronized
				$this->updateTimeRegisterSynchStatus($obj->fk_pairid, $taskId, $timeSpentId);
			}
		}

		// Update the last task's end time
		if ($taskId !== null) {
			$this->updateTaskEndTime($taskId, $maxDateTime);
		}
		dol_syslog("Successfully processed time registers into tasks", LOG_INFO);
		return true;
	}

	/** Restrict recovery and automatic replay to their operational dates. */
	private function attendanceDateCondition($alias, $dateFrom, $dateTo, $rawPunch = false)
	{
		global $conf;
		$hour = max(0, min(23, (int) ($conf->global->ZS_PUNCH_DAY_CHANGE_HOUR ?? 0)));
		$sql = '';
		foreach (array('>=' => $dateFrom, '<=' => $dateTo) as $operator => $value) {
			if ($value === null) {
				continue;
			}
			if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', (string) $value, $m)
				|| !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
				$this->error = 'Invalid attendance recovery date';
				return null;
			}
			$field = 'dataval';
			if ($rawPunch) {
				$field = 'datahora';
				$boundary = new DateTimeImmutable($value . sprintf(' %02d:00:00', $hour), new DateTimeZone('Europe/Lisbon'));
				if ($operator === '<=') {
					$boundary = $boundary->modify('+1 day');
				}
				if ($hour > 0 && $operator === '>=') {
					$boundary = $boundary->modify('+1 second');
				} elseif ($hour === 0 && $operator === '<=') {
					$boundary = $boundary->modify('-1 second');
				}
				$value = $boundary->format('Y-m-d H:i:s');
			}
			$sql .= ' AND ' . $alias . $field . ' ' . $operator . " '" . $this->db->escape($value) . "'";
		}
		if ($dateFrom !== null && $dateTo !== null && $dateFrom > $dateTo) {
			$this->error = 'Invalid attendance recovery range';
			return null;
		}
		return $sql;
	}

	/** The POS fiscal date can lag; attendance follows the configured local cutoff. */
	protected function attendanceDay($localTime)
	{
		global $conf;
		$hour = max(0, min(23, (int) ($conf->global->ZS_PUNCH_DAY_CHANGE_HOUR ?? 0)));
		$date = new DateTimeImmutable($localTime, new DateTimeZone('Europe/Lisbon'));
		if ($hour > 0 && $date <= $date->setTime($hour, 0, 0)) {
			$date = $date->modify('-1 day');
		}
		return $date->format('Y-m-d');
	}

	/**
	 * Updates the start time of a task.
	 *
	 * @param int $taskId The ID of the task to update.
	 * @param string $startTime The new start time for the task.
	 * @return void
	 */
	private function updateTaskStartTime($taskId, $startTime)
	{
		global $db, $user;


		$task = new Task($db);
		if ($task->fetch($taskId) > 0) {
			$task->date_start = strtotime($startTime);
			$task->update($user);
		} else {
			dol_syslog("Failed to fetch task with ID: $taskId", LOG_ERR);
		}
	}

	/**
	 * Updates the end time of a task.
	 *
	 * @param int $taskId The ID of the task to update.
	 * @param string $endTime The new end time for the task.
	 * @return void
	 */
	private function updateTaskEndTime($taskId, $endTime)
	{
		global $db, $user;


		$task = new Task($db);
		if ($task->fetch($taskId) > 0) {
			$task->date_end = strtotime($endTime);
			$task->update($user);
		} else {
			dol_syslog("Failed to fetch task with ID: $taskId", LOG_ERR);
		}
	}

	/**
	 * Updates the synchronization status and task ID of a time register directly in the database.
	 *
	 * @param int $rowid The ID of the time register to update.
	 * @param int $taskId The ID of the task associated with the time register.
	 * @global DoliDB $db Global database connection object.
	 * @return void
	 */
	private function updateTimeRegisterSynchStatus($pairid, $taskId, $timeSpentId)
	{
		global $db;


		$sql = "UPDATE " . MAIN_DB_PREFIX . "dolizsynch_zspunchclock ";
		$sql .= "SET synch = '1', "; // Mark as synchronized
		$sql .= "fk_task = " . (int) $taskId . ", ";
		$sql .= "fk_timespent = " . (int) $timeSpentId . " ";
		$sql .= "WHERE fk_pairid = " . (int) $pairid;

		$resql = $db->query($sql);
		if (!$resql) {
			dol_syslog("Failed to update time register with ID: $pairid. Error: " . $db->lasterror(), LOG_ERR);
		}
	}

	private function getOrCreateProject()
	{
		global $db, $user;


		$entityLabel = $this->fetchEntityLabel();
		$projectName = $entityLabel . "_Ponto"; // Unique project name for each store
		$project = new Project($db);

		// Try to fetch existing project by title
		$sql = "SELECT rowid FROM " . MAIN_DB_PREFIX . "projet WHERE title = '" . $db->escape($projectName) . "'";
		$resql = $db->query($sql);
		if ($resql && $db->num_rows($resql) > 0) {
			$obj = $db->fetch_object($resql);
			$project->fetch($obj->rowid);
		} else {
			// Create new project if not found
			$project->ref = $projectName;
			$project->title = $projectName;
			$project->public = 1; // Public project

			$project->usage_task = 1;
			$project->usage_bill_time = 1;
			$project->usage_organize_event = 1;

			$project->create($user);
			$project->setValid($user);
		}
		return $project->id;
	}

	private function createOrUpdateDailyTask($currentLojaZs, $currentDate, $minDateTime, $maxDateTime)
	{
		global $db, $user, $langs;


		$projectId = $this->getOrCreateProject($currentLojaZs);
		$entityLabel = $this->fetchEntityLabel();
		$taskRef = $entityLabel . "_Ponto_" . $currentDate;

		// Ensure minDateTime and maxDateTime are Unix timestamps
		$minDateTime = is_numeric($minDateTime) ? $minDateTime : strtotime($minDateTime);
		$maxDateTime = is_numeric($maxDateTime) ? $maxDateTime : strtotime($maxDateTime);

		$task = new Task($db);
		$existingTaskId = $task->fetch(0, $taskRef);

		if ($existingTaskId <= 0) {
			// Task does not exist, create new
			$task->ref = $taskRef;
			$task->label = $langs->trans("TimeRegistersToDate") . " " . $currentDate;
			$task->fk_project = $projectId;
			$task->date_start = $minDateTime;
			$task->date_end = $maxDateTime;
			$task->create($user);
		} else {
			// Task exists, possibly update its start/end times
			if ($task->date_start > $minDateTime) {
				$task->date_start = $minDateTime;
			}
			if ($task->date_end < $maxDateTime) {
				$task->date_end = $maxDateTime;
			}
			$task->update($user);
		}

		return $task->id;
	}

	/**
	 * Registers time spent for a given task based on time register data.
	 *
	 * @param int $taskId ID of the task to update.
	 * @param object $timeRegister Time register object with properties including fk_user and duration.
	 * @global User $user Global user object representing the current user.
	 * @global DoliDB $db Global database connection object.
	 * @return void
	 */
	public function registerTimeSpent($taskId, $timeRegister)
	{
		global $db, $user;


		// Create a new TimeSpent object
		$timeSpent = new TimeSpent($db);

		// Set properties for the time spent
		$timeSpent->fk_element = $taskId; // Assuming this is the ID of the task
		$timeSpent->elementtype = 'task'; // Assuming the element type for tasks
		$timeSpent->fk_user = $timeRegister->fk_user; // Assuming timeRegister includes fk_user
		$timeSpent->element_duration = $timeRegister->duration; // Assuming timeRegister includes duration (in seconds)

		// Pass Unix timestamps directly - Dolibarr's create/update will handle conversion
		if (is_numeric($timeRegister->datahora_in)) {
			$timeSpent->element_datehour = $timeRegister->datahora_in;
			$timeSpent->element_date = $timeRegister->datahora_in;
		} else {
			$ts = strtotime($timeRegister->datahora_in);
			$timeSpent->element_datehour = $ts;
			$timeSpent->element_date = $ts;
		}

		$timeSpent->element_date_withhour = '1';

		// Format note with out time
		if (is_numeric($timeRegister->datahora_out)) {
			$timeSpent->note = 'Saida ' . date('Y-m-d H:i:s', $timeRegister->datahora_out);
		} else {
			$timeSpent->note = 'Saida ' . $timeRegister->datahora_out;
		}

		// Save the time spent record
		$result = $timeSpent->create($user);

		if ($result <= 0) {
			dol_syslog("Failed to register time spent for task ID: $taskId. Error: " . $timeSpent->error, LOG_ERR);
		} else {
			// Successfully created TimeSpent record, now update task contacts.
			$task = new Task($db);
			if ($task->fetch($taskId) > 0) {
				$task->add_contact($timeRegister->fk_user, 'TASKCONTRIBUTOR', 'internal');
			}
			return $timeSpent->id;
		}
	}

	public function registerTimeSpentUpdate($timeRegister)
	{
		global $db, $user;


		// Create a new TimeSpent object
		$timeSpent = new TimeSpent($db);
		$timeSpent->fetch($timeRegister->fk_timespent);

		//$responseString = json_encode($timeSpent, JSON_PRETTY_PRINT);
		//dol_syslog("contactsoftask: " . $responseString, LOG_INFO);

		// Set properties for the time spent
		$timeSpent->fk_element = $timeRegister->fk_task;
		$timeSpent->element_duration = $timeRegister->duration; // Assuming timeRegister includes duration (in seconds)
		$timeSpent->element_datehour = $timeRegister->datahora_in; // Assuming timeRegister includes the datetime
		$timeSpent->element_date_withhour = '1';
		$timeSpent->element_date = $timeRegister->datahora_in;
		$timeSpent->note = 'Saida ' . date('Y-m-d H:i:s', (int)$timeRegister->datahora_out);

		// Save the time spent record
		$result = $timeSpent->update($user);

		if ($result <= 0) {
			dol_syslog("Failed to updated time spent for task ID: $timeRegister->fk_task. Error: " . $timeSpent->error, LOG_ERR);
		}
	}

	/**
	 * Fetches punch_out time for the given pair ID.
	 *
	 * @param int $pairId ID of the paired time register.
	 * @return string|null Punch_out time or null if not found.
	 */
	private function fetchPunchOutTime($pairId)
	{
		global $db;


		$sql = "SELECT datahora_in FROM " . MAIN_DB_PREFIX . "dolizsynch_zspunchclock WHERE rowid = " . (int)$pairId;
		$resql = $db->query($sql);
		if ($resql && $db->num_rows($resql) > 0) {
			$obj = $db->fetch_object($resql);
			return $obj->datahora_in;
		}

		return null;
	}

	/**
	 * Adds users as contacts to a specific project.
	 *
	 * @param int $projectId The ID of the project.
	 * @param array $userIds Array of user IDs to add as contacts to the project.
	 * @return bool True on success, false on failure.
	 */
	private function addUserToProjectContacts($projectId, $userId)
	{
		global $db, $user;


		$success = true;

		// Check if the user is already linked to avoid duplicates
		$sqlCheck = "SELECT rowid FROM " . MAIN_DB_PREFIX . "element_contact
                     WHERE element_id = " . (int)$projectId . "
                     AND fk_c_type_contact = 161
                     AND fk_socpeople = " . (int)$userId;
		$resqlCheck = $db->query($sqlCheck);

		if ($db->num_rows($resqlCheck) == 0) {
			// User not linked yet, proceed to link
			$sqlInsert = "INSERT INTO " . MAIN_DB_PREFIX . "element_contact (statut, element_id, fk_c_type_contact, fk_socpeople)
                          VALUES (4, " . (int)$projectId . ", 161, " . (int)$userId . ")";
			$resqlInsert = $db->query($sqlInsert);
			if (!$resqlInsert) {
				dol_syslog("Failed to add user $userId as contact to project $projectId: " . $db->lasterror(), LOG_ERR);
				$success = false;
				// Decide whether to break the loop and return false immediately or try to add other users
			}
		}

		return $success;
	}

	/**
	 * Removes a user from the project contacts based on the task ID.
	 *
	 * @param int $taskId The ID of the task.
	 * @param int $userId The ID of the user to remove.
	 * @return bool True on success, false on failure.
	 */
	public function deleteUserFromProjectContacts($taskId, $userId)
	{
		global $db;


		$success = true;


		// Check if the user is linked to the project
		$sqlCheck = "SELECT rowid FROM " . MAIN_DB_PREFIX . "element_contact
                     WHERE element_id = " . (int)$taskId . "
                     AND fk_c_type_contact = 181
                     AND fk_socpeople = " . (int)$userId;
		$resqlCheck = $db->query($sqlCheck);

		if ($db->num_rows($resqlCheck) > 0) {
			// User is linked, proceed to remove
			$sqlDelete = "DELETE FROM " . MAIN_DB_PREFIX . "element_contact
                          WHERE element_id = " . (int)$taskId . "
                          AND fk_c_type_contact = 181
                          AND fk_socpeople = " . (int)$userId;
			$resqlDelete = $db->query($sqlDelete);
			if (!$resqlDelete) {
				dol_syslog("Failed to remove user $userId from project $taskId: " . $db->lasterror(), LOG_ERR);
				$success = false;
			}
		}


		return $success;
	}

	public function switchToAdmin()
	{
		global $user;

		// Remember the original user
		$this->originalUser = clone $user;

		// Fetch the first admin user
		$sql = "SELECT rowid FROM " . MAIN_DB_PREFIX . "user WHERE admin = 1 ORDER BY rowid ASC LIMIT 1";
		$resql = $this->db->query($sql);
		if ($resql && $this->db->num_rows($resql) > 0) {
			$obj = $this->db->fetch_object($resql);
			$adminUserId = $obj->rowid;

			if ($user->fetch($adminUserId) > 0) {
				// Successfully switched to admin user
			} else {
				// Failed to fetch admin user
			}
		} else {
			// No admin users found or query failed
		}
	}

	/**
	 * Revert back to the original user context.
	 */
	public function revertToOriginalUser()
	{
		global $user;

		if ($this->originalUser !== null) {
			$user = $this->originalUser;
			$this->originalUser = null;
		}
	}

	/**
	 * SAFE: Creates a dropdown select element from an array of options.
	 * This is a pure renderer that doesn't execute any SQL.
	 *
	 * @param string $htmlname The name and ID for the HTML select element.
	 * @param array $options Array of objects with 'rowid' and 'label' properties
	 * @param string $selected The value of the option that should be selected by default.
	 * @param int $showempty Whether to include an empty option at the top of the dropdown (1 = show, 0 = hide).
	 * @param int $maxlen The maximum length for option labels. If set, labels will be truncated.
	 *
	 * @return string The HTML string for the dropdown select element.
	 */
	function renderSelect($htmlname, $options, $selected = '', $showempty = 1, $maxlen = 0)
	{
		global $langs;

		if (!is_array($options)) {
			return '';
		}

		$out = '<select class="flat" id="' . dol_escape_htmltag($htmlname) . '" name="' . dol_escape_htmltag($htmlname) . '">';
		if ($showempty) {
			$out .= '<option value="">&nbsp;</option>';
		}

		foreach ($options as $option) {
			if (!is_object($option) || !isset($option->rowid) || !isset($option->label)) {
				continue;
			}
			$label = ($maxlen ? dol_trunc(dol_escape_htmltag($option->label), $maxlen, 'middle') : dol_escape_htmltag($option->label));
			$value = dol_escape_htmltag($option->rowid);
			$selectedattr = ($selected == $option->rowid ? ' selected="selected"' : '');
			$out .= '<option value="' . $value . '"' . $selectedattr . '>' . $label . '</option>';
		}

		$out .= '</select>';
		return $out;
	}

	/**
	 * SAFE: Creates a dropdown select element from predefined table queries.
	 * Uses a whitelist mapping to prevent SQL injection from user inputs.
	 *
	 * @param string $htmlname The name and ID for the HTML select element.
	 * @param string $tableKey The key from the whitelist (e.g., 'projet_task', 'user', 'entity')
	 * @param string $selected The value of the option that should be selected by default.
	 * @param int $showempty Whether to include an empty option at the top of the dropdown (1 = show, 0 = hide).
	 * @param int $maxlen The maximum length for option labels. If set, labels will be truncated.
	 *
	 * @return string The HTML string for the dropdown select element.
	 */
	function renderSelectFromTable($htmlname, $tableKey, $selected = '', $showempty = 1, $maxlen = 0)
	{
		global $db, $conf;

		// Whitelist of allowed table configurations
		$tableWhitelist = $this->getTableWhitelist();

		if (!isset($tableWhitelist[$tableKey])) {
			dol_syslog("ZSPunchClock::renderSelectFromTable - Invalid table key: " . $tableKey, LOG_ERR);
			return '';
		}

		$config = $tableWhitelist[$tableKey];
		$table = MAIN_DB_PREFIX . $config['table'];
		$idColumn = $config['id'];
		$labelColumn = $config['label'];
		$where = isset($config['where']) ? $config['where'] : '';
		$orderBy = isset($config['order']) ? $config['order'] : $labelColumn;

		// Build safe query using whitelisted components
		$sql = "SELECT {$idColumn} as rowid, {$labelColumn} as label FROM {$table}";
		if ($where) {
			$sql .= " WHERE " . $where;
		}
		$sql .= " ORDER BY " . $orderBy;


		$resql = $db->query($sql);
		if (!$resql) {
			dol_syslog("ZSPunchClock::renderSelectFromTable - Query failed: " . $db->lasterror(), LOG_ERR);
			return '';
		}

		$options = array();
		while ($obj = $db->fetch_object($resql)) {
			$options[] = $obj;
		}

		return $this->renderSelect($htmlname, $options, $selected, $showempty, $maxlen);
	}

	/**
	 * Returns the whitelist mapping for renderSelectFromTable
	 * Each key maps to table configuration with: table, id, label, where (optional), order (optional)
	 *
	 * @return array The whitelist mapping
	 */
	private function getTableWhitelist()
	{
		global $conf;
		$entity_id = (int)$conf->entity;

		return array(
			'projet_task' => array(
				'table' => 'projet_task',
				'id' => 'rowid',
				'label' => 'ref',
				'where' => "entity = {$entity_id}",
				'order' => 'dateo DESC'
			),
			'projet_task_all' => array(
				'table' => 'projet_task',
				'id' => 'rowid',
				'label' => 'ref',
				'where' => "entity = {$entity_id}",
				'order' => 'ref ASC'
			),
			'user' => array(
				'table' => 'user',
				'id' => 'rowid',
				'label' => 'login',
				'where' => "entity IN (0, {$entity_id}) AND statut = 1",
				'order' => 'login ASC'
			),
			'societe' => array(
				'table' => 'societe',
				'id' => 'rowid',
				'label' => 'nom',
				'where' => "entity = {$entity_id} AND status = 1",
				'order' => 'nom ASC'
			),
			'projet' => array(
				'table' => 'projet',
				'id' => 'rowid',
				'label' => 'ref',
				'where' => "entity = {$entity_id} AND fk_statut = 1",
				'order' => 'ref ASC'
			)
		);
	}

	/**
	 * DEPRECATED: Use renderSelect() or renderSelectFromTable() instead.
	 * This method is kept for backward compatibility but should not be used.
	 * It triggers Dolibarr's SQL injection protection.
	 *
	 * @deprecated
	 */
	function selectDropdownFromQuery($htmlname, $sql, $selected = '', $showempty = 1, $maxlen = 0)
	{
		dol_syslog("ZSPunchClock::selectDropdownFromQuery - DEPRECATED METHOD CALLED. Use renderSelect() or renderSelectFromTable() instead.", LOG_WARNING);

		// For immediate safety, return empty select if this is still called
		return '<select class="flat" id="' . dol_escape_htmltag($htmlname) . '" name="' . dol_escape_htmltag($htmlname) . '"><option value="">-- Method deprecated for security --</option></select>';
	}






































	/**
	 * 	Return list of id of contacts of task
	 *
	 *	@param	string	$source		Source
	 *  @return array				Array of id of contacts
	 */
	public function getListContactId($source = 'internal')
	{
		$contactAlreadySelected = array();
		$tab = $this->liste_contact(-1, $source);
		//var_dump($tab);
		$num = count($tab);
		$i = 0;
		while ($i < $num) {
			if ($source == 'thirdparty') {
				$contactAlreadySelected[$i] = $tab[$i]['socid'];
			} else {
				$contactAlreadySelected[$i] = $tab[$i]['id'];
			}
			$i++;
		}
		return $contactAlreadySelected;
	}

	private function getOrCreateDailyTask($projectId, $dateVal, $storeId)
	{
		global $db, $user;


		$taskRef = "Daily_" . $dateVal . "_Store_" . $storeId;
		$sql = "SELECT rowid FROM " . MAIN_DB_PREFIX . "projet_task WHERE ref = '" . $db->escape($taskRef) . "' AND fk_projet = " . $projectId;
		$resql = $db->query($sql);
		if ($obj = $db->fetch_object($resql)) {
			return new Task($db, $obj->rowid);
		} else {
			$task = new Task($db);
			$task->ref = $taskRef;
			$task->label = "Daily Time Register " . $dateVal . " for Store " . $storeId;
			$task->fk_project = $projectId;
			// Set start and end dates based on the first and last register of the day, if available
			$task->date_start = strtotime($dateVal);
			$task->date_end = strtotime($dateVal . ' +1 day');
			$task->create($user);
			return $task;
		}
	}

	private function processTimeRegisterEntry($timeRegister)
	{
		global $db, $user, $conf;


		$projectName = $conf->global->ZS_TIME_REGISTER_PROJECT_NAME;

		$projectId = $this->getOrCreateProject();
		if (!$projectId) {
			dol_syslog("Failed to get or create project: " . $projectName, LOG_ERR);
			return false;
		}

		// Here, you have the $project loaded or created
		// Now, handle the time register as a task or a time spent on a task

		$taskId = $this->getOrCreateTaskForTimeRegister($timeRegister, $projectId);
		if (!$taskId) {
			dol_syslog("Failed to get or create task for time register", LOG_ERR);
			return false;
		}

		// Assuming $this->getOrCreateTaskForTimeRegister($timeRegister, $projectId) is a method you implement
		// that ensures a task exists for this time entry and returns its ID.

		// Now, register time spent on the task
		$task = new Task($db);
		$task->fetch($taskId);

		$durationInSecs = $this->calculateDuration($timeRegister); // Implement this to calculate the duration based on your time register data
		$task->addTimeSpent($user, $durationInSecs, 0, 0, '', '', '', 'TimeRegisterImport', '');

		return true;
	}

	private function getOrCreateTaskForTimeRegister($timeRegister, $projectId)
	{
		global $db, $user;


		$dateVal = $timeRegister->dataval;
		$empId = $timeRegister->empid;
		$shiftCount = $this->getShiftCountForDay($empId, $dateVal) + 1; // Increment for a new potential shift
		$taskRefPrefix = "TR_{$empId}_{$dateVal}_";
		$formattedShiftCount = str_pad($shiftCount, 2, '0', STR_PAD_LEFT); // Correctly format the shift count
		$taskRef = "{$taskRefPrefix}{$formattedShiftCount}"; // Corrected formatting
		$taskLabel = "Time Register for {$timeRegister->empname} on {$dateVal}, Shift {$shiftCount}";


		$sql = "SELECT rowid FROM " . MAIN_DB_PREFIX . "projet_task WHERE ref LIKE '" . $db->escape($taskRefPrefix) . "%' AND fk_projet = " . intval($projectId);
		$resql = $db->query($sql);
		if ($resql && $db->num_rows($resql) > 0) {
			while ($obj = $db->fetch_object($resql)) {
				if ($this->updateTaskIfNecessary($obj->rowid, $timeRegister, $taskRef)) {
					return $obj->rowid; // Task updated or no need to create a new task
				}
			}
		}

		// Handle creating a new task for sentido_in = 1 or handling anomalies
		if ($timeRegister->sentido_in == 1) {
			// Create a new task for a new shift.
			return $this->createNewTask($projectId, $taskRef, $taskLabel, $timeRegister);
		} else {
			// Possibly handle anomaly for missing clock-out, this needs a specific implementation strategy
		}

		return false; // Return false if no task was created or updated
	}

	private function updateTaskIfNecessary($taskId, $timeRegister, $expectedRef)
	{
		global $db, $user;


		$task = new Task($db);
		$task->fetch($taskId);
		if ($task->ref == $expectedRef && $timeRegister->sentido_in == 2) {
			// Update task with clock-out time
			$task->date_end = strtotime($timeRegister->datahora_in);
			$task->planned_workload = 100;
			$task->update($user);
			return true;
		}
		// No update necessary or different conditions, return false to indicate no action was taken
		return false;
	}

	private function createNewTask($projectId, $taskRef, $taskLabel, $timeRegister)
	{
		global $db, $user;


		$task = new Task($db);
		$task->ref = $taskRef;
		$task->label = $taskLabel;
		$task->fk_project = $projectId;
		$task->date_start = strtotime($timeRegister->datahora_in); // Assuming this is the start date/time
		// Add additional task setup here as necessary

		if ($task->create($user) > 0) {
			return $task->id; // Return the new task ID
		} else {
			dol_syslog("Failed to create task: " . $task->error, LOG_ERR);
			return false; // Task creation failed
		}
	}

	private function getShiftCountForDay($empId, $dateVal)
	{
		global $db;


		$sql = "SELECT COUNT(*) as shiftCount FROM " . MAIN_DB_PREFIX . "projet_task WHERE ref LIKE 'TR_{$empId}_{$dateVal}%'"; // Adjust the table name and column names as per your database schema
		$resql = $db->query($sql);
		if ($resql) {
			$obj = $db->fetch_object($resql);
			return (int)$obj->shiftCount;
		}
		return 0; // Default to 0 if query fails or no shifts found
	}

	private function formatDateTimeForDolibarr($dateTime)
	{
		// Assuming $dateTime is in 'YYYY-MM-DD HH:MM:SS' format
		return strtotime($dateTime); // Convert to Unix timestamp
	}

	private function calculateDuration($currentTimeRegister, $previousTimeRegister = null)
	{

		// If there's no previous entry, return a default duration or calculate differently
		if ($previousTimeRegister === null) {
			// Handle as needed; maybe return a default or 0
			return 0;
		}

		// Convert times to Unix timestamps for calculation
		$endTime = strtotime($currentTimeRegister->datahora_in);
		$startTime = strtotime($previousTimeRegister->datahora_in);

		// Calculate duration in seconds
		$durationInSeconds = $endTime - $startTime;

		return $durationInSeconds;
	}

	private function getLastSyncDateTime()
	{
		global $conf;


		// Check if the constant is defined and not empty
		if (!empty($conf->global->ZONE_SOFT_LAST_SYNC_DATETIME)) {
			// Return the stored last sync datetime
			return $conf->global->ZONE_SOFT_LAST_SYNC_DATETIME;
		} else {
			// Default to a date far in the past to ensure all records are fetched on first sync
			// Format: YYYY-MM-DD HH:MM:SS
			return '2000-01-01 00:00:00';
		}
	}

	/**
	 * Updates a time register by its ID with new values.
	 *
	 * @param int $rowid ID of the time register to update.
	 * @param array $values Key-value pairs of the columns to update.
	 * @return bool Returns true if the update was successful, false otherwise.
	 */
	private function updateTimeRegisterById($rowid, $values)
	{
		global $db;


		$allowedColumns = [
			'loja_zs', 'sentido', 'empid_zs', 'datahora', 'dataval',
			'id_zs', 'synch', 'zsbms_synch', 'pairid', 'fk_user_creat', 'fk_user_modif',
		];
		$sets = [];
		foreach ($values as $column => $value) {
			if (!in_array($column, $allowedColumns, true)) {
				dol_syslog(__METHOD__ . " Rejected unknown column: " . $column, LOG_WARNING);
				continue;
			}
			$sets[] = $column . " = '" . $db->escape($value) . "'";
		}
		if (empty($sets)) {
			return false;
		}
		$sqlSetPart = implode(', ', $sets);

		$sql = "UPDATE " . MAIN_DB_PREFIX . "dolizsynch_zspunchclock_synch SET $sqlSetPart WHERE rowid = " . intval($rowid);
		if ($db->query($sql)) {
			return true;
		} else {
			dol_syslog("Failed to update time register with ID: $rowid. Error: " . $db->lasterror(), LOG_ERR);
			return false;
		}
	}




















	public function create(User $user, $notrigger = false)
	{
		global $db, $conf;


		$error = 0;
		$now = dol_now();

		// Automatically generate reference if not provided
		if (empty($this->ref)) {
			// Capture any direct output produced by getNextNumRef (some numbering modules echo errors)
			$bufferingStarted = false;
			if (!ob_get_level()) {
				ob_start();
				$bufferingStarted = true;
			} else {
				ob_start();
				$bufferingStarted = true;
			}

			$generatedRef = $this->getNextNumRef();

			$bufferContent = '';
			if ($bufferingStarted) {
				$bufferContent = ob_get_clean();
			}

			if (!empty($bufferContent)) {
				dol_syslog(__METHOD__ . ": getNextNumRef output suppressed: " . trim($bufferContent), LOG_WARNING);
			}

			if (!empty($generatedRef)) {
				$this->ref = $generatedRef;
			} else {
				// Fallback reference: {EntityLabel}_{YYYYMMDD}_{uniq}
				$fallbackParts = array();
				if (method_exists($this, 'fetchEntityLabel')) {
					$entityLabel = trim($this->fetchEntityLabel());
					if (!empty($entityLabel)) {
						$fallbackParts[] = preg_replace('/[^A-Za-z0-9_]/', '', str_replace(' ', '_', $entityLabel));
					}
				}
				// Date part from dataval or current date
				$dateTimestamp = 0;
				if (!empty($this->dataval)) {
					if (is_numeric($this->dataval)) {
						$dateTimestamp = (int) $this->dataval;
					} else {
						$tmpTs = strtotime($this->dataval);
						if ($tmpTs) {
							$dateTimestamp = $tmpTs;
						}
					}
				}
				if (empty($dateTimestamp)) {
					$dateTimestamp = $now;
				}
				$fallbackParts[] = date('Ymd', $dateTimestamp);
				$randPart = '';
				try {
					$randPart = strtoupper(bin2hex(random_bytes(2)));
				} catch (Exception $e) {
					$randPart = strtoupper(substr(dechex(mt_rand()), 0, 4));
				}
				$fallbackParts[] = strtoupper(dechex($now)) . $randPart;

				$this->ref = implode('_', $fallbackParts);
			}
		}

		// Build the SQL insert query
		$sql = "INSERT INTO " . MAIN_DB_PREFIX . $this->table_element . " (";
		$sql .= "entity, ref, loja_zs, empid_zs, fk_user, dataval, sentido_in, datahora_in, id_zs_in, ";
		$sql .= "sentido_out, datahora_out, id_zs_out, duration, fk_pairid, fk_task, import_key, ";
		$sql .= "fk_user_creat, fk_user_modif, note_public, date_creation, status, synch, real_entry, fk_timespent, position";
		$sql .= ") VALUES (";
		$sql .= intval($conf->entity) . ", ";
		$sql .= "'" . $db->escape($this->ref) . "', ";
		$sql .= (is_null($this->loja_zs) ? 'NULL' : intval($this->loja_zs)) . ", ";
		$sql .= (is_null($this->empid_zs) ? 'NULL' : intval($this->empid_zs)) . ", ";
		$sql .= (is_null($this->fk_user) ? 'NULL' : intval($this->fk_user)) . ", ";
		$sql .= (is_null($this->dataval) ? 'NULL' : "'" . $db->escape($this->dataval) . "'") . ", ";
		$sql .= (is_null($this->sentido_in) ? 'NULL' : intval($this->sentido_in)) . ", ";
		// Convert Unix timestamp to MySQL datetime format using server timezone (Lisbon)
		$sql .= (is_null($this->datahora_in) ? 'NULL' : "'" . $db->escape(is_numeric($this->datahora_in) ? date('Y-m-d H:i:s', $this->datahora_in) : $this->datahora_in) . "'") . ", ";
		$sql .= (is_null($this->id_zs_in) ? 'NULL' : intval($this->id_zs_in)) . ", ";
		$sql .= (is_null($this->sentido_out) ? 'NULL' : intval($this->sentido_out)) . ", ";
		// Convert Unix timestamp to MySQL datetime format using server timezone (Lisbon)
		$sql .= (is_null($this->datahora_out) ? 'NULL' : "'" . $db->escape(is_numeric($this->datahora_out) ? date('Y-m-d H:i:s', $this->datahora_out) : $this->datahora_out) . "'") . ", ";
		$sql .= (is_null($this->id_zs_out) ? 'NULL' : intval($this->id_zs_out)) . ", ";
		$sql .= (is_null($this->duration) ? 'NULL' : intval($this->duration)) . ", ";
		$sql .= (is_null($this->fk_pairid) ? 'NULL' : intval($this->fk_pairid)) . ", ";
		$sql .= (is_null($this->fk_task) ? 'NULL' : intval($this->fk_task)) . ", ";
		$sql .= (is_null($this->import_key) ? 'NULL' : "'" . $db->escape($this->import_key) . "'") . ", ";
		$sql .= intval($user->id) . ", "; // fk_user_creat
		$sql .= intval($user->id) . ", "; // fk_user_modif
		$sql .= (is_null($this->note_public) ? 'NULL' : "'" . $db->escape($this->note_public) . "'") . ", ";
		$sql .= "'" . $db->idate($now) . "', "; // date_creation
		$sql .= self::STATUS_DRAFT . ", "; // status
		$sql .= (is_null($this->synch) ? 'NULL' : intval($this->synch)) . ", ";
		$sql .= ((is_null($this->real_entry) || $this->real_entry === '') ? 1 : intval($this->real_entry)) . ", ";
		$sql .= (is_null($this->fk_timespent) ? 'NULL' : intval($this->fk_timespent)) . ", ";
		$sql .= (is_null($this->position) ? '0' : intval($this->position));
		$sql .= ")";

		$db->begin();

		$resql = $db->query($sql);
		if (!$resql) {
			$error++;
			$this->errors[] = $db->lasterror();
		}

		if (!$error) {
			$this->id = $db->last_insert_id($this->table_element);

			if (!$notrigger) {
				$result = $this->call_trigger(strtoupper(get_class($this)) . '_CREATE', $user);
				if ($result < 0) {
					$error++;
				}
			}
		}

		if ($error) {
			$db->rollback();
			return -1;
		} else {
			$db->commit();
			return $this->id;
		}
	}




















	//////////////////////////////////////////////////////////////////////////////



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
			$object->date_creation = dol_now();
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
						if (is_int($value)) {
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

		$now = dol_now();

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
				$sql = 'UPDATE ' . MAIN_DB_PREFIX . "ecm_files set filename = CONCAT('" . $this->db->escape($this->newref) . "', SUBSTR(filename, " . (strlen($this->ref) + 1) . ")), filepath = 'zspunchclock/" . $this->db->escape($this->newref) . "'";
				$sql .= " WHERE filename LIKE '" . $this->db->escape($this->ref) . "%' AND filepath = 'zspunchclock/" . $this->db->escape($this->ref) . "' and entity = " . $conf->entity;
				$resql = $this->db->query($sql);
				if (!$resql) {
					$error++;
					$this->error = $this->db->lasterror();
				}
				$sql = 'UPDATE ' . MAIN_DB_PREFIX . "ecm_files set filepath = 'zspunchclock/" . $this->db->escape($this->newref) . "'";
				$sql .= " WHERE filepath = 'zspunchclock/" . $this->db->escape($this->ref) . "' and entity = " . $conf->entity;
				$resql = $this->db->query($sql);
				if (!$resql) {
					$error++;
					$this->error = $this->db->lasterror();
				}

				// We rename directory ($this->ref = old ref, $num = new ref) in order not to lose the attachments
				$oldref = dol_sanitizeFileName($this->ref);
				$newref = dol_sanitizeFileName($num);
				$dirsource = $conf->dolizsynch->dir_output . '/zspunchclock/' . $oldref;
				$dirdest = $conf->dolizsynch->dir_output . '/zspunchclock/' . $newref;
				if (!$error && file_exists($dirsource)) {
					if (@rename($dirsource, $dirdest)) {
						// Rename docs starting with $oldref with $newref
						$listoffiles = dol_dir_list($conf->dolizsynch->dir_output . '/zspunchclock/' . $newref, 'files', 1, '^' . preg_quote($oldref, '/'));
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
	 * getTooltipContentArray
	 *
	 * @param 	array 	$params 	Params to construct tooltip data
	 * @since 	v18
	 * @return 	array
	 */
	public function getTooltipContentArray($params)
	{
		global $langs;


		$datas = [];

		if (getDolGlobalInt('MAIN_OPTIMIZEFORTEXTBROWSER')) {
			return ['optimize' => $langs->trans("ShowZSPunchClock")];
		}
		$datas['picto'] = img_picto('', $this->picto) . ' <u>' . $langs->trans("ZSPunchClock") . '</u>';
		if (isset($this->status)) {
			$datas['picto'] .= ' ' . $this->getLibStatut(5);
		}
		if (property_exists($this, 'ref')) {
			$datas['ref'] = '<br><b>' . $langs->trans('Ref') . ':</b> ' . $this->ref;
		}
		if (property_exists($this, 'label')) {
			$datas['ref'] = '<br>' . $langs->trans('Label') . ':</b> ' . $this->label;
		}

		return $datas;
	}

	/**
	 *  Return a link to the object card (with optionaly the picto)
	 *
	 *  @param  int     $withpicto                  Include picto in link (0=No picto, 1=Include picto into link, 2=Only picto)
	 *  @param  string  $option                     On what the link point to ('nolink', ...)
	 *  @param  int     $notooltip                  1=Disable tooltip
	 *  @param  string  $morecss                    Add more css on link
	 *  @param  int     $save_lastsearch_value      -1=Auto, 0=No save of lastsearch_values when clicking, 1=Save lastsearch_values whenclicking
	 *  @return	string                              String with URL
	 */
	public function getNomUrl($withpicto = 0, $option = '', $notooltip = 0, $morecss = '', $save_lastsearch_value = -1)
	{
		global $conf, $langs, $hookmanager;


		if (!empty($conf->dol_no_mouse_hover)) {
			$notooltip = 1; // Force disable tooltips
		}

		$result = '';
		$params = [
			'id' => $this->id,
			'objecttype' => $this->element . ($this->module ? '@' . $this->module : ''),
			'option' => $option,
		];
		$classfortooltip = 'classfortooltip';
		$dataparams = '';
		if (getDolGlobalInt('MAIN_ENABLE_AJAX_TOOLTIP')) {
			$classfortooltip = 'classforajaxtooltip';
			$dataparams = ' data-params="' . dol_escape_htmltag(json_encode($params)) . '"';
			$label = '';
		} else {
			$label = implode($this->getTooltipContentArray($params));
		}

		// Link to list page instead of card page (removed)
		$url = dol_buildpath('/kreaflow/zspunchclock/zspunchclock_list.php', 1);

		if ($option !== 'nolink') {
			// Add param to save lastsearch_values or not
			$add_save_lastsearch_values = ($save_lastsearch_value == 1 ? 1 : 0);
			if ($save_lastsearch_value == -1 && isset($_SERVER["PHP_SELF"]) && preg_match('/list\.php/', $_SERVER["PHP_SELF"])) {
				$add_save_lastsearch_values = 1;
			}
			if ($url && $add_save_lastsearch_values) {
				$url .= '&save_lastsearch_values=1';
			}
		}

		$linkclose = '';
		if (empty($notooltip)) {
			if (getDolGlobalInt('MAIN_OPTIMIZEFORTEXTBROWSER')) {
				$label = $langs->trans("ShowZSPunchClock");
				$linkclose .= ' alt="' . dol_escape_htmltag($label, 1) . '"';
			}
			$linkclose .= ($label ? ' title="' . dol_escape_htmltag($label, 1) . '"' : ' title="tocomplete"');
			$linkclose .= $dataparams . ' class="' . $classfortooltip . ($morecss ? ' ' . $morecss : '') . '"';
		} else {
			$linkclose = ($morecss ? ' class="' . $morecss . '"' : '');
		}

		if ($option == 'nolink' || empty($url)) {
			$linkstart = '<span';
		} else {
			$linkstart = '<a href="' . $url . '"';
		}
		$linkstart .= $linkclose . '>';
		if ($option == 'nolink' || empty($url)) {
			$linkend = '</span>';
		} else {
			$linkend = '</a>';
		}

		$result .= $linkstart;

		if (empty($this->showphoto_on_popup)) {
			if ($withpicto) {
				$result .= img_object(($notooltip ? '' : $label), ($this->picto ? $this->picto : 'generic'), (($withpicto != 2) ? 'class="paddingright"' : ''), 0, 0, $notooltip ? 0 : 1);
			}
		} else {
			if ($withpicto) {
				require_once DOL_DOCUMENT_ROOT . '/core/lib/files.lib.php';

				list($class, $module) = explode('@', $this->picto);
				$upload_dir = $conf->$module->multidir_output[$conf->entity] . "/$class/" . dol_sanitizeFileName($this->ref);
				$filearray = dol_dir_list($upload_dir, "files");
				$filename = $filearray[0]['name'];
				if (!empty($filename)) {
					$pospoint = strpos($filearray[0]['name'], '.');

					$pathtophoto = $class . '/' . $this->ref . '/thumbs/' . substr($filename, 0, $pospoint) . '_mini' . substr($filename, $pospoint);
					if (!getDolGlobalString(strtoupper($module . '_' . $class) . '_FORMATLISTPHOTOSASUSERS')) {
						$result .= '<div class="floatleft inline-block valignmiddle divphotoref"><div class="photoref"><img class="photo' . $module . '" alt="No photo" border="0" src="' . DOL_URL_ROOT . '/viewimage.php?modulepart=' . $module . '&entity=' . $conf->entity . '&file=' . urlencode($pathtophoto) . '"></div></div>';
					} else {
						$result .= '<div class="floatleft inline-block valignmiddle divphotoref"><img class="photouserphoto userphoto" alt="No photo" border="0" src="' . DOL_URL_ROOT . '/viewimage.php?modulepart=' . $module . '&entity=' . $conf->entity . '&file=' . urlencode($pathtophoto) . '"></div>';
					}

					$result .= '</div>';
				} else {
					$result .= img_object(($notooltip ? '' : $label), ($this->picto ? $this->picto : 'generic'), ($notooltip ? (($withpicto != 2) ? 'class="paddingright"' : '') : 'class="' . (($withpicto != 2) ? 'paddingright ' : '') . '"'), 0, 0, $notooltip ? 0 : 1);
				}
			}
		}

		if ($withpicto != 2) {
			$result .= $this->ref;
		}

		$result .= $linkend;
		//if ($withpicto != 2) $result.=(($addlabel && $this->label) ? $sep . dol_trunc($this->label, ($addlabel > 1 ? $addlabel : 0)) : '');

		global $action, $hookmanager;
		$hookmanager->initHooks(array($this->element . 'dao'));
		$parameters = array('id' => $this->id, 'getnomurl' => &$result);
		$reshook = $hookmanager->executeHooks('getNomUrl', $parameters, $this, $action); // Note that $action and $object may have been modified by some hooks
		if ($reshook > 0) {
			$result = $hookmanager->resPrint;
		} else {
			$result .= $hookmanager->resPrint;
		}

		return $result;
	}

	/**
	 *	Return a thumb for kanban views
	 *
	 *	@param      string	    $option                 Where point the link (0=> main card, 1,2 => shipment, 'nolink'=>No link)
	 *  @param		array		$arraydata				Array of data
	 *  @return		string								HTML Code for Kanban thumb.
	 */
	public function getKanbanView($option = '', $arraydata = null)
	{
		global $conf, $langs;


		$selected = (empty($arraydata['selected']) ? 0 : $arraydata['selected']);

		$return = '<div class="box-flex-item box-flex-grow-zero">';
		$return .= '<div class="info-box info-box-sm">';
		$return .= '<span class="info-box-icon bg-infobox-action">';
		$return .= img_picto('', $this->picto);
		$return .= '</span>';
		$return .= '<div class="info-box-content">';
		$return .= '<span class="info-box-ref inline-block tdoverflowmax150 valignmiddle">' . (method_exists($this, 'getNomUrl') ? $this->getNomUrl() : $this->ref) . '</span>';
		if ($selected >= 0) {
			$return .= '<input id="cb' . $this->id . '" class="flat checkforselect fright" type="checkbox" name="toselect[]" value="' . $this->id . '"' . ($selected ? ' checked="checked"' : '') . '>';
		}
		if (property_exists($this, 'label')) {
			$return .= ' <div class="inline-block opacitymedium valignmiddle tdoverflowmax100">' . $this->label . '</div>';
		}
		if (property_exists($this, 'thirdparty') && is_object($this->thirdparty)) {
			$return .= '<br><div class="info-box-ref tdoverflowmax150">' . $this->thirdparty->getNomUrl(1) . '</div>';
		}
		if (property_exists($this, 'amount')) {
			$return .= '<br>';
			$return .= '<span class="info-box-label amount">' . price($this->amount, 0, $langs, 1, -1, -1, $conf->currency) . '</span>';
		}
		if (method_exists($this, 'getLibStatut')) {
			$return .= '<br><div class="info-box-status">' . $this->getLibStatut(3) . '</div>';
		}
		$return .= '</div>';
		$return .= '</div>';
		$return .= '</div>';

		return $return;
	}

	/**
	 *  Return the label of the status
	 *
	 *  @param  int		$mode          0=long label, 1=short label, 2=Picto + short label, 3=Picto, 4=Picto + long label, 5=Short label + Picto, 6=Long label + Picto
	 *  @return	string 			       Label of status
	 */
	public function getLabelStatus($mode = 0)
	{

		return $this->LibStatut($this->status, $mode);
	}

	/**
	 *  Return the label of the status
	 *
	 *  @param  int		$mode          0=long label, 1=short label, 2=Picto + short label, 3=Picto, 4=Picto + long label, 5=Short label + Picto, 6=Long label + Picto
	 *  @return	string 			       Label of status
	 */
	public function getLibStatut($mode = 0)
	{

		return $this->LibStatut($this->status, $mode);
	}

	// phpcs:disable PEAR.NamingConventions.ValidFunctionName.ScopeNotCamelCaps
	/**
	 *  Return the label of a given status
	 *
	 *  @param	int		$status        Id status
	 *  @param  int		$mode          0=long label, 1=short label, 2=Picto + short label, 3=Picto, 4=Picto + long label, 5=Short label + Picto, 6=Long label + Picto
	 *  @return string 			       Label of status
	 */
	public function LibStatut($status, $mode = 0)
	{

		// phpcs:enable
		if (is_null($status)) {
			return '';
		}

		if (empty($this->labelStatus) || empty($this->labelStatusShort)) {
			global $langs;
			//$langs->load("dolizsynch@kreaflow");
			$this->labelStatus[self::STATUS_DRAFT] = $langs->transnoentitiesnoconv('Draft');
			$this->labelStatus[self::STATUS_VALIDATED] = $langs->transnoentitiesnoconv('Enabled');
			$this->labelStatus[self::STATUS_CANCELED] = $langs->transnoentitiesnoconv('Disabled');
			$this->labelStatusShort[self::STATUS_DRAFT] = $langs->transnoentitiesnoconv('Draft');
			$this->labelStatusShort[self::STATUS_VALIDATED] = $langs->transnoentitiesnoconv('Enabled');
			$this->labelStatusShort[self::STATUS_CANCELED] = $langs->transnoentitiesnoconv('Disabled');
		}

		$statusType = 'status' . $status;
		//if ($status == self::STATUS_VALIDATED) $statusType = 'status1';
		if ($status == self::STATUS_CANCELED) {
			$statusType = 'status6';
		}

		return dolGetStatus($this->labelStatus[$status], $this->labelStatusShort[$status], '', $statusType, $mode);
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
	 * Initialise object with example values
	 * Id must be 0 if object instance is a specimen
	 *
	 * @return void
	 */
	public function initAsSpecimen()
	{

		// Set here init that are not commonf fields
		// $this->property1 = ...
		// $this->property2 = ...

		$this->initAsSpecimenCommon();
	}

	/**
	 * 	Create an array of lines
	 *
	 * 	@return array|int		array of lines if OK, <0 if KO
	 */
	public function getLinesArray()
	{

		// ZSPunchClock does not have line items, return empty array
		$this->lines = array();
		return $this->lines;
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
			$conf->global->MYMODULE_MYOBJECT_ADDON = 'mod_zspunchclock_standard';
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
	 *  Create a document onto disk according to template module.
	 *
	 *  @param	    string		$modele			Force template to use ('' to not force)
	 *  @param		Translate	$outputlangs	objet lang a utiliser pour traduction
	 *  @param      int			$hidedetails    Hide details of lines
	 *  @param      int			$hidedesc       Hide description
	 *  @param      int			$hideref        Hide ref
	 *  @param      null|array  $moreparams     Array to provide more information
	 *  @return     int         				0 if KO, 1 if OK
	 */
	public function generateDocument($modele, $outputlangs, $hidedetails = 0, $hidedesc = 0, $hideref = 0, $moreparams = null)
	{
		global $conf, $langs;


		$result = 0;
		$includedocgeneration = 0;

		$langs->load("dolizsynch@kreaflow");

		if (!dol_strlen($modele)) {
			$modele = 'standard_zspunchclock';

			if (!empty($this->model_pdf)) {
				$modele = $this->model_pdf;
			} elseif (getDolGlobalString('MYOBJECT_ADDON_PDF')) {
				$modele = getDolGlobalString('MYOBJECT_ADDON_PDF');
			}
		}

		$modelpath = "core/modules/kreaflow/doc/";

		if ($includedocgeneration && !empty($modele)) {
			$result = $this->commonGenerateDocument($modelpath, $modele, $outputlangs, $hidedetails, $hidedesc, $hideref, $moreparams);
		}

		return $result;
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
		global $conf, $langs;


		//$conf->global->SYSLOG_FILE = 'DOL_DATA_ROOT/dolibarr_mydedicatedlogfile.log';

		$error = 0;
		$this->output = '';
		$this->error = '';

		$now = dol_now();

		$this->db->begin();

		// ...

		$this->db->commit();

		return $error;
	}
}

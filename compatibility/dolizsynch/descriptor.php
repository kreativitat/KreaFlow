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
 * 	\defgroup   dolizsynch     Module DoliZSynch
 *  \brief      DoliZSynch module descriptor.
 *
 *  \file       htdocs/kreaflow/core/modules/modDoliZSynch.class.php
 *  \ingroup    dolizsynch
 *  \brief      Description and activation file for module DoliZSynch
 */
include_once DOL_DOCUMENT_ROOT . '/core/modules/DolibarrModules.class.php';
require_once DOL_DOCUMENT_ROOT . '/core/class/extrafields.class.php';
/**
 *  Description and activation class for module DoliZSynch
 */
class modDoliZSynch extends DolibarrModules
{
	/**
	 * Constructor. Define names, constants, directories, boxes, permissions
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		global $langs, $conf;
		$this->db = $db;		// Id for module (must be unique).
		// Use here a free id (See in Home -> System information -> Dolibarr for list of used modules id).
		// ID 156550 - 156599: Kreativität Works (http://kreativitat.com)
		// ID 156550 kreaproducts
		// ID 156551 degema
		// ID 156552 kreagenproduct
		// ID 156554 kreareports
		// ID 156555 kreatools
		// ID 156556 kreaml
		// ID 156558 dolizsynch
		// ID 156559 kreaecovalor
		// ID 156560 kreainvoiceparser
		$this->numero = 156558;
		$this->rights_class = 'dolizsynch';		// Family can be 'base' (core modules),'crm','financial','hr','projects','products','ecm','technic' (transverse modules),'interface' (link with external tools),'other','...'
		// It is used to group modules by family in module setup page
		$this->family = "Kreativität Works";		// Module position in the family on 2 digits ('01', '10', '20', ...)
		$this->module_position = '66';		// Gives the possibility for the module, to provide his own family info and position of this family (Overwrite $this->family and $this->module_position. Avoid this)
		//$this->familyinfo = array('myownfamily' => array('position' => '01', 'label' => $langs->trans("MyOwnFamily")));
		// Module label (no space allowed), used if translation string 'ModuleDoliZSynchName' not found (DoliZSynch is name of module).
		$this->name = preg_replace('/^mod/i', '', get_class($this));		// Module description, used if translation string 'ModuleDoliZSynchDesc' not found (DoliZSynch is name of module).
		$this->description = "DoliZSynchDescription";
		// Used only if file README.md and README-LL.md not found.
		$this->descriptionlong = "DoliZSynchDescription";
		// Author
		$this->editor_name = 'Kreativität Works';
		$this->editor_url = "https://kreativitat.com";
		// Possible values for version are: 'development', 'experimental', 'dolibarr', 'dolibarr_deprecated', 'experimental_deprecated' or a version string like 'x.y.z'
		$this->version = '3.4.10';
		// Url to the file with your last numberversion of this module.
		//$this->url_last_version = 'http://www.example.com/versionmodule.txt';		// Key used in llx_const table to save module status enabled/disabled (where DOLIZSYNCH is value of property name of module in uppercase)
		$this->const_name = 'MAIN_MODULE_' . strtoupper($this->name);		// Name of image file used for this module.
		// If file is in theme/yourtheme/img directory under name object_pictovalue.png, use this->picto='pictovalue'
		// If file is in module/img directory under name object_pictovalue.png, use this->picto='pictovalue@module'
		// To use a supported fa-xxx css style of font awesome, use this->picto='xxx'
		$this->picto = 'dolizsynch@kreaflow';		// Define some features supported by module (triggers, login, substitutions, menus, css, etc...)
		$this->module_parts = array(
			// Set this to 1 if module has its own trigger directory (core/triggers)
			'triggers' => 0,
			// Set this to 1 if module has its own login method file (core/login)
			'login' => 0,
			// Set this to 1 if module has its own substitution function file (core/substitutions)
			'substitutions' => 0,
			// Set this to 1 if module has its own menus handler directory (core/menus)
			'menus' => 0,
			// Set this to 1 if module overwrite template dir (core/tpl)
			'tpl' => 0,
			// Set this to 1 if module has its own barcode directory (core/modules/barcode)
			'barcode' => 0,
			// Set this to 1 if module has its own models directory (core/modules/xxx)
			'models' => 0,
			// Set this to 1 if module has its own printing directory (core/modules/printing)
			'printing' => 0,
			// Set this to 1 if module has its own theme directory (theme)
			'theme' => 0,
			// Set this to relative path of css file if module has its own css file
			'css' => array(
				//    '/kreaflow/css/dolizsynch.css.php',
			),
			// Set this to relative path of js file if module must load a js on all pages
			'js' => array(
				//   '/kreaflow/js/dolizsynch.js.php',
			),
			// Set here all hooks context managed by module. To find available hook context, make a "grep -r '>initHooks(' *" on source code. You can also set hook context to 'all'
			'hooks' => array(
				'data' => array(),
				'entity' => '0',
			),
			// Set this to 1 if features of module are opened to external users
			'moduleforexternal' => 0,
			// Set this to 1 if module provides REST API classes (class/api_*.class.php)
			'api' => 0,
		);		// Data directories to create when module is enabled.
		// Example: this->dirs = array("/kreaflow/temp","/kreaflow/subdir");
		$this->dirs = array("/dolizsynch/temp");		// Config pages. Put here list of php page, stored into dolizsynch/admin directory, to use to setup module.
		$this->config_page_url = array("setup.php@kreaflow");		// Dependencies
		// A condition to hide module
		$this->hidden = true;
		// List of module class names that must be enabled if this module is enabled. Example: array('always'=>array('modModuleToEnable1','modModuleToEnable2'), 'FR'=>array('modModuleToEnableFR')...)
		$this->depends = array();
		// List of module class names to disable if this one is disabled. Example: array('modModuleToDisable1', ...)
		$this->requiredby = array('modKreaFlow');
		// List of module class names this module is in conflict with. Example: array('modModuleToDisable1', ...)
		$this->conflictwith = array();		// The language file dedicated to your module
		$this->langfiles = array("dolizsynch@kreaflow");		// Prerequisites
		$this->phpmin = array(7, 0); // Minimum version of PHP required by module
		$this->need_dolibarr_version = array(11, -3); // Minimum version of Dolibarr required by module
		$this->need_javascript_ajax = 0;		// Messages at activation
		$this->warnings_activation = array(); // Warning to show when we activate module. array('always'='text') or array('FR'='textfr','MX'='textmx'...)
		$this->warnings_activation_ext = array(); // Warning to show when we activate an external module. array('always'='text') or array('FR'='textfr','MX'='textmx'...)
		//$this->automatic_activation = array('FR'=>'DoliZSynchWasAutomaticallyActivatedBecauseOfYourCountryChoice');
		//$this->always_enabled = true;								// If true, can't be disabled		// Constants
		// List of particular constants to add when module is enabled (key, 'chaine', value, desc, visible, 'current' or 'allentities', deleteonunactive)
		// Example: $this->const=array(1 => array('DOLIZSYNCH_MYNEWCONST1', 'chaine', 'myvalue', 'This is a constant to add', 1),
		//                             2 => array('DOLIZSYNCH_MYNEWCONST2', 'chaine', 'myvalue', 'This is another constant to add', 0, 'current', 1)
		// );
		$this->const = array();		// Some keys to add into the overwriting translation tables
		/*$this->overwrite_translation = array(
			'en_US:ParentCompany'=>'Parent company or reseller',
			'fr_FR:ParentCompany'=>'Maison mère ou revendeur'
		)*/
		if (!isset($conf->dolizsynch) || !isset($conf->dolizsynch->enabled)) {
			$conf->dolizsynch = new stdClass();
			$conf->dolizsynch->enabled = 0;
		}

		// Set up document directories for the module
		if (!isset($conf->dolizsynch->dir_output)) {
			$conf->dolizsynch->dir_output = DOL_DATA_ROOT . '/dolizsynch';
		}
		if (!isset($conf->dolizsynch->multidir_output)) {
			$conf->dolizsynch->multidir_output = array();
		}
		if (!isset($conf->dolizsynch->multidir_output[$conf->entity])) {
			$conf->dolizsynch->multidir_output[$conf->entity] = DOL_DATA_ROOT . (($conf->entity > 1) ? '/' . $conf->entity : '') . '/dolizsynch';
		}

		// Array to add new pages in new tabs
		$this->tabs = array();

		// Replace the default Dolibarr price tab with DoliZSynch version.
		$this->tabs[] = array('data' => 'product:-price:NU:$conf->dolizsynch->enabled');
		// Add DoliZSynch selling price tab at position 2 (where the original price tab was).
		$this->tabs[] = array('data' => 'product:+(2)dolizsynch_sellprice:SellingPrices:dolizsynch@kreaflow:$conf->dolizsynch->enabled:/kreaflow/sellPrice.php?id=__ID__');
		// Add dedicated DoliZSynch tab right after the main product card
		$this->tabs[] = array('data' => 'product:+(1)dolizsynch:DoliZSynchTab:dolizsynch@kreaflow:$conf->dolizsynch->enabled:/kreaflow/zsproduct.php?id=__ID__');

		// Example:
		// $this->tabs[] = array('data'=>'objecttype:+tabname1:Title1:mylangfile@kreaflow:$user->rights->dolizsynch->read:/kreaflow/mynewtab1.php?id=__ID__');  					// To add a new tab identified by code tabname1
		// $this->tabs[] = array('data'=>'objecttype:+tabname2:SUBSTITUTION_Title2:mylangfile@kreaflow:$user->rights->othermodule->read:/kreaflow/mynewtab2.php?id=__ID__',  	// To add another new tab identified by code tabname2. Label will be result of calling all substitution functions on 'Title2' key.
		// $this->tabs[] = array('data'=>'objecttype:-tabname:NU:conditiontoremove');                                                     										// To remove an existing tab identified by code tabname
		//
		// Where objecttype can be
		// 'categories_x'	  to add a tab in category view (replace 'x' by type of category (0=product, 1=supplier, 2=customer, 3=member)
		// 'contact'          to add a tab in contact view
		// 'contract'         to add a tab in contract view
		// 'group'            to add a tab in group view
		// 'intervention'     to add a tab in intervention view
		// 'invoice'          to add a tab in customer invoice view
		// 'invoice_supplier' to add a tab in supplier invoice view
		// 'member'           to add a tab in fundation member view
		// 'opensurveypoll'	  to add a tab in opensurvey poll view
		// 'order'            to add a tab in sale order view
		// 'order_supplier'   to add a tab in supplier order view
		// 'payment'		  to add a tab in payment view
		// 'payment_supplier' to add a tab in supplier payment view
		// 'product'          to add a tab in product view
		// 'propal'           to add a tab in propal view
		// 'project'          to add a tab in project view
		// 'stock'            to add a tab in stock view
		// 'thirdparty'       to add a tab in third party view
		// 'user'             to add a tab in user view		// Dictionaries

		//Dictionairs
		/* Example:
		$this->dictionaries=array(
			'langs'=>'dolizsynch@kreaflow',
			// List of tables we want to see into dictonnary editor
			'tabname'=>array("table1", "table2", "table3"),
			// Label of tables
			'tablib'=>array("Table1", "Table2", "Table3"),
			// Request to select fields
			'tabsql'=>array('SELECT f.rowid as rowid, f.code, f.label, f.active FROM '.MAIN_DB_PREFIX.'table1 as f', 'SELECT f.rowid as rowid, f.code, f.label, f.active FROM '.MAIN_DB_PREFIX.'table2 as f', 'SELECT f.rowid as rowid, f.code, f.label, f.active FROM '.MAIN_DB_PREFIX.'table3 as f'),
			// Sort order
			'tabsqlsort'=>array("label ASC", "label ASC", "label ASC"),
			// List of fields (result of select to show dictionary)
			'tabfield'=>array("code,label", "code,label", "code,label"),
			// List of fields (list of fields to edit a record)
			'tabfieldvalue'=>array("code,label", "code,label", "code,label"),
			// List of fields (list of fields for insert)
			'tabfieldinsert'=>array("code,label", "code,label", "code,label"),
			// Name of columns with primary key (try to always name it 'rowid')
			'tabrowid'=>array("rowid", "rowid", "rowid"),
			// Condition to show each dictionary
			'tabcond'=>array(isModEnabled('dolizsynch'), isModEnabled('dolizsynch'), isModEnabled('dolizsynch')),
			// Tooltip for every fields of dictionaries: DO NOT PUT AN EMPTY ARRAY
			'tabhelp'=>array(array('code'=>$langs->trans('CodeTooltipHelp'), 'field2' => 'field2tooltip'), array('code'=>$langs->trans('CodeTooltipHelp'), 'field2' => 'field2tooltip'), ...),
		);
		*/

		$this->dictionaries = array(
			'langs' => 'dolizsynch@kreaflow',
			// List of tables we want to see into dictionary editor
			'tabname' => array("llx_dolizsynch_zsfamily"),
			// Label of tables
			'tablib' => array("ZS Families"),
			// Request to select fields
			'tabsql' => array('SELECT f.rowid as rowid, f.entity as entity, f.family_id, f.family_name, f.active FROM ' . MAIN_DB_PREFIX . 'dolizsynch_zsfamily as f WHERE f.entity IN (0, ' . ((int) $conf->entity) . ')'),
			// Sort order
			'tabsqlsort' => array("family_name ASC"),
			// List of fields (result of select to show dictionary)
			'tabfield' => array("family_id,family_name"),
			// List of fields (list of fields to edit a record)
			'tabfieldvalue' => array("family_id,family_name"),
			// List of fields (list of fields for insert)
			'tabfieldinsert' => array("family_id,family_name"),
			// Name of columns with primary key (try to always name it 'rowid')
			'tabrowid' => array("rowid"),
			// Entity column used by the core dictionary editor for inserts and updates.
			'tabentity' => array('entity'),
			// Condition to show each dictionary
			'tabcond' => array(isModEnabled('dolizsynch')),
			// Tooltip for every fields of dictionaries: DO NOT PUT AN EMPTY ARRAY
			'tabhelp' => array(array('family_id' => $langs->trans('FamilyIDTooltipHelp'), 'family_name' => $langs->trans('FamilyNameTooltipHelp'))),
		);

		// Boxes/Widgets
		// Add here list of php file(s) stored in dolizsynch/core/boxes that contains a class to show a widget.
		$this->boxes = array(
			//  0 => array(
			//      'file' => 'dolizsynchwidget1.php@kreaflow',
			//      'note' => 'Widget provided by DoliZSynch',
			//      'enabledbydefaulton' => 'Home',
			//  ),
			//  ...
		);
		// Cronjobs (List of cron jobs entries to add when module is enabled)
		// unit_frequency must be 60 for minute, 3600 for hour, 86400 for day, 604800 for week
		$this->cronjobs = array(
			0 => array(
				'label' => $langs->trans('CronJobTitleImportInvoicesHeaders'),
				'jobtype' => 'method',
				'class' => '/kreaflow/class/zsinvoicesynch.class.php',
				'objectname' => 'ZSInvoiceSynch',
				'method' => 'importInvoicesHeaders',
				'parameters' => '',
				'comment' => $langs->trans('CronJobComentImportInvoicesHeaders'),
				'frequency' => 1,
				'unitfrequency' => 900, // 86400 seconds = 1 day
				'status' => 0,
				'test' => 'isModEnabled("dolizsynch")',
				'priority' => 1,
				'datestart' => dol_now() + 300, // Schedule to start 5 minutes after module activation
			),
			1 => array(
				'label' => $langs->trans('CronJobTitleSynchronizeAllUnsynchronizedInvoicesByStore'),
				'jobtype' => 'method',
				'class' => '/kreaflow/class/zsinvoicesynch.class.php',
				'objectname' => 'ZSInvoiceSynch',
				'method' => 'synchronizeAllUnsynchronizedInvoicesByStore',
				'parameters' => '',
				'comment' => $langs->trans('CronJobComentSynchronizeAllUnsynchronizedInvoicesByStore'),
				'frequency' => 1,
				'unitfrequency' => 900, // 900 seconds = 15 minutes
				'status' => 0,
				'test' => 'isModEnabled("dolizsynch")',
				'priority' => 1,
			),
			2 => array(
				'label' => $langs->trans('CronJobTitleSynchronizeYesterdayInvoices'),
				'jobtype' => 'method',
				'class' => '/kreaflow/class/zsinvoicesynch.class.php',
				'objectname' => 'ZSInvoiceSynch',
				'method' => 'synchronizeYesterdayInvoices',
				'parameters' => '',
				'comment' => $langs->trans('CronJobComentSynchronizeYesterdayInvoices'),
				'frequency' => 1,
				'unitfrequency' => 86400, // 86400 seconds = 1 day
				'status' => 0,
				'test' => 'isModEnabled("dolizsynch")',
				'priority' => 1,
			),
			3 => array(
				'label' => $langs->trans('CronJobTitleSynchronizePunchClock_performStoreZsPunchclock'),
				'jobtype' => 'method',
				'class' => '/kreaflow/class/zstimeregisterimport.class.php',
				'objectname' => 'TimeRegisterImport',
				'method' => 'performStoreZsPunchclock',
				'parameters' => '',
				'comment' => $langs->trans('CronJobTitleSynchronizePunchClock_performStoreZsPunchclock_comment'),
				'frequency' => 1,
				'unitfrequency' => 900, // 900 seconds = 15 minutes
				'status' => 0,
				'test' => 'isModEnabled("dolizsynch")',
				'priority' => 2
			),
			4 => array(
				'label' => $langs->trans('CronJobTitleSynchronizePunchClock_performPairTimeRegisters'),
				'jobtype' => 'method',
				'class' => '/kreaflow/class/zstimeregisterimport.class.php',
				'objectname' => 'TimeRegisterImport',
				'method' => 'performPairTimeRegisters',
				'parameters' => '',
				'comment' => $langs->trans('CronJobTitleSynchronizePunchClock_performPairTimeRegisters_comment'),
				'frequency' => 1,
				'unitfrequency' => 900, // Complete operational days only; independent of DST wall-clock jumps.
				'status' => 0,
				'test' => 'isModEnabled("dolizsynch")',
				'priority' => 3
			),
			5 => array(
				'label' => $langs->trans('CronJobTitleInterEntityInvoiceConversion'),
				'jobtype' => 'method',
				'class' => '/kreaflow/class/zsstoreinvoiceconverter.class.php',
				'objectname' => 'ZSStoreInvoiceConverter',
				'method' => 'runScheduledInterEntityInvoices',
				'parameters' => '',
				'comment' => $langs->trans('CronJobCommentInterEntityInvoiceConversion'),
				'frequency' => 1,
				'unitfrequency' => 3600, // 3600 seconds = 60 minutes
				'status' => 0,
				'test' => 'isModEnabled("multicompany") && getDolGlobalString("DOLIZSYNCH_INTERENTITY_INVOICE_ENABLED")',
				'priority' => 3
			),
			6 => array(
				'label' => 'DZ Notify Missed Punches',
				'jobtype' => 'method',
				'class' => '/kreaflow/class/zspunchclock_synch.class.php',
				'objectname' => 'ZSPunchClock_Synch',
				'method' => 'notifyMissedPunches',
				'parameters' => '',
				'comment' => 'Send telegram notifications for missed punch clock entries (IN without OUT or OUT without IN)',
				'frequency' => 1,
				'unitfrequency' => 86400, // 86400 seconds = 24 hours
				'status' => 0,
				'test' => 'isModEnabled("dolizsynch")',
				'priority' => 2
			),
			7 => array(
				'label' => $langs->trans('CronJobTitleSyncHealthCheck'),
				'jobtype' => 'method',
				'class' => '/kreaflow/class/zssyncmonitor.class.php',
				'objectname' => 'ZSSyncMonitor',
				'method' => 'checkHealthAndNotify',
				'parameters' => '',
				'comment' => $langs->trans('CronJobCommentSyncHealthCheck'),
				'frequency' => 1,
				'unitfrequency' => 3600, // 3600 seconds = 1 hour
				'status' => 0,
				'test' => 'false',
				'priority' => 3
			),
			8 => array(
				'label' => $langs->trans('CronJobTitleScheduledPriceUpdates'),
				'jobtype' => 'method',
				'class' => '/kreaflow/class/zsprodsynch.class.php',
				'objectname' => 'ZSProductSynch',
				'method' => 'processScheduledPriceUpdates',
				'parameters' => '100',
				'comment' => $langs->trans('CronJobCommentScheduledPriceUpdates'),
				'frequency' => 1,
				'unitfrequency' => 300, // 300 seconds = 5 minutes
				'status' => 0,
				'test' => 'isModEnabled("dolizsynch")',
				'priority' => 2
			),
			9 => array(
				'label' => $langs->trans('CronJobTitleProductPriceSynchronization'),
				'jobtype' => 'method',
				'class' => '/kreaflow/class/zsproductpricecron.class.php',
				'objectname' => 'ZSProductPriceCron',
				'method' => 'runScheduledPriceSynchronization',
				'parameters' => '',
				'comment' => $langs->trans('CronJobCommentProductPriceSynchronization'),
				'frequency' => 1,
				'unitfrequency' => 86400,
				'status' => 0,
				'test' => 'isModEnabled("dolizsynch") && getDolGlobalInt("DOLIZSYNCH_PRODUCT_PRICE_SYNC_ENABLED")',
				'priority' => 2
			),
			10 => array(
				'label' => $langs->trans('CronJobTitleProductPriceSynchronizationRecovery'),
				'jobtype' => 'method',
				'class' => '/kreaflow/class/zsproductpricecron.class.php',
				'objectname' => 'ZSProductPriceCron',
				'method' => 'recoverInterruptedPriceSynchronization',
				'parameters' => '',
				'comment' => $langs->trans('CronJobCommentProductPriceSynchronizationRecovery'),
				'frequency' => 1,
				'unitfrequency' => 300,
				'status' => 0,
				'test' => 'isModEnabled("dolizsynch") && getDolGlobalInt("DOLIZSYNCH_PRODUCT_PRICE_SYNC_ENABLED")',
				'priority' => 2
			)
		);


		// Example: $this->cronjobs=array(
		//    0=>array('label'=>'My label', 'jobtype'=>'method', 'class'=>'/dir/class/file.class.php', 'objectname'=>'MyClass', 'method'=>'myMethod', 'parameters'=>'param1, param2', 'comment'=>'Comment', 'frequency'=>2, 'unitfrequency'=>3600, 'status'=>0, 'test'=>'isModEnabled("dolizsynch")', 'priority'=>50),
		//    1=>array('label'=>'My label', 'jobtype'=>'command', 'command'=>'', 'parameters'=>'param1, param2', 'comment'=>'Comment', 'frequency'=>1, 'unitfrequency'=>3600*24, 'status'=>0, 'test'=>'isModEnabled("dolizsynch")', 'priority'=>50)
		// );		// Permissions provided by this module
		$this->rights = array();
		$r = 0;

		// IMPORTANT: Keep IDs fixed to preserve existing user/group rights bindings.
		$this->rights[$r][0] = 15655801;
		$this->rights[$r][1] = 'Ler picagens de ponto';
		$this->rights[$r][4] = 'zspunchclock';
		$this->rights[$r][5] = 'read';
		$r++;
		$this->rights[$r][0] = 156558102;
		$this->rights[$r][1] = 'Criar/Alterar picagens de ponto';
		$this->rights[$r][4] = 'zspunchclock';
		$this->rights[$r][5] = 'write';
		$r++;
		$this->rights[$r][0] = 156558203;
		$this->rights[$r][1] = 'Apagar picagens de ponto';
		$this->rights[$r][4] = 'zspunchclock';
		$this->rights[$r][5] = 'delete';
		$r++;
		$this->rights[$r][0] = 156558304;
		$this->rights[$r][1] = 'Read shifts';
		$this->rights[$r][4] = 'shift';
		$this->rights[$r][5] = 'read';
		$r++;
		$this->rights[$r][0] = 156558405;
		$this->rights[$r][1] = 'Create/Update shifts';
		$this->rights[$r][4] = 'shift';
		$this->rights[$r][5] = 'write';
		$r++;
		$this->rights[$r][0] = 156558506;
		$this->rights[$r][1] = 'Delete shifts';
		$this->rights[$r][4] = 'shift';
		$this->rights[$r][5] = 'delete';
		$r++;
		$this->rights[$r][0] = 156558607;
		$this->rights[$r][1] = 'Manage tasks (create/edit/delete)';
		$this->rights[$r][4] = 'shift';
		$this->rights[$r][5] = 'manage_tasks';
		$r++;
		$this->rights[$r][0] = 156558708;
		$this->rights[$r][1] = 'Edit employee weekly hours';
		$this->rights[$r][4] = 'shift';
		$this->rights[$r][5] = 'edit_employee_hours';
		$r++;
		$this->rights[$r][0] = 156558809;
		$this->rights[$r][1] = 'Horarios, picagens de ponto e processamento salarial';
		$this->rights[$r][4] = 'shift';
		$this->rights[$r][5] = 'report';
		$r++;
		$this->rights[$r][0] = 156558910;
		$this->rights[$r][1] = 'Ver relatório mensal (RH)';
		$this->rights[$r][4] = 'shift';
		$this->rights[$r][5] = 'schedule_report_view';
		$r++;
		$this->rights[$r][0] = 1565581011;
		$this->rights[$r][1] = 'Ver férias por ano';
		$this->rights[$r][4] = 'shift';
		$this->rights[$r][5] = 'schedule_vacations_view';
		$r++;
		$this->rights[$r][0] = 1565581112;
		$this->rights[$r][1] = 'Editar férias por ano';
		$this->rights[$r][4] = 'shift';
		$this->rights[$r][5] = 'schedule_vacations_edit';
		$r++;
		$this->rights[$r][0] = 1565581213;
		$this->rights[$r][1] = 'Ver log de alteracoes de horarios';
		$this->rights[$r][4] = 'shift';
		$this->rights[$r][5] = 'schedule_audit_view';
		$r++;
		$this->rights[$r][0] = 1565581314;
		$this->rights[$r][1] = 'Aceder ao criador manual de faturas ZSBMS';
		$this->rights[$r][4] = 'zsbmsinvoicecreator';
		$this->rights[$r][5] = 'read';
		$r++;

		$this->menu = array();
		$r = 0;
		// Add here entries to declare new menus
		/* BEGIN MODULEBUILDER TOPMENU */

		/* ADMINISTRATOR SYNCHRONIZATION TOOLS IN DOLIBARR TOOLS */
		$this->menu[$r++] = array(
			'fk_menu' => 'fk_mainmenu=tools,fk_leftmenu=kreaflow',
			'type' => 'left',
			'titre' => 'SyncTools',
			'prefix' => '',
			'mainmenu' => 'tools',
			'leftmenu' => 'dolizsynch_sync_tools',
			'url' => '/kreaflow/admin/sync_tools.php?mainmenu=tools&leftmenu=dolizsynch_sync_tools',
			'langs' => 'dolizsynch@kreaflow',
			'position' => 1002,
			'enabled' => '$conf->dolizsynch->enabled',
			'perms' => '$user->admin',
			'target' => '',
			'user' => 0,
		);

		/*MENU PUNCHCLOCK IN DEGEMA*/
		// Section (level 1)
		$this->menu[$r++] = array(
			'fk_menu'   => 'fk_mainmenu=degema',
			'type'      => 'left',
			'titre'     => 'Picagens de ponto',
			'mainmenu'  => 'degema',
			'leftmenu'  => 'degema_ponto',     // <-- only here
			'url'       => '/kreaflow/zspunchclock/zspunchclock_synch_list.php',
			'langs'     => 'dolizsynch@kreaflow',
			'position'  => 3300 + $r,
			'enabled'   => '$conf->degema->enabled',
			'perms'     => '$user->rights->dolizsynch->zspunchclock->read',
			'target'    => '',
			'user'      => 0,
		);

		// Children (level 2) — note unique/empty leftmenu values
		$this->menu[$r++] = array(
			'fk_menu'   => 'fk_mainmenu=degema,fk_leftmenu=degema_ponto',
			'type'      => 'left',
			'titre'     => 'Picagens de hoje',
			'mainmenu'  => 'degema',
			'leftmenu'  => 'degema_ponto_today',  // unique or ''
			'url'       => '/kreaflow/zspunchclock/zspunchclock_synch_list.php',
			'langs'     => 'dolizsynch@kreaflow',
			'position'  => 3300 + $r,
			'enabled'   => '$conf->degema->enabled',
			'perms'     => '$user->rights->dolizsynch->zspunchclock->read',
			'target'    => '',
			'user'      => 0,
		);

		$this->menu[$r++] = array(
			'fk_menu'   => 'fk_mainmenu=degema,fk_leftmenu=degema_ponto',
			'type'      => 'left',
			'titre'     => 'Picagens de outros dias',
			'mainmenu'  => 'degema',
			'leftmenu'  => 'degema_ponto_otherdays', // unique or ''
			'url'       => '/kreaflow/zspunchclock/zspunchclock_list.php',
			'langs'     => 'dolizsynch@kreaflow',
			'position'  => 3300 + $r,
			'enabled'   => '$conf->degema->enabled',
			'perms'     => '$user->rights->dolizsynch->zspunchclock->read',
			'target'    => '',
			'user'      => 0,
		);

		$this->menu[$r++] = array(
			'fk_menu'   => 'fk_mainmenu=degema,fk_leftmenu=degema_ponto',
			'type'      => 'left',
			'titre'     => 'PunchClockAllListMenu',
			'mainmenu'  => 'degema',
			'leftmenu'  => 'degema_ponto_alllist',
			'url'       => '/kreaflow/zspunchclock/zspunchclock_all_list.php',
			'langs'     => 'dolizsynch@kreaflow',
			'position'  => 3300 + $r,
			'enabled'   => '$conf->degema->enabled',
			'perms'     => '$user->rights->dolizsynch->zspunchclock->read',
			'target'    => '',
			'user'      => 0,
		);

		$this->menu[$r++] = array(
			'fk_menu'   => 'fk_mainmenu=degema,fk_leftmenu=degema_ponto',
			'type'      => 'left',
			'titre'     => 'Picagens por Funcionário',
			'mainmenu'  => 'degema',
			'leftmenu'  => 'degema_ponto_byemployee', // unique or ''
			'url'       => '/kreaflow/zspunchclock/zspunchclock_employee.php',
			'langs'     => 'dolizsynch@kreaflow',
			'position'  => 3300 + $r,
			'enabled'   => '$conf->degema->enabled',
			'perms'     => '$user->rights->dolizsynch->zspunchclock->read',
			'target'    => '',
			'user'      => 0,
		);

		/*MENU SHIFT PLANNER IN DEGEMA*/
		$this->menu[$r++] = array(
			'fk_menu'   => 'fk_mainmenu=degema',
			'type'      => 'left',
			'titre'     => 'Horários',
			'mainmenu'  => 'degema',
			'leftmenu'  => 'degema_horarios',
			'url'       => '/kreaflow/schedule/schedule_employees.php',
			'langs'     => 'dolizsynch@kreaflow',
			'position'  => 3300 + $r,
			'enabled'   => '$conf->degema->enabled',
			'perms'     => '$user->rights->dolizsynch->shift->read',
			'target'    => '',
			'user'      => 0,
		);

		$this->menu[$r++] = array(
			'fk_menu'   => 'fk_mainmenu=degema,fk_leftmenu=degema_horarios',
			'type'      => 'left',
			'titre'     => 'Horários - Funcionários',
			'mainmenu'  => 'degema',
			'leftmenu'  => 'degema_schedule_employees',
			'url'       => '/kreaflow/schedule/schedule_employees.php',
			'langs'     => 'dolizsynch@kreaflow',
			'position'  => 3300 + $r,
			'enabled'   => '$conf->degema->enabled',
			'perms'     => '$user->rights->dolizsynch->shift->read',
			'target'    => '',
			'user'      => 0,
		);

		$this->menu[$r++] = array(
			'fk_menu'   => 'fk_mainmenu=degema,fk_leftmenu=degema_horarios',
			'type'      => 'left',
			'titre'     => 'Horários - Barras (Semana)',
			'mainmenu'  => 'degema',
			'leftmenu'  => 'degema_schedule_bars',
			'url'       => '/kreaflow/schedule/schedule_bars.php',
			'langs'     => 'dolizsynch@kreaflow',
			'position'  => 3300 + $r,
			'enabled'   => '$conf->degema->enabled',
			'perms'     => '$user->rights->dolizsynch->shift->read',
			'target'    => '',
			'user'      => 0,
		);

		$this->menu[$r++] = array(
			'fk_menu'   => 'fk_mainmenu=degema,fk_leftmenu=degema_horarios',
			'type'      => 'left',
			'titre'     => 'Horários - Barras (Dia)',
			'mainmenu'  => 'degema',
			'leftmenu'  => 'degema_schedule_bars_day',
			'url'       => '/kreaflow/schedule/schedule_bars_horizontal.php',
			'langs'     => 'dolizsynch@kreaflow',
			'position'  => 3300 + $r,
			'enabled'   => '$conf->degema->enabled',
			'perms'     => '$user->rights->dolizsynch->shift->read',
			'target'    => '',
			'user'      => 0,
		);

		$this->menu[$r++] = array(
			'fk_menu'   => 'fk_mainmenu=degema,fk_leftmenu=degema_horarios',
			'type'      => 'left',
			'titre'     => 'ScheduleAuditLog',
			'mainmenu'  => 'degema',
			'leftmenu'  => 'degema_schedule_audit',
			'url'       => '/kreaflow/schedule/schedule_audit.php',
			'langs'     => 'dolizsynch@kreaflow',
			'position'  => 3300 + $r,
			'enabled'   => '$conf->degema->enabled',
			'perms'     => '$user->rights->dolizsynch->shift->schedule_audit_view',
			'target'    => '',
			'user'      => 0,
		);

		$this->menu[$r++] = array(
			'fk_menu'   => 'fk_mainmenu=degema',
			'type'      => 'left',
			'titre'     => 'Férias, licenças e faltas',
			'mainmenu'  => 'degema',
			'leftmenu'  => 'degema_leaves',
			'url'       => '/kreaflow/leaves/list.php',
			'langs'     => 'dolizsynch@kreaflow',
			'position'  => 3300 + $r,
			'enabled'   => '$conf->degema->enabled',
			'perms'     => '$user->rights->dolizsynch->shift->schedule_vacations_view',
			'target'    => '',
			'user'      => 0,
		);

		$this->menu[$r++] = array(
			'fk_menu'   => 'fk_mainmenu=degema,fk_leftmenu=degema_leaves',
			'type'      => 'left',
			'titre'     => 'Férias por Ano',
			'mainmenu'  => 'degema',
			'leftmenu'  => 'degema_schedule_vacations',
			'url'       => '/kreaflow/leaves/zsvacations.php',
			'langs'     => 'dolizsynch@kreaflow',
			'position'  => 3300 + $r,
			'enabled'   => '$conf->degema->enabled',
			'perms'     => '$user->rights->dolizsynch->shift->schedule_vacations_view',
			'target'    => '',
			'user'      => 0,
		);

		// Vacation quotas (define vacations)
		$this->menu[$r++] = array(
			'fk_menu'   => 'fk_mainmenu=degema,fk_leftmenu=degema_leaves',
			'type'      => 'left',
			'titre'     => 'Saldo de férias',
			'mainmenu'  => 'degema',
			'leftmenu'  => 'degema_define_vacations',
			'url'       => '/kreaflow/leaves/define_vacations.php',
			'langs'     => 'dolizsynch@kreaflow',
			'position'  => 3300 + $r,
			'enabled'   => '$conf->degema->enabled',
			'perms'     => '$user->rights->dolizsynch->shift->schedule_vacations_view',
			'target'    => '',
			'user'      => 0,
		);

		$this->menu[$r++] = array(
			'fk_menu'   => 'fk_mainmenu=degema,fk_leftmenu=degema_leaves',
			'type'      => 'left',
			'titre'     => 'Relatório Mensal (RH)',
			'mainmenu'  => 'degema',
			'leftmenu'  => 'degema_schedule_report',
			'url'       => '/kreaflow/schedule/schedule_report.php',
			'langs'     => 'dolizsynch@kreaflow',
			'position'  => 3300 + $r,
			'enabled'   => '$conf->degema->enabled',
			'perms'     => '$user->rights->dolizsynch->shift->schedule_report_view',
			'target'    => '',
			'user'      => 0,
		);

		// DoliZSynch Leave list (custom storage)
		$this->menu[$r++] = array(
			'fk_menu'   => 'fk_mainmenu=degema,fk_leftmenu=degema_leaves',
			'type'      => 'left',
			'titre'     => 'Férias, licenças e faltas',
			'mainmenu'  => 'degema',
			'leftmenu'  => 'degema_leave_list',
			'url'       => '/kreaflow/leaves/list.php',
			'langs'     => 'dolizsynch@kreaflow',
			'position'  => 3300 + $r,
			'enabled'   => '$conf->degema->enabled',
			'perms'     => '$user->rights->dolizsynch->shift->read',
			'target'    => '',
			'user'      => 0,
		);

		$this->menu[$r++] = array(
			'fk_menu'   => 'fk_mainmenu=degema,fk_leftmenu=degema_leaves',
			'type'      => 'left',
			'titre'     => 'Registar ausência',
			'mainmenu'  => 'degema',
			'leftmenu'  => 'degema_leave_new',
			'url'       => '/kreaflow/leaves/card.php?action=create',
			'langs'     => 'dolizsynch@kreaflow',
			'position'  => 3300 + $r,
			'enabled'   => '$conf->degema->enabled',
			'perms'     => '$user->rights->dolizsynch->shift->write',
			'target'    => '',
			'user'      => 0,
		);

		$this->menu[$r++] = array(
			'fk_menu'   => 'fk_mainmenu=degema,fk_leftmenu=degema_ponto',
			'type'      => 'left',
			'titre'     => 'Projectos (Ponto)',
			'mainmenu'  => 'degema',
			'leftmenu'  => 'degema_ponto_task',
			'url'       => '/kreaflow/zspunchclock/zspunchclock.php',
			'langs'     => 'dolizsynch@kreaflow',
			'position'  => 3300 + $r,
			'enabled'   => '$conf->degema->enabled',
			'perms'     => '$user->rights->dolizsynch->zspunchclock->read',
			'target'    => '',
			'user'      => 0,
		);

		/*MENU SHIFT PLANNER IN HRM*/
		$this->menu[$r++] = array(
			'fk_menu'   => 'fk_mainmenu=hrm',
			'type'      => 'left',
			'titre'     => 'Picagens de ponto',
			'mainmenu'  => 'hrm',
			'leftmenu'  => 'hrm_ponto',
			'url'       => '/kreaflow/zspunchclock/zspunchclock_synch_list.php',
			'langs'     => 'dolizsynch@kreaflow',
			'position'  => 3300 + $r,
			'enabled'   => '$conf->hrm->enabled',
			'perms'     => '$user->rights->dolizsynch->zspunchclock->read',
			'target'    => '',
			'user'      => 0,
		);

		$this->menu[$r++] = array(
			'fk_menu'   => 'fk_mainmenu=hrm,fk_leftmenu=hrm_ponto',
			'type'      => 'left',
			'titre'     => 'Picagens de hoje',
			'mainmenu'  => 'hrm',
			'leftmenu'  => 'hrm_ponto_today',
			'url'       => '/kreaflow/zspunchclock/zspunchclock_synch_list.php',
			'langs'     => 'dolizsynch@kreaflow',
			'position'  => 3300 + $r,
			'enabled'   => '$conf->hrm->enabled',
			'perms'     => '$user->rights->dolizsynch->zspunchclock->read',
			'target'    => '',
			'user'      => 0,
		);

		$this->menu[$r++] = array(
			'fk_menu'   => 'fk_mainmenu=hrm,fk_leftmenu=hrm_ponto',
			'type'      => 'left',
			'titre'     => 'Picagens de outros dias',
			'mainmenu'  => 'hrm',
			'leftmenu'  => 'hrm_ponto_otherdays',
			'url'       => '/kreaflow/zspunchclock/zspunchclock_list.php',
			'langs'     => 'dolizsynch@kreaflow',
			'position'  => 3300 + $r,
			'enabled'   => '$conf->hrm->enabled',
			'perms'     => '$user->rights->dolizsynch->zspunchclock->read',
			'target'    => '',
			'user'      => 0,
		);

		$this->menu[$r++] = array(
			'fk_menu'   => 'fk_mainmenu=hrm,fk_leftmenu=hrm_ponto',
			'type'      => 'left',
			'titre'     => 'PunchClockAllListMenu',
			'mainmenu'  => 'hrm',
			'leftmenu'  => 'hrm_ponto_alllist',
			'url'       => '/kreaflow/zspunchclock/zspunchclock_all_list.php',
			'langs'     => 'dolizsynch@kreaflow',
			'position'  => 3300 + $r,
			'enabled'   => '$conf->hrm->enabled',
			'perms'     => '$user->rights->dolizsynch->zspunchclock->read',
			'target'    => '',
			'user'      => 0,
		);

		$this->menu[$r++] = array(
			'fk_menu'   => 'fk_mainmenu=hrm,fk_leftmenu=hrm_ponto',
			'type'      => 'left',
			'titre'     => 'Picagens por Funcionário',
			'mainmenu'  => 'hrm',
			'leftmenu'  => 'hrm_ponto_byemployee',
			'url'       => '/kreaflow/zspunchclock/zspunchclock_employee.php',
			'langs'     => 'dolizsynch@kreaflow',
			'position'  => 3300 + $r,
			'enabled'   => '$conf->hrm->enabled',
			'perms'     => '$user->rights->dolizsynch->zspunchclock->read',
			'target'    => '',
			'user'      => 0,
		);

		$this->menu[$r++] = array(
			'fk_menu'   => 'fk_mainmenu=hrm',
			'type'      => 'left',
			'titre'     => 'Horários',
			'mainmenu'  => 'hrm',
			'leftmenu'  => 'hrm_horarios',
			'url'       => '/kreaflow/schedule/schedule_employees.php',
			'langs'     => 'dolizsynch@kreaflow',
			'position'  => 3300 + $r,
			'enabled'   => '$conf->hrm->enabled',
			'perms'     => '$user->rights->dolizsynch->shift->read',
			'target'    => '',
			'user'      => 0,
		);

		$this->menu[$r++] = array(
			'fk_menu'   => 'fk_mainmenu=hrm,fk_leftmenu=hrm_horarios',
			'type'      => 'left',
			'titre'     => 'Horários - Funcionários',
			'mainmenu'  => 'hrm',
			'leftmenu'  => 'hrm_schedule_employees',
			'url'       => '/kreaflow/schedule/schedule_employees.php',
			'langs'     => 'dolizsynch@kreaflow',
			'position'  => 3300 + $r,
			'enabled'   => '$conf->hrm->enabled',
			'perms'     => '$user->rights->dolizsynch->shift->read',
			'target'    => '',
			'user'      => 0,
		);

		$this->menu[$r++] = array(
			'fk_menu'   => 'fk_mainmenu=hrm,fk_leftmenu=hrm_horarios',
			'type'      => 'left',
			'titre'     => 'Horários - Barras (Semana)',
			'mainmenu'  => 'hrm',
			'leftmenu'  => 'hrm_schedule_bars',
			'url'       => '/kreaflow/schedule/schedule_bars.php',
			'langs'     => 'dolizsynch@kreaflow',
			'position'  => 3300 + $r,
			'enabled'   => '$conf->hrm->enabled',
			'perms'     => '$user->rights->dolizsynch->shift->read',
			'target'    => '',
			'user'      => 0,
		);

		$this->menu[$r++] = array(
			'fk_menu'   => 'fk_mainmenu=hrm,fk_leftmenu=hrm_horarios',
			'type'      => 'left',
			'titre'     => 'Horários - Barras (Dia)',
			'mainmenu'  => 'hrm',
			'leftmenu'  => 'hrm_schedule_bars_day',
			'url'       => '/kreaflow/schedule/schedule_bars_horizontal.php',
			'langs'     => 'dolizsynch@kreaflow',
			'position'  => 3300 + $r,
			'enabled'   => '$conf->hrm->enabled',
			'perms'     => '$user->rights->dolizsynch->shift->read',
			'target'    => '',
			'user'      => 0,
		);

		$this->menu[$r++] = array(
			'fk_menu'   => 'fk_mainmenu=hrm,fk_leftmenu=hrm_horarios',
			'type'      => 'left',
			'titre'     => 'ScheduleAuditLog',
			'mainmenu'  => 'hrm',
			'leftmenu'  => 'hrm_schedule_audit',
			'url'       => '/kreaflow/schedule/schedule_audit.php',
			'langs'     => 'dolizsynch@kreaflow',
			'position'  => 3300 + $r,
			'enabled'   => '$conf->hrm->enabled',
			'perms'     => '$user->rights->dolizsynch->shift->schedule_audit_view',
			'target'    => '',
			'user'      => 0,
		);

		// HRM leaves menu
		$this->menu[$r++] = array(
			'fk_menu'   => 'fk_mainmenu=hrm',
			'type'      => 'left',
			'titre'     => 'Férias, licenças e faltas',
			'mainmenu'  => 'hrm',
			'leftmenu'  => 'hrm_leaves',
			'url'       => '/kreaflow/leaves/list.php',
			'langs'     => 'dolizsynch@kreaflow',
			'position'  => 3300 + $r,
			'enabled'   => '$conf->hrm->enabled',
			'perms'     => '$user->rights->dolizsynch->shift->read',
			'target'    => '',
			'user'      => 0,
		);

		$this->menu[$r++] = array(
			'fk_menu'   => 'fk_mainmenu=hrm,fk_leftmenu=hrm_leaves',
			'type'      => 'left',
			'titre'     => 'Saldo de férias',
			'mainmenu'  => 'hrm',
			'leftmenu'  => 'hrm_define_vacations',
			'url'       => '/kreaflow/leaves/define_vacations.php',
			'langs'     => 'dolizsynch@kreaflow',
			'position'  => 3300 + $r,
			'enabled'   => '$conf->hrm->enabled',
			'perms'     => '$user->rights->dolizsynch->shift->schedule_vacations_view',
			'target'    => '',
			'user'      => 0,
		);

		$this->menu[$r++] = array(
			'fk_menu'   => 'fk_mainmenu=hrm',
			'type'      => 'left',
			'titre'     => 'Férias, licenças e faltas',
			'mainmenu'  => 'hrm',
			'leftmenu'  => 'hrm_leaves',
			'url'       => '/kreaflow/leaves/list.php',
			'langs'     => 'dolizsynch@kreaflow',
			'position'  => 3300 + $r,
			'enabled'   => '$conf->hrm->enabled',
			'perms'     => '$user->rights->dolizsynch->shift->schedule_vacations_view',
			'target'    => '',
			'user'      => 0,
		);

		$this->menu[$r++] = array(
			'fk_menu'   => 'fk_mainmenu=hrm,fk_leftmenu=hrm_leaves',
			'type'      => 'left',
			'titre'     => 'Férias por Ano',
			'mainmenu'  => 'hrm',
			'leftmenu'  => 'hrm_schedule_vacations',
			'url'       => '/kreaflow/leaves/zsvacations.php',
			'langs'     => 'dolizsynch@kreaflow',
			'position'  => 3300 + $r,
			'enabled'   => '$conf->hrm->enabled',
			'perms'     => '$user->rights->dolizsynch->shift->schedule_vacations_view',
			'target'    => '',
			'user'      => 0,
		);

		$this->menu[$r++] = array(
			'fk_menu'   => 'fk_mainmenu=hrm,fk_leftmenu=hrm_leaves',
			'type'      => 'left',
			'titre'     => 'Relatório Mensal (RH)',
			'mainmenu'  => 'hrm',
			'leftmenu'  => 'hrm_schedule_report',
			'url'       => '/kreaflow/schedule/schedule_report.php',
			'langs'     => 'dolizsynch@kreaflow',
			'position'  => 3300 + $r,
			'enabled'   => '$conf->hrm->enabled',
			'perms'     => '$user->rights->dolizsynch->shift->schedule_report_view',
			'target'    => '',
			'user'      => 0,
		);

		// DoliZSynch Leave list (custom storage)
		$this->menu[$r++] = array(
			'fk_menu'   => 'fk_mainmenu=hrm,fk_leftmenu=hrm_leaves',
			'type'      => 'left',
			'titre'     => 'Férias, licenças e faltas',
			'mainmenu'  => 'hrm',
			'leftmenu'  => 'hrm_leave_list',
			'url'       => '/kreaflow/leaves/list.php',
			'langs'     => 'dolizsynch@kreaflow',
			'position'  => 3300 + $r,
			'enabled'   => '$conf->hrm->enabled',
			'perms'     => '$user->rights->dolizsynch->shift->read',
			'target'    => '',
			'user'      => 0,
		);

		$this->menu[$r++] = array(
			'fk_menu'   => 'fk_mainmenu=hrm,fk_leftmenu=hrm_leaves',
			'type'      => 'left',
			'titre'     => 'Registar ausência',
			'mainmenu'  => 'hrm',
			'leftmenu'  => 'hrm_leave_new',
			'url'       => '/kreaflow/leaves/card.php?action=create',
			'langs'     => 'dolizsynch@kreaflow',
			'position'  => 3300 + $r,
			'enabled'   => '$conf->hrm->enabled',
			'perms'     => '$user->rights->dolizsynch->shift->write',
			'target'    => '',
			'user'      => 0,
		);

		/*MENU VISIBLE CATEGORIES*/
		$this->menu[$r++] = array(
			'fk_menu' => 'fk_mainmenu=products,fk_leftmenu=product',
			'type' => 'left',
			'titre' => 'DoliZSynchVisibleCategoriesMenu',
			'mainmenu' => 'products',
			'leftmenu' => 'product_produtosdevenda',
			'url' => '/custom/kreaflow/product_category_list.php',
			'langs' => 'dolizsynch@kreaflow',
			'position' => 1200 + $r,
			'enabled' => '$conf->dolizsynch->enabled',
			'perms' => '$user->rights->produit->lire',
			'target' => '',
			'user' => 2,
		);

		/*MENU SELL PRICE (FALLBACK IF KREAPRODUCTS NOT ENABLED)*/
		$this->menu[$r++] = array(
			'fk_menu' => 'fk_mainmenu=products',
			'type' => 'left',
			'titre' => 'Preços de Venda (ZS)',
			'mainmenu' => 'products',
			'leftmenu' => '',
			'url' => '/kreaflow/sellPrice.php?action=edit_price&id=__ID__',
			'langs' => 'dolizsynch@kreaflow',
			'position' => 3300 + $r,
			// Only show if KreaProducts module is NOT enabled
			'enabled' => '!$conf->kreaproducts->enabled && $conf->dolizsynch->enabled',
			'perms' => '1',
			'target' => '',
			'user' => 0,
		);

		/*MENU SCHEDULED PRICE UPDATES*/
		$this->menu[$r++] = array(
			'fk_menu' => 'fk_mainmenu=products,fk_leftmenu=product',
			'type' => 'left',
			'titre' => 'PriceScheduleMenu',
			'mainmenu' => 'products',
			'leftmenu' => 'dolizsynch_price_schedule',
			'url' => '/custom/kreaflow/price_schedule.php',
			'langs' => 'dolizsynch@kreaflow',
			'position' => 1213,
			'enabled' => '$conf->dolizsynch->enabled',
			'perms' => '$user->rights->produit->lire',
			'target' => '',
			'user' => 0,
		);

		/*MENU ZSBMS INVOICE CREATOR IN BILLING*/
		$this->menu[$r++] = array(
			'fk_menu' => 'fk_mainmenu=billing',
			'type' => 'left',
			'titre' => 'Criar Fatura ZSBMS',
			'prefix' => img_picto('', 'zsbms_32@kreaflow', 'class="pictofixedwidth paddingright"'),
			'mainmenu' => 'billing',
			'leftmenu' => 'dolizsynch_zsbms_invoice_creator',
			'url' => '/kreaflow/zsbms_invoice_creator.php',
			'langs' => 'dolizsynch@kreaflow',
			'position' => 101,
			'enabled' => '$conf->dolizsynch->enabled',
			'perms' => '$user->hasRight(\'dolizsynch\', \'zsbmsinvoicecreator\', \'read\')',
			'target' => '',
			'user' => 0
		);


		/* END MODULEBUILDER TOPMENU */
		/* BEGIN MODULEBUILDER LEFTMENU MYOBJECT */

		$r = 1;
		/* BEGIN MODULEBUILDER IMPORT MYOBJECT */
		/*
		$langs->load("dolizsynch@kreaflow");
		$this->import_code[$r]=$this->rights_class.'_'.$r;
		$this->import_label[$r]='DZSInvoicesLines';	// Translation key (used only if key ExportDataset_xxx_z not found)
		$this->import_icon[$r]='dzsinvoices@kreaflow';
		$this->import_tables_array[$r] = array('t' => MAIN_DB_PREFIX.'dolizsynch_dzsinvoices', 'extra' => MAIN_DB_PREFIX.'dolizsynch_dzsinvoices_extrafields');
		$this->import_tables_creator_array[$r] = array('t' => 'fk_user_author'); // Fields to store import user id
		$import_sample = array();
		$keyforclass = 'DZSInvoices'; $keyforclassfile='/kreaflow/class/dzsinvoices.class.php'; $keyforelement='dzsinvoices@kreaflow';
		include DOL_DOCUMENT_ROOT.'/core/commonfieldsinimport.inc.php';
		$import_extrafield_sample = array();
		$keyforselect='dzsinvoices'; $keyforaliasextra='extra'; $keyforelement='dzsinvoices@kreaflow';
		include DOL_DOCUMENT_ROOT.'/core/extrafieldsinimport.inc.php';
		$this->import_fieldshidden_array[$r] = array('extra.fk_object' => 'lastrowid-'.MAIN_DB_PREFIX.'dolizsynch_dzsinvoices');
		$this->import_regex_array[$r] = array();
		$this->import_examplevalues_array[$r] = array_merge($import_sample, $import_extrafield_sample);
		$this->import_updatekeys_array[$r] = array('t.ref' => 'Ref');
		$this->import_convertvalue_array[$r] = array(
			't.ref' => array(
				'rule'=>'getrefifauto',
				'class'=>(!getDolGlobalString('DOLIZSYNCH_MYOBJECT_ADDON') ? 'mod_dzsinvoices_standard' : getDolGlobalString('DOLIZSYNCH_MYOBJECT_ADDON')),
				'path'=>"/core/modules/commande/".(!getDolGlobalString('DOLIZSYNCH_MYOBJECT_ADDON') ? 'mod_dzsinvoices_standard' : getDolGlobalString('DOLIZSYNCH_MYOBJECT_ADDON')).'.php'
				'classobject'=>'DZSInvoices',
				'pathobject'=>'/kreaflow/class/dzsinvoices.class.php',
			),
			't.fk_soc' => array('rule' => 'fetchidfromref', 'file' => '/societe/class/societe.class.php', 'class' => 'Societe', 'method' => 'fetch', 'element' => 'ThirdParty'),
			't.fk_user_valid' => array('rule' => 'fetchidfromref', 'file' => '/user/class/user.class.php', 'class' => 'User', 'method' => 'fetch', 'element' => 'user'),
			't.fk_mode_reglement' => array('rule' => 'fetchidfromcodeorlabel', 'file' => '/compta/paiement/class/cpaiement.class.php', 'class' => 'Cpaiement', 'method' => 'fetch', 'element' => 'cpayment'),
		);
		$this->import_run_sql_after_array[$r] = array();
		$r++; */
		/* END MODULEBUILDER IMPORT MYOBJECT */
	}
	/**
	 *  Function called when module is enabled.
	 *  The init function add constants, boxes, permissions and menus (defined in constructor) into Dolibarr database.
	 *  It also creates data directories
	 *
	 *  @param      string  $options    Options when enabling module ('', 'noboxes')
	 *  @return     int             	1 if OK, 0 if KO
	 */
	public function init($options = '')
	{
		global $conf, $langs, $db, $user;		//$result = $this->_load_tables('/install/mysql/', 'dolizsynch');
		$result = $this->_load_tables('/kreaflow/sql/');
		if ($result < 0) {
			return -1; // Do not activate module if error 'not allowed' returned when loading module SQL queries (the _load_table run sql with run_sql with the error allowed parameter set to 'default')
		}
		$digitalMenuColumn = $this->db->DDLDescTable(MAIN_DB_PREFIX . 'dolizsynch_zsproduct', 'ementadigital');
		if (!$digitalMenuColumn) {
			return -1;
		}
		if (!$this->db->num_rows($digitalMenuColumn)
			&& !$this->db->query('ALTER TABLE ' . MAIN_DB_PREFIX . 'dolizsynch_zsproduct ADD COLUMN ementadigital INTEGER NULL')) {
			return -1;
		}
		if (!$this->ensureShiftEntityColumn()) {
			return -1;
		}
		if (!$this->ensureInterEntityAuditRetention()) {
			return -1;
		}
		require_once dirname(__DIR__, 2).'/class/zsinvoiceproperties.class.php';
		try {
			ZSInvoiceProperties::ensureExtraFields($this->db);
		} catch (Throwable $e) {
			$this->error = $e->getMessage();
			dol_syslog('KreaFlow sale-property extrafield migration failed', LOG_ERR);
			return -1;
		}

		// Set up document directories for multi-entity support
		if (!isset($conf->dolizsynch->dir_output)) {
			$conf->dolizsynch->dir_output = DOL_DATA_ROOT . '/dolizsynch';
		}
		if (!isset($conf->dolizsynch->multidir_output)) {
			$conf->dolizsynch->multidir_output = array();
		}
		if (!isset($conf->dolizsynch->multidir_output[$conf->entity])) {
			$conf->dolizsynch->multidir_output[$conf->entity] = DOL_DATA_ROOT . (($conf->entity > 1) ? '/' . $conf->entity : '') . '/dolizsynch';
		}

		// Legacy all-version migrations are intentionally not replayed here.
		// Activation must remain repeatable and must not re-run destructive schema history.

		// Default colors for shift planner
		if (empty($conf->global->DOLIZSYNCH_DAYOFF_COLOR)) {
			dolibarr_set_const($db, 'DOLIZSYNCH_DAYOFF_COLOR', '#bababa', 'chaine', 0, '', $conf->entity);
		}
		if (empty($conf->global->DOLIZSYNCH_LEAVE_COLOR)) {
			dolibarr_set_const($db, 'DOLIZSYNCH_LEAVE_COLOR', '#bababa', 'chaine', 0, '', $conf->entity);
		}
		if (empty($conf->global->DOLIZSYNCH_EMPLOYMENT_COLOR)) {
			dolibarr_set_const($db, 'DOLIZSYNCH_EMPLOYMENT_COLOR', '#bababa', 'chaine', 0, '', $conf->entity);
		}
		if (empty($conf->global->DOLIZSYNCH_MISSED_COLOR)) {
			dolibarr_set_const($db, 'DOLIZSYNCH_MISSED_COLOR', '#f8d7da', 'chaine', 0, '', $conf->entity);
		}
		if (empty($conf->global->DOLIZSYNCH_MISSED_FONT_COLOR)) {
			dolibarr_set_const($db, 'DOLIZSYNCH_MISSED_FONT_COLOR', '#7f1d1d', 'chaine', 0, '', $conf->entity);
		}
		if (empty($conf->global->ZS_DEFAULT_BUTTON_BACKGROUND_COLOR)) {
			dolibarr_set_const($db, 'ZS_DEFAULT_BUTTON_BACKGROUND_COLOR', '#696969', 'chaine', 0, '', $conf->entity);
		}
		if (empty($conf->global->ZS_DEFAULT_BUTTON_FONT_COLOR)) {
			dolibarr_set_const($db, 'ZS_DEFAULT_BUTTON_FONT_COLOR', '#ffffff', 'chaine', 0, '', $conf->entity);
		}
		if (empty($conf->global->DOLIZSYNCH_EXCLUDED_ZSBMS_DOCUMENT_TYPES)) {
			dolibarr_set_const($db, 'DOLIZSYNCH_EXCLUDED_ZSBMS_DOCUMENT_TYPES', 'EC', 'chaine', 0, '', $conf->entity);
		}
		if (!isset($conf->global->DOLIZSYNCH_PRODUCT_PRICE_SYNC_ENABLED)) {
			dolibarr_set_const($db, 'DOLIZSYNCH_PRODUCT_PRICE_SYNC_ENABLED', '0', 'chaine', 0, '', $conf->entity);
			$conf->global->DOLIZSYNCH_PRODUCT_PRICE_SYNC_ENABLED = '0';
		}
		if (empty($conf->global->DOLIZSYNCH_PRODUCT_PRICE_SYNC_START_TIME)) {
			dolibarr_set_const($db, 'DOLIZSYNCH_PRODUCT_PRICE_SYNC_START_TIME', '04:00', 'chaine', 0, '', $conf->entity);
			$conf->global->DOLIZSYNCH_PRODUCT_PRICE_SYNC_START_TIME = '04:00';
		}
		if (!isset($conf->global->DOLIZSYNCH_PRODUCT_PRICE_SYNC_REPEAT_INTERVAL)) {
			dolibarr_set_const($db, 'DOLIZSYNCH_PRODUCT_PRICE_SYNC_REPEAT_INTERVAL', '00:00', 'chaine', 0, '', $conf->entity);
			$conf->global->DOLIZSYNCH_PRODUCT_PRICE_SYNC_REPEAT_INTERVAL = '00:00';
		}
		if (empty($conf->global->FULLCALENDAR_SCHEDULER_KEY)) {
			dolibarr_set_const($db, 'FULLCALENDAR_SCHEDULER_KEY', 'GPL-My-Project-Is-Open-Source', 'chaine', 0, '', $conf->entity);
		}

		// Create extrafields during init
		//include_once DOL_DOCUMENT_ROOT.'/core/class/extrafields.class.php';
		//$extrafields = new ExtraFields($this->db);
		//$result1=$extrafields->addExtraField('dolizsynch_myattr1', "New Attr 1 label", 'boolean', 1,  3, 'thirdparty',   0, 0, '', '', 1, '', 0, 0, '', '', 'dolizsynch@kreaflow', 'isModEnabled("dolizsynch")');
		//$result2=$extrafields->addExtraField('dolizsynch_myattr2', "New Attr 2 label", 'varchar', 1, 10, 'project',      0, 0, '', '', 1, '', 0, 0, '', '', 'dolizsynch@kreaflow', 'isModEnabled("dolizsynch")');
		//$result3=$extrafields->addExtraField('dolizsynch_myattr3', "New Attr 3 label", 'varchar', 1, 10, 'bank_account', 0, 0, '', '', 1, '', 0, 0, '', '', 'dolizsynch@kreaflow', 'isModEnabled("dolizsynch")');
		//$result4=$extrafields->addExtraField('dolizsynch_myattr4', "New Attr 4 label", 'select',  1,  3, 'thirdparty',   0, 1, '', array('options'=>array('code1'=>'Val1','code2'=>'Val2','code3'=>'Val3')), 1,'', 0, 0, '', '', 'dolizsynch@kreaflow', 'isModEnabled("dolizsynch")');
		//$result5=$extrafields->addExtraField('dolizsynch_myattr5', "New Attr 5 label", 'text',    1, 10, 'user',         0, 0, '', '', 1, '', 0, 0, '', '', 'dolizsynch@kreaflow', 'isModEnabled("dolizsynch")');		// Permissions
		$this->remove($options);
		$sql = array();		// Document templates
		$moduledir = dol_sanitizeFileName('dolizsynch');
		$myTmpObjects = array();
		$myTmpObjects['DZSInvoices'] = array('includerefgeneration' => 0, 'includedocgeneration' => 0);
		foreach ($myTmpObjects as $myTmpObjectKey => $myTmpObjectArray) {
			if ($myTmpObjectKey == 'DZSInvoices') {
				continue;
			}
			if ($myTmpObjectArray['includerefgeneration']) {
				$src = DOL_DOCUMENT_ROOT . '/install/doctemplates/' . $moduledir . '/template_dzsinvoicess.odt';
				$dirodt = DOL_DATA_ROOT . '/doctemplates/' . $moduledir;
				$dest = $dirodt . '/template_dzsinvoicess.odt';
				if (file_exists($src) && !file_exists($dest)) {
					require_once DOL_DOCUMENT_ROOT . '/core/lib/files.lib.php';
					dol_mkdir($dirodt);
					$result = dol_copy($src, $dest, 0, 0);
					if ($result < 0) {
						$langs->load("errors");
						$this->error = $langs->trans('ErrorFailToCopyFile', $src, $dest);
						return 0;
					}
				}
				$sql = array_merge($sql, array(
					"DELETE FROM " . MAIN_DB_PREFIX . "document_model WHERE nom = 'standard_" . strtolower($myTmpObjectKey) . "' AND type = '" . $this->db->escape(strtolower($myTmpObjectKey)) . "' AND entity = " . ((int) $conf->entity),
					"INSERT INTO " . MAIN_DB_PREFIX . "document_model (nom, type, entity) VALUES('standard_" . strtolower($myTmpObjectKey) . "', '" . $this->db->escape(strtolower($myTmpObjectKey)) . "', " . ((int) $conf->entity) . ")",
					"DELETE FROM " . MAIN_DB_PREFIX . "document_model WHERE nom = 'generic_" . strtolower($myTmpObjectKey) . "_odt' AND type = '" . $this->db->escape(strtolower($myTmpObjectKey)) . "' AND entity = " . ((int) $conf->entity),
					"INSERT INTO " . MAIN_DB_PREFIX . "document_model (nom, type, entity) VALUES('generic_" . strtolower($myTmpObjectKey) . "_odt', '" . $this->db->escape(strtolower($myTmpObjectKey)) . "', " . ((int) $conf->entity) . ")"
				));
			}
		}

		$extrafields = new ExtraFields($db);
		$res = $extrafields->addExtraField(
			'invoice_number',       // Code (identifier)
			'Invoice Number',      // Label
			'varchar',             // Type
			100,                   // Position
			'50',                  // Size/length definition of attribute
			'facture',             // Element type
			0,                     // Is field unique or not
			0,                     // Is field required or not
			'',                    // Default value (In database)
			'',                    // Params for field
			0,                     // Is attribute always editable regardless of the document status
			'',                    // Permission to check
			'',                    // Visibility
			'',                    // Text with help tooltip
			'',                    // Computed value
			'',                    // Entity of extrafields (for multicompany modules)
			'',                    // Language file
			'1',                   // Condition to have the field enabled or not
			0,                     // Is a measure. Must show a total on lists
			0,                     // Is extrafield displayed on PDF
			array()                // More parameters
		);
		if ($res < 0 && stripos((string) $extrafields->error, 'already exists') === false) {
			dol_syslog("Error adding extrafield: " . $extrafields->error, LOG_ERR);
			return -1;
		}

		$extrafields = new ExtraFields($db);
		$res = $extrafields->addExtraField('zs_synch', 'ZSSynch', 'boolean', 1, '', 'product', 0, 0, '0', '', 1, '', '1', '', '', '0', '', '1', 0, 0, array('view' => 1, 'list' => 1, 'edit' => 1, 'create' => 1, 'pdf' => 0));
		if ($res < 0 && stripos((string) $extrafields->error, 'already exists') === false) {
			dol_syslog("Error adding extrafield: " . $extrafields->error, LOG_ERR);
			return -1;
		}

		$extrafields = new ExtraFields($db);
		$res = $extrafields->addExtraField('zs_user_hide', 'ZSUserHide', 'boolean', 1003, '', 'user', 0, 0, '0', '', 1, '', '3', '', '', '', '', '1', 0, 0, array());
		if ($res < 0 && stripos((string) $extrafields->error, 'already exists') === false) {
			dol_syslog("Error adding extrafield: " . $extrafields->error, LOG_ERR);
			return -1;
		}

		$extrafields = new ExtraFields($db);
		$res = $extrafields->addExtraField('zs_main_task', 'ZSMainTask', 'sellist', 1004, '', 'user', 0, 0, '', array('options' => array('dolizsynch_shift_task:label:rowid' => null)), 0, '', '3', '', '', '', '', '1', 0, 0, array());
		if ($res < 0 && stripos((string) $extrafields->error, 'already exists') === false) {
			dol_syslog("Error adding extrafield zs_main_task: " . $extrafields->error, LOG_ERR);
			return -1;
		}

		$extrafields = new ExtraFields($db);
		$res = $extrafields->addExtraField('vendor_employee', 'Vendor Employee', 'sellist', 100, 255, 'facture', 0, 0, '', array('options' => array("user:CONCAT(firstname, ' ',lastname):accountancy_code" => null)), 0, '', 1, '', '', '', '', 1, 0, 0, array());
		if ($res < 0 && stripos((string) $extrafields->error, 'already exists') === false) {
			dol_syslog("Error adding extrafield: " . $extrafields->error, LOG_ERR);
			return -1;
		}

		$extrafields = new ExtraFields($db);
		$res = $extrafields->addExtraField('zs_prod_vendor_employee', 'Vendor Employee', 'varchar', 100, 255, 'facturedet', 0, 0, '', '', 0, '', '', '', '', '', '', 1, 0, 0, array());
		if ($res < 0 && stripos((string) $extrafields->error, 'already exists') === false) {
			dol_syslog("Error adding extrafield: " . $extrafields->error, LOG_ERR);
			return -1;
		}

		$extrafields = new ExtraFields($db);
		$res = $extrafields->addExtraField('zs_posto', 'Posto', 'int', 101, 10, 'facturedet', 0, 0, '', '', 0, '', 0, '', '', '', '', 1, 0, 0, '');
		if ($res < 0 && stripos((string) $extrafields->error, 'already exists') === false) {
			dol_syslog("Error adding extrafield: " . $extrafields->error, LOG_ERR);
			return -1;
		}

		$extrafields = new ExtraFields($db);
		$res = $extrafields->addExtraField('zs_origem', 'Origem', 'int', 101, 10, 'facturedet', 0, 0, '', '', 0, '', 0, '', '', '', '', 1, 0, 0, '');
		if ($res < 0 && stripos((string) $extrafields->error, 'already exists') === false) {
			dol_syslog("Error adding extrafield: " . $extrafields->error, LOG_ERR);
			return -1;
		}

		$extrafields = new ExtraFields($db);
		$res = $extrafields->addExtraField('invoice_date', 'Invoice Date', 'date', 100, 0, 'facture', 0, 0, '', '', 0, '', '1', '', '', '', '', '1', 0, 0, array());
		if ($res < 0 && stripos((string) $extrafields->error, 'already exists') === false) {
			dol_syslog("Error adding extrafield: " . $extrafields->error, LOG_ERR);
			return -1;
		}

		$extrafields = new ExtraFields($db);
		$res = $extrafields->addExtraField('invoice_date_time', 'Invoice Date Time', 'datetime', 100, 0, 'facture', 0, 0, '', '', 0, '', '1', '', '', '', '', '1', 0, 0, array());
		if ($res < 0 && stripos((string) $extrafields->error, 'already exists') === false) {
			dol_syslog("Error adding extrafield: " . $extrafields->error, LOG_ERR);
			return -1;
		}

		$extrafields = new ExtraFields($db);
		$res = $extrafields->addExtraField('zs_employee_id', 'ZSEmployeeId', 'int', 1002, '12', 'user', 1, 0, '', '', 1, '', '3', '', '', '', '', '1', 0, 0, array());
		if ($res < 0 && stripos((string) $extrafields->error, 'already exists') === false) {
			dol_syslog("Error adding extrafield: " . $extrafields->error, LOG_ERR);
			return -1;
		}

		$extrafields = new ExtraFields($db);
		$res = $extrafields->addExtraField('created_without_zs', 'CreatedWithoutZoneSoft', 'boolean', 100, '', 'facture', 0, 0, '0', '', 0, '', '0', '', '', '', 'dolizsynch@kreaflow', '1', 0, 0, array());
		if ($res < 0 && stripos((string) $extrafields->error, 'already exists') === false) {
			dol_syslog("Error adding extrafield: " . $extrafields->error, LOG_ERR);
			return -1;
		}
		// Keep this internal flag hidden from invoice UI if field already existed.
		$res = $extrafields->updateExtraField('created_without_zs', 'CreatedWithoutZoneSoft', 'boolean', 100, '', 'facture', 0, 0, '0', '', 0, '', '0', '', '', '', 'dolizsynch@kreaflow', '1', 0, 0, array());
		if ($res < 0 && stripos((string) $extrafields->error, 'not found') === false) {
			dol_syslog("Error updating extrafield created_without_zs visibility: " . $extrafields->error, LOG_WARNING);
		}

		$extrafields = new ExtraFields($db);
		$res = $extrafields->addExtraField('zs_leave_start_datetime', 'ZS Leave Start Datetime', 'datetime', 200, 0, 'holiday', 0, 0, '', '', 0, '', '1', '', '', '', '', '1', 0, 0, array());
		if ($res < 0 && stripos((string) $extrafields->error, 'already exists') === false) {
			dol_syslog("Error adding extrafield zs_leave_start_datetime: " . $extrafields->error, LOG_ERR);
			return -1;
		}

		$extrafields = new ExtraFields($db);
		$res = $extrafields->addExtraField('zs_leave_end_datetime', 'ZS Leave End Datetime', 'datetime', 201, 0, 'holiday', 0, 0, '', '', 0, '', '1', '', '', '', '', '1', 0, 0, array());
		if ($res < 0 && stripos((string) $extrafields->error, 'already exists') === false) {
			dol_syslog("Error adding extrafield zs_leave_end_datetime: " . $extrafields->error, LOG_ERR);
			return -1;
		}

		$extrafields = new ExtraFields($db);
		$res = $extrafields->addExtraField('zs_leave_total_minutes', 'ZS Leave Total Minutes', 'int', 202, 10, 'holiday', 0, 0, '', '', 0, '', '1', '', '', '', '', '1', 0, 0, array());
		if ($res < 0 && stripos((string) $extrafields->error, 'already exists') === false) {
			dol_syslog("Error adding extrafield zs_leave_total_minutes: " . $extrafields->error, LOG_ERR);
			return -1;
		}

		$resInit = $this->_init($sql, $options);

		if ($resInit > 0) {
			require_once DOL_DOCUMENT_ROOT . '/custom/kreaflow/class/zsproductpricecron.class.php';
			$langs->load('dolizsynch@kreaflow');
			try {
				$priceStartTime = ZSProductPriceCron::normalizeTimeValue((string) $conf->global->DOLIZSYNCH_PRODUCT_PRICE_SYNC_START_TIME);
				$priceRepeatInterval = ZSProductPriceCron::normalizeTimeValue((string) $conf->global->DOLIZSYNCH_PRODUCT_PRICE_SYNC_REPEAT_INTERVAL);
			} catch (InvalidArgumentException $e) {
				$priceStartTime = '04:00';
				$priceRepeatInterval = '00:00';
				dolibarr_set_const($db, 'DOLIZSYNCH_PRODUCT_PRICE_SYNC_START_TIME', $priceStartTime, 'chaine', 0, '', $conf->entity);
				dolibarr_set_const($db, 'DOLIZSYNCH_PRODUCT_PRICE_SYNC_REPEAT_INTERVAL', $priceRepeatInterval, 'chaine', 0, '', $conf->entity);
				dol_syslog(__METHOD__ . ': Invalid stored product price schedule replaced with safe defaults', LOG_WARNING);
			}
			$priceScheduler = new ZSProductPriceCron($db);
			if (!$priceScheduler->synchronizeCronJobs(
				(int) $conf->entity,
				!empty($conf->global->DOLIZSYNCH_PRODUCT_PRICE_SYNC_ENABLED),
				$priceStartTime,
				$priceRepeatInterval,
				!empty($user->id) ? (int) $user->id : 0,
				$langs->trans('CronJobTitleProductPriceSynchronization'),
				$langs->trans('CronJobCommentProductPriceSynchronization'),
				$langs->trans('CronJobTitleProductPriceSynchronizationRecovery'),
				$langs->trans('CronJobCommentProductPriceSynchronizationRecovery')
			)) {
				$this->error = $priceScheduler->error;
				return -1;
			}
		}

		// Remove obsolete root-billing menu entry for the manual invoice creator.
		if ($resInit > 0) {
			$this->removeLegacyZsbmsInvoiceCreatorMenu();
		}

		// Remove legacy Degema category list entries now replaced by DoliZSynch page.
		if ($resInit > 0) {
			$this->removeLegacyDegemaProductCategoryMenu();
		}

		// Make module menus global across MultiCompany entities (shared menus).
		if ($resInit > 0 && !empty($conf->multicompany->enabled)) {
			$this->migrateDolizSynchMenusToEntityZero();
		}

		return $resInit;
	}
	/**
	 *  Function called when module is disabled.
	 *  Remove from database constants, boxes and permissions from Dolibarr database.
	 *  Data directories are not deleted
	 *
	 *  @param      string	$options    Options when enabling module ('', 'noboxes')
	 *  @return     int                 1 if OK, 0 if KO
	 */
	public function remove($options = '')
	{
		$sql = array();
		return $this->_remove($sql, $options);
	}

	/**
	 * Remove legacy cascading invoice foreign keys so synchronization audit rows survive invoice deletion.
	 *
	 * @return bool
	 */
	private function ensureInterEntityAuditRetention()
	{
		$table = MAIN_DB_PREFIX . 'dolizsynch_interentity_invoice';
		$constraints = array(
			'fk_dolizsynch_interentity_inv_source',
			'fk_dolizsynch_interentity_inv_dest',
		);

		foreach ($constraints as $constraint) {
			$sql = "SELECT CONSTRAINT_NAME FROM information_schema.REFERENTIAL_CONSTRAINTS";
			$sql .= " WHERE CONSTRAINT_SCHEMA = DATABASE()";
			$sql .= " AND TABLE_NAME = '" . $this->db->escape($table) . "'";
			$sql .= " AND CONSTRAINT_NAME = '" . $this->db->escape($constraint) . "'";
			$resql = $this->db->query($sql);
			if (!$resql) {
				$this->error = $this->db->lasterror();
				return false;
			}
			if ($this->db->num_rows($resql) === 0) {
				continue;
			}

			$sql = 'ALTER TABLE ' . $table . ' DROP FOREIGN KEY ' . $constraint;
			if (!$this->db->query($sql)) {
				$this->error = $this->db->lasterror();
				return false;
			}
		}

		return true;
	}

	private function ensureShiftEntityColumn()
	{
		$db = $this->db;
		$table = MAIN_DB_PREFIX . "dolizsynch_shift";

		$columnSql = "SHOW COLUMNS FROM " . $table . " LIKE 'entity'";
		$resql = $db->query($columnSql);
		if (!$resql) {
			$this->error = $db->lasterror();
			return false;
		}
		$hasColumn = $db->num_rows($resql) > 0;
		$db->free($resql);

		if (!$hasColumn) {
			$alterSql = "ALTER TABLE " . $table . " ADD COLUMN entity INT NOT NULL DEFAULT 1 AFTER fk_task";
			if (!$db->query($alterSql)) {
				$this->error = $db->lasterror();
				return false;
			}
		}

		$indexSql = "SHOW INDEX FROM " . $table . " WHERE Key_name = 'idx_entity'";
		$resql = $db->query($indexSql);
		if (!$resql) {
			$this->error = $db->lasterror();
			return false;
		}
		$hasIndex = $db->num_rows($resql) > 0;
		$db->free($resql);

		if (!$hasIndex) {
			$alterIndexSql = "ALTER TABLE " . $table . " ADD INDEX idx_entity (entity)";
			if (!$db->query($alterIndexSql)) {
				$this->error = $db->lasterror();
				return false;
			}
		}

		return true;
	}

	/**
	 * Convert menus of this module into entity=0 (shared) and remove duplicates across entities.
	 *
	 * Note: Dolibarr loads menus with entity IN (0, $conf->entity). By moving dolizsynch menus to entity 0,
	 * they become visible in all entities.
	 *
	 * @return void
	 */
	private function migrateDolizSynchMenusToEntityZero()
	{
		$db = $this->db;
		$module = empty($this->rights_class) ? strtolower($this->name) : $this->rights_class;
		$table = MAIN_DB_PREFIX . 'menu';

		$sql = "SELECT rowid, menu_handler, fk_menu, position, url, entity, tms";
		$sql .= " FROM " . $table;
		$sql .= " WHERE module = '" . $db->escape($module) . "'";
		$sql .= " AND menu_handler = 'all'";
		$sql .= " ORDER BY rowid ASC";

		$resql = $db->query($sql);
		if (!$resql) {
			dol_syslog(__METHOD__ . " failed to fetch menus: " . $db->lasterror(), LOG_WARNING);
			return;
		}

		$groups = array();
		while ($obj = $db->fetch_object($resql)) {
			$sig = $obj->menu_handler . '|' . $obj->fk_menu . '|' . $obj->position . '|' . $obj->url;
			if (!isset($groups[$sig])) {
				$groups[$sig] = array();
			}
			$groups[$sig][] = $obj;
		}

		$db->begin();

		foreach ($groups as $rows) {
			if (empty($rows)) {
				continue;
			}

			// Keep the most recent row (by tms), fallback to highest rowid.
			$keep = $rows[0];
			foreach ($rows as $row) {
				$keepTms = !empty($keep->tms) ? strtotime($keep->tms) : 0;
				$rowTms = !empty($row->tms) ? strtotime($row->tms) : 0;

				if ($rowTms > $keepTms) {
					$keep = $row;
					continue;
				}
				if ($rowTms === $keepTms && (int) $row->rowid > (int) $keep->rowid) {
					$keep = $row;
				}
			}

			// Promote the kept entry to entity 0.
			if ((int) $keep->entity !== 0) {
				$sqlu = "UPDATE " . $table . " SET entity = 0 WHERE rowid = " . ((int) $keep->rowid);
				if (!$db->query($sqlu)) {
					dol_syslog(__METHOD__ . " failed to update menu rowid=" . ((int) $keep->rowid) . ": " . $db->lasterror(), LOG_WARNING);
					$db->rollback();
					return;
				}
			}

			// Remove duplicates across entities (and potential duplicates already in entity=0).
			foreach ($rows as $row) {
				if ((int) $row->rowid === (int) $keep->rowid) {
					continue;
				}
				$sqld = "DELETE FROM " . $table . " WHERE rowid = " . ((int) $row->rowid);
				if (!$db->query($sqld)) {
					dol_syslog(__METHOD__ . " failed to delete menu rowid=" . ((int) $row->rowid) . ": " . $db->lasterror(), LOG_WARNING);
					$db->rollback();
					return;
				}
			}
		}

		$db->commit();
	}

	/**
	 * Remove the legacy root-level billing menu entry for the ZSBMS invoice creator.
	 *
	 * @return void
	 */
	private function removeLegacyZsbmsInvoiceCreatorMenu()
	{
		$db = $this->db;
		$table = MAIN_DB_PREFIX . 'menu';
		$module = empty($this->rights_class) ? strtolower($this->name) : $this->rights_class;

		$sql = "DELETE FROM " . $table;
		$sql .= " WHERE module = '" . $db->escape($module) . "'";
		$sql .= " AND url LIKE '/kreaflow/zsbms_invoice_creator.php%'";
		$sql .= " AND (";
		$sql .= " (fk_menu = 'fk_mainmenu=billing' AND leftmenu = 'customer_bills')";
		$sql .= " OR fk_menu = 'fk_mainmenu=billing,fk_leftmenu=customer_bills'";
		$sql .= " )";

		if (!$db->query($sql)) {
			dol_syslog(__METHOD__ . " failed to delete legacy ZSBMS invoice creator menu rows: " . $db->lasterror(), LOG_WARNING);
		}
	}

	/**
	 * Remove legacy category-list menu entries replaced by the DoliZSynch products-left entry.
	 *
	 * @return void
	 */
	private function removeLegacyDegemaProductCategoryMenu()
	{
		$db = $this->db;
		$table = MAIN_DB_PREFIX . 'menu';
		$module = empty($this->rights_class) ? strtolower($this->name) : $this->rights_class;

		$sql = "SELECT rowid FROM " . $table;
		$sql .= " WHERE (";
		$sql .= " (module = 'degema'";
		$sql .= " AND (rowid = 164698";
		$sql .= " OR url LIKE '/custom/degema/product_category_list.php%'";
		$sql .= " OR url LIKE '/degema/product_category_list.php%'))";
		$sql .= " OR (module = '" . $db->escape($module) . "'";
		$sql .= " AND fk_menu = 'fk_mainmenu=products'";
		$sql .= " AND url LIKE '/custom/kreaflow/product_category_list.php%')";
		$sql .= " )";
		$sql .= " ORDER BY rowid ASC";

		$resql = $db->query($sql);
		if (!$resql) {
			dol_syslog(__METHOD__ . " failed to fetch legacy Degema menu rows: " . $db->lasterror(), LOG_WARNING);
			return;
		}

		$rows = array();
		while ($obj = $db->fetch_object($resql)) {
			$rows[] = (int) $obj->rowid;
		}
		$db->free($resql);

		if (empty($rows)) {
			return;
		}

		$db->begin();
		foreach ($rows as $rowid) {
			$sqld = "DELETE FROM " . $table . " WHERE rowid = " . ((int) $rowid);
			if (!$db->query($sqld)) {
				dol_syslog(__METHOD__ . " failed to delete legacy Degema menu rowid=" . ((int) $rowid) . ": " . $db->lasterror(), LOG_WARNING);
				$db->rollback();
				return;
			}
		}
		$db->commit();
	}
}

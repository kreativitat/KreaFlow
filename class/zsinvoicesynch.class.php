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
 * \file        class/zsinvoicesynch.class.php
 * \ingroup     dolizsynch
 * \brief       This file is a CRUD class file for ZSInvoiceSynch (Create/Read/Update/Delete)
 */

/**
 * \class       ZSInvoiceSynch
 * \brief       Class to manage invoice synchronization with ZoneSoft
 *
 * This class handles the creation, update, and synchronization of invoices
 * between Dolibarr and ZoneSoft systems. It ensures that the invoices are
 * accurately mirrored in both systems, handling various synchronization states.
 */


require_once DOL_DOCUMENT_ROOT . '/core/class/commonobject.class.php';
require_once DOL_DOCUMENT_ROOT . '/societe/class/societe.class.php';
require_once DOL_DOCUMENT_ROOT . '/core/lib/company.lib.php';
require_once DOL_DOCUMENT_ROOT . '/compta/facture/class/facture.class.php';
require_once DOL_DOCUMENT_ROOT . '/compta/paiement/class/paiement.class.php';
require_once DOL_DOCUMENT_ROOT . '/user/class/user.class.php';
require_once DOL_DOCUMENT_ROOT . '/custom/kreaflow/class/zsapisynch.class.php';
require_once DOL_DOCUMENT_ROOT . '/custom/kreaflow/class/zsprodsynch.class.php';
require_once DOL_DOCUMENT_ROOT . '/custom/kreaflow/class/zsdictionairsynch.class.php';
require_once DOL_DOCUMENT_ROOT . '/product/stock/class/mouvementstock.class.php';
require_once DOL_DOCUMENT_ROOT . '/custom/kreaflow/class/dolizsynchstockreversal.class.php';
require_once DOL_DOCUMENT_ROOT . '/custom/kreaflow/lib/dolizsynch.lib.php';
require_once DOL_DOCUMENT_ROOT . '/custom/kreaflow/class/zssyncstatus.class.php';
require_once DOL_DOCUMENT_ROOT . '/custom/kreaflow/class/zssyncmanager.class.php';
require_once DOL_DOCUMENT_ROOT . '/custom/kreaflow/class/zsinvoicetaxcalculator.class.php';
require_once DOL_DOCUMENT_ROOT . '/custom/kreaflow/class/trait/zslogger.trait.php';
require_once DOL_DOCUMENT_ROOT . '/custom/kreaflow/class/trait/ZSInvoiceCreation.trait.php';
require_once DOL_DOCUMENT_ROOT . '/custom/kreaflow/class/trait/ZSInvoiceFetching.trait.php';
require_once DOL_DOCUMENT_ROOT . '/custom/kreaflow/class/trait/ZSCustomerManagement.trait.php';
require_once DOL_DOCUMENT_ROOT . '/custom/kreaflow/class/trait/ZSInvoiceMapping.trait.php';
require_once DOL_DOCUMENT_ROOT . '/custom/kreaflow/class/trait/ZSInvoiceSynchronization.trait.php';
require_once DOL_DOCUMENT_ROOT . '/custom/kreaflow/class/trait/ZSPaymentHandling.trait.php';
require_once DOL_DOCUMENT_ROOT . '/custom/kreaflow/class/trait/ZSInvoiceExport.trait.php';

/**
 * Class for ZSInvoiceSynch
 */
class ZSInvoiceSynch extends CommonObject
{
	use ZSLogger;
	use ZSInvoiceCreation;
	use ZSInvoiceFetching;
	use ZSCustomerManagement;
	use ZSInvoiceMapping;
	use ZSInvoiceSynchronization;
	use ZSPaymentHandling;
	use ZSInvoiceExport;

	/**
	 * @var string ID of module.
	 */
	public $module = 'dolizsynch';

	/**
	 * @var string ID to identify managed object.
	 */
	public $element = 'zsinvoicesynch';

	/**
	 * @var string Name of table without prefix where object is stored. This is also the key used for extrafields management.
	 */
	public $table_element = 'dolizsynch_zsinvoicesynch';

	/**
	 * @var int  Does this object support multicompany module ?
	 * 0=No test on entity, 1=Test with field entity, 'field@table'=Test with link by field@table
	 */
	public $ismultientitymanaged = 1;

	/**
	 * @var int  Does object support extrafields ? 0=No, 1=Yes
	 */
	public $isextrafieldmanaged = 1;

	/**
	 * @var string String with name of icon for zsinvoicesynch. Must be a 'fa-xxx' fontawesome code (or 'fa-xxx_fa_color_size') or 'zsinvoicesynch@kreaflow' if picto is file 'img/object_zsinvoicesynch.png'.
	 */
	public $picto = 'fa-file';

	/**
	 * @var ZSAPISynch ZoneSoft API integration object
	 */
	public $zoneSoftApi;

	/**
	 * @var ZSSyncManager Synchronization state manager
	 */
	public $syncManager;

	/**
	 *  'type' field format:
	 *  	'integer', 'integer:ObjectClass:PathToClass[:AddCreateButtonOrNot[:Filter[:Sortfield]]]',
	 *  	'select' (list of values are in 'options'),
	 *  	'sellist:TableName:LabelFieldName[:KeyFieldName[:KeyFieldParent[:Filter[:Sortfield]]]]',
	 *  	'chkbxlst:...',
	 *  	'varchar(x)',
	 *  	'text', 'text:none', 'html',
	 *   	'double(24,8)', 'real', 'price',
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

	/**
	 * @deprecated Synchronization status constants - use ZSSyncStatus class instead
	 * These constants are maintained for backward compatibility
	 */
	const SYNCH_STATUS_NOT_SYNCHRONIZED = 0; // @deprecated Use ZSSyncStatus::NOT_SYNCHRONIZED
	const SYNCH_STATUS_OK = 1; // @deprecated Use ZSSyncStatus::OK
	const SYNCH_STATUS_FAILED = 2; // @deprecated Use ZSSyncStatus::FAILED
	const SYNCH_STATUS_CANCELED_NEEDS_CREDIT = 3; // @deprecated Use ZSSyncStatus::CANCELED_NEEDS_CREDIT
	const SYNCH_STATUS_CREDIT_PROCESSED = 4; // @deprecated Use ZSSyncStatus::CREDIT_PROCESSED
	const SYNCH_STATUS_VALIDATION_ERROR = 5; // @deprecated Use ZSSyncStatus::VALIDATION_ERROR
	const SYNCH_STATUS_DO_NOT_SYNCHRONIZE = 6; // @deprecated Use ZSSyncStatus::DO_NOT_SYNCHRONIZE
	const SYNCH_STATUS_MAPPING_ERROR = 7; // @deprecated Use ZSSyncStatus::MAPPING_ERROR
	const SYNCH_STATUS_SAVE_UPDATE_ERROR = 8; // @deprecated Use ZSSyncStatus::SAVE_UPDATE_ERROR
	const SYNCH_STATUS_CREDIT_CREATION_FAILED = 9; // @deprecated Use ZSSyncStatus::CREDIT_CREATION_FAILED
	const SYNCH_STATUS_AUTHENTICATION_FAILED = 21; // @deprecated Use ZSSyncStatus::AUTHENTICATION_FAILED
	const SYNCH_STATUS_DATE_MISMATCH = 22; // @deprecated Use ZSSyncStatus::DATE_MISMATCH
	const SYNCH_STATUS_INVOICE_NOT_FOUND = 23; // @deprecated Use ZSSyncStatus::INVOICE_NOT_FOUND
	const SYNCH_STATUS_RECEIPT_ERROR_SYNCH = 24;
	const SYNCH_STATUS_FUTURE_DEVELOPMENT = 99; // @deprecated Use ZSSyncStatus::FUTURE_DEVELOPMENT

	// BEGIN MODULEBUILDER PROPERTIES
	/**
	 * @var array  Array with all fields and their property. Do not use it as a static var. It may be modified by constructor.
	 */
	public $fields = array(
		'rowid' => array('type' => 'integer', 'label' => 'TechnicalID', 'enabled' => '1', 'position' => 1, 'notnull' => 1, 'visible' => 0, 'noteditable' => '1', 'index' => 1, 'css' => 'left', 'comment' => "Id"),
		'entity' => array('type' => 'integer', 'label' => 'Entity', 'enabled' => '1', 'position' => 10, 'notnull' => 1, 'visible' => 0, 'default' => '1', 'index' => 1,),
		'contribuinte_zs' => array('type' => 'varchar(21)', 'label' => 'ContribuinteZS', 'enabled' => '1', 'position' => 3000, 'notnull' => 0, 'visible' => 0,),
		'countrycode_zs' => array('type' => 'varchar(6)', 'label' => 'CountryCodeZs', 'enabled' => '1', 'position' => 3000, 'notnull' => 0, 'visible' => 0,),
		'doc' => array('type' => 'varchar(6)', 'label' => 'TipoDocumento', 'enabled' => '1', 'position' => 3000, 'notnull' => 0, 'visible' => 0,),
		'doc_num' => array('type' => 'integer', 'label' => 'NumeroDocumento', 'enabled' => '1', 'position' => 3000, 'notnull' => 0, 'visible' => 0,),
		'doc_serie' => array('type' => 'varchar(12)', 'label' => 'SerieDocumento', 'enabled' => '1', 'position' => 3000, 'notnull' => 0, 'visible' => 0,),
		'data_zs' => array('type' => 'date', 'label' => 'DataZS', 'enabled' => '1', 'position' => 3000, 'notnull' => 0, 'visible' => 1,),
		'datahora_zs' => array('type' => 'datetime', 'label' => 'DataHoraZS', 'enabled' => '1', 'position' => 3000, 'notnull' => 0, 'visible' => 1,),
		'liquido_zs' => array('type' => 'price', 'label' => 'ValorLiquido', 'enabled' => '1', 'position' => 3000, 'notnull' => 0, 'visible' => 0,),
		'total_zs' => array('type' => 'price', 'label' => 'ValorTotalCIVA', 'enabled' => '1', 'position' => 3000, 'notnull' => 0, 'visible' => 0,),
		'pagamento_zs' => array('type' => 'integer', 'label' => 'TipoPagamento', 'enabled' => '1', 'position' => 3000, 'notnull' => 0, 'visible' => 0,),
		'pago_zs' => array('type' => 'integer', 'label' => 'PagamentoEfectuado', 'enabled' => '1', 'position' => 3000, 'notnull' => 0, 'visible' => 0,),
		'anulado_zs' => array('type' => 'integer', 'label' => 'AnuladoZS', 'enabled' => '1', 'position' => 3000, 'notnull' => 0, 'visible' => 0,),
		'loja_zs' => array('type' => 'integer', 'label' => 'LojaZS', 'enabled' => '1', 'position' => 3000, 'notnull' => 0, 'visible' => 0,),
		'tipo_zs' => array('type' => 'integer', 'label' => 'TipoZS', 'enabled' => '1', 'position' => 3000, 'notnull' => 0, 'visible' => 0,),
		'zona_zs' => array('type' => 'integer', 'label' => 'Zona_ZS', 'enabled' => '1', 'position' => 3000, 'notnull' => 0, 'visible' => 0,),
		'mesa_zs' => array('type' => 'integer', 'label' => 'MesaZS', 'enabled' => '1', 'position' => 3000, 'notnull' => 0, 'visible' => 0,),
		'emp_zs' => array('type' => 'integer', 'label' => 'EmpregadoZS', 'enabled' => '1', 'position' => 3000, 'notnull' => 0, 'visible' => 0,),
		'pessoas_zs' => array('type' => 'integer', 'label' => 'PessoasZS', 'enabled' => '1', 'position' => 3000, 'notnull' => 0, 'visible' => 0, 'default' => '1',),
		'fk_user_creat' => array('type' => 'integer:User:user/class/user.class.php', 'label' => 'UserAuthor', 'picto' => 'user', 'enabled' => '1', 'position' => 510, 'notnull' => 1, 'visible' => -2, 'foreignkey' => 'user.rowid', 'csslist' => 'tdoverflowmax150',),
		'fk_user_modif' => array('type' => 'integer:User:user/class/user.class.php', 'label' => 'UserModif', 'picto' => 'user', 'enabled' => '1', 'position' => 511, 'notnull' => -1, 'visible' => -2, 'csslist' => 'tdoverflowmax150',),
		'fk_soc' => array('type' => 'integer:Societe:societe/class/societe.class.php:1:((status:=:1) AND (entity:IN:__SHARED_ENTITIES__))', 'label' => 'ThirdParty', 'picto' => 'company', 'enabled' => 'isModEnabled("societe")', 'position' => 50, 'notnull' => -1, 'visible' => 1, 'index' => 1, 'css' => 'maxwidth500 widthcentpercentminusxx', 'csslist' => 'tdoverflowmax150', 'help' => "OrganizationEventLinkToThirdParty", 'validate' => '1',),
		'date_creation' => array('type' => 'datetime', 'label' => 'DateCreation', 'enabled' => '1', 'position' => 500, 'notnull' => 1, 'visible' => -2,),
		'tms' => array('type' => 'timestamp', 'label' => 'DateModification', 'enabled' => '1', 'position' => 501, 'notnull' => 0, 'visible' => -2,),
		'last_main_doc' => array('type' => 'varchar(255)', 'label' => 'LastMainDoc', 'enabled' => '1', 'position' => 600, 'notnull' => 0, 'visible' => 0,),
		'fk_facture' => array('type' => 'integer', 'label' => 'FaturaDolibarr', 'enabled' => '1', 'position' => 3000, 'notnull' => 0, 'visible' => 0,),
		'invoice_ref' => array('type' => 'varchar(33)', 'label' => 'InvoiceReference', 'enabled' => '1', 'position' => 3000, 'notnull' => 0, 'visible' => 0,),
		'credit_ref' => array('type' => 'integer', 'label' => 'CreditoDolibarr', 'enabled' => '1', 'position' => 3000, 'notnull' => 0, 'visible' => 0,),
		'synch' => array('type' => 'integer', 'label' => 'Synchronized', 'enabled' => '1', 'position' => 3000, 'notnull' => 1, 'visible' => 0,),
	);
	public $rowid;
	public $entity;
	public $contribuinte_zs;
	public $countrycode_zs;
	public $doc;
	public $doc_num;
	public $doc_serie;
	public $data_zs;
	public $datahora_zs;
	public $liquido_zs;
	public $total_zs;
	public $pagamento_zs;
	public $pago_zs;
	public $anulado_zs;
	public $loja_zs;
	public $tipo_zs;
	public $zona_zs;
	public $mesa_zs;
	public $emp_zs;
	public $pessoas_zs;
	public $fk_user_creat;
	public $fk_user_modif;
	public $fk_soc;
	public $date_creation;
	public $tms;
	public $last_main_doc;
	public $fk_facture;
	public $invoice_ref;
	public $credit_ref;
	public $synch;
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
		$this->zoneSoftApi = new ZSAPISynch($db);
		$this->syncManager = new ZSSyncManager($db, 'dolizsynch_zsinvoicesynch', 'invoice_ref');

		if (!getDolGlobalInt('MAIN_SHOW_TECHNICAL_ID') && isset($this->fields['rowid']) && !empty($this->fields['ref'])) {
			$this->fields['rowid']['visible'] = 0;
		}
		if (!isModEnabled('multicompany') && isset($this->fields['entity'])) {
			$this->fields['entity']['enabled'] = 0;
		}

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

	/**
	 * Check whether a ZSBMS document type is excluded from invoice synchronization.
	 *
	 * @param string $docType ZSBMS document type
	 * @return bool
	 */
	public function isExcludedZsbmsDocumentType($docType)
	{
		return dolizsynch_is_excluded_zsbms_document_type($docType);
	}

	/**
	 * Filter out ZSBMS document headers that must not enter invoice synchronization.
	 *
	 * @param array<int,object> $headers Raw ZSBMS document headers
	 * @param string $operation Caller used for logging context
	 * @return array<int,object>
	 */
	private function filterExcludedZsbmsDocumentHeaders(array $headers, $operation = '')
	{
		if (empty($headers)) {
			return $headers;
		}

		$filteredHeaders = array();
		$skippedByType = array();

		foreach ($headers as $header) {
			$docType = dolizsynch_normalize_zsbms_document_type(is_object($header) ? ($header->doc ?? '') : (is_array($header) ? ($header['doc'] ?? '') : ''));
			if ($this->isExcludedZsbmsDocumentType($docType)) {
				if (!isset($skippedByType[$docType])) {
					$skippedByType[$docType] = 0;
				}
				$skippedByType[$docType]++;
				continue;
			}

			$filteredHeaders[] = $header;
		}

		if (!empty($skippedByType)) {
			$this->logDebug(($operation !== '' ? $operation : __METHOD__), "Skipped excluded ZSBMS document headers", [
				'excluded_document_types' => implode(',', dolizsynch_get_excluded_zsbms_document_types()),
				'skipped_by_type' => $skippedByType
			]);
		}

		return $filteredHeaders;
	}

	/**
	 * Build a SQL condition excluding configured ZSBMS document types.
	 *
	 * @param string $fieldName SQL field expression containing the document type
	 * @return string SQL fragment without leading AND, or empty string if nothing is excluded
	 */
	private function buildExcludedZsbmsDocumentTypeSqlCondition($fieldName)
	{
		$excludedDocumentTypes = dolizsynch_get_excluded_zsbms_document_types();
		if (empty($excludedDocumentTypes)) {
			return '';
		}

		$quotedTypes = array();
		foreach ($excludedDocumentTypes as $documentType) {
			$quotedTypes[] = "'" . $this->db->escape($documentType) . "'";
		}

		return "UPPER(TRIM(" . $fieldName . ")) NOT IN (" . implode(', ', $quotedTypes) . ")";
	}

	/**
	 * Mark an excluded synchronization row as intentionally skipped.
	 *
	 * @param object $row Synchronization row
	 * @return bool
	 */
	private function markExcludedZsbmsDocumentAsSkipped($row)
	{
		$docType = dolizsynch_normalize_zsbms_document_type($row->doc ?? '');
		$invoiceRef = (string) ($row->invoice_ref ?? '');
		if ($invoiceRef === '') {
			return false;
		}

		$message = "Document type {$docType} is excluded from invoice synchronization";
		$this->logDebug(__METHOD__, $message, [
			'invoice_ref' => $invoiceRef,
			'doc_type' => $docType,
			'config_key' => 'DOLIZSYNCH_EXCLUDED_ZSBMS_DOCUMENT_TYPES'
		]);

		return $this->setSyncStatus($invoiceRef, ZSSyncStatus::DO_NOT_SYNCHRONIZE, $message);
	}

	/**
	 * Creates or updates a Dolibarr invoice from a mapped ZoneSoft invoice.
	 *
	 * @param array $dolibarrInvoice Mapped invoice data for Dolibarr
	 * @return int Invoice ID on success, false on failure
	 */
	public function createOrUpdateDolibarrInvoice($dolibarrInvoice) //, $user, $zsInvoice)
	{

		global $db, $conf, $user;
		$this->resolveInvoiceSyncActorUser(__METHOD__);

		if (!is_array($dolibarrInvoice)) {
			$this->error = 'Invalid mapped invoice payload';
			return false;
		}

		if ($this->isExcludedZsbmsDocumentType($dolibarrInvoice['doc_type'] ?? '')) {
			$this->error = "Document type " . dolizsynch_normalize_zsbms_document_type($dolibarrInvoice['doc_type'] ?? '') . " is excluded from invoice synchronization";
			$this->logOperationWarning(__METHOD__, $this->error, [
				'ref_client' => (string) ($dolibarrInvoice['ref_client'] ?? ''),
				'config_key' => 'DOLIZSYNCH_EXCLUDED_ZSBMS_DOCUMENT_TYPES'
			]);
			return false;
		}

		$defaultWarehouseId = (int) ($conf->global->MAIN_DEFAULT_WAREHOUSE ?? -1);
		$facture = new Facture($db);
		$dolibarrInvoiceRef = $dolibarrInvoice['array_options']['options_invoice_number'];
		$replacementFactureId = !empty($dolibarrInvoice['_replace_existing_facture_id']) ? (int) $dolibarrInvoice['_replace_existing_facture_id'] : 0;

		try {
			// Determine the invoice type based on the document type
			$parts = explode("_", $dolibarrInvoiceRef); // Split the string by '_'
			$doc = !empty($parts) ? $parts[0] : "";
			$facture->type = $this->getInvoiceType($doc);

			if ($replacementFactureId > 0) {
				if ($facture->fetch($replacementFactureId) <= 0) {
					$this->error = $facture->error ?: 'Failed to fetch existing invoice for replacement';
					$this->handleInvoiceCreationError(
						$dolibarrInvoiceRef,
						new Exception($this->error),
						[
							'stage' => 'replacement_fetch',
							'facture_id' => $replacementFactureId
						],
						'createOrUpdateDolibarrInvoice'
					);
					return false;
				}

				if (!$this->rebuildExistingInvoiceWithValidation($facture, $dolibarrInvoice, $defaultWarehouseId)) {
					return false;
				}

				if (!$this->updateSyncStatusWithValidation($dolibarrInvoiceRef, $facture->id)) {
					return false;
				}

				return $facture->id;
			}

			// Check if invoice already exists (pass date for additional verification)
			$invoiceDate = isset($dolibarrInvoice['date']) ? $dolibarrInvoice['date'] : null;
			if ($fk_facture = $this->isInvoiceExists($dolibarrInvoiceRef, $invoiceDate)) {
				return $this->handleDuplicateInvoice($dolibarrInvoiceRef, $fk_facture, $dolibarrInvoice);
			}

			// Create the invoice with error handling
			if (!$this->createInvoiceWithValidation($facture, $dolibarrInvoice)) {
				return false;
			}

			// Add line items to the invoice with error handling.
			// If lines fail after the invoice header was persisted, delete the orphaned invoice so
			// the next retry starts from a clean state (no partial invoice left in draft).
			if (!$this->addInvoiceLinesWithValidation($facture, $dolibarrInvoice['lines'], $conf->global->ZS_IVA_INCLUIDO)) {
				if (!empty($facture->id)) {
					$deleteResult = $facture->delete($user);
					if ($deleteResult < 0) {
						$this->logOperationError(__METHOD__, "Could not delete orphaned invoice after line-add failure", [
							'invoice_ref' => $dolibarrInvoiceRef,
							'facture_id' => $facture->id,
							'error' => $facture->error
						]);
					} else {
						$this->logDebug(__METHOD__, "Deleted orphaned invoice after line-add failure", [
							'invoice_ref' => $dolibarrInvoiceRef,
							'facture_id' => $facture->id
						]);
					}
				}
				return false;
			}

			if (!$this->reconcileCustomerInvoiceTotals($facture, $dolibarrInvoice)) {
				if (!empty($facture->id)) {
					$facture->delete($user);
				}
				return false;
			}

			// Validate invoice with comprehensive error handling
			if (!$this->validateInvoiceWithMonetaryPlan(
				$facture,
				$user,
				$defaultWarehouseId,
				$dolibarrInvoiceRef,
				$dolibarrInvoice['monetary_plan']
			)) {
				return false;
			}

			// Update synchronization status with error handling
			if (!$this->updateSyncStatusWithValidation($dolibarrInvoiceRef, $facture->id)) {
				return false;
			}

			return $facture->id;
		} catch (Exception $e) {
			$this->handleInvoiceCreationError($dolibarrInvoiceRef, $e, [
				'invoice_data' => $dolibarrInvoice,
				'facture_id' => $facture->id ?? null
			], 'createOrUpdateDolibarrInvoice');
			return false;
		}
	}


	/**
	 * Check if an invoice with the given invoice number already exists.
	 *
	 * @param string $invoiceNumber The invoice number to check.
	 * @param string $invoiceDate Invoice date (YYYY-MM-DD format) for additional verification
	 * @return bool True if the invoice exists, false otherwise.
	 */
	private function isInvoiceExists($invoiceNumber, $invoiceDate = null)
	{
		global $db, $conf;

		// First, cleanup any orphaned extrafield records (from rolled-back transactions)
		$this->cleanupOrphanedExtrafields($invoiceNumber);

		// Check using sync table - most reliable source (with date check if provided)
		$sql = "SELECT fk_facture
            FROM " . MAIN_DB_PREFIX . "dolizsynch_zsinvoicesynch
            WHERE invoice_ref = '" . $db->escape($invoiceNumber) . "'
            AND entity = " . ((int) $conf->entity) . "
            AND fk_facture > 0";

		// If date is provided, also match on date to ensure we get the correct invoice for this date
		if ($invoiceDate) {
			$sql .= " AND data_zs = '" . $db->escape($invoiceDate) . "'";
		}

		$sql .= " ORDER BY rowid DESC LIMIT 1";

		$resql = $db->query($sql);
		if ($resql && $obj = $db->fetch_object($resql)) {
			// Verify the invoice still exists in facture table
			$sql2 = "SELECT rowid FROM " . MAIN_DB_PREFIX . "facture WHERE rowid = " . intval($obj->fk_facture);
			$sql2 .= " AND entity = " . ((int) $conf->entity);
			$resql2 = $db->query($sql2);
			if ($resql2 && $db->num_rows($resql2) > 0) {
				return $obj->fk_facture;
			}
		}

		// Fallback: Check using extrafields (only valid records linked to existing invoices)
		$sql = "SELECT f.rowid
            FROM " . MAIN_DB_PREFIX . "facture AS f
            INNER JOIN " . MAIN_DB_PREFIX . "facture_extrafields AS ef ON f.rowid = ef.fk_object
            WHERE ef.invoice_number = '" . $db->escape($invoiceNumber) . "'
            AND f.entity = " . ((int) $conf->entity) . "
            ORDER BY f.rowid ASC
            LIMIT 1";

		$resql = $db->query($sql);
		if ($resql && $obj = $db->fetch_object($resql)) {
			return $obj->rowid;
		}

		// Last check: ref_client match
		$sql = "SELECT rowid FROM " . MAIN_DB_PREFIX . "facture";
		$sql .= " WHERE ref_client = '" . $db->escape($invoiceNumber) . "'";
		$sql .= " AND entity = " . ((int) $conf->entity);
		$sql .= " LIMIT 1";
		$resql = $db->query($sql);
		if ($resql && $obj = $db->fetch_object($resql)) {
			return $obj->rowid;
		}

		return false;
	}

	/**
	 * Clean up orphaned extrafield records that point to non-existent invoices
	 *
	 * @param string $invoiceNumber The invoice number to check
	 * @return void
	 */
	private function cleanupOrphanedExtrafields($invoiceNumber)
	{
		global $db;

		// Find extrafield records for this invoice_number where the invoice doesn't exist
		$sql = "SELECT ef.rowid, ef.fk_object
            FROM " . MAIN_DB_PREFIX . "facture_extrafields AS ef
            LEFT JOIN " . MAIN_DB_PREFIX . "facture AS f ON ef.fk_object = f.rowid
            WHERE ef.invoice_number = '" . $db->escape($invoiceNumber) . "'
            AND f.rowid IS NULL";

		$resql = $db->query($sql);
		if ($resql) {
			while ($obj = $db->fetch_object($resql)) {
				dol_syslog("Cleaning up orphaned extrafield record: rowid=" . $obj->rowid . " fk_object=" . $obj->fk_object, LOG_WARNING);
				$sqlDelete = "DELETE FROM " . MAIN_DB_PREFIX . "facture_extrafields WHERE rowid = " . intval($obj->rowid);
				$db->query($sqlDelete);
			}
		}
	}



	/**
	 * Process and update Dolibarr invoices based on data from getDayInvoices.
	 *
	 * @param stdClass $dayInvoices Data from getDayInvoices
	 */
	public function processAndUpdateInvoices($dayInvoices)
	{
		foreach ($dayInvoices->document as $zsInvoice) {
			$dolibarrInvoice = $this->mapZSToDolibarrInvoice($zsInvoice);
			if ($dolibarrInvoice === false) {
				continue;
			}
			$this->createOrUpdateDolibarrInvoice($dolibarrInvoice);
		}
	}

	/**
	 * Fetches daily invoices from ZoneSoft.
	 *
	 * @return array|false Array of invoices or false on failure
	 */
	public function getDayInvoices($store = null)
	{

		global $conf;
		// Set the interface for fetching documents (invoices)
		$this->zoneSoftApi->setInterface('documents');

		$configuredStoreId = $this->getConfiguredInvoiceStoreId();
		$store = $store ?: $configuredStoreId;
		$rawStore = trim((string) $store);
		$storeId = ctype_digit($rawStore) ? (int) $rawStore : 0;
		if ($configuredStoreId <= 0 || $storeId !== $configuredStoreId) {
			dol_syslog(__METHOD__ . ': Requested invoice store is outside the active entity scope', LOG_ERR);
			return false;
		}

		// Set the payload for the API call
		$payload = [
			"document" => [
				"order" => $this->getZoneSoftInvoiceHeaderOrder(),
				"limit" => $conf->global->ZS_GET_NUMBER_OF_INVOICES_LIMIT,
				"loja" => $storeId,
			]
		];

		// Fetch the invoices
		$response = json_decode(json_encode($this->zoneSoftApi->executeForStore('getInstances', $payload, (string) $storeId)));
		if ($response && isset($response->Response) && $response->Response->StatusCode == 200) {
			$content = $response->Response->Content;
			if (isset($content->document) && is_array($content->document)) {
				$content->document = $this->filterInvoiceHeadersByStore($content->document, $storeId, __METHOD__);
			}
			return $content;
		} else {
			dol_syslog("Failed to fetch invoices from ZoneSoft", LOG_ERR);
			return false;
		}
	}



	// setSyncStatus method is now in ZSInvoiceSynchronization trait

	/**
	 * Extract and validate data from an input string.
	 *
	 * @param string $inputString The input string to extract and validate.
	 * @return array|string The extracted data as an associative array if valid, or an error message string if invalid.
	 */
	public function extractAndValidateData($inputString)
	{

		// Split the string by the underscore character
		$parts = explode('_', $inputString);

		// Check if the exploded string has the expected 4 parts
		if (count($parts) !== 4) {
			return "Error: The input string does not have the expected format.";
		}

		// Extract parts
		$doc = $parts[0];
		$serie = $parts[1];
		$numero = $parts[2];
		$date = $parts[3];

		// Validate each part
		if (!preg_match('/^[A-Za-z]+$/', $doc)) {
			return "Error: Invalid document type.";
		}
		if (!preg_match('/^[A-Za-z0-9]+$/', $serie)) {
			return "Error: Invalid series format.";
		}
		if (!filter_var($numero, FILTER_VALIDATE_INT)) {
			return "Error: Number is not valid.";
		}
		if (DateTime::createFromFormat('Ymd', $date) === false) {
			return "Error: Invalid date format.";
		}

		$result = [
			'doc' => $doc,
			'serie' => $serie,
			'numero' => $numero,
			'date' => $date
		];

		return $result;
	}











	/**
	 * Fetches invoice from ZoneSoft API.
	 *
	 * @param string $doc Document type (FT, FR, etc.)
	 * @param string $serie Document series
	 * @param int $numero Document number
	 * @param string $data Expected document date (YYYY-MM-DD)
	 * @param int|null $store Store ID
	 * @return array Array with 'data' key on success, or 'error' key with error details on failure
	 */
	public function fetchInvoiceLines($doc, $serie, $numero, $data, $store = null)
	{
		global $conf;

		$operation = __METHOD__;
		$invoiceRef = "{$doc}_{$serie}_{$numero}_{$data}";
		$store = ($store !== null && $store !== '')
			? trim((string) $store)
			: trim((string) ($conf->global->ZS_API_STORE ?? ''));
		$configuredStoreId = $this->getConfiguredInvoiceStoreId();
		$storeId = ctype_digit($store) ? (int) $store : 0;
		if ($configuredStoreId <= 0 || $storeId !== $configuredStoreId) {
			$this->logOperationError($operation, 'Requested invoice detail store is outside the active entity scope', [
				'requested_store' => $store,
				'configured_store' => $configuredStoreId,
				'invoice_ref' => $invoiceRef,
			]);
			return ['error' => 'store_scope_mismatch', 'message' => 'Invoice store is outside the active entity scope'];
		}
		$useStoreScopedRequest = ($store !== '' && method_exists($this->zoneSoftApi, 'executeForStore'));

		$this->logDebug($operation, "Fetching invoice lines from ZoneSoft", [
			'doc' => $doc,
			'serie' => $serie,
			'numero' => $numero,
			'expected_date' => $data,
			'store' => $store,
			'use_store_scoped_request' => $useStoreScopedRequest
		]);

		$this->zoneSoftApi->setInterface('documents');

		$payload = [
			"document" => [
				"doc" => $doc,
				"serie" => $serie,
				"numero" => $numero,
				"data" => $data,  // Include date to get the specific invoice
			]
		];

		try {
			$rawResponse = $useStoreScopedRequest
				? $this->zoneSoftApi->executeForStore('getInstance', $payload, (string) $store)
				: $this->zoneSoftApi->execute('getInstance', $payload);

			// ZSAPIHttpClient returns [] for HTTP 204 No Content.
			if (empty($rawResponse)) {
				$this->logOperationWarning($operation, "Invoice not found (204/no content)", [
					'invoice_ref' => $invoiceRef,
					'status_code' => 204,
					'store' => $store
				]);
				return ['error' => 'invoice_not_found', 'status_code' => 204];
			}

			$response = json_decode(json_encode($rawResponse));

			if ($response && isset($response->Response) && $response->Response->StatusCode == 200) {
				$this->logDebug($operation, "Successfully fetched invoice from ZoneSoft", [
					'invoice_ref' => $invoiceRef,
					'response_status' => $response->Response->StatusCode
				]);

				$document = $response->Response->Content->document ?? null;
				if (!is_object($document)) {
					return ['error' => 'incomplete_source', 'message' => 'ZoneSoft returned no complete source document'];
				}

				// Never trust an API response merely because its date matches.
				// Every fiscal identity field must describe the requested document.
				$expectedNumber = trim((string) $numero);
				$actualNumber = trim((string) ($document->numero ?? ''));
				$identityMatches = $this->isInvoiceHeaderForStore($document, $configuredStoreId)
					&& trim((string) $doc) !== '' && trim((string) $serie) !== ''
					&& trim((string) ($document->doc ?? '')) === trim((string) $doc)
					&& trim((string) ($document->serie ?? '')) === trim((string) $serie)
					&& ctype_digit($expectedNumber) && ctype_digit($actualNumber)
					&& ltrim($expectedNumber, '0') !== ''
					&& ltrim($actualNumber, '0') === ltrim($expectedNumber, '0');
				if (!$identityMatches) {
					$this->logOperationError($operation, 'Source fiscal identity does not match the requested invoice', [
						'invoice_ref' => $invoiceRef,
						'configured_store' => $configuredStoreId,
					]);
					return ['error' => 'source_identity_mismatch', 'message' => 'ZoneSoft source store, document type, series or number does not match the requested invoice'];
				}
				$expectedDate = trim((string) $data);
				$actualDate = trim((string) ($document->data ?? ''));
				if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $expectedDate)
					|| !checkdate((int) substr($expectedDate, 5, 2), (int) substr($expectedDate, 8, 2), (int) substr($expectedDate, 0, 4))
					|| $actualDate !== $expectedDate) {
					return [
						'error' => 'date_mismatch',
						'message' => 'ZoneSoft source fiscal date does not match the requested invoice',
						'expected_date' => $data,
						'actual_date' => $actualDate,
					];
				}
				if ((string) $doc !== 'RC' && (!isset($document->vendas) || !is_array($document->vendas) || count($document->vendas) === 0)) {
					return ['error' => 'incomplete_source', 'message' => 'ZoneSoft source invoice has no complete sales lines; header-only creation is refused'];
				}
				return ['data' => $response->Response->Content];
			} elseif ($response && isset($response->Response)
				&& (int) $response->Response->StatusCode === 401) {
				return ['error' => 'authentication_failed', 'status_code' => 401, 'message' => 'ZoneSoft API authentication failed'];
			} elseif ($response && isset($response->Response)
				&& (int) $response->Response->StatusCode === 204) {
				return ['error' => 'invoice_not_found', 'status_code' => 204, 'message' => 'Invoice not found in ZoneSoft'];
			} else {
				$statusCode = $response && isset($response->Response->StatusCode)
					? $response->Response->StatusCode
					: 'unknown';

				$this->logOperationError($operation, "Failed to fetch invoice", [
					'invoice_ref' => $invoiceRef,
					'status_code' => $statusCode,
					'has_response' => isset($response)
				]);
				return ['error' => 'fetch_failed', 'status_code' => $statusCode];
			}
		} catch (ZSAPIException $e) {
			$this->logOperationError($operation, "ZoneSoft API exception", [
				'invoice_ref' => $invoiceRef,
				'exception_message' => $e->getMessage(),
				'exception_code' => $e->getCode()
			]);
			return [
				'error' => (int) $e->getCode() === 401 ? 'authentication_failed' : 'api_exception',
				'status_code' => (int) $e->getCode(),
				'message' => $e->getMessage(),
			];
		} catch (Exception $e) {
			$this->logOperationError($operation, "Unexpected exception", [
				'invoice_ref' => $invoiceRef,
				'exception_class' => get_class($e),
				'exception_message' => $e->getMessage(),
				'exception_code' => $e->getCode()
			]);
			return ['error' => 'unexpected_exception', 'message' => $e->getMessage()];
		}
	}

	/**
	 * Check whether user can create/validate customer invoices.
	 *
	 * @param User|null $actor
	 * @return bool
	 */
	private function canUserValidateInvoices($actor)
	{
		if (!is_object($actor) || !method_exists($actor, 'hasRight')) {
			return false;
		}

		if (method_exists($actor, 'loadRights')) {
			$actor->loadRights('', 0);
		}

		if (getDolGlobalInt('MAIN_USE_ADVANCED_PERMS')) {
			return $actor->hasRight('facture', 'invoice_advance', 'validate');
		}

		return $actor->hasRight('facture', 'creer');
	}

	/**
	 * Resolve an actor user suitable for invoice validation.
	 *
	 * When sync is executed manually or from CLI, global $user may be id=0
	 * or may not have invoice validation rights. In such cases we fallback to
	 * a configured actor (if provided) or admin user #1.
	 *
	 * @param string $operation
	 * @return User|null
	 */
	private function resolveInvoiceSyncActorUser($operation)
	{
		global $user, $db, $conf;

		if ($this->canUserValidateInvoices($user)) {
			return $user;
		}

		$currentUserId = (is_object($user) && !empty($user->id)) ? (int) $user->id : 0;
		$fallbackIds = array();
		$configuredActorId = !empty($conf->global->ZS_INVOICE_SYNC_ACTOR_USER_ID) ? (int) $conf->global->ZS_INVOICE_SYNC_ACTOR_USER_ID : 0;
		if ($configuredActorId > 0) {
			$fallbackIds[] = $configuredActorId;
		}
		$fallbackIds[] = 1;

		$sqlAdmins = "SELECT rowid FROM " . MAIN_DB_PREFIX . "user";
		$sqlAdmins .= " WHERE admin = 1";
		$sqlAdmins .= " AND statut = 1";
		$sqlAdmins .= " AND (entity = 0 OR entity = " . ((int) $conf->entity) . ")";
		$sqlAdmins .= " ORDER BY rowid ASC";
		$resqlAdmins = $db->query($sqlAdmins);
		if ($resqlAdmins) {
			while ($objAdmin = $db->fetch_object($resqlAdmins)) {
				$fallbackIds[] = (int) $objAdmin->rowid;
			}
			$db->free($resqlAdmins);
		}

		$fallbackIds = array_values(array_unique(array_filter($fallbackIds)));

		foreach ($fallbackIds as $fallbackId) {
			$fallbackUser = new User($db);
			if ($fallbackUser->fetch((int) $fallbackId) <= 0) {
				continue;
			}
			if ((int) $fallbackUser->statut !== 1 || !empty($fallbackUser->socid)) {
				continue;
			}

			if (!$this->canUserValidateInvoices($fallbackUser)) {
				continue;
			}

			if ($currentUserId !== (int) $fallbackUser->id) {
				$this->logOperationWarning(
					$operation,
					"Switching to fallback actor for invoice validation",
					[
						'previous_user_id' => $currentUserId,
						'fallback_user_id' => (int) $fallbackUser->id
					]
				);
			}

			$user = $fallbackUser;
			return $fallbackUser;
		}

		$this->logOperationError(
			$operation,
			"No user with invoice validation rights could be resolved",
			['current_user_id' => $currentUserId]
		);

		return is_object($user) ? $user : null;
	}

	/**
	 * Create an invoice without lines.
	 *
	 * This method is designed to handle the creation of an invoice without specific line items.
	 * The main goal is to set the real tax using the product tax rate and apply a simplification
	 * due to the unknown original tax rate that led to the total. Portugal has several levels
	 * of VAT: 0%, 6%, 13%, and 23%. Since the exact rate that resulted in the total amount is
	 * unknown, we use the product tax rate and a 0% tax rate to achieve the correct tax value.
	 *
	 * Calculation of p1 and p2:
	 * p1 + p2 = totalHt
	 * p1 * (1 + taxRate) + p2 = totalTtc
	 *
	 * Symplication:
	 * $p1 = ($totalTtc - $totalHt) / ($taxRate / 100);
	 * $p2 = ($totalHt * ($taxRate / 100) + $totalHt - $totalTtc) / ($taxRate / 100);
	 *
	 * @param object $row
	 * @return int|false
	 */
	private function createInvoiceWithoutLines($row)
	{

		global $user, $db, $conf;

		$facture = new Facture($db);
		// NOTE: Do NOT set $facture->ref before create() - it prevents Dolibarr from using provisional ref
		// The ref will be set after validation in validateInvoiceWithErrorHandling()
		$facture->ref_client = $row->invoice_ref;
		$facture->date = $this->convertZsDateToTimestamp($row->data_zs);
		$facture->socid = $this->customerValidation($row->contribuinte_zs, $row->emp_zs);
		$facture->type = $this->getInvoiceType((string) ($row->doc ?? 'FT'));
		$facture->note_public = "Imported with KreaFlow - Invoice not found in ZoneSoft";
		$this->normalizeFactureCreateHeader($facture, array('ref_client' => $row->invoice_ref));
		$facture->array_options['options_invoice_number'] = $row->invoice_ref;
		$facture->array_options['options_invoice_date_time'] = $this->convertZsDateToTimestamp($row->datahora_zs);
		$facture->array_options['options_invoice_date'] = $this->convertZsDateToTimestamp($row->data_zs);
		$facture->array_options['options_vendor_employee'] = $row->emp_zs;

		// Set datec to match the ZoneSoft local datetime.
		if (!empty($row->datahora_zs)) {
			$facture->date_creation = $this->convertZsDateToTimestamp($row->datahora_zs);
		}

		if ($facture->create($user) < 0) {
			dol_syslog("Error creating invoice without lines: " . $facture->error, LOG_ERR);
			$this->setSyncStatus($row->invoice_ref, ZSSyncStatus::FAILED, $this->error);
			return false;
		}

		// Use generic product for invoices not found in Zone Soft
		$genericProductId = $conf->global->ZS_GENERIC_PRODUCT_ID;
		if (!$genericProductId) {
			dol_syslog("Generic product ID not set in configuration", LOG_ERR);
			$this->setSyncStatus($row->invoice_ref, ZSSyncStatus::FAILED, 'Generic product not configured (ZS_GENERIC_PRODUCT_ID)');
			return false;
		}

		// Fetch the product details
		$product = new Product($db);
		if ($product->fetch($genericProductId) <= 0) {
			dol_syslog("Error fetching generic product details: " . $product->error, LOG_ERR);
			$this->setSyncStatus($row->invoice_ref, ZSSyncStatus::FAILED, 'Failed to fetch generic product');
			return false;
		}

		try {
			$taxRate = ZSDecimal::normalize((string) $product->tva_tx);
			$totalHt = ZSDecimal::normalize((string) $row->liquido_zs);
			$totalTtc = ZSDecimal::normalize((string) $row->total_zs);
		} catch (InvalidArgumentException $e) {
			$this->error = 'Fallback invoice contains an invalid decimal value';
			$this->setSyncStatus($row->invoice_ref, ZSSyncStatus::VALIDATION_ERROR, $this->error);
			return false;
		}
		if (ZSDecimal::compare($taxRate, '0') < 0 || ZSDecimal::compare($taxRate, '100') > 0
			|| ZSDecimal::compare($totalHt, '0') < 0 || ZSDecimal::compare($totalTtc, '0') < 0) {
			$this->error = 'Fallback invoice totals and VAT rate must be positive magnitudes';
			$this->setSyncStatus($row->invoice_ref, ZSSyncStatus::VALIDATION_ERROR, $this->error);
			return false;
		}

		$fallbackDescription = 'Source document unavailable in ZoneSoft';
		$fallbackTaxLines = [];
		$vatAmount = ZSDecimal::subtract($totalTtc, $totalHt);
		if (ZSDecimal::compare($vatAmount, '0') < 0) {
			$this->error = 'Fallback invoice TTC cannot be lower than HT';
			$this->setSyncStatus($row->invoice_ref, ZSSyncStatus::VALIDATION_ERROR, $this->error);
			return false;
		}

		if (ZSDecimal::compare($taxRate, '0') > 0) {
			// Only the header survives for this fallback. Reconstruct explicit taxable
			// and zero-rate bases exactly; neither line is a rounding adjustment.
			$taxableBase = ZSDecimal::round(
				ZSDecimal::divide(ZSDecimal::multiply($vatAmount, '100'), $taxRate),
				2
			);
			$zeroRateBase = ZSDecimal::subtract(ZSDecimal::round($totalHt, 2), $taxableBase);
			if (ZSDecimal::compare($taxableBase, '0') < 0 || ZSDecimal::compare($zeroRateBase, '0') < 0) {
				$this->error = 'Fallback totals cannot be represented by the configured VAT rate and a zero-rate remainder';
				$this->setSyncStatus($row->invoice_ref, ZSSyncStatus::VALIDATION_ERROR, $this->error);
				return false;
			}
			if (ZSDecimal::compare($taxableBase, '0') > 0) {
				$fallbackTaxLines[] = ['quantity' => '1', 'unit_price' => $taxableBase, 'vat_rate' => $taxRate];
				if ($facture->addline($fallbackDescription . ' - reconstructed taxable base', $taxableBase, 1, $taxRate, 0, 0, 0, 0, '', '', 0, 0, 0, 'HT', 0, 1, -1, 0, '', 0, 0, null, '0', '', [], 100, 0, null, '0', '', 1) < 0) {
					$this->error = $facture->error ?: 'Unable to add fallback taxable line';
					$this->setSyncStatus($row->invoice_ref, ZSSyncStatus::SAVE_UPDATE_ERROR, $this->error);
					return false;
				}
			}
			if (ZSDecimal::compare($zeroRateBase, '0') > 0) {
				$fallbackTaxLines[] = ['quantity' => '1', 'unit_price' => $zeroRateBase, 'vat_rate' => '0'];
				if ($facture->addline($fallbackDescription . ' - reconstructed zero-rate base', $zeroRateBase, 1, 0, 0, 0, 0, 0, '', '', 0, 0, 0, 'HT', 0, 1, -1, 0, '', 0, 0, null, '0', '', [], 100, 0, null, '0', '', 1) < 0) {
					$this->error = $facture->error ?: 'Unable to add fallback zero-rate line';
					$this->setSyncStatus($row->invoice_ref, ZSSyncStatus::SAVE_UPDATE_ERROR, $this->error);
					return false;
				}
			}
		} else {
			if (ZSDecimal::compare(ZSDecimal::round($totalHt, 2), ZSDecimal::round($totalTtc, 2)) !== 0) {
				$this->error = 'A zero-VAT fallback product cannot reconstruct different HT and TTC totals';
				$this->setSyncStatus($row->invoice_ref, ZSSyncStatus::VALIDATION_ERROR, $this->error);
				return false;
			}
			$fallbackTaxLines[] = ['quantity' => '1', 'unit_price' => $totalHt, 'vat_rate' => '0'];
			if ($facture->addline($fallbackDescription, $totalHt, 1, 0, 0, 0, 0, 0, '', '', 0, 0, 0, 'HT', 0, 1, -1, 0, '', 0, 0, null, '0', '', [], 100, 0, null, '0', '', 1) < 0) {
				$this->error = $facture->error ?: 'Unable to add fallback invoice line';
				$this->setSyncStatus($row->invoice_ref, ZSSyncStatus::SAVE_UPDATE_ERROR, $this->error);
				return false;
			}
		}

		try {
			$fallbackPlan = ZSInvoiceTaxCalculator::calculate(
				$fallbackTaxLines,
				'0',
				false,
				ZSInvoiceTaxCalculator::ROUND_VAT_GROUP
			);
			ZSInvoiceTaxCalculator::assertSourceTotals($fallbackPlan, $totalHt, null, $totalTtc, [
				'external_id' => (string) $row->invoice_ref,
				'invoice_number' => (string) $row->invoice_ref,
			]);
		} catch (Throwable $e) {
			$this->error = $e->getMessage();
			$this->setSyncStatus($row->invoice_ref, ZSSyncStatus::VALIDATION_ERROR, $this->error);
			return false;
		}
		if (!$this->reconcileCustomerInvoiceTotals($facture, ['monetary_plan' => $fallbackPlan])) {
			$this->setSyncStatus($row->invoice_ref, ZSSyncStatus::VALIDATION_ERROR, $this->error);
			return false;
		}

		// Mark this invoice as created without Zone Soft data
		$facture->array_options['options_created_without_zs'] = 1;
		$facture->insertExtraFields();

		// Validate the invoice with warehouse ID for stock movement
		if (!$this->validateInvoiceWithMonetaryPlan(
			$facture,
			$user,
			(int) $conf->global->MAIN_DEFAULT_WAREHOUSE,
			(string) $row->invoice_ref,
			$fallbackPlan
		)) {
			dol_syslog("Error validating invoice: " . $facture->error, LOG_ERR);
			// Adding more detailed logging for validation error
			dol_syslog("Invoice ID: " . $facture->id, LOG_ERR);
			dol_syslog("User ID: " . $user->id, LOG_ERR);
			dol_syslog("Warehouse ID: " . $conf->global->MAIN_DEFAULT_WAREHOUSE, LOG_ERR);
			dol_syslog("Invoice Status: " . $facture->statut, LOG_ERR);
			dol_syslog("Third Party ID: " . $facture->socid, LOG_ERR);
			// Set synch status to 5 (failed attempt)
			$this->setSynchStatus($row->invoice_ref, self::SYNCH_STATUS_VALIDATION_ERROR);
			return false;
		}

		// Apply payment for paid invoices only (must be after validation).
		if ((int) $row->pago_zs === 1) {
			if ((int) $facture->type === Facture::TYPE_CREDIT_NOTE) {
				$this->error = 'A paid credit note requires operator reconciliation and cannot use a customer payment';
				$this->setSyncStatus($row->invoice_ref, ZSSyncStatus::VALIDATION_ERROR, $this->error);
				return false;
			}
			$paymentInfo = $this->getDolibarrPaymentTypeAndAccount($row->pagamento_zs, $row->loja_zs);
			if (!$paymentInfo) {
				$this->setSyncStatus($row->invoice_ref, ZSSyncStatus::FAILED, 'Payment mapping not found for fallback invoice');
				return false;
			}

			$paymentDate = $this->convertZsDateToTimestamp($row->data_zs);
			if ($paymentDate <= 0) {
				$paymentDate = $this->convertZsDateToTimestamp($row->datahora_zs);
			}
			if ($paymentDate <= 0) {
				$paymentDate = dol_now();
			}

			$paymentApplied = $this->applyPaymentToInvoice(
				(int) $facture->id,
				(int) $paymentInfo['payment_type_id'],
				(int) $paymentInfo['account_id'],
				(float) $row->total_zs,
				(int) $paymentDate
			);
			if (!$paymentApplied) {
				$errorMessage = !empty($this->error) ? $this->error : 'Error applying payment to fallback invoice';
				$this->setSyncStatus($row->invoice_ref, ZSSyncStatus::FAILED, $errorMessage, ['facture_id' => $facture->id]);
				return false;
			}
		}

		// Update the synchronization status in the dolizsynch_zsinvoicesynch table
		$syncUpdated = $this->setSyncStatus(
			$row->invoice_ref,
			ZSSyncStatus::OK,
			null,
			['facture_id' => $facture->id, 'created_without_zs' => true]
		);
		if (!$syncUpdated) {
			dol_syslog("Error updating sync status in Dolibarr", LOG_ERR);
			$this->setSyncStatus($row->invoice_ref, ZSSyncStatus::FAILED, $this->error);
			return false;
		}

		return $facture->id;
	}


	/**
	 * Apply payment to an existing Dolibarr invoice when Zone Soft marks the row as paid.
	 *
	 * @param object $row Synchronization row
	 * @param int $factureId Dolibarr invoice id
	 * @param int|string|null $store Zone Soft store id
	 * @param array $syncContextOnFailure Context saved if payment fails
	 * @return bool
	 */
	private function applyPaidSyncRowPayment($row, $factureId, $store, array $syncContextOnFailure = [])
	{
		$factureId = (int) $factureId;
		if ((int) $row->pago_zs !== 1) {
			return true;
		}

		$paymentInfo = $this->getDolibarrPaymentTypeAndAccount($row->pagamento_zs, $store);
		if (!$paymentInfo) {
			$this->setSyncStatus(
				$row->invoice_ref,
				ZSSyncStatus::FAILED,
				'Payment mapping not found for paid invoice',
				$syncContextOnFailure
			);
			return false;
		}

		$paymentDate = $this->convertZsDateToTimestamp($row->data_zs);
		if ($paymentDate <= 0) {
			$paymentDate = $this->convertZsDateToTimestamp($row->datahora_zs);
		}
		if ($paymentDate <= 0) {
			$paymentDate = dol_now();
		}

		$paymentApplied = $this->applyPaymentToInvoice(
			$factureId,
			(int) $paymentInfo['payment_type_id'],
			(int) $paymentInfo['account_id'],
			(float) $row->total_zs,
			(int) $paymentDate
		);
		if (!$paymentApplied) {
			$errorMessage = !empty($this->error) ? $this->error : 'Error applying payment to paid invoice';
			$this->setSyncStatus(
				$row->invoice_ref,
				ZSSyncStatus::FAILED,
				$errorMessage,
				$syncContextOnFailure
			);
			return false;
		}

		return true;
	}

	/**
	 * Persist ZoneSoft people count in sync header row.
	 *
	 * If payload contains people data, update with exact value.
	 * If payload omits people data, enforce default value only when DB value is NULL.
	 *
	 * @param string $invoiceRef
	 * @param mixed $documentPayload
	 * @return bool
	 */
	private function persistInvoicePeopleCount($invoiceRef, $documentPayload)
	{
		global $db, $conf;

		$invoiceRef = trim((string) $invoiceRef);
		if ($invoiceRef === '') {
			return false;
		}

		$peopleCount = $this->resolveInvoicePeopleCount($documentPayload);
		$targetPeopleCount = ($peopleCount === null) ? 1 : (int) $peopleCount;

		$sql = "UPDATE " . MAIN_DB_PREFIX . "dolizsynch_zsinvoicesynch";
		$sql .= " SET pessoas_zs = " . $targetPeopleCount . ", date_modification = NOW()";
		$sql .= " WHERE invoice_ref = '" . $db->escape($invoiceRef) . "'";
		$sql .= " AND entity = " . ((int) $conf->entity);
		if ($peopleCount === null) {
			$sql .= " AND pessoas_zs IS NULL";
		} else {
			$sql .= " AND (pessoas_zs IS NULL OR pessoas_zs <> " . $targetPeopleCount . ")";
		}

		if (!$db->query($sql)) {
			$this->logSqlError(__METHOD__, $db, [
				'invoice_ref' => $invoiceRef,
				'entity' => (int) $conf->entity,
				'pessoas_zs' => $targetPeopleCount
			]);
			return false;
		}

		return true;
	}



	/**
	 * Resolve the active entity's optional inclusive fiscal-date boundary.
	 *
	 * @return string|false Empty when unset, ISO date when valid, false on invalid configuration.
	 */
	private function getInvoiceSyncMinimumDate()
	{
		global $conf;

		$date = trim((string) ($conf->global->ZS_INVOICE_SYNC_MIN_DATE ?? ''));
		if ($date === '') {
			return '';
		}
		if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)
			|| !checkdate((int) substr($date, 5, 2), (int) substr($date, 8, 2), (int) substr($date, 0, 4))) {
			$this->error = 'ZS_INVOICE_SYNC_MIN_DATE must be empty or a valid ISO date (YYYY-MM-DD) for the active entity';
			return false;
		}

		return $date;
	}

	/**
	 * Synchronizes the latest unsynchronized invoice for a specified store.
	 *
	 * This method searches for invoices that have not yet been synchronized, or
	 * those that need re-synchronization due to previous failures or incomplete data.
	 * It then fetches invoice details, processes them, and updates the invoice
	 * in Dolibarr. If applicable, it also handles payment application and invoice status updates.
	 *
	 * @param int|null $store The ID of the store for which the synchronization is performed.
	 *                        If null, the global store setting is used.
	 * @return bool Returns true on successful synchronization, false otherwise.
	 */
	public function synchronizeLatestUnsynchronisedInvoice($obj)
	{
		global $conf;

		$invoiceRef = (string) ($obj->invoice_ref ?? '');
		if ($invoiceRef === '' || !$this->acquireInvoiceRefLock($invoiceRef, 10)) {
			$this->error = 'Invoice synchronization is already running or has no source reference';
			return false;
		}
		try {
			// Re-read after acquiring the lock: a concurrent worker may have
			// completed validation/payment since this queue row was selected.
			$sql = 'SELECT * FROM ' . MAIN_DB_PREFIX . 'dolizsynch_zsinvoicesynch';
			$sql .= " WHERE invoice_ref = '" . $this->db->escape($invoiceRef) . "'";
			$sql .= ' AND entity = ' . (int) $conf->entity;
			$result = $this->db->query($sql);
			$row = $result ? $this->db->fetch_object($result) : null;
			if (!$row) {
				$this->error = 'Invoice synchronization row is unavailable in the active entity';
				return false;
			}
			$minimumDate = $this->getInvoiceSyncMinimumDate();
			if ($minimumDate === false) {
				return false;
			}
			if ($minimumDate !== '' && (empty($row->data_zs) || (string) $row->data_zs < $minimumDate)) {
				$this->error = 'Invoice fiscal date is outside ZS_INVOICE_SYNC_MIN_DATE (minimum ' . $minimumDate . ')';
				return false;
			}
			if (!in_array((int) $row->synch, array_merge([ZSSyncStatus::OK, ZSSyncStatus::PROCESSING], ZSSyncStatus::getSynchronizableStatuses()), true)) {
				$this->error = 'Invoice is not eligible for synchronization';
				return false;
			}
			return $this->synchronizeLatestUnsynchronisedInvoiceLocked($row);
		} finally {
			$this->releaseInvoiceRefLock($invoiceRef);
		}
	}

	/** @return bool */
	private function synchronizeLatestUnsynchronisedInvoiceLocked($obj)
	{
		global $user, $db, $conf, $langs;

		$operation = __METHOD__;
		$this->resolveInvoiceSyncActorUser($operation);
		$store = $obj->loja_zs ?? $conf->global->ZS_API_STORE;
		$row = $obj;
		$configuredStoreId = $this->getConfiguredInvoiceStoreId();
		$rawStore = trim((string) $store);
		if ($configuredStoreId <= 0 || !ctype_digit($rawStore) || (int) $rawStore !== $configuredStoreId) {
			$errorMessage = 'Invoice synchronization rejected: source store is outside the active entity scope';
			$this->logOperationError($operation, $errorMessage, [
				'invoice_ref' => (string) ($row->invoice_ref ?? ''),
				'source_store' => (string) $store,
				'configured_store' => $configuredStoreId,
			]);
			if (!empty($row->invoice_ref)) {
				$this->setSyncStatus($row->invoice_ref, ZSSyncStatus::DO_NOT_SYNCHRONIZE, $errorMessage);
			}
			return false;
		}
		$syncContext = $this->getSyncContextData($row);
		$replacementPending = !empty($syncContext['replace_existing']);
		$replacementFactureId = $replacementPending ? (int) ($syncContext['existing_facture_id'] ?? 0) : 0;

		$this->logDebug($operation, "Starting synchronization", [
			'invoice_ref' => $row->invoice_ref,
			'sync_status' => $row->synch,
			'sync_status_name' => ZSSyncStatus::getStatusDescription($row->synch),
			'fk_facture' => $row->fk_facture,
			'doc_type' => $row->doc,
			'store' => $store,
			'replacement_pending' => $replacementPending ? 1 : 0
		]);

		if ($this->isExcludedZsbmsDocumentType($row->doc ?? '')) {
			return $this->markExcludedZsbmsDocumentAsSkipped($row);
		}

		// RC documents are receipts/payments only - never create invoices
		if ($row->doc === "RC") {
			$invoiceDetails = $this->fetchInvoiceLines($row->doc, $row->doc_serie, $row->doc_num, $row->data_zs, $store);

			if (isset($invoiceDetails['error'])) {
				$errorMessage = isset($invoiceDetails['message']) ? $invoiceDetails['message'] : $invoiceDetails['error'];

				switch ($invoiceDetails['error']) {
					case 'authentication_failed':
						dol_syslog("Authentication failed for RC document " . $row->invoice_ref, LOG_ERR);
						break;
					case 'date_mismatch':
						dol_syslog("Date mismatch for RC document " . $row->invoice_ref, LOG_WARNING);
						break;
					case 'invoice_not_found':
					case 'fetch_failed':
						dol_syslog("RC document not found: " . $row->invoice_ref, LOG_ERR);
						break;
					case 'api_exception':
					case 'unexpected_exception':
					default:
						dol_syslog("Error fetching RC document " . $row->invoice_ref . ": " . $errorMessage, LOG_ERR);
						break;
				}

				$this->setSyncStatus(
					$row->invoice_ref,
					ZSSyncStatus::RECEIPT_ERROR_SYNCH,
					'RC document error: ' . $errorMessage
				);

				return false;
			}

			if (isset($invoiceDetails['data']->document) && !$this->persistInvoicePeopleCount($row->invoice_ref, $invoiceDetails['data']->document)) {
				$this->logOperationWarning($operation, "Failed to persist people count for RC document", [
					'invoice_ref' => $row->invoice_ref
				]);
			}

			if (!$this->payAllInvoicesFromRC($invoiceDetails['data'])) {
				dol_syslog("Error processing RC document: " . $this->summarizeValueForLog($invoiceDetails['data'], 600), LOG_ERR);
				$this->setSyncStatus($row->invoice_ref, ZSSyncStatus::RECEIPT_ERROR_SYNCH, 'Error processing RC document');
				return false;
			}

			$this->setSyncStatus($row->invoice_ref, ZSSyncStatus::OK, 'RC payment applied');
			return true;
		}

		// Recover rows that already point to an invoice.
		// This handles:
		// - pending or retryable failures with an existing invoice
		// - legacy false-positive rows marked as OK while facture is still draft
		if (
			$row->fk_facture > 0
			&& in_array((int) $row->synch, array_merge(
				[ZSSyncStatus::NOT_SYNCHRONIZED, ZSSyncStatus::OK, ZSSyncStatus::RETRY_QUEUED, ZSSyncStatus::PROCESSING],
				ZSSyncStatus::getRetryableStatuses()
			), true)
			&& (int) $row->anulado_zs !== 1
			&& !$replacementPending
		) {
			$facture = new Facture($db);
			$fetchResult = $facture->fetch($row->fk_facture);
			if ($fetchResult < 0) {
				$this->error = $facture->error ?: 'Unable to read the linked invoice; recreation refused';
				return false;
			}
			if ($fetchResult > 0) {
				if ((int) $facture->entity !== (int) $conf->entity) {
					$this->error = 'Linked invoice is outside the active entity';
					return false;
				}
				if ((int) $facture->statut === Facture::STATUS_DRAFT) {
					// A retry must use the same source cents as a first import. Native
					// validation alone recalculates fractional split-bill quantities.
					$details = $this->fetchInvoiceLines($row->doc, $row->doc_serie, $row->doc_num, $row->data_zs, $store);
					$mapped = isset($details['data']->document) ? $this->mapZSToDolibarrInvoice($details['data']->document) : false;
					if (!$mapped || !$this->reconcileCustomerInvoiceTotals($facture, $mapped)) {
						$this->setSyncStatus($row->invoice_ref, ZSSyncStatus::FAILED, $this->error ?: 'Unable to recover the source monetary plan');
						return false;
					}
					$warehouseId = !empty($conf->global->MAIN_DEFAULT_WAREHOUSE) ? (int) $conf->global->MAIN_DEFAULT_WAREHOUSE : -1;
					if (!$this->validateInvoiceWithMonetaryPlan($facture, $user, $warehouseId, $row->invoice_ref, $mapped['monetary_plan'])) {
						return false;
					}

					$syncContextUpdate = [
						'facture_id' => (int) $facture->id,
						'validated_existing_draft' => true
					];
					if (!$this->applyPaidSyncRowPayment($row, (int) $facture->id, $store, $syncContextUpdate)) {
						return false;
					}
					if ((int) $row->pago_zs === 1) {
						$syncContextUpdate['payment_applied_after_validation'] = true;
					}

					$this->setSyncStatus(
						$row->invoice_ref,
						ZSSyncStatus::OK,
						null,
						$syncContextUpdate
					);
					return true;
				}

				if (!$this->verifyStoredCustomerInvoiceConsistency($facture, $row->total_zs)) {
					$this->setSyncStatus($row->invoice_ref, ZSSyncStatus::FAILED, $this->error);
					return false;
				}

				if (
					!$this->applyPaidSyncRowPayment(
						$row,
						(int) $facture->id,
						$store,
						['facture_id' => (int) $facture->id]
					)
				) {
					return false;
				}

				if ((int) $row->synch !== ZSSyncStatus::OK) {
					$this->setSyncStatus(
						$row->invoice_ref,
						ZSSyncStatus::OK,
						'Invoice already exists in Dolibarr',
						['facture_id' => (int) $facture->id]
					);
				}
				return true;
			}

			// A confirmed missing partial invoice can be recreated without charging
			// another retry or erasing the retry history recorded for this failure.
			$syncContext['facture_id'] = 0;
			$syncContext['missing_facture_id'] = (int) $row->fk_facture;
			$nextStatus = (int) $row->synch === ZSSyncStatus::OK ? ZSSyncStatus::NOT_SYNCHRONIZED : (int) $row->synch;
			$sqlReset = 'UPDATE ' . MAIN_DB_PREFIX . 'dolizsynch_zsinvoicesynch SET fk_facture = 0';
			$sqlReset .= ', synch = ' . $nextStatus;
			$sqlReset .= ", sync_context = '" . $db->escape(json_encode($syncContext)) . "'";
			$sqlReset .= " WHERE invoice_ref = '" . $db->escape($row->invoice_ref) . "'";
			$sqlReset .= ' AND entity = ' . (int) $conf->entity;
			$sqlReset .= ' AND fk_facture = ' . (int) $row->fk_facture;
			$resetResult = $db->query($sqlReset);
			if (!$resetResult || (int) $db->affected_rows($resetResult) !== 1) {
				$this->error = 'Unable to clear the missing invoice link; recreation refused';
				return false;
			}
			$row->fk_facture = 0;
			$row->synch = $nextStatus;
		}

		// Process invoices that need to be created (including annulled invoices that need invoice + credit note)
		if (
			($row->fk_facture == 0 || ($replacementPending && $replacementFactureId > 0))
			&& !in_array($row->synch, [ZSSyncStatus::CREDIT_PROCESSED, ZSSyncStatus::OK])
		) {
			// An unsynchronized invoice is found (or annulled invoice that needs creation)
			$invoiceDetails = $this->fetchInvoiceLines($row->doc, $row->doc_serie, $row->doc_num, $row->data_zs, $store);

			if (isset($invoiceDetails['error'])) {
				$errorCode = (string) $invoiceDetails['error'];
				$message = trim((string) ($invoiceDetails['message'] ?? $errorCode));
				$statusByError = [
					'authentication_failed' => ZSSyncStatus::AUTHENTICATION_FAILED,
					'date_mismatch' => ZSSyncStatus::DATE_MISMATCH,
					'invoice_not_found' => ZSSyncStatus::INVOICE_NOT_FOUND,
					'source_identity_mismatch' => ZSSyncStatus::MAPPING_ERROR,
					'store_scope_mismatch' => ZSSyncStatus::MAPPING_ERROR,
					'incomplete_source' => ZSSyncStatus::MAPPING_ERROR,
				];
				$this->error = ($replacementPending ? 'Replacement source fetch failed: ' : 'Source invoice fetch failed: ') . $message;
				$this->setSyncStatus($row->invoice_ref, $statusByError[$errorCode] ?? ZSSyncStatus::FAILED, $this->error);
				return false;
			}

			$sourceDocument = $invoiceDetails['data']->document ?? null;
			if (!is_object($sourceDocument) || !isset($sourceDocument->vendas)
				|| !is_array($sourceDocument->vendas) || count($sourceDocument->vendas) === 0) {
				$this->error = 'Complete ZoneSoft source document and sales lines are required; header-only invoice creation is refused';
				$this->setSyncStatus($row->invoice_ref, ZSSyncStatus::MAPPING_ERROR, $this->error);
				return false;
			}

			if (isset($invoiceDetails['data']->document) && !$this->persistInvoicePeopleCount($row->invoice_ref, $invoiceDetails['data']->document)) {
				$this->logOperationWarning($operation, "Failed to persist people count from invoice details", [
					'invoice_ref' => $row->invoice_ref
				]);
			}

			// Separate logic for CM documents
			if ($row->doc == "CM") {
				$updated = $this->setSyncStatus($row->invoice_ref, ZSSyncStatus::DO_NOT_SYNCHRONIZE, 'CM - Consulta de Mesa');
				if (!$updated) {
					dol_syslog("CM - Consulta de Mesa, skip document", LOG_ERR);
					return false;
				}

				// Ensure fk_facture is cleared if any was set previously
				$sql = "UPDATE " . MAIN_DB_PREFIX . "dolizsynch_zsinvoicesynch SET fk_facture = 0";
				$sql .= " WHERE invoice_ref = '" . $this->db->escape($row->invoice_ref) . "'";
				$sql .= " AND entity = " . ((int) $conf->entity);
				$this->db->query($sql);
			}

			// Check if the invoice is annulled FIRST - before creating/updating invoice
			if (isset($invoiceDetails['data']->document->anulado) && $invoiceDetails['data']->document->anulado == 1) {
				dol_syslog("Invoice is annulled, processing cancellation: " . $row->invoice_ref, LOG_INFO);

				// First, check if invoice already exists or create it
				$dolibarrInvoice = $this->mapZSToDolibarrInvoice($invoiceDetails['data']->document);
				if (!$dolibarrInvoice) {
					$mappingError = !empty($this->error) ? $this->error : 'Error mapping ZoneSoft invoice to Dolibarr format';
					dol_syslog($mappingError, LOG_ERR);
					$this->setSyncStatus($row->invoice_ref, ZSSyncStatus::MAPPING_ERROR, $mappingError);
					return false;
				}

				if ($replacementPending && $replacementFactureId > 0) {
					$dolibarrInvoice['_replace_existing_facture_id'] = $replacementFactureId;
				}

				// Create or get existing invoice
				$dolibarrInvoiceId = $this->createOrUpdateDolibarrInvoice($dolibarrInvoice);
				if (!$dolibarrInvoiceId) {
					dol_syslog("Error creating/updating invoice for cancellation", LOG_ERR);
					return false;
				}

				// CRITICAL: Save fk_facture IMMEDIATELY after invoice creation, before any cancellation steps
				// This ensures we don't lose the invoice reference if cancellation fails
				$sqlSaveInvoiceId = "UPDATE " . MAIN_DB_PREFIX . "dolizsynch_zsinvoicesynch
									 SET fk_facture = " . intval($dolibarrInvoiceId) . "
									 WHERE invoice_ref = '" . $this->db->escape($row->invoice_ref) . "'
									 AND entity = " . ((int) $conf->entity);
				if (!$this->db->query($sqlSaveInvoiceId)) {
					dol_syslog("Error saving fk_facture reference: " . $this->db->lasterror(), LOG_ERR);
				}

				// Load the invoice
				$invoice = new Facture($db);
				if ($invoice->fetch($dolibarrInvoiceId) <= 0) {
					dol_syslog("Error fetching invoice for cancellation: " . $invoice->error, LOG_ERR);
					return false;
				}

				// Reopen paid invoices, remove dedicated payments, reverse stock, and cancel atomically.
				dol_syslog("Canceling invoice and reversing stock: " . $row->invoice_ref, LOG_INFO);
				if (!$this->cancelCustomerInvoiceWithStockReversal($invoice)) {
					$this->setSyncStatus($row->invoice_ref, ZSSyncStatus::FAILED, $this->error ?: 'Error canceling invoice and reversing stock');
					return false;
				}

				// Step 4: Update sync status with canceled flag
				$sql = "UPDATE " . MAIN_DB_PREFIX . "dolizsynch_zsinvoicesynch
                        SET anulado_zs = 1, stock_reversed = 1, synch = " . ZSSyncStatus::OK . ", fk_facture = " . intval($dolibarrInvoiceId) . ", date_modification = NOW()
                        WHERE invoice_ref = '" . $this->db->escape($row->invoice_ref) . "'
                        AND entity = " . ((int) $conf->entity);
				if (!$this->db->query($sql)) {
					dol_syslog("Error updating canceled status: " . $this->db->lasterror(), LOG_ERR);
					return false;
				}

				dol_syslog("Invoice successfully canceled with stock returned (anulado_zs=1, stock_reversed=1)", LOG_INFO);
				return true; // CRITICAL: Early return to prevent payment logic from executing
			} else {
				// Process normal (non-annulled) invoices
				$dolibarrInvoice = $this->mapZSToDolibarrInvoice($invoiceDetails['data']->document);
				if (!$dolibarrInvoice) {
					$mappingError = !empty($this->error) ? $this->error : 'Error mapping ZoneSoft invoice to Dolibarr format';
					dol_syslog($mappingError, LOG_ERR);
					$this->setSyncStatus($row->invoice_ref, ZSSyncStatus::MAPPING_ERROR, $mappingError);
					return false;
				}

				if ($replacementPending && $replacementFactureId > 0) {
					$dolibarrInvoice['_replace_existing_facture_id'] = $replacementFactureId;
				}

				// Save or update the invoice in Dolibarr
				$dolibarrInvoiceId = $this->createOrUpdateDolibarrInvoice($dolibarrInvoice);
				if (!$dolibarrInvoiceId) {
					dol_syslog("Error saving or updating Dolibarr invoice", LOG_ERR);
					return false;
				}
			}

			// Only process payments if invoice is NOT annulled
			if (isset($invoiceDetails['data']->document->anulado) && $invoiceDetails['data']->document->anulado == 1) {
				// Skip payment logic for annulled invoices
				return true;
			}

			if ($invoiceDetails['data']->document->pago == 1) {
				// Apply payment to the created invoice
				$paymentApplied = $this->applyPaymentToDolibarrInvoice($dolibarrInvoiceId, $invoiceDetails['data']);
				if (!$paymentApplied) {
					$errorMessage = !empty($this->error) ? $this->error : 'Error applying payment to invoice';
					dol_syslog("Error applying payment to invoice: " . $dolibarrInvoiceId . " - " . $errorMessage, LOG_ERR);
					$this->setSyncStatus($row->invoice_ref, ZSSyncStatus::FAILED, $errorMessage);
					return false;
				}
			} elseif ($invoiceDetails['data']->document->pago == 0 && !in_array($row->doc, ["FA", "RC", "NC"], true)) {
				// Set the invoice as paid
				$invoice = new Facture($db);
				if ($invoice->fetch($dolibarrInvoiceId) <= 0) {
					dol_syslog("Error fetching invoice: " . $invoice->error, LOG_ERR);
					return false;
				}
				$invoice->setPaid($user);
			}
		} elseif (
			$row->fk_facture > 0
			&& (
				(int) $row->anulado_zs === 1
				|| (int) $row->synch === ZSSyncStatus::CANCELED_NEEDS_CREDIT
			)
		) {
			// Handle already-synched invoices that need to be cancelled
			// This happens when an invoice was synched successfully (fk_facture > 0)
			// but was later cancelled in Zone Soft (anulado_zs = 1)
			dol_syslog("Processing cancellation for already-synched invoice: {$row->invoice_ref}, fk_facture={$row->fk_facture}", LOG_INFO);

			// Keep legacy rows consistent: synch=3 also means canceled in ZoneSoft.
			if ((int) $row->anulado_zs !== 1) {
				$sqlMarkCanceled = "UPDATE " . MAIN_DB_PREFIX . "dolizsynch_zsinvoicesynch";
				$sqlMarkCanceled .= " SET anulado_zs = 1, date_modification = NOW()";
				$sqlMarkCanceled .= " WHERE invoice_ref = '" . $this->db->escape($row->invoice_ref) . "'";
				$sqlMarkCanceled .= " AND entity = " . ((int) $conf->entity);
				$this->db->query($sqlMarkCanceled);
				$row->anulado_zs = 1;
			}

			// Load the invoice
			$invoice = new Facture($db);
			if ($invoice->fetch($row->fk_facture) <= 0) {
				dol_syslog("Error fetching invoice for cancellation: " . $invoice->error, LOG_ERR);
				$this->setSyncStatus($row->invoice_ref, ZSSyncStatus::FAILED, 'Error fetching invoice for cancellation');
				return false;
			}

				// Check if already cancelled
				if ($invoice->statut == Facture::STATUS_ABANDONED) {
					$db->begin();
					if (!$this->returnStockForInvoice($invoice)) {
						$db->rollback();
						$this->setSyncStatus($row->invoice_ref, ZSSyncStatus::FAILED, $this->error ?: 'Unable to reconcile canceled invoice stock');
						return false;
					}
					$sqlStockReversed = 'UPDATE '.MAIN_DB_PREFIX.'dolizsynch_zsinvoicesynch SET stock_reversed=1';
					$sqlStockReversed .= " WHERE invoice_ref='".$db->escape($row->invoice_ref)."' AND entity=".((int) $conf->entity);
					if (!$db->query($sqlStockReversed)) {
						$db->rollback();
						return false;
					}
					$db->commit();
					if (!$this->ensureCanceledInvoiceFinancialState($invoice, __METHOD__ . ':already_canceled')) {
					$this->setSyncStatus($row->invoice_ref, ZSSyncStatus::FAILED, 'Canceled invoice still has active payment links');
					return false;
				}

				dol_syslog("Invoice already cancelled in Dolibarr: {$row->invoice_ref}", LOG_INFO);
				$this->setSyncStatus($row->invoice_ref, ZSSyncStatus::OK, 'Invoice already cancelled');
				return true;
			}

			// Reopen paid invoices, remove dedicated payments, reverse stock, and cancel atomically.
			dol_syslog("Canceling invoice and reversing stock: " . $row->invoice_ref, LOG_INFO);
			if (!$this->cancelCustomerInvoiceWithStockReversal($invoice)) {
				$this->setSyncStatus($row->invoice_ref, ZSSyncStatus::FAILED, $this->error ?: 'Error canceling invoice and reversing stock');
				return false;
			}

			// Step 4: Update sync status with canceled flag
			$sql = "UPDATE " . MAIN_DB_PREFIX . "dolizsynch_zsinvoicesynch
	                    SET anulado_zs = 1, stock_reversed = 1, synch = " . ZSSyncStatus::OK . ", date_modification = NOW()
	                    WHERE invoice_ref = '" . $this->db->escape($row->invoice_ref) . "'
	                    AND entity = " . ((int) $conf->entity);
			if (!$this->db->query($sql)) {
				dol_syslog("Error updating canceled status: " . $this->db->lasterror(), LOG_ERR);
				return false;
			}

			dol_syslog("Already-synched invoice successfully canceled with stock returned (anulado_zs=1, stock_reversed=1)", LOG_INFO);
			return true;
		} else {
			dol_syslog("No unsynchronized invoices found or error in SQL query", LOG_INFO);
			return true;
		}

		return true;
	}



	/**
	 * Resets failed synchronization status from 2 back to 0.
	 */
	private function resetFailedSynchStatus()
	{

		global $db, $conf;

		// Check if there are no more invoices with synch status 0
		$sqlCheck = "SELECT COUNT(*) as cnt FROM " . MAIN_DB_PREFIX . "dolizsynch_zsinvoicesynch";
		$sqlCheck .= " WHERE synch = " . ZSSyncStatus::NOT_SYNCHRONIZED;
		$sqlCheck .= " AND entity = " . ((int) $conf->entity);
		$resqlCheck = $db->query($sqlCheck);
		if ($resqlCheck) {
			$obj = $db->fetch_object($resqlCheck);
			if ($obj->cnt == 0) {
				// Reset failed synch status
				$sqlReset = "UPDATE " . MAIN_DB_PREFIX . "dolizsynch_zsinvoicesynch";
				$sqlReset .= " SET synch = " . ZSSyncStatus::NOT_SYNCHRONIZED . ", date_modification = NOW()";
				$sqlReset .= " WHERE synch = " . ZSSyncStatus::FAILED;
				$sqlReset .= " AND entity = " . ((int) $conf->entity);
				$db->query($sqlReset);
			}
		}
	}

	/**
	 * Ensure orchestrator support tables exist.
	 *
	 * @return bool
	 */
	private function ensureMultiStoreOrchestratorTables()
	{
		global $db;

		$sqlQueue = "CREATE TABLE IF NOT EXISTS " . MAIN_DB_PREFIX . "dolizsynch_store_sync_queue (";
		$sqlQueue .= "rowid INTEGER AUTO_INCREMENT PRIMARY KEY,";
		$sqlQueue .= "entity INTEGER NOT NULL DEFAULT 0,";
		$sqlQueue .= "zsbms_store VARCHAR(10) NOT NULL,";
		$sqlQueue .= "priority INTEGER NOT NULL DEFAULT 100,";
		$sqlQueue .= "next_run_at DATETIME NULL,";
		$sqlQueue .= "processing TINYINT NOT NULL DEFAULT 0,";
		$sqlQueue .= "lock_token VARCHAR(64) NULL,";
		$sqlQueue .= "attempts INTEGER NOT NULL DEFAULT 0,";
		$sqlQueue .= "last_error TEXT NULL,";
		$sqlQueue .= "last_run_at DATETIME NULL,";
		$sqlQueue .= "date_creation DATETIME NULL,";
		$sqlQueue .= "tms TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,";
		$sqlQueue .= "UNIQUE KEY uk_store_entity (zsbms_store, entity),";
		$sqlQueue .= "KEY idx_due (processing, next_run_at, priority, rowid)";
		$sqlQueue .= ") ENGINE=innodb";

		if (!$db->query($sqlQueue)) {
			$this->logSqlError(__METHOD__, $db, ['table' => 'dolizsynch_store_sync_queue']);
			return false;
		}

		$sqlCursor = "CREATE TABLE IF NOT EXISTS " . MAIN_DB_PREFIX . "dolizsynch_store_sync_cursor (";
		$sqlCursor .= "rowid INTEGER AUTO_INCREMENT PRIMARY KEY,";
		$sqlCursor .= "entity INTEGER NOT NULL DEFAULT 0,";
		$sqlCursor .= "zsbms_store VARCHAR(10) NOT NULL,";
		$sqlCursor .= "offset_cursor INTEGER NOT NULL DEFAULT 0,";
		$sqlCursor .= "last_headers_count INTEGER NOT NULL DEFAULT 0,";
		$sqlCursor .= "last_status VARCHAR(32) NOT NULL DEFAULT 'idle',";
		$sqlCursor .= "last_message VARCHAR(255) NULL,";
		$sqlCursor .= "last_run_at DATETIME NULL,";
		$sqlCursor .= "date_creation DATETIME NULL,";
		$sqlCursor .= "tms TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,";
		$sqlCursor .= "UNIQUE KEY uk_store_entity (zsbms_store, entity)";
		$sqlCursor .= ") ENGINE=innodb";

		if (!$db->query($sqlCursor)) {
			$this->logSqlError(__METHOD__, $db, ['table' => 'dolizsynch_store_sync_cursor']);
			return false;
		}

		return true;
	}

	/**
	 * Return the customer-invoice store owned by the current entity.
	 *
	 * dolizsynch_storemap belongs to inter-entity supplier conversion and must
	 * never expand a customer-invoice cron to stores owned by other entities.
	 *
	 * @return array<int,string>
	 */
	private function getConfiguredInvoiceStores()
	{
		$storeId = $this->getConfiguredInvoiceStoreId();
		return $storeId > 0 ? [(string) $storeId] : [];
	}

	/**
	 * Ensure queue/cursor rows exist for mapped stores.
	 *
	 * @param array<int,string> $stores
	 * @return bool
	 */
	private function seedStoreQueueAndCursors(array $stores)
	{
		global $db, $conf;

		$entity = (int) $conf->entity;
		$nowSql = "'" . $db->idate(dol_now()) . "'";
		$stores = array_values(array_unique(array_filter(array_map(static function ($store) {
			return trim((string) $store);
		}, $stores), static function ($store) {
			return $store !== '';
		})));

		if (empty($stores)) {
			return true;
		}

		$storeSqlList = [];
		foreach ($stores as $storeForSql) {
			$storeSqlList[] = "'" . $db->escape($storeForSql) . "'";
		}
		$storesSql = implode(',', $storeSqlList);

		// Remove stale queue/cursor rows so we do not keep syncing stores from other entities/old mappings.
		$sqlPruneQueue = "DELETE FROM " . MAIN_DB_PREFIX . "dolizsynch_store_sync_queue";
		$sqlPruneQueue .= " WHERE entity = " . $entity;
		$sqlPruneQueue .= " AND zsbms_store NOT IN (" . $storesSql . ")";
		if (!$db->query($sqlPruneQueue)) {
			$this->logSqlError(__METHOD__, $db, ['table' => 'dolizsynch_store_sync_queue', 'operation' => 'prune_stale_rows']);
			return false;
		}

		$sqlPruneCursor = "DELETE FROM " . MAIN_DB_PREFIX . "dolizsynch_store_sync_cursor";
		$sqlPruneCursor .= " WHERE entity = " . $entity;
		$sqlPruneCursor .= " AND zsbms_store NOT IN (" . $storesSql . ")";
		if (!$db->query($sqlPruneCursor)) {
			$this->logSqlError(__METHOD__, $db, ['table' => 'dolizsynch_store_sync_cursor', 'operation' => 'prune_stale_rows']);
			return false;
		}

		foreach ($stores as $store) {
			$sqlQueue = "INSERT INTO " . MAIN_DB_PREFIX . "dolizsynch_store_sync_queue (";
			$sqlQueue .= "entity, zsbms_store, priority, next_run_at, processing, attempts, date_creation";
			$sqlQueue .= ") VALUES (";
			$sqlQueue .= $entity . ", ";
			$sqlQueue .= "'" . $db->escape($store) . "', ";
			$sqlQueue .= "100, " . $nowSql . ", 0, 0, " . $nowSql;
			$sqlQueue .= ")";
			$sqlQueue .= " ON DUPLICATE KEY UPDATE next_run_at = COALESCE(next_run_at, " . $nowSql . ")";

			if (!$db->query($sqlQueue)) {
				$this->logSqlError(__METHOD__, $db, ['table' => 'dolizsynch_store_sync_queue', 'store' => $store]);
				return false;
			}

			$sqlCursor = "INSERT INTO " . MAIN_DB_PREFIX . "dolizsynch_store_sync_cursor (";
			$sqlCursor .= "entity, zsbms_store, offset_cursor, last_headers_count, last_status, date_creation";
			$sqlCursor .= ") VALUES (";
			$sqlCursor .= $entity . ", ";
			$sqlCursor .= "'" . $db->escape($store) . "', ";
			$sqlCursor .= "0, 0, 'idle', " . $nowSql;
			$sqlCursor .= ")";
			$sqlCursor .= " ON DUPLICATE KEY UPDATE zsbms_store = '" . $db->escape($store) . "'";

			if (!$db->query($sqlCursor)) {
				$this->logSqlError(__METHOD__, $db, ['table' => 'dolizsynch_store_sync_cursor', 'store' => $store]);
				return false;
			}
		}

		return true;
	}

	/**
	 * Acquire next due store queue item.
	 *
	 * @param string $lockToken
	 * @return array<string,mixed>|null
	 */
	private function acquireNextStoreQueueItem($lockToken)
	{
		global $db, $conf;

		$entity = (int) $conf->entity;
		$nowSql = "'" . $db->idate(dol_now()) . "'";

		$sql = "SELECT rowid, zsbms_store, priority, next_run_at, attempts";
		$sql .= " FROM " . MAIN_DB_PREFIX . "dolizsynch_store_sync_queue";
		$sql .= " WHERE entity = " . $entity;
		$sql .= " AND processing = 0";
		$sql .= " AND (next_run_at IS NULL OR next_run_at <= " . $nowSql . ")";
		$sql .= " ORDER BY priority ASC, next_run_at ASC, rowid ASC";
		$sql .= " LIMIT 1";

		$resql = $db->query($sql);
		if (!$resql) {
			$this->logSqlError(__METHOD__, $db, ['query' => 'acquire queue candidate']);
			return null;
		}

		$obj = $db->fetch_object($resql);
		$db->free($resql);
		if (!$obj) {
			return null;
		}

		$store = trim((string) $obj->zsbms_store);
		if ($store === '') {
			return null;
		}

		$sqlLock = "UPDATE " . MAIN_DB_PREFIX . "dolizsynch_store_sync_queue";
		$sqlLock .= " SET processing = 1, lock_token = '" . $db->escape($lockToken) . "', attempts = attempts + 1";
		$sqlLock .= " WHERE rowid = " . ((int) $obj->rowid);
		$sqlLock .= " AND entity = " . $entity;
		$sqlLock .= " AND processing = 0";

		$resqlLock = $db->query($sqlLock);
		if (!$resqlLock) {
			$this->logSqlError(__METHOD__, $db, ['query' => 'lock queue row', 'store' => $store]);
			return null;
		}

		if ($db->affected_rows($resqlLock) <= 0) {
			return null;
		}

		return [
			'rowid' => (int) $obj->rowid,
			'zsbms_store' => $store,
			'attempts' => (int) $obj->attempts
		];
	}

	/**
	 * Release queue item after processing.
	 *
	 * @param string $store
	 * @param string $lockToken
	 * @param bool $success
	 * @param string $message
	 * @param int $delaySeconds
	 * @return void
	 */
	private function releaseStoreQueueItem($store, $lockToken, $success, $message, $delaySeconds)
	{
		global $db, $conf;

		$entity = (int) $conf->entity;
		$delaySeconds = max(0, (int) $delaySeconds);
		$nextRun = dol_now() + $delaySeconds;
		$nextRunSql = "'" . $db->idate($nextRun) . "'";
		$lastRunSql = "'" . $db->idate(dol_now()) . "'";
		$errorText = $success ? '' : trim((string) $message);

		$sql = "UPDATE " . MAIN_DB_PREFIX . "dolizsynch_store_sync_queue";
		$sql .= " SET processing = 0";
		$sql .= ", lock_token = NULL";
		$sql .= ", next_run_at = " . $nextRunSql;
		$sql .= ", last_run_at = " . $lastRunSql;
		$sql .= ", last_error = " . ($errorText !== '' ? "'" . $db->escape($errorText) . "'" : "NULL");
		$sql .= " WHERE entity = " . $entity;
		$sql .= " AND zsbms_store = '" . $db->escape((string) $store) . "'";
		$sql .= " AND lock_token = '" . $db->escape((string) $lockToken) . "'";

		if (!$db->query($sql)) {
			$this->logSqlError(__METHOD__, $db, ['store' => $store]);
		}
	}

	/**
	 * Return current offset cursor for a store.
	 *
	 * @param string $store
	 * @return int
	 */
	private function getStoreCursorOffset($store)
	{
		global $db, $conf;

		$entity = (int) $conf->entity;
		$sql = "SELECT offset_cursor FROM " . MAIN_DB_PREFIX . "dolizsynch_store_sync_cursor";
		$sql .= " WHERE entity = " . $entity;
		$sql .= " AND zsbms_store = '" . $db->escape((string) $store) . "'";
		$sql .= " LIMIT 1";

		$resql = $db->query($sql);
		if ($resql && ($obj = $db->fetch_object($resql))) {
			$db->free($resql);
			return max(0, (int) $obj->offset_cursor);
		}

		return 0;
	}

	/**
	 * Update store cursor state.
	 *
	 * @param string $store
	 * @param int $offset
	 * @param int $headersCount
	 * @param string $status
	 * @param string $message
	 * @return void
	 */
	private function updateStoreCursor($store, $offset, $headersCount, $status, $message = '')
	{
		global $db, $conf;

		$entity = (int) $conf->entity;
		$offset = max(0, (int) $offset);
		$headersCount = max(0, (int) $headersCount);
		$status = trim((string) $status);
		if ($status === '') {
			$status = 'idle';
		}
		$message = trim((string) $message);

		$sql = "UPDATE " . MAIN_DB_PREFIX . "dolizsynch_store_sync_cursor";
		$sql .= " SET offset_cursor = " . $offset;
		$sql .= ", last_headers_count = " . $headersCount;
		$sql .= ", last_status = '" . $db->escape(substr($status, 0, 32)) . "'";
		$sql .= ", last_message = " . ($message !== '' ? "'" . $db->escape(substr($message, 0, 255)) . "'" : "NULL");
		$sql .= ", last_run_at = '" . $db->idate(dol_now()) . "'";
		$sql .= " WHERE entity = " . $entity;
		$sql .= " AND zsbms_store = '" . $db->escape((string) $store) . "'";

		if (!$db->query($sql)) {
			$this->logSqlError(__METHOD__, $db, ['store' => $store]);
		}
	}

	/**
	 * Process unsynchronized invoices for one store.
	 *
	 * @param string|int $store
	 * @param int $maxSynchNumInvoices
	 * @param string $operation
	 * @return array<string,mixed>
	 */
	private function synchronizeUnsynchronizedInvoicesForStore($store, $maxSynchNumInvoices, $operation)
	{
		global $db, $conf;

		$store = trim((string) $store);
		$maxSynchNumInvoices = max(1, (int) $maxSynchNumInvoices);

		$result = [
			'processed' => 0,
			'success' => 0,
			'failed' => 0,
			'failed_invoices' => [],
			'sql_error' => ''
		];
		$minimumDate = $this->getInvoiceSyncMinimumDate();
		if ($minimumDate === false) {
			$result['sql_error'] = $this->error;
			return $result;
		}

		$sql = "SELECT zs.* FROM " . MAIN_DB_PREFIX . "dolizsynch_zsinvoicesynch AS zs ";
		$sql .= "LEFT JOIN " . MAIN_DB_PREFIX . "facture AS f ON f.rowid = zs.fk_facture AND f.entity = " . ((int) $conf->entity) . " ";
		$sql .= "WHERE zs.entity = " . ((int) $conf->entity) . " ";
		$sql .= "AND zs.loja_zs = '" . $db->escape($store) . "' ";
		if ($minimumDate !== '') {
			$sql .= "AND zs.data_zs >= '" . $db->escape($minimumDate) . "' ";
		}
		$sql .= "AND zs.synch <> " . ZSSyncStatus::DO_NOT_SYNCHRONIZE . " ";
		$excludedDocCondition = $this->buildExcludedZsbmsDocumentTypeSqlCondition('zs.doc');
		if ($excludedDocCondition !== '') {
			$sql .= "AND " . $excludedDocCondition . " ";
		}
		$sql .= "AND (";
		$sql .= "zs.synch IN (" . implode(',', ZSSyncStatus::getSynchronizableStatuses()) . ") ";
		$sql .= "OR (";
		$sql .= "zs.synch = " . ZSSyncStatus::OK . " ";
		$sql .= "AND zs.fk_facture > 0 ";
		$sql .= "AND zs.doc NOT IN ('RC', 'CM') ";
		$sql .= "AND COALESCE(zs.anulado_zs, 0) <> 1 ";
		$sql .= "AND (f.rowid IS NULL OR f.fk_statut = " . Facture::STATUS_DRAFT . ")";
		$sql .= ")";
		$sql .= ") ";
		$sql .= "ORDER BY CASE ";
		$sql .= "WHEN zs.synch = " . ZSSyncStatus::NOT_SYNCHRONIZED . " THEN 0 ";
		$sql .= "WHEN zs.synch = " . ZSSyncStatus::CANCELED_NEEDS_CREDIT . " THEN 1 ";
		$sql .= "WHEN zs.synch = " . ZSSyncStatus::OK . " THEN 2 ";
		$sql .= "ELSE 3 END, datahora_zs ASC ";
		$sql .= "LIMIT " . $maxSynchNumInvoices;

		$resql = $db->query($sql);
		if (!$resql) {
			$result['sql_error'] = $db->lasterror();
			$this->logSqlError($operation, $db, ['query_snippet' => 'SELECT unsynchronized invoices', 'store' => $store]);
			return $result;
		}

		while ($obj = $db->fetch_object($resql)) {
			$result['processed']++;

			$this->logDebug($operation, "Processing invoice", [
				'invoice_ref' => $obj->invoice_ref,
				'sync_status' => $obj->synch,
				'store' => $store,
				'progress' => $result['processed'] . '/' . $maxSynchNumInvoices
			]);

			$synchronizationResult = $this->synchronizeLatestUnsynchronisedInvoice($obj);
			if ($synchronizationResult) {
				$result['success']++;
			} else {
				$result['failed']++;
				$result['failed_invoices'][] = $obj->invoice_ref;

				$this->logOperationWarning($operation, "Invoice synchronization failed", [
					'invoice_ref' => $obj->invoice_ref,
					'sync_status' => $obj->synch,
					'sync_status_name' => ZSSyncStatus::getStatusDescription($obj->synch),
					'store' => $store
					]);
				}

			$this->releaseSyncRuntimeMemory($result['processed']);
		}

		$db->free($resql);

		return $result;
	}

	/**
	 * Multi-store orchestrator with per-store queue + cursor handling.
	 *
	 * @return int|string 0 on success/partial success, or error string.
	 */
	public function synchronizeInvoicesAcrossMappedStores()
	{
		global $conf, $langs;

		$operation = __METHOD__;
		if ($this->getInvoiceSyncMinimumDate() === false) {
			return $this->cronError($operation, $this->error);
		}
		$maxStoresPerRun = (int) ($conf->global->ZS_MULTI_STORE_MAX_STORES_PER_RUN ?? 3);
		$maxStoresPerRun = max(1, min(50, $maxStoresPerRun));
		$maxSynchNumInvoices = (int) ($conf->global->ZS_MAX_NUMBER_OF_INVOICES_IN_EACH_SYNCHRONIZATION ?? 50);
		$maxSynchNumInvoices = max(1, $maxSynchNumInvoices);
		$lockToken = substr(md5(uniqid((string) dol_now(), true)), 0, 32);

		$this->logOperationStart($operation, [
			'max_stores_per_run' => $maxStoresPerRun,
			'max_invoices_per_store' => $maxSynchNumInvoices
		]);

		if (!$this->ensureMultiStoreOrchestratorTables()) {
			return $this->cronError($operation, "Failed to initialize multi-store orchestrator tables");
		}

		$stores = $this->getConfiguredInvoiceStores();
		if (empty($stores)) {
			return $this->cronError($operation, "No mapped stores found for multi-store synchronization");
		}

		if (!$this->seedStoreQueueAndCursors($stores)) {
			return $this->cronError($operation, "Failed to seed store queue/cursors");
		}

		$totalStoresProcessed = 0;
		$totalStoreFailures = 0;
		$failedStores = [];
		$totalHeadersSaved = 0;
		$totalInvoicesProcessed = 0;
		$totalInvoicesSuccess = 0;
		$totalInvoicesFailed = 0;

		while ($totalStoresProcessed < $maxStoresPerRun) {
			$queueItem = $this->acquireNextStoreQueueItem($lockToken);
			if ($queueItem === null) {
				break;
			}

			$store = (string) $queueItem['zsbms_store'];
			$storeOperation = $operation . "[store={$store}]";
			$totalStoresProcessed++;

			try {
				// Regular cron synchronization must always revisit the newest page.
				// Advancing a persistent offset here delays new invoices until the
				// complete remote history has been traversed.
				$offset = 0;
				$headersResult = $this->fetchInvoiceHeaders($store, $offset, false);
				if ($headersResult === false || !is_object($headersResult) || !isset($headersResult->document) || !is_array($headersResult->document)) {
					$this->updateStoreCursor($store, $offset, 0, 'error', 'fetch_failed');
					$this->releaseStoreQueueItem($store, $lockToken, false, 'fetch_failed', 300);
					$totalStoreFailures++;
					$failedStores[] = $store . ':fetch_failed';
					continue;
				}

				$headersCount = count($headersResult->document);
				if ($headersCount > 0) {
					if (!$this->saveInvoiceHeaders($headersResult)) {
						$this->updateStoreCursor($store, $offset, $headersCount, 'error', 'save_failed');
						$this->releaseStoreQueueItem($store, $lockToken, false, 'save_failed', 300);
						$totalStoreFailures++;
						$failedStores[] = $store . ':save_failed';
						continue;
					}
					$totalHeadersSaved += $headersCount;
				}

				$this->updateStoreCursor($store, 0, $headersCount, 'ok', 'latest_headers_synced');

				$syncResult = $this->synchronizeUnsynchronizedInvoicesForStore($store, $maxSynchNumInvoices, $storeOperation);
				$totalInvoicesProcessed += (int) $syncResult['processed'];
				$totalInvoicesSuccess += (int) $syncResult['success'];
				$totalInvoicesFailed += (int) $syncResult['failed'];

				if (!empty($syncResult['sql_error'])) {
					$this->releaseStoreQueueItem($store, $lockToken, false, 'invoice_sync_sql_error', 300);
					$totalStoreFailures++;
					$failedStores[] = $store . ':invoice_sync_sql_error';
					continue;
				}

				$this->releaseStoreQueueItem($store, $lockToken, true, '', 5);
			} catch (Exception $e) {
				$this->updateStoreCursor($store, 0, 0, 'exception', $e->getMessage());
				$this->releaseStoreQueueItem($store, $lockToken, false, $e->getMessage(), 300);
				$totalStoreFailures++;
				$failedStores[] = $store . ':exception';
				$this->logOperationWarning($storeOperation, "Store orchestration failed", ['exception' => $e->getMessage()]);
			}
		}

		if ($totalStoresProcessed === 0) {
			$this->logOperationSuccess($operation, ['message' => 'No due stores in queue']);
			return 0;
		}

		$summary = "Multi-store sync: stores={$totalStoresProcessed}, store_failures={$totalStoreFailures}, headers_saved={$totalHeadersSaved}, invoices_processed={$totalInvoicesProcessed}, ok={$totalInvoicesSuccess}, failed={$totalInvoicesFailed}";
		if ($totalInvoicesFailed > 0 || $totalStoreFailures > 0) {
			setEventMessages($summary, null, 'warnings', 1);
		} else {
			setEventMessages($summary, null, 'mesgs', 1);
		}

		return $this->cronPartialSuccess($operation, $totalInvoicesSuccess, ($totalInvoicesFailed + $totalStoreFailures), [
			'stores_processed' => $totalStoresProcessed,
			'store_failures' => $totalStoreFailures,
			'failed_stores' => array_slice($failedStores, 0, 10),
			'headers_saved' => $totalHeadersSaved,
			'invoices_processed' => $totalInvoicesProcessed
		]);
	}



	/**
	 * Synchronize unsynchronized invoices for the configured store with per-run cap.
	 *
	 * Flow:
	 * 1) Fetch headers from Zone Soft for the configured store.
	 *    Uses a bounded incremental fetch (latest page only) to keep cron runtime stable.
	 * 2) Save headers locally.
	 * 3) Synchronize pending invoices in local queue, limited by setup max per run.
	 *
	 * @return int 0 on success (or partial success), or error string on failure (Dolibarr cron convention)
	 */
	public function synchronizeAllUnsynchronizedInvoicesByStore()
	{
		global $db, $conf, $langs;

		$operation = __METHOD__;
		if ($this->getInvoiceSyncMinimumDate() === false) {
			return $this->cronError($operation, $this->error);
		}
		$configuredStores = $this->getConfiguredInvoiceStores();
		if (count($configuredStores) !== 1) {
			return $this->cronError($operation, 'ZS_API_STORE must identify one positive numeric store for the active entity');
		}
		$store = $configuredStores[0];
		$maxSynchNumInvoices = (int) ($conf->global->ZS_MAX_NUMBER_OF_INVOICES_IN_EACH_SYNCHRONIZATION ?? 50);
		$maxSynchNumInvoices = max(1, $maxSynchNumInvoices);
		$paginateHeadersFully = false;

		$this->logOperationStart($operation, [
			'store' => $store,
			'max_invoices' => $maxSynchNumInvoices,
			'paginate_headers_fully' => !empty($paginateHeadersFully),
			'note' => 'Syncs latest headers incrementally then caps invoice synchronization per run'
		]);

		if (!empty($conf->global->ZS_INVOICE_IMPORT_PAGINATE_FULLY)) {
			$this->logOperationWarning(
				$operation,
				"Ignoring full header pagination for this cron to keep runs bounded and non-blocking",
				['setting' => 'ZS_INVOICE_IMPORT_PAGINATE_FULLY']
			);
		}

		$invoiceSync = new ZSInvoiceSynch($db);

		// Step 1: Fetch latest header page for the configured store.
		// This cron must stay bounded; it should not scan full remote history in one run.
		$this->logDebug($operation, "Step 1: Fetching invoice headers for configured store", [
			'store' => $store,
			'paginate_fully' => !empty($paginateHeadersFully)
		]);
		$invoiceHeaders = $invoiceSync->fetchInvoiceHeaders($store, 0, $paginateHeadersFully);

		if ($invoiceHeaders === false) {
			setEventMessages($langs->trans("ErrorFetchingInvoices"), null, 'errors');
			return $this->cronError(
				$operation,
				"Failed to fetch invoice headers from ZoneSoft",
				['store' => $store]
			);
		}

		$headersCount = isset($invoiceHeaders->document) && is_array($invoiceHeaders->document)
			? count($invoiceHeaders->document)
			: 0;

		// Step 2: Save fetched headers (if any) before invoice synchronization.
		if ($headersCount > 0) {
			$this->logDebug($operation, "Step 2: Saving invoice headers to database", [
				'store' => $store,
				'count' => $headersCount
			]);

			$saveResult = $invoiceSync->saveInvoiceHeaders($invoiceHeaders);
			if ($saveResult === false) {
				setEventMessages($langs->trans("ErrorSavingInvoiceHeaders"), null, 'errors');
				return $this->cronError(
					$operation,
					"Failed to save invoice headers",
					['store' => $store, 'headers_count' => $headersCount]
				);
			}
		} else {
			$this->logDebug($operation, "No new headers returned by API; continuing with local unsynchronized queue", [
				'store' => $store
			]);
		}

		// Step 3: Synchronize pending invoices with configured per-run cap.
		$this->logDebug($operation, "Step 3: Synchronizing invoices with per-run cap", [
			'store' => $store,
			'max_invoices' => $maxSynchNumInvoices
		]);
		$result = $this->synchronizeUnsynchronizedInvoicesForStore($store, $maxSynchNumInvoices, $operation);
		if (!empty($result['sql_error'])) {
			setEventMessages($langs->trans("ErrorFetchingInvoices"), null, 'errors');
			return $this->cronError(
				$operation,
				"SQL query failed to fetch unsynchronized invoices",
				['store' => $store, 'sql_error' => $result['sql_error']]
			);
		}

		if ((int) $result['processed'] > 0) {
			$message = $langs->trans("InvoiceSyncSummary", (int) $result['processed'], (int) $result['success'], (int) $result['failed']);
			if ((int) $result['failed'] > 0) {
				setEventMessages($message, null, 'warnings', 1);
			} else {
				setEventMessages($message, null, 'mesgs', 1);
			}
		}

		if ((int) $result['processed'] === 0) {
			return $this->cronSuccess($operation, [
				'store' => $store,
				'headers_synced' => $headersCount,
				'message' => 'No invoices to synchronize'
			]);
		}

		return $this->cronPartialSuccess($operation, (int) $result['success'], (int) $result['failed'], [
			'store' => $store,
			'headers_synced' => $headersCount,
			'max_invoices' => $maxSynchNumInvoices,
			'failed_invoices' => array_slice($result['failed_invoices'], 0, 10)
		]);
	}

	/**
	 * Synchronize all unsynchronized invoices for a specific date without limit
	 *
	 * This method is similar to synchronizeAllUnsynchronizedInvoicesByStore but
	 * processes ALL invoices for a given date without applying the config limit.
	 * Used by synchronizeYesterdayInvoices to ensure all yesterday's invoices are processed.
	 *
	 * @param string $date Date in Y-m-d format (optional, if null syncs all)
	 * @return int 0 on success, -1 on error (Dolibarr cron convention)
	 */
	public function synchronizeAllUnsynchronizedInvoicesForDate($date = null)
	{
		global $db, $conf, $langs;

		$operation = __METHOD__;
		$minimumDate = $this->getInvoiceSyncMinimumDate();
		if ($minimumDate === false) {
			return $this->cronError($operation, $this->error);
		}
		$storeId = $this->getConfiguredInvoiceStoreId();
		if ($storeId <= 0) {
			return $this->cronError($operation, 'ZS_API_STORE must identify one positive numeric store for the active entity');
		}
		$store = (string) $storeId;

		$this->logOperationStart($operation, [
			'store' => $store,
			'date' => $date,
			'note' => 'No limit applied - processes ALL invoices'
		]);

		$sql = "SELECT zs.* FROM " . MAIN_DB_PREFIX . "dolizsynch_zsinvoicesynch AS zs ";
		$sql .= "LEFT JOIN " . MAIN_DB_PREFIX . "facture AS f ON f.rowid = zs.fk_facture AND f.entity = " . ((int) $conf->entity) . " ";
		$sql .= "WHERE zs.entity = " . ((int) $conf->entity) . " ";
		$sql .= "AND zs.loja_zs = '" . $db->escape($store) . "' ";
		if ($minimumDate !== '') {
			$sql .= "AND zs.data_zs >= '" . $db->escape($minimumDate) . "' ";
		}
		$sql .= "AND zs.synch <> " . ZSSyncStatus::DO_NOT_SYNCHRONIZE . " ";
		$excludedDocCondition = $this->buildExcludedZsbmsDocumentTypeSqlCondition('zs.doc');
		if ($excludedDocCondition !== '') {
			$sql .= "AND " . $excludedDocCondition . " ";
		}
		$sql .= "AND (";
		$sql .= "zs.synch IN (" . implode(',', ZSSyncStatus::getSynchronizableStatuses()) . ") ";
		$sql .= "OR (";
		$sql .= "zs.synch = " . ZSSyncStatus::OK . " ";
		$sql .= "AND zs.fk_facture > 0 ";
		$sql .= "AND zs.doc NOT IN ('RC', 'CM') ";
		$sql .= "AND COALESCE(zs.anulado_zs, 0) <> 1 ";
		$sql .= "AND (f.rowid IS NULL OR f.fk_statut = " . Facture::STATUS_DRAFT . ")";
		$sql .= ")";
		$sql .= ") ";

		// If date is specified, filter by business date first (data_zs), with datetime fallback.
		// This avoids dropping late-night invoices when timezone normalization shifts datahora_zs date.
		if ($date !== null) {
			$normalizedFilterDate = $this->normalizeZsDate($date);
			if ($normalizedFilterDate === null) {
				return $this->cronError(
					$operation,
					"Invalid date filter format",
					['store' => $store, 'date' => $date]
				);
			}

			$filterDateStart = $normalizedFilterDate . " 00:00:00";
			$filterDateEndExclusive = (new DateTimeImmutable($normalizedFilterDate, $this->getSynchronizationTimezone()))
				->modify('+1 day')
				->format('Y-m-d') . " 00:00:00";

			$sql .= "AND (";
			$sql .= "zs.data_zs = '" . $db->escape($normalizedFilterDate) . "' ";
			$sql .= "OR (";
			$sql .= "zs.data_zs IS NULL ";
			$sql .= "AND zs.datahora_zs >= '" . $db->escape($filterDateStart) . "' ";
			$sql .= "AND zs.datahora_zs < '" . $db->escape($filterDateEndExclusive) . "'";
			$sql .= ")";
			$sql .= ") ";
		}

		$sql .= "ORDER BY CASE ";
		$sql .= "WHEN zs.synch = " . ZSSyncStatus::NOT_SYNCHRONIZED . " THEN 0 ";
		$sql .= "WHEN zs.synch = " . ZSSyncStatus::CANCELED_NEEDS_CREDIT . " THEN 1 ";
		$sql .= "WHEN zs.synch = " . ZSSyncStatus::OK . " THEN 2 ";
		$sql .= "ELSE 3 END, datahora_zs ASC";
		// NO LIMIT - process all invoices

		$resql = $db->query($sql);

		if (!$resql) {
			$this->logSqlError($operation, $db, ['query_snippet' => 'SELECT unsynchronized invoices for date']);
			setEventMessages($langs->trans("ErrorFetchingInvoices"), null, 'errors');
			return $this->cronError(
				$operation,
				"SQL query failed to fetch unsynchronized invoices",
				['store' => $store, 'date' => $date, 'sql_error' => $db->lasterror()]
			);
		}

		$totalProcessed = 0;
		$totalSuccess = 0;
		$totalFailed = 0;
		$failedInvoices = [];
		$stoppedByMemoryGuard = false;
		$memoryGuardThresholdRatio = 0.90;

		while ($obj = $db->fetch_object($resql)) {
			$totalProcessed++;

			$this->logDebug($operation, "Processing invoice", [
				'invoice_ref' => $obj->invoice_ref,
				'sync_status' => $obj->synch,
				'date' => $obj->datahora_zs,
				'progress' => $totalProcessed
			]);

			$synchronizationResult = $this->synchronizeLatestUnsynchronisedInvoice($obj);

			if ($synchronizationResult) {
				$totalSuccess++;
			} else {
				$totalFailed++;
				$failedInvoices[] = $obj->invoice_ref;

				$this->logOperationWarning($operation, "Invoice synchronization failed", [
					'invoice_ref' => $obj->invoice_ref,
					'sync_status' => $obj->synch,
					'sync_status_name' => ZSSyncStatus::getStatusDescription($obj->synch)
					]);
				}

			$this->releaseSyncRuntimeMemory($totalProcessed);
			if ($this->isMemoryUsageAboveRatio($memoryGuardThresholdRatio)) {
				$stoppedByMemoryGuard = true;
				$this->logOperationWarning($operation, "Synchronization paused due memory pressure", [
					'store' => $store,
					'date' => $date,
					'processed' => $totalProcessed,
					'memory_limit' => ini_get('memory_limit'),
					'threshold_ratio' => $memoryGuardThresholdRatio
				]);
				break;
			}
		}

		$db->free($resql);

		if ($stoppedByMemoryGuard) {
			$warningMsg = "Invoice synchronization paused due memory pressure after {$totalProcessed} invoice(s).";
			setEventMessages($warningMsg, null, 'warnings', 1);
		}

		// Show comprehensive summary message (persistent until user closes it)
		if ($totalProcessed > 0) {
			$dateInfo = $date ? " for date $date" : "";
			$message = $langs->trans("InvoiceSyncSummary", $totalProcessed, $totalSuccess, $totalFailed) . $dateInfo;
			if ($totalFailed > 0) {
				setEventMessages($message, null, 'warnings', 1); // 1 = keep in session until manually closed
			} else {
				setEventMessages($message, null, 'mesgs', 1); // 1 = keep in session until manually closed
			}
		}

		// Log final results
		if ($totalProcessed === 0) {
			$this->logOperationSuccess($operation, [
				'store' => $store,
				'date' => $date,
				'message' => 'No invoices to synchronize'
			]);
			return 0;
		}

		return $this->cronPartialSuccess($operation, $totalSuccess, $totalFailed, [
			'store' => $store,
			'date' => $date,
			'total_processed' => $totalProcessed,
			'stopped_by_memory_guard' => $stoppedByMemoryGuard ? 1 : 0,
			'failed_invoices' => array_slice($failedInvoices, 0, 10) // Only log first 10 to avoid log overflow
		]);
	}

	/**
	 * Clear query traces collected by debug DB wrappers and trigger periodic GC.
	 *
	 * @param int $processedCount
	 * @return void
	 */
	private function releaseSyncRuntimeMemory($processedCount = 0)
	{
		global $db;

		$this->clearDbTraceCollector($this->db);
		if (isset($db) && is_object($db) && $db !== $this->db) {
			$this->clearDbTraceCollector($db);
		}

		$processedCount = (int) $processedCount;
		if ($processedCount > 0 && function_exists('gc_collect_cycles') && ($processedCount % 5) === 0) {
			gc_collect_cycles();
		}
	}

	/**
	 * Reset known trace collector arrays on DB wrappers when present.
	 *
	 * @param mixed $dbHandle
	 * @return void
	 */
	private function clearDbTraceCollector($dbHandle)
	{
		if (!is_object($dbHandle)) {
			return;
		}

		if (property_exists($dbHandle, 'queries') && is_array($dbHandle->queries) && !empty($dbHandle->queries)) {
			$dbHandle->queries = array();
		}

		// Some wrappers expose the native db handle through ->db.
		if (property_exists($dbHandle, 'db') && is_object($dbHandle->db)) {
			$innerDb = $dbHandle->db;
			if (property_exists($innerDb, 'queries') && is_array($innerDb->queries) && !empty($innerDb->queries)) {
				$innerDb->queries = array();
			}
		}
	}

	/**
	 * Check whether current runtime memory usage exceeded a ratio of configured PHP limit.
	 *
	 * @param float $ratio
	 * @return bool
	 */
	private function isMemoryUsageAboveRatio($ratio = 0.90)
	{
		if (!function_exists('memory_get_usage')) {
			return false;
		}

		$limitBytes = $this->getPhpMemoryLimitInBytes();
		if ($limitBytes <= 0) {
			return false;
		}

		$ratio = (float) $ratio;
		if ($ratio <= 0 || $ratio >= 1) {
			$ratio = 0.90;
		}

		$currentUsage = (int) memory_get_usage(true);
		return ($currentUsage >= (int) floor($limitBytes * $ratio));
	}

	/**
	 * Convert `memory_limit` ini value into bytes.
	 *
	 * @return int `-1` means unlimited, `0` means unknown/invalid
	 */
	private function getPhpMemoryLimitInBytes()
	{
		$raw = trim((string) ini_get('memory_limit'));
		if ($raw === '') {
			return 0;
		}
		if ($raw === '-1') {
			return -1;
		}

		$unit = strtolower(substr($raw, -1));
		$value = (float) $raw;
		switch ($unit) {
			case 'g':
				return (int) ($value * 1024 * 1024 * 1024);
			case 'm':
				return (int) ($value * 1024 * 1024);
			case 'k':
				return (int) ($value * 1024);
			default:
				return (int) $value;
		}
	}

	/**
	 * Refresh master-data dictionaries used by invoice runtime logic.
	 *
	 * Excludes clients/suppliers by design.
	 *
	 * @param string $operation Caller operation name for logs.
	 * @return void
	 */
	private function refreshMasterDataBeforeInvoiceImport($operation)
	{
		try {
			$dictionarySync = new ZSDictionairSynch($this->db);
			$result = $dictionarySync->synchronizeMasterDataCore();

				if ($result) {
					$this->logDebug($operation, "Master-data pre-sync completed");
					return;
				}

			$this->logOperationWarning(
				$operation,
				"Master-data pre-sync finished with errors (continuing invoice import)",
				[
					'error' => $dictionarySync->error ?? '',
					'errors' => !empty($dictionarySync->errors) ? array_slice($dictionarySync->errors, 0, 5) : array()
				]
			);
		} catch (Exception $e) {
			$this->logOperationWarning(
				$operation,
				"Master-data pre-sync failed with exception (continuing invoice import)",
				['exception' => $e->getMessage()]
			);
		}
	}

	/**
	 * Check if invoice import should refresh dictionary/master-data first.
	 *
	 * Disabled by default to keep invoice synchronization focused on invoices.
	 *
	 * @return bool
	 */
	private function shouldRefreshMasterDataBeforeInvoiceImport()
	{
		global $conf;

		return !empty($conf->global->ZS_INVOICE_IMPORT_REFRESH_MASTERDATA);
	}

	/**
	 * Check if invoice header import should paginate through all pages.
	 *
	 * Disabled by default to reduce runtime/load for regular invoice sync runs.
	 *
	 * @return bool
	 */
	private function shouldPaginateInvoiceHeadersFully()
	{
		global $conf;

		return !empty($conf->global->ZS_INVOICE_IMPORT_PAGINATE_FULLY);
	}

	/**
	 * Import invoice headers from ZoneSoft and save them into the database.
	 *
	 * This method retrieves the store information from the global configuration.
	 * It then fetches invoice headers from the ZoneSoft API using the specified store.
	 * If the invoice headers are successfully fetched, they are saved into the database.
	 *
	 * @global object $conf The global configuration object.
	 *
	 * @return int 0 on success, or error string on failure (Dolibarr cron convention)
	 */
	public function importInvoicesHeaders()
	{
		global $conf;

		$operation = __METHOD__;
		$store = $conf->global->ZS_API_STORE ?? 'unknown';
		$refreshMasterData = $this->shouldRefreshMasterDataBeforeInvoiceImport();
		$paginateFully = $this->shouldPaginateInvoiceHeadersFully();

		$this->logOperationStart($operation, [
			'store' => $store,
			'limit' => $conf->global->ZS_GET_NUMBER_OF_INVOICES_LIMIT ?? 'not_set',
			'refresh_master_data' => !empty($refreshMasterData),
			'paginate_fully' => !empty($paginateFully)
		]);

		try {
			if ($refreshMasterData) {
				$this->refreshMasterDataBeforeInvoiceImport($operation);
			} else {
				$this->logDebug($operation, "Skipping master-data pre-sync before invoice import", [
					'setting' => 'ZS_INVOICE_IMPORT_REFRESH_MASTERDATA',
					'default_behavior' => 'disabled'
				]);
			}

			$invoiceHeaders = $this->fetchInvoiceHeadersForImport();

			if (!$invoiceHeaders) {
				return $this->cronError(
					$operation,
					"Failed to fetch invoice headers from ZoneSoft",
					['store' => $store]
				);
			}

			// Count how many invoices were fetched
			$fetchedCount = is_object($invoiceHeaders) && isset($invoiceHeaders->document)
				? count($invoiceHeaders->document)
				: 0;

			$result = $this->saveInvoiceHeaders($invoiceHeaders);

			if (!$result) {
				return $this->cronError(
					$operation,
					"Failed to save invoice headers to database",
					['store' => $store, 'fetched_count' => $fetchedCount]
				);
			}

			return $this->cronSuccess($operation, [
				'store' => $store,
				'fetched_count' => $fetchedCount
			]);
		} catch (Exception $e) {
			return $this->cronError(
				$operation,
				"Exception occurred: " . $e->getMessage(),
				[
					'store' => $store,
					'exception_class' => get_class($e),
					'exception_code' => $e->getCode()
				]
			);
		}
	}

	/**
	 * Integrates a payment from an external source into Dolibarr.
	 *
	 * @param object $paymentData The payment data received from the external source.
	 * @return bool True on successful integration and false on failure.
	 */
	public function integratePayment($paymentData)
	{

		global $user, $db, $conf;

		$store = $conf->global->ZS_API_STORE;

		foreach ($paymentData->compensacoes as $compensation) {
			$dolibarrInvoiceId = $this->fetchDolibarrInvoiceId($compensation->docNum, $compensation->docSerie);

			if ($dolibarrInvoiceId <= 0) {
				dol_syslog("Dolibarr invoice not found for docNum: " . $compensation->docNum . ", docSerie: " . $compensation->docSerie, LOG_ERR);
				continue;
			}

			$paymentInfo = $this->fetchDolibarrPaymentInfo($paymentData->pagamento, $store);

			if ($paymentInfo) {
				$paiement = new Paiement($db);
				$paiement->datepaye = dol_now(); // or convert $paymentData->dataPagamento to Dolibarr format
				$paiement->amounts = array($dolibarrInvoiceId => $compensation->valor);
				$paiement->paiementid = $paymentInfo['fk_paiement'];
				$paiement->fk_bank = $paymentInfo['fk_account'];

				if ($paiement->create($user) <= 0) {
					dol_syslog("Error creating payment: " . $paiement->error, LOG_ERR);
					return false;
				}

				$label = 'Payment for invoice ' . $dolibarrInvoiceId;
				if (!$paiement->addPaymentToBank($user, 'payment', $label, $paymentInfo['fk_account'], '', '')) {
					dol_syslog("Error linking payment to bank account: " . $paiement->error, LOG_ERR);
					return false;
				}

				//// Register RC Document in dolizsynch_zsinvoicesynch table
				//if (!$this->registerRCDocument($paymentData, $store)) {
				//	dol_syslog("Error registering RC document.", LOG_ERR);
				//	return false;
				//}
			} else {
				dol_syslog("Payment method or bank account not found for payment type: " . $paymentData->pagamento, LOG_ERR);
				return false;
			}
		}

		return true;
	}

	/**
	 * Fetches the Dolibarr invoice ID based on external document number and series.
	 *
	 * @param string $docNum Document number from the external source.
	 * @param string $docSerie Document series from the external source.
	 * @return int Dolibarr invoice ID or -1 if not found.
	 */
	private function fetchDolibarrInvoiceId($docNum, $docSerie)
	{

		global $db, $conf;

		$invoiceRef = $db->escape($docSerie) . '-' . $db->escape($docNum);

		$sql = "SELECT rowid FROM " . MAIN_DB_PREFIX . "facture";
		$sql .= " WHERE ref = '" . $invoiceRef . "'";
		$sql .= " AND entity = " . ((int) ($conf->entity ?? 1));
		$sql .= " LIMIT 1";
		$resql = $db->query($sql);
		if ($resql && $obj = $db->fetch_object($resql)) {
			return (int) $obj->rowid;
		}

		return -1;
	}

	/**
	 * Fetches the Dolibarr payment method ID and bank account ID based on an external payment type ID.
	 *
	 * @param int $externalPaymentTypeId External payment type ID.
	 * @return array|null Array containing Dolibarr payment method ID and bank account ID, or null if not found.
	 */
	private function fetchDolibarrPaymentInfo($externalPaymentTypeId)
	{

		global $db, $conf;

		$store = $conf->global->ZS_API_STORE;
		$entity = (int) ($conf->entity ?? 1);
		$paymentEntityIds = [0, $entity];
		if ($entity !== 1) {
			$paymentEntityIds[] = 1;
		}
		$paymentEntitySql = implode(',', array_values(array_unique($paymentEntityIds)));

		$sql = "SELECT zs.fk_paiement, zs.fk_account
            FROM " . MAIN_DB_PREFIX . "c_paiement AS dl
            INNER JOIN " . MAIN_DB_PREFIX . "dolizsynch_zspaymenttype AS zs
            ON dl.id = zs.fk_paiement
            WHERE zs.loja_zs = '" . $db->escape($store) . "'
            AND dl.code = '" . $db->escape($externalPaymentTypeId) . "'
            AND zs.entity IN (" . $paymentEntitySql . ")
            AND dl.entity IN (" . $paymentEntitySql . ")
            ORDER BY zs.fk_paiement ASC LIMIT 1";

		$resql = $db->query($sql);
		if ($resql && $obj = $db->fetch_object($resql)) {
			return array(
				'fk_paiement' => $obj->fk_paiement,
				'fk_account' => $obj->fk_account
			);
		}

		return null;
	}

	/**
	 * Registers a "RC" document in the dolizsynch_zsinvoicesynch table.
	 *
	 * @param object $rcDocument The RC document data.
	 * @param int $store The store ID
	 * @return bool Returns true on successful registration, false otherwise.
	 */
	public function payAllInvoicesFromRC($rcDocument)
	{

		global $db, $user, $conf, $langs;

		$document = $rcDocument->document ?? $rcDocument;
		$store = $document->loja ?? $conf->global->ZS_API_STORE;

		$compensacoes = $document->compensacoes ?? [];
		$reconciliationEntries = $this->buildRCReconciliationEntries($document, $store, $compensacoes);

		if (empty($reconciliationEntries)) {
			dol_syslog("No reconciliation entries found for RC document", LOG_ERR);
			return false;
		}

		// Fetch the Dolibarr payment mapping for this store/entity
		$sql = "SELECT fk_paiement, fk_account
			        FROM " . MAIN_DB_PREFIX . "dolizsynch_zspaymenttype
			        WHERE paymenttype_id = " . intval($document->pagamento) . "
			        AND loja_zs = '" . $db->escape($store) . "'
			        AND entity IN (0, " . (int) $conf->entity . ")
			        ORDER BY entity DESC
			        LIMIT 1";
		$resql = $db->query($sql);
		if ($resql) {
			$obj = $db->fetch_object($resql);
			if ($obj) {
				$paymentMethodId = $obj->fk_paiement;
				$bankAccountId = $obj->fk_account;

				// Use ZS_DEFAULT_BANK_ACCOUNT if fk_account is zero or null
				if (empty($bankAccountId) || $bankAccountId == 0) {
					if (!empty($conf->global->ZS_DEFAULT_BANK_ACCOUNT)) {
						$bankAccountId = $conf->global->ZS_DEFAULT_BANK_ACCOUNT;
					} else {
						dol_syslog("Default bank account not set", LOG_ERR);
						return false;
					}
				}

				// Fetch the payment method code from Dolibarr
				// Payment methods may be shared (stored under entity 1), so allow fallback to entity 1
				$paymentEntityIds = [(int) $conf->entity, 0];
				if ((int) $conf->entity !== 1) {
					$paymentEntityIds[] = 1;
				}
				$paymentEntityIds = array_values(array_unique($paymentEntityIds));
				$paymentEntitySql = implode(',', $paymentEntityIds);

				$sql = "SELECT code FROM " . MAIN_DB_PREFIX . "c_paiement
				        WHERE id = " . intval($paymentMethodId) . "
				        AND entity IN (" . $paymentEntitySql . ")
				        ORDER BY CASE entity
				          WHEN " . (int) $conf->entity . " THEN 0
				          WHEN 0 THEN 1
				          WHEN 1 THEN 2
				          ELSE 3 END
				        LIMIT 1";
				$resql = $db->query($sql);
				if ($resql) {
					$obj = $db->fetch_object($resql);
					if ($obj) {
						$paymentCode = $obj->code;
					} else {
						dol_syslog("Payment method not found for fk_paiement: " . $paymentMethodId . " (entities: " . $paymentEntitySql . ")", LOG_ERR);
						return false;
					}
				} else {
					dol_syslog("Error querying payment method: " . $db->lasterror(), LOG_ERR);
					return false;
				}
			} else {
				dol_syslog("Payment mapping not found for paymenttype_id: " . $document->pagamento . ", store: " . $store . ", entity: " . $conf->entity, LOG_ERR);
				return false;
			}
		} else {
			dol_syslog("Error querying payment mapping: " . $db->lasterror(), LOG_ERR);
			return false;
		}

		// Create a new payment object
		$payment = new Paiement($db);
		$payment->datepaye = strtotime($document->dataPagamento);
		$payment->paiementid = $paymentMethodId;
		$payment->paiementcode = $paymentCode;
		$payment->note = strtotime($document->observacoes);

		// Set the bank account ID
		$payment->fk_bank = $bankAccountId;

		// Prepare amounts array for the payment
		$amounts = [];
		foreach ($reconciliationEntries as $entry) {
			$entryDocType = $entry['docType'];
			$entryDocSerie = $entry['docSerie'];
			$entryDocNum = $entry['docNum'];
			$entryAmount = (float) ($entry['valor'] ?? 0);
			$entrySource = (string) ($entry['source'] ?? 'unknown');
			$isRequiredEntry = !empty($entry['required']);

			// Retrieve the invoice based on docType, docSerie, and docNum
			$sql = "SELECT rowid FROM " . MAIN_DB_PREFIX . "facture";
			$sql .= " WHERE ref_client LIKE '" . $db->escape($entryDocType . '_' . $entryDocSerie . '_' . $entryDocNum . '%') . "'";
			$sql .= " AND entity = " . ((int) $conf->entity);
			$sql .= " ORDER BY rowid DESC";
			$sql .= " " . $db->plimit(1, 0);
			$resql = $db->query($sql);
			if ($resql) {
				$obj = $db->fetch_object($resql);
				if ($obj) {
					$invoiceId = $obj->rowid;

					// Check the remaining amount to pay for the invoice
					$invoice = new Facture($db);
					if ($invoice->fetch($invoiceId) > 0) {
						$remainingAmount = $invoice->getRemainToPay();
						if ($remainingAmount <= 0) {
							continue;
						}
					} else {
						dol_syslog("Error fetching invoice for docNum: " . $entryDocNum . ", docSerie: " . $entryDocSerie, LOG_ERR);
						if ($isRequiredEntry) {
							return false;
						}
						continue;
					}

					if ($entryAmount <= 0) {
						$missingAmountMessage = "Missing payment amount for docNum: " . $entryDocNum . ", docSerie: " . $entryDocSerie . " (source: " . $entrySource . ")";
						dol_syslog($missingAmountMessage, LOG_WARNING);
						continue;
					}

					$amountToApply = min($entryAmount, $remainingAmount);
					if ($amountToApply <= 0) {
						continue;
					}

					if (!isset($amounts[$invoiceId])) {
						$amounts[$invoiceId] = 0.0;
					}

					$availableAmount = $remainingAmount - $amounts[$invoiceId];
					if ($availableAmount <= 0) {
						continue;
					}

					$amounts[$invoiceId] += min($amountToApply, $availableAmount);
				} else {
					$notFoundMessage = "Invoice not found for docNum: " . $entryDocNum . ", docSerie: " . $entryDocSerie . " (source: " . $entrySource . ")";
					if ($isRequiredEntry) {
						dol_syslog($notFoundMessage, LOG_ERR);
						return false;
					}

					dol_syslog($notFoundMessage, LOG_WARNING);
				}
			} else {
				dol_syslog("Error querying invoice: " . $db->lasterror(), LOG_ERR);
				return false;
			}
		}

		if (empty($amounts)) {
			dol_syslog("No invoices to pay", LOG_ERR);
			return false;
		}

		// Set the amounts for the payment
		$payment->amounts = $amounts;

		// Create the payment
		if ($payment->create($user) > 0) {
			$label = $langs->trans("PaymentForMultipleInvoicesFromRCDocument");
			if ($payment->addPaymentToBank($user, 'payment', $label, $bankAccountId, '', '') <= 0) {
				dol_syslog("Error linking payment to bank account: " . $payment->error, LOG_ERR);
				return false;
			}

			// Optionally set the invoices to paid if the payment covers the entire amount
			foreach ($amounts as $invoiceId => $amount) {
				$invoice = new Facture($db);
				if ($invoice->fetch($invoiceId) > 0) {
					if ($invoice->getRemainToPay() <= 0) {
						$invoice->set_paid($user);
					}
				}
			}

			// Update the synchronization status for the RC document
			$updateSql = "UPDATE " . MAIN_DB_PREFIX . "dolizsynch_zsinvoicesynch SET synch = " . ZSSyncStatus::OK . ", date_modification = NOW()
	                      WHERE doc = '" . $db->escape($document->doc) . "'
	                      AND doc_serie = '" . $db->escape($document->serie) . "'
	                      AND doc_num = '" . $db->escape($document->numero) . "'
	                      AND loja_zs = '" . $db->escape($conf->global->ZS_API_STORE) . "'
	                      AND entity = " . ((int) $conf->entity);
			if (!$db->query($updateSql)) {
				dol_syslog("Error updating sync status: " . $db->lasterror(), LOG_ERR);
				return false;
			}
		} else {
			dol_syslog("Error creating payment: " . $payment->error, LOG_ERR);
			return false;
		}

		return true;
	}

	/**
	 * Build reconciliation entries for an RC document.
	 *
	 * Priority:
	 * - Keep all compensacoes entries as required (current behavior baseline).
	 * - Enrich/override with paymentdoc/counterbalance action data when available.
	 * - Add API-only entries as optional when compensacoes already exist.
	 *
	 * @param object $document RC document object.
	 * @param string|int|null $store Store identifier.
	 * @param mixed $compensacoes RC compensacoes payload.
	 * @return array<int,array<string,mixed>>
	 */
	private function buildRCReconciliationEntries($document, $store, $compensacoes)
	{
		$entriesByKey = $this->normalizeCompensacaoEntries($compensacoes, true);
		$hasCompensacoes = !empty($entriesByKey);

		$doc = $this->normalizeDocumentType($document->doc ?? '');
		$serie = $this->normalizeDocumentSerie($document->serie ?? '');
		$numero = $this->normalizeDocumentNumber($document->numero ?? '');

		if ($doc === '' || $serie === '' || $numero === '') {
			return array_values($entriesByKey);
		}

		$rcKey = $this->buildDocumentReferenceKey($doc, $serie, $numero);
		$docTypeLookup = $this->buildCompensacaoDocTypeLookup($entriesByKey);

		$paymentDocInstances = [];
		$counterBalanceInstances = [];

		try {
			$paymentDocInstances = $this->zoneSoftApi->getPaymentDocInstancesFromDocument($doc, $serie, $numero, (string) $store);
		} catch (Exception $e) {
			dol_syslog("Failed to fetch getPaymentDocInstancesFromDocument for RC {$rcKey}: " . $e->getMessage(), LOG_WARNING);
		}

		try {
			$counterBalanceInstances = $this->zoneSoftApi->getCounterBalancesInstancesFromDocument($doc, $serie, $numero, (string) $store);
		} catch (Exception $e) {
			dol_syslog("Failed to fetch getCounterBalancesInstancesFromDocument for RC {$rcKey}: " . $e->getMessage(), LOG_WARNING);
		}

		$paymentDocEntries = $this->normalizeDocumentActionReconciliationEntries(
			$paymentDocInstances,
			'paymentdoc',
			$rcKey,
			$docTypeLookup,
			!$hasCompensacoes
		);
		$counterBalanceEntries = $this->normalizeDocumentActionReconciliationEntries(
			$counterBalanceInstances,
			'counterbalance',
			$rcKey,
			$docTypeLookup,
			!$hasCompensacoes
		);

		foreach ($paymentDocEntries as $key => $entry) {
			if (isset($entriesByKey[$key])) {
				if ($entriesByKey[$key]['valor'] <= 0 && $entry['valor'] > 0) {
					$entriesByKey[$key]['valor'] = $entry['valor'];
				}

				if (strpos($entriesByKey[$key]['source'], $entry['source']) === false) {
					$entriesByKey[$key]['source'] .= '+' . $entry['source'];
				}
			} else {
				$entry['required'] = $hasCompensacoes ? false : (bool) $entry['required'];
				$entriesByKey[$key] = $entry;
			}
		}

		foreach ($counterBalanceEntries as $key => $entry) {
			if (isset($entriesByKey[$key])) {
				if ($entriesByKey[$key]['valor'] <= 0 && $entry['valor'] > 0) {
					$entriesByKey[$key]['valor'] = $entry['valor'];
				}

				if (strpos($entriesByKey[$key]['source'], $entry['source']) === false) {
					$entriesByKey[$key]['source'] .= '+' . $entry['source'];
				}
			} else {
				$entry['required'] = $hasCompensacoes ? false : (bool) $entry['required'];
				$entriesByKey[$key] = $entry;
			}
		}

		return array_values($entriesByKey);
	}

	/**
	 * Normalize RC compensacoes entries into a keyed map.
	 *
	 * @param mixed $compensacoes Compensacoes payload.
	 * @param bool $required Whether entries are mandatory.
	 * @return array<string,array<string,mixed>>
	 */
	private function normalizeCompensacaoEntries($compensacoes, $required)
	{
		if (is_object($compensacoes)) {
			$compensacoes = [$compensacoes];
		}

		if (!is_array($compensacoes)) {
			return [];
		}

		$entriesByKey = [];
		foreach ($compensacoes as $compensacao) {
			$docType = $this->normalizeDocumentType($this->extractEntryFieldValue($compensacao, ['docType', 'doctype', 'doc_type', 'doc']));
			$docSerie = $this->normalizeDocumentSerie($this->extractEntryFieldValue($compensacao, ['docSerie', 'doc_serie', 'docserie', 'serie']));
			$docNum = $this->normalizeDocumentNumber($this->extractEntryFieldValue($compensacao, ['docNum', 'doc_num', 'docnum', 'numero', 'num']));

			if ($docType === '' || $docSerie === '' || $docNum === '') {
				continue;
			}

			$valor = $this->extractEntryNumericValue($compensacao, ['valor', 'amount', 'total', 'value']);
			$entryKey = $this->buildDocumentReferenceKey($docType, $docSerie, $docNum);

			if (!isset($entriesByKey[$entryKey])) {
				$entriesByKey[$entryKey] = [
					'docType' => $docType,
					'docSerie' => $docSerie,
					'docNum' => $docNum,
					'valor' => 0.0,
					'source' => 'compensacoes',
					'required' => (bool) $required
				];
			}

			if ($valor !== null && $valor > 0) {
				$entriesByKey[$entryKey]['valor'] += $valor;
			}
		}

		return $entriesByKey;
	}

	/**
	 * Normalize document action payload entries (paymentdoc/counterbalance).
	 *
	 * @param mixed $entries Action response entries.
	 * @param string $source Entry source label.
	 * @param string $rcKey Current RC key to avoid self-linking.
	 * @param array<string,string> $docTypeLookup Doc type lookup by serie+numero.
	 * @param bool $required Whether entries should be mandatory.
	 * @return array<string,array<string,mixed>>
	 */
	private function normalizeDocumentActionReconciliationEntries($entries, $source, $rcKey, array $docTypeLookup, $required)
	{
		if (is_object($entries)) {
			$entries = [$entries];
		}

		if (!is_array($entries)) {
			return [];
		}

		$normalizedByKey = [];
		foreach ($entries as $entry) {
			$docType = $this->normalizeDocumentType($this->extractEntryFieldValue($entry, ['doc', 'docType', 'doctype', 'doc_type', 'tipodoc', 'documenttype']));
			$docSerie = $this->normalizeDocumentSerie($this->extractEntryFieldValue($entry, ['serie', 'docSerie', 'doc_serie', 'docserie', 'series', 'seriedoc']));
			$docNum = $this->normalizeDocumentNumber($this->extractEntryFieldValue($entry, ['numero', 'docNum', 'doc_num', 'docnum', 'num']));

			if ($docType === '' && $docSerie !== '' && $docNum !== '') {
				$serieNumeroKey = $this->buildSerieNumeroKey($docSerie, $docNum);
				$docType = isset($docTypeLookup[$serieNumeroKey]) ? $docTypeLookup[$serieNumeroKey] : '';
			}

			if ($docType === '' || $docSerie === '' || $docNum === '') {
				continue;
			}

			$entryKey = $this->buildDocumentReferenceKey($docType, $docSerie, $docNum);
			if ($entryKey === $rcKey) {
				continue;
			}

			$valor = $this->extractEntryNumericValue(
				$entry,
				['valor', 'valorpago', 'valorpaymentdoc', 'amount', 'total', 'value', 'montante', 'totalpago']
			);

			if (!isset($normalizedByKey[$entryKey])) {
				$normalizedByKey[$entryKey] = [
					'docType' => $docType,
					'docSerie' => $docSerie,
					'docNum' => $docNum,
					'valor' => 0.0,
					'source' => $source,
					'required' => (bool) $required
				];
			}

			if ($valor !== null && $valor > 0) {
				$normalizedByKey[$entryKey]['valor'] += $valor;
			}
		}

		return $normalizedByKey;
	}

	/**
	 * Build doc type lookup by serie+numero from compensation entries.
	 *
	 * @param array<string,array<string,mixed>> $entriesByKey
	 * @return array<string,string>
	 */
	private function buildCompensacaoDocTypeLookup(array $entriesByKey)
	{
		$lookup = [];
		foreach ($entriesByKey as $entry) {
			$serieNumeroKey = $this->buildSerieNumeroKey($entry['docSerie'], $entry['docNum']);

			if (!isset($lookup[$serieNumeroKey])) {
				$lookup[$serieNumeroKey] = $entry['docType'];
				continue;
			}

			if ($lookup[$serieNumeroKey] !== $entry['docType']) {
				$lookup[$serieNumeroKey] = '';
			}
		}

		return $lookup;
	}

	/**
	 * Extract first matching scalar value from an entry (array/object, recursive).
	 *
	 * @param mixed $entry Entry payload.
	 * @param array<int,string> $candidateFields Candidate field names.
	 * @return mixed|null
	 */
	private function extractEntryFieldValue($entry, array $candidateFields)
	{
		$candidates = [];
		foreach ($candidateFields as $field) {
			$candidates[strtolower($field)] = true;
		}

		$stack = [$entry];
		while (!empty($stack)) {
			$current = array_pop($stack);
			if (is_object($current)) {
				$current = get_object_vars($current);
			}

			if (!is_array($current)) {
				continue;
			}

			foreach ($current as $key => $value) {
				$normalizedKey = strtolower((string) $key);
				if (isset($candidates[$normalizedKey]) && !is_array($value) && !is_object($value) && $value !== '' && $value !== null) {
					return $value;
				}

				if (is_array($value) || is_object($value)) {
					$stack[] = $value;
				}
			}
		}

		return null;
	}

	/**
	 * Extract a numeric value from an entry.
	 *
	 * @param mixed $entry Entry payload.
	 * @param array<int,string> $candidateFields Candidate field names.
	 * @return float|null
	 */
	private function extractEntryNumericValue($entry, array $candidateFields)
	{
		$value = $this->extractEntryFieldValue($entry, $candidateFields);
		if ($value === null || $value === '') {
			return null;
		}

		if (is_numeric($value)) {
			return (float) $value;
		}

		if (is_string($value)) {
			$normalized = trim($value);
			if ($normalized === '') {
				return null;
			}

			$normalized = str_replace(' ', '', $normalized);
			if (strpos($normalized, ',') !== false && strpos($normalized, '.') !== false) {
				if (strrpos($normalized, ',') > strrpos($normalized, '.')) {
					$normalized = str_replace('.', '', $normalized);
					$normalized = str_replace(',', '.', $normalized);
				} else {
					$normalized = str_replace(',', '', $normalized);
				}
			} else {
				$normalized = str_replace(',', '.', $normalized);
			}

			if (is_numeric($normalized)) {
				return (float) $normalized;
			}
		}

		return null;
	}

	/**
	 * Normalize a document type value.
	 *
	 * @param mixed $value
	 * @return string
	 */
	private function normalizeDocumentType($value)
	{
		return strtoupper(trim((string) $value));
	}

	/**
	 * Normalize a document series value.
	 *
	 * @param mixed $value
	 * @return string
	 */
	private function normalizeDocumentSerie($value)
	{
		return strtoupper(trim((string) $value));
	}

	/**
	 * Normalize a document number value.
	 *
	 * @param mixed $value
	 * @return string
	 */
	private function normalizeDocumentNumber($value)
	{
		if ($value === null) {
			return '';
		}

		if (is_int($value)) {
			return (string) $value;
		}

		if (is_float($value)) {
			if (floor($value) == $value) {
				return (string) ((int) $value);
			}
			return trim((string) $value);
		}

		$normalized = trim((string) $value);
		if ($normalized === '') {
			return '';
		}

		if (preg_match('/^\d+\.0+$/', $normalized)) {
			$normalized = (string) ((int) $normalized);
		}

		return $normalized;
	}

	/**
	 * Build a canonical key for a document reference.
	 *
	 * @param string $docType
	 * @param string $docSerie
	 * @param string $docNum
	 * @return string
	 */
	private function buildDocumentReferenceKey($docType, $docSerie, $docNum)
	{
		return $this->normalizeDocumentType($docType) . '_' . $this->normalizeDocumentSerie($docSerie) . '_' . $this->normalizeDocumentNumber($docNum);
	}

	/**
	 * Build a canonical key for serie+numero lookup.
	 *
	 * @param string $docSerie
	 * @param string $docNum
	 * @return string
	 */
	private function buildSerieNumeroKey($docSerie, $docNum)
	{
		return $this->normalizeDocumentSerie($docSerie) . '_' . $this->normalizeDocumentNumber($docNum);
	}

	/**
	 * Normalize a ZoneSoft date value into Y-m-d.
	 *
	 * @param mixed $rawDate
	 * @return string|null
	 */
	private function normalizeZsDate($rawDate)
	{
		$date = $this->parseZsDateTimeValue($rawDate, [
			'Y-m-d',
			'Y-m-d H:i:s',
			'Y-m-d H:i',
			'Y-m-d\\TH:i:s',
			'Y-m-d\\TH:i',
			'Y-m-d\\TH:i:sP',
			'Y-m-d\\TH:i:s.uP',
			'Y-m-d\\TH:i:s.u',
			'd/m/Y',
			'd/m/Y H:i:s',
			'd/m/Y H:i',
			'd-m-Y',
			'd-m-Y H:i:s',
			'd-m-Y H:i',
		]);

		return $date ? $date->format('Y-m-d') : null;
	}

	/**
	 * Normalize a ZoneSoft datetime value into Y-m-d H:i:s.
	 *
	 * @param mixed $rawDateTime
	 * @return string|null
	 */
	private function normalizeZsDateTime($rawDateTime)
	{
		$dateTime = $this->parseZsDateTimeValue($rawDateTime, [
			'Y-m-d H:i:s',
			'Y-m-d H:i',
			'Y-m-d\\TH:i:s',
			'Y-m-d\\TH:i',
			'Y-m-d\\TH:i:sP',
			'Y-m-d\\TH:i:s.uP',
			'Y-m-d\\TH:i:s.u',
			'd/m/Y H:i:s',
			'd/m/Y H:i',
			'd-m-Y H:i:s',
			'd-m-Y H:i',
		]);

		return $dateTime ? $dateTime->format('Y-m-d H:i:s') : null;
	}

	/**
	 * Parse a date/datetime value using explicit formats, then a safe fallback.
	 *
	 * @param mixed $rawValue
	 * @param array<int,string> $formats
	 * @return DateTimeImmutable|null
	 */
	private function parseZsDateTimeValue($rawValue, array $formats)
	{
		if ($rawValue === null) {
			return null;
		}

		$value = trim((string) $rawValue);
		if ($value === '' || $value === '0000-00-00' || $value === '0000-00-00 00:00:00' || $value === '0000-00-00T00:00:00') {
			return null;
		}

		foreach ($formats as $format) {
			$parsed = DateTimeImmutable::createFromFormat('!' . $format, $value);
			if (!($parsed instanceof DateTimeImmutable)) {
				continue;
			}

			$errors = DateTimeImmutable::getLastErrors();
			if ($errors === false || (((int) ($errors['warning_count'] ?? 0) === 0) && ((int) ($errors['error_count'] ?? 0) === 0))) {
				return $parsed;
			}
		}

		$timestamp = strtotime($value);
		if ($timestamp === false) {
			return null;
		}

		try {
			$timezone = new DateTimeZone(date_default_timezone_get());
			return (new DateTimeImmutable('@' . $timestamp))->setTimezone($timezone);
		} catch (Exception $e) {
			return null;
		}
	}


	/**
	 * Saves an individual invoice header to the database.
	 *
	 * @param object $header The invoice header object.
	 * @return bool Returns true on success, false on failure.
	 */
	public function saveInvoiceHeader($header)
	{

		global $user, $db, $conf;
		$actor = ($user instanceof User) ? $user : null;
		$resolvedActor = $this->resolveInvoiceSyncActorUser(__METHOD__);
		if ($resolvedActor instanceof User) {
			$actor = $resolvedActor;
		}
		$actorId = ($actor instanceof User && !empty($actor->id)) ? (int) $actor->id : 0;
		if ($actorId <= 0) {
			$message = "No valid actor user resolved for invoice header persistence";
			$this->error = $message;
			$this->errors[] = $message;
			dol_syslog(__METHOD__ . " - " . $message, LOG_ERR);
			return false;
		}

		$configuredStoreId = $this->getConfiguredInvoiceStoreId();
		if ($configuredStoreId <= 0 || !$this->isInvoiceHeaderForStore($header, $configuredStoreId)) {
			$message = 'Rejected ZoneSoft invoice header outside the active entity store scope';
			$this->error = $message;
			$this->errors[] = $message;
			dol_syslog(__METHOD__ . ' - ' . $message, LOG_ERR);
			return false;
		}

		$rawDate = isset($header->data) ? $header->data : null;
		$normalizedDate = $this->normalizeZsDate($rawDate);
		if ($normalizedDate === null) {
			$message = "Invalid ZoneSoft invoice date in saveInvoiceHeader";
			$context = [
				'doc' => isset($header->doc) ? $header->doc : '',
				'serie' => isset($header->serie) ? $header->serie : '',
				'numero' => isset($header->numero) ? $header->numero : '',
				'raw_date' => $rawDate
			];

			$this->error = $message;
			$this->errors[] = $message;
			dol_syslog(__METHOD__ . ' - ' . $message . ' - context=' . $this->encodeContextForLog($context), LOG_ERR);
			return false;
		}

		$rawDateTime = isset($header->datahora) ? $header->datahora : null;
		$normalizedDateTime = $this->normalizeZsDateTime($rawDateTime);
		if ($rawDateTime !== null && trim((string) $rawDateTime) !== '' && $normalizedDateTime === null) {
			$context = [
				'doc' => isset($header->doc) ? $header->doc : '',
				'serie' => isset($header->serie) ? $header->serie : '',
				'numero' => isset($header->numero) ? $header->numero : '',
				'raw_datehora' => $rawDateTime
			];
			dol_syslog(__METHOD__ . ' - Invalid ZoneSoft invoice datetime, storing NULL - context=' . $this->encodeContextForLog($context), LOG_WARNING);
		}

		$invoice_ref = $header->doc . '_' . $header->serie . '_' . $header->numero . '_' . str_replace('-', '', $normalizedDate);

		if (!$this->acquireInvoiceRefLock($invoice_ref, 2)) {
			dol_syslog("Could not acquire invoice lock, skipping single header import: " . $invoice_ref, LOG_WARNING);
			return $this->isInvoiceAlreadySaved($invoice_ref);
		}

		try {
			$otherEntityRef = $this->findInvoiceRefInOtherEntity($invoice_ref);
			if (!empty($otherEntityRef) && isset($otherEntityRef->entity)) {
				$this->logDebug(__METHOD__, "Invoice ref exists in another entity; continuing with current-entity header persistence", [
					'invoice_ref' => $invoice_ref,
					'other_entity' => (int) $otherEntityRef->entity
				]);
			}

			// Check if the invoice has already been saved
			if ($this->isInvoiceAlreadySaved($invoice_ref)) {
				$this->syncExistingHeaderWithImportedInvoice($invoice_ref, $normalizedDate, $header->total ?? null);
				dol_syslog("Invoice already saved: " . $header->numero, LOG_INFO);
				return true;
			}

			// Create new sync record
			$this->entity = (int) ($conf->entity ?? 1);
			$this->doc = $header->doc;
			$this->doc_num = $header->numero;
			$this->doc_serie = $header->serie;
			$this->invoice_ref = $invoice_ref;
			$this->contribuinte_zs = $header->contribuinte;
			$this->countrycode_zs = $header->countrycode;
			$this->data_zs = $normalizedDate;
			$this->datahora_zs = $normalizedDateTime;
			$this->liquido_zs = $header->liquido;
			$this->total_zs = $header->total;
			$this->pagamento_zs = $header->pagamento;
			$this->pago_zs = $header->pago;
			$this->anulado_zs = is_null($header->anulado) ? 0 : $header->anulado;
			$this->loja_zs = $header->loja;
			$this->tipo_zs = is_null($header->tipo) ? 0 : $header->tipo;
			$this->mesa_zs = $header->mesa;
			$this->emp_zs = $header->emp;
			$peopleCount = $this->resolveInvoicePeopleCount($header);
			$this->pessoas_zs = ($peopleCount === null ? 1 : $peopleCount);
			$this->synch = ZSSyncStatus::NOT_SYNCHRONIZED;
			$this->fk_facture = 0;
			$this->credit_ref = 0;
			$this->fk_user_creat = $actorId;
			$this->fk_user_modif = $actorId;

			// Reset object-level error state before insert attempt
			$this->error = '';
			$this->errors = array();

			$resultSync = $this->create($actor);
			if ($resultSync < 0) {
				if ($this->isDuplicateSyncRecordError($db)) {
					$this->syncExistingHeaderWithImportedInvoice($invoice_ref, $normalizedDate, $header->total ?? null);
					dol_syslog("Duplicate sync record detected, skipping invoice header: " . $invoice_ref, LOG_INFO);
					return true;
				}
				dol_syslog("Error creating ZS Invoice Sync: " . $this->error, LOG_ERR);
				return false;
			}

			dol_syslog("Invoice header saved successfully: " . $header->numero, LOG_INFO);
			return true;
		} finally {
			$this->releaseInvoiceRefLock($invoice_ref);
		}
	}

	/**
	 * Resolve synchronization timezone from Dolibarr configuration.
	 *
	 * @return DateTimeZone
	 */
	private function getSynchronizationTimezone()
	{
		global $conf;

		$timezoneName = '';
		if (!empty($conf->global->MAIN_SERVER_TZ)) {
			$timezoneName = trim((string) $conf->global->MAIN_SERVER_TZ);
		}
		if ($timezoneName === '') {
			$timezoneName = (string) date_default_timezone_get();
		}

		try {
			return new DateTimeZone($timezoneName);
		} catch (Exception $e) {
			return new DateTimeZone('UTC');
		}
	}

	/**
	 * Synchronize invoices for yesterday
	 *
	 * This method fetches invoice headers for yesterday, synchronizes the unsynchronized invoices,
	 * and handles the success or failure accordingly.
	 */
	function synchronizeYesterdayInvoices()
	{
		global $db, $langs;

		$operation = __METHOD__;
		$syncTimezone = $this->getSynchronizationTimezone();
		$yesterdayDate = (new DateTimeImmutable('now', $syncTimezone))->modify('-1 day')->format('Y-m-d');

		$this->logOperationStart($operation, [
			'date' => $yesterdayDate,
			'note' => 'Syncs ALL headers and invoices for yesterday without limit'
		]);

		// Instantiate the required classes
		$invoiceSync = new ZSInvoiceSynch($db);
		$zoneSoftInvoices = new ZSInvoiceSynch($db);

		// Calculate yesterday's date
		$initialDate = $db->escape($yesterdayDate);
		$endDate = $initialDate;  // We are only interested in yesterday's invoices

		// Step 1: Fetch ALL invoice headers for yesterday (with pagination, no 250 limit)
		$this->logDebug($operation, "Step 1: Fetching invoice headers for yesterday", ['date' => $yesterdayDate]);
		$invoiceHeaders = $invoiceSync->fetchInvoiceHeadersWithinDateRange($initialDate, $endDate, null);

		if ($invoiceHeaders) {
			// Count headers fetched
			$headerCount = isset($invoiceHeaders->document) && is_array($invoiceHeaders->document)
				? count($invoiceHeaders->document)
				: 0;

			$this->logDebug($operation, "Fetched invoice headers", [
				'count' => $headerCount,
				'date' => $yesterdayDate
			]);

			// Step 2: Save invoice headers to database (header sync)
			$this->logDebug($operation, "Step 2: Saving invoice headers to database");
			$saveResult = $invoiceSync->saveInvoiceHeaders($invoiceHeaders);

			if ($saveResult === false) {
				// Error saving headers
				setEventMessages($langs->trans("ErrorSavingInvoiceHeaders"), null, 'errors');
				return $this->cronError($operation, "Failed to save invoice headers", ['date' => $initialDate]);
			}

			$this->logDebug($operation, "Invoice headers saved to database", [
				'date' => $initialDate,
				'count' => $headerCount
			]);

			// Step 3: Synchronize ALL unsynchronized invoices for yesterday WITHOUT LIMIT
			$this->logDebug($operation, "Step 3: Synchronizing ALL invoices for yesterday (no limit)");
			$syncResult = $zoneSoftInvoices->synchronizeAllUnsynchronizedInvoicesForDate($yesterdayDate);

			if ($syncResult === 0) {
				// Success message
				setEventMessages($langs->trans("SynchronizationConcluded"), null, 'mesgs');
				return $this->cronSuccess($operation, [
					'date' => $initialDate,
					'headers_synced' => $headerCount,
					'note' => 'All invoices processed without limit'
				]);
			} else {
				// Partial success or failure
				// The method already sets event messages, so just log
				return $this->cronError($operation, "Some invoices failed to synchronize", [
					'date' => $initialDate,
					'result_code' => $syncResult
				]);
			}
		} else {
			// No invoices were found - this is not an error, just info
			setEventMessages($langs->trans("NoInvoicesFound"), null, 'warnings');
			$this->logOperationSuccess($operation, [
				'date' => $initialDate,
				'message' => 'No invoices to synchronize'
			]);
			return 0; // Return success - no invoices is not an error
		}
	}

	/**
	 * Synchronize invoice headers between dates
	 *
	 * This method fetches invoice headers within a date range, saves them to the database,
	 * and then synchronizes them to create invoices in Dolibarr.
	 *
	 * @param string $startDate Start date in Y-m-d format
	 * @param string $endDate End date in Y-m-d format
	 * @return bool True on success, false on failure
	 */
	public function synchronizeInvoiceHeadersByDate($startDate, $endDate)
	{

		global $db, $langs;

		// Validate dates
		if (empty($startDate) || empty($endDate)) {
			dol_syslog("Invalid date parameters", LOG_ERR);
			return false;
		}

		// Fetch invoice headers within date range
		dol_syslog("Fetching invoice headers from Zone Soft for date range: $startDate to $endDate", LOG_INFO);
		$invoiceHeaders = $this->fetchInvoiceHeadersWithinDateRange($startDate, $endDate, null);

		if ($invoiceHeaders === false) {
			dol_syslog("Failed to fetch invoice headers", LOG_ERR);
			return false;
		}

		// Count headers
		$synchedCount = 0;
		if (isset($invoiceHeaders->document) && is_array($invoiceHeaders->document)) {
			$synchedCount = count($invoiceHeaders->document);
		}

		if ($synchedCount == 0) {
			dol_syslog("No invoice headers found for date range: $startDate to $endDate", LOG_INFO);
			return 0;
		}

		// Save invoice headers to database
		$result = $this->saveInvoiceHeaders($invoiceHeaders);
		if (!$result) {
			dol_syslog("Failed to save invoice headers to database", LOG_ERR);
			return false;
		}

		dol_syslog("Invoice headers synchronized successfully: $synchedCount headers for date range: $startDate to $endDate", LOG_INFO);
		return $synchedCount;
	}

	/**
	 * Synchronize a specific document by exact criteria
	 *
	 * This method fetches a specific invoice header from ZoneSoft by exact document details,
	 * saves it to the database, and then synchronizes it to create the invoice in Dolibarr.
	 *
	 * @param string $date Document date in Y-m-d format
	 * @param string $docType Document type (FS, FT, etc.)
	 * @param string $serie Document serie
	 * @param int $numero Document number
	 * @return bool True on success, false on failure
	 */
	public function syncSpecificDocument($date, $docType, $serie, $numero, $replaceExisting = false)
	{
		global $db, $conf, $langs;

		$operation = __METHOD__;
		$store = $conf->global->ZS_API_STORE;
		$docType = dolizsynch_normalize_zsbms_document_type($docType);
		$invoiceRef = "{$docType}_{$serie}_{$numero}_" . date('Ymd', strtotime($date));

		$this->logOperationStart($operation, [
			'date' => $date,
			'doc_type' => $docType,
			'serie' => $serie,
			'numero' => $numero,
			'store' => $store,
			'invoice_ref' => $invoiceRef,
			'replace_existing' => !empty($replaceExisting) ? 1 : 0
		]);

		if ($this->isExcludedZsbmsDocumentType($docType)) {
			$this->error = "Document type {$docType} is excluded from invoice synchronization";
			$this->logOperationWarning($operation, $this->error, [
				'doc_type' => $docType,
				'serie' => $serie,
				'numero' => $numero,
				'date' => $date,
				'config_key' => 'DOLIZSYNCH_EXCLUDED_ZSBMS_DOCUMENT_TYPES'
			]);
			setEventMessages($this->error, null, 'warnings');
			return false;
		}

		// Step 1: Fetch the specific invoice header from ZoneSoft
		dol_syslog("Fetching specific invoice header from ZoneSoft: $invoiceRef", LOG_INFO);

		$this->zoneSoftApi->setInterface('documents');

		$payload = [
			"document" => [
				"doc" => $docType,
				"serie" => $serie,
				"numero" => $numero,
				"data" => $date,
			]
		];

		try {
			$response = json_decode(json_encode($this->zoneSoftApi->execute('getInstance', $payload)));

			if (!$response || !isset($response->Response) || $response->Response->StatusCode != 200) {
				$statusCode = isset($response->Response->StatusCode) ? $response->Response->StatusCode : 'unknown';
				$this->logOperationError($operation, "Failed to fetch document from ZoneSoft", [
					'invoice_ref' => $invoiceRef,
					'status_code' => $statusCode
				]);

				setEventMessages($langs->trans("DocumentNotFoundInZoneSoft"), null, 'errors');
				return false;
			}

			$documentHeader = $response->Response->Content->document;

			// Verify date matches (critical - same numero can exist on different dates)
			if ($documentHeader->data != $date) {
				$this->logOperationError($operation, "Date mismatch - different documents", [
					'invoice_ref' => $invoiceRef,
					'expected_date' => $date,
					'actual_date' => $documentHeader->data
				]);

				setEventMessages($langs->trans("DocumentDateMismatch"), null, 'errors');
				return false;
			}

			$this->logDebug($operation, "Document header fetched successfully", [
				'invoice_ref' => $invoiceRef
			]);

			// Step 2: Save/reset the sync record
			if (!empty($replaceExisting)) {
				$saveResult = $this->replaceSpecificDocumentSyncRecord($documentHeader, $invoiceRef, $date);
			} else {
				$headersToSave = (object) [
					'document' => [$documentHeader]
				];
				$saveResult = $this->saveInvoiceHeaders($headersToSave);
			}

			if ($saveResult === false) {
				$this->logOperationError($operation, "Failed to save document header to database", [
					'invoice_ref' => $invoiceRef
				]);

				$message = !empty($this->error) ? $this->error : $langs->trans("ErrorSavingInvoiceHeaders");
				setEventMessages($message, null, 'errors');
				return false;
			}

			$this->logDebug($operation, "Document header saved to database", [
				'invoice_ref' => $invoiceRef
			]);

			// Step 3: Fetch the saved record and synchronize it
			$obj = $this->fetchSpecificDocumentSyncRow($invoiceRef, $store);

			if (!$obj) {
				$this->logOperationError($operation, "Document not found in sync table after save", [
					'invoice_ref' => $invoiceRef
				]);

				setEventMessages($langs->trans("DocumentNotFoundAfterSave"), null, 'errors');
				return false;
			}

			if (empty($replaceExisting) && (int) ($obj->fk_facture ?? 0) > 0 && (int) ($obj->synch ?? 0) === ZSSyncStatus::OK) {
				$message = $langs->trans("DocumentAlreadySynchronizedUseReplace");
				$this->error = $message;
				$this->logOperationWarning($operation, "Document already synchronized, replace option required", [
					'invoice_ref' => $invoiceRef,
					'fk_facture' => (int) $obj->fk_facture
				]);
				setEventMessages($message, null, 'errors');
				return false;
			}

			// Step 4: Synchronize the invoice
			$this->logDebug($operation, "Starting document synchronization", [
				'invoice_ref' => $invoiceRef,
				'sync_status' => $obj->synch
			]);

			$syncResult = $this->synchronizeLatestUnsynchronisedInvoice($obj);

			if ($syncResult) {
				$this->logOperationSuccess($operation, [
					'invoice_ref' => $invoiceRef,
					'message' => 'Document synchronized successfully'
				]);

				setEventMessages($langs->trans("DocumentSyncSuccess"), null, 'mesgs');
				return true;
			} else {
				$this->logOperationError($operation, "Failed to synchronize document", [
					'invoice_ref' => $invoiceRef
				]);

				$message = !empty($this->error) ? $this->error : $langs->trans("DocumentSyncFailed");
				setEventMessages($message, null, 'errors');
				return false;
			}
		} catch (Exception $e) {
			$this->logOperationError($operation, "Exception occurred", [
				'invoice_ref' => $invoiceRef,
				'exception_message' => $e->getMessage(),
				'exception_class' => get_class($e)
			]);

			setEventMessages($langs->trans("DocumentSyncException") . ": " . $e->getMessage(), null, 'errors');
			return false;
		}
	}

	/**
	 * Fetch one sync row for the requested invoice reference and current entity.
	 *
	 * @param string $invoiceRef Invoice reference
	 * @param int|string|null $store Optional store filter
	 * @return object|null
	 */
	private function fetchSpecificDocumentSyncRow($invoiceRef, $store = null)
	{
		global $db, $conf;

		$sql = "SELECT * FROM " . MAIN_DB_PREFIX . "dolizsynch_zsinvoicesynch";
		$sql .= " WHERE invoice_ref = '" . $db->escape($invoiceRef) . "'";
		$sql .= " AND entity = " . ((int) $conf->entity);
		if ($store !== null && $store !== '') {
			$sql .= " AND loja_zs = " . ((int) $store);
		}
		$sql .= " ORDER BY rowid DESC";
		$sql .= " LIMIT 1";

		$resql = $db->query($sql);
		if (!$resql) {
			$this->logSqlError(__METHOD__, $db, [
				'invoice_ref' => $invoiceRef,
				'store' => $store
			]);
			return null;
		}

		$obj = $db->fetch_object($resql);
		return $obj ?: null;
	}

	/**
	 * Reset one sync record and mark it for replacement so the document can be rebuilt safely.
	 *
	 * @param object $documentHeader Zone Soft document payload
	 * @param string $invoiceRef Invoice reference
	 * @param string|null $invoiceDate Invoice date in Y-m-d format
	 * @return bool
	 */
	private function replaceSpecificDocumentSyncRecord($documentHeader, $invoiceRef, $invoiceDate = null)
	{
		global $db, $user, $langs, $conf;

		$operation = __METHOD__;
		$actor = ($user instanceof User) ? $user : null;
		$resolvedActor = $this->resolveInvoiceSyncActorUser($operation);
		if ($resolvedActor instanceof User) {
			$actor = $resolvedActor;
		}
		$actorId = ($actor instanceof User && !empty($actor->id)) ? (int) $actor->id : 0;
		if ($actorId <= 0) {
			$this->error = $langs->trans("DocumentReplaceSyncRowFailed");
			$this->logOperationError($operation, "No valid actor user resolved for document replacement", [
				'invoice_ref' => $invoiceRef,
				'entity' => (int) $conf->entity
			]);
			return false;
		}

		if (!$this->acquireInvoiceRefLock($invoiceRef, 5)) {
			$this->error = $langs->trans("DocumentReplaceBusy");
			$this->logOperationWarning($operation, "Could not acquire invoice replacement lock", [
				'invoice_ref' => $invoiceRef
			]);
			return false;
		}

		try {
			$replacementContext = $this->prepareExistingInvoiceForSpecificDocumentReplacement($invoiceRef, $invoiceDate);
			if ($replacementContext === false) {
				return false;
			}

			$replacementFactureId = (int) ($replacementContext['existing_facture_id'] ?? 0);
			$replacementMode = !empty($replacementContext['replacement_mode']) ? (string) $replacementContext['replacement_mode'] : 'create';
			$syncContext = array(
				'replace_existing' => 1,
				'replacement_mode' => $replacementMode,
				'existing_facture_id' => $replacementFactureId,
				'requested_at' => dol_print_date(dol_now(), 'dayhourlog')
			);

			$existingSyncRow = $this->fetchSpecificDocumentSyncRow($invoiceRef);
			if ($existingSyncRow) {
				$syncRow = new self($db);
				if ($syncRow->fetch((int) $existingSyncRow->rowid) <= 0) {
					$this->error = $langs->trans("DocumentReplaceSyncRowFailed");
					$this->logOperationError($operation, "Failed to fetch existing sync row for replacement", [
						'invoice_ref' => $invoiceRef,
						'rowid' => (int) $existingSyncRow->rowid
					]);
					return false;
				}

				$existingPeopleCount = $syncRow->pessoas_zs;
				$incomingPeopleCount = $this->resolveInvoicePeopleCount($documentHeader);
				$syncRow->setInvoiceData($documentHeader, $invoiceRef);
				if ($incomingPeopleCount === null) {
					$syncRow->pessoas_zs = ($existingPeopleCount === null || $existingPeopleCount === '') ? 1 : $existingPeopleCount;
				}
				$syncRow->fk_facture = $replacementFactureId;
				$syncRow->credit_ref = 0;
				$syncRow->synch = ZSSyncStatus::NOT_SYNCHRONIZED;
				$syncRow->sync_context = json_encode($syncContext);

				$syncRow->fk_user_modif = $actorId;
				if ($syncRow->update($actor) < 0) {
					$this->error = $langs->trans("DocumentReplaceSyncRowFailed");
					$this->logOperationError($operation, "Failed to update sync row for replacement", [
						'invoice_ref' => $invoiceRef,
						'rowid' => (int) $existingSyncRow->rowid,
						'error' => $syncRow->error
					]);
					return false;
				}

				$sql = "UPDATE " . MAIN_DB_PREFIX . "dolizsynch_zsinvoicesynch";
				$sql .= " SET stock_reversed = 0, error_message = NULL, sync_context = '" . $db->escape(json_encode($syncContext)) . "', date_modification = NOW()";
				$sql .= " WHERE rowid = " . ((int) $existingSyncRow->rowid);
				$sql .= " AND entity = " . ((int) $conf->entity);
				if (!$db->query($sql)) {
					$this->error = $langs->trans("DocumentReplaceSyncRowFailed");
					$this->logSqlError($operation, $db, [
						'invoice_ref' => $invoiceRef,
						'rowid' => (int) $existingSyncRow->rowid,
						'operation' => 'clear_extra_sync_metadata'
					]);
					return false;
				}
			} else {
				$syncRow = new self($db);
				$syncRow->setInvoiceData($documentHeader, $invoiceRef);
				$syncRow->fk_facture = $replacementFactureId;
				$syncRow->credit_ref = 0;
				$syncRow->synch = ZSSyncStatus::NOT_SYNCHRONIZED;
				$syncRow->sync_context = json_encode($syncContext);
				$syncRow->fk_user_creat = $actorId;
				$syncRow->fk_user_modif = $actorId;

				if ($syncRow->create($actor) <= 0) {
					$this->error = $langs->trans("DocumentReplaceSyncRowFailed");
					$this->logOperationError($operation, "Failed to create sync row for replacement", [
						'invoice_ref' => $invoiceRef,
						'error' => $syncRow->error
					]);
					return false;
				}
			}

			$this->logOperationSuccess($operation, [
				'invoice_ref' => $invoiceRef
			]);

			return true;
		} finally {
			$this->releaseInvoiceRefLock($invoiceRef);
		}
	}

	/**
	 * Prepare the currently linked Dolibarr invoice for a forced reimport.
	 *
	 * @param string $invoiceRef Invoice reference
	 * @param string|null $invoiceDate Invoice date in Y-m-d format
	 * @return array|false
	 */
	private function prepareExistingInvoiceForSpecificDocumentReplacement($invoiceRef, $invoiceDate = null)
	{
		global $db, $langs, $conf;

		$operation = __METHOD__;
		$existingSyncRow = $this->fetchSpecificDocumentSyncRow($invoiceRef);

		if ($existingSyncRow && (int) ($existingSyncRow->anulado_zs ?? 0) === 1) {
			$this->error = $langs->trans("DocumentReplaceBlockedCanceled");
			$this->logOperationWarning($operation, "Replace blocked for canceled document", [
				'invoice_ref' => $invoiceRef
			]);
			return false;
		}

		$existingFactureId = 0;
		if ($existingSyncRow && (int) ($existingSyncRow->fk_facture ?? 0) > 0) {
			$existingFactureId = (int) $existingSyncRow->fk_facture;
		} else {
			$existingFactureId = (int) $this->isInvoiceExists($invoiceRef, $invoiceDate);
		}

		if ($existingFactureId <= 0) {
			return array(
				'existing_facture_id' => 0,
				'replacement_mode' => 'create'
			);
		}

		$invoice = new Facture($db);
		if ($invoice->fetch($existingFactureId) <= 0) {
			$this->logOperationWarning($operation, "Linked invoice does not exist anymore, continuing with sync reset", [
				'invoice_ref' => $invoiceRef,
				'fk_facture' => $existingFactureId
			]);
			return array(
				'existing_facture_id' => 0,
				'replacement_mode' => 'create'
			);
		}

		if (isset($invoice->entity) && (int) $invoice->entity !== (int) $conf->entity) {
			$this->error = $langs->trans("DocumentReplaceWrongEntity");
			$this->logOperationError($operation, "Linked invoice belongs to another entity", [
				'invoice_ref' => $invoiceRef,
				'fk_facture' => $existingFactureId,
				'invoice_entity' => (int) $invoice->entity,
				'current_entity' => (int) $conf->entity
			]);
			return false;
		}

		if ((int) $invoice->status === Facture::STATUS_ABANDONED) {
			$this->error = $langs->trans("DocumentReplaceBlockedCanceled");
			$this->logOperationWarning($operation, "Replace blocked because invoice is already canceled", [
				'invoice_ref' => $invoiceRef,
				'fk_facture' => $existingFactureId
			]);
			return false;
		}

		if ($this->hasLinkedCreditNoteForInvoice($existingFactureId)) {
			$this->error = $langs->trans("DocumentReplaceBlockedCreditNote");
			$this->logOperationWarning($operation, "Replace blocked because linked credit note exists", [
				'invoice_ref' => $invoiceRef,
				'fk_facture' => $existingFactureId
			]);
			return false;
		}

		$sharedPaymentIds = array();
		if ($this->hasSharedPaymentsForInvoice($existingFactureId, $sharedPaymentIds)) {
			$this->error = $langs->trans("DocumentReplaceBlockedSharedPayments", implode(', ', $sharedPaymentIds));
			$this->logOperationWarning($operation, "Replace blocked because payment is shared with other invoices", [
				'invoice_ref' => $invoiceRef,
				'fk_facture' => $existingFactureId,
				'shared_payment_ids' => $sharedPaymentIds
			]);
			return false;
		}

		$this->logOperationSuccess($operation, [
			'invoice_ref' => $invoiceRef,
			'resolved_facture_id' => $existingFactureId,
			'replacement_mode' => 'rebuild'
		]);

		return array(
			'existing_facture_id' => $existingFactureId,
			'replacement_mode' => 'rebuild'
		);
	}

	/**
	 * Delete all lines from an existing draft invoice before rebuilding it from Zone Soft data.
	 *
	 * @param Facture $invoice Draft invoice object
	 * @param string $invoiceRef External invoice reference
	 * @return bool
	 */
	private function clearExistingDraftInvoiceLines($invoice, $invoiceRef)
	{
		global $langs;

		$operation = __METHOD__;

		if ((int) $invoice->status !== Facture::STATUS_DRAFT) {
			$this->error = $langs->trans("DocumentReplaceDeleteExistingFailed", $langs->trans("Error"));
			$this->logOperationError($operation, "Invoice must be draft before line purge", [
				'invoice_ref' => $invoiceRef,
				'fk_facture' => (int) $invoice->id,
				'status' => (int) $invoice->status
			]);
			return false;
		}

		$invoice->fetch_lines();
		$lineIndexes = array_reverse(array_keys((array) $invoice->lines));
		foreach ($lineIndexes as $lineIndex) {
			$line = $invoice->lines[$lineIndex];
			$lineId = (int) ($line->id ?? $line->rowid ?? 0);
			if ($lineId <= 0) {
				continue;
			}

			if ($invoice->deleteLine($lineId, $invoice->id) < 0) {
				$message = !empty($invoice->error) ? $invoice->error : $langs->trans("Error");
				$this->error = $langs->trans("DocumentReplaceDeleteExistingFailed", $message);
				$this->logOperationError($operation, "Failed to delete invoice line during replacement rebuild", [
					'invoice_ref' => $invoiceRef,
					'fk_facture' => (int) $invoice->id,
					'line_id' => $lineId,
					'error' => $message
				]);
				return false;
			}
		}

		if ($invoice->fetch($invoice->id) <= 0) {
			$this->error = $langs->trans("DocumentReplaceDeleteExistingFailed", $langs->trans("DocumentReplaceReloadFailed"));
			$this->logOperationError($operation, "Failed to reload invoice after line purge", [
				'invoice_ref' => $invoiceRef,
				'fk_facture' => (int) $invoice->id
			]);
			return false;
		}

		$invoice->fetch_lines();
		return true;
	}

	/**
	 * Decode sync_context for a sync row.
	 *
	 * @param object $row Sync row
	 * @return array
	 */
	private function getSyncContextData($row)
	{
		if (!isset($row->sync_context) || $row->sync_context === null || $row->sync_context === '') {
			return array();
		}

		if (is_array($row->sync_context)) {
			return $row->sync_context;
		}

		$decoded = json_decode((string) $row->sync_context, true);
		return is_array($decoded) ? $decoded : array();
	}

	/**
	 * Translate Dolibarr invoice erase refusal codes into explicit admin messages.
	 *
	 * @param Facture $invoice Invoice object
	 * @param int $eraseStatus Result of Facture::is_erasable()
	 * @return string
	 */
	private function getReplaceDeleteRefusalMessage($invoice, $eraseStatus)
	{
		global $langs;

		switch ((int) $eraseStatus) {
			case 0:
				return $langs->trans("DocumentReplaceBlockedDeleteDisabled");
			case -1:
				return $langs->trans("DocumentReplaceBlockedAccountingExport");
			case -2:
				return $langs->trans("DocumentReplaceBlockedNumbering", (string) ($invoice->ref ?? ''));
			case -3:
				return $langs->trans("DocumentReplaceBlockedSituationCycle");
			case -4:
				return $langs->trans("DocumentReplaceBlockedResidualPayments");
			default:
				return $langs->trans("DocumentReplaceBlockedUnknownDelete", (int) $eraseStatus);
		}
	}

	/**
	 * Check whether an invoice has a generated credit note linked to it.
	 *
	 * @param int $invoiceId Invoice ID
	 * @return bool
	 */
	private function hasLinkedCreditNoteForInvoice($invoiceId)
	{
		global $db, $conf;

		$sql = "SELECT rowid FROM " . MAIN_DB_PREFIX . "facture";
		$sql .= " WHERE fk_facture_source = " . ((int) $invoiceId);
		$sql .= " AND entity = " . ((int) $conf->entity);
		$sql .= " LIMIT 1";

		$resql = $db->query($sql);
		if (!$resql) {
			$this->logSqlError(__METHOD__, $db, [
				'invoice_id' => $invoiceId
			]);
			return true;
		}

		return ($db->num_rows($resql) > 0);
	}

	/**
	 * Check whether one invoice has payment records shared with other invoices.
	 *
	 * @param int $invoiceId Invoice ID
	 * @param array<int,int|string> $sharedPaymentIds Output list of shared payment IDs
	 * @return bool
	 */
	private function hasSharedPaymentsForInvoice($invoiceId, array &$sharedPaymentIds = array())
	{
		global $db;

		$sharedPaymentIds = array();

		$sql = "SELECT pf.fk_paiement, COUNT(DISTINCT pf2.fk_facture) AS invoice_links";
		$sql .= " FROM " . MAIN_DB_PREFIX . "paiement_facture AS pf";
		$sql .= " INNER JOIN " . MAIN_DB_PREFIX . "paiement_facture AS pf2 ON pf2.fk_paiement = pf.fk_paiement";
		$sql .= " WHERE pf.fk_facture = " . ((int) $invoiceId);
		$sql .= " GROUP BY pf.fk_paiement";
		$sql .= " HAVING COUNT(DISTINCT pf2.fk_facture) > 1";

		$resql = $db->query($sql);
		if (!$resql) {
			$this->logSqlError(__METHOD__, $db, [
				'invoice_id' => $invoiceId
			]);
			return true;
		}

		while ($obj = $db->fetch_object($resql)) {
			$sharedPaymentIds[] = (int) $obj->fk_paiement;
		}

		return !empty($sharedPaymentIds);
	}



















	/************************************************ OTHERS

	/**
	 * Create object into database
	 *
	 * @param  User $user      User that creates
	 * @param  bool $notrigger false=launch triggers after, true=disable triggers
	 * @return int             <0 if KO, Id of created object if OK
	 */
	public function create(User $user, $notrigger = false)
	{

		$resultcreate = $this->createCommon($user, $notrigger);

		//$resultvalidate = $this->validate($user, $notrigger);

		return $resultcreate;
	}

	/**
	 * Load object in memory from the database
	 *
	 * @param int    $id   Id object
	 * @param string $ref  Ref
	 * @return int         <0 if KO, 0 if not found, >0 if OK
	 */
	public function fetch($id, $ref = null)
	{

		$result = $this->fetchCommon($id, $ref);
		if ($result > 0 && !empty($this->table_element_line)) {
			$this->fetchLines();
		}
		return $result;
	}

	/**
	 * Load object lines in memory from the database
	 *
	 * @return int         <0 if KO, 0 if not found, >0 if OK
	 */
	public function fetchLines()
	{

		$this->lines = array();

		$result = $this->fetchLinesCommon();
		return $result;
	}


	/**
	 * Load list of objects in memory from the database.
	 *
	 * @param  string      $sortorder    Sort Order
	 * @param  string      $sortfield    Sort field
	 * @param  int         $limit        limit
	 * @param  int         $offset       Offset
	 * @param  array       $filter       Filter array. Example array('field'=>'valueforlike', 'customurl'=>...)
	 * @param  string      $filtermode   Filter mode (AND or OR)
	 * @return array|int                 int <0 if KO, array of pages if OK
	 */
	public function fetchAll($sortorder = '', $sortfield = '', $limit = 0, $offset = 0, array $filter = array(), $filtermode = 'AND')
	{

		$records = array();

		$sql = "SELECT ";
		$sql .= $this->getFieldList('t');
		$sql .= " FROM " . $this->db->prefix() . $this->table_element . " as t";
		if (isset($this->ismultientitymanaged) && $this->ismultientitymanaged == 1) {
			$sql .= " WHERE t.entity IN (" . getEntity($this->element) . ")";
		} else {
			$sql .= " WHERE 1 = 1";
		}
		// Manage filter
		$sqlwhere = array();
		if (count($filter) > 0) {
			foreach ($filter as $key => $value) {
				if ($key == 't.rowid') {
					$sqlwhere[] = $key . " = " . ((int) $value);
				} elseif (in_array($this->fields[$key]['type'], array('date', 'datetime', 'timestamp'))) {
					$sqlwhere[] = $key . " = '" . $this->db->idate($value) . "'";
				} elseif ($key == 'customsql') {
					$sqlwhere[] = $value;
				} elseif (strpos($value, '%') === false) {
					$sqlwhere[] = $key . " IN (" . $this->db->sanitize($this->db->escape($value)) . ")";
				} else {
					$sqlwhere[] = $key . " LIKE '%" . $this->db->escapeforlike($this->db->escape($value)) . "%'";
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

			return false;
		}
	}

	/**
	 * Update object into database
	 *
	 * @param  User $user      User that modifies
	 * @param  bool $notrigger false=launch triggers after, true=disable triggers
	 * @return int             <0 if KO, >0 if OK
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
	 * @return int             <0 if KO, >0 if OK
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
	 * Action executed by scheduler
	 * CAN BE A CRON TASK. In such a case, parameters come from the schedule job setup field 'Parameters'
	 * Use public function doScheduledJob($param1, $param2, ...) to get parameters
	 *
	 * @return	int			0 if OK, <>0 if KO (this function is used also by cron so only 0 is OK)
	 */
	public function doScheduledJob()
	{

		//global $conf, $langs;

		//$conf->global->SYSLOG_FILE = 'DOL_DATA_ROOT/dolibarr_mydedicatedlofile.log';

		$error = 0;
		$this->output = '';
		$this->error = '';


		$now = dol_now();

		$this->db->begin();

		// ...

		$this->db->commit();

		return $error;
	}


	//////////////// Test

	public function fetchInvoiceHeadersTest()
	{

		// Path to your JSON file (relative path)
		$jsonFilePath = __DIR__ . '/headers.json';

		// Check if file exists and is readable
		if (!file_exists($jsonFilePath) || !is_readable($jsonFilePath)) {
			dol_syslog("Error: File not found or not readable - " . $jsonFilePath, LOG_ERR);
			return false;
		}

		// Read the JSON file
		$jsonContent = file_get_contents($jsonFilePath);

		// Check for errors in file_get_contents
		if ($jsonContent === false) {
			dol_syslog("Error: Unable to read the file - " . $jsonFilePath, LOG_ERR);
			return false;
		}

		// Keep test logging bounded to avoid large memory usage in syslog backend.
		dol_syslog("Processing invoice test payload: file=" . $jsonFilePath . " bytes=" . strlen($jsonContent), LOG_INFO);

		// Decode JSON content
		$decodedJson = json_decode($jsonContent, true);

		return $decodedJson;
	}

	public function readInvoiceDataFromFile($filename)
	{
		if (!file_exists($filename)) {
			throw new ZSValidationException("File does not exist: $filename");
		}

		$content = file_get_contents($filename);
		return json_decode($content);
	}

	// ========== IMPROVED ERROR HANDLING METHODS ==========






	// ========== IMPROVED STATE MANAGEMENT METHODS ==========

	/**
	 * Get synchronization statistics
	 *
	 * @return array Statistics array with counts by status and category
	 */
	public function getSyncStatistics()
	{
		try {
			return $this->syncManager->getSyncStatistics();
		} catch (Exception $e) {
			throw new Exception("Failed to get sync statistics", 0, $e);
		}
	}

	/**
	 * Get records by synchronization status
	 *
	 * @param array $statuses Array of status codes to filter by
	 * @param int $limit Maximum number of records to return
	 * @param int $offset Offset for pagination
	 * @return array Array of records
	 */
	public function getRecordsByStatus(array $statuses, $limit = 100, $offset = 0)
	{
		try {
			return $this->syncManager->getRecordsByStatus($statuses, $limit, $offset);
		} catch (Exception $e) {
			throw new Exception("Failed to get records by status", 0, $e);
		}
	}

	/**
	 * Reset failed synchronization statuses back to pending for retry
	 *
	 * @param array|null $specificStatuses Specific statuses to reset, or null for all retryable
	 * @return int Number of records reset
	 */
	public function resetFailedStatuses($specificStatuses = null)
	{
		try {
			$count = $this->syncManager->resetFailedStatuses($specificStatuses);
			dol_syslog("Reset $count failed synchronization records", LOG_INFO);
			return $count;
		} catch (Exception $e) {
			throw new Exception("Failed to reset failed statuses", 0, $e);
		}
	}

	/**
	 * Clean up stuck processing records
	 *
	 * @param int $timeoutMinutes Records marked as processing for longer than this will be reset
	 * @return int Number of records cleaned up
	 */
	public function cleanupStuckProcessing($timeoutMinutes = 30)
	{
		try {
			$count = $this->syncManager->cleanupStuckProcessing($timeoutMinutes);
			if ($count > 0) {
				dol_syslog("Cleaned up $count stuck processing records", LOG_WARNING);
			}
			return $count;
		} catch (Exception $e) {
			throw new Exception("Failed to cleanup stuck processing", 0, $e);
		}
	}

	/**
	 * Batch synchronize invoices with proper state management
	 *
	 * @param int $batchSize Number of records to process in one batch
	 * @param array|null $statusFilter Specific statuses to process, or null for all synchronizable
	 * @return array Results summary
	 */
	public function batchSynchronizeInvoices($batchSize = 50, $statusFilter = null)
	{
		$statuses = $statusFilter ?? ZSSyncStatus::getSynchronizableStatuses();
		$results = [
			'processed' => 0,
			'successful' => 0,
			'failed' => 0,
			'errors' => []
		];

		try {
			// Clean up any stuck processing records first
			$this->cleanupStuckProcessing();

			// Get records to process
			$records = $this->getRecordsByStatus($statuses, $batchSize);

			foreach ($records as $record) {
				try {
					// Mark as processing to prevent concurrent processing
					if (!$this->syncManager->markAsProcessing($record->invoice_ref)) {
						continue; // Skip if already being processed
					}

					$success = $this->synchronizeAllUnsynchronizedInvoicesByStore();
					$results['processed']++;

					if ($success) {
						$results['successful']++;
					} else {
						$results['failed']++;
					}
				} catch (Exception $e) {
					$results['processed']++;
					$results['failed']++;
					$results['errors'][] = [
						'invoice_ref' => $record->invoice_ref ?? 'unknown',
						'error' => $e->getMessage()
					];

					dol_syslog("Error synchronizing invoice: " . $e->getMessage(), LOG_ERR);
				}
			}

			dol_syslog("Batch synchronization completed: {$results['successful']} successful, {$results['failed']} failed out of {$results['processed']} processed", LOG_INFO);
		} catch (Exception $e) {
			$results['errors'][] = [
				'general_error' => $e->getMessage()
			];
			throw $e;
		}

		return $results;
	}

	/**
	 * Get invoice synchronization status with full details
	 *
	 * @param string $invoiceRef Invoice reference
	 * @return array|null Status information or null if not found
	 */
	public function getInvoiceSyncStatus($invoiceRef)
	{
		try {
			return $this->syncManager->getSyncStatus($invoiceRef);
		} catch (Exception $e) {
			throw new Exception("Failed to get sync status for invoice $invoiceRef", 0, $e);
		}
	}

	/**
	 * Handle duplicate invoice detection with comprehensive error handling
	 *
	 * @param string $invoiceRef Invoice reference
	 * @param int $existingFactureId Existing facture ID
	 * @param array $mappedInvoice Validated source monetary plan and invoice data
	 * @return int|false Returns existing facture ID if duplicate handled successfully, false on error
	 */
	private function handleDuplicateInvoice($invoiceRef, $existingFactureId, array $mappedInvoice)
	{
		global $conf, $user;

		try {
			// Log the duplicate invoice detection as INFO (not an error)
			dol_syslog("ZSInvoiceSynch: Duplicate invoice detected - invoice already exists in Dolibarr: $invoiceRef (facture_id: $existingFactureId)", LOG_INFO);

			// Load the existing invoice to check its status
			$facture = new Facture($this->db);
			if ($facture->fetch($existingFactureId) <= 0) {
				dol_syslog("Error fetching existing invoice: " . $facture->error, LOG_ERR);
				return false;
			}
			if ((int) $facture->entity !== (int) $conf->entity || empty($mappedInvoice['monetary_plan']['lines'])) {
				$this->error = 'Existing invoice has no valid entity-scoped source monetary plan';
				return false;
			}
			$wasDraft = (int) $facture->statut === Facture::STATUS_DRAFT;

			// Check if invoice is still in draft status (status 0 = draft/PROV)
			if ($facture->statut == Facture::STATUS_DRAFT) {
				dol_syslog("Existing invoice is in draft status - attempting validation: $invoiceRef", LOG_INFO);

				// Validate the invoice with warehouse
				$defaultWarehouseId = (int) ($conf->global->MAIN_DEFAULT_WAREHOUSE ?? -1);
				if (!$this->reconcileCustomerInvoiceTotals($facture, $mappedInvoice)
					|| !$this->validateInvoiceWithMonetaryPlan($facture, $user, $defaultWarehouseId, $invoiceRef, $mappedInvoice['monetary_plan'])) {
					dol_syslog("Error validating existing draft invoice: " . $facture->error, LOG_ERR);
					$this->setSyncStatus($invoiceRef, ZSSyncStatus::FAILED, $this->error ?: $facture->error);
					return false;
				}

				dol_syslog("Successfully validated existing draft invoice: $invoiceRef", LOG_INFO);
			}

			$sign = (int) $facture->type === Facture::TYPE_CREDIT_NOTE ? -1 : 1;
			if (!$this->verifyStoredCustomerInvoiceConsistency($facture)
				|| !$this->verifyCustomerInvoiceMonetaryPlan($facture, $mappedInvoice['monetary_plan'], $sign)) {
				$this->setSyncStatus($invoiceRef, ZSSyncStatus::FAILED, $this->error);
				return false;
			}

			// Update sync status to mark as successfully synchronized with existing invoice
			$updateResult = $this->syncManager->setSyncStatus(
				$invoiceRef,
				ZSSyncStatus::OK,
				null,
				[
					'facture_id' => $existingFactureId,
					'existing_facture_id' => $existingFactureId,
					'duplicate_handled' => true,
					'action' => 'duplicate_detection',
					'was_validated' => $wasDraft
				]
			);

			if (!$updateResult) {
				// Old handleDatabaseError call removed - error handling simplified
				return false;
			}

			// Return the existing facture ID to indicate successful handling of duplicate
			return $existingFactureId;
		} catch (Exception $e) {
			// Old handleError call removed - error handling simplified
			return false;
		}
	}

	/**
	 * Create invoice with comprehensive validation and error handling
	 *
	 * @param Facture $facture Facture object
	 * @param array $dolibarrInvoice Invoice data
	 * @return bool Success status
	 */
	private function createInvoiceWithValidation($facture, $dolibarrInvoice)
	{
		try {
			if (!$this->createInvoice($facture, $dolibarrInvoice)) {
				$this->handleInvoiceCreationError(
					$dolibarrInvoice['array_options']['options_invoice_number'],
					new Exception($this->error),
					['stage' => 'invoice_creation', 'invoice_data' => $dolibarrInvoice],
					'createInvoice'
				);
				return false;
			}
			return true;
		} catch (Exception $e) {
			$this->handleInvoiceCreationError(
				$dolibarrInvoice['array_options']['options_invoice_number'],
				$e,
				['stage' => 'invoice_creation_validation', 'invoice_data' => $dolibarrInvoice],
				'createInvoiceWithValidation'
			);
			return false;
		}
	}

	/**
	 * Rebuild an existing draft invoice with fresh Zone Soft header and line data.
	 *
	 * @param Facture $facture Existing invoice already prepared for replacement
	 * @param array $dolibarrInvoice Mapped invoice payload
	 * @param int $warehouseId Default warehouse ID
	 * @return bool
	 */
	private function rebuildExistingInvoiceWithValidation($facture, array $dolibarrInvoice, $warehouseId)
	{
		global $user, $conf;

		$invoiceRef = $dolibarrInvoice['array_options']['options_invoice_number'] ?? '';
		$operation = __METHOD__;

		if (!$this->prepareExistingInvoiceForRebuild($facture, $invoiceRef)) {
			return false;
		}

		if ((int) $facture->status !== Facture::STATUS_DRAFT) {
			$message = 'Replacement target invoice must be in draft status before rebuild';
			$this->error = $message;
			$this->handleInvoiceCreationError(
				$invoiceRef,
				new Exception($message),
				[
					'stage' => 'replacement_rebuild_status',
					'facture_id' => $facture->id,
					'status' => (int) $facture->status
				],
				$operation
			);
			return false;
		}

		$this->applyMappedInvoiceHeaderToFacture($facture, $dolibarrInvoice);
		if ($facture->update($user) < 0) {
			$message = $facture->error ?: 'Failed to update invoice header during replacement';
			$this->error = $message;
			$this->handleInvoiceCreationError(
				$invoiceRef,
				new Exception($message),
				[
					'stage' => 'replacement_header_update',
					'facture_id' => $facture->id
				],
				$operation
			);
			return false;
		}

		if ($facture->fetch($facture->id) <= 0) {
			$message = $facture->error ?: 'Failed to reload invoice after replacement header update';
			$this->error = $message;
			$this->handleInvoiceCreationError(
				$invoiceRef,
				new Exception($message),
				[
					'stage' => 'replacement_reload_after_header',
					'facture_id' => $facture->id
				],
				$operation
			);
			return false;
		}

		$facture->fetch_lines();

		if (!$this->addInvoiceLinesWithValidation($facture, $dolibarrInvoice['lines'], $conf->global->ZS_IVA_INCLUIDO)) {
			return false;
		}

		if (!$this->reconcileCustomerInvoiceTotals($facture, $dolibarrInvoice)) {
			return false;
		}

		if (!$this->validateInvoiceWithMonetaryPlan(
			$facture,
			$user,
			$warehouseId,
			$invoiceRef,
			$dolibarrInvoice['monetary_plan']
		)) {
			return false;
		}

		return true;
	}

	/**
	 * Move an existing invoice back to a rebuildable draft state.
	 *
	 * @param Facture $invoice Existing invoice object
	 * @param string $invoiceRef External invoice reference
	 * @return bool
	 */
	private function prepareExistingInvoiceForRebuild($invoice, $invoiceRef)
	{
		global $user, $conf, $langs;

		$operation = __METHOD__;
		$warehouseId = !empty($conf->global->MAIN_DEFAULT_WAREHOUSE) ? (int) $conf->global->MAIN_DEFAULT_WAREHOUSE : -1;

		$invoice->fetch_lines();

		if ((int) $invoice->status !== Facture::STATUS_DRAFT) {
			if ($invoice->setDraft($user, $warehouseId) < 0) {
				$this->error = $langs->trans("DocumentReplaceDeleteExistingFailed", $invoice->error);
				$this->logOperationError($operation, "Failed to revert invoice to draft before rebuild", [
					'invoice_ref' => $invoiceRef,
					'fk_facture' => (int) $invoice->id,
					'error' => $invoice->error
				]);
				return false;
			}

			if ($invoice->fetch($invoice->id) <= 0) {
				$this->error = $langs->trans("DocumentReplaceDeleteExistingFailed", $langs->trans("DocumentReplaceReloadFailed"));
				$this->logOperationError($operation, "Failed to reload invoice after draft reset", [
					'invoice_ref' => $invoiceRef,
					'fk_facture' => (int) $invoice->id
				]);
				return false;
			}

			$invoice->fetch_lines();
		}

		if (!$this->removePaymentsFromInvoice($invoice)) {
			$message = !empty($this->error) ? $this->error : (!empty($invoice->error) ? $invoice->error : $langs->trans("Error"));
			$this->error = $langs->trans("DocumentReplaceDeleteExistingFailed", $message);
			$this->logOperationError($operation, "Failed to remove payments before rebuild", [
				'invoice_ref' => $invoiceRef,
				'fk_facture' => (int) $invoice->id,
				'error' => $message
			]);
			return false;
		}

		if ($invoice->fetch($invoice->id) <= 0) {
			$this->error = $langs->trans("DocumentReplaceDeleteExistingFailed", $langs->trans("DocumentReplaceReloadFailed"));
			$this->logOperationError($operation, "Failed to reload invoice after payment cleanup", [
				'invoice_ref' => $invoiceRef,
				'fk_facture' => (int) $invoice->id
			]);
			return false;
		}

		$invoice->fetch_lines();

		if (!$this->clearExistingDraftInvoiceLines($invoice, $invoiceRef)) {
			return false;
		}

		return true;
	}

	/**
	 * Normalize legacy Dolibarr invoice string fields before Facture::create().
	 *
	 * Dolibarr still trims deprecated aliases like ref_customer during create().
	 * Initializing them here avoids PHP 8.1+ null deprecation notices while keeping
	 * the external customer reference aligned with ref_client.
	 *
	 * @param Facture $facture Invoice object being created
	 * @param array $header Optional header overrides from mapped invoice data
	 * @return void
	 */
	private function normalizeFactureCreateHeader($facture, array $header = array())
	{
		$refClientSource = array_key_exists('ref_client', $header) ? $header['ref_client'] : ($facture->ref_client ?? '');
		$refClient = trim((string) $refClientSource);

		$refCustomerSource = array_key_exists('ref_customer', $header) ? $header['ref_customer'] : $refClient;
		$facture->ref_client = $refClient;
		$facture->ref_customer = trim((string) $refCustomerSource);
		$facture->note_private = trim((string) ($facture->note_private ?? ''));
		$facture->note_public = trim((string) ($facture->note_public ?? ''));
		$facture->note = trim((string) ($facture->note ?? $facture->note_private));
	}

	/**
	 * Apply mapped invoice header fields onto an existing Dolibarr invoice before rebuild.
	 *
	 * @param Facture $facture Existing invoice object
	 * @param array $dolibarrInvoice Mapped invoice payload
	 * @return void
	 */
	private function applyMappedInvoiceHeaderToFacture($facture, array $dolibarrInvoice)
	{
		$currentArrayOptions = is_array($facture->array_options) ? $facture->array_options : array();

		$facture->socid = $dolibarrInvoice['socid'];
		$facture->date = $dolibarrInvoice['date'];
		$facture->type = $this->getInvoiceType($dolibarrInvoice['doc_type']);
		$facture->ref_client = $dolibarrInvoice['ref_client'];
		$facture->note_public = $dolibarrInvoice['note_public'] ?? 'Imported with KreaFlow';
		$facture->note_private = $dolibarrInvoice['note_private'] ?? '';
		$facture->date_creation = !empty($dolibarrInvoice['array_options']['options_invoice_date_time']) ? $dolibarrInvoice['array_options']['options_invoice_date_time'] : $facture->date_creation;
		$facture->date_validation = '';
		$facture->user_validation_id = null;
		$facture->paye = 0;
		$facture->close_code = '';
		$facture->close_note = '';
		$facture->array_options = array_merge($currentArrayOptions, $dolibarrInvoice['array_options'] ?? array());
		$this->normalizeFactureCreateHeader($facture, $dolibarrInvoice);
	}

	/**
	 * Add invoice lines with comprehensive validation and error handling
	 *
	 * @param Facture $facture Facture object
	 * @param array $lines Invoice lines
	 * @param bool $ivaIncluido Tax included flag
	 * @return bool Success status
	 */
	private function addInvoiceLinesWithValidation($facture, $lines, $ivaIncluido)
	{
		try {
			if (!$this->addInvoiceLines($facture, $lines, $ivaIncluido)) {
				// Old handleBusinessLogicError call removed - error handling simplified
				return false;
			}
			return true;
		} catch (Exception $e) {
			// Old handleBusinessLogicError call removed - error handling simplified
			return false;
		}
	}

	/**
	 * Reconcile stored customer-invoice lines and header with the exact source plan.
	 *
	 * @param Facture $facture Facture object
	 * @param array $dolibarrInvoice Invoice data
	 * @return bool
	 */
	private function reconcileCustomerInvoiceTotals($facture, array $dolibarrInvoice)
	{
		$plan = $dolibarrInvoice['monetary_plan'] ?? null;
		if (!is_array($plan) || empty($plan['lines'])) {
			$this->error = 'The exact customer-invoice monetary plan is missing';
			return false;
		}
		try {
			$multicurrencyRate = ZSDecimal::normalize((string) ($facture->multicurrency_tx ?? '1'));
		} catch (InvalidArgumentException $e) {
			$this->error = 'The customer invoice contains an invalid currency conversion rate';
			return false;
		}
		if (ZSDecimal::compare($multicurrencyRate, '1') !== 0) {
			$this->error = 'ZoneSoft monetary reconciliation currently supports base-currency invoices only';
			return false;
		}
		$roundingMode = ($plan['rounding_method'] ?? '') === ZSInvoiceTaxCalculator::ROUND_VAT_GROUP ? '1' : '0';
		if ($facture->update_price(1, $roundingMode) <= 0
			|| $facture->fetch((int) $facture->id) <= 0
			|| $facture->fetch_lines() < 0) {
			$this->error = $facture->error ?: 'Dolibarr customer-invoice rounding failed';
			return false;
		}

		$sign = ((int) $facture->type === Facture::TYPE_CREDIT_NOTE) ? -1 : 1;
		if (!$this->persistExactCustomerInvoiceLineTotals($facture, $plan, $sign)) {
			return false;
		}
		if ($facture->update_price(1, 'none') <= 0
			|| $facture->fetch((int) $facture->id) <= 0
			|| $facture->fetch_lines() < 0) {
			$this->error = $facture->error ?: 'Unable to sum exact customer-invoice line totals';
			return false;
		}

		return $this->verifyCustomerInvoiceMonetaryPlan($facture, $plan, $sign);
	}

	/** @return bool */
	private function persistExactCustomerInvoiceLineTotals($facture, array $plan, $sign)
	{
		global $conf;

		$storedLines = array_values((array) ($facture->lines ?? array()));
		$plannedLines = array_values((array) ($plan['lines'] ?? array()));
		if (count($storedLines) !== count($plannedLines)) {
			$this->error = 'Dolibarr customer-invoice line count does not match the monetary plan';
			return false;
		}

		foreach ($storedLines as $index => $line) {
			$planned = $plannedLines[$index];
			$line->total_ht = $sign < 0 ? ZSDecimal::negate($planned['total_ht']) : $planned['total_ht'];
			$line->total_tva = $sign < 0 ? ZSDecimal::negate($planned['total_tva']) : $planned['total_tva'];
			$line->total_ttc = $sign < 0 ? ZSDecimal::negate($planned['total_ttc']) : $planned['total_ttc'];
			$line->total_localtax1 = '0.00';
			$line->total_localtax2 = '0.00';
			if ($line->update_total() <= 0) {
				$this->error = $line->error ?: 'Unable to persist exact customer-invoice line totals';
				return false;
			}
			$sql = 'UPDATE ' . MAIN_DB_PREFIX . 'facturedet';
			$sql .= " SET multicurrency_total_ht = '" . $this->db->escape($line->total_ht) . "'";
			$sql .= ", multicurrency_total_tva = '" . $this->db->escape($line->total_tva) . "'";
			$sql .= ", multicurrency_total_ttc = '" . $this->db->escape($line->total_ttc) . "'";
			$sql .= ' WHERE rowid = ' . (int) ($line->id ?? $line->rowid);
			$sql .= ' AND fk_facture = ' . (int) $facture->id;
			$sql .= ' AND EXISTS (SELECT 1 FROM ' . MAIN_DB_PREFIX . 'facture f';
			$sql .= ' WHERE f.rowid = ' . (int) $facture->id;
			$sql .= ' AND f.entity = ' . (int) ($conf->entity ?? 0) . ')';
			if (!$this->db->query($sql)) {
				$this->error = 'Unable to persist exact customer-invoice multicurrency line totals';
				return false;
			}
		}

		return true;
	}

	/** @return bool */
	private function verifyCustomerInvoiceMonetaryPlan($facture, array $plan, $sign)
	{
		$expected = [
			ZSDecimal::round($sign < 0 ? ZSDecimal::negate($plan['total_ht']) : $plan['total_ht'], 2),
			ZSDecimal::round($sign < 0 ? ZSDecimal::negate($plan['total_tva']) : $plan['total_tva'], 2),
			ZSDecimal::round($sign < 0 ? ZSDecimal::negate($plan['total_ttc']) : $plan['total_ttc'], 2),
		];
		$stored = [
			ZSDecimal::round((string) $facture->total_ht, 2),
			ZSDecimal::round((string) $facture->total_tva, 2),
			ZSDecimal::round((string) $facture->total_ttc, 2),
		];
		$storedMulticurrency = [
			ZSDecimal::round((string) $facture->multicurrency_total_ht, 2),
			ZSDecimal::round((string) $facture->multicurrency_total_tva, 2),
			ZSDecimal::round((string) $facture->multicurrency_total_ttc, 2),
		];
		$lineSums = ['0', '0', '0'];
		$lineMulticurrencySums = ['0', '0', '0'];
		$lineMatches = count((array) $facture->lines) === count($plan['lines']);
		foreach (array_values((array) $facture->lines) as $index => $line) {
			$planned = $plan['lines'][$index] ?? null;
			foreach (['total_ht', 'total_tva', 'total_ttc'] as $field) {
				$plannedAmount = $planned ? ($sign < 0 ? ZSDecimal::negate($planned[$field]) : $planned[$field]) : null;
				$lineMatches = $lineMatches && $plannedAmount !== null
					&& ZSDecimal::compare((string) $line->{$field}, $plannedAmount) === 0
					&& ZSDecimal::compare((string) $line->{'multicurrency_' . $field}, $plannedAmount) === 0;
			}
			$lineMatches = $lineMatches && $planned !== null
				&& ZSDecimal::compare((string) $line->tva_tx, $planned['vat_rate']) === 0;
			$lineSums[0] = ZSDecimal::add($lineSums[0], (string) $line->total_ht);
			$lineSums[1] = ZSDecimal::add($lineSums[1], (string) $line->total_tva);
			$lineSums[2] = ZSDecimal::add($lineSums[2], (string) $line->total_ttc);
			$lineMulticurrencySums[0] = ZSDecimal::add($lineMulticurrencySums[0], (string) $line->multicurrency_total_ht);
			$lineMulticurrencySums[1] = ZSDecimal::add($lineMulticurrencySums[1], (string) $line->multicurrency_total_tva);
			$lineMulticurrencySums[2] = ZSDecimal::add($lineMulticurrencySums[2], (string) $line->multicurrency_total_ttc);
		}
		$lineSums = array_map(function ($value) {
			return ZSDecimal::round($value, 2);
		}, $lineSums);
		$lineMulticurrencySums = array_map(function ($value) {
			return ZSDecimal::round($value, 2);
		}, $lineMulticurrencySums);

		if ($lineMatches && $stored === $expected && $lineSums === $expected
			&& $storedMulticurrency === $expected && $lineMulticurrencySums === $expected
			&& ZSDecimal::compare($stored[2], ZSDecimal::add($stored[0], $stored[1])) === 0) {
			return true;
		}

		$context = (array) ($plan['source_context'] ?? array());
		$groups = [];
		foreach ((array) ($plan['groups'] ?? array()) as $group) {
			$groups[] = $group['vat_rate'] . '%=' . $group['total_ht'] . '/' . $group['total_tva'] . '/' . $group['total_ttc'];
		}
		$this->error = 'Customer invoice lines/header do not match the source monetary plan; external_id=' . ($context['external_id'] ?? 'unknown')
			. '; invoice=' . ($context['invoice_number'] ?? 'unknown')
			. '; source=' . implode('/', $expected)
			. '; stored=' . implode('/', $stored)
			. '; line_sums=' . implode('/', $lineSums)
			. '; stored_multicurrency=' . implode('/', $storedMulticurrency)
			. '; line_multicurrency_sums=' . implode('/', $lineMulticurrencySums)
			. '; difference=' . ZSDecimal::subtract($stored[0], $expected[0]) . '/'
			. ZSDecimal::subtract($stored[1], $expected[1]) . '/'
			. ZSDecimal::subtract($stored[2], $expected[2])
			. '; vat_groups=[' . implode(', ', $groups) . ']';
		dol_syslog(__METHOD__ . ' - ' . $this->error, LOG_ERR);

		return false;
	}

	/**
	 * Validate invoice with comprehensive error handling
	 *
	 * @param Facture $facture Facture object
	 * @param User $user User object
	 * @param int $warehouseId Warehouse ID
	 * @param string $invoiceRef Invoice reference
	 * @param bool $verifyAfterValidation Verify immediately when no source-plan restoration follows
	 * @return bool Success status
	 */
	private function validateInvoiceWithErrorHandling($facture, $user, $warehouseId, $invoiceRef, $verifyAfterValidation = true)
	{
		try {
			if (!$this->verifyStoredCustomerInvoiceConsistency($facture)) {
				return false;
			}
			if ($facture->validate($user, '', $warehouseId) < 0) {
				$this->handleInvoiceValidationError($facture, $invoiceRef);
				return false;
			}

			return !$verifyAfterValidation || $this->verifyStoredCustomerInvoiceConsistency($facture);
		} catch (Exception $e) {
			$this->handleInvoiceCreationError(
				$invoiceRef,
				$e,
				[
					'stage' => 'invoice_validation',
					'facture_id' => $facture->id,
					'warehouse_id' => $warehouseId,
					'user_id' => $user->id
				],
				'validateInvoiceWithErrorHandling'
			);
			return false;
		}
	}

	/**
	 * Validate using the source rounding method, then restore the exact source
	 * cents because Dolibarr validation always recalculates invoice prices.
	 *
	 * @param Facture $facture Invoice
	 * @param User $user Lifecycle actor
	 * @param int $warehouseId Warehouse
	 * @param string $invoiceRef External reference
	 * @param array $plan Exact monetary plan
	 * @return bool
	 */
	private function validateInvoiceWithMonetaryPlan($facture, $user, $warehouseId, $invoiceRef, array $plan)
	{
		global $conf;

		$constant = 'MAIN_ROUNDOFTOTAL_NOT_TOTALOFROUND';
		$hadValue = property_exists($conf->global, $constant);
		$previousValue = $hadValue ? $conf->global->{$constant} : null;
		$baseline = (int) $this->db->transaction_opened;
		if (!$this->db->begin()) {
			$this->error = 'Unable to start source invoice validation transaction';
			return false;
		}
		$savepoint = $baseline > 0 ? 'kf_validate_' . (int) $facture->id . '_' . $baseline : null;
		$completed = false;
		$conf->global->{$constant} = ($plan['rounding_method'] ?? '') === ZSInvoiceTaxCalculator::ROUND_VAT_GROUP ? '1' : '0';
		try {
			if ($savepoint !== null && !$this->db->query('SAVEPOINT ' . $savepoint)) {
				$savepoint = null;
				$this->error = 'Unable to protect nested source invoice validation';
				return false;
			}
			// Native validation recalculates prices. Verify only after restoring the
			// exact source plan, inside the same transaction as that validation.
			if (!$this->validateInvoiceWithErrorHandling($facture, $user, $warehouseId, $invoiceRef, false)
				|| !$this->reconcileCustomerInvoiceTotals($facture, ['monetary_plan' => $plan])) {
				return false;
			}
			if ((int) $this->db->transaction_opened !== $baseline + 1) {
				$this->error = 'Unbalanced source invoice validation transaction';
				return false;
			}
			$completed = (bool) $this->db->commit();
			return $completed;
		} finally {
			if ($savepoint !== null) {
				if (!$completed) {
					$this->db->query('ROLLBACK TO SAVEPOINT ' . $savepoint);
				}
				$this->db->query('RELEASE SAVEPOINT ' . $savepoint);
			}
			while ((int) $this->db->transaction_opened > $baseline) {
				$this->db->rollback();
			}
			if ($hadValue) {
				$conf->global->{$constant} = $previousValue;
			} else {
				unset($conf->global->{$constant});
			}
		}
	}

	/**
	 * Verify the cent identities consumed by PDF and statutory exporters.
	 *
	 * @param Facture $facture Invoice
	 * @param string|null $sourceTtc Optional authoritative source gross total
	 * @return bool
	 */
	private function verifyStoredCustomerInvoiceConsistency($facture, $sourceTtc = null)
	{
		global $conf;

		if ($facture->fetch((int) $facture->id) <= 0 || $facture->fetch_lines() < 0) {
			$this->error = $facture->error ?: 'Unable to reload invoice totals';
			return false;
		}
		if ((int) $facture->entity !== (int) $conf->entity) {
			$this->error = 'Invoice monetary verification rejected a foreign entity';
			return false;
		}

		$lineSums = ['0', '0', '0'];
		try {
			foreach ((array) $facture->lines as $line) {
				$lineSums[0] = ZSDecimal::add($lineSums[0], (string) $line->total_ht);
				$lineSums[1] = ZSDecimal::add($lineSums[1], (string) $line->total_tva);
				$lineSums[2] = ZSDecimal::add($lineSums[2], (string) $line->total_ttc);
			}
			$header = [
				ZSDecimal::round((string) $facture->total_ht, 2),
				ZSDecimal::round((string) $facture->total_tva, 2),
				ZSDecimal::round((string) $facture->total_ttc, 2),
			];
			$lineSums = array_map(function ($value) {
				return ZSDecimal::round($value, 2);
			}, $lineSums);
		} catch (InvalidArgumentException $e) {
			$this->error = 'Invoice contains an invalid stored decimal total';
			return false;
		}

		if ($header !== $lineSums || ZSDecimal::compare($header[2], ZSDecimal::add($header[0], $header[1])) !== 0) {
			$this->error = 'Invoice header and line totals do not reconcile to the cent';
			return false;
		}
		if ($sourceTtc !== null) {
			$expected = ZSDecimal::round((string) $sourceTtc, 2);
			if ((int) $facture->type === Facture::TYPE_CREDIT_NOTE) {
				$expected = ZSDecimal::negate(ZSDecimal::absolute($expected));
			}
			if (ZSDecimal::compare($header[2], $expected) !== 0) {
				$this->error = 'Invoice total does not match the authoritative source TTC';
				return false;
			}
		}

		return true;
	}

	/**
	 * Update synchronization status with validation
	 *
	 * @param string $invoiceRef Invoice reference
	 * @param int $factureId Facture ID
	 * @return bool Success status
	 */
	private function updateSyncStatusWithValidation($invoiceRef, $factureId)
	{
		try {
			$updateResult = $this->syncManager->setSyncStatus(
				$invoiceRef,
				ZSSyncStatus::OK,
				null,
				['facture_id' => $factureId, 'validation_completed' => true]
			);

			if (!$updateResult) {
				// Old handleDatabaseError call removed - error handling simplified
				return false;
			}

			return true;
		} catch (Exception $e) {
			// Old handleDatabaseError call removed - error handling simplified
			return false;
		}
	}

	/**
	 * Handle invoice creation errors with comprehensive logging and recovery
	 *
	 * @param string $invoiceRef Invoice reference
	 * @param Exception $exception The exception
	 * @param array $context Additional context
	 * @param string $method Method where error occurred
	 */
	private function handleInvoiceCreationError($invoiceRef, Exception $exception, array $context = [], $method = '')
	{
		$errorContext = array_merge($context, [
			'invoice_ref' => $invoiceRef,
			'method' => $method,
			'class' => get_class($this),
			'timestamp' => date('Y-m-d H:i:s')
		]);

		// Old handleBusinessLogicError call removed - error handling simplified

		try {
			$this->setSyncStatus($invoiceRef, ZSSyncStatus::SAVE_UPDATE_ERROR, $exception->getMessage());
		} catch (Exception $e) {
			// Error handling simplified - using Dolibarr standard logging
			dol_syslog("Error in invoice creation: " . $e->getMessage(), LOG_ERR);
		}
	}

	/**
	 * Handle invoice validation errors with detailed context
	 *
	 * @param Facture $facture Facture object
	 * @param string $invoiceRef Invoice reference
	 */
	private function handleInvoiceValidationError($facture, $invoiceRef)
	{
		$validationErrors = [];
		if (!empty($facture->errors)) {
			$validationErrors = $facture->errors;
		} elseif (!empty($facture->error)) {
			$validationErrors[] = $facture->error;
		}

		// Old handleValidationError call removed - error handling simplified

		$this->setSyncStatus(
			$invoiceRef,
			ZSSyncStatus::VALIDATION_ERROR,
			implode('; ', $validationErrors)
		);
	}

	/**
	 * Synchronize invoice with comprehensive error recovery
	 *
	 * @param string $invoiceRef Invoice reference
	 * @return bool Success status
	 */
	public function synchronizeInvoiceWithRecovery($invoiceRef)
	{
		try {
			return $this->errorRecovery->recoverSynchronization(
				$invoiceRef,
				function () use ($invoiceRef) {
					global $conf;
					// Get invoice data from database
					$sql = "SELECT * FROM " . MAIN_DB_PREFIX . "dolizsynch_zsinvoicesynch ";
					$sql .= "WHERE invoice_ref = '" . $this->db->escape($invoiceRef) . "' ";
					$sql .= "AND entity = " . ((int) $conf->entity);
					$result = $this->db->query($sql);

					if (!$result || $this->db->num_rows($result) === 0) {
						throw new Exception("Invoice not found in synchronization table: $invoiceRef");
					}

					$invoiceData = $this->db->fetch_object($result);
					return $this->synchronizeLatestUnsynchronisedInvoice($invoiceData);
				}
			);
		} catch (Exception $e) {
			// Old handleBusinessLogicError call removed - error handling simplified

			// Set status to failed after recovery attempts
			$this->setSyncStatus(
				$invoiceRef,
				ZSSyncStatus::FAILED,
				"Recovery failed: " . $e->getMessage()
			);

			return false;
		}
	}

	/**
	 * Fetch invoice details with error recovery and comprehensive error handling
	 *
	 * @param string $invoiceRef Invoice reference
	 * @param array $invoiceData Invoice data from sync table
	 * @return array|false Invoice details or false on failure
	 */
	public function fetchInvoiceDetailsWithRecovery($invoiceRef, array $invoiceData)
	{
		try {
			return $this->errorRecovery->recoverApiConnection(
				'getDocumentDetails',
				[
					'document' => [
						'doc' => $invoiceData['doc'],
						'num' => $invoiceData['doc_num'],
						'serie' => $invoiceData['doc_serie'],
						'data' => $invoiceData['data_zs'],
						'loja' => $invoiceData['loja_zs']
					]
				],
				['timeout' => 30, 'retries' => 3]
			);
		} catch (Exception $e) {
			// Old handleApiError call removed - error handling simplified

			// Update status based on error type
			if (strpos(strtolower($e->getMessage()), 'not found') !== false) {
				$this->setSyncStatus($invoiceRef, ZSSyncStatus::INVOICE_NOT_FOUND, $e->getMessage());
			} elseif (strpos(strtolower($e->getMessage()), 'authentication') !== false) {
				$this->setSyncStatus($invoiceRef, ZSSyncStatus::AUTHENTICATION_FAILED, $e->getMessage());
			} else {
				$this->setSyncStatus($invoiceRef, ZSSyncStatus::FAILED, $e->getMessage());
			}

			return false;
		}
	}


	/**
	 * Enhanced synchronization method with comprehensive error recovery
	 *
	 * @param object $invoiceObj Invoice object from database
	 * @return bool Success status
	 */
	public function synchronizeInvoiceWithEnhancedRecovery($invoiceObj)
	{
		$invoiceRef = $invoiceObj->invoice_ref;

		try {
			// Mark as processing to prevent concurrent processing
			if (!$this->syncManager->markAsProcessing($invoiceRef)) {
				// Old handleBusinessLogicError call removed - error handling simplified
				return false;
			}

			// Use error recovery for the entire synchronization process
			$result = $this->errorRecovery->attemptRecovery(
				['category' => "general"],
				function () use ($invoiceObj) {
					return $this->synchronizeLatestUnsynchronisedInvoice($invoiceObj);
				},
				[]
			);

			// Monitor the result for patterns
			$this->errorMonitor->recordSyncAttempt([
				'invoice_ref' => $invoiceRef,
				'success' => $result,
				'sync_method' => 'enhanced_recovery'
			]);

			return $result;
		} catch (Exception $e) {
			// Old handleBusinessLogicError call removed - error handling simplified

			// Set final failure status
			$this->setSyncStatus(
				$invoiceRef,
				ZSSyncStatus::FAILED,
				"Enhanced sync failed: " . $e->getMessage()
			);

			return false;
		}
	}


	/**
	 * Return stock for all product lines in an annulled invoice
	 *
	 * @param Facture $invoice The invoice object
	 * @return bool True on success, false on error
	 */
	private function returnStockForInvoice($invoice)
	{
		global $db, $user;

		$reversal = new DoliZSynchStockReversal($db);
		$result = $reversal->reverseInvoiceMovements($invoice, 'facture', $user);
		if ($result < 0) {
			$this->error = $reversal->error;
			dol_syslog(__METHOD__.' '.$this->error, LOG_ERR);
			return false;
		}

		return true;
	}

	/**
	 * Remove payments, reverse customer invoice stock, and cancel atomically.
	 *
	 * @param Facture $invoice Customer invoice
	 * @param bool $stockAlreadyReversed Skip stock reversal for a previously persisted reversal
	 * @return bool True on success
	 */
	private function cancelCustomerInvoiceWithStockReversal($invoice, $stockAlreadyReversed = false)
	{
		global $db, $user, $conf;

		$invoiceId = (int) ($invoice->id ?? 0);
		$entity = (int) ($conf->entity ?? 0);
		if ($invoiceId <= 0 || $entity <= 0) {
			$this->error = 'Invalid entity-scoped customer invoice cancellation context';
			return false;
		}

		$transactionDepth = $this->getDatabaseTransactionDepth($db);
		if (!$db->begin()) {
			$this->error = 'Unable to start the customer invoice cancellation transaction';
			return false;
		}
		try {
			$sqlLock = 'SELECT rowid FROM ' . MAIN_DB_PREFIX . 'facture';
			$sqlLock .= ' WHERE rowid = ' . $invoiceId;
			$sqlLock .= ' AND entity = ' . $entity;
			$sqlLock .= ' FOR UPDATE';
			$resLock = $db->query($sqlLock);
			if (!$resLock || !$db->fetch_object($resLock)) {
				$this->error = 'Unable to lock the entity-scoped customer invoice before cancellation';
				$this->rollbackDatabaseTransactionToDepth($db, $transactionDepth);
				return false;
			}

			if ($invoice->fetch($invoiceId) <= 0) {
				$this->error = $invoice->error ?: 'Unable to reload customer invoice before cancellation';
				$this->rollbackDatabaseTransactionToDepth($db, $transactionDepth);
				return false;
			}

			if ($this->getInvoiceLifecycleStatus($invoice) === Facture::STATUS_ABANDONED) {
				$paymentLinks = $this->countInvoicePaymentLinks($invoiceId);
				if ($paymentLinks !== 0 || !empty($invoice->paye)) {
					$this->error = 'Canceled invoice has an inconsistent financial state and requires operator review';
					$this->rollbackDatabaseTransactionToDepth($db, $transactionDepth);
					return false;
				}
				if (!$stockAlreadyReversed && !$this->returnStockForInvoice($invoice)) {
					$this->rollbackDatabaseTransactionToDepth($db, $transactionDepth);
					return false;
				}
				if ($transactionDepth !== null && (int) $db->transaction_opened !== $transactionDepth + 1) {
					$this->error = 'Unbalanced nested transaction while reconciling an already-canceled invoice';
					$this->rollbackDatabaseTransactionToDepth($db, $transactionDepth);
					return false;
				}
				if (!$db->commit()) {
					$this->error = 'Unable to commit the already-canceled invoice reconciliation';
					$this->rollbackDatabaseTransactionToDepth($db, $transactionDepth);
					return false;
				}
				return true;
			}

			// Payment preflight and native Facture::setUnpaid() run before any destructive work.
			if (!$this->removePaymentsFromInvoice($invoice, true)) {
				$this->rollbackDatabaseTransactionToDepth($db, $transactionDepth);
				return false;
			}

			if ($invoice->fetch($invoiceId) <= 0) {
				$this->error = $invoice->error ?: 'Unable to reload customer invoice after payment removal';
				$this->rollbackDatabaseTransactionToDepth($db, $transactionDepth);
				return false;
			}

			if (!$stockAlreadyReversed && !$this->returnStockForInvoice($invoice)) {
				$this->rollbackDatabaseTransactionToDepth($db, $transactionDepth);
				return false;
			}
			if ($invoice->setCanceled($user) < 0) {
				$this->error = $invoice->error ?: 'Unable to cancel customer invoice';
				$this->rollbackDatabaseTransactionToDepth($db, $transactionDepth);
				return false;
			}

			if ($invoice->fetch($invoiceId) <= 0) {
				$this->error = $invoice->error ?: 'Unable to reload canceled customer invoice';
				$this->rollbackDatabaseTransactionToDepth($db, $transactionDepth);
				return false;
			}

			$paymentLinks = $this->countInvoicePaymentLinks($invoiceId);
			if ($paymentLinks < 0) {
				$this->error = 'Unable to verify customer invoice payment links after cancellation';
				$this->rollbackDatabaseTransactionToDepth($db, $transactionDepth);
				return false;
			}
			if ($paymentLinks !== 0
				|| $this->getInvoiceLifecycleStatus($invoice) !== Facture::STATUS_ABANDONED
				|| !empty($invoice->paye)
			) {
				$this->error = 'Customer invoice cancellation did not reach the abandoned, unpaid, payment-free state';
				$this->rollbackDatabaseTransactionToDepth($db, $transactionDepth);
				return false;
			}

			if ($transactionDepth !== null && (int) $db->transaction_opened !== $transactionDepth + 1) {
				$this->error = 'Unbalanced nested transaction after customer invoice cancellation';
				$this->rollbackDatabaseTransactionToDepth($db, $transactionDepth);
				return false;
			}
			if (!$db->commit()) {
				$this->error = 'Unable to commit the customer invoice cancellation transaction';
				$this->rollbackDatabaseTransactionToDepth($db, $transactionDepth);
				return false;
			}
			return true;
		} catch (Throwable $e) {
			$this->error = 'Customer invoice cancellation failed: ' . $e->getMessage();
			if (!$this->rollbackDatabaseTransactionToDepth($db, $transactionDepth)) {
				$this->error .= ' (transaction rollback did not restore the original nesting level)';
			}
			return false;
		}
	}

	/**
	 * Resolve warehouse for a line (priority: line > invoice > global default)
	 *
	 * @param object $line Invoice line object
	 * @param Facture $invoice Invoice object
	 * @return int Warehouse ID or 0 if not found
	 */
		private function resolveWarehouseForLine($line, $invoice)
		{
			global $conf;

		// Priority 1: Line warehouse
		if (isset($line->fk_warehouse) && $line->fk_warehouse > 0) {
			return (int)$line->fk_warehouse;
		}

		// Priority 2: Invoice warehouse (if set)
		if (isset($invoice->fk_warehouse) && $invoice->fk_warehouse > 0) {
			return (int)$invoice->fk_warehouse;
		}

		// Priority 3: Global default warehouse
		if (!empty($conf->global->MAIN_DEFAULT_WAREHOUSE)) {
			return (int)$conf->global->MAIN_DEFAULT_WAREHOUSE;
		}

		// Priority 4: Product default warehouse (if you want to implement this)
		// You could fetch the product and check for a default warehouse field

			return 0;
		}

		/**
		 * Count linked payment rows for an invoice.
		 *
		 * @param int $invoiceId
		 * @return int Returns >= 0 on success, -1 on SQL error
		 */
		private function countInvoicePaymentLinks($invoiceId)
		{
			global $db;

			$sql = "SELECT COUNT(*) AS cnt";
			$sql .= " FROM " . MAIN_DB_PREFIX . "paiement_facture";
			$sql .= " WHERE fk_facture = " . (int) $invoiceId;

			$resql = $db->query($sql);
			if (!$resql) {
				dol_syslog(__METHOD__ . " SQL error: " . $db->lasterror(), LOG_ERR);
				return -1;
			}

			$obj = $db->fetch_object($resql);
			return (int) ($obj->cnt ?? 0);
		}

		/**
		 * Verify a canceled invoice has no payment links and is unpaid.
		 *
		 * Legacy inconsistencies require operator review. Reopening an abandoned
		 * invoice or changing its paid flag with raw SQL would bypass the intended
		 * invoice lifecycle and could lose cancellation metadata.
		 *
		 * @param Facture $invoice
		 * @param string $origin
		 * @return bool
		 */
		private function ensureCanceledInvoiceFinancialState($invoice, $origin = '')
		{
			$invoiceId = (int) ($invoice->id ?? 0);
			if ($invoiceId <= 0) {
				return false;
			}

			$links = $this->countInvoicePaymentLinks($invoiceId);
			if ($links < 0) {
				return false;
			}

			if ($links > 0) {
				$this->error = 'Canceled invoice has residual payment links and requires operator review';
				dol_syslog(__METHOD__ . " {$this->error} for {$invoice->ref} (from {$origin})", LOG_ERR);
				return false;
			}

			if ((int) ($invoice->paye ?? 0) !== 0) {
				$this->error = 'Canceled invoice is still marked paid and requires operator review';
				dol_syslog(__METHOD__ . " {$this->error} for {$invoice->ref} (from {$origin})", LOG_ERR);
				return false;
			}

			return true;
		}

	/**
	 * Fix invoices that should be canceled but weren't between specified dates
	 *
	 * This tool handles two scenarios:
	 * 1. Annulled invoices where fk_facture=0 - finds the invoice via extrafields and updates fk_facture
	 * 2. Annulled invoices with fk_facture>0 but not canceled - removes payments, returns stock, cancels
	 *
	 * @param string $dateStart Start date (Y-m-d format)
	 * @param string $dateEnd End date (Y-m-d format)
	 * @return array Array with 'success', 'failed', 'skipped', and 'fixed_refs' counts
	 */
	public function fixCanceledInvoicesBetweenDates($dateStart, $dateEnd)
	{
		global $db, $user, $conf;
		$entity = (int) ($conf->entity ?? 1);

		dol_syslog(__METHOD__ . " - Fixing canceled invoices between $dateStart and $dateEnd", LOG_INFO);

		$results = [
			'success' => 0,
			'failed' => 0,
			'skipped' => 0,
			'fixed_refs' => 0,
			'errors' => []
		];

		// STEP 1: Fix annulled invoices where fk_facture=0 by finding them via invoice_number extrafield
		$sqlMissingRefs = "SELECT s.rowid, s.invoice_ref, s.data_zs
						   FROM " . MAIN_DB_PREFIX . "dolizsynch_zsinvoicesynch s
						   WHERE s.anulado_zs = 1
						   AND s.entity = " . $entity . "
						   AND (s.fk_facture = 0 OR s.fk_facture IS NULL)
						   AND s.data_zs >= '" . $db->escape($dateStart) . "'
						   AND s.data_zs <= '" . $db->escape($dateEnd) . "'
						   ORDER BY s.data_zs ASC";

		$resqlMissing = $db->query($sqlMissingRefs);
		if ($resqlMissing) {
			$missingCount = $db->num_rows($resqlMissing);
			dol_syslog("Found $missingCount annulled invoice(s) with fk_facture=0, looking up via extrafields", LOG_INFO);

			while ($missingObj = $db->fetch_object($resqlMissing)) {
				dol_syslog("Looking up invoice via extrafield: {$missingObj->invoice_ref}", LOG_INFO);

				// Find the invoice by matching invoice_number extrafield
				$sqlFindInvoice = "SELECT f.rowid
								   FROM " . MAIN_DB_PREFIX . "facture f
								   INNER JOIN " . MAIN_DB_PREFIX . "facture_extrafields fe ON f.rowid = fe.fk_object
								   WHERE fe.invoice_number = '" . $db->escape($missingObj->invoice_ref) . "'
								   AND f.entity = " . $entity;

				$resqlFind = $db->query($sqlFindInvoice);
				if ($resqlFind && $db->num_rows($resqlFind) > 0) {
					$invoiceObj = $db->fetch_object($resqlFind);
					$foundInvoiceId = $invoiceObj->rowid;

					// Update fk_facture in sync table
					$sqlUpdateRef = "UPDATE " . MAIN_DB_PREFIX . "dolizsynch_zsinvoicesynch
									 SET fk_facture = " . intval($foundInvoiceId) . "
									 WHERE invoice_ref = '" . $db->escape($missingObj->invoice_ref) . "'
									 AND entity = " . $entity;

					if ($db->query($sqlUpdateRef)) {
						dol_syslog("Updated fk_facture={$foundInvoiceId} for {$missingObj->invoice_ref}", LOG_INFO);
						$results['fixed_refs']++;
					} else {
						dol_syslog("Error updating fk_facture for {$missingObj->invoice_ref}: " . $db->lasterror(), LOG_ERR);
						$results['failed']++;
						$results['errors'][] = "Failed to update fk_facture for {$missingObj->invoice_ref}";
					}
				} else {
					dol_syslog("Invoice not found in extrafields for {$missingObj->invoice_ref}", LOG_WARNING);
					$results['failed']++;
					$results['errors'][] = "Invoice not found for {$missingObj->invoice_ref}";
				}
			}
		}

		// STEP 2: Fix existing invoices that should be canceled but aren't
		$sql = "SELECT s.rowid, s.invoice_ref, s.fk_facture, s.data_zs, s.anulado_zs, s.stock_reversed
				FROM " . MAIN_DB_PREFIX . "dolizsynch_zsinvoicesynch s
				INNER JOIN " . MAIN_DB_PREFIX . "facture f ON s.fk_facture = f.rowid
				WHERE s.anulado_zs = 1
				AND s.entity = " . $entity . "
				AND f.entity = " . $entity . "
				AND s.data_zs >= '" . $db->escape($dateStart) . "'
				AND s.data_zs <= '" . $db->escape($dateEnd) . "'
				AND s.fk_facture > 0
				ORDER BY s.data_zs ASC";

		$resql = $db->query($sql);
		if (!$resql) {
			dol_syslog("Error querying canceled invoices: " . $db->lasterror(), LOG_ERR);
			$results['errors'][] = "Database query failed: " . $db->lasterror();
			return $results;
		}

		$totalFound = $db->num_rows($resql);
		dol_syslog("Found $totalFound invoice(s) marked as canceled in sync table but not canceled in Dolibarr", LOG_INFO);

		while ($obj = $db->fetch_object($resql)) {
			dol_syslog("Processing invoice: {$obj->invoice_ref} (fk_facture={$obj->fk_facture})", LOG_INFO);

			// Load the invoice
			$invoice = new Facture($db);
			if ($invoice->fetch($obj->fk_facture) <= 0) {
				dol_syslog("Error fetching invoice {$obj->fk_facture}: " . $invoice->error, LOG_ERR);
				$results['failed']++;
				$results['errors'][] = "Failed to load invoice {$obj->invoice_ref}: " . $invoice->error;
				continue;
			}

			// Load invoice lines (required for stock return)
			$invoice->fetch_lines();

			// Skip if already canceled
			if ($invoice->statut == Facture::STATUS_ABANDONED) {
				$db->begin();
				if (!$this->returnStockForInvoice($invoice)) {
					$db->rollback();
					$results['failed']++;
					$results['errors'][] = "Failed to reconcile canceled invoice stock: {$obj->invoice_ref}";
					continue;
				}
				$sqlReconciled = 'UPDATE '.MAIN_DB_PREFIX.'dolizsynch_zsinvoicesynch SET stock_reversed=1';
				$sqlReconciled .= " WHERE rowid=".((int) $obj->rowid).' AND entity='.$entity;
				if (!$db->query($sqlReconciled)) {
					$db->rollback();
					$results['failed']++;
					$results['errors'][] = "Failed to persist canceled invoice stock state: {$obj->invoice_ref}";
					continue;
				}
				$db->commit();
				if ($this->ensureCanceledInvoiceFinancialState($invoice, __METHOD__ . ':already_canceled')) {
					dol_syslog("Invoice {$obj->invoice_ref} already canceled and financially clean, skipping", LOG_INFO);
					$results['skipped']++;
				} else {
					dol_syslog("Invoice {$obj->invoice_ref} already canceled but still has active payment links", LOG_ERR);
					$results['failed']++;
					$results['errors'][] = "Canceled invoice still has active payment links: {$obj->invoice_ref}";
				}
				continue;
			}

			$stockAlreadyReversed = isset($obj->stock_reversed) && $obj->stock_reversed == 1;
			if ($stockAlreadyReversed) {
				dol_syslog("Stock already reversed for invoice {$obj->invoice_ref}; canceling without another reversal", LOG_INFO);
			} else {
				dol_syslog("Removing payments, reversing stock, and canceling invoice {$obj->invoice_ref}", LOG_INFO);
			}
			if (!$this->cancelCustomerInvoiceWithStockReversal($invoice, $stockAlreadyReversed)) {
				dol_syslog("Error canceling invoice {$obj->invoice_ref}: " . $this->error, LOG_ERR);
				$results['failed']++;
				$results['errors'][] = "Failed to cancel {$obj->invoice_ref}: " . $this->error;
				continue;
			}

			$sqlUpdate = "UPDATE " . MAIN_DB_PREFIX . "dolizsynch_zsinvoicesynch
						  SET stock_reversed = 1
						  WHERE invoice_ref = '" . $db->escape($obj->invoice_ref) . "'
						  AND entity = " . $entity;
			if (!$db->query($sqlUpdate)) {
				$results['failed']++;
				$results['errors'][] = "Invoice {$obj->invoice_ref} was canceled but its reversal state could not be persisted";
				continue;
			}
			dol_syslog("Updated stock_reversed=1 for {$obj->invoice_ref}", LOG_INFO);

			dol_syslog("Successfully canceled invoice {$obj->invoice_ref}", LOG_INFO);
			$results['success']++;
		}

		dol_syslog("Fix completed: {$results['fixed_refs']} refs fixed, {$results['success']} success, {$results['failed']} failed, {$results['skipped']} skipped", LOG_INFO);
		return $results;
	}

	/**
	 * Fix all invoices with missing fk_facture references between specified dates
	 *
	 * Finds invoices where fk_facture=0 or NULL and looks them up via invoice_number extrafield
	 *
	 * @param string $dateStart Start date (Y-m-d format)
	 * @param string $dateEnd End date (Y-m-d format)
	 * @return array Array with 'fixed', 'not_found', and 'errors' counts
	 */
	public function fixMissingInvoiceReferences($dateStart, $dateEnd)
	{
		global $db, $conf;
		$entity = (int) ($conf->entity ?? 1);

		dol_syslog(__METHOD__ . " - Fixing missing fk_facture references between $dateStart and $dateEnd", LOG_INFO);

		$results = [
			'fixed' => 0,
			'not_found' => 0,
			'errors' => []
		];

		// Find all invoices with fk_facture=0 or NULL
		$sqlMissingRefs = "SELECT s.rowid, s.invoice_ref, s.data_zs, s.anulado_zs
						   FROM " . MAIN_DB_PREFIX . "dolizsynch_zsinvoicesynch s
						   WHERE (s.fk_facture = 0 OR s.fk_facture IS NULL)
						   AND s.entity = " . $entity . "
						   AND s.data_zs >= '" . $db->escape($dateStart) . "'
						   AND s.data_zs <= '" . $db->escape($dateEnd) . "'
						   ORDER BY s.data_zs ASC";

		$resqlMissing = $db->query($sqlMissingRefs);
		if (!$resqlMissing) {
			dol_syslog("Error querying missing references: " . $db->lasterror(), LOG_ERR);
			$results['errors'][] = "Database query failed: " . $db->lasterror();
			return $results;
		}

		$totalFound = $db->num_rows($resqlMissing);
		dol_syslog("Found $totalFound invoice(s) with fk_facture=0, looking up via extrafields", LOG_INFO);

		while ($missingObj = $db->fetch_object($resqlMissing)) {
			dol_syslog("Looking up invoice via extrafield: {$missingObj->invoice_ref} (anulado={$missingObj->anulado_zs})", LOG_INFO);

			// Find the invoice by matching invoice_number extrafield
			$sqlFindInvoice = "SELECT f.rowid
							   FROM " . MAIN_DB_PREFIX . "facture f
							   INNER JOIN " . MAIN_DB_PREFIX . "facture_extrafields fe ON f.rowid = fe.fk_object
							   WHERE fe.invoice_number = '" . $db->escape($missingObj->invoice_ref) . "'
							   AND f.entity = " . $entity;

			$resqlFind = $db->query($sqlFindInvoice);
			if ($resqlFind && $db->num_rows($resqlFind) > 0) {
				$invoiceObj = $db->fetch_object($resqlFind);
				$foundInvoiceId = $invoiceObj->rowid;

				// Update fk_facture in sync table
				$sqlUpdateRef = "UPDATE " . MAIN_DB_PREFIX . "dolizsynch_zsinvoicesynch
								 SET fk_facture = " . intval($foundInvoiceId) . "
								 WHERE invoice_ref = '" . $db->escape($missingObj->invoice_ref) . "'
								 AND entity = " . $entity;

				if ($db->query($sqlUpdateRef)) {
					dol_syslog("Updated fk_facture={$foundInvoiceId} for {$missingObj->invoice_ref}", LOG_INFO);
					$results['fixed']++;
				} else {
					dol_syslog("Error updating fk_facture for {$missingObj->invoice_ref}: " . $db->lasterror(), LOG_ERR);
					$results['errors'][] = "Failed to update fk_facture for {$missingObj->invoice_ref}";
				}
			} else {
				dol_syslog("Invoice not found in extrafields for {$missingObj->invoice_ref}", LOG_WARNING);
				$results['not_found']++;
				$results['errors'][] = "Invoice not found for {$missingObj->invoice_ref}";
			}
		}

		dol_syslog("Fix completed: {$results['fixed']} fixed, {$results['not_found']} not found", LOG_INFO);
		return $results;
	}

	/**
	 * Fix a specific canceled invoice by fk_facture ID
	 *
	 * Forces cancellation of an invoice by its fk_facture, removing payments and returning stock
	 *
	 * @param int $fkFacture The fk_facture ID
	 * @return array Array with 'success' boolean and 'message' string
	 */
	public function fixCanceledInvoiceByFkFacture($fkFacture)
	{
		global $db, $user, $conf;
		$entity = (int) ($conf->entity ?? 1);

		dol_syslog(__METHOD__ . " - Fixing invoice with fk_facture={$fkFacture}", LOG_INFO);

		// Increase time limit for complex stock movements
		$originalTimeLimit = ini_get('max_execution_time');
		set_time_limit(300); // 5 minutes

		$result = [
			'success' => false,
			'message' => ''
		];

		try {
			// Load invoice from sync table
			$sqlSync = "SELECT s.rowid, s.invoice_ref, s.anulado_zs, s.stock_reversed, s.fk_facture
						FROM " . MAIN_DB_PREFIX . "dolizsynch_zsinvoicesynch s
						WHERE s.fk_facture = " . intval($fkFacture) . "
						AND s.entity = " . $entity;

			$resqlSync = $db->query($sqlSync);
			if (!$resqlSync || $db->num_rows($resqlSync) == 0) {
				$result['message'] = "Invoice not found in sync table for fk_facture={$fkFacture}";
				return $result;
			}

			$syncObj = $db->fetch_object($resqlSync);

			// Ensure invoice belongs to current entity before loading it.
			$sqlInvoiceEntity = "SELECT rowid FROM " . MAIN_DB_PREFIX . "facture";
			$sqlInvoiceEntity .= " WHERE rowid = " . intval($fkFacture);
			$sqlInvoiceEntity .= " AND entity = " . $entity;
			$sqlInvoiceEntity .= " LIMIT 1";
			$resqlInvoiceEntity = $db->query($sqlInvoiceEntity);
			if (!$resqlInvoiceEntity || $db->num_rows($resqlInvoiceEntity) == 0) {
				$result['message'] = "Invoice {$fkFacture} does not belong to entity {$entity}";
				return $result;
			}

			// Load the Dolibarr invoice
			$invoice = new Facture($db);
			if ($invoice->fetch($fkFacture) <= 0) {
				$result['message'] = "Error fetching invoice {$fkFacture}: " . $invoice->error;
				return $result;
			}

			// Load invoice lines (required for stock return)
			$invoice->fetch_lines();

			// Check if already canceled
			if ($invoice->statut == Facture::STATUS_ABANDONED) {
				$db->begin();
				if (!$this->returnStockForInvoice($invoice)) {
					$db->rollback();
					$result['message'] = "Unable to reconcile stock for canceled invoice {$syncObj->invoice_ref}: ".$this->error;
					return $result;
				}
				$sqlReconciled = 'UPDATE '.MAIN_DB_PREFIX.'dolizsynch_zsinvoicesynch SET stock_reversed=1';
				$sqlReconciled .= ' WHERE fk_facture='.((int) $fkFacture).' AND entity='.$entity;
				if (!$db->query($sqlReconciled)) {
					$db->rollback();
					$result['message'] = "Unable to persist stock reconciliation for {$syncObj->invoice_ref}";
					return $result;
				}
				$db->commit();
				if (!$this->ensureCanceledInvoiceFinancialState($invoice, __METHOD__ . ':already_canceled')) {
					$result['message'] = "Invoice {$syncObj->invoice_ref} is canceled but still has active payment links";
					return $result;
				}

				$result['message'] = "Invoice {$syncObj->invoice_ref} is already canceled";
				$result['success'] = true;
				return $result;
			}

			$stockAlreadyReversed = !empty($syncObj->stock_reversed);
			dol_syslog("Removing payments and canceling invoice {$syncObj->invoice_ref}", LOG_INFO);
			if (!$this->cancelCustomerInvoiceWithStockReversal($invoice, $stockAlreadyReversed)) {
				$errorMsg = "Failed to cancel {$syncObj->invoice_ref}: " . $this->error;
				dol_syslog($errorMsg, LOG_ERR);
				$result['message'] = $errorMsg;
				return $result;
			}

			dol_syslog("Invoice {$syncObj->invoice_ref} canceled successfully", LOG_INFO);

			// Step 3: Persist the successful reversal state.
			$sqlUpdate = "UPDATE " . MAIN_DB_PREFIX . "dolizsynch_zsinvoicesynch
						  SET stock_reversed = 1
						  WHERE fk_facture = " . intval($fkFacture) . "
						  AND entity = " . $entity;
			if (!$db->query($sqlUpdate)) {
				$result['message'] = "Invoice {$syncObj->invoice_ref} was canceled but its reversal state could not be persisted";
				return $result;
			}
			dol_syslog("Stock reversal recorded for {$syncObj->invoice_ref}", LOG_INFO);

			$result['success'] = true;
			$result['message'] = "Successfully canceled invoice {$syncObj->invoice_ref} and returned stock";
			dol_syslog("Successfully canceled invoice {$syncObj->invoice_ref} and returned stock", LOG_INFO);
		} catch (Exception $e) {
			$result['message'] = "Exception during invoice cancellation: " . $e->getMessage();
			dol_syslog("Exception in fixCanceledInvoiceByFkFacture: " . $e->getMessage(), LOG_ERR);
		} finally {
			// Restore original time limit
			set_time_limit($originalTimeLimit);
		}

		return $result;
	}
}

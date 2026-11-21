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
 * \file       htdocs/core/modules/kreaflow/mod_zsinvoicesynch_advanced.php
 * \ingroup    dolizsynch
 * \brief      File containing class for advanced numbering model of ZSInvoiceSynch
 */

dol_include_once('/kreaflow/core/modules/kreaflow/modules_zsinvoicesynch.php');


/**
 *	Class to manage the Advanced numbering rule for ZSInvoiceSynch
 */
class mod_zsinvoicesynch_advanced extends ModeleNumRefZSInvoiceSynch
{
	/**
	 * Dolibarr version of the loaded document
	 * @var string
	 */
	public $version = 'dolibarr'; // 'development', 'experimental', 'dolibarr'

	/**
	 * @var string Error message
	 */
	public $error = '';

	/**
	 * @var string name
	 */
	public $name = 'advanced';


	/**
	 *  Returns the description of the numbering model
	 *
	 *  @return     string      Descriptive text
	 */
	public function info()
	{
		global $conf, $langs, $db;

		$langs->load("bills");

		$form = new Form($db);

		$texte = $langs->trans('GenericNumRefModelDesc') . "<br>\n";
		$texte .= '<form action="' . $_SERVER["PHP_SELF"] . '" method="POST">';
		$texte .= '<input type="hidden" name="token" value="' . newToken() . '">';
		$texte .= '<input type="hidden" name="action" value="updateMask">';
		$texte .= '<input type="hidden" name="maskconst" value="DOLIZSYNCH_MYOBJECT_ADVANCED_MASK">';
		$texte .= '<table class="nobordernopadding centpercent">';

		$tooltip = $langs->trans("GenericMaskCodes", $langs->transnoentities("ZSInvoiceSynch"), $langs->transnoentities("ZSInvoiceSynch"));
		$tooltip .= $langs->trans("GenericMaskCodes2");
		$tooltip .= $langs->trans("GenericMaskCodes3");
		$tooltip .= $langs->trans("GenericMaskCodes4a", $langs->transnoentities("ZSInvoiceSynch"), $langs->transnoentities("ZSInvoiceSynch"));
		$tooltip .= $langs->trans("GenericMaskCodes5");

		// Parametrage du prefix
		$texte .= '<tr><td>' . $langs->trans("Mask") . ':</td>';
		$texte .= '<td class="right">' . $form->textwithpicto('<input type="text" class="flat minwidth175" name="maskvalue" value="' . getDolGlobalString('DOLIZSYNCH_MYOBJECT_ADVANCED_MASK') . '">', $tooltip, 1, 1) . '</td>';
		$texte .= '<td class="left" rowspan="2">&nbsp; <input type="submit" class="button button-edit" value="' . $langs->trans("Modify") . '" name="Button"></td>';
		$texte .= '</tr>';

		$texte .= '</table>';
		$texte .= '</form>';

		return $texte;
	}

	/**
	 *  Return an example of numbering
	 *
	 *  @return     string      Example
	 */
	public function getExample()
	{
		global $conf, $db, $langs, $mysoc;

		$object = new ZSInvoiceSynch($db);
		$object->initAsSpecimen();

		/*$old_code_client = $mysoc->code_client;
		$old_code_type = $mysoc->typent_code;
		$mysoc->code_client = 'CCCCCCCCCC';
		$mysoc->typent_code = 'TTTTTTTTTT';*/

		$numExample = $this->getNextValue($object);

		/*$mysoc->code_client = $old_code_client;
		$mysoc->typent_code = $old_code_type;*/

		if (!$numExample) {
			$numExample = $langs->trans('NotConfigured');
		}
		return $numExample;
	}

	/**
	 * 	Return next free value
	 *
	 *  @param  Object		$object		Object we need next value for
	 *  @return string      			Value if KO, <0 if KO
	 */
	public function getNextValue($object)
	{
		global $db, $conf;

		require_once DOL_DOCUMENT_ROOT . '/core/lib/functions2.lib.php';

		// We get cursor rule
		$mask = getDolGlobalString('DOLIZSYNCH_MYOBJECT_ADVANCED_MASK');

		if (!$mask) {
			$this->error = 'NotConfigured';
			return 0;
		}

		$date = $object->date;

		$numFinal = get_next_value($db, $mask, 'dolizsynch_zsinvoicesynch', 'ref', '', null, $date);

		return  $numFinal;
	}
}

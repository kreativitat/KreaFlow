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
 * \file    core/modules/bom/mod_bom_dolizsynchid.php
 * \ingroup dolizsynch
 * \brief   BOM reference numbering based on the immutable technical ID.
 */

require_once DOL_DOCUMENT_ROOT . '/core/modules/bom/modules_bom.php';

/**
 * Generate globally unique BOM references from bom_bom.rowid.
 */
class mod_bom_dolizsynchid extends ModeleNumRefBoms
{
	/** @var string Dolibarr-compatible model version */
	public $version = 'dolibarr';

	/** @var string Model name */
	public $name = 'dolizsynchid';

	/** @var string Reference prefix */
	public $prefix = 'BO_';

	/** @var string Error code or message */
	public $error = '';

	/**
	 * Return the model description.
	 *
	 * @param Translate $langs Translation handler
	 * @return string
	 */
	public function info($langs)
	{
		return 'BOM reference based on its immutable technical ID.';
	}

	/**
	 * Return an example reference.
	 *
	 * @return string
	 */
	public function getExample()
	{
		return self::formatReference(123);
	}

	/**
	 * The technical ID is unique independently of entity visibility.
	 *
	 * @param CommonObject $object Object used by the setup compatibility check
	 * @return bool
	 */
	public function canBeActivated($object)
	{
		return true;
	}

	/**
	 * Return the reference for a persisted BOM draft.
	 *
	 * @param Product $objprod BOM product
	 * @param ?BOM $object BOM being validated
	 * @return string|int<-1,0>
	 */
	public function getNextValue($objprod, $object)
	{
		$bomId = 0;
		if (is_object($object)) {
			$bomId = !empty($object->id) ? (int) $object->id : (int) ($object->rowid ?? 0);
		}

		if ($bomId <= 0) {
			$this->error = 'A persisted BOM technical ID is required to generate its reference.';
			return -1;
		}

		return self::formatReference($bomId);
	}

	/**
	 * Format one BOM technical ID without truncating IDs longer than seven digits.
	 *
	 * @param int $bomId BOM technical ID
	 * @return string
	 */
	public static function formatReference($bomId)
	{
		return 'BO_' . str_pad((string) ((int) $bomId), 7, '0', STR_PAD_LEFT);
	}
}

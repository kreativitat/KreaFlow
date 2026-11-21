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
 * Compatibility shim for legacy StoreEntityInvoiceConverter class/file.
 * Delegates to the current implementation in zsstoreinvoiceconverter.class.php.
 */

if (!class_exists('ZSStoreInvoiceConverter')) {
	if (function_exists('dol_include_once')) {
		dol_include_once('/kreaflow/class/zsstoreinvoiceconverter.class.php');
	}
	if (!class_exists('ZSStoreInvoiceConverter')) {
		$fallbacks = array();
		if (defined('DOL_DOCUMENT_ROOT')) {
			$fallbacks[] = rtrim(DOL_DOCUMENT_ROOT, '/') . '/custom/kreaflow/class/zsstoreinvoiceconverter.class.php';
		}
		$fallbacks[] = __DIR__ . '/zsstoreinvoiceconverter.class.php';
		foreach ($fallbacks as $fallback) {
			if (is_readable($fallback)) {
				require_once $fallback;
				break;
			}
		}
		if (!class_exists('ZSStoreInvoiceConverter') && function_exists('dol_syslog')) {
			dol_syslog(__FILE__ . ' failed to load ZSStoreInvoiceConverter dependency', LOG_ERR);
		}
	}
}

// Provide legacy class name for older cron/task configurations
if (!class_exists('StoreEntityInvoiceConverter') && class_exists('ZSStoreInvoiceConverter')) {
	class StoreEntityInvoiceConverter extends ZSStoreInvoiceConverter
	{
	}
}

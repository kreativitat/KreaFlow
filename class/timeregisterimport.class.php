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
 * Compatibility loader for TimeRegisterImport.
 *
 * Some cron jobs or legacy references expect this filename. Delegate
 * to the canonical implementation kept in zstimeregisterimport.class.php.
 */

if (!class_exists('TimeRegisterImport')) {
	if (function_exists('dol_include_once')) {
		dol_include_once('/kreaflow/class/zstimeregisterimport.class.php');
	}
	if (!class_exists('TimeRegisterImport')) {
		$fallbacks = array();
		if (defined('DOL_DOCUMENT_ROOT')) {
			$fallbacks[] = rtrim(DOL_DOCUMENT_ROOT, '/') . '/custom/kreaflow/class/zstimeregisterimport.class.php';
		}
		$fallbacks[] = __DIR__ . '/zstimeregisterimport.class.php';
		foreach ($fallbacks as $fallback) {
			if (is_readable($fallback)) {
				require_once $fallback;
				break;
			}
		}
		if (!class_exists('TimeRegisterImport') && function_exists('dol_syslog')) {
			dol_syslog(__FILE__ . ' failed to load TimeRegisterImport dependency', LOG_ERR);
		}
	}
}

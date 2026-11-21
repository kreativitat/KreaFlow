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

$failures = array();
$dolStringToTimeGmtArgument = null;

function dol_stringtotime($value, $gm = false)
{
	global $dolStringToTimeGmtArgument;

	$dolStringToTimeGmtArgument = $gm;
	$timestamp = strtotime($value . ($gm ? ' UTC' : ''));

	return $timestamp === false ? 0 : $timestamp;
}

require_once dirname(__DIR__) . '/class/trait/ZSInvoiceMapping.trait.php';

class ZSInvoiceDateConversionHarness
{
	use ZSInvoiceMapping;

	public function convert($value)
	{
		return $this->convertZsDateToTimestamp($value);
	}
}

function assertSame($expected, $actual, $label)
{
	global $failures;

	if ($expected !== $actual) {
		$failures[] = $label;
		echo '[FAIL] ' . $label . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . "\n";
		return;
	}

	echo '[PASS] ' . $label . "\n";
}

date_default_timezone_set('Europe/Lisbon');

$harness = new ZSInvoiceDateConversionHarness();
$timestamp = $harness->convert('2026-08-15 10:30:00');

assertSame(false, $dolStringToTimeGmtArgument, 'ZoneSoft datetime is parsed as server-local time');
assertSame('2026-08-15 10:30:00', date('Y-m-d H:i:s', $timestamp), 'Lisbon daylight-saving time is preserved without a one-hour shift');
assertSame(0, $harness->convert(''), 'Empty ZoneSoft datetime remains unset');

if ($failures) {
	echo count($failures) . " invoice datetime conversion test(s) failed.\n";
	exit(1);
}

echo "All invoice datetime conversion tests passed.\n";

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
 * Exact base-10 arithmetic for invoice source values.
 *
 * Values remain decimal strings. PHP floating-point arithmetic is never used,
 * including during half-up rounding and division.
 */
final class ZSDecimal
{
	/** @var int */
	const DIVISION_SCALE = 18;

	/**
	 * @param int|string $value Decimal value
	 * @return string
	 */
	public static function normalize($value)
	{
		if (is_int($value)) {
			return (string) $value;
		}
		if (!is_string($value)) {
			throw new InvalidArgumentException('A monetary value must be supplied as a decimal string');
		}

		$value = trim(str_replace(',', '.', $value));
		if (!preg_match('/^[+-]?(?:[0-9]+(?:\.[0-9]+)?|\.[0-9]+)$/', $value)) {
			throw new InvalidArgumentException('Invalid plain decimal value: ' . $value);
		}

		$negative = isset($value[0]) && $value[0] === '-';
		$value = ltrim($value, '+-');
		$parts = explode('.', $value, 2);
		$integer = ltrim($parts[0], '0');
		$fraction = isset($parts[1]) ? rtrim($parts[1], '0') : '';
		if ($integer === '') {
			$integer = '0';
		}
		if ($integer === '0' && $fraction === '') {
			return '0';
		}

		return ($negative ? '-' : '') . $integer . ($fraction !== '' ? '.' . $fraction : '');
	}

	/** @return string */
	public static function add($left, $right)
	{
		list($leftSign, $leftDigits, $leftScale) = self::parts($left);
		list($rightSign, $rightDigits, $rightScale) = self::parts($right);
		$scale = max($leftScale, $rightScale);
		$leftDigits .= str_repeat('0', $scale - $leftScale);
		$rightDigits .= str_repeat('0', $scale - $rightScale);

		if ($leftSign === $rightSign) {
			return self::fromParts($leftSign, self::unsignedAdd($leftDigits, $rightDigits), $scale);
		}
		$comparison = self::unsignedCompare($leftDigits, $rightDigits);
		if ($comparison === 0) {
			return '0';
		}
		if ($comparison > 0) {
			return self::fromParts($leftSign, self::unsignedSubtract($leftDigits, $rightDigits), $scale);
		}

		return self::fromParts($rightSign, self::unsignedSubtract($rightDigits, $leftDigits), $scale);
	}

	/** @return string */
	public static function subtract($left, $right)
	{
		return self::add($left, self::negate($right));
	}

	/** @return string */
	public static function multiply($left, $right)
	{
		list($leftSign, $leftDigits, $leftScale) = self::parts($left);
		list($rightSign, $rightDigits, $rightScale) = self::parts($right);

		return self::fromParts(
			$leftSign * $rightSign,
			self::unsignedMultiply($leftDigits, $rightDigits),
			$leftScale + $rightScale
		);
	}

	/**
	 * Divide and half-up round the quotient to a fixed internal scale.
	 *
	 * @param int|string $left Dividend
	 * @param int|string $right Divisor
	 * @param int $scale Decimal places in result
	 * @return string
	 */
	public static function divide($left, $right, $scale = self::DIVISION_SCALE)
	{
		if ($scale < 0 || $scale > 30) {
			throw new InvalidArgumentException('Invalid decimal division scale');
		}
		list($leftSign, $leftDigits, $leftScale) = self::parts($left);
		list($rightSign, $rightDigits, $rightScale) = self::parts($right);
		if ($rightDigits === '0') {
			throw new InvalidArgumentException('Division by zero');
		}

		$power = $scale + $rightScale - $leftScale;
		if ($power >= 0) {
			$numerator = $leftDigits . str_repeat('0', $power);
			$denominator = $rightDigits;
		} else {
			$numerator = $leftDigits;
			$denominator = $rightDigits . str_repeat('0', -$power);
		}

		list($quotient, $remainder) = self::unsignedDivide($numerator, $denominator);
		if (self::unsignedCompare(self::unsignedMultiply($remainder, '2'), $denominator) >= 0) {
			$quotient = self::unsignedAdd($quotient, '1');
		}

		return self::fromParts($leftSign * $rightSign, $quotient, $scale);
	}

	/**
	 * Divide exactly by a power of ten without rounding.
	 *
	 * This is used for percentages so a high-precision input cannot be rounded
	 * at an arbitrary working scale before the final monetary result is known.
	 *
	 * @param int|string $value Decimal value
	 * @param int $power Non-negative base-10 exponent
	 * @return string
	 */
	public static function divideByPowerOfTen($value, $power)
	{
		$power = (int) $power;
		if ($power < 0) {
			throw new InvalidArgumentException('Invalid power-of-ten divisor');
		}
		list($sign, $digits, $scale) = self::parts($value);

		return self::fromParts($sign, $digits, $scale + $power);
	}

	/** @return string */
	public static function round($value, $scale = 2)
	{
		list($sign, $digits, $currentScale) = self::parts($value);
		if ($currentScale <= $scale) {
			return self::fromParts($sign, $digits . str_repeat('0', $scale - $currentScale), $scale, true);
		}

		$discard = $currentScale - $scale;
		$roundDigit = (int) $digits[strlen($digits) - $discard];
		$kept = substr($digits, 0, strlen($digits) - $discard);
		if ($kept === '') {
			$kept = '0';
		}
		if ($roundDigit >= 5) {
			$kept = self::unsignedAdd($kept, '1');
		}

		return self::fromParts($sign, $kept, $scale, true);
	}

	/** @return int */
	public static function compare($left, $right)
	{
		$difference = self::subtract($left, $right);
		if ($difference === '0') {
			return 0;
		}

		return $difference[0] === '-' ? -1 : 1;
	}

	/** @return string */
	public static function negate($value)
	{
		$value = self::normalize((string) $value);
		if ($value === '0') {
			return '0';
		}

		return $value[0] === '-' ? substr($value, 1) : '-' . $value;
	}

	/** @return string */
	public static function absolute($value)
	{
		$value = self::normalize((string) $value);

		return isset($value[0]) && $value[0] === '-' ? substr($value, 1) : $value;
	}

	/** @return string Signed minor-unit integer */
	public static function toMinorUnits($value)
	{
		$rounded = self::round($value, 2);
		$negative = isset($rounded[0]) && $rounded[0] === '-';
		$rounded = ltrim($rounded, '-');
		$parts = explode('.', $rounded, 2);
		$minor = ltrim($parts[0] . str_pad(isset($parts[1]) ? $parts[1] : '', 2, '0'), '0');
		if ($minor === '') {
			return '0';
		}

		return ($negative ? '-' : '') . $minor;
	}

	/** @return string */
	public static function fromMinorUnits($minor)
	{
		$minor = trim((string) $minor);
		if (!preg_match('/^-?[0-9]+$/', $minor)) {
			throw new InvalidArgumentException('Invalid minor-unit integer');
		}
		$negative = $minor[0] === '-';
		$digits = ltrim($minor, '-0');
		if ($digits === '') {
			return '0.00';
		}
		$digits = str_pad($digits, 3, '0', STR_PAD_LEFT);
		$value = substr($digits, 0, -2) . '.' . substr($digits, -2);

		return ($negative ? '-' : '') . $value;
	}

	/** @return array{int,string,int} */
	private static function parts($value)
	{
		$value = self::normalize(is_int($value) ? $value : (string) $value);
		$sign = isset($value[0]) && $value[0] === '-' ? -1 : 1;
		$value = ltrim($value, '-');
		$parts = explode('.', $value, 2);
		$scale = isset($parts[1]) ? strlen($parts[1]) : 0;
		$digits = ltrim($parts[0] . (isset($parts[1]) ? $parts[1] : ''), '0');

		return array($sign, $digits === '' ? '0' : $digits, $scale);
	}

	/** @return string */
	private static function fromParts($sign, $digits, $scale, $fixedScale = false)
	{
		$digits = ltrim($digits, '0');
		if ($digits === '') {
			return $fixedScale && $scale > 0 ? '0.' . str_repeat('0', $scale) : '0';
		}
		if ($scale > 0) {
			$digits = str_pad($digits, $scale + 1, '0', STR_PAD_LEFT);
			$value = substr($digits, 0, -$scale) . '.' . substr($digits, -$scale);
			if (!$fixedScale) {
				$value = rtrim(rtrim($value, '0'), '.');
			}
		} else {
			$value = $digits;
		}

		return ($sign < 0 ? '-' : '') . $value;
	}

	/** @return int */
	private static function unsignedCompare($left, $right)
	{
		$left = ltrim($left, '0');
		$right = ltrim($right, '0');
		$left = $left === '' ? '0' : $left;
		$right = $right === '' ? '0' : $right;
		if (strlen($left) !== strlen($right)) {
			return strlen($left) > strlen($right) ? 1 : -1;
		}

		return $left === $right ? 0 : ($left > $right ? 1 : -1);
	}

	/** @return string */
	private static function unsignedAdd($left, $right)
	{
		$carry = 0;
		$result = '';
		$i = strlen($left) - 1;
		$j = strlen($right) - 1;
		while ($i >= 0 || $j >= 0 || $carry) {
			$sum = ($i >= 0 ? (int) $left[$i--] : 0) + ($j >= 0 ? (int) $right[$j--] : 0) + $carry;
			$result = (string) ($sum % 10) . $result;
			$carry = intdiv($sum, 10);
		}

		return ltrim($result, '0') ?: '0';
	}

	/** @return string Left must be greater than or equal to right. */
	private static function unsignedSubtract($left, $right)
	{
		$borrow = 0;
		$result = '';
		$i = strlen($left) - 1;
		$j = strlen($right) - 1;
		while ($i >= 0) {
			$digit = (int) $left[$i--] - $borrow - ($j >= 0 ? (int) $right[$j--] : 0);
			if ($digit < 0) {
				$digit += 10;
				$borrow = 1;
			} else {
				$borrow = 0;
			}
			$result = (string) $digit . $result;
		}

		return ltrim($result, '0') ?: '0';
	}

	/** @return string */
	private static function unsignedMultiply($left, $right)
	{
		if ($left === '0' || $right === '0') {
			return '0';
		}
		$result = array_fill(0, strlen($left) + strlen($right), 0);
		for ($i = strlen($left) - 1; $i >= 0; $i--) {
			for ($j = strlen($right) - 1; $j >= 0; $j--) {
				$position = $i + $j + 1;
				$sum = $result[$position] + ((int) $left[$i] * (int) $right[$j]);
				$result[$position] = $sum % 10;
				$result[$position - 1] += intdiv($sum, 10);
			}
		}

		return ltrim(implode('', $result), '0') ?: '0';
	}

	/** @return array{string,string} */
	private static function unsignedDivide($numerator, $denominator)
	{
		$numerator = ltrim($numerator, '0') ?: '0';
		$denominator = ltrim($denominator, '0') ?: '0';
		if ($denominator === '0') {
			throw new InvalidArgumentException('Division by zero');
		}
		if (self::unsignedCompare($numerator, $denominator) < 0) {
			return array('0', $numerator);
		}

		$quotient = '';
		$remainder = '0';
		for ($i = 0, $length = strlen($numerator); $i < $length; $i++) {
			$remainder = ltrim(($remainder === '0' ? '' : $remainder) . $numerator[$i], '0') ?: '0';
			$digit = 0;
			while (self::unsignedCompare($remainder, $denominator) >= 0) {
				$remainder = self::unsignedSubtract($remainder, $denominator);
				$digit++;
			}
			$quotient .= (string) $digit;
		}

		return array(ltrim($quotient, '0') ?: '0', $remainder);
	}
}

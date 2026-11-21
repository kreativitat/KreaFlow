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

require_once __DIR__ . '/zsdecimal.class.php';

/**
 * Deterministic invoice calculator supporting source line and VAT-group rounding.
 */
final class ZSInvoiceTaxCalculator
{
	const ROUND_SOURCE_LINE = 'source_line';
	const ROUND_SOURCE_LINE_HT = 'source_line_ht';
	const ROUND_SOURCE_LINE_TTC = 'source_line_ttc';
	const ROUND_VAT_GROUP = 'vat_group';

	/**
	 * Calculate an invoice using one explicit rounding method.
	 *
	 * @param array $lines Source lines
	 * @param string $globalDiscountPercent Invoice discount percentage
	 * @param bool $pricesIncludeVat Whether unit prices include VAT
	 * @param string $roundingMethod source_line, source_line_ht, source_line_ttc, vat_group, or auto
	 * @return array
	 */
	public static function calculate(array $lines, $globalDiscountPercent = '0', $pricesIncludeVat = false, $roundingMethod = 'auto')
	{
		$globalDiscountPercent = self::validatePercent($globalDiscountPercent, 'global discount');
		if (empty($lines)) {
			throw new InvalidArgumentException('An invoice must contain at least one financial line');
		}
		if (!in_array($roundingMethod, array('auto', self::ROUND_SOURCE_LINE, self::ROUND_SOURCE_LINE_HT, self::ROUND_SOURCE_LINE_TTC, self::ROUND_VAT_GROUP), true)) {
			throw new InvalidArgumentException('Invalid invoice rounding method');
		}

		$allSourceTotals = true;
		$allSourceTtc = true;
		foreach ($lines as $line) {
			$hasHt = is_array($line) && array_key_exists('source_total_ht', $line);
			$hasTtc = is_array($line) && array_key_exists('source_total_ttc', $line);
			if ($hasHt !== $hasTtc) {
				throw new InvalidArgumentException('Authoritative line HT and TTC must be supplied together');
			}
			$allSourceTotals = $allSourceTotals && $hasHt;
			$allSourceTtc = $allSourceTtc && $hasTtc;
		}
		if ($roundingMethod === 'auto') {
			$roundingMethod = $allSourceTotals ? self::ROUND_SOURCE_LINE : self::ROUND_VAT_GROUP;
		}
		if ($roundingMethod === self::ROUND_SOURCE_LINE && !$allSourceTotals) {
			throw new InvalidArgumentException('Source line rounding requires HT and TTC on every line');
		}
		if ($roundingMethod === self::ROUND_SOURCE_LINE_HT && ($pricesIncludeVat || !$allSourceTotals)) {
			throw new InvalidArgumentException('VAT-exclusive source HT reconciliation requires HT and TTC on every line');
		}
		if ($roundingMethod === self::ROUND_SOURCE_LINE_TTC && (!$pricesIncludeVat || !$allSourceTtc)) {
			throw new InvalidArgumentException('VAT-inclusive source line TTC normalization requires TTC on every line');
		}

		$groups = array();
		$preparedLines = array();
		$sourceHtReconciliations = array();
		foreach ($lines as $index => $line) {
			if (!is_array($line)) {
				throw new InvalidArgumentException('Invalid invoice line at index ' . $index);
			}
			$qty = ZSDecimal::normalize((string) ($line['quantity'] ?? ''));
			$unitPrice = ZSDecimal::normalize((string) ($line['unit_price'] ?? ''));
			$discount = self::validatePercent((string) ($line['discount_percent'] ?? '0'), 'line discount');
			$discountAmount = ZSDecimal::normalize((string) ($line['discount_amount'] ?? '0'));
			$vatRate = self::validatePercent((string) ($line['vat_rate'] ?? '0'), 'VAT rate');
			$localTax1Rate = ZSDecimal::normalize((string) ($line['localtax1_rate'] ?? '0'));
			$localTax2Rate = ZSDecimal::normalize((string) ($line['localtax2_rate'] ?? '0'));
			if (ZSDecimal::compare($localTax1Rate, '0') !== 0 || ZSDecimal::compare($localTax2Rate, '0') !== 0) {
				throw new InvalidArgumentException('Non-zero local taxes require a dedicated Dolibarr persistence adapter');
			}
			if (ZSDecimal::compare($qty, '0') === 0) {
				throw new InvalidArgumentException('A financial invoice line cannot have zero quantity');
			}
			$netInput = ZSDecimal::multiply($qty, $unitPrice);
			$netInput = self::applyDiscount($netInput, $discount);
			if (ZSDecimal::compare($netInput, '0') < 0 && ZSDecimal::compare($discountAmount, '0') !== 0) {
				throw new InvalidArgumentException('A fixed line discount is not supported on a signed credit line');
			}
			if (ZSDecimal::compare($discountAmount, '0') >= 0
				&& ZSDecimal::compare($netInput, '0') >= 0
				&& ZSDecimal::compare($discountAmount, $netInput) > 0) {
				throw new InvalidArgumentException('A fixed line discount cannot exceed the discounted line amount');
			}
			// ZoneSoft may encode an upward cent reconciliation as a negative fixed
			// discount. Subtracting that signed amount applies the surcharge at full
			// precision before VAT; source line/header reconciliation remains mandatory.
			$netInput = ZSDecimal::subtract($netInput, $discountAmount);
			$netInput = self::applyDiscount($netInput, $globalDiscountPercent);
			if ($pricesIncludeVat) {
				$calculatedRawTtc = $netInput;
				$calculatedRawVat = ZSDecimal::compare($vatRate, '0') === 0
					? '0'
					: ZSDecimal::divide(
						ZSDecimal::multiply($calculatedRawTtc, $vatRate),
						ZSDecimal::add('100', $vatRate)
					);
				$calculatedRawHt = ZSDecimal::subtract($calculatedRawTtc, $calculatedRawVat);
			} else {
				$calculatedRawHt = $netInput;
				$calculatedRawVat = ZSDecimal::divideByPowerOfTen(ZSDecimal::multiply($calculatedRawHt, $vatRate), 2);
				$calculatedRawTtc = ZSDecimal::add($calculatedRawHt, $calculatedRawVat);
			}

			if (in_array($roundingMethod, array(self::ROUND_SOURCE_LINE, self::ROUND_SOURCE_LINE_HT), true)) {
				$rawHt = ZSDecimal::round((string) $line['source_total_ht'], 2);
				$rawTtc = ZSDecimal::round((string) $line['source_total_ttc'], 2);
				$rawVat = ZSDecimal::subtract($rawTtc, $rawHt);
				if ($pricesIncludeVat) {
					$expectedTtc = ZSDecimal::round($calculatedRawTtc, 2);
					$expectedVat = ZSDecimal::divide(
						ZSDecimal::multiply($calculatedRawTtc, $vatRate),
						ZSDecimal::add('100', $vatRate),
						2
					);
					$expectedHt = ZSDecimal::subtract($expectedTtc, $expectedVat);
				} else {
					$calculatedHt = ZSDecimal::round($calculatedRawHt, 2);
					$expectedHt = $calculatedHt;
					if ($roundingMethod === self::ROUND_SOURCE_LINE_HT) {
						$lineHtDifference = ZSDecimal::absolute(ZSDecimal::subtract($rawHt, $calculatedHt));
						if (ZSDecimal::compare($lineHtDifference, '0.01') > 0) {
							throw new InvalidArgumentException('VAT-exclusive source line HT differs from the supplied quantity and unit-price result by more than one cent at index ' . $index);
						}
						$expectedHt = $rawHt;
						if (ZSDecimal::compare($lineHtDifference, '0') !== 0) {
							$sourceHtReconciliations[] = array(
								'line_index' => $index,
								'calculated_total_ht' => $calculatedHt,
								'source_total_ht' => $rawHt,
								'difference_ht' => ZSDecimal::subtract($rawHt, $calculatedHt),
							);
						}
					}
					// With source-line rounding, the validated two-decimal line HT is
					// the taxable monetary amount. Preserve full input precision to
					// derive that amount, then calculate and round VAT from it once.
					$expectedVat = ZSDecimal::divide(ZSDecimal::multiply($expectedHt, $vatRate), '100', 2);
					$expectedTtc = ZSDecimal::add($expectedHt, $expectedVat);
				}
				if (ZSDecimal::compare($rawHt, $expectedHt) !== 0
					|| ZSDecimal::compare($rawVat, $expectedVat) !== 0
					|| ZSDecimal::compare($rawTtc, $expectedTtc) !== 0) {
					throw new InvalidArgumentException('Authoritative line totals are inconsistent with quantity, price, discount, and VAT at index ' . $index);
				}
			} elseif ($roundingMethod === self::ROUND_SOURCE_LINE_TTC) {
				$rawTtc = ZSDecimal::round((string) $line['source_total_ttc'], 2);
				$expectedTtc = ZSDecimal::round($calculatedRawTtc, 2);
				$lineTtcDifference = ZSDecimal::absolute(ZSDecimal::subtract($rawTtc, $expectedTtc));
				if (ZSDecimal::compare($lineTtcDifference, '0.01') > 0) {
					throw new InvalidArgumentException('VAT-inclusive source line TTC differs from the supplied quantity and unit-price result by more than one cent at index ' . $index);
				}

				$rawVat = ZSDecimal::compare($vatRate, '0') === 0
					? '0.00'
					: ZSDecimal::divide(
						ZSDecimal::multiply($rawTtc, $vatRate),
						ZSDecimal::add('100', $vatRate),
						2
					);
				$rawHt = ZSDecimal::subtract($rawTtc, $rawVat);

				if (array_key_exists('source_total_ht', $line)) {
					$sourceLineHt = ZSDecimal::round((string) $line['source_total_ht'], 2);
					$lineHtDifference = ZSDecimal::absolute(ZSDecimal::subtract($rawHt, $sourceLineHt));
					if (ZSDecimal::compare($lineHtDifference, '0.01') > 0) {
						throw new InvalidArgumentException('VAT-inclusive source line HT differs from the legal half-up result by more than one cent at index ' . $index);
					}
				}
			} else {
				$rawHt = $calculatedRawHt;
				$rawVat = $calculatedRawVat;
				$rawTtc = $calculatedRawTtc;
			}

			$groupKey = self::buildGroupKey($line, $vatRate);
			if (!isset($groups[$groupKey])) {
				$groups[$groupKey] = array(
					'vat_rate' => $vatRate,
					'exemption' => trim((string) ($line['exemption'] ?? '')),
					'localtax1_rate' => $localTax1Rate,
					'localtax1_type' => trim((string) ($line['localtax1_type'] ?? '0')),
					'localtax2_rate' => $localTax2Rate,
					'localtax2_type' => trim((string) ($line['localtax2_type'] ?? '0')),
					'raw_ht' => '0',
					'raw_vat' => '0',
					'raw_ttc' => '0',
					'line_indexes' => array(),
				);
			}
			$groups[$groupKey]['raw_ht'] = ZSDecimal::add($groups[$groupKey]['raw_ht'], $rawHt);
			$groups[$groupKey]['raw_vat'] = ZSDecimal::add($groups[$groupKey]['raw_vat'], $rawVat);
			$groups[$groupKey]['raw_ttc'] = ZSDecimal::add($groups[$groupKey]['raw_ttc'], $rawTtc);
			$groups[$groupKey]['line_indexes'][] = $index;
			$preparedLines[$index] = array(
				'quantity' => $qty,
				'unit_price' => $unitPrice,
				'discount_percent' => $discount,
				'discount_amount' => $discountAmount,
				'vat_rate' => $vatRate,
				'group_key' => $groupKey,
				'raw_ht' => $rawHt,
				'raw_vat' => $rawVat,
				'raw_ttc' => $rawTtc,
			);
		}

		$invoiceHt = '0.00';
		$invoiceVat = '0.00';
		$invoiceTtc = '0.00';
		foreach ($groups as &$group) {
			if (in_array($roundingMethod, array(self::ROUND_SOURCE_LINE, self::ROUND_SOURCE_LINE_HT, self::ROUND_SOURCE_LINE_TTC), true)) {
				$group['total_ht'] = ZSDecimal::round($group['raw_ht'], 2);
				$group['total_tva'] = ZSDecimal::round($group['raw_vat'], 2);
				$group['total_ttc'] = ZSDecimal::round($group['raw_ttc'], 2);
				foreach ($group['line_indexes'] as $lineIndex) {
					$preparedLines[$lineIndex]['total_ht'] = ZSDecimal::round($preparedLines[$lineIndex]['raw_ht'], 2);
					$preparedLines[$lineIndex]['total_tva'] = ZSDecimal::round($preparedLines[$lineIndex]['raw_vat'], 2);
					$preparedLines[$lineIndex]['total_ttc'] = ZSDecimal::round($preparedLines[$lineIndex]['raw_ttc'], 2);
				}
			} else {
				if ($pricesIncludeVat) {
					$group['total_ttc'] = ZSDecimal::round($group['raw_ttc'], 2);
					$group['total_tva'] = ZSDecimal::divide(
						ZSDecimal::multiply($group['raw_ttc'], $group['vat_rate']),
						ZSDecimal::add('100', $group['vat_rate']),
						2
					);
					$group['total_ht'] = ZSDecimal::subtract($group['total_ttc'], $group['total_tva']);
				} else {
					$group['total_ht'] = ZSDecimal::round($group['raw_ht'], 2);
					$group['total_tva'] = ZSDecimal::divide(ZSDecimal::multiply($group['raw_ht'], $group['vat_rate']), '100', 2);
					$group['total_ttc'] = ZSDecimal::add($group['total_ht'], $group['total_tva']);
				}

				$allocatedHt = '0.00';
				$allocatedVat = '0.00';
				$lastPosition = count($group['line_indexes']) - 1;
				foreach ($group['line_indexes'] as $position => $lineIndex) {
					if ($position === $lastPosition) {
						$lineHt = ZSDecimal::subtract($group['total_ht'], $allocatedHt);
						$lineVat = ZSDecimal::subtract($group['total_tva'], $allocatedVat);
					} else {
						if ($pricesIncludeVat) {
							$lineTtc = ZSDecimal::round($preparedLines[$lineIndex]['raw_ttc'], 2);
							$lineVat = ZSDecimal::divide(
								ZSDecimal::multiply($preparedLines[$lineIndex]['raw_ttc'], $group['vat_rate']),
								ZSDecimal::add('100', $group['vat_rate']),
								2
							);
							$lineHt = ZSDecimal::subtract($lineTtc, $lineVat);
						} else {
							$lineHt = ZSDecimal::round($preparedLines[$lineIndex]['raw_ht'], 2);
							$lineVat = ZSDecimal::divide(
								ZSDecimal::multiply($preparedLines[$lineIndex]['raw_ht'], $group['vat_rate']),
								'100',
								2
							);
						}
						$allocatedHt = ZSDecimal::add($allocatedHt, $lineHt);
						$allocatedVat = ZSDecimal::add($allocatedVat, $lineVat);
					}
					$preparedLines[$lineIndex]['total_ht'] = ZSDecimal::round($lineHt, 2);
					$preparedLines[$lineIndex]['total_tva'] = ZSDecimal::round($lineVat, 2);
					$preparedLines[$lineIndex]['total_ttc'] = ZSDecimal::round(ZSDecimal::add($lineHt, $lineVat), 2);
				}
			}

			$invoiceHt = ZSDecimal::add($invoiceHt, $group['total_ht']);
			$invoiceVat = ZSDecimal::add($invoiceVat, $group['total_tva']);
			$invoiceTtc = ZSDecimal::add($invoiceTtc, $group['total_ttc']);
		}
		unset($group);
		ksort($preparedLines);

		$plan = array(
			'rounding_method' => $roundingMethod,
			'lines' => array_values($preparedLines),
			'groups' => $groups,
			'total_ht' => ZSDecimal::round($invoiceHt, 2),
			'total_tva' => ZSDecimal::round($invoiceVat, 2),
			'total_ttc' => ZSDecimal::round($invoiceTtc, 2),
		);
		if ($roundingMethod === self::ROUND_SOURCE_LINE_HT) {
			$plan['source_ht_reconciliation'] = array(
				'reason' => 'vat_exclusive_authoritative_line_ht',
				'lines' => $sourceHtReconciliations,
			);
		}

		return $plan;
	}

	/**
	 * Select the source document's rounding method and require exact header parity.
	 *
	 * @return array
	 */
	public static function reconcileSource(array $lines, $globalDiscountPercent, $pricesIncludeVat, $sourceHt, $sourceVat, $sourceTtc, array $context = array())
	{
		$attempts = array();
		$allSourceTotals = !empty($lines);
		foreach ($lines as $line) {
			$allSourceTotals = $allSourceTotals
				&& is_array($line)
				&& array_key_exists('source_total_ht', $line)
				&& array_key_exists('source_total_ttc', $line);
		}

		if ($allSourceTotals) {
			try {
				$linePlan = self::calculate($lines, $globalDiscountPercent, $pricesIncludeVat, self::ROUND_SOURCE_LINE);
				if (self::totalsMatch($linePlan, $sourceHt, $sourceVat, $sourceTtc)) {
					$linePlan['source_context'] = $context;
					return $linePlan;
				}
				$attempts[] = self::formatPlan('source_line', $linePlan);
			} catch (Throwable $e) {
				$attempts[] = 'source_line:error=' . $e->getMessage();
			}
		}

		$groupLines = array();
		foreach ($lines as $line) {
			unset($line['source_total_ht'], $line['source_total_ttc']);
			$groupLines[] = $line;
		}
		$groupPlan = self::calculate($groupLines, $globalDiscountPercent, $pricesIncludeVat, self::ROUND_VAT_GROUP);
		if (self::totalsMatch($groupPlan, $sourceHt, $sourceVat, $sourceTtc)) {
			$groupPlan['source_context'] = $context;
			return $groupPlan;
		}
		$attempts[] = self::formatPlan('vat_group', $groupPlan);

		if (!$pricesIncludeVat && $allSourceTotals) {
			if (self::sourceLineTotalsMatchHeader($lines, $sourceHt, $sourceTtc)) {
				try {
					$sourceHtPlan = self::calculate($lines, $globalDiscountPercent, false, self::ROUND_SOURCE_LINE_HT);
					if (self::totalsMatch($sourceHtPlan, $sourceHt, $sourceVat, $sourceTtc)) {
						$sourceHtPlan['source_context'] = $context;
						return $sourceHtPlan;
					}
					$attempts[] = self::formatPlan('source_line_ht', $sourceHtPlan);
				} catch (Throwable $e) {
					$attempts[] = 'source_line_ht:error=' . $e->getMessage();
				}
			} else {
				$attempts[] = 'source_line_ht:error=Authoritative source line totals do not sum to the source header';
			}
		}

		if ($pricesIncludeVat && self::sourceSplitBalances($sourceHt, $sourceVat, $sourceTtc)) {
			if ($allSourceTotals) {
				if (self::sourceLineTotalsMatchHeader($lines, $sourceHt, $sourceTtc)) {
					try {
						$lineTtcPlan = self::calculate($lines, $globalDiscountPercent, true, self::ROUND_SOURCE_LINE_TTC);
						if (self::totalTtcMatches($lineTtcPlan, $sourceTtc)
							&& self::sourceSplitDifferenceWithinLineTolerance($lineTtcPlan, $sourceHt, count($lines))) {
							return self::attachSourceSplitNormalization($lineTtcPlan, $sourceHt, $sourceVat, $sourceTtc, $context);
						}
						$attempts[] = self::formatPlan('source_line_ttc', $lineTtcPlan);
					} catch (Throwable $e) {
						$attempts[] = 'source_line_ttc:error=' . $e->getMessage();
					}
				} else {
					$attempts[] = 'source_line_ttc:error=Authoritative source line totals do not sum to the source header';
				}
			} elseif (self::totalTtcMatches($groupPlan, $sourceTtc)
				&& self::sourceSplitDifferenceWithinLineTolerance($groupPlan, $sourceHt, count($lines))) {
				return self::attachSourceSplitNormalization($groupPlan, $sourceHt, $sourceVat, $sourceTtc, $context);
			}
		}

		self::assertSourceTotals($groupPlan, $sourceHt, $sourceVat, $sourceTtc, $context, $attempts);
		throw new RuntimeException('Unreachable invoice reconciliation state');
	}

	/**
	 * Require authoritative source totals to match one calculation.
	 *
	 * @return void
	 */
	public static function assertSourceTotals(array $calculation, $sourceHt, $sourceVat, $sourceTtc, array $context = array(), array $attempts = array())
	{
		$sourceHt = ZSDecimal::round((string) $sourceHt, 2);
		$sourceTtc = ZSDecimal::round((string) $sourceTtc, 2);
		$sourceVat = $sourceVat === null
			? ZSDecimal::subtract($sourceTtc, $sourceHt)
			: ZSDecimal::round((string) $sourceVat, 2);

		if (self::totalsMatch($calculation, $sourceHt, $sourceVat, $sourceTtc)) {
			return;
		}

		$groups = array();
		foreach ($calculation['groups'] as $group) {
			$groups[] = $group['vat_rate'] . '%:' . $group['total_ht'] . '/' . $group['total_tva'] . '/' . $group['total_ttc'];
		}
		$externalId = trim((string) ($context['external_id'] ?? 'unknown'));
		$invoiceNumber = trim((string) ($context['invoice_number'] ?? 'unknown'));
		$diffHt = ZSDecimal::subtract($calculation['total_ht'], $sourceHt);
		$diffVat = ZSDecimal::subtract($calculation['total_tva'], $sourceVat);
		$diffTtc = ZSDecimal::subtract($calculation['total_ttc'], $sourceTtc);

		throw new RuntimeException(
			'Invoice monetary reconciliation failed; external_id=' . $externalId
			. '; invoice=' . $invoiceNumber
			. '; source=' . $sourceHt . '/' . $sourceVat . '/' . $sourceTtc
			. '; calculated=' . $calculation['total_ht'] . '/' . $calculation['total_tva'] . '/' . $calculation['total_ttc']
			. '; difference=' . $diffHt . '/' . $diffVat . '/' . $diffTtc
			. '; vat_groups=[' . implode(', ', $groups) . ']'
			. (!empty($attempts) ? '; methods=[' . implode('; ', $attempts) . ']' : '')
		);
	}

	/** @return bool */
	private static function totalsMatch(array $calculation, $sourceHt, $sourceVat, $sourceTtc)
	{
		$sourceHt = ZSDecimal::round((string) $sourceHt, 2);
		$sourceTtc = ZSDecimal::round((string) $sourceTtc, 2);
		$sourceVat = $sourceVat === null
			? ZSDecimal::subtract($sourceTtc, $sourceHt)
			: ZSDecimal::round((string) $sourceVat, 2);

		return ZSDecimal::compare($sourceHt, $calculation['total_ht']) === 0
			&& ZSDecimal::compare($sourceVat, $calculation['total_tva']) === 0
			&& ZSDecimal::compare($sourceTtc, $calculation['total_ttc']) === 0;
	}

	/** @return bool */
	private static function totalTtcMatches(array $calculation, $sourceTtc)
	{
		return ZSDecimal::compare(
			ZSDecimal::round((string) $sourceTtc, 2),
			$calculation['total_ttc']
		) === 0;
	}

	/** @return bool */
	private static function sourceSplitBalances($sourceHt, $sourceVat, $sourceTtc)
	{
		if ($sourceVat === null) {
			return true;
		}

		return ZSDecimal::compare(
			ZSDecimal::add(
				ZSDecimal::round((string) $sourceHt, 2),
				ZSDecimal::round((string) $sourceVat, 2)
			),
			ZSDecimal::round((string) $sourceTtc, 2)
		) === 0;
	}

	/** @return bool */
	private static function sourceLineTotalsMatchHeader(array $lines, $sourceHt, $sourceTtc)
	{
		$linesHt = '0.00';
		$linesTtc = '0.00';
		foreach ($lines as $line) {
			$linesHt = ZSDecimal::add($linesHt, ZSDecimal::round((string) $line['source_total_ht'], 2));
			$linesTtc = ZSDecimal::add($linesTtc, ZSDecimal::round((string) $line['source_total_ttc'], 2));
		}

		return ZSDecimal::compare(ZSDecimal::round($linesHt, 2), ZSDecimal::round((string) $sourceHt, 2)) === 0
			&& ZSDecimal::compare(ZSDecimal::round($linesTtc, 2), ZSDecimal::round((string) $sourceTtc, 2)) === 0;
	}

	/** @return bool */
	private static function sourceSplitDifferenceWithinLineTolerance(array $plan, $sourceHt, $lineCount)
	{
		$maximumDifference = ZSDecimal::multiply((string) max(1, (int) $lineCount), '0.01');
		$actualDifference = ZSDecimal::absolute(
			ZSDecimal::subtract($plan['total_ht'], ZSDecimal::round((string) $sourceHt, 2))
		);

		return ZSDecimal::compare($actualDifference, $maximumDifference) <= 0;
	}

	/** @return array */
	private static function attachSourceSplitNormalization(array $plan, $sourceHt, $sourceVat, $sourceTtc, array $context)
	{
		$sourceHt = ZSDecimal::round((string) $sourceHt, 2);
		$sourceTtc = ZSDecimal::round((string) $sourceTtc, 2);
		$sourceVat = $sourceVat === null
			? ZSDecimal::subtract($sourceTtc, $sourceHt)
			: ZSDecimal::round((string) $sourceVat, 2);

		$plan['source_context'] = $context;
		$plan['source_split_normalization'] = array(
			'reason' => 'vat_inclusive_half_up',
			'source_total_ht' => $sourceHt,
			'source_total_tva' => $sourceVat,
			'source_total_ttc' => $sourceTtc,
			'legal_total_ht' => $plan['total_ht'],
			'legal_total_tva' => $plan['total_tva'],
			'legal_total_ttc' => $plan['total_ttc'],
			'difference_ht' => ZSDecimal::subtract($plan['total_ht'], $sourceHt),
			'difference_tva' => ZSDecimal::subtract($plan['total_tva'], $sourceVat),
		);

		return $plan;
	}

	/** @return string */
	private static function formatPlan($label, array $plan)
	{
		return $label . '=' . $plan['total_ht'] . '/' . $plan['total_tva'] . '/' . $plan['total_ttc'];
	}

	/** @return string */
	private static function applyDiscount($amount, $percent)
	{
		return ZSDecimal::divideByPowerOfTen(
			ZSDecimal::multiply($amount, ZSDecimal::subtract('100', $percent)),
			2
		);
	}

	/** @return string */
	private static function validatePercent($value, $label)
	{
		$value = ZSDecimal::normalize((string) $value);
		if (ZSDecimal::compare($value, '0') < 0 || ZSDecimal::compare($value, '100') > 0) {
			throw new InvalidArgumentException('Invalid ' . $label . ': ' . $value);
		}

		return $value;
	}

	/** @return string */
	private static function buildGroupKey(array $line, $vatRate)
	{
		return implode('|', array(
			$vatRate,
			trim((string) ($line['exemption'] ?? '')),
			ZSDecimal::normalize((string) ($line['localtax1_rate'] ?? '0')),
			trim((string) ($line['localtax1_type'] ?? '0')),
			ZSDecimal::normalize((string) ($line['localtax2_rate'] ?? '0')),
			trim((string) ($line['localtax2_type'] ?? '0')),
		));
	}
}

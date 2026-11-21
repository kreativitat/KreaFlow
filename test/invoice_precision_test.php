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

require_once dirname(__DIR__) . '/class/zsinvoicetaxcalculator.class.php';
require_once dirname(__DIR__) . '/class/trait/ZSInvoiceCreation.trait.php';
require_once dirname(__DIR__) . '/class/trait/ZSInvoiceExport.trait.php';
require_once dirname(__DIR__) . '/class/trait/ZSInvoiceMapping.trait.php';
require_once dirname(__DIR__) . '/class/trait/zslogger.trait.php';

if (!function_exists('dol_syslog')) {
	function dol_syslog($message, $level = LOG_INFO)
	{
		return true;
	}
}

class PrecisionCreationHarness
{
	use ZSInvoiceCreation;

	public function callNativeLine($invoice, array $line)
	{
		return $this->addCustomerInvoiceLineUsingNativeApi($invoice, $line, 101, 'TTC', '12.30');
	}
}

class PrecisionFakeInvoice
{
	public $arguments = [];

	public function addline()
	{
		$this->arguments = func_get_args();
		return 1;
	}
}

class PrecisionExportHarness
{
	use ZSInvoiceExport;

	public $error = '';

	public function validateInvoice($invoice, array $payloadLines, $pricesIncludeVat = 0)
	{
		return $this->validateDolibarrInvoiceExportTotals($invoice, $payloadLines, $pricesIncludeVat);
	}

	public function buildIntegratedLines(array $lines, array $context)
	{
		return $this->buildZsbmsIntegratedDocumentLinesFromManualInput($lines, $context);
	}
}

class PrecisionLoggerHarness
{
	use ZSLogger;

	public function partialResult($successCount, $failedCount)
	{
		return $this->cronPartialSuccess('precision_test', $successCount, $failedCount);
	}
}

class PrecisionMappingHarness
{
	use ZSInvoiceMapping;

	public $error = '';

	public function isExcludedZsbmsDocumentType($documentType)
	{
		return false;
	}

	public function customerValidation($customer, $name)
	{
		return 1;
	}

	public function findDolibarrProdIdFromCodigo($code, $line = null)
	{
		return 0;
	}

	public function logDebug($operation, $message, array $context = [])
	{
		return true;
	}

	public function logOperationWarning($operation, $message, array $context = [])
	{
		return true;
	}

	public function logOperationError($operation, $message, array $context = [])
	{
		return false;
	}
}

$assertions = 0;
$assertSame = function ($expected, $actual, $label) use (&$assertions) {
	$assertions++;
	if ($expected !== $actual) {
		fwrite(STDERR, "FAIL: {$label}; expected=" . var_export($expected, true) . '; actual=' . var_export($actual, true) . "\n");
		exit(1);
	}
};

// Case 1: VAT is rounded once for the complete 23% group and its residual
// remains on the last real line in that group.
$case1Lines = [
	['quantity' => '1', 'unit_price' => '2.37', 'vat_rate' => '23'],
	['quantity' => '1', 'unit_price' => '2.37', 'vat_rate' => '23'],
];
$case1 = ZSInvoiceTaxCalculator::calculate($case1Lines);
$assertSame('vat_group', $case1['rounding_method'], 'default calculation uses VAT groups');
$assertSame('4.74', $case1['total_ht'], 'two-line taxable base');
$assertSame('1.09', $case1['total_tva'], 'group VAT is not 1.10');
$assertSame('5.83', $case1['total_ttc'], 'two-line TTC');
$assertSame('0.54', $case1['lines'][1]['total_tva'], 'VAT residual stays in the same group');

// The source may instead use legally rounded line VAT. The reconciler selects
// that method only when the line totals and header agree.
$sourceLineMethod = ZSInvoiceTaxCalculator::reconcileSource([
	['quantity' => '1', 'unit_price' => '2.37', 'vat_rate' => '23', 'source_total_ht' => '2.37', 'source_total_ttc' => '2.92'],
	['quantity' => '1', 'unit_price' => '2.37', 'vat_rate' => '23', 'source_total_ht' => '2.37', 'source_total_ttc' => '2.92'],
], '0', false, '4.74', '1.10', '5.84');
$assertSame('source_line', $sourceLineMethod['rounding_method'], 'source line method detected');
$assertSame('1.10', $sourceLineMethod['total_tva'], 'source line VAT retained');

$sourceGroupMethod = ZSInvoiceTaxCalculator::reconcileSource([
	['quantity' => '1', 'unit_price' => '2.37', 'vat_rate' => '23', 'source_total_ht' => '2.37', 'source_total_ttc' => '2.92'],
	['quantity' => '1', 'unit_price' => '2.37', 'vat_rate' => '23', 'source_total_ht' => '2.37', 'source_total_ttc' => '2.92'],
], '0', false, '4.74', '1.09', '5.83');
$assertSame('vat_group', $sourceGroupMethod['rounding_method'], 'source group method detected');

// Synthetic fractional source lines round the taxable amount before VAT.
// The first line has raw HT 0.1065: VAT on rounded HT is 0.03, while VAT
// on the unrounded multiplication would be 0.02.
$fractionalSourceLines = [
	['quantity' => '0.071', 'unit_price' => '1.50', 'vat_rate' => '23', 'source_total_ht' => '0.11', 'source_total_ttc' => '0.14'],
	['quantity' => '2', 'unit_price' => '0.25', 'vat_rate' => '6', 'source_total_ht' => '0.50', 'source_total_ttc' => '0.53'],
	['quantity' => '1', 'unit_price' => '0.10', 'vat_rate' => '13', 'source_total_ht' => '0.10', 'source_total_ttc' => '0.11'],
];
$fractionalSourceFixture = ZSInvoiceTaxCalculator::reconcileSource(
	$fractionalSourceLines, '0', false, '0.71', '0.07', '0.78'
);
$assertSame('source_line', $fractionalSourceFixture['rounding_method'], 'fractional fixture retains the source line VAT method');
$assertSame('0.71', $fractionalSourceFixture['total_ht'], 'fractional fixture exact HT');
$assertSame('0.07', $fractionalSourceFixture['total_tva'], 'fractional fixture exact VAT');
$assertSame('0.78', $fractionalSourceFixture['total_ttc'], 'fractional fixture exact TTC');
$assertSame('0.03', $fractionalSourceFixture['lines'][0]['total_tva'], 'fractional line VAT uses rounded taxable HT');

// Synthetic truncated-price evidence allows precisely one cent of HT
// normalization, with exact source line/header sums and VAT recomputed from HT.
$sourceHtLines = [
	['quantity' => '0.1', 'unit_price' => '3.20', 'vat_rate' => '23', 'source_total_ht' => '0.33', 'source_total_ttc' => '0.41'],
	['quantity' => '2', 'unit_price' => '0.25', 'vat_rate' => '6', 'source_total_ht' => '0.50', 'source_total_ttc' => '0.53'],
];
$sourceHtFixture = ZSInvoiceTaxCalculator::reconcileSource(
	$sourceHtLines, '0', false, '0.83', null, '0.94',
	['external_id' => '1|FT|SYNTHETIC|1', 'invoice_number' => 'FT_SYNTHETIC_1']
);
$assertSame('source_line_ht', $sourceHtFixture['rounding_method'], 'synthetic fixture uses guarded VAT-exclusive source HT reconciliation');
$assertSame('0.83', $sourceHtFixture['total_ht'], 'source HT fixture exact HT');
$assertSame('0.11', $sourceHtFixture['total_tva'], 'source HT fixture exact VAT');
$assertSame('0.94', $sourceHtFixture['total_ttc'], 'source HT fixture exact TTC');
$assertSame('0.33', $sourceHtFixture['lines'][0]['total_ht'], 'fractional line retains authoritative HT');
$assertSame('0.08', $sourceHtFixture['lines'][0]['total_tva'], 'fractional line VAT derives from authoritative HT');
$assertSame(1, count($sourceHtFixture['source_ht_reconciliation']['lines']), 'fixture audits only the one adjusted source line');
$assertSame(0, $sourceHtFixture['source_ht_reconciliation']['lines'][0]['line_index'], 'fixture audits the expected source line index');
$assertSame('0.01', $sourceHtFixture['source_ht_reconciliation']['lines'][0]['difference_ht'], 'fixture records the one-cent HT difference');

$oversizedExclusiveHtDifferenceRejected = false;
try {
	ZSInvoiceTaxCalculator::reconcileSource([
		['quantity' => '1', 'unit_price' => '1', 'vat_rate' => '23', 'source_total_ht' => '1.02', 'source_total_ttc' => '1.25'],
	], '0', false, '1.02', null, '1.25');
} catch (RuntimeException $e) {
	$oversizedExclusiveHtDifferenceRejected = strpos($e->getMessage(), 'source_line_ht:error=') !== false
		&& strpos($e->getMessage(), 'more than one cent') !== false;
}
$assertSame(true, $oversizedExclusiveHtDifferenceRejected, 'VAT-exclusive source HT differences above one cent fail closed');

$inconsistentExclusiveVatRejected = false;
try {
	ZSInvoiceTaxCalculator::reconcileSource([
		['quantity' => '1', 'unit_price' => '1', 'vat_rate' => '23', 'source_total_ht' => '1.01', 'source_total_ttc' => '1.25'],
	], '0', false, '1.01', null, '1.25');
} catch (RuntimeException $e) {
	$inconsistentExclusiveVatRejected = strpos($e->getMessage(), 'source_line_ht:error=') !== false
		&& strpos($e->getMessage(), 'inconsistent with quantity, price, discount, and VAT') !== false;
}
$assertSame(true, $inconsistentExclusiveVatRejected, 'VAT-exclusive source HT tolerance rejects inconsistent VAT and TTC');

$exclusiveLineHeaderMismatchRejected = false;
try {
	ZSInvoiceTaxCalculator::reconcileSource([
		['quantity' => '0.1', 'unit_price' => '3.20', 'vat_rate' => '23', 'source_total_ht' => '0.33', 'source_total_ttc' => '0.41'],
	], '0', false, '0.34', null, '0.42');
} catch (RuntimeException $e) {
	$exclusiveLineHeaderMismatchRejected = strpos($e->getMessage(), 'source_line_ht:error=Authoritative source line totals do not sum to the source header') !== false;
}
$assertSame(true, $exclusiveLineHeaderMismatchRejected, 'VAT-exclusive source HT tolerance requires exact source line and header totals');

// A VAT-inclusive source may report a one-cent downward HT/VAT split while
// retaining a valid line TTC. TTC remains authoritative and the legal split is
// recomputed line by line with half-up rounding.
$inclusiveNormalized = ZSInvoiceTaxCalculator::reconcileSource([
	['quantity' => '1', 'unit_price' => '1', 'vat_rate' => '13', 'source_total_ht' => '0.89', 'source_total_ttc' => '1.00'],
], '0', true, '0.89', null, '1.00', [
	'external_id' => '18|FS|A|1',
	'invoice_number' => 'FS_A_1',
]);
$assertSame('source_line_ttc', $inclusiveNormalized['rounding_method'], 'VAT-inclusive source TTC normalization selected');
$assertSame('0.88', $inclusiveNormalized['total_ht'], 'VAT-inclusive normalized HT');
$assertSame('0.12', $inclusiveNormalized['total_tva'], 'VAT-inclusive normalized VAT');
$assertSame('1.00', $inclusiveNormalized['total_ttc'], 'VAT-inclusive authoritative TTC retained');
$assertSame('vat_inclusive_half_up', $inclusiveNormalized['source_split_normalization']['reason'], 'source split normalization is audited');
$assertSame('0.01', $inclusiveNormalized['source_split_normalization']['difference_tva'], 'normalized VAT difference is explicit');

// ZoneSoft can expose a unit price truncated relative to its authoritative
// legal line cents. A one-cent TTC delta is accepted only when the line and
// header totals remain internally exact.
$truncatedInclusivePrice = ZSInvoiceTaxCalculator::reconcileSource([
	['quantity' => '0.123', 'unit_price' => '4', 'vat_rate' => '23', 'source_total_ht' => '0.41', 'source_total_ttc' => '0.50'],
], '0', true, '0.41', null, '0.50');
$assertSame('source_line_ttc', $truncatedInclusivePrice['rounding_method'], 'one-cent truncated inclusive unit-price delta uses authoritative TTC');
$assertSame('0.41', $truncatedInclusivePrice['total_ht'], 'truncated inclusive unit-price legal HT');
$assertSame('0.09', $truncatedInclusivePrice['total_tva'], 'truncated inclusive unit-price legal VAT');
$assertSame('0.50', $truncatedInclusivePrice['total_ttc'], 'truncated inclusive unit-price authoritative TTC');

$oversizedInclusiveTtcDifferenceRejected = false;
try {
	ZSInvoiceTaxCalculator::reconcileSource([
		['quantity' => '1', 'unit_price' => '1', 'vat_rate' => '23', 'source_total_ht' => '0.83', 'source_total_ttc' => '1.02'],
	], '0', true, '0.83', null, '1.02');
} catch (RuntimeException $e) {
	$oversizedInclusiveTtcDifferenceRejected = strpos($e->getMessage(), 'TTC differs') !== false
		&& strpos($e->getMessage(), 'more than one cent') !== false;
}
$assertSame(true, $oversizedInclusiveTtcDifferenceRejected, 'VAT-inclusive source TTC differences above one cent fail closed');

// Synthetic VAT-inclusive lines normalize two one-cent source splits inside
// one VAT group while retaining a second VAT rate and every source TTC.
$sourceTtcFixture = ZSInvoiceTaxCalculator::reconcileSource([
	['quantity' => '1', 'unit_price' => '0.20', 'vat_rate' => '13', 'source_total_ht' => '0.19', 'source_total_ttc' => '0.20'],
	['quantity' => '1', 'unit_price' => '0.20', 'vat_rate' => '13', 'source_total_ht' => '0.19', 'source_total_ttc' => '0.20'],
	['quantity' => '1', 'unit_price' => '0.30', 'vat_rate' => '23', 'source_total_ht' => '0.24', 'source_total_ttc' => '0.30'],
], '0', true, '0.62', null, '0.70');
$assertSame('source_line_ttc', $sourceTtcFixture['rounding_method'], 'synthetic fixture uses legal source-line TTC normalization');
$assertSame('0.60', $sourceTtcFixture['total_ht'], 'source TTC fixture legal HT');
$assertSame('0.10', $sourceTtcFixture['total_tva'], 'source TTC fixture legal VAT');
$assertSame('0.70', $sourceTtcFixture['total_ttc'], 'source TTC fixture TTC retained');
$assertSame('0.18', $sourceTtcFixture['lines'][0]['total_ht'], 'first synthetic line HT corrected');
$assertSame('0.02', $sourceTtcFixture['lines'][1]['total_tva'], 'second synthetic line VAT corrected');

$oversizedInclusiveDifferenceRejected = false;
try {
	ZSInvoiceTaxCalculator::reconcileSource([
		['quantity' => '1', 'unit_price' => '1', 'vat_rate' => '13', 'source_total_ht' => '0.50', 'source_total_ttc' => '1.00'],
	], '0', true, '0.50', null, '1.00');
} catch (RuntimeException $e) {
	$oversizedInclusiveDifferenceRejected = strpos($e->getMessage(), 'more than one cent') !== false;
}
$assertSame(true, $oversizedInclusiveDifferenceRejected, 'large VAT-inclusive source split differences still fail closed');

// Case 2: commercial half-up rounding.
$assertSame('1.23', ZSDecimal::round('1.234', 2), 'half-up below midpoint');
$assertSame('1.24', ZSDecimal::round('1.235', 2), 'half-up exact midpoint');
$assertSame('0.55', ZSDecimal::round('0.5451', 2), 'half-up above midpoint');
$assertSame('-1.24', ZSDecimal::round('-1.235', 2), 'half-up credit-note midpoint');
$assertSame('1.2349999999999999995', ZSDecimal::divideByPowerOfTen('123.49999999999999995', 2), 'power-of-ten division preserves all digits');

$fullPrecision = ZSInvoiceTaxCalculator::calculate([
	['quantity' => '1', 'unit_price' => '1.2349999999999999995', 'vat_rate' => '0'],
]);
$assertSame('1.23', $fullPrecision['total_ht'], 'intermediate percentage steps do not double-round high precision');

// Case 3: independent tax combinations.
$case3 = ZSInvoiceTaxCalculator::calculate([
	['quantity' => '1', 'unit_price' => '10', 'vat_rate' => '6'],
	['quantity' => '1', 'unit_price' => '10', 'vat_rate' => '13'],
	['quantity' => '1', 'unit_price' => '10', 'vat_rate' => '23'],
]);
$assertSame('30.00', $case3['total_ht'], 'multiple-rate HT');
$assertSame('4.20', $case3['total_tva'], 'multiple-rate VAT');
$assertSame('34.20', $case3['total_ttc'], 'multiple-rate TTC');
$assertSame(3, count($case3['groups']), 'VAT rates remain separate');

$exemptionGroups = ZSInvoiceTaxCalculator::calculate([
	['quantity' => '1', 'unit_price' => '2.37', 'vat_rate' => '23', 'exemption' => 'A'],
	['quantity' => '1', 'unit_price' => '2.37', 'vat_rate' => '23', 'exemption' => 'B'],
]);
$assertSame(2, count($exemptionGroups['groups']), 'different exemption attributes remain separate');
$assertSame('1.10', $exemptionGroups['total_tva'], 'each tax combination rounds independently');

// Case 4: VAT-inclusive extraction.
$case4 = ZSInvoiceTaxCalculator::calculate([
	['quantity' => '1', 'unit_price' => '12.30', 'vat_rate' => '23'],
], '0', true);
$assertSame('10.00', $case4['total_ht'], 'inclusive HT extraction');
$assertSame('2.30', $case4['total_tva'], 'inclusive VAT extraction');
$assertSame('12.30', $case4['total_ttc'], 'inclusive TTC preservation');

// Case 5: line and global discounts precede VAT.
$case5 = ZSInvoiceTaxCalculator::calculate([
	['quantity' => '2', 'unit_price' => '10', 'discount_percent' => '10', 'vat_rate' => '23'],
], '10');
$assertSame('16.20', $case5['total_ht'], 'discounted taxable base');
$assertSame('3.73', $case5['total_tva'], 'VAT after both discounts');
$assertSame('19.93', $case5['total_ttc'], 'discounted TTC');

$fixedDiscount = ZSInvoiceTaxCalculator::reconcileSource([
	[
		'quantity' => '0.25',
		'unit_price' => '2.00',
		'discount_percent' => '0',
		'discount_amount' => '0.03',
		'vat_rate' => '23',
		'source_total_ht' => '0.38',
		'source_total_ttc' => '0.47',
	],
], '0', true, '0.38', null, '0.47');
$assertSame('source_line', $fixedDiscount['rounding_method'], 'fixed line discount retains valid source-line method');
$assertSame('0.38', $fixedDiscount['total_ht'], 'fixed line discount applies before VAT');
$assertSame('0.09', $fixedDiscount['total_tva'], 'fixed line discount VAT');
$assertSame('0.47', $fixedDiscount['total_ttc'], 'fixed line discount TTC');

$negativeFixedDiscount = ZSInvoiceTaxCalculator::reconcileSource([
	[
		'quantity' => '0.125',
		'unit_price' => '2.00',
		'discount_percent' => '0',
		'discount_amount' => '-0.02',
		'vat_rate' => '23',
		'source_total_ht' => '0.22',
		'source_total_ttc' => '0.27',
	],
], '0', true, '0.22', null, '0.27');
$assertSame('source_line', $negativeFixedDiscount['rounding_method'], 'negative fixed discount preserves the source-line method');
$assertSame('0.22', $negativeFixedDiscount['total_ht'], 'negative fixed discount surcharge applies before VAT');
$assertSame('0.05', $negativeFixedDiscount['total_tva'], 'negative fixed discount surcharge VAT');
$assertSame('0.27', $negativeFixedDiscount['total_ttc'], 'negative fixed discount surcharge TTC');

$conf = new stdClass();
$conf->global = new stdClass();
$conf->global->ZS_IVA_INCLUIDO = 1;
$mappedNegativeFixedDiscount = (new PrecisionMappingHarness())->mapZSToDolibarrInvoice((object) [
	'doc' => 'FT',
	'serie' => 'SYNTHETIC',
	'numero' => 1,
	'data' => '2000-01-01',
	'datahora' => '2000-01-01 12:00:00',
	'loja' => 2,
	'countrycode' => 'PT',
	'contribuinte' => '999999990',
	'nome' => 'Test Customer',
	'liquido' => '0.22',
	'total' => '0.27',
	'dpercent' => '0',
	'descontos' => '0',
	'emp' => 1,
	'vendas' => [(object) [
		'codigo' => 1,
		'descricao' => 'Fractional test line',
		'qtd' => '0.125',
		'punit' => '2.00',
		'liquido' => '0.22',
		'total' => '0.27',
		'iva' => '23',
		'desconto' => '-0.02',
		'desconto2' => '0',
	]],
]);
$assertSame(true, is_array($mappedNegativeFixedDiscount), 'customer mapping accepts a signed fixed-discount surcharge');
$assertSame('0.27', $mappedNegativeFixedDiscount['monetary_plan']['total_ttc'], 'mapped signed surcharge reconciles exact TTC');
$assertSame(true, ZSDecimal::compare($mappedNegativeFixedDiscount['lines'][0]['remise_percent'], '0') < 0, 'mapped signed surcharge remains effective on the native line');

$mappedRedundantDiscountAmount = (new PrecisionMappingHarness())->mapZSToDolibarrInvoice((object) [
	'doc' => 'FT',
	'serie' => 'SYNTHETIC',
	'numero' => 2,
	'data' => '2000-01-01',
	'datahora' => '2000-01-01 12:00:00',
	'loja' => 4,
	'countrycode' => 'PT',
	'contribuinte' => '999999990',
	'nome' => 'Test Customer',
	'liquido' => '0.44',
	'total' => '0.50',
	'dpercent' => '0',
	'descontos' => '0',
	'emp' => 1,
	'vendas' => [(object) [
		'codigo' => 2,
		'descricao' => 'Percentage discount test line',
		'qtd' => '1',
		'punit' => '2.51',
		'liquido' => '0.44',
		'total' => '0.50',
		'iva' => '13',
		'desconto' => '2.01',
		'desconto2' => '80',
	]],
]);
$assertSame(true, is_array($mappedRedundantDiscountAmount), 'customer mapping accepts a percentage discount with its rounded monetary representation');
$assertSame('0.50', $mappedRedundantDiscountAmount['monetary_plan']['total_ttc'], 'redundant monetary discount is not applied twice');
$assertSame('80', $mappedRedundantDiscountAmount['lines'][0]['remise_percent'], 'native line retains the source percentage discount');
$assertSame('0', $mappedRedundantDiscountAmount['lines'][0]['remise'], 'redundant monetary discount is not retained as an additional fixed adjustment');

// A fixed amount can own the cents even when its informational percentage uses another price basis.
$fixedDiscountFixture = function ($price, $percent, $ht, $ttc, $global = '0') {
	return (object) [
		'doc' => 'FT', 'serie' => 'SYNTHETIC', 'numero' => 1, 'data' => '2000-01-01',
		'loja' => 1, 'countrycode' => 'PT', 'contribuinte' => '999999990', 'nome' => 'Test Customer',
		'liquido' => $ht, 'total' => $ttc, 'dpercent' => $global, 'descontos' => '0',
		'vendas' => [(object) [
			'codigo' => 1, 'descricao' => 'Fixed discount fixture', 'qtd' => '1', 'punit' => $price,
			'liquido' => $ht, 'total' => $ttc, 'iva' => '13', 'desconto' => '1', 'desconto2' => $percent,
		]],
	];
};
foreach ([['4', '24', '2.65', '3.00'], ['3', '33', '1.77', '2.00'], ['2.5', '37', '1.33', '1.50'], ['4', '26', '2.65', '3.00']] as $values) {
	$harness = new PrecisionMappingHarness();
	$mapped = $harness->mapZSToDolibarrInvoice($fixedDiscountFixture(...$values));
	$assertSame(true, is_array($mapped), 'rounded percent of fixed discount maps: ' . $harness->error);
	$assertSame($values[2], $mapped['monetary_plan']['total_ht'], 'fixed amount preserves authoritative HT');
	$assertSame($values[3], $mapped['monetary_plan']['total_ttc'], 'fixed amount preserves authoritative TTC');
	$assertSame('1', $mapped['lines'][0]['remise'], 'native line retains the fixed discount');
	$assertSame($values[1], $mapped['lines'][0]['source_remise_percent'], 'original rounded percentage remains evidence');
	$assertSame('0', $mapped['monetary_plan']['lines'][0]['discount_percent'], 'calculator applies the fixed amount once');
}
// Invented compact split menu includes free components and two VAT rates.
$splitMenu = $fixedDiscountFixture('4', '24', '2.65', '3.00');
$splitMenu->liquido = '3.15';
$splitMenu->total = '3.59';
foreach ([
	['0', '0', '0', '13'], ['0.25', '0.20', '0.25', '23'],
	['0', '0', '0', '23'], ['0.34', '0.30', '0.34', '13'],
] as $row) {
	$splitMenu->vendas[] = (object) [
		'codigo' => 1, 'descricao' => 'Synthetic menu component', 'qtd' => '1',
		'punit' => $row[0], 'liquido' => $row[1], 'total' => $row[2], 'iva' => $row[3],
		'desconto' => '0', 'desconto2' => '0',
	];
}
$splitHarness = new PrecisionMappingHarness();
$splitMapped = $splitHarness->mapZSToDolibarrInvoice($splitMenu);
$assertSame(true, is_array($splitMapped), 'split-menu source discount maps: ' . $splitHarness->error);
$assertSame(5, count($splitMapped['lines']), 'split-menu source lines are preserved');
$assertSame('3.15', $splitMapped['monetary_plan']['total_ht'], 'split-menu source HT is preserved');
$assertSame('0.44', $splitMapped['monetary_plan']['total_tva'], 'split-menu VAT is preserved');
$assertSame('3.59', $splitMapped['monetary_plan']['total_ttc'], 'split-menu source TTC is preserved');
$assertSame('24', $splitMapped['lines'][0]['source_remise_percent'], 'split-menu source percentage remains evidence');
$withGlobal = (new PrecisionMappingHarness())->mapZSToDolibarrInvoice($fixedDiscountFixture('4', '24', '2.39', '2.70', '10'));
$assertSame('2.70', $withGlobal['monetary_plan']['total_ttc'], 'global discount remains additional to the fixed line discount');
$combined = (new PrecisionMappingHarness())->mapZSToDolibarrInvoice($fixedDiscountFixture('4', '30', '1.59', '1.80'));
$assertSame(true, is_array($combined), 'independent fixed and percentage discounts still map');
$assertSame('30', $combined['monetary_plan']['lines'][0]['discount_percent'], 'independent percentage is retained');
$assertSame('1', $combined['monetary_plan']['lines'][0]['discount_amount'], 'independent amount is retained');
foreach ([['4', '25', '2.65', '3.02'], ['4', '25', '2.50', '3.00'], ['2.5', '37', '1.33', '1.52'], ['2.5', '37', '1.00', '1.50']] as $values) {
	$assertSame(false, (new PrecisionMappingHarness())->mapZSToDolibarrInvoice($fixedDiscountFixture(...$values)),
		'fixed discount normalization cannot hide inconsistent TTC or HT');
}
$missingHt = $fixedDiscountFixture('4', '24', '2.65', '3.00');
unset($missingHt->vendas[0]->liquido);
$assertSame(false, (new PrecisionMappingHarness())->mapZSToDolibarrInvoice($missingHt), 'normalization requires authoritative HT and TTC');
$conf->global->ZS_IVA_INCLUIDO = 0;
$exclusive = (new PrecisionMappingHarness())->mapZSToDolibarrInvoice($fixedDiscountFixture('4', '24', '3.00', '3.39'));
$assertSame('3.00', $exclusive['monetary_plan']['total_ht'], 'VAT-exclusive fixed discount preserves HT');
$assertSame('3.39', $exclusive['monetary_plan']['total_ttc'], 'VAT-exclusive fixed discount preserves TTC');
$conf->global->ZS_IVA_INCLUIDO = 1;

$oversizedFixedDiscountRejected = false;
try {
	ZSInvoiceTaxCalculator::calculate([[
		'quantity' => '1',
		'unit_price' => '1',
		'discount_amount' => '1.01',
		'vat_rate' => '23',
	]]);
} catch (InvalidArgumentException $e) {
	$oversizedFixedDiscountRejected = strpos($e->getMessage(), 'cannot exceed') !== false;
}
$assertSame(true, $oversizedFixedDiscountRejected, 'fixed line discount cannot exceed the line amount');

// Case 6: fractional inputs retain their source precision.
$case6 = ZSInvoiceTaxCalculator::calculate([
	['quantity' => '1.234', 'unit_price' => '2.345', 'vat_rate' => '23'],
]);
$assertSame('1.234', $case6['lines'][0]['quantity'], 'fractional quantity retained');
$assertSame('2.345', $case6['lines'][0]['unit_price'], 'fractional unit price retained');
$assertSame('2.89', $case6['total_ht'], 'fractional base rounded once');
$assertSame('0.67', $case6['total_tva'], 'fractional VAT');

// Case 7: a signed credit calculation applies one sign only.
$case7 = ZSInvoiceTaxCalculator::calculate([
	['quantity' => '-2', 'unit_price' => '2.37', 'vat_rate' => '23'],
]);
$assertSame('-4.74', $case7['total_ht'], 'credit HT sign');
$assertSame('-1.09', $case7['total_tva'], 'credit VAT sign');
$assertSame('-5.83', $case7['total_ttc'], 'credit TTC sign');

// Case 8: repeated calculation is byte-for-byte idempotent.
$assertSame(serialize($case1), serialize(ZSInvoiceTaxCalculator::calculate($case1Lines)), 'repeat calculation is idempotent');

// Case 9: irreconcilable totals include all required audit context.
$caught = '';
try {
	ZSInvoiceTaxCalculator::assertSourceTotals(
		$case1,
		'4.74',
		'1.10',
		'5.84',
		['external_id' => '2|FT|A|42', 'invoice_number' => 'FT_A_42']
	);
} catch (RuntimeException $e) {
	$caught = $e->getMessage();
}
$assertSame(true, strpos($caught, 'external_id=2|FT|A|42') !== false, 'error contains external identifier');
$assertSame(true, strpos($caught, 'invoice=FT_A_42') !== false, 'error contains invoice number');
$assertSame(true, strpos($caught, 'source=4.74/1.10/5.84') !== false, 'error contains source totals');
$assertSame(true, strpos($caught, 'calculated=4.74/1.09/5.83') !== false, 'error contains calculated totals');
$assertSame(true, strpos($caught, 'difference=') !== false, 'error contains differences');
$assertSame(true, strpos($caught, 'vat_groups=[') !== false, 'error contains VAT groups');

$localTaxRejected = false;
try {
	ZSInvoiceTaxCalculator::calculate([[
		'quantity' => '1',
		'unit_price' => '10',
		'vat_rate' => '23',
		'localtax1_rate' => '1',
	]]);
} catch (InvalidArgumentException $e) {
	$localTaxRejected = strpos($e->getMessage(), 'Non-zero local taxes') !== false;
}
$assertSame(true, $localTaxRejected, 'unsupported local tax fails closed');

$invalidSourceLineRejected = false;
try {
	ZSInvoiceTaxCalculator::reconcileSource([
		['quantity' => '1', 'unit_price' => '2.37', 'vat_rate' => '23', 'source_total_ht' => '3.00', 'source_total_ttc' => '3.69'],
	], '0', false, '3.00', '0.69', '3.69');
} catch (RuntimeException $e) {
	$invalidSourceLineRejected = strpos($e->getMessage(), 'source_line:error=') !== false;
}
$assertSame(true, $invalidSourceLineRejected, 'source line totals must derive from quantity, price, discount, and VAT');

// Production guards: reconciliation writes real line totals, never a rounding line.
$customerSource = file_get_contents(dirname(__DIR__) . '/class/zsinvoicesynch.class.php');
$supplierSource = file_get_contents(dirname(__DIR__) . '/class/zsstoreinvoiceconverter.class.php');
$creationSource = file_get_contents(dirname(__DIR__) . '/class/trait/ZSInvoiceCreation.trait.php');
$paymentSource = file_get_contents(dirname(__DIR__) . '/class/trait/ZSPaymentHandling.trait.php');
$exportSource = file_get_contents(dirname(__DIR__) . '/class/trait/ZSInvoiceExport.trait.php');
$assertSame(false, strpos($customerSource, "addInvoiceAdjustmentLine(\$facture") !== false, 'customer orchestration creates no rounding line');
$assertSame(false, strpos($supplierSource, "'Rounding Adjustment'") !== false, 'supplier orchestration creates no rounding line');
$assertSame(false, strpos($customerSource, 'Zone Soft - Acerto') !== false, 'fallback creates no adjustment line');
$assertSame(false, strpos($paymentSource, '-$line->subprice') !== false, 'credit note does not reverse the unit-price sign twice');
$assertSame(true, strpos($customerSource, 'persistExactCustomerInvoiceLineTotals') !== false, 'customer line totals are persisted exactly');
$assertSame(true, strpos($customerSource, "update_price(1, 'none')") !== false, 'customer header sums stored lines');
$assertSame(true, strpos($supplierSource, "update_price(1, 'none')") !== false, 'supplier header sums stored lines');
$assertSame(true, strpos($supplierSource, 'verifyExistingSupplierInvoiceMonetaryPlan') !== false, 'existing supplier invoices require exact plan verification');
$assertSame(true, strpos($supplierSource, 'if ($reusedExistingInvoice)') !== false, 'existing supplier invoices bypass line rewriting');
$assertSame(true, strpos($supplierSource, 'Existing supplier invoice header or line sums do not match the source monetary plan') !== false, 'existing supplier invoice mismatches fail closed');
$assertSame(true, strpos($exportSource, 'validateDolibarrInvoiceExportTotals') !== false, 'outbound invoice totals are preflighted');
$assertSame(true, strpos($customerSource, 'ZSSyncStatus::MAPPING_ERROR, $mappingError') !== false, 'persistent mapping status retains the detailed error');

$loggerHarness = new PrecisionLoggerHarness();
$assertSame('ERROR: Operation failed: 0 succeeded, 21 failed', $loggerHarness->partialResult(0, 21), 'all-failed cron batch returns an error');
$assertSame(0, $loggerHarness->partialResult(1, 20), 'partially successful cron batch remains successful with warnings');

$exportInvoice = (object) [
	'id' => 42,
	'ref' => 'FT-A-42',
	'total_ht' => '4.74',
	'total_tva' => '1.09',
	'total_ttc' => '5.83',
	'lines' => [
		(object) ['qty' => '1', 'tva_tx' => '23', 'total_ht' => '2.37', 'total_tva' => '0.55', 'total_ttc' => '2.92'],
		(object) ['qty' => '1', 'tva_tx' => '23', 'total_ht' => '2.37', 'total_tva' => '0.54', 'total_ttc' => '2.91'],
	],
];
$exportHarness = new PrecisionExportHarness();
$assertSame(true, $exportHarness->validateInvoice($exportInvoice, [
	['qtd' => 1.0, 'punit' => 2.37, 'desconto' => 0.0],
	['qtd' => 1.0, 'punit' => 2.37, 'desconto' => 0.0],
]), 'outbound payload preserves group-rounded stored totals');

$manualIntegrated = $exportHarness->buildIntegratedLines([
	['codigo' => '1', 'qtd' => '1', 'punit' => '2.37', 'desconto' => '0', 'vat_tx' => '23'],
	['codigo' => '2', 'qtd' => '1', 'punit' => '2.37', 'desconto' => '0', 'vat_tx' => '23'],
], [
	'doc' => 'FA',
	'store' => 1,
	'numero' => 42,
	'serie' => 'A',
	'date' => '2000-01-01',
	'date_time' => '2000-01-01 12:00:00',
	'emp' => 1,
	'ivaincluido' => 0,
]);
$assertSame('5.83', $manualIntegrated['totals']['ttc'], 'manual outbound VAT group totals 5.83');

$fakeInvoice = new PrecisionFakeInvoice();
$creationHarness = new PrecisionCreationHarness();
$creationHarness->callNativeLine($fakeInvoice, [
	'desc' => 'Precision line',
	'qty' => '1.234',
	'tva_tx' => '23',
	'remise_percent' => '10',
	'date_start' => '',
	'date_end' => '',
	'array_options' => ['options_zs_posto' => '1'],
]);
$assertSame(31, count($fakeInvoice->arguments), 'Dolibarr 23 addline argument count');
$assertSame('TTC', $fakeInvoice->arguments[13], 'price base reaches argument 14');
$assertSame('12.30', $fakeInvoice->arguments[14], 'TTC price reaches argument 15');
$assertSame(0, $fakeInvoice->arguments[15], 'product type reaches argument 16');
$assertSame(['options_zs_posto' => '1'], $fakeInvoice->arguments[24], 'extrafields reach argument 25');
$assertSame(1, $fakeInvoice->arguments[30], 'per-line header recalculation is deferred');

echo 'PASS: ' . $assertions . " invoice precision assertions\n";

<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

require_once dirname(__DIR__) . '/class/zsinvoicetaxcalculator.class.php';
require_once dirname(__DIR__) . '/class/trait/ZSInvoiceMapping.trait.php';

class CreditNoteMappingHarness
{
	use ZSInvoiceMapping;

	public $error = '';
	public function isExcludedZsbmsDocumentType($documentType) { return false; }
	public function customerValidation($customer, $name) { return 1; }
	public function findDolibarrProdIdFromCodigo($code, $line = null) { return 0; }
	public function logDebug($operation, $message, array $context = []) { return true; }
	public function logOperationWarning($operation, $message, array $context = []) { return true; }
	public function logOperationError($operation, $message, array $context = []) { return false; }
}

$conf = (object) ['entity' => 2, 'global' => (object) ['ZS_IVA_INCLUIDO' => 1, 'ZS_API_STORE' => 18]];
$fixture = static function () {
	return (object) [
		'doc' => 'NC', 'serie' => 'CREDITTEST', 'numero' => 1, 'data' => '2020-01-01',
		'countrycode' => 'PT', 'contribuinte' => '', 'nome' => 'Synthetic customer',
		'loja' => 18, 'emp' => '', 'total' => '-103.29', 'liquido' => '-91.41',
		'dpercent' => '0', 'descontos' => '0', 'pago' => 0, 'anulado' => 0,
		'vendas' => [(object) [
			'codigo' => 1, 'descricao' => 'Synthetic credit line', 'qtd' => '-11',
			'punit' => '9.39', 'total' => '-103.29', 'liquido' => '-91.41',
			'iva' => '13', 'desconto' => '0', 'desconto2' => '0',
		]],
	];
};
$checks = 0;
$assert = static function ($condition, $message) use (&$checks) {
	if (!$condition) {
		throw new RuntimeException($message);
	}
	$checks++;
};
$map = static function ($source) use ($assert) {
	$sync = new CreditNoteMappingHarness();
	$mapped = $sync->mapZSToDolibarrInvoice($source);
	$assert(is_array($mapped), 'Expected a valid mapped credit note: ' . $sync->error);
	return $mapped;
};
$reject = static function ($source, $message) use ($assert) {
	$before = serialize($source);
	$sync = new CreditNoteMappingHarness();
	$assert($sync->mapZSToDolibarrInvoice($source) === false, $message);
	$assert($sync->error !== '', $message . ': error must explain rejection');
	$assert(serialize($source) === $before, $message . ': source evidence remains unchanged');
};

try {
	$source = $fixture();
	$before = serialize($source);
	$mapped = $map($source);
	$assert([$mapped['total'], $mapped['liquido'], $mapped['total_ht']] === ['-103.29', '-91.41', '-91.41'], 'Mapped headers retain signed source amounts');
	$assert([$mapped['monetary_plan']['total_ht'], $mapped['monetary_plan']['total_tva'], $mapped['monetary_plan']['total_ttc']] === ['91.41', '11.88', '103.29'], 'Signed credit produces one positive monetary plan');
	$assert($mapped['lines'][0]['qty'] === '11', 'Native credit quantity is positive');
	$assert($mapped['lines'][0]['pu_ttc'] === '9.39', 'Native unit price magnitude is preserved');
	$assert($map($source) === $mapped, 'Repeated mapping is idempotent');
	$assert(serialize($source) === $before, 'Mapping does not rewrite the source document or its nested lines');

	$positive = $fixture();
	foreach (['total', 'liquido'] as $field) {
		$positive->{$field} = ZSDecimal::absolute($positive->{$field});
		$positive->vendas[0]->{$field} = ZSDecimal::absolute($positive->vendas[0]->{$field});
	}
	$positive->vendas[0]->qtd = '11';
	$legacy = $map($positive);
	$assert($legacy['monetary_plan'] === $mapped['monetary_plan'], 'Legacy positive NC values retain the same native monetary plan');
	$assert($legacy['total'] === '103.29', 'Legacy positive mapped header remains unchanged');

	$exclusive = $fixture();
	$exclusive->vendas[0]->punit = '8.31';
	$conf->global->ZS_IVA_INCLUIDO = 0;
	$exclusiveMapped = $map($exclusive);
	$assert($exclusiveMapped['monetary_plan']['total_ttc'] === '103.29', 'VAT-exclusive signed credit reconciles exact TTC');
	$conf->global->ZS_IVA_INCLUIDO = 1;

	$multiVat = $fixture();
	$multiVat->total = '-115.59';
	$multiVat->liquido = '-101.41';
	$multiVat->vendas[] = (object) ['codigo' => 2, 'descricao' => 'Second synthetic credit', 'qtd' => '-1', 'punit' => '12.30', 'total' => '-12.30', 'liquido' => '-10.00', 'iva' => '23', 'desconto' => '0', 'desconto2' => '0'];
	$assert($map($multiVat)['monetary_plan']['total_tva'] === '14.18', 'Independent VAT groups preserve exact credit totals');

	$discount = $fixture();
	$discount->total = '-9.00';
	$discount->liquido = '-7.96';
	$discount->vendas[0]->qtd = '-1';
	$discount->vendas[0]->punit = '10';
	$discount->vendas[0]->total = '-9.00';
	$discount->vendas[0]->liquido = '-7.96';
	$discount->vendas[0]->desconto = '-1';
	$assert($map($discount)['monetary_plan']['total_ttc'] === '9.00', 'Signed fixed discount reverses with signed line amounts');
	$discount->total = '-11.00';
	$discount->liquido = '-9.73';
	$discount->vendas[0]->total = '-11.00';
	$discount->vendas[0]->liquido = '-9.73';
	$discount->vendas[0]->desconto = '1';
	$assert($map($discount)['monetary_plan']['total_ttc'] === '11.00', 'Signed credit surcharge retains its algebraic direction');

	$invalid = $fixture();
	$invalid->doc = 'FT';
	$reject($invalid, 'Negative ordinary invoices remain rejected');
	$invalid = $fixture();
	$invalid->liquido = '91.41';
	$reject($invalid, 'Mixed header polarity is rejected');
	$invalid = $fixture();
	$invalid->vendas[0]->qtd = '11';
	$reject($invalid, 'Negative NC totals cannot hide a positive quantity');
	$invalid = $fixture();
	$invalid->vendas[0]->total = '103.29';
	$reject($invalid, 'Mixed line polarity is rejected');
	$invalid = $fixture();
	$invalid->vendas[0]->punit = '-9.39';
	$reject($invalid, 'A second negative price sign is rejected');
	$invalid = $positive;
	$invalid = unserialize(serialize($invalid));
	$invalid->vendas[0]->qtd = '-11';
	$reject($invalid, 'Positive NC headers cannot hide negative financial lines');
	$invalid = $fixture();
	$invalid->vendas[] = (object) ['codigo' => 2, 'descricao' => 'Ambiguous reversal', 'qtd' => '1', 'punit' => '1', 'total' => '1', 'liquido' => '0.88', 'iva' => '13', 'desconto' => '0', 'desconto2' => '0'];
	$reject($invalid, 'Mixed debit and credit lines fail closed');
	foreach (['desconto2' => '-1', 'desconto' => '-200', 'iva' => '-13'] as $field => $value) {
		$invalid = $fixture();
		$invalid->vendas[0]->{$field} = $value;
		$reject($invalid, 'Invalid signed credit line ' . $field . ' remains rejected');
	}
	$invalid = $fixture();
	$invalid->dpercent = '-1';
	$reject($invalid, 'Negative global percentage remains rejected');
	$invalid = $fixture();
	$invalid->vendas[0]->desconto = 'invalid';
	$reject($invalid, 'Malformed fixed discounts are rejected');
	$invalid = $fixture();
	$invalid->total = '-113.29';
	$reject($invalid, 'Header and source-line amount mismatch is rejected');
	$invalid = $fixture();
	$invalid->total = 'invalid';
	$reject($invalid, 'Malformed header totals are rejected');
} catch (Throwable $e) {
	fwrite(STDERR, 'FAIL: ' . $e->getMessage() . "\n");
	exit(1);
}
echo 'PASS: ' . $checks . " credit-note mapping checks\n";

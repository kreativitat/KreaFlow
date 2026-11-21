<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License,
 * or (at your option) any later version.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once dirname(__DIR__).'/class/trait/ZSInvoiceMapping.trait.php';
require_once dirname(__DIR__).'/class/zsdecimal.class.php';
$checks=0;
function verifyUber($condition,$message) { global $checks; if (!$condition) {throw new RuntimeException($message);} $checks++; }
function rejectUber($callback) { try {$callback();} catch (InvalidArgumentException $e) {verifyUber(true,'rejected'); return;} throw new RuntimeException('Expected rejection'); }
$source=(object) array('loja'=>2,'movimentospropriedades'=>array((object)array('id'=>700,'idpropriedade'=>74,'valor_propriedade'=>'0012Ab')));
$expected=array('store'=>2,'properties'=>array(74=>array('0012Ab')));
verifyUber(ZSInvoiceProperties::extract($source,2)===$expected,'Arbitrary property ID and leading zeroes retained');
verifyUber(ZSInvoiceProperties::extract((object)array(),2)===array(),'Missing movements optional');
rejectUber(fn()=>ZSInvoiceProperties::extract($source,77));
rejectUber(fn()=>ZSInvoiceProperties::extract($source,0));
$multi=clone $source; $multi->movimentospropriedades[]=$source->movimentospropriedades[0];
verifyUber(ZSInvoiceProperties::extract($multi,2)===$expected,'Repeated line value deduplicates');
$multi->movimentospropriedades[]=(object)array('idpropriedade'=>74,'valor'=>'SECOND');
$multi->movimentospropriedades[]=(object)array('idpropriedade'=>88,'valor'=>1.25);
$multiEvidence=ZSInvoiceProperties::extract($multi,2);
verifyUber($multiEvidence['properties'][74]===array('0012Ab','SECOND'),'All distinct references retained');
verifyUber($multiEvidence['properties'][88]===array(1.25),'Other numeric properties retain their value type');
verifyUber(ZSInvoiceProperties::options(array())===array(),'Absent source cannot clear existing evidence');
foreach (array(array('bad'), "bad\nvalue", str_repeat('x',1001)) as $value) {
    $bad=clone $source; $bad->movimentospropriedades=array((object)array('idpropriedade'=>74,'valor'=>$value));
    rejectUber(fn()=>ZSInvoiceProperties::extract($bad,2));
}
class UberMappingHarness {
    use ZSInvoiceMapping;
    public $error='';
    public function isExcludedZsbmsDocumentType($type){return false;}
    public function customerValidation($customer,$name){return 44;}
    public function logDebug(...$args){}
    public function logOperationWarning(...$args){}
    public function logOperationError(...$args){}
}
$conf=(object)array('global'=>(object)array('ZS_API_STORE'=>2));
$source->doc='FT'; $source->serie='TEST'; $source->numero=123; $source->data='2026-09-23'; $source->total='10.00'; $source->liquido='10.00';
$mapped=(new UberMappingHarness())->mapZSToDolibarrInvoice($source);
verifyUber(is_array($mapped),'Actual mapping trait succeeds');
verifyUber(json_decode($mapped['array_options']['options_zs_sale_properties'],true)===$expected,'Actual mapping trait carries generic property evidence');
verifyUber($mapped['ref_client']==='FT_TEST_123_20260923','Fiscal reference preserved');
verifyUber($mapped['total']==='10','Monetary mapping unchanged');
$source->movimentospropriedades=array();
$mapped=(new UberMappingHarness())->mapZSToDolibarrInvoice($source);
verifyUber(!isset($mapped['array_options']['options_zs_sale_properties']),'Ordinary sale unchanged');
echo "$checks generic sale-property checks passed.\n";

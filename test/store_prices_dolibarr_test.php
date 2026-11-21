<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License,
 * or (at your option) any later version.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
if (!is_file('/var/www/html/main.inc.php')) { echo "SKIP: requires lamp-php81\n"; exit; }
define('NOLOGIN', 1);
require '/var/www/html/main.inc.php';
require_once dirname(__DIR__) . '/class/zsprodsynch.class.php';

class StorePricesRecordingApi
{
    public $calls = array();
    public function setInterface($interface) {}
    public function executeUpdateProducts($action, $payload, $store)
    {
        if ($action === 'getInstance') {
            return array('Response' => array('StatusCode' => 200, 'Content' => array('product' => array(
                'datacriacao' => '2026-01-01', 'precovenda' => -1, 'pvp1siva' => -1,
                'produto_opcoes' => array(array('codigo'=>999,'loja'=>$store,'opcao'=>'EMENTA_DIGITAL','valor'=>1), array('codigo'=>999,'loja'=>$store,'opcao'=>'REMOTE_ONLY','valor'=>3)),
            ))));
        }
        $this->calls[$store] = $payload['product'][0];
        return array('Response' => array('StatusCode' => 201));
    }
}
function expectStorePrice($ok, $message)
{
    if (!$ok) { throw new RuntimeException($message); }
}
function rejectStorePrice($callback, $message)
{
    try { $callback(); } catch (RuntimeException $e) { return; }
    throw new RuntimeException($message);
}
$open = false;
try {
    $migration = file_get_contents(dirname(__DIR__) . '/sql/upgrade/1.38.0.sql');
    $migration = preg_replace('/^--.*$/m', '', $migration);
    for ($repeat = 0; $repeat < 2; $repeat++) {
        foreach (explode(';', $migration) as $sql) {
            if (trim($sql) !== '') {
                expectStorePrice($db->query(str_replace('llx_', MAIN_DB_PREFIX, $sql)), 'Migration failed: ' . $db->lasterror());
            }
        }
    }
    $db->begin(); $open = true;
    $result = $db->query('SELECT p.rowid FROM ' . MAIN_DB_PREFIX . 'product p INNER JOIN ' . MAIN_DB_PREFIX
        . 'dolizsynch_zsproduct z ON z.fk_product=p.rowid WHERE p.entity IN (' . getEntity('product') . ') AND z.codigo>0 LIMIT 1');
    $row = $db->fetch_object($result);
    expectStorePrice($row, 'Missing mapped product fixture');
    $product = new Product($db); $product->fetch($row->rowid);
    $product->tva_tx = 23; $product->multiprices_tva_tx = array(1 => 23, 2 => 6);
    $product->array_options['options_zs_synch'] = 1;
    $conf->global->PRODUIT_MULTIPRICES = 1; $conf->global->PRODUIT_MULTIPRICES_LIMIT = 2;
    $actor = new User($db); $actor->id = 1; $actor->admin = 1; $actor->rights->produit = (object) array('creer'=>1); $actor->rights->service = (object) array('creer'=>1);
    $denied = new User($db); $denied->id = 2; $denied->admin = 0;
    $db->query('DELETE FROM ' . MAIN_DB_PREFIX . 'dolizsynch_store_prices WHERE fk_product=' . (int) $product->id . ' AND entity=' . (int) $product->entity);
    expectStorePrice($db->query('UPDATE ' . MAIN_DB_PREFIX . 'dolizsynch_zsproduct SET product_company=\'1,2,3\', retalho_by_store=\'{"1":1,"2":1,"3":0}\', ementadigital=1, consumominimo=1, prepagamento=1, topo=1, revenda=1, excluirdescontos=1 WHERE fk_product=' . (int) $product->id), 'Fixture update failed');
    $service = new ZSStorePrices($db);
    expectStorePrice($service->activeStores($product->id) === array(1,2), 'Inactive stores leaked');
    $original = array('precovenda' => '5.00', 'pvp1siva' => '4.07', 'pvp2'=>'8.00', 'pvp2siva'=>'7.55');
    expectStorePrice($service->apply($product,1,$original) === $original, 'Off default changed prices');
    rejectStorePrice(function () use ($service,$product,$denied) { $service->save($product,$denied,0,true); }, 'Write permission bypass');
    $service->save($product,$actor,0,true);
    $post = array(1=>array(1=>array('base'=>'HT','HT'=>'10')),2=>array(1=>array('base'=>'TTC','TTC'=>'24,60'),2=>array('base'=>'HT','HT'=>'-1')));
    $service->save($product,$actor,1,true,$post);
    $one = $service->apply($product,1,$original); $two = $service->apply($product,2,$original);
    expectStorePrice($one['precovenda']==='12.30' && $one['pvp1siva']==='10.00', 'HT conversion failed');
    expectStorePrice($two['precovenda']==='24.60' && $two['pvp1siva']==='20.00' && $two['pvp2']==='-1.00' && $two['pvp2siva']==='-1.00', 'TTC or variable price failed');
    expectStorePrice($one['pvp2']==='8.00' && $service->apply($product,3,$original)===$original, 'Fallback or disabled store changed');
    rejectStorePrice(function () use ($service,$product,$actor) { $service->save($product,$actor,1,false); }, 'Stale update accepted');
    foreach (array('abc','-2','1e9','1.2.3','INF') as $invalid) {
        rejectStorePrice(function () use ($service,$product,$actor,$invalid) { $service->save($product,$actor,2,true,array(1=>array(1=>array('base'=>'TTC','TTC'=>$invalid)))); }, 'Invalid amount accepted');
    }
    rejectStorePrice(function () use ($service,$product,$actor) { $service->save($product,$actor,2,true,array(3=>array(1=>array('base'=>'TTC','TTC'=>'1')))); }, 'Inactive store accepted');
    rejectStorePrice(function () use ($service) { $service->fetch(2147483647); }, 'Missing product accepted');
    $foreignResult = $db->query('SELECT rowid FROM ' . MAIN_DB_PREFIX . 'product WHERE entity NOT IN (' . getEntity('product') . ') LIMIT 1');
    $foreign = $db->fetch_object($foreignResult);
    if ($foreign) {
        rejectStorePrice(function () use ($service,$foreign) { $service->fetch($foreign->rowid); }, 'Foreign product accepted');
    }
    $nestedOptions = array(array('codigo'=>999,'loja'=>9,'opcao'=>'EMENTA_DIGITAL','valor'=>0), array('codigo'=>999,'loja'=>9,'opcao'=>'KEEP_OPTION','valor'=>7));
    $db->query("UPDATE " . MAIN_DB_PREFIX . "dolizsynch_zsproduct SET produto_opcoes='" . $db->escape(json_encode($nestedOptions)) . "' WHERE fk_product=" . (int) $product->id);
    expectStorePrice(ZSProductSynch::getDigitalMenuValue(json_encode($nestedOptions), 1) === 0, 'API nested option must override legacy root');
    expectStorePrice(ZSProductSynch::getDigitalMenuValue(array()) === null, 'Missing option must remain unknown');
    $api = new StorePricesRecordingApi(); $sync = new ZSProductSynch($db,$api);
    expectStorePrice($sync->updateZsbmsProductPricesForStore($product,1), 'Scheduled price payload failed: '.$sync->error);
    expectStorePrice($api->calls[1]['precovenda']==='12.30', 'Scheduled sync ignored override');
    expectStorePrice($sync->updateZsbmsProduct($product), 'Full synchronization failed: '.$sync->error);
    expectStorePrice($api->calls[1]['precovenda']==='12.30' && $api->calls[2]['precovenda']==='24.60', 'Full sync ignored store override or retained remote variable price');
    foreach (array('consumominimo','prepagamento','topo','revenda','excluirdescontos') as $flag) {
        expectStorePrice((int) $api->calls[1][$flag]===1, 'Missing enabled flag '.$flag);
    }
    foreach (array(1, 2) as $store) {
        $options = $api->calls[$store]['produto_opcoes'];
        expectStorePrice(!array_key_exists('ementadigital', $api->calls[$store]), 'Unsupported root field sent');
        expectStorePrice(count(array_filter($options, function ($option) { return $option['opcao'] === 'REMOTE_ONLY' && (int) $option['valor'] === 3; })) === 1, 'Remote-only option lost');
        expectStorePrice(ZSProductSynch::getDigitalMenuValue($options) === 1, 'Enabled checkbox did not replace stale nested zero');
        $menuRows = array_values(array_filter($options, function ($option) { return $option['opcao'] === 'EMENTA_DIGITAL'; }));
        expectStorePrice(count($menuRows) === 1 && (int) $menuRows[0]['loja'] === $store && (string) $menuRows[0]['codigo'] === (string) $api->calls[$store]['codigo'], 'Incorrect option membership');
        expectStorePrice(count(array_filter($options, function ($option) { return $option['opcao'] === 'KEEP_OPTION' && (int) $option['valor'] === 7; })) === 1, 'Unrelated option changed');
    }
    $db->query('UPDATE ' . MAIN_DB_PREFIX . 'dolizsynch_zsproduct SET ementadigital=0, consumominimo=0, prepagamento=0, topo=0, revenda=0, excluirdescontos=0 WHERE fk_product=' . (int) $product->id);
    expectStorePrice($sync->updateZsbmsProduct($product), 'Unchecked flag sync failed');
    foreach (array('consumominimo','prepagamento','topo','revenda','excluirdescontos') as $flag) {
        expectStorePrice((int) $api->calls[1][$flag]===0, 'Unchecked flag lost '.$flag);
    }
    expectStorePrice(ZSProductSynch::getDigitalMenuValue($api->calls[1]['produto_opcoes']) === 0, 'Unchecked menu lost');
    $db->query('UPDATE ' . MAIN_DB_PREFIX . 'dolizsynch_zsproduct SET ementadigital=NULL WHERE fk_product=' . (int) $product->id);
    expectStorePrice($sync->updateZsbmsProduct($product), 'Untouched menu sync failed');
    expectStorePrice(ZSProductSynch::getDigitalMenuValue($api->calls[1]['produto_opcoes']) === 1, 'Untouched menu did not preserve remote value');
    foreach (array(0, 1) as $value) {
        $payload = $sync->preparePayload(array('codigo'=>2231,'ementadigital'=>$value,'produto_opcoes'=>$nestedOptions), 2);
        expectStorePrice(ZSProductSynch::getDigitalMenuValue($payload['product'][0]['produto_opcoes']) === $value, 'Creation menu option mismatch');
        expectStorePrice(!isset($payload['product'][0]['ementadigital']), 'Creation uses root field');
    }
    $service->save($product,$actor,2,false);
    expectStorePrice($service->apply($product,1,$original)===$original, 'Disabled mode did not restore defaults');
    $service->save($product,$actor,3,true);
    expectStorePrice($service->apply($product,1,$original)['precovenda']==='12.30', 'Toggle lost saved prices');
    $service->save($product,$actor,4,true,array(1=>array(1=>array('base'=>'HT','HT'=>''))));
    expectStorePrice($service->apply($product,1,$original)===$original, 'Blank did not restore inherited price');
    $service->save($product,$actor,5,true,array(1=>array(1=>array('base'=>'HT','HT'=>'0'))));
    expectStorePrice($service->apply($product,1,$original)['precovenda']==='0.00', 'Zero price lost');
    // Render the actual controls in both modes, without calling external APIs.
    require_once dirname(__DIR__) . '/class/actions_dolizsynch.class.php';
    $form = new Form($db);
    $user = $actor;
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['PHP_SELF'] = '/custom/kreaflow/zsproduct.php';
    $parameters = array('from_dolizsynch_tab'=>1, 'from_dolizsynch_page'=>1, 'context'=>'productcard:dolizsynchproducttab');
    $langs->load('dolizsynch@kreaflow');
    foreach (array('' => false, 'edit' => true) as $renderAction => $editable) {
        $renderer = new ActionsDoliZSynch($db);
        $renderer->formObjectOptions($parameters, $product, $renderAction, $hookmanager);
        $doc = new DOMDocument();
        @$doc->loadHTML('<html><body>'.$renderer->resprints.'</body></html>');
        $xpath = new DOMXPath($doc);
        foreach (array('PREPAGAMENTO','TOPO','REVENDA','EXCLUIRDESCONTOS','CONSUMOMINIMO','EMENTADIGITAL') as $field) {
            $nodes = $xpath->query('//input[@name="DOLIZSYNCH_'.$field.'"]');
            expectStorePrice($nodes->length === 1, 'Missing or duplicate checkbox '.$field);
            expectStorePrice($nodes->item(0)->hasAttribute('disabled') === !$editable, 'Incorrect checkbox permission mode '.$field);
        }
    }
    $object = $product; $storePriceState = $service->fetch($product->id);
    $storePriceStores = $service->activeStores($product->id); $canEditStorePrices = true;
    ob_start(); include dirname(__DIR__).'/tpl/store_prices.tpl.php'; $html = ob_get_clean();
    $doc = new DOMDocument(); @$doc->loadHTML('<html><body><table>'.$html.'</table></body></html>');
    $xpath = new DOMXPath($doc);
    expectStorePrice($xpath->query('//button[@aria-pressed="true"]')->length === 1, 'Enabled switch not rendered');
    expectStorePrice($xpath->query('//form[@method="post"]')->length === 1, 'Switch must use POST');
    expectStorePrice($xpath->query('//input[@data-base]')->length === 0, 'Switch must not contain editor');
    $storePriceLabels = $service->storeLabels($product->id);
    expectStorePrice(array_keys($storePriceLabels) === array(1, 2), 'Labels escaped active membership');
    if (isModEnabled('multicompany')) {
        $labelRows = $db->query("SELECT c.value AS store, e.label FROM " . MAIN_DB_PREFIX . "const c INNER JOIN " . MAIN_DB_PREFIX . "entity e ON e.rowid=c.entity WHERE c.name='ZS_API_STORE' AND c.value IN ('1','2') ORDER BY e.rowid");
        while ($labelRow = $db->fetch_object($labelRows)) {
            expectStorePrice($storePriceLabels[(int) $labelRow->store] === $labelRow->label, 'Store entity label lost');
        }
    }
    $storePriceLabels = array(1 => 'Store A & B', 2 => 'Store <Two>');
    foreach (array('', 'edit_price') as $action) {
        ob_start(); include dirname(__DIR__).'/tpl/store_price_table.tpl.php'; $html = ob_get_clean();
        $doc = new DOMDocument(); @$doc->loadHTML('<html><body>'.$html.'</body></html>');
        $xpath = new DOMXPath($doc);
        expectStorePrice($xpath->query('//thead/tr/th')->length === 7, 'Missing standard price columns');
        expectStorePrice($xpath->query('//tr[contains(@class,"dzs-store-price-row")]')->length === 4, 'Missing store/level rows');
        expectStorePrice($xpath->query('//tbody/tr[count(td)=7]')->length === 4, 'Every price row needs all seven cells for theme borders');
        expectStorePrice(strpos($html, 'Store &lt;Two&gt;') !== false, 'Store label escaping failed');
        expectStorePrice($xpath->query('//input[@data-base]')->length === ($action === 'edit_price' ? 8 : 0), 'Wrong editor visibility');
        expectStorePrice($xpath->query('//form[@method="post"]')->length === ($action === 'edit_price' ? 1 : 0), 'Wrong edit form');
        expectStorePrice($xpath->query('//select')->length === 0, 'Redundant price-base selector');
    }
    $storePriceState['enabled'] = false;
    ob_start(); include dirname(__DIR__).'/tpl/store_price_table.tpl.php'; $html = ob_get_clean();
    expectStorePrice($html === '', 'Disabled mode renders store table');
    $fixtures = json_decode(file_get_contents(dirname(__DIR__).'/docs/product.json'));
    $incoming = end($fixtures);
    $incoming->product->codigo = $api->calls[1]['codigo'];
    $incoming->product->fk_product_override = $product->id;
    foreach (array(1, 0) as $value) {
        $incoming->product->ementadigital = 1 - $value;
        $incoming->product->produto_opcoes = array((object) array('codigo'=>$incoming->product->codigo,'loja'=>1,'opcao'=>'EMENTA_DIGITAL','valor'=>$value));
        expectStorePrice($sync->insertProductToLocalTable($incoming), 'Inbound option import failed');
        $stored = $db->fetch_object($db->query('SELECT ementadigital FROM '.MAIN_DB_PREFIX.'dolizsynch_zsproduct WHERE fk_product='.(int) $product->id.' ORDER BY rowid DESC LIMIT 1'));
        expectStorePrice((int) $stored->ementadigital === $value, 'Inbound nested value did not persist');
    }
    $db->rollback(); $open=false;
    echo "PASS: repeatable migration, store isolation, HT/TTC, variable/zero/blank prices, permissions, stale updates, both outbound paths, nested Digital Menu on/off/import/preservation and five root flags, real edit/view checkbox and price-form rendering; all fixture writes rolled back.\n";
} catch (Throwable $e) {
    if ($open) { $db->rollback(); }
    fwrite(STDERR, 'FAIL: '.$e->getMessage()."\n"); exit(1);
}

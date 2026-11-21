<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

$bootstrap = '/var/www/html/main.inc.php';
if (!is_file($bootstrap)) {
    echo "SKIP: installed Dolibarr integration is available only in lamp-php81\n";
    exit(0);
}

if (!defined('NOLOGIN')) {
    define('NOLOGIN', 1);
}
require_once $bootstrap;
require_once DOL_DOCUMENT_ROOT . '/product/class/product.class.php';
require_once dirname(__DIR__) . '/class/zsprodsynch.class.php';

global $db;

class ZSProductUpdateContractFakeApi
{
    public $calls = array();
    private $saveAttempts = 0;

    public function setInterface($interface)
    {
    }

    public function executeUpdateProducts($action, $payload, $store)
    {
        $this->calls[] = array(
            'action' => (string) $action,
            'payload' => $payload,
            'store' => (int) $store,
        );

        if ($action === 'getInstance') {
            return array(
                'Response' => array(
                    'StatusCode' => 200,
                    'Content' => array(
                        'product' => array(
                            'codigo' => (int) ($payload['product']['codigo'] ?? 0),
                            'descricao' => 'Existing remote description',
                            'datacriacao' => '2026-03-03',
                            'precovenda' => -1,
                            'pvp1siva' => -1,
                            'pvp2' => -1,
                            'pvp2siva' => -1,
                            'lastupdate' => '2026-08-25 21:00:00.000',
                            '_errors' => array(),
                        ),
                    ),
                ),
            );
        }

        $this->saveAttempts++;
        if ($this->saveAttempts === 1) {
            return array(
                'Response' => array(
                    'StatusCode' => 422,
                    'StatusMessage' => 'Unprocessable Entity',
                    'Content' => array(
                        'product' => array(
                            array(
                                '_errors' => array(
                                    array(
                                        'property' => 'Products.descricao',
                                        'error' => 'The description cannot be modified after sales exist',
                                    ),
                                ),
                            ),
                        ),
                    ),
                ),
            );
        }

        return array('Response' => array('StatusCode' => 201, 'StatusMessage' => 'Created'));
    }
}

$failure = '';
$evidence = array();
$transactionStarted = false;

try {
    $triggerSource = file_get_contents(dirname(__DIR__) . '/core/triggers/interface_99_modDoliZSynch_DoliZSynchTriggers.class.php');
    if ($triggerSource === false
        || strpos($triggerSource, "case 'PRODUCT_PRICE_MODIFY':") === false
        || strpos($triggerSource, "'/product/price.php'") === false
        || strpos($triggerSource, "array('update_price', 'update_level_price')") === false
        || strpos($triggerSource, "fetch_optionals()") === false
        || strpos($triggerSource, 'array($priceLevel)') === false) {
        throw new RuntimeException('The native Dolibarr price-change trigger is not wired to a level-specific ZSBMS override');
    }

    $sql = 'SELECT zs.fk_product, zs.rowid, zs.codigo FROM ' . MAIN_DB_PREFIX . 'dolizsynch_zsproduct zs';
    $sql .= ' INNER JOIN ' . MAIN_DB_PREFIX . 'product p ON p.rowid=zs.fk_product';
    $sql .= ' WHERE zs.codigo>0 AND p.entity IN (' . getEntity('product') . ') ORDER BY zs.rowid ASC ' . $db->plimit(1);
    $resql = $db->query($sql);
    $mapping = $resql ? $db->fetch_object($resql) : null;
    if (!$mapping) {
        throw new RuntimeException('The local fixture has no mapped product');
    }

    $product = new Product($db);
    if ($product->fetch((int) $mapping->fk_product) <= 0) {
        throw new RuntimeException($product->error ?: 'Unable to load the mapped product');
    }
    $product->fetch_optionals((int) $product->id);
    $product->array_options['options_zs_synch'] = 1;
    $product->label = 'Changed Dolibarr description';

    if (!$db->begin()) {
        throw new RuntimeException('Unable to start rollback transaction');
    }
    $transactionStarted = true;

    $product->price = 7.70;
    $product->price_ttc = 8.16;
    $product->multiprices[1] = 7.70;
    $product->multiprices_ttc[1] = 8.16;
    if (!empty($conf->global->PRODUIT_MULTIPRICES)) {
        $sql = 'SELECT rowid FROM ' . MAIN_DB_PREFIX . 'product_price';
        $sql .= ' WHERE fk_product=' . (int) $product->id;
        $sql .= ' AND entity IN (' . getEntity('productprice') . ') AND price_level=1';
        $sql .= ' ORDER BY date_price DESC, rowid DESC ' . $db->plimit(1);
        $resql = $db->query($sql);
        $priceRow = $resql ? $db->fetch_object($resql) : null;
        if ($priceRow) {
            $sql = 'UPDATE ' . MAIN_DB_PREFIX . 'product_price';
            $sql .= ' SET price=7.70, price_ttc=8.16 WHERE rowid=' . (int) $priceRow->rowid;
            if (!$db->query($sql)) {
                throw new RuntimeException($db->lasterror());
            }
        }
    }

    $sql = 'UPDATE ' . MAIN_DB_PREFIX . 'dolizsynch_zsproduct SET';
    $sql .= " product_company='1', retalho_by_store='{\"1\":1}',";
    $sql .= ' precovenda=8.16, pvp1siva=7.70';
    $sql .= ' WHERE rowid=' . (int) $mapping->rowid;
    if (!$db->query($sql)) {
        throw new RuntimeException($db->lasterror());
    }

    $fakeApi = new ZSProductUpdateContractFakeApi();
    $sync = new ZSProductSynch($db, $fakeApi);
    if (!$sync->updateZsbmsProduct($product)) {
        throw new RuntimeException($sync->error ?: 'The constrained product update failed');
    }

    $saveCalls = array_values(array_filter($fakeApi->calls, static function ($call) {
        return $call['action'] === 'saveInstances';
    }));
    if (count($saveCalls) !== 2) {
        throw new RuntimeException('The immutable description response did not produce exactly one retry');
    }

    $firstPayload = $saveCalls[0]['payload']['product'][0] ?? array();
    $retryPayload = $saveCalls[1]['payload']['product'][0] ?? array();
    if (($firstPayload['descricao'] ?? '') !== 'Changed Dolibarr description') {
        throw new RuntimeException('The first request did not attempt the current Dolibarr description');
    }
    if (($retryPayload['descricao'] ?? '') !== 'Existing remote description') {
        throw new RuntimeException('The retry did not retain the mandatory remote description');
    }
    if (($retryPayload['datacriacao'] ?? '') !== '2026-03-03') {
        throw new RuntimeException('The existing remote creation date was not preserved');
    }
    if ((float) ($retryPayload['precovenda'] ?? 0) !== -1.0 || (float) ($retryPayload['pvp1siva'] ?? 0) !== -1.0) {
        throw new RuntimeException('The remote variable-price sentinel was not preserved');
    }
    if (array_key_exists('_errors', $retryPayload) || array_key_exists('lastupdate', $retryPayload)) {
        throw new RuntimeException('Response-only metadata leaked into saveInstances');
    }
    if ($sync->error !== '') {
        throw new RuntimeException('A successful retry retained a stale public error');
    }

    $overrideApi = new ZSProductUpdateContractFakeApi();
    $overrideSync = new ZSProductSynch($db, $overrideApi);
    if (!$overrideSync->updateZsbmsProduct($product, false, array(1))) {
        throw new RuntimeException($overrideSync->error ?: 'The explicit Dolibarr price override failed');
    }

    $overrideSaveCalls = array_values(array_filter($overrideApi->calls, static function ($call) {
        return $call['action'] === 'saveInstances';
    }));
    if (count($overrideSaveCalls) !== 2) {
        throw new RuntimeException('The explicit price override did not preserve the immutable-description retry contract');
    }
    $overridePayload = $overrideSaveCalls[1]['payload']['product'][0] ?? array();
    if ((float) ($overridePayload['precovenda'] ?? -1) === -1.0 || (float) ($overridePayload['pvp1siva'] ?? -1) === -1.0) {
        throw new RuntimeException('The explicitly edited Dolibarr price level remained variable in the ZSBMS payload');
    }
    if ((float) ($overridePayload['pvp2'] ?? 0) !== -1.0 || (float) ($overridePayload['pvp2siva'] ?? 0) !== -1.0) {
        throw new RuntimeException('A variable-price level unrelated to the Dolibarr edit was overwritten');
    }

    $sql = 'SELECT precovenda, pvp1siva FROM ' . MAIN_DB_PREFIX . 'dolizsynch_zsproduct';
    $sql .= ' WHERE rowid=' . (int) $mapping->rowid;
    $resql = $db->query($sql);
    $shadowPrice = $resql ? $db->fetch_object($resql) : null;
    if (!$shadowPrice
        || (float) $overridePayload['precovenda'] !== (float) $shadowPrice->precovenda
        || (float) $overridePayload['pvp1siva'] !== (float) $shadowPrice->pvp1siva) {
        throw new RuntimeException('The ZSBMS override payload does not match the latest Dolibarr shadow prices');
    }

    $evidence = array(
        'save_attempts' => count($saveCalls),
        'remote_description' => $retryPayload['descricao'],
        'creation_date' => $retryPayload['datacriacao'],
        'variable_price' => array($retryPayload['precovenda'], $retryPayload['pvp1siva']),
        'explicit_price_override' => array($overridePayload['precovenda'], $overridePayload['pvp1siva']),
        'unrelated_variable_price' => array($overridePayload['pvp2'], $overridePayload['pvp2siva']),
        'price_trigger' => 'PRODUCT_PRICE_MODIFY level 1',
    );

    $db->rollback();
    $transactionStarted = false;
} catch (Throwable $e) {
    $failure = $e->getMessage();
    if ($transactionStarted) {
        $db->rollback();
    }
}

if ($failure !== '') {
    echo '[FAIL] ' . $failure . "\n";
    exit(1);
}

echo '[PASS] ' . json_encode($evidence, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";

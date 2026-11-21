<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License,
 * or (at your option) any later version.
 */

/** Product-owned store price configuration, shared only through native product visibility. */
class ZSStorePrices
{
    private $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    private function query($sql)
    {
        $result = $this->db->query($sql);
        if (!$result) {
            dol_syslog(__METHOD__ . ': ' . $this->db->lasterror(), LOG_ERR);
            throw new RuntimeException('Unable to read or save store prices. Check the module migration and database log.');
        }
        return $result;
    }

    public static function levelLimit()
    {
        global $conf;
        return empty($conf->global->PRODUIT_MULTIPRICES) ? 1 : min(10, max(1, getDolGlobalInt('PRODUIT_MULTIPRICES_LIMIT', 5)));
    }

    public function fetch($productId, $lock = false)
    {
        $result = $this->query('SELECT rowid, entity FROM ' . MAIN_DB_PREFIX . 'product WHERE rowid=' . (int) $productId
            . ' AND entity IN (' . getEntity('product') . ')' . ($lock ? ' FOR UPDATE' : ''));
        $product = $this->db->fetch_object($result);
        if (!$product) {
            throw new RuntimeException('Product is outside the permitted entity scope.');
        }
        $result = $this->query('SELECT enabled, prices, revision FROM ' . MAIN_DB_PREFIX . 'dolizsynch_store_prices'
            . ' WHERE fk_product=' . (int) $productId . ' AND entity=' . (int) $product->entity);
        $row = $this->db->fetch_object($result);
        $prices = $row ? json_decode($row->prices, true) : array();
        if (!is_array($prices)) {
            throw new RuntimeException('Invalid stored store-price configuration.');
        }
        return array('entity' => (int) $product->entity, 'enabled' => $row ? (bool) $row->enabled : false,
            'revision' => $row ? (int) $row->revision : 0, 'prices' => $prices);
    }

    /** Store availability follows the product card; an explicit disabled value always wins. */
    public function activeStores($productId)
    {
        $result = $this->query('SELECT z.retalho_by_store, z.product_company, z.loja_zs FROM ' . MAIN_DB_PREFIX . 'dolizsynch_zsproduct z'
            . ' INNER JOIN ' . MAIN_DB_PREFIX . 'product p ON p.rowid=z.fk_product'
            . ' WHERE p.rowid=' . (int) $productId . ' AND p.entity IN (' . getEntity('product') . ') ORDER BY z.rowid DESC ' . $this->db->plimit(1));
        $row = $this->db->fetch_object($result);
        if (!$row) {
            return array();
        }
        $raw = trim((string) $row->retalho_by_store);
        $availability = json_decode($raw, true);
        if (!is_array($availability)) {
            if ($raw !== '' && $raw !== '1') {
                throw new RuntimeException('Invalid product store availability.');
            }
            $availability = array();
        }
        $stores = array();
        foreach (explode(',', (string) $row->product_company) as $store) {
            $store = trim($store);
            if (ctype_digit($store) && (int) $store > 0) {
                $stores[(int) $store] = 1;
            }
        }
        if (!$stores && !$availability && (int) $row->loja_zs > 0) {
            $stores[(int) $row->loja_zs] = 1;
        }
        foreach ($availability as $store => $enabled) {
            if (ctype_digit((string) $store) && (int) $store > 0) {
                $stores[(int) $store] = ((string) $enabled === '1') ? 1 : 0;
            }
        }
        $stores = array_keys(array_filter($stores));
        sort($stores, SORT_NUMERIC);
        return $stores;
    }

    /** Resolve labels only for stores already available on this visible product. */
    public function storeLabels($productId)
    {
        global $conf, $langs, $mysoc;
        $stores = $this->activeStores($productId);
        $labels = array();
        foreach ($stores as $store) {
            $labels[$store] = $langs->trans('DZSStore') . ' ' . $store;
        }
        if (!$stores) {
            return $labels;
        }
        if (isModEnabled('multicompany')) {
            // Store/entity labels are the same shared configuration used by the product card.
            // Restrict the lookup to this product's authorized store membership.
            $sql = 'SELECT c.value AS store, e.label FROM ' . MAIN_DB_PREFIX . 'const c';
            $sql .= ' INNER JOIN ' . MAIN_DB_PREFIX . 'entity e ON e.rowid=c.entity';
            $sql .= " WHERE c.name='ZS_API_STORE' AND c.value IN ('" . implode("','", array_map('intval', $stores)) . "')";
            $sql .= ' ORDER BY e.rowid';
            $result = $this->query($sql);
            while ($row = $this->db->fetch_object($result)) {
                if (trim((string) $row->label) !== '') {
                    $labels[(int) $row->store] = (string) $row->label;
                }
            }
        } elseif (isset($labels[(int) ($conf->global->ZS_API_STORE ?? 0)]) && !empty($mysoc->name)) {
            $labels[(int) $conf->global->ZS_API_STORE] = $mysoc->name;
        }
        return $labels;
    }

    /** Writes are serialized against the visible parent; stale browser submissions are rejected. */
    public function save($product, $user, $revision, $enabled, $postedPrices = null)
    {
        $permission = (int) $product->type === 1 ? 'service' : 'produit';
        if (empty($user->id) || !empty($user->socid) || !$user->hasRight($permission, 'creer')) {
            throw new RuntimeException('Insufficient product price permissions.');
        }
        $this->db->begin();
        try {
            $state = $this->fetch($product->id, true);
            if ($state['revision'] !== (int) $revision) {
                throw new RuntimeException('Store prices changed in another session. Reload before saving.');
            }
            if ($postedPrices !== null) {
                if (!$state['enabled'] || !is_array($postedPrices)) {
                    throw new RuntimeException('Enable store prices before editing them.');
                }
                $stores = $this->activeStores($product->id);
                foreach ($postedPrices as $store => $levels) {
                    if (!ctype_digit((string) $store) || !in_array((int) $store, $stores, true) || !is_array($levels)) {
                        throw new RuntimeException('Invalid or inactive ZSBMS store.');
                    }
                    foreach ($levels as $level => $input) {
                        if (!ctype_digit((string) $level) || (int) $level < 1 || (int) $level > self::levelLimit() || !is_array($input)) {
                            throw new RuntimeException('Invalid store price level.');
                        }
                        $base = $input['base'] ?? '';
                        if (!in_array($base, array('HT', 'TTC'), true) || !isset($input[$base]) || !is_scalar($input[$base])) {
                            throw new RuntimeException('Invalid store price basis.');
                        }
                        $value = trim((string) $input[$base]);
                        if ($value === '') {
                            unset($state['prices'][$store][$level]);
                            continue;
                        }
                        // Validate before price2num, which can silently strip invalid input.
                        if (!preg_match('/^(?:[0-9]+(?:[.,][0-9]{1,8})?|-1)$/D', $value)) {
                            throw new RuntimeException('Enter a non-negative price, -1 for variable price, or leave the field blank.');
                        }
                        $value = str_replace(',', '.', $value);
                        if ((float) $value > 999999999.99) {
                            throw new RuntimeException('Store price exceeds the supported range.');
                        }
                        $entry = array('base' => $base, 'value' => $value);
                        self::pair($product, (int) $level, $entry);
                        $state['prices'][$store][$level] = $entry;
                    }
                }
            }
            $json = json_encode($state['prices']);
            $sql = 'INSERT INTO ' . MAIN_DB_PREFIX . 'dolizsynch_store_prices (entity,fk_product,enabled,prices,revision,fk_user_modif) VALUES ('
                . $state['entity'] . ',' . (int) $product->id . ',' . (int) (bool) $enabled . ", '" . $this->db->escape($json) . "',1," . (int) $user->id . ')'
                . ' ON DUPLICATE KEY UPDATE enabled=VALUES(enabled),prices=VALUES(prices),revision=revision+1,fk_user_modif=VALUES(fk_user_modif)';
            $this->query($sql);
            $this->db->commit();
            dol_syslog(__METHOD__ . ': product=' . (int) $product->id . ' actor=' . (int) $user->id, LOG_INFO);
        } catch (Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    /** Convert the authoritative HT or TTC amount using the current product VAT. */
    public static function pair($product, $level, array $price)
    {
        $value = $price['value'] ?? null;
        $base = $price['base'] ?? '';
        if (!in_array($base, array('HT', 'TTC'), true) || !is_numeric($value)
            || !is_finite((float) $value) || (float) $value > 999999999.99
            || ((float) $value < 0 && (float) $value !== -1.0)) {
            throw new RuntimeException('Invalid stored store price.');
        }
        $vat = (float) ($product->multiprices_tva_tx[$level] ?? $product->tva_tx);
        if ($vat < 0 || $vat > 100) {
            throw new RuntimeException('Invalid product VAT rate.');
        }
        if ((float) $value === -1.0) {
            return array('HT' => '-1.00', 'TTC' => '-1.00');
        }
        global $mysoc;
        require_once DOL_DOCUMENT_ROOT . '/core/lib/price.lib.php';
        $totals = calcul_price_total(1, (float) $value, 0, $vat, 1, 1, 0, $base, 0, (int) $product->type, $mysoc, array(0, 0, 0, 0));
        $ht = $totals[0];
        $ttc = $totals[2];
        return array('HT' => number_format($ht, 2, '.', ''), 'TTC' => number_format($ttc, 2, '.', ''));
    }

    /** Apply explicit overrides after remote variable-price constraints. */
    public function apply($product, $store, array $details)
    {
        $state = $this->fetch($product->id);
        if (!$state['enabled'] || !in_array((int) $store, $this->activeStores($product->id), true)) {
            return $details;
        }
        foreach ($state['prices'][(int) $store] ?? array() as $level => $value) {
            $level = (int) $level;
            if ($level < 1 || $level > self::levelLimit()) {
                continue;
            }
            $pair = self::pair($product, $level, $value);
            $details[$level === 1 ? 'precovenda' : 'pvp' . $level] = $pair['TTC'];
            $details['pvp' . $level . 'siva'] = $pair['HT'];
        }
        return $details;
    }
}

<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License,
 * or (at your option) any later version.
 */

/** Preserve source sale properties without assigning a platform-specific meaning. */
class ZSInvoiceProperties
{
    public static function extract($document, $expectedStore)
    {
        $document = (array) $document;
        $movements = $document['movimentospropriedades'] ?? array();
        if (is_object($movements)) {
            $movements = (array) $movements;
        }
        if (!is_array($movements)) {
            throw new InvalidArgumentException('ZoneSoft property movements must be a list.');
        }
        if (!$movements) {
            return array();
        }
        $store = (string) ($document['loja'] ?? '');
        if (!ctype_digit($store) || (int) $expectedStore <= 0 || (int) $store !== (int) $expectedStore) {
            throw new InvalidArgumentException('Sale property source is outside the configured invoice store.');
        }
        $properties = array();
        foreach ($movements as $movement) {
            if (!is_array($movement) && !is_object($movement)) {
                throw new InvalidArgumentException('Malformed ZoneSoft property movement.');
            }
            $movement = (array) $movement;
            $id = (string) ($movement['idpropriedade'] ?? $movement['cod_propriedade'] ?? $movement['propriedade'] ?? '');
            if (!ctype_digit($id) || (int) $id <= 0) {
                throw new InvalidArgumentException('Missing source sale property identifier.');
            }
            $value = $movement['valor_propriedade'] ?? $movement['valor'] ?? $movement['value'] ?? '';
            if (!is_string($value) && !is_int($value) && !is_float($value)) {
                throw new InvalidArgumentException('Unsupported source sale property value.');
            }
            // Retain the original value type: numeric properties must never become order codes.
            if (is_string($value)) {
                $value = trim($value);
                if (strlen($value) > 1000 || preg_match('/[\x00-\x1f\x7f]/', $value)) {
                    throw new InvalidArgumentException('Source sale property contains unsupported text.');
                }
            }
            if ($value === '') {
                continue;
            }
            $key = (string) (int) $id;
            if (!isset($properties[$key])) {
                $properties[$key] = array();
            }
            if (!in_array($value, $properties[$key], true)) {
                $properties[$key][] = $value;
            }
        }
        if (!$properties) {
            return array();
        }
        ksort($properties, SORT_NUMERIC);
        $evidence = array('store' => (int) $store, 'properties' => $properties);
        if (strlen(json_encode($evidence, JSON_THROW_ON_ERROR)) > 60000) {
            throw new InvalidArgumentException('Source sale property evidence exceeds the supported size.');
        }
        return $evidence;
    }

    public static function options(array $evidence)
    {
        return $evidence ? array('options_zs_sale_properties' => json_encode($evidence, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)) : array();
    }

    /** Idempotent native migration; shared definition, values scoped through their invoice. */
    public static function ensureExtraFields($db)
    {
        require_once DOL_DOCUMENT_ROOT.'/core/class/extrafields.class.php';
        $extra = new ExtraFields($db);
        if ($extra->fetch_name_optionals_label('facture') < 0) {
            throw new RuntimeException('Unable to read invoice extrafield definitions.');
        }
        $existing = $extra->attributes['facture']['type']['zs_sale_properties'] ?? '';
        if ($existing !== '') {
            if ($existing !== 'text') {
                throw new RuntimeException('Existing sale-property extrafield has an incompatible definition.');
            }
            return;
        }
        $result = $extra->addExtraField('zs_sale_properties', 'ZSSaleProperties', 'text', 105, '', 'facture', 0, 0, '', '', 0, '', '0', '', '', '0', 'dolizsynch@kreaflow', '1', 0, 0);
        if ($result < 0) {
            throw new RuntimeException('Unable to create sale-property invoice extrafield.');
        }
    }
}

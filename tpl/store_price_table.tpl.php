<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License,
 * or (at your option) any later version.
 */
if (!defined('DOL_DOCUMENT_ROOT') || empty($storePriceState['enabled'])) {
    return;
}
$editingStorePrices = $action === 'edit_price' && $canEditStorePrices;
$levelLimit = ZSStorePrices::levelLimit();
if ($editingStorePrices) {
    print '<form method="post" id="dzs-store-prices" action="' . dol_escape_htmltag($_SERVER['PHP_SELF']) . '?id=' . (int) $object->id . '">';
    print '<input type="hidden" name="token" value="' . newToken() . '"><input type="hidden" name="action" value="save_store_prices">';
    print '<input type="hidden" name="revision" value="' . (int) $storePriceState['revision'] . '">';
}
print '<div class="div-table-responsive"><table id="dzs-store-price-table" class="noborder tableforfield centpercent">';
print '<thead><tr class="liste_titre"><th>' . $langs->trans('DZSStore') . '</th><th>' . $langs->trans('PriceLevel') . '</th>';
foreach (array('DZSPriceTTC', 'DZSPriceHT', 'DZSCostHT', 'DZSMargin', 'DZSMinimumHT') as $heading) {
    print '<th class="right">' . $langs->trans($heading) . '</th>';
}
print '</tr></thead><tbody>';
foreach ($storePriceStores as $store) {
    for ($level = 1; $level <= $levelLimit; $level++) {
        $saved = $storePriceState['prices'][$store][$level] ?? null;
        $standard = array(
            'HT' => $object->multiprices[$level] ?? ($level === 1 ? $object->price : 0),
            'TTC' => $object->multiprices_ttc[$level] ?? ($level === 1 ? $object->price_ttc : 0),
        );
        $effective = $saved ? ZSStorePrices::pair($object, $level, $saved) : $standard;
        $pair = $saved ? $effective : array('HT' => '', 'TTC' => '');
        if ($saved) {
            $pair[$saved['base']] = $saved['value'];
        }
        $name = 'store_prices[' . (int) $store . '][' . $level . ']';
        $vat = (float) ($object->multiprices_tva_tx[$level] ?? $object->tva_tx);
        $cost = (float) $object->cost_price;
        $minimum = $object->multiprices_min[$level] ?? ($level === 1 ? $object->price_min : 0);
        $margin = $cost > 0 && (float) $effective['HT'] > 0 ? round(((float) $effective['HT'] / $cost - 1) * 100, 2) : 0;
        $variable = (float) $effective['HT'] === -1.0;
        print '<tr class="oddeven dzs-store-price-row" data-vat="' . $vat . '" data-cost="' . $cost . '">';
        // Keep a first-column cell in every row so native theme borders and corners align.
        print '<td class="valigntop">' . ($level === 1 ? dol_escape_htmltag(html_entity_decode($storePriceLabels[$store], ENT_QUOTES, 'UTF-8')) : '') . '</td>';
        $levelLabel = $langs->trans('SellingPrice') . ' ' . $level;
        $configuredLabel = getDolGlobalString('PRODUIT_MULTIPRICES_LABEL' . $level);
        print '<td class="nowrap">' . $levelLabel . ($configuredLabel !== '' ? ' - ' . dol_escape_htmltag($configuredLabel) : '') . '</td>';
        foreach (array('TTC', 'HT') as $base) {
            print '<td class="right nowrap">';
            if ($editingStorePrices) {
                print '<input type="text" inputmode="decimal" class="flat right width100" data-base="' . $base . '" name="' . $name . '[' . $base . ']" value="' . dol_escape_htmltag((string) $pair[$base]) . '" placeholder="' . dol_escape_htmltag((string) $standard[$base]) . '" aria-label="' . dol_escape_htmltag(html_entity_decode($storePriceLabels[$store] . ' ' . $levelLabel, ENT_QUOTES, 'UTF-8')) . ' ' . $base . '" title="' . dol_escape_htmltag(html_entity_decode($langs->trans('DZSStorePricesHelp'), ENT_QUOTES, 'UTF-8')) . '">';
                if ($base === 'HT') {
                    print '<input type="hidden" class="dzs-price-base" name="' . $name . '[base]" value="' . ($saved['base'] ?? 'TTC') . '">';
                }
            } else {
                print '<span class="amount"' . (!$saved ? ' title="' . dol_escape_htmltag(html_entity_decode($langs->trans('DZSInheritedPrice'), ENT_QUOTES, 'UTF-8')) . '"' : '') . ($variable ? '>' . $langs->trans('DZSVariablePrice') : '>' . price($effective[$base])) . '</span>';
            }
            print '</td>';
        }
        print '<td class="right"><span class="amount">' . price($cost) . '</span></td>';
        print '<td class="right"><span class="dzs-store-margin">' . ($variable ? '—' : price($margin) . '%') . '</span></td>';
        print '<td class="right"><span class="amount">' . price($minimum) . '</span></td></tr>';
    }
}
if (!$storePriceStores) {
    print '<tr class="oddeven"><td colspan="7">' . $langs->trans('DZSNoActiveStores') . '</td></tr>';
}
print '</tbody></table></div>';
if ($editingStorePrices) {
    print $form->buttonsSaveCancel();
    print '</form>';
}
?>
<script>
document.querySelectorAll('#dzs-store-prices .dzs-store-price-row').forEach(function (row) {
    row.querySelectorAll('input[data-base]').forEach(function (input) {
        input.addEventListener('input', function () {
            var base = input.dataset.base;
            row.querySelector('.dzs-price-base').value = base;
            var other = row.querySelector('input[data-base="' + (base === 'HT' ? 'TTC' : 'HT') + '"]');
            var text = input.value.trim().replace(',', '.');
            if (text === '') { other.value = ''; row.querySelector('.dzs-store-margin').textContent = '—'; return; }
            if (!/^(?:\d+(?:\.\d{1,8})?|-1)$/.test(text)) { return; }
            var value = Number(text), factor = 1 + Number(row.dataset.vat) / 100;
            other.value = value === -1 ? '-1' : (base === 'HT' ? value * factor : value / factor).toFixed(2);
            var ht = base === 'HT' ? value : value / factor, cost = Number(row.dataset.cost);
            row.querySelector('.dzs-store-margin').textContent = value === -1 ? '—' : (cost > 0 && ht > 0 ? ((ht / cost - 1) * 100).toFixed(2) : '0') + '%';
        });
    });
});
</script>

<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License,
 * or (at your option) any later version.
 */
if (!defined('DOL_DOCUMENT_ROOT')) {
    exit;
}
print '<tr><td class="titlefield">' . $langs->trans('DZSStorePricesEnable') . '</td><td colspan="3">';
if ($storePriceState !== null && $canEditStorePrices) {
    print '<form method="post" action="' . dol_escape_htmltag($_SERVER['PHP_SELF']) . '?id=' . (int) $object->id . '">';
    print '<input type="hidden" name="token" value="' . newToken() . '">';
    print '<input type="hidden" name="action" value="toggle_store_prices">';
    print '<input type="hidden" name="revision" value="' . (int) $storePriceState['revision'] . '">';
    print '<input type="hidden" name="enabled" value="' . (int) !$storePriceState['enabled'] . '">';
    print '<button type="submit" class="linkobject" style="border:0;background:none;padding:0;cursor:pointer" aria-label="' . dol_escape_htmltag($langs->trans('DZSStorePricesEnable')) . '" aria-pressed="' . ($storePriceState['enabled'] ? 'true' : 'false') . '">';
    print '<span class="fas ' . ($storePriceState['enabled'] ? 'fa-toggle-on font-status4' : 'fa-toggle-off opacitymedium') . '"></span></button></form>';
} else {
    print $storePriceState === null ? $langs->trans('DZSStorePricesUnavailable') : $langs->trans($storePriceState['enabled'] ? 'Yes' : 'No');
}
print '</td></tr>';

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

// CLI-only regression: node test/invoice_creator_validation_test.js
'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const { execFileSync } = require('node:child_process');
const root = path.resolve(__dirname, '..');
const source = fs.readFileSync(path.join(root, 'zsbms_invoice_creator.php'), 'utf8');
function extract(name, nextName) {
    return source.slice(source.indexOf('function ' + name + '('), source.indexOf('function ' + nextName + '(')).trim();
}
const functions = [
    extract('tokenizeCalculationExpression', 'calculateDecimalExpression'),
    extract('calculateDecimalExpression', 'parseNumber'),
    extract('parseNumber', 'formatNumber'),
    extract('isRowEmpty', 'getRowVatRate'),
    extract('formatInvalidRowsMessage', 'validateRows'),
    source.slice(source.indexOf('function validateRows('), source.indexOf('\n\tif (composerAddBtn)', source.indexOf('function validateRows('))).trim()
].join('\n');
const messages = {
    invalidRows: 'Please review {count} invalid line(s): {rows}.',
    lineReference: 'Reference', lineQuantity: 'Qty.', lineUnitPrice: 'Unit price', lineDiscount: 'Discount'
};
const context = vm.createContext({ messages, defaultWarehouseId: 1, rows: [],
    getRows() { return context.rows; },
    getInput(row, name) { return row.fields[name]; }
});
vm.runInContext(functions, context);
function row(values = {}) {
    const fields = {};
    const defaults = { codigo: '123', qtd: '1', punit: '4.25', desconto: '0', obs: 'Demo item', armazem: '1' };
    Object.entries({ ...defaults, ...values }).forEach(([name, value]) => { fields['line_' + name + '[]'] = { value }; });
    const classes = new Set();
    return { fields, classList: { add(name) { classes.add(name); }, remove(name) { classes.delete(name); } }, classes };
}
function validate(rows) { context.rows = rows; return context.validateRows(); }
let result = validate([row({ qtd: '2*3', punit: '1,25' }), row({ punit: '0' })]);
assert.equal(result.invalidRows, 0, 'valid arithmetic, decimal comma and free line remain valid');
result = validate([row({ codigo: '', qtd: '1', punit: '', obs: '' })]);
assert.equal(result.hasAnyLine, false, 'default blank row is ignored');
result = validate([...Array.from({ length: 9 }, () => row()), row({ punit: '..' })]);
assert.equal(result.invalidRows, 1);
assert.equal(context.formatInvalidRowsMessage(result), 'Please review 1 invalid line(s): 10 (Unit price).');
const invalid = row({ qtd: '0' });
result = validate([row(), invalid]);
assert.equal(result.invalidRows, 1);
assert.equal(context.formatInvalidRowsMessage(result), 'Please review 1 invalid line(s): 2 (Qty.).');
assert(invalid.classes.has('dzs-row-invalid'));
invalid.fields['line_qtd[]'].value = '3';
assert.equal(validate([invalid]).invalidRows, 0);
assert(!invalid.classes.has('dzs-row-invalid'), 'corrected row loses its error marker');
result = validate([row({ codigo: '', punit: '1/0' }), row({ qtd: '-1', desconto: '101' })]);
assert.equal(result.invalidRows, 2);
assert.equal(context.formatInvalidRowsMessage(result), 'Please review 2 invalid line(s): 1 (Reference, Unit price); 2 (Qty., Discount).');
for (const language of ['en_US', 'pt_PT']) {
    const lang = fs.readFileSync(path.join(root, 'langs', language, 'dolizsynch.lang'), 'utf8');
    const template = lang.match(/^ZSBMSInvalidRowsDetails = (.+)$/m)[1];
    // Dolibarr trans() formats even without supplied parameters. Preserve both named placeholders.
    messages.invalidRows = execFileSync('php', ['-r', 'echo sprintf($argv[1], "", "", "", "");', template], { encoding: 'utf8' });
    const formatted = context.formatInvalidRowsMessage(result);
    assert(formatted.includes('2 invalid') || formatted.includes('2 linha'));
    assert(formatted.includes('1 (Reference, Unit price); 2 (Qty., Discount)'));
    assert(!formatted.includes('{count}') && !formatted.includes('{rows}'));
}
const priceLabelExpression = source.match(/'lineUnitPrice' => (.+),/)[1];
const decodedLabel = execFileSync('php', ['-r', '$txtUnitPriceShort = "Pre&ccedil;o unit&aacute;rio"; echo ' + priceLabelExpression + ';'], { encoding: 'utf8' });
assert.equal(decodedLabel, 'Preço unitário', 'translated field labels remain readable in JavaScript alerts');
console.log('PASS: validation, row details, correction, arithmetic, blank rows, translated counts and decoded labels');
if (process.argv.includes('--fixture')) {
    const css = source.slice(source.indexOf('#dzs-invoice-creator {'), source.indexOf("</style>'", source.indexOf('#dzs-invoice-creator {')));
    assert(!css.includes('<?php') && !css.includes("' ."));
    const html = '<!doctype html><meta charset="utf-8"><title>Invoice validation regression</title><style>' + css + '</style>' +
        '<div id="dzs-invoice-creator"><h1>Invoice validation regression</h1><p id="result">' + context.formatInvalidRowsMessage(result) + '</p>' +
        '<div class="dzs-line-row oddeven dzs-row-invalid">1 — Invalid reference and price</div>' +
        '<div class="dzs-line-row oddevenpair dzs-row-invalid">2 — Invalid quantity and discount</div>' +
        '<div class="dzs-line-row oddevenpair">3 — Valid row</div></div>';
    fs.mkdirSync(path.join(root, 'bin'), { recursive: true });
    fs.writeFileSync(path.join(root, 'bin', 'invoice-validation-fixture.html'), html);
}

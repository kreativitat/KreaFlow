# ZoneSoft credit-note signs

ZoneSoft can return NC documents with negative header totals, negative quantities,
negative line totals and positive unit prices. The importer retains the signed
source header and queue evidence, but converts the consistently negative line
quantities and monetary amounts into positive inputs for the exact monetary plan.
Fixed monetary discounts reverse polarity with the line amounts; unit prices,
VAT rates and percentage discounts retain their original meaning. The existing
source-line, VAT-group and header checks must still reconcile every cent.

Dolibarr's native credit-note lifecycle applies the negative sign once. Its stored
line, header and multicurrency totals must remain negative. Existing NC documents
expressed as positive magnitudes remain supported. Mixed header or line polarity,
negative prices or percentages, and negative ordinary invoices fail validation.
Linked-invoice verification accepts either signed NC source totals or legacy
positive magnitudes by comparing the native total with the negative absolute
source total.

An unpaid NC remains open after import. Close it through the native credit-reuse
or refund lifecycle. When source evidence explicitly assigns a credit to an
existing invoice, use native credit allocation to reduce the remaining balance;
do not insert a negative invoice line or invent a bank payment.

Run `php test/invoice_credit_note_test.php` and the existing
`php test/invoice_precision_test.php` for mapping and arithmetic regression.
`php test/invoice_credit_note_dolibarr_test.php` requires the isolated local
Dolibarr integration container and rolls back its synthetic business rows.
Never run the integration fixture against production.

For a failed production NC, back up the original source document, queue row and
affected native records first. Verify the configured entity/store and fiscal
identity, then invoke the existing single-row synchronization entry point. Do not
change source fiscal totals or add balancing entries. Inspect native lifecycle
effects, exact line/header/currency amounts, payment and stock movements, daily
report equality and duplicate prevention before declaring recovery complete.

Let native invoice creation own its transactions, retaining its source-reference
lock. Holding additional invoice and queue row locks across creation can deadlock
with live finance activity. After verifying the committed credit, allocate it in
a separate transaction with both invoices locked in row-ID order. Recheck company,
customer, outstanding balance and existing allocation before writing. The native
unused-credit aggregate returns `NULL` when no unused rows remain; treat that empty
sum as zero while preserving query errors.

Production may contain independently deployed changes absent from a checkout.
Apply this hotfix to verified runtime baselines and preserve those changes. Record
runtime hashes and the source commit separately; this change needs no schema
migration or module reactivation.

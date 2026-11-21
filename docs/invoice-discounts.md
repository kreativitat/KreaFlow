# Source-confirmed invoice discounts

ZoneSoft can return both a fixed line discount (`desconto`) and a percentage
(`desconto2`) calculated against a different price basis. The percentage need
not equal the fixed discount divided by the supplied quantity and unit price.

When the fixed discount alone reproduces the authoritative source line TTC
exactly, the importer applies that amount once and retains the source percentage
in `source_remise_percent`. This requires source line HT and TTC. The existing
monetary calculator independently validates each line, VAT and the invoice
header before native invoice validation and payment creation. Separate combined
percentage and fixed discounts remain supported when both are needed to match
the source; inconsistent source cents remain errors.

For example, quantity 1, price EUR 4.00, fixed discount EUR 1.00 and informational
24 percent gives EUR 3.00 TTC and EUR 2.65 HT at 13 percent VAT. Applying both
discounts would incorrectly give EUR 2.04 before rounding.

Run `php test/invoice_precision_test.php` with PHP 7.1 or later. The synthetic
split-menu regression preserves five invented source lines and two VAT rates,
reconciling EUR 3.15 HT + EUR 0.44 VAT = EUR 3.59 TTC. It also covers independent
combined discounts, global discounts, missing source totals and rejection of
inconsistent HT/TTC. No schema migration or module reactivation is required.

Recovery must snapshot source documents and queue rows privately, import only
verified missing headers, retry failed invoices through the native lifecycle,
and independently reconcile source, queue, invoices and payment allocations.
Do not mark queue rows successful or rewrite fiscal source amounts by hand.

## Late source uploads

The newest invoice-header page can omit older invoices uploaded together after
a POS synchronization delay. `ZS_INVOICE_IMPORT_LOOKBACK_DAYS=1` makes the existing
routine header job paginate today's and yesterday's fiscal dates, in the configured
synchronization timezone, instead of only reading the newest page. Source store
ownership, duplicate protection and native invoice processing remain unchanged.
The setting accepts 0 through 31; 0 retains the existing page/full-pagination
behavior. Configure it per company, after backing up its prior value. It needs
no new scheduler, module activation or schema migration. Run
`php test/invoice_header_pagination_test.php` for the window/pagination regressions.

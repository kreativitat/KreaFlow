# Source line discount representations

KreaFlow 3.4.9 recognizes a fixed ZoneSoft line discount whose `desconto2`
percentage is a rounded representation of the same amount. For example, a
EUR 1 discount on EUR 3 is also reported as 33%, while the source line TTC is
EUR 2. Applying both fields would incorrectly produce EUR 1.01.

The fixed amount is applied once only when its ratio rounds to the supplied
percentage precision and the resulting line TTC matches the source cents after
any global discount. Authoritative line HT and TTC are required. The existing
decimal calculator must still reconcile every source line, VAT group and invoice
header. Original source percentage evidence is retained in the mapping.

The existing percentage-owned duplicate representation, independent combined
discounts, signed fixed surcharges and source rounding methods are unchanged.
No VAT-rate changes, adjustment products or schema migration are involved.

`test/invoice_precision_test.php` covers the two fixed-discount inputs, global
discounts, independent combined discounts, invalid percentage/HT/TTC evidence,
missing source totals and VAT-exclusive prices. The recovery/payment integration
suite remains `test/invoice_rounding_recovery_dolibarr_test.php`.

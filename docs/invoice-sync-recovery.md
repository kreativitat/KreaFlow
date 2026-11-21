# Invoice save failure recovery

Both configured-store and date-specific invoice queues use
`ZSSyncStatus::getSynchronizableStatuses()`. Save/update errors (8) and queued
retries (11) are eligible. Save failures consume the existing
`ZS_MAX_SYNCH_TRIES_PER_INVOICE` budget, defaulting to three attempts. The
configured per-run queue limit and existing scheduling remain authoritative.
No new scheduler, migration, module reactivation or version change is required.

Native creation failures retain the invoice error array/scalar error, falling
back to the database error and a nonempty native return-code message. A return
value of zero cannot be treated as a created invoice. Source retrieval also
requires exact store, document type, series, number and fiscal date. Ordinary
invoices require complete sales lines; receipts retain their payment-only
contract. HTTP 401 is an authentication failure. Missing, mismatched or failed
source retrieval never creates a generic invoice from queue header totals.

A retry holds the existing entity/reference advisory lock and reloads the queue
row before checking eligibility. A concurrently blocked or exhausted row cannot
resume from a stale selection. Failed linked invoices enter the same verified
recovery path as pending invoices. A failed database read must not authorize
recreation. After a confirmed missing partial invoice, clear its live link and
current context ID while retaining the previous ID as evidence and preserving
retry counters. Only a new explicit `facture_id` updates the live link; historical
context cannot silently restore it. Duplicate recovery supplies that ID explicitly.

The source includes the already deployed safeguards for exact source monetary
plans, draft/duplicate validation, individual base/multicurrency line totals,
company ownership and serialized remaining-balance/payment creation. Keep them
when applying this fix; an older checkout can lack these production changes.
Unchanged monetary, stock, payment, permission and company/store validation must
still succeed. Existing manual exclusions and exhausted retry states remain closed.

## Validation

Pure PHP regressions:

```sh
php test/invoice_creation_failure_test.php
php test/invoice_precision_test.php
php test/invoice_credit_note_test.php
php test/invoice_header_pagination_test.php
php test/invoice_datetime_conversion_test.php
php test/paid_invoice_cancellation_test.php
```

The native integration runner requires Docker, local Dolibarr 24.0.2 sources,
`mariadb:11.4`, and an existing PHP image with the Dolibarr mysqli/runtime
extensions. It creates a separate internal network, disposable database in tmpfs,
synthetic configuration/customer/user, and read-only source mounts. It refuses to
overwrite existing task containers/networks and removes its resources on exit.
It does not read the installed database or contact ZoneSoft. Mail/PDF output is
disabled in the synthetic instance. Tests cover both queues, stale selection,
retry exhaustion, missing/duplicate/foreign links, source monetary consistency,
credit notes and payment idempotence; financial fixtures roll back.

```sh
python3 test/run_invoice_sync_integration.py \
  --dolibarr-root /path/to/dolibarr/html \
  --php-image your-existing-dolibarr-php-image
```

Initial recovery fix validated on 19 November 2026: 189 native integration checks plus creation,
precision, credit-note, pagination, datetime and cancellation regressions passed
on PHP 8.2.35/MariaDB 11.4. All six changed runtime files also passed parsing
under production PHP 8.1.34 without executing their business logic. The broader
security/metadata suite has the same 38 pre-existing failures on clean main and
the candidate, with no additional failures. Production execution remains a
separate acceptance step.

The minimum-date follow-up passes 67 retry/boundary checks and all 234 native
integration checks on PHP 8.2.35/MariaDB 11.4, including inclusive selection,
stale-row rejection without evidence changes, invalid configuration and unset
compatibility. The changed class and fixture also pass PHP 8.2 lint; production
PHP 8.1 lint passed during deployment of this follow-up.

## Deployment and backlog review

Administrators can set the optional per-entity constant
`ZS_INVOICE_SYNC_MIN_DATE` to a valid ISO date (`YYYY-MM-DD`) before restoring
invoice jobs. Both native queues and the fresh row reloaded under the
entity/reference lock enforce this inclusive `data_zs` boundary. Rows with an
older or missing fiscal date are excluded without changing their status, links
or retry history. An explicit date-specific run cannot override the boundary.
Invalid configuration stops synchronization with an actionable error before
queue or financial writes. An unset or empty value preserves legacy behavior.
Clearing or moving the boundary earlier requires an explicit decision about the
historical recovery scope; it must not happen automatically after recent recovery.
This administrative constant adds no setup UI, schema change or scheduler.

Snapshot the current runtime and affected queue/source/native evidence privately.
Check current production hashes before replacing files, preserving independent
runtime changes. Review all newly eligible historical status-8 rows per enabled
company before deployment: their old exclusion may have accumulated a much older
backlog than the reported incident. Preserve disabled companies, manual skips,
schedules, source fiscal amounts and existing business records. Do not bulk-reset
statuses, erase retry history or manually mark failures successful.

A push updates source only. After an authorized deployment/recovery, verify exact
runtime hashes, source/queue/native totals, invoice identity and allocation/stock
idempotence. Distinguish recent incident recovery from legacy errors. Database
lock timeouts require separate live evidence about their owner; lost historical
error text alone cannot establish the original lock cause.

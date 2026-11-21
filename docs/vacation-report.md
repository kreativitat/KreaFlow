# Vacation calendar

## Usage

Open **Férias → Mapa de férias** on the annual vacation page, or `leaves/month_report.php`. Select a year and optionally a month. The annual summary always covers the full year. Excel exports contain the summary and selected monthly calendars. Browser printing uses landscape pages and repeated table headers.

Native presentation follows the installed Dolibarr 24 UI documentation (`admin/tools/ui/index.php`) and core `holiday/month_report.php`; scoped CSS reflows grids into cards below 1100px. Employee names and numbers are live module data, never copied from the reference workbook.

Access requires the enabled synchronization provider, an internal user and `dolizsynch.shift.schedule_vacations_view`, before source reads or downloads. The active company and the existing global/null leave-sharing contract remain authoritative. There is no cross-company aggregation or data import. Employees from the native company/year roster and historical users with scoped leave remain visible. Employee overrides in the interactive planner are not report filters.

The read-only projection uses `dolizsynch_leave` and configured vacation types, including inactive historical types. Calendar-day counts include covered weekends and public holidays. Overlapping marks are capped at one day per employee/date. Partial leave uses the configured working-day minutes, with hours allocated by interval intersection. The summary's elapsed figure is scheduled leave before today in Europe/Lisbon, not confirmation of actual leave taken. Entitlement uses configured user/type/year quotas, with year-zero fallback; missing quotas remain unavailable and no carryover is invented.

Planner intervals use stored operational cutoffs and half-open ends with calendar arithmetic across DST. The standalone card's date-only inclusive records are recognized when their cutoff is positive, plus equal midnight endpoints. Multi-day midnight records at cutoff zero cannot identify their writer convention; retain the planner half-open convention. Invalid records produce an explicit exclusion warning on screen and in Excel.

## Verification

`DOLIBARR_TEST_ROOT=/path/to/dolibarr/html php tests/run_vacation_report_tests.php` covers both DST transitions, leap day, cross-year clipping, all partial modes, hourly allocation, legacy cards, overlap, invalid rows, missing quota and access denial. PHP 8.2 lint/regressions and Dolibarr's bundled PhpSpreadsheet fixture export passed. Synthetic browser rendering passed at 1280px and 320px, with document width exactly 320px at the narrow breakpoint. Review by the Integration & ERP specialist found no remaining blockers after fixes.

## Deployment

Deploy only the nine runtime files associated with this report, preserving independent production translation additions. No migration, module reactivation, financial writes or scheduled jobs are needed. The existing provider version remains 3.6.2; this is an additive report patch. Back up replaced files and retain absent-file identities, then PHP-lint before replacement and verify every deployed SHA-256. Rollback restores the backed-up files and removes only newly introduced report files listed in the deployment manifest.

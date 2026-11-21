# CHANGELOG MODULE KREAFLOW FOR [DOLIBARR ERP CRM](https://www.dolibarr.org)

## Unreleased

### Security
- Rebuild the public PWA runtime from patched dependencies and remove obsolete generated bundles from the public source tree.
- Publish a sanitized source history without deployment records, packaged archives, customer tax/store mappings or production-derived financial fixtures.
- Require explicit installation configuration and add secret scanning, publication checks and dependency monitoring.

### Added
- Add a company-scoped vacation calendar with monthly employee/day grids, annual quota summary, mobile calendar cards, landscape printing and Excel export from the custom leave ledger.

### Fixed
- Require exact source store/type/series/number/date and complete invoice lines before import; classify HTTP 401 as authentication failure and refuse generic invoice creation for unavailable source evidence.
- Retry invoice save/update failures through both entity/store-scoped queues, honoring the existing retry cap and preserving native error details.
- Recover missing partial-invoice links without losing retry history or restoring stale IDs; recheck terminal states after locking and retain deployed exact-total, duplicate and payment safeguards.
- Import signed ZoneSoft credit-note quantities and totals through the exact monetary plan, applying the native credit sign once and accepting signed or legacy source totals on retry.
- Keep unpaid imported credit notes available for native credit reuse instead of closing them before a refund or allocation.
- Add an opt-in recent calendar-day overlap to routine invoice-header imports so delayed source batches cannot fall outside the newest page.
- Apply a source-confirmed fixed invoice discount once when the accompanying percentage uses a different price basis; preserve original percentage evidence and all monetary validation.

## [2.22.0] - 2026-09-20

### Added
- Displayed KreaReports archived forecast errors and bias in the mobile forecast view using the shared evaluator, current-company scope and both modules' history permissions.

## [2.21.4] - 2026-09-20

### Fixed
- Compared complete calendar months with the entire same month in the previous year, including February leap-day differences, before applying the short-period weekday rule.

## [2.21.3] - 2026-09-20

### Changed
- Displayed the historical sales variation percentage only beside VAT-exclusive totals.

## [2.21.2] - 2026-09-20

### Changed
- Aligned historical sales comparisons by weekday for periods up to 35 inclusive days (364 days earlier), retained calendar-year comparison for longer periods, and labeled the selected comparison method.

## [2.21.1] - 2026-09-14

### Changed
- Displayed signed year-over-year percentage changes beside historical sales totals, with a dash when the previous total is zero.

## [2.21.0] - 2026-09-14

### Added
- Added previous-year matching-period VAT-inclusive and VAT-exclusive totals to the mobile Total Sales card, respecting history permissions and entity visibility.

### Changed
- Reused selected-period daily sales for the chart and queried only missing padding days, avoiding duplicate selected-period invoice-line aggregation.

## [2.20.3] - 2026-08-25

### Fixed
- Excluded the current day from the PATTIES stock forecast selector and made tomorrow, or the first later available forecast date, the default view.

## [2.20.2] - 2026-08-25

### Changed
- Replaced the aggregated next-delivery PATTIES stock presentation with a selectable daily forecast showing each product's requirement for that day and its projected stock balance at the end of the selected day.

## [2.20.1] - 2026-08-25

### Changed
- Replaced the PATTIES full-horizon stock requirement in the primary table with the next delivery-cycle requirement, using deliveries on Monday, Tuesday, Thursday, Friday, and Saturday; Tuesday includes Wednesday demand and Saturday includes Sunday demand.
- Added an expandable per-delivery requirement breakdown while retaining the complete forecast-horizon totals in the API for diagnostic compatibility.
- Restricted the PATTIES stock forecast tab and API to a setup-managed global entity allowlist, requiring explicit entity selection.
- Decoupled forecast visualization dates from model training: mobile forecast views default to 30 days while the sales and stock forecast engines always retain the optimized completed-day training interval.

## [2.20.0] - 2026-08-25

### Added
- Added a mobile PATTIES stock forecast report using the latest entity-and-warehouse-scoped KreaProducts inventory, current physical stock, recent operational consumption, and the native seven-day sales forecast.
- Added product-level forecast requirements, projected balances, and shortage indicators without implicit safety stock or planned supplier receipts.

### Fixed
- Rendered the historical and forecast chart as one continuous curve with a color transition at the shared boundary point, removing the interpolation cusp created by two independently smoothed lines while preserving distinct real and forecast colors.

## [2.19.0] - 2026-08-25

### Added
- Added a forecast-specific revenue-only daily query so the wider training interval does not execute Dashboard invoice-line quantity aggregation.
- Added Open-Meteo rain-probability percentages while preserving the existing qualitative API field.

### Changed
- Reworked sales forecasting over up to 365 completed days to blend a recency-weighted ridge model with the recent median for each weekday, adding weekly seasonality, outlier resistance, stable missing-weather imputation, and independent VAT-inclusive and VAT-exclusive predictions.
- Aligned forecast weather requests with the Europe/Lisbon timezone.

## [2.18.2] - 2026-08-25

### Changed
- Added Dolibarr's native mobile-phone pictogram to the `App Móvel` left-menu entry.

## [2.18.1] - 2026-08-23

### Fixed
- Reduced Dashboard API response time by aggregating invoice-line quantities only for the entity- and date-filtered invoices instead of grouping the complete invoice-line table on every request.

## [2.18.0] - 2026-07-11

### Added
- Added a 12-hour, user-and-entity-scoped dashboard startup cache that renders the last validated response immediately and refreshes it from the API in the background.

### Changed
- Deferred product category and per-category product requests until the Products tab is opened, removing unrelated product requests from Dashboard startup.
- Skipped public login metadata loading for authenticated sessions, including installed-PWA startup where the session is validated through `whoami`.

## [2.17.1] - 2026-07-09

### Fixed
- Routed KreaFlow Google-login OAuth start and return through KreaFlow-owned mobile handlers so successful Google authentication opens the module dashboard instead of the Dolibarr root or global landing page, without changing the normal Dolibarr login route.

## [2.17.0] - 2026-07-09

### Added
- Added Krea2FactorAuth-aware mobile login metadata so KreaFlow can detect authenticator and Google-login availability for the selected entity.
- Added a six-digit authenticator code control to the existing KreaFlow mobile login design when Krea2FactorAuth is active for the selected entity.
- Added a Google login button to the existing KreaFlow mobile login design when Krea2FactorAuth Google login is configured for the selected entity.

### Changed
- Updated the KreaFlow login API to forward JSON-submitted `totp_code` values through Dolibarr request scope before web-context `checkLoginPassEntity()`, allowing Krea2FactorAuth to enforce its native TOTP validation, lockout, and logging rules.
- Normalized legacy KreaFlow author headers to `Kreativität Works <mail@kreativitat.com>`.

## [2.16.9] - 2026-05-14

### Fixed
- Fixed dashboard people totals and average ticket calculations by excluding credit-note (`NC`) rows from `pessoas_zs` aggregation while keeping credit notes in revenue totals.

## [2.16.8] - 2026-05-14

### Fixed
- Reworked synced dashboard daily sales rows to aggregate `liquido_zs` and `total_zs` directly by ZS sale date, avoiding invoice-join enrichment drift in headline sales KPIs.

## [2.16.7] - 2026-05-14

### Fixed
- Aligned dashboard and forecast KPI totals with InvoiceSynchIntegrity by removing the legacy `CI` exclusion from synced daily sales totals.

## [2.16.6] - 2026-05-14

### Fixed
- Fixed MariaDB SQL compatibility in synced dashboard daily totals by replacing the `lines` derived-table alias with a non-conflicting alias.

## [2.16.5] - 2026-05-14

### Fixed
- Fixed synced dashboard sales totals to use `dolizsynch_zsinvoicesynch.total_zs` as the VAT-inclusive source of truth, so ZS credit notes (`NC`) reduce daily KPI totals consistently with InvoiceSynchIntegrity.
- Updated synced dashboard product, employee, hourly, and payment breakdown calculations to include credit-note invoices with signed values instead of excluding Dolibarr invoice type 2.

## [2.16.4] - 2026-04-05

### Changed
- Removed manual date picker controls from the native `Prev. Vendas` tab and switched the forecast request to an automatic fixed historical window (last 60 days), reducing mobile UI clutter.

## [2.16.3] - 2026-04-05

### Changed
- Improved mobile readability of the native `Prev. Vendas` forecast table by adding alternating card backgrounds and alternating label/value row tones for clearer visual separation on small screens.

## [2.16.2] - 2026-04-05

### Changed
- Updated the native `Prev. Vendas` chart series merge so the forecast (`Previsão`) line starts at the last historical point, removing the visual break between real and predicted values.

## [2.16.1] - 2026-04-05

### Changed
- Updated the native `Prev. Vendas` KPI cards to prioritize VAT-inclusive (`C/IVA`) values by showing them first and with stronger visual emphasis.
- Swapped VAT columns in the forecast table so `Previsto C/IVA (€)` is displayed before `Previsto S/IVA (€)` for consistent reading order.

## [2.16.0] - 2026-04-05

### Added
- Added native `sales_forecast` API action in `mobile.php` and a weather-aware forecast engine in `KreaFlowMobileApi` (historical sales, Open-Meteo weather history/forecast, and regression fallback) using entity-safe sales data filters.
- Added typed frontend forecast contracts in `app/src/lib/api.ts` and a dedicated `getSalesForecast()` client request helper.

### Changed
- Replaced the temporary `Prev. Vendas` iframe integration with a fully native KreaFlow forecast tab (date filter, KPI cards, historical-vs-forecast chart, and forecast table) aligned with existing dashboard interaction patterns.
- Updated mobile forecast styling to maintain phone-width safety without horizontal overflow and published the rebuilt frontend bundle in `frontend/`.

## [2.15.0] - 2026-04-05

### Added
- Added a new `Prev. Vendas` tab in KreaFlow Mobile that embeds the weather-aware sales forecast report (`/custom/kreareports/reports/SalesForecast/index.php`) using a mobile-safe responsive container inspired by `example.htm`.

### Changed
- Added `tab=forecast` deep-link support (including `previsao` and `salesforecast` aliases) and an in-tab `Abrir` shortcut to open the report in a separate page.
- Bumped module version to `2.15.0` and published the updated frontend bundle in `frontend/`.

## [2.14.1] - 2026-04-04

### Fixed
- Restored the dashboard `Faturas` indicator in the `Unidades Vendidas` KPI card while keeping the new `Pessoas` indicator visible in the same card.

## [2.14.0] - 2026-04-04

### Added
- Added a dashboard `Hoje` preset and enabled selecting the current day in dashboard date-picker bounds while keeping `Ont.` as the default range.

### Changed
- Updated dashboard ticket-average KPI calculations to divide by synced people totals (`dolizsynch_zsinvoicesynch.pessoas_zs`) instead of invoice count.
- Extended dashboard payloads with `kpis.total_people` and daily-series `people_count` values for aligned frontend aggregation.
- Updated the `Unidades Vendidas` KPI card to display total people for the selected period.
- Inverted dashboard VAT emphasis in KPI cards so `C/IVA` values are primary and `S/IVA` values are secondary.

### Fixed
- Removed dashboard date-range clamping that blocked current-day data by allowing `date_end` up to today (without changing Fecho completed-day behavior).

## [2.13.16] - 2026-03-22

### Changed
- Replaced hardcoded `Sobre` metadata values with dynamic module descriptor values loaded from `modKreaFlow` (`name`, `version`, `editor`, `license`, `support`, `website`).
- Updated mobile session payload to expose `module_info` and render About fields with runtime-safe fallbacks, so displayed version and module details always reflect the current deployed module.

## [2.13.15] - 2026-03-22

### Changed
- Updated the mobile `Sobre` page to include legal/product metadata (`GPLv3 or later. See COPYING.`, module details, support contact, and website) and display the company `logo.png`.

## [2.13.14] - 2026-03-22

### Added
- Added a new `Sobre` menu page in the mobile app with contextual runtime info (user, entity, and environment).

### Changed
- Moved logout action from the top bar to the navigation drawer footer and pinned it at the bottom of the menu panel.

## [2.13.13] - 2026-03-22

### Added
- Added module icon asset wiring for Dolibarr UI (`img/object_kreaflow.png`) by configuring `editor_squarred_logo` and module `picto`, so the KreaFlow logo is rendered in `admin/modules.php`.

## [2.13.12] - 2026-03-22

### Added
- Added compact mini pagination controls in `Top Produtos (por faturação)` to navigate previous/next pages and show the current page index on mobile.

### Changed
- Updated dashboard action handling to accept `top_products_page` and return paginated metadata (`top_products_total`, `top_products_page`, `top_products_page_size`, `top_products_pages`) while keeping 12 rows per page.

## [2.13.11] - 2026-03-22

### Changed
- Removed the redundant internal subtitle from the `Ponto` data card, keeping only the panel-level `Ponto` title.

## [2.13.10] - 2026-03-22

### Changed
- Removed redundant internal section subtitles from workforce data cards in `Horarios Semana` and `Horarios Dia` tabs, keeping only the panel-level titles.

## [2.13.9] - 2026-03-22

### Changed
- Corrected workforce label spelling from `Horaios Semana` to `Horarios Semana` in menu and panel title.
- Removed repeated panel headings by using distinct content-card titles in workforce tabs:
  - `Horarios Semana` -> `Escala da Semana`
  - `Horarios Dia` -> `Escala do Dia`
  - `Ponto` -> `Registos de Ponto`
- Reviewed adjacent tabs for similar heading duplication and normalized repeated top-level/content titles where found.

## [2.13.8] - 2026-03-22

### Changed
- Optimized dashboard `Vendas por Tipo de Pagamento` for small displays with tighter chart sizing, compact table typography, and abbreviated column headers (`Pgto`, `Tx`, `S/IVA`, `C/IVA`, `Part.`).
- Added compact payment-type label rendering with safe abbreviation/truncation for narrow mobile widths.

### Fixed
- Removed the Euro symbol from all numeric values in the payment table (rows and totals) while keeping locale numeric formatting.
- Updated payment share values to one decimal place without `%` symbol, including totals.

## [2.13.7] - 2026-03-22

### Changed
- Hardened PWA update flow to force installed clients onto the latest release by enabling Workbox `skipWaiting`, `clientsClaim`, and outdated-cache cleanup.

### Fixed
- Fixed stale installed-app behavior after deployments by auto-applying pending service-worker updates and forcing client reload on `controllerchange`.
- Added periodic service-worker update checks in the frontend bootstrap so new versions are detected without requiring manual refresh.

## [2.13.6] - 2026-03-22

### Changed
- Reordered mobile navigation tabs so `Produtos` is listed immediately after `Dashboard`.
- Moved workforce entries (`Horaios Semana`, `Horarios Dia`, `Ponto`) to the bottom of the menu list.
- Renamed workforce layout labels and section titles from `Semana por Dia` / `Barras do Dia` to `Horaios Semana` / `Horarios Dia` for consistency with requested naming.

## [2.13.5] - 2026-03-22

### Fixed
- Fixed dashboard single-day previous/next navigation date updates by switching date-only formatting to local `YYYY-MM-DD` (instead of UTC-based `toISOString()` slicing), preventing stale/same-day results in non-UTC timezones.
- Fixed date picker trigger labels not reflecting day-navigation changes in timezone-offset environments.

## [2.13.4] - 2026-03-22

### Added
- Added dashboard single-day quick navigation buttons (`Dia Anterior` / `Próximo Dia`) shown when filter start and end dates are the same.

### Changed
- Aligned dashboard day-navigation behavior with existing history and completed-day limits, including button disabling when previous/next day is outside allowed bounds.

## [2.13.3] - 2026-03-22

### Changed
- Replaced dashboard `30D` preset with `Mês Ant.` (previous calendar month) for clearer period selection.

### Fixed
- Fixed session-expiration handling by detecting authentication-loss responses even when backend replies with non-401 payloads (including HTML/text access-denied messages) and forcing frontend logout flow back to custom login.
- Prevented post-expiration UI freeze by normalizing auth-loss API failures to `401` in the frontend transport layer.

## [2.13.2] - 2026-03-22

### Changed
- Enforced frontend date-picker bounds from KreaFlow history permissions (`history_limit_days` / `history_readall`) across dashboard, workforce, and Fecho filters.
- Updated dashboard presets to disable `Mês` and `30D` when the user history limit is shorter than those preset windows.

### Fixed
- Synced dashboard, workforce, and Fecho filter states from backend effective period payloads so wheel dials always reflect the actual applied range after refresh.
- Fixed out-of-limit date requests by clamping picker selections and filter ranges before API calls.

## [2.13.1] - 2026-03-22

### Fixed
- Fixed frontend alert/message text normalization to properly decode localized HTML entities (for example `Voc&ecirc;` -> `Você`) before rendering status and error messages.
- Improved fallback handling for HTML access-denied responses so user-facing alerts no longer show raw entity-encoded content.

## [2.13.0] - 2026-03-22

### Added
- Added dedicated KreaFlow Fecho permissions `updatevalidated` and `updateold`.
- Added setup parameter `KREAFLOW_FECHO_EDIT_LIMIT_DAYS` (default `3`) in `admin/setup.php`.

### Changed
- Updated Fecho metadata/session payloads to expose validated-edit and old-record-edit capabilities with configured edit limit.
- Updated Fecho edit eligibility rules so `can_edit` reflects update rights, validated status permission, and edit-age limit.

### Fixed
- Fixed dashboard access for users without history-bypass permission by removing dependency on Dolibarr `facture.lire` right and relying on KreaFlow dashboard permission.
- Fixed Fecho update flow to allow validated-status edits only when explicitly authorized, while still blocking unsupported statuses.

## [2.12.0] - 2026-03-22

### Added
- Added dedicated KreaFlow Fecho permissions for `create` and `update` actions.

### Changed
- Updated Fecho meta payload to expose independent `can_create` and `can_update` flags (kept `can_write` for backward compatibility).
- Updated mobile Fecho UI controls so `Novo` depends on create permission and `Editar` depends on update permission.

### Fixed
- Enforced separate create and update access checks server-side for Fecho create/update endpoints.
- Enforced Fecho detail `can_edit` flag to require update permission in addition to draft status.
- Preserved existing permission identifier order for `history.readall` to avoid role-assignment drift during upgrade.

## [2.11.0] - 2026-03-22

### Added
- Added dedicated Dolibarr KreaFlow permissions for each mobile menu area: `Dashboard`, `Products`, `Workforce timetable`, `Workforce punch`, and `Fecho Financeiro`.
- Added a new Dolibarr KreaFlow permission `Read data older than configured history limit`.
- Added setup parameter `KREAFLOW_HISTORY_LIMIT_DAYS` (default `15`) in `admin/setup.php`.

### Changed
- Updated mobile session payload (`whoami`) to expose new per-menu permission flags and history-limit metadata.
- Updated mobile navigation rendering to show only tabs allowed by the current user permissions.
- Updated login/bootstrap data preloads to fetch only datasets allowed by current user permissions.

### Fixed
- Enforced per-menu permission checks server-side for all menu-bound actions (`dashboard`, `products`, `workforce`, `fecho*`).
- Enforced historical date access limit on dated endpoints for users without extended-history permission.
- Prevented dashboard 7-day chart expansion from bypassing history limits.
- Enforced history-limit checks on Fecho detail/update by id to prevent direct access to out-of-window records.

## [2.10.12] - 2026-03-22

### Fixed
- Fixed dashboard date scope to always exclude today in the mobile app by clamping requests to completed days only (`end <= yesterday`).
- Fixed daily sales chart and dashboard aggregates to consistently stop at yesterday, even when today is selected in filters.

## [2.10.11] - 2026-03-22

### Changed
- Updated dashboard default date range to today (`start=end=today`) on app open/login while preserving the `Ontem` preset behavior.

## [2.10.10] - 2026-03-22

### Fixed
- Fixed `Ponto` today totals by computing shift duration from confirmed sequential punch pairs (`IN` followed by next valid `OUT`) per employee.
- Updated day total aggregation to include both direct paired rows and sequence-confirmed pairs from sync-based today punches.

## [2.10.9] - 2026-03-22

### Changed
- Updated `Ponto` filter actions so `Aplicar` and `Hoje` are rendered side by side on a single row with equal-width buttons.

## [2.10.8] - 2026-03-22

### Changed
- Set `Ponto` tab entry behavior to default to today whenever the view is opened.
- Simplified punch rows by removing repeated day labels and rendering only available `IN` / `OUT` values for each record.
- Updated punch row layout to compact pill-style markers for consistent mobile readability.

### Fixed
- Fixed punch totals visibility by showing employee/day total time only when valid paired records (`IN` + `OUT`) exist.

## [2.10.7] - 2026-03-22

### Changed
- Added previous/next day navigation controls in the `Ponto` view so users can browse punch-clock days without reopening the date picker.

### Fixed
- Fixed `Ponto` "today" dataset resolution by reading punches from `dolizsynch_zspunchclock_synch` (same source logic as `zspunchclock_synch_list.php`) when the selected day is current day.
- Kept historical/day-selected punch reads on `dolizsynch_zspunchclock` and excluded deleted synch rows (`synch IN (3,4)`) for the today source.

## [2.10.6] - 2026-03-22

### Changed
- Increased vertical spacing between the entity selector and login submit button on the custom login form.
- Increased spacing above login feedback messages and reduced their visual weight/size for a more compact mobile auth layout.

## [2.10.5] - 2026-03-22

### Fixed
- Fixed authenticated API requests failing with "Access denied" by removing `NOLOGIN` from `mobile.php`. With `NOLOGIN` Dolibarr's `main.inc.php` bypassed session-auth bootstrap on every request, so the user object was always empty even after a successful login. Replaced with `NOREDIRECTBYMAINTOLOGIN` only, which lets `main.inc.php` restore the authenticated user from session while still preventing redirects to the Dolibarr login page for unauthenticated shell visits.
- Fixed `KreaFlowMobileApi::login()` rights-check by adding an explicit `loadRights()` call after `User::fetch()`, ensuring `hasRight()` works correctly for all supported Dolibarr versions before the login guard runs.

## [2.10.4] - 2026-03-22

### Changed
- Removed the `KreaFlow Mobile` title and `Inicie sessão para continuar.` subtitle from the custom login screen, keeping a cleaner logo-first layout.

## [2.10.3] - 2026-03-22

### Added
- Added KreaFlow logo rendering on the custom mobile login screen.

### Fixed
- Fixed custom login access flow for multicompany contexts where the current entity does not have KreaFlow enabled, by allowing public login shell/actions before session auth.
- Fixed login entity validation to check KreaFlow module activation on the selected target entity instead of the pre-login current context.
- Fixed login metadata fallback to avoid returning disabled entities when no enabled-entity list is available.

## [2.10.2] - 2026-03-22

### Fixed
- Fixed unauthenticated `mobile.php` access flow by forcing `NOLOGIN` at bootstrap, preventing fallback redirects to Dolibarr `/user/login.php` and keeping the custom KreaFlow login screen as the default entrypoint.
- Fixed logout UX to always return to the in-app custom login screen even if the backend logout request fails, removing fallback redirect to Dolibarr `/user/logout.php`.

## [2.10.1] - 2026-03-22

### Changed
- Moved workforce statistics cards below the main data sections in `Semana por Dia`, `Barras do Dia`, and `Ponto`, so schedule/punch tables render first.

## [2.10.0] - 2026-03-22

### Added
- Added a dedicated `Barras do Dia` menu tab, fully separated from the weekly timetable layout.
- Added previous/next week navigation controls in `Semana por Dia` to move across weeks without reopening the date picker.

### Changed
- Split workforce schedule navigation into two independent menu entries (`Semana por Dia` and `Barras do Dia`) and removed the in-view layout switcher.
- Changed day-bars navigation to previous/next day flow with automatic week-context sync, removing weekday chip navigation.

## [2.9.4] - 2026-03-22

### Fixed
- Fixed `Horário Diário (Barras)` scale overflow by clamping bar segment geometry to timeline track bounds.
- Fixed timeline mark overflow on day bars by anchoring first/last scale labels inside container limits.

## [2.9.3] - 2026-03-22

### Changed
- Enforced strict day-based employee visibility in schedule views: employees are now shown only when they have effective shift time on the selected day.
- Removed day-only leave/day-off rows without shift segments from day-oriented schedule layouts.

## [2.9.2] - 2026-03-22

### Changed
- Added day navigation controls inside `Horário Diário (Barras)` with previous/next actions and direct weekday selection constrained to the selected week interval.
- Updated day-bars rendering to hide employees that have no shift segments on the selected day.
- Updated day-first weekly cards to hide employees with no shift/day-off/leave data for each day, reducing empty rows.

## [2.9.1] - 2026-03-22

### Changed
- Removed empty weekly timetable placeholders in `Horários` cards by hiding the `Sem turno neste dia` label when no shift exists.
- Removed zero-value weekly planned-hour pill (`Sem. 0h`) from day-first timetable rows, keeping only meaningful state pills.

## [2.9.0] - 2026-03-22

### Added
- Added workforce availability payloads (`dayoffs`, `leaves`, `public_holidays`) to the mobile workforce endpoint, aligned with DolizSynch schedule semantics.
- Added a dedicated punch-clock day selector in the mobile app, allowing users to open and inspect days other than today with the shared wheel date picker.
- Added a schedule layout switcher in `Horários` with two distinct views: `Semana por Dia` and `Barras do Dia`.

### Changed
- Reworked the weekly timetable rendering to be day-first (ordered by day) instead of employee-first.
- Enriched each employee row in workforce views with compact `IN`, `OUT`, and `Total` time indicators plus day-off / leave status chips.
- Updated punch-clock presentation to keep read-only behavior while showing records for the explicitly selected day instead of forcing today.

## [2.8.0] - 2026-03-22

### Added
- Added a custom in-app login page in the mobile frontend with username, password, and entity selection.
- Added public mobile actions `kf_action=login_meta` and `kf_action=login` to support the in-app authentication flow.

### Changed
- Changed app bootstrap behavior to stop redirecting unauthenticated users to Dolibarr login and render the KreaFlow login screen instead.
- Updated frontend unauthorized/session-expired handling to return to the in-app login flow.
- Added `kf_action=logout` support to terminate sessions from inside the app.

### Fixed
- Fixed mobile bootstrap session injection for anonymous access by serving `window.__KREAFLOW_BOOTSTRAP__ = null` when no authenticated user is present.

## [2.7.0] - 2026-03-22

### Added
- Split workforce navigation into two dedicated mobile views: `Horários` (timetables) and `Ponto` (punch clock), instead of a single mixed tab.

### Changed
- Updated Punch Clock behavior to mirror `zspunchclock_synch_list.php` by prioritizing today (`YYYY-MM-DD` current day) as the default date scope.
- Simplified the Horários filter block to week/day controls only, keeping punch operations isolated in the `Ponto` view.

### Fixed
- Fixed timetable data visibility by aligning KreaFlow workforce resource filtering with DolizSynch Shift Planner employee selection logic.
- Removed over-restrictive workforce resource constraints that excluded valid schedule users and caused empty timetable output.

## [2.6.1] - 2026-03-22

### Fixed
- Fixed mobile viewport rescaling when focusing product search (and any other editable field) by enforcing a mobile-safe `16px` font baseline on all inputs, selects, and textareas.
- Added root text-size adjustment guards to keep viewport scale stable during keyboard/input focus interactions on mobile browsers.

### Changed
- Added a permanent engineering rule in `AGENTS.md` to forbid input-focus zoom/rescale behavior and keep layout scale consistent across the full app.

## [2.6.0] - 2026-03-22

### Added
- Added a new read-only `Equipa` mobile tab to display weekly schedules, daily horizontal shift bars, and punch-clock records directly inside KreaFlow.
- Added a new mobile data action `kf_action=workforce` in `mobile.php` to serve consolidated DolizSynch schedule and punch-clock payloads.

### Changed
- Integrated DolizSynch schedule sources (`dolizsynch_shift`, employee groups, and shift tasks) into `KreaFlowMobileApi` with entity-safe filtering and rights checks.
- Integrated DolizSynch punch-clock list source (`dolizsynch_zspunchclock`) into `KreaFlowMobileApi` using the same date-driven daily scope as the DolizSynch list screen.
- Extended mobile date-picker scope to support workforce week/day/punch filters while keeping wheel-based date pickers as default UX.

## [2.5.0] - 2026-03-22

### Changed
- Added two distinct layouts in the mobile products tab: a search-first view and a category-board view based on setup categories.
- Renamed mobile tab and page wording from `Preços de Produtos` to `Produtos` / `Consulta de Produtos` to reflect full product checking scope.
- Filtered entity-switch options to entities where `MAIN_MODULE_KREAFLOW` is enabled, avoiding switches to entities without active app support.

### Fixed
- Fixed Fecho Financeiro list card overflow on mobile by tightening side/action wrapping rules and enforcing in-card overflow containment.

## [2.4.0] - 2026-03-22

### Added
- Added setup controls in `admin/setup.php` for product page category curation (`KREAFLOW_PRODUCTLIST_CATEGORIES`) and optional sellable-only filtering (`KREAFLOW_PRODUCTLIST_FILTER_TOSELL`).

### Changed
- Replaced the products tab category dropdown with a setup-driven category board layout inspired by Degema `product_category_list.php`.
- Updated product page behavior so the default view shows configured category sections and search mode focuses on global product lookup.
- Updated product list and category queries to respect setup constants for category scope and sellable-only filtering.

### Fixed
- Enforced configured category boundaries when a category id is sent in product search requests, preventing out-of-scope category access.
- Completed backend helper wiring for category configuration parsing and sellable filter defaults to avoid undefined-method runtime failures.

## [2.3.12] - 2026-03-22

### Changed
- Updated Fecho Financeiro initial filter defaults to load the last 7 days (ending yesterday) instead of a single day when opening the Fecho page.

## [2.3.11] - 2026-03-22

### Changed
- Increased dashboard `Top Produtos` result cap from 5 to 12 records in both synchronized and fallback sales query paths.

## [2.3.10] - 2026-03-22

### Changed
- Reworked dashboard data extraction to follow the same synchronized sales source used by KreaReports SalesDashboard when `dolizsynch` tables are available.
- Updated top products dashboard query to use synchronized invoice filtering (`doc`, `tipo_zs`, `anulado_zs`, entity) for consistency with payments, employees, and KPI totals.
- Added dashboard chart period metadata (`chart_start`) and served daily series with a minimum 7-day window when the selected range is shorter.

### Fixed
- Fixed payment type breakdown showing only `Sem etiqueta` by resolving labels from `dolizsynch_zspaymenttype` via `pagamento_zs` mapping.
- Fixed employee breakdown missing seller names by resolving employees from synchronized `emp_zs` mapping through `user_extrafields`.
- Fixed hourly sales chart readability by returning only non-zero hour buckets in the API and scaling the frontend bar chart domain to the current dataset.

## [2.3.9] - 2026-03-22

### Fixed
- Fixed top entity bullet clipping by replacing the fixed 18px circle with a compact adaptive pill (`min-width` + horizontal padding + ellipsis) so entity references fit without breaking mobile width constraints.

## [2.3.8] - 2026-03-22

### Changed
- Reorganized Fecho Financeiro mobile form to mirror Degema card section flow with explicit blocks: `Total de Vendas`, `Talão de Fecho`, `Total Recebido Hoje`, and `Resumo de Fecho`.
- Reordered Fecho inputs to follow the same sequence used in `/custom/degema/fechofinanceiro_card.php`.
- Replaced generic Fecho metric cards with inline summary rows aligned with Degema closure summary totals.

### Fixed
- Added explicit financial validation indicators in the Fecho modal for sales and treasury consistency, plus VAT-to-talão consistency feedback.
- Blocked Fecho save when validation checks are not consistent and surfaced exact mismatch values in the error message.
- Fixed `Total sem IVA` read-only behavior so it always reflects the current VAT inputs (computed value) during edit/create.

## [2.3.7] - 2026-03-22

### Changed
- Updated top entity bullet to display a compact entity reference derived from the entity label (fallback to entity id when no reference pattern is available).

## [2.3.6] - 2026-03-22

### Fixed
- Fixed missing `Gorjetas (tips)` input in Fecho Financeiro create/view/edit by adding `tips` to mobile value schemas and form field groups.
- Fixed Fecho tips persistence by adding `tips` to backend Fecho numeric field whitelist used in detail/create/update payload mapping.

## [2.3.5] - 2026-03-22

### Fixed
- Fixed date picker overlay layering so the wheel picker always opens above the Fecho create/edit modal.

### Changed
- Increased wheel picker modal z-index to keep date selection controls visible and interactive in all modal contexts.

## [2.3.4] - 2026-03-22

### Changed
- Updated Fecho Financeiro VAT input labels to include explicit percentage rates (`0%`, `6%`, `13%`, `23%`) in the mobile form.

## [2.3.3] - 2026-03-22

### Changed
- Standardized date input UX across the app to the shared wheel picker used by Dashboard, including Fecho Financeiro create/view/edit flows.
- Removed native browser date input usage from Fecho modal and routed date editing through the shared wheel picker state machine.
- Added a persistent engineering rule in `AGENTS.md` requiring the Dashboard wheel picker as the default date picker everywhere in the app.

## [2.3.2] - 2026-03-22

### Changed
- Removed the `E...` prefix from entity references in mobile UI and Fecho store option labels.
- Updated entity bullet and entity-switch sheet to display plain entity id values without the `E` prefix.

## [2.3.1] - 2026-03-22

### Fixed
- Fixed Fecho Financeiro user dropdown labels not appearing in mobile create/view/edit by aligning responsavel option sourcing with Degema logic and adding robust user-label fallbacks.
- Fixed Fecho Financeiro store dropdown labels by normalizing entity option labels with explicit store reference (`E<id> - <label>`) and fallback resolution for current selections.
- Fixed edit/save validation edge case where valid existing responsavel users could be rejected if not returned by the strict metadata list.

### Changed
- Updated Fecho modal frontend form state to preserve `store_label` and `responsavel_label` from detail payload so view/edit always shows meaningful selected values even when option lists are restricted.

## [2.3.0] - 2026-03-22

### Added
- Added a native in-app Fecho Financeiro bottom-sheet workflow for create, view, and edit with compact grouped financial fields and real-time metric cards.

### Changed
- Replaced Fecho list action navigation to external Degema card pages with an end-to-end mobile-native form flow.
- Added practical mobile form controls for date, store, responsible user, observations, and decimal normalization per field.
- Hardened Fecho modal layout to remain phone-width safe without horizontal overflow across all sections.

## [2.2.0] - 2026-03-22

### Added
- Added Fecho Financeiro user actions in mobile UI: `Novo`, `Ver`, and `Editar`, linked to native Degema card flows (`/custom/degema/fechofinanceiro_card.php`) with return navigation back to KreaFlow.

### Changed
- Added URL tab-state persistence (`?tab=dashboard|fecho|precos`) so users returning from Fecho create/edit/view pages land back on Fecho workflow.
- Updated Fecho list cards with compact action controls optimized for phone width.

## [2.1.0] - 2026-03-22

### Added
- Added `fechofinanceiro` mobile data action in `mobile.php` with a new service method in `KreaFlowMobileApi` to expose a compact list inspired by `/custom/degema/fechofinanceiro_list.php`.
- Added mobile Fecho Financeiro tab with date/status/search filters and compact cards showing reference, store, responsible user, status, and S/IVA + C/IVA totals.

### Changed
- Replaced in-app top tab bar navigation with a hamburger drawer menu for better mobile ergonomics.
- Enforced Fecho listing guards for production use: Dolibarr session rights check (`degema->fechofinanceiro->read`), entity scoping to current entity, and `viewalldates` date-limit restriction using `DEGEMA_FECHOFINANCEIRO_DATE_LIMIT_DAYS`.
- Updated frontend API contracts and README action list to include Fecho Financeiro route and payload.

## [2.0.3] - 2026-03-22

### Changed
- Regenerated all app icon sizes (`favicon.png`, `icon-192.png`, `icon-512.png`, `icon-maskable-512.png`) from `img/kreaflow-icon-source-portrait-a.png`.

### Removed
- Removed `img/kreaflow-icon-source-portrait-b.png` as requested.

## [2.0.2] - 2026-03-22

### Removed
- Removed `img/kreaflow-icon-master.png` from module sources because it is a collection/reference artifact and must not be used as an app icon asset.

## [2.0.1] - 2026-03-22

### Changed
- Renamed generated icon source files in `img/` to stable module names (`kreaflow-icon-source-*`) and created `img/kreaflow-icon-master.png` as canonical app icon source.
- Regenerated frontend app icons from module image source with production sizes (`icon-192.png`, `icon-512.png`, `icon-maskable-512.png`) and a PNG favicon (`favicon.png`).
- Updated PWA/frontend icon configuration to use PNG assets only (manifest + HTML favicon link), removing SVG icon usage from app runtime.

## [2.0.0] - 2026-03-22

### Changed
- Deprecated module `api/` URL surface by converting all legacy endpoint files to hard `404 Not Found` responses.
- Removed API entrypoint exposure from module home page (`kreaflowindex.php`).
- Updated README contracts to document `mobile.php` as the only supported data surface and to mark `/api/*` as disabled.

### Removed
- Removed functional support for direct module API routes under `/custom/kreaflow/api/*`.

## [1.4.2] - 2026-03-22

### Changed
- Removed the module top navigation entry (`mainmenu=kreaflow`) to avoid showing a dedicated KreaFlow top menu icon.
- Repositioned the mobile app menu entry as a left-menu item under Dolibarr Tools (`fk_mainmenu=tools`).

## [1.4.1] - 2026-03-22

### Changed
- Reworked mobile frontend data loading to use direct `mobile.php` action routes (`kf_action`) instead of `/api/*` URL paths.
- Injected Dolibarr session bootstrap directly into the frontend shell (`window.__KREAFLOW_BOOTSTRAP__`) so initial app session no longer depends on API whoami fetch for first render.
- Reworked entity switch navigation to use module-owned `kf_switch_entity` parameter, avoiding Dolibarr core `switchentity` interception.

### Fixed
- Fixed persistent production login failures caused by invalid whoami bootstrap responses on restricted `/api` deployments.
- Fixed entity switch access-denied failures triggered before module logic when using `switchentity` query string.

### Security
- Enforced token validation (`newToken`/`currentToken`) on `kf_switch_entity` to keep direct entity changes protected against cross-site request forgery.

## [1.4.0] - 2026-03-22

### Changed
- Reworked authentication flow to use direct Dolibarr session login instead of the in-app API login form.
- Reworked entity switching to use native MultiCompany `switchentity` navigation through `mobile.php`, removing dependency on API entity-switch calls from the frontend.
- Updated unauthorized-session handling to redirect users straight to Dolibarr login with return URL to KreaFlow mobile entrypoint.

### Fixed
- Fixed persistent "invalid API response" and "access denied" loops caused by frontend dependence on module API login/switch routes in restricted production setups.

## [1.3.3] - 2026-03-22

### Fixed
- Fixed API POST compatibility by explicitly disabling CSRF checks for module API bootstrap and preventing login redirects from `main.inc.php` API contexts.
- Fixed invalid JSON API responses by clearing buffered non-JSON output before emitting API envelopes.
- Fixed entity switching on MultiCompany setups where `switchEntity()` can fail despite valid `checkRight()` by adding a controlled session/config fallback switch flow.

### Changed
- Improved frontend API parser resilience with best-effort JSON extraction and normalized plain-text error messages when gateways/plugins inject HTML wrappers.

## [1.3.2] - 2026-03-22

### Fixed
- Fixed payment pie chart reliability by normalizing `sales_by_payment_type` values to numeric types before rendering.
- Fixed chart rendering edge cases by excluding non-positive totals from the pie dataset and showing a clear fallback message when no plottable data exists.

### Changed
- Updated payment table rendering to use the same normalized payment dataset as the pie chart for consistent totals and percentages.

## [1.3.1] - 2026-03-22

### Changed
- Removed PMP from product detail API payload and mobile product detail sheet to simplify pricing view.
- Added explicit `Preço S/IVA` presentation in product detail cards alongside `Preço C/IVA` and `Custo`.
- Updated README scope text to reflect the pricing fields shown in product details.

## [1.3.0] - 2026-03-22

### Added
- Added product detail endpoint (`GET /api/products/detail.php?id=<product_id>`) with consolidated payload for product pricing, cost, supplier references, nutrition, and allergens.
- Added mobile product detail bottom-sheet UI opened from clickable product cards.
- Added in-app PWA install action using browser install prompt plus iOS add-to-home-screen hint fallback.
- Added PNG app icons (`192x192`, `512x512`, maskable `512x512`) to improve installability across mobile browsers.

### Changed
- Updated API service layer with optional KreaProducts enrichment (nutrition/allergens) while keeping product read access as the main gate.
- Updated product details supplier extraction to use entity-safe joins (`product`, `product_fournisseur_price`, `societe`) and right-gated visibility.
- Updated README and API index documentation with the new product detail route and installable PWA scope.

## [1.2.11] - 2026-03-22

### Added
- Added product categories endpoint (`GET /api/product_categories.php`) to support mobile filtering by category.

### Changed
- Updated product search behavior to be intent-based: numeric-only query searches product reference (`ref`) only, while text or text+number query searches product label only.
- Removed barcode from product search payload and from mobile product rendering.
- Added category-aware filtering to products endpoint (`category_id` parameter) and frontend controls.
- Reworked product results UI into a compact single-row card layout optimized for fast mobile price checking.
- Updated API index and README contracts to include category filtering routes and parameters.

## [1.2.10] - 2026-03-22

### Added
- Added module-owned authentication endpoints for mobile login/logout (`POST /api/auth/login.php`, `POST /api/auth/logout.php`) so the app can run with its own login panel.
- Added a dedicated entity switching endpoint (`POST /api/auth/switch_entity.php`) for authenticated users.
- Added enriched session payload fields (`entity_label`, `can_switch_entity`, `available_entities`) to drive entity-aware frontend behavior.

### Changed
- Updated `mobile.php` to allow loading the frontend shell without an existing Dolibarr session while keeping API routes protected.
- Reworked frontend authentication flow to show an internal login form instead of redirecting users to the Dolibarr login page.
- Made the entity bullet interactive only for users with access to multiple entities and added an entity selector sheet.
- Extended API index endpoint documentation to include the new authentication and entity-switch routes.

### Security
- Enforced MultiCompany rights checks during entity switch and added rollback to the previous entity when the target entity does not grant `kreaflow.mobile.read`.

## [1.2.9] - 2026-03-22

### Changed
- Applied compact single-row mobile rendering to `Vendas por Tipo de Pagamento` to remove repeated per-line labels and keep each payment type on one row.
- Improved product search to token-based matching across reference, label, and barcode so split queries like `01 vp` match products such as `*01 SAND. & BA. 'VP`.
- Added relevance-oriented ordering for tokenized product search (exact/prefix reference and label priority) to return closer matches first.

## [1.2.8] - 2026-03-22

### Changed
- Simplified `Vendas por Empregado` to 3 columns only: employee, quantity sold, and sales with IVA (`Total C/IVA`).
- Converted `Top Produtos` and `Vendas por Empregado` to compact single-row mobile tables to avoid repeated per-line labels and keep each record on one row.

## [1.2.7] - 2026-03-22

### Changed
- Limited dashboard `Top Produtos` dataset to 5 records at API level for stricter mobile focus and faster rendering.

## [1.2.6] - 2026-03-22

### Changed
- Simplified the `Top Produtos` dashboard table to show only product name, quantity sold, and total value for faster mobile scanning.
- Removed reference and net/gross split columns from `Top Produtos`, keeping a single `Valor Total` column.

## [1.2.5] - 2026-03-22

### Changed
- Simplified top header by removing username rendering and keeping only app identification.
- Reworked entity indicator into a tiny discreet bullet at the top to reduce visual prominence.
- Forced preset date filters into a single fixed row of 4 equal-width buttons and abbreviated labels (`Ont.`, `7D`, `Mês`, `30D`) for consistent mobile fit.

## [1.2.4] - 2026-03-22

### Added
- Added an iOS-style rolling wheel date picker for dashboard filters using a mobile-first bottom-sheet interaction.

### Changed
- Replaced native browser date inputs in the dashboard filter with touch-optimized dial selectors (day, month, year) for better phone usability.
- Added automatic date-range coherence in picker apply flow by aligning start/end boundaries when needed.

### Fixed
- Fixed poor mobile date input ergonomics that made period selection difficult on phone devices.

## [1.2.3] - 2026-03-22

### Added
- Added `AGENTS.md` project memory with a mandatory mobile width policy that forbids horizontal overflow on phone screens.

### Changed
- Reworked mobile table rendering to stack rows under phone breakpoints using per-cell labels, removing desktop-width assumptions for dense datasets.
- Hardened layout behavior with root/container overflow guards and mobile wrapping rules for topbar, filters, charts, and search controls.

### Fixed
- Removed application-level horizontal overflow causes (`min-width` table constraint and non-wrapping content patterns) so screens no longer exceed phone viewport width.

## [1.2.2] - 2026-03-22

### Fixed
- Added module root `index.php` redirect to `mobile.php` so `/custom/kreaflow` no longer exposes directory listing on web servers with directory indexing enabled.

## [1.2.1] - 2026-03-22

### Fixed
- Fixed Module Builder export/load compatibility by enforcing lowercase module directory naming (`custom/kreaflow`) instead of mixed-case folder naming.

### Changed
- Added packaging guidance to keep module path lowercase so Module Builder lookups based on `strtolower(module)` resolve correctly.

## [1.2.0] - 2026-03-22

### Added
- Added a sales dashboard API payload with date-range filtering and structured datasets: KPIs, daily sales, hourly sales, sales by payment type, top products, and sales by employee.
- Added a mobile dashboard frontend in Portuguese with KPI cards, line/bar/pie charts, and analytical tables inspired by the SalesDashboard layout.
- Added dashboard period presets in the mobile UI (Ontem, Últimos 7 dias, Este mês, Últimos 30 dias).
- Added Portuguese (`pt_PT`) language file entries for module labels and mobile page messages.

### Changed
- Refocused the module scope to mobile product price checking and sales analysis.
- Reworked `api/dashboard.php` to return analytical sales data instead of simple counters.
- Updated API index and README documentation to the new dashboard + product pricing scope.
- Simplified module permissions to a single mobile read permission.

### Deprecated
- Marked customer search endpoint as out of current scope (`410`) for this release.

### Removed
- Deactivated draft order creation flow for the current release (`api/orders/create_draft.php` now returns `503`).

### Security
- Preserved multicompany isolation on all dashboard queries with explicit `getEntity(...)` filters.
- Preserved rights checks for invoice and product reads before exposing dashboard or pricing data.

## [1.1.0] - 2026-03-22

### Added
- Added a mobile API layer under `api/` with JSON endpoints for session introspection, dashboard counters, customer search, product search, and draft order creation.
- Added `class/KreaFlowMobileApi.class.php` to centralize permission checks, response handling, multicompany-safe queries, and order creation orchestration.
- Added a React + Vite + TypeScript PWA frontend scaffold under `app/`.
- Added `mobile.php` as Dolibarr-controlled entrypoint for the frontend build in `frontend/`.
- Added build automation script `build/sync-frontend.sh` to publish `app/` build output into `frontend/`.

### Changed
- Updated module descriptor to version `1.1.0` and introduced dedicated module rights for mobile read and write workflows.
- Updated module menu permissions to restrict access to internal users with KreaFlow mobile read permission.
- Updated module home page to link directly to mobile app and API index.
- Updated documentation to describe architecture, API contracts, and build flow.

### Security
- Enforced module enablement, authenticated internal user checks, and Dolibarr rights checks on mobile API and frontend entrypoint.
- Enforced multicompany entity scoping in API queries using `getEntity(...)`.
- Wrapped draft order creation in database transactions with rollback on any line-write failure.

## [1.0.0] - 2026-03-22

### Added
- Initial module scaffold generated for Dolibarr integration.

# KreaFlow design system

Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>

License: GPL-3.0-or-later

## Scope and architecture

Version 3.2.0 implements the approved neutral KreaFlow identity in the existing React app and adds conservative presentation styles to module-owned native Dolibarr pages. Routes, permission filtering, company context, APIs, business calculations, reporting and native write operations are unchanged.

- `css/design-tokens.css` owns colors, system typography, surfaces, geometry, spacing and chart colors. Legacy variable names are aliases to these tokens.
- `app/src/styles.css` retains existing component layouts, with every selector scoped to `kf-app`. `app/src/design-system.css` supplies shared visual components and responsive rules. `main.tsx` marks the standalone app document.
- `app/src/Navigation.tsx` renders the existing permission-filtered tab list. Desktop navigation collapses at widths of 1200px and above; smaller screens use a drawer with Escape, trapped keyboard focus, focus restoration and background scroll locking. No new menu destinations or permissions are introduced.
- `ActionsKreaFlow::llxHeader` appends the token/native stylesheets and body markers only when `realpath(SCRIPT_FILENAME)` belongs to this module. URL parameters and referrers cannot select this scope. Native header rendering, menus and form lifecycle remain in Dolibarr. The existing `all` hook registration is reused; no reactivation is required. The reference hook contract is documented in [Dolibarr's main.inc.php](https://doxygen.dolibarr.org/dolibarr_dev/dev/build/html/d0/db1/main_8inc_8php_source.html).
- `css/design-system-native.css` contains only `kf-native` selectors. Unrelated module and core pages do not receive the marker or stylesheet through this hook.

## Visual behavior

The page background is neutral `#F7F8FA`. Cards and form surfaces are white. Ordinary secondary actions are gray; selected controls have a restrained orange tint. The supplied transparent horizontal logo is reused on the app shell, sign-in and About screens; compact navigation uses the existing symbol asset.

The exact requested bright gradient is retained as `--kf-brand-gradient` for decorative use. Action buttons use darker orange stops (`#B84A00`, `#C13F00`, `#C93400`), each exceeding 5.2:1 against white, because the bright gradient cannot meet ordinary text contrast with white labels. Body labels use the darker brand-ink token where appropriate. No web font or frontend dependency was added.

Dashboard comparison details remain in an accessible native disclosure. Its compact summary retains the original HT-only variation calculation. A neutral badge avoids implying that every financial increase is beneficial. UberEats estimated profit is also neutral, including negative and unknown values. Existing semantic stock, warning and kitchen states retain their meanings; source-owned workforce colors are not reinterpreted.

Charts retain datasets, units, date ranges and tooltip formatters. Linear segments replace smoothing and entrance animations are disabled so filter/layout changes immediately display complete data. The actual/forecast boundary and distinct chart palette remain intact. Small dashboard tables reflow into labelled rows instead of truncating monetary values. All existing wheel date pickers remain in place.

## Build and validation

Run the standard TypeScript/Vite build into a private staging directory:

```sh
npm --prefix app run build -- --outDir ../artifacts/ui-redesign/frontend --emptyOutDir
```

Copy the staged frontend files and the parent `sw.js`/Workbox file to their existing runtime locations after validation. Retain previous hashed assets for updating PWA clients. Do not use an empty-output build directly against the release frontend directory if old bundles must remain available.

Browser acceptance is in `tests/ui_redesign.mjs`. It requires Playwright supplied by the development environment; `PLAYWRIGHT_MODULE` may specify its installed module path. The test serves no real account or financial data and intercepts API calls with synthetic responses. `KREAFLOW_REVIEW_URL` selects the local preview origin (default `http://127.0.0.1:4178`). Serve the module at `/custom/kreaflow/`, with the staged build mounted at its `frontend/` path. The preview used a temporary `python:3.13-slim` container bound to loopback.

Validation performed:

- TypeScript and production Vite/PWA build.
- Dashboard at 1622, 1280, 768, 390 and 320 pixels: amounts, historical details, date presets, wheel picker, sidebar/drawer behavior, keyboard focus, rendered chart series, and no clipped payment values or horizontal overflow.
- All ten remaining app tabs at 1280, 768 and 320 pixels with representative synthetic responses; negative-profit presentation and sign-in input sizing included.
- Permission-denied navigation and absence of styles affecting an unrelated document, even when the stylesheet files are loaded.
- Six PHP header-scope checks, existing dashboard-history and comparison-period regression suites, and PHP 8.1 lint of the modified native hook/descriptors.
- White-label action-stop contrast checks; representative desktop and phone screenshots inspected under the ignored `artifacts/ui-redesign/` directory.

The existing bundle-size and Browserslist-age warnings remain. There is no schema or business-data migration. Production deployment, authenticated native-page appearance, physical mobile devices and installed-PWA refresh are not verified by these local tests.

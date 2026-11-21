# KreaFlow 3.1.0 — single-folder installation

Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>

Only `custom/kreaflow` is required after this migration. The earlier instruction to retain `custom/dolizsynch` applies to 3.0.x and is superseded.

## Preserved contracts

The visible module remains ID 156561. The hidden synchronization provider retains ID 156558, `modDoliZSynch`, `MAIN_MODULE_DOLIZSYNCH`, permission IDs, table names, data directories and settings. Both descriptors now reside in `kreaflow/core/modules`. The hidden provider implementation remains here. Do not rename stored technical identifiers or the separate Dolibarr documents/dolizsynch data directory.

KreaFlow owns native hook, trigger, model and API filesystem discovery. `ActionsKreaFlow` delegates synchronization hooks only while the original synchronization module is enabled. The original trigger retains the same activation guard. Existing mobile-only companies remain mobile-only. No module reactivation is required.

## Upgrade from 3.0.x

1. Back up both module folders, dependent runtime files and native registration constants. Compare production with the intended source baseline. Wait for active synchronization jobs to finish.
2. Update KreaProducts to 4.22.10, KreaEats to 1.19.2 and DeGema to 2.8.2 (or newer releases containing their KreaFlow include/translation changes). Inspect other installed custom integrations for old filesystem references.
3. Stage the KreaFlow runtime. During the coordinated cutover, move the old synchronization descriptor out of discovery before placing `kreaflow/core/modules/modDoliZSynch.class.php`; never leave both discoverable.
4. Run `php scripts/migrate_kreaflow_registrations.php` for a rolled-back rehearsal, then `--apply --backup-dir=<private-existing-directory>`. This transfers only HOOKS, TRIGGERS, MODELS and API module-part registrations, preserving row IDs and company scopes. Conflicting target registrations fail closed. A repeat run must report zero changes.
5. Merge `compatibility/legacy-routes.htaccess` into `custom/.htaccess` to redirect old browser URLs with HTTP 308. The native `beforeApiCall` hook redirects `/api/index.php/dolizsynch/...` to `/api/index.php/kreaflow/...` with HTTP 307; clients must follow method-preserving redirects or use the canonical endpoint directly.
6. Move the complete old folder into a protected rollback location. It must no longer exist at `custom/dolizsynch`. Verify native descriptor discovery, hook loading, trigger/model paths, API redirects and stable permissions/settings/jobs. Never delete the documents data namespace.
7. Retain older hashed frontend assets for installed clients. Deploy the root `sw.js` and its root Workbox dependency along with frontend assets and manifest. The existing manifest identity is retained, but its scope now includes `mobile.php`, which is the installed launch URL. Authenticated PHP/API responses are not precached.

## Browser installation

Chrome can expose its native install option when browser eligibility and user engagement requirements are satisfied. A native prompt is also available on the login screen and application toolbar when Chrome provides it. Safari on Mac uses Share > Add to Dock; iPhone/iPad uses Share > Add to Home Screen. These browser controls cannot be forced by the website. KreaFlow provides its standalone manifest, branded icons and Apple metadata.

## Rollback

Restore registration rows from the private snapshot, then the prior runtime files, sibling integrations and retired folder as one coordinated operation. Remove the new canonical synchronization descriptor before restoring the old discoverable descriptor. Restore the previous custom/.htaccess if necessary. Do not reactivate modules or reconstruct business data.

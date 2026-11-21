# KreaFlow for Dolibarr ERP/CRM

![KreaFlow](branding/kreaflow/horizontal_logo.png)

Automate. Connect. Grow.

KreaFlow 3.x consolidates the existing mobile/PWA application and legacy DoliZSynch synchronization module. The canonical folder is `custom/kreaflow`, the visible module is `modKreaFlow`, and the main entry remains `custom/kreaflow/mobile.php`.

The module includes sales and stock forecasts, kitchen monitoring, workforce workflows, financial closes, UberEats analysis, ZoneSoft synchronization, inter-entity conversion and product pricing. Existing database ownership, native financial permissions and lifecycle rules remain authoritative.

## Public source

This repository has a clean source history. Private deployment records, credentials, historical packages and installation-specific mappings are excluded. See [publication and configuration](PUBLICATION.md) and [security reporting](SECURITY.md).

## Compatibility and installation

Requires Dolibarr 19+ and PHP 7.1+. Install the canonical folder; existing two-folder installations must follow the registration migration before removing the old folder. Existing installations must not reactivate modules or reset permissions for this code-only merge. See [migration instructions](compatibility/dolizsynch/README.md). Configure your store/entity mappings, kitchen station IDs, stock-forecast allowlist and weather location explicitly; this public source includes no customer deployment defaults.

The hidden synchronization provider preserves module ID 156558 and the legacy `dolizsynch` activation/permission namespace. The visible module retains ID 156561 and the established `kreaflow` mobile namespace. Existing company activation scopes, user/group grants, scheduled jobs and stored settings are retained. No database table is renamed.

## Development

The application source is in `app/`, the compiled PWA is in `frontend/`, mobile regression suites are in `tests/`, and synchronization regression suites are in `test/`. Both implementations are maintained in this repository. Preserve existing hashed frontend assets for installed service workers.

Brand originals and the identity guide are in [branding/kreaflow](branding/kreaflow/README.md).

## License and support

GPL-3.0-or-later; see `COPYING` and `LICENSE`.

Kreativität Works <mail@kreativitat.com>

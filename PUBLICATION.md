# Public source and configuration

Publication boundary: `sanitized-history-v1`.

This repository begins with a parentless, reviewed source snapshot. It does not carry private Git histories, deployment logs, packaged releases, customer tax/store maps, employee records or production financial captures. Keep those materials outside source control. Existing credentials must be revoked or rotated at their issuing service if their exposure is suspected; removing source text does not revoke access.

## Installation configuration

Existing saved settings remain authoritative. This public copy intentionally provides no customer-specific fallbacks:

- Add store/entity/tax mappings through the native setup before synchronization. No automatic customer mapping is seeded.
- Select permitted entities for `KREAFLOW_STOCK_FORECAST_ENTITIES` in mobile setup. Unconfigured stock forecasts are denied.
- Select kitchen station IDs per entity and keep the source binding/token in a private file outside the web root. See `docs/kitchen-integration.md`.
- Set entity-scoped `KREAFLOW_WEATHER_LATITUDE` and `KREAFLOW_WEATHER_LONGITUDE` to valid decimal coordinates; optionally set `KREAFLOW_WEATHER_LOCATION` to a display label. Missing or invalid coordinates disable weather requests while preserving sales-only forecasting and explicit unavailable analysis context.

No production deployment, schema migration, credential rotation or module reactivation is performed by this publication.

## Safe maintenance

Use the existing public main as the only ancestor of future public changes. Review and copy individual sanitized changes from private work; never merge or push private histories, tags, archives or recovery bundles. Keep reports private and do not attach scan findings or business captures to public issues/actions artifacts.

Enable the local fail-closed hook with `git config core.hooksPath .githooks` after installing Gitleaks. The hook and CI inspect historical paths/content as well as the current tree. Before every push, run `python3 tools/check_publication.py` and `gitleaks git --log-opts="--all --full-history" --redact --ignore-gitleaks-allow --max-archive-depth=2`. GitHub's required publication/secret checks supplement secret scanning and push protection. Scanners reduce risk and do not certify the absence of every possible secret or application vulnerability.

## Frontend toolchain

Use Node.js 22 or newer and `npm --prefix app ci`. Vite 6 and the matching PWA plugin include security fixes. The scoped `workbox-build` Glob override selects a patched compatible Glob release; remove it only after upstream Workbox resolves that dependency and the full audit/PWA build pass. Do not use `npm audit fix --force` as an unattended upgrade strategy.

Dependency automation is limited to security updates. Version-update PR limits are zero; security updates retain their separate GitHub limit. See [GitHub security-update configuration](https://docs.github.com/en/code-security/how-tos/secure-your-supply-chain/manage-your-dependency-security/customizing-dependabot-security-prs).

The public PWA entry bundle, root service worker and Workbox dependency are rebuilt from the patched lockfile. Obsolete generated bundles are excluded from this fresh publication. For an existing installed application, retain its previous hashed assets locally during a separately authorized deployment so updating clients can finish loading.

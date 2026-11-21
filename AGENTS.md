# KreaFlow maintainer guidance

KreaFlow is a Dolibarr module combining a React/Vite PWA with native PHP synchronization and workforce features. Preserve GPL-3.0-or-later and third-party notices.

- `app/`: TypeScript source and npm lockfile. `frontend/`, root service worker and Workbox dependency: compiled PWA runtime. Preserve old hashed assets when updating installed clients.
- `class/`, `class/trait/`, `api/`, `admin/`: server-side module; `sql/`: idempotent installation/upgrade schemas.
- `test/` and `tests/`: synchronization and mobile regression suites. Native integration requires a disposable Dolibarr environment; never run fixtures against production.
- Validation: `npm --prefix app ci`, `npm --prefix app run build`, `python3 tools/check_publication.py`, Gitleaks history scan, and targeted PHP scripts. Integration usage is in `docs/invoice-sync-recovery.md`.
- Require native permissions and active-company ownership before reads, writes or network access. Preserve exact monetary plans, native invoice/payment lifecycle, advisory locks and retry evidence.
- Configure store mappings, stock-forecast allowlist, kitchen station IDs and weather coordinates explicitly. See `PUBLICATION.md`; no customer deployment defaults are distributed.
- Preserve responsive layouts at 320px, input fonts of at least 16px on phones, accessible focus and existing date-picker behavior. Design reference: `docs/design-system.md`.
- Keep credentials, private configuration, production records, audit captures, logs and release ZIPs outside Git. Use synthetic fixtures and example.invalid destinations.
- Work on main and retain publication/secret checks. Never import private history. Deployment requires its own authorization and backup/readback verification; pushing source is not deployment.

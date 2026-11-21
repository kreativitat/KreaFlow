# Repository and module layout

KreaFlow combines the mobile/PWA application and the former DoliZSynch synchronization implementation in `custom/kreaflow`.

The public repository starts from a sanitized source snapshot. Private development histories, deployment records and historical binary packages are not included. Never merge an older private history into this repository.

Maintain and deploy only `custom/kreaflow`. Follow the [single-folder migration](../compatibility/dolizsynch/README.md) before retiring an existing `custom/dolizsynch` folder. Native IDs, activation flags, permission namespaces, database tables and existing business records retain their established identities.

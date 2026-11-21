# KreaFlow kitchen integration

KreaFlow 2.27.0 reads KreaSynch server 0.8.0 through a dedicated read-only bridge. The existing Windows collector remains compatible; this release does not redeploy it. No financial records, stock, invoice triggers, POS schema or synchronization queues are written by this dashboard.

## Source coverage

The feed contains retained observations, not a guaranteed complete active queue. A heartbeat proves receipt, not completeness. Verify source lifecycle codes and timestamp semantics before enabling durations; missing evidence remains unknown.

## Private connection

Use a separate cryptographically random URL-safe token of at least 32 characters per entity/source binding. Generate and store it outside source control; do not reuse Windows agent or browser credentials.

Mount a private JSON file into the Dolibarr runtime and set `KREAFLOW_KITCHEN_CONFIG_FILE` to its absolute path, either as an environment variable or as a native Dolibarr entity-scoped constant. The file must remain outside the web root. Exact entity-ID keys select the configuration; there is no default-company fallback:

```json
{
  "ENTITY_ID": {
    "enabled": true,
    "url": "https://kitchen.example.invalid",
    "source_id": "SOURCE_UUID",
    "token": "PRIVATE_RANDOM_URL_SAFE_TOKEN"
  }
}
```

Mount a separate JSON file into the KreaSynch API container and set `KREAFLOW_KITCHEN_BINDINGS_FILE` to that file's absolute path:

```json
{
  "ENTITY_ID": {
    "enabled": true,
    "source_id": "SOURCE_UUID",
    "token_sha256": "SHA256_OF_THE_PRIVATE_TOKEN",
    "profile": {
      "timezone": "Europe/Lisbon",
      "verified_reference": "",
      "statuses": {}
    }
  }
}
```

An optional Compose override can mount this file read-only without changing existing database mounts:

```yaml
services:
  api:
    environment:
      KREAFLOW_KITCHEN_BINDINGS_FILE: /run/secrets/kreaflow-kitchen.json
    volumes:
      - ./private/kreaflow-kitchen.json:/run/secrets/kreaflow-kitchen.json:ro
```

Restrict the receiver gateway to the intended ERP origins and read-only kitchen route. Require a separate source-bound token and keep administration routes private. The client forbids HTTP, redirects and credential-bearing URLs, and never exposes the token to JavaScript. Integration tokens cannot upload snapshots, update kitchen rows, or administer users. Disable a binding or rotate its token to revoke it.

## Verify the source contract before enabling timings

1. Capture an actual POS/KDS line before preparation, at preparation start, at ready and at completion. Establish row-ID stability and whether deleted/closed orders remain available.
2. Confirm each source status code and the actual timezone. In `profile.statuses`, map only confirmed codes to `waiting`, `preparing`, `ready`, `completed` or `cancelled`. Leave other codes unmapped.
3. Record the evidence reference in `profile.verified_reference`. A nonempty reference enables the explicitly supplied mapping; it is an operator assertion, not automatic validation.
4. If source evidence proves that separate captured timestamps represent preparation start and finish, set `preparation_start` and `preparation_end` to the verified field names. The current allowlist contains only `last_centro_date` and `lastupdate`; these names are not a recommendation to map them. A verified start alone enables elapsed preparation. A duration needs two distinct verified fields and a ready/completed state. If the actual events live in other columns/tables, extend the collector's schema contract and tests before enabling durations.
5. Verify index suitability and the Windows worker against the actual POS. Resolve complete-active-set/deletion/event-history gaps before describing this as a complete operational KDS.

Negative, future, missing, ambiguous DST and over-48-hour durations are excluded. Zero-second measured durations remain valid. Averages and nearest-rank p90 are per observed line, not quantity-weighted or complete-order timing. There is no inferred SLA threshold. Active counts and elapsed timers become unavailable after three minutes of source staleness. Product tables and timing samples remain labelled as retained observations.

## Dolibarr permissions and rollout

Refresh the module's permission definitions through the native module upgrade process while preserving existing assignments. Assign `kreaflow.kitchen.read` only to intended kitchen readers; `kreaflow.mobile.read` and `kreaflow.dashboard.read` are also required. External users are rejected. Existing history limits are sent to the receiver before projection; older source rows and start timestamps are withheld.

Deploy the server before KreaFlow, then its PHP and rebuilt frontend together, retaining prior hashed PWA assets for updating clients. No database schema migration is added. Back up existing runtime and private configuration before deployment.

Select station IDs explicitly in the active company's kitchen setup. No company-specific station defaults are distributed.

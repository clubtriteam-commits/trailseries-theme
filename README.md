# trailseries.bg rebuild

WordPress rebuild of [trailseries.bg](https://trailseries.bg) — Bulgarian trail
running series, 13 seasons, ~150 results pages. Hosted on Superhosting cPanel
(PHP 8.3); staging at `stg.trailseries.bg`.

The repo root mirrors the WordPress root. Only our own code is versioned —
core, config and third-party plugins are gitignored.

```
wp-content/
  plugins/trailseries-results/   # data + logic: schema, CPT, persistence, renderer, WP-CLI
  themes/trailseries/            # presentation only
docs/
  decisions/                     # ADRs — read these first
migration/
  data/                          # raw exports & canonical JSON (gitignored — personal data)
```

## Iron rules

1. **One results layout.** Every table is
   `Place | First name | Last name | Team | Age | Bib# | Finish Time | Status`,
   with splits only as trailing columns. Enforced in code by
   `TSR_Schema` / `TSR_Result_Set` / `TSR_Renderer`, not by convention
   ([ADR-001](docs/decisions/ADR-001-results-as-cpt.md),
   [ADR-002](docs/decisions/ADR-002-plugin-separate-from-theme.md)).
2. **Names are sacred.** Runner names are stored byte-for-byte, never trimmed,
   sanitized or re-encoded. `wp tsr import` byte-verifies names after every
   save; `wp tsr verify-names` re-checks any time via a stored SHA-256.
3. **Old URLs keep working.** Byte-for-byte, verified by an automated crawl
   diff before cutover ([ADR-003](docs/decisions/ADR-003-url-preservation.md)).

## Results data flow

Results enter only through the validated pipeline:

```
source export → canonical JSON → wp tsr import <post_id> <file>
                                   ├─ schema validation (throws on any deviation)
                                   ├─ save + round-trip check
                                   └─ byte-verification of all names
```

Canonical JSON shape (`TSR_Result_Set::to_array()`):

```json
{
  "schema_version": 1,
  "split_labels": ["CP1 Aleko", "CP2 Cherni Vrah"],
  "rows": [
    {
      "place": 1,
      "first_name": "Иван",
      "last_name": "Иванов",
      "team": "Trail Team Sofia",
      "age": 34,
      "bib": "101",
      "finish_time": "3:12:45",
      "status": "FIN",
      "splits": ["1:05:12", "2:10:33"]
    }
  ]
}
```

Status codes: `FIN`, `DNF`, `DNS`, `DSQ`, `OTL` (closed enum — `TSR_Status`).

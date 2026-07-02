# Migration workspace

Working area for migrating 13 seasons / ~150 results pages from the old site.

```
migration/
  data/                  # GITIGNORED — raw exports and canonical JSON (contains personal data)
  url-inventory.csv      # committed — complete legacy URL list (see ADR-003), when built
```

## data/ conventions

- Raw exports from the old site go in `data/raw/` (HTML dumps, CSVs, DB
  exports) — keep them pristine; they are the byte-verification source of
  truth for runner names.
- Canonical JSON files (the `TSR_Result_Set::to_array()` shape — see root
  README) go in `data/canonical/`, one file per results page, named
  `<season>-<race>-<distance>.json`, e.g. `2023-vitosha-run-30k.json`.
- Import with `wp tsr import <post_id> data/canonical/<file>.json` — the
  command validates the schema and byte-verifies every runner name.

Nothing in `data/` is ever committed: results contain names, ages and team
affiliations of real people. Back it up outside git.

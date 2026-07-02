# ADR-001: Results stored as a Custom Post Type with validated JSON meta

- Status: Accepted
- Date: 2026-07-02

## Context

trailseries.bg carries ~150 results pages across 13 seasons. On the old site
these are free-form content, which allowed every page to drift: different
column sets, different orders, hand-edited HTML tables. The rebuild has one
hard requirement: **every results table has exactly the columns
Place | First name | Last name | Team | Age | Bib# | Finish Time | Status, in
that order, with optional splits only as trailing columns** — and this must be
enforced structurally, not by editorial discipline.

Options considered for storage:

1. **Plain pages/posts with HTML tables** — status quo; no enforcement at all.
2. **Custom database table** — strongest typing, but loses everything WordPress
   gives posts for free (permalinks and rewrite control, REST, revisions,
   trash, author/date metadata, taxonomy queries), and URL preservation
   (ADR-003) is much easier when each results page *is* a WordPress permalink.
   ~150 sets × a few hundred rows is far below the scale where wp_postmeta
   becomes a problem, since row data is one meta value per post, not one meta
   row per runner.
3. **CPT with one structured, schema-validated JSON document per post** — each
   results page is a `ts_result` post; the full table lives in a single
   `_tsr_result_set` meta value validated on every read and write.

## Decision

Option 3. Specifically:

- CPT `ts_result`, one post per published results table (one race distance ×
  one edition). Taxonomies `ts_race` and `ts_season` classify it.
- The table data is a single JSON document in post meta `_tsr_result_set`,
  shaped exactly as `TSR_Result_Set::to_array()`:
  `{ schema_version, split_labels: [...], rows: [...] }`.
- The schema is code, not convention: `TSR_Schema::CORE_COLUMNS` fixes the
  column set and order; `TSR_Result_Row` is an immutable object whose
  constructor rejects invalid rows (unknown keys, bad status codes, malformed
  times, finished runners without a time or place); `TSR_Result_Set` rejects
  rows whose split count doesn't match the set's split columns, so a ragged
  table cannot be constructed.
- Validation runs on **load as well as save** (`TSR_Repository`), so data that
  drifts out of schema (bad import, manual DB edit) fails loudly instead of
  rendering wrong.
- `schema_version` is stored with the data; future shape changes require an
  explicit migration, not silent tolerance.

## Consequences

- Positive: uniform tables are guaranteed by construction; free permalinks,
  REST, revisions and taxonomy archives; migration writes through one audited
  code path (`wp tsr import`) that byte-verifies runner names.
- Negative: rows are not individually queryable in SQL (e.g. "all results for
  runner X across seasons" needs a PHP scan or a derived index). Accepted for
  now — at ~150 sets a full scan is cheap; a derived lookup table can be added
  later without changing the canonical storage.
- Negative: editing results via wp-admin needs a purpose-built editor or
  CLI re-import, since the JSON blob is not human-friendly. Deliberate: it
  keeps the validated write path the only write path.

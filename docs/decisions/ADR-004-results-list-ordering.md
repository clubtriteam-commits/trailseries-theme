# ADR-004: Results listings order by distance (desc), then gender (M before F)

- Status: Accepted
- Date: 2026-08-24

## Context

Any template that lists several `ts_result` posts side by side — so far the
homepage "Последни резултати" teaser, and in spirit anywhere else the same
pattern shows up later (event-history editions, a future category index) —
needs a consistent order. The initial homepage implementation used
`orderby => 'title'`, which sorts alphabetically by post_title and has no
relationship to distance or gender; the actual desired order came from
explicit user direction: longest distance to shortest, and within a
distance, men before women, because most of the field in this series is
men.

## Decision

**Longest distance first, then men before women within a distance**, for
every listing of multiple `ts_result` posts. Implemented once as
`tsr_sort_results_by_distance_gender( array $posts ): array` in
`wp-content/plugins/trailseries-results/includes/event-heuristics.php` —
the shared, WordPress-light heuristics file already used for year/gender/
event-name parsing from templates, the CLI and (eventually) tests — not
copied inline per template.

Sort key precedence:

1. `_tsr_distance_km` postmeta (set by `wp tsr backfill-meta`), descending.
2. `tsr_race_gender()` (same file): `'M'` before `'F'` before undetermined
   `''`.

Ties (equal distance, equal/undetermined gender) keep their incoming
relative order — `usort()` is stable in PHP 8, so this never shuffles two
otherwise-equal rows against each other.

Currently applied:

- `front-page.php`'s "Последни резултати" section (every category from the
  most recent past calendar event).
- `single-ts_result.php`'s hub accordion (all sections of a legacy page
  that absorbed multiple categories).
- `page-rezultati.php`'s per-event category pills — including the hub
  category itself, which used to render only as the plain event-name link
  with no pill/label of its own (one category per event was invisible).
- `page-event.php`'s "Издания" (editions) list, per year, via a second
  function `tsr_sort_by_distance_gender_scalars( array $items ): array`
  (same file) — this template reduces posts to scalar `{dist, url, km,
  gender}` arrays before the ordering point (the array is transient-cached
  and deliberately never holds `WP_Post` objects), so it sorts on the `km`/
  `gender` scalars directly instead of calling the `WP_Post`-based
  function.

## Consequences

- Positive: one rule, two small entry points (`WP_Post`-based and
  scalar-based) — a future template that lists results side by side reuses
  one of these instead of reinventing (or silently diverging from) the
  ordering.
- Negative: depends on `_tsr_distance_km` being populated
  (`wp tsr backfill-meta`) for a correct distance sort; a post missing that
  meta reads `0.0` and sorts as if it were the shortest distance rather than
  being flagged as unknown. Acceptable — every currently-published post has
  it, and a future gap would misplace one row, not crash.

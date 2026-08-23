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

Currently applied: `front-page.php`'s "Последни резултати" section (the
list of every category from the most recent past calendar event).

## Consequences

- Positive: one function, one rule — a future template that lists results
  side by side reuses `tsr_sort_results_by_distance_gender()` instead of
  reinventing (or silently diverging from) the ordering.
- Negative: depends on `_tsr_distance_km` being populated
  (`wp tsr backfill-meta`) for a correct distance sort; a post missing that
  meta reads `0.0` and sorts as if it were the shortest distance rather than
  being flagged as unknown. Acceptable — every currently-published post has
  it, and a future gap would misplace one row, not crash.
- Not yet retrofitted: `page-event.php`'s "Издания" (editions) list, which
  currently builds `{dist, url}` pairs (not full `WP_Post` objects) while
  looping for course records in the same pass — applying the shared
  function there means restructuring that loop to carry `WP_Post` through,
  not a drop-in call. Left for whoever next touches that template, per this
  ADR.

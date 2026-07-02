# ADR-002: Results logic lives in a plugin, separate from the theme

- Status: Accepted
- Date: 2026-07-02

## Context

The rebuild ships a new custom theme. The results data — 13 seasons, ~150
tables, runner names that must never change — will outlive any particular
design. WordPress convention is clear (functionality in plugins, presentation
in themes), but there is also a project-specific reason: the unified-columns
requirement must hold **regardless of which theme renders the page**, and a
theme-owned table builder is exactly the place where "just this once" column
tweaks creep in.

## Decision

All results functionality lives in the `trailseries-results` plugin:

- CPT/taxonomy registration, the canonical schema, validation, persistence,
  WP-CLI import/verify tooling — and, crucially, **the table renderer**.
- The theme's entire integration surface is `tsr_render_results( $post_id )`
  (plus the `[trailseries_results]` shortcode). These take a post ID and
  nothing else — there is no parameter to select, hide or reorder columns, so
  the theme *cannot* deviate from the canonical layout even intentionally.
- The theme owns look-and-feel only: page templates, navigation, and CSS for
  the `.tsr-results` markup the plugin emits.
- The theme must degrade gracefully when the plugin is inactive
  (`function_exists()` guard), and the plugin must not depend on the theme.

## Consequences

- Positive: switching or rewriting the theme cannot lose or reshape results;
  the column contract is enforced at a single choke point in plugin code;
  plugin and theme can be versioned and deployed independently.
- Positive: data-integrity tooling (`wp tsr verify-names`) ships with the data
  owner, not the design.
- Negative: design changes to the table beyond CSS (e.g. different markup)
  require touching the plugin. Intentional friction — markup changes to a
  results table deserve the same scrutiny as schema changes.
- Both live in this repo (repo root mirrors the WordPress root), so
  "separate" means separate deployable units, not separate repositories.

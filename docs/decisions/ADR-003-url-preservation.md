# ADR-003: Legacy URLs are preserved byte-for-byte, verified by crawl diff

- Status: Accepted
- Date: 2026-07-02

## Context

trailseries.bg has 13 years of inbound links, search rankings and shared
result URLs. The rebuild replaces theme and data model, but every public URL
that works today must keep working — hard requirement. "Preserved" means the
same path serves a 200 with equivalent content at the same address; redirects
are a fallback for exceptions, not the strategy, because even a correct 301
leaks link equity and breaks the "results link I bookmarked in 2016 still
works" promise.

## Decision

**1. Inventory first.** Before any content migration, build a complete URL
inventory (`migration/url-inventory.csv`, committed) from four sources:
the live site's XML sitemap, a full crawl of the live site, the old database
(`wp_posts` post_type/post_status/post_name + permalink structure), and Google
Search Console's indexed-pages export. Each row: URL, HTTP status, source,
content type (results page / race page / news / other).

**2. Slugs are copied, never regenerated.** Migrated posts get their
`post_name` set explicitly from the inventory. WordPress slug auto-generation
(sanitize/deduplicate) must never run on a legacy URL — the migration tooling
sets slugs verbatim and fails on collision instead of appending `-2`.

**3. Rewrite structure matches the old paths.** The `ts_result` CPT's rewrite
config (currently a `results` placeholder in `TSR_Post_Types`) will be set to
reproduce the old URL shape exactly once the inventory establishes what that
shape is — including Cyrillic/percent-encoded slugs, trailing-slash behavior,
and any date or hierarchy components. If old results URLs don't share a common
prefix, custom rewrite rules per pattern are added rather than moving pages.

**4. Verification is automated, on staging, before cutover.** A script fetches
every inventory URL against `stg.trailseries.bg` and asserts a 200 (or an
intentional, documented exception). The diff must be empty to ship. The same
check runs against production immediately after cutover.

**5. Exceptions get explicit 301s.** Any URL that genuinely cannot be served
at its old path (expected: none for results pages) gets a one-to-one 301 in a
committed redirect map — never a pattern redirect to the homepage — and is
marked as such in the inventory so the verifier expects it.

**6. 404s are monitored after launch.** 404 logging stays on for the first
weeks; hits from real referrers are treated as inventory misses and fixed with
entries per rule 5.

## Consequences

- Positive: SEO and shared links survive the rebuild; correctness is proven by
  a repeatable check, not by clicking around; the inventory doubles as the
  migration work-list for the ~150 results pages.
- Negative: new-site URL aesthetics are constrained by 13-year-old choices; the
  CPT rewrite setup may end up less idiomatic than a greenfield design.
  Accepted — URLs are an interface, and this one has users.
- Open until inventory exists: the exact old URL patterns, and therefore the
  final rewrite configuration. The `results` slug in code is a placeholder and
  must not ship to production.

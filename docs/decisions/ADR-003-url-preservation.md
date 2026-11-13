# ADR-003: Legacy URLs are preserved byte-for-byte, verified by crawl diff

- Status: Implemented
- Date: 2026-07-02
- Resolved: 2026-07-03

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

**3. Rewrite structure matches the old paths.** The URL inventory (172 result
URLs) confirms that all race result pages live at the root: `/{slug}/` with no
common prefix. `register_post_type()` cannot express this: an empty rewrite
slug is replaced by the post type name in core, so the CPT's own rules live
under `/ts_result/` (corrected 2026-11-13, audit B1 — the original text claimed
`'slug' => ''` produced root URLs; it never did). Root URLs are served by
`TSR_Routing` (`includes/class-routing.php`): a `request` filter points the
`/%postname%/` root rule at `ts_result` when no page or regular post owns the
slug, and a `post_type_link` filter makes permalinks, canonical tags and the
sitemap use `/{post_name}/`. Core's 404 "guess" redirect is disabled
(`do_redirect_guess_404_permalink`), so an unresolved legacy URL is a visible
404, never a prefix-matched 301 (rule 5). The built-in CPT archive is disabled
(`has_archive => false`); the Резултати index lives at the `/rezultati/` WP Page
with template `page-rezultati.php` instead. Cyrillic slugs are stored verbatim
as `post_name` (percent-encoding is handled transparently by WP's rewrite
engine). The single depth-1 URL (`/класиране/генерално-класиране/`) is a
standings page, not a ts_result post — it is handled by the redirect map.

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
- Resolved: the URL inventory confirmed top-level `/{slug}/` structure. The
  2026-07-03 change to `class-post-types.php` did not achieve it (results were
  at `/ts_result/{slug}/`, legacy URLs reached them only through core's 404
  guess redirect); fixed 2026-11-13 by `TSR_Routing`. Staging must flush
  rewrite rules after plugin reactivation (`wp rewrite flush`) before the
  crawl-diff verification pass.
- Known gap: WordPress only de-duplicates slugs within one post type. A new
  page or news post given a result's slug silently takes over that result's
  URL (pages and posts win over results). Rule 2 wants that to fail instead;
  not enforced yet.

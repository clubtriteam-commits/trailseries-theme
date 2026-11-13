# Cutover runbook — trailseries.bg (audit B5)

Status: draft, 2026-11-13. Decision (audit open question 9): **production is a
copy of the cleaned staging database**, not a fresh install with the imports
re-run. Content published on the old site after the 2026-07-02 export is
brought over with the existing importers before the copy.

Why: staging holds state that exists nowhere else (menu, Exhibz theme
settings, page-template assignments, partners, EventON events, 158 imported
event pages, 347 sideloaded images), and the one-off migration scripts
hard-code staging post IDs, so they cannot rebuild it. Staging is small (one
admin user, 10 MB database, 369 MB uploads). ADR-003 asks for a site that
passes the strict crawl diff, not for a reproducible rebuild.

Server: `ssh -i ~/.ssh/id_ed25519_argon -p 1022 trailser@argon.superhosting.bg`,
WP-CLI is `php ~/wp-cli.phar`. Placeholders below:

- `STG=/home/trailser/stg.trailseries.bg` — staging docroot
- `OLD=<docroot of the current trailseries.bg>` — **TBD**, confirm before step C1
- `NEW=<docroot for the new production site>` — **TBD** (a fresh directory;
  cutover switches the domain's document root to it, which is also the rollback)
- `BAK=/home/trailser/backups/cutover-<date>` — outside every docroot, mode 700

## A. Staging must be green first

1. Phase 1 data work, in this order: B3+B4 (titles, distance, gender), M4 prune
   (exclude `news__*.json` — podium tables of a news post; a `news` hub would
   collide with post 4811), `wp tsr bulk-import --force` from
   `canonical_fixed`, M3 cache flush.
2. M16: import the 5 missing posts from the WXR with their exact slugs (3 news
   posts, registration lists `/117/` and `/1539/`).
3. Delete the stub page `/event-directory/` (13 bytes; the map 301s that URL).
4. Strict crawl diff, locally, with a copy of the manifest kept outside the repo:
   `python -X utf8 migration/crawl_diff.py --strict --manifest <copy of tsr-data/migration/data/canonical_fixed/_manifest.csv>`
   Must end with **0 FAIL, 0 ERROR**. Exceptions only from
   `migration/crawl-diff-exceptions.csv`.

## B. Content delta since 2026-07-02 (three races so far)

The WXR export of 2026-07-02 is the migration snapshot. Since then **three
races** have taken place on the old site, plus any news.

1. List what changed on the old site (read-only, from `OLD`):
   `php ~/wp-cli.phar db query "SELECT post_type, post_name, post_date FROM <prefix>posts WHERE post_status='publish' AND (post_date > '2026-07-02' OR post_modified > '2026-07-02') ORDER BY post_date"`
2. Fresh WXR export from the old site (Tools → Export, or `wp export`) into
   `migration/data/raw/` locally (gitignored). Never into a docroot.
3. Add every new public URL to `migration/url-inventory.csv` and
   `migration/redirect-map.csv` (status 200; ADR-003 rule 1).
4. Build a page list for the new export
   (`python migration/build_results_page_list.py --xml <new WXR> --out <scratch>.csv`,
   never over `results-page-list.csv`), keep only the three new
   results pages, and extract them **into a separate directory** — the
   extractor's default `--out-dir` is the July set, and it writes the manifest
   for whatever it processed:
   `python migration/extract_canonical_results.py --xml <new WXR> --pages <3-page list> --out-dir migration/data/delta-<date>`
   Check `_manifest.csv` / `_issues.txt` there (categories, row counts, any
   category overrides needed in `category-overrides.csv`). Then add the JSON
   files to `tsr-data/migration/data/canonical_fixed/` on the server and append
   their manifest rows to its `_manifest.csv`; merge the three new page rows
   into `results-page-list.csv` and commit it.
5. Import them (`wp tsr bulk-import` with `TSR_DATA_DIR` or
   `--manifest`/`--json-dir`), and import new news posts with their exact slugs.
6. Re-run the strict crawl diff (A4) — green again, now including the new URLs.

## C. Cutover day

Announce a content freeze on the old site from C1 until D is done (no new
posts, no results). If a race falls in the window, publish its results on the
new site after launch.

1. **Backup** (verify each file is non-empty):
   - old site DB: `cd $OLD && php ~/wp-cli.phar db export $BAK/old-db.sql`
   - old site files: `tar czf $BAK/old-files.tgz -C $OLD .`
   - staging DB: `cd $STG && php ~/wp-cli.phar db export $BAK/stg-db.sql`
2. **Last delta check**: repeat B1. Anything new since B → do B2–B6 for it first.
3. **New docroot**: create `NEW`; install the same WordPress core version as
   staging (7.1.4) and the same third-party plugins (EventON etc.) with the
   same versions; copy from the git clone only
   `wp-content/plugins/trailseries-results/` and `wp-content/themes/exhibz-child/`
   (plus the parent theme `exhibz`). Never `migration/`, `docs/`, `.git`.
4. **Database**: create a new database + user in cPanel; write `NEW/wp-config.php`
   with the new credentials, **fresh salts** (https://api.wordpress.org/secret-key/1.1/salt/),
   `WP_DEBUG` false, and `define( 'TSR_DATA_DIR', '/home/trailser/tsr-data/migration/data/canonical_fixed' );`.
   Import: `php ~/wp-cli.phar db import $BAK/stg-db.sql` (from `NEW`).
5. **Uploads**: `cp -a $STG/wp-content/uploads $NEW/wp-content/`.
6. **URLs**: `php ~/wp-cli.phar search-replace 'https://stg.trailseries.bg' 'https://trailseries.bg' --skip-columns=guid --precise --dry-run`,
   check the counts, then run it without `--dry-run`. Repeat for
   `//stg.trailseries.bg` and the bare `stg.trailseries.bg` only if the dry run
   shows leftovers.
7. **Settings that differ from staging**:
   - `php ~/wp-cli.phar option update blog_public 1` — staging is `0`; launching
     with it would de-index the site.
   - admin user: new strong password (`wp user update TSadmin --user_pass=…`).
8. **.htaccess**: the "TSR security" block (`RedirectMatch 404` for
   `/migration` and `.git`) at the top, above the WordPress block; then
   `php ~/wp-cli.phar rewrite flush`.
9. **Switch**: point the trailseries.bg document root at `NEW` (cPanel →
   Domains). Keep `OLD` untouched.
10. Clear any CDN/host cache if one is in front (audit question 10 — none seen
    on staging).

## D. Verify (within the first hour)

1. Strict crawl diff against production:
   `python -X utf8 migration/crawl_diff.py --strict --host trailseries.bg --manifest <copy> --out <scratch path>`
   — must match the last staging run (0 FAIL). Commit the report as
   `migration/crawl-diff-production-report.csv`.
2. `curl -sI https://trailseries.bg/migration/` and `/.git/HEAD` → `404`.
3. `https://trailseries.bg/robots.txt` does not say `Disallow: /`; view source
   of `/` has no `noindex`.
4. Spot-check by hand: front page, `/rezultati/`, one results page per season,
   `/klasiraniya/`, `/calendar/`, one EventON event, one news post with images,
   a 301 (`/all-seasons-rankings/`) and a 410 (`/feedback/`).
5. Submit `https://trailseries.bg/sitemap.xml/` in Search Console (note L14:
   `/sitemap.xml` currently 301s to the slash form).

## E. Rollback

Trigger: any D1 failure on results or news URLs that can't be fixed in an hour,
or a broken front page.

1. Point the trailseries.bg document root back at `OLD` (cPanel → Domains).
   The old site's files and database were never modified.
2. Lift the content freeze on the old site.
3. Keep `NEW` and its database for diagnosis; fix on staging; restart from C.

## F. After launch

- ADR-003 rule 6: watch 404s in `~/access-logs/trailseries.bg-ssl_log` daily
  for the first weeks (logs rotate monthly and are not archived — copy them to
  `BAK` if needed). Hits from real referrers become one-to-one entries in
  `redirect-map.csv`, regenerate with `generate_redirects.py`, deploy.
- Old site: keep `OLD` and `BAK` for at least 3 months, then archive offline
  and delete from the server (personal data).
- Turn staging off or put it behind HTTP auth, so two copies of the site are
  not public.

## Open before this can run

- `OLD` and `NEW` paths, and whether the domain switch is a docroot change in
  cPanel (assumed) or a file swap.
- B5 depends on Phase 1 (A1) and M16 (A2) being done.
- The three new races: which pages they are (B1 lists them).

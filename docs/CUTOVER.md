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
   `canonical_fixed`, M3 cache flush, then **H1 seasons**:
   `wp tsr apply-seasons --file=/home/trailser/tsr-data/migration/series-seasons.csv`
   (dry run first with `--dry-run`; a second run must report "Would change 0"). Re-run it
   after **every** bulk import — the importer writes the race year from titles and does not
   know the 2012–2020 season split.
2. ~~M16: import the 5 missing posts~~ Done 2026-11-13 (`/1539/` is a 301 to `/1539-2/`, see audit M16).
3. ~~Delete the stub page `/event-directory/`~~ Not a stub: page 1566 is EventON's "Events" page (`[add_eventon]`); keep it. The map 301s `/event-directory/` to `/calendar/` regardless.
4. Strict crawl diff, locally, with a copy of the manifest kept outside the repo:
   `python -X utf8 migration/crawl_diff.py --strict --manifest <copy of tsr-data/migration/data/canonical_fixed/_manifest.csv>`
   Must end with **0 FAIL, 0 ERROR**. Exceptions only from
   `migration/crawl-diff-exceptions.csv`.
5. SEO code fixes deployed and verified on staging (audit B6, approved
   2026-11-13): home page canonical (`show_on_front=posts` gives none today);
   `noindex` on runner search pages (H4); `/sitemap.xml` served without the
   slash redirect (L14); core sitemap off with a one-to-one 301
   `/wp-sitemap.xml` → `/sitemap.xml/` (M7 — the old site's `robots.txt`
   advertises `/wp-sitemap.xml`); `robots.txt` lists the custom sitemap.
   Also M6 (`filemtime()` JS versions) before the 1-year cache headers in C8.

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
   `--manifest`/`--json-dir`), then `wp tsr apply-seasons` (see A1), then
   `wp tsr alias-auto` (merges Cyrillic/Latin spellings of one runner; it is not run
   automatically, so repeat it after every results import), and import new news
   posts with their exact slugs.
   New race landing pages go into `migration/event-pages-list.csv` and through
   `import-event-pages.php` (copy the list to `tsr-data/migration/` first; run
   with `wp eval-file … --use-include`, plain eval rejects the script's
   `declare(strict_types=1)`).
   Done 2026-11-13 for the three 2026 races so far: landing pages
   `/7-hills-run26/`, `/buhovo-half-marathon26/`, `/the-cactus-run26/` imported
   (staging pages 7296, 7298, 7304); their results pages
   `/7-hills-run26-ranking/`, `/buhovo-half-marathon26-results/`,
   `/the-cactus-run26-results/` (live pages 6636, 6652, 6670) extracted from the
   export `runbgtrailseries.WordPress.2026-11-13.xml` (pages since 2026-09-01)
   into `migration/data/delta-2026-11/`: 17 sections, 295 rows, every source
   data row accounted for, 0 issues (6 new headings added to
   `category-map.csv`). Imported to staging (posts 7308–7324, names verified),
   `backfill-meta` run (categories match the 2025 editions), and merged into
   `tsr-data/…/canonical_fixed/` (manifest backup
   `canonical_fixed-_manifest.bak-20261113`). Strict crawl: all six URLs pass.
   Still to do at cutover: B1/B2 again for anything newer, and news posts
   since July (export Posts from 2026-07).
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
   shows leftovers. Then clear everything cached with staging URLs:
   `php ~/wp-cli.phar transient delete tsr_sitemap_xml` and
   `php ~/wp-cli.phar eval 'tsr_flush_result_caches();'`.
7. **Settings that differ from staging**:
   - `php ~/wp-cli.phar option update blog_public 1` — staging is `0`; launching
     with it would de-index the site. This is the only thing blocking indexing
     on staging (meta `noindex`; no robots.txt or header rule), so it must be
     the last database change before C9, and it is checked again in D3.
   - site language: `WPLANG` must be `bg_BG` (set on staging 2026-11-14 so pages declare
     `lang="bg-BG"` and Chrome offers translation to foreign visitors; the old site and
     staging said `en-US`). Check: `curl -s https://trailseries.bg/ | grep -o '<html[^>]*>'`.
     Admin users keep English via their profile locale.
   - admin user: new strong password (`wp user update TSadmin --user_pass=…`).
8. **.htaccess**: the "TSR security" block (`RedirectMatch 404` for
   `/migration` and `.git`) at the top, above the WordPress block, plus the
   headers from audit L18 — `Strict-Transport-Security "max-age=300"` (raise
   to `31536000` after a week of clean HTTPS; no `includeSubDomains`/`preload`),
   `X-Frame-Options SAMEORIGIN`, `Cross-Origin-Opener-Policy
   same-origin-allow-popups`, `X-Content-Type-Options nosniff`,
   `Referrer-Policy strict-origin-when-cross-origin` — plus the "TSR
   performance" block (deflate + mod_expires) and the "TSR cache" block (L16),
   both copied verbatim from the staging `.htaccess`, after "TSR performance":
   ```
   # BEGIN TSR cache - versioned CSS/JS never change under the same URL (audit L16, 2026-11-13)
   # WordPress adds ?ver= (theme assets: file mtime), so a changed file gets a new URL.
   <IfModule mod_headers.c>
   <If "%{QUERY_STRING} =~ /(^|&)ver=/ && %{REQUEST_URI} =~ /\.(css|js)$/">
   Header set Cache-Control "public, max-age=31536000, immutable"
   Header unset Expires
   </If>
   </IfModule>
   # END TSR cache
   ```
   Check: `curl -sI "https://trailseries.bg/wp-content/themes/exhibz-child/style.css?ver=1"`
   shows `Cache-Control: public, max-age=31536000, immutable`.
   Then `php ~/wp-cli.phar rewrite flush`.
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
3. Indexing (B6): on `/`, `/rezultati/` and one results page — no
   `<meta name='robots' content='noindex…'>`, no `X-Robots-Tag` header,
   canonical on `https://trailseries.bg/…`; `robots.txt` has no `Disallow: /`
   and lists `https://trailseries.bg/sitemap.xml`; sitemap URLs are on the
   production host; `/wp-sitemap.xml` 301s to `/sitemap.xml/`; `http://` and
   `www.` URLs 301 to `https://trailseries.bg/`.
   Then close staging: HTTP auth on `stg.trailseries.bg` (cPanel → Directory
   Privacy), or at least `Header set X-Robots-Tag "noindex, nofollow"` in its
   `.htaccess` — it holds the same content as production.
4. Spot-check by hand: front page, `/rezultati/`, one results page per season,
   `/klasiraniya/`, `/calendar/`, one EventON event, one news post with images,
   a 301 (`/all-seasons-rankings/`) and a 410 (`/feedback/`).
5. Search Console: submit `https://trailseries.bg/sitemap.xml`, run URL
   inspection on five sample URLs (home, `/rezultati/`, a results page, a news
   post, an event page), then watch coverage and 404s daily (ADR-003 rule 6,
   section F).

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
- Staging stays closed (D3); turn it off once it is no longer needed.

## Open before this can run

- `OLD` and `NEW` paths, and whether the domain switch is a docroot change in
  cPanel (assumed) or a file swap.
- B5 depends on Phase 1 (A1) and M16 (A2) being done.
- The three new races: which pages they are (B1 lists them).

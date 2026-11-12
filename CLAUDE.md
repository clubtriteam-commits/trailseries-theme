# trailseries-theme

WordPress theme/site code for trailseries.bg (Bulgarian trail running series). Repo root mirrors
the WordPress root; only the site's own code is versioned — WP core, config, and third-party
plugins are gitignored.

Read `docs/decisions/` (ADR-001, ADR-002, ADR-003 at least) before any structural change to the
results table layout, runner-name handling, or URL routing — those three are enforced as hard
rules, not conventions.

## Deploy (SSH, no working auto-deploy)

There is currently **no automatic deploy on `git push`**. cPanel's own Git auto-deploy (`uapi
Git ...`) is broken on this host — `Cpanel::API::Git` fails to load (missing Perl module) — and
there's no `.cpanel.yml` to fall back on. Until that's fixed, deploying is a manual two-step SSH
job every time:

```
ssh -i ~/.ssh/id_ed25519_argon -p 1022 trailser@argon.superhosting.bg
```

Two *different* directories are involved on the server — don't conflate them:

- `/home/trailser/repositories/trailseries-theme` — the cPanel-managed git clone. `origin` is
  the GitHub repo, tracks `main`. Safe to `git pull origin main` here any time.
- `/home/trailser/stg.trailseries.bg` — the actual live docroot served at stg.trailseries.bg.
  Not a git checkout (a stray `.git` here was publicly served and was deleted on 2026-11-12)
  and not kept in sync with the clone automatically. Never run `git init`/`git clone` in it.
- `/home/trailser/tsr-data/` — private data, mode 700, **outside** the docroot. Holds
  `migration/` (the one-off scripts and `migration/data/` with the canonical results JSON —
  names and ages of real people). See "Results data lives outside the docroot" below.

**Procedure:**

1. `git push` locally (normal).
2. SSH in, `cd /home/trailser/repositories/trailseries-theme && git pull origin main`.
3. `cp` only the files that actually changed from the clone into the matching path under
   `/home/trailser/stg.trailseries.bg` — do not blanket-sync the whole tree over SSH. Example for
   a set of changed files:
   ```
   SRC=/home/trailser/repositories/trailseries-theme
   DST=/home/trailser/stg.trailseries.bg
   cp SRC/path/to/file DST/path/to/file   # repeat per changed file
   ```
4. Verify on the live file (e.g. `grep` for a string you just added) — don't assume the copy
   landed correctly.

Only `wp-content/plugins/trailseries-results/` and `wp-content/themes/exhibz-child/` are ever
copied into the docroot. `migration/`, `docs/`, `README.md` and `CLAUDE.md` never go there.

## Results data lives outside the docroot

Until 2026-11-12 `migration/data/` sat inside the staging docroot and was downloadable over
HTTP (docs/AUDIT-2026-11.md, H11). Rules since then:

- Nothing under `migration/` is uploaded to a docroot. On the server it lives in
  `/home/trailser/tsr-data/migration/`; run one-off scripts from the docroot with an absolute
  path, e.g. `wp eval-file /home/trailser/tsr-data/migration/seed-partners.php`.
- `wp tsr bulk-import` has no default data path. Either define `TSR_DATA_DIR` in
  `wp-config.php` (the directory that holds `_manifest.csv` and the JSON files) or pass both
  `--manifest` and `--json-dir`. It refuses any path inside the web root.
- The server has four copies of the results data under `tsr-data/migration/data/`:
  `canonical_fixed/` (904 files, 2026-08-22/23 — the current set), `canonical/` (July, stale),
  loose JSON files directly in `data/` (July) and two zips. Which to keep is not decided yet.
- The top of the docroot `.htaccess` has a "TSR security" block (`RedirectMatch 404` for
  `/migration` and `.git`) as a second line of defence — keep it above the WordPress block.
  Backups of the previous `.htaccess` are in `/home/trailser/htaccess-stg.bak-*`.

Same server/account as the `training-agent` project (`argon.superhosting.bg`, same SSH key,
comment says "training-agent-deploy" but it works for this repo too — same cPanel account).

**Not yet checked:** whether cPanel's web UI (Git Version Control page) has a working "Deploy
HEAD Commit" button despite the UAPI CLI being broken. If so, that would replace step 3 above.
Worth checking before assuming manual `cp` is the permanent answer.

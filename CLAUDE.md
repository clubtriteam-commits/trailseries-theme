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
  It has its own separate, unrelated `.git` (uncommitted junk — ignore it, it is **not** the
  deploy mechanism) and is not kept in sync with the clone automatically.

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

Same server/account as the `training-agent` project (`argon.superhosting.bg`, same SSH key,
comment says "training-agent-deploy" but it works for this repo too — same cPanel account).

**Not yet checked:** whether cPanel's web UI (Git Version Control page) has a working "Deploy
HEAD Commit" button despite the UAPI CLI being broken. If so, that would replace step 3 above.
Worth checking before assuming manual `cp` is the permanent answer.

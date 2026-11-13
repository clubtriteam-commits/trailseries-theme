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

## Model routing (which Claude model to use)

Recommend the best model for value (quality per unit of usage), not the most capable model.

Two driving rules:
1. Sonnet 5.5 is the default for almost all work; Haiku 5.5 takes bulk and mechanical work.
2. Opus 5.5 and Fable 5.2 are escalations: deliberate, rare and short. Use them for the hard
   decision, then drop back down.

Model IDs: claude-haiku-5-5, claude-sonnet-5-5, claude-opus-5-5, claude-fable-5-2. Tier order,
cheapest to most capable and most limited: Haiku, Sonnet, Opus, Fable.

Do not quote prices, benchmark scores or plan limits from memory. They change. Check the pricing
page at docs.claude.com and the usage screen (/usage in Claude Code) when the numbers matter.

### Decision matrix

| Lane | Use when | Model | Note |
|---|---|---|---|
| Bulk and lookup | read logs, grep and inventory files, extract fields, reformat, summarize known text, status checks, simple scripts | Haiku 5.5 | Read-only and mechanical. If the result is ambiguous, rerun on Sonnet. |
| Code (default) | implement, iterate, refactor, bugfix, agentic multi-file work, write tests, planning, task breakdown, everyday reasoning | Sonnet 5.5 | The default lane. Start here unless a rule below says otherwise. |
| Design (UI/UX) | frontend design, layout, typography, color, HTML/CSS/component prototypes | Sonnet 5.5 (iterate), then Opus 5.5 (polish) | Iterate cheaply, polish once. |
| Presentation and docs | polished docs, PDF, slides, mixed text, charts and images | Sonnet 5.5 | Use Opus 5.5 for long, multi-source or high-stakes reports. |
| Routine review | second opinion on a normal change: correctness, edge cases, tests, API misuse | Sonnet 5.5 | Do not pay for Opus on routine diffs. |
| Escalation | auth, payments, data migration, concurrency, infra, Kubernetes, security, architecture design, a hard problem Sonnet failed on twice | Opus 5.5 (default), then Fable 5.2 (critical) | Fable for high blast radius or irreversible work, a first audit of a whole codebase, or a decision that gates a launch. |

### Escalation protocol

- Say why in one line before switching, for example: Escalating to Opus because this touches auth.
- Give the stronger model a compact brief (goal, current state, constraints, key files) instead of
  making it rediscover context. Write plans and audits to a file in the repo so the handoff
  survives a lost session.
- Use the stronger model for planning, diagnosis and review. Switch back to Sonnet for execution.
- Failure rule: if Sonnet fails the same task twice with the same kind of error, escalate to Opus.
  If Opus fails once on a critical task, go to Fable.
- Do not escalate for formatting, renames, boilerplate, tests that follow an existing pattern, or
  doc edits.
- Do not ping-pong between models every few turns. Batch the hard work, because switching can
  discard cached context.

### How to switch (Claude Code)

- /model claude-sonnet-5-5 (or another ID above). Typing /model alone opens the picker.
- Always read the confirmation line (Set model to ...). A later /model command overrides an
  earlier one silently. A wrong name returns "Model ... not found".
- If a model is missing, run claude update. Close other Claude Code instances first (terminal and
  VS Code at the same time can block the update). If it is still missing, the plan may not
  include it. Use the next tier down.
- Subagents: pin exploratory and bulk subagents to Haiku or Sonnet. Do not spawn an Opus or Fable
  subagent for work Sonnet can do.
- Advisor: if your Claude Code build offers it, let Sonnet execute while a stronger model
  reviews. That is cheaper than running the whole session on Opus.

### Usage-limit awareness

- Before a large Opus or Fable job (full audit, repo-wide refactor), check the usage screen.
- When a limit is hit, keep going on read-only and preparation work with Haiku or Sonnet if the
  plan allows it, and queue the escalation task for after the reset.
- Map before you read: have Haiku inventory the repo, then give the stronger model only the files
  that matter.

### Layers: what does what

| | This guide | Workspace UI | Session persistence |
|---|---|---|---|
| Main job | choose the best-value model | visual workspace for sessions, diffs, review (VS Code or Cursor extension, Claude desktop app) | keep sessions alive and resumable |
| Decides the model? | Yes | No, you configure it | No, it runs what you start |
| Runs the agent? | No | Yes | Yes |
| Survives a disconnect? | No | History is kept | Yes: claude --continue or claude --resume locally; Claude Code on the web, or tmux on a server for always-on work |
| Remote or multiple agents | No | Limited | Strong fit |

If the user also runs OpenCode with other providers, the same lane structure applies there with
that provider's models. This guide covers the Claude lineup only.

### Anti-patterns

- Running a whole session on Fable or Opus out of habit.
- Downgrading to save usage on security, data-migration or launch-gating decisions.
- Escalating because a task is long, when it is only repetitive.
- Quoting prices or limits from memory.

### Answer format

When asked which model to use, reply in two or three lines: the lane, the model, the reason, and
the exact /model command.

Keep the matrix current. When new models ship, update the lane table and the IDs.

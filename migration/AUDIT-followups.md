# Follow-ups from the category-mislabeling audit (2026-08-22)

`category-overrides.csv` resolves 220 of the ~250 individually mislabeled files found by the 9-batch audit (see `AUDIT-category-mislabeling.md`). Running `extract_canonical_results.py` now reproduces the exact same file/row counts as before (893 files, 17604 rows after 6 confirmed duplicates are dropped) — nothing is lost, nothing is silently guessed. The remaining ~30 files are deliberately left unresolved; each still surfaces as `UNRESOLVED CATEGORY COLLISION` in `_issues.txt` on every run, and needs one of the fixes below before it can get an override entry.

## 1. Compound files — need row-level splitting, not just relabeling

A single "-N" file contains rows from **two or more** genuinely different categories, concatenated (sometimes with place-numbering flattened/restarted mid-file). A `category-overrides.csv` entry can only rename a whole file; these need actual row-range splitting logic (new script feature) before they can be resolved.

- `malak-sechko-run18-ranking__жени-4.json` (occurrence 4, raw "жени") — rows 1-8 = girls' kids race, rows 9-13 (renumbered from place 1) = boys' kids race.
- `simeonovo-run18-results__58км-2.json` (occurrence 2, raw "5.8КМ") — women's 5.8KM (~21 rows) followed by both kids' races (~16 rows), place numbering restarts partway through.
- `iran-run-results__all-2.json` (occurrence 2, raw "") — five categories concatenated in page order: 16.7км Жени + 11.5км Мъже + 11.5км Жени + 5.2км Мъже + 5.2км Жени.
- `buhovo-26-may__all.json` / `__all-2.json` — women's and men's tables concatenated with **continuous renumbering** (a man's real place 1 shows as place 14 in the file). Needs both a split AND place renumbering, not just a relabel.

## 2. Low-confidence — needs re-verification against the live page before writing an override

- `lyulin-trail-run-класиране__all.json` through `__all-6.json` (6 files) — the auditing agent could not confirm exact Bulgarian heading text (it paraphrased "Elite/Junior/Youth divisions" rather than quoting the page). Gender/order is plausible but not page-confirmed; re-fetch with an explicit instruction to quote raw heading text verbatim before trusting a label here.
- `malak-sechko-run22-results__all.json` through `__all-6.json` (6 files) — the live page shows no heading text at all above any table; the agent's suggested labels (19КМ/13КМ/6КМ × М/Ж, by analogy with other years of this race) are a guess, not a page-confirmed reading.
- `xmas-run16-results` (15km / 11km-mixed / 5.5km-mixed, 6 files total) — this slug was in an audit batch's work list but the returned report never covered it (an omission, not a "found nothing" result). Needs a fresh audit pass.

## 3. Data-integrity bugs unrelated to category labeling — separate fix needed in the parser

- `baba-marta-run18-ranking` — the `10KM` group's two files both actually contain **16KM** data (byte-identical to the correctly-labeled 16KM files elsewhere on the same page); likely safe to `__DROP__` once confirmed, since the real 10KM data already exists correctly (in the page's unlabeled `all-3`/`all-4` sections, already covered by an override). The `16KM` and `6KM` 2-file groups are stale/duplicate copies of the same page's `all`-set with slightly drifted row counts (9 vs 10, 18 vs 19) — needs a decision on which copy to trust, not a relabel.
- `baba-marta-run19-ranking__6km-mixed.json` (occurrence 1) — has 5 more rows than the live page's table, consistent with a second column (likely a gap-to-leader or lap column) being mis-parsed as extra runner rows. A parser bug, not a labeling bug.
- `maliovitsa-skyrun-4-август` files (already correctly relabeled by category) — separately, `first_name` has the position/номер column bled into it (e.g. `"1 Начев"` instead of `"Начев"}`). Category label is fixed; the name field itself still needs a parser fix.

## Not yet swept at all

The 9-batch audit only checked files that already had a filename collision (127 groups). It did **not** check whether any *cleanly-named* (non-suffixed) file is nonetheless mislabeled — out of scope so far, only collisions were checked.

~~Two confirmed extraction gaps: `iran-run19-results` and `lyulin-trail-run-класиране` each have a "Деца"/"KIDS" table missing entirely.~~ **Resolved 2026-08-23** — both were incidentally fixed by the malformed-`<tr>` parser fix below (same root cause as the Malak Sechko Run'26 case): `iran-run19-results__kids.json`, `lyulin-trail-run-класиране__деца.json` now extract correctly.

## 4. Full production-vs-staging cross-check (2026-08-23)

A systematic pass — every ~172 production page's finisher-time-token count vs. local canonical row sums, then 26 flagged pages individually re-verified against raw production HTML — found and fixed three further bug classes, all now deployed to staging and byte-verified live:

- **Missing `<tr>` before a header row** (same root cause as the original Malak Sechko Run'26 report): silently dropped the whole section with zero errors logged. Fixed generically in the HTML parser (a `<td>`/`<th>` run with no active row now implicitly opens one). Recovered sections: Malak Sechko Run'26 & '25 19KM МЪЖЕ, The Chrismas Run'19 11КМ МЪЖЕ, The Cactus Run'25 7КМ МЪЖЕ, the two kids sections noted above.
- **Relay/team tables dropped entirely**: rows require a first_name or last_name, but relay tables have no such column (team name is the row's identity) — every relay row was excluded as "row without a name". Fixed: falls back to the team name as first_name. Recovered: Pancharevo Night Run '18/'19/'21 — 3 relay categories each, 41 teams total.
- **Untimed finishers discarded as standings tables**: a few short kids' categories have no recorded times at all and were being treated as season-standings noise. Added `TSR_Status::FinishedNoTime` ('FNT') — a new, clearly-distinct status (PHP enum + validation + Python extraction, narrowly scoped to inherited-mapping sections with a genuinely blank finish_time cell) — rather than either losing the runners or mislabeling them DNF. Recovered: simeonovo-run-ranking "1км МОМИЧЕТА" (4), the-cactus-run15-results kids sections (11), cactus-run18-ranking "Kids Run Winners" (6).
- **Source-data age errors crashing the whole import**: two rows across the whole dataset had a birth year typed into the age column (e.g. "1992") instead of an age; the schema correctly rejects it, but as a fatal exception rather than a recoverable per-row issue. Age is now range-checked (0–130) at extraction time and nulled with a logged issue on failure.

**Decided, not migrated**: `news__*.json` (6 files, 18 rows, "7/14/21km × M/F" top-3 fragments) — traced to a 2013 news-article recap ("Новини след The Cactus Run"), confirmed byte-for-byte duplicate of runners already present in `cactus-run-10-11-2013`'s proper results files. `results-page-list.csv` had already flagged this URL `has_results_table=no` during curation. Deliberately excluded — would only create redundant posts.

**False alarms, no action needed**: `malak-sechko-run25-results` and `-run26-results`' "19KM МЪЖЕ"/"19KM ЖЕНИ" appearing to be missing from `/rezultati/` — both are the page's "hub" post (shown as the bare event-name link, not a distance pill) and were confirmed present, published, and byte-verified all along.

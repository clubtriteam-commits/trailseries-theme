# Audit: category mislabeling in migration/data/canonical/

- Date: 2026-08-22
- Trigger: user-reported bug — `golyam-sechko-run26-results` 15KM page labeled "МЪЖЕ" (men) actually showed women's results, and `/rezultati/` appeared to be missing 2026 results.
- Scope: every canonical JSON file group where `extract_canonical_results.py` produced a `-2`/`-3`/... suffixed filename due to a raw category-heading collision (127 groups found via `_manifest.csv`, 123 with divergent content after excluding 4 exact-duplicate groups).
- Method: 9 parallel audits (65 race pages), each fetching the live production page (trailseries.bg) and diffing its actual table headings + runner order against the local canonical JSON files. All 9 batches completed and are reflected below, except one gap noted under "Additional confirmed cases."

## Headline result

**0 of ~120 audited groups were legitimate pagination ("MERGE"). All were MISLABEL** (one batch has a single unreported group, see gap below — everything else is accounted for).

The original hypothesis — that repeated-heading files are just one long results table pasted into WordPress as multiple `<table>` chunks — was wrong for this dataset. The actual, near-universal pattern:

> A source page shows one distance heading (e.g. "18KM") followed by TWO separate tables — men, then women — with no second heading, or a heading that repeats verbatim, or a heading that's missing entirely. `extract_canonical_results.py` treats each table as its own "section," sees the same category text (or none) twice, and silently appends `-2` to avoid a filename clash — without ever recording that the two sections are actually different categories (usually opposite genders, sometimes a different distance, kids' race, or relay).

This means: **for the great majority of split-heading races across all 13 seasons, roughly half of the canonical data — every "-2" file, and sometimes the base file — carries the wrong gender label.** This is not cosmetic; a coach or runner searching by gender/category gets wrong or invisible results.

## Root cause

`extract_canonical_results.py` (lines ~798-810): filenames are keyed by `page_slug + category_raw` (or a fallback slug of the raw heading text). Collisions are resolved with a bare counter (`-2`, `-3`, ...) and **no signal is recorded about what actually changed between the colliding sections** — not gender, not distance, nothing. The information needed to label a "-2" file correctly (which table it was, i.e. its position on the page and the runners in it) is present in the extraction code's working state (`Section` objects, in page order) but is discarded once written to disk under a numeric suffix.

## Bug classes found (with counts, out of ~120 groups)

1. **Gender pair under one heading** (~90 groups, the dominant pattern) — base file is one gender, `-2` is the other. Which gender comes first varies by race/page; no fixed convention (confirmed both "men then women" and "women then men" across different pages).
2. **Wrong distance entirely**, not just wrong gender:
   - `baba-marta-run18-ranking__10km*.json` — both files actually contain **16KM** data (byte-identical to the correctly-labeled 16km files elsewhere on the same page). The real 10KM data lives only in the unlabeled `all-3`/`all-4` files. These two files are redundant/should be dropped, not relabeled.
   - `cactus-run-2016-ranking__21км-3.json` — actually the **10KM men's** table; no other file holds this race's 10km men's data, so this one must be *relabeled*, not dropped.
3. **Compound files bundling multiple categories in one file, place-numbering flattened**:
   - `malak-sechko-run18-ranking__жени-4.json` — rows 1-8 are girls' kids race, rows 9-13 (numbering restarts) are boys' kids race.
   - `simeonovo-run18-results__58км-2.json` — women's 5.8KM + both kids' races concatenated.
   - `buhovo-26-may__all.json` / `__all-2.json` — women's and men's tables concatenated with **continuous renumbering** (men's true place 1 shows as place 14) — gender boundary and real finishing places are lost, not just the label.
   - `runbg-trail-series-железница...__класиране-мъже-2.json` — not a second men's table at all; it's the page's separate "Крайно класиране" (combined) table, including DNF/DNS rows.
4. **Exact-duplicate extraction** (already isolated separately, 4 groups): `golyam-sechko-run24-ranking`'s `6km-f/-2`, `6km-m/-2`, `9km-m/-2`, `9км-жени/-2` are byte-identical pairs — the same table extracted twice (likely a duplicate WXR item not caught by the title+slug dedup check). Its `15км-мъже-2.json` is also an exact duplicate of the base file; `15км-мъже-3.json` is the real women's table, itself a duplicate of an already-correctly-named `15km-f.json`.
5. **Source-page duplicate-heading bug** (the original reported case, and confirmed elsewhere): the *live production site itself* shows the same heading text twice, once by mistake, immediately above a table it doesn't describe. Confirmed in `golyam-sechko-run26-results`, `iran-run-results15`/`results-iran-run15`, `pancharevo-night-run19-ranking`, `simeonovo-run16-ranking`, `the-cactus-run-results14`. This is a data-entry error on the **original** trailseries.bg, faithfully (and correctly, per the "byte-faithful" extraction principle) carried through — the fix is choosing the right label, not "correcting" the source.
6. **Extraction gap — data never extracted at all**: `iran-run19-results` and `lyulin-trail-run-класиране` each have a "Деца"/"KIDS" table visible on the live page with **no corresponding canonical JSON file** — those finishers are entirely absent from the migration, not just mislabeled. (Found incidentally; the full dataset was not swept for this specific gap — see Follow-ups.)
7. **Field-level data corruption** (found incidentally, not systematically swept):
   - `maliovitsa-skyrun-4-август__позиция*.json` — the position/номер column bled into `first_name` (e.g. `"1 Начев"` instead of `"Начев"`).
   - `baba-marta-run19-ranking__6km-mixed.json` — has 5 more rows than the live page's table, consistent with a second column being mis-parsed as extra runner rows.

## Additional confirmed cases (Christmas/Cactus/Vladaya/Zheleznitsa races)

Same patterns, same evidence standard (row counts + first/last runner names matched against the live production page):

- `the-cactus-run17-ranking` (20KM/13.5KM/6.6KM), `the-christmas-run-2017-klasirane` (15/11/5.5КМ), `the-christmas-run18-ranking` (15/11/5.5KM) — all standard gender pairs, base=Мъже, `-2`=Жени.
- `the-christmas-run21-results` — 6 unlabeled `all*.json` files map 1:1 to 6 page categories in order (2 Laps/1 Lap/5KM × Мъже/Жени).
- `the-christmas-run24-results__15km-f-2.json` — **not** a second 15km-women table at all: it's the page's **11КМ МЪЖЕ** table (31 rows). Same bug class as the original golyam-sechko-run26 report, but here it's both a wrong gender *and* a wrong distance.
- `vladaya-21-april` and `zheleznitsa-2-0-20-jan` — collision was **by distance**, not gender: the `-2` file is the *same gender's* other-distance table (e.g. `мъже.json`/`мъже-2.json` = 6.3KM/12.6KM men, not two men's-6.3KM tables).

**Gap:** `xmas-run16-results` (15KM/11KM/5.5KM groups) was included in this audit batch's work-list but not covered in the returned report — status unknown, needs a follow-up check before being treated as either MERGE or MISLABEL.

## What this means for `golyam-sechko-run26-results` (the original report)

Confirmed: `golyam-sechko-run26-results__15км-мъже-2.json` (15 rows, Dessy Tabakova et al.) is the real **15КМ ЖЕНИ** table; `golyam-sechko-run26-results__15км-мъже.json` (42 rows) is correctly the men's table. Simple 2-file relabel, no data loss, no re-splitting needed — the cleanest case in the whole audit.

## Recommendation (not yet actioned — awaiting direction)

1. **Do not import any more of this canonical data into WordPress as-is.** Everything already on staging that came from a split-heading page should be assumed mislabeled until checked — this audit only covered files that already had a filename collision; it did NOT check whether any *cleanly-named* (non-suffixed) file is nonetheless wrong (out of scope so far).
2. **Fix `extract_canonical_results.py`**, not the data by hand: teach it to detect a repeated/absent heading following a "restart at place 1" boundary and use page-order + adjacent context to assign a distinguishing label (e.g. append "(2nd table)" pending human confirmation, or better: track a per-page table sequence and cross-reference `category-map.csv`/known gender-pair conventions) rather than a bare numeric suffix. This is the only durable fix — hand-fixing ~120 groups without fixing the generator means the next full re-extraction regresses everything.
3. **Re-run extraction, then a second, narrower audit pass** to confirm the fix, before any (re-)import to WordPress.
4. Separately investigate the two known extraction gaps (kids tables missing entirely) and the two field-corruption cases — both suggest the parser has more edge cases than this audit's scope (heading collisions) covers; a fuller correctness pass of the parser may be warranted.

This was an agent-only investigation (9 parallel `general-purpose`/fable-model agents, each auditing ~8 race pages against live production). Full per-group evidence (row counts, first/last runner names matched against production) is in the individual agent transcripts if a specific case needs re-verification before acting on it.

# Lighthouse — staging, 2026-11-13 (after M17, M19, L15, M18, L16)

Lighthouse 12.6.1 CLI, mobile preset (simulated slow 4G + 4× CPU), headless
Chrome on the dev machine, staging `stg.trailseries.bg`. Same engine as
PageSpeed Insights; scores from Google's servers can differ a few points.
Raw JSON reports were not committed (≈ 0.5 MB each); re-run with:

    npx lighthouse@12.6.1 <url> --output=json --only-categories=performance,accessibility,best-practices,seo

| Page | Perf | A11y | Best pr. | SEO* | FCP | LCP | TBT | CLS | DOM |
|---|---|---|---|---|---|---|---|---|---|
| `/` | 99 | 96 | 93 | 66 | 1.4 s | 1.9 s | 10 ms | 0 | 243 |
| `/traseta/` | 99 | 92 | 89 | 69 | 1.4 s | 2.0 s | 30 ms | 0 | 2,430 |
| `/the-cactus-run26-results/` | 99 | 96 | 93 | 69 | 1.5 s | 1.8 s | 0 ms | 0.001 | 2,092 |
| `/runner/?runner=…` | 99 | 96 | 93 | 69 | 1.5 s | 1.9 s | 0 ms | 0 | 423 |
| `/rezultati/` | 99 | 96 | 93 | 69 | 1.5 s | 1.9 s | 40 ms | 0 | 315 |
| `/klasiraniya/` | 97 | 97 | 93 | 69 | 1.3 s | 1.4 s | 180 ms | 0 | 719 |

\* SEO is capped only by `is-crawlable`: staging is `noindex` on purpose
(`blog_public=0`). Production must show 100 after cutover step D3.

Findings and what happened to them:

- **Touch targets** (`target-size`): pass on all six pages → audit L17 closed.
- **Best practices 93 / 89**: the only failure was a console error on every
  page — WordPress's emoji loader starts a `blob:` Worker that the host's
  `Content-Security-Policy` (no `worker-src blob:`) blocks. Emoji loader
  removed (functions.php); console clean afterwards.
- **Colour contrast** (all pages): brand blue `#00aadd` as text on white
  (2.69:1) or white on it (buttons, badges), `--tsr-blue-dk #0088bb` text
  (4.0:1), orange `#e05c1e` (3.67:1). Needs ≥ 4.5:1 → audit L19.
- **`/traseta/` track rows**: `<li role="button">` (35×: role not allowed on
  `li`, list with non-`li` children, visible text missing from the
  `aria-label`), 5 labels under 12 px → audit L20.
- Not acted on (small): unminified CSS (a few KiB, skipped by decision),
  `uses-responsive-images` on the logo (13.5 KB, its sub-size is larger),
  `/traseta/` DOM 2,430 (35 track rows with stats).

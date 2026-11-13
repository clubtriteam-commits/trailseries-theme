"""Extract the old site's official season standings (audit H1, step 2).

The owner decided (2026-11-13, audit Q2) that past seasons show the official
final standings exactly as the old site published them. Seven seasons have a
full table, season 8's table (published April 2020, heading "октомври 2019 -
декември 2020") is confirmed final, and season 12 only has a top-10 winners
list. This reads those pages from the WordPress export and writes one JSON
file per season:

    {"season": 2016, "label": "Сезон 5", "source_slug": "...", "kind": "full"|"winners",
     "has_podiums": false, "m": [{"rank": 1, "name": "...", "points": 104, "podiums": null}, ...],
     "f": [...]}

`season` is the series-season key used by the site (year the season starts
for seasons 1–8, the calendar year from season 9 = 2021). Names stay as
published. The output holds names of real people: it goes to
migration/data/ (gitignored) and, on the server, to tsr-data — never into the
repository or a docroot.

Usage (repo root):
    python -X utf8 -I migration/extract_official_standings.py \
        [--xml migration/data/runbgtrailseries.WordPress.2026-07-02.xml] \
        [--html <slug>=<saved page.html> ...] \
        [--out migration/data/official-standings]

--html lets a live copy of a page replace the export's content (used to
cross-check the export against the live old site).
"""
from __future__ import annotations

import argparse
import html
import json
import re
import sys
import xml.etree.ElementTree as ET
from pathlib import Path
from urllib.parse import unquote

NS = {'wp': 'http://wordpress.org/export/1.2/', 'content': 'http://purl.org/rss/1.0/modules/content/'}

# season key -> (old page slug, layout, label)
SOURCES = {
    2012: ('генерално-класиране', 'sections', 'Сезон 1'),
    2014: ('трети-сезон-20142015', 'side', 'Сезон 3'),
    2016: ('сезон-5-резултати', 'side', 'Сезон 5'),
    2017: ('сезон-6-генерално-класиране', 'side', 'Сезон 6'),
    2019: ('генерално-класиране-сезон-8', 'side', 'Сезон 8'),
    2022: ('генерално-класиране22', 'side', 'Сезон 10'),
    2023: ('сезон-11-резултати', 'side', 'Сезон 11'),
    2024: ('победители-за-сезон-2024', 'side', 'Сезон 12'),
    2025: ('overal-ranking', 'side', 'Сезон 13'),
}
WINNERS_ONLY = {2024}

FIELD = {
    'място': 'rank', 'rank': 'rank',
    'име': 'first', 'name': 'first', 'first name': 'first',
    'фамилия': 'last', 'last name': 'last',
    'точки': 'points', 'points': 'points',
    'подиуми': 'podiums', 'podiums': 'podiums',
}


def clean(cell: str) -> str:
    text = re.sub(r'<[^>]+>|\[[^\]]*\]', '', cell)
    return re.sub(r'\s+', ' ', html.unescape(text).replace('\xa0', ' ')).strip()


def rows_of(fragment: str) -> list[list[str]]:
    out = []
    for tr in re.findall(r'<tr[^>]*>(.*?)</tr>', fragment, re.S):
        cells = [clean(c) for c in re.findall(r'<t[dh][^>]*>(.*?)</t[dh]>', tr, re.S)]
        if any(cells):
            out.append(cells)
    return out


def entry(rank: str, name: str, points: str, podiums: str | None) -> dict:
    return {
        'rank': int(rank.rstrip('.')),
        'name': name,
        'points': int(points),
        'podiums': int(podiums) if podiums not in (None, '') else None,
    }


def parse_sections(body: str) -> dict:
    """Season 1: second table, "Генерално класиране – жени" then "– мъже"."""
    tables = re.findall(r'<table.*?</table>', body, re.S)
    rows = rows_of(tables[1])
    out, gender = {'m': [], 'f': []}, None
    for r in rows:
        head = ' '.join(r).lower()
        if 'генерално класиране' in head:
            gender = 'f' if 'жени' in head else 'm' if 'мъже' in head else gender
            continue
        if not r or not re.fullmatch(r'\d+\.?', r[0]):
            continue
        if gender is None or len(r) < 3:
            raise ValueError(f'season 1: data row before a section header: {r}')
        out[gender].append(entry(r[0], r[1], r[2], None))
    return out


def parse_side(body: str) -> dict:
    """Men left, women right; the header row names the columns of each half."""
    rows = rows_of(body)  # all rows on the page: season 6's tables are not closed properly
    header_i = next(i for i, r in enumerate(rows) if r and r[0].lower() in ('място', 'rank'))
    header = [h.lower() for h in rows[header_i]]
    starts = [i for i, h in enumerate(header) if h in ('място', 'rank')]
    if len(starts) != 2:
        raise ValueError(f'expected two halves, header {header}')
    halves = []
    for gi, start in enumerate(starts):
        end = starts[1] if gi == 0 else len(header)
        cols = {FIELD[h]: start + i for i, h in enumerate(header[start:end]) if h in FIELD}
        halves.append(cols)
    out = {'m': [], 'f': []}
    for r in rows[header_i + 1:]:
        for gender, cols in zip(('m', 'f'), halves):
            rank = r[cols['rank']] if cols['rank'] < len(r) else ''
            if not re.fullmatch(r'\d+\.?', rank):
                continue
            first = r[cols['first']] if 'first' in cols else ''
            last = r[cols['last']] if 'last' in cols and cols['last'] < len(r) else ''
            name = (first + ' ' + last).strip()
            pod = r[cols['podiums']] if 'podiums' in cols else None
            out[gender].append(entry(rank, name, r[cols['points']], pod))
    return out


def fix_ranks(season: int, data: dict) -> list[str]:
    """Known defect of the old pages, repaired and recorded as a note.

    Season 1, men: the "място" column repeats the points (59, 47, 46 …)
    instead of the place. Places are the row order of the published table.
    """
    notes = []
    for g in ('m', 'f'):
        rows = data[g]
        if rows and rows[0]['rank'] != 1 and all(r['rank'] == r['points'] for r in rows):
            for i, r in enumerate(rows, 1):
                r['rank'] = i
            notes.append(f'{"men" if g == "m" else "women"}: the old page shows the points in the place '
                         'column; places here are the row order of the published table.')
    return notes


def check(season: int, data: dict) -> tuple[list[str], list[str]]:
    """Problems stop the run; warnings are published quirks kept as they are."""
    problems, warnings = [], []
    for g in ('m', 'f'):
        rows = data[g]
        if not rows:
            problems.append(f'{season} {g}: no rows')
            continue
        if rows[0]['rank'] != 1:
            problems.append(f'{season} {g}: first rank is {rows[0]["rank"]}')
        for a, b in zip(rows, rows[1:]):
            if b['rank'] < a['rank']:
                problems.append(f'{season} {g}: place goes down at {b["rank"]}')
            elif b['points'] > a['points']:
                warnings.append(f'{season} {g}: place {b["rank"]} has {b["points"]} points, more than place {a["rank"]} ({a["points"]}) — kept as published')
        if any(not r['name'] for r in rows):
            problems.append(f'{season} {g}: empty name')
    return problems, warnings


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument('--xml', default='migration/data/runbgtrailseries.WordPress.2026-07-02.xml')
    ap.add_argument('--html', action='append', default=[], help='slug=path of a saved live page')
    ap.add_argument('--out', default='migration/data/official-standings')
    args = ap.parse_args()

    bodies, dates = {}, {}
    wanted = {slug for slug, _, _ in SOURCES.values()}
    for it in ET.parse(args.xml).getroot().iter('item'):
        slug = unquote(it.findtext('wp:post_name', default='', namespaces=NS))
        if slug in wanted and slug not in bodies and it.findtext('wp:status', namespaces=NS) == 'publish':
            bodies[slug] = it.findtext('content:encoded', default='', namespaces=NS)
            dates[slug] = (it.findtext('wp:post_modified', default='', namespaces=NS) or '')[:10]
    for pair in args.html:
        slug, path = pair.split('=', 1)
        bodies[slug] = Path(path).read_text(encoding='utf-8')
        dates[slug] = 'live'

    out_dir = Path(args.out)
    out_dir.mkdir(parents=True, exist_ok=True)
    problems, warnings = [], []
    for season, (slug, layout, label) in sorted(SOURCES.items()):
        body = bodies[slug]
        data = parse_sections(body) if layout == 'sections' else parse_side(body)
        notes = fix_ranks(season, data)
        p, w = check(season, data)
        problems += p
        warnings += w
        doc = {
            'season': season,
            'label': label,
            'source_slug': slug,
            'source_modified': dates[slug],
            'kind': 'winners' if season in WINNERS_ONLY else 'full',
            'has_podiums': any(r['podiums'] is not None for g in ('m', 'f') for r in data[g]),
            'notes': notes,
            'm': data['m'],
            'f': data['f'],
        }
        (out_dir / f'season-{season}.json').write_text(json.dumps(doc, ensure_ascii=False, indent=1), encoding='utf-8')
        print(f'{label:9} ({season}) {doc["kind"]:7} men {len(data["m"]):3} women {len(data["f"]):3}'
              f' podiums {"yes" if doc["has_podiums"] else "no ":3} source {slug} ({dates[slug]})')
    for w in warnings:
        print('WARNING', w)
    for p in problems:
        print('PROBLEM', p)
    return 1 if problems else 0


if __name__ == '__main__':
    sys.exit(main())

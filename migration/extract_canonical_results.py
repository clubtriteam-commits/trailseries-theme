#!/usr/bin/env python3
"""Extract legacy results tables into canonical JSON (TSR_Result_Set shape).

For every page in results-page-list.csv (except out-of-scope ones), parses the
WXR export content, splits it into category sections (e.g. "16КМ МЪЖЕ"),
maps the old columns onto the canonical schema

    place | first_name | last_name | team | age | bib | finish_time | status

with extra time columns becoming trailing splits, and writes one JSON file per
section to migration/data/canonical/.

Principles (see README "Iron rules"):
  - Names are copied byte-for-byte after HTML whitespace collapse; the script
    NEVER splits, re-encodes or "fixes" a name. Pages with a single combined
    name column are skipped and reported, not guessed.
  - Nothing is silently dropped: excluded rows, skipped sections and dropped
    columns all land in _manifest.csv / _issues.txt next to the output.
  - Output validates against the same rules as the PHP plugin (a finished
    runner must have place + time; unknown statuses are impossible).

Sections whose tables have no time column (season standings, points tables)
are skipped as not-race-results and reported.

Usage:
    python migration/extract_canonical_results.py
"""

from __future__ import annotations

import argparse
import csv
import hashlib
import json
import re
import sys
import urllib.parse
import xml.etree.ElementTree as ET
from html.parser import HTMLParser
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
from build_results_page_list import (  # noqa: E402
    HEADLINE_SHORTCODE,
    MIN_RESULT_ROWS,
    NS,
    OUT_OF_SCOPE_TITLES,
    POST_TYPES,
    SKIP_STATUSES,
    SPLIT_COLUMN,
    is_column_word,
    normalize_url,
)

SCHEMA_VERSION = 1
TIME_RE = re.compile(r"^\d{1,3}:[0-5]\d:[0-5]\d$")
MMSS_RE = re.compile(r"^(\d{1,2}):([0-5]\d)$")
# "01:21:27.000" or "01:21:27.675" — millisecond-precision export artefact
TIME_MS_RE = re.compile(r"^(\d{1,3}:[0-5]\d:[0-5]\d)\.\d+$")
# "1.00,45" → H:MM:SS (hour.minute,second European notation)
H_MM_SS_RE = re.compile(r"^(\d+)\.(\d{2}),(\d{2})$")
# "36,11" → 0:36:11  (MM,SS — only when minutes ≥ 10)
MM_SS_COMMA_RE = re.compile(r"^(\d{1,3}),(\d{2})$")
# "1:25:48 DNF" or "00:40:23DNF" — time cell contains time + status in one
TIME_PLUS_STATUS_RE = re.compile(
    r"^(\d{1,3}:\d{2}:\d{2})[\s]*(dnf|dns|dsq|otl)$", re.IGNORECASE
)

# Old column header -> canonical field. Casefolded comparison.
COLUMN_ALIASES: dict[str, set[str]] = {
    "place": {"#", "no", "no.", "място", "rank", "place", "pos", "поз", "позиция", "ранк"},
    # "име" = Cyrillic и-м-е (looks like "иmе" but must be fully Cyrillic)
    "first_name": {"име", "first name", "firstname"},
    "last_name": {"фамилия", "last name", "lastname", "surname", "презиме"},
    "team": {"отбор", "team", "club", "клуб", "теам", "име на отбор"},
    "age": {"възраст", "age", "години", "год"},
    # "n" appears as bib-number header on some pages (lokorsko-23-fevruari etc.)
    "bib": {"номер", "number", "bib", "стартов номер", "№", "start no", "n"},
    "finish_time": {
        "време", "finish", "time", "финиш", "finish time", "резултат", "final", "финал",
        # typo: "Fnish" (iran-run19-results)
        "fnish",
        # total elapsed = finish time (birthday-run-3)
        "total",
        # "from start" = elapsed time from start = finish time; NOT a gap column
        "from start", "time from start",
        # race-named finish columns (golyam-sechko-run19, baba-marta-run22, etc.)
        "buhovo: finish", "lap: finish",
    },
    "status": {"статус", "status"},
}
# Lap columns become split columns, keeping their label.
# Matches "Lap", "Lap 1", "lap 11.5km", "Lap10.75" etc.
LAP_COLUMN = re.compile(r"^lap[\s_]?\d*(?:[.,]\d+)?(?:\s*km)?$", re.IGNORECASE)
# Pattern-based finish_time detection — catches race-named columns and
# distance-prefixed columns that alias lookups can't handle exhaustively.
# Matches: "15км/Финал", "10км / Finish", "17KM Finish", "ОББ Vertical Run: Финал",
#          "something: Finish", "Fnish" (already in aliases, redundant but harmless).
FINISH_TIME_RE = re.compile(
    r"""
    (?:
        # "15км/Финал" or "17KM Finish" — distance-prefixed finish
        \d+(?:[.,]\d+)?\s*(?:km|км)[\s/]+(?:финал|finish|fnish) |
        # "Race Name: Финал" or "Label: Finish"
        .+:\s*(?:финал|finish)
    )$
    """,
    re.IGNORECASE | re.VERBOSE,
)
# Pattern-based split detection — catches race-named intermediate-time columns.
SPLIT_LABEL_RE = re.compile(
    r"""
    (?:
        # "Race Name: Lap" or "Race Name: Старт"
        .+:\s*(?:lap|старт|start)$
    )
    """,
    re.IGNORECASE | re.VERBOSE,
)
# Columns that exist in old tables but not in the canonical schema. Dropping
# them is a documented migration decision; the manifest records every drop.
DROPPED_COLUMNS = {
    "пол", "gender", "sex", "държава", "country", "km/ч", "км/ч", "km/h",
    "скорост", "speed", "точки", "points", "подиуми", "podiums",
    "категория", "category", "дата", "date",
    # gap-to-leader columns — derivable from finish times
    "gap", "from first", "time from 1st", "dif", "dif +",
    "от първия", "oт първия",  # Bulgarian "from first" variants
    "времe от първият", "времe от първия", "изоставане",
    "from first one",  # birthday-run-3 gap column
}
# A single combined-name column means the extractor would have to SPLIT a
# name, which is forbidden — such sections are skipped and reported.
FULL_NAME_COLUMNS = {"name", "име и фамилия", "имена", "участник", "runner", "athlete"}

STATUS_MARKERS = {
    "dnf": "DNF", "не финишира": "DNF", "отпаднал": "DNF",
    "dns": "DNS", "не стартира": "DNS",
    "dsq": "DSQ", "dq": "DSQ", "дисквалифициран": "DSQ",
    "otl": "OTL", "извън лимит": "OTL",
    # explicit non-finish markers that are NOT standard abbreviations
    "n/a": "DNF", "na": "DNF",
    "-": "DNF", "–": "DNF", "—": "DNF",
}


def canonical_field(header: str) -> str | None:
    cf = header.casefold().strip()
    for field, aliases in COLUMN_ALIASES.items():
        if cf in aliases:
            return field
    # Pattern-based fallback for finish_time: "15км/Финал", "17KM Finish",
    # "Race Name: Финал", "Lap: Finish", etc.
    if FINISH_TIME_RE.search(cf):
        return "finish_time"
    return None


def clean(text: str) -> str:
    """HTML whitespace collapse only — never touches actual characters."""
    return re.sub(r"\s+", " ", text).strip()


def slugify(text: str) -> str:
    text = re.sub(r"[^\w\s-]", "", text.casefold(), flags=re.UNICODE)
    return re.sub(r"[\s_-]+", "-", text).strip("-") or "untitled"


def normalize_time(text: str) -> tuple[str | None, str | None]:
    """-> (canonical H:MM:SS or None, status marker or None).

    Handles the full variety found in 13 seasons of export data:
      H:MM:SS              standard
      H:MM:SS.mmm          millisecond-precision export artefact → strip .mmm
      H:MM:SS DNF          time cell contains time AND status → return (time, status)
      H.MM,SS              European dot-comma notation (e.g. 1.00,45 → 1:00:45)
      MM,SS                short race comma notation (e.g. 36,11 → 0:36:11, only ≥10 min)
      MM:SS                short race colon notation (only ≥10 min, to avoid ambiguity)
      n/a / - / –          explicit non-finish markers → DNF
    """
    value = text.strip()
    if not value:
        return None, None

    # millisecond precision: "01:21:27.000" → "01:21:27"
    ms_m = TIME_MS_RE.match(value)
    if ms_m:
        return ms_m.group(1), None

    # combined time + status in one cell: "1:25:48 DNF" or "00:40:23DSQ"
    ts_m = TIME_PLUS_STATUS_RE.match(value)
    if ts_m:
        t, st = ts_m.group(1), ts_m.group(2).upper()
        return t, STATUS_MARKERS.get(st.casefold(), st)

    # strip "/distance" suffix: "00:35:43/5км" → "00:35:43"
    if "/" in value:
        head = value.split("/")[0].strip()
        if TIME_RE.match(head):
            return head, None

    # known status markers (n/a, -, –, dnf, dns, etc.)
    marker = STATUS_MARKERS.get(value.casefold())
    if marker:
        return None, marker

    # standard H:MM:SS
    if TIME_RE.match(value):
        return value, None

    # European H.MM,SS: "1.00,45" → "1:00:45"
    h_m = H_MM_SS_RE.match(value)
    if h_m:
        h, m, s = h_m.groups()
        if 0 <= int(m) <= 59 and 0 <= int(s) <= 59:
            return f"{h}:{m}:{s}", None

    # MM,SS with comma: "36,11" → "0:36:11"  (only when minutes ≥ 10)
    c_m = MM_SS_COMMA_RE.match(value)
    if c_m and int(c_m.group(1)) >= 10 and int(c_m.group(2)) <= 59:
        mins = int(c_m.group(1))
        secs = int(c_m.group(2))
        # guard: if minutes > 59 it's actually H,MM — reparse as H.MM,SS won't help
        if mins <= 59:
            return f"0:{c_m.group(1)}:{c_m.group(2)}", None

    # MM:SS with colon: "45:32" → "0:45:32"  (only when minutes ≥ 10)
    mm_m = MMSS_RE.match(value)
    if mm_m and int(mm_m.group(1)) >= 10:
        return f"0:{value}", None

    return None, None  # unparseable (including ambiguous "1:15") → issue


class RowStream(HTMLParser):
    """Emit ('heading', text) and ('row', [(text, colspan), ...]) in order."""

    def __init__(self) -> None:
        super().__init__(convert_charrefs=True)
        self.events: list[tuple[str, object]] = []
        self._table_depth = 0
        self._row: list[tuple[str, int]] | None = None
        self._cell: list[str] | None = None
        self._cell_colspan = 1
        self._heading: list[str] | None = None

    def handle_starttag(self, tag: str, attrs: list[tuple[str, str | None]]) -> None:
        attr = dict(attrs)
        if tag == "table":
            self._flush_row()
            self._table_depth += 1
        elif tag == "tr" and self._table_depth:
            self._flush_row()
            self._row = []
        elif tag in ("td", "th") and self._row is not None:
            self._flush_cell()
            colspan = attr.get("colspan") or "1"
            self._cell = []
            self._cell_colspan = int(colspan) if colspan.isdigit() else 1
        elif tag in ("h1", "h2", "h3", "h4", "h5", "h6"):
            self._heading = []

    def handle_endtag(self, tag: str) -> None:
        if tag == "table" and self._table_depth:
            self._flush_row()
            self._table_depth -= 1
        elif tag in ("td", "th"):
            self._flush_cell()
        elif tag == "tr":
            self._flush_row()
        elif tag in ("h1", "h2", "h3", "h4", "h5", "h6") and self._heading is not None:
            text = clean("".join(self._heading))
            if text:
                self.events.append(("heading", text))
            self._heading = None

    def handle_data(self, data: str) -> None:
        if self._cell is not None:
            self._cell.append(data)
        if self._heading is not None:
            self._heading.append(data)

    def close(self) -> None:
        super().close()
        self._flush_row()

    def _flush_cell(self) -> None:
        if self._cell is not None and self._row is not None:
            self._row.append((clean("".join(self._cell)), self._cell_colspan))
        self._cell = None

    def _flush_row(self) -> None:
        self._flush_cell()
        if self._row:
            self.events.append(("row", self._row))
        self._row = None


class Section:
    def __init__(self, category: str) -> None:
        self.category = category
        self.columns: list[str | None] = []      # canonical field per position, None = dropped
        self.split_labels: list[str] = []
        self.split_positions: list[int] = []
        self.dropped: list[str] = []
        self.rows: list[dict] = []
        self.issues: list[str] = []
        self.has_time_column = False


class PageExtractor:
    def __init__(self, category_map: dict[str, tuple[str, str]]) -> None:
        self.category_map = category_map

    def extract(self, content: str) -> list[Section]:
        # [headline] shortcodes carry section names on some pages.
        content = HEADLINE_SHORTCODE.sub(lambda m: f"<h2>{m.group(1)}</h2>", content)
        stream = RowStream()
        stream.feed(content)
        stream.close()

        sections: list[Section] = []
        current: Section | None = None
        pending_category = ""

        for kind, payload in stream.events:
            if kind == "heading":
                pending_category = str(payload)
                continue

            cells: list[tuple[str, int]] = payload  # type: ignore[assignment]
            texts = self._expand(cells)
            non_empty = [t for t in texts if t]
            if not non_empty:
                continue

            # a lone-cell row is never data — it's a section name ("5.5км Мъже")
            if len(cells) == 1 and not texts[0].isdigit():
                pending_category = non_empty[0]
                continue

            spans_row = len(cells) >= 3 or max(c for _, c in cells) >= 3
            if len(non_empty) == 1 and spans_row and not self._is_data_row(texts, current):
                pending_category = non_empty[0]
                continue

            column_words = sum(1 for t in non_empty if is_column_word(t))
            if column_words >= 3:
                category_position = None
                if texts[0] and not is_column_word(texts[0]):
                    pending_category = texts[0]
                    category_position = 0
                elif not texts[0] and len(texts) > 1 and texts[1] and not is_column_word(texts[1]):
                    # "(empty) | жени 7км | номер | време | ..." — category sits
                    # above a combined full-name column
                    pending_category = texts[1]
                    category_position = 1
                current = self._new_section(pending_category or (current.category if current else ""))
                self._map_columns(current, texts, category_position)
                sections.append(current)
                pending_category = ""
                continue

            if current is None:
                # data before any header row — cannot be mapped safely
                orphan = self._new_section(pending_category)
                orphan.issues.append(f"data row before any column-header row: {texts[:4]}...")
                sections.append(orphan)
                current = orphan
                continue
            self._parse_data_row(current, texts)

        return [s for s in sections if s.rows or s.issues]

    @staticmethod
    def _expand(cells: list[tuple[str, int]]) -> list[str]:
        texts: list[str] = []
        for text, colspan in cells:
            texts.append(text)
            texts.extend("" for _ in range(colspan - 1))
        return texts

    @staticmethod
    def _is_data_row(texts: list[str], current: Section | None) -> bool:
        # guards against a lone leftover cell in a data row being mistaken
        # for a section header once a mapping is active
        return current is not None and bool(current.columns) and texts[0].isdigit()

    def _new_section(self, category: str) -> Section:
        return Section(clean(category))

    def _map_columns(self, section: Section, headers: list[str], category_position: int | None = None) -> None:
        for position, header in enumerate(headers):
            cf = header.casefold().strip()
            field = canonical_field(header)
            if position == 0 and category_position == 0:
                # observed convention: category name sits where "place" belongs
                field = "place"
            elif position == category_position == 1:
                # category above a combined full-name column — splitting names
                # is forbidden, so the whole section is skipped
                section.issues.append(
                    f'combined name column under category "{header}" — section skipped, names must not be split'
                )
                field = None
            elif not header:
                field = None
            elif field is None:
                if LAP_COLUMN.match(cf):
                    section.split_labels.append(header)
                    section.split_positions.append(position)
                    field = "__split__"
                elif cf in FULL_NAME_COLUMNS:
                    section.issues.append(
                        f'combined name column "{header}" — section skipped, names must not be split'
                    )
                    field = None
                elif cf in DROPPED_COLUMNS:
                    section.dropped.append(header)
                    field = None
                elif SPLIT_COLUMN.match(cf):
                    section.split_labels.append(header)
                    section.split_positions.append(position)
                    field = "__split__"
                elif SPLIT_LABEL_RE.search(cf):
                    # Race-named split: "Buhovo: Lap", "ОББ Vertical Run: Старт"
                    section.split_labels.append(header)
                    section.split_positions.append(position)
                    field = "__split__"
                else:
                    section.dropped.append(header)
                    section.issues.append(f'unknown column "{header}" dropped')
                    field = None
            if field == "finish_time":
                section.has_time_column = True
            section.columns.append(field if field != "__split__" else None)
        if any("names must not be split" in i for i in section.issues):
            section.columns = []  # disable row parsing entirely

    def _parse_data_row(self, section: Section, texts: list[str]) -> None:
        if not section.columns:
            return
        if not section.has_time_column:
            return  # standings/points table — section reported, rows not extracted
        width = len(section.columns)
        if len(texts) > width + 2 or len(texts) < width - 2:
            section.issues.append(f"row width {len(texts)} vs header {width}, excluded: {texts[:4]}...")
            return
        texts = (texts + [""] * width)[:width]

        def field(name: str) -> str:
            try:
                return texts[section.columns.index(name)]
            except ValueError:
                return ""

        first_name, last_name = field("first_name"), field("last_name")
        if not first_name and not last_name:
            if any(t for t in texts):
                section.issues.append(f"row without a name excluded: {texts[:5]}...")
            return

        # Skip placeholder / template rows left by race organisers in tables
        if first_name.casefold() in {"first name", "first", "участник"} or \
                last_name.casefold() in {"last name", "фамилия"}:
            return

        place_raw = field("place")
        place = int(place_raw) if place_raw.isdigit() else None
        age_raw = field("age")
        age = int(age_raw) if age_raw.isdigit() else None
        if age_raw and age is None:
            section.issues.append(f'unparseable age "{age_raw}" for {first_name} {last_name} -> null')

        time_value, marker = normalize_time(field("finish_time"))
        status_raw = field("status").casefold()
        status = STATUS_MARKERS.get(status_raw) or marker
        if status is None:
            if time_value:
                status = "FIN"
                if place is None:
                    # No place column or empty cell — assign from row order.
                    # Row order is 1-based and counted from already-committed rows.
                    place = len(section.rows) + 1
            else:
                # Named runner with no time and no explicit status: treat as DNF.
                # DNS runners are rarely listed; listed runners with no time are almost
                # always non-finishers (DNF).
                status = "DNF"

        splits: list[str | None] = []
        for position in section.split_positions:
            split_value, _ = normalize_time(texts[position] if position < len(texts) else "")
            splits.append(split_value)

        section.rows.append(
            {
                "place": place if status == "FIN" or place else None,
                "first_name": first_name,
                "last_name": last_name,
                "team": field("team"),
                "age": age,
                "bib": field("bib"),
                "finish_time": time_value if status == "FIN" else "",
                "status": status,
                "splits": splits,
            }
        )


def names_sha256(rows: list[dict]) -> str:
    blob = b"".join(
        row["first_name"].encode() + b"\x1f" + row["last_name"].encode() + b"\x1e" for row in rows
    )
    return hashlib.sha256(blob).hexdigest()


def load_category_map(path: str) -> dict[str, tuple[str, str]]:
    mapping: dict[str, tuple[str, str]] = {}
    with open(path, encoding="utf-8-sig") as f:
        for row in csv.DictReader(f):
            mapping[row["raw_header"].casefold()] = (row["canonical_distance_km"], row["gender"])
    return mapping


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--xml", default="migration/data/raw/runbgtrailseries.WordPress.2026-07-02.xml")
    parser.add_argument("--pages", default="migration/results-page-list.csv")
    parser.add_argument("--category-map", default="migration/category-map.csv")
    parser.add_argument("--out-dir", default="migration/data/canonical")
    args = parser.parse_args()

    out_dir = Path(args.out_dir)
    out_dir.mkdir(parents=True, exist_ok=True)

    category_map = load_category_map(args.category_map)
    with open(args.pages, encoding="utf-8-sig") as f:
        page_list = {(r["page_title"], r["slug"]): r for r in csv.DictReader(f)}

    extractor = PageExtractor(category_map)
    manifest: list[dict] = []
    issue_lines: list[str] = []
    used_names: set[str] = set()
    written = skipped_oos = 0

    for item in ET.parse(args.xml).getroot().findall("./channel/item"):
        post_type = item.findtext("wp:post_type", "", NS)
        status = item.findtext("wp:status", "", NS)
        if post_type not in POST_TYPES or status in SKIP_STATUSES:
            continue
        title = item.findtext("title") or ""
        slug = urllib.parse.unquote(item.findtext("wp:post_name", "", NS) or "")
        listed = page_list.get((title, slug if status == "publish" else ""))
        if listed is None:
            continue
        if "out of scope" in listed["notes"]:
            skipped_oos += 1
            continue

        content = item.findtext("content:encoded", "", NS) or ""
        page_slug = slug or slugify(title)

        for section in extractor.extract(content):
            raw = section.category
            distance, gender = category_map.get(raw.casefold(), ("", ""))
            cat_part = (
                f"{distance}km-{gender.casefold()}" if distance and gender else slugify(raw)[:40] if raw else "all"
            )
            base = f"{page_slug}__{cat_part}"
            name = base
            counter = 2
            while name in used_names:
                name = f"{base}-{counter}"
                counter += 1
            used_names.add(name)

            file_name = ""
            if section.rows:
                data = {
                    "schema_version": SCHEMA_VERSION,
                    "split_labels": section.split_labels,
                    "rows": section.rows,
                }
                file_name = f"{name}.json"
                (out_dir / file_name).write_text(
                    json.dumps(data, ensure_ascii=False, indent=1) + "\n", encoding="utf-8"
                )
                written += 1
            elif not section.has_time_column and section.columns:
                section.issues.insert(0, "no finish-time column — standings/points table, not extracted")

            manifest.append(
                {
                    "file": file_name,
                    "url": listed["url"],
                    "slug": page_slug,
                    "page_title": title,
                    "category_raw": raw,
                    "distance_km": distance,
                    "gender": gender,
                    "rows": len(section.rows),
                    "split_labels": " | ".join(section.split_labels),
                    "dropped_columns": " | ".join(section.dropped),
                    "issue_count": len(section.issues),
                    "names_sha256": names_sha256(section.rows) if section.rows else "",
                }
            )
            for issue in section.issues:
                issue_lines.append(f"{page_slug} [{raw or 'no category'}]: {issue}")

    with open(out_dir / "_manifest.csv", "w", newline="", encoding="utf-8-sig") as f:
        writer = csv.DictWriter(f, fieldnames=list(manifest[0].keys()))
        writer.writeheader()
        writer.writerows(manifest)
    (out_dir / "_issues.txt").write_text("\n".join(issue_lines) + "\n", encoding="utf-8")

    total_rows = sum(m["rows"] for m in manifest)
    print(f"Wrote {written} canonical JSON files ({total_rows} result rows) to {out_dir}/")
    print(f"Sections seen: {len(manifest)}; out-of-scope pages skipped: {skipped_oos}")
    print(f"Issues: {len(issue_lines)} (see {out_dir}/_issues.txt)")
    return 0


if __name__ == "__main__":
    sys.exit(main())

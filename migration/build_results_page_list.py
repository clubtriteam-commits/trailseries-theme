#!/usr/bin/env python3
"""Build the definitive results-page work-list from the WP XML export.

Parses the WXR export, keeps every page/post whose content contains at least
one HTML table with >= MIN_RESULT_ROWS rows, cross-references each with the
crawled url-inventory.csv (ADR-003), and writes results-page-list.csv:

    url, slug, page_title, table_count, category_headers, notes

- table_count:       number of tables with >= MIN_RESULT_ROWS rows.
- category_headers:  section names found on the page — colspan header cells
                     inside tables (e.g. "Класиране жени"), [headline]
                     shortcode texts, and h1-h6 headings, deduplicated in
                     document order.
- notes:             anything the migration must know: non-publish status,
                     URL missing from the crawl inventory, or crawl/export
                     disagreement on the table flag.

Stdlib only. Usage:
    python migration/build_results_page_list.py
"""

from __future__ import annotations

import argparse
import csv
import re
import sys
import urllib.parse
import xml.etree.ElementTree as ET
from html.parser import HTMLParser

MIN_RESULT_ROWS = 5
POST_TYPES = ("page", "post")
SKIP_STATUSES = ("trash", "auto-draft")

NS = {
    "content": "http://purl.org/rss/1.0/modules/content/",
    "wp": "http://wordpress.org/export/1.2/",
}

HEADLINE_SHORTCODE = re.compile(r"\[headline[^\]]*\](.*?)\[/headline\]", re.DOTALL)

# Column labels seen in the old site's header rows (Bulgarian and English).
# Used to recognize a column-header row; the first cell of such a row often
# smuggles the section name instead of a label ("19КМ МЪЖЕ | НОМЕР | ИМЕ | ...").
KNOWN_COLUMN_WORDS = {
    "#", "no", "no.", "място", "номер", "име", "фамилия", "отбор", "възраст",
    "пол", "време", "точки", "подиуми", "км/ч", "km/ч", "km/h", "статус",
    "rank", "place", "first name", "last name", "name", "team", "club",
    "country", "age", "gender", "sex", "number", "bib", "finish", "time",
    "points", "podiums", "status", "категория", "category",
}
SPLIT_COLUMN = re.compile(r"^\d+([.,]\d+)?\s*(км|km)$", re.IGNORECASE)


def is_column_word(text: str) -> bool:
    text = text.casefold().strip()
    return text in KNOWN_COLUMN_WORDS or bool(SPLIT_COLUMN.match(text))


class ContentParser(HTMLParser):
    """Extract table row counts and section-header texts from post content.

    Section headers inside tables come in two shapes on the old site:
      - a row whose only non-empty cell is the first one ("16.7KM", rest empty)
      - a single cell spanning the row via colspan ("Класиране жени")
    Ordinary data rows also carry stray colspan attributes, so headers are
    detected per ROW (exactly one non-empty cell), never per cell. The markup
    is dirty (unclosed nested <table> tags), so table state is a stack.
    """

    def __init__(self) -> None:
        super().__init__(convert_charrefs=True)
        self.table_row_counts: list[int] = []   # final count per closed table
        self.headers: list[str] = []            # section rows + headings, in order
        self._open_tables: list[int] = []
        self._row_cells: list[tuple[str, int]] | None = None  # (text, colspan) per cell
        self._cell: list[str] | None = None
        self._cell_colspan = 1
        self._heading: list[str] | None = None

    def handle_starttag(self, tag: str, attrs: list[tuple[str, str | None]]) -> None:
        attr = dict(attrs)
        if tag == "table":
            self._flush_row()
            self._open_tables.append(0)
        elif tag == "tr" and self._open_tables:
            self._flush_row()
            self._open_tables[-1] += 1
            self._row_cells = []
        elif tag in ("td", "th") and self._row_cells is not None:
            self._flush_cell()
            colspan = attr.get("colspan") or "1"
            self._cell = []
            self._cell_colspan = int(colspan) if colspan.isdigit() else 1
        elif tag in ("h1", "h2", "h3", "h4", "h5", "h6"):
            self._heading = []

    def handle_endtag(self, tag: str) -> None:
        if tag == "table" and self._open_tables:
            self._flush_row()
            self.table_row_counts.append(self._open_tables.pop())
        elif tag in ("td", "th"):
            self._flush_cell()
        elif tag == "tr":
            self._flush_row()
        elif tag in ("h1", "h2", "h3", "h4", "h5", "h6") and self._heading is not None:
            self._add_header("".join(self._heading))
            self._heading = None

    def handle_data(self, data: str) -> None:
        if self._cell is not None:
            self._cell.append(data)
        if self._heading is not None:
            self._heading.append(data)

    def close(self) -> None:
        super().close()
        # Dirty markup often leaves tables unclosed — count them anyway.
        self._flush_row()
        while self._open_tables:
            self.table_row_counts.append(self._open_tables.pop())

    def _flush_cell(self) -> None:
        if self._cell is not None and self._row_cells is not None:
            text = re.sub(r"\s+", " ", "".join(self._cell)).strip()
            self._row_cells.append((text, self._cell_colspan))
        self._cell = None

    def _flush_row(self) -> None:
        self._flush_cell()
        cells = self._row_cells
        self._row_cells = None
        if not cells:
            return
        non_empty = [text for text, _ in cells if text]
        spans_row = len(cells) >= 3 or max(colspan for _, colspan in cells) >= 3
        if len(non_empty) == 1 and spans_row:
            self._add_header(non_empty[0])
            return
        # Column-header row whose first cell carries the section name instead
        # of a label: "19КМ МЪЖЕ | НОМЕР | ИМЕ | ФАМИЛИЯ | ..."
        column_words = sum(1 for text in non_empty if is_column_word(text))
        if column_words >= 3 and cells[0][0] and not is_column_word(cells[0][0]):
            self._add_header(cells[0][0])

    def _add_header(self, text: str) -> None:
        text = re.sub(r"\s+", " ", text).strip()
        # Plausible section names only: 2-100 chars, not a bare number/time.
        if 2 <= len(text) <= 100 and not re.fullmatch(r"[\d\s:.,-]+", text):
            self.headers.append(text)


def normalize_url(url: str) -> str:
    """Comparison key: decoded path, no trailing slash, canonical host."""
    split = urllib.parse.urlsplit(url.strip())
    host = split.netloc.lower().removeprefix("www.")
    path = urllib.parse.unquote(split.path).rstrip("/")
    return f"{host}{path}"


def dedupe_keep_order(values: list[str]) -> list[str]:
    seen: set[str] = set()
    out: list[str] = []
    for value in values:
        key = value.casefold()
        if key not in seen:
            seen.add(key)
            out.append(value)
    return out


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--xml", default="migration/data/raw/runbgtrailseries.WordPress.2026-07-02.xml")
    parser.add_argument("--inventory", default="migration/url-inventory.csv")
    parser.add_argument("--out", default="migration/results-page-list.csv")
    args = parser.parse_args()

    inventory: dict[str, dict[str, str]] = {}
    with open(args.inventory, encoding="utf-8-sig") as f:
        for row in csv.DictReader(f):
            inventory[normalize_url(row["url"])] = row

    rows: list[dict[str, object]] = []
    for item in ET.parse(args.xml).getroot().findall("./channel/item"):
        post_type = item.findtext("wp:post_type", "", NS)
        status = item.findtext("wp:status", "", NS)
        if post_type not in POST_TYPES or status in SKIP_STATUSES:
            continue

        content = item.findtext("content:encoded", "", NS) or ""
        if "<table" not in content:
            continue

        page = ContentParser()
        page.feed(content)
        page.close()
        table_count = sum(1 for rows_in_table in page.table_row_counts if rows_in_table >= MIN_RESULT_ROWS)
        if table_count == 0:
            continue

        link = item.findtext("link") or ""
        slug = urllib.parse.unquote(item.findtext("wp:post_name", "", NS) or "")
        headlines = [re.sub(r"\s+", " ", h).strip() for h in HEADLINE_SHORTCODE.findall(content)]
        headers = dedupe_keep_order([h for h in headlines if h] + page.headers)

        notes: list[str] = []
        crawled = None
        if status != "publish":
            # Draft/private exports carry a placeholder link (often the bare
            # homepage) — no public URL exists, so never cross-reference.
            notes.append(f"status: {status} (no public URL)")
        else:
            crawled = inventory.get(normalize_url(link))
            if crawled is None:
                notes.append("not in url-inventory.csv")
            elif crawled["has_results_table"] != "yes":
                notes.append("crawl marked has_results_table=no")

        rows.append(
            {
                "url": crawled["url"] if crawled else (link if status == "publish" else ""),
                "slug": slug,
                "page_title": item.findtext("title") or "",
                "table_count": table_count,
                "category_headers": " | ".join(headers),
                "notes": "; ".join(notes),
            }
        )

    rows.sort(key=lambda r: str(r["url"]))
    with open(args.out, "w", newline="", encoding="utf-8-sig") as f:
        writer = csv.DictWriter(f, fieldnames=["url", "slug", "page_title", "table_count", "category_headers", "notes"])
        writer.writeheader()
        writer.writerows(rows)

    flagged_in_crawl = sum(1 for r in inventory.values() if r["has_results_table"] == "yes")
    with_notes = sum(1 for r in rows if r["notes"])
    print(f"Wrote {len(rows)} results pages to {args.out} ({with_notes} with notes).")
    print(f"For reference: crawl inventory flagged {flagged_in_crawl} URLs as having a results table.")
    return 0


if __name__ == "__main__":
    sys.exit(main())

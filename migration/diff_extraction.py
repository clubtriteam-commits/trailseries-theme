#!/usr/bin/env python3
"""Compare two extraction runs page by page — the safety net for parser changes.

Before changing extract_canonical_results.py: extract to a scratch directory
(OLD); after the change extract again (NEW); then

    python -X utf8 migration/diff_extraction.py OLD NEW [--expect slug,slug,...]

Prints every page whose sections differ (raw category, occurrence, rows,
file, override, names hash) and the total row count of both runs. Exit 1 when
the total row count changed (no runner may be gained or lost by a parser
change unless that is the point) or when a page outside --expect changed.
Both directories live under migration/data/ (gitignored: personal data).
"""

from __future__ import annotations

import argparse
import csv
import sys
from collections import defaultdict
from pathlib import Path

FIELDS = ("names_sha256", "rows", "file", "override_applied")


def load(d: Path) -> dict[tuple[str, str, str], dict]:
    rows = csv.DictReader((d / "_manifest.csv").open(encoding="utf-8-sig"))
    return {(r["slug"], r["category_raw"], r["occurrence"]): r for r in rows}


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("old", type=Path)
    ap.add_argument("new", type=Path)
    ap.add_argument("--expect", default="", help="Comma-separated page slugs expected to change")
    ap.add_argument("--allow-row-change", action="store_true", help="Do not fail on a changed total row count")
    args = ap.parse_args()

    a, b = load(args.old), load(args.new)
    pages: dict[str, list[str]] = defaultdict(list)
    for k in sorted(set(a) | set(b)):
        x, y = a.get(k), b.get(k)
        if x and y and all(x[f] == y[f] for f in FIELDS):
            continue
        pages[k[0]].append(
            f"    raw={k[1]!r:34} occ={k[2]}  rows {x and x['rows']} -> {y and y['rows']}"
            f"  override {(x or {}).get('override_applied', '')!r} -> {(y or {}).get('override_applied', '')!r}"
        )
    for page, lines in pages.items():
        print(f"== {page}")
        print("\n".join(lines))

    rows_a = sum(int(r["rows"]) for r in a.values())
    rows_b = sum(int(r["rows"]) for r in b.values())
    expected = {s for s in args.expect.split(",") if s}
    unexpected = sorted(set(pages) - expected) if expected else []
    print(f"\n{len(pages)} page(s) changed; total rows {rows_a} -> {rows_b}")
    failed = False
    if rows_a != rows_b and not args.allow_row_change:
        print("FAIL: total row count changed")
        failed = True
    if unexpected:
        print("FAIL: pages changed outside --expect: " + ", ".join(unexpected))
        failed = True
    return 1 if failed else 0


if __name__ == "__main__":
    sys.exit(main())

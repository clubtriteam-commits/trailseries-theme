#!/usr/bin/env python3
"""Byte-verify the results data set against the WXR source (audit H10).

Checks the data set that is actually imported — canonical_fixed, which mixes
the July extraction, later re-extractions, a delta from a later export and a
few sections kept under older file names — not a fresh scratch extraction.
Sections are matched by (page slug, category_raw, occurrence), never by file
name, using the extractor's own per-page pipeline (extract_items(): category
overrides incl. __DROP__, page-title overrides, place-restart splits), so the
two can never drift apart.

  1. FILE INTEGRITY  — every manifest row with a file: the JSON exists, its
     row count and recomputed names SHA-256 match the manifest.
  2. SOURCE FIDELITY — the same section re-extracted from the WXR export(s)
     has byte-identical names (hex dump of the first differing row on
     failure). A different file name is reported as a note, not a failure.
  3. COVERAGE        — every section the source yields with rows is in the
     data set, and every data-set section is reproducible from the source.
     (Would have caught the relay/kids sections lost in August.)

The published side (WordPress posts vs. this manifest) is checked on the
server with `wp tsr verify-import`.

The data set contains personal data and lives outside the repository; copy
canonical_fixed from tsr-data to migration/data/ (gitignored) first.

Stdlib + local imports only. Exit code 0 = PASS, 1 = FAIL.

Usage:
    python -X utf8 migration/verify_names.py --manifest migration/data/canonical_fixed/_manifest.csv
    python -X utf8 migration/verify_names.py --manifest ... --slug iran-run18
"""

from __future__ import annotations

import argparse
import csv
import json
import sys
import xml.etree.ElementTree as ET
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
from extract_canonical_results import (  # noqa: E402
    DEFAULT_XML,
    extract_items,
    load_category_map,
    load_overrides,
    names_sha256,
)


def key(row: dict) -> tuple[str, str, str]:
    return (row["slug"], row["category_raw"], str(row["occurrence"]))


def first_name_diff(expected: list[dict], actual: list[dict]) -> str:
    for i, (e, a) in enumerate(zip(expected, actual)):
        for field in ("first_name", "last_name"):
            if e[field] != a[field]:
                return (
                    f"      row {i + 1} {field}:\n"
                    f"        data set ({len(e[field].encode())}B): {e[field].encode()!r}\n"
                    f"        source   ({len(a[field].encode())}B): {a[field].encode()!r}"
                )
    return f"      row count differs: data set {len(expected)}, source {len(actual)}"


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--manifest", required=True, help="_manifest.csv of the data set (local copy of canonical_fixed)")
    ap.add_argument("--json-dir", default=None, help="Directory of the JSON files (default: next to the manifest)")
    ap.add_argument("--xml", action="append", default=None, help="WXR export (repeatable; default: July + 2026-11-13)")
    ap.add_argument("--pages", default="migration/results-page-list.csv")
    ap.add_argument("--category-map", default="migration/category-map.csv")
    ap.add_argument("--overrides", default="migration/category-overrides.csv")
    ap.add_argument("--titles", default="migration/page-title-overrides.csv")
    ap.add_argument("--slug", default=None, help="Only pages whose slug contains this string")
    ap.add_argument("-v", "--verbose", action="store_true", help="Also print notes (renamed files)")
    args = ap.parse_args()

    manifest_path = Path(args.manifest)
    json_dir = Path(args.json_dir) if args.json_dir else manifest_path.parent
    xmls = args.xml or list(DEFAULT_XML)

    data_rows = list(csv.DictReader(manifest_path.open(encoding="utf-8-sig")))
    title_overrides: dict[str, str] = {}
    if Path(args.titles).exists():
        title_overrides = {r["slug"]: r["display_title"] for r in csv.DictReader(open(args.titles, encoding="utf-8-sig"))}
    page_list = {(r["page_title"], r["slug"]): r for r in csv.DictReader(open(args.pages, encoding="utf-8-sig"))}
    items = [i for x in xmls for i in ET.parse(x).getroot().findall("./channel/item")]
    fresh_rows, fresh_sections, _, _ = extract_items(
        items, page_list, load_category_map(args.category_map), load_overrides(args.overrides), title_overrides
    )
    fresh = {key(r): (r, s) for r, s in zip(fresh_rows, fresh_sections)}

    def wanted(slug: str) -> bool:
        return not args.slug or args.slug in slug

    failures: list[str] = []
    notes: list[str] = []
    checked_files = checked_rows = 0

    # 1 + 2: every data-set section with a file
    for row in data_rows:
        if not row["file"] or not wanted(row["slug"]):
            continue
        k = key(row)
        label = f"{row['slug']} [{row['category_raw'] or 'no category'}] occ {row['occurrence']}"
        path = json_dir / row["file"]
        if not path.exists():
            failures.append(f"FILE   {label}: {row['file']} missing")
            continue
        rows = json.loads(path.read_text(encoding="utf-8"))["rows"]
        checked_files += 1
        checked_rows += len(rows)
        if len(rows) != int(row["rows"]) or names_sha256(rows) != row["names_sha256"]:
            failures.append(f"FILE   {label}: {row['file']} does not match its manifest row (rows/hash)")
            continue
        if k not in fresh:
            failures.append(f"SOURCE {label}: not reproducible from the WXR source")
            continue
        src_row, src_section = fresh[k]
        if names_sha256(src_section.rows) != row["names_sha256"]:
            failures.append(f"SOURCE {label}: names differ from the source\n" + first_name_diff(rows, src_section.rows))
            continue
        if src_row["file"] != row["file"]:
            notes.append(f"note   {label}: file {row['file']} (today's extractor would name it {src_row['file']})")

    # 3: coverage — every source section with rows is in the data set
    data_keys = {key(r) for r in data_rows if r["file"]}
    for k, (src_row, src_section) in fresh.items():
        if src_section.rows and k not in data_keys and wanted(k[0]):
            failures.append(
                f"COVER  {k[0]} [{k[1] or 'no category'}] occ {k[2]}: {len(src_section.rows)} rows in the source, "
                "missing from the data set"
            )

    if args.verbose:
        print("\n".join(notes))
    print(f"Checked {checked_files} files, {checked_rows} rows against {len(xmls)} export(s); "
          f"{len(notes)} renamed-file note(s).")
    if failures:
        print("\n".join(failures))
        print(f"RESULT: FAIL — {len(failures)} problem(s)")
        return 1
    print("RESULT: PASS — every name matches the WXR source byte-for-byte; data set complete")
    return 0


if __name__ == "__main__":
    sys.exit(main())

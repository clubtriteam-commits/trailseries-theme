#!/usr/bin/env python3
"""Byte-verify extracted names against the WXR source.

For every canonical JSON produced by extract_canonical_results.py, this
script runs two independent checks:

  1. FILE INTEGRITY — Recompute the names SHA-256 from the JSON rows and
     compare it with the hash recorded in _manifest.csv.  Catches any
     post-extraction modification of the JSON files.

  2. SOURCE FIDELITY — Re-parse the originating page from the WXR export
     using the same PageExtractor, then compare every first_name / last_name
     byte-for-byte against the JSON.  Proves the JSON matches the XML source.

A hex-dump diff is printed for any mismatch; the process exits non-zero
if any check fails.

Stdlib + local imports only.

Usage:
    python migration/verify_names.py
    python migration/verify_names.py --pages 10            # quick sample
    python migration/verify_names.py --slug iran-run18-results  # one page
"""

from __future__ import annotations

import argparse
import csv
import json
import sys
import urllib.parse
import xml.etree.ElementTree as ET
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
from build_results_page_list import (  # noqa: E402
    NS,
    OUT_OF_SCOPE_TITLES,
    POST_TYPES,
    SKIP_STATUSES,
)
from extract_canonical_results import (  # noqa: E402
    PageExtractor,
    load_category_map,
    names_sha256,
    slugify,
    clean,
)


# ── helpers ──────────────────────────────────────────────────────────────────

def hex_dump(label: str, value: str) -> str:
    raw = value.encode("utf-8")
    return f"  {label} ({len(raw)}B): {raw!r}"


def name_diff_report(
    json_file: str,
    row_idx: int,
    field: str,
    expected: str,
    actual: str,
) -> str:
    lines = [
        f"  MISMATCH  file={json_file}  row={row_idx}  field={field}",
        hex_dump("    json  ", expected),
        hex_dump("    xml   ", actual),
    ]
    return "\n".join(lines)


# ── manifest loading ──────────────────────────────────────────────────────────

def load_manifest(manifest_path: Path) -> list[dict]:
    with open(manifest_path, encoding="utf-8-sig") as f:
        return list(csv.DictReader(f))


def group_manifest_by_page(
    manifest: list[dict],
) -> dict[tuple[str, str], list[list[dict]]]:
    """Group manifest rows by (slug, page_title) preserving insertion order.

    Returns a dict where each value is a list-of-batches. Each batch corresponds
    to one WXR item occurrence (duplicate slugs appear as separate batches
    because the extractor processes each occurrence independently).

    Batch boundaries are detected by section-order resets: if a page appears N
    times in the WXR, consecutive manifest rows from different occurrences will
    have identical (slug, category_raw) combinations, so we simply keep a list
    of batches and the verifier pops one batch per WXR occurrence.
    """
    # We can't reliably detect batch boundaries from manifest data alone, so
    # we use a simpler model: store all rows per key and let the verifier
    # consume them in chunks matching the re-extracted section count.
    groups: dict[tuple[str, str], list[dict]] = {}
    for row in manifest:
        key = (row["slug"], row["page_title"])
        groups.setdefault(key, []).append(row)
    return groups  # type: ignore[return-value]


# ── check 1: file integrity ───────────────────────────────────────────────────

def check_file_integrity(
    manifest: list[dict],
    out_dir: Path,
    verbose: bool,
) -> tuple[int, int]:
    """Recompute names hash from each JSON and compare to manifest.

    Returns (passed, failed).
    """
    passed = failed = 0
    for row in manifest:
        fname = row.get("file", "")
        if not fname:
            continue  # section had only issues, no JSON written
        stored_hash = row.get("names_sha256", "")
        if not stored_hash:
            continue  # no hash to check (e.g. section with 0 rows)

        fpath = out_dir / fname
        if not fpath.exists():
            print(f"  MISSING  {fname}")
            failed += 1
            continue

        data = json.loads(fpath.read_text(encoding="utf-8"))
        rows = data.get("rows", [])
        computed = names_sha256(rows)
        if computed == stored_hash:
            passed += 1
            if verbose:
                print(f"  OK (integrity)  {fname}")
        else:
            failed += 1
            print(f"  INTEGRITY FAIL  {fname}")
            print(f"    manifest: {stored_hash}")
            print(f"    computed: {computed}")
    return passed, failed


# ── check 2: source fidelity ──────────────────────────────────────────────────

def check_source_fidelity(
    xml_path: str,
    manifest_groups: dict[tuple[str, str], list[dict]],
    out_dir: Path,
    category_map: dict,
    slug_filter: str | None,
    page_limit: int | None,
    verbose: bool,
) -> tuple[int, int, int]:
    """Re-parse WXR and byte-compare names.

    Returns (files_passed, files_failed, files_skipped).

    Duplicate WXR items (same slug + title appearing more than once, which the
    extractor processes independently) are handled by consuming manifest rows
    in chunks equal to the re-extracted section count for each occurrence.
    """
    extractor = PageExtractor(category_map)
    pages_checked = 0
    files_passed = files_failed = files_skipped = 0
    # Track how many manifest rows have already been consumed per key so that
    # duplicate WXR items are matched to the correct slice of the manifest.
    consumed: dict[tuple[str, str], int] = {}

    for item in ET.parse(xml_path).getroot().findall("./channel/item"):
        post_type = item.findtext("wp:post_type", "", NS)
        status = item.findtext("wp:status", "", NS)
        if post_type not in POST_TYPES or status in SKIP_STATUSES:
            continue

        title = item.findtext("title") or ""
        if title in OUT_OF_SCOPE_TITLES:
            continue

        raw_slug = item.findtext("wp:post_name", "", NS) or ""
        slug = urllib.parse.unquote(raw_slug) if status == "publish" else ""
        key = (slug if status == "publish" else "", title)

        if key not in manifest_groups:
            continue  # page not in manifest → not a results page

        if slug_filter and slug_filter not in slug:
            continue

        page_slug = slug or slugify(title)
        all_manifest_rows = manifest_groups[key]
        offset = consumed.get(key, 0)

        content = item.findtext("content:encoded", "", NS) or ""
        re_sections = extractor.extract(content)
        n = len(re_sections)

        # Slice the manifest rows for this particular occurrence of the page.
        manifest_rows = all_manifest_rows[offset: offset + n]
        consumed[key] = offset + n

        # Only process pages that have at least one file with a names_sha256
        if not any(r.get("names_sha256") for r in manifest_rows):
            pages_checked += 1
            if page_limit and pages_checked >= page_limit:
                break
            continue

        if len(manifest_rows) != n:
            print(
                f"  SECTION COUNT MISMATCH  slug={page_slug!r}  "
                f"manifest-slice={len(manifest_rows)}  re-extracted={n}"
            )
            files_failed += 1
            pages_checked += 1
            if page_limit and pages_checked >= page_limit:
                break
            continue

        page_passed = page_failed = 0
        for m_row, section in zip(manifest_rows, re_sections):
            fname = m_row.get("file", "")
            if not fname or not m_row.get("names_sha256"):
                files_skipped += 1
                continue

            fpath = out_dir / fname
            if not fpath.exists():
                files_skipped += 1
                continue

            json_rows = json.loads(fpath.read_text(encoding="utf-8")).get("rows", [])
            src_rows = section.rows

            if len(json_rows) != len(src_rows):
                print(
                    f"  ROW COUNT MISMATCH  {fname}  "
                    f"json={len(json_rows)}  xml={len(src_rows)}"
                )
                page_failed += 1
                continue

            section_failed = False
            for idx, (jr, sr) in enumerate(zip(json_rows, src_rows)):
                for field in ("first_name", "last_name"):
                    jv = jr.get(field, "")
                    sv = sr.get(field, "")
                    if jv != sv:
                        print(name_diff_report(fname, idx, field, jv, sv))
                        section_failed = True

            if section_failed:
                page_failed += 1
            else:
                page_passed += 1
                if verbose:
                    print(f"  OK (source)  {fname}")

        if page_failed:
            files_failed += page_failed
        else:
            files_passed += page_passed

        pages_checked += 1
        if page_limit and pages_checked >= page_limit:
            break

    return files_passed, files_failed, files_skipped


# ── main ─────────────────────────────────────────────────────────────────────

def main() -> int:
    parser = argparse.ArgumentParser(
        description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter
    )
    parser.add_argument(
        "--xml", default="migration/data/raw/runbgtrailseries.WordPress.2026-07-02.xml"
    )
    parser.add_argument("--manifest", default="migration/data/canonical/_manifest.csv")
    parser.add_argument("--category-map", default="migration/category-map.csv")
    parser.add_argument("--pages", default="migration/results-page-list.csv")
    parser.add_argument("--out-dir", default="migration/data/canonical")
    parser.add_argument("--slug", default=None, help="Limit to pages whose slug contains this string")
    parser.add_argument("--limit", type=int, default=None, help="Stop after this many pages (quick check)")
    parser.add_argument("-v", "--verbose", action="store_true", help="Print OK lines too")
    args = parser.parse_args()

    out_dir = Path(args.out_dir)
    manifest = load_manifest(Path(args.manifest))
    manifest_groups = group_manifest_by_page(manifest)
    category_map = load_category_map(args.category_map)

    total_with_files = sum(1 for r in manifest if r.get("file"))

    print(f"Manifest: {len(manifest)} sections, {total_with_files} with JSON files")
    print()

    # ── Check 1: file integrity ───────────────────────────────────────────────
    print("=== CHECK 1: FILE INTEGRITY (manifest SHA-256 vs JSON) ===")
    int_passed, int_failed = check_file_integrity(manifest, out_dir, args.verbose)
    print(f"  {int_passed} passed, {int_failed} failed")
    print()

    # ── Check 2: source fidelity ──────────────────────────────────────────────
    print("=== CHECK 2: SOURCE FIDELITY (JSON names vs WXR source) ===")
    src_passed, src_failed, src_skipped = check_source_fidelity(
        xml_path=args.xml,
        manifest_groups=manifest_groups,
        out_dir=out_dir,
        category_map=category_map,
        slug_filter=args.slug,
        page_limit=args.limit,
        verbose=args.verbose,
    )
    print(f"  {src_passed} sections passed, {src_failed} failed, {src_skipped} skipped (no file/hash)")
    print()

    total_failed = int_failed + src_failed
    if total_failed:
        print(f"RESULT: FAIL — {total_failed} check(s) failed")
        return 1
    print("RESULT: PASS — all names match the WXR source byte-for-byte")
    return 0


if __name__ == "__main__":
    sys.exit(main())

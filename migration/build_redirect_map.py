#!/usr/bin/env python3
"""Build the old→new URL redirect map for the trailseries.bg cutover.

Reads the crawled URL inventory (url-inventory.csv, ADR-003) and the results
page work-list (results-page-list.csv) and maps every crawled URL to its
equivalent on the rebuilt site. Output: redirect-map.csv with columns

    old_url, new_url, status_code, notes

status_code semantics:
    200 — URL is preserved byte-for-byte on the new site (iron rule 3);
          no redirect rule must be generated for these rows.
    301 — permanent redirect to the closest new equivalent.
    410 — gone; no equivalent exists on the new site.

A results-page URL counts as preserved ONLY if its slug produced at least one
canonical JSON file (checked against _manifest.csv when available) — only
those slugs become ts_result posts. Results pages without extracted data are
either standings pages (→ /klasiraniya/) or extraction gaps (→ 410 + review
note). Without the manifest (it lives in gitignored migration/data/), every
results-page slug is assumed extracted and a warning is printed.

New-site target paths assumed by this map (planned sitemap):
    /               homepage
    /novini/        news archive (posts page)
    /klasiraniya/   season standings
    /rekordi/       course records
    /pravila/       rules & scoring
    /istoriya/      series history
    /calendar/      event calendar (EventON)
    /{slug}/        every ts_result post — original results-page slug,
                    top level, preserved exactly (iron rule 3 / ADR-003)

Stdlib only. Usage:
    python migration/build_redirect_map.py
"""

from __future__ import annotations

import argparse
import csv
import os
import re
import sys
import urllib.parse

HOST = "https://trailseries.bg"

# Season hub / standings pages → the new season-standings page.
SEASON_HUB = re.compile(r"^/(сезон-\d+(-(резултати|класиране))?|втори-сезон-\d+)$")

# Unextracted results pages whose slug/title marks them as season standings,
# not race results. Deliberately narrow: "-ranking"/"класиране" alone also
# appear in per-race results slugs, and an unextracted one of those is an
# extraction gap that must surface as REVIEW, not silently redirect.
STANDINGS_HINT = re.compile(r"генерално|сезон|победители", re.IGNORECASE)

# One-off page mappings that no generic rule covers.
EXPLICIT: dict[str, tuple[str, str, str]] = {
    "/overal-ranking": ("/klasiraniya/", "301", "overall-ranking hub → season standings page"),
    "/all-seasons-rankings": ("/klasiraniya/", "301", "all-seasons rankings → season standings page"),
    "/за-trail-series": ("/istoriya/", "301", "about page → history page"),
    "/за-trail-series/rules": ("/pravila/", "301", "old rules page → new rules page"),
    "/за-trail-series/calendar": ("/calendar/", "301", "old calendar → new calendar"),
    "/calendar": ("/calendar/", "200", "preserved — becomes the new calendar page"),
    "/event-directory": ("/calendar/", "301", "event directory → calendar"),
}

GONE_NOTES: list[tuple[re.Pattern[str], str]] = [
    (re.compile(r"^/(snimki|sabitia)(/|$)"), "photo gallery — not migrated"),
    (re.compile(r"(снимки|photos|gallery|race-photos)"), "photo gallery — not migrated"),
    (re.compile(r"^/registration(/|$)"), "registration form — obsolete"),
    (re.compile(r"^/за-trail-series/t-shirt"), "t-shirt order confirmation — obsolete"),
    (re.compile(r"^/за-trail-series/(контакти|feedback)$"), "contact/feedback form — no equivalent yet"),
    (re.compile(r"^/feedback$"), "feedback form — no equivalent yet"),
    (re.compile(r"^/\d+-\d+$"), "junk/placeholder page"),
]


def norm_path(url: str) -> str:
    """Decoded path, no trailing slash. Root becomes empty string."""
    split = urllib.parse.urlsplit(url.strip())
    return urllib.parse.unquote(split.path).rstrip("/")


def new_url(path: str) -> str:
    """Absolute new-site URL for a decoded path ('' = root)."""
    if not path or path == "/":
        return HOST + "/"
    return HOST + urllib.parse.quote(path, safe="/") + "/"


def load_extracted_slugs(manifest_path: str) -> set[str] | None:
    """Slugs with at least one canonical JSON file — only these become posts."""
    if not os.path.exists(manifest_path):
        return None
    with open(manifest_path, encoding="utf-8-sig") as f:
        return {row["slug"] for row in csv.DictReader(f) if row.get("file")}


def build_landing_match(extracted_slugs: set[str]) -> dict[str, str]:
    """Map event-landing slugs to a results slug when the match is exact.

    A landing page like /malak-sechko-run19/ maps to the results page
    malak-sechko-run19-ranking only when the results slug is the landing slug
    plus a known results suffix — anything fuzzier risks a wrong redirect.
    """
    suffixes = ("-results", "-ranking", "-result", "-класиране", "-резултати")
    match: dict[str, str] = {}
    for slug in extracted_slugs:
        for suffix in suffixes:
            if slug.endswith(suffix):
                base = slug[: -len(suffix)]
                # Prefer -results over -ranking when both exist for one base.
                if base not in match or slug.endswith("-results"):
                    match[base] = slug
    return match


def classify(
    path: str,
    post_type: str,
    extracted_slugs: set[str],
    unextracted: dict[str, str],
    landing_match: dict[str, str],
) -> tuple[str, str, str]:
    """-> (new_url, status_code, notes) for one decoded old path."""
    # Front page.
    if not path:
        return new_url(""), "200", "front page — preserved"

    # Blog pagination on the old front page.
    if re.fullmatch(r"/page/\d+", path):
        return new_url("/novini"), "301", "front-page blog pagination → news archive"

    # Archives first — their last segment may collide with a results slug.
    if path.startswith("/category/"):
        return HOST + "/novini/", "301", "category archive → news archive"
    if path.startswith("/tag/"):
        return "", "410", "tag archive — taxonomy not migrated"

    last = path.rsplit("/", 1)[-1]

    # Results pages that become ts_result posts — iron rule 3: top-level slug.
    if last in extracted_slugs:
        if path == f"/{last}":
            return new_url(path), "200", "results page — slug preserved (iron rule 3)"
        return new_url(f"/{last}"), "301", "nested results page → preserved top-level slug"

    # Explicit one-off mappings — before the unextracted branch, because hub
    # pages like /overal-ranking/ also appear in results-page-list.
    if path in EXPLICIT:
        target, code, note = EXPLICIT[path]
        return HOST + target, code, note

    # Results pages that produced no canonical JSON: standings or extraction gap.
    if last in unextracted:
        title = unextracted[last]
        if STANDINGS_HINT.search(last) or STANDINGS_HINT.search(title):
            return HOST + "/klasiraniya/", "301", "standings page → season standings page"
        return "", "410", "results page with no extracted data — REVIEW: possible extraction gap"

    # Season hubs and standings pages.
    if SEASON_HUB.match(path):
        return HOST + "/klasiraniya/", "301", "season hub/standings → season standings page"

    # EventON event pages and event taxonomies.
    if path.startswith(("/events/", "/event-location/", "/event-organizer/", "/event-type/")):
        return HOST + "/calendar/", "301", "EventON page → calendar"

    # Galleries, registration forms, junk.
    for pattern, note in GONE_NOTES:
        if pattern.search(path):
            return "", "410", note

    # Attachment pages.
    if post_type == "attachment":
        return "", "410", "attachment page — not migrated"

    # News posts migrate with their slugs intact.
    if post_type == "post":
        return new_url(path), "200", "news post — slug preserved by post migration"

    # Event landing page with an exact-match results page.
    if last in landing_match:
        return new_url(f"/{landing_match[last]}"), "301", f"event landing page → results page {landing_match[last]}"

    return "", "410", "no equivalent on new site — review before cutover"


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--inventory", default="migration/url-inventory.csv")
    parser.add_argument("--pages", default="migration/results-page-list.csv")
    parser.add_argument("--manifest", default="migration/data/canonical/_manifest.csv")
    parser.add_argument("--out", default="migration/redirect-map.csv")
    args = parser.parse_args()

    with open(args.pages, encoding="utf-8-sig") as f:
        results_pages = {
            row["slug"]: row["page_title"]
            for row in csv.DictReader(f)
            if row["slug"] and row["url"]
        }

    extracted = load_extracted_slugs(args.manifest)
    if extracted is None:
        print(f"WARNING: {args.manifest} not found — assuming every results-page slug was extracted.")
        extracted = set(results_pages)
    extracted &= set(results_pages) | extracted  # keep manifest slugs even if page list drifted
    unextracted = {slug: title for slug, title in results_pages.items() if slug not in extracted}

    landing_match = build_landing_match(extracted)

    rows: list[dict[str, str]] = []
    seen: set[str] = set()
    counts: dict[str, int] = {"200": 0, "301": 0, "410": 0}

    with open(args.inventory, encoding="utf-8-sig") as f:
        for row in csv.DictReader(f):
            path = norm_path(row["url"])
            if path in seen:
                continue  # crawl inventory contains a handful of duplicates
            seen.add(path)

            target, code, note = classify(path, row["post_type"], extracted, unextracted, landing_match)
            counts[code] += 1
            rows.append(
                {
                    "old_url": row["url"],
                    "new_url": target,
                    "status_code": code,
                    "notes": note,
                }
            )

    rows.sort(key=lambda r: (r["status_code"], r["old_url"]))
    with open(args.out, "w", newline="", encoding="utf-8-sig") as f:
        writer = csv.DictWriter(f, fieldnames=["old_url", "new_url", "status_code", "notes"])
        writer.writeheader()
        writer.writerows(rows)

    review = sum(1 for r in rows if "REVIEW" in r["notes"])
    print(f"Wrote {len(rows)} URLs to {args.out}")
    print(f"  200 preserved: {counts['200']}")
    print(f"  301 redirect:  {counts['301']}")
    print(f"  410 gone:      {counts['410']}  ({review} flagged REVIEW — possible extraction gaps)")
    return 0


if __name__ == "__main__":
    sys.exit(main())

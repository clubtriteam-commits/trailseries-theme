#!/usr/bin/env python3
"""Fetch drace.bg track pages, extract GPX metadata, download GPX files.

Reads migration/track-urls.txt (one drace.bg /track/ URL per line), fetches
each page, extracts the displayed metrics (distance, ascending, descending,
highest/lowest point) and the GPX file URL, downloads the GPX into
migration/gpx/, and writes everything to migration/tracks-data.json.

Only stdlib is used (urllib) — no third-party dependencies.

Usage:  python migration/fetch_tracks.py
"""

from __future__ import annotations

import json
import re
import sys
import time
import urllib.request
from pathlib import Path

BASE_DIR = Path(__file__).resolve().parent
URLS_FILE = BASE_DIR / "track-urls.txt"
GPX_DIR = BASE_DIR / "gpx"
OUT_FILE = BASE_DIR / "tracks-data.json"

UA = "Mozilla/5.0 (compatible; TrailSeriesMigration/1.0; +https://trailseries.bg)"

# Page title:  <meta property="og:title" content="The Christmas Run - 15.5km" />
RE_TITLE = re.compile(r'<meta property="og:title" content="([^"]+)"')
# GPX link:  https://drace.bg/sites/default/files/tracks/xxx.gpx
RE_GPX = re.compile(r'href="(https?://drace\.bg/sites/default/files/tracks/[^"]+\.gpx)"')
# KML link:  same file area, .kml extension.
RE_KML = re.compile(r'href="(https?://drace\.bg/sites/default/files/tracks/[^"]+\.kml)"')
# Metric rows:  Distance: </span><div class="field-content">15494.50m</div>
RE_METRIC = re.compile(
    r'(Distance|Ascending|Descending|Highest point|Lowest point):\s*'
    r'</span><div class="field-content">([\d.]+)m'
)

METRIC_KEYS = {
    "Distance": "distance_m",
    "Ascending": "ascent_m",
    "Descending": "descent_m",
    "Highest point": "highest_m",
    "Lowest point": "lowest_m",
}


def fetch(url: str, binary: bool = False, retries: int = 3) -> bytes | str:
    last_err: Exception | None = None
    for attempt in range(1, retries + 1):
        try:
            req = urllib.request.Request(url, headers={"User-Agent": UA})
            with urllib.request.urlopen(req, timeout=30) as resp:
                data = resp.read()
            return data if binary else data.decode("utf-8", errors="replace")
        except Exception as e:  # noqa: BLE001 — retry on any transport error
            last_err = e
            if attempt < retries:
                time.sleep(2 * attempt)
    raise RuntimeError(f"failed after {retries} attempts: {url}: {last_err}")


def parse_track(url: str, html: str) -> dict:
    slug = url.rstrip("/").rsplit("/", 1)[-1]
    track: dict = {"slug": slug, "source_url": url}

    m = RE_TITLE.search(html)
    track["title"] = m.group(1).strip() if m else slug

    m = RE_GPX.search(html)
    track["gpx_url"] = m.group(1) if m else None

    m = RE_KML.search(html)
    track["kml_url"] = m.group(1) if m else None

    for label, value in RE_METRIC.findall(html):
        track[METRIC_KEYS[label]] = float(value)

    # Derived convenience fields.
    if "distance_m" in track:
        track["distance_km"] = round(track["distance_m"] / 1000, 2)
    return track


def main() -> int:
    urls = [u.strip() for u in URLS_FILE.read_text().splitlines() if u.strip()]
    GPX_DIR.mkdir(exist_ok=True)

    tracks: list[dict] = []
    errors: list[str] = []

    for i, url in enumerate(urls, 1):
        print(f"[{i:2d}/{len(urls)}] {url}", flush=True)
        try:
            html = fetch(url)
        except RuntimeError as e:
            print(f"    PAGE ERROR: {e}", file=sys.stderr)
            errors.append(url)
            continue

        track = parse_track(url, str(html))

        for kind, url_key, file_key in (("GPX", "gpx_url", "gpx_file"),
                                        ("KML", "kml_url", "kml_file")):
            if track[url_key]:
                name = track[url_key].rsplit("/", 1)[-1]
                path = GPX_DIR / name
                track[file_key] = name
                if not path.exists():
                    try:
                        path.write_bytes(fetch(track[url_key], binary=True))
                    except RuntimeError as e:
                        print(f"    {kind} ERROR: {e}", file=sys.stderr)
                        track[file_key] = None
            else:
                if kind == "GPX":
                    print("    WARN: no GPX link on page", file=sys.stderr)
                track[file_key] = None

        missing = [k for k in METRIC_KEYS.values() if k not in track]
        if missing:
            print(f"    WARN: missing metrics: {', '.join(missing)}", file=sys.stderr)

        tracks.append(track)
        time.sleep(0.5)  # be polite to drace.bg

    OUT_FILE.write_text(
        json.dumps({"generated": time.strftime("%Y-%m-%d %H:%M:%S"),
                    "count": len(tracks), "tracks": tracks},
                   ensure_ascii=False, indent=2),
        encoding="utf-8",
    )
    print(f"\nWrote {len(tracks)} tracks to {OUT_FILE}")
    if errors:
        print(f"{len(errors)} pages failed:", *errors, sep="\n  ", file=sys.stderr)
        return 1
    return 0


if __name__ == "__main__":
    raise SystemExit(main())

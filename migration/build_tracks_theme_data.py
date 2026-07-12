#!/usr/bin/env python3
"""Build the theme tracks data file from migration/tracks-data.json.

Groups tracks by event name (derived from the drace.bg page title), computes
a 1-5 star difficulty rating from km-effort (distance_km + ascent_m / 100),
and writes wp-content/themes/exhibz-child/data/tracks.json. Also copies the
downloaded GPX files into wp-content/themes/exhibz-child/gpx/.

Star scale (km-effort = distance_km + D+ / 100):
    < 10   → 1  лесно
    10-20  → 2  умерено
    20-35  → 3  средно
    35-60  → 4  трудно
    >= 60  → 5  много трудно

Usage:  python migration/build_tracks_theme_data.py
"""

from __future__ import annotations

import html
import json
import math
import re
import shutil
import sys
from pathlib import Path

sys.stdout.reconfigure(encoding="utf-8")

BASE_DIR = Path(__file__).resolve().parent
REPO_DIR = BASE_DIR.parent
IN_FILE = BASE_DIR / "tracks-data.json"
GPX_SRC = BASE_DIR / "gpx"
THEME_DIR = REPO_DIR / "wp-content" / "themes" / "exhibz-child"
OUT_FILE = THEME_DIR / "data" / "tracks.json"
GPX_DST = THEME_DIR / "gpx"

# Distance / year / edition tokens stripped from the tail of a title to get
# the event name: "The Christmas Run - 15.5km" → "The Christmas Run".
RE_TAIL = re.compile(
    r"""\s*[-–—]?\s*(
        \d+([.,]\d+)?\s*(km|км|k\b)   # 15.5km, 7km, 16k
      | ['’]\d\d                 # apostrophe-year: '20, '22
      | 20\d\d                        # year: 2025
      | hard\s*core\s*edition
      | \(?\d+\s*(обиколки|obikolki|laps?)\)?
      | long | middle | short
      | update[e]?
    )\s*$""",
    re.IGNORECASE | re.VERBOSE,
)

# Known variant spellings → canonical event name.
EVENT_ALIASES = {
    "Golyam Sechko": "Golyam Sechko Run",
    "Boyana X Trails": "Boyana X Trail",
}

# Year tokens: apostrophe-year ('20 → 2020) and literal 4-digit (2018).
RE_YEAR_APOS = re.compile(r"['’](\d\d)\b")
RE_YEAR_FULL = re.compile(r"\b(20\d\d)\b")

# Dated editions from this year onward are current, older ones are archives
# (when the event also has undated canonical pages).
RECENT_YEAR = 2024

# Undated tracks superseded by a newer dated version of the same course.
# The year rule can't catch these (no year in title or slug), so they are
# marked legacy explicitly.
LEGACY_SLUGS = {
    "cactus-run-66km",                  # → The Cactus Run'7km - 2025
    "cactus-run-135km",                 # → The Cactus Run'15km - 2025
    "cactus-run-20km",                  # → The Cactus Run'21km - 2025
    "lyulin-trail-run-17km-2-obikolki",  # → Lyulin Trail Run 17km - 2019 update
}


def track_year(title: str, slug: str) -> int | None:
    """Extract an edition year from the title or slug, None when undated."""
    for text in (html.unescape(title), slug):
        m = RE_YEAR_APOS.search(text)
        if m:
            return 2000 + int(m.group(1))
        m = RE_YEAR_FULL.search(text)
        if m:
            return int(m.group(1))
    return None


def event_name(title: str) -> str:
    """Strip distance/year/edition tokens from the end of a page title."""
    name = html.unescape(title).strip()
    while True:
        stripped = RE_TAIL.sub("", name).strip(" -–—'’")
        if stripped == name or not stripped:
            break
        name = stripped
    name = name or html.unescape(title).strip()
    return EVENT_ALIASES.get(name, name)


# First <trkpt> of a GPX file = the track's real-world start point. Used by
# the front-page map to place event pins (the previous pins were hand-guessed
# mountain coordinates, off by 3.5-43 km from the actual trailheads).
RE_TRKPT = re.compile(r'<trkpt[^>]*\blat="(-?[0-9.]+)"[^>]*\blon="(-?[0-9.]+)"')


def gpx_start(gpx_file: str | None) -> tuple[float, float] | None:
    """(lat, lng) of the first trackpoint, None when unavailable."""
    if not gpx_file:
        return None
    path = GPX_SRC / gpx_file
    if not path.exists():
        return None
    m = RE_TRKPT.search(path.read_text(encoding="utf-8", errors="replace"))
    if not m:
        return None
    return round(float(m.group(1)), 5), round(float(m.group(2)), 5)


def haversine_km(a: tuple[float, float], b: tuple[float, float]) -> float:
    dlat = math.radians(b[0] - a[0])
    dlng = math.radians(b[1] - a[1])
    x = (
        math.sin(dlat / 2) ** 2
        + math.cos(math.radians(a[0])) * math.cos(math.radians(b[0])) * math.sin(dlng / 2) ** 2
    )
    return 2 * 6371 * math.asin(math.sqrt(x))


def median(values: list[float]) -> float:
    ordered = sorted(values)
    n = len(ordered)
    mid = n // 2
    return ordered[mid] if n % 2 else (ordered[mid - 1] + ordered[mid]) / 2


def stars(distance_km: float | None, ascent_m: float | None) -> int | None:
    if distance_km is None:
        return None
    effort = distance_km + (ascent_m or 0) / 100
    if effort < 10:
        return 1
    if effort < 20:
        return 2
    if effort < 35:
        return 3
    if effort < 60:
        return 4
    return 5


def main() -> int:
    data = json.loads(IN_FILE.read_text(encoding="utf-8"))

    events: dict[str, list[dict]] = {}
    copied = 0
    GPX_DST.mkdir(exist_ok=True)
    OUT_FILE.parent.mkdir(exist_ok=True)

    for t in data["tracks"]:
        ev = event_name(t["title"])
        entry = {
            "title":       html.unescape(t["title"]),
            "slug":        t["slug"],
            "year":        track_year(t["title"], t["slug"]),
            "distance_km": t.get("distance_km"),
            "ascent_m":    round(t["ascent_m"]) if t.get("ascent_m") is not None else None,
            "descent_m":   round(t["descent_m"]) if t.get("descent_m") is not None else None,
            "highest_m":   round(t["highest_m"]) if t.get("highest_m") is not None else None,
            "lowest_m":    round(t["lowest_m"]) if t.get("lowest_m") is not None else None,
            "gpx_file":    t.get("gpx_file"),
            "kml_file":    t.get("kml_file"),
            "stars":       stars(t.get("distance_km"), t.get("ascent_m")),
        }
        start = gpx_start(t.get("gpx_file"))
        entry["start_lat"] = start[0] if start else None
        entry["start_lng"] = start[1] if start else None
        events.setdefault(ev, []).append(entry)

        for key in ("gpx_file", "kml_file"):
            if t.get(key):
                src = GPX_SRC / t[key]
                if src.exists():
                    shutil.copy2(src, GPX_DST / t[key])
                    copied += 1

    # Status: undated tracks are the canonical course pages → current (unless
    # explicitly superseded via LEGACY_SLUGS). A dated track is legacy when a
    # newer dated edition exists in the event, or when the event also has
    # undated canonical pages and the dated one is an old edition archive
    # (year < RECENT_YEAR and not an "update" of the course).
    for tracks in events.values():
        years = [t["year"] for t in tracks if t["year"] is not None]
        max_year = max(years) if years else None
        has_undated = any(t["year"] is None for t in tracks)
        for t in tracks:
            y = t["year"]
            legacy = (
                t["slug"] in LEGACY_SLUGS
                or (y is not None and max_year is not None and y < max_year)
                or (y is not None and has_undated and y < RECENT_YEAR
                    and "update" not in t["title"].lower())
            )
            t["status"] = "legacy" if legacy else "current"

    # Sort tracks inside each event: current first, then legacy, by distance;
    # events alphabetically.
    for tracks in events.values():
        tracks.sort(key=lambda x: (x["status"] != "current", x["distance_km"] or 0))

    out = {
        "events": [
            {"name": name, "tracks": tracks}
            for name, tracks in sorted(events.items(), key=lambda kv: kv[0].lower())
        ]
    }
    OUT_FILE.write_text(json.dumps(out, ensure_ascii=False, indent=2), encoding="utf-8")

    # Data-quality check: a track starting far from its event's median start
    # is either a mislabeled GPX or a relocated edition (e.g. the Pancharevo
    # Night Run 19km file starts at the 7 Hills trailhead). The front-page
    # map uses the per-event MEDIAN start, so one outlier cannot skew the
    # pin — but it should be visible at build time.
    for name, tracks in events.items():
        starts = [
            (t["start_lat"], t["start_lng"])
            for t in tracks
            if t["start_lat"] is not None
        ]
        if len(starts) < 2:
            continue
        med = (median([s[0] for s in starts]), median([s[1] for s in starts]))
        for t in tracks:
            if t["start_lat"] is None:
                continue
            dist = haversine_km((t["start_lat"], t["start_lng"]), med)
            if dist > 5:
                print(
                    f"  WARNING: {name}: {t['gpx_file']} starts {dist:.1f} km "
                    f"from the event's median start — check the GPX labelling"
                )

    n_tracks = sum(len(e["tracks"]) for e in out["events"])
    n_starts = sum(
        1 for e in out["events"] for t in e["tracks"] if t["start_lat"] is not None
    )
    print(f"Wrote {n_tracks} tracks in {len(out['events'])} events to {OUT_FILE}")
    print(f"GPX start points resolved for {n_starts}/{n_tracks} tracks")
    print(f"Copied {copied} GPX files to {GPX_DST}")
    for e in out["events"]:
        print(f"  {e['name']}: {len(e['tracks'])} tracks")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())

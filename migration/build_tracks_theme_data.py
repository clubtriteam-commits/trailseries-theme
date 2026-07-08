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
            "distance_km": t.get("distance_km"),
            "ascent_m":    round(t["ascent_m"]) if t.get("ascent_m") is not None else None,
            "descent_m":   round(t["descent_m"]) if t.get("descent_m") is not None else None,
            "highest_m":   round(t["highest_m"]) if t.get("highest_m") is not None else None,
            "lowest_m":    round(t["lowest_m"]) if t.get("lowest_m") is not None else None,
            "gpx_file":    t.get("gpx_file"),
            "stars":       stars(t.get("distance_km"), t.get("ascent_m")),
        }
        events.setdefault(ev, []).append(entry)

        if t.get("gpx_file"):
            src = GPX_SRC / t["gpx_file"]
            if src.exists():
                shutil.copy2(src, GPX_DST / t["gpx_file"])
                copied += 1

    # Sort tracks inside each event by distance; events alphabetically.
    for tracks in events.values():
        tracks.sort(key=lambda x: x["distance_km"] or 0)

    out = {
        "events": [
            {"name": name, "tracks": tracks}
            for name, tracks in sorted(events.items(), key=lambda kv: kv[0].lower())
        ]
    }
    OUT_FILE.write_text(json.dumps(out, ensure_ascii=False, indent=2), encoding="utf-8")

    n_tracks = sum(len(e["tracks"]) for e in out["events"])
    print(f"Wrote {n_tracks} tracks in {len(out['events'])} events to {OUT_FILE}")
    print(f"Copied {copied} GPX files to {GPX_DST}")
    for e in out["events"]:
        print(f"  {e['name']}: {len(e['tracks'])} tracks")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())

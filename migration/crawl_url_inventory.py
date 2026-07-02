#!/usr/bin/env python3
"""Legacy URL inventory crawler for the trailseries.bg rebuild (ADR-003).

Crawls the live site and writes url-inventory.csv with columns:

    url, page_title, post_type, slug, has_results_table

URL discovery:
  1. WordPress core sitemaps (robots.txt -> wp-sitemap.xml -> sub-sitemaps).
  2. BFS over internal links found on every crawled page, to catch anything
     the sitemap omits.

post_type is read from WordPress body classes (single-{type}, page,
post-type-archive-{type}, category, tag, tax-{tax}, author, date, ...).
has_results_table is a heuristic: any single <table> with >= MIN_RESULT_ROWS
rows. Verify by eye before trusting it for the migration work-list.

Stdlib only — no pip installs needed. Python 3.11+.

Usage:
    python migration/crawl_url_inventory.py
    python migration/crawl_url_inventory.py --base https://trailseries.bg \
        --out migration/url-inventory.csv --delay 0.4 --max-pages 2000
"""

from __future__ import annotations

import argparse
import csv
import re
import sys
import time
import urllib.error
import urllib.parse
import urllib.request
from collections import deque
from html.parser import HTMLParser

USER_AGENT = "Mozilla/5.0 (compatible; trailseries-rebuild-inventory/0.1; +https://trailseries.bg)"
MIN_RESULT_ROWS = 5  # a table with this many <tr> counts as a results table

# Path prefixes / patterns that are not content pages.
SKIP_PATH_PREFIXES = (
    "/wp-admin",
    "/wp-login",
    "/wp-json",
    "/wp-content",
    "/wp-includes",
    "/xmlrpc.php",
    "/cdn-cgi/",
)
SKIP_PATH_PATTERNS = (
    re.compile(r"/feed/?$"),
    re.compile(r"/trackback/?$"),
    re.compile(r"/embed/?$"),
)
SKIP_EXTENSIONS = (
    ".jpg", ".jpeg", ".png", ".gif", ".webp", ".svg", ".ico",
    ".pdf", ".zip", ".gz", ".rar", ".7z",
    ".mp3", ".mp4", ".avi", ".mov",
    ".doc", ".docx", ".xls", ".xlsx", ".ppt", ".pptx",
    ".gpx", ".kml", ".kmz",
    ".css", ".js", ".xml", ".xsl", ".txt",
)


class PageParser(HTMLParser):
    """One-pass extraction of title, body classes, links and table sizes."""

    def __init__(self) -> None:
        super().__init__(convert_charrefs=True)
        self.title_parts: list[str] = []
        self.body_classes: list[str] = []
        self.links: list[str] = []
        self.max_table_rows = 0
        self._in_title = False
        self._table_row_counts: list[int] = []  # stack, one entry per open <table>

    def handle_starttag(self, tag: str, attrs: list[tuple[str, str | None]]) -> None:
        attr = dict(attrs)
        if tag == "title":
            self._in_title = True
        elif tag == "body":
            self.body_classes = (attr.get("class") or "").split()
        elif tag == "a" and attr.get("href"):
            self.links.append(attr["href"])
        elif tag == "table":
            self._table_row_counts.append(0)
        elif tag == "tr" and self._table_row_counts:
            self._table_row_counts[-1] += 1

    def handle_endtag(self, tag: str) -> None:
        if tag == "title":
            self._in_title = False
        elif tag == "table" and self._table_row_counts:
            rows = self._table_row_counts.pop()
            self.max_table_rows = max(self.max_table_rows, rows)

    def handle_data(self, data: str) -> None:
        if self._in_title:
            self.title_parts.append(data)

    @property
    def title(self) -> str:
        return re.sub(r"\s+", " ", "".join(self.title_parts)).strip()


def post_type_from_body_classes(classes: list[str]) -> str:
    tokens = set(classes)
    for cls in classes:
        if cls.startswith("single-") and not cls.startswith(("single-format-", "single-paged-")):
            return cls.removeprefix("single-")
        if cls.startswith("post-type-archive-"):
            return cls.removeprefix("post-type-archive-") + "-archive"
        if cls.startswith("tax-") and not cls.startswith("tax-id-"):
            return cls.removeprefix("tax-") + "-archive"
    if "home" in tokens or "front-page" in tokens:
        return "front-page"
    if "page" in tokens:
        return "page"
    if "category" in tokens:
        return "category-archive"
    if "tag" in tokens:
        return "tag-archive"
    if "author" in tokens:
        return "author-archive"
    if "date" in tokens:
        return "date-archive"
    if "search" in tokens:
        return "search"
    if "attachment" in tokens:
        return "attachment"
    if "archive" in tokens:
        return "archive"
    if "blog" in tokens:
        return "blog-index"
    if "error404" in tokens:
        return "error-404"
    return "unknown"


def slug_from_url(url: str) -> str:
    path = urllib.parse.urlsplit(url).path
    segments = [s for s in path.split("/") if s]
    return urllib.parse.unquote(segments[-1]) if segments else ""


class Crawler:
    def __init__(self, base: str, delay: float, max_pages: int) -> None:
        split = urllib.parse.urlsplit(base)
        self.scheme = split.scheme
        host = split.netloc.lower()
        self.hosts = {host, host.removeprefix("www."), "www." + host.removeprefix("www.")}
        self.base = f"{split.scheme}://{split.netloc}"
        self.delay = delay
        self.max_pages = max_pages
        self.errors: list[str] = []

    # -- fetching -----------------------------------------------------------

    def fetch(self, url: str) -> tuple[str, str] | None:
        """GET url; return (final_url, decoded_body) or None (logged)."""
        request = urllib.request.Request(
            url,
            headers={"User-Agent": USER_AGENT, "Accept-Encoding": "identity"},
        )
        for attempt in (1, 2):
            try:
                with urllib.request.urlopen(request, timeout=30) as response:
                    raw = response.read()
                    charset = response.headers.get_content_charset() or "utf-8"
                    return response.url, raw.decode(charset, errors="replace")
            except urllib.error.HTTPError as e:
                self.errors.append(f"{url}\tHTTP {e.code}")
                return None
            except ValueError as e:  # http.client.InvalidURL and friends
                self.errors.append(f"{url}\tinvalid URL: {e}")
                return None
            except (urllib.error.URLError, TimeoutError, OSError) as e:
                if attempt == 2:
                    self.errors.append(f"{url}\t{e}")
                    return None
                time.sleep(2)
        return None

    # -- URL filtering ------------------------------------------------------

    def normalize(self, url: str, referrer: str) -> str | None:
        """Absolute, canonicalized page URL — or None if out of scope."""
        url = url.strip()
        # The legacy theme leaks PHP warnings into href attributes — anything
        # with whitespace, control characters or markup is not a real URL.
        if re.search(r'[\s<>"\x00-\x1f]', url):
            return None
        absolute = urllib.parse.urljoin(referrer, url)
        split = urllib.parse.urlsplit(absolute)
        if split.scheme not in ("http", "https"):
            return None
        if split.netloc.lower() not in self.hosts:
            return None
        path = split.path or "/"
        lower = path.lower()
        if lower.startswith(SKIP_PATH_PREFIXES):
            return None
        if any(p.search(lower) for p in SKIP_PATH_PATTERNS):
            return None
        if lower.endswith(SKIP_EXTENSIONS):
            return None
        if split.query:  # query URLs (?replytocom=, ?s=) are not canonical pages
            return None
        # WordPress canonical form: trailing slash on extension-less paths.
        if not path.endswith("/") and "." not in path.rsplit("/", 1)[-1]:
            path += "/"
        return f"{self.scheme}://{urllib.parse.urlsplit(self.base).netloc}{path}"

    # -- sitemap seeding ----------------------------------------------------

    def sitemap_urls(self) -> list[str]:
        seeds: list[str] = []
        seen_maps: set[str] = set()
        queue = deque([f"{self.base}/wp-sitemap.xml", f"{self.base}/sitemap.xml"])
        while queue:
            sitemap = queue.popleft()
            if sitemap in seen_maps:
                continue
            seen_maps.add(sitemap)
            fetched = self.fetch(sitemap)
            if fetched is None:
                continue
            _, body = fetched
            for loc in re.findall(r"<loc>\s*(.*?)\s*</loc>", body):
                loc = loc.strip()
                if loc.endswith(".xml"):
                    queue.append(loc)
                else:
                    normalized = self.normalize(loc, self.base)
                    if normalized:
                        seeds.append(normalized)
            time.sleep(self.delay)
        return seeds

    # -- main crawl ---------------------------------------------------------

    def crawl(self) -> list[dict[str, str]]:
        queue = deque()
        queued: set[str] = set()

        for url in [self.base + "/"] + self.sitemap_urls():
            if url not in queued:
                queued.add(url)
                queue.append(url)
        print(f"Seeded {len(queued)} URLs from sitemap + homepage.", flush=True)

        rows: list[dict[str, str]] = []
        while queue and len(rows) + len(self.errors) < self.max_pages:
            url = queue.popleft()
            fetched = self.fetch(url)
            if fetched is None:
                continue
            final_url, body = fetched
            if final_url.rstrip("/") != url.rstrip("/"):
                self.errors.append(f"{url}\tredirects to {final_url}")

            parser = PageParser()
            try:
                parser.feed(body)
            except Exception as e:  # noqa: BLE001 — malformed HTML must not kill the crawl
                self.errors.append(f"{url}\tparse error: {e}")

            rows.append(
                {
                    "url": url,
                    "page_title": parser.title,
                    "post_type": post_type_from_body_classes(parser.body_classes),
                    "slug": slug_from_url(url),
                    "has_results_table": "yes" if parser.max_table_rows >= MIN_RESULT_ROWS else "no",
                }
            )
            if len(rows) % 25 == 0:
                print(f"  crawled {len(rows)} pages, {len(queue)} queued...", flush=True)

            for link in parser.links:
                normalized = self.normalize(link, url)
                if normalized and normalized not in queued:
                    queued.add(normalized)
                    queue.append(normalized)

            time.sleep(self.delay)

        if queue:
            print(f"WARNING: stopped at max-pages={self.max_pages} with {len(queue)} URLs unvisited.", flush=True)
        return rows


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--base", default="https://trailseries.bg")
    parser.add_argument("--out", default="migration/url-inventory.csv")
    parser.add_argument("--errors-out", default="migration/crawl-errors.txt")
    parser.add_argument("--delay", type=float, default=0.4, help="seconds between requests")
    parser.add_argument("--max-pages", type=int, default=2000)
    args = parser.parse_args()

    crawler = Crawler(args.base, args.delay, args.max_pages)
    rows = crawler.crawl()
    rows.sort(key=lambda r: r["url"])

    # utf-8-sig so Excel opens Cyrillic titles correctly.
    with open(args.out, "w", newline="", encoding="utf-8-sig") as f:
        writer = csv.DictWriter(f, fieldnames=["url", "page_title", "post_type", "slug", "has_results_table"])
        writer.writeheader()
        writer.writerows(rows)

    with open(args.errors_out, "w", encoding="utf-8") as f:
        f.write("\n".join(crawler.errors))

    results_pages = sum(1 for r in rows if r["has_results_table"] == "yes")
    print(f"\nWrote {len(rows)} rows to {args.out} ({results_pages} with a results table).")
    print(f"{len(crawler.errors)} errors/redirects logged to {args.errors_out}.")
    return 0


if __name__ == "__main__":
    sys.exit(main())

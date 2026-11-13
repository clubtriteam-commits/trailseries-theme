#!/usr/bin/env python3
"""
crawl_diff.py — Verify all legacy URLs against the staging site.

For each entry in migration/redirect-map.csv, rewrites the URL to the
staging host and checks that the response matches the expected status:

    200  final HTTP status must be 200  (redirects are followed)
    301  first response must be 3xx, Location path must match new_url
    410  final HTTP status must be 404 or 410

--strict (the ADR-003 launch gate, audit B2) tightens this:

    200  the first response must be 200 — no redirect, the same path serves
         the page. With --manifest, a results page must also render one
         `table.tsr-results` per manifest section and exactly the manifest's
         total row count (`<tr class="tsr-status-…">`).
    301  as above, and the Location target itself must answer 200 directly
         (no chains, no redirect into a 404).
    410  the response must be exactly 410 (a 404 is a failure).

Transient 429/502/503/504 responses and timeouts are retried with backoff.

Usage:
    python migration/crawl_diff.py [options]

Options:
    --host STG        Staging hostname (default: stg.trailseries.bg)
    --concurrency N   Max parallel requests (default: 4)
    --timeout N       Per-request timeout in seconds (default: 20)
    --no-verify       Skip TLS certificate verification
    --strict          Strict ADR-003 checks (see above)
    --manifest PATH   _manifest.csv of the imported data set, for the strict
                      body check. It lives outside the repo (tsr-data); copy
                      it somewhere outside migration/, never commit it.
    --out PATH        Output CSV (default: migration/crawl-diff-report.csv,
                      or migration/crawl-diff-strict-report.csv with --strict)

Exit code: 0 = all pass, 1 = one or more failures.
"""

import argparse
import csv
import http.client
import re
import socket
import ssl
import sys
import threading
import time
import urllib.parse
from concurrent.futures import ThreadPoolExecutor, as_completed
from pathlib import Path

REPO_ROOT = Path(__file__).resolve().parent.parent
REDIRECT_MAP = REPO_ROOT / "migration" / "redirect-map.csv"
DEFAULT_REPORT = REPO_ROOT / "migration" / "crawl-diff-report.csv"
DEFAULT_STRICT_REPORT = REPO_ROOT / "migration" / "crawl-diff-strict-report.csv"

DEFAULT_HOST        = "stg.trailseries.bg"
DEFAULT_CONCURRENCY = 4    # the host answers 429 above a few parallel requests
DEFAULT_TIMEOUT     = 20
RETRIES             = 4

REDIRECT_STATUSES  = frozenset({301, 302, 303, 307, 308})
TRANSIENT_STATUSES = frozenset({429, 502, 503, 504})

TABLE_RE = re.compile(rb'<table class="tsr-results"')
ROW_RE   = re.compile(rb'<tr class="tsr-status-')

REPORT_FIELDS = [
    "old_url",
    "staging_url",
    "expected_status",
    "actual_status",
    "pass_fail",
    "expected_tables",
    "actual_tables",
    "expected_rows",
    "actual_rows",
    "notes",
]


# ── HTTP helpers ──────────────────────────────────────────────────────────────

def _make_conn(host: str, timeout: int, ssl_ctx):
    if ssl_ctx is not None:
        return http.client.HTTPSConnection(host, timeout=timeout, context=ssl_ctx)
    return http.client.HTTPSConnection(host, timeout=timeout)


def http_get(
    url: str,
    *,
    follow_redirects: bool,
    timeout: int,
    ssl_ctx,
    max_hops: int = 10,
    want_body: bool = False,
) -> tuple:
    """
    GET with retries on transient failures (429/5xx gateway errors, timeouts),
    optionally following redirects.

    Returns (status, headers_dict, final_url, error_str), plus the body as a
    fifth element when want_body is set. On error status is None.
    """
    res: tuple = ()
    for attempt in range(RETRIES + 1):
        res = _http_get_once(url, follow_redirects=follow_redirects,
                             timeout=timeout, ssl_ctx=ssl_ctx, max_hops=max_hops)
        transient = res[0] in TRANSIENT_STATUSES or (res[0] is None and "timed out" in res[3])
        if not transient or attempt == RETRIES:
            break
        time.sleep(2 * (attempt + 1))
    return res if want_body else res[:4]


def _http_get_once(
    url: str,
    *,
    follow_redirects: bool,
    timeout: int,
    ssl_ctx,
    max_hops: int,
) -> tuple:
    current = url
    seen: set[str] = set()

    for _ in range(max_hops):
        if current in seen:
            return None, {}, current, "redirect loop detected", b""
        seen.add(current)

        parsed = urllib.parse.urlsplit(current)
        host = parsed.netloc
        path = (parsed.path or "/") + (("?" + parsed.query) if parsed.query else "")

        try:
            conn = _make_conn(host, timeout, ssl_ctx)
            try:
                conn.request("GET", path, headers={"User-Agent": "tsr-crawl-diff/1.1"})
                resp = conn.getresponse()
                status = resp.status
                hdrs = {k.lower(): v for k, v in resp.getheaders()}
                body = resp.read()
            finally:
                conn.close()
        except socket.timeout:
            return None, {}, current, f"timed out after {timeout}s", b""
        except (http.client.HTTPException, OSError, ssl.SSLError) as exc:
            return None, {}, current, str(exc), b""

        if follow_redirects and status in REDIRECT_STATUSES:
            loc = hdrs.get("location", "")
            if not loc:
                return status, hdrs, current, "redirect with empty Location", body
            current = urllib.parse.urljoin(current, loc)
            continue

        return status, hdrs, current, "", body

    return None, {}, current, f"exceeded {max_hops} redirects", b""


# ── URL helpers ───────────────────────────────────────────────────────────────

def rewrite_host(url: str, host: str) -> str:
    p = urllib.parse.urlsplit(url)
    return urllib.parse.urlunsplit(("https", host, p.path, p.query, ""))


def norm_path(url: str) -> str:
    """Decode percent-encoding and strip trailing slash for path comparison."""
    return urllib.parse.unquote(urllib.parse.urlsplit(url).path).rstrip("/")


def path_key(url: str) -> str:
    """Comparable path: decoded, lower-cased, no trailing slash."""
    return norm_path(url).lower()


def load_manifest(path: Path) -> dict[str, tuple[int, int]]:
    """
    Map legacy URL path → (sections, total rows) from a _manifest.csv.

    Rows are grouped by slug, because only the first section of a page
    carries the url. Rows without a results file (e.g. standings pages) are
    not results tables and are skipped.
    """
    by_slug: dict[str, list[dict]] = {}
    for r in csv.DictReader(path.open(encoding="utf-8-sig")):
        by_slug.setdefault(r["slug"], []).append(r)
    out: dict[str, tuple[int, int]] = {}
    for rs in by_slug.values():
        url = next((r["url"] for r in rs if r["url"]), "")
        files = [r for r in rs if r["file"]]
        if not url or not files:
            continue
        out[path_key(url)] = (len(files), sum(int(r["rows"] or 0) for r in files))
    return out


# ── Per-URL check ─────────────────────────────────────────────────────────────

def check_one(row: dict, host: str, timeout: int, ssl_ctx,
              strict: bool = False, manifest: dict | None = None) -> dict:
    old_url  = row["old_url"]
    expected = int(row["status_code"])
    new_url  = row.get("new_url", "").strip()
    stg_url  = rewrite_host(old_url, host)

    out = {
        "old_url":         old_url,
        "staging_url":     stg_url,
        "expected_status": expected,
        "actual_status":   "",
        "pass_fail":       "FAIL",
        "expected_tables": "",
        "actual_tables":   "",
        "expected_rows":   "",
        "actual_rows":     "",
        "notes":           "",
    }

    if strict:
        return _check_strict(out, expected, new_url, stg_url, timeout, ssl_ctx, manifest or {})

    if expected == 200:
        status, _, final, err = http_get(
            stg_url, follow_redirects=True, timeout=timeout, ssl_ctx=ssl_ctx
        )
        if err and status is None:
            out["actual_status"] = "ERROR"
            out["notes"] = err
        else:
            out["actual_status"] = status
            if status == 200:
                out["pass_fail"] = "PASS"
                if err:
                    out["notes"] = err
            else:
                out["notes"] = f"expected 200, got {status}; final: {final}"

    elif expected == 301:
        status, hdrs, _, err = http_get(
            stg_url, follow_redirects=False, timeout=timeout, ssl_ctx=ssl_ctx
        )
        if err and status is None:
            out["actual_status"] = "ERROR"
            out["notes"] = err
        else:
            out["actual_status"] = status
            loc = hdrs.get("location", "")
            if status not in REDIRECT_STATUSES:
                out["notes"] = f"expected 3xx, got {status}"
            else:
                # Check Location path matches new_url path
                abs_loc   = urllib.parse.urljoin(stg_url, loc)
                got_path  = norm_path(abs_loc)
                exp_path  = norm_path(new_url) if new_url else ""
                if exp_path and got_path != exp_path:
                    out["notes"] = (
                        f"wrong Location: got path {got_path!r}, "
                        f"want {exp_path!r}"
                    )
                else:
                    out["pass_fail"] = "PASS"
                    out["notes"] = f"→ {loc}"

    elif expected == 410:
        status, _, final, err = http_get(
            stg_url, follow_redirects=True, timeout=timeout, ssl_ctx=ssl_ctx
        )
        if err and status is None:
            out["actual_status"] = "ERROR"
            out["notes"] = err
        else:
            out["actual_status"] = status
            if status in (404, 410):
                out["pass_fail"] = "PASS"
            else:
                out["notes"] = f"expected 404/410, got {status}; final: {final}"

    else:
        out["actual_status"] = "SKIP"
        out["pass_fail"]     = "SKIP"
        out["notes"]         = f"unrecognised expected status {expected}"

    return out


def _check_strict(out: dict, expected: int, new_url: str, stg_url: str,
                  timeout: int, ssl_ctx, manifest: dict) -> dict:
    status, hdrs, _, err, body = http_get(
        stg_url, follow_redirects=False, timeout=timeout, ssl_ctx=ssl_ctx, want_body=True
    )
    if status is None:
        out["actual_status"] = "ERROR"
        out["notes"] = err
        return out
    out["actual_status"] = status
    loc = hdrs.get("location", "")

    if expected == 200:
        if status != 200:
            out["notes"] = (f"expected 200 at the same path, got {status}"
                            + (f" → {loc}" if loc else ""))
            return out
        want = manifest.get(path_key(out["old_url"]))
        if want is None:
            out["pass_fail"] = "PASS"
            return out
        tables, rows = len(TABLE_RE.findall(body)), len(ROW_RE.findall(body))
        out["expected_tables"], out["expected_rows"] = want
        out["actual_tables"], out["actual_rows"] = tables, rows
        if (tables, rows) == want:
            out["pass_fail"] = "PASS"
        else:
            out["notes"] = (f"results mismatch: rendered {tables} tables / {rows} rows, "
                            f"manifest {want[0]} / {want[1]}")
        return out

    if expected == 301:
        if status not in REDIRECT_STATUSES:
            out["notes"] = f"expected 3xx, got {status}"
            return out
        abs_loc = urllib.parse.urljoin(stg_url, loc)
        if new_url and norm_path(abs_loc) != norm_path(new_url):
            out["notes"] = (f"wrong Location: got {norm_path(abs_loc)!r}, "
                            f"want {norm_path(new_url)!r}")
            return out
        t_status, t_hdrs, _, t_err = http_get(
            abs_loc, follow_redirects=False, timeout=timeout, ssl_ctx=ssl_ctx
        )
        if t_status != 200:
            out["notes"] = (f"→ {loc} answers {t_status or t_err}"
                            + (f" → {t_hdrs['location']}" if t_hdrs.get("location") else ""))
            return out
        out["pass_fail"] = "PASS"
        out["notes"] = f"→ {loc}"
        return out

    if expected == 410:
        if status == 410:
            out["pass_fail"] = "PASS"
        else:
            out["notes"] = f"expected 410, got {status}" + (f" → {loc}" if loc else "")
        return out

    out["actual_status"] = out["pass_fail"] = "SKIP"
    out["notes"] = f"unrecognised expected status {expected}"
    return out


# ── Main ──────────────────────────────────────────────────────────────────────

def main() -> None:
    ap = argparse.ArgumentParser(
        description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter
    )
    ap.add_argument("--host",        default=DEFAULT_HOST,
                    help=f"Staging hostname (default: {DEFAULT_HOST})")
    ap.add_argument("--concurrency", type=int, default=DEFAULT_CONCURRENCY,
                    help=f"Max parallel requests (default: {DEFAULT_CONCURRENCY})")
    ap.add_argument("--timeout",     type=int, default=DEFAULT_TIMEOUT,
                    help=f"Per-request timeout in seconds (default: {DEFAULT_TIMEOUT})")
    ap.add_argument("--no-verify",   action="store_true",
                    help="Skip TLS certificate verification")
    ap.add_argument("--strict",      action="store_true",
                    help="Strict ADR-003 checks: no redirect on 200 rows, exact 410, "
                         "301 targets must answer 200")
    ap.add_argument("--manifest",    type=Path, default=None,
                    help="_manifest.csv for the strict results body check")
    ap.add_argument("--out",         type=Path, default=None,
                    help=f"Output CSV (default: {DEFAULT_REPORT.name}, "
                         f"or {DEFAULT_STRICT_REPORT.name} with --strict)")
    args = ap.parse_args()
    if args.out is None:
        args.out = DEFAULT_STRICT_REPORT if args.strict else DEFAULT_REPORT

    if not REDIRECT_MAP.exists():
        sys.exit(f"redirect-map not found: {REDIRECT_MAP}")
    if args.manifest and not args.strict:
        sys.exit("--manifest only applies with --strict")

    manifest = load_manifest(args.manifest) if args.manifest else None

    ssl_ctx: ssl.SSLContext | None = None
    if args.no_verify:
        ssl_ctx = ssl.SSLContext(ssl.PROTOCOL_TLS_CLIENT)
        ssl_ctx.check_hostname = False
        ssl_ctx.verify_mode    = ssl.CERT_NONE

    rows = list(csv.DictReader(REDIRECT_MAP.open(encoding="utf-8-sig")))
    total = len(rows)

    print(f"Checking {total} URLs → https://{args.host}/"
          + ("  [strict]" if args.strict else ""), file=sys.stderr)
    print(
        f"Concurrency: {args.concurrency}  Timeout: {args.timeout}s"
        + ("  TLS: unverified" if args.no_verify else ""),
        file=sys.stderr,
    )
    if manifest is not None:
        print(f"Manifest: {len(manifest)} results pages", file=sys.stderr)

    results: list[dict] = []
    lock    = threading.Lock()
    counter = {"n": 0}

    with ThreadPoolExecutor(max_workers=args.concurrency) as pool:
        futs = {
            pool.submit(check_one, row, args.host, args.timeout, ssl_ctx,
                        args.strict, manifest): row
            for row in rows
        }
        for fut in as_completed(futs):
            res = fut.result()
            with lock:
                results.append(res)
                counter["n"] += 1
                n = counter["n"]
                pf = res["pass_fail"]
                bar = f"[{n:>3}/{total}]"
                print(
                    f"\r{bar} {pf:<4} {res['old_url'][:72]}",
                    end="",
                    file=sys.stderr,
                    flush=True,
                )

    print(file=sys.stderr)

    results.sort(key=lambda r: r["old_url"])

    by_status: dict[int, dict[str, int]] = {}
    for r in results:
        exp = r["expected_status"]
        by_status.setdefault(exp, {"PASS": 0, "FAIL": 0, "ERROR": 0})
        pf = r["pass_fail"]
        if pf == "PASS":
            by_status[exp]["PASS"] += 1
        elif pf == "FAIL":
            by_status[exp]["FAIL"] += 1
        else:
            by_status[exp]["ERROR"] += 1

    total_pass  = sum(v["PASS"]  for v in by_status.values())
    total_fail  = sum(v["FAIL"]  for v in by_status.values())
    total_error = sum(v["ERROR"] for v in by_status.values())

    print(f"\nSummary: {total_pass} PASS  {total_fail} FAIL  {total_error} ERROR/SKIP",
          file=sys.stderr)
    for exp_status in sorted(by_status):
        d = by_status[exp_status]
        n_exp = d["PASS"] + d["FAIL"] + d["ERROR"]
        print(
            f"  expect-{exp_status}: {d['PASS']}/{n_exp} pass"
            + (f"  {d['FAIL']} fail" if d["FAIL"] else "")
            + (f"  {d['ERROR']} error" if d["ERROR"] else ""),
            file=sys.stderr,
        )

    args.out.parent.mkdir(parents=True, exist_ok=True)
    with args.out.open("w", encoding="utf-8", newline="") as fh:
        writer = csv.DictWriter(fh, fieldnames=REPORT_FIELDS)
        writer.writeheader()
        writer.writerows(results)

    print(f"\nReport written: {args.out}", file=sys.stderr)
    sys.exit(0 if total_fail == 0 and total_error == 0 else 1)


if __name__ == "__main__":
    main()

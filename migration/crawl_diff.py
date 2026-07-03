#!/usr/bin/env python3
"""
crawl_diff.py — Verify all legacy URLs against the staging site.

For each entry in migration/redirect-map.csv, rewrites the URL to the
staging host and checks that the response matches the expected status:

    200  final HTTP status must be 200  (redirects are followed)
    301  first response must be 3xx, Location path must match new_url
    410  final HTTP status must be 404 or 410

Usage:
    python migration/crawl_diff.py [options]

Options:
    --host STG        Staging hostname (default: stg.trailseries.bg)
    --concurrency N   Max parallel requests (default: 16)
    --timeout N       Per-request timeout in seconds (default: 15)
    --no-verify       Skip TLS certificate verification
    --out PATH        Output CSV (default: migration/crawl-diff-report.csv)

Exit code: 0 = all pass, 1 = one or more failures.
"""

import argparse
import csv
import http.client
import socket
import ssl
import sys
import threading
import urllib.parse
from concurrent.futures import ThreadPoolExecutor, as_completed
from pathlib import Path

REPO_ROOT = Path(__file__).resolve().parent.parent
REDIRECT_MAP = REPO_ROOT / "migration" / "redirect-map.csv"
DEFAULT_REPORT = REPO_ROOT / "migration" / "crawl-diff-report.csv"

DEFAULT_HOST        = "stg.trailseries.bg"
DEFAULT_CONCURRENCY = 16
DEFAULT_TIMEOUT     = 15

REDIRECT_STATUSES = frozenset({301, 302, 303, 307, 308})

REPORT_FIELDS = [
    "old_url",
    "staging_url",
    "expected_status",
    "actual_status",
    "pass_fail",
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
) -> tuple:
    """
    Make a GET request, optionally following redirects.

    Returns (status, headers_dict, final_url, error_str).
    On error returns (None, {}, url, "description").
    """
    current = url
    seen: set[str] = set()

    for _ in range(max_hops):
        if current in seen:
            return None, {}, current, "redirect loop detected"
        seen.add(current)

        parsed = urllib.parse.urlsplit(current)
        host = parsed.netloc
        path = (parsed.path or "/") + (("?" + parsed.query) if parsed.query else "")

        try:
            conn = _make_conn(host, timeout, ssl_ctx)
            try:
                conn.request("GET", path, headers={"User-Agent": "tsr-crawl-diff/1.0"})
                resp = conn.getresponse()
                status = resp.status
                hdrs = {k.lower(): v for k, v in resp.getheaders()}
                resp.read()  # drain body so connection closes cleanly
            finally:
                conn.close()
        except socket.timeout:
            return None, {}, current, f"timed out after {timeout}s"
        except (http.client.HTTPException, OSError, ssl.SSLError) as exc:
            return None, {}, current, str(exc)

        if follow_redirects and status in REDIRECT_STATUSES:
            loc = hdrs.get("location", "")
            if not loc:
                return status, hdrs, current, "redirect with empty Location"
            current = urllib.parse.urljoin(current, loc)
            continue

        return status, hdrs, current, ""

    return None, {}, current, f"exceeded {max_hops} redirects"


# ── URL helpers ───────────────────────────────────────────────────────────────

def rewrite_host(url: str, host: str) -> str:
    p = urllib.parse.urlsplit(url)
    return urllib.parse.urlunsplit(("https", host, p.path, p.query, ""))


def norm_path(url: str) -> str:
    """Decode percent-encoding and strip trailing slash for path comparison."""
    return urllib.parse.unquote(urllib.parse.urlsplit(url).path).rstrip("/")


# ── Per-URL check ─────────────────────────────────────────────────────────────

def check_one(row: dict, host: str, timeout: int, ssl_ctx) -> dict:
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
        "notes":           "",
    }

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
    ap.add_argument("--out",         type=Path, default=DEFAULT_REPORT,
                    help=f"Output CSV (default: {DEFAULT_REPORT})")
    args = ap.parse_args()

    if not REDIRECT_MAP.exists():
        sys.exit(f"redirect-map not found: {REDIRECT_MAP}")

    ssl_ctx: ssl.SSLContext | None = None
    if args.no_verify:
        ssl_ctx = ssl.SSLContext(ssl.PROTOCOL_TLS_CLIENT)
        ssl_ctx.check_hostname = False
        ssl_ctx.verify_mode    = ssl.CERT_NONE

    rows = list(csv.DictReader(REDIRECT_MAP.open(encoding="utf-8-sig")))
    total = len(rows)

    print(f"Checking {total} URLs → https://{args.host}/", file=sys.stderr)
    print(
        f"Concurrency: {args.concurrency}  Timeout: {args.timeout}s"
        + ("  TLS: unverified" if args.no_verify else ""),
        file=sys.stderr,
    )

    results: list[dict] = []
    lock    = threading.Lock()
    counter = {"n": 0}

    with ThreadPoolExecutor(max_workers=args.concurrency) as pool:
        futs = {
            pool.submit(check_one, row, args.host, args.timeout, ssl_ctx): row
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

#!/usr/bin/env python3
"""
generate_redirects.py — Generate the WordPress redirect handler from redirect-map.csv.

Reads migration/redirect-map.csv and writes:
    wp-content/plugins/trailseries-results/includes/redirects.php

Run this whenever redirect-map.csv or redirect-prefixes.csv changes:
    python migration/generate_redirects.py

This generator is the only source of redirects.php. Never hand-edit the PHP:
the next regeneration silently drops the edit (audit H8: two July fixes were
lost that way). Behaviour decisions belong here:

- Hook: `template_redirect` priority 0, NOT `init`. The rules need the
  resolved query (see the singular gate below), which `init` does not have.
  The `init` move of 2026-07-04 (d9052ee) was about page-cache plugins; the
  host has none (no cache headers, checked 2026-11-13, audit question 10). If
  a page cache is ever added, it must not cache 404/410 responses.
- Singular gate (audit H7): a rule never fires when the request resolved to a
  real post, page, result or event (`is_singular()`, front page excepted).
  Legacy rules must not hide current content, e.g. an EventON event that
  reuses an old /events/{slug}/. Archive and pagination rules stay
  unconditional, since WordPress answers those with thin 200 pages.
- Prefix fallbacks come from migration/redirect-prefixes.csv
  (prefix,status_code,new_url; status 301 or 410). They run after the exact
  rules, only for requests that would otherwise 404, and are pattern rules,
  so ADR-003 rules 5 and 6 apply: add a family only as a deliberate decision.
"""

import csv
import io
import sys
import urllib.parse
from pathlib import Path

REPO_ROOT   = Path(__file__).resolve().parent.parent
REDIRECT_MAP = REPO_ROOT / "migration" / "redirect-map.csv"
PREFIXES     = REPO_ROOT / "migration" / "redirect-prefixes.csv"
OUT_PHP      = REPO_ROOT / "wp-content" / "plugins" / "trailseries-results" / "includes" / "redirects.php"


def norm_path(url: str) -> str:
    return urllib.parse.unquote(urllib.parse.urlsplit(url).path).rstrip("/")


def php_str(s: str) -> str:
    """Wrap a string in single-quoted PHP literal, escaping \\ and '."""
    return "'" + s.replace("\\", "\\\\").replace("'", "\\'") + "'"


def main() -> None:
    rows = list(csv.DictReader(REDIRECT_MAP.open(encoding="utf-8-sig")))

    redirects: dict[str, str] = {}
    gone: list[str] = []

    for r in rows:
        status = r["status_code"].strip()
        old_path = norm_path(r["old_url"])
        if not old_path:
            old_path = "/"

        if status == "301":
            new_path = urllib.parse.unquote(urllib.parse.urlsplit(r["new_url"]).path)
            if not new_path.endswith("/"):
                new_path += "/"
            if old_path != new_path.rstrip("/"):
                redirects[old_path] = new_path

        elif status == "410":
            gone.append(old_path)

    redirects_sorted = sorted(redirects.items())
    gone_sorted      = sorted(set(gone))

    prefix_rules: list[tuple[str, str, str]] = []
    if PREFIXES.exists():
        for r in csv.DictReader(PREFIXES.open(encoding="utf-8-sig")):
            prefix = urllib.parse.unquote(r["prefix"].strip())
            status = r["status_code"].strip()
            target = urllib.parse.unquote(urllib.parse.urlsplit(r.get("new_url", "").strip()).path)
            if not prefix.startswith("/") or not prefix.endswith("/"):
                sys.exit(f"prefix must start and end with '/': {prefix!r}")
            if status == "301" and not target:
                sys.exit(f"301 prefix without new_url: {prefix!r}")
            if status not in ("301", "410"):
                sys.exit(f"prefix status must be 301 or 410: {prefix!r} {status!r}")
            prefix_rules.append((prefix, status, target))

    lines = [
        "<?php",
        "/**",
        " * URL redirect and 410 Gone handler.",
        " *",
        " * Auto-generated from migration/redirect-map.csv — do not edit manually.",
        " * Regenerate: python migration/generate_redirects.py",
        " *",
        f" * 301 redirects: {len(redirects_sorted)} rules",
        f" * 410 gone:      {len(gone_sorted)} rules",
        f" * prefix rules:  {len(prefix_rules)} (from migration/redirect-prefixes.csv, 404 only)",
        " *",
        " * Behaviour (hook, singular gate, prefixes) is documented in the generator.",
        " *",
        " * @package trailseries-results",
        " */",
        "",
        "declare( strict_types=1 );",
        "",
        "add_action(",
        "\t'template_redirect',",
        "\tstatic function (): void {",
        "\t\t// Decode and strip trailing slash so we match regardless of slash style.",
        "\t\t$raw  = (string) ( parse_url( $_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH ) ?: '/' );",
        "\t\t$path = untrailingslashit( urldecode( $raw ) );",
        "\t\tif ( '' === $path ) {",
        "\t\t\t$path = '/';",
        "\t\t}",
        "",
        "\t\t// Legacy rules never hide current content (audit H7): if the request",
        "\t\t// resolved to a real post, page, result or event, serve it.",
        "\t\tif ( is_singular() && ! is_front_page() ) {",
        "\t\t\treturn;",
        "\t\t}",
        "",
        "\t\t$gone_response = static function (): void {",
        "\t\t\tstatus_header( 410 );",
        "\t\t\tnocache_headers();",
        "\t\t\twp_die(",
        "\t\t\t\tesc_html__( 'This page has been permanently removed.', 'trailseries-results' ),",
        "\t\t\t\tesc_html__( 'Gone', 'trailseries-results' ),",
        "\t\t\t\tarray( 'response' => 410 )",
        "\t\t\t);",
        "\t\t};",
        "",
        "\t\t// ── 301 Redirects ────────────────────────────────────────────────────",
        "\t\t$redirects = array(",
    ]

    for old, new in redirects_sorted:
        lines.append(f"\t\t\t{php_str(old)} => {php_str(new)},")

    lines += [
        "\t\t);",
        "",
        "\t\tif ( isset( $redirects[ $path ] ) ) {",
        "\t\t\twp_redirect( home_url( $redirects[ $path ] ), 301, 'TrailSeries' );",
        "\t\t\texit;",
        "\t\t}",
        "",
        "\t\t// ── 410 Gone ─────────────────────────────────────────────────────────",
        "\t\t$gone = array(",
    ]

    for p in gone_sorted:
        lines.append(f"\t\t\t{php_str(p)} => 1,")

    lines += [
        "\t\t);",
        "",
        "\t\tif ( isset( $gone[ $path ] ) ) {",
        "\t\t\t$gone_response();",
        "\t\t}",
        "",
        "\t\t// ── Prefix fallbacks (only for requests that would 404) ──────────────",
        "\t\t$prefixes = array(",
    ]

    for prefix, status, target in prefix_rules:
        lines.append(f"\t\t\tarray( {php_str(prefix)}, {status}, {php_str(target)} ),")

    lines += [
        "\t\t);",
        "",
        "\t\tif ( is_404() ) {",
        "\t\t\tforeach ( $prefixes as [ $prefix, $status, $target ] ) {",
        "\t\t\t\tif ( ! str_starts_with( $path . '/', $prefix ) ) {",
        "\t\t\t\t\tcontinue;",
        "\t\t\t\t}",
        "\t\t\t\tif ( 410 === $status ) {",
        "\t\t\t\t\t$gone_response();",
        "\t\t\t\t}",
        "\t\t\t\twp_redirect( home_url( $target ), 301, 'TrailSeries' );",
        "\t\t\t\texit;",
        "\t\t\t}",
        "\t\t}",
        "\t},",
        "\t0   // before redirect_canonical (priority 1) and any theme hooks",
        ");",
        "",
    ]

    php = "\n".join(lines)
    OUT_PHP.write_text(php, encoding="utf-8")

    print(f"Written {OUT_PHP}")
    print(f"  301 redirects: {len(redirects_sorted)}")
    print(f"  410 gone:      {len(gone_sorted)}")
    print(f"  prefix rules:  {len(prefix_rules)}")


if __name__ == "__main__":
    main()

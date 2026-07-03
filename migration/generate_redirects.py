#!/usr/bin/env python3
"""
generate_redirects.py — Generate the WordPress redirect handler from redirect-map.csv.

Reads migration/redirect-map.csv and writes:
    wp-content/plugins/trailseries-results/includes/redirects.php

Run this whenever redirect-map.csv changes:
    python migration/generate_redirects.py
"""

import csv
import io
import sys
import urllib.parse
from pathlib import Path

REPO_ROOT   = Path(__file__).resolve().parent.parent
REDIRECT_MAP = REPO_ROOT / "migration" / "redirect-map.csv"
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
        "\t\t\tstatus_header( 410 );",
        "\t\t\tnocache_headers();",
        "\t\t\twp_die(",
        "\t\t\t\tesc_html__( 'This page has been permanently removed.', 'trailseries-results' ),",
        "\t\t\t\tesc_html__( 'Gone', 'trailseries-results' ),",
        "\t\t\t\tarray( 'response' => 410 )",
        "\t\t\t);",
        "\t\t}",
        "\t},",
        "\t20  // before default template selection",
        ");",
        "",
    ]

    php = "\n".join(lines)
    OUT_PHP.write_text(php, encoding="utf-8")

    print(f"Written {OUT_PHP}")
    print(f"  301 redirects: {len(redirects_sorted)}")
    print(f"  410 gone:      {len(gone_sorted)}")


if __name__ == "__main__":
    main()

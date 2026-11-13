"""Regression tests for extract_canonical_results.py (audit H10).

One test per parser rule, most of them bugs or near-misses found on
2026-11-13. Synthetic HTML with invented names only — real results data
never goes into the repository.

Run:  python -X utf8 -m unittest discover -s migration/tests -v
"""

from __future__ import annotations

import json
import sys
import unittest
import xml.etree.ElementTree as ET
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / "migration"))

import extract_canonical_results as X  # noqa: E402

HEADER = "<tr><td>Място</td><td>Име</td><td>Фамилия</td><td>Време</td></tr>"


def row(place, first="Иван", last="Тестов", time="0:30:00") -> str:
    return f"<tr><td>{place}</td><td>{first}</td><td>{last}</td><td>{time}</td></tr>"


def extract(html: str) -> list:
    return X.PageExtractor({}).extract(html)


def item(slug: str, title: str, content: str) -> ET.Element:
    """A WXR <item> as the extractor reads it."""
    it = ET.Element("item")
    ET.SubElement(it, "title").text = title
    for tag, text in (("post_name", slug), ("post_type", "page"), ("status", "publish")):
        ET.SubElement(it, f"{{{X.NS['wp']}}}{tag}").text = text
    ET.SubElement(it, f"{{{X.NS['content']}}}encoded").text = content
    return it


def run_items(slug: str, content: str, overrides=None):
    page_list = {("T", slug): {"url": f"https://example.invalid/{slug}/", "notes": ""}}
    return X.extract_items([item(slug, "T", content)], page_list, {}, overrides or {}, {})


class PlaceRestartSplit(unittest.TestCase):
    def test_split_where_place_starts_again(self):
        secs = extract("<table>" + HEADER + row(1) + row(2) + row(1, "Мария", "Тестова") + "</table>")
        self.assertEqual([s.category for s in secs], ["", "[restart 2]"])
        self.assertEqual([len(s.rows) for s in secs], [2, 1])

    def test_split_never_shifts_occurrences_of_real_headings(self):
        html = ("<h3>5км</h3><table>" + HEADER + row(1) + row(2) + row(1) + "</table>"
                "<h3>5км</h3><table>" + HEADER + row(1) + "</table>")
        manifest, _, _, _ = run_items("p", html)
        keys = [(m["category_raw"], m["occurrence"]) for m in manifest]
        self.assertEqual(keys, [("5км", 1), ("5км [restart 2]", 1), ("5км", 2)])

    def test_dnf_rows_without_place_stay_with_their_segment(self):
        secs = extract("<table>" + HEADER + row(1) + row(2) + row("", "Петър", "Пробов", "DNF")
                       + row(1, "Мария", "Тестова") + "</table>")
        self.assertEqual([len(s.rows) for s in secs], [3, 1])


class LooseTextLabels(unittest.TestCase):
    def test_label_line_starts_section_only_for_headerless_table(self):
        html = ("Увод.\n16.7км Мъже\n<table>" + HEADER + row(1) + "</table>"
                "\n16.7км Жени\n<table>" + HEADER + row(1, "Мария", "Тестова") + "</table>"
                "\n11.5км Мъже\n<table>" + row(1, "Петър", "Пробов") + "</table>")
        cats = [s.category for s in extract(html)]
        # Header-row sections keep their raw category ('' here), so overrides
        # keyed on '' occurrences never break; the header-less table gets the label.
        self.assertEqual(cats, ["", "", "11.5км Мъже"])

    def test_loose_label_is_not_inherited_by_later_header_rows(self):
        html = ("\n16км\n<table>" + row(1) + row(2) + "</table>"
                "<table>" + HEADER + row(1, "Мария", "Тестова") + "</table>")
        cats = [s.category for s in extract(html)]
        self.assertNotIn("16км", cats[1:])

    def test_prose_is_never_a_label(self):
        self.assertTrue(X.LOOSE_CATEGORY.match("16.7км Мъже"))
        self.assertTrue(X.LOOSE_CATEGORY.match("5.2 км Жени"))
        self.assertTrue(X.LOOSE_CATEGORY.match("Деца"))
        self.assertFalse(X.LOOSE_CATEGORY.match("Предварително класиране на Trail Series"))
        self.assertFalse(X.LOOSE_CATEGORY.match(""))


class CategoryRows(unittest.TestCase):
    def test_dash_punctuation_is_ignored(self):
        html = ("<table>" + HEADER + "<tr><td></td><td>5км -</td><td>жени</td><td></td></tr>"
                + row(1, "Мария", "Тестова") + "<tr><td></td><td>5км -</td><td>мъже</td><td>точки</td></tr>"
                + row(1) + "</table>")
        cats = [s.category for s in extract(html) if s.rows]
        self.assertEqual(cats, ["5км жени", "5км мъже"])

    def test_distance_token_is_kept_even_though_it_is_a_column_word(self):
        html = ("<table>" + HEADER + "<tr><td></td><td>Мъже</td><td></td><td>6км</td></tr>" + row(1) + "</table>")
        self.assertEqual([s.category for s in extract(html) if s.rows], ["Мъже 6км"])

    def test_whole_cell_category_with_one_column_word(self):
        html = ('<table><tr><td colspan="3">17.7км Мъже</td><td>Точки</td></tr>' + HEADER + row(1)
                + '<tr><td colspan="3">17.7км Жени</td><td>Точки</td></tr>' + row(1, "Мария", "Тестова") + "</table>")
        self.assertEqual([s.category for s in extract(html) if s.rows], ["17.7км Мъже", "17.7км Жени"])


class BorrowedMapping(unittest.TestCase):
    def test_title_line_never_becomes_a_runner(self):
        html = ("<table><tr><td>МЪЖЕ</td><td>7 Hills Run - Hardcore Edition</td><td></td><td></td></tr></table>"
                "<table>" + HEADER + row(1) + "</table>")
        names = [r["first_name"] for s in extract(html) for r in s.rows]
        self.assertNotIn("7 Hills Run - Hardcore Edition", names)
        self.assertEqual(names, ["Иван"])


class Overrides(unittest.TestCase):
    def test_drop_and_label_overrides(self):
        html = "<h3>15KM</h3><table>" + HEADER + row(1) + "</table><h3>15KM</h3><table>" + HEADER + row(1) + "</table>"
        manifest, _, issues, stats = run_items("p", html, {("p", "15km", 1): "15KM МЪЖЕ", ("p", "15km", 2): X.DROP_SENTINEL})
        self.assertEqual([m["override_applied"] for m in manifest], ["15KM МЪЖЕ"])
        self.assertEqual(stats["dropped"], 1)

    def test_unresolved_repeat_warns_but_empty_sections_do_not(self):
        rows_html = "<h3>15KM</h3><table>" + HEADER + row(1) + "</table><h3>15KM</h3><table>" + HEADER + row(1) + "</table>"
        _, _, issues, _ = run_items("p", rows_html)
        self.assertTrue(any("UNRESOLVED CATEGORY COLLISION" in i for i in issues))
        empty_html = "<h3>15KM</h3><table>" + HEADER + "</table><h3>15KM</h3><table>" + HEADER + "</table>"
        _, _, issues, _ = run_items("p", empty_html)
        self.assertFalse(any("UNRESOLVED CATEGORY COLLISION" in i for i in issues))


class Times(unittest.TestCase):
    def test_documented_time_shapes(self):
        self.assertEqual(X.normalize_time("1:02:03"), ("1:02:03", None))
        self.assertEqual(X.normalize_time("01:21:27.000"), ("01:21:27", None))
        self.assertEqual(X.normalize_time("1.00,45"), ("1:00:45", None))
        self.assertEqual(X.normalize_time("36,11"), ("0:36:11", None))
        self.assertEqual(X.normalize_time("DNF"), (None, "DNF"))
        self.assertEqual(X.normalize_time("-"), (None, "DNF"))


class NamesHash(unittest.TestCase):
    def test_parity_fixture(self):
        fx = json.loads((ROOT / "tests/fixtures/names-hash.json").read_text(encoding="utf-8"))
        self.assertEqual(X.names_sha256(fx["rows"]), fx["sha256"])

    def test_names_kept_except_html_whitespace_collapse(self):
        # Documented contract (module docstring): names are copied byte-for-byte
        # AFTER HTML whitespace collapse — the old site rendered "a  b" as "a b".
        # Hyphens, apostrophes, Cyrillic and case are never touched or split.
        secs = extract("<table>" + HEADER + row(1, "  Анна-Мария  O'Тест ", "ван Пробна-Пример") + "</table>")
        self.assertEqual(secs[0].rows[0]["first_name"], "Анна-Мария O'Тест")
        self.assertEqual(secs[0].rows[0]["last_name"], "ван Пробна-Пример")


if __name__ == "__main__":
    unittest.main()

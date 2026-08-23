# ADR-005: Event/race listings order by proximity to "now"

- Status: Accepted
- Date: 2026-08-24

## Context

Multiple templates list races or calendar events relative to each other:
upcoming events (homepage, calendar page), past events for the current
year (calendar page), and — per season — the group of races shown on the
results archive (`/rezultati/`). These need one consistent principle for
"what comes first," rather than each template picking whatever order its
underlying query happened to produce.

`page-rezultati.php` grouped each season's races by event name in
whatever order `get_posts()`'s `orderby => date DESC` (import/edit time,
not race date) first encountered each event — arbitrary relative to the
actual calendar, and inconsistent with how the rest of the site already
ordered events.

## Decision

**Order by proximity to the present moment, symmetric in both
directions**: the soonest upcoming event leads an "upcoming" list; the
most recently completed event leads a "past" list. Put another way, for
any list mixing past and future within one race series, the entry
closest to "now" is always first — chronologically ascending after today,
descending before it.

Applied:

- **Upcoming events** (`front-page.php`'s "Предстоящи събития",
  `page-calendar.php`'s "Предстоящи"): `ajde_events` with
  `evcal_srow >= now`, ordered **ASC** — nearest future event first.
  Already implemented this way; no change needed.
- **Past events, current year** (`page-calendar.php`'s "Изминали тази
  година"): `ajde_events` with `evcal_srow < now`, ordered **DESC** —
  most recently completed event first. Already implemented; no change.
- **Races within a season** (`page-rezultati.php`'s per-year race
  groups): now sorted by matching each event's `ts_result` group to its
  `ajde_events` calendar entry (`_tsr_event_base` + year, same match
  `front-page.php`'s "Последни резултати" section uses) and ordering
  **DESC** by `evcal_srow` — the season's last race at the top, its
  first race at the bottom. Events with no calendar match (seasons that
  predate the EventON calendar) sort after every dated event in their
  year and keep their prior relative order among themselves.

## Consequences

- Positive: one principle, stated once, instead of each template
  re-deciding "newest first or oldest first" independently — a future
  listing of races/events has a rule to follow instead of guessing.
- Positive: `/rezultati/` no longer depends on import order, which could
  silently reshuffle race order every time content is re-imported or
  edited.
- Negative: the per-season race sort in `page-rezultati.php` depends on
  the `ajde_events` calendar existing and matching by event name/year —
  historical seasons imported without corresponding calendar entries fall
  back to insertion order (not wrong, just not date-accurate) rather than
  failing.

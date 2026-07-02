# Architecture Decision Records

Decisions that shape the trailseries.bg rebuild. One file per decision,
numbered, never deleted — superseded ADRs get `Status: Superseded by ADR-NNN`.

| ADR | Title | Status |
|-----|-------|--------|
| [ADR-001](ADR-001-results-as-cpt.md) | Results stored as a Custom Post Type with validated JSON meta | Accepted |
| [ADR-002](ADR-002-plugin-separate-from-theme.md) | Results logic lives in a plugin, separate from the theme | Accepted |
| [ADR-003](ADR-003-url-preservation.md) | Legacy URLs are preserved byte-for-byte, verified by crawl diff | Accepted |

## Template

```markdown
# ADR-NNN: Title

- Status: Proposed | Accepted | Superseded by ADR-NNN
- Date: YYYY-MM-DD

## Context
## Decision
## Consequences
```

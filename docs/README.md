# FreightMove SaaS Redesign Blueprint

This workspace contains the complete product, UX, architecture, and implementation blueprint for a modern freight marketplace platform redesign.

## Document Index

1. [Software Requirement Specification](01-srs.md)
2. [Information Architecture and Sitemap](02-information-architecture-and-sitemap.md)
3. [UI/UX Plan and Wireframes](03-ui-ux-plan.md)
4. [Architecture and Technical Design](04-architecture-and-technical-design.md)
5. [Database Schema](05-database-schema.md)
6. [REST API Specification](06-api-spec.md)
7. [Workflow and Process Diagrams](07-workflows-and-diagrams.md)
8. [Development Roadmap](08-development-roadmap.md) — **current build status**
9. [Legacy Data Migration](09-legacy-data-migration.md) — the import command and go-live procedure
10. [Domain Rules](10-domain-rules.md) — **legacy business logic merged with V2**
11. [Security](11-security.md) — audit findings, fixes, and the controls that must hold

### Source material

[`FREIGHTMOVE_LEGACY_ANALYSIS_AND_V2_SPEC.md`](FREIGHTMOVE_LEGACY_ANALYSIS_AND_V2_SPEC.md)
is the analysis of the old codebase, kept as supplied. Do not edit it — it is an
input. Its conclusions are reconciled against the live database in doc 10, which
is what the build follows.

## Design Direction

- Premium SaaS aesthetic inspired by Stripe, Uber Freight, Linear, Vercel, Notion, Airbnb, Slack, and Framer
- Modern dark/light-ready dashboard experience
- Enterprise-grade booking, quoting, matching, communication, and admin operations
- Scalable architecture for Angular 20 + Laravel 12 + MySQL + queue-based background services

## Implementation Note

These documents began as a design-first blueprint written before any code
existed. The application is now partly built, so **where a document and the code
disagree, the code is the truth** — the documents are being brought into line as
each area is completed.

Sections revised to match the build are marked with a note explaining what
changed and why. Three supersede the original plan outright:

- **Colour palette** (doc 03) — the approved template is navy and red, not the
  blue/green originally drafted.
- **Angular Material** (doc 04) — never adopted; the design system is
  hand-built on tokens.
- **Domain rules** (doc 10) — the marketplace logic is whatever the legacy data
  requires. Where the original blueprint and the live data conflict, the data
  wins, because it is what has to migrate.

For what is built versus outstanding, including the gaps that block launch, see
the [Development Roadmap](08-development-roadmap.md).

## Running locally

```bash
# MySQL must be running first (XAMPP Control Panel, or install it as a service)
cd api && php artisan serve      # http://127.0.0.1:8000
cd web && npm start              # http://localhost:4200
```

Demo accounts — `admin@`, `shipper@`, `carrier@freightmove.test`, all with the
password `password`.

# CLAUDE.md

Guidance for Claude Code (and humans) working in this repository.

## Project

PIT-Launchpad is a freshly scaffolded **Laravel 13** application. The Laravel
project lives at the repository root (`composer.json`, `artisan`, `app/`,
`routes/`, etc. are all top-level).

## Stack

| Concern        | Choice                                  |
| -------------- | --------------------------------------- |
| Language       | PHP 8.4+                                |
| Framework      | Laravel 13                              |
| Database       | PostgreSQL (`pgsql` connection)         |
| Sessions       | `database` driver                       |
| Cache          | `database` store                        |
| Queue          | `database` connection                   |
| Testing        | Pest 4 (`pestphp/pest`)                 |
| Asset bundling | Vite                                    |
| Code style     | Laravel Pint                            |

Because sessions, cache, and queues are database-backed, `php artisan migrate`
creates the supporting `sessions`, `cache`, and `jobs` tables out of the box.

## Common commands

```bash
# Dependencies
composer install
npm install

# Environment
cp .env.example .env
php artisan key:generate

# Database (PostgreSQL must be running and the database created)
php artisan migrate

# Run everything (server, queue, logs, Vite) together
composer run dev

# Tests
./vendor/bin/pest            # or: php artisan test
./vendor/bin/pest --filter=SomeTest

# Lint / format
./vendor/bin/pint            # apply fixes
./vendor/bin/pint --test     # check only

# Static analysis (Larastan)
./vendor/bin/phpstan analyse
```

## Testing conventions

- Tests are written with **Pest**, not the PHPUnit class style. Prefer the
  functional API:

  ```php
  test('it does the thing', function () {
      expect(true)->toBeTrue();
  });
  ```

- Feature tests live in `tests/Feature/`, unit tests in `tests/Unit/`.
- Shared setup, traits (e.g. `RefreshDatabase`), and custom expectations belong
  in `tests/Pest.php`.
- The test suite runs against an in-memory SQLite database and array drivers as
  configured in `phpunit.xml` — tests do not require a running PostgreSQL
  instance.

## Conventions

- Follow standard Laravel structure and naming. Run Pint before committing.
- Never commit secrets. `.env` is git-ignored; keep `.env.example` as the
  documented template (any new config key should be added there too).
- Add new database changes as migrations in `database/migrations/`.
- Before committing schema/model work, run the full gate: `migrate:fresh --seed`,
  `pint --test`, `phpstan analyse`, and `php artisan test` — all must be green.

## Domain model (§1 — foundation data layer)

The `§1` data layer is the multi-tenant control plane that builds and feeds
WordPress sites. It is schema + models only (no pillar features, WP sync, AI, or
UI). Key conventions:

- **ULID primary keys** everywhere (`HasUlids`); foreign keys use `foreignUlid`.
- **Backed enums** live in `app/Enums` and are applied as model casts — never
  store enumerated values as bare strings with magic values.
- **JSON columns** are cast to `array`.
- **Soft deletes** on `Content`, `ProofItem`, `MediaAsset`, `Silo`.

### Multi-tenancy

`Account` (1) — (N) `Site`. `Site` is the tenancy scope key; every
content-level table carries `site_id`.

- `App\Models\Concerns\BelongsToSite` applies a global `SiteScope` keyed on the
  resolved current site and auto-fills `site_id` on create. Use it on every
  site-scoped model.
- `App\Support\CurrentSite` is a request-lifetime singleton; resolve the tenant
  with `CurrentSite::id()` and set it via `CurrentSite::set($id)` (or the thin,
  swappable `ResolveCurrentSite` middleware). How a site is *selected*
  (subdomain, header, operator switch) is finalised in a later section.
- **Global records** (no `site_id`: `Account`, `User`, library-level
  `WireframeKit`) opt out by simply not using the trait. Use
  `Model::withoutGlobalScopes()` for cross-tenant/operator queries.

### Notes

- `Connection.credentials` uses the `encrypted:array` cast — no plaintext
  secrets at rest; `last_rotated_at` is the rotation hook.
- A single partial unique index enforces one `active` `VoiceProfile` per site.
- Two relationships are intentionally *not* DB-level foreign keys due to
  circular dependencies, populated after insert: `Silo.pillar_content_id` and
  `Keyword.target_content_id` (both indexed ULID columns).
- `database/seeders/DemoSeeder.php` builds one coherent demo tenant — the
  fixture later sections develop against.

## Keyword Generator (§5 — directed targeting + tracking)

`§5` turns silos + rule_sets into a prioritized, revenue-weighted plan of
cluster targets and tracks whether they win. It lives under
`app/KeywordGenerator/` and builds on §1 (and §4's silos/rule_sets — read from
`Silo.rule_set`, seeded as fixtures here).

- **Vendors are deferred.** All external data flows through capability-role
  interfaces with a **normalized contract**: `App\Integrations\Serp\SerpProvider`
  and `App\Integrations\LocalGrid\LocalGridProvider`, with normalized DTOs
  (`KeywordMetrics`, `SerpResult(Set)`, `GridMetrics`). `Mock*` implementations
  are the default container bindings; real adapters bind later with no change to
  scoring/beatability/tracking.
- **Opportunity** = `(w_d·Demand + w_i·Intent + w_v·BusinessValue) × Beatability`
  (`OpportunityScorer`, weights default `.35/.25/.45`, value-heavy). Demand is
  log-scaled volume; a vanity guard down-weights high-volume / no-revenue
  informational keywords. Quick-win build order ≈ `Opportunity × (1 − Difficulty)`.
- **Beatability is lane-aware** (`BeatabilityEngine`): `LaneClassifier` →
  local_pack vs organic; `CompetitorClassifier` (national/aggregator/local/
  editorial); local lane scored **per (keyword × market)** from grid data;
  organic gated by a coarse, self-calibrating `SiteAuthority` tier (derived from
  `PositionSnapshot` history). Below a floor a keyword is parked unless flagged a
  long-play. Output: 0–1 multiplier + lane tag + rationale.
- **Gap analysis** (`GapAnalyzer`) compares should-cover vs covered per silo and
  emits the prescriptive `GapBrief` (target, score/beatability/lane/intent, silo
  + page-type/kit, problem framing, coverage requirements **reusing the SERP
  pull**, proof hooks, internal links, differentiation, CTA, priority lane, SEO
  targets) into a quick-wins-ordered `GapBriefQueue`.
- **Position tracking** — `PositionSnapshot` time-series (organic series +
  per-market local series carrying `market_id`); `CannibalizationDetector` flags
  multiple owned URLs on one keyword.
- **Sampling cadence** — `Tiering` (value + market priority + lifecycle +
  volatility bump) and `CadenceScheduler` honor a per-tenant **budget ceiling**,
  degrading coverage/low tiers first and keeping forced event-triggers.
- `KeywordPipeline` runs discover → bucket (`Bucketer`, rule_set include/exclude)
  → score → gap end-to-end and writes scores back onto `Keyword` rows.

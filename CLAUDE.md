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

## Silo Creator (§4 — content-architecture skeleton)

`§4` turns a Site's Service Catalog + Targets into the silo tree (skeleton
only; §5 adds scored keyword targets → cluster pages). It lives under
`app/SiloCreator/` and builds on §1.

- **Auto-propose** (`AutoProposer`) runs two passes: `DeterministicProposer`
  (a `service_pillar` silo per `silo_role=pillar` service, its `ServiceProblem`s
  as candidate clusters) and `TopicalClusterer` (Claude-assisted clustering of
  problems + seed keywords into advisory themes → topical silos).
- **Proposals** are immutable value objects (`SiloProposal`, `RuleSet`,
  `ClusterCandidate`) in a reviewable `SiloProposalSet` (accept / edit / reject /
  merge), then `SiloCommitter` persists the tree (hierarchy, `Silo`↔`Service`
  mapping, rule_sets, pillars) in one transaction.
- **rule_sets** (`RuleSetSeeder`) seed `seed_terms` + `include_patterns` +
  `exclude_patterns` from service scope + problems (+ theme terms); §5 refines.
- **Viability guard** (`ViabilityGuard`) drops themes below a support threshold;
  **geo-neutral validator** (`GeoNeutralValidator`) rejects any silo/rule_set
  containing market/city/state terms (hard rule — geo lives only on location
  pages). `SiloCommitter` enforces it before writing.
- **Pillars** (`PillarFactory`) create/link a pillar `Content` stub per silo and
  pin `Silo.pillar_content_id`. **Internal linking** (`InternalLinking` +
  `silo_links` table) persists controlled cross-silo links; intra-silo
  pillar↔cluster/sibling links are derivable.
- **`wp_category_id` is left unset** — the §2 publish pipeline fills it (no WP
  push here).

### Integrations

- `App\Integrations\Claude\ClaudeClient` is the thin, swappable Claude seam
  (first use of the §2 adapter pattern). The default binding,
  `AnthropicClaudeClient`, uses the official Anthropic PHP SDK
  (`anthropic-ai/sdk`) with `claude-opus-4-8` + adaptive thinking; the model is
  configurable via `config/services.php` (`ANTHROPIC_API_KEY` / `ANTHROPIC_MODEL`).
  Tests bind a `FakeClaudeClient`, so no network call is made.

## Content Engine — Candidate funnel (§6a)

`§6a` is the first §6 sub-unit: it turns raw news intake (+ on-demand/backfill
sources) into scored, deduped, silo-routed, **draft-ready candidates** with angle
hints. Drafting (§6b), the review queue and publish (§6c) are **not** built here.
It lives under `app/ContentEngine/` and builds on §1 + §4.

- **Vendors deferred** — `App\Integrations\News\NewsProvider` and
  `App\Integrations\Embedding\EmbeddingProvider` (+ `OnDemandSourcePull`) with
  normalized DTOs and `Mock*` default bindings. Relevance scoring uses the §4
  `ClaudeClient` seam, contextually bound to the cheaper `scoring_model`
  (Haiku) for `RelevanceScorer`.
- **Pipeline** (`CandidateFunnel`): pre-filter (`PreFilter`) → same-story
  clustering (`SameStoryClusterer`) → relevance scoring → near-dup → routed
  candidates. `ingest()` for steady state; `backfill()` for first run.
- **Relevance** (`RelevanceScorer`, Haiku) is triple-duty: score + matched-silo
  routing (against §4 rule_sets, passed in-prompt) + advisory-angle hint, with a
  silo-match gate, a brand-safety/sensitivity gate, and a draft/borderline/drop
  band.
- **Near-dup** (`NearDuplicateDetector`): semantic similarity (embeddings, scoped
  to the matched silo) + keyword overlap; very-high vs a live page → **refresh**
  mark (don't duplicate) + operator alert; moderate → operator flag; low →
  proceed. Every dedup/refresh outcome raises an `OperatorAlert`.
- **First-run backfill** (`BackfillSplitter`): items older than the freshness
  cutoff (default 90d, tunable) become the **silo-discovery corpus**
  (`DiscoveryCluster`, never drafted); newer items flow normally.
- **§1 additions:** `RefreshEvent` (emitted later by §5/§6b/c) and
  `Content.source_name` / `source_url` + candidate fields (`matched_silo_id`,
  `angle_hint`, `relevance_score`, `local_relevance`). Candidates are `Content`
  posts (`reactive`, status `candidate`; borderline → `in_review`).

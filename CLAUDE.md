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

## Onboarding wizard (§7a — surfaces)

`§7a` is the first §7 slice: the onboarding wizard's intake-collection flow — a
resumable, role-gated multi-step **Filament** form that populates a Site's §1
entities. The client dashboard, operator-admin observability, and the wizard's
silo-selection step (9) wait on §4/§5/§6 and are not built here. It lives under
`app/Onboarding/` (+ `app/Filament/Pages/Onboarding`) and builds on §1 alone.

- **Filament** is installed (`filament/filament`, panel at `/admin`); its
  published `public/` assets are gitignored (regenerated by
  `php artisan filament:upgrade`).
- **Vendors deferred** — GBP (`GbpProvider`, category-seeded checklist), Census
  (`CensusProvider`, demographics), and Claude voice (`VoiceSynthesizer`) sit
  behind capability-role interfaces with `Mock*` default bindings.
- **`IntakeCollector`** persists each bucket into §1 entities (Site + WP
  credential `Connection`, `SiteBranding`/`Location`, `Service`+`ServiceProblem`,
  `Market` w/ Census enrichment, `ProofItem`, targets/conversion, assets,
  `VoiceProfile` synth + activate). The WP plugin handshake is stubbed.
- **`OnboardingWizard`** tracks resumable progress (`OnboardingState`:
  current_step + completed_steps), enforces hybrid authorship via `RoleGate`
  (client does steps 2–7; operator does account/voice/launch), and gates launch
  on `CompletenessChecker` (branding, service, priority market, substantiated
  proof, conversion config, active voice, keyword anchor).
- `WizardStep` enum is the dependency-ordered step list; step 9 (silo selection)
  is a wired placeholder. The Filament page is the surface; the validation gate
  is the service layer.

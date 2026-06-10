# CLAUDE.md

Guidance for Claude Code (and humans) working in this repository.

## Container-reset durability protocol

The build container resets without warning to a stale checkout, destroying
anything un-pushed (this has caused work loss three times). These rules are
mandatory:

- **Push early, push always.** Create a fresh, uniquely-named branch at the
  start of every work item and `commit` + `push` after every coherent layer
  (a migration, a service, a test file). Never accumulate more than a few
  minutes of un-pushed work.
- **WIP-push before every pause.** Before asking the user anything, ending a
  turn, or arming a check-in timer, push a WIP commit — those pauses are
  exactly the reset window.
- **Verify state on every session start and after any anomaly.** `git fetch`,
  compare `HEAD` to `origin/main`, and check for missing files/branches. If the
  checkout is stale: restore to current `origin/main`, report the reset, and
  rebuild only what is genuinely lost — never build on a stale base silently.
- **PR descriptions carry the spec.** Write each PR body complete enough that
  the work could be rebuilt from it alone — it is the recovery record when both
  the container and the conversation context are gone.
- **Never force-push over unmerged commits.** Branch names are disposable;
  commits are not. Unknown unmerged work gets diffed against `main` and either
  cherry-picked to its own PR or deleted only after confirming redundancy.

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

## Page Builder content contract (§3a)

`§3a` is the content-contract half of the Page Builder — schema + validation
only. **No LLM generation, no WordPress communication, no SEO/render** (those
need §2 and the generation work). It lives under `app/PageBuilder/`.

- **Kits as data.** The two locked kits (`service-page`, `location-page`) are
  authored as JSON in `database/data/wireframe-kits/` and seeded as library-level
  `WireframeKit` records by `WireframeKitSeeder`. A kit's full schema lives in the
  `slot_schema` JSON column; `version`/`page_type`/template + SEO refs are also
  denormalised to columns, unique on `(site_id, page_type, version)`.
- **Typed value objects** (`app/PageBuilder/Schema`): `KitSchema` → `SlotDefinition`
  → `SlotConstraints`/`Cardinality`/`MediaConstraints`/`SlotCondition`. They
  round-trip losslessly to/from `slot_schema`. `WireframeKit::schema()` returns the
  parsed `KitSchema`; `Content` pins `wireframe_kit_version`.
- **Slot enums:** `SlotContentType`, `SlotRole`, `SlotSource`
  (`generated|grounded|entity|client|media`) in `app/Enums`.
- **Validation engine** (`app/PageBuilder/Validation`): `KitValidator` checks
  structure (required/length/cardinality/content-type), media presence/size/alt,
  and entity/grounding resolution; it returns a structured `ValidationResult`
  (never throws for expected failures). `ThinPageGuard` holds a page from publish
  when its proof slots resolve to zero entity content. `PublishEligibility`
  orchestrates both and parks a failing page in `ContentStatus::InReview`.
- **Entity resolution** (`app/PageBuilder/Entities/EntityResolver`) maps entity
  keys (e.g. `proof.substantiated`, `reviews.market`, `location.nap`,
  `conversion.primary_action`) to §1 model counts, dropping only the `SiteScope`
  for determinism. `jobcapture.radius` resolves to 0 until the Job Capture
  section ships (no such §1 model yet).
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

## Security, Credentials & Tenancy Ops (§9 — credential vault hardening)

`§9` makes the platform safe for real clients: how secrets are stored, rotated,
access-controlled, how tenants are isolated, and how it is all audited. It
builds on §1 only (no §2/§3/§5/§6 dependency) and lives under `app/Security/`.

- **Two secret tiers.** *Platform* secrets (`PlatformSecret`: app_key, database,
  r2, fal_key, anthropic_key) live in env, never the DB. *Per-tenant* secrets
  live on `Connection.credentials` (`encrypted:array`, `site_id`-scoped).
- **The headline — pre-client rotation gate** (`SiteLaunchGate`): a `Site` cannot
  go `live` while any `Connection` is `compromised` / unrotated-since-`exposed_at`,
  or any required `PlatformSecret` lacks a `platform_secret_rotations`
  attestation. `check()` is pure and returns a structured `GateResult`
  (per-credential `GateCheck` checklist, red-until-green). `SiteLauncher` is the
  only place a site flips to `SiteStatus::Live`, and only when the gate passes.
- **Rotation tooling.** `ConnectionRotator` does no-downtime **verify-before-revoke**
  (the new credential is checked via the `ConnectionVerifier` seam — mock default,
  real per-provider adapters bind later — *before* the old is replaced; only then
  is `last_rotated_at` stamped and `compromised` cleared). `AppKeyRotator`
  re-encrypts every `Connection` across an APP_KEY change (decrypt-old →
  re-encrypt-new at the raw column level, round-trip safe). Commands:
  `launchpad:rotate-connection`, `launchpad:rotate-app-key`,
  `launchpad:attest-platform-rotation`.
- **Staleness** (`ConnectionStaleness` + `launchpad:check-stale-connections`,
  scheduled weekly): flags credentials past a config-driven per-provider
  threshold (`config/launchpad.php`). Advisory only — **never auto-rotates**.
- **RBAC + masked reveal.** `ConnectionPolicy` gates view/reveal/rotate to
  operators (`UserRole::Operator`); clients never see credentials.
  `CredentialMasker` renders `••••` + last 4. `CredentialRevealer` is the single
  audited path to plaintext (writes `AuditAction::CredentialRevealed`, never the
  secret itself).
- **Tenancy isolation** is the §1 `site_id` global scope; §9 adds regression
  tests proving tenant A cannot read tenant B's `Connection`/`Content`/`Silo`/
  media rows.
- **Audit** (`Audit` → append-only `AuditLog`, update/delete rejected at the
  model): reveal, rotation, role change, and go-live write rows. Publish
  (`ContentPublished`) attaches at the §2 pipeline (hook noted in
  `AppServiceProvider`).
- **§1 additions:** `Connection.compromised` (default `true`) /
  `compromised_reason` / `exposed_at`; `platform_secret_rotations` and
  `audit_logs` tables; enums `PlatformSecret` / `AuditAction` / `CredentialType` /
  `SiteStatus`. RBAC `User.role` already existed (§1) — not duplicated.

## Control-Plane Publish Pipeline (§2)

`§2` is the path from an **approved** `Content` to a live WordPress page. It
renders images, assembles the consolidated meta-blob, and pushes to the
companion plugin's authed REST contract — ending at "pushed to WP / state
recorded." It builds on §1/§3a/§4/§6b/§9 and lives under `app/Publishing/` +
`app/Integrations/{Fal,Vision,Wordpress}` + `app/Jobs`.

- **Contract authority is the companion plugin** (`wordpress-plugin/`, separate
  codebase). Three upsert endpoints under `launchpad/v1`, each keyed on the
  control-plane **ULID** so retries are idempotent: `/content` (the consolidated
  meta-blob: `content_id`/kind/page_type/kit/slug/status/locked + `slot_payload`
  + `images` + `seo`), `/silo` (returns `wp_category_id`), `/redirects`.
- **WP REST transport** (`WordpressClient` + `WordpressClientFactory`): Basic
  auth from the per-site WP app-password `Connection` (decrypted via §9's vault,
  never logged); transient 5xx/timeout retry with backoff; idempotent by ULID.
- **Render pipeline** (`ImageRenderer` + `RenderCoordinator`, `RenderImage` job):
  fal generate → R2 under the **per-tenant prefix** (`TenantStorage`) → Claude
  **vision** alt-text pass → minted `ImageObject`. Hardened from the pilot: fal
  HTTP timeout + job timeout, **bounded retries → `render_failed` terminal**, and
  `launchpad:reset-render` to requeue. A failed **required** image blocks publish
  (no partial page); images serve from R2/CDN, never the WP media library.
- **Publish jobs** (Horizon-ready `ShouldQueue`, idempotent by ULID):
  `PublishContent` (the entrypoint §6c calls — drives `approved → rendering →
  publishing → published`, with `render_failed`/`publish_failed` surfaced branches;
  stores `wp_post_id`; fires §9's `ContentPublished` audit), `PublishSilo`
  (carries §4's `wp_category_id`), `PublishRedirects`.
- **Locked / locally-edited protection**: `PublishContent` never overwrites a
  `locked` or `locally_edited` page (or one the plugin reports skipped) — it skips
  with a surfaced warning.
- **Meta-blob** (`MetaBlobAssembler`): §3a slot values pass through keyed by slot
  key (the plugin's `lp/*` tags read them); the kit's `og_image` seo_binding picks
  the OG image; SEO is engine-owned (title/meta/canonical/robots/og/schema/
  breadcrumbs). NO ACF.
- **Adapters** (committed, mocked in tests): `FalClient`→`FalHttpClient`,
  `VisionClient`→`ClaudeVisionClient`, WP REST (Http-faked), R2 (Storage-faked).
  **§9's `ConnectionVerifier` is now WP-backed** (`WordpressConnectionVerifier`):
  rotation's verify-before-revoke pings live WordPress; non-WP providers stay
  permissive until their adapters land.
- **§1 additions:** `ContentStatus::Rendering`/`PublishFailed`;
  `Content.locked`/`locally_edited`/`last_publish_error`; `render_jobs` per-image
  fields (slot, seo_filename, alt/title/caption, required, attempts, width/height).
  `Silo.wp_category_id` (§4) is now filled by `PublishSilo`.

## Content Engine review queue + publish wiring (§6c)

`§6c` is the operator **review queue** — the command center where `needs_review`
drafts are triaged, edited, and approved into publish. It **closes the
pipeline**: approve → §2's `PublishContent`. So intake → draft → review →
approve → render → publish now runs end to end. It lives under
`app/ContentEngine/Review/` (logic) + `app/Filament/Resources/ContentReviewResource`
(surface) and builds on §1/§6a/§6b/§9/§2/§7a.

- **§6c ↔ §7 boundary:** §6c is the *queue itself* (the Filament resource);
  §7 is the cockpit around it (portfolio triage, dashboards, coverage
  workspace). §7 embeds this queue as its home base.
- **Logic in testable services** (UI-agnostic): `ReviewQueue` (the actionable
  status set — needs_review / in_review / render_failed / publish_failed —
  flagged-first ordering, filters), `AlertFlags` (derives the flagged-lane
  alerts from persisted upstream state + DB filters), `ReviewActions`
  (approve / reject / lock / bulk / edit-in-place).
- **Flagged-lane alert center** (`ReviewFlag`): render_failed (§2, blocks
  approve), unsupported-claim (§6b verification, warns), near-duplicate (§6a
  linkage), brand-safety (meta flag), on-demand (non-reactive trigger),
  relevance-band (in_review). Informational + filterable — never auto-rejects.
- **Approve → publish** (`ReviewActions::approve`): validates (a required-image
  `render_failed` hard-**blocks**; an unsupported claim **warns**), flips to
  `approved`, and **enqueues `PublishContent`** (§2's idempotent-by-ULID job —
  a refresh re-publish updates the same WP post). Bulk-approve applies the same
  guard per item.
- **Review detail** (`EditContentReview`): edit kit slots / body / SEO in place;
  saves route through `ReviewActions::saveEdits` so SEO merges into `meta`
  without clobbering the drafter's image specs.
- **Operator-only** (`ContentReviewResource::canAccess` → `UserRole::Operator`).
- **§1 additions:** `Content.reject_reason`, `Content.near_dup_of_content_id`
  (deferred-FK, populated by §6a's near-dup detection); `ReviewFlag` enum;
  `SiteStatus::Onboarding` (reconciling §7a's lifecycle with §9's enum).
- **Out of scope (→ §7):** portfolio triage, dashboards/funnel/stat-cards,
  coverage workspace, client performance dashboard, scheduled publishing.

## Operator-Admin Cockpit (§7b — surfaces)

`§7b` is the operator's multi-tenant cockpit that wraps the §6c review queue:
monitor the pipeline across all tenants, and hand sites over to clients **through
the §9 gate**. Operator-only and internal. Logic lives in testable services under
`app/Operator/`; Filament surfaces are thin over them. Shipped in stages — **(a)**
portfolio triage + pipeline dashboards + the handover gate (this), then **(b)**
coverage/targeting workspace, **(c)** controls.

### Site lifecycle (locked)
`Onboarding →(wizard complete)→ Active →(operator handover, §9-gated)→ Live`.
Every install is a fresh blank WordPress instance the platform controls — so
**§2 publish is NOT gated on Live**: content flows to the blank instance from
`Active`. `Live` is the **client-handover** milestone, not a publish switch.

### Stage (a)
- **Portfolio triage** (`PortfolioHealth` → `SiteHealth`; `SiteResource`): every
  tenant with at-a-glance health — review backlog, job failures, published/week,
  §9 compromised credentials — most-urgent-first, click-through to the tenant's
  review queue.
- **Pipeline dashboards** (`PipelineMetrics`; widgets): stat cards, funnel
  (candidate → published), per-silo volume, published/week trend, job health —
  computed for the whole portfolio or a single tenant.
- **Site handover** (`SiteHandover` — THE invariant): the single guarded path to
  `Live`. Every path routes through §9's `SiteLauncher` (the sole writer of
  `SiteStatus::Live`) so the gate always runs and blocks until credentials are
  clean. **Stays-on-our-hosting** → gate → Live (same Connection). **Migrate-to-
  client-hosting** → re-point the WP Connection (new URL + fresh app password) →
  verify against the new host (a §9 rotation, verify-before-revoke) → gate → Live;
  the engine resumes there. `SiteWentLive` audited.
- **Panel gate:** the whole admin panel is operator-only via
  `User::canAccessPanel` (`FilamentUser`).

### §7b stage (b) — Coverage / targeting workspace
Manage what the engine targets. Services under `app/Operator/Coverage/`; Filament
resources are thin over them.
- **Target queue + gaps** (`TargetQueue`; `KeywordResource`): §5 keyword targets
  opportunity-sorted; **gaps** = uncovered keywords (no `target_content_id`),
  **queue** = priority-then-opportunity. Operator promote/demote sets a `priority`
  override on the keyword.
- **Silo management** (`SiloManager`; `SiloManagementResource`): view/edit §4
  silos with §4's `ViabilityGuard` surfaced — a thin silo (below the
  keyword-support floor) is flagged.
- **Position tracking** (`PositionTracking` → `KeywordStandings`): the §5 two-lane
  data — latest organic standing + per-market local-pack standings, a
  cannibalization flag (multiple owned URLs in one capture), and refresh-ROI
  markers (RefreshEvent count on the target content + the organic rank series).
- **§1 additions:** `Keyword.priority` (operator target-queue override).

### §7b stage (c) — Controls (engine configuration, per tenant)
The final §7b stage. Services under `app/Operator/Controls/`; Filament resources
thin over them. Completes the operator cockpit.
- **Connections** (`ConnectionsResource`): §9 connection management — credentials
  **masked** (`CredentialMasker`), explicit **audited reveal** (`CredentialRevealer`
  writes the audit row, plaintext only to an operator), **rotate** wired to §9's
  verify-before-revoke `ConnectionRotator`, compromised/unrotated gate flags.
- **Feeds** (`FeedControl`; `SourceResource`): view / add / remove / enable §6a
  source feeds; backfill/freshness tunables on the feed config.
- **Budget + cadence** (`BudgetControl`, `CadenceControl`; SiteResource action):
  set the §5 per-tenant budget ceiling; usage-against-budget **read-only**
  (metered billing deferred); the sampling-tier degradation order (C→B→A) shown.
- **Voice** (`VoiceControl`; `VoiceProfileResource`): view versioned profiles, the
  active version, which version is pinned on recent content; activate a version
  (archives the prior active).
- **§1 additions:** `Source.enabled`, `Site.budget_ceiling`.

## Client Performance Dashboard (§7c — surfaces)

`§7c` is the **client-facing** performance dashboard — a SEPARATE, white-labeled
Filament panel (id `client`, path `/portal`), client-gated and read-only. The one
client-facing surface. View-models under `app/Client/`; widgets under
`app/Filament/Client/Widgets/`.

- **Honest framing (hard constraint):** the dashboard demonstrates value without
  over-claiming. Refresh↔position is shown as **observed correlation** (refresh
  markers annotate the position trend) — **no causal claims, no stored/computed
  ROI-attribution field** (the `conversions` table has no roi/attribution/value
  column; `PositionTrends` emits only `series` / `refresh_markers` (dates) /
  `standings`). Leads are totals + trends, never per-action attributed. Guarded
  in code + tests.
- **Separate client panel** (`ClientPanelProvider`): client-gated via
  `User::canAccessPanel` (panel-aware — clients never reach the operator panel,
  operators never reach the client panel); **white-labeled per Account**
  (brand name / logo / primary color resolved dynamically from the client's
  Account — Launchpad is invisible); Account-scoped via `ClientAccess` +
  `ClientContext` with a Site switcher (session-selected, owned-only).
- **Leads/conversions headline** (`LeadsMetrics`; `LeadsHeadlineWidget`): total +
  weekly trend, from the **mock-first** GA4/GHL `ConversionProvider` seam →
  `Conversion` model.
- **Ranking gains + position trends** (`RankingGains`, `PositionTrends`): moved-up
  / newly-ranked keywords; organic series + SERP standings
  (primary/secondary/tertiary, as-of) with refresh markers as correlation.
- **Local grid heatmap** (`LocalGrid`; `LocalGridWidget`): per-market local-pack
  visibility.
- **Content/coverage + quick-wins** (`CoverageSummary`, `QuickWins`): published
  body of work per silo; early low-difficulty keywords that landed.
- **Performance card grid** (`PerformanceCards`; `PerformanceCardsWidget`): the
  *performance* face of the lifecycle card — per published page, its best
  position + refresh history + publish date.
- **§1 additions:** `Account` white-label fields (brand_name/logo_url/
  primary_color/accent_color); `Conversion` model + table (no attribution column,
  by design); enums `ConversionType` / `ConversionSource`; the `ConversionProvider`
  (GA4/GHL) mock-first seam.

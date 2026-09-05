# Tenant-lock remediation — the complete inventory & sequence

**Acceptance criterion (enforced by `tests/Feature/Security/TenantLockLeakTest.php`):** no `/admin` surface may
resolve or display a site other than the locked `App\Operator\ActiveTenant` — not via a picker, a second
session key, a query param, a cross-tenant listing, or a dropped `SiteScope`. A page rendered under a lock
on tenant A must contain **no other tenant's `brand_name`, `id`, or `domain_url`** anywhere in its output,
and a foreign `?site=` / `?content=` / `?location=` must not resolve B's data.

Three prior sweeps each found a different SHAPE of this bug and each was reported complete. This is the
full inventory, classified by shape, with the remediation type per surface. The test is the ratchet: as
each step lands, its surfaces move into the asserted-clean set and out of the skip/known-leak list.

## `discoverResources` — off-nav is not a mitigation
The panel disables Filament's auto-nav and renders a bespoke header, BUT `discoverResources` /
`discoverPages` still register every route. So every Filament resource is directly URL-reachable
regardless of nav. Any resource whose `getEloquentQuery` drops `SiteScope` renders cross-tenant data at
its URL. Confirmed by auditing every resource's `getEloquentQuery`:

**7 resources drop `SiteScope` → cross-tenant data, all URL-reachable:** `AiContentResource`,
`CandidateResource`, `ContentEditResource`, `ContentReviewResource`, `PageResource`,
`PublishedContentResource`, `ReviewCaptureResource`. Every other resource keeps `SiteScope` (data locked).

## The shapes

- **A — name leak.** Data is locked (`SiteScope` kept) but the UI exposes other tenants' names via an
  all-tenant `SelectFilter('site_id')->relationship('site','brand_name')` + a "Tenant" column. Fails the
  criterion (a dropdown lists every tenant). Surfaces: `KeywordResource`, `SiloManagementResource`,
  `ConnectionsResource`, `SourceResource`, `VoiceProfileResource`, `ServiceResource`, `LocationResource`,
  `GeoPromptResource`, `CoverageScanPlanResource`, `BlogTargetResource`, `TenantSharedPhoneResource`,
  `LocationNapProfileResource` (verify).
- **B — URL param overrides the lock (most severe).** A query string resolves another tenant's data,
  bypassing `ActiveTenant`; the gate only refuses a foreign `?site=`, not `?content=`/`?location=`.
  - `ProofEditor` (`proof`) — `request()->query('content')` → `Content::withoutGlobalScope->find` (`:85,379`); this is the approve-and-publish surface.
  - `Citations\CitationsWorkspace` (`citations/workspace`) — `?location=` → `CurrentSite::set(location.site_id)` (`:55,59,77`).
  - `Citations\CitationsReport` (`citations/report`) — same.
  - `LocationDashboard` — `#[Url] public ?string $siteId` (`:42`); mount overrides from ActiveTenant, but remove the attribute (it is the exact shape-2 pattern).
- **C — cross-tenant listing** in a tenant nav slot or the locked landing.
  - `OperateDashboard` (`operate/dashboard`) — all-tenants AttentionBoard; reached via `Lobby::enter`/`badgeUrl` + `TenantSwitcher:38` + `SiteResource:188` redirects.
  - `CitationsPortfolio` (`citations`, **Territory nav**) — one row per tenant.
  - `Overview` (`/`, panel landing) — loops `Site::query()->get()`.
  - `ReviewCaptureResource` (**Build·Reviews nav**) — drops `SiteScope`, no default tenant filter. Fix in place (about to carry ~1,290 imported reviews; a wrong-tenant approval publishes a customer's words on another company's site).
- **D — `withoutGlobalScope(SiteScope)` list reads not constrained to ActiveTenant:** the 7 scope-dropping resources above.

## Legitimately cross-tenant (leave — already declared lobby-scope)
`Lobby` (clears ActiveTenant on mount; allowlisted in `EnsureTenantSelected`) and `SiteResource` index /
Portfolio (the tenant picker; allowlisted). These are the ONLY surfaces that answer a portfolio-wide
question. `ContentEditResource` is **not** one of these — it's per-tenant audit detail stored globally, so
it gets `SiteScope`, not an allowlist (if a cross-tenant view is ever needed it belongs in the Lobby).

## Remediation sequence (each step is a PR; each turns the test greener)

1. **The test** (this PR) — the acceptance guard; asserts the 14 correct nav pages clean, codifies the
   breaches as skipped/known-leak with their step. Lands first.
2. **Shape B** — the lock overrides. Route `ProofEditor` / `CitationsWorkspace` / `CitationsReport` through
   `ActiveTenant` (resolve the record scoped to the locked site; 404/redirect a foreign id); strip the
   `#[Url]` from `LocationDashboard`. Un-skip the two param-case tests.
3. **Repoints** — `Lobby::enter`/`badgeUrl`, `TenantSwitcher`, `SiteResource::selectTenant` → `TenantDashboard`; `ConsoleNav` Territory·Citations → `CitationsBoard`; panel landing → `TenantDashboard`. Retire `operate/dashboard` + `citations` portfolio to the Lobby.
4. **Shape D / Build·Reviews** — default the tenant filter to `WorkingTenant::id()` or keep `SiteScope` on the 7 scope-dropping resources; add `SiteScope` to `ContentEditResource`.
5. **Shape A** — strip the "Tenant" column + all-tenant `SelectFilter('site_id')` from the locked resources; pre-set create-form `site_id` to `ActiveTenant::id()`.
6. **`?site=` link removal** — delete the 20 `getUrl(['site'=>...])` args (dead readers, live vectors — a bookmarked `?site=X` under a lock on Y silently mis-scopes, and becomes real ambiguity under URL-path tenancy); route the `?content=`/`?location=` drill links through the now-scoped resolvers.

When steps 2–6 land, `TenantLockLeakTest` asserts every surface clean and the known-leak list is empty.

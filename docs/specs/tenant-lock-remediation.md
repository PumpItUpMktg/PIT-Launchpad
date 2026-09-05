# Tenant-lock remediation — the complete inventory & sequence

**Acceptance criterion (enforced by `tests/Feature/Security/TenantLockLeakTest.php`):** no `/admin` surface may
resolve or display a site other than the locked `App\Operator\ActiveTenant` — not via a picker, a second
session key, a query param, a cross-tenant listing, or a dropped `SiteScope`. A page rendered under a lock
on tenant A must contain **no other tenant's `brand_name`, `id`, or `domain_url`** anywhere in its output,
and a foreign `?site=` / `?content=` / `?location=` must not resolve B's data.

Three prior sweeps each found a different SHAPE of this bug and each was reported complete. This is the
full inventory, classified by shape, with the remediation type per surface. The test is the ratchet: as
each step lands, its surfaces move from red to asserted-clean and the whole-page failure count drops.

## Standing rules (learned here — apply to every guard from now on)

1. **A test proving absence must first be proven capable of detecting presence.** Seed the exact thing
   you assert isn't there, watch the test go RED, *then* write the fix that turns it green. A guard that
   is green on a broken base is worse than no guard. This bug hid four times because each check was green
   while never reaching the leak path:
   - **The `?site=` sweep** keyed on the wrong mechanism (looked for `?site=` producers, missed the
     `?content=`/`?location=` param shape and the dropped-scope shape entirely).
   - **The account-wide membership test** passed while never exercising an account-wide operator.
   - **The first version of THIS guard** seeded the foreign tenant B but made the operator a member of
     tenant A only — so `VisibleSiteScope` hid B from the very `Site::query()` loops that leak
     (`CitationPortfolio`, `AttentionBoard`, `Overview`). The fixture made the breach invisible: the page
     rendered a one-tenant list and B could not appear. Fixed by making the operator a member of BOTH
     accounts (the real multi-tenant operator shape). This is the same shape as the two failures above.
2. **A skip list is a hiding place.** Parked assertions tagged "un-skip at step N" are how a green suite
   carries a known live breach — the FOURTH hiding place found here (the old guard on #730 skipped its
   breaching surfaces to stay green). Guards assert every surface directly; a breach is red, never skipped.
3. **When a fix breaks existing tests, the first question is whether those tests were asserting the bug.**
   A test that only passes with the vulnerability present is documenting the vulnerability as intended
   behaviour. The FIFTH instance found this session: `ProofEditorTest`, `CitationsWorkspaceTest`, and
   `CitationsReportTest` all passed because **no test ever established a tenant lock** — they exercised the
   scope-drop (`withoutGlobalScope(SiteScope)->find`) as though cross-tenant resolution were the feature.
   The step-B fix (resolve within the lock) correctly broke them; the right response was to make them
   establish the lock (the real locked-operator path), not to weaken the fix. Catch this deliberately: a
   red existing test after a security fix is a signal to check what that test was really asserting.

The five false-greens this session, in one line each — same shape every time (green while never reaching
the real condition): the `?site=` sweep (wrong mechanism) · the account-wide membership test (never
exercised an account-wide operator) · the skip list (breaches parked, not asserted) · the single-account
fixture (VisibleSiteScope hid B) · the three test files above (no lock ever set).

The guard asserts **whole-page** output (not a content region): the acceptance baseline is **0 green** —
nothing is currently compliant, and that is the honest starting point. Each step turns its surfaces green.

## 404 vs redirect — reasoned per surface (not incidental)

Two distinct denials, chosen by *what* is out of the lock:
- **404 — a RECORD outside the lock.** A foreign `?content=`/`?location=`/`?siteId=` is indistinguishable
  from "doesn't exist" for this operator, which is the correct signal. Shape B uses 404 (ProofEditor,
  CitationsWorkspace, CitationsReport).
- **Redirect — a ROUTE that doesn't fit the locked context.** A cross-tenant surface reached under a lock
  is not a missing record; it's the wrong surface for a locked operator, so it redirects to the surface
  that *does* fit — the locked tenant's equivalent (OperateDashboard/Overview → TenantDashboard;
  CitationsPortfolio → CitationsBoard), keeping the lock rather than dropping it. Redirect-to-Lobby is
  reserved for the no-lock case the `EnsureTenantSelected` gate already handles (a locked-only route with
  no tenant selected → pick one).

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
- **E — the site switcher in the locked chrome.** `resources/views/livewire/tenant-switcher.blade.php`
  renders a dropdown of EVERY tenant the operator can switch to, on EVERY page via the `TOPBAR_AFTER`
  render hook. The page data region is clean, but the picker chrome carries other tenants' names lock-wide.
  The relay is explicit: **no site switcher in the locked chrome** — "Changing site is Exit site → lobby →
  enter. Deliberate friction; it is the feature." This affordance was supposed to be removed with the
  sweep and survived as chrome. Under a lock the header shows the CURRENT tenant only, plus **Exit site**.

## Observed baseline (measured under a lock on A, operator member of A+B)

Whole-page assertion, 37 surface checks, all RED. Data-region leaks vs switcher-only chrome:
- **Data-region leaks (22):** Citations portfolio · OperateDashboard · Overview; the 7 SiteScope-dropping
  resources (Reviews-capture, Pages, Published, Content-review, AI-content, Candidates, Content-edits); the
  7 shape-A resources (Keywords, Silos, Connections, Feeds, Voice, Services, Locations); shape-B
  `ProofEditor?content=` and `CitationsReport?location=`.
- **Switcher-only, data clean (15):** the 14 ActiveTenant-scoped nav pages + `CitationsWorkspace?location=`
  (Workspace already declines the foreign `?location=`; only its sibling Report leaks — confirm in step B).

## Legitimately cross-tenant (leave — already declared lobby-scope)
`Lobby` (clears ActiveTenant on mount; allowlisted in `EnsureTenantSelected`) and `SiteResource` index /
Portfolio (the tenant picker; allowlisted). These are the ONLY surfaces that answer a portfolio-wide
question. `ContentEditResource` is **not** one of these — it's per-tenant audit detail stored globally, so
it gets `SiteScope`, not an allowlist (if a cross-tenant view is ever needed it belongs in the Lobby).

## Remediation sequence (ONE branch, red guard first; commit per step; merge when green)

The guard is pushed RED (0 green). Steps land as commits on the same branch so the whole-page failure
count drops visibly at each one — the red→green transition is the review artifact. `#730` (the flawed
green-on-broken-base guard) is closed, not repurposed.

0. **The guard** (pushed) — whole-page acceptance test, every surface asserted directly, 0 green baseline.
1. **Shape B** — the lock overrides. Route `ProofEditor` / `CitationsWorkspace` / `CitationsReport` through
   `ActiveTenant` (resolve the record scoped to the locked site; 404/redirect a foreign id); strip the
   `#[Url]` from `LocationDashboard`. → `ProofEditor?content=`, `CitationsReport?location=` green.
2. **Repoints** — `Lobby::enter`/`badgeUrl`, `TenantSwitcher`, `SiteResource::selectTenant` → `TenantDashboard`; `ConsoleNav` Territory·Citations → `CitationsBoard`; panel landing → `TenantDashboard`. Retire `operate/dashboard` + `citations` portfolio to the Lobby. → OperateDashboard, Citations-portfolio, Overview off the tenant path.
3. **Shape D / Build·Reviews** — keep `SiteScope` on the 7 scope-dropping resources (default the tenant filter where a genuine cross-tenant read is still wanted, in the Lobby only); add `SiteScope` to `ContentEditResource`. → the 7 resources green.
4. **Shape A** — strip the "Tenant" column + all-tenant `SelectFilter('site_id')` from the locked resources; pre-set create-form `site_id` to `ActiveTenant::id()`. → the 7 shape-A resources green.
5. **Shape E — the switcher** ✅ — the tenant dropdown is removed from the locked chrome; the header shows
   the current tenant only + **Exit site** (→ Lobby → enter). With this, `TenantLockLeakTest` is **fully
   green** — every URL-reachable admin surface renders under a lock with zero foreign-tenant markers, and
   every foreign `?content=`/`?location=`/`?siteId=` is 404-denied.
6. **`?site=` link removal** — delete the `getUrl(['site'=>...])` args (dead readers, live vectors — a bookmarked `?site=X` under a lock on Y silently mis-scopes, and becomes real ambiguity under URL-path tenancy); route the `?content=`/`?location=` drill links through the now-scoped resolvers. Hardening only — the guard is already green; this closes latent vectors before URL-path tenancy.

**Status: steps 1–5 landed; the acceptance guard is green.** Step 6 is latent-vector hardening.

## Lobby badge tiers — authoritative (15 conditions)

The cross-tenant OperateDashboard is deleted; the Lobby is the sole cross-tenant surface. Its per-card
attention badges are the authoritative portfolio-level signal, in a single aggregated pass (no per-card
query). Two conditions were absorbed from the retired dashboard (marked ← dashboard); raw blog candidates
stay deliberately unbadged (Review-stage only; a bare candidate count would swamp everything).

- **Tier 1 — BrokenBlocking (publishing blocked, red):** `wp_connection` (WP compromised) · `publish_failed` · `render_failed`.
- **Tier 2 — WrongData (wrong data reaching the public, red):** `wrong_nap` · `held_market` · `reviews_no_market` · `setup_gaps` (live site missing service / served towns / active voice / WP — ← dashboard).
- **Tier 3 — WorkWaiting (work waiting on a person, amber):** `reviews_pending` · `jobs_review` · `pages_review` · `blog_review`.
- **Tier 4 — Degrading (degrading quietly, grey):** `feeds_bad` · `coverage_overdue` · `starved_queues` (silo blog queue run dry — ← dashboard).

15 conditions (was 13). Onboarding tenants show a progress card, never operational badges.

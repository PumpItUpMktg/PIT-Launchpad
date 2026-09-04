# PR 2b — `/admin/{tenant}` URL scoping (turnkey plan)

**Status:** groundwork landed (dormant `HasTenants` adapter on `User`); full build
deferred to an uninterrupted run. This doc is the complete spec so the pickup does
**not** depend on conversation context.

Relay 3, "Super-user UI — sequenced build." 2b is the one step with no incremental
checkpoint — the panel path flips from `/admin/…` to `/admin/{tenant}/…` in a single
coherent change, and ~19 HTTP/URL test files move together. Do it in one sitting.

## Why 2b is decoupled from PR 4 / PR 5

PR 4 (dashboard) and PR 5 (nav) depend on **2c** (the cross-tenant write guard,
already merged), **not** on 2b's URLs. 2b changes only *where the panel lives in the
URL*; it does not change what a locked tenant can read or write. So 2b can land before
or after PR 4/PR 5 without reordering them. The three tenant-lock invariants
(`ActiveTenant` lock, `CurrentSite` → `SiteScope`, the write guard) are all in place on
`main` and remain the source of truth after 2b.

## The mechanism finding (why Option B, not a custom prefix)

The obvious approach — a custom `->path('admin/{tenant}')` — **does not work**, proven
empirically:

- `->path('admin/{tenant}')` registers the login route as `admin/{tenant}/login`. Login
  happens **before** a tenant is chosen, so there is no `{tenant}` value to fill → the
  login URL cannot be generated.
- `->path('admin/{tenant?}')` (optional segment) makes Filament emit `/admin//login` —
  a malformed double-slash path — because the optional segment collapses to empty but
  the separator stays.

Neither is viable. The resolution is **Option B — Filament native tenancy routing**:
call `->tenant(Site::class)` on the panel. Filament then registers the `{tenant}`
segment **only on the tenant-scoped route group**, and keeps auth routes
(`/admin/login`, `/admin/logout`) flat and tenant-free. A thin `HasTenants` adapter on
`User` supplies the tenant list; `ActiveTenant`/`CurrentSite` stay the source of truth
and the URL tenant is verified against the lock.

### Groundwork already on `main`-track (this branch)

`User implements HasTenants` with `getTenants()` (permitted Sites) and
`canAccessTenant()` (delegates to `canSeeSite()`). **This is dormant**: Filament gates
every tenancy code path behind `Panel::hasTenancy()`, which is literally
`filled($this->getTenantModel())` — true only once `->tenant(...)` is called.
`IdentifyTenant` middleware returns early when `! hasTenancy()`. The admin panel does
**not** call `->tenant()` yet, so routes stay flat and the adapter is never exercised.
Confirmed via `route:list --path=admin` (still `admin/login`, `admin/operate/dashboard`).

## The seven steps

### Step 1 — Enable panel tenancy
`AdminPanelProvider`: add `->tenant(Site::class)->tenantMenu(false)->tenantSwitcher(false)`.
Keep `->path('admin')`. Filament now serves panel pages at `/admin/{tenant}/…` and auth
at `/admin/login`. The tenant menu + switcher are **off** — tenant selection is the
Lobby (relay 3), not Filament's built-in switcher.

### Step 2 — Move the Lobby off the panel to a standalone `/lobby`
The Lobby is the cross-tenant home; it must live **outside** the `{tenant}` segment (you
pick a tenant there, so you can't already be inside one). It's already custom Blade, not
a tenant-scoped Filament resource — register it as a standalone route at `/lobby`
(guarded by the operator auth stack, tenant-free). `Lobby::mount()` still clears
`ActiveTenant`; `enter()/enterBadge()` still `ActiveTenant::set(...)` then redirect into
the tenant-scoped panel URL for that site.

### Step 3 — Post-login and no-tenant redirects → `/lobby`
`EnsureTenantSelected` currently redirects a no-tenant operator to the Portfolio picker.
Repoint it (and Filament's post-login redirect) to the standalone `/lobby`. A no-lock
request to any `/admin/{tenant}/…` URL lands at `/lobby` to choose a tenant.

### Step 4 — Tenant-bridge middleware (URL tenant ↔ lock reconciliation)
Add middleware in the panel's tenant-aware stack that:
- reads Filament's resolved URL tenant (the `{tenant}` route param → `Site`),
- sets `CurrentSite` from it (replacing today's `ResolveCurrentSite` header/session read
  as the primary source inside the panel),
- verifies it against the `ActiveTenant` lock: **mismatch or no lock → redirect to
  `/lobby`** (never silently serve a tenant the operator didn't lock).

This keeps `ActiveTenant`/`CurrentSite` authoritative; the URL segment is a view onto the
lock, not a second source of truth. `ResolveCurrentSite` stays for the `X-Site-Id`
header path (Livewire AJAX / non-panel), or is folded into the bridge — decide at build.

### Step 5 — Chrome: locked-site identity, no selector
The topbar tenant-banner (`filament.operator.tenant-banner`) shows the **locked** site
name + a "Locked" affordance + an **"Exit site"** link (→ `/lobby`). Remove the "Switch"
link to the Portfolio picker (that path is the Lobby now). No Filament tenant switcher
(disabled in step 1).

### Step 6 — Old-URL survival (flat → scoped redirects)
Any inbound flat `/admin/<page>` URL (bookmarks, old links, tests mid-migration) should
`302` to `/admin/{tenant}/<page>` using the **locked** tenant, or to `/lobby` if no lock.
A catch-all route or a redirect in the bridge handles this so no existing deep link 404s.

### Step 7 — Tests: bind a default tenant + update literal-URL assertions
Two buckets:

**(a) Base `TestCase` tenant default.** 19 test files call `SomeResource::getUrl()` /
`Livewire::test()` against panel pages. On a tenant-scoped panel `getUrl()` needs a
tenant param or it throws. In `tests/TestCase.php` `setUp()` (where the null Geocoder is
already bound), bind a default tenant for URL generation — e.g. `Filament::setTenant($site, 'admin')`
+ `URL::defaults(['tenant' => $site->getRouteKey()])` — behind a helper the tenant-aware
tests opt into, or default-on for the panel. Each such test already creates/locks a site;
route that site in as the default.

The 19 `::getUrl()` files:
```
tests/Feature/Operate/NewMenuTest.php
tests/Feature/Lobby/LobbyPageTest.php
tests/Feature/Gathering/GatheringStepsTest.php
tests/Feature/Tenancy/PortfolioEntryTest.php
tests/Feature/Tenancy/ResolveCurrentSiteTest.php
tests/Feature/Filament/InteractionStylesTest.php
tests/Feature/Sites/CreateSiteTest.php
tests/Feature/Operator/NavFinalTest.php
tests/Feature/Operator/TenantGateTest.php
tests/Feature/Overview/OverviewTest.php
tests/Feature/Guided/ConnectWordpressTest.php
tests/Feature/Guided/GuidedFlowTest.php
tests/Feature/Guided/PlanApproveGrowTest.php
tests/Feature/Guided/PlanStructureTest.php
tests/Feature/Guided/Step1BusinessTest.php
tests/Feature/Guided/UnifiedMenuTest.php
tests/Feature/Guided/BrandStepTest.php
tests/Feature/Gathering/BrandStepTest.php
tests/Feature/Cockpit/CockpitAccessTest.php
```

**(b) Literal `/admin/…` URL assertions.** 6 files hard-code the flat path in
`->get('/admin…')` / `assertRedirect('/admin…')` and must move to the scoped form (or the
`/lobby` redirect where they assert the no-tenant bounce):
```
tests/Feature/Tenancy/ResolveCurrentSiteTest.php   (also in list (a))
tests/Feature/Gathering/LaunchStepTest.php
tests/Feature/Ui/DesignSystemTest.php
tests/Feature/Operator/TenantGateTest.php          (also in list (a))
tests/Feature/Gating/OperatorGatingTest.php
tests/Feature/Cockpit/CockpitAccessTest.php        (also in list (a))
```

Union of (a) + (b) is the ~19-file blast radius (3 overlap).

## Acceptance (2b)
- `/admin/login` stays flat and reachable pre-tenant; no `/admin//login`.
- Panel pages live at `/admin/{tenant}/…`; a locked operator sees only their locked
  tenant; the URL tenant is verified against the lock (mismatch → `/lobby`).
- No Filament tenant switcher/menu (Lobby is the picker).
- Old flat deep links redirect, don't 404.
- Full gate green: `pint --test`, `phpstan analyse`, `pest`.

## Full gate
```
./vendor/bin/pint --test
./vendor/bin/phpstan analyse
./vendor/bin/pest
```
CI job "Pint · Larastan · migrate · Pest" runs `migrate:fresh --seed` on PostgreSQL.

# Build spec — Author-declared service grouping (services → structure)

**Status:** proposed · **Depends on:** §1 (Service), §4 (SiloBlueprint/Spoke), §7a (ServicesStep), §8 (materialize)
**One line:** Let the operator group services and sub-services in the services entry area *before* the
structure is built, so the pillar/hub/spoke tree and the header menu are **author-declared and
deterministic** instead of AI-guessed.

---

## 1. Why

Today services are entered flat, then §4 (`AutoProposer` / `SiloExpander`) **guesses** the silo tree.
That guess promotes a service to a pillar and sometimes attaches no spokes — you get a **hub page with
nothing under it**: it renders thin (the services grid drops) and carries a permanent "Needs
generation" flag. Basement Waterproofing / Crawl Space / Battery & Water Backup are live examples.

Letting the operator declare the grouping up front makes the tree deterministic — *what they group is
what gets built* — and removes the whole class of "why is this a thin hub" problems. It also gives the
header menu a real hierarchy to render.

## 2. Confirmed decisions

1. **Default sub-services to _Section_**, operator can promote to _Page_. (Avoids thin, cannibalizing
   URLs; `ThinPageGuard` already fights this.)
2. **AI is a _suggester_, not the decider.** It pre-fills a proposed grouping the operator edits and
   confirms; the deterministic writer builds from the confirmed tree.
3. **Cap the UI at 2 levels** (service → sub-service). The model supports deeper nesting; the UI does
   not expose it.
4. **Existing orphan hubs resolve through the same UI** — re-author the grouping and the build
   reconciles hub→service automatically.

## 3. The invariant that fixes the bug

> A top-level service is a **HUB** *iff* it has ≥ 1 child whose treatment is **Page**.
> Otherwise it is a **standalone SERVICE page** (its Section children render as blocks inside it).

`page_type` (hub vs service *render*) is therefore **derived from the grouping**, never hand-set. This
single rule structurally prevents spoke-less hubs: a service with no page-children can never become a
category shell — it becomes a rich single service page instead.

## 4. Scope

**In:** service grouping model + UI, the deterministic blueprint writer, AI "Suggest grouping",
menu derivation, reverse-derive + reconcile for existing tenants.
**Out (unchanged):** the composers (composeHub/composeSpoke already render both shapes), publish
pipeline, keyword/§5 scoring (consumed only as an advisory hint), geo/location pages.

## 5. Model

### 5.1 What already exists (reuse, don't rebuild)

- `SpokeGranularity` = `OwnPage` (own URL) / `Folded` (section in parent) / `BlogTarget`. **This is the
  Page/Section toggle.**
- `Service.silo_role` (`ServiceSiloRole`: pillar vs supporting) — becomes **derived** (see §6), not
  hand-set.
- `Spoke` blueprint carries `silo` (parent pillar), `is_pillar`, `parent_silo_id`/`is_sub_hub`,
  `page_type`, `granularity`. The writer targets these.
- `Content.parent_content_id` (URL nesting) + `nav_featured`/`nav_order` (menu) — the menu consumers.

### 5.2 New columns on `Service` (the authoring source of truth)

| Column | Type | Meaning |
| --- | --- | --- |
| `parent_service_id` | ULID, nullable, indexed | Groups a sub-service under its parent. Null = top-level. Deferred-FK (self-ref), per §1 convention. |
| `page_treatment` | enum `ServicePageTreatment{Page,Section}`, default `Section` | Only meaningful on a child. Top-level is implicitly a page. Default `Section`. |
| `group_order` | int, nullable | Manual order within a group / among top-level services. |

New enum `App\Enums\ServicePageTreatment { Page, Section }` (backed; label()).

**Derived, never stored as truth:** `Service::isHub()` = `children()->where('page_treatment', Page)->exists()`.
The writer syncs `silo_role` from it (hub ⇒ pillar) so downstream nav-ranking stays correct, but
`isHub()` is the authority.

**2-level guard:** a service with a non-null `parent_service_id` may not itself be a parent — enforced
in the model (`canHaveChildren()`) and the UI (no "add sub-service" on a child).

## 6. The blueprint writer — `ServiceStructureWriter`

Pure function: **current Service tree → a valid `SiloBlueprint` + `Spoke` set** the existing build
(`PlanSync` → `PageMaterializer`) already consumes. Deterministic and idempotent (re-run = same tree;
upsert by stable key, never duplicate).

Per top-level service `S`:

- **`S` has ≥1 Page child → HUB silo.**
  - Pillar spoke: `is_pillar=true`, `page_type=hub`, `granularity=OwnPage`, `silo=S.name`.
  - Each **Page** child → spoke `page_type=service`, `granularity=OwnPage`, `silo=S.name` (a real
    spoke page; nests under the hub URL via `parent_content_id`).
  - Each **Section** child → spoke `granularity=Folded`, `silo=S.name` (renders as a block on the hub
    page; no URL, no menu entry).
- **`S` has no Page child → STANDALONE SERVICE silo.**
  - Single spoke: `page_type=service`, `granularity=OwnPage`, `is_pillar=true`, `silo=S.name`.
    Renders through `composeSpoke` (symptoms/scope/process/cost from the enriched `Service`).
  - Each **Section** child → `granularity=Folded` into that service page.

**Critical wiring change:** the build's `page_type` assignment (and `PillarFactory`) must key on
**"has ≥1 OwnPage child," not on `is_pillar`.** Decouple render-layout (`page_type`) from silo-role
(`is_pillar`) — a standalone service is the pillar of its own single-spoke silo yet renders as a
service page. This is the one place today's code conflates the two.

Geo-neutral rule (§4 `GeoNeutralValidator`) still applies to every silo/spoke the writer emits.

Command: `launchpad:rebuild-structure-from-services {site} [--apply]` (dry-run report by default) —
re-derives the blueprint from the authored Service tree.

## 7. UI — the services step (`ServicesStep`)

Nested authoring list, ≤ 2 levels:

- **Top-level rows** = services. Each: drag handle, name, "gets a page" affordance, **+ Add
  sub-service**, and the existing enrich/suggest controls.
- **Child rows** (indented under a parent): drag handle, name, and a **Page / Section** segmented
  toggle (default **Section**).
- **Drag** a top-level service onto another to make it a sub-service; drag out to promote back.
- **Demand hint** next to the toggle when §5 volume exists for the sub-service's keyword
  (e.g. "≈ 840 searches/mo — worth its own page"). Advisory only; never flips the toggle.
- A live **"this becomes: Hub with 3 service pages + 1 section"** summary per top-level service, so the
  operator sees the derived shape before building.

Persistence: the UI writes `parent_service_id` / `page_treatment` / `group_order` on `Service`. No
blueprint is written until the operator runs the structure build (unchanged entry point) — the writer
runs then.

## 8. AI — "Suggest grouping"

Repurpose `AutoProposer`/`ServiceSuggester`: instead of writing the blueprint directly, it **pre-fills
`parent_service_id` + `page_treatment` on the Service rows** as a suggestion the operator edits.
Suggestion respects the defaults (children default Section; only clear demand promotes to Page). The
operator confirms; the deterministic writer does the rest. Keeps the AI head-start without the
determinism loss.

## 9. Menu derivation

The header menu falls out of the same tree (feeding the existing `nav_featured`/`nav_order` +
`parent_content_id`):

- Top-level service → top-level nav item.
- **Page** child → nav child (dropdown) under the parent hub.
- **Section** child → **no nav entry** (optionally an in-page anchor).

One hierarchy drives URLs *and* the menu — no separate menu authoring for the common case.

## 10. Existing tenants — reverse-derive + reconcile (orphan-hub fix)

- **Reverse-derive** the initial Service tree from current silos/Content on first open of the step:
  existing hub + its spoke services → top-level with Page children; an **orphan hub** (no spokes) →
  top-level standalone (which the writer will render as a **service page**).
- **On save**, the writer re-derives the blueprint and a **reconciler** diffs it against live Content:
  - Hub that now has no Page child → convert its Content `page_type` hub→service and mark for
    regenerate (this is the "resolve orphan hubs through the UI" path — no one-off script needed).
  - Page child demoted to Section → its existing spoke page is taken down + 301-redirected to the
    parent, its content folded in. (Surface this as an explicit confirm — it removes a live URL.)

## 11. Build sequence (stages, each its own PR + gate)

1. **Model + writer.** `parent_service_id` / `page_treatment` / `group_order` migration,
   `ServicePageTreatment` enum, `Service::isHub()`/`canHaveChildren()`, `ServiceStructureWriter`,
   decouple `page_type` from `is_pillar` in the build, `launchpad:rebuild-structure-from-services`.
   Tests: tree → blueprint mapping, the hub-iff-page-child rule, idempotency, geo-neutral.
2. **Services-step UI.** Nested drag authoring + Page/Section toggle + "becomes…" summary. Tests:
   Livewire grouping writes the right columns; 2-level guard.
3. **AI suggest.** Repurpose the proposer to pre-fill suggestions. Tests: suggestion respects defaults;
   operator edits win.
4. **Menu derivation.** Hierarchy → header menu. Tests: page-children nest, section-children omitted.
5. **Reverse-derive + reconcile.** Existing-tenant onboarding of the tree + hub→service reconciliation
   + demote-to-section redirect. Tests: orphan hub reconciles to a service page; demotion redirects.

## 12. Edge cases & invariants

- **Delete a parent** → its children re-parent to top-level (become their own pages), never orphaned.
- **Promote Section → Page** → a new spoke page is materialized under the hub; menu gains an entry.
- **Demote Page → Section** → live URL removed + redirected (explicit confirm; §11).
- **Standalone with only Section children** → one rich service page, *not* a hub (the desired default —
  this is what stops thin hubs).
- **2-level cap** enforced in model + UI (a child cannot parent).
- **Geo-neutral** unchanged — no market/city terms in grouping (hard rule).
- **§5 demand** is advisory only; it annotates the toggle, never sets it.

## 13. Open questions

- Should a Page child's URL nest under the hub (`/basement-waterproofing/sump-pump/`) or stay flat
  (`/sump-pump/`)? (Model supports nesting via `parent_content_id`; recommend nesting for the silo
  signal, but it's a slug-strategy call.)
- Do we want a hard cap on Page children per hub before it's "too broad" (a soft advisory like the
  §4 viability floor)?

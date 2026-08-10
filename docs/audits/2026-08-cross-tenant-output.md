# Cross-Tenant Output Defect Audit — Root-Cause Report

**Date:** 2026-08 · **Tenants examined:** Sump Pump Gurus (live), Super Plumbers (`plumbing-trt6tj.flywp.xyz`, pre-launch)
**Harness:** `php artisan launchpad:audit` (this PR) · **Scope:** investigation + harness only — **no generator fixes shipped** (gated on review).

> Working assumption: any defect confirmed on one tenant exists on every tenant until proven otherwise.
> Method: for each observation, find the code that produced it, decide if several observations share one
> cause, and record whether the fix lands inside a **protected invariant file** (materializer / assembler /
> assigner / classifier). Where it does, the fix is a **decision, not an implementation** — flagged, not made.

## How to read this

Each class lists the **verdict** (against the original audit), the **root cause** with `file:line`, whether it's
**shared or several look-alikes**, **fix location**, **invariant risk**, and the **regression check** that catches it.
Several original-audit attributions were **wrong** and are corrected here — that reconciliation is the point of
doing this cross-tenant.

---

## Class A — Slot & record resolution

### SLOT-001 · Wrong service record on spoke pages — **CONFIRMED (mechanism corrected)** · CRITICAL
- **Observed:** `/sewer-lines/emergency-plumbing` rendered Drain Cleaning's warning-signs / scope / process / cost.
- **Root cause:** structured slots resolve **live at compose** from a pinned `Service`, and a **null `primary_service_id` falls back to the silo's alphabetically-first sibling** — `app/Publishing/Blocks/BlockContentAssembler.php:473-493` (`pinnedService`). "Drain Cleaning" < "Emergency Plumbing" → the bleed. The drafter's grounding shares the identical fallback: `app/ContentEngine/Drafting/PageGroundingAssembler.php:336-384`.
- **Why the pin goes null:** `app/Build/GuidedEntityProjector.php:181-196` (`serviceForSpoke`) matches a Service by **exact `(site_id, name)` string equality**; any rename/regroup/casing drift → null. Pages materialized before the pin feature are permanently null until backfilled.
- **Corrected attribution:** NOT a parent/hub lookup and NOT `parent_content_id` (that is URL-nesting only, `Content.php:313`). The original "by a sibling" guess is right; "by parent" is wrong.
- **Shared?** Same null-fallback taints prose (grounding) and structured slots (compose) — one root cause, two surfaces.
- **Fix location / invariant risk:** the resolution lives in **`BlockContentAssembler` (assembler) + `GuidedEntityProjector` (assigner) + `PageGroundingAssembler`** — all **PROTECTED**. **DECISION, not implementation.** Options to weigh: (a) fuzzy/id-based spoke→service resolution instead of exact-name; (b) make a null pin **fail loud / omit structured slots** rather than grab a sibling. Existing remediation `launchpad:backfill-page-service` is manual and requires **regenerate + republish** to correct live pages.
- **Regression:** `SLOT-001`.

### SLOT-002 · Price-range fallback fires everywhere — **CONFIRMED** · HIGH
- **Root cause:** the cost section renders factors-only whenever the pinned `Service.price_range` has no `low`/`high` (`app/Models/Service.php:150` cast; consumed at `BlockContentAssembler.php:515-521`). If the range is empty on every service, the fallback is the only branch. This is a **data/spec** condition, not a broken condition — the harness rollup tells you whether it's a few pages (data) or all of them (spec/generator).
- **Fix location:** Service record data, or the drafting spec that should populate a range. Not protected.
- **Regression:** `SLOT-002`.

### CASE-001 · Raw lowercase list items — **CONFIRMED** · MEDIUM
- **Root cause:** no casing is applied between record and render. `BlockSections::text()` only `htmlspecialchars(trim())` (`app/Publishing/Blocks/BlockSections.php:1558-1561`); symptoms/scope/cost print verbatim (`:210,:265,:800`). SPG's waterproofing page looks cased only because its **records** were cased — same block, different data.
- **Fix location:** apply sentence-casing at render (`BlockSections`) **or** normalize at the record. `BlockSections` is a rendering helper, not the named assembler — **verify it's outside the protected set before touching**.
- **Regression:** `CASE-001`.

### Placeholder tagline — **FALSE (data, not code)**
- "Residential Plumbing · Serving Homeowners in Your Area" is **not** a hardcoded fallback (grep: zero matches). All three surfaces read the home page's `service_area` slot (`SiteProfileAssembler.php:211-223`, `BlockPageComposer.php:57`). The placeholder-ish text is whatever data sits in that slot. **No fix here** — the field simply wasn't filled with the real footprint.

---

## Class B — Structure assignment

### STRUCT-001 · Emergency Plumbing nested under Sewer Lines; orphans — **PARTIAL / misattributed** · HIGH
- **Root cause:** the hub/spoke tree is taken **verbatim from `Service.parent_service_id`** by `app/Build/ServiceStructureWriter.php:48-76` (no keyword logic). Parentage is set either **by the operator by hand** (`app/Filament/Pages/Gathering/ServicesStep.php:303-352`) or by the **name-only** `app/Guided/GroupingSuggester.php:44` — which prompts Claude with just the flat service-name list, no volume/intent/clustering. A name-only LLM grouping is exactly what nests "Emergency Plumbing" under "Sewer Lines."
- **Corrected attribution:** the "keyword-first structure generator was supposed to prevent this" premise is **wrong** — keyword-first is **off by default** (`config/launchpad.php:391`) and is **not** the parentage path even when on (`app/KeywordGenerator/Derive/*`). `ServiceStructureWriter` emits **every** service, so no service is *structurally* orphaned; a service "in no nav and no grid" is a nav-composition/data issue (see STRUCT-001 check), not a dropped structure.
- **Shared?** The misplacement (data/authoring) and the orphan (nav composition) are **different causes that look like one problem**.
- **Fix location / open question:** if demand/intent-aware structure is the real answer, that is **keyword-first**, which is a build-out, not a patch. **Report the size, don't decide the approach** — the `launchpad:audit` STRUCT-001 rollup + a per-silo volume table quantify it.
- **Invariant risk:** `ServiceStructureWriter` is build/assigner-adjacent — treat a change as a **decision**.
- **Regression:** `STRUCT-001` (orphans). Spoke-outranks-hub volume ranking is a **future check** (needs keyword volumes joined).

---

## Class C — Surface query divergence

### GRID-001 · Homepage grid ≠ header nav — **CONFIRMED (ordering corrected)** · HIGH
- **Root cause:** two independent queries. Grid: `BlockContentAssembler.php:1808-1843` — live service/hub pages, **created_at order, capped 6**. Nav: `SiteProfileAssembler.php:245-312` — **featured/importance order, capped 8**, with dropdown nesting. Different filters (`wp_post_id` vs `status`), ordering, and caps → they diverge. (Correction: the grid is **created_at**-ordered, not alphabetical.)
- **Fix location / invariant risk:** both emitters are **assemblers (PROTECTED)**. **DECISION:** define one canonical service set both surfaces read.
- **Regression:** `GRID-001`.

### Coverage self-links on `/areas-we-serve` — **CONFIRMED (symptom of no location pages)** · —
- **Root cause:** `app/Publishing/Blocks/ServiceAreaResolver.php:91-93` links each town to its location-page permalink, else to a fallback (`:63,176-190`) that resolves to the areas page itself. With **zero location pages** every town falls to that fallback → 18 self-links. **Minor code smell:** the fallback doesn't exclude the current areas page. Downstream of "no location pages," not an independent bug.
- **Fix location:** `ServiceAreaResolver` (not protected) — exclude self, and/or don't emit a link with no target. **Regression:** future `COV-002` self-link check.

---

## Class D — URL & host emission

### CTA `#contact` anchors — **FALSE** · —
- The original "dead anchor on every page, no conversion path" is **wrong**. An `id="contact"` target **is** emitted on home, hub, and spoke: `BlockSections.php:477-479` sets `anchor=contact` on the soft CTA, `BlockBuilder.php:66-69` turns it into the HTML `id`; on a form spoke the target is the 60/40 row (`BlockSections.php:770`). CTAs pointing at `#contact` therefore resolve. **No fix.** Add a rendered-HTML regression later that asserts one `#contact` target exists per page.

### SLASH-001 · Trailing-slash mismatch — **CONFIRMED** · MEDIUM
- **Root cause:** canonical always ends `/` (`app/Support/PublicUrl.php:28`); nav/footer/body links never do (`SiteProfileAssembler.php:423,447-452`; `BlockContentAssembler` link sites). Every internal click is a 301.
- **Fix location:** unify slash policy in the URL emitters (`PublicUrl` + the link builders). `PublicUrl` is Support (not protected); the link builders are in assemblers — a shared helper is the clean move. **Regression:** future `SLASH-001` check (deterministic; not yet in the v1 set).

### Hardcoded staging host — **PARTIAL** · —
- The hostname is **derived from `sites.domain_url` on every compose**, not baked into stored content; body links are **relative** (`app/Build/Permalinks.php:23-26`). So a cutover mostly needs to change one field + re-push. **What's missing** is a single **domain-cutover action** (today it's the `domain_url` text field + `LinkRepublisher`, which only handles `kind=Page`). **Fix:** add a cutover command/action. Not a "baked host" rewrite.

### NOINDEX-001 · Staging is indexable — **CONFIRMED** · CRITICAL
- **Root cause:** `app/Publishing/MetaBlobAssembler.php:766` emits `robots: 'index, follow'` as a **literal**; nothing reads `Site.status`. The plugin only noindexes if the payload already says so (`wordpress-plugin/.../seo/class-head.php:63-68`), which never happens. No site-wide `Disallow` in the plugin either.
- **Fix location / invariant risk:** the robots string is in **`MetaBlobAssembler` (assembler, PROTECTED)**. **DECISION:** add a status-driven (or per-tenant) indexable flag and have the assembler read it; wire it to the Launch state.
- **Regression:** `NOINDEX-001`.

---

## Class E — Identity & source resolution

### NAP-001 · Agency/duplicate address — **CONFIRMED gap; two audit claims FALSE** · CRITICAL
- **Address is not seed data:** no seeder/factory ships `377 Valley Road`/`Clifton` (grep hits are **tests only**). A live tenant carrying it is **live-entered intake**, not unreplaced seed.
- **No city-upcasing in code:** `Site::corporateAddressLine()` (`app/Models/Site.php:240-252`) applies **no `strtoupper`** to the city; the address path is verbatim. A visible `CLIFTON` is **theme CSS `text-transform`**, not a data-layer bug. (Original "casing bug in the formatter" is **FALSE**.)
- **Real gap — no launch guard:** neither `app/Onboarding/CompletenessChecker.php:26-60` nor `app/Security/SiteLaunchGate.php` compares a tenant's address to the agency's or another tenant's.
- **Fix location:** add the comparison to the readiness gate as a **hard blocker** (not protected). **Regression:** `NAP-001`.

### Title/meta template — **PARTIAL (audit inverted)** · —
- Brand is **stripped from ALL titles** (`app/Publishing/Seo/SeoTitle.php:22-27`), and geo **is** applied to **both** service and hub (`MetaBlobAssembler.php:1012-1014`); home/areas get no geo. So the original "only the hub has brand+geo" is **wrong** in both directions. **DECISION** (assembler, PROTECTED): whether to keep brand-stripping and whether home/areas should carry geo.

### IMG-001 · r2.dev image domain — **CONFIRMED (one config knob)** · HIGH
- **Root cause:** public image URLs use the R2 disk `url` = `R2_PUBLIC_URL` (`config/filesystems.php:71`), **blank in `.env.example`** → the deployed value is the `pub-*.r2.dev` bucket domain. Single env knob; no per-tenant CDN, no production default baked in.
- **Fix location:** **ops/config** — point `R2_PUBLIC_URL` at a production custom domain on the client zone; no code change, no per-tenant special-casing. **Regression:** `IMG-001`.

---

## Class F — Content pipeline hygiene *(not deep-traced in this pass)*

- **BLOG-001** (this harness) detects **duplicate posts** (repeated title / `-N` slug). Observed duplicate ("...radon-risk" + "-2") means a **candidate-funnel dedupe gate leaked** — root cause needs its own investigation (the near-dup detector + the two review gates). **Truncated titles/slugs** and **un-filtered blog modules** (silo/location relevance) are **not yet traced**; flagged for a follow-up brief.

## Class G — Coverage data quality *(partly traced)*

- **COV-001** (this harness) detects **numbered parse artifacts** (`"1, Abingdon"`) and **duplicate towns**. Cross-state assignment, town-slug-equals-parent, and out-of-footprint geography are **future checks**; the one-town-one-location validation scope (per-site vs per-location) needs a dedicated look — flagged.

---

## Where the root causes land (the headline)

| Class / check | Severity | Root cause file | Protected invariant? | Disposition |
|---|---|---|---|---|
| A · SLOT-001 | critical | BlockContentAssembler, GuidedEntityProjector, PageGroundingAssembler | **YES** (assembler + assigner) | **DECISION** |
| D · NOINDEX-001 | critical | MetaBlobAssembler | **YES** (assembler) | **DECISION** |
| E · NAP-001 | critical | CompletenessChecker / SiteLaunchGate | No | **Fixable** (add gate) |
| A · SLOT-002 | high | Service data / drafting spec | No | Data/spec |
| C · GRID-001 | high | BlockContentAssembler + SiteProfileAssembler | **YES** (assemblers) | **DECISION** |
| B · STRUCT-001 | high | ServiceStructureWriter ← parent_service_id / GroupingSuggester | Adjacent | **DECISION / size-then-handoff** |
| E · IMG-001 | high | R2_PUBLIC_URL config | No | Ops/config |
| F · BLOG-001 | high | candidate-funnel dedupe (untraced) | TBD | Investigate |
| G · COV-001 | high | coverage ingest (untraced) | TBD | Investigate |
| D · SLASH-001 | medium | PublicUrl + link builders | Partial | Fixable (shared helper) |
| A · CASE-001 | medium | BlockSections render | Verify | Fixable (render/record) |
| E · titles | — | SeoTitle / MetaBlobAssembler | **YES** (assembler) | **DECISION** |
| A · tagline / D · #contact | — | — | — | **FALSE — no defect** |

**The takeaway:** the two most severe generator defects (SLOT-001 wrong-record, NOINDEX-001 crawlable staging) and the two structural ones (GRID-001, titles) all root inside **protected invariant files**. Per the relay, those are **decisions for review**, not edits I make. The cleanly fixable, high-value, non-invariant items are **NAP-001** (add a launch gate), **IMG-001** (config), **SLASH-001** (shared URL helper), and **CASE-001** (render/record casing).

## Launch-readiness hard blockers (recommended)

Per the relay's read, plus what the code confirms these gates *don't* check today:
1. **NAP-001** — corporate address matching the agency or another tenant (`CompletenessChecker` has no address check).
2. **NOINDEX-001** — a non-Live tenant with no status-driven noindex.
3. **SLOT-001** — any live service page with a null service pin (guarantees wrong structured data).

`launchpad:audit --fail-on=critical` already returns non-zero on all three, so it can gate a Launch action directly.

## Fix order (by blast radius, gated on review)

1. **Review the DECISION items** (SLOT-001, NOINDEX-001, GRID-001, titles) — they touch protected files; pick the approach before any edit.
2. **NAP-001** launch gate — non-invariant, hard blocker, cheap.
3. **IMG-001** — set `R2_PUBLIC_URL` to a production domain (ops).
4. **SLASH-001** — unify trailing-slash in a shared URL helper.
5. **CASE-001** — case at render or enforce at record.
6. **Investigate** Class F (dedupe leak) and Class G (coverage ingest) with dedicated briefs.

Every fix ships with the audit check that fails before and passes after; after each, **regenerate** one affected tenant and re-run `launchpad:audit` — never hand-edit WordPress.

# Build Order & Reconciliation (§B)

> Status: **DRAFT for review.** No code yet — this is the spec we agree on before building.

## Why this exists

The platform is a **dependency chain**: intake feeds structure, structure feeds pages
and keywords, structure feeds blog routing, and everything lands in WordPress at
publish. Rebuilding an *upstream* layer (Services → Silos) **silently orphans**
everything hanging off it downstream, because there is no enforced order and no
reconciliation cascade.

The blog symptom that surfaced this: posts publish **Uncategorized**. Root cause — a
post's WordPress category comes from `Content.silo_id` in the meta-blob
(`assign_category(post_id, silo_id)`); after a silo rebuild that `silo_id` points at a
deleted silo (or was never set), so the plugin has nothing to categorize with. The
same broken edge shows up as thin hubs, dangling sibling links, and stale page pins.

**The fix is not "fix the blog." It is: codify the build order, and make a rebuild
cascade its reconciliations downstream.**

---

## 1. The dependency DAG (canonical order)

Each layer depends on the one(s) above it. The **bold** items are the cross-layer
*links* (foreign-key-ish references) that break on a rebuild.

| # | Layer | Produces | Depends on |
|---|-------|----------|------------|
| 1 | **Intake (§1)** | Site, WP `Connection`, Branding, **Locations**, **Services (+ ServiceProblems)**, **Markets**, Proof, Voice | — (source of truth) |
| 2 | **Territory** | `CoverageArea` towns + `page_selected` | Locations |
| 3 | **Structure (§4)** | `SiloBlueprint` → `Spoke`s → **`Silo`s + rule_sets + pillars** | Services + trade |
| 4 | **Keywords (§5)** | scored/bucketed keyword targets; **`Keyword.silo_id` / `target_content_id`** | §4 rule_sets |
| 5 | **Materialize** | pages: service (**`silo_id` pin**), location hubs + town pages, standard | §4 + Territory |
| 6 | **Silo → WP category** | **`Silo.wp_category_id`** + the WP category term | §4 + WP connection |
| 7 | **Blog routing (§6a)** | candidates stamped **`Content.silo_id`** (+ `matched_silo_id`); **[NEW] town refs** | §4 rule_sets |
| 8 | **Draft (§6b)** | drafted pages/posts (silo pinned, voice, grounding) | 3–7 |
| 9 | **Publish (§2)** | render → meta-blob (**carries `silo_id`, [NEW] town terms**) → WP; plugin assigns category | 6 (term exists) + 7 (silo set) |

**The break:** a rebuild of layer 3 (or 1→2) after layers 5–9 have already run leaves
those downstream `silo_id` / `wp_category_id` references stale, and nothing re-links
them. `BuildStructure` today does **only layer 3 + enrich (volume/arrange)** — it does
not re-pin pages, re-sync categories, re-route posts, or re-tag towns.

---

## 2. Cross-link edges → reconciler ownership

Every edge that can dangle gets **one owner** responsible for re-pointing it at the
current upstream. This table is the contract.

| Edge | Reference | Set at | Breaks when | Reconciler (owner) |
|------|-----------|--------|-------------|--------------------|
| Page → Silo | `Content.silo_id` (service/hub/town) | materialize / projector | §4 rebuild/prune | `GuidedEntityProjector` re-pin (exists, extend) |
| Silo → WP category | `Silo.wp_category_id` + WP term | `SyncSiloCategories` / `PublishSilo` | §4 rebuild; never synced | **`SyncSiloCategories` (ensure-all)** |
| Keyword → Silo/Content | `Keyword.silo_id`, `target_content_id` | §5 bucket | §4 rebuild | re-bucket (exists, tasks #93/#94) |
| **Post → Silo** | `Content.silo_id` (kind=post) | §6a routing | §4 rebuild; weak/absent match | **`PostSiloReconciler` (NEW)** |
| **Post → Town(s)** | **NEW** join (see §5) | town extraction | town/coverage change | **`PostTownTagger` (NEW)** |
| Town page → Coverage | `Content` ↔ `CoverageArea` | materialize | reselect / rebuild | `PlanSync` (exists) |
| Live content → WP | `wp_post_id`, category/terms | publish | any of the above changed | **repush affected (NEW policy)** |

---

## 3. The orchestrated rebuild

One entry point — **"Rebuild structure & reconcile"** — runs the stages **in
dependency order**, each **idempotent**, and each downstream stage **reconciles to the
current upstream** before proceeding. Emits a structured report (what changed, what
was re-linked, what got repushed).

```
Rebuild(site):
  1. Structure    → BuildStructure (silos + rule_sets + pillars)          [layer 3]
  2. Keywords     → re-bucket to current silos                            [layer 4]
  3. Pages        → PlanSync + GuidedEntityProjector re-pin silo_id       [layer 5]
  4. Categories   → SyncSiloCategories (ensure every silo has a WP term)  [layer 6]
  5. Posts        → PostSiloReconciler (re-route) + PostTownTagger (towns)[layer 7]
  6. Republish    → repush live content whose silo/category/town changed  [layer 9]
```

- **Idempotent:** running it when nothing changed is a no-op; safe to re-run.
- **Bounded republish:** step 6 does not silently republish 700 pages — it **queues**
  affected content (respecting the same worker + monitor we just built), or lists it
  for the operator, per the decision in §7.
- **Failure-isolated:** a downstream provider hiccup (e.g. categories) doesn't roll
  back the structure; it records the gap and the readiness surface shows it red.

---

## 4. Readiness / staleness surface

A per-tenant **stage checklist** (Operate) so the operator always knows what to build
and in what order, and what's currently **stale**:

```
① Intake        ✓ complete
② Territory     ✓ 79 towns · 29 selected
③ Structure     ✓ 6 silos (rebuilt 2h ago)
④ Keywords      ⚠ 14 targets still on old silos — re-bucket
⑤ Pages         ⚠ 3 service pages pinned to a deleted silo — re-pin
⑥ Categories    ✗ 6 silos, 2 WP categories — sync
⑦ Blog routing  ✗ 340 posts on a stale/missing silo — re-route
⑨ Publish       ⚠ 41 live posts Uncategorized — repush
```

Each red/amber row links to the reconciler that fixes it (or "Rebuild & reconcile"
runs them all in order). Staleness is derived from persisted rows only (no network).

---

## 5. NEW — Blog ↔ Town categorization + location-page content feed

Two connected additions:

### 5a. A post gets its town(s) as a taxonomy term
When a post references a town, tag it with that town — **in addition to** its silo
category — so the town becomes a real, queryable dimension.

- **Extraction:** match town names appearing in the post's title/body/grounding
  against the site's `CoverageArea` / `Location` names (normalized, state-aware — the
  same `townKey` matcher we already use). Owner: **`PostTownTagger`**. Runs at routing
  (§6a) for new posts and in the rebuild cascade for existing posts (backfill).
- **Storage (control-plane):** a `content_towns` join (or a `Content.town_ids` JSON) —
  **decision D1** below on shape.
- **WordPress (contract):** the meta-blob carries the matched town terms; the plugin
  assigns them. **Decision D2:** a **dedicated `lp_area` taxonomy** (cleaner queries,
  keeps silo categories uncluttered) vs. **plain categories** (what you literally
  asked for — "add the town as a category as well"). Recommendation: a dedicated
  taxonomy that *renders* like categories but is queryable separately — but this is
  your call.

### 5b. Location / areas-served pages pull a local content feed
A location hub (and town page) gains a **"Local updates / news"** section that lists
recent **published posts tagged with that town** (and/or its silo) — additional,
honest content beyond the provider-gated **reviews** and **jobs** already there. This
directly fattens the thin areas-served pages.

- Read model: posts tagged town T (published), newest first, capped.
- Renders in `BlockPageComposer::composeLocation` as a new section (like `areasServed`
  / `testimonials`), with the same crawlable-links treatment.
- Gated: only shows when ≥1 (or ≥N) posts exist for the town, else the section drops.

---

## 6. Backfill (fixing the current orphans)

For the existing Uncategorized posts and stale pins, the cascade's reconcilers are
also the backfill:
- `PostSiloReconciler` — re-match each published post to a current silo (by its
  keyword / rule_set / matched_silo_id), set `silo_id`.
- `PostTownTagger` — tag towns from content.
- `SyncSiloCategories` — ensure the WP category terms exist.
- **Repush** the affected live posts → the plugin assigns the (now-correct) silo
  category + town terms.

Plus one cheap hardening independent of all the above: the meta-blob should fall back
to `matched_silo_id` when `silo_id` is null, so a post we *know* the intended silo for
never publishes Uncategorized.

---

## 7. Decisions (RESOLVED)

- **D1 — Post↔town storage:** ✅ **`content_towns` join table** (many-to-many).
- **D2 — WP taxonomy for towns:** ✅ **dedicated `lp_area` taxonomy** — must be
  queryable so location pages can list their town's posts (that's the whole point).
- **D3 — Town extraction scope:** ✅ **title + body, restricted to towns in the site's
  own coverage set** (no stray matches).
- **D4 — Cascade trigger:** ✅ **explicit "Rebuild & reconcile" button + readiness
  surface** (never surprise-republishes).
- **D5 — Republish policy:** ✅ **auto-queue** affected live content (idempotent by
  ULID; rides the worker + the pipeline monitor), count shown first.
- **D6 — Location feed:** recency-ordered, capped (default ~6), section gated on ≥1
  post for the town.

---

## 8. Build sequence (once the spec is agreed)

1. `PostSiloReconciler` + meta-blob `matched_silo_id` fallback + `SyncSiloCategories`
   ensure-all → **fixes the Uncategorized blogs now** (the first real cascade slice).
2. `PostTownTagger` + `content_towns` (D1) + the WP town taxonomy (D2) + meta-blob term
   push + plugin assignment.
3. Location-page **local content feed** section (5b) + read model.
4. The **orchestrated "Rebuild & reconcile"** entry point (§3) wiring all reconcilers
   in order.
5. The **readiness surface** (§4).

Each stage is its own gated PR; §8.1 ships first because it stops the bleeding.

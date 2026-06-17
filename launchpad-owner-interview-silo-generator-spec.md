# Launchpad — Unified Owner Interview + Silo/Keyword Generator

Trade-agnostic, multi-tenant. From one conversational owner interview, produce the
**voice profile** and a **grounded, confirmed silo + keyword blueprint** that becomes
the page inventory. The owner **confirms and vetoes — never enumerates**, because a
service they forget to mention is a lost lead forever.

This document is the durable design record for the whole arc. It is built as a
sequence of PRs; each PR's body references this file as the spec.

---

## 1. The unified owner interview (conversational)

One AI-led conversation; the owner describes their business naturally. It does double
duty — one telling of the story, two outputs:

- **Voice profile** (the existing `VoiceProfile`/`VoiceKit` shape): owner background,
  positioning, tone → versioned voice profile injected into all later generation.
- **Silo seed**: trade / primary work, a few anchor services (not an exhaustive list),
  the **broad region** served (positioning only — a short phrase like "NJ, eastern PA",
  NOT a town-by-town list), and explicit **exclusions** (what they won't do).

> **Region vs. service areas.** The seed's `region` is broad positioning only. The
> specific towns/townships/municipalities that become location pages and localize
> keyword volume are the authoritative **Locations** layer (owner base location(s) +
> radius → Census enumerates the cities in range), read by Phase 3 + the location
> pages — NOT this interview field. Nothing downstream treats `region` as the
> service-area list.

GBP connect is **offered, preferred, not required**: when connected it pulls categories
+ listed services to ground the expansion and reduce what we have to ask; when absent,
the conversation plus the model's trade knowledge carry it.

Output: `VoiceProfile` + `SiloSeed { trade, anchor_services[], region, exclusions[], gbp_signals? }`.

## 2. AI expansion — problem-chain adjacency

The core principle: **reason about the customer's problem, not the owner's service
category.** The named services point at an underlying problem; the problem has *causes
upstream* and *effects downstream*; services live all along that chain, including in
other trades.

- Infer the customer **problem(s)** the business solves.
- Map the **causal chain**: upstream causes → the core fix → downstream effects.
- Generate candidate silos + spokes spanning the chain (cross-trade), each tagged:
  - **core** — confirmed offering (matches seed / GBP)
  - **adjacent** — related service within the trade
  - **connecting** — problem-chain service, often another trade, with the connection
    stated (e.g. *"gutters — a cause of basement water"*)
  - **fringe** — peripheral / out-of-lane (flag; route to a sibling brand if one applies)
- Each spoke carries head keyword(s).

GBP grounds what they **do**; the problem-chain reasoning generates what they could
**capture**. Owner veto + the upside number keep it honest.

### Phase 2 — implemented (headless `SiloExpander`)

The expansion is a headless service (`app/Interview/Expansion/`): one strict-schema
Claude call takes `SiloSeed + VoiceProfile` → a validated candidate tree
(`ExpansionResult` = `CandidateSilo[]` + a `FringeCandidate[]` handoff set). Same
discipline as `InterviewExtractor`: validate (`ExpansionValidator`), retry-then-throw
(`ExpansionException`), no fabrication. It reasons across five dimensions, calibrated to
SPG's ~6-service → ~40-page target:

1. **Equipment × action matrix** (biggest multiplier) — each core equipment fans into
   the actions that genuinely apply (install/replace/repair/maintenance/monitoring/
   backup/emergency/troubleshooting); the model reasons which are real per type.
2. **Problem-chain adjacencies** (cross-trade) — `connecting`, `connection_note` required.
3. **Upstream content pages** — symptom/problem-aware, `page_type = content`.
4. **Audience axis** — a secondary audience becomes a parallel **silo** (e.g. Commercial).
5. **Brand axis** — named brands become a "Brands We Service" **silo**.

Audience and brand are **silos, not new enum fields**. Granularity is the **maximal
split** (`own_page`, volume-pending — Phase 3 folds the low-volume ones). `status =
candidate` (pre-prune). **Fringe is tag-only**: out-of-lane items go to the
`fringe_handoff` set (with `connection_note` + optional `sibling_brand` hint) for the
separate **Routing layer** — Phase 2 builds no routing pages.

- **Persistence** (`ExpansionPersister`, behind `--persist`): writes a pillar spoke per
  silo + its candidate spokes + the fringe set (tag `fringe`, `silo = "Out of Lane"`,
  `sibling_brand`) onto the site's one `SiloBlueprint`; re-running replaces the set.
- **CLI** `launchpad:silo-expand {site} [--json] [--persist]` — **dry-run by default**;
  the calibration gate is the human eyeball of the printed tree against the target.
- **§1 additions:** `SpokeStatus::Candidate`; `spokes.sibling_brand` (Routing handoff hint).

## 3. Keyword grounding (DataForSEO)

Pull search volume per candidate head keyword, **service-area-localized** to the
tenant's covered metros. Volume is attached to every candidate = the **lead-upside
number** that drives the prune.

### Phase 3 — implemented (headless `VolumeGrounder`)

`app/Interview/Volume/` + `app/Locations/Dma/`. One explicit, paid command grounds the
candidate tree:

- **Grain: metro/DMA-aggregated** (not per-municipality — town-level service-keyword
  volume is mostly zero-rounded). A consistent relative-prioritization signal, not an
  absolute forecast.
- **Coverage → metros** (`MetroResolver`): each coverage county subdivision's GEOID
  encodes its county (`STATE+COUNTY` = first 5 digits) → Nielsen DMA → DataForSEO
  `location_name`; deduped. Places (GEOID carries no county) and unmapped counties fall
  back to the **state** location. The county→DMA + state tables are shipped data files
  (`database/data/dma/`, NJ/eastern-PA calibration set; verify additions against the
  DataForSEO catalog).
- **Query:** DataForSEO Google Ads Search Volume **by location_name** (new
  `liveSearchVolumeByName` on the existing client — avoids shipping unverifiable numeric
  codes), **batched** per metro. A metro whose name doesn't resolve is skipped (warned),
  never fatal.
- **Aggregate:** **sum** each head keyword across the covered metros → `spoke.volume`;
  the per-metro `volume_breakdown` is persisted alongside, `volume_at` stamped.
- **Granularity resolved:** a non-pillar spoke under the tunable `fold_threshold`
  (`config/launchpad.php`, default 50) → `folded`; else `own_page`. **Advisory** (Phase 4
  + owner confirm); pillars are never folded; fringe/no-keyword spokes untouched.
- **Cost-aware:** `launchpad:silo-volume {site} [--json]` is the explicit trigger —
  queries + persists once on run; never on read. The gate is the human eyeball: sane
  volumes, the right metros (NYC/Philly/Allentown), reasonable fold flags.
- **§1 additions:** `spokes.volume_breakdown` + `spokes.volume_at`.

## 4. The prune (conversational, with a visible candidate list)

The AI walks the owner through the candidates in conversation, backed by a running list
they can see and confirm against. Core = confirm offerings. Adjacent + connecting = the
lean-in, each presented with its volume and (for connecting) its problem-connection.

**Routing — for any candidate they don't currently offer:**

| Owner's answer        | Path                                                                 |
| --------------------- | -------------------------------------------------------------------- |
| Yes, I offer this     | **Service page** (built, live)                                       |
| I'd add it — **now**  | **Service page** (built, live)                                       |
| I'd add it — **future** | **Service page, live day one** — earns SEO authority ahead of fulfilment; the owner refers out (or takes the job) rather than miss the business. |
| No                    | **Content path** — an info page that owns the problem-connection, captures the upstream searcher, routes them to the core service |
| Out of lane / sibling | Skip or route to the sibling brand                                   |

**Hard gate:** nothing becomes a page without an explicit owner confirm and a chosen
path — no fabricated coverage.

**Granularity:** where volume makes it ambiguous, surface the split-vs-consolidate call
(a head term as its own spoke page vs. folded into the pillar).

### Phase 4 — implemented, PR-A (headless `PruneEngine`)

`app/Interview/Prune/` — the headless engine the conversational owner surface (PR-B)
sits on (iron law: prove the routing before the UI):

- **The routing table is the `PruneOutcome` enum** — `offer`/`future` → `SpokeStatus`
  `Offered`/`Future` (service page); `capture` → `Content` + converts the spoke's
  `page_type` to the content-path guide; `skip` → `Skipped`. Resolves a `candidate` into
  a confirmed status + page type.
- **`PruneEngine`** — per-spoke AND silo-level decisions:
  - `plan()` → `PrunePlan`/`PruneRow`: the candidate list grouped by silo, **volume-sorted
    within** (highest-upside lean-ins first) with a **per-silo summary** (stated core vs
    lean-ins + their combined upside) — the asymmetry-of-effort view (batch-confirm core,
    focus on the lean-ins).
  - `applySpokes()` — route decisions keyed by spoke name|id, each `{outcome, tag?,
    granularity?}`: routing **plus first-class re-tagging** (promote a mis-tagged
    `connecting` the owner actually offers → `core`, self-correcting the expander) **plus
    granularity override** (confirm/override the Phase-3 fold recommendation).
  - `foldSilo()` / `renameSilo()` / `confirmSilo()` — silo-level structure: collapse a
    thin silo under another pillar (e.g. sewage/grinder → pumps), rename a grouping, or
    batch-confirm a silo's core.
  - `applyDecisionSet()` — one transaction: spoke decisions first (stable keys), then
    silo renames → folds → confirms.
  - `acceptCore()` (bulk-confirm core) and `confirm()` — the **hard gate**: stamps
    `confirmed_at` only when every **non-fringe** candidate is decided (un-reviewed =
    not built; fringe is the Routing-layer handoff, excluded).
- **CLIs:** `launchpad:silo-prune {site} [--json]` (the grouped/summarized viewer);
  `launchpad:prune-apply {site} {decisions.json} [--accept-core] [--confirm] [--json]`
  (apply a decision-set: `{"silos":{...fold/rename/confirm},"spokes":{...outcome/tag/granularity}}`).
- **§1 additions:** `silo_blueprints.confirmed_at`; enum `PruneOutcome`.
- **PR-B (deferred):** the Filament prune UI — the grouped, volume-sorted, batch-confirm
  surface that writes through `PruneEngine`; its live SPG walkthrough waits on the
  validated `silo-volume` run.

## 5. Output — the silo blueprint / page inventory

The confirmed tree becomes:
- **Layer-2 silo pages** — pillar + spokes, on the proven service-page type.
- **Content-path pages** — a **new evergreen guide page-type** (distinct kit + slot
  schema), purpose-built for problem-connection info pages.
- Feeds **Layer-3 location pages** (services framed per market) and informs **Layer-1
  basic pages**.

This confirmed blueprint is the **directed-coverage layer** the reactive content engine
(Google News RSS + DataForSEO SERP signals, coverage-state tracker) fills against.

## 6. Data model

- `SiloSeed` (from the interview) — value object emitted by the extractor; round-trips to
  array. Persisted with the wizard in a later PR.
- `SiloBlueprint` → silos → spokes. Each spoke: `name`, `page_type` (service | content),
  `tag` (core | adjacent | connecting | fringe), `head_keyword`, `volume`, `status`
  (offered | future | content | skipped), `connection_note`, `granularity`
  (own-page | folded). The silo grouping is represented on the spoke via `silo` (the
  parent silo/pillar name) + `is_pillar`.
- `VoiceProfile` (existing).
- Confirmed spokes hand off to the page generator + `PageConfig`.

## 7. Build phasing (PR arc)

1. **PR #1 — contract + headless extraction (this PR).** The data contract
   (`SiloSeed` VO, `SiloBlueprint` + `Spoke` models/migrations + spoke enums) and the
   headless `InterviewExtractor` (business description [+ optional GBP signals] → a
   validated `SiloSeed` + `VoiceProfile` payload via one strict-schema Anthropic call),
   proven through a read-only CLI (`launchpad:interview-extract`). No UI, no persistence.
2. **PR #2 — conversational multi-turn UI** on top of the proven extractor; persists the
   seed + voice profile.
3. **PR #3 / Phase 2 — problem-chain expansion** fills the `SiloBlueprint`/`Spoke` tree
   (tagged core/adjacent/connecting/fringe + head keywords).
4. **PR #4 / Phase 3 — DataForSEO volume grounding** (service-area-localized) onto every
   candidate spoke.
5. **PR #5 / Phase 4 — the prune + routing** → confirmed `SiloBlueprint`; then **Phase 5
   — blueprint → inventory → generate** (service pages + new-guide content-path pages),
   feeding the location / basic layers.

## 8. Resolved decisions

- **Content-path page type:** a **new evergreen guide/info page-type** (own kit + slot
  schema), not a re-skinned post.
- **DataForSEO localization:** **service-area-localized** volume (resolved per the
  tenant's priority market) for the prune, not national.
- **GBP fallback:** lean on the model's trade knowledge; GBP is **grounding/confirmation
  only** when present — never a hard dependency. Description-only is the floor.

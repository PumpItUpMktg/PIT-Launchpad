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
  service area / markets, and explicit **exclusions** (what they won't do).

GBP connect is **offered, preferred, not required**: when connected it pulls categories
+ listed services to ground the expansion and reduce what we have to ask; when absent,
the conversation plus the model's trade knowledge carry it.

Output: `VoiceProfile` + `SiloSeed { trade, anchor_services[], markets[], exclusions[], gbp_signals? }`.

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

## 3. Keyword grounding (DataForSEO)

Pull search volume per candidate head keyword, **service-area-localized** to the
tenant's priority market(s). Volume is attached to every candidate = the **lead-upside
number** that drives the prune. Reuses the existing `DataForSeoSerpProvider` seam
(`metrics()` / `warm()`); no new integration work.

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

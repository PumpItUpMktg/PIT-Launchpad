{{-- Shared styles for the five Console → Published pages (Blog / Core / Service / Storefront / Location).
     The rich card + card grid are identical across all five; the sub-tab styles are used by the Location
     page (one sub-tab per storefront). --}}
<style>
    /* Storefront sub-tabs (Location Pages) */
    .pt-subtabs { display:flex; gap:6px; flex-wrap:wrap; margin:0 0 14px; }
    .pt-sub { font-size:12.5px; font-weight:600; padding:6px 12px; border-radius:8px; border:1px solid rgba(148,163,184,.35); background:transparent; color:#475569; cursor:pointer; display:inline-flex; align-items:center; gap:7px; }
    .pt-sub.on { background:rgba(79,70,229,.1); border-color:#4f46e5; color:#4f46e5; }
    .pt-n { font-size:11px; font-weight:700; padding:1px 7px; border-radius:99px; background:rgba(148,163,184,.18); color:#64748b; }
    .pt-badge { font-size:10px; font-weight:700; padding:1px 6px; border-radius:99px; background:rgba(217,119,6,.14); color:#b45309; }

    /* Card grid — capped at 3 across for readability, dropping to 2 then 1 on narrower screens. */
    .rc-cards { display:grid; grid-template-columns:repeat(3, minmax(0, 1fr)); gap:14px; }
    @media (max-width: 1100px) { .rc-cards { grid-template-columns:repeat(2, minmax(0, 1fr)); } }
    @media (max-width: 720px) { .rc-cards { grid-template-columns:1fr; } }
    /* Rich card */
    .rc-card { border:1px solid rgba(148,163,184,.35); border-radius:12px; padding:14px 16px; display:flex; flex-direction:column; gap:9px; }
    .rc-thumb { width:100%; aspect-ratio:16/9; object-fit:cover; border-radius:9px; background:rgba(148,163,184,.15); }
    .rc-head { display:flex; justify-content:space-between; gap:10px; flex-wrap:wrap; align-items:flex-start; }
    .rc-chips { display:flex; gap:6px; flex-wrap:wrap; }
    .rc-chip { font-size:11px; font-weight:600; padding:2px 9px; border-radius:99px; background:rgba(148,163,184,.16); color:#475569; }
    .rc-chip.silo { background:rgba(99,102,241,.12); color:#4f46e5; }
    .rc-chip.town { background:rgba(217,119,6,.13); color:#b45309; }
    .rc-chip.good { background:rgba(22,163,74,.15); color:#15803d; }
    .rc-chip.muted { background:rgba(148,163,184,.16); color:#94a3b8; }
    .rc-chip.score { background:rgba(79,70,229,.12); color:#4f46e5; }
    .rc-chip.score.good { background:rgba(22,163,74,.15); color:#15803d; }
    .rc-chip.score.muted { background:rgba(148,163,184,.16); color:#94a3b8; }
    /* Indexing pill: grey (not submitted) → yellow (submitted) → green (indexed) — the at-a-glance drip signal. */
    .rc-ipill { font-size:11px; font-weight:700; padding:2px 9px; border-radius:99px; display:inline-flex; align-items:center; gap:5px; }
    .rc-ipill::before { content:''; width:7px; height:7px; border-radius:50%; background:currentColor; }
    .rc-ipill.unsubmitted { background:rgba(148,163,184,.18); color:#64748b; }
    .rc-ipill.submitted { background:rgba(202,138,4,.16); color:#a16207; }
    .rc-ipill.indexed { background:rgba(22,163,74,.16); color:#15803d; }
    .rc-title { font-size:15px; font-weight:680; }
    .rc-url { font-size:12px; color:#4f46e5; text-decoration:none; word-break:break-all; }
    .rc-sub { font-size:11.5px; color:#94a3b8; }
    .rc-grid { display:grid; grid-template-columns:repeat(auto-fit, minmax(150px,1fr)); gap:10px; margin-top:3px; }
    .rc-stat { border:1px solid rgba(148,163,184,.25); border-radius:9px; padding:8px 11px; }
    .rc-k { font-size:10.5px; font-weight:700; text-transform:uppercase; letter-spacing:.04em; color:#94a3b8; }
    .rc-v { font-size:13.5px; font-weight:600; margin-top:2px; }
    .rc-muted { color:#94a3b8; font-weight:500; }
    .rc-delta { font-size:11px; color:#15803d; }
    .rc-block { margin-top:2px; }
    .rc-queries { display:flex; gap:7px; flex-wrap:wrap; margin-top:4px; }
    .rc-q { font-size:11.5px; padding:2px 8px; border-radius:6px; background:rgba(148,163,184,.12); color:#475569; }
    .rc-q em { color:#94a3b8; font-style:normal; }
    .rc-links { display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-top:4px; }
    .rc-linkcol { display:flex; flex-direction:column; gap:3px; }
    .rc-link { font-size:12px; color:#4f46e5; text-decoration:none; }
    .rc-actions { display:flex; gap:8px; margin-top:4px; }
    .rc-btn { font-size:12px; font-weight:600; padding:6px 13px; border-radius:8px; cursor:pointer; border:1px solid rgba(148,163,184,.4); background:transparent; color:#334155; text-decoration:none; }
    .rc-empty { border:1px dashed rgba(148,163,184,.4); border-radius:10px; padding:20px; color:#94a3b8; font-size:13.5px; text-align:center; }
</style>

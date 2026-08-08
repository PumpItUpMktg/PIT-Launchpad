{{-- Shared card styling for the console blog pipeline pages (Candidates / Review / Publish).
     One skeleton: [silo · source · date] → title → keyword → excerpt → footer(actions + score). --}}
<style>
    .bc-list { display:grid; grid-template-columns:repeat(3, minmax(0, 1fr)); gap:14px; }
    @media (max-width: 1024px) { .bc-list { grid-template-columns:repeat(2, minmax(0, 1fr)); } }
    @media (max-width: 640px) { .bc-list { grid-template-columns:1fr; } }

    .bc-card { display:flex; flex-direction:column; gap:7px; min-width:0; border:1px solid rgba(148,163,184,.35); border-radius:12px; padding:13px 15px; }

    /* Generate-time hero render (fal → R2). Present the moment a draft lands; no upload needed. */
    .bc-thumb { width:100%; aspect-ratio:16/9; object-fit:cover; border-radius:9px; background:rgba(148,163,184,.12); display:block; }
    .bc-thumb-empty { width:100%; aspect-ratio:16/9; border-radius:9px; background:rgba(148,163,184,.1); border:1px dashed rgba(148,163,184,.4); display:flex; align-items:center; justify-content:center; font-size:11.5px; color:#94a3b8; }

    /* Top row: silo · source · date — one line, silo truncates, date pinned right. */
    .bc-top { display:flex; align-items:center; gap:6px; font-size:11px; min-width:0; }
    .bc-chip { display:inline-flex; align-items:center; gap:4px; padding:2px 8px; border-radius:99px; white-space:nowrap; }
    .bc-chip.silo { background:rgba(79,70,229,.1); color:#4f46e5; overflow:hidden; text-overflow:ellipsis; min-width:0; }
    .bc-chip.source { background:rgba(13,148,136,.12); color:#0f766e; flex:none; }
    .bc-chip.date { background:rgba(100,116,139,.12); color:#475569; flex:none; margin-left:auto; }

    .bc-title { font-size:14.5px; font-weight:660; margin:0; line-height:1.3; }
    .bc-kw { font-size:12px; font-weight:600; color:#4f46e5; }
    .bc-kw .k { color:#94a3b8; font-weight:500; }
    .bc-excerpt { font-size:12.5px; color:#64748b; line-height:1.45; display:-webkit-box; -webkit-line-clamp:3; -webkit-box-orient:vertical; overflow:hidden; }
    .bc-sub { font-size:11.5px; color:#94a3b8; }
    .bc-sub.stalled { color:#dc2626; font-weight:600; }

    .bc-tags { display:flex; gap:6px; flex-wrap:wrap; }
    .bc-tag { font-size:11px; padding:2px 8px; border-radius:99px; background:rgba(148,163,184,.14); color:#475569; }
    .bc-tag.town { background:rgba(217,119,6,.13); color:#b45309; }
    .bc-tag.directed { background:rgba(79,70,229,.13); color:#4f46e5; }
    .bc-tag.revived { background:rgba(217,119,6,.14); color:#b45309; }
    .bc-tag.writing { background:rgba(79,70,229,.13); color:#4f46e5; }
    .bc-tag.bad { background:rgba(220,38,38,.12); color:#dc2626; }

    /* Footer: actions left, prominent score right, pinned to the card bottom for even rows. */
    .bc-foot { display:flex; align-items:center; gap:8px; margin-top:auto; padding-top:5px; }
    .bc-actions { display:flex; gap:8px; align-items:center; flex-wrap:wrap; }
    .bc-btn { font-size:12.5px; font-weight:600; padding:6px 13px; border-radius:8px; cursor:pointer; border:1px solid rgba(148,163,184,.4); background:transparent; color:#334155; }
    .bc-btn.primary { background:#4f46e5; border-color:#4f46e5; color:#fff; }
    .bc-btn.green { background:#16a34a; border-color:#16a34a; color:#fff; }
    .bc-btn.danger { color:#dc2626; border-color:rgba(220,38,38,.4); }

    .bc-score { margin-left:auto; flex:none; display:flex; flex-direction:column; align-items:center; justify-content:center; min-width:52px; padding:4px 9px; border-radius:9px; background:rgba(22,163,74,.12); color:#15803d; }
    .bc-score .n { font-size:18px; font-weight:800; line-height:1; }
    .bc-score .l { font-size:8.5px; font-weight:700; letter-spacing:.07em; text-transform:uppercase; opacity:.8; }
    .bc-score.mid { background:rgba(217,119,6,.13); color:#b45309; }
    .bc-score.low { background:rgba(100,116,139,.14); color:#475569; }

    .bc-reject { display:flex; gap:8px; align-items:center; }
    .bc-reject input { flex:1 1 auto; font-size:13px; padding:6px 10px; border-radius:8px; border:1px solid rgba(148,163,184,.4); background:transparent; color:inherit; }
    .bc-empty { grid-column:1 / -1; border:1px dashed rgba(148,163,184,.4); border-radius:10px; padding:22px; color:#94a3b8; font-size:13.5px; text-align:center; }
</style>

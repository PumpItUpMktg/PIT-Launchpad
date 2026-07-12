{{-- Locations-workspace styling, shared by the Settings page and the guided WhereYouWork step.
     EVERY selector is prefixed with .lp-wrap: the guided page also loads the lp- guided styles
     (rail/cards), which reuse several class names (.lp-card, .lp-btn, .lp-chip, .lp-town, .lp-mini,
     .lp-tag, .lp-empty, .lp-input); the prefix scopes this block so inside the workspace these
     rules win and outside it the guided rules are untouched. --}}
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Archivo:wght@500;600;700&family=Inter:wght@400;500;600&family=Spline+Sans+Mono:wght@400;500&display=swap" rel="stylesheet">
<style>
    .lp-wrap { --teal-major:#0A4F4F; --teal-large:#0E6B6B; --teal-medium:#4E9A98; --teal-small:#A6CFCD; --ungrouped:#C3CCD6;
        --ink:#13343b; --muted:#5b7178; --line:#e3eaec; --surface:#ffffff; --surface-2:#f5f8f8; --accent:#0E6B6B;
        font-family:'Inter',ui-sans-serif,system-ui,sans-serif; color:var(--ink); display:flex; flex-direction:column; gap:16px; }
    .dark .lp-wrap { --ink:#e6eef0; --muted:#9fb3b8; --line:#23373c; --surface:#0f2226; --surface-2:#13292e; }
    .lp-wrap *{ box-sizing:border-box; }
    .lp-wrap .lp-card { background:var(--surface); border:1px solid var(--line); border-radius:14px; padding:20px; box-shadow:0 1px 2px rgba(13,52,52,.04); margin-bottom:0; }
    .lp-wrap .lp-h { font-family:'Archivo',sans-serif; font-weight:700; }
    .lp-wrap .lp-muted { color:var(--muted); font-size:13px; }
    .lp-wrap .lp-label { display:block; font-size:13px; font-weight:600; color:var(--ink); margin-bottom:6px; }
    .lp-wrap .lp-select, .lp-wrap .lp-input { width:100%; max-width:340px; padding:9px 12px; border:1px solid var(--line); border-radius:10px;
        background:var(--surface); color:var(--ink); font-size:14px; font-family:inherit; }
    .lp-wrap .lp-input { max-width:none; }
    .lp-wrap .lp-warn { background:#fff7ed; color:#9a3412; border:1px solid #fed7aa; border-radius:12px; padding:12px 16px; font-size:13px; }

    /* Hero */
    .lp-wrap .lp-hero-row { display:flex; flex-wrap:wrap; align-items:flex-end; justify-content:space-between; gap:18px; }
    .lp-wrap .lp-nums { display:flex; gap:34px; }
    .lp-wrap .lp-num { font-family:'Archivo',sans-serif; font-weight:700; font-size:30px; line-height:1; }
    .lp-wrap .lp-num.alt { color:var(--teal-large); }
    .lp-wrap .lp-num-lbl { font-size:11px; text-transform:uppercase; letter-spacing:.06em; color:var(--muted); margin-top:6px; }
    .lp-wrap .lp-badge { border-radius:999px; padding:5px 12px; font-size:12px; font-weight:600; }
    .lp-wrap .lp-badge.ok { background:#e7f6ee; color:#1b7a47; }
    .lp-wrap .lp-badge.warn { background:#fdf3e2; color:#9a6a16; }
    .lp-wrap .lp-bar { display:flex; height:10px; width:100%; overflow:hidden; border-radius:999px; background:var(--surface-2); margin-top:16px; }
    .lp-wrap .lp-bar > span { display:block; height:100%; }
    .lp-wrap .lp-legend { display:flex; flex-wrap:wrap; gap:6px 16px; margin-top:10px; font-size:12px; color:var(--muted); }
    .lp-wrap .lp-legend span { display:inline-flex; align-items:center; gap:6px; }
    .lp-wrap .lp-sw { width:11px; height:11px; border-radius:3px; display:inline-block; }

    /* Tabs */
    .lp-wrap .lp-tabs { display:flex; flex-wrap:wrap; gap:8px; }
    .lp-wrap .lp-tab { display:inline-flex; align-items:center; gap:8px; padding:9px 13px; border-radius:11px; border:1px solid var(--line);
        background:var(--surface-2); cursor:pointer; font-size:14px; color:var(--ink); }
    .lp-wrap .lp-tab:hover { background:var(--surface); }
    .lp-wrap .lp-tab.active { background:var(--surface); border-color:var(--accent); box-shadow:0 0 0 1px var(--accent) inset; }
    .lp-wrap .lp-tab.add { border-style:dashed; color:var(--muted); }
    .lp-wrap .lp-tab.add.on { border-color:var(--accent); color:var(--accent); }
    .lp-wrap .lp-dot { width:11px; height:11px; border-radius:999px; flex:0 0 auto; }
    .lp-wrap .lp-tab-name { font-weight:600; }
    .lp-wrap .lp-tab-count { font-size:12px; color:var(--muted); }
    .lp-wrap .lp-tab-badge { background:var(--teal-major); color:#fff; border-radius:999px; padding:1px 7px; font-size:11px; font-weight:700; }

    /* Status pills */
    .lp-wrap .lp-status { border-radius:999px; padding:2px 10px; font-size:12px; font-weight:600; white-space:nowrap; }
    .lp-wrap .lp-status.ok { background:#e7f6ee; color:#1b7a47; }
    .lp-wrap .lp-status.bad { background:#fde8e8; color:#a23b3b; }
    .lp-wrap .lp-status.wait { background:var(--surface-2); color:var(--muted); }

    /* Location panel */
    .lp-wrap .lp-panel { display:flex; flex-direction:column; gap:18px; }
    .lp-wrap .lp-loc-head { display:flex; align-items:flex-start; justify-content:space-between; gap:10px; }
    .lp-wrap .lp-loc-name { font-family:'Archivo',sans-serif; font-weight:600; font-size:16px; }
    .lp-wrap .lp-loc-addr { font-size:13px; color:var(--muted); margin-top:2px; }
    .lp-wrap .lp-loc-coords { font-size:11px; color:var(--muted); font-family:'Spline Sans Mono',monospace; margin-top:2px; }
    .lp-wrap .lp-rule { border-top:1px solid var(--line); padding-top:14px; }
    .lp-wrap .lp-seclbl { font-size:12px; font-weight:600; color:var(--muted); margin-bottom:7px; }

    /* Chips (counties) */
    .lp-wrap .lp-chips { display:flex; flex-wrap:wrap; gap:7px; }
    .lp-wrap .lp-chip { border-radius:999px; padding:5px 11px; font-size:12px; border:1px solid var(--line); background:var(--surface);
        color:var(--ink); cursor:pointer; }
    .lp-wrap .lp-chip:hover { background:var(--surface-2); }
    .lp-wrap .lp-chip.on { background:var(--accent); border-color:var(--accent); color:#fff; }

    /* Compact searchable county multi-select */
    [x-cloak] { display:none !important; }
    .lp-wrap .lp-combo { position:relative; max-width:420px; }
    .lp-wrap .lp-combo-box { display:flex; flex-wrap:wrap; gap:6px; align-items:center; min-height:40px; padding:6px 10px;
        border:1px solid var(--line); border-radius:10px; background:var(--surface); cursor:pointer; }
    .lp-wrap .lp-tag { display:inline-flex; align-items:center; gap:6px; background:var(--accent); color:#fff; border-radius:999px;
        padding:3px 6px 3px 10px; font-size:12px; }
    .lp-wrap .lp-tag-home { background:rgba(255,255,255,.25); border-radius:999px; padding:0 6px; font-size:10px; text-transform:uppercase; letter-spacing:.04em; }
    .lp-wrap .lp-tag-x { background:none; border:0; color:#fff; cursor:pointer; font-size:14px; line-height:1; padding:0 2px; }
    .lp-wrap .lp-combo-menu { position:absolute; z-index:30; top:calc(100% + 4px); left:0; right:0; background:var(--surface);
        border:1px solid var(--line); border-radius:10px; box-shadow:0 8px 24px rgba(13,52,52,.14); padding:8px; }
    .lp-wrap .lp-combo-list { max-height:220px; overflow:auto; margin-top:8px; display:flex; flex-direction:column; gap:2px; }
    .lp-wrap .lp-combo-opt { display:flex; align-items:center; gap:8px; padding:6px 8px; border-radius:8px; font-size:13px; cursor:pointer; }
    .lp-wrap .lp-combo-opt:hover { background:var(--surface-2); }

    /* Locstat + minibar */
    .lp-wrap .lp-locstat { display:flex; flex-wrap:wrap; align-items:center; gap:12px; }
    .lp-wrap .lp-locstat .n { font-weight:600; }
    .lp-wrap .lp-pill { background:var(--teal-major); color:#fff; border-radius:999px; padding:2px 10px; font-size:12px; font-weight:700; }
    .lp-wrap .lp-mini { display:flex; height:8px; flex:1; min-width:120px; overflow:hidden; border-radius:999px; background:var(--surface-2); }
    .lp-wrap .lp-mini > span { display:block; height:100%; }

    /* Tier groups */
    .lp-wrap .lp-tgroup { border:1px solid var(--line); border-radius:11px; overflow:hidden; }
    .lp-wrap .lp-tgroup-head { display:flex; align-items:center; justify-content:space-between; gap:10px; padding:9px 12px; }
    .lp-wrap .lp-tgroup-title { display:flex; align-items:center; gap:8px; font-size:14px; font-weight:600; background:none; border:0; cursor:pointer; color:var(--ink); }
    .lp-wrap .lp-tgroup-frac { font-size:12px; color:var(--muted); font-weight:500; }
    .lp-wrap .lp-tgroup-actions { display:flex; gap:4px; font-size:12px; }
    .lp-wrap .lp-link { background:none; border:0; cursor:pointer; border-radius:6px; padding:2px 8px; color:var(--accent); }
    .lp-wrap .lp-link.dim { color:var(--muted); }
    .lp-wrap .lp-link:hover { background:var(--surface-2); }
    .lp-wrap .lp-towns { display:flex; flex-wrap:wrap; gap:7px; padding:12px; border-top:1px solid var(--line); }

    /* Town checkbox chips */
    .lp-wrap .lp-town { display:inline-flex; align-items:center; gap:6px; border-radius:999px; padding:5px 11px; font-size:12px;
        border:1px solid var(--line); background:var(--surface); color:var(--ink); cursor:pointer; }
    .lp-wrap .lp-town:hover { background:var(--surface-2); }
    .lp-wrap .lp-town.on { background:var(--accent); border-color:var(--accent); color:#fff; }
    .lp-wrap .lp-town-pop { opacity:.65; font-family:'Spline Sans Mono',monospace; font-size:11px; }

    /* Buttons / results / map / bottom bar */
    .lp-wrap .lp-btn { display:inline-flex; align-items:center; gap:6px; border-radius:10px; padding:8px 14px; font-size:13px; font-weight:600;
        background:var(--accent); color:#fff; border:0; cursor:pointer; }
    .lp-wrap .lp-btn:hover { filter:brightness(1.06); }
    .lp-wrap .lp-btn.ghost { background:var(--surface-2); color:var(--ink); border:1px solid var(--line); }
    .lp-wrap .lp-row { display:flex; gap:8px; flex-wrap:wrap; align-items:flex-end; }
    .lp-wrap .lp-result { display:block; width:100%; text-align:left; border:1px solid var(--line); background:var(--surface-2); border-radius:10px;
        padding:8px 12px; font-size:13px; cursor:pointer; }
    .lp-wrap .lp-result:hover { background:var(--surface); }
    .lp-wrap .lp-map { height:380px; width:100%; border-radius:12px; background:#e5e7eb; }
    .lp-wrap .lp-bottom { position:sticky; bottom:8px; display:flex; align-items:center; justify-content:space-between; gap:12px;
        background:var(--teal-major); color:#fff; border-radius:14px; padding:13px 18px; font-size:14px; box-shadow:0 6px 18px rgba(10,79,79,.25); }
    .lp-wrap .lp-bottom b { font-family:'Archivo',sans-serif; }
    .lp-wrap .lp-empty { text-align:center; color:var(--muted); padding:28px; }
    .lp-wrap .lp-add-stack { display:flex; flex-direction:column; gap:12px; }
    .lp-wrap .lp-seg { display:inline-flex; gap:6px; }
    .lp-wrap .lp-seg button { border:0; background:none; cursor:pointer; border-radius:8px; padding:5px 12px; font-size:13px; color:var(--muted); }
    .lp-wrap .lp-seg button.on { background:var(--accent); color:#fff; }
</style>

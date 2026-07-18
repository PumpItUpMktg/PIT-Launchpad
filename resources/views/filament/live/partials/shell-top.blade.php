{{-- Shared Live-board chrome: house lp- styles, the working-site switcher, and the data-source
     chips (honest per-source connection state — a disconnected source renders connect prompts on
     every card, never zeros). Include once at the top of each Live board view. --}}
<style>
    .lv-wrap { display:flex; flex-direction:column; gap:16px; }
    .lv-head { display:flex; justify-content:space-between; align-items:flex-end; gap:16px; flex-wrap:wrap; }
    .lv-sub { color:#64748b; font-size:13px; max-width:64ch; margin:4px 0 0; }
    .lv-sources { display:flex; gap:8px; align-items:center; flex-wrap:wrap; font-size:12px; }
    .lv-src { display:inline-flex; align-items:center; gap:6px; padding:3px 10px; border-radius:99px; background:rgba(148,163,184,.12); border:1px solid rgba(148,163,184,.35); color:#64748b; }
    .lv-src .dot { width:7px; height:7px; border-radius:50%; background:#16a34a; }
    .lv-src.off { border-style:dashed; }
    .lv-src.off .dot { background:#94a3b8; }
    .lv-grid { display:grid; grid-template-columns:repeat(auto-fill, minmax(320px, 1fr)); gap:12px; }
    .lv-card { border:1px solid rgba(148,163,184,.35); border-radius:10px; background:rgba(255,255,255,.02); display:flex; flex-direction:column; overflow:hidden; }
    .lv-top { display:flex; justify-content:space-between; align-items:center; padding:10px 13px 0; }
    .lv-type { font-size:10px; text-transform:uppercase; letter-spacing:.07em; font-weight:700; padding:2px 8px; border-radius:5px; background:rgba(148,163,184,.15); color:#64748b; }
    .lv-state { font-size:11px; font-weight:600; padding:2px 9px; border-radius:99px; background:rgba(22,163,74,.12); color:#16a34a; }
    .lv-id { padding:7px 13px 4px; }
    .lv-id h3 { margin:0 0 2px; font-size:15px; }
    .lv-id a { font-size:12px; word-break:break-all; }
    .lv-dates { font-size:11px; color:#94a3b8; margin-top:2px; }
    .lv-kw { padding:0 13px 6px; font-size:12px; color:#64748b; }
    .lv-kw b { font-weight:600; }
    .lv-local { display:inline-block; margin-left:7px; padding:1px 7px; border-radius:99px; background:rgba(22,163,74,.12); color:#16a34a; font-size:11px; font-weight:600; }
    .lv-metrics { display:grid; grid-template-columns:1fr 1fr 1fr; border-top:1px solid rgba(148,163,184,.25); border-bottom:1px solid rgba(148,163,184,.25); margin-top:8px; }
    .lv-m { padding:8px 11px; border-right:1px solid rgba(148,163,184,.25); min-width:0; }
    .lv-m:last-child { border-right:0; }
    .lv-m .k { font-size:9.5px; text-transform:uppercase; letter-spacing:.07em; color:#94a3b8; }
    .lv-m .v { font-size:16px; font-weight:700; font-variant-numeric:tabular-nums; margin-top:1px; }
    .lv-m .d { font-size:11px; color:#64748b; font-variant-numeric:tabular-nums; }
    .lv-up { color:#16a34a; font-weight:700; }
    .lv-down { color:#dc2626; font-weight:700; }
    .lv-pending { font-size:11.5px; color:#94a3b8; font-style:italic; margin-top:3px; line-height:1.3; }
    .lv-spark { display:flex; align-items:center; gap:9px; padding:7px 13px; }
    .lv-spark svg { flex:1; height:30px; min-width:0; }
    .lv-spark .cap { font-size:10px; color:#94a3b8; white-space:nowrap; }
    .lv-actions { display:flex; gap:7px; padding:9px 13px 11px; border-top:1px solid rgba(148,163,184,.25); margin-top:auto; flex-wrap:wrap; }
    .lv-btn { font-size:12px; font-weight:600; padding:4px 11px; border-radius:7px; border:1px solid rgba(148,163,184,.4); background:transparent; cursor:pointer; }
    .lv-btn.primary { background:#4f46e5; border-color:#4f46e5; color:#fff; }
    .lv-btn.danger { color:#dc2626; }
    .lv-locgroup { border:1px solid rgba(148,163,184,.35); border-radius:12px; overflow:hidden; }
    .lv-pin { font-size:11px; color:#94a3b8; font-weight:400; }
    /* The location CARD (menu-reorg relay): the physical location leads its group as a prominent
       card — identity left, the towns rollup as stat blocks right — instead of a plain heading. */
    .lv-loccard { display:flex; justify-content:space-between; align-items:center; gap:18px; flex-wrap:wrap;
        padding:16px 18px; border-bottom:1px solid rgba(148,163,184,.25); background:rgba(148,163,184,.07); }
    .lv-loccard .id h2 { margin:0; font-size:18px; display:flex; align-items:center; gap:9px; flex-wrap:wrap; }
    .lv-loccard .id .badge { font-size:10px; text-transform:uppercase; letter-spacing:.05em; font-weight:700;
        padding:3px 9px; border-radius:99px; background:rgba(148,163,184,.16); color:#94a3b8; }
    .lv-loccard .serves { font-size:12px; color:#94a3b8; margin-top:5px; max-width:56ch; }
    .lv-locstats { display:flex; gap:10px; flex-wrap:wrap; align-items:center; }
    .lv-locstat { min-width:96px; padding:8px 14px; border:1px solid rgba(148,163,184,.3); border-radius:9px; text-align:center; }
    .lv-locstat .n { font-size:19px; font-weight:700; font-variant-numeric:tabular-nums; line-height:1.2; }
    .lv-locstat .l { font-size:10.5px; color:#94a3b8; }
    .lv-band { font-size:10px; text-transform:uppercase; letter-spacing:.07em; color:#94a3b8; padding:11px 16px 0; }
    .lv-towns { display:grid; grid-template-columns:repeat(auto-fill, minmax(300px, 1fr)); gap:11px; padding:11px 16px 14px; }
    .lv-empty { border:1px dashed rgba(148,163,184,.4); border-radius:10px; padding:14px 16px; color:#94a3b8; font-size:13px; }
    .lv-select { font-size:12px; border:1px solid rgba(148,163,184,.4); border-radius:7px; padding:3px 8px; background:transparent; }
    .lv-lock { font-size:11px; color:#b45309; font-weight:600; margin-left:8px; }
    .lv-navctl { display:flex; align-items:center; gap:9px; padding:7px 13px 10px; font-size:12px; color:#64748b; border-top:1px solid rgba(148,163,184,.18); }
    .lv-navctl label { display:flex; align-items:center; gap:5px; cursor:pointer; }
    .lv-navorder-lbl { margin-left:auto; }
    .lv-navorder { width:58px; font-size:12px; border:1px solid rgba(148,163,184,.4); border-radius:7px; padding:2px 6px; background:transparent; }
    .lv-navorder:disabled { opacity:.4; cursor:not-allowed; }
</style>

<div class="lv-head">
    <div>
        <p class="lv-sub">{{ $subtitle }}</p>
    </div>
    <div style="display:flex; gap:10px; align-items:center;">
        <select class="lv-select" wire:change="setSite($event.target.value)">
            @foreach ($this->siteOptions as $id => $label)
                <option value="{{ $id }}" @selected($id === $this->siteId)>{{ $label }}</option>
            @endforeach
        </select>
    </div>
</div>

@php $sources = $this->sources; @endphp
<div class="lv-sources">
    <span style="font-size:10px; text-transform:uppercase; letter-spacing:.06em; color:#94a3b8">Data sources</span>
    <span class="lv-src {{ $sources['serp'] ? '' : 'off' }}"><span class="dot"></span>Position tracking {{ $sources['serp'] ? '· active' : '· pending first snapshots' }}</span>
    <span class="lv-src {{ $sources['gsc'] ? '' : 'off' }}"><span class="dot"></span>Search Console {{ $sources['gsc'] ? '· 28d' : '· not connected' }}</span>
    <span class="lv-src {{ $sources['ga'] ? '' : 'off' }}"><span class="dot"></span>GA4 {{ $sources['ga'] ? '· 28d' : '· not connected' }}</span>
</div>

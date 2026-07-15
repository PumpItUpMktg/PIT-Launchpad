<x-filament-panels::page>
    {{-- The decision surface itself lives in the shared partial (also hosted by the new
         Setup's Silos & keywords step); this page keeps only the site-pick / finalized shell. --}}
    <style>
        .lps-card { border:1px solid rgba(148,163,184,.35); border-radius:14px; padding:20px; }
        .lps-muted { color:#64748b; font-size:13px; }
        .lps-label { display:block; font-size:13px; font-weight:600; margin-bottom:6px; }
        .lps-select { padding:7px 10px; border:1px solid rgba(148,163,184,.4); border-radius:9px; background:transparent; font-size:13px; font-family:inherit; width:100%; }
        .lps-btn { display:inline-flex; align-items:center; gap:6px; border-radius:10px; padding:8px 14px; font-size:13px; font-weight:600; background:#0E6B6B; color:#fff; border:0; cursor:pointer; }
        .lps-btn.ghost { background:transparent; color:inherit; border:1px solid rgba(148,163,184,.4); }
        .lps-warn { background:#fff7ed; color:#9a3412; border:1px solid #fed7aa; border-radius:12px; padding:12px 16px; font-size:13px; margin-top:14px; }
        .lps-ok { background:#e7f6ee; color:#1b7a47; border:1px solid #b6e2c8; border-radius:14px; padding:20px; font-size:14px; }
    </style>

    @if (! $started)
        <div class="lps-card">
            <p class="lps-muted" style="margin:0 0 14px">
                Pick a site with a candidate tree, then walk it into a confirmed blueprint: batch-confirm the stated
                core, route the volume-sorted lean-ins, fold thin silos, and quick-route the fringe. Nothing is built
                until you finalize — un-reviewed candidates are dropped.
            </p>
            <div style="display:flex; flex-wrap:wrap; align-items:flex-end; gap:12px">
                <label style="flex:1; min-width:220px">
                    <span class="lps-label">Site</span>
                    <select wire:model.live="siteId" class="lps-select">
                        <option value="">Select a site…</option>
                        @foreach ($this->siteOptions as $id => $name)
                            <option value="{{ $id }}">{{ $name }}</option>
                        @endforeach
                    </select>
                </label>
                @if ($this->hasCandidates)
                    <button type="button" wire:click="runAutoArrange" class="lps-btn ghost">⚙ Run auto-arrange</button>
                    <button type="button" wire:click="start" class="lps-btn">✂ Open prune</button>
                @endif
            </div>

            @if ($siteId && ! $this->hasCandidates)
                <div class="lps-warn">
                    No candidate tree yet — run <code>launchpad:silo-expand --persist</code> for this site first.
                </div>
            @endif
        </div>
    @elseif ($finalized)
        <div class="lps-ok">
            Blueprint confirmed — the directed-coverage layer is locked. This is the page inventory generation builds from.
        </div>
    @else
        @include('filament.pages.partials.prune-surface')
    @endif
</x-filament-panels::page>

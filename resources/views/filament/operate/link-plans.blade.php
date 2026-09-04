<x-filament-panels::page>
    <style>
        .lp-wrap { display:flex; flex-direction:column; gap:16px; }
        .lp-head { display:flex; justify-content:space-between; align-items:flex-end; gap:14px; flex-wrap:wrap; }
        .lp-sub { color:#64748b; font-size:13px; max-width:70ch; margin:4px 0 0; }
        .lp-sel { font-size:12px; border:1px solid rgba(148,163,184,.4); border-radius:7px; padding:5px 9px; background:transparent; }
        .lp-propose { display:flex; gap:8px; align-items:center; flex-wrap:wrap; border:1px solid rgba(148,163,184,.3); border-radius:10px; padding:11px 13px; }
        .lp-btn { font-size:12px; font-weight:600; border-radius:7px; padding:5px 12px; border:1px solid transparent; cursor:pointer; }
        .lp-btn.primary { background:#2563eb; color:#fff; }
        .lp-btn.ok { background:rgba(22,163,74,.12); color:#15803d; border-color:rgba(22,163,74,.35); }
        .lp-btn.mut { background:rgba(148,163,184,.15); color:#475569; }
        .lp-plan { border:1px solid rgba(148,163,184,.35); border-radius:11px; overflow:hidden; }
        .lp-ptop { display:flex; align-items:center; gap:12px; padding:11px 14px; background:rgba(148,163,184,.06); border-bottom:1px solid rgba(148,163,184,.2); flex-wrap:wrap; }
        .lp-ptop h3 { margin:0; font-size:14.5px; }
        .lp-status { font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.04em; padding:2px 8px; border-radius:99px; }
        .lp-status.proposed { background:rgba(37,99,235,.12); color:#2563eb; }
        .lp-status.applied { background:rgba(22,163,74,.13); color:#16a34a; }
        .lp-actions { margin-left:auto; display:flex; gap:6px; }
        .lp-items { padding:8px 14px 12px; }
        .lp-src { font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.04em; color:#94a3b8; margin:8px 0 4px; }
        .lp-item { display:flex; align-items:center; gap:8px; font-size:12.5px; padding:3px 0; }
        .lp-item .t { color:#334155; }
        .lp-item .st { font-size:10px; font-weight:700; padding:1px 7px; border-radius:99px; margin-left:auto; }
        .lp-item .st.proposed { background:rgba(148,163,184,.18); color:#64748b; }
        .lp-item .st.approved { background:rgba(22,163,74,.12); color:#15803d; }
        .lp-item .st.rejected { background:rgba(220,38,38,.1); color:#dc2626; }
        .lp-item .st.applied { background:rgba(37,99,235,.12); color:#2563eb; }
        .lp-x { font-size:11px; color:#dc2626; background:transparent; border:0; cursor:pointer; }
        .lp-empty { color:#94a3b8; font-size:13px; padding:20px 4px; }
    </style>

    <div class="lp-wrap">
        <div class="lp-head">
            <p class="lp-sub">When a tier unlocks and its town pages are built, propose inbound links from the
                five sources (job/review back-link, market page, neighbouring towns, blog mentions, Areas We
                Serve), approve, then apply — links are written and the new towns submitted to IndexNow. No
                town with zero inbound links is ever submitted.</p>
        </div>

        <div class="lp-propose">
            <strong style="font-size:12.5px;">Propose:</strong>
            <select class="lp-sel" wire:model="proposeMarketId">
                <option value="">— market —</option>
                @foreach ($this->marketOptions as $id => $name)
                    <option value="{{ $id }}">{{ $name }}</option>
                @endforeach
            </select>
            <select class="lp-sel" wire:model="proposeTier">
                <option value="">ungrouped</option>
                <option value="major">Major</option>
                <option value="large">Large</option>
                <option value="medium">Medium</option>
                <option value="small">Small</option>
            </select>
            <button class="lp-btn primary" wire:click="propose">Propose plan</button>
        </div>

        @forelse ($this->plans as $plan)
            <div class="lp-plan">
                <div class="lp-ptop">
                    <h3>{{ $plan->marketLocation?->name ?? 'Market' }} · {{ $plan->tier ?? 'ungrouped' }}</h3>
                    <span class="lp-status {{ $plan->status->value }}">{{ $plan->status->label() }}</span>
                    @if ($plan->status->value === 'proposed')
                        <div class="lp-actions">
                            <button class="lp-btn ok" wire:click="approveAll('{{ $plan->id }}')">Approve all</button>
                            <button class="lp-btn primary" wire:click="applyPlan('{{ $plan->id }}')"
                                wire:confirm="Write the approved links and submit the new towns to IndexNow?">Apply</button>
                        </div>
                    @endif
                </div>
                <div class="lp-items">
                    @php $bySource = $plan->items->groupBy(fn ($i) => $i->source_type->value); @endphp
                    @forelse ($bySource as $src => $group)
                        <div class="lp-src">{{ $group->first()->source_type->label() }} ({{ $group->count() }})</div>
                        @foreach ($group as $item)
                            <div class="lp-item">
                                <span class="t">→ {{ $item->target?->title ?? $item->target_content_id }}</span>
                                <span class="st {{ $item->status->value }}">{{ $item->status->label() }}</span>
                                @if ($item->status->value === 'proposed')
                                    <button class="lp-x" wire:click="rejectItem('{{ $item->id }}')">reject</button>
                                @endif
                            </div>
                        @endforeach
                    @empty
                        <p class="lp-empty">No links proposed for this plan.</p>
                    @endforelse
                </div>
            </div>
        @empty
            <p class="lp-empty">No link plans yet. Propose one for an unlocked market tier above.</p>
        @endforelse
    </div>
</x-filament-panels::page>

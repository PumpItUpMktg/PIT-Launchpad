<x-filament-panels::page>
    @include('filament.console.partials.site-switcher')

    @php $jobs = $this->reviewJobs; @endphp

    <style>
        .jr-wrap { display:flex; flex-direction:column; gap:18px; }
        .jr-card { border:1px solid rgba(148,163,184,.35); border-radius:12px; padding:16px; display:flex; flex-direction:column; gap:12px; }
        .jr-head { display:flex; align-items:center; justify-content:space-between; gap:10px; flex-wrap:wrap; }
        .jr-head h3 { margin:0; font-size:15px; }
        .jr-badge { font-size:11px; font-weight:700; padding:2px 8px; border-radius:99px; background:rgba(56,189,248,.15); color:#0284c7; }
        .jr-meta { font-size:12.5px; color:#64748b; }
        .jr-chips { display:flex; gap:6px; flex-wrap:wrap; }
        .jr-chip { font-size:11px; font-weight:600; padding:2px 9px; border-radius:99px; background:rgba(56,189,248,.14); color:#0284c7; }
        .jr-photos { display:flex; gap:8px; flex-wrap:wrap; }
        .jr-photo { position:relative; width:120px; height:120px; border-radius:10px; overflow:hidden; border:1px solid rgba(148,163,184,.35); }
        .jr-photo.primary { border-color:#38bdf8; border-width:2px; }
        .jr-photo img { width:100%; height:100%; object-fit:cover; }
        .jr-photo .star { position:absolute; top:4px; left:6px; color:#38bdf8; font-size:14px; }
        .jr-desc { display:grid; grid-template-columns:repeat(auto-fit, minmax(200px,1fr)); gap:12px; }
        .jr-col { border:1px solid rgba(148,163,184,.25); border-radius:10px; padding:10px 12px; }
        .jr-col .lbl { font-size:11px; text-transform:uppercase; letter-spacing:.04em; color:#94a3b8; margin-bottom:4px; }
        .jr-col .txt { font-size:13px; white-space:pre-wrap; color:#334155; }
        .jr-row { display:flex; gap:9px; flex-wrap:wrap; align-items:center; }
        .jr-btn { font-size:12.5px; font-weight:600; padding:7px 14px; border-radius:8px; cursor:pointer; border:1px solid rgba(148,163,184,.4); background:transparent; color:#334155; }
        .jr-btn.go { border-color:rgba(22,163,74,.5); color:#15803d; }
        .jr-btn.warn { border-color:rgba(217,119,6,.5); color:#b45309; }
        .jr-btn.danger { border-color:rgba(220,38,38,.5); color:#dc2626; }
        .jr-field { width:100%; border:1px solid rgba(148,163,184,.4); border-radius:8px; padding:9px 11px; font-size:13px; background:transparent; color:inherit; }
        textarea.jr-field { min-height:90px; resize:vertical; }
        .jr-empty { color:#94a3b8; font-size:13px; }
        .jr-lbl { font-size:11px; color:#94a3b8; margin:8px 0 4px; }
    </style>

    <div class="jr-wrap">
        @forelse ($jobs as $job)
            <div class="jr-card" wire:key="jr-{{ $job['id'] }}">
                <div class="jr-head">
                    <h3>{{ $job['client'] ?: 'Job' }}
                        <span class="jr-badge">{{ $job['status_label'] }}</span>
                    </h3>
                    <span class="jr-meta">
                        {{ $job['city'] ?: '—' }}{{ $job['county'] ? ', '.$job['county'] : '' }}
                        @if ($job['lat'] !== null)
                            · <a href="https://www.openstreetmap.org/?mlat={{ $job['lat'] }}&mlon={{ $job['lng'] }}#map=15/{{ $job['lat'] }}/{{ $job['lng'] }}" target="_blank" rel="noopener">map (approx.)</a>
                        @endif
                    </span>
                </div>

                @if (count($job['job_types']))
                    <div class="jr-chips">
                        @foreach ($job['job_types'] as $t) <span class="jr-chip">{{ $t }}</span> @endforeach
                    </div>
                @endif

                @if (count($job['photos']))
                    <div class="jr-photos">
                        @foreach ($job['photos'] as $p)
                            <div class="jr-photo {{ $p['primary'] ? 'primary' : '' }}">
                                @if ($p['url']) <img src="{{ $p['url'] }}" alt="{{ $p['alt'] }}"> @endif
                                @if ($p['primary']) <span class="star">★</span> @endif
                            </div>
                        @endforeach
                    </div>
                @endif

                @if ($editingId === $job['id'])
                    {{-- Inline edit: the source seed feeds re-enhance; title/meta ship as SEO. --}}
                    <div>
                        <div class="jr-lbl">Source (the AI seed — edit, then Re-enhance)</div>
                        <textarea class="jr-field" wire:model="editSource"></textarea>
                        <div class="jr-lbl">Post title</div>
                        <input type="text" class="jr-field" wire:model="editTitle">
                        <div class="jr-lbl">Meta description</div>
                        <input type="text" class="jr-field" wire:model="editMeta">
                        @if (count($job['photos']) > 1)
                            <div class="jr-lbl">Primary photo</div>
                            <div class="jr-row">
                                @foreach ($job['photos'] as $i => $p)
                                    <label class="jr-meta"><input type="radio" wire:model="editPrimary" value="{{ $i }}"> #{{ $i + 1 }}</label>
                                @endforeach
                            </div>
                        @endif
                        <div class="jr-row" style="margin-top:10px;">
                            <button class="jr-btn go" wire:click="saveEdits">Save edits</button>
                            <button class="jr-btn" wire:click="cancelEdit">Cancel</button>
                        </div>
                    </div>
                @else
                    <div class="jr-desc">
                        <div class="jr-col"><div class="lbl">Tech (raw)</div><div class="txt">{{ $job['raw'] ?: '—' }}</div></div>
                        <div class="jr-col"><div class="lbl">Source (seed)</div><div class="txt">{{ $job['source'] ?: '—' }}</div></div>
                        <div class="jr-col"><div class="lbl">Enhanced</div><div class="txt">{{ $job['enhanced'] ?: '— not enhanced yet —' }}</div></div>
                    </div>
                @endif

                @if ($rejectingId === $job['id'])
                    <div>
                        <div class="jr-lbl">Reason (optional)</div>
                        <input type="text" class="jr-field" wire:model="rejectReason" placeholder="e.g. Blurry photos — reshoot">
                        <div class="jr-row" style="margin-top:10px;">
                            <button class="jr-btn danger" wire:click="confirmReject">Confirm reject</button>
                            <button class="jr-btn" wire:click="cancelReject">Cancel</button>
                        </div>
                    </div>
                @elseif ($editingId !== $job['id'])
                    <div class="jr-row">
                        <button class="jr-btn go" wire:click="approve('{{ $job['id'] }}')" @disabled(! $job['has_draft'])>Approve &amp; publish</button>
                        <button class="jr-btn warn" wire:click="reEnhance('{{ $job['id'] }}')">Re-enhance</button>
                        <button class="jr-btn" wire:click="startEdit('{{ $job['id'] }}')">Edit</button>
                        <button class="jr-btn danger" wire:click="startReject('{{ $job['id'] }}')">Reject</button>
                    </div>
                @endif
            </div>
        @empty
            <div class="jr-empty">No jobs awaiting review on this site.</div>
        @endforelse
    </div>
</x-filament-panels::page>

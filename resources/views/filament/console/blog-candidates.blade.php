<x-filament-panels::page>
    @include('filament.console.partials.blog-filters')
    @include('filament.console.partials.blog-card-styles')

    <style>
        .bc-manual { display:flex; gap:8px; flex-wrap:wrap; align-items:flex-end; padding:12px 14px; border:1px dashed var(--line,#cbd5e1); border-radius:12px; margin:4px 0 6px; background:rgba(148,163,184,.06); }
        .bc-manual .f { display:flex; flex-direction:column; gap:3px; }
        .bc-manual label { font-size:10.5px; font-weight:800; letter-spacing:.03em; text-transform:uppercase; color:#64748b; }
        .bc-manual input, .bc-manual select { font-size:13px; padding:6px 8px; border:1px solid var(--line,#e5e7eb); border-radius:8px; background:#fff; color:#0f172a; }
        .bc-manual .grow { flex:1 1 220px; }
        .bc-manual .hint { flex-basis:100%; font-size:11.5px; color:#94a3b8; margin-top:2px; }
        @media (prefers-color-scheme: dark) { .bc-manual input, .bc-manual select { background:#0f141b; color:#f1f5f9; } }
        .bc-group { margin-top: 18px; }
        .bc-group:first-of-type { margin-top: 4px; }
        .bc-group-head { display:flex; align-items:baseline; gap:10px; padding:6px 2px; border-bottom:1px solid var(--line,#e5e7eb); margin-bottom:10px; }
        .bc-group-silo { font-size:14px; font-weight:800; color:#0f172a; }
        .bc-group-meta { font-size:12px; color:#64748b; }
        .bc-group-meta .local { color:#2E7D6B; font-weight:700; }
        .bc-more { font-size:12.5px; color:#64748b; padding:8px 2px 2px; font-style:italic; }
        @media (prefers-color-scheme: dark) { .bc-group-silo { color:#f1f5f9; } }
        .bc-asked { display:flex; flex-direction:column; gap:6px; padding:12px 14px; border:1px solid var(--line,#e5e7eb); border-radius:12px; margin:6px 0 10px; background:rgba(46,125,107,.05); }
        .bc-asked-head { display:flex; align-items:baseline; gap:10px; }
        .bc-asked-head .t { font-size:13px; font-weight:800; color:#0f172a; }
        .bc-asked-head .m { font-size:11.5px; color:#64748b; }
        .bc-asked-row { display:flex; flex-wrap:wrap; gap:6px 12px; align-items:baseline; font-size:12.5px; padding:4px 0; border-top:1px dashed var(--line,#e5e7eb); }
        .bc-asked-row:first-of-type { border-top:0; }
        .bc-asked-row .q { font-weight:700; color:#0f172a; }
        .bc-asked-row .n { color:#64748b; }
        .bc-asked-row .p { color:#2E7D6B; font-weight:700; }
        .bc-asked-row a { color:#0f172a; text-decoration:underline; text-underline-offset:2px; }
        @media (prefers-color-scheme: dark) { .bc-asked-head .t, .bc-asked-row .q, .bc-asked-row a { color:#f1f5f9; } }
    </style>

    @if ($this->can(\App\Security\Capability::EditContent))
        <div class="bc-manual">
            <div class="f grow">
                <label>Idea / working title</label>
                <input type="text" wire:model="manualTitle" placeholder="e.g. The Polk high-water-table job — what homeowners should know" maxlength="180">
            </div>
            <div class="f">
                <label>Silo</label>
                <select wire:model="manualSiloId">
                    <option value="">Choose a silo…</option>
                    @foreach ($this->siloFilterOptions as $id => $name)
                        <option value="{{ $id }}">{{ $name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="f">
                <label>Town (optional → local)</label>
                <input type="text" wire:model="manualTown" placeholder="e.g. Polk" maxlength="80">
            </div>
            <button class="bc-btn primary" wire:click="addManual" wire:loading.attr="disabled" wire:target="addManual">
                <span wire:loading.remove wire:target="addManual">Add idea</span>
                <span wire:loading wire:target="addManual">Adding…</span>
            </button>
            <div class="hint">A hand-typed idea joins the board immediately (always shown, never capped) and expires in 30 days if nobody writes it.</div>
        </div>
    @endif

    @php $marks = $this->questionRefreshMarks; @endphp
    @if ($marks !== [])
        <div class="bc-asked">
            <div class="bc-asked-head">
                <span class="t">Refresh suggested — questions people ask that a post of ours already answers</span>
                <span class="m">{{ count($marks) }} {{ \Illuminate\Support\Str::plural('post', count($marks)) }} · from the Search Console questions lane · Refresh adds a direct answer at the top, a Q&A section with FAQ schema at the end, and a title proposal — into Review, nothing publishes until approved</span>
            </div>
            @foreach ($marks as $m)
                <div class="bc-asked-row" wire:key="mark-{{ $m['id'] }}">
                    <span class="q">“{{ $m['query'] }}”</span>
                    <span class="n">asked {{ number_format($m['impressions']) }}× @if ($m['position'] !== null)· <span class="p">pos {{ number_format($m['position'], 1) }}</span>@endif</span>
                    @if ($m['also'] !== []) <span class="n" title="{{ implode(' · ', $m['also']) }}">+{{ count($m['also']) }} more {{ \Illuminate\Support\Str::plural('question', count($m['also'])) }}</span> @endif
                    <span class="n">→ @if ($m['url'])<a href="{{ $m['url'] }}" target="_blank" rel="noopener">{{ $m['title'] }} ↗</a>@else{{ $m['title'] }}@endif</span>
                    @if (! empty($m['silo'])) <span class="bc-chip silo">{{ $m['silo'] }}</span> @endif
                    @if ($m['marked_at']) <span class="n">marked {{ $m['marked_at'] }}</span> @endif
                    @if ($m['state'] === 'in_review')
                        <span class="bc-tag">{{ str_replace('_', ' ', $m['status']) }} — in Review</span>
                    @elseif ($m['state'] === 'refreshing')
                        <span class="bc-tag writing">Refreshing…</span>
                    @elseif ($m['state'] === 'refreshed')
                        <span class="bc-tag">refreshed {{ $m['refreshed_at'] }}</span>
                    @else
                        @if ($m['state'] === 'failed') <span class="bc-tag bad" title="{{ $m['error'] }}">refresh failed</span> @endif
                        @if ($this->can(\App\Security\Capability::GenerateContent))
                            <button class="bc-btn primary" wire:click="refreshForQuestion('{{ $m['id'] }}')" wire:loading.attr="disabled" wire:target="refreshForQuestion('{{ $m['id'] }}')">
                                <span wire:loading.remove wire:target="refreshForQuestion('{{ $m['id'] }}')">{{ $m['state'] === 'failed' ? 'Retry refresh' : 'Refresh' }}</span>
                                <span wire:loading wire:target="refreshForQuestion('{{ $m['id'] }}')">Queuing…</span>
                            </button>
                        @endif
                    @endif
                </div>
            @endforeach
        </div>
    @endif

    @php $groups = $this->candidateGroups; @endphp

    @forelse ($groups as $g)
        <div class="bc-group" wire:key="grp-{{ $loop->index }}">
            <div class="bc-group-head">
                <span class="bc-group-silo">{{ $g['silo'] }}</span>
                <span class="bc-group-meta">
                    {{ $g['total'] }} {{ \Illuminate\Support\Str::plural('candidate', $g['total']) }}
                    @if ($g['local'] > 0) · <span class="local">{{ $g['local'] }} local</span> @endif
                </span>
            </div>

            <div class="bc-list">
                @foreach ($g['visible'] as $c)
                    @include('filament.console.partials.blog-candidate-card', ['c' => $c])
                @endforeach
            </div>

            @if ($g['overflow'] > 0)
                <div class="bc-more">+{{ $g['overflow'] }} more in this silo — narrow with the score filter or open the silo to triage them.</div>
            @endif
        </div>
    @empty
        <div class="bc-empty">No candidates waiting. New ones arrive as feeds are ingested.</div>
    @endforelse
</x-filament-panels::page>

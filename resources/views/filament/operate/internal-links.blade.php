<x-filament-panels::page>
    <div class="g-wrap">
        <style>
            .il-head { display:flex; align-items:center; gap:10px; margin-bottom:14px; flex-wrap:wrap; }
            .il-card { border:1px solid rgba(148,163,184,.35); border-radius:11px; overflow:hidden; margin-bottom:14px; }
            .il-cardhead { display:flex; align-items:center; gap:10px; padding:11px 14px; border-bottom:1px solid rgba(148,163,184,.22); flex-wrap:wrap; }
            .il-cardhead h3 { margin:0; font-size:14.5px; }
            .il-badge { font-size:10px; text-transform:uppercase; letter-spacing:.05em; font-weight:700; padding:2px 8px; border-radius:99px; background:rgba(148,163,184,.15); }
            .il-split { margin-left:auto; font-size:11.5px; color:#64748b; }
            .il-row { display:flex; align-items:center; gap:10px; padding:9px 14px; border-bottom:1px solid rgba(148,163,184,.14); font-size:12.5px; flex-wrap:wrap; }
            .il-row:last-child { border-bottom:0; }
            .il-url { font-weight:600; }
            .il-detail { color:#64748b; }
            .il-arrow { color:#2563eb; }
            .il-btn { margin-left:auto; font-size:11.5px; padding:3px 11px; border-radius:7px; border:1px solid rgba(37,99,235,.5); color:#2563eb; background:transparent; cursor:pointer; }
            .il-btn[disabled] { opacity:.5; cursor:default; border-color:rgba(148,163,184,.4); color:#94a3b8; }
        </style>

        @php
            $groups = $this->findings;
            $labels = ['opportunity' => 'New link available', 'orphan' => 'No inbound links', 'dead_end' => 'No outbound links'];
            $total = collect($groups)->map(fn ($g) => count($g))->sum();
        @endphp

        <div class="il-head">
            <h2 style="margin:0;font-size:17px">Internal links</h2>
            <span class="g-muted" style="font-size:13px">Published-page audit for {{ $this->getSite()?->brand_name ?? 'this tenant' }} — approve each fix; it edits the page and re-publishes.</span>
            <button type="button" class="g-btn" style="margin-left:auto" wire:click="$refresh">↻ Re-scan</button>
        </div>

        @if ($total === 0)
            <div class="g-card" style="border-color:rgba(22,163,74,.4);background:rgba(22,163,74,.06)">
                <h3>Clean</h3>
                <p class="g-hint">Every published page has inbound + outbound links and no unlinked mentions. Nothing to fix.</p>
            </div>
        @endif

        @foreach (['opportunity', 'orphan', 'dead_end'] as $type)
            @php $rows = $groups[$type] ?? []; @endphp
            @if (count($rows))
                <div class="il-card">
                    <div class="il-cardhead">
                        <h3>{{ $labels[$type] }}</h3>
                        <span class="il-badge">{{ count($rows) }}</span>
                        <span class="il-split">
                            @if ($type === 'opportunity') a page names another’s ranking term but doesn’t link it
                            @elseif ($type === 'orphan') nothing links to these — they can’t be found
                            @else these link nowhere — they pass no authority onward @endif
                        </span>
                    </div>
                    @foreach ($rows as $row)
                        <div class="il-row" wire:key="il-{{ $type }}-{{ $row['content_id'] }}-{{ $row['suggested_id'] ?? '' }}">
                            <span class="il-url">{{ $row['url'] }}</span>
                            <span class="g-muted">{{ $row['title'] }}</span>
                            @if ($row['suggested_label'])
                                <span class="il-arrow">→ {{ $type === 'opportunity' ? 'link to' : ($type === 'orphan' ? 'from' : 'to') }} “{{ $row['suggested_label'] }}”</span>
                            @endif
                            @if ($type === 'opportunity')
                                <span class="il-detail">· {{ $row['detail'] }}</span>
                            @endif
                            <button type="button" class="il-btn"
                                @disabled(! $row['fixable'])
                                wire:click="fix('{{ $row['type'] }}', '{{ $row['content_id'] }}', @js($row['suggested_id']))"
                                wire:loading.attr="disabled"
                                wire:confirm="Add this link and re-publish the page?">
                                {{ $row['fixable'] ? 'Fix + re-publish' : 'Fix manually' }}
                            </button>
                        </div>
                    @endforeach
                </div>
            @endif
        @endforeach
    </div>
</x-filament-panels::page>

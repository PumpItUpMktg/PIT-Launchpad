<x-filament-panels::page>
    <style>
        .ob-wrap { display:flex; flex-direction:column; gap:14px; }
        .ob-bar { display:flex; align-items:center; gap:10px; flex-wrap:wrap; }
        .ob-tabs { display:inline-flex; gap:4px; border:1px solid rgba(148,163,184,.35); border-radius:9px; padding:3px; }
        .ob-tab { border:0; background:transparent; font-size:13px; font-weight:600; padding:6px 15px; border-radius:7px; cursor:pointer; color:#64748b; }
        .ob-tab.on { background:#4f46e5; color:#fff; }
        .ob-select { font-size:12px; border:1px solid rgba(148,163,184,.4); border-radius:7px; padding:4px 8px; background:transparent; }
        .ob-btn { display:inline-flex; align-items:center; gap:6px; font-size:12px; font-weight:600; padding:5px 12px; border-radius:7px; border:1px solid rgba(148,163,184,.4); background:transparent; cursor:pointer; text-decoration:none; }
        .ob-btn.primary { background:#4f46e5; border-color:#4f46e5; color:#fff; }
        .ob-btn.danger { color:#dc2626; }
        .ob-grid { display:grid; grid-template-columns:repeat(auto-fill, minmax(330px, 1fr)); gap:11px; }
        .ob-card { border:1px solid rgba(148,163,184,.35); border-radius:10px; padding:12px 14px; display:flex; flex-direction:column; gap:8px; }
        .ob-card h3 { margin:0; font-size:14px; line-height:1.35; }
        .ob-chips { display:flex; gap:6px; flex-wrap:wrap; }
        .ob-chip { font-size:10.5px; font-weight:700; text-transform:uppercase; letter-spacing:.03em; padding:2px 8px; border-radius:5px; background:rgba(148,163,184,.15); color:#64748b; }
        .ob-chip.kw { background:rgba(79,70,229,.12); color:#6366f1; text-transform:none; letter-spacing:0; }
        .ob-chip.warn { background:rgba(217,119,6,.13); color:#b45309; }
        .ob-chip.danger { background:rgba(220,38,38,.12); color:#dc2626; }
        .ob-muted { color:#94a3b8; font-size:12px; }
        .ob-excerpt { font-size:12.5px; color:#64748b; line-height:1.45; max-height:5.8em; overflow:hidden; }
        .ob-actions { display:flex; gap:7px; flex-wrap:wrap; margin-top:auto; }
        .ob-group { border:1px solid rgba(148,163,184,.35); border-radius:11px; overflow:hidden; }
        .ob-ghead { display:flex; align-items:center; gap:10px; padding:11px 14px; background:rgba(148,163,184,.07); border-bottom:1px solid rgba(148,163,184,.2); flex-wrap:wrap; }
        .ob-ghead .arrow { color:#94a3b8; }
        .ob-ghead strong { font-size:13.5px; }
        .ob-count { margin-left:auto; font-size:11px; color:#94a3b8; font-variant-numeric:tabular-nums; }
        .ob-articles { display:flex; flex-direction:column; }
        .ob-article { display:flex; align-items:center; gap:10px; padding:8px 14px; border-bottom:1px solid rgba(148,163,184,.12); font-size:13px; }
        .ob-article:last-child { border-bottom:0; }
        .ob-bare { padding:9px 14px; font-size:12px; color:#b45309; font-style:italic; }
        .ob-empty { border:1px dashed rgba(148,163,184,.4); border-radius:10px; padding:15px; color:#94a3b8; font-size:13px; }
        .ob-drawer { border:1px solid rgba(79,70,229,.4); border-radius:11px; padding:13px 16px; }
        .ob-target { display:flex; align-items:center; gap:10px; padding:6px 0; border-bottom:1px solid rgba(148,163,184,.12); font-size:12.5px; }
        .ob-target:last-child { border-bottom:0; }
        .ob-reject { display:flex; gap:8px; align-items:center; }
        .ob-reject input { flex:1; font-size:12.5px; border:1px solid rgba(148,163,184,.4); border-radius:7px; padding:5px 9px; background:transparent; }
        .ob-publishing { display:flex; align-items:center; flex-wrap:wrap; gap:8px 10px; margin-bottom:12px; padding:11px 15px; border:1px solid rgba(37,99,235,.35); background:rgba(37,99,235,.07); border-radius:11px; }
        .ob-publishing strong { font-size:13px; color:#1d4ed8; }
        .ob-publishing-list { display:flex; flex-wrap:wrap; gap:6px; flex-basis:100%; }
        .ob-spinner { width:13px; height:13px; border-radius:50%; border:2px solid rgba(37,99,235,.3); border-top-color:#2563eb; animation:ob-spin .7s linear infinite; }
        @keyframes ob-spin { to { transform:rotate(360deg); } }
    </style>

    <div class="ob-wrap">
        <div class="ob-bar">
            <div class="ob-tabs">
                <button class="ob-tab {{ $tab === 'candidates' ? 'on' : '' }}" wire:click="setTab('candidates')">Candidates</button>
                <button class="ob-tab {{ $tab === 'review' ? 'on' : '' }}" wire:click="setTab('review')">Review</button>
                <button class="ob-tab {{ $tab === 'published' ? 'on' : '' }}" wire:click="setTab('published')">Published</button>
            </div>
            <select class="ob-select" wire:model.live="siloFilter">
                <option value="">All silos</option>
                @foreach ($this->siloOptions as $id => $label)
                    <option value="{{ $id }}">{{ $label }}</option>
                @endforeach
            </select>
            <button class="ob-btn primary" style="margin-left:auto" wire:click="populateBlog"
                wire:loading.attr="disabled" wire:target="populateBlog"
                title="Re-file keywords, rebuild the news feeds, and fetch candidates for the selected tenant">
                <span wire:loading.remove wire:target="populateBlog">Populate blog now</span>
                <span wire:loading wire:target="populateBlog">Populating…</span>
            </button>
            <button class="ob-btn" wire:click="toggleTargets">
                {{ $showTargets ? 'Hide' : 'Show' }} blog targets
            </button>
        </div>

        <div wire:poll.5s>
            @if ($this->publishing !== [])
                <div class="ob-publishing" role="status">
                    <span class="ob-spinner" aria-hidden="true"></span>
                    <strong>Publishing {{ count($this->publishing) }} {{ \Illuminate\Support\Str::plural('post', count($this->publishing)) }}…</strong>
                    @php $stalled = collect($this->publishing)->where('stalled', true)->count(); @endphp
                    @if ($stalled > 0)
                        <span class="ob-muted" style="color:#b45309">{{ $stalled }} stuck at “queued to publish” for 5+ min — the queue worker may be down. Use “Publish now” to push inline, or run <code>launchpad:drain-publish</code>.</span>
                    @else
                        <span class="ob-muted">Approve queues a background job — it renders the image and pushes to WordPress, then moves to Published. This updates automatically.</span>
                    @endif
                    <div class="ob-publishing-list">
                        @foreach ($this->publishing as $p)
                            <span class="ob-chip" wire:key="obp-{{ $p['id'] }}">
                                {{ $p['title'] }} · {{ $p['state'] }}@if(! $siteFilter && $p['tenant']) · {{ $p['tenant'] }}@endif
                                @if ($p['stalled'])
                                    <button class="ob-btn primary" style="margin-left:6px;padding:1px 7px;font-size:11px"
                                            wire:click="publishNowSync('{{ $p['id'] }}')"
                                            wire:loading.attr="disabled" wire:target="publishNowSync"
                                            wire:confirm="Publish '{{ $p['title'] }}' now, synchronously? This renders the image and pushes to WordPress on this request.">Publish now</button>
                                @endif
                            </span>
                        @endforeach
                    </div>
                </div>
            @endif
        </div>

        @if ($showTargets)
            <div class="ob-drawer">
                <strong style="font-size:13px">Blog targets — the unconsumed queue (consumption is automatic; volume-led order)</strong>
                @forelse ($this->targets as $t)
                    <div class="ob-target" wire:key="obt-{{ $t['id'] }}">
                        <span class="ob-chip kw">{{ $t['keyword'] }}</span>
                        <span class="ob-muted">{{ $t['silo'] }}@if(! $siteFilter) · {{ $t['tenant'] }}@endif · {{ $t['volume'] !== null ? number_format((int) $t['volume']).'/mo' : 'no volume' }} · queued {{ $t['queued_at'] }}</span>
                        <button class="ob-btn danger" style="margin-left:auto" wire:click="dismissTarget('{{ $t['id'] }}')">Dismiss</button>
                    </div>
                @empty
                    <p class="ob-muted" style="margin:6px 0 0">No queued targets in this scope — the directed lane is starved here.</p>
                @endforelse
            </div>
        @endif

        {{-- ─── Candidates ─── --}}
        @if ($tab === 'candidates')
            <div class="ob-grid">
                @forelse ($this->candidates as $c)
                    <div class="ob-card" wire:key="obc-{{ $c['id'] }}">
                        <div class="ob-chips">
                            @if ($c['directed'])
                                <span class="ob-chip kw">{{ $c['keyword'] }}</span>
                            @else
                                <span class="ob-chip">{{ $c['source'] }}</span>
                            @endif
                            @if ($c['silo'])<span class="ob-chip">{{ $c['silo'] }}</span>@endif
                            @if (! $siteFilter && $c['tenant'])<span class="ob-chip warn">{{ $c['tenant'] }}</span>@endif
                        </div>
                        <h3>{{ $c['title'] }}</h3>
                        @if ($c['directed'] && $c['target_page'])
                            <span class="ob-muted">Supports: {{ $c['target_page'] }}</span>
                        @elseif ($c['angle'])
                            <span class="ob-muted">{{ $c['angle'] }}</span>
                        @endif
                        <div class="ob-actions">
                            <button class="ob-btn primary" wire:click="promote('{{ $c['id'] }}')">Promote</button>
                            <button class="ob-btn danger" wire:click="dismissCandidate('{{ $c['id'] }}')">Dismiss</button>
                            @if ($c['score'] !== null)<span class="ob-muted" style="margin-left:auto">score {{ $c['score'] }}</span>@endif
                        </div>
                    </div>
                @empty
                    <div class="ob-empty">No candidates to triage in this scope.</div>
                @endforelse
            </div>
        @endif

        {{-- ─── Review — drafting happens INTO this tab: promoted items appear as "writing"
             cards immediately, land reviewable with copy + image, and failed drafts retry here. --}}
        @if ($tab === 'review')
            @php $reviewCards = $this->review; $writing = collect($reviewCards)->contains(fn ($c) => $c['state'] === 'writing'); @endphp
            <div class="ob-grid" @if ($writing) wire:poll.visible.10s @endif>
                @forelse ($reviewCards as $d)
                    <div class="ob-card" wire:key="obr-{{ $d['id'] }}">
                        <div class="ob-chips">
                            @if ($d['state'] === 'writing')
                                <span class="ob-chip" style="background:rgba(79,70,229,.12);color:#6366f1">✍ writing now…</span>
                            @elseif ($d['state'] === 'draft_failed')
                                <span class="ob-chip danger">draft failed</span>
                            @elseif ($d['state'] === 'undrafted')
                                <span class="ob-chip warn">no draft yet</span>
                            @else
                                <span class="ob-chip {{ in_array($d['status'], ['render_failed', 'publish_failed'], true) ? 'danger' : '' }}">{{ str_replace('_', ' ', $d['status']) }}</span>
                            @endif
                            <span class="ob-chip kw">{{ $d['keyword'] ?? 'reactive' }}</span>
                            @if ($d['silo'])<span class="ob-chip">{{ $d['silo'] }}</span>@endif
                            @if (! $siteFilter && $d['tenant'])<span class="ob-chip warn">{{ $d['tenant'] }}</span>@endif
                        </div>
                        @if ($d['image'])
                            <img src="{{ $d['image'] }}" alt="" style="width:100%;height:120px;object-fit:cover;border-radius:8px">
                        @endif
                        <h3>{{ $d['title'] }}</h3>
                        @if ($d['state'] === 'writing')
                            <div class="ob-muted">Copy + image are being generated on the worker — this card updates itself.</div>
                        @elseif ($d['state'] === 'draft_failed')
                            <div class="ob-muted" style="color:#dc2626">{{ $d['draft_error'] ?: 'The draft attempt failed.' }}</div>
                        @else
                            <div class="ob-excerpt">{{ $d['excerpt'] !== '' ? $d['excerpt'] : 'No body yet — generate the draft to review it.' }}</div>
                        @endif
                        <div class="ob-actions">
                            @if ($d['state'] === 'writing')
                                {{-- in flight — never interrupt a running job --}}
                            @elseif (! $d['has_draft'])
                                <button class="ob-btn primary" wire:click="promote('{{ $d['id'] }}')">{{ $d['state'] === 'draft_failed' ? 'Retry draft' : 'Generate draft' }}</button>
                                <button class="ob-btn danger" wire:click="startReject('{{ $d['id'] }}')">Reject</button>
                            @else
                                <button class="ob-btn primary" wire:click="approve('{{ $d['id'] }}')">Approve</button>
                                <a class="ob-btn" href="{{ $this->editUrl($d['id']) }}" wire:navigate>Edit</a>
                                <button class="ob-btn" wire:click="regeneratePost('{{ $d['id'] }}')"
                                    wire:confirm="Re-draft this post from scratch (fresh copy + image)? The current draft is replaced; the URL slug is kept.">Regenerate</button>
                                <button class="ob-btn danger" wire:click="startReject('{{ $d['id'] }}')">Reject</button>
                            @endif
                        </div>
                        @if ($rejecting === $d['id'])
                            <div class="ob-reject">
                                <input type="text" placeholder="Reason (optional — improves future drafts)" wire:model="rejectReason" wire:keydown.enter="reject('{{ $d['id'] }}')">
                                <button class="ob-btn danger" wire:click="reject('{{ $d['id'] }}')">Confirm</button>
                            </div>
                        @endif
                    </div>
                @empty
                    <div class="ob-empty">Review queue is clear in this scope.</div>
                @endforelse
            </div>
        @endif

        {{-- ─── Published — the relevance map ─── --}}
        @if ($tab === 'published')
            @forelse ($this->published as $i => $g)
                <div class="ob-group" wire:key="obp-{{ $i }}">
                    <div class="ob-ghead">
                        @if ($g['kind'] === 'keyword')
                            <span class="ob-chip kw">{{ $g['keyword'] }}</span>
                        @else
                            <span class="ob-chip warn">Freshness</span>
                        @endif
                        <span class="arrow">→</span>
                        @if ($g['target_url'])
                            <a href="{{ $g['target_url'] }}" target="_blank" rel="noopener"><strong>{{ $g['target_page'] ?? $g['silo'] }}</strong></a>
                        @else
                            <strong>{{ $g['target_page'] ?? $g['silo'] ?? 'No pillar yet' }}</strong>
                        @endif
                        @if ($g['silo'])<span class="ob-chip">{{ $g['silo'] }}</span>@endif
                        @if (! $siteFilter && $g['tenant'])<span class="ob-chip warn">{{ $g['tenant'] }}</span>@endif
                        <span class="ob-count">{{ count($g['articles']) }} article(s)</span>
                    </div>
                    @if ($g['articles'] === [])
                        <div class="ob-bare">No supporting article yet — {{ $g['status'] === 'queued' ? 'queued for the directed lane' : 'in flight' }}.</div>
                    @else
                        <div class="ob-articles">
                            @foreach ($g['articles'] as $a)
                                <div class="ob-article" wire:key="oba-{{ $a['id'] }}">
                                    <span>{{ $a['title'] }}</span>
                                    <span class="ob-muted" style="margin-left:auto">{{ $a['published_at'] }}</span>
                                    @if ($a['url'])<a class="ob-btn" href="{{ $a['url'] }}" target="_blank" rel="noopener">View live ↗</a>@endif
                                    <button class="ob-btn" wire:click="repushPost('{{ $a['id'] }}')"
                                        title="Re-publish this post to WordPress on the same URL (re-syncs the body + silo category)">Re-push</button>
                                    <button class="ob-btn danger" wire:click="takeDownPost('{{ $a['id'] }}')"
                                        wire:confirm="Take this post off WordPress? Re-push recreates it on the same URL.">Take down</button>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            @empty
                <div class="ob-empty">Nothing published (and no queued targets) in this scope yet.</div>
            @endforelse
        @endif
    </div>
</x-filament-panels::page>

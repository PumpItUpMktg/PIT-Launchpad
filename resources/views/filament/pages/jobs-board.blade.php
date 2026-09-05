<x-lp.shell
    variant="table"
    eyebrow="Build"
    title="Jobs"
    lede="Field jobs captured from the app — review and approve them into published proof, and keep the published body of work healthy. The review queue is what needs you; Published is the live work.">

    @php($board = $this->board)
    @php($s = $board['summary'])
    @php($tab = $this->tab)
    @php($tone = fn (string $st) => match ($st) {
        'review' => 'info', 'published' => 'good', 'publish_failed' => 'bad',
        'approved', 'publishing' => 'info', default => 'neutral',
    })

    <style>
        .jb-stats { display:flex; gap:10px; flex-wrap:wrap; margin-bottom:16px; }
        .jb-stat { background:var(--card); border:1px solid var(--line); border-radius:11px; padding:12px 16px; min-width:118px; }
        .jb-stat .n { font-family:'Spline Sans Mono',monospace; font-size:22px; font-weight:600; color:var(--teal-deep); }
        .jb-stat .n.warn { color:var(--amber); } .jb-stat .n.bad { color:#B5341A; }
        .jb-stat .l { font-size:11px; color:var(--ink-soft); text-transform:uppercase; letter-spacing:.04em; margin-top:2px; }
        .jb-tabs { display:flex; gap:4px; margin-bottom:14px; border-bottom:1px solid var(--line); }
        .jb-tab { background:none; border:0; border-bottom:2px solid transparent; padding:9px 14px; font-size:13.5px; font-weight:700; color:var(--ink-soft); cursor:pointer; }
        .jb-tab.on { color:var(--teal-deep); border-bottom-color:var(--teal); }
        .jb-table { width:100%; border-collapse:collapse; font-size:13px; background:var(--card); border:1px solid var(--line); border-radius:12px; overflow:hidden; }
        .jb-table th { text-align:left; font-size:10.5px; text-transform:uppercase; letter-spacing:.05em; color:var(--ink-soft); font-weight:700; padding:11px 14px; border-bottom:1px solid var(--line); background:var(--paper); }
        .jb-table td { padding:11px 14px; border-bottom:1px solid var(--line); vertical-align:top; }
        .jb-table tr:last-child td { border-bottom:0; }
        .jb-title { font-weight:700; color:var(--ink); }
        .jb-sub { color:var(--ink-soft); font-size:12px; margin-top:2px; }
        .jb-svc { display:inline-block; font-size:11px; background:var(--paper); border:1px solid var(--line); border-radius:20px; padding:2px 8px; margin:2px 3px 0 0; color:var(--ink-soft); }
        .jb-act { display:inline-flex; gap:6px; flex-wrap:wrap; }
        .jb-btn { font-size:12px; font-weight:600; border:1px solid var(--line); background:#fff; border-radius:8px; padding:5px 10px; cursor:pointer; color:var(--ink); }
        .jb-btn.primary { background:var(--teal); color:#fff; border-color:var(--teal); }
        .jb-btn.danger { color:#B5341A; } .jb-btn.danger:hover { border-color:#B5341A; }
        .jb-btn:disabled { opacity:.45; cursor:not-allowed; }
        .jb-reject { margin-top:8px; display:flex; gap:8px; align-items:flex-start; }
        .jb-reject textarea { font-size:12.5px; border:1px solid var(--line); border-radius:8px; padding:6px 9px; width:260px; font-family:inherit; }
        .jb-pipe { background:#FBEFD9; border:1px solid #F0D9A8; border-radius:10px; padding:10px 14px; margin-bottom:14px; font-size:12.5px; color:#8a5a12; }
        .jb-err { color:#B5341A; font-size:11.5px; margin-top:3px; }
        .jb-dash { color:var(--ungrouped); }
    </style>

    @if ($this->siteId === null)
        <x-lp.empty title="No tenant selected" action="Go to Portfolio" :href="\App\Filament\Resources\SiteResource::getUrl('index')">
            Pick a working tenant from the topbar to see its jobs.
        </x-lp.empty>
    @else
        <div class="jb-stats">
            <div class="jb-stat"><div class="n {{ $s['review_backlog'] ? 'warn' : '' }}">{{ number_format($s['review_backlog']) }}</div><div class="l">Needs review</div></div>
            <div class="jb-stat"><div class="n">{{ number_format($s['in_capture']) }}</div><div class="l">Capturing</div></div>
            <div class="jb-stat"><div class="n">{{ number_format($s['pipeline']) }}</div><div class="l">Publishing</div></div>
            <div class="jb-stat"><div class="n">{{ number_format($s['published']) }}</div><div class="l">Published</div></div>
            <div class="jb-stat"><div class="n {{ $s['failed'] ? 'bad' : '' }}">{{ number_format($s['failed']) }}</div><div class="l">Publish failed</div></div>
        </div>

        <div class="jb-tabs">
            <button type="button" class="jb-tab {{ $tab === 'queue' ? 'on' : '' }}" wire:click="setTab('queue')">Review queue ({{ count($board['queue']) }})</button>
            <button type="button" class="jb-tab {{ $tab === 'published' ? 'on' : '' }}" wire:click="setTab('published')">Published ({{ count($board['published']) }})</button>
        </div>

        @if ($tab === 'queue')
            @if (empty($board['queue']))
                <x-lp.empty title="Nothing to review" action="Open Posts" :href="\App\Filament\Pages\Operate\OperateBlog::getUrl()">
                    Captured jobs land here for review. When a tech captures a job in the field, it shows up ready to approve into a published proof page.
                </x-lp.empty>
            @else
                <table class="jb-table">
                    <thead><tr><th>Job</th><th>Where</th><th>Services</th><th>Performed</th><th>Status</th><th></th></tr></thead>
                    <tbody>
                        @foreach ($board['queue'] as $j)
                            <tr wire:key="q-{{ $j['id'] }}">
                                <td>
                                    <div class="jb-title">{{ $j['title'] }}</div>
                                    <div class="jb-sub">{{ $j['client'] }} · {{ $j['photos'] }} {{ \Illuminate\Support\Str::plural('photo', $j['photos']) }}</div>
                                </td>
                                <td>{{ $j['place'] }}</td>
                                <td>@forelse ($j['services'] as $svc)<span class="jb-svc">{{ $svc }}</span>@empty<span class="jb-dash">—</span>@endforelse</td>
                                <td>{{ $j['performed_at'] ?? '—' }}</td>
                                <td><x-lp.chip :tone="$tone($j['status'])">{{ $j['status_label'] }}</x-lp.chip></td>
                                <td>
                                    <div class="jb-act">
                                        <button type="button" class="jb-btn primary" wire:click="approve('{{ $j['id'] }}')" @disabled(! $j['has_draft']) title="{{ $j['has_draft'] ? 'Approve into publishing' : 'Needs a write-up before it can be approved' }}">Approve</button>
                                        <button type="button" class="jb-btn" wire:click="reEnhance('{{ $j['id'] }}')">Re-enhance</button>
                                        <button type="button" class="jb-btn danger" wire:click="startReject('{{ $j['id'] }}')">Reject</button>
                                    </div>
                                    @if ($this->rejectingId === $j['id'])
                                        <div class="jb-reject">
                                            <textarea wire:model="rejectReason" rows="2" placeholder="Reason (optional)"></textarea>
                                            <div style="display:flex;flex-direction:column;gap:5px">
                                                <button type="button" class="jb-btn danger" wire:click="confirmReject">Confirm reject</button>
                                                <button type="button" class="jb-btn" wire:click="cancelReject">Cancel</button>
                                            </div>
                                        </div>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        @else
            @if (! empty($board['pipeline']))
                <div class="jb-pipe">
                    <b>{{ count($board['pipeline']) }}</b> {{ \Illuminate\Support\Str::plural('job', count($board['pipeline'])) }} still publishing —
                    approved and pushing to WordPress. A failed push shows below with a Retry.
                </div>
            @endif

            @php($rows = array_merge($board['pipeline'], $board['published']))
            @if (empty($rows))
                <x-lp.empty title="Nothing published yet" action="Review queue" :href="\App\Filament\Pages\JobsBoard::getUrl()">
                    Approved jobs publish to WordPress as proof pages and land here. Approve a job from the review queue to get started.
                </x-lp.empty>
            @else
                <table class="jb-table">
                    <thead><tr><th>Job</th><th>Where</th><th>Storefront</th><th>WP post</th><th>Status</th><th></th></tr></thead>
                    <tbody>
                        @foreach ($rows as $j)
                            <tr wire:key="p-{{ $j['id'] }}">
                                <td>
                                    <div class="jb-title">{{ $j['title'] }}</div>
                                    <div class="jb-sub">{{ $j['client'] }}@if ($j['performed_at']) · {{ $j['performed_at'] }}@endif</div>
                                    @foreach ($j['services'] as $svc)<span class="jb-svc">{{ $svc }}</span>@endforeach
                                    @if ($j['error'])<div class="jb-err">{{ \Illuminate\Support\Str::limit($j['error'], 80) }}</div>@endif
                                </td>
                                <td>{{ $j['place'] }}</td>
                                <td>{{ $j['storefront'] ?? '—' }}</td>
                                <td>{{ $j['wp_post_id'] ? '#'.$j['wp_post_id'] : '—' }}</td>
                                <td><x-lp.chip :tone="$tone($j['status'])">{{ $j['status_label'] }}</x-lp.chip></td>
                                <td>
                                    <div class="jb-act">
                                        <button type="button" class="jb-btn" wire:click="retryPublish('{{ $j['id'] }}')">{{ $j['status'] === 'publish_failed' ? 'Retry' : 'Re-push' }}</button>
                                        @if ($j['status'] === 'published')
                                            <button type="button" class="jb-btn danger" wire:click="takeDown('{{ $j['id'] }}')" wire:confirm="Take this job down from WordPress?">Take down</button>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        @endif
    @endif
</x-lp.shell>

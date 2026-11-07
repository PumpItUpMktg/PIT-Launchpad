<x-filament-panels::page>
    <style>
        .sh { display:flex; flex-direction:column; gap:18px; }
        .sh h3 { font-size:14px; font-weight:800; margin:0 0 8px; color:#1f2937; }
        .sh .sub { font-size:12px; color:#64748b; }
        .sh-panel { border:1px solid var(--line,#e5e7eb); border-radius:12px; padding:14px 16px; background:#fff; }
        .sh-line { display:flex; gap:8px; align-items:center; flex-wrap:wrap; font-size:12.5px; }
        .sh-pill { font-size:11px; font-weight:800; border-radius:20px; padding:2px 8px; background:#eef1f4; color:#475569; }
        .sh-pill.gbp { background:#dbeafe; color:#1d4ed8; } .sh-pill.facebook { background:#e0e7ff; color:#3730a3; } .sh-pill.instagram { background:#fce7f3; color:#9d174d; }
        .sh-pill.sent, .sh-pill.approved, .sh-pill.auto { background:#dcfce7; color:#15803d; } .sh-pill.failed { background:#fee2e2; color:#b91c1c; } .sh-pill.queued { background:#fef3c7; color:#b45309; } .sh-pill.skipped, .sh-pill.off { background:#eef1f4; color:#64748b; } .sh-pill.warn { background:#fef3c7; color:#b45309; }
        .sh-btn { font-size:12px; font-weight:700; border:1px solid var(--line,#e5e7eb); border-radius:8px; padding:6px 10px; background:#fff; color:#334155; cursor:pointer; }
        .sh-btn:hover { border-color:#f59e0b; color:#b45309; }
        .sh-btn.primary { background:#b45309; border-color:#b45309; color:#fff; }
        .sh select { border:1px solid var(--line,#e5e7eb); border-radius:8px; padding:6px 10px; font-size:13px; background:#fff; max-width:360px; }
        .sh-card { display:grid; grid-template-columns:120px 1fr; gap:14px; border-top:1px solid var(--line,#eef1f4); padding:14px 0; }
        .sh-card:first-of-type { border-top:0; }
        .sh-img { width:120px; height:90px; object-fit:cover; border-radius:8px; background:#eef1f4; }
        .sh-noimg { width:120px; height:90px; border-radius:8px; background:#eef1f4; display:flex; align-items:center; justify-content:center; font-size:11px; color:#94a3b8; }
        .sh-why { font-size:12.5px; color:#b45309; font-weight:700; }
        .sh-copy { white-space:pre-wrap; font-size:12.5px; color:#334155; background:#f8fafc; border-radius:8px; padding:8px 10px; margin-top:6px; }
        .sh-chk { display:inline-flex; align-items:center; gap:6px; font-size:12.5px; font-weight:700; color:#334155; margin-right:12px; }
        .sh-act { display:flex; gap:8px; align-items:center; margin-top:10px; flex-wrap:wrap; }
        .sh details summary { cursor:pointer; font-size:12px; color:#64748b; }
        .sh-qa textarea { width:100%; min-height:54px; font-size:12px; border:1px solid var(--line,#e5e7eb); border-radius:8px; padding:8px; margin-top:4px; }
        @media (prefers-color-scheme: dark) {
            .sh-panel, .sh-btn, .sh select, .sh-qa textarea { background:#151b24; color:#cbd5e1; }
            .sh h3 { color:#e2e8f0; } .sh-copy { background:#0f141b; color:#cbd5e1; }
        }
        @media (max-width:640px) { .sh-card { grid-template-columns:1fr; } }
    </style>

    <div class="sh">
        @if ($siteId === null)
            <div class="sub">Select a tenant.</div>
        @else
            <div class="sh-panel">
                <div class="sh-line">
                    <b style="font-size:13px">Channels</b>
                    @forelse ($this->channels as $ch)
                        <span class="sh-pill {{ $ch['enabled'] ? $ch['network'] : 'off' }}" title="{{ $ch['provider_name'] }}">{{ $ch['label'] }}{{ $ch['enabled'] ? '' : ' · gone' }}</span>
                        @if ($ch['unmatched'])<span class="sh-pill warn" title="This GBP profile could not be tied to one of our locations — it receives no shares">unmatched</span>@endif
                    @empty
                        <span class="sub">none connected yet — connect the profiles in the posting provider, then sync</span>
                    @endforelse
                    <button type="button" class="sh-btn" style="margin-left:auto" wire:click="syncChannels" wire:loading.attr="disabled">Sync now</button>
                </div>
                <div class="sub" style="margin-top:6px">Jobs share on their own when they publish — to the Business Profile of the location that serves the job's town, and the Pages. Posts share on first publish. Everything below is a re-share waiting on you, except rain, which goes on its own.</div>
            </div>

            <div class="sh-panel">
                <h3>Suggested re-shares</h3>
                @forelse ($this->open as $s)
                    <div class="sh-card" wire:key="sg-{{ $s['id'] }}">
                        <div>
                            @if ($s['image_url'])<img class="sh-img" src="{{ $s['image_url'] }}" alt="">@else<div class="sh-noimg">no image</div>@endif
                        </div>
                        <div>
                            <div class="sh-why">{{ $s['trigger_label'] }} · {{ $s['reason'] }}</div>
                            <b style="font-size:13.5px">{{ $s['title'] }}</b>
                            <div class="sub">
                                @if ($s['gbp_locations'] === null) GBP: every location @else GBP: {{ implode(', ', $s['gbp_locations']) }} @endif
                                · suggested {{ \Illuminate\Support\Carbon::parse($s['created_at'])->diffForHumans() }}
                            </div>
                            <div class="sh-copy">{{ $s['copy'] }}</div>
                            <div class="sh-act">
                                @foreach (\App\Enums\SocialNetwork::cases() as $n)
                                    @if (in_array($n->value, $s['networks'], true))
                                        <label class="sh-chk"><input type="checkbox" wire:model="picks.{{ $s['id'] }}.{{ $n->value }}"> {{ $n->label() }}</label>
                                    @endif
                                @endforeach
                                <button type="button" class="sh-btn primary" wire:click="approve('{{ $s['id'] }}')">Approve</button>
                                <button type="button" class="sh-btn" wire:click="skip('{{ $s['id'] }}')">Skip</button>
                            </div>
                            @if ($s['qa'])
                                <details class="sh-qa"><summary>GBP Q&amp;A to add by hand</summary>
                                    <textarea readonly onclick="this.select()">{{ $s['qa']['question'] }}</textarea>
                                    <textarea readonly onclick="this.select()">{{ $s['qa']['answer'] }}</textarea>
                                </details>
                            @endif
                        </div>
                    </div>
                @empty
                    <div class="sub">Nothing waiting. A refreshed question post, a post moving up in Search Console, or a post you pick below lands here.</div>
                @endforelse
                <div class="sh-line" style="margin-top:12px; border-top:1px dashed var(--line,#e5e7eb); padding-top:10px">
                    <select wire:model="pickPostId">
                        <option value="">Pick a published post…</option>
                        @foreach ($this->pickable as $id => $title)
                            <option value="{{ $id }}">{{ $title }}</option>
                        @endforeach
                    </select>
                    <button type="button" class="sh-btn" wire:click="suggestPost">Add to the board</button>
                </div>
            </div>

            <div class="sh-panel" wire:poll.20s>
                <h3>Went out</h3>
                @forelse ($this->decided as $s)
                    <div class="sh-card" wire:key="sd-{{ $s['id'] }}">
                        <div>@if ($s['image_url'])<img class="sh-img" src="{{ $s['image_url'] }}" alt="">@else<div class="sh-noimg">no image</div>@endif</div>
                        <div>
                            <span class="sh-pill {{ $s['status'] }}">{{ $s['status'] === 'auto' ? 'sent on its own' : $s['status'] }}</span>
                            <b style="font-size:13.5px">{{ $s['title'] }}</b>
                            <div class="sub">{{ $s['trigger_label'] }} · {{ $s['reason'] }}@if ($s['decided_at']) · {{ \Illuminate\Support\Carbon::parse($s['decided_at'])->diffForHumans() }}@endif
                                @if (is_array($s['snapshot']) && isset($s['snapshot']['lead'])) · opened with “{{ $s['snapshot']['lead'] }}”@endif
                            </div>
                            @foreach ($s['posts'] as $p)
                                <div class="sh-line"><span class="sh-pill {{ $p['network'] }}">{{ $p['channel'] ?? $p['network'] }}</span><span class="sh-pill {{ $p['status'] }}">{{ $p['status'] }}</span>
                                    @if ($p['permalink'])<a href="{{ $p['permalink'] }}" target="_blank" rel="noopener" class="sub">view post</a>@endif
                                    @if ($p['error'])<span class="sub" style="color:#b91c1c">{{ $p['error'] }}</span>@endif
                                </div>
                            @endforeach
                            <details><summary>copy</summary><div class="sh-copy">{{ $s['copy'] }}</div></details>
                        </div>
                    </div>
                @empty
                    <div class="sub">Nothing yet.</div>
                @endforelse
            </div>
        @endif
    </div>
</x-filament-panels::page>

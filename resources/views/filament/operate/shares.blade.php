<x-filament-panels::page>
    <style>
        .sh { display:flex; flex-direction:column; gap:18px; }
        .sh h3 { font-size:14px; font-weight:800; margin:0 0 8px; color:#1f2937; }
        .sh .sub { font-size:12px; color:#64748b; }
        .sh-cards { display:grid; grid-template-columns:repeat(auto-fill,minmax(150px,1fr)); gap:10px; }
        .sh-card { border:1px solid var(--line,#e5e7eb); border-radius:10px; padding:10px 12px; background:#fff; }
        .sh-card b { display:block; font-size:22px; font-weight:800; }
        .sh-panel { border:1px solid var(--line,#e5e7eb); border-radius:12px; padding:14px 16px; background:#fff; }
        .sh-row { display:flex; gap:10px; align-items:center; flex-wrap:wrap; padding:8px 0; border-top:1px solid var(--line,#eef1f4); font-size:13px; }
        .sh-row:first-of-type { border-top:0; }
        .sh-pill { font-size:11px; font-weight:800; border-radius:20px; padding:2px 8px; background:#eef1f4; color:#475569; }
        .sh-pill.gbp { background:#dbeafe; color:#1d4ed8; } .sh-pill.facebook { background:#e0e7ff; color:#3730a3; } .sh-pill.instagram { background:#fce7f3; color:#9d174d; }
        .sh-pill.sent { background:#dcfce7; color:#15803d; } .sh-pill.failed { background:#fee2e2; color:#b91c1c; } .sh-pill.queued { background:#fef3c7; color:#b45309; } .sh-pill.skipped { background:#eef1f4; color:#64748b; }
        .sh-btn { font-size:12px; font-weight:700; border:1px solid var(--line,#e5e7eb); border-radius:8px; padding:6px 10px; background:#fff; color:#334155; cursor:pointer; }
        .sh-btn:hover { border-color:#f59e0b; color:#b45309; }
        .sh-btn.primary { background:#b45309; border-color:#b45309; color:#fff; }
        .sh input[type=text], .sh input[type=number], .sh select { border:1px solid var(--line,#e5e7eb); border-radius:8px; padding:6px 10px; font-size:13px; background:#fff; }
        .sh input[type=number] { width:70px; }
        .sh-copy { white-space:pre-wrap; font-size:12.5px; color:#334155; background:#f8fafc; border-radius:8px; padding:8px 10px; margin-top:6px; }
        .sh-err { font-size:12px; color:#b91c1c; }
        .sh details summary { cursor:pointer; font-size:12px; color:#64748b; }
        .sh-qa { border-top:1px solid var(--line,#eef1f4); padding:10px 0; }
        .sh-qa textarea { width:100%; min-height:60px; font-size:12.5px; border:1px solid var(--line,#e5e7eb); border-radius:8px; padding:8px; margin-top:4px; }
        @media (prefers-color-scheme: dark) {
            .sh-card, .sh-panel, .sh-btn, .sh input, .sh select, .sh-qa textarea { background:#151b24; color:#cbd5e1; }
            .sh h3 { color:#e2e8f0; } .sh-copy { background:#0f141b; color:#cbd5e1; }
        }
    </style>

    <div class="sh">
        @if ($siteId === null)
            <div class="sub">Select a tenant to manage its shares.</div>
        @else
            @php $c = $this->counts; @endphp
            <div class="sh-cards">
                <div class="sh-card"><span class="sub">Sent, 7 days</span><b>{{ $c['sent_7d'] }}</b></div>
                <div class="sh-card"><span class="sub">Sent, all time</span><b>{{ $c['sent'] }}</b></div>
                <div class="sh-card"><span class="sub">Queued</span><b>{{ $c['queued'] }}</b></div>
                <div class="sh-card"><span class="sub">Failed</span><b style="color:{{ $c['failed'] > 0 ? '#b91c1c' : 'inherit' }}">{{ $c['failed'] }}</b></div>
            </div>

            <div class="sh-panel">
                <h3>Channels</h3>
                <div class="sub" style="margin-bottom:8px">A GBP channel is one physical location's Business Profile — a job posts to the location that serves its town. Facebook and Instagram are the business's Page / account. The channel id is the connected profile's id in the posting provider.</div>
                @forelse ($this->channels as $ch)
                    <div class="sh-row" wire:key="ch-{{ $ch['id'] }}">
                        <span class="sh-pill {{ $ch['network'] }}">{{ $ch['network_label'] }}</span>
                        <span><b>{{ $ch['label'] }}</b> <span class="sub">· id {{ $ch['provider_channel_id'] }}</span></span>
                        <span class="sub">sent {{ $ch['sent'] }}@if ($ch['failed'] > 0) · <span style="color:#b91c1c">failed {{ $ch['failed'] }}</span>@endif</span>
                        <span class="sub" style="margin-left:auto">max/day</span>
                        <input type="number" min="0" value="{{ $ch['max_per_day'] }}" placeholder="∞" wire:change="setChannelCap('{{ $ch['id'] }}', $event.target.value)">
                        <button type="button" class="sh-btn" wire:click="toggleChannel('{{ $ch['id'] }}')">{{ $ch['enabled'] ? 'Enabled' : 'Disabled' }}</button>
                        <button type="button" class="sh-btn" wire:click="removeChannel('{{ $ch['id'] }}')" wire:confirm="Remove this channel? Its share history stays.">Remove</button>
                    </div>
                @empty
                    <div class="sub">No channels yet — nothing is shared until one is added and enabled.</div>
                @endforelse
                <div class="sh-row" style="border-top:1px dashed var(--line,#e5e7eb); margin-top:6px">
                    <select wire:model.live="newNetwork">
                        @foreach (\App\Enums\SocialNetwork::cases() as $n)
                            <option value="{{ $n->value }}">{{ $n->label() }}</option>
                        @endforeach
                    </select>
                    <select wire:model="newLocationId">
                        <option value="">{{ $newNetwork === 'gbp' ? 'Location (required)' : 'Whole business' }}</option>
                        @foreach ($this->locationOptions as $id => $name)
                            <option value="{{ $id }}">{{ $name }}</option>
                        @endforeach
                    </select>
                    <input type="text" wire:model="newChannelId" placeholder="provider channel id">
                    <input type="text" wire:model="newLabel" placeholder="label (optional)">
                    <button type="button" class="sh-btn primary" wire:click="addChannel">Add channel</button>
                </div>
            </div>

            <div class="sh-panel">
                <h3>Share by hand</h3>
                <div class="sh-row">
                    <select wire:model="shareJobId">
                        <option value="">A published job…</option>
                        @foreach ($this->shareable['jobs'] as $id => $title)
                            <option value="{{ $id }}">{{ $title }}</option>
                        @endforeach
                    </select>
                    <button type="button" class="sh-btn" wire:click="shareJob">Share job</button>
                    <select wire:model="sharePostId" style="margin-left:14px">
                        <option value="">A published post…</option>
                        @foreach ($this->shareable['posts'] as $id => $title)
                            <option value="{{ $id }}">{{ $title }}</option>
                        @endforeach
                    </select>
                    <button type="button" class="sh-btn" wire:click="sharePost">Share post</button>
                </div>
            </div>

            <div class="sh-panel" wire:poll.15s>
                <h3>Shares</h3>
                @forelse ($this->shares as $s)
                    <div class="sh-row" style="align-items:flex-start" wire:key="sp-{{ $s['id'] }}">
                        <div style="flex:1; min-width:260px">
                            <span class="sh-pill {{ $s['network'] }}">{{ $s['network_label'] }}</span>
                            <span class="sh-pill {{ $s['status'] }}">{{ $s['status'] }}</span>
                            <b>{{ $s['subject_title'] }}</b>
                            <span class="sub">· {{ $s['subject_type'] }} · {{ $s['trigger'] }}@if ($s['channel']) · {{ $s['channel'] }}@endif</span>
                            <div class="sub">
                                @if ($s['sent_at']) sent {{ \Illuminate\Support\Carbon::parse($s['sent_at'])->diffForHumans() }}
                                @elseif ($s['status'] === 'queued' && $s['scheduled_for']) posts {{ \Illuminate\Support\Carbon::parse($s['scheduled_for'])->diffForHumans() }}
                                @else {{ \Illuminate\Support\Carbon::parse($s['created_at'])->diffForHumans() }} @endif
                                @if ($s['permalink']) · <a href="{{ $s['permalink'] }}" target="_blank" rel="noopener">view post</a>@endif
                                @if ($s['link']) · <a href="{{ $s['link'] }}" target="_blank" rel="noopener">tagged link</a>@endif
                                @if (is_array($s['snapshot']) && $s['snapshot'] !== []) · {{ collect($s['snapshot'])->map(fn ($v, $k) => $k.' '.(is_scalar($v) ? $v : json_encode($v)))->implode(', ') }}@endif
                            </div>
                            @if ($s['error'])<div class="sh-err">{{ $s['error'] }}</div>@endif
                            <details><summary>copy</summary><div class="sh-copy">{{ $s['copy'] }}</div>@if ($s['image_url'])<div class="sub">image: {{ $s['image_url'] }}</div>@endif</details>
                        </div>
                        @if ($s['status'] === 'failed')
                            <button type="button" class="sh-btn" wire:click="retry('{{ $s['id'] }}')">Retry</button>
                        @endif
                    </div>
                @empty
                    <div class="sub">Nothing shared yet. Jobs share when they publish; posts when they publish and when a question refresh lands.</div>
                @endforelse
            </div>

            @if ($this->gbpQa !== [])
                <div class="sh-panel">
                    <h3>GBP Q&amp;A to add by hand</h3>
                    <div class="sub">The mined question and a short answer with the tagged link — post the question on the Business Profile as the owner, then answer it. Until the Q&amp;A API lands this is a copy step.</div>
                    @foreach ($this->gbpQa as $qa)
                        <div class="sh-qa" wire:key="qa-{{ $qa['content_id'] }}">
                            <b style="font-size:13px">{{ $qa['title'] }}</b>
                            <div class="sub">Question</div>
                            <textarea readonly onclick="this.select()">{{ $qa['question'] }}</textarea>
                            <div class="sub">Answer</div>
                            <textarea readonly onclick="this.select()">{{ $qa['answer'] }}</textarea>
                        </div>
                    @endforeach
                </div>
            @endif
        @endif
    </div>
</x-filament-panels::page>

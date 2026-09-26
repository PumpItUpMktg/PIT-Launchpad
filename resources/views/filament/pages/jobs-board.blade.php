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
        /* workbench */
        .jb-card { background:var(--card); border:1px solid var(--line); border-radius:12px; padding:16px 18px; margin-bottom:14px; display:flex; flex-direction:column; gap:12px; }
        .jb-head { display:flex; align-items:flex-start; justify-content:space-between; gap:12px; flex-wrap:wrap; }
        .jb-head .jb-title { font-size:15px; }
        .jb-meta { font-size:12.5px; color:var(--ink-soft); }
        .jb-meta a { color:var(--teal-deep); }
        .jb-photos { display:flex; gap:8px; flex-wrap:wrap; }
        .jb-photo { position:relative; width:110px; height:110px; border-radius:10px; overflow:hidden; border:1px solid var(--line); background:var(--paper); }
        .jb-photo.primary { border:2px solid var(--teal); }
        .jb-photo img { width:100%; height:100%; object-fit:cover; display:block; }
        .jb-photo .star { position:absolute; top:4px; left:6px; color:var(--teal); font-size:14px; }
        .jb-lib { width:60px; height:60px; padding:0; cursor:pointer; }
        .jb-desc { display:grid; grid-template-columns:repeat(auto-fit, minmax(220px,1fr)); gap:10px; }
        .jb-col { border:1px solid var(--line); border-radius:10px; padding:10px 12px; background:var(--paper); }
        .jb-col .l { font-size:10.5px; text-transform:uppercase; letter-spacing:.05em; color:var(--ink-soft); font-weight:700; margin-bottom:4px; }
        .jb-col .t { font-size:13px; white-space:pre-wrap; color:var(--ink); }
        .jb-row { display:flex; gap:8px; flex-wrap:wrap; align-items:center; }
        .jb-lbl { font-size:11px; color:var(--ink-soft); margin:6px 0 4px; font-weight:600; }
        .jb-field { width:100%; border:1px solid var(--line); border-radius:8px; padding:8px 10px; font-size:13px; background:#fff; color:var(--ink); font-family:inherit; }
        textarea.jb-field { min-height:84px; resize:vertical; }
        .jb-grid { display:grid; grid-template-columns:repeat(auto-fit, minmax(220px,1fr)); gap:12px; }
        .jb-check { display:inline-flex; align-items:center; gap:6px; font-size:13px; cursor:pointer; border:1px solid var(--line); border-radius:20px; padding:5px 11px; background:#fff; }
        .jb-check:has(input:checked) { border-color:var(--teal); background:#E2F0EC; }
        .jb-ac { position:relative; }
        .jb-ac-list { position:absolute; z-index:20; left:0; right:0; top:calc(100% + 2px); background:#fff; border:1px solid var(--line); border-radius:8px; overflow:hidden; box-shadow:0 8px 24px rgba(15,23,42,.12); }
        .jb-ac-item { padding:8px 11px; font-size:12.5px; cursor:pointer; color:var(--ink); }
        .jb-ac-item:hover { background:#E2F0EC; }
        .jb-hint { font-size:12px; color:var(--ink-soft); }
        .jb-split { border-top:1px solid var(--line); padding-top:12px; margin-top:4px; }
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
            {{-- Add a previous job / import many --}}
            <div class="jb-card" wire:key="add-job-panel">
                <div class="jb-head">
                    <div>
                        <div class="jb-title">Add a previous job</div>
                        <div class="jb-meta">Backfill work you did before the app: type the address (it places the job — the phone isn’t needed), pick the services, add photos now or after. Or import many at once from a CSV.</div>
                    </div>
                    <button type="button" class="jb-btn {{ $addingJob ? '' : 'primary' }}" wire:click="toggleAddJob">{{ $addingJob ? 'Close' : '+ Add job / Import CSV' }}</button>
                </div>
                @if ($addingJob)
                    @php($suggestions = $this->addressSuggestions)
                    @php($typeOptions = $this->jobTypeOptions)
                    <div class="jb-grid">
                        <div>
                            <div class="jb-lbl">Client name</div>
                            <input type="text" class="jb-field" wire:model="newClientName" placeholder="Jane Homeowner">
                        </div>
                        <div class="jb-ac">
                            <div class="jb-lbl">Job address</div>
                            <input type="text" class="jb-field" wire:model.live.debounce.500ms="newAddress" placeholder="Start typing the street address…" autocomplete="off">
                            @if (count($suggestions) > 0)
                                <div class="jb-ac-list">
                                    @foreach ($suggestions as $sg)
                                        <div class="jb-ac-item" wire:key="ac-{{ md5($sg) }}" wire:click="pickSuggestion(@js($sg))">{{ $sg }}</div>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                        <div>
                            <div class="jb-lbl">Date performed</div>
                            <input type="date" class="jb-field" wire:model="newPerformedAt">
                        </div>
                    </div>
                    <div>
                        <div class="jb-lbl">Services performed — pick all that apply (up to 3)</div>
                        @if (count($typeOptions) > 0)
                            <div class="jb-row">
                                @foreach ($typeOptions as $opt)
                                    <label class="jb-check" wire:key="nt-{{ md5($opt) }}"><input type="checkbox" wire:model="newJobTypeLabels" value="{{ $opt }}"> {{ $opt }}</label>
                                @endforeach
                            </div>
                        @else
                            <div class="jb-hint">No services in this site’s catalog yet — add them under Services, or type them below.</div>
                        @endif
                        <input type="text" class="jb-field" style="margin-top:8px;" wire:model="newJobTypesOther" placeholder="Other service(s), comma separated">
                    </div>
                    <div>
                        <div class="jb-lbl" style="display:flex;justify-content:space-between;align-items:center;">
                            <span>What was done</span>
                            <button type="button" class="jb-btn" wire:click="enhanceDescription" wire:loading.attr="disabled" wire:target="enhanceDescription">
                                <span wire:loading.remove wire:target="enhanceDescription">✦ Enhance with AI</span>
                                <span wire:loading wire:target="enhanceDescription">Enhancing…</span>
                            </button>
                        </div>
                        <textarea class="jb-field" wire:model="newDescription" placeholder="Rough notes are fine — hit Enhance to polish, or write it yourself…"></textarea>
                    </div>
                    <div>
                        <div class="jb-lbl">Photos (optional, up to 3)</div>
                        <input type="file" class="jb-field" wire:model="newPhotos" multiple accept="image/*">
                        <div wire:loading wire:target="newPhotos" class="jb-hint">Uploading…</div>
                    </div>
                    <div class="jb-row">
                        <button type="button" class="jb-btn primary" wire:click="addJob" wire:loading.attr="disabled" wire:target="addJob,newPhotos">Add job</button>
                    </div>

                    <div class="jb-split">
                        <div class="jb-lbl" style="display:flex;justify-content:space-between;align-items:center;">
                            <span>Import many at once (CSV — text only; add photos per job below after import)</span>
                            <button type="button" class="jb-btn" wire:click="downloadTemplate">↓ Template</button>
                        </div>
                        <div class="jb-row">
                            <input type="file" class="jb-field" style="max-width:340px;" wire:model="csvFile" accept=".csv,text/csv">
                            <button type="button" class="jb-btn primary" wire:click="importCsv" wire:loading.attr="disabled" wire:target="csvFile,importCsv">
                                <span wire:loading.remove wire:target="importCsv">Import CSV</span>
                                <span wire:loading wire:target="importCsv">Importing…</span>
                            </button>
                        </div>
                        <div class="jb-hint">Columns: client_name, address, performed_at, service_types (; separated — matched to the services above), description. Each row is geocoded and lands here for review.</div>
                    </div>
                @endif
            </div>

            @if (empty($board['queue']))
                <x-lp.empty title="Nothing to review" action="Open Posts" :href="\App\Filament\Pages\Operate\OperateBlog::getUrl()">
                    Captured jobs land here for review. When a tech captures a job in the field, it shows up ready to approve into a published proof page.
                </x-lp.empty>
            @else
                @php($library = $this->libraryPhotos)
                @foreach ($board['queue'] as $j)
                    <div class="jb-card" wire:key="q-{{ $j['id'] }}">
                        <div class="jb-head">
                            <div>
                                <div class="jb-title">{{ $j['title'] }} <x-lp.chip :tone="$tone($j['status'])">{{ $j['status_label'] }}</x-lp.chip></div>
                                <div class="jb-meta">
                                    {{ $j['client'] ?: 'No client name' }}@if ($j['place'] !== '—') · {{ $j['place'] }}@else · <span title="Town resolves once the job is placed">no town yet</span>@endif @if ($j['performed_at'])· {{ $j['performed_at'] }}@endif
                                    @if ($j['lat'] !== null)
                                        · <a href="https://www.openstreetmap.org/?mlat={{ $j['lat'] }}&mlon={{ $j['lng'] }}#map=15/{{ $j['lat'] }}/{{ $j['lng'] }}" target="_blank" rel="noopener">map (approx.)</a>
                                    @endif
                                </div>
                                <div>@forelse ($j['services'] as $svc)<span class="jb-svc">{{ $svc }}</span>@empty<span class="jb-hint">No services tagged — Edit to add.</span>@endforelse</div>
                            </div>
                            @if ($editingId !== $j['id'] && $rejectingId !== $j['id'] && $placingId !== $j['id'])
                                <div class="jb-act">
                                    <button type="button" class="jb-btn primary" wire:click="approve('{{ $j['id'] }}')" @disabled(! $j['has_draft']) title="{{ $j['has_draft'] ? 'Approve into publishing' : 'Needs a write-up before it can be approved' }}">Approve &amp; publish</button>
                                    <button type="button" class="jb-btn" wire:click="reEnhance('{{ $j['id'] }}')">Re-enhance</button>
                                    <button type="button" class="jb-btn" wire:click="startEdit('{{ $j['id'] }}')">Edit</button>
                                    <button type="button" class="jb-btn" wire:click="startPlace('{{ $j['id'] }}')" title="Move the job to its real address">Re-place</button>
                                    <button type="button" class="jb-btn danger" wire:click="startReject('{{ $j['id'] }}')">Reject</button>
                                </div>
                            @endif
                        </div>

                        @if (count($j['photo_set']))
                            <div class="jb-photos">
                                @foreach ($j['photo_set'] as $p)
                                    <div class="jb-photo {{ $p['primary'] ? 'primary' : '' }}">
                                        @if ($p['url']) <img src="{{ $p['url'] }}" alt="{{ $p['alt'] }}" loading="lazy"> @endif
                                        @if ($p['primary']) <span class="star">★</span> @endif
                                    </div>
                                @endforeach
                            </div>
                        @endif

                        @if ($j['photos'] < 3)
                            <div class="jb-row">
                                <input type="file" class="jb-field" style="max-width:300px;" wire:model="jobPhotos.{{ $j['id'] }}" multiple accept="image/*">
                                <button type="button" class="jb-btn" wire:click="attachPhotos('{{ $j['id'] }}')" wire:loading.attr="disabled" wire:target="jobPhotos.{{ $j['id'] }},attachPhotos">
                                    <span wire:loading.remove wire:target="jobPhotos.{{ $j['id'] }}">Add photos</span>
                                    <span wire:loading wire:target="jobPhotos.{{ $j['id'] }}">Uploading…</span>
                                </button>
                                <span class="jb-hint">up to {{ 3 - $j['photos'] }} more</span>
                                @if ($library !== [])
                                    <span class="jb-hint">· or from the library:</span>
                                    @foreach ($library as $lib)
                                        <button type="button" class="jb-photo jb-lib" title="{{ $lib['label'] ?? 'Attach to this job' }}" wire:click="attachFromLibrary('{{ $j['id'] }}', '{{ $lib['id'] }}')" wire:loading.attr="disabled">
                                            <img src="{{ $lib['url'] }}" alt="{{ $lib['label'] }}" loading="lazy">
                                        </button>
                                    @endforeach
                                @endif
                            </div>
                        @endif

                        @if ($editingId === $j['id'])
                            @php($typeOptions = $this->jobTypeOptions)
                            <div class="jb-grid">
                                <div>
                                    <div class="jb-lbl">Client name (internal — published as “First L.”)</div>
                                    <input type="text" class="jb-field" wire:model="editClientName" placeholder="Jane Homeowner">
                                </div>
                                <div>
                                    <div class="jb-lbl">Date performed</div>
                                    <input type="date" class="jb-field" wire:model="editPerformedAt">
                                </div>
                            </div>
                            <div>
                                <div class="jb-lbl">Services performed (up to 3)</div>
                                @if (count($typeOptions) > 0)
                                    <div class="jb-row">
                                        @foreach ($typeOptions as $opt)
                                            <label class="jb-check" wire:key="et-{{ $j['id'] }}-{{ md5($opt) }}"><input type="checkbox" wire:model="editJobTypeLabels" value="{{ $opt }}"> {{ $opt }}</label>
                                        @endforeach
                                    </div>
                                @endif
                                <input type="text" class="jb-field" style="margin-top:8px;" wire:model="editJobTypesOther" placeholder="Other service(s), comma separated">
                            </div>
                            <div>
                                <div class="jb-lbl">Source (the AI seed — edit, then Re-enhance)</div>
                                <textarea class="jb-field" wire:model="editSource"></textarea>
                                <div class="jb-lbl">Post title</div>
                                <input type="text" class="jb-field" wire:model="editTitle">
                                <div class="jb-lbl">Meta description</div>
                                <input type="text" class="jb-field" wire:model="editMeta">
                                @if ($j['photos'] > 1)
                                    <div class="jb-lbl">Featured photo</div>
                                    <div class="jb-row">
                                        @foreach ($j['photo_set'] as $i => $p)
                                            <label class="jb-meta"><input type="radio" wire:model="editPrimary" value="{{ $i }}"> #{{ $i + 1 }}</label>
                                        @endforeach
                                    </div>
                                @endif
                            </div>
                            <div class="jb-row">
                                <button type="button" class="jb-btn primary" wire:click="saveEdits">Save edits</button>
                                <button type="button" class="jb-btn" wire:click="cancelEdit">Cancel</button>
                            </div>
                        @else
                            <div class="jb-desc">
                                <div class="jb-col"><div class="l">Tech notes (raw)</div><div class="t">{{ $j['raw'] ?: '—' }}</div></div>
                                <div class="jb-col"><div class="l">Source (seed)</div><div class="t">{{ $j['source'] ?: '—' }}</div></div>
                                <div class="jb-col"><div class="l">Write-up</div><div class="t">{{ $j['enhanced'] ?: '— not enhanced yet —' }}</div></div>
                            </div>
                        @endif

                        @if ($placingId === $j['id'])
                            @php($placeSuggestions = $this->placeSuggestions)
                            <div class="jb-ac">
                                <div class="jb-lbl">Re-place this job — its real street address</div>
                                <div class="jb-hint" style="margin-bottom:6px;">
                                    Currently placed {{ $j['address'] !== '' ? 'at: '.$j['address'] : 'by the capture GPS fix' }}. Moving it re-resolves the town/county, the public map pin, and the GPS written into every photo.
                                </div>
                                <input type="text" class="jb-field" wire:model.live.debounce.500ms="placeAddress" placeholder="Start typing the street address…" autocomplete="off">
                                @if (count($placeSuggestions) > 0)
                                    <div class="jb-ac-list">
                                        @foreach ($placeSuggestions as $sg)
                                            <div class="jb-ac-item" wire:key="pac-{{ $j['id'] }}-{{ md5($sg) }}" wire:click="pickPlaceSuggestion(@js($sg))">{{ $sg }}</div>
                                        @endforeach
                                    </div>
                                @endif
                                <div class="jb-row" style="margin-top:10px;">
                                    <button type="button" class="jb-btn primary" wire:click="place" wire:loading.attr="disabled" wire:target="place">Re-place job</button>
                                    <button type="button" class="jb-btn" wire:click="cancelPlace">Cancel</button>
                                    @if ($j['pushed'])<span class="jb-hint">Live page: re-approve after re-placing to republish.</span>@endif
                                </div>
                            </div>
                        @elseif ($rejectingId === $j['id'])
                            <div class="jb-reject">
                                <textarea wire:model="rejectReason" rows="2" placeholder="Reason (optional)"></textarea>
                                <div style="display:flex;flex-direction:column;gap:5px">
                                    <button type="button" class="jb-btn danger" wire:click="confirmReject">Confirm reject</button>
                                    <button type="button" class="jb-btn" wire:click="cancelReject">Cancel</button>
                                </div>
                            </div>
                        @endif
                    </div>
                @endforeach
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

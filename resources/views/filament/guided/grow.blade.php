@php $site = $this->getSite(); $brand = $site?->brand_name ?? 'your business'; $stats = $this->stats; $sections = $this->sections; $pages = collect($sections)->flatMap(fn ($s) => $s['pages']); @endphp
<x-guided.shell :steps="$this->steps" :brand="$brand" :grow="true">
    <div class="lp-eyebrow">Grow · {{ $brand }}</div>
    <h1 class="lp-h1">Your pages are ready — generate them at your pace</h1>
    <p class="lp-lede">Generate each page, review the draft, then publish. Nothing reaches WordPress until you publish it.</p>

    @unless ($site)
        <div class="lp-card"><div class="lp-empty">No sites yet.</div></div>
    @else
        {{-- Build-out counts: a header strip above the workbench, derived from the same page set.
             Plain-language labels with a one-line "what this means" — no internal vocabulary. --}}
        <div class="lp-stats">
            <div class="lp-stat"><div class="sn">{{ number_format($stats['live']) }}</div><div class="sl">Live on the site</div><div class="sc">Published and visible to visitors.</div></div>
            <div class="lp-stat"><div class="sn">{{ number_format($stats['building']) }}</div><div class="sl">Writing now</div><div class="sc">Being written, reviewed, or published.</div></div>
            <div class="lp-stat"><div class="sn">{{ number_format($stats['planned']) }}</div><div class="sl">Not started</div><div class="sc">Planned — not generated yet.</div></div>
        </div>

        {{-- THE primary content: the pages workbench. One row per planned page, morphing primary. --}}
        <div class="lp-card">
            <h3>Your pages</h3>
            <div class="hint">Each page generates on demand — nothing reaches WordPress until you publish.</div>

            @if ($pages->isEmpty())
                <div class="lp-empty" style="padding:22px">No pages yet — finalize your plan to materialize them here.</div>
            @else
                @php $bulkable = $pages->filter(fn ($p) => $p['bulk'] !== null); @endphp
                @if ($bulkable->isNotEmpty())
                    <div class="lp-bulkbar">
                        <span class="bsel">{{ count($this->selected) }} selected</span>
                        <button class="lp-btn ghost sm" wire:click="bulkApprove" @disabled(count($this->selected) === 0)>Approve selected</button>
                        <button class="lp-btn sm" wire:click="bulkPublish" @disabled(count($this->selected) === 0)>Publish selected</button>
                    </div>
                @endif

                {{-- Grouped by page type: each lane (Core / Service / Town) is in its own readiness state. --}}
                @foreach ($sections as $section)
                    <div class="lp-pgsection" wire:key="sec-{{ $section['key'] }}">
                        <div class="pgsection-head">
                            <span class="pgsection-title">{{ $section['label'] }}</span>
                            <span class="pgsection-count">{{ $section['count'] }}</span>
                        </div>
                        <ul class="lp-pglist">
                            @foreach ($section['pages'] as $p)
                                <div class="lp-pgrow" wire:key="pg-{{ $p['id'] }}">
                                    <div class="pgsel">
                                        @if ($p['bulk'] !== null)
                                            <input type="checkbox" wire:model.live="selected" value="{{ $p['id'] }}" aria-label="Select page">
                                        @endif
                                    </div>
                                    <div class="pgmain">
                                        <div class="pgtitle">{{ $p['title'] }}</div>
                                        <div class="pgperma">{{ $p['permalink'] }}</div>
                                        {{-- whose-move line — the scannability spine (your-move vs ours) --}}
                                        <div class="pgmove">{{ $p['whose_move'] }}</div>
                                    </div>
                                    <div class="pgstate">
                                        {{-- the sacred client line (shared with the future client screen) --}}
                                        <span class="pgbadge tone-{{ $p['tone'] }}">{{ $p['client_line'] }}</span>
                                        {{-- operator tail: append-only diagnostic, operator screen only --}}
                                        @if (!empty($p['operator_tail']))<div class="pgtail">{{ $p['operator_tail'] }}</div>@endif
                                    </div>
                                    <div class="pgact">
                                        @foreach ($p['actions'] as $act)
                                            @switch ($act)
                                                @case('generate')
                                                    <button class="lp-btn sm" wire:click="generate('{{ $p['id'] }}')" wire:loading.attr="disabled" wire:target="generate('{{ $p['id'] }}')">Generate</button>
                                                    @break
                                                @case('review')
                                                    <a class="lp-btn sm ghost" href="{{ $this->reviewUrl($p['id']) }}">Review</a>
                                                    @break
                                                @case('approve')
                                                    <button class="lp-btn sm warn" wire:click="approve('{{ $p['id'] }}')" wire:loading.attr="disabled" wire:target="approve('{{ $p['id'] }}')">Approve</button>
                                                    @break
                                                @case('publish')
                                                    <button class="lp-btn sm" wire:click="publish('{{ $p['id'] }}')" wire:loading.attr="disabled" wire:target="publish('{{ $p['id'] }}')">Publish</button>
                                                    @break
                                                @case('view')
                                                    @if ($p['live_url'])<a class="lp-btn sm ghost" href="{{ $p['live_url'] }}" target="_blank" rel="noopener">View</a>@endif
                                                    @break
                                            @endswitch
                                        @endforeach

                                        {{-- Secondary lifecycle controls, tucked in a native overflow menu so the row stays uncluttered. --}}
                                        @if (!empty($p['menu']))
                                            <details class="lp-menu" wire:key="menu-{{ $p['id'] }}">
                                                <summary class="lp-menu-trigger" title="More actions" aria-label="More actions">⋯</summary>
                                                <div class="lp-menu-pop">
                                                    @foreach ($p['menu'] as $m)
                                                        @switch ($m)
                                                            @case('regenerate')
                                                                <button type="button" wire:click="regenerate('{{ $p['id'] }}')">Regenerate</button>
                                                                @break
                                                            @case('lock')
                                                                <button type="button" wire:click="lock('{{ $p['id'] }}')">Lock</button>
                                                                @break
                                                            @case('reject')
                                                                <button type="button" wire:click="startReject('{{ $p['id'] }}')">Reject…</button>
                                                                @break
                                                            @case('delete')
                                                                <button type="button" class="danger" wire:click="delete('{{ $p['id'] }}')" wire:confirm="Delete this page? It's removed from your plan@if ($p['live_url']) and taken down from WordPress@endif.">Delete</button>
                                                                @break
                                                        @endswitch
                                                    @endforeach
                                                </div>
                                            </details>
                                        @endif
                                    </div>

                                    {{-- Inline reject-reason capture — appears full-width under the row it belongs to. --}}
                                    @if ($this->rejecting === $p['id'])
                                        <div class="pgreject" wire:key="rej-{{ $p['id'] }}">
                                            <input type="text" wire:model="rejectReason" wire:keydown.enter="reject('{{ $p['id'] }}')"
                                                   placeholder="Reason (optional) — helps sharpen the next draft" aria-label="Reject reason">
                                            <button class="lp-btn sm warn" wire:click="reject('{{ $p['id'] }}')">Confirm reject</button>
                                            <button class="lp-btn sm ghost" wire:click="cancelReject">Cancel</button>
                                        </div>
                                    @endif
                                </div>
                            @endforeach
                        </ul>
                    </div>
                @endforeach
            @endif
        </div>

        {{-- Operator tools — demoted: they live below the page actions, never as the whole toolbar. --}}
        <div class="lp-foot">
            <span class="lp-foot-label">Tools</span>
            <button class="lp-btn ghost" wire:click="reGround">Re-ground volume</button>
            <button class="lp-btn ghost" wire:click="reArrange">Re-arrange structure</button>
            <span class="lp-gate ok">Your finalized decisions are preserved on any re-run</span>
        </div>
    @endunless
</x-guided.shell>

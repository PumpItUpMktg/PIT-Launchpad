@php $site = $this->getSite(); $brand = $site?->brand_name ?? 'your business'; $stats = $this->stats; $sections = $this->sections; $pages = collect($sections)->flatMap(fn ($s) => $s['pages']); @endphp
<x-guided.shell :steps="$this->steps" :brand="$brand" :grow="true">
    <div class="lp-eyebrow">Grow · {{ $brand }}</div>
    <h1 class="lp-h1">Your pages are ready</h1>
    <p class="lp-lede">Generate each page, review the draft, then publish — at your pace.</p>

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
                                        @if (!empty($p['reason']))<div class="pgreason">{{ $p['reason'] }}</div>@endif
                                    </div>
                                    <span class="pgbadge tone-{{ $p['tone'] }}">{{ $p['state'] }}</span>
                                    <div class="pgact">
                                        @switch ($p['action'])
                                            @case('generate')
                                                <button class="lp-btn sm" wire:click="generate('{{ $p['id'] }}')" wire:loading.attr="disabled" wire:target="generate('{{ $p['id'] }}')">Generate</button>
                                                @break
                                            @case('review')
                                                <a class="lp-btn sm warn" href="{{ $this->reviewUrl($p['id']) }}">Review</a>
                                                @break
                                            @case('publish')
                                                <button class="lp-btn sm" wire:click="publish('{{ $p['id'] }}')" wire:loading.attr="disabled" wire:target="publish('{{ $p['id'] }}')">Publish</button>
                                                @break
                                            @case('view')
                                                @if ($p['live_url'])<a class="lp-btn sm ghost" href="{{ $p['live_url'] }}" target="_blank" rel="noopener">View</a>@endif
                                                @break
                                            {{-- One held state for the operator; data-hold carries the composer/grounding
                                                 distinction for our own debugging only (not user-facing copy). --}}
                                            @case('held')
                                                <span class="pgheld" data-hold="{{ $p['hold_kind'] }}">Held</span>
                                                @break
                                        @endswitch
                                    </div>
                                </div>
                            @endforeach
                        </ul>
                    </div>
                @endforeach
            @endif
        </div>

        {{-- Deferred layers: clearly labeled, never primary. --}}
        <div class="lp-card lp-later">
            <h3>Town pages <span class="laterpill">activates later</span></h3>
            <div class="hint">The coverage layer + drip controller. Towns build when they earn enough local relevance — a strong flood/soil story, or jobs &amp; reviews on the ground.</div>
            <div class="lp-empty" style="padding:18px">The town queue activates once the coverage layer and the drip controller are wired.</div>
        </div>

        <div class="lp-card lp-later">
            <h3>Fresh content <span class="laterpill">activates later</span></h3>
            <div class="hint">The news engine — local news, drafted into your categories and linked to the right service pages.</div>
            @forelse ($this->news as $item)
                <div class="lp-news">
                    @if ($item['silo'])<span class="silotag">{{ $item['silo'] }}</span>@endif
                    <div class="ntx"><b>{{ $item['title'] }}</b><div class="nmeta">{{ $item['status'] }}</div></div>
                </div>
            @empty
                <div class="lp-empty" style="padding:18px">No fresh-content posts yet — they appear as the news engine drafts them.</div>
            @endforelse
        </div>

        <div class="lp-foot">
            <button class="lp-btn ghost" wire:click="reGround">Re-ground volume</button>
            <button class="lp-btn ghost" wire:click="reArrange">Re-arrange structure</button>
            <span class="lp-gate ok">Your finalized decisions are preserved on any re-run</span>
        </div>
    @endunless
</x-guided.shell>

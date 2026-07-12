@php
    $site = $this->getSite();
    $brand = $site?->brand_name ?? 'your business';
    $status = $this->status;
    $spineColors = ['#0A4F4F', '#0E6B6B', '#4E9A98', '#A6CFCD'];
@endphp
<x-guided.shell :steps="$this->steps" :brand="$brand">
    <div class="lp-eyebrow">{{ \App\Enums\SetupStep::Plan->eyebrow() }}</div>
    <h1 class="lp-h1">Your website plan</h1>
    <p class="lp-lede">Every page we'll build for you, laid out as cards. When it looks right, approve it — your pages are set up instantly and you generate, review, and publish each one at your pace.</p>

    @unless ($site)
        <div class="lp-card"><div class="lp-empty">No sites yet — create a site to begin setup.</div></div>
    @elseif ($status === 'building' || $status === null)
        {{-- wire:init kicks the synchronous build chain on entry (no queue worker); the wire:loading
             block shows the spinner while that request is in flight and auto-clears when it returns.
             Stage labels cycle via Alpine — a raw <script> does NOT re-execute after wire:navigate
             (SPA) navigation, which is how the stepper reaches this page; Alpine runs reliably and
             tears the timer down via destroy() when the build returns and the block is removed. --}}
        <div class="lp-card" wire:init="runBuild">
            <div wire:loading.flex wire:target="runBuild"
                 class="lp-empty"
                 style="flex-direction:column;align-items:center;gap:12px;padding:48px"
                 x-data="{
                     stages: ['Planning your website…', 'Pulling categories…', 'Scoring search demand…', 'Arranging your pages…', 'Finalizing…'],
                     i: 0,
                     timer: null,
                     init() { this.timer = setInterval(() => { this.i = (this.i + 1) % this.stages.length }, 1400) },
                     destroy() { clearInterval(this.timer) }
                 }">
                <div class="lp-spinner"></div>
                <div style="font-family:'Archivo',sans-serif;font-weight:700;font-size:16px" x-text="stages[i]">Planning your website…</div>
                <div style="font-size:12.5px;color:var(--ink-soft)">This takes a moment — hang tight.</div>
            </div>
        </div>
    @elseif ($status === 'failed')
        <div class="lp-card">
            <div class="lp-empty">We couldn't build your plan — check that Step 1 has a trade and services.</div>
            <div style="text-align:center"><button class="lp-mini primary" wire:click="rebuild">Try again</button></div>
        </div>
    @else
        @php
            $inv = $this->inventory;
            $counts = $inv['counts'];
            $flags = $this->arrangeFlags;
        @endphp

        {{-- Judgment-call flags (amber) — accept / dismiss; they block Approve until clear --}}
        @foreach ($flags as $flag)
            <div class="lp-flag">
                <div class="fic">!</div>
                <div class="ftx">
                    <b>{{ $flag['type'] }}@if ($flag['spoke']) · {{ $flag['spoke'] }}@endif</b>
                    <div class="fsub">{{ $flag['message'] }}</div>
                </div>
                <div class="lp-fbtns">
                    <button class="lp-mini primary" wire:click="acceptFlag('{{ $flag['id'] }}')">Accept</button>
                    <button class="lp-mini" wire:click="dismissFlag('{{ $flag['id'] }}')">Dismiss</button>
                </div>
            </div>
        @endforeach

        <div class="lp-invsum">
            <div class="iv"><span class="ivn">{{ $counts['total'] }}</span><span class="ivl">pages total</span></div>
            <div class="iv"><span class="ivn">{{ $counts['foundation'] }}</span><span class="ivl">core pages</span></div>
            <div class="iv"><span class="ivn">{{ $counts['service'] }}</span><span class="ivl">service pages</span></div>
            <div class="iv"><span class="ivn">{{ $counts['location_now'] }}</span><span class="ivl">town pages</span></div>
            <div class="iv"><span class="ivn">{{ $counts['reserve'] }}</span><span class="ivl">towns in reserve</span></div>
        </div>

        {{-- Foundation — the standard layer. Optional pages toggle into the build. --}}
        <div class="lp-basic">
            <div class="bhd"><span class="bd"></span>Core pages — the basics every site needs</div>
            <div class="hint" style="margin:-6px 0 12px;color:var(--ink-soft)">Always built. Toggle the optional pages to include or remove them.</div>
            <div class="lp-basicgrid">
                @foreach ($inv['foundation'] as $page)
                    @if ($page['toggleable'])
                        <div wire:key="found-{{ $page['type'] }}"
                             class="lp-pageitem {{ $page['accepted'] ? 'opt on' : 'off' }}"
                             style="cursor:pointer;user-select:none"
                             title="{{ $page['accepted'] ? 'Included — click to remove' : 'Click to include' }}"
                             wire:click="toggleStandard('{{ $page['type'] }}')">
                            <span class="pd">{{ $page['accepted'] ? '✓' : '' }}</span>{{ $page['label'] }}
                        </div>
                    @else
                        <div wire:key="found-{{ $page['type'] }}" class="lp-pageitem on {{ $page['kind'] === 'legal' ? 'legal' : '' }}" title="Always built">
                            <span class="pd">✓</span>{{ $page['label'] }}
                        </div>
                    @endif
                @endforeach
            </div>
        </div>

        {{-- Service pages, as cards: the hub leads full-width, its pages beneath --}}
        <div class="lp-invlabel">Service pages</div>
        @forelse ($inv['silos'] as $si => $silo)
            <div class="lp-plangrid" wire:key="plan-silo-{{ $si }}">
                <div class="lp-pcard hub">
                    <span class="lp-ptag hub">Hub</span>
                    <span class="ttl">{{ $silo['hub']['name'] }}</span>
                    <span class="kw">“{{ $silo['hub']['keyword'] }}” · {{ $silo['count'] }} {{ \Illuminate\Support\Str::plural('page', $silo['count']) }}</span>
                </div>
                @foreach ($silo['pages'] as $page)
                    <div class="lp-pcard">
                        <span class="lp-ptag sub">Service</span>
                        <span class="ttl">{{ $page['name'] }}</span>
                        <span class="kw">“{{ $page['keyword'] }}”@if (count($page['covers']) > 0) · covers {{ collect($page['covers'])->take(3)->join(', ') }}@endif</span>
                    </div>
                @endforeach
                @foreach ($silo['subhubs'] as $sub)
                    <div class="lp-pcard">
                        <span class="lp-ptag hub">Sub-hub</span>
                        <span class="ttl">{{ $sub['name'] }}</span>
                        <span class="kw">“{{ $sub['keyword'] }}”</span>
                    </div>
                    @foreach ($sub['pages'] as $page)
                        <div class="lp-pcard">
                            <span class="lp-ptag sub">Service</span>
                            <span class="ttl">{{ $page['name'] }}</span>
                            <span class="kw">“{{ $page['keyword'] }}”@if (count($page['covers']) > 0) · covers {{ collect($page['covers'])->take(3)->join(', ') }}@endif</span>
                        </div>
                    @endforeach
                @endforeach
            </div>
        @empty
            <div class="lp-card"><div class="lp-empty">No service pages yet — the plan builds from your structure.</div></div>
        @endforelse

        {{-- Town pages, as cards by size tier --}}
        <div class="lp-invlabel">Town pages</div>
        @if (count($inv['tiers']) > 0 || $counts['reserve'] > 0)
            <div class="lp-plangrid">
                @foreach ($inv['tiers'] as $tier)
                    @foreach ($tier['towns'] as $town)
                        <div class="lp-pcard" wire:key="plan-town-{{ $tier['tier'] }}-{{ \Illuminate\Support\Str::slug($town) }}">
                            <span class="lp-ptag town">{{ $tier['label'] }}</span>
                            <span class="ttl">{{ $town }}</span>
                        </div>
                    @endforeach
                @endforeach
                @if ($counts['reserve'] > 0)
                    <div class="lp-pcard muted">
                        <span class="lp-ptag">Reserve</span>
                        <span class="ttl" style="color:var(--ink-soft);font-weight:500">+ {{ $counts['reserve'] }} more towns</span>
                        <span class="kw">Build automatically as you earn local relevance — adjust in Where you work.</span>
                    </div>
                @endif
            </div>
        @else
            <div class="lp-card"><div class="lp-empty">No town pages selected yet — pick them in Where you work.</div></div>
        @endif

        {{-- The structure tree, demoted to an adjust panel — most owners never open this --}}
        <details class="lp-adjust">
            <summary>
                <span>⚙ Adjust structure</span>
                <span class="sub">How the pages group and link — most owners never need this</span>
                @if ($this->unresolvedFlagCount > 0)
                    <span class="pgbadge tone-warn">{{ $this->unresolvedFlagCount }} to resolve</span>
                @endif
                <span class="chev">▾</span>
            </summary>
            <div class="body">
                @foreach ($this->bySilo as $silo => $rows)
                    @php
                        $pages = collect($rows)->reject(fn ($r) => $r->isFringe());
                        $vol = $pages->sum(fn ($r) => (int) ($r->volume ?? 0));
                        $pageCount = $pages->reject(fn ($r) => $r->granularity === \App\Enums\SpokeGranularity::Folded)->count();
                        $color = $spineColors[$loop->index % count($spineColors)];
                    @endphp
                    <div class="lp-silo">
                        <div class="lp-silohd">
                            <span class="spine" style="background:{{ $color }}"></span>
                            <div><div class="nm">{{ $silo }}</div><div class="meta">Category hub · {{ $pageCount }} {{ \Illuminate\Support\Str::plural('page', $pageCount) }}</div></div>
                            <span class="vol">{{ number_format($vol) }}</span>
                        </div>
                        <div class="lp-rows">
                            @foreach ($pages->reject(fn ($r) => $r->isPillar) as $row)
                                @php $folded = $row->granularity === \App\Enums\SpokeGranularity::Folded; @endphp
                                <div class="lp-prow {{ $folded ? 'child' : '' }}">
                                    <span class="pnm">{{ $row->name }}</span>
                                    <span class="pvol">{{ $row->volume ? number_format($row->volume) : '—' }}</span>
                                    <span class="lp-tag {{ $folded ? 'fold' : 'own' }}">{{ $folded ? 'Section' : 'Own page' }}</span>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endforeach
                <a class="lp-gate" style="color:var(--ink-soft)" href="{{ \App\Filament\Pages\SiloPrune::getUrl() }}" wire:navigate>Open the detailed structure editor →</a>
            </div>
        </details>

        {{-- Build config --}}
        @php $drip = $this->drip; @endphp
        <div class="lp-card">
            <h3>Before we build</h3>
            <div class="hint">Sensible defaults — adjust if you like.</div>
            <div class="lp-tog">
                <div><div class="tnm">Local relevance data</div><div class="tsub">Ground each town page in {{ $brand }}'s own local signals — local demand, competition, and your review footprint, pulled per town. No two sites use the same data.</div></div>
                <button class="lp-switch {{ $this->localize ? '' : 'off' }}" wire:click="toggleLocalize"></button>
            </div>
            <div class="lp-tog">
                <div><div class="tnm">Town pages build as they earn relevance</div><div class="tsub">{{ $drip['now'] }} biggest {{ \Illuminate\Support\Str::plural('town', $drip['now']) }} build now · {{ $drip['reserve'] }} in reserve, dripping live as each earns enough local relevance{{ $drip['ready'] > 0 ? ' ('.$drip['ready'].' already ready)' : '' }}.</div></div>
            </div>
            <div class="lp-tog">
                <div><div class="tnm">Town page pace</div><div class="tsub">Release up to {{ $this->townPagePace }} new town pages per week</div></div>
                <input type="number" min="1" class="lp-input" style="width:72px;margin-left:auto" wire:model="townPagePace">
            </div>
            <div class="lp-tog">
                <div><div class="tnm">Fresh content</div><div class="tsub">Publish local news-driven articles into your categories</div></div>
                <button class="lp-switch {{ $this->freshContent ? '' : 'off' }}" wire:click="toggleFreshContent"></button>
            </div>
        </div>

        <div class="lp-foot">
            <a class="lp-btn ghost" href="{{ \App\Enums\SetupStep::WhereYouWork->pageClass()::getUrl() }}" wire:navigate>Back</a>
            <button class="lp-btn" wire:click="approveAndBuild" @disabled(! $this->canApprove)>Approve &amp; build my site</button>
            @if ($this->canApprove)
                <span class="lp-gate ok">{{ $counts['total'] }} pages planned · you can change anything later from Grow</span>
            @elseif ($this->unresolvedFlagCount > 0)
                <span class="lp-gate">{{ $this->unresolvedFlagCount }} item{{ $this->unresolvedFlagCount === 1 ? '' : 's' }} need your input — open "Adjust structure"</span>
            @else
                <span class="lp-gate">Your plan needs at least one service page</span>
            @endif
        </div>
    @endunless
</x-guided.shell>

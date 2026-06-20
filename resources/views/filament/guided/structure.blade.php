@php
    $site = $this->getSite();
    $brand = $site?->brand_name ?? 'your business';
    $status = $this->status;
    $spineColors = ['#0A4F4F', '#0E6B6B', '#4E9A98', '#A6CFCD'];
@endphp
<x-guided.shell :steps="$this->steps" :brand="$brand">
    <div class="lp-eyebrow">{{ \App\Enums\SetupStep::Structure->eyebrow() }} · Operator view</div>
    <h1 class="lp-h1">Your structure is ready to review</h1>
    <p class="lp-lede">We grouped your services into categories, grounded them on real search demand, and made a few judgment calls. Resolve the flags, then finalize.</p>

    @unless ($site)
        <div class="lp-card"><div class="lp-empty">No sites yet — create a site to begin setup.</div></div>
    @elseif ($status === 'building' || $status === null)
        {{-- wire:init runs the chain synchronously; wire:loading shows the spinner until it returns. --}}
        <div class="lp-card" wire:init="runBuild">
            <div class="lp-empty" style="display:flex;flex-direction:column;align-items:center;gap:12px;padding:48px">
                <div class="lp-spinner"></div>
                <div style="font-family:'Archivo',sans-serif;font-weight:700;font-size:16px" data-lp-build-stage>Building your structure…</div>
                <div style="font-size:12.5px;color:var(--ink-soft)">This takes a moment — hang tight.</div>
            </div>
        </div>
        <script>
            (function () {
                var stages = ['Pulling categories…', 'Scoring volume…', 'Arranging silos…', 'Finalizing…'];
                var el = document.querySelector('[data-lp-build-stage]');
                if (! el) return;
                var i = 0;
                var t = setInterval(function () {
                    if (! document.body.contains(el)) { clearInterval(t); return; } // cleared when the build returns
                    el.textContent = stages[i % stages.length];
                    i++;
                }, 1400);
            })();
        </script>
    @elseif ($status === 'failed')
        <div class="lp-card">
            <div class="lp-empty">We couldn't build the structure — check that Step 1 has a trade and services.</div>
            <div style="text-align:center"><button class="lp-mini primary" wire:click="rebuild">Try again</button></div>
        </div>
    @else
        {{-- Flag cards (amber) — accept / dismiss; block Finalize until clear --}}
        @foreach ($this->arrangeFlags as $flag)
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

        {{-- Arranged tree --}}
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

        <div class="lp-foot">
            <a class="lp-btn ghost" href="{{ \App\Enums\SetupStep::Territory->pageClass()::getUrl() }}" wire:navigate>Back</a>
            <button class="lp-btn" wire:click="finalize" @disabled(! $this->canFinalize)>Finalize structure</button>
            @if ($this->canFinalize)
                <span class="lp-gate ok">All set — ready to finalize</span>
            @else
                <span class="lp-gate">{{ $this->unresolvedFlagCount }} item{{ $this->unresolvedFlagCount === 1 ? '' : 's' }} need your input first</span>
            @endif
            <a class="lp-gate" style="margin-left:auto;color:var(--ink-soft)" href="{{ \App\Filament\Pages\SiloPrune::getUrl() }}" wire:navigate>Open detailed prune →</a>
        </div>
    @endunless
</x-guided.shell>

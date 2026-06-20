@php
    $site = $this->getSite();
    $brand = $site?->brand_name ?? 'your business';
    $progress = $this->progress;
    $statusColors = [
        'published' => ['#E2F0EC', '#2E7D6B'],
        'in_review' => ['#FBEFD9', '#B5731A'],
        'queued' => ['#EEF2F4', '#54666C'],
        'drafting' => ['#E7EFEF', '#0A4F4F'],
        'composed' => ['#E7EFEF', '#0A4F4F'],
    ];
@endphp
<x-guided.shell :steps="$this->steps" :brand="$brand">
    <div class="lp-eyebrow">Build</div>
    <h1 class="lp-h1">Building your site</h1>
    <p class="lp-lede">Your foundational pages go up first, behind a quick review. Services and towns follow on the drip.</p>

    @unless ($site)
        <div class="lp-card"><div class="lp-empty">No sites yet.</div></div>
    @else
        {{-- Overall progress --}}
        <div class="lp-card">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px">
                <h3 style="margin:0">Progress</h3>
                <span class="lp-mono" style="font-weight:600;color:var(--teal-deep)">{{ $progress['published'] }} / {{ $progress['total'] }} live</span>
            </div>
            <div style="height:10px;border-radius:6px;background:#EEF2F4;overflow:hidden">
                <div style="height:100%;width:{{ $progress['pct'] }}%;background:var(--teal)"></div>
            </div>
        </div>

        {{-- Review queue (brand-critical, gated) --}}
        @if (! empty($this->reviewQueue))
            <div class="lp-card">
                <h3>Needs your review</h3>
                <div class="hint">Brand-critical pages publish only after you approve them.</div>
                @foreach ($this->reviewQueue as $page)
                    <div class="lp-flag">
                        <div class="fic">★</div>
                        <div class="ftx"><b>{{ $page->title }}</b><div class="fsub">{{ $page->recipe }}</div></div>
                        <div class="lp-fbtns"><button class="lp-mini primary" wire:click="publishReviewed('{{ $page->id }}')">Approve &amp; publish</button></div>
                    </div>
                @endforeach
            </div>
        @endif

        {{-- Manifest grouped by source --}}
        @foreach ($this->manifest as $source => $pages)
            <div class="lp-card">
                <h3>{{ $source }} <span style="color:var(--ink-soft);font-weight:400">· {{ count($pages) }}</span></h3>
                @foreach ($pages as $page)
                    @php [$bg, $fg] = $statusColors[$page->status->value] ?? ['#EEF2F4', '#54666C']; @endphp
                    <div class="lp-q">
                        <span class="qd" style="background:{{ $fg }}"></span>
                        <div><div class="qn">{{ $page->title }}</div><div class="qs">{{ $page->recipe }}</div></div>
                        <div class="qr"><span class="lp-pill" style="background:{{ $bg }};color:{{ $fg }}">{{ $page->status->label() }}</span></div>
                    </div>
                @endforeach
            </div>
        @endforeach

        <div class="lp-foot">
            <button class="lp-btn" wire:click="continueToGrow" @disabled(! $this->launched)>Continue to Grow</button>
            @if ($this->launched)
                <span class="lp-gate ok">Your site is live — foundation published</span>
            @else
                <span class="lp-gate">Approve the brand-critical pages to go live</span>
            @endif
        </div>
    @endunless
</x-guided.shell>

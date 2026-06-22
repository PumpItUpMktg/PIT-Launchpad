<x-filament-panels::page>
    @include('filament._lp-styles')
    <div class="lpa">
        <div class="lp-row">
            <div>
                <div class="lp-eyebrow">Portfolio</div>
                <div class="lp-h1">Your sites</div>
            </div>
            <a class="lp-btn" href="{{ $this->newSiteUrl() }}" wire:navigate>+ New site</a>
        </div>

        @if (count($this->sites) > 0)
            <div class="lp-cards">
                @foreach ($this->sites as $card)
                    <a class="lp-sitecard" href="{{ $card['url'] }}" wire:navigate>
                        <div class="nm">
                            {{ $card['name'] }}
                            <span class="lp-status {{ $card['onboarding'] ? 'onboarding' : 'live' }}">{{ ucfirst($card['status']) }}</span>
                        </div>

                        @if ($card['onboarding'])
                            <div class="lp-prog"><i style="width:{{ $card['pct'] }}%"></i></div>
                            <div class="lp-progtxt">{{ $card['pct'] }}% through setup — resume the wizard</div>
                        @else
                            @php $pg = $card['pages']; @endphp
                            <div class="lp-progtxt" style="margin:2px 0 8px">{{ $pg['published'] }}/{{ $pg['total'] }} {{ \Illuminate\Support\Str::plural('page', $pg['total']) }} published — build the rest on demand</div>
                            <div class="lp-sigs">
                                <div class="lp-sig"><span class="v">{{ number_format($card['signals']['publish']) }}</span><span class="l">to publish</span></div>
                                <div class="lp-sig"><span class="v {{ $card['signals']['review'] ? 'warn' : '' }}">{{ number_format($card['signals']['review']) }}</span><span class="l">needs review</span></div>
                                <div class="lp-sig"><span class="v {{ $card['signals']['failed'] ? 'bad' : '' }}">{{ number_format($card['signals']['failed']) }}</span><span class="l">needs attention</span></div>
                            </div>
                        @endif
                    </a>
                @endforeach
            </div>
        @else
            <div class="lp-card"><div class="lp-empty">No sites yet — add your first site to begin.</div></div>
        @endif
    </div>
</x-filament-panels::page>

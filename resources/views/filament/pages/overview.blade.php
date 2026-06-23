<x-filament-panels::page>
    @include('filament._lp-styles')
    @php
        $all = collect($this->sites);
        $setup = $all->where('mode', 'setup')->values();
        $live = $all->where('mode', 'live')->values();
    @endphp
    <div class="lpa">
        <div class="lp-row">
            <div>
                <div class="lp-eyebrow">Portfolio</div>
                <div class="lp-h1">What needs you</div>
            </div>
            <a class="lp-btn" href="{{ $this->newSiteUrl() }}" wire:navigate>+ New site</a>
        </div>

        @if ($all->isEmpty())
            <div class="lp-card">
                <div class="lp-empty">
                    <div style="font-weight:600;margin-bottom:4px">Add your first site</div>
                    Launchpad builds and feeds a site for each of your clients.
                </div>
                <div style="margin-top:14px"><a class="lp-btn" href="{{ $this->newSiteUrl() }}" wire:navigate>+ Add your first site</a></div>
            </div>
        @else
            {{-- In-setup cluster on top — setup tasks, quarantined from content tasks. --}}
            @if ($setup->isNotEmpty())
                <div class="lp-eyebrow" style="margin:18px 0 8px">In setup</div>
                <div class="lp-cards">
                    @foreach ($setup as $card)
                        <a class="lp-sitecard" href="{{ $card['url'] }}" wire:navigate>
                            <div class="nm">
                                {{ $card['name'] }}
                                <span class="lp-status onboarding">{{ $card['stalled'] ? 'Stalled' : 'In setup' }}</span>
                            </div>
                            <div class="lp-prog"><i style="width:{{ $card['pct'] }}%"></i></div>
                            <div class="lp-progtxt">Resume setup · {{ $card['resume'] }}</div>
                        </a>
                    @endforeach
                </div>
            @endif

            {{-- Live sites below — content tasks; open Grow. --}}
            @if ($live->isNotEmpty())
                <div class="lp-eyebrow" style="margin:22px 0 8px">Live sites</div>
                <div class="lp-cards">
                    @foreach ($live as $card)
                        @php $pg = $card['pages']; $failed = $card['signals']['failed'] > 0; @endphp
                        <a class="lp-sitecard" href="{{ $card['url'] }}" wire:navigate>
                            <div class="nm">
                                {{ $card['name'] }}
                                <span class="lp-status {{ $failed ? 'bad' : 'live' }}">{{ ucfirst($card['status']) }}</span>
                            </div>
                            <div class="lp-worktxt" style="font-weight:600;margin:2px 0 6px {{ $failed ? ';color:var(--lp-bad)' : '' }}">{{ $card['work'] }}</div>
                            <div class="lp-progtxt">{{ $pg['published'] }} of {{ $pg['total'] }} {{ \Illuminate\Support\Str::plural('page', $pg['total']) }} live</div>
                        </a>
                    @endforeach
                </div>
            @endif
        @endif
    </div>
</x-filament-panels::page>

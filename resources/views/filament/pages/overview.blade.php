@php
    $all = collect($this->sites);
    $setup = $all->where('mode', 'setup')->values();
    $live = $all->where('mode', 'live')->values();
@endphp
<x-lp.shell variant="board" eyebrow="Portfolio" title="What needs you" :scope="false">
    <x-slot:action>
        <a class="lp-btn" href="{{ $this->newSiteUrl() }}" wire:navigate>+ New site</a>
    </x-slot:action>

    <div>
        @if ($all->isEmpty())
            <div class="lp-card">
                <x-lp.empty title="Add your first site" action="+ Add your first site" :href="$this->newSiteUrl()">
                    Launchpad builds and feeds a site for each of your clients.
                </x-lp.empty>
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
                                <x-lp.chip :tone="$card['stalled'] ? 'bad' : 'warn'" :label="$card['stalled'] ? 'Stalled' : 'In setup'" />
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
                                <span style="display:inline-flex;gap:6px;align-items:center">
                                    @if ($failed)<x-lp.chip tone="bad" label="Jobs failed" />@endif
                                    <x-lp.chip :for="$card['status']" />
                                </span>
                            </div>
                            <div class="lp-worktxt" style="font-weight:600;margin:2px 0 6px{{ $failed ? ';color:#B5341A' : '' }}">{{ $card['work'] }}</div>
                            <div class="lp-progtxt">{{ $pg['published'] }} of {{ $pg['total'] }} {{ \Illuminate\Support\Str::plural('page', $pg['total']) }} live</div>
                        </a>
                    @endforeach
                </div>
            @endif
        @endif
    </div>
</x-lp.shell>

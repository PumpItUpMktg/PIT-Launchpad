<x-filament-panels::page>
    <div class="lv-wrap">
        @include('filament.live.partials.shell-top', ['subtitle' => 'Every published geo surface, grouped by the physical location that serves it. Regenerating or taking a page down moves it back to the Grow board automatically.'])

        @php $board = $this->board; @endphp

        @forelse ($board['groups'] as $group)
            <div class="lv-locgroup" wire:key="lvg-{{ $group['location']['id'] }}">
                {{-- The location leads its group as a big card: identity + its territory line on
                     the left, the towns rollup as stat blocks on the right. --}}
                <div class="lv-loccard">
                    <div class="id">
                        <h2>{{ $group['location']['name'] !== '' ? $group['location']['name'] : $group['location']['city'] }}
                            <span class="badge">{{ $group['location']['storefront'] ? 'storefront' : 'service area' }}</span>
                        </h2>
                        @if ($group['location']['served'] !== [])
                            <div class="serves">Serves {{ implode(', ', $group['location']['served']) }}</div>
                        @endif
                    </div>
                    <div class="lv-locstats">
                        <div class="lv-locstat"><div class="n">{{ $group['rollup']['towns_live'] }}</div><div class="l">town pages live</div></div>
                        <div class="lv-locstat"><div class="n">{{ $group['rollup']['avg_rank'] !== null ? '#'.$group['rollup']['avg_rank'] : '—' }}</div><div class="l">avg position</div></div>
                        <div class="lv-locstat"><div class="n">{{ $group['rollup']['impressions'] !== null ? number_format($group['rollup']['impressions']) : '—' }}</div><div class="l">impressions · 28d</div></div>
                        <div class="lv-locstat"><div class="n">{{ $group['rollup']['clicks'] !== null ? number_format((int) $group['rollup']['clicks']) : '—' }}</div><div class="l">clicks · 28d</div></div>
                    </div>
                </div>

                @if ($group['location_card'] !== null)
                    <div class="lv-band">Location page</div>
                    <div class="lv-towns">
                        @include('filament.live.partials.card', ['card' => $group['location_card']])
                    </div>
                @else
                    <div class="lv-band">Location page</div>
                    <div style="padding:8px 16px 4px"><div class="lv-empty">No location landing page published yet — generate it with <code>launchpad:generate-location</code> and publish from Grow.</div></div>
                @endif

                @if ($group['towns'] !== [])
                    <div class="lv-band">Town pages assigned to this location</div>
                    <div class="lv-towns">
                        @foreach ($group['towns'] as $card)
                            @include('filament.live.partials.card', ['card' => $card])
                        @endforeach
                    </div>
                @endif

                @if ($group['city_services'] !== [])
                    <div class="lv-band">City-service pages · earned per pair</div>
                    <div class="lv-towns">
                        @foreach ($group['city_services'] as $card)
                            @include('filament.live.partials.card', ['card' => $card])
                        @endforeach
                    </div>
                @endif
            </div>
        @empty
            <div class="lv-empty">No locations captured yet — add the business location(s) in setup; published geo pages will group here.</div>
        @endforelse

        @if ($board['orphans'] !== [])
            <div>
                <div class="lv-band" style="padding-left:0">Unassigned town pages — pick the location that serves each town
                    <button type="button" class="lv-btn" style="margin-left:10px" wire:click="reassign">Re-run auto-assign</button>
                </div>
                <div class="lv-grid" style="margin-top:8px">
                    @foreach ($board['orphans'] as $card)
                        @include('filament.live.partials.card', ['card' => $card, 'locationOptions' => $board['location_options']])
                    @endforeach
                </div>
            </div>
        @endif
    </div>
</x-filament-panels::page>

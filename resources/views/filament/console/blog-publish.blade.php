<x-filament-panels::page>
    @include('filament.console.partials.blog-filters')
    @include('filament.console.partials.blog-card-styles')

    @php $items = $this->publishing; @endphp

    <p style="color:#94a3b8; font-size:13px; margin:0 0 4px;">Approved posts, ready to publish. Publishing pushes straight to WordPress. Live posts appear under Published.</p>

    <div class="bc-list">
        @forelse ($items as $p)
            <div class="bc-card" wire:key="pub-{{ $p['id'] }}">
                {{-- Top line: silo · source · date --}}
                <div class="bc-top">
                    @if (! empty($p['silo'])) <span class="bc-chip silo">{{ $p['silo'] }}</span> @endif
                    @if (! empty($p['source'])) <span class="bc-chip source">{{ $p['source'] }}</span> @endif
                    @if (! empty($p['date'])) <span class="bc-chip date">🗓 {{ $p['date'] }}</span> @endif
                </div>

                {{-- Title --}}
                <p class="bc-title">{{ $p['title'] ?: 'Untitled post' }}</p>

                {{-- Longtail keyword + towns --}}
                @if (! empty($p['keyword']) || ! empty($p['towns']))
                    <div class="bc-kw">
                        @if (! empty($p['keyword'])) <span class="k">Target:</span> {{ $p['keyword'] }} @endif
                        @foreach (array_slice($p['towns'] ?? [], 0, 4) as $town) <span class="bc-tag town">📍 {{ $town }}</span> @endforeach
                        @if (count($p['towns'] ?? []) > 4) <span class="bc-tag">+{{ count($p['towns']) - 4 }}</span> @endif
                    </div>
                @endif

                {{-- Excerpt --}}
                @if (! empty($p['excerpt'])) <div class="bc-excerpt">{{ $p['excerpt'] }}</div> @endif

                {{-- State line --}}
                <div class="bc-sub {{ ($p['stalled'] ?? false) ? 'stalled' : '' }}">
                    {{ $p['state'] ?? 'ready' }}{{ ($p['stalled'] ?? false) ? ' — stalled' : '' }}
                </div>

                {{-- Footer: Publish + prominent score --}}
                <div class="bc-foot">
                    <div class="bc-actions">
                        @if (($p['state'] ?? '') === 'queued to publish' || ($p['stalled'] ?? false))
                            @if ($this->can(\App\Security\Capability::PublishContent))
                                <button class="bc-btn green" wire:click="publish('{{ $p['id'] }}')"
                                        wire:loading.attr="disabled" wire:target="publish('{{ $p['id'] }}')">
                                    <span wire:loading.remove wire:target="publish('{{ $p['id'] }}')">Publish</span>
                                    <span wire:loading wire:target="publish('{{ $p['id'] }}')">Publishing…</span>
                                </button>
                            @endif
                        @else
                            <span class="bc-sub">In progress…</span>
                        @endif
                    </div>
                    @if (($p['score'] ?? null) !== null)
                        @php $s = (float) $p['score']; @endphp
                        <div class="bc-score {{ $s >= 0.7 ? '' : ($s >= 0.4 ? 'mid' : 'low') }}">
                            <span class="n">{{ number_format($s, 2) }}</span>
                            <span class="l">Score</span>
                        </div>
                    @endif
                </div>

                {{-- Upload / replace photo --}}
                @include('filament.console.partials.swap-photo', ['id' => $p['id']])
            </div>
        @empty
            <div class="bc-empty">Nothing ready to publish. Approve drafts in Review to queue them here.</div>
        @endforelse
    </div>
</x-filament-panels::page>

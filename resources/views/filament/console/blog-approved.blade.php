<x-filament-panels::page>
    @include('filament.console.partials.blog-filters')
    @include('filament.console.partials.blog-card-styles')

    @php $items = $this->approved; @endphp

    <p style="color:#94a3b8; font-size:13px; margin:0 0 4px;">Approved posts, waiting for a final look. Preview the finished post, then Send to Publish to queue the push. Sent-to-Publish posts move to the Publish page.</p>

    <div class="bc-list">
        @forelse ($items as $p)
            <div class="bc-card" wire:key="appr-{{ $p['id'] }}">
                {{-- Generate-time hero render --}}
                @include('filament.console.partials.blog-thumb', ['image' => $p['image'] ?? null])

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

                {{-- Footer: Preview + Send to Publish + score --}}
                <div class="bc-foot">
                    <div class="bc-actions">
                        <a class="bc-btn" href="{{ \App\Filament\Console\Pages\BlogPreview::getUrl(['content' => $p['id']]) }}">Preview</a>
                        @if ($this->can(\App\Security\Capability::ApproveContent))
                            <button class="bc-btn green" wire:click="release('{{ $p['id'] }}')"
                                    wire:loading.attr="disabled" wire:target="release('{{ $p['id'] }}')">
                                <span wire:loading.remove wire:target="release('{{ $p['id'] }}')">Send to Publish</span>
                                <span wire:loading wire:target="release('{{ $p['id'] }}')">Sending…</span>
                            </button>
                        @endif
                        @if ($this->can(\App\Security\Capability::EditContent))
                            <button class="bc-btn" wire:click="sendBack('{{ $p['id'] }}')">Back to Review</button>
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

                {{-- Swap the generate-time render for an operator-supplied photo --}}
                @include('filament.console.partials.swap-photo', ['id' => $p['id']])
            </div>
        @empty
            <div class="bc-empty">Nothing approved yet. Approve drafts in Review and they land here for a final look.</div>
        @endforelse
    </div>
</x-filament-panels::page>

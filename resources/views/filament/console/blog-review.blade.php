<x-filament-panels::page>
    @include('filament.console.partials.blog-filters')
    @include('filament.console.partials.blog-card-styles')

    @php $review = $this->review; @endphp

    <div class="bc-list">
        @forelse ($review as $r)
            <div class="bc-card" wire:key="rev-{{ $r['id'] }}">
                {{-- Generate-time hero render (present as soon as the draft lands) --}}
                @include('filament.console.partials.blog-thumb', ['image' => $r['image'] ?? null])

                {{-- Top line: silo · source · date --}}
                <div class="bc-top">
                    @if (! empty($r['silo'])) <span class="bc-chip silo">{{ $r['silo'] }}</span> @endif
                    @if (! empty($r['source'])) <span class="bc-chip source">{{ $r['source'] }}</span> @endif
                    @if (! empty($r['date'])) <span class="bc-chip date">🗓 {{ $r['date'] }}</span> @endif
                </div>

                {{-- Title --}}
                <p class="bc-title">{{ $r['title'] ?: 'Untitled draft' }}</p>

                {{-- Longtail keyword + state / towns --}}
                <div class="bc-kw">
                    @if (! empty($r['keyword'])) <span class="k">Target:</span> {{ $r['keyword'] }} @endif
                    @php $state = $r['state'] ?? $r['status']; @endphp
                    <span class="bc-tag {{ $state === 'writing' ? 'writing' : (in_array($state, ['draft_failed','render_failed','publish_failed','undrafted'], true) ? 'bad' : '') }}">{{ str_replace('_', ' ', (string) $state) }}</span>
                    @foreach (array_slice($r['towns'] ?? [], 0, 4) as $town) <span class="bc-tag town">📍 {{ $town }}</span> @endforeach
                    @if (count($r['towns'] ?? []) > 4) <span class="bc-tag">+{{ count($r['towns']) - 4 }}</span> @endif
                </div>

                {{-- Excerpt (or draft error) --}}
                @if (! empty($r['draft_error']))
                    <div class="bc-excerpt" style="color:#dc2626;">{{ $r['draft_error'] }}</div>
                @elseif (! empty($r['excerpt']))
                    <div class="bc-excerpt">{{ $r['excerpt'] }}</div>
                @endif

                {{-- Inline reject reason --}}
                @if ($rejectingId === $r['id'])
                    <div class="bc-reject">
                        <input type="text" wire:model="rejectReason" placeholder="Reason (optional)" wire:keydown.enter="reject">
                        <button class="bc-btn danger" wire:click="reject">Confirm</button>
                        <button class="bc-btn" wire:click="cancelReject">Cancel</button>
                    </div>
                @endif

                {{-- Footer: Approve / Generate / Reject + prominent score --}}
                <div class="bc-foot">
                    <div class="bc-actions">
                        @php $hasDraft = $r['has_draft'] ?? false; @endphp
                        @if ($hasDraft && $this->can(\App\Security\Capability::ApproveContent))
                            <button class="bc-btn green" wire:click="approve('{{ $r['id'] }}')" wire:loading.attr="disabled" wire:target="approve('{{ $r['id'] }}')">Approve</button>
                        @endif
                        @if ($this->can(\App\Security\Capability::GenerateContent))
                            <button class="bc-btn {{ $hasDraft ? '' : 'primary' }}" wire:click="regenerate('{{ $r['id'] }}')" wire:loading.attr="disabled" wire:target="regenerate('{{ $r['id'] }}')">
                                <span wire:loading.remove wire:target="regenerate('{{ $r['id'] }}')">{{ $hasDraft ? 'Regenerate' : 'Generate' }}</span>
                                <span wire:loading wire:target="regenerate('{{ $r['id'] }}')">Working…</span>
                            </button>
                        @endif
                        @if ($this->can(\App\Security\Capability::EditContent) && $rejectingId !== $r['id'])
                            <button class="bc-btn danger" wire:click="startReject('{{ $r['id'] }}')">Reject</button>
                        @endif
                    </div>
                    @if (($r['score'] ?? null) !== null)
                        @php $s = (float) $r['score']; @endphp
                        <div class="bc-score {{ $s >= 0.7 ? '' : ($s >= 0.4 ? 'mid' : 'low') }}">
                            <span class="n">{{ number_format($s, 2) }}</span>
                            <span class="l">Score</span>
                        </div>
                    @endif
                </div>

                {{-- Swap the generate-time render for an operator-supplied photo --}}
                @include('filament.console.partials.swap-photo', ['id' => $r['id']])
            </div>
        @empty
            <div class="bc-empty">Nothing waiting for review. Generated drafts land here.</div>
        @endforelse
    </div>
</x-filament-panels::page>

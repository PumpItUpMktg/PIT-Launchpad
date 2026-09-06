{{-- Rich published-post card: the Live-page tracking block (index / Bing / GSC + found-in-search / GA4 /
     target keyword + position) plus the console extras — silo, brick-and-mortar towns, and internal links
     both ways. $post is the PublishedContentBoard post-card array. --}}
@php $m = $post['metrics']; @endphp
<div class="rc-card" wire:key="live-{{ $post['id'] }}">
    {{-- Thumbnail --}}
    @if (! empty($post['image']))
        <img class="rc-thumb" src="{{ $post['image'] }}" alt="" loading="lazy">
    @endif

    {{-- Header: identity chips + live/index/bing status --}}
    <div class="rc-head">
        <div class="rc-chips">
            @if (! empty($post['silo'])) <span class="rc-chip silo">{{ $post['silo'] }}</span> @endif
            @foreach (array_slice($post['towns'] ?? [], 0, 4) as $town) <span class="rc-chip town">📍 {{ $town }}</span> @endforeach
            @if (count($post['towns'] ?? []) > 4) <span class="rc-chip">+{{ count($post['towns']) - 4 }}</span> @endif
            @if ($post['days_live'] !== null) <span class="rc-chip">Live · {{ $post['days_live'] }}d</span> @endif
        </div>
        <div class="rc-chips">
            @if (($post['score'] ?? null) !== null)
                @php $sc = (float) $post['score']; @endphp
                <span class="rc-chip score {{ $sc >= 0.7 ? 'good' : ($sc >= 0.4 ? '' : 'muted') }}" title="Engine score at generation — track it against real ranking/index below">◎ {{ number_format($sc, 2) }}</span>
            @endif
            @php $ip = $post['index_pill']; @endphp
            <span class="rc-ipill {{ $ip['state'] }}" title="{{ $ip['title'] }}">{{ $ip['label'] }}@if (! empty($m['index']['last_crawled_at'])) · {{ $m['index']['last_crawled_at'] }}@endif</span>
            @if (! empty($post['indexnow_at'])) <span class="rc-chip muted">↗ Submitted to Bing</span> @endif
        </div>
    </div>

    {{-- Title + URL --}}
    <div class="rc-title">{{ $post['title'] ?: 'Untitled' }}</div>
    @if (! empty($post['url']))
        <a class="rc-url" href="{{ $post['url'] }}" target="_blank" rel="noopener">{{ $post['url'] }} ↗</a>
    @endif
    @if (! empty($post['published_at'])) <div class="rc-sub">Published {{ $post['published_at'] }}</div> @endif

    {{-- Metrics grid --}}
    <div class="rc-grid">
        <div class="rc-stat">
            <div class="rc-k">Target keyword</div>
            <div class="rc-v">{{ $m['keyword'] ?? ($post['keyword'] ?? '—') }}</div>
        </div>
        <div class="rc-stat">
            <div class="rc-k">Target page</div>
            <div class="rc-v">
                @if (! empty($post['target_page']))
                    <a class="rc-url" @if ($post['target_page']['url']) href="{{ $post['target_page']['url'] }}" target="_blank" rel="noopener" @endif>{{ $post['target_page']['title'] }} ↗</a>
                @else
                    <span class="rc-muted">—</span>
                @endif
            </div>
        </div>
        <div class="rc-stat">
            <div class="rc-k">Position</div>
            <div class="rc-v">
                @if ($m['position']['rank'] !== null)
                    #{{ $m['position']['rank'] }}
                    @if (($m['position']['delta'] ?? null)) <span class="rc-delta">({{ $m['position']['delta'] > 0 ? '▲' : '▼' }}{{ abs($m['position']['delta']) }})</span> @endif
                @else
                    <span class="rc-muted lp-rank{{ ($m['position']['state'] ?? null) ? ' lp-rank--'.$m['position']['state'] : '' }}"
                          @if ($m['position']['state'] ?? null) data-ranking-state="{{ $m['position']['state'] }}" @endif>{{ $m['position']['pending'] ?? \App\Enums\RankingState::TrackedNotRanking->label() }}</span>
                @endif
            </div>
        </div>
        <div class="rc-stat">
            <div class="rc-k">GSC · 28d</div>
            <div class="rc-v">
                @if ($m['gsc']['pending'])
                    <span class="rc-muted">{{ $m['gsc']['pending'] }}</span>
                @else
                    {{ number_format((int) $m['gsc']['impressions']) }} impr ·
                    {{ number_format((int) $m['gsc']['clicks']) }} clicks ·
                    {{ number_format((float) $m['gsc']['ctr'] * 100, 1) }}% CTR
                @endif
            </div>
        </div>
        <div class="rc-stat">
            <div class="rc-k">GA4 sessions</div>
            <div class="rc-v">{{ $m['traffic']['pending'] ? '' : number_format((int) $m['traffic']['sessions']) }}<span class="rc-muted">{{ $m['traffic']['pending'] }}</span></div>
        </div>
    </div>

    {{-- Found in search for --}}
    @if (! empty($m['gsc']['queries']))
        <div class="rc-block">
            <div class="rc-k">Found in search for</div>
            <div class="rc-queries">
                @foreach (array_slice($m['gsc']['queries'], 0, 6) as $q)
                    <span class="rc-q">{{ $q['query'] }} <em>#{{ $q['position'] }}</em></span>
                @endforeach
            </div>
        </div>
    @endif

    {{-- Internal links both ways --}}
    <div class="rc-links">
        <div class="rc-linkcol">
            <div class="rc-k">Links on this page ({{ count($post['links']['outbound']) }})</div>
            @forelse (array_slice($post['links']['outbound'], 0, 8) as $l)
                <a class="rc-link" @if ($l['url']) href="{{ $l['url'] }}" target="_blank" rel="noopener" @endif>{{ $l['title'] }}</a>
            @empty
                <span class="rc-muted">None</span>
            @endforelse
        </div>
        <div class="rc-linkcol">
            <div class="rc-k">Linked from ({{ count($post['links']['inbound']) }})</div>
            @forelse (array_slice($post['links']['inbound'], 0, 8) as $l)
                <a class="rc-link" @if ($l['url']) href="{{ $l['url'] }}" target="_blank" rel="noopener" @endif>{{ $l['title'] }}</a>
            @empty
                <span class="rc-muted">Nothing links here yet</span>
            @endforelse
        </div>
    </div>

    {{-- Actions --}}
    <div class="rc-actions">
        @if (! empty($post['url']))
            <a class="rc-btn" href="{{ $post['url'] }}" target="_blank" rel="noopener">View ↗</a>
        @endif
        @if ($this->can(\App\Security\Capability::PublishContent))
            <button class="rc-btn" wire:click="repush('{{ $post['id'] }}')"
                    wire:loading.attr="disabled" wire:target="repush('{{ $post['id'] }}')">Re-sync</button>
        @endif
    </div>
    @include('filament.console.partials.swap-photo', ['id' => $post['id']])
</div>

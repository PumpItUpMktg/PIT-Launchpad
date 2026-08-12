<x-filament-panels::page>
    @include('filament.console.partials.blog-filters')
    @include('filament.console.partials.published-styles')

    @php $filtered = ($county ?? null) || ($siloId ?? null); @endphp

    @if (count($this->board['blog']) > 0)
        <div class="rc-cards">
            @foreach ($this->board['blog'] as $post)
                @include('filament.console.partials.post-card', ['post' => $post])
            @endforeach
        </div>
    @else
        <div class="rc-empty">No live blog posts{{ $filtered ? ' for this filter' : '' }} yet.</div>
    @endif
</x-filament-panels::page>

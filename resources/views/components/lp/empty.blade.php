@props(['title' => null, 'action' => null, 'href' => null])
{{-- The standard empty state: never a dead end. A short title, an optional line of guidance (the
     slot), and — the point — a named next action linking somewhere. Pass `title`, put guidance in the
     slot, and give `action` + `href` for the call-to-action. --}}
<div {{ $attributes->merge(['class' => 'lp-empty']) }}>
    @if ($title)
        <div class="lp-empty-title">{{ $title }}</div>
    @endif
    @if (trim($slot) !== '')
        <div class="lp-empty-body">{{ $slot }}</div>
    @endif
    @if ($action && $href)
        <div class="lp-empty-cta"><a class="lp-btn" href="{{ $href }}" wire:navigate>{{ $action }}</a></div>
    @endif
</div>

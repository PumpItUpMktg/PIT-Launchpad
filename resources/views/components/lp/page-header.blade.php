@props(['eyebrow' => null, 'title', 'lede' => null, 'scope' => true])
{{-- The standard admin page header: eyebrow + title (+ optional lede) on the left, the site-scope
     indicator and a SINGLE primary action on the right. The scope indicator reads the SAME session
     tenant as the topbar switcher (App\Operator\ActiveTenant), so every page states which tenant it is
     acting on. Pass `:scope="false"` on portfolio-wide pages that aren't tenant-scoped; the primary
     action goes in the `action` slot (keep it to one). --}}
@php
    $__banner = $scope ? app(\App\Operator\ActiveTenant::class)->banner() : ['has' => false];
@endphp
<div {{ $attributes->merge(['class' => 'lp-row lp-pagehead']) }}>
    <div class="lp-pagehead-titles">
        @if ($eyebrow)
            <div class="lp-eyebrow">{{ $eyebrow }}</div>
        @endif
        <div class="lp-h1">{{ $title }}</div>
        @isset($meta)
            <div class="lp-pagehead-meta">{{ $meta }}</div>
        @endisset
        @if ($lede)
            <div class="lp-lede" style="margin-bottom:0">{{ $lede }}</div>
        @endif
    </div>
    <div class="lp-pagehead-aside">
        @if (($__banner['has'] ?? false))
            <span class="lp-scope" title="Working tenant">
                @if ($__banner['logo_url'])
                    <img class="lp-scope-logo" src="{{ $__banner['logo_url'] }}" alt="">
                @else
                    <span class="lp-scope-dot"></span>
                @endif
                <span class="lp-scope-name">{{ $__banner['name'] }}</span>
            </span>
        @endif
        @isset($action)
            <span class="lp-pagehead-action">{{ $action }}</span>
        @endisset
    </div>
</div>

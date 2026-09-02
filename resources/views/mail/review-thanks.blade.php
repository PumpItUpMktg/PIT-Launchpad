@component('mail::message')
# Thank you!

We really appreciate you taking a moment to tell us about your experience with {{ $brand }}.

@if ($googleUrl)
If you have a minute, a public review on Google helps other neighbors find us too:

@component('mail::button', ['url' => $googleUrl])
Review us on Google
@endcomponent
@endif

Thanks again,<br>
{{ $brand }}
@endcomponent

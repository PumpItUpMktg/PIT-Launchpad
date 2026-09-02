@component('mail::message')
# Thanks for choosing {{ $brand }}

We'd love a quick word about the work we did for you — it only takes a minute and helps other neighbors find us.

@component('mail::button', ['url' => $url])
Leave a review
@endcomponent

Thank you,<br>
{{ $brand }}
@endcomponent

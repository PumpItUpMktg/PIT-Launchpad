@component('mail::message')
# You have access to {{ $brand }}

@if ($name !== '')
Hi {{ $name }},
@endif

Your marketing team has given you **{{ $role }}** access to {{ $brand }}. {{ $powers }}

Set your password with the link below, then sign in with this email address.

@component('mail::button', ['url' => $url])
Set your password
@endcomponent

This link works for 24 hours. If it has expired, open {{ $loginUrl }} and use “Forgot password” — a fresh link will be emailed to you.

Thank you,<br>
The {{ $brand }} web team
@endcomponent

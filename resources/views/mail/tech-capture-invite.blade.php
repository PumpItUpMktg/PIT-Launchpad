<x-mail::message>
# You're set up for job capture

Hi {{ $techName }} — you can now log jobs from your phone in a couple of taps.

<x-mail::panel>
**1.** Open this link on your phone:<br>
{{ $link }}

**2.** Tap **Add to Home Screen** so it's one tap next time.

**3.** Enter this code when it asks:
</x-mail::panel>

<x-mail::panel>
# {{ $code }}
</x-mail::panel>

The code is one-time and expires soon. If it stops working, ask your admin to resend it.

Thanks,<br>
{{ $brand }}
</x-mail::message>

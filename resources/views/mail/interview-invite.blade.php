@component('mail::message')
# A few questions about {{ $brand }}

Your marketing team is building {{ $brand }}'s new website, and there are a few things only you know — your license and insurance, what you do, where you go, and how you talk about your work.

The link below opens a short, private interview. Answer in your own words, as much or as little as you like. You can close it and come back any time; every answer is saved as you go.

@component('mail::button', ['url' => $url])
Start the interview
@endcomponent

Nothing you write goes live on its own — your team reviews every answer first. This link is private to you and stays open for {{ $days }} {{ $days === 1 ? 'day' : 'days' }}.

Thank you,<br>
The {{ $brand }} web team
@endcomponent

@component('mail::message')
# {{ $brand }} finished the onboarding interview

The owner completed the client interview. Their answers are on the Interview step — review the transcript and run **Extract** to seed trust facts, services, coverage, market notes, and a draft voice profile. Nothing activates until you do.

@component('mail::button', ['url' => $url])
Open the Interview step
@endcomponent
@endcomponent

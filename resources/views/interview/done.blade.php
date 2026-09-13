<!doctype html>
<html lang="en">
<head>
    <title>Thank you · {{ $brand['name'] }}</title>
    @include('interview._head', ['brand' => $brand])
</head>
<body>
<div class="wrap">
    <div class="brandbar">
        @if ($brand['logo_url'])<img src="{{ $brand['logo_url'] }}" alt="{{ $brand['name'] }}">@endif
        <span class="name">{{ $brand['name'] }}</span>
    </div>
    <div class="card">
        <h1>Thank you — that's everything we need.</h1>
        <p class="sub">Your answers are saved and your marketing team has them. They'll review everything before anything appears on {{ $brand['name'] }}'s website, and they'll reach out if a detail needs a second look.</p>
        <p class="muted">This interview is complete. If something changes, tell your team and they can reopen it.</p>
    </div>
</div>
</body>
</html>

<!doctype html>
<html lang="en">
<head>
    <title>Your business, in your words · {{ $brand['name'] }}</title>
    @include('interview._head', ['brand' => $brand])
</head>
<body>
<div class="wrap">
    <div class="brandbar">
        @if ($brand['logo_url'])<img src="{{ $brand['logo_url'] }}" alt="{{ $brand['name'] }}">@endif
        <span class="name">{{ $brand['name'] }}</span>
    </div>
    <div class="card">
        <h1>Tell us about {{ $brand['name'] }}</h1>
        <p class="sub">Your marketing team is building {{ $brand['name'] }}'s new website and asked us to gather a few details only you know — your license and insurance, what you do, where you go, and how you talk about your work. Nothing you write here goes live on its own: your team reviews every answer first.</p>
        <div class="secure" title="This is a private link that only opens your interview. Answers travel over an encrypted connection.">&#128274; Private link · encrypted connection · only your team sees this</div>

        @if ($returning)
            <div class="resume"><strong>Welcome back.</strong> Your {{ $answered }} {{ $answered === 1 ? 'answer is' : 'answers are' }} saved — we'll pick up right where you left off.</div>
        @endif

        <div class="progress" aria-label="What we've covered so far">
            @foreach ($progress as $row)
                <div class="{{ $row['state'] }}">{{ $row['label'] }}<span>{{ $row['state'] === 'filled' ? 'covered' : ($row['state'] === 'thin' ? 'a little more' : 'not yet') }}</span></div>
            @endforeach
        </div>

        <div class="chat">
            @foreach ($turns as $turn)
                <div class="msg {{ $turn->role }} {{ $question !== null && $turn->id === $question->id ? 'current' : '' }}">{{ $turn->content }}</div>
            @endforeach
        </div>

        @if ($awaiting)
            <div class="retry">
                Your last answer is saved. We couldn't load the next question just now — tap below to try again.
                <form method="POST" action="{{ route('interview.retry', ['token' => $token]) }}" style="margin-top:8px">@csrf<button class="btn secondary" type="submit">Get the next question</button></form>
            </div>
        @else
            <form method="POST" action="{{ route('interview.answer', ['token' => $token]) }}">
                @csrf
                <label for="answer">Your answer</label>
                <textarea id="answer" name="answer" placeholder="Type as much or as little as you like — plain language is perfect." autofocus>{{ old('answer') }}</textarea>
                @error('answer')<div class="err">{{ $message }}</div>@enderror
                <div class="row">
                    <button class="btn" type="submit">Send</button>
                    <span class="muted">Every answer is saved as you go. Close this page any time and come back with the same link.</span>
                </div>
            </form>
        @endif

        <div class="foot">
            <div class="row" style="justify-content:space-between">
                <span>Done for now? Just close the page — your progress is kept. Finished for good?</span>
                <form method="POST" action="{{ route('interview.finish', ['token' => $token]) }}" onsubmit="return confirm('Finish the interview? Anything not covered, your team will follow up on.');">@csrf<button class="btn secondary" type="submit">Finish interview</button></form>
            </div>
        </div>
    </div>
</div>
</body>
</html>

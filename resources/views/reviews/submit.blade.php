<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Leave a review · {{ $brand }}</title>
    <style>
        body { font-family: system-ui, sans-serif; margin: 0; background: #f4f5f7; color: #1f2933; }
        .wrap { max-width: 560px; margin: 0 auto; padding: 32px 20px; }
        .card { background: #fff; border-radius: 14px; padding: 24px; box-shadow: 0 1px 3px rgba(0,0,0,.08); }
        h1 { font-size: 20px; margin: 0 0 6px; }
        p.sub { color: #647380; margin: 0 0 20px; font-size: 14px; }
        label { display: block; font-size: 13px; font-weight: 600; margin: 16px 0 6px; }
        textarea, input[type=email], input[type=tel] { width: 100%; box-sizing: border-box; border: 1px solid #cbd2d9; border-radius: 8px; padding: 10px 12px; font-size: 15px; }
        textarea { min-height: 120px; resize: vertical; }
        .stars { display: flex; gap: 6px; flex-direction: row-reverse; justify-content: flex-end; }
        .stars input { display: none; }
        .stars label { font-size: 34px; color: #d2d6dc; cursor: pointer; margin: 0; }
        .stars input:checked ~ label, .stars label:hover, .stars label:hover ~ label { color: #f5b301; }
        button { margin-top: 22px; width: 100%; background: #2563eb; color: #fff; border: 0; border-radius: 8px; padding: 13px; font-size: 15px; font-weight: 600; cursor: pointer; }
        .err { color: #c81e1e; font-size: 13px; margin-top: 4px; }
        .muted { color: #8695a3; font-size: 12px; }
    </style>
</head>
<body>
    <div class="wrap">
        <div class="card">
            <h1>How did we do?</h1>
            <p class="sub">Your feedback for {{ $brand }} takes less than a minute.</p>

            <form method="POST" action="{{ route('reviews.submit', ['token' => $token]) }}">
                @csrf

                <label>Your rating</label>
                <div class="stars">
                    @foreach ([5,4,3,2,1] as $n)
                        <input type="radio" id="star{{ $n }}" name="rating" value="{{ $n }}" @checked(old('rating') == $n)>
                        <label for="star{{ $n }}" title="{{ $n }} star{{ $n === 1 ? '' : 's' }}">★</label>
                    @endforeach
                </div>
                @error('rating')<div class="err">{{ $message }}</div>@enderror

                <label for="body">Tell us about your experience</label>
                <textarea id="body" name="body" placeholder="What went well?">{{ old('body') }}</textarea>
                @error('body')<div class="err">{{ $message }}</div>@enderror

                <label for="customer_email">Email <span class="muted">(optional — only if we should update it)</span></label>
                <input type="email" id="customer_email" name="customer_email" value="{{ old('customer_email') }}">
                @error('customer_email')<div class="err">{{ $message }}</div>@enderror

                <label for="customer_phone">Phone <span class="muted">(optional)</span></label>
                <input type="tel" id="customer_phone" name="customer_phone" value="{{ old('customer_phone') }}">

                <button type="submit">Submit review</button>
            </form>
        </div>
    </div>
</body>
</html>

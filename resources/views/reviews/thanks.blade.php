<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Thank you · {{ $brand }}</title>
    <style>
        body { font-family: system-ui, sans-serif; margin: 0; background: #f4f5f7; color: #1f2933; }
        .wrap { max-width: 560px; margin: 0 auto; padding: 48px 20px; text-align: center; }
        .card { background: #fff; border-radius: 14px; padding: 32px 24px; box-shadow: 0 1px 3px rgba(0,0,0,.08); }
        h1 { font-size: 22px; margin: 0 0 8px; }
        p { color: #647380; font-size: 15px; }
    </style>
</head>
<body>
    <div class="wrap">
        <div class="card">
            <h1>Thank you! 🙏</h1>
            <p>We appreciate you taking the time to tell us about your experience with {{ $brand }}.</p>
            {{-- The Google review CTA is added on this screen in PR 4/7. --}}
        </div>
    </div>
</body>
</html>

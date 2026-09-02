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
        .cta { display: inline-block; margin-top: 18px; background: #2563eb; color: #fff; text-decoration: none; border-radius: 8px; padding: 12px 22px; font-size: 15px; font-weight: 600; }
    </style>
</head>
<body>
    <div class="wrap">
        <div class="card">
            <h1>Thank you! 🙏</h1>
            <p>We appreciate you taking the time to tell us about your experience with {{ $brand }}.</p>
            @if (! empty($googleUrl))
                <p>Would you share it on Google too? It helps other neighbors find us.</p>
                <a class="cta" href="{{ $googleUrl }}" target="_blank" rel="noopener">Review us on Google</a>
            @endif
        </div>
    </div>
</body>
</html>

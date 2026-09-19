<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ $heading }} — {{ config('app.name') }}</title>
    <style>
        * { box-sizing: border-box; }
        body {
            font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
            margin: 0; min-height: 100vh; padding: 1.5rem;
            display: flex; align-items: center; justify-content: center;
            background: #f9f9f8; color: #191c1c;
        }
        .card {
            text-align: center; width: 100%; max-width: 24rem;
            padding: 2.5rem 2rem; background: #fff; border-radius: 1rem;
            box-shadow: 0 10px 30px -10px rgba(0, 59, 57, 0.15);
        }
        .badge {
            width: 64px; height: 64px; border-radius: 9999px;
            display: flex; align-items: center; justify-content: center;
            margin: 0 auto 1.25rem;
            background: {{ $accentBg }}; color: {{ $accentFg }};
        }
        .badge svg { width: 32px; height: 32px; }
        h1 { font-size: 1.375rem; font-weight: 700; margin: 0 0 0.5rem; color: #002422; }
        p { margin: 0 0 1.75rem; font-size: 0.9375rem; line-height: 1.55; color: #64748b; }
        .btn {
            display: inline-block; background: #002422; color: #fff; text-decoration: none;
            padding: 0.75rem 1.75rem; border-radius: 9999px; font-size: 0.9375rem; font-weight: 600;
        }
        .hint { margin: 1rem 0 0; font-size: 0.8125rem; color: #94a3b8; }
    </style>
</head>
<body>
    <div class="card">
        <div class="badge">{!! $icon !!}</div>
        <h1>{{ $heading }}</h1>
        <p>{{ $message }}</p>
        <a class="btn" href="{{ $appLink ?? 'vtrata://billing' }}">Return to {{ config('app.name') }}</a>
        <p class="hint">You can close this window anytime.</p>
    </div>
</body>
</html>

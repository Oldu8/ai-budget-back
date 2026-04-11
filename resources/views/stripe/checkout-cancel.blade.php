<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Checkout canceled — {{ config('app.name') }}</title>
    <style>
        body {
            font-family: system-ui, -apple-system, Segoe UI, Roboto, sans-serif;
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #f8fafc;
            color: #0f172a;
        }
        .card {
            text-align: center;
            padding: 2rem 2.25rem;
            max-width: 24rem;
            background: #fff;
            border-radius: 0.5rem;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.08);
        }
        h1 {
            font-size: 1.25rem;
            font-weight: 600;
            margin: 0 0 0.75rem;
        }
        p {
            margin: 0;
            font-size: 0.9375rem;
            line-height: 1.5;
            color: #64748b;
        }
    </style>
</head>
<body>
    <div class="card">
        <h1>Checkout canceled</h1>
        <p>No charge was made. You can close this window and try again when you are ready.</p>
    </div>
</body>
</html>

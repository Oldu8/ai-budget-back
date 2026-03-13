<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name') }}</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600" rel="stylesheet" />
    <style>
        body {
            font-family: 'Instrument Sans', system-ui, sans-serif;
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #fdfdfc;
            color: #1b1b18;
        }

        .container {
            text-align: center;
            padding: 2rem;
            max-width: 32rem;
        }

        h1 {
            font-size: 2rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
        }

        p {
            color: #706f6c;
            margin-bottom: 2rem;
        }

        .links {
            display: flex;
            gap: 1rem;
            justify-content: center;
            flex-wrap: wrap;
        }

        .links a {
            color: #1b1b18;
            text-decoration: none;
            padding: 0.5rem 1rem;
            border: 1px solid #e3e3e0;
            border-radius: 0.25rem;
            font-size: 0.875rem;
        }

        .links a:hover {
            border-color: #1b1b18;
            background: #f5f5f4;
        }
    </style>
</head>

<body>
    <div class="container">
        <h1>Hello AI Budget</h1>
        <p>Smart budget tracking powered by AI. Mobile apps coming soon.</p>
        <div class="links">
            <a href="{{ url('/admin') }}">Admin Panel</a>
            {{-- Add App Store / Play Store links when ready:
            <a href="#">App Store</a>
            <a href="#">Google Play</a>
            --}}
        </div>
    </div>
</body>

</html>
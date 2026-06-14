<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Admin login – {{ config('app.name') }}</title>
    {{-- Admin uses the Tailwind Play CDN: internal tooling (no SEO/perf concern), so no asset rebuild is needed when adding views. --}}
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100 min-h-screen flex items-center justify-center p-4">
    <div class="w-full max-w-sm bg-white rounded-lg shadow p-6">
        <h1 class="text-xl font-semibold mb-4">Admin login</h1>
        @if ($errors->any())
            <div class="mb-4 p-3 bg-red-50 text-red-700 rounded text-sm">
                {{ $errors->first() }}
            </div>
        @endif
        <form method="POST" action="{{ url('/admin/login') }}">
            @csrf
            <div class="mb-4">
                <label for="email" class="block text-sm font-medium text-gray-700 mb-1">Email</label>
                <input type="email" name="email" id="email" value="{{ old('email') }}" required autofocus
                    class="w-full border border-gray-300 rounded px-3 py-2 text-sm">
            </div>
            <div class="mb-4">
                <label for="password" class="block text-sm font-medium text-gray-700 mb-1">Password</label>
                <input type="password" name="password" id="password" required
                    class="w-full border border-gray-300 rounded px-3 py-2 text-sm">
            </div>
            <button type="submit" class="w-full bg-gray-800 text-white rounded px-3 py-2 text-sm font-medium hover:bg-gray-700">
                Log in
            </button>
        </form>
    </div>
</body>
</html>

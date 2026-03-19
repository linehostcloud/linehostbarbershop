<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>@yield('title', config('app.name'))</title>
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600,700" rel="stylesheet" />
        @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
            @vite(['resources/css/app.css', 'resources/js/app.js'])
        @endif
    </head>
    <body class="min-h-screen bg-stone-100 text-slate-900 antialiased">
        <div class="absolute inset-0 -z-10 bg-[radial-gradient(circle_at_top_left,_rgba(245,158,11,0.14),_transparent_26%),radial-gradient(circle_at_top_right,_rgba(15,23,42,0.06),_transparent_24%),linear-gradient(180deg,_rgba(255,255,255,0.9),_rgba(245,245,244,0.94))]"></div>
        <div class="absolute inset-0 -z-10 bg-[linear-gradient(rgba(148,163,184,0.12)_1px,transparent_1px),linear-gradient(90deg,rgba(148,163,184,0.12)_1px,transparent_1px)] bg-[size:22px_22px] [mask-image:linear-gradient(180deg,white,rgba(255,255,255,0.18))]"></div>
        <div class="mx-auto min-h-screen max-w-7xl px-4 py-5 sm:px-6 lg:px-8">
            @yield('content')
        </div>
    </body>
</html>

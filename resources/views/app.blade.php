<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#07111f">
    <link rel="manifest" href="/manifest.webmanifest">
    <title inertia>{{ config('app.name', 'Triathlon Timing') }}</title>
    <script>
        (() => {
            let saved;
            try { saved = localStorage.getItem('triathlon-theme'); } catch (_) {}
            const theme = saved === 'light' || saved === 'dark' ? saved : (matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
            document.documentElement.dataset.theme = theme;
            document.querySelector('meta[name="theme-color"]').content = theme === 'dark' ? '#07111f' : '#f8fafc';
        })();
    </script>
    @vite(['resources/css/app.css', 'resources/js/app.ts'])
    @inertiaHead
</head>
<body class="bg-canvas text-foreground antialiased">
    @inertia
</body>
</html>

<!DOCTYPE html>
<html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title>{{ $title ?? config('app.name', 'Agung Farm & Garden') }}</title>

        <link rel="preload" href="{{ asset('fonts/outfit/outfit-1.ttf') }}" as="font" type="font/ttf" crossorigin>
        @vite(['resources/css/public.css', 'resources/js/app.js'])
        @livewireStyles
    </head>
    <body class="public-site auth-site">
        <a href="#main-content" class="skip-link">Skip to content</a>
        <main id="main-content" class="auth-main" tabindex="-1">
            <a href="{{ route('home') }}" class="auth-brand">
                <img src="{{ asset('images/fancy/agung-farm-logo.webp') }}" alt="Agung Farm & Garden" width="150" height="74">
            </a>
            {!! $slot !!}
        </main>

        @livewireScripts
    </body>
</html>

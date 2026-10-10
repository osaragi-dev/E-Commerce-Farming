<!DOCTYPE html>
<html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title>{{ $title ?? config('app.name', 'Agung Farm & Garden') }}</title>
        <meta name="description" content="{{ $description ?? 'Agung Farm & Garden — fresh livestock, agriculture, and fisheries products, straight from farm to table.' }}">

        <link rel="preload" href="{{ asset('fonts/outfit/outfit-1.ttf') }}" as="font" type="font/ttf" crossorigin>
        @vite(['resources/css/public.css', 'resources/js/app.js'])
        @livewireStyles
    </head>
    <body class="public-site min-h-screen flex flex-col">
        <a href="#main-content" class="skip-link">Skip to content</a>
        <x-public.header />

        <main id="main-content" class="flex-1 min-w-0" tabindex="-1">
            {{ $slot }}
        </main>

        <x-public.footer />

        <livewire:public.quick-add-modal />

        <x-public.bottom-nav />

        <div
            x-data="{ show: false, message: '', timeout: null, destroy() { clearTimeout(this.timeout) } }"
            x-on:notify.window="message = $event.detail.message; show = true; clearTimeout(timeout); timeout = setTimeout(() => show = false, 3000)"
            x-show="show"
            x-transition
            x-cloak
            class="toast-notification"
            role="status"
            aria-live="polite"
            aria-atomic="true"
        >
            <span x-text="message"></span>
        </div>

        <div
            x-data="{
                show: false,
                message: '',
                timeout: null,
                showNotification(message) {
                    this.message = message;
                    this.show = true;
                    clearTimeout(this.timeout);
                    this.timeout = setTimeout(() => this.show = false, 6000);
                },
                dismiss() {
                    this.show = false;
                    clearTimeout(this.timeout);
                },
                destroy() {
                    clearTimeout(this.timeout);
                },
            }"
            x-on:profile-updated.window="showNotification($event.detail.message)"
            x-show="show"
            x-transition
            x-cloak
            class="profile-toast-notification"
            role="alert"
            aria-live="polite"
            aria-atomic="true"
        >
            <span class="profile-toast-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="m5 12.5 4.5 4.5L19 7.5" />
                </svg>
            </span>

            <span class="profile-toast-message" x-text="message"></span>

            <button
                type="button"
                class="profile-toast-dismiss"
                x-on:click="dismiss()"
                aria-label="Dismiss notification"
            >
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true">
                    <path d="m6 6 12 12M18 6 6 18" />
                </svg>
            </button>
        </div>

        @livewireScripts
    </body>
</html>

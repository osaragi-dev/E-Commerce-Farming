@php
    use Illuminate\Support\Facades\Route;

    $loginUrl = Route::has('login') ? route('login') : '#';
    $registerUrl = Route::has('register') ? route('register') : '#';
    $aboutUrl = Route::has('company-profile.show') ? route('company-profile.show') : '#';
    $authUser = auth('customer')->user();
@endphp

<header class="site-header" x-data="{ menuOpen: false, searchOpen: false }" x-on:keydown.escape="if (menuOpen) $refs.menuToggle.focus(); else if (searchOpen) $refs.searchToggle.focus(); menuOpen = false; searchOpen = false" x-on:click.outside="menuOpen = false; searchOpen = false">
    <div class="wrap header-inner">
        <a href="{{ route('home') }}" class="site-brand">
            <img src="{{ asset('images/fancy/agung-farm-logo.webp') }}" alt="Agung Farm & Garden" class="site-logo-img" width="128" height="60">
        </a>

        <nav class="main-nav hidden lg:flex items-center gap-5" aria-label="Main navigation">
            <a href="{{ route('home') }}" class="{{ request()->routeIs('home') ? 'is-active' : '' }}">Homepage</a>
            <a href="{{ route('catalog') }}" class="{{ request()->routeIs('catalog') ? 'is-active' : '' }}">Products</a>
            <a href="{{ $aboutUrl }}" class="{{ request()->routeIs('company-profile.show') ? 'is-active' : '' }}">About Us</a>
        </nav>

        <div id="header-search" class="header-search" :class="{ 'is-open': searchOpen }" x-ref="headerSearch">
            <livewire:public.search-bar />
        </div>

        <div class="header-actions">
            <button type="button" class="header-mobile-button" x-ref="searchToggle" x-on:click="searchOpen = !searchOpen; menuOpen = false; if (searchOpen) $nextTick(() => $refs.headerSearch.querySelector('input')?.focus())" :aria-expanded="searchOpen" aria-controls="header-search" aria-label="Search products">
                <x-heroicon-o-magnifying-glass class="w-5 h-5" aria-hidden="true" />
            </button>
            <livewire:public.cart-icon />

            @if ($authUser)
                <livewire:public.notifications-bell />
            @endif

            <div class="hidden lg:flex items-center gap-3">
                @if ($authUser)
                    <details class="profile-menu" x-data x-on:click.outside="$el.removeAttribute('open')" x-on:keydown.escape="$el.removeAttribute('open'); $el.querySelector('summary').focus()">
                        <summary class="profile-chip">
                            @if ($authUser->profile_photo_path)
                                <img src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($authUser->profile_photo_path) }}" alt="{{ $authUser->name }}" class="profile-chip-avatar">
                            @else
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="w-4 h-4">
                                    <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>
                                    <circle cx="12" cy="7" r="4"></circle>
                                </svg>
                            @endif
                            Hi, {{ $authUser->name }}
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="w-4 h-4 profile-chip-chevron">
                                <path d="m6 9 6 6 6-6"></path>
                            </svg>
                        </summary>

                        <div class="profile-menu-dropdown">
                            <p class="profile-menu-name">{{ $authUser->name }}</p>
                            <a href="{{ route('account') }}" class="profile-menu-item">My Account</a>
                            <a href="{{ route('orders.history') }}" class="profile-menu-item">My Orders</a>
                            <form method="POST" action="{{ route('logout') }}">
                                @csrf
                                <button type="submit" class="profile-menu-item profile-menu-logout">Log Out</button>
                            </form>
                        </div>
                    </details>
                @else
                    <a href="{{ $loginUrl }}" class="btn btn-ghost">Log In</a>
                    <a href="{{ $registerUrl }}" class="btn btn-primary">Sign Up</a>
                @endif
            </div>

            <button type="button" class="header-mobile-button" x-ref="menuToggle" x-on:click="menuOpen = !menuOpen; searchOpen = false" :aria-expanded="menuOpen" aria-controls="mobile-menu" :aria-label="menuOpen ? 'Close menu' : 'Open menu'">
                <x-heroicon-o-bars-3 x-show="!menuOpen" class="w-6 h-6" aria-hidden="true" />
                <x-heroicon-o-x-mark x-show="menuOpen" x-cloak class="w-6 h-6" aria-hidden="true" />
            </button>
        </div>
    </div>

    <nav id="mobile-menu" class="header-mobile-nav wrap" aria-label="Mobile navigation" x-show="menuOpen" x-cloak>
        <a href="{{ route('home') }}">Homepage</a>
        <a href="{{ route('catalog') }}">Products</a>
        <a href="{{ $aboutUrl }}">About Us</a>
        <a href="{{ route('help') }}">Help &amp; FAQ</a>
        @if ($authUser)
            <a href="{{ route('account') }}">My Account</a>
            <a href="{{ route('orders.history') }}">My Orders</a>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit">Log Out</button>
            </form>
        @else
            <div class="header-mobile-auth">
                <a href="{{ $loginUrl }}" class="btn btn-ghost">Log In</a>
                <a href="{{ $registerUrl }}" class="btn btn-primary">Sign Up</a>
            </div>
        @endif
    </nav>
</header>

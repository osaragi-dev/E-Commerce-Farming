@php
    $authUser = auth('customer')->user();
@endphp

<nav class="bottom-nav" aria-label="Quick navigation">
    <a href="{{ route('home') }}" class="bottom-nav-item {{ request()->routeIs('home') ? 'is-active' : '' }}">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path>
            <path d="M9 22V12h6v10"></path>
        </svg>
        Home
    </a>

    <a href="{{ route('catalog') }}" class="bottom-nav-item {{ request()->routeIs('catalog') ? 'is-active' : '' }}">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M6 2l1.5 6M18 2l-1.5 6"></path>
            <path d="M3.5 8h17l-1.6 11.2a2 2 0 0 1-2 1.8H7.1a2 2 0 0 1-2-1.8z"></path>
            <path d="M9 12a3 3 0 0 0 6 0"></path>
        </svg>
        Products
    </a>

    @if ($authUser)
        <a href="{{ route('orders.history') }}" class="bottom-nav-item {{ request()->routeIs('orders.history') ? 'is-active' : '' }}">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"></path>
                <path d="M3.27 6.96 12 12l8.73-5.04M12 22.08V12"></path>
            </svg>
            Orders
        </a>

        <a href="{{ route('account') }}" class="bottom-nav-item {{ request()->routeIs('account') ? 'is-active' : '' }}">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>
                <circle cx="12" cy="7" r="4"></circle>
            </svg>
            Account
        </a>
    @else
        <a href="{{ route('login') }}" class="bottom-nav-item {{ request()->routeIs('login') ? 'is-active' : '' }}">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"></path>
                <path d="M10 17l5-5-5-5"></path>
                <path d="M15 12H3"></path>
            </svg>
            Log In
        </a>
    @endif
</nav>

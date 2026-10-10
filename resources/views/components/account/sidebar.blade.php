<nav class="account-sidebar" aria-label="Your account">
    <a href="{{ route('account') }}" class="account-sidebar-link {{ request()->routeIs('account') ? 'is-active' : '' }}">
        My Account
    </a>
    <a href="{{ route('orders.history') }}" class="account-sidebar-link {{ request()->routeIs('orders.history') ? 'is-active' : '' }}">
        My Orders
    </a>
</nav>

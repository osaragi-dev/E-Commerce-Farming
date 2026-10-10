<div>
    @if ($mode === 'text')
        <a href="{{ route('cart') }}" class="btn btn-ghost w-full">
            Cart @if ($count > 0) ({{ $count }}) @endif
        </a>
    @else
        <a href="{{ route('cart') }}" class="cart-icon-link" aria-label="Shopping cart">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="w-5 h-5">
                <circle cx="9" cy="21" r="1"></circle>
                <circle cx="20" cy="21" r="1"></circle>
                <path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"></path>
            </svg>
            @if ($count > 0)
                <span class="cart-icon-badge">{{ $count }}</span>
            @endif
        </a>
    @endif
</div>

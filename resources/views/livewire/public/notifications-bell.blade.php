<div class="relative" wire:poll.30s x-data x-on:keydown.escape.window="$wire.open && $wire.set('open', false)" x-on:click.outside="$wire.open && $wire.set('open', false)">
    <button
        type="button"
        wire:click="toggle"
        class="notification-trigger"
        aria-label="Notifications{{ $unreadCount > 0 ? ', '.$unreadCount.' unread' : '' }}"
        aria-expanded="{{ $open ? 'true' : 'false' }}"
        aria-controls="customer-notifications"
    >
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-5 w-5" aria-hidden="true">
            <path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9"></path>
            <path d="M10 21h4"></path>
        </svg>
        @if ($unreadCount > 0)
            <span class="notification-count" aria-hidden="true">{{ $unreadCount > 9 ? '9+' : $unreadCount }}</span>
        @endif
    </button>

    @if ($open)
        <section id="customer-notifications" class="notification-panel" aria-labelledby="customer-notifications-heading">
            <div class="notification-heading">
                <h2 id="customer-notifications-heading">Notifications</h2>
                <div class="flex items-center gap-3">
                    @if ($unreadCount > 0)
                        <button type="button" wire:click="markAllAsRead" class="text-link inline-flex items-center gap-1 text-xs" wire:loading.attr="disabled">
                            <x-heroicon-o-check-circle class="h-4 w-4 shrink-0" aria-hidden="true" />
                            Mark all read
                        </button>
                    @endif
                    @if ($notifications->isNotEmpty())
                        <button type="button" wire:click="clearAll" class="text-link inline-flex items-center gap-1 text-xs" wire:loading.attr="disabled">
                            <x-heroicon-o-trash class="h-4 w-4 shrink-0" aria-hidden="true" />
                            Clear all
                        </button>
                    @endif
                </div>
            </div>

            <div class="notification-list">
                @forelse ($notifications as $notification)
                    @php
                        $orderNumber = $notification->data['order_number'] ?? null;
                        $orderUrl = $orderNumber ? route('orders.show', ['order' => $orderNumber]) : route('orders.history');
                    @endphp
                    <a
                        href="{{ $orderUrl }}"
                        wire:click="markAsRead('{{ $notification->id }}')"
                        wire:key="customer-notification-{{ $notification->id }}"
                        class="notification-item {{ $notification->read_at ? '' : 'is-unread' }}"
                    >
                        <p class="text-sm font-medium">{{ $notification->data['title'] ?? 'Notification' }}</p>
                        <p class="mt-1 text-xs text-[var(--ink-soft)]">{{ $notification->data['body'] ?? '' }}</p>
                        <p class="mt-2 text-xs text-[var(--ink-soft)]">{{ $notification->created_at?->diffForHumans() }}</p>
                        @if (! $notification->read_at)
                            <span class="sr-only">Unread</span>
                        @endif
                    </a>
                @empty
                    <p class="px-4 py-8 text-center text-sm text-[var(--ink-soft)]">No notifications yet.</p>
                @endforelse
                @if ($hasMore)
                    <button type="button" wire:click="loadMore" class="text-link inline-flex w-full items-center justify-center gap-2 px-4 py-3 text-sm" wire:loading.attr="disabled" wire:target="loadMore">
                        <x-heroicon-o-chevron-down class="h-4 w-4 shrink-0" aria-hidden="true" />
                        Show more notifications
                    </button>
                @endif
            </div>
        </section>
    @endif
</div>

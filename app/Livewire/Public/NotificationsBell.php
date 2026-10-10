<?php

namespace App\Livewire\Public;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

class NotificationsBell extends Component
{
    public bool $open = false;

    #[Locked]
    public int $visibleCount = 8;

    public function loadMore(): void
    {
        $this->visibleCount += 8;
    }

    public function toggle(): void
    {
        $this->open = ! $this->open;
    }

    public function markAsRead(string $notificationId): void
    {
        auth('customer')->user()?->notifications()->whereKey($notificationId)->update(['read_at' => now()]);
    }

    public function markAllAsRead(): void
    {
        auth('customer')->user()?->unreadNotifications()->update(['read_at' => now()]);
    }

    public function render(): View
    {
        $user = auth('customer')->user();
        $notifications = $user?->notifications()->latest()->orderByDesc('id')->limit($this->visibleCount + 1)->get() ?? collect();

        return view('livewire.public.notifications-bell', [
            'unreadCount' => $user?->unreadNotifications()->count() ?? 0,
            'notifications' => $notifications->take($this->visibleCount),
            'hasMore' => $notifications->count() > $this->visibleCount,
        ]);
    }

    public function clearAll(): void
    {
        auth('customer')->user()?->notifications()->delete();
        $this->visibleCount = 8;
    }
}

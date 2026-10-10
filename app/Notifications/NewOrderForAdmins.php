<?php

namespace App\Notifications;

use App\Filament\Resources\Orders\OrderResource;
use App\Models\Order;
use App\Models\User;
use Filament\Actions\Action;
use Illuminate\Notifications\Notification;

class NewOrderForAdmins extends Notification
{
    public function __construct(public Order $order) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toDatabase(object $notifiable): array
    {
        return [
            'format' => 'filament',
            'title' => 'New order received',
            'body' => "Order {$this->order->order_number} has been placed.",
            'icon' => 'heroicon-o-shopping-bag',
            'color' => 'info',
            'duration' => 'persistent',
            'order_number' => $this->order->order_number,
            'actions' => [
                Action::make('viewOrder')
                    ->label('View order')
                    ->url(OrderResource::getUrl('index'))
                    ->markAsRead()
                    ->toArray(),
            ],
        ];
    }

    public static function send(Order $order): void
    {
        User::query()
            ->where('is_active', true)
            ->whereDoesntHave('roles', fn ($query) => $query->where('name', 'customer'))
            ->get()
            ->each(fn (User $user): mixed => $user->notify(new self($order)));
    }
}

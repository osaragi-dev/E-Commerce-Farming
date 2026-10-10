<?php

namespace App\Notifications;

use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\StockMovements\StockMovementResource;
use App\Models\Order;
use App\Models\User;
use App\Models\Warehouse;
use Filament\Actions\Action;
use Illuminate\Notifications\Notification;

class StockMovementRecommendationForAdmins extends Notification
{
    public function __construct(
        public Order $order,
        public Warehouse $sourceWarehouse,
        public ?Warehouse $posWarehouse = null,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toDatabase(object $notifiable): array
    {
        $posName = $this->posWarehouse?->name ?? 'the POS warehouse';

        return [
            'format' => 'filament',
            'title' => 'Stock movement recommended',
            'body' => "Order {$this->order->order_number} is using {$this->sourceWarehouse->name}. Move stock to {$posName} when possible.",
            'icon' => 'heroicon-o-arrows-right-left',
            'color' => 'warning',
            'duration' => 'persistent',
            'order_number' => $this->order->order_number,
            'actions' => [
                Action::make('createStockMovement')
                    ->label('Open Stock Movements')
                    ->url(StockMovementResource::getUrl('index'))
                    ->markAsRead()
                    ->toArray(),
                Action::make('viewOrder')
                    ->label('View order')
                    ->url(OrderResource::getUrl('index'))
                    ->markAsRead()
                    ->toArray(),
            ],
        ];
    }

    public static function send(
        Order $order,
        Warehouse $sourceWarehouse,
        ?Warehouse $posWarehouse = null,
    ): void {
        User::query()
            ->where('is_active', true)
            ->whereDoesntHave('roles', fn ($query) => $query->where('name', 'customer'))
            ->get()
            ->each(fn (User $user): mixed => $user->notify(
                new self($order, $sourceWarehouse, $posWarehouse),
            ));
    }
}

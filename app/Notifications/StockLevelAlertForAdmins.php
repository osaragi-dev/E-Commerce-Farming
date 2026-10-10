<?php

namespace App\Notifications;

use App\Filament\Resources\Stocks\StockResource;
use App\Models\User;
use App\Models\WarehouseStock;
use Filament\Actions\Action;
use Illuminate\Notifications\Notification;

class StockLevelAlertForAdmins extends Notification
{
    public function __construct(
        public WarehouseStock $stock,
        public bool $outOfStock = false,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toDatabase(object $notifiable): array
    {
        $variant = $this->stock->productVariant;
        $productName = $variant?->product?->name ?? 'Unknown product';
        $variantName = $variant?->name ?? 'Unknown variant';
        $warehouseName = $this->stock->warehouse?->name ?? 'POS warehouse';
        $available = (string) $this->stock->stock_available;
        $minimum = (string) ($variant?->low_stock_threshold ?? 0);

        return [
            'format' => 'filament',
            'title' => $this->outOfStock ? 'Out of stock alert' : 'Low stock alert',
            'body' => "{$productName} - {$variantName} at {$warehouseName} has {$available} available (minimum {$minimum}).",
            'icon' => $this->outOfStock
                ? 'heroicon-o-x-circle'
                : 'heroicon-o-exclamation-triangle',
            'color' => $this->outOfStock ? 'danger' : 'warning',
            'duration' => 'persistent',
            'warehouse_name' => $warehouseName,
            'available' => $available,
            'minimum' => $minimum,
            'actions' => [
                Action::make('viewStocks')
                    ->label('Open Stocks')
                    ->url(StockResource::getUrl('index'))
                    ->markAsRead()
                    ->toArray(),
            ],
        ];
    }

    public static function send(WarehouseStock $stock, bool $outOfStock = false): void
    {
        User::query()
            ->where('is_active', true)
            ->whereDoesntHave('roles', fn ($query) => $query->where('name', 'customer'))
            ->get()
            ->each(fn (User $user): mixed => $user->notify(
                new self($stock, $outOfStock),
            ));
    }
}

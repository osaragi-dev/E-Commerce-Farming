<?php

namespace App\Filament\Resources\Stocks\Pages;

use App\Filament\Resources\Stocks\StockResource;
use App\Models\Warehouse;
use Filament\Resources\Pages\ListRecords;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Locked;

class ListStocks extends ListRecords
{
    protected static string $resource = StockResource::class;

    #[Locked]
    public ?string $warehouseId = null;

    #[Locked]
    public ?string $warehouseName = null;

    #[Locked]
    public ?string $stockStatus = null;

    public function mount(?string $warehouse = null, ?string $status = null): void
    {
        parent::mount();

        if ($status !== null) {
            abort_unless(in_array($status, ['all', 'available', 'out-of-stock'], true), 404);
            $this->stockStatus = $status;
        }

        if ($warehouse === null) {
            return;
        }

        $record = Warehouse::query()
            ->saved()
            ->whereIn('code', StockResource::WAREHOUSE_CODES)
            ->findOrFail($warehouse);

        $this->warehouseId = $record->id;
        $this->warehouseName = $record->name;
    }

    public function getTitle(): string
    {
        if ($this->warehouseName !== null) {
            return "{$this->warehouseName} Stocks";
        }

        return match ($this->stockStatus) {
            'all' => 'All Stock Variants',
            'available' => 'Available Stock',
            'out-of-stock' => 'Out of Stock',
            default => parent::getTitle(),
        };
    }

    public function getBreadcrumb(): string
    {
        return $this->warehouseName ?? ($this->stockStatus === null ? parent::getBreadcrumb() : $this->getTitle());
    }

    public function table(Table $table): Table
    {
        $table->getColumn('warehouse.name')?->hidden(fn (): bool => $this->warehouseId !== null);
        $table->getColumn('warehouse.code')?->hidden();
        $table->getColumn('productVariant.initial_stock')?->hidden();

        return parent::table($table);
    }

    protected function getTableQuery(): Builder
    {
        return parent::getTableQuery()
            ->whereHas('warehouse', fn (Builder $query): Builder => $query
                ->saved()
                ->whereIn('code', StockResource::WAREHOUSE_CODES))
            ->when(
                $this->warehouseId,
                fn (Builder $query, string $warehouseId): Builder => $query->where('warehouse_id', $warehouseId),
            )
            ->when($this->stockStatus === 'available', fn (Builder $query): Builder => $query->availableStock())
            ->when($this->stockStatus === 'out-of-stock', fn (Builder $query): Builder => $query->outOfStock());
    }

    protected function getHeaderActions(): array
    {
        return [];
    }
}

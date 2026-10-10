<?php

namespace App\Filament\Resources\Stocks\Pages;

use App\Filament\Actions\TableExportActions;
use App\Filament\Resources\Stocks\StockResource;
use App\Filament\Widgets\StockOverview;
use App\Models\Warehouse;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Resources\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class StockWarehouses extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string $resource = StockResource::class;

    protected string $view = 'filament.resources.stocks.pages.stock-warehouses';

    public function getTitle(): string
    {
        return 'Stocks';
    }

    public function table(Table $table): Table
    {
        $warehouseUrl = fn (Warehouse $record): string => StockResource::getUrl('warehouse', ['warehouse' => $record->id]);

        return $table
            ->query(Warehouse::query()
                ->saved()
                ->whereIn('code', StockResource::WAREHOUSE_CODES)
                ->withCount([
                    'stocks as total_items_count' => fn (Builder $query): Builder => $query
                        ->whereHas('productVariant', fn (Builder $variantQuery): Builder => $variantQuery->inventoryVisible()),
                    'stocks as critical_items_count' => fn (Builder $query): Builder => $query
                        ->whereHas('productVariant', fn (Builder $variantQuery): Builder => $variantQuery->inventoryVisible())
                        ->criticalStock(),
                ]))
            ->columns([
                TextColumn::make('name')->label('Warehouse')->searchable()->sortable(),
                TextColumn::make('code')->label('Warehouse Code')->searchable()->sortable(),
                TextColumn::make('total_items_count')->label('Total Items')->sortable(),
                TextColumn::make('critical_items_count')->label('Critical Items')->sortable(),
            ])
            ->recordUrl($warehouseUrl)
            ->recordActions([
                Action::make('view')
                    ->label('View Stocks')
                    ->icon('heroicon-o-eye')
                    ->url($warehouseUrl),
            ])
            ->recordActionsColumnLabel('Action')
            ->toolbarActions([
                TableExportActions::group(StockResource::class, StockResource::warehouseExportDefinition(), Warehouse::class, exportPermission: 'Export:Stock'),
                BulkActionGroup::make([
                    ...TableExportActions::selected(StockResource::class, StockResource::warehouseExportDefinition(), Warehouse::class, exportPermission: 'Export:Stock'),
                ])
                    ->label('Bulk Actions')
                    ->icon('heroicon-o-squares-plus')
                    ->color('gray')
                    ->button(),
            ])
            ->defaultSort('code')
            ->paginated(false);
    }

    protected function getHeaderWidgets(): array
    {
        return [StockOverview::class];
    }
}

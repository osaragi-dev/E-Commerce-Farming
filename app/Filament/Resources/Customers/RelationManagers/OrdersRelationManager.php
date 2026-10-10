<?php

namespace App\Filament\Resources\Customers\RelationManagers;

use App\Filament\Resources\Customers\CustomerResource;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Tables\Columns\RupiahColumn;
use App\Models\Order;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class OrdersRelationManager extends RelationManager
{
    protected static string $relationship = 'orders';

    protected static ?string $title = 'Order History';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return CustomerResource::canView($ownerRecord) && OrderResource::canViewAny();
    }

    public function mount(): void
    {
        abort_unless(static::canViewForRecord($this->getOwnerRecord(), $this->getPageClass()), 403);

        parent::mount();
    }

    public function hydrate(): void
    {
        abort_unless(static::canViewForRecord($this->getOwnerRecord(), $this->getPageClass()), 403);
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('payment'))
            ->recordUrl(fn (Order $record): ?string => auth()->user()?->can('view', $record)
                ? OrderResource::getUrl('view', ['record' => $record])
                : null)
            ->columns([
                TextColumn::make('order_number')->label('Order Number')->searchable()->sortable(),
                TextColumn::make('created_at')->label('Order Date')->dateTime()->sortable(),
                TextColumn::make('status')->label('Order Status')->badge(),
                TextColumn::make('payment_status')
                    ->label('Payment Status')
                    ->state(fn (Order $record): string => $record->paymentStageLabel())
                    ->badge()
                    ->color(fn (Order $record): string => $record->paymentStageColor()),
                RupiahColumn::make('total')->label('Total')->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('No orders yet')
            ->emptyStateDescription('This customer has no order history.')
            ->headerActions([])
            ->recordActions([])
            ->toolbarActions([]);
    }
}

<?php

namespace App\Filament\Tables;

use App\Filament\Support\Rupiah;
use App\Services\InvoiceGenerationService;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class InvoiceOrderSelectionTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->whereIn('orders.id', app(InvoiceGenerationService::class)
                    ->availableOrders($table->getArguments()['customer_id'] ?? null)->select('orders.id'))
                ->with('customer'))
            ->columns([
                TextColumn::make('order_number')->label('Order Number')->searchable()->sortable(),
                TextColumn::make('created_at')->label('Order Date')->dateTime('d M Y H:i')->timezone('Asia/Jakarta')->sortable(),
                TextColumn::make('customer.name')->label('Customer')->searchable(),
                TextColumn::make('total')->label('Total')->formatStateUsing(fn (string $state): string => Rupiah::formatWithPrefix($state))->alignEnd()->sortable(),
            ])
            ->defaultSort('created_at', 'desc')->paginated([10, 25, 50])
            ->emptyStateHeading('No orders available for invoicing')
            ->emptyStateDescription('This customer has no unpaid Invoice orders that have not already been invoiced.');
    }
}

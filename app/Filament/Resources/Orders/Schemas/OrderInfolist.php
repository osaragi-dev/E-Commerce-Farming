<?php

namespace App\Filament\Resources\Orders\Schemas;

use App\Enums\ShippingMethod;
use App\Filament\Resources\Payments\PaymentResource;
use App\Filament\Support\Rupiah;
use App\Models\Order;
use App\Support\Quantity;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class OrderInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            // Default Schema::columns() adalah 2 — tanpa ini, Section top-level otomatis
            // disusun berpasangan 2 kolom (Order Items|Order Summary, Payment|Status
            // History), makanya sebelumnya terlihat bersebelahan. Dipaksa 1 kolom supaya
            // semua Section turun ke bawah berurutan.
            ->columns(1)
            ->components([
                // ---- Order Information: identitas order & customer ----
                Section::make('Order Information')
                    ->schema([
                        TextEntry::make('void_hold')->label('Processing Hold')
                            ->state('Order processing is on hold while a void payment request is active. View payment approval.')
                            ->url(fn (Order $record): ?string => $record->payment && auth()->user()?->can('view', $record->payment)
                                ? PaymentResource::getUrl('view', ['record' => $record->payment]) : null)
                            ->visible(fn ($record): bool => $record?->payment?->hasActiveVoidRequest() ?? false),
                        TextEntry::make('order_number')->label('Order Number'),
                        TextEntry::make('created_at')->label('Date')->dateTime(),
                        TextEntry::make('status')
                            ->label('Order Status')
                            ->state(fn (Order $record): string => $record->adminStatusLabel(
                                paymentStatus: $record->payment?->status,
                            ))
                            ->color(fn (Order $record): string => $record->adminStatusColor())
                            ->badge(),
                        TextEntry::make('stock_reservation_status')
                            ->label('Stock Reservation')
                            ->badge(),
                        TextEntry::make('stock_reservation_error')
                            ->label('Reservation Error')
                            ->placeholder('-')
                            ->columnSpanFull(),
                        TextEntry::make('recipient_name')->label('Recipient Name')->placeholder('—'),
                        TextEntry::make('recipient_phone')->label('Recipient Phone Number')->placeholder('—'),
                    ])
                    ->columns(3),

                // ---- Shipping Information: method, location & notes ----
                Section::make('Shipping Information')
                    ->schema([
                        TextEntry::make('shipping_method')
                            ->label('Shipping Method')
                            ->formatStateUsing(fn (mixed $state): string => $state instanceof ShippingMethod
                                ? $state->getLabel()
                                : '—'),
                        TextEntry::make('shipping_address')
                            ->label(fn (Order $record): string => $record->shipping_method?->getLocationLabel() ?? 'Shipping Location')
                            ->placeholder('—')
                            ->columnSpanFull(),
                        TextEntry::make('notes')
                            ->label('Notes')
                            ->placeholder('—')
                            ->columnSpanFull(),
                    ])
                    ->columns(1),

                // ---- Order Items + Order Summary: satu Section, tidak dipisah ----
                // Digabung sesuai permintaan: daftar item di atas, lalu Subtotal /
                // Shipping Cost / Total sebagai footer ringkasan di bawahnya, dalam satu
                // kartu yang sama. Total tetap dari field `orders.total` existing (tidak
                // ada perhitungan baru), Shipping Cost tetap dari `orders.shipping_cost`.
                Section::make('Order Items')
                    ->schema([
                        RepeatableEntry::make('items')
                            ->label('')
                            ->table([
                                TableColumn::make('Product'),
                                TableColumn::make('Variant'),
                                TableColumn::make('Qty')->alignCenter(),
                                TableColumn::make('Unit')->alignCenter(),
                                TableColumn::make('Price Quantity'),
                                TableColumn::make('Price')->alignEnd(),
                                TableColumn::make('Subtotal')->alignEnd(),
                            ])
                            ->schema([
                                TextEntry::make('product_name')->label('Product'),
                                TextEntry::make('variant_name')->label('Variant')->placeholder('—'),
                                TextEntry::make('quantity')->formatStateUsing(fn ($state): string => Quantity::format($state))->label('Qty')->alignCenter(),
                                TextEntry::make('unit')->label('Unit')->placeholder('—')->alignCenter(),
                                TextEntry::make('price_quantity')->label('Price Quantity')->formatStateUsing(fn ($state): string => Quantity::format($state)),
                                TextEntry::make('unit_price')
                                    ->label('Price')
                                    ->alignEnd()
                                    ->formatStateUsing(fn (mixed $state): ?string => Rupiah::formatWithPrefix($state)),
                                TextEntry::make('subtotal')
                                    ->label('Subtotal')
                                    ->alignEnd()
                                    ->formatStateUsing(fn (mixed $state): ?string => Rupiah::formatWithPrefix($state)),
                            ])
                            ->columnSpanFull(),

                        TextEntry::make('subtotal')
                            ->label('Subtotal')
                            ->inlineLabel()
                            ->columnSpanFull()
                            ->formatStateUsing(fn (mixed $state): ?string => Rupiah::formatWithPrefix($state)),
                        TextEntry::make('discount_amount')
                            ->label('Discount')
                            ->inlineLabel()
                            ->columnSpanFull()
                            ->formatStateUsing(fn (mixed $state): ?string => Rupiah::formatWithPrefix($state)),
                        TextEntry::make('shipping_cost')
                            ->label('Shipping Cost')
                            ->inlineLabel()
                            ->columnSpanFull()
                            ->formatStateUsing(fn (mixed $state): ?string => Rupiah::formatWithPrefix($state)),
                        TextEntry::make('total')
                            ->label('Total')
                            ->inlineLabel()
                            ->columnSpanFull()
                            ->formatStateUsing(fn (mixed $state): ?string => Rupiah::formatWithPrefix($state))
                            ->weight('bold')
                            ->size('lg')
                            ->color('primary'),
                    ])
                    ->columns(1)
                    ->divided(),

                // ---- Payment: informasi payment terpisah dari Order Summary ----
                Section::make('Payment')
                    ->schema([
                        TextEntry::make('payment.method')->label('Payment Method'),
                        TextEntry::make('payment.status')->label('Payment Status')->badge(),
                        TextEntry::make('payment.amount')
                            ->label('Payment Amount')
                            ->formatStateUsing(fn (mixed $state): ?string => Rupiah::formatWithPrefix($state)),
                    ])
                    ->columns(3),

                // ---- Status History: show each transition in a readable table ----
                Section::make('Status History')
                    ->schema([
                        RepeatableEntry::make('statusLogs')
                            ->label('')
                            ->table([
                                TableColumn::make('Status')
                                    ->width('24%')
                                    ->alignCenter(),
                                TableColumn::make('Changed By')->width('22%'),
                                TableColumn::make('Changed At')->width('22%'),
                                TableColumn::make('Notes')->width('32%'),
                            ])
                            ->schema([
                                TextEntry::make('to_status')
                                    ->label('Status')
                                    ->badge()
                                    ->wrap()
                                    ->extraAttributes(['class' => 'order-status-history-status'])
                                    ->alignCenter(),
                                TextEntry::make('changedBy.name')->label('Changed By')->placeholder('System'),
                                TextEntry::make('created_at')->label('Changed At')->dateTime(),
                                TextEntry::make('note')->label('Notes')->placeholder('—')->wrap(),
                            ])
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}

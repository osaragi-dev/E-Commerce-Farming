<?php

namespace App\Filament\Resources\Orders\Tables;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\ShippingMethod;
use App\Enums\StockReservationStatus;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\Orders\Tables\Actions\AdvanceOrderStatusAction;
use App\Filament\Resources\Orders\Tables\Actions\CancelOrderAction;
use App\Filament\Resources\Orders\Tables\Actions\CreatePaymentAction;
use App\Filament\Resources\Orders\Tables\Actions\EditOrderAction;
use App\Filament\Resources\Orders\Tables\Actions\PrintReceiptAction;
use App\Filament\Resources\Orders\Tables\Actions\RetryReservationAction;
use App\Filament\Tables\ApplicationTable;
use App\Filament\Tables\Columns\RupiahColumn;
use App\Models\Order;
use CodeWithKyrian\FilamentDateRange\Tables\Filters\DateRangeFilter;
use Filament\Actions\ReplicateAction;
use Filament\Actions\ViewAction;
use Filament\Support\Enums\Width;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class OrdersTable
{
    public static function configure(Table $table): Table
    {
        $table = $table
            ->columns([
                TextColumn::make('order_number')
                    ->label('Order Number')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('recipient_name')
                    ->label('Customer')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('shipping_address')
                    ->label('Delivery Address')
                    ->placeholder('—')
                    ->limit(36),
                TextColumn::make('shipping_method')
                    ->label('Shipping Method')
                    ->formatStateUsing(fn (mixed $state): string => $state instanceof ShippingMethod
                        ? $state->getLabel()
                        : '—')
                    ->sortable(),
                TextColumn::make('payment_method')
                    ->label('Payment Method')
                    ->searchable(),
                TextColumn::make('payment.status')
                    ->label('Payment Status')
                    ->state(fn (Order $record): string => $record->paymentStageLabel())
                    ->badge()
                    ->color(fn (Order $record): string => $record->paymentStageColor()),
                TextColumn::make('stock_reservation_status')
                    ->label('Stock Reservation')
                    ->state(fn (Order $record): string => $record->stock_reservation_status?->getLabel() ?? '-')
                    ->badge()
                    ->color(fn (Order $record): string => $record->stock_reservation_status?->getColor() ?? 'gray'),
                TextColumn::make('stock_reservation_error')
                    ->label('Reservation Error')
                    ->placeholder('-')
                    ->limit(40)
                    ->toggleable(isToggledHiddenByDefault: true),
                RupiahColumn::make('total')
                    ->label('Total')
                    ->sortable(),
                TextColumn::make('status')
                    ->label('Order Status')
                    ->state(fn (Order $record): string => $record->adminStatusLabel(
                        paymentStatus: $record->payment?->status,
                    ))
                    ->color(fn (Order $record): string => $record->adminStatusColor())
                    ->badge(),
                TextColumn::make('cancelled_at')
                    ->label('Cancelled At')
                    ->placeholder('-')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('createdBy.name')
                    ->label('Created By')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')
                    ->label('Date')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Order Status')
                    ->options([
                        OrderStatus::WaitingPayment->value => OrderStatus::WaitingPayment->getLabel(),
                        OrderStatus::ReadyToProcess->value => OrderStatus::ReadyToProcess->getLabel(),
                        OrderStatus::Packing->value => OrderStatus::Packing->getLabel(),
                        OrderStatus::Shipping->value => OrderStatus::Shipping->getLabel(),
                        OrderStatus::Done->value => OrderStatus::Done->getLabel(),
                        OrderStatus::Cancelled->value => OrderStatus::Cancelled->getLabel(),
                    ])
                    ->query(function (Builder $query, mixed $state): Builder {
                        $value = is_array($state) ? ($state['value'] ?? null) : $state;

                        if (blank($value)) {
                            return $query;
                        }

                        if ($value === OrderStatus::ReadyToProcess->value) {
                            return $query->whereIn('status', [
                                OrderStatus::WaitingConfirmation->value,
                                OrderStatus::ReadyToProcess->value,
                            ]);
                        }

                        return $query->where('status', $value);
                    }),
                SelectFilter::make('payment_method')
                    ->label('Payment Method')
                    ->options(collect(PaymentMethod::cases())->mapWithKeys(
                        fn (PaymentMethod $method): array => [$method->value => $method->getLabel()]
                    ))
                    ->query(function (Builder $query, mixed $state): Builder {
                        $value = is_array($state) ? ($state['value'] ?? null) : $state;

                        if (blank($value)) {
                            return $query;
                        }

                        // Filter on the order's intended payment method so
                        // legacy orders without a Payment remain visible.
                        return $query->where('payment_method', $value);
                    }),
                SelectFilter::make('payment_status')
                    ->label('Payment Status')
                    ->options(collect(PaymentStatus::cases())->mapWithKeys(
                        fn (PaymentStatus $status): array => [$status->value => $status->getLabel()]
                    ))
                    ->query(function (Builder $query, mixed $state): Builder {
                        $value = is_array($state) ? ($state['value'] ?? null) : $state;

                        if (blank($value)) {
                            return $query;
                        }

                        return $query->whereHas(
                            'payment',
                            fn (Builder $paymentQuery): Builder => $paymentQuery->where('status', $value),
                        );
                    }),
                SelectFilter::make('stock_reservation_status')
                    ->label('Stock Reservation')
                    ->options(collect(StockReservationStatus::cases())->mapWithKeys(
                        fn (StockReservationStatus $status): array => [$status->value => $status->getLabel()]
                    )),
                SelectFilter::make('created_by')
                    ->relationship('createdBy', 'name')
                    ->label('Created By'),
                SelectFilter::make('updated_by')
                    ->relationship('updatedBy', 'name')
                    ->label('Updated By'),
                DateRangeFilter::make('created_at')
                    ->label('Date')
                    ->displayFormat('d/m/Y')
                    ->startPlaceholder('dd/mm/yyyy')
                    ->endPlaceholder('dd/mm/yyyy')
                    ->firstDayOfWeek(1)
                    ->dualCalendar(false)
                    ->inline(true)
                    ->locale('en')
                    ->timezone('Asia/Jakarta'),
            ])
            ->filtersFormWidth(Width::Large)
            ->recordActions([
                EditOrderAction::make(),
                ViewAction::make()
                    ->label('View')
                    ->slideOver()
                    ->modalHeading(fn ($record): string => $record->order_number),
                RetryReservationAction::make(),
                AdvanceOrderStatusAction::make(),
                PrintReceiptAction::make(),
                ReplicateAction::make('clone')->hidden(),
                CancelOrderAction::make(),
                CreatePaymentAction::make(),
            ])
            ->toolbarActions([]);

        return ApplicationTable::configure($table, OrderResource::class, hasLifecycle: false);
    }
}

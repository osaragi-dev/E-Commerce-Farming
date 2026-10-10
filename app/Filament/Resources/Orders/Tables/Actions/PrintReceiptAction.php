<?php

namespace App\Filament\Resources\Orders\Tables\Actions;

use App\Enums\OrderStatus;
use App\Models\Order;
use Filament\Actions\Action;
use Illuminate\Support\Js;

class PrintReceiptAction
{
    public static function make(): Action
    {
        return Action::make('printReceipt')
            ->label('Print Receipt')
            ->icon('heroicon-o-printer')
            ->color('gray')
            ->authorize('view')
            ->visible(fn (Order $record): bool => in_array($record->status, [
                OrderStatus::Packing,
                OrderStatus::Shipping,
                OrderStatus::ReadyForPickup,
                OrderStatus::PickedUp,
                OrderStatus::Done,
            ], true))
            ->alpineClickHandler(fn (Order $record): string => "\$dispatch('farming-print-receipt', ".Js::from([
                'receiptUrl' => route('admin.orders.receipt', $record),
                'jobUrl' => route('admin.orders.print', $record),
                'certificateUrl' => route('admin.orders.print-certificate', $record),
                'rawbtUrl' => route('admin.orders.rawbt', $record),
            ]).')');
    }
}

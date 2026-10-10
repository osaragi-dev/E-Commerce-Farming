<?php

namespace App\Filament\Resources\Orders\Tables\Actions;

use App\Enums\CustomerType;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\ShippingLocation;
use App\Enums\ShippingMethod;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentSetting;
use App\Models\SystemFreezeState;
use App\Services\SystemFreezeGuard;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class EditOrderAction
{
    public static function make(): EditAction
    {
        return EditAction::make()
            ->authorize('deleteAny')
            ->visible(fn (Order $record): bool => $record->status !== OrderStatus::Cancelled)
            ->disabled(fn (): bool => SystemFreezeState::current()->is_frozen)
            ->mutateRecordDataUsing(fn (Order $record, array $data): array => [
                'customer_id' => $record->customer_id,
                'method' => $record->payment?->method?->value,
                'recipient_phone' => $record->recipient_phone,
                'shipping_method' => $record->shipping_method->value,
                'shipping_address' => ShippingLocation::tryFrom($record->shipping_address ?? '')?->value ?? 'Others',
                'shipping_address_other' => ShippingLocation::tryFrom($record->shipping_address ?? '') === null ? $record->shipping_address : null,
            ])
            ->schema([
                Select::make('customer_id')->label('Customer')->required()->searchable()->preload()
                    ->options(fn (): array => Customer::query()->saved()->where('is_active', true)->orderBy('name')
                        ->get(['id', 'name', 'customer_type'])
                        ->mapWithKeys(fn (Customer $customer): array => [
                            $customer->id => $customer->name.' — '.$customer->customer_type->getLabel(),
                        ])->all())
                    ->disabled(fn (Order $record): bool => $record->payment?->invoice_id !== null)
                    ->live()
                    ->afterStateUpdated(fn (Set $set, ?string $state) => $set('recipient_phone', Customer::find($state)?->phone))
                    ->helperText('Changing the customer updates the recipient name. You can edit the phone number below.'),
                TextInput::make('recipient_phone')->label('Phone Number')->tel()->maxLength(30),
                Select::make('shipping_method')->label('Shipping Method')->options(ShippingMethod::class)->required()->live()
                    ->afterStateUpdated(function (Set $set): void {
                        $set('shipping_address', ShippingLocation::AgungFarmAndGarden->value);
                        $set('shipping_address_other', null);
                    }),
                Select::make('shipping_address')
                    ->label(fn (Get $get): string => in_array($get('shipping_method'), [ShippingMethod::Pickup, ShippingMethod::Pickup->value], true) ? 'Pickup Location' : 'Delivery Address')
                    ->options(fn (Get $get): array => ShippingLocation::options() + (in_array($get('shipping_method'), [ShippingMethod::Delivery, ShippingMethod::Delivery->value], true) ? ['Others' => 'Others'] : []))
                    ->required()->live(),
                Textarea::make('shipping_address_other')->label('Other Delivery Address')->maxLength(1000)
                    ->visible(fn (Get $get): bool => $get('shipping_address') === 'Others')
                    ->required(fn (Get $get): bool => $get('shipping_address') === 'Others'),
                Select::make('method')->label('Payment Method')
                    ->visible(fn (Order $record): bool => $record->payment !== null)
                    ->options(fn (Order $record, Get $get): array => collect(PaymentMethod::cases())
                        ->filter(fn (PaymentMethod $method): bool => $method !== PaymentMethod::Invoice
                            || $record->payment?->method === PaymentMethod::Invoice
                            || Customer::query()->saved()->where('is_active', true)->where('customer_type', CustomerType::Invoice)->whereKey($get('customer_id'))->exists())
                        ->mapWithKeys(fn (PaymentMethod $method): array => [$method->value => $method->getLabel()])->all())
                    ->required()
                    ->disabled(fn (Order $record): bool => $record->payment === null || ! auth()->user()->can('update', $record->payment) || ! self::canCorrectDetails($record->payment)),
            ])
            ->using(function (Order $record, array $data, EditAction $action): Order {
                return DB::transaction(function () use ($record, $data, $action): Order {
                    $statePath = 'mountedActions.'.$action->getNestingIndex().'.data.';
                    $order = Order::query()->lockForUpdate()->findOrFail($record->id);
                    Gate::authorize('deleteAny', Order::class);
                    if ($order->status === OrderStatus::Cancelled) {
                        throw ValidationException::withMessages([$statePath.'customer_id' => 'Cancelled orders cannot be edited.']);
                    }
                    app(SystemFreezeGuard::class)->assertNotFrozen();
                    $payment = $order->payment()->lockForUpdate()->first();
                    if (isset($data['customer_id']) && $data['customer_id'] !== $order->customer_id) {
                        $customer = Customer::query()->saved()->where('is_active', true)->find($data['customer_id']);
                        if ($customer === null || $payment?->invoice_id !== null) {
                            throw ValidationException::withMessages([$statePath.'customer_id' => 'Select an active customer. Orders already included in an invoice cannot change customer.']);
                        }
                        if ($order->payment_method === PaymentMethod::Invoice && $customer->customer_type !== CustomerType::Invoice) {
                            throw ValidationException::withMessages([$statePath.'customer_id' => 'Select an invoice customer for this order.']);
                        }
                        $order->fill(['customer_id' => $customer->id, 'recipient_name' => $customer->name]);
                    }
                    $order->fill([
                        'recipient_phone' => $data['recipient_phone'],
                        'shipping_method' => $data['shipping_method'],
                        'shipping_address' => $data['shipping_address'] === 'Others' ? trim($data['shipping_address_other']) : $data['shipping_address'],
                    ])->save();
                    if ($payment === null) {
                        return $order;
                    }
                    $payment->setRelation('order', $order);
                    $method = $data['method'] ?? $payment->method;
                    $method = $method instanceof PaymentMethod ? $method : PaymentMethod::from($method);
                    $changed = $method !== $payment->method;
                    if ($changed) {
                        Gate::authorize('update', $payment);
                    }

                    if ($changed && ! self::canCorrectDetails($payment)) {
                        throw ValidationException::withMessages([$statePath.'method' => 'Only unpaid payments without proof or an invoice can be corrected.']);
                    }
                    if ($changed && $method === PaymentMethod::Invoice
                        && ! Customer::query()->saved()->where('is_active', true)->where('customer_type', CustomerType::Invoice)->whereKey($order->customer_id)->exists()) {
                        throw ValidationException::withMessages([$statePath.'method' => 'Select an active invoice customer for this payment method.']);
                    }
                    if ($changed) {
                        $payment->fill(['method' => $method]);
                        if ($payment->isDirty('method')) {
                            $payment->proof_upload_deadline_at = $method->requiresProof()
                                ? now()->addHours(PaymentSetting::current()->proof_resubmission_hours)
                                : null;
                        }
                        $payment->save();
                        $order->fill(['payment_method' => $method])->save();
                        if (in_array($method, [PaymentMethod::Cash, PaymentMethod::Invoice], true) && $order->status === OrderStatus::WaitingPayment) {
                            $order->forceFill(['status' => OrderStatus::ReadyToProcess])->save();
                            $order->statusLogs()->create([
                                'from_status' => OrderStatus::WaitingPayment,
                                'to_status' => OrderStatus::ReadyToProcess,
                                'changed_by_user_id' => auth()->id(),
                                'note' => 'Payment method changed to '.$method->value.'.',
                                'created_at' => now(),
                            ]);
                        }
                        $payment->setRelation('order', $order);
                    }

                    return $order->refresh();
                });
            });
    }

    private static function canCorrectDetails(Payment $payment): bool
    {
        return $payment->status === PaymentStatus::Unpaid
            && $payment->invoice_id === null
            && $payment->method !== PaymentMethod::Invoice
            && $payment->order?->status !== OrderStatus::Cancelled
            && ! $payment->proofs()->exists();
    }
}

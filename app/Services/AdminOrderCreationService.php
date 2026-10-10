<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\CustomerType;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\ShippingLocation;
use App\Enums\ShippingMethod;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Payment;
use App\Models\ProductVariant;
use App\Models\StockMutation;
use App\Notifications\NewOrderForAdmins;
use App\Support\Quantity;
use App\Support\VariantPricing;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class AdminOrderCreationService
{
    /**
     * @param  array<string, mixed>  $data  Validated order form data.
     * @param  array<string, string>  $expectedPrices  Prices reviewed by the POS cashier, keyed by variant ID.
     */
    public function create(array $data, bool $confirmPayment = false, array $expectedPrices = [], array $expectedPriceQuantities = []): Order
    {
        Gate::authorize('create', Order::class);
        app(SystemFreezeGuard::class)->assertNotFrozen();

        $paymentMethod = ($data['payment_method'] ?? null) instanceof PaymentMethod
            ? $data['payment_method']
            : PaymentMethod::tryFrom((string) ($data['payment_method'] ?? ''));

        if ($paymentMethod === null) {
            throw ValidationException::withMessages(['payment_method' => 'Please select a valid payment method.']);
        }

        $data['payment_method'] = $paymentMethod->value;
        $isPosCheckout = filled($data['pos_checkout_token'] ?? null);
        $transactionAt = null;

        if ($isPosCheckout && filter_var($data['backdate_enabled'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $backdateDate = Validator::make(
                ['backdate_date' => $data['backdate_date'] ?? null],
                ['backdate_date' => ['required', 'date_format:Y-m-d', 'before:'.today('Asia/Jakarta')->toDateString()]],
            )->validate()['backdate_date'];
            $checkoutAt = now('Asia/Jakarta');
            $transactionAt = CarbonImmutable::parse($backdateDate.' '.$checkoutAt->format('H:i:s'), 'Asia/Jakarta');
        }

        if ($confirmPayment) {
            Gate::authorize('Verify:Payment');
        }

        if ($isPosCheckout) {
            if ($existing = Order::withTrashed()->where('pos_checkout_token', $data['pos_checkout_token'])->first()) {
                abort_unless($existing->created_by === Auth::id(), 403);

                return $existing;
            }
        }

        $shippingMethodState = $data['shipping_method'] ?? ShippingMethod::Delivery->value;
        $shippingMethod = $shippingMethodState instanceof ShippingMethod
            ? $shippingMethodState
            : ShippingMethod::from((string) $shippingMethodState);
        $shippingAddressOption = (string) ($data['shipping_address'] ?? '');
        $orderNumber = self::generateOrderNumber($transactionAt);
        $shippingAddress = $shippingAddressOption === 'Others'
            ? trim((string) ($data['shipping_address_other'] ?? ''))
            : $shippingAddressOption;

        if ($shippingMethod === ShippingMethod::Pickup && $shippingAddressOption !== 'Others') {
            $shippingAddress = ShippingLocation::AgungFarmAndGarden->value;
        }

        $proofPath = null;

        try {
            $order = DB::transaction(function () use ($data, $orderNumber, $shippingAddress, $shippingMethod, $confirmPayment, $isPosCheckout, $expectedPrices, $expectedPriceQuantities, $transactionAt, &$proofPath) {
                $customer = Customer::query()->saved()->where('is_active', true)->lockForUpdate()->find($data['customer_id']);

                if (! $customer) {
                    throw ValidationException::withMessages(['customer_id' => 'Please select an active customer.']);
                }

                if ($data['payment_method'] === PaymentMethod::Invoice->value) {
                    if ($customer->customer_type !== CustomerType::Invoice) {
                        throw ValidationException::withMessages(['payment_method' => 'Invoice payment requires an Invoice customer.']);
                    }

                    if ($confirmPayment) {
                        throw ValidationException::withMessages(['payment_method' => 'Invoice orders must be created with an unpaid payment.']);
                    }
                }

                $variantIds = collect($data['items'])->pluck('product_variant_id')->filter()->unique();

                $variants = ProductVariant::query()
                    ->inventoryVisible()
                    ->with(['product', 'unit', 'pricePeriods'])
                    ->whereIn('id', $variantIds)
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('id');

                $subtotal = '0';
                $lines = [];

                foreach ($data['items'] as $item) {
                    $variant = $variants->get($item['product_variant_id'] ?? null);
                    $quantity = Quantity::validated($item['quantity'] ?? null);

                    if (! $variant) {
                        throw ValidationException::withMessages([
                            'items' => 'One of the selected products could not be found.',
                        ]);
                    }

                    if ($quantity <= 0) {
                        throw ValidationException::withMessages([
                            'items' => "Quantity for {$variant->name} must be greater than 0.",
                        ]);
                    }

                    if ($isPosCheckout && (! isset($expectedPrices[$variant->id]) || bccomp($variant->effective_price, $expectedPrices[$variant->id], 2) !== 0 || bccomp($variant->price_quantity, $expectedPriceQuantities[$variant->id] ?? '1', 3) !== 0)) {
                        throw ValidationException::withMessages(['prices' => 'Product prices have changed. Review the updated total and confirm again.']);
                    }

                    $lineSubtotal = VariantPricing::subtotal($variant->effective_price, $quantity, $variant->price_quantity);
                    $subtotal = bcadd($subtotal, $lineSubtotal, 2);

                    $lines[] = [
                        'variant' => $variant,
                        'quantity' => $quantity,
                        'subtotal' => $lineSubtotal,
                    ];
                }

                $fulfillmentWarehouse = app(OrderFulfillmentResolver::class)->resolveForLines(
                    $lines,
                );

                if (! $fulfillmentWarehouse) {
                    throw ValidationException::withMessages([
                        'items' => 'The selected products are not available in the POS warehouse.',
                    ]);
                }

                $shippingCost = (string) ($data['shipping_cost'] ?? '0');
                $discountAmount = $data['discount_amount'] ?? '0';
                Validator::make(['discount_amount' => $discountAmount], [
                    'discount_amount' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:'.$subtotal],
                ])->validate();
                $total = bcadd(bcsub($subtotal, (string) $discountAmount, 2), $shippingCost, 2);

                $order = new Order([
                    'pos_checkout_token' => $isPosCheckout ? $data['pos_checkout_token'] : null,
                    'order_number' => $orderNumber,
                    'customer_id' => $data['customer_id'],
                    'fulfillment_warehouse_id' => $fulfillmentWarehouse->id,
                    'status' => OrderStatus::WaitingPayment,
                    'shipping_method' => $shippingMethod,
                    'payment_method' => $data['payment_method'],
                    'recipient_name' => $data['recipient_name'],
                    'recipient_phone' => $data['recipient_phone'],
                    'shipping_address' => $shippingAddress,
                    'shipping_cost' => $shippingCost,
                    'subtotal' => $subtotal,
                    'discount_amount' => $discountAmount,
                    'total' => $total,
                    'notes' => $data['notes'] ?? null,
                    'created_by' => Auth::id(),
                ]);
                if ($transactionAt !== null) {
                    $order->created_at = $transactionAt;
                }
                $order->save();

                foreach ($lines as $line) {
                    $variant = $line['variant'];

                    $orderItem = $order->items()->create([
                        'product_id' => $variant->product_id,
                        'product_variant_id' => $variant->id,
                        'product_name' => $variant->product?->name,
                        'variant_name' => $variant->name,
                        'unit' => $variant->unit?->code,
                        'quantity' => $line['quantity'],
                        'unit_price' => $variant->effective_price,
                        'price_quantity' => $variant->price_quantity,
                        'subtotal' => $line['subtotal'],
                    ]);

                    StockMutation::reserveForOrder($variant, $line['quantity'], $orderItem->id, Auth::id(), $fulfillmentWarehouse);
                }

                $order->runDuringCreation(function () use ($data, $order, $orderNumber, $transactionAt, &$proofPath): void {
                    if (PaymentMethod::from($data['payment_method'])->requiresProof() && filled($data['payment_proof'] ?? null)) {
                        $payment = $order->payment()->create([
                            'payment_number' => $transactionAt === null ? null : Payment::generatePaymentNumber($transactionAt),
                            'method' => $data['payment_method'],
                            'amount' => $order->total,
                            'status' => PaymentStatus::WaitingConfirm,
                            'created_by' => Auth::id(),
                        ]);

                        $uploadedPath = $data['payment_proof'];
                        $extension = pathinfo($uploadedPath, PATHINFO_EXTENSION);
                        $proofPath = 'payment-proofs/'.$orderNumber.'.'.$extension;

                        if (! Storage::disk('public')->copy($uploadedPath, $proofPath)) {
                            throw ValidationException::withMessages(['payment_proof' => 'The payment proof could not be saved. Please try again.']);
                        }
                        $payment->submitProof($proofPath, Auth::user());
                        $order->markWaitingConfirmation(Auth::user());
                    } elseif (PaymentMethod::from($data['payment_method'])->requiresProof()) {
                        $order->initializeAdminUnpaidPayment(Auth::user(), $transactionAt);
                    } else {
                        $order->initializeUnpaidPayment(Auth::user(), $transactionAt);
                    }
                });

                if ($transactionAt !== null) {
                    DB::table('payments')->where('order_id', $order->id)->update(['created_at' => $transactionAt]);
                }

                if ($confirmPayment) {
                    $payment = $order->payment()->firstOrFail();
                    $payment->confirm(Auth::user());
                    $order->refresh();

                    if ($transactionAt !== null) {
                        $payment->forceFill(['verified_at' => $transactionAt])->save();
                    }
                }

                if ($transactionAt !== null) {
                    DB::table('order_status_logs')->where('order_id', $order->id)->update(['created_at' => $transactionAt]);
                }

                return $order;
            });
        } catch (\Throwable $exception) {
            if ($proofPath !== null) {
                Storage::disk('public')->delete($proofPath);
            }

            throw $exception;
        }

        DB::afterCommit(function () use ($order, $data, $proofPath): void {
            if ($proofPath !== null && $data['payment_proof'] !== $proofPath) {
                Storage::disk('public')->delete($data['payment_proof']);
            }

            NewOrderForAdmins::send($order);
        });

        return $order;
    }

    private static function generateOrderNumber(?CarbonImmutable $transactionAt = null): string
    {
        $prefix = 'ORD-'.($transactionAt?->format('Ymd') ?? now('Asia/Jakarta')->format('Ymd')).'-';

        $lastNumber = Order::query()
            ->withTrashed()
            ->where('order_number', 'like', $prefix.'%')
            ->orderByDesc('order_number')
            ->value('order_number');

        $next = $lastNumber ? ((int) Str::afterLast($lastNumber, '-')) + 1 : 1;

        return $prefix.Str::padLeft((string) $next, 3, '0');
    }
}

<?php

namespace App\Services;

use App\Enums\CustomerType;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Payment;
use App\Support\Quantity;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Number;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class InvoiceGenerationService
{
    /** @return Builder<Order> */
    public function availableOrders(?string $customerId): Builder
    {
        return Order::query()
            ->where('customer_id', $customerId)
            ->where('status', '!=', OrderStatus::Cancelled)
            ->where('payment_method', PaymentMethod::Invoice)
            ->whereHas('payment', fn (Builder $query): Builder => $query
                ->where('method', PaymentMethod::Invoice)
                ->where('status', PaymentStatus::Unpaid)
                ->whereNull('invoice_id'));
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function preview(array $data): array
    {
        Gate::authorize('create', Invoice::class);

        return $this->buildPayload($data);
    }

    /** @param array<string, mixed> $payload */
    public function fingerprint(array $payload): string
    {
        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
    }

    /** @param array<string, mixed> $data */
    public function generate(array $data, string $expectedFingerprint, string $generationToken): Invoice
    {
        Gate::authorize('create', Invoice::class);
        app(SystemFreezeGuard::class)->assertNotFrozen();
        Validator::make(['token' => $generationToken], ['token' => ['required', 'uuid']])->validate();

        return DB::transaction(function () use ($data, $expectedFingerprint, $generationToken): Invoice {
            if ($existing = Invoice::withTrashed()->where('generation_token', $generationToken)->first()) {
                abort_unless($existing->created_by === auth('web')->id(), 403);
                if ($existing->trashed()) {
                    throw ValidationException::withMessages(['data.customer_id' => 'This invoice has been deleted. Preview again to create a new invoice.']);
                }

                return $existing;
            }

            $payload = $this->buildPayload($data, lock: true);
            if (! hash_equals($expectedFingerprint, $this->fingerprint($payload))) {
                throw ValidationException::withMessages(['data.customer_id' => 'Invoice details have changed. Preview and review the invoice again.']);
            }

            $date = CarbonImmutable::parse($payload['invoice_date']);
            DB::table('invoice_sequences')->insertOrIgnore(['year' => $date->year, 'last_number' => 0]);
            $sequence = (int) DB::table('invoice_sequences')->where('year', $date->year)->lockForUpdate()->value('last_number') + 1;
            DB::table('invoice_sequences')->where('year', $date->year)->update(['last_number' => $sequence]);
            $months = ['I', 'II', 'III', 'IV', 'V', 'VI', 'VII', 'VIII', 'IX', 'X', 'XI', 'XII'];
            $payload['invoice_number'] = sprintf('%04d/FARM/%s/%d', $sequence, $months[$date->month - 1], $date->year);

            $invoice = Invoice::query()->create([
                'generation_token' => $generationToken,
                'invoice_number' => $payload['invoice_number'],
                'sequence_year' => $date->year,
                'sequence' => $sequence,
                'customer_id' => $payload['customer_id'],
                'customer_name' => $payload['customer_name'],
                'invoice_date' => $payload['invoice_date'],
                'due_date' => $payload['due_date'],
                'period_start' => $payload['period_start'],
                'period_end' => $payload['period_end'],
                'total' => $payload['total'],
                'document_payload' => $payload,
                'created_by' => auth('web')->id(),
            ]);
            $linked = Payment::query()->whereKey($payload['payment_ids'])->whereNull('invoice_id')
                ->update(['invoice_id' => $invoice->id]);
            if ($linked !== count($payload['payment_ids'])) {
                throw ValidationException::withMessages(['data.customer_id' => 'An order has already been invoiced. Preview again.']);
            }

            return $invoice;
        }, attempts: 3);
    }

    public function setPaid(Invoice $invoice, bool $paid): void
    {
        Gate::authorize('view', $invoice);
        Gate::authorize('Verify:Payment');
        app(SystemFreezeGuard::class)->assertNotFrozen();

        DB::transaction(function () use ($invoice, $paid): void {
            $payments = $this->lockPayments($invoice);
            foreach ($payments as $payment) {
                if ($paid && $payment->status === PaymentStatus::Unpaid) {
                    $payment->confirm(auth('web')->user());
                } elseif (! $paid && $payment->status === PaymentStatus::Paid) {
                    $payment->forceFill([
                        'status' => PaymentStatus::Unpaid,
                        'verified_by' => null,
                        'verified_at' => null,
                        'updated_by' => auth('web')->id(),
                    ])->save();
                }
            }
        }, attempts: 3);
        $invoice->unsetRelation('payments');
    }

    public function delete(Invoice $invoice): void
    {
        Gate::authorize('delete', $invoice);
        app(SystemFreezeGuard::class)->assertNotFrozen();
        DB::transaction(function () use ($invoice): void {
            $payments = $this->lockPayments($invoice);
            if ($payments->contains(fn (Payment $payment): bool => $payment->status !== PaymentStatus::Unpaid)) {
                throw ValidationException::withMessages(['invoice' => 'Only a fully unpaid invoice can be deleted.']);
            }
            Payment::query()->whereKey($payments->modelKeys())->update(['invoice_id' => null]);
            $invoice->forceFill(['deleted_by' => auth('web')->id()])->save();
            $invoice->delete();
        }, attempts: 3);
    }

    /** @return Collection<int, Payment> */
    private function lockPayments(Invoice $invoice): Collection
    {
        $orderIds = Payment::withTrashed()->where('invoice_id', $invoice->id)->pluck('order_id');
        $orders = Order::withTrashed()->whereKey($orderIds)->orderBy('id')->lockForUpdate()->get();
        $payments = Payment::withTrashed()->where('invoice_id', $invoice->id)->orderBy('id')->lockForUpdate()->get();
        Invoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
        if ($payments->isEmpty() || $orders->contains(fn (Order $order): bool => $order->trashed() || $order->status === OrderStatus::Cancelled)
            || $payments->contains(fn (Payment $payment): bool => $payment->trashed() || $payment->method !== PaymentMethod::Invoice || ! in_array($payment->status, [PaymentStatus::Paid, PaymentStatus::Unpaid], true))) {
            throw ValidationException::withMessages(['invoice' => 'Invoice payments have changed. Review the linked orders before continuing.']);
        }

        return $payments;
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function buildPayload(array $data, bool $lock = false): array
    {
        $data = Validator::make($data, [
            'customer_id' => ['required', 'uuid'],
            'generation_method' => ['required', Rule::in(['by_date', 'by_selected_order'])],
            'invoice_date' => ['required', 'date_format:Y-m-d'],
            'due_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:invoice_date'],
            'start_date' => ['required_if:generation_method,by_date', 'nullable', 'date_format:Y-m-d'],
            'end_date' => ['required_if:generation_method,by_date', 'nullable', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'selected_order_ids' => ['required_if:generation_method,by_selected_order', 'array', 'max:500'],
            'selected_order_ids.*' => ['required', 'uuid', 'distinct'],
        ])->validate();
        $customer = Customer::query()->saved()->where('is_active', true)->where('customer_type', CustomerType::Invoice)->find($data['customer_id']);
        if (! $customer) {
            throw ValidationException::withMessages(['data.customer_id' => 'Select an active, saved Invoice customer.']);
        }
        $query = $this->availableOrders($customer->id);
        if ($data['generation_method'] === 'by_selected_order') {
            $query->whereKey($data['selected_order_ids']);
        } else {
            $query->where('created_at', '>=', CarbonImmutable::parse($data['start_date'], 'Asia/Jakarta')->startOfDay())
                ->where('created_at', '<', CarbonImmutable::parse($data['end_date'], 'Asia/Jakarta')->addDay()->startOfDay());
        }
        $orders = $query->with(['items' => fn ($query) => $query->orderBy('id')])->orderBy('id')->when($lock, fn (Builder $query): Builder => $query->lockForUpdate())->get();
        if ($orders->isEmpty() || ($data['generation_method'] === 'by_selected_order' && $orders->count() !== count($data['selected_order_ids']))) {
            $field = $data['generation_method'] === 'by_date' ? 'data.customer_id' : 'data.selected_order_ids';
            $message = $data['generation_method'] === 'by_date'
                ? 'No eligible orders: this customer has no unpaid, uninvoiced orders in the selected date range.'
                : 'No eligible orders, or a selected order is no longer available. Select unpaid Invoice orders that have not been invoiced.';
            throw ValidationException::withMessages([$field => $message]);
        }
        $payments = Payment::query()->currentAttempt()->whereIn('order_id', $orders->modelKeys())->orderBy('id')->when($lock, fn (Builder $query): Builder => $query->lockForUpdate())->get();
        if ($payments->count() !== $orders->count() || $payments->contains(fn (Payment $payment): bool => $payment->method !== PaymentMethod::Invoice || $payment->status !== PaymentStatus::Unpaid || $payment->invoice_id !== null)) {
            throw ValidationException::withMessages(['data.customer_id' => 'Order payments have changed. Preview again.']);
        }
        $items = [];
        $subtotal = '0.00';
        $shipping = '0.00';
        $discount = '0.00';
        foreach ($orders as $order) {
            $lineTotal = '0.00';
            foreach ($order->items as $item) {
                $items[] = [
                    'description' => $item->product_name.($item->variant_name && $item->variant_name !== $item->product_name ? ' | '.$item->variant_name : ''),
                    'quantity' => Quantity::format($item->quantity),
                    'price_quantity' => Quantity::format($item->price_quantity),
                    'unit' => $item->unit ?? '',
                    'unit_price' => $item->unit_price,
                    'subtotal' => $item->subtotal,
                ];
                $lineTotal = bcadd($lineTotal, $item->subtotal, 2);
            }
            $payment = $payments->firstWhere('order_id', $order->id);
            if ($order->items->isEmpty() || bccomp($lineTotal, $order->subtotal, 2) !== 0 || bccomp(bcadd(bcsub($lineTotal, $order->discount_amount, 2), $order->shipping_cost, 2), $payment->amount, 2) !== 0) {
                throw ValidationException::withMessages(['data.customer_id' => 'An order total does not match its items and payment. Review the order before generating an invoice.']);
            }
            $subtotal = bcadd($subtotal, $lineTotal, 2);
            $shipping = bcadd($shipping, $order->shipping_cost, 2);
            $discount = bcadd($discount, $order->discount_amount, 2);
        }
        $total = bcadd(bcsub($subtotal, $discount, 2), $shipping, 2);
        $logo = public_path('images/logo/agung-farm-logo.png');

        return [
            ...Arr::only(config('invoice'), ['company_name', 'address', 'company_phone', 'bank_name', 'bank_account', 'bank_holder']),
            'invoice_number' => 'DRAFT/FARM/'.CarbonImmutable::parse($data['invoice_date'])->format('m/Y'),
            'invoice_date' => $data['invoice_date'],
            'due_date' => $data['due_date'],
            'customer_id' => $customer->id,
            'customer_name' => $customer->name,
            'customer_phone' => $customer->phone ?: '-',
            'period_start' => $orders->min('created_at')->timezone('Asia/Jakarta')->toDateString(),
            'period_end' => $orders->max('created_at')->timezone('Asia/Jakarta')->toDateString(),
            'items' => $items,
            'subtotal' => $subtotal,
            'discount_amount' => $discount,
            'shipping_cost' => $shipping,
            'total' => $total,
            'total_words' => $this->amountInWords($total),
            'payment_ids' => $payments->modelKeys(),
            'order_numbers' => $orders->pluck('order_number')->all(),
            'logo_data_uri' => 'data:image/png;base64,'.base64_encode(file_get_contents($logo)),
        ];
    }

    private function amountInWords(string $amount): string
    {
        [$rupiah, $sen] = explode('.', bcadd($amount, '0', 2));
        $words = Str::title(Number::spell((int) $rupiah, locale: 'id')).' Rupiah';

        return (int) $sen === 0 ? $words : $words.' '.Str::title(Number::spell((int) $sen, locale: 'id')).' Sen';
    }
}

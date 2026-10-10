<?php

namespace App\Livewire\Public;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\ShippingMethod;
use App\Enums\StockReservationStatus;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Order;
use App\Models\ProductVariant;
use App\Models\StockMutation;
use App\Notifications\NewOrderForAdmins;
use App\Services\OrderFulfillmentResolver;
use App\Services\SystemFreezeGuard;
use App\Support\Quantity;
use App\Support\VariantPricing;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

#[Title('Checkout — Agung Farm & Garden')]
class Checkout extends Component
{
    use WithFileUploads;

    public const DELIVERY_LOCATIONS = [
        'SCCR',
        'Agung Putra University',
        'Sains De Resto',
        'Karenina Agung Resort',
        'Agung Farm & Garden',
    ];

    public const PICKUP_LOCATION = 'Agung Farm & Garden';

    public const PICKUP_LOCATION_MAPS_ADDRESS = 'W985+P3X, Gunungpati, Kec. Gn. Pati, Kota Semarang, Jawa Tengah';

    public const OTHER_LOCATION = 'Others';

    /** @var array<int, string> */
    public array $itemIds = [];

    public ?string $buyNowVariantId = null;

    public int $buyNowQuantity = 1;

    public string $recipientName = '';

    public string $recipientPhone = '';

    public string $shippingAddress = '';

    public string $shippingAddressOther = '';

    public string $shippingMethod = ShippingMethod::Delivery->value;

    public string $paymentMethod = 'cash';

    public string $notes = '';

    /**
     * Bukti pembayaran QRIS opsional saat checkout.
     * Customer dapat mengunggahnya dari halaman detail order jika belum tersedia.
     * OrderConfirmation::$proofFile — Cash tidak pernah memakai field ini.
     */
    public $proofFile = null;

    public function mount(): void
    {
        $this->buyNowVariantId = request()->query('variant');
        $this->buyNowQuantity = max(1, (int) request()->query('qty', 1));

        if (! $this->buyNowVariantId) {
            $this->itemIds = array_values(array_filter(explode(',', (string) request()->query('items', ''))));
        }

        $customer = auth('customer')->user();
        $this->recipientName = $customer->name;
        $this->recipientPhone = $customer->phone ?? '';
        $address = $customer->address ?? '';
        $this->shippingAddress = in_array($address, self::DELIVERY_LOCATIONS, true)
            ? $address
            : '';
    }

    public static function pickupLocationMapsUrl(): string
    {
        return 'https://www.google.com/maps/search/?api=1&query='.urlencode(self::PICKUP_LOCATION_MAPS_ADDRESS);
    }

    public function updatedShippingMethod(): void
    {
        $this->shippingAddressOther = '';

        if ($this->shippingMethod === ShippingMethod::Pickup->value) {
            $this->shippingAddress = self::PICKUP_LOCATION;

            return;
        }

        $this->shippingAddress = '';
    }

    public function updatedShippingAddress(): void
    {
        if ($this->shippingAddress !== self::OTHER_LOCATION) {
            $this->shippingAddressOther = '';
        }
    }

    /**
     * Kalau customer pindah dari QRIS ke Cash, bukti yang sempat dipilih
     * (kalau ada) dibuang supaya tidak ada file upload "nyangkut" yang tak
     * relevan lagi untuk Cash — Cash tidak pernah membutuhkan proof.
     */
    public function updatedPaymentMethod(): void
    {
        if ($this->paymentMethod !== 'qris') {
            $this->reset('proofFile');
        }
    }

    #[Computed]
    public function cartItems()
    {
        // Mode Buy Now: satu item "sementara", TIDAK pernah disimpan ke
        // tabel cart_items sama sekali — supaya tidak bisa numpuk sama
        // quantity yang kebetulan sudah ada di Cart untuk variant yang sama.
        if ($this->buyNowVariantId) {
            $variant = ProductVariant::query()
                ->with(['product.images', 'unit', 'pricePeriods', 'warehouseStocks.warehouse'])
                ->where('record_status', 'saved')
                ->where('is_active', true)
                ->whereHas('product', fn ($query) => $query
                    ->where('record_status', 'saved')
                    ->where('is_active', true))
                ->find($this->buyNowVariantId);

            if (! $variant || $variant->availableForSale() <= 0) {
                return collect();
            }

            $item = new CartItem([
                'product_variant_id' => $variant->id,
                'quantity' => min($this->buyNowQuantity, $variant->availableForSale()),
            ]);
            $item->setRelation('productVariant', $variant);

            return collect([$item]);
        }

        return Cart::forCustomer(auth('customer')->user())
            ->items()
            ->whereIn('id', $this->itemIds)
            ->with(['productVariant.product', 'productVariant.unit', 'productVariant.pricePeriods'])
            ->get();
    }

    #[Computed]
    public function subtotal(): float
    {
        return (float) $this->cartItems->sum->subtotal;
    }

    #[Computed]
    public function shippingCost(): float
    {
        return 0.0;
    }

    #[Computed]
    public function total(): float
    {
        return $this->subtotal + $this->shippingCost;
    }

    /**
     * Sama seperti CreateOrder.php punya Adam (nomor order, snapshot item,
     * reservasi stok) — bedanya di sini customer sendiri yang checkout,
     * dan sumber item-nya dari Cart yang dicentang, bukan diketik manual
     * oleh Admin.
     */
    public function placeOrder()
    {
        if ($this->shippingMethod === ShippingMethod::Pickup->value
            && $this->shippingAddress !== self::OTHER_LOCATION) {
            $this->shippingAddress = self::PICKUP_LOCATION;
        }

        $this->shippingAddressOther = trim($this->shippingAddressOther);
        $shippingAddressOptions = $this->shippingMethod === ShippingMethod::Pickup->value
            ? [self::PICKUP_LOCATION]
            : self::DELIVERY_LOCATIONS;

        $this->validate([
            'recipientName' => ['required', 'string', 'max:255'],
            'recipientPhone' => ['required', 'string', 'max:30'],
            'shippingMethod' => [
                'required',
                Rule::in(array_map(
                    fn (ShippingMethod $method): string => $method->value,
                    ShippingMethod::cases(),
                )),
            ],
            'shippingAddress' => ['required', Rule::in([
                ...$shippingAddressOptions,
                self::OTHER_LOCATION,
            ])],
            'shippingAddressOther' => [
                Rule::requiredIf($this->shippingAddress === self::OTHER_LOCATION),
                'nullable',
                'string',
                'max:1000',
            ],
            'paymentMethod' => ['required', 'in:cash,qris'],
            // QRIS proof is optional at checkout. Cash does not use this field;
            // both payment methods keep the same image validation when a file
            // is supplied, matching OrderConfirmation::submitProof().
            'proofFile' => ['nullable', 'image', 'max:2048'],
        ]);

        $shippingAddress = $this->shippingAddress === self::OTHER_LOCATION
            ? $this->shippingAddressOther
            : $this->shippingAddress;

        if ($this->cartItems->isEmpty()) {
            $this->addError('itemIds', 'There are no products selected for checkout.');

            return;
        }

        $shippingMethod = ShippingMethod::from($this->shippingMethod);
        $orderNumber = $this->generateOrderNumber();
        // Disimpan ke disk SEBELUM transaction dibuka — upload file bukan
        // operasi transactional, jadi tidak digabung ke DB::transaction di
        // bawah. Hanya terisi untuk QRIS; validate() di atas sudah memastikan
        // proofFile is optional for QRIS and remains unused for Cash.
        $proofPath = $this->paymentMethod === 'qris' && $this->proofFile !== null
            ? $this->proofFile->storeAs(
                'payment-proofs',
                $orderNumber.'.'.$this->proofFile->getClientOriginalExtension(),
                'public',
            )
            : null;

        $freezeGuard = app(SystemFreezeGuard::class);
        $reservationIsPending = $freezeGuard->isFrozen();

        $order = $freezeGuard->runPublicOrderCreation(function () use (
            $orderNumber,
            $proofPath,
            $shippingMethod,
            $shippingAddress,
            $reservationIsPending,
        ): Order {
            return DB::transaction(function () use (
                $orderNumber,
                $proofPath,
                $shippingMethod,
                $shippingAddress,
                $reservationIsPending,
            ): Order {
                $customer = auth('customer')->user();

                $variantIds = $this->cartItems->pluck('productVariant.id');

                $variants = ProductVariant::query()
                    ->with(['product', 'unit', 'pricePeriods'])
                    ->whereIn('id', $variantIds)
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('id');

                $subtotal = '0';
                $lines = [];

                foreach ($this->cartItems as $cartItem) {
                    $variant = $variants->get($cartItem->product_variant_id);
                    $quantity = $cartItem->quantity;

                    if (! $variant) {
                        throw ValidationException::withMessages([
                            'itemIds' => 'One of the selected products is no longer available.',
                        ]);
                    }

                    $lineSubtotal = VariantPricing::subtotal($variant->effective_price, $quantity, $variant->price_quantity);
                    $subtotal = bcadd($subtotal, $lineSubtotal, 2);

                    $lines[] = ['variant' => $variant, 'quantity' => $quantity, 'subtotal' => $lineSubtotal];
                }

                $fulfillmentWarehouse = app(OrderFulfillmentResolver::class)->resolveForLines($lines);

                if (! $fulfillmentWarehouse) {
                    throw ValidationException::withMessages([
                        'itemIds' => 'The selected products are not available in the POS warehouse.',
                    ]);
                }

                $shippingCost = (string) $this->shippingCost;
                $total = bcadd($subtotal, $shippingCost, 2);

                $order = Order::query()->create([
                    'order_number' => $orderNumber,
                    'customer_id' => $customer->id,
                    'fulfillment_warehouse_id' => $fulfillmentWarehouse->id,
                    'status' => OrderStatus::WaitingPayment,
                    'stock_reservation_status' => $reservationIsPending
                        ? StockReservationStatus::Pending
                        : StockReservationStatus::Reserved,
                    'shipping_method' => $shippingMethod,
                    'payment_method' => $this->paymentMethod,
                    'recipient_name' => $this->recipientName,
                    'recipient_phone' => $this->recipientPhone,
                    'shipping_address' => $shippingAddress,
                    'shipping_cost' => $shippingCost,
                    'subtotal' => $subtotal,
                    'total' => $total,
                    'notes' => $this->notes ?: null,
                ]);

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

                    if (! $reservationIsPending) {
                        StockMutation::reserveForOrder(
                            $variant,
                            $line['quantity'],
                            $orderItem->id,
                            null,
                            $fulfillmentWarehouse,
                        );
                    }
                }

                $order->runDuringCreation(function () use ($order, $proofPath, $total): void {
                    if ($this->paymentMethod === 'qris' && $proofPath !== null) {
                        $payment = $order->payment()->create([
                            'method' => $this->paymentMethod,
                            'amount' => $total,
                            'status' => PaymentStatus::WaitingConfirm,
                        ]);

                        $payment->submitProof($proofPath);
                        $order->markWaitingConfirmation();
                    } elseif ($this->paymentMethod === 'qris') {
                        $order->initializeUnpaidPayment();
                    } else {
                        $order->initializeCashPayment();
                    }
                });

                // Item yang barusan di-checkout dihapus dari Cart — TAPI cuma
                // kalau ini beneran dari Cart. Mode Buy Now tidak pernah
                // nyentuh tabel Cart sama sekali, jadi tidak ada yang perlu
                // dihapus.
                if (! $this->buyNowVariantId) {
                    CartItem::query()->whereIn('id', $this->itemIds)->delete();
                }

                return $order;
            });
        });

        NewOrderForAdmins::send($order);

        return redirect()->route('orders.show', $order);
    }

    private function generateOrderNumber(): string
    {
        $prefix = 'ORD-'.now()->format('Ymd').'-';

        $lastNumber = Order::query()
            ->withTrashed()
            ->where('order_number', 'like', $prefix.'%')
            ->orderByDesc('order_number')
            ->value('order_number');

        $next = $lastNumber ? ((int) Str::afterLast($lastNumber, '-')) + 1 : 1;

        return $prefix.Str::padLeft((string) $next, 3, '0');
    }

    public function render(): View
    {
        return view('livewire.public.checkout');
    }
}

<?php

namespace App\Filament\Pages;

use App\Enums\CustomerType;
use App\Enums\PaymentMethod;
use App\Enums\ShippingLocation;
use App\Enums\ShippingMethod;
use App\Filament\Forms\Components\RupiahInput;
use App\Filament\Resources\Customers\Schemas\CustomerForm;
use App\Filament\Support\Rupiah;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ShippingSetting;
use App\Models\Warehouse;
use App\Services\AdminOrderCreationService;
use App\Services\SystemFreezeGuard;
use App\Support\Quantity;
use App\Support\VariantPricing;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\WithPagination;

class Pos extends Page
{
    use WithPagination;

    protected static ?string $title = 'POS';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedComputerDesktop;

    protected static string|\UnitEnum|null $navigationGroup = 'Operations';

    protected string $view = 'filament.pages.pos';

    public string $search = '';

    public string $category = '';

    /** @var array<string, string> */
    #[Locked]
    public array $cart = [];

    /** @var array<string, string> */
    #[Locked]
    public array $prices = [];

    /** @var array<string, string> */
    #[Locked]
    public array $priceQuantities = [];

    #[Locked]
    public string $checkoutToken;

    /** @var array{customer_id: string, recipient_name: ?string, recipient_phone: ?string, backdate_enabled?: bool, backdate_date?: ?string}|array{} */
    #[Locked]
    public array $customerData = [];

    #[Locked]
    public ?string $lastOrderNumber = null;

    #[Locked]
    public ?string $lastPaymentStatus = null;

    public function mount(): void
    {
        $this->checkoutToken = (string) Str::uuid7();
    }

    #[Computed]
    public function selectedCustomer(): ?Customer
    {
        return Customer::query()->saved()->where('is_active', true)->find($this->customerData['customer_id'] ?? null);
    }

    private function validateCustomer(): void
    {
        unset($this->selectedCustomer);

        if ($this->selectedCustomer === null) {
            throw ValidationException::withMessages(['customer' => 'Select a customer before checkout.']);
        }
    }

    public static function canAccess(): bool
    {
        return Gate::allows('create', Order::class) && Gate::allows('Verify:Payment');
    }

    public function getMaxContentWidth(): Width|string|null
    {
        return Width::Full;
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedCategory(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function products(): LengthAwarePaginator
    {
        return Product::query()->saved()->where('is_active', true)
            ->with(['generalImages', 'variants' => fn (HasMany $query) => $query->inventoryVisible()->orderBy('name')->orderBy('id')->with(['pricePeriods', 'unit', 'warehouseStocks.warehouse'])])
            ->withExists(['variants as has_available_stock' => fn (Builder $query) => $query
                ->inventoryVisible()
                ->where(fn (Builder $query) => $query
                    ->whereHas('warehouseStocks', fn (Builder $query) => $query
                        ->where('stock_available', '>', 0)
                        ->whereHas('warehouse', fn (Builder $query) => $query->saved()->where('is_active', true)->where('is_pos', true)))
                    ->when(Warehouse::defaultFulfillment() !== null, fn (Builder $query) => $query
                        ->orWhere(fn (Builder $query) => $query->whereDoesntHave('warehouseStocks')->where('stock_available', '>', 0))))])
            ->when($this->search !== '', fn (Builder $query) => $query->whereLike('name', '%'.trim($this->search).'%'))
            ->when($this->category !== '', fn (Builder $query) => $query->where('category_id', $this->category))
            ->orderByDesc('has_available_stock')->orderBy('name')->orderBy('id')->paginate(24);
    }

    #[Computed]
    public function categories(): Collection
    {
        return Category::query()->saved()->where('is_active', true)->orderBy('name')->pluck('name', 'id');
    }

    #[Computed]
    public function cartLines(): Collection
    {
        $variants = ProductVariant::query()->inventoryVisible()
            ->with(['product', 'unit', 'pricePeriods', 'warehouseStocks.warehouse'])
            ->whereIn('id', array_keys($this->cart))->get()->keyBy('id');

        return collect($this->cart)->map(function (string $quantity, string $id) use ($variants): array {
            $variant = $variants->get($id);
            $price = $this->prices[$id] ?? '0';

            return [
                'id' => $id,
                'name' => $variant?->product?->name ?? 'Unavailable product',
                'variant' => $variant?->name ?? 'Remove this item to continue.',
                'quantity' => $quantity,
                'price' => $price,
                'price_quantity' => $this->priceQuantities[$id] ?? '1',
                'unit' => $variant?->unit?->code,
                'subtotal' => VariantPricing::subtotal($price, $quantity, $this->priceQuantities[$id] ?? '1'),
                'stock' => $variant?->availableForSale() ?? 0,
            ];
        });
    }

    #[Computed]
    public function subtotal(): string
    {
        return $this->cartLines->reduce(fn (string $total, array $line): string => bcadd($total, $line['subtotal'], 2), '0.00');
    }

    /** @param list<array{id: string, quantity: int|string, price: string, price_quantity?: string}> $items */
    public function openCheckout(array $items): void
    {
        abort_unless(static::canAccess(), 403);
        app(SystemFreezeGuard::class)->assertNotFrozen();
        Validator::make(['items' => $items], [
            'items' => ['required', 'array', 'min:1', 'max:500'],
            'items.*' => ['required', 'array'],
        ])->validate();
        foreach ($items as &$item) {
            $item['quantity'] = Quantity::normalize($item['quantity'] ?? null);
            $item['price_quantity'] = Quantity::normalize($item['price_quantity'] ?? '1');
        }
        unset($item);
        Validator::make(['items' => $items], [
            'items' => ['required', 'array', 'min:1', 'max:500'],
            'items.*.id' => ['required', 'uuid', 'distinct'],
            'items.*.quantity' => ['required', ...Quantity::rules()],
            'items.*.price_quantity' => ['required', ...Quantity::rules()],
            'items.*.price' => ['required', 'numeric', 'min:0', 'max:999999999999', 'decimal:0,2'],
        ])->validate();

        $variants = ProductVariant::query()->inventoryVisible()
            ->with(['pricePeriods', 'warehouseStocks.warehouse'])
            ->whereIn('id', array_column($items, 'id'))->get()->keyBy('id');

        foreach ($items as $item) {
            $variant = $variants->get($item['id']);
            if (! $variant || $item['quantity'] > $variant->availableForSale()) {
                throw ValidationException::withMessages(['items' => 'Some items are unavailable or exceed current stock. Update your cart and try again.']);
            }
        }

        $cart = collect($items)->mapWithKeys(fn (array $item): array => [$item['id'] => Quantity::add($item['quantity'], 0)])->all();
        $prices = collect($items)->mapWithKeys(fn (array $item): array => [$item['id'] => bcadd((string) $item['price'], '0', 2)])->all();
        $priceQuantities = collect($items)->mapWithKeys(fn (array $item): array => [$item['id'] => Quantity::add($item['price_quantity'], 0)])->all();
        if ($cart !== $this->cart || $prices !== $this->prices || $priceQuantities !== $this->priceQuantities) {
            $this->cart = $cart;
            $this->prices = $prices;
            $this->priceQuantities = $priceQuantities;
            $this->cartChanged();
        }

        try {
            $this->validateCustomer();
        } catch (ValidationException) {
            $this->resetValidation('customer');
            $this->mountAction('customer');

            return;
        }

        $this->mountAction('checkout');
    }

    public function chooseProduct(string $id): void
    {
        abort_unless(static::canAccess(), 403);
        $variants = ProductVariant::query()->inventoryVisible()->where('product_id', $id)->get();

        if ($variants->count() === 1) {
            $this->addVariant($variants->first()->id);

            return;
        }

        if ($variants->isNotEmpty()) {
            $this->mountAction('chooseVariant', ['product' => $id]);
        }
    }

    public function chooseVariantAction(): Action
    {
        return Action::make('chooseVariant')->label('Add to cart')->color('success')->modalHeading('Choose a variant')
            ->modalSubmitActionLabel('Add to cart')
            ->schema([
                Select::make('variant')->label('Variant')->required()->options(function (): array {
                    $arguments = $this->getMountedAction()?->getArguments() ?? [];

                    return ProductVariant::query()->inventoryVisible()->where('product_id', $arguments['product'] ?? '')
                        ->with(['pricePeriods', 'warehouseStocks.warehouse'])->orderBy('name')->orderBy('id')->get()
                        ->filter(fn (ProductVariant $variant): bool => $variant->availableForSale() > 0)
                        ->mapWithKeys(fn (ProductVariant $variant): array => [$variant->id => $variant->name.' · '.Rupiah::formatWithPrefix($variant->effective_price).' · Stock: '.$variant->availableForSale()])
                        ->all();
                }),
            ])->action(fn (array $data) => $this->addVariant($data['variant']));
    }

    public function addVariant(string $id): void
    {
        abort_unless(static::canAccess(), 403);
        $variant = ProductVariant::query()->inventoryVisible()->with(['pricePeriods', 'warehouseStocks.warehouse'])->find($id);
        $quantity = Quantity::add($this->cart[$id] ?? 0, 1);

        if (! $variant || $variant->availableForSale() <= 0 || (isset($this->cart[$id]) && $quantity > $variant->availableForSale())) {
            Notification::make()->title('Not enough stock available.')->warning()->send();

            return;
        }

        $this->cart[$id] = $quantity;
        $this->prices[$id] ??= $variant->effective_price;
        $this->priceQuantities[$id] ??= $variant->price_quantity;
        $this->cartChanged();
    }

    public function setQuantity(string $id, mixed $quantity): void
    {
        abort_unless(static::canAccess(), 403);
        $quantity = Quantity::validated($quantity);

        if (! isset($this->cart[$id])) {
            return;
        }

        $variant = ProductVariant::query()->inventoryVisible()->with('warehouseStocks.warehouse')->find($id);

        if (! $variant || $quantity > $variant->availableForSale()) {
            Notification::make()->title('Not enough stock available.')->warning()->send();

            return;
        }

        $this->cart[$id] = $quantity;
        $this->cartChanged();
    }

    public function removeItem(string $id): void
    {
        abort_unless(static::canAccess(), 403);
        unset($this->cart[$id], $this->prices[$id], $this->priceQuantities[$id]);
        $this->cartChanged();
    }

    public function clearCart(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->cart = $this->prices = $this->priceQuantities = [];
        $this->cartChanged();
    }

    private function cartChanged(): void
    {
        $this->checkoutToken = (string) Str::uuid7();
        $this->resetErrorBag();
        unset($this->cartLines, $this->subtotal);
    }

    public function customerAction(): Action
    {
        return Action::make('customer')
            ->label(fn (): string => $this->selectedCustomer?->name ?? 'Customer')
            ->icon(Heroicon::OutlinedUser)
            ->modalHeading('Customer')
            ->modalSubmitActionLabel('Save Customer')
            ->authorize(fn (): bool => static::canAccess())
            ->fillForm(fn (): array => $this->customerData)
            ->schema([
                Select::make('customer_id')->label('Customer')->searchable()->required()->live()
                    ->options(fn (): array => Customer::query()->saved()->where('is_active', true)->orderBy('name')->get()
                        ->mapWithKeys(fn (Customer $customer): array => [$customer->id => $customer->name.' ('.$customer->customer_type->getLabel().')'])->all())
                    ->createOptionForm(fn (Schema $schema): Schema => CustomerForm::configure($schema->model(Customer::class)))
                    ->createOptionAction(fn (Action $action): Action => $action
                        ->label('Create customer')->button()->modalHeading('Create customer')
                        ->authorize(fn (): bool => static::canAccess() && Gate::allows('create', Customer::class)))
                    ->createOptionUsing(function (array $data): string {
                        abort_unless(static::canAccess(), 403);
                        Gate::authorize('create', Customer::class);

                        try {
                            return Customer::query()->create($data)->getKey();
                        } catch (ValidationException $exception) {
                            $statePath = $this->getMountedActionSchema()->getStatePath();

                            throw ValidationException::withMessages(collect($exception->errors())
                                ->mapWithKeys(fn (array $messages, string $key): array => [$statePath.'.'.$key => $messages])->all());
                        }
                    })
                    ->afterStateUpdated(function (Set $set, ?string $state): void {
                        $customer = Customer::query()->saved()->where('is_active', true)->find($state);
                        $set('recipient_name', $customer?->name);
                        $set('recipient_phone', $customer?->phone);

                    }),
                TextInput::make('recipient_name')->label('Recipient Name')->maxLength(255),
                TextInput::make('recipient_phone')->label('Recipient Phone')->maxLength(30),
                Checkbox::make('backdate_enabled')->label('Backdate')->live()
                    ->afterStateUpdated(fn (Set $set, bool $state) => $state ? null : $set('backdate_date', null)),
                DatePicker::make('backdate_date')->label('Transaction Date')
                    ->required(fn (Get $get): bool => (bool) $get('backdate_enabled'))
                    ->visible(fn (Get $get): bool => (bool) $get('backdate_enabled'))
                    ->maxDate(now('Asia/Jakarta')->subDay()->toDateString())
                    ->rules(['date_format:Y-m-d', 'before:'.today('Asia/Jakarta')->toDateString()]),
            ])
            ->action(function (array $data): void {
                abort_unless(static::canAccess(), 403);
                $this->customerData = Arr::only($data, ['customer_id', 'recipient_name', 'recipient_phone', 'backdate_enabled', 'backdate_date']);
                unset($this->selectedCustomer);
                $this->cartChanged();
            });
    }

    public function checkoutAction(): Action
    {
        return Action::make('checkout')->label('Checkout')->icon(Heroicon::OutlinedCreditCard)
            ->disabled(fn (): bool => $this->cart === [] || $this->customerData === [] || app(SystemFreezeGuard::class)->isFrozen())
            ->beforeFormFilled(fn () => $this->validateCustomer())
            ->modalHeading('Checkout')->modalWidth(Width::TwoExtraLarge)
            ->modalSubmitActionLabel(fn (): string => ($this->mountedActions[0]['data']['payment_method'] ?? null) === PaymentMethod::Invoice->value
                ? 'Create Invoice Order'
                : 'Create Order')
            ->fillForm([
                'shipping_method' => ShippingMethod::Pickup->value,
                'shipping_address' => ShippingLocation::AgungFarmAndGarden->value,
                'shipping_cost' => '0',
                'payment_method' => PaymentMethod::Cash->value,
                'print_receipt' => true,
                'discount_percent' => '0',
                'discount_amount' => '0',
            ])
            ->schema([
                Placeholder::make('customer_summary')->label('Customer')
                    ->content(fn (): string => $this->selectedCustomer?->name ?? '-'),
                Placeholder::make('recipient_name_summary')->label('Recipient Name')
                    ->content(fn (): string => filled($this->customerData['recipient_name'] ?? null) ? $this->customerData['recipient_name'] : '-'),
                Placeholder::make('recipient_phone_summary')->label('Recipient Phone')
                    ->content(fn (): string => filled($this->customerData['recipient_phone'] ?? null) ? $this->customerData['recipient_phone'] : '-'),
                Radio::make('shipping_method')->label('Shipping Method')->options(collect(ShippingMethod::cases())->mapWithKeys(fn (ShippingMethod $method): array => [$method->value => $method->getLabel()])->all())->inline()->required()->live()
                    ->afterStateUpdated(function (Set $set, string $state): void {
                        $pickup = $state === ShippingMethod::Pickup->value;
                        $set('shipping_address', $pickup ? ShippingLocation::AgungFarmAndGarden->value : null);
                        $set('shipping_address_other', null);
                        $set('shipping_cost', $pickup ? '0' : Rupiah::format(ShippingSetting::current()->flat_shipping_cost));
                    }),
                Select::make('shipping_address')->label('Shipping Address')->required()->live()
                    ->options(fn (Get $get): array => $get('shipping_method') === ShippingMethod::Pickup->value
                        ? [ShippingLocation::AgungFarmAndGarden->value => ShippingLocation::AgungFarmAndGarden->value, 'Others' => 'Others']
                        : ShippingLocation::options() + ['Others' => 'Others']),
                Textarea::make('shipping_address_other')->label('Other Shipping Address')->maxLength(1000)
                    ->visible(fn (Get $get): bool => $get('shipping_address') === 'Others')
                    ->required(fn (Get $get): bool => $get('shipping_address') === 'Others'),
                RupiahInput::make('shipping_cost')->label('Shipping Cost')->required()->rules(['min:0'])->live(onBlur: true)
                    ->disabled(fn (Get $get): bool => $get('shipping_method') === ShippingMethod::Pickup->value)->dehydrated(),
                Radio::make('payment_method')->label('Payment Method')
                    ->options(fn (Get $get): array => collect(PaymentMethod::cases())
                        ->reject(fn (PaymentMethod $method): bool => $method === PaymentMethod::Invoice
                            && ! Customer::query()->saved()->where('is_active', true)->where('customer_type', CustomerType::Invoice)->whereKey($this->customerData['customer_id'] ?? null)->exists())
                        ->mapWithKeys(fn (PaymentMethod $method): array => [$method->value => $method->getLabel()])->all())
                    ->inline()->required()->live()
                    ->afterStateUpdated(fn (Set $set) => $set('payment_received', false)),
                View::make('filament.forms.components.company-bank-transfer')
                    ->visible(fn (Get $get): bool => $get('payment_method') === PaymentMethod::BankTransfer->value),
                View::make('filament.forms.components.company-qris')
                    ->visible(fn (Get $get): bool => $get('payment_method') === PaymentMethod::Qris->value),
                FileUpload::make('payment_proof')->label(fn (Get $get): string => 'Payment Proof ('.(PaymentMethod::tryFrom((string) $get('payment_method'))?->getLabel() ?? '').')')->image()->maxSize(5120)
                    ->disk('public')->directory('payment-proofs')->storeFiles(false)
                    ->visible(fn (Get $get): bool => (PaymentMethod::tryFrom((string) $get('payment_method'))?->requiresProof() ?? false))
                    ->required(fn (Get $get): bool => (PaymentMethod::tryFrom((string) $get('payment_method'))?->requiresProof() ?? false) && (bool) $get('payment_received')),
                Textarea::make('notes')->label('Notes')->rows(2)->maxLength(2000),
                Placeholder::make('subtotal')->content(fn (): string => Rupiah::formatWithPrefix($this->subtotal)),
                TextInput::make('discount_percent')->label('Discount (%)')->suffix('%')
                    ->numeric()->minValue(0)->maxValue(100)->rules(['decimal:0,2'])->required()->live(onBlur: true)
                    ->helperText('Applied to the product subtotal, before shipping.')
                    ->afterStateUpdated(function (Set $set, mixed $state): void {
                        if (preg_match('/^\d+(?:\.\d{1,2})?$/', (string) $state) !== 1 || bccomp((string) $state, '100', 2) > 0) {
                            return;
                        }

                        $set('discount_amount', Rupiah::format(bcround(bcdiv(bcmul($this->subtotal, (string) $state, 4), '100', 4), 2)));
                    }),
                RupiahInput::make('discount_amount')->label('Discount Amount')->required()
                    ->rules(fn (): array => ['decimal:0,2', 'min:0', 'max:'.$this->subtotal])->live(onBlur: true)
                    ->afterStateUpdated(function (Set $set, mixed $state): void {
                        $amount = Rupiah::normalize($state);

                        if (preg_match('/^\d+(?:\.\d{1,2})?$/', $amount ?? '') !== 1) {
                            return;
                        }

                        $set('discount_percent', bccomp($this->subtotal, '0', 2) === 0 ? '0.00'
                            : bcround(bcdiv(bcmul($amount, '100', 2), $this->subtotal, 4), 2));
                    }),
                Placeholder::make('total')->content(function (Get $get): string {
                    $discount = Rupiah::normalize($get('discount_amount')) ?? '0';
                    $discount = preg_match('/^\d+(?:\.\d{1,2})?$/', $discount) === 1 ? $discount : '0';

                    return Rupiah::formatWithPrefix(bcadd(bcsub($this->subtotal, $discount, 2), Rupiah::normalize($get('shipping_cost')) ?? '0', 2));
                }),
                Checkbox::make('print_receipt')->label('Print Receipt')->default(true)
                    ->helperText("Print after successful payment using this device's Printer Settings.")
                    ->visible(fn (Get $get): bool => Gate::allows('View:Payment') && $get('payment_method') !== PaymentMethod::Invoice->value)->dehydratedWhenHidden(false),
                Checkbox::make('payment_received')->label('I confirm that payment has been received in full.')
                    ->visible(fn (Get $get): bool => $get('payment_method') !== PaymentMethod::Invoice->value)
                    ->default(false)->live()
                    ->dehydratedWhenHidden(false),
            ])->action(function (array $data): void {
                $this->completeCheckout($data);
            });
    }

    /** @param array<string, mixed> $data */
    private function completeCheckout(array $data): void
    {
        abort_unless(static::canAccess(), 403);
        app(SystemFreezeGuard::class)->assertNotFrozen();

        if ($this->cart === []) {
            return;
        }

        $data['items'] = collect($this->cart)->map(fn (string $quantity, string $id): array => ['product_variant_id' => $id, 'quantity' => $quantity])->values()->all();
        $data['pos_checkout_token'] = $this->checkoutToken;

        if ($data['shipping_method'] === ShippingMethod::Pickup->value) {
            $data['shipping_cost'] = '0';
        }

        $proof = null;

        try {
            $this->validateCustomer();
            $data = array_replace($data, $this->customerData);
            $order = Cache::lock('pos-checkout:'.$this->checkoutToken, 30)->block(5, function () use ($data, &$proof): Order {
                if ($existing = Order::withTrashed()->where('pos_checkout_token', $this->checkoutToken)->first()) {
                    abort_unless($existing->created_by === auth()->id(), 403);

                    return $existing;
                }

                if (PaymentMethod::from($data['payment_method'])->requiresProof() && filled($data['payment_proof'] ?? null)) {
                    $proof = $data['payment_proof']->store('payment-proofs', 'public');
                    $data['payment_proof'] = $proof;
                } else {
                    unset($data['payment_proof']);
                }

                return app(AdminOrderCreationService::class)->create($data,
                    confirmPayment: $data['payment_method'] !== PaymentMethod::Invoice->value && (bool) ($data['payment_received'] ?? false),
                    expectedPrices: $this->prices,
                    expectedPriceQuantities: $this->priceQuantities,
                );
            });
        } catch (ValidationException $exception) {
            if (isset($exception->errors()['prices'])) {
                $variants = ProductVariant::query()->inventoryVisible()->with('pricePeriods')->whereIn('id', array_keys($this->cart))->get();
                $this->prices = $variants->mapWithKeys(fn (ProductVariant $variant): array => [$variant->id => $variant->effective_price])->all();
                $this->priceQuantities = $variants->mapWithKeys(fn (ProductVariant $variant): array => [$variant->id => $variant->price_quantity])->all();
                unset($this->cartLines, $this->subtotal);
                $discountAmount = (string) ($data['discount_amount'] ?? '0');
                $this->mountedActions[0]['data']['discount_percent'] = bccomp($this->subtotal, '0', 2) === 0 ? '0.00'
                    : bcround(bcdiv(bcmul($discountAmount, '100', 2), $this->subtotal, 4), 2);
                $this->dispatch('pos-prices-updated', prices: $this->prices, priceQuantities: $this->priceQuantities);
                $this->mountedActions[0]['data']['payment_received'] = false;
                $this->forceRender();
            }

            Notification::make()->title('Checkout needs your attention')->body(collect($exception->errors())->flatten()->implode(' '))->warning()->send();
            throw ValidationException::withMessages(collect($exception->errors())->mapWithKeys(fn (array $messages, string $key): array => ['mountedActions.0.data.'.$key => $messages])->all());
        } catch (\Throwable $exception) {
            report($exception);
            Notification::make()->title('Checkout could not be completed')->body('Your cart has been kept. Please try again.')->danger()->send();

            throw ValidationException::withMessages(['mountedActions.0.data.payment_received' => 'Checkout could not be completed. Please try again.']);
        } finally {
            if ($proof) {
                Storage::disk('public')->delete($proof);
            }
        }

        if (($data['print_receipt'] ?? false) && $order->isPaymentConfirmed() && Gate::allows('view', $order->payment)) {
            $this->dispatch('farming-print-receipt',
                receiptUrl: route('admin.payments.receipt', $order->payment),
                jobUrl: route('admin.payments.print', $order->payment),
                certificateUrl: route('admin.payments.print-certificate', $order->payment),
                rawbtUrl: route('admin.payments.rawbt', $order->payment),
            );
        }

        $this->lastOrderNumber = $order->order_number;
        $this->lastPaymentStatus = $order->paymentStageLabel();
        $this->clearCart();
        $this->customerData = [];
        unset($this->selectedCustomer);
        $this->dispatch('pos-cart-cleared');
        unset($this->products);
        Notification::make()->title('Order '.$order->order_number.' created')
            ->body('Payment: '.$order->paymentStageLabel().' · Order: '.$order->adminStatusLabel())
            ->success()->send();
    }
}

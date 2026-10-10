<?php

namespace App\Filament\Resources\DirectOrders\Schemas;

use App\Enums\RecordStatus;
use App\Filament\Forms\Components\QuantityInput;
use App\Filament\Forms\Components\RupiahInput;
use App\Filament\Support\Rupiah;
use App\Models\DirectOrder;
use App\Models\DirectOrderItem;
use App\Models\Item;
use App\Models\User;
use App\Models\Vendor;
use App\Models\Warehouse;
use App\Support\Quantity;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;

class DirectOrderForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make('Direct Order Information')->columns(2)->schema([
                TextInput::make('do_number')
                    ->label('DO Number')
                    ->disabled()
                    ->dehydrated(false)
                    ->placeholder('Generated when saved'),
                DatePicker::make('do_date')
                    ->label('DO Date')
                    ->displayFormat('d/m/Y')
                    ->placeholder('dd/mm/yyyy')
                    ->default(now())
                    ->required(),
                Select::make('payment_by')
                    ->label('Payment By')
                    ->options(['Holding' => 'Holding', 'Farming' => 'Farming'])
                    ->required(),
                TextInput::make('reason')->required()->maxLength(255),
                Select::make('warehouse_id')
                    ->label('Location')
                    ->options(fn (): array => Warehouse::query()
                        ->saved()
                        ->where('is_active', true)
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all())
                    ->searchable()
                    ->live()
                    ->afterStateUpdated(function (Get $get, Set $set): void {
                        $set('items', self::candidateRows($get('warehouse_id'), $get('item_category') ?? 'critical'));
                        self::updateAmounts($get, $set);
                    })
                    ->required(),
                Select::make('vendor_id')
                    ->label('Vendor')
                    ->options(fn (): array => Vendor::query()
                        ->saved()
                        ->where('is_active', true)
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all())
                    ->searchable()
                    ->required(),
                Select::make('requested_by')
                    ->label('Requested By')
                    ->options(fn (): array => User::query()
                        ->saved()
                        ->where('is_active', true)
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all())
                    ->default(fn (): ?string => auth('web')->id())
                    ->searchable()
                    ->required(),
                Textarea::make('notes')->rows(2)->columnSpanFull(),
            ]),
            ToggleButtons::make('item_category')
                ->hiddenLabel()
                ->options(['critical' => 'Critical', 'not_critical' => 'Not Critical'])
                ->default('critical')
                ->grouped()
                ->live()
                ->dehydrated(false)
                ->afterStateUpdated(function (Get $get, Set $set, string $state): void {
                    self::updateCandidates($get, $set, $state);
                })
                ->extraAttributes(['class' => 'purchase-request-filter-tabs'])
                ->visible(fn (Get $get): bool => filled($get('warehouse_id')))
                ->columnSpanFull(),
            TextInput::make('item_search')
                ->hiddenLabel()
                ->placeholder('Search items...')
                ->prefixIcon(Heroicon::MagnifyingGlass)
                ->live(debounce: 500)
                ->dehydrated(false)
                ->afterStateUpdated(function (Get $get, Set $set): void {
                    self::updateCandidates($get, $set, $get('item_category') ?? 'critical');
                })
                ->visible(fn (Get $get): bool => filled($get('warehouse_id')))
                ->columnSpanFull(),
            Repeater::make('items')
                ->hiddenLabel()
                ->visible(fn (Get $get): bool => filled($get('warehouse_id')))
                ->schema([
                    Hidden::make('item_id')->required(),
                    Checkbox::make('selected')
                        ->label('')
                        ->default(fn (?DirectOrderItem $record): bool => $record !== null)
                        ->live()
                        ->afterStateUpdated(function (bool $state, Get $get, Set $set): void {
                            if ($state && blank($get('quantity'))) {
                                $set('quantity', '1.00');
                            }
                        }),
                    TextEntry::make('item_label')
                        ->label('Item')
                        ->placeholder('-')
                        ->extraAttributes(['class' => 'purchase-request-item-name']),
                    TextInput::make('unit')->label('Unit')->disabled()->dehydrated(false),
                    QuantityInput::make('available')->label('Available')->disabled()->dehydrated(false),
                    QuantityInput::make('critical')->label('Critical')->disabled()->dehydrated(false),
                    TextEntry::make('status')
                        ->label('Status')
                        ->formatStateUsing(fn (mixed $state): HtmlString => new HtmlString(sprintf(
                            '<span class="purchase-request-status-badge purchase-request-status-badge--%s">%s</span>',
                            $state === 'OK' ? 'ok' : 'critical',
                            e((string) ($state ?? 'Critical')),
                        ))),
                    QuantityInput::make('quantity')
                        ->label('Qty')
                        ->live()
                        ->visible(fn (Get $get): bool => (bool) $get('selected'))
                        ->required(fn (Get $get): bool => (bool) $get('selected'))
                        ->minValue(0.001)
                        ->afterStateUpdated(fn (Get $get, Set $set): mixed => self::updateLineTotal($get, $set)),
                    RupiahInput::make('unit_price')
                        ->label('Unit Price')
                        ->live()
                        ->visible(fn (Get $get): bool => (bool) $get('selected'))
                        ->required(fn (Get $get): bool => (bool) $get('selected'))
                        ->minValue(0)
                        ->afterStateUpdated(fn (Get $get, Set $set): mixed => self::updateLineTotal($get, $set)),
                    RupiahInput::make('total_price')
                        ->label('Total Price')
                        ->readOnly()
                        ->dehydrated()
                        ->visible(fn (Get $get): bool => (bool) $get('selected')),
                    TextInput::make('notes')
                        ->label('Notes')
                        ->visible(fn (Get $get): bool => (bool) $get('selected')),
                ])
                ->table([
                    TableColumn::make('Selected')->hiddenHeaderLabel()->width('3rem'),
                    TableColumn::make('Item')->width('18rem'),
                    TableColumn::make('Unit')->width('6rem'),
                    TableColumn::make('Available')->width('7rem'),
                    TableColumn::make('Critical')->width('7rem'),
                    TableColumn::make('Status')->width('7rem'),
                    TableColumn::make('Qty')->width('8rem'),
                    TableColumn::make('Unit Price')->width('12rem'),
                    TableColumn::make('Total Price')->width('12rem'),
                    TableColumn::make('Notes')->width('10rem'),
                ])
                ->default(fn (Get $get): array => self::candidateRows($get('warehouse_id'), 'critical'))
                ->live()
                ->afterStateUpdated(fn (Get $get, Set $set): mixed => self::updateAmounts($get, $set))
                ->minItems(0)
                ->addable(false)
                ->deletable(false)
                ->reorderable(false)
                ->extraAttributes(fn (Get $get): array => [
                    'class' => 'purchase-request-items-table direct-order-checklist direct-order-checklist--'.($get('item_category') ?? 'critical'),
                ])
                ->columnSpanFull(),
            Section::make('Amounts & Terms')->columns(2)->schema([
                RupiahInput::make('subtotal')->default('0')->readOnly()->dehydrated(),
                RupiahInput::make('tax_amount')
                    ->default('0')
                    ->minValue(0)
                    ->live()
                    ->afterStateUpdated(fn (Get $get, Set $set): mixed => self::updateAmounts($get, $set)),
                RupiahInput::make('discount_amount')
                    ->default('0')
                    ->minValue(0)
                    ->maxValue(fn (Get $get): float => (float) (Rupiah::normalize($get('subtotal')) ?? 0))
                    ->live()
                    ->afterStateUpdated(fn (Get $get, Set $set): mixed => self::updateAmounts($get, $set)),
                RupiahInput::make('total_amount')->default('0')->readOnly()->dehydrated(),
                Textarea::make('terms_conditions')->label('Terms & Conditions')->rows(4)->columnSpanFull(),
                FileUpload::make('transaction_proof')
                    ->label('Transaction Proof')
                    ->multiple(false)
                    ->maxFiles(1)
                    ->disk('public')
                    ->directory('direct-order-proofs')
                    ->acceptedFileTypes(['image/jpeg', 'image/png', 'application/pdf'])
                    ->maxSize(4096)
                    ->required()
                    ->columnSpanFull()
                    ->helperText('Allowed: JPG, PNG, JPEG, PDF. Max size: 4 MB.'),
            ]),
        ]);
    }

    /** @return list<array<string, mixed>> */
    private static function candidateRows(?string $warehouseId, string $category, ?string $search = null): array
    {
        if (blank($warehouseId)) {
            return [];
        }

        $query = Item::query()
            ->where('record_status', RecordStatus::Saved)
            ->where('is_active', true)
            ->where('purchasable', true)
            ->where('initial_warehouse_id', $warehouseId);

        if ($category === 'critical') {
            $query->whereColumn('initial_stock', '<', 'min_stock');
        } else {
            $query->whereColumn('initial_stock', '>=', 'min_stock');
        }

        if (filled($search)) {
            $search = mb_strtolower(trim($search));
            $query->where(fn (Builder $query): Builder => $query
                ->whereRaw('LOWER(name) LIKE ?', ["%{$search}%"])
                ->orWhereRaw('LOWER(code) LIKE ?', ["%{$search}%"])
                ->orWhereRaw('LOWER(sku) LIKE ?', ["%{$search}%"]));
        }

        return $query->with('unit')
            ->orderBy('name')
            ->get()
            ->values()
            ->map(fn (Item $item, int $index): array => [
                'item_id' => $item->id,
                'selected' => false,
                'item_label' => trim($item->code.' - '.$item->name),
                'unit' => $item->unit?->code ?? $item->unit?->name ?? '-',
                'available' => (string) $item->initial_stock,
                'critical' => (string) $item->min_stock,
                'status' => (float) $item->initial_stock < (float) $item->min_stock ? 'Critical' : 'OK',
                'quantity' => null,
                'unit_price' => null,
                'total_price' => null,
                'notes' => null,
                'sort_order' => $index,
            ])
            ->all();
    }

    private static function updateCandidates(Get $get, Set $set, string $category): void
    {
        $selectedItems = collect($get('items') ?? [])
            ->filter(fn (array $item): bool => (bool) ($item['selected'] ?? false))
            ->keyBy('item_id');

        foreach (self::candidateRows($get('warehouse_id'), $category, $get('item_search')) as $candidate) {
            if (! $selectedItems->has($candidate['item_id'])) {
                $selectedItems->put($candidate['item_id'], $candidate);
            }
        }

        $set('items', $selectedItems->values()->all());
        self::updateAmounts($get, $set);
    }

    private static function updateLineTotal(Get $get, Set $set): mixed
    {
        $totalPrice = self::lineTotal($get('quantity'), $get('unit_price'));
        $set('total_price', Rupiah::format($totalPrice));

        return null;
    }

    private static function updateAmounts(Get $get, Set $set): mixed
    {
        $subtotal = collect($get('items') ?? [])
            ->filter(fn (array $item): bool => (bool) ($item['selected'] ?? false))
            ->sum(fn (array $item): float => self::lineTotal($item['quantity'] ?? null, $item['unit_price'] ?? null));
        $tax = (float) (Rupiah::normalize($get('tax_amount')) ?? 0);
        $discount = (float) (Rupiah::normalize($get('discount_amount')) ?? 0);

        $set('subtotal', Rupiah::format($subtotal));
        $set('total_amount', Rupiah::format($subtotal + $tax - $discount));

        return null;
    }

    private static function lineTotal(mixed $quantity, mixed $unitPrice): float
    {
        $normalizedQuantity = (float) Quantity::normalize($quantity);
        $normalizedUnitPrice = (float) (Rupiah::normalize($unitPrice) ?? 0);

        return round($normalizedQuantity * $normalizedUnitPrice, 2);
    }

    /** @param array<int, array<string, mixed>> $items
     * @return list<array{item_id: string, quantity: float, unit_price: float, total_price: float, notes: ?string, sort_order: int}>
     */
    public static function selectedItems(array $items, string $warehouseId): array
    {
        $selectedItems = collect($items)->filter(fn (array $item): bool => (bool) ($item['selected'] ?? false));

        if ($selectedItems->isEmpty()) {
            throw ValidationException::withMessages(['items' => 'Select at least one item for the direct order.']);
        }

        $allowedIds = Item::query()
            ->where('record_status', RecordStatus::Saved)
            ->where('is_active', true)
            ->where('purchasable', true)
            ->where('initial_warehouse_id', $warehouseId)
            ->whereIn('id', $selectedItems->pluck('item_id'))
            ->pluck('id')
            ->all();

        if (count($allowedIds) !== $selectedItems->count()) {
            throw ValidationException::withMessages(['items' => 'Selected items must belong to the chosen location.']);
        }

        return $selectedItems->values()->map(fn (array $item, int $index): array => [
            'item_id' => $item['item_id'],
            'quantity' => (float) Quantity::normalize($item['quantity']),
            'unit_price' => (float) (Rupiah::normalize($item['unit_price']) ?? 0),
            'total_price' => self::lineTotal($item['quantity'], $item['unit_price']),
            'notes' => $item['notes'] ?? null,
            'sort_order' => $index,
        ])->all();
    }

    /** @return list<array<string, mixed>> */
    public static function rowsForEdit(DirectOrder $record): array
    {
        $selectedRows = $record->items()
            ->with('item.unit')
            ->get()
            ->map(fn (DirectOrderItem $line, int $index): array => [
                'item_id' => $line->item_id,
                'selected' => true,
                'item_label' => $line->item ? trim($line->item->code.' - '.$line->item->name) : '-',
                'unit' => $line->item?->unit?->code ?? $line->item?->unit?->name ?? '-',
                'available' => (string) ($line->item?->initial_stock ?? 0),
                'critical' => (string) ($line->item?->min_stock ?? 0),
                'status' => $line->item !== null && (float) $line->item->initial_stock < (float) $line->item->min_stock
                    ? 'Critical'
                    : 'OK',
                'quantity' => (string) $line->quantity,
                'unit_price' => (string) $line->unit_price,
                'total_price' => (string) $line->total_price,
                'notes' => $line->notes,
                'sort_order' => $index,
            ])
            ->keyBy('item_id');

        foreach (self::candidateRows($record->warehouse_id, 'critical') as $candidate) {
            if (! $selectedRows->has($candidate['item_id'])) {
                $selectedRows->put($candidate['item_id'], $candidate);
            }
        }

        return $selectedRows->values()->all();
    }
}

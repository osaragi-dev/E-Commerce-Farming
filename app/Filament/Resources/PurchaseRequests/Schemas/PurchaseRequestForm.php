<?php

namespace App\Filament\Resources\PurchaseRequests\Schemas;

use App\Enums\RecordStatus;
use App\Filament\Forms\Components\QuantityInput;
use App\Models\Item;
use App\Models\PurchaseRequest;
use App\Models\PurchaseRequestItem;
use App\Models\User;
use App\Models\Warehouse;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\DatePicker;
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

class PurchaseRequestForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->columns(['default' => 1, 'lg' => 2])->components([
            Section::make('Request Information')->columns(['default' => 1, 'lg' => 2])->schema([
                TextInput::make('pr_number')
                    ->label('PR Number')
                    ->disabled()
                    ->dehydrated(false),
                DatePicker::make('pr_date')
                    ->label('PR Date')
                    ->displayFormat('d/m/Y')
                    ->default(now())
                    ->native(false)
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
                DatePicker::make('required_date')
                    ->label('Required Date')
                    ->displayFormat('d/m/Y')
                    ->placeholder('dd/mm/yyyy')
                    ->suffixIcon(Heroicon::Calendar)
                    ->extraAlpineAttributes(['x-on:focus-input.stop' => 'togglePanelVisibility()'])
                    ->native(false)
                    ->minDate(fn (Get $get): mixed => $get('pr_date'))
                    ->required(),
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
                        self::updateCandidates($get, $set, $get('item_category') ?? 'critical');
                    })
                    ->required()
                    ->columnSpanFull(),
            ]),
            Section::make('Notes')->schema([
                Textarea::make('notes')
                    ->label('Notes')
                    ->placeholder('Optional notes...')
                    ->rows(2)
                    ->columnSpanFull(),
            ]),
            ToggleButtons::make('item_category')
                ->hiddenLabel()
                ->options([
                    'critical' => 'Critical',
                    'not_critical' => 'Not Critical',
                ])
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
            Hidden::make('selected_item_rows')->default([]),
            Repeater::make('items')
                ->hiddenLabel()
                ->visible(fn (Get $get): bool => filled($get('warehouse_id')))
                ->schema([
                    Hidden::make('item_id')->required(),
                    Checkbox::make('selected')
                        ->label('')
                        ->default(fn (?PurchaseRequestItem $record): bool => $record !== null)
                        ->live()
                        ->afterStateUpdated(function (bool $state, Get $get, Set $set): void {
                            if ($state && blank($get('qty_order'))) {
                                $set('qty_order', '1.00');
                            }
                        }),
                    TextEntry::make('item_label')
                        ->label('Item')
                        ->placeholder('-')
                        ->extraAttributes(['class' => 'purchase-request-item-name']),
                    TextInput::make('unit')
                        ->label('Unit')
                        ->disabled()
                        ->dehydrated(false),
                    TextInput::make('available')
                        ->label('Available')
                        ->disabled()
                        ->dehydrated(false),
                    TextInput::make('critical')
                        ->label('Critical')
                        ->disabled()
                        ->dehydrated(false),
                    TextEntry::make('status')
                        ->label('Status')
                        ->formatStateUsing(fn (mixed $state): HtmlString => new HtmlString(sprintf(
                            '<span class="purchase-request-status-badge purchase-request-status-badge--%s">%s</span>',
                            $state === 'OK' ? 'ok' : 'critical',
                            e((string) ($state ?? 'Critical')),
                        ))),
                    QuantityInput::make('qty_order')
                        ->label('Qty Order')
                        ->placeholder('0.00')
                        ->formatStateUsing(fn (mixed $state): ?string => self::formatChecklistQuantity($state))
                        ->visible(fn (Get $get): bool => (bool) $get('selected'))
                        ->required(fn (Get $get): bool => (bool) $get('selected'))
                        ->live(onBlur: true)
                        ->minValue(0.01)
                        ->step(0.01)
                        ->rule('regex:/^\\d+(?:\\.\\d{1,2})?$/D'),
                    TextInput::make('specification')
                        ->visible(fn (Get $get): bool => (bool) $get('selected'))
                        ->placeholder('Specification')
                        ->live(onBlur: true),
                    TextInput::make('notes')
                        ->label('Catatan')
                        ->placeholder('Catatan')
                        ->visible(fn (Get $get): bool => (bool) $get('selected'))
                        ->live(onBlur: true),
                ])
                ->table([
                    TableColumn::make('Selected')->hiddenHeaderLabel()->width('3rem'),
                    TableColumn::make('Item')->width('18rem'),
                    TableColumn::make('Unit')->width('6rem'),
                    TableColumn::make('Available')->width('7rem'),
                    TableColumn::make('Critical')->width('7rem'),
                    TableColumn::make('Status')->width('7rem'),
                    TableColumn::make('Qty Order')->width('8rem'),
                    TableColumn::make('Specification')->width('10rem'),
                    TableColumn::make('Catatan')->width('10rem'),
                ])
                ->default(fn (?PurchaseRequest $record, Get $get): array => $record === null
                    ? self::candidateRows('critical', $get('warehouse_id'))
                    : self::existingRows($record))
                ->minItems(0)
                ->addable(false)
                ->deletable(false)
                ->reorderable(false)
                ->extraAttributes(['class' => 'purchase-request-items-table'])
                ->columnSpanFull(),
        ]);
    }

    private static function formatChecklistQuantity(mixed $state): ?string
    {
        return filled($state) ? number_format((float) $state, 2, '.', '') : null;
    }

    private static function formatStockQuantity(mixed $state): ?string
    {
        return filled($state) ? number_format((float) $state, 2, '.', '') : null;
    }

    /** @return list<array{item_id: string, selected: bool, item_label: string, unit: string, available: string, critical: string, status: string, qty_order: null, specification: null, notes: null, sort_order: int}> */
    private static function candidateRows(string $category, ?string $warehouseId = null, ?string $search = null): array
    {
        if (blank($warehouseId)) {
            return [];
        }

        $query = Item::query()
            ->where('record_status', RecordStatus::Saved)
            ->where('is_active', true)
            ->where('purchasable', true);

        $availableStock = filled($warehouseId)
            ? 'CASE WHEN initial_warehouse_id = ? THEN initial_stock ELSE 0 END'
            : 'initial_stock';
        $bindings = filled($warehouseId) ? [$warehouseId] : [];

        if ($category === 'critical') {
            $query->whereRaw("{$availableStock} <= min_stock", $bindings);
        } else {
            $query->whereRaw("{$availableStock} > min_stock", $bindings);
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
            ->map(fn (Item $item, int $index): array => self::itemRow($item, $warehouseId, $index))
            ->all();
    }

    private static function updateCandidates(Get $get, Set $set, string $category): void
    {
        $warehouseId = $get('warehouse_id');
        $selectedItems = collect($get('selected_item_rows') ?? [])->keyBy('item_id');

        foreach ($get('items') ?? [] as $item) {
            if ((bool) ($item['selected'] ?? false)) {
                $selectedItems->put($item['item_id'], $item);
            } else {
                $selectedItems->forget($item['item_id']);
            }
        }

        $visibleRows = collect(self::candidateRows($category, $warehouseId, $get('item_search')))
            ->map(function (array $candidate) use ($selectedItems): array {
                $selected = $selectedItems->get($candidate['item_id']);

                if ($selected === null) {
                    return $candidate;
                }

                return [
                    ...$candidate,
                    'selected' => true,
                    'qty_order' => $selected['qty_order'] ?? null,
                    'specification' => $selected['specification'] ?? null,
                    'notes' => $selected['notes'] ?? null,
                ];
            })
            ->values()
            ->all();

        $set('selected_item_rows', $selectedItems->values()->all());
        $set('items', $visibleRows);
    }

    /** @return list<array<string, mixed>> */
    public static function visibleRows(array $selectedRows, string $category, ?string $warehouseId = null): array
    {
        $selectedItems = collect($selectedRows)->keyBy('item_id');

        return collect(self::candidateRows($category, $warehouseId))
            ->map(function (array $candidate) use ($selectedItems): array {
                $selected = $selectedItems->get($candidate['item_id']);

                return $selected === null ? $candidate : [
                    ...$candidate,
                    'selected' => true,
                    'qty_order' => $selected['qty_order'] ?? null,
                    'specification' => $selected['specification'] ?? null,
                    'notes' => $selected['notes'] ?? null,
                ];
            })
            ->all();
    }

    /** @param array<string, mixed> $data
     * @return list<array{item_id: string, qty_order: mixed, specification: mixed, notes: mixed, sort_order: int}>
     */
    public static function selectedItemsFromFormState(array $data): array
    {
        $selectedItems = collect($data['selected_item_rows'] ?? [])->keyBy('item_id');

        foreach ($data['items'] ?? [] as $item) {
            if ((bool) ($item['selected'] ?? false)) {
                $selectedItems->put($item['item_id'], $item);
            } else {
                $selectedItems->forget($item['item_id']);
            }
        }

        return self::selectedItems($selectedItems->values()->all());
    }

    /** @return list<array<string, mixed>> */
    public static function existingRows(PurchaseRequest $record): array
    {
        return $record->items()
            ->with('item.unit')
            ->orderBy('sort_order')
            ->get()
            ->map(fn (PurchaseRequestItem $line, int $index): array => [
                ...($line->item
                    ? self::itemRow($line->item, $record->warehouse_id, $index)
                    : [
                        'item_id' => $line->item_id,
                        'item_label' => '-',
                        'unit' => '-',
                        'available' => '0.000',
                        'critical' => '0.000',
                        'status' => 'Critical',
                        'sort_order' => $index,
                    ]),
                'selected' => true,
                'qty_order' => (string) $line->qty_order,
                'specification' => $line->specification,
                'notes' => $line->notes,
            ])
            ->all();
    }

    /** @return array<string, mixed> */
    private static function itemRow(Item $item, ?string $warehouseId, int $sortOrder): array
    {
        $available = filled($warehouseId) && $item->initial_warehouse_id !== $warehouseId
            ? 0
            : (float) $item->initial_stock;
        $minimum = (float) $item->min_stock;

        return [
            'item_id' => $item->id,
            'selected' => false,
            'item_label' => $item->name,
            'unit' => $item->unit?->code ?? $item->unit?->name ?? '-',
            'available' => self::formatStockQuantity($available),
            'critical' => self::formatStockQuantity($minimum),
            'status' => $available <= $minimum ? 'Critical' : 'OK',
            'qty_order' => null,
            'specification' => null,
            'notes' => null,
            'sort_order' => $sortOrder,
        ];
    }

    /** @param array<int, array<string, mixed>> $items
     * @return list<array{item_id: string, qty_order: mixed, specification: mixed, notes: mixed, sort_order: int}>
     */
    public static function selectedItems(array $items): array
    {
        $hasSelectionState = collect($items)->contains(fn (array $item): bool => array_key_exists('selected', $item));
        $selectedItems = collect($items)
            ->filter(fn (array $item): bool => ! $hasSelectionState || (bool) ($item['selected'] ?? false))
            ->values();

        if ($selectedItems->isEmpty()) {
            throw ValidationException::withMessages([
                'items' => 'Select at least one item for the purchase request.',
            ]);
        }

        return $selectedItems->map(fn (array $item, int $index): array => [
            'item_id' => $item['item_id'],
            'qty_order' => $item['qty_order'] ?? null,
            'specification' => $item['specification'] ?? null,
            'notes' => $item['notes'] ?? null,
            'sort_order' => $index,
        ])->all();
    }
}

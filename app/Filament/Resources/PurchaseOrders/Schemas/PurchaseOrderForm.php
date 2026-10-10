<?php

namespace App\Filament\Resources\PurchaseOrders\Schemas;

use App\Enums\PurchaseRequestApprovalStatus;
use App\Enums\RecordStatus;
use App\Filament\Forms\Components\QuantityInput;
use App\Filament\Forms\Components\RupiahInput;
use App\Filament\Support\Rupiah;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\PurchaseRequest;
use App\Models\PurchaseRequestItem;
use App\Models\Vendor;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class PurchaseOrderForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->columns(['default' => 1, 'lg' => 2])->components([
            Section::make('Purchase Order Information')->columns(1)->schema([
                TextInput::make('po_number')->label('PO Number')->disabled()->dehydrated(false),
                DatePicker::make('po_date')->label('PO Date')->displayFormat('d/m/Y')->default(now())->required(),
                DatePicker::make('expected_delivery_date')->label('Expected Delivery Date')->displayFormat('d/m/Y'),
            ]),
            Section::make('Purchase Order Details')->columns(1)->schema([
                Select::make('purchase_request_id')
                    ->label('Purchase Request')
                    ->options(fn (): array => self::purchaseRequestOptions())
                    ->searchable()
                    ->optionsLimit(50)
                    ->live()
                    ->afterStateUpdated(function (mixed $state, Set $set): void {
                        if (blank($state)) {
                            $set('warehouse_id', null);
                            $set('items', []);

                            return;
                        }

                        $purchaseRequest = PurchaseRequest::query()->find($state);
                        $set('warehouse_id', $purchaseRequest?->warehouse_id);
                        $set('items', self::candidateRows((string) $state));
                    })
                    ->required(),
                Select::make('vendor_id')
                    ->label('Vendor')
                    ->options(fn (): array => self::vendorOptions())
                    ->searchable()
                    ->preload()
                    ->optionsLimit(50)
                    ->required(),
                Select::make('payment_by')
                    ->label('Payment By')
                    ->options(['holding' => 'Holding', 'resto' => 'Resto'])
                    ->required(),
                Hidden::make('payment_basis')->default('goods_receipt')->required(),
                Hidden::make('warehouse_id')->required(),
            ]),
            TextInput::make('item_search')->hiddenLabel()->placeholder('Search items...')->live(debounce: 400)->dehydrated(false)
                ->afterStateUpdated(function (Get $get, Set $set): void {
                    self::refreshCandidateRows($get, $set);
                })->columnSpanFull(),
            Repeater::make('items')->schema([
                Hidden::make('purchase_request_item_id')->required(),
                Hidden::make('item_id')->required(),
                Hidden::make('remaining_quantity')->dehydrated(false),
                Checkbox::make('selected')->label('')->default(false)->live()
                    ->afterStateUpdated(function (Get $get, Set $set): void {
                        self::updateLineTotal($get, $set);
                    }),
                TextEntry::make('line_number')->label('No')->state(fn (Get $get): string => (string) ((int) ($get('sort_order') ?? 0) + 1)),
                TextEntry::make('item_label')->label('Item')->placeholder('-'),
                TextEntry::make('qty_requested')->label('Requested')->formatStateUsing(fn (mixed $state): string => number_format((float) $state, 2, ',', '.')),
                TextEntry::make('ordered_quantity')->label('Ordered')->formatStateUsing(fn (mixed $state): string => number_format((float) $state, 2, ',', '.')),
                TextEntry::make('accepted_quantity')->label('Accepted')->formatStateUsing(fn (mixed $state): string => number_format((float) $state, 2, ',', '.')),
                QuantityInput::make('qty_order')->label('Qty')->visible(fn (Get $get): bool => (bool) $get('selected'))
                    ->required(fn (Get $get): bool => (bool) $get('selected'))->minValue(0.001)->maxValue(fn (Get $get): float => (float) ($get('remaining_quantity') ?? 0))
                    ->live(onBlur: true)->afterStateUpdated(function (Get $get, Set $set): void {
                        self::updateLineTotal($get, $set);
                    }),
                TextEntry::make('unit_code')->label('Unit')->placeholder('-'),
                RupiahInput::make('unit_price')->label('Unit Price')->visible(fn (Get $get): bool => (bool) $get('selected'))
                    ->required(fn (Get $get): bool => (bool) $get('selected'))->minValue(0)->live(debounce: 300)
                    ->afterStateUpdated(function (Get $get, Set $set): void {
                        self::updateLineTotal($get, $set);
                    }),
                TextEntry::make('total_price')->label('Total Price')->formatStateUsing(fn (mixed $state): string => Rupiah::format($state) ?? '0,00'),
            ])->table([
                TableColumn::make('Select')->width('5%')->alignCenter()->hiddenHeaderLabel(),
                TableColumn::make('No')->width('5%'),
                TableColumn::make('Item'),
                TableColumn::make('Requested'),
                TableColumn::make('Ordered'),
                TableColumn::make('Accepted'),
                TableColumn::make('Qty'),
                TableColumn::make('Unit'),
                TableColumn::make('Unit Price'),
                TableColumn::make('Total Price'),
            ])->default(fn (?PurchaseOrder $record): array => $record === null ? [] : self::existingRows($record))
                ->addable(false)->deletable(false)->reorderable(false)->columnSpanFull(),
            Section::make('Amounts & Terms')->columns(2)->schema([
                RupiahInput::make('subtotal')->disabled()->dehydrated()->default('0')
                    ->formatStateUsing(fn (mixed $state, Get $get): string => number_format(
                        collect($get('items') ?? [])->filter(fn (array $item): bool => (bool) ($item['selected'] ?? false))
                            ->sum(fn (array $item): float => (float) ($item['qty_order'] ?? 0) * (float) ($item['unit_price'] ?? 0)),
                        2,
                        '.',
                        '',
                    )),
                RupiahInput::make('tax_amount')->label('Tax Amount')->default('0')->live(onBlur: true),
                RupiahInput::make('discount_amount')->label('Discount Amount')->default('0')->live(onBlur: true),
                RupiahInput::make('total_amount')->label('Total Amount')->disabled()->dehydrated()->default('0')
                    ->formatStateUsing(fn (mixed $state, Get $get): string => number_format(max(
                        0,
                        (float) collect($get('items') ?? [])->filter(fn (array $item): bool => (bool) ($item['selected'] ?? false))
                            ->sum(fn (array $item): float => (float) ($item['qty_order'] ?? 0) * (float) ($item['unit_price'] ?? 0))
                            + (float) ($get('tax_amount') ?? 0)
                            - (float) ($get('discount_amount') ?? 0),
                    ), 2, '.', '')),
                Textarea::make('terms_and_conditions')->label('Terms & Conditions')->rows(3)->columnSpanFull(),
                Textarea::make('notes')->rows(3)->columnSpanFull(),
                FileUpload::make('quotation_file_path')->label('Quotation File')->disk('public')->directory('purchase-orders/quotations')
                    ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/gif', 'application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'])
                    ->maxSize(4096)->columnSpanFull(),
            ])->columnSpanFull(),
        ]);
    }

    /** @return array<string, string> */
    private static function purchaseRequestOptions(): array
    {
        return PurchaseRequest::query()
            ->where('record_status', RecordStatus::Saved)
            ->where('approval_status', PurchaseRequestApprovalStatus::Approved->value)
            ->whereHas('items')
            ->orderByDesc('pr_date')
            ->limit(50)
            ->pluck('pr_number', 'id')
            ->all();
    }

    /** @return array<string, string> */
    private static function vendorOptions(): array
    {
        return Vendor::query()
            ->where('record_status', RecordStatus::Saved)
            ->where('approval_status', 'approved')
            ->where('is_active', true)
            ->orderBy('name')
            ->limit(50)
            ->pluck('name', 'id')
            ->all();
    }

    /** @return Collection<string, string> */
    private static function orderedQuantitiesByRequestItem(?string $purchaseRequestId = null): Collection
    {
        return PurchaseOrderItem::query()
            ->whereHas('purchaseOrder', fn (Builder $query): Builder => $query
                ->where('record_status', RecordStatus::Saved)
                ->whereDoesntHave('closeRequests', fn (Builder $close): Builder => $close->where('approval_status', 'approved'))
                ->when($purchaseRequestId !== null, fn (Builder $query): Builder => $query->where('purchase_request_id', $purchaseRequestId)))
            ->selectRaw('purchase_request_item_id, SUM(qty_order) AS ordered_quantity')
            ->groupBy('purchase_request_item_id')
            ->pluck('ordered_quantity', 'purchase_request_item_id');
    }

    /** @return list<array<string, mixed>> */
    public static function existingRows(PurchaseOrder $record): array
    {
        return $record->items()->with('item.unit')->orderBy('sort_order')->get()->map(fn (PurchaseOrderItem $line): array => [
            'purchase_request_item_id' => $line->purchase_request_item_id,
            'item_id' => $line->item_id,
            'selected' => true,
            'line_number' => $line->sort_order + 1,
            'item_label' => $line->item ? trim($line->item->code.' - '.$line->item->name) : '-',
            'qty_requested' => (string) $line->qty_requested,
            'ordered_quantity' => '0.000',
            'remaining_quantity' => (string) $line->qty_requested,
            'qty_order' => (string) $line->qty_order,
            'accepted_quantity' => '0.000',
            'unit_code' => $line->unit_code,
            'unit_price' => (string) $line->unit_price,
            'total_price' => (string) $line->total_price,
            'specification' => $line->specification,
            'notes' => $line->notes,
            'sort_order' => $line->sort_order,
        ])->all();
    }

    /** @return list<array<string, mixed>> */
    public static function candidateRows(string $purchaseRequestId, ?string $search = null): array
    {
        if (blank($purchaseRequestId)) {
            return [];
        }

        $orderedByRequestItem = self::orderedQuantitiesByRequestItem($purchaseRequestId);

        return PurchaseRequestItem::query()
            ->where('purchase_request_id', $purchaseRequestId)
            ->when(filled($search), function (Builder $query) use ($search): void {
                $term = mb_strtolower(trim((string) $search));
                $query->whereHas('item', fn (Builder $itemQuery): Builder => $itemQuery
                    ->whereRaw('LOWER(name) LIKE ?', ["%{$term}%"])
                    ->orWhereRaw('LOWER(code) LIKE ?', ["%{$term}%"])
                    ->orWhereRaw('LOWER(sku) LIKE ?', ["%{$term}%"]));
            })
            ->with(['item.unit'])
            ->orderBy('sort_order')
            ->get()
            ->values()
            ->map(function (PurchaseRequestItem $line, int $index) use ($orderedByRequestItem): ?array {
                $remaining = max(0, (float) $line->qty_order - (float) $orderedByRequestItem->get($line->id, 0));

                if ($remaining <= 0) {
                    return null;
                }

                return [
                    'purchase_request_item_id' => $line->id,
                    'item_id' => $line->item_id,
                    'selected' => false,
                    'line_number' => $index + 1,
                    'item_label' => $line->item ? trim($line->item->code.' - '.$line->item->name) : '-',
                    'qty_requested' => number_format((float) $line->qty_order, 3, '.', ''),
                    'ordered_quantity' => number_format((float) $orderedByRequestItem->get($line->id, 0), 3, '.', ''),
                    'remaining_quantity' => number_format($remaining, 3, '.', ''),
                    'qty_order' => number_format($remaining, 3, '.', ''),
                    'accepted_quantity' => '0.000',
                    'unit_code' => $line->item?->unit?->code ?? $line->item?->unit?->name ?? '-',
                    'unit_price' => '0.00',
                    'total_price' => '0.00',
                    'specification' => $line->specification,
                    'notes' => $line->notes,
                    'sort_order' => $index,
                ];
            })
            ->filter()
            ->all();
    }

    private static function refreshCandidateRows(Get $get, Set $set): void
    {
        $purchaseRequestId = $get('purchase_request_id');

        if (blank($purchaseRequestId)) {
            $set('items', []);

            return;
        }

        $existingRows = collect($get('items') ?? [])->keyBy('purchase_request_item_id');
        $candidateRows = collect(self::candidateRows((string) $purchaseRequestId, $get('item_search')))
            ->map(fn (array $row): array => array_replace($row, $existingRows->get($row['purchase_request_item_id'], [])))
            ->keyBy('purchase_request_item_id');

        $selectedRows = $existingRows->filter(fn (array $row): bool => (bool) ($row['selected'] ?? false));

        $set('items', $candidateRows->union($selectedRows)->values()->all());
    }

    private static function updateLineTotal(Get $get, Set $set): void
    {
        $lineTotal = (bool) $get('selected')
            ? (float) ($get('qty_order') ?? 0) * (float) (Rupiah::normalize($get('unit_price')) ?? 0)
            : 0;

        $set('total_price', number_format($lineTotal, 2, '.', ''));
    }

    /** @param array<int, array<string, mixed>> $items
     * @return list<array<string, mixed>>
     */
    public static function validatedItems(array $items, string $purchaseRequestId): array
    {
        $allowedRows = collect(self::candidateRows($purchaseRequestId))->keyBy('purchase_request_item_id');
        $hasSelectionState = collect($items)->contains(fn (array $line): bool => array_key_exists('selected', $line));
        $selectedRows = collect($items)
            ->filter(fn (array $line): bool => $hasSelectionState
                ? (bool) ($line['selected'] ?? false)
                : (float) ($line['qty_order'] ?? 0) > 0)
            ->values();

        if ($selectedRows->isEmpty()) {
            throw ValidationException::withMessages(['items' => 'Add at least one purchase order item.']);
        }

        return $selectedRows->map(function (array $line, int $index) use ($allowedRows): array {
            $requestItemId = (string) ($line['purchase_request_item_id'] ?? '');
            $source = $allowedRows->get($requestItemId);
            $unitPrice = (float) ($line['unit_price'] ?? 0);
            $quantity = (float) ($line['qty_order'] ?? 0);

            if (! $source || $quantity <= 0 || $quantity > (float) $source['remaining_quantity']) {
                throw ValidationException::withMessages(['items' => 'Order quantity must be greater than zero and no greater than the remaining Purchase Request quantity.']);
            }

            if ($unitPrice < 0) {
                throw ValidationException::withMessages(['items' => 'Unit price must be zero or greater.']);
            }

            return [
                'purchase_request_item_id' => $requestItemId,
                'item_id' => $source['item_id'],
                'qty_requested' => $source['qty_requested'],
                'qty_order' => number_format($quantity, 3, '.', ''),
                'unit_code' => $source['unit_code'],
                'unit_price' => number_format($unitPrice, 2, '.', ''),
                'total_price' => number_format($quantity * $unitPrice, 2, '.', ''),
                'specification' => $source['specification'],
                'notes' => $source['notes'],
                'sort_order' => $index,
            ];
        })->all();
    }
}

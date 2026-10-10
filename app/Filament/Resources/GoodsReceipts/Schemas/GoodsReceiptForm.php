<?php

namespace App\Filament\Resources\GoodsReceipts\Schemas;

use App\Enums\PurchaseOrderApprovalStatus;
use App\Enums\PurchaseOrderCloseApprovalStatus;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptItem;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\User;
use App\Support\Quantity;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

class GoodsReceiptForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->columns(['default' => 1, 'lg' => 2])->components([
            Section::make('Goods Receipt Information')->schema([
                TextInput::make('receipt_number')
                    ->label('GR Number')
                    ->disabled()
                    ->dehydrated(false)
                    ->placeholder('Generated when submitted'),
                DatePicker::make('received_date')
                    ->label('Received Date')
                    ->displayFormat('d/m/Y')
                    ->default(now())
                    ->native(false)
                    ->required(),
                TimePicker::make('received_time')
                    ->label('Time')
                    ->default(now())
                    ->seconds(false)
                    ->required(),
                TextInput::make('delivery_note_number')
                    ->label('Delivery Note Number')
                    ->maxLength(255),
                Select::make('checked_by')
                    ->label('Checked By')
                    ->options(fn (): array => User::query()->saved()->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable()
                    ->required(),
                FileUpload::make('documentation_path')
                    ->label('Documentation')
                    ->disk('public')
                    ->directory('goods-receipts')
                    ->acceptedFileTypes([
                        'image/jpeg', 'image/png', 'image/gif', 'application/pdf',
                        'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                    ])
                    ->maxSize(4096)
                    ->required()
                    ->helperText('Allowed: JPG, PNG, GIF, PDF, DOC, DOCX. Max size: 4 MB.'),
            ])->columnSpan(1),
            Section::make('Purchase Order Details')->schema([
                Select::make('purchase_order_id')
                    ->label('Purchase Order')
                    ->default(fn (): ?string => request()->query('purchase_order_id'))
                    ->searchable()
                    ->getSearchResultsUsing(fn (string $search): array => self::purchaseOrderOptions($search))
                    ->getOptionLabelUsing(fn (?string $state): ?string => filled($state)
                        ? PurchaseOrder::query()->whereKey($state)->value('po_number')
                        : null)
                    ->live()
                    ->afterStateHydrated(function (Get $get, Set $set, ?string $state): void {
                        $purchaseOrder = filled($state) ? PurchaseOrder::query()->with(['items.item.unit', 'items.goodsReceiptItems.goodsReceipt', 'vendor', 'warehouse'])->find($state) : null;

                        $set('warehouse_label', $purchaseOrder?->warehouse?->name);
                        $set('vendor_label', $purchaseOrder?->vendor?->name);
                        $items = $purchaseOrder
                            ? self::mergeRows(self::itemRows($purchaseOrder), $get('items') ?? [])
                            : [];
                        $set('item_catalog', $items);
                        $set('items', $items);
                    })
                    ->afterStateUpdated(function (Get $get, Set $set, ?string $state): void {
                        $purchaseOrder = filled($state) ? PurchaseOrder::query()->with(['items.item.unit', 'items.goodsReceiptItems.goodsReceipt', 'vendor', 'warehouse'])->find($state) : null;

                        $set('warehouse_label', $purchaseOrder?->warehouse?->name);
                        $set('vendor_label', $purchaseOrder?->vendor?->name);
                        $items = $purchaseOrder ? self::itemRows($purchaseOrder) : [];
                        $set('item_catalog', $items);
                        $set('items', $items);
                    })
                    ->required(),
                Select::make('warehouse_label')
                    ->label('Location')
                    ->options(fn (Get $get): array => filled($get('warehouse_label')) ? [$get('warehouse_label') => $get('warehouse_label')] : [])
                    ->disabled()
                    ->dehydrated(false),
                Select::make('vendor_label')
                    ->label('Vendor')
                    ->options(fn (Get $get): array => filled($get('vendor_label')) ? [$get('vendor_label') => $get('vendor_label')] : [])
                    ->disabled()
                    ->dehydrated(false),
                Textarea::make('notes')->label('Notes')->rows(3),
            ])->columnSpan(1),
            TextInput::make('item_search')
                ->hiddenLabel()
                ->placeholder('Search items...')
                ->prefixIcon('heroicon-o-magnifying-glass')
                ->live(debounce: 400)
                ->dehydrated(false)
                ->afterStateUpdated(function (Get $get, Set $set, ?string $state): void {
                    $catalog = self::mergeRows($get('item_catalog') ?? [], $get('items') ?? []);
                    $set('item_catalog', $catalog);
                    $needle = mb_strtolower(trim((string) $state));
                    $visibleItems = $needle === ''
                        ? $catalog
                        : array_values(array_filter($catalog, fn (array $row): bool => str_contains(mb_strtolower((string) ($row['item_label'] ?? '')), $needle)));
                    $set('items', $visibleItems);
                })
                ->visible(fn (Get $get): bool => filled($get('purchase_order_id')))
                ->columnSpanFull(),
            Hidden::make('item_catalog')->default([]),
            Repeater::make('items')
                ->hiddenLabel()
                ->schema([
                    Checkbox::make('is_selected')->hiddenLabel()->default(false)->live(),
                    Hidden::make('purchase_order_item_id')->required(),
                    Placeholder::make('item_number')->label('No')->content(fn (Get $get): string => (string) $get('item_number')),
                    Placeholder::make('item_label')->label('Item')->content(fn (Get $get): string => (string) $get('item_label')),
                    Placeholder::make('ordered_quantity')->label('Ordered')->content(fn (Get $get): string => (string) $get('ordered_quantity')),
                    Placeholder::make('previous_received')->label('Prev. Received')->content(fn (Get $get): string => (string) $get('previous_received')),
                    Placeholder::make('previous_accepted')->label('Prev. Accepted')->content(fn (Get $get): string => (string) $get('previous_accepted')),
                    Placeholder::make('previous_rejected')->label('Prev. Rejected')->content(fn (Get $get): string => (string) $get('previous_rejected')),
                    TextInput::make('quantity_received')->label('Receive Qty')->numeric()->minValue(0)->default(0)->required(fn (Get $get): bool => (bool) $get('is_selected'))->disabled(fn (Get $get): bool => ! $get('is_selected')),
                    TextInput::make('accepted_quantity')->label('Accepted')->numeric()->minValue(0)->default(0)->required(fn (Get $get): bool => (bool) $get('is_selected'))->disabled(fn (Get $get): bool => ! $get('is_selected')),
                    TextInput::make('rejected_quantity')->label('Rejected')->numeric()->minValue(0)->default(0)->required(fn (Get $get): bool => (bool) $get('is_selected'))->disabled(fn (Get $get): bool => ! $get('is_selected')),
                    TextInput::make('rejection_reason')->label('Rejection Reason')->placeholder('Reason...')->disabled(fn (Get $get): bool => ! $get('is_selected')),
                    Select::make('condition')->label('Condition')->options([
                        'good' => 'Good',
                        'damaged' => 'Damaged',
                        'partial' => 'Partial',
                    ])->placeholder('Select')->disabled(fn (Get $get): bool => ! $get('is_selected')),
                    Placeholder::make('unit')->label('Unit')->content(fn (Get $get): string => (string) $get('unit')),
                    TextInput::make('notes')->label('Notes')->placeholder('Notes...')->disabled(fn (Get $get): bool => ! $get('is_selected')),
                ])
                ->table([
                    TableColumn::make('Select'),
                    TableColumn::make('No'),
                    TableColumn::make('Item'),
                    TableColumn::make('Ordered'),
                    TableColumn::make('Prev. Received'),
                    TableColumn::make('Prev. Accepted'),
                    TableColumn::make('Prev. Rejected'),
                    TableColumn::make('Receive Qty'),
                    TableColumn::make('Accepted'),
                    TableColumn::make('Rejected'),
                    TableColumn::make('Rejection Reason'),
                    TableColumn::make('Condition *'),
                    TableColumn::make('Unit'),
                    TableColumn::make('Notes'),
                ])
                ->columns(14)
                ->defaultItems(0)
                ->addable(false)
                ->deletable(false)
                ->reorderable(false)
                ->visible(fn (Get $get): bool => filled($get('purchase_order_id')))
                ->columnSpanFull(),
        ]);
    }

    /** @return array<string, string> */
    private static function purchaseOrderOptions(string $search): array
    {
        return PurchaseOrder::query()
            ->where('record_status', 'saved')
            ->where('approval_status', PurchaseOrderApprovalStatus::Approved)
            ->whereDoesntHave('closeRequests', fn ($query) => $query->where('approval_status', PurchaseOrderCloseApprovalStatus::Approved))
            ->where('po_number', 'like', '%'.trim($search).'%')
            ->orderByDesc('po_date')
            ->limit(50)
            ->pluck('po_number', 'id')
            ->all();
    }

    /** @return list<array<string, string>> */
    private static function itemRows(PurchaseOrder $purchaseOrder): array
    {
        return $purchaseOrder->items()->with(['item.unit', 'goodsReceiptItems.goodsReceipt'])->get()
            ->map(function (PurchaseOrderItem $orderItem, int $index): array {
                $previous = $orderItem->goodsReceiptItems;
                $unit = $orderItem->item?->unit?->code ?? $orderItem->unit_code ?? '-';

                return [
                    'purchase_order_item_id' => $orderItem->id,
                    'is_selected' => false,
                    'item_number' => (string) ($index + 1),
                    'item_label' => $orderItem->item?->name ?? '-',
                    'ordered_quantity' => Quantity::format($orderItem->qty_order),
                    'previous_received' => Quantity::format($previous->sum('quantity_received')),
                    'previous_accepted' => Quantity::format($previous->sum('accepted_quantity')),
                    'previous_rejected' => Quantity::format($previous->sum('rejected_quantity')),
                    'quantity_received' => '0.000',
                    'accepted_quantity' => '0.000',
                    'rejected_quantity' => '0.000',
                    'condition' => null,
                    'unit' => $unit,
                ];
            })
            ->all();
    }

    /** @return list<array<string, mixed>> */
    public static function existingRows(GoodsReceipt $receipt): array
    {
        $receipt->loadMissing('items.purchaseOrderItem.item.unit');

        return $receipt->items
            ->map(function (GoodsReceiptItem $receiptItem, int $index): array {
                $orderItem = $receiptItem->purchaseOrderItem;
                $unit = $orderItem?->item?->unit?->code ?? $orderItem?->unit_code ?? '-';

                return [
                    'purchase_order_item_id' => $receiptItem->purchase_order_item_id,
                    'is_selected' => true,
                    'item_number' => (string) ($index + 1),
                    'item_label' => $orderItem?->item?->name ?? '-',
                    'ordered_quantity' => Quantity::format($orderItem?->qty_order ?? 0),
                    'previous_received' => '0.000',
                    'previous_accepted' => '0.000',
                    'previous_rejected' => '0.000',
                    'quantity_received' => (string) $receiptItem->quantity_received,
                    'accepted_quantity' => (string) $receiptItem->accepted_quantity,
                    'rejected_quantity' => (string) $receiptItem->rejected_quantity,
                    'rejection_reason' => $receiptItem->rejection_reason,
                    'condition' => $receiptItem->condition,
                    'unit' => $unit,
                    'notes' => $receiptItem->notes,
                ];
            })
            ->all();
    }

    /** @param list<array<string, mixed>> $catalog
     * @param  list<array<string, mixed>>  $current
     * @return list<array<string, mixed>>
     */
    public static function mergeRows(array $catalog, array $current): array
    {
        $currentById = collect($current)->keyBy('purchase_order_item_id');

        return collect($catalog)
            ->map(fn (array $row): array => [...$row, ...($currentById->get($row['purchase_order_item_id']) ?? [])])
            ->values()
            ->all();
    }
}

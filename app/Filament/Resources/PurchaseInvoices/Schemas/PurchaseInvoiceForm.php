<?php

namespace App\Filament\Resources\PurchaseInvoices\Schemas;

use App\Enums\PurchaseOrderApprovalStatus;
use App\Enums\RecordStatus;
use App\Filament\Forms\Components\QuantityInput;
use App\Filament\Forms\Components\RupiahInput;
use App\Filament\Support\Rupiah;
use App\Models\GoodsReceiptItem;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseInvoiceItem;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Validation\ValidationException;

class PurchaseInvoiceForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make('Invoice Information')->columns(2)->schema([
                Hidden::make('purchase_order_id')->required(),
                Hidden::make('vendor_id')->required(),
                TextInput::make('purchase_order_label')->label('Purchase Order')->default(fn (?PurchaseInvoice $record): ?string => $record?->purchaseOrder?->po_number)->disabled()->dehydrated(false),
                TextInput::make('vendor_label')->label('Vendor')->default(fn (?PurchaseInvoice $record): ?string => $record?->vendor?->name)->disabled()->dehydrated(false),
                DatePicker::make('invoice_date')->label('Invoice Date')->displayFormat('d/m/Y')->default(now())->required(),
            ]),
            Section::make('Final Item Prices')->schema([
                TextInput::make('item_search')->hiddenLabel()->placeholder('Search by item name or code...')->live(debounce: 300)->dehydrated(false),
                Repeater::make('items')->schema([
                    Hidden::make('purchase_order_item_id')->required(),
                    TextInput::make('item_label')->label('Item')->disabled()->dehydrated(false),
                    TextInput::make('unit_code')->label('Unit')->disabled()->dehydrated(false),
                    QuantityInput::make('po_quantity')->label('PO Qty')->disabled()->dehydrated(false),
                    QuantityInput::make('grn_quantity')->label('GRN Qty')->disabled()->dehydrated(false),
                    RupiahInput::make('quoted_price')->label('Quoted Price')->disabled()->dehydrated(false),
                    QuantityInput::make('invoice_quantity')->label('Invoice Qty')->required()->minValue(0)->live(onBlur: true),
                    RupiahInput::make('final_unit_price')->label('Final Unit Price')->required()->minValue(0)->default('0')->live(onBlur: true),
                    RupiahInput::make('line_total')->label('Line Total')->disabled()->dehydrated(false)
                        ->formatStateUsing(fn (mixed $state, Get $get): string => Rupiah::format(
                            (float) ($get('invoice_quantity') ?? 0) * (float) ($get('final_unit_price') ?? 0),
                        ) ?? '0'),
                ])->table([
                    TableColumn::make('No'), TableColumn::make('Item'), TableColumn::make('Unit'),
                    TableColumn::make('PO Qty'), TableColumn::make('GRN Qty'), TableColumn::make('Quoted Price'),
                    TableColumn::make('Invoice Qty'), TableColumn::make('Final Unit Price'), TableColumn::make('Line Total'),
                ])->default(fn (?PurchaseInvoice $record): array => $record === null ? [] : self::existingRows($record))
                    ->addable(false)->deletable(false)->reorderable(false),
            ]),
            Section::make('Amounts & Document')->columns(2)->schema([
                RupiahInput::make('subtotal')->disabled()->dehydrated()->default('0'),
                RupiahInput::make('tax_amount')->label('Tax Amount')->default('0'),
                RupiahInput::make('discount_amount')->label('Discount Amount')->default('0'),
                RupiahInput::make('grand_total')->label('Grand Total')->disabled()->dehydrated()->default('0'),
                FileUpload::make('vendor_invoice_file_path')->label('Vendor Invoice File')->disk('public')->directory('purchase-invoices')
                    ->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/png'])->maxSize(4096)->columnSpanFull(),
                Textarea::make('notes')->rows(3)->columnSpanFull(),
            ]),
        ]);
    }

    /** @return array<string, mixed> */
    public static function initialData(string $purchaseOrderId): array
    {
        $purchaseOrder = PurchaseOrder::query()
            ->with(['vendor', 'items.item.unit', 'items.goodsReceiptItems.goodsReceipt'])
            ->findOrFail($purchaseOrderId);

        if ($purchaseOrder->approval_status !== PurchaseOrderApprovalStatus::Approved) {
            throw ValidationException::withMessages(['purchase_order_id' => 'Purchase Invoices can only be created from approved Purchase Orders.']);
        }

        return [
            'purchase_order_id' => $purchaseOrder->id,
            'vendor_id' => $purchaseOrder->vendor_id,
            'purchase_order_label' => $purchaseOrder->po_number,
            'vendor_label' => $purchaseOrder->vendor?->name,
            'invoice_date' => now()->toDateString(),
            'items' => self::candidateRows($purchaseOrder),
            'tax_amount' => '0',
            'discount_amount' => '0',
        ];
    }

    /** @return list<array<string, mixed>> */
    public static function candidateRows(PurchaseOrder $purchaseOrder): array
    {
        return $purchaseOrder->items->values()->map(function (PurchaseOrderItem $item): ?array {
            $grnAccepted = (float) GoodsReceiptItem::query()
                ->where('purchase_order_item_id', $item->id)
                ->whereHas('goodsReceipt', fn ($query) => $query->where('status', 'approved'))
                ->sum('accepted_quantity');
            $invoiced = (float) PurchaseInvoiceItem::query()
                ->where('purchase_order_item_id', $item->id)
                ->whereHas('purchaseInvoice', fn ($query) => $query
                    ->where('record_status', RecordStatus::Saved)
                    ->whereNull('deleted_at'))
                ->sum('invoice_quantity');
            $remaining = max(0, $grnAccepted - $invoiced);

            return [
                'purchase_order_item_id' => $item->id,
                'item_label' => $item->item ? trim($item->item->code.' - '.$item->item->name) : '-',
                'unit_code' => $item->unit_code,
                'po_quantity' => (string) $item->qty_order,
                'grn_quantity' => number_format($grnAccepted, 3, '.', ''),
                'quoted_price' => (string) $item->unit_price,
                'invoice_quantity' => number_format($remaining, 3, '.', ''),
                'final_unit_price' => (string) $item->unit_price,
                'line_total' => '0',
            ];
        })->filter()->all();
    }

    /** @return list<array<string, mixed>> */
    public static function existingRows(PurchaseInvoice $invoice): array
    {
        return $invoice->items()->with(['purchaseOrderItem.item'])->get()->map(fn (PurchaseInvoiceItem $line): array => [
            'purchase_order_item_id' => $line->purchase_order_item_id,
            'item_label' => $line->purchaseOrderItem?->item ? trim($line->purchaseOrderItem->item->code.' - '.$line->purchaseOrderItem->item->name) : '-',
            'unit_code' => $line->purchaseOrderItem?->unit_code ?? '-',
            'po_quantity' => (string) ($line->purchaseOrderItem?->qty_order ?? 0),
            'grn_quantity' => (string) ($line->purchaseOrderItem?->accepted_quantity ?? 0),
            'quoted_price' => (string) ($line->purchaseOrderItem?->unit_price ?? 0),
            'invoice_quantity' => (string) $line->invoice_quantity,
            'final_unit_price' => (string) $line->final_unit_price,
            'line_total' => (string) $line->line_total,
        ])->all();
    }

    /** @param array<int, array<string, mixed>> $items
     * @return list<array{purchase_order_item_id: string, invoice_quantity: string, final_unit_price: string, line_total: string}>
     */
    public static function validatedItems(array $items, PurchaseOrder $purchaseOrder): array
    {
        $available = collect(self::candidateRows($purchaseOrder))->keyBy('purchase_order_item_id');
        $selected = collect($items)->filter(fn (array $line): bool => (float) ($line['invoice_quantity'] ?? 0) > 0)->values();

        if ($selected->isEmpty()) {
            throw ValidationException::withMessages(['items' => 'No accepted GRN quantity is available to invoice.']);
        }

        return $selected->map(function (array $line) use ($available): array {
            $orderItemId = (string) ($line['purchase_order_item_id'] ?? '');
            $source = $available->get($orderItemId);
            $quantity = (float) ($line['invoice_quantity'] ?? 0);
            $price = (float) ($line['final_unit_price'] ?? 0);

            if (! $source || $quantity > (float) $source['invoice_quantity']) {
                throw ValidationException::withMessages(['items' => 'Invoice quantity cannot exceed the uninvoiced accepted GRN quantity.']);
            }

            return [
                'purchase_order_item_id' => $orderItemId,
                'invoice_quantity' => number_format($quantity, 3, '.', ''),
                'final_unit_price' => number_format($price, 2, '.', ''),
                'line_total' => number_format($quantity * $price, 2, '.', ''),
            ];
        })->all();
    }
}

<?php

namespace App\Filament\Resources\PurchaseInvoices\Tables;

use App\Enums\RecordStatus;
use App\Filament\Actions\TableExportActions;
use App\Filament\Resources\PurchaseInvoices\PurchaseInvoiceResource;
use App\Filament\Resources\PurchaseInvoices\Schemas\PurchaseInvoiceForm;
use App\Filament\Support\Rupiah;
use App\Filament\Tables\ApplicationTable;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseOrder;
use CodeWithKyrian\FilamentDateRange\Tables\Filters\DateRangeFilter;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

class PurchaseInvoicesTable
{
    public static function configure(Table $table): Table
    {
        $table = $table
            ->modifyQueryUsing(fn ($query) => $query->with(['purchaseOrder', 'vendor', 'createdBy']))
            ->columns([
                TextColumn::make('invoice_number')->label('Invoice Number')->searchable()->sortable()->placeholder('-'),
                TextColumn::make('purchaseOrder.po_number')->label('PO Number')->searchable()->sortable(),
                TextColumn::make('vendor.name')->searchable()->sortable(),
                TextColumn::make('invoice_date')->date()->sortable(),
                TextColumn::make('grand_total')->formatStateUsing(fn ($state): string => Rupiah::formatWithPrefix($state) ?? 'Rp 0,00')->sortable(),
                TextColumn::make('record_status')->badge()->sortable(),
                TextColumn::make('createdBy.name')->label('Created By')->placeholder('-'),
                TextColumn::make('created_at')->label('Created At')->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('record_status')->options([
                    RecordStatus::Draft->value => 'Draft',
                    RecordStatus::Saved->value => 'Saved',
                    RecordStatus::Deleted->value => 'Deleted',
                ]),
                DateRangeFilter::make('invoice_date')->label('Invoice Date'),
                TrashedFilter::make(),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make()
                    ->mutateDataUsing(function (array $data, PurchaseInvoice $record): array {
                        $order = PurchaseOrder::query()->findOrFail($record->purchase_order_id);
                        $items = PurchaseInvoiceForm::validatedItems($data['items'] ?? [], $order);
                        $data['items'] = $items;
                        $data['subtotal'] = collect($items)->sum(fn (array $line): float => (float) $line['line_total']);
                        $data['grand_total'] = max(0, $data['subtotal'] + (float) ($data['tax_amount'] ?? 0) - (float) ($data['discount_amount'] ?? 0));

                        return $data;
                    })
                    ->after(function (PurchaseInvoice $record, Action $action): void {
                        $record->items()->delete();
                        $record->items()->createMany($action->getData()['items'] ?? []);
                    })
                    ->visible(fn ($record): bool => $record->record_status === RecordStatus::Draft),
                DeleteAction::make()->visible(fn ($record): bool => $record->record_status === RecordStatus::Draft),
                RestoreAction::make(),
            ])
            ->toolbarActions([
                TableExportActions::group(PurchaseInvoiceResource::class),
                BulkActionGroup::make([
                    ...TableExportActions::selected(PurchaseInvoiceResource::class),
                    RestoreBulkAction::make(),
                ]),
            ]);

        return ApplicationTable::configure($table, PurchaseInvoiceResource::class);
    }
}

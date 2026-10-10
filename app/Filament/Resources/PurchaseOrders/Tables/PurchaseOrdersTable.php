<?php

namespace App\Filament\Resources\PurchaseOrders\Tables;

use App\Enums\RecordStatus;
use App\Filament\Actions\TableExportActions;
use App\Filament\Resources\PurchaseOrders\PurchaseOrderResource;
use App\Filament\Tables\ApplicationTable;
use App\Filament\Tables\Columns\RupiahColumn;
use App\Models\PurchaseOrder;
use CodeWithKyrian\FilamentDateRange\Tables\Filters\DateRangeFilter;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class PurchaseOrdersTable
{
    public static function configure(Table $table): Table
    {
        $table = $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with([
                'purchaseRequest', 'warehouse', 'vendor', 'createdBy', 'updatedBy', 'deletedBy',
                'approvalSetting.levels', 'approvalLogs', 'closeRequests.approvalLogs', 'items.goodsReceiptItems', 'payments',
            ]))
            ->columns([
                TextColumn::make('row_number')->label('No')->rowIndex(),
                TextColumn::make('approval_progress')->label('Approval')
                    ->state(fn (PurchaseOrder $record): string => $record->approvalProgressLabel())
                    ->badge()->color(fn (PurchaseOrder $record): string => $record->approval_status?->getColor() ?? 'gray'),
                TextColumn::make('po_number')->label('PO Number')->searchable()->sortable()->placeholder('-'),
                TextColumn::make('po_date')->label('PO Date')->date()->sortable(),
                TextColumn::make('purchaseRequest.pr_number')->label('PR Number')->searchable()->sortable(),
                TextColumn::make('warehouse.name')->label('Location')->searchable()->sortable(),
                TextColumn::make('vendor.name')->searchable()->sortable(),
                RupiahColumn::make('total_amount')->label('Total Amount')->sortable(),
                TextColumn::make('received_status')->label('Received')->badge()
                    ->formatStateUsing(fn ($state): string => $state?->getLabel() ?? '-')
                    ->color(fn ($state): string => $state?->getColor() ?? 'gray'),
                TextColumn::make('closing_status')->label('Closing')->badge()->formatStateUsing(fn (string $state): string => str($state)->headline()->toString()),
                TextColumn::make('payment_status')->label('Payment')->badge()
                    ->formatStateUsing(fn ($state): string => $state?->getLabel() ?? '-')
                    ->color(fn ($state): string => $state?->getColor() ?? 'gray'),
                RupiahColumn::make('payment_total')->label('Payment Total'),
                TextColumn::make('latest_payment_date')->label('Payment Date')->date(),
                TextColumn::make('record_status')->label('Status')->badge()->sortable(),
                TextColumn::make('log_history')->label('Log History')->state(fn (PurchaseOrder $record): int => $record->approvalLogs->count() + $record->closeRequests->sum(fn ($closeRequest): int => $closeRequest->approvalLogs->count()),
                ),
                TextColumn::make('expected_delivery_date')->label('Expected Delivery')->date()->sortable(),
                TextColumn::make('notes')->limit(35)->placeholder('-')->toggleable(),
                TextColumn::make('createdBy.name')->label('Created By')->placeholder('-')->sortable()->toggleable(),
                TextColumn::make('created_at')->label('Created At')->dateTime()->sortable()->toggleable(),
                TextColumn::make('updatedBy.name')->label('Updated By')->placeholder('-')->sortable()->toggleable(),
                TextColumn::make('updated_at')->label('Updated At')->dateTime()->sortable()->toggleable(),
                TextColumn::make('deleted_at')->label('Deleted At')->dateTime()->placeholder('-')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('deletedBy.name')->label('Deleted By')->placeholder('-')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('record_status')->options([
                    RecordStatus::Draft->value => 'Draft',
                    RecordStatus::Saved->value => 'Saved',
                    RecordStatus::Deleted->value => 'Deleted',
                ]),
                DateRangeFilter::make('po_date')->label('PO Date'),
                TrashedFilter::make(),
            ])
            ->recordActions([
                ViewAction::make()->url(fn (PurchaseOrder $record): string => PurchaseOrderResource::getUrl('view', ['record' => $record])),
                EditAction::make()
                    ->mutateDataUsing(function (array $data, PurchaseOrder $record): array {
                        $items = PurchaseOrderForm::validatedItems($data['items'] ?? [], $record->purchase_request_id);
                        $data['items'] = $items;
                        $data['subtotal'] = collect($items)->sum(fn (array $item): float => (float) $item['total_price']);
                        $data['total_amount'] = max(0, $data['subtotal'] + (float) ($data['tax_amount'] ?? 0) - (float) ($data['discount_amount'] ?? 0));

                        return $data;
                    })
                    ->after(function (PurchaseOrder $record, Action $action): void {
                        $record->items()->delete();
                        $record->items()->createMany($action->getData()['items'] ?? []);
                    })
                    ->visible(fn (PurchaseOrder $record): bool => PurchaseOrderResource::canEdit($record)),
                DeleteAction::make()->visible(fn (PurchaseOrder $record): bool => PurchaseOrderResource::canDelete($record)),
                RestoreAction::make(),
            ])
            ->recordActionsColumnLabel('Action')
            ->toolbarActions([
                TableExportActions::group(PurchaseOrderResource::class),
                BulkActionGroup::make([
                    ...TableExportActions::selected(PurchaseOrderResource::class),
                    DeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                ]),
            ]);

        return ApplicationTable::configure($table, PurchaseOrderResource::class);
    }
}

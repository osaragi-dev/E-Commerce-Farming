<?php

namespace App\Filament\Resources\GoodsReceipts\Tables;

use App\Enums\RecordStatus;
use App\Filament\Actions\TableExportActions;
use App\Filament\Resources\GoodsReceipts\GoodsReceiptResource;
use App\Filament\Tables\ApplicationTable;
use App\Models\GoodsReceipt;
use CodeWithKyrian\FilamentDateRange\Tables\Filters\DateRangeFilter;
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
use Illuminate\Database\Eloquent\Builder;

class GoodsReceiptsTable
{
    public static function configure(Table $table): Table
    {
        $table = $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with([
                'purchaseOrder.warehouse', 'purchaseOrder.vendor', 'checkedBy', 'createdBy', 'updatedBy', 'deletedBy',
                'approvalSetting.levels', 'approvalLogs',
            ])->withSum('items as accepted_total', 'accepted_quantity')->withSum('items as rejected_total', 'rejected_quantity'))
            ->columns([
                TextColumn::make('row_number')->label('No')->rowIndex(),
                TextColumn::make('approval_status')
                    ->label('Approval')
                    ->state(fn (GoodsReceipt $record): string => $record->approvalProgressLabel())
                    ->badge()
                    ->color(fn (GoodsReceipt $record): string => $record->approval_status?->getColor() ?? 'gray'),
                TextColumn::make('approval_level')
                    ->label('Progress')
                    ->state(fn (GoodsReceipt $record): string => $record->approvalProgressCountLabel())
                    ->placeholder('-'),
                TextColumn::make('receipt_number')->label('GR Number')->searchable()->sortable()->placeholder('-'),
                TextColumn::make('received_at')->label('Received Date')->dateTime('d M Y')->sortable(),
                TextColumn::make('purchaseOrder.po_number')->label('PO Number')->searchable()->sortable(),
                TextColumn::make('purchaseOrder.warehouse.name')->label('Location')->searchable()->sortable(),
                TextColumn::make('purchaseOrder.vendor.name')->label('Vendor')->searchable()->sortable(),
                TextColumn::make('delivery_note_number')->label('Delivery Note')->searchable()->placeholder('-'),
                TextColumn::make('checkedBy.name')->label('Checked By')->searchable()->placeholder('-'),
                TextColumn::make('accepted_total')->label('Total Accepted')->numeric(decimalPlaces: 3)->sortable(),
                TextColumn::make('rejected_total')->label('Total Rejected')->numeric(decimalPlaces: 3)->sortable(),
                TextColumn::make('record_status')->label('Status')->badge()->sortable(),
                TextColumn::make('approval_logs_count')->label('Log History')->counts('approvalLogs')->sortable(),
                TextColumn::make('notes')->label('Notes')->limit(40)->placeholder('-'),
                TextColumn::make('createdBy.name')->label('Created By')->placeholder('-')->sortable(),
                TextColumn::make('created_at')->label('Created At')->dateTime()->sortable(),
                TextColumn::make('updatedBy.name')->label('Updated By')->placeholder('-')->sortable(),
                TextColumn::make('updated_at')->label('Updated At')->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('record_status')->label('Status')->options([
                    RecordStatus::Draft->value => 'Draft',
                    RecordStatus::Saved->value => 'Saved',
                    RecordStatus::Deleted->value => 'Deleted',
                ]),
                DateRangeFilter::make('received_at')->label('Received Date'),
                TrashedFilter::make(),
            ])
            ->recordActions([
                ViewAction::make()->url(fn (GoodsReceipt $record): string => GoodsReceiptResource::getUrl('view', ['record' => $record])),
                EditAction::make()
                    ->url(fn (GoodsReceipt $record): string => GoodsReceiptResource::getUrl('edit', ['record' => $record]))
                    ->visible(fn (GoodsReceipt $record): bool => GoodsReceiptResource::canEdit($record)),
                DeleteAction::make()->visible(fn (GoodsReceipt $record): bool => $record->record_status === RecordStatus::Draft),
                RestoreAction::make(),
            ])
            ->recordActionsColumnLabel('Action')
            ->toolbarActions([
                TableExportActions::group(GoodsReceiptResource::class),
                BulkActionGroup::make([
                    ...TableExportActions::selected(GoodsReceiptResource::class),
                    RestoreBulkAction::make(),
                ]),
            ]);

        return ApplicationTable::configure(
            $table,
            GoodsReceiptResource::class,
            auditColumnOrder: ['created_at', 'createdBy.name', 'updated_at', 'updatedBy.name', 'deleted_at', 'deletedBy.name'],
        );
    }
}

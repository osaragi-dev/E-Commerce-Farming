<?php

namespace App\Filament\Resources\StockAdjustments\Tables;

use App\Enums\RecordStatus;
use App\Enums\StockAdjustmentApprovalStatus;
use App\Enums\StockAdjustmentSource;
use App\Filament\Actions\TableExportActions;
use App\Filament\Resources\StockAdjustments\Schemas\StockAdjustmentForm;
use App\Filament\Resources\StockAdjustments\StockAdjustmentResource;
use App\Filament\Tables\ApplicationTable;
use App\Models\ProductVariant;
use App\Models\StockAdjustment;
use CodeWithKyrian\FilamentDateRange\Tables\Filters\DateRangeFilter;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ReplicateAction;
use Filament\Actions\RestoreAction;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class StockAdjustmentsTable
{
    public static function configure(Table $table): Table
    {
        $table = $table
            ->columns([
                TextColumn::make('approval_status')
                    ->label('Approval Status')
                    ->state(fn (StockAdjustment $record): string => $record->approvalStatusLabel())
                    ->badge()
                    ->color(fn (StockAdjustment $record): string => $record->approval_status?->getColor() ?? 'gray')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('adjustment_number')
                    ->label('Adjustment Number')
                    ->searchable()
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('source')
                    ->label('Source')
                    ->badge()
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('warehouse.name')
                    ->label('Warehouse')
                    ->searchable()
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('product_summary')
                    ->label('Items')
                    ->state(fn (StockAdjustment $record): int => $record->source === StockAdjustmentSource::StockOpname
                        ? $record->stockOpnameItems->count()
                        : 1)
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->where(function (Builder $query) use ($search): void {
                        $query
                            ->whereHas('productVariant', fn (Builder $variantQuery): Builder => $variantQuery->where('name', 'like', "%{$search}%")
                                ->orWhereHas('product', fn (Builder $productQuery): Builder => $productQuery->where('name', 'like', "%{$search}%")))
                            ->orWhereHas('stockOpnameItems.productVariant', fn (Builder $variantQuery): Builder => $variantQuery->where('name', 'like', "%{$search}%")
                                ->orWhereHas('product', fn (Builder $productQuery): Builder => $productQuery->where('name', 'like', "%{$search}%")));
                    }))
                    ->toggleable(),
                TextColumn::make('type_summary')
                    ->label('Type')
                    ->badge()
                    ->toggleable(),
                TextColumn::make('record_status')
                    ->label('Status')
                    ->badge()
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('approval_level')
                    ->label('Approval Level')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('quantity_summary')
                    ->label('Quantity')
                    ->toggleable(),
                TextColumn::make('created_at')
                    ->label('Created At')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('createdBy.name')
                    ->label('Created By')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->label('Updated At')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updatedBy.name')
                    ->label('Updated By')
                    ->placeholder('-')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('deleted_at')
                    ->label('Deleted At')
                    ->dateTime()
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('deletedBy.name')
                    ->label('Deleted By')
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('warehouse_id')
                    ->relationship('warehouse', 'name')
                    ->label('Warehouse'),
                SelectFilter::make('source')->options(StockAdjustmentSource::class),
                SelectFilter::make('product_variant_id')
                    ->label('Product Variant')
                    ->options(fn () => ProductVariant::query()->inventoryVisible()->pluck('name', 'id'))
                    ->query(function (Builder $query, array $data): Builder {
                        $variantId = $data['value'] ?? null;

                        if (blank($variantId)) {
                            return $query;
                        }

                        return $query->where(fn (Builder $query): Builder => $query
                            ->where('product_variant_id', $variantId)
                            ->orWhereHas('stockOpnameItems', fn (Builder $itemsQuery): Builder => $itemsQuery->where('product_variant_id', $variantId)));
                    }),
                DateRangeFilter::make('created_at')
                    ->label('Date')
                    ->displayFormat('d/m/Y')
                    ->startPlaceholder('dd/mm/yyyy')
                    ->endPlaceholder('dd/mm/yyyy')
                    ->firstDayOfWeek(1)
                    ->dualCalendar(false)
                    ->inline(true)
                    ->locale('en')
                    ->timezone('Asia/Jakarta'),
                TrashedFilter::make(),
            ])
            // TCS revision (20/8): panel filter default terlalu sempit,
            // bikin field Date Range perlu di-scroll buat lihat tanggal
            // lengkap. Dilebarin biar muat tanpa geser.
            ->filtersFormWidth(Width::Large)
            // TCS revision (22/8): default Filament pecah kata pencarian
            // jadi token per kata, terus nyari tiap token itu di SEMUA
            // kolom searchable secara terpisah — bikin "Grade A" ikut
            // nampilin "Grade B" (gara-gara nama Product-nya kebetulan
            // ada huruf "a"). Dimatikan biar cari FRASA UTUH.
            ->splitSearchTerms(false)
            ->recordActions([
                EditAction::make()
                    ->databaseTransaction()
                    ->modalWidth(Width::TwoExtraLarge)
                    ->visible(fn (StockAdjustment $record): bool => StockAdjustmentResource::canEdit($record))
                    ->mutateDataUsing(fn (array $data, array $arguments): array => [
                        ...$data,
                        'record_status' => ($arguments['draft'] ?? false) ? RecordStatus::Draft : RecordStatus::Saved,
                        ...(($arguments['draft'] ?? false) ? [
                            'approval_status' => StockAdjustmentApprovalStatus::Pending,
                            'approval_level' => 0,
                        ] : []),
                    ])
                    ->modalSubmitActionLabel('Submit')
                    ->extraModalFooterActions(fn (EditAction $action): array => [
                        $action->makeModalSubmitAction('saveDraft', ['draft' => true])
                            ->label('Save as Draft')
                            ->icon('heroicon-o-document')
                            ->color('gray'),
                    ]),
                Action::make('view')
                    ->label('View')
                    ->icon('heroicon-o-eye')
                    ->color('gray')
                    ->url(fn (StockAdjustment $record): string => StockAdjustmentResource::getUrl('view', [
                        'record' => $record,
                    ]))
                    ->authorize(fn (StockAdjustment $record): bool => auth()->user()?->can('view', $record) ?? false),
                DeleteAction::make()
                    ->label('Delete')
                    ->visible(fn (StockAdjustment $record): bool => StockAdjustmentResource::canDelete($record)),
                RestoreAction::make()
                    ->label('Restore')
                    ->visible(fn (StockAdjustment $record): bool => StockAdjustmentResource::canRestore($record)),
                ReplicateAction::make('clone')
                    ->databaseTransaction()
                    ->label('Clone')
                    ->color('primary')
                    ->icon('heroicon-o-document-duplicate')
                    ->modalWidth(Width::TwoExtraLarge)
                    // TCS revision (22/8): konsepnya samain kayak Clone
                    // di Master Data — klik Clone harus munculin FORM
                    // LENGKAP (sama seperti form New Stock Adjustment),
                    // bukan cuma kotak konfirmasi "yakin replicate?"
                    // polos seperti default ReplicateAction.
                    ->schema(fn (Schema $schema): Schema => StockAdjustmentForm::configure($schema))
                    ->modalSubmitAction(fn (Action $action): Action => $action
                        ->label('Create')
                        ->color('primary'))
                    ->extraModalFooterActions(fn (ReplicateAction $action): array => [
                        $action->makeModalSubmitAction('saveDraft', ['draft' => true])
                            ->label('Save as Draft')
                            ->icon('heroicon-o-document')
                            ->color('gray'),
                    ])
                    ->beforeReplicaSaved(function (StockAdjustment $replica, array $arguments): void {
                        $replica->record_status = ($arguments['draft'] ?? false) ? RecordStatus::Draft : RecordStatus::Saved;
                    })
                    ->excludeAttributes([
                        'id',
                        'adjustment_number',
                        'record_status',
                        'previous_record_status',
                        'created_by',
                        'deleted_by',
                        'deleted_at',
                        'adjusted_by',
                        'updated_by',
                        'created_at',
                        'updated_at',
                        'approval_status',
                        'approval_level',
                        'approval_setting_id',
                        'applied_at',
                        'applied_by',
                    ])
                    // TCS revision (24/8): excludeAttributes() ternyata
                    // tidak selalu cukup buat nge-reset nilai yang sudah
                    // kepenuhan di FORM waktu Clone (beda dari nilai yang
                    // beneran disimpan ke database). Dipaksa manual di sini
                    // biar field Date & Time SELALU tampil waktu SEKARANG,
                    // bukan ikutan tanggal data asli yang di-clone.
                    ->mutateRecordDataUsing(function (array $data): array {
                        $data['source'] = StockAdjustmentSource::DirectAdjustment->value;
                        $data['created_at'] = now();

                        return $data;
                    }),
            ])
            ->recordActionsColumnLabel('Action')
            ->toolbarActions([
                TableExportActions::group(StockAdjustmentResource::class),
                BulkActionGroup::make([
                    ...TableExportActions::selected(StockAdjustmentResource::class),
                ])
                    ->label('Bulk Actions')
                    ->icon('heroicon-o-squares-plus')
                    ->color('gray')
                    ->button(),
            ])
            ->defaultSort('id', 'desc');

        $table->modifyQueryUsing(fn ($query) => $query->with([
            'productVariant.product',
            'stockOpnameItems.productVariant.product',
        ]));

        return ApplicationTable::configureColumnManager($table);
    }
}

<?php

namespace App\Filament\Resources\StockWastes\Tables;

use App\Enums\RecordStatus;
use App\Enums\StockWasteApprovalStatus;
use App\Enums\StockWasteSource;
use App\Enums\StockWasteStatus;
use App\Enums\StockWasteType;
use App\Filament\Actions\LifecycleEditAction;
use App\Filament\Actions\TableExportActions;
use App\Filament\Resources\StockWastes\Schemas\StockWasteForm;
use App\Filament\Resources\StockWastes\StockWasteResource;
use App\Filament\Tables\ApplicationTable;
use App\Models\ProductVariant;
use App\Models\StockWaste;
use App\Models\Warehouse;
use App\Support\Quantity;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ReplicateAction;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

class StockWastesTable
{
    public static function configure(Table $table): Table
    {
        $table = $table
            ->columns([
                TextColumn::make('row_number')->label('No')->rowIndex(),
                TextColumn::make('waste_number')->label('Waste Number')->searchable()->sortable()->copyable(),
                TextColumn::make('warehouse.name')->label('Location')->searchable()->sortable(),
                TextColumn::make('waste_date')->label('Date')->date()->sortable(),
                TextColumn::make('productVariant.name')
                    ->label('Item')
                    ->state(fn (StockWaste $record): string => collect([
                        $record->productVariant?->product?->name,
                        $record->productVariant?->name,
                    ])->filter()->implode(' - '))
                    ->searchable(['productVariant.name', 'productVariant.product.name']),
                TextColumn::make('quantity')->formatStateUsing(fn ($state): string => Quantity::format($state))->sortable(),
                TextColumn::make('productVariant.unit.name')->label('Unit')->sortable(),
                TextColumn::make('waste_type')->label('Waste Type')->badge()->sortable(),
                TextColumn::make('record_status')->label('Record Status')->badge()->sortable(),
                TextColumn::make('waste_status')->label('Waste Status')->badge()->sortable(),
                TextColumn::make('reason')->limit(45)->placeholder('-')->toggleable(),
                TextColumn::make('created_at')->label('Created At')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('createdBy.name')->label('Created By')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')->label('Updated At')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updatedBy.name')->label('Updated By')->placeholder('-')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('deleted_at')->label('Deleted At')->dateTime()->placeholder('-')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('deletedBy.name')->label('Deleted By')->placeholder('-')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('record_status')->options(RecordStatus::class),
                SelectFilter::make('source')->options(StockWasteSource::class),
                SelectFilter::make('waste_type')->options(StockWasteType::class),
                SelectFilter::make('warehouse_id')->label('Location')->options(fn (): array => Warehouse::query()->orderBy('name')->pluck('name', 'id')->all()),
                SelectFilter::make('product_variant_id')->label('Item')->options(fn (): array => ProductVariant::query()->inventoryVisible()->with('product')->get()->mapWithKeys(fn (ProductVariant $variant): array => [$variant->id => $variant->product->name.' - '.$variant->name])->all()),
                TrashedFilter::make(),
            ])
            ->splitSearchTerms(false)
            ->recordUrl(fn (StockWaste $record): ?string => auth()->user()?->can('view', $record)
                ? StockWasteResource::getUrl('view', ['record' => $record])
                : null)
            ->recordActions([
                Action::make('view')->label('View')->icon('heroicon-o-eye')->color('gray')
                    ->url(fn (StockWaste $record): string => StockWasteResource::getUrl('view', ['record' => $record]))
                    ->authorize(fn (StockWaste $record): bool => auth()->user()?->can('view', $record) ?? false),
                LifecycleEditAction::configure(EditAction::make('edit')
                    ->label('Edit')
                    ->schema(fn (Schema $schema): Schema => StockWasteForm::configure($schema))
                    ->visible(fn (StockWaste $record): bool => StockWasteResource::canEdit($record))
                    ->databaseTransaction())
                    ->after(function (StockWaste $record): void {
                        if ($record->isPostable()) {
                            $record->post(auth()->id());
                        }
                    }),
                DeleteAction::make('delete')
                    ->label('Delete')
                    ->visible(fn (StockWaste $record): bool => StockWasteResource::canDelete($record)),
                ReplicateAction::make('clone')
                    ->label('Clone')
                    ->color('primary')
                    ->icon('heroicon-o-document-duplicate')
                    ->modalWidth(Width::TwoExtraLarge)
                    ->schema(fn (Schema $schema): Schema => StockWasteForm::configure($schema))
                    ->modalSubmitAction(fn (Action $action): Action => $action->label('Create')->color('primary'))
                    ->extraModalFooterActions(fn (ReplicateAction $action): array => [
                        $action->makeModalSubmitAction('saveDraft', ['draft' => true])
                            ->label('Save as Draft')
                            ->icon('heroicon-o-document')
                            ->color('gray')
                            ->extraAttributes(['formnovalidate' => true]),
                    ])
                    ->authorize(fn (StockWaste $record): bool => StockWasteResource::canCreate()
                        && (auth()->user()?->can('view', $record) ?? false))
                    ->databaseTransaction()
                    ->excludeAttributes([
                        'id',
                        'waste_number',
                        'record_status',
                        'previous_record_status',
                        'created_by',
                        'updated_by',
                        'deleted_by',
                        'deleted_at',
                        'created_at',
                        'updated_at',
                        'waste_status',
                        'approval_status',
                        'approval_level',
                        'approval_setting_id',
                        'posted_at',
                        'posted_by',
                    ])
                    ->beforeReplicaSaved(function (StockWaste $replica, array $arguments): void {
                        $isDraft = (bool) ($arguments['draft'] ?? false);

                        $replica->forceFill([
                            'record_status' => $isDraft ? RecordStatus::Draft : RecordStatus::Saved,
                            'previous_record_status' => null,
                            'waste_status' => StockWasteStatus::Saved,
                            'approval_status' => $isDraft
                                ? StockWasteApprovalStatus::Pending
                                : StockWasteApprovalStatus::Approved,
                            'approval_level' => 0,
                            'approval_setting_id' => null,
                            'posted_at' => null,
                            'posted_by' => null,
                        ]);
                    })
                    ->after(function (StockWaste $replica): void {
                        if ($replica->isPostable()) {
                            $replica->post(auth()->id());
                        }
                    }),
            ])
            ->recordActionsColumnLabel('Action')
            ->toolbarActions([
                TableExportActions::group(StockWasteResource::class),
                BulkActionGroup::make([...TableExportActions::selected(StockWasteResource::class)])->label('Bulk Actions'),
            ])
            ->defaultSort('waste_date', 'desc');

        return ApplicationTable::configureColumnManager($table);
    }
}

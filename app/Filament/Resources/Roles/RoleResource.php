<?php

declare(strict_types=1);

namespace App\Filament\Resources\Roles;

use App\Filament\Contracts\HasTableExport;
use App\Filament\Exports\TableExportDefinition;
use App\Filament\Resources\Roles\Pages\CreateRole;
use App\Filament\Resources\Roles\Pages\EditRole;
use App\Filament\Resources\Roles\Pages\ListRoles;
use App\Filament\Resources\Roles\Pages\ViewRole;
use App\Filament\Resources\Stocks\StockResource;
use App\Filament\Tables\ApplicationTable;
use App\Models\Role;
use BezhanSalleh\FilamentShield\Facades\FilamentShield;
use BezhanSalleh\FilamentShield\FilamentShieldPlugin;
use BezhanSalleh\FilamentShield\Support\Utils;
use BezhanSalleh\FilamentShield\Traits\HasShieldFormComponents;
use BezhanSalleh\PluginEssentials\Concerns\Resource as Essentials;
use CodeWithKyrian\FilamentDateRange\Tables\Filters\DateRangeFilter;
use Filament\Actions\EditAction;
use Filament\Actions\ReplicateAction;
use Filament\Actions\ViewAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Panel;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\Width;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Unique;
use Override;

class RoleResource extends Resource implements HasTableExport
{
    use Essentials\BelongsToParent;
    use Essentials\BelongsToTenant;
    use Essentials\HasGlobalSearch;
    use Essentials\HasLabels;
    use Essentials\HasNavigation;
    use HasShieldFormComponents;

    protected static ?string $recordTitleAttribute = 'name';

    /**
     * Settings > Roles hanya untuk mengelola Role & Permission Internal
     * Account (Admin/Staff) — TIDAK BOLEH menyentuh legacy role Customer.
     * Role 'customer' dikecualikan total dari resource ini: tidak muncul di
     * listing, tidak bisa dibuka/diedit/di-Clone lewat UI ini sama sekali.
     *
     * Role ini dihapus oleh migration pemisahan akun. Exclusion tetap
     * mencegah role legacy muncul saat deployment dilakukan bertahap.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('name', '!=', 'customer');
    }

    #[Override]
    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make()
                    ->schema([
                        TextInput::make('code')
                            ->unique(ignoreRecord: true)
                            ->required()
                            ->maxLength(30),

                        TextInput::make('name')
                            ->label(__('filament-shield::filament-shield.field.name'))
                            ->unique(
                                ignoreRecord: true, /** @phpstan-ignore-next-line */
                                modifyRuleUsing: fn (Unique $rule): Unique => Utils::isTenancyEnabled() ? $rule->where(Utils::getTenantModelForeignKey(), Filament::getTenant()?->id) : $rule
                            )
                            ->required()
                            ->maxLength(255),

                        Hidden::make('guard_name')
                            ->default(Utils::getFilamentAuthGuard())
                            ->dehydrateStateUsing(fn (): string => Utils::getFilamentAuthGuard()),

                        Select::make(config('permission.column_names.team_foreign_key'))
                            ->label(__('filament-shield::filament-shield.field.team'))
                            ->placeholder(__('filament-shield::filament-shield.field.team.placeholder'))
                            /** @phpstan-ignore-next-line */
                            ->default(Filament::getTenant()?->id)
                            ->options(fn (): array => in_array(Utils::getTenantModel(), [null, '', '0'], true) ? [] : Utils::getTenantModel()::pluck('name', 'id')->toArray())
                            ->visible(fn (): bool => static::shield()->isCentralApp() && Utils::isTenancyEnabled())
                            ->dehydrated(fn (): bool => static::shield()->isCentralApp() && Utils::isTenancyEnabled()),
                        static::getSelectAllFormComponent(),

                    ])
                    ->columns(2)
                    ->columnSpanFull(),
                static::getShieldFormComponents(),
            ]);
    }

    public static function getTabFormComponentForResources(): Component
    {
        return Tab::make('resources')
            ->label(__('filament-shield::filament-shield.resources'))
            ->badge(static::getResourceTabBadgeCount())
            ->schema([
                Grid::make()->schema(static::getResourceEntitiesSchema())
                    ->columns(static::shield()->getGridColumns()),
            ]);
    }

    public static function getResourceEntitiesSchema(): array
    {
        $navigation = [];
        foreach (Filament::getCurrentOrDefaultPanel()->getNavigation() as $group) {
            foreach ($group->getItems() as $item) {
                $navigation[rtrim((string) $item->getUrl(), '/')] = count($navigation);
            }
        }

        return collect(static::modulePermissionOptions())
            ->sortBy(fn (array $options, string $resource): array => [
                $navigation[rtrim($resource::getUrl('index'), '/')] ?? PHP_INT_MAX,
                $resource::getNavigationLabel(),
            ])
            ->map(fn (array $options, string $resource): Section => Section::make($resource::getNavigationLabel())
                ->description(static::moduleDescription($resource))
                ->icon($resource::getNavigationIcon())
                ->compact()
                ->schema([
                    static::getCheckboxListFormComponent(
                        name: $resource,
                        options: $options,
                        searchable: false,
                        columns: static::shield()->getResourceCheckboxListColumns(),
                        columnSpan: static::shield()->getResourceCheckboxListColumnSpan(),
                    )->label('Permissions'),
                ])
                ->columnSpan(static::shield()->getSectionColumnSpan())
                ->collapsible())
            ->values()->all();
    }

    public static function getResourceTabBadgeCount(): int
    {
        return count(array_merge(...array_values(static::modulePermissionOptions())));
    }

    /** @return array<class-string<resource>, array<string, string>> */
    private static function modulePermissionOptions(): array
    {
        $custom = FilamentShield::getCustomPermissions(static::shield()->hasLocalizedPermissionLabels()) ?? [];
        $modules = [];

        foreach (FilamentShield::getResources() as $entity) {
            $subjects = $entity['model'] === 'Order' ? ['Order', 'Invoice'] : [$entity['model']];
            $modules[$entity['resourceFqcn']] = static::getResourcePermissionOptions($entity)
                + array_filter($custom, fn (string $key): bool => in_array(Str::afterLast($key, ':'), $subjects, true), ARRAY_FILTER_USE_KEY);
        }

        $modules[StockResource::class] = array_filter(
            $custom,
            fn (string $key): bool => Str::afterLast($key, ':') === 'Stock',
            ARRAY_FILTER_USE_KEY,
        );

        return $modules;
    }

    /** @return array<string, string> */
    private static function remainingCustomPermissions(): array
    {
        return array_diff_key(
            FilamentShield::getCustomPermissions(static::shield()->hasLocalizedPermissionLabels()) ?? [],
            array_merge(...array_values(static::modulePermissionOptions())),
        );
    }

    public static function getTabFormComponentForCustomPermissions(): Component
    {
        $options = static::remainingCustomPermissions();

        return Tab::make('custom_permissions')
            ->label('General')
            ->visible($options !== [])
            ->badge(count($options))
            ->schema([
                static::getCheckboxListFormComponent('custom_permissions_tab', $options)->label('Permissions'),
            ]);
    }

    private static function moduleDescription(string $resource): string
    {
        return match (class_basename($resource)) {
            'SectorResource' => 'Organize products by business sector.',
            'CategoryResource' => 'Group products into categories.',
            'UnitResource' => 'Define units used for product quantities and sales.',
            'ProductResource' => 'Manage products, variants, pricing, and availability.',
            'WarehouseResource' => 'Manage storage locations and warehouse settings.',
            'CustomerResource' => 'Manage customer profiles and contact details.',
            'ProductDisplaySettingResource' => 'Choose which products appear on the storefront.',
            'StockResource' => 'Review available stock and update inventory quantities.',
            'StockMovementResource' => 'Dispatch, receive, reject, and cancel warehouse transfers.',
            'StockOpnameResource' => 'Count physical inventory and reconcile stock differences.',
            'StockAdjustmentResource' => 'Record inventory corrections and their reasons.',
            'StockWasteResource' => 'Record, approve, and post inventory waste.',
            'StockMutationResource' => 'Review stock movements and inventory transaction history.',
            'OrderResource' => 'Manage orders, fulfillment, and invoices.',
            'PaymentResource' => 'Review payments, verify proof, and handle rejected or voided payments.',
            'UserResource' => 'Manage back-office role assignments and user impersonation.',
            'RoleResource' => 'Configure roles and access permissions for each module.',
            'CompanyProfileResource' => 'Maintain company details used in documents and the storefront.',
            'ShippingSettingResource' => 'Configure delivery options and shipping charges.',
            'PaymentSettingResource' => 'Configure accepted payment methods and payment instructions.',
            'ApprovalSettingResource' => 'Configure approval levels and authorized approvers.',
            default => 'Manage this module and its records.',
        };
    }

    #[Override]
    public static function table(Table $table): Table
    {
        $table = $table
            ->columns([
                TextColumn::make('code')
                    ->searchable(),
                TextColumn::make('name')
                    ->weight(FontWeight::Medium)
                    ->label(__('filament-shield::filament-shield.column.name'))
                    ->formatStateUsing(fn (string $state): string => Str::headline($state))
                    ->searchable(),
                TextColumn::make('team.name')
                    ->default('Global')
                    ->badge()
                    ->color(fn (mixed $state): string => str($state)->contains('Global') ? 'gray' : 'primary')
                    ->label(__('filament-shield::filament-shield.column.team'))
                    ->searchable()
                    ->visible(fn (): bool => static::shield()->isCentralApp() && Utils::isTenancyEnabled()),
                TextColumn::make('permissions_count')
                    ->badge()
                    ->label(__('filament-shield::filament-shield.column.permissions'))
                    ->counts('permissions')
                    ->color('primary'),
                TextColumn::make('created_at')
                    ->label('Created At')
                    ->dateTime()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('createdBy.name')
                    ->label('Created By')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->label(__('filament-shield::filament-shield.column.updated_at'))
                    ->dateTime(),
                TextColumn::make('updatedBy.name')
                    ->label('Updated By')
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('created_by')
                    ->relationship('createdBy', 'name')
                    ->label('Created By'),
                SelectFilter::make('updated_by')
                    ->relationship('updatedBy', 'name')
                    ->label('Updated By'),
                DateRangeFilter::make('created_at')->label('Created Date'),
            ])
            ->filtersFormWidth(Width::Small)
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                ApplicationTable::cloneAction(static::class, hasLifecycle: false)
                    ->excludeAttributes([
                        'id',
                        'sso_id',
                        'permissions_count',
                        'created_at',
                        'updated_at',
                        'deleted_at',
                    ])
                    ->mutateRecordDataUsing(fn (array $data, Role $record): array => $data + static::getClonePermissionState($record))
                    ->beforeReplicaSaved(function (Role $replica): void {
                        $replica->guard_name = Utils::getFilamentAuthGuard();
                    })
                    ->after(function (ReplicateAction $action, array $data): void {
                        $replica = $action->getReplica();

                        if ($replica instanceof Role) {
                            static::syncClonePermissions($replica, $data);
                        }
                    }),
            ]);

        return ApplicationTable::configure(
            $table,
            static::class,
            hasLifecycle: false,
            auditColumnOrder: ['created_at', 'createdBy.name', 'updated_at', 'updatedBy.name'],
        );
    }

    public static function exportDefinition(): TableExportDefinition
    {
        return new TableExportDefinition(
            title: 'Roles',
            columns: [
                'code' => 'Code',
                'name' => 'Name',
                'permissions_count' => 'Permissions',
                'created_at' => 'Created At',
                'updated_at' => 'Updated At',
                'deleted_at' => 'Deleted At',
            ],
        );
    }

    #[Override]
    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRoles::route('/'),
            'create' => CreateRole::route('/create'),
            'view' => ViewRole::route('/{record}'),
            'edit' => EditRole::route('/{record}/edit'),
        ];
    }

    #[Override]
    public static function getModel(): string
    {
        return Utils::getRoleModel();
    }

    public static function getSlug(?Panel $panel = null): string
    {
        return Utils::getResourceSlug();
    }

    public static function getCluster(): ?string
    {
        return Utils::getResourceCluster();
    }

    public static function getEssentialsPlugin(): ?FilamentShieldPlugin
    {
        return FilamentShieldPlugin::get();
    }

    /** @param array<string, mixed> $data */
    private static function syncClonePermissions(Role $role, array $data): void
    {
        $permissionModels = collect($data)
            ->except([
                'code',
                'name',
                'guard_name',
                'select_all',
                Utils::getTenantModelForeignKey(),
            ])
            ->flatten()
            ->unique()
            ->map(fn (string $permission): Model => Utils::getPermissionModel()::firstOrCreate([
                'name' => $permission,
                'guard_name' => $role->guard_name,
            ]));

        $role->syncPermissions($permissionModels);
    }

    /**
     * Builds the initial checkbox-list state for the Clone form, mirroring
     * the exact field names/options Shield's own permission matrix uses
     * (see HasShieldFormComponents::getCheckboxListFormComponent() calls),
     * pre-checking only the permissions the source Role actually owns.
     *
     * @return array<string, list<string>>
     */
    private static function getClonePermissionState(Role $source): array
    {
        $owned = $source->getPermissionNames()->all();
        $state = [];

        foreach (static::modulePermissionOptions() as $resourceFqcn => $options) {
            $state[$resourceFqcn] = array_values(array_intersect(array_keys($options), $owned));
        }

        $state['pages_tab'] = array_values(array_intersect(array_keys(static::getPageOptions()), $owned));
        $state['widgets_tab'] = array_values(array_intersect(array_keys(static::getWidgetOptions()), $owned));
        $state['custom_permissions_tab'] = array_values(array_intersect(
            array_keys(static::remainingCustomPermissions()),
            $owned,
        ));

        return $state;
    }
}

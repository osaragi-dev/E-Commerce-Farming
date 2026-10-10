<?php

namespace App\Providers\Filament;

use AchyutN\FilamentLogViewer\FilamentLogViewer;
use App\Filament\Livewire\PermissionFilteredDatabaseNotifications;
use App\Filament\Pages\Auth\Login;
use App\Filament\Pages\ChangePassword;
use App\Filament\Pages\Dashboard;
use App\Filament\Pages\Pos;
use App\Filament\Pages\PrinterSettings;
use App\Filament\Resources\Orders\Pages\ManageOrders;
use App\Filament\Resources\Payments\Pages\ManagePayments;
use App\Filament\Widgets\AdminStatsOverview;
use App\Filament\Widgets\LowStockWidget;
use App\Filament\Widgets\OrderPipelineChart;
use App\Filament\Widgets\SalesTrendChart;
use App\Filament\Widgets\StockOverview;
use App\Http\Middleware\EnsureAdminPasswordChanged;
use BezhanSalleh\FilamentShield\FilamentShieldPlugin;
use Caresome\FilamentAuthDesigner\AuthDesignerPlugin;
use Caresome\FilamentAuthDesigner\Data\AuthPageConfig;
use Filament\Actions\Action as FilamentAction;
use Filament\Facades\Filament;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Vite;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Octopy\Filament\Palette\PaletteSwitcherPlugin;
use pxlrbt\FilamentEnvironmentIndicator\EnvironmentIndicatorPlugin;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        FilamentAction::configureUsing(
            fn (FilamentAction $action): FilamentAction => $action
                ->icon(self::iconForAction($action->getName()))
                ->extraAttributes(['formnovalidate' => true]),
        );

        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->renderHook(PanelsRenderHook::PAGE_END, fn () => view('filament.printer-controls'), scopes: [ManageOrders::class, ManagePayments::class, PrinterSettings::class, Pos::class])
            ->renderHook(PanelsRenderHook::HEAD_END, fn () => Vite::withEntryPoints(['resources/js/pos-cart.js'])->toHtml(), scopes: [Pos::class])
            ->viteTheme('resources/css/filament/admin/theme.css')
            ->brandName(config('app.name'))
            ->globalSearch(false)
            ->colors([
                // Warna forest-green Farming (selaras dengan palet brand
                // storefront publik di resources/css/public.css: --forest
                // #1f4d3d) — QA request: "kasih warna hijau seperti logo
                // farming".
                'primary' => Color::hex('#1f4d3d'),
            ])
            ->sidebarCollapsibleOnDesktop()
            ->navigationGroups([
                'Master Data',
                'Procurement',
                'Inventory',
                'Operations',
                'Settings',
            ])
            ->databaseNotifications(livewireComponent: PermissionFilteredDatabaseNotifications::class)
            ->databaseNotificationsPolling('30s')
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([
                AdminStatsOverview::class,
                SalesTrendChart::class,
                OrderPipelineChart::class,
                StockOverview::class,
                LowStockWidget::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
                EnsureAdminPasswordChanged::class,
            ], isPersistent: true)
            ->authPasswordBroker('users')
            ->passwordReset()
            ->userMenuItems([
                FilamentAction::make('changePassword')
                    ->label('Change Password')
                    ->icon('heroicon-o-key')
                    ->url(fn (): string => ChangePassword::getUrl()),
            ])
            ->plugins([
                AuthDesignerPlugin::make()
                    ->login(fn (AuthPageConfig $config): AuthPageConfig => $config->usingPage(Login::class))
                    ->themeToggle(),
                FilamentShieldPlugin::make()
                    ->navigationGroup('Settings')
                    ->navigationSort(20),
                EnvironmentIndicatorPlugin::make(),
                PaletteSwitcherPlugin::make(),
                FilamentLogViewer::make()
                    ->navigationGroup('Operations')
                    ->navigationLabel('Log Viewer')
                    ->navigationSort(3)
                    ->authorize(fn (): bool => Filament::auth()->user()?->can('View:LogTable') ?? false),
            ]);
    }

    private static function iconForAction(string $name): string
    {
        return match ($name) {
            'create', 'createAnother' => 'heroicon-o-plus',
            'createDraft', 'saveDraft' => 'heroicon-o-document',
            'view' => 'heroicon-o-eye',
            'edit' => 'heroicon-o-pencil-square',
            'delete' => 'heroicon-o-trash',
            'restore' => 'heroicon-o-arrow-path',
            'replicate', 'clone' => 'heroicon-o-document-duplicate',
            'cancel', 'close' => 'heroicon-o-x-mark',
            'submit', 'save' => 'heroicon-o-check',
            'export', 'exportExcel', 'exportCsv', 'exportPdf' => 'heroicon-o-arrow-down-tray',
            default => 'heroicon-o-cursor-arrow-rays',
        };
    }
}

<?php

namespace App\Providers;

use App\Models\CompanyProfile;
use App\Models\DirectOrder;
use App\Models\GoodsReceipt;
use App\Models\PaymentVoidRequest;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderCloseRequest;
use App\Models\PurchaseRequest;
use App\Models\Sector;
use App\Models\StockAdjustment;
use App\Models\StockMovement;
use App\Models\StockOpnameSession;
use App\Models\SystemFreezeState;
use App\Models\User;
use App\Models\Vendor;
use App\Observers\ApprovalRequestObserver;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ReplicateAction;
use Filament\Support\Facades\FilamentView;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Filament\Tables\View\TablesRenderHook;
use Filament\View\PanelsRenderHook;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Illuminate\View\View as BladeView;
use Livewire\Livewire;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        StockAdjustment::observe(ApprovalRequestObserver::class);
        StockMovement::observe(ApprovalRequestObserver::class);
        StockOpnameSession::observe(ApprovalRequestObserver::class);
        PaymentVoidRequest::observe(ApprovalRequestObserver::class);
        PurchaseRequest::observe(ApprovalRequestObserver::class);
        DirectOrder::observe(ApprovalRequestObserver::class);
        PurchaseOrder::observe(ApprovalRequestObserver::class);
        PurchaseOrderCloseRequest::observe(ApprovalRequestObserver::class);
        GoodsReceipt::observe(ApprovalRequestObserver::class);
        Vendor::observe(ApprovalRequestObserver::class);

        Livewire::setUpdateRoute(
            fn (array $handle, string $path): \Illuminate\Routing\Route => Route::post($path, $handle),
        );

        View::composer('components.public.footer', function (BladeView $view): void {
            $view->with([
                'footerSectors' => Sector::query()->saved()->where('is_active', true)->orderBy('sort_order')->get(['id', 'name', 'slug']),
                'companyProfile' => CompanyProfile::current(),
            ]);
        });

        Gate::before(
            fn (User $user): ?bool => $user->roles()
                ->whereRaw('LOWER(name) = ?', ['super_admin'])
                ->exists() ? true : null,
        );

        Table::configureUsing(
            fn (Table $table): Table => $table
                ->paginationPageOptions([10, 25, 50, 100, 'all']),
        );

        EditAction::configureUsing(fn (EditAction $action): EditAction => $action
            ->color('warning')
            ->modalSubmitAction(fn (Action $action): Action => $action->color('primary')));
        ReplicateAction::configureUsing(fn (ReplicateAction $action): ReplicateAction => $action->color('info'));

        FilamentView::registerRenderHook(
            TablesRenderHook::TOOLBAR_START,
            function () {
                $livewire = Livewire::current();
                $table = $livewire instanceof HasTable ? $livewire->getTable() : null;

                return view('filament.tables.records-per-page', [
                    'isPaginated' => $table?->isPaginated() ?? false,
                    'pageOptions' => $table?->getPaginationPageOptions() ?? [],
                ]);
            },
        );

        FilamentView::registerRenderHook(
            PanelsRenderHook::STYLES_AFTER,
            fn () => view('filament.tables.styles'),
        );

        FilamentView::registerRenderHook(
            PanelsRenderHook::TOPBAR_BEFORE,
            fn () => SystemFreezeState::current()->is_frozen
                ? view('filament.system-freeze-banner')
                : null,
        );

        FilamentView::registerRenderHook(
            PanelsRenderHook::SCRIPTS_AFTER,
            fn () => view('filament.order-notification-sound'),
        );
    }
}

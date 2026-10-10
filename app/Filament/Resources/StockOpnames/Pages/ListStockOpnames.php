<?php

namespace App\Filament\Resources\StockOpnames\Pages;

use App\Enums\StockOpnameStatus;
use App\Filament\Resources\StockOpnames\StockOpnameResource;
use App\Models\StockOpnameSession;
use App\Models\SystemFreezeState;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Auth;

class ListStockOpnames extends ListRecords
{
    protected static string $resource = StockOpnameResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('create')
                ->label('Create Stock Opname')
                ->icon('heroicon-o-plus')
                ->url(fn (): string => StockOpnameResource::getUrl('create'))
                ->disabled(fn (): bool => ! SystemFreezeState::current()->is_frozen)
                ->visible(fn (): bool => StockOpnameResource::canCreate()),
            Action::make('freeze')
                ->label('Freeze')
                ->icon('heroicon-o-lock-closed')
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading('Freeze System')
                ->modalDescription('Freezing the system blocks all transaction changes while Master Data and Settings remain available. Continue?')
                ->action(function (): void {
                    $session = StockOpnameSession::query()
                        ->whereIn('status', [StockOpnameStatus::Draft, StockOpnameStatus::Counting])
                        ->latest('created_at')
                        ->first();

                    SystemFreezeState::activate($session, Auth::id());

                    Notification::make()->title('System frozen successfully.')->success()->send();
                })
                ->authorize(fn (): bool => StockOpnameResource::canCreate())
                ->visible(fn (): bool => StockOpnameResource::canCreate() && ! SystemFreezeState::current()->is_frozen),
            Action::make('unfreeze')
                ->label('Unfreeze')
                ->icon('heroicon-o-lock-open')
                ->color('warning')
                ->requiresConfirmation()
                ->modalHeading('Unfreeze System')
                ->modalDescription('All transaction modules will become available again. Continue?')
                ->action(function (): void {
                    SystemFreezeState::deactivate(Auth::id());

                    Notification::make()->title('System unfrozen successfully.')->success()->send();
                })
                ->authorize(fn (): bool => StockOpnameResource::canCreate())
                ->visible(fn (): bool => StockOpnameResource::canCreate() && SystemFreezeState::current()->is_frozen),
        ];
    }
}

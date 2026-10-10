<?php

namespace App\Filament\Livewire;

use App\Models\User;
use App\Notifications\NewOrderForAdmins;
use App\Notifications\StockLevelAlertForAdmins;
use App\Notifications\StockMovementRecommendationForAdmins;
use Filament\Livewire\DatabaseNotifications as FilamentDatabaseNotifications;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;

class PermissionFilteredDatabaseNotifications extends FilamentDatabaseNotifications
{
    /** @var array<class-string, string> */
    private const array NOTIFICATION_PERMISSIONS = [
        NewOrderForAdmins::class => 'ViewAny:Order',
        StockLevelAlertForAdmins::class => 'ViewAny:Stock',
        StockMovementRecommendationForAdmins::class => 'ViewAny:StockMovement',
    ];

    public function getNotificationsQuery(): Builder|Relation
    {
        $user = $this->getUser();

        abort_unless($user instanceof User, 401);

        $visibleTypes = collect(self::NOTIFICATION_PERMISSIONS)
            ->filter(fn (string $permission): bool => $user->can($permission))
            ->keys()
            ->all();

        return $user->notifications()
            ->where('data->format', 'filament')
            ->whereIn('type', $visibleTypes);
    }
}

<?php

namespace App\Filament\Actions;

use App\Actions\AssignUserRoles;
use App\Models\Role;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AssignUserRoleAction
{
    public static function make(): Action
    {
        return Action::make('assignRole')
            ->label('Assign Role')
            ->icon('heroicon-o-shield-check')
            ->authorize(fn (User $record): bool => Gate::allows('update', $record))
            ->hidden(fn (User $record): bool => $record->trashed())
            ->fillForm(fn (User $record): array => ['roles' => $record->roles()->pluck('roles.id')->all()])
            ->schema([
                Select::make('roles')->label('Roles')->multiple()->searchable()->preload()
                    ->nestedRecursiveRules([
                        Rule::exists(Role::class, 'id')->where('guard_name', Role::DEFAULT_GUARD_NAME)->whereNull('deleted_at'),
                    ])
                    ->options(fn (): array => Role::query()->where('guard_name', Role::DEFAULT_GUARD_NAME)
                        ->where('name', '!=', 'customer')->orderBy('name')->pluck('name', 'id')->all()),
            ])
            ->action(function (Action $action, User $record, array $data, AssignUserRoles $assign): void {
                try {
                    $assign->execute($record, $data['roles'] ?? []);
                } catch (ValidationException $exception) {
                    $statePath = $action->getLivewire()->getMountedActionSchema()->getStatePath();

                    throw ValidationException::withMessages(collect($exception->errors())
                        ->mapWithKeys(fn (array $messages, string $field): array => [$statePath.'.'.$field => $messages])->all());
                }

                Notification::make()->title('Roles assigned')->success()->send();
            });
    }
}

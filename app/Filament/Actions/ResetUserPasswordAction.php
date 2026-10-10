<?php

namespace App\Filament\Actions;

use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class ResetUserPasswordAction
{
    public static function make(): Action
    {
        return Action::make('resetPassword')
            ->label('Reset Password')
            ->icon('heroicon-o-key')
            ->authorize(fn (User $record): bool => Gate::allows('update', $record))
            ->hidden(fn (User $record): bool => $record->trashed() || $record->is(auth('web')->user()))
            ->schema([
                TextInput::make('password')
                    ->label('Temporary Password')
                    ->password()
                    ->revealable()
                    ->autocomplete('new-password')
                    ->required()
                    ->minLength(8)
                    ->confirmed(),
                TextInput::make('password_confirmation')
                    ->label('Confirm Temporary Password')
                    ->password()
                    ->revealable()
                    ->autocomplete('new-password')
                    ->required()
                    ->dehydrated(false),
            ])
            ->action(function (Action $action, User $record, array $data): void {
                Gate::authorize('update', $record);
                abort_if($record->trashed() || $record->is(auth('web')->user()), 403);

                $record->forceFill([
                    'password' => $data['password'],
                    'must_change_password' => true,
                    'remember_token' => Str::random(60),
                ])->save();

                $action->success();
            })
            ->successNotificationTitle('Password reset. The user must change it at next sign-in.');
    }
}

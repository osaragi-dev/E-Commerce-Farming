<?php

namespace App\Filament\Actions;

use App\Enums\RecordStatus;
use App\Filament\Validation\LifecycleIdentifierValidator;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Illuminate\Database\Eloquent\Model;

class LifecycleEditAction
{
    public static function configure(EditAction $action): EditAction
    {
        return $action
            ->color('primary')
            ->mutateDataUsing(fn (array $data, array $arguments): array => [
                ...$data,
                'record_status' => ($arguments['draft'] ?? false)
                    ? RecordStatus::Draft
                    : RecordStatus::Saved,
            ])
            ->before(fn (Action $action, LifecycleIdentifierValidator $validator) => $validator->validate($action))
            ->modalSubmitAction(fn (Action $action): Action => $action
                ->label('Save changes')
                ->icon('heroicon-o-check')
                ->color('primary')
                ->extraAttributes(['formnovalidate' => true]))
            ->extraModalFooterActions(fn (EditAction $action, ?Model $record): array => self::isDraft($record) ? [
                $action->makeModalSubmitAction('saveDraft', ['draft' => true])
                    ->label('Save as Draft')
                    ->icon('heroicon-o-document')
                    ->color('gray')
                    ->extraAttributes(['formnovalidate' => true]),
            ] : [])
            ->modalCancelAction(fn (Action $action): Action => $action
                ->label('Cancel')
                ->icon('heroicon-o-x-mark'));
    }

    private static function isDraft(?Model $record): bool
    {
        if ($record === null) {
            return false;
        }

        return $record->getAttribute('record_status') === RecordStatus::Draft
            || $record->getAttribute('record_status') === RecordStatus::Draft->value;
    }
}

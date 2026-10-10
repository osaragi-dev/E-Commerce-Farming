<?php

namespace App\Filament\Actions;

use App\Enums\RecordStatus;
use App\Filament\Validation\LifecycleIdentifierValidator;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Support\Enums\Width;

class LifecycleCreateAction
{
    public static function make(
        Width|string|null $modalWidth = Width::FiveExtraLarge,
        bool $allowDraft = true,
        string $draftLabel = 'Create as Draft',
    ): CreateAction {
        return CreateAction::make()
            ->icon('heroicon-o-plus')
            ->createAnother(false)
            ->modalWidth($modalWidth)
            ->mutateDataUsing(fn (array $data, array $arguments): array => [
                ...$data,
                'record_status' => ($arguments['draft'] ?? false)
                    ? RecordStatus::Draft
                    : RecordStatus::Saved,
            ])
            ->before(fn (Action $action, LifecycleIdentifierValidator $validator) => $validator->validate($action))
            ->modalSubmitAction(fn (Action $action): Action => $action
                ->label('Create')
                ->icon('heroicon-o-check')
                ->extraAttributes(['formnovalidate' => true]))
            ->extraModalFooterActions($allowDraft
                ? fn (CreateAction $action): array => [
                    $action->makeModalSubmitAction('createDraft', ['draft' => true])
                        ->label($draftLabel)
                        ->icon('heroicon-o-document')
                        ->color('gray')
                        ->extraAttributes(['formnovalidate' => true]),
                ]
                : fn (): array => [])
            ->modalCancelAction(fn (Action $action): Action => $action
                ->label('Cancel')
                ->icon('heroicon-o-x-mark'));
    }
}

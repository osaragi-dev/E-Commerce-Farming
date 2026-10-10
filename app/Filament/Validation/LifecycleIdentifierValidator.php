<?php

namespace App\Filament\Validation;

use App\Enums\RecordStatus;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LifecycleIdentifierValidator
{
    public function validate(
        Action $action,
        ?RecordStatus $targetStatus = null,
        bool $ignoreCurrentRecord = true,
    ): void {
        $data = $action->getData();
        $rawStatus = $data['record_status'] ?? RecordStatus::Saved;
        $status = $targetStatus ?? ($rawStatus instanceof RecordStatus
            ? $rawStatus
            : RecordStatus::tryFrom((string) $rawStatus));

        if ($status !== RecordStatus::Saved) {
            return;
        }

        $modelClass = $action->getModel();

        if (blank($modelClass)) {
            return;
        }

        /** @var Model $prototype */
        $prototype = new $modelClass;

        if (! method_exists($prototype, 'getLifecycleUniqueAttributes')) {
            return;
        }

        $record = $ignoreCurrentRecord ? $action->getRecord() : null;
        $errors = [];

        foreach ($prototype->getLifecycleUniqueAttributes() as $attribute) {
            $value = $prototype->getLifecycleUniqueAttributeValue($attribute, $data);

            if (blank($value)) {
                continue;
            }

            $query = $modelClass::query()
                ->whereNull('deleted_at')
                ->where('record_status', RecordStatus::Saved)
                ->whereRaw("lower(trim({$attribute})) = ?", [Str::lower(Str::squish((string) $value))]);

            if ($record?->exists) {
                $query->whereKeyNot($record->getKey());
            }

            if ($query->exists()) {
                $message = 'The '.Str::headline($attribute).' has already been used by a saved record.';
                $errors[$attribute] = $message;
                $errors['mountedActions.0.data.'.$attribute] = $message;
            }
        }

        if ($errors !== []) {
            Notification::make()
                ->title('Gagal disimpan')
                ->body(implode(' ', array_unique($errors)))
                ->danger()
                ->send();

            throw ValidationException::withMessages($errors);
        }
    }
}

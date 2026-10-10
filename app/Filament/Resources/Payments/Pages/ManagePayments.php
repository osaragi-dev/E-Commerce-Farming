<?php

namespace App\Filament\Resources\Payments\Pages;

use App\Filament\Pages\PrinterSettings;
use App\Filament\Resources\Payments\PaymentResource;
use App\Filament\Resources\PaymentSetting\Schemas\PaymentSettingForm;
use App\Models\PaymentSetting;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Enums\Width;

class ManagePayments extends ManageRecords
{
    protected static string $resource = PaymentResource::class;

    protected function getHeaderActions(): array
    {
        $setting = PaymentSetting::current();

        return [
            Action::make('printerSettings')
                ->label('Printer Settings')
                ->icon('heroicon-o-printer')
                ->visible(fn (): bool => PrinterSettings::canAccess())
                ->url(fn (): string => PrinterSettings::getUrl()),
            Action::make('paymentSettings')
                ->label('Payment Settings')
                ->icon('heroicon-o-adjustments-horizontal')
                ->color('primary')
                ->modalHeading('Payment Settings')
                ->modalSubmitActionLabel('Save Changes')
                ->modalWidth(Width::Small)
                ->schema(PaymentSettingForm::components())
                ->fillForm(fn (): array => [
                    'proof_resubmission_hours' => PaymentSetting::current()->proof_resubmission_hours,
                ])
                ->visible(fn (): bool => auth('web')->user()?->can('update', $setting) ?? false)
                ->authorize(fn (): bool => auth('web')->user()?->can('update', $setting) ?? false)
                ->action(function (array $data): void {
                    PaymentSetting::current()->update([
                        'proof_resubmission_hours' => $data['proof_resubmission_hours'],
                        'updated_by' => auth('web')->id(),
                    ]);

                    Notification::make()
                        ->title('Payment settings updated successfully.')
                        ->success()
                        ->send();
                }),
        ];
    }
}

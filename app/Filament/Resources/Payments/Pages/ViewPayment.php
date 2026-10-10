<?php

namespace App\Filament\Resources\Payments\Pages;

use App\Filament\Actions\ApproveWorkflowAction;
use App\Filament\Actions\RejectWorkflowAction;
use App\Filament\Actions\ReviseWorkflowAction;
use App\Filament\Resources\Payments\PaymentResource;
use App\Filament\Resources\Payments\Tables\Actions\VoidPaymentAction;
use App\Models\PaymentVoidRequest;
use App\Services\PaymentVoidWorkflow;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Pages\ViewRecord;

class ViewPayment extends ViewRecord
{
    protected static string $resource = PaymentResource::class;

    protected function getHeaderActions(): array
    {
        $request = $this->getRecord()->voidRequests()->whereIn('approval_status', PaymentVoidRequest::ACTIVE_STATUSES)->first();
        $actions = [VoidPaymentAction::make()];
        if ($request === null) {
            return $actions;
        }

        return [
            ...$actions,
            ApproveWorkflowAction::make()->record(fn (): PaymentVoidRequest => $request->fresh()),
            ReviseWorkflowAction::make()->record(fn (): PaymentVoidRequest => $request->fresh()),
            RejectWorkflowAction::make()->record(fn (): PaymentVoidRequest => $request->fresh()),
            Action::make('resubmitVoid')->label('Resubmit Void')
                ->visible(fn (): bool => $request->fresh()->approval_status === 'revised' && $request->requested_by === auth()->id())
                ->schema([Textarea::make('reason')->required()->maxLength(5000)->default($request->reason)])
                ->action(function (array $data) use ($request): void {
                    app(PaymentVoidWorkflow::class)->resubmit($request, auth()->user(), $data['reason']);
                    $this->record = $this->getRecord()->fresh();
                }),
            Action::make('withdrawVoid')->label('Withdraw Void')->color('gray')
                ->visible(fn (): bool => app(PaymentVoidWorkflow::class)->canManage($request->fresh(), auth()->user()))
                ->requiresConfirmation()
                ->action(function () use ($request): void {
                    app(PaymentVoidWorkflow::class)->withdraw($request, auth()->user());
                    $this->record = $this->getRecord()->fresh();
                }),
        ];
    }
}

<?php

namespace App\Filament\Resources\DirectOrders\Support;

use App\Enums\PurchaseRequestApprovalStatus;
use App\Enums\RecordStatus;
use App\Models\ApprovalLog;
use App\Models\DirectOrder;
use Illuminate\Support\Carbon;

class DirectOrderDetailPresenter
{
    /** @return list<array<string, mixed>> */
    public function timeline(DirectOrder $record): array
    {
        $record->loadMissing(['approvalLogs.actedBy', 'approvalSetting.levels', 'createdBy', 'updatedBy']);
        $logs = $record->approvalLogs->sortBy('created_at')->values();
        $lastRevisionIndex = $logs->filter(fn (ApprovalLog $log): bool => $log->action === 'revised')->keys()->last();
        $currentLogs = $lastRevisionIndex === null ? $logs : $logs->slice($lastRevisionIndex + 1)->values();
        $levelCount = $record->approvalSetting?->levels->count() ?? 0;
        $isSaved = $record->record_status === RecordStatus::Saved;
        $requestLog = $currentLogs->last(fn (ApprovalLog $log): bool => in_array($log->action, ['requested', 'resubmitted'], true));
        $steps = [$this->step(
            'requested',
            $isSaved ? 'Requested' : 'Draft',
            $isSaved ? ($requestLog?->created_at ?? $record->created_at) : null,
            $requestLog?->actedBy?->name ?? $record->createdBy?->name,
            $isSaved,
        )];

        for ($level = 1; $level < $levelCount; $level++) {
            $approvalLog = $currentLogs->first(fn (ApprovalLog $log): bool => $log->action === 'approved' && (int) $log->level === $level);
            $steps[] = $this->step(
                "level-{$level}",
                "Level {$level}",
                $approvalLog?->created_at,
                $approvalLog?->actedBy?->name,
                $approvalLog !== null || $record->approval_status === PurchaseRequestApprovalStatus::Approved,
            );
        }

        $lastApproval = $currentLogs->filter(fn (ApprovalLog $log): bool => $log->action === 'approved')->last();
        $isApproved = $record->approval_status === PurchaseRequestApprovalStatus::Approved;
        $steps[] = $this->step(
            'approved',
            'Approved',
            $isApproved ? ($lastApproval?->created_at ?? $record->created_at) : null,
            $isApproved ? ($lastApproval?->actedBy?->name ?? $record->updatedBy?->name ?? $record->createdBy?->name) : null,
            $isApproved,
        );

        $currentIndex = collect($steps)->search(fn (array $step): bool => ! $step['complete']);

        foreach ($steps as $index => &$step) {
            $step['state'] = $step['complete']
                ? 'complete'
                : ($index === $currentIndex
                    ? (in_array($record->approval_status, [PurchaseRequestApprovalStatus::Rejected, PurchaseRequestApprovalStatus::Revised], true) ? 'stopped' : 'current')
                    : 'pending');
            $step['message'] = match ($record->approval_status) {
                PurchaseRequestApprovalStatus::Rejected => 'Rejected',
                PurchaseRequestApprovalStatus::Revised => 'Revision requested',
                default => 'Waiting',
            };
        }
        unset($step);

        return $steps;
    }

    /** @return array<string, mixed> */
    private function step(string $key, string $label, ?Carbon $date, ?string $actor, bool $complete): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'complete' => $complete,
            'date' => $date?->format('d M Y'),
            'time' => $date?->format('H:i'),
            'actor' => $actor,
        ];
    }
}

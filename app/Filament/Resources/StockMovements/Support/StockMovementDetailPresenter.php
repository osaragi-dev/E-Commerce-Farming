<?php

namespace App\Filament\Resources\StockMovements\Support;

use App\Enums\StockMovementApprovalStatus;
use App\Enums\StockMovementStatus;
use App\Models\ApprovalLog;
use App\Models\StockMovement;
use App\Models\StockMovementLog;
use Illuminate\Support\Carbon;

class StockMovementDetailPresenter
{
    /** @return list<array<string, mixed>> */
    public function timeline(StockMovement $record): array
    {
        $record->loadMissing([
            'approvalLogs.actedBy',
            'approvalSetting.levels',
            'logs.changedBy',
            'requestedBy',
            'updatedBy',
        ]);

        $approvalLogs = $record->approvalLogs->sortBy('id')->values();
        $lastRevisionIndex = $approvalLogs
            ->filter(fn (ApprovalLog $log): bool => $log->action === 'revised')
            ->keys()
            ->last();
        $currentApprovalLogs = $lastRevisionIndex === null
            ? $approvalLogs
            : $approvalLogs->slice($lastRevisionIndex + 1)->values();
        $approvalStatus = $record->approval_status?->value;
        $levelCount = max(
            $record->approval_setting_id === null ? 2 : 0,
            $record->approvalSetting?->levels->count() ?? 0,
            (int) $currentApprovalLogs->max('level'),
            in_array($approvalStatus, [StockMovementApprovalStatus::Requested->value, StockMovementApprovalStatus::Pending->value], true)
                ? (int) $record->approval_level : 0,
        );

        $steps = [
            $this->step(
                'requested',
                'Requested',
                $record->status !== StockMovementStatus::Draft ? $record->created_at : null,
                $record->requestedBy?->name ?? $record->createdBy?->name,
                $record->status !== StockMovementStatus::Draft,
            ),
        ];

        for ($level = 1; $level < $levelCount; $level++) {
            $decision = $currentApprovalLogs->first(
                fn (ApprovalLog $log): bool => $log->action === 'approved' && (int) $log->level === $level,
            );

            $steps[] = $this->step(
                "level-{$level}",
                "Level {$level}",
                $decision?->created_at,
                $decision?->actedBy?->name,
                $decision !== null
                    || $approvalStatus === StockMovementApprovalStatus::Approved->value
                    || ($approvalStatus === StockMovementApprovalStatus::Pending->value && (int) $record->approval_level > $level),
            );
        }

        $lastApprovalLog = $currentApprovalLogs
            ->filter(fn (ApprovalLog $log): bool => $log->action === 'approved')
            ->last();
        $isApproved = $approvalStatus === StockMovementApprovalStatus::Approved->value;
        $approvedAt = $isApproved ? $lastApprovalLog?->created_at : null;
        $approvedBy = $isApproved ? $lastApprovalLog?->actedBy?->name : null;

        if ($approvalStatus === StockMovementApprovalStatus::Approved->value && $approvedAt === null) {
            $approvedAt = $record->updated_at;
            $approvedBy = $record->updatedBy?->name;
        }

        $steps[] = $this->step('approved', 'Approved', $approvedAt, $approvedBy, $isApproved);

        $shippedLog = $this->statusLog($record, StockMovementStatus::Shipped);
        $isShipped = in_array($record->status, [
            StockMovementStatus::Shipped,
            StockMovementStatus::Received,
        ], true);
        $steps[] = $this->step(
            'shipped',
            'Shipped',
            $record->shipped_at ?? $shippedLog?->created_at,
            $shippedLog?->changedBy?->name ?? ($isShipped ? $record->updatedBy?->name : null),
            $isShipped,
        );

        $receivedLog = $this->statusLog($record, StockMovementStatus::Received);
        $isReceived = $record->status === StockMovementStatus::Received;
        $steps[] = $this->step(
            'received',
            'Received',
            $record->received_at ?? $receivedLog?->created_at,
            $receivedLog?->changedBy?->name ?? ($isReceived ? $record->updatedBy?->name : null),
            $isReceived,
        );

        $currentIndex = 0;

        foreach ($steps as $index => $step) {
            if (! $step['complete']) {
                $currentIndex = $index;

                break;
            }

            $currentIndex = $index + 1;
        }

        foreach ($steps as $index => &$step) {
            $step['state'] = $step['complete']
                ? 'complete'
                : ($index === $currentIndex ? 'current' : 'pending');
        }

        unset($step);

        return $steps;
    }

    private function statusLog(StockMovement $record, StockMovementStatus $status): ?StockMovementLog
    {
        return $record->logs
            ->sortBy('created_at')
            ->first(fn (StockMovementLog $log): bool => $log->to_status === $status->value);
    }

    /** @return array<string, mixed> */
    private function step(
        string $key,
        string $label,
        ?Carbon $occurredAt,
        ?string $actorName,
        bool $complete,
    ): array {
        return [
            'key' => $key,
            'label' => $label,
            'complete' => $complete,
            'date' => $occurredAt?->format('d M Y'),
            'time' => $occurredAt?->format('H:i'),
            'actor' => $actorName,
        ];
    }
}

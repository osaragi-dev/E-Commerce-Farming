<?php

namespace App\Filament\Resources\StockOpnames\Support;

use App\Enums\ApprovalModule;
use App\Enums\StockOpnameApprovalStatus;
use App\Enums\StockOpnameStatus;
use App\Models\ApprovalLog;
use App\Models\ApprovalSetting;
use App\Models\StockOpnameLog;
use App\Models\StockOpnameSession;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class StockOpnameDetailPresenter
{
    /** @return list<array<string, mixed>> */
    public function timeline(StockOpnameSession $record): array
    {
        $record->loadMissing(['approvalSetting.levels', 'approvalLogs.actedBy']);
        $approvalStatus = $record->approval_status?->value;
        $isSaved = $record->status !== StockOpnameStatus::Draft;
        $approvalLogs = $record->approvalLogs->sortBy('id')->values();
        $lastRevisionIndex = $approvalLogs
            ->filter(fn (ApprovalLog $log): bool => $log->action === 'revised')
            ->keys()
            ->last();
        $currentApprovalLogs = $lastRevisionIndex === null
            ? $approvalLogs
            : $approvalLogs->slice($lastRevisionIndex + 1)->values();
        $statusLogs = $record->logs;
        $startedAt = $this->statusLogDate($statusLogs, [
            StockOpnameStatus::Counting->value,
            StockOpnameStatus::Frozen->value,
        ]) ?? ($isSaved ? $record->created_at : null);
        $startedBy = $this->statusLogActor($statusLogs, [
            StockOpnameStatus::Counting->value,
            StockOpnameStatus::Frozen->value,
        ]) ?? ($isSaved ? ($record->checker?->name ?? $record->createdBy?->name) : null);
        $steps = [
            $this->step('requested', 'Requested', $isSaved ? $record->created_at : null, $record->createdBy?->name, $isSaved),
            $this->step('started', 'Started', $startedAt, $startedBy, $isSaved),
        ];

        $levelCount = max(
            $record->approval_setting_id === null
                ? (int) ApprovalSetting::query()
                    ->where('module_code', ApprovalModule::StockOpname->value)
                    ->where('is_active', true)
                    ->withCount('levels')
                    ->value('levels_count')
                : 0,
            $record->approvalSetting?->levels->count() ?? 0,
            (int) $currentApprovalLogs->max('level'),
            in_array($approvalStatus, [
                StockOpnameApprovalStatus::Requested->value,
                StockOpnameApprovalStatus::Pending->value,
            ], true) ? (int) $record->approval_level : 0,
        );

        for ($level = 1; $level < $levelCount; $level++) {
            $approvalLog = $currentApprovalLogs->first(
                fn (ApprovalLog $log): bool => $log->action === 'approved' && (int) $log->level === $level,
            );

            $isComplete = $approvalLog !== null
                || $approvalStatus === StockOpnameApprovalStatus::Approved->value
                || ($approvalStatus === StockOpnameApprovalStatus::Pending->value
                    && (int) $record->approval_level > $level);

            $steps[] = $this->step(
                "level-{$level}",
                "Level {$level}",
                $approvalLog?->created_at,
                $approvalLog?->actedBy?->name,
                $isComplete,
            );
        }

        $approvedLog = $currentApprovalLogs
            ->filter(fn (ApprovalLog $log): bool => $log->action === 'approved')
            ->last();
        $isApproved = $approvalStatus === StockOpnameApprovalStatus::Approved->value
            || ($record->status === StockOpnameStatus::Finalized
                && in_array($approvalStatus, [
                    StockOpnameApprovalStatus::Requested->value,
                    StockOpnameApprovalStatus::Posted->value,
                ], true));
        $approvedAt = $isApproved ? $approvedLog?->created_at : null;
        $approvedBy = $isApproved ? $approvedLog?->actedBy?->name : null;

        if ($approvalStatus === StockOpnameApprovalStatus::Approved->value && $approvedAt === null) {
            $approvedAt = $record->approved_at ?? $record->updated_at;
            $approvedBy = $record->updatedBy?->name;
        }

        $endedAt = $this->statusLogDate($statusLogs, [StockOpnameStatus::Submitted->value])
            ?? ($isApproved ? $approvedAt : null);
        $endedBy = $this->statusLogActor($statusLogs, [StockOpnameStatus::Submitted->value])
            ?? ($isApproved ? $approvedBy : null);
        $steps[] = $this->step('approved', 'Approved', $approvedAt, $approvedBy, $isApproved);
        $steps[] = $this->step('ended', 'Ended', $endedAt, $endedBy, $isApproved || in_array($record->status?->value, [
            StockOpnameStatus::Submitted->value,
            StockOpnameStatus::Approved->value,
            StockOpnameStatus::Finalized->value,
        ], true));
        $finalizedLog = $statusLogs->first(fn (StockOpnameLog $log): bool => $log->to_status === StockOpnameStatus::Finalized->value);
        $steps[] = $this->step(
            'finalized',
            'Finalized',
            $record->finalized_at ?? $finalizedLog?->created_at,
            $finalizedLog?->changedBy?->name ?? $record->updatedBy?->name,
            $record->status === StockOpnameStatus::Finalized,
        );

        $currentIndex = 0;

        foreach ($steps as $index => $step) {
            if ($step['complete'] === true) {
                $currentIndex = $index + 1;

                continue;
            }

            $currentIndex = $index;
            break;
        }

        foreach ($steps as $index => &$step) {
            $step['state'] = $step['complete']
                ? 'complete'
                : ($index === $currentIndex ? 'current' : 'pending');
        }

        unset($step);

        return $steps;
    }

    /** @return list<array<string, mixed>> */
    public function activities(StockOpnameSession $record): array
    {
        $statusActivities = $record->logs->map(function (StockOpnameLog $log): array {
            $isInitialSavedRecord = $log->action === 'created'
                && $log->to_status !== StockOpnameStatus::Draft->value;
            $action = $isInitialSavedRecord ? 'requested' : str($log->action)->lower()->toString();
            $actionLabel = $isInitialSavedRecord
                ? 'Requested'
                : str($log->action)->headline()->toString();
            $statusFrom = $isInitialSavedRecord || blank($log->from_status)
                ? null
                : strtoupper((string) $log->from_status);
            $statusTo = $isInitialSavedRecord
                ? 'PENDING'
                : (filled($log->to_status) ? strtoupper((string) $log->to_status) : null);

            return [
                'created_at' => $log->created_at?->format('d M Y H:i'),
                'created_at_raw' => $log->created_at?->timestamp ?? 0,
                'action' => $action,
                'action_label' => $actionLabel,
                'pic' => $log->changedBy?->name ?? 'System',
                'status_from' => $statusFrom,
                'status_to' => $statusTo,
                'status_to_label' => $statusTo,
                'comment' => $log->notes,
                'action_color' => 'text-gray-700 dark:text-gray-300',
                'row_bg' => '',
            ];
        });

        $lastApprovedLog = $record->approvalLogs
            ->filter(fn ($log): bool => $log->action === 'approved')
            ->sortByDesc('created_at')
            ->first();
        $approvalActivities = $record->approvalLogs->map(function ($log) use ($lastApprovedLog, $record): array {
            $action = str($log->action)->lower()->toString();
            $actionLabel = match ($action) {
                'approved' => "Approved Level {$log->level}",
                'rejected' => "Rejected Level {$log->level}",
                'revised' => "Revised Level {$log->level}",
                default => str($action)->headline()->toString(),
            };
            $statusTo = match ($action) {
                'approved' => $lastApprovedLog?->is($log)
                    && in_array($record->approval_status, [
                        StockOpnameApprovalStatus::Approved,
                        StockOpnameApprovalStatus::Requested,
                        StockOpnameApprovalStatus::Posted,
                    ], true)
                    ? 'APPROVED'
                    : "L{$log->level}_APPROVED",
                'rejected' => 'REJECTED',
                'revised' => 'REVISED',
                default => strtoupper($action),
            };
            $color = match ($action) {
                'approved' => ['text-success-700 dark:text-success-400', 'bg-success-50/30 dark:bg-success-900/10'],
                'rejected' => ['text-danger-700 dark:text-danger-400', 'bg-danger-50/30 dark:bg-danger-900/10'],
                'revised' => ['text-warning-700 dark:text-warning-400', 'bg-warning-50/30 dark:bg-warning-900/10'],
                default => ['text-gray-700 dark:text-gray-200', 'bg-white dark:bg-gray-900'],
            };

            return [
                'created_at' => $log->created_at?->format('d M Y H:i'),
                'created_at_raw' => $log->created_at?->timestamp ?? 0,
                'action' => $action,
                'action_label' => $actionLabel,
                'pic' => $log->actedBy?->name ?? 'System',
                'status_from' => 'PENDING',
                'status_to' => $statusTo,
                'status_to_label' => $statusTo,
                'comment' => $log->notes,
                'action_color' => $color[0],
                'row_bg' => $color[1],
            ];
        });

        return $statusActivities
            ->concat($approvalActivities)
            ->sortBy('created_at_raw')
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    private function step(
        string $key,
        string $label,
        ?Carbon $occurredAt,
        ?string $actorName,
        bool $complete = false,
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

    /** @param Collection<int, StockOpnameLog> $logs */
    private function statusLogDate(Collection $logs, array $statuses): ?Carbon
    {
        return $logs
            ->first(fn (StockOpnameLog $log): bool => in_array($log->to_status, $statuses, true))
            ?->created_at;
    }

    /** @param Collection<int, StockOpnameLog> $logs */
    private function statusLogActor(Collection $logs, array $statuses): ?string
    {
        return $logs
            ->first(fn (StockOpnameLog $log): bool => in_array($log->to_status, $statuses, true))
            ?->changedBy?->name;
    }
}

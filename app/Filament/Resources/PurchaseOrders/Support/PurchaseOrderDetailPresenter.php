<?php

namespace App\Filament\Resources\PurchaseOrders\Support;

use App\Enums\PurchaseOrderApprovalStatus;
use App\Enums\PurchaseOrderCloseApprovalStatus;
use App\Models\ApprovalLog;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderCloseRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class PurchaseOrderDetailPresenter
{
    /** @return array{approval: list<array<string, mixed>>, closing: list<array<string, mixed>>} */
    public function timelines(PurchaseOrder $purchaseOrder): array
    {
        $purchaseOrder->loadMissing(['approvalLogs.actedBy', 'approvalSetting.levels', 'createdBy']);
        $closeRequest = $purchaseOrder->latest_close_request;
        $logs = $purchaseOrder->approvalLogs->sortBy('created_at')->values();

        return [
            'approval' => $this->timeline(
                $logs,
                $purchaseOrder->approvalSetting?->levels->count() ?? 0,
                $purchaseOrder->approval_status,
                $purchaseOrder->created_at,
                $purchaseOrder->createdBy?->name,
                'Requested',
                'Approved',
            ),
            'closing' => $closeRequest instanceof PurchaseOrderCloseRequest
                ? $this->closeTimeline($closeRequest)
                : [],
        ];
    }

    /** @param Collection<int, ApprovalLog> $logs
     * @return list<array<string, mixed>>
     */
    private function timeline(
        Collection $logs,
        int $levelCount,
        mixed $status,
        ?Carbon $createdAt,
        ?string $creator,
        string $requestedLabel,
        string $approvedLabel,
    ): array {
        $steps = [$this->step($requestedLabel, $createdAt, $creator, true)];

        for ($level = 1; $level <= $levelCount; $level++) {
            $log = $logs->first(fn (ApprovalLog $entry): bool => $entry->action === 'approved' && (int) $entry->level === $level);
            $steps[] = $this->step('Level '.$level, $log?->created_at, $log?->actedBy?->name, $log !== null);
        }

        $approved = $status === PurchaseOrderApprovalStatus::Approved || $status === PurchaseOrderCloseApprovalStatus::Approved;
        $steps[] = $this->step($approvedLabel, $approved ? $logs->last()?->created_at : null, null, $approved);
        $current = collect($steps)->search(fn (array $step): bool => ! $step['complete']);

        foreach ($steps as $index => &$step) {
            $step['current'] = $index === $current;
        }
        unset($step);

        return $steps;
    }

    private function closeTimeline(PurchaseOrderCloseRequest $closeRequest): array
    {
        $closeRequest->loadMissing(['approvalLogs.actedBy', 'approvalSetting.levels', 'requestedBy']);
        $logs = $closeRequest->approvalLogs->sortBy('created_at')->values();

        return $this->timeline(
            $logs,
            $closeRequest->approvalSetting?->levels->count() ?? 0,
            $closeRequest->approval_status,
            $closeRequest->created_at,
            $closeRequest->requestedBy?->name,
            'Close Requested',
            'Closed',
        );
    }

    /** @return array<string, mixed> */
    private function step(string $label, ?Carbon $date, ?string $actor, bool $complete): array
    {
        return [
            'label' => $label,
            'date' => $date?->format('d M Y'),
            'time' => $date?->format('H:i'),
            'actor' => $actor,
            'complete' => $complete,
            'current' => false,
        ];
    }
}

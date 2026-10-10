<?php

namespace App\Filament\Resources\StockAdjustments\Support;

use App\Enums\RecordStatus;
use App\Enums\StockAdjustmentApprovalStatus;
use App\Enums\StockAdjustmentSource;
use App\Enums\StockAdjustmentType;
use App\Models\ApprovalLog;
use App\Models\StockAdjustment;
use App\Models\StockOpnameItem;
use App\Support\Quantity;
use Illuminate\Support\Carbon;

class StockAdjustmentDetailPresenter
{
    /** @return list<array<string, mixed>> */
    public function timeline(StockAdjustment $record): array
    {
        $record->loadMissing(['adjustedBy', 'appliedBy', 'approvalLogs.actedBy', 'approvalSetting.levels']);

        $approvalLogs = $record->approvalLogs->sortBy('id')->values();
        $lastRevisionIndex = $approvalLogs
            ->filter(fn (ApprovalLog $log): bool => $log->action === 'revised')
            ->keys()
            ->last();
        $currentApprovalLogs = $lastRevisionIndex === null
            ? $approvalLogs
            : $approvalLogs->slice($lastRevisionIndex + 1)->values();
        $approvalStatus = $record->approval_status?->value;
        $levelCount = max(0,
            $record->approvalSetting?->levels->count() ?? 0,
            (int) $currentApprovalLogs->max('level'),
            in_array($approvalStatus, [
                StockAdjustmentApprovalStatus::Requested->value,
                StockAdjustmentApprovalStatus::Pending->value,
            ], true) ? (int) $record->approval_level : 0,
        );

        $steps = [
            $this->step(
                'requested',
                $record->record_status === RecordStatus::Draft ? 'Draft' : 'Requested',
                $record->created_at,
                $record->createdBy?->name,
                $record->record_status === RecordStatus::Draft && $record->approval_status !== StockAdjustmentApprovalStatus::Revised
                    ? false
                    : true,
            ),
        ];

        for ($level = 1; $level < $levelCount; $level++) {
            $decision = $currentApprovalLogs->first(
                fn (ApprovalLog $log): bool => (int) $log->level === $level
                    && $log->action === 'approved',
            );

            $steps[] = $this->step(
                "level-{$level}",
                "Level {$level}",
                $decision?->created_at,
                $decision?->actedBy?->name,
                $decision !== null || in_array($approvalStatus, [StockAdjustmentApprovalStatus::Approved->value, StockAdjustmentApprovalStatus::Posted->value], true),
            );
        }

        $lastApproval = $currentApprovalLogs
            ->filter(fn (ApprovalLog $log): bool => $log->action === 'approved')
            ->last();
        $isApproved = in_array($approvalStatus, [StockAdjustmentApprovalStatus::Approved->value, StockAdjustmentApprovalStatus::Posted->value], true);
        $approvedAt = $isApproved ? ($lastApproval?->created_at ?? $record->applied_at) : null;
        $approvedBy = $isApproved ? ($lastApproval?->actedBy?->name ?? $record->appliedBy?->name) : null;

        $steps[] = $this->step('approved', 'Approved', $approvedAt, $approvedBy, $isApproved);
        $steps[] = $this->step('posted', 'Posted', $record->applied_at, $record->appliedBy?->name, $record->applied_at !== null);

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
                : ($index === $currentIndex
                    ? (in_array($approvalStatus, [StockAdjustmentApprovalStatus::Rejected->value, StockAdjustmentApprovalStatus::Revised->value], true) ? 'stopped' : 'current')
                    : 'pending');
            $step['message'] = match ($approvalStatus) {
                StockAdjustmentApprovalStatus::Rejected->value => 'Rejected',
                StockAdjustmentApprovalStatus::Revised->value => 'Revision requested',
                default => 'Waiting',
            };
        }

        unset($step);

        return $steps;
    }

    /** @return list<array<string, int|float|string>> */
    public function items(StockAdjustment $record): array
    {
        if ($record->source === StockAdjustmentSource::StockOpname) {
            $record->loadMissing(['stockOpnameItems.productVariant.product', 'stockOpnameItems.productVariant.unit']);

            return $record->stockOpnameItems
                ->sortBy('id')
                ->map(fn (StockOpnameItem $item): array => $this->opnameItem($item))
                ->values()
                ->all();
        }

        $mutation = $record->stockMutations->first();
        $currentStock = $mutation === null && $record->applied_at === null
            ? ($record->productVariant?->warehouseStocks()
                ->where('warehouse_id', $record->warehouse_id)
                ->value('stock_available') ?? $record->productVariant?->stock_available)
            : null;
        $systemQty = $mutation?->stock_before
            ?? ($record->applied_at === null ? $currentStock : null);
        $variance = $mutation?->quantity_change ?? ($record->type === StockAdjustmentType::Tambah ? $record->quantity : Quantity::subtract(0, $record->quantity));
        $adjustedQty = $mutation?->stock_after ?? ($systemQty !== null ? Quantity::add($systemQty, $variance) : null);
        $unit = $record->productVariant?->unit?->code ?? $record->productVariant?->unit?->name ?? '-';

        return [[
            'item' => sprintf(
                '%s - %s',
                $record->productVariant?->product?->name ?? 'Unknown Product',
                $record->productVariant?->name ?? 'Unknown Variant',
            ),
            'system_qty' => $systemQty !== null ? Quantity::format($systemQty) : '-',
            'adjusted_qty' => $adjustedQty !== null ? Quantity::format($adjustedQty) : '-',
            'variance' => Quantity::format($variance),
            'variance_value' => (float) $variance,
            'unit' => $unit,
            'type' => $record->type?->getLabel() ?? '-',
            'notes' => $record->note ?? '-',
        ]];
    }

    /** @return array<string, int|float|string> */
    private function opnameItem(StockOpnameItem $item): array
    {
        $variance = (string) $item->variance_qty;
        $varianceNumber = (float) $variance;
        $variant = $item->productVariant;

        return [
            'item' => sprintf(
                '%s - %s',
                $variant?->product?->name ?? 'Unknown Product',
                $variant?->name ?? 'Unknown Variant',
            ),
            'system_qty' => Quantity::format($item->system_qty),
            'adjusted_qty' => $item->physical_qty !== null ? Quantity::format($item->physical_qty) : '-',
            'variance' => Quantity::format($variance),
            'variance_value' => $varianceNumber,
            'unit' => filled($item->unit)
                ? $item->unit
                : ($variant?->unit?->code ?? $variant?->unit?->name ?? '-'),
            'type' => $varianceNumber > 0
                ? StockAdjustmentType::Tambah->getLabel()
                : ($varianceNumber < 0 ? StockAdjustmentType::Kurang->getLabel() : '-'),
            'notes' => $item->notes ?? '-',
        ];
    }

    /** @return list<array<string, int|string>> */
    public function activities(StockAdjustment $record): array
    {
        $hasApproval = $record->approval_setting_id !== null;
        $activities = [[
            'created_at' => $record->created_at?->format('d M Y H:i') ?? '-',
            'created_at_raw' => $record->created_at?->timestamp ?? 0,
            'action' => $record->record_status === RecordStatus::Draft && ! $hasApproval ? 'Draft saved' : ($hasApproval ? 'Requested' : 'Created'),
            'actor' => $record->createdBy?->name ?? 'System',
            'status_from' => '-',
            'status_to' => $record->record_status === RecordStatus::Draft && ! $hasApproval ? 'DRAFT' : ($hasApproval ? 'REQUESTED' : 'APPROVED'),
            'notes' => $record->note ?? '-',
        ]];

        foreach ($record->approvalLogs->sortBy('id') as $log) {
            $level = (int) $log->level;
            $previousStatus = $level > 1 ? 'L'.($level - 1).'_APPROVED' : 'REQUESTED';
            $nextStatus = match ($log->action) {
                'approved' => $level === ($record->approvalSetting?->levels->count() ?? $level)
                    ? 'APPROVED'
                    : "L{$level}_APPROVED",
                'rejected' => 'REJECTED',
                'revised' => 'REVISED',
                default => strtoupper($log->action),
            };

            $activities[] = [
                'created_at' => $log->created_at?->format('d M Y H:i') ?? '-',
                'created_at_raw' => $log->created_at?->timestamp ?? 0,
                'action' => $log->action === 'approved' ? "Approved Level {$level}" : str($log->action)->headline()->toString(),
                'actor' => $log->actedBy?->name ?? 'System',
                'status_from' => $previousStatus,
                'status_to' => $nextStatus,
                'notes' => $log->notes ?? '-',
            ];
        }

        if ($record->applied_at !== null) {
            $activities[] = [
                'created_at' => $record->applied_at->format('d M Y H:i'),
                'created_at_raw' => $record->applied_at->timestamp,
                'action' => 'Posted',
                'actor' => $record->appliedBy?->name ?? 'System',
                'status_from' => 'APPROVED',
                'status_to' => 'POSTED',
                'notes' => '-',
            ];
        }

        return collect($activities)->sortBy('created_at_raw')->values()->all();
    }

    public function source(StockAdjustment $record): string
    {
        return $record->source?->getLabel() ?? '-';
    }

    public function stockOpnameNumber(StockAdjustment $record): string
    {
        $item = $record->relationLoaded('stockOpnameItems')
            ? $record->stockOpnameItems->first()
            : $record->stockOpnameItems()->with('session')->first();

        return $item?->session?->opname_number ?? '-';
    }

    private function step(string $key, string $label, ?Carbon $occurredAt, ?string $actorName, bool $complete): array
    {
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

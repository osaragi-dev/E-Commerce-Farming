<?php

namespace App\Services;

use App\Enums\ApprovalModule;
use App\Enums\RecordStatus;
use App\Enums\StockMovementApprovalStatus;
use App\Enums\StockMovementStatus;
use App\Enums\StockOpnameApprovalStatus;
use App\Enums\StockOpnameStatus;
use App\Filament\Resources\DirectOrders\DirectOrderResource;
use App\Filament\Resources\GoodsReceipts\GoodsReceiptResource;
use App\Filament\Resources\Payments\PaymentResource;
use App\Filament\Resources\PurchaseOrders\PurchaseOrderResource;
use App\Filament\Resources\PurchaseRequests\PurchaseRequestResource;
use App\Filament\Resources\StockAdjustments\StockAdjustmentResource;
use App\Filament\Resources\StockMovements\StockMovementResource;
use App\Filament\Resources\StockOpnames\StockOpnameResource;
use App\Filament\Resources\Vendors\VendorResource;
use App\Models\ApprovalSetting;
use App\Models\DirectOrder;
use App\Models\GoodsReceipt;
use App\Models\Payment;
use App\Models\PaymentVoidRequest;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderCloseRequest;
use App\Models\PurchaseRequest;
use App\Models\StockAdjustment;
use App\Models\StockMovement;
use App\Models\StockOpnameSession;
use App\Models\User;
use App\Models\Vendor;
use BackedEnum;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class ApprovalRequestInbox
{
    /** @var list<string> */
    private const REQUEST_ACTIONS = ['requested', 'resubmitted'];

    public function canAccess(User $user): bool
    {
        $modules = $this->visibleModules($user);

        if ($modules === []) {
            return false;
        }

        if ($this->isApprovalAdministrator($user)) {
            return true;
        }

        return ApprovalSetting::withTrashed()
            ->whereIn('module_code', array_map(fn (ApprovalModule $module): string => $module->value, $modules))
            ->whereHas('levels', fn (Builder $query): Builder => $query->whereIn('role_id', $this->roleIds($user)))
            ->exists();
    }

    public function canReviewDetail(User $user, Model $record): bool
    {
        if ($record instanceof Payment) {
            return $this->canReviewPayment($user, $record);
        }

        if (! $this->isApprovalRecord($record)) {
            return false;
        }

        return $this->canViewModule($user, ApprovalModule::forModel($record))
            && $this->isAssignedToAnyLevel($user, $record);
    }

    /** @return LengthAwarePaginator<int, array<string, mixed>> */
    public function paginate(
        User $user,
        int $page,
        int|string $perPage,
        ?string $search = null,
        ?string $sortColumn = null,
        ?string $sortDirection = null,
        string $pageName = 'page',
    ): LengthAwarePaginator {
        $records = $this->records($user);

        if (filled($search)) {
            $needle = Str::lower(trim($search));

            $records = $records->filter(function (array $record) use ($needle): bool {
                foreach (['type', 'number', 'requested_by', 'status', 'notes'] as $field) {
                    if (Str::contains(Str::lower((string) $record[$field]), $needle)) {
                        return true;
                    }
                }

                return false;
            });
        }

        $sortableColumns = [
            'type',
            'number',
            'date',
            'requested_by',
            'status',
            'progress',
            'requested_at',
            'updated_at',
            'notes',
        ];
        $sortColumn = in_array($sortColumn, $sortableColumns, true) ? $sortColumn : 'requested_at';
        $sortDirection = in_array($sortDirection, ['asc', 'desc'], true)
            ? $sortDirection
            : ($sortColumn === 'requested_at' ? 'desc' : 'asc');

        $records = $records->sort(function (array $left, array $right) use ($sortColumn, $sortDirection): int {
            $comparison = $this->compareSortValues(
                $this->sortValue($left, $sortColumn),
                $this->sortValue($right, $sortColumn),
            );

            if ($comparison === 0) {
                $comparison = strcmp($left['key'], $right['key']);
            }

            return $sortDirection === 'desc' ? -$comparison : $comparison;
        })->values();

        $currentPage = max(1, $page);
        $pageSize = $perPage === 'all' ? max(1, $records->count()) : max(1, (int) $perPage);

        return new LengthAwarePaginator(
            $records->forPage($currentPage, $pageSize)->values(),
            $records->count(),
            $pageSize,
            $currentPage,
            ['path' => request()->url(), 'pageName' => $pageName],
        );
    }

    public function canExportAnyModule(User $user): bool
    {
        return $this->exportableModules($user) !== [];
    }

    /** @param Collection<int, array<string, mixed>> $records
     * @return Collection<int, array<string, mixed>>
     */
    public function exportableRows(User $user, Collection $records): Collection
    {
        $exportableModules = array_map(
            fn (ApprovalModule $module): string => $module->value,
            $this->exportableModules($user),
        );

        return $records
            ->filter(fn (array $record): bool => in_array($record['module_code'] ?? null, $exportableModules, true))
            ->values();
    }

    /** @return Collection<int, array<string, mixed>> */
    private function records(User $user): Collection
    {
        if (! $this->canAccess($user)) {
            return collect();
        }

        $isAdministrator = $this->isApprovalAdministrator($user);

        return collect($this->visibleModules($user))
            ->flatMap(fn (ApprovalModule $module): Collection => $this->recordsForModule($user, $module, $isAdministrator))
            ->values();
    }

    /** @return list<ApprovalModule> */
    private function visibleModules(User $user): array
    {
        return array_values(array_filter(
            ApprovalModule::cases(),
            fn (ApprovalModule $module): bool => $this->canViewModule($user, $module),
        ));
    }

    private function canViewModule(User $user, ApprovalModule $module): bool
    {
        return $user->can('ViewAny:'.$this->permissionResource($module));
    }

    /** @return list<ApprovalModule> */
    private function exportableModules(User $user): array
    {
        $modules = array_values(array_filter(
            $this->visibleModules($user),
            fn (ApprovalModule $module): bool => $user->can('Export:'.$this->permissionResource($module)),
        ));

        if ($modules === []) {
            return [];
        }

        $isAdministrator = $this->isApprovalAdministrator($user);
        $roleIds = $isAdministrator ? [] : $this->roleIds($user);
        $configuredModules = ApprovalSetting::withTrashed()
            ->whereIn('module_code', array_map(fn (ApprovalModule $module): string => $module->value, $modules))
            ->whereHas('levels', fn (Builder $query): Builder => $isAdministrator ? $query : $query->whereIn('role_id', $roleIds))
            ->distinct()
            ->pluck('module_code')
            ->all();

        return array_values(array_filter(
            $modules,
            fn (ApprovalModule $module): bool => in_array($module->value, $configuredModules, true),
        ));
    }

    private function permissionResource(ApprovalModule $module): string
    {
        return match ($module) {
            ApprovalModule::VoidPayment => 'Payment',
            ApprovalModule::StockAdjustment => 'StockAdjustment',
            ApprovalModule::StockMovement => 'StockMovement',
            ApprovalModule::StockOpname => 'StockOpnameSession',
            ApprovalModule::PurchaseRequest => 'PurchaseRequest',
            ApprovalModule::DirectOrder => 'DirectOrder',
            ApprovalModule::Vendor => 'Vendor',
            ApprovalModule::PurchaseOrder, ApprovalModule::PurchaseOrderClose => 'PurchaseOrder',
            ApprovalModule::GoodsReceipt => 'GoodsReceipt',
        };
    }

    private function isApprovalAdministrator(User $user): bool
    {
        return $user->hasAnyRole(['admin', 'super_admin'])
            || $user->roles()->where('code', 'FARMING_ADMIN')->exists();
    }

    /** @return list<string> */
    private function roleIds(User $user): array
    {
        return $user->roles()->pluck('roles.id')->all();
    }

    private function isAssignedToAnyLevel(User $user, Model $record): bool
    {
        $settingId = $record->getAttribute('approval_setting_id');

        if ($settingId === null) {
            return false;
        }

        $setting = ApprovalSetting::withTrashed()
            ->whereKey($settingId)
            ->where('module_code', ApprovalModule::forModel($record)->value)
            ->whereHas('levels');

        if ($this->isApprovalAdministrator($user)) {
            return $setting->exists();
        }

        return $setting->whereHas('levels', fn (Builder $query): Builder => $query->whereIn('role_id', $this->roleIds($user)))
            ->exists();
    }

    private function canReviewPayment(User $user, Payment $payment): bool
    {
        if (! $this->canViewModule($user, ApprovalModule::VoidPayment)) {
            return false;
        }

        $requests = PaymentVoidRequest::query()
            ->where('payment_id', $payment->getKey())
            ->whereNotNull('approval_setting_id')
            ->whereHas('approvalSetting', fn (Builder $query): Builder => $query
                ->where('module_code', ApprovalModule::VoidPayment->value)
                ->whereHas('levels'));

        if ($this->isApprovalAdministrator($user)) {
            return $requests->exists();
        }

        return $requests->whereHas('approvalSetting.levels.role.users', fn (Builder $query): Builder => $query->whereKey($user->getKey()))
            ->exists();
    }

    private function isApprovalRecord(Model $record): bool
    {
        return $record instanceof PaymentVoidRequest
            || $record instanceof StockAdjustment
            || $record instanceof StockMovement
            || $record instanceof StockOpnameSession
            || $record instanceof PurchaseRequest
            || $record instanceof DirectOrder
            || $record instanceof Vendor
            || $record instanceof PurchaseOrder
            || $record instanceof PurchaseOrderCloseRequest
            || $record instanceof GoodsReceipt;
    }

    /** @return Collection<int, array<string, mixed>> */
    private function recordsForModule(User $user, ApprovalModule $module, bool $isAdministrator): Collection
    {
        $query = match ($module) {
            ApprovalModule::VoidPayment => PaymentVoidRequest::query()
                ->with([
                    'approvalSetting.levels',
                    'logs' => fn ($query) => $query->reorder()->orderBy('created_at'),
                    'payment:id,payment_number,created_at',
                    'requester:id,name',
                ]),
            ApprovalModule::StockAdjustment => StockAdjustment::query()
                ->where('record_status', RecordStatus::Saved->value)
                ->with(['adjustedBy:id,name', 'approvalSetting.levels', 'approvalLogs']),
            ApprovalModule::StockMovement => StockMovement::query()
                ->where('status', '!=', StockMovementStatus::Draft->value)
                ->with(['approvalSetting.levels', 'approvalLogs', 'requestedBy:id,name']),
            ApprovalModule::StockOpname => StockOpnameSession::query()
                ->where('status', '!=', StockOpnameStatus::Draft->value)
                ->with(['approvalSetting.levels', 'approvalLogs', 'createdBy:id,name']),
            ApprovalModule::PurchaseRequest => PurchaseRequest::query()
                ->where('record_status', RecordStatus::Saved->value)
                ->whereIn('approval_status', ['requested', 'pending'])
                ->with(['approvalSetting.levels', 'approvalLogs', 'requestedBy:id,name']),
            ApprovalModule::DirectOrder => DirectOrder::query()
                ->where('record_status', RecordStatus::Saved->value)
                ->whereIn('approval_status', ['requested', 'pending'])
                ->with(['approvalSetting.levels', 'approvalLogs', 'requestedBy:id,name']),
            ApprovalModule::Vendor => Vendor::query()
                ->where('record_status', RecordStatus::Saved->value)
                ->with(['approvalSetting.levels', 'approvalLogs', 'createdBy:id,name']),
            ApprovalModule::PurchaseOrder => PurchaseOrder::query()
                ->where('record_status', RecordStatus::Saved->value)
                ->whereIn('approval_status', ['requested', 'pending'])
                ->with(['approvalSetting.levels', 'approvalLogs', 'createdBy:id,name']),
            ApprovalModule::PurchaseOrderClose => PurchaseOrderCloseRequest::query()
                ->whereIn('approval_status', ['requested', 'pending'])
                ->with(['purchaseOrder', 'approvalSetting.levels', 'approvalLogs', 'requestedBy:id,name']),
            ApprovalModule::GoodsReceipt => GoodsReceipt::query()
                ->where('record_status', RecordStatus::Saved->value)
                ->whereIn('approval_status', ['requested', 'pending'])
                ->with(['purchaseOrder', 'approvalSetting.levels', 'approvalLogs', 'createdBy:id,name']),
        };

        $query->whereNotNull('approval_setting_id')
            ->whereNotIn('approval_status', ['approved', 'posted'])
            ->whereHas('approvalSetting', fn (Builder $query): Builder => $query
                ->where('module_code', $module->value)
                ->whereHas('levels'));

        if (! $isAdministrator) {
            $query->whereHas('approvalSetting.levels.role.users', fn (Builder $query): Builder => $query->whereKey($user->getKey()));
        }

        return $query->get()->map(fn (Model $record): array => $this->row($module, $record));
    }

    /** @return array<string, mixed> */
    private function row(ApprovalModule $module, Model $record): array
    {
        [$number, $date, $requester, $notes, $reviewUrl] = match (true) {
            $record instanceof PaymentVoidRequest => [
                $record->payment?->payment_number,
                $record->payment?->created_at,
                $record->requester?->name,
                $record->reason,
                PaymentResource::getUrl('view', ['record' => $record->payment]),
            ],
            $record instanceof StockAdjustment => [
                $record->adjustment_number,
                $record->created_at,
                $record->adjustedBy?->name,
                $record->note,
                StockAdjustmentResource::getUrl('view', ['record' => $record]),
            ],
            $record instanceof StockMovement => [
                $record->movement_number,
                $record->movement_date,
                $record->requestedBy?->name,
                $record->notes ?? $record->note,
                StockMovementResource::getUrl('view', ['record' => $record]),
            ],
            $record instanceof StockOpnameSession => [
                $record->opname_number,
                $record->opname_date,
                $record->createdBy?->name,
                $record->notes,
                StockOpnameResource::getUrl('view', ['record' => $record]),
            ],
            $record instanceof PurchaseRequest => [
                $record->pr_number,
                $record->pr_date,
                $record->requestedBy?->name,
                $record->notes,
                PurchaseRequestResource::getUrl('view', ['record' => $record]),
            ],
            $record instanceof DirectOrder => [
                $record->do_number,
                $record->do_date,
                $record->requestedBy?->name,
                $record->notes,
                DirectOrderResource::getUrl('view', ['record' => $record]),
            ],
            $record instanceof Vendor => [
                $record->vendor_code,
                $record->created_at,
                $record->createdBy?->name,
                $record->notes,
                VendorResource::getUrl('view', ['record' => $record]),
            ],
            $record instanceof PurchaseOrder => [
                $record->po_number,
                $record->po_date,
                $record->createdBy?->name,
                $record->notes,
                PurchaseOrderResource::getUrl('view', ['record' => $record]),
            ],
            $record instanceof PurchaseOrderCloseRequest => [
                $record->purchaseOrder?->po_number,
                $record->created_at,
                $record->requestedBy?->name,
                $record->notes,
                PurchaseOrderResource::getUrl('view', ['record' => $record->purchaseOrder]),
            ],
            $record instanceof GoodsReceipt => [
                $record->receipt_number,
                $record->received_at,
                $record->createdBy?->name,
                $record->notes,
                GoodsReceiptResource::getUrl('view', ['record' => $record]),
            ],
        };

        $logs = $record instanceof PaymentVoidRequest ? $record->logs : $record->approvalLogs;
        $latestRequestLog = $logs
            ->filter(fn ($log): bool => in_array($log->action, self::REQUEST_ACTIONS, true))
            ->sortByDesc(fn ($log): int => $log->created_at?->getTimestamp() ?? 0)
            ->first();
        $requestedAt = $latestRequestLog?->created_at ?? $record->created_at;
        $status = $record->getAttribute('approval_status');
        $levels = $record->approvalSetting?->levels?->count() ?? 0;
        $approvedLevels = $logs
            ->filter(fn ($log): bool => $log->action === 'approved'
                && ($requestedAt === null || $log->created_at?->greaterThanOrEqualTo($requestedAt)))
            ->count();

        if (in_array($this->statusValue($status), ['approved', 'posted'], true)
            || ($record instanceof StockMovement && in_array($record->status, [StockMovementStatus::Shipped, StockMovementStatus::Received], true))) {
            $approvedLevels = $levels;
        } elseif ($approvedLevels === 0 && $this->statusValue($status) === 'pending') {
            $approvedLevels = max(0, (int) $record->approval_level - 1);
        }

        return [
            'key' => $module->value.':'.$record->getKey(),
            'module_code' => $module->value,
            'type' => $module->getLabel(),
            'number' => filled($number) ? (string) $number : '-',
            'date' => $date,
            'requested_by' => $requester ?? '-',
            'status' => $this->statusLabel($record, $status),
            'status_color' => method_exists($status, 'getColor') ? $status->getColor() : 'gray',
            'progress' => min($approvedLevels, $levels).'/'.$levels,
            'requested_at' => $requestedAt,
            'updated_at' => $record->getAttribute('updated_at'),
            'notes' => filled($notes) ? (string) $notes : '-',
            'review_url' => $reviewUrl,
        ];
    }

    private function statusLabel(Model $record, mixed $status): string
    {
        if ($record instanceof StockAdjustment) {
            return $record->approvalStatusLabel();
        }

        if ($record instanceof StockMovement) {
            if ($status === StockMovementApprovalStatus::Pending && $record->approval_level > 1) {
                return 'L'.($record->approval_level - 1).' Approved';
            }
        }

        if ($record instanceof StockOpnameSession) {
            if ($status === StockOpnameApprovalStatus::Posted) {
                return 'Posted';
            }

            if ($status === StockOpnameApprovalStatus::Pending && $record->approval_level > 1) {
                return 'L'.($record->approval_level - 1).' Approved';
            }
        }

        if ($record instanceof PurchaseRequest && $this->statusValue($status) === 'pending' && $record->approval_level > 1) {
            return 'L'.($record->approval_level - 1).' Approved';
        }

        if ($status instanceof BackedEnum && method_exists($status, 'getLabel')) {
            return $status->getLabel();
        }

        return filled($status) ? Str::headline((string) $status) : '-';
    }

    private function statusValue(mixed $status): ?string
    {
        return $status instanceof BackedEnum ? $status->value : (filled($status) ? (string) $status : null);
    }

    private function sortValue(array $record, string $column): int|float|string|null
    {
        $value = $record[$column] ?? null;

        return match ($column) {
            'date', 'requested_at', 'updated_at' => $value?->getTimestamp(),
            'progress' => $this->progressValue((string) $value),
            default => filled($value) ? Str::lower((string) $value) : null,
        };
    }

    private function compareSortValues(int|float|string|null $left, int|float|string|null $right): int
    {
        if ($left === null || $right === null) {
            return match (true) {
                $left === $right => 0,
                $left === null => -1,
                default => 1,
            };
        }

        if (is_string($left) && is_string($right)) {
            return strnatcasecmp($left, $right);
        }

        return $left <=> $right;
    }

    private function progressValue(string $progress): float
    {
        [$approved, $levels] = array_map('intval', explode('/', $progress, 2));

        return $levels === 0 ? 0 : $approved / $levels;
    }
}

<?php

namespace App\Services;

use App\Enums\ApprovalModule;
use App\Enums\RecordStatus;
use App\Enums\StockOpnameStatus;
use App\Models\ApprovalLog;
use App\Models\ApprovalSetting;
use App\Models\DirectOrder;
use App\Models\GoodsReceipt;
use App\Models\PaymentVoidRequest;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderCloseRequest;
use App\Models\PurchaseRequest;
use App\Models\StockAdjustment;
use App\Models\StockMovement;
use App\Models\StockOpnameSession;
use App\Models\User;
use App\Models\Vendor;
use App\Notifications\ApprovalRequestedNotification;
use BackedEnum;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ApprovalWorkflow
{
    public function requiresApproval(Model $record): bool
    {
        return $this->settingFor($record)?->levels()->exists() ?? false;
    }

    public function captureApprovalSetting(Model $record): bool
    {
        if ($record->getAttribute('approval_setting_id') !== null) {
            return $this->requiresApproval($record);
        }

        $setting = $this->activeSettingFor($record);

        if (! $setting || ! $setting->levels()->exists()) {
            return false;
        }

        $record->setAttribute('approval_setting_id', $setting->id);

        return true;
    }

    public function recordRequest(Model $record, ?string $requestedBy): void
    {
        if ($record->getAttribute('approval_setting_id') === null
            || (int) $record->getAttribute('approval_level') < 1) {
            return;
        }

        ApprovalLog::query()->create([
            'approvable_type' => $record::class,
            'approvable_id' => $record->getKey(),
            'module_code' => ApprovalModule::forModel($record)->value,
            'level' => (int) $record->getAttribute('approval_level'),
            'action' => 'requested',
            'acted_by' => $requestedBy,
        ]);
    }

    public function canApprove(Model $record, ?User $actor): bool
    {
        if (! $actor
            || (method_exists($record, 'trashed') && $record->trashed())
            || ($record instanceof StockAdjustment && $record->record_status !== RecordStatus::Saved)
            || app(SystemFreezeGuard::class)->isFrozen()
            || ! $this->requiresApproval($record)) {
            return false;
        }

        if ($record instanceof PaymentVoidRequest && (! $record->payment || ! app(PaymentVoidWorkflow::class)->isEligible($record->payment, $record->cancel_order))) {
            return false;
        }

        $status = $record->getAttribute('approval_status');
        $status = $status instanceof BackedEnum ? $status->value : $status;

        $processStatus = $record->getAttribute('status');
        $processStatus = $processStatus instanceof BackedEnum ? $processStatus->value : $processStatus;

        if (in_array($processStatus, ['draft', 'frozen', 'cancelled', 'shipped', 'received'], true)) {
            return false;
        }

        if (! in_array($status, ['requested', 'pending'], true)) {
            return false;
        }

        if ((int) $record->getAttribute('approval_level') < 1) {
            return false;
        }

        if ($actor->hasAnyRole(['admin', 'super_admin'])
            || $actor->roles()->where('code', 'FARMING_ADMIN')->exists()) {
            return true;
        }

        $level = $this->currentLevel($record);

        return $level?->role?->users()->whereKey($actor->id)->exists() ?? false;
    }

    public function notifyCurrentApprovers(Model $record): void
    {
        $status = $record->getAttribute('approval_status');
        $status = $status instanceof BackedEnum ? $status->value : $status;

        if (! in_array($status, ['requested', 'pending'], true)
            || (int) $record->getAttribute('approval_level') < 1
            || ! $this->requiresApproval($record)) {
            return;
        }

        $roleId = $this->currentLevel($record)?->role_id;

        if (! $roleId) {
            return;
        }

        User::query()
            ->where('is_active', true)
            ->where('record_status', RecordStatus::Saved)
            ->whereHas('roles', fn (Builder $roles): Builder => $roles
                ->where(fn (Builder $roles): Builder => $roles
                    ->whereKey($roleId)
                    ->orWhereIn('name', ['admin', 'super_admin'])
                    ->orWhere('code', 'FARMING_ADMIN')))
            ->get()
            ->each(fn (User $user): mixed => $user->notify(new ApprovalRequestedNotification($record)));
    }

    public function approve(Model $record, User $actor): Model
    {
        return $this->decide($record, $actor, 'approved');
    }

    public function reject(Model $record, User $actor, ?string $notes = null): Model
    {
        return $this->decide($record, $actor, 'rejected', $notes);
    }

    public function revise(Model $record, User $actor, ?string $notes = null): Model
    {
        return $this->decide($record, $actor, 'revised', $notes);
    }

    private function decide(Model $record, User $actor, string $action, ?string $notes = null): Model
    {
        $module = ApprovalModule::forModel($record);

        return DB::transaction(function () use ($record, $actor, $action, $notes, $module): Model {
            $payment = $record instanceof PaymentVoidRequest
                ? app(PaymentVoidWorkflow::class)->lockPayment($record->payment)
                : null;
            $lockedRecord = $record::query()->lockForUpdate()->findOrFail($record->getKey());
            if ($payment !== null) {
                $lockedRecord->setRelation('payment', $payment);
                app(PaymentVoidWorkflow::class)->assertEligible($payment, $lockedRecord->cancel_order);
                if (in_array($action, ['rejected', 'revised'], true) && blank($notes)) {
                    throw ValidationException::withMessages(['notes' => 'A reason is required.']);
                }
            }
            $setting = $this->settingFor($lockedRecord);

            if (! $setting) {
                throw ValidationException::withMessages([
                    'approval' => 'No approval setting is assigned to this record.',
                ]);
            }

            $levels = $setting->levels()->with('role')->get();
            $currentLevel = (int) ($lockedRecord->getAttribute('approval_level') ?: 1);
            $level = $levels->firstWhere('level', $currentLevel);

            if (! $level || ! $this->canApprove($lockedRecord, $actor)) {
                throw ValidationException::withMessages([
                    'approval' => 'You are not assigned to the current approval level.',
                ]);
            }

            $nextLevel = match ($action) {
                'approved' => $currentLevel + 1,
                'revised' => 1,
                default => $currentLevel,
            };
            $status = match (true) {
                $action === 'rejected' => 'rejected',
                $action === 'revised' => 'revised',
                $nextLevel > $levels->count() => 'approved',
                default => 'pending',
            };

            $lockedRecord->forceFill([
                'approval_status' => $status,
                'approval_level' => $nextLevel,
                'updated_by' => $actor->id,
            ])->save();

            if ($payment !== null && $status === 'approved') {
                app(PaymentVoidWorkflow::class)->complete($lockedRecord, $actor);
            }

            if ($lockedRecord instanceof StockMovement && $status === 'approved') {
                $lockedRecord->markApproved($actor->id);
            }

            if ($lockedRecord instanceof StockMovement && $status === 'rejected') {
                $lockedRecord->markApprovalRejected($actor->id);
            }

            if ($lockedRecord instanceof StockOpnameSession && $status === 'rejected') {
                $lockedRecord->forceFill([
                    'status' => StockOpnameStatus::Cancelled,
                    'updated_by' => $actor->id,
                ])->save();
            }

            if ($lockedRecord instanceof StockOpnameSession && $status === 'approved') {
                $lockedRecord->forceFill([
                    'status' => StockOpnameStatus::Approved,
                    'approved_by' => $actor->id,
                    'approved_at' => now(),
                    'updated_by' => $actor->id,
                ])->save();
            }

            if ($lockedRecord instanceof GoodsReceipt && $status === 'approved') {
                app(ReceiveGoodsReceiptStock::class)->handle($lockedRecord, $actor->id);
            }

            ApprovalLog::query()->create([
                'approvable_type' => $lockedRecord::class,
                'approvable_id' => $lockedRecord->getKey(),
                'module_code' => $module->value,
                'level' => $currentLevel,
                'action' => $action,
                'acted_by' => $actor->id,
                'notes' => $notes,
            ]);

            return $lockedRecord->refresh();
        });
    }

    private function settingFor(Model $record): ?ApprovalSetting
    {
        $settingId = $record->getAttribute('approval_setting_id');

        if ($settingId !== null) {
            return ApprovalSetting::query()->withTrashed()->find($settingId);
        }

        if ($this->usesApprovalSnapshot($record)) {
            return null;
        }

        return $this->activeSettingFor($record);
    }

    private function currentLevel(Model $record): ?object
    {
        return $this->settingFor($record)?->levels()
            ->with('role')
            ->where('level', (int) ($record->getAttribute('approval_level') ?: 1))
            ->first();
    }

    private function activeSettingFor(Model $record): ?ApprovalSetting
    {
        return ApprovalSetting::query()
            ->where('module_code', ApprovalModule::forModel($record)->value)
            ->where('is_active', true)
            ->first();
    }

    private function usesApprovalSnapshot(Model $record): bool
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
}

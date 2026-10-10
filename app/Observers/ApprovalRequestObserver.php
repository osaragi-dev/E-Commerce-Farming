<?php

namespace App\Observers;

use App\Services\ApprovalWorkflow;
use Illuminate\Database\Eloquent\Model;

class ApprovalRequestObserver
{
    public function created(Model $record): void
    {
        $this->notifyApprovers($record);
    }

    public function updated(Model $record): void
    {
        if (! $record->wasChanged(['approval_status', 'approval_level'])) {
            return;
        }

        $this->notifyApprovers($record);
    }

    private function notifyApprovers(Model $record): void
    {
        app(ApprovalWorkflow::class)->notifyCurrentApprovers($record);
    }
}

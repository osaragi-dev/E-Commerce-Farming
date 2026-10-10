<?php

namespace App\Policies;

use App\Enums\RecordStatus;
use App\Models\Repack;
use App\Models\SystemFreezeState;
use App\Models\User;

class RepackPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('ViewAny:Repack');
    }

    public function view(User $user, Repack $repack): bool
    {
        return $user->can('View:Repack');
    }

    public function create(User $user): bool
    {
        return $user->can('Create:Repack') && ! SystemFreezeState::current()->is_frozen;
    }

    public function update(User $user, Repack $repack): bool
    {
        return $user->can('Update:Repack') && ! $repack->trashed()
            && $repack->record_status === RecordStatus::Draft && $repack->applied_at === null
            && ! SystemFreezeState::current()->is_frozen;
    }

    public function delete(User $user, Repack $repack): bool
    {
        return $user->can('Delete:Repack') && $repack->record_status === RecordStatus::Draft
            && ! $repack->trashed() && $repack->applied_at === null && ! SystemFreezeState::current()->is_frozen;
    }

    public function restore(User $user, Repack $repack): bool
    {
        return $user->can('Restore:Repack') && $repack->trashed()
            && $repack->previous_record_status === RecordStatus::Draft && $repack->applied_at === null
            && ! SystemFreezeState::current()->is_frozen;
    }

    public function deleteAny(User $user): bool
    {
        return $user->can('DeleteAny:Repack') && ! SystemFreezeState::current()->is_frozen;
    }

    public function restoreAny(User $user): bool
    {
        return $user->can('RestoreAny:Repack') && ! SystemFreezeState::current()->is_frozen;
    }

    public function replicate(User $user, Repack $repack): bool
    {
        return ! $repack->trashed() && $this->create($user) && $this->view($user, $repack);
    }

    public function cancel(User $user, Repack $repack): bool
    {
        return $user->can('Cancel:Repack') && $repack->isCancellable() && ! SystemFreezeState::current()->is_frozen;
    }

    public function forceDelete(User $user, Repack $repack): bool
    {
        return false;
    }

    public function forceDeleteAny(User $user): bool
    {
        return false;
    }

    public function export(User $user): bool
    {
        return $user->can('Export:Repack');
    }
}

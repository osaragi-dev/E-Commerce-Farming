<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ProductImage;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

/**
 * Product images are managed as part of the Product resource (Bab 12),
 * so authorization piggybacks on the Product permission set rather than
 * having its own standalone permission group.
 */
class ProductImagePolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('View:Product');
    }

    public function view(AuthUser $authUser, ProductImage $record): bool
    {
        return $authUser->can('View:Product');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Update:Product');
    }

    public function update(AuthUser $authUser, ProductImage $record): bool
    {
        return $authUser->can('Update:Product');
    }

    public function delete(AuthUser $authUser, ProductImage $record): bool
    {
        return $authUser->can('Update:Product');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('Update:Product');
    }
}

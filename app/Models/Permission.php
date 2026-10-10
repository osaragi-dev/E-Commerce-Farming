<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Spatie\Permission\Models\Permission as SpatiePermission;

class Permission extends SpatiePermission
{
    use HasUuidPrimaryKey;
}

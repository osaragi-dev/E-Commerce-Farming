<?php

namespace App\Models;

use App\Models\Concerns\HasRecordLifecycle;
use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['module_code', 'is_active', 'created_by', 'updated_by', 'deleted_by'])]
class ApprovalSetting extends Model
{
    use HasFactory, HasRecordLifecycle, HasUuidPrimaryKey, SoftDeletes;

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function levels(): HasMany
    {
        return $this->hasMany(ApprovalSettingLevel::class)->orderBy('level');
    }

    protected function lifecycleUniqueAttributes(): array
    {
        return ['module_code'];
    }
}

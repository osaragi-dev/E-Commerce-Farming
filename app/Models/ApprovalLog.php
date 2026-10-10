<?php

namespace App\Models;

use App\Enums\ApprovalModule;
use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

#[Fillable(['approvable_type', 'approvable_id', 'module_code', 'level', 'action', 'acted_by', 'notes'])]
class ApprovalLog extends Model
{
    use HasUuidPrimaryKey;

    protected function casts(): array
    {
        return [
            'module_code' => ApprovalModule::class,
            'level' => 'integer',
        ];
    }

    public function approvable(): MorphTo
    {
        return $this->morphTo();
    }

    public function actedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acted_by');
    }
}

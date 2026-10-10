<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Database\Factories\CompanyGalleryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

#[Fillable(['company_profile_id', 'image', 'description', 'sort_order', 'is_active'])]
class CompanyGallery extends Model
{
    /** @use HasFactory<CompanyGalleryFactory> */
    use HasFactory, HasUuidPrimaryKey;

    public function updateTimestamps(): static
    {
        parent::updateTimestamps();

        if (! $this->exists) {
            $this->setUpdatedAt(null);
        }

        return $this;
    }

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function imageUrl(): string
    {
        return Storage::disk('public')->url($this->image);
    }

    public function companyProfile(): BelongsTo
    {
        return $this->belongsTo(CompanyProfile::class);
    }
}

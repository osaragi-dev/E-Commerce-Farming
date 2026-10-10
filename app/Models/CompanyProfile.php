<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

#[Fillable(['company_name', 'logo', 'description', 'address', 'whatsapp_number', 'email', 'instagram', 'updated_by'])]
class CompanyProfile extends Model
{
    use HasUuidPrimaryKey;

    /** @return HasMany<CompanyGallery, $this> */
    public function galleries(): HasMany
    {
        return $this->hasMany(CompanyGallery::class);
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public static function current(): self
    {
        return static::query()->first() ?? static::query()->create([
            'company_name' => 'Nama Perusahaan',
        ]);
    }

    public function logoUrl(): ?string
    {
        if (! $this->logo) {
            return null;
        }

        return Storage::disk('public')->url($this->logo);
    }

    public function mapsUrl(): ?string
    {
        if (! $this->address) {
            return null;
        }

        return 'https://www.google.com/maps/search/?api=1&query='.urlencode($this->address);
    }

    public function whatsappUrl(): ?string
    {
        if (! $this->whatsapp_number) {
            return null;
        }

        $digits = preg_replace('/\D/', '', $this->whatsapp_number);
        $digits = preg_replace('/^0/', '62', $digits);

        return "https://wa.me/{$digits}";
    }

    public function instagramUrl(): ?string
    {
        if (! $this->instagram) {
            return null;
        }

        return 'https://instagram.com/'.ltrim($this->instagram, '@');
    }
}

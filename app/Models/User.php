<?php

namespace App\Models;

use App\Enums\RecordStatus;
use App\Models\Concerns\HasRecordLifecycle;
use App\Models\Concerns\HasUuidPrimaryKey;
use Database\Factories\UserFactory;
use Filament\Facades\Filament;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Auth\MustVerifyEmail as MustVerifyEmailTrait;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['name', 'email', 'phone', 'address', 'profile_photo_path', 'google_id', 'password', 'must_change_password', 'is_active', 'sso_id', 'created_by', 'updated_by'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser, MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, HasUuidPrimaryKey, MustVerifyEmailTrait, Notifiable, SoftDeletes;

    use HasRecordLifecycle {
        save as private saveWithLifecycle;
    }

    protected string $guard_name = 'web';

    protected $attributes = [
        'must_change_password' => false,
    ];

    /** @param array<string, mixed> $options */
    public function save(array $options = []): bool
    {
        if ($this->record_status === RecordStatus::Draft) {
            throw ValidationException::withMessages([
                'record_status' => 'User accounts cannot be saved as drafts.',
            ]);
        }

        return $this->saveWithLifecycle($options);
    }

    public function requiresPasswordChange(): bool
    {
        return (bool) $this->must_change_password;
    }

    public function canImpersonate(): bool
    {
        return $this->canAccessPanel(Filament::getPanel('admin'))
            && ! $this->requiresPasswordChange()
            && $this->can('Impersonate:User');
    }

    public function canBeImpersonated(): bool
    {
        return $this->canAccessPanel(Filament::getPanel('admin'))
            && ! $this->hasRole('customer');
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return ($this->record_status === RecordStatus::Saved
                || $this->record_status === RecordStatus::Saved->value)
            && $this->is_active
            && ! $this->trashed()
            && $this->roles()->where('guard_name', 'web')->where('name', '!=', 'customer')->exists();
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'must_change_password' => 'boolean',
            'is_active' => 'boolean',
            'sso_id' => 'integer',
        ];
    }
}

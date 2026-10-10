<?php

namespace App\Models;

use App\Enums\CustomerType;
use App\Enums\RecordStatus;
use App\Models\Concerns\HasRecordLifecycle;
use App\Models\Concerns\HasUuidPrimaryKey;
use Database\Factories\CustomerFactory;
use Illuminate\Auth\MustVerifyEmail as MustVerifyEmailTrait;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable([
    'name',
    'phone',
    'email',
    'password',
    'remember_token',
    'email_verified_at',
    'google_id',
    'address',
    'profile_photo_path',
    'is_active',
    'customer_type',
])]
#[Hidden(['password', 'remember_token', 'google_id'])]
class Customer extends Authenticatable implements MustVerifyEmail
{
    /** @var list<string> */
    public const ADDRESS_OPTIONS = [
        'SCCR',
        'Agung Putra University',
        'Sains De Resto',
        'Karenina Agung Resort',
        'Agung Farm & Garden',
    ];

    /** @use HasFactory<CustomerFactory> */
    use HasFactory, HasRecordLifecycle, HasUuidPrimaryKey, MustVerifyEmailTrait, Notifiable;

    use SoftDeletes {
        restore as protected restoreSoftDeletedRecord;
    }

    protected static function booted(): void
    {
        static::restored(function (Customer $customer): void {
            $customer->statusLogs()->create([
                'event' => 'restored',
                'from_status' => RecordStatus::Deleted,
                'to_status' => $customer->record_status,
                'changed_by_user_id' => $customer->currentLifecycleActorId(),
                'created_at' => now(),
            ]);
        });
    }

    public function restore(): bool
    {
        if (! $this->trashed()) {
            return false;
        }

        return $this->getConnection()->transaction(fn (): bool => $this->restoreSoftDeletedRecord());
    }

    public function statusLogs(): HasMany
    {
        return $this->hasMany(CustomerStatusLog::class)->latest('created_at')->latest('id');
    }

    protected function casts(): array
    {
        return [
            'customer_type' => CustomerType::class,
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    /** @return HasMany<Order, $this> */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function hasLoginAccount(): bool
    {
        return filled($this->password) || filled($this->google_id);
    }

    public function accountStatusLabel(): string
    {
        return $this->hasLoginAccount() ? 'Registered' : 'Profile Only';
    }

    /**
     * TCS revision (25 Aug 2026): Customer tidak punya kolom "slug"
     * kayak Sectors/Products, jadi cek duplikat lifecycle-nya dikunci
     * ke "email" — itu identifier alami yang harus unik per Customer.
     */
    protected function lifecycleUniqueAttributes(): array
    {
        return ['email', 'phone'];
    }
}

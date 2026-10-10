<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    private const string ADMIN_EMAIL = 'admin@sccr.id';

    private const string ADMIN_ROLE = 'super_admin';

    public function up(): void
    {
        $customer = DB::table('customers')
            ->whereRaw('LOWER(email) = ?', [self::ADMIN_EMAIL])
            ->first();

        if ($customer === null) {
            return;
        }

        DB::transaction(function () use ($customer): void {
            $user = DB::table('users')
                ->whereRaw('LOWER(email) = ?', [self::ADMIN_EMAIL])
                ->first();

            if ($user === null) {
                if (blank($customer->password) && blank($customer->google_id)) {
                    throw new RuntimeException('Cannot restore admin@sccr.id because the customer profile has no login credentials.');
                }

                $userId = (string) Str::orderedUuid();

                DB::table('users')->insert([
                    'id' => $userId,
                    'name' => $customer->name,
                    'email' => $customer->email,
                    'google_id' => $customer->google_id,
                    'phone' => $customer->phone,
                    'address' => $customer->address,
                    'profile_photo_path' => $customer->profile_photo_path,
                    'email_verified_at' => $customer->email_verified_at,
                    'password' => $customer->password,
                    'is_active' => true,
                    'record_status' => 'saved',
                    'previous_record_status' => null,
                    'remember_token' => $customer->remember_token,
                    'created_by' => null,
                    'updated_by' => null,
                    'deleted_by' => null,
                    'created_at' => $customer->created_at,
                    'updated_at' => null,
                    'deleted_at' => null,
                ]);

                $user = (object) ['id' => $userId];
            } else {
                DB::table('users')
                    ->where('id', $user->id)
                    ->update([
                        'is_active' => true,
                        'record_status' => 'saved',
                        'previous_record_status' => null,
                        'deleted_by' => null,
                        'deleted_at' => null,
                    ]);
            }

            $roleId = $this->ensureSuperAdminRole();
            $modelHasRoles = config('permission.table_names.model_has_roles', 'model_has_roles');
            $modelKey = config('permission.column_names.model_morph_key', 'model_id');
            $roleKey = config('permission.column_names.role_pivot_key') ?: 'role_id';
            $assignment = [
                $roleKey => $roleId,
                'model_type' => 'App\\Models\\User',
                $modelKey => $user->id,
            ];

            $teamKey = config('permission.column_names.team_foreign_key', 'team_id');
            if (Schema::hasColumn($modelHasRoles, $teamKey)) {
                $assignment[$teamKey] = null;
            }

            if (! DB::table($modelHasRoles)->where($assignment)->exists()) {
                DB::table($modelHasRoles)->insert($assignment);
            }

            DB::table('customers')
                ->where('id', $customer->id)
                ->update([
                    'password' => null,
                    'remember_token' => null,
                    'email_verified_at' => null,
                    'google_id' => null,
                    'updated_by' => null,
                ]);

            if (Schema::hasTable('sessions')) {
                DB::table('sessions')->delete();
            }

            app('cache')
                ->store(config('permission.cache.store') !== 'default' ? config('permission.cache.store') : null)
                ->forget(config('permission.cache.key'));
        });
    }

    public function down(): void
    {
        // The repaired User row and cleared Customer credentials cannot be
        // restored safely without reintroducing the removed customer account.
    }

    private function ensureSuperAdminRole(): string
    {
        $roles = config('permission.table_names.roles', 'roles');
        $role = DB::table($roles)
            ->where('name', self::ADMIN_ROLE)
            ->where('guard_name', 'web')
            ->first();

        if ($role !== null) {
            if ($role->deleted_at !== null) {
                DB::table($roles)->where('id', $role->id)->update(['deleted_at' => null]);
            }

            return (string) $role->id;
        }

        $roleId = (string) Str::orderedUuid();
        DB::table($roles)->insert([
            'id' => $roleId,
            'code' => self::ADMIN_ROLE,
            'name' => self::ADMIN_ROLE,
            'guard_name' => 'web',
            'created_by' => null,
            'updated_by' => null,
            'created_at' => now(),
            'updated_at' => null,
            'deleted_at' => null,
        ]);

        return $roleId;
    }
};

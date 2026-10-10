<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('carts', 'customer_id')) {
            Schema::table('carts', function (Blueprint $table): void {
                $table->foreignUuid('customer_id')
                    ->nullable()
                    ->after('id')
                    ->unique()
                    ->constrained('customers')
                    ->cascadeOnDelete();
            });
        }

        $legacyCustomerIds = $this->legacyCustomerUserIds();

        DB::transaction(function () use ($legacyCustomerIds): void {
            $this->migrateCustomerAccounts($legacyCustomerIds);
            $this->moveCartsToCustomers();
            $this->clearInternalAuditReferences($legacyCustomerIds);
            $this->detachLegacyCustomerRelations($legacyCustomerIds);
        });

        $this->removeLegacyColumns();
        $this->removeCustomerRole();
    }

    public function down(): void
    {
        // Customer authentication data cannot be restored safely to deleted
        // User rows after this migration has run.
    }

    /** @return list<string> */
    private function legacyCustomerUserIds(): array
    {
        $modelHasRoles = config('permission.table_names.model_has_roles', 'model_has_roles');
        $roles = config('permission.table_names.roles', 'roles');
        $modelKey = config('permission.column_names.model_morph_key', 'model_id');

        $roleUserIds = DB::table($modelHasRoles)
            ->join($roles, $roles.'.id', '=', $modelHasRoles.'.role_id')
            ->where($roles.'.name', 'customer')
            ->where($modelHasRoles.'.model_type', 'App\\Models\\User')
            ->pluck($modelHasRoles.'.'.$modelKey)
            ->all();

        $linkedUserIds = DB::table('customers')
            ->whereNotNull('user_id')
            ->pluck('user_id')
            ->all();

        return array_values(array_unique(array_merge($roleUserIds, $linkedUserIds)));
    }

    /** @param list<string> $legacyCustomerIds */
    private function migrateCustomerAccounts(array $legacyCustomerIds): void
    {
        if ($legacyCustomerIds === []) {
            return;
        }

        foreach (DB::table('users')->whereIn('id', $legacyCustomerIds)->orderBy('id')->get() as $user) {
            $customer = DB::table('customers')->where('user_id', $user->id)->first();
            $isDeleted = $user->deleted_at !== null;
            $customerId = $customer?->id ?? (string) Str::orderedUuid();

            if ($customer === null) {
                DB::table('customers')->insert([
                    'id' => $customerId,
                    'name' => $user->name,
                    'phone' => $user->phone,
                    'email' => $user->email,
                    'password' => $user->password,
                    'remember_token' => $user->remember_token,
                    'email_verified_at' => $user->email_verified_at,
                    'google_id' => $user->google_id,
                    'address' => $user->address,
                    'profile_photo_path' => $user->profile_photo_path,
                    'is_active' => $user->is_active,
                    'record_status' => $isDeleted ? 'deleted' : 'saved',
                    'previous_record_status' => $isDeleted ? 'saved' : null,
                    'created_by' => null,
                    'updated_by' => null,
                    'deleted_by' => null,
                    'created_at' => $user->created_at,
                    'updated_at' => null,
                    'deleted_at' => $isDeleted ? $user->deleted_at : null,
                ]);

                continue;
            }

            DB::table('customers')
                ->where('id', $customer->id)
                ->update([
                    'password' => $user->password,
                    'remember_token' => $user->remember_token,
                    'email_verified_at' => $user->email_verified_at,
                    'google_id' => $user->google_id,
                    'is_active' => $user->is_active,
                    'profile_photo_path' => $customer->profile_photo_path ?? $user->profile_photo_path,
                    'record_status' => $isDeleted ? 'deleted' : 'saved',
                    'previous_record_status' => $isDeleted ? 'saved' : null,
                    'deleted_at' => $isDeleted ? $user->deleted_at : null,
                ]);
        }
    }

    private function moveCartsToCustomers(): void
    {
        foreach (DB::table('carts')->whereNotNull('user_id')->get(['id', 'user_id']) as $cart) {
            $customerId = DB::table('customers')
                ->where('user_id', $cart->user_id)
                ->value('id');

            if ($customerId === null) {
                DB::table('cart_items')->where('cart_id', $cart->id)->delete();
                DB::table('carts')->where('id', $cart->id)->delete();

                continue;
            }

            DB::table('carts')->where('id', $cart->id)->update(['customer_id' => $customerId]);
        }
    }

    /** @param list<string> $legacyCustomerIds */
    private function clearInternalAuditReferences(array $legacyCustomerIds): void
    {
        if ($legacyCustomerIds === []) {
            return;
        }

        $auditColumns = [
            'users' => ['created_by', 'updated_by', 'deleted_by'],
            'customers' => ['created_by', 'updated_by', 'deleted_by'],
            'units' => ['created_by', 'updated_by', 'deleted_by'],
            'sectors' => ['created_by', 'updated_by', 'deleted_by'],
            'categories' => ['created_by', 'updated_by', 'deleted_by'],
            'products' => ['created_by', 'updated_by', 'deleted_by'],
            'product_variants' => ['created_by', 'updated_by', 'deleted_by'],
            'stock_mutations' => ['recorded_by'],
            'stock_adjustments' => ['adjusted_by', 'updated_by'],
            'orders' => ['created_by', 'updated_by'],
            'order_status_logs' => ['changed_by_user_id'],
            'payments' => ['created_by', 'updated_by', 'verified_by'],
            'payment_proofs' => ['reviewed_by'],
            'customer_status_logs' => ['changed_by_user_id'],
            'company_profiles' => ['updated_by'],
            'shipping_settings' => ['updated_by'],
            'product_display_settings' => ['updated_by'],
            'payment_settings' => ['updated_by'],
            'roles' => ['created_by', 'updated_by'],
        ];

        foreach ($auditColumns as $table => $columns) {
            foreach ($columns as $column) {
                if (Schema::hasColumn($table, $column)) {
                    DB::table($table)
                        ->whereIn($column, $legacyCustomerIds)
                        ->update([$column => null]);
                }
            }
        }

        DB::table('sessions')
            ->whereIn('user_id', $legacyCustomerIds)
            ->update(['user_id' => null]);
    }

    /** @param list<string> $legacyCustomerIds */
    private function detachLegacyCustomerRelations(array $legacyCustomerIds): void
    {
        $modelHasRoles = config('permission.table_names.model_has_roles', 'model_has_roles');
        $modelHasPermissions = config('permission.table_names.model_has_permissions', 'model_has_permissions');
        $modelKey = config('permission.column_names.model_morph_key', 'model_id');

        if ($legacyCustomerIds !== []) {
            DB::table($modelHasRoles)
                ->where('model_type', 'App\\Models\\User')
                ->whereIn($modelKey, $legacyCustomerIds)
                ->delete();

            DB::table($modelHasPermissions)
                ->where('model_type', 'App\\Models\\User')
                ->whereIn($modelKey, $legacyCustomerIds)
                ->delete();
        }

        if (Schema::hasColumn('customers', 'user_id')) {
            try {
                Schema::table('customers', function (Blueprint $table): void {
                    $table->dropForeign(['user_id']);
                });
            } catch (Throwable) {
                // The legacy column may already have lost its constraint in a
                // partially completed maintenance run.
            }
        }

        if (Schema::hasColumn('carts', 'user_id')) {
            try {
                Schema::table('carts', function (Blueprint $table): void {
                    $table->dropForeign(['user_id']);
                });
            } catch (Throwable) {
                // See the equivalent customer column handling above.
            }

            Schema::table('carts', function (Blueprint $table): void {
                $table->dropUnique('carts_user_id_unique');
            });
        }

        if ($legacyCustomerIds !== []) {
            DB::table('users')->whereIn('id', $legacyCustomerIds)->delete();
        }
    }

    private function removeLegacyColumns(): void
    {
        if (Schema::hasColumn('customers', 'user_id')) {
            Schema::table('customers', function (Blueprint $table): void {
                $table->dropColumn('user_id');
            });
        }

        if (Schema::hasColumn('carts', 'user_id')) {
            Schema::table('carts', function (Blueprint $table): void {
                $table->dropColumn('user_id');
            });
        }
    }

    private function removeCustomerRole(): void
    {
        $roles = config('permission.table_names.roles', 'roles');
        $roleHasPermissions = config('permission.table_names.role_has_permissions', 'role_has_permissions');
        $modelHasRoles = config('permission.table_names.model_has_roles', 'model_has_roles');
        $roleId = DB::table($roles)->where('name', 'customer')->where('guard_name', 'web')->value('id');

        if ($roleId === null) {
            return;
        }

        DB::table($modelHasRoles)->where('role_id', $roleId)->delete();
        DB::table($roleHasPermissions)->where('role_id', $roleId)->delete();
        DB::table($roles)->where('id', $roleId)->delete();
    }
};

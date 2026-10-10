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
        Schema::create('system_freeze_states', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('key')->unique();
            $table->boolean('is_frozen')->default(false)->index();
            $table->foreignUuid('frozen_session_id')->nullable()->constrained('stock_opname_sessions')->nullOnDelete();
            $table->foreignUuid('frozen_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('frozen_at')->nullable();
            $table->foreignUuid('unfrozen_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('unfrozen_at')->nullable();
            $table->timestamps();
        });

        DB::table('system_freeze_states')->insert([
            'id' => (string) Str::uuid7(),
            'key' => 'system',
            'is_frozen' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('system_freeze_states');
    }
};

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
        if (! DB::table('approval_settings')->where('module_code', 'void_payment')->exists()) {
            DB::table('approval_settings')->insert([
                'id' => (string) Str::uuid7(),
                'module_code' => 'void_payment',
                'is_active' => true,
                'record_status' => 'saved',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        Schema::create('payment_void_requests', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('payment_id')->constrained();
            $table->foreignUuid('requested_by')->constrained('users');
            $table->text('reason');
            $table->foreignUuid('approval_setting_id')->nullable()->constrained();
            $table->string('approval_status')->default('requested');
            $table->unsignedSmallInteger('approval_level')->default(1);
            $table->foreignUuid('updated_by')->nullable()->constrained('users');
            $table->timestamps();
            $table->index(['payment_id', 'approval_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_void_requests');
    }
};

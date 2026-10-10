<?php

use App\Enums\ApprovalModule;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_adjustments', function (Blueprint $table): void {
            $table->string('approval_status')->default('approved')->after('reason')->index();
            $table->unsignedSmallInteger('approval_level')->default(0)->after('approval_status');
            $table->timestamp('applied_at')->nullable()->after('updated_at');
            $table->foreignUuid('applied_by')->nullable()->after('applied_at')->constrained('users')->nullOnDelete();
        });

        DB::table('stock_adjustments')->update([
            'approval_status' => 'approved',
            'approval_level' => 0,
            'applied_at' => DB::raw('created_at'),
        ]);

        $setting = DB::table('approval_settings')
            ->where('module_code', ApprovalModule::StockAdjustment->value)
            ->first();

        if ($setting === null) {
            DB::table('approval_settings')->insert([
                'id' => (string) Str::uuid7(),
                'module_code' => ApprovalModule::StockAdjustment->value,
                'is_active' => false,
                'record_status' => 'saved',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } elseif (! DB::table('approval_setting_levels')
            ->where('approval_setting_id', $setting->id)
            ->exists()) {
            DB::table('approval_settings')
                ->where('id', $setting->id)
                ->update(['is_active' => false]);
        }
    }

    public function down(): void
    {
        DB::table('approval_settings')
            ->where('module_code', ApprovalModule::StockAdjustment->value)
            ->delete();

        Schema::table('stock_adjustments', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('applied_by');
            $table->dropColumn(['applied_at', 'approval_level', 'approval_status']);
        });
    }
};

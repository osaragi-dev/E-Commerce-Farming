<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_proofs', function (Blueprint $table): void {
            $table->timestamp('resubmission_deadline_at')->nullable();
            $table->index(
                ['is_current', 'status', 'resubmission_deadline_at'],
                'payment_proofs_expiry_lookup_index',
            );
        });
    }

    public function down(): void
    {
        Schema::table('payment_proofs', function (Blueprint $table): void {
            $table->dropIndex('payment_proofs_expiry_lookup_index');
            $table->dropColumn('resubmission_deadline_at');
        });
    }
};

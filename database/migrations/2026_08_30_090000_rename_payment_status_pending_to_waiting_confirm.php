<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PaymentStatus::Pending was renamed to PaymentStatus::WaitingConfirm
 * (value 'pending' -> 'waiting_confirm') to match the business rule
 * lifecycle: WAITING PAYMENT (order stage, no Payment row yet) -> WAITING
 * CONFIRM (Payment row exists) -> PAID -> ... This migration only updates
 * existing data + column defaults; it does not change table structure.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('payments')
            ->where('status', 'pending')
            ->update(['status' => 'waiting_confirm']);

        DB::table('payment_proofs')
            ->where('status', 'pending')
            ->update(['status' => 'waiting_confirm']);

        Schema::table('payments', function (Blueprint $table): void {
            $table->string('status')->default('waiting_confirm')->change();
        });

        Schema::table('payment_proofs', function (Blueprint $table): void {
            $table->string('status')->default('waiting_confirm')->change();
        });
    }

    public function down(): void
    {
        DB::table('payments')
            ->where('status', 'waiting_confirm')
            ->update(['status' => 'pending']);

        DB::table('payment_proofs')
            ->where('status', 'waiting_confirm')
            ->update(['status' => 'pending']);

        Schema::table('payments', function (Blueprint $table): void {
            $table->string('status')->default('pending')->change();
        });

        Schema::table('payment_proofs', function (Blueprint $table): void {
            $table->string('status')->default('pending')->change();
        });
    }
};

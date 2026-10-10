<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Orders need to know their intended payment method even before a Payment
 * row exists — per business rule B, Cash orders sit in WaitingPayment with
 * no Payment record at all until Admin submits Record Payment. Without this
 * column there is no way to tell a Cash order (no Payment yet) apart from
 * any other order that simply doesn't have a Payment yet, which is needed
 * by RecordPaymentAction's eligibility check and by the existing "Payment
 * Method" filter/column on the Orders table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->string('payment_method')->nullable()->after('shipping_method');
        });

        // Backfill from the existing Payment relation for orders created
        // before this column existed, so historical/seeded data doesn't
        // suddenly show a blank Payment Method. Raw statement used because
        // Laravel's query builder join+update doesn't reliably translate to
        // PostgreSQL's UPDATE ... FROM syntax across drivers.
        DB::statement(<<<'SQL'
            UPDATE orders
            SET payment_method = payments.method
            FROM payments
            WHERE payments.order_id = orders.id
              AND orders.payment_method IS NULL
        SQL);
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn('payment_method');
        });
    }
};

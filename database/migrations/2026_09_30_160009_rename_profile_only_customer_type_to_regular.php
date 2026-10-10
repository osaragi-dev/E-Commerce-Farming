<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('customers')->where('customer_type', 'profile_only')->update(['customer_type' => 'regular']);

        Schema::table('customers', function (Blueprint $table): void {
            $table->string('customer_type')->default('regular')->change();
        });
    }

    public function down(): void
    {
        DB::table('customers')->where('customer_type', 'regular')->update(['customer_type' => 'profile_only']);

        Schema::table('customers', function (Blueprint $table): void {
            $table->string('customer_type')->default('profile_only')->change();
        });
    }
};

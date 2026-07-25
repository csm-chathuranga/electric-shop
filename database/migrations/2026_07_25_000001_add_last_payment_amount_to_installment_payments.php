<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('installment_payments', function (Blueprint $table) {
            $table->decimal('last_payment_amount', 10, 2)->nullable()->after('amount_paid');
        });
    }

    public function down(): void
    {
        Schema::table('installment_payments', function (Blueprint $table) {
            $table->dropColumn('last_payment_amount');
        });
    }
};

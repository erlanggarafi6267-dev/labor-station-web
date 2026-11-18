<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->unsignedBigInteger('platform_deduction')
                ->nullable();

            $table->unsignedBigInteger('net_payout')
                ->nullable();

            $table->string('payout_status', 20)
                ->nullable();

            $table->timestamp('payout_date')
                ->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn([
                'platform_deduction',
                'net_payout',
                'payout_status',
                'payout_date',
            ]);
        });
    }
};
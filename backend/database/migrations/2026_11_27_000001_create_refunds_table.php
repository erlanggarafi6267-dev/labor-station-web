<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('refunds', function (Blueprint $table) {
            $table->id();

            $table->foreignId('transaction_id')
                ->unique()
                ->constrained('transactions')
                ->restrictOnDelete();

            $table->foreignId('processed_by')
                ->constrained('users')
                ->restrictOnDelete();

            $table->unsignedBigInteger('amount');
            $table->string('method', 20)->default('CASH');
            $table->string('reason', 1000);
            $table->timestamp('refunded_at');
            $table->timestamps();

            $table->index('refunded_at');
            $table->index(['processed_by', 'refunded_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refunds');
    }
};

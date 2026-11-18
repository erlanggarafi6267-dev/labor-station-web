<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_prices', function (Blueprint $table) {
            $table->id();

            $table->foreignId('product_id')
                ->constrained('products')
                ->restrictOnDelete();

            $table->string('sales_channel', 20);
            $table->unsignedBigInteger('price');

            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->unique(
                ['product_id', 'sales_channel'],
                'product_prices_product_channel_unique'
            );

            $table->index(['sales_channel', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_prices');
    }
};
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('pos_menu_item_id')->constrained('pos_menu_items');
            $table->string('name'); // snapshot of item name at time of order
            $table->decimal('price', 10, 2); // snapshot of price at time of order
            $table->integer('quantity');
            $table->decimal('line_total', 10, 2);
            $table->timestamps();

            $table->index('order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_items');
    }
};

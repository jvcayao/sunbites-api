<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_menu_item_inventory', function (Blueprint $table) {
            $table->foreignId('pos_menu_item_id')->constrained('pos_menu_items')->cascadeOnDelete();
            $table->foreignId('inventory_item_id')->constrained('inventory_items')->cascadeOnDelete();
            $table->integer('quantity_used')->default(1);
            $table->unique(['pos_menu_item_id', 'inventory_item_id'], 'pmi_inventory_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_menu_item_inventory');
    }
};

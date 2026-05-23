<?php

use App\Enums\InventoryLogType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('inventory_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('adjusted_by')->constrained('users')->cascadeOnDelete();
            $table->enum('type', array_column(InventoryLogType::cases(), 'value'));
            $table->decimal('quantity_change', 8, 2);
            $table->decimal('stock_after', 8, 2);
            $table->string('reason');
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_logs');
    }
};

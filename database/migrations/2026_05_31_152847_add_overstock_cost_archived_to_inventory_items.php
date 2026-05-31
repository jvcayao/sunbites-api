<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_items', function (Blueprint $table) {
            $table->decimal('overstock_threshold', 8, 2)->nullable()->after('restock_threshold');
            $table->decimal('cost_per_unit', 8, 2)->nullable()->after('overstock_threshold');
            $table->boolean('is_archived')->default(false)->after('cost_per_unit');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_items', function (Blueprint $table) {
            $table->dropColumn(['overstock_threshold', 'cost_per_unit', 'is_archived']);
        });
    }
};

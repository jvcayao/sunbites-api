<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_logs', function (Blueprint $table) {
            $table->string('item_name_snapshot')->after('reason');
            $table->foreignId('order_id')->nullable()->after('item_name_snapshot')->constrained('orders')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('inventory_logs', function (Blueprint $table) {
            $table->dropForeign(['order_id']);
            $table->dropColumn(['item_name_snapshot', 'order_id']);
        });
    }
};

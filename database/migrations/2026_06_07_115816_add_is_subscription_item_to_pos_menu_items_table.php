<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_menu_items', function (Blueprint $table) {
            $table->boolean('is_subscription_item')->default(false)->after('is_available');
        });
    }

    public function down(): void
    {
        Schema::table('pos_menu_items', function (Blueprint $table) {
            $table->dropColumn('is_subscription_item');
        });
    }
};

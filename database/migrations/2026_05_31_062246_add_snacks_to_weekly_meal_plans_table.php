<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('weekly_meal_plans', function (Blueprint $table) {
            $table->string('snacks')->nullable()->after('soup');
        });
    }

    public function down(): void
    {
        Schema::table('weekly_meal_plans', function (Blueprint $table) {
            $table->dropColumn('snacks');
        });
    }
};

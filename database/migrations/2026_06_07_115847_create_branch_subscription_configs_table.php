<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('branch_subscription_configs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete()->unique();
            $table->unsignedTinyInteger('meal_daily_limit')->default(1);
            $table->unsignedTinyInteger('snack_daily_limit')->default(1);
            $table->unsignedTinyInteger('drink_daily_limit')->default(1);
            $table->unsignedTinyInteger('extra_daily_limit')->default(1);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('branch_subscription_configs');
    }
};

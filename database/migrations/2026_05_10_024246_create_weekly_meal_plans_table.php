<?php

use App\Enums\DayOfWeek;
use App\Enums\SchoolMonth;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('weekly_meal_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->enum('school_month', array_column(SchoolMonth::cases(), 'value'));
            $table->tinyInteger('week_number')->unsigned();
            $table->enum('day_of_week', array_column(DayOfWeek::cases(), 'value'));
            $table->string('ulam')->nullable();
            $table->string('vegetables')->nullable();
            $table->string('fruit')->nullable();
            $table->string('soup')->nullable();
            $table->timestamps();

            $table->unique(['branch_id', 'school_month', 'week_number', 'day_of_week'], 'weekly_meal_plans_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('weekly_meal_plans');
    }
};

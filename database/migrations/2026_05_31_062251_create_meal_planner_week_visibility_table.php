<?php

use App\Enums\SchoolMonth;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meal_planner_week_visibility', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->enum('school_month', array_column(SchoolMonth::cases(), 'value'));
            $table->tinyInteger('week_number')->unsigned();
            $table->boolean('visible_to_parents')->default(true);
            $table->timestamp('updated_at')->nullable();

            $table->unique(['branch_id', 'school_month', 'week_number'], 'meal_planner_week_visibility_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meal_planner_week_visibility');
    }
};

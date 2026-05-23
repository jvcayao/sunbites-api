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
        Schema::create('students', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->string('student_number');
            $table->string('first_name');
            $table->string('last_name');
            $table->string('grade_level');
            $table->string('section')->nullable();
            $table->date('birthday');
            $table->string('photo_path')->nullable();
            $table->text('allergies')->nullable();
            $table->text('notes')->nullable();
            $table->string('qr_code')->unique();
            $table->string('student_type');
            $table->string('enrollment_status')->default('enrolled');
            $table->date('enrollment_date');
            $table->integer('points')->default(0);
            $table->decimal('total_spent', 10, 2)->default(0);
            $table->decimal('credit_balance', 10, 2)->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['branch_id', 'student_number']);
            $table->index(['branch_id', 'enrollment_status']);
            $table->index(['branch_id', 'student_type']);
            $table->index(['last_name', 'first_name']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('students');
    }
};

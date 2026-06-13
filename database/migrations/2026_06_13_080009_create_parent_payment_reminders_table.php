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
        Schema::create('parent_payment_reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_user_id')->constrained('parents')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->string('school_month');
            $table->integer('school_year');
            $table->timestamp('sent_at');
            $table->foreignId('sent_by_user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['parent_user_id', 'branch_id', 'school_month', 'school_year'], 'ppr_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('parent_payment_reminders');
    }
};

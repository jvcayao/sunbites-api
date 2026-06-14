<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pre_registrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();

            // Student info
            $table->string('first_name');
            $table->string('last_name');
            $table->string('student_number')->nullable();
            $table->string('grade_level');
            $table->string('section')->nullable();
            $table->date('birthday');
            $table->text('allergies')->nullable();
            $table->text('notes')->nullable();
            $table->enum('enrollment_type', ['subscription', 'non_subscription']);

            // Subscription period (null for non-subscription students)
            $table->string('subscription_start_month')->nullable();
            $table->unsignedSmallInteger('subscription_start_year')->nullable();
            $table->string('subscription_end_month')->nullable();
            $table->unsignedSmallInteger('subscription_end_year')->nullable();

            // Acknowledgement
            $table->string('signatory_name');
            $table->timestamp('acknowledged_at');

            // Status & processing
            $table->enum('status', ['pending', 'approved', 'rejected', 'expired'])->default('pending');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('rejected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('rejection_reason')->nullable();
            $table->timestamp('processed_at')->nullable();

            // Security
            $table->decimal('recaptcha_score', 3, 2)->nullable();
            $table->string('submitter_ip')->nullable();

            // Expiry
            $table->timestamp('expires_at');

            $table->timestamps();

            $table->index(['branch_id', 'status']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pre_registrations');
    }
};

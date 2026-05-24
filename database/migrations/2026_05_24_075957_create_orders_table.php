<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->nullable()->constrained('students')->nullOnDelete();
            $table->foreignId('cashier_id')->constrained('users');
            $table->string('receipt_number')->unique();
            $table->string('payment_method'); // cash | gcash | wallet
            $table->decimal('subtotal', 10, 2);
            $table->decimal('discount_amount', 10, 2)->default(0);
            $table->string('discount_reason')->nullable();
            $table->decimal('total', 10, 2);
            $table->decimal('amount_tendered', 10, 2)->nullable();
            $table->decimal('change_amount', 10, 2)->nullable();
            $table->string('reference_number', 50)->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_credit')->default(false);
            $table->decimal('credit_amount', 10, 2)->default(0);
            $table->integer('points_earned')->default(0);
            $table->string('status')->default('completed'); // completed | voided
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('void_reason')->nullable();
            $table->timestamps();

            $table->index(['branch_id', 'status']);
            $table->index(['branch_id', 'created_at']);
            $table->index('student_id');
            $table->index('cashier_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};

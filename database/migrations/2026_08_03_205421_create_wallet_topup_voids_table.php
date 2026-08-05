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
        Schema::create('wallet_topup_voids', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('wallet_transaction_id')->unique();
            $table->unsignedBigInteger('refund_wallet_transaction_id')->nullable();
            $table->foreignId('credit_transaction_id')->nullable()->constrained('credit_transactions')->nullOnDelete();
            $table->decimal('original_amount', 10, 2);
            $table->decimal('voided_amount', 10, 2);
            $table->decimal('shortfall_amount', 10, 2)->default(0);
            $table->string('void_reason', 500);
            $table->foreignId('voided_by')->constrained('users');
            $table->timestamp('created_at')->nullable();

            $table->index('refund_wallet_transaction_id');
            $table->index('student_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('wallet_topup_voids');
    }
};

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
        Schema::table('credit_transactions', function (Blueprint $table) {
            $table->foreignId('branch_id')->nullable()->after('student_id')
                ->constrained()->nullOnDelete();
            $table->string('payment_method')->nullable()->after('amount');
            $table->string('reference_number', 50)->nullable()->after('payment_method');
            $table->unsignedBigInteger('wallet_transaction_id')->nullable()->after('reference_number');

            $table->index(['branch_id', 'type', 'created_at']);
            $table->index('wallet_transaction_id');
        });

        Schema::table('credit_transactions', function (Blueprint $table) {
            $table->text('notes')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('credit_transactions', function (Blueprint $table) {
            $table->dropIndex(['branch_id', 'type', 'created_at']);
            $table->dropIndex(['wallet_transaction_id']);
            $table->dropConstrainedForeignId('branch_id');
            $table->dropColumn(['payment_method', 'reference_number', 'wallet_transaction_id']);
        });

        Schema::table('credit_transactions', function (Blueprint $table) {
            $table->string('notes')->nullable()->change();
        });
    }
};

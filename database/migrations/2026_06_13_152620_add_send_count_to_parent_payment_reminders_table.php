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
        Schema::table('parent_payment_reminders', function (Blueprint $table) {
            $table->unsignedInteger('send_count')->default(1)->after('sent_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('parent_payment_reminders', function (Blueprint $table) {
            $table->dropColumn('send_count');
        });
    }
};

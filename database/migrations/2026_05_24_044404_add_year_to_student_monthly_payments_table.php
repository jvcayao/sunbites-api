<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('student_monthly_payments', function (Blueprint $table) {
            $table->unsignedSmallInteger('year')->default(2025)->after('school_month');
            $table->unique(['student_id', 'school_month', 'year']);
            $table->dropUnique(['student_id', 'school_month']);
        });

        // Remove the temporary default now that existing rows have been backfilled
        Schema::table('student_monthly_payments', function (Blueprint $table) {
            $table->unsignedSmallInteger('year')->default(null)->change();
        });
    }

    public function down(): void
    {
        Schema::table('student_monthly_payments', function (Blueprint $table) {
            $table->dropUnique(['student_id', 'school_month', 'year']);
            $table->unique(['student_id', 'school_month']);
            $table->dropColumn('year');
        });
    }
};

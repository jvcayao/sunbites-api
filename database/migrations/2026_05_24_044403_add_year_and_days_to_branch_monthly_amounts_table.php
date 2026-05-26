<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branch_monthly_amounts', function (Blueprint $table) {
            $table->unsignedSmallInteger('year')->after('school_month');
            $table->unsignedSmallInteger('days')->after('year');
            $table->unique(['branch_id', 'school_month', 'year']);
            $table->dropUnique(['branch_id', 'school_month']);
        });
    }

    public function down(): void
    {
        Schema::table('branch_monthly_amounts', function (Blueprint $table) {
            $table->dropUnique(['branch_id', 'school_month', 'year']);
            $table->unique(['branch_id', 'school_month']);
            $table->dropColumn(['year', 'days']);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pre_registrations', function (Blueprint $table) {
            $table->timestamp('duplicate_check_passed_at')->nullable()->after('expires_at');
            $table->boolean('parent_email_exists')->default(false)->after('duplicate_check_passed_at');
            $table->boolean('parent_phone_exists')->default(false)->after('parent_email_exists');
        });
    }

    public function down(): void
    {
        Schema::table('pre_registrations', function (Blueprint $table) {
            $table->dropColumn(['duplicate_check_passed_at', 'parent_email_exists', 'parent_phone_exists']);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('first_name')->after('id');
            $table->string('last_name')->after('first_name');
            $table->string('middle_name')->nullable()->after('last_name');
            $table->string('nickname')->nullable()->after('middle_name');
            $table->date('birthday')->nullable()->after('nickname');
            $table->enum('gender', ['male', 'female', 'other'])->nullable()->after('birthday');
            $table->enum('civil_status', ['single', 'married', 'widowed', 'separated'])->nullable()->after('gender');
            $table->string('profile_photo_path')->nullable()->after('civil_status');

            $table->string('phone')->nullable()->after('profile_photo_path');
            $table->string('emergency_contact_name')->nullable()->after('phone');
            $table->string('emergency_contact_phone')->nullable()->after('emergency_contact_name');
            $table->string('emergency_contact_relationship')->nullable()->after('emergency_contact_phone');

            $table->string('address_line')->nullable()->after('emergency_contact_relationship');
            $table->string('city')->nullable()->after('address_line');
            $table->string('province')->nullable()->after('city');
            $table->string('zip_code')->nullable()->after('province');

            $table->string('position')->nullable()->after('zip_code');
            $table->enum('employment_type', ['full_time', 'part_time', 'contractual'])->nullable()->after('position');
            $table->date('date_hired')->nullable()->after('employment_type');
            $table->decimal('daily_rate', 8, 2)->nullable()->after('date_hired');

            $table->string('sss_number')->nullable()->after('daily_rate');
            $table->string('pagibig_number')->nullable()->after('sss_number');
            $table->string('philhealth_number')->nullable()->after('pagibig_number');
            $table->string('tin_number')->nullable()->after('philhealth_number');

            $table->boolean('is_active')->default(true)->after('tin_number');

            $table->softDeletes();

            $table->dropColumn('name');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('name')->after('id');
            $table->dropColumn([
                'first_name', 'last_name', 'middle_name', 'nickname', 'birthday',
                'gender', 'civil_status', 'profile_photo_path', 'phone',
                'emergency_contact_name', 'emergency_contact_phone', 'emergency_contact_relationship',
                'address_line', 'city', 'province', 'zip_code', 'position',
                'employment_type', 'date_hired', 'daily_rate', 'sss_number',
                'pagibig_number', 'philhealth_number', 'tin_number', 'is_active', 'deleted_at',
            ]);
        });
    }
};

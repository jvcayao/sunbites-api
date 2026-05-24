<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parents', function (Blueprint $table) {
            $table->id();
            $table->string('first_name');
            $table->string('last_name');
            $table->string('email')->unique();
            $table->string('password')->nullable();
            $table->string('phone')->nullable();
            $table->string('address')->nullable();
            $table->string('profile_photo_path')->nullable();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('remember_token', 100)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('parents');
    }
};

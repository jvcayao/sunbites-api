<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pre_registration_contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pre_registration_id')->constrained()->cascadeOnDelete();
            $table->string('full_name');
            $table->string('relationship');
            $table->string('phone');
            $table->string('email')->nullable();
            $table->string('address');
            $table->boolean('is_primary')->default(false);
            $table->timestamps();

            $table->index('pre_registration_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pre_registration_contacts');
    }
};

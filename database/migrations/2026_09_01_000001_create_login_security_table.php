<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('login_security', function (Blueprint $table) {
            $table->id();
            $table->string('ip_address', 45)->unique();
            $table->unsignedInteger('failed_attempts')->default(0);
            $table->unsignedInteger('block_level')->default(0);
            $table->timestamp('blocked_until')->nullable();
            $table->boolean('was_blocked')->default(false);
            $table->timestamp('last_failed_at')->nullable();
            $table->timestamp('last_success_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('login_security');
    }
};

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
        Schema::create('application_settings', function (Blueprint $table) {
            $table->id();
            $table->string('logo')->nullable();
            $table->string('name');
            $table->string('short_name');
            $table->string('slogan')->nullable();
            $table->string('address');
            $table->string('email')->unique();
            $table->string('phone_one');
            $table->string('phone_two')->nullable(true);
            $table->boolean('in_maintenance')->default(false);
            $table->integer('image_size')->default(2048);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('application_settings');
    }
};

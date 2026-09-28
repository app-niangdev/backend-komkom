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
        Schema::table('stores', function (Blueprint $table) {
            $table->boolean('use_company_logo')->default(true)->after('uses_measurements');
            $table->boolean('use_company_colors')->default(true)->after('use_company_logo');
            $table->string('primary_color', 7)->nullable()->after('use_company_colors');
            $table->string('secondary_color', 7)->nullable()->after('primary_color');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->dropColumn(['use_company_logo', 'use_company_colors', 'primary_color', 'secondary_color']);
        });
    }
};

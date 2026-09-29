<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Boutique qui suit ses produits par numéro de série (IMEI) : vrai par défaut,
     * les boutiques existantes gardent le même fonctionnement.
     */
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->boolean('uses_serial_numbers')
                ->default(true)
                ->after('uses_measurements');
        });
    }

    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->dropColumn('uses_serial_numbers');
        });
    }
};

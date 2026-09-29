<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Largeur du rouleau de l'imprimante ticket de la boutique : 80 mm (défaut) ou 58 mm. */
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->unsignedSmallInteger('ticket_width')->default(80)->after('uses_serial_numbers');
        });
    }

    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->dropColumn('ticket_width');
        });
    }
};

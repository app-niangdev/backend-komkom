<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vente dans une unité autre que l'unité de base (ex. un sac de 50 kg) :
 * on garde l'unité vendue et la quantité sortie du stock (en unité de base).
 * La quantité devient décimale pour les boutiques qui vendent au poids ou au volume.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sale_line_items', function (Blueprint $table) {
            $table->decimal('quantity', 12, 3)->change();
            $table->foreignId('unit_of_measure_id')->nullable()->after('product_id')
                ->constrained('unit_of_measures')->nullOnDelete();
            $table->string('unit_name')->nullable()->after('unit_of_measure_id');
            $table->decimal('base_quantity', 12, 3)->nullable()->after('quantity');
        });
    }

    public function down(): void
    {
        Schema::table('sale_line_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('unit_of_measure_id');
            $table->dropColumn(['unit_name', 'base_quantity']);
            $table->integer('quantity')->change();
        });
    }
};

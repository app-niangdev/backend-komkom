<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Traçabilité de la réception : date d'entrée réelle en stock et utilisateur qui l'a validée.
     */
    public function up(): void
    {
        Schema::table('supplies', function (Blueprint $table) {
            $table->timestamp('received_at')->nullable()->after('total_amount');
            $table->foreignId('received_by')->nullable()->after('received_at')->constrained('users')->nullOnDelete();
        });

        // Historique : la dernière mise à jour d'un approvisionnement reçu correspond à sa validation
        DB::table('supplies')
            ->where('status', 'received')
            ->whereNull('received_at')
            ->update(['received_at' => DB::raw('updated_at')]);
    }

    public function down(): void
    {
        Schema::table('supplies', function (Blueprint $table) {
            $table->dropConstrainedForeignId('received_by');
            $table->dropColumn('received_at');
        });
    }
};

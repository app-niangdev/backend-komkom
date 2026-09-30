<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Envoi automatique des factures et reçus au client sur WhatsApp (activé par l'administrateur). */
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->boolean('whatsapp_invoices_enabled')->default(false)->after('ticket_width');
        });
    }

    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->dropColumn('whatsapp_invoices_enabled');
        });
    }
};

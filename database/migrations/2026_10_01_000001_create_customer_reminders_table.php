<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Relances WhatsApp des clients débiteurs : une ligne par tentative d'envoi (réussie ou non). */
    public function up(): void
    {
        Schema::create('customer_reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->onDelete('cascade');
            $table->foreignId('store_id')->constrained('stores')->onDelete('cascade');
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->integer('amount');
            $table->unsignedSmallInteger('invoices_count');
            $table->string('phone', 20);
            $table->enum('status', ['sent', 'failed']);
            $table->string('error', 500)->nullable();
            $table->timestamps();

            $table->index(['customer_id', 'status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_reminders');
    }
};

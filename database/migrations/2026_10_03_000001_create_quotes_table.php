<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Devis : document commercial indépendant des ventes. Il ne touche ni au stock, ni aux
     * produits, ni aux factures ; ses lignes sont une copie (désignation, unité, prix) faite à la saisie.
     */
    public function up(): void
    {
        Schema::create('quotes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained('stores')->onDelete('cascade');
            $table->foreignId('customer_id')->constrained('customers');
            $table->foreignId('user_id')->constrained('users');
            // Devis d'origine quand celui-ci est une copie
            $table->foreignId('source_quote_id')->nullable()->constrained('quotes')->nullOnDelete();
            $table->string('quote_number', 30);
            $table->enum('status', ['draft', 'sent', 'accepted', 'refused'])->default('draft');
            $table->date('valid_until')->nullable();
            $table->text('notes')->nullable();
            $table->bigInteger('gross_amount')->default(0);
            $table->bigInteger('discount')->default(0);
            $table->bigInteger('total_amount')->default(0);
            $table->timestamp('sent_at')->nullable();
            // Date d'acceptation ou de refus
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['store_id', 'quote_number']);
            $table->index(['store_id', 'status', 'created_at']);
        });

        Schema::create('quote_line_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quote_id')->constrained('quotes')->onDelete('cascade');
            // Produit suggéré d'où vient la ligne (simple référence) ; null pour une ligne libre
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->foreignId('unit_of_measure_id')->nullable()->constrained('unit_of_measures')->nullOnDelete();
            $table->string('designation', 255);
            $table->string('unit_name', 50)->nullable();
            $table->decimal('quantity', 14, 3);
            $table->bigInteger('unit_price');
            $table->bigInteger('subtotal');
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quote_line_items');
        Schema::dropIfExists('quotes');
    }
};

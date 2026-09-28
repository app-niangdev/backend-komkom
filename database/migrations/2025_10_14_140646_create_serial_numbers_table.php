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
        Schema::create('serial_numbers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->onDelete('cascade');
            $table->foreignId('supply_line_item_id')->nullable()->constrained()->onDelete('set null');
            $table->foreignId('sale_line_item_id')->nullable()->constrained()->onDelete('set null');
            $table->string('serial_number');
            $table->boolean('is_sold')->default(false);
            $table->unique(['product_id', 'serial_number']);
            $table->softDeletes();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('serial_numbers');
    }
};

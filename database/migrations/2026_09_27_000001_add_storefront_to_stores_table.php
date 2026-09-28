<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Vitrine publique d'une boutique : activée par l'administrateur, accessible par son slug.
     */
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->boolean('storefront_enabled')->default(false)->after('secondary_color');
            $table->string('slug', 60)->nullable()->unique()->after('storefront_enabled');
            $table->timestamp('storefront_enabled_at')->nullable()->after('slug');
        });
    }

    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->dropUnique(['slug']);
            $table->dropColumn(['storefront_enabled', 'slug', 'storefront_enabled_at']);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('plan');
            $table->decimal('amount', 12, 2)->default(0);
            $table->string('currency', 3)->default('XOF');
            // Période couverte, bornes incluses
            $table->date('starts_at');
            $table->date('ends_at');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['store_id', 'starts_at', 'ends_at']);
        });

        // Les boutiques existantes reçoivent une période d'essai pour ne pas être bloquées
        // dès la mise en production de la fonctionnalité.
        $trialDays = (int) config('subscriptions.trial_days', 30);
        if ($trialDays > 0) {
            $today = now()->startOfDay();
            $rows = DB::table('stores')->whereNull('deleted_at')->pluck('id')->map(fn ($storeId) => [
                'store_id' => $storeId,
                'plan' => 'Essai',
                'amount' => 0,
                'currency' => config('subscriptions.default_currency', 'XOF'),
                'starts_at' => $today->toDateString(),
                'ends_at' => $today->copy()->addDays($trialDays - 1)->toDateString(),
                'notes' => 'Période d\'essai attribuée automatiquement à la mise en place des abonnements.',
                'created_at' => now(),
                'updated_at' => now(),
            ])->all();

            if ($rows) {
                DB::table('subscriptions')->insert($rows);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};

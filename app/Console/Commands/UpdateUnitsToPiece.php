<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class UpdateUnitsToPiece extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'units:set-to-piece';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Met à jour tous les noms d\'unités de mesure et les unités de base des produits à "piece"';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Mise à jour des tables en cours...');

        try {
            DB::transaction(function () {
                // Mise à jour de la table unit_of_measures
                $affectedUom = DB::table('unit_of_measures')->update(['name' => 'piece']);
                $this->line("Table unit_of_measures : {$affectedUom} lignes mises à jour.");

                // Mise à jour de la table products
                $affectedProducts = DB::table('products')->update(['base_unit' => 'piece']);
                $this->line("Table products : {$affectedProducts} lignes mises à jour.");
            });

            $this->info('Mise à jour terminée avec succès.');
            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error('Une erreur est survenue lors de la mise à jour : ' . $e->getMessage());
            return self::FAILURE;
        }
    }
}

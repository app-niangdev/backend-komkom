<?php

namespace App\Console\Commands;

use App\Models\Product;
use Illuminate\Console\Command;

class SyncSerialProductStock extends Command
{
    protected $signature = 'stock:sync-serial-products
                            {--dry-run : Affiche les corrections sans les appliquer}
                            {--store= : Limiter à une boutique (store_id)}';

    protected $description = 'Recalcule base_unit_quantity à partir des numéros de série non vendus (produits require_serial_number)';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $storeId = $this->option('store');

        $query = Product::query()
            ->where('require_serial_number', true);

        if ($storeId) {
            $query->where('store_id', $storeId);
        }

        $products = $query->get();
        $updated = 0;

        $this->info(sprintf(
            '%s — %d produit(s) avec numéro de série%s',
            $dryRun ? '[DRY-RUN]' : 'Synchronisation',
            $products->count(),
            $storeId ? " (boutique #{$storeId})" : ''
        ));

        foreach ($products as $product) {
            $unsoldCount = $product->countUnsoldSerials();
            $availableCount = $product->countAvailableSerials();
            $current = (float) $product->base_unit_quantity;

            if ((float) $current === (float) $unsoldCount) {
                continue;
            }

            $this->line(sprintf(
                '  Produit #%d "%s" : base_unit_quantity %s → %d (dispo libres: %d)',
                $product->id,
                $product->name,
                $current,
                $unsoldCount,
                $availableCount
            ));

            if (!$dryRun) {
                $product->syncStockFromSerialNumbers();
            }

            $updated++;
        }

        if ($updated === 0) {
            $this->info('Aucune correction nécessaire.');
        } else {
            $this->info($dryRun
                ? "{$updated} produit(s) seraient corrigés. Relancez sans --dry-run pour appliquer."
                : "{$updated} produit(s) corrigé(s).");
        }

        return self::SUCCESS;
    }
}

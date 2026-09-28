<?php

namespace Database\Seeders;

use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleLineItem;
use App\Models\Seller;
use App\Models\Store;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class SaleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        DB::transaction(function () {
            // Récupérer un magasin existant (le premier)
            $store = Store::first();

            if (!$store) {
                $this->command->warn('Aucun magasin trouvé. Veuillez créer un magasin avant de lancer ce seeder.');
                return;
            }

            // Récupérer un vendeur du magasin
            $seller = Seller::where('store_id', $store->id)->first();

            if (!$seller) {
                // Si aucun vendeur n'existe, créer un vendeur à partir du premier utilisateur
                $user = User::first();

                if (!$user) {
                    $this->command->warn('Aucun utilisateur trouvé. Veuillez créer un utilisateur avant de lancer ce seeder.');
                    return;
                }

                $seller = Seller::create([
                    'user_id' => $user->id,
                    'store_id' => $store->id,
                ]);

                $this->command->info('Vendeur créé avec succès.');
            }

            // Récupérer ou créer un client pour ce magasin
            $customer = Customer::where('store_id', $store->id)->first();

            if (!$customer) {
                $customer = Customer::create([
                    'name' => 'Client Test',
                    'email' => 'client.test@example.com',
                    'phone' => '771234567',
                    'address' => 'Dakar, Sénégal',
                    'store_id' => $store->id,
                ]);
                $this->command->info('Client de test créé avec succès.');
            }

            // Récupérer 2-3 produits du magasin avec du stock
            $products = Product::where('store_id', $store->id)
                ->where('base_unit_quantity', '>', 0)
                ->take(3)
                ->get();

            if ($products->isEmpty()) {
                $this->command->warn('Aucun produit avec stock trouvé pour ce magasin. Veuillez créer des produits avant de lancer ce seeder.');
                return;
            }

            // Générer le numéro de vente
            $prefix = 'VNT';
            $date = now()->format('Ymd');
            $count = Sale::where('store_id', $store->id)
                ->whereDate('created_at', now()->toDateString())
                ->count();
            $sequence = str_pad($count + 1, 4, '0', STR_PAD_LEFT);
            $saleNumber = "{$prefix}-{$date}-{$store->id}-{$sequence}";

            // Créer une vente en statut pending
            $sale = Sale::create([
                'store_id' => $store->id,
                'seller_id' => $seller->id, // C'est l'ID du seller (table sellers)
                'sale_number' => $saleNumber,
                'gross_amount' => 0,
                'discount' => 0,
                'total_amount' => 0,
                'status' => 'pending',
                'customer_id' => $customer->id,
                'status_payment' => 'no_paid',
            ]);

            $this->command->info("Vente créée: {$sale->sale_number}");

            $totalAmount = 0;
            $grossAmount = 0;

            // Créer les lignes de vente
            foreach ($products as $product) {
                // Déterminer la quantité aléatoire (entre 1 et min(5, stock disponible))
                $maxQty = min(5, $product->base_unit_quantity);
                $quantity = rand(1, $maxQty);

                // Récupérer le prix de vente (prix de l'unité de base)
                $baseUnit = $product->unitOfMeasures()->where('is_base_unit', true)->first();

                if (!$baseUnit) {
                    $this->command->warn("Produit {$product->name} n'a pas d'unité de base. Ignoré.");
                    continue;
                }

                $unitPriceAtSale = $baseUnit->price;
                $lineAmount = $quantity * $unitPriceAtSale;

                $totalAmount += $lineAmount;
                $grossAmount += $lineAmount;

                SaleLineItem::create([
                    'sale_id' => $sale->id,
                    'product_id' => $product->id,
                    'quantity' => $quantity,
                    'unit_price' => $unitPriceAtSale,
                    'subtotal' => $lineAmount,
                ]);

                $this->command->info("  - {$product->name}: {$quantity} x {$unitPriceAtSale} = {$lineAmount}");
            }

            // Appliquer un rabais de 10% (optionnel)
            $discountAmount = (int) ($grossAmount * 0.10);
            $totalAmount = $grossAmount - $discountAmount;

            // Mettre à jour la vente avec les montants
            $sale->update([
                'gross_amount' => $grossAmount,
                'total_amount' => $totalAmount,
                'discount' => $discountAmount,
            ]);

            $this->command->info("\nRésumé de la vente:");
            $this->command->info("  Montant brut: {$grossAmount}");
            $this->command->info("  Remise: {$discountAmount}");
            $this->command->info("  Montant total: {$totalAmount}");
            $this->command->info("  Statut: {$sale->status}");
            $this->command->info("\nLa vente est en statut 'pending'. Utilisez l'endpoint /sale/validate/{id} pour la valider.");
        });
    }
}

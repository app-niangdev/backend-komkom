<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Product;
use App\Models\Store;
use App\Services\FileValidationService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class TesteproductSeeder extends Seeder
{
    /**
     * Liste de 25 produits de test avec un lien d'image public.
     */
    private const PRODUCTS = [
        ['name' => 'Casque Bluetooth Pro', 'price' => 25000, 'image' => 'https://picsum.photos/seed/product1/600/600'],
        ['name' => 'Smartphone Galaxy X', 'price' => 250000, 'image' => 'https://picsum.photos/seed/product2/600/600'],
        ['name' => 'Montre connectée Sport', 'price' => 45000, 'image' => 'https://picsum.photos/seed/product3/600/600'],
        ['name' => 'Enceinte Bluetooth JBL', 'price' => 30000, 'image' => 'https://picsum.photos/seed/product4/600/600'],
        ['name' => 'Ordinateur portable 15"', 'price' => 450000, 'image' => 'https://picsum.photos/seed/product5/600/600'],
        ['name' => 'Chargeur rapide USB-C', 'price' => 8000, 'image' => 'https://picsum.photos/seed/product6/600/600'],
        ['name' => 'Tee-shirt coton bio', 'price' => 6000, 'image' => 'https://picsum.photos/seed/product7/600/600'],
        ['name' => 'Sac à dos voyage', 'price' => 20000, 'image' => 'https://picsum.photos/seed/product8/600/600'],
        ['name' => 'Chaussures de sport', 'price' => 35000, 'image' => 'https://picsum.photos/seed/product9/600/600'],
        ['name' => 'Casquette logo', 'price' => 5000, 'image' => 'https://picsum.photos/seed/product10/600/600'],
        ['name' => 'Bouteille isotherme', 'price' => 7000, 'image' => 'https://picsum.photos/seed/product11/600/600'],
        ['name' => 'Lampe de bureau LED', 'price' => 12000, 'image' => 'https://picsum.photos/seed/product12/600/600'],
        ['name' => 'Clavier mécanique', 'price' => 28000, 'image' => 'https://picsum.photos/seed/product13/600/600'],
        ['name' => 'Souris sans fil', 'price' => 9000, 'image' => 'https://picsum.photos/seed/product14/600/600'],
        ['name' => 'Tapis de yoga', 'price' => 15000, 'image' => 'https://picsum.photos/seed/product15/600/600'],
        ['name' => 'Cafetière électrique', 'price' => 22000, 'image' => 'https://picsum.photos/seed/product16/600/600'],
        ['name' => 'Mixeur plongeant', 'price' => 18000, 'image' => 'https://picsum.photos/seed/product17/600/600'],
        ['name' => 'Ventilateur de bureau', 'price' => 14000, 'image' => 'https://picsum.photos/seed/product18/600/600'],
        ['name' => 'Chaise de bureau ergonomique', 'price' => 65000, 'image' => 'https://picsum.photos/seed/product19/600/600'],
        ['name' => 'Table basse bois', 'price' => 55000, 'image' => 'https://picsum.photos/seed/product20/600/600'],
        ['name' => 'Batterie externe 20000mAh', 'price' => 13000, 'image' => 'https://picsum.photos/seed/product21/600/600'],
        ['name' => 'Casque antibruit', 'price' => 40000, 'image' => 'https://picsum.photos/seed/product22/600/600'],
        ['name' => 'Support téléphone voiture', 'price' => 4000, 'image' => 'https://picsum.photos/seed/product23/600/600'],
        ['name' => 'Parapluie automatique', 'price' => 6000, 'image' => 'https://picsum.photos/seed/product24/600/600'],
        ['name' => 'Sac à main cuir', 'price' => 32000, 'image' => 'https://picsum.photos/seed/product25/600/600'],
    ];

    public function run(): void
    {
        $store = Store::first();

        if (!$store) {
            $this->command->warn('Aucun magasin trouvé. Veuillez créer un magasin avant de lancer ce seeder.');
            return;
        }

        $category = Category::firstOrCreate(
            ['store_id' => $store->id, 'name' => 'Test Produits'],
            ['description' => 'Catégorie générée par TesteproductSeeder']
        );

        $usesMeasurements = $store->uses_measurements ?? true;
        $fileValidator = app(FileValidationService::class);

        foreach (self::PRODUCTS as $data) {
            DB::transaction(function () use ($data, $store, $category, $usesMeasurements, $fileValidator) {
                $stockQuantity = rand(2, 100);
                $baseUnit = $usesMeasurements ? 'piece' : Product::DEFAULT_UNIT_WITHOUT_MEASUREMENTS;

                $product = Product::create([
                    'store_id' => $store->id,
                    'category_id' => $category->id,
                    'name' => $data['name'],
                    'description' => "{$data['name']} - produit de test",
                    'require_serial_number' => false,
                    'alert_threshold' => 5,
                    'base_unit' => $baseUnit,
                    'base_unit_quantity' => $stockQuantity,
                ]);

                $product->unitOfMeasures()->create([
                    'name' => $baseUnit,
                    'price' => $data['price'],
                    'conversion_factor' => 1,
                    'is_base_unit' => true,
                ]);

                try {
                    $fileValidator->validateAndStoreFileFromUrl($data['image'], $product, 'image');
                } catch (\Throwable $e) {
                    $this->command->warn("Image non téléchargée pour {$data['name']}: {$e->getMessage()}");
                }

                $this->command->info("Produit créé: {$product->name} (stock: {$stockQuantity})");
            });
        }

        $this->command->info('25 produits de test ont été créés avec succès.');
    }
}

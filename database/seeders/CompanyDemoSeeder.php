<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Company;
use App\Models\Manager;
use App\Models\Owner;
use App\Models\Product;
use App\Models\Role;
use App\Models\Seller;
use App\Models\Store;
use App\Models\User;
use App\Services\FileValidationService;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class CompanyDemoSeeder extends Seeder
{
    /**
     * 5 catégories, chacune avec 5 produits (25 produits par magasin).
     */
    private const CATEGORIES = [
        'Électronique' => [
            ['name' => 'Casque Bluetooth Pro', 'price' => 25000],
            ['name' => 'Smartphone Galaxy X', 'price' => 250000],
            ['name' => 'Montre connectée Sport', 'price' => 45000],
            ['name' => 'Enceinte Bluetooth JBL', 'price' => 30000],
            ['name' => 'Batterie externe 20000mAh', 'price' => 13000],
        ],
        'Informatique' => [
            ['name' => 'Ordinateur portable 15"', 'price' => 450000],
            ['name' => 'Chargeur rapide USB-C', 'price' => 8000],
            ['name' => 'Clavier mécanique', 'price' => 28000],
            ['name' => 'Souris sans fil', 'price' => 9000],
            ['name' => 'Casque antibruit', 'price' => 40000],
        ],
        'Mode & Accessoires' => [
            ['name' => 'Tee-shirt coton bio', 'price' => 6000],
            ['name' => 'Sac à dos voyage', 'price' => 20000],
            ['name' => 'Chaussures de sport', 'price' => 35000],
            ['name' => 'Casquette logo', 'price' => 5000],
            ['name' => 'Sac à main cuir', 'price' => 32000],
        ],
        'Maison & Cuisine' => [
            ['name' => 'Bouteille isotherme', 'price' => 7000],
            ['name' => 'Lampe de bureau LED', 'price' => 12000],
            ['name' => 'Cafetière électrique', 'price' => 22000],
            ['name' => 'Mixeur plongeant', 'price' => 18000],
            ['name' => 'Ventilateur de bureau', 'price' => 14000],
        ],
        'Mobilier & Divers' => [
            ['name' => 'Tapis de yoga', 'price' => 15000],
            ['name' => 'Chaise de bureau ergonomique', 'price' => 65000],
            ['name' => 'Table basse bois', 'price' => 55000],
            ['name' => 'Support téléphone voiture', 'price' => 4000],
            ['name' => 'Parapluie automatique', 'price' => 6000],
        ],
    ];

    public function run(): void
    {
        DB::transaction(function () {
            $ownerUser = $this->firstOrCreateOwnerUser();
            $owner = Owner::firstOrCreate(['user_id' => $ownerUser->id]);

            $company = Company::firstOrCreate(
                ['email' => 'contact@gstock-demo.com'],
                [
                    'name' => 'GStock Demo',
                    'short_name' => 'GStock',
                    'slogan' => 'La gestion de stock simplifiée',
                    'head_office_address' => 'Dakar, Sénégal',
                    'phone_one' => '771000000',
                    'phone_two' => '331000000',
                    'owner_id' => $owner->id,
                ]
            );

            $this->command->info("Entreprise: {$company->name}");

            $stores = [
                ['name' => 'Magasin Centre-Ville', 'address' => 'Plateau, Dakar', 'phone_one' => '771111111', 'email' => 'centre-ville@gstock-demo.com'],
                ['name' => 'Magasin Almadies', 'address' => 'Almadies, Dakar', 'phone_one' => '772222222', 'email' => 'almadies@gstock-demo.com'],
            ];

            $fileValidator = app(FileValidationService::class);
            $imageSeed = 1;

            foreach ($stores as $index => $storeData) {
                $store = Store::firstOrCreate(
                    ['company_id' => $company->id, 'name' => $storeData['name']],
                    [
                        'address' => $storeData['address'],
                        'phone_one' => $storeData['phone_one'],
                        'email' => $storeData['email'],
                        'active' => true,
                        'uses_measurements' => true,
                    ]
                );

                $this->command->info("Magasin créé: {$store->name}");

                $this->createStaff($store, $index + 1, 'Seller');
                $this->createStaff($store, $index + 1, 'Manager');
                $imageSeed = $this->createCategoriesWithProducts($store, $fileValidator, $imageSeed);
            }
        });

        $this->command->info('Seed terminé: 1 entreprise, 2 magasins, 5 catégories et 25 produits par magasin, 1 gérant et 1 vendeur par magasin.');
    }

    private function firstOrCreateOwnerUser(): User
    {
        $existing = User::where('email', 'owner@gstock-demo.com')->first();
        if ($existing) {
            return $existing;
        }

        $ownerRole = Role::firstOrCreate(['name' => 'Owner']);

        return User::create([
            'first_name' => 'Awa',
            'last_name' => 'DIOP',
            'email' => 'owner@gstock-demo.com',
            'status' => true,
            'role_id' => $ownerRole->id,
            'email_verified_at' => Carbon::now(),
            'type' => 'owner',
            'password' => Hash::make('password'),
            'phone_number_one' => '770000000',
            'address' => 'Dakar',
            'gender' => 'female',
            'active' => true,
        ]);
    }

    /** Gérant (Manager) ou vendeur (Seller) rattaché à une boutique. */
    private function createStaff(Store $store, int $storeIndex, string $roleName): void
    {
        $isManager = $roleName === 'Manager';
        $label = $isManager ? 'Gerant' : 'Vendeur';
        $email = strtolower($isManager ? 'manager' : 'vendeur') . "{$storeIndex}@gstock-demo.com";
        $user = User::where('email', $email)->first();

        if (!$user) {
            $role = Role::firstOrCreate(['name' => $roleName]);

            $user = User::create([
                'first_name' => "{$label}{$storeIndex}",
                'last_name' => $store->name,
                'email' => $email,
                'status' => true,
                'role_id' => $role->id,
                'email_verified_at' => Carbon::now(),
                'type' => strtolower($roleName),
                'password' => Hash::make('password'),
                'phone_number_one' => ($isManager ? '76400000' : '76300000') . $storeIndex,
                'address' => $store->address,
                'gender' => $storeIndex % 2 === 0 ? 'female' : 'male',
                'active' => true,
                'store_id' => $store->id,
                'company_id' => $store->company_id,
            ]);
        }

        ($isManager ? Manager::class : Seller::class)::firstOrCreate([
            'user_id' => $user->id,
            'store_id' => $store->id,
        ]);

        $this->command->info("  {$label} créé: {$user->email}");
    }

    private function createCategoriesWithProducts(Store $store, FileValidationService $fileValidator, int $imageSeed): int
    {
        $usesMeasurements = $store->uses_measurements ?? true;
        $baseUnit = $usesMeasurements ? 'piece' : Product::DEFAULT_UNIT_WITHOUT_MEASUREMENTS;

        foreach (self::CATEGORIES as $categoryName => $products) {
            $category = Category::firstOrCreate(
                ['store_id' => $store->id, 'name' => $categoryName],
                ['description' => "Catégorie {$categoryName} générée par CompanyDemoSeeder"]
            );

            foreach ($products as $data) {
                $product = Product::where('store_id', $store->id)
                    ->where('category_id', $category->id)
                    ->where('name', $data['name'])
                    ->first();

                if ($product) {
                    $imageSeed++;
                    continue;
                }

                $stockQuantity = rand(2, 100);

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

                $imageUrl = "https://picsum.photos/seed/gstock{$imageSeed}/600/600";

                try {
                    $fileValidator->validateAndStoreFileFromUrl($imageUrl, $product, 'image');
                } catch (\Throwable $e) {
                    $this->command->warn("Image non téléchargée pour {$data['name']}: {$e->getMessage()}");
                }

                $imageSeed++;
            }

            $this->command->info("  Catégorie '{$categoryName}': 5 produits créés");
        }

        return $imageSeed;
    }
}

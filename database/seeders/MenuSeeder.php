<?php

namespace Database\Seeders;

use App\Models\Menu;
use App\Models\Role;
use Illuminate\Database\Seeder;

class MenuSeeder extends Seeder
{
    /**
     * Menus issus de l'ancien frontend (navigation-loader.service.ts), URLs adaptées à la refonte Angular.
     *
     * @var array<int, array{code: string, title: string, url: string, icon: string, position: int, roles: list<string>}>
     */
    private array $definitions = [
        // Admin
        ['code' => 'admin_dashboard', 'title' => 'Statistiques', 'url' => '/admin/dashboard', 'icon' => 'bi-graph-up', 'position' => 10, 'roles' => ['Admin']],
        ['code' => 'admin_companies', 'title' => 'Entreprises', 'url' => '/admin/companies', 'icon' => 'bi-building', 'position' => 20, 'roles' => ['Admin']],
        ['code' => 'admin_subscriptions', 'title' => 'Abonnements', 'url' => '/admin/subscriptions', 'icon' => 'bi-credit-card-2-front', 'position' => 30, 'roles' => ['Admin']],
        ['code' => 'admin_users', 'title' => 'Utilisateurs', 'url' => '/admin/users', 'icon' => 'bi-people', 'position' => 40, 'roles' => ['Admin']],

        // Owner : les données sont filtrées par le sélecteur de boutique (barre du haut)
        ['code' => 'owner_dashboard', 'title' => 'Tableau de bord', 'url' => '/owner/dashboard', 'icon' => 'bi-speedometer2', 'position' => 10, 'roles' => ['Owner']],
        ['code' => 'owner_stores', 'title' => 'Mes boutiques', 'url' => '/owner/stores', 'icon' => 'bi-shop', 'position' => 20, 'roles' => ['Owner']],
        ['code' => 'owner_sales', 'title' => 'Ventes', 'url' => '/owner/sales', 'icon' => 'bi-cart-check', 'position' => 30, 'roles' => ['Owner']],
        ['code' => 'owner_payments', 'title' => 'Encaissements', 'url' => '/owner/payments', 'icon' => 'bi-wallet2', 'position' => 35, 'roles' => ['Owner']],
        ['code' => 'owner_sales_add', 'title' => 'Nouvelle vente', 'url' => '/owner/sales/new', 'icon' => 'bi-plus-circle', 'position' => 25, 'roles' => ['Owner']],
        ['code' => 'owner_invoices', 'title' => 'Factures & reçus', 'url' => '/owner/invoices', 'icon' => 'bi-receipt-cutoff', 'position' => 33, 'roles' => ['Owner']],
        ['code' => 'owner_quotes', 'title' => 'Devis', 'url' => '/owner/quotes', 'icon' => 'bi-file-earmark-text', 'position' => 34, 'roles' => ['Owner']],
        ['code' => 'owner_products','title' => 'Produits & stock', 'url' => '/owner/products', 'icon' => 'bi-box-seam', 'position' => 40, 'roles' => ['Owner']],
        ['code' => 'owner_imei', 'title' => 'IMEI / N° de série', 'url' => '/owner/serials', 'icon' => 'bi-upc-scan', 'position' => 42, 'roles' => ['Owner']],
        ['code' => 'owner_categories', 'title' => 'Catégories', 'url' => '/owner/categories', 'icon' => 'bi-tags', 'position' => 45, 'roles' => ['Owner']],
        ['code' => 'owner_customers', 'title' => 'Clients', 'url' => '/owner/customers', 'icon' => 'bi-person-lines-fill', 'position' => 50, 'roles' => ['Owner']],
        ['code' => 'owner_expenses', 'title' => 'Dépenses', 'url' => '/owner/expenses', 'icon' => 'bi-receipt', 'position' => 60, 'roles' => ['Owner']],
        ['code' => 'owner_procurements', 'title' => 'Approvisionnements', 'url' => '/owner/procurements', 'icon' => 'bi-truck', 'position' => 70, 'roles' => ['Owner']],
        ['code' => 'owner_procurement_scanner', 'title' => 'Approvisionner par scanner', 'url' => '/owner/procurements/scan', 'icon' => 'bi-upc-scan', 'position' => 72, 'roles' => ['Owner']],
        ['code' => 'owner_suppliers', 'title' => 'Fournisseurs', 'url' => '/owner/suppliers', 'icon' => 'bi-person-vcard', 'position' => 75, 'roles' => ['Owner']],
        ['code' => 'owner_company', 'title' => 'Mon entreprise', 'url' => '/owner/company', 'icon' => 'bi-building', 'position' => 80, 'roles' => ['Owner']],
        ['code' => 'owner_users', 'title' => 'Utilisateurs', 'url' => '/owner/users', 'icon' => 'bi-people', 'position' => 90, 'roles' => ['Owner']],

        // Manager : mêmes écrans que le propriétaire (/manager/*), limités à sa boutique
        ['code' => 'manager_home', 'title' => 'Tableau de bord', 'url' => '/manager/dashboard', 'icon' => 'bi-speedometer2', 'position' => 10, 'roles' => ['Manager']],
        ['code' => 'manager_sales_list', 'title' => 'Ventes', 'url' => '/manager/sales', 'icon' => 'bi-cart-check', 'position' => 20, 'roles' => ['Manager']],
        ['code' => 'manager_sales_add', 'title' => 'Nouvelle vente', 'url' => '/manager/sales/new', 'icon' => 'bi-plus-circle', 'position' => 25, 'roles' => ['Manager']],
        ['code' => 'manager_products', 'title' => 'Produits & stock', 'url' => '/manager/products', 'icon' => 'bi-box-seam', 'position' => 30, 'roles' => ['Manager']],
        ['code' => 'manager_categories', 'title' => 'Catégories', 'url' => '/manager/categories', 'icon' => 'bi-tags', 'position' => 35, 'roles' => ['Manager']],
        ['code' => 'manager_customers', 'title' => 'Clients', 'url' => '/manager/customers', 'icon' => 'bi-person-lines-fill', 'position' => 40, 'roles' => ['Manager']],
        ['code' => 'manager_invoices', 'title' => 'Factures & reçus', 'url' => '/manager/invoices', 'icon' => 'bi-receipt-cutoff', 'position' => 45, 'roles' => ['Manager']],
        ['code' => 'manager_quotes', 'title' => 'Devis', 'url' => '/manager/quotes', 'icon' => 'bi-file-earmark-text', 'position' => 47, 'roles' => ['Manager']],
        ['code' => 'manager_payments','title' => 'Paiements', 'url' => '/manager/payments', 'icon' => 'bi-wallet2', 'position' => 50, 'roles' => ['Manager']],
        ['code' => 'manager_expenses', 'title' => 'Dépenses', 'url' => '/manager/expenses', 'icon' => 'bi-receipt', 'position' => 60, 'roles' => ['Manager']],
        ['code' => 'manager_procurement', 'title' => 'Approvisionnements', 'url' => '/manager/procurements', 'icon' => 'bi-truck', 'position' => 70, 'roles' => ['Manager']],
        ['code' => 'manager_procurement_scanner', 'title' => 'Approvisionner par scanner', 'url' => '/manager/procurements/scan', 'icon' => 'bi-upc-scan', 'position' => 72, 'roles' => ['Manager']],
        ['code' => 'manager_suppliers', 'title' => 'Fournisseurs', 'url' => '/manager/suppliers', 'icon' => 'bi-person-vcard', 'position' => 75, 'roles' => ['Manager']],
        ['code' => 'manager_users', 'title' => 'Utilisateurs', 'url' => '/manager/users', 'icon' => 'bi-people', 'position' => 90, 'roles' => ['Manager']],
        ['code' => 'manager_imei', 'title' => 'IMEI / N° de série', 'url' => '/manager/serials', 'icon' => 'bi-upc-scan', 'position' => 32, 'roles' => ['Manager']],

        // Seller : écrans partagés sous /seller, limités à ses ventes (catalogue en consultation)
        ['code' => 'seller_home', 'title' => 'Mon activité', 'url' => '/seller/dashboard', 'icon' => 'bi-speedometer2', 'position' => 10, 'roles' => ['Seller']],
        ['code' => 'seller_sales_add', 'title' => 'Nouvelle vente', 'url' => '/seller/sales/new', 'icon' => 'bi-plus-circle', 'position' => 20, 'roles' => ['Seller']],
        ['code' => 'seller_sales_list', 'title' => 'Mes ventes', 'url' => '/seller/sales', 'icon' => 'bi-cart-check', 'position' => 25, 'roles' => ['Seller']],
        ['code' => 'seller_invoices', 'title' => 'Factures & reçus', 'url' => '/seller/invoices', 'icon' => 'bi-receipt-cutoff', 'position' => 30, 'roles' => ['Seller']],
        ['code' => 'seller_payments', 'title' => 'Mes encaissements', 'url' => '/seller/payments', 'icon' => 'bi-wallet2', 'position' => 35, 'roles' => ['Seller']],
        ['code' => 'seller_customers', 'title' => 'Clients', 'url' => '/seller/customers', 'icon' => 'bi-person-lines-fill', 'position' => 40, 'roles' => ['Seller']],
        ['code' => 'seller_products', 'title' => 'Produits', 'url' => '/seller/products', 'icon' => 'bi-box-seam', 'position' => 50, 'roles' => ['Seller']],
        ['code' => 'seller_categories', 'title' => 'Catégories', 'url' => '/seller/categories', 'icon' => 'bi-tags', 'position' => 55, 'roles' => ['Seller']],
    ];

    public function run(): void
    {
        $rolesByName = Role::query()->pluck('id', 'name');

        foreach ($this->definitions as $definition) {
            $menu = Menu::updateOrCreate(
                ['code' => $definition['code']],
                [
                    'title' => $definition['title'],
                    'type' => 'link',
                    'classes' => null,
                    'url' => $definition['url'],
                    'icon' => $definition['icon'],
                    'breadcrumbs' => true,
                    'position' => $definition['position'],
                    'is_active' => true,
                ]
            );

            $roleIds = collect($definition['roles'])
                ->map(fn (string $name) => $rolesByName[$name] ?? null)
                ->filter()
                ->values()
                ->all();

            $menu->roles()->syncWithoutDetaching($roleIds);
        }
    }
}

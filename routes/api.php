<?php

use App\Http\Controllers\AdminStatsController;
use App\Http\Controllers\Admin\AdminStorefrontController;
use App\Http\Controllers\Storefront\StorefrontController;
use App\Http\Controllers\ApplicationSettingController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\ChangePasswordController;
use App\Http\Controllers\CompanyOwnerController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\EmailVerifyController;
use App\Http\Controllers\ExpenseController;
use App\Http\Controllers\ForgotPasswordController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\MenuController;
use App\Http\Controllers\Owner\OwnerCatalogController;
use App\Http\Controllers\Owner\OwnerCategoryController;
use App\Http\Controllers\Owner\OwnerCompanyController;
use App\Http\Controllers\Owner\OwnerCustomerController;
use App\Http\Controllers\Owner\OwnerDashboardController;
use App\Http\Controllers\Owner\OwnerExpenseController;
use App\Http\Controllers\Owner\OwnerInvoiceController;
use App\Http\Controllers\Owner\OwnerPaymentController;
use App\Http\Controllers\Owner\OwnerPosController;
use App\Http\Controllers\Owner\OwnerProductController;
use App\Http\Controllers\Owner\OwnerSerialController;
use App\Http\Controllers\Owner\OwnerSaleController;
use App\Http\Controllers\Owner\OwnerSupplierController;
use App\Http\Controllers\Owner\OwnerSupplyController;
use App\Http\Controllers\Owner\OwnerTeamController;
use App\Http\Controllers\PaymentReceiptController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ReportingController;
use App\Http\Controllers\ResetPasswordController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\SaleController;
use App\Http\Controllers\SerialNumberController;
use App\Http\Controllers\StoreController;
use App\Http\Controllers\SubscriptionController;
use App\Http\Controllers\SupplierProductController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\SupplyController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;


// Routes avec rate limiting pour la connexion / le refresh
Route::middleware(['ip.blocked', 'throttle:20,1'])->group(function () {
    Route::post('/login', [AuthController::class, 'login']);
});

Route::middleware(['throttle:20,1'])->group(function () {
    Route::post('/refresh', [AuthController::class, 'refresh']);
});

// Vérification du code de connexion (OTP après déblocage d'IP)
Route::middleware(['throttle:10,1'])->group(function () {
    Route::post('/login/verify-otp', [AuthController::class, 'verifyLoginOtp']);
});
Route::middleware(['throttle:5,10'])->group(function () {
    Route::post('/login/resend-otp', [AuthController::class, 'resendLoginOtp']);
});

Route::post('/logout', [AuthController::class, 'logout']);

// Vitrine publique d'une boutique (sans authentification), identifiée par son slug
Route::prefix('public/storefront/{slug}')
    ->where(['slug' => '[a-z0-9-]{3,60}'])
    ->middleware('throttle:storefront')
    ->group(function () {
        Route::get('/', [StorefrontController::class, 'show']);
        Route::get('/categories', [StorefrontController::class, 'categories']);
        Route::get('/products', [StorefrontController::class, 'products']);
        Route::get('/products/{id}', [StorefrontController::class, 'product'])->whereNumber('id');
    });

// Routes publiques sans rate limiting spécifique
Route::get('/app-setting', [ApplicationSettingController::class, 'index']);
Route::get('/product/available/{storeId}', [ProductController::class, 'getProductAvailableByStore']);
Route::get('/store/info/{id}', [StoreController::class, 'getStoreInfo']);

// Routes de réinitialisation de mot de passe avec rate limiting standard
Route::middleware(['throttle:5,1'])->group(function () {
    Route::post('/forgot-password', [ForgotPasswordController::class, 'sendResetLinkEmail']);
    Route::post('reset-password', [ResetPasswordController::class, 'reset']);
});

// Routes de vérification d'email
Route::get('/email/verify/{id}/{hash}', [EmailVerifyController::class, 'verify'])->name('verification.verify');
Route::post('/email/resend', [EmailVerifyController::class, 'resend'])->name('verification.resend');

Route::get('reset-password/{token}', function (Request $request, string $token) {
    return redirect(\App\Notifications\ResetPasswordNotification::resetUrl($token, $request->query('email')));
})->name('password.reset');

// Route pour envoyer l'email de vérification
Route::middleware('auth:jwt')->post('/email/verification-notification', function (Request $request) {
    $request->user()->sendEmailVerificationNotification();
    return response()->json(['message' => 'Verification link sent!']);
})->name('verification.send');


Route::middleware(['auth:jwt', 'subscription'])->group(function () {
    Route::get('/authenticate', [AuthController::class, 'authenticate']);
    Route::get('/menus', [MenuController::class, 'index']);
    Route::put('/update-app-setting/{id}', [ApplicationSettingController::class, 'update']);
    Route::get('/roles', [RoleController::class, 'index']);
    Route::put('/change-password/{id}', [ChangePasswordController::class, 'changePassword']);

    Route::prefix('profile')->group(function () {
        Route::get('/', [ProfileController::class, 'show']);
        Route::put('/contacts', [ProfileController::class, 'updateContacts']);
        Route::put('/password', [ProfileController::class, 'updatePassword'])->middleware('throttle:5,1');
    });

    Route::prefix('user')->group(function () {
        Route::get('/list', [UserController::class, 'index']);
        // Écritures sans contrôle de périmètre : réservées à l'administrateur
        // (le propriétaire gère son équipe via /owner/team)
        Route::middleware('role:Admin')->group(function () {
            Route::post('/add', [UserController::class, 'store']);
            Route::get('/disable/{id}', [UserController::class, 'disable']);
            Route::put('/update/{id}', [UserController::class, 'update']);
            Route::delete('/delete/{id}', [UserController::class, 'destroy']);
        });
    });

    Route::prefix('company')->group(function () {
        Route::middleware('role:Admin')->group(function () {
            Route::get('/list', [CompanyOwnerController::class, 'index']);
            Route::get('/show/{id}', [CompanyOwnerController::class, 'show']);
            Route::post('/add', [CompanyOwnerController::class, 'store']);
            Route::put('/update/{id}', [CompanyOwnerController::class, 'update']);
            Route::delete('/delete/{id}', [CompanyOwnerController::class, 'destroy']);
        });
        Route::put('/update-info-company/{id}', [CompanyOwnerController::class, 'updateCompany']);
    });

    Route::get('/admin/stats', [AdminStatsController::class, 'index'])->middleware('role:Admin');

    // Vitrines publiques : activation et lien, réservés à l'administrateur
    Route::prefix('admin/storefronts')->middleware('role:Admin')->group(function () {
        Route::get('/', [AdminStorefrontController::class, 'index']);
        Route::get('/{storeId}/suggest', [AdminStorefrontController::class, 'suggest'])->whereNumber('storeId');
        Route::put('/{storeId}', [AdminStorefrontController::class, 'update'])->whereNumber('storeId');
    });

    // Modules de gestion d'une boutique, partagés par le propriétaire (toutes ses boutiques,
    // via le sélecteur) et le gérant (sa seule boutique) : le périmètre vient d'OwnerScopeService
    $storeModules = function () {
        Route::get('/store-options', [OwnerDashboardController::class, 'storeOptions']);
        Route::get('/dashboard', [OwnerDashboardController::class, 'dashboard']);
        Route::get('/sales', [OwnerSaleController::class, 'index']);
        Route::get('/sales/export', [OwnerSaleController::class, 'export']);
        Route::get('/sales/export-pdf', [OwnerSaleController::class, 'exportPdf']);
        Route::get('/sales/{id}', [OwnerSaleController::class, 'show']);
        Route::post('/sales', [OwnerPosController::class, 'checkout'])->middleware('throttle:60,1');
        Route::get('/pos/products', [OwnerPosController::class, 'products']);
        Route::get('/pos/products/{id}/serials', [OwnerPosController::class, 'serials'])->whereNumber('id');
        Route::get('/pos/customers', [OwnerPosController::class, 'customers']);
        Route::get('/invoices', [OwnerInvoiceController::class, 'index']);
        Route::get('/payments', [OwnerPaymentController::class, 'index']);
        Route::get('/payments/export', [OwnerPaymentController::class, 'export']);
        Route::get('/payments/export-pdf', [OwnerPaymentController::class, 'exportPdf']);
        Route::get('/invoices/{id}', [OwnerInvoiceController::class, 'show'])->whereNumber('id');
        Route::post('/invoices/{id}/payments', [OwnerInvoiceController::class, 'pay'])->whereNumber('id');
        Route::post('/invoices/{id}/whatsapp', [OwnerInvoiceController::class, 'whatsapp'])->whereNumber('id')->middleware('throttle:20,1');
        Route::get('/products', [OwnerCatalogController::class, 'products']);
        Route::post('/products', [OwnerProductController::class, 'store']);
        Route::get('/products/{id}', [OwnerProductController::class, 'show'])->whereNumber('id');
        Route::post('/products/{id}', [OwnerProductController::class, 'update'])->whereNumber('id');
        Route::delete('/products/{id}', [OwnerProductController::class, 'destroy'])->whereNumber('id');
        Route::get('/categories/overview', [OwnerCategoryController::class, 'index']);
        Route::post('/categories', [OwnerCategoryController::class, 'store']);
        Route::post('/categories/quick', [OwnerProductController::class, 'storeCategory']);
        Route::put('/categories/{id}', [OwnerCategoryController::class, 'update'])->whereNumber('id');
        Route::delete('/categories/{id}', [OwnerCategoryController::class, 'destroy'])->whereNumber('id');
        Route::get('/serials', [OwnerSerialController::class, 'index']);
        Route::put('/serials/{id}', [OwnerSerialController::class, 'update'])->whereNumber('id');
        Route::get('/categories', [OwnerCatalogController::class, 'categories']);
        Route::get('/customers', [OwnerCustomerController::class, 'index']);
        Route::get('/customers/{id}', [OwnerCustomerController::class, 'show']);
        Route::post('/customers', [OwnerCustomerController::class, 'store']);
        Route::put('/customers/{id}', [OwnerCustomerController::class, 'update']);
        Route::delete('/customers/{id}', [OwnerCustomerController::class, 'destroy']);
        Route::get('/expenses', [OwnerExpenseController::class, 'index']);
        Route::post('/expenses', [OwnerExpenseController::class, 'store']);
        Route::put('/expenses/{id}', [OwnerExpenseController::class, 'update']);
        Route::delete('/expenses/{id}', [OwnerExpenseController::class, 'destroy']);
        Route::get('/supplies', [OwnerSupplyController::class, 'index']);
        Route::post('/supplies', [OwnerSupplyController::class, 'store']);
        Route::get('/supplies/{id}', [OwnerSupplyController::class, 'show'])->whereNumber('id');
        Route::put('/supplies/{id}', [OwnerSupplyController::class, 'update'])->whereNumber('id');
        Route::post('/supplies/{id}/receive', [OwnerSupplyController::class, 'receive'])->whereNumber('id');
        Route::post('/supplies/{id}/cancel', [OwnerSupplyController::class, 'cancel'])->whereNumber('id');
        Route::get('/supply-products', [OwnerSupplyController::class, 'products']);
        Route::post('/supply-serials/check', [OwnerSupplyController::class, 'checkSerials']);
        Route::get('/suppliers', [OwnerSupplierController::class, 'index']);
        Route::post('/suppliers', [OwnerSupplierController::class, 'store']);
        Route::get('/suppliers/{id}', [OwnerSupplierController::class, 'show'])->whereNumber('id');
        Route::put('/suppliers/{id}', [OwnerSupplierController::class, 'update'])->whereNumber('id');
        Route::delete('/suppliers/{id}', [OwnerSupplierController::class, 'destroy'])->whereNumber('id');
        // Équipe : le gérant voit les gestionnaires mais ne gère que les vendeurs de sa boutique
        Route::get('/team', [OwnerTeamController::class, 'index']);
        Route::post('/team', [OwnerTeamController::class, 'store']);
        Route::put('/team/{id}', [OwnerTeamController::class, 'update'])->whereNumber('id');
        Route::patch('/team/{id}/status', [OwnerTeamController::class, 'toggleStatus'])->whereNumber('id');
        Route::post('/team/{id}/reset-access', [OwnerTeamController::class, 'resetAccess'])->whereNumber('id')->middleware('throttle:10,1');
        Route::delete('/team/{id}', [OwnerTeamController::class, 'destroy'])->whereNumber('id');
    };

    // Espace propriétaire : données limitées aux boutiques de son entreprise
    Route::prefix('owner')->middleware('role:Owner')->group(function () use ($storeModules) {
        $storeModules();
        Route::get('/stores', [OwnerDashboardController::class, 'stores']);
        Route::patch('/stores/{id}/settings', [OwnerDashboardController::class, 'updateSettings'])->whereNumber('id');
        Route::get('/stores/{id}', [\App\Http\Controllers\Owner\OwnerStoreController::class, 'show'])->whereNumber('id');
        // POST (et non PUT) : formulaire multipart avec logo
        Route::post('/stores/{id}', [\App\Http\Controllers\Owner\OwnerStoreController::class, 'update'])->whereNumber('id');
        Route::get('/company', [OwnerCompanyController::class, 'show']);
        Route::post('/company', [OwnerCompanyController::class, 'update']);
    });

    // Espace gérant : mêmes écrans, limités à la boutique qu'il gère
    Route::prefix('manager')->middleware('role:Manager')->group($storeModules);

    // Espace vendeur : sa boutique, ses ventes, ses factures et ses encaissements.
    // Catalogue en consultation ; clients sans suppression ; ni stock, ni dépenses, ni équipe.
    Route::prefix('seller')->middleware('role:Seller')->group(function () {
        Route::get('/store-options', [OwnerDashboardController::class, 'storeOptions']);
        Route::get('/dashboard', [OwnerDashboardController::class, 'dashboard']);
        Route::get('/sales', [OwnerSaleController::class, 'index']);
        Route::get('/sales/export', [OwnerSaleController::class, 'export']);
        Route::get('/sales/export-pdf', [OwnerSaleController::class, 'exportPdf']);
        Route::get('/sales/{id}', [OwnerSaleController::class, 'show'])->whereNumber('id');
        Route::post('/sales', [OwnerPosController::class, 'checkout'])->middleware('throttle:60,1');
        Route::get('/pos/products', [OwnerPosController::class, 'products']);
        Route::get('/pos/products/{id}/serials', [OwnerPosController::class, 'serials'])->whereNumber('id');
        Route::get('/pos/customers', [OwnerPosController::class, 'customers']);
        Route::get('/invoices', [OwnerInvoiceController::class, 'index']);
        Route::get('/invoices/{id}', [OwnerInvoiceController::class, 'show'])->whereNumber('id');
        Route::post('/invoices/{id}/payments', [OwnerInvoiceController::class, 'pay'])->whereNumber('id');
        Route::post('/invoices/{id}/whatsapp', [OwnerInvoiceController::class, 'whatsapp'])->whereNumber('id')->middleware('throttle:20,1');
        Route::get('/payments', [OwnerPaymentController::class, 'index']);
        Route::get('/payments/export', [OwnerPaymentController::class, 'export']);
        Route::get('/payments/export-pdf', [OwnerPaymentController::class, 'exportPdf']);
        Route::get('/products', [OwnerCatalogController::class, 'products']);
        Route::get('/categories', [OwnerCatalogController::class, 'categories']);
        Route::get('/categories/overview', [OwnerCategoryController::class, 'index']);
        Route::get('/customers', [OwnerCustomerController::class, 'index']);
        Route::get('/customers/{id}', [OwnerCustomerController::class, 'show'])->whereNumber('id');
        Route::post('/customers', [OwnerCustomerController::class, 'store']);
        Route::put('/customers/{id}', [OwnerCustomerController::class, 'update'])->whereNumber('id');
    });

    Route::prefix('subscription')->middleware('role:Admin')->group(function () {
        Route::get('/stores', [SubscriptionController::class, 'stores']);
        Route::get('/store/{storeId}', [SubscriptionController::class, 'index']);
        Route::post('/add', [SubscriptionController::class, 'store']);
        Route::put('/update/{id}', [SubscriptionController::class, 'update']);
        Route::delete('/delete/{id}', [SubscriptionController::class, 'destroy']);
    });

    Route::prefix('supplier')->group(function () {
        Route::get('/list', [SupplierProductController::class, 'index']);
        Route::post('/add', [SupplierProductController::class, 'store']);
        Route::put('/update/{id}', [SupplierProductController::class, 'update']);
        Route::delete('/delete/{id}', [SupplierProductController::class, 'destroy']);
    });

    Route::prefix('customer')->group(function () {
        Route::get('/list', [CustomerController::class, 'index']);
        Route::post('/add', [CustomerController::class, 'store']);
        Route::put('/update/{id}', [CustomerController::class, 'update']);
        Route::delete('/delete/{id}', [CustomerController::class, 'destroy']);
    });

    Route::prefix('category')->group(function () {
        Route::get('/all', [CategoryController::class, 'allCategories']);
        Route::get('/list', [CategoryController::class, 'index']);
        Route::post('/add', [CategoryController::class, 'store']);
        Route::put('/update/{id}', [CategoryController::class, 'update']);
        Route::delete('/delete/{id}', [CategoryController::class, 'delete']);
    });

    Route::prefix('store')->group(function () {
        Route::get('/list/{id}', [StoreController::class, 'index']);
        Route::post('/add', [StoreController::class, 'store']);
        Route::put('/update/{id}', [StoreController::class, 'update']);
        Route::get('/change-status/{id}', [StoreController::class, 'changeStatus']);
        Route::delete('/delete/{id}', [StoreController::class, 'destroy']);
    });

    Route::prefix('product')->group(function () {
        Route::get('/list', [ProductController::class, 'index']);
        Route::post('/add', [ProductController::class, 'store']);
        Route::put('/update/{id}', [ProductController::class, 'update']);
        Route::delete('/delete/{id}', [ProductController::class, 'delete']);
        Route::get('/available', [ProductController::class, 'getProductAvailable']);
    });

    Route::prefix('sale')->group(function () {
        Route::get('/list', [SaleController::class, 'index']);
        Route::get('/show/{id}', [SaleController::class, 'show']);
        Route::post('/add', [SaleController::class, 'store']);
        Route::post('/validate/{id}', [SaleController::class, 'validateSale']);
        Route::post('/cancel/{id}', [SaleController::class, 'cancel']);
    });

    Route::prefix('procurement')->group(function () {
        Route::get('/list', [SupplyController::class, 'index']);
        Route::post('/add', [SupplyController::class, 'store']);
        Route::post('/validate/{id}', [SupplyController::class, 'validateSupply']);
        Route::post('/cancel/{id}', [SupplyController::class, 'cancelSupply']);
        Route::put('/update/{id}', [SupplyController::class, 'update']);
    });

    Route::prefix('expense')->group(function () {
        Route::get('/list', [ExpenseController::class, 'index']);
        Route::post('/add', [ExpenseController::class, 'store']);
        Route::put('/update/{id}', [ExpenseController::class, 'update']);
        Route::delete('/delete/{id}', [ExpenseController::class, 'destroy']);
    });

    Route::prefix('invoice')->group(function () {
        Route::get('/list', [InvoiceController::class, 'index']);
        Route::post('/paid/{id}', [InvoiceController::class, 'paidInvoice']);
        Route::get('/{id}/details', [InvoiceController::class, 'getInvoiceWithPayments']);
    });

    Route::prefix('payment')->group(function () {
        Route::get('/list', [PaymentReceiptController::class, 'index']);
        Route::get('/export-pdf', [PaymentReceiptController::class, 'exportPdf']);
    });

    Route::prefix('imei')->group(function () {
        Route::get('/list', [SerialNumberController::class, 'index']);
        Route::put('/update/{id}', [SerialNumberController::class, 'update']);
        // Route::get('/{id}/details', [SerialNumberController::class, 'getInvoiceWithPayments']);
    });

    Route::get('/reporting', [ReportingController::class, 'getDashboardData']);
});

// php artisan cache:clear && php artisan config:clear && php artisan migrate:fresh && php artisan db:seed && php artisan serve

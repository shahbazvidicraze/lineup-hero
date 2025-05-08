<?php

use App\Http\Controllers\Api\V1\Admin\AdminController; // Correct Controller for User management
use App\Http\Controllers\Api\V1\Admin\AdminPaymentController;
use App\Http\Controllers\Api\V1\Admin\AdminPromoCodeController; // Import Admin controller
use App\Http\Controllers\Api\V1\Admin\AdminSettingsController;
use App\Http\Controllers\Api\V1\Auth\AdminAuthController;
use App\Http\Controllers\Api\V1\Auth\UserAuthController;
use App\Http\Controllers\Api\V1\GameController;
use App\Http\Controllers\Api\V1\OrganizationController;
use App\Http\Controllers\Api\V1\PlayerController;
use App\Http\Controllers\Api\V1\PlayerPreferenceController;
use App\Http\Controllers\Api\V1\PositionController;
use App\Http\Controllers\Api\V1\PromoCodeController as UserPromoCodeController; // Keep if PromoCodes are implemented
use App\Http\Controllers\Api\V1\StripeController;
use App\Http\Controllers\Api\V1\TeamController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;




/*
|--------------------------------------------------------------------------
| API Routes V1
|--------------------------------------------------------------------------
*/

Route::prefix('v1')->group(function () {

    // --- Public Authentication ---
    Route::prefix('user/auth')->controller(UserAuthController::class)->group(function () {
        Route::post('register', 'register');
        Route::post('login', 'login');
        Route::post('logout', 'logout')->middleware('auth:api_user');
        Route::post('refresh', 'refresh')->middleware('auth:api_user');
        Route::get('profile', 'profile')->middleware('auth:api_user');
    });

    Route::prefix('admin/auth')->controller(AdminAuthController::class)->group(function () {
        Route::post('login', 'login');
        Route::post('logout', 'logout')->middleware('auth:api_admin');
        Route::post('refresh', 'refresh')->middleware('auth:api_admin');
        Route::get('profile', 'profile')->middleware('auth:api_admin');
    });

    // --- Protected User Routes ---
    Route::middleware('auth:api_user')->group(function () {
        // Team Management
        Route::apiResource('teams', TeamController::class);
        Route::get('teams/{team}/players', [TeamController::class, 'listPlayers']);

        // Player Management (within Team context for creation)
        Route::post('teams/{team}/players', [PlayerController::class, 'store']);
        Route::put('players/{player}', [PlayerController::class, 'update']);
        Route::delete('players/{player}', [PlayerController::class, 'destroy']);
        Route::get('players/{player}', [PlayerController::class, 'show']); // Added
        // Player Preferences
        Route::post('players/{player}/preferences', [PlayerPreferenceController::class, 'store']);
        Route::get('players/{player}/preferences', [PlayerPreferenceController::class, 'show']);

        // Game Management
        Route::get('teams/{team}/games', [GameController::class, 'index']);
        Route::post('teams/{team}/games', [GameController::class, 'store']);
        Route::get('games/{game}', [GameController::class, 'show']);
        Route::put('games/{game}', [GameController::class, 'update']); // Updates game details, not lineup
        Route::delete('games/{game}', [GameController::class, 'destroy']);

        // Lineup & PDF
        Route::get('games/{game}/lineup', [GameController::class, 'getLineup']);
        Route::put('games/{game}/lineup', [GameController::class, 'updateLineup']);
        Route::post('games/{game}/autocomplete-lineup', [GameController::class, 'autocompleteLineup']);
        Route::get('games/{game}/pdf-data', [GameController::class, 'getLineupPdfData']);

        // Supporting Lists
        Route::get('organizations', [OrganizationController::class, 'index']); // User list view
        Route::get('positions', [PositionController::class, 'index']);         // User list view

        // --- Stripe Payment Initiation ---
        Route::post('teams/{team}/create-payment-intent', [StripeController::class, 'createTeamPaymentIntent']); // Added

        Route::post('promo-codes/redeem', [UserPromoCodeController::class, 'redeem']); // Added

    }); // End User middleware group

    // Stripe Webhook
    Route::post('stripe/webhook', [StripeController::class, 'handleWebhook'])->name('stripe.webhook'); // Added & named


    // --- Protected Admin Routes ---
    Route::middleware('auth:api_admin')->prefix('admin')->group(function () {
        // Organization Management (Full CRUD except index handled separately)
        Route::apiResource('organizations', OrganizationController::class)->except(['index']);
        Route::get('organizations', [OrganizationController::class, 'index']); // Admin list view (might differ from user view)

        Route::get('settings', [AdminSettingsController::class, 'show']);
        Route::put('settings', [AdminSettingsController::class, 'update']);
        // Position Management (Full CRUD except index handled separately)
        Route::apiResource('positions', PositionController::class)->except(['index']);
        Route::get('positions', [PositionController::class, 'index']);         // Admin list view (might differ from user view)

        // User Management (Full CRUD) - Using AdminUserController
        Route::apiResource('users', AdminController::class);

        Route::apiResource('promo-codes', AdminPromoCodeController::class); // Added

        Route::apiResource('payments', AdminPaymentController::class)->only([
            'index', 'show' // Admins likely only need to view payments, not create/edit/delete them via API
        ]);
    }); // End Admin middleware group

}); // End V1 prefix group

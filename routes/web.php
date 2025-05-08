<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\WebPaymentController;

// --- Basic Laravel Welcome Route ---
Route::get('/', function () {
    return view('welcome');
});

// --- Payment Web Flow Routes (NO AUTHENTICATION) ---

// Route to initiate payment for a specific team - ANYONE can hit this if they know the team ID
Route::get('/teams/{team}/pay', [WebPaymentController::class, 'showPaymentPage'])
    ->name('payment.initiate');

// Route Stripe redirects back to after payment attempt
Route::get('/payment/return', [WebPaymentController::class, 'handleReturn'])
    ->name('payment.return');


// --- Stripe Webhook Route (Still needs to be public) ---
// Defined in routes/api.php or routes/web.php - ensure it's accessible by Stripe
// Route::post('/stripe/webhook', [\App\Http\Controllers\Api\V1\StripeController::class, 'handleWebhook'])->name('stripe.webhook');

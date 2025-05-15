<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponseTrait; // <-- USE TRAIT
use App\Models\Payment;
use App\Models\Team;
use App\Models\Settings; // Import Settings
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Exception\ApiErrorException; // For Stripe API errors
use Stripe\PaymentIntent;
use Stripe\Stripe;
use Stripe\Webhook;
use UnexpectedValueException;
use Illuminate\Http\Response; // For status codes

class StripeController extends Controller
{
    use ApiResponseTrait; // <-- INCLUDE TRAIT

    public function __construct()
    {
        Stripe::setApiKey(config('services.stripe.secret'));
        Stripe::setApiVersion('2024-04-10');
    }

    /**
     * Create a Stripe Payment Intent for unlocking a specific team.
     */
    public function createTeamPaymentIntent(Request $request, Team $team)
    {
        $user = $request->user();
        if ($user->id !== $team->user_id) {
            return $this->forbiddenResponse('You do not own this team.');
        }
        if ($team->hasActiveAccess()) {
            return $this->errorResponse('This team already has active access.', Response::HTTP_CONFLICT);
        }

        $settings = Settings::instance();
        $amount = $settings->unlock_price_amount;
        $currency = $settings->unlock_currency;

        if (!$amount || !$currency) {
            Log::error("Stripe PI creation failed: Missing unlock price/currency in settings for Team ID {$team->id}.");
            return $this->errorResponse('Payment configuration error.', Response::HTTP_INTERNAL_SERVER_ERROR, 'Unlock price or currency not set.');
        }

        try {
            $paymentIntent = PaymentIntent::create([
                'amount' => $amount,
                'currency' => $currency,
                'automatic_payment_methods' => ['enabled' => true],
                'description' => "Access unlock for Team: {$team->name} (ID: {$team->id})",
                'metadata' => [
                    'team_id' => $team->id, 'team_name' => $team->name,
                    'user_id' => $user->id, 'user_email' => $user->email,
                ],
            ]);
            Log::info("Created PaymentIntent {$paymentIntent->id} for Team ID {$team->id}");

            return $this->successResponse([
                'clientSecret' => $paymentIntent->client_secret,
                'amount' => $amount, // Amount in cents
                'currency' => $currency,
                // For display in Flutter, convert to dollars if needed
                'displayAmount' => number_format($amount / 100, 2),
                'displayCurrencySymbol' => $settings->unlock_currency_symbol,
                'displaySymbolPosition' => $settings->unlock_currency_symbol_position,
            ], 'Payment Intent created successfully.');

        } catch (ApiErrorException $e) { // Catch Stripe specific API errors
            Log::error("Stripe PaymentIntent creation API error for Team ID {$team->id}: " . $e->getMessage(), ['stripe_error' => $e->getError()?->message]);
            return $this->errorResponse('Failed to initiate payment: ' . ($e->getError()?->message ?: 'Stripe API error.'), Response::HTTP_SERVICE_UNAVAILABLE);
        } catch (\Exception $e) {
            Log::error("Stripe PaymentIntent creation failed for Team ID {$team->id}: " . $e->getMessage());
            return $this->errorResponse('Failed to initiate payment process.', Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Handle incoming Stripe webhooks.
     */
    public function handleWebhook(Request $request)
    {
        $payload = $request->getContent();
        $sigHeader = $request->server('HTTP_STRIPE_SIGNATURE');
        $webhookSecret = config('services.stripe.webhook_secret');
        $event = null;

        if (!$webhookSecret) {
            Log::critical('Stripe webhook secret is NOT configured.');
            return $this->errorResponse('Webhook secret not configured.', Response::HTTP_INTERNAL_SERVER_ERROR, 'Server configuration error.');
        }

        try {
            $event = Webhook::constructEvent($payload, $sigHeader, $webhookSecret);
        } catch (UnexpectedValueException | SignatureVerificationException $e) {
            Log::warning('Stripe Webhook Error: Invalid payload or signature.', ['exception' => $e->getMessage()]);
            return $this->errorResponse('Invalid webhook payload or signature.', Response::HTTP_BAD_REQUEST);
        } catch (\Exception $e) {
            Log::error('Stripe Webhook Error: Generic construction error.', ['exception' => $e->getMessage()]);
            return $this->errorResponse('Webhook processing error.', Response::HTTP_BAD_REQUEST);
        }

        Log::info('Stripe Webhook Received:', ['type' => $event->type, 'id' => $event->id]);

        switch ($event->type) {
            case 'payment_intent.succeeded':
                $this->handlePaymentIntentSucceeded($event->data->object);
                break;
            case 'payment_intent.payment_failed':
                $this->handlePaymentIntentFailed($event->data->object);
                break;
            default:
                Log::info('Received unhandled Stripe event type: ' . $event->type);
        }
        // Always return 200 to Stripe for acknowledged webhooks
        return $this->successResponse(null, 'Webhook handled.');
    }

    protected function handlePaymentIntentSucceeded(PaymentIntent $paymentIntent): void
    {
        Log::info("Handling payment_intent.succeeded: {$paymentIntent->id}");
        $teamId = $paymentIntent->metadata->team_id ?? null;
        $userId = $paymentIntent->metadata->user_id ?? null;

        if (!$teamId || !$userId) {
            Log::error("Webhook Succeeded Error: Missing team_id or user_id in PI metadata {$paymentIntent->id}");
            return;
        }
        if (Payment::where('stripe_payment_intent_id', $paymentIntent->id)->exists()) {
            Log::info("Webhook Succeeded Info: PaymentIntent {$paymentIntent->id} already processed.");
            return;
        }
        $team = Team::find($teamId);
        if (!$team) {
            Log::error("Webhook Succeeded Error: Team not found for team_id {$teamId} from PI {$paymentIntent->id}");
            return;
        }

        Payment::create([
            'user_id' => $userId, 'team_id' => $teamId,
            'stripe_payment_intent_id' => $paymentIntent->id,
            'amount' => $paymentIntent->amount_received,
            'currency' => $paymentIntent->currency,
            'status' => $paymentIntent->status,
            'paid_at' => now(),
        ]);
        $team->grantPaidAccess(null); // Grant access (decide expiry based on business logic)
        Log::info("Access granted for Team ID {$teamId} via PaymentIntent {$paymentIntent->id}");
    }

    protected function handlePaymentIntentFailed(PaymentIntent $paymentIntent): void
    {
        Log::warning("Handling payment_intent.payment_failed: {$paymentIntent->id}", ['metadata' => $paymentIntent->metadata]);
        // TODO: Notify user or admin if needed
    }
}

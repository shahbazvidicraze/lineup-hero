<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\Settings;
use App\Models\Team;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\SignatureVerificationException;
use Stripe\PaymentIntent;
use Stripe\Stripe;
use Stripe\Webhook;
use UnexpectedValueException;

class StripeController extends Controller
{
    public function __construct()
    {
        // Set Stripe API key for all methods in this controller
        Stripe::setApiKey(config('services.stripe.secret'));
        Stripe::setApiVersion('2024-04-10'); // Use a specific API version
    }

    /**
     * Create a Stripe Payment Intent for unlocking a specific team.
     * Route: POST /teams/{team}/create-payment-intent
     */
    public function createTeamPaymentIntent(Request $request, Team $team)
    {
        $user = $request->user();

        // Authorization: Ensure user owns the team
        if ($user->id !== $team->user_id) {
            return response()->json(['error' => 'Forbidden: You do not own this team.'], 403);
        }

        // Check if team already has access
        if ($team->hasActiveAccess()) {
            return response()->json(['error' => 'This team already has active access.'], 409); // Conflict
        }

        $settings = Settings::instance();
        $amount = ($settings->unlock_price_amount*100); // Amount in cents
        $currency = $settings->unlock_currency;

        // $amount = config('services.stripe.unlock_amount');
        // $currency = config('services.stripe.currency');

        try {
            $paymentIntent = PaymentIntent::create([
                'amount' => $amount,
                'currency' => $currency,
                'automatic_payment_methods' => ['enabled' => true], // Let Stripe manage payment methods
                'description' => "Access unlock for Team: {$team->name} (ID: {$team->id})",
                'metadata' => [ // Store relevant info for webhook processing
                    'team_id' => $team->id,
                    'team_name' => $team->name,
                    'user_id' => $user->id,
                    'user_email' => $user->email,
                ],
            ]);

            Log::info("Created PaymentIntent {$paymentIntent->id} for Team ID {$team->id}");

            return response()->json([
                'clientSecret' => $paymentIntent->client_secret,
                'amount' => $amount,
                'currency' => $currency,
            ]);

        } catch (\Exception $e) {
            Log::error("Stripe PaymentIntent creation failed for Team ID {$team->id}: " . $e->getMessage());
            return response()->json(['error' => 'Failed to initiate payment process.'], 500);
        }
    }

    /**
     * Handle incoming Stripe webhooks.
     * Route: POST /stripe/webhook
     */
    public function handleWebhook(Request $request)
    {
        $payload = $request->getContent();
        $sigHeader = $request->server('HTTP_STRIPE_SIGNATURE');
        $webhookSecret = config('services.stripe.webhook_secret');
        $event = null;

        if (!$webhookSecret) {
             Log::error('Stripe webhook secret is not configured.');
             return response()->json(['error' => 'Webhook secret not configured.'], 500);
        }

        try {
            $event = Webhook::constructEvent($payload, $sigHeader, $webhookSecret);
        } catch (UnexpectedValueException $e) {
            // Invalid payload
            Log::error('Stripe Webhook Error: Invalid payload.', ['exception' => $e]);
            return response()->json(['error' => 'Invalid payload'], 400);
        } catch (SignatureVerificationException $e) {
            // Invalid signature
            Log::error('Stripe Webhook Error: Invalid signature.', ['exception' => $e]);
            return response()->json(['error' => 'Invalid signature'], 400);
        } catch (\Exception $e) {
             Log::error('Stripe Webhook Error: Generic construction error.', ['exception' => $e]);
             return response()->json(['error' => 'Webhook processing error'], 400);
        }


        Log::info('Stripe Webhook Received:', ['type' => $event->type, 'id' => $event->id]);

        // Handle the event type
        switch ($event->type) {
            case 'payment_intent.succeeded':
                $paymentIntent = $event->data->object; // contains a \Stripe\PaymentIntent
                $this->handlePaymentIntentSucceeded($paymentIntent);
                break;
             case 'payment_intent.payment_failed':
                 $paymentIntent = $event->data->object;
                 $this->handlePaymentIntentFailed($paymentIntent);
                 break;
            // ... handle other event types as needed
            default:
                Log::info('Received unhandled Stripe event type: ' . $event->type);
        }

        return response()->json(['status' => 'success']);
    }

    /**
     * Handle successful PaymentIntent.
     */
    protected function handlePaymentIntentSucceeded(PaymentIntent $paymentIntent): void
    {
        Log::info("Handling payment_intent.succeeded: {$paymentIntent->id}");

        // Extract metadata
        $teamId = $paymentIntent->metadata->team_id ?? null;
        $userId = $paymentIntent->metadata->user_id ?? null;

        if (!$teamId || !$userId) {
            Log::error("Webhook Error: Missing team_id or user_id in metadata for PaymentIntent {$paymentIntent->id}");
            return; // Stop processing if metadata is missing
        }

        // Check if payment already recorded (webhooks can sometimes be sent multiple times)
        if (Payment::where('stripe_payment_intent_id', $paymentIntent->id)->exists()) {
             Log::info("Webhook Info: PaymentIntent {$paymentIntent->id} already processed.");
             return; // Already handled
        }

        // Find the team
        $team = Team::find($teamId);
        if (!$team) {
             Log::error("Webhook Error: Team not found for team_id {$teamId} from PaymentIntent {$paymentIntent->id}");
             return; // Team doesn't exist anymore?
        }

        $triggerSource = $paymentIntent->metadata->trigger_source ?? 'api'; // Default to 'api'
        Log::info("Handling payment_intent.succeeded: {$paymentIntent->id} from {$triggerSource}");

        // Record the payment in your database
        Payment::create([
            'user_id' => $userId,
            'team_id' => $teamId,
            'stripe_payment_intent_id' => $paymentIntent->id,
            'amount' => round($paymentIntent->amount_received / 100, 2), // amount includes fees potentially
            'currency' => $paymentIntent->currency,
            'status' => $paymentIntent->status, // Should be 'succeeded'
            'paid_at' => now(), // Or use $paymentIntent->created timestamp? Check Stripe docs.
        ]);

        // Grant access to the team
        // Decide if access is permanent or timed based on your business logic
        $team->grantPaidAccess(null); // Grant permanent access for now

         Log::info("Access granted for Team ID {$teamId} via PaymentIntent {$paymentIntent->id}");

        // TODO: Send confirmation email/notification to the user
    }

     /**
      * Handle failed PaymentIntent.
      */
     protected function handlePaymentIntentFailed(PaymentIntent $paymentIntent): void
     {
         Log::warning("Handling payment_intent.payment_failed: {$paymentIntent->id}");
         // TODO: Notify user about the payment failure if needed
     }

} // End StripeController Class

<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponseTrait; // <-- USE TRAIT
use App\Mail\AdminPaymentReceivedMail;
use App\Mail\UserPaymentFailedMail;
use App\Mail\UserPaymentSuccessMail;
use App\Models\Payment;
use App\Models\Team;
use App\Models\Settings; // Import Settings
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
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
        $amount = ($settings->unlock_price_amount*100);
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
        $user = User::find($userId);

        if (!$team) {
            Log::error("Webhook Succeeded Error: Team not found for team_id {$teamId} from PI {$paymentIntent->id}");
            return;
        }

        $payment = Payment::create([
            'user_id' => $userId, 'team_id' => $teamId,
            'stripe_payment_intent_id' => $paymentIntent->id,
            'amount' => $paymentIntent->amount_received,
            'currency' => $paymentIntent->currency,
            'status' => $paymentIntent->status,
            'paid_at' => now(),
        ]);

        $accessExpiryDate = $team->grantPaidAccess(); // This now returns the Carbon expiry date
        $settings = Settings::instance(); // Get settings to read duration for notification
        $durationDays = $settings->access_duration_days > 0 ? $settings->access_duration_days : 365;
        $durationString = $this->getHumanReadableDuration($durationDays); // Use helper

        Log::info("Access granted for Team ID {$teamId} via PI {$paymentIntent->id} for {$durationString}. Expires: {$accessExpiryDate->toIso8601String()}");


        // --- Send User Payment Success Notification (Check Preference) ---
        if ($user->email && $user->receive_payment_notifications) { // <-- CHECK PREFERENCE
            try {
                Mail::to($user->email)->send(new UserPaymentSuccessMail($payment));
                Log::info("User payment success notification sent to {$user->email} for PI {$paymentIntent->id}");
            } catch (\Exception $e) {
                Log::error("Failed to send user payment success notification for PI {$paymentIntent->id}: " . $e->getMessage());
            }
        } elseif ($user->email && !$user->receive_payment_notifications) {
            Log::info("User {$user->email} has opted out of payment success notifications for PI {$paymentIntent->id}.");
        }
        // --- End Send User Notification ---

        // --- Send Admin Notification Email ---
        $settings = Settings::instance();
        if ($settings->notify_admin_on_payment && !empty($settings->admin_notification_email)) {
            try {
                // Pass the newly created Payment model instance to the mailable
                Mail::to($settings->admin_notification_email)->send(new AdminPaymentReceivedMail($payment));
                Log::info("Admin payment notification sent to {$settings->admin_notification_email} for PaymentIntent {$paymentIntent->id}");
            } catch (\Exception $e) {
                Log::error("Failed to send admin payment notification for PI {$paymentIntent->id}: " . $e->getMessage(), ['exception' => $e]);
            }
        } elseif (!$settings->notify_admin_on_payment) {
            Log::info("Admin payment notification is disabled in settings for PI {$paymentIntent->id}.");
        } elseif (empty($settings->admin_notification_email)) {
            Log::warning("Admin payment notification enabled, but no admin_notification_email is set in settings for PI {$paymentIntent->id}.");
        }
        // --- End Send Admin Notification Email ---
    }

    protected function handlePaymentIntentFailed(PaymentIntent $paymentIntent): void
    {
        Log::warning("Handling payment_intent.payment_failed: {$paymentIntent->id}", ['metadata' => $paymentIntent->metadata]);

        $teamId = $paymentIntent->metadata->team_id ?? null;
        $userId = $paymentIntent->metadata->user_id ?? null;

        if ($teamId && $userId) {
            $team = Team::find($teamId);
            $user = User::find($userId);

            // --- Send User Payment Failed Notification (Check Preference) ---
            if ($user && $team && $user->email && $user->receive_payment_notifications) { // <-- CHECK PREFERENCE
                try {
                    Mail::to($user->email)->send(new UserPaymentFailedMail($user, $team, $paymentIntent));
                    Log::info("User payment failed notification sent to {$user->email} for PI {$paymentIntent->id}");
                } catch (\Exception $e) {
                    Log::error("Failed to send user payment failed notification for PI {$paymentIntent->id}: " . $e->getMessage());
                }
            } elseif ($user && $user->email && !$user->receive_payment_notifications) {
                Log::info("User {$user->email} has opted out of payment failed notifications for PI {$paymentIntent->id}.");
            } elseif (!$user || !$user->email) {
                Log::warning("Could not send payment failed notification: User or User email missing for PI {$paymentIntent->id}");
            }
            // --- End Send User Notification ---
        } else {
            Log::warning("Could not send payment failed notification: TeamID or UserID missing in metadata for PI {$paymentIntent->id}");
        }

        // Optionally: Record failed payment attempt in 'payments' table with 'failed' status
        if ($userId && $teamId && !Payment::where('stripe_payment_intent_id', $paymentIntent->id)->exists()) {
            Payment::create([
                'user_id' => $userId,
                'team_id' => $teamId,
                'stripe_payment_intent_id' => $paymentIntent->id,
                'amount' => $paymentIntent->amount, // Amount attempted
                'currency' => $paymentIntent->currency,
                'status' => 'failed', // Mark as failed
                'paid_at' => null, // Not paid
            ]);
            Log::info("Recorded failed payment attempt for PI {$paymentIntent->id}");
        }
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\Settings;
use App\Models\Team;
use App\Models\User; // Still needed to potentially get user info later
// use Illuminate\Support\Facades\Auth; // No longer using web Auth facade here
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\ApiErrorException;
use Stripe\PaymentIntent;
use Stripe\Stripe;

class WebPaymentController extends Controller
{
    public function __construct()
    {
        // ---- REMOVED AUTH MIDDLEWARE ----
        // $this->middleware('auth');

        Stripe::setApiKey(config('services.stripe.secret'));
        Stripe::setApiVersion('2024-04-10');
    }

    /**
     * Show the payment initiation page for a team.
     * WARNING: No user authentication here. Relies on webhook metadata.
     */
    public function showPaymentPage(Request $request, Team $team): View|RedirectResponse
    {
        // We don't know which user is initiating, CANNOT reliably check ownership here!
        // Anyone knowing the team ID can reach this point.

        // Check if team already has access (still useful)
        if ($team->hasActiveAccess()) {
            // Redirect or show message - maybe redirect to a generic success page?
             return redirect('/')->with('info', 'Team ' . $team->name . ' already has active access.'); // Redirect to homepage
        }

        // Fetch the owner of the team to store their info in metadata
        // This assumes the webhook needs to know the owner, even if the web user isn't logged in.
        $owner = $team->user; // Load the owner relationship
        if (!$owner) {
            Log::error("Web Flow (No Auth): Cannot find owner for Team ID {$team->id}. Cannot proceed.");
            // Redirect back or show error view
             return redirect('/')->with('error', 'Cannot process payment for this team due to missing owner information.');
        }


        $settings = Settings::instance();
        $amount = ($settings->unlock_price_amount*100); // Amount in cents
        $currency = $settings->unlock_currency;

        try {
            $paymentIntent = PaymentIntent::create([
                'amount' => $amount,
                'currency' => $currency,
                'automatic_payment_methods' => ['enabled' => true],
                'description' => "Web Access unlock for Team: {$team->name} (ID: {$team->id})",
                'metadata' => [
                    'team_id' => $team->id,
                    'team_name' => $team->name,
                    // Store the OWNER's ID and email from the team relationship
                    'user_id' => $owner->id,
                    'user_email' => $owner->email,
                    'trigger_source' => 'web_flow_no_auth', // Identify source
                ],
            ]);

            Log::info("Web Flow (No Auth): Created PaymentIntent {$paymentIntent->id} for Team ID {$team->id}, Owner ID {$owner->id}");

            return view('payments.initiate', [
                'stripeKey' => config('services.stripe.key'),
                'clientSecret' => $paymentIntent->client_secret,
                'team' => $team,
                'amount' => $amount,
                'currency' => $currency,
                 'returnUrl' => route('payment.return'), // Return URL remains the same
            ]);

        } catch (ApiErrorException $e) {
            Log::error("Web Flow (No Auth): Stripe PI creation failed for Team ID {$team->id}: " . $e->getMessage());
            return redirect('/')->with('error', 'Could not initiate payment. Please try again later.');
        } catch (\Exception $e) {
             Log::error("Web Flow (No Auth): Generic error initiating payment for Team ID {$team->id}: " . $e->getMessage());
             return redirect('/')->with('error', 'An unexpected error occurred.');
        }
    }

    /**
     * Handle the return URL redirect from Stripe after payment attempt.
     * Still relies on webhook for granting access.
     */
    public function handleReturn(Request $request): View
    {
        // This method remains largely the same, as it primarily checks the PI status from Stripe
        // based on query parameters, not the logged-in user state.

        $paymentIntentId = $request->query('payment_intent');
        // ... rest of handleReturn method remains the same as previous answer ...
        // ... retrieving PI, checking status, returning success/processing/failed view ...

        // --- (Copy the rest of handleReturn from the previous answer here) ---
        if (!$paymentIntentId) {
            Log::warning("Payment Return: Missing payment_intent ID in return URL.");
            return view('payments.failed', ['message' => 'Payment details missing.']);
        }
        try {
            $paymentIntent = PaymentIntent::retrieve($paymentIntentId);
            Log::info("Payment Return: Handling PI {$paymentIntent->id} with status {$paymentIntent->status}");
            if ($paymentIntent->status === 'succeeded') {
                 $teamId = $paymentIntent->metadata->team_id ?? null;
                 $teamName = $paymentIntent->metadata->team_name ?? 'your team';
                 return view('payments.success', ['teamId' => $teamId, 'teamName' => $teamName]);
            } elseif ($paymentIntent->status === 'processing') {
                 return view('payments.processing');
            } else {
                 Log::warning("Payment Return: PaymentIntent {$paymentIntent->id} status is {$paymentIntent->status}");
                 return view('payments.failed', ['message' => 'Payment attempt failed or requires action. Please try again.']);
            }
        } catch (ApiErrorException $e) {
             Log::error("Payment Return: Error retrieving PI {$paymentIntentId}: " . $e->getMessage());
             return view('payments.failed', ['message' => 'Could not verify payment status.']);
        } catch (\Exception $e) {
             Log::error("Payment Return: Generic error handling return for PI {$paymentIntentId}: " . $e->getMessage());
             return view('payments.failed', ['message' => 'An unexpected error occurred verifying payment.']);
        }
        // --- End copied section ---
    }
}

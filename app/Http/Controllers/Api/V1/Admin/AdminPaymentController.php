<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use Illuminate\Http\Request;

class AdminPaymentController extends Controller
{
    /**
     * Display a listing of the payments.
     * Allows filtering by user or team.
     * Route: GET /admin/payments
     */
    public function index(Request $request)
    {
        $query = Payment::query()
                    ->with(['user:id,first_name,last_name,email', 'team:id,name']) // Eager load basic user/team info
                    ->orderBy('paid_at', 'desc'); // Show most recent first

        // --- Filtering ---
        if ($request->filled('user_id')) {
            $query->where('user_id', $request->input('user_id'));
        }
        if ($request->filled('team_id')) {
            $query->where('team_id', $request->input('team_id'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->input('status')); // e.g., 'succeeded'
        }
        if ($request->filled('stripe_payment_intent_id')) {
             $query->where('stripe_payment_intent_id', $request->input('stripe_payment_intent_id'));
        }
        // Add date range filtering if needed
        if ($request->filled('start_date')) {
            $query->whereDate('paid_at', '>=', $request->input('start_date'));
        }
         if ($request->filled('end_date')) {
            $query->whereDate('paid_at', '<=', $request->input('end_date'));
        }


        $payments = $query->paginate($request->input('per_page', 25));

        return response()->json($payments);
    }


    /**
     * Display the specified payment resource.
     * Route: GET /admin/payments/{payment}
     */
    public function show(Payment $payment)
    {
        // Eager load relationships for detailed view
        $payment->load(['user:id,first_name,last_name,email', 'team:id,name,user_id']);

        return response()->json($payment);
    }

    // --- No store, update, destroy methods needed for admins usually ---

}

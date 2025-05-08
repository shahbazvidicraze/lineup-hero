<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\PromoCode;
use App\Models\PromoCodeRedemption;
use App\Models\Team;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

// Rename controller class if needed to avoid conflict with Admin one
class PromoCodeController extends Controller
{
    /**
     * Redeem a promo code for the authenticated user, applying access to a team.
     * Route: POST /promo-codes/redeem
     */
    public function redeem(Request $request)
    {
        $user = $request->user();

        $validator = Validator::make($request->all(), [
            'code' => 'required|string',
            // Team ID is now MANDATORY for applying promo benefit
            'team_id' => 'required|integer|exists:teams,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $codeString = strtoupper($request->input('code'));
        $teamId = $request->input('team_id');
        $team = null;

        // --- Validation Step 1: Find Code & Team ---
        $promoCode = PromoCode::where('code', $codeString)->first();
        $team = Team::find($teamId); // Find team

        if (!$promoCode) {
            return response()->json(['error' => 'Invalid promo code.'], 404);
        }
        if (!$team) {
            // Should be caught by exists:teams validation, but double check
             return response()->json(['error' => 'Team not found.'], 404);
        }

        // --- Validation Step 2: Check Team Ownership ---
        if ($team->user_id !== $user->id) {
             return response()->json(['error' => 'You do not own this team.'], 403);
        }

        // --- Validation Step 3: Check if Team Already Has Access ---
        if ($team->hasActiveAccess()) {
            return response()->json(['error' => 'This team already has active access.'], 409); // Conflict
        }

        // --- Validation Step 4: Check Code Status & Limits ---
        if (!$promoCode->is_active) { return response()->json(['error' => 'This promo code is not active.'], 400); }
        if ($promoCode->expires_at && $promoCode->expires_at->isPast()) { return response()->json(['error' => 'This promo code has expired.'], 400); }
        if ($promoCode->hasReachedMaxUses()) { return response()->json(['error' => 'Promo code usage limit reached.'], 400); }

        // --- Validation Step 5: Check User/Team Usage Limit ---
        // Check how many times THIS user used THIS code for THIS team
        $redemptionCount = PromoCodeRedemption::where('user_id', $user->id)
                                        ->where('promo_code_id', $promoCode->id)
                                        ->where('team_id', $teamId) // Check for specific team
                                        ->count();

        if ($redemptionCount >= $promoCode->max_uses_per_user) { // Usually max_uses_per_user is 1
             return response()->json(['error' => 'You have already used this promo code for this team.'], 400);
        }


        // --- Redemption Logic ---
        try {
            DB::transaction(function () use ($user, $promoCode, $teamId, $team) {
                // 1. Record Redemption
                PromoCodeRedemption::create([ 'user_id' => $user->id, 'promo_code_id' => $promoCode->id, 'team_id' => $teamId, 'redeemed_at' => now() ]);
                // 2. Increment Global Use Count
                $promoCode->increment('use_count');
                // 3. Grant Access to the Team
                $team->grantPromoAccess(); // Update team status

                Log::info("Promo code {$promoCode->code} redeemed by User ID {$user->id} for Team ID {$teamId}");
            });

            return response()->json(['message' => 'Promo code redeemed successfully! Access granted for team ' . $team->name . '.']);

        } catch (\Exception $e) {
            Log::error("Promo redemption failed: User {$user->id}, Code: {$promoCode->code}, Team: {$teamId}, Error: " . $e->getMessage());
            return response()->json(['error' => 'Failed to redeem promo code due to an internal error.'], 500);
        }
    }
}

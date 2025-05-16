<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponseTrait;
use App\Models\PromoCode;
use App\Models\PromoCodeRedemption;
use App\Models\Team;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Http\Response;
use Carbon\Carbon;

class PromoCodeController extends Controller
{
    use ApiResponseTrait;

    public function redeem(Request $request)
    {
        $user = $request->user();
        $validator = Validator::make($request->all(), [
            'code' => 'required|string',
            'team_id' => 'required|integer|exists:teams,id', // Team is still required to apply the benefit
        ]);
        if ($validator->fails()) return $this->validationErrorResponse($validator);

        $codeString = strtoupper($request->input('code'));
        $teamId = $request->input('team_id');

        $promoCode = PromoCode::where('code', $codeString)->first();
        if (!$promoCode) return $this->notFoundResponse('Invalid promo code.');

        $team = Team::find($teamId);
        if (!$team) return $this->notFoundResponse('Team not found.');
        if ($team->user_id !== $user->id) return $this->forbiddenResponse('You do not own this team.');
        if ($team->hasActiveAccess()) return $this->errorResponse('This team already has active access for PDF generation.', Response::HTTP_CONFLICT);

        // Code status & limits checks (as before)
        if (!$promoCode->is_active) return $this->errorResponse('This promo code is not active.', Response::HTTP_BAD_REQUEST);
        if ($promoCode->expires_at && $promoCode->expires_at->isPast()) return $this->errorResponse('This promo code has expired.', Response::HTTP_BAD_REQUEST);
        if ($promoCode->hasReachedMaxUses()) return $this->errorResponse('This promo code has reached its global usage limit.', Response::HTTP_BAD_REQUEST);

        // --- MODIFIED: User/Promo Code Usage Limit Check (Independent of Team) ---
        $userGlobalRedemptionCount = PromoCodeRedemption::where('user_id', $user->id)
            ->where('promo_code_id', $promoCode->id)
            // ->where('team_id', $teamId) // <-- REMOVE team_id from this specific check
            ->count();

        if ($userGlobalRedemptionCount >= $promoCode->max_uses_per_user) {
            // If max_uses_per_user is 1, this message is appropriate.
            // If it could be > 1, adjust message.
            return $this->errorResponse(
                'You have already used this promo code.',
                Response::HTTP_BAD_REQUEST
            );
        }
        // --- END MODIFICATION ---

        // Still check if this specific team has already had THIS promo code applied,
        // even if the user hasn't reached their global limit for the code.
        // This prevents applying the same promo to the same team twice if somehow possible.
        $teamSpecificRedemptionCount = PromoCodeRedemption::where('promo_code_id', $promoCode->id)
            ->where('team_id', $teamId)
            ->count();
        if ($teamSpecificRedemptionCount > 0) {
            return $this->errorResponse('This promo code has already been applied to this specific team.', Response::HTTP_BAD_REQUEST);
        }


        try {
            $actualExpiryDate = null;

            DB::transaction(function () use ($user, $promoCode, $teamId, $team, &$actualExpiryDate) {
                PromoCodeRedemption::create([
                    'user_id' => $user->id,
                    'promo_code_id' => $promoCode->id,
                    'team_id' => $teamId, // Still record which team it was applied to
                    'redeemed_at' => now()
                ]);
                $promoCode->increment('use_count'); // Global use count
                $actualExpiryDate = $team->grantPromoAccess();
                Log::info("Promo code {$promoCode->code} redeemed by User ID {$user->id} for Team ID {$teamId}. Access expires: {$actualExpiryDate->toIso8601String()}");
            });

            $durationString = "for a limited time";
            if ($actualExpiryDate) {
                $daysGranted = Carbon::now()->diffInDays($actualExpiryDate, false);
                if ($daysGranted < 0) $daysGranted = 0;
                $durationString = $this->getHumanReadableDuration((int) round($daysGranted));
            }

            return $this->successResponse(
                ['access_expires_at' => $actualExpiryDate?->toISOString()],
                'Promo code redeemed successfully! Access for team ' . $team->name . ' granted ' . $durationString . '.'
            );

        } catch (\Exception $e) {
            Log::error("Promo redemption failed: User {$user->id}, Code: {$promoCode->code}, Team: {$teamId}, Error: " . $e->getMessage());
            return $this->errorResponse('Failed to redeem promo code.', Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}

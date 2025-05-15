<?php

namespace App\Http\Controllers\Api\V1; // Ensure correct namespace

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponseTrait; // <-- USE TRAIT
use App\Models\PromoCode;
use App\Models\PromoCodeRedemption;
use App\Models\Team;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Http\Response;

class PromoCodeController extends Controller // Name for User actions
{
    use ApiResponseTrait; // <-- INCLUDE TRAIT

    public function redeem(Request $request)
    {
        $user = $request->user();
        $validator = Validator::make($request->all(), [
            'code' => 'required|string',
            'team_id' => 'required|integer|exists:teams,id',
        ]);
        if ($validator->fails()) return $this->validationErrorResponse($validator);

        $codeString = strtoupper($request->input('code'));
        $teamId = $request->input('team_id');

        $promoCode = PromoCode::where('code', $codeString)->first();
        if (!$promoCode) return $this->notFoundResponse('Invalid promo code.');

        $team = Team::find($teamId);
        if (!$team) return $this->notFoundResponse('Team not found.');
        if ($team->user_id !== $user->id) return $this->forbiddenResponse('You do not own this team.');
        if ($team->hasActiveAccess()) return $this->errorResponse('This team already has active access.', Response::HTTP_CONFLICT);

        // Code status & limits checks
        if (!$promoCode->is_active) return $this->errorResponse('This promo code is not active.', Response::HTTP_BAD_REQUEST);
        if ($promoCode->expires_at && $promoCode->expires_at->isPast()) return $this->errorResponse('This promo code has expired.', Response::HTTP_BAD_REQUEST);
        if ($promoCode->hasReachedMaxUses()) return $this->errorResponse('Promo code usage limit reached.', Response::HTTP_BAD_REQUEST);

        // User/Team usage limit check
        $redemptionCount = PromoCodeRedemption::where('user_id', $user->id)
            ->where('promo_code_id', $promoCode->id)
            ->where('team_id', $teamId)->count();
        if ($redemptionCount >= $promoCode->max_uses_per_user) {
            return $this->errorResponse('You have already used this promo code for this team.', Response::HTTP_BAD_REQUEST);
        }

        try {
            DB::transaction(function () use ($user, $promoCode, $teamId, $team) {
                PromoCodeRedemption::create([ /* ... data ... */
                    'user_id' => $user->id, 'promo_code_id' => $promoCode->id,
                    'team_id' => $teamId, 'redeemed_at' => now()
                ]);
                $promoCode->increment('use_count');
                $team->grantPromoAccess();
                Log::info("Promo code {$promoCode->code} redeemed by User ID {$user->id} for Team ID {$teamId}");
            });
            return $this->successResponse(null, 'Promo code redeemed successfully! Access granted for team ' . $team->name . '.');
        } catch (\Exception $e) {
            Log::error("Promo redemption failed: User {$user->id}, Code: {$promoCode->code}, Team: {$teamId}, Error: " . $e->getMessage());
            return $this->errorResponse('Failed to redeem promo code.', Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}

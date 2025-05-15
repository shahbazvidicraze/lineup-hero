<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponseTrait; // <-- USE TRAIT
use App\Models\Player;
use App\Models\Position;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Response;

class PlayerPreferenceController extends Controller
{
    use ApiResponseTrait; // <-- INCLUDE TRAIT

    public function show(Request $request, Player $player)
    {
        if (!$player->team || $request->user()->id !== $player->team->user_id) {
            return $this->forbiddenResponse('You do not manage this player.');
        }

        $player->load(['preferredPositions:id,name', 'restrictedPositions:id,name']);
        $responseData = [
            'player_id' => $player->id,
            // Pluck IDs, or client can map from full objects if preferred
            'preferred_positions' => $player->preferredPositions->pluck('id'),
            'restricted_positions' => $player->restrictedPositions->pluck('id'),
        ];
        return $this->successResponse($responseData, 'Player preferences retrieved.');
    }

    public function store(Request $request, Player $player)
    {
        if (!$player->team || $request->user()->id !== $player->team->user_id) {
            return $this->forbiddenResponse('You do not manage this player.');
        }

        $validator = Validator::make($request->all(), [
            'preferred_position_ids' => 'present|array',
            'preferred_position_ids.*' => 'integer|exists:positions,id',
            'restricted_position_ids' => 'present|array',
            'restricted_position_ids.*' => 'integer|exists:positions,id',
        ]);
        if ($validator->fails()) return $this->validationErrorResponse($validator);

        $preferredIds = $request->input('preferred_position_ids', []);
        $restrictedIds = $request->input('restricted_position_ids', []);

        if (!empty(array_intersect($preferredIds, $restrictedIds))) {
            return $this->errorResponse('A position cannot be both preferred and restricted.', Response::HTTP_UNPROCESSABLE_ENTITY, ['conflicting_ids' => array_intersect($preferredIds, $restrictedIds)]);
        }

        $outPosition = Position::where('name', 'OUT')->first();
        if ($outPosition && (in_array($outPosition->id, $preferredIds) || in_array($outPosition->id, $restrictedIds))) {
            return $this->errorResponse('The "OUT" position cannot be set as a preference.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            DB::transaction(function () use ($player, $preferredIds, $restrictedIds) {
                $prefsToSync = [];
                foreach ($preferredIds as $id) { $prefsToSync[$id] = ['preference_type' => 'preferred']; }
                foreach ($restrictedIds as $id) { $prefsToSync[$id] = ['preference_type' => 'restricted']; }

                $player->belongsToMany(Position::class, 'player_position_preferences', 'player_id', 'position_id')
                    ->withPivot('preference_type')->sync($prefsToSync);
            });

            $player->load(['preferredPositions:id,name', 'restrictedPositions:id,name']);
            $responseData = [
                'player_id' => $player->id,
                'preferred_positions' => $player->preferredPositions->pluck('id'),
                'restricted_positions' => $player->restrictedPositions->pluck('id'),
            ];
            return $this->successResponse($responseData, 'Player preferences updated successfully.');

        } catch (\Exception $e) {
            Log::error("Error updating player preferences for Player ID {$player->id}: " . $e->getMessage());
            return $this->errorResponse('Failed to update preferences.', Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}

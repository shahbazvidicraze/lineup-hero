<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Player;
use App\Models\Position;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB; // Use DB facade for transaction

class PlayerPreferenceController extends Controller
{
    /**
     * Get the preferences for a specific player.
     * Route: GET /players/{player}/preferences
     */
    public function show(Request $request, Player $player)
    {
        // Authorization: Ensure the user owns the team the player belongs to
        if ($request->user()->id !== $player->team->user_id) {
            return response()->json(['error' => 'Forbidden: You do not manage this player'], 403);
        }

        // Eager load the related positions
        $player->load(['preferredPositions:id,name', 'restrictedPositions:id,name']);

        // Format the response (optional, but can be cleaner for frontend)
        $response = [
            'player_id' => $player->id,
            'preferred_positions' => $player->preferredPositions->pluck('id'), // Send only IDs back or full objects?
            'restricted_positions' => $player->restrictedPositions->pluck('id'),
        ];
        // Or return the player object directly: return response()->json($player);

        return response()->json($response);
    }


    /**
     * Store or update preferences for a specific player.
     * This will replace existing preferences for the player.
     * Route: POST /players/{player}/preferences
     */
    public function store(Request $request, Player $player)
    {
         // Authorization: Ensure the user owns the team the player belongs to
        if ($request->user()->id !== $player->team->user_id) {
            return response()->json(['error' => 'Forbidden: You do not manage this player'], 403);
        }

         $validator = Validator::make($request->all(), [
            'preferred_position_ids' => 'present|array', // Use 'present' to ensure key exists, even if empty array
            'preferred_position_ids.*' => 'integer|exists:positions,id',
            'restricted_position_ids' => 'present|array',
            'restricted_position_ids.*' => 'integer|exists:positions,id',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $preferredIds = $request->input('preferred_position_ids', []);
        $restrictedIds = $request->input('restricted_position_ids', []);

        // Validation: Ensure a position isn't both preferred and restricted
        $intersection = array_intersect($preferredIds, $restrictedIds);
        if (!empty($intersection)) {
            return response()->json([
                'error' => 'A position cannot be both preferred and restricted.',
                'conflicting_ids' => $intersection
            ], 422);
        }

        // Validation: Ensure 'OUT' position cannot be preferred or restricted (if needed)
        $outPosition = Position::where('name', 'OUT')->first();
        if ($outPosition) {
            if (in_array($outPosition->id, $preferredIds) || in_array($outPosition->id, $restrictedIds)) {
                 return response()->json(['error' => 'The "OUT" position cannot be set as a preference.'], 422);
            }
        }


        try {
            // Use a transaction to ensure atomicity
            DB::transaction(function () use ($player, $preferredIds, $restrictedIds) {
                // Clear existing preferences for this player
                $player->positionPreferences()->delete(); // Or $player->preferredPositions()->detach(); $player->restrictedPositions()->detach();

                // Build the data for new preferences
                $preferencesData = [];
                foreach ($preferredIds as $id) {
                    $preferencesData[] = ['position_id' => $id, 'preference_type' => 'preferred'];
                }
                foreach ($restrictedIds as $id) {
                     $preferencesData[] = ['position_id' => $id, 'preference_type' => 'restricted'];
                }

                // Insert new preferences if any exist
                if (!empty($preferencesData)) {
                    $player->positionPreferences()->createMany($preferencesData);
                }
            });

             // Reload preferences to return the updated state
             $player->load(['preferredPositions:id,name', 'restrictedPositions:id,name']);
             $response = [
                 'player_id' => $player->id,
                 'preferred_positions' => $player->preferredPositions->pluck('id'),
                 'restricted_positions' => $player->restrictedPositions->pluck('id'),
             ];
             return response()->json($response);

        } catch (\Exception $e) {
            // Log::error("Error updating player preferences: " . $e->getMessage());
             return response()->json(['error' => 'Failed to update preferences. Please try again.'], 500);
        }
    }
}

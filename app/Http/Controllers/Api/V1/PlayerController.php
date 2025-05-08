<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Player;
use App\Models\Team; // Import Team model
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class PlayerController extends Controller
{
    // Note: index() and show() might be better handled via TeamController
    // (e.g., GET /teams/{team}/players) if you primarily view players in team context.
    // We'll implement store, update, destroy here as per routes defined.

    /**
     * Store a newly created player for a specific team.
     * Route: POST /teams/{team}/players
     */
    public function store(Request $request, Team $team) // Inject the Team model
    {
        // Authorization: Ensure the user owns the team they're adding a player to
        if ($request->user()->id !== $team->user_id) {
            return response()->json(['error' => 'Forbidden: You do not own this team'], 403);
        }

        $validator = Validator::make($request->all(), [
            'first_name' => 'required|string|max:100',
            'last_name' => 'required|string|max:100',
            'jersey_number' => 'nullable|string|max:10', // Can be non-numeric e.g., "00"
            'email' => [
                'nullable',
                'email',
                'max:255',
                Rule::unique('players', 'email')->where(function ($query) use ($team) {
                    // Allow duplicate emails *across different teams* if needed,
                    // but often email should be globally unique or unique within the app.
                    // For simplicity now, make it unique in the players table.
                    // Adjust if players can truly share emails across the system.
                    return $query; // This makes it unique across all players
                }),
            ],
            // If adding preferences during creation (optional):
            // 'preferred_position_ids' => 'nullable|array',
            // 'preferred_position_ids.*' => 'exists:positions,id',
            // 'restricted_position_ids' => 'nullable|array',
            // 'restricted_position_ids.*' => 'exists:positions,id',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $validatedData = $validator->validated();
        // We don't need team_id in validated data as we use the relationship
        // unset($validatedData['team_id']); // Not needed if not in validation rules

        $player = $team->players()->create($validatedData);

        // Handle preferences if sent during creation (more complex, add later if needed)
        // if (!empty($validatedData['preferred_position_ids'])) { ... }
        // if (!empty($validatedData['restricted_position_ids'])) { ... }

        return response()->json($player, 201);
    }

    public function show(Request $request, Player $player)
    {
        // Authorization: Ensure user owns the team this player belongs to
        // Or if an Admin should access this via a different route/check
        $user = $request->user();
        if (!$user || !$player->team || $user->id !== $player->team->user_id) {
             // Add Admin check here if admins should also use this route
             if (!$request->user('api_admin')) {
                 return response()->json(['error' => 'Forbidden: You cannot access this player.'], 403);
             }
        }

        // Load relationships that might be useful
        $player->load(['team:id,name', 'preferredPositions:id,name', 'restrictedPositions:id,name']);

        // The 'stats' accessor on the Player model will automatically calculate
        // and include stats when the $player object is serialized to JSON.

        return response()->json($player);
    }


    /**
     * Update the specified player.
     * Route: PUT /players/{player}
     */
    public function update(Request $request, Player $player)
    {
         // Authorization: Ensure the user owns the team the player belongs to
        if ($request->user()->id !== $player->team->user_id) {
            return response()->json(['error' => 'Forbidden: You do not manage this player'], 403);
        }

        $validator = Validator::make($request->all(), [
             'first_name' => 'sometimes|required|string|max:100',
             'last_name' => 'sometimes|required|string|max:100',
             'jersey_number' => 'nullable|string|max:10',
             'email' => [
                'nullable',
                'email',
                'max:255',
                // Ensure email is unique, ignoring the current player's record
                Rule::unique('players', 'email')->ignore($player->id),
            ],
            // Allow changing teams? Probably not here. Team changes are complex.
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $player->update($validator->validated());

        return response()->json($player);
    }

    /**
     * Remove the specified player from storage.
     * Route: DELETE /players/{player}
     */
    public function destroy(Request $request, Player $player)
    {
        // Authorization: Ensure the user owns the team the player belongs to
        if ($request->user()->id !== $player->team->user_id) {
            return response()->json(['error' => 'Forbidden: You do not manage this player'], 403);
        }

        // Frontend should have a confirmation dialog

        // Delete associated preferences first if cascade on delete isn't reliable/set
        // $player->positionPreferences()->delete(); // Or use the relationship

        $player->delete();

        return response()->json(null, 204); // No Content success response
    }
}

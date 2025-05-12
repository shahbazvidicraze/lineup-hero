<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Game;
use App\Models\Player;
use App\Models\Settings;
use App\Models\Team;
use Illuminate\Auth\Access\AuthorizationException; // Import exception
use Illuminate\Foundation\Auth\Access\AuthorizesRequests; // <-- IMPORT THE TRAIT
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class GameController extends Controller
{
    use AuthorizesRequests; // <-- USE THE TRAIT

    /**
     * Check if the authenticated user owns the team associated with the game.
     * Note: Direct checks often replaced by Policy/Gate checks now.
     */
    private function authorizeUserForGame(Game $game): bool
    {
        $user = Auth::guard('api_user')->user();
        $game->loadMissing('team');
        return $user && $game->team && $user->id === $game->team->user_id;
    }

    /**
     * Check if the authenticated user owns the team before creating a game for it.
     * Note: Direct checks often replaced by Policy/Gate checks now.
     */
    private function authorizeUserForTeam(Team $team): bool
    {
        $user = Auth::guard('api_user')->user();
        return $user && $user->id === $team->user_id;
    }

    /**
     * Display a listing of games for a specific team.
     */
    public function index(Request $request, Team $team)
    {
        // Use policy or direct check
        if (!$this->authorizeUserForTeam($team)) { // Keep direct check or replace with policy if preferred
            return response()->json(['error' => 'Forbidden: You do not manage this team.'], 403);
        }
        $games = $team->games()->orderBy('game_date', 'desc')->get(['id', 'team_id', 'opponent_name', 'game_date', 'innings', 'location_type', 'submitted_at']);
        return response()->json($games);
    }

    /**
     * Store a newly created game for a specific team.
     */
    public function store(Request $request, Team $team)
    {
        // Use policy or direct check
        if (!$this->authorizeUserForTeam($team)) { // Keep direct check or replace with policy if preferred
            return response()->json(['error' => 'Forbidden: You do not manage this team.'], 403);
        }

        $validator = Validator::make($request->all(), [
            'opponent_name' => 'nullable|string|max:255',
            'game_date' => 'required|date',
            'innings' => 'required|integer|min:1|max:20',
            'location_type' => ['required', Rule::in(['home', 'away'])],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $validatedData = $validator->validated();
        $validatedData['lineup_data'] = (object) []; // Initialize empty object

        $game = $team->games()->create($validatedData);
        $game->load('team:id,name');
        return response()->json($game, 201);
    }

    /**
     * Display the specified game.
     */
    public function show(Request $request, Game $game)
    {
        // Example using policy check for 'view' action
        try {
            $this->authorize('view', $game); // Requires 'view' method in GamePolicy
        } catch (AuthorizationException $e) {
            return response()->json(['error' => 'Forbidden: Cannot view this game.'], 403);
        }

        $game->load(['team', 'team.players']); // Load necessary details
        return response()->json($game);
    }

    /**
     * Update the specified game details.
     */
    public function update(Request $request, Game $game)
    {
        // Example using policy check for 'update' action
        try {
            $this->authorize('update', $game); // Requires 'update' method in GamePolicy
        } catch (AuthorizationException $e) {
            return response()->json(['error' => 'Forbidden: Cannot update this game.'], 403);
        }

        $validator = Validator::make($request->all(), [
            'opponent_name' => 'sometimes|required|string|max:255',
            'game_date' => 'sometimes|required|date',
            'innings' => 'sometimes|required|integer|min:1|max:20',
            'location_type' => ['sometimes','required', Rule::in(['home', 'away'])],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $game->update($validator->validated());
        $game->load('team:id,name');
        return response()->json($game);
    }

    /**
     * Remove the specified game from storage.
     */
    public function destroy(Request $request, Game $game)
    {
        // Example using policy check for 'delete' action
        try {
            $this->authorize('delete', $game); // Requires 'delete' method in GamePolicy
        } catch (AuthorizationException $e) {
            return response()->json(['error' => 'Forbidden: Cannot delete this game.'], 403);
        }

        $game->delete();
        return response()->json(null, 204);
    }

    // --- Lineup Handling Methods ---

    /**
     * Get the current lineup structure for a game.
     */
    public function getLineup(Request $request, Game $game)
    {
        // Example using policy check for 'view' action
        try {
            $this->authorize('view', $game);
        } catch (AuthorizationException $e) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $game->load([
            'team.players' => function ($query) {
                $query->select(['id', 'team_id', 'first_name', 'last_name', 'jersey_number', 'email', 'phone'])
                    ->with(['preferredPositions:id,name', 'restrictedPositions:id,name']); // Load preferences here
            }
        ]);

        // Append stats automatically via accessor when JSON serialized
        $response = [
            'game_id' => $game->id,
            'innings' => $game->innings,
            'players' => $game->team->players, // Includes preferences, stats added on serialization
            'lineup' => $game->lineup_data ?? (object)[],
            'submitted_at' => $game->submitted_at,
        ];

        return response()->json($response);
    }

    /**
     * Save/Update the lineup data for a game.
     */
    public function updateLineup(Request $request, Game $game)
    {
        // Example using policy check for 'update' action (or a specific 'updateLineup' action)
        try {
            $this->authorize('update', $game); // Assume updating game allows lineup update
        } catch (AuthorizationException $e) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $validator = Validator::make($request->all(), [
            'lineup' => 'required|array',
            'lineup.*.player_id' => 'required|integer|exists:players,id,team_id,' . $game->team_id, // Ensure player belongs to this team
            'lineup.*.innings' => 'required|array',
            'lineup.*.innings.*' => 'nullable|string|exists:positions,name',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $lineupData = $request->input('lineup');

        // --- Business Logic Validation (Duplicate Position Check) ---
        $inningsCount = $game->innings;
        for ($i = 1; $i <= $inningsCount; $i++) {
            $inningPositions = [];
            foreach ($lineupData as $playerLineup) {
                $inningStr = (string)$i;
                if (isset($playerLineup['innings'][$inningStr])) {
                    $position = $playerLineup['innings'][$inningStr];
                    if (!empty($position) && is_string($position) && strtoupper($position) !== 'OUT' && strtoupper($position) !== 'BENCH') {
                        $upperPos = strtoupper($position);
                        if (isset($inningPositions[$upperPos])) {
                            return response()->json(['error' => "Duplicate position '{$position}' found in inning {$i}."], 422);
                        }
                        $inningPositions[$upperPos] = true;
                    }
                }
            }
        }
        // --- End Business Logic Validation ---

        $game->lineup_data = $lineupData;
        $game->submitted_at = now();
        $game->save();

        return response()->json(['message' => 'Lineup updated successfully.', 'lineup' => $game->lineup_data, 'submitted_at' => $game->submitted_at]);
    }


    /**
     * Trigger the auto-complete feature by calling the optimization service.
     */
    public function autocompleteLineup(Request $request, Game $game)
    {
        // Example using policy check for 'update' action (or a specific 'optimizeLineup' action)
        try {
            $this->authorize('update', $game); // Assume updating game allows optimization
        } catch (AuthorizationException $e) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        // --- Input Validation ---
        $validator = Validator::make($request->all(), [
            'fixed_assignments' => 'present|array',
            'fixed_assignments.*' => 'sometimes|array',
            'fixed_assignments.*.*' => 'sometimes|string|exists:positions,name',
            'players_in_game' => 'required|array|min:1',
            // Ensure players exist and belong to the game's team
            'players_in_game.*' => ['integer', Rule::exists('players', 'id')->where('team_id', $game->team_id)],
        ]);
        if ($validator->fails()) { return response()->json(['errors' => $validator->errors()], 422); }

        $fixedAssignmentsInput = $request->input('fixed_assignments', []);
        $playersInGameIds = $request->input('players_in_game');

        // --- Data Preparation ---
        try {
            $players = Player::with(['preferredPositions:id,name', 'restrictedPositions:id,name', 'team'])
                ->whereIn('id', $playersInGameIds)
                ->get(); // Already validated they belong to team

            $actualCounts = [];
            $playerPreferences = [];
            foreach ($players as $player) {
                $stats = $player->calculateHistoricalStats(); // Uses accessor logic now
                $actualCounts[(string)$player->id] = $stats['position_counts'] ?? (object)[];
                $playerPreferences[(string)$player->id] = [
                    'preferred' => $player->preferredPositions->pluck('name')->toArray(),
                    'restricted' => $player->restrictedPositions->pluck('name')->toArray(),
                ];
            }

            $formattedFixedAssignments = [];
            if (is_array($fixedAssignmentsInput) && !empty($fixedAssignmentsInput)) {
                foreach ($fixedAssignmentsInput as $playerId => $assignments) {
                    if (is_array($assignments)) {
                        $formattedFixedAssignments[(string)$playerId] = $assignments;
                    }
                }
            }
            $finalFixedAssignments = empty($formattedFixedAssignments) ? (object)[] : $formattedFixedAssignments;

            // --- Prepare Payload ---
            $payload = [
                'players' => collect($playersInGameIds)->map(fn($id) => (string)$id)->toArray(),
                'fixed_assignments' => $finalFixedAssignments,
                'actual_counts' => $actualCounts,
                'game_innings' => $game->innings,
                'player_preferences' => $playerPreferences,
            ];

            $settings = Settings::instance();
            $optimizerUrl = $settings->optimizer_service_url;
            $optimizerTimeout = $settings->optimizer_timeout;
            // $optimizerUrl = config('services.lineup_optimizer.url');
            // $optimizerTimeout = config('services.lineup_optimizer.timeout', 60);
            if (!$optimizerUrl) { throw new \Exception('Optimizer service URL not configured.'); }

            // --- Call Python Service ---
            Log::info("Sending payload to optimizer: ", ['game_id' => $game->id, 'player_ids' => $playersInGameIds]); // Less verbose log
            $response = Http::timeout($optimizerTimeout)->acceptJson()->post($optimizerUrl, $payload);

            // --- Process Response ---
            if ($response->successful()) {
                $optimizedLineupData = $response->json();
                Log::info("Received optimized lineup.", ['game_id' => $game->id]);

                // --- Validate received data structure ---
                if (!is_array($optimizedLineupData)) { throw new \Exception('Optimizer returned invalid data format.'); }
                // Add deeper validation if necessary

                // --- Update Game Lineup ---
                $game->lineup_data = $optimizedLineupData;
                $game->submitted_at = now();
                $game->save();

                return response()->json(['message' => 'Lineup optimized and saved successfully.', 'lineup' => $game->lineup_data]);
            } else {
                // Handle optimizer errors
                $errorBody = $response->body();
                Log::error('Lineup optimizer service failed.', ['status' => $response->status(), 'body' => $errorBody, 'game_id' => $game->id]);
                $decodedError = json_decode($errorBody, true);
                $errorMessage = $decodedError['error'] ?? 'Optimization failed.';
                return response()->json(['error' => 'Lineup optimization service failed.', 'details' => $errorMessage], $response->status());
            }
        } catch (\Illuminate\Http\Client\RequestException $e) {
            Log::error('HTTP Request to optimizer service failed: ' . $e->getMessage(), ['game_id' => $game->id]);
            return response()->json(['error' => 'Could not connect to the lineup optimizer service.'], 503);
        } catch (\Exception $e) {
            Log::error('Autocomplete Error: ' . $e->getMessage(), ['exception' => $e, 'game_id' => $game->id]);
            return response()->json(['error' => 'An internal error occurred during lineup optimization.'], 500);
        }
    }


    /**
     * Provide JSON data required for the client (Flutter) to generate a PDF lineup.
     * Authorization checks if the user owns the game AND the team has active access.
     * Route: GET /games/{game}/pdf
     */
    public function getLineupPdfData(Request $request, Game $game) // Inject Request
    {
        // --- AUTHORIZATION CHECK ---
        // Uses GamePolicy@viewPdfData method via the AuthorizesRequests trait
        try {
            $this->authorize('viewPdfData', $game);
        } catch (AuthorizationException $e) {
            return response()->json(['error' => 'Access Denied. Ensure the team has active access (via payment or promo code).'], 403);
        }
        // --- END AUTHORIZATION CHECK ---

        // --- Proceed with data fetching if authorized ---
        $lineupData = $game->lineup_data;
        if (empty($lineupData) || (!is_array($lineupData) && !is_object($lineupData))) {
            return response()->json(['error' => 'No valid lineup data available for this game.'], 404);
        }
        $lineupArray = is_object($lineupData) ? json_decode(json_encode($lineupData), true) : $lineupData;
        if (!is_array($lineupArray)) {
            Log::error("PDF Data: Failed conversion Game ID {$game->id}.");
            return response()->json(['error' => 'Invalid lineup data format.'], 500);
        }

        $playerIds = collect($lineupArray)->pluck('player_id')->filter()->unique()->toArray();
        $playersMap = [];
        if (!empty($playerIds)) {
            $playersMap = Player::whereIn('id', $playerIds)
                ->select(['id', 'first_name', 'last_name', 'jersey_number'])
                ->get()
                ->keyBy('id')
                ->map(fn ($p) => ['id'=>$p->id, 'full_name'=>$p->full_name, 'jersey_number'=>$p->jersey_number])
                ->all();
        }

        $game->loadMissing('team:id,name');

        $responseData = [
            'game_details' => [
                'id' => $game->id,
                'team_name' => $game->team?->name ?? 'N/A',
                'opponent_name' => $game->opponent_name ?? 'N/A',
                'game_date' => $game->game_date?->toISOString(),
                'innings_count' => $game->innings,
                'location_type' => $game->location_type
            ],
            'players_info' => (object) $playersMap, // Send as object keyed by player ID
            'lineup_assignments' => $lineupArray
        ];

        return response()->json($responseData);
    }

} // End GameController Class

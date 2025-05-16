<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Game;
use App\Http\Traits\ApiResponseTrait; // <-- USE TRAIT
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
use Illuminate\Http\Response; // For status codes

class GameController extends Controller
{
    use ApiResponseTrait, AuthorizesRequests; // <-- USE THE TRAIT

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
        try { $this->authorize('viewAny', [Game::class, $team]); } // Assumes a policy method
        catch (AuthorizationException $e) { return $this->forbiddenResponse('You do not manage this team or cannot view its games.'); }

        $games = $team->games()->orderBy('game_date', 'desc')
            ->get(['id', 'team_id', 'opponent_name', 'game_date', 'innings', 'location_type', 'submitted_at']);
        return $this->successResponse($games, 'Games retrieved successfully.');
    }

    public function store(Request $request, Team $team)
    {
        try { $this->authorize('create', [Game::class, $team]); }
        catch (AuthorizationException $e) { return $this->forbiddenResponse('You do not manage this team or cannot create games.'); }

        $validator = Validator::make($request->all(), [ /* ... validation rules ... */
            'opponent_name' => 'nullable|string|max:255', 'game_date' => 'required|date',
            'innings' => 'required|integer|min:1|max:20', 'location_type' => ['required', Rule::in(['home', 'away'])],
        ]);
        if ($validator->fails()) return $this->validationErrorResponse($validator);

        $validatedData = $validator->validated();
        $validatedData['lineup_data'] = (object) [];
        $game = $team->games()->create($validatedData);
        $game->load('team:id,name');
        return $this->successResponse($game, 'Game created successfully.', Response::HTTP_CREATED);
    }

    public function show(Request $request, Game $game)
    {
        try { $this->authorize('view', $game); }
        catch (AuthorizationException $e) { return $this->forbiddenResponse('Cannot view this game.'); }

        $game->load(['team:id,name', 'team.players' => function($q){
            $q->select(['id', 'team_id', 'first_name', 'last_name', 'jersey_number', 'email']);
        }]);
        return $this->successResponse($game);
    }

    public function update(Request $request, Game $game)
    {
        try { $this->authorize('update', $game); }
        catch (AuthorizationException $e) { return $this->forbiddenResponse('Cannot update this game.'); }

        $validator = Validator::make($request->all(), [ /* ... validation rules ... */
            'opponent_name' => 'sometimes|required|string|max:255', 'game_date' => 'sometimes|required|date',
            'innings' => 'sometimes|required|integer|min:1|max:20',
            'location_type' => ['sometimes','required', Rule::in(['home', 'away'])],
        ]);
        if ($validator->fails()) return $this->validationErrorResponse($validator);

        $game->update($validator->validated());
        $game->load('team:id,name');
        return $this->successResponse($game, 'Game updated successfully.');
    }

    public function destroy(Request $request, Game $game)
    {
        try { $this->authorize('delete', $game); }
        catch (AuthorizationException $e) { return $this->forbiddenResponse('Cannot delete this game.'); }

        $game->delete();
        return $this->successResponse(null, 'Game deleted successfully.', Response::HTTP_OK, false);
    }

    public function getLineup(Request $request, Game $game)
    {
        try { $this->authorize('view', $game); }
        catch (AuthorizationException $e) { return $this->forbiddenResponse('Cannot view this game lineup.'); }

        $game->load(['team.players' => fn($q) =>
        $q->select(['id','team_id','first_name','last_name','jersey_number','email'])
            ->with(['preferredPositions:id,name', 'restrictedPositions:id,name'])
        ]);
        $responseData = [ /* ... data as before ... */
            'game_id' => $game->id, 'innings' => $game->innings, 'players' => $game->team->players,
            'lineup' => $game->lineup_data ?? (object)[], 'submitted_at' => $game->submitted_at,
        ];
        return $this->successResponse($responseData, 'Lineup data retrieved.');
    }

    public function updateLineup(Request $request, Game $game)
    {
        try { $this->authorize('update', $game); }
        catch (AuthorizationException $e) { return $this->forbiddenResponse('Cannot update this game lineup.'); }

        $validator = Validator::make($request->all(), [ /* ... validation ... */
            'lineup' => 'required|array', 'lineup.*.player_id' => 'required|integer|exists:players,id,team_id,'.$game->team_id,
            'lineup.*.innings' => 'required|array', 'lineup.*.innings.*' => 'nullable|string|exists:positions,name',
        ]);
        if ($validator->fails()) return $this->validationErrorResponse($validator);
        // ... (duplicate position check logic as before) ...
        // (If duplicate check fails): return $this->errorResponse("Duplicate position '{$position}' found in inning {$i}.", Response::HTTP_UNPROCESSABLE_ENTITY);


        $game->lineup_data = $request->input('lineup');
        $game->submitted_at = now();
        $game->save();
        return $this->successResponse(
            ['lineup' => $game->lineup_data, 'submitted_at' => $game->submitted_at],
            'Lineup updated successfully.'
        );
    }

    public function autocompleteLineup(Request $request, Game $game)
    {
        try { $this->authorize('update', $game); } // Or a specific 'optimizeLineup' permission
        catch (AuthorizationException $e) { return $this->forbiddenResponse('Cannot optimize lineup for this game.'); }

        $validator = Validator::make($request->all(), [ /* ... validation ... */
            'fixed_assignments' => 'present|array',
            'players_in_game' => 'required|array|min:1',
            'players_in_game.*' => ['integer', Rule::exists('players', 'id')->where('team_id', $game->team_id)],
        ]);
        if ($validator->fails()) { return $this->validationErrorResponse($validator); }

        // ... (Data Preparation for Python as before) ...
        // ... (Payload creation as before) ...
        $actualCounts = []; $playerPreferences = []; // Initialize
        $players = Player::with(['preferredPositions:id,name', 'restrictedPositions:id,name'])->whereIn('id', $request->input('players_in_game'))->get();
        foreach ($players as $player) {
            $stats = $player->stats; // Uses accessor
            $actualCounts[(string)$player->id] = $stats['position_counts'] ?? (object)[];
            $playerPreferences[(string)$player->id] = ['preferred' => $player->preferredPositions->pluck('name')->toArray(), 'restricted' => $player->restrictedPositions->pluck('name')->toArray()];
        }
        $finalFixedAssignments = empty($request->input('fixed_assignments',[])) ? (object)[] : $request->input('fixed_assignments',[]);
        $payload = ['players' => collect($request->input('players_in_game'))->map(fn($id)=>(string)$id)->toArray(), /* + other data */
            'fixed_assignments' => $finalFixedAssignments, 'actual_counts' => $actualCounts,
            'game_innings' => $game->innings, 'player_preferences' => $playerPreferences];


        try {
            $optimizerUrl = Settings::instance()->optimizer_service_url; // Using Settings model
            $optimizerTimeout = config('services.lineup_optimizer.timeout', 60);
            if (!$optimizerUrl) { throw new \Exception('Optimizer service URL not configured.'); }

            Log::info("Sending payload to optimizer: ", ['game_id' => $game->id]);
            $response = Http::timeout($optimizerTimeout)->acceptJson()->post($optimizerUrl, $payload);

            if ($response->successful()) {
                $optimizedLineupData = $response->json();
                if (!is_array($optimizedLineupData) /* basic validation */) {
                    throw new \Exception('Optimizer returned invalid data format.');
                }
                $game->lineup_data = $optimizedLineupData;
                $game->submitted_at = now();
                $game->save();
                return $this->successResponse(['lineup' => $game->lineup_data], 'Lineup optimized and saved successfully.');
            } else {
                $errorBody = $response->json() ?? ['error' => 'Unknown optimizer error', 'details' => $response->body()];
                Log::error('Lineup optimizer service failed.', ['status' => $response->status(), 'body' => $errorBody, 'game_id' => $game->id]);
                return $this->errorResponse(
                    'Lineup optimization service failed.',
                    $response->status(),
                    $errorBody['error'] ?? ($errorBody['details'] ?? 'Optimizer service error')
                );
            }
        } catch (\Illuminate\Http\Client\RequestException $e) {
            Log::error('HTTP Request to optimizer service failed: ' . $e->getMessage(), ['game_id' => $game->id]);
            return $this->errorResponse('Could not connect to the lineup optimizer service.', Response::HTTP_SERVICE_UNAVAILABLE);
        } catch (\Exception $e) {
            Log::error('Autocomplete Error: ' . $e->getMessage(), ['exception' => $e, 'game_id' => $game->id]);
            return $this->errorResponse('An internal error occurred during lineup optimization.', Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function getLineupPdfData(Request $request, Game $game)
    {
        try {
            // Policy check: GamePolicy@viewPdfData
            $this->authorize('viewPdfData', $game);
        } catch (AuthorizationException $e) {
            // If authorization fails, check if it's due to expiry
            $game->loadMissing('team'); // Ensure team is loaded for the check below

            if ($game->team && $game->team->user_id === $request->user()->id) { // User owns the team
                if ($game->team->hasAccessExpired()) {
                    return $this->forbiddenResponse('Your team\'s access to PDF generation has expired. Please renew your subscription or apply a new promo code.');
                } else if (!in_array($game->team->access_status, ['paid_active', 'promo_active'])) {
                    return $this->forbiddenResponse('Access Denied. This team does not have active paid access or a valid promo code applied.');
                }
            }
            // Default forbidden if ownership check also fails or other policy reasons
            return $this->forbiddenResponse('Access Denied. You may not have permission or the team lacks active access.');
        }

        // --- Proceed with data fetching if authorized ---
        // ... (rest of the getLineupPdfData method as before) ...
        $lineupArray = is_object($game->lineup_data) ? json_decode(json_encode($game->lineup_data), true) : $game->lineup_data;
        if (empty($lineupArray) || !is_array($lineupArray)) return $this->notFoundResponse('No valid lineup data for this game.');

        $playerIds = collect($lineupArray)->pluck('player_id')->filter()->unique()->toArray();
        $playersMap = [];
        if (!empty($playerIds)) {
            $playersMap = \App\Models\Player::whereIn('id', $playerIds)
                ->select(['id', 'first_name', 'last_name', 'jersey_number'])
                ->get()
                ->keyBy('id')
                ->map(fn ($p) => ['id'=>$p->id, 'full_name'=>$p->full_name, 'jersey_number'=>$p->jersey_number])
                ->all();
        }

        $game->loadMissing('team:id,name');
        $gameDetails = [
            'id' => $game->id, 'team_name' => $game->team?->name ?? 'N/A',
            'opponent_name' => $game->opponent_name ?? 'N/A',
            'game_date' => $game->game_date?->toISOString(),
            'innings_count' => $game->innings, 'location_type' => $game->location_type
        ];
        $responseData = ['game_details' => $gameDetails, 'players_info' => (object)$playersMap, 'lineup_assignments' => $lineupArray];
        return $this->successResponse($responseData, 'PDF data retrieved successfully.');
    }

    public function getLineupPdfData_old(Request $request, Game $game)
    {
        try { $this->authorize('viewPdfData', $game); }
        catch (AuthorizationException $e) { return $this->forbiddenResponse('Access Denied. Ensure team has active access.'); }

        // ... (Data fetching as before) ...
        $lineupArray = is_object($game->lineup_data) ? json_decode(json_encode($game->lineup_data), true) : $game->lineup_data;
        if (empty($lineupArray) || !is_array($lineupArray)) return $this->notFoundResponse('No valid lineup data for this game.');
        // ... (fetch playerIds, playersMap, gameDetails as before) ...
        $playerIds = collect($lineupArray)->pluck('player_id')->filter()->unique()->toArray();
        $playersMap = empty($playerIds) ? (object)[] : Player::whereIn('id', $playerIds)->select(['id','first_name','last_name','jersey_number'])->get()->keyBy('id')->map(fn($p)=>(['id'=>$p->id, 'full_name'=>$p->full_name, 'jersey_number'=>$p->jersey_number]))->all();
        $game->loadMissing('team:id,name');
        $gameDetails = ['id'=>$game->id, 'team_name'=>$game->team?->name, /* ... */];


        $responseData = ['game_details' => $gameDetails, 'players_info' => (object)$playersMap, 'lineup_assignments' => $lineupArray];
        return $this->successResponse($responseData, 'PDF data retrieved successfully.');
    }

} // End GameController Class

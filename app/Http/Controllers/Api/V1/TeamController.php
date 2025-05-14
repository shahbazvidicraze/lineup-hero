<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Team;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule; // Import Rule

class TeamController extends Controller
{
    /**
     * Display a listing of the resource for the authenticated user.
     */
    public function index(Request $request)
    {
        // Get teams belonging to the currently authenticated user (coach/manager)
        $user = $request->user(); // Gets user via the 'auth:api_user' middleware
        $teams = $user->teams()
                      ->with('organization') // Eager load organization if needed
                      ->when($request->has('season'), function ($query) use ($request) {
                          return $query->where('season', $request->input('season'));
                      })
                      ->when($request->has('year'), function ($query) use ($request) {
                          return $query->where('year', $request->input('year'));
                      })
                      ->orderBy('created_at', 'desc')
//                      ->orderBy('year', 'desc')
//                      ->orderBy('season', 'asc') // Adjust ordering as needed
                      ->get();
        if($teams->count() > 0){
            return response()->json($teams);
        }else{
            return response()->json(['message'=>'No team created yet.']);
        }
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'season' => 'nullable|string|max:50',
            'year' => 'nullable|integer|digits:4',
            'sport_type' => ['required', Rule::in(['baseball', 'softball'])],
            'team_type' => ['required', Rule::in(['travel', 'recreation', 'school'])],
            'age_group' => 'required|string|max:50',
            'city' => 'nullable|string|max:100',
            'state' => 'nullable|string|max:100',
            'organization_id' => 'nullable|exists:organizations,id', // Ensure the org exists if provided
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $user = $request->user(); // Get the authenticated user

        $team = $user->teams()->create($validator->validated());

        // Optionally load the organization relationship if it was set
        if ($team->organization_id) {
            $team->load('organization');
        }

        return response()->json($team, 201); // Return created team with 201 status
    }

    public function show(Request $request, Team $team)
    {
         // Authorization check (ensure user owns the team)
         if ($request->user()->id !== $team->user_id) {
             return response()->json(['error' => 'Forbidden'], 403);
         }

        // Eager load players. The 'stats' accessor handles calculation.
        $team->load(['organization', 'games', 'players' => function ($query) {
            $query->select(['id', 'team_id', 'first_name', 'last_name', 'jersey_number', 'email', 'phone']) // Select specific columns
                  ->orderBy('last_name')
                  ->orderBy('first_name');
        }]);

        // When $team is converted to JSON, the 'stats' accessor on each player model
        // within the loaded relationship will be automatically called.
        return response()->json($team);
    }

    /**
     * Display a listing of players for the specified team, including stats.
     * Route: GET /teams/{team}/players
     */
    public function listPlayers(Request $request, Team $team)
    {
        // Authorization check (user owns team OR is admin, etc.)
        if ($request->user()->id !== $team->user_id) {
             if (!$request->user('api_admin')) { // Example admin check
                 return response()->json(['error' => 'Forbidden'], 403);
             }
        }

        // Retrieve players
        $players = $team->players()
                        ->select(['id', 'team_id', 'first_name', 'last_name', 'jersey_number', 'email', 'phone'])
                        ->orderBy('last_name')
                        ->orderBy('first_name')
                        ->get();

        // When $players collection is converted to JSON, the 'stats' accessor
        // on each player model will be automatically called.
        return response()->json($players);
    }

    public function listPlayers_old(Request $request, Team $team)
    {
        // Authorization: Ensure the user owns the team OR is an Admin
        // Adjust authorization based on who should see the player list.
        // Let's assume only the owning User (coach) for now as per typical flow.
        // If Admins or Orgs need access, you'd adjust this check or handle it in middleware/policies.

        $user = $request->user(); // Get authenticated user from 'auth:api_user' middleware

        if (!$user || $user->id !== $team->user_id) {
            // Check if an admin is trying to access (optional, depending on requirements)
             $admin = $request->user('api_admin'); // Check admin guard
             if (!$admin) { // If not the owner and not an admin
                 return response()->json(['error' => 'Forbidden: You cannot access this team\'s players.'], 403);
             }
             // If admin access is allowed, proceed without the user ownership check
        }


        // Retrieve players for the team
        $playersQuery = $team->players()
                        ->select(['id', 'team_id', 'first_name', 'last_name', 'jersey_number', 'email', 'phone', 'created_at']) // Select desired fields
                        ->orderBy('last_name') // Example sorting
                        ->orderBy('first_name');

        // Optionally add pagination if teams can have many players
        // $players = $playersQuery->paginate($request->input('per_page', 50));
        $players = $playersQuery->get();


        // Append calculated stats to each player if the calculation method exists
        // This relies on the 'getStatsAttribute' accessor we previously added to the Player model
        if (method_exists(\App\Models\Player::class, 'getStatsAttribute')) {
            $players->each(function ($player) {
                $player->stats = $player->stats; // Calculate and append stats
            });
        }


        return response()->json($players);
    }

    /**
     * Display the specified resource.
     */
    public function show_old(Request $request, Team $team) // Use route model binding
    {
        // Basic authorization: Ensure the user owns the team
         if ($request->user()->id !== $team->user_id) {
             return response()->json(['error' => 'Forbidden'], 403);
         }

        // Eager load players and games for the detail view
        $team->load(['players', 'games', 'organization']);

        return response()->json($team);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Team $team)
    {
        // Authorization: Ensure the user owns the team
        if ($request->user()->id !== $team->user_id) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:255', // 'sometimes' means validate only if present
            'season' => 'nullable|string|max:50',
            'year' => 'nullable|integer|digits:4',
            'sport_type' => ['sometimes','required', Rule::in(['baseball', 'softball'])],
            'team_type' => ['sometimes','required', Rule::in(['travel', 'recreation', 'school'])],
            'age_group' => 'sometimes|required|string|max:50',
            'city' => 'nullable|string|max:100',
            'state' => 'nullable|string|max:100',
            'organization_id' => 'nullable|exists:organizations,id',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $team->update($validator->validated());

        // Reload relationships if needed (e.g., organization might have changed)
        $team->load('organization');

        return response()->json($team);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Request $request, Team $team)
    {
        // Authorization: Ensure the user owns the team
        if ($request->user()->id !== $team->user_id) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        // Add confirmation check on frontend - backend performs the delete
        $team->delete(); // This should cascade delete players via foreign key constraint if set up correctly

        return response()->json(null, 204); // No Content success response
    }
}

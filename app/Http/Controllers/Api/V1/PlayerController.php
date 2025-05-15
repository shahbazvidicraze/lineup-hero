<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponseTrait; // <-- USE TRAIT
use App\Models\Player;
use App\Models\Team;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Http\Response; // For status codes

class PlayerController extends Controller
{
    use ApiResponseTrait; // <-- INCLUDE TRAIT

    public function store(Request $request, Team $team)
    {
        if ($request->user()->id !== $team->user_id) {
            return $this->forbiddenResponse('You do not own this team.');
        }

        $validator = Validator::make($request->all(), [
            'first_name' => 'required|string|max:100',
            'last_name' => 'required|string|max:100',
            'jersey_number' => 'nullable|string|max:10',
            'email' => ['nullable','email','max:255', Rule::unique('players', 'email')],
        ]);
        if ($validator->fails()) return $this->validationErrorResponse($validator);

        $player = $team->players()->create($validator->validated());
        return $this->successResponse($player, 'Player created successfully.', Response::HTTP_CREATED);
    }

    public function show(Request $request, Player $player)
    {
        if (!$player->team || $request->user()->id !== $player->team->user_id && !$request->user('api_admin')) {
            return $this->forbiddenResponse('You cannot access this player.');
        }
        $player->load(['team:id,name', 'preferredPositions:id,name', 'restrictedPositions:id,name']);
        return $this->successResponse($player);
    }

    public function update(Request $request, Player $player)
    {
        if (!$player->team || $request->user()->id !== $player->team->user_id) {
            return $this->forbiddenResponse('You do not manage this player.');
        }

        $validator = Validator::make($request->all(), [
            'first_name' => 'sometimes|required|string|max:100',
            'last_name' => 'sometimes|required|string|max:100',
            'jersey_number' => 'nullable|string|max:10',
            'email' => ['nullable','email','max:255', Rule::unique('players', 'email')->ignore($player->id)],
        ]);
        if ($validator->fails()) return $this->validationErrorResponse($validator);

        $player->update($validator->validated());
        return $this->successResponse($player, 'Player updated successfully.');
    }

    public function destroy(Request $request, Player $player)
    {
        if (!$player->team || $request->user()->id !== $player->team->user_id) {
            return $this->forbiddenResponse('You do not manage this player.');
        }
        $player->delete();
        return $this->successResponse(null, 'Player deleted successfully.', Response::HTTP_OK, false);
    }
}

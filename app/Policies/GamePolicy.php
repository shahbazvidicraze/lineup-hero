<?php

namespace App\Policies;

use App\Models\Game;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization; // Use new namespace if needed

class GamePolicy
{
    use HandlesAuthorization; // Or use Gate facade directly

    /**
     * Determine whether the user can view the model.
     * (Basic ownership check - might already be handled elsewhere)
     */
    public function view(User $user, Game $game): bool
    {
         return $user->id === $game->team->user_id;
    }

    /**
     * Determine whether the user can get the data needed to generate a PDF for the game.
     */
    public function viewPdfData(User $user, Game $game): bool
    {
        // 1. Check ownership first
        if ($user->id !== $game->team->user_id) {
            return false;
        }

        // 2. Check if the associated team has active access status
        // Eager load team if not already loaded (usually handled by controller)
        $game->loadMissing('team');

        return $game->team?->hasActiveAccess() ?? false;
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Game $game): bool
    {
        return $user->id === $game->team->user_id;
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Game $game): bool
    {
         return $user->id === $game->team->user_id;
    }

    // Add other policy methods (create, restore, forceDelete) if needed
}

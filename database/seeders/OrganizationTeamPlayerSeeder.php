<?php

namespace Database\Seeders;

use App\Models\Game;
use App\Models\Organization;
use App\Models\Player;
use App\Models\Position;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Hash; // Keep Hash if factory still uses it

class OrganizationTeamPlayerSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // --- Configurable Options ---
        $numberOfOrganizations = 5;
        $teamsPerOrganization = 3;
        $playersPerTeam = 12;
        $gamesPerTeam = 4;
        $targetUserId = 1; // <-- SPECIFY THE EXISTING USER ID HERE
        // --- End Options ---

        $this->command->info('Seeding Organizations, Teams, Players, Preferences, and Games...');

        // --- Find the Target User ---
        $targetUser = User::find($targetUserId);

        if (!$targetUser) {
            $this->command->error("Target User with ID {$targetUserId} not found. Cannot proceed with seeding.");
            // Optionally, create the user if not found, or just stop.
            // Example: Create if not found
            // $targetUser = User::factory()->create(['id' => $targetUserId, 'email' => 'user1@example.com', ...]);
            // $this->command->warn("Created User with ID {$targetUserId} as it was not found.");
            return; // Stop seeding if the user must exist
        } else {
            $this->command->info("Found Target User: {$targetUser->email} (ID: {$targetUser->id})");
        }
        // --- End User Handling ---


        // --- Fetch Positions ---
        $positions = Position::all();
        $preferablePositions = $positions->whereNotIn('name', ['OUT', 'BENCH', 'DH', 'EH'])->pluck('id')->toArray();
        if (empty($preferablePositions)) {
             $this->command->error('No preferable positions found. Did PositionSeeder run?');
             return;
        }
        $this->command->info('Fetched Positions for preference setting.');

        // --- Create Organizations ---
        $organizations = Organization::factory()->count($numberOfOrganizations)->create();
        $this->command->info("Created {$numberOfOrganizations} Organizations.");

        // --- Create Teams, Players, Preferences, Games ---
        foreach ($organizations as $org) {
             $this->command->info(" > Processing Organization: {$org->name} (ID: {$org->id})");

             // Create Teams for this Org, assigned to the TARGET User
             $teams = Team::factory()
                        ->count($teamsPerOrganization)
                        ->for($targetUser) // Assign owner using the target user object
                        ->for($org)
                        ->create();
             $this->command->info("   - Created {$teamsPerOrganization} Teams for User ID {$targetUser->id}.");

             foreach ($teams as $team) {
                 $this->command->info("     - Processing Team: {$team->name} (ID: {$team->id})");
                 $players = Player::factory()->count($playersPerTeam)->state(['team_id' => $team->id])->create();
                 $this->command->info("       - Created {$playersPerTeam} Players.");

                 // --- Set Preferences using sync ---
                 foreach ($players as $player) {
                     $numPreferred = rand(1, 3);
                     $numRestricted = rand(0, 2);
                     $numPreferred = min($numPreferred, count($preferablePositions));
                     $numRestricted = min($numRestricted, count($preferablePositions) - $numPreferred);
                     $prefsToSync = [];

                     if (count($preferablePositions) >= $numPreferred) {
                        $preferredIds = ($numPreferred > 0) ? Arr::random($preferablePositions, $numPreferred) : [];
                        $preferredIds = is_array($preferredIds) ? $preferredIds : [$preferredIds];
                        foreach ($preferredIds as $id) { $prefsToSync[$id] = ['preference_type' => 'preferred']; }

                        $remainingPositions = array_diff($preferablePositions, $preferredIds);
                        if (count($remainingPositions) >= $numRestricted && $numRestricted > 0) {
                            $restrictedIds = Arr::random($remainingPositions, $numRestricted);
                            $restrictedIds = is_array($restrictedIds) ? $restrictedIds : [$restrictedIds];
                            foreach ($restrictedIds as $id) { $prefsToSync[$id] = ['preference_type' => 'restricted']; }
                        } elseif ($numRestricted > 0) {
                             $this->command->warn("       ! Could not assign restricted prefs for Player {$player->id}.");
                        }

                        if (!empty($prefsToSync)) {
                             $player->belongsToMany(Position::class, 'player_position_preferences', 'player_id', 'position_id')
                                    ->withPivot('preference_type')
                                    ->sync($prefsToSync);
                        } else {
                             $player->belongsToMany(Position::class, 'player_position_preferences', 'player_id', 'position_id')->detach();
                        }
                     } else {
                          $this->command->warn("       ! Skipping preferences for Player {$player->id}.");
                     }
                 } // End player loop
                 $this->command->info("       - Set random Player Preferences using sync.");

                 // --- Create Games ---
                 Game::factory()->count($gamesPerTeam)->for($team)->create();
                 $this->command->info("       - Created {$gamesPerTeam} Games.");

             } // End team loop
        } // End organization loop

        $this->command->info('Database seeding completed successfully!');
    }
}

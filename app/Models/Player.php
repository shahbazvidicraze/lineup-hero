<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Support\Facades\Log;

class Player extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     * @var array<int, string>
     */
    protected $fillable = [
        'team_id',
        'first_name',
        'last_name',
        'jersey_number',
        'email'
    ];

    /**
     * Cache for calculated stats within a single request lifecycle.
     * @var array|null
     */
    protected ?array $calculatedStatsCache = null;

    /**
     * The accessors to append to the model's array form.
     * Add 'stats' here to ensure it's included in JSON responses.
     *
     * @var array
     */
    protected $appends = ['stats']; // <-- ADD THIS LINE

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'amount' => 'integer', // This seems out of place for Player model? Remove if not applicable.
        'paid_at' => 'datetime', // This seems out of place for Player model? Remove if not applicable.
    ];


    // --- Relationships ---

    public function team()
    {
        return $this->belongsTo(Team::class);
    }

    public function positionPreferences()
    {
        return $this->hasMany(PlayerPositionPreference::class);
    }

    public function preferredPositions()
    {
        return $this->belongsToMany(Position::class, 'player_position_preferences', 'player_id', 'position_id')
            ->wherePivot('preference_type', 'preferred');
    }

    public function restrictedPositions()
    {
        return $this->belongsToMany(Position::class, 'player_position_preferences', 'player_id', 'position_id')
            ->wherePivot('preference_type', 'restricted');
    }

    // --- Stats Calculation Logic ---

    /**
     * Calculate historical player stats based on submitted game lineups.
     *
     * @return array{
     *     pct_innings_played: float|null,
     *     top_position: string|null,
     *     avg_batting_loc: int|null,
     *     position_counts: object|array
     * }
     */
    public function calculateHistoricalStats(): array
    {
        if ($this->calculatedStatsCache !== null) {
            return $this->calculatedStatsCache;
        }

        $this->loadMissing('team');
        if (!$this->team) {
            Log::warning("Player ID {$this->id} missing team relationship for stats calculation.");
            return $this->cacheStatsResult(['pct_innings_played' => null, 'top_position' => null, 'avg_batting_loc' => null, 'position_counts' => (object) []]);
        }

        $submittedGames = $this->team->games()
            ->whereNotNull('submitted_at')
            ->whereNotNull('lineup_data')
            ->where(function ($query) {
                $query->where('lineup_data', '!=', '[]')
                    ->where('lineup_data', '!=', '{}');
            })
            ->get(['id', 'innings', 'lineup_data']);

        $totalGameInningsAvailable = 0;
        $playerInningsPlayed = 0;
        $positionCounts = [];
        $battingLocations = [];

        if ($submittedGames->isEmpty()) {
            return $this->cacheStatsResult(['pct_innings_played' => 0.0, 'top_position' => null, 'avg_batting_loc' => null, 'position_counts' => (object) []]);
        }

        foreach ($submittedGames as $game) {
            try {
                $lineupData = $game->lineup_data;
                if (empty($lineupData)) continue;

                $lineupCollection = collect(is_object($lineupData) ? json_decode(json_encode($lineupData), true) : $lineupData);
                $playerLineupEntry = $lineupCollection->firstWhere('player_id', $this->id);

                if ($playerLineupEntry) {
                    $totalGameInningsAvailable += $game->innings;

                    if (isset($playerLineupEntry['innings']) && (is_array($playerLineupEntry['innings']) || is_object($playerLineupEntry['innings']))) {
                        $inningsArray = (array) $playerLineupEntry['innings'];
                        foreach ($inningsArray as $inning => $position) {
                            if (!empty($position) && is_string($position)) {
                                $upperPos = strtoupper($position);
                                if ($upperPos !== 'OUT' && $upperPos !== 'BENCH') {
                                    $playerInningsPlayed++;
                                    $positionCounts[$position] = ($positionCounts[$position] ?? 0) + 1;
                                }
                            }
                        }
                    }
                    if (isset($playerLineupEntry['batting_order']) && is_numeric($playerLineupEntry['batting_order'])) {
                        $battingLocations[] = (int) $playerLineupEntry['batting_order'];
                    }
                }
            } catch (\Exception $e) {
                Log::warning("Stats Calc: Failed processing game ID {$game->id}, player {$this->id}: " . $e->getMessage());
                continue;
            }
        }

        // Calculate final stats
        $pctInningsPlayed = ($totalGameInningsAvailable > 0) ? round(($playerInningsPlayed / $totalGameInningsAvailable) * 100, 1) : 0.0;
        $topPosition = null;
        if (!empty($positionCounts)) {
            $maxCount = 0;
            foreach ($positionCounts as $pos => $count) {
                if ($count > $maxCount) { $maxCount = $count; $topPosition = $pos; }
            }
        }
        $avgBattingLoc = null;
        if (!empty($battingLocations)) {
            $avgBattingLoc = (int) round(array_sum($battingLocations) / count($battingLocations));
        }
        $finalPositionCounts = !empty($positionCounts) ? $positionCounts : (object) [];

        $result = [
            'pct_innings_played' => $pctInningsPlayed,
            'top_position' => $topPosition,
            'avg_batting_loc' => $avgBattingLoc,
            'position_counts' => $finalPositionCounts,
        ];

        return $this->cacheStatsResult($result);
    }

    /**
     * Helper function to cache the stats result.
     */
    private function cacheStatsResult(array $result): array
    {
        $this->calculatedStatsCache = $result;
        return $result;
    }

    // --- Accessor ---

    /**
     * Get the calculated historical stats for the player.
     */
    protected function stats(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->calculateHistoricalStats(),
        );
    }

    // --- Optional: Full Name Accessor ---
    protected function fullName(): Attribute
    {
        return Attribute::make(
            get: fn (mixed $value, array $attributes) => ($attributes['first_name'] ?? '') . ' ' . ($attributes['last_name'] ?? ''),
        );
    }
}
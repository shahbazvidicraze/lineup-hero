<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache; // For caching settings

class Settings extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     * Allow mass assignment for easier updates by admins.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'optimizer_service_url',
        'optimizer_timeout',
        'unlock_price_amount',
        'unlock_currentcy', // Corrected typo from migration 'unlock_currency'
        'unlock_currentcy_symbol', // Corrected typo from migration 'unlock_currency_symbol'
        'unlock_currentcy_symbol_position', // Corrected typo from migration 'unlock_currency_symbol_position'
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'unlock_price_amount' => 'integer', // Amount is stored in cents
    ];

    /**
     * The table associated with the model.
     * Explicitly defining can prevent issues if table name differs from pluralized model name.
     *
     * @var string
     */
    protected $table = 'settings';


    // --- Singleton Access ---

    /**
     * Cache key for settings.
     * @var string
     */
    protected const CACHE_KEY = 'app_settings';

    /**
     * Get the application settings instance.
     * Creates default settings if none exist and caches the result.
     *
     * @param bool $forceRefresh Force fetching from DB instead of cache.
     * @return self
     */
    public static function instance(bool $forceRefresh = false): self
    {
        $cacheKey = self::CACHE_KEY;

        if ($forceRefresh) {
            Cache::forget($cacheKey);
        }

        // Cache settings for efficiency (e.g., for 1 hour)
        // Use Cache::rememberForever for permanent caching until manually cleared
        return Cache::remember($cacheKey, now()->addHour(), function () {
            // Attempt to find the first settings record, or create it with defaults
            // Note: Defaults here should match migration defaults if possible
            return self::firstOrCreate([], [
                'optimizer_service_url' => 'http://127.0.0.1:5000/optimize',
                'unlock_price_amount' => 500, // Use cents (match StripeController expectation)
                'unlock_currentcy' => 'usd',
                'unlock_currentcy_symbol' => '$',
                'unlock_currentcy_symbol_position' => 'before',
            ]);
        });
    }

    /**
     * Clear the settings cache. Should be called after updating settings.
     */
    public static function clearCache(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    // --- Optional: Helper Methods ---

    /**
     * Format the unlock price for display.
     *
     * @return string
     */
    public function getFormattedUnlockPriceAttribute(): string
    {
        // Convert cents to dollars/base unit for display
        $amountFormatted = number_format($this->unlock_price_amount / 100, 2);

        if ($this->unlock_currentcy_symbol_position === 'before') {
            return $this->unlock_currentcy_symbol . $amountFormatted;
        } else {
            return $amountFormatted . $this->unlock_currentcy_symbol;
        }
    }
}

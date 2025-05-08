<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Team extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'organization_id',
        'name',
        'season',
        'year',
        'sport_type',
        'team_type',
        'age_group',
        'city',
        'state',
        'access_status',      // Added
        'access_expires_at',  // Added
    ];

    protected $casts = [
        'year' => 'integer',
        'access_expires_at' => 'datetime', // Added
    ];

    // --- Relationships ---

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }

    public function players()
    {
        return $this->hasMany(Player::class);
    }

    public function games()
    {
        return $this->hasMany(Game::class);
    }

    public function promoCodeRedemptions()
    {
        return $this->hasMany(PromoCodeRedemption::class);
    }

    public function payments() // Relationship to payments table
    {
        return $this->hasMany(Payment::class);
    }


    // --- Helper Methods ---

    /**
     * Check if the team currently has active access (paid or promo).
     */
    public function hasActiveAccess(): bool
    {
        // Check status first
        if (!in_array($this->access_status, ['paid_active', 'promo_active'])) {
            return false;
        }

        // Check expiry (if it exists)
        if ($this->access_expires_at && $this->access_expires_at->isPast()) {
            // Optional: Add logic here to automatically set status to 'inactive' if expired
            // $this->access_status = 'inactive';
            // $this->saveQuietly(); // Avoid triggering events if needed
            return false;
        }

        // Status is active and not expired (or expiry is null)
        return true;
    }

    /**
     * Grant access via Promo Code (assumes permanent unless promo has expiry logic)
     */
    public function grantPromoAccess(): void
    {
        $this->access_status = 'promo_active';
        $this->access_expires_at = null; // Or set based on promo code details if they grant timed access
        $this->save();
    }

    /**
     * Grant access via Payment (assumes permanent or set expiry based on purchase type)
     */
    public function grantPaidAccess(?\DateTimeInterface $expiresAt = null): void
    {
        $this->access_status = 'paid_active';
        $this->access_expires_at = $expiresAt; // Set expiry if applicable (e.g., subscription)
        $this->save();
    }
}

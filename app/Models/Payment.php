<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Casts\Attribute; // Import Attribute

class Payment extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'team_id',
        'stripe_payment_intent_id',
        'amount', // Stored in cents
        'currency',
        'status',
        'paid_at',
    ];

    protected $casts = [
        'amount' => 'integer', // Keep as integer (cents)
        'paid_at' => 'datetime',
    ];

    /**
      * The accessors to append to the model's array form.
      * This ensures 'formatted_amount' is included in JSON responses.
      *
      * @var array
      */

    // --- Relationships ---
    public function user() { return $this->belongsTo(User::class); }
    public function team() { return $this->belongsTo(Team::class); }


    // --- Accessor for Formatted Amount ---

    /**
     * Get the payment amount formatted as a decimal string (e.g., "5.00").
     * Access via $payment->formatted_amount
     */

}

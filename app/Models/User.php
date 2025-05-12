<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Tymon\JWTAuth\Contracts\JWTSubject; // <-- Import JWTSubject

class User extends Authenticatable implements JWTSubject // <-- Implement JWTSubject
{
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'first_name', // Added
        'last_name',  // Added
        'email',
        'password',
        'phone',      // Added
        'role_id',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    // Add this method inside the User class
    public function promoCodeRedemptions()
    {
        return $this->hasMany(PromoCodeRedemption::class);
    }

    // JWTSubject Methods Implementation START

    public function getJWTIdentifier()
    {
        return $this->getKey();
    }

    public function getJWTCustomClaims()
    {
        return [
            'id' => $this->id,
            'first_name' => $this->first_name, // Use first_name
            'last_name' => $this->last_name,   // Use last_name
            'email' => $this->email,
            'phone' => $this->phone,           // Include phone if needed in token
            'type' => 'user',
        ];
    }
    // JWTSubject Methods Implementation END

    // Define Relationships
    public function teams()
    {
        return $this->hasMany(Team::class);
    }

     // Optional: Accessor to get full name easily
     public function getFullNameAttribute(): string
     {
         return "{$this->first_name} {$this->last_name}";
     }
}

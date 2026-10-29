<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable; // <Pasang HasApiTokens di sini

    protected $fillable = [
        'keycloak_sub',
        'name',
        'email',
        'phone',
        'password',
        'role', // deprecated, role dikelola di Keycloak
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * Check if user has a specific role (from Keycloak token_roles first, fallback to DB role)
     */
    public function hasRole(string $role): bool
    {
        if (isset($this->token_roles) && is_array($this->token_roles)) {
            return in_array($role, $this->token_roles, true);
        }

        return $this->role === $role;
    }

    public function isAdmin(): bool
    {
        return $this->hasRole('admin');
    }
}
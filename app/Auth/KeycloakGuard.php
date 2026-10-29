<?php

namespace App\Auth;

use App\Services\KeycloakService;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Http\Request;

class KeycloakGuard implements Guard
{
    protected Request $request;
    protected KeycloakService $keycloakService;
    protected ?Authenticatable $user = null;
    protected ?string $lastToken = null;

    public function __construct(KeycloakService $keycloakService, Request $request)
    {
        $this->keycloakService = $keycloakService;
        $this->request = $request;
    }

    /**
     * Determine if the current user is authenticated.
     */
    public function check(): bool
    {
        return ! is_null($this->user());
    }

    /**
     * Determine if the current user is a guest.
     */
    public function guest(): bool
    {
        return ! $this->check();
    }

    /**
     * Get the currently authenticated user.
     */
    public function user(): ?Authenticatable
    {
        $request = app()->bound('request') ? app('request') : $this->request;
        $token = $request->bearerToken();

        if (! is_null($this->user) && $this->lastToken === $token) {
            return $this->user;
        }

        if (! $token) {
            $this->user = null;
            $this->lastToken = null;
            return null;
        }

        // Verify token (throws HttpResponseException if invalid or missing required role, or null if not a Keycloak token)
        $payload = $this->keycloakService->verifyToken($token);
        if (! $payload) {
            $this->user = null;
            $this->lastToken = null;
            return null;
        }

        // Resolve or provision user model
        $user = $this->keycloakService->resolveUser($payload);

        $roles = $payload['extracted_roles'] ?? ($payload['realm_access']['roles'] ?? []);
        $user->token_roles = $roles;
        $user->keycloak_payload = $payload;

        $request->attributes->set('keycloak_token_payload', $payload);
        $request->attributes->set('keycloak_roles', $roles);

        $this->user = $user;
        $this->lastToken = $token;
        return $this->user;
    }

    /**
     * Get the ID for the currently authenticated user.
     */
    public function id()
    {
        return $this->user() ? $this->user()->getAuthIdentifier() : null;
    }

    /**
     * Validate a user's credentials.
     */
    public function validate(array $credentials = []): bool
    {
        return false;
    }

    /**
     * Determine if the guard has a user instance.
     */
    public function hasUser(): bool
    {
        return ! is_null($this->user);
    }

    /**
     * Set the current user.
     */
    public function setUser(Authenticatable $user): static
    {
        $this->user = $user;
        return $this;
    }
}

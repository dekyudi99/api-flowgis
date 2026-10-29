<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireRole
{
    /**
     * Handle an incoming request and enforce required role(s).
     *
     * @param  Closure(Request): (Response)  $next
     * @param  string  ...$roles
     */
    public function handle(Request $request, Closure $next, ...$roles): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json([
                'code' => 'AUTH_UNAUTHORIZED',
                'message' => 'Unauthenticated.',
            ], 401);
        }

        // Retrieve token roles set by KeycloakGuard, with fallback to user model
        $userRoles = $request->attributes->get('keycloak_roles') ?? $user->token_roles ?? [];

        if (empty($userRoles) && isset($user->role)) {
            $userRoles = [$user->role];
        }

        // If no specific roles required, ensure user has at least one valid role
        if (empty($roles)) {
            $validRoles = config('keycloak.valid_roles', ['analyst', 'admin']);
            $hasAnyRole = ! empty(array_intersect($validRoles, $userRoles));

            if (! $hasAnyRole) {
                return response()->json([
                    'code' => 'AUTH_FORBIDDEN_ROLE',
                    'message' => 'Anda belum memiliki akses ke aplikasi ini.',
                    'portal_url' => config('keycloak.portal_url', 'http://localhost:5170'),
                ], 403);
            }

            return $next($request);
        }

        // Check if user has at least one of the specified required roles
        $hasRequiredRole = false;
        foreach ($roles as $role) {
            if (in_array(trim($role), $userRoles, true)) {
                $hasRequiredRole = true;
                break;
            }
        }

        if (! $hasRequiredRole) {
            return response()->json([
                'code' => 'AUTH_FORBIDDEN_ROLE',
                'message' => 'Access denied: Requires role ' . implode(' or ', $roles),
            ], 403);
        }

        return $next($request);
    }
}

<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckAdmin
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $roles = $request->attributes->get('keycloak_roles') ?? $user?->token_roles ?? [];

        $isAdmin = in_array('admin', $roles, true) || ($user && $user->isAdmin());

        if (! $user || ! $isAdmin) {
            return response()->json([
                'code' => 'AUTH_FORBIDDEN_ROLE',
                'message' => 'Access denied. This endpoint can only be accessed by an Admin.'
            ], 403);
        }

        return $next($request);
    }
}

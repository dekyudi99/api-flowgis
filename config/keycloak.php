<?php

$keycloakUrl = rtrim(env('KEYCLOAK_URL', 'http://localhost:8081'), '/');
$keycloakRealm = env('KEYCLOAK_REALM', 'wefgis');
$keycloakInternalUrl = rtrim(env('KEYCLOAK_INTERNAL_URL', 'http://keycloak:8080'), '/');

return [
    /*
    |--------------------------------------------------------------------------
    | Keycloak OIDC Authentication Configuration
    |--------------------------------------------------------------------------
    */

    'realm' => $keycloakRealm,

    // Browser-facing Issuer claim URL for token validation (iss claim in JWT)
    'issuer' => env('KEYCLOAK_ISSUER', "{$keycloakUrl}/realms/{$keycloakRealm}"),

    // Direct inter-container URL to fetch JWKS public keys
    'jwks_url' => env('KEYCLOAK_JWKS_URL', "{$keycloakInternalUrl}/realms/{$keycloakRealm}/protocol/openid-connect/certs"),

    // Client ID expected in azp (Authorized Party) or aud claim
    'client_id' => env('KEYCLOAK_CLIENT_ID', 'flowgis'),

    // Valid roles for FlowGIS (client level or realm level fallback)
    'valid_roles' => explode(',', env('KEYCLOAK_VALID_ROLES', 'analyst,admin')),

    // Fallback required role if specified
    'required_role' => env('KEYCLOAK_REQUIRED_ROLE', null),

    // Portal SSO URL
    'portal_url' => env('PORTAL_URL', env('VITE_PORTAL_URL', 'http://localhost:5170')),

    // Cache duration for JWKS keys in seconds (default 10 minutes)
    'jwks_cache_ttl' => (int) env('KEYCLOAK_JWKS_CACHE_TTL', 600),

    // Clock skew / leeway in seconds for exp/nbf/iat validation
    'leeway' => (int) env('KEYCLOAK_LEEWAY', 60),

    // Flag for legacy authentication (Sanctum / local forms) coexistence
    'auth_local_enabled' => filter_var(env('AUTH_LOCAL_ENABLED', env('LEGACY_AUTH_ENABLED', false)), FILTER_VALIDATE_BOOLEAN),
];

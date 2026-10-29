<?php

namespace App\Services;

use App\Models\User;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class KeycloakService
{
    protected const CACHE_KEY = 'keycloak_jwks_certs';

    /**
     * Retrieve Keycloak JWKS public keys, with caching and on-demand refresh.
     */
    public function getJwks(bool $forceRefresh = false): array
    {
        if ($forceRefresh) {
            Cache::forget(self::CACHE_KEY);
        }

        $ttl = (int) config('keycloak.jwks_cache_ttl', 600);

        return Cache::remember(self::CACHE_KEY, $ttl, function () {
            $jwksUrl = config('keycloak.jwks_url');
            $response = Http::timeout(5)->get($jwksUrl);

            if (! $response->successful()) {
                Log::error("Failed to fetch Keycloak JWKS from {$jwksUrl}: HTTP {$response->status()}");
                throw new \RuntimeException("Unable to fetch Keycloak public certificates");
            }

            return $response->json();
        });
    }

    /**
     * Decode and validate Keycloak Access Token.
     * Returns the validated token payload array, or null if the token is not a JWT.
     */
    public function verifyToken(string $token): ?array
    {
        // 1. Inspect header without signature verification first
        $tokenParts = explode('.', $token);
        if (count($tokenParts) !== 3) {
            // Not a JWT token (e.g. Sanctum personal access token format "id|hash")
            return null;
        }

        $headerJson = base64_decode(strtr($tokenParts[0], '-_', '+/'));
        if (! $headerJson) {
            return null;
        }

        $header = json_decode($headerJson, true);

        if (! is_array($header) || ! isset($header['alg']) || ! isset($header['kid'])) {
            return null;
        }

        // Enforce RS256 algorithm ONLY
        if ($header['alg'] !== 'RS256') {
            $this->abortUnauthorized('AUTH_INVALID_ALG', 'Only RS256 algorithm is allowed');
        }

        $kid = $header['kid'];

        // 2. Fetch JWKS and check if kid exists in cache
        $jwks = $this->getJwks(forceRefresh: false);
        $hasKid = $this->jwksContainsKid($jwks, $kid);

        // If kid is missing, reload JWKS once (key rotation)
        if (! $hasKid) {
            $jwks = $this->getJwks(forceRefresh: true);
            if (! $this->jwksContainsKid($jwks, $kid)) {
                $this->abortUnauthorized('AUTH_UNKNOWN_KID', "Signing key '{$kid}' not found in JWKS");
            }
        }

        // 3. Verify signature and claims using Firebase JWT
        try {
            $parsedKeys = JWK::parseKeySet($jwks, 'RS256');
            JWT::$leeway = (int) config('keycloak.leeway', 60);

            $decoded = JWT::decode($token, $parsedKeys);
            $payload = json_decode(json_encode($decoded), true);
        } catch (\Firebase\JWT\ExpiredException $e) {
            $this->abortUnauthorized('AUTH_TOKEN_EXPIRED', 'Token has expired');
        } catch (\Exception $e) {
            Log::warning('Keycloak JWT verification failed: ' . $e->getMessage());
            $this->abortUnauthorized('AUTH_INVALID_TOKEN', 'Token verification failed');
        }

        // 4. Validate Issuer (iss == KEYCLOAK_ISSUER)
        $expectedIssuer = config('keycloak.issuer');
        if (! isset($payload['iss']) || $payload['iss'] !== $expectedIssuer) {
            Log::warning("Keycloak token issuer mismatch. Got '{$payload['iss']}', expected '{$expectedIssuer}'");
            $this->abortUnauthorized('AUTH_INVALID_ISSUER', 'Token issuer is invalid');
        }

        // 5. Validate Authorized Party (azp == KEYCLOAK_CLIENT_ID or aud contains KEYCLOAK_CLIENT_ID)
        $expectedClientId = config('keycloak.client_id');
        $azp = $payload['azp'] ?? null;
        $aud = (array) ($payload['aud'] ?? []);
        if ($azp !== $expectedClientId && ! in_array($expectedClientId, $aud, true)) {
            Log::warning("Keycloak token azp/aud mismatch. Got azp='{$azp}', expected '{$expectedClientId}'");
            $this->abortUnauthorized('AUTH_INVALID_AZP', 'Token authorized party or audience is invalid');
        }

        // 6. Extract roles from resource_access[client_id].roles (fallback realm_access.roles)
        $clientRoles = $payload['resource_access'][$expectedClientId]['roles'] ?? [];
        $realmRoles = $payload['realm_access']['roles'] ?? [];
        $allRoles = array_values(array_unique(array_merge($clientRoles, $realmRoles)));
        $payload['extracted_roles'] = $allRoles;

        // Check if user has ANY valid role for this client
        $validRoles = config('keycloak.valid_roles', ['analyst', 'admin']);
        $hasValidRole = false;
        foreach ($validRoles as $validRole) {
            if (in_array(trim($validRole), $allRoles, true)) {
                $hasValidRole = true;
                break;
            }
        }

        if (! $hasValidRole) {
            $username = $payload['preferred_username'] ?? $payload['sub'] ?? 'unknown';
            Log::warning("User '{$username}' lacks any valid role for client '{$expectedClientId}'. Roles found: " . json_encode($allRoles));
            throw new HttpResponseException(
                response()->json([
                    'code' => 'AUTH_FORBIDDEN_ROLE',
                    'message' => 'Anda belum memiliki akses ke aplikasi ini.',
                    'portal_url' => config('keycloak.portal_url', 'http://localhost:5170'),
                ], 403)
            );
        }

        return $payload;
    }

    /**
     * Map Keycloak token payload to a local User model.
     * 1. Search by keycloak_sub = token.sub
     * 2. If not found, search by email (case-insensitive) ONLY IF email_verified is true and exactly 1 row matches.
     *    - If email matches local user but email_verified is false, reject with 403 "Verifikasi email Anda terlebih dahulu".
     *    - If multiple rows match (duplicate email conflict), log error and reject with 409 Conflict.
     * 3. If no match at all, create new local user with unusable random password.
     * 4. Update local user's name and email from token claims on every login.
     */
    public function resolveUser(array $payload): User
    {
        $sub = $payload['sub'] ?? null;
        if (! $sub) {
            $this->abortUnauthorized('AUTH_INVALID_TOKEN', "Missing 'sub' claim in token");
        }

        $rawEmail = $payload['email'] ?? null;
        $cleanEmail = $rawEmail ? strtolower(trim($rawEmail)) : ($sub . '@keycloak.local');
        $emailVerified = (bool) ($payload['email_verified'] ?? false);
        $name = $payload['name'] ?? trim(($payload['given_name'] ?? '') . ' ' . ($payload['family_name'] ?? '')) ?: ($payload['preferred_username'] ?? ($rawEmail ? explode('@', $rawEmail)[0] : 'Keycloak User'));
        $roles = $payload['extracted_roles'] ?? ($payload['realm_access']['roles'] ?? []);

        // 1. Search based on keycloak_sub
        $user = User::where('keycloak_sub', $sub)->first();
        if ($user) {
            // Update email and name from token claims
            $user->name = $name;
            if ($rawEmail && $cleanEmail !== strtolower(trim($user->email))) {
                $user->email = $cleanEmail;
            }
            if ($emailVerified && ! $user->email_verified_at) {
                $user->email_verified_at = now();
            }
            $user->save();
            return $user;
        }

        // 2. Search based on email (case-insensitive)
        if ($rawEmail) {
            $matches = User::whereRaw('LOWER(email) = ?', [$cleanEmail])->get();

            if ($matches->count() > 1) {
                Log::error("Multiple ({$matches->count()}) local users found with email '{$cleanEmail}'. Refusing to link ambiguous account.");
                throw new HttpResponseException(
                    response()->json([
                        'code' => 'AUTH_EMAIL_CONFLICT',
                        'message' => 'Konflik email: Ditemukan lebih dari satu akun lokal dengan email ini. Silakan hubungi administrator.',
                    ], 409)
                );
            }

            if ($matches->count() === 1) {
                $matchedUser = $matches->first();

                // If email matches local user but token email_verified is false, reject
                if (! $emailVerified) {
                    Log::warning("Local user exists for email '{$cleanEmail}' but Keycloak email_verified is false.");
                    throw new HttpResponseException(
                        response()->json([
                            'code' => 'AUTH_EMAIL_UNVERIFIED',
                            'message' => 'Verifikasi email Anda terlebih dahulu.',
                        ], 403)
                    );
                }

                // Check if keycloak_sub is already used by another user
                $subOwner = User::where('keycloak_sub', $sub)->where('id', '!=', $matchedUser->id)->first();
                if ($subOwner) {
                    Log::error("Keycloak sub '{$sub}' is already associated with user ID {$subOwner->id}.");
                    throw new HttpResponseException(
                        response()->json([
                            'code' => 'AUTH_SUB_CONFLICT',
                            'message' => 'Identitas SSO telah digunakan oleh akun lokal lain.',
                        ], 409)
                    );
                }

                // Link sub to this user and update profile
                $matchedUser->keycloak_sub = $sub;
                $matchedUser->name = $name;
                $matchedUser->email = $cleanEmail;
                if (! $matchedUser->email_verified_at) {
                    $matchedUser->email_verified_at = now();
                }
                $matchedUser->save();

                Log::info("Linked existing user '{$matchedUser->email}' to Keycloak sub '{$sub}'");
                return $matchedUser;
            }
        }

        // 3. No match found: Provision new local user
        $role = in_array('admin', $roles, true) ? 'admin' : 'analyst';

        $newUser = User::create([
            'keycloak_sub' => $sub,
            'name' => $name,
            'email' => $cleanEmail,
            'password' => Hash::make(Str::random(40)), // nilai acak tak terpakai (NOT NULL)
            'role' => $role, // deprecated, role dikelola di Keycloak
            'email_verified_at' => $emailVerified ? now() : null,
        ]);

        Log::info("Provisioned new local user '{$newUser->email}' from Keycloak sub '{$sub}'");
        return $newUser;
    }

    protected function jwksContainsKid(array $jwks, string $kid): bool
    {
        $keys = $jwks['keys'] ?? [];
        foreach ($keys as $key) {
            if (isset($key['kid']) && $key['kid'] === $kid) {
                return true;
            }
        }
        return false;
    }

    protected function abortUnauthorized(string $code, string $message): void
    {
        throw new HttpResponseException(
            response()->json([
                'code' => $code,
                'message' => $message,
            ], 401, ['WWW-Authenticate' => 'Bearer'])
        );
    }
}

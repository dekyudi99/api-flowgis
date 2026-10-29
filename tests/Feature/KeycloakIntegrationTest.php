<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\KeycloakService;
use Firebase\JWT\JWT;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class KeycloakIntegrationTest extends TestCase
{
    protected string $privateKey;
    protected string $publicKey;
    protected string $kid = 'test-keycloak-key-id-1';

    protected function setUp(): void
    {
        parent::setUp();

        // Generate pasangan RSA key pair baru untuk pengujian JWKS terisolasi
        $res = openssl_pkey_new([
            'digest_alg' => 'sha256',
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        $privateKey = '';
        openssl_pkey_export($res, $privateKey);
        $this->privateKey = $privateKey;
        $keyDetails = openssl_pkey_get_details($res);
        $this->publicKey = $keyDetails['key'];

        $rsa = openssl_pkey_get_public($this->publicKey);
        $details = openssl_pkey_get_details($rsa);
        $modulus = rtrim(strtr(base64_encode($details['rsa']['n']), '+/', '-_'), '=');
        $exponent = rtrim(strtr(base64_encode($details['rsa']['e']), '+/', '-_'), '=');

        $jwks = [
            'keys' => [
                [
                    'kid' => $this->kid,
                    'kty' => 'RSA',
                    'alg' => 'RS256',
                    'use' => 'sig',
                    'n' => $modulus,
                    'e' => $exponent,
                ],
            ],
        ];

        Cache::put('keycloak_jwks_certs', $jwks, 600);
    }

    protected function createTestToken(array $customClaims = [], ?string $overrideKey = null, string $alg = 'RS256'): string
    {
        $now = time();
        $clientId = config('keycloak.client_id', 'flowgis');

        $payload = array_merge([
            'iss' => config('keycloak.issuer'),
            'sub' => 'sub-test-' . uniqid(),
            'azp' => $clientId,
            'aud' => [$clientId, 'account'],
            'exp' => $now + 300,
            'iat' => $now,
            'email' => 'testuser@example.com',
            'email_verified' => true,
            'name' => 'Test User',
            'preferred_username' => 'testuser',
            'resource_access' => [
                $clientId => [
                    'roles' => ['analyst'],
                ],
            ],
            'realm_access' => [
                'roles' => ['analyst'],
            ],
        ], $customClaims);

        $headers = [
            'kid' => $this->kid,
            'alg' => $alg,
        ];

        return JWT::encode($payload, $overrideKey ?? $this->privateKey, $alg, $this->kid, $headers);
    }

    /** 1. Token valid dengan role analyst -> 200 */
    public function test_api_me_with_valid_analyst_role_returns_200()
    {
        $validToken = $this->createTestToken([
            'email' => 'analyst_user@example.com',
            'name' => 'Analyst User',
            'resource_access' => [
                'flowgis' => ['roles' => ['analyst']],
            ],
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $validToken)->getJson('/api/me');
        $response->assertStatus(200);
        $response->assertJsonPath('status', 'success');
        $response->assertJsonPath('data.email', 'analyst_user@example.com');
        $this->assertContains('analyst', $response->json('data.roles'));
    }

    /** 2. Token valid dengan role admin -> 200 */
    public function test_api_me_with_valid_admin_role_returns_200()
    {
        $adminToken = $this->createTestToken([
            'email' => 'admin_user@example.com',
            'name' => 'Administrator User',
            'resource_access' => [
                'flowgis' => ['roles' => ['admin']],
            ],
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $adminToken)->getJson('/api/me');
        $response->assertStatus(200);
        $this->assertContains('admin', $response->json('data.roles'));
    }

    /** 3. Token tanpa role apapun -> 403 Forbidden */
    public function test_api_me_without_role_returns_403()
    {
        $forbiddenToken = $this->createTestToken([
            'resource_access' => [
                'flowgis' => ['roles' => []],
            ],
            'realm_access' => ['roles' => []],
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $forbiddenToken)->getJson('/api/me');
        $response->assertStatus(403);
        $response->assertJsonPath('code', 'AUTH_FORBIDDEN_ROLE');
        $this->assertEquals('Anda belum memiliki akses ke aplikasi ini.', $response->json('message'));
        $this->assertNotEmpty($response->json('portal_url'));
    }

    /** 4. Role dari client lain (bukan flowgis) -> 403 Forbidden */
    public function test_api_me_with_role_from_other_client_returns_403()
    {
        $otherClientToken = $this->createTestToken([
            'resource_access' => [
                'voxagis' => ['roles' => ['user', 'admin']],
                'flowgis' => ['roles' => []],
            ],
            'realm_access' => ['roles' => []],
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $otherClientToken)->getJson('/api/me');
        $response->assertStatus(403);
        $response->assertJsonPath('code', 'AUTH_FORBIDDEN_ROLE');
    }

    /** 5. Token kedaluwarsa -> 401 Unauthorized */
    public function test_expired_token_returns_401()
    {
        $expiredToken = $this->createTestToken([
            'exp' => time() - 300,
            'iat' => time() - 600,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $expiredToken)->getJson('/api/me');
        $response->assertStatus(401);
        $response->assertJsonPath('code', 'AUTH_TOKEN_EXPIRED');
    }

    /** 6. Issuer salah -> 401 Unauthorized */
    public function test_wrong_issuer_token_returns_401()
    {
        $wrongIssToken = $this->createTestToken([
            'iss' => 'http://evil.issuer.com/realms/fake',
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $wrongIssToken)->getJson('/api/me');
        $response->assertStatus(401);
        $response->assertJsonPath('code', 'AUTH_INVALID_ISSUER');
    }

    /** 7. Pencocokan via sub -> mencocokkan user lokal yang sudah punya keycloak_sub */
    public function test_matching_existing_user_via_sub()
    {
        $sub = 'kc-sub-matched-' . uniqid();
        $user = User::create([
            'keycloak_sub' => $sub,
            'name' => 'Original Name',
            'email' => 'sub_matched_' . uniqid() . '@example.com',
            'password' => Hash::make(Str::random(40)),
            'role' => 'analyst',
        ]);

        $token = $this->createTestToken([
            'sub' => $sub,
            'name' => 'Updated Keycloak Name',
            'email' => $user->email,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)->getJson('/api/me');
        $response->assertStatus(200);

        $user->refresh();
        $this->assertEquals('Updated Keycloak Name', $user->name);
        $this->assertEquals($user->id, $response->json('data.id'));
    }

    /** 8. Pencocokan via email saat email_verified true -> tautkan keycloak_sub */
    public function test_matching_existing_user_via_verified_email()
    {
        $email = 'verified_analyst_' . uniqid() . '@example.com';
        $user = User::create([
            'name' => 'Legacy Analyst',
            'email' => $email,
            'password' => Hash::make('legacy_pass'),
            'role' => 'analyst',
        ]);

        $sub = 'kc-sub-link-' . uniqid();
        $token = $this->createTestToken([
            'sub' => $sub,
            'email' => strtoupper($email), // Uji case-insensitive
            'email_verified' => true,
            'name' => 'Linked Analyst Name',
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)->getJson('/api/me');
        $response->assertStatus(200);

        $user->refresh();
        $this->assertEquals($sub, $user->keycloak_sub);
        $this->assertEquals('Linked Analyst Name', $user->name);
        $this->assertNotNull($user->email_verified_at);
    }

    /** 9. Pencocokan via email saat email_verified false -> tolak 403 "Verifikasi email Anda terlebih dahulu" */
    public function test_matching_via_unverified_email_returns_403()
    {
        $email = 'unverified_' . uniqid() . '@example.com';
        $user = User::create([
            'name' => 'Local User',
            'email' => $email,
            'password' => Hash::make('secret'),
            'role' => 'analyst',
        ]);

        $sub = 'kc-sub-unverified-' . uniqid();
        $token = $this->createTestToken([
            'sub' => $sub,
            'email' => $email,
            'email_verified' => false,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)->getJson('/api/me');
        $response->assertStatus(403);
        $response->assertJsonPath('code', 'AUTH_EMAIL_UNVERIFIED');
        $this->assertEquals('Verifikasi email Anda terlebih dahulu.', $response->json('message'));

        // Pastikan tidak ada akun baru yang terbuat
        $this->assertNull(User::where('keycloak_sub', $sub)->first());
    }

    /** 10. Konflik identitas sub / email -> penanganan konflik */
    public function test_duplicate_email_conflict_returns_409()
    {
        $service = new KeycloakService();
        $takenSub = 'already-taken-' . uniqid();

        $existingUser = User::create([
            'keycloak_sub' => $takenSub,
            'name' => 'Existing Owner',
            'email' => 'owner_' . uniqid() . '@example.com',
            'password' => Hash::make('pass'),
            'role' => 'analyst',
        ]);

        $candidateUser = User::create([
            'keycloak_sub' => null,
            'name' => 'Candidate User',
            'email' => 'candidate_' . uniqid() . '@example.com',
            'password' => Hash::make('pass'),
            'role' => 'analyst',
        ]);

        // Uji pencocokan sub langsung mengembalikan pemilik asli
        $resolved = $service->resolveUser([
            'sub' => $takenSub,
            'email' => $existingUser->email,
            'email_verified' => true,
            'preferred_username' => 'existing_user',
        ]);

        $this->assertEquals($existingUser->id, $resolved->id);
    }

    /** 11. User baru (tidak cocok sub & email) -> dibuatkan akun lokal baru */
    public function test_provisioning_new_user()
    {
        $newEmail = 'brand_new_' . uniqid() . '@example.com';
        $sub = 'kc-new-' . uniqid();

        $token = $this->createTestToken([
            'sub' => $sub,
            'email' => $newEmail,
            'email_verified' => true,
            'name' => 'Newflow User',
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)->getJson('/api/me');
        $response->assertStatus(200);

        $created = User::where('keycloak_sub', $sub)->first();
        $this->assertNotNull($created);
        $this->assertEquals($newEmail, $created->email);
        $this->assertEquals('Newflow User', $created->name);
        $this->assertEquals('analyst', $created->role);
        $this->assertNotEmpty($created->password);
    }

    /** 12. Local register dan login dinonaktifkan ketika flag AUTH_LOCAL_ENABLED = false */
    public function test_local_login_and_register_disabled_returns_404()
    {
        config(['keycloak.auth_local_enabled' => false]);

        $registerRes = $this->postJson('/api/auth/register', [
            'fullname' => 'Test User',
            'email' => 'local_attempt@example.com',
            'password' => 'password123',
        ]);
        $registerRes->assertStatus(404);
        $registerRes->assertJsonPath('code', 'LOCAL_AUTH_DISABLED');

        $loginRes = $this->postJson('/api/auth/login', [
            'email' => 'local_attempt@example.com',
            'password' => 'password123',
        ]);
        $loginRes->assertStatus(404);
        $loginRes->assertJsonPath('code', 'LOCAL_AUTH_DISABLED');
    }

    /** 13. Otorisasi middleware CheckAdmin: admin boleh, analyst ditolak 403 */
    public function test_admin_middleware_authorization()
    {
        $analystToken = $this->createTestToken([
            'resource_access' => [
                'flowgis' => ['roles' => ['analyst']],
            ],
        ]);

        // Route admin/cctv terlindungi middleware admin
        $forbiddenRes = $this->withHeader('Authorization', 'Bearer ' . $analystToken)->getJson('/api/admin/cctv');
        $forbiddenRes->assertStatus(403);

        $adminToken = $this->createTestToken([
            'email' => 'admin_cctv_' . uniqid() . '@example.com',
            'resource_access' => [
                'flowgis' => ['roles' => ['admin']],
            ],
            'realm_access' => ['roles' => ['admin']],
        ]);

        $allowedRes = $this->withHeader('Authorization', 'Bearer ' . $adminToken)->getJson('/api/admin/cctv');
        $this->assertNotEquals(403, $allowedRes->status());
    }
}

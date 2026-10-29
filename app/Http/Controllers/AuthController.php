<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    /**
     * Endpoint untuk Login User & Generate Token
     */
    public function register(Request $request)
    {
        if (! config('keycloak.auth_local_enabled', false)) {
            return response()->json([
                'code' => 'LOCAL_AUTH_DISABLED',
                'message' => 'Pendaftaran lokal dinonaktifkan. Silakan gunakan SSO Keycloak.',
            ], 404);
        }

        $validated = $request->validate([
            'fullname' => 'required|string|max:255',
            'username' => 'nullable|string|max:255',
            'email' => 'required|email|max:255|unique:users,email',
            'password' => 'required|min:8',
        ], [
            'email.unique' => 'This email is already registered. Please use a different email or log in directly. (login).',
            'password.min' => 'The password must consist of at least 8 characters.',
        ]);

        $user = User::create([
            'name' => $validated["fullname"],
            'email' => $validated["email"],
            'password' => Hash::make($validated["password"]),
            'role' => 'analyst',
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Registration successful! Please log in.',
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role ?? 'analyst',
            ]
        ], 201);
    }

    public function login(Request $request)
    {
        if (! config('keycloak.auth_local_enabled', false)) {
            return response()->json([
                'code' => 'LOCAL_AUTH_DISABLED',
                'message' => 'Login lokal dinonaktifkan. Silakan gunakan SSO Keycloak.',
            ], 404);
        }

        // Validasi Input
        $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        // Cari User berdasarkan Email
        $user = User::where('email', $request->email)->first();

        // Verifikasi Password
        if (! $user || ! Hash::check($request->password, $user->password)) {
            return response()->json([
                'status' => 'error',
                'message' => 'The email address or password is incorrect.'
            ], 401);
        }

        // Hapus token lama jika ingin membatasi 1 device aktif saja
        // $user->tokens()->delete();

        // Generate Sanctum Bearer Token
        $token = $user->createToken('auth_token')->plainTextToken;

        // Kembalikan Response JSON ke Frontend
        return response()->json([
            'status' => 'success',
            'message' => 'Login successful',
            'access_token' => $token,
            'token_type' => 'Bearer',
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role ?? 'analyst', // Mengembalikan info role untuk React
            ]
        ]);
    }

    /**
     * Mengambil Profil User yang Sedang Login
     */
    public function me(Request $request)
    {
        $user = $request->user();
        $tokenRoles = $request->attributes->get('keycloak_roles') ?? $user->token_roles ?? [];

        return response()->json([
            'status' => 'success',
            'data' => [
                'id' => $user->id,
                'keycloak_sub' => $user->keycloak_sub,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'roles' => $tokenRoles ?: [$user->role ?? 'analyst'],
                'auth_type' => $request->attributes->has('keycloak_roles') ? 'keycloak' : 'sanctum',
                'created_at' => $user->created_at,
                'updated_at' => $user->updated_at,
            ],
        ]);
    }

    /**
     * Endpoint Logout (Revoke Token)
     */
    public function logout(Request $request)
    {
        // Menghapus token Sanctum yang sedang digunakan jika ada (legacy auth)
        if ($request->user() && method_exists($request->user(), 'currentAccessToken') && $request->user()->currentAccessToken()) {
            $request->user()->currentAccessToken()->delete();
        }

        return response()->json([
            'message' => 'The user session has ended successfully.'
        ], 200);
    }
}
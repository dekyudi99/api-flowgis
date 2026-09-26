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
        $validated = $request->validate([
            'fullname' => 'required|string',
            'username' => 'required|string',
            'phone' => [
                'required',
                'regex:/^[0-9]+$/',
                'min:10',
                'max:15',
            ],
            'email' => 'required|email',
            'password' => 'required|min:8',
        ]);

        $user = User::create([
            'name' => $validated["fullname"],
            'email' => $validated["email"],
            'phone' => $validated["phone"],
            'password' => Hash::make($validated["password"]),
        ]);

        if (!$user) {
            return response()->json([
                'status' => 'failed',
                'message' => 'Registrasi gagal!',
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role,
                ]
            ]);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Registrasi berhasil!',
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
            ]
        ]);
    }

    public function login(Request $request)
    {
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
            'message' => 'Login berhasil',
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
        return response()->json([
            'status' => 'success',
            'data' => $request->user()
        ]);
    }

    /**
     * Endpoint Logout (Revoke Token)
     */
    public function logout(Request $request)
    {
        // Menghapus token Sanctum yang sedang digunakan pada request ini
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'The user session has ended successfully.'
        ], 200);
    }
}
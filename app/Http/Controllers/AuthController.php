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
            'fullname' => 'required|string|max:255',
            'username' => 'nullable|string|max:255',
            'phone' => [
                'required',
                'regex:/^[0-9]+$/',
                'min:10',
                'max:20',
            ],
            'email' => 'required|email|max:255|unique:users,email',
            'password' => 'required|min:8',
        ], [
            'email.unique' => 'Email ini sudah terdaftar. Silakan gunakan email lain atau langsung masuk (login).',
            'phone.regex' => 'Format nomor telepon hanya boleh berisi angka.',
            'password.min' => 'Password minimal terdiri dari 8 karakter.',
        ]);

        $user = User::create([
            'name' => $validated["fullname"],
            'email' => $validated["email"],
            'phone' => $validated["phone"],
            'password' => Hash::make($validated["password"]),
            'role' => 'analyst',
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Registrasi berhasil! Silakan login.',
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
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AdminUserController extends Controller
{
    /**
     * [GET] /api/admin/users
     * Daftar seluruh pengguna terdaftar
     */
    public function index(Request $request)
    {
        try {
            $query = User::query();

            if ($request->has('search') && !empty($request->search)) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'ilike', "%{$search}%")
                      ->orWhere('email', 'ilike', "%{$search}%");
                });
            }

            if ($request->has('role') && !empty($request->role)) {
                $query->where('role', $request->role);
            }

            $users = $query->select(['id', 'name', 'email', 'role', 'created_at'])
                ->orderBy('created_at', 'desc')
                ->get();

            // Jika database masih sepi, pastikan ada akun admin default
            if ($users->isEmpty()) {
                $defaultAdmin = User::create([
                    'name'     => 'Administrator FlowGIS',
                    'email'    => 'admin@flowgis.com',
                    'password' => Hash::make('password123'),
                    'role'     => 'admin',
                ]);
                $users = collect([$defaultAdmin]);
            }

            return response()->json([
                'status' => 'success',
                'data'   => $users
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Failed to load users: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * [POST] /api/admin/users
     * Tambah pengguna baru
     */
    public function store(Request $request)
    {
        try {
            $validated = $request->validate([
                'name'     => 'required|string|max:255',
                'email'    => 'required|email|unique:users,email',
                'password' => 'required|string|min:6',
                'role'     => 'required|string|in:admin,analyst,user',
            ]);

            $user = User::create([
                'name'     => $validated['name'],
                'email'    => $validated['email'],
                'password' => Hash::make($validated['password']),
                'role'     => $validated['role'],
            ]);

            return response()->json([
                'status'  => 'success',
                'message' => 'User created successfully.',
                'data'    => [
                    'id'         => $user->id,
                    'name'       => $user->name,
                    'email'      => $user->email,
                    'role'       => $user->role,
                    'created_at' => $user->created_at,
                ]
            ], 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Validation failed',
                'errors'  => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Failed to create user: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * [PATCH] /api/admin/users/{id}/role
     * Ubah role pengguna (admin <-> analyst / user)
     */
    public function toggleRole(Request $request, $id)
    {
        try {
            $user = User::findOrFail($id);

            if ($request->has('role')) {
                $newRole = $request->role;
            } else {
                // Otomatis toggle antara admin dan analyst
                $newRole = ($user->role === 'admin') ? 'analyst' : 'admin';
            }

            $user->role = $newRole;
            $user->save();

            return response()->json([
                'status'  => 'success',
                'message' => "User role successfully changed to {$newRole}.",
                'data'    => [
                    'id'    => $user->id,
                    'name'  => $user->name,
                    'email' => $user->email,
                    'role'  => $user->role,
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Failed to change user role: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * [PUT] /api/admin/users/{id}
     * Update data pengguna
     */
    public function update(Request $request, $id)
    {
        try {
            $user = User::findOrFail($id);

            $validated = $request->validate([
                'name'     => 'required|string|max:255',
                'email'    => 'required|email|unique:users,email,' . $id,
                'role'     => 'nullable|string|in:admin,analyst,user',
                'password' => 'nullable|string|min:6',
            ]);

            $user->name  = $validated['name'];
            $user->email = $validated['email'];
            if (isset($validated['role']))  $user->role  = $validated['role'];
            if (!empty($validated['password'])) {
                $user->password = Hash::make($validated['password']);
            }

            $user->save();

            return response()->json([
                'status'  => 'success',
                'message' => 'User details updated successfully.',
                'data'    => [
                    'id'    => $user->id,
                    'name'  => $user->name,
                    'email' => $user->email,
                    'role'  => $user->role,
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Failed to update user: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * [DELETE] /api/admin/users/{id}
     * Hapus pengguna
     */
    public function destroy(Request $request, $id)
    {
        try {
            $user = User::findOrFail($id);

            // Mencegah admin menghapus dirinya sendiri jika sedang login
            if ($request->user() && $request->user()->id == $user->id) {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Anda tidak dapat menghapus akun Anda sendiri yang sedang aktif!'
                ], 403);
            }

            $user->delete();

            return response()->json([
                'status'     => 'success',
                'message'    => 'User removed successfully.',
                'deleted_id' => $id
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Failed to delete user: ' . $e->getMessage()
            ], 500);
        }
    }
}

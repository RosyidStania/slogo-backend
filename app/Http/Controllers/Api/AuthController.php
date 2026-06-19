<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        // 1. REVISI: Ubah validasi dari 'email' menjadi 'username' 
        // karena React mengirimkan { username, password }
        $request->validate([
            'username' => 'required',
            'password' => 'required'
        ]);

        $credentials = $request->only('username', 'password');

        if (!auth()->attempt($credentials)) {
            return response()->json([
                'success' => false,
                'message' => 'Username atau Password salah.'
            ], 401);
        }

        $user = auth()->user();
        
        // MEMBUAT TOKEN SANCTUM
        $token = $user->createToken('auth_token')->plainTextToken;

        // 2. REVISI: Sesuaikan nama kunci (key) dengan yang diharapkan React
        return response()->json([
            'success'      => true,
            'message'      => 'Login sukses',
            'user'         => $user,
            'access_token' => $token,       // Diubah dari 'token' menjadi 'access_token'
            'role'         => $user->role   // Menambahkan 'role' agar bisa ditangkap React
        ], 200);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();
        return response()->json(['message' => 'Berhasil logout']);
    }

    public function profile(Request $request)
    {
        return response()->json([
            'success' => true,
            'user' => $request->user()
        ]);
    }

    public function resetPassword(Request $request)
    {
        $request->validate([
            'old_password' => 'required',
            'new_password' => 'required|min:6' // in a real app might need |confirmed
        ]);

        $user = $request->user();

        if (!Hash::check($request->old_password, $user->password)) {
            return response()->json([
                'success' => false,
                'message' => 'Password lama tidak sesuai.'
            ], 400);
        }

        $user->password = Hash::make($request->new_password);
        
        $user->save();

        return response()->json([
            'success' => true,
            'message' => 'Password berhasil diubah.'
        ]);
    }
}
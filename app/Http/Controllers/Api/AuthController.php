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
            'user' => $request->user()->load('generus')
        ]);
    }

    public function updateProfile(Request $request)
    {
        $user = $request->user();

        $request->validate([
            'name' => 'required|string|max:255',
            'username' => 'required|string|max:255|unique:users,username,' . $user->id,
            'tempat_lahir' => 'nullable|string|max:255',
            'tanggal_lahir' => 'nullable|date',
            'jenis_kelamin' => 'nullable|in:L,P',
            'nama_ayah' => 'nullable|string|max:255',
            'nama_ibu' => 'nullable|string|max:255',
            'no_hp' => 'nullable|string|max:20',
            'akun_media' => 'nullable|string|max:255',
            'hobi' => 'nullable|string|max:255',
            'keterangan' => 'nullable|string|max:255',
            'libur' => 'nullable|string|max:255',
            'kelompok' => 'nullable|string|max:255',
            'jenjang' => 'nullable|string|max:255',
        ]);

        $user->name = $request->name;
        $user->username = strtolower($request->username);
        $user->save();

        if ($user->generus) {
            $user->generus->update([
                'nama_lengkap' => $request->name,
                'tempat_lahir' => $request->tempat_lahir,
                'tanggal_lahir' => $request->tanggal_lahir,
                'jenis_kelamin' => $request->jenis_kelamin,
                'nama_ayah' => $request->nama_ayah,
                'nama_ibu' => $request->nama_ibu,
                'no_hp' => $request->no_hp,
                'akun_media' => $request->akun_media,
                'hobi' => $request->hobi,
                'keterangan' => $request->keterangan,
                'libur' => $request->libur,
                'kelompok' => $request->kelompok,
                'jenjang' => $request->jenjang,
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Profil berhasil diupdate.',
            'user' => $user->load('generus')
        ]);
    }

    public function resetPassword(Request $request)
    {
        $request->validate([
            'old_password' => 'required',
            'new_password' => 'required|min:6|confirmed'
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
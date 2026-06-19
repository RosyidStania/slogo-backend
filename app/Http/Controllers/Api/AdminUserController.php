<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class AdminUserController extends Controller
{
    public function index()
    {
        $users = User::with('generus')->orderBy('created_at', 'desc')->get();
        return response()->json(['success' => true, 'data' => $users], 200);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name'     => 'required|string|max:255',
            'username' => 'required|string|unique:users,username|max:255',
            'password' => 'required|string|min:6',
            'role'     => 'required|in:admin,user',
            'generus_id' => 'nullable|integer'
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => $validator->errors()], 422);
        }

        $user = User::create([
            'name'     => $request->name,
            'username' => strtolower($request->username), // Pastikan username lowercase
            'password' => Hash::make($request->password),
            'role'     => $request->role,
        ]);

        if ($request->generus_id && $request->role === 'user') {
            \App\Models\Generus::where('id', $request->generus_id)->update(['user_id' => $user->id]);
        }

        return response()->json(['success' => true, 'message' => 'User berhasil ditambahkan', 'data' => $user], 201);
    }

    public function update(Request $request, $id)
    {
        $user = User::find($id);
        if (!$user) return response()->json(['success' => false, 'message' => 'User tidak ditemukan'], 404);

        $validator = Validator::make($request->all(), [
            'name'     => 'required|string|max:255',
            'username' => 'required|string|max:255|unique:users,username,'.$id,
            'role'     => 'required|in:admin,user',
            'password' => 'nullable|string|min:6', // Password opsional saat edit
            'generus_id' => 'nullable|integer'
        ]);

        if ($validator->fails()) return response()->json(['success' => false, 'message' => $validator->errors()], 422);

        $user->name = $request->name;
        $user->username = strtolower($request->username);
        $user->role = $request->role;
        
        // Update password hanya jika diisi
        if ($request->filled('password')) {
            $user->password = Hash::make($request->password);
        }
        
        $user->save();

        if ($request->role === 'user') {
            if ($request->has('generus_id')) {
                \App\Models\Generus::where('user_id', $user->id)->update(['user_id' => null]);
                if ($request->generus_id) {
                    \App\Models\Generus::where('id', $request->generus_id)->update(['user_id' => $user->id]);
                }
            }
        } else {
            \App\Models\Generus::where('user_id', $user->id)->update(['user_id' => null]);
        }

        return response()->json(['success' => true, 'message' => 'User berhasil diupdate', 'data' => $user], 200);
    }

    public function destroy($id)
    {
        $user = User::find($id);
        if (!$user) return response()->json(['success' => false, 'message' => 'User tidak ditemukan'], 404);

        $user->delete();
        return response()->json(['success' => true, 'message' => 'User berhasil dihapus'], 200);
    }

    public function destroyAllUsers()
    {
        // Ambil semua id user biasa
        $userIds = User::where('role', '!=', 'admin')->pluck('id');
        
        // Null-kan user_id di tabel generus agar tidak ada error foreign key (jika ada)
        \App\Models\Generus::whereIn('user_id', $userIds)->update(['user_id' => null]);
        
        // Hapus user
        User::whereIn('id', $userIds)->delete();

        return response()->json(['success' => true, 'message' => 'Semua akun user biasa berhasil dihapus'], 200);
    }

    public function generateFromGenerus()
    {
        // Ambil semua data generus yang belum punya user_id
        $generuses = \App\Models\Generus::whereNull('user_id')->get();
        $count = 0;

        $existingUsernames = \App\Models\User::pluck('username')->flip()->toArray();

        foreach ($generuses as $generus) {
            // Username: nama depan + 3 angka random
            $firstName = strtolower(explode(' ', trim($generus->nama_lengkap))[0]);
            // Bersihkan dari karakter non-alphanumeric
            $firstName = preg_replace('/[^a-z0-9]/', '', $firstName);
            if (empty($firstName)) $firstName = 'user';
            
            // Generate unique username
            do {
                $username = $firstName . rand(100, 999);
            } while (isset($existingUsernames[$username]));
            
            $existingUsernames[$username] = true; // add to cache

            // Password: nama depan + 123
            $password = $firstName . '123';

            $user = User::create([
                'name'     => $generus->nama_lengkap,
                'username' => $username,
                'password' => Hash::make($password),
                'role'     => 'user',
            ]);

            // Update generus dengan user_id yang baru dibuat
            $generus->update(['user_id' => $user->id]);
            $count++;
        }

        return response()->json([
            'success' => true, 
            'message' => "Berhasil men-generate $count akun user baru dari data Generus."
        ], 200);
    }
}
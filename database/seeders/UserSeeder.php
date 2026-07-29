<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Akun Admin
        User::create([
            'name'     => 'admin',
            'username' => 'admin',
            'password' => Hash::make('admin123'),
            'role'     => 'admin',
        ]);

        // Akun User
        User::create([
            'name'     => 'user',
            'username' => 'user',
            'password' => Hash::make('user123'),
            'role'     => 'user',
        ]);
    }
}
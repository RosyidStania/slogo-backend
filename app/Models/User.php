<?php

namespace App\Models;

// Tambahkan baris ini untuk memanggil Sanctum
use Laravel\Sanctum\HasApiTokens; 

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    // Pastikan HasApiTokens ada di sini
    use HasApiTokens, HasFactory, Notifiable; 

    protected $fillable = [
        'name', 
        'username', 
        'password', 
        'role'
    ];

    protected $hidden = [
        'password', 
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
        ];
    }

    public function generus()
    {
        return $this->hasOne(Generus::class);
    }
}
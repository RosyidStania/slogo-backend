<?php

namespace App\Models;

// Tambahkan baris import ini
use Illuminate\Database\Eloquent\Factories\HasFactory; 
use Illuminate\Database\Eloquent\Model;

class Generus extends Model
{
    use HasFactory;
    
    protected $table = 'generus';
    
    protected $fillable = [
        'user_id', 'nama_lengkap', 'kelompok', 'status', 'tempat_lahir', 'tanggal_lahir', 
        'umur', 'jenis_kelamin', 'jenjang', 'keterangan', 'libur', 
        'nama_ayah', 'nama_ibu', 'no_hp', 'akun_media', 'hobi'
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
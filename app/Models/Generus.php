<?php

namespace App\Models;

// Tambahkan baris import ini
use Illuminate\Database\Eloquent\Factories\HasFactory; 
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Generus extends Model
{
    use HasFactory;
    
    protected $table = 'generus';
    
    protected $fillable = [
        'user_id', 'nama_lengkap', 'kelompok', 'status', 'tempat_lahir', 'tanggal_lahir', 
        'umur', 'jenis_kelamin', 'jenjang', 'is_pengurus', 'keterangan', 'libur', 
        'nama_ayah', 'nama_ibu', 'no_hp', 'akun_media', 'hobi', 'kode_unik'
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->kode_unik)) {
                $model->kode_unik = 'GEN-' . strtoupper(Str::random(8));
            }
        });
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function getNamaLengkapAttribute($value)
    {
        return mb_convert_case(mb_strtolower($value, 'UTF-8'), MB_CASE_TITLE, 'UTF-8');
    }
}
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory; 
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Generus extends Model
{
    use HasFactory;
    
    protected $table = 'generus';
    
    protected $fillable = [
        'user_id', 'nama_lengkap', 'kelompok', 'status', 'tempat_lahir', 'tanggal_lahir', 
        'umur', 'jenis_kelamin', 'jenjang', 'is_pengurus', 'is_pengurus_muda_mudi', 'is_ketua_wakil_kelompok', 'keterangan', 'libur', 
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

    public function pengurusTypes()
    {
        return $this->belongsToMany(PengurusType::class, 'generus_pengurus', 'generus_id', 'pengurus_type_id')
                    ->withTimestamps();
    }

    /**
     * Sinkronisasi jenis pengurus dan otomatis jaga kompatibilitas kolom legacy
     */
    public function syncPengurusTypes(array $typeIds): void
    {
        $this->pengurusTypes()->sync($typeIds);

        // Ambil code dari tipe yang di-assign untuk sinkronisasi kolom boolean lama
        $codes = PengurusType::whereIn('id', $typeIds)->pluck('code')->toArray();

        $this->update([
            'is_pengurus'              => in_array('PENGURUS_USMAN', $codes),
            'is_pengurus_muda_mudi'    => in_array('PENGURUS_MUDA_MUDI', $codes),
            'is_ketua_wakil_kelompok' => in_array('KETUA_WAKIL_KELOMPOK', $codes),
        ]);
    }

    public function getNamaLengkapAttribute($value)
    {
        return mb_convert_case(mb_strtolower($value, 'UTF-8'), MB_CASE_TITLE, 'UTF-8');
    }
}

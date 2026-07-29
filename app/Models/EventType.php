<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EventType extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'code', 'description', 'start_time', 'target_kategori'];

    protected $casts = [
        'target_kategori' => 'array', // Agar otomatis jadi array saat dibaca di React
    ];
    // Relasi satu Jenis Acara bisa memiliki banyak Event
    public function events()
    {
        return $this->hasMany(Event::class, 'event_type_id');
    }
}
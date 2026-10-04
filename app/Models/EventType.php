<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EventType extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'code', 'description', 'start_time', 'target_kategori', 'is_group_attendance'];

    protected $casts = [
        'target_kategori' => 'array', // Agar otomatis jadi array saat dibaca di React
        'is_group_attendance' => 'boolean',
    ];
    // Relasi satu Jenis Acara bisa memiliki banyak Event
    public function events()
    {
        return $this->hasMany(Event::class, 'event_type_id');
    }
}
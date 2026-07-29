<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Attendance extends Model
{
    use HasFactory;

    // Kolom yang boleh diisi
    protected $fillable = [
        'event_id', 
        'generus_id', 
        'status', 
        'time_arrived', 
        'is_late'
    ];

    // Relasi ke tabel Generus (Wajib ada untuk fitur Rekapan)
    public function generus()
    {
        return $this->belongsTo(Generus::class, 'generus_id');
    }

    // Relasi ke tabel Event
    public function event()
    {
        return $this->belongsTo(Event::class, 'event_id');
    }
}
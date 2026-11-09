<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MeetingNote extends Model
{
    use HasFactory;

    protected $fillable = [
        'event_id',
        'judul',
        'tanggal',
        'waktu',
        'lokasi',
        'pemimpin',
        'notulis',
        'agenda',
        'pembahasan',
        'keputusan',
        'peserta_hadir_count',
        'lampiran_info',
        'created_by'
    ];

    protected $casts = [
        'tanggal' => 'date:Y-m-d',
        'keputusan' => 'array',
        'peserta_hadir_count' => 'integer',
    ];

    public function event()
    {
        return $this->belongsTo(Event::class, 'event_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}

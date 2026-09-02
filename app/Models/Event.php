<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Event extends Model
{
    use HasFactory;

    // PASTIKAN event_type_id ADA DI DALAM ARRAY INI
    protected $fillable = [
        'name', 
        'event_date', 
        'start_time', 
        'target_kategori', 
        'event_type_id',
        'allow_other_participants'
    ];

    protected $casts = [
        'allow_other_participants' => 'boolean',
    ];

    public function eventType()
    {
        return $this->belongsTo(EventType::class, 'event_type_id');
    }

    public function attendances()
    {
        return $this->hasMany(Attendance::class, 'event_id');
    }
}
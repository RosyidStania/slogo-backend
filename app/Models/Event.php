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
        'end_time',
        'target_kategori', 
        'event_type_id',
        'group_schedule_id',
        'allow_other_participants',
        'kelompok',
        'infaq',
        'is_closed',
        'auto_closed_at',
        'auto_alpa_at',
    ];

    protected $casts = [
        'allow_other_participants' => 'boolean',
        'is_closed' => 'boolean',
        'auto_closed_at' => 'datetime',
        'auto_alpa_at' => 'datetime',
    ];

    public function eventType()
    {
        return $this->belongsTo(EventType::class, 'event_type_id');
    }

    public function groupSchedule()
    {
        return $this->belongsTo(GroupSchedule::class, 'group_schedule_id');
    }

    public function attendances()
    {
        return $this->hasMany(Attendance::class, 'event_id');
    }

    public function meetingNote()
    {
        return $this->hasOne(MeetingNote::class, 'event_id');
    }
}
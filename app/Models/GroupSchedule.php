<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GroupSchedule extends Model
{
    use HasFactory;

    protected $fillable = [
        'kelompok',
        'day_of_week',
        'start_time',
        'end_time',
        'event_type_id',
        'name',
        'target_kategori',
        'allow_other_participants',
        'lead_minutes',
        'auto_close_minutes',
        'is_active',
        'last_generated_date',
    ];

    protected $casts = [
        'day_of_week' => 'integer',
        'target_kategori' => 'array',
        'allow_other_participants' => 'boolean',
        'lead_minutes' => 'integer',
        'auto_close_minutes' => 'integer',
        'is_active' => 'boolean',
    ];

    public function eventType()
    {
        return $this->belongsTo(EventType::class, 'event_type_id');
    }

    public function events()
    {
        return $this->hasMany(Event::class, 'group_schedule_id');
    }
}

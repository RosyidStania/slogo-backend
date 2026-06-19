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
        'event_type_id' 
    ];

    public function eventType()
    {
        return $this->belongsTo(EventType::class, 'event_type_id');
    }
}
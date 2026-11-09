<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class PengurusType extends Model
{
    use HasFactory;

    protected $table = 'pengurus_types';

    protected $fillable = [
        'name',
        'code',
        'badge_color',
        'description',
        'is_active',
        'order',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'order'     => 'integer',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->code)) {
                $baseCode = strtoupper(preg_replace('/[^A-Za-z0-9]/', '_', $model->name));
                $code = $baseCode;
                $counter = 1;
                while (static::where('code', $code)->exists()) {
                    $code = "{$baseCode}_{$counter}";
                    $counter++;
                }
                $model->code = $code;
            }
        });
    }

    public function generus()
    {
        return $this->belongsToMany(Generus::class, 'generus_pengurus', 'pengurus_type_id', 'generus_id')
                    ->withTimestamps();
    }
}

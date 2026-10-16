<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DoctorTimeOff extends Model
{
    protected $fillable = ['doctor_id', 'starts_at', 'ends_at', 'reason'];
    protected $casts = ['starts_at' => 'datetime', 'ends_at' => 'datetime'];

    public function doctor()
    {
        return $this->belongsTo(Doctor::class);
    }
}

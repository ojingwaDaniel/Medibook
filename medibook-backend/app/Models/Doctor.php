<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\User;

class Doctor extends Model
{
    protected $fillable = [
        'user_id',
        'specialty_id',
        'bio',
        'years_experience',
        'consultation_fee',
        'clinic_name',
        'clinic_address',
        'slot_minutes',
        'timezone',
    ];

    protected $casts = ['consultation_fee' => 'decimal:2'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
    public function specialty()
    {
        return $this->belongsTo(Specialty::class);
    }
    public function availabilities()
    {
        return $this->hasMany(DoctorAvailability::class);
    }
    public function timeOffs()
    {
        return $this->hasMany(DoctorTimeOff::class);
    }
    public function appointments()
    {
        return $this->hasMany(Appointment::class);
    }

    public function scopeApproved($query)
    {
        return $query->where('status', 'approved');
    }

   
}

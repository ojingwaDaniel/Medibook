<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use App\Models\Doctor;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;

use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable implements FilamentUser
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = ['name', 'email', 'phone', 'password'];
    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    public function doctor()
    {
        return $this->hasOne(Doctor::class);
    }
    public function patientProfile()
    {
        return $this->hasOne(PatientProfile::class);
    }
    public function appointments()
    {
        return $this->hasMany(Appointment::class, 'patient_id');
    }
    public function deviceTokens()
    {
        return $this->hasMany(DeviceToken::class);
    }


    public function doctorProfile(): Doctor
    {
        $doctor = $this->doctor;

        abort_if(! $doctor, 403, 'No doctor profile found.');
        abort_if($doctor->status === 'suspended', 403, 'Your doctor account is suspended.');

        return $doctor;
    }
     public function canAccessPanel(Panel $panel): bool
    {
        return $this->role === 'admin' && $this->is_active;
    }

    public function deactivate(): void
    {
        $this->forceFill(['is_active' => false])->save();
        $this->tokens()->delete(); // logs them out of the mobile app immediately
    }

    public function activate(): void
    {
        $this->forceFill(['is_active' => true])->save();
    }
}

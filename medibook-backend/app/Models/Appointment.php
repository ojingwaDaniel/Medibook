<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\User;

class Appointment extends Model
{
    protected $fillable = ['patient_id', 'doctor_id', 'starts_at', 'ends_at', 'reason'];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'reminder_sent_at' => 'datetime',
    ];

    public function patient()
    {
        return $this->belongsTo(User::class, 'patient_id');
    }
    public function doctor()
    {
        return $this->belongsTo(Doctor::class);
    }
    public function cancelledBy()
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'confirmed');
    }

    public function cancel(User $by, ?string $reason = null): void
    {
        $this->status = 'cancelled';
        $this->slot_lock = null;
        $this->cancelled_by = $by->id;
        $this->cancel_reason = $reason;
        $this->save();
    }

    public function isCancellableByPatient(): bool
    {
        if ($this->status !== 'confirmed') {
            return false;
        }

        $latest = $this->starts_at->copy()->subMinutes(config('booking.cancel_cutoff_minutes'));

        return now()->lte($latest);
    }
}

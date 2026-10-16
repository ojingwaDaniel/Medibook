<?php

namespace App\Policies;

use App\Models\Appointment;
use App\Models\User;

class AppointmentPolicy
{
    public function view(User $user, Appointment $appointment): bool
    {
        return $appointment->patient_id === $user->id;
    }

    public function cancel(User $user, Appointment $appointment): bool
    {
        return $appointment->patient_id === $user->id;
    }

    public function manage(User $user, Appointment $appointment): bool
    {
        return $user->role === 'doctor'
            && $user->doctor?->id === $appointment->doctor_id;
    }
}

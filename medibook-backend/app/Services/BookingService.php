<?php

namespace App\Services;

use App\Exceptions\BookingException;
use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

class BookingService
{
    public function __construct(private SlotGenerator $slots) {}

    public function book(User $patient, Doctor $doctor, CarbonImmutable $startsAt, ?string $reason = null): Appointment
    {
        try {
            return DB::transaction(function () use ($patient, $doctor, $startsAt, $reason) {
                // Queue concurrent bookings for this doctor (ignored by SQLite in tests)
                Doctor::whereKey($doctor->id)->lockForUpdate()->first();

                $active = Appointment::where('patient_id', $patient->id)
                    ->where('status', 'confirmed')
                    ->where('starts_at', '>', now())
                    ->count();

                if ($active >= config('booking.max_active_per_patient')) {
                    throw BookingException::tooManyActive();
                }

                if (! $this->slots->isSlotFree($doctor, $startsAt)) {
                    throw BookingException::slotTaken();
                }

                $appointment = new Appointment([
                    'patient_id' => $patient->id,
                    'doctor_id' => $doctor->id,
                    'starts_at' => $startsAt,
                    'ends_at' => $startsAt->addMinutes($doctor->slot_minutes),
                    'reason' => $reason,
                ]);
                $appointment->status = 'confirmed';
                $appointment->slot_lock = 1;
                $appointment->save();

                return $appointment;
            });
        } catch (UniqueConstraintViolationException) {
            // The unique index is the last line of defence if two requests slip through together.
            // It is caught outside the transaction because PostgreSQL aborts a transaction after an error.
            throw BookingException::slotTaken();
        }
    }

    public function cancelAsPatient(Appointment $appointment, User $patient, ?string $reason = null): Appointment
    {
        return DB::transaction(function () use ($appointment, $patient, $reason) {
            $appointment = Appointment::whereKey($appointment->id)->lockForUpdate()->firstOrFail();

            if ($appointment->status !== 'confirmed') {
                throw BookingException::cannotCancel('This appointment can no longer be cancelled.');
            }

            if (! $appointment->isCancellableByPatient()) {
                $hours = config('booking.cancel_cutoff_minutes') / 60;

                throw BookingException::cannotCancel(
                    "Appointments can only be cancelled at least {$hours} hours before they start."
                );
            }

            $appointment->cancel($patient, $reason);

            return $appointment;
        });
    }

    /** $status is 'completed' or 'no_show'. */
    public function markAttended(Appointment $appointment, string $status): Appointment
    {
        return DB::transaction(function () use ($appointment, $status) {
            $appointment = Appointment::whereKey($appointment->id)->lockForUpdate()->firstOrFail();

            if ($appointment->status !== 'confirmed') {
                throw BookingException::invalid('Only confirmed appointments can be updated.');
            }

            if ($appointment->starts_at->isFuture()) {
                throw BookingException::invalid('You can only mark an appointment after it has started.');
            }

            $appointment->status = $status;
            $appointment->save();

            return $appointment;
        });
    }

    public function cancelAsDoctor(Appointment $appointment, User $doctorUser, string $reason): Appointment
    {
        return DB::transaction(function () use ($appointment, $doctorUser, $reason) {
            $appointment = Appointment::whereKey($appointment->id)->lockForUpdate()->firstOrFail();

            if ($appointment->status !== 'confirmed') {
                throw BookingException::invalid('This appointment can no longer be cancelled.');
            }

            if ($appointment->starts_at->isPast()) {
                throw BookingException::invalid('This appointment has already started. Mark it completed or no-show instead.');
            }

            $appointment->cancel($doctorUser, $reason);

            return $appointment;
        });
    }
}

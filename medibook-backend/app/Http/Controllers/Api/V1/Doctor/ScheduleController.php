<?php

namespace App\Http\Controllers\Api\V1\Doctor;

use App\Http\Controllers\Controller;
use App\Http\Resources\DoctorAppointmentResource;
use App\Models\Appointment;
use App\Services\BookingService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ScheduleController extends Controller
{
    /** The doctor's appointments for one calendar day in the doctor's own timezone (default: today). */
    public function index(Request $request)
    {
        $doctor = $request->user()->doctorProfile();

        $data = $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d'],
            'status' => ['nullable', 'in:confirmed,completed,cancelled,no_show'],
        ]);

        $date = $data['date'] ?? now($doctor->timezone)->format('Y-m-d');
        $day = CarbonImmutable::createFromFormat('!Y-m-d', $date, $doctor->timezone);

        $appointments = $doctor->appointments()
            ->with('patient:id,name,phone')
            ->where('starts_at', '>=', $day->utc())
            ->where('starts_at', '<', $day->addDay()->utc())
            ->when($data['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->orderBy('starts_at')
            ->get();

        return DoctorAppointmentResource::collection($appointments);
    }

    public function complete(Request $request, Appointment $appointment, BookingService $booking)
    {
        return $this->attend($request, $appointment, $booking, 'completed');
    }

    public function noShow(Request $request, Appointment $appointment, BookingService $booking)
    {
        return $this->attend($request, $appointment, $booking, 'no_show');
    }

    public function cancel(Request $request, Appointment $appointment, BookingService $booking)
    {
        $request->user()->doctorProfile();
        Gate::authorize('manage', $appointment);

        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        $appointment = $booking->cancelAsDoctor($appointment, $request->user(), $data['reason']);

        return new DoctorAppointmentResource($appointment->load('patient:id,name,phone'));
    }

    private function attend(Request $request, Appointment $appointment, BookingService $booking, string $status)
    {
        $request->user()->doctorProfile();
        Gate::authorize('manage', $appointment);

        $appointment = $booking->markAttended($appointment, $status);

        return new DoctorAppointmentResource($appointment->load('patient:id,name,phone'));
    }
}
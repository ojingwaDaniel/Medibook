<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAppointmentRequest;
use App\Http\Resources\AppointmentResource;
use App\Models\Appointment;
use App\Models\Doctor;
use App\Services\BookingService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class AppointmentController extends Controller
{
    private const WITH = ['doctor.user:id,name', 'doctor.specialty'];

    public function index(Request $request)
    {
        $request->validate(['scope' => ['nullable', 'in:upcoming,past']]);

        $query = Appointment::where('patient_id', $request->user()->id)->with(self::WITH);

        match ($request->query('scope')) {
            'upcoming' => $query->where('status', 'confirmed')
                ->where('starts_at', '>=', now())
                ->orderBy('starts_at'),
            'past' => $query->where(fn ($q) => $q->where('status', '!=', 'confirmed')
                    ->orWhere('starts_at', '<', now()))
                ->orderByDesc('starts_at'),
            default => $query->orderByDesc('starts_at'),
        };

        return AppointmentResource::collection($query->paginate(15));
    }

    public function store(StoreAppointmentRequest $request, BookingService $booking): JsonResponse
    {
        $data = $request->validated();

        $doctor = Doctor::approved()->findOrFail($data['doctor_id']);

        $appointment = $booking->book(
            $request->user(),
            $doctor,
            CarbonImmutable::parse($data['starts_at'])->utc(),
            $data['reason'] ?? null,
        );

        return (new AppointmentResource($appointment->load(self::WITH)))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Appointment $appointment): AppointmentResource
    {
        Gate::authorize('view', $appointment);

        return new AppointmentResource($appointment->load(self::WITH));
    }

    public function cancel(Request $request, Appointment $appointment, BookingService $booking): AppointmentResource
    {
        Gate::authorize('cancel', $appointment);

        $data = $request->validate(['reason' => ['nullable', 'string', 'max:255']]);

        $appointment = $booking->cancelAsPatient($appointment, $request->user(), $data['reason'] ?? null);

        return new AppointmentResource($appointment->load(self::WITH));
    }
}
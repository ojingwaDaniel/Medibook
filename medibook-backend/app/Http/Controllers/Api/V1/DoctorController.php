<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\DoctorResource;
use App\Models\Doctor;
use App\Services\SlotGenerator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DoctorController extends Controller
{
    public function index(Request $request)
    {
        $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'specialty_id' => ['nullable', 'integer', 'exists:specialties,id'],
        ]);

        $doctors = Doctor::approved()
            ->with(['user:id,name', 'specialty'])
            ->when($request->filled('specialty_id'), fn ($q) => $q->where('specialty_id', $request->specialty_id))
            ->when($request->filled('search'), function ($q) use ($request) {
                $term = '%'.$request->search.'%';

                $q->where(function ($q) use ($term) {
                    $q->whereHas('user', fn ($u) => $u->where('name', 'like', $term))
                        ->orWhere('clinic_name', 'like', $term);
                });
            })
            ->orderBy('id')
            ->paginate(15);

        return DoctorResource::collection($doctors);
    }

    public function show(Doctor $doctor): DoctorResource
    {
        $this->ensureVisible($doctor);

        return new DoctorResource($doctor->load(['user:id,name', 'specialty']));
    }

    public function slots(Request $request, Doctor $doctor, SlotGenerator $generator): JsonResponse
    {
        $this->ensureVisible($doctor);

        $data = $request->validate([
            'date' => [
                'required',
                'date_format:Y-m-d',
                'after_or_equal:today',
                'before_or_equal:'.now()->addDays(60)->toDateString(),
            ],
        ]);

        $slots = $generator->forDate($doctor, $data['date'])
            ->map(fn (array $slot) => [
                'starts_at' => $slot['starts_at']->toIso8601ZuluString(),
                'ends_at' => $slot['ends_at']->toIso8601ZuluString(),
                'local_time' => $slot['local_time'],
            ])
            ->values();

        return response()->json([
            'data' => [
                'date' => $data['date'],
                'timezone' => $doctor->timezone,
                'slots' => $slots,
            ],
        ]);
    }

    private function ensureVisible(Doctor $doctor): void
    {
        abort_unless($doctor->status === 'approved', 404);
    }
}
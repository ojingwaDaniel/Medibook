<?php

namespace App\Http\Controllers\Api\V1\Doctor;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateAvailabilityRequest;
use App\Http\Resources\AvailabilityResource;
use App\Models\Doctor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AvailabilityController extends Controller
{
    public function show(Request $request)
    {
        return $this->respond($request->user()->doctorProfile());
    }

    /** Replaces the whole weekly schedule in one call. */
    public function update(UpdateAvailabilityRequest $request)
    {
        $doctor = $request->user()->doctorProfile();

        DB::transaction(function () use ($doctor, $request) {
            $doctor->availabilities()->delete();

            $doctor->availabilities()->createMany(
                collect($request->validated('availability'))
                    ->map(fn ($r) => [
                        'weekday' => $r['weekday'],
                        'start_time' => $r['start_time'],
                        'end_time' => $r['end_time'],
                    ])->all()
            );
        });

        return $this->respond($doctor);
    }

    private function respond(Doctor $doctor)
    {
        return AvailabilityResource::collection(
            $doctor->availabilities()->orderBy('weekday')->orderBy('start_time')->get()
        );
    }
}
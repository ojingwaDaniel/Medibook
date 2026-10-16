<?php

namespace App\Http\Controllers\Api\V1\Doctor;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreTimeOffRequest;
use App\Http\Resources\TimeOffResource;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TimeOffController extends Controller
{
    public function index(Request $request)
    {
        $doctor = $request->user()->doctorProfile();

        return TimeOffResource::collection(
            $doctor->timeOffs()->where('ends_at', '>=', now())->orderBy('starts_at')->get()
        );
    }

    public function store(StoreTimeOffRequest $request): JsonResponse
    {
        $doctor = $request->user()->doctorProfile();
        $starts = CarbonImmutable::parse($request->starts_at)->utc();
        $ends = CarbonImmutable::parse($request->ends_at)->utc();

        $booked = $doctor->appointments()
            ->where('status', 'confirmed')
            ->where('starts_at', '<', $ends)
            ->where('ends_at', '>', $starts)
            ->count();

        if ($booked > 0) {
            return response()->json([
                'message' => "You have {$booked} confirmed appointment(s) in this period. Cancel them first, then add the time off.",
            ], 422);
        }

        $timeOff = $doctor->timeOffs()->create([
            'starts_at' => $starts,
            'ends_at' => $ends,
            'reason' => $request->reason,
        ]);

        return (new TimeOffResource($timeOff))->response()->setStatusCode(201);
    }

    public function destroy(Request $request, int $timeOff): JsonResponse
    {
        $request->user()->doctorProfile()->timeOffs()->findOrFail($timeOff)->delete();

        return response()->json(['message' => 'Time off removed.']);
    }
}
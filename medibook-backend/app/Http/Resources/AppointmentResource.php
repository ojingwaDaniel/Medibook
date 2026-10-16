<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AppointmentResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $local = $this->starts_at->copy()->setTimezone($this->doctor->timezone);

        return [
            'id' => $this->id,
            'status' => $this->status,
            'starts_at' => $this->starts_at->toIso8601ZuluString(),
            'ends_at' => $this->ends_at->toIso8601ZuluString(),
            'local_date' => $local->format('Y-m-d'),
            'local_time' => $local->format('H:i'),
            'reason' => $this->reason,
            'can_cancel' => $this->isCancellableByPatient(),
            'doctor' => new DoctorResource($this->doctor),
        ];
    }
}

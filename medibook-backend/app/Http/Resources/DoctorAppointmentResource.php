<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DoctorAppointmentResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $local = $this->starts_at->copy()->setTimezone($request->user()->doctor->timezone);

        return [
            'id' => $this->id,
            'status' => $this->status,
            'starts_at' => $this->starts_at->toIso8601ZuluString(),
            'ends_at' => $this->ends_at->toIso8601ZuluString(),
            'local_date' => $local->format('Y-m-d'),
            'local_time' => $local->format('H:i'),
            'reason' => $this->reason,
            'patient' => [
                'id' => $this->patient->id,
                'name' => $this->patient->name,
                'phone' => $this->patient->phone,
            ],
            'can_mark_attended' => $this->status === 'confirmed' && $this->starts_at->isPast(),
            'can_cancel' => $this->status === 'confirmed' && $this->starts_at->isFuture(),
        ];
    }
}

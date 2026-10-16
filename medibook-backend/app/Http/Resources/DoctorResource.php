<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DoctorResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->user->name,
            'specialty' => new SpecialtyResource($this->whenLoaded('specialty')),
            'bio' => $this->bio,
            'years_experience' => $this->years_experience,
            'consultation_fee' => (float) $this->consultation_fee,
            'clinic_name' => $this->clinic_name,
            'clinic_address' => $this->clinic_address,
            'slot_minutes' => $this->slot_minutes,
            'timezone' => $this->timezone,
            'status' => $this->when($request->user()?->id === $this->user_id, $this->status),
        ];
    }
}

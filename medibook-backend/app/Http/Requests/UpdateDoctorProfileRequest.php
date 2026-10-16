<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateDoctorProfileRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'specialty_id' => ['sometimes', 'required', 'exists:specialties,id'],
            'bio' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'years_experience' => ['sometimes', 'required', 'integer', 'min:0', 'max:70'],
            'consultation_fee' => ['sometimes', 'required', 'numeric', 'min:0'],
            'clinic_name' => ['sometimes', 'required', 'string', 'max:255'],
            'clinic_address' => ['sometimes', 'required', 'string', 'max:255'],
            'slot_minutes' => ['sometimes', 'required', 'integer', 'in:15,20,30,45,60'],
            'timezone' => ['sometimes', 'required', 'timezone'],
        ];
    }
}

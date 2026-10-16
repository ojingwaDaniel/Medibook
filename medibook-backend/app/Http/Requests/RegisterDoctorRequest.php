<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class RegisterDoctorRequest extends FormRequest
{


    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'phone' => ['required', 'string', 'max:20', 'unique:users,phone'],
            'password' => ['required', 'confirmed', Password::min(8)],
            'specialty_id' => ['required', 'exists:specialties,id'],
            'clinic_name' => ['required', 'string', 'max:255'],
            'clinic_address' => ['required', 'string', 'max:255'],
            'consultation_fee' => ['required', 'numeric', 'min:0'],
            'years_experience' => ['nullable', 'integer', 'min:0', 'max:70'],
            'bio' => ['nullable', 'string', 'max:2000'],
            'timezone' => ['nullable', 'timezone'],
        ];
    }
}

<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreAppointmentRequest extends FormRequest
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
            'doctor_id' => ['required', 'integer'],
            'starts_at' => [
                'required',
                'date',
                'after:now',
                'before_or_equal:' . now()->addDays(60)->toDateTimeString(),
            ],
            'reason' => ['nullable', 'string', 'max:255'],
        ];
    }
}

<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateAvailabilityRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    

    private const DAYS = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'availability' => ['present', 'array', 'max:28'],
            'availability.*.weekday' => ['required', 'integer', 'between:0,6'],
            'availability.*.start_time' => ['required', 'date_format:H:i'],
            'availability.*.end_time' => ['required', 'date_format:H:i', 'after:availability.*.start_time'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            foreach (collect($this->input('availability'))->groupBy('weekday') as $weekday => $ranges) {
                $sorted = $ranges->sortBy('start_time')->values();

                for ($i = 1; $i < $sorted->count(); $i++) {
                    // zero-padded H:i strings compare correctly as text
                    if ($sorted[$i]['start_time'] < $sorted[$i - 1]['end_time']) {
                        $validator->errors()->add('availability', 'Working hours overlap on ' . self::DAYS[$weekday] . '.');

                        return;
                    }
                }
            }
        }];
    }
}

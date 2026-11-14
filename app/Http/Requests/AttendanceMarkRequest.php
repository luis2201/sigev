<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AttendanceMarkRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasRole('student');
    }

    public function rules(): array
    {
        return ['request_key' => ['required', 'uuid'], 'local_date' => ['prohibited'], 'actual_entry_at' => ['prohibited'], 'actual_exit_at' => ['prohibited'], 'hours' => ['prohibited'], 'minutes' => ['prohibited']];
    }
}

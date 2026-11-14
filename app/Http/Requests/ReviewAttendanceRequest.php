<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReviewAttendanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('review-attendance', $this->route('day'));
    }

    public function rules(): array
    {
        return ['request_key' => ['required', 'uuid'], 'revision_number' => ['required', 'integer', 'min:1'],
            'decision' => ['required', Rule::in(['ordinary', 'exceptional', 'observed', 'rejected', 'reopened'])],
            'justification' => ['nullable', 'required_unless:decision,ordinary', 'string', 'min:10', 'max:5000'],
            'circumstance' => ['nullable', 'required_if:decision,exceptional', 'string', 'max:1000'],
            'support_reference' => ['nullable', 'required_if:decision,exceptional', 'string', 'min:5', 'max:1000'],
            'approved_minutes' => ['prohibited'], 'approved_hours' => ['prohibited']];
    }
}

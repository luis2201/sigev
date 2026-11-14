<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PlacementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage-placements');
    }

    public function rules(): array
    {
        return [
            'academic_period_id' => ['required', 'integer', 'exists:academic_periods,id'],
            'participation_id' => ['nullable', 'required_without:placement_group_id', 'prohibits:placement_group_id', 'integer', 'exists:participations,id'],
            'placement_group_id' => ['nullable', 'required_without:participation_id', 'prohibits:participation_id', 'integer', 'exists:placement_groups,id'],
            'project_id' => ['required', 'integer', 'exists:projects,id'],
            'host_institution_id' => ['required', 'integer', 'exists:host_institutions,id'],
            'institution_area_id' => ['required', 'integer', 'exists:institution_areas,id'],
            'institutional_tutor_id' => ['required', 'integer', 'exists:institutional_tutors,id'],
            'teacher_id' => ['required', 'integer', 'exists:teachers,id'],
            'practice_starts_on' => ['required', 'date_format:Y-m-d'],
            'practice_ends_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:practice_starts_on'],
            'required_hours' => ['required', 'numeric', 'min:0.0166666667', 'max:10000'],
            'replace_existing' => ['sometimes', 'boolean'],
            'reason' => ['required', 'string', 'max:1000'],
            'intervals' => ['required', 'array', 'min:1', 'max:50'],
            'intervals.*' => ['array:weekday,starts_at,ends_at,valid_from,valid_until'],
            'intervals.*.weekday' => ['required', 'integer', 'between:1,7'],
            'intervals.*.starts_at' => ['required', 'date_format:H:i'],
            'intervals.*.ends_at' => ['required', 'date_format:H:i'],
            'intervals.*.valid_from' => ['required', 'date_format:Y-m-d'],
            'intervals.*.valid_until' => ['nullable', 'date_format:Y-m-d'],
        ];
    }
}

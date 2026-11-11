<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AcademicRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasRole('admin');
    }

    public function rules(): array
    {
        $period = $this->routeIs('academic.periods.*');
        $record = $this->route($period ? 'period' : 'career');
        $rules = ['code' => ['required', 'string', 'max:40', Rule::unique($period ? 'academic_periods' : 'careers')->ignore($record?->id)], 'name' => ['required', 'string', 'max:'.($period ? 150 : 200)], 'status' => ['required', Rule::in($period ? ['planned', 'active', 'closed'] : ['active', 'inactive'])]];
        if ($period) {
            $rules += ['starts_on' => ['required', 'date_format:Y-m-d'], 'ends_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:starts_on']];
        }

        return $rules;
    }
}

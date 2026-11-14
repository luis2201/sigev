<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class OperationalCatalogRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage-placements');
    }

    protected function prepareForValidation(): void
    {
        $clean = [];
        foreach (['name', 'code', 'address', 'province', 'canton', 'email', 'phone'] as $field) {
            if ($this->has($field)) {
                $clean[$field] = preg_replace('/\s+/u', ' ', trim((string) $this->input($field)));
            }
        }
        $this->merge($clean);
    }

    public function rules(): array
    {
        $kind = $this->route('catalog');
        $rules = ['name' => ['required', 'string', 'max:'.match ($kind) {
            'areas', 'groups' => 150, 'institutional-tutors' => 200, default => 255
        }], 'status' => ['required', Rule::in(['active', 'inactive'])], 'revision' => ['nullable', 'string', 'max:40']];
        if (in_array($kind, ['projects', 'institutions'], true)) {
            $rules['code'] = ['required', 'string', 'max:50'];
        }
        if (in_array($kind, ['projects', 'groups'], true)) {
            $rules['academic_period_id'] = ['required', 'integer', 'exists:academic_periods,id'];
        }
        if ($kind === 'projects') {
            $rules['career_id'] = ['nullable', 'integer', 'exists:careers,id'];
        }
        if ($kind === 'institutions') {
            foreach (['address' => 500, 'province' => 100, 'canton' => 100] as $field => $max) {
                $rules[$field] = ['required', 'string', 'max:'.$max];
            }
        }
        if (in_array($kind, ['areas', 'institutional-tutors'], true)) {
            $rules['host_institution_id'] = ['required', 'integer', 'exists:host_institutions,id'];
        }
        if ($kind === 'institutional-tutors') {
            $rules['institution_area_id'] = ['nullable', 'integer', 'exists:institution_areas,id'];
            $rules['email'] = ['nullable', 'email', 'max:254'];
            $rules['phone'] = ['nullable', 'string', 'max:30'];
        }

        return $rules;
    }
}

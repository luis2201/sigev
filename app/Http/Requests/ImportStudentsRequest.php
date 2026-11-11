<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ImportStudentsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasRole('admin');
    }

    public function rules(): array
    {
        return ['academic_period_id' => ['required', 'integer', Rule::exists('academic_periods', 'id')->where(fn ($q) => $q->whereIn('status', ['planned', 'active']))], 'file' => ['required', 'file', 'extensions:xlsx', 'mimetypes:application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/zip', 'max:'.config('academic.import_max_kb')]];
    }
}

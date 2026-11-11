<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TeacherProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasRole('admin') || $this->user()->hasRole('coordinator') || ($this->user()->hasRole('tutor') && $this->user()->id === $this->route('account')->id);
    }

    public function rules(): array
    {
        return ['academic_title' => ['nullable', 'string', 'max:150'], 'status' => [$this->user()->hasRole('admin') || $this->user()->hasRole('coordinator') ? 'required' : 'prohibited', Rule::in(['active', 'inactive'])]];
    }
}

<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreActivityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasRole('student');
    }

    public function rules(): array
    {
        return ['description' => ['required', 'string', 'max:5000'], 'photo' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'extensions:jpg,jpeg,png,webp', 'max:'.config('attendance.photo_max_kb'), 'dimensions:max_width='.config('attendance.photo_max_dimension').',max_height='.config('attendance.photo_max_dimension')], 'registered_at' => ['prohibited'], 'local_date' => ['prohibited'], 'student_id' => ['prohibited'], 'hours' => ['prohibited']];
    }

    public function messages(): array
    {
        return ['photo.required' => 'Cada actividad debe incluir una fotografía.', 'photo.image' => 'Seleccione una fotografía válida.', 'photo.mimes' => 'Se permiten fotografías JPEG, PNG o WebP.', 'photo.max' => 'La fotografía supera el tamaño máximo permitido.'];
    }
}

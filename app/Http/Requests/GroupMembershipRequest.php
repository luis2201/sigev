<?php

namespace App\Http\Requests;

use App\Models\Participation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class GroupMembershipRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage-placements');
    }

    public function rules(): array
    {
        return [
            'participation_id' => ['nullable', 'required_without:participation_ids', 'prohibits:participation_ids', 'integer', 'exists:participations,id'],
            'participation_ids' => ['nullable', 'required_without:participation_id', 'prohibits:participation_id', 'array', 'min:1', 'max:500'],
            'participation_ids.*' => ['required', 'integer', 'distinct'],
            'reason' => ['required', 'string', 'max:1000'],
            'action' => ['required', 'in:add,remove'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty() || ! $this->filled('participation_ids')) {
                return;
            }
            $ids = $this->input('participation_ids');
            if (Participation::whereIn('id', $ids)->count() !== count($ids)) {
                $validator->errors()->add('participation_ids', 'Uno de los estudiantes seleccionados ya no existe. Actualice la lista.');
            }
        }];
    }

    public function messages(): array
    {
        return ['participation_ids.required_without' => 'Seleccione al menos un estudiante.', 'participation_ids.max' => 'Seleccione como máximo 500 estudiantes por operación.', 'participation_ids.*.distinct' => 'La selección contiene estudiantes repetidos.'];
    }
}

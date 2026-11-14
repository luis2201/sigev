<?php

namespace App\Http\Requests;

class UpdatePlacementRequest extends PlacementRequest
{
    protected function prepareForValidation(): void
    {
        $participation = $this->route('participation');
        $group = $this->route('group');
        $this->merge([
            'academic_period_id' => ($participation ?? $group)->academic_period_id,
            'participation_id' => $participation?->id,
            'placement_group_id' => $group?->id,
            'replace_existing' => true,
        ]);
    }

    public function rules(): array
    {
        return parent::rules() + [
            'edit_token' => ['required', 'uuid'],
            'selected_participation_ids' => $this->route('group') ? ['required', 'array', 'min:1', 'max:500'] : ['prohibited'],
            'selected_participation_ids.*' => ['required', 'integer', 'distinct'],
        ];
    }

    public function messages(): array
    {
        return ['selected_participation_ids.required' => 'Seleccione al menos un integrante al que aplicar los cambios.', 'selected_participation_ids.max' => 'Puede editar hasta 500 integrantes por operación.'];
    }
}

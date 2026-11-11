<?php

namespace App\Http\Requests;

use App\Models\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasRole('admin') || ($this->isMethod('post') && $this->user()->hasRole('coordinator'));
    }

    protected function prepareForValidation(): void
    {
        $email = $this->input('email');
        $identification = $this->input('identification_number');
        $this->merge(['email' => is_string($email) ? mb_strtolower(trim($email)) : $email, 'identification_number' => is_string($identification) ? trim($identification) : $identification, 'roles' => $this->input('roles', [])]);
    }

    public function rules(): array
    {
        $id = $this->route('user')?->id;

        return [
            'name' => ['required', 'string', 'max:200'],
            'identification_type' => ['required', Rule::in(['cedula', 'pasaporte', 'otro'])],
            'identification_number' => ['required', 'string', 'max:30', 'regex:/^[\pL\pN-]+$/u', Rule::unique('users')->where(fn ($q) => $q->where('identification_type', $this->input('identification_type')))->ignore($id)],
            'email' => ['required', 'email', 'max:254', 'ends_with:@'.config('sigev.institutional_domain'), Rule::unique('users')->ignore($id)],
            'status' => ['required', Rule::in(['active', 'inactive', 'suspended'])],
            'roles' => ['present', 'array', $this->isMethod('post') ? 'min:1' : 'min:0'],
            'roles.*' => ['string', 'distinct', Rule::in(array_keys(Role::LABELS))],
            'reason' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if (! $this->user()->hasRole('admin') && ($this->input('roles') !== ['tutor'] || $this->input('status') !== 'active')) {
                $validator->errors()->add('roles', 'El coordinador únicamente puede registrar docentes tutores activos.');
            }
        });
    }

    public function messages(): array
    {
        return ['email.ends_with' => 'Utilice un correo del dominio institucional.', 'email.unique' => 'El correo ya está registrado.', 'identification_number.unique' => 'La identificación ya está registrada.', 'required' => 'Este campo es obligatorio.', 'roles.min' => 'Seleccione al menos un rol para la cuenta nueva.'];
    }
}

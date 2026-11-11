<?php

namespace App\Console\Commands;

use App\Models\Role;
use App\Services\UserManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class BootstrapAdmin extends Command
{
    protected $signature = 'sigev:bootstrap-admin';

    protected $description = 'Crear de forma interactiva y controlada el primer administrador de SIGEV-ITSUP';

    public function handle(UserManager $manager): int
    {
        if (! $this->input->isInteractive()) {
            $this->error('Este comando requiere una consola interactiva.');

            return self::FAILURE;
        }
        $role = Role::where('code', 'admin')->first();
        if ($role && DB::table('role_user')->where('role_id', $role->id)->exists()) {
            $this->error('La inicialización ya está cerrada: existe historial de administrador.');

            return self::FAILURE;
        }
        $data = [
            'name' => trim((string) $this->ask('Nombre completo')),
            'identification_type' => $this->choice('Tipo de identificación', ['cedula', 'pasaporte', 'otro']),
            'identification_number' => trim((string) $this->ask('Identificación')),
            'email' => mb_strtolower(trim((string) $this->ask('Correo institucional'))),
            'password' => $this->secret('Contraseña segura (mínimo 12 caracteres, mayúsculas, minúsculas, números y símbolos)', false),
            'password_confirmation' => $this->secret('Confirme la contraseña', false),
            'status' => 'active', 'roles' => ['admin'],
        ];
        $validator = Validator::make($data, [
            'name' => ['required', 'string', 'max:200'],
            'identification_type' => ['required', Rule::in(['cedula', 'pasaporte', 'otro'])],
            'identification_number' => ['required', 'string', 'max:30', 'regex:/^[\pL\pN-]+$/u'],
            'email' => ['required', 'email', 'max:254', 'ends_with:@'.config('sigev.institutional_domain')],
            'password' => ['required', 'string', 'max:128', 'confirmed', 'different:identification_number', Password::min(12)->mixedCase()->numbers()->symbols()],
        ]);
        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }
        try {
            $password = $data['password'];
            unset($data['password'], $data['password_confirmation']);
            $manager->save(null, null, $data, $password);
            unset($password);
        } catch (ValidationException $e) {
            foreach ($e->errors() as $messages) {
                foreach ($messages as $message) {
                    $this->error($message);
                }
            }

            return self::FAILURE;
        }
        $this->info('Administrador creado. Deberá cambiar su contraseña al iniciar sesión.');

        return self::SUCCESS;
    }
}

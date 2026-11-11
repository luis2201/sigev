<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request, AuditLogger $audit)
    {
        $email = $request->input('email');
        $request->merge(['email' => is_string($email) ? mb_strtolower(trim($email)) : $email]);
        $data = $request->validate(['email' => ['required', 'email', 'max:254'], 'password' => ['required', 'string', 'max:1024']]);
        $key = 'login:'.hash('sha256', $data['email'].'|'.$request->ip());
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages(['email' => 'Demasiados intentos. Espere un minuto antes de volver a intentar.']);
        }
        RateLimiter::hit($key, 60);
        $user = User::with('roles')->where('email', $data['email'])->first();
        // Always perform a password comparison, including unknown accounts.
        $dummyHash = Hash::make(Str::random(40));
        $valid = Hash::check($data['password'], $user?->password ?? $dummyHash);
        if (! $valid || ! $user || $user->status !== 'active' || $user->roles->isEmpty()) {
            throw ValidationException::withMessages(['email' => 'No fue posible iniciar sesión con las credenciales proporcionadas.']);
        }
        DB::transaction(function () use ($user, $audit) {
            $user->last_login_at = now('UTC');
            $user->save();
            $audit->record($user, 'auth.login', $user->id);
        });
        RateLimiter::clear($key);
        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route($user->must_change_password ? 'password.edit' : 'dashboard');
    }

    public function logout(Request $request, AuditLogger $audit)
    {
        if ($request->user()) {
            $audit->record($request->user(), 'auth.logout', $request->user()->id);
        }
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }

    public function password(Request $request, AuditLogger $audit)
    {
        $data = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'string', 'max:128', 'confirmed', 'different:current_password', Password::min(12)->mixedCase()->numbers()->symbols()],
        ], ['current_password.current_password' => 'La contraseña actual no es válida.', 'password.confirmed' => 'La confirmación no coincide.', 'password.different' => 'La nueva contraseña debe ser diferente.']);
        $user = $request->user();
        if (hash_equals($user->identification_number, $data['password'])) {
            throw ValidationException::withMessages(['password' => 'La nueva contraseña no puede ser su identificación.']);
        }
        DB::transaction(function () use ($user, $data, $audit, $request) {
            $locked = User::lockForUpdate()->findOrFail($user->id);
            abort_unless($locked->status === 'active', 403);
            if (! Hash::check($data['current_password'], $locked->password)) {
                throw ValidationException::withMessages(['current_password' => 'La contraseña actual no es válida.']);
            }
            $locked->password = Hash::make($data['password']);
            $locked->must_change_password = false;
            $locked->password_changed_at = now('UTC');
            $locked->remember_token = Str::random(60);
            $locked->save();
            DB::table('sessions')->where('user_id', $user->id)->where('id', '!=', $request->session()->getId())->delete();
            $audit->record($locked, 'auth.password_changed', $locked->id);
            Auth::setUser($locked);
        });
        $request->session()->regenerate();

        return redirect()->route('dashboard')->with('success', 'Contraseña actualizada correctamente.');
    }
}

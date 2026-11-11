<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class AccountAccess
{
    public function handle(Request $request, Closure $next, ?string $scope = null): Response
    {
        $request->attributes->set('audit_request_id', (string) Str::uuid());
        $user = $request->user();
        if (! $user || $user->status !== 'active') {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->withErrors(['email' => 'No es posible acceder con esta cuenta.']);
        }
        $user->loadMissing('roles');
        if ($user->roles->isEmpty()) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->withErrors(['email' => 'No es posible acceder con esta cuenta.']);
        }
        if ($scope !== 'password' && $user->must_change_password) {
            return redirect()->route('password.edit');
        }
        if ($scope === 'admin') {
            abort_unless($user->hasRole('admin'), 403);
        }
        if ($scope === 'users') {
            abort_unless($user->hasRole('admin') || $user->hasRole('coordinator'), 403);
        }

        return $next($request);
    }
}

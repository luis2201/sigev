<?php

namespace App\Http\Controllers;

use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __invoke(Request $request)
    {
        $counts = [];
        $coordinator = null;
        if ($request->user()->hasRole('admin')) {
            $counts = ['Usuarios registrados' => User::count(), 'Cuentas activas' => User::where('status', 'active')->count()];
            $counts['Cuentas de docentes tutores'] = User::whereHas('roles', fn ($q) => $q->where('code', 'tutor'))->count();
            $counts['Cuentas de estudiantes'] = User::whereHas('roles', fn ($q) => $q->where('code', 'student'))->count();
            $coordinator = Role::where('code', 'coordinator')->first()?->users()->where('status', 'active')->first();
        }

        return view('dashboard', compact('counts', 'coordinator'));
    }
}

<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaveUserRequest;
use App\Models\Role;
use App\Models\User;
use App\Services\UserManager;
use Illuminate\Http\Request;

class UserController extends Controller
{
    public function index(Request $request)
    {
        $filters = $request->validate(['q' => ['nullable', 'string', 'max:200'], 'role' => ['nullable', 'in:admin,coordinator,tutor,student'], 'status' => ['nullable', 'in:active,inactive,suspended']]);
        $users = User::with('roles')->when($filters['q'] ?? null, fn ($q, $s) => $q->where(fn ($q) => $q->where('name', 'like', '%'.$s.'%')->orWhere('email', 'like', '%'.$s.'%')->orWhere('identification_number', 'like', '%'.$s.'%')))->when($filters['role'] ?? null, fn ($q, $role) => $q->whereHas('roles', fn ($q) => $q->where('code', $role)))->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))->orderBy('name')->paginate(15)->withQueryString();

        return view('users.index', ['users' => $users, 'labels' => Role::LABELS]);
    }

    public function create()
    {
        return view('users.form', ['account' => new User(['identification_type' => 'cedula']), 'labels' => Role::LABELS]);
    }

    public function store(SaveUserRequest $request, UserManager $manager)
    {
        $manager->save($request->user(), null, $request->validated());

        return redirect()->route($request->user()->hasRole('admin') ? 'users.index' : 'dashboard')->with('success', 'Cuenta creada. Se exigirá el cambio de contraseña en el primer acceso.');
    }

    public function edit(User $user)
    {
        return view('users.form', ['account' => $user->load('roles'), 'labels' => Role::LABELS]);
    }

    public function update(SaveUserRequest $request, User $user, UserManager $manager)
    {
        $manager->save($request->user(), $user, $request->validated());

        return redirect()->route('users.index')->with('success', 'Cuenta actualizada. Los cambios de acceso se aplican inmediatamente.');
    }
}

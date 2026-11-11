<?php

use App\Http\Controllers\AcademicController;
use App\Http\Controllers\AuditController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\StudentImportController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect()->route(auth()->check() ? 'dashboard' : 'login'));
Route::middleware('guest')->group(function () {
    Route::view('/login', 'auth.login')->name('login');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:20,1')->name('login.store');
});
Route::middleware(['auth', 'account:password'])->group(function () {
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
    Route::view('/perfil', 'auth.password')->name('password.edit');
    Route::put('/perfil/password', [AuthController::class, 'password'])->middleware('throttle:6,1')->name('password.update');
});
Route::middleware(['auth', 'account'])->group(function () {
    Route::get('/panel', DashboardController::class)->name('dashboard');
    Route::middleware('account:users')->group(function () {
        Route::get('/usuarios/crear', [UserController::class, 'create'])->name('users.create');
        Route::post('/usuarios', [UserController::class, 'store'])->name('users.store');
    });
    Route::middleware('account:admin')->group(function () {
        Route::get('/usuarios', [UserController::class, 'index'])->name('users.index');
        Route::get('/usuarios/{user}/editar', [UserController::class, 'edit'])->name('users.edit');
        Route::put('/usuarios/{user}', [UserController::class, 'update'])->name('users.update');
        Route::get('/auditoria', AuditController::class)->name('audit.index');
    });
});

Route::middleware(['auth', 'account'])->prefix('academico')->name('academic.')->group(function () {
    Route::middleware('can:manage-academic')->group(function () {
        Route::get('/carreras', [AcademicController::class, 'careers'])->name('careers.index');
        Route::get('/carreras/crear', [AcademicController::class, 'careerForm'])->name('careers.create');
        Route::post('/carreras', [AcademicController::class, 'saveCareer'])->name('careers.store');
        Route::get('/carreras/{career}/editar', [AcademicController::class, 'careerForm'])->name('careers.edit');
        Route::put('/carreras/{career}', [AcademicController::class, 'saveCareer'])->name('careers.update');
        Route::get('/periodos', [AcademicController::class, 'periods'])->name('periods.index');
        Route::get('/periodos/crear', [AcademicController::class, 'periodForm'])->name('periods.create');
        Route::post('/periodos', [AcademicController::class, 'savePeriod'])->name('periods.store');
        Route::get('/periodos/{period}/editar', [AcademicController::class, 'periodForm'])->name('periods.edit');
        Route::put('/periodos/{period}', [AcademicController::class, 'savePeriod'])->name('periods.update');
        Route::get('/estudiantes', [AcademicController::class, 'students'])->name('students.index');
        Route::get('/importaciones', [StudentImportController::class, 'index'])->name('imports.index');
        Route::get('/importaciones/crear', [StudentImportController::class, 'create'])->name('imports.create');
        Route::get('/importaciones/plantilla', [StudentImportController::class, 'template'])->name('imports.template');
        Route::post('/importaciones', [StudentImportController::class, 'preview'])->name('imports.preview');
        Route::get('/importaciones/{batch}', [StudentImportController::class, 'show'])->name('imports.show');
        Route::post('/importaciones/{batch}/confirmar', [StudentImportController::class, 'confirm'])->name('imports.confirm');
        Route::post('/importaciones/{batch}/cancelar', [StudentImportController::class, 'cancel'])->name('imports.cancel');
    });
    Route::get('/docentes', [AcademicController::class, 'teachers'])->middleware('can:manage-teachers')->name('teachers.index');
    Route::get('/docentes/{account}/perfil', [AcademicController::class, 'teacherForm'])->name('teachers.edit');
    Route::put('/docentes/{account}/perfil', [AcademicController::class, 'saveTeacher'])->name('teachers.update');
});

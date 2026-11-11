<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::define('manage-academic', fn (User $user) => $user->hasRole('admin'));
        Gate::define('manage-teachers', fn (User $user) => $user->hasRole('admin') || $user->hasRole('coordinator'));
    }
}

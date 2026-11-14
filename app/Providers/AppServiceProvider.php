<?php

namespace App\Providers;

use App\Models\AttendanceDay;
use App\Models\Participation;
use App\Models\User;
use App\Services\AttendanceAccess;
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
        Gate::define('view-attendance', fn (User $user, AttendanceDay $day) => app(AttendanceAccess::class)->canView($user, $day));
        Gate::define('review-attendance', fn (User $user, AttendanceDay $day) => app(AttendanceAccess::class)->canReview($user, $day));
        Gate::define('manage-placements', fn (User $user) => $user->hasRole('coordinator'));
        Gate::define('view-placement', function (User $user, Participation $participation): bool {
            return $user->hasRole('coordinator') || ($user->hasRole('student') && $participation->student->user_id === $user->id) || ($user->hasRole('tutor') && $participation->tutorAssignments()->whereHas('teacher', fn ($query) => $query->where('user_id', $user->id))->exists());
        });
        Gate::define('manage-academic', fn (User $user) => $user->hasRole('admin'));
        Gate::define('manage-teachers', fn (User $user) => $user->hasRole('admin') || $user->hasRole('coordinator'));
    }
}

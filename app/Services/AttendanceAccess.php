<?php

namespace App\Services;

use App\Models\AttendanceDay;
use App\Models\Participation;
use App\Models\TutorAssignment;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class AttendanceAccess
{
    public function owner(User $actor, Participation $participation): bool
    {
        return $actor->hasRole('student') && $participation->student->user_id === $actor->id;
    }

    public function tutor(User $actor, Participation $participation): ?TutorAssignment
    {
        if (! $actor->hasRole('tutor') || $participation->student->user_id === $actor->id) {
            return null;
        }

        return $participation->tutorAssignments()->where('valid_from', '<=', now('UTC'))->where(fn ($query) => $query->whereNull('valid_until')->orWhere('valid_until', '>', now('UTC')))
            ->whereHas('teacher', fn ($query) => $query->where('status', 'active')->where('user_id', $actor->id))->first();
    }

    public function canReview(User $actor, AttendanceDay $day): bool
    {
        return $this->tutor($actor, $day->participation) !== null;
    }

    public function canView(User $actor, AttendanceDay $day): bool
    {
        if ($this->owner($actor, $day->participation) || $actor->hasRole('coordinator') || $this->canReview($actor, $day)) {
            return true;
        }
        if (! $actor->hasRole('tutor')) {
            return false;
        }
        if ($day->reviews()->where('reviewed_by', $actor->id)->exists()) {
            return true;
        }

        return $day->participation->tutorAssignments()->whereHas('teacher', fn ($query) => $query->where('user_id', $actor->id))->where('valid_from', '<=', $day->created_at)
            ->where(fn ($query) => $query->whereNull('valid_until')->orWhere('valid_until', '>', $day->created_at))->exists();
    }

    public function scope(Builder $query, User $actor): Builder
    {
        if ($actor->hasRole('coordinator')) {
            return $query;
        }

        return $query->where(function (Builder $query) use ($actor): void {
            if ($actor->hasRole('student')) {
                $query->orWhereHas('participation.student', fn ($query) => $query->where('user_id', $actor->id));
            }
            if ($actor->hasRole('tutor')) {
                $query->orWhereHas('reviews', fn ($query) => $query->where('reviewed_by', $actor->id))
                    ->orWhereHas('participation.tutorAssignments', fn ($query) => $query->whereHas('teacher', fn ($query) => $query->where('user_id', $actor->id))->where(function ($query): void {
                        $query->where(fn ($query) => $query->where('valid_from', '<=', now('UTC'))->where(fn ($query) => $query->whereNull('valid_until')->orWhere('valid_until', '>', now('UTC'))))
                            ->orWhere(fn ($query) => $query->whereColumn('valid_from', '<=', 'attendance_days.created_at')->where(fn ($query) => $query->whereNull('valid_until')->orWhereColumn('valid_until', '>', 'attendance_days.created_at')));
                    }));
            }
            if (! $actor->hasRole('student') && ! $actor->hasRole('tutor')) {
                $query->whereRaw('1 = 0');
            }
        });
    }
}

<?php

namespace App\Models;

use App\Models\Concerns\UsesUtcDates;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Participation extends Model
{
    use UsesUtcDates;

    protected $guarded = ['id'];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function career(): BelongsTo
    {
        return $this->belongsTo(Career::class);
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(AcademicPeriod::class, 'academic_period_id');
    }

    public function careerRecord(): BelongsTo
    {
        return $this->belongsTo(StudentCareerRecord::class, 'student_career_record_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function configurations(): HasMany
    {
        return $this->hasMany(ParticipationConfiguration::class);
    }

    public function currentConfiguration(): HasOne
    {
        return $this->hasOne(ParticipationConfiguration::class)->whereNull('valid_until');
    }

    public function tutorAssignments(): HasMany
    {
        return $this->hasMany(TutorAssignment::class);
    }

    public function currentTutor(): HasOne
    {
        return $this->hasOne(TutorAssignment::class)->whereNull('valid_until');
    }

    public function attendanceDays(): HasMany
    {
        return $this->hasMany(AttendanceDay::class);
    }
}

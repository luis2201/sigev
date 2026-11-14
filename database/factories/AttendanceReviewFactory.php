<?php

namespace Database\Factories;

use App\Models\AttendanceReview;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<AttendanceReview>
 */
class AttendanceReviewFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'sequence_number' => 1, 'decision' => 'observed', 'justification' => 'Revisión de prueba pendiente de aclaración', 'approved_minutes' => '0.000000', 'computable_minutes_snapshot' => '0.000000', 'day_revision_number' => 1, 'reviewed_at' => now('UTC'), 'request_key' => (string) Str::uuid(),
        ];
    }
}

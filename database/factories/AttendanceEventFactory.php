<?php

namespace Database\Factories;

use App\Models\AttendanceEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AttendanceEvent>
 */
class AttendanceEventFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'from_status' => null, 'to_status' => 'in_progress', 'reason' => 'entry', 'occurred_at' => now('UTC'),
        ];
    }
}

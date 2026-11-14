<?php

namespace Database\Factories;

use App\Models\AttendanceDay;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AttendanceDay>
 */
class AttendanceDayFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'local_date' => now('America/Guayaquil')->toDateString(), 'status' => 'in_progress', 'revision_number' => 1,
        ];
    }
}

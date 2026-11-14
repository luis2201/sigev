<?php

namespace Database\Factories;

use App\Models\AttendanceIncident;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AttendanceIncident>
 */
class AttendanceIncidentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'kind' => 'other', 'observation' => fake()->sentence(),
        ];
    }
}

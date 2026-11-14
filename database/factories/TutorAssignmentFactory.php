<?php

namespace Database\Factories;

use App\Models\TutorAssignment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<TutorAssignment> */
class TutorAssignmentFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['valid_from' => now('UTC'), 'valid_until' => null, 'assigned_by' => User::factory(), 'reason' => 'Asignación de prueba'];
    }
}

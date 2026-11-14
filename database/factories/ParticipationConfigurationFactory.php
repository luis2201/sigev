<?php

namespace Database\Factories;

use App\Models\ParticipationConfiguration;
use App\Models\PlacementConfiguration;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ParticipationConfiguration> */
class ParticipationConfigurationFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['valid_from' => now('UTC'), 'valid_until' => null, 'assigned_by' => User::factory(), 'reason' => 'Asignación de prueba', 'placement_configuration_id' => PlacementConfiguration::factory(), 'academic_period_id' => fn (array $attributes) => PlacementConfiguration::findOrFail($attributes['placement_configuration_id'])->academic_period_id];
    }
}

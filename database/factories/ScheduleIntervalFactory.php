<?php

namespace Database\Factories;

use App\Models\PlacementConfiguration;
use App\Models\ScheduleInterval;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ScheduleInterval> */
class ScheduleIntervalFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['placement_configuration_id' => PlacementConfiguration::factory(), 'weekday' => 1, 'starts_at' => '08:00', 'ends_at' => '12:00', 'valid_from' => '2026-11-01', 'valid_until' => null, 'status' => 'active'];
    }
}

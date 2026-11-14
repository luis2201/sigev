<?php

namespace Database\Factories;

use App\Models\AcademicPeriod;
use App\Models\PlacementGroup;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PlacementGroup> */
class PlacementGroupFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['academic_period_id' => fn () => AcademicPeriod::create(['code' => fake()->unique()->bothify('PER-####??'), 'name' => 'Período de prueba', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'status' => 'active', 'created_by' => User::factory()->create()->id])->id, 'name' => fake()->unique()->bothify('Grupo ####??'), 'created_by' => User::factory(), 'status' => 'active'];
    }
}

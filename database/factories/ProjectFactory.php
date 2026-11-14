<?php

namespace Database\Factories;

use App\Models\AcademicPeriod;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Project> */
class ProjectFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['academic_period_id' => fn () => AcademicPeriod::create(['code' => fake()->unique()->bothify('PER-####??'), 'name' => 'Período de prueba', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'status' => 'active', 'created_by' => User::factory()->create()->id])->id, 'career_id' => null, 'code' => fake()->unique()->bothify('PR-####??'), 'name' => fake()->sentence(4), 'status' => 'active'];
    }
}

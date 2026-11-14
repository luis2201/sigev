<?php

namespace Database\Factories;

use App\Models\HostInstitution;
use App\Models\InstitutionArea;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<InstitutionArea> */
class InstitutionAreaFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['host_institution_id' => HostInstitution::factory(), 'name' => fake()->unique()->bothify('Área ####??'), 'status' => 'active'];
    }
}

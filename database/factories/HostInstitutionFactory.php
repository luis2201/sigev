<?php

namespace Database\Factories;

use App\Models\HostInstitution;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<HostInstitution> */
class HostInstitutionFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['code' => fake()->unique()->bothify('INST-####??'), 'name' => fake()->unique()->company(), 'normalized_name' => fn (array $attributes) => Str::lower(preg_replace('/[^\pL\pN]+/u', '', Str::ascii($attributes['name']))), 'address' => fake()->address(), 'province' => 'Manabí', 'canton' => 'Portoviejo', 'status' => 'active'];
    }
}

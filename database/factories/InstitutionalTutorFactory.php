<?php

namespace Database\Factories;

use App\Models\HostInstitution;
use App\Models\InstitutionalTutor;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<InstitutionalTutor> */
class InstitutionalTutorFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['host_institution_id' => HostInstitution::factory(), 'institution_area_id' => null, 'name' => fake()->name(), 'normalized_name' => fn (array $attributes) => Str::lower(preg_replace('/[^\pL\pN]+/u', '', Str::ascii($attributes['name']))), 'email' => fake()->safeEmail(), 'phone' => null, 'status' => 'active'];
    }
}

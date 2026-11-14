<?php

namespace Database\Factories;

use App\Models\HostInstitution;
use App\Models\InstitutionalTutor;
use App\Models\InstitutionArea;
use App\Models\PlacementConfiguration;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PlacementConfiguration> */
class PlacementConfigurationFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['project_id' => Project::factory(), 'academic_period_id' => fn (array $attributes) => Project::findOrFail($attributes['project_id'])->academic_period_id, 'placement_group_id' => null, 'host_institution_id' => HostInstitution::factory(), 'institution_area_id' => fn (array $attributes) => InstitutionArea::factory()->create(['host_institution_id' => $attributes['host_institution_id']])->id, 'institutional_tutor_id' => fn (array $attributes) => InstitutionalTutor::factory()->create(['host_institution_id' => $attributes['host_institution_id']])->id, 'configuration_key' => fake()->uuid(), 'version_number' => 1, 'institution_name_snapshot' => fn (array $attributes) => HostInstitution::findOrFail($attributes['host_institution_id'])->name, 'address_snapshot' => fn (array $attributes) => HostInstitution::findOrFail($attributes['host_institution_id'])->address, 'area_name_snapshot' => fn (array $attributes) => InstitutionArea::findOrFail($attributes['institution_area_id'])->name, 'institutional_tutor_name' => fn (array $attributes) => InstitutionalTutor::findOrFail($attributes['institutional_tutor_id'])->name, 'project_name_snapshot' => fn (array $attributes) => Project::findOrFail($attributes['project_id'])->name, 'practice_starts_on' => '2026-11-01', 'practice_ends_on' => '2026-12-01', 'required_minutes' => 9600, 'valid_from' => now('UTC'), 'created_by' => User::factory(), 'reason' => 'Configuración de prueba'];
    }
}

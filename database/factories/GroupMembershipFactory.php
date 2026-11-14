<?php

namespace Database\Factories;

use App\Models\GroupMembership;
use App\Models\PlacementGroup;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<GroupMembership> */
class GroupMembershipFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['valid_from' => now('UTC'), 'valid_until' => null, 'assigned_by' => User::factory(), 'reason' => 'Asignación de prueba', 'placement_group_id' => PlacementGroup::factory(), 'academic_period_id' => fn (array $attributes) => PlacementGroup::findOrFail($attributes['placement_group_id'])->academic_period_id];
    }
}

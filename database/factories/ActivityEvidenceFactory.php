<?php

namespace Database\Factories;

use App\Models\ActivityEvidence;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ActivityEvidence>
 */
class ActivityEvidenceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'created_at' => now('UTC'),
        ];
    }
}

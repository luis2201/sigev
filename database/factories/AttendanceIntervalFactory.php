<?php

namespace Database\Factories;

use App\Models\AttendanceInterval;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<AttendanceInterval>
 */
class AttendanceIntervalFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'authorized_start_at' => now('UTC'), 'authorized_end_at' => now('UTC')->addHour(), 'actual_entry_at' => now('UTC'), 'mark_status' => 'open', 'entry_request_key' => (string) Str::uuid(),
        ];
    }
}

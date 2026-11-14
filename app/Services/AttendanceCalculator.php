<?php

namespace App\Services;

use App\Models\AttendanceInterval;

class AttendanceCalculator
{
    /** @param array<int,array{0:int,1:int}> $ranges @return array<int,array{0:int,1:int}> */
    public function union(array $ranges): array
    {
        sort($ranges);
        $merged = [];
        foreach ($ranges as [$start, $end]) {
            if ($end <= $start) {
                continue;
            }
            $last = count($merged) - 1;
            if ($last >= 0 && $start <= $merged[$last][1]) {
                $merged[$last][1] = max($merged[$last][1], $end);
            } else {
                $merged[] = [$start, $end];
            }
        }

        return $merged;
    }

    /** @param array<int,array{0:int,1:int}> $actual @param array<int,array{0:int,1:int}> $authorized */
    public function eligibleSeconds(array $actual, array $authorized): int
    {
        $total = 0;
        foreach ($this->union($actual) as [$start, $end]) {
            foreach ($this->union($authorized) as [$allowedStart, $allowedEnd]) {
                $total += max(0, min($end, $allowedEnd) - max($start, $allowedStart));
            }
        }

        return $total;
    }

    public function minuteUnitsFromSeconds(int $seconds): int
    {
        return intdiv(max(0, $seconds) * 1000000 + 30, 60);
    }

    public function decimal(int $units): string
    {
        return intdiv($units, 1000000).'.'.str_pad((string) ($units % 1000000), 6, '0', STR_PAD_LEFT);
    }

    public function units(string|int $decimal): int
    {
        [$whole, $fraction] = array_pad(explode('.', (string) $decimal, 2), 2, '');

        return (int) $whole * 1000000 + (int) str_pad(substr($fraction, 0, 6), 6, '0');
    }

    public function display(int $units): string
    {
        $seconds = intdiv($units * 60 + 500000, 1000000);

        return intdiv($seconds, 3600).' h '.intdiv($seconds % 3600, 60).' min '.($seconds % 60).' s';
    }

    /** @param iterable<AttendanceInterval> $intervals @return array{real_seconds:int,eligible_seconds:int,outside_seconds:int,provisional:bool} */
    public function totals(iterable $intervals): array
    {
        $actual = [];
        $eligible = [];
        $provisional = false;
        foreach ($intervals as $interval) {
            $end = $interval->actual_exit_at ?? $interval->provisional_closed_at;
            if (! $end) {
                continue;
            }
            $start = $interval->actual_entry_at->timestamp;
            $finish = max($start, $end->timestamp);
            $actual[] = [$start, $finish];
            $eligible[] = [max($start, $interval->authorized_start_at->timestamp), min($finish, $interval->authorized_end_at->timestamp)];
            $provisional = $provisional || $interval->mark_status === 'provisional_closed';
        }
        $seconds = fn (array $ranges): int => array_sum(array_map(fn ($range) => $range[1] - $range[0], $this->union($ranges)));
        $real = $seconds($actual);
        $valid = $seconds($eligible);

        return ['real_seconds' => $real, 'eligible_seconds' => $valid, 'outside_seconds' => max(0, $real - $valid), 'provisional' => $provisional];
    }
}

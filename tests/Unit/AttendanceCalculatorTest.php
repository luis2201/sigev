<?php

namespace Tests\Unit;

use App\Services\AttendanceCalculator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AttendanceCalculatorTest extends TestCase
{
    public static function intersections(): array
    {
        return [
            'early entry and late exit' => [[[7 * 3600, 13 * 3600]], [[8 * 3600, 12 * 3600]], 4 * 3600],
            'late entry' => [[[9 * 3600, 12 * 3600]], [[8 * 3600, 12 * 3600]], 3 * 3600],
            'early exit' => [[[8 * 3600, 11 * 3600]], [[8 * 3600, 12 * 3600]], 3 * 3600],
            'break excluded' => [[[8 * 3600, 16 * 3600]], [[8 * 3600, 12 * 3600], [13 * 3600, 16 * 3600]], 7 * 3600],
            'overlapping actual ranges' => [[[8 * 3600, 11 * 3600], [10 * 3600, 12 * 3600]], [[8 * 3600, 12 * 3600]], 4 * 3600],
            'overlapping schedules' => [[[8 * 3600, 12 * 3600]], [[8 * 3600, 11 * 3600], [10 * 3600, 12 * 3600]], 4 * 3600],
            'outside only' => [[[6 * 3600, 7 * 3600]], [[8 * 3600, 12 * 3600]], 0],
            'touching boundaries' => [[[7 * 3600, 8 * 3600], [12 * 3600, 13 * 3600]], [[8 * 3600, 12 * 3600]], 0],
            'adjacent real intervals' => [[[8 * 3600, 10 * 3600], [10 * 3600, 12 * 3600]], [[8 * 3600, 12 * 3600]], 4 * 3600],
            'zero or reversed intervals' => [[[3, 3], [9, 2]], [[0, 20]], 0],
        ];
    }

    #[DataProvider('intersections')]
    public function test_eligible_time_is_union_of_intersections_without_double_counting(array $actual, array $program, int $seconds): void
    {
        $this->assertSame($seconds, (new AttendanceCalculator)->eligibleSeconds($actual, $program));
    }

    public function test_fixed_precision_uses_integer_arithmetic(): void
    {
        $calculator = new AttendanceCalculator;
        $this->assertSame(1016667, $calculator->minuteUnitsFromSeconds(61));
        $this->assertSame('1.016667', $calculator->decimal(1016667));
        $this->assertSame(1016667, $calculator->units('1.016667'));
        $this->assertSame('1 h 1 min 1 s', $calculator->display($calculator->minuteUnitsFromSeconds(3661)));
        $this->assertSame(0, $calculator->minuteUnitsFromSeconds(-5));
    }
}

<?php

namespace App\Services;

use App\Models\Participation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class AttendanceLedger
{
    public function __construct(private AttendanceCalculator $calculator) {}

    /** @return array{required_units:int,credited_units:int,pending_units:int,percent:int} */
    public function progress(Participation $participation): array
    {
        $decimal = DB::table('attendance_days as day')->join('attendance_reviews as review', function ($join): void {
            $join->on('review.id', '=', 'day.current_review_id')->on('review.attendance_day_id', '=', 'day.id');
        })->where('day.participation_id', $participation->id)->whereIn('review.decision', ['ordinary', 'exceptional'])->sum('review.approved_minutes');

        return $this->summary($participation, (string) $decimal);
    }

    /** @return array<int,array{required_units:int,credited_units:int,pending_units:int,percent:int}> */
    public function forParticipations(Collection $participations): array
    {
        $totals = DB::table('attendance_days as day')->join('attendance_reviews as review', 'review.id', '=', 'day.current_review_id')
            ->whereIn('day.participation_id', $participations->pluck('id'))->whereIn('review.decision', ['ordinary', 'exceptional'])
            ->groupBy('day.participation_id')->selectRaw('day.participation_id, SUM(review.approved_minutes) AS credited')->pluck('credited', 'participation_id');

        return $participations->mapWithKeys(fn (Participation $p) => [$p->id => $this->summary($p, (string) ($totals[$p->id] ?? '0'))])->all();
    }

    /** @return array{required_units:int,credited_units:int,pending_units:int,percent:int} */
    private function summary(Participation $participation, string $decimal): array
    {
        $credited = $this->calculator->units($decimal);
        $required = (int) ($participation->currentConfiguration?->configuration->required_minutes ?? 0) * 1000000;

        return ['required_units' => $required, 'credited_units' => $credited, 'pending_units' => max(0, $required - $credited), 'percent' => $required > 0 ? min(100, intdiv($credited * 100, $required)) : 0];
    }
}

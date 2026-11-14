<?php

namespace App\Console\Commands;

use App\Models\AttendanceDay;
use App\Models\AttendanceInterval;
use App\Services\AttendanceManager;
use Illuminate\Console\Command;

class CloseAttendance extends Command
{
    protected $signature = 'sigev:close-attendance';

    protected $description = 'Cierra provisionalmente intervalos vencidos y finaliza jornadas; nunca acredita horas';

    public function handle(AttendanceManager $manager): int
    {
        $closed = 0;
        AttendanceInterval::where('mark_status', 'open')->where('authorized_end_at', '<=', now('UTC'))->select(['id', 'participation_id'])->chunkById(100, function ($intervals) use ($manager, &$closed): void {
            foreach ($intervals as $interval) {
                if ($manager->autoClose($interval->id)) {
                    $closed++;
                }
            }
        });
        AttendanceDay::where('status', 'in_progress')->whereDoesntHave('intervals', fn ($query) => $query->where('mark_status', 'open'))->select('id')->chunkById(100, function ($days) use ($manager): void {
            foreach ($days as $day) {
                $manager->finalizeDay($day->id);
            }
        });
        $this->info('Intervalos cerrados provisionalmente: '.$closed.'. No se acreditaron horas.');

        return self::SUCCESS;
    }
}

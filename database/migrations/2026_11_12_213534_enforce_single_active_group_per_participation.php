<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            DB::table('roles')->orderBy('id')->lockForUpdate()->get();
            DB::table('academic_periods')->orderBy('id')->lockForUpdate()->get();
            $duplicates = DB::table('group_memberships')->whereNull('valid_until')->select('participation_id')->groupBy('participation_id')->havingRaw('COUNT(*) > 1')->orderBy('participation_id')->pluck('participation_id');
            foreach ($duplicates as $participationId) {
                DB::table('participations')->where('id', $participationId)->lockForUpdate()->first();
                $memberships = DB::table('group_memberships')->where('participation_id', $participationId)->whereNull('valid_until')->orderBy('valid_from')->orderBy('id')->lockForUpdate()->get();
                $assignedGroupId = DB::table('participation_configurations as application')
                    ->join('placement_configurations as configuration', 'configuration.id', '=', 'application.placement_configuration_id')
                    ->where('application.participation_id', $participationId)->whereNull('application.valid_until')->value('configuration.placement_group_id');
                $keep = $memberships->firstWhere('placement_group_id', $assignedGroupId) ?? $memberships->first();
                foreach ($memberships as $membership) {
                    if ($membership->id === $keep->id) {
                        continue;
                    }
                    $until = now('UTC')->max(Carbon::parse($membership->valid_from, 'UTC')->addMicrosecond());
                    DB::table('group_memberships')->where('id', $membership->id)->update(['valid_until' => $until->format('Y-m-d H:i:s.u')]);
                    DB::table('audit_events')->insert([
                        'actor_id' => null, 'academic_period_id' => $membership->academic_period_id,
                        'action' => 'group.member.reconciled', 'entity_type' => 'group_memberships', 'entity_id' => $membership->id,
                        'request_id' => (string) Str::uuid(), 'changes' => json_encode(['fields' => ['valid_until']], JSON_THROW_ON_ERROR),
                        'reason' => 'Regla confirmada: un solo grupo vigente por participación y período. Se conserva el grupo '.$keep->placement_group_id.($assignedGroupId === $keep->placement_group_id ? ' por corresponder a la asignación vigente.' : ' por ser la pertenencia vigente más antigua.'),
                        'occurred_at' => now('UTC'), 'created_at' => now('UTC'),
                    ]);
                }
            }
        }, 3);
        Schema::table('group_memberships', function (Blueprint $table): void {
            $table->unique('current_participation_id', 'uq_group_memberships_one_current');
        });
    }

    public function down(): void
    {
        Schema::table('group_memberships', function (Blueprint $table): void {
            $table->dropUnique('uq_group_memberships_one_current');
        });
    }
};

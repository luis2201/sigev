<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\Career;
use App\Models\GroupMembership;
use App\Models\Participation;
use App\Models\ParticipationConfiguration;
use App\Models\PlacementConfiguration;
use App\Models\PlacementGroup;
use App\Models\Project;
use App\Models\Student;
use App\Models\StudentCareerRecord;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class GroupMembershipMigrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $name = config('database.connections.mysql.database');
        if (getenv('SIGEV_TEST_DB_AUTHORIZED') !== '1' || config('database.default') !== 'mysql' || ! is_string($name) || ! str_ends_with($name, '_test') || $name === 'sigev') {
            $this->markTestSkipped('Requiere MariaDB aislada *_test y autorización explícita.');
        }
    }

    public function test_migration_keeps_assigned_group_or_oldest_membership_and_preserves_history_and_configuration(): void
    {
        $this->travelTo(now('UTC')->setDate(2026, 11, 12)->setTime(20, 0));
        $tables = ['participation_configurations', 'group_memberships', 'placement_configurations', 'placement_groups', 'institutional_tutors', 'institution_areas', 'host_institutions', 'audit_events', 'participations', 'student_career_records', 'students', 'projects', 'academic_periods', 'careers', 'users'];
        $baseline = [];
        foreach ($tables as $table) {
            $baseline[$table] = DB::table($table)->max('id') ?? 0;
        }
        $migration = require database_path('migrations/2026_11_12_213534_enforce_single_active_group_per_participation.php');
        try {
            $migration->down();
            $project = Project::factory()->create();
            $actor = User::factory()->create();
            $career = Career::create(['code' => 'MIG', 'name' => 'Carrera de prueba', 'status' => 'active']);
            $firstGroup = PlacementGroup::factory()->create(['academic_period_id' => $project->academic_period_id, 'created_by' => $actor->id]);
            $assignedGroup = PlacementGroup::factory()->create(['academic_period_id' => $project->academic_period_id, 'created_by' => $actor->id]);
            $participations = [];
            foreach (range(1, 2) as $index) {
                $student = Student::create(['user_id' => User::factory()->create()->id, 'status' => 'active']);
                $record = StudentCareerRecord::create(['student_id' => $student->id, 'career_id' => $career->id, 'valid_from' => '2026-01-01', 'recorded_by' => $actor->id, 'reason' => 'Ingreso']);
                $p = Participation::create(['student_id' => $student->id, 'academic_period_id' => $project->academic_period_id, 'career_id' => $career->id, 'student_career_record_id' => $record->id, 'status' => 'active', 'registered_by' => $actor->id, 'registered_at' => now('UTC')]);
                $participations[] = $p;
                foreach ([$firstGroup, $assignedGroup] as $offset => $group) {
                    GroupMembership::factory()->create(['placement_group_id' => $group->id, 'participation_id' => $p->id, 'academic_period_id' => $p->academic_period_id, 'assigned_by' => $actor->id, 'valid_from' => now('UTC')->subDays(2 - $offset), 'reason' => 'Ingreso original']);
                }
            }
            [$withAssignment, $withoutAssignment] = $participations;
            $configuration = PlacementConfiguration::factory()->create(['project_id' => $project->id, 'academic_period_id' => $project->academic_period_id, 'placement_group_id' => $assignedGroup->id]);
            ParticipationConfiguration::factory()->create(['participation_id' => $withAssignment->id, 'placement_configuration_id' => $configuration->id, 'academic_period_id' => $project->academic_period_id, 'assigned_by' => $actor->id]);
            $historical = GroupMembership::factory()->create(['placement_group_id' => $firstGroup->id, 'participation_id' => $withAssignment->id, 'academic_period_id' => $project->academic_period_id, 'assigned_by' => $actor->id, 'valid_from' => now('UTC')->subDays(5), 'valid_until' => now('UTC')->subDays(4)]);

            $migration->up();

            $this->assertSame($assignedGroup->id, GroupMembership::where('participation_id', $withAssignment->id)->whereNull('valid_until')->sole()->placement_group_id);
            $this->assertSame($firstGroup->id, GroupMembership::where('participation_id', $withoutAssignment->id)->whereNull('valid_until')->sole()->placement_group_id);
            $this->assertSame(5, GroupMembership::count() - DB::table('group_memberships')->where('id', '<=', $baseline['group_memberships'])->count());
            $this->assertSame(2, AuditEvent::where('action', 'group.member.reconciled')->where('id', '>', $baseline['audit_events'])->count());
            $this->assertSame($historical->valid_until->format('Y-m-d H:i:s.u'), $historical->fresh()->valid_until->format('Y-m-d H:i:s.u'));
            $this->assertSame($assignedGroup->id, $configuration->fresh()->placement_group_id);
            $this->assertSame(9600, $configuration->fresh()->required_minutes);
            $this->assertTrue(Schema::hasIndex('group_memberships', 'uq_group_memberships_one_current'));
        } finally {
            foreach ($tables as $table) {
                DB::table($table)->where('id', '>', $baseline[$table])->delete();
            }
            if (! Schema::hasIndex('group_memberships', 'uq_group_memberships_one_current')) {
                $migration->up();
            }
        }
    }
}
